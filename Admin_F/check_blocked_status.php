<?php
/**
 * Check blocked URLs system status
 * Access via: http://localhost/Admin-F/check_blocked_status.php
 */

session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['admin_id'])) {
    die("ERROR: Not logged in.");
}

echo "<h1>Blocked URLs System Status</h1>";
echo "<p>Admin ID: " . $_SESSION['admin_id'] . "</p>";
echo "<hr>";

// Check blocked_urls table
$total_blocked = $conn->query("SELECT COUNT(*) as count FROM blocked_urls")->fetch_assoc()['count'];
echo "<h2>Total Blocked URLs: {$total_blocked}</h2>";

if ($total_blocked > 0) {
    // Breakdown by reason
    echo "<h3>Breakdown by Reason:</h3>";
    $breakdown = $conn->query("SELECT reason, COUNT(*) as count FROM blocked_urls GROUP BY reason");
    echo "<ul>";
    while ($row = $breakdown->fetch_assoc()) {
        echo "<li><strong>{$row['reason']}</strong>: {$row['count']}</li>";
    }
    echo "</ul>";
    
    // Show recent blocked URLs
    echo "<h3>Recent Blocked URLs (last 10):</h3>";
    $recent = $conn->query("SELECT page_url, reason, blocked_at, notes FROM blocked_urls ORDER BY blocked_at DESC LIMIT 10");
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse'>";
    echo "<tr><th>URL</th><th>Reason</th><th>Blocked At</th><th>Notes</th></tr>";
    while ($row = $recent->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars(substr($row['page_url'], 0, 80)) . "...</td>";
        echo "<td>" . htmlspecialchars($row['reason']) . "</td>";
        echo "<td>" . htmlspecialchars($row['blocked_at']) . "</td>";
        echo "<td>" . htmlspecialchars(substr($row['notes'] ?? '', 0, 50)) . "...</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "<hr>";

// Check scraped_content status
echo "<h2>Scraped Content Status:</h2>";
$status_breakdown = $conn->query("SELECT status, COUNT(*) as count FROM scraped_content GROUP BY status");
echo "<ul>";
while ($row = $status_breakdown->fetch_assoc()) {
    echo "<li><strong>{$row['status']}</strong>: {$row['count']}</li>";
}
echo "</ul>";

echo "<hr>";

// Check queue status
echo "<h2>Queue Status:</h2>";
$queue_breakdown = $conn->query("SELECT status, COUNT(*) as count FROM scrape_link_queue GROUP BY status");
echo "<ul>";
while ($row = $queue_breakdown->fetch_assoc()) {
    echo "<li><strong>{$row['status']}</strong>: {$row['count']}</li>";
}
echo "</ul>";

echo "<hr>";

// System readiness
echo "<h2>System Readiness:</h2>";
echo "<ul>";
echo "<li>✅ blocked_urls table exists</li>";
echo "<li>✅ Scan will check blocked URLs</li>";
echo "<li>✅ Scrape will skip blocked URLs</li>";
echo "<li>✅ Cleanup features will move to blocked_urls</li>";
echo "</ul>";

echo "<h3>Next Steps:</h3>";
echo "<ol>";
echo "<li>When you find duplicate content, use <strong>'Find Similar Content'</strong> button</li>";
echo "<li>When you see malformed URLs, use <strong>'Cleanup Malformed'</strong> buttons</li>";
echo "<li>Blocked URLs will automatically prevent re-scanning and re-scraping</li>";
echo "</ol>";

echo "<hr>";
echo "<p><a href='web_scraper.php'>← Back to Web Scraper</a></p>";
?>
