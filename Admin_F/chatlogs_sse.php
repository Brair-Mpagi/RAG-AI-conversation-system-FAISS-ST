<?php
require_once 'db.php';
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

$lastId = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;
$startTime = time();
$maxDuration = 30; // seconds, to prevent runaway

while (true) {
    $result = $conn->query("SELECT message_id, session_id, user_message, bot_response, created_at FROM chat_messages WHERE message_id > $lastId ORDER BY message_id DESC LIMIT 1");
    if ($row = $result->fetch_assoc()) {
        $lastId = $row['message_id'];
        echo "data: " . json_encode($row) . "\n\n";
        ob_flush();
        flush();
    }
    if ((time() - $startTime) > $maxDuration) break;
    sleep(1);
}
exit;
?>
