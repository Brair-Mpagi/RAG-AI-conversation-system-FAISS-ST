from __future__ import annotations

from sqlalchemy import Integer, String, Text, DateTime, ForeignKey, func, Boolean
from sqlalchemy.orm import Mapped, mapped_column, relationship

from .base import Base

class ChatLog(Base):
    __tablename__ = "chatlogs"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    user_id: Mapped[int | None] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))
    session_id: Mapped[str | None] = mapped_column(String(128))
    timestamp: Mapped[object] = mapped_column(DateTime(timezone=False), server_default=func.now())
    message: Mapped[str] = mapped_column(Text)
    source: Mapped[str] = mapped_column(String(32))  # 'user' | 'assistant'
    intent: Mapped[str | None] = mapped_column(String(64))
    response_id: Mapped[int | None] = mapped_column(ForeignKey("responses.id", ondelete="SET NULL"))

    user = relationship("User")
    response = relationship("Response")

class FAQCache(Base):
    __tablename__ = "faq_cache"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    question: Mapped[str] = mapped_column(Text, nullable=False)
    answer: Mapped[str] = mapped_column(Text, nullable=False)
    last_used: Mapped[object] = mapped_column(DateTime(timezone=False), server_default=func.now())
    usage_count: Mapped[int] = mapped_column(Integer, default=0)

class FAQFrequency(Base):
    __tablename__ = "faq_frequency"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    question: Mapped[str] = mapped_column(Text, nullable=False)
    frequency: Mapped[int] = mapped_column(Integer, default=0)
    last_asked: Mapped[object] = mapped_column(DateTime(timezone=False), server_default=func.now())

class PasswordReset(Base):
    __tablename__ = "password_resets"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    user_id: Mapped[int] = mapped_column(ForeignKey("users.id", ondelete="CASCADE"))
    token: Mapped[str] = mapped_column(String(128), nullable=False)
    created_at: Mapped[object] = mapped_column(DateTime(timezone=False), server_default=func.now())
    used_at: Mapped[object | None] = mapped_column(DateTime(timezone=False), nullable=True)
    status: Mapped[str] = mapped_column(String(32), default="pending")

    user = relationship("User")

class PushedQuery(Base):
    __tablename__ = "pushed_query"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    user_id: Mapped[int | None] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))
    query: Mapped[str] = mapped_column(Text, nullable=False)
    timestamp: Mapped[object] = mapped_column(DateTime(timezone=False), server_default=func.now())
    status: Mapped[str] = mapped_column(String(32), default="pending")

    user = relationship("User")

class Response(Base):
    __tablename__ = "responses"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    response_text: Mapped[str] = mapped_column(Text, nullable=False)
    category: Mapped[str | None] = mapped_column(String(64))
    created_at: Mapped[object] = mapped_column(DateTime(timezone=False), server_default=func.now())

class FAQInfoField(Base):
    __tablename__ = "faq_info_fields"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    keyword: Mapped[str] = mapped_column(String(128), unique=True, nullable=False)
    value: Mapped[str | None] = mapped_column(Text)
