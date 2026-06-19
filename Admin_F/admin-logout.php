<?php
// Start the session
session_start();

// Clean remember token from database if present
if (isset($_SESSION['admin_id']) && isset($_COOKIE['admin_remember_token'])) {
    require_once 'db.php';
    $del = $conn->prepare("DELETE FROM admin_remember_tokens WHERE admin_id = ?");
    if ($del) {
        $del->bind_param('i', $_SESSION['admin_id']);
        $del->execute();
        $del->close();
    }
}

// Clear all session variables
$_SESSION = array();

// Delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Clear remember-me cookie if present
if (isset($_COOKIE['admin_remember_token'])) {
    setcookie('admin_remember_token', '', time() - 42000, '/', '', false, true);
}

// Destroy the session
session_destroy();

// Prevent caching of protected pages (back-button protection)
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

// Redirect to the login page
header("Location: admin-login.php");
exit();
?>