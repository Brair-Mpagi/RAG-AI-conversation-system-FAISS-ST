<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: ./admin-login.php");
    exit();
}

require_once 'db.php';
if (!$conn || $conn->connect_error) {
    die("Connection failed: " . ($conn ? $conn->connect_error : 'No connection object.'));
}

$admin_query = "SELECT admin_id, username, email FROM admins WHERE admin_id = ?";
$admin_stmt = $conn->prepare($admin_query);
$admin_stmt->bind_param("i", $_SESSION['admin_id']);
$admin_stmt->execute();
$admin_result = $admin_stmt->get_result();
$admin = $admin_result->fetch_assoc();
$admin_stmt->close();

$summary = ['total_messages' => 0,'total_sessions' => 0,'avg_response_time' => 0,'avg_confidence' => 0,'context_rate' => 0,'total_conversations' => 0];
$sr = $conn->query("SELECT COUNT(*) as total_messages, COUNT(DISTINCT session_id) as total_sessions, COUNT(DISTINCT conversation_id) as total_conversations, AVG(CASE WHEN response_time_ms > 0 THEN response_time_ms END) as avg_rt, AVG(CASE WHEN confidence_score > 0 THEN confidence_score END) as avg_conf, AVG(context_retrieved) as ctx_rate FROM chat_messages");
if ($sr && $r = $sr->fetch_assoc()) {
    $summary = ['total_messages' => (int)$r['total_messages'],'total_sessions' => (int)$r['total_sessions'],'total_conversations' => (int)$r['total_conversations'],'avg_response_time' => round((float)$r['avg_rt'], 0),'avg_confidence' => round((float)$r['avg_conf'] * 100, 1),'context_rate' => round((float)$r['ctx_rate'] * 100, 1)];
}

$hr = $conn->query("SELECT SUM(CASE WHEN was_helpful=1 THEN 1 ELSE 0 END) as helpful, SUM(CASE WHEN was_helpful=0 THEN 1 ELSE 0 END) as not_helpful, COUNT(*) as total FROM chat_messages WHERE was_helpful IS NOT NULL");
$helpful = ['helpful' => 0,'not_helpful' => 0,'total' => 0,'rate' => 0];
if ($hr && $h = $hr->fetch_assoc()) {
    $helpful = ['helpful' => (int)$h['helpful'],'not_helpful' => (int)$h['not_helpful'],'total' => (int)$h['total'],'rate' => $h['total'] > 0 ? round($h['helpful'] / $h['total'] * 100, 1) : 0];
}

$daily_days = max(1, min(365, (int)($_GET['days'] ?? 30)));
$daily_start = isset($_GET['start']) ? date('Y-m-d', strtotime($_GET['start'])) : date('Y-m-d', strtotime("-{$daily_days} days"));
$daily_end   = isset($_GET['end'])   ? date('Y-m-d', strtotime($_GET['end'])) : date('Y-m-d');
$daily_result = $conn->query("SELECT DATE(created_at) as day, COUNT(*) as cnt FROM chat_messages WHERE DATE(created_at) BETWEEN '$daily_start' AND '$daily_end' GROUP BY day ORDER BY day");
$daily_labels = []; $daily_data = [];
if ($daily_result) { while ($row = $daily_result->fetch_assoc()) { $daily_labels[] = date('M j', strtotime($row['day'])); $daily_data[] = (int)$row['cnt']; } }

$hourly_result = $conn->query("SELECT HOUR(created_at) as hr, COUNT(*) as cnt FROM chat_messages GROUP BY hr ORDER BY hr");
$hourly_data = array_fill(0, 24, 0);
if ($hourly_result) { while ($row = $hourly_result->fetch_assoc()) { $hourly_data[(int)$row['hr']] = (int)$row['cnt']; } }

$intent_result = $conn->query("SELECT intent_classification, COUNT(*) as cnt FROM chat_messages WHERE intent_classification IS NOT NULL GROUP BY intent_classification ORDER BY cnt DESC");
$intent_labels = []; $intent_data = []; $intent_bg = [];
$intent_colors = ['general_campus'=>'#2563eb','faq'=>'#059669','academic'=>'#7c3aed','admission'=>'#d97706','financial'=>'#dc2626','out_of_scope'=>'#6b7280','sensitive_data'=>'#9f1239'];
if ($intent_result) { while ($row = $intent_result->fetch_assoc()) { $intent_labels[] = str_replace('_', ' ', $row['intent_classification']); $intent_data[] = (int)$row['cnt']; $intent_bg[] = $intent_colors[$row['intent_classification']] ?? '#94a3b8'; } }

$response_result = $conn->query("SELECT response_type, COUNT(*) as cnt FROM chat_messages WHERE response_type IS NOT NULL GROUP BY response_type ORDER BY cnt DESC");
$response_labels = []; $response_data = []; $response_bg = [];
$response_colors = ['rag_based'=>'#2563eb','faq'=>'#059669','fallback'=>'#d97706','refusal'=>'#dc2626','error'=>'#9f1239'];
if ($response_result) { while ($row = $response_result->fetch_assoc()) { $response_labels[] = str_replace('_', ' ', $row['response_type']); $response_data[] = (int)$row['cnt']; $response_bg[] = $response_colors[$row['response_type']] ?? '#94a3b8'; } }

$conf_result = $conn->query("SELECT SUM(CASE WHEN confidence_score >= 0.75 THEN 1 ELSE 0 END) as high, SUM(CASE WHEN confidence_score >= 0.50 AND confidence_score < 0.75 THEN 1 ELSE 0 END) as medium, SUM(CASE WHEN confidence_score > 0 AND confidence_score < 0.50 THEN 1 ELSE 0 END) as low, SUM(CASE WHEN confidence_score = 0 OR confidence_score IS NULL THEN 1 ELSE 0 END) as none FROM chat_messages");
$confidence = ['high'=>0,'medium'=>0,'low'=>0,'none'=>0];
if ($conf_result && $c = $conf_result->fetch_assoc()) { $confidence = ['high'=>(int)$c['high'],'medium'=>(int)$c['medium'],'low'=>(int)$c['low'],'none'=>(int)$c['none']]; }

$depth_result = $conn->query("SELECT AVG(msg_count) as avg_depth, MAX(msg_count) as max_depth, MIN(msg_count) as min_depth FROM (SELECT conversation_id, COUNT(*) as msg_count FROM chat_messages GROUP BY conversation_id) t");
$depth = ['avg'=>0,'max'=>0,'min'=>0];
if ($depth_result && $d = $depth_result->fetch_assoc()) { $depth = ['avg'=>round((float)$d['avg_depth'],1),'max'=>(int)$d['max_depth'],'min'=>(int)$d['min_depth']]; }

$sessions_result = $conn->query("SELECT ws.session_id, ws.session_token, ws.interface_type, ws.device_type, ws.device_model, ws.device_brand, ws.os_name, ws.os_version, ws.browser_name, ws.browser_version, ws.screen_resolution, ws.ip_address, ws.location, ws.status, ws.total_messages_sent, ws.total_messages_received, ws.start_time, ws.end_time, ws.duration_seconds, ws.created_at, ws.updated_at, TIMESTAMPDIFF(SECOND, ws.updated_at, NOW()) AS inactive_seconds, COUNT(cm.message_id) AS actual_msg_count FROM web_sessions ws LEFT JOIN chat_messages cm ON cm.session_id = ws.session_id GROUP BY ws.session_id ORDER BY ws.updated_at DESC LIMIT 20");
$sessions = [];
if ($sessions_result) { while ($row = $sessions_result->fetch_assoc()) $sessions[] = $row; }

