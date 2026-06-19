<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: ./admin-login.php');
    exit();
}
header('Location: index.php');
exit();
require_once 'db.php';
if (!$conn || $conn->connect_error) {
    die('Connection failed: ' . ($conn ? $conn->connect_error : 'No connection object.'));
}

$notice = null;
$error = null;

function hash_password($plain)
{
    return password_hash($plain, PASSWORD_BCRYPT);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create_user') {
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $user_type = $_POST['user_type'] ?? 'registered';
            $account_status = $_POST['account_status'] ?? 'active';
            $password = trim($_POST['password'] ?? '');
            if ($name === '' || $email === '' || $password === '')
                throw new Exception('Name, email and password are required.');
            $pwd = hash_password($password);
            $stmt = $conn->prepare('INSERT INTO users (name, email, password_hash, user_type, account_status, is_active) VALUES (?,?,?,?,?,TRUE)');
            $stmt->bind_param('sssss', $name, $email, $pwd, $user_type, $account_status);
            $stmt->execute();
            $notice = 'User created.';
        } elseif ($action === 'update_user') {
            $user_id = (int) $_POST['user_id'];
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $user_type = $_POST['user_type'] ?? 'registered';
            $account_status = $_POST['account_status'] ?? 'active';
            $stmt = $conn->prepare('UPDATE users SET name=?, email=?, user_type=?, account_status=? WHERE user_id=?');
            $stmt->bind_param('ssssi', $name, $email, $user_type, $account_status, $user_id);
            $stmt->execute();
            $notice = 'User updated.';
        } elseif ($action === 'toggle_active') {
            $user_id = (int) $_POST['user_id'];
            $is_active = (int) $_POST['new_state'];
            $stmt = $conn->prepare('UPDATE users SET is_active=?, account_status=? WHERE user_id=?');
            $status = $is_active ? 'active' : 'inactive';
            $stmt->bind_param('isi', $is_active, $status, $user_id);
            $stmt->execute();
            $notice = $is_active ? 'User activated.' : 'User deactivated.';
        } elseif ($action === 'reset_password') {
            $user_id = (int) $_POST['user_id'];
            $new_password = trim($_POST['new_password'] ?? '');
            if (strlen($new_password) < 8)
                throw new Exception('Password must be at least 8 characters.');
            $pwd = hash_password($new_password);
            $stmt = $conn->prepare('UPDATE users SET password_hash=? WHERE user_id=?');
            $stmt->bind_param('si', $pwd, $user_id);
            $stmt->execute();
            $notice = 'Password reset.';
        } elseif ($action === 'delete_user') {
            $user_id = (int) $_POST['user_id'];
            $stmt = $conn->prepare('DELETE FROM users WHERE user_id=?');
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $notice = 'User deleted.';
        }
    } catch (Exception $ex) {
        $error = $ex->getMessage();
    }
}

// Filters
$q = trim($_GET['q'] ?? '');
$period = $_GET['period'] ?? '';
$selected_user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
$where = 'WHERE 1=1';
if ($q !== '') {
    $qs = $conn->real_escape_string($q);
    $where .= " AND (name LIKE '%$qs%' OR email LIKE '%$qs%')";
}
if ($period === 'day') {
    $where .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)";
}
if ($period === 'week') {
    $where .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)";
}
if ($period === 'month') {
    $where .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";
}

$users = [];
$res = $conn->query("SELECT * FROM users $where ORDER BY created_at DESC LIMIT 300");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $users[] = $r;
    }
}

