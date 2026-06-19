from __future__ import annotations

from sqlalchemy import Boolean, DateTime, Integer, String, Float, func
from sqlalchemy.orm import Mapped, mapped_column

from .base import Base


class UserSession(Base):
    __tablename__ = "user_sessions"

    session_id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    user_id: Mapped[int | None] = mapped_column(Integer, nullable=True)
    guest_id: Mapped[int | None] = mapped_column(Integer, nullable=True)
    session_token: Mapped[str] = mapped_column(String(255), unique=True, nullable=False)

    interface_type: Mapped[str] = mapped_column(String(10), nullable=False, default="web")
    access_mode: Mapped[str] = mapped_column(String(20), nullable=False, default="guest")

    device_type: Mapped[str | None] = mapped_column(String(50))
    device_model: Mapped[str | None] = mapped_column(String(100))
    device_brand: Mapped[str | None] = mapped_column(String(50))
    os_name: Mapped[str | None] = mapped_column(String(50))
    os_version: Mapped[str | None] = mapped_column(String(50))
    browser_name: Mapped[str | None] = mapped_column(String(50))
    browser_version: Mapped[str | None] = mapped_column(String(50))

    screen_resolution: Mapped[str | None] = mapped_column(String(20))

    start_time: Mapped[object] = mapped_column(DateTime(timezone=False), server_default=func.now())
    end_time: Mapped[object | None] = mapped_column(DateTime(timezone=False), nullable=True)
    duration_seconds: Mapped[int] = mapped_column(Integer, default=0)

    ip_address: Mapped[str | None] = mapped_column(String(45))
    location: Mapped[str | None] = mapped_column(String(255))

    status: Mapped[str] = mapped_column(String(20), default="active")

    total_messages_sent: Mapped[int] = mapped_column(Integer, default=0)
    total_messages_received: Mapped[int] = mapped_column(Integer, default=0)

    created_at: Mapped[object] = mapped_column(DateTime(timezone=False), server_default=func.now())
    updated_at: Mapped[object] = mapped_column(DateTime(timezone=False), server_default=func.now(), onupdate=func.now())
