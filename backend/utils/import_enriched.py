# backend/utils/import_enriched.py
"""Utility for importing enriched scraped_content rows.
Supports .xlsx, .csv, .json files, preview mode, batched updates, and detailed reporting.
"""

import csv
import json
import io
import logging
import math
import time
import uuid
from datetime import datetime
from typing import Any, Dict, List, Tuple, Optional

import pandas as pd
from sqlalchemy import text
from sqlalchemy.orm import Session

from utils.content_enrichment import parse_sections
from utils.enrichment_runner import _update_status_failed

logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------
# Fields that MUST NOT be overwritten by the import
PROTECTED_FIELDS = {
    "scraped_id",
    "raw_content",
    "original_url",
    "created_at",
    "source_metadata",
    "scraper_metadata",
    "embeddings",
    "foreign_key_id",  # placeholder for any FK column names
}

# Fields that are allowed to be updated (enrichment related)
UPDATABLE_FIELDS = {
    "cleaned_content",
    "summary",
    "short_description",
    "keywords",
    "tags",
    "entities",
    "search_document",
    "sections_json",
    "enrichment_json",
    "enrichment_status",
    "enriched_at",
    "category",
    "topics",
    "page_type",
    "section_types",
    "query_aliases",
}

# Batch size for DB commits (100‑250 rows per transaction as requested)
DEFAULT_BATCH_SIZE = 200

# ---------------------------------------------------------------------------
# Helper functions
# ---------------------------------------------------------------------------

def _detect_file_type(filename: str) -> str:
    """Return 'xlsx', 'csv' or 'json' based on file extension.
    Raises ValueError for unsupported types.
    """
    lower = filename.lower()
    if lower.endswith('.xlsx'):
        return 'xlsx'
    if lower.endswith('.csv'):
        return 'csv'
    if lower.endswith('.json'):
        return 'json'
    raise ValueError('Unsupported file type: must be .xlsx, .csv or .json')


def _load_file(file_bytes: bytes, file_type: str) -> List[Dict[str, Any]]:
    """Parse the uploaded file and return a list of row dictionaries.
    Expected column for matching is `scraped_id`.
    """
    if file_type == 'xlsx':
        df = pd.read_excel(io.BytesIO(file_bytes), engine='openpyxl')
    elif file_type == 'csv':
        df = pd.read_csv(io.BytesIO(file_bytes))
    elif file_type == 'json':
        data = json.loads(file_bytes.decode('utf-8'))
        if isinstance(data, list):
            return data
        if isinstance(data, dict) and 'rows' in data:
            return data['rows']
        raise ValueError('JSON must be a list of objects or contain a "rows" key')
    else:
        raise ValueError('Unsupported file type')

    return json.loads(df.to_json(orient='records'))


def _validate_rows(rows: List[Dict[str, Any]]) -> Tuple[bool, Dict[str, Any]]:
    """Validate presence of scraped_id, detect duplicates.
    Returns (is_valid, report).
    """
    report: Dict[str, Any] = {
        "total_rows": len(rows),
        "duplicate_ids": [],
        "missing_id_rows": [],
    }
    seen = set()
    for idx, row in enumerate(rows):
        sid = row.get('scraped_id')
        if sid is None:
            report['missing_id_rows'].append(idx)
            continue
        try:
            sid = int(sid)
        except Exception:
            pass
        if sid in seen:
            report['duplicate_ids'].append(sid)
        else:
            seen.add(sid)
    is_valid = not report['duplicate_ids'] and not report['missing_id_rows']
    return is_valid, report


def _filter_updatable(row: Dict[str, Any]) -> Dict[str, Any]:
    """Return a dict containing only fields that are allowed to be updated.
    Removes protected fields and any keys not in UPDATABLE_FIELDS.
    """
    return {k: v for k, v in row.items() if k in UPDATABLE_FIELDS}


