"""MMU Chatbot – Chat endpoint (Spec §3 full pipeline).

Pipeline:
  1. Input reception
  2. Pre-processing (normalise, spell-correct, expand abbreviations)
  3. Intent classification (with mixed-query awareness)
  4. Safety check (toxicity, prompt injection, PII, self-harm)  ← utils/safety.py
  5. Structured data check (fees, contacts, deadlines from DB)
  6. Query reformulation (add MMU context, conversation history)
  7. RAG retrieval (FAISS + MMR + dedup + source limit)
  8. Context assembly (rank, deduplicate, attach metadata)
  9. Response generation (LLM with context)
  10. Verification, confidence scoring, post-processing (disclaimers, escalation)
"""

from __future__ import annotations

import logging
import time
import threading
from collections import defaultdict

from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel
from sqlalchemy.orm import Session

from core.config import settings
from databases.session import get_db
from models.chat_message import ChatMessage
from models.conversation import Conversation
from utils.intent import classify_intent, contains_sensitive_data, IntentType
from utils.llm import generate_response, generate_response_with_context
from utils.preprocessor import preprocess
from utils.rag import assemble_context_for_prompt, retrieve_context
from utils.db_logger import log_to_db, log_error_to_db
from utils.safety import safety_check as _safety_check_fn

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/api/v1", tags=["chat"])

# ---------------------------------------------------------------------------
# Rate limiting (§16 — 30 queries / 5 min per session)
# Thread-safe in-process implementation using a Lock (ARCH-01).
# This is correct under multi-threading (e.g. uvicorn with multiple threads).
# For multi-process deployments (gunicorn workers) migrate to Redis.
# ---------------------------------------------------------------------------

_rate_limiter: dict[str, list[float]] = defaultdict(list)
_rate_limiter_lock = threading.Lock()
RATE_LIMIT_MAX = getattr(settings, "RATE_LIMIT_MAX", 30)
RATE_LIMIT_WINDOW = getattr(settings, "RATE_LIMIT_WINDOW", 300)


def _check_rate_limit(session_key: str) -> bool:
    """Returns True if the request is allowed, False if rate-limited.

    Thread-safe: all reads and writes to _rate_limiter are guarded by
    _rate_limiter_lock so concurrent requests from different threads
    cannot corrupt the timestamp list.
    """
    now = time.time()
    with _rate_limiter_lock:
        timestamps = _rate_limiter[session_key]
        # Prune old entries outside the window
        _rate_limiter[session_key] = [t for t in timestamps if now - t < RATE_LIMIT_WINDOW]
        if len(_rate_limiter[session_key]) >= RATE_LIMIT_MAX:
            return False
        _rate_limiter[session_key].append(now)
        return True


# ---------------------------------------------------------------------------
# Safety classifier (§13) — delegated to utils/safety.py (CODE-02)
# ---------------------------------------------------------------------------

def _safety_check(text: str) -> tuple[bool, str, str]:
    """Thin wrapper around the centralised safety checker.

    Returns: (is_safe, response_if_unsafe, category)
    """
    return _safety_check_fn(text)


# ---------------------------------------------------------------------------
# Request / Response models
# ---------------------------------------------------------------------------

class ChatRequest(BaseModel):
    prompt: str
    history: list[str] | None = None
    conversation_id: int | None = None
    session_id: int | None = None
    interface_type: str | None = None


class ChatResponse(BaseModel):
    response: str
    response_type: str
    intent: str
    context_used: bool
    confidence: float | None = None
    confidence_level: str | None = None
    conversation_id: int | None = None
    message_id: int | None = None


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _save_chat_message(
    db: Session, conversation: Conversation, user_msg: str,
    bot_response: str, intent_class: str, response_type: str,
    context_retrieved: bool, retrieval_doc_count: int = 0,
    confidence_score: float = 0.0, response_time_ms: float = 0.0,
    model_used: str | None = None,
) -> ChatMessage | None:
    """Persist a chat message and return it."""
    chat_message = ChatMessage(
        conversation_id=conversation.conversation_id,
        session_id=conversation.session_id,
        sender_type="bot",
        user_message=user_msg,
        bot_response=bot_response,
        intent_classification=intent_class,
        response_type=response_type,
        context_retrieved=context_retrieved,
        retrieval_doc_count=retrieval_doc_count,
        confidence_score=confidence_score,
        response_time_ms=response_time_ms,
        model_used=model_used,
    )
    db.add(chat_message)
    db.commit()
    db.refresh(chat_message)
    return chat_message


