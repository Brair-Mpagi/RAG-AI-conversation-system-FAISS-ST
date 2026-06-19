from __future__ import annotations

import logging
import re
import json
from fastapi import Request
from fastapi.responses import JSONResponse
from starlette.middleware.base import BaseHTTPMiddleware

# Import all safety patterns from the single source of truth (CODE-02)
from utils.safety import PROMPT_INJECTION, PERSONAL_DATA_PATTERNS

logger = logging.getLogger(__name__)


class MMURuleMiddleware(BaseHTTPMiddleware):
    """Lightweight pre-filter middleware.

    Catches obvious prompt-injection and PII requests before the request body
    is consumed by the router, reducing unnecessary processing.
    Most of the safety logic lives in the chat pipeline (chat.py) which uses
    the same patterns from utils/safety.py.
    """

    async def dispatch(self, request: Request, call_next):
        if request.method == "POST" and (
            "/chat" in request.url.path or request.url.path.startswith("/api")
        ):
            try:
                body = await request.body()
                data = json.loads(body) if body else {}
                question = data.get("question", data.get("prompt", "")).lower()
            except Exception:
                return await call_next(request)

            # 1. Prompt injection — block before reaching LLM
            if PROMPT_INJECTION.search(question):
                return JSONResponse(
                    {
                        "response": (
                            "I'm the MMU Campus Assistant and I'm here to help with "
                            "university-related questions."
                        ),
                        "response_type": "escalation",
                        "intent": "prompt_injection",
                        "context_used": False,
                    },
                    status_code=200,
                )

            # 2. Personal / confidential data request
            if any(pat.search(question) for pat in PERSONAL_DATA_PATTERNS):
                return JSONResponse(
                    {
                        "response": (
                            "Personal student data cannot be accessed or shared for privacy "
                            "protection. Please contact the Registrar's office directly."
                        ),
                        "response_type": "refusal",
                        "intent": "SENSITIVE_OR_PERSONAL_DATA_REQUEST",
                        "context_used": False,
                    },
                    status_code=200,
                )

        return await call_next(request)
