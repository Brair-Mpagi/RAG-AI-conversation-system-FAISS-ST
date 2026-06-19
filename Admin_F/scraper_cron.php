<?php
/**
 * Standalone Web Scraper Cron Script
 * 
 * Usage: Run this script via server cron (e.g., every hour)
 * Example crontab entry:
 * 0 * * * * /usr/bin/php /home/bcodz/Desktop/University_Ai_Chatbot_System/Admin-Finale/scraper_cron.php
 */

// Since this is a CLI script, set appropriate error reporting and prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Forbidden: This script can only be run from the command line.");
}

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';

// Helper function to log to system_logs
function logToSystem($conn, $level, $message, $metadata = null) {
    static $stmt = null;
    if ($stmt === null) {
        $stmt = $conn->prepare("INSERT INTO system_logs (log_level, module, message, metadata, ip_address) VALUES (?, 'CronScraper', ?, ?, '127.0.0.1')");
    }
    $meta_json = $metadata ? json_encode($metadata) : null;
    $stmt->bind_param("sss", $level, $message, $meta_json);
    $stmt->execute();
}

logToSystem($conn, 'info', "Cron Scraper Started.", ['timestamp' => date('Y-m-d H:i:s')]);

// Fetch all active sources that have a schedule
$query = "SELECT source_id, source_name, base_url, schedule_type, last_scraped 
          FROM scraping_sources 
          WHERE is_active = 1 AND schedule_type != 'none'";

$result = $conn->query($query);
if (!$result) {
    logToSystem($conn, 'error', "Failed to retrieve scraping sources.", ['error' => $conn->error]);
    exit(1);
}

$sources = $result->fetch_all(MYSQLI_ASSOC);
$executed_count = 0;

$python_script = __DIR__ . '/scripts/web_scraper.py';
if (!file_exists($python_script)) {
    $alt = '/home/bcodz/Desktop/Research_Project/University_Ai_Chatbot_System/scripts/web_scraper.py';
    if (file_exists($alt)) {
        $python_script = $alt;
    } else {
        logToSystem($conn, 'error', "Scraper python script not found.", ['attempted_paths' => [__DIR__ . '/scripts/web_scraper.py', $alt]]);
        exit(1);
    }
}

$python_bin = '/home/bcodz/Desktop/Research_Project/University_Ai_Chatbot_System/backend/backend_env/bin/python3';
if (!file_exists($python_bin)) {
    $python_bin = 'python3';
}

foreach ($sources as $source) {
    $is_due = false;
    
    // If it's never been scraped, it's due.
    if (empty($source['last_scraped'])) {
        $is_due = true;
    } else {
        $last_scraped_time = strtotime($source['last_scraped']);
        $now = time();
        $diff_seconds = $now - $last_scraped_time;
        
        switch ($source['schedule_type']) {
            case 'daily':
                if ($diff_seconds >= 86400) $is_due = true;
                break;
            case 'weekly':
                if ($diff_seconds >= 604800) $is_due = true;
                break;
            case 'monthly':
                if ($diff_seconds >= 2592000) $is_due = true;
                break;
        }
    }

    if ($is_due) {
        logToSystem($conn, 'info', "Starting scheduled scrape for source ID {$source['source_id']}", ['source_name' => $source['source_name']]);
        
        $cmd = sprintf(
            '%s %s --source-id %d --base-url %s --db-host %s --db-user %s --db-password %s --db-name %s 2>&1',
            escapeshellarg($python_bin),
            escapeshellarg($python_script),
            $source['source_id'],
            escapeshellarg($source['base_url']),
            escapeshellarg($host),
            escapeshellarg($username),
            escapeshellarg($password),
            escapeshellarg($dbname)
        );

        $output = [];
        $return_var = 0;
        exec($cmd, $output, $return_var);

        if ($return_var === 0) {
            // Success
            $upd = $conn->prepare("UPDATE scraping_sources SET last_scraped = NOW() WHERE source_id = ?");
            $upd->bind_param("i", $source['source_id']);
            $upd->execute();
            
            logToSystem($conn, 'info', "Completed scheduled scrape for source ID {$source['source_id']}", ['return_code' => $return_var]);
            $executed_count++;
        } else {
            // Failed
            logToSystem($conn, 'error', "Scheduled scrape failed for source ID {$source['source_id']}.", ['return_code' => $return_var, 'output' => array_slice($output, -10)]); // Log last 10 lines of output
        }
    }
}

if ($executed_count > 0) {
    $pipeline_script = realpath(__DIR__ . '/../scripts/post_scrape_pipeline.py');
    if (!$pipeline_script) {
        $pipeline_script = realpath('/home/bcodz/Desktop/pjt-chatbot/scripts/post_scrape_pipeline.py');
    }
    if ($pipeline_script && file_exists($pipeline_script)) {
        $pcmd = sprintf(
            '%s %s --max-rounds 80 > /dev/null 2>&1 &',
            escapeshellarg($python_bin),
            escapeshellarg($pipeline_script)
        );
        exec($pcmd);
        logToSystem($conn, 'info', 'Post-scrape pipeline started (enrich + reindex).', ['script' => $pipeline_script]);
    }
}

logToSystem($conn, 'info', "Cron Scraper Finished. Executed {$executed_count} source(s).");
echo "Cron Scraper execution completed. Evaluated " . count($sources) . " active scheduled sources, executed $executed_count.\n";
