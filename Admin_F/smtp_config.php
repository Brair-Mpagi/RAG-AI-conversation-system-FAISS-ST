<?php
// SMTP Configuration for Password Reset Emails
// ──────────────────────────────────────────────
// Loads credentials from .env file for security.
//
// For Gmail:
//   1. Enable 2-Factor Authentication on your Google account
//   2. Go to https://myaccount.google.com/apppasswords
//   3. Generate an "App Password" for "Mail"
//   4. Paste the 16-character password in .env as SMTP_PASS
//   5. Set SMTP_USER to your full Gmail address in .env
//

require_once __DIR__ . '/env_loader.php';

// Export SMTP configuration variables (no PHPMailer instance created here)
$smtp_host      = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
$smtp_port      = (int)(getenv('SMTP_PORT') ?: 587);
$smtp_username  = getenv('SMTP_USER') ?: '';
$smtp_password  = getenv('SMTP_PASS') ?: '';
$smtp_from      = getenv('SMTP_FROM') ?: $smtp_username;
$smtp_from_name = getenv('SMTP_FROM_NAME') ?: 'MMU Admin';
$app_url        = getenv('APP_URL') ?: 'http://localhost/Admin-F';
?>