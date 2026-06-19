"""MMU Chatbot – Intent Classification (Spec §5).

Intent classes: MMU, SOCIAL, CLARIFICATION, OUT_OF_SCOPE, AMBIGUOUS, SENSITIVE.
Mixed Query Rule: if message contains both social AND MMU content → treat as MMU.
Greeting + Query: "hello, what programs?" → MMU (with greeting flag).
"""

from __future__ import annotations

from dataclasses import dataclass
from enum import Enum
import re


class IntentType(str, Enum):
    MMU = "MMU"                          # University-related query
    SOCIAL = "SOCIAL"                    # Greeting, thanks, farewell, small talk
    CLARIFICATION = "CLARIFICATION"      # User asking for clarification
    AMBIGUOUS = "AMBIGUOUS"              # Can't determine intent
    OUT_OF_SCOPE = "OUT_OF_SCOPE"        # Non-MMU topics
    SENSITIVE_OR_PERSONAL_DATA_REQUEST = "SENSITIVE_OR_PERSONAL_DATA_REQUEST"
    # Keep old names as aliases for backward compat
    GREETING = "SOCIAL"
    GENERAL_CAMPUS_INFORMATION = "MMU"


@dataclass(frozen=True)
class IntentResult:
    intent: IntentType
    confidence: float
    reason: str
    has_greeting: bool = False  # True when message starts with a greeting


SENSITIVE_PATTERNS = [
    # Personal data — require possessive pronoun or explicit entity ID framing
    re.compile(r"\b(my|his|her|their)\s+(student|staff|applicant)\s*(id|number|result|grade|status)\b", re.I),
    re.compile(r"\b(student|staff|user|person|applicant)\s*(id|number)\b", re.I),
    re.compile(r"\b(my admission status|check my result|my student result|my marks|my scores?)\b", re.I),
    re.compile(r"\b(transcript|gpa)\b", re.I),
    re.compile(r"\b(password|pin|otp|login|credential)\b", re.I),
]

OUT_OF_SCOPE_PATTERNS = [
    re.compile(r"\b(weather|news|recipe|joke|movie|sports|politics|election|stock|crypto)\b", re.I),
    re.compile(r"\b(what\s+time|time\s+in|capital\s+of|president\s+of)\b", re.I),
    re.compile(r"\b(bitcoin|forex|betting|gambling)\b", re.I),
]

# Pure greetings (standalone only – no query after)
GREETING_PATTERNS = [
    re.compile(r"^\s*(hi|hello|hey|hiya|howdy|yo|sup|hola|greetings?)\s*[!?.]*\s*$", re.I),
    re.compile(r"^\s*(good\s*(morning|afternoon|evening|day|night))\s*[!?.]*\s*$", re.I),
    re.compile(r"^\s*(how\s+are\s+you|how\s+is\s+it\s+going|how\s+do\s+you\s+do|what'?s\s+up)\s*[!?.]*\s*$", re.I),
    re.compile(r"^\s*(thanks?|thank\s+you|thx|ty|cheers)\s*[!?.]*\s*$", re.I),
    re.compile(r"^\s*(bye|goodbye|see\s+you|take\s+care|farewell|later)\s*[!?.]*\s*$", re.I),
    re.compile(r"^\s*(nice\s+to\s+meet\s+you|pleased\s+to\s+meet\s+you)\s*[!?.]*\s*$", re.I),
    re.compile(r"^\s*(ok|okay|alright|sure|got\s+it|fine)\s*[!?.]*\s*$", re.I),
    re.compile(r"^\s*(welcome|you'?re\s+welcome|no\s+problem)\s*[!?.]*\s*$", re.I),
    # Identity / self-referential questions (should NOT trigger RAG)
    re.compile(r"^\s*(who\s+are\s+you|what\s+are\s+you|what\s+is\s+your\s+name|what'?s\s+your\s+name)\s*[!?.]*\s*$", re.I),
    re.compile(r"^\s*(what\s+can\s+you\s+do|what\s+do\s+you\s+do|how\s+can\s+you\s+help)\s*[!?.]*\s*$", re.I),
    re.compile(r"^\s*(are\s+you\s+a\s+(bot|robot|ai|human|person|chatbot))\s*[!?.]*\s*$", re.I),
    re.compile(r"^\s*he+i+\b.*\s*(who\s+are\s+you|what\s+are\s+you)?\s*[!?.]*\s*$", re.I),
    # Multi-word greetings / small talk ("hello there, how are you", "hey, what's up", etc.)
    re.compile(r"^\s*(hi|hello|hey|hiya|howdy|yo|sup)\s+(there|everyone|friend)?[\s,!.]*"
               r"(how\s+are\s+you|how'?s\s+it\s+going|what'?s\s+up|how\s+do\s+you\s+do)?\s*[!?.]*\s*$", re.I),
    re.compile(r"^\s*(good\s*(morning|afternoon|evening|day))\s*[,!.]*\s*"
               r"(how\s+are\s+you|how'?s\s+it\s+going)?\s*[!?.]*\s*$", re.I),
    # Conversational / small-talk phrases (no campus content)
    re.compile(r"^\s*(i'?m\s+(good|fine|great|okay|doing\s+well)|nothing\s+much|not\s+much|i'?m\s+here)\s*[!?.]*\s*$", re.I),
]

