<?php
require_once __DIR__ . '/../database.php';

$customerCols = [];
$res = $conn->query("SHOW COLUMNS FROM `customers`");
while ($row = $res->fetch_assoc()) {
    $customerCols[] = $row['Field'];
}

$bookCols = [];
$res = $conn->query("SHOW COLUMNS FROM `books`");
while ($row = $res->fetch_assoc()) {
    $bookCols[] = $row['Field'];
}

assert(in_array('email', $customerCols), "customers missing email");
assert(in_array('password', $customerCols), "customers missing password");
assert(in_array('id_image', $customerCols), "customers missing id_image");
assert(in_array('account_status', $customerCols), "customers missing account_status");
assert(in_array('total_copies', $bookCols), "books missing total_copies");
assert(in_array('available_copies', $bookCols), "books missing available_copies");
assert(is_dir(__DIR__ . '/../uploads/customer_ids'), "uploads/customer_ids folder missing");

echo "PASS: Migration check successful\n";
