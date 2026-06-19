"""MMU Chatbot – LLM integration with verification (Spec §10–§12, §18).

Implements:
  - Enhanced MMU advisor persona system prompt
  - Response generation with context + history
  - Verification / fact-check second pass (spec §11)
  - Confidence scoring (4-factor weighted, spec §12)
  - Auto-disclaimers for fees/admissions (spec §18)

Fixes applied:
  - FIX-01: Removed unused `concurrent.futures` import
  - FIX-02: Replaced inline `__import__("numpy")` with top-level import + pure-Python fallback
  - FIX-03: Verifier now uses a neutral system prompt instead of the MMU advisor persona
  - FIX-04: Removed duplicate `from utils.list_retrieval import is_list_query` inside function
  - FIX-05: Cloud path in generate_response_with_context no longer double-injects system prompt
  - FIX-06: Added exponential-backoff retry wrapper for cloud API rate-limit (429) errors
  - FIX-07: API keys are masked in all log output
  - FIX-08: _append_disclaimers now uses plain text (no markdown asterisks) so _strip_formatting
            does not remove disclaimer emphasis
  - FIX-09: Deduplicated and restructured system prompt rules; added missing rules for language,
            graceful "I don't know", no-speculation, contact-info caution, response length,
            and inappropriate-input handling
  - FIX-10: Added upstream prompt length guard in public API functions
"""

import logging
import math
import os
import re
import json as _json
import time
import traceback
import requests
from dataclasses import dataclass
from datetime import date as _date
from typing import Optional

try:
    import numpy as _np
    _HAS_NUMPY = True
except ImportError:
    _np = None          # type: ignore[assignment]
    _HAS_NUMPY = False

try:
    import ollama        # type: ignore
    OLLAMA_AVAILABLE = True
except Exception:
    ollama = None        # type: ignore[assignment]
    OLLAMA_AVAILABLE = False

from core.config import settings
from databases.session import SessionLocal
from utils.db_logger import log_to_db, log_error_to_db

logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# §10 – System Prompts
# ---------------------------------------------------------------------------

def _build_system_prompt() -> str:
    """Build the MMU advisor system prompt with today's date injected (FIX-09)."""
    today = _date.today().strftime("%B %d, %Y")
    return (
        f"You are a helpful campus assistant for Mountains of the Moon University (MMU) "
        f"in Fort Portal, Uganda. You help students, staff, and applicants. "
        f"Today's date is {today}.\n\n"

        # ── SCOPE ──────────────────────────────────────────────────────────
        "SCOPE:\n"
        "S1. Only answer questions related to MMU. Politely decline all unrelated topics.\n"
        "S2. If the question is conversational (greetings, identity, casual chat), respond "
        "naturally without forcing retrieved university data.\n"
        "S3. Never speculate about future MMU policies, new programmes, fee changes, or "
        "decisions not confirmed in the provided context.\n\n"

        # ── FACTUAL ACCURACY ───────────────────────────────────────────────
        "FACTUAL ACCURACY:\n"
        "A1. Never invent or fabricate information. Only use facts present in the provided context.\n"
        "A2. Only use context that is directly relevant to the user's question; ignore unrelated chunks.\n"
        "A3. When context is provided and relevant, you MUST use it to answer the question.\n"
        "A4. When retrieved chunks conflict, use the most recent and most relevant one.\n"
        "A5. Be cautious with phone numbers, email addresses, and office names. Do not add long "
        "warning disclaimers or boilerplate verify-via-official-channels advice. Keep answers "
        "clean, direct, and factual.\n"
        "A6. If you do not have the answer, say clearly: "
        "\"I don't have that specific information. Please contact the relevant MMU office directly.\"\n\n"

        # ── TONE & STYLE ───────────────────────────────────────────────────
        "TONE & STYLE:\n"
        "T1. Always respond in English unless the user explicitly writes in another language "
        "and requests a response in that language.\n"
        "T2. Be concise, friendly, and direct. Keep responses under 150 words unless the user "
        "asks for a list, detailed breakdown, or step-by-step explanation.\n"
        "T3. Answer precisely what is asked. Do not add unrequested details.\n"
        "T4. If a user sends offensive, abusive, or inappropriate input, respond calmly and "
        "professionally: \"I'm here to help with MMU-related questions. Please keep our "
        "conversation respectful.\"\n\n"

        # ── FORMATTING ─────────────────────────────────────────────────────
        "FORMATTING:\n"
        "F1. Never use markdown formatting. No asterisks (*), double asterisks (**), "
        "hash symbols (#), or underscores for emphasis. Write in plain, natural text only.\n"
        "F2. For list requests (staff, faculties, departments, programmes), use a plain "
        "hyphen-space bullet per line (- Item). Group by department with a short heading. "
        "Include every name and role shown in the relevant context.\n"
        "F3. For multi-part questions, answer every part that is supported by the context.\n\n"

        # ── TEMPORAL ──────────────────────────────────────────────────────
        "TEMPORAL:\n"
        f"V1. Today is {today}. For upcoming events (intake, semester start, deadlines), "
        "only reference future dates. If retrieved data only contains past dates, say the "
        "specific upcoming date is not yet confirmed and advise checking the official MMU website.\n\n"

        # ── CONVERSATION ──────────────────────────────────────────────────
        "CONVERSATION:\n"
        "C1. You have access to recent conversation history. Use it to answer follow-up "
        "questions and recall what the user asked earlier.\n"
        "C2. Never reference your data source. Do not use phrases like 'Based on the context', "
        "'According to the provided information', 'The context states', or similar. "
        "Answer directly and naturally as if you simply know the answer."
    )