def _merge_row_into_enrichment(row: Dict[str, Any], current_enrichment_str: Optional[str]) -> str:
    """Parse existing enrichment_json and merge updatable subfields from the row."""
    enrichment = {}
    if current_enrichment_str:
        try:
            enrichment = json.loads(current_enrichment_str)
        except Exception:
            pass

    # If the row has direct 'enrichment_json', update our dictionary with it first
    if 'enrichment_json' in row and row['enrichment_json'] is not None:
        try:
            val = row['enrichment_json']
            if isinstance(val, str):
                val = json.loads(val)
            if isinstance(val, dict):
                enrichment.update(val)
        except Exception:
            pass

    # Merge individual updatable subfields
    subfields = [
        "summary", "short_description", "keywords", "tags", "entities",
        "category", "topics", "page_type", "section_types", "query_aliases"
    ]
    for field in subfields:
        if field in row and row[field] is not None:
            val = row[field]
            # Normalize list-like fields if they are strings (e.g. from Excel/CSV)
            if field in ['tags', 'entities', 'section_types', 'query_aliases']:
                if isinstance(val, str):
                    try:
                        parsed = json.loads(val)
                        if isinstance(parsed, list):
                            val = parsed
                        else:
                            val = [v.strip() for v in val.split(',') if v.strip()]
                    except Exception:
                        val = [v.strip() for v in val.split(',') if v.strip()]
            enrichment[field] = val

    return json.dumps(enrichment)


def _update_imported_row_with_retry(
    db: Session,
    scraped_id: int,
    payload: Dict[str, Any],
    content_hash: Optional[str],
    max_retries: int = 3,
) -> bool:
    """Update scraped_content with imported data, retrying on deadlocks."""
    for attempt in range(max_retries):
        try:
            db.execute(
                text("""
                    UPDATE scraped_content
                    SET cleaned_content = COALESCE(:cleaned_content, cleaned_content),
                        sections_json = COALESCE(:sections_json, sections_json),
                        search_document = :search_document,
                        enrichment_json = :enrichment_json,
                        enrichment_hash = :enrichment_hash,
                        enrichment_status = :enrichment_status,
                        enriched_at = NOW(),
                        status = IF(status = 'indexed', 'updated', status)
                    WHERE scraped_id = :scraped_id
                """),
                {
                    "scraped_id": scraped_id,
                    "cleaned_content": payload.get("cleaned_content"),
                    "sections_json": payload.get("sections_json"),
                    "search_document": payload.get("search_document"),
                    "enrichment_json": payload.get("enrichment_json"),
                    "enrichment_status": payload.get("enrichment_status"),
                    "enrichment_hash": content_hash,
                },
            )
            db.commit()
            return True
        except Exception as exc:
            db.rollback()
            err_msg = str(exc)
            is_lock_issue = any(code in err_msg for code in ("1213", "1205", "Deadlock", "Lock wait timeout"))
            if is_lock_issue and attempt < max_retries - 1:
                sleep_time = 0.2 * (2 ** attempt)
                time.sleep(sleep_time)
            else:
                logger.error(
                    "Failed to update imported row for scraped_id=%s on attempt %s/%s: %s",
                    scraped_id,
                    attempt + 1,
                    max_retries,
                    exc,
                )
                break
    return False


