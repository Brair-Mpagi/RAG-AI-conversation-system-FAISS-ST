#!/usr/bin/env python3
"""
DEPRECATED — use the production indexer instead.

Rebuilds FAISS via backend.utils.indexer.rebuild_faiss_index (same path as
POST /api/v1/admin/reindex_kb). This avoids divergent chunk sizes (old 600/100).

For post-scrape automation use: scripts/post_scrape_pipeline.py
"""

from __future__ import annotations

import logging
import sys
from pathlib import Path

BACKEND_DIR = Path(__file__).resolve().parent.parent / "backend"
sys.path.insert(0, str(BACKEND_DIR))

logging.basicConfig(level=logging.INFO, format="%(asctime)s | %(levelname)s | %(message)s")
logger = logging.getLogger(__name__)


def main() -> int:
    logger.warning(
        "build_vector_store.py is deprecated. Using rebuild_faiss_index() "
        "(entities + campus_knowledge_items + scraped content)."
    )
    from databases.session import SessionLocal
    from utils.indexer import rebuild_faiss_index

    db = SessionLocal()
    try:
        summary = rebuild_faiss_index(db)
        logger.info("Index rebuild complete: %s", summary)
        return 0 if summary.get("faiss_built") else 1
    finally:
        db.close()


if __name__ == "__main__":
    raise SystemExit(main())