# Neutral verifier system prompt — intentionally minimal (FIX-03)
_VERIFIER_SYSTEM_PROMPT = (
    "You are a strict factual auditor. Evaluate answers against provided context with no bias "
    "toward being helpful or friendly. Your only goal is accuracy."
)

VERIFIER_PROMPT = """CONTEXT (source of truth):
{context}

ANSWER TO VERIFY:
{answer}

EVALUATION CRITERIA:
1. Are ALL facts in the answer present in the context?
2. Are there any claims NOT supported by the context?
3. Are numeric values (fees, dates, counts) accurate per the context?
4. Are there any contradictions with the context?

Rate factual accuracy on a scale of 1-5:
  5 = All facts verified, no unsupported claims
  4 = Minor phrasing differences but factually accurate
  3 = Mostly accurate but contains one unsupported claim
  2 = Multiple unsupported or incorrect claims
  1 = Largely fabricated or contradicts the context

Respond in EXACTLY this format:
SCORE: [1-5]
ISSUES: [list any issues, or "None"]"""


# ---------------------------------------------------------------------------
# Ollama client setup
# ---------------------------------------------------------------------------

def _get_ollama_client():
    if not OLLAMA_AVAILABLE:
        return None
    host = settings.OLLAMA_HOST
    try:
        return ollama.Client(host=host)  # type: ignore[attr-defined]
    except Exception:
        return None


OLLAMA_CLIENT = _get_ollama_client()

if OLLAMA_AVAILABLE:
    try:
        os.environ.setdefault("OLLAMA_HOST", settings.OLLAMA_HOST)
    except Exception:
        pass


# ---------------------------------------------------------------------------
# Model info container
# ---------------------------------------------------------------------------

@dataclass
class ModelInfo:
    """Unified model descriptor for local and cloud models."""
    name: str
    model_type: str = "local_ollama"       # 'local_ollama' or 'cloud_api'
    api_provider: str | None = None        # 'gemini', 'openai', 'anthropic', ...
    api_key: str | None = None
    api_endpoint: str | None = None
    config: dict | None = None             # model_config JSON from DB

    @property
    def is_cloud(self) -> bool:
        return self.model_type == "cloud_api"

    def masked_key(self) -> str:
        """Return a masked version of the API key safe for logging (FIX-07)."""
        if not self.api_key:
            return "(none)"
        key = self.api_key
        if len(key) <= 8:
            return "***"
        return f"{key[:4]}...{key[-4:]}"


# ---------------------------------------------------------------------------
# FIX-06: Cloud retry helper with exponential backoff
# ---------------------------------------------------------------------------

def _with_retry(fn, max_retries: int = 3, base_delay: float = 1.0):
    """Call *fn()* up to *max_retries* times, backing off on 429/5xx errors."""
    last_exc: Exception | None = None
    for attempt in range(max_retries):
        try:
            return fn()
        except requests.exceptions.HTTPError as exc:
            status = exc.response.status_code if exc.response is not None else 0
            if status in (429, 500, 502, 503, 504) and attempt < max_retries - 1:
                delay = base_delay * (2 ** attempt)
                logger.warning(
                    "HTTP %s on attempt %d/%d — retrying in %.1fs",
                    status, attempt + 1, max_retries, delay,
                )
                time.sleep(delay)
                last_exc = exc
                continue
            raise
        except Exception as exc:
            raise
    raise last_exc  # type: ignore[misc]


# ---------------------------------------------------------------------------
# Core generation helpers
# ---------------------------------------------------------------------------

def _call_ollama_chat(
    model: str,
    system_prompt: str,
    user_prompt: str,
    timeout: int = 90,
    json_format: bool = False,
) -> str:
    """Call Ollama via /api/chat with proper message roles and streaming."""
    if len(user_prompt) > settings.MAX_CONTEXT_LENGTH:
        user_prompt = user_prompt[: settings.MAX_CONTEXT_LENGTH]

    if settings.DEV_MODE and not OLLAMA_AVAILABLE:
        return _get_mock_response(user_prompt)

    ollama_url = f"{settings.OLLAMA_HOST}/api/chat"
    payload = {
        "model": model,
        "messages": [
            {"role": "system", "content": system_prompt},
            {"role": "user", "content": user_prompt},
        ],
        "stream": True,
        "options": {
            "temperature": 0.2,
            "top_k": 40,
            "top_p": 0.9,
            "num_predict": 512 if json_format else 300,
            "num_ctx": 4096,
        },
    }
    if json_format:
        payload["format"] = "json"

    try:
        resp = requests.post(
            ollama_url, json=payload, timeout=(10, timeout), stream=True
        )
        resp.raise_for_status()

        chunks: list[str] = []
        for line in resp.iter_lines(decode_unicode=True):
            if not line:
                continue
            try:
                data = _json.loads(line)
            except Exception:
                continue
            token = data.get("message", {}).get("content", "")
            if token:
                chunks.append(token)
            if data.get("done"):
                break

        resp_text = "".join(chunks).strip()
    except requests.exceptions.Timeout:
        msg = f"Ollama generation timed out after {timeout}s with model {model}"
        logger.error(msg)
        log_to_db("error", "llm", msg)
        log_error_to_db("LLM_TIMEOUT", msg)
        raise TimeoutError(f"Model {model} timed out after {timeout} seconds.")
    except requests.exceptions.ConnectionError:
        msg = f"Cannot connect to Ollama at {ollama_url}"
        logger.error(msg)
        if settings.DEV_MODE:
            return _get_mock_response(user_prompt)
        raise RuntimeError(msg)
    except Exception as e:
        msg = f"Ollama generation error with model {model}: {e}"
        logger.error(msg)
        log_to_db("error", "llm", msg, stack_trace=traceback.format_exc())
        log_error_to_db("LLM_ERROR", msg)
        raise

    return resp_text if resp_text else "I apologize, but I couldn't generate a response. Please try again."


