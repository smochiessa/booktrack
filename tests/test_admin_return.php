<?php
require_once __DIR__ . '/../database.php';

// Setup book with 2 total, 1 available
$conn->query("INSERT INTO books (title, author, total_copies, available_copies, status) VALUES ('Return Test Book', 'Author', 2, 1, 'Available')");
$bid = $conn->insert_id;

$conn->query("INSERT INTO customers (name, contact) VALUES ('Return Tester', '09123456780')");
$cid = $conn->insert_id;

$due = date("Y-m-d H:i:s", strtotime("+7 days"));
$conn->query("INSERT INTO borrowings (customer_id, book_id, due_date, status) VALUES ($cid, $bid, '$due', 'Borrowed')");
$brid = $conn->insert_id;

// Execute return transaction
$conn->begin_transaction();
$conn->query("UPDATE borrowings SET return_date = NOW(), status = 'Returned' WHERE id = $brid AND status = 'Borrowed'");
$conn->query("UPDATE books SET available_copies = LEAST(total_copies, available_copies + 1), status = 'Available' WHERE id = $bid");
$conn->commit();

// Verify
$bRes = $conn->query("SELECT available_copies, status FROM books WHERE id = $bid")->fetch_assoc();
$brRes = $conn->query("SELECT status, return_date FROM borrowings WHERE id = $brid")->fetch_assoc();

assert((int)$bRes['available_copies'] === 2, "available copies should be restored to 2");
assert($brRes['status'] === 'Returned', "borrowing status should be Returned");
assert(!empty($brRes['return_date']), "return_date should be populated");

// Cleanup
$conn->query("DELETE FROM borrowings WHERE id = $brid");
$conn->query("DELETE FROM customers WHERE id = $cid");
$conn->query("DELETE FROM books WHERE id = $bid");

echo "PASS: Admin return test successful\n";
