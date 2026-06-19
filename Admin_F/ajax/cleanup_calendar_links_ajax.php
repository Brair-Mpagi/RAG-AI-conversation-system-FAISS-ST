<?php
/**
 * AJAX endpoint for cleaning up calendar download links
 * Removes URLs with ?ical= or ?outlook-ical= parameters
 */

session_start();
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'count';

try {
    if ($action === 'count') {
        // Count calendar links in both tables
        $queue_count = $conn->query("
            SELECT COUNT(*) as count
            FROM scrape_link_queue 
            WHERE page_url LIKE '%?ical=%' 
               OR page_url LIKE '%?outlook-ical=%'
               OR page_url LIKE '%&ical=%'
               OR page_url LIKE '%&outlook-ical=%'
        ")->fetch_assoc()['count'];
        
        $scraped_count = $conn->query("
            SELECT COUNT(*) as count
            FROM scraped_content 
            WHERE page_url LIKE '%?ical=%' 
               OR page_url LIKE '%?outlook-ical=%'
               OR page_url LIKE '%&ical=%'
               OR page_url LIKE '%&outlook-ical=%'
        ")->fetch_assoc()['count'];
        
        // Get examples
        $examples = [];
        if ($queue_count > 0 || $scraped_count > 0) {
            $examples_query = "
                SELECT page_url, 'queue' as source_table FROM scrape_link_queue 
                WHERE page_url LIKE '%?ical=%' 
                   OR page_url LIKE '%?outlook-ical=%'
                   OR page_url LIKE '%&ical=%'
                   OR page_url LIKE '%&outlook-ical=%'
                LIMIT 5
            ";
            
            if ($scraped_count > 0) {
                $examples_query .= " UNION ALL 
                    SELECT page_url, 'scraped' as source_table FROM scraped_content 
                    WHERE page_url LIKE '%?ical=%' 
                       OR page_url LIKE '%?outlook-ical=%'
                       OR page_url LIKE '%&ical=%'
                       OR page_url LIKE '%&outlook-ical=%'
                    LIMIT 5
                ";
            }
            
            $examples_result = $conn->query($examples_query);
            while ($row = $examples_result->fetch_assoc()) {
                $examples[] = $row;
            }
        }
        
        echo json_encode([
            'success' => true,
            'queue_count' => $queue_count,
            'scraped_count' => $scraped_count,
            'total_count' => $queue_count + $scraped_count,
            'examples' => $examples
        ]);
        
    } elseif ($action === 'cleanup') {
        // Get calendar links from both tables
        $calendar_urls = [];
        
        // From queue
        $queue_result = $conn->query("
            SELECT DISTINCT source_id, page_url
            FROM scrape_link_queue 
            WHERE page_url LIKE '%?ical=%' 
               OR page_url LIKE '%?outlook-ical=%'
               OR page_url LIKE '%&ical=%'
               OR page_url LIKE '%&outlook-ical=%'
        ");
        while ($row = $queue_result->fetch_assoc()) {
            $calendar_urls[] = $row;
        }
        
        // From scraped_content
        $scraped_result = $conn->query("
            SELECT DISTINCT source_id, page_url
            FROM scraped_content 
            WHERE page_url LIKE '%?ical=%' 
               OR page_url LIKE '%?outlook-ical=%'
               OR page_url LIKE '%&ical=%'
               OR page_url LIKE '%&outlook-ical=%'
        ");
        while ($row = $scraped_result->fetch_assoc()) {
            $calendar_urls[] = $row;
        }
        
        // Move to blocked_urls
        $blocked_count = 0;
        $insert_stmt = $conn->prepare("
            INSERT INTO blocked_urls (source_id, page_url, reason, blocked_by, notes)
            VALUES (?, ?, 'malformed', ?, 'Calendar download link (ical/outlook-ical parameter)')
            ON DUPLICATE KEY UPDATE blocked_id = blocked_id
        ");
        
        foreach ($calendar_urls as $url_data) {
            $insert_stmt->bind_param(
                'isi',
                $url_data['source_id'],
                $url_data['page_url'],
                $_SESSION['admin_id']
            );
            $insert_stmt->execute();
            if ($insert_stmt->affected_rows > 0) {
                $blocked_count++;
            }
        }
        $insert_stmt->close();
        
        // Delete from queue
        $queue_deleted = $conn->query("
            DELETE FROM scrape_link_queue 
            WHERE page_url LIKE '%?ical=%' 
               OR page_url LIKE '%?outlook-ical=%'
               OR page_url LIKE '%&ical=%'
               OR page_url LIKE '%&outlook-ical=%'
        ")->affected_rows;
        
        // Delete from scraped_content
        $scraped_deleted = $conn->query("
            DELETE FROM scraped_content 
            WHERE page_url LIKE '%?ical=%' 
               OR page_url LIKE '%?outlook-ical=%'
               OR page_url LIKE '%&ical=%'
               OR page_url LIKE '%&outlook-ical=%'
        ")->affected_rows;
        
        // Log activity
        $log_stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, module, description, ip_address) VALUES (?, 'CLEANUP_CALENDAR_LINKS', 'web_scraper', ?, ?)");
        $desc = "Cleaned up {$blocked_count} calendar download links, deleted {$queue_deleted} from queue and {$scraped_deleted} from scraped_content";
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $log_stmt->bind_param('iss', $_SESSION['admin_id'], $desc, $ip);
        $log_stmt->execute();
        $log_stmt->close();
        
        echo json_encode([
            'success' => true,
            'blocked' => $blocked_count,
            'queue_deleted' => $queue_deleted,
            'scraped_deleted' => $scraped_deleted
        ]);
        
    } else {
        throw new Exception("Unknown action: $action");
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
