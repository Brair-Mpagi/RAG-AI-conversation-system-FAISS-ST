<?php
require_once 'db.php';
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

$lastRank = isset($_GET['last_rank']) ? (int)$_GET['last_rank'] : 0;
$startTime = time();
$maxDuration = 30; // seconds, to prevent runaway

while (true) {
    $result = $conn->query("SELECT * FROM faq_frequency WHERE rank > $lastRank ORDER BY rank DESC LIMIT 1");
    if ($row = $result->fetch_assoc()) {
        $lastRank = $row['rank'];
        echo "data: " . json_encode($row) . "\n\n";
        ob_flush();
        flush();
    }
    if ((time() - $startTime) > $maxDuration) break;
    sleep(1);
}
exit;
?>
