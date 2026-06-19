<?php
/**
 * get_daily_messages.php
 * Returns daily/weekly/monthly message counts for trend chart.
 * GET params: days=N OR start=YYYY-MM-DD&end=YYYY-MM-DD, group=day|week|month
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

$labels = [];
$data   = [];
$group  = $_GET['group'] ?? 'day';

try {
    if (isset($_GET['start']) && isset($_GET['end'])) {
        $start = date('Y-m-d', strtotime($_GET['start']));
        $end   = date('Y-m-d', strtotime($_GET['end']));
    } else {
        $days  = max(1, min(730, (int)($_GET['days'] ?? 14)));
        $start = date('Y-m-d', strtotime("-$days days"));
        $end   = date('Y-m-d');
    }

    if ($group === 'week') {
        $sql = "SELECT DATE(DATE_SUB(created_at, INTERVAL (DAYOFWEEK(created_at)-2+7)%7 DAY)) as period,
                       COUNT(*) as cnt
                FROM chat_messages
                WHERE DATE(created_at) BETWEEN ? AND ?
                GROUP BY period ORDER BY period";
    } elseif ($group === 'month') {
        $sql = "SELECT DATE_FORMAT(created_at, '%Y-%m-01') as period, COUNT(*) as cnt
                FROM chat_messages
                WHERE DATE(created_at) BETWEEN ? AND ?
                GROUP BY period ORDER BY period";
    } else {
        $sql = "SELECT DATE(created_at) as period, COUNT(*) as cnt
                FROM chat_messages
                WHERE DATE(created_at) BETWEEN ? AND ?
                GROUP BY period ORDER BY period";
    }

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ss', $start, $end);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $ts = strtotime($row['period']);
            if ($group === 'week') {
                $labels[] = 'W/c ' . date('M j', $ts);
            } elseif ($group === 'month') {
                $labels[] = date('M Y', $ts);
            } else {
                $labels[] = date('M j', $ts);
            }
            $data[] = (int)$row['cnt'];
        }
        $stmt->close();
    }
} catch (Exception $e) {
    error_log('get_daily_messages: ' . $e->getMessage());
}

echo json_encode(['labels' => $labels, 'data' => $data]);
