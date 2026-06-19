<?php
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
$admin_stmt = $conn->prepare($admin_query);
if ($admin_stmt) {
    $admin_stmt->bind_param("i", $_SESSION['admin_id']);
    $admin_stmt->execute();
    $admin_result = $admin_stmt->get_result();
    $admin = $admin_result->fetch_assoc();
    $admin_stmt->close();
} else {
    $admin = ['username' => 'Admin', 'email' => ''];
}

// ===== Frequently Asked Questions from Chatbot =====
// Group user messages (using prefix to avoid TEXT grouping issues), count frequency
$faq_query = "
    SELECT 
        MIN(user_message) as user_message,
        COUNT(*) as ask_count,
        MAX(created_at) as last_asked,
        intent_classification
    FROM chat_messages
    WHERE user_message IS NOT NULL
    AND TRIM(user_message) != ''
    AND CHAR_LENGTH(user_message) > 3
    GROUP BY LEFT(user_message, 100), intent_classification
    HAVING ask_count >= 1
    ORDER BY ask_count DESC
    LIMIT 50
";

$faq_result = $conn->query($faq_query);
if (!$faq_result) {
    die("Query failed: " . $conn->error);
}

$faqs = [];
$max_count = 1;
if ($faq_result) {
    while ($row = $faq_result->fetch_assoc()) {
        // Fetch sample response separately to avoid complex correlated subquery on TEXT
        $sample_sql = "SELECT bot_response FROM chat_messages 
                       WHERE user_message = ? 
                       AND bot_response IS NOT NULL AND bot_response != ''
                       ORDER BY created_at DESC LIMIT 1";
        $stmt = $conn->prepare($sample_sql);
        if ($stmt) {
            $stmt->bind_param("s", $row['user_message']);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($r = $res->fetch_assoc()) {
                $row['sample_response'] = $r['bot_response'];
            }
            $stmt->close();
        }

        $faqs[] = $row;
        if ((int) $row['ask_count'] > $max_count) {
            $max_count = (int) $row['ask_count'];
        }
    }
}

// Stats
$total_questions = 0;
$unique_questions = 0;
$today_questions = 0;

$stats_query = "SELECT 
    COUNT(*) as total,
    COUNT(DISTINCT user_message) as uniq,
    SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today
FROM chat_messages WHERE user_message IS NOT NULL AND TRIM(user_message) != ''";
$stats_result = $conn->query($stats_query);
if ($stats_result && $s = $stats_result->fetch_assoc()) {
    $total_questions = (int) $s['total'];
    $unique_questions = (int) $s['uniq'];
    $today_questions = (int) $s['today'];
}

// Intent breakdown
$intent_query = "SELECT intent_classification, COUNT(*) as cnt 
FROM chat_messages WHERE user_message IS NOT NULL 
GROUP BY intent_classification ORDER BY cnt DESC";
$intent_result = $conn->query($intent_query);
$intents = [];
if ($intent_result) {
    while ($row = $intent_result->fetch_assoc()) {
        $intents[] = $row;
    }
}

// ===== TOP 10 MOST ASKED QUESTIONS =====
$top_query = "SELECT user_message, COUNT(*) as cnt, 
    AVG(confidence_score) as avg_conf, intent_classification