def _make_response(
    response_text: str, response_type: str, intent: str,
    context_used: bool, conversation, msg, ephemeral_mode: bool,
    confidence: float | None = None, confidence_level: str | None = None,
) -> ChatResponse:
    return ChatResponse(
        response=response_text,
        response_type=response_type,
        intent=intent,
        context_used=context_used,
        confidence=round(confidence, 3) if confidence is not None else None,
        confidence_level=confidence_level,
        conversation_id=None if ephemeral_mode or conversation is None else conversation.conversation_id,
        message_id=msg.message_id if msg else None,
    )


# Escalation/fallback counter per session
_fallback_counter: dict[str, int] = defaultdict(int)


# ---------------------------------------------------------------------------
# Main chat endpoint (§3 full pipeline)
# ---------------------------------------------------------------------------

@router.post("/chat", response_model=ChatResponse)
def chat(req: ChatRequest, db: Session = Depends(get_db)) -> ChatResponse:
    t0 = time.time()
    try:
        message = (req.prompt or "").strip()
        if not message:
            raise HTTPException(status_code=400, detail="Prompt cannot be empty")
        if len(message) > settings.MAX_MESSAGE_LENGTH:
            raise HTTPException(status_code=400, detail="Prompt is too long")

        # --- Rate limiting (§16) ---
        session_key = str(req.session_id or "anon")
        if not _check_rate_limit(session_key):
            raise HTTPException(
                status_code=429,
                detail="Rate limit exceeded. Please wait a few minutes before sending more messages."
            )

        # ──────────────────────────────────────────────────────────
        # STEP 1–2: Pre-processing (§4–§7)
        # ──────────────────────────────────────────────────────────
        pp = preprocess(message, req.history)

        # Use corrected text for subsequent steps
        cleaned_message = pp.query_text if pp.query_text else pp.normalised

        # ──────────────────────────────────────────────────────────
        # STEP 3: Intent classification (§5)
        # ──────────────────────────────────────────────────────────
        intent_result = classify_intent(cleaned_message, has_greeting_prefix=pp.greeting_detected)

        # ──────────────────────────────────────────────────────────
        # Session / conversation management
        # ──────────────────────────────────────────────────────────
        conversation = None
        ephemeral_mode = False
        if req.conversation_id:
            conversation = db.get(Conversation, req.conversation_id)
        if conversation is None:
            if not req.session_id:
                ephemeral_mode = True
            else:
                conversation = Conversation(session_id=req.session_id)
                db.add(conversation)
                db.commit()
                db.refresh(conversation)

        # ──────────────────────────────────────────────────────────
        # STEP 4: Safety check (§13)
        # ──────────────────────────────────────────────────────────
        is_safe, safety_response, safety_category = _safety_check(message)
        if not is_safe:
            msg = None
            if not ephemeral_mode and conversation:
                msg = _save_chat_message(
                    db, conversation, message, safety_response,
                    safety_category, "escalation", False,
                    response_time_ms=(time.time() - t0) * 1000
                )
            return _make_response(
                safety_response, "escalation", intent_result.intent.value,
                False, conversation, msg, ephemeral_mode
            )

        # --- Sensitive data request ---
        if intent_result.intent == IntentType.SENSITIVE_OR_PERSONAL_DATA_REQUEST:
            response_text = (
                "Personal student data cannot be accessed or shared for privacy protection. "
                "Please contact the Registrar's office directly for assistance."
            )
            msg = None
            if not ephemeral_mode and conversation:
                msg = _save_chat_message(
                    db, conversation, message, response_text,
                    "sensitive_data", "refusal", False,
                    response_time_ms=(time.time() - t0) * 1000
                )
            return _make_response(
                response_text, "refusal", intent_result.intent.value,
                False, conversation, msg, ephemeral_mode
            )

        # --- Out of scope ---
        if intent_result.intent == IntentType.OUT_OF_SCOPE:
            response_text = (
                "I'm specifically designed to help with Mountains of the Moon University information. "
                "For other topics, I'd recommend appropriate external resources. "
                "Is there anything about MMU I can help you with?"
            )
            msg = None
            if not ephemeral_mode and conversation:
                msg = _save_chat_message(
                    db, conversation, message, response_text,
                    "out_of_scope", "refusal", False,
                    response_time_ms=(time.time() - t0) * 1000
                )
            return _make_response(
                response_text, "refusal", intent_result.intent.value,
                False, conversation, msg, ephemeral_mode
            )

        # --- Ambiguous → ask one clarification question ---
        if intent_result.intent == IntentType.AMBIGUOUS:
            response_text = (
                "I'd love to help! Could you please tell me a bit more about what you're looking for? "
                "For example, are you asking about MMU programs, admissions, fees, campus facilities, or something else?"
            )
            msg = None
            if not ephemeral_mode and conversation:
                msg = _save_chat_message(
                    db, conversation, message, response_text,
                    "ambiguous", "clarification", False,
                    response_time_ms=(time.time() - t0) * 1000
                )
            return _make_response(
                response_text, "clarification", intent_result.intent.value,
                False, conversation, msg, ephemeral_mode
            )

        # --- Social / Pure greeting (no query component) ---
        if intent_result.intent == IntentType.SOCIAL:
            # Respond naturally without RAG
            greeting_input = pp.greeting_text or cleaned_message
            response_text = generate_response(greeting_input)
            msg = None
            if not ephemeral_mode and conversation:
                msg = _save_chat_message(
                    db, conversation, message, response_text,
                    "social", "greeting", False,
                    response_time_ms=(time.time() - t0) * 1000,
                    model_used=getattr(settings, 'DEFAULT_MODEL', 'llama3')
                )
            return _make_response(
                response_text, "greeting", intent_result.intent.value,
                False, conversation, msg, ephemeral_mode
            )

        # ──────────────────────────────────────────────────────────
        # STEPS 5–8: Retrieval (structured data + FAISS + assembly)
        # ──────────────────────────────────────────────────────────

        # Fix 1: If no history was sent in the request, load it from the DB.
        # This makes the AI remember the conversation across page refreshes.
        effective_history = req.history
        if not effective_history and conversation and conversation.conversation_id:
            try:
                past_msgs = (
                    db.query(ChatMessage)
                    .filter(ChatMessage.conversation_id == conversation.conversation_id)
                    .order_by(ChatMessage.created_at.asc())
                    .limit(20)
                    .all()
                )
                if past_msgs:
                    effective_history = []
                    for m in past_msgs:
                        if m.user_message:
                            effective_history.append(m.user_message)
                        if m.bot_response:
                            effective_history.append(m.bot_response)
            except Exception as _e:
                logger.warning("Failed to load DB history: %s", _e)

        # Retrieval: use reformulated MMU-aware query when available (better semantic match).
        # Plain greetings / off-topic messages keep the cleaned message only.
        if (
            pp.reformulated
            and len(pp.reformulated.strip()) >= 12
            and (pp.has_mmu_context or pp.query_text)
            and not (pp.greeting_detected and not pp.query_text)
        ):
            retrieval_query = pp.reformulated
        else:
            retrieval_query = cleaned_message

        # Log chat request
        log_to_db("info", "chat", f"Chat request: intent={intent_result.intent.value}, len={len(message)}",
                  metadata={"intent": intent_result.intent.value, "session": session_key, "has_greeting": pp.greeting_detected})

        # Fix 2: pass is_temporal flag from preprocessor so RAG can apply date penalty
        context_items = retrieve_context(
            cleaned_message,
            history=effective_history,
            is_temporal=pp.is_temporal_query,
            retrieval_query=retrieval_query,
        )
        context_used = bool(context_items)

        # ──────────────────────────────────────────────────────────
        # STEPS 9–10: Generation + Verification + Post-processing
        # ──────────────────────────────────────────────────────────

        if context_items:
            from utils.list_retrieval import is_list_query

            if is_list_query(cleaned_message):
                max_ctx = 9000
            elif "," in cleaned_message or " and " in cleaned_message.lower():
                max_ctx = 5000
            else:
                max_ctx = 3500
            context_text = assemble_context_for_prompt(
                context_items, cleaned_message, max_chars=max_ctx,
            )

            # Build greeting prefix if user greeted + asked a question
            greeting_prefix = ""
            if intent_result.has_greeting or pp.greeting_detected:
                greeting_prefix = "Hello! "

            # Generate with verification — pass retrieval scores for confidence
            retrieval_scores = [float(item.get("score", 0.5)) for item in context_items]
            response_text, confidence, level = generate_response_with_context(
                cleaned_message, context_text, effective_history,
                retrieval_scores=retrieval_scores,
            )


            # Prepend greeting if needed
            if greeting_prefix and not response_text.lower().startswith(("hello", "hi ", "hey", "good ")):
                response_text = greeting_prefix + response_text

            # Reset fallback counter on successful RAG answer
            _fallback_counter[session_key] = 0

        else:
            # No context found — try LLM first, then fall back to static message
            _fallback_counter[session_key] = _fallback_counter.get(session_key, 0) + 1
            confidence = 0.2
            level = "low"

            if _fallback_counter[session_key] >= 3:
                # Repeated fallback → escalate (§13)
                response_text = (
                    "I've been unable to find specific information for your recent questions. "
                    "I'd recommend reaching out directly to MMU for more personalized assistance:\n\n"
                    "📧 **Email**: info@mmu.ac.ug\n"
                    "📞 **Phone**: +256-483-660-691\n"
                    "🌐 **Website**: https://mmu.ac.ug"
                )
                _fallback_counter[session_key] = 0
            else:
                # Try LLM for a natural answer (it has the MMU system prompt)
                try:
                    llm_response = generate_response(cleaned_message)
                    if llm_response and "development mode" not in llm_response.lower():
                        greeting_prefix = "Hello! " if (intent_result.has_greeting or pp.greeting_detected) else ""
                        if greeting_prefix and not llm_response.lower().startswith(("hello", "hi ", "hey", "good ")):
                            response_text = greeting_prefix + llm_response
                        else:
                            response_text = llm_response
                        confidence = 0.35
                        level = "low"
                    else:
                        raise ValueError("LLM gave empty or dev-mode response")
                except Exception:
                    greeting_prefix = "Hello! " if (intent_result.has_greeting or pp.greeting_detected) else ""
                    response_text = (
                        f"{greeting_prefix}I'm sorry, I don't currently have that specific information about "
                        "Mountains of the Moon University. You can try rephrasing your question, "
                        "or I can forward it to the university administration for assistance."
                    )

        # --- Post-processing: check response for sensitive data ---
        if not response_text.strip():
            response_text = "I apologize, but I couldn't generate a response. Please try again."
            level = "low"
            confidence = 0.0

        if contains_sensitive_data(response_text):
            response_text = (
                "Personal student data cannot be accessed or shared for privacy protection. "
                "Please contact the Registrar's office directly."
            )
            msg = None
            if not ephemeral_mode and conversation:
                elapsed_ms = (time.time() - t0) * 1000
                msg = _save_chat_message(
                    db, conversation, message, response_text,
                    "sensitive_data", "refusal", context_used, len(context_items) if context_items else 0,
                    confidence_score=confidence or 0.0, response_time_ms=elapsed_ms,
                    model_used=getattr(settings, 'DEFAULT_MODEL', 'llama3')
                )
            return _make_response(
                response_text, "refusal", intent_result.intent.value,
                context_used, conversation, msg, ephemeral_mode,
                confidence, level
            )

        # --- Save and return ---
        response_type = "rag_based" if context_used else "fallback"
        elapsed_ms = (time.time() - t0) * 1000
        msg = None
        if not ephemeral_mode and conversation:
            msg = _save_chat_message(
                db, conversation, message, response_text,
                intent_result.intent.value, response_type, context_used,
                len(context_items) if context_items else 0,
                confidence_score=confidence if confidence is not None else 0.0,
                response_time_ms=elapsed_ms,
                model_used=getattr(settings, 'DEFAULT_MODEL', 'llama3')
            )

        return _make_response(
            response_text, response_type, intent_result.intent.value,
            context_used, conversation, msg, ephemeral_mode,
            confidence, level
        )

    except HTTPException:
        raise
    except Exception:
        # SEC-05: log the real error server-side but never expose internals to clients
        logger.exception("Chat endpoint failed for prompt: %s", (req.prompt or "")[:100])
        raise HTTPException(
            status_code=500,
            detail="An internal error occurred. Please try again later.",
        )
