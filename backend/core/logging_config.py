import logging
from logging.handlers import RotatingFileHandler
from pathlib import Path


def setup_logging(level_str: str, log_file: str | None = None) -> None:
    level = getattr(logging, level_str.upper(), logging.INFO)

    # Root logger
    logger = logging.getLogger()
    logger.setLevel(level)

    # Clear existing handlers to prevent duplication on reload
    for h in list(logger.handlers):
        logger.removeHandler(h)

    fmt = logging.Formatter(
        "%(asctime)s | %(levelname)s | %(name)s | %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S",
    )

    # Console handler
    ch = logging.StreamHandler()
    ch.setLevel(level)
    ch.setFormatter(fmt)
    logger.addHandler(ch)

    # File handler (optional)
    if log_file:
        try:
            log_path = Path(log_file)
            log_path.parent.mkdir(parents=True, exist_ok=True)
            fh = RotatingFileHandler(log_path, maxBytes=5 * 1024 * 1024, backupCount=3)
            fh.setLevel(level)
            fh.setFormatter(fmt)
            logger.addHandler(fh)
        except Exception:
            # If file handler fails, continue with console only
            logger.warning("File logging could not be initialized; defaulting to console only.")
