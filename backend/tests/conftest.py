"""pytest conftest — lightweight fixtures for unit testing without a real DB or Ollama."""

from __future__ import annotations

# Monkeypatch bcrypt to fix passlib bug in Python 3.12
import bcrypt
if not hasattr(bcrypt, "__about__"):
    class About:
        __version__ = getattr(bcrypt, "__version__", "4.0.0")
    bcrypt.__about__ = About

import pytest
from fastapi.testclient import TestClient
from sqlalchemy import create_engine, text
from sqlalchemy.orm import sessionmaker

# ---------------------------------------------------------------------------
# In-memory SQLite engine (no MariaDB required for unit tests)
# ---------------------------------------------------------------------------
SQLITE_URL = "sqlite:///./test_temp.db"

test_engine = create_engine(SQLITE_URL, connect_args={"check_same_thread": False})
TestingSessionLocal = sessionmaker(autocommit=False, autoflush=False, bind=test_engine)


def _create_minimal_tables():
    """Create just enough schema for the routes under test."""
    with test_engine.connect() as conn:
        conn.execute(text("""
            CREATE TABLE IF NOT EXISTS web_sessions (
                session_id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_token TEXT NOT NULL,
                interface_type TEXT DEFAULT 'web',
                device_type TEXT,
                device_model TEXT,
                device_brand TEXT,
                os_name TEXT,
                os_version TEXT,
                browser_name TEXT,
                browser_version TEXT,
                screen_resolution TEXT,
                ip_address TEXT,
                location TEXT,
                status TEXT DEFAULT 'active',
                start_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                end_time DATETIME,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                duration_seconds INTEGER
            )
        """))
        conn.execute(text("""
            CREATE TABLE IF NOT EXISTS admin_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                role TEXT DEFAULT 'admin',
                is_active INTEGER DEFAULT 1
            )
        """))
        conn.execute(text("""
            CREATE TABLE IF NOT EXISTS conversations (
                conversation_id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id INTEGER
            )
        """))
        conn.execute(text("""
            CREATE TABLE IF NOT EXISTS chat_messages (
                message_id INTEGER PRIMARY KEY AUTOINCREMENT,
                conversation_id INTEGER,
                session_id INTEGER,
                sender_type TEXT,
                user_message TEXT,
                bot_response TEXT,
                intent_classification TEXT,
                response_type TEXT,
                context_retrieved INTEGER DEFAULT 0,
                retrieval_doc_count INTEGER DEFAULT 0,
                confidence_score REAL DEFAULT 0.0,
                response_time_ms REAL DEFAULT 0.0,
                model_used TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        """))
        conn.execute(text("""
            CREATE TABLE IF NOT EXISTS system_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                log_level TEXT,
                module TEXT,
                message TEXT,
                stack_trace TEXT,
                ip_address TEXT,
                metadata TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        """))
        conn.execute(text("""
            CREATE TABLE IF NOT EXISTS error_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                error_type TEXT,
                error_message TEXT,
                message_id INTEGER,
                conversation_id INTEGER,
                stack_trace TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        """))
        conn.commit()


_create_minimal_tables()


def get_test_db():
    db = TestingSessionLocal()
    try:
        yield db
    finally:
        db.close()


@pytest.fixture(scope="session")
def client():
    """Return a FastAPI TestClient with DB overridden to SQLite in-memory."""
    from unittest.mock import patch, MagicMock

    # Patch the generate functions so no Ollama is needed
    mock_generate = MagicMock(return_value="Hello! I'm the MMU Campus Assistant.")
    mock_generate_ctx = MagicMock(return_value=("This is a test answer.", 0.85, "high"))

    with patch("utils.llm.generate_response", mock_generate), \
         patch("utils.llm.generate_response_with_context", mock_generate_ctx), \
         patch("utils.rag.retrieve_context", return_value=[]):

        from main import app
        from databases.session import get_db
        app.dependency_overrides[get_db] = get_test_db

        with TestClient(app, raise_server_exceptions=False) as c:
            yield c

        app.dependency_overrides.clear()


@pytest.fixture(scope="session")
def admin_token(client):
    """Insert a test admin user and return a valid JWT token for it."""
    from utils.security import hash_password
    from utils.auth import create_access_token

    # Generate bcrypt hash first (outside the DB context to isolate any passlib issues)
    test_pw = "Abc1234x"
    try:
        hashed = hash_password(test_pw)
    except Exception:
        # If bcrypt unavailable in test environment (Python 3.12 passlib bug),
        # fall back to a pre-computed hash for "Abc1234x"
        hashed = "$2b$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/LedYsBqTW1gSFAFCa"

    # Insert test admin into SQLite
    with test_engine.connect() as conn:
        conn.execute(
            text("INSERT OR IGNORE INTO admin_users (username, password_hash, role, is_active) "
                 "VALUES (:u, :h, 'admin', 1)"),
            {"u": "testadmin", "h": hashed},
        )
        conn.commit()

    return create_access_token({"sub": "testadmin", "role": "admin"})
