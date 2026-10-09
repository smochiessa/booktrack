<?php
require_once __DIR__ . '/../database.php';

$res = $conn->query("SELECT id, title, author, category, isbn, qr_code FROM books LIMIT 5");
assert($res !== false, "books query should succeed");

echo "PASS: Printable QR query successful\n";
