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

$booksUploadDir = __DIR__ . '/uploads/books';
if (!is_dir($booksUploadDir)) {
    @mkdir($booksUploadDir, 0755, true);
}

$logoSource = 'C:/Users/MCK/AppData/Local/Temp/claude/c--xampp-htdocs-booktrack/0be319c6-e747-4532-931d-0aa91a5f8520/images/1.jpg';
$logoDest = __DIR__ . '/logo.jpg';
if (!file_exists($logoDest) && file_exists($logoSource)) {
    @copy($logoSource, $logoDest);
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