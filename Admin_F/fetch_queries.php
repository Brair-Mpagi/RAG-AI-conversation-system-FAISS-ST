<?php
// fetch_queries.php
header('Content-Type: application/json');

require_once 'db.php';
if (!$conn || $conn->connect_error) {
    die("Connection failed: " . ($conn ? $conn->connect_error : 'No connection object.'));
}
if (!$conn->ping()) {
    die("Database connection is closed.");
}

$sql_queries = "SELECT q.*, 
            s.session_token,
            s.ip_address,
            s.location,
            COALESCE(q.user_name, CONCAT('Session-', q.session_id)) AS user_name_display,
            COALESCE(q.user_email, s.ip_address, 'N/A') AS user_email_display
     FROM user_queries q
     LEFT JOIN web_sessions s ON q.session_id = s.session_id
     WHERE q.status NOT IN ('resolved', 'closed')
     ORDER BY q.submitted_at DESC";
$result_queries = $conn->query($sql_queries);
$queries = [];
while ($row = $result_queries->fetch_assoc()) {
    $sub_time = strtotime($row['submitted_at']);
    $hours_ago = floor((time() - $sub_time) / 3600);
    $age_str = $hours_ago . 'h ago';
    if ($hours_ago < 1) $age_str = '< 1h ago';
    
    $sla_class = 'pq-sla-green';
    $is_urgent = false;
    if ($hours_ago > 24) { 
        $sla_class = 'pq-sla-red'; 
        $is_urgent = true; 
    } elseif ($hours_ago > 4) {
        $sla_class = 'pq-sla-amber';
    }

    $row['submitted_at_formatted'] = date('M j, g:i A', $sub_time);
    $row['age_str'] = $age_str;
    $row['sla_class'] = $sla_class;
    $row['is_urgent'] = $is_urgent;

    $queries[] = $row;
}

$sql_count_pending = "SELECT COUNT(*) as pending_count FROM user_queries WHERE status = 'pending'";
$result_count_pending = $conn->query($sql_count_pending);
$pending_count = $result_count_pending ? ($result_count_pending->fetch_assoc()['pending_count'] ?? 0) : 0;

$conn->close();

echo json_encode([
    'queries' => $queries,
    'not_yet_count' => $pending_count
]);
?>