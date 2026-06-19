<?php
/**
 * Quick cleanup script for malformed URLs in Missing Links Queue
 * Run this once to remove all existing malformed URLs
 */

session_start();
require_once 'db.php';

// Security check
if (!isset($_SESSION['admin_id'])) {
    die('Unauthorized. Please login as admin first.');
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Cleanup Malformed URLs</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1200px; margin: 20px auto; padding: 20px; }
        .info { background: #e3f2fd; padding: 15px; border-left: 4px solid #2196f3; margin: 10px 0; }
        .success { background: #e8f5e9; padding: 15px; border-left: 4px solid #4caf50; margin: 10px 0; }
        .warning { background: #fff3e0; padding: 15px; border-left: 4px solid #ff9800; margin: 10px 0; }
        .error { background: #ffebee; padding: 15px; border-left: 4px solid #f44336; margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f5f5f5; font-weight: bold; }
        .url { font-family: monospace; font-size: 0.9em; word-break: break-all; }
        button { background: #f44336; color: white; padding: 12px 24px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        button:hover { background: #d32f2f; }
        button:disabled { background: #ccc; cursor: not-allowed; }
        .back-link { display: inline-block; margin-top: 20px; color: #2196f3; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <h1>🧹 Cleanup Malformed URLs</h1>

<?php

$action = $_GET['action'] ?? 'preview';

try {
    // Count malformed URLs with all patterns
    $count_query = "
        SELECT COUNT(*) as count
        FROM scrape_link_queue 
        WHERE page_url LIKE '%?p=%?p=%' 
           OR page_url LIKE '%?p=%/%?p=%'
           OR page_url LIKE '%2F%3Fp%3D%'
           OR page_url REGEXP '\\\\?p=[^&]*\\\\?p='
           OR page_url REGEXP '/[^/]+/[^/?]+/[^/]+/[^/?]+' AND page_url REGEXP '/([^/]+)/([^/?]+)/\\\\1/\\\\2'
           OR page_url REGEXP '/[^/]+/[^/?]+\\\\?p=[0-9]+'
           OR page_url REGEXP '/[0-9]{4}/[0-9]{2}(/[0-9]{2})?\\\\?p=[0-9]+'
    ";
    
    $result = $conn->query($count_query);
    $malformed_count = $result->fetch_assoc()['count'];
    
    // Get total count
    $total_result = $conn->query("SELECT COUNT(*) as count FROM scrape_link_queue");
    $total_count = $total_result->fetch_assoc()['count'];
    
    if ($action === 'preview') {
        // Preview mode - show what will be deleted
        echo "<div class='info'>";
        echo "<strong>Found {$malformed_count} malformed URLs</strong> out of {$total_count} total URLs in queue<br>";
        echo "These URLs have recursive encoding patterns like <code>?p=123%2F%3Fp%3D456</code>";
        echo "</div>";
        
        if ($malformed_count > 0) {
            // Show examples
            $examples_query = "
                SELECT queue_id, page_url, status, discovered_at
                FROM scrape_link_queue 
                WHERE page_url LIKE '%?p=%?p=%' 
                   OR page_url LIKE '%?p=%/%?p=%'
                   OR page_url LIKE '%2F%3Fp%3D%'
                   OR page_url REGEXP '\\\\?p=[^&]*\\\\?p='
                   OR page_url REGEXP '/[^/]+/[^/?]+/[^/]+/[^/?]+' AND page_url REGEXP '/([^/]+)/([^/?]+)/\\\\1/\\\\2'
                   OR page_url REGEXP '/[^/]+/[^/?]+\\\\?p=[0-9]+'
                   OR page_url REGEXP '/[0-9]{4}/[0-9]{2}(/[0-9]{2})?\\\\?p=[0-9]+'
                ORDER BY discovered_at DESC
                LIMIT 20
            ";
            
            $examples = $conn->query($examples_query);
            
            echo "<h3>Examples (showing first 20):</h3>";
            echo "<table>";
            echo "<tr><th>URL</th><th>Status</th><th>Discovered</th></tr>";
            
            while ($row = $examples->fetch_assoc()) {
                echo "<tr>";
                echo "<td class='url'>" . htmlspecialchars($row['page_url']) . "</td>";
                echo "<td>" . htmlspecialchars($row['status']) . "</td>";
                echo "<td>" . htmlspecialchars($row['discovered_at']) . "</td>";
                echo "</tr>";
            }
            
            echo "</table>";
            
            echo "<div class='warning'>";
            echo "<strong>⚠️ Warning:</strong> This will permanently delete {$malformed_count} URLs from the queue.";
            echo "</div>";
            
            echo "<form method='get' onsubmit='return confirm(\"Are you sure you want to delete {$malformed_count} malformed URLs?\");'>";
            echo "<input type='hidden' name='action' value='delete'>";
            echo "<button type='submit'>🗑️ Delete {$malformed_count} Malformed URLs</button>";
            echo "</form>";
        } else {
            echo "<div class='success'>";
            echo "✓ No malformed URLs found! Your queue is clean.";
            echo "</div>";
        }
        
    } elseif ($action === 'delete') {
        // Delete mode - actually remove the URLs
        if ($malformed_count > 0) {
            $delete_query = "
                DELETE FROM scrape_link_queue 
                WHERE page_url LIKE '%?p=%?p=%' 
                   OR page_url LIKE '%?p=%/%?p=%'
                   OR page_url LIKE '%2F%3Fp%3D%'
                   OR page_url REGEXP '\\\\?p=[^&]*\\\\?p='
                   OR page_url REGEXP '/[^/]+/[^/?]+/[^/]+/[^/?]+' AND page_url REGEXP '/([^/]+)/([^/?]+)/\\\\1/\\\\2'
                   OR page_url REGEXP '/[^/]+/[^/?]+\\\\?p=[0-9]+'
                   OR page_url REGEXP '/[0-9]{4}/[0-9]{2}(/[0-9]{2})?\\\\?p=[0-9]+'
            ";
            
            $conn->query($delete_query);
            $deleted = $conn->affected_rows;
            
            echo "<div class='success'>";
            echo "<strong>✓ Success!</strong> Deleted {$deleted} malformed URLs from the queue.";
            echo "</div>";
            
            // Show new counts
            $new_total = $conn->query("SELECT COUNT(*) as count FROM scrape_link_queue")->fetch_assoc()['count'];
            $new_pending = $conn->query("SELECT COUNT(*) as count FROM scrape_link_queue WHERE status='pending'")->fetch_assoc()['count'];
            
            echo "<div class='info'>";
            echo "<strong>Queue Status After Cleanup:</strong><br>";
            echo "Total URLs: {$new_total}<br>";
            echo "Pending: {$new_pending}<br>";
            echo "Removed: {$deleted}";
            echo "</div>";
            
            echo "<p><a href='?action=preview' class='back-link'>← Check Again</a></p>";
        } else {
            echo "<div class='info'>No malformed URLs to delete.</div>";
        }
    }
    
} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<strong>Error:</strong> " . htmlspecialchars($e->getMessage());
    echo "</div>";
}

?>

    <p><a href="web_scraper.php" class='back-link'>← Back to Web Scraper</a></p>

</body>
</html>