$ss_result = $conn->query("SELECT COUNT(*) as total, SUM(CASE WHEN location IS NOT NULL AND location != '' THEN 1 ELSE 0 END) as with_location, COUNT(DISTINCT ip_address) as unique_ips, COUNT(DISTINCT os_name) as unique_os, COUNT(DISTINCT browser_name) as unique_browsers FROM web_sessions");
$session_stats = ['total'=>0,'with_location'=>0,'unique_ips'=>0,'unique_os'=>0,'unique_browsers'=>0];
if ($ss_result && $ss = $ss_result->fetch_assoc()) { $session_stats = ['total'=>(int)$ss['total'],'with_location'=>(int)$ss['with_location'],'unique_ips'=>(int)$ss['unique_ips'],'unique_os'=>(int)$ss['unique_os'],'unique_browsers'=>(int)$ss['unique_browsers']]; }

$device_result = $conn->query("SELECT COALESCE(device_type, 'Unknown') as device, COUNT(*) as cnt FROM web_sessions GROUP BY device ORDER BY cnt DESC LIMIT 6");
$device_labels = []; $device_data = []; $device_bg = [];
$device_colors = ['PC'=>'#2563eb','Mobile'=>'#059669','Tablet'=>'#d97706','Bot'=>'#dc2626','Unknown'=>'#94a3b8'];
if ($device_result) { while ($row = $device_result->fetch_assoc()) { $device_labels[] = $row['device']; $device_data[] = (int)$row['cnt']; $device_bg[] = $device_colors[$row['device']] ?? '#7c3aed'; } }

$browser_result = $conn->query("SELECT CASE WHEN browser_name LIKE '%Chrome%' THEN 'Chrome' WHEN browser_name LIKE '%Firefox%' THEN 'Firefox' WHEN browser_name LIKE '%Safari%' THEN 'Safari' WHEN browser_name LIKE '%Edge%' THEN 'Edge' WHEN browser_name LIKE '%Opera%' THEN 'Opera' WHEN browser_name IS NULL OR browser_name = '' THEN 'Unknown' ELSE 'Other' END as browser, COUNT(*) as cnt FROM web_sessions GROUP BY browser ORDER BY cnt DESC");
$browser_labels = []; $browser_data = []; $browser_bg = [];
$browser_colors = ['Chrome'=>'#4285F4','Firefox'=>'#FF7139','Safari'=>'#006CFF','Edge'=>'#0078D7','Opera'=>'#FF1B2D','Unknown'=>'#94a3b8','Other'=>'#6b7280'];
if ($browser_result) { while ($row = $browser_result->fetch_assoc()) { $browser_labels[] = $row['browser']; $browser_data[] = (int)$row['cnt']; $browser_bg[] = $browser_colors[$row['browser']] ?? '#7c3aed'; } }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Interactions — Analytics Dashboard</title>
    <link rel="shortcut icon" href="images/mmu_logo_- no bg.png" type="image/x-icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Outfit:wght@300;400;500;600;700;800&family=Lora:ital,wght@0,400;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="css/style.css?v=1775081173">
    <link rel="stylesheet" href="css/style-mob.css">
    <link rel="stylesheet" href="css/admin.css">

<style>
/* ═══════════════════════════════════════════════════════
   ANALYTICS DASHBOARD — PAGE CONTENT STYLES
   Scope: .an-workspace and descendants
═══════════════════════════════════════════════════════ */
:root {
    --an-bg:          #f4f6fb;
    --an-surface:     #ffffff;
    --an-surface-2:   #f8f9fc;
    --an-border:      #e4e9f2;
    --an-border-2:    #c9d3e8;
    --an-text-h:      #0b1220;
    --an-text-p:      #3d4a5c;
    --an-text-m:      #7e8fa5;
    --an-blue:        #1d4ed8;
    --an-blue-lt:     #dbeafe;
    --an-green:       #047857;
    --an-green-lt:    #d1fae5;
    --an-amber:       #b45309;
    --an-amber-lt:    #fef3c7;
    --an-red:         #b91c1c;
    --an-red-lt:      #fee2e2;
    --an-purple:      #6d28d9;
    --an-purple-lt:   #ede9fe;
    --an-cyan:        #0e7490;
    --an-cyan-lt:     #cffafe;
    --an-heading:     'Outfit', sans-serif;
    --an-body:        'Outfit', sans-serif;
    --an-mono:        'DM Mono', monospace;
    --an-serif:       'Lora', serif;
    --an-r:           10px;
    --an-r-lg:        16px;
    --an-sh:          0 1px 3px rgba(0,0,0,.07), 0 1px 2px rgba(0,0,0,.05);
    --an-sh-md:       0 4px 14px rgba(0,0,0,.09), 0 1px 4px rgba(0,0,0,.05);
    --an-sh-lg:       0 12px 36px rgba(0,0,0,.12);
}

.an-workspace {
    font-family: var(--an-body);
    background: var(--an-bg);
    min-height: 100vh;
    padding-bottom: 56px;
    color: var(--an-text-h);
    padding:10px 60px;
}

/* ── Page Header ── */
.an-header {
    background: rgb(5, 53, 107);
    padding: 30px 32px 26px;
    position: relative;
    overflow: hidden;
    border-bottom: 1px solid rgba(255,255,255,.07);
}
.an-header::before {
    content: '';
    position: absolute; inset: 0;
    background:
        radial-gradient(ellipse 600px 300px at 80% 50%, rgba(37,99,235,.12) 0%, transparent 70%),
        repeating-linear-gradient(90deg, rgba(255,255,255,.018) 0, rgba(255,255,255,.018) 1px, transparent 1px, transparent 72px),
        repeating-linear-gradient(0deg,  rgba(255,255,255,.018) 0, rgba(255,255,255,.018) 1px, transparent 1px, transparent 72px);
    pointer-events: none;
}
.an-header-inner {
    position: relative;
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 20px; flex-wrap: wrap;
}
.an-header-left .eyebrow {
    font-family: var(--an-mono);
    font-size: .68rem;
    text-transform: uppercase;
    letter-spacing: 1.8px;
    color: rgba(255,255,255,.35);
    margin: 0 0 8px;
    display: flex; align-items: center; gap: 8px;
}
.an-header-left .eyebrow::before {
    content: '';
    display: inline-block;
    width: 22px; height: 1px;
    background: rgba(255,255,255,.25);
}
.an-header-left h1 {
    font-family: var(--an-heading);
    font-size: 1.6rem;
    font-weight: 800;
    color: #fff;
    margin: 0 0 6px;
    letter-spacing: -.5px;
    line-height: 1.15;
}
.an-header-left p {
    font-size: .8rem;
    color: rgba(255,255,255,.38);
    margin: 0;
    letter-spacing: .2px;
}
.an-header-right {
    display: flex; gap: 8px; align-items: center; flex-wrap: wrap;
}
.an-hbtn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 8px 16px;
    border-radius: 8px;
    font-size: .78rem; font-weight: 600;
    font-family: var(--an-body);
    cursor: pointer; border: none; transition: all .16s;
    white-space: nowrap;
}
.an-hbtn-outline {
    background: rgba(255,255,255,.08);
    color: rgba(255,255,255,.7);
    border: 1px solid rgba(255,255,255,.14);
}
.an-hbtn-outline:hover { background: rgba(255,255,255,.14); color: #fff; }

/* ── Pulse dot ── */
.an-pulse { display: inline-flex; align-items: center; gap: 6px; font-size: .72rem; color: rgba(255,255,255,.4); }
.an-pulse-dot { width: 7px; height: 7px; border-radius: 50%; background: #34d399; animation: anPulse 1.6s ease-in-out infinite; }
@keyframes anPulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.4;transform:scale(.65)} }

