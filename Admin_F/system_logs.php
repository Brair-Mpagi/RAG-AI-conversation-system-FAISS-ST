<?php
// Production: log errors, never display them
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: ./admin-login.php');
    exit();
}
require_once 'db.php';
if (!$conn || $conn->connect_error) {
    die('Connection failed: ' . ($conn ? $conn->connect_error : 'No connection object.'));
}

// Fetch Admin Details
$admin_query = "SELECT admin_id, username, email FROM admins WHERE admin_id = ?";
if (!$conn->ping()) {
    die('MySQL connection lost.');
}
$admin_stmt = $conn->prepare($admin_query);
$admin_stmt->bind_param("i", $_SESSION['admin_id']);
$admin_stmt->execute();
$admin_result = $admin_stmt->get_result();
$admin = $admin_result->fetch_assoc();

// ---- CSV Export ----
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="system_logs_' . date('Y-m-d_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Level', 'Module', 'Message', 'IP', 'Timestamp']);
    $export_res = $conn->query("SELECT log_id, log_level, module, message, ip_address, timestamp FROM system_logs ORDER BY timestamp DESC LIMIT 5000");
    if ($export_res) {
        while ($row = $export_res->fetch_assoc()) {
            fputcsv($out, $row);
        }
    }
    fclose($out);
    exit();
}

// ---- Handle POST actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['clear_system_logs'])) {
        $conn->query("TRUNCATE TABLE system_logs");
        header("Location: system_logs.php?cleared=1");
        exit();
    }
    if (isset($_POST['mark_reviewed'])) {
        $log_id = (int)$_POST['log_id'];
        $stmt = $conn->prepare("UPDATE system_logs SET is_reviewed = 1, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP WHERE log_id = ?");
        $stmt->bind_param("ii", $_SESSION['admin_id'], $log_id);
        $stmt->execute();
        header("Location: system_logs.php?reviewed=1");
        exit();
    }
    if (isset($_POST['mark_all_pending_reviewed'])) {
        $stmt = $conn->prepare("UPDATE system_logs SET is_reviewed = 1, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP WHERE is_reviewed = 0");
        $stmt->bind_param("i", $_SESSION['admin_id']);
        $stmt->execute();
        header("Location: system_logs.php?all_reviewed=1");
        exit();
    }
}

$cleared = isset($_GET['cleared']);
$reviewed = isset($_GET['reviewed']);
$all_reviewed = isset($_GET['all_reviewed']);

// Filters (auto-applied via GET on every load)
$level_filter  = isset($_GET['level'])  ? $_GET['level']  : '';
$module_filter = isset($_GET['module']) ? trim($_GET['module']) : '';
$search_filter = isset($_GET['search']) ? trim($_GET['search']) : '';
$date_from     = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to       = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : 'pending'; // Default to pending
$limit = isset($_GET['limit']) ? max(10, min(500, (int) $_GET['limit'])) : 50;

// Build query
$where_clauses = [];
$params = [];
$types  = '';

if ($level_filter && in_array($level_filter, ['debug', 'info', 'warning', 'error', 'critical'])) {
    $where_clauses[] = "log_level = ?";
    $params[] = $level_filter;
    $types   .= 's';
}
if ($module_filter) {
    $where_clauses[] = "module LIKE ?";
    $params[] = "%$module_filter%";
    $types   .= 's';
}
if ($search_filter) {
    $where_clauses[] = "(message LIKE ? OR stack_trace LIKE ?)";
    $params[] = "%$search_filter%";
    $params[] = "%$search_filter%";
    $types   .= 'ss';
}
if ($date_from) {
    $where_clauses[] = "timestamp >= ?";
    $params[] = $date_from . ' 00:00:00';
    $types   .= 's';
}
if ($date_to) {
    $where_clauses[] = "timestamp <= ?";
    $params[] = $date_to . ' 23:59:59';
    $types   .= 's';
}
if ($status_filter === 'pending') {
    $where_clauses[] = "is_reviewed = 0";
} elseif ($status_filter === 'reviewed') {
    $where_clauses[] = "is_reviewed = 1";
}

