from __future__ import annotations

from sqlalchemy import Integer, Text, DateTime, ForeignKey, func
from sqlalchemy.orm import Mapped, mapped_column

from .base import Base
from sqlalchemy import String 

class Feedback(Base):
    __tablename__ = "feedback"

    feedback_id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    session_id: Mapped[int] = mapped_column(
        ForeignKey("web_sessions.session_id", ondelete="CASCADE"), nullable=False
    )
    conversation_id: Mapped[int | None] = mapped_column(
        ForeignKey("conversations.conversation_id", ondelete="CASCADE"), nullable=True
    )
    message_id: Mapped[int | None] = mapped_column(
        ForeignKey("chat_messages.message_id", ondelete="CASCADE"), nullable=True
    )
    rating: Mapped[str | None] = mapped_column(String(20))
    comment: Mapped[str | None] = mapped_column(Text)
    category: Mapped[str | None] = mapped_column(String(50))
    created_at: Mapped[object] = mapped_column(DateTime(timezone=False), server_default=func.now())


class MessageReaction(Base):
    __tablename__ = "message_reactions"

    reaction_id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    message_id: Mapped[int] = mapped_column(
        ForeignKey("chat_messages.message_id", ondelete="CASCADE"), nullable=False
    )
    session_id: Mapped[int] = mapped_column(
        ForeignKey("web_sessions.session_id", ondelete="CASCADE"), nullable=False
    )
    reaction_type: Mapped[str] = mapped_column(String(20), nullable=False)
    created_at: Mapped[object] = mapped_column(DateTime(timezone=False), server_default=func.now())