# Greeting prefixes (can appear before a real query)
GREETING_PREFIX_RE = re.compile(
    r"^(hi|hello|hey|hiya|howdy|yo|sup|hola|greetings?|good\s*(?:morning|afternoon|evening|day|night))"
    r"[\s,!.;:]+",
    re.I,
)

CLARIFICATION_PATTERNS = [
    re.compile(r"^\s*(what do you mean|can you (explain|clarify)|i don'?t understand|please (elaborate|explain))", re.I),
    re.compile(r"^\s*(could you be more specific|what exactly|which one)\b", re.I),
]

# Safety / escalation patterns
SAFETY_PATTERNS = [
    re.compile(r"\b(kill\s+(myself|me)|suicide|self[- ]harm|end\s+my\s+life)\b", re.I),
    re.compile(r"\b(complaint|i\s+want\s+to\s+complain|speak\s+to\s+(a\s+)?human|talk\s+to\s+someone)\b", re.I),
    re.compile(r"\b(harassment|abuse|threaten|assault)\b", re.I),
]

# Static campus keywords — generic academic terms that never change.
# University-specific entity codes and names are loaded dynamically from
# the database by _get_campus_keywords() below.
_STATIC_CAMPUS_KEYWORDS = [
    "admission", "admissions", "apply", "application", "program", "programme",
    "course", "courses", "tuition", "fees", "fee", "department", "faculty",
    "campus", "library", "hostel", "accommodation", "registration", "register",
    "calendar", "timetable", "schedule", "policy", "policies", "exam",
    "examination", "events", "event", "scholarship", "scholarships",
    "contact", "office", "staff", "lecturer", "dean", "registrar",
    "mmu", "mountains of the moon", "fort portal",
    "graduation", "convocation", "semester", "academic",
    "research", "thesis", "dissertation", "undergraduate", "postgraduate",
    "masters", "bachelor", "diploma", "certificate",
    "student", "students", "class", "lecture", "laboratory", "lab",
    "bursar", "finance", "payment", "deadline", "requirement",
    "curriculum", "syllabus", "transcript", "credit",
    # Structural keywords
    "faculties", "departments", "schools", "colleges",
    "hierarchy", "structure", "units", "entities",
    # IT systems / platforms (Fix 3)
    "aims", "elearning", "e-learning", "lms", "moodle", "odel",
    "portal", "erp", "mis", "sms", "student portal", "online portal",
    "student information system", "academic information", "learning management",
    "distance learning", "online learning", "blended learning",
    "intake", "intake period", "application window", "open day",
]


def _get_campus_keywords() -> list[str]:
    """Return merged campus keyword list: static terms + DB entity names/codes."""
    try:
        from utils.db_config import get_db_campus_keywords
        db_kws = get_db_campus_keywords()
        combined = list(_STATIC_CAMPUS_KEYWORDS)
        for kw in db_kws:
            if kw not in combined:
                combined.append(kw)
        return combined
    except Exception:
        return list(_STATIC_CAMPUS_KEYWORDS)


# Back-compat alias — kept as a list for code that iterates CAMPUS_KEYWORDS directly
CAMPUS_KEYWORDS = _STATIC_CAMPUS_KEYWORDS


