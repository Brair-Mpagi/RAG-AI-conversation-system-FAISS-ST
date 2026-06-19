<?php
// Production: log errors, never display them
ini_set("display_errors", 0);
ini_set("display_startup_errors", 0);
ini_set("log_errors", 1);
error_reporting(E_ALL);

// Start session to check authentication
session_start();

// Check if the user is logged in
if (!isset($_SESSION["admin_id"])) {
    header("Location: ./admin-login.php");
    exit();
}

// Database Configuration (must be loaded before any $conn usage)
require_once "db.php";

// Helper for formatting big numbers
if (!function_exists("formatMetricNumber")) {
    function formatMetricNumber($num)
    {
        $num = (int) $num;
        if ($num >= 1000000) {
            return round($num / 1000000, 1) . "M";
        }
        if ($num >= 1000) {
            return round($num / 1000, 1) . "K";
        }
        return number_format($num);
    }
}

// Additional Metrics
try {
    $time_threshold_24hr = date("Y-m-d H:i:s", strtotime("-24 hours"));
    // Bot replied messages in last 24h
    $stmt = $conn->prepare(
        "SELECT COUNT(*) as cnt FROM chat_messages WHERE sender_type='bot' AND created_at >= ?",
    );
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("s", $time_threshold_24hr);
    $stmt->execute();
    $replied_24hr = $stmt->get_result()->fetch_assoc()["cnt"] ?? 0;

    // Missed (fallback) responses in last 24h
    $stmt = $conn->prepare(
        "SELECT COUNT(*) as cnt FROM chat_messages WHERE response_type='fallback' AND created_at >= ?",
    );
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("s", $time_threshold_24hr);
    $stmt->execute();
    $missed_24hr = $stmt->get_result()->fetch_assoc()["cnt"] ?? 0;

    // Errors occurred in last 24h
    $stmt = $conn->prepare(
        "SELECT COUNT(*) as cnt FROM error_logs WHERE created_at >= ?",
    );
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("s", $time_threshold_24hr);
    $stmt->execute();
    $errors_24hr = $stmt->get_result()->fetch_assoc()["cnt"] ?? 0;

    // Web session usage in last 24h
    $stmt = $conn->prepare(
        "SELECT COUNT(*) as cnt FROM web_sessions WHERE start_time >= ?",
    );
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("s", $time_threshold_24hr);
    $stmt->execute();
    $web_sessions_24hr = $stmt->get_result()->fetch_assoc()["cnt"] ?? 0;

    // Inquiries handled (resolved/closed)
    $inq_daily =
        $conn
            ->query(
                "SELECT COUNT(*) as c FROM user_queries WHERE status IN ('resolved','closed') AND resolved_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)",
            )
            ->fetch_assoc()["c"] ?? 0;
    $inq_weekly =
        $conn
            ->query(
                "SELECT COUNT(*) as c FROM user_queries WHERE status IN ('resolved','closed') AND resolved_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)",
            )
            ->fetch_assoc()["c"] ?? 0;
    $inq_monthly =
        $conn
            ->query(
                "SELECT COUNT(*) as c FROM user_queries WHERE status IN ('resolved','closed') AND resolved_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)",
            )
            ->fetch_assoc()["c"] ?? 0;
} catch (Exception $e) {
    error_log("Additional metrics failed: " . $e->getMessage());
    $replied_24hr = $missed_24hr = $errors_24hr = $web_sessions_24hr = $inq_daily = $inq_weekly = $inq_monthly = 0;
}

// Fetch Admin Details
$admin_query =
    "SELECT admin_id, username, email FROM admins WHERE admin_id = ?";
if (!$conn->ping()) {
    die("Database connection is closed.");
}
$admin_stmt = $conn->prepare($admin_query);
if (!$admin_stmt) {
    die("Prepare failed: " . $conn->error);
}
$admin_stmt->bind_param("i", $_SESSION["admin_id"]);
$admin_stmt->execute();
$admin_result = $admin_stmt->get_result();
$admin = $admin_result->fetch_assoc();

if (!$admin) {
    // Session holds an admin_id that no longer exists; reset and re-authenticate
    session_unset();
    session_destroy();
    header("Location: ./admin-login.php?err=account_missing");
    exit();
}

// Widget Data
// Users in last 24 hours (distinct sessions)
try {
    $stmt = $conn->prepare(
        "SELECT COUNT(DISTINCT session_id) as count FROM web_sessions WHERE start_time >= ?",
    );
    if (!$stmt) {
        error_log("Prepare failed for sessions count: " . $conn->error);
        $users_24hr = 0;
    } else {
        $time_24h = date("Y-m-d H:i:s", strtotime("-24 hours"));
        $stmt->bind_param("s", $time_24h);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        $users_24hr = $row["count"] ?? 0;
    }
} catch (Exception $e) {
    error_log("Error fetching sessions count: " . $e->getMessage());
    $users_24hr = 0;
}

// Widget comparison data: previous 24h users
try {
    $prev_24h_start = date("Y-m-d H:i:s", strtotime("-48 hours"));
    $prev_24h_end = date("Y-m-d H:i:s", strtotime("-24 hours"));
    $stmt2 = $conn->prepare(
        "SELECT COUNT(DISTINCT session_id) as count FROM web_sessions WHERE start_time >= ? AND start_time < ?",
    );
    $stmt2->bind_param("ss", $prev_24h_start, $prev_24h_end);
    $stmt2->execute();
    $users_prev_24hr =
        (int) ($stmt2->get_result()->fetch_assoc()["count"] ?? 0);
    $stmt2->close();
} catch (Exception $e) {
    $users_prev_24hr = 0;
}

// Widget: Feedback yesterday
try {
    $yesterday_start = date("Y-m-d 00:00:00", strtotime("-1 day"));
    $yesterday_end = date("Y-m-d 23:59:59", strtotime("-1 day"));
    $fb_yest_stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM (
        SELECT id FROM feedback WHERE created_at >= ? AND created_at <= ?
        UNION ALL
        SELECT id FROM message_reactions WHERE created_at >= ? AND created_at <= ?
    ) t");
    $fb_yest_stmt->bind_param(
        "ssss",
        $yesterday_start,
        $yesterday_end,
        $yesterday_start,
        $yesterday_end,
    );
    $fb_yest_stmt->execute();
    $feedback_yesterday =
        (int) ($fb_yest_stmt->get_result()->fetch_assoc()["cnt"] ?? 0);
    $fb_yest_stmt->close();
} catch (Exception $e) {
    $feedback_yesterday = 0;
}

// Widget: 7-day sparkline data for Users, Feedback, Awaiting
try {
    $spark_users_7d = [];
    $spark_res = $conn->query(
        "SELECT DATE(start_time) as d, COUNT(DISTINCT session_id) as cnt FROM web_sessions WHERE start_time >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(start_time) ORDER BY d",
    );
    while ($spark_res && ($r = $spark_res->fetch_assoc())) {
        $spark_users_7d[] = (int) $r["cnt"];
    }

    $spark_feedback_7d = [];
    $spark_fb_res = $conn->query("SELECT d, SUM(cnt) as total FROM (
        SELECT DATE(created_at) as d, COUNT(*) as cnt FROM feedback WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(created_at)
        UNION ALL
        SELECT DATE(created_at) as d, COUNT(*) as cnt FROM message_reactions WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(created_at)
    ) t GROUP BY d ORDER BY d");
    while ($spark_fb_res && ($r = $spark_fb_res->fetch_assoc())) {
        $spark_feedback_7d[] = (int) $r["total"];
    }
} catch (Exception $e) {
    $spark_users_7d = [];
    $spark_feedback_7d = [];
}

try {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) as count FROM feedback WHERE DATE(created_at) = CURDATE()",
    );
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $typed_feedback_today = $row["count"] ?? 0;

    // Reaction feedback today (thumbs up/down)
    $stmt = $conn->prepare(
        "SELECT COUNT(*) as count FROM message_reactions WHERE DATE(created_at) = CURDATE()",
    );
    if ($stmt) {
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $reactions_today = $row["count"] ?? 0;
    } else {
        $reactions_today = 0;
    }

    $feedback_today = $typed_feedback_today + $reactions_today;
} catch (Exception $e) {
    error_log("Query failed: " . $e->getMessage());
    $feedback_today = 0;
    $typed_feedback_today = 0;
    $reactions_today = 0;
}

try {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) as count FROM user_queries WHERE status = 'pending'",
    );
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $pushed_awaiting = $row["count"] ?? 0;
} catch (Exception $e) {
    error_log("Query failed: " . $e->getMessage());
    $pushed_awaiting = 0;
}

// Graph Data
try {
    $stmt = $conn->prepare(
        "SELECT DATE(created_at) as date, COUNT(*) as count FROM chat_messages GROUP BY DATE(created_at) ORDER BY date",
    );
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $chatlogs_per_day = $result->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    error_log("Query failed: " . $e->getMessage());
    $chatlogs_per_day = [];
}

// Queries per week
try {
    $stmt = $conn->prepare(
        "SELECT YEARWEEK(created_at, 1) as week, COUNT(*) as count FROM chat_messages GROUP BY week ORDER BY week",
    );
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $chatlogs_per_week = $result->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    error_log("Query failed: " . $e->getMessage());
    $chatlogs_per_week = [];
}

// Queries per month
try {
    $stmt = $conn->prepare(
        "SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count FROM chat_messages GROUP BY month ORDER BY month",
    );
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $chatlogs_per_month = $result->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    error_log("Query failed: " . $e->getMessage());
    $chatlogs_per_month = [];
}

// Queries per year
try {
    $stmt = $conn->prepare(
        "SELECT YEAR(created_at) as year, COUNT(*) as count FROM chat_messages GROUP BY year ORDER BY year",
    );
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $chatlogs_per_year = $result->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    error_log("Query failed: " . $e->getMessage());
    $chatlogs_per_year = [];
}

try {
    $stmt = $conn->prepare(
        "SELECT HOUR(created_at) as hour, COUNT(*) as count FROM chat_messages GROUP BY HOUR(created_at) ORDER BY hour",
    );
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $chatlogs_by_hour = $result->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    error_log("Query failed: " . $e->getMessage());
    $chatlogs_by_hour = [];
}

try {
    $stmt = $conn->prepare(
        "SELECT title as answer, LENGTH(content) as count FROM entity_knowledge_chunks WHERE is_active = TRUE ORDER BY chunk_id DESC LIMIT 5",
    );
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $faq_cache_answers = $result->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    error_log("Query failed: " . $e->getMessage());
    $faq_cache_answers = [];
}

try {
    // FAQ Frequency: count the most common user message topics
    // Try intent_classification first, fall back to common words in user_message
    $stmt = $conn->prepare("
        SELECT 
            CASE 
                WHEN intent_classification IS NOT NULL AND intent_classification != '' 
                THEN intent_classification 
                ELSE 'general' 
            END as query, 
            COUNT(*) as frequency 
        FROM chat_messages 
        WHERE sender_type='user' AND user_message IS NOT NULL 
        GROUP BY query 
        ORDER BY frequency DESC 
        LIMIT 8
    ");
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $faq_frequency = $result->fetch_all(MYSQLI_ASSOC);
    // Clean up labels for display
    foreach ($faq_frequency as &$fq) {
        $fq["query"] = ucwords(str_replace("_", " ", $fq["query"]));
    }
    unset($fq);
} catch (Exception $e) {
    error_log("FAQ query failed: " . $e->getMessage());
    $faq_frequency = [];
}

try {
    $stmt = $conn->prepare(
        "SELECT rating, COUNT(*) as count FROM feedback GROUP BY rating",
    );
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $feedback_counts = $result->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    error_log("Query failed: " . $e->getMessage());
    $feedback_counts = [];
}

// Message Reactions (thumbs up/down)
try {
    $stmt = $conn->prepare(
        "SELECT reaction_type, COUNT(*) as count FROM message_reactions GROUP BY reaction_type",
    );
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        $reaction_counts = $result->fetch_all(MYSQLI_ASSOC);
    } else {
        $reaction_counts = [];
    }
    $thumbs_up_reactions = 0;
    $thumbs_down_reactions = 0;
    $total_reactions = 0;
    foreach ($reaction_counts as $rc) {
        $total_reactions += (int) $rc["count"];
        if (
            in_array($rc["reaction_type"], ["thumbs_up", "helpful", "accurate"])
        ) {
            $thumbs_up_reactions += (int) $rc["count"];
        } else {
            $thumbs_down_reactions += (int) $rc["count"];
        }
    }

    // Reactions over time (for charts)
    $stmt = $conn->prepare(
        "SELECT DATE(created_at) as date, reaction_type, COUNT(*) as count FROM message_reactions GROUP BY DATE(created_at), reaction_type ORDER BY date",
    );
    if ($stmt) {
        $stmt->execute();
        $reactions_over_time = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $reactions_over_time = [];
    }
} catch (Exception $e) {
    error_log("Reactions query failed: " . $e->getMessage());
    $reaction_counts = [];
    $thumbs_up_reactions = $thumbs_down_reactions = $total_reactions = 0;
    $reactions_over_time = [];
}

try {
    $stmt = $conn->prepare(
        "SELECT DATE(created_at) as date, rating, COUNT(*) as count FROM feedback GROUP BY DATE(created_at), rating ORDER BY date",
    );
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $feedback_over_time = $result->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    error_log("Query failed: " . $e->getMessage());
    $feedback_over_time = [];
}

try {
    // Cap at 3600s (1h) to exclude abandoned sessions that inflate averages
    $stmt = $conn->prepare(
        "SELECT s.duration_seconds as session_duration, IFNULL(msg_counts.cnt, 0) as number_of_queries 
         FROM web_sessions s 
         LEFT JOIN (
             SELECT session_id, COUNT(user_message) as cnt 
             FROM chat_messages 
             GROUP BY session_id
         ) msg_counts ON s.session_id = msg_counts.session_id 
         WHERE s.duration_seconds <= 3600",
    );
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $user_sessions = $result->fetch_all(MYSQLI_ASSOC);

    // Count excluded sessions (>1h) for transparency
    $excl_stmt = $conn->prepare(
        "SELECT COUNT(*) as cnt FROM web_sessions WHERE duration_seconds > 3600",
    );
    if ($excl_stmt) {
        $excl_stmt->execute();
        $excluded_sessions_count =
            (int) ($excl_stmt->get_result()->fetch_assoc()["cnt"] ?? 0);
        $excl_stmt->close();
    } else {
        $excluded_sessions_count = 0;
    }
} catch (Exception $e) {
    error_log("Query failed: " . $e->getMessage());
    $user_sessions = [];
    $excluded_sessions_count = 0;
}