/* ── KPI Strip ── */
.an-kpi-strip {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    background: var(--an-surface);
    border-bottom: 1px solid var(--an-border);
    box-shadow: var(--an-sh);
}
.an-kpi {
    padding: 18px 20px 16px;
    text-align: center;
    position: relative;
    border-right: 1px solid var(--an-border);
    transition: background .14s;
    cursor: default;
}
.an-kpi:last-child { border-right: none; }
.an-kpi:hover { background: var(--an-surface-2); }
.an-kpi-accent {
    display: block;
    width: 100%; height: 2px;
    border-radius: 0;
    position: absolute;
    top: 0; left: 0;
}
.an-kpi-val {
    font-family: var(--an-heading);
    font-size: 1.9rem;
    font-weight: 800;
    line-height: 1;
    margin: 4px 0 5px;
    color: var(--an-text-h);
}
.an-kpi-label {
    font-size: .66rem;
    text-transform: uppercase;
    letter-spacing: .8px;
    color: var(--an-text-m);
    font-weight: 600;
}
.an-kpi-sub { font-size: .65rem; color: var(--an-text-m); margin-top: 3px; font-family: var(--an-mono); }
.kpi-a-blue   .an-kpi-accent { background: var(--an-blue); }
.kpi-a-blue   .an-kpi-val    { color: var(--an-blue); }
.kpi-a-purple .an-kpi-accent { background: var(--an-purple); }
.kpi-a-purple .an-kpi-val    { color: var(--an-purple); }
.kpi-a-cyan   .an-kpi-accent { background: var(--an-cyan); }
.kpi-a-cyan   .an-kpi-val    { color: var(--an-cyan); }
.kpi-a-amber  .an-kpi-accent { background: var(--an-amber); }
.kpi-a-amber  .an-kpi-val    { color: var(--an-amber); }
.kpi-a-green  .an-kpi-accent { background: var(--an-green); }
.kpi-a-green  .an-kpi-val    { color: var(--an-green); }
.kpi-a-red    .an-kpi-accent { background: var(--an-red); }
.kpi-a-red    .an-kpi-val    { color: var(--an-red); }

/* ── Main content ── */
.an-content { padding: 28px 32px; display: flex; flex-direction: column; gap: 22px; }

/* ── Section labels ── */
.an-section-label {
    font-family: var(--an-mono);
    font-size: .63rem;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: var(--an-text-m);
    font-weight: 500;
    margin-bottom: 14px;
    display: flex; align-items: center; gap: 10px;
}
.an-section-label::after { content:''; flex:1; height:1px; background:var(--an-border); }

/* ── Panel ── */
.an-panel {
    background: var(--an-surface);
    border: 1px solid var(--an-border);
    border-radius: var(--an-r-lg);
    box-shadow: var(--an-sh);
    overflow: hidden;
}
.an-panel-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--an-border);
    background: var(--an-surface-2);
    display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
}
.an-panel-header-left { display: flex; align-items: center; gap: 10px; }
.an-panel-icon {
    width: 34px; height: 34px;
    border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    font-size: .85rem; flex-shrink: 0;
}
.pi-blue   { background: var(--an-blue-lt);   color: var(--an-blue);   border: 1px solid rgba(29,78,216,.15); }
.pi-green  { background: var(--an-green-lt);  color: var(--an-green);  border: 1px solid rgba(4,120,87,.15);  }
.pi-amber  { background: var(--an-amber-lt);  color: var(--an-amber);  border: 1px solid rgba(180,83,9,.15);  }
.pi-purple { background: var(--an-purple-lt); color: var(--an-purple); border: 1px solid rgba(109,40,217,.15);}
.pi-cyan   { background: var(--an-cyan-lt);   color: var(--an-cyan);   border: 1px solid rgba(14,116,144,.15);}
.pi-slate  { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
.an-panel-title { font-family: var(--an-heading); font-size: .95rem; font-weight: 700; color: var(--an-text-h); margin: 0; }
.an-panel-sub { font-size: .72rem; color: var(--an-text-m); margin: 2px 0 0; }
.an-panel-controls { display: flex; gap: 7px; align-items: center; flex-wrap: wrap; }

/* ── Chart grids ── */
.an-chart-row-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
}
.an-chart-row-3 {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr;
    gap: 18px;
}
.an-chart-row-4 {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 18px;
}
.an-chart-body { padding: 18px 20px; }
.an-chart-body canvas { max-height: 260px !important; width: 100% !important; }

/* ── Confidence Meter ── */
.an-conf-track {
    height: 10px;
    border-radius: 6px;
    overflow: hidden;
    display: flex;
    margin: 12px 0 16px;
    background: var(--an-border);
}
.an-conf-track div {
    display: flex; align-items: center; justify-content: center;
    font-size: .6rem; font-weight: 700; color: rgba(255,255,255,.9);
    transition: width .4s ease;
    min-width: 0;
}
.an-conf-table { width: 100%; border-collapse: collapse; }
.an-conf-table tr { border-bottom: 1px solid var(--an-border); }
.an-conf-table tr:last-child { border-bottom: none; }
.an-conf-table td { padding: 8px 4px; font-size: .8rem; color: var(--an-text-p); }
.an-conf-table td:last-child { text-align: right; font-weight: 700; color: var(--an-text-h); font-family: var(--an-mono); }
.an-conf-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; vertical-align: middle; }

/* ── Satisfaction Bar ── */
.an-sat-wrap { margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--an-border); }
.an-sat-label { font-size: .75rem; font-weight: 600; color: var(--an-text-p); margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
.an-sat-bar { height: 8px; border-radius: 5px; overflow: hidden; display: flex; margin-bottom: 7px; }
.an-sat-bar .sat-pos { background: var(--an-green); }
.an-sat-bar .sat-neg { background: var(--an-red); }
.an-sat-meta { font-size: .72rem; color: var(--an-text-m); font-family: var(--an-mono); }

/* ── Depth Metrics ── */
.an-depth-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }
.an-depth-cell {
    background: var(--an-surface-2);
    border: 1px solid var(--an-border);
    border-radius: 9px;
    padding: 14px;
    text-align: center;
}
.an-depth-cell .dv { font-family: var(--an-heading); font-size: 1.5rem; font-weight: 800; color: var(--an-text-h); line-height: 1; margin-bottom: 4px; }
.an-depth-cell .dl { font-size: .65rem; text-transform: uppercase; letter-spacing: .7px; color: var(--an-text-m); font-weight: 600; }
.an-depth-avg .dv { color: var(--an-blue); }
.an-depth-max .dv { color: var(--an-green); }
.an-depth-min .dv { color: var(--an-amber); }

