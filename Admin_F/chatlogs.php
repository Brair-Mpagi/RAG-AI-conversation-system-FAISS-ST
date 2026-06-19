<?php
// Production: log errors, never display them
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Start session to check authentication
session_start();

// Check if the user is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: ./admin-login.php");
    exit();
}

require_once 'db.php';

if (!$conn->ping()) {
    die("Database connection is closed.");
}

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch Admin Details
$admin_query = "SELECT admin_id, username, email FROM admins WHERE admin_id = ?";
$admin_stmt = $conn->prepare($admin_query);

if ($admin_stmt) {
    $admin_stmt->bind_param("i", $_SESSION['admin_id']);
    $admin_stmt->execute();
    $admin_result = $admin_stmt->get_result();
    $admin = $admin_result->fetch_assoc();
    $admin_stmt->close();

    if (!$admin) {
        session_unset();
        session_destroy();
        header("Location: ./admin-login.php?err=account_missing");
        exit();
    }
} else {
    echo "Error preparing admin query: " . $conn->error;
}


// AJAX: hourly data for a given day range
if (isset($_GET['ajax_hourly'])) {
    header('Content-Type: application/json');
    $days = max(0, min(3650, (int)($_GET['days'] ?? 0)));
    $view = $_GET['view'] ?? 'hour';
    $cond = $days > 0 ? "WHERE created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)" : '';

    if ($view === 'week') {
        $q = "SELECT DAYOFWEEK(created_at) as idx, COUNT(*) as cnt FROM chat_messages $cond GROUP BY idx ORDER BY idx";
        $data = array_fill(1, 7, 0);
        $res = $conn->query($q);
        if ($res) {
            while ($r = $res->fetch_assoc()) $data[(int)$r['idx']] = (int)$r['cnt'];
        }
        echo json_encode(['data' => array_values($data), 'labels' => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']]);
    } else if ($view === 'month') {
        $q = "SELECT MONTH(created_at) as idx, COUNT(*) as cnt FROM chat_messages $cond GROUP BY idx ORDER BY idx";
        $data = array_fill(1, 12, 0);
        $res = $conn->query($q);
        if ($res) {
            while ($r = $res->fetch_assoc()) $data[(int)$r['idx']] = (int)$r['cnt'];
        }
        echo json_encode(['data' => array_values($data), 'labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']]);
    } else {
        $q = "SELECT HOUR(created_at) as idx, COUNT(*) as cnt FROM chat_messages $cond GROUP BY idx ORDER BY idx";
        $data = array_fill(0, 24, 0);
        $res = $conn->query($q);
        if ($res) {
            while ($r = $res->fetch_assoc()) $data[(int)$r['idx']] = (int)$r['cnt'];
        }
        echo json_encode(['hourly' => array_values($data)]);
    }
    exit;
}

// Handle POST requests (empty table, delete record)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['empty_table'])) {
        $conn->query("SET FOREIGN_KEY_CHECKS=0");
        $conn->query("TRUNCATE TABLE message_context");
        $conn->query("TRUNCATE TABLE message_reactions");
        $conn->query("TRUNCATE TABLE chat_messages");
        $conn->query("SET FOREIGN_KEY_CHECKS=1");
        echo json_encode(["status" => "success", "message" => "Table emptied"]);
        exit;
    }
    if (isset($_POST['delete_record'])) {
        $id = $conn->real_escape_string($_POST['record_id']);
        $conn->query("DELETE FROM chat_messages WHERE message_id = '$id'");
        echo json_encode(["status" => "success", "message" => "Record deleted"]);
        exit;
    }
}

// Handle filtering via GET (for AJAX and Dashboard links)
$where = "";
$conditions = [];
$range = 'few';
$from = '';
$to = '';

if (isset($_GET['filter'])) {
    $filterParam = $_GET['filter'];
    $filterAttr = json_decode($filterParam, true);

    // Check if it's a dashboard URL param like filter=response_type:fallback
    if (!$filterAttr && strpos($filterParam, ':') !== false) {
        list($k, $v) = explode(':', $filterParam, 2);
        if (trim($k) === 'response_type') {
            $v_clean = $conn->real_escape_string(trim($v));
            $conditions[] = "response_type = '$v_clean'";
        }
    }
    // Otherwise it's the AJAX JSON filter
    else if (is_array($filterAttr)) {
        $question = isset($filterAttr['question']) ? $conn->real_escape_string($filterAttr['question']) : '';
        $response = isset($filterAttr['response']) ? $conn->real_escape_string($filterAttr['response']) : '';
        $timestamp = isset($filterAttr['timestamp']) ? $conn->real_escape_string($filterAttr['timestamp']) : '';
        $session_id = isset($filterAttr['session_id']) ? $conn->real_escape_string($filterAttr['session_id']) : '';
        $intent = isset($filterAttr['intent']) ? $conn->real_escape_string($filterAttr['intent']) : '';
        $range = isset($filterAttr['range']) ? $conn->real_escape_string($filterAttr['range']) : 'few';
        $from = isset($filterAttr['from']) ? $conn->real_escape_string($filterAttr['from']) : '';
        $to = isset($filterAttr['to']) ? $conn->real_escape_string($filterAttr['to']) : '';

        if ($question) $conditions[] = "user_message LIKE '%$question%'";
        if ($response) $conditions[] = "bot_response LIKE '%$response%'";
        if ($timestamp) $conditions[] = "created_at LIKE '%$timestamp%'";
        if ($session_id) $conditions[] = "session_id LIKE '%$session_id%'";
        if ($intent) $conditions[] = "intent_classification LIKE '%$intent%'";

        if ($range === 'custom' && $from && $to) {
            $conditions[] = "created_at BETWEEN '$from 00:00:00' AND '$to 23:59:59'";
        }
    }
}

// Handle range parameter from Dashboard
if (isset($_GET['range']) && $_GET['range'] === '24h') {
    $conditions[] = "created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
}

if (!empty($conditions)) {
    $where = "WHERE " . implode(" AND ", $conditions);
}

// Pagination setup
$limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 100;
if ($range === 'all') {
    $limit = 1000000;
}
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$total_filtered_result = $conn->query("SELECT COUNT(*) as cnt FROM chat_messages $where");
$total_filtered = $total_filtered_result ? (int) $total_filtered_result->fetch_assoc()['cnt'] : 0;
$total_pages = ceil($total_filtered / $limit);

$result = $conn->query("SELECT message_id, session_id, user_message, bot_response, intent_classification, response_type, confidence_score, response_time_ms, context_retrieved, model_used, created_at FROM chat_messages $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
$chatlogs = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $chatlogs[] = $row;
    }
} else {
    error_log("Chatlogs query failed: " . $conn->error);
}

