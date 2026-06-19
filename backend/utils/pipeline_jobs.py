"""Background KB pipeline jobs (enrich → reindex) with progress and cancel."""

from __future__ import annotations

import json
import logging
import threading
import time
import uuid
from pathlib import Path
from typing import Any, Dict, Optional

from core.config import settings

logger = logging.getLogger(__name__)

_JOB_PATH = Path(settings.FAISS_METADATA_PATH).parent / "pipeline_job.json"
_lock = threading.Lock()
_thread: Optional[threading.Thread] = None


def _read_job() -> Dict[str, Any]:
    if not _JOB_PATH.exists():
        return {"status": "idle"}
    try:
        with _JOB_PATH.open("r", encoding="utf-8") as f:
            return json.load(f)
    except Exception:
        return {"status": "idle"}


def _write_job(data: Dict[str, Any]) -> None:
    _JOB_PATH.parent.mkdir(parents=True, exist_ok=True)
    data["updated_at"] = time.time()
    with _JOB_PATH.open("w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)


def get_job_status() -> Dict[str, Any]:
    with _lock:
        job = _read_job()
    if job.get("status") == "running":
        initial = job.get("initial_total_pending") or job.get("total_pending", 0)
        done = job.get("enriched", 0) + job.get("failed", 0)
        if initial > 0:
            job["progress_pct"] = min(99, int(100 * done / initial))
        elif job.get("phase") == "reindex":
            job["progress_pct"] = 95
    elif job.get("status") == "done":
        job["progress_pct"] = 100
    else:
        job.setdefault("progress_pct", 0)
    return job


def request_cancel() -> Dict[str, Any]:
    with _lock:
        job = _read_job()
        if job.get("status") != "running":
            return {"status": "ok", "message": "No running job"}
        job["cancel_requested"] = True
        _write_job(job)
    return {"status": "ok", "message": "Cancel requested"}


def is_cancel_requested() -> bool:
    return bool(_read_job().get("cancel_requested"))


def _is_cancelled() -> bool:
    return is_cancel_requested()


def _run_pipeline(job_id: str, *, enrich: bool, reindex: bool, batch_size: int, max_rounds: int) -> None:
    from databases.session import SessionLocal
    from utils.enrichment_runner import enrich_all_pending, enrich_scraped_content, get_enrichment_status
    from utils.indexer import rebuild_faiss_index

    db = SessionLocal()
    try:
        if enrich:
            status = get_enrichment_status(db)
            total_pending = int(status.get("pending", 0))
            initial_total = total_pending
            _write_job({
                "job_id": job_id,
                "status": "running",
                "phase": "enrich",
                "enriched": 0,
                "failed": 0,
                "processed": 0,
                "rounds": 0,
                "total_pending": total_pending,
                "initial_total_pending": initial_total,
                "cancel_requested": False,
            })

            for round_i in range(max_rounds):
                if _is_cancelled():
                    _write_job({**_read_job(), "status": "cancelled", "phase": "cancelled"})
                    return

                if total_pending <= 0:
                    break

                summary = enrich_scraped_content(
                    db,
                    only_pending=True,
                    limit=batch_size,
                    use_llm=True,
                )
                job = _read_job()
                job["rounds"] = round_i + 1
                job["enriched"] = job.get("enriched", 0) + summary.get("enriched", 0)
                job["failed"] = job.get("failed", 0) + summary.get("failed", 0)
                job["processed"] = job.get("processed", 0) + summary.get("processed", 0)
                status = get_enrichment_status(db)
                total_pending = int(status.get("pending", 0))
                job["total_pending"] = total_pending
                job["remaining_pending"] = total_pending
                _write_job(job)

                if summary.get("processed", 0) == 0:
                    break

        if _is_cancelled():
            _write_job({**_read_job(), "status": "cancelled"})
            return

        if reindex:
            job = _read_job()
            job["phase"] = "reindex"
            job["status"] = "running"
            _write_job(job)
            index_summary = rebuild_faiss_index(db)
            job = _read_job()
            job["index"] = index_summary
            job["status"] = "done"
            job["phase"] = "done"
            job["progress_pct"] = 100
            _write_job(job)
        else:
            job = _read_job()
            job["status"] = "done"
            job["phase"] = "done"
            job["progress_pct"] = 100
            _write_job(job)
    except Exception as exc:
        logger.exception("Pipeline job failed: %s", exc)
        _write_job({
            **_read_job(),
            "status": "error",
            "detail": str(exc),
        })
    finally:
        db.close()


def start_pipeline(
    *,
    enrich: bool = True,
    reindex: bool = True,
    batch_size: int | None = None,
    max_rounds: int = 200,
    scraped_ids: list[int] | None = None,
) -> Dict[str, Any]:
    """Start background enrich/reindex pipeline (single worker)."""
    global _thread

    with _lock:
        current = _read_job()
        if current.get("status") == "running" and _thread and _thread.is_alive():
            return {"status": "error", "detail": "A pipeline job is already running", "job": current}

        job_id = str(uuid.uuid4())[:8]
        batch = batch_size or settings.ENRICHMENT_BATCH_LIMIT

        if scraped_ids:
            def _run_selected():
                from databases.session import SessionLocal
                from utils.enrichment_runner import enrich_scraped_content
                from utils.indexer import rebuild_faiss_index
                db = SessionLocal()
                try:
                    _write_job({
                        "job_id": job_id,
                        "status": "running",
                        "phase": "enrich",
                        "total_pending": len(scraped_ids),
                        "enriched": 0,
                        "failed": 0,
                        "cancel_requested": False,
                    })
                    summary = enrich_scraped_content(
                        db, scraped_ids=scraped_ids, only_pending=False,
                        limit=len(scraped_ids), use_llm=True,
                    )
                    job = _read_job()
                    job.update(summary)
                    if reindex and not _is_cancelled():
                        job["phase"] = "reindex"
                        _write_job(job)
                        job["index"] = rebuild_faiss_index(db)
                    job["status"] = "done" if not _is_cancelled() else "cancelled"
                    job["progress_pct"] = 100
                    _write_job(job)
                except Exception as exc:
                    _write_job({**_read_job(), "status": "error", "detail": str(exc)})
                finally:
                    db.close()

            _thread = threading.Thread(target=_run_selected, daemon=True)
            _thread.start()
            return {"status": "ok", "job_id": job_id, "mode": "selected", "count": len(scraped_ids)}

        _write_job({
            "job_id": job_id,
            "status": "running",
            "phase": "starting",
            "enriched": 0,
            "failed": 0,
            "processed": 0,
            "rounds": 0,
            "cancel_requested": False,
        })
        _thread = threading.Thread(
            target=_run_pipeline,
            args=(job_id,),
            kwargs={
                "enrich": enrich,
                "reindex": reindex,
                "batch_size": batch,
                "max_rounds": max_rounds,
            },
            daemon=True,
        )
        _thread.start()
        return {"status": "ok", "job_id": job_id, "mode": "full"}


def run_post_scrape_pipeline_sync(
    *,
    enrich: bool = True,
    reindex: bool = True,
    max_rounds: int = 50,
) -> Dict[str, Any]:
    """Synchronous pipeline for cron/CLI (no background thread)."""
    from databases.session import SessionLocal
    from utils.enrichment_runner import enrich_all_pending
    from utils.indexer import rebuild_faiss_index

    db = SessionLocal()
    out: Dict[str, Any] = {"status": "ok"}
    try:
        if enrich:
            out["enrichment"] = enrich_all_pending(
                db, batch_size=settings.ENRICHMENT_BATCH_LIMIT, max_rounds=max_rounds,
            )
        if reindex:
            out["index"] = rebuild_faiss_index(db)
    except Exception as exc:
        out = {"status": "error", "detail": str(exc)}
    finally:
        db.close()
    return out
