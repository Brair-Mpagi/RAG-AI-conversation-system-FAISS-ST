<?php
/**
 * ajax/scrape_missing_ajax.php
 *
 * Endpoint for scraping missing links from the queue.
 * Calls Python web_scraper.py --mode scrape-missing in background
 * and provides status polling.
 *
 * Actions:
 *   start  — kick off scrape-missing, return immediately
 *   status — poll progress by checking queue status
 */

// Catch ALL errors and convert to JSON
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

ob_start();

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

set_exception_handler(function($e) {
    ob_clean();
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ]);
    exit;
});

session_start();
require_once __DIR__ . '/../db.php';

ob_clean();
header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action    = $_POST['action'] ?? $_GET['action'] ?? '';
$source_id = (int)($_POST['source_id'] ?? $_GET['source_id'] ?? 0);

// Status file helpers
function scrape_status_file(int $source_id): string {
    return sys_get_temp_dir() . "/scrape_missing_{$source_id}.json";
}

function write_scrape_status(int $source_id, array $data): void {
    file_put_contents(scrape_status_file($source_id), json_encode($data));
}

function read_scrape_status(int $source_id): array {
    $f = scrape_status_file($source_id);
    if (!file_exists($f)) return ['running' => false, 'finished' => false];
    $d = json_decode(file_get_contents($f), true);
    return is_array($d) ? $d : ['running' => false, 'finished' => false];
}

try {
    if ($action === 'status') {
        if (!$source_id) throw new Exception('source_id required');
        
        // Read status file
        $status = read_scrape_status($source_id);
        
        // Also check actual queue status from DB
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status='scraping' THEN 1 ELSE 0 END) as scraping,
                SUM(CASE WHEN status='done' THEN 1 ELSE 0 END) as done,
                SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed
            FROM scrape_link_queue 
            WHERE source_id = ?
        ");
        $stmt->bind_param('i', $source_id);
        $stmt->execute();
        $counts = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $status['queue'] = $counts;
        
        // If status says running but no 'scraping' items and no 'pending' items, mark as finished
        if ($status['running'] && $counts['scraping'] == 0 && $counts['pending'] == 0) {
            $status['running'] = false;
            $status['finished'] = true;
            write_scrape_status($source_id, $status);
        }
        
        echo json_encode($status);
        exit;
    }
    
    if ($action === 'stop') {
        if (!$source_id) throw new Exception('source_id required');
        
        // Kill all web_scraper.py processes for this source
        exec("pkill -9 -f 'web_scraper.py.*--source-id {$source_id}'");
        
        // Clear status file
        $status_file = scrape_status_file($source_id);
        if (file_exists($status_file)) {
            unlink($status_file);
        }
        
        // Reset any 'scraping' status back to 'pending' so they can be retried
        $stmt = $conn->prepare("
            UPDATE scrape_link_queue 
            SET status='pending' 
            WHERE source_id=? AND status='scraping'
        ");
        $stmt->bind_param('i', $source_id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        
        echo json_encode([
            'success' => true,
            'message' => "Scraping stopped. Reset {$affected} URLs back to pending."
        ]);
        exit;
    }

    if ($action !== 'start') throw new Exception("Unknown action: $action");
    if (!$source_id) throw new Exception('source_id required');

    // Check if a scan is currently running for this source
    $scan_status_file = sys_get_temp_dir() . "/scan_missing_{$source_id}.json";
    if (file_exists($scan_status_file)) {
        $scan_status = json_decode(file_get_contents($scan_status_file), true);
        if (!empty($scan_status['running'])) {
            // Force stop the scan by deleting its status file
            unlink($scan_status_file);
            
            // Wait a moment for the scan process to detect the deletion and stop
            sleep(2);
            
            // If file was recreated (scan is still running), throw error
            if (file_exists($scan_status_file)) {
                throw new Exception('Cannot scrape while scan is running. Please stop the scan first or wait for it to complete.');
            }
        }
    }

    // Check if already running
    $current = read_scrape_status($source_id);
    if (!empty($current['running'])) {
        echo json_encode([
            'success' => true,
            'already_running' => true,
            'message' => 'Scraping already in progress'
        ]);
        exit;
    }

    // Count pending items
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM scrape_link_queue WHERE source_id=? AND status='pending'");
    $stmt->bind_param('i', $source_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $pending_count = $row['cnt'] ?? 0;
    $stmt->close();

    if ($pending_count === 0) {
        echo json_encode([
            'success' => true,
            'nothing_to_scrape' => true,
            'message' => 'No pending URLs to scrape'
        ]);
        exit;
    }

    // Write initial status
    write_scrape_status($source_id, [
        'running' => true,
        'finished' => false,
        'started' => time(),
        'pending' => $pending_count,
        'phase' => 'Starting scraper…'
    ]);

    // Build Python command
    $python_script = realpath(__DIR__ . '/../scripts/web_scraper.py');
    
    // Use the backend_env Python (now has all required modules)
    $python_bin = '/home/bcodz/Desktop/pjt-chatbot/backend/backend_env/bin/python3';
    
    if (!file_exists($python_bin)) {
        // Fallback to pyenv or system Python
        $python_bin = '/home/bcodz/.pyenv/shims/python3';
        if (!file_exists($python_bin)) {
            $python_bin = 'python3';
        }
    }

    if (!$python_script || !file_exists($python_script)) {
        throw new Exception('Scraper script not found. Tried: ' . __DIR__ . '/../scripts/web_scraper.py');
    }

    // Log file for debugging
    $log_file = sys_get_temp_dir() . "/scrape_missing_{$source_id}.log";

    $cmd = sprintf(
        '%s %s --mode scrape-missing --source-id %d --db-host %s --db-user %s --db-password %s --db-name %s > %s 2>&1 &',
        escapeshellarg($python_bin),
        escapeshellarg($python_script),
        $source_id,
        escapeshellarg($host),
        escapeshellarg($username),
        escapeshellarg($password),
        escapeshellarg($dbname),
        escapeshellarg($log_file)
    );

    exec($cmd);

    // Log activity
    $log_stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, module, description, ip_address) VALUES (?, 'SCRAPE_MISSING', 'web_scraper', ?, ?)");
    $desc = "Started scrape-missing for source $source_id ($pending_count pending URLs)";
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $log_stmt->bind_param('iss', $_SESSION['admin_id'], $desc, $ip);
    $log_stmt->execute();
    $log_stmt->close();

    echo json_encode([
        'success' => true,
        'started' => true,
        'pending_count' => $pending_count,
        'log_file' => $log_file,
        'message' => "Scraping $pending_count URLs in background"
    ]);

} catch (Exception $e) {
    if ($source_id) {
        write_scrape_status($source_id, [
            'running' => false,
            'finished' => true,
            'error' => $e->getMessage()
        ]);
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
