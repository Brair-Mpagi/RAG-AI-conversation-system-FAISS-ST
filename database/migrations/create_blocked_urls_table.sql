-- Migration: Create blocked_urls table
-- Purpose: Store permanently blocked URLs to prevent re-scanning and re-scraping
-- Date: 2026-05-24

CREATE TABLE IF NOT EXISTS blocked_urls (
  blocked_id INT AUTO_INCREMENT PRIMARY KEY,
  source_id INT NOT NULL,
  page_url VARCHAR(1000) NOT NULL,
  reason ENUM('duplicate', 'malformed', 'manual', 'redirect_loop') NOT NULL,
  blocked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  blocked_by INT NULL,  -- admin_id who blocked it
  original_scraped_id INT NULL,  -- reference to original page if duplicate
  notes TEXT NULL,
  UNIQUE KEY unique_source_url (source_id, page_url),
  KEY idx_source (source_id),
  KEY idx_reason (reason),
  FOREIGN KEY (source_id) REFERENCES scraping_sources(source_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrate existing duplicate pages to blocked_urls
INSERT INTO blocked_urls (source_id, page_url, reason, original_scraped_id, notes)
SELECT 
  source_id,
  page_url,
  'duplicate' as reason,
  canonical_page_id as original_scraped_id,
  CONCAT('Migrated from scraped_content. Content hash: ', COALESCE(content_hash, 'unknown')) as notes
FROM scraped_content
WHERE status = 'duplicate'
ON DUPLICATE KEY UPDATE blocked_id = blocked_id;

-- Optional: Clean up migrated duplicates from scraped_content
-- Uncomment the line below if you want to remove duplicates after migration
-- DELETE FROM scraped_content WHERE status = 'duplicate';
