<?php
require_once __DIR__ . '/../database.php';

$conn->query("INSERT INTO customers (name, email, contact, account_status) VALUES ('Approval Test', 'app@test.com', '09111111111', 'Pending')");
$cid = $conn->insert_id;

// Approve customer
$upd = $conn->prepare("UPDATE customers SET account_status = 'Approved' WHERE id = ?");
$upd->bind_param("i", $cid);
$upd->execute();

$res = $conn->query("SELECT account_status FROM customers WHERE id = $cid")->fetch_assoc();
assert($res['account_status'] === 'Approved', "status should be Approved");

// Reject customer
$upd2 = $conn->prepare("UPDATE customers SET account_status = 'Rejected' WHERE id = ?");
$upd2->bind_param("i", $cid);
$upd2->execute();

$res2 = $conn->query("SELECT account_status FROM customers WHERE id = $cid")->fetch_assoc();
assert($res2['account_status'] === 'Rejected', "status should be Rejected");

// Cleanup
$conn->query("DELETE FROM customers WHERE id = $cid");

echo "PASS: Approval state transition test successful\n";
