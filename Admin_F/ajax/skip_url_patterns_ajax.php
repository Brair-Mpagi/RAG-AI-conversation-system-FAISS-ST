<?php
/**
 * AJAX endpoint for managing skip URL patterns
 * Allows blocking entire URL patterns from being scanned
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
    // Create table if not exists
    $conn->query("
        CREATE TABLE IF NOT EXISTS skip_url_patterns (
            pattern_id INT AUTO_INCREMENT PRIMARY KEY,
            source_id INT NOT NULL,
            url_pattern VARCHAR(500) NOT NULL,
            pattern_type ENUM('contains', 'starts_with', 'regex') DEFAULT 'contains',
            notes TEXT,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_source_pattern (source_id, url_pattern),
            KEY idx_source (source_id),
            FOREIGN KEY (source_id) REFERENCES scraping_sources(source_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    if ($action === 'list') {
        // List all skip patterns
        $source_id = isset($_GET['source_id']) ? (int)$_GET['source_id'] : 0;
        
        $where = $source_id > 0 ? "WHERE sp.source_id = {$source_id}" : '';
        
        $query = "
            SELECT 
                sp.pattern_id,
                sp.source_id,
                sp.url_pattern,
                sp.pattern_type,
                sp.notes,
                sp.created_at,
                ss.source_name,
                a.username as created_by_username
            FROM skip_url_patterns sp
            LEFT JOIN scraping_sources ss ON sp.source_id = ss.source_id
            LEFT JOIN admins a ON sp.created_by = a.admin_id
            $where
            ORDER BY sp.created_at DESC
        ";
        
        $result = $conn->query($query);
        $patterns = [];
        while ($row = $result->fetch_assoc()) {
            $patterns[] = $row;
        }
        
        echo json_encode([
            'success' => true,
            'patterns' => $patterns
        ]);
        
    } elseif ($action === 'add') {
        // Add new skip pattern
        $source_id = isset($_POST['source_id']) ? (int)$_POST['source_id'] : 0;
        $url_pattern = $_POST['url_pattern'] ?? '';
        $pattern_type = $_POST['pattern_type'] ?? 'contains';
        $notes = $_POST['notes'] ?? '';
        
        if (!$source_id || !$url_pattern) {
            throw new Exception('source_id and url_pattern required');
        }
        
        $stmt = $conn->prepare("
            INSERT INTO skip_url_patterns (source_id, url_pattern, pattern_type, notes, created_by)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                pattern_type = VALUES(pattern_type),
                notes = VALUES(notes)
        ");
        $stmt->bind_param('isssi', $source_id, $url_pattern, $pattern_type, $notes, $_SESSION['admin_id']);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        
        // Log activity
        $log_stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, module, description, ip_address) VALUES (?, 'ADD_SKIP_PATTERN', 'web_scraper', ?, ?)");
        $desc = "Added skip pattern: {$url_pattern} for source {$source_id}";
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $log_stmt->bind_param('iss', $_SESSION['admin_id'], $desc, $ip);
        $log_stmt->execute();
        $log_stmt->close();
        
        echo json_encode([
            'success' => true,
            'added' => $affected > 0
        ]);
        
    } elseif ($action === 'delete') {
        // Delete skip pattern
        $pattern_id = isset($_POST['pattern_id']) ? (int)$_POST['pattern_id'] : 0;
        
        if (!$pattern_id) {
            throw new Exception('pattern_id required');
        }
        
        $stmt = $conn->prepare("DELETE FROM skip_url_patterns WHERE pattern_id = ?");
        $stmt->bind_param('i', $pattern_id);
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();
        
        // Log activity
        $log_stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, module, description, ip_address) VALUES (?, 'DELETE_SKIP_PATTERN', 'web_scraper', ?, ?)");
        $desc = "Deleted skip pattern ID: {$pattern_id}";
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $log_stmt->bind_param('iss', $_SESSION['admin_id'], $desc, $ip);
        $log_stmt->execute();
        $log_stmt->close();
        
        echo json_encode([
            'success' => true,
            'deleted' => $deleted
        ]);
        
    } elseif ($action === 'cleanup_existing') {
        // Remove URLs matching skip patterns from queue
        $source_id = isset($_POST['source_id']) ? (int)$_POST['source_id'] : 0;
        
        if (!$source_id) {
            throw new Exception('source_id required');
        }
        
        // Get all patterns for this source
        $patterns_result = $conn->query("
            SELECT url_pattern, pattern_type 
            FROM skip_url_patterns 
            WHERE source_id = {$source_id}
        ");
        
        $total_removed = 0;
        
        while ($pattern = $patterns_result->fetch_assoc()) {
            $url_pattern = $pattern['url_pattern'];
            $pattern_type = $pattern['pattern_type'];
            
            if ($pattern_type === 'contains') {
                $where = "page_url LIKE ?";
                $param = "%{$url_pattern}%";
            } elseif ($pattern_type === 'starts_with') {
                $where = "page_url LIKE ?";
                $param = "{$url_pattern}%";
            } else { // regex
                $where = "page_url REGEXP ?";
                $param = $url_pattern;
            }
            
            $stmt = $conn->prepare("
                DELETE FROM scrape_link_queue 
                WHERE source_id = ? AND $where
            ");
            $stmt->bind_param('is', $source_id, $param);
            $stmt->execute();
            $total_removed += $stmt->affected_rows;
            $stmt->close();
        }
        
        echo json_encode([
            'success' => true,
            'removed' => $total_removed
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
