from __future__ import annotations

from sqlalchemy import DateTime, Float, ForeignKey, Integer, String, func
from sqlalchemy.orm import Mapped, mapped_column

from .base import Base


class Conversation(Base):
    """Conversation model - tracks chat sessions"""
    __tablename__ = "conversations"

    conversation_id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    session_id: Mapped[int] = mapped_column(ForeignKey("web_sessions.session_id", ondelete="CASCADE"))
    conversation_title: Mapped[str | None] = mapped_column(String(255))
    started_at: Mapped[object] = mapped_column(DateTime(timezone=False), server_default=func.now())
    ended_at: Mapped[object | None] = mapped_column(DateTime(timezone=False), nullable=True)
    status: Mapped[str] = mapped_column(String(20), default="active")
    total_messages: Mapped[int] = mapped_column(Integer, default=0)
    user_messages_count: Mapped[int] = mapped_column(Integer, default=0)
    bot_messages_count: Mapped[int] = mapped_column(Integer, default=0)
    avg_response_time_ms: Mapped[float] = mapped_column(Float, default=0)
    created_at: Mapped[object] = mapped_column(DateTime(timezone=False), server_default=func.now())
    updated_at: Mapped[object] = mapped_column(DateTime(timezone=False), server_default=func.now(), onupdate=func.now())
