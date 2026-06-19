"""LLM-based enrichment for scraped pages (Phase 2)."""

from __future__ import annotations

import json
import logging
import re
from typing import Any, Dict, List, Optional

import requests

from core.config import settings
from utils.content_enrichment import build_rule_enrichment, parse_sections
from utils.llm import generate_custom_response, _resolve_model

logger = logging.getLogger(__name__)

ENRICHMENT_SYSTEM_PROMPT = """You analyze university web pages for a search system.
Return ONLY valid JSON with no markdown fences."""

ENRICHMENT_USER_TEMPLATE = """Analyze this university web page for search/retrieval.

Page title: {title}
URL: {url}
Category: {category}

Section headings:
{headings}

Content excerpt:
{excerpt}

Return JSON exactly in this shape:
{{
  "page_type": "staff_directory|fees|admissions|course_info|policy|calendar|contact|faq|announcement|facilities|department_info|news|other",
  "summary": "1-2 sentence description of what a user can find on this page",
  "tags": ["5-15 lowercase search keywords and phrases"],
  "entities": ["important names, departments, programs mentioned"],
  "section_types": ["types of sections e.g. staff_list, fees_table"],
  "query_aliases": ["3-8 example questions users might ask that this page answers"]
}}"""


def _llm_generate(prompt: str, system: str = ENRICHMENT_SYSTEM_PROMPT) -> str:
    if settings.DEV_MODE:
        return json.dumps({
            "page_type": "other",
            "summary": "Dev mode placeholder summary.",
            "tags": ["university"],
            "entities": [],
            "section_types": [],
            "query_aliases": [],
        })

    return generate_custom_response(
        system_prompt=system,
        user_prompt=prompt,
        json_format=True,
        timeout=120,
    )


def _extract_json(text: str) -> Dict[str, Any]:
    text = (text or "").strip()
    if not text:
        raise ValueError("Empty LLM response")
    try:
        return json.loads(text)
    except json.JSONDecodeError:
        match = re.search(r"\{[\s\S]*\}", text)
        if not match:
            raise ValueError("No JSON object in LLM response")
        return json.loads(match.group(0))


def _normalize_llm_result(raw: Dict[str, Any]) -> Dict[str, Any]:
    page_type = str(raw.get("page_type") or "other").strip().lower()
    valid_types = {
        "staff_directory", "fees", "admissions", "course_info", "policy",
        "calendar", "contact", "faq", "announcement", "facilities",
        "department_info", "news", "other",
    }
    if page_type not in valid_types:
        page_type = "other"

    def _str_list(key: str, max_items: int = 20) -> List[str]:
        val = raw.get(key) or []
        if isinstance(val, str):
            val = [v.strip() for v in val.split(",") if v.strip()]
        if not isinstance(val, list):
            return []
        out = []
        for item in val:
            s = str(item).strip()
            if s and s not in out:
                out.append(s)
            if len(out) >= max_items:
                break
        return out

    return {
        "page_type": page_type,
        "summary": str(raw.get("summary") or "").strip()[:500],
        "tags": _str_list("tags", 20),
        "entities": _str_list("entities", 30),
        "section_types": _str_list("section_types", 15),
        "query_aliases": _str_list("query_aliases", 10),
    }


def build_llm_input(
    page_title: str,
    page_url: str,
    cleaned_content: str,
    sections: List[Dict[str, str]] | Any = None,
    meta_category: str | None = None,
    excerpt_max: int = 3500,
) -> str:
    sections_list = parse_sections(sections) if not isinstance(sections, list) else sections
    headings = "\n".join(
        f"- {(s.get('heading') or '(no heading)')}" for s in sections_list[:25]
    ) or "- (none)"
    excerpt = (cleaned_content or "")[:excerpt_max]
    return ENRICHMENT_USER_TEMPLATE.format(
        title=page_title or "Untitled",
        url=page_url or "",
        category=meta_category or "—",
        headings=headings,
        excerpt=excerpt,
    )


def enrich_page_with_llm(
    page_title: str,
    page_url: str,
    cleaned_content: str,
    sections: List[Dict[str, str]] | Any = None,
    meta_category: str | None = None,
) -> Dict[str, Any]:
    """Call LLM and return normalized enrichment dict."""
    prompt = build_llm_input(
        page_title, page_url, cleaned_content, sections, meta_category,
    )
    raw_text = _llm_generate(prompt)
    parsed = _extract_json(raw_text)
    result = _normalize_llm_result(parsed)
    result["source"] = "llm"
    try:
        model_info = _resolve_model()
        model_name = model_info.name
    except Exception:
        model_name = settings.OLLAMA_PRIMARY_MODEL
    result["model"] = model_name
    return result


def merge_enrichment(rule: Dict[str, Any], llm: Dict[str, Any]) -> Dict[str, Any]:
    """Merge rule-based and LLM enrichment into one JSON object."""
    rule_json = rule.get("enrichment_json") or {}
    tags = list(dict.fromkeys(
        (llm.get("tags") or [])
        + (rule_json.get("tags") or [])
    ))[:30]
    entities = list(dict.fromkeys(
        (llm.get("entities") or [])
        + (rule_json.get("entities") or [])
    ))[:30]

    page_type = llm.get("page_type") or rule_json.get("page_type") or "other"
    if page_type == "other" and rule_json.get("page_type"):
        page_type = rule_json["page_type"]

    merged = {
        "page_type": page_type,
        "summary": llm.get("summary") or "",
        "tags": tags,
        "entities": entities,
        "section_types": llm.get("section_types") or [],
        "query_aliases": llm.get("query_aliases") or [],
        "section_headings": rule_json.get("section_headings") or [],
        "url_keywords": rule_json.get("url_keywords") or [],
        "source": "llm+rule",
        "model": llm.get("model"),
    }
    return merged


def build_search_document_with_summary(
    page_title: str,
    page_url: str,
    search_document: str,
    enrichment_json: Dict[str, Any],
) -> str:
    """Append LLM summary and query aliases to search_document."""
    parts = [search_document] if search_document else []
    summary = enrichment_json.get("summary")
    if summary:
        parts.append(f"Summary: {summary}")
    aliases = enrichment_json.get("query_aliases") or []
    if aliases:
        parts.append(f"Example queries: {', '.join(aliases[:8])}")
    entities = enrichment_json.get("entities") or []
    if entities:
        parts.append(f"Key entities: {', '.join(entities[:15])}")
    return "\n".join(parts)


def enrich_row(row: dict) -> Dict[str, Any]:
    """Full enrichment pipeline for one scraped_content row."""
    title = row.get("page_title") or ""
    url = row.get("page_url") or ""
    content = row.get("cleaned_content") or ""
    sections = parse_sections(row.get("sections_json"))
    meta_category = row.get("meta_category")

    rule = build_rule_enrichment(title, url, content, sections, meta_category)
    llm = enrich_page_with_llm(title, url, content, sections, meta_category)
    merged = merge_enrichment(rule, llm)

    search_doc = build_search_document_with_summary(
        title, url, rule["search_document"], merged,
    )

    return {
        "sections_json": json.dumps(rule["sections_json"], ensure_ascii=False),
        "search_document": search_doc,
        "enrichment_json": json.dumps(merged, ensure_ascii=False),
        "enrichment_status": "done",
    }
