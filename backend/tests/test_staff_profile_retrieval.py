"""Tests for multi-page staff profile retrieval."""

from utils.staff_profile_retrieval import (
    expand_staff_profile_chunks,
    extract_person_name_tokens,
    filter_wrong_person_chunks,
    name_match_factor,
    staff_profile_key,
)


def test_staff_profile_key_normalizes_pagination():
    assert staff_profile_key(
        "https://mmu.ac.ug/staff_member/tugume-andrew-karitani/page/2"
    ) == "tugume-andrew-karitani"
    assert staff_profile_key(
        "https://mmu.ac.ug/staff_member/tugume-andrew-karitani"
    ) == "tugume-andrew-karitani"


def test_name_match_penalizes_wrong_first_name():
    assert name_match_factor("Daniel Tugume - MMU", ["andrew", "tugume"]) < 0.4
    assert name_match_factor("Andrew Karitani Tugume - MMU", ["andrew", "tugume"]) == 1.0


def test_expand_includes_sibling_scraped_ids():
    metadata = [
        {
            "content": "Andrew profile intro",
            "source": "scraped:8252",
            "page_title": "Andrew Karitani Tugume - MMU",
            "url": "https://mmu.ac.ug/staff_member/tugume-andrew-karitani",
            "chunk_type": "body",
            "chunk_index": 3,
        },
        {
            "content": "Email:andrtug@mmu.ac.ug Phone:+256782726787",
            "source": "scraped:8252",
            "page_title": "Andrew Karitani Tugume - MMU",
            "url": "https://mmu.ac.ug/staff_member/tugume-andrew-karitani",
            "chunk_type": "page_card",
            "chunk_index": 0,
        },
        {
            "content": "bio page 2",
            "source": "scraped:9312",
            "page_title": "Andrew Karitani Tugume - MMU",
            "url": "https://mmu.ac.ug/staff_member/tugume-andrew-karitani/page/2",
            "chunk_type": "body",
            "chunk_index": 1,
        },
        {
            "content": "Email:tugume.daniel@mmu.ac.ug",
            "source": "scraped:8280",
            "page_title": "Daniel Tugume - MMU",
            "url": "https://mmu.ac.ug/staff_member/daniel-tugume",
            "chunk_type": "page_card",
            "chunk_index": 0,
        },
    ]
    seed = [metadata[0]]
    expanded = expand_staff_profile_chunks(
        seed,
        "get me the contact of andrew tugume",
        metadata,
    )
    sources = {i["source"] for i in expanded}
    titles = " ".join(i.get("page_title", "") for i in expanded).lower()
    assert "scraped:8252" in sources
    assert "scraped:9312" in sources
    assert "daniel" not in titles
    assert any("andrtug" in i["content"] for i in expanded)


def test_filter_drops_daniel_for_andrew_query():
    items = [
        {"page_title": "Andrew Karitani Tugume", "content": "a", "score": 2.0},
        {"page_title": "Daniel Tugume", "content": "b", "score": 3.0},
    ]
    out = filter_wrong_person_chunks(items, "contact andrew tugume")
    assert all("daniel" not in (i["page_title"] or "").lower() for i in out)


def test_extract_person_name_tokens():
    assert extract_person_name_tokens("get me the contact of andrew tugume") == ["andrew", "tugume"]
