"""Merge all scraped chunks for the same staff member (multi-page profiles)."""

from __future__ import annotations

import re
from typing import Dict, List, Optional, Set, Tuple

_STAFF_URL_RE = re.compile(r"/staff_member/([^/?#]+)", re.I)
_CONTACT_SIGNAL_RE = re.compile(
    r"(?:contact\s+details|e-?mail|phone|tel:|\+256\d|@\w+\.ac\.ug|@\w+\.\w{2,})",
    re.I,
)
_CONTACT_QUERY_RE = re.compile(
    r"\b(contact|email|e-mail|phone|number|mobile|reach|call|whatsapp)\b",
    re.I,
)
_QUERY_STOP = {
    "get", "give", "show", "tell", "find", "what", "who", "the", "of", "for", "me",
    "mmu", "mountains", "moon", "university", "please", "contact", "information",
    "details", "about", "and", "is", "are",
}


def staff_profile_key(url: str) -> Optional[str]:
    """Canonical staff slug from URL (strips /page/N pagination path only)."""
    if not url:
        return None
    m = _STAFF_URL_RE.search(url)
    if not m:
        return None
    return m.group(1).lower().strip("/")


def is_contact_query(query: str) -> bool:
    return bool(_CONTACT_QUERY_RE.search(query or ""))


def extract_person_name_tokens(query: str) -> List[str]:
    """Significant name tokens from a person lookup query."""
    words = re.findall(r"[a-z]{3,}", (query or "").lower())
    return [w for w in words if w not in _QUERY_STOP]


def name_match_factor(page_title: str, name_tokens: List[str]) -> float:
    """1.0 = full name match; low score = wrong person (e.g. Daniel vs Andrew Tugume)."""
    if not name_tokens:
        return 1.0
    title = (page_title or "").lower()
    matched = sum(1 for t in name_tokens if t in title)
    if matched == len(name_tokens):
        return 1.0
    if matched == 0:
        return 0.15
    # Partial match (surname only when first name also asked)
    if len(name_tokens) >= 2 and matched < len(name_tokens):
        return 0.25
    return 0.6


def chunk_contact_score(content: str, chunk_type: str = "") -> float:
    """Higher when chunk likely contains contact details."""
    text = content or ""
    score = 0.0
    if _CONTACT_SIGNAL_RE.search(text):
        score += 2.0
    if "contact details" in text.lower():
        score += 1.5
    if chunk_type == "page_card" and _CONTACT_SIGNAL_RE.search(text[:1200]):
        score += 1.0
    return score


def _item_identity(item: Dict) -> Tuple[str, object]:
    return (str(item.get("source", "")), item.get("chunk_index"))


def expand_staff_profile_chunks(
    items: List[Dict],
    question: str,
    metadata: List[Dict],
    *,
    max_profile_chunks: int = 8,
) -> List[Dict]:
    """Add sibling chunks/pages for the same staff_member slug (e.g. /page/2)."""
    name_tokens = extract_person_name_tokens(question)
    contact_q = is_contact_query(question)
    if not name_tokens and not contact_q:
        return items

    profile_keys: Set[str] = set()
    for item in items:
        title = item.get("page_title") or ""
        if name_tokens and name_match_factor(title, name_tokens) < 0.5:
            continue
        pk = staff_profile_key(item.get("url") or "")
        if pk:
            profile_keys.add(pk)

    if not profile_keys and name_tokens:
        for meta in metadata:
            title = meta.get("page_title") or ""
            if name_match_factor(title, name_tokens) >= 0.5:
                pk = staff_profile_key(meta.get("url") or "")
                if pk:
                    profile_keys.add(pk)

    if not profile_keys:
        return items

    seen = {_item_identity(it) for it in items}
    extras: List[Dict] = []

    for meta in metadata:
        pk = staff_profile_key(meta.get("url") or "")
        if pk not in profile_keys:
            continue
        title = meta.get("page_title") or ""
        if name_tokens and name_match_factor(title, name_tokens) < 0.5:
            continue
        ident = (str(meta.get("source", "")), meta.get("chunk_index"))
        if ident in seen:
            continue

        base = float(meta.get("score") or 0.5)
        cscore = chunk_contact_score(meta.get("content", ""), meta.get("chunk_type", ""))
        score = base + cscore
        if contact_q and cscore > 0:
            score += 2.0
        if meta.get("chunk_type") == "page_card" and contact_q:
            score += 1.5

        extras.append({
            "content": meta.get("content", ""),
            "source": meta.get("source", ""),
            "type": meta.get("type", "scraped"),
            "score": score,
            "chunk_type": meta.get("chunk_type", ""),
            "page_title": meta.get("page_title", ""),
            "url": meta.get("url", ""),
            "chunk_index": meta.get("chunk_index"),
            "tags": meta.get("tags", ""),
            "page_type": meta.get("page_type", ""),
        })
        seen.add(ident)

    extras.sort(
        key=lambda x: (
            -chunk_contact_score(x.get("content", ""), x.get("chunk_type", "")),
            -float(x.get("score", 0)),
        ),
    )

    merged = list(items) + extras[:max_profile_chunks]
    merged.sort(key=lambda x: float(x.get("score", 0)), reverse=True)
    return merged


def filter_wrong_person_chunks(items: List[Dict], question: str) -> List[Dict]:
    """Drop chunks whose page title does not match requested name tokens."""
    tokens = extract_person_name_tokens(question)
    if len(tokens) < 2:
        return items
    filtered = []
    for item in items:
        factor = name_match_factor(item.get("page_title") or "", tokens)
        if factor < 0.4:
            continue
        item = {**item, "score": float(item.get("score", 0)) * factor}
        filtered.append(item)
    return filtered if filtered else items
