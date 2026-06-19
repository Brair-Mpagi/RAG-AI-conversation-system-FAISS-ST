#!/usr/bin/env python3
"""Post-scrape pipeline: enrich pending pages + rebuild FAISS index.

Used by scraper cron, AJAX hook, and manual CLI.

Examples:
  python scripts/post_scrape_pipeline.py
  python scripts/post_scrape_pipeline.py --background
  python scripts/post_scrape_pipeline.py --skip-enrich
"""

from __future__ import annotations

import argparse
import subprocess
import sys
from pathlib import Path

BACKEND_DIR = Path(__file__).resolve().parent.parent / "backend"
sys.path.insert(0, str(BACKEND_DIR))


def main() -> int:
    parser = argparse.ArgumentParser(description="Run enrich + reindex after scraping")
    parser.add_argument("--background", action="store_true", help="Spawn detached background process")
    parser.add_argument("--skip-enrich", action="store_true", help="Only rebuild FAISS index")
    parser.add_argument("--skip-reindex", action="store_true", help="Only run enrichment")
    parser.add_argument("--max-rounds", type=int, default=80, help="Max enrich batches (cron)")
    args = parser.parse_args()

    if args.background:
        cmd = [sys.executable, str(Path(__file__).resolve()), "--max-rounds", str(args.max_rounds)]
        if args.skip_enrich:
            cmd.append("--skip-enrich")
        if args.skip_reindex:
            cmd.append("--skip-reindex")
        subprocess.Popen(
            cmd,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            start_new_session=True,
        )
        print('{"status":"ok","message":"Pipeline started in background"}')
        return 0

    from utils.pipeline_jobs import run_post_scrape_pipeline_sync

    result = run_post_scrape_pipeline_sync(
        enrich=not args.skip_enrich,
        reindex=not args.skip_reindex,
        max_rounds=args.max_rounds,
    )
    print(result)
    return 0 if result.get("status") == "ok" else 1


if __name__ == "__main__":
    raise SystemExit(main())