/* ── Session Quick Stats ── */
.an-session-meta-row {
    display: flex; gap: 10px; flex-wrap: wrap;
    padding: 14px 20px;
    border-bottom: 1px solid var(--an-border);
    background: var(--an-surface-2);
}
.an-meta-chip {
    display: inline-flex; align-items: center; gap: 7px;
    background: var(--an-surface);
    border: 1px solid var(--an-border-2);
    border-radius: 7px;
    padding: 6px 13px;
    font-size: .78rem;
}
.an-meta-chip strong { color: var(--an-text-h); font-weight: 700; }
.an-meta-chip span { color: var(--an-text-m); }

/* ── Filter Bar ── */
.an-filter-bar {
    display: flex; gap: 8px; flex-wrap: wrap; align-items: center;
    padding: 12px 20px;
    border-bottom: 1px solid var(--an-border);
}
.an-filter-input, .an-filter-select {
    padding: 7px 12px;
    border: 1px solid var(--an-border-2);
    border-radius: 7px;
    background: var(--an-surface);
    color: var(--an-text-h);
    font-size: .8rem;
    font-family: var(--an-body);
    outline: none;
    transition: border-color .15s, box-shadow .15s;
}
.an-filter-input { width: 220px; }
.an-filter-input:focus, .an-filter-select:focus { border-color: var(--an-blue); box-shadow: 0 0 0 3px rgba(29,78,216,.1); }
.an-search-wrap { position: relative; }
.an-search-wrap i { position:absolute; left:10px; top:50%; transform:translateY(-50%); font-size:.75rem; color:var(--an-text-m); pointer-events:none; }
.an-search-wrap .an-filter-input { padding-left: 30px; }

