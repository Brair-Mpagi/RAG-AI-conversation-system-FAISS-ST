"""Tests for multi-intent context assembly."""

from utils.rag import _merge_retrieval_results, assemble_context_for_prompt


def test_merge_reserves_slots_per_subquery():
    items = [
        {"content": "hierarchy chunk", "source": "structured_db", "score": 2.0, "_sub_query": 0},
        {"content": "vc page " + "x" * 200, "source": "scraped:1", "score": 9.0, "_sub_query": 1},
        {"content": "vc page duplicate", "source": "scraped:2", "score": 8.5, "_sub_query": 1},
        {"content": "dean of students rosaline", "source": "scraped:8450", "score": 4.0, "_sub_query": 2},
        {"content": "generic news", "source": "scraped:99", "score": 7.0, "_sub_query": 1},
    ]
    merged = _merge_retrieval_results(items, max_items=5, min_per_subquery=1)
    texts = " ".join(i["content"] for i in merged).lower()
    assert "hierarchy" in texts
    assert "dean of students" in texts


def test_assemble_context_prioritizes_each_subquery():
    items = [
        {"content": "faculties list", "source": "structured_db", "_sub_query": 0},
        {"content": "vc prof achanga", "source": "scraped:1", "_sub_query": 1},
        {"content": "dean ssali rosaline", "source": "scraped:8450", "_sub_query": 2},
        {"content": "filler " * 400, "source": "scraped:9", "_sub_query": 1},
    ]
    ctx = assemble_context_for_prompt(items, "faculties and vc and dean", max_chars=800)
    assert "faculties" in ctx.lower()
    assert "dean ssali" in ctx.lower()
