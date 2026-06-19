<?php
/**
 * get_active_users.php
 * Returns real-time active user count as JSON.
 * Active = session status='active' AND updated_at within last 5 minutes.
 *
 * Before counting, we call the Python backend's /expire endpoint so that
 * sessions idle for >5 minutes are marked timeout first.
 */
session_start();
if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}
require_once 'db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

// ── Step 1: Ask the Python backend to expire stale sessions ──────────────────
$ctx = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'timeout' => 2,   // non-blocking; if it fails we still show current count
        'ignore_errors' => true,
    ],
]);
@file_get_contents('http://localhost:8000/api/v1/sessions/expire', false, $ctx);

// ── Step 2: Count remaining truly-active sessions ────────────────────────────
$active = 0;
try {
    $threshold = date('Y-m-d H:i:s', strtotime('-5 minutes'));
    $stmt = $conn->prepare(
        "SELECT COUNT(DISTINCT s.session_id) AS cnt
         FROM web_sessions s
         INNER JOIN chat_messages cm ON cm.session_id = s.session_id
         WHERE s.status = 'active'
           AND (s.updated_at >= ? OR s.start_time >= ?)"
    );
    if ($stmt) {
        $stmt->bind_param('ss', $threshold, $threshold);
        $stmt->execute();
        $active = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmt->close();
    }
} catch (Exception $e) {
    error_log('get_active_users: ' . $e->getMessage());
}

echo json_encode(['active' => $active]);
