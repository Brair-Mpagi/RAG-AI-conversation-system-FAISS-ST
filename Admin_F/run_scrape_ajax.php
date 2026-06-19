<?php
/**
 * AJAX endpoint for running web scraper with JSON response.
 * Called via JavaScript fetch() from web_scraper.php for progress feedback.
 */
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$source_id = (int) ($_POST['source_id'] ?? 0);
$force_refresh = (int) ($_POST['force_refresh'] ?? 0);
if ($source_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid source_id']);
    exit();
}

try {
    // Get source details
    $stmt = $conn->prepare("SELECT * FROM scraping_sources WHERE source_id=?");
    $stmt->bind_param('i', $source_id);
    $stmt->execute();
    $source = $stmt->get_result()->fetch_assoc();

    if (!$source) {
        http_response_code(404);
        echo json_encode(['error' => 'Source not found']);
        exit();
    }

    // Resolve Python scraper script path
    $python_script = __DIR__ . '/scripts/web_scraper.py';
    if (!file_exists($python_script)) {
        $alt = '/home/bcodz/Desktop/Research_Project/University_Ai_Chatbot_System/scripts/web_scraper.py';
        if (file_exists($alt)) {
            $python_script = $alt;
        } else {
            $python_script = null;
        }
    }

    if (!$python_script) {
        http_response_code(500);
        echo json_encode(['error' => 'Scraper script not found']);
        exit();
    }

    $python_bin = '/home/bcodz/Desktop/Research_Project/University_Ai_Chatbot_System/backend/backend_env/bin/python3';
    if (!file_exists($python_bin)) {
        $python_bin = 'python3';
    }

    $cmd = sprintf(
        '%s %s --source-id %d --base-url %s --db-host %s --db-user %s --db-password %s --db-name %s%s 2>&1',
        escapeshellarg($python_bin),
        escapeshellarg($python_script),
        $source_id,
        escapeshellarg($source['base_url']),
        escapeshellarg($host),
        escapeshellarg($username),
        escapeshellarg($password),
        escapeshellarg($dbname),
        $force_refresh ? ' --force-refresh' : ''
    );

    $output = [];
    $return_var = 0;
    
    // Close session to prevent blocking other concurrent AJAX requests (like termination)
    session_write_close();
    
    exec($cmd, $output, $return_var);

    // Parse scraper output
    $scrape_result = [
        'success' => ($return_var === 0),
        'pages_visited' => 0,
        'new_count' => 0,
        'updated_count' => 0,
        'unchanged_count' => 0,
        'skipped_count' => 0,
        'failed_count' => 0,
        'elapsed' => '0',
        'log_lines' => [],
        'error_lines' => [],
    ];

    foreach ($output as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || preg_match('/^={5,}$/', $trimmed) || preg_match('/^-{5,}$/', $trimmed))
            continue;

        if (preg_match('/Pages visited:\s*(\d+)/i', $trimmed, $m))
            $scrape_result['pages_visited'] = (int) $m[1];
        elseif (preg_match('/New pages:\s*(\d+)/i', $trimmed, $m))
            $scrape_result['new_count'] = (int) $m[1];
        elseif (preg_match('/Updated pages:\s*(\d+)/i', $trimmed, $m))
            $scrape_result['updated_count'] = (int) $m[1];
        elseif (preg_match('/Unchanged:\s*(\d+)/i', $trimmed, $m))
            $scrape_result['unchanged_count'] = (int) $m[1];
        elseif (preg_match('/Skipped:\s*(\d+)/i', $trimmed, $m))
            $scrape_result['skipped_count'] = (int) $m[1];
        elseif (preg_match('/Failed:\s*(\d+)/i', $trimmed, $m))
            $scrape_result['failed_count'] = (int) $m[1];
        elseif (preg_match('/Time elapsed:\s*([\d.]+)s/i', $trimmed, $m))
            $scrape_result['elapsed'] = $m[1];

        $type = 'info';
        if (mb_strpos($trimmed, '✓') !== false || mb_strpos($trimmed, 'NEW:') !== false || mb_strpos($trimmed, 'successfully') !== false)
            $type = 'success';
        elseif (mb_strpos($trimmed, '✗') !== false || mb_strpos($trimmed, 'FAILED') !== false || mb_strpos($trimmed, 'Error') !== false || mb_strpos($trimmed, 'error') !== false)
            $type = 'error';
        elseif (mb_strpos($trimmed, '↻') !== false || mb_strpos($trimmed, 'UPDATED') !== false)
            $type = 'update';
        elseif (mb_strpos($trimmed, '⊘') !== false || mb_strpos($trimmed, 'SKIP') !== false || mb_strpos($trimmed, 'Skipping') !== false)
            $type = 'skipped';

        $scrape_result['log_lines'][] = ['text' => $trimmed, 'type' => $type];
        if ($type === 'error') {
            $scrape_result['error_lines'][] = $trimmed;
        }
    }

    if ($return_var === 0) {
        $stmt = $conn->prepare("UPDATE scraping_sources SET last_scraped=NOW() WHERE source_id=?");
        $stmt->bind_param('i', $source_id);
        $stmt->execute();

        $changed = (int) ($scrape_result['new_count'] + $scrape_result['updated_count']);
        if ($changed > 0) {
            $pipeline_script = realpath(__DIR__ . '/../scripts/post_scrape_pipeline.py');
            if (!$pipeline_script) {
                $pipeline_script = realpath('/home/bcodz/Desktop/pjt-chatbot/scripts/post_scrape_pipeline.py');
            }
            if ($pipeline_script && file_exists($pipeline_script)) {
                $pipeline_py = file_exists($python_bin) ? $python_bin : 'python3';
                $pcmd = sprintf(
                    '%s %s --background --max-rounds 60 > /dev/null 2>&1 &',
                    escapeshellarg($pipeline_py),
                    escapeshellarg($pipeline_script)
                );
                exec($pcmd);
                $scrape_result['pipeline_started'] = true;
                $scrape_result['pipeline_message'] = 'Post-scrape enrich + reindex started in background';
            }
        }
    }

    echo json_encode($scrape_result);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>