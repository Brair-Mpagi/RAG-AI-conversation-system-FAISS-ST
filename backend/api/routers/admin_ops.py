"""Admin operations router — knowledge-base rebuild and config reload.

All routes in this router require a valid admin JWT token.
Obtain one via: POST /api/v1/auth/login
"""

from __future__ import annotations

from typing import List, Optional

from fastapi import APIRouter, Depends, UploadFile, File, Query
from pydantic import BaseModel, Field
from sqlalchemy.orm import Session

from core.config import settings
from databases.session import get_db
from utils.auth import require_admin
from utils.enrichment_runner import (
    enrich_all_pending,
    enrich_scraped_content,
    get_enrichment_status,
)
from utils.indexer import rebuild_faiss_index
from utils.pipeline_jobs import get_job_status, request_cancel, start_pipeline

router = APIRouter(prefix="/api/v1/admin", tags=["admin"])


class EnrichKbRequest(BaseModel):
    scraped_ids: Optional[List[int]] = None
    only_pending: bool = True
    limit: int = Field(default=100, ge=1, le=200)
    use_llm: bool = True
    max_rounds: int = Field(default=100, ge=1, le=500)


@router.post("/enrich_kb")
def enrich_kb(
    body: EnrichKbRequest | None = None,
    db: Session = Depends(get_db),
    _admin: dict = Depends(require_admin),
) -> dict:
    """Run LLM + rule enrichment on scraped pages (pending/failed or selected IDs)."""
    req = body or EnrichKbRequest()
    limit = min(req.limit, settings.ENRICHMENT_BATCH_LIMIT)
    summary = enrich_scraped_content(
        db,
        scraped_ids=req.scraped_ids,
        only_pending=req.only_pending,
        limit=limit,
        use_llm=req.use_llm,
    )
    return summary


@router.get("/enrich_kb/status")
def enrich_kb_status(
    db: Session = Depends(get_db),
    _admin: dict = Depends(require_admin),
) -> dict:
    """Pending/done/failed enrichment counts for admin dashboard."""
    return get_enrichment_status(db)


@router.post("/enrich_all_kb")
def enrich_all_kb(
    body: EnrichKbRequest | None = None,
    db: Session = Depends(get_db),
    _admin: dict = Depends(require_admin),
) -> dict:
    """Enrich all pending/failed pages in repeated batches."""
    req = body or EnrichKbRequest()
    batch = min(req.limit, settings.ENRICHMENT_BATCH_LIMIT)
    return enrich_all_pending(
        db,
        batch_size=batch,
        max_rounds=req.max_rounds,
        use_llm=req.use_llm,
    )


@router.post("/enrich_and_reindex_kb")
def enrich_and_reindex_kb(
    body: EnrichKbRequest | None = None,
    db: Session = Depends(get_db),
    _admin: dict = Depends(require_admin),
) -> dict:
    """Enrich pending scraped pages then rebuild the FAISS index."""
    req = body or EnrichKbRequest()
    if req.scraped_ids:
        enrich_summary = enrich_scraped_content(
            db,
            scraped_ids=req.scraped_ids,
            only_pending=False,
            limit=min(req.limit, settings.ENRICHMENT_BATCH_LIMIT),
            use_llm=req.use_llm,
        )
    else:
        enrich_summary = enrich_all_pending(
            db,
            batch_size=min(req.limit, settings.ENRICHMENT_BATCH_LIMIT),
            max_rounds=req.max_rounds,
            use_llm=req.use_llm,
        )
    index_summary = rebuild_faiss_index(db)
    return {"status": "ok", "enrichment": enrich_summary, "index": index_summary}


@router.post("/reindex_kb")
def reindex_kb(
    db: Session = Depends(get_db),
    _admin: dict = Depends(require_admin),
) -> dict:
    """Rebuild the FAISS knowledge-base index from current DB content.

    Requires: Authorization: Bearer <admin_token>
    """
    summary = rebuild_faiss_index(db)
    return {"status": "ok", **summary}


class PipelineStartRequest(BaseModel):
    enrich: bool = True
    reindex: bool = True
    scraped_ids: Optional[List[int]] = None
    batch_size: int = Field(default=100, ge=1, le=200)
    max_rounds: int = Field(default=200, ge=1, le=500)


@router.get("/pipeline/status")
def pipeline_status(
    _admin: dict = Depends(require_admin),
) -> dict:
    """Poll background enrich/reindex job progress."""
    return get_job_status()


    @router.post("/import_enriched")
    def import_enriched(
        file: UploadFile = File(...),
        preview: bool = Query(False),
        db: Session = Depends(get_db),
        _admin: dict = Depends(require_admin),
    ) -> dict:
        """Import enriched rows from an Excel/CSV/JSON file.
        If `preview` is true, the endpoint returns a diff of changes without
        mutating the database.
        """
        from utils.import_enriched import import_enriched_file
        return import_enriched_file(file, db, preview=preview)



@router.post("/pipeline/start")
def pipeline_start(
    body: PipelineStartRequest | None = None,
    _admin: dict = Depends(require_admin),
) -> dict:
    """Start background enrich → reindex pipeline with progress tracking."""
    req = body or PipelineStartRequest()
    return start_pipeline(
        enrich=req.enrich,
        reindex=req.reindex,
        batch_size=min(req.batch_size, settings.ENRICHMENT_BATCH_LIMIT),
        max_rounds=req.max_rounds,
        scraped_ids=req.scraped_ids,
    )


@router.post("/reload-config")
def reload_config(
    _admin: dict = Depends(require_admin),
) -> dict:
    """Bust the in-process config cache so new entity codes/names are used
    immediately without restarting the server.

    Requires: Authorization: Bearer <admin_token>
    """
    try:
        from utils import db_config
        counts = db_config.reload_all()
        return {"status": "ok", **counts}
    except Exception as exc:
        return {"status": "error", "detail": str(exc)}
