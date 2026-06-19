<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
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

if (!$admin) {
    session_unset();
    session_destroy();
    header("Location: ./admin-login.php?err=account_missing");
    exit();
}

// Helpers
function fetch_ollama_tags()
{
    $url = 'http://127.0.0.1:11434/api/tags';
    $ctx = stream_context_create(['http' => ['timeout' => 3]]);
    $json = @file_get_contents($url, false, $ctx);
    if (!$json)
        return ['models' => []];
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['models']))
        return ['models' => []];
    return $data;
}

function check_ollama_running()
{
    $url = 'http://127.0.0.1:11434/api/version';
    $ctx = stream_context_create(['http' => ['timeout' => 2]]);
    $json = @file_get_contents($url, false, $ctx);
    if (!$json)
        return null;
    $data = json_decode($json, true);
    return $data;
}

// Actions
$notice = null;
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'sync_ollama') {
        $data = fetch_ollama_tags();
        $inserted = 0;
        $updated = 0;
        foreach (($data['models'] ?? []) as $m) {
            $full = isset($m['model']) ? $m['model'] : (isset($m['name']) ? $m['name'] : null);
            if (!$full)
                continue;
            $size_bytes = isset($m['size']) ? (int)$m['size'] : null;
            $size_mb = $size_bytes ? (int)round($size_bytes / (1024 * 1024)) : null;
            $model_name = $full;
            $version = '';
            if (strpos($full, ':') !== false) {
                [$base, $ver] = explode(':', $full, 2);
                $model_name = $base;
                $version = $ver;
            }
            $res = $conn->query("SELECT model_id, model_version, model_size_mb FROM ai_models WHERE model_name='" . $conn->real_escape_string($model_name) . "'");
            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
                $changed = false;
                if ($row['model_version'] !== $version) {
                    $stmt = $conn->prepare("UPDATE ai_models SET model_version=? WHERE model_id=?");
                    $stmt->bind_param('si', $version, $row['model_id']);
                    $stmt->execute();
                    $changed = true;
                }
                if ($size_mb && ((int)$row['model_size_mb']) !== $size_mb) {
                    $stmt = $conn->prepare("UPDATE ai_models SET model_size_mb=? WHERE model_id=?");
                    $stmt->bind_param('ii', $size_mb, $row['model_id']);
                    $stmt->execute();
                    $changed = true;
                }
                if ($changed)
                    $updated++;
            } else {
                $status = 'active';
                $type = 'local_ollama';
                $is_default = 0;
                $path = null;
                $stmt = $conn->prepare("INSERT INTO ai_models (model_name, model_version, model_type, status, is_default, model_path, model_size_mb) VALUES (?,?,?,?,?,?,?)");
                $stmt->bind_param('ssssisi', $model_name, $version, $type, $status, $is_default, $path, $size_mb);
                $stmt->execute();
                $inserted++;
            }
        }
        $notice = "Synced with Ollama: inserted $inserted, updated $updated.";
    } elseif ($action === 'set_default' && isset($_POST['model_id'])) {
        $id = (int)$_POST['model_id'];
        $conn->query("UPDATE ai_models SET is_default=0");
        $stmt = $conn->prepare("UPDATE ai_models SET is_default=1, status='active' WHERE model_id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $notice = 'Default model updated.';
    } elseif ($action === 'toggle_status' && isset($_POST['model_id'])) {
        $id = (int)$_POST['model_id'];
        $res = $conn->query("SELECT status FROM ai_models WHERE model_id=$id");
        $row = $res ? $res->fetch_assoc() : null;
        $new = ($row && $row['status'] === 'active') ? 'inactive' : 'active';
        $stmt = $conn->prepare("UPDATE ai_models SET status=? WHERE model_id=?");
        $stmt->bind_param('si', $new, $id);
        $stmt->execute();
        $notice = 'Model status updated.';
    } elseif ($action === 'update_type' && isset($_POST['model_id'], $_POST['model_type'])) {
        $id = (int)$_POST['model_id'];
        $type = $_POST['model_type'] === 'cloud_api' ? 'cloud_api' : 'local_ollama';
        $stmt = $conn->prepare("UPDATE ai_models SET model_type=? WHERE model_id=?");
        $stmt->bind_param('si', $type, $id);
        $stmt->execute();
        $notice = 'Model type updated.';
    } elseif ($action === 'update_version' && isset($_POST['model_id'], $_POST['model_version'])) {
        $id = (int)$_POST['model_id'];
        $ver = trim($_POST['model_version']);
        $stmt = $conn->prepare("UPDATE ai_models SET model_version=? WHERE model_id=?");
        $stmt->bind_param('si', $ver, $id);
        $stmt->execute();
        $notice = 'Model version updated.';
    } elseif ($action === 'update_config' && isset($_POST['model_id'])) {
        $id = (int)$_POST['model_id'];
        $config_raw = $_POST['model_config'] ?? '';
        if ($config_raw !== '') {
            $decoded = json_decode($config_raw, true);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                $error = 'Invalid JSON for model_config: ' . json_last_error_msg();
            } else {
                $config_json = json_encode($decoded);
                $stmt = $conn->prepare("UPDATE ai_models SET model_config=? WHERE model_id=?");
                $stmt->bind_param('si', $config_json, $id);
                $stmt->execute();
                $notice = 'Model configuration updated.';
            }
        } else {
            $stmt = $conn->prepare("UPDATE ai_models SET model_config=NULL WHERE model_id=?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $notice = 'Model configuration cleared.';
        }
    } elseif ($action === 'update_path' && isset($_POST['model_id'])) {
        $id = (int)$_POST['model_id'];
        $path = trim($_POST['model_path'] ?? '');
        $stmt = $conn->prepare("UPDATE ai_models SET model_path=? WHERE model_id=?");
        $stmt->bind_param('si', $path, $id);
        $stmt->execute();
        $notice = 'Model path updated.';
    } elseif ($action === 'add_cloud_model') {
        $model_name = trim($_POST['cloud_model_name'] ?? '');
        $provider = trim($_POST['cloud_provider'] ?? 'gemini');
        $api_key = trim($_POST['cloud_api_key'] ?? '');
        $api_endpoint = trim($_POST['cloud_api_endpoint'] ?? '');
        $version = trim($_POST['cloud_model_version'] ?? '');
        $config_raw = trim($_POST['cloud_model_config'] ?? '');
        if (!$model_name || !$api_key) {
            $error = 'Model name and API key are required.';
        } else {
            // Set default endpoints per provider
            if (!$api_endpoint) {
                switch ($provider) {
                    case 'gemini':
                        $api_endpoint = 'https://generativelanguage.googleapis.com/v1beta';
                        break;
                    case 'openai':
                        $api_endpoint = 'https://api.openai.com/v1';
                        break;
                    case 'anthropic':
                        $api_endpoint = 'https://api.anthropic.com/v1';
                        break;
                }
            }
            $config_json = null;
            if ($config_raw !== '') {
                $decoded = json_decode($config_raw, true);
                if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                    $error = 'Invalid JSON for model config: ' . json_last_error_msg();
                } else {
                    $config_json = json_encode($decoded);
                }
            }
            if (!$error) {
                $type = 'cloud_api';
                $status = 'active';
                $is_default = 0;
                $stmt = $conn->prepare("INSERT INTO ai_models (model_name, model_version, model_type, status, is_default, model_config, api_provider, api_endpoint, api_key) VALUES (?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param('ssssissss', $model_name, $version, $type, $status, $is_default, $config_json, $provider, $api_endpoint, $api_key);
                $stmt->execute();
                $notice = 'Cloud model "' . htmlspecialchars($model_name) . '" added successfully.';
            }
        }
    } elseif ($action === 'update_cloud_config' && isset($_POST['model_id'])) {
        $id = (int)$_POST['model_id'];
        $provider = trim($_POST['api_provider'] ?? '');
        $endpoint = trim($_POST['api_endpoint'] ?? '');
        $key = trim($_POST['api_key'] ?? '');
        $stmt = $conn->prepare("UPDATE ai_models SET api_provider=?, api_endpoint=?, api_key=? WHERE model_id=?");
        $stmt->bind_param('sssi', $provider, $endpoint, $key, $id);
        $stmt->execute();
        $notice = 'Cloud API configuration updated.';
    } elseif ($action === 'delete_model' && isset($_POST['model_id'])) {
        $id = (int)$_POST['model_id'];
        // Don't delete default model
        $check = $conn->query("SELECT is_default FROM ai_models WHERE model_id=$id");
        $row = $check ? $check->fetch_assoc() : null;
        if ($row && $row['is_default']) {
            $error = 'Cannot delete the default model. Set another model as default first.';
        } else {
            $stmt = $conn->prepare("DELETE FROM ai_models WHERE model_id=?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $notice = 'Model deleted.';
        }
    }
}

// Fetch models
$models = [];
$res = $conn->query("SELECT * FROM ai_models ORDER BY is_default DESC, status DESC, model_name ASC");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $models[] = $r;
    }
}

