<?php
// db.php - Centralized database connection for the project
// Loads credentials from .env file for security

require_once __DIR__ . '/env_loader.php';

// Database configuration from environment
$host     = getenv('DB_HOST') ?: 'localhost';
$dbname   = getenv('DB_NAME') ?: 'campus_ai_db';
$username = getenv('DB_USER') ?: 'campus_ai_user';
$password = getenv('DB_PASS') ?: 'root';

// Create MySQLi connection
$conn = new mysqli($host, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to UTF-8
$conn->set_charset("utf8mb4");

// API helpers for future use (when refactoring to use backend API)
$api_base_url = "http://localhost:8000/api";

function api_get($endpoint) {
    global $api_base_url;
    $url = $api_base_url . $endpoint;
    $response = @file_get_contents($url);
    return $response ? json_decode($response, true) : null;
}

function api_post($endpoint, $data) {
    global $api_base_url;
    $url = $api_base_url . $endpoint;
    $options = [
        'http' => [
            'header'  => "Content-type: application/json\r\n",
            'method'  => 'POST',
            'content' => json_encode($data),
        ]
    ];
    $context  = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);
    return $result ? json_decode($result, true) : null;
}

// Format Admin ID to MMU-chtbt-00X
function format_admin_id($id) {
    if (empty($id)) return 'Unknown';
    return sprintf("MMU-chtbt-%03d", (int)$id);
}
?>
