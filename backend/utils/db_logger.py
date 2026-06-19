"""Database logger – writes to the `system_logs` table.

Provides a simple `log_to_db()` function that the chat pipeline, middleware,
and startup hooks can call to persist logs visible in the admin panel.
"""

from __future__ import annotations

import json
import logging
import traceback
from typing import Any

from databases.session import SessionLocal

logger = logging.getLogger(__name__)


def log_to_db(
    level: str,
    module: str,
    message: str,
    stack_trace: str | None = None,
    metadata: dict[str, Any] | None = None,
    ip_address: str | None = None,
) -> None:
    """Insert a row into system_logs. Will not raise on failure."""
    try:
        db = SessionLocal()
        meta_json = json.dumps(metadata) if metadata else None
        db.execute(
            __import__("sqlalchemy").text(
                "INSERT INTO system_logs (log_level, module, message, stack_trace, ip_address, metadata) "
                "VALUES (:level, :module, :msg, :trace, :ip, :meta)"
            ),
            {
                "level": level,
                "module": module[:50],
                "msg": message[:2000],
                "trace": (stack_trace or "")[:5000] or None,
                "ip": ip_address,
                "meta": meta_json,
            },
        )
        db.commit()
    except Exception:
        # Never let logging crash the application
        logger.debug("db_logger: failed to write log", exc_info=True)
    finally:
        try:
            db.close()
        except Exception:
            pass


def log_error_to_db(
    error_type: str,
    error_message: str,
    message_id: int | None = None,
    conversation_id: int | None = None,
) -> None:
    """Insert a row into error_logs."""
    try:
        db = SessionLocal()
        db.execute(
            __import__("sqlalchemy").text(
                "INSERT INTO error_logs (error_type, error_message, message_id, conversation_id, stack_trace) "
                "VALUES (:etype, :emsg, :mid, :cid, :trace)"
            ),
            {
                "etype": error_type[:100],
                "emsg": error_message[:2000],
                "mid": message_id,
                "cid": conversation_id,
                "trace": traceback.format_exc()[:5000],
            },
        )
        db.commit()
    except Exception:
        logger.debug("db_logger: failed to write error_log", exc_info=True)
    finally:
        try:
            db.close()
        except Exception:
            pass