if (isset($_GET['ajax'])) {
    echo json_encode($chatlogs);
    exit;
}

// ===== Stats =====
$total_result = $conn->query("SELECT COUNT(*) as total FROM chat_messages");
$total_messages = $total_result ? $total_result->fetch_assoc()['total'] : 0;

$today_result = $conn->query("SELECT COUNT(*) as today FROM chat_messages WHERE DATE(created_at) = CURDATE()");
$today_messages = $today_result ? $today_result->fetch_assoc()['today'] : 0;

$avg_conf_result = $conn->query("SELECT AVG(confidence_score) as avg_conf FROM chat_messages WHERE confidence_score > 0");
$avg_confidence = $avg_conf_result ? round(($avg_conf_result->fetch_assoc()['avg_conf'] ?? 0) * 100, 1) : 0;

$avg_time_result = $conn->query("SELECT AVG(response_time_ms) as avg_time FROM chat_messages WHERE response_time_ms > 0");
$avg_response_time = $avg_time_result ? round($avg_time_result->fetch_assoc()['avg_time'] ?? 0) : 0;

$sessions_result = $conn->query("SELECT COUNT(DISTINCT session_id) as sessions FROM chat_messages");
$unique_sessions = $sessions_result ? $sessions_result->fetch_assoc()['sessions'] : 0;

$rag_result = $conn->query("SELECT COUNT(*) as rag FROM chat_messages WHERE context_retrieved = 1");
$rag_count = $rag_result ? $rag_result->fetch_assoc()['rag'] : 0;

// Intent distribution for chart
$intent_result = $conn->query("SELECT intent_classification, COUNT(*) as cnt FROM chat_messages WHERE intent_classification IS NOT NULL GROUP BY intent_classification ORDER BY cnt DESC LIMIT 8");
$intent_labels = [];
$intent_counts = [];
if ($intent_result) {
    while ($row = $intent_result->fetch_assoc()) {
        $intent_labels[] = $row['intent_classification'] ?: 'unknown';
        $intent_counts[] = (int) $row['cnt'];
    }
}

// Daily message trend (configurable range)
$trend_days = max(1, min(365, (int)($_GET['days'] ?? 14)));
$trend_start = isset($_GET['start']) ? date('Y-m-d', strtotime($_GET['start'])) : date('Y-m-d', strtotime("-{$trend_days} days"));
$trend_end   = isset($_GET['end'])   ? date('Y-m-d', strtotime($_GET['end'])) : date('Y-m-d');

$daily_result = $conn->query("SELECT DATE(created_at) as day, COUNT(*) as cnt FROM chat_messages WHERE DATE(created_at) BETWEEN '$trend_start' AND '$trend_end' GROUP BY DATE(created_at) ORDER BY day");
$daily_labels = [];
$daily_counts = [];
if ($daily_result) {
    while ($row = $daily_result->fetch_assoc()) {
        $daily_labels[] = date('M j', strtotime($row['day']));
        $daily_counts[] = (int) $row['cnt'];
    }
}

// ===== HOURLY ACTIVITY =====
$cl_hourly_query = "SELECT HOUR(created_at) as hr, COUNT(*) as cnt FROM chat_messages GROUP BY hr ORDER BY hr";
$cl_hourly_result = $conn->query($cl_hourly_query);
$cl_hourly_data = array_fill(0, 24, 0);
if ($cl_hourly_result) {
    while ($row = $cl_hourly_result->fetch_assoc()) {
        $cl_hourly_data[(int) $row['hr']] = (int) $row['cnt'];
    }
}


