from __future__ import annotations

from sqlalchemy import Boolean, DateTime, Float, ForeignKey, Integer, String, Text, func
from sqlalchemy.orm import Mapped, mapped_column, relationship

from .base import Base


class ChatMessage(Base):
    """Chat message model - stores individual messages"""
    __tablename__ = "chat_messages"

    message_id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    conversation_id: Mapped[int] = mapped_column(
        ForeignKey("conversations.conversation_id", ondelete="CASCADE"), nullable=False
    )
    session_id: Mapped[int] = mapped_column(
        ForeignKey("web_sessions.session_id", ondelete="CASCADE"), nullable=False
    )
    sender_type: Mapped[str] = mapped_column(String(10), nullable=False)
    user_message: Mapped[str | None] = mapped_column(Text)
    bot_response: Mapped[str | None] = mapped_column(Text)
    intent_classification: Mapped[str] = mapped_column(String(32), default="general_campus")
    response_type: Mapped[str] = mapped_column(String(32), default="rag_based")
    model_used: Mapped[str | None] = mapped_column(String(100))
    response_time_ms: Mapped[float] = mapped_column(Float, default=0)
    context_retrieved: Mapped[bool] = mapped_column(Boolean, default=False)
    retrieval_doc_count: Mapped[int] = mapped_column(Integer, default=0)
    confidence_score: Mapped[float] = mapped_column(Float, default=0)
    was_helpful: Mapped[bool | None] = mapped_column(Boolean, nullable=True)
    parent_message_id: Mapped[int | None] = mapped_column(
        ForeignKey("chat_messages.message_id", ondelete="SET NULL")
    )
    user_timestamp: Mapped[object] = mapped_column(DateTime(timezone=False), server_default=func.now())
    bot_timestamp: Mapped[object | None] = mapped_column(DateTime(timezone=False), nullable=True)
    created_at: Mapped[object] = mapped_column(DateTime(timezone=False), server_default=func.now())

    conversation = relationship("Conversation", backref="chat_messages")
