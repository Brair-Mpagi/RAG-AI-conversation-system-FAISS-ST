<?php
session_start();
header('Content-Type: application/json');

// Ensure user is logged in
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(["status" => "error", "detail" => "Unauthorized: Admin session required"]);
    exit;
}

// Secret key matching the Python backend
$secret = 'lE4_40K7T6exwAo9fV8BrmKNe1R3VyDE0z9TS6eusQk';

// Generate a valid JWT token
$header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
$payload = json_encode(['sub' => 'admin_proxy', 'role' => 'admin', 'exp' => time() + 300]);

$base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
$base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));

$signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret, true);
$base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

$jwt = $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;

// Proxy request to the Python backend
$action = $_GET['action'] ?? '';
$url = '';

$method = 'POST';
$body = file_get_contents('php://input');

if ($action === 'reindex_kb') {
    $url = 'http://127.0.0.1:8000/api/v1/admin/reindex_kb';
} elseif ($action === 'enrich_kb') {
    $url = 'http://127.0.0.1:8000/api/v1/admin/enrich_kb';
} elseif ($action === 'enrich_all_kb') {
    $url = 'http://127.0.0.1:8000/api/v1/admin/enrich_all_kb';
} elseif ($action === 'enrich_and_reindex_kb') {
    $url = 'http://127.0.0.1:8000/api/v1/admin/enrich_and_reindex_kb';
} elseif ($action === 'enrich_kb_status') {
    $url = 'http://127.0.0.1:8000/api/v1/admin/enrich_kb/status';
    $method = 'GET';
} elseif ($action === 'pipeline_start') {
    $url = 'http://127.0.0.1:8000/api/v1/admin/pipeline/start';
} elseif ($action === 'pipeline_status') {
    $url = 'http://127.0.0.1:8000/api/v1/admin/pipeline/status';
    $method = 'GET';
} elseif ($action === 'pipeline_cancel') {
    $url = 'http://127.0.0.1:8000/api/v1/admin/pipeline/cancel';
} elseif ($action === 'reload_config') {
    $url = 'http://127.0.0.1:8000/api/v1/admin/reload-config';
} elseif ($action === 'import_enriched') {
    $url = 'http://127.0.0.1:8000/api/v1/admin/import_enriched';
    $preview = $_GET['preview'] ?? 'false';
    $url .= '?preview=' . urlencode($preview);
} else {
    http_response_code(400);
    echo json_encode(["status" => "error", "detail" => "Invalid action"]);
    exit;
}

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$headers = [
    'Authorization: Bearer ' . $jwt
];
if ($action !== 'import_enriched') {
    $headers[] = 'Content-Type: application/json';
}
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

if ($method === 'GET') {
    curl_setopt($ch, CURLOPT_HTTPGET, true);
} else {
    curl_setopt($ch, CURLOPT_POST, true);
    if ($action === 'import_enriched') {
        $postFields = [];
        if (!empty($_FILES)) {
            foreach ($_FILES as $key => $file) {
                // If it's a file upload array/dictionary, find the main uploaded file
                $postFields[$key] = new CURLFile($file['tmp_name'], $file['type'], $file['name']);
            }
        }
        foreach ($_POST as $key => $val) {
            $postFields[$key] = $val;
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    } else {
        if ($body !== false && $body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
    }
}

$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($response === false) {
    http_response_code(500);
    echo json_encode(["status" => "error", "detail" => "cURL error: " . curl_error($ch)]);
} else {
    http_response_code($httpcode);
    echo $response;
}
curl_close($ch);

