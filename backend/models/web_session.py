from __future__ import annotations

from sqlalchemy import DateTime, Integer, String, func
from sqlalchemy.orm import Mapped, mapped_column

from .base import Base


class WebSession(Base):
    """Web session model matching the web_sessions table in schema.sql"""
    __tablename__ = "web_sessions"

    session_id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    session_token: Mapped[str] = mapped_column(String(255), unique=True, nullable=False)
    
    # Device and browser info
    device_type: Mapped[str | None] = mapped_column(String(50))
    device_model: Mapped[str | None] = mapped_column(String(100))
    device_brand: Mapped[str | None] = mapped_column(String(50))
    os_name: Mapped[str | None] = mapped_column(String(50))
    os_version: Mapped[str | None] = mapped_column(String(50))
    browser_name: Mapped[str | None] = mapped_column(String(50))
    browser_version: Mapped[str | None] = mapped_column(String(50))
    screen_resolution: Mapped[str | None] = mapped_column(String(20))
    
    # Location and network
    ip_address: Mapped[str | None] = mapped_column(String(45))
    location: Mapped[str | None] = mapped_column(String(255))
    
    # Session metadata
    interface_type: Mapped[str] = mapped_column(String(10), nullable=False, default="web")
    start_time: Mapped[object] = mapped_column(DateTime(timezone=False), server_default=func.now())
    end_time: Mapped[object | None] = mapped_column(DateTime(timezone=False), nullable=True)
    duration_seconds: Mapped[int] = mapped_column(Integer, default=0)
    status: Mapped[str] = mapped_column(String(20), default="active")
    total_messages_sent: Mapped[int] = mapped_column(Integer, default=0)
    total_messages_received: Mapped[int] = mapped_column(Integer, default=0)
    
    # Timestamps
    created_at: Mapped[object] = mapped_column(DateTime(timezone=False), server_default=func.now())
    updated_at: Mapped[object] = mapped_column(DateTime(timezone=False), server_default=func.now(), onupdate=func.now())

