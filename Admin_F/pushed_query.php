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
    // Redirect to login page if not authenticated
    header("Location: ./admin-login.php");
    exit();
}

require_once 'db.php';
if (!$conn || $conn->connect_error) {
    die("Connection failed: " . ($conn ? $conn->connect_error : 'No connection object.'));
}
if (!$conn->ping()) {
    die("Database connection is closed.");
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

    // Now you can use the $admin array
    if ($admin) {
        // Access admin details like this:
        // echo "Welcome, " . $admin['username'];
    } else {
        // Handle the case where no admin is found with the given ID
        echo "Admin not found.";
    }
} else {
    // Handle the case where the prepare statement failed
    echo "Error preparing admin query: " . $conn->error;
}

// Handle Status Update
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['update_status'])) {
    $id = (int) $_POST['id'];
    $new_status = $_POST['status'] ?? 'pending';
    $admin_response = trim($_POST['admin_response'] ?? '');
    $is_resolved = in_array($new_status, ['resolved', 'closed'], true);

    if ($is_resolved) {
        $resolved_at = date('Y-m-d H:i:s');
        $resolved_by = (int) ($_SESSION['admin_id'] ?? 0);
        $sql_update = "UPDATE user_queries SET status = ?, admin_response = ?, resolved_at = ?, resolved_by = ? WHERE query_id = ?";
        $stmt = $conn->prepare($sql_update);
        $stmt->bind_param("sssii", $new_status, $admin_response, $resolved_at, $resolved_by, $id);
    } else {
        $sql_update = "UPDATE user_queries SET status = ?, admin_response = ?, resolved_at = NULL, resolved_by = NULL WHERE query_id = ?";
        $stmt = $conn->prepare($sql_update);
        $stmt->bind_param("ssi", $new_status, $admin_response, $id);
    }
    $stmt->execute();
}

// Handle Delete Actions
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['delete_query'])) {
    $id = (int) $_POST['id'];
    $sql_delete = "DELETE FROM user_queries WHERE query_id = ?";
    $stmt = $conn->prepare($sql_delete);
    $stmt->bind_param("i", $id);
    $stmt->execute();
}

// Handle Reopen Actions
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['reopen_query'])) {
    $id = (int) $_POST['id'];
    $sql_update = "UPDATE user_queries SET status = 'pending', resolved_at = NULL, resolved_by = NULL WHERE query_id = ?";
    $stmt = $conn->prepare($sql_update);
    $stmt->bind_param("i", $id);
    $stmt->execute();
}

// Handle Delete All
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['delete_all_open'])) {
    $conn->query("DELETE FROM user_queries WHERE status NOT IN ('resolved','closed')");
}
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['delete_all_responded'])) {
    $conn->query("DELETE FROM user_queries WHERE status IN ('resolved','closed')");
}

// ===== STATS QUERIES =====
$open_count_result = $conn->query("SELECT COUNT(*) as cnt FROM user_queries WHERE status NOT IN ('resolved','closed')");
$open_count = $open_count_result ? (int)$open_count_result->fetch_assoc()['cnt'] : 0;

$urgent_count_result = $conn->query("SELECT COUNT(*) as cnt FROM user_queries WHERE status NOT IN ('resolved','closed') AND (priority = 'urgent' OR priority = 'high' OR TIMESTAMPDIFF(HOUR, submitted_at, NOW()) > 24)");
$urgent_count = $urgent_count_result ? (int)$urgent_count_result->fetch_assoc()['cnt'] : 0;

$avg_wait_result = $conn->query("SELECT AVG(TIMESTAMPDIFF(MINUTE, submitted_at, NOW())) as avg_wait FROM user_queries WHERE status NOT IN ('resolved','closed')");
$avg_wait_minutes = $avg_wait_result ? round($avg_wait_result->fetch_assoc()['avg_wait'] ?? 0) : 0;
if ($avg_wait_minutes >= 60) {
    $avg_wait_display = round($avg_wait_minutes / 60, 1) . 'h';
} else {
    $avg_wait_display = $avg_wait_minutes . 'm';
}