def _call_ollama_with_prompt(model: str, full_prompt: str, timeout: int = 90) -> str:
    """Backward-compatible wrapper: calls /api/chat with MMU system prompt."""
    return _call_ollama_chat(model, _build_system_prompt(), full_prompt, timeout)


def _call_ollama(model: str, prompt: str) -> str:
    """Call Ollama with MMU system prompt using chat API."""
    return _call_ollama_chat(model, _build_system_prompt(), prompt)


# ---------------------------------------------------------------------------
# Cloud API helpers
# ---------------------------------------------------------------------------

def _resolve_cloud_endpoint(provider: str, configured_endpoint: str | None, model_name: str) -> str:
    """Resolve and correct cloud endpoints based on provider and model name."""
    provider = provider.lower()
    endpoint = (configured_endpoint or "").strip()

    if provider == "openai":
        if not endpoint or "anthropic.com" in endpoint or "googleapis.com" in endpoint:
            if "deepseek" in model_name.lower():
                return "https://api.deepseek.com/v1"
            return "https://api.openai.com/v1"
        return endpoint

    elif provider == "anthropic":
        if not endpoint or "openai.com" in endpoint or "googleapis.com" in endpoint or "deepseek.com" in endpoint:
            return "https://api.anthropic.com/v1"
        return endpoint

    elif provider == "gemini":
        if not endpoint or "openai.com" in endpoint or "anthropic.com" in endpoint or "deepseek.com" in endpoint:
            return "https://generativelanguage.googleapis.com/v1beta"
        return endpoint

    return endpoint


def _call_gemini_api(
    model_info: ModelInfo,
    full_prompt: str,
    timeout: int = 60,
    system_prompt: str | None = None,
    json_format: bool = False,
) -> str:
    """Call Google Gemini API via REST with retry (FIX-06, FIX-07)."""
    resolved_endpoint = _resolve_cloud_endpoint("gemini", model_info.api_endpoint, model_info.name)
    endpoint = resolved_endpoint.rstrip("/")
    model_name = model_info.name
    url = f"{endpoint}/models/{model_name}:generateContent?key={model_info.api_key}"

    config = model_info.config or {}
    generation_config = {
        "temperature": config.get("temperature", 0.3),
        "maxOutputTokens": config.get("max_output_tokens", 512),
        "topP": config.get("top_p", 0.9),
        "topK": config.get("top_k", 40),
    }
    if json_format:
        generation_config["responseMimeType"] = "application/json"

    sys_prompt = system_prompt if system_prompt is not None else _build_system_prompt()

    payload = {
        "contents": [{"parts": [{"text": full_prompt}]}],
        "generationConfig": generation_config,
        "systemInstruction": {"parts": [{"text": sys_prompt}]},
    }

    def _do_request():
        resp = requests.post(url, json=payload, timeout=timeout)
        resp.raise_for_status()
        data = resp.json()
        candidates = data.get("candidates", [])
        if candidates:
            parts = candidates[0].get("content", {}).get("parts", [])
            if parts:
                return parts[0].get("text", "").strip()
        logger.warning("Gemini returned empty response: %s", data)
        return "I apologize, but I couldn't generate a response. Please try again."

    try:
        return _with_retry(_do_request)
    except requests.exceptions.Timeout:
        msg = f"Gemini API timed out after {timeout}s with model {model_name}"
        logger.error(msg)
        log_to_db("error", "llm", msg)
        log_error_to_db("CLOUD_API_TIMEOUT", msg)
        raise TimeoutError(msg)
    except requests.exceptions.HTTPError as e:
        status = e.response.status_code if e.response is not None else "unknown"
        body = ""
        try:
            body = e.response.text[:500] if e.response is not None else ""
        except Exception:
            pass
        msg = f"Gemini API HTTP {status} for model {model_name} [key={model_info.masked_key()}]: {body}"
        logger.error(msg)
        log_to_db("error", "llm", msg)
        log_error_to_db("CLOUD_API_HTTP_ERROR", msg)
        raise RuntimeError(msg)
    except Exception as e:
        msg = f"Gemini API error with model {model_name} [key={model_info.masked_key()}]: {e}"
        logger.error(msg)
        log_to_db("error", "llm", msg, stack_trace=traceback.format_exc())
        log_error_to_db("CLOUD_API_ERROR", msg)
        raise


