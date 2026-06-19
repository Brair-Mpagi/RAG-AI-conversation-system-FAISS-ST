"""Optional live Ollama integration test (skipped unless OLLAMA_INTEGRATION=1)."""

import os

import pytest

pytestmark = pytest.mark.skipif(
    os.getenv("OLLAMA_INTEGRATION") != "1",
    reason="Set OLLAMA_INTEGRATION=1 to run live Ollama tests",
)


def test_live_enrich_page_with_ollama():
    from utils.llm_enrichment import enrich_page_with_llm

    result = enrich_page_with_llm(
        "Department of Computer Science",
        "https://mmu.ac.ug/department-of-computer-science/",
        "Jack Turihohabwe – Lecturer. Samuel Ocen – Lecturer.",
        sections=[{"heading": "Lecturers", "text": "Jack Turihohabwe – Lecturer"}],
    )
    assert result.get("page_type")
    assert result.get("tags")
    assert result.get("summary")
