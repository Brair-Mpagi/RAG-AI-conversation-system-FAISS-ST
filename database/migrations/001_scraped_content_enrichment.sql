-- Migration: scraped content enrichment columns (Phase 1)
-- Run: mysql -u campus_ai_user -p campus_ai_db < database/migrations/001_scraped_content_enrichment.sql

USE campus_ai_db;

ALTER TABLE scraped_content
    ADD COLUMN sections_json JSON NULL COMMENT 'Structured sections [{heading, text}] from scraper' AFTER cleaned_content,
    ADD COLUMN search_document TEXT NULL COMMENT 'Rule-built document card for retrieval' AFTER sections_json,
    ADD COLUMN enrichment_json JSON NULL COMMENT 'LLM enrichment (phase 2); rule hints stored until then' AFTER search_document,
    ADD COLUMN enrichment_hash VARCHAR(64) NULL COMMENT 'Hash when enrichment last ran' AFTER enrichment_json,
    ADD COLUMN enrichment_status ENUM('pending', 'done', 'failed', 'skipped') DEFAULT 'pending' AFTER enrichment_hash,
    ADD COLUMN enriched_at TIMESTAMP NULL AFTER enrichment_status;

-- Broader full-text search across title + search document + body
ALTER TABLE scraped_content
    ADD FULLTEXT INDEX ft_scraped_search (page_title, search_document, cleaned_content);
