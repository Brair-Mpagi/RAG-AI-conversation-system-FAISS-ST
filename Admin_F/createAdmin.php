<?php
require_once 'db.php';

// ===== HARD-CODED ADMIN DETAILS =====
$username   = 'brair';
$email      = 'brair@gmail.com';
$password   = 'brair'; // CHANGE THIS
$full_name  = 'System Administrator';
$role       = 'super_admin';
$phone      = '0700000000';
$is_active  = 1;

// Hash password securely
$password_hash = password_hash($password, PASSWORD_DEFAULT);

// Check if admin already exists
$check = $conn->prepare("SELECT admin_id FROM admins WHERE username = ? OR email = ?");
$check->bind_param("ss", $username, $email);
$check->execute();
$check->store_result();

if ($check->num_rows > 0) {
    die("❌ Admin already exists. Delete this file.");
}

// Insert admin
$stmt = $conn->prepare("
    INSERT INTO admins 
    (username, email, password_hash, full_name, role, phone_number, is_active)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");

$stmt->bind_param(
    "ssssssi",
    $username,
    $email,
    $password_hash,
    $full_name,
    $role,
    $phone,
    $is_active
);

if ($stmt->execute()) {
    echo "✅ Admin account created successfully.<br>";
    echo "Username: <b>$username</b><br>";
    echo "Password: <b>$password</b><br>";
    echo "<br>⚠️ DELETE THIS FILE IMMEDIATELY!";
} else {
    echo "❌ Error: " . $stmt->error;
}
