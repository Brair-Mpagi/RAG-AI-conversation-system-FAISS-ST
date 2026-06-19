<?php
session_start();
require_once 'db.php';


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


if (!isset($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit();
}

// Fetch Admin Details (needed for sidebar avatar/name)
$admin_query = "SELECT admin_id, username, email FROM admins WHERE admin_id = ?";
$admin_stmt = $conn->prepare($admin_query);
if ($admin_stmt) {
    $admin_stmt->bind_param('i', $_SESSION['admin_id']);
    $admin_stmt->execute();
    $admin_result = $admin_stmt->get_result();
    $admin = $admin_result->fetch_assoc();
    $admin_stmt->close();
}

function logAdminActivity($conn, $admin_id, $action, $module, $description, $affected = null)
{
    if (!$conn || !$admin_id) return;
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $affected_json = $affected ? json_encode($affected) : null;
    $stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, module, description, affected_records, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param('isssss', $admin_id, $action, $module, $description, $affected_json, $ip);
        $stmt->execute();
        $stmt->close();
    }
}

$notice = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create_source') {
            $source_name = trim($_POST['source_name'] ?? '');
            $base_url = trim($_POST['base_url'] ?? '');
            $scrape_frequency = $_POST['scrape_frequency'] ?? 'daily';
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $max_depth = (int) ($_POST['max_depth'] ?? 2);

            // Build scraping config
            $config = [
                'max_depth' => $max_depth,
                'follow_links' => isset($_POST['follow_links']) ? true : false,
                'selectors' => [
                    'content' => $_POST['content_selector'] ?? 'body',
                    'title' => $_POST['title_selector'] ?? 'h1, title',
                    'exclude' => $_POST['exclude_selector'] ?? 'nav, footer, script, style'
                ],
                'url_patterns' => [
                    'include' => array_values(array_filter(explode("\n", $_POST['include_patterns'] ?? ''))),
                    'exclude' => array_values(array_filter(explode("\n", $_POST['exclude_patterns'] ?? '')))
                ]
            ];

            $stmt = $conn->prepare("INSERT INTO scraping_sources (source_name, base_url, scrape_frequency, is_active, scraping_config) VALUES (?, ?, ?, ?, ?)");
            $config_json = json_encode($config);
            $stmt->bind_param('sssis', $source_name, $base_url, $scrape_frequency, $is_active, $config_json);
            $stmt->execute();
            logAdminActivity($conn, $_SESSION['admin_id'], 'CREATE', 'web_scraper', "Created scraping source: $source_name");
            $notice = 'Scraping source created successfully!';
        } elseif ($action === 'update_source') {
            $source_id = (int) $_POST['source_id'];
            $source_name = trim($_POST['source_name'] ?? '');
            $base_url = trim($_POST['base_url'] ?? '');
            $scrape_frequency = $_POST['scrape_frequency'] ?? 'daily';
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $max_depth = (int) ($_POST['max_depth'] ?? 2);

            $config = [
                'max_depth' => $max_depth,
                'follow_links' => isset($_POST['follow_links']) ? true : false,
                'selectors' => [
                    'content' => $_POST['content_selector'] ?? 'body',
                    'title' => $_POST['title_selector'] ?? 'h1, title',
                    'exclude' => $_POST['exclude_selector'] ?? 'nav, footer, script, style'
                ],
                'url_patterns' => [
                    'include' => array_values(array_filter(explode("\n", $_POST['include_patterns'] ?? ''))),
                    'exclude' => array_values(array_filter(explode("\n", $_POST['exclude_patterns'] ?? '')))
                ]
            ];

            $stmt = $conn->prepare("UPDATE scraping_sources SET source_name=?, base_url=?, scrape_frequency=?, is_active=?, scraping_config=? WHERE source_id=?");
            $config_json = json_encode($config);
            $stmt->bind_param('sssisi', $source_name, $base_url, $scrape_frequency, $is_active, $config_json, $source_id);
            $stmt->execute();
            logAdminActivity($conn, $_SESSION['admin_id'], 'UPDATE', 'web_scraper', "Updated scraping source: $source_name (ID: $source_id)");
            $notice = 'Scraping source updated successfully!';
        } elseif ($action === 'delete_source') {
            $source_id = (int) $_POST['source_id'];
            $stmt = $conn->prepare("DELETE FROM scraping_sources WHERE source_id=?");
            $stmt->bind_param('i', $source_id);
            $stmt->execute();
            logAdminActivity($conn, $_SESSION['admin_id'], 'DELETE', 'web_scraper', "Deleted scraping source (ID: $source_id)");
            $notice = 'Scraping source deleted successfully!';
        } elseif ($action === 'delete_content') {
            $scraped_id = (int) $_POST['scraped_id'];
            $stmt = $conn->prepare("DELETE FROM scraped_content WHERE scraped_id=?");
            $stmt->bind_param('i', $scraped_id);
            $stmt->execute();
            logAdminActivity($conn, $_SESSION['admin_id'], 'DELETE', 'web_scraper', "Deleted scraped content (ID: $scraped_id)");
            $notice = 'Scraped content deleted successfully!';
        } elseif ($action === 'bulk_delete_content') {
            $ids = [];
            if (!empty($_POST['selected_ids_str'])) {
                $ids = array_map('intval', explode(',', $_POST['selected_ids_str']));
            } elseif (!empty($_POST['selected_ids']) && is_array($_POST['selected_ids'])) {
                $ids = array_map('intval', $_POST['selected_ids']);
            }
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $types = str_repeat('i', count($ids));

                $stmt = $conn->prepare("DELETE FROM scraped_content WHERE scraped_id IN ($placeholders)");
                $stmt->bind_param($types, ...$ids);
                $stmt->execute();

                logAdminActivity($conn, $_SESSION['admin_id'], 'BULK_DELETE', 'web_scraper', "Bulk deleted " . count($ids) . " items", $ids);
                $notice = count($ids) . ' scraped items deleted successfully!';
            }
        } elseif ($action === 'bulk_strip_content') {
            $ids = [];
            if (!empty($_POST['selected_ids_str'])) {
                $ids = array_map('intval', explode(',', $_POST['selected_ids_str']));
            } elseif (!empty($_POST['selected_ids']) && is_array($_POST['selected_ids'])) {
                $ids = array_map('intval', $_POST['selected_ids']);
            }
            if (!empty($ids)) {
                $strip_text_raw = $_POST['strip_text'] ?? '';
                $strip_text = trim($strip_text_raw);

                if ($strip_text !== '') {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $types = str_repeat('i', count($ids));

                    // Fetch existing content
                    $stmt = $conn->prepare("SELECT scraped_id, cleaned_content FROM scraped_content WHERE scraped_id IN ($placeholders)");
                    $stmt->bind_param($types, ...$ids);
                    $stmt->execute();
                    $result = $stmt->get_result();

                    $update_stmt = $conn->prepare("UPDATE scraped_content SET cleaned_content=?, content_hash=? WHERE scraped_id=?");

                    // Create a robust regex pattern that ignores whitespace discrepancies
                    // 1. Escape literal characters
                    $regex_escaped = preg_quote($strip_text_raw, '/');
                    // 2. Replace any literal whitespace block in the escaped string with \s+ so it matches flexibly
                    $regex_escaped = preg_replace('/\s+/', '\s+', $regex_escaped);
                    $full_pattern = '/' . $regex_escaped . '/isu';

                    $update_count = 0;
                    while ($row = $result->fetch_assoc()) {
                        $content = $row['cleaned_content'];
                        
                        // First try an exact substring replacement (fastest)
                        $new_content_eval = str_replace($strip_text_raw, '', $content);
                        
                        // If exact match failed, try the flexible whitespace-agnostic regex
                        if ($new_content_eval === $content) {
                            $preg_result = preg_replace($full_pattern, '', $content);
                            if ($preg_result !== null) {
                                $new_content_eval = $preg_result;
                            }
                        }

                        if ($new_content_eval !== $content) {
                            $new_hash = hash('sha256', $new_content_eval);
                            $scraped_id = $row['scraped_id'];
                            $update_stmt->bind_param('ssi', $new_content_eval, $new_hash, $scraped_id);
                            $update_stmt->execute();
                            $update_count++;
                        }
                    }
                    if (isset($update_stmt) && $update_stmt) $update_stmt->close();

                    logAdminActivity($conn, $_SESSION['admin_id'], 'BULK_UPDATE', 'web_scraper', "Bulk stripped text from $update_count items", $ids);
                    if ($update_count > 0) {
                        $notice = $update_count . ' scraped items had text removed and were updated successfully!';
                    } else {
                        $error = 'No matching content found to strip. Try selecting a smaller or more specific string.';
                    }
                } else {
                    $error = 'Text to remove cannot be empty. Received length: ' . strlen($strip_text_raw) . ' chars.';
                }
            }
        } elseif ($action === 'update_content') {
            $scraped_id = (int) $_POST['scraped_id'];
            $new_content = trim($_POST['cleaned_content'] ?? '');
            $new_hash = hash('sha256', $new_content);

            $stmt = $conn->prepare("
                UPDATE scraped_content
                SET cleaned_content=?, content_hash=?,
                    enrichment_status='pending', status='updated'
                WHERE scraped_id=?
            ");
            $stmt->bind_param('ssi', $new_content, $new_hash, $scraped_id);
            $stmt->execute();
            logAdminActivity($conn, $_SESSION['admin_id'], 'UPDATE', 'web_scraper', "Manually updated scraped content (ID: $scraped_id)");
            $notice = 'Content manually updated successfully!';
        } elseif ($action === 'run_scrape') {
            $source_id = (int) $_POST['source_id'];

            // Get source details
            $stmt = $conn->prepare("SELECT * FROM scraping_sources WHERE source_id=?");
            $stmt->bind_param('i', $source_id);
            $stmt->execute();
            $source = $stmt->get_result()->fetch_assoc();

            if ($source) {
                // Resolve Python scraper script path
                $python_script = __DIR__ . '/scripts/web_scraper.py';
                if (!file_exists($python_script)) {
                    $alt = '/home/bcodz/Desktop/Research_Project/University_Ai_Chatbot_System/scripts/web_scraper.py';
                    if (file_exists($alt)) {
                        $python_script = $alt;
                    } else {
                        $python_script = null;
                    }
                }

                if (!$python_script) {
                    $error = 'Scraper script not found. Please check the scripts/web_scraper.py path.';
                } else {
                    $python_bin = '/home/bcodz/Desktop/Research_Project/University_Ai_Chatbot_System/backend/backend_env/bin/python3';
                    if (!file_exists($python_bin)) {
                        $python_bin = 'python3';
                    }
                    $cmd = sprintf(
                        '%s %s --source-id %d --base-url %s --db-host %s --db-user %s --db-password %s --db-name %s 2>&1',
                        escapeshellarg($python_bin),
                        escapeshellarg($python_script),
                        $source_id,
                        escapeshellarg($source['base_url']),
                        escapeshellarg($host),
                        escapeshellarg($username),
                        escapeshellarg($password),
                        escapeshellarg($dbname)
                    );

                    $output = [];
                    $return_var = 0;
                    exec($cmd, $output, $return_var);

                    // Parse scraper output into structured summary
                    $scrape_result = [
                        'success' => ($return_var === 0),
                        'pages_visited' => 0,
                        'new_count' => 0,
                        'updated_count' => 0,
                        'unchanged_count' => 0,
                        'skipped_count' => 0,
                        'failed_count' => 0,
                        'elapsed' => '0',
                        'log_lines' => [],
                        'error_lines' => [],
                    ];

                    foreach ($output as $line) {
                        $trimmed = trim($line);
                        if ($trimmed === '' || preg_match('/^={5,}$/', $trimmed) || preg_match('/^-{5,}$/', $trimmed)) continue;

                        // Parse summary stats
                        if (preg_match('/Pages visited:\s*(\d+)/i', $trimmed, $m)) $scrape_result['pages_visited'] = (int)$m[1];
                        elseif (preg_match('/New pages:\s*(\d+)/i', $trimmed, $m)) $scrape_result['new_count'] = (int)$m[1];
                        elseif (preg_match('/Updated pages:\s*(\d+)/i', $trimmed, $m)) $scrape_result['updated_count'] = (int)$m[1];
                        elseif (preg_match('/Unchanged:\s*(\d+)/i', $trimmed, $m)) $scrape_result['unchanged_count'] = (int)$m[1];
                        elseif (preg_match('/Skipped:\s*(\d+)/i', $trimmed, $m)) $scrape_result['skipped_count'] = (int)$m[1];
                        elseif (preg_match('/Failed:\s*(\d+)/i', $trimmed, $m)) $scrape_result['failed_count'] = (int)$m[1];
                        elseif (preg_match('/Time elapsed:\s*([\d.]+)s/i', $trimmed, $m)) $scrape_result['elapsed'] = $m[1];

                        // Classify each log line
                        $type = 'info';
                        if (mb_strpos($trimmed, '✓') !== false || mb_strpos($trimmed, 'NEW:') !== false || mb_strpos($trimmed, 'successfully') !== false) $type = 'success';
                        elseif (mb_strpos($trimmed, '✗') !== false || mb_strpos($trimmed, 'FAILED') !== false || mb_strpos($trimmed, 'Error') !== false || mb_strpos($trimmed, 'error') !== false) $type = 'error';
                        elseif (mb_strpos($trimmed, '↻') !== false || mb_strpos($trimmed, 'UPDATED') !== false) $type = 'update';
                        elseif (mb_strpos($trimmed, '⊘') !== false || mb_strpos($trimmed, 'SKIP') !== false || mb_strpos($trimmed, 'Skipping') !== false) $type = 'skipped';

                        $scrape_result['log_lines'][] = ['text' => $trimmed, 'type' => $type];
                        if ($type === 'error') {
                            $scrape_result['error_lines'][] = $trimmed;
                        }
                    }

                    if ($return_var === 0) {
                        $stmt = $conn->prepare("UPDATE scraping_sources SET last_scraped=NOW() WHERE source_id=?");
                        $stmt->bind_param('i', $source_id);
                        $stmt->execute();
                    }
                    logAdminActivity($conn, $_SESSION['admin_id'], 'RUN', 'web_scraper', "Triggered manual synchronous scrape for source (ID: $source_id)");
                }
            } else {
                $error = 'Source not found!';
            }

        // ── Add one OR many URLs to an existing source ─────────────────────
        } elseif ($action === 'add_url_to_source') {
            $source_id   = (int) ($_POST['source_id'] ?? 0);
            $urls_raw    = trim($_POST['page_urls'] ?? '');
            $scrape_now  = !empty($_POST['scrape_now']);

            if (!$source_id || !$urls_raw) {
                $error = 'Source and at least one URL are required.';
            } else {
                $python_script = __DIR__ . '/scripts/web_scraper.py';
                $python_bin    = file_exists('/home/bcodz/Desktop/pjt-chatbot/backend/backend_env/bin/python3')
                    ? '/home/bcodz/Desktop/pjt-chatbot/backend/backend_env/bin/python3'
                    : 'python3';

                // Parse list of URLs (newlines or commas)
                $raw_lines = preg_split('/[\r\n,]+/', $urls_raw);
                $urls = array_values(array_filter(array_map('trim', $raw_lines)));

                $added = 0; $skipped = 0; $updated = 0;
                foreach ($urls as $page_url) {
                    if (!filter_var($page_url, FILTER_VALIDATE_URL)) { $skipped++; continue; }

                    // Check if already scraped under this source
                    $chk = $conn->prepare("SELECT scraped_id FROM scraped_content WHERE source_id=? AND page_url=? LIMIT 1");
                    $chk->bind_param('is', $source_id, $page_url);
                    $chk->execute();
                    $existing = $chk->get_result()->fetch_assoc();
                    $chk->close();

                    // Check if already in queue (any non-skipped status)
                    $chk2 = $conn->prepare("SELECT queue_id, status FROM scrape_link_queue WHERE source_id=? AND page_url=? LIMIT 1");
                    $chk2->bind_param('is', $source_id, $page_url);
                    $chk2->execute();
                    $in_queue = $chk2->get_result()->fetch_assoc();
                    $chk2->close();

                    if ($in_queue && $in_queue['status'] === 'pending' && !$scrape_now) {
                        // Already pending — don't duplicate
                        $skipped++;
                        continue;
                    }

                    // Upsert into queue
                    $depth_one = 1;
                    $ins = $conn->prepare("
                        INSERT INTO scrape_link_queue (source_id, page_url, crawl_depth, status)
                        VALUES (?, ?, ?, 'pending')
                        ON DUPLICATE KEY UPDATE status = IF(status IN ('done','failed','skipped'), 'pending', status)
                    ");
                    $ins->bind_param('isi', $source_id, $page_url, $depth_one);
                    $ins->execute();
                    $ins->close();

                    if ($existing) { $updated++; } else { $added++; }

                    if ($scrape_now && file_exists($python_script)) {
                        $cmd = sprintf(
                            '%s %s --mode single --source-id %d --single-url %s --db-host %s --db-user %s --db-password %s --db-name %s > /dev/null 2>&1 &',
                            escapeshellarg($python_bin), escapeshellarg($python_script),
                            $source_id, escapeshellarg($page_url),
                            escapeshellarg($host), escapeshellarg($username),
                            escapeshellarg($password), escapeshellarg($dbname)
                        );
                        exec($cmd);
                    }
                }

                logAdminActivity($conn, $_SESSION['admin_id'], 'ADD_URL', 'web_scraper',
                    "Batch-queued URLs for source $source_id: $added new, $updated re-queue, $skipped skipped");

                $parts = [];
                if ($added)   $parts[] = "$added new URL(s) queued";
                if ($updated) $parts[] = "$updated already-scraped URL(s) re-queued for update";
                if ($skipped) $parts[] = "$skipped duplicate/invalid URL(s) skipped";
                $notice = implode(', ', $parts) . '.';
                if ($scrape_now && ($added + $updated) > 0)
                    $notice .= ' Background scraping started.';
            }

        // ── Scan a source for missing links (smart domain re-indexer) ──────────────
        } elseif ($action === 'scan_missing_links') {
            $source_id = (int) ($_POST['source_id'] ?? 0);
            if (!$source_id) {
                $error = 'Source ID required.';
            } else {
                $python_script = __DIR__ . '/scripts/web_scraper.py';
                $python_bin    = file_exists('/home/bcodz/Desktop/pjt-chatbot/backend/backend_env/bin/python3')
                    ? '/home/bcodz/Desktop/pjt-chatbot/backend/backend_env/bin/python3'
                    : 'python3';

                if (!file_exists($python_script)) {
                    $error = 'Scraper script not found.';
                } else {
                    // Record baseline queue count so JS can track newly queued items
                    $count_q = $conn->prepare("SELECT COUNT(*) AS cnt FROM scrape_link_queue WHERE source_id=? AND status='pending'");
                    $count_q->bind_param('i', $source_id);
                    $count_q->execute();
                    $count_row = $count_q->get_result()->fetch_assoc();
                    $count_q->close();
                    $baseline = (int)($count_row['cnt'] ?? 0);

                    // Run in --mode scan-missing (BFS link-discovery only, no re-scraping)
                    $cmd = sprintf(
                        '%s %s --mode scan-missing --source-id %d --db-host %s --db-user %s --db-password %s --db-name %s > /dev/null 2>&1 &',
                        escapeshellarg($python_bin),
                        escapeshellarg($python_script),
                        $source_id,
                        escapeshellarg($host),
                        escapeshellarg($username),
                        escapeshellarg($password),
                        escapeshellarg($dbname)
                    );
                    exec($cmd);

                    logAdminActivity($conn, $_SESSION['admin_id'], 'SCAN_MISSING', 'web_scraper',
                        "Started smart missing-links scan for source $source_id (queue baseline: $baseline pending)");
                    $notice = "SCAN_STARTED:$source_id:$baseline";
                }
            }


        // ── Scrape pending queue items for a source ───────────────────────────
        } elseif ($action === 'scrape_missing_links') {
            $source_id = (int) ($_POST['source_id'] ?? 0);
            if (!$source_id) {
                $error = 'Source ID required.';
            } else {
                $python_script = __DIR__ . '/scripts/web_scraper.py';
                $python_bin    = file_exists('/home/bcodz/Desktop/pjt-chatbot/backend/backend_env/bin/python3')
                    ? '/home/bcodz/Desktop/pjt-chatbot/backend/backend_env/bin/python3'
                    : 'python3';

                if (!file_exists($python_script)) {
                    $error = 'Scraper script not found.';
                } else {
                    // Run in background (non-blocking)
                    $cmd = sprintf(
                        '%s %s --mode scrape-missing --source-id %d --db-host %s --db-user %s --db-password %s --db-name %s > /dev/null 2>&1 &',
                        escapeshellarg($python_bin),
                        escapeshellarg($python_script),
                        $source_id,
                        escapeshellarg($host),
                        escapeshellarg($username),
                        escapeshellarg($password),
                        escapeshellarg($dbname)
                    );
                    exec($cmd);

                    // Count how many pending items we started
                    $count_stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM scrape_link_queue WHERE source_id=? AND status='pending'");
                    $count_stmt->bind_param('i', $source_id);
                    $count_stmt->execute();
                    $count_row = $count_stmt->get_result()->fetch_assoc();
                    $pending_count = $count_row['cnt'] ?? 0;
                    $count_stmt->close();

                    logAdminActivity($conn, $_SESSION['admin_id'], 'SCRAPE_MISSING', 'web_scraper',
                        "Started scrape-missing for source $source_id ($pending_count pending URLs)");
                    $notice = "Background scraping of $pending_count missing URL(s) started. Refresh in a few minutes to see results.";
                }
            }

        // ── Dismiss (skip) a single queue item ────────────────────────────────
        } elseif ($action === 'dismiss_queue_item') {
            $queue_id = (int) ($_POST['queue_id'] ?? 0);
            if ($queue_id) {
                $stmt = $conn->prepare("UPDATE scrape_link_queue SET status='skipped' WHERE queue_id=?");
                $stmt->bind_param('i', $queue_id);
                $stmt->execute();
                $stmt->close();
                $notice = 'Queue item dismissed.';
            }

        // ── Bulk dismiss selected queue items ─────────────────────────────────
        } elseif ($action === 'bulk_dismiss_queue') {
            $source_id = (int) ($_POST['source_id'] ?? 0);
            $ids_str   = $_POST['queue_ids'] ?? '';
            $ids = array_filter(array_map('intval', explode(',', $ids_str)));
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $types = str_repeat('i', count($ids));
                $stmt  = $conn->prepare("UPDATE scrape_link_queue SET status='skipped' WHERE queue_id IN ($placeholders)");
                $stmt->bind_param($types, ...$ids);
                $stmt->execute();
                $stmt->close();
                $notice = count($ids) . ' queue item(s) dismissed.';
            }
        }

    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}


// Fetch all scraping sources
$sources = [];
$result = $conn->query("SELECT * FROM scraping_sources ORDER BY created_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $sources[] = $row;
    }
}

// Fetch queue counts per source for badge display (safe — table may not exist before migration)
$queue_counts = [];
$qc_result = @$conn->query("SELECT source_id, status, COUNT(*) as cnt FROM scrape_link_queue GROUP BY source_id, status");
if ($qc_result) {
    while ($row = $qc_result->fetch_assoc()) {
        $queue_counts[$row['source_id']][$row['status']] = (int)$row['cnt'];
    }
}

// Fetch admin activity logs for scraper module
// Fetch admin activity logs for scraper module
$admin_logs = [];
$log_action = trim($_GET['log_action'] ?? '');
$log_admin = trim($_GET['log_admin'] ?? '');

$log_where = ["l.module = 'web_scraper'"];
$log_params = [];
$log_types = "";

if ($log_action !== '') {
    $log_where[] = "l.action = ?";
    $log_params[] = $log_action;
    $log_types .= "s";
}
if ($log_admin !== '') {
    $log_where[] = "a.username LIKE ?";
    $log_params[] = "%" . $log_admin . "%";
    $log_types .= "s";
}

$where_clause = implode(" AND ", $log_where);
$log_query = "
    SELECT l.log_id, l.action, l.description, l.timestamp, a.username 
    FROM admin_activity_logs l 
    JOIN admins a ON l.admin_id = a.admin_id 
    WHERE $where_clause 
    ORDER BY l.timestamp DESC LIMIT 50
";

if (empty($log_params)) {
    $log_res = $conn->query($log_query);
} else {
    $stmt = $conn->prepare($log_query);
    if ($stmt) {
        $stmt->bind_param($log_types, ...$log_params);
        $stmt->execute();
        $log_res = $stmt->get_result();
    }
}

if (isset($log_res) && $log_res) {
    while ($row = $log_res->fetch_assoc()) {
        $admin_logs[] = $row;
    }
}

// Search / filter parameters
$search_q = trim($_GET['q'] ?? '');
$filter_status = trim($_GET['status'] ?? '');
$filter_enrichment = trim($_GET['enrichment_status'] ?? '');
$filter_source = (int) ($_GET['source_id'] ?? 0);

// Build scraped content query with filters
$where_clauses = [];
$params = [];
$types = '';

if ($search_q !== '') {
    $where_clauses[] = "(sc.page_title LIKE ? OR sc.page_url LIKE ?)";
    $search_like = "%$search_q%";
    $params[] = $search_like;
    $params[] = $search_like;
    $types .= 'ss';
}
if ($filter_status !== '') {
    $where_clauses[] = "sc.status = ?";
    $params[] = $filter_status;
    $types .= 's';
}
if ($filter_enrichment !== '') {
    $where_clauses[] = "sc.enrichment_status = ?";
    $params[] = $filter_enrichment;
    $types .= 's';
}
if ($filter_source > 0) {
    $where_clauses[] = "sc.source_id = ?";
    $params[] = $filter_source;
    $types .= 'i';
}

$where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

$recent_scraped = [];
$sql = "SELECT sc.*, ss.source_name,
        (SELECT COUNT(*) FROM scraped_content sc2 
         WHERE sc2.page_url = sc.page_url AND sc2.source_id != sc.source_id) as duplicate_count
        FROM scraped_content sc
        LEFT JOIN scraping_sources ss ON sc.source_id = ss.source_id
        $where_sql ORDER BY sc.scraped_at DESC";

if (count($params) > 0) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $recent_scraped[] = $row;
    }
}

// Get stats
$stats = ['total' => 0, 'new' => 0, 'updated' => 0, 'processed' => 0, 'indexed' => 0, 'failed' => 0];
$stats_result = $conn->query("SELECT status, COUNT(*) as cnt FROM scraped_content GROUP BY status");
if ($stats_result) {
    while ($row = $stats_result->fetch_assoc()) {
        $stats[$row['status']] = (int) $row['cnt'];
        $stats['total'] += (int) $row['cnt'];
    }
}

