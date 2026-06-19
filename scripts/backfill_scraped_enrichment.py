#!/usr/bin/env python3
"""Backfill sections_json, search_document, and rule enrichment for existing scraped rows.

Run after applying database/migrations/001_scraped_content_enrichment.sql:

    cd backend && source backend_env/bin/activate
    python ../scripts/backfill_scraped_enrichment.py
"""

from __future__ import annotations

import json
import os
import sys
from pathlib import Path

import pymysql
import pymysql.cursors
from dotenv import load_dotenv

_BACKEND_DIR = Path(__file__).resolve().parent.parent / "backend"
sys.path.insert(0, str(_BACKEND_DIR))

from utils.content_enrichment import build_rule_enrichment  # noqa: E402

load_dotenv(_BACKEND_DIR / ".env")


def _db_config() -> dict:
    return {
        "host": os.getenv("DB_HOST", "localhost"),
        "port": int(os.getenv("DB_PORT", "3306")),
        "user": os.getenv("DB_USER", "campus_ai_user"),
        "password": os.getenv("DB_PASSWORD", "root"),
        "database": os.getenv("DB_NAME", "campus_ai_db"),
        "charset": os.getenv("DB_CHARSET", "utf8mb4"),
        "cursorclass": pymysql.cursors.DictCursor,
    }


def main() -> None:
    conn = pymysql.connect(**_db_config())
    updated = 0
    try:
        with conn.cursor() as cur:
            cur.execute("""
                SELECT scraped_id, page_url, page_title, cleaned_content,
                       sections_json, meta_category
                FROM scraped_content
                WHERE cleaned_content IS NOT NULL AND cleaned_content != ''
            """)
            rows = cur.fetchall()

        print(f"Found {len(rows)} rows to backfill")
        for row in rows:
            sections = row.get("sections_json")
            if isinstance(sections, str):
                try:
                    sections = json.loads(sections)
                except json.JSONDecodeError:
                    sections = []
            built = build_rule_enrichment(
                row.get("page_title") or "",
                row.get("page_url") or "",
                row.get("cleaned_content") or "",
                sections=sections or [],
                meta_category=row.get("meta_category"),
            )
            content_hash = row.get("content_hash")
            with conn.cursor() as cur:
                cur.execute("""
                    UPDATE scraped_content
                    SET sections_json = %s,
                        search_document = %s,
                        enrichment_json = %s,
                        enrichment_hash = COALESCE(content_hash, %s),
                        enrichment_status = 'pending',
                        enriched_at = NOW()
                    WHERE scraped_id = %s
                """, (
                    json.dumps(built["sections_json"], ensure_ascii=False),
                    built["search_document"],
                    json.dumps(built["enrichment_json"], ensure_ascii=False),
                    content_hash,
                    row["scraped_id"],
                ))
            updated += 1

        conn.commit()
        print(f"Backfilled {updated} rows")
    finally:
        conn.close()


if __name__ == "__main__":
    main()