FROM chat_messages 
WHERE user_message IS NOT NULL AND TRIM(user_message) != '' AND CHAR_LENGTH(user_message) > 3
GROUP BY user_message, intent_classification 
ORDER BY cnt DESC LIMIT 10";
$top_result = $conn->query($top_query);
$top_questions = [];
if ($top_result) {
    while ($row = $top_result->fetch_assoc()) {
        $top_questions[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FAQ - Frequently Asked Questions</title>
    <link rel="shortcut icon" href="images/mmu_logo_- no bg.png" type="image/x-icon">
     <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/style.css?v=1775081173">
    <link rel="stylesheet" href="css/admin.css">
    <style>
        .faq-item {
            background: #fff;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            padding: 16px 20px;
            margin-bottom: 12px;
            transition: all 0.2s ease;
        }

        .faq-item:hover {
            border-color: var(--primary-light);
            box-shadow: var(--shadow-sm);
        }

        .faq-question {
            font-weight: 600;
            color: var(--gray-800);
            font-size: var(--text-base);
            margin-bottom: 6px;
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }

        .faq-question i {
            color: var(--primary-color);
            margin-top: 3px;
            flex-shrink: 0;
        }

        .faq-answer {
            color: var(--gray-600);
            font-size: var(--text-sm);
            border-left: 3px solid var(--primary-light);
            padding-left: 12px;
            margin: 8px 0 8px 22px;
            line-height: 1.6;
            display: none;
        }

        .faq-meta {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-left: 22px;
            font-size: 0.78rem;
            color: var(--gray-500);
        }

        .faq-meta span {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .faq-expand {
            color: var(--primary-color);
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 22px;
            margin-top: 6px;
            display: inline-block;
        }

        .faq-expand:hover {
            text-decoration: underline;
        }

        .intent-chip {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: capitalize;
        }

        .intent-general_campus {
            background: rgba(59, 130, 246, 0.1);
            color: #2563eb;
        }

        .intent-faq {
            background: rgba(16, 185, 129, 0.1);
            color: #059669;
        }

        .intent-academic {
            background: rgba(139, 92, 246, 0.1);
            color: #7c3aed;
        }

        .intent-admission {
            background: rgba(245, 158, 11, 0.1);
            color: #d97706;
        }

        .intent-financial {
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
        }

        .intent-out_of_scope {
            background: rgba(107, 114, 128, 0.1);
            color: #6b7280;
        }

        .intent-sensitive_data {
            background: rgba(239, 68, 68, 0.15);
            color: #b91c1c;
        }

        .top-questions-table {
            margin-top: 32px;
        }
    </style>
</head>

<body>
      <!--== MAIN CONTAINER ==-->
    <?php include 'includes/topbar.php'; ?>

    </div>
    </div>

    <div class="container-fluid sb2">
        <div class="row">
            <?php include 'includes/sidebar.php'; ?>
            <div class="sb2-2 col-md-9">
                
                <div class="db-2">
                    <div style="padding: 24px; ">
                        <h2><i class="fa-solid fa-circle-question" style="color: var(--primary-color);"></i> Frequently
                            Asked Questions</h2>
                      

                        <!-- Stats Cards -->
                        <div class="stat-cards-row">
                            <div class="stat-card">
                                <div class="stat-card-icon blue"><i class="fa-solid fa-comments"></i></div>
                                <h6>Total Questions</h6>
                                <p class="stat-value"><?= number_format($total_questions) ?></p>
                            </div>
                            <div class="stat-card">
                                <div class="stat-card-icon purple"><i class="fa-solid fa-fingerprint"></i></div>
                                <h6>Unique Questions</h6>
                                <p class="stat-value"><?= number_format($unique_questions) ?></p>
                            </div>
                            <div class="stat-card">
                                <div class="stat-card-icon green"><i class="fa-solid fa-calendar-day"></i></div>
                                <h6>Asked Today</h6>
                                <p class="stat-value"><?= number_format($today_questions) ?></p>
                            </div>
                            <div class="stat-card">
                                <div class="stat-card-icon amber"><i class="fa-solid fa-fire-flame-curved"></i></div>
                                <h6>Most Popular</h6>
                                <p class="stat-value"><?= $max_count ?>×</p>
                                <p class="stat-sub">times asked</p>
                            </div>
                        </div>

                        <!-- Intent Breakdown Mini -->
                        <?php if (!empty($intents)): ?>
                            <div class="chart-card" style="margin-bottom: 20px;">
                                <h3><i class="fa-solid fa-tags"></i> Question Types (Intent Breakdown)</h3>
                                <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                                    <?php foreach ($intents as $it): ?>
                                        <div
                                            style="display: flex; align-items: center; gap: 6px; background: var(--gray-50); padding: 6px 14px; border-radius: 8px;">
                                            <span
                                                class="intent-chip intent-<?= htmlspecialchars($it['intent_classification'] ?? 'general_campus') ?>">
                                                <?= htmlspecialchars(str_replace('_', ' ', $it['intent_classification'] ?? 'unknown')) ?>
                                            </span>
                                            <strong style="font-size: 0.85rem;"><?= number_format($it['cnt']) ?></strong>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                         <!-- Top 10 Most Asked Questions -->
                        <div class="top-questions-table chart-card" style="margin-bottom: 20px;">
                            <h3><i class="fa-solid fa-ranking-star"></i> Top 10 Most Asked Questions</h3>
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Question</th>
                                        <th>Count</th>
                                        <th>Intent</th>
                                        <th>Avg Confidence</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($top_questions)): ?>
                                        <tr>
                                            <td colspan="5" style="text-align:center;color:var(--gray-400);padding:30px;">No questions recorded yet</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($top_questions as $i => $q): ?>
                                            <tr>
                                                <td><strong><?= $i + 1 ?></strong></td>
                                                <td class="truncate-cell">
                                                    <?= htmlspecialchars(mb_strimwidth($q['user_message'], 0, 120, '...')) ?>
                                                </td>
                                                <td><strong><?= number_format($q['cnt']) ?></strong></td>
                                                <td>
                                                    <span class="intent-chip intent-<?= htmlspecialchars($q['intent_classification'] ?? 'general_campus') ?>">
                                                        <?= htmlspecialchars(str_replace('_', ' ', $q['intent_classification'] ?? 'unknown')) ?>
                                                    </span>
                                                </td>
                                                <td><?= $q['avg_conf'] > 0 ? round($q['avg_conf'] * 100, 1) . '%' : '—' ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- FAQ List -->
                        <div class="chart-card">
                            <h3><i class="fa-solid fa-ranking-star"></i> Top Chatbot Questions</h3>
                            <div style="margin-bottom: 16px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <div style="position: relative; flex: 1; min-width: 200px; max-width: 400px;">
                                    <input type="text" id="faqSearchInput" placeholder="Search questions..."
                                        oninput="filterFAQs()"
                                        style="width: 100%; padding: 8px 36px 8px 12px; border: 1px solid var(--gray-200, #e2e8f0); border-radius: 8px; font-size: 0.85rem; outline: none;">
                                    <i class="fa-solid fa-search"
                                        style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: var(--gray-400, #9ca3af); font-size: 0.8rem;"></i>
                                </div>
                                <select id="faqLimitSelect" onchange="filterFAQs()"
                                    style="padding: 8px 12px; border: 1px solid var(--gray-200, #e2e8f0); border-radius: 8px; font-size: 0.85rem;">
                                    <option value="20">Top 20</option>
                                    <option value="50" selected>All (50)</option>
                                    <option value="10">Top 10</option>
                                    <option value="5">Top 5</option>
                                </select>
                                <select id="faqIntentFilter" onchange="filterFAQs()"
                                    style="padding: 8px 12px; border: 1px solid var(--gray-200, #e2e8f0); border-radius: 8px; font-size: 0.85rem;">
                                    <option value="">All Types</option>
                                    <?php foreach ($intents as $it): ?>
                                        <option value="<?= htmlspecialchars($it['intent_classification'] ?? '') ?>">
                                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $it['intent_classification'] ?? 'unknown'))) ?>
                                            (<?= number_format($it['cnt']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button onclick="document.getElementById('faqSearchInput').value=''; document.getElementById('faqLimitSelect').value='50'; document.getElementById('faqIntentFilter').value=''; filterFAQs();"
                                    style="padding: 8px 14px; border: 1px solid var(--gray-200, #e2e8f0); border-radius: 8px; background: #fff; cursor: pointer; font-size: 0.8rem; color: var(--gray-600, #4b5563);">
                                    <i class="fa-solid fa-times"></i> Clear
                                </button>
                            </div>
                            <?php if (empty($faqs)): ?>
                                <div style="text-align: center; padding: 40px; color: var(--gray-400);">
                                    <i class="fa-solid fa-inbox"
                                        style="font-size: 2.5rem; margin-bottom: 12px; display: block;"></i>
                                    <p>No chatbot conversations found yet.</p>
                                    <p style="font-size: 0.85rem;">FAQs will appear here once users start asking questions.
                                    </p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($faqs as $i => $faq):
                                    $pct = ($max_count > 0) ? round(((int) $faq['ask_count'] / $max_count) * 100) : 0;
                                    ?>
                                    <div class="faq-item">
                                        <div class="faq-question">
                                            <i class="fa-solid fa-message"></i>
                                            <span><?= htmlspecialchars($faq['user_message']) ?></span>
                                        </div>
                                        <div class="faq-meta">
                                            <span><i class="fa-solid fa-repeat"></i> <?= (int) $faq['ask_count'] ?> times</span>
                                            <span><i class="fa-regular fa-clock"></i> Last:
                                                <?= date('M j, Y g:ia', strtotime($faq['last_asked'])) ?></span>
                                            <?php if (!empty($faq['intent_classification'])): ?>
                                                <span
                                                    class="intent-chip intent-<?= htmlspecialchars($faq['intent_classification']) ?>">
                                                    <?= htmlspecialchars(str_replace('_', ' ', $faq['intent_classification'])) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="freq-bar" style="margin: 8px 0 0 22px; max-width: 300px;">
                                            <div class="freq-bar-fill" style="width: <?= $pct ?>%;"></div>
                                            <span class="freq-count"><?= $pct ?>%</span>
                                        </div>
                                        <?php if (!empty($faq['sample_response'])): ?>
                                            <span class="faq-expand"
                                                onclick="let a=this.nextElementSibling; a.style.display = a.style.display==='block'?'none':'block'; this.innerHTML = a.style.display==='block'?'<i class=\'fa-solid fa-chevron-up\'></i> Hide answer':'<i class=\'fa-solid fa-chevron-down\'></i> Show answer';">
                                                <i class="fa-solid fa-chevron-down"></i> Show answer
                                            </span>
                                            <div class="faq-answer">
                                                <?= nl2br(htmlspecialchars(mb_strimwidth($faq['sample_response'], 0, 500, '...'))) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                       

                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
        function filterFAQs() {
            const term = (document.getElementById('faqSearchInput').value || '').toLowerCase();
            const limit = parseInt(document.getElementById('faqLimitSelect').value) || 999;
            const intent = (document.getElementById('faqIntentFilter').value || '').toLowerCase();
            const items = document.querySelectorAll('.faq-item');
            let shown = 0;
            items.forEach(item => {
                const question = item.querySelector('.faq-question span');
                if (!question) return;
                const text = question.textContent.toLowerCase();
                const intentChip = item.querySelector('.intent-chip');
                const itemIntent = intentChip ? intentChip.textContent.trim().toLowerCase().replace(/\s+/g, '_') : '';
                const matchSearch = !term || text.includes(term);
                const matchIntent = !intent || itemIntent === intent.replace(/_/g, ' ');
                if (matchSearch && matchIntent && shown < limit) {
                    item.style.display = '';
                    shown++;
                } else {
                    item.style.display = 'none';
                }
            });
        }
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
    </script>
</body>

</html>