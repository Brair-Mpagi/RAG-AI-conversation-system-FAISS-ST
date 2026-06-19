#!/usr/bin/env python3
"""
Admin User Seeding Script
=========================
Run this ONCE after first deployment to create the initial admin account
for the JWT-protected API (`/api/v1/auth/login`).

Usage:
    cd backend/
    source backend_env/bin/activate
    python ../scripts/seed_admin.py

The script reads DATABASE_URL from backend/.env automatically.
"""

from __future__ import annotations

import getpass
import sys
from pathlib import Path

# ── Bootstrap: load .env so DATABASE_URL is available ──────────────────────
BACKEND_DIR = Path(__file__).resolve().parent.parent / "backend"
ENV_FILE = BACKEND_DIR / ".env"

if ENV_FILE.exists():
    from dotenv import load_dotenv
    load_dotenv(ENV_FILE)

# ── Imports (must come after env load) ─────────────────────────────────────
try:
    from sqlalchemy import create_engine, text
    from passlib.context import CryptContext
except ImportError as e:
    sys.exit(f"❌ Missing dependency: {e}\n   Run: pip install sqlalchemy passlib[bcrypt]")

try:
    from core.config import settings  # type: ignore
    DATABASE_URL = settings.DATABASE_URL
except Exception:
    import os
    DATABASE_URL = os.getenv("DATABASE_URL")

if not DATABASE_URL:
    sys.exit("❌  DATABASE_URL not found in environment or .env file")

# ── Password hashing ────────────────────────────────────────────────────────
_pwd_ctx = CryptContext(schemes=["bcrypt"], deprecated="auto")


def create_admin() -> None:
    print("\n═══════════════════════════════════════════════════")
    print("  MMU Campus AI — Admin User Seeding Tool")
    print("═══════════════════════════════════════════════════\n")

    username = input("Enter admin username: ").strip()
    if not username:
        sys.exit("❌ Username cannot be empty.")

    password = getpass.getpass("Enter admin password: ")
    if len(password) < 8:
        sys.exit("❌ Password must be at least 8 characters.")

    confirm = getpass.getpass("Confirm password: ")
    if password != confirm:
        sys.exit("❌ Passwords do not match.")

    role = input("Role [admin/superadmin] (default: admin): ").strip() or "admin"

    hashed = _pwd_ctx.hash(password)

    engine = create_engine(DATABASE_URL, pool_pre_ping=True)
    with engine.connect() as conn:
        # Check if table exists
        result = conn.execute(text(
            "SELECT COUNT(*) FROM information_schema.tables "
            "WHERE table_schema = DATABASE() AND table_name = 'admin_users'"
        ))
        if result.scalar() == 0:
            print("\n⚠️  'admin_users' table not found — creating it…")
            conn.execute(text("""
                CREATE TABLE admin_users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(80) UNIQUE NOT NULL,
                    password_hash VARCHAR(255) NOT NULL,
                    role VARCHAR(20) NOT NULL DEFAULT 'admin',
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            """))
            conn.commit()
            print("✅ Table created.\n")

        # Check for existing user
        existing = conn.execute(
            text("SELECT id FROM admin_users WHERE username = :u"), {"u": username}
        ).fetchone()

        if existing:
            print(f"\n⚠️  User '{username}' already exists.")
            overwrite = input("Overwrite password? [y/N]: ").strip().lower()
            if overwrite != 'y':
                print("Aborted.")
                return
            conn.execute(
                text("UPDATE admin_users SET password_hash = :h, role = :r, is_active = 1 WHERE username = :u"),
                {"h": hashed, "r": role, "u": username},
            )
            conn.commit()
            print(f"\n✅ Password updated for '{username}'.")
        else:
            conn.execute(
                text("INSERT INTO admin_users (username, password_hash, role, is_active) VALUES (:u, :h, :r, 1)"),
                {"u": username, "h": hashed, "r": role},
            )
            conn.commit()
            print(f"\n✅ Admin user '{username}' created successfully!")

    print("\nYou can now log in via:")
    print("  POST /api/v1/auth/login")
    print(f"  {{\"username\": \"{username}\", \"password\": \"<your password>\"}}")
    print("\n═══════════════════════════════════════════════════\n")


if __name__ == "__main__":
    try:
        create_admin()
    except KeyboardInterrupt:
        print("\n\nAborted by user.")
        sys.exit(0)