def _call_openai_api(
    model_info: ModelInfo,
    full_prompt: str,
    timeout: int = 60,
    system_prompt: str | None = None,
    json_format: bool = False,
) -> str:
    """Call OpenAI-compatible API via REST with retry (FIX-06, FIX-07)."""
    resolved_endpoint = _resolve_cloud_endpoint("openai", model_info.api_endpoint, model_info.name)
    endpoint = resolved_endpoint.rstrip("/")
    url = f"{endpoint}/chat/completions"

    sys_prompt = system_prompt if system_prompt is not None else _build_system_prompt()

    config = model_info.config or {}
    payload = {
        "model": model_info.name,
        "messages": [
            {"role": "system", "content": sys_prompt},
            {"role": "user", "content": full_prompt},
        ],
        "temperature": config.get("temperature", 0.3),
        "max_tokens": config.get("max_tokens", 512),
    }
    if json_format:
        payload["response_format"] = {"type": "json_object"}

    headers = {
        "Authorization": f"Bearer {model_info.api_key}",
        "Content-Type": "application/json",
    }

    def _do_request():
        resp = requests.post(url, json=payload, headers=headers, timeout=timeout)
        resp.raise_for_status()
        data = resp.json()
        choices = data.get("choices", [])
        if choices:
            return choices[0].get("message", {}).get("content", "").strip()
        return "I apologize, but I couldn't generate a response. Please try again."

    try:
        return _with_retry(_do_request)
    except Exception as e:
        msg = f"OpenAI API error with model {model_info.name} [key={model_info.masked_key()}]: {e}"
        logger.error(msg)
        log_to_db("error", "llm", msg, stack_trace=traceback.format_exc())
        log_error_to_db("CLOUD_API_ERROR", msg)
        raise


def _call_anthropic_api(
    model_info: ModelInfo,
    full_prompt: str,
    timeout: int = 60,
    system_prompt: str | None = None,
    json_format: bool = False,
) -> str:
    """Call Anthropic API via REST with retry (FIX-06, FIX-07)."""
    resolved_endpoint = _resolve_cloud_endpoint("anthropic", model_info.api_endpoint, model_info.name)
    endpoint = resolved_endpoint.rstrip("/")
    url = f"{endpoint}/messages"

    sys_prompt = system_prompt if system_prompt is not None else _build_system_prompt()

    config = model_info.config or {}
    payload = {
        "model": model_info.name,
        "max_tokens": config.get("max_tokens", 512),
        "system": sys_prompt,
        "messages": [{"role": "user", "content": full_prompt}],
    }

    headers = {
        "x-api-key": model_info.api_key,
        "anthropic-version": "2023-06-01",
        "Content-Type": "application/json",
    }

    def _do_request():
        resp = requests.post(url, json=payload, headers=headers, timeout=timeout)
        resp.raise_for_status()
        data = resp.json()
        content_blocks = data.get("content", [])
        if content_blocks:
            return content_blocks[0].get("text", "").strip()
        return "I apologize, but I couldn't generate a response. Please try again."

    try:
        return _with_retry(_do_request)
    except Exception as e:
        msg = f"Anthropic API error with model {model_info.name} [key={model_info.masked_key()}]: {e}"
        logger.error(msg)
        log_to_db("error", "llm", msg, stack_trace=traceback.format_exc())
        log_error_to_db("CLOUD_API_ERROR", msg)
        raise


# Cloud provider dispatch map
_CLOUD_DISPATCH = {
    "gemini": _call_gemini_api,
    "openai": _call_openai_api,
    "anthropic": _call_anthropic_api,
}


def _call_cloud_api(
    model_info: ModelInfo,
    full_prompt: str,
    timeout: int = 60,
    system_prompt: str | None = None,
    json_format: bool = False,
) -> str:
    """Dispatch to the correct cloud provider API."""
    provider = (model_info.api_provider or "").lower()
    handler = _CLOUD_DISPATCH.get(provider)

    if not handler:
        if provider == "custom":
            handler = _call_openai_api
        else:
            raise ValueError(f"Unknown cloud provider: {provider}")

    if not model_info.api_key:
        raise ValueError(f"No API key configured for cloud model {model_info.name}")

    logger.info(
        "Calling cloud API: provider=%s, model=%s, key=%s",
        provider, model_info.name, model_info.masked_key(),
    )
    return handler(model_info, full_prompt, timeout=timeout, system_prompt=system_prompt, json_format=json_format)


# ---------------------------------------------------------------------------
# Model selection helpers
# ---------------------------------------------------------------------------

def _list_installed_models() -> list[str]:
    try:
        resp = requests.get(f"{settings.OLLAMA_HOST}/api/tags", timeout=3)
        if resp.status_code != 200:
            return []
        data = resp.json()
        models = data.get("models", [])
        names: list[str] = []
        for m in models:
            if isinstance(m, dict):
                name = m.get("model") or m.get("name")
                if isinstance(name, str):
                    names.append(name)
        return names
    except Exception:
        return []


def _pick_best_model(installed: list[str], prefer_small: bool = False) -> str | None:
    if not installed:
        return None

    if prefer_small:
        prefs = [
            "tinyllama:1.1b",
            "phi3:latest",
            "llama3.2:latest",
            "gemma:2b",
            settings.OLLAMA_FALLBACK_MODEL,
            settings.OLLAMA_PRIMARY_MODEL,
        ]
    else:
        prefs = [
            settings.OLLAMA_PRIMARY_MODEL,
            settings.OLLAMA_FALLBACK_MODEL,
            "llama3.2:latest",
            "tinyllama:1.1b",
            "phi3:latest",
            "llama3.2:3b-instruct",
            "llama3.1:8b-instruct",
            "llama2",
            "mistral",
            "qwen2.5:3b-instruct",
            "gemma:2b",
        ]

    installed_set = {m.lower() for m in installed}
    for p in prefs:
        if p and p.lower() in installed_set:
            return p
    return installed[0]


