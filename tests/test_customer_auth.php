<?php
require_once __DIR__ . '/../database.php';

$testEmail = "tester_" . time() . "@example.com";
$testPass = "secret123";
$hash = password_hash($testPass, PASSWORD_DEFAULT);

$stmt = $conn->prepare("INSERT INTO customers (name, email, password, contact, account_status) VALUES ('Test User', ?, ?, '09123456789', 'Pending')");
$stmt->bind_param("ss", $testEmail, $hash);
$stmt->execute();
$userId = $conn->insert_id;

// Verify password
$stmtCheck = $conn->prepare("SELECT id, password, account_status FROM customers WHERE email = ?");
$stmtCheck->bind_param("s", $testEmail);
$stmtCheck->execute();
$cust = $stmtCheck->get_result()->fetch_assoc();

assert($cust !== null, "customer should exist");
assert(password_verify($testPass, $cust['password']), "password should verify");
assert($cust['account_status'] === 'Pending', "new customer should be Pending");

// Cleanup
$conn->query("DELETE FROM customers WHERE id = $userId");

echo "PASS: Customer auth test successful\n";
