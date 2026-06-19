<?php
/**
 * Simple test page to check migration status and run migration
 * Access via: http://localhost/Admin-F/test_migration.php
 */

session_start();
require_once __DIR__ . '/db.php';

// Check if logged in
if (!isset($_SESSION['admin_id'])) {
    die("ERROR: Not logged in. Please login to Admin Panel first.");
}

echo "<h1>Blocked URLs Migration Test</h1>";
echo "<p>Logged in as admin_id: " . $_SESSION['admin_id'] . "</p>";
echo "<hr>";

// Check if blocked_urls table exists
$table_check = $conn->query("SHOW TABLES LIKE 'blocked_urls'");
if ($table_check->num_rows === 0) {
    die("<p style='color:red'>ERROR: blocked_urls table does not exist. Run the migration SQL first.</p>");
}
echo "<p style='color:green'>✓ blocked_urls table exists</p>";

// Count duplicates in scraped_content
$scraped_result = $conn->query("SELECT COUNT(*) as count FROM scraped_content WHERE status = 'duplicate'");
$scraped_count = $scraped_result->fetch_assoc()['count'];
echo "<p>Duplicates in scraped_content: <strong>{$scraped_count}</strong></p>";

// Count duplicates in blocked_urls
$blocked_result = $conn->query("SELECT COUNT(*) as count FROM blocked_urls WHERE reason = 'duplicate'");
$blocked_count = $blocked_result->fetch_assoc()['count'];
echo "<p>Duplicates in blocked_urls: <strong>{$blocked_count}</strong></p>";

// Show examples if any
if ($scraped_count > 0) {
    echo "<h3>Examples of duplicates to migrate:</h3>";
    $examples = $conn->query("SELECT page_url, page_title FROM scraped_content WHERE status = 'duplicate' LIMIT 5");
    echo "<ul>";
    while ($row = $examples->fetch_assoc()) {
        echo "<li>" . htmlspecialchars($row['page_title'] ?? 'Untitled') . "<br>";
        echo "<small>" . htmlspecialchars($row['page_url']) . "</small></li>";
    }
    echo "</ul>";
}

echo "<hr>";

// Handle migration action
if (isset($_GET['action']) && $_GET['action'] === 'migrate') {
    echo "<h2>Running Migration...</h2>";
    
    // Get all duplicates
    $select_query = "
        SELECT scraped_id, source_id, page_url, content_hash, canonical_page_id
        FROM scraped_content 
        WHERE status = 'duplicate'
    ";
    
    $result = $conn->query($select_query);
    $duplicates = [];
    while ($row = $result->fetch_assoc()) {
        $duplicates[] = $row;
    }
    
    echo "<p>Found {count($duplicates)} duplicates to migrate</p>";
    
    $migrated_count = 0;
    
    // Insert into blocked_urls
    $insert_stmt = $conn->prepare("
        INSERT INTO blocked_urls (source_id, page_url, reason, blocked_by, original_scraped_id, notes)
        VALUES (?, ?, 'duplicate', ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            reason = 'duplicate',
            original_scraped_id = VALUES(original_scraped_id),
            notes = VALUES(notes)
    ");
    
    foreach ($duplicates as $dup) {
        $notes = "Migrated from scraped_content. Content hash: " . ($dup['content_hash'] ?? 'unknown');
        if ($dup['canonical_page_id']) {
            $notes .= ". Duplicate of scraped_id " . $dup['canonical_page_id'];
        }
        
        $insert_stmt->bind_param(
            'isiss',
            $dup['source_id'],
            $dup['page_url'],
            $_SESSION['admin_id'],
            $dup['canonical_page_id'],
            $notes
        );
        $insert_stmt->execute();
        $migrated_count++;
    }
    $insert_stmt->close();
    
    echo "<p style='color:green'>✓ Migrated {$migrated_count} to blocked_urls</p>";
    
    // Delete from scraped_content
    $delete_result = $conn->query("DELETE FROM scraped_content WHERE status = 'duplicate'");
    $deleted_count = $conn->affected_rows;
    echo "<p style='color:green'>✓ Deleted {$deleted_count} from scraped_content</p>";
    
    // Remove from queue
    $conn->query("
        DELETE slq FROM scrape_link_queue slq
        INNER JOIN blocked_urls bu ON slq.page_url = bu.page_url AND slq.source_id = bu.source_id
        WHERE bu.reason = 'duplicate'
    ");
    $queue_deleted = $conn->affected_rows;
    echo "<p style='color:green'>✓ Deleted {$queue_deleted} from queue</p>";
    
    echo "<h3>Migration Complete!</h3>";
    echo "<p><a href='test_migration.php'>Refresh to see new counts</a></p>";
    
} else {
    // Show migration button
    if ($scraped_count > 0) {
        echo "<p><a href='test_migration.php?action=migrate' style='display:inline-block;padding:10px 20px;background:#fbbf24;color:#000;text-decoration:none;border-radius:5px;font-weight:bold'>Migrate {$scraped_count} Duplicates</a></p>";
    } else {
        echo "<p style='color:green'>✓ No duplicates to migrate!</p>";
    }
}

echo "<hr>";
echo "<p><a href='web_scraper.php'>← Back to Web Scraper</a></p>";
?>
