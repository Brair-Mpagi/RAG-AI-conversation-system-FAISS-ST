"""Centralised safety patterns and checker (SEC audit CODE-02).

All regex patterns and the safety_check() function are defined here once.
Both the chat pipeline (chat.py) and the MMU middleware (mmu_rules.py) import
from this module so patterns stay in sync.
"""

from __future__ import annotations

import re

# ---------------------------------------------------------------------------
# Compiled regex safety patterns
# ---------------------------------------------------------------------------

SAFETY_KEYWORDS = re.compile(
    r"\b(kill\s+(myself|me)|suicide|self[- ]harm|end(ing)?\s+my\s+life)\b", re.I
)

TOXICITY_KEYWORDS = re.compile(
    r"\b(fuck|shit|bitch|bastard|damn|ass|dick|crap|stupid\s+bot|idiot)\b", re.I
)

PROMPT_INJECTION = re.compile(
    r"(ignore\s+(previous|above|all)\s+(instructions|rules|prompts)|"
    r"you\s+are\s+now\s+a|act\s+as\s+if|pretend\s+you|"
    r"system\s*:\s*|override|jailbreak|dan\s+mode)",
    re.I,
)

COMPLAINT_PATTERNS = re.compile(
    r"\b(complaint|i\s+want\s+to\s+complain|speak\s+to\s+(a\s+)?human|"
    r"talk\s+to\s+someone|escalate|real\s+person|human\s+agent)\b",
    re.I,
)

PERSONAL_DATA_PATTERNS = [
    # Match "<entity_type> <data_type>" patterns (e.g. "my student id", "staff email")
    # Must have possessive pronoun OR explicit personal framing to avoid false positives
    re.compile(
        r"\b(my|his|her|their)\s+(student|staff|applicant)\s*(id|number|result|grade|status)\b",
        re.I,
    ),
    re.compile(
        r"\b(student|staff|user|person|applicant)\s*(id|number)\b",
        re.I,
    ),
    # Match explicit personal data-request phrasings
    re.compile(
        r"(show me.*phone\s+number|show me.*result|show me.*grade"
        r"|what are .* results|email address of a specific|my admission status)",
        re.I,
    ),
    # Match standalone personal result/grade requests
    re.compile(
        r"\b(my student results?|my student grades?|my marks?|my scores?|check my result)\b",
        re.I,
    ),
]


# ---------------------------------------------------------------------------
# Public helpers
# ---------------------------------------------------------------------------

def safety_check(text: str) -> tuple[bool, str, str]:
    """Pre-generation safety classifier.

    Returns:
        (is_safe, response_if_unsafe, category)
    """
    if SAFETY_KEYWORDS.search(text):
        return False, (
            "I'm really sorry you're feeling this way. Please reach out to someone who can help:\n\n"
            "🆘 **MMU Counselling Services**: Visit the Dean of Students' office\n"
            "📞 **Uganda Helpline**: 0800-100-100\n\n"
            "You're not alone, and there are people who care about you."
        ), "self_harm_escalation"

    if PROMPT_INJECTION.search(text):
        return False, (
            "I'm the MMU Campus Assistant and I'm here to help with university-related questions. "
            "How can I assist you with information about Mountains of the Moon University?"
        ), "prompt_injection"

    if COMPLAINT_PATTERNS.search(text):
        return False, (
            "I understand you'd like to speak with someone directly. Here's how to reach MMU staff:\n\n"
            "📧 **General Inquiries**: info@mmu.ac.ug\n"
            "📞 **Main Switchboard**: +256-483-660-691\n"
            "🏢 **Dean of Students Office**: Ground Floor, Main Administration Block\n\n"
            "You can also submit a formal inquiry through the university's website."
        ), "human_escalation"

    if TOXICITY_KEYWORDS.search(text):
        return False, (
            "I understand your frustration, and I'm here to help. "
            "Could you please rephrase your question? I'd be happy to assist with any "
            "MMU-related information."
        ), "toxicity"

    return True, "", "safe"


def is_personal_data_request(text: str) -> bool:
    """Return True if the text appears to request personal/confidential data."""
    return any(pat.search(text) for pat in PERSONAL_DATA_PATTERNS)
