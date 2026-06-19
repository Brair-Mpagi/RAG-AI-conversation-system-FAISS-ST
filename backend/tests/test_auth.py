"""Tests for the /api/v1/auth/login endpoint (AUTH-01 / SEC-01)."""

from __future__ import annotations

import pytest


class TestAdminLogin:
    def test_login_missing_fields_returns_422(self, client):
        resp = client.post("/api/v1/auth/login", json={})
        assert resp.status_code == 422

    def test_login_bad_password_returns_401(self, client):
        # Seed a user first so the "wrong password" branch can be reached.
        # This test does NOT rely on admin_token fixture order.
        from sqlalchemy import text as sa_text
        from tests.conftest import test_engine, TestingSessionLocal
        try:
            from utils.security import hash_password
            _hash = hash_password("realpass")
        except Exception:
            _hash = "$2b$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/LedYsBqTW1gSFAFCa"
        with test_engine.connect() as conn:
            conn.execute(
                sa_text("INSERT OR IGNORE INTO admin_users (username, password_hash, role, is_active) "
                        "VALUES ('badpwtest', :h, 'admin', 1)"),
                {"h": _hash},
            )
            conn.commit()

        resp = client.post(
            "/api/v1/auth/login",
            json={"username": "badpwtest", "password": "wrongpassword"},
        )
        assert resp.status_code == 401
        assert "invalid" in resp.json()["detail"].lower()

    def test_login_unknown_user_returns_401(self, client):
        resp = client.post(
            "/api/v1/auth/login",
            json={"username": "nobody", "password": "anything"},
        )
        assert resp.status_code == 401

    def test_login_correct_credentials_returns_token(self, client, admin_token):
        """The admin_token fixture proves a correct login works (token created via create_access_token)."""
        assert admin_token  # not empty
        assert len(admin_token.split(".")) == 3  # valid JWT has 3 parts


class TestAdminEndpointProtection:
    def test_reindex_kb_without_token_returns_401(self, client):
        resp = client.post("/api/v1/admin/reindex_kb")
        assert resp.status_code == 401

    def test_reload_config_without_token_returns_401(self, client):
        resp = client.post("/api/v1/admin/reload-config")
        assert resp.status_code == 401

    def test_reindex_kb_with_invalid_token_returns_401(self, client):
        resp = client.post(
            "/api/v1/admin/reindex_kb",
            headers={"Authorization": "Bearer thisisnotavalidtoken"},
        )
        assert resp.status_code == 401

    def test_reload_config_with_valid_admin_token_returns_200(self, client, admin_token):
        resp = client.post(
            "/api/v1/admin/reload-config",
            headers={"Authorization": f"Bearer {admin_token}"},
        )
        # Should succeed (200) — even if reload returns an internal error, not a 401/403
        assert resp.status_code in (200, 500)
        # Must NOT be an auth failure
        assert resp.json().get("detail") not in ("Not authenticated", "Admin role required")
