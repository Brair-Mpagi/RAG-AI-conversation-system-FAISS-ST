-- Cleanup Script: Remove duplicates from scraped_content after migration to blocked_urls
-- Run this AFTER running create_blocked_urls_table.sql

-- Check how many duplicates exist
SELECT 
    'Before cleanup:' as status,
    COUNT(*) as duplicate_count 
FROM scraped_content 
WHERE status = 'duplicate';

-- Check how many are in blocked_urls
SELECT 
    'In blocked_urls:' as status,
    COUNT(*) as blocked_count 
FROM blocked_urls 
WHERE reason = 'duplicate';

-- Delete duplicates from scraped_content (they're now in blocked_urls)
DELETE FROM scraped_content WHERE status = 'duplicate';

-- Show results
SELECT 
    'After cleanup:' as status,
    COUNT(*) as remaining_duplicates 
FROM scraped_content 
WHERE status = 'duplicate';

-- Show space saved
SELECT 
    'Total pages in scraped_content:' as status,
    COUNT(*) as total_pages 
FROM scraped_content;

SELECT 
    'Total blocked URLs:' as status,
    COUNT(*) as total_blocked 
FROM blocked_urls;
