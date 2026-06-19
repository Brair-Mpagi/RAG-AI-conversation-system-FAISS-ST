"""MySQL FULLTEXT retrieval for hybrid RAG (keyword + semantic)."""

from __future__ import annotations

import logging
import re
from typing import Dict, List

from sqlalchemy import text
from sqlalchemy.orm import Session

logger = logging.getLogger(__name__)

_TITLE_LIKE_RE = re.compile(
    r'"[^"]{8,}"|\'[^\']{8,}\'|'
    r"\b(department|faculty|school|college|institute|centre|center)\s+of\b",
    re.I,
)


def _sanitize_fulltext_query(q: str, max_len: int = 200) -> str:
    """Strip characters invalid for MySQL NATURAL LANGUAGE MODE."""
    q = re.sub(r"[+\-><()~*\"@]+", " ", q)
    q = re.sub(r"\s+", " ", q).strip()
    return q[:max_len] if q else ""


def is_title_like_query(question: str) -> bool:
    """Heuristic: user may be searching for a specific page title."""
    q = (question or "").strip()
    if not q or len(q) < 8:
        return False
    if _TITLE_LIKE_RE.search(q):
        return True
    words = [w for w in re.split(r"\W+", q) if len(w) > 2]
    if len(words) <= 14:
        caps = sum(1 for w in words if w[0].isupper())
        if caps >= 3:
            return True
    return False


def search_scraped_fulltext(
    db: Session,
    question: str,
    *,
    limit: int = 12,
    title_boost: bool = False,
) -> List[Dict[str, str]]:
    """Search scraped_content via ft_scraped_search."""
    ft_q = _sanitize_fulltext_query(question)
    if len(ft_q) < 3:
        return []

    try:
        if title_boost:
            sql = text("""
                SELECT scraped_id, page_title, page_url, search_document, cleaned_content,
                       (
                         2.0 * MATCH(page_title) AGAINST(:q IN NATURAL LANGUAGE MODE)
                         + MATCH(page_title, search_document, cleaned_content)
                           AGAINST(:q IN NATURAL LANGUAGE MODE)
                       ) AS ft_score
                FROM scraped_content
                WHERE cleaned_content IS NOT NULL AND cleaned_content != ''
                  AND (
                    MATCH(page_title) AGAINST(:q IN NATURAL LANGUAGE MODE)
                    OR MATCH(page_title, search_document, cleaned_content)
                       AGAINST(:q IN NATURAL LANGUAGE MODE)
                  )
                ORDER BY ft_score DESC
                LIMIT :lim
            """)
        else:
            sql = text("""
                SELECT scraped_id, page_title, page_url, search_document, cleaned_content,
                       MATCH(page_title, search_document, cleaned_content)
                         AGAINST(:q IN NATURAL LANGUAGE MODE) AS ft_score
                FROM scraped_content
                WHERE cleaned_content IS NOT NULL AND cleaned_content != ''
                  AND MATCH(page_title, search_document, cleaned_content)
                      AGAINST(:q IN NATURAL LANGUAGE MODE)
                ORDER BY ft_score DESC
                LIMIT :lim
            """)
        rows = db.execute(sql, {"q": ft_q, "lim": limit}).mappings().all()
    except Exception as exc:
        logger.warning("FULLTEXT search failed: %s", exc)
        return []

    results: List[Dict[str, str]] = []
    for r in rows:
        title = (r.get("page_title") or "").strip()
        body = (r.get("search_document") or r.get("cleaned_content") or "").strip()
        content = f"{title}\n\n{body}" if title else body
        ft_score = float(r.get("ft_score") or 0)
        # Normalize FT scores into retrieval score band (~0.7–1.8)
        norm = min(1.8, 0.75 + ft_score * 0.15)
        results.append({
            "content": content[:8192],
            "source": f"scraped:{r.get('scraped_id')}",
            "type": "scraped",
            "score": norm,
            "chunk_type": "fulltext",
            "page_title": title,
            "url": r.get("page_url") or "",
            "retrieval": "fulltext",
        })
    return results


def search_campus_knowledge_fulltext(
    db: Session,
    question: str,
    *,
    limit: int = 8,
) -> List[Dict[str, str]]:
    """FULLTEXT on campus_knowledge_items."""
    ft_q = _sanitize_fulltext_query(question)
    if len(ft_q) < 3:
        return []

    try:
        sql = text("""
            SELECT item_id, category, title, short_description, full_content,
                   MATCH(title, short_description, full_content)
                     AGAINST(:q IN NATURAL LANGUAGE MODE) AS ft_score
            FROM campus_knowledge_items
            WHERE is_active = TRUE
              AND MATCH(title, short_description, full_content)
                  AGAINST(:q IN NATURAL LANGUAGE MODE)
            ORDER BY ft_score DESC
            LIMIT :lim
        """)
        rows = db.execute(sql, {"q": ft_q, "lim": limit}).mappings().all()
    except Exception as exc:
        logger.warning("Campus knowledge FULLTEXT failed: %s", exc)
        return []

    out: List[Dict[str, str]] = []
    for r in rows:
        title = (r.get("title") or "").strip()
        cat = (r.get("category") or "").strip()
        body = (r.get("full_content") or "")[:4000]
        content = f"{title} ({cat})\n\n{body}" if cat else f"{title}\n\n{body}"
        ft_score = float(r.get("ft_score") or 0)
        out.append({
            "content": content,
            "source": f"campus_item:{r.get('item_id')}",
            "type": "campus_knowledge",
            "score": min(1.75, 0.7 + ft_score * 0.12),
            "chunk_type": "fulltext",
            "page_title": title,
            "retrieval": "fulltext",
        })
    return out
