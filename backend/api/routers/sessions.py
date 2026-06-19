from __future__ import annotations

import logging
import os
from typing import Optional

from fastapi import APIRouter, BackgroundTasks, Depends, HTTPException, Request, status
from pydantic import BaseModel
from sqlalchemy.orm import Session
from sqlalchemy import func, update, text
from user_agents import parse as parse_ua

from core.config import settings
from databases.session import get_db
from models.web_session import WebSession

logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# GeoIP — optional; gracefully skips if the .mmdb file is absent
# ---------------------------------------------------------------------------
_geoip_reader = None
_GEOIP_DB_PATHS = [
    os.path.join(os.path.dirname(__file__), "..", "..", "data", "GeoLite2-City.mmdb"),
    "/usr/share/GeoIP/GeoLite2-City.mmdb",
    os.path.expanduser("~/GeoLite2-City.mmdb"),
]

try:
    import geoip2.database  # type: ignore
    for _p in _GEOIP_DB_PATHS:
        _p = os.path.abspath(_p)
        if os.path.isfile(_p):
            _geoip_reader = geoip2.database.Reader(_p)
            logger.info("GeoIP loaded from %s", _p)
            break
    if _geoip_reader is None:
        logger.info("GeoIP: no .mmdb file found — location lookup disabled")
except ImportError:
    logger.info("geoip2 not installed — location lookup disabled")


def _geoip_lookup(ip: str) -> str | None:
    """Resolve an IP address to 'City, Country' or None (fast, local DB)."""
    if _geoip_reader is None:
        return None
    if ip.startswith(("127.", "10.", "192.168.", "172.", "::1", "0.0.0.0")):
        return None
    try:
        resp = _geoip_reader.city(ip)
        parts = [resp.city.name, resp.country.name]
        return ", ".join(p for p in parts if p) or None
    except Exception:
        return None


def _ip_api_lookup(ip: str) -> str | None:
    """Fallback IP geolocation using ip-api.com (free, no key required).

    NOTE: this makes an external HTTP call. Call it only from a
    BackgroundTask so it never blocks a request handler.
    """
    import requests as _requests
    if ip.startswith(("127.", "10.", "192.168.", "172.", "::1", "0.0.0.0")):
        return None
    try:
        resp = _requests.get(
            f"http://ip-api.com/json/{ip}?fields=city,country",
            timeout=3,
        )
        if resp.status_code == 200:
            data = resp.json()
            parts = [data.get("city"), data.get("country")]
            name = ", ".join(p for p in parts if p)
            return name or None
    except Exception:
        pass
    return None


def _enrich_session_location(session_id: int, ip: str) -> None:
    """Background task: look up geolocation and update the session row.

    Runs after the HTTP response has already been sent so the client
    never waits for the external ip-api.com call (ARCH-02).
    """
    location = _ip_api_lookup(ip)
    if not location:
        return
    try:
        from databases.session import SessionLocal
        db = SessionLocal()
        db.execute(
            update(WebSession)
            .where(WebSession.session_id == session_id)
            .values(location=location[:255])
        )
        db.commit()
        db.close()
    except Exception:
        pass


def _get_client_ip(request: Request) -> str:
    """Extract client IP, honouring X-Forwarded-For only when behind a
    trusted proxy (SEC-06 — prevents IP spoofing via header injection).
    """
    if settings.BEHIND_PROXY:
        xff = request.headers.get("x-forwarded-for", "")
        if xff:
            # Take only the first (leftmost) IP — that is the actual client
            return xff.split(",")[0].strip()
    return request.client.host


router = APIRouter(prefix="/api/v1/sessions", tags=["sessions"])


class SessionStartRequest(BaseModel):
    interface_type: str = "web"
    device_type: Optional[str] = None
    device_model: Optional[str] = None
    device_brand: Optional[str] = None
    os_name: Optional[str] = None
    os_version: Optional[str] = None
    browser_name: Optional[str] = None
    browser_version: Optional[str] = None
    screen_resolution: Optional[str] = None
    location: Optional[str] = None


