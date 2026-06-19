<?php
/**
 * delete_activity.php
 * AJAX endpoint – hides a single recent activity entry from the UI feed only.
 * Does NOT delete real operational data (sessions, messages, logs).
 *
 * Only deletes from "safe" log/audit tables:
 *   - admin_activity_logs (admin actions)
 *   - admin_password_resets (password reset requests)
 *   - error_logs (error entries)
 *   - scraped_content (KB update records)
 *
 * Entries from web_sessions (session_start) and chat_messages (fallback)
 * are NOT deleted — the UI just removes the card visually.
 */
session_start();
if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once 'db.php';
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

// SAFE deletable types: only audit/log tables, never operational data
$safe_delete_map = [
    'admin_action'   => ['table' => 'admin_activity_logs',   'ts_col' => 'timestamp'],
    'password_reset' => ['table' => 'admin_password_resets', 'ts_col' => 'created_at'],
    'error'          => ['table' => 'error_logs',            'ts_col' => 'created_at'],
    'kb_update'      => ['table' => 'scraped_content',       'ts_col' => 'scraped_at'],
];

// These types touch operational tables — UI removes card visually, no DB delete
$ui_only_types = ['session_start', 'fallback'];

if ($action === 'delete') {
    $type       = trim($_POST['type'] ?? '');
    $created_at = trim($_POST['created_at'] ?? '');

    if (empty($type) || empty($created_at)) {
        echo json_encode(['error' => 'Invalid parameters']);
        exit();
    }

    // UI-only removal (operational tables — don't touch)
    if (in_array($type, $ui_only_types)) {
        echo json_encode(['ok' => true, 'deleted' => 0, 'note' => 'ui_only']);
        exit();
    }

    if (!isset($safe_delete_map[$type])) {
        echo json_encode(['error' => 'Unknown type']);
        exit();
    }

    $m      = $safe_delete_map[$type];
    $table  = $m['table'];
    $ts_col = $m['ts_col'];

    $sql  = "DELETE FROM `{$table}` WHERE `{$ts_col}` = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('s', $created_at);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        echo json_encode(['ok' => true, 'deleted' => $affected]);
    } else {
        echo json_encode(['error' => $conn->error]);
    }

} elseif ($action === 'clear_all') {
    // Only clear safe audit log tables — never sessions or chat messages
    $safe_tables = ['admin_activity_logs', 'admin_password_resets', 'error_logs'];
    $failed = [];
    foreach ($safe_tables as $t) {
        if (!$conn->query("DELETE FROM `{$t}` WHERE 1")) {
            $failed[] = $t;
        }
    }
    if (empty($failed)) {
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['error' => 'Failed to clear: ' . implode(', ', $failed)]);
    }

} else {
    echo json_encode(['error' => 'Unknown action']);
}
