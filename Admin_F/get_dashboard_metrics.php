<?php
// get_dashboard_metrics.php - API endpoint for dashboard auto-refresh
header('Content-Type: application/json');
session_start();

// Check if the user is logged in
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once 'db.php';

try {
    $time_threshold_24hr = date('Y-m-d H:i:s', strtotime('-24 hours'));

    // Bot replied messages in last 24h
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM chat_messages WHERE sender_type='bot' AND created_at >= ?");
    $stmt->bind_param('s', $time_threshold_24hr);
    $stmt->execute();
    $replied_24hr = ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);

    // Errors in last 24h
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM error_logs WHERE created_at >= ?");
    $stmt->bind_param('s', $time_threshold_24hr);
    $stmt->execute();
    $errors_24hr = ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);

    // Missed (fallback) responses in last 24h
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM chat_messages WHERE response_type='fallback' AND created_at >= ?");
    $stmt->bind_param('s', $time_threshold_24hr);
    $stmt->execute();
    $missed_24hr = ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);

    // Web sessions in last 24h
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM web_sessions WHERE start_time >= ?");
    $stmt->bind_param('s', $time_threshold_24hr);
    $stmt->execute();
    $web_sessions_24hr = ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);

    echo json_encode([
        'replied_24hr' => $replied_24hr,
        'errors_24hr' => $errors_24hr,
        'missed_24hr' => $missed_24hr,
        'web_sessions_24hr' => $web_sessions_24hr,
        'timestamp' => time()
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
?>