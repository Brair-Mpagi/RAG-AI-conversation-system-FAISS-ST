"""Tests for multi-intent query decomposition."""

from utils.query_decomposition import classify_retrieval_intents, decompose_query


def test_decompose_combined_faculties_and_leadership():
    q = "what faculties does MMU have , who is the VC and dean of students mmu"
    parts = decompose_query(q)
    assert len(parts) >= 3
    assert any("facult" in p.lower() for p in parts)
    assert any("vc" in p.lower() for p in parts)
    assert any("dean" in p.lower() for p in parts)


def test_extract_role_phrases():
    from utils.query_decomposition import extract_role_phrases

    assert "dean of students" in extract_role_phrases("who is the dean of students")
    assert "vice chancellor" in extract_role_phrases("who is the VC")


def test_single_intent_unchanged():
    q = "what faculties does MMU have?"
    assert decompose_query(q) == [q]


def test_classify_hierarchy_and_staff():
    assert "hierarchy" in classify_retrieval_intents("what faculties does MMU have")
    intents = classify_retrieval_intents("who is the VC")
    assert "staff" in intents
