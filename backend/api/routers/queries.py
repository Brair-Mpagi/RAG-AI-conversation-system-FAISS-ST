from __future__ import annotations

import secrets
from typing import Optional

from fastapi import APIRouter, Depends, Request, status, HTTPException
from pydantic import BaseModel, EmailStr
from sqlalchemy import text
from sqlalchemy.orm import Session

from databases.session import get_db
from models.web_session import WebSession


router = APIRouter(prefix="/api/v1/queries", tags=["queries"])


class ForwardQueryRequest(BaseModel):
    username: Optional[str] = None
    email: Optional[EmailStr] = None
    query: str
    session_id: Optional[int] = None
    conversation_id: Optional[int] = None
    message_id: Optional[int] = None
    interface_type: Optional[str] = "web"


class ForwardQueryResponse(BaseModel):
    status: str
    query_id: int


@router.post("/forward", response_model=ForwardQueryResponse, status_code=status.HTTP_201_CREATED)
def forward_query(payload: ForwardQueryRequest, request: Request, db: Session = Depends(get_db)) -> ForwardQueryResponse:
    """Forward a user query to the admin panel for manual handling"""
    if not payload.query or not payload.query.strip():
        raise HTTPException(status_code=400, detail="Query text is required")

    # Use session_id directly, no need for user_id/guest_id
    session_id = payload.session_id

    # Insert into user_queries table
    ins = text(
        """
        INSERT INTO user_queries (session_id, conversation_id, message_id, query_text, query_type, priority, status, submitted_at, user_name, user_email)
        VALUES (:session_id, :conversation_id, :message_id, :query_text, :query_type, :priority, :status, NOW(), :user_name, :user_email)
        """
    )
    params = {
        "session_id": session_id,
        "conversation_id": payload.conversation_id,
        "message_id": payload.message_id,
        "query_text": payload.query.strip(),
        "query_type": "general",
        "priority": "medium",
        "status": "pending",
        "user_name": payload.username,
        "user_email": payload.email,
    }
    res = db.execute(ins, params)
    db.commit()

    query_id = res.lastrowid if hasattr(res, "lastrowid") else 0
    return ForwardQueryResponse(status="ok", query_id=int(query_id))
