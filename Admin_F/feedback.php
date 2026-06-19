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

// Fetch Admin Details
$admin_query = "SELECT admin_id, username, email FROM admins WHERE admin_id = ?";
if (!$conn->ping()) {
    die("Database connection is closed.");
}
$admin_stmt = $conn->prepare($admin_query);
if (!$admin_stmt) {
    die("Prepare failed: " . $conn->error);
}
$admin_stmt->bind_param('i', $_SESSION['admin_id']);
$admin_stmt->execute();
$admin_result = $admin_stmt->get_result();
$admin = $admin_result->fetch_assoc();

// Handle table operations
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['empty_table'])) {
        $conn->query("TRUNCATE TABLE feedback");
    }
    if (isset($_POST['delete_record'])) {
        $id = (int)$_POST['record_id'];
        $conn->query("DELETE FROM feedback WHERE feedback_id = $id");
    }
    if (isset($_POST['mark_reviewed'])) {
        $id = (int)$_POST['record_id'];
        $stmt = $conn->prepare("UPDATE feedback SET is_reviewed = 1 WHERE feedback_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
        }
        header('Location: feedback.php');
        exit;
    }
}

// ===== Stats =====
$total_result = $conn->query("SELECT COUNT(*) as total FROM feedback");
$total_feedback = $total_result ? $total_result->fetch_assoc()['total'] : 0;

