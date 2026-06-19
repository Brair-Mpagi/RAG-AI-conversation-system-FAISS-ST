from __future__ import annotations

from functools import lru_cache
from typing import Iterable

import numpy as np

try:
    from sentence_transformers import SentenceTransformer  # type: ignore
    SENTENCE_TRANSFORMERS_AVAILABLE = True
except Exception:
    SentenceTransformer = None  # type: ignore
    SENTENCE_TRANSFORMERS_AVAILABLE = False

from core.config import settings


import logging
logger = logging.getLogger(__name__)

@lru_cache(maxsize=1)
def _get_model():
    if not SENTENCE_TRANSFORMERS_AVAILABLE:
        return None
    try:
        # Attempt to load locally/strictly offline first to avoid huggingface network timeouts/latency
        return SentenceTransformer(settings.EMBEDDING_MODEL, local_files_only=True)
    except Exception:
        try:
            # Fall back to online mode if not cached locally
            return SentenceTransformer(settings.EMBEDDING_MODEL, local_files_only=False)
        except Exception as e:
            logger.warning(f"Failed to load SentenceTransformer online/offline: {e}")
            return None


def embed_texts(texts: Iterable[str]):
    texts_list = list(texts)
    model = _get_model()
    if model is None:
        # Deterministic, low-quality fallback embedding (dev only)
        dim = 384
        arr = np.zeros((len(texts_list), dim), dtype=np.float32)
        for i, t in enumerate(texts_list):
            # Simple hashing to distribute tokens
            h = abs(hash(t)) % dim
            arr[i, h] = 1.0
        return arr
    return model.encode(texts_list, convert_to_numpy=True)
