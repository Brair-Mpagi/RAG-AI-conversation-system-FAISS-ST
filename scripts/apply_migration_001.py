#!/usr/bin/env python3
"""Apply migration 001_scraped_content_enrichment.sql using backend .env credentials."""

from __future__ import annotations

import os
import sys
from pathlib import Path

import pymysql
from dotenv import load_dotenv

ROOT = Path(__file__).resolve().parent.parent
load_dotenv(ROOT / "backend" / ".env")

MIGRATION = ROOT / "database" / "migrations" / "001_scraped_content_enrichment.sql"


def main() -> None:
    sql = MIGRATION.read_text(encoding="utf-8")
    statements = []
    current = []
    for line in sql.splitlines():
        stripped = line.strip()
        if stripped.startswith("--") or stripped.upper().startswith("USE "):
            continue
        current.append(line)
        if stripped.endswith(";"):
            stmt = "\n".join(current).strip()
            if stmt:
                statements.append(stmt)
            current = []

    conn = pymysql.connect(
        host=os.getenv("DB_HOST", "localhost"),
        port=int(os.getenv("DB_PORT", "3306")),
        user=os.getenv("DB_USER", "campus_ai_user"),
        password=os.getenv("DB_PASSWORD", "root"),
        database=os.getenv("DB_NAME", "campus_ai_db"),
        charset=os.getenv("DB_CHARSET", "utf8mb4"),
    )
    try:
        with conn.cursor() as cur:
            for stmt in statements:
                try:
                    cur.execute(stmt)
                    print(f"OK: {stmt[:80]}...")
                except pymysql.err.OperationalError as e:
                    if e.args[0] == 1060:  # Duplicate column
                        print(f"SKIP (already applied): {stmt[:60]}...")
                    elif e.args[0] == 1061:  # Duplicate key name
                        print(f"SKIP (index exists): {stmt[:60]}...")
                    else:
                        raise
        conn.commit()
        print("Migration complete")
    finally:
        conn.close()


if __name__ == "__main__":
    main()