$enrich_stats = ['pending' => 0, 'done' => 0, 'failed' => 0, 'skipped' => 0];
$enrich_stats_result = @$conn->query("SELECT enrichment_status, COUNT(*) as cnt FROM scraped_content GROUP BY enrichment_status");
if ($enrich_stats_result) {
    while ($row = $enrich_stats_result->fetch_assoc()) {
        $key = $row['enrichment_status'] ?? 'pending';
        $enrich_stats[$key] = (int) $row['cnt'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Web Scraper Management - Campus AI</title>
    <link rel="shortcut icon" href="images/mmu_logo_- no bg.png" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"
        integrity="sha512-Kc323vGBEqzTmouAECnVceyQqyqdsSiqLQISBL29aUW4U/M7pSPA/gEUZQqv1cwx4OnYxTxve5UMg5GT6L4JJg=="
        crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="css/style.css?v=1775081173">
    <link rel="stylesheet" href="css/admin.css">

    <style>
        /* ===================================================
           WEB SCRAPER — Modern Enterprise Design System
        =================================================== */
        :root {
            --ws-bg: #f0f2f7;
            --ws-surface: #ffffff;
            --ws-surface-2: #f8f9fc;
            --ws-border: #e2e6ef;
            --ws-border-soft: #eef0f6;
            --ws-primary: #002147;
            --ws-primary-mid: #05356b;
            --ws-accent: #1a6ef7;
            --ws-accent-soft: #e8f0fe;
            --ws-success: #059669;
            --ws-success-bg: #ecfdf5;
            --ws-warn: #d97706;
            --ws-warn-bg: #fffbeb;
            --ws-danger: #dc2626;
            --ws-danger-bg: #fef2f2;
            --ws-text: #111827;
            --ws-text-2: #374151;
            --ws-text-3: #6b7280;
            --ws-text-4: #9ca3af;
            --ws-mono: 'IBM Plex Mono', monospace;
            --ws-sans: 'Sora', sans-serif;
            --ws-radius: 10px;
            --ws-radius-lg: 14px;
            --ws-shadow: 0 1px 4px rgba(0, 0, 0, .07), 0 4px 18px rgba(0, 0, 0, .04);
            --ws-shadow-md: 0 2px 8px rgba(0, 0, 0, .09), 0 8px 28px rgba(0, 0, 0, .06);
        }

        /* ─── Page Wrapper ─── */
        .ws-page {
            font-family: var(--ws-sans);
            background: var(--ws-bg);
            min-height: 100vh;
            padding: 15px;
        }

        /* ─── Command Bar ─── */
        .ws-command-bar {
            background: #05356b;
            border-bottom: 3px solid var(--ws-accent);
            padding: 14px 24px;
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .ws-command-bar .ws-title {
            font-family: var(--ws-sans);
            font-weight: 700;
            font-size: 1.05rem;
            color: #fff;
            letter-spacing: -.01em;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }

        .ws-command-bar .ws-title i {
            color: #7db3ff;
            font-size: .95rem;
        }

        .ws-command-bar .ws-stats-pills {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            flex: 1;
        }

        .ws-stat-pill {
            background: rgba(255, 255, 255, .1);
            border: 1px solid rgba(255, 255, 255, .15);
            border-radius: 20px;
            padding: 4px 12px;
            font-size: .72rem;
            color: rgba(255, 255, 255, .85);
            display: flex;
            align-items: center;
            gap: 6px;
            font-family: var(--ws-mono);
        }

        .ws-stat-pill strong {
            color: #fff;
        }

        .ws-command-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-shrink: 0;
        }

        .ws-btn-cmd {
            font-size: .78rem;
            padding: 8px 16px;
            border-radius: 6px;
            font-family: var(--ws-sans);
            font-weight: 500;
            border: 1px solid rgba(255, 255, 255, .2);
            background: rgba(255, 255, 255, .08);
            color: #fff;
            cursor: pointer;
            transition: all .15s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .ws-btn-cmd:hover {
            background: rgba(255, 255, 255, .18);
            color: #fff;
        }

        .ws-btn-cmd.ws-btn-cmd--accent {
            background: linear-gradient(135deg, #002147 0%, #05356b 100%);
            border-color: #002147;
        }

        .ws-btn-cmd.ws-btn-cmd--accent:hover {
            background: linear-gradient(135deg, #05356b 0%, #002147 100%);
        }

        /* ─── Alert Banner ─── */
        .ws-alert {
            margin: 16px 24px 0;
            padding: 12px 18px;
            border-radius: var(--ws-radius);
            font-size: .85rem;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
        }

        .ws-alert--success {
            background: var(--ws-success-bg);
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .ws-alert--error {
            background: var(--ws-danger-bg);
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .ws-alert i {
            flex-shrink: 0;
        }

        /* ─── Main Layout ─── */
        .ws-body {
            display: flex;
            gap: 0;
            height: 100%;
            overflow: hidden;
            padding: 10px;
        }

        /* ─── Left Navigator Panel ─── */
        .ws-nav-panel {
            width: 220px;
            flex-shrink: 0;
            background: var(--ws-surface);
            border-right: 1px solid var(--ws-border);
            display: flex;
            flex-direction: column;
            overflow-y: auto;
        }

        .ws-nav-section {
            padding: 16px 12px 6px;
        }

        .ws-nav-section-label {
            font-size: .65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--ws-text-4);
            padding: 0 6px;
            margin-bottom: 4px;
        }

        .ws-nav-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 10px;
            border-radius: 7px;
            cursor: pointer;
            font-size: .82rem;
            font-weight: 500;
            color: var(--ws-text-2);
            text-decoration: none;
            transition: all .13s;
            position: relative;
        }

        .ws-nav-item:hover {
            background: var(--ws-accent-soft);
            color: var(--ws-accent);
        }

        /* Only apply active styles to nav items with data-tab attribute */
        .ws-nav-item[data-tab].active {
            background: var(--ws-accent-soft);
            color: var(--ws-accent);
            font-weight: 600;
        }

        .ws-nav-item[data-tab].active::before {
            content: '';
            position: absolute;
            left: -1px;
            top: 20%;
            height: 60%;
            width: 3px;
            background: var(--ws-accent);
            border-radius: 0 3px 3px 0;
        }

        /* Prevent action buttons and stats from showing active state */
        .ws-nav-item.ws-nav-action.active,
        .ws-nav-item.ws-nav-stat.active {
            background: transparent;
            color: var(--ws-text-2);
            font-weight: 500;
        }

        .ws-nav-item.ws-nav-action.active::before,
        .ws-nav-item.ws-nav-stat.active::before {
            display: none;
        }

        .ws-nav-item i {
            width: 18px;
            text-align: center;
            font-size: .8rem;
            flex-shrink: 0;
        }

        .ws-nav-badge {
            margin-left: auto;
            background: var(--ws-accent-soft);
            color: var(--ws-accent);
            border-radius: 10px;
            font-size: .65rem;
            padding: 1px 7px;
            font-weight: 700;
            font-family: var(--ws-mono);
        }

        .ws-nav-divider {
            height: 1px;
            background: var(--ws-border-soft);
            margin: 8px 12px;
        }

        /* ─── Main Content Area ─── */
        .ws-content-area {
            flex: 1;
            overflow-y: auto;
            background: var(--ws-bg);
            padding: 10px;
        }

        /* ─── Section Header ─── */
        .ws-section-header {
            background: var(--ws-surface);
            border-bottom: 1px solid var(--ws-border);
            padding: 18px 28px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .ws-section-header .ws-section-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--ws-text);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .ws-section-header .ws-section-title i {
            width: 32px;
            height: 32px;
            background: var(--ws-primary);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: .8rem;
            flex-shrink: 0;
        }

        .ws-section-subtitle {
            font-size: .78rem;
            color: var(--ws-text-3);
            font-weight: 400;
            margin-top: 1px;
            margin-left: 42px;
        }

        /* ─── Panels / Cards ─── */
        .ws-panel {
            background: var(--ws-surface);
            border-radius: var(--ws-radius-lg);
            border: 1px solid var(--ws-border);
            box-shadow: var(--ws-shadow);
            overflow: hidden;
            margin-bottom: 20px;
        }

        .ws-panel-header {
            padding: 14px 20px;
            border-bottom: 1px solid var(--ws-border-soft);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            background: var(--ws-surface-2);
        }

        .ws-panel-header .ws-panel-title {
            font-size: .88rem;
            font-weight: 700;
            color: var(--ws-text);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .ws-panel-header .ws-panel-title i {
            color: var(--ws-accent);
        }

        .ws-panel-body {
            padding: 20px;
        }

        /* ─── Stat Grid ─── */
        .ws-stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
        }

        .ws-stat-card {
            background: var(--ws-surface);
            border: 1px solid var(--ws-border);
            border-radius: var(--ws-radius);
            padding: 20px 22px;
            display: flex;
            align-items: flex-start;
            gap: 16px;
            transition: box-shadow .15s;
            cursor: default;
        }

        .ws-stat-card:hover {
            box-shadow: var(--ws-shadow-md);
        }

        .ws-stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--ws-primary), var(--ws-primary-mid));
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.15rem;
            flex-shrink: 0;
        }

        .ws-stat-body .ws-stat-value {
            font-size: 1.95rem;
            font-weight: 700;
            color: var(--ws-text);
            line-height: 1;
            font-family: var(--ws-mono);
        }

        .ws-stat-body .ws-stat-label {
            font-size: .8rem;
            color: var(--ws-text-3);
            margin-top: 5px;
            font-weight: 500;
        }

        /* ─── Content Panes ─── */
        .ws-pane {
            display: none;
            height: 100%;
        }

        .ws-pane.active {
            display: flex;
            flex-direction: column;
        }

        .ws-pane-inner {
            flex: 1;
            overflow-y: auto;
            padding: 24px 28px;
        }

        .filter-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 14px;
            flex-wrap: wrap;
            align-items: center;
        }

        .filter-bar input,
        .filter-bar select {
            padding: 6px 12px;
            border: 1px solid #334155;
            border-radius: 6px;
            background: #f5f5f5ff;
            color: #19191aff;
            font-size: 0.85rem;
        }

        .filter-bar .btn {
            font-size: 0.85rem;
        }

        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .status-new {
            background: #fef3c7;
            color: #92400e;
        }

        .status-updated {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-processed {
            background: #d1fae5;
            color: #065f46;
        }

        .status-indexed {
            background: #ede9fe;
            color: #5b21b6;
        }

        .status-failed {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-duplicate {
            background: #f1f5f9;
            color: #475569;
        }

        .enrich-pending { background: #fef3c7; color: #92400e; }
        .enrich-done { background: #d1fae5; color: #065f46; }
        .enrich-failed { background: #fee2e2; color: #991b1b; }
        .enrich-skipped { background: #f1f5f9; color: #64748b; }

        .enrichment-panel {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 14px;
            margin: 12px 0;
            font-size: 0.88rem;
        }
        .enrichment-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
        .enrichment-tag {
            background: #e0f2fe;
            color: #0369a1;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.72rem;
        }

        /* Content modal — clean light theme */
        .content-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.45);
            backdrop-filter: blur(4px);
            z-index: 10000;
            padding: 30px;
            overflow-y: auto;
        }

        .content-modal-box {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            max-width: 900px;
            margin: 0 auto;
            padding: 32px;
            position: relative;
            color: #1e293b;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
        }

        .content-modal-box .close-btn {
            position: absolute;
            top: 14px;
            right: 18px;
            font-size: 1.5rem;
            color: #64748b;
            cursor: pointer;
            background: none;
            border: none;
            transition: color 0.2s;
        }

        .content-modal-box .close-btn:hover {
            color: #1e293b;
        }

        .content-modal-box h2 {
            margin: 0 0 6px;
            font-size: 1.25rem;
            color: #0f172a;
        }

        .content-modal-box .meta-row {
            font-size: 0.8rem;
            color: #64748b;
            margin-bottom: 4px;
        }

        .content-modal-box .meta-row span {
            color: #334155;
            font-weight: 500;
        }

        .content-modal-box .content-body {
            margin-top: 16px;
            background: #f8fafc;
            border-radius: 10px;
            padding: 18px;
            white-space: pre-wrap;
            font-size: 0.88rem;
            line-height: 1.6;
            max-height: 50vh;
            overflow-y: auto;
            border: 1px solid #e2e8f0;
            color: #334155;
        }

        .content-modal-box .history-section {
            margin-top: 20px;
            border-top: 1px solid #e2e8f0;
            padding-top: 16px;
        }

        .content-modal-box .history-item {
            background: #f8fafc;
            border-radius: 10px;
            padding: 14px;
            margin-bottom: 10px;
            border-left: 3px solid #3b82f6;
        }

        .content-modal-box .history-item .hist-date {
            font-size: 0.78rem;
            color: #64748b;
            margin-bottom: 6px;
        }

        .content-modal-box .history-item .hist-content {
            font-size: 0.84rem;
            max-height: 150px;
            overflow-y: auto;
            white-space: pre-wrap;
            color: #475569;
        }

        /* Dismissible banners */
        .dismiss-banner {
            position: relative;
        }

        .dismiss-banner .dismiss-btn {
            position: absolute;
            top: 8px;
            right: 12px;
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            opacity: 0.6;
            line-height: 1;
        }

        .dismiss-banner .dismiss-btn:hover {
            opacity: 1;
        }

        /* Scrape Result Panel */
        .scrape-result-panel {
            margin-bottom: 18px;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #334155;
        }

        .scrape-result-panel.result-success {
            border-color: #10b981;
        }

        .scrape-result-panel.result-error {
            border-color: #ef4444;
        }

        .scrape-summary {
            padding: 18px 22px;
            display: flex;
            align-items: center;
            gap: 18px;
            flex-wrap: wrap;
        }

        .result-success .scrape-summary {
            background: linear-gradient(135deg, #064e3b 0%, #0f172a 100%);
        }

        .result-error .scrape-summary {
            background: linear-gradient(135deg, #7f1d1d 0%, #0f172a 100%);
        }

        .scrape-summary .summary-icon {
            font-size: 2rem;
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            flex-shrink: 0;
        }

        .result-success .summary-icon {
            background: rgba(16, 185, 129, 0.2);
            color: #34d399;
        }

        .result-error .summary-icon {
            background: rgba(239, 68, 68, 0.2);
            color: #f87171;
        }

        .scrape-summary .summary-text {
            flex: 1;
            min-width: 200px;
        }

        .scrape-summary .summary-text h3 {
            margin: 0 0 4px;
            font-size: 1.1rem;
            color: #f1f5f9;
        }

        .scrape-summary .summary-text p {
            margin: 0;
            font-size: 0.82rem;
            color: #94a3b8;
        }

        .scrape-stats-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            padding: 0 22px 14px;
            background: #0f172a;
        }

        .scrape-stats-row .mini-stat {
            background: #1e293b;
            border-radius: 8px;
            padding: 8px 14px;
            text-align: center;
            min-width: 80px;
        }

        .mini-stat .mini-num {
            font-size: 1.3rem;
            font-weight: 700;
            color: #e2e8f0;
        }

        .mini-stat .mini-label {
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #64748b;
        }

        .mini-stat.ms-new .mini-num {
            color: #fbbf24;
        }

        .mini-stat.ms-updated .mini-num {
            color: #60a5fa;
        }

        .mini-stat.ms-unchanged .mini-num {
            color: #94a3b8;
        }

        .mini-stat.ms-skipped .mini-num {
            color: #64748b;
        }

        .mini-stat.ms-failed .mini-num {
            color: #f87171;
        }

        .mini-stat.ms-time .mini-num {
            color: #a78bfa;
        }

        .scrape-actions {
            display: flex;
            gap: 8px;
            padding: 0 22px 16px;
            background: #0f172a;
        }

        .scrape-actions .btn {
            font-size: 0.8rem;
        }

        .scrape-log-container {
            max-height: 400px;
            overflow-y: auto;
            background: #0a0f1a;
            border-top: 1px solid #1e293b;
            font-family: 'Courier New', monospace;
            font-size: 0.78rem;
            line-height: 1.6;
            padding: 0;
        }

        .scrape-log-container .log-line {
            padding: 3px 22px;
            border-bottom: 1px solid #111827;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .scrape-log-container .log-line:hover {
            background: #1e293b;
            white-space: normal;
            word-break: break-all;
        }

        .log-line.lt-success {
            color: #34d399;
        }

        .log-line.lt-error {
            color: #f87171;
            font-weight: 600;
        }

        .log-line.lt-update {
            color: #60a5fa;
        }

        .log-line.lt-skipped {
            color: #64748b;
        }

        .log-line.lt-info {
            color: #94a3b8;
        }

        /* Scrape Progress Bar */
        .scrape-progress-wrapper {
            display: none;
            margin: 18px 0;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 12px;
            padding: 24px;
        }

        .scrape-progress-wrapper.active {
            display: block;
        }

        .scrape-progress-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }

        .scrape-progress-header .spinner {
            width: 24px;
            height: 24px;
            border: 3px solid #334155;
            border-top-color: #3b82f6;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .scrape-progress-header h3 {
            margin: 0;
            font-size: 1rem;
            color: #e2e8f0;
        }

        .scrape-progress-header .elapsed {
            margin-left: auto;
            font-size: 0.85rem;
            color: #94a3b8;
            font-variant-numeric: tabular-nums;
        }

        .progress-track {
            height: 8px;
            background: #1e293b;
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #3b82f6, #8b5cf6, #3b82f6);
            background-size: 200% 100%;
            animation: shimmer 1.5s ease-in-out infinite;
            border-radius: 4px;
            width: 100%;
        }

        @keyframes shimmer {
            0% {
                background-position: 200% 0;
            }

            100% {
                background-position: -200% 0;
            }
        }

        .progress-status {
            margin-top: 10px;
            font-size: 0.82rem;
            color: #64748b;
        }

        /* Toast Notification System */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 99999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            pointer-events: none;
        }

        .toast-notification {
            pointer-events: auto;
            min-width: 340px;
            max-width: 480px;
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            border: 1px solid #334155;
            border-radius: 12px;
            padding: 16px 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
            display: flex;
            align-items: flex-start;
            gap: 12px;
            transform: translateX(120%);
            animation: toastSlideIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        .toast-notification.toast-dismiss {
            animation: toastSlideOut 0.3s ease-in forwards;
        }

        @keyframes toastSlideIn {
            to {
                transform: translateX(0);
            }
        }

        @keyframes toastSlideOut {
            to {
                transform: translateX(120%);
                opacity: 0;
            }
        }

        .toast-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .toast-success .toast-icon {
            background: rgba(16, 185, 129, 0.2);
            color: #34d399;
        }

        .toast-error .toast-icon {
            background: rgba(239, 68, 68, 0.2);
            color: #f87171;
        }

        .toast-info .toast-icon {
            background: rgba(59, 130, 246, 0.2);
            color: #60a5fa;
        }

        .toast-warning .toast-icon {
            background: rgba(245, 158, 11, 0.2);
            color: #fbbf24;
        }

        .toast-body {
            flex: 1;
            min-width: 0;
        }

        .toast-title {
            font-size: 0.92rem;
            font-weight: 600;
            color: #f1f5f9;
            margin-bottom: 2px;
        }

        .toast-message {
            font-size: 0.8rem;
            color: #94a3b8;
            line-height: 1.4;
        }

        .toast-close {
            background: none;
            border: none;
            color: #64748b;
            font-size: 1.2rem;
            cursor: pointer;
            padding: 0;
            line-height: 1;
            flex-shrink: 0;
        }

        .toast-close:hover {
            color: #e2e8f0;
        }

        .toast-progress {
            position: absolute;
            bottom: 0;
            left: 0;
            height: 3px;
            border-radius: 0 0 12px 12px;
            animation: toastTimer linear forwards;
        }

        .toast-success .toast-progress {
            background: #10b981;
        }

        .toast-error .toast-progress {
            background: #ef4444;
        }

        .toast-info .toast-progress {
            background: #3b82f6;
        }

        .toast-warning .toast-progress {
            background: #f59e0b;
        }

        @keyframes toastTimer {
            from {
                width: 100%;
            }

            to {
                width: 0%;
            }
        }

        /* Source Modal Styling */
        .source-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(8px);
            z-index: 10000;
            padding: 30px;
            overflow-y: auto;
            align-items: center;
            justify-content: center;
        }

        .source-modal-box {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            width: 95%;
            max-width: 800px;
            margin: auto;
            padding: 32px;
            position: relative;
            color: #1e293b;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
            animation: modalSlideIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes modalSlideIn {
            from {
                transform: translateY(20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .source-modal-box .close-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            font-size: 1.25rem;
            color: #64748b;
            cursor: pointer;
            background: none;
            border: none;
            transition: color 0.2s, transform 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 8px;
        }

        .source-modal-box .close-btn:hover {
            color: #0f172a;
            background: #f1f5f9;
        }

        .source-modal-box h2 {
            margin: 0 0 24px;
            font-size: 1.45rem;
            color: #0f172a;
            font-weight: 700;
            border-bottom: 2px solid #f1f5f9;
            padding-bottom: 16px;
            display: flex;
            align-items: center;
        }

        .source-form-group {
            margin-bottom: 20px;
        }

        .source-form-group label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            color: #334155;
            margin-bottom: 8px;
        }

        .source-form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 0.95rem;
            color: #1e293b;
            transition: all 0.2s;
            background: #f8fafc;
        }

        .source-form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
            background: #ffffff;
        }

        .source-form-group small {
            display: block;
            margin-top: 6px;
            font-size: 0.8rem;
            color: #64748b;
        }

        .source-checkbox-group {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 16px;
            background: #f8fafc;
            padding: 14px 16px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s;
        }

        .source-checkbox-group:hover {
            border-color: #cbd5e1;
            background: #f1f5f9;
        }

        .source-checkbox-group input[type="checkbox"] {
            margin-top: 2px;
            width: 18px;
            height: 18px;
            accent-color: #3b82f6;
            cursor: pointer;
        }

        .source-checkbox-group .checkbox-title {
            margin: 0;
            font-size: 0.95rem;
            font-weight: 600;
            color: #1e293b;
        }

        .source-checkbox-group span {
            display: block;
            font-size: 0.82rem;
            color: #64748b;
            margin-top: 2px;
        }

        .source-form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid #e2e8f0;
        }

        .source-btn {
            padding: 12px 28px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
        }

        .source-btn-cancel {
            background: #f1f5f9;
            color: #475569;
        }

        .source-btn-cancel:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        .source-btn-submit {
            background: linear-gradient(135deg, #002147 0%, #05356b 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(0, 33, 71, 0.2);
        }

        .source-btn-submit:hover {
            background: linear-gradient(135deg, #05356b 0%, #002147 100%);
            box-shadow: 0 6px 16px rgba(0, 33, 71, 0.3);
            transform: translateY(-1px);
        }

        .source-advanced-options {
            margin-bottom: 24px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
            background: #fff;
        }

        .source-advanced-options summary {
            padding: 14px 16px;
            background: #f8fafc;
            color: #334155;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            list-style: none;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: background 0.2s;
        }

        .source-advanced-options summary::-webkit-details-marker {
            display: none;
        }

        .source-advanced-options summary:hover {
            background: #f1f5f9;
        }

        .source-advanced-options[open] summary {
            border-bottom: 1px solid #e2e8f0;
        }

        .advanced-options-content {
            padding: 20px 16px 4px;
        }
        
        /* ─── Buttons ─── */
        .ws-btn, .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 20px;
            border-radius: 7px;
            font-size: .8rem;
            font-weight: 600;
            font-family: var(--ws-sans);
            cursor: pointer;
            border: 1px solid transparent;
            transition: all .13s;
            text-decoration: none;
            white-space: nowrap;
            background: linear-gradient(135deg, #002147 0%, #05356b 100%);
            color: #fff;
        }

        .ws-btn:hover, .btn:hover {
            background: linear-gradient(135deg, #05356b 0%, #002147 100%);
            color: #fff;
            transform: translateY(-1px);
        }

        .tab-btn {
            padding: 12px 24px !important;
        }

        .ws-btn--primary, .btn-primary {
            background: linear-gradient(135deg, #002147 0%, #05356b 100%);
            color: #fff;
            border: none;
        }

        .ws-btn--primary:hover, .btn-primary:hover {
            background: linear-gradient(135deg, #05356b 0%, #002147 100%);
            color: #fff;
        }

        .ws-btn--outline, .btn-secondary, .btn-info, .btn-warning, .btn-success {
            background: linear-gradient(135deg, #002147 0%, #05356b 100%);
            color: #fff;
            border: none;
        }

        .ws-btn--outline:hover, .btn-secondary:hover, .btn-info:hover, .btn-warning:hover, .btn-success:hover {
            background: linear-gradient(135deg, #05356b 0%, #002147 100%);
            color: #fff;
        }

        /* Only delete/danger buttons are different */
        .ws-btn--danger, .btn-danger {
            background: linear-gradient(135deg, #991b1b 0%, #7f1d1d 100%);
            color: #fca5a5;
            border: 1px solid #dc2626;
        }

        .ws-btn--danger:hover, .btn-danger:hover {
            background: linear-gradient(135deg, #b91c1c 0%, #991b1b 100%);
            color: #fff;
        }

        .ws-btn--sm, .btn-sm {
            padding: 12px;
            font-size: .75rem;
        }

        /* ─── Table ─── */
        .ws-table-wrap {
            overflow-x: auto;
        }

        .ws-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .82rem;
        }

        .ws-table thead th {
            background: var(--ws-surface-2);
            border-bottom: 2px solid var(--ws-border);
            padding: 10px 14px;
            text-align: left;
            font-size: .7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--ws-text-3);
            white-space: nowrap;
        }

        .ws-table tbody td {
            padding: 10px 14px;
            border-bottom: 1px solid var(--ws-border-soft);
            vertical-align: middle;
            color: var(--ws-text-2);
        }

        .ws-table tbody tr:hover td {
            background: var(--ws-bg);
        }

        /* ─── Badges ─── */
        .ws-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: .68rem;
            font-weight: 600;
            font-family: var(--ws-mono);
        }

        .ws-badge--green {
            background: var(--ws-success-bg);
            color: var(--ws-success);
        }

        .ws-badge--yellow {
            background: var(--ws-warn-bg);
            color: var(--ws-warn);
        }

        .ws-badge--red {
            background: var(--ws-danger-bg);
            color: var(--ws-danger);
        }

        /* Keep existing styles for modals and specific components */
        .filter-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 14px;
            flex-wrap: wrap;
            align-items: center;
        }

        .filter-bar input,
        .filter-bar select {
            padding: 6px 12px;
            border: 1px solid #334155;
            border-radius: 6px;
            background: #f5f5f5ff;
            color: #19191aff;
            font-size: 0.85rem;
        }

        .filter-bar .btn {
            font-size: 0.85rem;
        }

        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .status-new {
            background: #fef3c7;
            color: #92400e;
        }

        .status-updated {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-processed {
            background: #d1fae5;
            color: #065f46;
        }

        .status-indexed {
            background: #ede9fe;
            color: #5b21b6;
        }

        .status-failed {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-duplicate {
            background: #f1f5f9;
            color: #475569;
        }

        .enrich-pending { background: #fef3c7; color: #92400e; }
        .enrich-done { background: #d1fae5; color: #065f46; }
        .enrich-failed { background: #fee2e2; color: #991b1b; }
        .enrich-skipped { background: #f1f5f9; color: #64748b; }

        .enrichment-panel {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 14px;
            margin: 12px 0;
            font-size: 0.88rem;
        }
        .enrichment-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
        .enrichment-tag {
            background: #e0f2fe;
            color: #0369a1;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.72rem;
        }

        /* Content modal — clean light theme */
        .content-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.45);
            backdrop-filter: blur(4px);
            z-index: 10000;
            padding: 30px;
            overflow-y: auto;
        }

        .content-modal-box {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            max-width: 900px;
            margin: 0 auto;
            padding: 32px;
            position: relative;
            color: #1e293b;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
        }

        .content-modal-box .close-btn {
            position: absolute;
            top: 14px;
            right: 18px;
            font-size: 1.5rem;
            color: #64748b;
            cursor: pointer;
            background: none;
            border: none;
            transition: color 0.2s;
        }

        .content-modal-box .close-btn:hover {
            color: #1e293b;
        }

        .content-modal-box h2 {
            margin: 0 0 6px;
            font-size: 1.25rem;
            color: #0f172a;
        }

        .content-modal-box .meta-row {
            font-size: 0.8rem;
            color: #64748b;
            margin-bottom: 4px;
        }

        .content-modal-box .meta-row span {
            color: #334155;
            font-weight: 500;
        }

        .content-modal-box .content-body {
            margin-top: 16px;
            background: #f8fafc;
            border-radius: 10px;
            padding: 18px;
            white-space: pre-wrap;
            font-size: 0.88rem;
            line-height: 1.6;
            max-height: 50vh;
            overflow-y: auto;
            border: 1px solid #e2e8f0;
            color: #334155;
        }

        .content-modal-box .history-section {
            margin-top: 20px;
            border-top: 1px solid #e2e8f0;
            padding-top: 16px;
        }

        .content-modal-box .history-item {
            background: #f8fafc;
            border-radius: 10px;
            padding: 14px;
            margin-bottom: 10px;
            border-left: 3px solid #3b82f6;
        }

        .content-modal-box .history-item .hist-date {
            font-size: 0.78rem;
            color: #64748b;
            margin-bottom: 6px;
        }

        .content-modal-box .history-item .hist-content {
            font-size: 0.84rem;
            max-height: 150px;
            overflow-y: auto;
            white-space: pre-wrap;
            color: #475569;
        }

        /* Toast Notification System */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 99999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            pointer-events: none;
        }

        .toast-notification {
            pointer-events: auto;
            min-width: 340px;
            max-width: 480px;
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            border: 1px solid #334155;
            border-radius: 12px;
            padding: 16px 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
            display: flex;
            align-items: flex-start;
            gap: 12px;
            transform: translateX(120%);
            animation: toastSlideIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        .toast-notification.toast-dismiss {
            animation: toastSlideOut 0.3s ease-in forwards;
        }

        @keyframes toastSlideIn {
            to {
                transform: translateX(0);
            }
        }

        @keyframes toastSlideOut {
            to {
                transform: translateX(120%);
                opacity: 0;
            }
        }

        /* Source Modal Styling */
        .source-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(8px);
            z-index: 10000;
            padding: 30px;
            overflow-y: auto;
            align-items: center;
            justify-content: center;
        }

        .source-modal-box {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            width: 95%;
            max-width: 800px;
            margin: auto;
            padding: 32px;
            position: relative;
            color: #1e293b;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
            animation: modalSlideIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes modalSlideIn {
            from {
                transform: translateY(20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .source-modal-box .close-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            font-size: 1.25rem;
            color: #64748b;
            cursor: pointer;
            background: none;
            border: none;
            transition: color 0.2s, transform 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 8px;
        }

        .source-modal-box .close-btn:hover {
            color: #0f172a;
            background: #f1f5f9;
        }

        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .ws-nav-panel {
                display: none;
            }

            .ws-stat-grid {
                grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            }

            .ws-command-bar {
                flex-direction: column;
                align-items: flex-start;
            }
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
            <div class="sb2-2 col-md-9" style="padding:0;">

                <!-- =================== WEB SCRAPER APP =================== -->
                <div class="ws-page">

                    <!-- ── Command Bar ── -->
                    <div class="ws-command-bar">
                        <div class="ws-title">
                            <i class="fa-solid fa-spider"></i>
                            Web Scraper Management
                        </div>
                        <div class="ws-stats-pills">
                            <div class="ws-stat-pill"><strong><?= $stats['total'] ?></strong> total pages</div>
                            <div class="ws-stat-pill"><strong><?= $stats['new'] ?? 0 ?></strong> new</div>
                            <div class="ws-stat-pill"><strong><?= $stats['updated'] ?? 0 ?></strong> updated</div>
                            <div class="ws-stat-pill"><strong><?= $stats['processed'] ?? 0 ?></strong> processed</div>
                            <div class="ws-stat-pill"><strong><?= count($sources) ?></strong> sources</div>
                        </div>
                        <div class="ws-command-actions">
                            <a href="entity_manage.php" class="ws-btn-cmd"><i class="fa-solid fa-database"></i> Entity Manager</a>
                            <button class="ws-btn-cmd ws-btn-cmd--accent" onclick="showCreateModal()"><i class="fa-solid fa-plus"></i> Add Source</button>
                            <button class="ws-btn-cmd" onclick="enrichKB(this, {reloadOnSuccess:true})"><i class="fa-solid fa-wand-magic-sparkles"></i> Enrich Batch</button>
                            <button class="ws-btn-cmd" onclick="reindexKB(this, {reloadOnSuccess:false})"><i class="fa-solid fa-rotate"></i> Rebuild Index</button>
                        </div>
                    </div>

                    <!-- ── Alerts ── -->
                    <?php if ($notice): ?>
                        <div class="ws-alert ws-alert--success"><i class="fa-solid fa-circle-check"></i><?= htmlspecialchars($notice) ?></div>
                    <?php endif; ?>
                    <?php if ($error): ?>
                        <div class="ws-alert ws-alert--error"><i class="fa-solid fa-circle-xmark"></i><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>
                    
                    <!-- Toast Container -->
                    <div class="toast-container" id="toastContainer"></div>

                    <!-- ── App Body ── -->
                    <div class="ws-body">

                        <!-- ════ LEFT NAVIGATOR ════ -->
                        <nav class="ws-nav-panel">
                            <div class="ws-nav-section">
                                <div class="ws-nav-section-label">Views</div>
                                <a class="ws-nav-item" data-tab="scrapedContentTab"
                                    href="javascript:void(0)" onclick="switchDataTab('scrapedContentTab', this)">
                                    <i class="fa-solid fa-globe"></i> Scraped Content
                                    <span class="ws-nav-badge"><?= count($recent_scraped) ?></span>
                                </a>
                                <a class="ws-nav-item" data-tab="sourcesTab"
                                    href="javascript:void(0)" onclick="switchDataTab('sourcesTab', this)">
                                    <i class="fa-solid fa-spider"></i> Sources
                                    <span class="ws-nav-badge"><?= count($sources) ?></span>
                                </a>
                                <a class="ws-nav-item" data-tab="blockedUrlsTab"
                                    href="javascript:void(0)" onclick="switchDataTab('blockedUrlsTab', this)">
                                    <i class="fa-solid fa-ban"></i> Blocked URLs
                                </a>
                                <a class="ws-nav-item" data-tab="activityLogTab"
                                    href="javascript:void(0)" onclick="switchDataTab('activityLogTab', this)">
                                    <i class="fa-solid fa-clock-rotate-left"></i> Activity Log
                                    <span class="ws-nav-badge"><?= count($admin_logs) ?></span>
                                </a>
                                <a class="ws-nav-item" data-tab="missingLinksTab"
                                    href="javascript:void(0)" onclick="switchDataTab('missingLinksTab', this)">
                                    <i class="fa-solid fa-link-slash"></i> Missing Links Queue
                                </a>
                                <a class="ws-nav-item" data-tab="statisticsTab"
                                    href="javascript:void(0)" onclick="switchDataTab('statisticsTab', this)">
                                    <i class="fa-solid fa-chart-bar"></i> Statistics
                                </a>
                            </div>
                            <div class="ws-nav-divider"></div>
                            <div class="ws-nav-section">
                                <div class="ws-nav-section-label">Actions</div>
                                <a class="ws-nav-item ws-nav-action" href="javascript:void(0)" onclick="showCreateModal()">
                                    <i class="fa-solid fa-plus"></i> New Source
                                </a>
                                <a class="ws-nav-item ws-nav-action" href="javascript:void(0)" onclick="runAllScrapes()">
                                    <i class="fa-solid fa-play"></i> Scrape All
                                </a>
                                <a class="ws-nav-item ws-nav-action" href="javascript:void(0)" onclick="enrichAndReindexKB(this, {reloadOnSuccess:true})">
                                    <i class="fa-solid fa-wand-magic-sparkles"></i> Enrich All
                                </a>
                            </div>
                        </nav>

                        <!-- ════ MAIN CONTENT ════ -->
                        <div class="ws-content-area" id="wsContentArea">

                    <!-- AJAX Progress Bar -->
                    <style>
                        .scrape-progress-wrapper {
                            display: none;
                            margin: 18px 0;
                            background: linear-gradient(135deg, #002147 0%, #05356b 100%);
                            border: 1px solid #334155;
                            border-radius: 12px;
                            padding: 24px;
                            box-shadow: 0 10px 30px rgba(0,0,0,0.15), inset 0 1px 0 rgba(255,255,255,0.05);
                            position: relative;
                            overflow: hidden;
                        }
                        
                        .scrape-progress-wrapper::before {
                            content: '';
                            position: absolute;
                            top: 0; left: 0; right: 0;
                            height: 2px;
                            background: linear-gradient(90deg, transparent, #3b82f6, transparent);
                            animation: pulse-line 2s infinite ease-in-out;
                        }

                        @keyframes pulse-line {
                            0% { transform: translateX(-100%); opacity: 0; }
                            50% { opacity: 1; }
                            100% { transform: translateX(100%); opacity: 0; }
                        }

                        .scrape-progress-wrapper.active {
                            display: block;
                            animation: fade-in 0.3s ease-out forwards;
                        }
                        
                        @keyframes fade-in {
                            from { opacity: 0; transform: translateY(10px); }
                            to { opacity: 1; transform: translateY(0); }
                        }

                        .scrape-progress-header {
                            display: flex;
                            align-items: center;
                            gap: 12px;
                            margin-bottom: 16px;
                        }

                        .scrape-progress-header .spinner {
                            width: 24px;
                            height: 24px;
                            border: 3px solid #334155;
                            border-top-color: #3b82f6;
                            border-radius: 50%;
                            animation: spin 0.8s linear infinite;
                        }

                        .scrape-progress-header h3 {
                            margin: 0;
                            font-size: 1.15rem;
                            color: #f1f5f9;
                            font-weight: 600;
                            letter-spacing: -0.01em;
                            display: flex;
                            align-items: center;
                            gap: 8px;
                        }
                        
                        .scrape-progress-header h3::after {
                            content: '';
                            display: inline-block;
                            width: 6px;
                            height: 6px;
                            background: #10b981;
                            border-radius: 50%;
                            border: 2px solid #0f172a;
                            box-shadow: 0 0 0 2px #10b98144;
                            animation: pulse-dot 2s linear infinite;
                        }

                        @keyframes pulse-dot {
                            0% { box-shadow: 0 0 0 0 rgba(16,185,129,0.7); }
                            70% { box-shadow: 0 0 0 6px rgba(16,185,129,0); }
                            100% { box-shadow: 0 0 0 0 rgba(16,185,129,0); }
                        }

                        .scrape-progress-header .elapsed {
                            margin-left: auto;
                            font-size: 0.9rem;
                            color: #94a3b8;
                            font-variant-numeric: tabular-nums;
                            background: rgba(255,255,255,0.05);
                            padding: 4px 10px;
                            border-radius: 6px;
                            border: 1px solid rgba(255,255,255,0.05);
                        }

                        .progress-status {
                            font-size: 0.85rem;
                            color: #cbd5e1;
                            font-weight: 500;
                            background: #1e293b;
                            display: inline-block;
                            padding: 4px 12px;
                            border-radius: 12px;
                            border: 1px solid #334155;
                            margin-top: 10px;
                        }
                        
                        .btn-terminate-scrape {
                            background: linear-gradient(135deg, #991b1b 0%, #7f1d1d 100%);
                            color: #fca5a5;
                            border: 1px solid #dc2626;
                            font-weight: 600;
                            padding: 12px 28px;
                            border-radius: 8px;
                            display: inline-flex;
                            align-items: center;
                            gap: 10px;
                            margin: 0 auto;
                            cursor: pointer;
                            transition: all 0.2s ease;
                            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.2);
                        }
                        
                        .btn-terminate-scrape:hover {
                            background: linear-gradient(135deg, #b91c1c 0%, #991b1b 100%);
                            color: #fff;
                            transform: translateY(-1px);
                            box-shadow: 0 6px 16px rgba(220, 38, 38, 0.3);
                        }
                        
                        .btn-terminate-scrape:active {
                            transform: translateY(1px);
                            box-shadow: 0 2px 4px rgba(220, 38, 38, 0.2);
                        }
                        
                        .btn-terminate-scrape:disabled {
                            opacity: 0.7;
                            cursor: not-allowed;
                            transform: none;
                        }
                    </style>
                    <div class="scrape-progress-wrapper" id="scrapeProgressWrapper">
                        <div class="scrape-progress-header">
                            <div class="spinner"></div>
                            <h3>Scraping in progress...</h3>
                            <span class="elapsed" id="scrapeElapsed">0s</span>
                        </div>
                        <div class="progress-status" id="scrapeProgressStatus">Starting scraper...</div>
                        <div style="text-align:center;margin-top:20px;padding-top:16px;border-top:1px dashed #334155;">
                            <button id="btnTerminateScrape" class="btn btn-terminate-scrape" onclick="terminateScrape()">
                                <i class="fa-solid fa-stop-circle" style="font-size:1.1rem;"></i> Cancel & Stop Server
                            </button>
                        </div>
                    </div>

                    <!-- AJAX Scrape Results -->
                    <div id="ajaxScrapeResult"></div>

                    <?php if (isset($scrape_result)): ?>
                    <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        openScrapeModal(<?php echo json_encode($scrape_result, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>);
                    });
                    </script>
                    <?php endif; ?>

                    <!-- ── SCRAPE RESULT MODAL ── -->
                    <div id="scrapeResultModal" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.55);backdrop-filter:blur(6px);overflow-y:auto;padding:20px;">
                      <div style="max-width:820px;margin:0 auto;background:#fff;border-radius:18px;box-shadow:0 24px 80px rgba(0,0,0,.22);overflow:hidden;" id="scrapeModalBox">
                        <!-- Header -->
                        <div id="scrapeModalHeader" style="padding:22px 28px 18px;display:flex;align-items:center;gap:16px;">
                          <div id="scrapeModalIcon" style="width:50px;height:50px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.6rem;flex-shrink:0;"></div>
                          <div style="flex:1;">
                            <div id="scrapeModalTitle" style="font-size:1.15rem;font-weight:800;color:#0f172a;margin-bottom:3px;"></div>
                            <div id="scrapeModalSub" style="font-size:.82rem;color:#64748b;"></div>
                          </div>
                          <button onclick="closeScrapeModal()" style="background:none;border:none;font-size:1.6rem;color:#94a3b8;cursor:pointer;line-height:1;padding:4px;">&times;</button>
                        </div>
                        <!-- Stats row -->
                        <div id="scrapeModalStats" style="display:flex;gap:0;border-top:1px solid #f1f5f9;border-bottom:1px solid #f1f5f9;background:#f8fafc;"></div>
                        <!-- Log toolbar -->
                        <div style="padding:14px 28px 8px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                          <button onclick="scrapeModalToggleLog()" id="btnModalToggleLog" class="btn btn-sm" style="display:flex;align-items:center;gap:7px;"><i class="fa-solid fa-terminal"></i> Show Full Log</button>
                          <button onclick="scrapeModalFilterErrors()" id="btnModalErrors" style="display:none;background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;border-radius:7px;padding:7px 18px;font-weight:600;font-size:.85rem;display:none;align-items:center;gap:7px;cursor:pointer;"><i class="fa-solid fa-triangle-exclamation"></i> Show Errors Only</button>
                          <span id="scrapeModalLogCount" style="font-size:.8rem;color:#64748b;margin-left:auto;"></span>
                        </div>
                        <!-- Log area -->
                        <div id="scrapeModalLog" style="display:none;max-height:340px;overflow-y:auto;margin:0 28px 20px;border-radius:10px;border:1px solid #e2e8f0;"></div>
                        <!-- Footer -->
                        <div style="padding:12px 28px 20px;display:flex;justify-content:flex-end;gap:10px;">
                          <button onclick="closeScrapeModal()" class="btn" style="padding:9px 24px;font-size:.9rem;">Close</button>
                          <button onclick="location.reload()" class="btn" style="padding:9px 24px;font-size:.9rem;"><i class="fa-solid fa-rotate"></i> Refresh Page</button>
                        </div>
                      </div>
                    </div>

                    <!-- Add URL to Source Modal -->
                    <div id="addUrlModal" class="content-modal-overlay" style="display:none;z-index:10001">
                      <div class="content-modal-box" style="max-width:560px">
                        <button class="close-btn" onclick="document.getElementById('addUrlModal').style.display='none'">&times;</button>
                        <h2><i class="fa-solid fa-link"></i> Add URL to Existing Source</h2>
                        <p style="color:#64748b;font-size:.9rem;margin-bottom:16px">The URL will be attached to the selected source — no new source will be created.</p>
                        <form method="post" id="addUrlForm">
                          <input type="hidden" name="action" value="add_url_to_source">
                          <div class="mb-3">
                            <label class="form-label fw-semibold">Source</label>
                            <select name="source_id" id="addUrlSourceSel" class="form-select" required>
                              <?php foreach ($sources as $s): ?>
                              <option value="<?php echo (int)$s['source_id']; ?>"><?php echo htmlspecialchars($s['source_name']); ?></option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                          <div class="mb-3">
                            <label class="form-label fw-semibold">Page URLs <span style="font-weight:400;color:#64748b;font-size:.85rem">(one per line, or comma-separated)</span></label>
                            <textarea name="page_urls" id="addUrlInput" class="form-control" required
                              placeholder="https://mmu.ac.ug/page-one&#10;https://mmu.ac.ug/page-two&#10;https://mmu.ac.ug/page-three"
                              rows="6" style="font-size:.85rem;font-family:monospace"></textarea>
                            <div id="urlExistsNote" style="display:none;margin-top:6px;font-size:.82rem"></div>
                            <div class="form-text mt-1">Duplicates are automatically skipped. Already-scraped URLs are re-queued for update.</div>
                          </div>
                          <div class="mb-4">
                            <div class="form-check">
                              <input class="form-check-input" type="checkbox" name="scrape_now" id="scrapeNowChk" value="1" checked>
                              <label class="form-check-label" for="scrapeNowChk">
                                <strong>Scrape immediately</strong> (background) &mdash; recommended
                              </label>
                            </div>
                            <div class="form-text">Unchecked: URL is queued only and will be processed on the next "Scrape Missing" run.</div>
                          </div>
                          <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add &amp; Queue URL</button>
                            <button type="button" class="btn btn-secondary" onclick="document.getElementById('addUrlModal').style.display='none'">Cancel</button>
                          </div>
                        </form>
                      </div>
                    </div>

                    <!-- Missing Links Preview Modal -->
                    <div id="missingLinksModal" class="content-modal-overlay" style="display:none;z-index:10001">
                      <div class="content-modal-box" style="max-width:860px">
                        <button class="close-btn" onclick="document.getElementById('missingLinksModal').style.display='none'">&times;</button>
                        <h2><i class="fa-solid fa-magnifying-glass"></i> Missing Links — <span id="mlSourceName"></span></h2>
                        <div id="mlScanStatus" style="margin-bottom:14px"></div>
                        <div id="mlQueueList" style="max-height:420px;overflow-y:auto"></div>
                        <div class="d-flex gap-2 mt-3" id="mlActions" style="display:none">
                          <button class="btn btn-success" id="mlScrapeSelectedBtn" onclick="scrapeMissingSelected()">
                            <i class="fa-solid fa-play"></i> Scrape Selected
                          </button>
                          <button class="btn btn-warning" id="mlScrapeAllBtn" onclick="scrapeMissingAll()">
                            <i class="fa-solid fa-bolt"></i> Scrape All Pending
                          </button>
                          <button class="btn btn-secondary" id="mlDismissSelectedBtn" onclick="dismissMissingSelected()">
                            <i class="fa-solid fa-ban"></i> Dismiss Selected
                          </button>
                        </div>
                        <div class="alert alert-info mt-2" style="font-size:0.85rem;padding:8px 12px;margin-bottom:0">
                          <i class="fa-solid fa-circle-info"></i> <strong>Note:</strong> Use "Scrape All Pending" above to scrape these URLs. 
                          The "Run Full Scrape" button on the main page only crawls from the base URL and won't process pending queue items.
                        </div>
                      </div>
                    </div>

                    <!-- Page Tree Modal -->
                    <div id="pageTreeModal" class="content-modal-overlay" style="display:none;z-index:10001">
                      <div class="content-modal-box" style="max-width:960px">
                        <button class="close-btn" onclick="document.getElementById('pageTreeModal').style.display='none'">&times;</button>
                        <h2><i class="fa-solid fa-sitemap"></i> Page Tree — <span id="ptSourceName"></span></h2>
                        <div id="ptLoading" style="color:#64748b"><i class="fa-solid fa-spinner fa-spin"></i> Loading hierarchy...</div>
                        <div id="ptTree" style="max-height:520px;overflow-y:auto;margin-top:12px"></div>
                      </div>
                    </div>

                    <!-- Similar Content Modal -->
                    <div id="similarContentModal" class="content-modal-overlay" style="display:none;z-index:10001">
                      <div class="content-modal-box" style="max-width:1200px;max-height:90vh;overflow-y:auto">
                        <button class="close-btn" onclick="document.getElementById('similarContentModal').style.display='none'">&times;</button>
                        <h2><i class="fa-solid fa-clone"></i> Similar/Duplicate Content</h2>
                        <div id="similarContentLoading" style="color:#64748b;padding:20px">
                          <i class="fa-solid fa-spinner fa-spin"></i> Analyzing content similarity...
                        </div>
                        <div id="similarContentStats" style="margin-bottom:15px"></div>
                        <div id="similarContentResults"></div>
                      </div>
                    </div>


                    <!-- Tabs for Switching Views -->
                    <div style="display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;">
                        <button class="btn tab-btn" onclick="switchDataTab('scrapedContentTab', this)" style="background:linear-gradient(135deg,#002147 0%,#05356b 100%);color:#fff;border:none;padding:10px 20px;font-weight:600;border-radius:6px;">
                            <i class="fa-solid fa-database"></i> Scraped Content
                        </button>
                        <button class="btn tab-btn" onclick="switchDataTab('sourcesTab', this)" style="background:#f8fafc;color:#64748b;border:1px solid #cbd5e1;padding:10px 20px;font-weight:600;border-radius:6px;">
                            <i class="fa-solid fa-spider"></i> Sources
                        </button>
                        <button class="btn tab-btn" onclick="switchDataTab('missingLinksTab', this)" style="background:#f8fafc;color:#64748b;border:1px solid #cbd5e1;padding:10px 20px;font-weight:600;border-radius:6px;">
                            <i class="fa-solid fa-link-slash"></i> Missing Links Queue
                        </button>
                        <button class="btn tab-btn" onclick="switchDataTab('blockedUrlsTab', this)" style="background:#f8fafc;color:#64748b;border:1px solid #cbd5e1;padding:10px 20px;font-weight:600;border-radius:6px;">
                            <i class="fa-solid fa-ban"></i> Blocked URLs
                        </button>
                        <button class="btn tab-btn" onclick="switchDataTab('activityLogTab', this)" style="background:#f8fafc;color:#64748b;border:1px solid #cbd5e1;padding:10px 20px;font-weight:600;border-radius:6px;">
                            <i class="fa-solid fa-clock-rotate-left"></i> Activity Log
                        </button>
                        <button class="btn tab-btn" onclick="switchDataTab('statisticsTab', this)" style="background:#f8fafc;color:#64748b;border:1px solid #cbd5e1;padding:10px 20px;font-weight:600;border-radius:6px;">
                            <i class="fa-solid fa-chart-bar"></i> Statistics
                        </button>
                    </div>

                    <!-- ══ SOURCES TAB ══ -->
                    <div class="table-container tab-content" id="sourcesTab" style="display:none">
                        <div class="ws-section-header">
                            <div>
                                <div class="ws-section-title"><i class="fa-solid fa-spider"></i> Scraping Sources</div>
                                <div class="ws-section-subtitle">Configure and manage websites to scrape for content</div>
                            </div>
                            <div style="display:flex;gap:8px;">
                                <button class="ws-btn ws-btn--primary" onclick="showCreateModal()"><i class="fa-solid fa-plus"></i> Add New Source</button>
                                <button class="ws-btn ws-btn--outline" onclick="runAllScrapes()"><i class="fa-solid fa-play"></i> Scrape All Active</button>
                            </div>
                        </div>
                        <div class="ws-pane-inner"><div class="ws-panel"><div class="ws-panel-body"><div class="ws-table-wrap">
                            <table class="ws-table">
                                <thead><tr><th>ID</th><th>Name</th><th>Base URL</th><th>Frequency</th><th>Last Scraped</th><th>Status</th><th>Queue</th><th>Actions</th></tr></thead>
                                <tbody>
                                    <?php foreach ($sources as $s): ?>
                                    <tr>
                                        <td><?= (int)$s['source_id'] ?></td>
                                        <td style="font-weight:600;color:var(--ws-text)"><?= htmlspecialchars($s['source_name']) ?></td>
                                        <td><a href="<?= htmlspecialchars($s['base_url']) ?>" target="_blank" style="color:var(--ws-accent);text-decoration:none"><?= htmlspecialchars($s['base_url']) ?></a></td>
                                        <td><?= htmlspecialchars($s['scrape_frequency']) ?></td>
                                        <td><?= $s['last_scraped'] ? date('Y-m-d H:i',strtotime($s['last_scraped'])) : 'Never' ?></td>
                                        <td><span class="ws-badge <?= $s['is_active'] ? 'ws-badge--green' : 'ws-badge--red' ?>"><?= $s['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                                        <td><?php $sid=(int)$s['source_id'];$pc=$queue_counts[$sid]['pending']??0;$dc=$queue_counts[$sid]['done']??0; ?>
                                            <?php if($pc>0): ?><span class="ws-badge ws-badge--yellow"><i class="fa-solid fa-hourglass-half"></i> <?= $pc ?></span><?php else: ?><span style="color:#64748b;font-size:.8rem"><?= $dc ?> done</span><?php endif; ?>
                                        </td>
                                        <td style="white-space:nowrap">
                                            <button class="ws-btn ws-btn--outline ws-btn--sm" onclick="runScrapeAjax(<?= (int)$s['source_id'] ?>,'<?= htmlspecialchars($s['source_name'],ENT_QUOTES) ?>',false)" title="Run Scrape"><i class="fa-solid fa-play"></i></button>
                                            <button class="ws-btn ws-btn--outline ws-btn--sm" onclick="openAddUrlModal(<?= (int)$s['source_id'] ?>,'<?= htmlspecialchars($s['source_name'],ENT_QUOTES) ?>')" title="Add URL"><i class="fa-solid fa-link"></i></button>
                                            <button class="ws-btn ws-btn--outline ws-btn--sm" onclick="editSource(<?= htmlspecialchars(json_encode($s)) ?>)" title="Edit"><i class="fa-solid fa-pen-to-square"></i></button>
                                            <form method="post" style="display:inline" id="delSrcFrm-<?= (int)$s['source_id'] ?>"><input type="hidden" name="action" value="delete_source"><input type="hidden" name="source_id" value="<?= (int)$s['source_id'] ?>"></form>
                                            <button class="ws-btn ws-btn--danger ws-btn--sm" onclick="showConfirmModal({title:'Delete Source',message:'Delete this source and all its scraped content? This cannot be undone.',confirmText:'DELETE',formId:'delSrcFrm-<?= (int)$s['source_id'] ?>'})" title="Delete"><i class="fa-solid fa-trash"></i></button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if(empty($sources)): ?><tr><td colspan="8" style="text-align:center;color:#999;padding:40px">No scraping sources configured</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div></div></div></div>
                    </div><!-- /.sourcesTab -->

                    <!-- ══ STATISTICS TAB ══ -->
                    <div class="table-container tab-content" id="statisticsTab" style="display:none">
                        <div class="ws-panel"><div class="ws-panel-header"><div class="ws-panel-title"><i class="fa-solid fa-chart-bar"></i> Scrape Statistics</div></div>
                        <div class="ws-panel-body">
                            <div class="ws-stat-grid">
                                <div class="ws-stat-card"><div class="ws-stat-icon"><i class="fa-solid fa-database"></i></div><div class="ws-stat-body"><div class="ws-stat-value"><?= $stats['total'] ?></div><div class="ws-stat-label">Total Pages</div></div></div>
                                <div class="ws-stat-card"><div class="ws-stat-icon"><i class="fa-solid fa-plus"></i></div><div class="ws-stat-body"><div class="ws-stat-value"><?= $stats['new']??0 ?></div><div class="ws-stat-label">New</div></div></div>
                                <div class="ws-stat-card"><div class="ws-stat-icon"><i class="fa-solid fa-arrows-rotate"></i></div><div class="ws-stat-body"><div class="ws-stat-value"><?= $stats['updated']??0 ?></div><div class="ws-stat-label">Updated</div></div></div>
                                <div class="ws-stat-card"><div class="ws-stat-icon"><i class="fa-solid fa-check"></i></div><div class="ws-stat-body"><div class="ws-stat-value"><?= $stats['processed']??0 ?></div><div class="ws-stat-label">Processed</div></div></div>
                                <div class="ws-stat-card"><div class="ws-stat-icon"><i class="fa-solid fa-layer-group"></i></div><div class="ws-stat-body"><div class="ws-stat-value"><?= $stats['indexed']??0 ?></div><div class="ws-stat-label">Indexed</div></div></div>
                                <div class="ws-stat-card"><div class="ws-stat-icon"><i class="fa-solid fa-xmark"></i></div><div class="ws-stat-body"><div class="ws-stat-value"><?= $stats['failed']??0 ?></div><div class="ws-stat-label">Failed</div></div></div>
                            </div>
                            <div class="ws-stat-grid" style="margin-top:20px">
                                <div class="ws-stat-card"><div class="ws-stat-icon"><i class="fa-solid fa-hourglass-half"></i></div><div class="ws-stat-body"><div class="ws-stat-value"><?= $enrich_stats['pending']??0 ?></div><div class="ws-stat-label">Enrich Pending</div></div></div>
                                <div class="ws-stat-card"><div class="ws-stat-icon"><i class="fa-solid fa-wand-magic-sparkles"></i></div><div class="ws-stat-body"><div class="ws-stat-value"><?= $enrich_stats['done']??0 ?></div><div class="ws-stat-label">Enriched</div></div></div>
                                <div class="ws-stat-card"><div class="ws-stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></div><div class="ws-stat-body"><div class="ws-stat-value"><?= $enrich_stats['failed']??0 ?></div><div class="ws-stat-label">Enrich Failed</div></div></div>
                            </div>
                        </div></div>
                    </div><!-- /.statisticsTab -->

                    <!-- Missing Links Queue Tab -->
                    <div class="table-container tab-content" id="missingLinksTab" style="display:none">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:10px">
                          <h2 style="margin:0;font-size:1.1rem">Missing Links Queue</h2>
                          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                            <select id="mlFilterSource" class="form-select form-select-sm" style="width:220px" onchange="loadQueueTab()">
                              <option value="">All Sources</option>
                              <?php foreach ($sources as $s): ?>
                              <option value="<?php echo (int)$s['source_id']; ?>"><?php echo htmlspecialchars($s['source_name']); ?></option>
                              <?php endforeach; ?>
                            </select>
                            <select id="mlFilterStatus" class="form-select form-select-sm" style="width:150px" onchange="loadQueueTab()">
                              <option value="pending">Pending</option>
                              <option value="done">Done</option>
                              <option value="failed">Failed</option>
                              <option value="skipped">Skipped</option>
                              <option value="">All</option>
                            </select>
                            <button class="btn btn-secondary btn-sm" onclick="loadQueueTab()" title="Refresh table">
                              <i class="fa-solid fa-rotate"></i> Refresh
                            </button>
                            <button class="btn btn-danger btn-sm" onclick="cleanupMalformedUrls()" title="Remove malformed URLs from queue">
                              <i class="fa-solid fa-broom"></i> Cleanup Malformed
                            </button>
                            <button class="btn btn-primary" onclick="startScanFromQueue()" id="queueScanBtn" style="display:none">
                              <i class="fa-solid fa-magnifying-glass"></i> Scan for Missing
                            </button>
                            <button class="btn btn-danger" onclick="stopScanFromQueue()" id="queueStopScanBtn" style="display:none">
                              <i class="fa-solid fa-stop"></i> Stop Scan
                            </button>
                            <button class="btn btn-warning" onclick="scrapeAllPendingFromQueue()" id="queueScrapeAllBtn" style="display:none">
                              <i class="fa-solid fa-bolt"></i> Scrape Pending
                            </button>
                            <button class="btn btn-danger" onclick="stopScrapeFromQueue()" id="queueStopScrapeBtn" style="display:none">
                              <i class="fa-solid fa-stop-circle"></i> Stop Scraping
                            </button>
                            <button class="btn btn-info" onclick="cleanupCalendarLinks()" title="Remove calendar download links (ical/outlook-ical)">
                              <i class="fa-solid fa-calendar-xmark"></i> Cleanup Calendar Links
                            </button>
                            <button class="btn btn-success btn-sm" onclick="showSkipPatternsModal()" title="Manage URL patterns to skip during scan">
                              <i class="fa-solid fa-filter-circle-xmark"></i> Skip Patterns
                            </button>
                            <button class="btn btn-danger btn-sm" onclick="clearQueueTable()" title="Delete all items from queue (cannot be undone)">
                              <i class="fa-solid fa-trash-can"></i> Clear Queue
                            </button>
                          </div>
                        </div>
                        <div id="queueScrapeStatus" style="margin-bottom:12px"></div>
                        
                        <!-- Auto-refresh toggle and pagination info -->
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;padding:10px;background:#f8fafc;border-radius:8px;flex-wrap:wrap;gap:10px">
                          <div style="display:flex;align-items:center;gap:10px">
                            <button class="btn btn-sm" id="queueAutoRefreshToggle" onclick="toggleQueueAutoRefresh()" style="background:#10b981;color:#fff;border:none">
                              <i class="fa-solid fa-toggle-on"></i> Auto-refresh ON
                            </button>
                            <span id="queueAutoRefreshStatus" style="color:#64748b;font-size:0.85rem;display:none">
                              <i class="fa-solid fa-rotate fa-spin"></i> Refreshing every 10s | Last: <span id="queueLastRefresh">—</span>
                            </span>
                          </div>
                          <div id="queuePaginationTop" style="color:#64748b;font-size:0.9rem"></div>
                        </div>
                        
                        <div id="queueTabContent"><p style="color:#94a3b8">Select a source to load queue...</p></div>
                    </div>

                    <!-- Scraped Content with search/filter (Tab Content) -->
                    <div class="table-container tab-content" id="scrapedContentTab">
                        <h2 style="display: none;">Scraped Content</h2>

                        <!-- Filter bar -->
                        <form method="get" class="filter-bar">
                            <input type="hidden" name="tab" value="scrapedContentTab">
                            <input type="text" name="q" placeholder="Search title or URL..."
                                value="<?php echo htmlspecialchars($search_q); ?>">
                            <select name="status">
                                <option value="">All Statuses</option>
                                <option value="new" <?php echo $filter_status === 'new' ? 'selected' : ''; ?>>New</option>
                                <option value="updated" <?php echo $filter_status === 'updated' ? 'selected' : ''; ?>>Updated</option>
                                <option value="processed" <?php echo $filter_status === 'processed' ? 'selected' : ''; ?>>Processed</option>
                                <option value="indexed" <?php echo $filter_status === 'indexed' ? 'selected' : ''; ?>>Indexed</option>
                                <option value="failed" <?php echo $filter_status === 'failed' ? 'selected' : ''; ?>>Failed</option>
                                <option value="duplicate" <?php echo $filter_status === 'duplicate' ? 'selected' : ''; ?>>Duplicate</option>
                            </select>
                            <select name="source_id">
                                <option value="0">All Sources</option>
                                <?php foreach ($sources as $src): ?>
                                    <option value="<?php echo (int) $src['source_id']; ?>"
                                        <?php echo $filter_source === (int) $src['source_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($src['source_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <select name="enrichment_status">
                                <option value="">All Enrichment</option>
                                <option value="pending" <?php echo $filter_enrichment === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="done" <?php echo $filter_enrichment === 'done' ? 'selected' : ''; ?>>Done</option>
                                <option value="failed" <?php echo $filter_enrichment === 'failed' ? 'selected' : ''; ?>>Failed</option>
                                <option value="skipped" <?php echo $filter_enrichment === 'skipped' ? 'selected' : ''; ?>>Skipped</option>
                            </select>
                            <button type="submit" class="btn btn-secondary"><i class="fa-solid fa-search"></i> Filter</button>
                            <a href="web_scraper.php" class="btn btn-secondary" style="text-decoration:none"><i class="fa-solid fa-xmark"></i> Clear</a>
                            <button type="button" class="btn btn-warning" onclick="migrateDuplicatesToBlocked()" title="Move duplicate pages to blocked_urls table (one-time migration)">
                                <i class="fa-solid fa-database"></i> Migrate Duplicates
                            </button>
                            <button type="button" class="btn btn-danger" onclick="cleanupScrapedMalformed()" title="Remove pages with malformed URLs (wrong content due to redirects)">
                                <i class="fa-solid fa-broom"></i> Cleanup Malformed Pages
                            </button>
                            <button type="button" class="btn btn-info" onclick="openSimilarContentModal()" title="Find pages with similar/duplicate content">
                                <i class="fa-solid fa-clone"></i> Find Similar Content
                            </button>
                        </form>

                        <div id="bulkActionsForm" style="margin-bottom:14px; padding-top:10px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center;">
                            <button type="button" class="btn btn-danger btn-sm" onclick="submitBulkAction('bulk_delete_content')">
                                <i class="fa-solid fa-trash"></i> Delete Selected (<span id="bulkCount">0</span>)
                            </button>
                            <button type="button" class="btn btn-info btn-sm" onclick="enrichSelectedKB(this, {reloadOnSuccess:true})">
                                <i class="fa-solid fa-wand-magic-sparkles"></i> Enrich Selected
                            </button>
                            <button type="button" class="btn btn-primary btn-sm" onclick="enrichAndReindexKB(this, {scraped_ids: getSelectedScrapedIds(), reloadOnSuccess:true})">
                                <i class="fa-solid fa-layer-group"></i> Enrich Selected &amp; Reindex
                            </button>
                            <button type="button" class="btn btn-primary btn-sm" onclick="showBulkStripModal()">
                                <i class="fa-solid fa-eraser"></i> Bulk Strip Text
                            </button>
                            <div style="flex-grow: 1;"></div>
                            <a href="export.php?table=scraped_content&format=csv" class="btn btn-success btn-sm"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
                            <a href="export.php?table=scraped_content&format=json" class="btn btn-success btn-sm"><i class="fa-solid fa-file-code"></i> Export JSON</a>
                            <button type="button" class="btn btn-success btn-sm" onclick="showImportModal()"><i class="fa-solid fa-file-import"></i> Import Enriched</button>
                        </div>

                        <table>
                            <thead>
                                <tr>
                                    <th style="width: 40px"><input type="checkbox" id="selectAll"></th>
                                    <th>ID</th>
                                    <th>Source</th>
                                    <th>Page Title</th>
                                    <th>URL</th>
                                    <th>Hash</th>
                                    <th>Status</th>
                                    <th>Enrichment</th>
                                    <th>Scraped At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_scraped as $sc): ?>
                                    <tr>
                                        <td><input type="checkbox" class="bulk-select" value="<?php echo (int) $sc['scraped_id']; ?>"></td>
                                        <td><?php echo (int) $sc['scraped_id']; ?></td>
                                        <td>
                                            <?php echo htmlspecialchars($sc['source_name'] ?? 'N/A'); ?>
                                            <?php if (isset($sc['duplicate_count']) && $sc['duplicate_count'] > 0): ?>
                                                <span title="This URL exists in <?php echo (int)$sc['duplicate_count']; ?> other source(s)" 
                                                      style="color:#f59e0b; margin-left:4px; cursor:help;">
                                                    <i class="fa-solid fa-copy"></i> +<?php echo (int)$sc['duplicate_count']; ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td title="<?php echo htmlspecialchars($sc['page_title'] ?? ''); ?>">
                                            <?php echo htmlspecialchars(mb_strimwidth($sc['page_title'] ?? 'Untitled', 0, 40, '...')); ?>
                                        </td>
                                        <td><a href="<?php echo htmlspecialchars($sc['page_url']); ?>"
                                                target="_blank" title="<?php echo htmlspecialchars($sc['page_url']); ?>">
                                                <?php echo htmlspecialchars(mb_strimwidth($sc['page_url'], 0, 40, '...')); ?></a>
                                        </td>
                                        <td style="font-family:monospace;font-size:0.72rem;color:#94a3b8"
                                            title="<?php echo htmlspecialchars($sc['content_hash'] ?? ''); ?>">
                                            <?php echo htmlspecialchars(substr($sc['content_hash'] ?? '—', 0, 8)); ?>
                                        </td>
                                        <td>
                                            <?php
                                            $status_class = 'status-' . ($sc['status'] ?? 'new');
                                            ?>
                                            <span class="status-badge <?php echo $status_class; ?>">
                                                <?php echo htmlspecialchars($sc['status'] ?? 'new'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $enrich_st = $sc['enrichment_status'] ?? 'pending';
                                            $enrich_class = 'enrich-' . preg_replace('/[^a-z]/', '', strtolower($enrich_st));
                                            $page_type = '';
                                            if (!empty($sc['enrichment_json'])) {
                                                $ej = json_decode($sc['enrichment_json'], true);
                                                $page_type = is_array($ej) ? ($ej['page_type'] ?? '') : '';
                                            }
                                            ?>
                                            <span class="status-badge <?php echo htmlspecialchars($enrich_class); ?>" title="<?php echo htmlspecialchars($page_type); ?>">
                                                <?php echo htmlspecialchars($enrich_st); ?>
                                            </span>
                                            <?php if ($page_type && $page_type !== 'other'): ?>
                                                <br><small style="color:#64748b;"><?php echo htmlspecialchars($page_type); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('Y-m-d H:i', strtotime($sc['scraped_at'])); ?></td>
                                        <td style="white-space:nowrap">
                                            <button class="btn btn-secondary btn-sm"
                                                onclick="viewContent(<?php echo (int) $sc['scraped_id']; ?>)"
                                                title="View Content">
                                                <i class="fa-solid fa-eye"></i>
                                            </button>
                                            <form method="post" style="display:inline" id="delContentForm-<?php echo (int) $sc['scraped_id']; ?>">
                                                <input type="hidden" name="action" value="delete_content">
                                                <input type="hidden" name="scraped_id"
                                                    value="<?php echo (int) $sc['scraped_id']; ?>">
                                            </form>
                                            <button class="btn btn-danger btn-sm" type="button" title="Delete"
                                                onclick="showConfirmModal({title:'Delete Content', message:'Delete this scraped content? This cannot be undone.', confirmText:'DELETE', formId:'delContentForm-<?php echo (int) $sc['scraped_id']; ?>'})">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($recent_scraped)): ?>
                                    <tr>
                                        <td colspan="9" style="text-align:center;color:#999">No scraped content found</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                <!-- Blocked URLs Tab -->
                <div class="table-container tab-content" id="blockedUrlsTab" style="display: none;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:15px;flex-wrap:wrap;gap:10px">
                        <h2 style="margin:0;font-size:1.1rem">
                            <i class="fa-solid fa-ban"></i> Blocked URLs Management
                        </h2>
                        <div id="blockedUrlsStats" style="display:flex;gap:15px;font-size:0.9rem;color:#64748b"></div>
                    </div>

                    <!-- Filter and Action Bar -->
                    <div class="filter-bar" style="margin-bottom:15px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                        <select id="blockedFilterSource" class="form-select form-select-sm" style="width:200px" onchange="loadBlockedUrlsTab()">
                            <option value="">All Sources</option>
                            <?php foreach ($sources as $s): ?>
                                <option value="<?php echo $s['source_id']; ?>"><?php echo htmlspecialchars($s['source_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="blockedFilterReason" class="form-select form-select-sm" style="width:150px" onchange="loadBlockedUrlsTab()">
                            <option value="">All Reasons</option>
                            <option value="duplicate">Duplicate</option>
                            <option value="malformed">Malformed</option>
                            <option value="manual">Manual</option>
                            <option value="redirect_loop">Redirect Loop</option>
                        </select>
                        <input type="text" id="blockedSearchUrl" class="form-control form-control-sm" placeholder="Search URL..." style="width:250px" onkeyup="if(event.key==='Enter') loadBlockedUrlsTab()">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="loadBlockedUrlsTab()">
                            <i class="fa-solid fa-search"></i> Search
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="clearBlockedFilters()">
                            <i class="fa-solid fa-xmark"></i> Clear
                        </button>
                        <div style="flex:1"></div>
                        <button type="button" class="btn btn-primary btn-sm" onclick="showBulkBlockModal()">
                            <i class="fa-solid fa-plus"></i> Bulk Block URLs
                        </button>
                        <button type="button" class="btn btn-info btn-sm" onclick="showBlockedStatsModal()">
                            <i class="fa-solid fa-chart-pie"></i> Statistics
                        </button>
                        <button type="button" class="btn btn-success btn-sm" onclick="exportBlockedUrls()">
                            <i class="fa-solid fa-download"></i> Export
                        </button>
                        <button type="button" class="btn btn-warning btn-sm" onclick="showImportBlockedModal()">
                            <i class="fa-solid fa-upload"></i> Import
                        </button>
                    </div>

                    <!-- Blocked URLs Table Container -->
                    <div id="blockedUrlsTableContainer" style="margin-top:15px">
                        <p style="color:#94a3b8;text-align:center;padding:40px 0">
                            <i class="fa-solid fa-spinner fa-spin"></i> Loading blocked URLs...
                        </p>
                    </div>
                </div>

                <!-- Admin Activity Log (Tab Content) -->
                <div class="table-container tab-content" id="activityLogTab" style="display: none;">
                    <h2 style="display: none;">Recent Administrator Activity (Accountability Log)</h2>
                    
                    <!-- Filter bar for Accountability -->
                    <form method="get" class="filter-bar" style="margin-bottom: 15px;">
                        <input type="hidden" name="tab" value="activityLogTab">
                        <input type="text" name="log_admin" placeholder="Search admin username..." value="<?php echo htmlspecialchars($log_admin); ?>">
                        <select name="log_action">
                            <option value="">All Actions</option>
                            <option value="CREATE" <?php echo $log_action === 'CREATE' ? 'selected' : ''; ?>>CREATE</option>
                            <option value="UPDATE" <?php echo $log_action === 'UPDATE' ? 'selected' : ''; ?>>UPDATE</option>
                            <option value="DELETE" <?php echo $log_action === 'DELETE' ? 'selected' : ''; ?>>DELETE</option>
                            <option value="BULK_UPDATE" <?php echo $log_action === 'BULK_UPDATE' ? 'selected' : ''; ?>>BULK_UPDATE</option>
                            <option value="BULK_DELETE" <?php echo $log_action === 'BULK_DELETE' ? 'selected' : ''; ?>>BULK_DELETE</option>
                        </select>
                        <button type="submit" class="btn btn-secondary"><i class="fa-solid fa-search"></i> Filter</button>
                        <a href="web_scraper.php?tab=activityLogTab" class="btn btn-secondary" style="text-decoration:none"><i class="fa-solid fa-xmark"></i> Clear</a>
                    </form>

                    <table>
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Admin</th>
                                <th>Action</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($admin_logs as $log): ?>
                                <tr>
                                    <td><?php echo date('Y-m-d H:i:s', strtotime($log['timestamp'])); ?></td>
                                    <td>
                                        <span style="font-weight: 500; color: #3b82f6;">
                                            <i class="fa-solid fa-user-shield"></i> <?php echo htmlspecialchars($log['username']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge" style="background:#475569;">
                                            <?php echo htmlspecialchars($log['action']); ?>
                                        </span>
                                    </td>
                                    <td style="color:#475569;"><?php echo htmlspecialchars($log['description']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($admin_logs)): ?>
                                <tr>
                                    <td colspan="4" style="text-align:center;color:#999">No recent activity found in the web scraper module.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                        </div><!-- /.ws-content-area -->
                    </div><!-- /.ws-body -->
                </div><!-- /.ws-page -->

            </div><!-- /.sb2-2 -->
        </div><!-- /.row -->
    </div><!-- /.container-fluid sb2 -->

    <!-- Create/Edit Source Modal -->
    <div id="sourceModal" class="source-modal-overlay">
        <div class="source-modal-box">
            <button class="close-btn" type="button" onclick="closeModal()" title="Close"><i class="fa-solid fa-xmark"></i></button>
            <h2 id="modalTitle"><i class="fa-solid fa-earth-americas" style="color:#3b82f6;margin-right:12px"></i> Add Website to Scrape</h2>
            <form method="post" id="sourceForm">
                <input type="hidden" name="action" id="formAction" value="create_source">
                <input type="hidden" name="source_id" id="source_id">

                <div class="source-form-group">
                    <label>Website Name</label>
                    <input type="text" name="source_name" id="source_name" class="source-form-control" required placeholder="e.g. MMU Official Website">
                </div>

                <div class="source-form-group">
                    <label>Website URL</label>
                    <input type="url" name="base_url" id="base_url" class="source-form-control" required placeholder="https://mmu.ac.ug">
                </div>

                <div class="row">
                    <div class="col-md-6 source-form-group">
                        <label>How Often to Scrape</label>
                        <select name="scrape_frequency" id="scrape_frequency" class="source-form-control">
                            <option value="hourly">Every Hour</option>
                            <option value="daily" selected>Once a Day</option>
                            <option value="weekly">Once a Week</option>
                            <option value="monthly">Once a Month</option>
                        </select>
                    </div>

                    <div class="col-md-6 source-form-group">
                        <label>How Deep to Follow Links</label>
                        <select name="max_depth" id="max_depth" class="source-form-control">
                            <option value="1">1 - This page only</option>
                            <option value="2" selected>2 - This page + its links</option>
                            <option value="3">3 - Deeper links</option>
                            <option value="4">4 - Very deep</option>
                            <option value="5">5 - Extremely deep</option>
                            <option value="99">Entire Website (Full Crawl)</option>
                        </select>
                    </div>
                </div>

                <label class="source-checkbox-group">
                    <input type="checkbox" name="follow_links" id="follow_links" checked>
                    <div>
                        <div class="checkbox-title">Follow links on the page</div>
                        <span>Allows the scraper to discover more content from this source</span>
                    </div>
                </label>

                <label class="source-checkbox-group">
                    <input type="checkbox" name="is_active" id="is_active" checked>
                    <div>
                        <div class="checkbox-title">Active</div>
                        <span>Enable automated scraping for this source</span>
                    </div>
                </label>

                <details class="source-advanced-options">
                    <summary><i class="fa-solid fa-sliders"></i> Advanced Selectors & Patterns</summary>
                    <div class="advanced-options-content">
                        <div class="source-form-group">
                            <label>Content Area Selector</label>
                            <input type="text" name="content_selector" id="content_selector" class="source-form-control" value="body">
                            <small>CSS selector for the main article text (e.g. <code>.entry-content</code>)</small>
                        </div>
                        <div class="source-form-group">
                            <label>Title Selector</label>
                            <input type="text" name="title_selector" id="title_selector" class="source-form-control" value="h1, title">
                        </div>
                        <div class="source-form-group">
                            <label>Exclude Selectors (Noise)</label>
                            <input type="text" name="exclude_selector" id="exclude_selector" class="source-form-control" value="nav, footer, script, style">
                        </div>
                        <div class="row">
                            <div class="col-md-12 source-form-group">
                                <label>Include URL Patterns</label>
                                <textarea name="include_patterns" id="include_patterns" class="source-form-control" rows="6" placeholder="/blog/&#10;/news/"></textarea>
                                <small>Only matching URLs crawled. (One per line)</small>
                            </div>
                            <div class="col-md-12 source-form-group">
                                <label>Exclude URL Patterns</label>
                                <textarea name="exclude_patterns" id="exclude_patterns" class="source-form-control" rows="6" placeholder="/login&#10;/cart"></textarea>
                            </div>
                        </div>
                    </div>
                </details>

                <div class="source-form-actions">
                    <button type="button" class="source-btn source-btn-cancel" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="source-btn source-btn-submit"><i class="fa-solid fa-save" style="margin-right:8px"></i>Save Website</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Content View Modal -->
    <div class="content-modal-overlay" id="contentModal">
        <div class="content-modal-box">
            <div style="position: absolute; top: 16px; right: 20px; display:flex; gap:8px;">
                <button class="btn btn-info btn-sm" id="btnReEnrichContent" type="button" title="Re-run LLM enrichment for this page">
                    <i class="fa-solid fa-wand-magic-sparkles"></i> Re-enrich
                </button>
                <button class="btn btn-primary btn-sm" id="btnEditContent" onclick="toggleEditContent()"><i class="fa-solid fa-pen" style="color:white; margin-right:5px"></i> Edit</button>
                <button class="close-btn" style="position:static; margin-left:10px" onclick="closeContentModal()">&times;</button>
            </div>
            <h2 id="contentTitle" style="margin-top:0;">Page Title</h2>
            <div class="meta-row"><i class="fa-solid fa-link"></i> URL: <span id="contentUrl"></span></div>
            <div id="duplicateSourceInfo" style="display:none; padding:12px; background:#fef3c7; border-left:4px solid #f59e0b; margin:12px 0; border-radius:4px; font-size:0.9rem;"></div>
            <div class="meta-row"><i class="fa-solid fa-fingerprint"></i> Hash: <span id="contentHash" style="font-family:monospace"></span></div>
            <div class="meta-row"><i class="fa-solid fa-user"></i> Author: <span id="contentAuthor"></span></div>
            <div class="meta-row"><i class="fa-solid fa-calendar"></i> Publish Date: <span id="contentDate"></span></div>
            <div class="meta-row"><i class="fa-solid fa-tag"></i> Category: <span id="contentCategory"></span></div>
            <div class="meta-row"><i class="fa-solid fa-clock"></i> Last Crawled: <span id="contentCrawled"></span></div>

            <div class="enrichment-panel" id="enrichmentPanel" style="display:none;">
                <div><strong><i class="fa-solid fa-wand-magic-sparkles"></i> Enrichment</strong>
                    <span id="contentEnrichStatus" class="status-badge enrich-pending" style="margin-left:8px;">pending</span>
                    <span id="contentEnrichedAt" style="color:#64748b;font-size:0.8rem;margin-left:8px;"></span>
                </div>
                <div id="contentPageType" style="margin-top:6px;color:#334155;"></div>
                <div id="contentSummary" style="margin-top:6px;color:#475569;"></div>
                <div class="enrichment-tags" id="contentTags"></div>
                <details style="margin-top:10px;">
                    <summary style="cursor:pointer;color:#3b82f6;font-size:0.85rem;">Search document &amp; sections</summary>
                    <pre id="contentSearchDoc" style="white-space:pre-wrap;font-size:0.75rem;max-height:160px;overflow:auto;margin-top:8px;background:#fff;padding:8px;border-radius:6px;border:1px solid #e2e8f0;"></pre>
                    <pre id="contentSections" style="white-space:pre-wrap;font-size:0.75rem;max-height:120px;overflow:auto;margin-top:8px;background:#fff;padding:8px;border-radius:6px;border:1px solid #e2e8f0;"></pre>
                </details>
            </div>

            <form method="post" id="contentEditForm">
                <input type="hidden" name="action" value="update_content">
                <input type="hidden" name="scraped_id" id="edit_scraped_id">

                <div class="content-body" id="contentBodyContainer">
                    <div id="contentBodyDisplay">Loading...</div>
                    <textarea name="cleaned_content" id="contentBodyEdit" style="display:none; width:100%; min-height:450px; padding:15px; border:1px solid #cbd5e1; border-radius:8px; font-family:monospace; margin-top:10px;"></textarea>
                </div>

                <div id="contentEditActions" style="display:none; margin-top:15px; text-align:right;">
                    <button type="button" class="btn btn-secondary" style="margin-right:8px" onclick="toggleEditContent()">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save" style="margin-right:5px"></i> Save Changes</button>
                </div>
            </form>

            <div class="history-section" id="historySection" style="display:none">
                <h3 style="font-size:1rem;margin-bottom:10px"><i class="fa-solid fa-clock-rotate-left"></i> Version History</h3>
                <div id="historyList"></div>
            </div>
        </div>
    </div>

    <!-- Bulk Strip Modal -->
    <div class="source-modal-overlay" id="bulkStripModal">
        <div class="source-modal-box" style="max-width: 800px; width: 95%;">
            <button class="close-btn" type="button" onclick="document.getElementById('bulkStripModal').style.display='none'" title="Close"><i class="fa-solid fa-xmark"></i></button>
            <h2 style="margin-top:0;margin-bottom:16px;border-bottom:2px solid #f1f5f9;padding-bottom:12px;"><i class="fa-solid fa-eraser" style="color:#3b82f6;margin-right:12px"></i> Bulk Remove Text</h2>
            <form method="post" id="bulkStripFormInner">
                <input type="hidden" name="action" value="bulk_strip_content">
                <div id="bulkStripInputs"></div>
                <div class="source-form-group">
                    <label>Text or HTML to Remove</label>
                    <textarea name="strip_text" class="source-form-control" rows="14" style="font-family:monospace; resize:vertical; min-height:300px;" required placeholder="Paste the exact text, nav bar HTML, or phrase you want to remove across all selected pages..."></textarea>
                    <small>This will find and remove all exact occurrences of the text provided from the selected pages. Ensure you have copied exactly what you want removed.</small>
                </div>
                <div class="source-form-actions">
                    <button type="button" class="source-btn source-btn-cancel" onclick="document.getElementById('bulkStripModal').style.display='none'">Cancel</button>
                    <button type="submit" class="source-btn source-btn-submit"><i class="fa-solid fa-eraser" style="margin-right:8px"></i>Remove Text</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Import Enriched Modal -->
    <div class="source-modal-overlay" id="importEnrichedModal" style="display: none;">
        <div class="source-modal-box" style="max-width: 700px; width: 95%;">
            <button class="close-btn" type="button" onclick="document.getElementById('importEnrichedModal').style.display='none'" title="Close"><i class="fa-solid fa-xmark"></i></button>
            <h2 style="margin-top:0;margin-bottom:16px;border-bottom:2px solid #f1f5f9;padding-bottom:12px;"><i class="fa-solid fa-file-import" style="color:#10b981;margin-right:12px"></i> Import Enriched Records</h2>
            <form id="real-import-form" enctype="multipart/form-data">
                <div class="source-form-group" style="margin-bottom: 15px;">
                    <label style="font-weight: 600; margin-bottom: 6px; display: block;">Select File (.xlsx, .csv, .json)</label>
                    <input type="file" id="modal-import-file" class="form-control" accept=".xlsx,.csv,.json" required>
                </div>
                <div class="source-form-group" style="margin-bottom: 20px;">
                    <label style="display: flex; align-items: center; gap: 8px; font-weight: 500; cursor: pointer;">
                        <input type="checkbox" id="modal-preview-checkbox" checked style="width:18px; height:18px;">
                        <span>Preview changes (safe mode: view diff report without writing to database)</span>
                    </label>
                </div>
                <div class="source-form-actions">
                    <button type="button" class="source-btn source-btn-cancel" onclick="document.getElementById('importEnrichedModal').style.display='none'">Cancel</button>
                    <button type="button" id="btn-submit-import" class="source-btn source-btn-submit"><i class="fa-solid fa-cloud-upload-alt" style="margin-right:8px"></i>Upload &amp; Process</button>
                </div>
            </form>
            
            <!-- Result/Report Container -->
            <div id="import-report-container" style="display:none; margin-top:20px; border-top:1px solid #e2e8f0; padding-top:15px;">
                <h4 style="font-size:1.05rem; margin-bottom:12px;"><i class="fa-solid fa-chart-bar" style="color:#10b981; margin-right:8px;"></i> Import Report</h4>
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; margin-bottom:15px;">
                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px; text-align:center;">
                        <div id="report-total" style="font-size:1.4rem; font-weight:700; color:#334155;">0</div>
                        <div style="font-size:0.75rem; color:#64748b; text-transform:uppercase;">Processed</div>
                    </div>
                    <div style="background:#ecfeff; border:1px solid #cffafe; border-radius:8px; padding:10px; text-align:center;">
                        <div id="report-updated" style="font-size:1.4rem; font-weight:700; color:#0891b2;">0</div>
                        <div style="font-size:0.75rem; color:#0891b2; text-transform:uppercase;">Updated</div>
                    </div>
                    <div style="background:#fef3c7; border:1px solid #fef3c7; border-radius:8px; padding:10px; text-align:center;">
                        <div id="report-skipped" style="font-size:1.4rem; font-weight:700; color:#d97706;">0</div>
                        <div style="font-size:0.75rem; color:#d97706; text-transform:uppercase;">Skipped</div>
                    </div>
                    <div style="background:#fee2e2; border:1px solid #fee2e2; border-radius:8px; padding:10px; text-align:center;">
                        <div id="report-errors" style="font-size:1.4rem; font-weight:700; color:#dc2626;">0</div>
                        <div style="font-size:0.75rem; color:#dc2626; text-transform:uppercase;">Errors/Unmatched</div>
                    </div>
                </div>
                
                <div id="report-details" style="max-height: 250px; overflow-y: auto; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px; font-family:monospace; font-size:0.8rem; white-space:pre-wrap; color:#334155;">
                </div>
            </div>
        </div>
    </div>

    <!-- Hidden data for JS (scraped content details) -->
    <?php
    // Build a JSON lookup of all scraped content for the modal
    $content_data = [];
    foreach ($recent_scraped as $sc) {
        // Find other sources that have this same URL
        $duplicate_sources = [];
        if (isset($sc['duplicate_count']) && $sc['duplicate_count'] > 0) {
            $dup_stmt = $conn->prepare(
                "SELECT ss.source_name, sc2.scraped_id, sc2.scraped_at 
                 FROM scraped_content sc2 
                 JOIN scraping_sources ss ON sc2.source_id = ss.source_id 
                 WHERE sc2.page_url = ? AND sc2.source_id != ? 
                 ORDER BY sc2.scraped_at DESC"
            );
            $dup_stmt->bind_param('si', $sc['page_url'], $sc['source_id']);
            $dup_stmt->execute();
            $dup_result = $dup_stmt->get_result();
            while ($dup = $dup_result->fetch_assoc()) {
                $duplicate_sources[] = [
                    'source_name' => $dup['source_name'],
                    'scraped_id' => (int)$dup['scraped_id'],
                    'scraped_at' => $dup['scraped_at']
                ];
            }
            $dup_stmt->close();
        }
        
        $enrichment = [];
        if (!empty($sc['enrichment_json'])) {
            $decoded = json_decode($sc['enrichment_json'], true);
            if (is_array($decoded)) $enrichment = $decoded;
        }
        $sections = [];
        if (!empty($sc['sections_json'])) {
            $decoded_sec = json_decode($sc['sections_json'], true);
            if (is_array($decoded_sec)) $sections = $decoded_sec;
        }
        $content_data[$sc['scraped_id']] = [
            'scraped_id' => (int) $sc['scraped_id'],
            'title' => $sc['page_title'] ?? 'Untitled',
            'url' => $sc['page_url'],
            'hash' => $sc['content_hash'] ?? '—',
            'author' => $sc['meta_author'] ?? '—',
            'publish_date' => $sc['meta_publish_date'] ?? '—',
            'category' => $sc['meta_category'] ?? '—',
            'crawled' => $sc['scraped_at'],
            'content' => $sc['cleaned_content'] ?? '(no content)',
            'status' => $sc['status'] ?? 'new',
            'source_name' => $sc['source_name'] ?? 'N/A',
            'duplicate_sources' => $duplicate_sources,
            'enrichment_status' => $sc['enrichment_status'] ?? 'pending',
            'enriched_at' => $sc['enriched_at'] ?? '',
            'enrichment' => $enrichment,
            'search_document' => $sc['search_document'] ?? '',
            'sections' => $sections,
        ];
    }

    // Fetch history for content items on this page
    $history_data = [];
    if (!empty($recent_scraped)) {
        $ids = array_map(function ($sc) {
            return (int) $sc['scraped_id'];
        }, $recent_scraped);
        $placeholders = implode(',', $ids);
        $hist_result = $conn->query(
            "SELECT * FROM scraped_content_history WHERE scraped_id IN ($placeholders) ORDER BY changed_at DESC"
        );
        if ($hist_result) {
            while ($h = $hist_result->fetch_assoc()) {
                $sid = (int) $h['scraped_id'];
                if (!isset($history_data[$sid])) $history_data[$sid] = [];
                $history_data[$sid][] = [
                    'date' => $h['changed_at'],
                    'title' => $h['page_title'] ?? '',
                    'hash' => $h['content_hash'] ?? '',
                    'content' => mb_strimwidth($h['cleaned_content'] ?? '', 0, 500, '...'),
                ];
            }
        }
    }
    ?>
    <script>
        const contentData = <?php echo json_encode($content_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const historyData = <?php echo json_encode($history_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const activeSources = <?php echo json_encode(array_values(array_filter($sources, function($s) { return $s['is_active']; })), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        // Modern pane switching function
        function switchPane(paneId, navItem) {
            // Hide all panes
            document.querySelectorAll('.ws-pane').forEach(p => p.classList.remove('active'));
            // Show target pane
            const pane = document.getElementById(paneId);
            if (pane) pane.classList.add('active');
            
            // Remove active from ONLY nav items with data-tab attribute (not action buttons or stats)
            document.querySelectorAll('.ws-nav-item[data-tab]').forEach(item => {
                item.classList.remove('active');
            });
            // Then add active only to the clicked item
            if (navItem) navItem.classList.add('active');
        }

        // Tab switching for the legacy tab content sections & sidebar navigation items
        function switchDataTab(tabId, btnElement) {
            console.log('Switching to tab:', tabId); // Debug
            
            // Hide all tab content
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.style.display = 'none';
            });
            
            // Show selected tab
            const targetTab = document.getElementById(tabId);
            if (targetTab) {
                targetTab.style.display = 'block';
                console.log('Tab found and displayed:', tabId); // Debug
            } else {
                console.log('Tab NOT found:', tabId); // Debug
            }
            
            // Handle sidebar navigation items (.ws-nav-item)
            document.querySelectorAll('.ws-nav-item[data-tab]').forEach(item => {
                item.classList.remove('active');
                // Remove any inline styles that might have been applied by legacy/overridden code
                item.style.background = '';
                item.style.color = '';
                item.style.border = '';
            });
            
            // Handle top/legacy tab buttons (.tab-btn)
            document.querySelectorAll('.tab-btn').forEach(function(el) {
                el.style.background = '#f8fafc';
                el.style.color = '#64748b';
                el.style.border = '1px solid #cbd5e1';
            });
            
            // Set active states
            if (btnElement) {
                if (btnElement.classList && btnElement.classList.contains('ws-nav-item')) {
                    btnElement.classList.add('active');
                    
                    // Synchronize the corresponding top tab-btn if it exists
                    const topBtn = Array.from(document.querySelectorAll('.tab-btn')).find(b => {
                        const onclickStr = b.getAttribute('onclick') || '';
                        return onclickStr.includes(tabId);
                    });
                    if (topBtn) {
                        topBtn.style.background = 'linear-gradient(135deg, #002147 0%, #05356b 100%)';
                        topBtn.style.color = '#fff';
                        topBtn.style.border = 'none';
                    }
                } else if (btnElement.classList && btnElement.classList.contains('tab-btn')) {
                    btnElement.style.background = 'linear-gradient(135deg, #002147 0%, #05356b 100%)';
                    btnElement.style.color = '#fff';
                    btnElement.style.border = 'none';
                    
                    // Synchronize the corresponding sidebar nav item if it exists
                    const navItem = document.querySelector(`.ws-nav-item[data-tab="${tabId}"]`);
                    if (navItem) {
                        navItem.classList.add('active');
                    }
                } else if (typeof btnElement === 'string') {
                    // Find by data-tab attribute
                    const navItem = document.querySelector(`.ws-nav-item[data-tab="${btnElement}"]`);
                    if (navItem) {
                        navItem.classList.add('active');
                    }
                    // Find by onclick action for tab-btn
                    const topBtn = Array.from(document.querySelectorAll('.tab-btn')).find(b => {
                        const onclickStr = b.getAttribute('onclick') || '';
                        return onclickStr.includes(btnElement);
                    });
                    if (topBtn) {
                        topBtn.style.background = 'linear-gradient(135deg, #002147 0%, #05356b 100%)';
                        topBtn.style.color = '#fff';
                        topBtn.style.border = 'none';
                    }
                }
            } else {
                // If btnElement is not provided, try to find the nav item and tab button automatically
                const navItem = document.querySelector(`.ws-nav-item[data-tab="${tabId}"]`);
                if (navItem) {
                    navItem.classList.add('active');
                }
                const topBtn = Array.from(document.querySelectorAll('.tab-btn')).find(b => {
                    const onclickStr = b.getAttribute('onclick') || '';
                    return onclickStr.includes(tabId);
                });
                if (topBtn) {
                    topBtn.style.background = 'linear-gradient(135deg, #002147 0%, #05356b 100%)';
                    topBtn.style.color = '#fff';
                    topBtn.style.border = 'none';
                }
            }
            
            // Load content for specific tabs
            if (tabId === 'blockedUrlsTab') {
                if (typeof loadBlockedUrlsTab === 'function') loadBlockedUrlsTab();
            } else if (tabId === 'missingLinksTab') {
                if (typeof loadQueueTab === 'function') loadQueueTab();
            }
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Page loaded, initializing tabs'); // Debug
            
            // Remove any leftover active classes first
            document.querySelectorAll('.ws-nav-item[data-tab]').forEach(item => {
                item.classList.remove('active');
            });
            
            // Determine which tab to show
            const urlParams = new URLSearchParams(window.location.search);
            const tabParam = urlParams.get('tab');
            
            const validTabs = ['scrapedContentTab','sourcesTab','activityLogTab','blockedUrlsTab','missingLinksTab','statisticsTab'];
            let defaultTab = validTabs.includes(tabParam) ? tabParam : 'scrapedContentTab';
            
            console.log('Default tab:', defaultTab); // Debug
            switchDataTab(defaultTab);
        });

        // Source modal
        function showCreateModal() {
            document.getElementById('modalTitle').innerHTML = '<i class="fa-solid fa-earth-americas" style="color:#3b82f6;margin-right:12px"></i> Add Website to Scrape';
            document.getElementById('formAction').value = 'create_source';
            document.getElementById('sourceForm').reset();
            document.getElementById('sourceModal').style.display = 'flex';
        }

        function editSource(source) {
            document.getElementById('modalTitle').innerHTML = '<i class="fa-solid fa-pen-to-square" style="color:#3b82f6;margin-right:12px"></i> Edit Scraping Source';
            document.getElementById('formAction').value = 'update_source';
            document.getElementById('source_id').value = source.source_id;
            document.getElementById('source_name').value = source.source_name;
            document.getElementById('base_url').value = source.base_url;
            document.getElementById('scrape_frequency').value = source.scrape_frequency;
            document.getElementById('is_active').checked = source.is_active == 1;

            const config = JSON.parse(source.scraping_config || '{}');
            let maxD = config.max_depth || 2;
            if (maxD > 5 && maxD < 99) maxD = 99;
            document.getElementById('max_depth').value = maxD;
            document.getElementById('follow_links').checked = config.follow_links !== false;
            document.getElementById('content_selector').value = config.selectors?.content || 'body';
            document.getElementById('title_selector').value = config.selectors?.title || 'h1, title';
            document.getElementById('exclude_selector').value = config.selectors?.exclude || 'nav, footer, script, style';
            document.getElementById('include_patterns').value = (config.url_patterns?.include || []).join('\n');
            document.getElementById('exclude_patterns').value = (config.url_patterns?.exclude || []).join('\n');

            document.getElementById('sourceModal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('sourceModal').style.display = 'none';
        }

        // Scrape log rendering
        function scrapeModalToggleLog() {
            const container = document.getElementById('scrapeModalLog');
            const btn = document.getElementById('btnModalToggleLog');
            if (container.style.display === 'none') {
                container.style.display = 'block';
                btn.innerHTML = '<i class="fa-solid fa-terminal"></i> Hide Log';
                if (!scrapeLogRendered && window.currentScrapeData) {
                    renderScrapeModalLog(window.currentScrapeData.log_lines);
                    scrapeLogRendered = true;
                }
            } else {
                container.style.display = 'none';
                btn.innerHTML = '<i class="fa-solid fa-terminal"></i> Show Full Log';
            }
        }

        function scrapeModalFilterErrors() {
            const btn = document.getElementById('btnModalErrors');
            const container = document.getElementById('scrapeModalLog');
            scrapeLogFilterErrors = !scrapeLogFilterErrors;
            if (!window.currentScrapeData) return;
            
            if (scrapeLogFilterErrors) {
                const errors = window.currentScrapeData.log_lines.filter(function(l) {
                    return l.type === 'error';
                });
                renderScrapeModalLog(errors);
                btn.innerHTML = '<i class="fa-solid fa-list"></i> Show All Notes';
            } else {
                renderScrapeModalLog(window.currentScrapeData.log_lines);
                btn.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Show Errors Only';
            }
            container.style.display = 'block';
            document.getElementById('btnModalToggleLog').innerHTML = '<i class="fa-solid fa-terminal"></i> Hide Log';
            scrapeLogRendered = true;
        }

        // Fallback for the static old inline result, though it shouldn't show anymore:
        function toggleScrapeLog() { scrapeModalToggleLog(); }
        function filterScrapeErrors() { scrapeModalFilterErrors(); }

        // Content view modal
        function viewContent(scrapedId) {
            const data = contentData[scrapedId];
            if (!data) {
                alert('Content not found');
                return;
            }

            document.getElementById('edit_scraped_id').value = scrapedId;
            document.getElementById('contentTitle').textContent = data.title;
            document.getElementById('contentUrl').textContent = data.url;
            document.getElementById('contentHash').textContent = data.hash;
            document.getElementById('contentAuthor').textContent = data.author;
            document.getElementById('contentDate').textContent = data.publish_date;
            document.getElementById('contentCategory').textContent = data.category;
            document.getElementById('contentCrawled').textContent = data.crawled;

            var enrichPanel = document.getElementById('enrichmentPanel');
            var enrich = data.enrichment || {};
            if (data.enrichment_status || enrich.page_type || enrich.summary) {
                enrichPanel.style.display = 'block';
                var stEl = document.getElementById('contentEnrichStatus');
                stEl.textContent = data.enrichment_status || 'pending';
                stEl.className = 'status-badge enrich-' + (data.enrichment_status || 'pending');
                document.getElementById('contentEnrichedAt').textContent = data.enriched_at ? ('· ' + data.enriched_at) : '';
                document.getElementById('contentPageType').textContent = enrich.page_type ? ('Type: ' + enrich.page_type) : '';
                document.getElementById('contentSummary').textContent = enrich.summary || '';
                var tagsEl = document.getElementById('contentTags');
                tagsEl.innerHTML = '';
                (enrich.tags || []).forEach(function(t) {
                    var span = document.createElement('span');
                    span.className = 'enrichment-tag';
                    span.textContent = t;
                    tagsEl.appendChild(span);
                });
                document.getElementById('contentSearchDoc').textContent = data.search_document || '(none)';
                var secText = '';
                (data.sections || []).forEach(function(s) {
                    secText += (s.heading || '(section)') + '\n' + (s.text || '').substring(0, 200) + '\n\n';
                });
                document.getElementById('contentSections').textContent = secText || '(no sections)';
            } else {
                enrichPanel.style.display = 'none';
            }
            
            // Show duplicate sources if any
            const duplicateInfo = document.getElementById('duplicateSourceInfo');
            if (data.duplicate_sources && data.duplicate_sources.length > 0) {
                const sourceNames = data.duplicate_sources.map(d => d.source_name).join(', ');
                duplicateInfo.innerHTML = '<i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b;"></i> This URL also exists in: <strong>' + 
                    escapeHtml(sourceNames) + '</strong> (' + data.duplicate_sources.length + ' other source' + 
                    (data.duplicate_sources.length > 1 ? 's' : '') + ')';
                duplicateInfo.style.display = 'block';
            } else {
                duplicateInfo.style.display = 'none';
            }
            
            document.getElementById('contentBodyDisplay').textContent = data.content;
            document.getElementById('contentBodyEdit').value = data.content;

            // Reset edit mode
            document.getElementById('contentBodyDisplay').style.display = 'block';
            document.getElementById('contentBodyEdit').style.display = 'none';
            document.getElementById('contentEditActions').style.display = 'none';
            document.getElementById('btnEditContent').style.display = 'inline-block';

            // Load history
            const histSection = document.getElementById('historySection');
            const histList = document.getElementById('historyList');
            histList.innerHTML = '';

            const hist = historyData[scrapedId];
            if (hist && hist.length > 0) {
                histSection.style.display = 'block';
                hist.forEach(function(h) {
                    const div = document.createElement('div');
                    div.className = 'history-item';
                    div.innerHTML = '<div class="hist-date"><i class="fa-solid fa-clock"></i> ' +
                        escapeHtml(h.date) + ' — Hash: <code>' + escapeHtml(h.hash.substring(0, 12)) +
                        '</code></div><div class="hist-content">' + escapeHtml(h.content) + '</div>';
                    histList.appendChild(div);
                });
            } else {
                histSection.style.display = 'none';
            }

            var reBtn = document.getElementById('btnReEnrichContent');
            if (reBtn) {
                reBtn.onclick = function () {
                    if (typeof reEnrichPage === 'function') {
                        reEnrichPage(scrapedId, reBtn);
                    }
                };
            }

            document.getElementById('contentModal').style.display = 'block';
        }

        function closeContentModal() {
            document.getElementById('contentModal').style.display = 'none';
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.appendChild(document.createTextNode(text || ''));
            return div.innerHTML;
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const sourceModal = document.getElementById('sourceModal');
            const contentModal = document.getElementById('contentModal');
            if (event.target === sourceModal) closeModal();
            if (event.target === contentModal) closeContentModal();
        }

        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
                closeContentModal();
            }
        });

        // Edit Content Logic
        function toggleEditContent() {
            const display = document.getElementById('contentBodyDisplay');
            const edit = document.getElementById('contentBodyEdit');
            const actions = document.getElementById('contentEditActions');
            const btn = document.getElementById('btnEditContent');
            if (display.style.display === 'none') {
                display.style.display = 'block';
                edit.style.display = 'none';
                actions.style.display = 'none';
                btn.style.display = 'inline-block';
            } else {
                display.style.display = 'none';
                edit.style.display = 'block';
                actions.style.display = 'block';
                btn.style.display = 'none';
            }
        }

        // Bulk Selection Logic
        const selectAll = document.getElementById('selectAll');
        if (selectAll) {
            selectAll.addEventListener('change', function() {
                const checks = document.querySelectorAll('.bulk-select');
                checks.forEach(c => c.checked = this.checked);
                updateBulkUI();
            });
        }

        document.querySelectorAll('.bulk-select').forEach(c => {
            c.addEventListener('change', updateBulkUI);
        });

        function updateBulkUI() {
            const checked = document.querySelectorAll('.bulk-select:checked');
            const countEl = document.getElementById('bulkCount');
            if (countEl) countEl.textContent = checked.length;
        }

        function submitBulkAction(action) {
            const checked = document.querySelectorAll('.bulk-select:checked');
            if (checked.length === 0) {
                if (typeof showToast === 'function') {
                    showToast('warning', 'No Items Selected', 'Please select at least one page from the table first.', 4000);
                } else {
                    alert('Please select at least one item first.');
                }
                return;
            }

            if (action === 'bulk_delete_content') {
                if (!confirm('Delete selected content? This cannot be undone.')) return;

                const form = document.createElement('form');
                form.method = 'POST';
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = action;
                form.appendChild(actionInput);

                const ids_str = Array.from(checked).map(c => c.value).join(',');
                const inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'selected_ids_str';
                inp.value = ids_str;
                form.appendChild(inp);
                document.body.appendChild(form);
                form.submit();
            }
        }

        function showBulkStripModal() {
            const checked = document.querySelectorAll('.bulk-select:checked');
            if (checked.length === 0) {
                if (typeof showToast === 'function') {
                    showToast('warning', 'No Items Selected', 'Please select at least one page from the table first.', 4000);
                } else {
                    alert('Please select at least one item first.');
                }
                return;
            }

            const bulkStripInputs = document.getElementById('bulkStripInputs');
            bulkStripInputs.innerHTML = '';
            const ids_str = Array.from(checked).map(c => c.value).join(',');
            const inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'selected_ids_str';
            inp.value = ids_str;
            bulkStripInputs.appendChild(inp);
            document.getElementById('bulkStripModal').style.display = 'flex';
        }

        // ============ Toast Notification System ============
        function showToast(type, title, message, duration) {
            duration = duration || 6000;
            var container = document.getElementById('toastContainer');
            var icons = {
                success: 'fa-circle-check',
                error: 'fa-circle-xmark',
                info: 'fa-circle-info',
                warning: 'fa-triangle-exclamation'
            };

            var toast = document.createElement('div');
            toast.className = 'toast-notification toast-' + type;
            toast.style.position = 'relative';
            toast.innerHTML =
                '<div class="toast-icon"><i class="fa-solid ' + (icons[type] || icons.info) + '"></i></div>' +
                '<div class="toast-body"><div class="toast-title">' + escapeHtml(title) + '</div>' +
                '<div class="toast-message">' + escapeHtml(message) + '</div></div>' +
                '<button class="toast-close" onclick="dismissToast(this.parentElement)">&times;</button>' +
                '<div class="toast-progress" style="animation-duration:' + duration + 'ms"></div>';

            container.appendChild(toast);

            setTimeout(function() {
                dismissToast(toast);
            }, duration);
        }

        function dismissToast(el) {
            if (!el || el.classList.contains('toast-dismiss')) return;
            el.classList.add('toast-dismiss');
            setTimeout(function() {
                el.remove();
            }, 300);
        }

        // ============ AJAX Scrape with Progress Bar ============
        let activeScrapeController = null;
        let activeScrapeSourceId = null;

        function runScrapeAjax(sourceId, sourceName, forceRefresh, isBatch = false) {
            return new Promise((resolve, reject) => {
                var wrapper = document.getElementById('scrapeProgressWrapper');
                var elapsedEl = document.getElementById('scrapeElapsed');
                var statusEl = document.getElementById('scrapeProgressStatus');
                var resultArea = document.getElementById('ajaxScrapeResult');

                activeScrapeSourceId = sourceId;

                // Show progress bar
                wrapper.classList.add('active');
                if (!isBatch) resultArea.innerHTML = '';
                statusEl.textContent = 'Connecting to scraper for "' + sourceName + '"...';

                showToast('info', 'Scraping Started',
                    (forceRefresh ? 'Full re-scrape' : 'Incremental scrape') +
                    ' started for "' + sourceName + '"...', 4000);

                var startTime = Date.now();
                var timerInterval = setInterval(function() {
                    var secs = Math.floor((Date.now() - startTime) / 1000);
                    var mins = Math.floor(secs / 60);
                    var remSecs = secs % 60;
                    elapsedEl.textContent = (mins > 0 ? mins + 'm ' : '') + remSecs + 's';
                }, 1000);

                var statusMessages = [
                    'Fetching pages...', 'Parsing content...', 'Extracting text...',
                    'Checking for duplicates...', 'Processing results...', 'Still working...'
                ];
                var msgIdx = 0;
                var statusInterval = setInterval(function() {
                    msgIdx = (msgIdx + 1) % statusMessages.length;
                    statusEl.textContent = statusMessages[msgIdx];
                }, 4000);

                var formData = new FormData();
                formData.append('source_id', sourceId);
                if (forceRefresh) formData.append('force_refresh', '1');

                activeScrapeController = new AbortController();

                fetch('run_scrape_ajax.php', {
                        method: 'POST',
                        body: formData,
                        signal: activeScrapeController.signal
                    })
                    .then(function(res) {
                        return res.json();
                    })
                    .then(function(data) {
                        cleanupScrape(timerInterval, statusInterval, wrapper);
                        activeScrapeController = null;
                        if (data.error) {
                            showToast('error', 'Scraping Failed', data.error, 10000);
                            reject(data.error);
                            return;
                        }
                        var totalPages = data.new_count + data.updated_count;
                        if (data.success) {
                            var successMsg = 'Visited ' + data.pages_visited + ' pages in ' + data.elapsed + 's. ' +
                                totalPages + ' page(s) saved.';
                            if (data.failed_count > 0) {
                                showToast('warning', 'Completed with Errors', successMsg, 10000);
                            } else {
                                showToast('success', 'Scraping Completed', successMsg, 8000);
                            }
                        }
                        if (!isBatch) openScrapeModal(data);
                        resolve(data);
                    })
                    .catch(function(err) {
                        cleanupScrape(timerInterval, statusInterval, wrapper);
                        
                        if (err.name === 'AbortError') {
                            showToast('warning', 'Scraping Terminated', 'The scraping job was manually terminated.', 6000);
                        } else {
                            showToast('error', 'Request Failed', err.message || 'Network error', 10000);
                        }
                        activeScrapeController = null;
                        reject(err);
                    });
            });
        }

        async function runAllScrapes() {
            if (!activeSources || activeSources.length === 0) {
                showToast('warning', 'No Active Sources', 'Please enable at least one scraping source first.', 4000);
                return;
            }
            if (!confirm("Run scraping for all " + activeSources.length + " active sources sequentially?")) return;
            
            let totalVisited = 0;
            let totalNew = 0;
            let totalUpdated = 0;
            let errors = 0;

            for (let i = 0; i < activeSources.length; i++) {
                const s = activeSources[i];
                const batchMsg = " (Source " + (i + 1) + " of " + activeSources.length + ")";
                
                try {
                    const result = await runScrapeAjax(s.source_id, s.source_name + batchMsg, false, true);
                    if (result && result.success) {
                        totalVisited += result.pages_visited || 0;
                        totalNew += result.new_count || 0;
                        totalUpdated += result.updated_count || 0;
                    } else {
                        errors++;
                    }
                } catch (e) {
                    console.error("Batch scrape failed for " + s.source_name, e);
                    errors++;
                    if (!confirm("Scraping failed for " + s.source_name + ". Continue with next source?")) break;
                }
            }

            const summary = "Processed " + activeSources.length + " sources. Total: " + totalVisited + " pages visited, " + (totalNew + totalUpdated) + " items saved.";
            if (errors > 0) {
                showToast('warning', 'Batch Scraping Finished with Issues', summary + " (" + errors + " sources failed)", 10000);
            } else {
                showToast('success', 'Batch Scraping Finished', summary, 10000);
            }
            
            setTimeout(() => location.reload(), 3000);
        }

        function cleanupScrape(timerInt, statusInt, wrap) {
            clearInterval(timerInt);
            clearInterval(statusInt);
            wrap.classList.remove('active');
        }

        function terminateScrape() {
            if (!activeScrapeSourceId) return;
            if (confirm("Are you sure you want to forcibly stop the scraping operation?")) {
                const btn = document.getElementById('btnTerminateScrape');
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Stopping...';
                
                var fd = new FormData();
                fd.append('source_id', activeScrapeSourceId);
                fetch('terminate_scrape_ajax.php', {
                    method: 'POST',
                    body: fd
                }).then(() => {
                    if (activeScrapeController) {
                        activeScrapeController.abort();
                    }
                    setTimeout(() => {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fa-solid fa-stop-circle"></i> Stop Scraping';
                    }, 2000);
                }).catch(e => {
                    console.error('Termination failed:', e);
                });
            }
        }

        function openScrapeModal(data) {
            window.currentScrapeData = data;
            scrapeLogRendered = false;
            scrapeLogFilterErrors = false;
            
            document.getElementById('scrapeResultModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
            
            const isSuccess = data.success !== false;
            const hasErrors = data.failed_count > 0;
            
            const iconWrap = document.getElementById('scrapeModalIcon');
            const title = document.getElementById('scrapeModalTitle');
            const sub = document.getElementById('scrapeModalSub');
            
            if (isSuccess && !hasErrors) {
                iconWrap.style.background = '#dcfce7';
                iconWrap.style.color = '#15803d';
                iconWrap.innerHTML = '<i class="fa-solid fa-check"></i>';
                title.textContent = 'Scraping Completed Successfully';
            } else if (isSuccess && hasErrors) {
                iconWrap.style.background = '#fef3c7';
                iconWrap.style.color = '#b45309';
                iconWrap.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i>';
                title.textContent = 'Completed With Errors (' + data.failed_count + ')';
            } else {
                iconWrap.style.background = '#fee2e2';
                iconWrap.style.color = '#b91c1c';
                iconWrap.innerHTML = '<i class="fa-solid fa-xmark"></i>';
                title.textContent = 'Scraping Failed';
            }
            
            sub.textContent = 'Visited ' + data.pages_visited + ' pages in ' + data.elapsed + 's';
            
            const statsHtml = `
                <div style="flex:1;text-align:center;padding:16px;' + (data.new_count>0?'color:#166534;':'color:#64748b;') + '">
                    <div style="font-size:1.6rem;font-weight:700;">${data.new_count}</div>
                    <div style="font-size:.75rem;text-transform:uppercase;font-weight:700;letter-spacing:1px;margin-top:2px;opacity:.8">New</div>
                </div>
                <div style="width:1px;background:#e2e8f0;margin:12px 0;"></div>
                <div style="flex:1;text-align:center;padding:16px;' + (data.updated_count>0?'color:#1e40af;':'color:#64748b;') + '">
                    <div style="font-size:1.6rem;font-weight:700;">${data.updated_count}</div>
                    <div style="font-size:.75rem;text-transform:uppercase;font-weight:700;letter-spacing:1px;margin-top:2px;opacity:.8">Updated</div>
                </div>
                <div style="width:1px;background:#e2e8f0;margin:12px 0;"></div>
                <div style="flex:1;text-align:center;padding:16px;color:#64748b;">
                    <div style="font-size:1.6rem;font-weight:700;">${data.unchanged_count}</div>
                    <div style="font-size:.75rem;text-transform:uppercase;font-weight:700;letter-spacing:1px;margin-top:2px;opacity:.8">Unchanged</div>
                </div>
                <div style="width:1px;background:#e2e8f0;margin:12px 0;"></div>
                <div style="flex:1;text-align:center;padding:16px;color:#64748b;">
                    <div style="font-size:1.6rem;font-weight:700;">${data.skipped_count}</div>
                    <div style="font-size:.75rem;text-transform:uppercase;font-weight:700;letter-spacing:1px;margin-top:2px;opacity:.8">Skipped</div>
                </div>
                <div style="width:1px;background:#e2e8f0;margin:12px 0;"></div>
                <div style="flex:1;text-align:center;padding:16px;' + (data.failed_count>0?'color:#b91c1c;':'color:#64748b;') + '">
                    <div style="font-size:1.6rem;font-weight:700;">${data.failed_count}</div>
                    <div style="font-size:.75rem;text-transform:uppercase;font-weight:700;letter-spacing:1px;margin-top:2px;opacity:.8">Failed</div>
                </div>
            `;
            document.getElementById('scrapeModalStats').innerHTML = statsHtml;
            
            const btnErrs = document.getElementById('btnModalErrors');
            if (hasErrors) {
                btnErrs.style.display = 'inline-flex';
                btnErrs.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Show Errors Only (' + data.failed_count + ')';
            } else {
                btnErrs.style.display = 'none';
            }
            
            const logLines = data.log_lines || [];
            document.getElementById('scrapeModalLogCount').textContent = logLines.length + ' log entries recorded';
            document.getElementById('scrapeModalLog').style.display = 'none';
            document.getElementById('btnModalToggleLog').innerHTML = '<i class="fa-solid fa-terminal"></i> Show Full Log';
        }

        function closeScrapeModal() {
            document.getElementById('scrapeResultModal').style.display = 'none';
            document.body.style.overflow = '';
            window.currentScrapeData = null;
        }

        function renderScrapeModalLog(lines) {
            const container = document.getElementById('scrapeModalLog');
            container.innerHTML = '';
            const frag = document.createDocumentFragment();
            lines.forEach(function(item) {
                const div = document.createElement('div');
                div.className = 'log-line lt-' + item.type;
                div.style.padding = '8px 12px';
                div.style.borderBottom = '1px solid #f1f5f9';
                div.style.fontSize = '.85rem';
                div.style.fontFamily = 'monospace';
                div.style.whiteSpace = 'nowrap';
                div.style.overflow = 'hidden';
                div.style.textOverflow = 'ellipsis';
                if (item.type === 'error') div.style.color = '#ef4444';
                else if (item.type === 'success') div.style.color = '#10b981';
                else if (item.type === 'update') div.style.color = '#3b82f6';
                else if (item.type === 'skipped') div.style.color = '#94a3b8';
                else div.style.color = '#475569';
                
                div.textContent = item.text;
                div.title = item.text;
                frag.appendChild(div);
            });
            container.appendChild(frag);
        }

        // (Duplicate switchDataTab removed; logic merged above)
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
    <script src="js/kb_admin.js"></script>
    <?php include 'includes/global_toasts.php'; ?>
    <?php include 'includes/confirm_modal.php'; ?>
<script>
function showImportModal() {
    document.getElementById('importEnrichedModal').style.display = 'flex';
    document.getElementById('modal-import-file').value = '';
    document.getElementById('modal-preview-checkbox').checked = true;
    document.getElementById('import-report-container').style.display = 'none';
}

document.getElementById('btn-submit-import').addEventListener('click', async function() {
    const fileInput = document.getElementById('modal-import-file');
    if (!fileInput.files.length) {
        if (typeof showToast === 'function') {
            showToast('warning', 'No File Selected', 'Please select an Excel, CSV, or JSON file first.', 4000);
        } else {
            alert('Please select a file first.');
        }
        return;
    }
    
    const btn = this;
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';
    
    const isPreview = document.getElementById('modal-preview-checkbox').checked;
    const formData = new FormData();
    formData.append('file', fileInput.files[0]);
    
    try {
        const resp = await fetch('admin_api_proxy.php?action=import_enriched&preview=' + (isPreview ? 'true' : 'false'), {
            method: 'POST',
            body: formData
        });
        
        if (!resp.ok) {
            throw new Error('HTTP error ' + resp.status);
        }
        
        const res = await resp.json();
        
        // Show report metrics
        document.getElementById('report-total').textContent = res.processed_rows ?? 0;
        document.getElementById('report-updated').textContent = res.updated_count ?? 0;
        document.getElementById('report-skipped').textContent = res.skipped_count ?? 0;
        
        const errorsCount = (res.unmatched_ids ? res.unmatched_ids.length : 0) + (res.errors ? res.errors.length : 0);
        document.getElementById('report-errors').textContent = errorsCount;
        
        // Build report details log
        let details = "";
        if (res.import_batch_id) {
            details += `Batch ID: ${res.import_batch_id}\n`;
        }
        if (res.timestamp) {
            details += `Time: ${res.timestamp}\n`;
        }
        details += `Status: ${res.status || 'success'}\n`;
        details += `Processed: ${res.processed_rows ?? 0} rows\n`;
        details += `Updated: ${res.updated_count ?? 0} records\n`;
        details += `Skipped: ${res.skipped_count ?? 0} records\n`;
        
        if (res.unmatched_ids && res.unmatched_ids.length > 0) {
            details += `\n[!] Unmatched Scraped IDs (${res.unmatched_ids.length}):\n${res.unmatched_ids.join(', ')}\n`;
        }
        if (res.errors && res.errors.length > 0) {
            details += `\n[x] Errors (${res.errors.length}):\n${res.errors.join('\n')}\n`;
        }
        
        document.getElementById('report-details').textContent = details;
        document.getElementById('import-report-container').style.display = 'block';
        
        if (typeof showToast === 'function') {
            if (isPreview) {
                showToast('info', 'Preview Mode Done', `No database changes made. Diffs populated in report.`, 6000);
            } else {
                showToast('success', 'Import Completed', `Successfully imported: ${res.updated_count ?? 0} records updated.`, 6000);
                setTimeout(() => location.reload(), 3000);
            }
        }
    } catch (e) {
        console.error('Import failed', e);
        if (typeof showToast === 'function') {
            showToast('error', 'Import Failed', e.message || 'Error occurred during import.', 8000);
        } else {
            alert('Import failed: ' + e.message);
        }
    } finally {
        btn.disabled = false;
        btn.innerHTML = origHtml;
    }
});

// ── Add URL to existing source ─────────────────────────────────────────────
let _addUrlSourceId = null;

function openAddUrlModal(sourceId, sourceName) {
    _addUrlSourceId = sourceId;
    const sel = document.getElementById('addUrlSourceSel');
    if (sel) sel.value = sourceId;
    document.getElementById('addUrlInput').value = '';
    document.getElementById('urlExistsNote').style.display = 'none';
    document.getElementById('addUrlModal').style.display = 'flex';
}

// Live URL existence check
const addUrlInput = document.getElementById('addUrlInput');
if (addUrlInput) {
    let _urlCheckTimer = null;
    addUrlInput.addEventListener('input', function () {
        clearTimeout(_urlCheckTimer);
        const note = document.getElementById('urlExistsNote');
        const url  = this.value.trim();
        const sid  = document.getElementById('addUrlSourceSel')?.value;
        if (!url || !sid) { note.style.display = 'none'; return; }
        _urlCheckTimer = setTimeout(async () => {
            try {
                const res = await fetch(`ajax/scraper_ajax.php?action=check_url&source_id=${sid}&url=${encodeURIComponent(url)}`);
                const data = await res.json();
                if (data.exists) {
                    note.style.display = 'block';
                    note.innerHTML = `<span style="color:#f59e0b"><i class="fa-solid fa-triangle-exclamation"></i> This URL already exists under this source (scraped on ${data.existing?.scraped_at?.substring(0,10) ?? '?'}). Submitting will re-scrape and update it.</span>`;
                } else if (data.queued) {
                    note.style.display = 'block';
                    note.innerHTML = `<span style="color:#3b82f6"><i class="fa-solid fa-hourglass-half"></i> Already in queue (status: ${data.queue_entry?.status}). Submitting will re-activate it if dismissed.</span>`;
                } else {
                    note.style.display = 'block';
                    note.innerHTML = `<span style="color:#10b981"><i class="fa-solid fa-circle-check"></i> New URL — will be added to this source.</span>`;
                }
            } catch(e) { note.style.display = 'none'; }
        }, 500);
    });
}

// ── Missing Links Scanner ──────────────────────────────────────────────────
let _mlSourceId = null;
let _mlPollTimer = null;

function scanMissingLinks(sourceId, sourceName) {
    _mlSourceId = sourceId;
    if (_mlPollTimer) { clearTimeout(_mlPollTimer); _mlPollTimer = null; }

    document.getElementById('mlSourceName').textContent = sourceName;
    document.getElementById('mlQueueList').innerHTML = '';
    document.getElementById('mlActions').style.display = 'none';
    document.getElementById('missingLinksModal').style.display = 'flex';

    _mlSetStatus('info',
        '<i class="fa-solid fa-spinner fa-spin"></i> ' +
        '<strong>Starting smart domain scan…</strong><br>' +
        'Fetching pages and extracting all internal links. This may take 1–5 minutes for large sites.'
    );

    // Kick off the PHP scan (returns immediately, scan runs in background)
    const fd = new FormData();
    fd.append('action', 'start');
    fd.append('source_id', sourceId);

    fetch('ajax/scan_missing_ajax.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                _mlSetStatus('danger', '<i class="fa-solid fa-circle-xmark"></i> Scan error: ' + escHtml(data.error));
                return;
            }
            if (data.already_running) {
                _mlSetStatus('info', '<i class="fa-solid fa-spinner fa-spin"></i> Scan already in progress…');
            }
            // Start polling for progress immediately
            pollStatus();
        })
        .catch(err => {
            _mlSetStatus('danger', '<i class="fa-solid fa-circle-xmark"></i> Network error: ' + escHtml(err.message || String(err)));
        });

    // Poll status endpoint every 2s for live progress
    function pollStatus() {
        fetch(`ajax/scan_missing_ajax.php?action=status&source_id=${sourceId}`)
            .then(r => r.json())
            .then(st => {
                if (st.error) {
                    _mlSetStatus('danger', '<i class="fa-solid fa-circle-xmark"></i> ' + escHtml(st.error));
                    if (_mlPollTimer) clearTimeout(_mlPollTimer);
                    return;
                }
                if (st.running) {
                    // Show live progress
                    const elapsed = st.started ? Math.floor((Date.now()/1000) - st.started) : 0;
                    const elapsedStr = elapsed > 0 ? ` (${elapsed}s elapsed)` : '';
                    _mlSetStatus('info',
                        `<i class="fa-solid fa-spinner fa-spin"></i> <strong>${escHtml(st.phase || 'Scanning…')}</strong>${elapsedStr}<br>` +
                        `Fetched: <strong>${st.fetched || 0}</strong> pages &nbsp;|&nbsp; Missing found: <strong>${st.found || 0}</strong>`
                    );
                    // Continue polling
                    _mlPollTimer = setTimeout(pollStatus, 2000);
                } else if (st.finished) {
                    // Scan complete
                    if (_mlPollTimer) clearTimeout(_mlPollTimer);
                    const elapsed = st.elapsed || 0;
                    _mlSetStatus('success',
                        `<i class="fa-solid fa-circle-check"></i> <strong>Scan complete</strong> (${elapsed}s) — ` +
                        `fetched <strong>${st.fetched || 0}</strong> pages, ` +
                        `discovered <strong>${st.found || 0}</strong> missing link(s).`
                    );
                    loadMissingLinksQueue(sourceId);
                } else {
                    // Unknown state, keep polling
                    _mlPollTimer = setTimeout(pollStatus, 3000);
                }
            })
            .catch(() => {
                // Network error, retry
                _mlPollTimer = setTimeout(pollStatus, 4000);
            });
    }
}

function _mlSetStatus(type, html) {
    const colors = {
        info:    'background:#eff6ff;border-left:4px solid #3b82f6;color:#1e40af',
        success: 'background:#f0fdf4;border-left:4px solid #16a34a;color:#15803d',
        danger:  'background:#fef2f2;border-left:4px solid #dc2626;color:#b91c1c',
        warning: 'background:#fffbeb;border-left:4px solid #d97706;color:#92400e',
    };
    const style = colors[type] || colors.info;
    document.getElementById('mlScanStatus').innerHTML =
        `<div style="padding:12px 14px;border-radius:8px;font-size:.88rem;${style}">${html}</div>`;
}

function loadMissingLinksQueue(sourceId) {
    fetch(`ajax/scraper_ajax.php?action=link_queue&source_id=${sourceId}&status=pending`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) throw new Error(data.error || 'Unknown error');
            const items = data.items || [];
            if (items.length === 0) {
                document.getElementById('mlScanStatus').innerHTML =
                    '<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> No missing links found! This source appears complete.</div>';
                document.getElementById('mlQueueList').innerHTML = '';
                document.getElementById('mlActions').style.display = 'none';
                return;
            }
            document.getElementById('mlScanStatus').innerHTML =
                `<div class="alert alert-info"><i class="fa-solid fa-link-slash"></i> Found <strong>${items.length}</strong> missing link(s). Review below and choose which to scrape.</div>`;

            // Render table with checkboxes
            let html = `<table style="width:100%;border-collapse:collapse;font-size:.83rem">
                <thead><tr style="background:#f1f5f9">
                    <th style="padding:6px 8px"><input type="checkbox" id="mlSelectAll" onchange="mlToggleAll(this)"></th>
                    <th style="padding:6px 8px;text-align:left">URL</th>
                    <th style="padding:6px 8px;text-align:left">Discovered From</th>
                    <th style="padding:6px 8px">Depth</th>
                    <th style="padding:6px 8px">Dismiss</th>
                </tr></thead><tbody>`;
            items.forEach(it => {
                const shortUrl  = it.page_url.length > 60 ? it.page_url.substring(0,57)+'...' : it.page_url;
                const shortFrom = (it.discovered_from_url || '').length > 50
                    ? (it.discovered_from_url||'').substring(0,47)+'...' : (it.discovered_from_url||'—');
                html += `<tr style="border-bottom:1px solid #e2e8f0">
                    <td style="padding:5px 8px"><input type="checkbox" class="ml-chk" value="${it.queue_id}" checked></td>
                    <td style="padding:5px 8px"><a href="${escHtml(it.page_url)}" target="_blank" title="${escHtml(it.page_url)}">${escHtml(shortUrl)}</a></td>
                    <td style="padding:5px 8px;color:#64748b" title="${escHtml(it.discovered_from_url||'')}">${escHtml(shortFrom)}</td>
                    <td style="padding:5px 8px;text-align:center">
                        <span style="background:#e0f2fe;color:#0369a1;padding:2px 7px;border-radius:8px;font-size:.75rem">L${it.crawl_depth||'?'}</span>
                    </td>
                    <td style="padding:5px 8px;text-align:center">
                        <button class="btn btn-sm" style="padding:2px 7px;font-size:.75rem;background:#f1f5f9;color:#64748b;border:1px solid #cbd5e1"
                            onclick="dismissQueueItem(${it.queue_id}, this)">✕</button>
                    </td>
                </tr>`;
            });
            html += '</tbody></table>';
            document.getElementById('mlQueueList').innerHTML = html;
            document.getElementById('mlActions').style.display = 'flex';
        })
        .catch(e => {
            document.getElementById('mlScanStatus').innerHTML =
                `<div class="alert alert-danger">Error loading queue: ${e.message}</div>`;
        });
}

function mlToggleAll(cb) {
    document.querySelectorAll('.ml-chk').forEach(c => c.checked = cb.checked);
}

function getCheckedQueueIds() {
    return [...document.querySelectorAll('.ml-chk:checked')].map(c => parseInt(c.value));
}

function scrapeMissingSelected() {
    const ids = getCheckedQueueIds();
    if (!ids.length) { alert('No items selected.'); return; }
    scrapeMissingAll();  // For now scrape all pending (server handles queue ordering)
}

let _scrapePollTimer = null;

function scrapeMissingAll() {
    if (!_mlSourceId) return;
    if (_scrapePollTimer) clearTimeout(_scrapePollTimer);
    
    const fd = new FormData();
    fd.append('action', 'start');
    fd.append('source_id', _mlSourceId);
    
    _mlSetStatus('info', '<i class="fa-solid fa-spinner fa-spin"></i> Starting scraper…');
    document.getElementById('mlActions').style.display = 'none';
    
    fetch('ajax/scrape_missing_ajax.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                _mlSetStatus('danger', '<i class="fa-solid fa-circle-xmark"></i> Error: ' + escHtml(data.error));
                return;
            }
            if (data.nothing_to_scrape) {
                _mlSetStatus('info', '<i class="fa-solid fa-circle-info"></i> No pending URLs to scrape.');
                return;
            }
            if (data.already_running) {
                _mlSetStatus('info', '<i class="fa-solid fa-spinner fa-spin"></i> Scraping already in progress…');
            } else {
                _mlSetStatus('info', 
                    `<i class="fa-solid fa-spinner fa-spin"></i> Scraping <strong>${data.pending_count}</strong> URLs in background…`
                );
            }
            // Start polling
            pollScrapeStatus();
        })
        .catch(err => {
            _mlSetStatus('danger', '<i class="fa-solid fa-circle-xmark"></i> Network error: ' + escHtml(err.message));
        });
    
    function pollScrapeStatus() {
        fetch(`ajax/scrape_missing_ajax.php?action=status&source_id=${_mlSourceId}`)
            .then(r => r.json())
            .then(st => {
                if (st.error) {
                    _mlSetStatus('danger', '<i class="fa-solid fa-circle-xmark"></i> ' + escHtml(st.error));
                    return;
                }
                
                const q = st.queue || {};
                const total = q.total || 0;
                const pending = q.pending || 0;
                const scraping = q.scraping || 0;
                const done = q.done || 0;
                const failed = q.failed || 0;
                
                if (st.running) {
                    const elapsed = st.started ? Math.floor((Date.now()/1000) - st.started) : 0;
                    const elapsedStr = elapsed > 0 ? ` (${elapsed}s)` : '';
                    const progress = total > 0 ? Math.round((done / total) * 100) : 0;
                    
                    _mlSetStatus('info',
                        `<i class="fa-solid fa-spinner fa-spin"></i> <strong>Scraping in progress…</strong>${elapsedStr}<br>` +
                        `Progress: <strong>${done}/${total}</strong> (${progress}%) &nbsp;|&nbsp; ` +
                        `Pending: ${pending} &nbsp;|&nbsp; Failed: ${failed}`
                    );
                    _scrapePollTimer = setTimeout(pollScrapeStatus, 2000);
                } else if (st.finished) {
                    const elapsed = st.elapsed || (st.started ? Math.floor((Date.now()/1000) - st.started) : 0);
                    _mlSetStatus('success',
                        `<i class="fa-solid fa-circle-check"></i> <strong>Scraping complete</strong> (${elapsed}s) — ` +
                        `<strong>${done}</strong> pages scraped` +
                        (failed > 0 ? `, <strong>${failed}</strong> failed` : '')
                    );
                    // Reload the queue to show updated status
                    setTimeout(() => loadMissingLinksQueue(_mlSourceId), 1000);
                } else {
                    // Keep polling
                    _scrapePollTimer = setTimeout(pollScrapeStatus, 3000);
                }
            })
            .catch(() => {
                _scrapePollTimer = setTimeout(pollScrapeStatus, 4000);
            });
    }
}

function dismissQueueItem(queueId, btn) {
    const fd = new FormData();
    fd.append('action', 'dismiss_queue_item');
    fd.append('queue_id', queueId);
    fetch('web_scraper.php', { method: 'POST', body: fd }).then(() => {
        const row = btn.closest('tr');
        if (row) row.remove();
    });
}

function dismissMissingSelected() {
    const ids = getCheckedQueueIds();
    if (!ids.length) { alert('No items selected.'); return; }
    const fd = new FormData();
    fd.append('action', 'bulk_dismiss_queue');
    fd.append('source_id', _mlSourceId);
    fd.append('queue_ids', ids.join(','));
    fetch('web_scraper.php', { method: 'POST', body: fd })
        .then(() => loadMissingLinksQueue(_mlSourceId));
}

// ── Missing Links Queue Tab ────────────────────────────────────────────────
let _queueSourceId = null;
let _queueScrapePollTimer = null;
let _queueScanPollTimer = null;
let _queueAutoRefreshTimer = null;
let _queueTabActive = false;
let _queueAutoRefreshEnabled = true; // Auto-refresh enabled by default

function toggleQueueAutoRefresh() {
    _queueAutoRefreshEnabled = !_queueAutoRefreshEnabled;
    const toggleBtn = document.getElementById('queueAutoRefreshToggle');
    const statusSpan = document.getElementById('queueAutoRefreshStatus');
    
    if (_queueAutoRefreshEnabled) {
        toggleBtn.innerHTML = '<i class="fa-solid fa-toggle-on"></i> Auto-refresh ON';
        toggleBtn.style.background = '#10b981';
        if (_queueTabActive) {
            statusSpan.style.display = 'inline';
            // Restart timer
            if (_queueAutoRefreshTimer) clearInterval(_queueAutoRefreshTimer);
            _queueAutoRefreshTimer = setInterval(() => {
                if (_queueTabActive && _queueAutoRefreshEnabled) {
                    loadQueueTab(true, getCurrentQueuePage());
                }
            }, 10000);
        }
    } else {
        toggleBtn.innerHTML = '<i class="fa-solid fa-toggle-off"></i> Auto-refresh OFF';
        toggleBtn.style.background = '#64748b';
        statusSpan.style.display = 'none';
        // Stop timer
        if (_queueAutoRefreshTimer) {
            clearInterval(_queueAutoRefreshTimer);
            _queueAutoRefreshTimer = null;
        }
    }
}

function getCurrentQueuePage() {
    // Extract current page from pagination or default to 1
    const paginationDiv = document.getElementById('queuePagination');
    if (paginationDiv) {
        const activeBtn = paginationDiv.querySelector('.btn-primary[disabled]');
        if (activeBtn) {
            return parseInt(activeBtn.textContent) || 1;
        }
    }
    return 1;
}

function loadQueueTab(skipAutoRefresh = false, page = 1) {
    const sid    = document.getElementById('mlFilterSource')?.value  || '';
    const status = document.getElementById('mlFilterStatus')?.value || 'pending';
    const container = document.getElementById('queueTabContent');
    const scrapeBtn = document.getElementById('queueScrapeAllBtn');
    const scanBtn = document.getElementById('queueScanBtn');
    const stopScanBtn = document.getElementById('queueStopScanBtn');
    
    if (!container) return;
    
    // Initialize _queueSourceId from dropdown value
    if (sid) {
        _queueSourceId = parseInt(sid);
    } else {
        _queueSourceId = null;
    }
    
    // Start auto-refresh timer if enabled and not already running
    if (!skipAutoRefresh && _queueTabActive && _queueAutoRefreshEnabled && !_queueAutoRefreshTimer) {
        _queueAutoRefreshTimer = setInterval(() => {
            if (_queueTabActive && _queueAutoRefreshEnabled) {
                loadQueueTab(true, getCurrentQueuePage());
            }
        }, 10000); // Refresh every 10 seconds
    }
    
    // Update last refresh timestamp
    const refreshStatus = document.getElementById('queueAutoRefreshStatus');
    const lastRefresh = document.getElementById('queueLastRefresh');
    if (refreshStatus && _queueTabActive && _queueAutoRefreshEnabled) {
        refreshStatus.style.display = 'inline';
        if (lastRefresh) {
            const now = new Date();
            lastRefresh.textContent = now.toLocaleTimeString();
        }
    } else if (refreshStatus) {
        refreshStatus.style.display = 'none';
    }
    
    // Only show loading on initial load, not on auto-refresh
    const existingTable = container.querySelector('table');
    if (!existingTable) {
        container.innerHTML = '<p style="color:#94a3b8"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</p>';
    }

    let url = `ajax/scraper_ajax.php?action=link_queue&status=${encodeURIComponent(status)}&page=${page}`;
    if (sid) url += `&source_id=${sid}`;
    fetch(url).then(r => r.json()).then(data => {
        if (!data.success) { container.innerHTML = `<p style="color:#ef4444">Error: ${data.error}</p>`; return; }
        const items = data.items || [];
        if (!items.length) { 
            container.innerHTML = '<p style="color:#94a3b8;padding:20px 0">No items found for this filter.</p>'; 
            const refreshStatus = document.getElementById('queueAutoRefreshStatus');
            if (refreshStatus) refreshStatus.style.display = 'none';
            if (scrapeBtn) scrapeBtn.style.display = 'none';
            if (scanBtn && sid) scanBtn.style.display = 'inline-block';
            if (stopScanBtn) stopScanBtn.style.display = 'none';
            return; 
        }

        // Show appropriate buttons based on source selection and status
        const hasPending = items.some(it => it.status === 'pending');
        if (sid) {
            if (scanBtn) scanBtn.style.display = 'inline-block';
            if (scrapeBtn && hasPending && status === 'pending') {
                scrapeBtn.style.display = 'inline-block';
            } else if (scrapeBtn) {
                scrapeBtn.style.display = 'none';
            }
        } else {
            if (scrapeBtn) scrapeBtn.style.display = 'none';
            if (scanBtn) scanBtn.style.display = 'none';
        }
        if (stopScanBtn) stopScanBtn.style.display = 'none';

        const colorMap = { pending:'#f59e0b', scraping:'#3b82f6', done:'#10b981', failed:'#ef4444', skipped:'#94a3b8' };
        
        // Build tbody HTML
        let tbodyHtml = '';
        items.forEach(it => {
            const shortUrl = it.page_url.length > 65 ? it.page_url.substring(0,62)+'...' : it.page_url;
            const color    = colorMap[it.status] || '#64748b';
            tbodyHtml += `<tr style="border-bottom:1px solid #f1f5f9">
                <td style="padding:5px 10px"><a href="${escHtml(it.page_url)}" target="_blank" title="${escHtml(it.page_url)}">${escHtml(shortUrl)}</a></td>
                <td style="padding:5px 10px;color:#64748b;font-size:.78rem">${escHtml((it.discovered_from_url||'—').substring(0,55))}</td>
                <td style="padding:5px 10px;text-align:center"><span style="background:#e0f2fe;color:#0369a1;padding:2px 7px;border-radius:8px;font-size:.73rem">L${it.crawl_depth||0}</span></td>
                <td style="padding:5px 10px;text-align:center"><span style="background:${color}22;color:${color};padding:2px 9px;border-radius:10px;font-size:.73rem;font-weight:600;text-transform:uppercase">${it.status}</span></td>
                <td style="padding:5px 10px;color:#94a3b8;font-size:.78rem">${(it.discovered_at||'').substring(0,16)}</td>
            </tr>`;
        });
        
        // Check if table already exists
        const existingTable = container.querySelector('table');
        if (existingTable) {
            // Just update tbody to avoid blinking
            const tbody = existingTable.querySelector('tbody');
            if (tbody) {
                tbody.innerHTML = tbodyHtml;
            } else {
                // Fallback: replace entire table
                buildFullTable();
            }
            // Update pagination
            updatePagination();
        } else {
            // First load: build full table
            buildFullTable();
        }
        
        function buildFullTable() {
            let html = `<table style="width:100%;border-collapse:collapse;font-size:.82rem">
                <thead><tr style="background:#f1f5f9">
                    <th style="padding:6px 10px;text-align:left">URL</th>
                    <th style="padding:6px 10px;text-align:left">Discovered From</th>
                    <th style="padding:6px 10px">Depth</th>
                    <th style="padding:6px 10px">Status</th>
                    <th style="padding:6px 10px">Discovered</th>
                </tr></thead><tbody>${tbodyHtml}</tbody></table>`;
            
            // Add pagination
            html += buildPaginationHtml();
            
            container.innerHTML = html;
        }
        
        function buildPaginationHtml() {
            if (!data.total || data.total <= data.per_page) {
                // Update top info even if no pagination needed
                const topInfo = document.getElementById('queuePaginationTop');
                if (topInfo) {
                    topInfo.textContent = `Showing ${data.total} items`;
                }
                return '';
            }
            
            const currentPage = data.page;
            const totalPages = data.total_pages;
            const startItem = ((currentPage - 1) * data.per_page) + 1;
            const endItem = Math.min(currentPage * data.per_page, data.total);
            
            // Update top info
            const topInfo = document.getElementById('queuePaginationTop');
            if (topInfo) {
                topInfo.textContent = `Showing ${startItem}-${endItem} of ${data.total} items`;
            }
            
            let paginationHtml = `<div id="queuePagination" style="margin-top:15px;display:flex;justify-content:space-between;align-items:center;padding:10px;background:#f8fafc;border-radius:8px">`;
            paginationHtml += `<div style="color:#64748b;font-size:0.9rem">Showing ${startItem}-${endItem} of ${data.total} items</div>`;
            paginationHtml += `<div style="display:flex;gap:5px">`;
            
            // Previous button
            if (currentPage > 1) {
                paginationHtml += `<button class="btn btn-sm btn-secondary" onclick="loadQueueTab(false, ${currentPage - 1})">← Previous</button>`;
            }
            
            // Page numbers (show max 5 pages)
            const startPage = Math.max(1, currentPage - 2);
            const endPage = Math.min(totalPages, currentPage + 2);
            
            if (startPage > 1) {
                paginationHtml += `<button class="btn btn-sm btn-secondary" onclick="loadQueueTab(false, 1)">1</button>`;
                if (startPage > 2) paginationHtml += `<span style="padding:5px">...</span>`;
            }
            
            for (let i = startPage; i <= endPage; i++) {
                if (i === currentPage) {
                    paginationHtml += `<button class="btn btn-sm btn-primary" disabled>${i}</button>`;
                } else {
                    paginationHtml += `<button class="btn btn-sm btn-secondary" onclick="loadQueueTab(false, ${i})">${i}</button>`;
                }
            }
            
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) paginationHtml += `<span style="padding:5px">...</span>`;
                paginationHtml += `<button class="btn btn-sm btn-secondary" onclick="loadQueueTab(false, ${totalPages})">${totalPages}</button>`;
            }
            
            // Next button
            if (currentPage < totalPages) {
                paginationHtml += `<button class="btn btn-sm btn-secondary" onclick="loadQueueTab(false, ${currentPage + 1})">Next →</button>`;
            }
            
            paginationHtml += `</div></div>`;
            return paginationHtml;
        }
        
        function updatePagination() {
            const existingPagination = document.getElementById('queuePagination');
            if (existingPagination) {
                existingPagination.outerHTML = buildPaginationHtml();
            } else {
                container.insertAdjacentHTML('beforeend', buildPaginationHtml());
            }
        }
    }).catch(e => { 
        container.innerHTML = `<p style="color:#ef4444">Error: ${e.message}</p>`;
        const refreshStatus = document.getElementById('queueAutoRefreshStatus');
        if (refreshStatus) refreshStatus.style.display = 'none';
        if (scrapeBtn) scrapeBtn.style.display = 'none';
        if (scanBtn) scanBtn.style.display = 'none';
        if (stopScanBtn) stopScanBtn.style.display = 'none';
    });
}

function startScanFromQueue() {
    if (!_queueSourceId) {
        alert('Please select a specific source first');
        return;
    }
    
    if (_queueScanPollTimer) clearTimeout(_queueScanPollTimer);
    
    const statusDiv = document.getElementById('queueScrapeStatus');
    const scanBtn = document.getElementById('queueScanBtn');
    const stopScanBtn = document.getElementById('queueStopScanBtn');
    const scrapeBtn = document.getElementById('queueScrapeAllBtn');
    
    if (scanBtn) scanBtn.style.display = 'none';
    if (stopScanBtn) stopScanBtn.style.display = 'inline-block';
    if (scrapeBtn) scrapeBtn.disabled = true;
    
    const fd = new FormData();
    fd.append('action', 'start');
    fd.append('source_id', _queueSourceId);
    
    statusDiv.innerHTML = '<div class="alert alert-info"><i class="fa-solid fa-spinner fa-spin"></i> Starting scan…</div>';
    
    fetch('ajax/scan_missing_ajax.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                statusDiv.innerHTML = `<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> Error: ${escHtml(data.error)}</div>`;
                if (scanBtn) scanBtn.style.display = 'inline-block';
                if (stopScanBtn) stopScanBtn.style.display = 'none';
                return;
            }
            pollQueueScanStatus();
        })
        .catch(err => {
            statusDiv.innerHTML = `<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> Network error: ${escHtml(err.message)}</div>`;
            if (scanBtn) scanBtn.style.display = 'inline-block';
            if (stopScanBtn) stopScanBtn.style.display = 'none';
        });
    
    function pollQueueScanStatus() {
        fetch(`ajax/scan_missing_ajax.php?action=status&source_id=${_queueSourceId}`)
            .then(r => r.json())
            .then(st => {
                if (st.error) {
                    statusDiv.innerHTML = `<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> ${escHtml(st.error)}</div>`;
                    if (scanBtn) scanBtn.style.display = 'inline-block';
                    if (stopScanBtn) stopScanBtn.style.display = 'none';
                    if (scrapeBtn) scrapeBtn.disabled = false;
                    return;
                }
                
                if (st.running) {
                    const elapsed = st.started ? Math.floor((Date.now()/1000) - st.started) : 0;
                    statusDiv.innerHTML = 
                        `<div class="alert alert-info"><i class="fa-solid fa-spinner fa-spin"></i> <strong>Scanning for missing links…</strong> (${elapsed}s)<br>` +
                        `Fetched: <strong>${st.fetched || 0}</strong> pages &nbsp;|&nbsp; Missing found: <strong>${st.found || 0}</strong></div>`;
                    _queueScanPollTimer = setTimeout(pollQueueScanStatus, 2000);
                } else if (st.finished) {
                    const elapsed = st.elapsed || (st.started ? Math.floor((Date.now()/1000) - st.started) : 0);
                    statusDiv.innerHTML = 
                        `<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <strong>Scan complete</strong> (${elapsed}s) — ` +
                        `fetched <strong>${st.fetched || 0}</strong> pages, discovered <strong>${st.found || 0}</strong> missing link(s).</div>`;
                    if (scanBtn) scanBtn.style.display = 'inline-block';
                    if (stopScanBtn) stopScanBtn.style.display = 'none';
                    if (scrapeBtn) scrapeBtn.disabled = false;
                    setTimeout(() => loadQueueTab(), 1500);
                } else {
                    _queueScanPollTimer = setTimeout(pollQueueScanStatus, 3000);
                }
            })
            .catch(() => {
                _queueScanPollTimer = setTimeout(pollQueueScanStatus, 4000);
            });
    }
}

function stopScanFromQueue() {
    if (!_queueSourceId) return;
    
    const fd = new FormData();
    fd.append('action', 'stop');
    fd.append('source_id', _queueSourceId);
    
    fetch('ajax/scan_missing_ajax.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (_queueScanPollTimer) clearTimeout(_queueScanPollTimer);
            const statusDiv = document.getElementById('queueScrapeStatus');
            statusDiv.innerHTML = '<div class="alert alert-warning"><i class="fa-solid fa-stop"></i> Scan stopped by user</div>';
            const scanBtn = document.getElementById('queueScanBtn');
            const stopScanBtn = document.getElementById('queueStopScanBtn');
            const scrapeBtn = document.getElementById('queueScrapeAllBtn');
            if (scanBtn) scanBtn.style.display = 'inline-block';
            if (stopScanBtn) stopScanBtn.style.display = 'none';
            if (scrapeBtn) scrapeBtn.disabled = false;
            setTimeout(() => loadQueueTab(), 1000);
        });
}

function scrapeAllPendingFromQueue() {
    if (!_queueSourceId) {
        alert('Please select a specific source first');
        return;
    }
    
    if (_queueScrapePollTimer) clearTimeout(_queueScrapePollTimer);
    
    const statusDiv = document.getElementById('queueScrapeStatus');
    const scrapeBtn = document.getElementById('queueScrapeAllBtn');
    const stopScrapeBtn = document.getElementById('queueStopScrapeBtn');
    const scanBtn = document.getElementById('queueScanBtn');
    const stopScanBtn = document.getElementById('queueStopScanBtn');
    
    if (scrapeBtn) scrapeBtn.disabled = true;
    
    // First, check if scan is running and stop it
    fetch(`ajax/scan_missing_ajax.php?action=status&source_id=${_queueSourceId}`)
        .then(r => r.json())
        .then(scanStatus => {
            if (scanStatus.running) {
                // Stop the scan first
                statusDiv.innerHTML = '<div class="alert alert-warning"><i class="fa-solid fa-stop"></i> Stopping scan before scraping…</div>';
                
                const stopFd = new FormData();
                stopFd.append('action', 'stop');
                stopFd.append('source_id', _queueSourceId);
                
                return fetch('ajax/scan_missing_ajax.php', { method: 'POST', body: stopFd })
                    .then(r => r.json())
                    .then(() => {
                        // Wait 2 seconds for scan to fully stop
                        return new Promise(resolve => setTimeout(resolve, 2000));
                    });
            }
            return Promise.resolve();
        })
        .then(() => {
            // Now start the scrape
            const fd = new FormData();
            fd.append('action', 'start');
            fd.append('source_id', _queueSourceId);
            
            statusDiv.innerHTML = '<div class="alert alert-info"><i class="fa-solid fa-spinner fa-spin"></i> Starting scraper…</div>';
            
            return fetch('ajax/scrape_missing_ajax.php', { method: 'POST', body: fd });
        })
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                statusDiv.innerHTML = `<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> Error: ${escHtml(data.error)}</div>`;
                if (scrapeBtn) scrapeBtn.disabled = false;
                return;
            }
            if (data.nothing_to_scrape) {
                statusDiv.innerHTML = '<div class="alert alert-info"><i class="fa-solid fa-circle-info"></i> No pending URLs to scrape.</div>';
                if (scrapeBtn) scrapeBtn.disabled = false;
                return;
            }
            if (data.already_running) {
                statusDiv.innerHTML = '<div class="alert alert-info"><i class="fa-solid fa-spinner fa-spin"></i> Scraping already in progress…</div>';
            } else {
                statusDiv.innerHTML = `<div class="alert alert-info"><i class="fa-solid fa-spinner fa-spin"></i> Scraping <strong>${data.pending_count}</strong> URLs in background…</div>`;
            }
            
            // Hide scan buttons, show stop scrape button
            if (scanBtn) scanBtn.style.display = 'none';
            if (stopScanBtn) stopScanBtn.style.display = 'none';
            if (scrapeBtn) scrapeBtn.style.display = 'none';
            if (stopScrapeBtn) stopScrapeBtn.style.display = 'inline-block';
            
            pollQueueScrapeStatus();
        })
        .catch(err => {
            statusDiv.innerHTML = `<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> Network error: ${escHtml(err.message)}</div>`;
            if (scrapeBtn) scrapeBtn.disabled = false;
        });
    
    function pollQueueScrapeStatus() {
        fetch(`ajax/scrape_missing_ajax.php?action=status&source_id=${_queueSourceId}`)
            .then(r => r.json())
            .then(st => {
                if (st.error) {
                    statusDiv.innerHTML = `<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> ${escHtml(st.error)}</div>`;
                    if (scrapeBtn) scrapeBtn.disabled = false;
                    if (scrapeBtn) scrapeBtn.style.display = 'inline-block';
                    if (stopScrapeBtn) stopScrapeBtn.style.display = 'none';
                    return;
                }
                
                const q = st.queue || {};
                const total = q.total || 0;
                const pending = q.pending || 0;
                const scraping = q.scraping || 0;
                const done = q.done || 0;
                const failed = q.failed || 0;
                
                if (st.running) {
                    const elapsed = st.started ? Math.floor((Date.now()/1000) - st.started) : 0;
                    const elapsedStr = elapsed > 0 ? ` (${elapsed}s)` : '';
                    const progress = total > 0 ? Math.round((done / total) * 100) : 0;
                    
                    statusDiv.innerHTML = 
                        `<div class="alert alert-info"><i class="fa-solid fa-spinner fa-spin"></i> <strong>Scraping in progress…</strong>${elapsedStr}<br>` +
                        `Progress: <strong>${done}/${total}</strong> (${progress}%) &nbsp;|&nbsp; ` +
                        `Pending: ${pending} &nbsp;|&nbsp; Failed: ${failed}</div>`;
                    _queueScrapePollTimer = setTimeout(pollQueueScrapeStatus, 2000);
                } else if (st.finished) {
                    const elapsed = st.elapsed || (st.started ? Math.floor((Date.now()/1000) - st.started) : 0);
                    statusDiv.innerHTML = 
                        `<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <strong>Scraping complete</strong> (${elapsed}s) — ` +
                        `<strong>${done}</strong> pages scraped` +
                        (failed > 0 ? `, <strong>${failed}</strong> failed` : '') + '</div>';
                    if (scrapeBtn) scrapeBtn.disabled = false;
                    if (scrapeBtn) scrapeBtn.style.display = 'inline-block';
                    if (stopScrapeBtn) stopScrapeBtn.style.display = 'none';
                    
                    // Re-enable scan button
                    if (scanBtn && _queueSourceId) scanBtn.style.display = 'inline-block';
                    
                    setTimeout(() => loadQueueTab(), 1500);
                } else {
                    _queueScrapePollTimer = setTimeout(pollQueueScrapeStatus, 3000);
                }
            })
            .catch(() => {
                _queueScrapePollTimer = setTimeout(pollQueueScrapeStatus, 4000);
            });
    }
}

function stopScrapeFromQueue() {
    if (!_queueSourceId) {
        alert('Please select a source first');
        return;
    }
    
    if (!confirm('Stop scraping? URLs being processed will be reset to pending status.')) {
        return;
    }
    
    const statusDiv = document.getElementById('queueScrapeStatus');
    const scrapeBtn = document.getElementById('queueScrapeAllBtn');
    const stopScrapeBtn = document.getElementById('queueStopScrapeBtn');
    const scanBtn = document.getElementById('queueScanBtn');
    
    statusDiv.innerHTML = '<div class="alert alert-warning"><i class="fa-solid fa-spinner fa-spin"></i> Stopping scraper…</div>';
    
    const fd = new FormData();
    fd.append('action', 'stop');
    fd.append('source_id', _queueSourceId);
    
    fetch('ajax/scrape_missing_ajax.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                statusDiv.innerHTML = `<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> Error: ${escHtml(data.error)}</div>`;
                return;
            }
            
            statusDiv.innerHTML = `<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> ${escHtml(data.message)}</div>`;
            
            // Clear poll timer
            if (_queueScrapePollTimer) {
                clearTimeout(_queueScrapePollTimer);
                _queueScrapePollTimer = null;
            }
            
            // Show scrape button, hide stop button
            if (scrapeBtn) {
                scrapeBtn.disabled = false;
                scrapeBtn.style.display = 'inline-block';
            }
            if (stopScrapeBtn) stopScrapeBtn.style.display = 'none';
            if (scanBtn) scanBtn.style.display = 'inline-block';
            
            // Reload queue to show updated statuses
            setTimeout(() => loadQueueTab(), 1000);
        })
        .catch(err => {
            statusDiv.innerHTML = `<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> Network error: ${escHtml(err.message)}</div>`;
        });
}

// ── Cleanup Malformed URLs ─────────────────────────────────────────────────
function cleanupMalformedUrls() {
    const statusDiv = document.getElementById('queueScrapeStatus');
    
    // First, count malformed URLs
    statusDiv.innerHTML = '<div class="alert alert-info"><i class="fa-solid fa-spinner fa-spin"></i> Checking for malformed URLs...</div>';
    
    fetch('ajax/cleanup_malformed_ajax.php?action=count')
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                statusDiv.innerHTML = `<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> Error: ${escHtml(data.error)}</div>`;
                return;
            }
            
            if (data.malformed_count === 0) {
                statusDiv.innerHTML = '<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> No malformed URLs found! Queue is clean.</div>';
                setTimeout(() => { statusDiv.innerHTML = ''; }, 3000);
                return;
            }
            
            // Show confirmation with examples
            let examplesHtml = '';
            if (data.examples && data.examples.length > 0) {
                examplesHtml = '<br><br><strong>Examples:</strong><ul style="margin:5px 0;padding-left:20px;font-size:0.85em">';
                data.examples.forEach(url => {
                    const shortUrl = url.length > 80 ? url.substring(0, 77) + '...' : url;
                    examplesHtml += `<li style="word-break:break-all">${escHtml(shortUrl)}</li>`;
                });
                examplesHtml += '</ul>';
            }
            
            const confirmed = confirm(
                `Found ${data.malformed_count} malformed URLs out of ${data.total_count} total.\n\n` +
                `These include:\n` +
                `- Duplicate paths (/news/123/news/123)\n` +
                `- Recursive encoding (?p=123%2F%3Fp%3D456)\n` +
                `- Mixed URL formats (/news/123?p=456)\n\n` +
                `Delete all ${data.malformed_count} malformed URLs?`
            );
            
            if (!confirmed) {
                statusDiv.innerHTML = '';
                return;
            }
            
            // Delete malformed URLs
            statusDiv.innerHTML = `<div class="alert alert-warning"><i class="fa-solid fa-spinner fa-spin"></i> Deleting ${data.malformed_count} malformed URLs...</div>`;
            
            fetch('ajax/cleanup_malformed_ajax.php?action=delete', { method: 'POST' })
                .then(r => r.json())
                .then(result => {
                    if (!result.success) {
                        statusDiv.innerHTML = `<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> Error: ${escHtml(result.error)}</div>`;
                        return;
                    }
                    
                    statusDiv.innerHTML = 
                        `<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <strong>Cleanup complete!</strong><br>` +
                        `Deleted: <strong>${result.deleted}</strong> malformed URLs<br>` +
                        `Remaining: <strong>${result.new_total}</strong> total, <strong>${result.new_pending}</strong> pending</div>`;
                    
                    // Refresh the queue table
                    setTimeout(() => {
                        loadQueueTab();
                        setTimeout(() => { statusDiv.innerHTML = ''; }, 2000);
                    }, 1500);
                })
                .catch(err => {
                    statusDiv.innerHTML = `<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> Network error: ${escHtml(err.message)}</div>`;
                });
        })
        .catch(err => {
            statusDiv.innerHTML = `<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> Network error: ${escHtml(err.message)}</div>`;
        });
}

