from __future__ import annotations

import json
import logging
from typing import List, Dict

import numpy as np
from sqlalchemy import text
from sqlalchemy.orm import Session

from core.config import settings
from utils.embeddings import embed_texts
from utils.content_enrichment import (
    build_page_card_text,
    build_rule_enrichment,
    build_section_chunk_text,
    parse_sections,
)
from utils.text_chunking import chunk_text

try:
    import faiss  # type: ignore
    FAISS_AVAILABLE = True
except Exception:
    faiss = None  # type: ignore
    FAISS_AVAILABLE = False

logger = logging.getLogger(__name__)


def _write_metadata(metadata: List[Dict[str, str]]) -> None:
    path = settings.FAISS_METADATA_PATH
    with open(path, "w", encoding="utf-8") as f:
        json.dump(metadata, f, ensure_ascii=False, indent=2)


def _append_scraped_doc(
    docs: List[str],
    metadata: List[Dict],
    *,
    content: str,
    scraped_id: int,
    page_url: str,
    page_title: str,
    chunk_type: str,
    chunk_index: int,
    enrichment_json: dict | None,
    section_heading: str = "",
) -> None:
    if not content or len(content.strip()) < 30:
        return
    tags = []
    page_type = ""
    if enrichment_json:
        tags = enrichment_json.get("tags") or []
        page_type = enrichment_json.get("page_type") or ""
    docs.append(content)
    metadata.append({
        "content": content[: settings.MAX_CONTEXT_LENGTH],
        "source": f"scraped:{scraped_id}",
        "type": "scraped",
        "url": page_url or "",
        "page_title": page_title or "",
        "chunk_type": chunk_type,
        "chunk_index": chunk_index,
        "section_heading": section_heading,
        "tags": ", ".join(tags[:20]) if tags else "",
        "page_type": page_type,
    })


def _index_scraped_page(
    docs: List[str],
    metadata: List[Dict],
    row: dict,
) -> int:
    """Index one scraped page: page card + section chunks (fallback: flat chunks)."""
    scraped_id = row.get("scraped_id")
    title = (row.get("page_title") or "").strip()
    content = (row.get("cleaned_content") or "").strip()
    page_url = row.get("page_url") or ""

    if not content:
        return 0

    sections = parse_sections(row.get("sections_json"))
    enrichment_json = row.get("enrichment_json")
    if isinstance(enrichment_json, str):
        try:
            enrichment_json = json.loads(enrichment_json)
        except (json.JSONDecodeError, TypeError):
            enrichment_json = None

    search_document = (row.get("search_document") or "").strip()
    if not search_document:
        built = build_rule_enrichment(
            title, page_url, content, sections,
            meta_category=row.get("meta_category"),
        )
        search_document = built["search_document"]
        if not enrichment_json:
            enrichment_json = built["enrichment_json"]
        if not sections:
            sections = built["sections_json"]

    count = 0
    chunk_index = 0

    # 1) Page card vector — broad semantic matching
    page_card = build_page_card_text(title, search_document, enrichment_json)
    _append_scraped_doc(
        docs, metadata,
        content=page_card,
        scraped_id=scraped_id,
        page_url=page_url,
        page_title=title,
        chunk_type="page_card",
        chunk_index=chunk_index,
        enrichment_json=enrichment_json,
    )
    count += 1
    chunk_index += 1

    # 2) Section-based chunks
    if sections:
        for sec in sections:
            heading = (sec.get("heading") or "").strip()
            sec_text = (sec.get("text") or "").strip()
            if not sec_text and not heading:
                continue
            for embed_text in build_section_chunk_text(
                title, heading, sec_text, enrichment_json,
            ):
                _append_scraped_doc(
                    docs, metadata,
                    content=embed_text,
                    scraped_id=scraped_id,
                    page_url=page_url,
                    page_title=title,
                    chunk_type="section",
                    chunk_index=chunk_index,
                    enrichment_json=enrichment_json,
                    section_heading=heading,
                )
                count += 1
                chunk_index += 1
    else:
        # Fallback: flat body chunks with enrichment header
        header = build_page_card_text(title, "", enrichment_json)
        for body in chunk_text(content):
            embed_text = f"{header}\n\n{body}" if header else body
            _append_scraped_doc(
                docs, metadata,
                content=embed_text,
                scraped_id=scraped_id,
                page_url=page_url,
                page_title=title,
                chunk_type="body",
                chunk_index=chunk_index,
                enrichment_json=enrichment_json,
            )
            count += 1
            chunk_index += 1

    return count


