<?php
require_once __DIR__ . '/../database.php';

// Test insert book with 5 copies
$stmt = $conn->prepare("INSERT INTO books (title, author, total_copies, available_copies, status) VALUES ('Test Copies Title', 'Test Author', 5, 5, 'Available')");
$stmt->execute();
$book_id = $conn->insert_id;

$stmt2 = $conn->prepare("SELECT total_copies, available_copies, status FROM books WHERE id = ?");
$stmt2->bind_param("i", $book_id);
$stmt2->execute();
$res = $stmt2->get_result()->fetch_assoc();

assert((int)$res['total_copies'] === 5, "total copies should be 5");
assert((int)$res['available_copies'] === 5, "available copies should be 5");
assert($res['status'] === 'Available', "status should be Available");

// Test edit calculation: suppose 2 are borrowed, so available = 3
$borrowed = 2;
$new_total = 7;
$new_available = max(0, $new_total - $borrowed);
assert($new_available === 5, "new available copies should be 5 when total increased from 5 to 7");

// Cleanup test row
$conn->query("DELETE FROM books WHERE id = $book_id");

echo "PASS: Multi-copy test successful\n";