// Auto-load queue tab when clicked and manage auto-refresh
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        // Stop auto-refresh for all tabs first
        _queueTabActive = false;
        if (_queueAutoRefreshTimer) {
            clearInterval(_queueAutoRefreshTimer);
            _queueAutoRefreshTimer = null;
        }
        
        // If switching to Missing Links Queue tab, activate auto-refresh
        if (this.getAttribute('onclick')?.includes('missingLinksTab')) {
            _queueTabActive = true;
            setTimeout(loadQueueTab, 100);
        }
    });
});

// ── Page Tree ──────────────────────────────────────────────────────────────
function openPageTree(sourceId, sourceName) {
    document.getElementById('ptSourceName').textContent = sourceName;
    document.getElementById('ptLoading').style.display = 'block';
    document.getElementById('ptTree').innerHTML = '';
    document.getElementById('pageTreeModal').style.display = 'flex';

    fetch(`ajax/scraper_ajax.php?action=link_tree&source_id=${sourceId}`)
        .then(r => r.json())
        .then(data => {
            document.getElementById('ptLoading').style.display = 'none';
            if (!data.success) throw new Error(data.error);
            renderPageTree(data.by_depth, data.total);
        })
        .catch(e => {
            document.getElementById('ptLoading').style.display = 'none';
            document.getElementById('ptTree').innerHTML =
                `<div class="alert alert-danger">Error loading tree: ${e.message}</div>`;
        });
}

