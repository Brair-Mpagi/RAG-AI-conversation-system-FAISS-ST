from __future__ import annotations

from pathlib import Path

from pydantic import model_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

# Resolve the backend directory so .env is always found correctly
_BASE_DIR = Path(__file__).resolve().parents[1]


class Settings(BaseSettings):
    """Application settings loaded from environment variables / .env file.

    Using pydantic-settings gives us:
      - Automatic type coercion and validation
      - Clear startup errors for missing / invalid config
      - Built-in .env file loading
    """

    model_config = SettingsConfigDict(
        env_file=str(_BASE_DIR / ".env"),
        env_file_encoding="utf-8",
        case_sensitive=True,
        extra="ignore",
    )

    # ── App ──────────────────────────────────────────────────────────────
    ENVIRONMENT: str = "development"
    DEBUG: bool = True
    HOST: str = "0.0.0.0"
    PORT: int = 8000
    WEBSOCKET_PORT: int = 8002

    # ── Database ─────────────────────────────────────────────────────────
    DB_HOST: str = "localhost"
    DB_PORT: int = 3306
    DB_USER: str = "campus_ai_user"
    DB_PASSWORD: str = "root"
    DB_NAME: str = "campus_ai_db"
    DB_CHARSET: str = "utf8mb4"
    MYSQL_UNIX_SOCKET: str | None = None

    # Explicit full URL (overrides everything below)
    DATABASE_URL: str | None = None

    DB_POOL_SIZE: int = 10
    DB_MAX_OVERFLOW: int = 20
    DB_POOL_TIMEOUT: int = 30
    DB_POOL_RECYCLE: int = 3600

    # ── LLM / Ollama ─────────────────────────────────────────────────────
    OLLAMA_HOST: str = "http://localhost:11434"
    OLLAMA_PRIMARY_MODEL: str = "llama3.2:latest"
    OLLAMA_FALLBACK_MODEL: str = "tinyllama:1.1b"

    # ── Security ─────────────────────────────────────────────────────────
    SECRET_KEY: str = "change-me-in-production"
    ALGORITHM: str = "HS256"
    ACCESS_TOKEN_EXPIRE_MINUTES: int = 30

    # ── Proxy / CORS ──────────────────────────────────────────────────────
    # Set BEHIND_PROXY=True only when the app is behind a trusted reverse proxy
    # (nginx, Traefik, etc.) so X-Forwarded-For headers are honoured.
    BEHIND_PROXY: bool = False
    # Store CORS_ORIGINS as a plain string (comma-separated) to avoid
    # pydantic-settings v2 attempting to JSON-decode a List field from .env.
    # Use the cors_origins_list property wherever a list is needed.
    CORS_ORIGINS: str = "http://localhost:3000,http://localhost:5173,http://localhost:8080"

    @property
    def cors_origins_list(self) -> list[str]:
        """Return CORS_ORIGINS split into a list."""
        return [item.strip() for item in self.CORS_ORIGINS.split(",") if item.strip()]

    # ── Vector / Embeddings ───────────────────────────────────────────────
    EMBEDDING_MODEL: str = "all-MiniLM-L12-v2"
    RAG_HYBRID_ENABLED: bool = True
    AUTO_PIPELINE_AFTER_SCRAPE: bool = True
    FAISS_INDEX_PATH: str = str(_BASE_DIR / "vector_store" / "campus_knowledge.faiss")
    FAISS_METADATA_PATH: str = str(_BASE_DIR / "vector_store" / "metadata.json")
    RAG_TOP_K: int = 15  # Increased from 10 for better recall
    RAG_SCORE_THRESHOLD: float = 0.35
    RAG_DISTANCE_THRESHOLD: float = 0.95  # L2 distance threshold — tighter with chunked docs
    MAX_CONTEXT_LENGTH: int = 8192

    # ── Content enrichment (Phase 2) ───────────────────────────────────────
    ENRICHMENT_BATCH_LIMIT: int = 100

    # ── Preprocessing ─────────────────────────────────────────────────────
    MAX_MESSAGE_LENGTH: int = 5000

    # ── Logging ───────────────────────────────────────────────────────────
    LOG_LEVEL: str = "INFO"
    LOG_FILE: str = str(_BASE_DIR.parent / "logs" / "backend.log")

    # ── Cache ─────────────────────────────────────────────────────────────
    ENABLE_RESPONSE_CACHE: bool = True
    CACHE_TTL: int = 3600

    # ── Dev / resource guards ─────────────────────────────────────────────
    DEV_MODE: bool = False
    LOW_MEMORY: bool = False
    RAG_ENABLED: bool = True

    # ── Confidence scoring thresholds (spec §12) ──────────────────────────
    CONFIDENCE_HIGH_THRESHOLD: float = 0.75
    CONFIDENCE_MEDIUM_THRESHOLD: float = 0.50

    # ── Verification (spec §11) ───────────────────────────────────────────
    VERIFICATION_ENABLED: bool = True
    # Only run the second-pass verifier when confidence is below this gate
    VERIFICATION_CONFIDENCE_GATE: float = 0.60

    # ── Rate limiting (spec §16) ──────────────────────────────────────────
    RATE_LIMIT_MAX: int = 30
    RATE_LIMIT_WINDOW: int = 300  # seconds

    # ── Computed: DATABASE_URL ─────────────────────────────────────────────
    @model_validator(mode="after")
    def build_database_url(self) -> "Settings":
        """Build DATABASE_URL from components if not explicitly set."""
        if not self.DATABASE_URL:
            if self.MYSQL_UNIX_SOCKET:
                self.DATABASE_URL = (
                    f"mysql+pymysql://{self.DB_USER}:{self.DB_PASSWORD}@localhost/{self.DB_NAME}"
                    f"?unix_socket={self.MYSQL_UNIX_SOCKET}&charset={self.DB_CHARSET}"
                )
            else:
                self.DATABASE_URL = (
                    f"mysql+pymysql://{self.DB_USER}:{self.DB_PASSWORD}"
                    f"@{self.DB_HOST}:{self.DB_PORT}/{self.DB_NAME}"
                    f"?charset={self.DB_CHARSET}"
                )
        return self

    @model_validator(mode="after")
    def validate_production_secrets(self) -> "Settings":
        """Crash at startup in production if secrets are not changed from defaults."""
        if self.ENVIRONMENT == "production":
            if self.SECRET_KEY in ("change-me-in-production", "", "replace-with-a-secure-32char-key"):
                raise ValueError(
                    "SECRET_KEY must be set to a strong random value in production. "
                    "Generate one with: openssl rand -hex 32"
                )
            if len(self.SECRET_KEY) < 32:
                raise ValueError(
                    "SECRET_KEY must be at least 32 characters long in production."
                )
        return self

    # Backwards-compat: code that reads settings.CORS_ORIGINS as a list
    # should use settings.cors_origins_list instead, but for simple cases
    # where CORS_ORIGINS is already a list (e.g. tests without .env file)
    # we keep it as-is.


# Singleton settings instance used throughout the backend
settings = Settings()