def _get_default_model_from_db() -> ModelInfo | None:
    """Read the default active model from the DB, including cloud config."""
    try:
        from sqlalchemy import text
        db = SessionLocal()
        row = db.execute(
            text("""
                SELECT model_name, model_version, model_type,
                       api_provider, api_endpoint, api_key, model_config
                FROM ai_models
                WHERE is_default = 1 AND status = 'active'
                ORDER BY updated_at DESC
                LIMIT 1
            """)
        ).first()
        if not row:
            return None
        name = (row[0] or '').strip()
        ver = (row[1] or '').strip()
        model_type = (row[2] or 'local_ollama').strip()
        api_provider = (row[3] or '').strip() or None
        api_endpoint = (row[4] or '').strip() or None
        api_key = (row[5] or '').strip() or None
        config_raw = row[6]
        config = None
        if config_raw:
            try:
                config = _json.loads(config_raw) if isinstance(config_raw, str) else config_raw
            except Exception:
                pass
        if not name:
            return None
        if ':' not in name and ver:
            name = f"{name}:{ver}"
        return ModelInfo(
            name=name,
            model_type=model_type,
            api_provider=api_provider,
            api_key=api_key,
            api_endpoint=api_endpoint,
            config=config,
        )
    except Exception:
        logger.debug("Failed to read default model from DB", exc_info=True)
        return None
    finally:
        try:
            db.close()  # type: ignore[name-defined]
        except Exception:
            pass


def _resolve_model() -> ModelInfo:
    """Determine the best model to use (DB default → primary config)."""
    db_model = _get_default_model_from_db()
    if db_model:
        if db_model.is_cloud:
            return db_model
        installed = _list_installed_models()
        installed_lower = {m.lower() for m in installed}
        if db_model.name.lower() in installed_lower:
            return db_model
        logger.warning("DB default model %s not installed, using config primary", db_model.name)
    return ModelInfo(name=settings.OLLAMA_PRIMARY_MODEL, model_type="local_ollama")


def _generate_with_fallback(full_prompt: str) -> str:
    """Try primary → fast fallback → smallest installed model.

    Supports both cloud API and local Ollama models.
    """
    model_info = _resolve_model()
    logger.info("Resolved model: %s (type=%s)", model_info.name, model_info.model_type)

    # --- Cloud API path ---
    if model_info.is_cloud:
        try:
            return _call_cloud_api(model_info, full_prompt, timeout=60)
        except Exception as exc:
            logger.warning(
                "Cloud model %s failed: %s — falling back to local Ollama",
                model_info.name, exc,
            )
            log_to_db("warning", "llm",
                      f"Cloud model {model_info.name} failed: {exc}. Falling back to local.")
            model_info = ModelInfo(name=settings.OLLAMA_PRIMARY_MODEL, model_type="local_ollama")

    # --- Local Ollama path ---
    model = model_info.name

    try:
        return _call_ollama_with_prompt(model, full_prompt, timeout=90)
    except Exception as exc:
        logger.warning("Primary model %s failed, trying fallback: %s", model, exc)
        log_to_db("warning", "llm", f"Primary model {model} failed: {exc}. Trying fallback.")

    fb_model = settings.OLLAMA_FALLBACK_MODEL
    if fb_model and fb_model.lower() != model.lower():
        try:
            return _call_ollama_with_prompt(fb_model, full_prompt, timeout=60)
        except Exception as exc:
            logger.warning("Fallback model %s failed: %s", fb_model, exc)
            log_to_db("warning", "llm", f"Fallback model {fb_model} failed: {exc}")

    try:
        installed = _list_installed_models()
        tried = {model.lower(), (fb_model or "").lower()}
        remaining = [m for m in installed if m.lower() not in tried]
        chosen = _pick_best_model(remaining, prefer_small=True)
        if chosen:
            logger.info("Last-resort model: %s", chosen)
            return _call_ollama_with_prompt(chosen, full_prompt, timeout=60)
    except Exception as exc:
        logger.exception("Installed model selection failed")
        log_to_db("error", "llm", f"All installed models failed: {exc}", stack_trace=traceback.format_exc())

    log_to_db("error", "llm", "All LLM models exhausted. Returning fallback message.",
              metadata={"primary": model, "fallback": settings.OLLAMA_FALLBACK_MODEL})
    log_error_to_db("LLM_TOTAL_FAILURE",
                    f"All models failed. Primary={model}, Fallback={settings.OLLAMA_FALLBACK_MODEL}")

    if settings.DEV_MODE:
        return _get_mock_response(full_prompt)

    return "I apologize, but the AI assistant is currently unavailable. Please try again later."


# ---------------------------------------------------------------------------
# §11 – Verification / Fact-Check Layer
# ---------------------------------------------------------------------------

def _verify_response(answer: str, context: str) -> tuple[int, str]:
    """Run a second LLM call to verify the answer against context.

    Uses a neutral auditor system prompt (FIX-03) to avoid the verifier
    softening its critique due to the MMU advisor persona.

    Returns (score 1-5, issues_text).
    """
    if not settings.VERIFICATION_ENABLED:
        return 5, "Verification disabled"

    prompt = VERIFIER_PROMPT.format(context=context[:2000], answer=answer[:1000])
    try:
        model_info = _resolve_model()
        if model_info.is_cloud:
            result = _call_cloud_api(
                model_info, prompt, timeout=30,
                system_prompt=_VERIFIER_SYSTEM_PROMPT,
            )
        else:
            result = _call_ollama_chat(
                model_info.name,
                _VERIFIER_SYSTEM_PROMPT,
                prompt,
                timeout=30,
            )

        score_match = re.search(r"SCORE:\s*(\d)", result)
        score = int(score_match.group(1)) if score_match else 3

        issues_match = re.search(r"ISSUES:\s*(.+)", result, re.DOTALL)
        issues = issues_match.group(1).strip() if issues_match else "Parse error"

        return min(max(score, 1), 5), issues
    except Exception as e:
        logger.warning("Verification failed: %s – accepting answer", e)
        return 4, "Verification unavailable"


