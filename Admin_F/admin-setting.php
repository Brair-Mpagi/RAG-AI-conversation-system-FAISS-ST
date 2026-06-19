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
if (!$conn || $conn->connect_error)
    die("Connection failed: " . ($conn ? $conn->connect_error : 'No connection object.'));

$admin_query = "SELECT admin_id, username, email, full_name, profile_image FROM admins WHERE admin_id = ?";
if (!$conn->ping()) die("Database connection is closed.");
$admin_stmt = $conn->prepare($admin_query);
if (!$admin_stmt) die("Prepare failed: " . $conn->error);
$admin_stmt->bind_param('i', $_SESSION['admin_id']);
$admin_stmt->execute();
$admin = $admin_stmt->get_result()->fetch_assoc();
$admin_stmt->close();

$stmt = $conn->prepare("SELECT admin_id, role FROM admins WHERE admin_id = ?");
$stmt->bind_param('i', $_SESSION['admin_id']);
$stmt->execute();
$current_admin = $stmt->get_result()->fetch_assoc();
if (!$current_admin) {
    session_unset(); session_destroy();
    header('Location: ./admin-login.php?err=account_missing'); exit();
}
$current_admin_role = $current_admin['role'] ?? 'admin';
$current_admin_id   = $current_admin['admin_id'];
$stmt->close();

$current_avatar_path = 'images/default_admin_icon.png';
try {
    $aid = (int) $current_admin_id;
    foreach (["uploads/admin_{$aid}_avatar.jpg","uploads/admin_{$aid}_avatar.jpeg","uploads/admin_{$aid}_avatar.png","uploads/admin_{$aid}_avatar.webp"] as $p) {
        if (file_exists(__DIR__ . '/' . $p)) { $current_avatar_path = $p; break; }
    }
} catch (Exception $e) {}

$error = $success = null;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['create']) && $current_admin_role === 'super_admin') {
        $username  = trim($_POST['username'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $password  = trim($_POST['password'] ?? '');
        $full_name = trim($_POST['full_name'] ?? '');
        $role      = $_POST['role'] ?? 'admin';
        if (empty($username) || empty($email) || empty($password) || empty($full_name)) {
            $error = "All fields (username, email, full name, password) are required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Invalid email format.";
        } elseif (!in_array($role, ['super_admin','admin','moderator','viewer'])) {
            $error = "Invalid role selected.";
        } else {
            try {
                $stmt = $conn->prepare("SELECT admin_id FROM admins WHERE email = ?");
                $stmt->bind_param('s', $email); $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $error = "Email already exists."; $stmt->close();
                } else {
                    $stmt->close();
                    $password_hashed = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $conn->prepare("INSERT INTO admins (username, email, password_hash, full_name, role, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                    $stmt->bind_param('sssss', $username, $email, $password_hashed, $full_name, $role);
                    if ($stmt->execute()) {
                        $success = "Admin created successfully! (ID: " . $stmt->insert_id . ")";
                        header("Location: admin-setting.php?success=" . urlencode($success)); exit();
                    } else { $error = "Failed to create admin: " . $stmt->error; }
                    $stmt->close();
                }
            } catch (Exception $e) { $error = "Error: " . $e->getMessage(); }
        }
    } elseif (isset($_POST['update'])) {
        $admin_id  = (int) $_POST['admin_id'];
        $can_update = ($current_admin_role === 'super_admin' || $admin_id == $current_admin_id);
        if (!$can_update) { $error = "Access Denied."; } else {
            $username = trim($_POST['username']);
            $email    = trim($_POST['email']);
            $password = !empty($_POST['password']) ? password_hash(trim($_POST['password']), PASSWORD_BCRYPT) : null;
            if (empty($username) || empty($email)) { $error = "Username and email are required."; }
            elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $error = "Invalid email format."; }
            else {
                try {
                    if (isset($_FILES['avatar']) && is_uploaded_file($_FILES['avatar']['tmp_name'])) {
                        $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
                        if (!in_array($ext, ['jpg','jpeg','png','webp'])) throw new Exception('Invalid avatar format.');
                        $uploadDir = __DIR__ . '/uploads';
                        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
                        foreach (['jpg','jpeg','png','webp'] as $ex) @unlink($uploadDir . "/admin_{$admin_id}_avatar.$ex");
                        if (!move_uploaded_file($_FILES['avatar']['tmp_name'], $uploadDir . "/admin_{$admin_id}_avatar.$ext"))
                            throw new Exception('Failed to save avatar image.');
                    }
                    if ($password) {
                        $stmt = $conn->prepare("UPDATE admins SET username=?, email=?, password_hash=? WHERE admin_id=?");
                        $stmt->bind_param('sssi', $username, $email, $password, $admin_id);
                    } else {
                        $stmt = $conn->prepare("UPDATE admins SET username=?, email=? WHERE admin_id=?");
                        $stmt->bind_param('ssi', $username, $email, $admin_id);
                    }
                    if ($stmt->execute()) {
                        $success = "Admin updated successfully!";
                        header("Location: admin-setting.php?success=" . urlencode($success)); exit();
                    } else { $error = "Failed to update admin: " . $stmt->error; }
                    $stmt->close();
                } catch (Exception $e) { $error = "Error: " . $e->getMessage(); }
            }
        }
    } elseif (isset($_POST['delete']) && $current_admin_role === 'super_admin') {
        $admin_id = (int) $_POST['admin_id'];
        if ($admin_id == $current_admin_id) { $error = "You cannot delete your own account."; }
        else {
            try {
                $stmt = $conn->prepare("SELECT role FROM admins WHERE admin_id=?");
                $stmt->bind_param('i', $admin_id); $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
                $deleting_role = $row['role'];
                $stmt = $conn->prepare("SELECT COUNT(*) FROM admins WHERE role='super_admin'");
                $stmt->execute(); $cnt = $stmt->get_result()->fetch_row()[0]; $stmt->close();
                if ($deleting_role === 'super_admin' && $cnt <= 1) { $error = "Cannot delete the last Super Admin."; }
                else {
                    $stmt = $conn->prepare("DELETE FROM admins WHERE admin_id=?");
                    $stmt->bind_param('i', $admin_id);
                    if ($stmt->execute()) { $success = "Admin deleted."; header("Location: admin-setting.php?success=" . urlencode($success)); exit(); }
                    else { $error = "Failed to delete: " . $stmt->error; }
                    $stmt->close();
                }
            } catch (Exception $e) { $error = "Error: " . $e->getMessage(); }
        }
    }
}

