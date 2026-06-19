<?php
session_start();
require 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Always return JSON
header('Content-Type: application/json');

// Load SMTP configuration from .env
require_once __DIR__ . '/smtp_config.php';

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $recipient_name  = isset($_POST['recipient_name'])  ? trim($_POST['recipient_name'])  : '';
    $recipient_email = isset($_POST['recipient_email']) ? trim($_POST['recipient_email']) : '';
    $subject         = isset($_POST['subject'])         ? trim($_POST['subject'])         : '';
    $message         = isset($_POST['message'])         ? trim($_POST['message'])         : '';
    $query_id        = isset($_POST['query_id'])        ? (int)$_POST['query_id']         : 0;

    if (!filter_var($recipient_email, FILTER_VALIDATE_EMAIL)) {
        $response['message'] = 'Invalid recipient email address: "' . htmlspecialchars($recipient_email) . '". Please ensure the user has a valid email.';
        echo json_encode($response);
        exit;
    }
    if (!filter_var($smtp_from, FILTER_VALIDATE_EMAIL)) {
        $response['message'] = 'Server SMTP from-address is not configured correctly.';
        echo json_encode($response);
        exit;
    }
    if (empty($recipient_name) || empty($subject) || empty($message)) {
        $response['message'] = 'All fields are required';
        echo json_encode($response);
        exit;
    }

    require_once 'db.php';
    if (!$conn || $conn->connect_error) {
        $response['message'] = 'Database connection failed.';
        echo json_encode($response);
        exit;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->SMTPDebug = 0;
        $mail->isSMTP();
        $mail->Host       = $smtp_host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtp_username;
        $mail->Password   = $smtp_password;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $smtp_port;

        $mail->setFrom($smtp_from, $smtp_from_name);
        $mail->addAddress($recipient_email, $recipient_name);
        $mail->addReplyTo($smtp_from, $smtp_from_name);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = "
            <html>
            <head><title>" . htmlspecialchars($subject) . "</title></head>
            <body>
                <p>Hello " . htmlspecialchars($recipient_name) . ",</p>
                <div>" . nl2br(htmlspecialchars($message)) . "</div>
                <p>Best regards,<br>" . htmlspecialchars($smtp_from_name) . "</p>
            </body>
            </html>
        ";

        $mail->send();

        // Auto-resolve query
        if ($query_id > 0) {
            $resolved_at = date('Y-m-d H:i:s');
            $admin_response = "Sent email reply regarding: " . $subject;
            $sql_update = "UPDATE user_queries SET status = 'resolved', admin_response = ?, resolved_at = ? WHERE query_id = ?";
            $stmt = $conn->prepare($sql_update);
            if ($stmt) {
                $stmt->bind_param("ssi", $admin_response, $resolved_at, $query_id);
                $stmt->execute();
            }
        }

        $response['success'] = true;
        $response['message'] = 'Email sent successfully';
    } catch (Exception $e) {
        $response['message'] = 'Failed to send email: ' . $mail->ErrorInfo;
    }
} else {
    $response['message'] = 'Invalid request method. Expected POST, got ' . $_SERVER['REQUEST_METHOD'] . '.';
}

echo json_encode($response);
?>