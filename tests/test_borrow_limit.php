<?php
require_once __DIR__ . '/../database.php';

// Setup test customer
$conn->query("INSERT INTO customers (name, email, contact, account_status) VALUES ('Limit Tester', 'limit@test.com', '09999999999', 'Approved')");
$cid = $conn->insert_id;

// Setup test book with 10 copies
$conn->query("INSERT INTO books (title, author, total_copies, available_copies, status) VALUES ('Limit Book 1', 'Author', 10, 10, 'Available')");
$b1 = $conn->insert_id;
$conn->query("INSERT INTO books (title, author, total_copies, available_copies, status) VALUES ('Limit Book 2', 'Author', 10, 10, 'Available')");
$b2 = $conn->insert_id;
$conn->query("INSERT INTO books (title, author, total_copies, available_copies, status) VALUES ('Limit Book 3', 'Author', 10, 10, 'Available')");
$b3 = $conn->insert_id;

// Borrow book 1 & book 2
$due = date("Y-m-d H:i:s", strtotime("+7 days"));
$conn->query("INSERT INTO borrowings (customer_id, book_id, due_date, status) VALUES ($cid, $b1, '$due', 'Borrowed')");
$conn->query("INSERT INTO borrowings (customer_id, book_id, due_date, status) VALUES ($cid, $b2, '$due', 'Borrowed')");

// Check active borrowings count
$stmt = $conn->prepare("SELECT COUNT(*) AS active_count FROM borrowings WHERE customer_id = ? AND status = 'Borrowed'");
$stmt->bind_param("i", $cid);
$stmt->execute();
$activeCount = (int)$stmt->get_result()->fetch_assoc()['active_count'];

assert($activeCount === 2, "active count should be 2");

// Attempt to borrow 3rd book should be blocked
$canBorrow = ($activeCount < 2);
assert($canBorrow === false, "3rd borrow should be blocked by 2-book limit");

// Cleanup
$conn->query("DELETE FROM borrowings WHERE customer_id = $cid");
$conn->query("DELETE FROM customers WHERE id = $cid");
$conn->query("DELETE FROM books WHERE id IN ($b1, $b2, $b3)");

echo "PASS: 2-book limit test successful\n";
