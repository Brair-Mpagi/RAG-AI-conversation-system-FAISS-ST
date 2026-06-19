<?php
/**
 * get_feedback_trend.php
 * Returns daily/weekly/monthly feedback counts split into likes and dislikes.
 * GET params: days=N, group=day|week|month
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
$likes_data = [];
$dislikes_data = [];
$group  = $_GET['group'] ?? 'day';
$days   = max(1, min(730, (int)($_GET['days'] ?? 14)));
$start  = date('Y-m-d', strtotime("-$days days"));
$end    = date('Y-m-d');

try {
    // Build SQL for grouping by period
    if ($group === 'week') {
        $period_expr = "DATE(DATE_SUB(created_at, INTERVAL (DAYOFWEEK(created_at)-2+7)%7 DAY))";
    } elseif ($group === 'month') {
        $period_expr = "DATE_FORMAT(created_at, '%Y-%m-01')";
    } else {
        $period_expr = "DATE(created_at)";
    }

    // Fetch typed feedback by period and rating
    $sql_feedback = "SELECT $period_expr as period, rating, COUNT(*) as cnt
                     FROM feedback WHERE DATE(created_at) BETWEEN ? AND ?
                     GROUP BY period, rating ORDER BY period";
    
    $feedback_by_period = [];
    $stmt = $conn->prepare($sql_feedback);
    if ($stmt) {
        $stmt->bind_param('ss', $start, $end);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            if (!isset($feedback_by_period[$row['period']])) {
                $feedback_by_period[$row['period']] = ['likes' => 0, 'dislikes' => 0];
            }
            // excellent/good = likes, bad = dislikes
            if (in_array($row['rating'], ['excellent', 'good'])) {
                $feedback_by_period[$row['period']]['likes'] += (int)$row['cnt'];
            } else {
                $feedback_by_period[$row['period']]['dislikes'] += (int)$row['cnt'];
            }
        }
        $stmt->close();
    }

    // Fetch reactions by period and type
    $sql_reactions = "SELECT $period_expr as period, reaction_type, COUNT(*) as cnt
                      FROM message_reactions WHERE DATE(created_at) BETWEEN ? AND ?
                      GROUP BY period, reaction_type ORDER BY period";
    
    $reactions_by_period = [];
    $stmt = $conn->prepare($sql_reactions);
    if ($stmt) {
        $stmt->bind_param('ss', $start, $end);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            if (!isset($reactions_by_period[$row['period']])) {
                $reactions_by_period[$row['period']] = ['likes' => 0, 'dislikes' => 0];
            }
            // thumbs_up/helpful/accurate = likes, rest = dislikes
            if (in_array($row['reaction_type'], ['thumbs_up', 'helpful', 'accurate'])) {
                $reactions_by_period[$row['period']]['likes'] += (int)$row['cnt'];
            } else {
                $reactions_by_period[$row['period']]['dislikes'] += (int)$row['cnt'];
            }
        }
        $stmt->close();
    }

    // Combine both datasets
    $all_periods = array_unique(array_merge(array_keys($feedback_by_period), array_keys($reactions_by_period)));
    sort($all_periods);

    foreach ($all_periods as $period) {
        $ts = strtotime($period);
        if ($group === 'week')       $labels[] = 'W/c ' . date('M j', $ts);
        elseif ($group === 'month')  $labels[] = date('M Y', $ts);
        else                         $labels[] = date('M j', $ts);
        
        $fb_likes = $feedback_by_period[$period]['likes'] ?? 0;
        $fb_dislikes = $feedback_by_period[$period]['dislikes'] ?? 0;
        $rx_likes = $reactions_by_period[$period]['likes'] ?? 0;
        $rx_dislikes = $reactions_by_period[$period]['dislikes'] ?? 0;
        
        $likes_data[] = $fb_likes + $rx_likes;
        $dislikes_data[] = $fb_dislikes + $rx_dislikes;
    }

} catch (Exception $e) {
    error_log('get_feedback_trend: ' . $e->getMessage());
}

echo json_encode([
    'labels' => $labels, 
    'likes' => $likes_data,
    'dislikes' => $dislikes_data
]);