def rebuild_faiss_index(db: Session) -> Dict[str, int | bool]:
    """Rebuild FAISS index from entity knowledge chunks + scraped content.

    Scraped pages are indexed as:
      - one page_card vector per URL (search_document + tags)
      - section vectors when sections_json is available
      - fallback body chunks otherwise

    Returns summary dict {count, entity_chunks, scraped_chunks, campus_chunks, faiss_built}.
    """
    docs: List[str] = []
    metadata: List[Dict[str, str]] = []

    # --- 1. Load entity knowledge chunks ---
    entity_sql = text("""
        SELECT ck.chunk_id, ck.entity_id, ck.chunk_index, ck.title, ck.content,
               e.entity_code, e.name AS entity_name, et.type_name AS entity_type
        FROM entity_knowledge_chunks ck
        INNER JOIN university_entities e ON ck.entity_id = e.entity_id
        INNER JOIN entity_types et ON e.entity_type_id = et.type_id
        WHERE ck.is_active = TRUE AND e.is_active = TRUE
        ORDER BY e.entity_id, ck.chunk_index
    """)
    chunk_rows = db.execute(entity_sql).mappings().all()
    entity_chunk_count = 0
    for r in chunk_rows:
        title = (r.get("title") or "").strip()
        content = (r.get("content") or "").strip()
        full = f"{title}\n\n{content}" if title else content
        docs.append(full)
        metadata.append({
            "content": full[: settings.MAX_CONTEXT_LENGTH],
            "source": f"entity:{r.get('entity_id')}:{r.get('chunk_index')}",
            "type": r.get("entity_type", "entity"),
            "entity_name": r.get("entity_name", ""),
            "entity_code": r.get("entity_code", ""),
            "chunk_type": "entity",
        })
        entity_chunk_count += 1

    # --- 1b. Load base entity profiles directly from university_entities ---
    base_entity_sql = text("""
        SELECT e.entity_id, e.entity_code, e.name, e.short_name, e.description, e.structured_data,
               et.type_name, et.type_label,
               p.name AS parent_name
        FROM university_entities e
        INNER JOIN entity_types et ON e.entity_type_id = et.type_id
        LEFT JOIN university_entities p ON e.parent_entity_id = p.entity_id
        WHERE e.is_active = TRUE
    """)
    base_entity_rows = db.execute(base_entity_sql).mappings().all()
    for r in base_entity_rows:
        title = f"{r.get('name')} ({r.get('type_label')})"
        content_parts = []
        if r.get('short_name'):
            content_parts.append(f"Acronym/Short Name: {r.get('short_name')}")
        if r.get('parent_name'):
            content_parts.append(f"Under: {r.get('parent_name')}")
        if r.get('description'):
            content_parts.append(f"Description: {r.get('description')}")

        sd = r.get('structured_data')
        if sd:
            try:
                sd_dict = json.loads(sd)
                if isinstance(sd_dict, dict):
                    for k, v in sd_dict.items():
                        if v and str(v).strip():
                            content_parts.append(f"{k.replace('_', ' ').title()}: {v}")
            except Exception as e:
                logger.warning("Failed to parse structured_data for entity %s: %s", r.get('entity_id'), e)

        content = "\n".join(content_parts)
        full = f"{title}\n\n{content}"

        docs.append(full)
        metadata.append({
            "content": full[: settings.MAX_CONTEXT_LENGTH],
            "source": f"entity:{r.get('entity_id')}:base",
            "type": r.get("type_name", "entity"),
            "entity_name": r.get("name", ""),
            "entity_code": r.get("entity_code", ""),
            "chunk_type": "entity",
        })
        entity_chunk_count += 1

    # --- 1c. Campus knowledge items (CMS structured content) ---
    campus_chunk_count = 0
    try:
        campus_sql = text("""
            SELECT item_id, category, subcategory, title, short_description, full_content, program_code
            FROM campus_knowledge_items
            WHERE is_active = TRUE AND full_content IS NOT NULL AND full_content != ''
            ORDER BY display_order, item_id
        """)
        campus_rows = db.execute(campus_sql).mappings().all()
        for r in campus_rows:
            title = (r.get("title") or "").strip()
            cat = (r.get("category") or "").strip()
            sub = (r.get("subcategory") or "").strip()
            header = f"{title} ({cat})" if cat else title
            if sub:
                header += f" — {sub}"
            if r.get("program_code"):
                header += f" [{r.get('program_code')}]"
            desc = (r.get("short_description") or "").strip()
            body = (r.get("full_content") or "").strip()
            parts = [header]
            if desc:
                parts.append(desc)
            parts.append(body)
            full = "\n\n".join(parts)
            for ci, chunk in enumerate(chunk_text(body) or [body]):
                embed = f"{header}\n\n{chunk}" if chunk != body else full
                docs.append(embed)
                metadata.append({
                    "content": embed[: settings.MAX_CONTEXT_LENGTH],
                    "source": f"campus_item:{r.get('item_id')}:{ci}",
                    "type": "campus_knowledge",
                    "page_title": title,
                    "chunk_type": "campus_item",
                    "category": cat,
                })
                campus_chunk_count += 1
    except Exception as e:
        logger.warning("campus_knowledge_items not indexed: %s", e)

    # --- 2. Load scraped content with enrichment fields ---
    scraped_sql = text("""
        SELECT scraped_id, page_title, cleaned_content, page_url,
               sections_json, search_document, enrichment_json, meta_category
        FROM scraped_content
        WHERE status IN ('new', 'processed', 'indexed', 'updated')
          AND cleaned_content IS NOT NULL
          AND cleaned_content != ''
        ORDER BY scraped_id
    """)
    try:
        scraped_rows = db.execute(scraped_sql).mappings().all()
    except Exception:
        # Backward compatibility if migration not yet applied
        logger.warning("Enrichment columns missing — using legacy scraped_content query")
        scraped_sql = text("""
            SELECT scraped_id, page_title, cleaned_content, page_url
            FROM scraped_content
            WHERE status IN ('new', 'processed', 'indexed', 'updated')
              AND cleaned_content IS NOT NULL
              AND cleaned_content != ''
            ORDER BY scraped_id
        """)
        scraped_rows = db.execute(scraped_sql).mappings().all()

    scraped_ids: list[int] = []
    scraped_chunk_count = 0
    for r in scraped_rows:
        content = (r.get("cleaned_content") or "").strip()
        if not content:
            continue
        scraped_ids.append(r.get("scraped_id"))
        scraped_chunk_count += _index_scraped_page(docs, metadata, dict(r))

    # Mark scraped rows as indexed
    if scraped_ids:
        try:
            db.execute(
                text("UPDATE scraped_content SET status = 'indexed', indexed_at = NOW() WHERE scraped_id IN :ids"),
                {"ids": tuple(scraped_ids)},
            )
            db.commit()
        except Exception as e:
            logger.warning("Failed to update scraped_content status: %s", e)

    if not docs:
        _write_metadata([])
        return {"count": 0, "entity_chunks": 0, "scraped_chunks": 0, "campus_chunks": 0, "faiss_built": False}

    vectors = embed_texts(docs).astype(np.float32)
    _write_metadata(metadata)

    if not FAISS_AVAILABLE:
        logger.warning("FAISS not available - metadata written, index not built")
        return {
            "count": len(docs),
            "entity_chunks": entity_chunk_count,
            "scraped_chunks": scraped_chunk_count,
            "campus_chunks": campus_chunk_count,
            "faiss_built": False,
        }

    d = vectors.shape[1]
    index = faiss.IndexFlatL2(d)  # type: ignore[attr-defined]
    index.add(vectors)  # type: ignore[attr-defined]
    faiss.write_index(index, settings.FAISS_INDEX_PATH)  # type: ignore[attr-defined]

    return {
        "count": len(docs),
        "entity_chunks": entity_chunk_count,
        "scraped_chunks": scraped_chunk_count,
        "campus_chunks": campus_chunk_count,
        "faiss_built": True,
    }
