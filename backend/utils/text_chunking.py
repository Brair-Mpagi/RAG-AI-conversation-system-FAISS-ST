"""Shared text chunking for indexing and enrichment."""

from __future__ import annotations

from typing import List


def chunk_text(text_content: str, chunk_size: int = 500, overlap: int = 75) -> List[str]:
    """Split text into overlapping semantic chunks for better embedding quality."""
    if not text_content or len(text_content) <= chunk_size:
        return [text_content] if text_content else []

    chunks: List[str] = []
    paragraphs = [p.strip() for p in text_content.split("\n") if p.strip()]

    current_chunk = ""
    for para in paragraphs:
        if current_chunk and len(current_chunk) + len(para) + 1 > chunk_size:
            chunks.append(current_chunk.strip())
            if overlap > 0 and len(current_chunk) > overlap:
                current_chunk = current_chunk[-overlap:].strip() + "\n" + para
            else:
                current_chunk = para
        else:
            current_chunk = (current_chunk + "\n" + para).strip() if current_chunk else para

    if current_chunk.strip():
        chunks.append(current_chunk.strip())

    final_chunks: List[str] = []
    for chunk in chunks:
        if len(chunk) <= chunk_size * 1.5:
            final_chunks.append(chunk)
        else:
            pos = 0
            while pos < len(chunk):
                end = min(pos + chunk_size, len(chunk))
                if end < len(chunk):
                    for sep in [". ", ".\n", "! ", "? ", "\n"]:
                        last_sep = chunk[pos:end].rfind(sep)
                        if last_sep > chunk_size // 3:
                            end = pos + last_sep + len(sep)
                            break
                final_chunks.append(chunk[pos:end].strip())
                pos = end - overlap if end < len(chunk) else end

    return [c for c in final_chunks if len(c) >= 30]
