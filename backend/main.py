from __future__ import annotations

import logging
from contextlib import asynccontextmanager

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from core.mmu_rules import MMURuleMiddleware

from core.config import settings
from core.logging_config import setup_logging
from api.routers import health, chat
from api.routers import sessions
from api.routers import admin_ops
from api.routers import queries
from api.routers import feedback
from api.routers import entities
from api.routers import auth as auth_router
from databases.session import engine


# ---------------------------------------------------------------------------
# Lifespan (replaces deprecated @app.on_event) — ENT-06 / CODE-07
# ---------------------------------------------------------------------------

@asynccontextmanager
async def lifespan(app: FastAPI):
    # ── Startup ──
    setup_logging(settings.LOG_LEVEL, settings.LOG_FILE)
    _log = logging.getLogger(__name__)
    _log.info(
        "Backend starting - env=%s, host=%s, port=%s",
        settings.ENVIRONMENT, settings.HOST, settings.PORT,
    )
    try:
        from utils.db_logger import log_to_db
        log_to_db(
            "info", "system",
            f"Backend started - env={settings.ENVIRONMENT}, host={settings.HOST}, port={settings.PORT}",
        )
    except Exception:
        pass

    yield  # ← application runs here

    # ── Shutdown (graceful) ──
    _log.info("Backend shutting down — disposing DB connection pool")
    try:
        engine.dispose()
    except Exception:
        pass
    try:
        from utils.db_logger import log_to_db
        log_to_db("info", "system", "Backend shutdown complete")
    except Exception:
        pass


# ---------------------------------------------------------------------------
# App
# ---------------------------------------------------------------------------

app = FastAPI(
    title="Campus Query AI Assistant Backend",
    version="0.1.0",
    lifespan=lifespan,
)

# MMU rules enforcement middleware
app.add_middleware(MMURuleMiddleware)

# ---------------------------------------------------------------------------
# CORS (SEC-03) — wildcard ONLY in non-production; never gated on DEBUG
# ---------------------------------------------------------------------------
if settings.ENVIRONMENT != "production":
    app.add_middleware(
        CORSMiddleware,
        allow_origins=[],           # use regex instead to support credentials
        allow_origin_regex=".*",
        allow_credentials=True,
        allow_methods=["*"],
        allow_headers=["*"],
    )
else:
    app.add_middleware(
        CORSMiddleware,
        allow_origins=settings.cors_origins_list or [],
        allow_credentials=True,
        allow_methods=["*"],
        allow_headers=["*"],
    )

# ---------------------------------------------------------------------------
# Routers
# ---------------------------------------------------------------------------
app.include_router(health.router)
app.include_router(auth_router.router)   # ← new: /api/v1/auth/login
app.include_router(chat.router)
app.include_router(sessions.router)
app.include_router(admin_ops.router)
app.include_router(queries.router)
app.include_router(feedback.router)
app.include_router(entities.router)


@app.get("/")
def root():
    return {"name": "Campus Query AI Assistant Backend", "status": "running"}


# ---------------------------------------------------------------------------
# Dev entrypoint
# ---------------------------------------------------------------------------
if __name__ == "__main__":
    import uvicorn
    uvicorn.run("main:app", host=settings.HOST, port=settings.PORT, reload=True)