$resolved_today_result = $conn->query("SELECT COUNT(*) as cnt FROM user_queries WHERE status IN ('resolved','closed') AND DATE(resolved_at) = CURDATE()");
$resolved_today = $resolved_today_result ? (int)$resolved_today_result->fetch_assoc()['cnt'] : 0;

// 7-day open query trend for sparkline
$q_trend = [];
for ($di = 6; $di >= 0; $di--) {
    $day = date('Y-m-d', strtotime("-{$di} days"));
    $q_trend[$day] = 0;
}
$q_trend_result = $conn->query("SELECT DATE(submitted_at) as day, COUNT(*) as cnt FROM user_queries WHERE status NOT IN ('resolved','closed') AND submitted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY DATE(submitted_at)");
if ($q_trend_result) {
    while ($row = $q_trend_result->fetch_assoc()) {
        $key = date('Y-m-d', strtotime($row['day']));
        if (isset($q_trend[$key])) $q_trend[$key] = (int)$row['cnt'];
    }
}
$q_trend_values = array_values($q_trend);

// Fetch Queries with session info
$queries_result = $conn->query(
    "SELECT q.*, 
            s.session_token,
            s.ip_address,
            s.location,
            COALESCE(q.user_name, CONCAT('Session-', q.session_id)) AS user_name_display,
            COALESCE(q.user_email, s.ip_address, 'N/A') AS user_email_display
     FROM user_queries q
     LEFT JOIN web_sessions s ON q.session_id = s.session_id
     ORDER BY q.submitted_at DESC"
);

// Fetch Responded Inquiries (resolved/closed)
$responded_result = $conn->query(
    "SELECT q.*, 
            s.session_token,
            s.ip_address,
            s.location,
            COALESCE(q.user_name, CONCAT('Session-', q.session_id)) AS user_name_display,
            COALESCE(q.user_email, s.ip_address, 'N/A') AS user_email_display,
            a.full_name AS admin_name
     FROM user_queries q
     LEFT JOIN web_sessions s ON q.session_id = s.session_id
     LEFT JOIN admins a ON q.resolved_by = a.admin_id
     WHERE q.status IN ('resolved','closed')
     ORDER BY q.resolved_at DESC"
);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>User Queries — Admin Panel</title>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="shortcut icon" href="images/mmu_logo_- no bg.png" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/style.css?v=1775081173">
    <link rel="stylesheet" href="css/admin.css">
    <style>
        .pq-stats-row {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .pq-stat-card {
            flex: 1;
            min-width: 150px;
            background: linear-gradient(135deg, #002147 0%, #05356b 100%);
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px 18px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
        }

        .pq-stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #fff;
        }

        .pq-stat-label {
            font-size: 0.78rem;
            color: #fff;
            margin-top: 4px;
        }

        .pq-stat-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            margin-bottom: 8px;
        }

        .pq-sparkline {
            display: flex;
            align-items: flex-end;
            gap: 2px;
            height: 28px;
            margin-top: 6px;
        }

        .pq-sparkline-bar {
            width: 6px;
            border-radius: 2px 2px 0 0;
            background: #6366f1;
            transition: height 0.2s;
        }

        .pq-query-text {
            max-width: 320px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: pointer;
        }

        .pq-query-text:hover {
            white-space: normal;
            word-break: break-word;
        }

        .pq-user-cell .pq-user-email {
            font-size: 0.72rem;
            color: #94a3b8;
            margin-top: 1px;
        }

        .pq-sla-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.72rem;
            font-weight: 600;
        }

        .pq-sla-green {
            background: rgba(16, 185, 129, 0.12);
            color: #059669;
        }

        .pq-sla-amber {
            background: rgba(245, 158, 11, 0.12);
            color: #d97706;
        }

        .pq-sla-red {
            background: rgba(239, 68, 68, 0.12);
            color: #dc2626;
        }
    </style>
</head>

