"""Retrieval helpers for list/roster queries (faculty-wide staff directories)."""

from __future__ import annotations

import re
from typing import Dict, List, Optional, Set

_LIST_QUERY_RE = re.compile(
    r"\b(list|show|display|give\s+me|who\s+are|name\s+all|all\s+the)\b.*\b"
    r"(staff|lecturer|lecturers|faculty\s+members?|teachers?|team|personnel)\b"
    r"|\b(staff|lecturer|lecturers)\b.*\b(list|under|in|at)\b",
    re.I,
)

_ROSTER_TABLE_RE = re.compile(r"\|\s*names\s*\|\s*position\s*\|", re.I)
_UNIT_PATH_RE = re.compile(r"/university_unit/([^?#]+)", re.I)

FACULTY_UNIT_PATHS: dict[str, str] = {
    "fosti": "faculty-of-science-technology-and-innovations",
    "science technology": "faculty-of-science-technology-and-innovations",
    "science and technology": "faculty-of-science-technology-and-innovations",
    "foaes": "faculty-of-agriculture-and-environmental-sciences",
    "agriculture": "faculty-of-agriculture-and-environmental-sciences",
    "fobms": "faculty-of-business-and-management-sciences",
    "business": "faculty-of-business-and-management-sciences",
    "fohss": "faculty-of-humanities-and-social-sciences",
    "humanities": "faculty-of-humanities-and-social-sciences",
    "fohs": "faculty-of-health-sciences",
    "health sciences": "faculty-of-health-sciences",
    "foe": "faculty-of-education",
    "education": "faculty-of-education",
}

DEPARTMENT_UNIT_PATHS: dict[str, str] = {
    "computer science": "department-of-computer-science",
    "biological": "department-of-biological-sciences",
    "physical science": "department-of-physical-science",
    "physical sciences": "department-of-physical-science",
    "business administration": "department-of-business-administration",
    "nursing": "department-of-nursing",
}


def is_list_query(query: str) -> bool:
    return bool(_LIST_QUERY_RE.search(query or ""))


def resolve_unit_path_prefix(query: str) -> Optional[str]:
    """Return university_unit path prefix (faculty or faculty/dept) matched in query."""
    q = (query or "").lower()
    for kw, dept_path in DEPARTMENT_UNIT_PATHS.items():
        if kw in q:
            for fac_kw, fac_path in FACULTY_UNIT_PATHS.items():
                if fac_kw in q:
                    return f"{fac_path}/{dept_path}"
            return dept_path
    for kw, fac_path in FACULTY_UNIT_PATHS.items():
        if kw in q:
            return fac_path
    return None


def department_roster_key(url: str) -> Optional[str]:
    """Group paginated department roster pages (page/2, page/3, …)."""
    if not url or "/university_unit/" not in url:
        return None
    m = _UNIT_PATH_RE.search(url)
    if not m:
        return None
    path = m.group(1).lower()
    path = re.sub(r"/page/\d+$", "", path)
    return path


def _is_entity_roster_chunk(meta: Dict) -> bool:
    """True for admin-curated entity: knowledge chunks that carry a staff table."""
    if not (meta.get("source") or "").startswith("entity:"):
        return False
    content = meta.get("content") or ""
    # Must mention at least 2 staff roles to be treated as a roster
    role_count = sum(
        1 for role in ("Lecturer", "Assistant Lecturer", "Teaching Assistant", "Tutor", "Professor")
        if role in content
    )
    return role_count >= 2


def is_department_roster_page(meta: Dict) -> bool:
    """True for university_unit listing pages (not individual staff_member profiles)."""
    # Admin-curated entity knowledge chunks count as roster pages
    if _is_entity_roster_chunk(meta):
        return True

    url = (meta.get("url") or "").lower()
    if "/staff_member/" in url:
        return False
    if "/university_unit/" not in url:
        return False

    page_type = (meta.get("page_type") or "").lower()
    if page_type == "staff_directory":
        return True

    content = (meta.get("content") or "").lower()
    tags = (meta.get("tags") or "").lower()
    roster_signals = (
        "university staff list",
        "directory of academic staff",
        "staff directory",
        "faculty directory",
        "list of staff",
    )
    if any(sig in content or sig in tags for sig in roster_signals):
        return True
    if _ROSTER_TABLE_RE.search(meta.get("content") or ""):
        return True
    if "head of department" in content and "| lecturer |" in content:
        return True
    return False


def roster_listing_score(meta: Dict) -> float:
    """Higher = more likely a full roster chunk."""
    score = 1.0
    content = meta.get("content") or ""
    if _ROSTER_TABLE_RE.search(content):
        score += 2.5
    if "university staff list" in content.lower():
        score += 1.5
    if (meta.get("page_type") or "") == "staff_directory":
        score += 1.0
    if meta.get("chunk_type") == "page_card":
        score += 0.5
    return score


def demote_individual_staff_pages(items: List[Dict]) -> List[Dict]:
    """For roster queries, deprioritize single staff_member profile chunks."""
    out = []
    for item in items:
        url = (item.get("url") or "").lower()
        if "/staff_member/" in url:
            item = {**item, "score": float(item.get("score", 0)) * 0.25}
        out.append(item)
    out.sort(key=lambda x: float(x.get("score", 0)), reverse=True)
    return out


