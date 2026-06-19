<?php
// Quick syntax check for web_scraper.php
// Visit: http://localhost/Admin-F/check_syntax.php
// DELETE after use.

$file = __DIR__ . '/web_scraper.php';
$output = shell_exec("php -l " . escapeshellarg($file) . " 2>&1");
echo "<pre style='font-family:monospace;background:#111;color:#0f0;padding:20px;font-size:14px'>";
echo htmlspecialchars($output ?: "No output from php -l");
echo "</pre>";

// Also check last lines of apache error log
$log = shell_exec("tail -30 /var/log/apache2/error.log 2>/dev/null || tail -30 /var/log/nginx/error.log 2>/dev/null || echo 'No log found'");
echo "<pre style='font-family:monospace;background:#111;color:#fa0;padding:20px;font-size:13px'>";
echo htmlspecialchars($log ?: "Empty log");
echo "</pre>";