$where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Stats
$total_logs = 0; $pending_count = 0; $reviewed_count = 0; $last_24h = 0;
$stats_query = "SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN is_reviewed = 0 THEN 1 ELSE 0 END) AS pending_review,
    SUM(CASE WHEN is_reviewed = 1 THEN 1 ELSE 0 END) AS reviewed,
    SUM(CASE WHEN timestamp >= NOW() - INTERVAL 24 HOUR THEN 1 ELSE 0 END) AS recent
FROM system_logs";
$stats_result = $conn->query($stats_query);
if ($stats_result && $row = $stats_result->fetch_assoc()) {
    $total_logs   = (int) $row['total'];
    $pending_count= (int) $row['pending_review'];
    $reviewed_count=(int) $row['reviewed'];
    $last_24h     = (int) $row['recent'];
}

// ---- Error Timeline (Last 7 Days) ----
$timeline_data = [];
$timeline_labels = [];
for ($i = 6; $i >= 0; $i--) {
    $dateLabel = date('Y-m-d', strtotime("-$i days"));
    $timeline_labels[] = date('M j', strtotime($dateLabel));
    $timeline_data[$dateLabel] = 0;
}
$tl_res = $conn->query("SELECT DATE(timestamp) as dt, COUNT(*) as cnt FROM system_logs WHERE log_level IN ('error', 'critical', 'warning') AND timestamp >= DATE_SUB(DATE(NOW()), INTERVAL 6 DAY) GROUP BY dt");
if ($tl_res) {
    while ($row = $tl_res->fetch_assoc()) {
        $dt = $row['dt'];
        if (isset($timeline_data[$dt])) {
            $timeline_data[$dt] = (int)$row['cnt'];
        }
    }
}
$timeline_counts = array_values($timeline_data);


