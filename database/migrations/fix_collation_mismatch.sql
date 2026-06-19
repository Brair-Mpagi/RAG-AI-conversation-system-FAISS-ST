-- Fix Collation Mismatch
-- This script standardizes all text columns to utf8mb4_unicode_ci
-- Run this to fix: Illegal mix of collations error

-- ============================================================================
-- BACKUP FIRST!
-- ============================================================================
-- mysqldump -u root -p campus_ai_chatbot > backup_before_collation_fix.sql

-- ============================================================================
-- Step 1: Convert Database Default Collation
-- ============================================================================
ALTER DATABASE campus_ai_chatbot 
CHARACTER SET = utf8mb4 
COLLATE = utf8mb4_unicode_ci;

-- ============================================================================
-- Step 2: Convert scraped_content Table
-- ============================================================================
ALTER TABLE scraped_content 
CONVERT TO CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

-- Specifically fix key columns
ALTER TABLE scraped_content 
MODIFY COLUMN page_url TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
MODIFY COLUMN page_title VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
MODIFY COLUMN content_hash VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
MODIFY COLUMN parent_url TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
MODIFY COLUMN raw_content LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
MODIFY COLUMN cleaned_content LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
MODIFY COLUMN page_category VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
MODIFY COLUMN page_author VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
MODIFY COLUMN status VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
MODIFY COLUMN enrichment_status VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
MODIFY COLUMN search_document LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ============================================================================
-- Step 3: Convert scrape_link_queue Table
-- ============================================================================
ALTER TABLE scrape_link_queue 
CONVERT TO CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

ALTER TABLE scrape_link_queue 
MODIFY COLUMN page_url TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
MODIFY COLUMN discovered_from_url TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
MODIFY COLUMN status VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ============================================================================
-- Step 4: Convert blocked_urls Table
-- ============================================================================
ALTER TABLE blocked_urls 
CONVERT TO CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

ALTER TABLE blocked_urls 
MODIFY COLUMN page_url TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
MODIFY COLUMN reason VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
MODIFY COLUMN notes TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ============================================================================
-- Step 5: Convert scraping_sources Table
-- ============================================================================
ALTER TABLE scraping_sources 
CONVERT TO CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

ALTER TABLE scraping_sources 
MODIFY COLUMN source_name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
MODIFY COLUMN base_url TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
MODIFY COLUMN scrape_frequency VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
MODIFY COLUMN scraping_config TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ============================================================================
-- Step 6: Convert skip_url_patterns Table
-- ============================================================================
ALTER TABLE skip_url_patterns 
CONVERT TO CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

ALTER TABLE skip_url_patterns 
MODIFY COLUMN url_pattern VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
MODIFY COLUMN pattern_type ENUM('contains', 'starts_with', 'regex') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
MODIFY COLUMN notes TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ============================================================================
-- Step 7: Convert enrichment_data Table (if exists)
-- ============================================================================
ALTER TABLE enrichment_data 
CONVERT TO CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

ALTER TABLE enrichment_data 
MODIFY COLUMN page_type VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
MODIFY COLUMN summary TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
MODIFY COLUMN key_topics TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
MODIFY COLUMN entities TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
MODIFY COLUMN metadata TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ============================================================================
-- Step 8: Convert admin tables
-- ============================================================================
ALTER TABLE admins 
CONVERT TO CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

ALTER TABLE admins 
MODIFY COLUMN username VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
MODIFY COLUMN email VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
MODIFY COLUMN password_hash VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
MODIFY COLUMN role VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE admin_activity_logs 
CONVERT TO CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

ALTER TABLE admin_activity_logs 
MODIFY COLUMN action VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
MODIFY COLUMN module VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
MODIFY COLUMN description TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
MODIFY COLUMN affected_records TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
MODIFY COLUMN ip_address VARCHAR(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ============================================================================
-- Verification Queries
-- ============================================================================

-- Check database collation
SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME 
FROM information_schema.SCHEMATA 
WHERE SCHEMA_NAME = 'campus_ai_chatbot';

-- Check table collations
SELECT TABLE_NAME, TABLE_COLLATION 
FROM information_schema.TABLES 
WHERE TABLE_SCHEMA = 'campus_ai_chatbot' 
ORDER BY TABLE_NAME;

-- Check column collations for scraped_content
SELECT COLUMN_NAME, CHARACTER_SET_NAME, COLLATION_NAME 
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = 'campus_ai_chatbot' 
AND TABLE_NAME = 'scraped_content' 
AND CHARACTER_SET_NAME IS NOT NULL
ORDER BY COLUMN_NAME;

-- Check column collations for scrape_link_queue
SELECT COLUMN_NAME, CHARACTER_SET_NAME, COLLATION_NAME 
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = 'campus_ai_chatbot' 
AND TABLE_NAME = 'scrape_link_queue' 
AND CHARACTER_SET_NAME IS NOT NULL
ORDER BY COLUMN_NAME;

-- ============================================================================
-- Expected Results After Migration
-- ============================================================================
-- All tables should show: utf8mb4_unicode_ci
-- All text columns should show: utf8mb4 | utf8mb4_unicode_ci
-- No more collation mismatch errors!
