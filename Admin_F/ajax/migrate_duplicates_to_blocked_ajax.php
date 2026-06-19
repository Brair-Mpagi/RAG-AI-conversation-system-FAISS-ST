<?php
/**
 * AJAX endpoint for migrating duplicate pages to blocked_urls table
 * This is a one-time migration after blocked_urls table is created
 */

session_start();
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'check';

try {
    if ($action === 'check') {
        // Check how many duplicates exist in scraped_content
        $scraped_result = $conn->query("SELECT COUNT(*) as count FROM scraped_content WHERE status = 'duplicate'");
        $scraped_count = $scraped_result->fetch_assoc()['count'];
        
        // Check how many are already in blocked_urls
        $blocked_result = $conn->query("SELECT COUNT(*) as count FROM blocked_urls WHERE reason = 'duplicate'");
        $blocked_count = $blocked_result->fetch_assoc()['count'];
        
        // Get examples
        $examples = [];
        if ($scraped_count > 0) {
            $examples_result = $conn->query("
                SELECT page_url, page_title, scraped_at 
                FROM scraped_content 
                WHERE status = 'duplicate' 
                ORDER BY scraped_at DESC 
                LIMIT 5
            ");
            while ($row = $examples_result->fetch_assoc()) {
                $examples[] = $row;
            }
        }
        
        echo json_encode([
            'success' => true,
            'duplicates_in_scraped' => $scraped_count,
            'duplicates_in_blocked' => $blocked_count,
            'examples' => $examples
        ]);
        
    } elseif ($action === 'migrate') {
        // Get all duplicates from scraped_content
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
        
        // Delete from scraped_content
        $delete_result = $conn->query("DELETE FROM scraped_content WHERE status = 'duplicate'");
        $deleted_count = $conn->affected_rows;
        
        // Also remove from queue if present
        $conn->query("
            DELETE slq FROM scrape_link_queue slq
            INNER JOIN blocked_urls bu ON slq.page_url = bu.page_url AND slq.source_id = bu.source_id
            WHERE bu.reason = 'duplicate'
        ");
        $queue_deleted = $conn->affected_rows;
        
        // Get new counts
        $new_scraped = $conn->query("SELECT COUNT(*) as count FROM scraped_content")->fetch_assoc()['count'];
        $new_blocked = $conn->query("SELECT COUNT(*) as count FROM blocked_urls")->fetch_assoc()['count'];
        
        // Log activity
        $log_stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, module, description, ip_address) VALUES (?, 'MIGRATE_DUPLICATES', 'web_scraper', ?, ?)");
        $desc = "Migrated {$migrated_count} duplicate pages to blocked_urls, deleted {$deleted_count} from scraped_content";
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $log_stmt->bind_param('iss', $_SESSION['admin_id'], $desc, $ip);
        $log_stmt->execute();
        $log_stmt->close();
        
        echo json_encode([
            'success' => true,
            'migrated_to_blocked' => $migrated_count,
            'deleted_from_scraped' => $deleted_count,
            'deleted_from_queue' => $queue_deleted,
            'new_scraped_total' => $new_scraped,
            'new_blocked_total' => $new_blocked
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
