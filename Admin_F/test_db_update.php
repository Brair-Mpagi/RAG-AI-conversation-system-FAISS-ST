<?php
require_once 'db.php';
$query_id = 9; // use an existing ID
$resolved_at = date('Y-m-d H:i:s');
$resolved_by = 0;
$admin_response = "Test manual update";
$sql_update = "UPDATE user_queries SET status = 'resolved', admin_response = ?, resolved_at = ?, resolved_by = ? WHERE query_id = ?";
$stmt = $conn->prepare($sql_update);
if (!$stmt) {
    echo "Prepare failed: " . $conn->error;
    exit;
}
$stmt->bind_param("ssii", $admin_response, $resolved_at, $resolved_by, $query_id);
$result = $stmt->execute();
if (!$result) {
    echo "Execute failed: " . $stmt->error;
} else {
    echo "Execute SUCCESS! Rows affected: " . $stmt->affected_rows;
}
?>
