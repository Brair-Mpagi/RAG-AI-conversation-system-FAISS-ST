"""Tests for rule-based scraped content enrichment."""

from utils.content_enrichment import (
    build_page_card_text,
    build_rule_enrichment,
    build_section_chunk_text,
    parse_sections,
)


def test_staff_page_enrichment():
    sections = [
        {"heading": "Lecturers", "text": "Jack Turihohabwe – Lecturer\nSamuel Ocen – Lecturer"},
        {"heading": "Teaching Assistants", "text": "Derrick Mwanje – Teaching Assistant"},
    ]
    built = build_rule_enrichment(
        "Department of Computer Science",
        "https://mmu.ac.ug/university_unit/faculty-of-science-technology-and-innovations/department-of-computer-science/",
        "Head of Department Prof. Example",
        sections=sections,
    )
    assert built["enrichment_json"]["page_type"] == "staff_directory"
    tags = " ".join(built["enrichment_json"]["tags"]).lower()
    assert "lecturer" in tags or "lecturers" in tags
    assert "fosti" in tags.lower() or "science" in tags
    assert "Lecturers" in built["search_document"]


def test_parse_sections_json_string():
    raw = '[{"heading": "Fees", "text": "Tuition per semester"}]'
    parsed = parse_sections(raw)
    assert len(parsed) == 1
    assert parsed[0]["heading"] == "Fees"


def test_section_chunk_includes_heading():
    built = build_rule_enrichment("Fees", "https://mmu.ac.ug/fees/", "Amounts...", sections=[])
    chunks = build_section_chunk_text(
        "Fees", "Tuition", "500000 UGX per semester", built["enrichment_json"],
    )
    assert chunks
    assert "Section: Tuition" in chunks[0]


def test_page_card_non_empty():
    built = build_rule_enrichment("Admissions", "https://mmu.ac.ug/admissions/", "Apply now...")
    card = build_page_card_text("Admissions", built["search_document"], built["enrichment_json"])
    assert len(card) >= 30
