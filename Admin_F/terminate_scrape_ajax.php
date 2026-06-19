<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit();
}

$source_id = (int)($_POST['source_id'] ?? 0);
if ($source_id > 0) {
    $signal_file = "/tmp/scrape_stop_signal_{$source_id}";
    file_put_contents($signal_file, "STOP");
    
    // Attempt to kill python3 web_scraper.py process running for this source_id as fallback
    // Since we have the signal file, Python will pick it up on its own, but
    // just in case we can also run pkill if the user has permissions. 
    $cmd = sprintf("pkill -f 'web_scraper.py.*--source-id %d'", $source_id);
    exec($cmd);
    
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['error' => 'Invalid source_id']);
}
