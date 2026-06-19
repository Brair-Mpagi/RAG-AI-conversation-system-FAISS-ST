"""Rule-based content enrichment for scraped pages (Phase 1).

Builds search_document and indexing headers from page title, URL, and
structured sections — no LLM required.
"""

from __future__ import annotations

import json
import re
from typing import Any, Dict, List, Optional
from urllib.parse import unquote, urlparse

# URL slug tokens → retrieval keywords
_URL_KEYWORD_MAP = {
    "faculty-of-science-technology-and-innovations": "FOSTI Faculty of Science Technology and Innovations",
    "fosti": "FOSTI",
    "department-of-computer-science": "Computer Science department",
    "computer-science": "computer science",
    "lecturer": "lecturers staff",
    "staff": "staff directory",
    "fee": "fees tuition",
    "tuition": "fees tuition",
    "admission": "admissions requirements",
    "calendar": "academic calendar dates",
    "contact": "contact information",
    "policy": "policy regulations",
    "announcement": "announcements news",
    "course": "courses programmes",
    "programme": "programmes courses",
    "program": "programmes courses",
}

# Section headings → semantic tags
_HEADING_TAG_MAP = {
    "lecturer": "lecturers",
    "teaching assistant": "teaching assistants",
    "head of department": "head of department staff",
    "staff": "staff",
    "qualification": "qualifications",
    "fee": "fees",
    "tuition": "fees",
    "admission": "admissions",
    "requirement": "admission requirements",
    "calendar": "academic calendar",
    "contact": "contacts",
    "email": "contacts",
    "phone": "contacts",
    "course": "courses",
    "programme": "programmes",
    "policy": "policies",
    "deadline": "deadlines",
    "intake": "intake",
    "facility": "facilities",
    "faq": "faq",
    "announcement": "announcements",
}


def parse_sections(sections_json: Any) -> List[Dict[str, str]]:
    """Parse sections from JSON string, list, or None."""
    if not sections_json:
        return []
    if isinstance(sections_json, str):
        try:
            data = json.loads(sections_json)
        except (json.JSONDecodeError, TypeError):
            return []
    elif isinstance(sections_json, list):
        data = sections_json
    else:
        return []

    sections: List[Dict[str, str]] = []
    for item in data:
        if not isinstance(item, dict):
            continue
        heading = (item.get("heading") or "").strip()
        text = (item.get("text") or "").strip()
        if heading or text:
            sections.append({"heading": heading, "text": text})
    return sections


def _url_keywords(page_url: str) -> List[str]:
    if not page_url:
        return []
    path = unquote(urlparse(page_url).path.lower())
    tokens = [t for t in re.split(r"[/\-_]+", path) if len(t) > 2]
    keywords: List[str] = []
    path_joined = path.replace("-", " ")
    for slug, label in _URL_KEYWORD_MAP.items():
        if slug in path or slug.replace("-", " ") in path_joined:
            keywords.append(label)
    for t in tokens:
        if t in _URL_KEYWORD_MAP:
            keywords.append(_URL_KEYWORD_MAP[t])
    return list(dict.fromkeys(keywords))


def _heading_tags(sections: List[Dict[str, str]]) -> List[str]:
    tags: List[str] = []
    for sec in sections:
        heading = (sec.get("heading") or "").lower()
        if not heading:
            continue
        for key, tag in _HEADING_TAG_MAP.items():
            if key in heading:
                tags.append(tag)
        if heading and heading not in tags:
            tags.append(heading)
    return list(dict.fromkeys(tags))


def _content_preview(cleaned_content: str, max_len: int = 400) -> str:
    if not cleaned_content:
        return ""
    text = re.sub(r"\n{3,}", "\n\n", cleaned_content.strip())
    if len(text) <= max_len:
        return text
    return text[:max_len].rsplit(" ", 1)[0] + "..."