def _apply_updates(db: Session, rows: List[Dict[str, Any]]) -> Dict[str, Any]:
    """Process rows in sub‑batches, updating matching records.
    Returns a detailed report.
    """
    total = len(rows)
    updated = 0
    skipped = 0
    failed = 0
    unmatched_ids = []
    batch_size = DEFAULT_BATCH_SIZE

    # Process in chunks to keep transaction short
    for start in range(0, total, batch_size):
        chunk = rows[start:start + batch_size]
        try:
            for row in chunk:
                sid = row.get('scraped_id')
                if sid is None:
                    continue  # already counted as missing earlier
                
                # Check existence
                cur = db.execute(
                    text('SELECT search_document, enrichment_json FROM scraped_content WHERE scraped_id = :sid'),
                    {'sid': sid}
                ).mappings().first()
                if not cur:
                    unmatched_ids.append(sid)
                    continue

                payload = _filter_updatable(row)
                
                # Merge imported columns into enrichment_json
                merged_enrichment = _merge_row_into_enrichment(row, cur['enrichment_json'])
                payload['enrichment_json'] = merged_enrichment
                
                # Default essential database columns
                payload.setdefault('search_document', cur['search_document'])
                payload.setdefault('enrichment_status', 'done')
                
                content_hash = row.get('content_hash')
                success = _update_imported_row_with_retry(db, sid, payload, content_hash)
                if success:
                    updated += 1
                else:
                    _update_status_failed(db, sid)
                    failed += 1
            db.commit()
        except Exception as exc:
            db.rollback()
            logger.exception('Batch import failed, rolling back chunk starting at %s', start)
            failed += len(chunk)

    return {
        "total_rows": total,
        "updated": updated,
        "skipped_unmatched": len(unmatched_ids),
        "failed": failed,
        "duplicate_ids": [],
        "unmatched_ids": unmatched_ids,
    }

# ---------------------------------------------------------------------------
# Public API
# ---------------------------------------------------------------------------

def import_enriched_file(upload_file, db: Session, preview: bool = False) -> Dict[str, Any]:
    """Main entry point for the import endpoint.
    * `upload_file` – a FastAPI `UploadFile`‑like object with .filename and .read().
    * `preview` – if True, the function returns a diff‑style preview without mutating the DB.
    Returns a dictionary suitable for JSON response.
    """
    # 1. Detect file type
    file_type = _detect_file_type(upload_file.filename)
    # 2. Load content
    file_bytes = upload_file.file.read()
    rows = _load_file(file_bytes, file_type)
    # 3. Validate rows (duplicate IDs, missing IDs)
    is_valid, validation_report = _validate_rows(rows)
    if not is_valid:
        return {
            "status": "error",
            "message": "Validation failed",
            "validation": validation_report,
        }
    # 4. Preview mode – compare current DB values with uploaded ones
    if preview:
        preview_changes = []
        for row in rows:
            sid = row.get('scraped_id')
            if sid is None:
                continue
            try:
                sid = int(sid)
            except ValueError:
                pass
            cur = db.execute(
                text('SELECT * FROM scraped_content WHERE scraped_id = :sid'),
                {'sid': sid}
            ).mappings().first()
            if not cur:
                continue
            cur_dict = dict(cur)
            
            # Construct what the updated payload would look like
            payload = _filter_updatable(row)
            merged_enrichment = _merge_row_into_enrichment(row, cur_dict.get('enrichment_json'))
            payload['enrichment_json'] = merged_enrichment
            payload.setdefault('search_document', cur_dict.get('search_document'))
            payload.setdefault('enrichment_status', 'done')

            # Show only fields that differ and are database columns
            diffs = {}
            db_columns = ['cleaned_content', 'sections_json', 'search_document', 'enrichment_json', 'enrichment_status']
            for key in db_columns:
                if key in payload:
                    new_val = payload[key]
                    old_val = cur_dict.get(key)
                    if str(old_val) != str(new_val):
                        diffs[key] = {"old": old_val, "new": new_val}
            if diffs:
                preview_changes.append({"scraped_id": sid, "diffs": diffs})
        return {
            "status": "preview",
            "total_rows": len(rows),
            "changes": preview_changes,
        }

    # 5. Perform batched updates
    report = _apply_updates(db, rows)
    # 6. Add import batch metadata (simple UUID + timestamp)
    batch_id = str(uuid.uuid4())
    report.update({
        "import_batch_id": batch_id,
        "import_timestamp": datetime.utcnow().isoformat() + 'Z',
    })
    return {"status": "ok", **report}
