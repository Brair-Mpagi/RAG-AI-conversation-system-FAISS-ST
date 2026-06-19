import io
import json
import pytest
from datetime import datetime
from sqlalchemy import create_engine, text, event
from sqlalchemy.orm import sessionmaker
from utils.import_enriched import import_enriched_file

class MockUploadFile:
    def __init__(self, filename: str, content: bytes):
        self.filename = filename
        self.file = io.BytesIO(content)

@pytest.fixture
def db_session():
    # In-memory SQLite database
    engine = create_engine("sqlite:///:memory:")
    
    # Register MySQL specific functions for SQLite
    @event.listens_for(engine, "connect")
    def connect(dbapi_connection, connection_record):
        dbapi_connection.create_function("now", 0, lambda: datetime.now().isoformat())
        dbapi_connection.create_function("if", 3, lambda cond, t, f: t if cond else f)
        dbapi_connection.create_function("NOW", 0, lambda: datetime.now().isoformat())
        dbapi_connection.create_function("IF", 3, lambda cond, t, f: t if cond else f)

    Session = sessionmaker(bind=engine)
    db = Session()
    
    # Create the minimal schema for scraped_content
    db.execute(text("""
        CREATE TABLE scraped_content (
            scraped_id INTEGER PRIMARY KEY,
            page_url TEXT,
            page_title TEXT,
            raw_content TEXT,
            cleaned_content TEXT,
            sections_json TEXT,
            search_document TEXT,
            enrichment_json TEXT,
            enrichment_status TEXT,
            enriched_at TEXT,
            status TEXT,
            content_hash TEXT,
            enrichment_hash TEXT
        )
    """))
    db.commit()
    
    yield db
    db.close()

def test_import_csv_preview(db_session):
    # Seed db with raw page
    db_session.execute(text("""
        INSERT INTO scraped_content (scraped_id, page_url, page_title, raw_content, status)
        VALUES (101, 'http://example.com', 'Example Title', 'Raw content here', 'new')
    """))
    db_session.commit()

    # Create CSV import file contents
    csv_data = (
        "scraped_id,summary,tags\n"
        "101,\"This is an enriched summary\",\"[\"\"tag1\"\", \"\"tag2\"\"]\"\n"
    ).encode("utf-8")
    
    upload = MockUploadFile("enriched.csv", csv_data)
    
    # Run import in preview mode
    res = import_enriched_file(upload, db_session, preview=True)
    
    assert res["status"] == "preview"
    assert res["total_rows"] == 1
    assert len(res["changes"]) == 1
    assert res["changes"][0]["scraped_id"] == 101
    assert "enrichment_json" in res["changes"][0]["diffs"]
    
    new_enrichment = json.loads(res["changes"][0]["diffs"]["enrichment_json"]["new"])
    assert new_enrichment["summary"] == "This is an enriched summary"
    assert new_enrichment["tags"] == ["tag1", "tag2"]

def test_import_json_write(db_session):
    # Seed db with raw page
    db_session.execute(text("""
        INSERT INTO scraped_content (scraped_id, page_url, page_title, raw_content, enrichment_json, status)
        VALUES (202, 'http://example2.com', 'Example Title 2', 'Raw content 2', '{"summary": "Old summary"}', 'new')
    """))
    db_session.commit()

    # Create JSON import file contents
    json_data = json.dumps([
        {
            "scraped_id": 202,
            "summary": "Updated summary from JSON",
            "tags": "tagA, tagB",
            "raw_content": "Try to mutate protected field"
        }
    ]).encode("utf-8")
    
    upload = MockUploadFile("enriched.json", json_data)
    
    # Run import in write mode
    res = import_enriched_file(upload, db_session, preview=False)
    
    assert res["status"] == "ok"
    assert res["total_rows"] == 1
    assert res["updated"] == 1
    assert res["skipped_unmatched"] == 0
    assert "import_batch_id" in res
    
    # Verify DB state
    db_session.expire_all()
    row = db_session.execute(text("SELECT * FROM scraped_content WHERE scraped_id = 202")).mappings().first()
    
    # Parse and check enrichment_json
    enrichment = json.loads(row["enrichment_json"])
    assert enrichment["summary"] == "Updated summary from JSON"
    assert enrichment["tags"] == ["tagA", "tagB"]
    
    # Ensure protected field raw_content was NOT overwritten
    assert row["raw_content"] == "Raw content 2"

def test_import_unmatched_and_duplicates(db_session):
    # Seed db
    db_session.execute(text("""
        INSERT INTO scraped_content (scraped_id, page_url, page_title, raw_content, status)
        VALUES (303, 'http://example3.com', 'Example Title 3', 'Raw content 3', 'new')
    """))
    db_session.commit()

    # Test duplicates in import file
    csv_dup = (
        "scraped_id,summary\n"
        "303,Summary A\n"
        "303,Summary B\n"
    ).encode("utf-8")
    upload_dup = MockUploadFile("dups.csv", csv_dup)
    res_dup = import_enriched_file(upload_dup, db_session, preview=False)
    assert res_dup["status"] == "error"
    assert "duplicate_ids" in res_dup["validation"]
    assert 303 in res_dup["validation"]["duplicate_ids"]

    # Test unmatched ID in import file (should report unmatched/skipped, not error/crash)
    csv_unmatched = (
        "scraped_id,summary\n"
        "999,Summary for missing record\n"
    ).encode("utf-8")
    upload_unmatched = MockUploadFile("unmatched.csv", csv_unmatched)
    res_unmatched = import_enriched_file(upload_unmatched, db_session, preview=False)
    assert res_unmatched["status"] == "ok"
    assert res_unmatched["updated"] == 0
    assert res_unmatched["skipped_unmatched"] == 1
    assert 999 in res_unmatched["unmatched_ids"]