$stmt = $conn->prepare("SELECT admin_id, username, email, full_name, role, profile_image, last_login_at, created_at FROM admins ORDER BY created_at ASC");
$stmt->execute();
$admins = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

$role_config = [
    'super_admin' => ['icon' => 'fa-crown',       'class' => 'role-super',   'label' => 'Super Admin'],
    'admin'       => ['icon' => 'fa-user-shield',  'class' => 'role-admin',   'label' => 'Admin'],
    'moderator'   => ['icon' => 'fa-user-pen',     'class' => 'role-mod',     'label' => 'Moderator'],
    'viewer'      => ['icon' => 'fa-eye',          'class' => 'role-viewer',  'label' => 'Viewer'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Admin Settings</title>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="shortcut icon" href="images/mmu_logo_- no bg.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"
        integrity="sha512-Kc323vGBEqzTmouAECnVceyQqyqdsSiqLQISBL29aUW4U/M7pSPA/gEUZQqv1cwx4OnYxTxve5UMg5GT6L4JJg=="
        crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="css/style.css?v=1775081173" rel="stylesheet" />
    <link href="css/style-mob.css" rel="stylesheet" />
    <link href="css/admin.css" rel="stylesheet" />
    <link href="css/admin-profile.css" rel="stylesheet" />
    <style>
        /* ============================================================
           ADMIN SETTINGS — Enterprise Design System
        ============================================================ */
        :root {
            --as-bg:           #f0f2f7;
            --as-surface:      #ffffff;
            --as-surface-2:    #f8f9fc;
            --as-border:       #e2e6ef;
            --as-border-soft:  #eef0f6;
            --as-primary:      #002147;
            --as-primary-mid:  #05356b;
            --as-accent:       #1a6ef7;
            --as-accent-soft:  #e8f0fe;
            --as-success:      #059669;
            --as-success-bg:   #ecfdf5;
            --as-warn:         #d97706;
            --as-warn-bg:      #fffbeb;
            --as-danger:       #dc2626;
            --as-danger-bg:    #fef2f2;
            --as-text:         #111827;
            --as-text-2:       #374151;
            --as-text-3:       #6b7280;
            --as-text-4:       #9ca3af;
            --as-mono:         'JetBrains Mono', monospace;
            --as-sans:         'Inter', sans-serif;
            --as-radius:       10px;
            --as-radius-lg:    14px;
            --as-shadow:       0 1px 4px rgba(0,0,0,.07), 0 4px 18px rgba(0,0,0,.04);
            --as-shadow-md:    0 2px 8px rgba(0,0,0,.09), 0 8px 28px rgba(0,0,0,.06);
        }

        [class*="fa-"], .fa, .fas, .far, .fab, .fa-solid, .fa-regular, .fa-brands {
            font-family: "Font Awesome 6 Free" !important;
            font-weight: 900 !important;
            font-style: normal !important;
            font-variant: normal !important;
            text-rendering: auto;
            -webkit-font-smoothing: antialiased;
            display: inline-block !important;
            line-height: 1;
        }

        /* ── Page Shell ── */
        .as-page { font-family: var(--as-sans); background: var(--as-bg); padding:10px;}

        /* ── Command Bar ── */
        .as-command-bar {
            background: var(--as-primary);
            border-bottom: 3px solid var(--as-accent);
            padding: 14px 28px;
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .as-command-bar .as-title {
            font-weight: 700;
            font-size: 1rem;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 10px;
            letter-spacing: -.01em;
        }
        .as-command-bar .as-title i { color: #7db3ff; }
        .as-breadcrumb {
            font-size: .75rem;
            color: rgba(255,255,255,.5);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .as-breadcrumb span { color: rgba(255,255,255,.8); }

        /* ── Alert Banner ── */
        .as-alert {
            margin: 16px 28px 0;
            padding: 11px 16px;
            border-radius: var(--as-radius);
            font-size: .83rem;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
        }
        .as-alert--success { background: var(--as-success-bg); color: #065f46; border: 1px solid #a7f3d0; }
        .as-alert--error   { background: var(--as-danger-bg);  color: #991b1b; border: 1px solid #fca5a5; }
        .as-alert button { margin-left: auto; background: none; border: none; color: inherit; cursor: pointer; font-size: 1rem; padding: 0; }

        /* ── Layout: two-column ── */
        .as-layout {
            display: grid;
            grid-template-columns: 340px 1fr;
            gap: 24px;
            padding: 24px 28px;
            align-items: flex-start;
        }
        @media (max-width: 960px) { .as-layout { grid-template-columns: 1fr; } }

        /* ── Panels ── */
        .as-panel {
            background: var(--as-surface);
            border-radius: var(--as-radius-lg);
            border: 1px solid var(--as-border);
            box-shadow: var(--as-shadow);
            overflow: hidden;
        }
        .as-panel-header {
            padding: 14px 20px;
            border-bottom: 1px solid var(--as-border-soft);
            background: var(--as-surface-2);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .as-panel-header .as-panel-title {
            font-size: .88rem;
            font-weight: 700;
            color: var(--as-text);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .as-panel-header .as-panel-title i { color: var(--as-accent); }
        .as-panel-body { padding: 20px; }

        /* ── Profile Card (left column) ── */
        .as-profile-avatar-wrap {
            position: relative;
            display: inline-block;
            margin-bottom: 16px;
        }
        .as-profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--as-primary);
            display: block;
        }
        .as-avatar-placeholder {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: var(--as-surface-2);
            border: 2px dashed var(--as-border);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            color: var(--as-text-4);
        }
        .as-avatar-overlay {
            position: absolute;
            bottom: 4px;
            right: 4px;
            width: 26px;
            height: 26px;
            background: var(--as-accent);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: .65rem;
            cursor: pointer;
            border: 2px solid #fff;
        }
        .as-profile-name {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--as-text);
            margin-bottom: 2px;
        }
        .as-profile-meta {
            font-size: .76rem;
            color: var(--as-text-3);
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: center;
            margin-bottom: 4px;
        }

        /* ── Role Badge ── */
        .as-role-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: .72rem;
            font-weight: 700;
            text-transform: capitalize;
        }
        .role-super  { background: #fef3c7; color: #92400e; }
        .role-admin  { background: #dbeafe; color: #1e40af; }
        .role-mod    { background: #ede9fe; color: #5b21b6; }
        .role-viewer { background: #f0fdf4; color: #166534; }

        /* ── Form Elements ── */
        .as-label {
            display: block;
            font-size: .76rem;
            font-weight: 600;
            color: var(--as-text-2);
            margin-bottom: 5px;
        }
        .as-label .req { color: var(--as-danger); }
        .as-input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--as-border);
            border-radius: 7px;
            font-size: .83rem;
            font-family: var(--as-sans);
            color: var(--as-text);
            background: var(--as-surface);
            outline: none;
            transition: border-color .15s, box-shadow .15s;
        }
        .as-input:focus { border-color: var(--as-accent); box-shadow: 0 0 0 3px rgba(26,110,247,.1); }
        .as-form-group { margin-bottom: 14px; }
        .as-form-grid { display: grid; gap: 14px; }
        .as-form-grid.cols-2 { grid-template-columns: 1fr 1fr; }
        @media (max-width: 640px) { .as-form-grid.cols-2 { grid-template-columns: 1fr; } }

        .as-form-section-label {
            font-size: .68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .09em;
            color: var(--as-text-4);
            border-bottom: 1px solid var(--as-border-soft);
            padding-bottom: 6px;
            margin-bottom: 12px;
            margin-top: 18px;
        }
        .as-form-section-label:first-child { margin-top: 0; }

        /* ── Buttons ── */
        .as-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 7px;
            font-size: .8rem;
            font-weight: 600;
            font-family: var(--as-sans);
            cursor: pointer;
            border: 1px solid transparent;
            transition: all .13s;
            text-decoration: none;
            white-space: nowrap;
        }
        .as-btn--primary { background: linear-gradient(135deg, var(--as-primary), var(--as-primary-mid)); color: #fff; }
        .as-btn--primary:hover { opacity: .88; color: #fff; }
        .as-btn--accent  { background: #002147; color: #fff; border-color: var(--as-accent);  ; }
        .as-btn--accent:hover { background: #05356b; color: #fff; }
        .as-btn--outline { background: transparent; color: var(--as-text-2); border-color: var(--as-border); }
        .as-btn--outline:hover { border-color: var(--as-accent); color: var(--as-accent); background: var(--as-accent-soft); }
        .as-btn--danger  { background: transparent; color: var(--as-danger); border-color: #fca5a5; }
        .as-btn--danger:hover  { background: var(--as-danger-bg); }
        .as-btn--sm { padding: 4px 10px; font-size: .75rem; }
        .as-btn--full { width: 60%; justify-content: center; margin-left: 20%; }

        /* ── Password Strength ── */
        .pw-strength-bar {
            height: 3px;
            background: var(--as-border);
            border-radius: 4px;
            margin-top: 6px;
            overflow: hidden;
        }
        .pw-strength-fill { height: 100%; width: 0; border-radius: 4px; transition: width .3s, background .3s; }
        .pw-strength-text { font-size: .72rem; color: var(--as-text-3); margin-top: 3px; display: block; }

        /* ── Admin Table ── */
        .as-table-wrap { overflow-x: auto; }
        .as-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .82rem;
        }
        .as-table thead th {
            background: var(--as-surface-2);
            border-bottom: 2px solid var(--as-border);
            padding: 10px 14px;
            text-align: left;
            font-size: .68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--as-text-3);
            white-space: nowrap;
        }
        .as-table tbody td {
            padding: 11px 14px;
            border-bottom: 1px solid var(--as-border-soft);
            vertical-align: middle;
            color: var(--as-text-2);
        }
        .as-table tbody tr:last-child td { border-bottom: none; }
        .as-table tbody tr:hover td { background: #fafbff; }

        /* ── Avatar in table ── */
        .as-table-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--as-border);
        }
        .as-table-avatar-placeholder {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: var(--as-surface-2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: var(--as-text-4);
        }

        /* ── Self row highlight ── */
        .as-table tbody tr.is-self td { background: #f0f4ff; }
        .as-self-tag {
            font-size: .65rem;
            background: var(--as-accent-soft);
            color: var(--as-accent);
            padding: 1px 6px;
            border-radius: 8px;
            font-weight: 700;
            margin-left: 4px;
        }

        /* ── Tabs in right column ── */
        .as-tabs { display: flex; gap: 0; border-bottom: 2px solid var(--as-border); margin-bottom: 20px; }
        .as-tab {
            padding: 9px 16px;
            font-size: .8rem;
            font-weight: 600;
            color: var(--as-text-3);
            cursor: pointer;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            transition: all .13s;
            display: flex;
            align-items: center;
            gap: 7px;
        }
        .as-tab:hover { color: var(--as-accent); }
        .as-tab.active { color: var(--as-accent); border-bottom-color: var(--as-accent); }
        .as-tab-pane { display: none; }
        .as-tab-pane.active { display: block; }

        /* ── Create form card ── */
        .as-create-section {
            background: var(--as-surface-2);
            border: 1px solid var(--as-border-soft);
            border-radius: var(--as-radius);
            padding: 18px;
            margin-bottom: 20px;
        }
        .as-create-title {
            font-size: .85rem;
            font-weight: 700;
            color: var(--as-text);
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .as-create-title i { color: var(--as-accent); }

        /* ── Admin count pill ── */
        .as-count-pill {
            background: var(--as-accent-soft);
            color: var(--as-accent);
            border-radius: 20px;
            font-size: .7rem;
            padding: 2px 9px;
            font-weight: 700;
            font-family: var(--as-mono);
        }

        /* ── Edit Modal ── */
        .modal-content { border-radius: 12px; border: 1px solid var(--as-border); }
        .modal-header  { background: var(--as-surface-2); border-bottom: 1px solid var(--as-border-soft); padding: 14px 20px; }
        .modal-title   { font-family: var(--as-sans); font-size: .9rem; font-weight: 700; }
        .modal-body    { padding: 20px; }
        .modal-footer  { padding: 12px 20px; border-top: 1px solid var(--as-border-soft); }

        /* ── Misc ── */
        .as-divider { height: 1px; background: var(--as-border-soft); margin: 16px 0; }
        .as-hint { font-size: .72rem; color: var(--as-text-4); margin-top: 3px; font-style: italic; }
        .as-id-tag { font-family: var(--as-mono); font-size: .72rem; background: var(--as-bg); padding: 2px 7px; border-radius: 4px; border: 1px solid var(--as-border); color: var(--as-text-3); }
        .as-date { font-size: .76rem; color: var(--as-text-3); white-space: nowrap; font-family: var(--as-mono); }
    </style>
</head>
<body>
    <!-- ── Outer Shell (unchanged) ── -->
    <!--== MAIN CONTAINER ==-->
     <!--== MAIN CONTAINER ==-->
    <?php include 'includes/topbar.php'; ?>

    </div>
    </div>

    <div class="container-fluid sb2">
        <div class="row">
            <?php include 'includes/sidebar.php'; ?>

            <div class="sb2-2 col-md-9" style="padding:0;">
                <div class="as-page">

                    <!-- ── Command Bar ── -->
                    <div class="as-command-bar">
                        <div class="as-title"><i class="fa-solid fa-users-gear"></i> Admin Settings</div>
                        <div class="as-breadcrumb">
                           
                            <i class="fa-solid fa-chevron-right" style="font-size:.55rem;"></i>
                            <span>Settings</span>
                        </div>
                        <div style="margin-left:auto;display:flex;gap:8px;align-items:center;">
                            <span style="font-size:.75rem;color:rgba(255,255,255,.6);">
                                Logged in as&nbsp;<strong style="color:#fff"><?= htmlspecialchars($admin['username']) ?></strong>
                            </span>
                            <span class="as-role-badge <?= $role_config[$current_admin_role]['class'] ?? 'role-admin' ?>">
                                <i class="fa-solid <?= $role_config[$current_admin_role]['icon'] ?? 'fa-user' ?>"></i>
                                <?= $role_config[$current_admin_role]['label'] ?? $current_admin_role ?>
                            </span>
                        </div>
                    </div>

                    <!-- ── Alerts ── -->
                    <?php if (isset($_GET['success'])): ?>
                    <div class="as-alert as-alert--success">
                        <i class="fa-solid fa-circle-check"></i><?= htmlspecialchars($_GET['success']) ?>
                        <button onclick="this.parentElement.remove()">&times;</button>
                    </div>
                    <?php endif; ?>
                    <?php if (isset($success)): ?>
                    <div class="as-alert as-alert--success">
                        <i class="fa-solid fa-circle-check"></i><?= htmlspecialchars($success) ?>
                        <button onclick="this.parentElement.remove()">&times;</button>
                    </div>
                    <?php endif; ?>
                    <?php if (isset($error)): ?>
                    <div class="as-alert as-alert--error">
                        <i class="fa-solid fa-circle-xmark"></i><?= htmlspecialchars($error) ?>
                        <button onclick="this.parentElement.remove()">&times;</button>
                    </div>
                    <?php endif; ?>

                    <!-- ── Two-Column Layout ── -->
                    <div class="as-layout">

                        <!-- ════ LEFT: My Profile ════ -->
                        <div>
                            <div class="as-panel">
                                <div class="as-panel-header">
                                    <div class="as-panel-title"><i class="fa-solid fa-circle-user"></i> My Profile</div>
                                    <span class="as-id-tag"><?= htmlspecialchars(format_admin_id($current_admin_id)) ?></span>
                                </div>
                                <div class="as-panel-body">
                                    <form method="POST" action="" enctype="multipart/form-data">
                                        <input type="hidden" name="admin_id" value="<?= $current_admin_id ?>">
                                        <input type="hidden" name="update" value="1">

                                        <!-- Avatar -->
                                        <div style="text-align:center;margin-bottom:20px;">
                                            <div class="as-profile-avatar-wrap" style="display:inline-block;">
                                                <?php if ($current_avatar_path && file_exists(__DIR__ . '/' . $current_avatar_path)): ?>
                                                    <img src="<?= htmlspecialchars($current_avatar_path) ?>" alt="Avatar" class="as-profile-avatar">
                                                <?php else: ?>
                                                    <div class="as-avatar-placeholder"><i class="fa-solid fa-user"></i></div>
                                                <?php endif; ?>
                                                <label for="selfAvatar" class="as-avatar-overlay" title="Change photo">
                                                    <i class="fa-solid fa-camera"></i>
                                                </label>
                                                <input type="file" name="avatar" id="selfAvatar" accept="image/*" style="display:none" onchange="previewAvatar(this)">
                                            </div>
                                            <div class="as-profile-name"><?= htmlspecialchars($admin['full_name'] ?? $admin['username']) ?></div>
                                            <div class="as-profile-meta">
                                                <span class="as-role-badge <?= $role_config[$current_admin_role]['class'] ?? 'role-admin' ?>">
                                                    <i class="fa-solid <?= $role_config[$current_admin_role]['icon'] ?? 'fa-user' ?>"></i>
                                                    <?= $role_config[$current_admin_role]['label'] ?? $current_admin_role ?>
                                                </span>
                                            </div>
                                            <div style="font-size:.72rem;color:var(--as-text-4);margin-top:2px;"><?= htmlspecialchars($admin['email']) ?></div>
                                        </div>

                                        <div class="as-divider"></div>

                                        <!-- Account Info -->
                                        <div class="as-form-section-label">Account Information</div>
                                        <div class="as-form-group">
                                            <label class="as-label"><i class="fa-solid fa-user" style="color:var(--as-accent);margin-right:4px;"></i>Username <span class="req">*</span></label>
                                            <input type="text" name="username" class="as-input" value="<?= htmlspecialchars($admin['username']) ?>" required>
                                        </div>
                                        <div class="as-form-group">
                                            <label class="as-label"><i class="fa-solid fa-envelope" style="color:var(--as-accent);margin-right:4px;"></i>Email <span class="req">*</span></label>
                                            <input type="email" name="email" class="as-input" value="<?= htmlspecialchars($admin['email']) ?>" required>
                                        </div>

                                        <!-- Security -->
                                        <div class="as-form-section-label">Security</div>
                                        <div class="as-form-group">
                                            <label class="as-label"><i class="fa-solid fa-lock" style="color:var(--as-accent);margin-right:4px;"></i>New Password</label>
                                            <input type="password" name="password" class="as-input pw-strength-input" placeholder="Leave blank to keep current" oninput="checkPwStrength(this)">
                                            <div class="pw-strength-bar"><div class="pw-strength-fill"></div></div>
                                            <small class="pw-strength-text"></small>
                                        </div>

                                        <!-- Profile Image -->
                                        <div class="as-form-section-label">Profile Photo</div>
                                        <div class="as-form-group">
                                            <label class="as-label"><i class="fa-solid fa-image" style="color:var(--as-accent);margin-right:4px;"></i>Upload Image</label>
                                            <input type="file" name="avatar" class="as-input" accept="image/*" style="padding:5px 12px;">
                                            <div class="as-hint">JPG, PNG or WebP. Max recommended: 2MB.</div>
                                        </div>

                                        <div class="as-divider"></div>
                                        <button type="submit" class="as-btn as-btn--primary as-btn--full">
                                            <i class="fa-solid fa-floppy-disk"></i> Save Profile Changes
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- ════ RIGHT: Admin Management ════ -->
                        <div>
                            <div class="as-panel">
                                <div class="as-panel-header">
                                    <div class="as-panel-title"><i class="fa-solid fa-users-gear"></i> Admin Management</div>
                                    <span class="as-count-pill"><?= count($admins) ?> accounts</span>
                                </div>
                                <div class="as-panel-body">

                                    <!-- Tabs -->
                                    <div class="as-tabs">
                                        <?php if ($current_admin_role === 'super_admin'): ?>
                                        <div class="as-tab active" onclick="switchAsTab('tab-create',this)">
                                            <i class="fa-solid fa-user-plus"></i> Create Account
                                        </div>
                                        <?php endif; ?>
                                        <div class="as-tab <?= $current_admin_role !== 'super_admin' ? 'active' : '' ?>" onclick="switchAsTab('tab-list',this)">
                                            <i class="fa-solid fa-list"></i> All Admins
                                        </div>
                                    </div>

                                    <!-- Tab: Create -->
                                    <?php if ($current_admin_role === 'super_admin'): ?>
                                    <div class="as-tab-pane active" id="tab-create">
                                        <div class="as-create-section">
                                            <div class="as-create-title"><i class="fa-solid fa-user-plus"></i> New Admin Account</div>
                                            <form method="POST" action="" id="createAdminForm">
                                                <div class="as-form-section-label">Basic Details</div>
                                                <div class="as-form-grid cols-2">
                                                    <div class="as-form-group">
                                                        <label class="as-label"><i class="fa-solid fa-user" style="color:var(--as-accent);margin-right:4px;font-size:.72rem;"></i>Username <span class="req">*</span></label>
                                                        <input type="text" name="username" class="as-input" placeholder="e.g. brair" required>
                                                    </div>
                                                    <div class="as-form-group">
                                                        <label class="as-label"><i class="fa-solid fa-id-card" style="color:var(--as-accent);margin-right:4px;font-size:.72rem;"></i>Full Name <span class="req">*</span></label>
                                                        <input type="text" name="full_name" class="as-input" placeholder="e.g. brair Codz" required>
                                                    </div>
                                                </div>
                                                <div class="as-form-group">
                                                    <label class="as-label"><i class="fa-solid fa-envelope" style="color:var(--as-accent);margin-right:4px;font-size:.72rem;"></i>Email Address <span class="req">*</span></label>
                                                    <input type="email" name="email" class="as-input" placeholder="e.g. brair.mmu.ac.ug" required>
                                                </div>
                                                <div class="as-form-section-label">Security &amp; Permissions</div>
                                                <div class="as-form-grid cols-2">
                                                    <div class="as-form-group">
                                                        <label class="as-label"><i class="fa-solid fa-lock" style="color:var(--as-accent);margin-right:4px;font-size:.72rem;"></i>Password <span class="req">*</span></label>
                                                        <input type="password" name="password" class="as-input" placeholder="Strong password" required>
                                                    </div>
                                                    <div class="as-form-group">
                                                        <label class="as-label"><i class="fa-solid fa-shield-halved" style="color:var(--as-accent);margin-right:4px;font-size:.72rem;"></i>Role <span class="req">*</span></label>
                                                        <select name="role" class="as-input" required>
                                                            <option value="super_admin">Super Admin</option>
                                                            <option value="admin" selected>Admin</option>
                                                            <option value="moderator">Moderator</option>
                                                            <option value="viewer">Viewer</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <!-- Role guide -->
                                                <div style="background:var(--as-bg);border-radius:7px;padding:10px 14px;margin-bottom:14px;font-size:.74rem;color:var(--as-text-3);">
                                                    <div style="font-weight:600;color:var(--as-text-2);margin-bottom:6px;"><i class="fa-solid fa-circle-info" style="color:var(--as-accent)"></i> Role Permissions</div>
                                                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 12px;">
                                                        <div><span class="as-role-badge role-super" style="font-size:.65rem;">Super Admin</span> — Full access</div>
                                                        <div><span class="as-role-badge role-admin" style="font-size:.65rem;">Admin</span> — Manage content</div>
                                                        <div><span class="as-role-badge role-mod" style="font-size:.65rem;">Moderator</span> — Moderate only</div>
                                                        <div><span class="as-role-badge role-viewer" style="font-size:.65rem;">Viewer</span> — Read only</div>
                                                    </div>
                                                </div>
                                                <button type="submit" name="create" class="as-btn as-btn--accent as-btn--full">
                                                    <i class="fa-solid fa-user-plus"></i> Create Admin Account
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Tab: List -->
                                    <div class="as-tab-pane <?= $current_admin_role !== 'super_admin' ? 'active' : '' ?>" id="tab-list">
                                        <div class="as-table-wrap">
                                            <table class="as-table">
                                                <thead>
                                                    <tr>
                                                        <th>ID</th>
                                                        <th>Admin</th>
                                                        <th>Role</th>
                                                        <th>Last Login</th>
                                                        <th>Created</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($admins as $a):
                                                        $is_self   = ($a['admin_id'] == $current_admin_id);
                                                        $can_edit  = ($current_admin_role === 'super_admin' && !$is_self);
                                                        $can_delete= ($current_admin_role === 'super_admin' && !$is_self);
                                                        $rc = $role_config[$a['role']] ?? ['icon'=>'fa-user','class'=>'role-admin','label'=>ucfirst($a['role'])];
                                                        // Resolve avatar for each admin
                                                        $av_path = null;
                                                        foreach (['jpg','jpeg','png','webp'] as $ex) {
                                                            $candidate = "uploads/admin_{$a['admin_id']}_avatar.$ex";
                                                            if (file_exists(__DIR__ . '/' . $candidate)) { $av_path = $candidate; break; }
                                                        }
                                                    ?>
                                                    <tr class="<?= $is_self ? 'is-self' : '' ?>">
                                                        <td><span class="as-id-tag"><?= htmlspecialchars(format_admin_id($a['admin_id'])) ?></span></td>
                                                        <td>
                                                            <div style="display:flex;align-items:center;gap:10px;">
                                                                <?php if ($av_path): ?>
                                                                    <img src="<?= htmlspecialchars($av_path) ?>" class="as-table-avatar" alt="">
                                                                <?php else: ?>
                                                                    <div class="as-table-avatar-placeholder"><i class="fa-solid fa-user"></i></div>
                                                                <?php endif; ?>
                                                                <div>
                                                                    <div style="font-weight:600;font-size:.83rem;color:var(--as-text);">
                                                                        <?= htmlspecialchars($a['username']) ?>
                                                                        <?php if ($is_self): ?><span class="as-self-tag">You</span><?php endif; ?>
                                                                    </div>
                                                                    <div style="font-size:.72rem;color:var(--as-text-3);"><?= htmlspecialchars($a['email']) ?></div>
                                                                    <?php if ($a['full_name']): ?>
                                                                    <div style="font-size:.72rem;color:var(--as-text-4);"><?= htmlspecialchars($a['full_name']) ?></div>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <span class="as-role-badge <?= $rc['class'] ?>">
                                                                <i class="fa-solid <?= $rc['icon'] ?>"></i><?= $rc['label'] ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php if (!empty($a['last_login_at'])): ?>
                                                                <div class="as-date"><?= date('M j, Y', strtotime($a['last_login_at'])) ?></div>
                                                                <div style="font-size:.68rem;color:var(--as-text-4);font-family:var(--as-mono);"><?= date('H:i', strtotime($a['last_login_at'])) ?></div>
                                                            <?php else: ?>
                                                                <span style="color:var(--as-text-4);font-size:.75rem;">Never</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><div class="as-date"><?= !empty($a['created_at']) ? date('M j, Y', strtotime($a['created_at'])) : '—' ?></div></td>
                                                        <td>
                                                            <div style="display:flex;gap:5px;">
                                                                <?php if ($can_edit): ?>
                                                                <button class="as-btn as-btn--outline as-btn--sm"
                                                                    data-bs-toggle="modal"
                                                                    data-bs-target="#editModal-<?= $a['admin_id'] ?>">
                                                                    <i class="fa-solid fa-pen"></i> Edit
                                                                </button>
                                                                <?php endif; ?>
                                                                <?php if ($can_delete): ?>
                                                                <form method="POST" id="deleteAdminForm-<?= $a['admin_id'] ?>" style="display:inline">
                                                                    <input type="hidden" name="admin_id" value="<?= $a['admin_id'] ?>">
                                                                    <input type="hidden" name="delete" value="1">
                                                                </form>
                                                                <button class="as-btn as-btn--danger as-btn--sm"
                                                                    onclick="showConfirmModal({title:'Delete Admin',message:'Permanently delete <strong><?= htmlspecialchars($a['username']) ?></strong>? This cannot be undone.',confirmText:'DELETE',formId:'deleteAdminForm-<?= $a['admin_id'] ?>'})">
                                                                    <i class="fa-solid fa-trash"></i>
                                                                </button>
                                                                <?php endif; ?>
                                                                <?php if ($is_self): ?>
                                                                <span style="font-size:.72rem;color:var(--as-text-4);padding:4px 0;">Editing above ↖</span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>

                                </div><!-- /panel-body -->
                            </div><!-- /panel -->
                        </div><!-- /right col -->
                    </div><!-- /layout -->
                </div><!-- /as-page -->
            </div><!-- /sb2-2 -->
        </div>
    </div>

    <!-- ═══ Edit Modals ═══ -->
    <?php foreach ($admins as $a):
        if ($current_admin_role !== 'super_admin' || $a['admin_id'] == $current_admin_id) continue;
        $rc = $role_config[$a['role']] ?? ['icon'=>'fa-user','class'=>'role-admin','label'=>ucfirst($a['role'])];
    ?>
    <div class="modal fade" id="editModal-<?= $a['admin_id'] ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fa-solid fa-user-pen" style="color:var(--as-accent);margin-right:8px;"></i>
                        Edit: <?= htmlspecialchars($a['username']) ?>
                        <span class="as-role-badge <?= $rc['class'] ?>" style="margin-left:8px;font-size:.68rem;">
                            <i class="fa-solid <?= $rc['icon'] ?>"></i><?= $rc['label'] ?>
                        </span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="" enctype="multipart/form-data">
                        <input type="hidden" name="admin_id" value="<?= $a['admin_id'] ?>">
                        <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--as-text-4);margin-bottom:10px;">Account</div>
                        <div class="as-form-group">
                            <label class="as-label">Username</label>
                            <input type="text" name="username" class="as-input" value="<?= htmlspecialchars($a['username']) ?>" required>
                        </div>
                        <div class="as-form-group">
                            <label class="as-label">Email</label>
                            <input type="email" name="email" class="as-input" value="<?= htmlspecialchars($a['email']) ?>" required>
                        </div>
                        <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--as-text-4);margin:14px 0 10px;">Security</div>
                        <div class="as-form-group">
                            <label class="as-label">New Password <span class="as-hint" style="font-style:italic;">(blank = no change)</span></label>
                            <input type="password" name="password" class="as-input pw-strength-input" placeholder="Leave blank to keep current" oninput="checkPwStrength(this)">
                            <div class="pw-strength-bar"><div class="pw-strength-fill"></div></div>
                            <small class="pw-strength-text"></small>
                        </div>
                        <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--as-text-4);margin:14px 0 10px;">Profile Photo</div>
                        <div class="as-form-group">
                            <label class="as-label">Upload Image <span class="as-hint">(optional)</span></label>
                            <input type="file" name="avatar" class="as-input" accept="image/*" style="padding:5px 12px;">
                        </div>
                        <div class="modal-footer" style="padding:0;border:none;margin-top:16px;">
                            <button type="button" class="as-btn as-btn--outline" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="update" class="as-btn as-btn--primary">
                                <i class="fa-solid fa-floppy-disk"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <script src="js/main.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
        crossorigin="anonymous"></script>
    <script src="js/custom.js"></script>

    <?php include 'includes/global_toasts.php'; ?>
    <?php include 'includes/confirm_modal.php'; ?>

    <script>
    /* ── Tabs ── */
    function switchAsTab(tabId, el) {
        document.querySelectorAll('.as-tab-pane').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.as-tab').forEach(t => t.classList.remove('active'));
        document.getElementById(tabId)?.classList.add('active');
        if (el) el.classList.add('active');
    }

    /* ── Password Strength ── */
    function checkPwStrength(input) {
        var pw = input.value, score = 0;
        if (pw.length >= 8) score++;
        if (pw.length >= 12) score++;
        if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) score++;
        if (/[0-9]/.test(pw)) score++;
        if (/[^A-Za-z0-9]/.test(pw)) score++;
        var colors = ['#ef4444','#f59e0b','#eab308','#22c55e','#10b981'];
        var labels = ['Very Weak','Weak','Fair','Strong','Very Strong'];
        var container = input.closest('.as-form-group') || input.closest('.mb-3');
        var fill = container?.querySelector('.pw-strength-fill');
        var text = container?.querySelector('.pw-strength-text');
        var idx  = Math.min(score, 4);
        if (!fill || !text) return;
        if (pw.length === 0) { fill.style.width='0'; text.textContent=''; return; }
        fill.style.width     = ((idx+1)*20) + '%';
        fill.style.background= colors[idx];
        text.textContent     = labels[idx];
        text.style.color     = colors[idx];
    }

    /* ── Avatar preview ── */
    function previewAvatar(input) {
        if (!input.files || !input.files[0]) return;
        const reader = new FileReader();
        reader.onload = e => {
            const img = document.querySelector('.as-profile-avatar');
            if (img) img.src = e.target.result;
        };
        reader.readAsDataURL(input.files[0]);
    }

    /* ── Notifications ── */
    function updateNotificationCount() {
        fetch('fetch_queries.php').then(r=>r.json()).then(d=>{
            const el = document.getElementById('not-yet-count');
            if (el) { el.textContent = d.not_yet_count; el.style.display = d.not_yet_count > 0 ? 'inline' : 'none'; }
        }).catch(()=>{});
    }
    updateNotificationCount(); setInterval(updateNotificationCount, 60000);

    /* ── Create form client validation ── */
    const createForm = document.getElementById('createAdminForm');
    if (createForm) {
        createForm.onsubmit = function(e) {
            const vals = ['username','email','password','full_name'].map(n => createForm.querySelector('[name="'+n+'"]')?.value?.trim());
            if (vals.some(v => !v)) { alert('All fields are required.'); e.preventDefault(); return; }
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(vals[1])) { alert('Invalid email format.'); e.preventDefault(); }
        };
    }
    </script>
</body>
</html>