function renderPageTree(byDepth, total) {
    const container = document.getElementById('ptTree');
    if (!byDepth || Object.keys(byDepth).length === 0) {
        container.innerHTML = '<p style="color:#94a3b8">No pages scraped yet for this source.</p>';
        return;
    }

    const depthLabels = { '0': 'L0 — Seed Pages', '1': 'L1 — Direct Links', '2': 'L2 — Section Pages', '3': 'L3+ — Deep Pages' };
    const depthColors = { '0': '#7c3aed', '1': '#2563eb', '2': '#0891b2', '3': '#059669' };
    const statusColors = { 'new':'#f59e0b', 'updated':'#3b82f6', 'processed':'#10b981', 'indexed':'#8b5cf6', 'failed':'#ef4444' };

    let html = `<div style="margin-bottom:10px;color:#64748b;font-size:.85rem"><strong>${total}</strong> total pages indexed</div>`;

    Object.entries(byDepth).sort((a,b)=>parseInt(a[0])-parseInt(b[0])).forEach(([depth, pages]) => {
        const label = depthLabels[depth] || `L${depth}+ Pages`;
        const color = depthColors[depth] || '#64748b';
        const groupId = `ptDepth${depth}`;
        html += `
        <div style="margin-bottom:16px">
          <div style="display:flex;align-items:center;gap:10px;cursor:pointer;padding:8px 12px;background:#f8fafc;border-radius:8px;border-left:4px solid ${color}"
               onclick="ptToggle('${groupId}')">
            <i class="fa-solid fa-chevron-down" id="ptIcon${depth}" style="color:${color};transition:.2s;font-size:.8rem"></i>
            <span style="font-weight:600;color:${color}">${escHtml(label)}</span>
            <span style="background:${color}22;color:${color};padding:1px 9px;border-radius:10px;font-size:.75rem;font-weight:700">${pages.length}</span>
          </div>
          <div id="${groupId}" style="padding:0 4px">
            <table style="width:100%;border-collapse:collapse;font-size:.8rem;margin-top:6px">
              <thead><tr style="background:#f1f5f9">
                <th style="padding:5px 8px;text-align:left;font-weight:600;color:#475569">Page Title</th>
                <th style="padding:5px 8px;text-align:left;font-weight:600;color:#475569">URL</th>
                <th style="padding:5px 8px;font-weight:600;color:#475569">Status</th>
                <th style="padding:5px 8px;font-weight:600;color:#475569">Scraped</th>
              </tr></thead><tbody>`;
        pages.forEach(p => {
            const sc = statusColors[p.status] || '#64748b';
            const shortUrl = p.page_url.length > 55 ? p.page_url.substring(0,52)+'...' : p.page_url;
            html += `<tr style="border-bottom:1px solid #f1f5f9">
                <td style="padding:5px 8px">${escHtml(p.page_title||'Untitled')}</td>
                <td style="padding:5px 8px"><a href="${escHtml(p.page_url)}" target="_blank" style="color:#3b82f6;text-decoration:none" title="${escHtml(p.page_url)}">${escHtml(shortUrl)}</a></td>
                <td style="padding:5px 8px;text-align:center">
                  <span style="background:${sc}22;color:${sc};padding:1px 8px;border-radius:8px;font-size:.72rem;font-weight:600;text-transform:uppercase">${p.status||'?'}</span>
                </td>
                <td style="padding:5px 8px;color:#94a3b8">${(p.scraped_at||'').substring(0,10)}</td>
            </tr>`;
        });
        html += `</tbody></table></div></div>`;
    });

    container.innerHTML = html;
}