$sessions = [];
if ($selected_user_id > 0) {
    $res = $conn->query("SELECT * FROM user_sessions WHERE user_id=" . (int) $selected_user_id . " ORDER BY start_time DESC LIMIT 100");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $sessions[] = $r;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>User Management</title>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="shortcut icon" href="images/mmu_logo_- no bg.png" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/style.css?v=1775081173">
    <link href="css/admin.css" rel="stylesheet" />
    
</head>

<body>
    <div class="container-fluid sb2">
        <div class="row">
            <?php include 'includes/sidebar.php'; ?>
            <div class="sb2-2 col-md-9">
                

                <div class="db-2">
                    <?php if ($notice): ?>
                        <div class="table-container" style="background:#ecfeff;color:#065f46">
                            <?php echo htmlspecialchars($notice); ?>
                        </div><?php endif; ?>
                    <?php if ($error): ?>
                        <div class="table-container" style="background:#fee2e2;color:#991b1b">
                            <?php echo htmlspecialchars($error); ?>
                        </div><?php endif; ?>

                    <div class="table-container">
                        <h2>Create User</h2>
                        <form method="post"
                            style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr 1fr 160px;gap:10px">
                            <input type="hidden" name="action" value="create_user">
                            <input type="text" name="name" placeholder="Name" required>
                            <input type="email" name="email" placeholder="Email" required>
                            <select name="user_type">
                                <option value="registered">Registered</option>
                                <option value="student">Student</option>
                                <option value="staff">Staff</option>
                                <option value="guest">Guest</option>
                            </select>
                            <select name="account_status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="suspended">Suspended</option>
                            </select>
                            <input type="password" name="password" placeholder="Password (min 8)" required>
                            <button class="btn btn-primary" type="submit">Create</button>
                        </form>
                    </div>

                    <div class="table-container">
                        <h2>Users</h2>
                        <form method="get" style="display:flex;gap:10px;margin-bottom:10px">
                            <input type="text" name="q" placeholder="Search name/email"
                                value="<?php echo htmlspecialchars($q); ?>">
                            <select name="period">
                                <option value="">All time</option>
                                <option value="day" <?php echo $period === 'day' ? 'selected' : ''; ?>>Last 24h</option>
                                <option value="week" <?php echo $period === 'week' ? 'selected' : ''; ?>>Last week
                                </option>
                                <option value="month" <?php echo $period === 'month' ? 'selected' : ''; ?>>Last month
                                </option>
                            </select>
                            <button class="btn btn-secondary" type="submit">Filter</button>
                            <a class="btn btn-secondary" href="export.php?table=users">Export</a>
                        </form>
                        <table>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>User Type</th>
                                <th>Status</th>
                                <th>Active</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                            <?php foreach ($users as $u): ?>
                                <tr>
                                    <td><?php echo (int) $u['user_id']; ?></td>
                                    <td><?php echo htmlspecialchars($u['name']); ?></td>
                                    <td><?php echo htmlspecialchars($u['email']); ?></td>
                                    <td><?php echo htmlspecialchars($u['user_type']); ?></td>
                                    <td><?php echo htmlspecialchars($u['account_status']); ?></td>
                                    <td><?php echo $u['is_active'] ? 'Yes' : 'No'; ?></td>
                                    <td><?php echo htmlspecialchars($u['created_at']); ?></td>
                                    <td>
                                        <form method="post"
                                            style="display:grid;grid-template-columns:160px 160px 110px 110px 1fr;gap:6px;align-items:center">
                                            <input type="hidden" name="action" value="update_user">
                                            <input type="hidden" name="user_id" value="<?php echo (int) $u['user_id']; ?>">
                                            <input type="text" name="name"
                                                value="<?php echo htmlspecialchars($u['name']); ?>">
                                            <input type="email" name="email"
                                                value="<?php echo htmlspecialchars($u['email']); ?>">
                                            <select name="user_type">
                                                <option value="registered" <?php echo $u['user_type'] === 'registered' ? 'selected' : ''; ?>>Registered</option>
                                                <option value="student" <?php echo $u['user_type'] === 'student' ? 'selected' : ''; ?>>Student</option>
                                                <option value="staff" <?php echo $u['user_type'] === 'staff' ? 'selected' : ''; ?>>Staff</option>
                                                <option value="guest" <?php echo $u['user_type'] === 'guest' ? 'selected' : ''; ?>>Guest</option>
                                            </select>
                                            <select name="account_status">
                                                <option value="active" <?php echo $u['account_status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                                <option value="inactive" <?php echo $u['account_status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                                <option value="suspended" <?php echo $u['account_status'] === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                            </select>
                                            <button class="btn btn-secondary" type="submit">Save</button>
                                        </form>
                                        <form method="post" style="display:inline">
                                            <input type="hidden" name="action" value="toggle_active">
                                            <input type="hidden" name="user_id" value="<?php echo (int) $u['user_id']; ?>">
                                            <input type="hidden" name="new_state"
                                                value="<?php echo $u['is_active'] ? 0 : 1; ?>">
                                            <button class="btn btn-secondary"
                                                type="submit"><?php echo $u['is_active'] ? 'Deactivate' : 'Activate'; ?></button>
                                        </form>
                                        <form method="post" style="display:inline"
                                            onsubmit="return confirm('Delete user? This cannot be undone.')">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="user_id" value="<?php echo (int) $u['user_id']; ?>">
                                            <button class="btn btn-danger" type="submit">Delete</button>
                                        </form>
                                        <form method="post"
                                            style="display:grid;grid-template-columns:200px 110px;gap:6px;margin-top:6px">
                                            <input type="hidden" name="action" value="reset_password">
                                            <input type="hidden" name="user_id" value="<?php echo (int) $u['user_id']; ?>">
                                            <input type="password" name="new_password" placeholder="New password (min 8)">
                                            <button class="btn btn-primary" type="submit">Set Password</button>
                                        </form>
                                        <a class="btn btn-secondary"
                                            href="user_mgmt.php?user_id=<?php echo (int) $u['user_id']; ?>">View
                                            Sessions</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>

                    <?php if ($selected_user_id > 0): ?>
                        <div class="table-container">
                            <h2>User Sessions (User #<?php echo $selected_user_id; ?>)</h2>
                            <a class="btn btn-secondary" href="export.php?table=user_sessions">Export Sessions</a>
                            <table>
                                <tr>
                                    <th>ID</th>
                                    <th>Interface</th>
                                    <th>Access Mode</th>
                                    <th>Start</th>
                                    <th>End</th>
                                    <th>Duration(s)</th>
                                    <th>IP</th>
                                    <th>Msgs Sent</th>
                                    <th>Msgs Recv</th>
                                </tr>
                                <?php foreach ($sessions as $s): ?>
                                    <tr>
                                        <td><?php echo (int) $s['session_id']; ?></td>
                                        <td><?php echo htmlspecialchars($s['interface_type']); ?></td>
                                        <td><?php echo htmlspecialchars($s['access_mode']); ?></td>
                                        <td><?php echo htmlspecialchars($s['start_time']); ?></td>
                                        <td><?php echo htmlspecialchars($s['end_time']); ?></td>
                                        <td><?php echo (int) $s['duration_seconds']; ?></td>
                                        <td><?php echo htmlspecialchars($s['ip_address']); ?></td>
                                        <td><?php echo (int) $s['total_messages_sent']; ?></td>
                                        <td><?php echo (int) $s['total_messages_received']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>
    <script src="js/main.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
        crossorigin="anonymous"></script>
    <script src="js/custom.js"></script>
</body>

</html>