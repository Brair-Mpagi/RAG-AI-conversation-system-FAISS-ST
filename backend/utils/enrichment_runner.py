"""Batch enrichment runner for scraped_content."""

from __future__ import annotations

import json
import logging
from typing import Any, Dict, List, Optional

from sqlalchemy import text
from sqlalchemy.orm import Session

from utils.content_enrichment import build_rule_enrichment, parse_sections
from utils.llm_enrichment import enrich_row

import time

logger = logging.getLogger(__name__)


def _row_to_dict(row) -> dict:
    return dict(row) if hasattr(row, "keys") else row


def _update_enrichment_with_retry(
    db: Session,
    scraped_id: int,
    payload: Dict[str, Any],
    content_hash: Optional[str],
    max_retries: int = 3,
) -> bool:
    """Update scraped_content with enrichment results, retrying on deadlocks."""
    for attempt in range(max_retries):
        try:
            db.execute(
                text("""
                    UPDATE scraped_content
                    SET sections_json = COALESCE(:sections_json, sections_json),
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
                    "sections_json": payload.get("sections_json"),
                    "search_document": payload["search_document"],
                    "enrichment_json": payload["enrichment_json"],
                    "enrichment_status": payload["enrichment_status"],
                    "enrichment_hash": content_hash,
                },
            )
            db.commit()
            return True
        except Exception as exc:
            db.rollback()
            err_msg = str(exc)
            # MySQL error codes: 1213 (Deadlock found), 1205 (Lock wait timeout)
            is_lock_issue = any(code in err_msg for code in ("1213", "1205", "Deadlock", "Lock wait timeout"))
            if is_lock_issue and attempt < max_retries - 1:
                sleep_time = 0.2 * (2 ** attempt)
                logger.warning(
                    "Deadlock or lock timeout updating scraped_id=%s. Retrying in %.2fs (attempt %s/%s): %s",
                    scraped_id,
                    sleep_time,
                    attempt + 1,
                    max_retries,
                    exc,
                )
                time.sleep(sleep_time)
            else:
                logger.error(
                    "Failed to update enrichment for scraped_id=%s on attempt %s/%s: %s",
                    scraped_id,
                    attempt + 1,
                    max_retries,
                    exc,
                )
                break
    return False


def _update_status_failed(db: Session, scraped_id: int, max_retries: int = 3) -> None:
    """Mark a scraped_content row as failed, retrying on deadlocks."""
    for attempt in range(max_retries):
        try:
            db.execute(
                text("""
                    UPDATE scraped_content
                    SET enrichment_status = 'failed', enriched_at = NOW()
                    WHERE scraped_id = :scraped_id
                """),
                {"scraped_id": scraped_id},
            )
            db.commit()
            return
        except Exception as exc:
            db.rollback()
            err_msg = str(exc)
            is_lock_issue = any(code in err_msg for code in ("1213", "1205", "Deadlock", "Lock wait timeout"))
            if is_lock_issue and attempt < max_retries - 1:
                sleep_time = 0.1 * (2 ** attempt)
                time.sleep(sleep_time)
            else:
                logger.error(
                    "Failed to mark status as failed for scraped_id=%s: %s",
                    scraped_id,
                    exc,
                )
                break


def enrich_scraped_content(
    db: Session,
    *,
    scraped_ids: Optional[List[int]] = None,
    only_pending: bool = True,
    limit: int = 50,
    use_llm: bool = True,
) -> Dict[str, Any]:
    """Enrich scraped pages. Returns counts summary."""
    if scraped_ids:
        scraped_ids = [int(i) for i in scraped_ids]
        limit = max(len(scraped_ids), min(int(limit), 200))
    else:
        limit = max(1, min(int(limit), 200))

    where_parts = [
        "cleaned_content IS NOT NULL",
        "cleaned_content != ''",
    ]
    params: Dict[str, Any] = {"lim": limit}

    if scraped_ids:
        placeholders = ", ".join(str(i) for i in scraped_ids)
        where_parts.append(f"scraped_id IN ({placeholders})")
    elif only_pending:
        where_parts.append("enrichment_status IN ('pending', 'failed')")

    where_sql = " AND ".join(where_parts)

    try:
        sql = text(f"""
            SELECT scraped_id, page_title, page_url, cleaned_content,
                   sections_json, meta_category, content_hash, enrichment_status
            FROM scraped_content
            WHERE {where_sql}
            ORDER BY scraped_at DESC
            LIMIT :lim
        """)
        rows = db.execute(sql, params).mappings().all()
        db.commit()  # Release read locks / transaction immediately
    except Exception as exc:
        db.rollback()
        logger.warning("Enrichment query failed (migration missing?): %s", exc)
        return {
            "status": "error",
            "detail": str(exc),
            "processed": 0,
            "enriched": 0,
            "failed": 0,
            "skipped": 0,
        }

    enriched = failed = skipped = 0

    for row in rows:
        r = _row_to_dict(row)
        sid = r.get("scraped_id")
        try:
            if use_llm:
                payload = enrich_row(r)
            else:
                sections = parse_sections(r.get("sections_json"))
                built = build_rule_enrichment(
                    r.get("page_title") or "",
                    r.get("page_url") or "",
                    r.get("cleaned_content") or "",
                    sections,
                    meta_category=r.get("meta_category"),
                )
                payload = {
                    "sections_json": json.dumps(built["sections_json"], ensure_ascii=False),
                    "search_document": built["search_document"],
                    "enrichment_json": json.dumps(built["enrichment_json"], ensure_ascii=False),
                    "enrichment_status": "done",
                }
        except Exception as exc:
            logger.error("Enrichment processing failed for scraped_id=%s: %s", sid, exc)
            _update_status_failed(db, sid)
            failed += 1
            continue

        if _update_enrichment_with_retry(db, sid, payload, r.get("content_hash")):
            enriched += 1
        else:
            _update_status_failed(db, sid)
            failed += 1

    return {
        "status": "ok",
        "processed": len(rows),
        "enriched": enriched,
        "failed": failed,
        "skipped": skipped,
        "use_llm": use_llm,
    }


def get_enrichment_status(db: Session) -> Dict[str, Any]:
    """Return counts by enrichment_status for admin UI."""
    try:
        rows = db.execute(text("""
            SELECT enrichment_status, COUNT(*) AS cnt
            FROM scraped_content
            WHERE cleaned_content IS NOT NULL AND cleaned_content != ''
            GROUP BY enrichment_status
        """)).mappings().all()
    except Exception as exc:
        return {"status": "error", "detail": str(exc), "counts": {}}

    counts: Dict[str, int] = {}
    total = 0
    for r in rows:
        key = r.get("enrichment_status") or "pending"
        cnt = int(r.get("cnt") or 0)
        counts[key] = cnt
        total += cnt

    pending = counts.get("pending", 0) + counts.get("failed", 0)
    return {
        "status": "ok",
        "counts": counts,
        "total": total,
        "pending": pending,
        "done": counts.get("done", 0),
    }


def enrich_all_pending(
    db: Session,
    *,
    batch_size: int = 50,
    max_rounds: int = 100,
    use_llm: bool = True,
) -> Dict[str, Any]:
    """Process all pending/failed pages in batches until none remain."""
    batch_size = max(1, min(int(batch_size), 200))
    max_rounds = max(1, min(int(max_rounds), 500))

    totals = {"enriched": 0, "failed": 0, "processed": 0, "rounds": 0}

    for _ in range(max_rounds):
        try:
            from utils.pipeline_jobs import is_cancel_requested
            if is_cancel_requested():
                totals["cancelled"] = True
                break
        except Exception:
            pass

        summary = enrich_scraped_content(
            db,
            only_pending=True,
            limit=batch_size,
            use_llm=use_llm,
        )
        if summary.get("status") == "error":
            return {**summary, **totals, "rounds": totals["rounds"]}

        totals["rounds"] += 1
        totals["enriched"] += summary.get("enriched", 0)
        totals["failed"] += summary.get("failed", 0)
        totals["processed"] += summary.get("processed", 0)

        if summary.get("processed", 0) == 0:
            break

    remaining = get_enrichment_status(db)
    return {
        "status": "ok",
        **totals,
        "remaining_pending": remaining.get("pending", 0),
        "use_llm": use_llm,
    }
