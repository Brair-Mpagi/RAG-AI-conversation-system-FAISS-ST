"""MMU Chatbot – Input Pre-processing (Spec §4-§7).

Pipeline:
  1. Normalise (lowercase, trim, punctuation – preserve acronyms)
  2. Spelling correction with MMU dictionary
  3. Abbreviation expansion
  4. Greeting extraction (split "hello, what programs?" → greeting + query)
  5. Query reformulation (inject MMU context keywords)
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from typing import List, Optional

# ---------------------------------------------------------------------------
# MMU Acronym / Abbreviation dictionaries
#
# Static entries are generic academic / administrative abbreviations that
# do not change with the university's organisational structure.
#
# University-specific entries (entity codes, faculty short-names) are loaded
# dynamically from the database via utils.db_config so that an admin can
# rename, add or remove faculties / departments from the admin panel and have
# the chatbot reflect the change immediately after the next Rebuild Index.
# ---------------------------------------------------------------------------

_STATIC_ABBREVIATIONS: dict[str, str] = {
    "mmu": "Mountains of the Moon University",
    "cs": "Computer Science",
    "it": "Information Technology",
    "ict": "Information and Communication Technology",
    "bba": "Bachelor of Business Administration",
    "mba": "Master of Business Administration",
    "bsc": "Bachelor of Science",
    "msc": "Master of Science",
    "pgd": "Postgraduate Diploma",
    "phd": "Doctor of Philosophy",
    # Administrative / legacy abbreviations
    "dvc": "Deputy Vice Chancellor",
    "vc": "Vice Chancellor",
    "ar": "Academic Registrar",
    "gpa": "Grade Point Average",
    "cgpa": "Cumulative Grade Point Average",
    "tbd": "To Be Determined",
    "sem": "Semester",
    "dept": "Department",
    "prog": "Program",
    "accom": "Accommodation",
    "reg": "Registration",
    "lib": "Library",
    "ugx": "Ugandan Shillings",
    "sacco": "Savings and Credit Cooperative",
    # Systems / platforms (Fix 3)
    "aims": "Academic Information Management System",
    "elearning": "e-learning platform",
    "e-learning": "e-learning platform",
    "lms": "Learning Management System",
    "moodle": "Moodle e-learning platform",
    "odel": "Open Distance and e-Learning",
    "erp": "Enterprise Resource Planning system",
    "sms": "Student Management System",
    "mis": "Management Information System",
}

# Words that should stay uppercase (acronyms) — static generic set.
# Entity codes from the DB are automatically added at lookup time.
_STATIC_PRESERVE_UPPER = {
    "mmu", "ict", "it", "cs", "bba", "mba", "bsc", "msc", "pgd", "phd",
    "gpa", "cgpa", "ugx", "vc", "dvc", "ar",
    "sacco", "faiss", "rag", "ai", "api",
    # Systems / platforms (Fix 3)
    "aims", "lms", "odel", "erp", "sms", "mis",
}


def _get_abbreviations() -> dict[str, str]:
    """Return merged abbreviation dict: static defaults + DB entity codes."""
    try:
        from utils.db_config import get_db_abbreviations
        db_abbrevs = get_db_abbreviations()
        # Static entries win on conflict (they are more specific/correct)
        merged = dict(db_abbrevs)
        merged.update(_STATIC_ABBREVIATIONS)
        return merged
    except Exception:
        return _STATIC_ABBREVIATIONS


def _get_preserve_upper() -> set[str]:
    """Return set of tokens that must NOT be lowercased."""
    try:
        from utils.db_config import get_db_abbreviations
        db_keys = set(get_db_abbreviations().keys())
        return _STATIC_PRESERVE_UPPER | db_keys
    except Exception:
        return _STATIC_PRESERVE_UPPER


# Public aliases used by normalise() and expand_abbreviations()
def MMU_ABBREVIATIONS() -> dict[str, str]:  # noqa: N802 — kept for back-compat
    return _get_abbreviations()

PRESERVE_UPPER = _STATIC_PRESERVE_UPPER  # updated dynamically in normalise()

# ---------------------------------------------------------------------------
# Spelling correction – simple MMU-specific dictionary with edit distance
# ---------------------------------------------------------------------------

MMU_TERMS: dict[str, str] = {
    # Common misspellings → correct
    "admision": "admission", "admisions": "admissions",
    "addmission": "admission", "addmissions": "admissions",
    "accomodation": "accommodation", "acommodation": "accommodation",
    "sholarship": "scholarship", "scholarhip": "scholarship",
    "registation": "registration", "registraion": "registration",
    "programm": "program", "programes": "programs", "programmes": "programs",
    "tution": "tuition", "tuiton": "tuition",
    "univesity": "university", "univeristy": "university", "unversity": "university",
    "mountians": "mountains", "moutains": "mountains",
    "libary": "library", "libarary": "library",
    "hostal": "hostel", "hostels": "hostels",
    "exams": "exams", "examintion": "examination",
    "feees": "fees", "feee": "fee",
    "bussiness": "business", "buisness": "business",
    "engneering": "engineering", "enginering": "engineering",
    "calender": "calendar", "calandar": "calendar",
    "departmant": "department", "deparment": "department",
    "facullty": "faculty", "faclty": "faculty",
    "kampla": "kampala", "fortportal": "Fort Portal",
    "gradution": "graduation", "graduaton": "graduation",
    "semster": "semester", "semesta": "semester",
    "lecturers": "lecturers", "lectureos": "lecturers",
    "scince": "science", "sciense": "science",
    "managment": "management", "managemnt": "management",
    "educaton": "education", "eduction": "education",
    "tecnology": "technology", "tecnolgy": "technology",
}

# ---------------------------------------------------------------------------
# Greeting patterns (for extraction, not classification)
# ---------------------------------------------------------------------------

_GREETING_PREFIX_RE = re.compile(
    r"^(hi|hello|hey|hiya|howdy|yo|sup|hola|greetings?|good\s*(?:morning|afternoon|evening|day|night))"
    r"[\s,!.;:]*",
    re.I,
)

# ---------------------------------------------------------------------------
# Data classes
# ---------------------------------------------------------------------------

# Temporal query keywords — signals the user wants upcoming/current info (Fix 2)
_TEMPORAL_KEYWORDS = [
    "next intake", "upcoming intake", "next admission", "when is intake",
    "next semester", "current semester", "this semester", "next academic",
    "this year", "next year", "current year", "upcoming", "next",
    "when is", "when are", "when will", "when does",
    "current intake", "open intake", "intake date", "intake period",
    "application deadline", "admission deadline", "closing date",
]


@dataclass
class PreprocessResult:
    """Output of the preprocessing pipeline."""
    original: str
    normalised: str
    corrected_tokens: list[str] = field(default_factory=list)
    expanded_abbreviations: list[str] = field(default_factory=list)
    greeting_detected: bool = False
    greeting_text: str = ""
    query_text: str = ""  # Query portion after greeting extraction
    reformulated: str = ""
    has_mmu_context: bool = False
    is_temporal_query: bool = False  # True when user asks about upcoming/current events (Fix 2)

# ---------------------------------------------------------------------------
# Pipeline functions
# ---------------------------------------------------------------------------

def _normalise(text: str) -> str:
    """§4.1 Normalise: lowercase (preserving acronyms), trim, normalise punctuation."""
    text = text.strip()
    text = re.sub(r"\s+", " ", text)
    # Normalise smart quotes and dashes
    text = text.replace("\u2018", "'").replace("\u2019", "'")
    text = text.replace("\u201c", '"').replace("\u201d", '"')
    text = text.replace("\u2013", "-").replace("\u2014", "-")
    # Lowercase but preserve acronyms
    words = text.split()
    result = []
    for w in words:
        stripped = re.sub(r"[^\w]", "", w).lower()
        if stripped in PRESERVE_UPPER:
            result.append(w)  # keep original casing
        else:
            result.append(w.lower())
    return " ".join(result)


def _correct_spelling(text: str) -> tuple[str, list[str]]:
    """§4.2 Spelling correction using MMU dictionary."""
    corrections: list[str] = []
    words = text.split()
    corrected = []
    for w in words:
        # Strip punctuation for lookup
        clean = re.sub(r"[^\w]", "", w).lower()
        if clean in MMU_TERMS:
            replacement = MMU_TERMS[clean]
            # Preserve any trailing punctuation
            suffix = w[len(clean):] if len(w) > len(clean) else ""
            corrected.append(replacement + suffix)
            corrections.append(f"{clean}→{replacement}")
        else:
            corrected.append(w)
    return " ".join(corrected), corrections


def _expand_abbreviations(text: str) -> tuple[str, list[str]]:
    """§4.3 Abbreviation expansion – retain original alongside expansion."""
    abbrevs = _get_abbreviations()
    expanded: list[str] = []
    words = text.split()
    result_tokens = []
    for w in words:
        clean = re.sub(r"[^\w]", "", w).lower()
        if clean in abbrevs:
            expansion = abbrevs[clean]
            result_tokens.append(f"{w} ({expansion})")
            expanded.append(f"{clean}→{expansion}")
        else:
            result_tokens.append(w)
    return " ".join(result_tokens), expanded


def _extract_greeting(text: str) -> tuple[bool, str, str]:
    """Split 'hello, what programs?' → (True, 'hello', 'what programs?')."""
    m = _GREETING_PREFIX_RE.match(text)
    if m:
        greeting_part = m.group(1).strip()
        rest = text[m.end():].strip()
        if rest:
            # There's a real query after the greeting
            return True, greeting_part, rest
        else:
            # Pure greeting, no query part
            return True, greeting_part, ""
    return False, "", text


def _reformulate(query: str, history: list[str] | None = None) -> str:
    """§7 Query reformulation – add MMU context keywords and conversation context."""
    parts = [query]
    # Add MMU context if not already present
    if "mmu" not in query.lower() and "mountains of the moon" not in query.lower():
        parts.append("(at Mountains of the Moon University)")
    # Include last 3 turns of conversation for context
    if history:
        recent = history[-3:]
        context_snippet = " | ".join(h[:100] for h in recent)
        parts.append(f"[context: {context_snippet}]")
    return " ".join(parts)


# ---------------------------------------------------------------------------
# Main entry point
# ---------------------------------------------------------------------------

def preprocess(message: str, history: list[str] | None = None) -> PreprocessResult:
    """Run the full preprocessing pipeline on a user message.
    
    Returns a PreprocessResult with all intermediate results.
    """
    original = message
    
    # 1. Normalise
    normalised = _normalise(message)
    
    # 2. Spelling correction
    corrected, correction_list = _correct_spelling(normalised)
    
    # 3. Abbreviation expansion (for retrieval – we keep both forms)
    expanded, expansion_list = _expand_abbreviations(corrected)
    
    # 4. Extract greeting if present
    greeting_detected, greeting_text, query_text = _extract_greeting(corrected)
    
    # If the whole message is just a greeting, query_text is empty
    effective_query = query_text if query_text else corrected
    
    # 5. Reformulate (for retrieval purposes)
    reformulated = _reformulate(expanded if query_text else expanded, history)
    
    # Check if query has MMU context
    has_mmu = any(kw in corrected.lower() for kw in [
        "mmu", "mountains", "admission", "faculty", "program", "tuition",
        "fee", "campus", "scholarship", "hostel", "library", "department",
        "course", "registration", "fort portal",
    ])

    # Fix 2: Detect temporal queries (upcoming/next/current events)
    msg_lower = corrected.lower()
    is_temporal = any(kw in msg_lower for kw in _TEMPORAL_KEYWORDS)

    return PreprocessResult(
        original=original,
        normalised=normalised,
        corrected_tokens=correction_list,
        expanded_abbreviations=expansion_list,
        greeting_detected=greeting_detected,
        greeting_text=greeting_text,
        query_text=query_text,
        reformulated=reformulated,
        has_mmu_context=has_mmu,
        is_temporal_query=is_temporal,
    )