/* ── Range controls ── */
.an-range-select {
    padding: 5px 9px;
    border: 1px solid var(--an-border-2);
    border-radius: 6px;
    font-size: .76rem;
    font-family: var(--an-body);
    color: var(--an-text-p);
    background: var(--an-surface);
    outline: none;
    cursor: pointer;
}
.an-range-select:focus { border-color: var(--an-blue); }
.an-range-btn-group { display: flex; }
.an-range-btn {
    padding: 4px 10px;
    border: 1px solid var(--an-border-2);
    font-size: .73rem; font-family: var(--an-body);
    font-weight: 600; cursor: pointer;
    background: var(--an-surface); color: var(--an-text-p);
    transition: all .14s;
}
.an-range-btn:first-child { border-radius: 6px 0 0 6px; }
.an-range-btn:last-child  { border-radius: 0 6px 6px 0; border-left: none; }
.an-range-btn.active { background: var(--an-blue); border-color: var(--an-blue); color: #fff; }
.an-custom-date-row {
    display: none; align-items: center; gap: 6px;
    font-size: .76rem; font-family: var(--an-body);
}
.an-custom-date-row.show { display: inline-flex; }
.an-custom-date-row input[type="date"] {
    padding: 4px 8px;
    border: 1px solid var(--an-border-2);
    border-radius: 6px; font-size: .76rem;
    font-family: var(--an-body); outline: none;
}
.an-apply-btn {
    padding: 4px 10px;
    background: var(--an-blue); color: #fff;
    border: none; border-radius: 6px;
    font-size: .76rem; font-weight: 600; cursor: pointer;
    font-family: var(--an-body); transition: background .14s;
}
.an-apply-btn:hover { background: #1e40af; }

/* ── Data Table ── */
.an-table-wrap { overflow-x: auto; }
.an-table {
    width: 100%; border-collapse: collapse;
    font-size: .8rem;
}
.an-table thead tr {
    background: var(--an-surface-2);
    border-bottom: 2px solid var(--an-border);
}
.an-table th {
    padding: 10px 14px;
    text-align: left;
    font-size: .66rem;
    text-transform: uppercase; letter-spacing: .6px;
    font-weight: 700; color: var(--an-text-m);
    white-space: nowrap;
}
.an-table td {
    padding: 11px 14px;
    border-bottom: 1px solid var(--an-border);
    color: var(--an-text-p);
    vertical-align: middle;
}
.an-table tbody tr { transition: background .1s; }
.an-table tbody tr:hover { background: #f5f7fb; }
.an-table tbody tr:last-child td { border-bottom: none; }
.an-table .col-mono { font-family: var(--an-mono); font-size: .73rem; color: var(--an-text-m); }

/* ── Status Badge ── */
.an-status {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 9px;
    border-radius: 20px;
    font-size: .66rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .4px;
    white-space: nowrap;
}
.an-status::before { content:''; width:5px; height:5px; border-radius:50%; flex-shrink:0; }
.status-active   { background:#dcfce7; color:#166534; } .status-active::before   { background:#16a34a; }
.status-ended    { background:#f1f5f9; color:#475569; } .status-ended::before    { background:#94a3b8; }
.status-expired  { background:#fef9c3; color:#854d0e; } .status-expired::before  { background:#ca8a04; }
.status-timeout  { background:#fee2e2; color:#991b1b; } .status-timeout::before  { background:#dc2626; }

/* ── Empty ── */
.an-empty { text-align:center; padding:40px; color:var(--an-text-m); }
.an-empty i { font-size:2rem; display:block; margin-bottom:10px; opacity:.3; }

/* ── Responsive ── */
@media (max-width: 1100px) {
    .an-kpi-strip     { grid-template-columns: repeat(3,1fr); }
    .an-chart-row-3   { grid-template-columns: 1fr 1fr; }
    .an-chart-row-4   { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 800px) {
    .an-kpi-strip     { grid-template-columns: repeat(2,1fr); }
    .an-chart-row-2,
    .an-chart-row-3,
    .an-chart-row-4   { grid-template-columns: 1fr; }
    .an-content       { padding: 16px 14px; }
    .an-header        { padding: 22px 16px 20px; }
    .an-filter-input  { width: 100%; }
}
</style>
</head>

<body>
<?php include 'includes/topbar.php'; ?>
</div> </div>
<div class="container-fluid sb2">
    <div class="row">
        <?php include 'includes/sidebar.php'; ?>
        <div class="sb2-2 col-md-9" style="padding:0;">

<!-- ═══════════════════ ANALYTICS WORKSPACE ═══════════════════ -->
<div class="an-workspace">

    <!-- ── Page Header ── -->
    <h2><i class="fa-solid fa-chart-line" style="color: #18569d;"></i> User
                            Interactions
                            Analytics</h2>

    <!-- ── KPI Strip ── -->
                                <div class="stat-cards-row" style="grid-template-columns: repeat(6, 1fr);">
                            <div class="stat-card">
                                <div class="stat-card-icon blue"><i class="fa-solid fa-message"></i></div>
                                <h6>Total Messages</h6>
                                <p class="stat-value">109</p>
                            </div>
                            <div class="stat-card">
                                <div class="stat-card-icon purple"><i class="fa-solid fa-comments"></i></div>
                                <h6>Conversations</h6>
                                <p class="stat-value">65</p>
                            </div>
                            <div class="stat-card">
                                <div class="stat-card-icon cyan"><i class="fa-solid fa-globe"></i></div>
                                <h6>Sessions</h6>
                                <p class="stat-value">56</p>
                            </div>
                            <div class="stat-card">
                                <div class="stat-card-icon amber"><i class="fa-solid fa-bolt"></i></div>
                                <h6>Avg Response</h6>
                                <p class="stat-value">2958<span style="font-size:0.8rem;">ms</span></p>
                            </div>
                            <div class="stat-card">
                                <div class="stat-card-icon green"><i class="fa-solid fa-bullseye"></i></div>
                                <h6>Avg Confidence</h6>
                                <p class="stat-value">70.1%</p>
                            </div>
                            <div class="stat-card">
                                <div class="stat-card-icon red"><i class="fa-solid fa-book-open"></i></div>
                                <h6>Context Used</h6>
                                <p class="stat-value">33%</p>
                            </div>
                        </div>
    <div class="an-content">

        <!-- ══════════════════════════════════════
             ROW 1 — Activity Over Time
        ══════════════════════════════════════ -->
        <div class="an-section-label"><i class="fa-solid fa-chart-area" style="color:var(--an-blue)"></i> Activity Over Time</div>
        <div class="an-chart-row-2">

            <!-- Messages Per Day -->
            <div class="an-panel">
                <div class="an-panel-header">
                    <div class="an-panel-header-left">
                        <div class="an-panel-icon pi-blue"><i class="fa-solid fa-chart-line"></i></div>
                        <div>
                            <h3 class="an-panel-title">Messages Per Day</h3>
                            <p class="an-panel-sub">Volume trend over selected period</p>
                        </div>
                    </div>
                    <div class="an-panel-controls">
                        <select id="dailyRangeSelect" class="an-range-select">
                            <option value="7">Last 7 days</option>
                            <option value="30" selected>Last 30 days</option>
                            <option value="90">Last 90 days</option>
                            <option value="custom">Custom…</option>
                        </select>
                        <span class="an-custom-date-row" id="dailyCustomRange">
                            <input type="date" id="dailyStart">
                            <span style="color:var(--an-text-m)">→</span>
                            <input type="date" id="dailyEnd">
                            <button class="an-apply-btn" onclick="loadDailyCustom()">Apply</button>
                        </span>
                    </div>
                </div>
                <div class="an-chart-body"><canvas id="dailyChart"></canvas></div>
            </div>

            <!-- Hourly Distribution -->
            <div class="an-panel">
                <div class="an-panel-header">
                    <div class="an-panel-header-left">
                        <div class="an-panel-icon pi-purple"><i class="fa-solid fa-clock"></i></div>
                        <div>
                            <h3 class="an-panel-title">Hourly Distribution</h3>
                            <p class="an-panel-sub">Peak usage periods (all-time)</p>
                        </div>
                    </div>
                    <div class="an-panel-controls">
                        <select id="uiHourRange" onchange="applyUiHourFilter()" class="an-range-select">
                            <option value="all">All Hours</option>
                            <option value="morning">Morning (6–11)</option>
                            <option value="afternoon">Afternoon (12–17)</option>
                            <option value="evening">Evening (18–21)</option>
                            <option value="night">Night (22–5)</option>
                        </select>
                        <div class="an-range-btn-group">
                            <button class="an-range-btn active" id="uiBtn24" onclick="setUiHourFmt(24)">24h</button>
                            <button class="an-range-btn" id="uiBtn12" onclick="setUiHourFmt(12)">12h</button>
                        </div>
                    </div>
                </div>
                <div class="an-chart-body"><canvas id="hourlyChart"></canvas></div>
            </div>
        </div>

        <!-- ══════════════════════════════════════
             ROW 2 — Query Intelligence
        ══════════════════════════════════════ -->
        <div class="an-section-label"><i class="fa-solid fa-brain" style="color:var(--an-purple)"></i> Query Intelligence</div>
        <div class="an-chart-row-2">

            <!-- Intent Classification -->
            <div class="an-panel">
                <div class="an-panel-header">
                    <div class="an-panel-header-left">
                        <div class="an-panel-icon pi-purple"><i class="fa-solid fa-tags"></i></div>
                        <div>
                            <h3 class="an-panel-title">Intent Classification</h3>
                            <p class="an-panel-sub">How queries are categorised by the AI</p>
                        </div>
                    </div>
                </div>
                <div class="an-chart-body"><canvas id="intentChart"></canvas></div>
            </div>

            <!-- Response Type -->
            <div class="an-panel">
                <div class="an-panel-header">
                    <div class="an-panel-header-left">
                        <div class="an-panel-icon pi-cyan"><i class="fa-solid fa-layer-group"></i></div>
                        <div>
                            <h3 class="an-panel-title">Response Type Breakdown</h3>
                            <p class="an-panel-sub">Distribution of how answers were generated</p>
                        </div>
                    </div>
                </div>
                <div class="an-chart-body"><canvas id="responseChart"></canvas></div>
            </div>
        </div>

        <!-- ══════════════════════════════════════
             ROW 3 — Quality & Depth
        ══════════════════════════════════════ -->
        <div class="an-section-label"><i class="fa-solid fa-gauge-high" style="color:var(--an-green)"></i> Quality & Engagement</div>
        <div class="an-chart-row-3">

            <!-- Confidence Distribution -->
            <div class="an-panel">
                <div class="an-panel-header">
                    <div class="an-panel-header-left">
                        <div class="an-panel-icon pi-green"><i class="fa-solid fa-gauge"></i></div>
                        <div>
                            <h3 class="an-panel-title">Confidence Score Distribution</h3>
                            <p class="an-panel-sub">AI self-assessed answer certainty bands</p>
                        </div>
                    </div>
                </div>
                <div class="an-chart-body">
                    <?php $conf_total = array_sum($confidence); ?>
                    <div class="an-conf-track">
                        <?php if ($conf_total > 0):
                            $h_pct = round($confidence['high']   / $conf_total * 100);
                            $m_pct = round($confidence['medium'] / $conf_total * 100);
                            $l_pct = round($confidence['low']    / $conf_total * 100);
                            $n_pct = max(0, 100 - $h_pct - $m_pct - $l_pct);
                        ?>
                        <div style="width:<?= $h_pct ?>%;background:#059669;" title="High: <?= $h_pct ?>%"></div>
                        <div style="width:<?= $m_pct ?>%;background:#d97706;" title="Medium: <?= $m_pct ?>%"></div>
                        <div style="width:<?= $l_pct ?>%;background:#b91c1c;" title="Low: <?= $l_pct ?>%"></div>
                        <div style="width:<?= $n_pct ?>%;background:#cbd5e1;" title="N/A: <?= $n_pct ?>%"></div>
                        <?php else: ?>
                        <div style="width:100%;background:#e2e8f0;"></div>
                        <?php endif; ?>
                    </div>
                    <table class="an-conf-table">
                        <tr><td><span class="an-conf-dot" style="background:#059669"></span>High ≥75%</td><td><?= number_format($confidence['high']) ?></td></tr>
                        <tr><td><span class="an-conf-dot" style="background:#d97706"></span>Medium 50–74%</td><td><?= number_format($confidence['medium']) ?></td></tr>
                        <tr><td><span class="an-conf-dot" style="background:#b91c1c"></span>Low &lt;50%</td><td><?= number_format($confidence['low']) ?></td></tr>
                        <tr><td><span class="an-conf-dot" style="background:#cbd5e1"></span>N/A</td><td><?= number_format($confidence['none']) ?></td></tr>
                    </table>
                    <?php if ($helpful['total'] > 0): ?>
                    <div class="an-sat-wrap">
                        <div class="an-sat-label"><i class="fa-solid fa-thumbs-up" style="color:var(--an-green)"></i> User Satisfaction</div>
                        <div class="an-sat-bar">
                            <div class="sat-pos" style="width:<?= $helpful['rate'] ?>%"></div>
                            <div class="sat-neg" style="width:<?= 100 - $helpful['rate'] ?>%"></div>
                        </div>
                        <div class="an-sat-meta"><?= $helpful['helpful'] ?> helpful · <?= $helpful['not_helpful'] ?> not helpful · <?= $helpful['rate'] ?>% satisfaction (<?= $helpful['total'] ?> rated)</div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Conversation Depth -->
            <div class="an-panel">
                <div class="an-panel-header">
                    <div class="an-panel-header-left">
                        <div class="an-panel-icon pi-amber"><i class="fa-solid fa-comments"></i></div>
                        <div>
                            <h3 class="an-panel-title">Conversation Depth</h3>
                            <p class="an-panel-sub">Messages per conversation</p>
                        </div>
                    </div>
                </div>
                <div class="an-chart-body">
                    <div class="an-depth-grid">
                        <div class="an-depth-cell an-depth-avg">
                            <div class="dv"><?= $depth['avg'] ?></div>
                            <div class="dl">Avg</div>
                        </div>
                        <div class="an-depth-cell an-depth-max">
                            <div class="dv"><?= $depth['max'] ?></div>
                            <div class="dl">Longest</div>
                        </div>
                        <div class="an-depth-cell an-depth-min">
                            <div class="dv"><?= $depth['min'] ?></div>
                            <div class="dl">Shortest</div>
                        </div>
                    </div>
                    <p style="font-size:.73rem;color:var(--an-text-m);margin-top:14px;line-height:1.5">
                        Average conversation involves <strong style="color:var(--an-text-h)"><?= $depth['avg'] ?> messages</strong> across <?= number_format($summary['total_conversations']) ?> unique conversations.
                        <?php if ($depth['max'] > 10): ?>Longest thread reached <strong style="color:var(--an-green)"><?= $depth['max'] ?> messages</strong>, indicating deep engagement.<?php endif; ?>
                    </p>
                </div>
            </div>

            <!-- Device Distribution -->
            <div class="an-panel">
                <div class="an-panel-header">
                    <div class="an-panel-header-left">
                        <div class="an-panel-icon pi-slate"><i class="fa-solid fa-laptop"></i></div>
                        <div>
                            <h3 class="an-panel-title">Device Types</h3>
                            <p class="an-panel-sub">Session device breakdown</p>
                        </div>
                    </div>
                </div>
                <div class="an-chart-body"><canvas id="deviceChart"></canvas></div>
            </div>
        </div>

        <!-- ══════════════════════════════════════
             ROW 4 — Audience & Clients
        ══════════════════════════════════════ -->
        <div class="an-section-label"><i class="fa-solid fa-globe" style="color:var(--an-cyan)"></i> Audience & Clients</div>
        <div class="an-chart-row-2">
            <div class="an-panel">
                <div class="an-panel-header">
                    <div class="an-panel-header-left">
                        <div class="an-panel-icon pi-cyan"><i class="fa-brands fa-chrome"></i></div>
                        <div>
                            <h3 class="an-panel-title">Browser Usage</h3>
                            <p class="an-panel-sub">Browsers used across all sessions</p>
                        </div>
                    </div>
                </div>
                <div class="an-chart-body"><canvas id="browserChart"></canvas></div>
            </div>

            <!-- Session Summary mini-panel -->
            <div class="an-panel">
                <div class="an-panel-header">
                    <div class="an-panel-header-left">
                        <div class="an-panel-icon pi-blue"><i class="fa-solid fa-network-wired"></i></div>
                        <div>
                            <h3 class="an-panel-title">Audience Overview</h3>
                            <p class="an-panel-sub">Unique client fingerprints</p>
                        </div>
                    </div>
                </div>
                <div class="an-chart-body">
                    <?php
                    $aud = [
                        ['fa-network-wired','var(--an-blue)',  'Unique IP Addresses', $session_stats['unique_ips']],
                        ['fa-location-dot', 'var(--an-green)', 'Sessions with Location', $session_stats['with_location']],
                        ['fa-desktop',      'var(--an-amber)', 'Operating Systems', $session_stats['unique_os']],
                        ['fa-globe',        'var(--an-purple)','Browser Types', $session_stats['unique_browsers']],
                        ['fa-desktop',      'var(--an-cyan)',  'Total Sessions', $session_stats['total']],
                    ];
                    foreach ($aud as $a): ?>
                    <div style="display:flex;align-items:center;gap:14px;padding:10px 0;border-bottom:1px solid var(--an-border)">
                        <div style="width:34px;height:34px;border-radius:8px;background:var(--an-surface-2);border:1px solid var(--an-border);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="fa-solid <?= $a[0] ?>" style="color:<?= $a[1] ?>;font-size:.82rem"></i>
                        </div>
                        <div style="flex:1;font-size:.8rem;color:var(--an-text-p)"><?= $a[2] ?></div>
                        <div style="font-family:var(--an-mono);font-size:.95rem;font-weight:700;color:var(--an-text-h)"><?= number_format($a[3]) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════
             SECTION — Recent Sessions
        ══════════════════════════════════════ -->
        <div class="an-section-label"><i class="fa-solid fa-desktop" style="color:var(--an-amber)"></i> Recent Sessions</div>
        <div class="an-panel">
            <div class="an-panel-header">
                <div class="an-panel-header-left">
                    <div class="an-panel-icon pi-amber"><i class="fa-solid fa-desktop"></i></div>
                    <div>
                        <h3 class="an-panel-title">Session Log</h3>
                        <p class="an-panel-sub">Last 20 sessions · live client data</p>
                    </div>
                </div>
            </div>

            <!-- Session Quick Stats -->
            <div class="an-session-meta-row">
                <div class="an-meta-chip"><i class="fa-solid fa-network-wired" style="color:var(--an-blue)"></i><strong><?= $session_stats['unique_ips'] ?></strong><span>Unique IPs</span></div>
                <div class="an-meta-chip"><i class="fa-solid fa-location-dot" style="color:var(--an-green)"></i><strong><?= $session_stats['with_location'] ?></strong><span>With Location</span></div>
                <div class="an-meta-chip"><i class="fa-solid fa-laptop" style="color:var(--an-amber)"></i><strong><?= $session_stats['unique_os'] ?></strong><span>OS Types</span></div>
                <div class="an-meta-chip"><i class="fa-solid fa-globe" style="color:var(--an-purple)"></i><strong><?= $session_stats['unique_browsers'] ?></strong><span>Browsers</span></div>
            </div>

            <!-- Filter -->
            <div class="an-filter-bar">
                <div class="an-search-wrap">
                    <i class="fa-solid fa-search"></i>
                    <input type="text" id="searchSessions" class="an-filter-input" placeholder="Search sessions, IP, location…">
                </div>
                <select id="filterSessionStatus" class="an-filter-select">
                    <option value="">All Statuses</option>
                    <option value="active">Active</option>
                    <option value="ended">Ended</option>
                    <option value="expired">Expired</option>
                    <option value="timeout">Timeout</option>
                </select>
                <select id="filterDeviceType" class="an-filter-select">
                    <option value="">All Devices</option>
                    <option value="pc">PC</option>
                    <option value="mobile">Mobile</option>
                    <option value="tablet">Tablet</option>
                </select>
            </div>

            <!-- Sessions Table -->
            <div class="an-table-wrap">
                <table class="an-table" id="sessionsTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>IP Address</th>
                            <th>Location</th>
                            <th>Browser</th>
                            <th>OS</th>
                            <th>Screen</th>
                            <th>Device</th>
                            <th>Status</th>
                            <th style="text-align:center">Msgs</th>
                            <th>Started</th>
                            <th>Ended</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sessions)): ?>
                        <tr><td colspan="11"><div class="an-empty"><i class="fa-solid fa-inbox"></i><span>No sessions recorded yet</span></div></td></tr>
                        <?php else: ?>
                        <?php foreach ($sessions as $sess):
                            $browser_display = $sess['browser_name'] ?? '—';
                            if (strlen($browser_display) > 40) {
                                if (preg_match('/Firefox\/([\d.]+)/', $browser_display, $bm))     $browser_display = 'Firefox '.$bm[1];
                                elseif (preg_match('/Chrome\/([\d.]+)/', $browser_display, $bm))  $browser_display = 'Chrome '.$bm[1];
                                elseif (preg_match('/Safari\/([\d.]+)/', $browser_display, $bm))  $browser_display = 'Safari '.$bm[1];
                                elseif (preg_match('/Edge\/([\d.]+)/', $browser_display, $bm))    $browser_display = 'Edge '.$bm[1];
                                else $browser_display = mb_strimwidth($browser_display, 0, 28, '…');
                            }
                            $os_display = $sess['os_name'] ?? '—';
                            if (strlen($os_display) > 24) $os_display = mb_strimwidth($os_display, 0, 20, '…');
                            if (!empty($sess['os_version'])) $os_display .= ' '.$sess['os_version'];
                            $sess_status = $sess['status'] ?? 'active';
                            $end_time = $sess['end_time'];
                            if ($sess_status === 'active' && !empty($sess['updated_at']) && isset($sess['inactive_seconds']) && $sess['inactive_seconds'] > 300) {
                                $sess_status = 'expired'; $end_time = $sess['updated_at'];
                            }
                            $is_coords = false; $coord_lat = ''; $coord_lng = '';
                            $location_display = trim($sess['location'] ?? '');
                            if (empty($location_display)) $location_display = '—';
                            elseif (preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $location_display, $matches)) {
                                $is_coords = true; $coord_lat = $matches[1]; $coord_lng = $matches[2];
                                $location_display = '📍 '.$location_display;
                            }
                            $total_msgs = (int)($sess['actual_msg_count'] ?? 0);
                        ?>
                        <tr>
                            <td class="col-mono"><?= (int)$sess['session_id'] ?></td>
                            <td class="col-mono"><?= htmlspecialchars($sess['ip_address'] ?? '—') ?></td>
                            <td title="<?= htmlspecialchars($sess['location'] ?? '') ?>"
                                <?php if ($is_coords): ?>data-lat="<?= $coord_lat ?>" data-lng="<?= $coord_lng ?>" class="geo-cell"<?php endif; ?>
                                style="font-size:.77rem">
                                <?= $is_coords ? '<span style="color:var(--an-text-m);font-size:.73rem">Resolving…</span>' : htmlspecialchars($location_display) ?>
                            </td>
                            <td title="<?= htmlspecialchars($sess['browser_name'] ?? '') ?>"><?= htmlspecialchars($browser_display) ?></td>
                            <td title="<?= htmlspecialchars(($sess['os_name']??'').' '.($sess['os_version']??'')) ?>"><?= htmlspecialchars($os_display) ?></td>
                            <td class="col-mono" style="font-size:.72rem"><?= htmlspecialchars($sess['screen_resolution'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($sess['device_type'] ?? ($sess['device_brand'] ?? '—')) ?></td>
                            <td><span class="an-status status-<?= htmlspecialchars($sess_status) ?>"><?= htmlspecialchars(ucfirst($sess_status)) ?></span></td>
                            <td style="text-align:center;font-family:var(--an-mono);font-weight:700;color:var(--an-text-h)"><?= $total_msgs ?></td>
                            <td style="white-space:nowrap;font-size:.75rem;color:var(--an-text-m)"><?= date('M j, g:ia', strtotime($sess['created_at'])) ?></td>
                            <td style="white-space:nowrap;font-size:.75rem;color:var(--an-text-m)">
                                <?= !empty($end_time) ? date('M j, g:ia', strtotime($end_time)) : '<span style="color:var(--an-border-2)">—</span>' ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div><!-- /an-content -->
</div><!-- /an-workspace -->

        </div>
    </div>
</div>

<script>
Chart.defaults.font.family = "'Outfit', sans-serif";
Chart.defaults.font.size = 11;
Chart.defaults.plugins.legend.labels.usePointStyle = true;
Chart.defaults.plugins.legend.labels.pointStyleWidth = 8;

/* ── Colour tokens ── */
const C = {
    blue:   '#1d4ed8',
    purple: '#6d28d9',
    green:  '#047857',
    amber:  '#b45309',
    red:    '#b91c1c',
    cyan:   '#0e7490',
    grid:   'rgba(0,0,0,.045)',
};

/* ─ Messages Per Day ─ */
let dailyChart = new Chart(document.getElementById('dailyChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($daily_labels) ?>,
        datasets: [{
            label: 'Messages',
            data: <?= json_encode($daily_data) ?>,
            borderColor: C.blue,
            backgroundColor: ctx => {
                const {chartArea, ctx: c} = ctx.chart;
                if (!chartArea) return 'rgba(29,78,216,.08)';
                const g = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                g.addColorStop(0, 'rgba(29,78,216,.22)');
                g.addColorStop(1, 'rgba(29,78,216,.01)');
                return g;
            },
            fill: true, tension: 0.42,
            pointRadius: 0, pointHoverRadius: 5,
            pointHoverBackgroundColor: C.blue,
            pointHoverBorderColor: '#fff', pointHoverBorderWidth: 2,
            borderWidth: 2.5,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, grid: { color: C.grid }, border: { display: false }, ticks: { color: '#7e8fa5' } },
            x: { grid: { display: false }, border: { display: false }, ticks: { maxTicksLimit: 10, color: '#7e8fa5' } }
        }
    }
});

function reloadDailyChart(url) {
    fetch(url).then(r => r.json()).then(d => {
        dailyChart.data.labels = d.labels;
        dailyChart.data.datasets[0].data = d.data;
        dailyChart.update('active');
    });
}
document.getElementById('dailyRangeSelect').addEventListener('change', function() {
    const cr = document.getElementById('dailyCustomRange');
    if (this.value === 'custom') { cr.classList.add('show'); }
    else { cr.classList.remove('show'); reloadDailyChart('get_daily_messages.php?days=' + this.value); }
});
function loadDailyCustom() {
    const s = document.getElementById('dailyStart').value;
    const e = document.getElementById('dailyEnd').value;
    if (s && e) reloadDailyChart(`get_daily_messages.php?start=${s}&end=${e}`);
}

/* ─ Hourly Distribution ─ */
const uiHourlyRaw  = Array.from({length:24},(_,i) => String(i).padStart(2,'0')+':00');
const uiHourlyData = <?= json_encode(array_values($hourly_data)) ?>;
let uiHourFmt = 24;

let hourlyChartInst = new Chart(document.getElementById('hourlyChart'), {
    type: 'bar',
    data: {
        labels: uiHourlyRaw.slice(),
        datasets: [{
            label: 'Messages',
            data: uiHourlyData.slice(),
            backgroundColor: ctx => {
                const {chartArea, ctx: c} = ctx.chart;
                if (!chartArea) return 'rgba(109,40,217,.7)';
                const g = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                g.addColorStop(0, 'rgba(109,40,217,.85)');
                g.addColorStop(1, 'rgba(109,40,217,.35)');
                return g;
            },
            borderRadius: 5, borderSkipped: false,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, grid: { color: C.grid }, border: { display: false }, ticks: { color: '#7e8fa5' } },
            x: { grid: { display: false }, border: { display: false }, ticks: { maxTicksLimit: 12, color: '#7e8fa5' } }
        }
    }
});

const uiHourRanges = { all:{min:0,max:23}, morning:{min:6,max:11}, afternoon:{min:12,max:17}, evening:{min:18,max:21}, night:null };
function fmtUiHourLabel(h24, fmt) {
    const h = parseInt(h24);
    if (fmt === 24) return h24;
    if (h === 0) return '12 AM'; if (h === 12) return '12 PM';
    return h > 12 ? `${h-12} PM` : `${h} AM`;
}
function applyUiHourFilter() {
    const rk = document.getElementById('uiHourRange').value;
    const range = uiHourRanges[rk];
    let ls = [], vs = [];
    uiHourlyRaw.forEach((lbl,i) => {
        const h = parseInt(lbl);
        const inR = !range ? (h>=22||h<=5) : (h>=range.min&&h<=range.max);
        if (inR) { ls.push(fmtUiHourLabel(lbl, uiHourFmt)); vs.push(uiHourlyData[i]||0); }
    });
    hourlyChartInst.data.labels = ls;
    hourlyChartInst.data.datasets[0].data = vs;
    hourlyChartInst.update('active');
}
function setUiHourFmt(fmt) {
    uiHourFmt = fmt;
    document.getElementById('uiBtn24').classList.toggle('active', fmt===24);
    document.getElementById('uiBtn12').classList.toggle('active', fmt===12);
    applyUiHourFilter();
}

/* ─ Intent Chart ─ */
new Chart(document.getElementById('intentChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($intent_labels) ?>,
        datasets: [{
            data: <?= json_encode($intent_data) ?>,
            backgroundColor: <?= json_encode($intent_bg) ?>,
            borderWidth: 0, borderRadius: 5, borderSkipped: false,
        }]
    },
    options: {
        indexAxis: 'y', responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { beginAtZero: true, grid: { color: C.grid }, border: { display: false }, ticks: { color: '#7e8fa5' } },
            y: { grid: { display: false }, border: { display: false }, ticks: { font: { size: 11 }, color: '#3d4a5c' } }
        }
    }
});

/* ─ Response Type Doughnut ─ */
new Chart(document.getElementById('responseChart'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode($response_labels) ?>,
        datasets: [{
            data: <?= json_encode($response_data) ?>,
            backgroundColor: <?= json_encode($response_bg) ?>,
            borderWidth: 3, borderColor: '#fff', hoverBorderColor: '#fff',
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        cutout: '62%',
        plugins: { legend: { position: 'bottom', labels: { padding: 14, font: { size: 11 }, color: '#3d4a5c' } } }
    }
});

/* ─ Device Doughnut ─ */
new Chart(document.getElementById('deviceChart'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode($device_labels) ?>,
        datasets: [{
            data: <?= json_encode($device_data) ?>,
            backgroundColor: <?= json_encode($device_bg) ?>,
            borderWidth: 3, borderColor: '#fff',
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        cutout: '60%',
        plugins: { legend: { position: 'bottom', labels: { padding: 12, font: { size: 11 }, color: '#3d4a5c' } } }
    }
});

/* ─ Browser Bar ─ */
new Chart(document.getElementById('browserChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($browser_labels) ?>,
        datasets: [{
            data: <?= json_encode($browser_data) ?>,
            backgroundColor: <?= json_encode($browser_bg) ?>,
            borderWidth: 0, borderRadius: 5, borderSkipped: false,
        }]
    },
    options: {
        indexAxis: 'y', responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { beginAtZero: true, grid: { color: C.grid }, border: { display: false }, ticks: { color: '#7e8fa5' } },
            y: { grid: { display: false }, border: { display: false }, ticks: { color: '#3d4a5c' } }
        }
    }
});

/* ─ Session Filter ─ */
(function () {
    const q  = document.getElementById('searchSessions');
    const st = document.getElementById('filterSessionStatus');
    const dv = document.getElementById('filterDeviceType');
    const tb = document.getElementById('sessionsTable');

    function filter() {
        const qv  = (q.value||'').toLowerCase();
        const stv = st.value.toLowerCase();
        const dvv = dv.value.toLowerCase();
        tb?.querySelector('tbody')?.querySelectorAll('tr').forEach(row => {
            if (row.querySelector('td[colspan]')) return;
            const cells = row.querySelectorAll('td');
            const matchQ  = !qv  || row.textContent.toLowerCase().includes(qv);
            const matchS  = !stv || (cells[7]?.textContent||'').trim().toLowerCase() === stv;
            const matchD  = !dvv || (cells[6]?.textContent||'').trim().toLowerCase().includes(dvv);
            row.style.display = (matchQ && matchS && matchD) ? '' : 'none';
        });
    }
    q?.addEventListener('input', filter);
    st?.addEventListener('change', filter);
    dv?.addEventListener('change', filter);

    /* Reverse geocode */
    const geoCells = document.querySelectorAll('.geo-cell');
    const cache = {};
    geoCells.forEach(cell => {
        const lat = cell.dataset.lat, lng = cell.dataset.lng;
        if (!lat || !lng) return;
        const key = lat+','+lng;
        if (cache[key]) { cell.textContent = cache[key]; return; }
        fetch(`https://photon.komoot.io/reverse?lat=${lat}&lon=${lng}`)
            .then(r => r.json())
            .then(d => {
                const p = d.features?.[0]?.properties;
                const name = p ? [p.name,p.street,p.locality,p.city,p.state].filter(Boolean).slice(0,3).join(', ') : key;
                cache[key] = '📍 '+name;
                cell.textContent = '📍 '+name;
            })
            .catch(() => { cell.textContent = '📍 '+key; });
    });
})();

/* ─ Notifications ─ */
function updateNotificationCount() {
    fetch('fetch_queries.php').then(r=>r.json()).then(d=>{
        const el = document.getElementById('not-yet-count');
        if (el) { el.textContent = d.not_yet_count>0?d.not_yet_count:''; el.style.display = d.not_yet_count>0?'inline':'none'; }
    }).catch(()=>{});
}
updateNotificationCount();
setInterval(updateNotificationCount, 60000);
</script>

</body>
</html>