function ptToggle(groupId) {
    const el = document.getElementById(groupId);
    const depth = groupId.replace('ptDepth','');
    const icon  = document.getElementById(`ptIcon${depth}`);
    if (!el) return;
    if (el.style.display === 'none') {
        el.style.display = 'block';
        if (icon) icon.style.transform = 'rotate(0deg)';
    } else {
        el.style.display = 'none';
        if (icon) icon.style.transform = 'rotate(-90deg)';
    }
}

// ── Cleanup Scraped Malformed URLs ─────────────────────────────────────────
function cleanupScrapedMalformed() {
    // Check for malformed URLs in scraped_content
    const statusArea = document.createElement('div');
    statusArea.id = 'scrapedCleanupStatus';
    statusArea.style.cssText = 'margin: 15px 0; padding: 15px; border-radius: 8px; background: #f8fafc;';
    
    const filterBar = document.querySelector('.filter-bar');
    if (filterBar && filterBar.nextSibling) {
        filterBar.parentNode.insertBefore(statusArea, filterBar.nextSibling);
    }
    
    statusArea.innerHTML = '<div class="alert alert-info"><i class="fa-solid fa-spinner fa-spin"></i> Checking for malformed URLs in scraped content...</div>';
    
    fetch('ajax/cleanup_scraped_malformed_ajax.php?action=count')
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                statusArea.innerHTML = `<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> Error: ${escHtml(data.error)}</div>`;
                return;
            }
            
            if (data.malformed_count === 0) {
                statusArea.innerHTML = '<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> No malformed URLs found in scraped content! All clean.</div>';
                setTimeout(() => { statusArea.remove(); }, 3000);
                return;
            }
            
            // Show examples
            let examplesHtml = '<br><br><strong>Examples of pages that will be deleted:</strong><ul style="margin:10px 0;padding-left:20px;font-size:0.9em">';
            data.examples.forEach(ex => {
                examplesHtml += `<li><strong>${escHtml(ex.title || 'Untitled')}</strong><br>`;
                examplesHtml += `<span style="color:#64748b;font-size:0.9em">${escHtml(ex.url)}</span><br>`;
                examplesHtml += `<span style="color:#94a3b8;font-size:0.85em">Scraped: ${ex.scraped_at}</span></li>`;
            });
            examplesHtml += '</ul>';
            
            statusArea.innerHTML = 
                `<div class="alert alert-warning">` +
                `<strong><i class="fa-solid fa-triangle-exclamation"></i> Found ${data.malformed_count} malformed pages</strong> out of ${data.total_count} total.<br><br>` +
                `These pages have URLs with <code>?p=</code> parameters on paths (e.g., <code>/course/name?p=123</code>).<br>` +
                `They contain <strong>wrong content</strong> due to WordPress redirects and should be deleted.` +
                examplesHtml +
                `<br><br>` +
                `<button class="btn btn-danger" onclick="confirmDeleteScrapedMalformed(${data.malformed_count})">` +
                `<i class="fa-solid fa-trash"></i> Delete ${data.malformed_count} Malformed Pages` +
                `</button> ` +
                `<button class="btn btn-secondary" onclick="document.getElementById('scrapedCleanupStatus').remove()">` +
                `<i class="fa-solid fa-xmark"></i> Cancel` +
                `</button>` +
                `</div>`;
        })
        .catch(err => {
            statusArea.innerHTML = `<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> Network error: ${escHtml(err.message)}</div>`;
        });
}