# ---------------------------------------------------------------------------
# §12 – Confidence Scoring
# ---------------------------------------------------------------------------

def _std(values: list[float]) -> float:
    """Pure-Python standard deviation — used when numpy is unavailable (FIX-02)."""
    n = len(values)
    if n < 2:
        return 0.0
    mean = sum(values) / n
    variance = sum((x - mean) ** 2 for x in values) / n
    return math.sqrt(variance)


def compute_confidence(
    retrieval_scores: list[float],
    context_coverage: float,
    verifier_score: int,
) -> tuple[float, str]:
    """Compute weighted confidence score.

    Weights per spec §12:
      Retrieval score:       0.35
      Context coverage:      0.25
      Cross-chunk agreement: 0.25
      Verifier:              0.15

    Returns (confidence 0-1, level "high"|"medium"|"low").
    """
    avg_retrieval = sum(retrieval_scores) / max(len(retrieval_scores), 1)

    if len(retrieval_scores) > 1:
        # FIX-02: use numpy if available, otherwise pure-Python fallback
        if _HAS_NUMPY:
            std = float(_np.std(retrieval_scores))
        else:
            std = _std(retrieval_scores)
        agreement = max(0.0, 1.0 - std * 2)
    else:
        agreement = 0.5

    verifier_norm = (verifier_score - 1) / 4.0

    confidence = (
        0.35 * avg_retrieval
        + 0.25 * context_coverage
        + 0.25 * agreement
        + 0.15 * verifier_norm
    )
    confidence = max(0.0, min(1.0, confidence))

    if confidence >= settings.CONFIDENCE_HIGH_THRESHOLD:
        level = "high"
    elif confidence >= settings.CONFIDENCE_MEDIUM_THRESHOLD:
        level = "medium"
    else:
        level = "low"

    return confidence, level


# ---------------------------------------------------------------------------
# §18 – Auto-disclaimers
# ---------------------------------------------------------------------------

_FEE_PATTERNS = re.compile(
    r"\b(ugx|shilling|fee|fees|tuition|cost|price|pay|payment|amount)\b", re.I
)


def _append_disclaimers(response: str) -> str:
    """Append a disclaimer only for fee/financial content where accuracy is critical.

    Contact and admissions disclaimers were removed — the LLM already advises
    users to verify directly, so appending a duplicate NOTE was redundant.
    """
    if _FEE_PATTERNS.search(response):
        return (
            response.rstrip()
            + "\n\nNOTE: Fee amounts are estimates based on available information. "
            "Always verify official amounts at the University Bursar's office."
        )
    return response


# ---------------------------------------------------------------------------
# Post-processing: strip markdown formatting
# ---------------------------------------------------------------------------

def _strip_formatting(text: str) -> str:
    """Remove markdown formatting symbols from LLM output."""
    text = re.sub(r'\*\*(.+?)\*\*', r'\1', text)
    text = re.sub(r'\*(.+?)\*', r'\1', text)
    text = re.sub(r'^#{1,6}\s+', '', text, flags=re.MULTILINE)
    text = re.sub(r'__(.+?)__', r'\1', text)
    text = re.sub(r'^\*\s+', '- ', text, flags=re.MULTILINE)
    return text.strip()


# ---------------------------------------------------------------------------
# Input sanitisation helper (FIX-10)
# ---------------------------------------------------------------------------

_MAX_PROMPT_CHARS = 2000  # Hard cap on user-supplied prompt before building LLM payload


def _sanitise_prompt(prompt: str) -> str:
    """Trim and hard-cap the user prompt before it reaches the LLM."""
    prompt = prompt.strip()
    if len(prompt) > _MAX_PROMPT_CHARS:
        logger.warning("User prompt truncated from %d to %d chars", len(prompt), _MAX_PROMPT_CHARS)
        prompt = prompt[:_MAX_PROMPT_CHARS]
    return prompt


# ---------------------------------------------------------------------------
# Public API
# ---------------------------------------------------------------------------

_INSTANT_GREETINGS = {
    "hi", "hello", "hey", "hiya", "howdy", "yo", "sup", "hola",
    "greetings", "greeting", "good morning", "good afternoon",
    "good evening", "good day", "good night",
}
_INSTANT_THANKS = {"thanks", "thank you", "thx", "ty", "cheers"}
_INSTANT_BYES = {"bye", "goodbye", "see you", "take care", "farewell", "later"}


