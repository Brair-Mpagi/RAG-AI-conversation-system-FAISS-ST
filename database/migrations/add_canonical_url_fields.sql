-- Migration: Add canonical URL and alias tracking fields
-- Purpose: Implement proper URL canonicalization and deduplication

ALTER TABLE scraped_content
ADD COLUMN canonical_url VARCHAR(1000) DEFAULT NULL COMMENT 'Canonical URL extracted from <link rel="canonical">',
ADD COLUMN is_canonical BOOLEAN DEFAULT TRUE COMMENT 'TRUE if this is the canonical version, FALSE if alias',
ADD COLUMN canonical_page_id INT DEFAULT NULL COMMENT 'Points to canonical scraped_id if this is an alias',
ADD COLUMN url_aliases JSON DEFAULT NULL COMMENT 'Array of alias URLs that point to this canonical page',
ADD INDEX idx_canonical_url (canonical_url(500)),
ADD INDEX idx_is_canonical (is_canonical),
ADD INDEX idx_canonical_page_id (canonical_page_id),
ADD FOREIGN KEY fk_canonical_page (canonical_page_id) REFERENCES scraped_content(scraped_id) ON DELETE SET NULL;

-- Add index on content_hash for faster duplicate detection
ALTER TABLE scraped_content
ADD INDEX idx_content_hash_source (source_id, content_hash);