function confirmDeleteScrapedMalformed(count) {
    if (!confirm(`Delete ${count} malformed pages from scraped content?\n\nThese pages have wrong content due to WordPress redirects.\nThey will be re-discovered and re-scraped correctly on next scan.`)) {
        return;
    }
    
    const statusArea = document.getElementById('scrapedCleanupStatus');
    statusArea.innerHTML = `<div class="alert alert-warning"><i class="fa-solid fa-spinner fa-spin"></i> Deleting ${count} malformed pages...</div>`;
    
    fetch('ajax/cleanup_scraped_malformed_ajax.php?action=delete', { method: 'POST' })
        .then(r => r.json())
        .then(result => {
            if (!result.success) {
                statusArea.innerHTML = `<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> Error: ${escHtml(result.error)}</div>`;
                return;
            }
            
            let statusHtml = '<ul style="margin:5px 0;padding-left:20px">';
            for (const [status, count] of Object.entries(result.status_breakdown)) {
                statusHtml += `<li>${status}: ${count}</li>`;
            }
            statusHtml += '</ul>';
            
            statusArea.innerHTML = 
                `<div class="alert alert-success">` +
                `<i class="fa-solid fa-circle-check"></i> <strong>Cleanup complete!</strong><br>` +
                `Deleted: <strong>${result.deleted}</strong> malformed pages<br>` +
                `Remaining: <strong>${result.new_total}</strong> total pages<br><br>` +
                `<strong>Status breakdown:</strong>` +
                statusHtml +
                `<br>` +
                `<em>Run scan to re-discover these URLs and scrape them correctly.</em>` +
                `</div>`;
            
            // Reload the page after 3 seconds
            setTimeout(() => {
                window.location.reload();
            }, 3000);
        })
        .catch(err => {
            statusArea.innerHTML = `<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> Network error: ${escHtml(err.message)}</div>`;
        });
}