def generate_response(prompt: str) -> str:
    """Generate response for non-RAG queries (greetings, simple interactions).

    Simple greetings are answered instantly without calling Ollama.
    FIX-10: prompt is sanitised before use.
    """
    prompt = _sanitise_prompt(prompt)
    stripped = prompt.lower().rstrip("!?.")

    if stripped in _INSTANT_GREETINGS:
        return ("Hello! Welcome to the MMU Campus Assistant. "
                "How can I help you with Mountains of the Moon University today?")
    if stripped in _INSTANT_THANKS:
        return "You're welcome! Feel free to ask if you have any more questions about MMU."
    if stripped in _INSTANT_BYES:
        return "Goodbye! Don't hesitate to come back if you need more help with MMU."
    if stripped in {"how are you", "how is it going", "what's up", "whats up"}:
        return ("I'm doing great, thank you for asking! "
                "I'm here to help with any questions about Mountains of the Moon University.")
    if stripped in {"who are you", "what are you", "whats your name", "what is your name"}:
        return ("I'm the MMU Campus Assistant, a chatbot designed to help students, staff, and "
                "applicants with information about Mountains of the Moon University. "
                "How can I help you today?")
    if stripped in {"what can you do", "what do you do", "how can you help"}:
        return ("I can help you with information about MMU programmes, admissions, fees, campus "
                "facilities, academic calendar, contacts, and more! Just ask me anything about "
                "Mountains of the Moon University.")

    full_prompt = (
        "You are a friendly campus assistant for Mountains of the Moon University (MMU).\n"
        f"The student says: {prompt}\n"
        "Reply with a brief, warm greeting and ask how you can help with MMU-related questions. "
        "Keep your reply to 1-2 sentences.\n\n"
        "Your reply:"
    )
    return _strip_formatting(_generate_with_fallback(full_prompt))


def generate_response_with_context(
    prompt: str,
    context: str,
    history: list[str] | None = None,
    verify: bool = True,
    retrieval_scores: list[float] | None = None,
) -> tuple[str, float, str]:
    """Generate a RAG-grounded response with optional verification.

    Returns: (response_text, confidence_score, confidence_level)

    FIX-04: Removed duplicate is_list_query import (now imported once at top of function).
    FIX-05: Cloud path passes system_prompt separately — no double-injection.
    FIX-10: Prompt sanitised before use.
    """
    from utils.list_retrieval import is_list_query  # single import (FIX-04)

    prompt = _sanitise_prompt(prompt)

    # Truncate context to keep within LLM context window
    source_count = context.count("[Source:")
    if is_list_query(prompt):
        max_ctx = 9000
    elif source_count >= 3:
        max_ctx = 5000
    else:
        max_ctx = 3500
    if len(context) > max_ctx:
        context = context[:max_ctx]

    # Format history as structured User/Bot turns (up to 10 recent turns)
    history_turns = history[-10:] if history else []
    history_block = ""
    if history_turns:
        formatted_turns = []
        for i, turn in enumerate(history_turns):
            role = "User" if i % 2 == 0 else "Bot"
            formatted_turns.append(f"{role}: {turn.strip()}")
        history_block = "\n".join(formatted_turns)

    # Build a fresh system prompt with today's date
    system_prompt = _build_system_prompt()

    # Build the user-turn prompt (system prompt sent separately)
    user_prompt = (
        "=== CONTEXT FROM MMU DATABASE ===\n"
        f"{context}\n"
        "=== END OF CONTEXT ===\n\n"
    )
    if history_block:
        user_prompt += f"=== CONVERSATION HISTORY ===\n{history_block}\n=== END OF HISTORY ===\n\n"
    user_prompt += (
        f"User's question: {prompt}\n\n"
        "INSTRUCTIONS:\n"
        "- Do NOT add verbose warning disclaimers or repeat that contact details can change.\n"
        "- Carefully read ALL context chunks above before answering.\n"
        "- Answer ONLY using information from the context that is DIRECTLY relevant to the question.\n"
        "- For faculty/department/school questions: look for any mention of departments, units, "
        "or schools listed under that faculty in any chunk — do not give up after reading one chunk.\n"
        "- For staff/role questions: scan every chunk for names, titles, and roles that match the "
        "faculty or department asked about.\n"
        "- Only say you don't have the information if you have read ALL chunks and found nothing relevant.\n"
        "- Do NOT use unrelated context to fabricate an answer.\n"
        "- If asked about a previous question, refer to the conversation history.\n"
        "- Be concise, direct, and helpful.\n"
        "- NEVER reference your data source. Answer directly and naturally.\n"
    )
    if is_list_query(prompt):
        user_prompt += (
            "- FORMAT: Use a plain bullet list with one line per person. "
            "Start each line with a hyphen and a space, like: - Name - Role\n"
            "- Group by department with a short heading line, then bullets underneath.\n"
            "- Include ALL staff names and roles from the context.\n"
        )
    user_prompt += "Answer:"

    logger.info(
        "RAG prompt: question=%r, context_len=%d, context_preview=%r",
        prompt, len(context), context[:200],
    )

    # Generate response — system prompt passed separately for both paths (FIX-05)
    model_info = _resolve_model()
    if model_info.is_cloud:
        try:
            response = _call_cloud_api(
                model_info, user_prompt, timeout=60,
                system_prompt=system_prompt,   # passed as dedicated param, not concatenated
            )
        except Exception:
            response = _generate_with_fallback(user_prompt)
    else:
        try:
            response = _call_ollama_chat(
                model_info.name, system_prompt, user_prompt, timeout=90,
            )
        except Exception:
            response = _generate_with_fallback(user_prompt)

    # ── Preliminary confidence gate ────────────────────────────────────────
    _prelim_scores = retrieval_scores if retrieval_scores else [0.5]
    _prelim_avg = sum(_prelim_scores) / max(len(_prelim_scores), 1)
    _confidence_gate = getattr(settings, "VERIFICATION_CONFIDENCE_GATE", 0.60)

    # Verification pass (spec §11)
    verifier_score = 5
    if verify and settings.VERIFICATION_ENABLED and _prelim_avg < _confidence_gate:
        logger.info(
            "Running verification (prelim_avg=%.2f < gate=%.2f)",
            _prelim_avg, _confidence_gate,
        )
        verifier_score, issues = _verify_response(response, context)

        if verifier_score <= 2:
            logger.warning("Verifier score %d — falling back. Issues: %s", verifier_score, issues)
            response = (
                "I found some information about your question, but I want to make sure I give you "
                "accurate details. I'd recommend contacting the relevant MMU office directly, or "
                "you can try rephrasing your question for a more specific answer."
            )
        elif verifier_score == 3:
            logger.info("Verifier score 3 — regenerating once. Issues: %s", issues)
            regen_prompt = (
                f"{user_prompt}\n\n"
                f"IMPORTANT: A fact-checker found these issues with a previous answer: {issues}\n"
                "Please provide a corrected answer using ONLY the context above:"
            )
            response = _generate_with_fallback(regen_prompt)

    # Compute confidence
    query_terms = set(prompt.lower().split())
    context_lower = context.lower()
    covered = sum(1 for t in query_terms if t in context_lower and len(t) > 2)
    coverage = covered / max(len(query_terms), 1)

    scores = retrieval_scores if retrieval_scores else [0.5]
    confidence, level = compute_confidence(scores, coverage, verifier_score)

    # Apply disclaimers BEFORE stripping (FIX-08: disclaimers are now plain text)
    response = _append_disclaimers(response)

    # Strip any residual markdown
    response = _strip_formatting(response)

    return response, confidence, level


