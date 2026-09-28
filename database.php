<?php

$host = "localhost";
$username = "root";
$password = "";
$database = "booktrack";

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

// Auto-migrate: ensure `image` column exists in `books` table
$colCheck = $conn->query("SHOW COLUMNS FROM `books` LIKE 'image'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE `books` ADD COLUMN `image` VARCHAR(255) DEFAULT NULL AFTER `isbn`");
}

// Auto-migrate: ensure `admins` table exists and default librarian account is seeded
$conn->query("CREATE TABLE IF NOT EXISTS `admins` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(100) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_admins_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$adminCountCheck = $conn->query("SELECT COUNT(*) AS total FROM `admins`");
if ($adminCountCheck) {
    $adminCountRow = $adminCountCheck->fetch_assoc();
    if (intval($adminCountRow['total'] ?? 0) === 0) {
        $defaultUser = "librarian";
        $defaultPassHash = password_hash("booktrack123", PASSWORD_DEFAULT);
        $initStmt = $conn->prepare("INSERT INTO `admins` (`username`, `password`) VALUES (?, ?)");
        if ($initStmt) {
            $initStmt->bind_param("ss", $defaultUser, $defaultPassHash);
            $initStmt->execute();
            $initStmt->close();
        }
    }
}

$booksUploadDir = __DIR__ . '/uploads/books';
if (!is_dir($booksUploadDir)) {
    @mkdir($booksUploadDir, 0755, true);
}



$qrLibSource = dirname(__DIR__) . '/classroom_finder/assets/js/vendor/html5-qrcode.min.js';
$qrLibDest = __DIR__ . '/html5-qrcode.min.js';
if (!file_exists($qrLibDest) && file_exists($qrLibSource)) {
    @copy($qrLibSource, $qrLibDest);
}
$qrLibDest2 = __DIR__ . '/customer/html5-qrcode.min.js';
if (!file_exists($qrLibDest2) && file_exists($qrLibSource)) {
    @copy($qrLibSource, $qrLibDest2);
}

/**
 * Dynamically resolves the base URL of the Page Lounge installation.
 */
function pagelounge_base_url(): string {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
                ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $docRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $appRoot = str_replace('\\', '/', realpath(__DIR__));

    $subPath = '';
    if ($docRoot !== '' && strpos($appRoot, $docRoot) === 0) {
        $subPath = substr($appRoot, strlen($docRoot));
    }
    $subPath = '/' . ltrim(str_replace('\\', '/', $subPath), '/');
    $subPath = rtrim($subPath, '/');

    return $protocol . '://' . $host . $subPath;
}

function booktrack_base_url(): string {
    return pagelounge_base_url();
}

/**
 * Returns the URL of the Page Lounge emblem logo.
 */
function pagelounge_logo_url(): string {
    $base = booktrack_base_url();
    if (file_exists(__DIR__ . '/logo.jpg')) {
        return $base . '/logo.jpg';
    }
    return $base . '/logo.svg';
}

function booktrack_logo_url(): string {
    return pagelounge_logo_url();
}

?>