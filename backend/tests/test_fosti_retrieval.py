"""Tests for FOSTI RAG retrieval improvements (Spec §6, §8, §9)."""

from unittest.mock import MagicMock, patch
import pytest
import numpy as np
from utils.rag import check_structured_data, _merge_hybrid_candidates, resolve_coreferences, _keyword_rerank, _normalize_word, _mmr_rerank

def test_merge_hybrid_candidates_preserves_unique_chunks():
    # Vector chunks of the same page
    vector_candidates = [
        {"source": "scraped:8958", "chunk_index": 0, "score": 3.5, "chunk_type": "vector"},
        {"source": "scraped:8958", "chunk_index": 9, "score": 2.8, "chunk_type": "vector"},
    ]
    # Fulltext hit of the same page
    fulltext_hits = [
        {"source": "scraped:8958", "score": 1.5, "chunk_type": "fulltext"},
    ]
    
    merged = _merge_hybrid_candidates(fulltext_hits, vector_candidates)
    
    # Should contain all three since they have unique chunk identifiers
    assert len(merged) == 3
    sources = [item.get("source") for item in merged]
    assert all(s == "scraped:8958" for s in sources)
    
    # Check that highest score is first
    assert merged[0]["score"] == 3.5
    assert merged[1]["score"] == 2.8
    assert merged[2]["score"] == 1.5

@patch("databases.session.SessionLocal")
def test_check_structured_data_query_aware_program(mock_session_local):
    # Mock DB session and execution
    mock_db = MagicMock()
    mock_session_local.return_value = mock_db
    
    # Mock data returned by DB
    mock_rows = [
        ("Bachelor of Science in Computer Science", "Desc CS", '{"level": "undergraduate", "duration_years": 3}', "CS1", "Department of Computer Science", "Program"),
        ("Bachelor of Information Technology", "Desc IT", '{"level": "undergraduate", "duration_years": 3}', "IT1", "Department of Computer Science", "Program")
    ]
    mock_db.execute.return_value.fetchall.return_value = mock_rows
    
    # Test query containing "computer science" triggers structured data
    results = check_structured_data("what courses are under computer science?")
    
    assert len(results) == 2
    assert "Computer Science" in results[0]["content"]
    assert "Information Technology" in results[1]["content"]
    
    # Verify it used targeted filtering (the SQL query should contain ID filters or LIKE clauses)
    args, kwargs = mock_db.execute.call_args
    sql_query = str(args[0])
    assert "e.entity_id IN" in sql_query or "e.name LIKE" in sql_query

@patch("databases.session.SessionLocal")
def test_check_structured_data_staff_trigger(mock_session_local):
    mock_db = MagicMock()
    mock_session_local.return_value = mock_db
    
    mock_rows = [
        ("Department of Computer Science", "Desc", '{"head": "Samuel Ocen", "head_title": "Head of Department"}', "Faculty of Science Technology and Innovation", "Department")
    ]
    mock_db.execute.return_value.fetchall.return_value = mock_rows
    
    # Test query containing HOD
    results = check_structured_data("who is the HOD of computer science?")
    
    assert len(results) == 1
    assert "Samuel Ocen" in results[0]["content"]
    assert "Head of Department" in results[0]["content"]

def test_resolve_coreferences_pronouns():
    # Previous turn mentions Computer Science
    history = [
        "who is the current HOD of Computer Science?",
        "Samuel Ocen is the HOD of Computer Science."
    ]
    
    # Query with pronoun "he" should inherit entity anchor
    resolved = resolve_coreferences("is he under FOSTI?", history)
    
    assert "Computer Science" in resolved
    assert "FOSTI" in resolved
    
    # Query without pronouns or short phrase should stay unchanged
    unchanged = resolve_coreferences("what is the deadline for tuition payment?", history)
    assert unchanged == "what is the deadline for tuition payment?"

def test_keyword_rerank():
    candidates = [
        {"content": "Welcome to Mountains of the Moon University. This is a generic portal.", "page_title": "MMU Home", "section_heading": "Introduction", "score": 1.0},
        {"content": "The Faculty of Science Technology and Innovations (FOSTI) has three departments.", "page_title": "Faculty of Science Technology and Innovations", "section_heading": "Overview", "score": 0.5}
    ]
    
    reranked = _keyword_rerank(candidates, "tell me about FOSTI courses")
    
    # The FOSTI chunk should now have a significantly boosted score and be ranked first
    assert reranked[0]["page_title"] == "Faculty of Science Technology and Innovations"
    assert reranked[0]["score"] > 1.0