def classify_intent(message: str, has_greeting_prefix: bool = False) -> IntentResult:
    """Classify user intent following spec §5.

    Args:
        message: The user message (after preprocessing).
        has_greeting_prefix: True if preprocessor detected a greeting prefix
                            (meaning the message was "hello, <query>" and the
                            greeting was already extracted).
    """
    text = (message or "").strip()
    lowered = text.lower()

    if not lowered:
        return IntentResult(IntentType.OUT_OF_SCOPE, 0.0, "empty_message")

    # --- Sensitive data request ---
    for pattern in SENSITIVE_PATTERNS:
        if pattern.search(lowered):
            return IntentResult(IntentType.SENSITIVE_OR_PERSONAL_DATA_REQUEST, 0.9, "sensitive_pattern", has_greeting_prefix)

    # --- Safety / escalation ---
    for pattern in SAFETY_PATTERNS:
        if pattern.search(lowered):
            return IntentResult(IntentType.SENSITIVE_OR_PERSONAL_DATA_REQUEST, 0.95, "safety_escalation", has_greeting_prefix)

    # --- Mixed query rule (spec §2): greeting + campus content → MMU ---
    has_campus_keyword = any(kw in lowered for kw in _get_campus_keywords())

    # Check if message starts with greeting but also has campus content
    greeting_prefix_match = GREETING_PREFIX_RE.match(text)
    if greeting_prefix_match and has_campus_keyword:
        return IntentResult(IntentType.MMU, 0.85, "mixed_greeting_and_mmu", True)

    # If preprocessor already extracted greeting and the remaining text has campus keywords
    if has_greeting_prefix and has_campus_keyword:
        return IntentResult(IntentType.MMU, 0.85, "greeting_plus_campus", True)

    # --- Pure greeting/small talk (no campus content) ---
    if not has_campus_keyword:
        for pattern in GREETING_PATTERNS:
            if pattern.search(lowered):
                return IntentResult(IntentType.SOCIAL, 0.95, "greeting_pattern")

    # --- Conversational text after greeting extraction (no campus keywords) ---
    # If preprocessor already removed a greeting prefix and the remaining text
    # has no campus keywords, treat as social/greeting
    if has_greeting_prefix and not has_campus_keyword:
        return IntentResult(IntentType.SOCIAL, 0.80, "greeting_residual", True)

    # --- Out of scope ---
    for pattern in OUT_OF_SCOPE_PATTERNS:
        if pattern.search(lowered):
            # But if it also mentions MMU, treat as MMU (mixed query rule)
            if has_campus_keyword:
                return IntentResult(IntentType.MMU, 0.6, "mixed_oos_and_mmu", has_greeting_prefix)
            return IntentResult(IntentType.OUT_OF_SCOPE, 0.7, "out_of_scope_pattern")

    # --- Clarification ---
    for pattern in CLARIFICATION_PATTERNS:
        if pattern.search(lowered):
            return IntentResult(IntentType.CLARIFICATION, 0.8, "clarification_pattern", has_greeting_prefix)

    # --- Campus / MMU query ---
    if has_campus_keyword:
        return IntentResult(IntentType.MMU, 0.8, "campus_keyword", has_greeting_prefix)

    # --- Ambiguous (short queries without clear intent) ---
    # Fix 3: Before returning AMBIGUOUS, check if the short query matches a known MMU
    # abbreviation (e.g. "AIMS", "LMS", "ODEL"). If so, treat as MMU query so the
    # chatbot looks it up rather than asking for clarification.
    if len(lowered.split()) <= 3:
        from utils.preprocessor import _STATIC_ABBREVIATIONS
        short_clean = re.sub(r"[^\w\s]", "", lowered).strip()
        if short_clean in _STATIC_ABBREVIATIONS or short_clean.replace(" ", "") in _STATIC_ABBREVIATIONS:
            return IntentResult(IntentType.MMU, 0.7, "known_abbreviation_short_query", has_greeting_prefix)
        return IntentResult(IntentType.AMBIGUOUS, 0.4, "short_unclear", has_greeting_prefix)

    # Default: treat as MMU query (spec says default to campus when uncertain)
    return IntentResult(IntentType.MMU, 0.4, "default_mmu", has_greeting_prefix)


def contains_sensitive_data(text: str) -> bool:
    """Check if text contains sensitive/personal data patterns."""
    lowered = (text or "").lower()
    return any(pattern.search(lowered) for pattern in SENSITIVE_PATTERNS)