# ---------------------------------------------------------------------------
# Mock response (DEV_MODE)
# ---------------------------------------------------------------------------

def _get_mock_response(prompt: str) -> str:
    """Generate a mock response for development/testing when Ollama is unavailable."""
    prompt_lower = prompt.lower()

    if any(word in prompt_lower for word in ['hello', 'hi', 'hey', 'greetings']):
        return "Hello! I'm the MMU Campus Assistant. How can I help you learn about Mountains of the Moon University today?"

    if 'course' in prompt_lower or 'program' in prompt_lower:
        return "MMU offers various programmes including Computer Science, Business Administration, Engineering, and more. Would you like details about a specific programme?"

    if 'tuition' in prompt_lower or 'fee' in prompt_lower:
        return (
            "For current tuition fees and payment plans, please visit the MMU admissions office "
            "or check the university website.\n\n---\n"
            "NOTE: Fee amounts are estimates. Always verify official amounts at the University Bursar's office."
        )

    if 'admission' in prompt_lower:
        return (
            "MMU admissions are open! Requirements vary by programme. Contact the admissions office for details.\n\n---\n"
            "NOTE: This is advisory information only. Final admission decisions are determined solely by the Academic Registrar's office."
        )

    return (
        f"I'm the MMU Campus Assistant (running in development mode). "
        f"You asked about: '{prompt[:50]}'. For accurate information, please contact MMU directly."
    )


# ---------------------------------------------------------------------------
# Custom generation (used by admin / non-RAG pathways)
# ---------------------------------------------------------------------------

def generate_custom_response(
    system_prompt: str,
    user_prompt: str,
    json_format: bool = False,
    timeout: int = 90,
) -> str:
    """Generate a response using a custom system prompt and user prompt.

    Falls back to smaller/local models on error.
    FIX-10: user_prompt sanitised before use.
    """
    user_prompt = _sanitise_prompt(user_prompt)

    model_info = _resolve_model()
    logger.info(
        "Resolved model for custom generation: %s (type=%s)",
        model_info.name, model_info.model_type,
    )

    if model_info.is_cloud:
        try:
            provider = (model_info.api_provider or "").lower()
            handler = _CLOUD_DISPATCH.get(provider)
            if not handler:
                if provider == "custom":
                    handler = _call_openai_api
                else:
                    raise ValueError(f"Unknown cloud provider: {provider}")

            if not model_info.api_key:
                raise ValueError(f"No API key configured for cloud model {model_info.name}")

            return handler(
                model_info, user_prompt, timeout=timeout,
                system_prompt=system_prompt, json_format=json_format,
            )
        except Exception as exc:
            logger.warning(
                "Cloud model %s failed: %s — falling back to local Ollama",
                model_info.name, exc,
            )
            model_info = ModelInfo(name=settings.OLLAMA_PRIMARY_MODEL, model_type="local_ollama")

    # Local Ollama path
    model = model_info.name
    try:
        return _call_ollama_chat(
            model, system_prompt, user_prompt, timeout=timeout, json_format=json_format,
        )
    except Exception as exc:
        logger.warning("Primary local model %s failed, trying fallback: %s", model, exc)

    fb_model = settings.OLLAMA_FALLBACK_MODEL
    if fb_model and fb_model.lower() != model.lower():
        try:
            return _call_ollama_chat(
                fb_model, system_prompt, user_prompt, timeout=timeout, json_format=json_format,
            )
        except Exception as exc:
            logger.warning("Fallback local model %s failed: %s", fb_model, exc)

    try:
        installed = _list_installed_models()
        tried = {model.lower(), (fb_model or "").lower()}
        remaining = [m for m in installed if m.lower() not in tried]
        chosen = _pick_best_model(remaining, prefer_small=True)
        if chosen:
            logger.info("Last-resort model for custom generation: %s", chosen)
            return _call_ollama_chat(
                chosen, system_prompt, user_prompt, timeout=timeout, json_format=json_format,
            )
    except Exception:
        logger.exception("Installed model selection failed for custom generation")

    if settings.DEV_MODE:
        return _get_mock_response(user_prompt)

    raise RuntimeError("All LLM models exhausted for custom generation")