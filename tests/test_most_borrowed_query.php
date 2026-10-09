<?php
require_once __DIR__ . '/../database.php';

$sql = "SELECT books.title, COUNT(borrowings.id) AS borrow_count
        FROM borrowings
        JOIN books ON borrowings.book_id = books.id
        GROUP BY books.id
        ORDER BY borrow_count DESC
        LIMIT 5";

$res = $conn->query($sql);
assert($res !== false, "most borrowed query should execute successfully");

$rows = [];
while ($r = $res->fetch_assoc()) {
    $rows[] = $r;
}

echo "PASS: Most borrowed books query works. Rows: " . count($rows) . "\n";