def is_roster_chunk(item: Dict) -> bool:
    """Chunk from a department listing page OR an admin-curated entity knowledge chunk."""
    # Entity knowledge chunks with staff tables are always roster chunks
    if _is_entity_roster_chunk(item):
        return True
    url = (item.get("url") or "").lower()
    if "/staff_member/" in url:
        return False
    if "/university_unit/" not in url:
        return False
    if (item.get("page_type") or "") == "staff_directory":
        return True
    return bool(_ROSTER_TABLE_RE.search(item.get("content") or ""))


def _entity_matches_unit_prefix(meta: Dict, prefix: str) -> bool:
    """True if an entity: chunk belongs to the department/faculty in `prefix`."""
    # Derive department name from the prefix slug, e.g.
    # "department-of-computer-science" → "computer science"
    slug = prefix.split("/")[-1].lower()          # last path segment
    readable = slug.replace("-", " ")
    # Drop "department of " / "faculty of " leading words
    for drop in ("department of ", "faculty of "):
        if readable.startswith(drop):
            readable = readable[len(drop):]
    entity_name = (meta.get("entity_name") or "").lower()
    content    = (meta.get("content")     or "").lower()
    return readable in entity_name or readable in content[:200]


def collect_faculty_roster_chunks(
    question: str,
    metadata: List[Dict],
    *,
    max_chunks: int = 20,
) -> List[Dict]:
    """Load department roster chunks from metadata (bypasses vector/MMR).

    Now also includes admin-curated entity knowledge chunks (source=entity:N:M)
    that carry a staff table for the requested department.
    """
    if not is_list_query(question):
        return []

    prefix = resolve_unit_path_prefix(question)
    if not prefix:
        return []

    prefix = prefix.lower()
    chunks: List[Dict] = []
    for meta in metadata:
        if not is_department_roster_page(meta):
            continue

        source = (meta.get("source") or "")

        # --- Entity knowledge chunks: match by name/content instead of URL ---
        if source.startswith("entity:"):
            if not _entity_matches_unit_prefix(meta, prefix):
                continue
            score = 14.0 + roster_listing_score(meta)   # slightly higher than scraped
            chunks.append({
                "content": meta.get("content", ""),
                "source": source,
                "type": "entity",
                "score": score,
                "chunk_type": meta.get("chunk_type", "entity"),
                "page_title": meta.get("entity_name", ""),
                "url": "",
                "chunk_index": meta.get("chunk_index"),
                "tags": meta.get("tags", ""),
                "page_type": "staff_directory",
                "_roster_key": prefix,
            })
            continue

        # --- Scraped pages: match by URL as before ---
        url = (meta.get("url") or "").lower()
        roster_key = department_roster_key(url) or ""
        if prefix not in url and prefix not in roster_key:
            continue

        score = 12.0 + roster_listing_score(meta)
        chunks.append({
            "content": meta.get("content", ""),
            "source": source,
            "type": meta.get("type", "scraped"),
            "score": score,
            "chunk_type": meta.get("chunk_type", ""),
            "page_title": meta.get("page_title", ""),
            "url": url,
            "chunk_index": meta.get("chunk_index"),
            "tags": meta.get("tags", ""),
            "page_type": meta.get("page_type", ""),
            "_roster_key": roster_key,
        })

    chunks.sort(
        key=lambda x: (
            x.get("_roster_key", ""),
            -float(x.get("score", 0)),
            int(x.get("chunk_index") or 0),
        ),
    )
    return chunks[:max_chunks]


def finalize_list_context(all_results: List[Dict], question: str) -> List[Dict]:
    """Roster listing pages first; drop generic faculty blurbs for list queries."""
    if not is_list_query(question):
        return all_results

    rosters = [x for x in all_results if is_roster_chunk(x)]
    if not rosters:
        return all_results

    others: List[Dict] = []
    for x in all_results:
        if is_roster_chunk(x):
            continue
        src = str(x.get("source", ""))
        url = (x.get("url") or "").lower()
        # Skip generic entity blurbs, but KEEP entity roster chunks (admin staff tables)
        if src.startswith("entity:") and not _is_entity_roster_chunk(x):
            continue
        if "/university_unit/" in url and (x.get("page_type") or "") != "staff_directory":
            continue
        others.append(x)

    merged = rosters + others[:2]
    merged.sort(
        key=lambda x: (0 if is_roster_chunk(x) else 1, -float(x.get("score", 0))),
    )
    return merged


def expand_faculty_staff_rosters(
    items: List[Dict],
    question: str,
    metadata: List[Dict],
    *,
    max_roster_chunks: int = 18,
) -> List[Dict]:
    """Pull all department roster pages under a faculty (paginated listings)."""
    if not is_list_query(question):
        return items

    roster_primary = collect_faculty_roster_chunks(question, metadata, max_chunks=max_roster_chunks)
    if not roster_primary:
        return items

    seen: Set[tuple] = {(str(r.get("source", "")), r.get("chunk_index")) for r in roster_primary}
    merged = list(roster_primary)
    for it in demote_individual_staff_pages(items):
        ident = (str(it.get("source", "")), it.get("chunk_index"))
        if ident not in seen:
            merged.append(it)
            seen.add(ident)

    merged.sort(key=lambda x: (0 if is_roster_chunk(x) else 1, -float(x.get("score", 0))))
    return merged[:max_roster_chunks]
