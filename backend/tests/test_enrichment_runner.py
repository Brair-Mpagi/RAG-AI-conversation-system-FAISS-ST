"""Tests for enrichment batch runner."""

from unittest.mock import MagicMock, patch

from utils.enrichment_runner import enrich_all_pending, get_enrichment_status


def test_get_enrichment_status_parses_rows():
    db = MagicMock()
    db.execute.return_value.mappings.return_value.all.return_value = [
        {"enrichment_status": "pending", "cnt": 5},
        {"enrichment_status": "done", "cnt": 10},
    ]
    out = get_enrichment_status(db)
    assert out["status"] == "ok"
    assert out["pending"] == 5
    assert out["done"] == 10
    assert out["total"] == 15


def test_enrich_all_stops_when_no_rows():
    db = MagicMock()
    with patch("utils.enrichment_runner.enrich_scraped_content") as mock_enrich:
        mock_enrich.return_value = {"status": "ok", "processed": 0, "enriched": 0, "failed": 0}
        with patch("utils.enrichment_runner.get_enrichment_status") as mock_status:
            mock_status.return_value = {"pending": 0}
            out = enrich_all_pending(db, batch_size=10, max_rounds=5)
    assert out["rounds"] == 1
    assert mock_enrich.call_count == 1


def test_update_enrichment_retry_success():
    from utils.enrichment_runner import _update_enrichment_with_retry
    db = MagicMock()

    call_count = 0
    def mock_execute(*args, **kwargs):
        nonlocal call_count
        call_count += 1
        if call_count == 1:
            raise Exception("1213 Deadlock found when trying to get lock")
        return MagicMock()

    db.execute = mock_execute

    with patch("time.sleep") as mock_sleep:
        res = _update_enrichment_with_retry(
            db,
            scraped_id=123,
            payload={"search_document": "doc", "enrichment_json": "{}", "enrichment_status": "done"},
            content_hash="hash",
            max_retries=3,
        )

    assert res is True
    assert call_count == 2
    assert db.rollback.call_count == 1
    assert db.commit.call_count == 1
    mock_sleep.assert_called_once_with(0.2)


def test_update_enrichment_retry_failure():
    from utils.enrichment_runner import _update_enrichment_with_retry
    db = MagicMock()

    db.execute.side_effect = Exception("1205 Lock wait timeout exceeded")

    with patch("time.sleep") as mock_sleep, patch("utils.enrichment_runner._update_status_failed") as mock_failed:
        res = _update_enrichment_with_retry(
            db,
            scraped_id=123,
            payload={"search_document": "doc", "enrichment_json": "{}", "enrichment_status": "done"},
            content_hash="hash",
            max_retries=3,
        )

    assert res is False
    assert db.execute.call_count == 3
    assert db.rollback.call_count == 3
    assert mock_sleep.call_count == 2


def test_update_status_failed_retry():
    from utils.enrichment_runner import _update_status_failed
    db = MagicMock()

    call_count = 0
    def mock_execute(*args, **kwargs):
        nonlocal call_count
        call_count += 1
        if call_count == 1:
            raise Exception("1213 Deadlock found")
        return MagicMock()

    db.execute = mock_execute

    with patch("time.sleep") as mock_sleep:
        _update_status_failed(db, 123, max_retries=3)

    assert call_count == 2
    assert db.rollback.call_count == 1
    assert db.commit.call_count == 1
    mock_sleep.assert_called_once_with(0.1)