?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Chat Logs — Admin Panel</title>
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

    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"
        integrity="sha512-Kc323vGBEqzTmouAECnVceyQqyqdsSiqLQISBL29aUW4U/M7pSPA/gEUZQqv1cwx4OnYxTxve5UMg5GT6L4JJg=="
        crossorigin="anonymous" referrerpolicy="no-referrer" />

    <link href="css/style.css?v=1775081173" rel="stylesheet" />
    <link href="css/style-mob.css" rel="stylesheet" />
    <link href="css/admin.css" rel="stylesheet" />

    <link href="css/admin-profile.css" rel="stylesheet" />

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
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
            font-style: normal !important;
            font-variant: normal !important;
            text-rendering: auto;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            display: inline-block !important;
            line-height: 1;
        }

        /* Stat cards */
        .cl-stats-row {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .cl-stat-card {
            flex: 1;
            min-width: 140px;
            background: linear-gradient(135deg, #002147 0%, #05356b 100%);
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
        }

        .cl-stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #cfd1d5ff;
        }

        .cl-stat-label {
            font-size: 0.78rem;
            color: #c8cdd5ff;
            margin-top: 4px;
        }

        /* Chart cards */
        .cl-chart-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 0;
        }

        @media (max-width: 900px) {
            .cl-chart-row {
                grid-template-columns: 1fr;
            }
        }

        .cl-chart-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 18px 20px;
            box-shadow: 0 1px 4px rgba(99, 102, 241, 0.07);
        }

        .cl-chart-card h4 {
            color: #1e293b;
            font-size: 0.9rem;
            margin-bottom: 12px;
            font-weight: 700;
        }

        /* Period toggle buttons */
        .chart-time-toggle {
            display: flex;
            gap: 2px;
            background: #f1f5f9;
            border-radius: 8px;
            padding: 3px;
        }

        .chart-time-toggle button {
            padding: 3px 10px;
            border: none;
            background: transparent;
            border-radius: 6px;
            font-size: 0.72rem;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            transition: all 0.15s;
        }

        .chart-time-toggle button.active {
            background: #fff;
            color: #6366f1;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .chart-time-toggle button:hover:not(.active) {
            color: #475569;
        }

        .cl-period-toggle {
            display: flex;
            gap: 2px;
            background: #f1f5f9;
            border-radius: 8px;
            padding: 3px;
        }

        .cl-period-toggle button {
            padding: 3px 10px;
            border: none;
            background: transparent;
            border-radius: 6px;
            font-size: 0.72rem;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            transition: all 0.15s;
        }

        .cl-period-toggle button.active {
            background: #fff;
            color: #6366f1;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .cl-period-toggle button:hover:not(.active) {
            color: #475569;
        }

        /* Filters */
        .cl-filter-bar {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 16px;
            align-items: center;
        }

        .cl-filter-bar input,
        .cl-filter-bar select {
            padding: 7px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: white;
            color: #1e293b;
            font-size: 0.82rem;
            min-width: 120px;
        }

        .cl-filter-bar input::placeholder {
            color: #64748b;
        }

        /* Table */
        .cl-table-wrap {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 12px;
            overflow: hidden;
        }

        .cl-table {
            width: 100%;
            border-collapse: collapse;
        }

        .cl-table th {
            background: #0f172a;
            color: #94a3b8;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 12px 14px;
            border-bottom: 1px solid #334155;
            white-space: nowrap;
        }

        .cl-table td {
            padding: 10px 14px;
            color: #cbd5e1;
            font-size: 0.82rem;
            border-bottom: 1px solid rgba(51, 65, 85, 0.5);
            vertical-align: top;
        }

        .cl-table tr:hover td {
            background: rgba(59, 130, 246, 0.05);
        }

        .cl-table .msg-text {
            max-width: 300px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: pointer;
        }

        .cl-table .msg-text:hover {
            white-space: normal;
            word-break: break-word;
        }

        /* Badges */
        .cl-badge {
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.68rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .cl-badge-rag {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
        }

        .cl-badge-greeting {
            background: rgba(59, 130, 246, 0.15);
            color: #60a5fa;
        }

        .cl-badge-refusal {
            background: rgba(239, 68, 68, 0.15);
            color: #f87171;
        }

        .cl-badge-default {
            background: rgba(100, 116, 139, 0.15);
            color: #94a3b8;
        }

        /* Confidence dot */
        .conf-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 4px;
            vertical-align: middle;
        }

        .conf-high {
            background: #10b981;
        }

        .conf-med {
            background: #f59e0b;
        }

        .conf-low {
            background: #ef4444;
        }

        /* Actions bar */
        .cl-actions {
            display: flex;
            gap: 8px;
            margin-bottom: 16px;

        }

        .cl-actions button {
            padding: 6px 12px;
            border: 1px solid #334155;
            border-radius: 6px;
            background: linear-gradient(135deg, #002147 0%, #05356b 100%);
            color: #e2e8f0;
            font-size: 0.82rem;
            min-width: 120px;
        }
    </style>
</head>

<body>
    <!--== MAIN CONTAINER ==-->
    <!--== MAIN CONTAINER ==-->
        <!--== MAIN CONTAINER ==-->
    <?php include 'includes/topbar.php'; ?>

    </div>
    </div>

    <div class="container-fluid sb2">
        <div class="row">
            <?php include 'includes/sidebar.php'; ?>

            <div class="sb2-2">


                <div class="sb2-2-1">
                    <div class="db-2">

                        <!-- ===== PAGE HEADER ===== -->
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
                            <div>
                                <h2 style="color:#104a95;font-size:1.4rem;margin:0;font-weight:700;">
                                    <i class="fa-solid fa-comments" style="color: #18569d; margin-right:8px;"></i>Chat
                                    Logs
                                </h2>

                            </div>
                            <div style="color:#64748b;font-size:0.78rem;">
                                <i class="fa-solid fa-clock"></i> Last updated: <?= date('M j, g:ia') ?>
                            </div>
                        </div>

                        <!-- ===== STATS ROW ===== -->
                        <div class="cl-stats-row">
                            <div class="cl-stat-card">
                                <div class="cl-stat-value"><?= number_format($total_messages) ?></div>
                                <div class="cl-stat-label">Total Messages</div>
                            </div>
                            <div class="cl-stat-card">
                                <div class="cl-stat-value" style="color:#3b82f6;"><?= number_format($today_messages) ?>
                                </div>
                                <div class="cl-stat-label">Today</div>
                            </div>
                            <div class="cl-stat-card">
                                <div class="cl-stat-value"><?= number_format($unique_sessions) ?></div>
                                <div class="cl-stat-label">Unique Sessions</div>
                            </div>
                            <div class="cl-stat-card">
                                <div class="cl-stat-value" style="color:#10b981;"><?= $avg_confidence ?>%</div>
                                <div class="cl-stat-label">Avg Confidence</div>
                            </div>
                            <div class="cl-stat-card">
                                <div class="cl-stat-value"><?= number_format($avg_response_time) ?><span
                                        style="font-size:0.7rem;">ms</span></div>
                                <div class="cl-stat-label">Avg Response Time</div>
                            </div>
                            <div class="cl-stat-card">
                                <div class="cl-stat-value" style="color:#a78bfa;"><?= number_format($rag_count) ?></div>
                                <div class="cl-stat-label">RAG Queries</div>
                            </div>
                        </div>

                        <!-- ===== ANALYTICS HEADER ===== -->


                        <!-- ===== ROW 1: Trend (wide) + Intent ===== -->
                        <div class="cl-chart-row" style="grid-template-columns:1.8fr 1.2fr;">
                            <!-- Message Trend -->
                            <div class="cl-chart-card">
                                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                                    <h4 style="margin:0;"><i class="fa-solid fa-chart-line" style="color:#6366f1;margin-right:6px;"></i>Message Trend</h4>
                                    <div class="cl-period-toggle" id="trendPeriodToggle">
                                        <button class="active" data-period="daily" onclick="setCLPeriod('trend','daily',this)">Daily</button>
                                        <button data-period="weekly" onclick="setCLPeriod('trend','weekly',this)">Weekly</button>
                                        <button data-period="monthly" onclick="setCLPeriod('trend','monthly',this)">Monthly</button>
                                    </div>
                                </div>
                                <div style="height:260px;position:relative;">
                                    <canvas id="dailyTrendChart"></canvas>
                                    <div id="trendEmpty" style="display:none;position:absolute;inset:0;align-items:center;justify-content:center;color:#94a3b8;font-size:0.85rem;"><i class="fa-solid fa-chart-line" style="margin-right:6px;"></i>No data for this period</div>
                                </div>
                            </div>
                            <!-- Intent Distribution -->
                            <div class="cl-chart-card">
                                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                                    <h4 style="margin:0;"><i class="fa-solid fa-tags" style="color:#8b5cf6;margin-right:6px;"></i>Intent Split</h4>
                                </div>
                                <div style="height:260px;position:relative;">
                                    <canvas id="intentChart"></canvas>
                                    <div id="intentEmpty" style="display:none;position:absolute;inset:0;align-items:center;justify-content:center;color:#94a3b8;font-size:0.85rem;"><i class="fa-solid fa-tags" style="margin-right:6px;"></i>No intent data</div>
                                </div>
                            </div>
                        </div>

                        
                        <!-- ===== ROW 2: Hourly + Response Type ===== -->
                        <div class="cl-chart-row" style="margin:16px 0; grid-template-columns: 1fr;">
                            <!-- Hourly Activity -->
                            <div class="cl-chart-card">
                                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
                                    <h4 style="margin:0;">
                                        <i class="fa-solid fa-clock" style="color:#f59e0b;margin-right:6px;"></i>
                                        Hourly Activity
                                    </h4>

                                    <!-- View Toggle -->
                                    <div class="chart-time-toggle">
                                        <button class="active" onclick="switchCLView('hour', this)" id="clViewHour">By Hour</button>
                                        <button onclick="switchCLView('week', this)" id="clViewWeek">By Week</button>
                                        <button onclick="switchCLView('month', this)" id="clViewMonth">By Month</button>
                                    </div>

                                    <!-- Period Filter -->
                                    <div class="cl-period-toggle" id="hourlyPeriodToggle">
                                        <button class="active" data-period="alltime" onclick="setCLPeriod('hourly','alltime',this)">All Time</button>
                                        <button data-period="7d" onclick="setCLPeriod('hourly','7d',this)">7 Days</button>
                                        <button data-period="30d" onclick="setCLPeriod('hourly','30d',this)">30 Days</button>
                                    </div>

                                    <!-- Hour-specific controls -->
                                    <div id="clHourlyControls" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                                        <select id="clHourRangeSelect" onchange="applyCLHourFilter()"
                                            style="padding:4px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:0.78rem;">
                                            <option value="all">All Hours (0–23)</option>
                                            <option value="morning">Morning (6–11)</option>
                                            <option value="afternoon">Afternoon (12–17)</option>
                                            <option value="evening">Evening (18–21)</option>
                                            <option value="night">Night (22–5)</option>
                                        </select>

                                        <div style="display:flex;gap:2px;">
                                            <button id="clBtn24" onclick="setCLHourFmt(24)"
                                                style="padding:3px 9px;border:1px solid #3b82f6;border-radius:5px 0 0 5px;font-size:0.76rem;background:#3b82f6;color:#fff;cursor:pointer;">
                                                24hr
                                            </button>
                                            <button id="clBtn12" onclick="setCLHourFmt(12)"
                                                style="padding:3px 9px;border:1px solid #d1d5db;border-radius:0 5px 5px 0;font-size:0.76rem;background:#fff;color:#374151;cursor:pointer;">
                                                12hr
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div style="height:260px;">
                                    <canvas id="clHourlyChart"></canvas>
                                </div>
                            </div>
                        </div>

                        <!-- ===== FILTERS ===== -->
                        <div class="cl-filter-bar">
                            <input type="text" id="filter-session-id" placeholder="Session ID">
                            <input type="text" id="filter-question" placeholder="Search question...">
                            <input type="text" id="filter-response" placeholder="Search response...">
                            <select id="filter-intent">
                                <option value="">All Intents</option>
                                <?php
                                $intents_res = $conn->query("SELECT DISTINCT intent_classification FROM chat_messages WHERE intent_classification IS NOT NULL AND intent_classification != ''");
                                if ($intents_res) {
                                    while ($i_row = $intents_res->fetch_assoc()) {
                                        echo '<option value="' . htmlspecialchars($i_row['intent_classification']) . '">' . htmlspecialchars(ucfirst($i_row['intent_classification'])) . '</option>';
                                    }
                                }
                                ?>
                            </select>
                            <select id="filter-range">
                                <option value="few">Show Few</option>
                                <option value="custom">Custom Range</option>
                                <option value="all">All</option>
                            </select>
                            <input type="date" id="filter-from" style="display:none;" title="From Date">
                            <input type="date" id="filter-to" style="display:none;" title="To Date">
                            <button class="btn btn-outline-secondary btn-sm" onclick="clearFilters()">
                                <i class="fa-solid fa-times"></i> Clear
                            </button>
                        </div>

                        <!-- ===== ACTIONS ===== -->
                        <div class="cl-actions">
                            <button class="btn btn-outline-light btn-sm" onclick="exportTable('chat_messages')">
                                <i class="fa-solid fa-download"></i> Export
                            </button>
                            <!-- Hidden form for empty-table action (submitted by confirm modal) -->
                            <form id="emptyChatsForm" method="POST" style="display:none;">
                                <input type="hidden" name="empty_table" value="1">
                            </form>
                            <button class="btn btn-outline-danger btn-sm"
                                onclick="showConfirmModal({title:'Empty All Chat Logs', message:'This will permanently delete ALL chat messages, reactions, and context records. This cannot be undone.', confirmText:'DELETE', formId:'emptyChatsForm'})">
                                <i class="fa-solid fa-trash"></i> Empty Table
                            </button>
                            <span style="margin-left:auto;color:#64748b;font-size:0.78rem;align-self:center;">
                                Showing <?= count($chatlogs) ?> of <?= number_format($total_messages) ?> messages
                            </span>
                        </div>

                        <!-- ===== TABLE ===== -->
                        <div class="admin-table-container">
                            <table class="admin-table" id="chatlogs-table">
                                <thead>
                                    <tr>
                                        <th class="admin-th">ID</th>
                                        <th class="admin-th">Session</th>
                                        <th class="admin-th">Question</th>
                                        <th class="admin-th">Response</th>
                                        <th class="admin-th">Intent</th>
                                        <th class="admin-th">Type</th>
                                        <th class="admin-th">Confidence</th>
                                        <th class="admin-th">Time</th>
                                        <th class="admin-th">Context</th>
                                        <th class="admin-th">Timestamp</th>
                                        <th class="admin-th">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($chatlogs as $row):
                                        $conf = (float) ($row['confidence_score'] ?? 0);
                                        $confPct = round($conf * 100, 1);
                                        $confClass = $conf >= 0.7 ? 'conf-high' : ($conf >= 0.4 ? 'conf-med' : 'conf-low');

                                        $intent = $row['intent_classification'] ?? '';
                                        $badgeClass = 'cl-badge-default';
                                        if (strpos($intent, 'rag') !== false || strpos($intent, 'campus') !== false)
                                            $badgeClass = 'cl-badge-rag';
                                        elseif (strpos($intent, 'social') !== false || strpos($intent, 'greeting') !== false)
                                            $badgeClass = 'cl-badge-greeting';
                                        elseif (strpos($intent, 'refusal') !== false || strpos($intent, 'out_of_scope') !== false)
                                            $badgeClass = 'cl-badge-refusal';
                                    ?>
                                        <tr class="admin-tr" data-id="<?= $row['message_id'] ?>">
                                            <td class="admin-td"><?= $row['message_id'] ?></td>
                                            <td class="admin-td"><?= $row['session_id'] ?></td>
                                            <td class="admin-td msg-text"
                                                title="<?= htmlspecialchars($row['user_message'] ?? '') ?>">
                                                <?= htmlspecialchars($row['user_message'] ?? '') ?>
                                            </td>
                                            <td class="admin-td msg-text"
                                                title="<?= htmlspecialchars($row['bot_response'] ?? '') ?>">
                                                <?= htmlspecialchars($row['bot_response'] ?? '') ?>
                                            </td>
                                            <td class="admin-td"><span
                                                    class="cl-badge <?= $badgeClass ?>"><?= htmlspecialchars($intent ?: '—') ?></span>
                                            </td>
                                            <td class="admin-td">
                                                <?php
                                                $rtype = $row['response_type'] ?? '';
                                                $rtypeClass = 'cl-badge-default';
                                                if ($rtype === 'rag_based') $rtypeClass = 'cl-badge-rag';
                                                elseif ($rtype === 'fallback') $rtypeClass = 'cl-badge-greeting';
                                                elseif ($rtype === 'error' || $rtype === 'refusal') $rtypeClass = 'cl-badge-refusal';
                                                ?>
                                                <span class="cl-badge <?= $rtypeClass ?>"><?= htmlspecialchars(str_replace('_', ' ', $rtype ?: '—')) ?></span>
                                            </td>
                                            <td class="admin-td">
                                                <span class="conf-dot <?= $confClass ?>"></span><?= $confPct ?>%
                                            </td>
                                            <td class="admin-td">
                                                <?= $row['response_time_ms'] ? round((float) $row['response_time_ms']) . 'ms' : '—' ?>
                                            </td>
                                            <td class="admin-td">
                                                <?= !empty($row['context_retrieved']) ? '<i class="fa-solid fa-check" style="color:#10b981;"></i>' : '<i class="fa-solid fa-xmark" style="color:#d1d5db;"></i>' ?>
                                            </td>
                                            <td class="admin-td cl-ts-cell" style="white-space:nowrap;font-size:0.75rem;"
                                                data-raw="<?= htmlspecialchars($row['created_at']) ?>">
                                                <?= date('M j, g:ia', strtotime($row['created_at'])) ?>
                                            </td>
                                            <td class="admin-td">
                                                <button class="btn btn-outline-danger btn-sm"
                                                    style="font-size:0.7rem;padding:2px 8px;"
                                                    onclick="deleteRecord(<?= $row['message_id'] ?>)"><i
                                                        class="fa-solid fa-trash"></i></button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination controls -->
                        <?php if (isset($total_pages) && $total_pages > 1): ?>
                            <div style="display:flex; justify-content:space-between; align-items:center; padding:15px; border-top:1px solid #e2e8f0; background:#f8fafc; border-radius:0 0 8px 8px;">
                                <div style="font-size:0.85rem; color:#64748b;">
                                    Showing <?= $offset + 1 ?> to <?= min($offset + $limit, $total_filtered) ?> of <?= $total_filtered ?> entries
                                </div>
                                <div style="display:flex; gap:5px;">
                                    <?php
                                    $filter_query = '';
                                    if (isset($_GET['filter'])) $filter_query = '&filter=' . urlencode($_GET['filter']);
                                    ?>
                                    <a href="?page=<?= max(1, $page - 1) ?><?= $filter_query ?>" class="btn btn-sm" style="background:#fff; border:1px solid #cbd5e1; font-weight:500; color: <?= $page <= 1 ? '#cbd5e1; pointer-events:none;' : '#3b82f6;' ?>">Prev</a>
                                    <span style="padding: 4px 10px; font-size:0.85rem; color:#475569; font-weight:600;">Page <?= $page ?> of <?= $total_pages ?></span>
                                    <a href="?page=<?= min($total_pages, $page + 1) ?><?= $filter_query ?>" class="btn btn-sm" style="background:#fff; border:1px solid #cbd5e1; font-weight:500; color: <?= $page >= $total_pages ? '#cbd5e1; pointer-events:none;' : '#3b82f6;' ?>">Next</a>
                                </div>
                            </div>
                        <?php endif; ?>

                    </div>
                </div>
            </div>

            <!-- Scripts -->
            <script src="js/main.min.js"></script>
            <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
                integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
                crossorigin="anonymous"></script>
            <script src="js/custom.js"></script>

            <script>
                function updateNotificationCount() {
                    fetch('fetch_queries.php')
                        .then(response => response.json())
                        .then(data => {
                            const el = document.getElementById('not-yet-count');
                            if (el) {
                                if (data.not_yet_count > 0) {
                                    el.textContent = data.not_yet_count;
                                    el.style.display = 'inline';
                                } else {
                                    el.style.display = 'none';
                                }
                            }
                        })
                        .catch(err => console.error('Notification error:', err));
                }
                updateNotificationCount();
                setInterval(updateNotificationCount, 60000);

                // Filtering
                function applyFilters() {
                    const filter = {
                        session_id: document.getElementById('filter-session-id').value,
                        question: document.getElementById('filter-question').value,
                        response: document.getElementById('filter-response').value,
                        intent: document.getElementById('filter-intent').value,
                        range: document.getElementById('filter-range').value,
                        from: document.getElementById('filter-from').value,
                        to: document.getElementById('filter-to').value
                    };
                    fetchChatlogs(filter);
                }

                function clearFilters() {
                    document.getElementById('filter-session-id').value = '';
                    document.getElementById('filter-question').value = '';
                    document.getElementById('filter-response').value = '';
                    document.getElementById('filter-intent').value = '';
                    document.getElementById('filter-range').value = 'few';
                    document.getElementById('filter-from').value = '';
                    document.getElementById('filter-to').value = '';
                    document.getElementById('filter-from').style.display = 'none';
                    document.getElementById('filter-to').style.display = 'none';
                    fetchChatlogs({});
                }

                function fetchChatlogs(filter = {}) {
                    fetch(`chatlogs.php?ajax=1&filter=${encodeURIComponent(JSON.stringify(filter))}`)
                        .then(r => r.json())
                        .then(data => updateTable(data));
                }

                // Add automatic filtering listeners
                document.addEventListener('DOMContentLoaded', function() {
                    document.getElementById('filter-intent').addEventListener('change', applyFilters);
                    
                    const rangeSelect = document.getElementById('filter-range');
                    if (rangeSelect) {
                        rangeSelect.addEventListener('change', function() {
                            const isCustom = this.value === 'custom';
                            document.getElementById('filter-from').style.display = isCustom ? 'inline-block' : 'none';
                            document.getElementById('filter-to').style.display = isCustom ? 'inline-block' : 'none';
                            applyFilters();
                        });
                    }

                    document.getElementById('filter-from').addEventListener('change', applyFilters);
                    document.getElementById('filter-to').addEventListener('change', applyFilters);

                    // Text fields filtering with 300ms debounce
                    let debounceTimer;
                    ['filter-session-id', 'filter-question', 'filter-response'].forEach(id => {
                        const inputEl = document.getElementById(id);
                        if (inputEl) {
                            inputEl.addEventListener('input', () => {
                                clearTimeout(debounceTimer);
                                debounceTimer = setTimeout(applyFilters, 300);
                            });
                        }
                    });
                });

                function updateTable(chatlogs) {
                    const tbody = document.querySelector('#chatlogs-table tbody');
                    tbody.innerHTML = '';
                    chatlogs.forEach(row => {
                        const conf = parseFloat(row.confidence_score || 0);
                        const confPct = (conf * 100).toFixed(1);
                        const confClass = conf >= 0.7 ? 'conf-high' : (conf >= 0.4 ? 'conf-med' : 'conf-low');
                        const intent = row.intent_classification || '';
                        let badgeClass = 'cl-badge-default';
                        if (intent.includes('rag') || intent.includes('campus')) badgeClass = 'cl-badge-rag';
                        else if (intent.includes('social') || intent.includes('greeting')) badgeClass = 'cl-badge-greeting';
                        else if (intent.includes('refusal') || intent.includes('out_of_scope')) badgeClass = 'cl-badge-refusal';

                        const tr = document.createElement('tr');
                        tr.className = 'admin-tr'; // Added for consistency
                        tr.dataset.id = row.message_id;
                        tr.innerHTML = `
                            <td class="admin-td">${row.message_id}</td>
                            <td class="admin-td">${row.session_id}</td>
                            <td class="admin-td msg-text" title="${(row.user_message || '').replace(/"/g, '&quot;')}">${(row.user_message || '').replace(/</g, '&lt;')}</td>
                            <td class="admin-td msg-text" title="${(row.bot_response || '').replace(/"/g, '&quot;')}">${(row.bot_response || '').replace(/</g, '&lt;')}</td>
                            <td class="admin-td"><span class="cl-badge ${badgeClass}">${intent || '—'}</span></td>
                            <td class="admin-td"><span class="cl-badge cl-badge-default">${(row.response_type || '—').replace(/_/g, ' ')}</span></td>
                            <td class="admin-td"><span class="conf-dot ${confClass}"></span>${confPct}%</td>
                            <td class="admin-td">${row.response_time_ms ? Math.round(row.response_time_ms) + 'ms' : '—'}</td>
                            <td class="admin-td">${row.context_retrieved ? '<i class="fa-solid fa-check" style="color:#10b981;"></i>' : '<i class="fa-solid fa-xmark" style="color:#d1d5db;"></i>'}</td>
                            <td class="admin-td cl-ts-cell" style="white-space:nowrap;font-size:0.75rem;" data-raw="${row.created_at}">${row.created_at}</td>
                            <td class="admin-td"><button class="btn btn-outline-danger btn-sm" style="font-size:0.7rem;padding:2px 8px;" onclick="deleteRecord(${row.message_id})"><i class="fa-solid fa-trash"></i></button></td>
                        `;
                        tbody.appendChild(tr);
                    });
                }

                function deleteRecord(id) {
                    // Use typed-confirm modal for single-record delete
                    var overlay = document.getElementById('confirmModalOverlay');
                    document.getElementById('confirmModalTitle').textContent = 'Delete Message';
                    document.getElementById('confirmModalMessage').textContent = 'This will permanently delete message #' + id + '. This cannot be undone.';
                    var word = 'DELETE';
                    window._cmWord = word;
                    document.getElementById('confirmModalWord').textContent = word;
                    var input = document.getElementById('confirmModalInput');
                    input.value = '';
                    input.placeholder = 'Type DELETE here';
                    var btn = document.getElementById('confirmModalSubmit');
                    btn.disabled = true;
                    // Override submit action to do AJAX delete
                    window._cmFormId = null;
                    window._cmDeleteChatId = id;
                    overlay.style.display = 'flex';
                    setTimeout(function() {
                        input.focus();
                    }, 100);
                }

                // Override the modal submit for chat delete AJAX
                document.getElementById('confirmModalSubmit').addEventListener('click', function() {
                    if (window._cmDeleteChatId) {
                        var id = window._cmDeleteChatId;
                        window._cmDeleteChatId = null;
                        document.getElementById('confirmModalOverlay').style.display = 'none';
                        fetch('chatlogs.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: 'delete_record=1&record_id=' + id + '&table_name=chat_messages'
                        }).then(function() {
                            applyFilters();
                            showToast('success', 'Deleted', 'Message #' + id + ' removed.');
                        });
                    }
                }, {
                    once: false
                });

                function emptyTable() {
                    fetch('chatlogs.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: 'empty_table=1&table_name=chat_messages'
                    }).then(() => location.reload());
                }

                function exportTable() {
                    window.location.href = 'export.php?table=chat_messages';
                }

                // SSE for real-time updates
                try {
                    const source = new EventSource('chatlogs_sse.php');
                    source.onmessage = function(event) {
                        const newLog = JSON.parse(event.data);
                        const tbody = document.querySelector('#chatlogs-table tbody');
                        const tr = document.createElement('tr');
                        tr.dataset.id = newLog.message_id;
                        tr.innerHTML = `
                            <td>${newLog.message_id}</td>
                            <td>${newLog.session_id}</td>
                            <td class="msg-text">${(newLog.user_message || '').replace(/</g, '&lt;')}</td>
                            <td class="msg-text">${(newLog.bot_response || '').replace(/</g, '&lt;')}</td>
                            <td><span class="cl-badge cl-badge-default">${newLog.intent_classification || '—'}</span></td>
                            <td><span class="cl-badge cl-badge-default">${(newLog.response_type || '—').replace(/_/g, ' ')}</span></td>
                            <td>—</td><td>—</td>
                            <td>${newLog.context_retrieved ? '<i class="fa-solid fa-check" style="color:#10b981;"></i>' : '<i class="fa-solid fa-xmark" style="color:#d1d5db;"></i>'}</td>
                            <td style="white-space:nowrap;font-size:0.75rem;">${newLog.created_at}</td>
                            <td><button class="btn btn-outline-danger btn-sm" style="font-size:0.7rem;padding:2px 8px;" onclick="deleteRecord(${newLog.message_id})"><i class="fa-solid fa-trash"></i></button></td>
                        `;
                        tbody.insertBefore(tr, tbody.firstChild);
                    };
                } catch (e) {
                    console.log('SSE not available');
                }
            </script>

            <!-- Chart Engine (isolated script to prevent parse-error contamination) -->
            <script>
                // ═══════════════════════════════════════════
                // CHART ENGINE — Production SaaS
                // ═══════════════════════════════════════════
                const CL = {
                    grid: 'rgba(99,102,241,0.07)',
                    tick: '#64748b',
                    palette: ['#6366f1', '#8b5cf6', '#ec4899', '#06b6d4', '#10b981', '#f59e0b', '#f43e5e', '#a78bfa', '#0ea5e9', '#14b8a6'],
                    conf: ['#10b981', '#f59e0b', '#ef4444', '#94a3b8'],
                    hourly: ['#6366f1', '#8b5cf6'],
                };

                // Helper: gradient fill
                function clGrad(ctx, area, hex) {
                    if (!area) return hex + '20';
                    const g = ctx.createLinearGradient(0, area.bottom, 0, area.top);
                    g.addColorStop(0, hex + '00');
                    g.addColorStop(1, hex + '30');
                    return g;
                }

                // Helper: show/hide empty state overlay
                function clShowEmpty(id, show) {
                    const el = document.getElementById(id);
                    if (el) el.style.display = show ? 'flex' : 'none';
                }

                // ── 12/24hr time format toggle ──
                let timeFormat = localStorage.getItem('clTimeFormat') ? parseInt(localStorage.getItem('clTimeFormat')) : 24;

                function setTimeFormat(fmt) {
                    timeFormat = fmt;
                    localStorage.setItem('clTimeFormat', fmt);
                    const active = 'padding:4px 10px;border:1px solid #6366f1;border-radius:6px;font-size:0.8rem;background:#6366f1;color:#fff;cursor:pointer;';
                    const idle = 'padding:4px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:0.8rem;background:#fff;color:#64748b;cursor:pointer;';
                    const btn12 = document.getElementById('btn12hr');
                    const btn24 = document.getElementById('btn24hr');
                    if (btn12) btn12.style.cssText = fmt === 12 ? active : idle;
                    if (btn24) btn24.style.cssText = fmt === 24 ? active : idle;
                    document.querySelectorAll('.cl-ts-cell').forEach(cell => {
                        const raw = cell.dataset.raw;
                        if (!raw) return;
                        const d = new Date(raw);
                        if (isNaN(d)) return;
                        cell.textContent = d.toLocaleDateString('en-US', {
                                month: 'short',
                                day: 'numeric'
                            }) + ', ' +
                            d.toLocaleTimeString('en-US', {
                                hour: 'numeric',
                                minute: '2-digit',
                                hour12: fmt === 12
                            });
                    });
                }
                setTimeFormat(timeFormat);

                // ── Period toggle controller ──
                let clCurrentPeriod = 'alltime';

                function setCLPeriod(chartKey, period, btn) {
                    const group = btn.closest('.cl-period-toggle');
                    group.querySelectorAll('button').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    if (chartKey === 'trend') reloadTrendChart(period);
                    if (chartKey === 'hourly') {
                        clCurrentPeriod = period;
                        reloadHourlyChart(period);
                    }
                }

                // ─────────────────────────────────────────
                //  1. MESSAGE TREND
                // ─────────────────────────────────────────
                const trendData = {
                    labels: <?= json_encode($daily_labels) ?>,
                    data: <?= json_encode($daily_counts) ?>
                };

                const trendCtx = document.getElementById('dailyTrendChart');
                let clTrendChart = null;
                if (trendCtx) {
                    const hasData = trendData.data && trendData.data.some(v => v > 0);
                    clShowEmpty('trendEmpty', !hasData);
                    clTrendChart = new Chart(trendCtx, {
                        type: 'line',
                        data: {
                            labels: trendData.labels.length ? trendData.labels : ['No data'],
                            datasets: [{
                                label: 'Messages',
                                data: trendData.data.length ? trendData.data : [0],
                                borderColor: '#6366f1',
                                backgroundColor: function(ctx2) {
                                    return clGrad(ctx2.chart.ctx, ctx2.chart.chartArea, '#6366f1');
                                },
                                fill: true,
                                tension: 0.45,
                                borderWidth: 2.5,
                                pointRadius: 3,
                                pointBackgroundColor: '#6366f1',
                                pointHoverRadius: 6,
                                pointHoverBackgroundColor: '#6366f1',
                                pointHoverBorderColor: '#fff',
                                pointHoverBorderWidth: 2
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
                                    backgroundColor: 'rgba(30,41,59,0.95)',
                                    titleColor: '#f8fafc',
                                    bodyColor: '#cbd5e1',
                                    padding: 10,
                                    cornerRadius: 8,
                                    callbacks: {
                                        label: ctx2 => ' ' + ctx2.parsed.y + ' messages'
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    grid: {
                                        color: CL.grid
                                    },
                                    ticks: {
                                        color: CL.tick,
                                        maxTicksLimit: 6
                                    }
                                },
                                x: {
                                    grid: {
                                        display: false
                                    },
                                    ticks: {
                                        color: CL.tick,
                                        font: {
                                            size: 10
                                        },
                                        maxTicksLimit: 14
                                    }
                                }
                            }
                        }
                    });
                }

                function reloadTrendChart(period) {
                    let url = 'get_daily_messages.php?';
                    if (period === 'daily') url += 'days=14';
                    else if (period === 'weekly') url += 'days=84&group=week';
                    else if (period === 'monthly') url += 'days=365&group=month';
                    else url += 'days=' + period;
                    fetch(url).then(r => r.json()).then(d => {
                        if (!clTrendChart) return;
                        clTrendChart.data.labels = d.labels || [];
                        clTrendChart.data.datasets[0].data = d.data || [];
                        clTrendChart.update('active');
                        const hasData = (d.data || []).some(v => v > 0);
                        clShowEmpty('trendEmpty', !hasData);
                    }).catch(() => {});
                }

                // ─────────────────────────────────────────
                //  2. INTENT DISTRIBUTION
                // ─────────────────────────────────────────
                (function() {
                    const labels = <?= json_encode($intent_labels) ?>;
                    const data = <?= json_encode($intent_counts) ?>;
                    const canvas = document.getElementById('intentChart');
                    if (!canvas) return;
                    const hasData = labels.length > 0;
                    clShowEmpty('intentEmpty', !hasData);
                    if (!hasData) return;
                    new Chart(canvas, {
                        type: 'bar',
                        data: {
                            labels,
                            datasets: [{
                                data,
                                backgroundColor: CL.palette.slice(0, labels.length),
                                borderWidth: 0,
                                borderRadius: 6,
                                borderSkipped: false,
                            }]
                        },
                        options: {
                            indexAxis: 'y',
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                },
                                tooltip: {
                                    backgroundColor: 'rgba(30,41,59,0.95)',
                                    titleColor: '#f8fafc',
                                    bodyColor: '#cbd5e1',
                                    padding: 10,
                                    cornerRadius: 8
                                }
                            },
                            scales: {
                                x: {
                                    beginAtZero: true,
                                    grid: {
                                        color: CL.grid
                                    },
                                    ticks: {
                                        color: CL.tick
                                    }
                                },
                                y: {
                                    grid: {
                                        display: false
                                    },
                                    ticks: {
                                        color: CL.tick,
                                        font: {
                                            size: 11
                                        }
                                    }
                                }
                            }
                        }
                    });
                })();

                // ─────────────────────────────────────────
                //  3. HOURLY ACTIVITY
                // ─────────────────────────────────────────
                const hourlyAllTime = <?= json_encode(array_values($cl_hourly_data)) ?>;
                let clHourlyChart = null;
                let clCurrentView = 'hour';
                let clHourRange = 'all';
                let clHourFmt = typeof timeFormat !== 'undefined' ? timeFormat : 24;
                let clOriginalHourlyData = hourlyAllTime;

                function formatHourLabel(hr, fmt) {
                    if (fmt === 24) return String(hr).padStart(2, '0') + ':00';
                    const ampm = hr >= 12 ? 'PM' : 'AM';
                    const h12 = hr % 12 || 12;
                    return h12 + ' ' + ampm;
                }

                function updateHourlyChartDisplay() {
                    if (!clHourlyChart) return;

                    if (clCurrentView === 'hour') {
                        let labels = [];
                        let data = [];

                        for (let i = 0; i < 24; i++) {
                            let include = true;
                            if (clHourRange === 'morning' && (i < 6 || i > 11)) include = false;
                            if (clHourRange === 'afternoon' && (i < 12 || i > 17)) include = false;
                            if (clHourRange === 'evening' && (i < 18 || i > 21)) include = false;
                            if (clHourRange === 'night' && (i > 5 && i < 22)) include = false;

                            if (include) {
                                labels.push(formatHourLabel(i, clHourFmt));
                                data.push(clOriginalHourlyData[i] || 0);
                            }
                        }

                        clHourlyChart.data.labels = labels;
                        clHourlyChart.data.datasets[0].data = data;
                        clHourlyChart.update('active');
                    }
                }

                function switchCLView(view, btn) {
                    if (btn) {
                        document.querySelectorAll('.chart-time-toggle button').forEach(b => b.classList.remove('active'));
                        btn.classList.add('active');
                    }
                    clCurrentView = view;
                    const hourlyCtrls = document.getElementById('clHourlyControls');
                    if (hourlyCtrls) {
                        hourlyCtrls.style.display = (view === 'hour') ? 'flex' : 'none';
                    }
                    reloadHourlyChart(clCurrentPeriod);
                }

                function applyCLHourFilter() {
                    const sel = document.getElementById('clHourRangeSelect');
                    if (sel) clHourRange = sel.value;
                    updateHourlyChartDisplay();
                }

                function setCLHourFmt(fmt) {
                    clHourFmt = fmt;
                    const btn24 = document.getElementById('clBtn24');
                    const btn12 = document.getElementById('clBtn12');

                    if (btn24 && btn12) {
                        btn24.style.background = fmt === 24 ? '#3b82f6' : '#fff';
                        btn24.style.color = fmt === 24 ? '#fff' : '#374151';
                        btn24.style.border = fmt === 24 ? '1px solid #3b82f6' : '1px solid #d1d5db';

                        btn12.style.background = fmt === 12 ? '#3b82f6' : '#fff';
                        btn12.style.color = fmt === 12 ? '#fff' : '#374151';
                        btn12.style.border = fmt === 12 ? '1px solid #3b82f6' : '1px solid #d1d5db';
                    }

                    updateHourlyChartDisplay();
                }

                (function() {
                    const canvas = document.getElementById('clHourlyChart');
                    if (!canvas) return;

                    let initialLabels = [];
                    for (let i = 0; i < 24; i++) {
                        initialLabels.push(formatHourLabel(i, clHourFmt));
                    }

                    clHourlyChart = new Chart(canvas, {
                        type: 'bar',
                        data: {
                            labels: initialLabels,
                            datasets: [{
                                label: 'Messages',
                                data: hourlyAllTime,
                                backgroundColor: function(ctx2) {
                                    const {
                                        ctx,
                                        chartArea
                                    } = ctx2.chart;
                                    if (!chartArea) return '#6366f1';
                                    const g = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
                                    g.addColorStop(0, 'rgba(99,102,241,0.6)');
                                    g.addColorStop(1, 'rgba(139,92,246,0.9)');
                                    return g;
                                },
                                borderRadius: 5,
                                borderSkipped: false,
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
                                    backgroundColor: 'rgba(30,41,59,0.95)',
                                    titleColor: '#f8fafc',
                                    bodyColor: '#cbd5e1',
                                    padding: 10,
                                    cornerRadius: 8,
                                    callbacks: {
                                        title: items => items[0].label,
                                        label: ctx2 => ' ' + ctx2.parsed.y + ' msgs'
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    grid: {
                                        color: CL.grid
                                    },
                                    ticks: {
                                        color: CL.tick
                                    }
                                },
                                x: {
                                    grid: {
                                        display: false
                                    },
                                    ticks: {
                                        color: CL.tick,
                                        maxTicksLimit: 12,
                                        font: {
                                            size: 10
                                        }
                                    }
                                }
                            }
                        }
                    });

                    // Initial set format
                    document.addEventListener('DOMContentLoaded', () => {
                        setCLHourFmt(clHourFmt);
                    });
                })();

                function reloadHourlyChart(period) {
                    let url = 'chatlogs.php?ajax_hourly=1&view=' + clCurrentView;
                    if (period === '7d') url += '&days=7';
                    else if (period === '30d') url += '&days=30';
                    fetch(url).then(r => r.json()).then(d => {
                        if (!clHourlyChart) return;

                        if (clCurrentView === 'hour') {
                            clOriginalHourlyData = d.hourly || d.data;
                            updateHourlyChartDisplay();
                        } else {
                            clHourlyChart.data.labels = d.labels;
                            clHourlyChart.data.datasets[0].data = d.data;
                            clHourlyChart.update('active');
                        }
                    }).catch(() => {});
                }
            </script>

            <?php include 'includes/confirm_modal.php'; ?>
            <?php include 'includes/global_toasts.php'; ?>
</body>

</html>

<?php
$conn->close();
?>