// ── Migrate Duplicates to Blocked URLs ─────────────────────────────────────
function migrateDuplicatesToBlocked() {
    // Check for duplicates in scraped_content
    const statusArea = document.createElement('div');
    statusArea.id = 'migrateDuplicatesStatus';
    statusArea.style.cssText = 'margin: 15px 0; padding: 15px; border-radius: 8px; background: #f8fafc;';
    
    const filterBar = document.querySelector('.filter-bar');
    if (filterBar && filterBar.nextSibling) {
        filterBar.parentNode.insertBefore(statusArea, filterBar.nextSibling);
    }
    
    statusArea.innerHTML = '<div class="alert alert-info"><i class="fa-solid fa-spinner fa-spin"></i> Checking for duplicate pages...</div>';
    
    fetch('ajax/migrate_duplicates_to_blocked_ajax.php?action=check')
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                statusArea.innerHTML = `<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> Error: ${escHtml(data.error)}</div>`;
                return;
            }
            
            if (data.duplicates_in_scraped === 0) {
                statusArea.innerHTML = 
                    `<div class="alert alert-success">` +
                    `<i class="fa-solid fa-circle-check"></i> No duplicates found in scraped_content!<br>` +
                    `<strong>${data.duplicates_in_blocked}</strong> duplicates already in blocked_urls table.` +
                    `</div>`;
                setTimeout(() => { statusArea.remove(); }, 3000);
                return;
            }
            
            // Show examples
            let examplesHtml = '';
            if (data.examples && data.examples.length > 0) {
                examplesHtml = '<br><br><strong>Examples of pages that will be migrated:</strong><ul style="margin:10px 0;padding-left:20px;font-size:0.9em">';
                data.examples.forEach(ex => {
                    examplesHtml += `<li><strong>${escHtml(ex.page_title || 'Untitled')}</strong><br>`;
                    examplesHtml += `<span style="color:#64748b;font-size:0.9em">${escHtml(ex.page_url)}</span><br>`;
                    examplesHtml += `<span style="color:#94a3b8;font-size:0.85em">Scraped: ${ex.scraped_at}</span></li>`;
                });
                examplesHtml += '</ul>';
            }
            
            statusArea.innerHTML = 
                `<div class="alert alert-warning">` +
                `<strong><i class="fa-solid fa-database"></i> Found ${data.duplicates_in_scraped} duplicate pages</strong> in scraped_content.<br><br>` +
                `These pages will be:<br>` +
                `• Moved to <code>blocked_urls</code> table (permanently blocked from scanning/scraping)<br>` +
                `• Deleted from <code>scraped_content</code> (frees up space)<br>` +
                `• Removed from <code>scrape_link_queue</code> if present<br><br>` +
                `<strong>Already in blocked_urls:</strong> ${data.duplicates_in_blocked} duplicates` +
                examplesHtml +
                `<br><br>` +
                `<button class="btn btn-warning" onclick="confirmMigrateDuplicates(${data.duplicates_in_scraped})">` +
                `<i class="fa-solid fa-database"></i> Migrate ${data.duplicates_in_scraped} Duplicates` +
                `</button> ` +
                `<button class="btn btn-secondary" onclick="document.getElementById('migrateDuplicatesStatus').remove()">` +
                `<i class="fa-solid fa-xmark"></i> Cancel` +
                `</button>` +
                `</div>`;
        })
        .catch(err => {
            statusArea.innerHTML = `<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> Network error: ${escHtml(err.message)}</div>`;
        });
}

function confirmMigrateDuplicates(count) {
    if (!confirm(`Migrate ${count} duplicate pages to blocked_urls?\n\nThis will:\n• Move them to blocked_urls table\n• Delete them from scraped_content\n• Prevent them from being re-scanned or re-scraped\n\nThis is a one-time migration and is recommended.`)) {
        return;
    }
    
    const statusArea = document.getElementById('migrateDuplicatesStatus');
    statusArea.innerHTML = `<div class="alert alert-warning"><i class="fa-solid fa-spinner fa-spin"></i> Migrating ${count} duplicate pages...</div>`;
    
    fetch('ajax/migrate_duplicates_to_blocked_ajax.php?action=migrate', { method: 'POST' })
        .then(r => r.json())
        .then(result => {
            if (!result.success) {
                statusArea.innerHTML = `<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> Error: ${escHtml(result.error)}</div>`;
                return;
            }
            
            statusArea.innerHTML = 
                `<div class="alert alert-success">` +
                `<i class="fa-solid fa-circle-check"></i> <strong>Migration complete!</strong><br><br>` +
                `<strong>Migrated to blocked_urls:</strong> ${result.migrated_to_blocked} pages<br>` +
                `<strong>Deleted from scraped_content:</strong> ${result.deleted_from_scraped} pages<br>` +
                `<strong>Deleted from queue:</strong> ${result.deleted_from_queue} items<br><br>` +
                `<strong>New totals:</strong><br>` +
                `• scraped_content: ${result.new_scraped_total} pages<br>` +
                `• blocked_urls: ${result.new_blocked_total} blocked URLs<br><br>` +
                `<em>These URLs will now be permanently blocked from scanning and scraping.</em>` +
                `</div>`;
            
            // Reload the page after 3 seconds
            setTimeout(() => {
                window.location.reload();
            }, 3000);
        })
        .catch(err => {
            statusArea.innerHTML = `<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> Network error: ${escHtml(err.message)}</div>`;
        });
}

// ── Similar Content Detection ──────────────────────────────────────────────
function openSimilarContentModal() {
    document.getElementById('similarContentLoading').style.display = 'block';
    document.getElementById('similarContentStats').innerHTML = '';
    document.getElementById('similarContentResults').innerHTML = '';
    document.getElementById('similarContentModal').style.display = 'flex';
    
    const sourceId = <?php echo $filter_source > 0 ? $filter_source : 0; ?>;
    
    fetch(`ajax/find_similar_content_ajax.php?action=find&source_id=${sourceId}`)
        .then(r => r.json())
        .then(data => {
            document.getElementById('similarContentLoading').style.display = 'none';
            
            if (!data.success) {
                document.getElementById('similarContentResults').innerHTML =
                    `<div class="alert alert-danger">Error: ${escHtml(data.error)}</div>`;
                return;
            }
            
            // Show stats
            const stats = data.stats;
            document.getElementById('similarContentStats').innerHTML =
                `<div style="background:#f8fafc;padding:15px;border-radius:8px;border:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
                    <div>
                        <strong>Content Analysis:</strong> 
                        ${stats.total_pages} total pages | 
                        ${stats.unique_content} unique content | 
                        ${stats.duplicate_pages} duplicate pages
                    </div>
                    <button class="btn btn-primary" onclick="keepLatestFromAllGroups()" title="Automatically keep the most recent page from each duplicate group">
                        <i class="fa-solid fa-bolt"></i> Keep Latest from All Groups
                    </button>
                </div>`;
            
            if (data.duplicates.length === 0) {
                document.getElementById('similarContentResults').innerHTML =
                    '<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> No duplicate content found!</div>';
                return;
            }
            
            // Show duplicate groups
            let html = `<p style="color:#64748b;margin-bottom:15px">Found ${data.duplicates.length} groups of duplicate content:</p>`;
            
            data.duplicates.forEach((group, idx) => {
                html += `<div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:15px;margin-bottom:15px">`;
                html += `<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">`;
                html += `<strong style="color:#0f172a">Group ${idx + 1}: ${group.duplicate_count} duplicate pages</strong>`;
                html += `<span style="color:#64748b;font-size:0.85em">Hash: ${group.content_hash.substring(0, 12)}...</span>`;
                html += `</div>`;
                
                // Add manual URL input for this group
                html += `<div style="background:#fef3c7;border:1px solid #fbbf24;border-radius:6px;padding:12px;margin-bottom:12px">`;
                html += `<div style="display:flex;gap:10px;align-items:center">`;
                html += `<div style="flex:1">`;
                html += `<label style="display:block;color:#92400e;font-weight:600;margin-bottom:5px;font-size:0.9em">`;
                html += `<i class="fa-solid fa-hand-pointer"></i> Keep this URL instead (optional):`;
                html += `</label>`;
                html += `<input type="text" id="keepUrl_${idx}" class="form-control" placeholder="https://mmu.ac.ug/page-to-keep" style="width:100%;padding:6px;border:1px solid #d97706;border-radius:4px;font-size:0.9em">`;
                html += `<small style="color:#78350f;display:block;margin-top:3px">Enter a URL to keep, then click "Use This URL" to mark all ${group.duplicate_count} pages as duplicates</small>`;
                html += `</div>`;
                html += `<button class="btn btn-warning btn-sm" onclick="keepManualUrl('${group.content_hash}', ${idx})" style="white-space:nowrap;align-self:flex-end">`;
                html += `<i class="fa-solid fa-check"></i> Use This URL`;
                html += `</button>`;
                html += `</div>`;
                html += `</div>`;
                
                html += `<table style="width:100%;border-collapse:collapse;font-size:0.9em">`;
                html += `<thead><tr style="background:#f8fafc">`;
                html += `<th style="padding:8px;text-align:left">Page</th>`;
                html += `<th style="padding:8px;text-align:center">Status</th>`;
                html += `<th style="padding:8px;text-align:center">Scraped</th>`;
                html += `<th style="padding:8px;text-align:center">Size</th>`;
                html += `<th style="padding:8px;text-align:center">Action</th>`;
                html += `</tr></thead><tbody>`;
                
                group.pages.forEach((page, pidx) => {
                    const statusColor = page.status === 'duplicate' ? '#94a3b8' : '#10b981';
                    html += `<tr style="border-bottom:1px solid #f1f5f9">`;
                    html += `<td style="padding:8px">`;
                    html += `<div style="font-weight:600;margin-bottom:3px">${escHtml(page.page_title || 'Untitled')}</div>`;
                    html += `<div style="color:#64748b;font-size:0.85em;word-break:break-all">${escHtml(page.page_url)}</div>`;
                    html += `</td>`;
                    html += `<td style="padding:8px;text-align:center"><span style="background:${statusColor}22;color:${statusColor};padding:3px 8px;border-radius:4px;font-size:0.8em">${page.status}</span></td>`;
                    html += `<td style="padding:8px;text-align:center;color:#64748b;font-size:0.85em">${page.scraped_at.substring(0, 16)}</td>`;
                    html += `<td style="padding:8px;text-align:center;color:#64748b;font-size:0.85em">${(page.content_length / 1024).toFixed(1)} KB</td>`;
                    html += `<td style="padding:8px;text-align:center">`;
                    if (page.status !== 'duplicate') {
                        html += `<button class="btn btn-success btn-sm" onclick="keepThisPage(${page.scraped_id}, '${group.content_hash}')" title="Keep this page, mark others as duplicate">`;
                        html += `<i class="fa-solid fa-check"></i> Keep This`;
                        html += `</button>`;
                    } else {
                        html += `<span style="color:#94a3b8;font-size:0.85em">Marked duplicate</span>`;
                    }
                    html += `</td>`;
                    html += `</tr>`;
                });
                
                html += `</tbody></table>`;
                html += `</div>`;
            });
            
            document.getElementById('similarContentResults').innerHTML = html;
        })
        .catch(e => {
            document.getElementById('similarContentLoading').style.display = 'none';
            document.getElementById('similarContentResults').innerHTML =
                `<div class="alert alert-danger">Error: ${escHtml(e.message)}</div>`;
        });
}

function keepManualUrl(contentHash, groupIndex) {
    const urlInput = document.getElementById(`keepUrl_${groupIndex}`);
    const keepUrl = urlInput?.value.trim();
    
    if (!keepUrl) {
        alert('Please enter a URL to keep');
        return;
    }
    
    // Validate URL format
    try {
        new URL(keepUrl);
    } catch (e) {
        alert('Please enter a valid URL (e.g., https://mmu.ac.ug/page)');
        return;
    }
    
    if (!confirm(`Keep this URL and mark all others in this group as duplicates?\n\nKeep: ${keepUrl}\n\nAll other pages with the same content will be:\n• Moved to blocked_urls\n• Deleted from scraped_content\n• Prevented from being re-scraped`)) {
        return;
    }
    
    const fd = new FormData();
    fd.append('action', 'keep_manual_url');
    fd.append('keep_url', keepUrl);
    fd.append('content_hash', contentHash);
    
    fetch('ajax/find_similar_content_ajax.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert('Error: ' + data.error);
                return;
            }
            
            alert(`✓ Success!\n\nKept: ${keepUrl}\nMarked as duplicate: ${data.deleted_from_scraped} pages\nMoved to blocked_urls: ${data.moved_to_blocked} pages`);
            openSimilarContentModal(); // Refresh the modal
        })
        .catch(e => {
            alert('Error: ' + e.message);
        });
}

let _selectedKeepScrapedId = null; // Store selected page for manual marking

function keepThisPage(scrapedId, contentHash) {
    if (!confirm('Mark all other pages with identical content as duplicate?')) {
        return;
    }
    
    // Store this as the selected "keep" page for manual marking
    _selectedKeepScrapedId = scrapedId;
    
    const fd = new FormData();
    fd.append('action', 'keep_one');
    fd.append('keep_scraped_id', scrapedId);
    fd.append('content_hash', contentHash);
    
    fetch('ajax/find_similar_content_ajax.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert('Error: ' + data.error);
                return;
            }
            
            alert(`Successfully marked ${data.deleted_from_scraped} pages as duplicate and moved to blocked_urls`);
            openSimilarContentModal(); // Refresh the modal
        })
        .catch(e => {
            alert('Error: ' + e.message);
        });
}

