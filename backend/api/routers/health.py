from fastapi import APIRouter
import time
import os

from databases.session import test_db_connection
from core.config import settings

router = APIRouter()
_started_at = time.time()


def _check_faiss() -> bool:
    """Return True if the FAISS index file exists on disk."""
    try:
        return os.path.isfile(settings.FAISS_INDEX_PATH)
    except Exception:
        return False


def _check_ollama() -> bool:
    """Return True if the Ollama API is reachable."""
    import requests
    try:
        resp = requests.get(f"{settings.OLLAMA_HOST}/api/version", timeout=2)
        return resp.status_code == 200
    except Exception:
        return False


@router.get("/health")
def health():
    """Dependency-aware health check (ENT-04).

    Returns the status of every downstream service so a load balancer or
    container orchestrator can detect partial degradation.
    """
    db_ok = test_db_connection()
    ollama_ok = _check_ollama()
    faiss_ok = _check_faiss()

    overall_status = "healthy" if (db_ok and faiss_ok) else "degraded"

    return {
        "status": overall_status,
        "uptime_seconds": round(time.time() - _started_at, 2),
        "environment": settings.ENVIRONMENT,
        "services": {
            "database": "ok" if db_ok else "unavailable",
            "ollama": "ok" if ollama_ok else "unavailable",
            "faiss_index": "ok" if faiss_ok else "missing",
        },
    }
