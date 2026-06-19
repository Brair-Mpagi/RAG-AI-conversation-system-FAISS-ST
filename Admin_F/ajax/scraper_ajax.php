<?php
/**
 * ajax/scraper_ajax.php — JSON endpoint for live scraper views
 *
 * Actions:
 *   link_tree     — hierarchy tree of scraped pages for a source
 *   link_queue    — pending/done queue items for a source
 *   queue_count   — badge counts per source
 *   check_url     — check if a URL already exists under a source
 */
session_start();
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action    = $_GET['action'] ?? $_POST['action'] ?? '';
$source_id = (int)($_GET['source_id'] ?? $_POST['source_id'] ?? 0);

try {
    switch ($action) {

        // ── page hierarchy tree ───────────────────────────────────────────────
        case 'link_tree':
            if (!$source_id) throw new Exception('source_id required');

            $rows = [];
            $res = $conn->prepare("
                SELECT scraped_id, page_url, page_title, status, scraped_at,
                       parent_url, crawl_depth, page_category
                FROM scraped_content
                WHERE source_id = ?
                ORDER BY crawl_depth ASC, scraped_at DESC
            ");
            $res->bind_param('i', $source_id);
            $res->execute();
            $result = $res->get_result();
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $res->close();

            // Build tree grouped by depth
            $by_depth = [];
            $url_map  = [];
            foreach ($rows as $r) {
                $d = (int)($r['crawl_depth'] ?? 0);
                $display_depth = $d <= 2 ? $d : 3;  // L3+ bucket
                $by_depth[$display_depth][] = $r;
                $url_map[$r['page_url']] = $r;
            }
            ksort($by_depth);
            echo json_encode(['success' => true, 'by_depth' => $by_depth, 'total' => count($rows)]);
            break;

        // ── queue items ───────────────────────────────────────────────────────
        case 'link_queue':
            $status_filter = $_GET['status'] ?? '';
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $per_page = 100;
            $offset = ($page - 1) * $per_page;

            $where  = '1=1';
            $types  = '';
            $params = [];
            if ($source_id) {
                $where   .= ' AND source_id = ?';
                $types   .= 'i';
                $params[] = $source_id;
            }
            if ($status_filter !== '') {
                $where   .= ' AND status = ?';
                $types   .= 's';
                $params[] = $status_filter;
            }

            // Get total count
            $count_sql = "SELECT COUNT(*) as total FROM scrape_link_queue WHERE $where";
            if ($types) {
                $count_stmt = $conn->prepare($count_sql);
                $count_stmt->bind_param($types, ...$params);
                $count_stmt->execute();
                $total = $count_stmt->get_result()->fetch_assoc()['total'];
                $count_stmt->close();
            } else {
                $total = $conn->query($count_sql)->fetch_assoc()['total'];
            }

            $sql = "
                SELECT queue_id, source_id, page_url, discovered_from_url, crawl_depth,
                       status, discovered_at, processed_at
                FROM scrape_link_queue
                WHERE $where
                ORDER BY crawl_depth ASC, discovered_at DESC
                LIMIT ? OFFSET ?
            ";
            
            $types .= 'ii';
            $params[] = $per_page;
            $params[] = $offset;
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $items = [];
            while ($row = $result->fetch_assoc()) {
                $items[] = $row;
            }
            $stmt->close();
            
            echo json_encode([
                'success' => true, 
                'items' => $items,
                'total' => $total,
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => ceil($total / $per_page)
            ]);
            break;


        // ── badge counts ──────────────────────────────────────────────────────
        case 'queue_count':
            $where = $source_id ? 'WHERE source_id = ?' : '';
            $types = $source_id ? 'i' : '';
            $params = $source_id ? [$source_id] : [];

            $stmt = $conn->prepare("
                SELECT source_id, status, COUNT(*) as cnt
                FROM scrape_link_queue
                $where
                GROUP BY source_id, status
            ");
            if ($source_id) $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $counts = [];
            while ($row = $result->fetch_assoc()) {
                $sid = $row['source_id'];
                $counts[$sid][$row['status']] = (int)$row['cnt'];
            }
            $stmt->close();
            echo json_encode(['success' => true, 'counts' => $counts]);
            break;

        // ── check if URL already exists under a source ────────────────────────
        case 'check_url':
            $url = trim($_GET['url'] ?? $_POST['url'] ?? '');
            if (!$source_id || !$url) throw new Exception('source_id and url required');

            $stmt = $conn->prepare("
                SELECT scraped_id, page_title, status, scraped_at
                FROM scraped_content
                WHERE source_id = ? AND page_url = ?
                LIMIT 1
            ");
            $stmt->bind_param('is', $source_id, $url);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            // Also check queue
            $stmt2 = $conn->prepare("
                SELECT queue_id, status FROM scrape_link_queue
                WHERE source_id = ? AND page_url = ? LIMIT 1
            ");
            $stmt2->bind_param('is', $source_id, $url);
            $stmt2->execute();
            $queued = $stmt2->get_result()->fetch_assoc();
            $stmt2->close();

            echo json_encode([
                'success'  => true,
                'exists'   => $row ? true : false,
                'queued'   => $queued ? true : false,
                'existing' => $row,
                'queue_entry' => $queued,
            ]);
            break;

        // ── clear queue (delete all items for a source) ───────────────────────
        case 'clear_queue':
            if (!$source_id) throw new Exception('source_id required');
            
            // Delete all queue items for this source
            $stmt = $conn->prepare("DELETE FROM scrape_link_queue WHERE source_id = ?");
            $stmt->bind_param('i', $source_id);
            $stmt->execute();
            $deleted = $stmt->affected_rows;
            $stmt->close();
            
            // Log activity
            $log_stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, module, description, ip_address) VALUES (?, 'CLEAR_QUEUE', 'web_scraper', ?, ?)");
            $desc = "Cleared queue for source $source_id: $deleted items deleted";
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $log_stmt->bind_param('iss', $_SESSION['admin_id'], $desc, $ip);
            $log_stmt->execute();
            $log_stmt->close();
            
            echo json_encode([
                'success' => true,
                'deleted' => $deleted,
                'message' => "Deleted $deleted items from queue"
            ]);
            break;

        default:
            throw new Exception("Unknown action: $action");
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