$daily_result = $conn->query("SELECT COUNT(*) as daily FROM feedback WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
$daily_feedback = $daily_result ? $daily_result->fetch_assoc()['daily'] : 0;

$weekly_result = $conn->query("SELECT COUNT(*) as weekly FROM feedback WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)");
$weekly_feedback = $weekly_result ? $weekly_result->fetch_assoc()['weekly'] : 0;

$monthly_result = $conn->query("SELECT COUNT(*) as monthly FROM feedback WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$monthly_feedback = $monthly_result ? $monthly_result->fetch_assoc()['monthly'] : 0;

// Rating distribution
$rating_data_result = $conn->query("SELECT rating, COUNT(*) as count FROM feedback WHERE rating IS NOT NULL GROUP BY rating ORDER BY rating");
$rating_labels = [];
$rating_counts = [];
$rating_map = [];
if ($rating_data_result) {
    while ($row = $rating_data_result->fetch_assoc()) {
        $rating_labels[] = ucfirst($row['rating']);
        $rating_counts[] = (int)$row['count'];
        $rating_map[$row['rating']] = (int)$row['count'];
    }
}

// Rating score distribution (numeric 1-5) - Obsolete
$score_labels = [];
$score_counts = [];

// Average rating score - Obsolete
$avg_score = 0;

// Category distribution
$category_result = $conn->query("SELECT category, COUNT(*) as count FROM feedback WHERE category IS NOT NULL GROUP BY category ORDER BY count DESC");
$cat_labels = [];
$cat_counts = [];
if ($category_result) {
    while ($row = $category_result->fetch_assoc()) {
        $cat_labels[] = ucfirst($row['category']);
        $cat_counts[] = (int)$row['count'];
    }
}

// Review status
$reviewed_result = $conn->query("SELECT COUNT(*) as reviewed FROM feedback WHERE is_reviewed = 1");
$reviewed_count = $reviewed_result ? $reviewed_result->fetch_assoc()['reviewed'] : 0;
$unreviewed_count = $total_feedback - $reviewed_count;

// Unreviewed NEGATIVE feedback — needs attention
$neg_unreviewed_result = $conn->query("SELECT COUNT(*) as cnt FROM feedback WHERE is_reviewed = 0 AND rating = 'bad'");
$neg_unreviewed_count = $neg_unreviewed_result ? (int)$neg_unreviewed_result->fetch_assoc()['cnt'] : 0;

// Week-over-week comparison
$this_week_result = $conn->query("SELECT COUNT(*) as cnt FROM feedback WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$this_week_count = $this_week_result ? (int)$this_week_result->fetch_assoc()['cnt'] : 0;
$last_week_result = $conn->query("SELECT COUNT(*) as cnt FROM feedback WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
$last_week_count = $last_week_result ? (int)$last_week_result->fetch_assoc()['cnt'] : 0;
$week_change = $last_week_count > 0 ? round((($this_week_count - $last_week_count) / $last_week_count) * 100, 1) : 0;

// Daily feedback trend (last 14 days)
$feedback_over_time_result = $conn->query("SELECT DATE(created_at) as date, rating, COUNT(*) as count FROM feedback WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) GROUP BY DATE(created_at), rating ORDER BY date");
$feedback_over_time = [];
if ($feedback_over_time_result) {
    while ($row = $feedback_over_time_result->fetch_assoc()) {
        $feedback_over_time[] = $row;
    }
}

// Fetch reactions over time
$reactions_over_time_result = $conn->query("SELECT DATE(created_at) as date, reaction_type, COUNT(*) as count FROM message_reactions WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) GROUP BY DATE(created_at), reaction_type ORDER BY date");
$reactions_over_time = [];
if ($reactions_over_time_result) {
    while ($row = $reactions_over_time_result->fetch_assoc()) {
        $reactions_over_time[] = $row;
    }
}

// Sentiment breakdown
$excellent = $rating_map['excellent'] ?? 0;
$good = $rating_map['good'] ?? 0;
$bad = $rating_map['bad'] ?? 0;
$positive = $excellent + $good;
$negative = $bad;
$sentiment_pct = $total_feedback > 0 ? round(($positive / $total_feedback) * 100, 1) : 0;
$negative_pct = $total_feedback > 0 ? round(($negative / $total_feedback) * 100, 1) : 0;

// Message reactions combined metrics
$thumbs_up_reactions = 0;
$thumbs_down_reactions = 0;
$total_reactions = 0;
$reactions_today = 0;

$reaction_res = $conn->query("SELECT reaction_type, COUNT(*) as count FROM message_reactions GROUP BY reaction_type");
if ($reaction_res) {
    while ($rc = $reaction_res->fetch_assoc()) {
        $count = (int)$rc['count'];
        $total_reactions += $count;
        if (in_array($rc['reaction_type'], ['thumbs_up', 'helpful', 'accurate'])) {
            $thumbs_up_reactions += $count;
        } else {
            $thumbs_down_reactions += $count;
        }
    }
}

$react_today_res = $conn->query("SELECT COUNT(*) as count FROM message_reactions WHERE DATE(created_at) = CURDATE()");
if ($react_today_res) {
    $reactions_today = (int)$react_today_res->fetch_assoc()['count'];
}

$typed_feedback_today_res = $conn->query("SELECT COUNT(*) as cnt FROM feedback WHERE DATE(created_at) = CURDATE()");
$typed_feedback_today = $typed_feedback_today_res ? (int)$typed_feedback_today_res->fetch_assoc()['cnt'] : 0;

$hybrid_pos = $positive + $thumbs_up_reactions;
$hybrid_neg = $negative + $thumbs_down_reactions;
$hybrid_tot = $total_feedback + $total_reactions;

$thumbs_up_ratio = $hybrid_tot > 0 ? round(($hybrid_pos / $hybrid_tot) * 100, 1) : 0;
$hybrid_negative_pct = $hybrid_tot > 0 ? round(($hybrid_neg / $hybrid_tot) * 100, 1) : 0;

$feedback_today = $typed_feedback_today + $reactions_today;

// ===== Decision-Support Insights =====
$top_complaint_result = $conn->query("SELECT category, COUNT(*) as cnt FROM feedback WHERE rating='bad' AND category IS NOT NULL GROUP BY category ORDER BY cnt DESC LIMIT 1");
$top_complaint = $top_complaint_result ? $top_complaint_result->fetch_assoc() : null;

$suggestions_list = [];

$oldest_unreviewed = $conn->query("SELECT DATEDIFF(NOW(), MIN(created_at)) as age FROM feedback WHERE is_reviewed=0 AND rating='bad'");
$backlog_age_days = $oldest_unreviewed ? (int)($oldest_unreviewed->fetch_assoc()['age'] ?? 0) : 0;

$recent_neg_react_res = $conn->query("SELECT COUNT(*) as cnt FROM message_reactions WHERE reaction_type NOT IN('thumbs_up','helpful','accurate') AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
$recent_pos_react_res = $conn->query("SELECT COUNT(*) as cnt FROM message_reactions WHERE reaction_type IN('thumbs_up','helpful','accurate') AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)");

$recent_neg_react = $recent_neg_react_res ? (int)$recent_neg_react_res->fetch_assoc()['cnt'] : 0;
$recent_pos_react = $recent_pos_react_res ? (int)$recent_pos_react_res->fetch_assoc()['cnt'] : 0;

$recent_neg_typed = $conn->query("SELECT COUNT(*) as cnt FROM feedback WHERE rating='bad' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
$recent_pos_typed = $conn->query("SELECT COUNT(*) as cnt FROM feedback WHERE rating IN('good','excellent') AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)");

$recent_neg_count = ($recent_neg_typed ? (int)$recent_neg_typed->fetch_assoc()['cnt'] : 0) + $recent_neg_react;
$recent_pos_count = ($recent_pos_typed ? (int)$recent_pos_typed->fetch_assoc()['cnt'] : 0) + $recent_pos_react;

$this_week_neg_res = $conn->query("
    SELECT (
        (SELECT COUNT(*) FROM feedback WHERE rating='bad' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) +
        (SELECT COUNT(*) FROM message_reactions WHERE reaction_type NOT IN('thumbs_up','helpful','accurate') AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))
    ) as cnt
");
$this_week_neg = $this_week_neg_res ? (int)$this_week_neg_res->fetch_assoc()['cnt'] : 0;

$last_week_neg_res = $conn->query("
    SELECT (
        (SELECT COUNT(*) FROM feedback WHERE rating='bad' AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)) +
        (SELECT COUNT(*) FROM message_reactions WHERE reaction_type NOT IN('thumbs_up','helpful','accurate') AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY))
    ) as cnt
");
$last_week_neg = $last_week_neg_res ? (int)$last_week_neg_res->fetch_assoc()['cnt'] : 0;

$week_change_neg = $last_week_neg > 0 ? round((($this_week_neg - $last_week_neg) / $last_week_neg) * 100, 1) : 0;

// Process feedback_over_time + reactions_over_time into likes/dislikes per day
$fb_likes = [];
$fb_dislikes = [];

foreach ($feedback_over_time as $fb) {
    $d = $fb['date'];
    if (!isset($fb_likes[$d])) { $fb_likes[$d] = 0; $fb_dislikes[$d] = 0; }
    if (in_array($fb['rating'] ?? '', ['excellent', 'good'])) {
        $fb_likes[$d] += (int)$fb['count'];
    } else {
        $fb_dislikes[$d] += (int)$fb['count'];
    }
}

foreach ($reactions_over_time as $rx) {
    $d = $rx['date'];
    if (!isset($fb_likes[$d])) { $fb_likes[$d] = 0; $fb_dislikes[$d] = 0; }
    if (in_array($rx['reaction_type'], ['thumbs_up', 'helpful', 'accurate'])) {
        $fb_likes[$d] += (int)$rx['count'];
    } else {
        $fb_dislikes[$d] += (int)$rx['count'];
    }
}

ksort($fb_likes);
ksort($fb_dislikes);
$fb_dates = array_keys($fb_likes);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Feedback — Admin Panel</title>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="shortcut icon" href="images/mmu_logo_- no bg.png" type="image/x-icon">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700%7CJosefin+Sans:600,700" rel="stylesheet">
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
        [class*="fa-"], .fa, .fas, .far, .fab, .fa-solid, .fa-regular, .fa-brands {
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

        .fb-page { padding: 30px; }

        .fb-stats-row { display: flex; gap: 14px; flex-wrap: wrap; margin-bottom: 20px; }
        .fb-stat-card { flex: 1; min-width: 130px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
        .fb-stat-value { font-size: 1.5rem; font-weight: 700; color: #1e293b; }
        .fb-stat-label { font-size: 0.78rem; color: #64748b; margin-top: 4px; }
        .fb-stat-trend { font-size: 0.72rem; margin-top: 4px; font-weight: 600; }

        .fb-sentiment-bar { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
        .fb-sentiment-bar h4 { color: #1e293b; font-size: 0.95rem; margin-bottom: 12px; font-weight: 600; }
        .fb-progress-wrap { display: flex; height: 28px; border-radius: 14px; overflow: hidden; background: #f1f5f9; }
        .fb-progress-positive { background: linear-gradient(135deg, #10b981, #34d399); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 0.72rem; font-weight: 600; }
        .fb-progress-negative { background: linear-gradient(135deg, #ef4444, #f87171); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 0.72rem; font-weight: 600; }
        .fb-sentiment-legend { display: flex; gap: 20px; margin-top: 8px; font-size: 0.78rem; color: #64748b; }
        .fb-sentiment-legend span::before { content: ''; display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 5px; }
        .fb-legend-positive::before { background: #10b981 !important; }
        .fb-legend-negative::before { background: #ef4444 !important; }

        .fb-chart-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 20px; }
        @media (max-width: 1200px) { .fb-chart-row { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 768px) { .fb-chart-row { grid-template-columns: 1fr; } }
        .fb-chart-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px; height: 300px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
        .fb-chart-card h4 { color: #1e293b; font-size: 0.95rem; margin-bottom: 12px; font-weight: 600; }

        .fb-filter-bar { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; align-items: center; }
        .fb-filter-bar input, .fb-filter-bar select { padding: 7px 12px; border: 1px solid #e2e8f0; border-radius: 6px; background: white; color: #1e293b; font-size: 0.82rem; min-width: 120px; }
        .fb-filter-bar input::placeholder { color: #94a3b8; }
        .fb-filter-bar select option { background: #ffffff; }

        .fb-table-wrap { background: #1e293b; border: 1px solid #334155; border-radius: 12px; overflow: hidden; }
        .fb-table { width: 100%; border-collapse: collapse; }
        .fb-table th { background: #0f172a; color: #94a3b8; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px; padding: 12px 14px; border-bottom: 1px solid #334155; white-space: nowrap; }
        .fb-table td { padding: 10px 14px; color: #cbd5e1; font-size: 0.82rem; border-bottom: 1px solid rgba(51,65,85,0.5); vertical-align: top; }
        .fb-table tr:hover td { background: rgba(59,130,246,0.05); }

        .fb-badge { padding: 3px 10px; border-radius: 12px; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; }
        .fb-badge-excellent { background: rgba(16,185,129,0.15); color: #059669; }
        .fb-badge-good { background: rgba(59,130,246,0.15); color: #3b82f6; }
        .fb-badge-bad { background: rgba(239,68,68,0.15); color: #dc2626; }

        .fb-reviewed-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 4px; vertical-align: middle; }
        .fb-reviewed { background: #10b981; }
        .fb-unreviewed { background: #f59e0b; }

        .fb-tabs { display: flex; gap: 4px; flex-wrap: wrap; margin-bottom: 16px; }
        .fb-tab { padding: 6px 14px; border: 1px solid #d1d5db; border-radius: 8px; background: #f8fafc; color: #6b7280; font-size: 0.78rem; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .fb-tab:hover { border-color: #10b981; color: #10b981; }
        .fb-tab.active { background: linear-gradient(135deg, #002147 0%, #05356b 100%); color: #fff; border-color: transparent; }

        .fb-panel { display: none; animation: predFadeIn 0.3s ease; }
        .fb-panel.active { display: block; }

        .fb-kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; margin-bottom: 16px; }
        .fb-kpi { background: linear-gradient(135deg, #002147 0%, #05356b 100%); border: 1px solid #dcfce7; border-radius: 10px; padding: 24px; height: 140px; display: flex; justify-content: center; align-items: center; text-align: center; flex-direction: column; }
        .fb-kpi-value { font-size: 2rem; font-weight: 800; color: #ffffff; }
        .fb-kpi-label { font-size: 0.85rem; color: #e4e4e5ff; margin-top: 8px; font-weight: 500; }

        .fb-period-toggle { display: inline-flex; gap: 2px; background: #f1f5f9; border-radius: 6px; padding: 2px; }
        .fb-period-toggle button { padding: 4px 10px; border: none; border-radius: 5px; background: transparent; font-size: 0.72rem; font-weight: 600; color: #6b7280; cursor: pointer; transition: all 0.2s; }
        .fb-period-toggle button.active { background: #fff; color: #10b981; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }

        .fb-dist-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media (max-width: 768px) { .fb-dist-grid { grid-template-columns: 1fr; } }
        .fb-dist-chart { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; }
        .fb-dist-chart h5 { font-size: 0.85rem; font-weight: 700; color: #1e293b; margin-bottom: 12px; }

        .fb-actions { display: flex; gap: 8px; margin-bottom: 16px; }
        .fb-actions button { padding: 6px 12px; border: 1px solid #e2e8f0; border-radius: 6px; background: #fff; color: #475569; font-size: 0.82rem; min-width: 120px; box-shadow: 0 1px 2px rgba(0,0,0,0.04); }

        .fb-comment-cell { max-width: 300px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: pointer; }
        .fb-comment-cell:hover { white-space: normal; word-break: break-word; }

        @keyframes predFadeIn {
            from { opacity: 0; transform: translateY(5px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 768px) {
            .fb-kpi-row { grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); }
            .fb-chart-row { grid-template-columns: 1fr; }
        }

        form[method="get"] { display: block !important; visibility: visible !important; }
    </style>
</head>

<body>
    <!--== MAIN CONTAINER ==-->
    <?php include 'includes/topbar.php'; ?>
    </div>
    </div>

    <!--== BODY CONTAINER ==-->
    <div class="container-fluid sb2">
        <div class="row">
            <?php include 'includes/sidebar.php'; ?>

            <!--== BODY INNER CONTAINER ==-->
            <div class="sb2-2">
                <div class="sb2-2-3">
                    <div class="row">
                        <div class="col-md-12">
                            <div class="box-inn-sp">
                                <div class="fb-page">

                                    <!-- ===== HEADER ===== -->
                                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
                                        <div>
                                            <h2 style="color:#104a95;font-size:1.4rem;margin:0;font-weight:700;">
                                                <i class="fa-solid fa-message" style="color:#18569d;margin-right:8px;"></i>User Feedback
                                            </h2>
                                        </div>
                                        <div style="color:#64748b;font-size:0.78rem;">
                                            <i class="fa-solid fa-clock"></i> Last updated: <?= date('M j, g:ia') ?>
                                        </div>
                                    </div>

                                    <!-- ===== ALERT BANNER: Needs Review ===== -->
                                    <?php if ($neg_unreviewed_count > 0): ?>
                                        <div style="background:linear-gradient(135deg,#fef2f2,#fee2e2);border:1px solid #fca5a5;border-left:4px solid #ef4444;border-radius:12px;padding:14px 18px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                                            <div style="display:flex;align-items:center;gap:10px;">
                                                <i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;font-size:1.2rem;"></i>
                                                <div>
                                                    <div style="font-weight:700;color:#991b1b;font-size:0.92rem;"><?= $neg_unreviewed_count ?> Negative Feedback Item<?= $neg_unreviewed_count > 1 ? 's' : '' ?> Awaiting Review</div>
                                                    <div style="font-size:0.78rem;color:#b91c1c;margin-top:2px;">These are negative ratings that haven't been actioned yet. Review them below to identify chatbot failures.</div>
                                                </div>
                                            </div>
                                            <a href="feedback.php?rating=bad&reviewed=0" class="btn btn-sm" style="background:#dc2626;color:#fff;border:none;border-radius:8px;font-size:0.8rem;font-weight:600;padding:7px 16px;text-decoration:none;white-space:nowrap;">
                                                <i class="fa-solid fa-eye" style="margin-right:5px;"></i>Review Now
                                            </a>
                                        </div>
                                    <?php endif; ?>

                                  <!-- ===== SENTIMENT OVERVIEW & INSIGHTS ===== -->
                                    <div class="chart-card" style="margin-bottom:32px;">
                                        <h4 style="margin:0 0 20px;font-size:1rem;font-weight:700;color:#0f172a;">
                                            <i class="fa-solid fa-face-smile" style="color:#1a6ef7;margin-right:8px;"></i>Sentiment Overview &amp; Insights
                                        </h4>

                                        <div style="padding:20px 22px;">
                                            <!-- Sentiment Progress Bar -->
                                            <div style="margin-bottom:20px;">
                                                <div class="fb-progress-wrap">
                                                    <?php if ($hybrid_pos > 0): ?>
                                                        <div class="fb-progress-positive" style="width:<?= $hybrid_tot > 0 ? round(($hybrid_pos / $hybrid_tot) * 100) : 0 ?>%">
                                                            <?= $thumbs_up_ratio ?>% positive
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($hybrid_neg > 0): ?>
                                                        <div class="fb-progress-negative" style="width:<?= $hybrid_tot > 0 ? round(($hybrid_neg / $hybrid_tot) * 100) : 0 ?>%">
                                                            <?= $hybrid_negative_pct ?>% negative
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="fb-sentiment-legend">
                                                    <span class="fb-legend-positive">Positive (<?= $hybrid_pos ?>)</span>
                                                    <span class="fb-legend-negative">Negative (<?= $hybrid_neg ?>)</span>
                                                </div>
                                            </div>

                                            <!-- Decision-Support Insights -->
                                            <div style="background:linear-gradient(135deg,#f0f9ff,#e0f2fe);border:1px solid #bae6fd;border-radius:14px;padding:20px;">
                                                <h5 style="margin:0 0 16px;font-size:0.95rem;font-weight:700;color:#0c4a6e;">
                                                    <i class="fa-solid fa-lightbulb" style="color:#f59e0b;margin-right:8px;"></i>Insights &amp; Recommended Actions
                                                </h5>
                                                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:12px;">
                                                    <?php if ($top_complaint): ?>
                                                        <div style="background:#fff;border-radius:10px;padding:16px;border:1px solid #e2e8f0;">
                                                            <div style="font-size:0.72rem;color:#64748b;text-transform:uppercase;font-weight:600;letter-spacing:0.03em;margin-bottom:6px;">
                                                                <i class="fa-solid fa-triangle-exclamation" style="color:#ef4444;margin-right:4px;"></i>Top Complaint Area
                                                            </div>
                                                            <div style="font-size:1.1rem;font-weight:700;color:#dc2626;margin-bottom:4px;"><?= ucfirst($top_complaint['category']) ?></div>
                                                            <div style="font-size:0.78rem;color:#64748b;"><?= $top_complaint['cnt'] ?> negative report<?= $top_complaint['cnt'] > 1 ? 's' : '' ?> — Review &amp; improve bot responses in this area</div>
                                                        </div>
                                                    <?php endif; ?>

                                                    <div style="background:#fff;border-radius:10px;padding:16px;border:1px solid #e2e8f0;">
                                                        <div style="font-size:0.72rem;color:#64748b;text-transform:uppercase;font-weight:600;letter-spacing:0.03em;margin-bottom:6px;">
                                                            <i class="fa-solid fa-clock" style="color:#f59e0b;margin-right:4px;"></i>Review Backlog
                                                        </div>
                                                        <div style="font-size:1.1rem;font-weight:700;color:<?= $backlog_age_days > 3 ? '#dc2626' : ($backlog_age_days > 1 ? '#f59e0b' : '#10b981') ?>;margin-bottom:4px;">
                                                            <?= $neg_unreviewed_count ?> item<?= $neg_unreviewed_count != 1 ? 's' : '' ?>
                                                            <?php if ($backlog_age_days > 0): ?>
                                                                <span style="font-size:0.8rem;font-weight:500;">· <?= $backlog_age_days ?>d old</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div style="font-size:0.78rem;color:#64748b;"><?= $backlog_age_days > 3 ? '⚠ Stale backlog — action overdue' : ($backlog_age_days > 1 ? 'Getting stale — review soon' : 'On track') ?></div>
                                                    </div>

                                                    <div style="background:#fff;border-radius:10px;padding:16px;border:1px solid #e2e8f0;">
                                                        <div style="font-size:0.72rem;color:#64748b;text-transform:uppercase;font-weight:600;letter-spacing:0.03em;margin-bottom:6px;">
                                                            <i class="fa-solid fa-chart-line" style="color:#3b82f6;margin-right:4px;"></i>Week-over-Week (Negatives)
                                                        </div>
                                                        <div style="font-size:1.1rem;font-weight:700;color:<?= $week_change_neg > 0 ? '#dc2626' : ($week_change_neg < 0 ? '#10b981' : '#64748b') ?>;margin-bottom:4px;">
                                                            <?= $week_change_neg > 0 ? '+' : '' ?><?= $week_change_neg ?>%
                                                            <i class="fa-solid fa-arrow-<?= $week_change_neg >= 0 ? 'up' : 'down' ?>" style="font-size:0.8rem;margin-left:4px;"></i>
                                                        </div>
                                                        <div style="font-size:0.78rem;color:#64748b;"><?= $this_week_neg ?> this week vs <?= $last_week_neg ?> last week</div>
                                                    </div>

                                                    <div style="background:#fff;border-radius:10px;padding:16px;border:1px solid #e2e8f0;">
                                                        <div style="font-size:0.72rem;color:#64748b;text-transform:uppercase;font-weight:600;letter-spacing:0.03em;margin-bottom:6px;">
                                                            <i class="fa-solid fa-heart-pulse" style="color:#8b5cf6;margin-right:4px;"></i>Last 24h Pulse
                                                        </div>
                                                        <div style="display:flex;align-items:center;gap:16px;margin:8px 0;">
                                                            <span style="font-size:1rem;font-weight:700;color:#10b981;">
                                                                <i class="fa-solid fa-thumbs-up" style="margin-right:4px;"></i><?= $recent_pos_count ?>
                                                            </span>
                                                            <span style="font-size:1rem;font-weight:700;color:#dc2626;">
                                                                <i class="fa-solid fa-thumbs-down" style="margin-right:4px;"></i><?= $recent_neg_count ?>
                                                            </span>
                                                        </div>
                                                        <div style="font-size:0.78rem;color:#64748b;"><?= $recent_neg_count > $recent_pos_count ? '⚠ More negatives than positives today' : (($recent_neg_count + $recent_pos_count) > 0 ? 'Sentiment looks healthy' : 'No feedback yet today') ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- ===== FILTERS ===== -->
                                    <form method="get" class="fb-filter-bar" id="feedback-filter-form">
                                        <input type="text" name="id" placeholder="ID" value="<?= htmlspecialchars($_GET['id'] ?? '') ?>">
                                        <input type="text" name="created_at" placeholder="Date (YYYY-MM-DD)" value="<?= htmlspecialchars($_GET['created_at'] ?? '') ?>">
                                        <input type="text" name="comment" placeholder="Search comment..." value="<?= htmlspecialchars($_GET['comment'] ?? '') ?>">
                                        <select name="rating">
                                            <option value="">All Ratings</option>
                                            <option value="excellent" <?= ($_GET['rating'] ?? '') === 'excellent' ? 'selected' : '' ?>>Excellent</option>
                                            <option value="good" <?= ($_GET['rating'] ?? '') === 'good' ? 'selected' : '' ?>>Good</option>
                                            <option value="bad" <?= ($_GET['rating'] ?? '') === 'bad' ? 'selected' : '' ?>>Bad</option>
                                        </select>
                                        <select name="category">
                                            <option value="">All Categories</option>
                                            <option value="accuracy" <?= ($_GET['category'] ?? '') === 'accuracy' ? 'selected' : '' ?>>Accuracy</option>
                                            <option value="speed" <?= ($_GET['category'] ?? '') === 'speed' ? 'selected' : '' ?>>Speed</option>
                                            <option value="helpfulness" <?= ($_GET['category'] ?? '') === 'helpfulness' ? 'selected' : '' ?>>Helpfulness</option>
                                            <option value="tone" <?= ($_GET['category'] ?? '') === 'tone' ? 'selected' : '' ?>>Tone</option>
                                            <option value="relevance" <?= ($_GET['category'] ?? '') === 'relevance' ? 'selected' : '' ?>>Relevance</option>
                                            <option value="completeness" <?= ($_GET['category'] ?? '') === 'completeness' ? 'selected' : '' ?>>Completeness</option>
                                        </select>
                                        <select name="reviewed">
                                            <option value="">All Status</option>
                                            <option value="1" <?= ($_GET['reviewed'] ?? '') === '1' ? 'selected' : '' ?>>Reviewed</option>
                                            <option value="0" <?= ($_GET['reviewed'] ?? '') === '0' ? 'selected' : '' ?>>Unreviewed</option>
                                        </select>
                                        <a href="feedback.php" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-times"></i> Reset</a>
                                        <?php if ($neg_unreviewed_count > 0): ?>
                                            <a href="feedback.php?rating=bad&reviewed=0" class="btn btn-sm" style="background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;font-weight:600;font-size:0.8rem;">
                                                <i class="fa-solid fa-flag" style="margin-right:4px;"></i>Pending (<?= $neg_unreviewed_count ?>)
                                            </a>
                                        <?php endif; ?>
                                    </form>

                                    <!-- ===== ACTIONS ===== -->
                                    <div class="fb-actions" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                                        <div style="display:flex;gap:8px;align-items:center;">
                                            <button class="btn btn-outline-light btn-sm" onclick="exportTable('feedback')">
                                                <i class="fa-solid fa-download"></i> Export
                                            </button>
                                            <form id="emptyFeedbackForm" method="post" style="display:none;">
                                                <input type="hidden" name="table_name" value="feedback">
                                                <input type="hidden" name="empty_table" value="1">
                                            </form>
                                            <button class="btn btn-outline-danger btn-sm"
                                                onclick="showConfirmModal({title:'Empty All Feedback', message:'This will permanently delete ALL feedback records. This cannot be undone.', confirmText:'DELETE', formId:'emptyFeedbackForm'})">
                                                <i class="fa-solid fa-trash"></i> Empty Table
                                            </button>
                                        </div>
                                        <div style="display:flex;align-items:center;gap:10px;">
                                            <div style="font-size:0.75rem;color:#64748b;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:6px 12px;">
                                                <i class="fa-solid fa-circle-info" style="color:#6366f1;margin-right:5px;"></i>
                                                <strong>To review:</strong> Click <i class="fa-solid fa-check" style="color:#10b981;"></i> on any unreviewed row below to mark it as actioned.
                                            </div>
                                            <?php if ($neg_unreviewed_count > 0): ?>
                                                <a href="feedback.php?rating=bad&reviewed=0" style="font-size:0.78rem;color:#dc2626;font-weight:600;text-decoration:none;">
                                                    <i class="fa-solid fa-flag"></i> <?= $neg_unreviewed_count ?> pending
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- ===== TABLE ===== -->
                                    <?php
                                    $conditions = [];
                                    $params = [];
                                    $types = '';

                                    if (!empty($_GET['id'])) { $conditions[] = 'feedback_id = ?'; $params[] = $_GET['id']; $types .= 'i'; }
                                    if (!empty($_GET['created_at'])) { $conditions[] = 'DATE(created_at) = ?'; $params[] = $_GET['created_at']; $types .= 's'; }
                                    if (!empty($_GET['comment'])) { $conditions[] = 'comment LIKE ?'; $params[] = '%' . $_GET['comment'] . '%'; $types .= 's'; }
                                    if (!empty($_GET['rating'])) { $conditions[] = 'rating = ?'; $params[] = $_GET['rating']; $types .= 's'; }
                                    if (!empty($_GET['category'])) { $conditions[] = 'category = ?'; $params[] = $_GET['category']; $types .= 's'; }
                                    if (isset($_GET['reviewed']) && $_GET['reviewed'] !== '') { $conditions[] = 'is_reviewed = ?'; $params[] = (int)$_GET['reviewed']; $types .= 'i'; }

                                    $where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';
                                    $sql = "SELECT * FROM feedback $where ORDER BY created_at DESC LIMIT 100";
                                    $stmt = $conn->prepare($sql);
                                    if ($params) { $stmt->bind_param($types, ...$params); }
                                    $stmt->execute();
                                    $feedback_rows = $stmt->get_result();

                                    $all_feedbacks = [];
                                    if ($feedback_rows && $feedback_rows->num_rows > 0) {
                                        while ($r = $feedback_rows->fetch_assoc()) { $all_feedbacks[] = $r; }
                                    }
                                    ?>

                                    <!-- INCOMING FEEDBACK TABLE -->
                                    <div class="admin-table-container">
                                        <h2 class="admin-h2" style="margin-bottom:14px;">Incoming Feedback</h2>
                                        <table class="admin-table" id="feedback-table-incoming">
                                            <thead>
                                                <tr>
                                                    <th class="admin-th">ID</th>
                                                    <th class="admin-th">Session</th>
                                                    <th class="admin-th">Rating</th>
                                                    <th class="admin-th">Category</th>
                                                    <th class="admin-th">Comment</th>
                                                    <th class="admin-th">Date</th>
                                                    <th class="admin-th">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $has_incoming = false;
                                                foreach ($all_feedbacks as $row):
                                                    if ($row['is_reviewed']) continue;
                                                    $has_incoming = true;
                                                    $rating = $row['rating'] ?? '';
                                                    $badgeClass = 'fb-badge-good';
                                                    if ($rating === 'excellent') $badgeClass = 'fb-badge-excellent';
                                                    elseif ($rating === 'bad') $badgeClass = 'fb-badge-bad';
                                                ?>
                                                    <tr class="admin-tr">
                                                        <td class="admin-td"><?= $row['feedback_id'] ?></td>
                                                        <td class="admin-td"><?= htmlspecialchars($row['session_id'] ?? '—') ?></td>
                                                        <td class="admin-td">
                                                            <span class="fb-badge <?= $badgeClass ?>"><?= htmlspecialchars($rating ?: '—') ?></span>
                                                        </td>
                                                        <td class="admin-td">
                                                            <?= !empty($row['category']) ? '<span style="color:#94a3b8;font-size:0.78rem;">' . ucfirst($row['category']) . '</span>' : '—' ?>
                                                        </td>
                                                        <td class="admin-td fb-comment-cell" title="<?= htmlspecialchars($row['comment'] ?? '') ?>">
                                                            <?= htmlspecialchars($row['comment'] ?? '') ?: '—' ?>
                                                        </td>
                                                        <td class="admin-td" style="white-space:nowrap;font-size:0.75rem;">
                                                            <?= date('M j, g:ia', strtotime($row['created_at'])) ?>
                                                        </td>
                                                        <td class="admin-td" style="display:flex;gap:5px;">
                                                            <form method='post' style='display:inline;'>
                                                                <input type='hidden' name='table_name' value='feedback'>
                                                                <input type='hidden' name='mark_reviewed' value='1'>
                                                                <input type='hidden' name='record_id' value='<?= $row['feedback_id'] ?>'>
                                                                <button type='submit' class='btn btn-outline-success btn-sm' style='font-size:0.7rem;padding:2px 8px;' title='Mark as reviewed'>
                                                                    <i class="fa-solid fa-check"></i>
                                                                </button>
                                                            </form>
                                                            <?php $fbDeleteFormId = 'fbDelIn' . $row['feedback_id']; ?>
                                                            <form id='<?= $fbDeleteFormId ?>' method='post' style='display:none;'>
                                                                <input type='hidden' name='table_name' value='feedback'>
                                                                <input type='hidden' name='record_id' value='<?= $row['feedback_id'] ?>'>
                                                                <input type='hidden' name='delete_record' value='1'>
                                                            </form>
                                                            <button type='button' class='btn btn-outline-danger btn-sm' style='font-size:0.7rem;padding:2px 8px;'
                                                                onclick="showConfirmModal({title:'Delete Feedback', message:'Permanently delete feedback #<?= $row['feedback_id'] ?>?', confirmText:'DELETE', formId:'<?= $fbDeleteFormId ?>'})">
                                                                <i class="fa-solid fa-trash"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <?php if (!$has_incoming): ?>
                                                    <tr>
                                                        <td colspan="7" class="admin-td" style="text-align:center;padding:20px;color:#64748b;">No incoming feedback found.</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <!-- REVIEWED FEEDBACK TABLE -->
                                    <div class="admin-table-container">
                                        <h2 class="admin-h2" style="margin-bottom:14px;">Reviewed Feedback</h2>
                                        <table class="admin-table" id="feedback-table-reviewed">
                                            <thead>
                                                <tr>
                                                    <th class="admin-th">ID</th>
                                                    <th class="admin-th">Session</th>
                                                    <th class="admin-th">Rating</th>
                                                    <th class="admin-th">Category</th>
                                                    <th class="admin-th">Comment</th>
                                                    <th class="admin-th">Date</th>
                                                    <th class="admin-th">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $has_reviewed = false;
                                                foreach ($all_feedbacks as $row):
                                                    if (!$row['is_reviewed']) continue;
                                                    $has_reviewed = true;
                                                    $rating = $row['rating'] ?? '';
                                                    $badgeClass = 'fb-badge-good';
                                                    if ($rating === 'excellent') $badgeClass = 'fb-badge-excellent';
                                                    elseif ($rating === 'bad') $badgeClass = 'fb-badge-bad';
                                                ?>
                                                    <tr class="admin-tr">
                                                        <td class="admin-td"><?= $row['feedback_id'] ?></td>
                                                        <td class="admin-td"><?= htmlspecialchars($row['session_id'] ?? '—') ?></td>
                                                        <td class="admin-td">
                                                            <span class="fb-badge <?= $badgeClass ?>"><?= htmlspecialchars($rating ?: '—') ?></span>
                                                        </td>
                                                        <td class="admin-td">
                                                            <?= !empty($row['category']) ? '<span style="color:#94a3b8;font-size:0.78rem;">' . ucfirst($row['category']) . '</span>' : '—' ?>
                                                        </td>
                                                        <td class="admin-td fb-comment-cell" title="<?= htmlspecialchars($row['comment'] ?? '') ?>">
                                                            <?= htmlspecialchars($row['comment'] ?? '') ?: '—' ?>
                                                        </td>
                                                        <td class="admin-td" style="white-space:nowrap;font-size:0.75rem;">
                                                            <?= date('M j, g:ia', strtotime($row['created_at'])) ?>
                                                        </td>
                                                        <td class="admin-td" style="display:flex;gap:5px;">
                                                            <?php $fbDeleteFormId = 'fbDelRev' . $row['feedback_id']; ?>
                                                            <form id='<?= $fbDeleteFormId ?>' method='post' style='display:none;'>
                                                                <input type='hidden' name='table_name' value='feedback'>
                                                                <input type='hidden' name='record_id' value='<?= $row['feedback_id'] ?>'>
                                                                <input type='hidden' name='delete_record' value='1'>
                                                            </form>
                                                            <button type='button' class='btn btn-outline-danger btn-sm' style='font-size:0.7rem;padding:2px 8px;'
                                                                onclick="showConfirmModal({title:'Delete Feedback', message:'Permanently delete feedback #<?= $row['feedback_id'] ?>?', confirmText:'DELETE', formId:'<?= $fbDeleteFormId ?>'})">
                                                                <i class="fa-solid fa-trash"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <?php if (!$has_reviewed): ?>
                                                    <tr>
                                                        <td colspan="7" class="admin-td" style="text-align:center;padding:20px;color:#64748b;">No reviewed feedback found.</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // ── Notification count ──
        function updateNotificationCount() {
            fetch('fetch_queries.php')
                .then(r => r.json())
                .then(data => {
                    const el = document.getElementById('not-yet-count');
                    if (el) {
                        el.textContent = data.not_yet_count;
                        el.style.display = data.not_yet_count > 0 ? 'inline' : 'none';
                    }
                })
                .catch(e => console.error('Notification error:', e));
        }
        updateNotificationCount();
        setInterval(updateNotificationCount, 60000);
    </script>

    <script src="js/main.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
        crossorigin="anonymous"></script>
    <script src="js/custom.js"></script>

    <script>
        // ══════════════════════════════════════════════════════════
        // CHART COLOUR PALETTE & HELPERS
        // ══════════════════════════════════════════════════════════
        const FB = {
            grid:    'rgba(99,102,241,0.07)',
            tick:    '#64748b',
            palette: ['#6366f1','#8b5cf6','#ec4899','#06b6d4','#10b981','#f59e0b','#f43e5e'],
            rating:  { 'Excellent': '#10b981', 'Good': '#3b82f6', 'Bad': '#ef4444' },
            star:    ['#ef4444','#f59e0b','#eab308','#3b82f6','#10b981'],
        };

        const colors = {
            green:  '#10b981',
            pink:   '#ec4899',
            blue:   '#3b82f6',
            purple: '#a855f7',
        };

        const fbTip = {
            backgroundColor: 'rgba(30,41,59,0.95)',
            titleColor:      '#f8fafc',
            bodyColor:       '#cbd5e1',
            padding:         10,
            cornerRadius:    8,
        };

        const defaultOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        color: '#6b7280',
                        font: { family: 'Inter', size: 12, weight: '600' },
                        padding: 16,
                        usePointStyle: true,
                        pointStyle: 'circle',
                    },
                },
                tooltip: {
                    backgroundColor: 'rgba(255,255,255,0.98)',
                    titleColor:      '#002147',
                    bodyColor:       '#6b7280',
                    borderColor:     'rgba(0,33,71,0.1)',
                    borderWidth:     1,
                    cornerRadius:    12,
                    padding:         16,
                    displayColors:   true,
                    titleFont: { family: 'Inter', size: 14, weight: '600' },
                    bodyFont:  { family: 'Inter', size: 13 },
                },
            },
            animation: {
                duration: 1200,
                easing:   'easeInOutQuart',
                delay:    ctx => (ctx.type === 'data' && ctx.mode === 'default') ? ctx.dataIndex * 40 : 0,
            },
            scales: {
                x: {
                    grid:  { display: false, drawBorder: false },
                    ticks: { color: '#6b7280', font: { family: 'Inter', size: 11, weight: '500' }, padding: 8 },
                },
                y: {
                    grid:  { color: 'rgba(0,33,71,0.06)', drawBorder: false, lineWidth: 1 },
                    ticks: { color: '#6b7280', font: { family: 'Inter', size: 11, weight: '500' }, padding: 8 },
                },
            },
        };

        function createGradient(ctx, area, colorStops) {
            const g = ctx.createLinearGradient(0, area.bottom, 0, area.top);
            colorStops.forEach((c, i) => g.addColorStop(i / (colorStops.length - 1), c));
            return g;
        }

        // ══════════════════════════════════════════════════════════
        // PHP DATA PASSED TO JS
        // ══════════════════════════════════════════════════════════
        const feedbackDates    = <?= json_encode(array_values($fb_dates)) ?>;
        const fbTrendLikes     = <?= json_encode(array_values($fb_likes)) ?>;
        const fbTrendDislikes  = <?= json_encode(array_values($fb_dislikes)) ?>;
        const ratingLabels     = <?= json_encode($rating_labels) ?>;
        const ratingCounts     = <?= json_encode($rating_counts) ?>;
        const totalFeedback    = <?= (int)$total_feedback ?>;

        // ══════════════════════════════════════════════════════════
        // TAB SWITCHER
        // ══════════════════════════════════════════════════════════
        function switchFbTab(tab) {
            document.querySelectorAll('.fb-tab').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.fb-panel').forEach(p => p.classList.remove('active'));

            const panel = document.getElementById('fb-' + tab);
            if (panel) panel.classList.add('active');

            // Activate matching button
            document.querySelectorAll('.fb-tab').forEach(b => {
                if (b.getAttribute('onclick') && b.getAttribute('onclick').includes("'" + tab + "'")) {
                    b.classList.add('active');
                }
            });

            // Lazy-init charts when Trends tab is opened
            if (tab === 'trends') {
                if (!fbTrendChart) initFbTrendChart();
                initRatingChart();
            }
        }

        // ══════════════════════════════════════════════════════════
        // RATING DOUGHNUT — lazy, only built when Trends tab opens
        // ══════════════════════════════════════════════════════════
        let ratingChartInstance = null;

        function initRatingChart() {
            const canvas = document.getElementById('ratingChart');
            if (!canvas || ratingChartInstance) return; // already built
            if (!ratingLabels.length) return;

            const rColors = ratingLabels.map(l => FB.rating[l] || '#94a3b8');
            const rTotal  = ratingCounts.reduce((a, b) => a + b, 0);

            ratingChartInstance = new Chart(canvas, {
                type: 'doughnut',
                data: {
                    labels:   ratingLabels,
                    datasets: [{
                        data:            ratingCounts,
                        backgroundColor: rColors,
                        borderWidth:     4,
                        borderColor:     '#ffffff',
                        hoverOffset:     12,
                        hoverBorderWidth:5,
                        borderRadius:    4,
                    }],
                },
                options: {
                    responsive:          true,
                    maintainAspectRatio: false,
                    cutout: '68%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color:        '#1e293b',
                                font:         { size: 12, weight: '600' },
                                padding:      16,
                                usePointStyle:true,
                                pointStyle:   'circle',
                                boxWidth:     12,
                                boxHeight:    12,
                            },
                        },
                        tooltip: {
                            ...fbTip,
                            callbacks: {
                                label: ctx => ' ' + ctx.label + ': ' + ctx.parsed +
                                    ' (' + (rTotal > 0 ? ((ctx.parsed / rTotal) * 100).toFixed(1) : 0) + '%)',
                            },
                        },
                    },
                    animation: { animateRotate: true, animateScale: true, duration: 1000, easing: 'easeInOutQuart' },
                },
            });
        }

        // ══════════════════════════════════════════════════════════
        // FEEDBACK TREND LINE CHART — lazy
        // ══════════════════════════════════════════════════════════
        let fbTrendChart = null;

        function initFbTrendChart() {
            const canvas = document.getElementById('feedbackTrendChart');
            if (!canvas) return;

            fbTrendChart = new Chart(canvas, {
                type: 'line',
                data: {
                    labels:   feedbackDates,
                    datasets: [
                        {
                            label:           'Likes',
                            data:            fbTrendLikes,
                            borderColor:     colors.green,
                            backgroundColor: function(context) {
                                const { ctx, chartArea } = context.chart;
                                if (!chartArea) return 'rgba(16,185,129,0.15)';
                                return createGradient(ctx, chartArea, ['rgba(16,185,129,0)', 'rgba(16,185,129,0.3)']);
                            },
                            fill:                 true,
                            tension:              0.4,
                            borderWidth:          3,
                            pointRadius:          0,
                            pointHoverRadius:     8,
                            pointHoverBackgroundColor: colors.green,
                            pointHoverBorderColor:     '#fff',
                            pointHoverBorderWidth:     3,
                        },
                        {
                            label:           'Dislikes',
                            data:            fbTrendDislikes,
                            borderColor:     colors.pink,
                            backgroundColor: function(context) {
                                const { ctx, chartArea } = context.chart;
                                if (!chartArea) return 'rgba(236,72,153,0.15)';
                                return createGradient(ctx, chartArea, ['rgba(236,72,153,0)', 'rgba(236,72,153,0.3)']);
                            },
                            fill:                 true,
                            tension:              0.4,
                            borderWidth:          3,
                            pointRadius:          0,
                            pointHoverRadius:     8,
                            pointHoverBackgroundColor: colors.pink,
                            pointHoverBorderColor:     '#fff',
                            pointHoverBorderWidth:     3,
                        },
                    ],
                },
                options: {
                    ...defaultOptions,
                    interaction: { intersect: false, mode: 'index' },
                    scales: {
                        x: {
                            ...defaultOptions.scales.x,
                            title: { display: true, text: 'Date', color: '#6b7280', font: { family: 'Inter', size: 12, weight: '600' } },
                        },
                        y: {
                            ...defaultOptions.scales.y,
                            beginAtZero: true,
                            title: { display: true, text: 'Reactions', color: '#6b7280', font: { family: 'Inter', size: 12, weight: '600' } },
                        },
                    },
                },
            });
        }

        // ── Period toggle for trend chart ──
        function switchFbTrendPeriod(period, btn) {
            const toggle = document.getElementById('fbTrendsPeriodToggle');
            if (toggle) toggle.querySelectorAll('button').forEach(b => b.classList.remove('active'));
            if (btn) btn.classList.add('active');

            if (!fbTrendChart) { initFbTrendChart(); return; }

            let url = 'get_feedback_trend.php?';
            if (period === 'daily')   url += 'days=14';
            else if (period === 'weekly')  url += 'days=84&group=week';
            else if (period === 'monthly') url += 'days=365&group=month';

            fetch(url)
                .then(r => r.json())
                .then(d => {
                    fbTrendChart.data.labels            = d.labels   || feedbackDates;
                    fbTrendChart.data.datasets[0].data  = d.likes    || fbTrendLikes;
                    fbTrendChart.data.datasets[1].data  = d.dislikes || fbTrendDislikes;
                    fbTrendChart.update('active');
                })
                .catch(() => {});
        }

        // ══════════════════════════════════════════════════════════
        // EXPORT
        // ══════════════════════════════════════════════════════════
        function exportTable(tableName) {
            window.location.href = 'export.php?table=' + tableName;
        }

        // ══════════════════════════════════════════════════════════
        // FILTER FORM — auto-submit on change / debounced input
        // ══════════════════════════════════════════════════════════
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.getElementById('feedback-filter-form');
            if (!form) return;

            form.querySelectorAll('select').forEach(sel => {
                sel.addEventListener('change', () => form.submit());
            });

            const dateInput = form.querySelector('input[name="created_at"]');
            if (dateInput) dateInput.addEventListener('change', () => form.submit());

            let debounceTimer;
            form.querySelectorAll('input[type="text"]').forEach(input => {
                if (input.name !== 'created_at') {
                    input.addEventListener('input', () => {
                        clearTimeout(debounceTimer);
                        debounceTimer = setTimeout(() => form.submit(), 500);
                    });
                }
            });
        });
    </script>

    <?php include 'includes/confirm_modal.php'; ?>
    <?php include 'includes/global_toasts.php'; ?>
</body>

</html>

<?php $conn->close(); ?>