def build_rule_enrichment(
    page_title: str,
    page_url: str,
    cleaned_content: str,
    sections: List[Dict[str, str]] | Any = None,
    meta_category: str | None = None,
) -> Dict[str, Any]:
    """Build rule-based enrichment fields for DB storage and indexing."""
    sections_list = parse_sections(sections) if not isinstance(sections, list) else sections
    if sections and not sections_list:
        sections_list = parse_sections(sections)

    section_headings = [s["heading"] for s in sections_list if s.get("heading")]
    url_kw = _url_keywords(page_url)
    heading_tags = _heading_tags(sections_list)

    tags = list(dict.fromkeys(heading_tags + url_kw))
    if meta_category:
        tags.insert(0, meta_category.strip())

    title_clean = (page_title or "").strip()
    if title_clean:
        tags.insert(0, title_clean)

    lines = [f"Page: {title_clean or 'Untitled'}"]
    if page_url:
        lines.append(f"URL: {page_url}")
    if section_headings:
        lines.append(f"Sections: {', '.join(section_headings[:20])}")
    if tags:
        lines.append(f"Topics: {', '.join(tags[:25])}")
    preview = _content_preview(cleaned_content)
    if preview:
        lines.append(f"Preview: {preview}")

    search_document = "\n".join(lines)

    enrichment_json = {
        "page_type": _infer_page_type(section_headings, tags, page_url),
        "tags": tags[:30],
        "section_headings": section_headings[:30],
        "url_keywords": url_kw,
        "source": "rule",
    }

    return {
        "sections_json": sections_list,
        "search_document": search_document,
        "enrichment_json": enrichment_json,
    }


def _infer_page_type(headings: List[str], tags: List[str], url: str) -> str:
    combined = " ".join(headings + tags + [url]).lower()
    checks = [
        ("staff_directory", ("lecturer", "teaching assistant", "head of department", "staff directory")),
        ("fees", ("fee", "tuition", "cost", "price")),
        ("admissions", ("admission", "requirement", "apply", "intake")),
        ("course_info", ("course", "programme", "curriculum", "module")),
        ("policy", ("policy", "regulation", "guideline")),
        ("calendar", ("calendar", "semester", "timetable")),
        ("contact", ("contact", "email", "phone", "office")),
        ("faq", ("faq", "frequently asked")),
        ("announcement", ("announcement", "news", "notice")),
        ("facilities", ("facility", "laboratory", "library")),
        ("department_info", ("department", "faculty", "school")),
    ]
    for page_type, keys in checks:
        if any(k in combined for k in keys):
            return page_type
    return "other"


def build_page_card_text(
    page_title: str,
    search_document: str,
    enrichment_json: Dict[str, Any] | None = None,
) -> str:
    """Text embedded for the page-level retrieval card vector."""
    parts = []
    if page_title:
        parts.append(page_title.strip())
    if enrichment_json:
        page_type = enrichment_json.get("page_type")
        tags = enrichment_json.get("tags") or []
        summary = enrichment_json.get("summary")
        aliases = enrichment_json.get("query_aliases") or []
        if page_type and page_type != "other":
            parts.append(f"Page type: {page_type}")
        if summary:
            parts.append(f"Summary: {summary}")
        if tags:
            parts.append(f"Tags: {', '.join(tags[:20])}")
        if aliases:
            parts.append(f"Example queries: {', '.join(aliases[:8])}")
    if search_document:
        parts.append(search_document.strip())
    return "\n\n".join(parts)


def build_section_chunk_text(
    page_title: str,
    section_heading: str,
    section_text: str,
    enrichment_json: Dict[str, Any] | None = None,
    chunk_size: int = 500,
    overlap: int = 75,
) -> List[str]:
    """Build one or more embed strings for a section (with optional sub-chunking)."""
    from utils.text_chunking import chunk_text

    header_parts = []
    if page_title:
        header_parts.append(page_title.strip())
    if section_heading:
        header_parts.append(f"Section: {section_heading}")
    if enrichment_json:
        page_type = enrichment_json.get("page_type")
        tags = enrichment_json.get("tags") or []
        summary = enrichment_json.get("summary")
        if page_type and page_type != "other":
            header_parts.append(f"Page type: {page_type}")
        if summary:
            header_parts.append(f"Summary: {summary[:200]}")
        if tags:
            header_parts.append(f"Tags: {', '.join(tags[:15])}")

    header = "\n".join(header_parts)
    body_chunks = chunk_text(section_text, chunk_size=chunk_size, overlap=overlap)
    if not body_chunks:
        if section_text.strip():
            return [f"{header}\n\n{section_text.strip()}" if header else section_text.strip()]
        return [header] if header else []

    result = []
    for body in body_chunks:
        result.append(f"{header}\n\n{body}" if header else body)
    return result