// Aggregate metrics (last 30 days)
$metrics_by_model = [];
$resm = $conn->query("SELECT model_id,
    SUM(total_requests) AS total_requests,
    SUM(successful_responses) AS successful_responses,
    SUM(failed_responses) AS failed_responses,
    AVG(avg_response_time_ms) AS avg_response_time_ms,
    AVG(user_satisfaction_avg) AS avg_user_satisfaction
  FROM model_performance_metrics
  WHERE metric_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
  GROUP BY model_id");
if ($resm) {
    while ($row = $resm->fetch_assoc()) {
        $metrics_by_model[(int)$row['model_id']] = $row;
    }
}

// Recent training history
$training_by_model = [];
$resh = $conn->query("SELECT * FROM model_training_history ORDER BY training_started DESC LIMIT 200");
if ($resh) {
    while ($row = $resh->fetch_assoc()) {
        $mid = (int)$row['model_id'];
        if (!isset($training_by_model[$mid])) {
            $training_by_model[$mid] = [];
        }
        if (count($training_by_model[$mid]) < 5) {
            $training_by_model[$mid][] = $row;
        }
    }
}

// Overall model stats from chat_messages
$model_usage = [];
$mu_res = $conn->query("SELECT model_used, COUNT(*) as total_calls, 
    AVG(response_time_ms) as avg_time, 
    AVG(confidence_score) as avg_conf,
    MIN(created_at) as first_used,
    MAX(created_at) as last_used
    FROM chat_messages WHERE model_used IS NOT NULL AND model_used != '' 
    GROUP BY model_used ORDER BY total_calls DESC");
if ($mu_res) {
    while ($row = $mu_res->fetch_assoc()) {
        $model_usage[$row['model_used']] = $row;
    }
}

// Overall stats
$total_active = 0;
$total_inactive = 0;
$default_model = null;
$total_size_mb = 0;
foreach ($models as $m) {
    if ($m['status'] === 'active')
        $total_active++;
    else
        $total_inactive++;
    if ($m['is_default'])
        $default_model = $m;
    $total_size_mb += (int)($m['model_size_mb'] ?? 0);
}

// Ollama live status
$ollama_info = check_ollama_running();
$ollama_running = ($ollama_info !== null);
$ollama_models_data = $ollama_running ? fetch_ollama_tags() : ['models' => []];
$installed_models = [];
foreach ($ollama_models_data['models'] ?? [] as $om) {
    $name = $om['model'] ?? $om['name'] ?? '';
    $installed_models[$name] = $om;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>AI Models Management</title>
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

        /* Model cards */
        .model-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        .model-card {
            background: #f6f6f6ff;
            border: 1px solid #05356b;
            border-radius: 14px;
            padding: 20px;
            position: relative;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .model-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(5, 53, 107, 0.2);
        }

        .model-card.is-default {
            border-color: #05356b;
            box-shadow: 0 0 0 1px #05356b;
        }

        .model-card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }

        .model-card-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: #ffffff;
            background: #05356b;
        }

        .model-card-icon.active {
            background: #05356b;
            color: #ffffff;
        }

        .model-card-icon.inactive {
            background: #ffffff;
            color: #05356b;
            border: 1px solid #05356b;
        }

        .model-card-name {
            font-size: 1.05rem;
            font-weight: 600;
            color: #05356b;
            margin: 0;
            line-height: 1.2;
        }

        .model-card-version {
            font-size: 0.78rem;
            color: #05356b;
        }

        .model-card-badges {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }

        .mc-badge {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .mc-badge-default {
            background: #05356b;
            color: #ffffff;
        }

        .mc-badge-active {
            background: #ffffff;
            color: #05356b;
            border: 1px solid #05356b;
        }

        .mc-badge-inactive {
            background: #ffffff;
            color: #05356b;
            border: 1px dashed #05356b;
        }

        .mc-badge-local {
            background: #ffffff;
            color: #05356b;
            border: 1px solid #05356b;
        }

        .mc-badge-cloud {
            background: #ffffff;
            color: #05356b;
            border: 1px solid #05356b;
        }

        .mc-badge-installed {
            background: #ffffff;
            color: #05356b;
            border: 1px solid #05356b;
        }

        .mc-badge-missing {
            background: #ffffff;
            color: red;
            border: 1px solid red;
        }

        .model-meta-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-bottom: 14px;
        }

        .meta-item {
            display: flex;
            flex-direction: column;
        }

        .meta-label {
            font-size: 0.7rem;
            color: #05356b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }

        .meta-value {
            font-size: 0.88rem;
            color: #05356b;
            font-weight: 500;
        }

        .model-card-actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            padding-top: 14px;
            border-top: 1px solid #05356b;
        }

        .model-card-actions .btn {
            font-size: 0.75rem;
            padding: 5px 12px;
            background: #ffffff;
            color: #05356b;
            border: 1px solid #05356b;
        }
        
        .model-card-actions .btn:hover {
            background: #05356b;
            color: #ffffff;
        }

        /* Override specific primary/secondary bootstrap colors if used */

        /* Stats row */
        .ai-stats-row {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }

        .ai-stat-card {
            flex: 1;
            min-width: 150px;
            background: #05356b;
            border: 1px solid #ffffff;
            border-radius: 10px;
            padding: 16px;
            text-align: center;
        }

        .ai-stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #ffffff;
        }

        .ai-stat-label {
            font-size: 0.9rem;
            color: #ffffff;
            margin-top: 4px;
        }

        /* Ollama status */
        .ollama-status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 16px;
            border: 1px solid #05356b;
        }

        .ollama-status.online {
            background: #ffffff;
            color: #05356b;
        }

        .ollama-status.offline {
            background: #ffffff;
            color: red;
            border-color: red;
        }

        .ollama-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }

        .ollama-dot.online {
            background: #05356b;
            animation: pulse 2s infinite;
        }

        .ollama-dot.offline {
            background: red;
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.4;
            }
        }

        /* Usage chart */
        .usage-chart-card {
            background: #ffffff;
            border: 1px solid #05356b;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            max-height: 400px;
            overflow-y: auto;
        }

        .usage-chart-card h3 {
            color: #05356b;
            font-size: 1rem;
            margin-bottom: 16px;
        }

        /* Expandable details */
        .model-expand-section {
            background: #ffffff;
            border: 1px solid #05356b;
            border-radius: 8px;
            padding: 16px;
            margin-top: 12px;
        }

        .expand-toggle {
            cursor: pointer;
            color: #05356b;
            font-size: 0.82rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .expand-toggle:hover {
            color: #05356b;
            text-decoration: underline;
        }

        /* Quick test box */
        .quick-test-box {
            background: #ffffff;
            border: 1px solid #05356b;
            border-radius: 8px;
            padding: 14px;
            margin-top: 12px;
        }

        .quick-test-box input {
            width: calc(100% - 80px);
            padding: 6px 10px;
            border: 1px solid #05356b;
            border-radius: 6px;
            background: #ffffff;
            color: #05356b;
            font-size: 0.82rem;
        }

        .quick-test-box .test-btn {
            padding: 6px 14px;
            background: #05356b;
            color: #ffffff;
            border: none;
            border-radius: 6px;
            font-size: 0.82rem;
            cursor: pointer;
        }

        .quick-test-result {
            margin-top: 10px;
            padding: 10px;
            background: #ffffff;
            border: 1px solid #05356b;
            border-radius: 6px;
            font-size: 0.82rem;
            color: #05356b;
            max-height: 150px;
            overflow-y: auto;
            display: none;
        }
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
                    <!-- ===== OLLAMA STATUS ===== -->
                    <div class="ollama-status <?= $ollama_running ? 'online' : 'offline' ?>">
                        <span class="ollama-dot <?= $ollama_running ? 'online' : 'offline' ?>"></span>
                        Ollama <?= $ollama_running ? 'Online' : 'Offline' ?>
                        <?php if ($ollama_running && isset($ollama_info['version'])): ?>
                            <span style="font-weight:400; font-size:0.78rem; color:#05356b; margin-left:8px;">
                                v<?= htmlspecialchars($ollama_info['version']) ?>
                            </span>
                        <?php
                        endif; ?>
                    </div>

                    <?php if ($notice): ?>
                        <div class="badge active" style="margin-bottom:12px;font-size:0.85rem;padding:8px 16px;">
                            <?php echo htmlspecialchars($notice); ?>
                        </div>
                    <?php
                    endif; ?>
                    <?php if ($error): ?>
                        <div class="badge inactive" style="margin-bottom:12px;font-size:0.85rem;padding:8px 16px;">
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php
                    endif; ?>

                    <!-- ===== STATS ROW ===== -->
                    <div class="ai-stats-row">
                        <div class="ai-stat-card">
                            <div class="ai-stat-value"><?= count($models) ?></div>
                            <div class="ai-stat-label">Total Models</div>
                        </div>
                        <div class="ai-stat-card">
                            <div class="ai-stat-value" style="color:#ffffff;"><?= $total_active ?></div>
                            <div class="ai-stat-label">Active</div>
                        </div>
                        <div class="ai-stat-card">
                            <div class="ai-stat-value" style="color:#ffffff;"><?= $total_inactive ?></div>
                            <div class="ai-stat-label">Inactive</div>
                        </div>
                        <div class="ai-stat-card">
                            <div class="ai-stat-value" style="color:#ffffff;"><?= count($installed_models) ?></div>
                            <div class="ai-stat-label">Installed (Ollama)</div>
                        </div>
                        <div class="ai-stat-card">
                            <div class="ai-stat-value"><?= number_format($total_size_mb) ?> <span
                                    style="font-size:0.9rem; color: white;">MB</span></div>
                            <div class="ai-stat-label">Total Size</div>
                        </div>
                    </div>

                    <!-- ===== DEFAULT MODEL HIGHLIGHT ===== -->
<!-- ===== DEFAULT MODEL HIGHLIGHT ===== -->
<?php if ($default_model): ?>
    <div
        style="
            background: #05356b;
            border: 1px solid #05356b;
            border-left: 4px solid #ffffff;
            border-radius: 14px;
            padding: 18px 22px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 18px;
            flex-wrap: wrap;
            box-shadow: none;
        ">
        
        <div
            style="
                width: 52px;
                height: 52px;
                min-width: 52px;
                border-radius: 50%;
                background: #ffffff;
                border: 1px solid #ffffff;
                display: flex;
                align-items: center;
                justify-content: center;
            ">
            <i class="fa-solid fa-star" style="font-size: 1.35rem; color: #05356b;"></i>
        </div>

        <div style="display: flex; flex-direction: column; justify-content: center;">
            <div
                style="
                    font-size: 0.75rem;
                    color: #ffffff;
                    text-transform: uppercase;
                    letter-spacing: 0.8px;
                    margin-bottom: 4px;
                    font-weight: 600;
                ">
                Current Default Model
            </div>
            <div
                style="
                    font-size: 1.08rem;
                    color: #ffffff;
                    font-weight: 700;
                    line-height: 1.4;
                    word-break: break-word;
                ">
                <?= htmlspecialchars($default_model['model_name']) ?>:<?= htmlspecialchars($default_model['model_version'] ?? 'latest') ?>
            </div>
        </div>

        <?php
        $dk = $default_model['model_name'] . ':' . ($default_model['model_version'] ?: 'latest');
        $default_usage = $model_usage[$dk] ?? null;
        if ($default_usage): ?>
            <div
                style="
                    margin-left: auto;
                    display: flex;
                    align-items: center;
                    gap: 14px;
                    flex-wrap: wrap;
                ">

                <div
                    style="
                        background: #05356b;
                        border: 1px solid #ffffff;
                        border-radius: 10px;
                        padding: 10px 14px;
                        min-width: 110px;
                        text-align: center;
                    ">
                    <div style="font-size: 0.72rem; color: #ffffff; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;">
                        Calls
                    </div>
                    <strong style="font-size: 1rem; color: #ffffff; font-weight: 700;">
                        <?= number_format((int)$default_usage['total_calls']) ?>
                    </strong>
                </div>

                <div
                    style="
                        background: #05356b;
                        border: 1px solid #ffffff;
                        border-radius: 10px;
                        padding: 10px 14px;
                        min-width: 110px;
                        text-align: center;
                    ">
                    <div style="font-size: 0.72rem; color: #ffffff; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;">
                        Avg Time
                    </div>
                    <strong style="font-size: 1rem; color: #ffffff; font-weight: 700;">
                        <?= round((float)$default_usage['avg_time']) ?>ms
                    </strong>
                </div>

                <div
                    style="
                        background: #05356b;
                        border: 1px solid #ffffff;
                        border-radius: 10px;
                        padding: 10px 14px;
                        min-width: 130px;
                        text-align: center;
                    ">
                    <div style="font-size: 0.72rem; color: #ffffff; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;">
                        Avg Confidence
                    </div>
                    <strong style="font-size: 1rem; color: #ffffff; font-weight: 700;">
                        <?= round((float)$default_usage['avg_conf'] * 100, 1) ?>%
                    </strong>
                </div>

            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>



                    <!-- ===== SYNC BUTTON + ADD CLOUD MODEL ===== -->
                    <div style="margin-bottom:16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                        <form method="post" style="display:inline-block">
                            <input type="hidden" name="action" value="sync_ollama">
                            <button class="btn btn-primary" type="submit">
                                <i class="fa-solid fa-arrows-rotate"></i> Sync from Ollama
                            </button>
                        </form>
                        <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#addCloudModelModal"
                            style="background:#05356b;border:none;">
                            <i class="fa-solid fa-cloud-arrow-up"></i> Add Cloud Model
                        </button>
                        <div style="margin-left:auto;display:flex;gap:4px;">
                            <button class="btn btn-secondary" id="viewCardsBtn" onclick="toggleModelView('cards')"
                                style="font-size:0.8rem;padding:6px 14px;">
                                <i class="fa-solid fa-grip"></i> Cards
                            </button>
                            <button class="btn" id="viewListBtn" onclick="toggleModelView('list')"
                                style="font-size:0.8rem;padding:6px 14px;background:#ffffff;color:#05356b;border:1px solid #05356b;">
                                <i class="fa-solid fa-list"></i> List
                            </button>
                        </div>
                    </div>

                    <!-- ===== MODEL LIST TABLE ===== -->
                    <div id="modelListView" style="display:none;margin-bottom:24px;">
                        <div style="overflow-x:auto;border-radius:12px;border:1px solid #334155;">
                            <table style="width:100%;border-collapse:collapse;font-size:0.85rem; background:#ffffff;">
                                <thead>
                                    <tr style="background:#05356b;">
                                        <th
                                            style="padding:12px 14px;text-align:left;color:#ffffff;font-weight:600;font-size:0.75rem;text-transform:uppercase;letter-spacing:0.5px;">
                                            Model</th>
                                        <th
                                            style="padding:12px 14px;text-align:left;color:#ffffff;font-weight:600;font-size:0.75rem;text-transform:uppercase;">
                                            Version</th>
                                        <th
                                            style="padding:12px 14px;text-align:center;color:#ffffff;font-weight:600;font-size:0.75rem;text-transform:uppercase;">
                                            Status</th>
                                        <th
                                            style="padding:12px 14px;text-align:center;color:#ffffff;font-weight:600;font-size:0.75rem;text-transform:uppercase;">
                                            Type</th>
                                        <th
                                            style="padding:12px 14px;text-align:center;color:#ffffff;font-weight:600;font-size:0.75rem;text-transform:uppercase;">
                                            Default</th>
                                        <th
                                            style="padding:12px 14px;text-align:right;color:#ffffff;font-weight:600;font-size:0.75rem;text-transform:uppercase;">
                                            Size</th>
                                        <?php if ($ollama_running): ?>
                                            <th
                                                style="padding:12px 14px;text-align:center;color:#ffffff;font-weight:600;font-size:0.75rem;text-transform:uppercase;">
                                                Installed</th>
                                        <?php
                                        endif; ?>
                                        <th
                                            style="padding:12px 14px;text-align:right;color:#ffffff;font-weight:600;font-size:0.75rem;text-transform:uppercase;">
                                            Calls</th>
                                        <th
                                            style="padding:12px 14px;text-align:right;color:#ffffff;font-weight:600;font-size:0.75rem;text-transform:uppercase;">
                                            Avg Time</th>
                                        <th
                                            style="padding:12px 14px;text-align:center;color:#ffffff;font-weight:600;font-size:0.75rem;text-transform:uppercase;">
                                            Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($models as $m):
                                        $mk = $m['model_name'] . ':' . ($m['model_version'] ?: 'latest');
                                        $is_installed = isset($installed_models[$mk]);
                                        $usage = $model_usage[$mk] ?? null;
                                        $size_gb = ($m['model_size_mb'] ?? 0) > 0 ? round((int)$m['model_size_mb'] / 1024, 1) : null;
                                    ?>
                                        <tr style="border-bottom:1px solid #05356b;transition:background 0.2s;"
                                            onmouseover="this.style.background='#ffffff'"
                                            onmouseout="this.style.background='#ffffff'">
                                            <td style="padding:10px 14px;color:#05356b;font-weight:600;">
                                                <i class="fa-solid fa-robot"
                                                    style="color:#05356b;margin-right:6px;"></i>
                                                <?= htmlspecialchars($m['model_name']) ?>
                                            </td>
                                            <td style="padding:10px 14px;color:#ffffff;">
                                                <?= htmlspecialchars($m['model_version'] ?: 'latest') ?>
                                            </td>
                                            <td style="padding:10px 14px;text-align:center;">
                                                <span
                                                    class="mc-badge <?= $m['status'] === 'active' ? 'mc-badge-active' : 'mc-badge-inactive' ?>"><?= $m['status'] ?></span>
                                            </td>
                                            <td style="padding:10px 14px;text-align:center;">
                                                <span
                                                    class="mc-badge <?= $m['model_type'] === 'cloud_api' ? 'mc-badge-cloud' : 'mc-badge-local' ?>"><?= $m['model_type'] === 'cloud_api' ? 'Cloud' : 'Local' ?></span>
                                            </td>
                                            <td style="padding:10px 14px;text-align:center;">
                                                <?php if ($m['is_default']): ?>
                                                    <i class="fa-solid fa-star" style="color:#05356b;"></i>
                                                <?php
                                                else: ?>
                                                    <span style="color:#05356b;">—</span>
                                                <?php
                                                endif; ?>
                                            </td>
                                            <td style="padding:10px 14px;text-align:right;color:#05356b;">
                                                <?= $size_gb ? $size_gb . ' GB' : '—' ?>
                                            </td>
                                            <?php if ($ollama_running): ?>
                                                <td style="padding:10px 14px;text-align:center;">
                                                    <span
                                                        class="mc-badge <?= $is_installed ? 'mc-badge-installed' : 'mc-badge-missing' ?>"><?= $is_installed ? '✓' : '✗' ?></span>
                                                </td>
                                            <?php
                                            endif; ?>
                                            <td style="padding:10px 14px;text-align:right;color:#05356b;">
                                                <?= $usage ? number_format((int)$usage['total_calls']) : '0' ?>
                                            </td>
                                            <td style="padding:10px 14px;text-align:right;color:#05356b;">
                                                <?= $usage ? round((float)$usage['avg_time']) . 'ms' : '—' ?>
                                            </td>
                                            <td style="padding:10px 14px;text-align:center;white-space:nowrap;">

                                                <form method="post" style="display:inline"><input type="hidden"
                                                        name="action" value="set_default"><input type="hidden"
                                                        name="model_id" value="<?= (int)$m['model_id'] ?>">
                                                    <button class="btn btn-primary" type="submit"
                                                        style="font-size:0.7rem;padding:3px 8px;" <?= $m['is_default'] ? 'disabled' : '' ?> title="Set Default">
                                                        <i class="fa-solid fa-star"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php
                                    endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- ===== MODEL CARDS ===== -->
                    <div class="model-cards-grid" id="modelCardsView">
                        <?php foreach ($models as $m):
                            $mk = $m['model_name'] . ':' . ($m['model_version'] ?: 'latest');
                            $is_installed = isset($installed_models[$mk]);
                            $usage = $model_usage[$mk] ?? null;
                            $met = $metrics_by_model[(int)$m['model_id']] ?? null;
                            $th = $training_by_model[(int)$m['model_id']] ?? [];
                            $size_gb = ($m['model_size_mb'] ?? 0) > 0 ? round((int)$m['model_size_mb'] / 1024, 1) : null;
                        ?>
                            <div class="model-card <?= $m['is_default'] ? 'is-default' : '' ?>">
                                <div class="model-card-header">
                                    <div class="model-card-icon <?= $m['status'] === 'active' ? 'active' : 'inactive' ?>"
                                        <?= $m['model_type'] === 'cloud_api' ? 'style="background:#05356b;"' : '' ?>>
                                        <i class="fa-solid <?= $m['model_type'] === 'cloud_api' ? 'fa-cloud' : 'fa-robot' ?>"></i>
                                    </div>
                                    <div>
                                        <h4 class="model-card-name"><?= htmlspecialchars($m['model_name']) ?></h4>
                                        <div class="model-card-version">
                                            <?= htmlspecialchars($m['model_version'] ?: 'latest') ?>
                                            <?php if ($size_gb): ?>
                                                · <?= $size_gb ?> GB
                                            <?php
                                            endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Badges -->
                                <div class="model-card-badges">
                                    <?php if ($m['is_default']): ?>
                                        <span class="mc-badge mc-badge-default"><i class="fa-solid fa-star"></i> Default</span>
                                    <?php
                                    endif; ?>
                                    <span
                                        class="mc-badge <?= $m['status'] === 'active' ? 'mc-badge-active' : 'mc-badge-inactive' ?>">
                                        <?= $m['status'] ?>
                                    </span>
                                    <span
                                        class="mc-badge <?= $m['model_type'] === 'cloud_api' ? 'mc-badge-cloud' : 'mc-badge-local' ?>">
                                        <?= $m['model_type'] === 'cloud_api' ? 'Cloud' : 'Local' ?>
                                    </span>
                                    <?php if ($m['model_type'] === 'cloud_api' && !empty($m['api_provider'])): ?>
                                        <span class="mc-badge" style="background:#ede9fe;color:#6d28d9;">
                                            <i class="fa-solid fa-cloud" style="margin-right:3px;"></i> <?= htmlspecialchars(ucfirst($m['api_provider'])) ?>
                                        </span>
                                    <?php
                                    elseif ($ollama_running): ?>
                                        <span class="mc-badge <?= $is_installed ? 'mc-badge-installed' : 'mc-badge-missing' ?>">
                                            <?= $is_installed ? '✓ Installed' : '✗ Not Found' ?>
                                        </span>
                                    <?php
                                    endif; ?>
                                </div>

                                <!-- Meta grid -->
                                <div class="model-meta-grid">
                                    <div class="meta-item">
                                        <span class="meta-label">Total Calls</span>
                                        <span
                                            class="meta-value"><?= $usage ? number_format((int)$usage['total_calls']) : '0' ?></span>
                                    </div>
                                    <div class="meta-item">
                                        <span class="meta-label">Avg Response</span>
                                        <span
                                            class="meta-value"><?= $usage ? round((float)$usage['avg_time']) . 'ms' : '—' ?></span>
                                    </div>
                                    <div class="meta-item">
                                        <span class="meta-label">Avg Confidence</span>
                                        <span
                                            class="meta-value"><?= $usage ? round((float)$usage['avg_conf'] * 100, 1) . '%' : '—' ?></span>
                                    </div>
                                    <div class="meta-item">
                                        <span class="meta-label">Last Used</span>
                                        <span
                                            class="meta-value"><?= $usage ? date('M j, g:ia', strtotime($usage['last_used'])) : '—' ?></span>
                                    </div>
                                </div>

                                <!-- Performance metrics -->
                                <?php if ($met): ?>
                                    <div style="margin-bottom:14px;">
                                        <div class="meta-label" style="margin-bottom:6px;">30-Day Performance</div>
                                        <?php
                                        $total_req = max((int)$met['total_requests'], 1);
                                        $success_pct = round((int)$met['successful_responses'] / $total_req * 100);
                                        $fail_pct = 100 - $success_pct;
                                        ?>
                                        <div
                                            style="display:flex;height:6px;border-radius:3px;overflow:hidden;background:#ffffff;border:1px solid #05356b;">
                                            <div style="width:<?= $success_pct ?>%;background:#05356b;"></div>
                                            <div style="width:<?= $fail_pct ?>%;background:red;"></div>
                                        </div>
                                        <div
                                            style="display:flex;justify-content:space-between;font-size:0.7rem;color:#05356b;margin-top:4px;">
                                            <span><?= $success_pct ?>% Success</span>
                                            <span><?= number_format((int)$met['total_requests']) ?> requests</span>
                                        </div>
                                    </div>
                                <?php
                                endif; ?>

                                <!-- Expandable details (Modal Button) -->
                                <div style="margin-bottom:12px;">
                                    <span class="expand-toggle" data-bs-toggle="modal" data-bs-target="#configModal<?= (int)$m['model_id'] ?>">
                                        <i class="fa-solid fa-gear"></i> Configuration & Details
                                    </span>
                                </div>

                                <!-- Configuration Modal -->
                                <div class="modal fade" id="configModal<?= (int)$m['model_id'] ?>" tabindex="-1" aria-labelledby="configModalLabel<?= (int)$m['model_id'] ?>" aria-hidden="true" style="z-index: 1060;">
                                    <div class="modal-dialog modal-dialog-centered modal-lg">
                                        <div class="modal-content" style="background:#ffffff; border:1px solid #05356b; border-radius:14px;">
                                            <div class="modal-header" style="border-bottom:1px solid #05356b;">
                                                <h5 class="modal-title" id="configModalLabel<?= (int)$m['model_id'] ?>" style="color:#05356b; font-weight:600;">
                                                    <i class="fa-solid fa-gear"></i> <?= htmlspecialchars($m['model_name']) ?> - Configuration
                                                </h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="transform: scale(0.65); outline: none; box-shadow: none; background:#b10505; width:60%; padding: 14px;"> Close</button>
                                            </div>
                                            <div class="modal-body" style="padding:20px; text-align:left;">
                                    <div class="model-expand-section" style="border:none; margin-top:0;">
                                        <div style="margin-bottom:10px;">
                                            <form method="post" style="margin-bottom:12px;">
                                                <input type="hidden" name="action" value="update_version">
                                                <input type="hidden" name="model_id" value="<?= (int)$m['model_id'] ?>">
                                                <span class="meta-label" style="display:block; margin-bottom:4px;">Version:</span>
                                                <input type="text" name="model_version"
                                                    value="<?= htmlspecialchars((string)$m['model_version']) ?>"
                                                    style="width: 100%; padding:4px 8px;background:#ffffff;border:1px solid #05356b;border-radius:4px;color:#05356b;font-size:0.82rem;">
                                                <button class="btn btn-secondary" type="submit"
                                                    style="font-size:0.70rem;padding: 10px;width:60%; margin-top:6px; display:block;">Save</button>
                                            </form>
                                        </div>
                                        <div style="margin-bottom:10px;">
                                            <form method="post" style="margin-bottom:12px;">
                                                <input type="hidden" name="action" value="update_path">
                                                <input type="hidden" name="model_id" value="<?= (int)$m['model_id'] ?>">
                                                <span class="meta-label" style="display:block; margin-bottom:4px;">Path:</span>
                                                <input type="text" name="model_path"
                                                    value="<?= htmlspecialchars((string)$m['model_path']) ?>"
                                                    placeholder="Model path"
                                                    style="width: 100%; padding:4px 8px;background:#ffffff;border:1px solid #05356b;border-radius:4px;color:#05356b;font-size:0.82rem;">
                                                <button class="btn btn-secondary" type="submit"
                                                    style="font-size:0.70rem;padding: 10px;width:60%; margin-top:6px; display:block;">Save</button>
                                            </form>
                                        </div>
                                        <div style="margin-bottom:10px;">
                                            <form method="post">
                                                <input type="hidden" name="action" value="update_type">
                                                <input type="hidden" name="model_id" value="<?= (int)$m['model_id'] ?>">
                                                <span class="meta-label">Type:</span>
                                                <select name="model_type" onchange="this.form.submit()"
                                                    style="padding:4px 8px;background:#ffffff;border:1px solid #05356b;border-radius:4px;color:#05356b;font-size:0.82rem;">
                                                    <option value="local_ollama" <?= $m['model_type'] === 'local_ollama' ? 'selected' : '' ?>>Local (Ollama)</option>
                                                    <option value="cloud_api" <?= $m['model_type'] === 'cloud_api' ? 'selected' : '' ?>>Cloud API</option>
                                                </select>
                                            </form>
                                        </div>
                                        <div>
                                            <form method="post">
                                                <input type="hidden" name="action" value="update_config">
                                                <input type="hidden" name="model_id" value="<?= (int)$m['model_id'] ?>">
                                                <span class="meta-label">Config JSON:</span>
                                                <textarea name="model_config" rows="4"
                                                    placeholder='{"temperature": 0.2, "num_predict": 256}'
                                                    style="width:100%;margin-top:4px;padding:8px;background:#ffffff;border:1px solid #05356b;border-radius:4px;color:#05356b;font-size:0.78rem;font-family:monospace;"><?= htmlspecialchars(isset($m['model_config']) && $m['model_config'] !== null ? json_encode(json_decode($m['model_config'], true), JSON_PRETTY_PRINT) : '') ?></textarea>
                                                <button class="btn btn-secondary" type="submit"
                                                    style="font-size:0.70rem;padding: 10px;width:60%; margin-top:6px; max-width: 100px;">Save
                                                    Config</button>
                                            </form>
                                        </div>

                                        <!-- Cloud API Config Section -->
                                        <?php if ($m['model_type'] === 'cloud_api'): ?>
                                            <div style="margin-top:12px;border-top:1px solid #05356b;padding-top:12px;">
                                                <div class="meta-label" style="margin-bottom:8px;color:#05356b;">
                                                    <i class="fa-solid fa-cloud" style="margin-right:4px;"></i> Cloud API Configuration
                                                </div>
                                                <form method="post">
                                                    <input type="hidden" name="action" value="update_cloud_config">
                                                    <input type="hidden" name="model_id" value="<?= (int)$m['model_id'] ?>">
                                                    <div style="margin-bottom:8px;">
                                                        <span class="meta-label" style="display:block;margin-bottom:3px;">Provider:</span>
                                                        <select name="api_provider"
                                                            style="width:100%;padding:5px 8px;background:#ffffff;border:1px solid #05356b;border-radius:4px;color:#05356b;font-size:0.82rem;">
                                                            <option value="gemini" <?= ($m['api_provider'] ?? '') === 'gemini' ? 'selected' : '' ?>>Google Gemini</option>
                                                            <option value="openai" <?= ($m['api_provider'] ?? '') === 'openai' ? 'selected' : '' ?>>OpenAI</option>
                                                            <option value="anthropic" <?= ($m['api_provider'] ?? '') === 'anthropic' ? 'selected' : '' ?>>Anthropic</option>
                                                            <option value="custom" <?= ($m['api_provider'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                                                        </select>
                                                    </div>
                                                    <div style="margin-bottom:8px;">
                                                        <span class="meta-label" style="display:block;margin-bottom:3px;">API Endpoint:</span>
                                                        <input type="text" name="api_endpoint"
                                                            value="<?= htmlspecialchars($m['api_endpoint'] ?? '') ?>"
                                                            placeholder="https://generativelanguage.googleapis.com/v1beta"
                                                            style="width:100%;padding:5px 8px;background:#ffffff;border:1px solid #05356b;border-radius:4px;color:#05356b;font-size:0.82rem;">
                                                    </div>
                                                    <div style="margin-bottom:8px;">
                                                        <span class="meta-label" style="display:block;margin-bottom:3px;">API Key:</span>
                                                        <input type="password" name="api_key"
                                                            value="<?= htmlspecialchars($m['api_key'] ?? '') ?>"
                                                            placeholder="Enter API key"
                                                            style="width:100%;padding:5px 8px;background:#ffffff;border:1px solid #05356b;border-radius:4px;color:#05356b;font-size:0.82rem;">
                                                        <?php if (!empty($m['api_key'])): ?>
                                                            <span style="font-size:0.72rem;color:#05356b;margin-top:3px;display:block;">✓ Key configured (<?= strlen($m['api_key']) ?> chars)</span>
                                                        <?php
                                                        endif; ?>
                                                    </div>
                                                    <button class="btn btn-secondary" type="submit"
                                                        style="font-size:0.70rem;padding: 10px;width:60%; "><i class="fa-solid fa-save"></i> Save Cloud Config</button>
                                                </form>
                                            </div>
                                        <?php
                                        endif; ?>

                                        <div style="margin-top:10px;font-size:0.78rem;color:#05356b;">
                                            <div>Created: <?= htmlspecialchars($m['created_at'] ?? '—') ?></div>
                                            <div>Updated: <?= htmlspecialchars($m['updated_at'] ?? '—') ?></div>
                                            <?php if ($m['deployed_at']): ?>
                                                <div>Deployed: <?= htmlspecialchars($m['deployed_at']) ?></div>
                                            <?php
                                            endif; ?>
                                        </div>

                                        <!-- Training history -->
                                        <?php if ($th): ?>
                                            <div style="margin-top:14px;border-top:1px solid #05356b;padding-top:12px;">
                                                <div class="meta-label" style="margin-bottom:6px;">Training History</div>
                                                <table style="width:100%;font-size:0.75rem;border-collapse:collapse;">
                                                    <tr style="color:#05356b;">
                                                        <th style="text-align:left;padding:4px;">Started</th>
                                                        <th style="text-align:left;padding:4px;">Type</th>
                                                        <th style="text-align:left;padding:4px;">Status</th>
                                                        <th style="text-align:left;padding:4px;">Epochs</th>
                                                    </tr>
                                                    <?php foreach ($th as $tr): ?>
                                                        <tr style="color:#ffffff;">
                                                            <td style="padding:4px;">
                                                                <?= htmlspecialchars($tr['training_started'] ?? '') ?>
                                                            </td>
                                                            <td style="padding:4px;">
                                                                <?= htmlspecialchars($tr['training_type'] ?? '') ?>
                                                            </td>
                                                            <td style="padding:4px;"><?= htmlspecialchars($tr['status'] ?? '') ?>
                                                            </td>
                                                            <td style="padding:4px;">
                                                                <?= htmlspecialchars((string)($tr['training_epochs'] ?? '')) ?>
                                                            </td>
                                                        </tr>
                                                    <?php
                                                    endforeach; ?>
                                                </table>
                                            </div>
                                        <?php
                                        endif; ?>
                                    </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Quick Test -->
                                <?php if ($is_installed && $m['status'] === 'active'): ?>
                                    <details>
                                        <summary class="expand-toggle">
                                            <i class="fa-solid fa-flask"></i> Quick Test
                                        </summary>
                                        <div class="quick-test-box">
                                            <div style="display:flex;gap:6px;">
                                                <input type="text" id="test-input-<?= (int)$m['model_id'] ?>"
                                                    placeholder="Type a test prompt..." value="Hello, what is MMU?">
                                                <button class="test-btn"
                                                    onclick="quickTest(<?= (int)$m['model_id'] ?>, '<?= htmlspecialchars($mk, ENT_QUOTES) ?>')">
                                                    <i class="fa-solid fa-play"></i> Test
                                                </button>
                                            </div>
                                            <div class="quick-test-result" id="test-result-<?= (int)$m['model_id'] ?>"></div>
                                        </div>
                                    </details>
                                <?php
                                endif; ?>

                                <!-- Actions -->
                                <div class="model-card-actions">

                                    <form method="post" style="display:inline">
                                        <input type="hidden" name="action" value="set_default">
                                        <input type="hidden" name="model_id" value="<?= (int)$m['model_id'] ?>">
                                        <button class="btn btn-primary" type="submit" <?= $m['is_default'] ? 'disabled' : '' ?>>
                                            <i class="fa-solid fa-star"></i> Set Default
                                        </button>
                                    </form>

                                    <?php if (!$m['is_default']): ?>
                                        <form method="post" style="display:inline" onsubmit="return confirm('Delete this model? This cannot be undone.')">
                                            <input type="hidden" name="action" value="delete_model">
                                            <input type="hidden" name="model_id" value="<?= (int)$m['model_id'] ?>">
                                            <button class="btn" type="submit"
                                                style="font-size:0.75rem;padding:5px 12px;background:#b10505;color:white;border:1px solid #b10505;">
                                                <i class="fa-solid fa-trash"></i> Delete
                                            </button>
                                        </form>
                                    <?php
                                    endif; ?>
                                </div>
                            </div>
                        <?php
                        endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== ADD CLOUD MODEL MODAL ===== -->
    <div class="modal fade" id="addCloudModelModal" tabindex="-1" aria-labelledby="addCloudModelLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="background:#1e293b;border:1px solid #334155;border-radius:16px;color:#e2e8f0;">
                <div class="modal-header" style="border-bottom:1px solid #334155;padding:20px 24px;">
                    <h5 class="modal-title" id="addCloudModelLabel" style="font-weight:700;font-size:1.1rem;">
                        <i class="fa-solid fa-cloud-arrow-up" style="color:#a78bfa;margin-right:8px;"></i> Add Cloud Model
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="add_cloud_model">
                    <div class="modal-body" style="padding:24px;">
                        <!-- Provider -->
                        <div style="margin-bottom:16px;">
                            <label style="display:block;font-size:0.8rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;">Provider *</label>
                            <select name="cloud_provider" id="cloudProviderSelect" onchange="updateCloudEndpoint()"
                                style="width:100%;padding:8px 12px;background:#0f172a;border:1px solid #334155;border-radius:8px;color:#e2e8f0;font-size:0.9rem;">
                                <option value="gemini">Google Gemini</option>
                                <option value="openai">OpenAI</option>
                                <option value="anthropic">Anthropic</option>
                                <option value="custom">Custom</option>
                            </select>
                        </div>
                        <!-- Model Name -->
                        <div style="margin-bottom:16px;">
                            <label style="display:block;font-size:0.8rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;">Model Name *</label>
                            <input type="text" name="cloud_model_name" required placeholder="e.g. gemini-2.0-flash"
                                style="width:100%;padding:8px 12px;background:#0f172a;border:1px solid #334155;border-radius:8px;color:#e2e8f0;font-size:0.9rem;">
                            <small style="color:#64748b;font-size:0.75rem;margin-top:4px;display:block;">The exact model identifier used by the API</small>
                        </div>
                        <!-- API Key -->
                        <div style="margin-bottom:16px;">
                            <label style="display:block;font-size:0.8rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;">API Key *</label>
                            <input type="password" name="cloud_api_key" required placeholder="Enter your API key"
                                style="width:100%;padding:8px 12px;background:#0f172a;border:1px solid #334155;border-radius:8px;color:#e2e8f0;font-size:0.9rem;">
                            <small style="color:#64748b;font-size:0.75rem;margin-top:4px;display:block;">Your API key will be stored securely in the database</small>
                        </div>
                        <!-- API Endpoint -->
                        <div style="margin-bottom:16px;">
                            <label style="display:block;font-size:0.8rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;">API Endpoint</label>
                            <input type="text" name="cloud_api_endpoint" id="cloudEndpointInput"
                                placeholder="Auto-filled based on provider"
                                style="width:100%;padding:8px 12px;background:#0f172a;border:1px solid #334155;border-radius:8px;color:#e2e8f0;font-size:0.9rem;">
                            <small style="color:#64748b;font-size:0.75rem;margin-top:4px;display:block;">Leave blank for provider default</small>
                        </div>
                        <!-- Version -->
                        <div style="margin-bottom:16px;">
                            <label style="display:block;font-size:0.8rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;">Version</label>
                            <input type="text" name="cloud_model_version" placeholder="e.g. v1, latest"
                                style="width:100%;padding:8px 12px;background:#0f172a;border:1px solid #334155;border-radius:8px;color:#e2e8f0;font-size:0.9rem;">
                        </div>
                        <!-- Config JSON -->
                        <div>
                            <label style="display:block;font-size:0.8rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;">Config JSON (optional)</label>
                            <textarea name="cloud_model_config" rows="3"
                                placeholder='{"temperature": 0.3, "max_output_tokens": 512}'
                                style="width:100%;padding:8px 12px;background:#0f172a;border:1px solid #334155;border-radius:8px;color:#e2e8f0;font-size:0.82rem;font-family:monospace;"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer" style="border-top:1px solid #334155;padding:16px 24px;">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="font-size:0.85rem;">Cancel</button>
                        <button type="submit" class="btn btn-primary" style="background:linear-gradient(135deg,#6366f1,#8b5cf6);border:none;font-size:0.85rem;">
                            <i class="fa-solid fa-plus"></i> Add Cloud Model
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="js/main.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
        crossorigin="anonymous"></script>
    <script src="js/custom.js"></script>
    <script>
        // Move modals out of deep tree to prevent transform hover flickering
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.modal').forEach(m => document.body.appendChild(m));
        });

        // Notification update
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

        // Toggle Cards / List view
        function toggleModelView(view) {
            const cards = document.getElementById('modelCardsView');
            const list = document.getElementById('modelListView');
            const cardsBtn = document.getElementById('viewCardsBtn');
            const listBtn = document.getElementById('viewListBtn');
            if (view === 'list') {
                cards.style.display = 'none';
                list.style.display = 'block';
                cardsBtn.className = 'btn';
                cardsBtn.style.cssText = 'font-size:0.8rem;padding:6px 14px;background:#1e293b;color:#94a3b8;border:1px solid #334155;';
                listBtn.className = 'btn btn-secondary';
                listBtn.style.cssText = 'font-size:0.8rem;padding:6px 14px;';
            } else {
                cards.style.display = 'grid';
                list.style.display = 'none';
                listBtn.className = 'btn';
                listBtn.style.cssText = 'font-size:0.8rem;padding:6px 14px;background:#1e293b;color:#94a3b8;border:1px solid #334155;';
                cardsBtn.className = 'btn btn-secondary';
                cardsBtn.style.cssText = 'font-size:0.8rem;padding:6px 14px;';
            }
            localStorage.setItem('aiModelView', view);
        }
        // Restore saved preference
        if (localStorage.getItem('aiModelView') === 'list') toggleModelView('list');

        // Cloud model endpoint auto-fill
        function updateCloudEndpoint() {
            const provider = document.getElementById('cloudProviderSelect').value;
            const input = document.getElementById('cloudEndpointInput');
            const endpoints = {
                'gemini': 'https://generativelanguage.googleapis.com/v1beta',
                'openai': 'https://api.openai.com/v1',
                'anthropic': 'https://api.anthropic.com/v1',
                'custom': ''
            };
            input.value = endpoints[provider] || '';
            input.placeholder = provider === 'custom' ? 'Enter your custom API endpoint' : 'Auto-filled based on provider';
        }

        // Model Usage Chart
        <?php if (!empty($model_usage)): ?>
            new Chart(document.getElementById('modelUsageChart'), {
                type: 'bar',
                data: {
                    labels: <?= json_encode(array_keys($model_usage)) ?>,
                    datasets: [{
                        label: 'Total Calls',
                        data: <?= json_encode(array_map(function ($u) {
                                    return (int)$u['total_calls'];
                                }, array_values($model_usage))) ?>,
                        backgroundColor: [
                            'rgba(59,130,246,0.7)', 'rgba(139,92,246,0.7)', 'rgba(16,185,129,0.7)',
                            'rgba(245,158,11,0.7)', 'rgba(239,68,68,0.7)', 'rgba(100,116,139,0.7)'
                        ],
                        borderColor: [
                            '#3b82f6', '#8b5cf6', '#10b981', '#f59e0b', '#ef4444', '#64748b'
                        ],
                        borderWidth: 1,
                        borderRadius: 6,
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
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(255,255,255,0.05)'
                            },
                            ticks: {
                                color: '#64748b'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                color: '#94a3b8',
                                font: {
                                    size: 11
                                }
                            }
                        }
                    }
                }
            });
        <?php
        endif; ?>

        // Quick Test function
        function quickTest(modelId, modelName) {
            const input = document.getElementById('test-input-' + modelId);
            const result = document.getElementById('test-result-' + modelId);
            const prompt = input.value.trim();
            if (!prompt) return;

            result.style.display = 'block';
            result.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Testing ' + modelName + '...';

            const startTime = Date.now();

            fetch('http://127.0.0.1:11434/api/generate', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        model: modelName,
                        prompt: prompt,
                        stream: false,
                        options: {
                            num_predict: 100,
                            num_ctx: 512
                        }
                    })
                })
                .then(function(res) {
                    return res.json();
                })
                .then(function(data) {
                    const elapsed = ((Date.now() - startTime) / 1000).toFixed(1);
                    result.innerHTML = '<div style="color:#34d399;font-weight:600;margin-bottom:6px;">✓ Response in ' + elapsed + 's</div>' +
                        '<div style="color:#e2e8f0;">' + (data.response || 'No response').replace(/</g, '&lt;').substring(0, 500) + '</div>' +
                        '<div style="margin-top:6px;color:#475569;font-size:0.72rem;">Tokens: ' + (data.eval_count || '?') + ' | Model: ' + modelName + '</div>';
                })
                .catch(function(err) {
                    const elapsed = ((Date.now() - startTime) / 1000).toFixed(1);
                    result.innerHTML = '<div style="color:#f87171;">✗ Failed after ' + elapsed + 's: ' + (err.message || 'Unknown error') + '</div>';
                });
        }
    </script>
</body>

</html>