class SessionStartResponse(BaseModel):
    session_id: int
    session_token: str


@router.post("/start", response_model=SessionStartResponse, status_code=status.HTTP_201_CREATED)
def start_session(
    payload: SessionStartRequest,
    request: Request,
    background_tasks: BackgroundTasks,
    db: Session = Depends(get_db),
) -> SessionStartResponse:
    """Create a new web session for anonymous users."""
    import secrets
    client_ip = _get_client_ip(request)  # SEC-06: trusted proxy gate
    token = secrets.token_urlsafe(32)

    def clip(val: str | None, max_len: int) -> str | None:
        return str(val)[:max_len] if val is not None else None

    # ── Server-side User-Agent parsing ──
    ua_string = request.headers.get("user-agent", "")
    ua = parse_ua(ua_string)

    device_type = clip(payload.device_type, 50) or (
        "Mobile" if ua.is_mobile else "Tablet" if ua.is_tablet else "PC" if ua.is_pc else "Bot" if ua.is_bot else None
    )
    device_brand = clip(payload.device_brand, 50) or clip(ua.device.brand, 50)
    device_model = clip(payload.device_model, 100) or clip(ua.device.model, 100)
    os_name = clip(ua.os.family, 50) or clip(payload.os_name, 50)
    os_version = clip(ua.os.version_string, 50) or clip(payload.os_version, 50)
    browser_name = clip(ua.browser.family, 50) or clip(payload.browser_name, 50)
    browser_version = clip(ua.browser.version_string, 50) or clip(payload.browser_version, 50)

    # Fast local GeoIP lookup (no external call)
    location = clip(payload.location, 255) or _geoip_lookup(client_ip)

    # Create web session
    session = WebSession(
        session_token=token,
        interface_type=payload.interface_type or "web",
        device_type=device_type,
        device_model=device_model,
        device_brand=device_brand,
        os_name=os_name,
        os_version=os_version,
        browser_name=browser_name,
        browser_version=browser_version,
        screen_resolution=clip(payload.screen_resolution, 20),
        ip_address=client_ip,
        location=location,
    )
    db.add(session)
    db.commit()
    db.refresh(session)

    # ARCH-02: If no local location resolved, kick off the external HTTP
    # lookup as a background task so it doesn't block the response.
    if not location:
        background_tasks.add_task(_enrich_session_location, session.session_id, client_ip)

    return SessionStartResponse(session_id=session.session_id, session_token=session.session_token)


class HeartbeatRequest(BaseModel):
    session_id: int
    session_token: str   # SEC-04: token required to prove session ownership


@router.post("/heartbeat", status_code=status.HTTP_200_OK)
def heartbeat(payload: HeartbeatRequest, db: Session = Depends(get_db)):
    """Update last active time for a session.

    The session_token must match the one issued at /sessions/start (SEC-04).
    """
    session = db.query(WebSession).filter(
        WebSession.session_id == payload.session_id,
        WebSession.session_token == payload.session_token,  # ← token validation
    ).first()

    if not session:
        raise HTTPException(status_code=404, detail="Session not found")

    db.execute(
        update(WebSession)
        .where(WebSession.session_id == payload.session_id)
        .values(updated_at=func.now())
    )
    db.commit()
    return {"status": "ok", "message": "Heartbeat registered"}


@router.post("/expire", status_code=status.HTTP_200_OK)
def expire_sessions(db: Session = Depends(get_db)):
    """Mark sessions that have been inactive for >5 minutes as 'timeout'.

    Also populates end_time and duration_seconds so Avg Session Duration
    on the dashboard reflects real data.
    """
    result = db.execute(
        text("""
            UPDATE web_sessions
            SET
                status           = 'timeout',
                end_time         = NOW(),
                duration_seconds = GREATEST(0, TIMESTAMPDIFF(SECOND, start_time, NOW()))
            WHERE status = 'active'
              AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        """)
    )
    db.commit()
    expired = result.rowcount
    return {"status": "ok", "expired": expired}
