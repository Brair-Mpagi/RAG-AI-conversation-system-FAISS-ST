"""Admin authentication endpoint (SEC-01 / AUTH-01).

POST /api/v1/auth/login  →  returns a short-lived JWT bearer token.
The token must be presented as `Authorization: Bearer <token>` to access
any protected admin route (e.g. /api/v1/admin/reindex_kb).
"""

from __future__ import annotations

import logging

from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel
from sqlalchemy import text
from sqlalchemy.orm import Session

from databases.session import get_db
from utils.auth import create_access_token
from utils.security import verify_password

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/api/v1/auth", tags=["auth"])


class LoginRequest(BaseModel):
    username: str
    password: str


class TokenResponse(BaseModel):
    access_token: str
    token_type: str = "bearer"


@router.post("/login", response_model=TokenResponse, status_code=status.HTTP_200_OK)
def admin_login(payload: LoginRequest, db: Session = Depends(get_db)) -> TokenResponse:
    """Authenticate an admin user and return a JWT bearer token.

    Deliberately returns a generic 401 for both "user not found" and
    "wrong password" to prevent username-enumeration attacks.
    """
    _INVALID = HTTPException(
        status_code=status.HTTP_401_UNAUTHORIZED,
        detail="Invalid credentials",
        headers={"WWW-Authenticate": "Bearer"},
    )

    row = db.execute(
        text(
            "SELECT password_hash, role FROM admin_users "
            "WHERE username = :username AND is_active = 1 LIMIT 1"
        ),
        {"username": payload.username.strip()},
    ).first()

    if not row:
        logger.warning("Admin login attempt for unknown user: %s", payload.username)
        raise _INVALID

    password_hash, role = row[0], row[1] or "admin"

    if not verify_password(payload.password, password_hash):
        logger.warning("Admin login failed (bad password) for user: %s", payload.username)
        raise _INVALID

    token = create_access_token({"sub": payload.username, "role": role})
    logger.info("Admin login successful for user: %s", payload.username)
    return TokenResponse(access_token=token)