@patch("databases.session.SessionLocal")
def test_check_structured_data_multi_trigger(mock_session_local):
    mock_db = MagicMock()
    mock_session_local.return_value = mock_db

    hierarchy_rows = [
        (1, "Mountains of the Moon University", "MMU", "MMU", "Univ", "university", "University", None, None, 10),
        (2, "Faculty of Science Technology and Innovations", "FOSTI", "FOSTI", "Faculty", "faculty", "Faculty", "Mountains of the Moon University", "MMU", 15),
    ]
    staff_rows = [
        ("Vice Chancellor Office", "VC message", '{"role": "Vice Chancellor"}', None, "Office"),
    ]
    mock_db.execute.return_value.fetchall.side_effect = [hierarchy_rows, staff_rows]

    results = check_structured_data(
        "what faculties does MMU have, who is the VC"
    )

    assert len(results) >= 2
    types = {r["type"] for r in results}
    assert "hierarchy" in types
    assert "staff" in types


@patch("databases.session.SessionLocal")
def test_check_structured_data_hierarchy_trigger(mock_session_local):
    mock_db = MagicMock()
    mock_session_local.return_value = mock_db
    
    # e.entity_id, e.name, e.entity_code, e.short_name, e.description, et.type_name, et.type_label, p.name, p.entity_code, chunk_count
    mock_rows = [
        (1, "Mountains of the Moon University", "MMU", "MMU", "Univ", "university", "University", None, None, 10),
        (2, "Faculty of Science Technology and Innovations", "FOSTI", "FOSTI", "Faculty", "faculty", "Faculty", "Mountains of the Moon University", "MMU", 15),
        (3, "Department of Computer Science", "CS", "CS", "Dept", "department", "Department", "Faculty of Science Technology and Innovations", "FOSTI", 5)
    ]
    mock_db.execute.return_value.fetchall.return_value = mock_rows
    
    # Test broad queries for hierarchy trigger
    results = check_structured_data("What faculties does MMU have?")
    
    assert len(results) == 1
    assert "Mountains of the Moon University" in results[0]["content"]
    assert "Faculty of Science Technology and Innovations" in results[0]["content"]
    assert "Department of Computer Science" in results[0]["content"]

def test_normalize_word():
    assert _normalize_word("lecturers") == "lecturer"
    assert _normalize_word("departments") == "department"
    assert _normalize_word("faculties") == "faculty"
    assert _normalize_word("courses") == "course"
    assert _normalize_word("business") == "business"
    assert _normalize_word("lecturer") == "lecturer"

def test_adaptive_mmr_name_query():
    # Mock embeddings: query, candidate 1 (profile), candidate 2 (directory card), candidate 3 (unrelated)
    # Profile and directory card are very similar to each other
    q_emb = np.array([[1.0, 0.0, 0.0]])
    c_embs = np.array([
        [0.9, 0.1, 0.0],  # Candidate 1 (very relevant, highly similar)
        [0.85, 0.15, 0.0], # Candidate 2 (very relevant, highly similar to Cand 1)
        [0.1, 0.0, 0.9]   # Candidate 3 (unrelated, highly diverse)
    ])
    
    candidates = [
        {"content": "Samuel Ocen member profile page.", "score": 0.9},
        {"content": "Samuel Ocen CS directory contact card.", "score": 0.85},
        {"content": "Agriculture student admission details.", "score": 0.1}
    ]
    
    # Under standard strict MMR diversity (lambda = 0.5), Candidate 2 is discarded for being too redundant
    reranked_diverse = _mmr_rerank(q_emb, c_embs, candidates, top_k=2, lambda_param=0.5)
    assert reranked_diverse[0]["content"] == "Samuel Ocen member profile page."
    assert reranked_diverse[1]["content"] == "Agriculture student admission details."
    
    # Under adaptive name query MMR (lambda = 0.95), Candidate 2 is preserved because relevance is prioritized
    reranked_relevant = _mmr_rerank(q_emb, c_embs, candidates, top_k=2, lambda_param=0.95)
    assert reranked_relevant[0]["content"] == "Samuel Ocen member profile page."
    assert reranked_relevant[1]["content"] == "Samuel Ocen CS directory contact card."