function markManualDuplicate() {
    const urlInput = document.getElementById('manualDuplicateUrl');
    const duplicateUrl = urlInput?.value.trim();
    
    if (!duplicateUrl) {
        alert('Please enter a URL to mark as duplicate');
        return;
    }
    
    // Validate URL format
    try {
        new URL(duplicateUrl);
    } catch (e) {
        alert('Please enter a valid URL (e.g., https://mmu.ac.ug/page)');
        return;
    }
    
    const keepInfo = _selectedKeepScrapedId 
        ? `\n\nThis will be marked as duplicate of the page you selected (scraped_id: ${_selectedKeepScrapedId})`
        : '';
    
    if (!confirm(`Mark this URL as duplicate?${keepInfo}\n\nURL: ${duplicateUrl}\n\nThis will:\n• Move it to blocked_urls\n• Delete it from scraped_content\n• Prevent it from being re-scraped`)) {
        return;
    }
    
    const fd = new FormData();
    fd.append('action', 'mark_url_duplicate');
    fd.append('duplicate_url', duplicateUrl);
    if (_selectedKeepScrapedId) {
        fd.append('keep_scraped_id', _selectedKeepScrapedId);
    }
    
    fetch('ajax/find_similar_content_ajax.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert('Error: ' + data.error);
                return;
            }
            
            alert('✓ URL marked as duplicate and moved to blocked_urls');
            urlInput.value = ''; // Clear input
            _selectedKeepScrapedId = null; // Reset selection
            openSimilarContentModal(); // Refresh the modal
        })
        .catch(e => {
            alert('Error: ' + e.message);
        });
}

function keepLatestFromAllGroups() {
    if (!confirm('⚡ BULK ACTION: Keep Latest from All Groups\n\nThis will automatically:\n• Keep the MOST RECENT page from each duplicate group\n• Mark all older duplicates as blocked\n• Delete older duplicates from scraped_content\n\nThis action affects ALL duplicate groups shown.\n\nContinue?')) {
        return;
    }
    
    const sourceId = <?php echo $filter_source > 0 ? $filter_source : 0; ?>;
    
    // Show loading state
    const statsDiv = document.getElementById('similarContentStats');
    const originalStats = statsDiv.innerHTML;
    statsDiv.innerHTML = '<div style="background:#fef3c7;padding:15px;border-radius:8px;border:1px solid #fbbf24;text-align:center"><i class="fa-solid fa-spinner fa-spin"></i> Processing all groups...</div>';
    
    const fd = new FormData();
    fd.append('action', 'keep_latest_all');
    if (sourceId > 0) {
        fd.append('source_id', sourceId);
    }
    
    fetch('ajax/find_similar_content_ajax.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert('Error: ' + data.error);
                statsDiv.innerHTML = originalStats;
                return;
            }
            
            alert(`✓ Bulk Action Complete!\n\nGroups processed: ${data.groups_processed}\nPages kept (latest): ${data.groups_processed}\nPages marked as duplicate: ${data.total_deleted}\nMoved to blocked_urls: ${data.total_moved}\n\n${data.message}`);
            openSimilarContentModal(); // Refresh the modal
        })
        .catch(e => {
            alert('Error: ' + e.message);
            statsDiv.innerHTML = originalStats;
        });
}

function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Blocked URLs Tab ───────────────────────────────────────────────────────

function loadBlockedUrlsTab() {
    const sourceId = document.getElementById('blockedFilterSource')?.value || '';
    const reason = document.getElementById('blockedFilterReason')?.value || '';
    const search = document.getElementById('blockedSearchUrl')?.value || '';
    const container = document.getElementById('blockedUrlsTableContainer');
    
    if (!container) return;
    
    container.innerHTML = '<p style="color:#94a3b8;text-align:center;padding:40px 0"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</p>';
    
    let url = `ajax/blocked_urls_ajax.php?action=list`;
    if (sourceId) url += `&source_id=${sourceId}`;
    if (reason) url += `&reason=${encodeURIComponent(reason)}`;
    if (search) url += `&search=${encodeURIComponent(search)}`;
    
    fetch(url)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                container.innerHTML = `<p style="color:#ef4444">Error: ${escHtml(data.error)}</p>`;
                return;
            }
            
            const items = data.blocked_urls || [];
            
            // Update stats
            updateBlockedStats(data.total);
            
            if (!items.length) {
                container.innerHTML = '<p style="color:#94a3b8;text-align:center;padding:40px 0">No blocked URLs found.</p>';
                return;
            }
            
            const reasonColors = {
                duplicate: '#3b82f6',
                malformed: '#ef4444',
                manual: '#f59e0b',
                redirect_loop: '#8b5cf6'
            };
            
            let html = `<table style="width:100%;border-collapse:collapse;font-size:0.85rem">
                <thead><tr style="background:#f1f5f9">
                    <th style="padding:8px;text-align:left;width:40%">URL</th>
                    <th style="padding:8px;text-align:center">Reason</th>
                    <th style="padding:8px;text-align:left">Source</th>
                    <th style="padding:8px;text-align:center">Blocked At</th>
                    <th style="padding:8px;text-align:left">Blocked By</th>
                    <th style="padding:8px;text-align:center">Actions</th>
                </tr></thead><tbody>`;
            
            items.forEach(item => {
                const shortUrl = item.page_url.length > 70 ? item.page_url.substring(0,67)+'...' : item.page_url;
                const color = reasonColors[item.reason] || '#64748b';
                const blockedDate = new Date(item.blocked_at).toLocaleString();
                
                html += `<tr style="border-bottom:1px solid #f1f5f9">
                    <td style="padding:8px">
                        <a href="${escHtml(item.page_url)}" target="_blank" title="${escHtml(item.page_url)}" style="color:#0369a1;text-decoration:none">
                            ${escHtml(shortUrl)}
                        </a>
                        ${item.notes ? `<br><small style="color:#94a3b8" title="${escHtml(item.notes)}">${escHtml(item.notes.substring(0,60))}...</small>` : ''}
                    </td>
                    <td style="padding:8px;text-align:center">
                        <span style="background:${color}22;color:${color};padding:3px 10px;border-radius:12px;font-size:0.75rem;font-weight:600;text-transform:uppercase">
                            ${escHtml(item.reason)}
                        </span>
                    </td>
                    <td style="padding:8px;color:#64748b">${escHtml(item.source_name || 'Unknown')}</td>
                    <td style="padding:8px;text-align:center;color:#94a3b8;font-size:0.8rem">${blockedDate}</td>
                    <td style="padding:8px;color:#64748b">${escHtml(item.blocked_by_username || 'System')}</td>
                    <td style="padding:8px;text-align:center">
                        <button class="btn btn-sm btn-danger" onclick="unblockUrl(${item.blocked_id})" title="Unblock this URL">
                            <i class="fa-solid fa-unlock"></i>
                        </button>
                    </td>
                </tr>`;
            });
            
            html += '</tbody></table>';
            
            // Add pagination if needed
            if (data.pages > 1) {
                html += '<div style="margin-top:15px;text-align:center">';
                html += `<p style="color:#64748b">Page ${data.page} of ${data.pages} (${data.total} total)</p>`;
                html += '</div>';
            }
            
            container.innerHTML = html;
        })
        .catch(err => {
            container.innerHTML = `<p style="color:#ef4444">Network error: ${escHtml(err.message)}</p>`;
        });
}

function updateBlockedStats(total) {
    const statsDiv = document.getElementById('blockedUrlsStats');
    if (statsDiv) {
        statsDiv.innerHTML = `<strong>${total}</strong> blocked URLs`;
    }
}

function clearBlockedFilters() {
    document.getElementById('blockedFilterSource').value = '';
    document.getElementById('blockedFilterReason').value = '';
    document.getElementById('blockedSearchUrl').value = '';
    loadBlockedUrlsTab();
}

function unblockUrl(blockedId) {
    if (!confirm('Unblock this URL? It will be able to be scanned and scraped again.')) {
        return;
    }
    
    const fd = new FormData();
    fd.append('action', 'unblock');
    fd.append('blocked_ids', blockedId);
    
    fetch('ajax/blocked_urls_ajax.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(result => {
            if (!result.success) {
                alert('Error: ' + result.error);
                return;
            }
            alert(`Unblocked ${result.unblocked} URL(s)`);
            loadBlockedUrlsTab();
        })
        .catch(err => alert('Network error: ' + err.message));
}

function showBulkBlockModal() {
    const modal = document.createElement('div');
    modal.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:9999';
    
    modal.innerHTML = `
        <div style="background:#fff;padding:25px;border-radius:10px;max-width:600px;width:90%;max-height:80vh;overflow-y:auto">
            <h3 style="margin:0 0 20px 0"><i class="fa-solid fa-plus"></i> Bulk Block URLs</h3>
            
            <div style="margin-bottom:15px">
                <label style="display:block;margin-bottom:5px;font-weight:600">Source:</label>
                <select id="bulkBlockSource" class="form-select" required>
                    <option value="">Select Source</option>
                    <?php foreach ($sources as $s): ?>
                        <option value="<?php echo $s['source_id']; ?>"><?php echo htmlspecialchars($s['source_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div style="margin-bottom:15px">
                <label style="display:block;margin-bottom:5px;font-weight:600">Method:</label>
                <select id="bulkBlockMethod" class="form-select" onchange="toggleBulkBlockMethod()">
                    <option value="urls">List of URLs</option>
                    <option value="pattern">Regex Pattern</option>
                </select>
            </div>
            
            <div id="bulkBlockUrlsDiv" style="margin-bottom:15px">
                <label style="display:block;margin-bottom:5px;font-weight:600">URLs (one per line):</label>
                <textarea id="bulkBlockUrls" class="form-control" rows="8" placeholder="https://example.com/page1&#10;https://example.com/page2"></textarea>
            </div>
            
            <div id="bulkBlockPatternDiv" style="margin-bottom:15px;display:none">
                <label style="display:block;margin-bottom:5px;font-weight:600">Regex Pattern:</label>
                <input type="text" id="bulkBlockPattern" class="form-control" placeholder=".*\\?p=.*">
                <small style="color:#64748b">Example: .*\\?p=.* (matches URLs with ?p= parameter)</small>
            </div>
            
            <div style="margin-bottom:15px">
                <label style="display:block;margin-bottom:5px;font-weight:600">Reason:</label>
                <select id="bulkBlockReason" class="form-select">
                    <option value="manual">Manual</option>
                    <option value="malformed">Malformed</option>
                    <option value="duplicate">Duplicate</option>
                    <option value="redirect_loop">Redirect Loop</option>
                </select>
            </div>
            
            <div style="margin-bottom:20px">
                <label style="display:block;margin-bottom:5px;font-weight:600">Notes (optional):</label>
                <textarea id="bulkBlockNotes" class="form-control" rows="2" placeholder="Reason for blocking..."></textarea>
            </div>
            
            <div style="display:flex;gap:10px;justify-content:flex-end">
                <button class="btn btn-secondary" onclick="this.closest('div[style*=fixed]').remove()">Cancel</button>
                <button class="btn btn-primary" onclick="executeBulkBlock()">Block URLs</button>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
}

function toggleBulkBlockMethod() {
    const method = document.getElementById('bulkBlockMethod').value;
    document.getElementById('bulkBlockUrlsDiv').style.display = method === 'urls' ? 'block' : 'none';
    document.getElementById('bulkBlockPatternDiv').style.display = method === 'pattern' ? 'block' : 'none';
}

function executeBulkBlock() {
    const sourceId = document.getElementById('bulkBlockSource').value;
    const method = document.getElementById('bulkBlockMethod').value;
    const urls = document.getElementById('bulkBlockUrls').value;
    const pattern = document.getElementById('bulkBlockPattern').value;
    const reason = document.getElementById('bulkBlockReason').value;
    const notes = document.getElementById('bulkBlockNotes').value;
    
    if (!sourceId) {
        alert('Please select a source');
        return;
    }
    
    if (method === 'urls' && !urls.trim()) {
        alert('Please enter URLs to block');
        return;
    }
    
    if (method === 'pattern' && !pattern.trim()) {
        alert('Please enter a regex pattern');
        return;
    }
    
    const fd = new FormData();
    fd.append('action', 'bulk_block');
    fd.append('source_id', sourceId);
    fd.append('reason', reason);
    fd.append('notes', notes);
    
    if (method === 'urls') {
        fd.append('urls', urls);
    } else {
        fd.append('pattern', pattern);
    }
    
    fetch('ajax/blocked_urls_ajax.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(result => {
            if (!result.success) {
                alert('Error: ' + result.error);
                return;
            }
            alert(`Blocked ${result.blocked} URLs`);
            document.querySelector('div[style*="fixed"]').remove();
            loadBlockedUrlsTab();
        })
        .catch(err => alert('Network error: ' + err.message));
}

function showBlockedStatsModal() {
    const sourceId = document.getElementById('blockedFilterSource')?.value || '';
    
    fetch(`ajax/blocked_urls_ajax.php?action=stats${sourceId ? '&source_id='+sourceId : ''}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert('Error: ' + data.error);
                return;
            }
            
            const modal = document.createElement('div');
            modal.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:9999';
            
            let byReasonHtml = '<ul>';
            for (const [reason, count] of Object.entries(data.by_reason)) {
                byReasonHtml += `<li><strong>${reason}</strong>: ${count}</li>`;
            }
            byReasonHtml += '</ul>';
            
            let bySourceHtml = '<ul>';
            for (const [source, count] of Object.entries(data.by_source)) {
                bySourceHtml += `<li><strong>${source}</strong>: ${count}</li>`;
            }
            bySourceHtml += '</ul>';
            
            let recentHtml = '<ul>';
            data.recent_blocks.forEach(item => {
                recentHtml += `<li>${item.date}: ${item.count} blocked</li>`;
            });
            recentHtml += '</ul>';
            
            let blockersHtml = '<ul>';
            data.top_blockers.forEach(item => {
                blockersHtml += `<li><strong>${escHtml(item.username || 'Unknown')}</strong>: ${item.count}</li>`;
            });
            blockersHtml += '</ul>';
            
            modal.innerHTML = `
                <div style="background:#fff;padding:25px;border-radius:10px;max-width:700px;width:90%;max-height:80vh;overflow-y:auto">
                    <h3 style="margin:0 0 20px 0"><i class="fa-solid fa-chart-pie"></i> Blocked URLs Statistics</h3>
                    
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">
                        <div style="background:#f8fafc;padding:15px;border-radius:8px">
                            <h4 style="margin:0 0 10px 0;color:#64748b">Total Blocked</h4>
                            <p style="font-size:2rem;font-weight:bold;margin:0;color:#002147">${data.total}</p>
                        </div>
                        
                        <div style="background:#f8fafc;padding:15px;border-radius:8px">
                            <h4 style="margin:0 0 10px 0;color:#64748b">By Reason</h4>
                            ${byReasonHtml}
                        </div>
                        
                        <div style="background:#f8fafc;padding:15px;border-radius:8px">
                            <h4 style="margin:0 0 10px 0;color:#64748b">By Source</h4>
                            ${bySourceHtml}
                        </div>
                        
                        <div style="background:#f8fafc;padding:15px;border-radius:8px">
                            <h4 style="margin:0 0 10px 0;color:#64748b">Last 7 Days</h4>
                            ${recentHtml}
                        </div>
                    </div>
                    
                    <div style="background:#f8fafc;padding:15px;border-radius:8px;margin-bottom:20px">
                        <h4 style="margin:0 0 10px 0;color:#64748b">Top Blockers</h4>
                        ${blockersHtml}
                    </div>
                    
                    <div style="text-align:right">
                        <button class="btn btn-secondary" onclick="this.closest('div[style*=fixed]').remove()">Close</button>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
        })
        .catch(err => alert('Network error: ' + err.message));
}

function exportBlockedUrls() {
    const sourceId = document.getElementById('blockedFilterSource')?.value || '';
    
    fetch(`ajax/blocked_urls_ajax.php?action=export${sourceId ? '&source_id='+sourceId : ''}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert('Error: ' + data.error);
                return;
            }
            
            // Create download link
            const blob = new Blob([data.csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = data.filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
            
            alert('Exported successfully!');
        })
        .catch(err => alert('Network error: ' + err.message));
}

function showImportBlockedModal() {
    const modal = document.createElement('div');
    modal.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:9999';
    
    modal.innerHTML = `
        <div style="background:#fff;padding:25px;border-radius:10px;max-width:600px;width:90%">
            <h3 style="margin:0 0 20px 0"><i class="fa-solid fa-upload"></i> Import Blocked URLs</h3>
            
            <div style="margin-bottom:15px">
                <label style="display:block;margin-bottom:5px;font-weight:600">CSV File:</label>
                <input type="file" id="importBlockedFile" class="form-control" accept=".csv">
                <small style="color:#64748b">Expected format: URL,Reason,Source,Notes</small>
            </div>
            
            <div style="background:#fef3c7;padding:15px;border-radius:8px;margin-bottom:15px">
                <strong>⚠️ Note:</strong> Import feature requires additional backend implementation for CSV parsing and validation.
                For now, use bulk block with URLs list instead.
            </div>
            
            <div style="display:flex;gap:10px;justify-content:flex-end">
                <button class="btn btn-secondary" onclick="this.closest('div[style*=fixed]').remove()">Close</button>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
}

// ── Cleanup Calendar Links ─────────────────────────────────────────────────
function cleanupCalendarLinks() {
    const statusArea = document.createElement('div');
    statusArea.id = 'calendarCleanupStatus';
    statusArea.style.cssText = 'margin: 15px 0; padding: 15px; border-radius: 8px; background: #f8fafc;';
    
    const queueContent = document.getElementById('queueTabContent');
    if (queueContent && queueContent.parentNode) {
        queueContent.parentNode.insertBefore(statusArea, queueContent);
    }
    
    statusArea.innerHTML = '<div class="alert alert-info"><i class="fa-solid fa-spinner fa-spin"></i> Checking for calendar download links...</div>';
    
    fetch('ajax/cleanup_calendar_links_ajax.php?action=count')
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                statusArea.innerHTML = `<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> Error: ${escHtml(data.error)}</div>`;
                return;
            }
            
            if (data.total_count === 0) {
                statusArea.innerHTML = '<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> No calendar download links found! All clean.</div>';
                setTimeout(() => { statusArea.remove(); }, 3000);
                return;
            }
            
            // Show examples
            let examplesHtml = '<br><br><strong>Examples of calendar links that will be removed:</strong><ul style="margin:10px 0;padding-left:20px;font-size:0.9em">';
            data.examples.forEach(ex => {
                examplesHtml += `<li><span style="color:#64748b;font-size:0.9em">${escHtml(ex.page_url)}</span>`;
                examplesHtml += ` <span style="color:#94a3b8;font-size:0.85em">(${ex.source_table})</span></li>`;
            });
            examplesHtml += '</ul>';
            
            statusArea.innerHTML = 
                `<div class="alert alert-warning">` +
                `<strong><i class="fa-solid fa-calendar-xmark"></i> Found ${data.total_count} calendar download links</strong><br><br>` +
                `• In queue: <strong>${data.queue_count}</strong><br>` +
                `• In scraped content: <strong>${data.scraped_count}</strong><br><br>` +
                `These are calendar download links with <code>?ical=</code> or <code>?outlook-ical=</code> parameters.<br>` +
                `They download .ics files and should not be scraped as content pages.` +
                examplesHtml +
                `<br><br>` +
                `<button class="btn btn-danger" onclick="confirmCleanupCalendarLinks(${data.total_count})">` +
                `<i class="fa-solid fa-trash"></i> Remove ${data.total_count} Calendar Links` +
                `</button> ` +
                `<button class="btn btn-secondary" onclick="document.getElementById('calendarCleanupStatus').remove()">` +
                `<i class="fa-solid fa-xmark"></i> Cancel` +
                `</button>` +
                `</div>`;
        })
        .catch(err => {
            statusArea.innerHTML = `<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> Network error: ${escHtml(err.message)}</div>`;
        });
}

function confirmCleanupCalendarLinks(count) {
    if (!confirm(`Remove ${count} calendar download links?\n\nThese links will be:\n• Moved to blocked_urls (permanently blocked)\n• Deleted from queue and scraped_content\n• Will not be re-discovered on future scans`)) {
        return;
    }
    
    const statusArea = document.getElementById('calendarCleanupStatus');
    statusArea.innerHTML = `<div class="alert alert-warning"><i class="fa-solid fa-spinner fa-spin"></i> Cleaning up ${count} calendar links...</div>`;
    
    fetch('ajax/cleanup_calendar_links_ajax.php?action=cleanup', { method: 'POST' })
        .then(r => r.json())
        .then(result => {
            if (!result.success) {
                statusArea.innerHTML = `<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> Error: ${escHtml(result.error)}</div>`;
                return;
            }
            
            statusArea.innerHTML = 
                `<div class="alert alert-success">` +
                `<i class="fa-solid fa-circle-check"></i> <strong>Cleanup complete!</strong><br><br>` +
                `<strong>Moved to blocked_urls:</strong> ${result.blocked} URLs<br>` +
                `<strong>Deleted from queue:</strong> ${result.queue_deleted} items<br>` +
                `<strong>Deleted from scraped_content:</strong> ${result.scraped_deleted} pages<br><br>` +
                `<em>These URLs are now permanently blocked and won't be re-discovered.</em>` +
                `</div>`;
            
            // Reload the queue tab after 3 seconds
            setTimeout(() => {
                loadQueueTab();
                statusArea.remove();
            }, 3000);
        })
        .catch(err => {
            statusArea.innerHTML = `<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> Network error: ${escHtml(err.message)}</div>`;
        });
}

// ── Clear Queue Table ──────────────────────────────────────────────────────
function clearQueueTable() {
    const sourceId = document.getElementById('mlFilterSource')?.value || '';
    
    if (!sourceId) {
        alert('Please select a source first');
        return;
    }
    
    const statusDiv = document.getElementById('queueScrapeStatus');
    statusDiv.innerHTML = '<div class="alert alert-info"><i class="fa-solid fa-spinner fa-spin"></i> Checking queue status...</div>';
    
    // Get queue counts by status
    fetch(`ajax/scraper_ajax.php?action=queue_count&source_id=${sourceId}`)
        .then(r => r.json())
        .then(countData => {
            const counts = countData.counts[sourceId] || {};
            const pending = counts.pending || 0;
            const done = counts.done || 0;
            const failed = counts.failed || 0;
            const skipped = counts.skipped || 0;
            const scraping = counts.scraping || 0;
            const totalCount = pending + done + failed + skipped + scraping;
            
            statusDiv.innerHTML = '';
            
            if (totalCount === 0) {
                alert('Queue is already empty!');
                return;
            }
            
            // Build breakdown message
            let breakdown = '';
            if (pending > 0) breakdown += `\n  • Pending: ${pending}`;
            if (done > 0) breakdown += `\n  • Done: ${done}`;
            if (failed > 0) breakdown += `\n  • Failed: ${failed}`;
            if (skipped > 0) breakdown += `\n  • Skipped: ${skipped}`;
            if (scraping > 0) breakdown += `\n  • Scraping: ${scraping}`;
            
            if (!confirm(`⚠️ CLEAR MISSING LINKS QUEUE?\n\nThis will DELETE ALL ${totalCount} items from the queue:\n${breakdown}\n\n✓ ONLY deletes from Missing Links Queue\n✓ Does NOT delete scraped content\n✓ Does NOT delete blocked URLs\n✓ Deletes ALL statuses (pending, done, failed, etc.)\n✓ Cannot be undone\n\nYou'll need to run "Scan for Missing" to rebuild the queue.\n\nProceed with clearing queue?`)) {
                return;
            }
            
            // Double confirmation for safety
            if (!confirm(`FINAL CONFIRMATION\n\nDeleting ALL ${totalCount} queue items (all statuses).\n\nThis ONLY affects the Missing Links Queue.\nYour scraped content is safe.\n\nClick OK to clear the queue.`)) {
                return;
            }
            
            statusDiv.innerHTML = '<div class="alert alert-warning"><i class="fa-solid fa-spinner fa-spin"></i> Deleting queue items...</div>';
            
            const fd = new FormData();
            fd.append('action', 'clear_queue');
            fd.append('source_id', sourceId);
            
            fetch('ajax/scraper_ajax.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(result => {
                    if (result.error) {
                        statusDiv.innerHTML = `<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> Error: ${escHtml(result.error)}</div>`;
                        return;
                    }
                    
                    statusDiv.innerHTML = 
                        `<div class="alert alert-success">` +
                        `<i class="fa-solid fa-circle-check"></i> <strong>Queue cleared successfully!</strong><br>` +
                        `Deleted: <strong>${result.deleted}</strong> items from Missing Links Queue<br><br>` +
                        `<strong>✓ Your scraped content is safe</strong><br>` +
                        `<em>Run "Scan for Missing" to rebuild the queue.</em>` +
                        `</div>`;
                    
                    // Reload the queue tab after 2 seconds
                    setTimeout(() => {
                        loadQueueTab();
                    }, 2000);
                })
                .catch(err => {
                    statusDiv.innerHTML = `<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> Network error: ${escHtml(err.message)}</div>`;
                });
        })
        .catch(err => {
            alert('Error fetching queue count: ' + err.message);
        });
}

// ── Skip URL Patterns Management ───────────────────────────────────────────
function showSkipPatternsModal() {
    const sourceId = document.getElementById('mlFilterSource')?.value || '';
    
    if (!sourceId) {
        alert('Please select a source first');
        return;
    }
    
    fetch(`ajax/skip_url_patterns_ajax.php?action=list&source_id=${sourceId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert('Error: ' + data.error);
                return;
            }
            
            const modal = document.createElement('div');
            modal.id = 'skipPatternsModal';
            modal.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:9999';
            
            let patternsHtml = '';
            if (data.patterns.length > 0) {
                patternsHtml = '<table style="width:100%;border-collapse:collapse;margin:15px 0"><thead><tr style="background:#f1f5f9"><th style="padding:8px;text-align:left">Pattern</th><th style="padding:8px">Type</th><th style="padding:8px">Actions</th></tr></thead><tbody>';
                data.patterns.forEach(p => {
                    patternsHtml += `<tr style="border-bottom:1px solid #e5e7eb">
                        <td style="padding:8px"><code>${escHtml(p.url_pattern)}</code><br><small style="color:#64748b">${escHtml(p.notes || '')}</small></td>
                        <td style="padding:8px;text-align:center"><span style="background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:6px;font-size:0.8rem">${p.pattern_type}</span></td>
                        <td style="padding:8px;text-align:center"><button class="btn btn-sm btn-danger" onclick="deleteSkipPattern(${p.pattern_id})"><i class="fa-solid fa-trash"></i></button></td>
                    </tr>`;
                });
                patternsHtml += '</tbody></table>';
            } else {
                patternsHtml = '<p style="color:#64748b;text-align:center;padding:20px">No skip patterns defined yet.</p>';
            }
            
            modal.innerHTML = `
                <div style="background:#fff;padding:25px;border-radius:10px;max-width:700px;width:90%;max-height:80vh;overflow-y:auto">
                    <h3 style="margin:0 0 15px 0"><i class="fa-solid fa-filter-circle-xmark"></i> Skip URL Patterns</h3>
                    <p style="color:#64748b;margin-bottom:15px">URLs matching these patterns will be skipped during scanning.</p>
                    
                    ${patternsHtml}
                    
                    <div style="background:#f8fafc;padding:15px;border-radius:8px;margin-bottom:15px">
                        <h4 style="margin:0 0 10px 0">Add New Pattern</h4>
                        <div style="display:grid;gap:10px">
                            <input type="text" id="skipPatternUrl" class="form-control" placeholder="e.g., /events/" style="width:100%">
                            <select id="skipPatternType" class="form-select">
                                <option value="contains">Contains (matches if URL contains this text)</option>
                                <option value="starts_with">Starts With (matches if URL starts with this)</option>
                                <option value="regex">Regex (advanced pattern matching)</option>
                            </select>
                            <input type="text" id="skipPatternNotes" class="form-control" placeholder="Notes (optional)">
                            <button class="btn btn-primary" onclick="addSkipPattern(${sourceId})"><i class="fa-solid fa-plus"></i> Add Pattern</button>
                        </div>
                    </div>
                    
                    <div style="background:#fef3c7;padding:15px;border-radius:8px;margin-bottom:15px">
                        <strong>💡 Examples:</strong><br>
                        • <code>/events/</code> (contains) - Skips all URLs with /events/ in them<br>
                        • <code>https://mmu.ac.ug/events/</code> (starts_with) - Skips all URLs starting with this<br>
                        • <code>.*\\/events\\/.*</code> (regex) - Advanced pattern matching
                    </div>
                    
                    <div style="display:flex;gap:10px;justify-content:space-between">
                        <button class="btn btn-warning" onclick="cleanupExistingSkipPatterns(${sourceId})">
                            <i class="fa-solid fa-broom"></i> Remove Existing URLs
                        </button>
                        <button class="btn btn-secondary" onclick="document.getElementById('skipPatternsModal').remove()">Close</button>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
        })
        .catch(err => alert('Network error: ' + err.message));
}

function addSkipPattern(sourceId) {
    const pattern = document.getElementById('skipPatternUrl').value.trim();
    const type = document.getElementById('skipPatternType').value;
    const notes = document.getElementById('skipPatternNotes').value.trim();
    
    if (!pattern) {
        alert('Please enter a URL pattern');
        return;
    }
    
    const fd = new FormData();
    fd.append('action', 'add');
    fd.append('source_id', sourceId);
    fd.append('url_pattern', pattern);
    fd.append('pattern_type', type);
    fd.append('notes', notes);
    
    fetch('ajax/skip_url_patterns_ajax.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(result => {
            if (!result.success) {
                alert('Error: ' + result.error);
                return;
            }
            alert('Pattern added successfully!');
            document.getElementById('skipPatternsModal').remove();
            showSkipPatternsModal(); // Reload modal
        })
        .catch(err => alert('Network error: ' + err.message));
}

function deleteSkipPattern(patternId) {
    if (!confirm('Delete this skip pattern?')) return;
    
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('pattern_id', patternId);
    
    fetch('ajax/skip_url_patterns_ajax.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(result => {
            if (!result.success) {
                alert('Error: ' + result.error);
                return;
            }
            alert('Pattern deleted!');
            document.getElementById('skipPatternsModal').remove();
            showSkipPatternsModal(); // Reload modal
        })
        .catch(err => alert('Network error: ' + err.message));
}

function cleanupExistingSkipPatterns(sourceId) {
    if (!confirm('Remove all URLs from queue that match skip patterns?\n\nThis will clean up existing URLs that should have been skipped.')) {
        return;
    }
    
    const fd = new FormData();
    fd.append('action', 'cleanup_existing');
    fd.append('source_id', sourceId);
    
    fetch('ajax/skip_url_patterns_ajax.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(result => {
            if (!result.success) {
                alert('Error: ' + result.error);
                return;
            }
            alert(`Removed ${result.removed} URLs from queue that match skip patterns`);
            loadQueueTab(); // Reload queue
        })
        .catch(err => alert('Network error: ' + err.message));
}

</script>

</html>