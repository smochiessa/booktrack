<?php

require_once __DIR__ . '/vendor/autoload.php';

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

$book_id = $_GET['book_id'] ?? null;

if (!$book_id) {
    die("Book ID is missing.");
}

$qrText = "http://192.168.1.12/BookTrack/customer/borrow.php?book_id=" . $book_id;

$qrCode = new QrCode(
    data: $qrText,
    size: 300,
    margin: 10
);

$writer = new PngWriter();

$result = $writer->write($qrCode);

$fileName = "book_" . $book_id . ".png";
$filePath = __DIR__ . "/qrcodes/" . $fileName;

$result->saveToFile($filePath);

$qrPath = "qrcodes/" . $fileName;

require_once __DIR__ . '/database.php';

$updateSql = "UPDATE books SET qr_code = ? WHERE id = ?";
$updateStmt = $conn->prepare($updateSql);
$updateStmt->bind_param("si", $qrPath, $book_id);
$updateStmt->execute();

echo "QR code generated successfully!<br>";
echo "<img src='qrcodes/$fileName' width='300'>";