// Top Recurring Issues (grouped) - Unreviewed Only
$recurring_issues = [];
try {
    $rec_res = $conn->query("SELECT message, log_level, COUNT(*) as cnt, MAX(timestamp) as last_seen
        FROM system_logs
        WHERE log_level IN ('error', 'critical', 'warning') AND is_reviewed = 0 AND timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY message, log_level
        HAVING cnt > 1
        ORDER BY cnt DESC LIMIT 5");
    if ($rec_res) {
        $recurring_issues = $rec_res->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) {
    error_log("Recurring issues query failed: " . $e->getMessage());
}

// Fetch logs
$log_query = "SELECT log_id, log_level, module, message, stack_trace, ip_address, metadata, timestamp, is_reviewed, reviewed_by, reviewed_at
              FROM system_logs $where_sql ORDER BY timestamp DESC LIMIT ?";
$types .= 'i';
$params[] = $limit;

$log_stmt = $conn->prepare($log_query);
if ($log_stmt) {
    if (!empty($params)) {
        $log_stmt->bind_param($types, ...$params);
    }
    $log_stmt->execute();
    $logs_result = $log_stmt->get_result();
} else {
    $logs_result = false;
}

// Distinct modules for filter dropdown
$modules = [];
$mod_result = $conn->query("SELECT DISTINCT module FROM system_logs ORDER BY module");
if ($mod_result) {
    while ($m = $mod_result->fetch_assoc()) {
        $modules[] = $m['module'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Logs - Admin Panel</title>
    <link rel="shortcut icon" href="images/mmu_logo_- no bg.png" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"
        integrity="sha512-Kc323vGBEqzTmouAECnVceyQqyqdsSiqLQISBL29aUW4U/M7pSPA/gEUZQqv1cwx4OnYxTxve5UMg5GT6L4JJg=="
        crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="css/style.css?v=1775081173">
    <link rel="stylesheet" href="css/admin.css">
    <style>
        .log-message {
            max-width: 500px;
            word-break: break-word;
        }

        .stack-toggle {
            color: var(--primary-color);
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .stack-toggle:hover {
            text-decoration: underline;
        }

        .stack-content {
            display: none;
            margin-top: 8px;
            background: var(--gray-50);
            padding: 10px;
            border-radius: var(--radius-md);
            font-family: monospace;
            font-size: 0.75rem;
            white-space: pre-wrap;
            max-height: 200px;
            overflow-y: auto;
        }

        .metadata-pill {
            display: inline-block;
            background: var(--gray-100);
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.7rem;
            color: var(--gray-600);
            margin: 1px 2px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray-400);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 16px;
            display: block;
        }

        /* Button hover fixes */
        .btn-modern, .btn-primary-modern, .btn-secondary-modern, .btn-danger-modern { color: #fff !important; }
        .btn-modern:hover, .btn-primary-modern:hover, .btn-secondary-modern:hover, .btn-danger-modern:hover,
        .btn-modern:focus, .btn-primary-modern:focus, .btn-secondary-modern:focus { color: #fff !important; opacity: 0.88; }

        /* Recurring issues card */
        .recurring-card {
            background: #fffbeb; border: 1px solid #fde68a; border-radius: 12px;
            padding: 16px 20px; margin-bottom: 16px;
        }
        .recurring-card h6 { color: #92400e; font-weight: 700; margin-bottom: 10px; font-size: 0.9rem; }
        .recurring-item {
            display: flex; align-items: center; gap: 10px; padding: 6px 0;
            border-bottom: 1px solid rgba(245,158,11,0.15); font-size: 0.82rem;
        }
        .recurring-item:last-child { border-bottom:none; }
        .recurring-count { background:#dc2626; color:#fff; padding:2px 8px; border-radius:12px; font-weight:700; font-size:0.75rem; min-width:30px; text-align:center; }
        .recurring-msg { flex:1; color:#44403c; word-break:break-word; }
        .recurring-time { color:#a8a29e; font-size:0.72rem; white-space:nowrap; }

        /* Date range inputs */
        .date-filter { display:flex; align-items:center; gap:6px; }
        .date-filter input[type="date"] { padding:5px 8px; border:1px solid #d1d5db; border-radius:6px; font-size:0.82rem; }
        .date-filter label { font-size:0.78rem; color:#6b7280; white-space:nowrap; }
    </style>
</head>

<body>
    <!--== MAIN CONTAINER ==-->
      <!--== MAIN CONTAINER ==-->
    <?php include 'includes/topbar.php'; ?>

    </div>
    </div>
    
    <div class="container-fluid sb2">
        <div class="row">
            <?php include 'includes/sidebar.php'; ?>
            <div class="sb2-2 col-md-9">
                
                <div class="db-2">
                    <h2><i class="fa-solid fa-scroll" style="color: var(--primary-color);"></i> System Logs</h2>

                    <?php if ($cleared): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert" style="margin-bottom:12px;">
                            <i class="fa-solid fa-check-circle"></i> All system logs have been cleared successfully.
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    <?php if ($reviewed): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert" style="margin-bottom:12px;background:#f0fdf4;border-color:#bbf7d0;color:#166534;">
                            <i class="fa-solid fa-circle-check"></i> System log marked as reviewed.
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    <?php if ($all_reviewed): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert" style="margin-bottom:12px;background:#f0fdf4;border-color:#bbf7d0;color:#166534;">
                            <i class="fa-solid fa-check-double"></i> All pending system logs have been marked as reviewed.
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Stat Cards -->
                    <div class="stat-cards-row">
                        <div class="stat-card">
                            <div class="stat-card-icon blue"><i class="fa-solid fa-list"></i></div>
                            <h6>Total Logs</h6>
                            <p class="stat-value"><?= number_format($total_logs) ?></p>
                        </div>
                        <div class="stat-card">
                            <div class="stat-card-icon red"><i class="fa-solid fa-triangle-exclamation"></i></div>
                            <h6>Pending Review</h6>
                            <p class="stat-value"><?= number_format($pending_count) ?></p>
                        </div>
                        <div class="stat-card">
                            <div class="stat-card-icon green"><i class="fa-solid fa-check-double"></i></div>
                            <h6>Reviewed Logs</h6>
                            <p class="stat-value"><?= number_format($reviewed_count) ?></p>
                        </div>
                        <div class="stat-card">
                            <div class="stat-card-icon green"><i class="fa-solid fa-clock"></i></div>
                            <h6>Last 24 Hours</h6>
                            <p class="stat-value"><?= number_format($last_24h) ?></p>
                        </div>
                    </div>

                    <!-- Top Recurring Issues -->
                    <?php if (!empty($recurring_issues)): ?>
                    <div class="recurring-card" style="background: #fef2f2; border: 1px solid #fecaca;">
                        <h6 style="color: #991b1b;"><i class="fa-solid fa-lightbulb"></i> Actionable Insights: Unreviewed Recurring Issues (7 days)</h6>
                        <p style="font-size:0.8rem; color: #7f1d1d; margin-bottom:12px;">The following critical or warning logs are repeating and require administrative review.</p>
                        <?php foreach ($recurring_issues as $ri): ?>
                        <div class="recurring-item">
                            <span class="recurring-count">&times;<?= $ri['cnt'] ?></span>
                            <span class="log-badge log-badge-<?= htmlspecialchars($ri['log_level']) ?>" style="font-size:0.7rem;"><?= htmlspecialchars($ri['log_level']) ?></span>
                            <span class="recurring-msg"><?= htmlspecialchars(mb_strimwidth($ri['message'], 0, 120, '…')) ?></span>
                            <span class="recurring-time"><?= date('M j H:i', strtotime($ri['last_seen'])) ?></span>
                        </div>
                        <?php endforeach; ?>
                        
                        <!-- Timeline Chart -->
                        <div style="margin-top: 15px; border-top: 1px dashed rgba(245,158,11,0.3); padding-top: 15px;">
                            <h6 style="color:#b45309; font-size:0.8rem; margin-bottom:8px;">Error & Warning Heatmap (7 Days)</h6>
                            <div style="height: 100px; width: 100%;">
                                <canvas id="errorTimelineChart"></canvas>
                            </div>
                        </div>
                        
                    </div>
                    <?php endif; ?>

                    <!-- Filters -->
                    <form method="GET" id="logsFilterForm" class="filters">
                        <select name="status" onchange="this.form.submit()">
                            <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Logs</option>
                            <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending Review</option>
                            <option value="reviewed" <?= $status_filter === 'reviewed' ? 'selected' : '' ?>>Reviewed</option>
                        </select>
                        <select name="level" onchange="this.form.submit()">
                            <option value="">All Levels</option>
                            <?php foreach (['debug', 'info', 'warning', 'error', 'critical'] as $lvl): ?>
                                <option value="<?= $lvl ?>" <?= $level_filter === $lvl ? 'selected' : '' ?>>
                                    <?= ucfirst($lvl) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <select name="module" onchange="this.form.submit()">
                            <option value="">All Modules</option>
                            <?php foreach ($modules as $mod): ?>
                                <option value="<?= htmlspecialchars($mod) ?>" <?= $module_filter === $mod ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($mod) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="search" id="searchInput"
                            value="<?= htmlspecialchars($search_filter) ?>"
                            placeholder="Search messages...">
                        <input type="number" name="limit" value="<?= $limit ?>" min="10" max="500"
                            style="width:80px;" title="Results limit" onchange="this.form.submit()">

                        <!-- Date range filter -->
                        <div class="date-filter">
                            <label>From</label>
                            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" onchange="this.form.submit()">
                            <label>To</label>
                            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" onchange="this.form.submit()">
                        </div>

                        <!-- Reset button -->
                        <a href="system_logs.php" class="btn btn-modern btn-secondary-modern"
                            style="text-decoration:none;"><i class="fa-solid fa-rotate"></i> Reset</a>
                        <!-- Auto-refresh toggle -->
                        <label class="auto-refresh-toggle" style="margin-left: auto;">
                            <input type="checkbox" id="autoRefresh"> Auto-refresh (30s)
                        </label>
                    </form>

                    <!-- Action bar -->
                    <div style="display:flex;gap:10px;margin-bottom:12px;align-items:center;flex-wrap:wrap;">
                        <form method="POST" style="display:inline;" id="clearLogsForm">
                            <input type="hidden" name="clear_system_logs" value="1">
                        </form>
                        <button class="btn btn-modern btn-danger-modern"
                            style="background: linear-gradient(135deg,#dc3545,#b02a37);border:none;"
                            onclick="showConfirmModal({title:'Clear All Logs', message:'This will permanently delete ALL system log entries. This action cannot be undone.', confirmText:'CLEAR', formId:'clearLogsForm'})">
                            <i class="fa-solid fa-trash-can"></i> Clear All Logs
                        </button>
                        <form method="POST" style="display:inline;" id="markAllReviewedForm">
                            <input type="hidden" name="mark_all_pending_reviewed" value="1">
                        </form>
                        <button class="btn btn-modern btn-success"
                            style="background: linear-gradient(135deg,#198754,#146c43);border:none;color:#fff;"
                            onclick="showConfirmModal({title:'Review All Pending Logs', message:'This will mark all currently pending system logs as reviewed. Proceed?', confirmText:'MARK ALL', formId:'markAllReviewedForm'})">
                            <i class="fa-solid fa-check-double"></i> Review All Pending
                        </button>
                        <a href="?export=csv" class="btn btn-modern btn-secondary-modern"
                            style="background:linear-gradient(135deg,#475569,#334155);border:none;text-decoration:none;">
                            <i class="fa-solid fa-file-csv"></i> Export CSV
                        </a>
                        <span style="color:var(--gray-500);font-size:0.82rem;margin-left:auto;">
                            Showing <?= $logs_result ? $logs_result->num_rows : 0 ?> of
                            <?= number_format($total_logs) ?> logs
                        </span>
                    </div>

                    <!-- Logs Table -->
                    <div class="admin-table-container" style="overflow-x: auto;">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th class="admin-th">ID</th>
                                    <th class="admin-th">Level</th>
                                    <th class="admin-th">Module</th>
                                    <th class="admin-th">Message</th>
                                    <th class="admin-th">IP</th>
                                    <th class="admin-th">Metadata</th>
                                    <th class="admin-th">Status</th>
                                    <th class="admin-th">Timestamp</th>
                                    <th class="admin-th">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($logs_result && $logs_result->num_rows > 0): ?>
                                    <?php while ($log = $logs_result->fetch_assoc()): ?>
                                        <tr class="admin-tr" data-timestamp="<?= htmlspecialchars($log['timestamp'])?>" data-log-id="<?= $log['log_id']?>">
                                            <td class="admin-td"><?= $log['log_id'] ?></td>
                                            <td class="admin-td">
                                                <span class="log-badge log-badge-<?= htmlspecialchars($log['log_level']) ?>">
                                                    <?= htmlspecialchars($log['log_level']) ?>
                                                </span>
                                            </td>
                                            <td class="admin-td"><strong><?= htmlspecialchars($log['module']) ?></strong></td>
                                            <td class="admin-td log-message">
                                                <?= htmlspecialchars(mb_strimwidth($log['message'], 0, 200, '...')) ?>
                                                <?php if (!empty($log['stack_trace'])): ?>
                                                    <br>
                                                    <span class="stack-toggle"
                                                        onclick="this.nextElementSibling.style.display = this.nextElementSibling.style.display === 'block' ? 'none' : 'block'">
                                                        <i class="fa-solid fa-code"></i> Stack trace
                                                    </span>
                                                    <div class="stack-content"><?= htmlspecialchars($log['stack_trace']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="admin-td"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></td>
                                            <td class="admin-td">
                                                <?php if (!empty($log['metadata'])): ?>
                                                    <?php
                                                    $meta = json_decode($log['metadata'], true);
                                                    if (is_array($meta)):
                                                        foreach (array_slice($meta, 0, 4) as $k => $v): ?>
                                                            <span class="metadata-pill"><?= htmlspecialchars($k) ?>:
                                                                <?= htmlspecialchars(is_string($v) ? mb_strimwidth($v, 0, 30, '...') : json_encode($v)) ?></span>
                                                    <?php endforeach;
                                                    endif; ?>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                            <td class="admin-td">
                                                <?php if ($log['is_reviewed']): ?>
                                                    <span class="badge bg-success" title="Reviewed At: <?= htmlspecialchars($log['reviewed_at']) ?>"><i class="fa-solid fa-check-double"></i> Reviewed</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark"><i class="fa-solid fa-clock"></i> Pending</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="admin-td" style="white-space: nowrap; font-size: 0.8rem;">
                                                <?= date('M j, Y H:i', strtotime($log['timestamp'])) ?>
                                            </td>
                                            <td class="admin-td">
                                                <?php if (!$log['is_reviewed']): ?>
                                                    <form method="POST" style="display:inline;">
                                                        <input type="hidden" name="mark_reviewed" value="1">
                                                        <input type="hidden" name="log_id" value="<?= $log['log_id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-success" style="padding:2px 6px;font-size:0.75rem;" title="Mark as reviewed"><i class="fa-solid fa-check"></i></button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr class="admin-tr">
                                        <td class="admin-td" colspan="9">
                                            <div class="empty-state">
                                                <i class="fa-solid fa-inbox"></i>
                                                <p>No log entries found</p>
                                                <p style="font-size: 0.85rem;">Logs will appear here once the chatbot
                                                    backend processes requests.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
        crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <script>
        // ---- Render Error Timeline Chart ----
        const tlLabels = <?= json_encode($timeline_labels) ?>;
        const tlData = <?= json_encode($timeline_counts) ?>;
        const ctx = document.getElementById('errorTimelineChart');
        if (ctx) {
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: tlLabels,
                    datasets: [{
                        label: 'Issues',
                        data: tlData,
                        backgroundColor: tlData.map(v => v > 5 ? 'rgba(239, 68, 68, 0.8)' : 'rgba(245, 158, 11, 0.6)'),
                        borderRadius: 4,
                        barPercentage: 0.6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: { displayColors: false } },
                    scales: {
                        x: { grid: { display: false }, ticks: { font: { size: 10 } } },
                        y: { 
                            grid: { color: 'rgba(0,0,0,0.05)' }, 
                            ticks: { precision: 0, font: { size: 10 }, padding: 4 },
                            beginAtZero: true
                        }
                    }
                }
            });
        }
    </script>

    <script>
        // ---- Auto-search on input with debounce ----
        let searchTimer;
        document.getElementById('searchInput').addEventListener('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => { document.getElementById('logsFilterForm').submit(); }, 600);
        });

        // ---- Auto-refresh with localStorage persistence ----
        const AR_KEY = 'syslogsAutoRefresh';
        const arCheckbox = document.getElementById('autoRefresh');
        let refreshInterval;

        function startRefresh() {
            refreshInterval = setInterval(() => { location.reload(); }, 30000);
            arCheckbox.checked = true;
            localStorage.setItem(AR_KEY, '1');
        }
        function stopRefresh() {
            clearInterval(refreshInterval);
            arCheckbox.checked = false;
            localStorage.removeItem(AR_KEY);
        }

        // Restore state on load
        if (localStorage.getItem(AR_KEY) === '1') startRefresh();

        arCheckbox.addEventListener('change', function () {
            if (this.checked) startRefresh(); else stopRefresh();
        });
    </script>

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

        // ---- Deep-link highlight from dashboard activity feed ----
        (function() {
            const params = new URLSearchParams(window.location.search);
            const hl = params.get('highlight');
            if (!hl) return;
            // Find the row whose timestamp best matches
            const rows = document.querySelectorAll('tr[data-timestamp]');
            let target = null;
            for (const row of rows) {
                const ts = row.getAttribute('data-timestamp');
                if (ts === hl || ts.startsWith(hl)) {
                    target = row;
                    break;
                }
            }
            // Also try matching by date format
            if (!target) {
                const hlDate = new Date(hl);
                for (const row of rows) {
                    const rowDate = new Date(row.getAttribute('data-timestamp'));
                    if (Math.abs(hlDate - rowDate) < 2000) { // within 2 seconds
                        target = row;
                        break;
                    }
                }
            }
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                target.style.transition = 'background 0.3s ease';
                target.style.background = '#fef3c7';
                target.style.boxShadow = '0 0 0 2px #f59e0b';
                setTimeout(() => {
                    target.style.background = '#fffbeb';
                    target.style.boxShadow = 'none';
                }, 3000);
            }
        })();
    </script>
    <?php include 'includes/global_toasts.php'; ?>
    <?php include 'includes/confirm_modal.php'; ?>
</body>

</html>