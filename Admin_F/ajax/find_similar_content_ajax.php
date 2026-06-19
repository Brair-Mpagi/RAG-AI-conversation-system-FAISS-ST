<?php
/**
 * AJAX endpoint for finding pages with similar content
 * Uses content_hash to identify duplicate or near-duplicate content
 */

session_start();
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'find';
$source_id = isset($_GET['source_id']) ? (int)$_GET['source_id'] : 0;

try {
    if ($action === 'find') {
        // Find pages with duplicate content_hash
        $query = "
            SELECT 
                content_hash,
                COUNT(*) as duplicate_count,
                GROUP_CONCAT(scraped_id ORDER BY scraped_at DESC SEPARATOR ',') as scraped_ids,
                MIN(scraped_at) as first_scraped,
                MAX(scraped_at) as last_scraped
            FROM scraped_content
            WHERE content_hash IS NOT NULL 
                AND content_hash != ''
        ";
        
        if ($source_id > 0) {
            $query .= " AND source_id = " . $source_id;
        }
        
        $query .= "
            GROUP BY content_hash
            HAVING duplicate_count > 1
            ORDER BY duplicate_count DESC, last_scraped DESC
            LIMIT 100
        ";
        
        $result = $conn->query($query);
        $duplicates = [];
        
        while ($row = $result->fetch_assoc()) {
            $scraped_ids = explode(',', $row['scraped_ids']);
            
            // Get details for each duplicate page
            $ids_str = implode(',', array_map('intval', $scraped_ids));
            $details_query = "
                SELECT scraped_id, page_url, page_title, scraped_at, status,
                       LENGTH(COALESCE(cleaned_content, raw_content, '')) as content_length
                FROM scraped_content
                WHERE scraped_id IN ($ids_str)
                ORDER BY scraped_at DESC
            ";
            
            $details_result = $conn->query($details_query);
            $pages = [];
            
            while ($page = $details_result->fetch_assoc()) {
                $pages[] = $page;
            }
            
            $duplicates[] = [
                'content_hash' => $row['content_hash'],
                'duplicate_count' => (int)$row['duplicate_count'],
                'first_scraped' => $row['first_scraped'],
                'last_scraped' => $row['last_scraped'],
                'pages' => $pages
            ];
        }
        
        // Get total stats
        $stats_query = "
            SELECT 
                COUNT(DISTINCT content_hash) as unique_content,
                COUNT(*) as total_pages,
                SUM(CASE WHEN content_hash IN (
                    SELECT content_hash FROM scraped_content 
                    WHERE content_hash IS NOT NULL AND content_hash != ''
                    GROUP BY content_hash HAVING COUNT(*) > 1
                ) THEN 1 ELSE 0 END) as duplicate_pages
            FROM scraped_content
            WHERE content_hash IS NOT NULL AND content_hash != ''
        ";
        
        if ($source_id > 0) {
            $stats_query .= " AND source_id = " . $source_id;
        }
        
        $stats_result = $conn->query($stats_query);
        $stats = $stats_result->fetch_assoc();
        
        echo json_encode([
            'success' => true,
            'duplicates' => $duplicates,
            'stats' => [
                'unique_content' => (int)$stats['unique_content'],
                'total_pages' => (int)$stats['total_pages'],
                'duplicate_pages' => (int)$stats['duplicate_pages']
            ]
        ]);
        
    } elseif ($action === 'keep_one') {
        // Keep one page and move others to blocked_urls
        $keep_scraped_id = isset($_POST['keep_scraped_id']) ? (int)$_POST['keep_scraped_id'] : 0;
        $content_hash = $_POST['content_hash'] ?? '';
        
        if (!$keep_scraped_id || !$content_hash) {
            throw new Exception('Missing required parameters');
        }
        
        // Get all pages with same content_hash except the one to keep
        $stmt = $conn->prepare("
            SELECT scraped_id, source_id, page_url 
            FROM scraped_content 
            WHERE content_hash = BINARY ? AND scraped_id != ?
        ");
        $stmt->bind_param('si', $content_hash, $keep_scraped_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $duplicates = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        $moved_count = 0;
        
        // Move each duplicate to blocked_urls
        $insert_stmt = $conn->prepare("
            INSERT INTO blocked_urls (source_id, page_url, reason, blocked_by, original_scraped_id, notes)
            VALUES (?, ?, 'duplicate', ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                reason = 'duplicate',
                original_scraped_id = VALUES(original_scraped_id),
                notes = VALUES(notes)
        ");
        
        foreach ($duplicates as $dup) {
            $notes = "Duplicate of scraped_id {$keep_scraped_id}. Content hash: {$content_hash}";
            $insert_stmt->bind_param(
                'issis',
                $dup['source_id'],
                $dup['page_url'],
                $_SESSION['admin_id'],
                $keep_scraped_id,
                $notes
            );
            $insert_stmt->execute();
            $moved_count++;
        }
        $insert_stmt->close();
        
        // Delete duplicates from scraped_content
        $delete_stmt = $conn->prepare("
            DELETE FROM scraped_content 
            WHERE content_hash = BINARY ? AND scraped_id != ?
        ");
        $delete_stmt->bind_param('si', $content_hash, $keep_scraped_id);
        $delete_stmt->execute();
        $deleted_count = $delete_stmt->affected_rows;
        $delete_stmt->close();
        
        // Also remove from queue if present
        $queue_delete_stmt = $conn->prepare("
            DELETE FROM scrape_link_queue 
            WHERE page_url IN (SELECT page_url FROM blocked_urls WHERE source_id = ?)
        ");
        foreach ($duplicates as $dup) {
            $queue_delete_stmt->bind_param('i', $dup['source_id']);
            $queue_delete_stmt->execute();
        }
        $queue_delete_stmt->close();
        
        // Log activity
        $log_stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, module, description, ip_address) VALUES (?, 'BLOCK_DUPLICATES', 'web_scraper', ?, ?)");
        $desc = "Moved {$moved_count} duplicate pages to blocked_urls, kept scraped_id {$keep_scraped_id}";
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $log_stmt->bind_param('iss', $_SESSION['admin_id'], $desc, $ip);
        $log_stmt->execute();
        $log_stmt->close();
        
        echo json_encode([
            'success' => true,
            'moved_to_blocked' => $moved_count,
            'deleted_from_scraped' => $deleted_count
        ]);
        
    } elseif ($action === 'mark_url_duplicate') {
        // Mark a specific URL as duplicate (manual entry)
        $duplicate_url = trim($_POST['duplicate_url'] ?? '');
        $keep_scraped_id = isset($_POST['keep_scraped_id']) ? (int)$_POST['keep_scraped_id'] : 0;
        
        if (!$duplicate_url) {
            throw new Exception('Duplicate URL is required');
        }
        
        // Get the page to keep (for notes)
        $keep_page = null;
        if ($keep_scraped_id > 0) {
            $keep_stmt = $conn->prepare("SELECT page_url, content_hash FROM scraped_content WHERE scraped_id = ?");
            $keep_stmt->bind_param('i', $keep_scraped_id);
            $keep_stmt->execute();
            $keep_page = $keep_stmt->get_result()->fetch_assoc();
            $keep_stmt->close();
        }
        
        // Find the page to mark as duplicate
        $find_stmt = $conn->prepare("
            SELECT scraped_id, source_id, page_url, content_hash 
            FROM scraped_content 
            WHERE page_url = BINARY ?
            LIMIT 1
        ");
        $find_stmt->bind_param('s', $duplicate_url);
        $find_stmt->execute();
        $duplicate_page = $find_stmt->get_result()->fetch_assoc();
        $find_stmt->close();
        
        if (!$duplicate_page) {
            throw new Exception('URL not found in scraped content');
        }
        
        // Prevent marking the same page
        if ($keep_scraped_id > 0 && $duplicate_page['scraped_id'] == $keep_scraped_id) {
            throw new Exception('Cannot mark the same page as duplicate of itself');
        }
        
        // Move to blocked_urls
        $notes = $keep_scraped_id > 0 
            ? "Manually marked as duplicate of scraped_id {$keep_scraped_id} ({$keep_page['page_url']})"
            : "Manually marked as duplicate";
            
        $insert_stmt = $conn->prepare("
            INSERT INTO blocked_urls (source_id, page_url, reason, blocked_by, original_scraped_id, notes)
            VALUES (?, ?, 'duplicate', ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                reason = 'duplicate',
                original_scraped_id = VALUES(original_scraped_id),
                notes = VALUES(notes)
        ");
        $insert_stmt->bind_param(
            'issis',
            $duplicate_page['source_id'],
            $duplicate_page['page_url'],
            $_SESSION['admin_id'],
            $keep_scraped_id,
            $notes
        );
        $insert_stmt->execute();
        $insert_stmt->close();
        
        // Delete from scraped_content
        $delete_stmt = $conn->prepare("DELETE FROM scraped_content WHERE scraped_id = ?");
        $delete_stmt->bind_param('i', $duplicate_page['scraped_id']);
        $delete_stmt->execute();
        $delete_stmt->close();
        
        // Remove from queue if present
        $queue_delete_stmt = $conn->prepare("DELETE FROM scrape_link_queue WHERE page_url = BINARY ?");
        $queue_delete_stmt->bind_param('s', $duplicate_page['page_url']);
        $queue_delete_stmt->execute();
        $queue_delete_stmt->close();
        
        // Log activity
        $log_stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, module, description, ip_address) VALUES (?, 'MARK_DUPLICATE', 'web_scraper', ?, ?)");
        $desc = "Manually marked {$duplicate_url} as duplicate" . ($keep_scraped_id > 0 ? " of scraped_id {$keep_scraped_id}" : "");
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $log_stmt->bind_param('iss', $_SESSION['admin_id'], $desc, $ip);
        $log_stmt->execute();
        $log_stmt->close();
        
        echo json_encode([
            'success' => true,
            'message' => 'URL marked as duplicate and moved to blocked_urls'
        ]);
        
    } elseif ($action === 'keep_manual_url') {
        // Keep a manually specified URL and mark all others with same content_hash as duplicate
        $keep_url = trim($_POST['keep_url'] ?? '');
        $content_hash = $_POST['content_hash'] ?? '';
        
        if (!$keep_url || !$content_hash) {
            throw new Exception('Keep URL and content hash are required');
        }
        
        // Validate URL format
        if (!filter_var($keep_url, FILTER_VALIDATE_URL)) {
            throw new Exception('Invalid URL format');
        }
        
        // Check if the keep_url exists in scraped_content with this content_hash
        $check_stmt = $conn->prepare("
            SELECT scraped_id, source_id 
            FROM scraped_content 
            WHERE page_url = BINARY ? AND content_hash = BINARY ?
            LIMIT 1
        ");
        $check_stmt->bind_param('ss', $keep_url, $content_hash);
        $check_stmt->execute();
        $keep_page = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();
        
        if (!$keep_page) {
            // Try to find the URL without content_hash check to give better error message
            $url_check = $conn->prepare("SELECT scraped_id, content_hash FROM scraped_content WHERE page_url = BINARY ? LIMIT 1");
            $url_check->bind_param('s', $keep_url);
            $url_check->execute();
            $url_exists = $url_check->get_result()->fetch_assoc();
            $url_check->close();
            
            if (!$url_exists) {
                throw new Exception('The specified URL does not exist in scraped content. Please make sure the URL is correct and has been scraped.');
            } else {
                throw new Exception('The specified URL exists but is not part of this duplicate group (different content hash). Please select a URL from this group.');
            }
        }
        
        $keep_scraped_id = $keep_page['scraped_id'];
        
        // Get all pages with same content_hash except the one to keep
        $stmt = $conn->prepare("
            SELECT scraped_id, source_id, page_url 
            FROM scraped_content 
            WHERE content_hash = BINARY ? AND scraped_id != ?
        ");
        $stmt->bind_param('si', $content_hash, $keep_scraped_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $duplicates = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        $moved_count = 0;
        
        // Move each duplicate to blocked_urls
        $insert_stmt = $conn->prepare("
            INSERT INTO blocked_urls (source_id, page_url, reason, blocked_by, original_scraped_id, notes)
            VALUES (?, ?, 'duplicate', ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                reason = 'duplicate',
                original_scraped_id = VALUES(original_scraped_id),
                notes = VALUES(notes)
        ");
        
        foreach ($duplicates as $dup) {
            $notes = "Duplicate of manually specified URL: {$keep_url} (scraped_id {$keep_scraped_id}). Content hash: {$content_hash}";
            $insert_stmt->bind_param(
                'issis',
                $dup['source_id'],
                $dup['page_url'],
                $_SESSION['admin_id'],
                $keep_scraped_id,
                $notes
            );
            $insert_stmt->execute();
            $moved_count++;
        }
        $insert_stmt->close();
        
        // Delete duplicates from scraped_content
        $delete_stmt = $conn->prepare("
            DELETE FROM scraped_content 
            WHERE content_hash = BINARY ? AND scraped_id != ?
        ");
        $delete_stmt->bind_param('si', $content_hash, $keep_scraped_id);
        $delete_stmt->execute();
        $deleted_count = $delete_stmt->affected_rows;
        $delete_stmt->close();
        
        // Also remove from queue if present
        $queue_delete_stmt = $conn->prepare("
            DELETE FROM scrape_link_queue 
            WHERE page_url IN (SELECT page_url FROM blocked_urls WHERE source_id = ?)
        ");
        foreach ($duplicates as $dup) {
            $queue_delete_stmt->bind_param('i', $dup['source_id']);
            $queue_delete_stmt->execute();
        }
        $queue_delete_stmt->close();
        
        // Log activity
        $log_stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, module, description, ip_address) VALUES (?, 'KEEP_MANUAL_URL', 'web_scraper', ?, ?)");
        $desc = "Kept manually specified URL {$keep_url}, moved {$moved_count} duplicate pages to blocked_urls";
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $log_stmt->bind_param('iss', $_SESSION['admin_id'], $desc, $ip);
        $log_stmt->execute();
        $log_stmt->close();
        
        echo json_encode([
            'success' => true,
            'kept_url' => $keep_url,
            'moved_to_blocked' => $moved_count,
            'deleted_from_scraped' => $deleted_count
        ]);
        
    } elseif ($action === 'keep_latest_all') {
        // Bulk action: Keep the latest (most recent) page from each duplicate group
        $source_id_filter = isset($_POST['source_id']) ? (int)$_POST['source_id'] : 0;
        
        // Find all duplicate groups
        $query = "
            SELECT 
                content_hash,
                COUNT(*) as duplicate_count,
                MAX(scraped_id) as latest_scraped_id
            FROM scraped_content
            WHERE content_hash IS NOT NULL 
                AND content_hash != ''
        ";
        
        if ($source_id_filter > 0) {
            $query .= " AND source_id = " . $source_id_filter;
        }
        
        $query .= "
            GROUP BY content_hash
            HAVING duplicate_count > 1
        ";
        
        $result = $conn->query($query);
        $groups = [];
        
        while ($row = $result->fetch_assoc()) {
            $groups[] = [
                'content_hash' => $row['content_hash'],
                'latest_scraped_id' => (int)$row['latest_scraped_id'],
                'duplicate_count' => (int)$row['duplicate_count']
            ];
        }
        
        if (empty($groups)) {
            echo json_encode([
                'success' => true,
                'groups_processed' => 0,
                'total_moved' => 0,
                'total_deleted' => 0,
                'message' => 'No duplicate groups found'
            ]);
            exit;
        }
        
        $total_moved = 0;
        $total_deleted = 0;
        $groups_processed = 0;
        
        // Process each group
        foreach ($groups as $group) {
            $content_hash = $group['content_hash'];
            $keep_scraped_id = $group['latest_scraped_id'];
            
            // Get all pages with same content_hash except the latest one
            $stmt = $conn->prepare("
                SELECT scraped_id, source_id, page_url 
                FROM scraped_content 
                WHERE content_hash = BINARY ? AND scraped_id != ?
            ");
            $stmt->bind_param('si', $content_hash, $keep_scraped_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $duplicates = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            
            if (empty($duplicates)) {
                continue;
            }
            
            // Move each duplicate to blocked_urls
            $insert_stmt = $conn->prepare("
                INSERT INTO blocked_urls (source_id, page_url, reason, blocked_by, original_scraped_id, notes)
                VALUES (?, ?, 'duplicate', ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    reason = 'duplicate',
                    original_scraped_id = VALUES(original_scraped_id),
                    notes = VALUES(notes)
            ");
            
            foreach ($duplicates as $dup) {
                $notes = "Auto-kept latest (scraped_id {$keep_scraped_id}). Content hash: {$content_hash}";
                $insert_stmt->bind_param(
                    'issis',
                    $dup['source_id'],
                    $dup['page_url'],
                    $_SESSION['admin_id'],
                    $keep_scraped_id,
                    $notes
                );
                $insert_stmt->execute();
                $total_moved++;
            }
            $insert_stmt->close();
            
            // Delete duplicates from scraped_content
            $delete_stmt = $conn->prepare("
                DELETE FROM scraped_content 
                WHERE content_hash = BINARY ? AND scraped_id != ?
            ");
            $delete_stmt->bind_param('si', $content_hash, $keep_scraped_id);
            $delete_stmt->execute();
            $total_deleted += $delete_stmt->affected_rows;
            $delete_stmt->close();
            
            // Remove from queue if present
            $queue_delete_stmt = $conn->prepare("
                DELETE FROM scrape_link_queue 
                WHERE page_url IN (SELECT page_url FROM blocked_urls WHERE source_id = ?)
            ");
            foreach ($duplicates as $dup) {
                $queue_delete_stmt->bind_param('i', $dup['source_id']);
                $queue_delete_stmt->execute();
            }
            $queue_delete_stmt->close();
            
            $groups_processed++;
        }
        
        // Log activity
        $log_stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, module, description, ip_address) VALUES (?, 'KEEP_LATEST_ALL', 'web_scraper', ?, ?)");
        $desc = "Bulk kept latest from {$groups_processed} duplicate groups, moved {$total_moved} pages to blocked_urls";
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $log_stmt->bind_param('iss', $_SESSION['admin_id'], $desc, $ip);
        $log_stmt->execute();
        $log_stmt->close();
        
        echo json_encode([
            'success' => true,
            'groups_processed' => $groups_processed,
            'total_moved' => $total_moved,
            'total_deleted' => $total_deleted,
            'message' => "Processed {$groups_processed} groups, kept latest from each"
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