<body>
    <!--== MAIN CONTAINER ==-->
      <!--== MAIN CONTAINER ==-->
    <?php include 'includes/topbar.php'; ?>

    </div>
    </div>

    <!-- Body Container -->
    <div class="container-fluid sb2">
        <div class="row">
            <?php include 'includes/sidebar.php'; ?>

            <!-- Main Content -->
            <div class="sb2-2">
                <!-- Breadcrumbs -->

                <div class="sb2-2-1">
                    <div class="db-2">

                        <!-- Page Header -->
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
                            <div>
                                <h2 style="color:#104a95;font-size:1.4rem;margin:0;font-weight:700;">
                                    <i class="fa-solid fa-inbox" style="color:#18569d;margin-right:8px;"></i>User Queries
                                </h2>
                                <p style="color:#64748b;font-size:0.82rem;margin:4px 0 0;">Manage and respond to user inquiries</p>
                            </div>
                            <div style="color:#64748b;font-size:0.78rem;">
                                <i class="fa-solid fa-clock"></i> Last updated: <?= date('M j, g:ia') ?>
                            </div>
                        </div>

                        <!-- ===== STATS CARDS ===== -->
                        <div class="pq-stats-row">
                            <div class="pq-stat-card">
                                <div class="pq-stat-icon" style="background:rgba(99,102,241,0.1);color:#6366f1;">
                                    <i class="fa-solid fa-inbox"></i>
                                </div>
                                <div class="pq-stat-value"><?= $open_count ?></div>
                                <div class="pq-stat-label">Open Inquiries</div>
                                <div class="pq-sparkline">
                                    <?php
                                    $max_trend = max(1, max($q_trend_values));
                                    foreach ($q_trend_values as $v): ?>
                                        <div class="pq-sparkline-bar" style="height:<?= max(2, round(($v / $max_trend) * 28)) ?>px;"></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="pq-stat-card">
                                <div class="pq-stat-icon" style="background:rgba(245,158,11,0.1);color:#f59e0b;">
                                    <i class="fa-solid fa-hourglass-half"></i>
                                </div>
                                <div class="pq-stat-value"><?= $avg_wait_display ?></div>
                                <div class="pq-stat-label">Avg Wait Time</div>
                                <div style="font-size:0.72rem;color:#94a3b8;margin-top:4px;">Open Inquiries mean age</div>
                            </div>
                            <div class="pq-stat-card">
                                <div class="pq-stat-icon" style="background:rgba(16,185,129,0.1);color:#10b981;">
                                    <i class="fa-solid fa-check-circle"></i>
                                </div>
                                <div class="pq-stat-value" style="color:#059669;"><?= $resolved_today ?></div>
                                <div class="pq-stat-label">Resolved Today</div>
                            </div>
                        </div>

                        <!-- Tables Container -->
                        <div class="admin-table-container">
                            <div class="admin-header-container" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                                <h2 class="admin-h2">Open Inquiries</h2>
                                <form id="deleteAllOpenForm" method="POST" style="display:none;margin-left:auto;">
                                    <input type="hidden" name="delete_all_open" value="1">
                                </form>
                                <button type="button" style="margin-left:auto;padding:6px 14px;background:#dc3545;color:#fff;border:none;border-radius:6px;font-size:0.82rem;cursor:pointer;"
                                    onclick="showConfirmModal({title:'Delete All Open Inquiries', message:'This will permanently delete ALL pending/in-progress user queries. This cannot be undone.', confirmText:'DELETE', formId:'deleteAllOpenForm'})">
                                    <i class="fa-solid fa-trash"></i> Delete All
                                </button>
                            </div>
                            <!-- Filter Controls -->
                            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px;">
                                <input type="text" id="searchOpen" placeholder="Search queries…"
                                    style="flex:1;min-width:180px;padding:7px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:0.85rem;font-family:Inter,sans-serif;">
                            </div>
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th class="admin-th">ID</th>
                                        <th class="admin-th">UserName</th>
                                        <th class="admin-th">Email</th>
                                        <th class="admin-th">Query</th>
                                        <th class="admin-th">Submitted</th>
                                        <th class="admin-th">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="queries-body">
                                    <?php while ($row = $queries_result->fetch_assoc()): ?>
                                        <?php if ($row['status'] !== 'resolved' && $row['status'] !== 'closed'): ?>
                                            <?php
                                            $sub_time = strtotime($row['submitted_at']);
                                            $hours_ago = floor((time() - $sub_time) / 3600);
                                            $age_str = $hours_ago . 'h ago';
                                            if ($hours_ago < 1) $age_str = '< 1h ago';
                                            $sla_class = 'pq-sla-green';
                                            if ($hours_ago > 24) $sla_class = 'pq-sla-red';
                                            elseif ($hours_ago > 4) $sla_class = 'pq-sla-amber';
                                            $has_email = strpos($row['user_email_display'] ?? '', '@') !== false;
                                            ?>
                                            <tr class="admin-tr">
                                                <td class="admin-td"><?= $row['query_id'] ?></td>
                                                <td class="admin-td">
                                                    <div style="font-weight:500;"><?= htmlspecialchars($row['user_name_display'] ?? 'Guest') ?></div>
                                                </td>
                                                <td class="admin-td pq-user-cell">
                                                    <div class="pq-user-email" style="margin-top:0; font-size:0.85rem; color:inherit;"><?= htmlspecialchars($row['user_email_display'] ?? 'N/A') ?></div>
                                                </td>
                                                <td class="admin-td pq-query-text" title="<?= htmlspecialchars($row['query_text']) ?>">
                                                    <?= htmlspecialchars($row['query_text']) ?>
                                                </td>
                                                <td class="admin-td">
                                                    <div style="font-size:0.82rem;"><?= date('M j, g:i A', $sub_time) ?></div>
                                                    <span class="pq-sla-badge <?= $sla_class ?>">
                                                        <?php if ($hours_ago > 24) echo '<i class="fa-solid fa-triangle-exclamation" style="font-size:0.6rem;"></i> '; ?>
                                                        <?= $age_str ?>
                                                    </span>
                                                </td>
                                                <td class="admin-td">
                                                    <div style="display: flex; gap: 5px;">
                                                        <button type="button" class="action-btn email-btn"
                                                            data-id="<?= $row['query_id'] ?>"
                                                            data-name="<?= htmlspecialchars($row['user_name_display'] ?? 'Guest') ?>"
                                                            data-email="<?= $has_email ? htmlspecialchars($row['user_email_display']) : '' ?>"
                                                            data-text="<?= htmlspecialchars($row['query_text'] ?? '') ?>"
                                                            onclick="openEmailModal(this.dataset.id, this.dataset.name, this.dataset.email, this.dataset.text)"
                                                            <?= !$has_email ? 'disabled title="No email address available"' : '' ?>>
                                                            <i class="fa-solid fa-envelope"></i>
                                                        </button>
                                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Mark this query as responded?');">
                                                            <input type="hidden" name="id" value="<?= $row['query_id'] ?>">
                                                            <input type="hidden" name="status" value="resolved">
                                                            <input type="hidden" name="update_status" value="1">
                                                            <input type="hidden" name="admin_response" value="Marked as responded via quick action">
                                                            <button type="submit" class="action-btn" style="background:#10b981; color:#fff;" title="Mark as Responded">
                                                                <i class="fa-solid fa-check"></i> Respond
                                                            </button>
                                                        </form>
                                                        <?php $qDelFormId = 'qDel' . $row['query_id']; ?>
                                                        <form id='<?= $qDelFormId ?>' method="POST" style="display:none;">
                                                            <input type="hidden" name="id" value="<?= $row['query_id'] ?>">
                                                            <input type="hidden" name="delete_query" value="1">
                                                        </form>
                                                        <button type='button' class="action-btn delete-btn"
                                                            onclick="showConfirmModal({title:'Delete Query', message:'Permanently delete query #<?= $row['query_id'] ?>?', confirmText:'DELETE', formId:'<?= $qDelFormId ?>'})">
                                                            <i class="fa-solid fa-trash"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Responded Inquiries Table -->
                        <div class="admin-table-container">
                            <div class="admin-header-container" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                                <h2 class="admin-h2">Responded Inquiries</h2>
                                <form id="deleteAllRespondedForm" method="POST" style="display:none;margin-left:auto;">
                                    <input type="hidden" name="delete_all_responded" value="1">
                                </form>
                                <button type="button" style="margin-left:auto;padding:6px 14px;background:#dc3545;color:#fff;border:none;border-radius:6px;font-size:0.82rem;cursor:pointer;"
                                    onclick="showConfirmModal({title:'Delete All Responded Inquiries', message:'This will permanently delete ALL resolved/closed queries. This cannot be undone.', confirmText:'DELETE', formId:'deleteAllRespondedForm'})">
                                    <i class="fa-solid fa-trash"></i> Delete All
                                </button>
                            </div>
                            <!-- Filter Controls -->
                            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px;">
                                <input type="text" id="searchResolved" placeholder="Search resolved…"
                                    style="flex:1;min-width:180px;padding:7px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:0.85rem;font-family:Inter,sans-serif;">

                            </div>
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th class="admin-th">ID</th>
                                        <th class="admin-th">UserName</th>
                                        <th class="admin-th">Email</th>
                                        <th class="admin-th">Resolved At</th>
                                        <th class="admin-th">Responded By</th>
                                        <th class="admin-th">Response</th>
                                        <th class="admin-th">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($responded_result && $responded_result->num_rows > 0): ?>
                                        <?php while ($row = $responded_result->fetch_assoc()): ?>
                                            <tr class="admin-tr">
                                                <td class="admin-td"><?= (int) $row['query_id'] ?></td>
                                                <td class="admin-td">
                                                    <div style="font-weight:500;"><?= htmlspecialchars($row['user_name_display'] ?? 'Guest') ?></div>
                                                </td>
                                                <td class="admin-td pq-user-cell">
                                                    <div class="pq-user-email" style="margin-top:0; font-size:0.85rem; color:inherit;"><?= htmlspecialchars($row['user_email_display'] ?? 'N/A') ?></div>
                                                </td>
                                                <td class="admin-td" style="font-size:0.82rem;"><?= $row['resolved_at'] ? date('M j, g:i A', strtotime($row['resolved_at'])) : '—' ?></td>
                                                <td class="admin-td"><?= htmlspecialchars($row['admin_name'] ?? '—') ?></td>
                                                <td class="admin-td pq-query-text" title="<?= htmlspecialchars($row['admin_response'] ?? '') ?>">
                                                    <?= htmlspecialchars($row['admin_response'] ?? '—') ?>
                                                </td>
                                                <td class="admin-td">
                                                    <?php $rReopenFormId = 'rReopen' . $row['query_id']; ?>
                                                    <form id='<?= $rReopenFormId ?>' method="POST" style="display:none;">
                                                        <input type="hidden" name="id" value="<?= (int) $row['query_id'] ?>">
                                                        <input type="hidden" name="reopen_query" value="1">
                                                    </form>
                                                    <button type='button' class="action-btn"
                                                        style="background:#f59e0b;color:#fff;border:none;padding:4px 10px;border-radius:6px;cursor:pointer;font-size:0.78rem;margin-right:5px;"
                                                        onclick="showConfirmModal({title:'Reopen Query', message:'Push query #<?= $row['query_id'] ?> back to open inquiries?', confirmText:'REOPEN', formId:'<?= $rReopenFormId ?>'})">
                                                        <i class="fa-solid fa-folder-open"></i> Reopen
                                                    </button>
                                                    <?php $rDelFormId = 'rDel' . $row['query_id']; ?>
                                                    <form id='<?= $rDelFormId ?>' method="POST" style="display:none;">
                                                        <input type="hidden" name="id" value="<?= (int) $row['query_id'] ?>">
                                                        <input type="hidden" name="delete_query" value="1">
                                                    </form>
                                                    <button type='button' class="action-btn"
                                                        style="background:#dc3545;color:#fff;border:none;padding:4px 10px;border-radius:6px;cursor:pointer;font-size:0.78rem;"
                                                        onclick="showConfirmModal({title:'Delete Responded Query', message:'Permanently delete responded query #<?= $row['query_id'] ?>?', confirmText:'DELETE', formId:'<?= $rDelFormId ?>'})">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr class="admin-tr">
                                            <td class="admin-td" colspan="7">No Responded Inquiries yet.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Email Modal -->
                        <div id="emailModal" class="modal">
                            <div class="modal-content">
                                <span class="close">×</span>
                                <h2>Send Email</h2>
                                <form id="emailForm" method="POST" action="send_email.php">
                                    <input type="hidden" name="query_id" id="email_query_id" value="">
                                    <div class="form-group">
                                        <label for="recipient_name">Recipient Name:</label>
                                        <input type="text" id="recipient_name" name="recipient_name" readonly>
                                    </div>
                                    <div class="form-group">
                                        <label for="recipient_email">Recipient Email:</label>
                                        <input type="email" id="recipient_email" name="recipient_email" readonly>
                                    </div>
                                    <div class="form-group">
                                        <label for="subject">Subject:</label>
                                        <input type="text" id="subject" name="subject" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="message">Message:</label>
                                        <textarea id="message" name="message" rows="6" required></textarea>
                                    </div>
                                    <button type="submit" class="submit-btn">Send Email</button>
                                </form>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <!-- JavaScript -->
        <script src="js/main.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
            crossorigin="anonymous"></script>
        <script src="js/custom.js"></script>

        <!-- Table Update Script -->
        <script>
            function escapeHtml(text) {
                const map = {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                };
                return text.replace(/[&<>"']/g, m => map[m]);
            }

            function updateTables() {
                fetch('fetch_queries.php')
                    .then(response => response.json())
                    .then(data => {
                        const queriesBody = document.getElementById('queries-body');
                        queriesBody.innerHTML = '';
                        if (data.queries.length > 0) {
                            data.queries.forEach(row => {
                                const hasEmail = row.user_email && row.user_email.includes('@');
                                const tr = document.createElement('tr');
                                tr.className = 'admin-tr';
                                tr.innerHTML = `
                                <td class="admin-td">${row.query_id}</td>
                                <td class="admin-td">
                                    <div style="font-weight:500;">${escapeHtml(row.user_name_display ?? 'Guest')}</div>
                                </td>
                                <td class="admin-td pq-user-cell">
                                    <div class="pq-user-email" style="margin-top:0; font-size:0.85rem; color:inherit;">${escapeHtml(row.user_email_display ?? 'N/A')}</div>
                                </td>
                                <td class="admin-td pq-query-text" title="${escapeHtml(row.query_text)}">
                                    ${escapeHtml(row.query_text)}
                                </td>
                                <td class="admin-td">
                                    <div style="font-size:0.82rem;">${row.submitted_at_formatted || row.submitted_at}</div>
                                    <span class="pq-sla-badge ${row.sla_class || 'pq-sla-green'}">
                                        ${row.is_urgent ? '<i class="fa-solid fa-triangle-exclamation" style="font-size:0.6rem;"></i> ' : ''}
                                        ${row.age_str || ''}
                                    </span>
                                </td>
                                <td class="admin-td">
                                    <div style="display: flex; gap: 5px;">
                                        <button type="button" class="action-btn email-btn" 
                                                data-id="${row.query_id}"
                                                data-name="${escapeHtml(row.user_name_display ?? 'Guest')}" 
                                                data-email="${hasEmail ? escapeHtml(row.user_email_display) : ''}" 
                                                data-text="${escapeHtml(row.query_text ?? '')}"
                                                onclick="openEmailModal(this.dataset.id, this.dataset.name, this.dataset.email, this.dataset.text)"
                                                ${!hasEmail ? 'disabled title="No email address available"' : ''}>
                                            <i class="fa-solid fa-envelope"></i>
                                        </button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Mark this query as responded?');">
                                            <input type="hidden" name="id" value="${row.query_id}">
                                            <input type="hidden" name="status" value="resolved">
                                            <input type="hidden" name="update_status" value="1">
                                            <input type="hidden" name="admin_response" value="Marked as responded via quick action">
                                            <button type="submit" class="action-btn" style="background:#10b981; color:#fff;" title="Mark as Responded">
                                                <i class="fa-solid fa-check"></i> Respond
                                            </button>
                                        </form>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this query?');">
                                            <input type="hidden" name="id" value="${row.query_id}">
                                            <input type="hidden" name="delete_query" value="1">
                                            <button type="submit" class="action-btn delete-btn">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            `;
                                queriesBody.appendChild(tr);
                            });
                        } else {
                            queriesBody.innerHTML = '<tr class="admin-tr"><td class="admin-td" colspan="6">No queries found.</td></tr>';
                        }

                        // Update Notification Count
                        const notYetCount = document.getElementById('not-yet-count');
                        if (data.not_yet_count > 0) {
                            notYetCount.textContent = data.not_yet_count;
                            notYetCount.style.display = 'inline';
                        } else {
                            notYetCount.style.display = 'none';
                        }
                    })
                    .catch(error => console.error('Error fetching queries:', error));
            }

            // Initial load and polling
            updateTables();
            setInterval(updateTables, 5000);
        </script>

        <!-- Table Filter Script -->
        <script>
            (function() {
                // ── Open Inquiries table filter ──
                const searchOpen = document.getElementById('searchOpen');
                const openBody = document.getElementById('queries-body');

                function filterOpenTable() {
                    const q = (searchOpen.value || '').toLowerCase();
                    if (!openBody) return;
                    openBody.querySelectorAll('tr.admin-tr').forEach(row => {
                        const text = row.textContent.toLowerCase();
                        const matchSearch = !q || text.includes(q);
                        row.style.display = matchSearch ? '' : 'none';
                    });
                }
                if (searchOpen) searchOpen.addEventListener('input', filterOpenTable);

                // ── Responded Inquiries table filter ──
                const searchResolved = document.getElementById('searchResolved');

                function filterResolvedTable() {
                    const q = (searchResolved.value || '').toLowerCase();
                    const tables = document.querySelectorAll('.admin-table-container');
                    const resolvedBody = tables[1]?.querySelector('tbody');
                    if (!resolvedBody) return;
                    resolvedBody.querySelectorAll('tr.admin-tr').forEach(row => {
                        const text = row.textContent.toLowerCase();
                        const matchSearch = !q || text.includes(q);
                        row.style.display = matchSearch ? '' : 'none';
                    });
                }
                if (searchResolved) searchResolved.addEventListener('input', filterResolvedTable);
            })();
        </script>

        <!-- Query Detail Modal -->
        <div id="queryDetailModal" class="modal">
            <div class="modal-content" style="max-width: 600px;">
                <span class="close" onclick="document.getElementById('queryDetailModal').style.display='none'">&times;</span>
                <h2>Query Details</h2>
                <div style="margin-top:20px; font-size: 0.95rem; line-height: 1.6;">
                    <div style="display:flex; justify-content:space-between; margin-bottom:10px;">
                        <div><strong>Query ID:</strong> <span id="qDetailId"></span></div>
                        <div><strong>Age:</strong> <span id="qDetailAge" style="font-weight:600;"></span></div>
                    </div>
                    <div style="margin-bottom:5px;"><strong>User:</strong> <span id="qDetailUser"></span></div>
                    <div style="margin-bottom:15px;"><strong>Email:</strong> <span id="qDetailEmail"></span></div>

                    <p><strong>Query Text:</strong> <br>
                    <div id="qDetailText" style="margin-top:5px; padding:15px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; font-style:italic; color:#1e293b;"></div>
                    </p>

                    <form method="POST" style="margin-top:20px; border-top:1px solid #e2e8f0; padding-top:20px;">
                        <input type="hidden" name="update_status" value="1">
                        <input type="hidden" name="id" id="qDetailFormId" value="">

                        <div style="margin-bottom:15px;">
                            <label for="qDetailResponse" style="font-weight:bold; display:block; margin-bottom:8px; color:#334155;">Admin Response / Notes (Recorded in DB)</label>
                            <textarea name="admin_response" id="qDetailResponse" rows="4" style="width:100%; border:1px solid #cbd5e1; border-radius:6px; padding:10px; font-family:inherit;" required placeholder="Enter notes about how this was resolved, or the actual response provided..."></textarea>
                        </div>

                        <div style="display:flex; justify-content:flex-end; align-items:center; flex-wrap:wrap; gap:10px;">
                            <input type="hidden" name="status" id="qDetailStatus" value="resolved">
                            <button type="submit" class="action-btn" style="background:#10b981; color:#fff; padding:8px 16px; font-size:0.9rem;" onclick="document.getElementById('qDetailStatus').value='resolved';">
                                <i class="fa-solid fa-check"></i> Mark as Responded
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
            function openQueryDetailModal(data) {
                document.getElementById('queryDetailModal').style.display = 'block';
                document.getElementById('qDetailId').textContent = data.id;
                document.getElementById('qDetailUser').textContent = data.user;
                document.getElementById('qDetailEmail').textContent = data.email || 'N/A';
                document.getElementById('qDetailAge').innerHTML = data.age;
                document.getElementById('qDetailText').textContent = data.text;
                document.getElementById('qDetailFormId').value = data.id;
                document.getElementById('qDetailResponse').value = data.response;
                if (data.status) {
                    document.getElementById('qDetailStatus').value = data.status === 'pending' ? 'resolved' : data.status;
                }
            }

            // Close modal clicking outside
            window.addEventListener('click', function(e) {
                const m = document.getElementById('queryDetailModal');
                if (e.target == m) m.style.display = 'none';
            });
        </script>

        <!-- Email Modal Script -->
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const modal = document.getElementById('emailModal');
                const span = document.getElementsByClassName('close')[0];

                span.onclick = () => modal.style.display = 'none';
                window.onclick = event => {
                    if (event.target == modal) modal.style.display = 'none';
                };

                document.getElementById('emailForm').addEventListener('submit', function(e) {
                    e.preventDefault();
                    const formData = new FormData(this);

                    fetch('send_email.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                alert('Email sent successfully!');
                                modal.style.display = 'none';
                                updateTables();
                            } else {
                                alert('Failed to send email: ' + data.message);
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('An error occurred while sending the email.');
                        });
                });
            });

            function openEmailModal(queryId, username, email, queryText) {
                const modal = document.getElementById('emailModal');
                document.getElementById('recipient_name').value = username;
                document.getElementById('recipient_email').value = email;
                document.getElementById('subject').value = 'Re: Your Query : ' + (queryText || '');
                document.getElementById('message').value = '';
                document.getElementById('email_query_id').value = queryId;

                modal.style.display = 'block';
            }
        </script>
        <script>
            // URL Parameter handling for Dashboard anomalies links
            document.addEventListener('DOMContentLoaded', () => {
                const params = new URLSearchParams(window.location.search);
                const statusFilter = params.get('status');
                if (statusFilter) {
                    // If switchTab is available (it should be loaded from custom.js), switch to the appropriate tab
                    setTimeout(() => {
                        if (statusFilter === 'pending' || statusFilter === 'open') {
                            if (typeof switchTab === 'function') switchTab('pending');
                        } else if (statusFilter === 'resolved' || statusFilter === 'closed' || statusFilter === 'responded') {
                            if (typeof switchTab === 'function') switchTab('resolved');
                        }
                    }, 200); // Slight delay to ensure DOM and tables are ready
                }
            });
        </script>
        <?php include 'includes/confirm_modal.php'; ?>
        <?php include 'includes/global_toasts.php'; ?>
</body>

</html>

<?php
$conn->close();
?>