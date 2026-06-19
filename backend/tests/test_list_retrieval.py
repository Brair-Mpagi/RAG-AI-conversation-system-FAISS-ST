"""Tests for faculty staff roster listing retrieval."""

from utils.list_retrieval import (
    demote_individual_staff_pages,
    expand_faculty_staff_rosters,
    is_department_roster_page,
    is_list_query,
    resolve_unit_path_prefix,
)


def test_is_list_query_staff_under_faculty():
    assert is_list_query("list staff under fosti")
    assert is_list_query("great well done list staff under fosti")


def test_resolve_fosti_path():
    assert resolve_unit_path_prefix("list staff under fosti") == (
        "faculty-of-science-technology-and-innovations"
    )


def test_roster_page_detection():
    meta = {
        "url": "https://mmu.ac.ug/university_unit/faculty-of-science-technology-and-innovations/department-of-computer-science/page/2",
        "page_type": "staff_directory",
        "content": "| Names | Position | Qualification\n| Samuel Ocen | Lecturer | PhD",
    }
    assert is_department_roster_page(meta)
    assert not is_department_roster_page({
        "url": "https://mmu.ac.ug/staff_member/tugume-andrew-karitani",
        "page_type": "staff_directory",
        "content": "Assistant Lecturer profile",
    })


def test_expand_fosti_includes_cs_roster_pages():
    metadata = [
        {
            "content": "Faculty dean message only",
            "source": "scraped:8958",
            "url": "https://mmu.ac.ug/university_unit/faculty-of-science-technology-and-innovations",
            "page_type": "department_info",
            "chunk_index": 0,
            "page_title": "FOSTI",
        },
        {
            "content": "| Names | Position |\n| Jack Turihohabwe | Lecturer |",
            "source": "scraped:9251",
            "url": "https://mmu.ac.ug/university_unit/faculty-of-science-technology-and-innovations/department-of-computer-science/page/2",
            "page_type": "staff_directory",
            "chunk_index": 1,
            "page_title": "Department of Computer Science - MMU",
        },
        {
            "content": "| Peter Baranga | Assistant Lecturer |",
            "source": "scraped:9251",
            "url": "https://mmu.ac.ug/university_unit/faculty-of-science-technology-and-innovations/department-of-computer-science/page/2",
            "page_type": "staff_directory",
            "chunk_index": 2,
            "page_title": "Department of Computer Science - MMU",
        },
        {
            "content": "Andrew profile only",
            "source": "scraped:8252",
            "url": "https://mmu.ac.ug/staff_member/tugume-andrew-karitani",
            "page_type": "staff_directory",
            "chunk_index": 0,
            "page_title": "Andrew Karitani Tugume",
        },
    ]
    expanded = expand_faculty_staff_rosters(
        [metadata[0]], "list staff under fosti", metadata, max_roster_chunks=10,
    )
    sources = {i["source"] for i in expanded}
    assert expanded[0]["source"] == "scraped:9251"
    assert "scraped:9251" in sources
    roster_chunks = [i for i in expanded if i["source"] == "scraped:9251"]
    assert len(roster_chunks) >= 2
    assert all(
        float(i.get("score", 0)) > float(metadata[3].get("score", 0) or 0) * 2
        for i in roster_chunks
    )


def test_demote_individual_profiles():
    items = [
        {"url": "https://mmu.ac.ug/staff_member/foo", "score": 5.0},
        {"url": "https://mmu.ac.ug/university_unit/.../department-of-cs/page/2", "score": 3.0},
    ]
    out = demote_individual_staff_pages(items)
    by_url = {i["url"]: i["score"] for i in out}
    assert by_url["https://mmu.ac.ug/staff_member/foo"] == 1.25
    assert by_url["https://mmu.ac.ug/university_unit/.../department-of-cs/page/2"] == 3.0
