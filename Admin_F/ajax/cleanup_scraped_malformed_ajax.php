<?php
/**
 * AJAX endpoint for cleaning up malformed URLs from scraped_content table
 * Removes pages with ?p= parameters on non-root paths (these have wrong content due to redirects)
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
        // Count malformed URLs in scraped_content
        // These are URLs with ?p= parameter on non-root paths (cause redirects and wrong content)
        $count_query = "
            SELECT COUNT(*) as count
            FROM scraped_content 
            WHERE (
                -- URLs with ?p= on paths (not root)
                page_url LIKE '%/%?p=%'
                AND page_url NOT LIKE '%://%.%/?p=%'
            )
            OR (
                -- Recursive encoding patterns
                page_url LIKE '%?p=%?p=%' 
                OR page_url LIKE '%?p=%/%?p=%'
                OR page_url LIKE '%2F%3Fp%3D%'
            )
            OR (
                -- Duplicate path segments
                page_url REGEXP '/([^/]+)/([^/?]+)/\\\\1/\\\\2'
            )
        ";
        
        $result = $conn->query($count_query);
        $malformed_count = $result->fetch_assoc()['count'];
        
        // Get total count
        $total_result = $conn->query("SELECT COUNT(*) as count FROM scraped_content");
        $total_count = $total_result->fetch_assoc()['count'];
        
        // Get examples
        $examples = [];
        if ($malformed_count > 0) {
            $examples_query = "
                SELECT page_url, page_title, scraped_at
                FROM scraped_content 
                WHERE (
                    page_url LIKE '%/%?p=%'
                    AND page_url NOT LIKE '%://%.%/?p=%'
                )
                OR (
                    page_url LIKE '%?p=%?p=%' 
                    OR page_url LIKE '%?p=%/%?p=%'
                    OR page_url LIKE '%2F%3Fp%3D%'
                )
                OR (
                    page_url REGEXP '/([^/]+)/([^/?]+)/\\\\1/\\\\2'
                )
                ORDER BY scraped_at DESC
                LIMIT 10
            ";
            
            $examples_result = $conn->query($examples_query);
            while ($row = $examples_result->fetch_assoc()) {
                $examples[] = [
                    'url' => $row['page_url'],
                    'title' => $row['page_title'],
                    'scraped_at' => $row['scraped_at']
                ];
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
            FROM scraped_content 
            WHERE (
                -- URLs with ?p= on paths (not root)
                page_url LIKE '%/%?p=%'
                AND page_url NOT LIKE '%://%.%/?p=%'
            )
            OR (
                -- Recursive encoding patterns
                page_url LIKE '%?p=%?p=%' 
                OR page_url LIKE '%?p=%/%?p=%'
                OR page_url LIKE '%2F%3Fp%3D%'
            )
            OR (
                -- Duplicate path segments
                page_url REGEXP '/([^/]+)/([^/?]+)/\\\\1/\\\\2'
            )
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
            VALUES (?, ?, 'malformed', ?, 'Malformed URL with ?p= on path (causes redirect to wrong content)')
            ON DUPLICATE KEY UPDATE 
                reason = 'malformed',
                notes = 'Malformed URL with ?p= on path (causes redirect to wrong content)'
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
        
        // Delete from scraped_content
        $delete_query = "
            DELETE FROM scraped_content 
            WHERE (
                -- URLs with ?p= on paths (not root)
                page_url LIKE '%/%?p=%'
                AND page_url NOT LIKE '%://%.%/?p=%'
            )
            OR (
                -- Recursive encoding patterns
                page_url LIKE '%?p=%?p=%' 
                OR page_url LIKE '%?p=%/%?p=%'
                OR page_url LIKE '%2F%3Fp%3D%'
            )
            OR (
                -- Duplicate path segments
                page_url REGEXP '/([^/]+)/([^/?]+)/\\\\1/\\\\2'
            )
        ";
        
        $conn->query($delete_query);
        $deleted = $conn->affected_rows;
        
        // Get new counts
        $new_total = $conn->query("SELECT COUNT(*) as count FROM scraped_content")->fetch_assoc()['count'];
        
        // Get status breakdown
        $status_query = "
            SELECT status, COUNT(*) as count 
            FROM scraped_content 
            GROUP BY status
        ";
        $status_result = $conn->query($status_query);
        $status_breakdown = [];
        while ($row = $status_result->fetch_assoc()) {
            $status_breakdown[$row['status']] = (int)$row['count'];
        }
        
        // Log activity
        $log_stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, module, description, ip_address) VALUES (?, 'CLEANUP_SCRAPED_MALFORMED', 'web_scraper', ?, ?)");
        $desc = "Moved {$moved_count} malformed URLs to blocked_urls and deleted from scraped_content";
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $log_stmt->bind_param('iss', $_SESSION['admin_id'], $desc, $ip);
        $log_stmt->execute();
        $log_stmt->close();
        
        echo json_encode([
            'success' => true,
            'moved_to_blocked' => $moved_count,
            'deleted_from_scraped' => $deleted,
            'new_total' => $new_total,
            'status_breakdown' => $status_breakdown
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
