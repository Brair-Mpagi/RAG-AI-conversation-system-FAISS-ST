<?php
/**
 * AJAX endpoint for managing blocked URLs
 * Actions: list, unblock, bulk_block, stats, export, import
 */

session_start();
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    if ($action === 'list') {
        // List blocked URLs with filters
        $source_id = isset($_GET['source_id']) ? (int)$_GET['source_id'] : 0;
        $reason = $_GET['reason'] ?? '';
        $search = $_GET['search'] ?? '';
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = 50;
        $offset = ($page - 1) * $limit;
        
        $where = [];
        $params = [];
        $types = '';
        
        if ($source_id > 0) {
            $where[] = "bu.source_id = ?";
            $params[] = $source_id;
            $types .= 'i';
        }
        
        if ($reason) {
            $where[] = "bu.reason = ?";
            $params[] = $reason;
            $types .= 's';
        }
        
        if ($search) {
            $where[] = "bu.page_url LIKE ?";
            $params[] = "%{$search}%";
            $types .= 's';
        }
        
        $where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        
        // Get total count
        $count_query = "SELECT COUNT(*) as total FROM blocked_urls bu $where_clause";
        if ($params) {
            $stmt = $conn->prepare($count_query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $total = $stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();
        } else {
            $total = $conn->query($count_query)->fetch_assoc()['total'];
        }
        
        // Get blocked URLs
        $query = "
            SELECT 
                bu.blocked_id,
                bu.source_id,
                bu.page_url,
                bu.reason,
                bu.blocked_at,
                bu.blocked_by,
                bu.original_scraped_id,
                bu.notes,
                ss.source_name,
                a.username as blocked_by_username
            FROM blocked_urls bu
            LEFT JOIN scraping_sources ss ON bu.source_id = ss.source_id
            LEFT JOIN admins a ON bu.blocked_by = a.admin_id
            $where_clause
            ORDER BY bu.blocked_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $blocked_urls = [];
        while ($row = $result->fetch_assoc()) {
            $blocked_urls[] = $row;
        }
        $stmt->close();
        
        echo json_encode([
            'success' => true,
            'blocked_urls' => $blocked_urls,
            'total' => $total,
            'page' => $page,
            'pages' => ceil($total / $limit)
        ]);
        
    } elseif ($action === 'unblock') {
        // Unblock specific URLs
        $blocked_ids = $_POST['blocked_ids'] ?? '';
        if (!$blocked_ids) {
            throw new Exception('No blocked_ids provided');
        }
        
        $ids = array_map('intval', explode(',', $blocked_ids));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        
        $stmt = $conn->prepare("DELETE FROM blocked_urls WHERE blocked_id IN ($placeholders)");
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();
        
        // Log activity
        $log_stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, module, description, ip_address) VALUES (?, 'UNBLOCK_URLS', 'web_scraper', ?, ?)");
        $desc = "Unblocked {$deleted} URLs";
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $log_stmt->bind_param('iss', $_SESSION['admin_id'], $desc, $ip);
        $log_stmt->execute();
        $log_stmt->close();
        
        echo json_encode([
            'success' => true,
            'unblocked' => $deleted
        ]);
        
    } elseif ($action === 'bulk_block') {
        // Bulk block URLs by pattern or list
        $source_id = isset($_POST['source_id']) ? (int)$_POST['source_id'] : 0;
        $urls = $_POST['urls'] ?? '';
        $pattern = $_POST['pattern'] ?? '';
        $reason = $_POST['reason'] ?? 'manual';
        $notes = $_POST['notes'] ?? '';
        
        if (!$source_id) {
            throw new Exception('source_id required');
        }
        
        $blocked_count = 0;
        
        if ($urls) {
            // Block specific URLs (one per line)
            $url_list = array_filter(array_map('trim', explode("\n", $urls)));
            
            $insert_stmt = $conn->prepare("
                INSERT INTO blocked_urls (source_id, page_url, reason, blocked_by, notes)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE blocked_id = blocked_id
            ");
            
            foreach ($url_list as $url) {
                $insert_stmt->bind_param('issis', $source_id, $url, $reason, $_SESSION['admin_id'], $notes);
                $insert_stmt->execute();
                if ($insert_stmt->affected_rows > 0) {
                    $blocked_count++;
                }
            }
            $insert_stmt->close();
            
        } elseif ($pattern) {
            // Block URLs matching pattern from queue or scraped_content
            $tables = ['scrape_link_queue', 'scraped_content'];
            $url_field = ['scrape_link_queue' => 'page_url', 'scraped_content' => 'page_url'];
            
            foreach ($tables as $table) {
                $field = $url_field[$table];
                $select_query = "SELECT DISTINCT source_id, $field as page_url FROM $table WHERE source_id = ? AND $field REGEXP ?";
                $stmt = $conn->prepare($select_query);
                $stmt->bind_param('is', $source_id, $pattern);
                $stmt->execute();
                $result = $stmt->get_result();
                
                $insert_stmt = $conn->prepare("
                    INSERT INTO blocked_urls (source_id, page_url, reason, blocked_by, notes)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE blocked_id = blocked_id
                ");
                
                while ($row = $result->fetch_assoc()) {
                    $pattern_notes = "Blocked by pattern: {$pattern}. " . $notes;
                    $insert_stmt->bind_param('issis', $row['source_id'], $row['page_url'], $reason, $_SESSION['admin_id'], $pattern_notes);
                    $insert_stmt->execute();
                    if ($insert_stmt->affected_rows > 0) {
                        $blocked_count++;
                    }
                }
                
                $insert_stmt->close();
                $stmt->close();
            }
        }
        
        // Log activity
        $log_stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, module, description, ip_address) VALUES (?, 'BULK_BLOCK_URLS', 'web_scraper', ?, ?)");
        $desc = "Bulk blocked {$blocked_count} URLs";
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $log_stmt->bind_param('iss', $_SESSION['admin_id'], $desc, $ip);
        $log_stmt->execute();
        $log_stmt->close();
        
        echo json_encode([
            'success' => true,
            'blocked' => $blocked_count
        ]);
        
    } elseif ($action === 'stats') {
        // Get statistics
        $source_id = isset($_GET['source_id']) ? (int)$_GET['source_id'] : 0;
        $where = $source_id > 0 ? "WHERE source_id = {$source_id}" : '';
        
        // Total blocked
        $total = $conn->query("SELECT COUNT(*) as count FROM blocked_urls $where")->fetch_assoc()['count'];
        
        // By reason
        $by_reason = [];
        $reason_result = $conn->query("SELECT reason, COUNT(*) as count FROM blocked_urls $where GROUP BY reason");
        while ($row = $reason_result->fetch_assoc()) {
            $by_reason[$row['reason']] = (int)$row['count'];
        }
        
        // By source
        $by_source = [];
        $source_result = $conn->query("
            SELECT ss.source_name, COUNT(*) as count 
            FROM blocked_urls bu
            LEFT JOIN scraping_sources ss ON bu.source_id = ss.source_id
            $where
            GROUP BY bu.source_id
        ");
        while ($row = $source_result->fetch_assoc()) {
            $by_source[$row['source_name']] = (int)$row['count'];
        }
        
        // Recent blocks (last 7 days)
        $recent = $conn->query("
            SELECT DATE(blocked_at) as date, COUNT(*) as count 
            FROM blocked_urls 
            $where
            WHERE blocked_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY DATE(blocked_at)
            ORDER BY date DESC
        ")->fetch_all(MYSQLI_ASSOC);
        
        // Top blockers
        $top_blockers = [];
        $blocker_result = $conn->query("
            SELECT a.username, COUNT(*) as count 
            FROM blocked_urls bu
            LEFT JOIN admins a ON bu.blocked_by = a.admin_id
            $where
            GROUP BY bu.blocked_by
            ORDER BY count DESC
            LIMIT 5
        ");
        while ($row = $blocker_result->fetch_assoc()) {
            $top_blockers[] = $row;
        }
        
        echo json_encode([
            'success' => true,
            'total' => $total,
            'by_reason' => $by_reason,
            'by_source' => $by_source,
            'recent_blocks' => $recent,
            'top_blockers' => $top_blockers
        ]);
        
    } elseif ($action === 'export') {
        // Export blocked URLs as CSV
        $source_id = isset($_GET['source_id']) ? (int)$_GET['source_id'] : 0;
        $where = $source_id > 0 ? "WHERE bu.source_id = {$source_id}" : '';
        
        $query = "
            SELECT 
                bu.page_url,
                bu.reason,
                bu.blocked_at,
                ss.source_name,
                bu.notes
            FROM blocked_urls bu
            LEFT JOIN scraping_sources ss ON bu.source_id = ss.source_id
            $where
            ORDER BY bu.blocked_at DESC
        ";
        
        $result = $conn->query($query);
        
        $csv = "URL,Reason,Blocked At,Source,Notes\n";
        while ($row = $result->fetch_assoc()) {
            $csv .= '"' . str_replace('"', '""', $row['page_url']) . '",';
            $csv .= '"' . str_replace('"', '""', $row['reason']) . '",';
            $csv .= '"' . str_replace('"', '""', $row['blocked_at']) . '",';
            $csv .= '"' . str_replace('"', '""', $row['source_name'] ?? '') . '",';
            $csv .= '"' . str_replace('"', '""', $row['notes'] ?? '') . '"' . "\n";
        }
        
        echo json_encode([
            'success' => true,
            'csv' => $csv,
            'filename' => 'blocked_urls_' . date('Y-m-d') . '.csv'
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
