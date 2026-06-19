<?php
/**
 * AJAX endpoint for cleaning up malformed URLs from Missing Links Queue
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
        // Count malformed URLs
        $count_query = "
            SELECT COUNT(*) as count
            FROM scrape_link_queue 
            WHERE page_url LIKE '%?p=%?p=%' 
               OR page_url LIKE '%?p=%/%?p=%'
               OR page_url LIKE '%2F%3Fp%3D%'
               OR page_url REGEXP '\\\\?p=[^&]*\\\\?p='
               OR page_url REGEXP '/([^/]+)/([^/?]+)/\\\\1/\\\\2'
               OR page_url REGEXP '/[^/]+/[^/?]+\\\\?p=[0-9]+'
               OR page_url REGEXP '/[0-9]{4}/[0-9]{2}(/[0-9]{2})?\\\\?p=[0-9]+'
        ";
        
        $result = $conn->query($count_query);
        $malformed_count = $result->fetch_assoc()['count'];
        
        // Get total count
        $total_result = $conn->query("SELECT COUNT(*) as count FROM scrape_link_queue");
        $total_count = $total_result->fetch_assoc()['count'];
        
        // Get examples
        $examples = [];
        if ($malformed_count > 0) {
            $examples_query = "
                SELECT page_url
                FROM scrape_link_queue 
                WHERE page_url LIKE '%?p=%?p=%' 
                   OR page_url LIKE '%?p=%/%?p=%'
                   OR page_url LIKE '%2F%3Fp%3D%'
                   OR page_url REGEXP '\\\\?p=[^&]*\\\\?p='
                   OR page_url REGEXP '/([^/]+)/([^/?]+)/\\\\1/\\\\2'
                   OR page_url REGEXP '/[^/]+/[^/?]+\\\\?p=[0-9]+'
                   OR page_url REGEXP '/[0-9]{4}/[0-9]{2}(/[0-9]{2})?\\\\?p=[0-9]+'
                ORDER BY discovered_at DESC
                LIMIT 5
            ";
            
            $examples_result = $conn->query($examples_query);
            while ($row = $examples_result->fetch_assoc()) {
                $examples[] = $row['page_url'];
            }
        }
        
        echo json_encode([
            'success' => true,
            'malformed_count' => $malformed_count,
            'total_count' => $total_count,
            'examples' => $examples
        ]);
        
    } elseif ($action === 'delete') {
        // Get malformed URLs before deleting
        $select_query = "
            SELECT DISTINCT source_id, page_url
            FROM scrape_link_queue 
            WHERE page_url LIKE '%?p=%?p=%' 
               OR page_url LIKE '%?p=%/%?p=%'
               OR page_url LIKE '%2F%3Fp%3D%'
               OR page_url REGEXP '\\\\?p=[^&]*\\\\?p='
               OR page_url REGEXP '/([^/]+)/([^/?]+)/\\\\1/\\\\2'
               OR page_url REGEXP '/[^/]+/[^/?]+\\\\?p=[0-9]+'
               OR page_url REGEXP '/[0-9]{4}/[0-9]{2}(/[0-9]{2})?\\\\?p=[0-9]+'
        ";
        
        $result = $conn->query($select_query);
        $malformed_urls = [];
        while ($row = $result->fetch_assoc()) {
            $malformed_urls[] = $row;
        }
        
        // Move to blocked_urls
        $moved_count = 0;
        $insert_stmt = $conn->prepare("
            INSERT INTO blocked_urls (source_id, page_url, reason, blocked_by, notes)
            VALUES (?, ?, 'malformed', ?, 'Malformed URL pattern detected and blocked')
            ON DUPLICATE KEY UPDATE 
                reason = 'malformed',
                notes = 'Malformed URL pattern detected and blocked'
        ");
        
        foreach ($malformed_urls as $url_data) {
            $insert_stmt->bind_param(
                'isi',
                $url_data['source_id'],
                $url_data['page_url'],
                $_SESSION['admin_id']
            );
            $insert_stmt->execute();
            $moved_count++;
        }
        $insert_stmt->close();
        
        // Delete from queue
        $delete_query = "
            DELETE FROM scrape_link_queue 
            WHERE page_url LIKE '%?p=%?p=%' 
               OR page_url LIKE '%?p=%/%?p=%'
               OR page_url LIKE '%2F%3Fp%3D%'
               OR page_url REGEXP '\\\\?p=[^&]*\\\\?p='
               OR page_url REGEXP '/([^/]+)/([^/?]+)/\\\\1/\\\\2'
               OR page_url REGEXP '/[^/]+/[^/?]+\\\\?p=[0-9]+'
               OR page_url REGEXP '/[0-9]{4}/[0-9]{2}(/[0-9]{2})?\\\\?p=[0-9]+'
        ";
        
        $conn->query($delete_query);
        $deleted = $conn->affected_rows;
        
        // Get new counts
        $new_total = $conn->query("SELECT COUNT(*) as count FROM scrape_link_queue")->fetch_assoc()['count'];
        $new_pending = $conn->query("SELECT COUNT(*) as count FROM scrape_link_queue WHERE status='pending'")->fetch_assoc()['count'];
        
        // Log activity
        $log_stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, module, description, ip_address) VALUES (?, 'CLEANUP_MALFORMED', 'web_scraper', ?, ?)");
        $desc = "Moved {$moved_count} malformed URLs to blocked_urls and deleted from queue";
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $log_stmt->bind_param('iss', $_SESSION['admin_id'], $desc, $ip);
        $log_stmt->execute();
        $log_stmt->close();
        
        echo json_encode([
            'success' => true,
            'moved_to_blocked' => $moved_count,
            'deleted_from_queue' => $deleted,
            'new_total' => $new_total,
            'new_pending' => $new_pending
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
