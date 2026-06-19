"""Tests for LLM enrichment helpers."""

import json

from utils.content_enrichment import build_rule_enrichment
from utils.llm_enrichment import enrich_page_with_llm, merge_enrichment, _normalize_llm_result


def test_normalize_llm_result():
    raw = {
        "page_type": "staff_directory",
        "summary": "CS department staff list.",
        "tags": ["lecturers", "staff"],
        "entities": ["Jack Turihohabwe"],
        "query_aliases": ["who teaches computer science"],
    }
    out = _normalize_llm_result(raw)
    assert out["page_type"] == "staff_directory"
    assert "lecturers" in out["tags"]


def test_merge_enrichment_combines_tags():
    rule = build_rule_enrichment(
        "Department of Computer Science",
        "https://mmu.ac.ug/.../department-of-computer-science/",
        "Jack Turihohabwe – Lecturer",
        sections=[{"heading": "Lecturers", "text": "Jack Turihohabwe"}],
    )
    llm = {
        "page_type": "staff_directory",
        "summary": "Staff directory for CS.",
        "tags": ["computer science faculty"],
        "entities": ["Jack Turihohabwe"],
        "section_types": ["staff_list"],
        "query_aliases": ["CS lecturers"],
    }
    merged = merge_enrichment(rule, llm)
    assert merged["page_type"] == "staff_directory"
    assert merged["source"] == "llm+rule"
    assert any("lecturer" in t.lower() for t in merged["tags"])


def test_enrich_page_with_llm_dev_mode(monkeypatch):
    from core.config import settings
    monkeypatch.setattr(settings, "DEV_MODE", True)
    result = enrich_page_with_llm("Fees", "https://mmu.ac.ug/fees/", "Tuition 500000")
    assert result["page_type"]
    assert isinstance(result["tags"], list)
