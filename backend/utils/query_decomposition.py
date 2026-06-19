"""Split multi-intent user questions into focused sub-queries for retrieval."""

from __future__ import annotations

import re
from typing import List

# Split before a new question clause (comma/semicolon/and + wh-word).
_SUBQUERY_BOUNDARY = re.compile(
    r"(?:\s*[,;]\s*|\s+and\s+)(?=(?:who|what|which|where|when|how|list|tell|show|give|name)\b)",
    re.IGNORECASE,
)

# Split combined leadership asks: "who is the VC and dean of students".
_LEADERSHIP_AND = re.compile(
    r"\s+and\s+(?=(?:the\s+)?(?:dean|vc|v\.c|vice|hod|registrar|director|head)\b)",
    re.IGNORECASE,
)

_KNOWN_ROLE_PHRASES = (
    "dean of students",
    "vice chancellor",
    "deputy vice chancellor",
    "academic registrar",
    "dean of faculty",
    "head of department",
    "dean of school",
)

# Lightweight intent labels for routing structured lookups.
_INTENT_PATTERNS: list[tuple[str, re.Pattern[str]]] = [
    ("hierarchy", re.compile(r"\b(facult(y|ies)|department|departments|school|college|hierarchy|structure|under\s+mmu|units?)\b", re.I)),
    ("staff", re.compile(r"\b(dean|hod|head\s+of|lecturer|staff|vc|vice\s+chancellor|chancellor|registrar|director|students?\s+affairs)\b", re.I)),
    ("program", re.compile(r"\b(program|programme|course|courses|degree|admission)\b", re.I)),
    ("fee", re.compile(r"\b(fee|fees|tuition|cost|price|pay)\b", re.I)),
    ("contact", re.compile(r"\b(contact|phone|email|call|address)\b", re.I)),
    ("deadline", re.compile(r"\b(deadline|due\s+date|intake|closing)\b", re.I)),
]


def _normalize_subquery(part: str) -> str:
    """Ensure leadership fragments are searchable questions."""
    p = part.strip(" ,;")
    if re.match(r"^(dean|vc|v\.c|vice|hod|registrar|director|head)\b", p, re.I):
        return f"who is the {p}"
    return p


def _split_leadership_clauses(parts: List[str]) -> List[str]:
    refined: List[str] = []
    for part in parts:
        subparts = _LEADERSHIP_AND.split(part)
        for sp in subparts:
            normalized = _normalize_subquery(sp)
            if normalized and len(normalized) >= 4:
                refined.append(normalized)
    return refined


def decompose_query(query: str, max_subqueries: int = 4) -> List[str]:
    """Return one or more focused sub-queries when the user combines multiple asks."""
    text = (query or "").strip()
    if not text:
        return [text]

    parts = _SUBQUERY_BOUNDARY.split(text)
    parts = [p.strip(" ,;") for p in parts if p and p.strip(" ,;")]
    if len(parts) > 1:
        parts = _split_leadership_clauses(parts)
    elif len(parts) == 1:
        split = _split_leadership_clauses(parts)
        if len(split) > 1:
            parts = split

    if len(parts) <= 1:
        return [text]

    seen: set[str] = set()
    unique: List[str] = []
    for part in parts:
        key = part.lower()
        if key in seen or len(part) < 4:
            continue
        seen.add(key)
        unique.append(part)

    if len(unique) <= 1:
        return [text]
    if len(unique) > max_subqueries:
        return [text]
    return unique


def extract_role_phrases(query: str) -> List[str]:
    """Return canonical role phrases present in the query (for targeted FULLTEXT)."""
    q = (query or "").lower()
    found: List[str] = []
    for phrase in _KNOWN_ROLE_PHRASES:
        if phrase in q:
            found.append(phrase)
    if re.search(r"\bvc\b|\bv\.c\b", q) and "vice chancellor" not in found:
        found.append("vice chancellor")
    return found


def classify_retrieval_intents(query: str) -> List[str]:
    """Return ordered intent labels detected in a sub-query (for logging/routing)."""
    found: List[str] = []
    for label, pattern in _INTENT_PATTERNS:
        if pattern.search(query):
            found.append(label)
    return found or ["general"]