// User Sessions over time (for trend line chart)
try {
    $res = $conn->query("
        SELECT 
            DATE(s.start_time) as date,
            ROUND(AVG(s.duration_seconds)) as avg_duration,
            ROUND(AVG(IFNULL(msg_counts.cnt, 0)), 1) as avg_queries,
            COUNT(s.session_id) as session_count
        FROM web_sessions s
        LEFT JOIN (
            SELECT session_id, COUNT(user_message) as cnt 
            FROM chat_messages 
            GROUP BY session_id
        ) msg_counts ON s.session_id = msg_counts.session_id
        WHERE s.start_time IS NOT NULL
        GROUP BY DATE(s.start_time)
        ORDER BY date
        LIMIT 90
    ");
    $session_trends = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
} catch (Exception $e) {
    error_log("Session trends query failed: " . $e->getMessage());
    $session_trends = [];
}

// Daily Throughput data (with fallback to chat_messages if analytics_daily is empty)
try {
    $res = $conn->query(
        "SELECT analytics_date as date, total_conversations, successful_responses, failed_responses FROM analytics_daily ORDER BY analytics_date",
    );
    $daily_metrics = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

    // Fallback: build daily metrics from chat_messages + error_logs if analytics_daily is empty
    if (empty($daily_metrics)) {
        $res = $conn->query("
            SELECT 
                DATE(cm.created_at) as date,
                COUNT(*) as total_conversations,
                SUM(CASE WHEN cm.confidence_score IS NULL OR cm.confidence_score >= 0.3 THEN 1 ELSE 0 END) as successful_responses,
                SUM(CASE WHEN cm.confidence_score IS NOT NULL AND cm.confidence_score < 0.3 THEN 1 ELSE 0 END) as failed_responses
            FROM chat_messages cm
            GROUP BY DATE(cm.created_at)
            ORDER BY date
            LIMIT 90
        ");
        $daily_metrics = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }

    // Error counts per day for the throughput chart
    $res = $conn->query(
        "SELECT DATE(created_at) as date, COUNT(*) as error_count FROM error_logs GROUP BY DATE(created_at) ORDER BY date",
    );
    $errors_by_date = [];
    if ($res) {
        foreach ($res->fetch_all(MYSQLI_ASSOC) as $row) {
            $errors_by_date[$row["date"]] = (int) $row["error_count"];
        }
    }
    // Merge error counts into daily_metrics
    foreach ($daily_metrics as &$dm) {
        $dm["error_count"] = $errors_by_date[$dm["date"]] ?? 0;
    }
    unset($dm);

    // Weekly throughput
    $res = $conn->query(
        "
        SELECT 
            CONCAT(YEAR(d.date), '-W', LPAD(WEEK(d.date), 2, '0')) as week_label,
            SUM(d.total_conversations) as total_conversations,
            SUM(d.successful_responses) as successful_responses,
            SUM(d.failed_responses) as failed_responses
        FROM (" .
            (empty($daily_metrics)
                ? "SELECT CURDATE() as date, 0 as total_conversations, 0 as successful_responses, 0 as failed_responses"
                : "
            SELECT DATE(cm.created_at) as date,
                COUNT(*) as total_conversations,
                SUM(CASE WHEN cm.confidence_score IS NULL OR cm.confidence_score >= 0.3 THEN 1 ELSE 0 END) as successful_responses,
                SUM(CASE WHEN cm.confidence_score IS NOT NULL AND cm.confidence_score < 0.3 THEN 1 ELSE 0 END) as failed_responses
            FROM chat_messages cm
            GROUP BY DATE(cm.created_at)") .
            ") d
        GROUP BY YEAR(d.date), WEEK(d.date)
        ORDER BY MIN(d.date)
    ",
    );
    $weekly_throughput = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

    // Monthly throughput
    $res = $conn->query(
        "
        SELECT 
            DATE_FORMAT(d.date, '%Y-%m') as month_label,
            SUM(d.total_conversations) as total_conversations,
            SUM(d.successful_responses) as successful_responses,
            SUM(d.failed_responses) as failed_responses
        FROM (" .
            (empty($daily_metrics)
                ? "SELECT CURDATE() as date, 0 as total_conversations, 0 as successful_responses, 0 as failed_responses"
                : "
            SELECT DATE(cm.created_at) as date,
                COUNT(*) as total_conversations,
                SUM(CASE WHEN cm.confidence_score IS NULL OR cm.confidence_score >= 0.3 THEN 1 ELSE 0 END) as successful_responses,
                SUM(CASE WHEN cm.confidence_score IS NOT NULL AND cm.confidence_score < 0.3 THEN 1 ELSE 0 END) as failed_responses
            FROM chat_messages cm
            GROUP BY DATE(cm.created_at)") .
            ") d
        GROUP BY DATE_FORMAT(d.date, '%Y-%m')
        ORDER BY MIN(d.date)
    ",
    );
    $monthly_throughput = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
} catch (Exception $e) {
    error_log("Daily metrics query failed: " . $e->getMessage());
    $daily_metrics = [];
    $weekly_throughput = [];
    $monthly_throughput = [];
    $errors_by_date = [];
}

try {
    $res = $conn->query(
        "SELECT device_type, COUNT(*) as sessions FROM web_sessions WHERE start_time >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY device_type",
    );
    $device_usage = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
} catch (Exception $e) {
    error_log("Device usage query failed: " . $e->getMessage());
    $device_usage = [];
}

try {
    $res = $conn->query(
        "SELECT m.model_name, ROUND(SUM(mp.successful_responses)/NULLIF(SUM(mp.total_requests),0)*100,2) as success_rate FROM ai_models m LEFT JOIN model_performance_metrics mp ON m.model_id = mp.model_id GROUP BY m.model_id, m.model_name ORDER BY success_rate DESC",
    );
    $model_success = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
} catch (Exception $e) {
    error_log("Model success query failed: " . $e->getMessage());
    $model_success = [];
}

try {
    $res = $conn->query(
        "SELECT DATE(created_at) as date, COUNT(*) as count FROM error_logs GROUP BY DATE(created_at) ORDER BY date",
    );
    $errors_over_time = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
} catch (Exception $e) {
    error_log("Errors trend query failed: " . $e->getMessage());
    $errors_over_time = [];
}

try {
    $res = $conn->query(
        "SELECT DATE(created_at) as date, ROUND(AVG(satisfaction_score),2) as avg_score FROM conversation_metadata GROUP BY DATE(created_at) ORDER BY date",
    );
    $satisfaction_trend = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
} catch (Exception $e) {
    error_log("Satisfaction trend query failed: " . $e->getMessage());
    $satisfaction_trend = [];
}

// Calculate peak activity range
$max_range_count = 0;
$start_hour = null;
$end_hour = null;

for ($i = 0; $i < count($chatlogs_by_hour); $i++) {
    $current_range_count = $chatlogs_by_hour[$i]["count"];
    $current_start = $chatlogs_by_hour[$i]["hour"];
    $current_end = $current_start;

    for ($j = $i + 1; $j < count($chatlogs_by_hour); $j++) {
        if ($chatlogs_by_hour[$j]["hour"] == $current_end + 1) {
            $current_range_count += $chatlogs_by_hour[$j]["count"];
            $current_end = $chatlogs_by_hour[$j]["hour"];
        } else {
            break;
        }
    }

    if ($current_range_count > $max_range_count) {
        $max_range_count = $current_range_count;
        $start_hour = $current_start;
        $end_hour = $current_end;
    }
}

$time_range =
    $start_hour !== null && $end_hour !== null
    ? sprintf("%02d:00 - %02d:00", $start_hour, $end_hour)
    : "No significant range";
$total_queries_hour = array_sum(array_column($chatlogs_by_hour, "count"));

// Active Users Calculation — real users who opened chat & sent ≥1 message in current session
try {
    $time_threshold_5min = date("Y-m-d H:i:s", strtotime("-5 minutes"));

    // Real active: session is active AND has at least 1 chat message
    $active_users_query = "
        SELECT COUNT(DISTINCT s.session_id) AS active_users_5min
        FROM web_sessions s
        INNER JOIN chat_messages cm ON cm.session_id = s.session_id
        WHERE s.status = 'active'
          AND (s.updated_at >= ? OR s.start_time >= ?)
    ";

    $stmt = $conn->prepare($active_users_query);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("ss", $time_threshold_5min, $time_threshold_5min);
    $stmt->execute();
    $result = $stmt->get_result();
    $active_users_5min =
        (int) ($result->fetch_assoc()["active_users_5min"] ?? 0);
    $stmt->close();

    // Removed fallback logic to strictly adhere to the 5-minute active window timeout

    $active_users_24hr = $active_users_5min; // kept for template compatibility
} catch (Exception $e) {
    error_log("Active users query failed: " . $e->getMessage());
    $active_users_5min = 0;
    $active_users_24hr = 0;
}

// Total Queries in the last 24 hours
try {
    $time_threshold_24hr = date("Y-m-d H:i:s", strtotime("-24 hours"));
    $stmt = $conn->prepare(
        "SELECT COUNT(*) as count FROM chat_messages WHERE created_at >= ?",
    );
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("s", $time_threshold_24hr);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $total_queries_24hr = $row["count"] ?? 0;

    // User messages in 24h (for Successful Response Rate)
    // Since only bot responses are logged with sender_type='bot', every logged message in chat_messages represents one user query/interaction.
    $user_messages_24hr = $total_queries_24hr;

    // Non-fallback bot replies in 24h
    $stmtNonFallback = $conn->prepare(
        "SELECT COUNT(*) as count FROM chat_messages WHERE sender_type='bot' AND (response_type IS NULL OR response_type != 'fallback') AND created_at >= ?",
    );
    $stmtNonFallback->bind_param("s", $time_threshold_24hr);
    $stmtNonFallback->execute();
    $successful_replies_24hr =
        (int) ($stmtNonFallback->get_result()->fetch_assoc()["count"] ?? 0);

    $stmtSess = $conn->prepare(
        "SELECT COUNT(DISTINCT session_id) as count FROM web_sessions WHERE start_time >= ?",
    );
    $stmtSess->bind_param("s", $time_threshold_24hr);
    $stmtSess->execute();
    $web_sessions_24hr = $stmtSess->get_result()->fetch_assoc()["count"] ?? 0;

    $avg_messages_per_session =
        $web_sessions_24hr > 0
        ? round($total_queries_24hr / $web_sessions_24hr, 1)
        : 0;
} catch (Exception $e) {
    error_log("Query failed: " . $e->getMessage());
    $total_queries_24hr = 0;
    $user_messages_24hr = 0;
    $successful_replies_24hr = 0;
    $avg_messages_per_session = 0;
}

// ── Computed Dashboard Metrics ──
// Successful Response Rate = non-fallback bot replies / total user messages
$response_rate =
    $user_messages_24hr > 0
    ? round(($successful_replies_24hr / $user_messages_24hr) * 100, 1)
    : 0;

$avg_session_duration = 0;
$avg_dur_available = false;
if (!empty($user_sessions)) {
    // Only average sessions that have a real duration recorded (> 0)
    $real_durations = array_filter(
        array_column($user_sessions, "session_duration"),
        fn($d) => $d !== null && $d > 0,
    );
    if (!empty($real_durations)) {
        $avg_session_duration = round(
            array_sum($real_durations) / count($real_durations),
        );
        $avg_dur_available = true;
    }
}
$avg_dur_hrs = floor($avg_session_duration / 3600);
$avg_dur_min = floor(($avg_session_duration % 3600) / 60);
$avg_dur_sec = $avg_session_duration % 60;
// Build human-readable duration string
if (!$avg_dur_available) {
    $avg_dur_display = "N/A";
} elseif ($avg_dur_hrs > 0) {
    $avg_dur_display = "{$avg_dur_hrs}h {$avg_dur_min}m";
} elseif ($avg_dur_min > 0) {
    $avg_dur_display = "{$avg_dur_min}m {$avg_dur_sec}s";
} else {
    $avg_dur_display = "{$avg_dur_sec}s";
}

$peak_hour_label = "N/A";
$peak_hour_count = 0;
if (!empty($chatlogs_by_hour)) {
    $max_idx = 0;
    foreach ($chatlogs_by_hour as $i => $h) {
        if ($h["count"] > ($chatlogs_by_hour[$max_idx]["count"] ?? 0)) {
            $max_idx = $i;
        }
    }
    $peak_hour_label = sprintf("%02d:00", $chatlogs_by_hour[$max_idx]["hour"]);
    $peak_hour_count = $chatlogs_by_hour[$max_idx]["count"];
}

$typed_up_count = 0;
$typed_total = 0;
foreach ($feedback_counts as $fc) {
    $typed_total += (int) $fc["count"];
    if (in_array($fc["rating"] ?? "", ["excellent", "good"])) {
        $typed_up_count += (int) $fc["count"];
    }
}
// Combine typed + reactions for total positive feedback
$thumbs_up_count = $typed_up_count + $thumbs_up_reactions;
$thumbs_total = $typed_total + $total_reactions;
$thumbs_up_ratio =
    $thumbs_total > 0 ? round(($thumbs_up_count / $thumbs_total) * 100, 1) : 0;

// ── Previous Period Comparisons (24h-ago window for trend indicators) ──
try {
    $prev_start = date("Y-m-d H:i:s", strtotime("-48 hours"));
    $prev_end = date("Y-m-d H:i:s", strtotime("-24 hours"));
    $stmt = $conn->prepare(
        "SELECT COUNT(*) as cnt FROM chat_messages WHERE created_at >= ? AND created_at < ?",
    );
    $stmt->bind_param("ss", $prev_start, $prev_end);
    $stmt->execute();
    $prev_queries_24hr = (int) ($stmt->get_result()->fetch_assoc()["cnt"] ?? 0);
    $stmt = $conn->prepare(
        "SELECT COUNT(*) as cnt FROM web_sessions WHERE start_time >= ? AND start_time < ?",
    );
    $stmt->bind_param("ss", $prev_start, $prev_end);
    $stmt->execute();
    $prev_sessions_24hr =
        (int) ($stmt->get_result()->fetch_assoc()["cnt"] ?? 0);
    $stmt = $conn->prepare(
        "SELECT COUNT(*) as cnt FROM feedback WHERE created_at >= ? AND created_at < ?",
    );
    $stmt->bind_param("ss", $prev_start, $prev_end);
    $stmt->execute();
    $prev_feedback_24hr =
        (int) ($stmt->get_result()->fetch_assoc()["cnt"] ?? 0);
    $stmt = $conn->prepare(
        "SELECT COUNT(*) as cnt FROM error_logs WHERE created_at >= ? AND created_at < ?",
    );
    $stmt->bind_param("ss", $prev_start, $prev_end);
    $stmt->execute();
    $prev_errors_24hr = (int) ($stmt->get_result()->fetch_assoc()["cnt"] ?? 0);
    $trend_queries =
        $prev_queries_24hr > 0
        ? round(
            (($total_queries_24hr - $prev_queries_24hr) /
                $prev_queries_24hr) *
                100,
            1,
        )
        : ($total_queries_24hr > 0
            ? 100
            : 0);
    $trend_sessions =
        $prev_sessions_24hr > 0
        ? round(
            (($web_sessions_24hr - $prev_sessions_24hr) /
                $prev_sessions_24hr) *
                100,
            1,
        )
        : ($web_sessions_24hr > 0
            ? 100
            : 0);
    $trend_feedback =
        $prev_feedback_24hr > 0
        ? round(
            (($feedback_today - $prev_feedback_24hr) /
                $prev_feedback_24hr) *
                100,
            1,
        )
        : ($feedback_today > 0
            ? 100
            : 0);
    $trend_errors =
        $prev_errors_24hr > 0
        ? round(
            (($errors_24hr - $prev_errors_24hr) / $prev_errors_24hr) * 100,
            1,
        )
        : ($errors_24hr > 0
            ? 100
            : 0);
} catch (Exception $e) {
    error_log("Trend comparisons failed: " . $e->getMessage());
    $trend_queries = $trend_sessions = $trend_feedback = $trend_errors = 0;
}

// ── 7-day sparkline data ──
try {
    $spark_res = $conn->query(
        "SELECT DATE(created_at) as d, COUNT(*) as c FROM chat_messages WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(created_at) ORDER BY d",
    );
    $spark_queries_data = $spark_res
        ? array_column($spark_res->fetch_all(MYSQLI_ASSOC), "c")
        : [];
    $spark_res = $conn->query(
        "SELECT DATE(start_time) as d, COUNT(*) as c FROM web_sessions WHERE start_time >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(start_time) ORDER BY d",
    );
    $spark_sessions_data = $spark_res
        ? array_column($spark_res->fetch_all(MYSQLI_ASSOC), "c")
        : [];
    $spark_res = $conn->query(
        "SELECT DATE(created_at) as d, COUNT(*) as c FROM feedback WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(created_at) ORDER BY d",
    );
    $spark_feedback_data = $spark_res
        ? array_column($spark_res->fetch_all(MYSQLI_ASSOC), "c")
        : [];
    $spark_res = $conn->query(
        "SELECT DATE(created_at) as d, COUNT(*) as c FROM error_logs WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(created_at) ORDER BY d",
    );
    $spark_errors_data = $spark_res
        ? array_column($spark_res->fetch_all(MYSQLI_ASSOC), "c")
        : [];
} catch (Exception $e) {
    error_log("Sparkline data failed: " . $e->getMessage());
    $spark_queries_data = $spark_sessions_data = $spark_feedback_data = $spark_errors_data = [];
}

// ── Recent Activity Feed (System Events) ──
try {
    $activity_sql = "
        (SELECT 'admin_action' as type, CONCAT(action, ': ', LEFT(COALESCE(description,''), 50)) as detail, timestamp as created_at FROM admin_activity_logs ORDER BY timestamp DESC LIMIT 3)
        UNION ALL
        (SELECT 'password_reset' as type, CONCAT('Password reset requested (admin_id: MMU-chtbt-', LPAD(admin_id, 3, '0'), ')') as detail, created_at FROM admin_password_resets ORDER BY created_at DESC LIMIT 2)
        UNION ALL
        (SELECT 'fallback' as type, CONCAT('AI fallback triggered: ', LEFT(COALESCE(user_message,''), 45)) as detail, created_at FROM chat_messages WHERE response_type='fallback' ORDER BY created_at DESC LIMIT 2)
        UNION ALL
        (SELECT 'error' as type, CONCAT(error_type, ': ', LEFT(COALESCE(error_message,''), 50)) as detail, created_at FROM error_logs ORDER BY created_at DESC LIMIT 2)
        UNION ALL
        (SELECT 'kb_update' as type, CONCAT('Knowledge base updated: ', LEFT(COALESCE(page_title,''), 50)) as detail, scraped_at as created_at FROM scraped_content WHERE status IN ('processed','indexed') ORDER BY scraped_at DESC LIMIT 1)
        ORDER BY created_at DESC LIMIT 10
    ";
    $activity_res = $conn->query($activity_sql);
    $recent_activity = $activity_res
        ? $activity_res->fetch_all(MYSQLI_ASSOC)
        : [];
} catch (Exception $e) {
    error_log("Activity feed failed: " . $e->getMessage());
    $recent_activity = [];
}

// ── Top Intents ──
try {
    $intent_res = $conn->query(
        "SELECT intent_classification as intent, COUNT(*) as cnt FROM chat_messages WHERE intent_classification IS NOT NULL AND intent_classification != '' GROUP BY intent_classification ORDER BY cnt DESC LIMIT 5",
    );
    $top_intents = $intent_res ? $intent_res->fetch_all(MYSQLI_ASSOC) : [];
    $total_intent_msgs = array_sum(array_column($top_intents, "cnt"));
} catch (Exception $e) {
    error_log("Top intents failed: " . $e->getMessage());
    $top_intents = [];
    $total_intent_msgs = 0;
}

// ── Anomaly Detection ──
try {
    $avg_err_res = $conn->query(
        "SELECT ROUND(AVG(cnt),1) as avg_err FROM (SELECT COUNT(*) as cnt FROM error_logs WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(created_at)) t",
    );
    $avg_daily_errors = $avg_err_res
        ? (float) ($avg_err_res->fetch_assoc()["avg_err"] ?? 0)
        : 0;
    $error_spike =
        $avg_daily_errors > 0 && $errors_24hr > $avg_daily_errors * 1.5;
    $low_sat_res = $conn->query(
        "SELECT ROUND(AVG(satisfaction_score),2) as avg_sat FROM conversation_metadata WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
    );
    $recent_satisfaction = $low_sat_res
        ? $low_sat_res->fetch_assoc()["avg_sat"] ?? null
        : null;
    $low_satisfaction =
        $recent_satisfaction !== null && (float) $recent_satisfaction < 3.0;
    $old_unresolved_res = $conn->query(
        "SELECT COUNT(*) as cnt FROM user_queries WHERE status='pending' AND submitted_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
    );
    $old_unresolved = $old_unresolved_res
        ? (int) ($old_unresolved_res->fetch_assoc()["cnt"] ?? 0)
        : 0;
} catch (Exception $e) {
    error_log("Anomaly detection failed: " . $e->getMessage());
    $error_spike = false;
    $low_satisfaction = false;
    $recent_satisfaction = null;
    $old_unresolved = 0;
    $avg_daily_errors = 0;
}

// ══════════════════════════════════════════════════════════
// ── PREDICTIONS SECTION DATA ──
// ══════════════════════════════════════════════════════════

// 1. Usage Forecast — 30-day daily counts + linear regression
try {
    $fc_res = $conn->query(
        "SELECT DATE(created_at) as d, COUNT(*) as c FROM chat_messages WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY DATE(created_at) ORDER BY d",
    );
    $fc_daily = [];
    if ($fc_res) {
        while ($r = $fc_res->fetch_assoc()) {
            $fc_daily[] = (int) $r["c"];
        }
    }
    $fc_n = count($fc_daily);
    // Linear regression: y = a + b*x
    $fc_slope = 0;
    $fc_intercept = 0;
    $fc_r2 = 0;
    if ($fc_n >= 3) {
        $sum_x = $sum_y = $sum_xy = $sum_x2 = 0;
        for ($i = 0; $i < $fc_n; $i++) {
            $sum_x += $i;
            $sum_y += $fc_daily[$i];
            $sum_xy += $i * $fc_daily[$i];
            $sum_x2 += $i * $i;
        }
        $denom = $fc_n * $sum_x2 - $sum_x * $sum_x;
        if ($denom != 0) {
            $fc_slope = ($fc_n * $sum_xy - $sum_x * $sum_y) / $denom;
            $fc_intercept = ($sum_y - $fc_slope * $sum_x) / $fc_n;
        }
        // R² for confidence
        $mean_y = $sum_y / $fc_n;
        $ss_tot = $ss_res = 0;
        for ($i = 0; $i < $fc_n; $i++) {
            $pred = $fc_intercept + $fc_slope * $i;
            $ss_res += pow($fc_daily[$i] - $pred, 2);
            $ss_tot += pow($fc_daily[$i] - $mean_y, 2);
        }
        $fc_r2 = $ss_tot > 0 ? max(0, 1 - $ss_res / $ss_tot) : 0;
    }
    $fc_last_val = $fc_n > 0 ? end($fc_daily) : 0;
    $fc_predict_24h = max(0, round($fc_intercept + $fc_slope * $fc_n));
    $fc_predict_7d = max(
        0,
        round(
            array_sum(
                array_map(
                    fn($d) => max(0, $fc_intercept + $fc_slope * ($fc_n + $d)),
                    range(0, 6),
                ),
            ),
        ),
    );
    $fc_predict_30d = max(
        0,
        round(
            array_sum(
                array_map(
                    fn($d) => max(0, $fc_intercept + $fc_slope * ($fc_n + $d)),
                    range(0, 29),
                ),
            ),
        ),
    );
    $fc_confidence = $fc_r2 > 0.7 ? "High" : ($fc_r2 > 0.4 ? "Medium" : "Low");
    $fc_conf_color =
        $fc_r2 > 0.7 ? "#10b981" : ($fc_r2 > 0.4 ? "#f59e0b" : "#ef4444");
} catch (Exception $e) {
    error_log("Forecast failed: " . $e->getMessage());
    $fc_daily = [];
    $fc_predict_24h = 0;
    $fc_predict_7d = 0;
    $fc_predict_30d = 0;
    $fc_confidence = "Low";
    $fc_conf_color = "#ef4444";
    $fc_r2 = 0;
    $fc_slope = 0;
}

// 2. User Growth — sessions per day trend (14d)
try {
    $ug_res = $conn->query(
        "SELECT DATE(start_time) as d, COUNT(*) as c FROM web_sessions WHERE start_time >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) GROUP BY DATE(start_time) ORDER BY d",
    );
    $ug_daily = [];
    $ug_labels = [];
    if ($ug_res) {
        while ($r = $ug_res->fetch_assoc()) {
            $ug_daily[] = (int) $r["c"];
            $ug_labels[] = date("M j", strtotime($r["d"]));
        }
    }
    $ug_n = count($ug_daily);
    // First half vs second half growth rate
    $ug_first_half =
        $ug_n >= 4 ? array_sum(array_slice($ug_daily, 0, intdiv($ug_n, 2))) : 0;
    $ug_second_half =
        $ug_n >= 4 ? array_sum(array_slice($ug_daily, intdiv($ug_n, 2))) : 0;
    $ug_growth_pct =
        $ug_first_half > 0
        ? round(
            (($ug_second_half - $ug_first_half) / $ug_first_half) * 100,
            1,
        )
        : 0;
    $ug_total_sessions =
        $conn->query("SELECT COUNT(*) as c FROM web_sessions")->fetch_assoc()["c"] ?? 0;
    $ug_confidence = $ug_n >= 10 ? "High" : ($ug_n >= 5 ? "Medium" : "Low");
} catch (Exception $e) {
    error_log("User growth failed: " . $e->getMessage());
    $ug_daily = [];
    $ug_labels = [];
    $ug_growth_pct = 0;
    $ug_total_sessions = 0;
    $ug_confidence = "Low";
}

// 3. Peak Load — busiest hours and days
try {
    $pl_hour_res = $conn->query(
        "SELECT HOUR(created_at) as hr, COUNT(*) as c FROM chat_messages GROUP BY hr ORDER BY c DESC LIMIT 5",
    );
    $pl_hours = $pl_hour_res ? $pl_hour_res->fetch_all(MYSQLI_ASSOC) : [];
    $pl_day_res = $conn->query(
        "SELECT DAYNAME(created_at) as day_name, COUNT(*) as c FROM chat_messages GROUP BY day_name ORDER BY c DESC LIMIT 5",
    );
    $pl_days = $pl_day_res ? $pl_day_res->fetch_all(MYSQLI_ASSOC) : [];
    $pl_confidence = count($pl_hours) >= 3 ? "High" : "Medium";
} catch (Exception $e) {
    error_log("Peak load failed: " . $e->getMessage());
    $pl_hours = [];
    $pl_days = [];
    $pl_confidence = "Low";
}

// 4. Model Performance
try {
    $mp_res = $conn->query("
        SELECT model_used,
            COUNT(*) as call_count,
            ROUND(AVG(response_time_ms)) as avg_latency,
            ROUND(AVG(confidence_score) * 100, 1) as avg_confidence
        FROM chat_messages
        WHERE model_used IS NOT NULL AND model_used != ''
        GROUP BY model_used
        ORDER BY call_count DESC
    ");
    $mp_models = $mp_res ? $mp_res->fetch_all(MYSQLI_ASSOC) : [];
    $mp_confidence = count($mp_models) > 0 ? "High" : "Low";
} catch (Exception $e) {
    error_log("Model perf failed: " . $e->getMessage());
    $mp_models = [];
    $mp_confidence = "Low";
}

// 5. Anomaly Detection (extended)
$anomalies = [];
if ($error_spike) {
    $anomalies[] = [
        "type" => "critical",
        "icon" => "fa-bolt",
        "msg" => "Error spike: {$errors_24hr} errors today vs {$avg_daily_errors} daily average",
        "reason" => "Possible API failure or backend issue",
        "link" => "system_logs.php?level=error&range=24h",
    ];
}
if ($low_satisfaction) {
    $anomalies[] = [
        "type" => "warning",
        "icon" => "fa-face-frown",
        "msg" => "Satisfaction dropped to {$recent_satisfaction}/5 in last 24h",
        "reason" => "Response quality may be degrading",
        "link" => "feedback.php?rating=bad",
    ];
}
// Traffic anomaly: today vs 7-day avg
try {
    $today_msgs_res = $conn->query(
        "SELECT COUNT(*) as c FROM chat_messages WHERE DATE(created_at) = CURDATE()",
    );
    $today_msgs = $today_msgs_res
        ? (int) $today_msgs_res->fetch_assoc()["c"]
        : 0;
    $avg7d_res = $conn->query(
        "SELECT ROUND(AVG(cnt)) as a FROM (SELECT COUNT(*) as cnt FROM chat_messages WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND DATE(created_at) < CURDATE() GROUP BY DATE(created_at)) t",
    );
    $avg7d_msgs = $avg7d_res ? (int) ($avg7d_res->fetch_assoc()["a"] ?? 0) : 0;
    if ($avg7d_msgs > 0 && $today_msgs > $avg7d_msgs * 2) {
        $anomalies[] = [
            "type" => "warning",
            "icon" => "fa-arrow-trend-up",
            "msg" => "Traffic spike: {$today_msgs} messages today vs {$avg7d_msgs} daily avg",
            "reason" => "Unusual surge in user activity",
            "link" => "user_interactions.php",
        ];
    } elseif ($avg7d_msgs > 5 && $today_msgs < $avg7d_msgs * 0.3) {
        $anomalies[] = [
            "type" => "warning",
            "icon" => "fa-arrow-trend-down",
            "msg" => "Traffic drop: {$today_msgs} messages today vs {$avg7d_msgs} daily avg",
            "reason" => "Possible system outage or user drop-off",
            "link" => "user_interactions.php",
        ];
    }
} catch (Exception $e) {
    /* ignore */
}
$an_confidence = count($anomalies) > 0 ? "High" : "Medium";

// 6. Smart Recommendations
// Query avg response time from chat_messages (safe default = 0)
try {
    $rt_res = $conn->query(
        "SELECT ROUND(AVG(response_time_ms)) as avg_rt FROM chat_messages WHERE response_time_ms IS NOT NULL AND response_time_ms > 0",
    );
    $avg_response_time = $rt_res
        ? (int) ($rt_res->fetch_assoc()["avg_rt"] ?? 0)
        : 0;
} catch (Exception $e) {
    error_log("Avg response time query failed: " . $e->getMessage());
    $avg_response_time = 0;
}

$recommendations = [];
if (!empty($pl_hours) && $pl_hours[0]["c"] > 50) {
    $peak_h = sprintf("%02d:00", $pl_hours[0]["hr"]);
    $recommendations[] = [
        "icon" => "fa-server",
        "msg" => "Consider scaling during peak hour ({$peak_h}) — {$pl_hours[0]["c"]} interactions recorded",
        "priority" => "medium",
    ];
}
if ($avg_response_time > 3000) {
    $recommendations[] = [
        "icon" => "fa-gauge-high",
        "msg" => "Avg response time is {$avg_response_time}ms — optimize slow-response models or add caching",
        "priority" => "high",
    ];
}
if ($thumbs_up_ratio < 60 && $thumbs_total > 10) {
    $recommendations[] = [
        "icon" => "fa-thumbs-down",
        "msg" => "Only {$thumbs_up_ratio}% positive feedback — review response quality and consider UI improvements",
        "priority" => "high",
    ];
}
if ($missed_24hr > 0) {
    $recommendations[] = [
        "icon" => "fa-robot",
        "msg" => "{$missed_24hr} fallback responses in 24h — expand knowledge base for unhandled topics",
        "priority" => "medium",
    ];
}
if ($ug_growth_pct < -20) {
    $recommendations[] = [
        "icon" => "fa-chart-line",
        "msg" => "User engagement declining ({$ug_growth_pct}%) — consider outreach or UI improvements",
        "priority" => "high",
    ];
}
if (empty($recommendations)) {
    $recommendations[] = [
        "icon" => "fa-check-circle",
        "msg" =>
        "System performing well — no critical improvements needed at this time",
        "priority" => "low",
    ];
}

// ══════════════════════════════════════════════════════════
// ── ADDITIONAL DATA FOR ENHANCED DASHBOARD ──
// ══════════════════════════════════════════════════════════

// ── Active Users: Hourly Baseline ──
try {
    $current_hour = (int) date("G");
    $baseline_res = $conn->query("
        SELECT ROUND(AVG(cnt)) as avg_count 
        FROM (
            SELECT HOUR(start_time) as hr, DATE(start_time) as d, COUNT(DISTINCT session_id) as cnt 
            FROM web_sessions 
            WHERE start_time >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            AND HOUR(start_time) = {$current_hour}
            GROUP BY DATE(start_time)
        ) t
    ");
    $hourly_baseline = $baseline_res
        ? (int) ($baseline_res->fetch_assoc()["avg_count"] ?? 0)
        : 0;
} catch (Exception $e) {
    error_log("Hourly baseline failed: " . $e->getMessage());
    $hourly_baseline = 0;
}

// ── KB Health: Real Check ──
try {
    // Last scrape date and outcome
    $kb_last_scrape_res = $conn->query(
        "SELECT page_title, scraped_at, status FROM scraped_content ORDER BY scraped_at DESC LIMIT 1",
    );
    $kb_last_scrape = $kb_last_scrape_res
        ? $kb_last_scrape_res->fetch_assoc()
        : null;

    $kb_enrich_pending = 0;
    $kb_enrich_pending_res = @$conn->query(
        "SELECT COUNT(*) as c FROM scraped_content WHERE enrichment_status IN ('pending','failed')",
    );
    if ($kb_enrich_pending_res) {
        $kb_enrich_pending = (int) ($kb_enrich_pending_res->fetch_assoc()["c"] ?? 0);
    }

    // Orphaned chunks — never matched a user query (approximate: chunks whose title never appears in intent_classification)
    $kb_orphan_res = $conn->query("
        SELECT COUNT(*) as cnt FROM entity_knowledge_chunks ekc
        WHERE ekc.is_active = 1
        AND NOT EXISTS (
            SELECT 1 FROM chat_messages cm 
            WHERE cm.intent_classification IS NOT NULL 
            AND cm.intent_classification != ''
            AND cm.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            AND LOWER(cm.intent_classification) LIKE CONCAT('%', LOWER(SUBSTRING_INDEX(ekc.title, ' ', 2)), '%')
        )
    ");
    $kb_orphaned_chunks = $kb_orphan_res
        ? (int) ($kb_orphan_res->fetch_assoc()["cnt"] ?? 0)
        : 0;

    // Entity categories with zero intent matches in 30 days
    $kb_dormant_res = $conn->query("
        SELECT COUNT(*) as cnt FROM university_entities ue
        WHERE ue.is_active = 1
        AND NOT EXISTS (
            SELECT 1 FROM chat_messages cm 
            WHERE cm.intent_classification IS NOT NULL 
            AND cm.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            AND LOWER(cm.intent_classification) LIKE CONCAT('%', LOWER(ue.name), '%')
        )
    ");
    $kb_dormant_entities = $kb_dormant_res
        ? (int) ($kb_dormant_res->fetch_assoc()["cnt"] ?? 0)
        : 0;
} catch (Exception $e) {
    error_log("KB health queries failed: " . $e->getMessage());
    $kb_last_scrape = null;
    $kb_orphaned_chunks = 0;
    $kb_dormant_entities = 0;
}

// ── Conversations Needing Review ──
try {
    $review_res = $conn->query("
        SELECT s.session_id, s.start_time, s.duration_seconds,
            (SELECT COUNT(*) FROM chat_messages cm WHERE cm.session_id = s.session_id AND cm.response_type = 'fallback') as fallback_count,
            (SELECT ROUND(AVG(cmd.satisfaction_score), 1) FROM conversation_metadata cmd WHERE cmd.session_id = s.session_id) as sat_score,
            (SELECT COUNT(*) FROM chat_messages cm2 WHERE cm2.session_id = s.session_id) as msg_count
        FROM web_sessions s
        WHERE s.start_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        HAVING fallback_count >= 3 OR (sat_score IS NOT NULL AND sat_score <= 2)
        ORDER BY s.start_time DESC
        LIMIT 10
    ");
    $conversations_needing_review = $review_res
        ? $review_res->fetch_all(MYSQLI_ASSOC)
        : [];
} catch (Exception $e) {
    error_log("Conversations needing review: " . $e->getMessage());
    $conversations_needing_review = [];
}

// ── Actionable Alert Data ──
// Old unresolved query texts (for inline display in alerts)
$old_unresolved_queries = [];
if ($old_unresolved > 0) {
    try {
        $oq_res = $conn->query(
            "SELECT query_id, query_text, submitted_at FROM user_queries WHERE status='pending' AND submitted_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY submitted_at ASC LIMIT 5",
        );
        $old_unresolved_queries = $oq_res
            ? $oq_res->fetch_all(MYSQLI_ASSOC)
            : [];
    } catch (Exception $e) {
        $old_unresolved_queries = [];
    }
}

// Recent error messages (for error spike alert)
$recent_error_messages = [];
if ($error_spike) {
    try {
        $err_msg_res = $conn->query(
            "SELECT error_type, error_message, created_at FROM error_logs ORDER BY created_at DESC LIMIT 3",
        );
        $recent_error_messages = $err_msg_res
            ? $err_msg_res->fetch_all(MYSQLI_ASSOC)
            : [];
    } catch (Exception $e) {
        $recent_error_messages = [];
    }
}

// ── Feedback data for Feedback & Insights card ──
// Weekly feedback
try {
    $fb_weekly_res = $conn->query("
        SELECT YEARWEEK(created_at, 1) as week, rating, COUNT(*) as count 
        FROM feedback 
        GROUP BY YEARWEEK(created_at, 1), rating 
        ORDER BY week
    ");
    $feedback_weekly = $fb_weekly_res
        ? $fb_weekly_res->fetch_all(MYSQLI_ASSOC)
        : [];

    $fb_monthly_res = $conn->query("
        SELECT DATE_FORMAT(created_at, '%Y-%m') as month, rating, COUNT(*) as count 
        FROM feedback 
        GROUP BY DATE_FORMAT(created_at, '%Y-%m'), rating 
        ORDER BY month
    ");
    $feedback_monthly = $fb_monthly_res
        ? $fb_monthly_res->fetch_all(MYSQLI_ASSOC)
        : [];

    // Reactions weekly
    $rx_weekly_res = $conn->query("
        SELECT YEARWEEK(created_at, 1) as week, reaction_type, COUNT(*) as count 
        FROM message_reactions 
        GROUP BY YEARWEEK(created_at, 1), reaction_type 
        ORDER BY week
    ");
    $reactions_weekly = $rx_weekly_res
        ? $rx_weekly_res->fetch_all(MYSQLI_ASSOC)
        : [];

    // Reactions monthly
    $rx_monthly_res = $conn->query("
        SELECT DATE_FORMAT(created_at, '%Y-%m') as month, reaction_type, COUNT(*) as count 
        FROM message_reactions 
        GROUP BY DATE_FORMAT(created_at, '%Y-%m'), reaction_type 
        ORDER BY month
    ");
    $reactions_monthly = $rx_monthly_res
        ? $rx_monthly_res->fetch_all(MYSQLI_ASSOC)
        : [];
} catch (Exception $e) {
    error_log("Feedback extra data failed: " . $e->getMessage());
    $feedback_weekly = $feedback_monthly = $reactions_weekly = $reactions_monthly = [];
}

// Process weekly & monthly feedback like daily
$fb_weekly_likes = $fb_weekly_dislikes = [];
foreach ($feedback_weekly as $fb) {
    $w = $fb["week"];
    if (!isset($fb_weekly_likes[$w])) {
        $fb_weekly_likes[$w] = 0;
        $fb_weekly_dislikes[$w] = 0;
    }
    if (in_array($fb["rating"] ?? "", ["excellent", "good"])) {
        $fb_weekly_likes[$w] += (int) $fb["count"];
    } else {
        $fb_weekly_dislikes[$w] += (int) $fb["count"];
    }
}
foreach ($reactions_weekly as $rx) {
    $w = $rx["week"];
    if (!isset($fb_weekly_likes[$w])) {
        $fb_weekly_likes[$w] = 0;
        $fb_weekly_dislikes[$w] = 0;
    }
    if (in_array($rx["reaction_type"], ["thumbs_up", "helpful", "accurate"])) {
        $fb_weekly_likes[$w] += (int) $rx["count"];
    } else {
        $fb_weekly_dislikes[$w] += (int) $rx["count"];
    }
}
ksort($fb_weekly_likes);
ksort($fb_weekly_dislikes);

$fb_monthly_likes = $fb_monthly_dislikes = [];
foreach ($feedback_monthly as $fb) {
    $m = $fb["month"];
    if (!isset($fb_monthly_likes[$m])) {
        $fb_monthly_likes[$m] = 0;
        $fb_monthly_dislikes[$m] = 0;
    }
    if (in_array($fb["rating"] ?? "", ["excellent", "good"])) {
        $fb_monthly_likes[$m] += (int) $fb["count"];
    } else {
        $fb_monthly_dislikes[$m] += (int) $fb["count"];
    }
}
foreach ($reactions_monthly as $rx) {
    $m = $rx["month"];
    if (!isset($fb_monthly_likes[$m])) {
        $fb_monthly_likes[$m] = 0;
        $fb_monthly_dislikes[$m] = 0;
    }
    if (in_array($rx["reaction_type"], ["thumbs_up", "helpful", "accurate"])) {
        $fb_monthly_likes[$m] += (int) $rx["count"];
    } else {
        $fb_monthly_dislikes[$m] += (int) $rx["count"];
    }
}
ksort($fb_monthly_likes);
ksort($fb_monthly_dislikes);

// Prepare data for JSON response
$data = ["not_yet_count" => $pushed_awaiting];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Admin Chatbot Panel</title>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="shortcut icon" href="images/mmu_logo_- no bg.png" type="image/x-icon">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700%7CJosefin+Sans:600,700"
        rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">

    <!-- Bootstrap 5.3 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">

    <!-- Font Awesome 6 (latest stable free version as of 2025/2026) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"
        integrity="sha512-Kc323vGBEqzTmouAECnVceyQqyqdsSiqLQISBL29aUW4U/M7pSPA/gEUZQqv1cwx4OnYxTxve5UMg5GT6L4JJg=="
        crossorigin="anonymous" referrerpolicy="no-referrer" />

    <!-- Your custom styles (load AFTER bootstrap & font-awesome so they can override if needed) -->
    <link href="css/style.css?v=1775081173" rel="stylesheet" />
    <link href="css/style-mob.css" rel="stylesheet" />
    <link href="css/admin.css" rel="stylesheet" />

    <!-- FA 6.6.0 already loaded above — removed duplicate 6.4.0 -->
    <link href="css/admin-profile.css" rel="stylesheet" />

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <!-- ────────────────────────────────────────────────
         FORCE Font Awesome 6 to win over any conflicting styles
         (Materialize leftovers, bootstrap resets, custom * selectors, etc.)
    ──────────────────────────────────────────────── -->
    <style>
        /* Make sure FA6 font is used everywhere icons appear */
        [class*="fa-"],
        .fa,
        .fas,
        .far,
        .fab,
        .fa-solid,
        .fa-regular,
        .fa-brands {
            font-family: "Font Awesome 6 Free" !important;
            font-weight: 900 !important;
            /* required for solid style */
            font-style: normal !important;
            font-variant: normal !important;
            text-rendering: auto;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            display: inline-block !important;
            line-height: 1;
        }
    </style>


    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap');


        :root {
            --bg-primary: #f4f7fa;
            --bg-secondary: #eef2f7;
            --bg-card: #ffffff;
            --bg-card-hover: #f8fafc;
            --text-primary: #1f2937;
            --text-secondary: #6b7280;
            --accent-cyan: #18569d;
            --accent-purple: #7c3aed;
            --accent-pink: #ec4899;
            --accent-orange: #f59e0b;
            --accent-green: #10b981;
            --accent-yellow: #fbbf24;
            --gradient-1: linear-gradient(135deg, #002147 0%, #05356b 100%);
            --gradient-2: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            --gradient-3: linear-gradient(135deg, #002147 0%, #18569d 100%);
            --gradient-4: linear-gradient(135deg, #10b981 0%, #059669 100%);
            --gradient-5: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            --gradient-rainbow: linear-gradient(90deg, #002147, #05356b, #18569d, #3b82f6, #60a5fa);
        }




        @keyframes backgroundShift {

            0%,
            100% {
                opacity: 1;
                transform: scale(1);
            }

            50% {
                opacity: 0.8;
                transform: scale(1.1);
            }
        }

        .dashboard-container {
            position: relative;
            z-index: 1;
            max-width: 1600px;
            margin: 0 auto;
            padding: 40px 24px;
        }

        /* Card Styles */
        .chart-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid rgba(0, 33, 71, 0.08);
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
        }

        .chart-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--gradient-1);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .chart-card:hover::before {
            opacity: 1;
        }

        .chart-card:hover {
            transform: translateY(-4px);
            border-color: rgba(0, 33, 71, 0.12);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .card-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: #002147;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .card-subtitle {
            font-size: 0.85rem;
            color: #6b7280;
            font-weight: 400;
        }

        .chart-grid-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 24px;
        }

        .card-title i {
            color: #18569d;
            font-size: 1.2rem;
        }

        /* Stat Cards Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 16px;
            margin: 20px 15px;
        }

        .stat-card-link {
            text-decoration: none;
            color: inherit;
            display: flex;
            height: 100%;
        }

        .stat-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 16px;
            border: 1px solid rgba(0, 33, 71, 0.08);
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            flex: 1;
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .stat-card::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--gradient-1);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .stat-card:hover::after {
            opacity: 1;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            border-color: rgba(0, 33, 71, 0.12);
            box-shadow: 0 8px 24px rgba(0, 33, 71, 0.1);
        }

        @keyframes rotate {
            from {
                transform: rotate(0deg);
            }

            to {
                transform: rotate(360deg);
            }
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--text-secondary);
            font-weight: 500;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 800;
            background: var(--gradient-1);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1;
            margin-bottom: 6px;
        }

        .stat-change {
            font-size: 0.75rem;
            color: var(--accent-green);
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .stat-change.negative {
            color: var(--accent-pink);
        }

        /* Chart Canvas Wrapper */
        .chart-wrapper {
            position: relative;
            width: 100%;
            margin: 20px 0;
        }

        canvas {
            filter: drop-shadow(0 4px 20px rgba(0, 0, 0, 0.3));
        }

        /* Insight Box */
        .insight-box {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.05) 100%);
            border-left: 3px solid var(--accent-cyan);
            border-radius: 12px;
            padding: 20px;
            margin-top: 24px;
            position: relative;
            overflow: hidden;
        }

        .insight-box::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 3px;
            height: 100%;
            background: var(--gradient-3);
            animation: slideUpDown 3s ease-in-out infinite;
        }

        @keyframes slideUpDown {

            0%,
            100% {
                transform: translateY(-100%);
            }

            50% {
                transform: translateY(100%);
            }
        }

        .insight-box p {
            margin: 10px 0;
            color: var(--text-secondary);
            font-size: 0.95rem;
            line-height: 1.6;
            position: relative;
            padding-left: 20px;
        }

        .insight-box p::before {
            content: '●';
            position: absolute;
            left: 0;
            background: var(--gradient-3);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 1.2rem;
        }

        .insight-box strong {
            color: #002147;
            font-weight: 600;
        }

        /* Grid Layouts */
        .chart-grid-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        .compact-card::before {
            content: '';
            position: absolute;
            inset: 0;
            background: var(--gradient-3);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .compact-card:hover::before {
            opacity: 0.02;
        }

        .compact-card:hover {
            transform: translateY(-3px);
            border-color: rgba(0, 33, 71, 0.12);
            box-shadow: 0 6px 20px rgba(0, 33, 71, 0.08);
        }

        .compact-card h3 {
            font-size: 1.05rem;
            font-weight: 700;
            text-align: center;
            margin-bottom: 16px;
            color: #002147;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f5f9;
        }

        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* Loading Animation */
        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.5;
            }
        }



        /* Fade-in animation */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(40px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* State-change animation only — no entrance animations */
        @keyframes statValueUpdate {
            0% {
                opacity: 0.3;
                transform: scale(0.95);
            }

            100% {
                opacity: 1;
                transform: scale(1);
            }
        }

        .stat-value-updated {
            animation: statValueUpdate 0.3s ease-out;
        }

        /* Pill badge */
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            background: var(--gradient-4);
            color: #fff;
        }

        /* ── Dashboard Control Bar ── */
        .dash-control-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            background: #ffffff;
            border-radius: 12px;
            padding: 14px 20px;
            margin-bottom: 24px;
            border: 1px solid rgba(0, 33, 71, 0.08);
            flex-wrap: wrap;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .dcb-group {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .dcb-input {
            background: #f8fafc;
            border: 1px solid rgba(0, 33, 71, 0.12);
            border-radius: 8px;
            padding: 8px 14px;
            color: #1f2937;
            font-size: 0.82rem;
            font-family: 'Inter', sans-serif;
            outline: none;
            transition: border-color 0.3s;
        }

        .dcb-input:focus {
            border-color: #3b82f6;
        }

        .dcb-input::placeholder {
            color: #9ca3af;
        }

        .dcb-btn {
            background: #002147;
            border: none;
            border-radius: 8px;
            padding: 8px 16px;
            color: #fff;
            font-size: 0.8rem;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .dcb-btn:hover {
            background: #05356b;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 33, 71, 0.2);
        }

        .dcb-btn.outline {
            background: transparent;
            border: 1px solid rgba(0, 33, 71, 0.15);
            color: #002147;
        }

        .dcb-btn.outline:hover {
            border-color: #3b82f6;
            background: rgba(59, 130, 246, 0.05);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.1);
        }

        .dcb-sep {
            width: 1px;
            height: 28px;
            background: rgba(0, 33, 71, 0.1);
        }

        /* ── Stat Card Enhancements ── */
        .stat-trend {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 20px;
            margin-left: 8px;
        }

        .stat-trend.up {
            color: #059669;
            background: rgba(16, 185, 129, 0.1);
        }

        .stat-trend.down {
            color: #dc2626;
            background: rgba(239, 68, 68, 0.1);
        }

        .stat-trend.neutral {
            color: #6b7280;
            background: rgba(107, 114, 128, 0.1);
        }

        .sparkline-wrap {
            margin-top: 8px;
            height: 32px;
            position: relative;
            z-index: 1;
        }

        .sparkline-wrap canvas {
            width: 100% !important;
            height: 32px !important;
        }

        .stat-card {
            position: relative;
        }

        .stat-card-tooltip {
            display: none;
            position: absolute;
            top: -6px;
            left: 50%;
            transform: translateX(-50%) translateY(-100%);
            background: rgba(255, 255, 255, 0.98);
            border: 1px solid rgba(0, 33, 71, 0.1);
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 0.75rem;
            color: #1f2937;
            white-space: nowrap;
            z-index: 100;
            pointer-events: none;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
        }

        .stat-card:hover .stat-card-tooltip {
            display: block;
        }

        /* ── Activity Feed ── */
        .activity-feed {
            max-height: 380px;
            overflow-y: auto;
            padding-right: 4px;
        }

        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 8px;
            background: #f8fafc;
            border: 1px solid rgba(0, 33, 71, 0.06);
            transition: all 0.3s;
        }

        .activity-item:hover {
            background: #f1f5f9;
            border-color: rgba(0, 33, 71, 0.1);
            transform: translateX(4px);
        }

        .activity-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            flex-shrink: 0;
        }

        .activity-icon.msg {
            background: rgba(59, 130, 246, 0.1);
            color: #2563eb;
        }

        .activity-icon.fb {
            background: rgba(16, 185, 129, 0.1);
            color: #059669;
        }

        .activity-icon.err {
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
        }

        .activity-icon.qry {
            background: rgba(124, 58, 237, 0.1);
            color: #7c3aed;
        }

        .activity-detail {
            flex: 1;
            min-width: 0;
        }

        .activity-detail p {
            margin: 0;
            font-size: 0.82rem;
            color: #1f2937;
            line-height: 1.4;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .activity-detail small {
            color: #6b7280;
            font-size: 0.72rem;
        }

        /* ── Alerts & Insights ── */
        .dash-alert {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            border-radius: 10px;
            margin-bottom: 10px;
            font-size: 0.82rem;
            border: 1px solid;
        }

        .dash-alert.critical {
            background: rgba(239, 68, 68, 0.06);
            border-color: rgba(239, 68, 68, 0.15);
            color: #b91c1c;
        }

        .dash-alert.warning {
            background: rgba(251, 191, 36, 0.06);
            border-color: rgba(251, 191, 36, 0.15);
            color: #92400e;
        }

        .dash-alert.info {
            background: rgba(59, 130, 246, 0.06);
            border-color: rgba(59, 130, 246, 0.15);
            color: #1e40af;
        }

        .dash-alert.success {
            background: rgba(16, 185, 129, 0.06);
            border-color: rgba(16, 185, 129, 0.15);
            color: #065f46;
        }

        .dash-alert i {
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .intent-bar-wrap {
            margin-top: 16px;
        }

        .intent-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
            font-size: 0.8rem;
        }

        .intent-label {
            width: 130px;
            color: #374151;
            font-weight: 500;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .intent-bar-bg {
            flex: 1;
            height: 8px;
            background: rgba(0, 33, 71, 0.06);
            border-radius: 4px;
            overflow: hidden;
        }

        .intent-bar-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 1s ease;
        }

        .intent-pct {
            width: 40px;
            text-align: right;
            color: #6b7280;
            font-size: 0.75rem;
        }

        /* ── Loading Skeleton ── */
        @keyframes shimmer {
            0% {
                background-position: -200px 0;
            }

            100% {
                background-position: 200px 0;
            }
        }

        .skeleton {
            background: linear-gradient(90deg, rgba(0, 33, 71, 0.04) 0%, rgba(0, 33, 71, 0.08) 50%, rgba(0, 33, 71, 0.04) 100%);
            background-size: 400px 100%;
            animation: shimmer 1.5s infinite;
            border-radius: 8px;
        }

        /* ── Empty State ── */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #9ca3af;
        }

        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 12px;
            opacity: 0.4;
            display: block;
        }

        .empty-state p {
            font-size: 0.9rem;
            margin: 0;
        }

        /* ── Chart Time Toggle ── */
        .chart-time-toggle {
            display: flex;
            gap: 4px;
        }

        .chart-time-toggle button {
            background: #f8fafc;
            border: 1px solid rgba(0, 33, 71, 0.1);
            border-radius: 6px;
            padding: 4px 12px;
            color: #6b7280;
            font-size: 0.72rem;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
            transition: all 0.3s;
        }

        .chart-time-toggle button:hover,
        .chart-time-toggle button.active {
            background: #002147;
            border-color: #002147;
            color: #ffffff;
        }

        /* ── Enhanced Chart Wrapper ── */
        .chart-wrapper {
            max-height: 400px;
        }

        /* ── Responsive Breakpoints ── */
        @media (max-width:1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .chart-grid-2 {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width:768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .dash-control-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .dcb-group {
                justify-content: center;
            }

            .dashboard-container {
                padding: 20px 12px;
            }
        }

        @media (max-width:480px) {
            .chart-card {
                padding: 16px;
                border-radius: 16px;
            }

            .card-title {
                font-size: 1.1rem;
            }
        }
    </style>
</head>

<style>
    /* Smooth page entry animation */
    body {
        animation: dashboardFadeIn 0.5s ease-out;
    }

    @keyframes dashboardFadeIn {
        from {
            opacity: 0;
        }

        to {
            opacity: 1;
        }
    }
</style>

<body>
    <!--== MAIN CONTAINER ==-->
    <?php include 'includes/topbar.php'; ?>

    </div>
    </div>

    <!--== BODY CONTAINER ==-->
    <div class="container-fluid sb2">
        <div class="row">
            <?php include "includes/sidebar.php"; ?>

            <!--== BODY INNER CONTAINER ==-->
            <div class="sb2-2">
                <!--== breadcrumbs ==-->

                <!--== DASHBOARD INFO ==-->
                <div class="container" style="width: 100%;">
                    <h1 class="text-center mb-4"><i class="fas fa-chart-pie mr-2"></i> Chatbot Dashboard</h1>

                    <!-- ===== CONTROL BAR ===== -->
                    <div class="dash-control-bar">
                        <div class="dcb-group">
                            <button class="dcb-btn outline" onclick="location.reload()" title="Refresh dashboard"><i
                                    class="fas fa-sync-alt"></i> Refresh</button>
                            <button class="dcb-btn outline" onclick="exportDashCSV()" title="Export CSV"><i
                                    class="fas fa-file-csv"></i> CSV</button>
                            <button class="dcb-btn outline" onclick="window.print()" title="Print / PDF"><i
                                    class="fas fa-file-pdf"></i> PDF</button>
                        </div>
                        <!-- Backend Health Status (compact, right-aligned) -->
                        <div style="display:flex; align-items:center; gap:10px; margin-left:auto;">
                            <div style="display:flex; align-items:center; gap:8px; font-size:0.82rem;">
                                <i class="fas fa-server" style="color:#1e3a8a; font-size:0.95rem;"></i>
                                <span style="font-weight:600; color:#111827;">Backend Health Status</span>
                            </div>
                            <div style="display:flex; align-items:center; gap:6px; font-size:0.8rem;">
                                <span class="rasa-status-indicator rasa-offline"></span>
                                <span class="spinner" style="display:none;"></span>
                                <span id="healthStatusText" style="font-weight:600; color:#dc2626; font-size:0.8rem;">Checking...</span>
                            </div>
                            <button onclick="fetchBackendHealth()" class="dcb-btn" style="padding:5px 12px; font-size:0.75rem; border-radius:6px;">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Widgets – Compact Version -->

                    <?php
                    // Widget comparison calculations
                    $users_change =
                        $users_prev_24hr > 0
                        ? round(
                            (($users_24hr - $users_prev_24hr) /
                                $users_prev_24hr) *
                                100,
                        )
                        : 0;
                    $users_change_icon =
                        $users_change >= 0 ? "fa-arrow-up" : "fa-arrow-down";
                    $users_change_color =
                        $users_change >= 0 ? "#10b981" : "#ef4444";

                    $fb_change =
                        $feedback_yesterday > 0
                        ? round(
                            (($feedback_today - $feedback_yesterday) /
                                $feedback_yesterday) *
                                100,
                        )
                        : 0;
                    $fb_change_icon =
                        $fb_change >= 0 ? "fa-arrow-up" : "fa-arrow-down";
                    $fb_change_color = $fb_change >= 0 ? "#10b981" : "#ef4444";

                    // Awaiting urgency
                    $aw_urgency_color =
                        $pushed_awaiting > 10
                        ? "#ef4444"
                        : ($pushed_awaiting > 5
                            ? "#f59e0b"
                            : "#10b981");
                    $aw_urgency_label =
                        $pushed_awaiting > 10
                        ? "High backlog"
                        : ($pushed_awaiting > 5
                            ? "Moderate"
                            : "Manageable");

                    // Inline SVG sparkline helper
                    function renderSvgSparkline(
                        $data,
                        $color = "#3b82f6",
                        $width = 60,
                        $height = 20,
                    ) {
                        if (empty($data) || count($data) < 2) {
                            return "";
                        }
                        $max = max($data) ?: 1;
                        $min = min($data);
                        $range = $max - $min ?: 1;
                        $step = $width / (count($data) - 1);
                        $points = [];
                        foreach ($data as $i => $v) {
                            $x = round($i * $step, 1);
                            $y = round(
                                $height -
                                    (($v - $min) / $range) * ($height - 2) -
                                    1,
                                1,
                            );
                            $points[] = "$x,$y";
                        }
                        $path = implode(" ", $points);
                        return '<svg width="' .
                            $width .
                            '" height="' .
                            $height .
                            '" viewBox="0 0 ' .
                            $width .
                            " " .
                            $height .
                            '" style="display:block; margin-top:4px;">
                            <polyline points="' .
                            $path .
                            '" fill="none" stroke="' .
                            $color .
                            '" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>';
                    }
                    ?>

                    <div class="row mb-2" style="
                    display:flex;
                    justify-content:center;
                    gap:8px;
                    flex-wrap:nowrap;
                ">
                        <!-- Users (24h) -->
                        <div style="flex:1 1 0;">
                            <a href="user_interactions" style="text-decoration:none; color:inherit; display:block; height:100%;">
                                <div class="widget-card compact" style="height:100%;">
                                    <i class="fas fa-users"></i>
                                    <h5 style="font-size:0.8rem;">Users (24h)</h5>
                                    <p><?= formatMetricNumber(
                                            $users_24hr,
                                        ) ?></p>
                                    <?= renderSvgSparkline(
                                        $spark_users_7d,
                                        "#3b82f6",
                                    ) ?>
                                    <span style="font-size:0.8rem; display:flex; align-items:center; gap:3px; justify-content:center; margin-top:3px; color:<?= $users_change_color ?>;">
                                        <i class="fas <?= $users_change_icon ?>" style="font-size:0.45rem;"></i> <?= abs(
                                                                                                                        $users_change,
                                                                                                                    ) ?>% vs yesterday
                                    </span>
                                </div>
                            </a>
                        </div>

                        <!-- Active Users -->
                        <?php
                        // Active users baseline comparison
                        $au_deviation_class = "";
                        if ($hourly_baseline > 0) {
                            $au_ratio = $active_users_5min / $hourly_baseline;
                            if ($au_ratio < 0.3) {
                                $au_deviation_class =
                                    "border: 2px solid #f59e0b;";
                            } elseif ($au_ratio > 1.5) {
                                $au_deviation_class =
                                    "border: 2px solid #10b981;";
                            }
                        }
                        $au_status_color =
                            $hourly_baseline > 0 &&
                            $active_users_5min > $hourly_baseline
                            ? "#10b981"
                            : ($active_users_5min < $hourly_baseline * 0.5
                                ? "#ef4444"
                                : "#6b7280");
                        ?>
                        <div style="flex:1 1 0;">
                            <a href="user_interactions" style="text-decoration:none; color:inherit; display:block; height:100%;">
                                <div class="widget-card compact" style="height:100%; <?= $au_deviation_class ?>">
                                    <i class="fas fa-user-check"></i>
                                    <h5 style="font-size:0.8rem;">Active Users</h5>
                                    <p id="activeUsersCount"><?= formatMetricNumber(
                                                                    $active_users_5min,
                                                                ) ?></p>
                                    <!-- Baseline bar indicator -->
                                    <?php if ($hourly_baseline > 0): ?>
                                        <div style="width:80%; margin:4px auto 0; background:#e2e8f0; border-radius:3px; height:4px; overflow:hidden;">
                                            <div style="width:<?= min(
                                                                    100,
                                                                    round(
                                                                        ($active_users_5min /
                                                                            max($hourly_baseline, 1)) *
                                                                            100,
                                                                    ),
                                                                ) ?>%; height:100%; background:<?= $au_status_color ?>; border-radius:3px; transition:width 0.5s;"></div>
                                        </div>
                                        <span style="font-size:0.8rem; color:#6b7280; display:block; margin-top:2px;">
                                            <?= round(
                                                ($active_users_5min /
                                                    max($hourly_baseline, 1)) *
                                                    100,
                                            ) ?>% of typical volume
                                        </span>
                                    <?php else: ?>
                                        <span style="font-size:0.52rem; color:#6b7280; display:block; margin-top:3px;">Live · refreshes every 30s</span>
                                    <?php endif; ?>
                                </div>
                            </a>
                        </div>

                        <!-- Successful Response Rate -->
                        <div style="flex:1 1 0;">
                            <a href="chatlogs" style="text-decoration:none; color:inherit; display:block; height:100%;">
                                <div class="widget-card compact" style="height:100%;">
                                    <i class="fas fa-check-circle"></i>
                                    <h5 style="font-size:0.8rem;">Success Rate (24h)</h5>
                                    <p><?= $response_rate ?>%</p>
                                    <span style="font-size:0.8rem; display:flex; align-items:center; gap:3px; justify-content:center; margin-top:3px; color:#6b7280;">
                                        <?= formatMetricNumber($successful_replies_24hr) ?> successful replies
                                    </span>
                                </div>
                            </a>
                        </div>

                        <!-- Feedback Today -->
                        <div style="flex:1 1 0;">
                            <a href="feedback" style="text-decoration:none; color:inherit; display:block; height:100%;">
                                <div class="widget-card compact" style="height:100%;">
                                    <i class="fas fa-thumbs-up"></i>
                                    <h5 style="font-size:0.8rem;">Feedback Today</h5>
                                    <p><?= formatMetricNumber(
                                            $feedback_today,
                                        ) ?></p>
                                    <?= renderSvgSparkline(
                                        $spark_feedback_7d,
                                        "#10b981",
                                    ) ?>
                                    <span style="font-size:0.8rem; display:flex; align-items:center; gap:3px; justify-content:center; margin-top:3px; color:<?= $fb_change_color ?>;">
                                        <i class="fas <?= $fb_change_icon ?>" style="font-size:0.45rem;"></i> <?= abs(
                                                                                                                    $fb_change,
                                                                                                                ) ?>% vs yesterday (<?= $feedback_yesterday ?>)
                                    </span>
                                </div>
                            </a>
                        </div>

                        <!-- Awaiting Queries -->
                        <div style="flex:1 1 0;">
                            <a href="pushed_query?status=pending" style="text-decoration:none; color:inherit; display:block; height:100%;">
                                <div class="widget-card compact" style="height:100%; <?= $pushed_awaiting >
                                                                                            10
                                                                                            ? "border:2px solid #ef4444;"
                                                                                            : ($pushed_awaiting > 5
                                                                                                ? "border:2px solid #f59e0b;"
                                                                                                : "") ?>">
                                    <i class="fas fa-hourglass-half"></i>
                                    <h5 style="font-size:0.8rem;">Awaiting Queries</h5>
                                    <p><?= formatMetricNumber(
                                            $pushed_awaiting,
                                        ) ?></p>
                                    <!-- Urgency indicator -->
                                    <div style="display:flex; align-items:center; gap:4px; justify-content:center; margin-top:4px;">
                                        <span style="width:6px; height:6px; border-radius:50%; background:<?= $aw_urgency_color ?>; display:inline-block;"></span>
                                        <span style="font-size:0.8rem; color:<?= $aw_urgency_color ?>; font-weight:600;"><?= $aw_urgency_label ?></span>
                                    </div>
                                    <?php if ($pushed_awaiting > 0): ?>
                                        <span style="font-size:0.8rem; color:#6b7280; display:block; margin-top:1px;">Needs attention</span>
                                    <?php else: ?>
                                        <span style="font-size:0.8rem; color:#10b981; display:block; margin-top:1px;">All clear ✓</span>
                                    <?php endif; ?>
                                </div>
                            </a>
                        </div>
                    </div>

                </div>




                <!-- Operational Row -->
                <div class="row" style="display:flex; gap:20px; margin: 30px 5px 0px 5px; flex-wrap:wrap;">

                    <!-- Open Inquiries -->
                    <div style="flex:1; min-width:300px;">
                        <div class="chart-card" style="height: 100%;">

                            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                                <h2 class="card-title">
                                    <i class="fas fa-inbox"></i> Top 5 Oldest Open Inquiries
                                </h2>
                                <a href="pushed_query.php" style="font-size:0.8rem; font-weight:600; text-decoration:none; color:#3b82f6;">
                                    View All &rarr;
                                </a>
                            </div>

                            <div class="card-body" style="padding:15px;">
                                <?php try {
                                    $old_q = $conn->query(
                                        "SELECT query_id, query_text, submitted_at, priority FROM user_queries WHERE status='pending' ORDER BY submitted_at ASC LIMIT 5",
                                    );

                                    if ($old_q && $old_q->num_rows > 0) {
                                        echo '<ul style="list-style:none; padding:0; margin:0;">';

                                        while ($q = $old_q->fetch_assoc()) {
                                            $hrs = floor(
                                                (time() - strtotime($q["submitted_at"])) / 3600,
                                            );
                                            $age =
                                                $hrs > 24
                                                ? '<span style="color:#ef4444; font-weight:600;"><i class="fas fa-exclamation-triangle"></i> ' .
                                                $hrs .
                                                "h</span>"
                                                : '<span style="color:#64748b;">' .
                                                $hrs .
                                                "h</span>";

                                            echo '<li style="padding:10px 0; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:flex-start; gap:10px;">';
                                            echo '  <div style="flex:1;">
                                    <a href="pushed_query.php" style="color:#1e293b; text-decoration:none; font-size:0.85rem; font-weight:500;">
                                        ' .
                                                htmlspecialchars(
                                                    mb_strimwidth(
                                                        $q["query_text"],
                                                        0,
                                                        60,
                                                        "...",
                                                    ),
                                                ) .
                                                '
                                    </a>
                                  </div>';
                                            echo '  <div style="font-size:0.75rem; text-align:right;">' .
                                                $age .
                                                "</div>";
                                            echo "</li>";
                                        }

                                        echo "</ul>";
                                    } else {
                                        echo '<div style="color:#64748b; font-size:0.85rem; padding:20px 0; text-align:center;">No pending queries.</div>';
                                    }
                                } catch (Exception $e) {
                                } ?>
                            </div>
                        </div>
                    </div>

                    <!-- KB Health (Real Check) -->
                    <div style="flex:1; min-width:300px; ">
                        <div class="chart-card" style="height: 100%;">

                            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                                <h2 class="card-title">
                                    <i class="fas fa-database"></i> Knowledge Base Health
                                </h2>
                                <a href="entity_manage.php" style="font-size:0.8rem; font-weight:600; text-decoration:none; color:#3b82f6;">
                                    Manage &rarr;
                                </a>
                            </div>

                            <div class="card-body" style="padding:20px;">
                                <?php
                                $kb_chunks = $kb_entities = 0;

                                try {
                                    $kb_chunks =
                                        $conn
                                            ->query(
                                                "SELECT COUNT(*) as c FROM entity_knowledge_chunks",
                                            )
                                            ->fetch_assoc()["c"] ?? 0;
                                    $kb_entities =
                                        $conn
                                            ->query(
                                                "SELECT COUNT(*) as c FROM university_entities WHERE is_active=1",
                                            )
                                            ->fetch_assoc()["c"] ?? 0;
                                } catch (Exception $e) {
                                }
                                ?>

                                <div style="display:flex; justify-content:space-around; align-items:center; text-align:center; padding:10px 0;">
                                    <div>
                                        <div style="font-size:1.6rem; font-weight:bold; color:#1e293b;">
                                            <?= number_format($kb_chunks) ?>
                                        </div>
                                        <div style="font-size:0.72rem; color:#64748b; font-weight:500; text-transform:uppercase;">
                                            Articles Available
                                        </div>
                                    </div>

                                    <div style="width:1px; height:40px; background:#e2e8f0;"></div>

                                    <div>
                                        <div style="font-size:1.6rem; font-weight:bold; color:#1e293b;">
                                            <?= number_format($kb_entities) ?>
                                        </div>
                                        <div style="font-size:0.72rem; color:#64748b; font-weight:500; text-transform:uppercase;">
                                            Registered Topics
                                        </div>
                                    </div>
                                </div>

                                <!-- Health Signals -->
                                <div style="border-top:1px solid #e2e8f0; margin-top:10px; padding-top:10px;">
                                    <!-- Last Scrape -->
                                    <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px; font-size:0.78rem;">
                                        <?php if ($kb_last_scrape): ?>
                                            <i class="fas fa-sync-alt" style="color:#3b82f6; font-size:0.7rem;"></i>
                                            <span style="color:#475569;">Last scrape: <strong><?= htmlspecialchars(
                                                                                                    date(
                                                                                                        "M j, g:ia",
                                                                                                        strtotime(
                                                                                                            $kb_last_scrape["scraped_at"] ?? "now",
                                                                                                        ),
                                                                                                    ),
                                                                                                ) ?></strong></span>
                                            <?php if (isset($kb_last_scrape["status"])): ?>
                                                <span style="padding:1px 6px; border-radius:4px; font-size:0.65rem; font-weight:600; background:<?= in_array(
                                                                                                                                                    $kb_last_scrape["status"],
                                                                                                                                                    ["processed", "indexed", "new"],
                                                                                                                                                )
                                                                                                                                                    ? "#dcfce7"
                                                                                                                                                    : "#fef2f2" ?>; color:<?= in_array(
                                                                                                                                                                                $kb_last_scrape["status"],
                                                                                                                                                                                ["processed", "indexed", "new"],
                                                                                                                                                                            )
                                                                                                                                                                                ? "#166534"
                                                                                                                                                                                : "#991b1b" ?>;">
                                                    <?= ucfirst(
                                                        htmlspecialchars(
                                                            $kb_last_scrape["status"],
                                                        ),
                                                    ) ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <i class="fas fa-info-circle" style="color:#3b82f6; font-size:0.7rem;"></i>
                                            <span style="color:#475569;">No web content scraped yet</span>
                                            <a href="web_scraper.php" style="font-size:0.72rem; font-weight:600; color:#3b82f6; text-decoration:none; margin-left:auto;">Start Scraping →</a>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Enrichment pending -->
                                    <?php if ($kb_enrich_pending > 0): ?>
                                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px; font-size:0.78rem;">
                                            <i class="fas fa-wand-magic-sparkles" style="color:#d97706; font-size:0.7rem;"></i>
                                            <span style="color:#92400e;"><strong><?= $kb_enrich_pending ?></strong> pages need LLM enrichment</span>
                                            <a href="web_scraper.php?enrichment_status=pending" style="font-size:0.72rem; font-weight:600; color:#3b82f6; text-decoration:none; margin-left:auto;">Enrich →</a>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Orphaned Chunks -->
                                    <?php if ($kb_orphaned_chunks > 0): ?>
                                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px; font-size:0.78rem;">
                                            <i class="fas fa-unlink" style="color:#f59e0b; font-size:0.7rem;"></i>
                                            <span style="color:#92400e;"><strong><?= $kb_orphaned_chunks ?></strong> unused articles (never asked about in 30d)</span>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Dormant Entities -->
                                    <?php if ($kb_dormant_entities > 0): ?>
                                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px; font-size:0.78rem;">
                                            <i class="fas fa-moon" style="color:#6b7280; font-size:0.7rem;"></i>
                                            <span style="color:#6b7280;"><strong><?= $kb_dormant_entities ?></strong> topics ignored by users in 30 days</span>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Overall Status -->
                                    <?php $kb_healthy =
                                        $kb_orphaned_chunks < 10 && $kb_dormant_entities < 5;
                                    // If no scraping was done, KB can still be healthy from manual entries
                                    ?>
                                    <div style="margin-top:6px; padding:8px; background:<?= $kb_healthy
                                                                                            ? "#f0fdf4"
                                                                                            : "#fffbeb" ?>; border-radius:6px; font-size:0.75rem; color:<?= $kb_healthy
                                                                                                                                                            ? "#166534"
                                                                                                                                                            : "#92400e" ?>; display:flex; align-items:center; gap:6px;">
                                        <i class="fas <?= $kb_healthy
                                                            ? "fa-check-circle"
                                                            : "fa-exclamation-triangle" ?>" style="color:<?= $kb_healthy
                                                                                                                ? "#10b981"
                                                                                                                : "#f59e0b" ?>;"></i>
                                        <?php if (!$kb_last_scrape && $kb_chunks > 0): ?>
                                            Knowledge base has <?= number_format(
                                                                    $kb_chunks,
                                                                ) ?> articles added manually
                                        <?php elseif (!$kb_last_scrape): ?>
                                            Set up web scraping to populate the knowledge base
                                        <?php elseif ($kb_healthy): ?>
                                            Knowledge base is healthy
                                        <?php else: ?>
                                            Review unused topics and articles
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>


                <div class="dashboard-container">


                    <!-- ===== ACTIVITY & INSIGHTS PANEL ===== -->
                    <div class="chart-grid-2" style="margin-bottom:32px;">
                        <!-- Recent Activity Feed -->
                        <div class="chart-card">
                            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                                <h2 class="card-title"><i class="fas fa-stream"></i> Admin & System Activity</h2>
                                <div style="display:flex;gap:12px;align-items:center;">
                                    <span id="activityEventCount" style="font-size:0.75rem; color:#6b7280;"><?= count(
                                                                                                                $recent_activity,
                                                                                                            ) ?> events</span>
                                    <a href="system_logs" style="font-size:0.8rem; font-weight:600; text-decoration:none; color:#3b82f6;">View All &rarr;</a>
                                </div>
                            </div>
                            <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px;">
                                <input type="text" id="activitySearch" placeholder="Search activity..."
                                    oninput="filterActivity(this.value)"
                                    style="flex:1;padding:7px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:0.82rem;outline:none;">
                                <button onclick="document.getElementById('activitySearch').value='';filterActivity('');"
                                    style="padding:7px 14px;border:1px solid #d1d5db;border-radius:8px;background:#fff;cursor:pointer;font-size:0.8rem;color:#4b5563;">Clear</button>
                            </div>
                            <?php if (empty($recent_activity)): ?>
                                <div class="empty-state"><i class="fas fa-inbox"></i>
                                    <p>No recent activity</p>
                                </div>
                            <?php // Map activity types to links with deep-link identifiers
                            // Add timestamp-based highlight anchor for deep-linking
                            // Map activity types to links with deep-link identifiers
                            // Add timestamp-based highlight anchor for deep-linking
                            else: ?>
                                <div class="activity-feed" id="activityFeed">
                                    <?php foreach ($recent_activity as $act):

                                        $atype = $act["type"];
                                        $icon_map = [
                                            "admin_action" => [
                                                "cls" => "msg",
                                                "icon" => "fa-user-shield",
                                            ],
                                            "password_reset" => [
                                                "cls" => "qry",
                                                "icon" => "fa-key",
                                            ],
                                            "session_start" => [
                                                "cls" => "fb",
                                                "icon" => "fa-play-circle",
                                            ],
                                            "fallback" => [
                                                "cls" => "err",
                                                "icon" => "fa-robot",
                                            ],
                                            "error" => [
                                                "cls" => "err",
                                                "icon" => "fa-bug",
                                            ],
                                            "kb_update" => [
                                                "cls" => "fb",
                                                "icon" => "fa-database",
                                            ],
                                        ];
                                        $aicon_cls =
                                            $icon_map[$atype]["cls"] ?? "qry";
                                        $aicon =
                                            $icon_map[$atype]["icon"] ??
                                            "fa-info-circle";
                                        $type_labels = [
                                            "admin_action" => "Admin Action",
                                            "password_reset" =>
                                            "Password Reset",
                                            "session_start" =>
                                            "Session Started",
                                            "fallback" => "AI Fallback",
                                            "error" => "System Error",
                                            "kb_update" => "KB Updated",
                                        ];
                                        $ago = "";
                                        if (!empty($act["created_at"])) {
                                            $diff =
                                                time() -
                                                strtotime($act["created_at"]);
                                            if ($diff < 60) {
                                                $ago = $diff . "s ago";
                                            } elseif ($diff < 3600) {
                                                $ago =
                                                    floor($diff / 60) . "m ago";
                                            } elseif ($diff < 86400) {
                                                $ago =
                                                    floor($diff / 3600) .
                                                    "h ago";
                                            } else {
                                                $ago =
                                                    floor($diff / 86400) .
                                                    "d ago";
                                            }
                                        }
                                    ?>
                                        <?php
                                        $activity_link_map = [
                                            "admin_action" => "admin-setting.php",
                                            "password_reset" => "admin-setting.php",
                                            "fallback" => "chatlogs.php?filter=response_type:fallback",
                                            "error" => "system_logs.php?level=error",
                                            "kb_update" => "web_scraper.php",
                                        ];
                                        $activity_link = $activity_link_map[$atype] ?? null;
                                        $highlight_ts = isset($act["created_at"])
                                            ? "&highlight=" . urlencode($act["created_at"])
                                            : "";
                                        $activity_link_full = $activity_link
                                            ? $activity_link .
                                            (strpos($activity_link, "?") !== false
                                                ? $highlight_ts
                                                : str_replace("&", "?", $highlight_ts))
                                            : null;
                                        ?>
                                        <?php if ($activity_link_full): ?>
                                            <a href="<?= $activity_link_full ?>" style="text-decoration:none; color:inherit; display:flex; flex:1;">
                                            <?php endif; ?>
                                            <div class="activity-item"
                                                data-search="<?= htmlspecialchars(
                                                                    strtolower(
                                                                        $act["detail"] ?? "",
                                                                    ),
                                                                ) ?>"
                                                data-type="<?= htmlspecialchars(
                                                                $atype,
                                                            ) ?>"
                                                data-created="<?= htmlspecialchars(
                                                                    $act["created_at"] ?? "",
                                                                ) ?>"
                                                style="display:flex;align-items:flex-start;gap:10px;position:relative;flex:1;">
                                                <div class="activity-icon <?= $aicon_cls ?>">
                                                    <i class="fas <?= $aicon ?>"></i>
                                                </div>
                                                <div class="activity-detail" style="flex:1;">
                                                    <p><strong style="font-size:0.72rem; color:#6b7280; text-transform:uppercase;"><?= $type_labels[$atype] ??
                                                                                                                                        ucfirst(
                                                                                                                                            $atype,
                                                                                                                                        ) ?></strong><br>
                                                        <?= htmlspecialchars(
                                                            $act["detail"] ??
                                                                "No detail",
                                                        ) ?></p>
                                                    <small><i class="fas fa-clock" style="margin-right:3px;"></i><?= $ago ?></small>
                                                </div>
                                                <button onclick="event.preventDefault();event.stopPropagation();confirmDeleteActivity(this)"
                                                    title="Delete this entry"
                                                    style="flex-shrink:0;padding:3px 7px;border:1px solid #fca5a5;border-radius:5px;background:#fff;color:#ef4444;font-size:0.7rem;cursor:pointer;align-self:center;">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                            <?php if ($activity_link): ?>
                                            </a>
                                        <?php endif; ?>
                                    <?php
                                    endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Alerts & Insights -->
                        <div class="chart-card">
                            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                                <h2 class="card-title"><i class="fas fa-lightbulb"></i> Alerts & Insights</h2>
                                <a href="system_logs?level=warning" style="font-size:0.8rem; font-weight:600; text-decoration:none; color:#3b82f6;">View All &rarr;</a>
                            </div>

                            <?php if ($error_spike): ?>
                                <div class="dash-alert critical" style="flex-direction:column; align-items:flex-start;">
                                    <div style="display:flex; align-items:center; gap:8px; width:100%;">
                                        <i class="fas fa-exclamation-circle"></i>
                                        <span style="flex:1;">Error spike detected — <?= $errors_24hr ?> errors today vs <?= $avg_daily_errors ?> daily avg</span>
                                        <a href="system_logs.php?level=error&range=24h" style="font-size:0.75rem; font-weight:600; color:#dc2626; text-decoration:none;">Investigate &rarr;</a>
                                    </div>
                                    <?php if (
                                        !empty($recent_error_messages)
                                    ): ?>
                                        <details style="margin-top:8px; width:100%;">
                                            <summary style="font-size:0.75rem; cursor:pointer; color:#991b1b;">Top 3 recent errors</summary>
                                            <ul style="margin:6px 0 0 0; padding-left:16px; font-size:0.75rem; color:#7f1d1d;">
                                                <?php foreach (
                                                    $recent_error_messages
                                                    as $err
                                                ): ?>
                                                    <li style="margin-bottom:4px;"><strong><?= htmlspecialchars(
                                                                                                $err["error_type"] ?? "Error",
                                                                                            ) ?></strong>: <?= htmlspecialchars(
                                                                                                                mb_strimwidth($err["error_message"] ?? "", 0, 80, "..."),
                                                                                                            ) ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </details>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($low_satisfaction): ?>
                                <div class="dash-alert warning" style="display:flex; align-items:center; gap:8px;">
                                    <i class="fas fa-frown"></i>
                                    <span style="flex:1;">Low satisfaction — avg score <?= $recent_satisfaction ?>/5 in last 24h</span>
                                    <a href="feedback.php?rating=bad&range=24h" style="font-size:0.75rem; font-weight:600; color:#92400e; text-decoration:none;">View Feedback &rarr;</a>
                                </div>
                            <?php endif; ?>
                            <?php if ($old_unresolved > 0): ?>
                                <div class="dash-alert warning" style="flex-direction:column; align-items:flex-start;">
                                    <div style="display:flex; align-items:center; gap:8px; width:100%;">
                                        <i class="fas fa-hourglass-half"></i>
                                        <span style="flex:1;"><?= $old_unresolved ?> unresolved queries older than 24 hours</span>
                                        <a href="pushed_query.php?status=pending" style="font-size:0.75rem; font-weight:600; color:#92400e; text-decoration:none;">View All &rarr;</a>
                                    </div>
                                    <?php if (
                                        !empty($old_unresolved_queries)
                                    ): ?>
                                        <ul style="margin:8px 0 0 0; padding:0; list-style:none; width:100%;">
                                            <?php foreach (
                                                $old_unresolved_queries
                                                as $oq
                                            ): ?>
                                                <li style="display:flex; align-items:center; justify-content:space-between; padding:6px 0; border-top:1px solid rgba(251,191,36,0.15); font-size:0.78rem;">
                                                    <span style="color:#1e293b; flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= htmlspecialchars(
                                                                                                                                                            mb_strimwidth(
                                                                                                                                                                $oq["query_text"],
                                                                                                                                                                0,
                                                                                                                                                                55,
                                                                                                                                                                "...",
                                                                                                                                                            ),
                                                                                                                                                        ) ?></span>
                                                    <a href="pushed_query.php?id=<?= (int) $oq["query_id"] ?>" style="padding:3px 10px; background:#f59e0b; color:#fff; border-radius:5px; font-size:0.7rem; font-weight:600; text-decoration:none; white-space:nowrap; margin-left:8px;">Respond</a>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($pushed_awaiting > 0): ?>
                                <div class="dash-alert info"><i class="fas fa-inbox"></i> <?= $pushed_awaiting ?>
                                    queries awaiting admin response <a href="pushed_query.php?status=pending" style="font-size:0.75rem; font-weight:600; color:#1e40af; text-decoration:none; margin-left:8px;">Respond &rarr;</a></div>
                            <?php endif; ?>
                            <?php if (
                                !$error_spike &&
                                !$low_satisfaction &&
                                $old_unresolved == 0 &&
                                $pushed_awaiting == 0
                            ): ?>
                                <div class="dash-alert success"><i class="fas fa-check-circle"></i> All systems healthy
                                    — no alerts</div>
                            <?php endif; ?>

                            <!-- Top Intents -->
                            <?php if (!empty($top_intents)): ?>
                                <div style="margin-top:20px;">
                                    <h4 style="font-size:0.9rem; font-weight:600; color:#002147; margin-bottom:12px;"><i
                                            class="fas fa-bullseye" style="color:#a855f7; margin-right:6px;"></i>Top
                                        Intents</h4>
                                    <div class="intent-bar-wrap">
                                        <?php
                                        $intent_colors = [
                                            "#667eea",
                                            "#a855f7",
                                            "#ec4899",
                                            "#f97316",
                                            "#10b981",
                                        ];
                                        foreach ($top_intents as $idx => $ti):

                                            $pct =
                                                $total_intent_msgs > 0
                                                ? round(
                                                    ($ti["cnt"] /
                                                        $total_intent_msgs) *
                                                        100,
                                                    1,
                                                )
                                                : 0;
                                            $color =
                                                $intent_colors[$idx % count($intent_colors)];
                                        ?>
                                            <div class="intent-row">
                                                <span class="intent-label"
                                                    title="<?= htmlspecialchars(
                                                                $ti["intent"],
                                                            ) ?>"><?= htmlspecialchars(
                                                                        $ti["intent"],
                                                                    ) ?></span>
                                                <div class="intent-bar-bg">
                                                    <div class="intent-bar-fill"
                                                        style="width:<?= $pct ?>%; background:<?= $color ?>;"></div>
                                                </div>
                                                <span class="intent-pct"><?= $pct ?>%</span>
                                            </div>
                                        <?php
                                        endforeach;
                                        ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- ══════════════════════════════════════════════════════
                         CONVERSATIONS NEEDING REVIEW
                         ══════════════════════════════════════════════════════ -->
                    <?php if (!empty($conversations_needing_review)): ?>
                        <div class="chart-card" style="margin-bottom:32px;">
                            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                                <h2 class="card-title"><i class="fas fa-flag" style="color:#ef4444;"></i> Conversations Needing Review</h2>
                                <span style="font-size:0.75rem; color:#6b7280;"><?= count(
                                                                                    $conversations_needing_review,
                                                                                ) ?> sessions flagged this week</span>
                            </div>
                            <div style="overflow-x:auto;">
                                <table style="width:100%; border-collapse:collapse; font-size:0.82rem;">
                                    <thead>
                                        <tr style="border-bottom:2px solid #e2e8f0;">
                                            <th style="padding:10px 12px; text-align:left; color:#6b7280; font-weight:600; font-size:0.72rem; text-transform:uppercase;">Session</th>
                                            <th style="padding:10px 12px; text-align:center; color:#6b7280; font-weight:600; font-size:0.72rem; text-transform:uppercase;">Started</th>
                                            <th style="padding:10px 12px; text-align:center; color:#6b7280; font-weight:600; font-size:0.72rem; text-transform:uppercase;">Messages</th>
                                            <th style="padding:10px 12px; text-align:center; color:#6b7280; font-weight:600; font-size:0.72rem; text-transform:uppercase;">Fallbacks</th>
                                            <th style="padding:10px 12px; text-align:center; color:#6b7280; font-weight:600; font-size:0.72rem; text-transform:uppercase;">Satisfaction</th>
                                            <th style="padding:10px 8px; text-align:right; color:#6b7280; font-weight:600; font-size:0.72rem; text-transform:uppercase;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (
                                            $conversations_needing_review
                                            as $cnr
                                        ): ?>
                                            <tr style="border-bottom:1px solid #f1f5f9; transition:background 0.15s;">
                                                <td style="padding:10px 12px; font-family:monospace; color:#1e293b; font-size:0.75rem;"><?= htmlspecialchars(
                                                                                                                                            substr($cnr["session_id"], 0, 12),
                                                                                                                                        ) ?>…</td>
                                                <td style="padding:10px 12px; text-align:center; color:#6b7280;"><?= date(
                                                                                                                        "M j, g:ia",
                                                                                                                        strtotime($cnr["start_time"]),
                                                                                                                    ) ?></td>
                                                <td style="padding:10px 12px; text-align:center; color:#1e293b; font-weight:600;"><?= (int) $cnr["msg_count"] ?></td>
                                                <td style="padding:10px 12px; text-align:center;">
                                                    <span style="padding:2px 8px; border-radius:10px; font-size:0.72rem; font-weight:600; background:<?= (int) $cnr["fallback_count"] >= 3
                                                                                                                                                            ? "#fef2f2"
                                                                                                                                                            : "#f8fafc" ?>; color:<?= (int) $cnr["fallback_count"] >= 3
                                                                                                                                                                                        ? "#dc2626"
                                                                                                                                                                                        : "#6b7280" ?>;">
                                                        <?= (int) $cnr["fallback_count"] ?>
                                                    </span>
                                                </td>
                                                <td style="padding:10px 12px; text-align:center;">
                                                    <?php if (
                                                        $cnr["sat_score"] !== null
                                                    ): ?>
                                                        <span style="padding:2px 8px; border-radius:10px; font-size:0.72rem; font-weight:600; background:<?= (float) $cnr["sat_score"] <= 2
                                                                                                                                                                ? "#fef2f2"
                                                                                                                                                                : "#f0fdf4" ?>; color:<?= (float) $cnr["sat_score"] <= 2
                                                                                                                                                                                            ? "#dc2626"
                                                                                                                                                                                            : "#166534" ?>;">
                                                            <?= $cnr["sat_score"] ?>/5
                                                        </span>
                                                    <?php else: ?>
                                                        <span style="color:#6b7280; font-size:0.72rem;">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="padding:10px 8px; text-align:right;">
                                                    <a href="chatlogs.php?session=<?= urlencode(
                                                                                        $cnr["session_id"],
                                                                                    ) ?>" style="padding:4px 12px; background:#3b82f6; color:#fff; border-radius:6px; font-size:0.72rem; font-weight:600; text-decoration:none;">Review</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- ══════════════════════════════════════════════════════
                         FEEDBACK & INSIGHTS SECTION
                         ══════════════════════════════════════════════════════ -->
                    <!-- ══════════════════════════════════════════════════════
                         FEEDBACK & INSIGHTS SECTION
                         ══════════════════════════════════════════════════════ -->
                    <div class="chart-card" id="feedbackInsightsSection" style="margin-bottom:32px;">
                        <style>
                            .fb-tabs {
                                display: flex;
                                gap: 4px;
                                flex-wrap: wrap;
                                margin-bottom: 16px;
                            }

                            .fb-tab {
                                padding: 6px 14px;
                                border: 1px solid #d1d5db;
                                border-radius: 8px;
                                background: #f8fafc;
                                color: #6b7280;
                                font-size: 0.78rem;
                                font-weight: 600;
                                cursor: pointer;
                                transition: all 0.2s;
                            }

                            .fb-tab:hover {
                                border-color: #10b981;
                                color: #10b981;
                            }

                            .fb-tab.active {
                                background: linear-gradient(135deg, #10b981, #059669);
                                color: #fff;
                                border-color: transparent;
                            }

                            .fb-panel {
                                display: none;
                                animation: predFadeIn 0.3s ease;
                            }

                            .fb-panel.active {
                                display: block;
                            }

                            .fb-kpi-row {
                                display: grid;
                                grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
                                gap: 12px;
                                margin-bottom: 16px;
                            }

                            .fb-kpi {
                                background: #f0fdf4;
                                border-radius: 10px;
                                padding: 14px;
                                text-align: center;
                                border: 1px solid #dcfce7;
                            }

                            .fb-kpi-value {
                                font-size: 1.4rem;
                                font-weight: 800;
                                color: #002147;
                            }

                            .fb-kpi-label {
                                font-size: 0.72rem;
                                color: #6b7280;
                                margin-top: 2px;
                            }

                            .fb-period-toggle {
                                display: inline-flex;
                                gap: 2px;
                                background: #f1f5f9;
                                border-radius: 6px;
                                padding: 2px;
                            }

                            .fb-period-toggle button {
                                padding: 4px 10px;
                                border: none;
                                border-radius: 5px;
                                background: transparent;
                                font-size: 0.72rem;
                                font-weight: 600;
                                color: #6b7280;
                                cursor: pointer;
                                transition: all 0.2s;
                            }

                            .fb-period-toggle button.active {
                                background: #fff;
                                color: #10b981;
                                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
                            }
                        </style>

                        <div class="card-header" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                            <h2 class="card-title"><i class="fas fa-chart-bar" style="color:#10b981;"></i> Feedback & Insights</h2>
                        </div>

                        <!-- Tabs -->
                        <div class="fb-tabs">
                            <button class="fb-tab active" onclick="switchFbTab('overview')"><i class="fas fa-tachometer-alt"></i> Overview</button>
                            <button class="fb-tab" onclick="switchFbTab('trends')"><i class="fas fa-chart-area"></i> Trends</button>
                        </div>

                        <!-- ── TAB: Overview ── -->
                        <div class="fb-panel active" id="fb-overview">
                            <div class="fb-kpi-row">
                                <div class="fb-kpi">
                                    <div class="fb-kpi-value" style="color:#10b981;"><?= $thumbs_up_ratio ?>%</div>
                                    <div class="fb-kpi-label">Positive Feedback</div>
                                </div>
                                <div class="fb-kpi">
                                    <div class="fb-kpi-value" style="color:#3b82f6;"><?= formatMetricNumber(
                                                                                            $feedback_today,
                                                                                        ) ?></div>
                                    <div class="fb-kpi-label">Feedback Today</div>
                                </div>
                            </div>
                            <div style="padding:12px; background:#f8fafc; border-radius:8px; font-size:0.82rem; color:#475569;">
                                <i class="fas fa-info-circle" style="color:#3b82f6; margin-right:6px;"></i>
                                <strong>Breakdown:</strong>
                                👍 <?= $thumbs_up_reactions ?> likes / 👎 <?= $thumbs_down_reactions ?> dislikes ·
                                Typed feedback: <?= array_sum(
                                                    array_column(
                                                        $feedback_counts ?? [],
                                                        "count",
                                                    ),
                                                ) ?:
                                                    0 ?>
                            </div>
                        </div>

                        <!-- ── TAB: Trends (Both Graphs Side-by-Side) ── -->
                        <div class="fb-panel" id="fb-trends">
                            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                                <!-- Left: Feedback Over Time -->
                                <div>
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                                        <span style="font-size:0.82rem; color:#374151; font-weight:600;">Feedback Over Time</span>
                                        <div class="fb-period-toggle" id="fbTrendsPeriodToggle">
                                            <button class="active" onclick="switchFbTrendPeriod('daily')">Daily</button>
                                            <button onclick="switchFbTrendPeriod('weekly')">Weekly</button>
                                            <button onclick="switchFbTrendPeriod('monthly')">Monthly</button>
                                        </div>
                                    </div>
                                    <div class="chart-wrapper"><canvas id="feedbackOverTimeChart"></canvas></div>
                                </div>

                                <!-- Right: Like vs Dislike Distribution -->
                                <div>
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                                        <span style="font-size:0.82rem; color:#374151; font-weight:600;">Like vs Dislike</span>
                                    </div>
                                    <div class="chart-wrapper"><canvas id="feedbackChart"></canvas></div>
                                    <div class="insight-box" style="margin-top:12px;">
                                        <p><strong>Note:</strong> Combines typed feedback and emoji reactions</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>


                    <!-- ══════════════════════════════════════════════════════
                         PREDICTIONS SECTION
                         ══════════════════════════════════════════════════════ -->
                    <div class="chart-card" id="predictionsSection">
                        <style>
                            .pred-tabs {
                                display: flex;
                                gap: 4px;
                                flex-wrap: wrap;
                                margin-bottom: 16px;
                            }

                            .pred-tab {
                                padding: 6px 14px;
                                border: 1px solid #d1d5db;
                                border-radius: 8px;
                                background: #f8fafc;
                                color: #6b7280;
                                font-size: 0.78rem;
                                font-weight: 600;
                                cursor: pointer;
                                transition: all 0.2s;
                            }

                            .pred-tab:hover {
                                border-color: #3b82f6;
                                color: #3b82f6;
                            }

                            .pred-tab.active {
                                background: var(--gradient-2);
                                color: #fff;
                                border-color: transparent;
                            }

                            .pred-panel {
                                display: none;
                                animation: predFadeIn 0.3s ease;
                            }

                            .pred-panel.active {
                                display: block;
                            }

                            @keyframes predFadeIn {
                                from {
                                    opacity: 0;
                                    transform: translateY(6px);
                                }

                                to {
                                    opacity: 1;
                                    transform: translateY(0);
                                }
                            }

                            .pred-kpi-row {
                                display: grid;
                                grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
                                gap: 12px;
                                margin-bottom: 16px;
                            }

                            .pred-kpi {
                                background: #f1f5f9;
                                border-radius: 10px;
                                padding: 14px;
                                text-align: center;
                                border: 1px solid #e2e8f0;
                            }

                            .pred-kpi-value {
                                font-size: 1.4rem;
                                font-weight: 800;
                                color: #002147;
                            }

                            .pred-kpi-label {
                                font-size: 0.72rem;
                                color: #6b7280;
                                margin-top: 2px;
                            }

                            .pred-conf {
                                display: inline-flex;
                                align-items: center;
                                gap: 4px;
                                padding: 2px 8px;
                                border-radius: 10px;
                                font-size: 0.65rem;
                                font-weight: 700;
                                text-transform: uppercase;
                            }

                            .pred-conf-high {
                                background: rgba(16, 185, 129, 0.12);
                                color: #10b981;
                            }

                            .pred-conf-medium {
                                background: rgba(245, 158, 11, 0.12);
                                color: #f59e0b;
                            }

                            .pred-conf-low {
                                background: rgba(239, 68, 68, 0.12);
                                color: #ef4444;
                            }

                            .pred-table {
                                width: 100%;
                                border-collapse: collapse;
                                font-size: 0.8rem;
                            }

                            .pred-table th {
                                background: #f1f5f9;
                                color: #6b7280;
                                font-size: 0.72rem;
                                text-transform: uppercase;
                                letter-spacing: 0.5px;
                                padding: 8px 10px;
                                text-align: left;
                            }

                            .pred-table td {
                                padding: 8px 10px;
                                border-bottom: 1px solid #f1f5f9;
                                color: #374151;
                            }

                            .pred-table tr:hover td {
                                background: #f8fafc;
                            }

                            .pred-alert {
                                display: flex;
                                align-items: flex-start;
                                gap: 10px;
                                padding: 10px 14px;
                                border-radius: 8px;
                                margin-bottom: 8px;
                                font-size: 0.8rem;
                            }

                            .pred-alert-critical {
                                background: rgba(239, 68, 68, 0.08);
                                border-left: 3px solid #ef4444;
                            }

                            .pred-alert-warning {
                                background: rgba(245, 158, 11, 0.08);
                                border-left: 3px solid #f59e0b;
                            }

                            .pred-alert-info {
                                background: rgba(59, 130, 246, 0.08);
                                border-left: 3px solid #3b82f6;
                            }

                            .pred-alert-success {
                                background: rgba(16, 185, 129, 0.08);
                                border-left: 3px solid #10b981;
                            }

                            .pred-alert i {
                                margin-top: 2px;
                            }

                            .pred-alert-reason {
                                font-size: 0.72rem;
                                color: #6b7280;
                                margin-top: 2px;
                            }

                            .pred-bar-row {
                                display: flex;
                                align-items: center;
                                gap: 8px;
                                margin-bottom: 6px;
                            }

                            .pred-bar-label {
                                width: 70px;
                                font-size: 0.75rem;
                                color: #6b7280;
                                text-align: right;
                            }

                            .pred-bar-bg {
                                flex: 1;
                                height: 20px;
                                background: #f1f5f9;
                                border-radius: 6px;
                                overflow: hidden;
                            }

                            .pred-bar-fill {
                                height: 100%;
                                border-radius: 6px;
                                transition: width 0.6s ease;
                                display: flex;
                                align-items: center;
                                padding-left: 6px;
                                color: #fff;
                                font-size: 0.65rem;
                                font-weight: 700;
                            }

                            .pred-rec {
                                display: flex;
                                align-items: flex-start;
                                gap: 10px;
                                padding: 10px 14px;
                                border-radius: 8px;
                                margin-bottom: 8px;
                                font-size: 0.8rem;
                                background: #f8fafc;
                                border: 1px solid #e2e8f0;
                            }

                            .pred-rec i {
                                color: #3b82f6;
                                margin-top: 2px;
                            }

                            .pred-rec-high i {
                                color: #ef4444;
                            }

                            .pred-rec-medium i {
                                color: #f59e0b;
                            }
                        </style>

                        <div class="card-header" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                            <h2 class="card-title"><i class="fas fa-crystal-ball" style="color:#7c3aed;"></i> <i class="fas fa-wand-magic-sparkles" style="color:#7c3aed;"></i> Predictions & Insights</h2>
                        </div>

                        <?php
                        $default_tab = "peak";
                        ?>
                        <div class="pred-tabs">
                            <button class="pred-tab <?= $default_tab ===
                                                        "forecast"
                                                        ? "active"
                                                        : "" ?>" onclick="switchPredTab('forecast')"><i class="fas fa-chart-line"></i> Forecast</button>
                            <?php if ($show_growth): ?>
                                <button class="pred-tab <?= $default_tab ===
                                                            "growth"
                                                            ? "active"
                                                            : "" ?>" onclick="switchPredTab('growth')"><i class="fas fa-users"></i> Growth</button>
                            <?php endif; ?>
                            <button class="pred-tab <?= $default_tab === "peak"
                                                        ? "active"
                                                        : "" ?>" onclick="switchPredTab('peak')"><i class="fas fa-bolt"></i> Peak Load</button>
                            <button class="pred-tab" onclick="switchPredTab('models')"><i class="fas fa-microchip"></i> Models</button>
                            <button class="pred-tab <?= $default_tab ===
                                                        "anomalies"
                                                        ? "active"
                                                        : "" ?>" onclick="switchPredTab('anomalies')"><i class="fas fa-triangle-exclamation"></i> Anomalies</button>
                            <button class="pred-tab" onclick="switchPredTab('tips')"><i class="fas fa-lightbulb"></i> Tips</button>
                        </div>

                        <!-- ── TAB: Forecast ── -->
                        <div class="pred-panel <?= $default_tab === "forecast"
                                                    ? "active"
                                                    : "" ?>" id="pred-forecast">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                                <span style="font-size:0.82rem; color:#374151; font-weight:600;">Usage Forecast</span>
                                <?php if ($show_forecast): ?>
                                    <span class="pred-conf pred-conf-<?= strtolower(
                                                                            $fc_confidence,
                                                                        ) ?>"><?= $fc_confidence ?> confidence</span>
                                <?php endif; ?>
                            </div>
                            <div class="pred-kpi-row">
                                <div class="pred-kpi">
                                    <div class="pred-kpi-value" style="color:#3b82f6;"><?= number_format(
                                                                                            $fc_predict_24h,
                                                                                        ) ?></div>
                                    <div class="pred-kpi-label">Next 24 Hours</div>
                                </div>
                                <div class="pred-kpi">
                                    <div class="pred-kpi-value" style="color:#7c3aed;"><?= number_format(
                                                                                            $fc_predict_7d,
                                                                                        ) ?></div>
                                    <div class="pred-kpi-label">Next 7 Days</div>
                                </div>
                                <div class="pred-kpi">
                                    <div class="pred-kpi-value" style="color:#ec4899;"><?= number_format(
                                                                                            $fc_predict_30d,
                                                                                        ) ?></div>
                                    <div class="pred-kpi-label">Next 30 Days</div>
                                </div>
                            </div>
                            <div style="height:200px;"><canvas id="predForecastChart"></canvas></div>
                            <div style="font-size:0.72rem; color:#6b7280; margin-top:8px;">
                                R² = <?= round($fc_r2, 3) ?> · Trend: <?= ($fc_slope > 0 ? "+" : "") . round($fc_slope, 2) ?> msgs/day
                            </div>
                        </div>

                        <!-- ── TAB: Growth ── -->
                        <?php if ($show_growth): ?>
                            <div class="pred-panel <?= $default_tab === "growth"
                                                        ? "active"
                                                        : "" ?>" id="pred-growth">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                                    <span style="font-size:0.82rem; color:#374151; font-weight:600;">User Growth (14-Day Sessions)</span>
                                    <span class="pred-conf pred-conf-<?= strtolower(
                                                                            $ug_confidence,
                                                                        ) ?>"><?= $ug_confidence ?> confidence</span>
                                </div>
                                <div class="pred-kpi-row">
                                    <div class="pred-kpi">
                                        <div class="pred-kpi-value"><?= number_format(
                                                                        $ug_total_sessions,
                                                                    ) ?></div>
                                        <div class="pred-kpi-label">Total Sessions</div>
                                    </div>
                                    <div class="pred-kpi">
                                        <div class="pred-kpi-value" style="color:<?= $ug_growth_pct >=
                                                                                        0
                                                                                        ? "#10b981"
                                                                                        : "#ef4444" ?>;">
                                            <?= ($ug_growth_pct >= 0 ? "+" : "") . $ug_growth_pct ?>%
                                        </div>
                                        <div class="pred-kpi-label">Growth Rate</div>
                                    </div>
                                    <div class="pred-kpi">
                                        <div class="pred-kpi-value" style="color:#3b82f6;"><?= count(
                                                                                                $ug_daily,
                                                                                            ) > 0
                                                                                                ? round(
                                                                                                    array_sum($ug_daily) /
                                                                                                        count($ug_daily),
                                                                                                    1,
                                                                                                )
                                                                                                : 0 ?></div>
                                        <div class="pred-kpi-label">Avg Daily Sessions</div>
                                    </div>
                                </div>
                                <div style="height:180px;"><canvas id="predGrowthChart"></canvas></div>
                            </div>
                        <?php endif; ?>

                        <!-- ── TAB: Peak Load ── -->
                        <div class="pred-panel <?= $default_tab === "peak"
                                                    ? "active"
                                                    : "" ?>" id="pred-peak">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                                <span style="font-size:0.82rem; color:#374151; font-weight:600;">Peak Load Analysis</span>
                                <span class="pred-conf pred-conf-<?= strtolower(
                                                                        $pl_confidence,
                                                                    ) ?>"><?= $pl_confidence ?> confidence</span>
                            </div>
                            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                                <div>
                                    <h5 style="font-size:0.78rem; font-weight:600; color:#002147; margin-bottom:8px;"><i class="fas fa-clock" style="color:#f59e0b;"></i> Busiest Hours</h5>
                                    <?php
                                    $pl_max_h = !empty($pl_hours)
                                        ? max(array_column($pl_hours, "c"))
                                        : 1;
                                    foreach ($pl_hours as $ph):
                                        $w = round(
                                            ($ph["c"] / $pl_max_h) * 100,
                                        ); ?>
                                        <div class="pred-bar-row">
                                            <span class="pred-bar-label"><?= sprintf(
                                                                                "%02d:00",
                                                                                $ph["hr"],
                                                                            ) ?></span>
                                            <div class="pred-bar-bg">
                                                <div class="pred-bar-fill" style="width:<?= $w ?>%; background:linear-gradient(90deg,#f59e0b,#ec4899);"><?= $ph["c"] ?></div>
                                            </div>
                                        </div>
                                    <?php
                                    endforeach;
                                    ?>
                                </div>
                                <div>
                                    <h5 style="font-size:0.78rem; font-weight:600; color:#002147; margin-bottom:8px;"><i class="fas fa-calendar-day" style="color:#3b82f6;"></i> Busiest Days</h5>
                                    <?php
                                    $pl_max_d = !empty($pl_days)
                                        ? max(array_column($pl_days, "c"))
                                        : 1;
                                    foreach ($pl_days as $pd):
                                        $w = round(
                                            ($pd["c"] / $pl_max_d) * 100,
                                        ); ?>
                                        <div class="pred-bar-row">
                                            <span class="pred-bar-label"><?= substr(
                                                                                $pd["day_name"],
                                                                                0,
                                                                                3,
                                                                            ) ?></span>
                                            <div class="pred-bar-bg">
                                                <div class="pred-bar-fill" style="width:<?= $w ?>%; background:linear-gradient(90deg,#3b82f6,#7c3aed);"><?= $pd["c"] ?></div>
                                            </div>
                                        </div>
                                    <?php
                                    endforeach;
                                    ?>
                                </div>
                            </div>
                        </div>

                        <!-- ── TAB: Models ── -->
                        <div class="pred-panel" id="pred-models">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                                <span style="font-size:0.82rem; color:#374151; font-weight:600;">Model Performance Comparison</span>
                                <span class="pred-conf pred-conf-<?= strtolower(
                                                                        $mp_confidence,
                                                                    ) ?>"><?= $mp_confidence ?> confidence</span>
                            </div>
                            <?php if (!empty($mp_models)): ?>
                                <div style="overflow-x:auto;">
                                    <table class="pred-table">
                                        <thead>
                                            <tr>
                                                <th>Model</th>
                                                <th>Calls</th>
                                                <th>Avg Latency</th>
                                                <th>Avg Confidence</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($mp_models as $m): ?>
                                                <tr>
                                                    <td style="font-weight:600;"><?= htmlspecialchars(
                                                                                        $m["model_used"],
                                                                                    ) ?></td>
                                                    <td><?= number_format(
                                                            $m["call_count"],
                                                        ) ?></td>
                                                    <td><?= $m["avg_latency"]
                                                            ? $m["avg_latency"] .
                                                            "ms"
                                                            : "N/A" ?></td>
                                                    <td><?= $m["avg_confidence"] ?? "N/A" ?>%</td>
                                                    <td>
                                                        <?php
                                                        $latency =
                                                            (int) ($m["avg_latency"] ?? 0);
                                                        if ($latency > 3000) {
                                                            echo '<span class="pred-conf pred-conf-low">Slow</span>';
                                                        } elseif (
                                                            $latency > 1500
                                                        ) {
                                                            echo '<span class="pred-conf pred-conf-medium">OK</span>';
                                                        } else {
                                                            echo '<span class="pred-conf pred-conf-high">Fast</span>';
                                                        }
                                                        ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div style="text-align:center; padding:20px; color:#6b7280; font-size:0.82rem;">
                                    <i class="fas fa-database" style="font-size:1.5rem; margin-bottom:8px; display:block;"></i>
                                    No model performance data available yet
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- ── TAB: Anomalies ── -->
                        <div class="pred-panel" id="pred-anomalies">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                                <span style="font-size:0.82rem; color:#374151; font-weight:600;">Anomaly Detection</span>
                                <span class="pred-conf pred-conf-<?= strtolower(
                                                                        $an_confidence,
                                                                    ) ?>"><?= $an_confidence ?> confidence</span>
                            </div>
                            <?php if (!empty($anomalies)): ?>
                                <?php foreach ($anomalies as $a): ?>
                                    <div class="pred-alert pred-alert-<?= $a["type"] ?>">
                                        <i class="fas <?= $a["icon"] ?>" style="color:<?= $a["type"] ===
                                                                                            "critical"
                                                                                            ? "#ef4444"
                                                                                            : "#f59e0b" ?>;"></i>
                                        <div style="flex:1;">
                                            <div><?= $a["msg"] ?></div>
                                            <div class="pred-alert-reason"><i class="fas fa-info-circle"></i> <?= $a["reason"] ?></div>
                                        </div>
                                        <?php if (isset($a["link"])): ?>
                                            <div style="margin-left:auto;">
                                                <a href="<?= $a["link"] ?>" class="btn btn-sm" style="background:#fff; color:#1e293b; border:1px solid #cbd5e1; font-weight:600; font-size:0.75rem; border-radius:6px; text-decoration:none;">Investigate <i class="fa-solid fa-arrow-right" style="margin-left:4px;"></i></a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="pred-alert pred-alert-success">
                                    <i class="fas fa-check-circle" style="color:#10b981;"></i>
                                    <div>No anomalies detected — all metrics are within normal ranges</div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- ── TAB: Tips ── -->
                        <div class="pred-panel" id="pred-tips">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                                <span style="font-size:0.82rem; color:#374151; font-weight:600;">Smart Recommendations</span>
                            </div>
                            <?php foreach ($recommendations as $r): ?>
                                <div class="pred-rec pred-rec-<?= $r["priority"] ?>">
                                    <i class="fas <?= $r["icon"] ?>"></i>
                                    <div><?= $r["msg"] ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Unified Queries Chart with Toggle -->
                    <div class="chart-card">
                        <div class="card-header"
                            style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                            <h2 class="card-title">
                                <i class="fas fa-chart-bar"></i>
                                Chatlog Queries
                            </h2>
                            <div class="chart-time-toggle">
                                <button class="active" onclick="switchQueryView('hour')" id="qvHour">By
                                    Hour</button>
                                <button onclick="switchQueryView('week')" id="qvWeek">By
                                    Week</button>
                                <button onclick="switchQueryView('month')" id="qvMonth">By
                                    Month</button>
                            </div>
                            <!-- 12/24hr + Range controls (shown only in Hour view) -->
                            <div id="hourlyChartControls" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                                <select id="hourRangeSelect" onchange="applyHourFilter()"
                                    style="padding:4px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:0.78rem;">
                                    <option value="all">All Hours (0–23)</option>
                                    <option value="morning">Morning (6–11)</option>
                                    <option value="afternoon">Afternoon (12–17)</option>
                                    <option value="evening">Evening (18–21)</option>
                                    <option value="night">Night (22–5)</option>
                                </select>
                                <div style="display:flex;gap:2px;">
                                    <button id="dashBtn24" onclick="setDashHourFmt(24)"
                                        style="padding:3px 9px;border:1px solid #3b82f6;border-radius:5px 0 0 5px;font-size:0.76rem;background:#3b82f6;color:#fff;cursor:pointer;">24hr</button>
                                    <button id="dashBtn12" onclick="setDashHourFmt(12)"
                                        style="padding:3px 9px;border:1px solid #d1d5db;border-radius:0 5px 5px 0;font-size:0.76rem;background:#fff;color:#374151;cursor:pointer;">12hr</button>
                                </div>
                            </div>
                        </div>
                        <div class="chart-wrapper">
                            <canvas id="unifiedQueryChart"></canvas>
                        </div>
                        <div class="insight-box" id="queryInsight">
                            <p><strong>Peak Activity Range:</strong> Users are most active between
                                <?php echo $time_range ??
                                    "14:00-18:00"; ?> (<?php echo $max_range_count ??
                                                            450; ?>
                                queries).
                            </p>
                            <p><strong>Total Queries:</strong> <?php echo $total_queries_24hr ??
                                                                    3240; ?> queries in
                                the last 24 hours.</p>
                        </div>
                    </div>

                    <!-- Throughput Chart (Full Width) -->
                    <div style="margin-bottom: 32px;">
                        <div class="chart-card">
                            <div class="card-header" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                                <h2 class="card-title"><i class="fas fa-tachometer-alt"></i> System Throughput</h2>
                                <div class="chart-time-toggle">
                                    <button class="active" onclick="switchThroughputView('daily')" id="tpDaily">Daily</button>
                                    <button onclick="switchThroughputView('weekly')" id="tpWeekly">Weekly</button>
                                    <button onclick="switchThroughputView('monthly')" id="tpMonthly">Monthly</button>
                                </div>
                            </div>
                            <div class="chart-wrapper"><canvas id="dailyMetricsChart"></canvas></div>
                            <div class="insight-box" id="throughputInsight">
                                <p>Total conversations, successful responses, failed responses, and errors over time.</p>
                            </div>
                        </div>
                    </div>


                    <!-- User Sessions Trend -->
                    <div class="chart-card">
                        <div class="card-header">
                            <h2 class="card-title">
                                <i class="fas fa-user-clock"></i>
                                User Sessions: Duration & Queries Over Time
                            </h2>
                        </div>
                        <div class="chart-wrapper">
                            <canvas id="userChart"></canvas>
                        </div>
                        <div class="insight-box">
                            <p><strong>Overview:</strong> Tracks average session duration and queries per user over time.</p>
                            <p><strong>Stats:</strong> <?= count(
                                                            $session_trends,
                                                        ) ?> days of data,
                                Avg duration: <?= count($user_sessions ?? [])
                                                    ? round(
                                                        array_sum(
                                                            array_column(
                                                                $user_sessions,
                                                                "session_duration",
                                                            ),
                                                        ) / count($user_sessions),
                                                        1,
                                                    )
                                                    : 0 ?>s,
                                Avg queries: <?= count($user_sessions ?? [])
                                                    ? round(
                                                        array_sum(
                                                            array_column(
                                                                $user_sessions,
                                                                "number_of_queries",
                                                            ),
                                                        ) / count($user_sessions),
                                                        1,
                                                    )
                                                    : 0 ?>
                            </p>
                        </div>
                    </div>

                </div>

                <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
                <script>
                    // Modern color palette inspired by the reference designs
                    const colors = {
                        cyan: '#00d9ff',
                        purple: '#a855f7',
                        pink: '#ec4899',
                        orange: '#f97316',
                        green: '#10b981',
                        yellow: '#fbbf24',
                        blue: '#3b82f6',
                        gradient1: ['#667eea', '#764ba2'],
                        gradient2: ['#f093fb', '#f5576c'],
                        gradient3: ['#4facfe', '#00f2fe'],
                        gradient4: ['#43e97b', '#38f9d7'],
                        gradient5: ['#fa709a', '#fee140'],
                        gradientRainbow: ['#667eea', '#764ba2', '#f093fb', '#f5576c', '#4facfe', '#00f2fe', '#43e97b', '#38f9d7']
                    };

                    // Create advanced gradient
                    function createGradient(ctx, area, colorStops) {
                        const gradient = ctx.createLinearGradient(0, area.bottom, 0, area.top);
                        colorStops.forEach((color, index) => {
                            gradient.addColorStop(index / (colorStops.length - 1), color);
                        });
                        return gradient;
                    }

                    // Create radial gradient
                    function createRadialGradient(ctx, area, colorStops) {
                        const centerX = (area.left + area.right) / 2;
                        const centerY = (area.top + area.bottom) / 2;
                        const radius = Math.min(area.right - area.left, area.bottom - area.top) / 2;
                        const gradient = ctx.createRadialGradient(centerX, centerY, 0, centerX, centerY, radius);
                        colorStops.forEach((color, index) => {
                            gradient.addColorStop(index / (colorStops.length - 1), color);
                        });
                        return gradient;
                    }

                    // Default chart options
                    const defaultOptions = {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top',
                                labels: {
                                    color: '#6b7280',
                                    font: {
                                        family: 'Inter',
                                        size: 12,
                                        weight: '600'
                                    },
                                    padding: 16,
                                    usePointStyle: true,
                                    pointStyle: 'circle'
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(255, 255, 255, 0.98)',
                                titleColor: '#002147',
                                bodyColor: '#6b7280',
                                borderColor: 'rgba(0, 33, 71, 0.1)',
                                borderWidth: 1,
                                cornerRadius: 12,
                                padding: 16,
                                displayColors: true,
                                titleFont: {
                                    family: 'Inter',
                                    size: 14,
                                    weight: '600'
                                },
                                bodyFont: {
                                    family: 'Inter',
                                    size: 13
                                },
                                callbacks: {
                                    labelColor: function(context) {
                                        return {
                                            borderColor: 'transparent',
                                            backgroundColor: context.dataset.borderColor || context.dataset.backgroundColor,
                                            borderRadius: 4
                                        };
                                    }
                                }
                            }
                        },
                        animation: {
                            duration: 2000,
                            easing: 'easeInOutQuart',
                            delay: (context) => {
                                return context.type === 'data' && context.mode === 'default' ? context.dataIndex * 40 : 0;
                            }
                        },
                        scales: {
                            x: {
                                grid: {
                                    display: false,
                                    drawBorder: false
                                },
                                ticks: {
                                    color: '#6b7280',
                                    font: {
                                        family: 'Inter',
                                        size: 11,
                                        weight: '500'
                                    },
                                    padding: 8
                                },
                                title: {
                                    display: true,
                                    text: '',
                                    color: '#6b7280',
                                    font: {
                                        family: 'Inter',
                                        size: 12,
                                        weight: '600'
                                    }
                                }
                            },
                            y: {
                                grid: {
                                    color: 'rgba(0, 33, 71, 0.06)',
                                    drawBorder: false,
                                    lineWidth: 1
                                },
                                ticks: {
                                    color: '#6b7280',
                                    font: {
                                        family: 'Inter',
                                        size: 11,
                                        weight: '500'
                                    },
                                    padding: 8
                                },
                                title: {
                                    display: true,
                                    text: '',
                                    color: '#6b7280',
                                    font: {
                                        family: 'Inter',
                                        size: 12,
                                        weight: '600'
                                    }
                                }
                            }
                        }
                    };

                    // Sample data (replace with your PHP data)
                    const sampleHourlyData = Array.from({
                        length: 24
                    }, (_, i) => ({
                        hour: i,
                        count: Math.floor(Math.random() * 200) + 50
                    }));
                    const sampleWeeklyData = Array.from({
                        length: 12
                    }, (_, i) => ({
                        week: i + 1,
                        count: Math.floor(Math.random() * 500) + 200
                    }));
                    const sampleMonthlyData = Array.from({
                        length: 12
                    }, (_, i) => ({
                        month: `2024-${String(i + 1).padStart(2, '0')}`,
                        count: Math.floor(Math.random() * 800) + 400
                    }));

                    // Unified Query Chart: Hour / Week / Month (toggle)
                    const queryChartData = {
                        hour: {
                            type: 'bar',
                            labels: <?php echo json_encode(
                                        array_map(function ($h) {
                                            return sprintf("%02d:00", $h["hour"]);
                                        }, $chatlogs_by_hour ?? []),
                                    ); ?> || sampleHourlyData.map(h => `${String(h.hour).padStart(2, '0')}:00`),
                            data: <?php echo json_encode(
                                        array_column($chatlogs_by_hour ?? [], "count"),
                                    ); ?> || sampleHourlyData.map(h => h.count),
                            label: 'Queries by Hour',
                            indexAxis: 'y',
                            insight: '<p><strong>Peak Activity:</strong> Users are most active between <?php echo addslashes(
                                                                                                            $time_range ?? "14:00-18:00",
                                                                                                        ); ?> (<?php echo $max_range_count ??
                                                                                                                    450; ?> queries).</p><p><strong>Total Queries:</strong> <?php echo $total_queries_24hr ??
                                                                                                                                                                                3240; ?> in the last 24 hours.</p>'
                        },
                        week: {
                            type: 'line',
                            labels: <?php echo json_encode(
                                        array_map(function ($row) {
                                            return "W" . $row["week"];
                                        }, $chatlogs_per_week ?? []),
                                    ); ?> || sampleWeeklyData.map(w => `W${w.week}`),
                            data: <?php echo json_encode(
                                        array_column($chatlogs_per_week ?? [], "count"),
                                    ); ?> || sampleWeeklyData.map(w => w.count),
                            label: 'Queries Per Week',
                            indexAxis: 'x',
                            insight: '<p><strong>Overview:</strong> Number of user queries received each week.</p>'
                        },
                        month: {
                            type: 'line',
                            labels: <?php echo json_encode(
                                        array_column(
                                            $chatlogs_per_month ?? [],
                                            "month",
                                        ),
                                    ); ?> || sampleMonthlyData.map(m => m.month),
                            data: <?php echo json_encode(
                                        array_column(
                                            $chatlogs_per_month ?? [],
                                            "count",
                                        ),
                                    ); ?> || sampleMonthlyData.map(m => m.count),
                            label: 'Queries Per Month',
                            indexAxis: 'x',
                            insight: '<p><strong>Overview:</strong> Monthly query volume trend.</p>'
                        }
                    };

                    let unifiedQueryChart = null;
                    const queryCanvas = document.getElementById('unifiedQueryChart');

                    function switchQueryView(view) {
                        document.querySelectorAll('#qvHour, #qvWeek, #qvMonth').forEach(b => b.classList.remove('active'));
                        document.getElementById('qv' + view.charAt(0).toUpperCase() + view.slice(1)).classList.add('active');
                        const d = queryChartData[view];
                        document.getElementById('queryInsight').innerHTML = d.insight;
                        if (unifiedQueryChart) unifiedQueryChart.destroy();
                        const ctx = queryCanvas.getContext('2d');
                        unifiedQueryChart = new Chart(ctx, {
                            type: d.type,
                            data: {
                                labels: d.labels,
                                datasets: [{
                                    label: d.label,
                                    data: d.data,
                                    backgroundColor: function(context) {
                                        const chart = context.chart;
                                        const {
                                            ctx: c,
                                            chartArea
                                        } = chart;
                                        if (!chartArea) return 'rgba(102,126,234,0.8)';
                                        const g = c.createLinearGradient(
                                            d.type === 'bar' ? chartArea.left : 0,
                                            d.type === 'bar' ? 0 : chartArea.bottom,
                                            d.type === 'bar' ? chartArea.right : 0,
                                            d.type === 'bar' ? 0 : chartArea.top
                                        );
                                        g.addColorStop(0, 'rgba(102,126,234,0.8)');
                                        g.addColorStop(0.5, 'rgba(168,85,247,0.6)');
                                        g.addColorStop(1, 'rgba(236,72,153,0.4)');
                                        return g;
                                    },
                                    borderColor: d.type === 'line' ? colors.cyan : undefined,
                                    borderRadius: d.type === 'bar' ? 8 : undefined,
                                    borderSkipped: false,
                                    fill: d.type === 'line',
                                    tension: 0.4,
                                    borderWidth: d.type === 'line' ? 3 : undefined,
                                    pointRadius: 0,
                                    pointHoverRadius: 8,
                                    pointHoverBackgroundColor: colors.cyan,
                                    pointHoverBorderColor: '#fff',
                                    pointHoverBorderWidth: 3
                                }]
                            },
                            options: {
                                ...defaultOptions,
                                indexAxis: d.indexAxis,
                                interaction: {
                                    intersect: false,
                                    mode: 'index'
                                },
                                scales: {
                                    x: {
                                        ...defaultOptions.scales.x,
                                        beginAtZero: true,
                                        title: {
                                            display: true,
                                            text: view === 'hour' ? 'Hour of Day' : (view === 'week' ? 'Week' : 'Month'),
                                            color: '#6b7280',
                                            font: {
                                                family: 'Inter',
                                                size: 12,
                                                weight: '600'
                                            }
                                        }
                                    },
                                    y: {
                                        ...defaultOptions.scales.y,
                                        beginAtZero: true,
                                        title: {
                                            display: true,
                                            text: 'Number of Queries',
                                            color: '#6b7280',
                                            font: {
                                                family: 'Inter',
                                                size: 12,
                                                weight: '600'
                                            }
                                        }
                                    }
                                }
                            }
                        });
                        // show/hide hourly controls
                        const ctrl = document.getElementById('hourlyChartControls');
                        if (ctrl) ctrl.style.display = view === 'hour' ? 'flex' : 'none';
                    }
                    switchQueryView('hour');

                    // ── Hourly chart: 12/24hr format + range filter ──
                    const allHourlyRawLabels = <?php echo json_encode(
                                                    array_map(function ($h) {
                                                        return sprintf("%02d:00", $h["hour"]);
                                                    }, $chatlogs_by_hour ?? []),
                                                ); ?>;
                    const allHourlyData = <?php echo json_encode(
                                                array_column($chatlogs_by_hour ?? [], "count"),
                                            ); ?>;
                    let dashHourFmt = 24;

                    const hourRanges = {
                        all: {
                            min: 0,
                            max: 23
                        },
                        morning: {
                            min: 6,
                            max: 11
                        },
                        afternoon: {
                            min: 12,
                            max: 17
                        },
                        evening: {
                            min: 18,
                            max: 21
                        },
                        night: null // 22–5 wraps around
                    };

                    function fmtHourLabel(label24, fmt) {
                        // label24 = "14:00"
                        const h = parseInt(label24);
                        if (fmt === 24) return label24;
                        if (h === 0) return '12:00 AM';
                        if (h === 12) return '12:00 PM';
                        return h > 12 ? `${h-12}:00 PM` : `${h}:00 AM`;
                    }

                    function applyHourFilter() {
                        const rangeKey = document.getElementById('hourRangeSelect').value;
                        const range = hourRanges[rangeKey];
                        let filteredLabels, filteredData;
                        if (!range) { // night: 22–5
                            filteredLabels = [];
                            filteredData = [];
                            allHourlyRawLabels.forEach((lbl, i) => {
                                const h = parseInt(lbl);
                                if (h >= 22 || h <= 5) {
                                    filteredLabels.push(fmtHourLabel(lbl, dashHourFmt));
                                    filteredData.push(allHourlyData[i] || 0);
                                }
                            });
                        } else {
                            filteredLabels = [];
                            filteredData = [];
                            allHourlyRawLabels.forEach((lbl, i) => {
                                const h = parseInt(lbl);
                                if (h >= range.min && h <= range.max) {
                                    filteredLabels.push(fmtHourLabel(lbl, dashHourFmt));
                                    filteredData.push(allHourlyData[i] || 0);
                                }
                            });
                        }
                        if (unifiedQueryChart && unifiedQueryChart.config.type === 'bar') {
                            unifiedQueryChart.data.labels = filteredLabels;
                            unifiedQueryChart.data.datasets[0].data = filteredData;
                            unifiedQueryChart.update();
                        }
                    }

                    function setDashHourFmt(fmt) {
                        dashHourFmt = fmt;
                        document.getElementById('dashBtn24').style.cssText =
                            fmt === 24 ? 'padding:3px 9px;border:1px solid #3b82f6;border-radius:5px 0 0 5px;font-size:0.76rem;background:#3b82f6;color:#fff;cursor:pointer;' :
                            'padding:3px 9px;border:1px solid #d1d5db;border-radius:5px 0 0 5px;font-size:0.76rem;background:#fff;color:#374151;cursor:pointer;';
                        document.getElementById('dashBtn12').style.cssText =
                            fmt === 12 ? 'padding:3px 9px;border:1px solid #3b82f6;border-radius:0 5px 5px 0;font-size:0.76rem;background:#3b82f6;color:#fff;cursor:pointer;' :
                            'padding:3px 9px;border:1px solid #d1d5db;border-radius:0 5px 5px 0;font-size:0.76rem;background:#fff;color:#374151;cursor:pointer;';
                        applyHourFilter(); // re-render with new format
                    }



                    // Chart 7: Feedback Pie
                    const feedbackCtx = document.getElementById('feedbackChart').getContext('2d');
                    new Chart(feedbackCtx, {
                        type: 'doughnut',
                        data: {
                            labels: [
                                ...<?php echo json_encode(
                                        array_map(function ($row) {
                                            return ucfirst($row["rating"] ?? "N/A");
                                        }, $feedback_counts ?? []),
                                    ); ?>,
                                '👍 Thumbs Up',
                                '👎 Thumbs Down'
                            ],
                            datasets: [{
                                data: [
                                    ...<?php echo json_encode(
                                            array_map(
                                                "intval",
                                                array_column(
                                                    $feedback_counts ?? [],
                                                    "count",
                                                ),
                                            ),
                                        ); ?>,
                                    <?= $thumbs_up_reactions ?>,
                                    <?= $thumbs_down_reactions ?>
                                ],
                                backgroundColor: [...colors.gradientRainbow.slice(0, <?= count(
                                                                                            $feedback_counts ?? [],
                                                                                        ) ?>).map(c => c + 'CC'), '#10b981CC', '#ec4899CC'],
                                borderColor: '#e5e7eb',
                                borderWidth: 4,
                                hoverOffset: 20
                            }]
                        },
                        options: {
                            ...defaultOptions,
                            cutout: '65%',
                            plugins: {
                                ...defaultOptions.plugins,
                                legend: {
                                    ...defaultOptions.plugins.legend,
                                    position: 'right'
                                }
                            }
                        }
                    });

                    // Chart 8: Feedback Over Time
                    const feedbackTimeCtx = document.getElementById('feedbackOverTimeChart').getContext('2d');
                    <?php
                    // Process feedback_over_time + reactions_over_time: merge typed + reaction feedback
                    $fb_likes = [];
                    $fb_dislikes = []; // Typed feedback: excellent/good = like, bad = dislike
                    foreach ($feedback_over_time ?? [] as $fb) {
                        $d = $fb["date"];
                        if (!isset($fb_likes[$d])) {
                            $fb_likes[$d] = 0;
                            $fb_dislikes[$d] = 0;
                        }
                        if (
                            in_array($fb["rating"] ?? "", ["excellent", "good"])
                        ) {
                            $fb_likes[$d] += (int) $fb["count"];
                        } else {
                            $fb_dislikes[$d] += (int) $fb["count"];
                        }
                    } // Reaction feedback: thumbs_up/helpful/accurate = like, rest = dislike
                    foreach ($reactions_over_time ?? [] as $rx) {
                        $d = $rx["date"];
                        if (!isset($fb_likes[$d])) {
                            $fb_likes[$d] = 0;
                            $fb_dislikes[$d] = 0;
                        }
                        if (
                            in_array($rx["reaction_type"], [
                                "thumbs_up",
                                "helpful",
                                "accurate",
                            ])
                        ) {
                            $fb_likes[$d] += (int) $rx["count"];
                        } else {
                            $fb_dislikes[$d] += (int) $rx["count"];
                        }
                    } // Sort by date
                    ksort($fb_likes);
                    ksort($fb_dislikes);
                    $fb_dates = array_keys($fb_likes);
                    ?>
                    const feedbackDates = <?php echo json_encode(
                                                array_values($fb_dates),
                                            ); ?> || [];
                    new Chart(feedbackTimeCtx, {
                        type: 'line',
                        data: {
                            labels: feedbackDates,
                            datasets: [{
                                    label: 'Likes',
                                    data: <?php echo json_encode(
                                                array_values($fb_likes),
                                            ); ?> || [],
                                    borderColor: colors.green,
                                    backgroundColor: function(context) {
                                        const chart = context.chart;
                                        const {
                                            ctx,
                                            chartArea
                                        } = chart;
                                        if (!chartArea) return;
                                        return createGradient(ctx, chartArea, [
                                            'rgba(16, 185, 129, 0)',
                                            'rgba(16, 185, 129, 0.3)'
                                        ]);
                                    },
                                    fill: true,
                                    tension: 0.4,
                                    borderWidth: 3,
                                    pointRadius: 0,
                                    pointHoverRadius: 8,
                                    pointHoverBackgroundColor: colors.green,
                                    pointHoverBorderColor: '#fff',
                                    pointHoverBorderWidth: 3
                                },
                                {
                                    label: 'Dislikes',
                                    data: <?php echo json_encode(
                                                array_values($fb_dislikes),
                                            ); ?> || [],
                                    borderColor: colors.pink,
                                    backgroundColor: function(context) {
                                        const chart = context.chart;
                                        const {
                                            ctx,
                                            chartArea
                                        } = chart;
                                        if (!chartArea) return;
                                        return createGradient(ctx, chartArea, [
                                            'rgba(236, 72, 153, 0)',
                                            'rgba(236, 72, 153, 0.3)'
                                        ]);
                                    },
                                    fill: true,
                                    tension: 0.4,
                                    borderWidth: 3,
                                    pointRadius: 0,
                                    pointHoverRadius: 8,
                                    pointHoverBackgroundColor: colors.pink,
                                    pointHoverBorderColor: '#fff',
                                    pointHoverBorderWidth: 3
                                }
                            ]
                        },
                        options: {
                            ...defaultOptions,
                            interaction: {
                                intersect: false,
                                mode: 'index'
                            },
                            scales: {
                                ...defaultOptions.scales,
                                x: {
                                    ...defaultOptions.scales.x,
                                    title: {
                                        display: true,
                                        text: 'Date',
                                        color: '#6b7280',
                                        font: {
                                            family: 'Inter',
                                            size: 12,
                                            weight: '600'
                                        }
                                    }
                                },
                                y: {
                                    ...defaultOptions.scales.y,
                                    beginAtZero: true,
                                    title: {
                                        display: true,
                                        text: 'Reactions',
                                        color: '#6b7280',
                                        font: {
                                            family: 'Inter',
                                            size: 12,
                                            weight: '600'
                                        }
                                    }
                                }
                            }
                        }
                    });

                    // Chart 9: User Sessions — Trend over time (dual-axis line)
                    const userCtx = document.getElementById('userChart').getContext('2d');
                    new Chart(userCtx, {
                        type: 'line',
                        data: {
                            labels: <?php echo json_encode(
                                        array_column($session_trends ?? [], "date"),
                                    ); ?> || [],
                            datasets: [{
                                    label: 'Avg Duration (min)',
                                    data: <?php echo json_encode(
                                                array_map(function ($v) {
                                                    return round($v / 60, 1);
                                                }, array_column(
                                                    $session_trends ?? [],
                                                    "avg_duration",
                                                )),
                                            ); ?> || [],
                                    borderColor: colors.cyan,
                                    backgroundColor: function(context) {
                                        const chart = context.chart;
                                        const {
                                            ctx,
                                            chartArea
                                        } = chart;
                                        if (!chartArea) return 'rgba(0,217,255,0.1)';
                                        const g = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
                                        g.addColorStop(0, 'rgba(0,217,255,0)');
                                        g.addColorStop(1, 'rgba(0,217,255,0.25)');
                                        return g;
                                    },
                                    fill: true,
                                    tension: 0.4,
                                    borderWidth: 3,
                                    pointRadius: 0,
                                    pointHoverRadius: 8,
                                    pointHoverBackgroundColor: colors.cyan,
                                    pointHoverBorderColor: '#fff',
                                    pointHoverBorderWidth: 3,
                                    yAxisID: 'y'
                                },
                                {
                                    label: 'Avg Queries',
                                    data: <?php echo json_encode(
                                                array_column(
                                                    $session_trends ?? [],
                                                    "avg_queries",
                                                ),
                                            ); ?> || [],
                                    borderColor: colors.purple,
                                    backgroundColor: function(context) {
                                        const chart = context.chart;
                                        const {
                                            ctx,
                                            chartArea
                                        } = chart;
                                        if (!chartArea) return 'rgba(168,85,247,0.1)';
                                        const g = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
                                        g.addColorStop(0, 'rgba(168,85,247,0)');
                                        g.addColorStop(1, 'rgba(168,85,247,0.25)');
                                        return g;
                                    },
                                    fill: true,
                                    tension: 0.4,
                                    borderWidth: 3,
                                    pointRadius: 0,
                                    pointHoverRadius: 8,
                                    pointHoverBackgroundColor: colors.purple,
                                    pointHoverBorderColor: '#fff',
                                    pointHoverBorderWidth: 3,
                                    yAxisID: 'y1'
                                },
                                {
                                    label: 'Sessions',
                                    data: <?php echo json_encode(
                                                array_column(
                                                    $session_trends ?? [],
                                                    "session_count",
                                                ),
                                            ); ?> || [],
                                    borderColor: 'rgba(236,72,153,0.7)',
                                    backgroundColor: 'transparent',
                                    borderDash: [5, 5],
                                    tension: 0.4,
                                    borderWidth: 2,
                                    pointRadius: 0,
                                    pointHoverRadius: 6,
                                    yAxisID: 'y1'
                                }
                            ]
                        },
                        options: {
                            ...defaultOptions,
                            interaction: {
                                intersect: false,
                                mode: 'index'
                            },
                            plugins: {
                                ...defaultOptions.plugins,
                                tooltip: {
                                    ...defaultOptions.plugins.tooltip,
                                    callbacks: {
                                        ...defaultOptions.plugins.tooltip.callbacks,
                                        label: function(ctx) {
                                            const v = ctx.parsed.y;
                                            if (ctx.dataset.label === 'Avg Duration (min)') return `Duration: ${v} min`;
                                            if (ctx.dataset.label === 'Avg Queries') return `Queries: ${v}`;
                                            return `Sessions: ${v}`;
                                        }
                                    }
                                }
                            },
                            scales: {
                                x: {
                                    ...defaultOptions.scales.x
                                },
                                y: {
                                    ...defaultOptions.scales.y,
                                    type: 'linear',
                                    position: 'left',
                                    beginAtZero: true,
                                    title: {
                                        display: true,
                                        text: 'Duration (min)',
                                        color: '#6b7280',
                                        font: {
                                            family: 'Inter',
                                            size: 12,
                                            weight: '600'
                                        }
                                    }
                                },
                                y1: {
                                    type: 'linear',
                                    position: 'right',
                                    beginAtZero: true,
                                    grid: {
                                        drawOnChartArea: false
                                    },
                                    ticks: {
                                        color: '#6b7280',
                                        font: {
                                            family: 'Inter',
                                            size: 11,
                                            weight: '500'
                                        }
                                    },
                                    title: {
                                        display: true,
                                        text: 'Queries / Sessions',
                                        color: '#6b7280',
                                        font: {
                                            family: 'Inter',
                                            size: 12,
                                            weight: '600'
                                        }
                                    }
                                }
                            }
                        }
                    });

                    // Chart 10: Throughput (Daily / Weekly / Monthly toggle)
                    const throughputData = {
                        daily: {
                            labels: <?php echo json_encode(
                                        array_column($daily_metrics ?? [], "date"),
                                    ); ?> || [],
                            total: <?php echo json_encode(
                                        array_map(
                                            "intval",
                                            array_column(
                                                $daily_metrics ?? [],
                                                "total_conversations",
                                            ),
                                        ),
                                    ); ?> || [],
                            success: <?php echo json_encode(
                                            array_map(
                                                "intval",
                                                array_column(
                                                    $daily_metrics ?? [],
                                                    "successful_responses",
                                                ),
                                            ),
                                        ); ?> || [],
                            failed: <?php echo json_encode(
                                        array_map(
                                            "intval",
                                            array_column(
                                                $daily_metrics ?? [],
                                                "failed_responses",
                                            ),
                                        ),
                                    ); ?> || [],
                            errors: <?php echo json_encode(
                                        array_map(
                                            "intval",
                                            array_column(
                                                $daily_metrics ?? [],
                                                "error_count",
                                            ),
                                        ),
                                    ); ?> || [],
                            insight: '<p><strong>Daily:</strong> Showing day-by-day throughput of conversations, successes, failures, and errors.</p>'
                        },
                        weekly: {
                            labels: <?php echo json_encode(
                                        array_column(
                                            $weekly_throughput ?? [],
                                            "week_label",
                                        ),
                                    ); ?> || [],
                            total: <?php echo json_encode(
                                        array_map(
                                            "intval",
                                            array_column(
                                                $weekly_throughput ?? [],
                                                "total_conversations",
                                            ),
                                        ),
                                    ); ?> || [],
                            success: <?php echo json_encode(
                                            array_map(
                                                "intval",
                                                array_column(
                                                    $weekly_throughput ?? [],
                                                    "successful_responses",
                                                ),
                                            ),
                                        ); ?> || [],
                            failed: <?php echo json_encode(
                                        array_map(
                                            "intval",
                                            array_column(
                                                $weekly_throughput ?? [],
                                                "failed_responses",
                                            ),
                                        ),
                                    ); ?> || [],
                            errors: [],
                            insight: '<p><strong>Weekly:</strong> Aggregated throughput per week.</p>'
                        },
                        monthly: {
                            labels: <?php echo json_encode(
                                        array_column(
                                            $monthly_throughput ?? [],
                                            "month_label",
                                        ),
                                    ); ?> || [],
                            total: <?php echo json_encode(
                                        array_map(
                                            "intval",
                                            array_column(
                                                $monthly_throughput ?? [],
                                                "total_conversations",
                                            ),
                                        ),
                                    ); ?> || [],
                            success: <?php echo json_encode(
                                            array_map(
                                                "intval",
                                                array_column(
                                                    $monthly_throughput ?? [],
                                                    "successful_responses",
                                                ),
                                            ),
                                        ); ?> || [],
                            failed: <?php echo json_encode(
                                        array_map(
                                            "intval",
                                            array_column(
                                                $monthly_throughput ?? [],
                                                "failed_responses",
                                            ),
                                        ),
                                    ); ?> || [],
                            errors: [],
                            insight: '<p><strong>Monthly:</strong> Month-over-month throughput trends.</p>'
                        }
                    };

                    let throughputChart = null;
                    const throughputCanvas = document.getElementById('dailyMetricsChart');

                    function makeThroughputDataset(label, data, borderCol, gradientStops) {
                        return {
                            label: label,
                            data: data,
                            borderColor: borderCol,
                            backgroundColor: function(context) {
                                const chart = context.chart;
                                const {
                                    ctx,
                                    chartArea
                                } = chart;
                                if (!chartArea) return gradientStops[1];
                                const g = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
                                g.addColorStop(0, gradientStops[0]);
                                g.addColorStop(1, gradientStops[1]);
                                return g;
                            },
                            fill: true,
                            tension: 0.4,
                            borderWidth: 3,
                            pointRadius: 0,
                            pointHoverRadius: 8,
                            pointHoverBackgroundColor: borderCol,
                            pointHoverBorderColor: '#fff',
                            pointHoverBorderWidth: 3
                        };
                    }

                    function switchThroughputView(view) {
                        document.querySelectorAll('#tpDaily, #tpWeekly, #tpMonthly').forEach(b => b.classList.remove('active'));
                        document.getElementById('tp' + view.charAt(0).toUpperCase() + view.slice(1)).classList.add('active');
                        const d = throughputData[view];
                        document.getElementById('throughputInsight').innerHTML = d.insight;
                        if (throughputChart) throughputChart.destroy();
                        const ctx = throughputCanvas.getContext('2d');
                        const datasets = [
                            makeThroughputDataset('Total Conversations', d.total, 'rgba(102,126,234,0.9)', ['rgba(102,126,234,0)', 'rgba(102,126,234,0.25)']),
                            makeThroughputDataset('Successful', d.success, colors.green, ['rgba(16,185,129,0)', 'rgba(16,185,129,0.25)']),
                            makeThroughputDataset('Failed', d.failed, colors.pink, ['rgba(236,72,153,0)', 'rgba(236,72,153,0.25)']),
                        ];
                        if (d.errors && d.errors.length > 0) {
                            datasets.push(makeThroughputDataset('Errors', d.errors, colors.orange, ['rgba(249,115,22,0)', 'rgba(249,115,22,0.25)']));
                        }
                        throughputChart = new Chart(ctx, {
                            type: 'line',
                            data: {
                                labels: d.labels,
                                datasets: datasets
                            },
                            options: {
                                ...defaultOptions,
                                interaction: {
                                    intersect: false,
                                    mode: 'index'
                                },
                                plugins: {
                                    ...defaultOptions.plugins,
                                    tooltip: {
                                        ...defaultOptions.plugins.tooltip,
                                        callbacks: {
                                            ...defaultOptions.plugins.tooltip.callbacks,
                                            label: ctx => `${ctx.dataset.label}: ${ctx.parsed.y}`
                                        }
                                    }
                                },
                                scales: {
                                    ...defaultOptions.scales,
                                    x: {
                                        ...defaultOptions.scales.x,
                                        title: {
                                            display: true,
                                            text: 'Date',
                                            color: '#6b7280',
                                            font: {
                                                family: 'Inter',
                                                size: 12,
                                                weight: '600'
                                            }
                                        }
                                    },
                                    y: {
                                        ...defaultOptions.scales.y,
                                        beginAtZero: true,
                                        title: {
                                            display: true,
                                            text: 'Count',
                                            color: '#6b7280',
                                            font: {
                                                family: 'Inter',
                                                size: 12,
                                                weight: '600'
                                            }
                                        }
                                    }
                                }
                            }
                        });
                    }
                    switchThroughputView('daily');
                </script>

                <!-- ── Predictions Section JS ── -->
                <script>
                    // Tab switching
                    function switchPredTab(tab) {
                        document.querySelectorAll('.pred-tab').forEach(t => t.classList.remove('active'));
                        document.querySelectorAll('.pred-panel').forEach(p => p.classList.remove('active'));
                        document.getElementById('pred-' + tab).classList.add('active');
                        event.target.closest('.pred-tab').classList.add('active');
                    }

                    // Forecast chart — actual + trend + prediction
                    (function() {
                        const actual = <?= json_encode($fc_daily) ?>;
                        const n = actual.length;
                        const slope = <?= round($fc_slope, 4) ?>;
                        const intercept = <?= round($fc_intercept, 4) ?>;

                        // Build labels: last N days + 7 forecast days
                        const labels = [];
                        const trendLine = [];
                        const forecastData = [];
                        const actualData = [];
                        for (let i = 0; i < n + 7; i++) {
                            const d = new Date();
                            d.setDate(d.getDate() - (n - 1) + i);
                            labels.push(d.toLocaleDateString('en-US', {
                                month: 'short',
                                day: 'numeric'
                            }));
                            const trendVal = Math.max(0, Math.round(intercept + slope * i));
                            trendLine.push(trendVal);
                            if (i < n) {
                                actualData.push(actual[i]);
                                forecastData.push(null);
                            } else {
                                actualData.push(null);
                                forecastData.push(trendVal);
                            }
                        }

                        const fcCtx = document.getElementById('predForecastChart');
                        if (fcCtx) {
                            new Chart(fcCtx, {
                                type: 'line',
                                data: {
                                    labels: labels,
                                    datasets: [{
                                            label: 'Actual',
                                            data: actualData,
                                            borderColor: '#3b82f6',
                                            backgroundColor: 'rgba(59,130,246,0.08)',
                                            fill: true,
                                            tension: 0.4,
                                            borderWidth: 2.5,
                                            pointRadius: 0,
                                            pointHoverRadius: 5,
                                        },
                                        {
                                            label: 'Trend',
                                            data: trendLine,
                                            borderColor: 'rgba(124,58,237,0.5)',
                                            borderDash: [6, 3],
                                            borderWidth: 1.5,
                                            pointRadius: 0,
                                            fill: false,
                                        },
                                        {
                                            label: 'Forecast',
                                            data: forecastData,
                                            borderColor: '#ec4899',
                                            backgroundColor: 'rgba(236,72,153,0.1)',
                                            borderDash: [4, 4],
                                            fill: true,
                                            tension: 0.4,
                                            borderWidth: 2.5,
                                            pointRadius: 0,
                                            pointHoverRadius: 5,
                                        }
                                    ]
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    plugins: {
                                        legend: {
                                            display: true,
                                            position: 'top',
                                            labels: {
                                                color: '#6b7280',
                                                font: {
                                                    size: 11
                                                },
                                                usePointStyle: true,
                                                pointStyle: 'line',
                                                padding: 12
                                            }
                                        },
                                        tooltip: {
                                            backgroundColor: 'rgba(255,255,255,0.95)',
                                            titleColor: '#002147',
                                            bodyColor: '#6b7280',
                                            borderColor: '#e2e8f0',
                                            borderWidth: 1,
                                            cornerRadius: 8
                                        }
                                    },
                                    scales: {
                                        x: {
                                            grid: {
                                                display: false
                                            },
                                            ticks: {
                                                color: '#6b7280',
                                                font: {
                                                    size: 9
                                                },
                                                maxTicksLimit: 10
                                            },
                                            title: {
                                                display: true,
                                                text: 'Date',
                                                color: '#6b7280',
                                                font: {
                                                    size: 11,
                                                    weight: '600'
                                                }
                                            }
                                        },
                                        y: {
                                            beginAtZero: true,
                                            grid: {
                                                color: 'rgba(0,0,0,0.04)'
                                            },
                                            ticks: {
                                                color: '#6b7280',
                                                font: {
                                                    size: 10
                                                }
                                            },
                                            title: {
                                                display: true,
                                                text: 'Messages',
                                                color: '#6b7280',
                                                font: {
                                                    size: 11,
                                                    weight: '600'
                                                }
                                            }
                                        }
                                    }
                                }
                            });
                        }
                    })();

                    // Growth chart
                    (function() {
                        const gLabels = <?= json_encode($ug_labels) ?>;
                        const gData = <?= json_encode($ug_daily) ?>;
                        const gCtx = document.getElementById('predGrowthChart');
                        if (gCtx && gLabels.length > 0) {
                            new Chart(gCtx, {
                                type: 'line',
                                data: {
                                    labels: gLabels,
                                    datasets: [{
                                        label: 'Sessions',
                                        data: gData,
                                        borderColor: '#10b981',
                                        backgroundColor: function(context) {
                                            const chart = context.chart;
                                            const {
                                                ctx,
                                                chartArea
                                            } = chart;
                                            if (!chartArea) return 'rgba(16,185,129,0.1)';
                                            const g = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
                                            g.addColorStop(0, 'rgba(16,185,129,0)');
                                            g.addColorStop(1, 'rgba(16,185,129,0.2)');
                                            return g;
                                        },
                                        fill: true,
                                        tension: 0.4,
                                        borderWidth: 2.5,
                                        pointRadius: 3,
                                        pointBackgroundColor: '#10b981',
                                        pointHoverRadius: 6,
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    plugins: {
                                        legend: {
                                            display: false
                                        }
                                    },
                                    scales: {
                                        x: {
                                            grid: {
                                                display: false
                                            },
                                            ticks: {
                                                color: '#6b7280',
                                                font: {
                                                    size: 9
                                                }
                                            },
                                            title: {
                                                display: true,
                                                text: 'Date',
                                                color: '#6b7280',
                                                font: {
                                                    size: 11,
                                                    weight: '600'
                                                }
                                            }
                                        },
                                        y: {
                                            beginAtZero: true,
                                            grid: {
                                                color: 'rgba(0,0,0,0.04)'
                                            },
                                            ticks: {
                                                color: '#6b7280'
                                            },
                                            title: {
                                                display: true,
                                                text: 'Sessions',
                                                color: '#6b7280',
                                                font: {
                                                    size: 11,
                                                    weight: '600'
                                                }
                                            }
                                        }
                                    }
                                }
                            });
                        }
                    })();
                </script>

                <script>
                    // Notification update function
                    function filterActivity(term) {
                        const items = document.querySelectorAll('.activity-item');
                        const lower = term.toLowerCase();
                        items.forEach(item => {
                            const searchText = item.getAttribute('data-search') || '';
                            item.style.display = searchText.includes(lower) ? '' : 'none';
                        });
                    }

                    function updateActivityCount() {
                        const feed = document.getElementById('activityFeed');
                        const countEl = document.getElementById('activityEventCount');
                        if (!feed || !countEl) return;
                        const visible = feed.querySelectorAll('.activity-item').length;
                        countEl.textContent = visible + ' event' + (visible !== 1 ? 's' : '');
                    }

                    function deleteActivityItem(btn) {
                        const item = btn.closest('.activity-item');
                        const type = item.dataset.type || '';
                        const created = item.dataset.created || '';
                        const fd = new FormData();
                        fd.append('action', 'delete');
                        fd.append('type', type);
                        fd.append('created_at', created);
                        item.style.transition = 'opacity 0.3s ease, max-height 0.3s ease';
                        item.style.opacity = '0.4';
                        fetch('delete_activity.php', {
                                method: 'POST',
                                body: fd
                            })
                            .then(r => r.json())
                            .then(d => {
                                if (d.ok || d.deleted >= 0) {
                                    item.style.maxHeight = item.offsetHeight + 'px';
                                    item.style.overflow = 'hidden';
                                    requestAnimationFrame(() => {
                                        item.style.maxHeight = '0';
                                        item.style.opacity = '0';
                                        item.style.marginBottom = '0';
                                        item.style.paddingTop = '0';
                                        item.style.paddingBottom = '0';
                                    });
                                    setTimeout(() => {
                                        item.remove();
                                        updateActivityCount();
                                    }, 320);
                                } else {
                                    item.style.opacity = '1';
                                    alert('Delete failed: ' + (d.error || 'Unknown error'));
                                }
                            }).catch(() => {
                                item.style.opacity = '1';
                            });
                    }

                    // Confirm-modal-based delete for activity items
                    var _pendingDeleteBtn = null;

                    function confirmDeleteActivity(btn) {
                        _pendingDeleteBtn = btn;
                        showConfirmModal({
                            title: 'Delete Activity Entry',
                            message: 'This will permanently remove this activity entry. Continue?',
                            confirmText: 'DELETE',
                            formId: null
                        });
                        // Override the confirm button to call our AJAX delete
                        const submitBtn = document.getElementById('confirmModalSubmit');
                        const newSubmit = submitBtn.cloneNode(true);
                        submitBtn.parentNode.replaceChild(newSubmit, submitBtn);
                        newSubmit.addEventListener('click', function() {
                            if (_pendingDeleteBtn) {
                                deleteActivityItem(_pendingDeleteBtn);
                                _pendingDeleteBtn = null;
                            }
                            hideConfirmModal();
                        });
                        // Re-enable when typed
                        document.getElementById('confirmModalInput').addEventListener('input', function() {
                            newSubmit.disabled = this.value.toUpperCase() !== 'DELETE';
                        });
                    }

                    // ── Feedback & Insights Tab Switching ──
                    function switchFbTab(tab) {
                        document.querySelectorAll('.fb-tab').forEach(t => t.classList.remove('active'));
                        document.querySelectorAll('.fb-panel').forEach(p => p.classList.remove('active'));
                        document.getElementById('fb-' + tab).classList.add('active');
                        event.target.closest('.fb-tab').classList.add('active');
                    }

                    // ── Feedback Over Time Period Toggle ──
                    var fbTrendChart = null;
                    const fbTrendData = {
                        daily: {
                            labels: <?= json_encode(
                                        array_values($fb_dates ?? []),
                                    ) ?> || [],
                            likes: <?= json_encode(
                                        array_values($fb_likes ?? []),
                                    ) ?> || [],
                            dislikes: <?= json_encode(
                                            array_values($fb_dislikes ?? []),
                                        ) ?> || []
                        },
                        weekly: {
                            labels: <?= json_encode(
                                        array_keys($fb_weekly_likes),
                                    ) ?> || [],
                            likes: <?= json_encode(
                                        array_values($fb_weekly_likes),
                                    ) ?> || [],
                            dislikes: <?= json_encode(
                                            array_values($fb_weekly_dislikes),
                                        ) ?> || []
                        },
                        monthly: {
                            labels: <?= json_encode(
                                        array_keys($fb_monthly_likes),
                                    ) ?> || [],
                            likes: <?= json_encode(
                                        array_values($fb_monthly_likes),
                                    ) ?> || [],
                            dislikes: <?= json_encode(
                                            array_values($fb_monthly_dislikes),
                                        ) ?> || []
                        }
                    };

                    function switchFbTrendPeriod(period) {
                        // Toggle active button
                        document.querySelectorAll('#fbTrendsPeriodToggle button').forEach(b => b.classList.remove('active'));
                        event.target.classList.add('active');

                        const d = fbTrendData[period];
                        if (fbTrendChart) fbTrendChart.destroy();

                        const ctx = document.getElementById('feedbackOverTimeChart');
                        if (!ctx) return;

                        fbTrendChart = new Chart(ctx.getContext('2d'), {
                            type: 'line',
                            data: {
                                labels: d.labels,
                                datasets: [{
                                        label: 'Likes',
                                        data: d.likes,
                                        borderColor: '#10b981',
                                        backgroundColor: 'rgba(16,185,129,0.1)',
                                        fill: true,
                                        tension: 0.4,
                                        borderWidth: 2.5,
                                        pointRadius: 3,
                                        pointBackgroundColor: '#10b981',
                                        pointHoverRadius: 6
                                    },
                                    {
                                        label: 'Dislikes',
                                        data: d.dislikes,
                                        borderColor: '#ef4444',
                                        backgroundColor: 'rgba(239,68,68,0.1)',
                                        fill: true,
                                        tension: 0.4,
                                        borderWidth: 2.5,
                                        pointRadius: 3,
                                        pointBackgroundColor: '#ef4444',
                                        pointHoverRadius: 6
                                    }
                                ]
                            },
                            options: {
                                ...defaultOptions,
                                plugins: {
                                    ...defaultOptions.plugins,
                                    legend: {
                                        display: true,
                                        position: 'top',
                                        labels: {
                                            color: '#6b7280',
                                            font: {
                                                size: 11
                                            },
                                            usePointStyle: true,
                                            pointStyle: 'circle',
                                            padding: 12
                                        }
                                    }
                                },
                                scales: {
                                    x: {
                                        ...defaultOptions.scales.x
                                    },
                                    y: {
                                        ...defaultOptions.scales.y,
                                        beginAtZero: true
                                    }
                                }
                            }
                        });
                    }

                    function updateNotificationCount() {
                        fetch('fetch_queries.php')
                            .then(response => response.json())
                            .then(data => {
                                const notYetCount = document.getElementById('not-yet-count');
                                if (notYetCount) {
                                    if (data.not_yet_count > 0) {
                                        notYetCount.textContent = data.not_yet_count;
                                        notYetCount.style.display = 'inline';
                                    } else {
                                        notYetCount.style.display = 'none';
                                    }
                                }
                            })
                            .catch(error => console.error('Error fetching notification count:', error));
                    }

                    updateNotificationCount();
                    setInterval(updateNotificationCount, 60000);

                    // ── Real-time Active Users (polls every 10s) ──
                    function updateActiveUsers() {
                        fetch('get_active_users.php')
                            .then(r => r.json())
                            .then(d => {
                                const el = document.getElementById('activeUsersCount');
                                if (el && typeof d.active !== 'undefined') {
                                    // Animate number change
                                    const prev = parseInt(el.textContent) || 0;
                                    const next = d.active;
                                    if (prev !== next) {
                                        el.style.transition = 'opacity 0.3s';
                                        el.style.opacity = '0';
                                        setTimeout(() => {
                                            el.textContent = next;
                                            el.style.opacity = '1';
                                        }, 300);
                                    }
                                }
                            })
                            .catch(() => {}); // silently ignore errors
                    }
                    updateActiveUsers();
                    setInterval(updateActiveUsers, 10000);
                </script>
                <script>
                    // ── Sparkline Renderer ──
                    function renderSparkline(canvasId, dataArr, color) {
                        const el = document.getElementById(canvasId);
                        if (!el || !dataArr || dataArr.length === 0) return;
                        new Chart(el.getContext('2d'), {
                            type: 'line',
                            data: {
                                labels: dataArr.map((_, i) => i),
                                datasets: [{
                                    data: dataArr.map(Number),
                                    borderColor: color,
                                    backgroundColor: color + '20',
                                    fill: true,
                                    tension: 0.4,
                                    borderWidth: 2,
                                    pointRadius: 0,
                                    pointHoverRadius: 3
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: {
                                        display: false
                                    },
                                    tooltip: {
                                        enabled: true,
                                        callbacks: {
                                            title: () => ''
                                        }
                                    }
                                },
                                scales: {
                                    x: {
                                        display: false
                                    },
                                    y: {
                                        display: false
                                    }
                                },
                                animation: {
                                    duration: 1000
                                }
                            }
                        });
                    }


                    // ── CSV Export (with proper escaping) ──
                    function csvEscape(val) {
                        val = String(val);
                        if (val.includes(',') || val.includes('"') || val.includes('\n')) {
                            return '"' + val.replace(/"/g, '""') + '"';
                        }
                        return val;
                    }

                    function exportDashCSV() {
                        const rows = [
                            ['Metric', 'Value'],
                            ['Successful Response Rate (24h)', '<?= $response_rate ?>%'],
                            ['Total Queries (24h)', '<?= $total_queries_24hr ?>'],
                            ['Successful Replies (24h)', '<?= $successful_replies_24hr ?>'],
                            ['User Messages (24h)', '<?= $user_messages_24hr ?>'],
                            ['Errors (24h)', '<?= $errors_24hr ?>'],
                            ['Fallbacks (24h)', '<?= $missed_24hr ?>'],
                            ['Web Sessions (24h)', '<?= $web_sessions_24hr ?>'],
                            ['Active Users (5min)', '<?= $active_users_5min ?>'],
                            ['Users (24h)', '<?= $users_24hr ?>'],
                            ['Feedback Today', '<?= $feedback_today ?>'],
                            ['Pending Queries', '<?= $pushed_awaiting ?>'],
                            ['Avg Session Duration', '<?= $avg_dur_display ?>'],
                            ['Excluded Sessions (>1h)', '<?= $excluded_sessions_count ?>'],
                            ['Peak Hour', '<?= $peak_hour_label ?>'],
                            ['Positive Feedback %', '<?= $thumbs_up_ratio ?>%'],
                            ['Inquiries Daily', '<?= $inq_daily ?>'],
                            ['Inquiries Weekly', '<?= $inq_weekly ?>'],
                            ['Inquiries Monthly', '<?= $inq_monthly ?>']
                        ];
                        const csv = rows.map(r => r.map(csvEscape).join(',')).join('\n');
                        const blob = new Blob([csv], {
                            type: 'text/csv'
                        });
                        const a = document.createElement('a');
                        a.href = URL.createObjectURL(blob);
                        a.download = 'dashboard_export_' + new Date().toISOString().slice(0, 10) + '.csv';
                        a.click();
                        URL.revokeObjectURL(a.href);
                    }

                    // ── Activity Search Filter ──
                    const dcbSearch = document.getElementById('dcbSearch');
                    if (dcbSearch) {
                        dcbSearch.addEventListener('input', function() {
                            const q = this.value.toLowerCase();
                            document.querySelectorAll('.activity-item').forEach(item => {
                                const text = item.getAttribute('data-search') || '';
                                item.style.display = text.includes(q) ? '' : 'none';
                            });
                        });
                    }
                </script>
                <script src="js/main.min.js"></script>
                <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
                    integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
                    crossorigin="anonymous"></script>
                <script src="js/custom.js"></script>
                <?php include "includes/global_toasts.php"; ?>
                <?php include "includes/confirm_modal.php"; ?>

                <style>
                    /* Deep-link stat cards */
                    .stat-card-link {
                        text-decoration: none;
                        color: inherit;
                        display: block;
                    }

                    .stat-card-link:hover .stat-card {
                        border-color: rgba(0, 33, 71, 0.18);
                        box-shadow: 0 6px 24px rgba(0, 0, 0, 0.1);
                    }
                </style>

</body>

</html>