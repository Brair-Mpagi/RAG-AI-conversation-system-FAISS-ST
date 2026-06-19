"""Add interface_type to web_sessions

Revision ID: 20260213_181500
Revises: 20251006_114600
Create Date: 2026-02-13 18:15:00.000000
"""

from __future__ import annotations

from alembic import op
import sqlalchemy as sa

# revision identifiers, used by Alembic.
revision = "20260213_181500"
down_revision = "20251006_114600"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.add_column(
        "web_sessions",
        sa.Column("interface_type", sa.String(length=10), nullable=False, server_default="web"),
    )


def downgrade() -> None:
    op.drop_column("web_sessions", "interface_type")
