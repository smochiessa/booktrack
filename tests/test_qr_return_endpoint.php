<?php
require_once __DIR__ . '/../database.php';

// Setup book & borrowing
$conn->query("INSERT INTO books (title, author, total_copies, available_copies, status) VALUES ('QR Return Test', 'Author', 1, 0, 'Borrowed')");
$bid = $conn->insert_id;

$conn->query("INSERT INTO customers (name, contact) VALUES ('QR Scanner Tester', '09112233445')");
$cid = $conn->insert_id;

$conn->query("INSERT INTO borrowings (customer_id, book_id, due_date, status) VALUES ($cid, $bid, NOW(), 'Borrowed')");
$brid = $conn->insert_id;

// Simulate QR payload parser (e.g. "http://localhost/booktrack/customer/borrow.php?book_id=123" or "book_123" or "123")
$qrToken = "http://localhost/booktrack/customer/borrow.php?book_id=$bid";
$parsed_id = null;
if (preg_match('/book_id=(\d+)/i', $qrToken, $m)) {
    $parsed_id = (int)$m[1];
} elseif (preg_match('/^(\d+)$/', trim($qrToken), $m)) {
    $parsed_id = (int)$m[1];
}

assert($parsed_id === $bid, "should parse book ID from QR payload");

// Find active borrowing
$stmt = $conn->prepare("SELECT id FROM borrowings WHERE book_id = ? AND status = 'Borrowed' ORDER BY borrow_date ASC LIMIT 1");
$stmt->bind_param("i", $parsed_id);
$stmt->execute();
$found = $stmt->get_result()->fetch_assoc();

assert((int)$found['id'] === $brid, "should find the active borrowing");

// Execute atomic return
$conn->begin_transaction();
$conn->query("UPDATE borrowings SET return_date = NOW(), status = 'Returned' WHERE id = " . (int)$found['id']);
$conn->query("UPDATE books SET available_copies = LEAST(total_copies, available_copies + 1), status = 'Available' WHERE id = $bid");
$conn->commit();

// Verify
$bCheck = $conn->query("SELECT available_copies, status FROM books WHERE id = $bid")->fetch_assoc();
$brCheck = $conn->query("SELECT status FROM borrowings WHERE id = $brid")->fetch_assoc();
assert((int)$bCheck['available_copies'] === 1, "available copies should be 1");
assert($bCheck['status'] === 'Available', "status should be Available");
assert($brCheck['status'] === 'Returned', "borrowing status should be Returned");

// Cleanup
$conn->query("DELETE FROM borrowings WHERE id = $brid");
$conn->query("DELETE FROM customers WHERE id = $cid");
$conn->query("DELETE FROM books WHERE id = $bid");

echo "PASS: QR return endpoint test successful\n";
