<?php

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/vendor/autoload.php';

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;

$book_id = filter_var($_GET['book_id'] ?? null, FILTER_VALIDATE_INT);
$error = "";
$book = null;
$fileName = "";
$borrowUrl = "";
$logo_url = pagelounge_logo_url();

if (!$book_id) {
    $error = "Valid Book ID is required.";
} else {
    $stmt = $conn->prepare("SELECT id, title, author FROM books WHERE id = ?");
    $stmt->bind_param("i", $book_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $error = "Book not found in database.";
    } else {
        $book = $result->fetch_assoc();

        $qrDir = __DIR__ . '/qrcodes';
        if (!is_dir($qrDir)) {
            mkdir($qrDir, 0755, true);
        }

        $borrowUrl = booktrack_base_url() . "/customer/borrow.php?book_id=" . $book_id;

        try {
            $qrCode = new QrCode(
                data: $borrowUrl,
                size: 300,
                margin: 10
            );

            if (extension_loaded('gd')) {
                $writer = new PngWriter();
                $ext = "png";
            } else {
                $writer = new SvgWriter();
                $ext = "svg";
            }

            $qrResult = $writer->write($qrCode);

            $fileName = "book_" . $book_id . "." . $ext;
            $filePath = $qrDir . "/" . $fileName;
            $qrResult->saveToFile($filePath);

            $qrPath = "qrcodes/" . $fileName;

            $updateStmt = $conn->prepare("UPDATE books SET qr_code = ? WHERE id = ?");
            $updateStmt->bind_param("si", $qrPath, $book_id);
            $updateStmt->execute();
        } catch (\Throwable $e) {
            $error = "Failed to generate QR code: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Code - Page Lounge</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .qr-page {
            max-width: 520px;
            margin: 50px auto;
            padding: 20px;
        }
        .qr-card {
            background: #ffffff;
            padding: 35px;
            border-radius: 6px;
            border: 1px solid #e5dfd5;
            text-align: center;
        }
        .qr-card h1 {
            font-size: 24px;
            margin-bottom: 8px;
            color: #241e1a;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .qr-card p {
            color: #6b625b;
            margin-bottom: 20px;
            font-size: 15px;
        }
        .qr-image-wrap {
            padding: 16px;
            background: #ffffff;
            border: 1px solid #e5dfd5;
            border-radius: 6px;
            display: inline-block;
            margin-bottom: 20px;
        }
        .qr-image-wrap img {
            display: block;
            max-width: 100%;
            height: auto;
            border-radius: 4px;
        }
        .qr-url-text {
            word-break: break-all;
            font-size: 12px;
            color: #6b625b;
            background: #f4efe8;
            padding: 10px 14px;
            border-radius: 6px;
            border: 1px solid #dfd7cc;
            margin-bottom: 24px;
        }
        .error-box {
            background: #fdeeed;
            color: #c62828;
            padding: 14px;
            border-radius: 6px;
            border: 1px solid #ffcdd2;
            margin-bottom: 20px;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <header>
        <a href="index.php" class="brand-link">
            <div class="brand-emblem-wrap">
                <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="Page Lounge Logo" class="brand-emblem-img" onerror="this.onerror=null; this.src='logo.svg';">
            </div>
            <div class="brand-text-block">
                <div class="brand-title-line">
                    <span class="brand-page">Page</span>
                    <span class="brand-lounge">Lounge</span>
                </div>
                <span class="brand-tagline">Book Café</span>
            </div>
        </a>
        <div class="header-text">
            Shelf QR Generator
        </div>
    </header>

    <main class="qr-page">
        <div class="qr-card">
            <?php if ($error !== ""): ?>
                <h1><i data-heroicon="exclamation-triangle"></i> Error</h1>
                <div class="error-box">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php else: ?>
                <h1><i data-heroicon="qr-code"></i> QR Code Generated</h1>
                <p>
                    <strong><?php echo htmlspecialchars($book['title']); ?></strong>
                    <br>by <?php echo htmlspecialchars($book['author']); ?>
                </p>
                <div class="qr-image-wrap">
                    <img src="qrcodes/<?php echo htmlspecialchars($fileName); ?>" alt="Book QR Code" width="260" height="260">
                </div>
                <div class="qr-url-text">
                    <strong>Manual Token:</strong> book_<?php echo (int)$book_id; ?> (or ID: <?php echo (int)$book_id; ?>)
                    <br><span style="font-size: 11px; opacity: 0.85;"><?php echo htmlspecialchars($borrowUrl); ?></span>
                </div>
            <?php endif; ?>

            <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
                <a href="admin/books.php" class="button">
                    <i data-heroicon="arrow-left"></i> Back to Inventory
                </a>
                <?php if ($error === ""): ?>
                    <a href="<?php echo htmlspecialchars($borrowUrl); ?>" class="button" style="background: #382f28;">
                        <i data-heroicon="bookmark"></i> Test Borrow Link
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script src="heroicons.js?v=<?= @filemtime(__DIR__ . '/heroicons.js') ?: time() ?>"></script>
    <script>
        heroicons.createIcons();
    </script>
</body>
</html>
