"""db_config.py — Dynamic university configuration loaded from the database.

All university-specific data (entity abbreviations, campus keywords, entity
types / hierarchy trigger words) are derived at runtime from the entities the
admin manages, so nothing is hard-coded.  The results are cached in-process and
can be refreshed without a server restart by calling ``reload_all()``.
"""
from __future__ import annotations

import logging
from typing import Dict, List, Set

from databases.session import SessionLocal
from sqlalchemy import text

logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# In-process cache — populated lazily the first time each getter is called
# ---------------------------------------------------------------------------
_abbreviations: Dict[str, str] | None = None
_campus_keywords: List[str] | None = None
_entity_type_names: Set[str] | None = None


def _load_from_db() -> None:
    """Query the database and populate all caches."""
    global _abbreviations, _campus_keywords, _entity_type_names

    abbrevs: Dict[str, str] = {}
    keywords: List[str] = []
    type_names: Set[str] = set()

    try:
        db = SessionLocal()
        try:
            # ── Abbreviations from entity codes + short names ────────────────
            rows = db.execute(text("""
                SELECT name, entity_code, short_name
                FROM university_entities
                WHERE is_active = TRUE
                  AND (entity_code IS NOT NULL OR short_name IS NOT NULL)
            """)).fetchall()

            for row in rows:
                full_name = row[0] or ""
                for code_raw in [row[1], row[2]]:
                    if code_raw:
                        code_lc = code_raw.strip().lower()
                        if code_lc and code_lc not in abbrevs:
                            abbrevs[code_lc] = full_name

                # Also add the entity name to campus keywords (lowercased)
                if full_name:
                    kw = full_name.strip().lower()
                    if kw and kw not in keywords:
                        keywords.append(kw)

            # ── Entity codes as campus keywords ──────────────────────────────
            code_rows = db.execute(text("""
                SELECT DISTINCT entity_code, short_name
                FROM university_entities
                WHERE is_active = TRUE
            """)).fetchall()
            for row in code_rows:
                for val in [row[0], row[1]]:
                    if val:
                        kw = val.strip().lower()
                        if kw and kw not in keywords:
                            keywords.append(kw)

            # ── Entity type names for hierarchy triggers ─────────────────────
            type_rows = db.execute(text("""
                SELECT DISTINCT type_name, type_label
                FROM entity_types
            """)).fetchall()
            for row in type_rows:
                for val in [row[0], row[1]]:
                    if val:
                        type_names.add(val.strip().lower())

        finally:
            db.close()
    except Exception as exc:
        logger.error("db_config: failed to load from DB: %s", exc)

    _abbreviations = abbrevs
    _campus_keywords = keywords
    _entity_type_names = type_names
    logger.info(
        "db_config: loaded %d abbreviations, %d campus keywords, %d type names",
        len(abbrevs), len(keywords), len(type_names),
    )


def get_db_abbreviations() -> Dict[str, str]:
    """Return a mapping of lowercase entity code → full name from the DB."""
    global _abbreviations
    if _abbreviations is None:
        _load_from_db()
    return _abbreviations or {}


def get_db_campus_keywords() -> List[str]:
    """Return DB-derived university entity names/codes for intent matching."""
    global _campus_keywords
    if _campus_keywords is None:
        _load_from_db()
    return _campus_keywords or []


def get_db_entity_type_names() -> Set[str]:
    """Return entity type names (e.g. 'faculty', 'department') for RAG triggers."""
    global _entity_type_names
    if _entity_type_names is None:
        _load_from_db()
    return _entity_type_names or set()


def reload_all() -> Dict[str, int]:
    """Bust all caches and reload from the database.  Called by the reload-config endpoint."""
    global _abbreviations, _campus_keywords, _entity_type_names
    _abbreviations = None
    _campus_keywords = None
    _entity_type_names = None
    _load_from_db()
    return {
        "abbreviations": len(_abbreviations or {}),
        "campus_keywords": len(_campus_keywords or []),
        "entity_type_names": len(_entity_type_names or set()),
    }
