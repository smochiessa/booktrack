
<?php

session_start();

require_once "../database.php";
$logo_url = pagelounge_logo_url();

require_once "../vendor/autoload.php";

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;

if (!isset($_SESSION['librarian'])) {
    header("Location: login.php");
    exit();
}

$message = "";
$message_type = "";

// Helper to reset AUTO_INCREMENT to 1 when no books exist, or align to max(id) + 1
function sync_books_autoincrement(mysqli $conn) {
    $res = $conn->query("SELECT COUNT(*) AS total, COALESCE(MAX(id), 0) AS max_id FROM books");
    if ($res) {
        $row = $res->fetch_assoc();
        $total = intval($row['total'] ?? 0);
        $max_id = intval($row['max_id'] ?? 0);
        if ($total === 0) {
            $conn->query("ALTER TABLE books AUTO_INCREMENT = 1");
        } else {
            $next_id = $max_id + 1;
            $conn->query("ALTER TABLE books AUTO_INCREMENT = $next_id");
        }
    }
}

// Reset counter if inventory is currently empty
sync_books_autoincrement($conn);

/**
 * Handles cover image file upload and returns relative storage path or null
 */
function handle_book_cover_upload(int $book_id, ?string $existing_image = null): ?string {
    if (empty($_FILES['cover_image']['tmp_name'])) {
        return $existing_image;
    }

    $file = $_FILES['cover_image'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return $existing_image;
    }

    $allowed_extensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    $mime = '';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']) ?: '';
    } elseif (function_exists('mime_content_type')) {
        $mime = mime_content_type($file['tmp_name']) ?: '';
    }

    if (!in_array($ext, $allowed_extensions, true)) {
        return $existing_image;
    }
    if ($mime !== '' && !in_array($mime, $allowed_mimes, true)) {
        return $existing_image;
    }

    $uploadDir = __DIR__ . '/../uploads/books';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    $fileName = 'cover_' . $book_id . '_' . time() . '.' . $ext;
    $targetPath = $uploadDir . '/' . $fileName;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        // Clean up previous image file if replacing
        if (!empty($existing_image)) {
            $oldPath = __DIR__ . '/../' . $existing_image;
            if (file_exists($oldPath) && is_file($oldPath)) {
                @unlink($oldPath);
            }
        }
        return 'uploads/books/' . $fileName;
    }

    return $existing_image;
}


// ADD BOOK
if ($_SERVER["REQUEST_METHOD"] == "POST" && (isset($_POST['add_book']) || isset($_POST['title'])) && !isset($_POST['edit_book'])) {

    $title = trim($_POST['title'] ?? '');
    $author = trim($_POST['author'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $isbn = trim($_POST['isbn'] ?? '');

    if ($title == "" || $author == "") {

        $message = "Title and author are required.";
        $message_type = "error";

    } else {

        // Ensure auto_increment is fresh if no books exist
        sync_books_autoincrement($conn);

        $sql = "INSERT INTO books
                (title, author, category, isbn, status)
                VALUES (?, ?, ?, ?, 'Available')";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            $message = "Database error: " . $conn->error;
            $message_type = "error";
        } else {
            $stmt->bind_param(
                "ssss",
                $title,
                $author,
                $category,
                $isbn
            );

            if ($stmt->execute()) {

                $book_id = $conn->insert_id;

                // Handle Cover Image Upload
                if (!empty($_FILES['cover_image']['tmp_name'])) {
                    $cover_path = handle_book_cover_upload($book_id, null);
                    if ($cover_path) {
                        $imgStmt = $conn->prepare("UPDATE books SET image = ? WHERE id = ?");
                        $imgStmt->bind_param("si", $cover_path, $book_id);
                        $imgStmt->execute();
                    }
                }

                $qrDir = __DIR__ . "/../qrcodes";
                if (!is_dir($qrDir)) {
                    mkdir($qrDir, 0755, true);
                }

                $qrText = booktrack_base_url() . "/customer/borrow.php?book_id=" . $book_id;

                try {
                    $qrCode = new QrCode(
                        data: $qrText,
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

                    $result = $writer->write($qrCode);

                    $fileName = "book_" . $book_id . "." . $ext;
                    $filePath = $qrDir . "/" . $fileName;

                    $result->saveToFile($filePath);

                    $qrPath = "qrcodes/" . $fileName;

                    $updateSql = "UPDATE books SET qr_code = ? WHERE id = ?";
                    $updateStmt = $conn->prepare($updateSql);
                    $updateStmt->bind_param("si", $qrPath, $book_id);
                    $updateStmt->execute();

                    $message = "Book added successfully!";
                    $message_type = "success";
                } catch (\Throwable $e) {
                    $message = "Book added, but QR code could not be created: " . $e->getMessage();
                    $message_type = "error";
                }

            } else {

                $message = "Failed to add book: " . ($stmt->error ?: $conn->error);
                $message_type = "error";
            }
        }
    }
}


// EDIT BOOK
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_book'])) {

    $book_id = intval($_POST['book_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $author = trim($_POST['author'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $isbn = trim($_POST['isbn'] ?? '');
    $remove_image = !empty($_POST['remove_image']) && $_POST['remove_image'] === '1';

    if ($book_id <= 0) {
        $message = "Invalid book selected for editing.";
        $message_type = "error";
    } elseif ($title === "" || $author === "") {
        $message = "Title and author are required.";
        $message_type = "error";
    } else {
        // Fetch current book info
        $cur_stmt = $conn->prepare("SELECT id, image FROM books WHERE id = ?");
        $cur_stmt->bind_param("i", $book_id);
        $cur_stmt->execute();
        $cur_book = $cur_stmt->get_result()->fetch_assoc();

        if (!$cur_book) {
            $message = "Book not found.";
            $message_type = "error";
        } else {
            $image_path = $cur_book['image'];

            if ($remove_image && empty($_FILES['cover_image']['tmp_name'])) {
                if (!empty($image_path)) {
                    $old_file = __DIR__ . "/../" . $image_path;
                    if (file_exists($old_file) && is_file($old_file)) {
                        @unlink($old_file);
                    }
                }
                $image_path = null;
            } elseif (!empty($_FILES['cover_image']['tmp_name'])) {
                $image_path = handle_book_cover_upload($book_id, $image_path);
            }

            $update_sql = "UPDATE books SET title = ?, author = ?, category = ?, isbn = ?, image = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("sssssi", $title, $author, $category, $isbn, $image_path, $book_id);

            if ($update_stmt->execute()) {
                $message = "Book updated successfully!";
                $message_type = "success";
            } else {
                $message = "Failed to update book: " . ($update_stmt->error ?: $conn->error);
                $message_type = "error";
            }
        }
    }
}


// DELETE BOOK
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_book'])) {

    $book_id = intval($_POST['book_id']);

    // Check book status, QR code, and cover image first
    $check_stmt = $conn->prepare("SELECT id, status, qr_code, image FROM books WHERE id = ?");
    $check_stmt->bind_param("i", $book_id);
    $check_stmt->execute();
    $book_data = $check_stmt->get_result()->fetch_assoc();

    if (!$book_data) {
        $message = "Book not found.";
        $message_type = "error";
    } elseif ($book_data['status'] !== 'Available') {
        $message = "Borrowed books cannot be deleted.";
        $message_type = "error";
    } else {
        $sql = "DELETE FROM books WHERE id = ? AND status = 'Available'";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $book_id);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            // Clean up QR code image file
            if (!empty($book_data['qr_code'])) {
                $file_on_disk = __DIR__ . "/../" . $book_data['qr_code'];
                if (file_exists($file_on_disk)) {
                    @unlink($file_on_disk);
                }
            }

            // Clean up cover image file
            if (!empty($book_data['image'])) {
                $img_on_disk = __DIR__ . "/../" . $book_data['image'];
                if (file_exists($img_on_disk)) {
                    @unlink($img_on_disk);
                }
            }

            // Reset AUTO_INCREMENT to 1 if no books left, or max(id) + 1
            sync_books_autoincrement($conn);

            $message = "Book deleted successfully!";
            $message_type = "success";
        } else {
            $message = "Failed to delete book.";
            $message_type = "error";
        }
    }
}


// SEARCH & STATUS FILTER / GET BOOKS
$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');

// Inventory counts for toolbar status tabs
$stat_total = 0;
$stat_available = 0;
$stat_borrowed = 0;
$counts_res = $conn->query("SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN status = 'Available' THEN 1 ELSE 0 END) AS available_count,
    SUM(CASE WHEN status = 'Borrowed' THEN 1 ELSE 0 END) AS borrowed_count
FROM books");
if ($counts_res && ($row = $counts_res->fetch_assoc())) {
    $stat_total = (int)($row['total'] ?? 0);
    $stat_available = (int)($row['available_count'] ?? 0);
    $stat_borrowed = (int)($row['borrowed_count'] ?? 0);
}

$where_clauses = [];
$params = [];
$types = "";

if ($search !== '') {
    $where_clauses[] = "(title LIKE ? OR author LIKE ? OR category LIKE ? OR isbn LIKE ?)";
    $term = "%" . $search . "%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $types .= "ssss";
}

if ($status_filter === 'Available' || $status_filter === 'Borrowed') {
    $where_clauses[] = "status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
$order_sql = "ORDER BY id ASC";

if (!empty($params)) {
    $sql = "SELECT * FROM books $where_sql $order_sql";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $books = $stmt->get_result();
} else {
    $books = $conn->query("SELECT * FROM books $where_sql $order_sql");
}
?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Manage Books - Page Lounge</title>

    <link rel="stylesheet" href="../style.css">

    <style>

        .books-admin {
            width: 100%;
            max-width: 100%;
            margin: 0;
            padding: 0;
        }

        .page-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .page-title-text h1 {
            font-size: 28px;
            color: #241e1a;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
        }

        .page-title-text p {
            color: #6b625b;
            margin: 6px 0 0;
            font-size: 15px;
        }


        /* FORM */

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            font-size: 14px;
            color: #2e2620;
        }

        .form-group input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d5cbbe;
            border-radius: 6px;
            font-size: 14px;
            background: #ffffff;
            color: #2e2620;
            outline: none;
            transition: border-color 0.15s ease, outline 0.15s ease;
            box-sizing: border-box;
        }

        .form-group input:focus {
            background: #ffffff;
            border-color: #2e2620;
            outline: 2px solid rgba(46, 38, 32, 0.2);
            outline-offset: 1px;
        }

        .add-button {
            padding: 5px 12px;
            background: #2e2620;
            color: white;
            border: 1px solid #241e1a;
            border-radius: 4px;
            font-weight: 600;
            font-size: 12px;
            cursor: pointer;
            min-height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            transition: background 0.15s ease;
        }

        .add-button:hover {
            background: #443830;
        }

        /* Modal Overlay & Base */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(20, 14, 10, 0.65);
            backdrop-filter: blur(2px);
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            box-sizing: border-box;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease;
        }

        .modal-overlay.is-active {
            opacity: 1;
            pointer-events: auto;
        }

        .modal-dialog {
            background: #ffffff;
            border: 1px solid #d5cbbe;
            border-radius: 8px;
            width: 100%;
            max-width: 520px;
            max-height: 92vh;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: #d5cbbe transparent;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.28);
            transform: translateY(12px) scale(0.98);
            transition: transform 0.2s ease;
            box-sizing: border-box;
        }

        .modal-dialog::-webkit-scrollbar {
            width: 6px;
        }

        .modal-dialog::-webkit-scrollbar-track {
            background: transparent;
        }

        .modal-dialog::-webkit-scrollbar-thumb {
            background: #d5cbbe;
            border-radius: 3px;
        }

        .modal-dialog::-webkit-scrollbar-thumb:hover {
            background: #8b7d72;
        }

        .modal-overlay.is-active .modal-dialog {
            transform: translateY(0) scale(1);
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 18px;
            border-bottom: 1px solid #eae4db;
            background: #faf7f3;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 16px;
            color: #241e1a;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .modal-close-btn {
            background: transparent;
            border: none;
            color: #6b625b;
            cursor: pointer;
            padding: 4px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            transition: background 0.15s ease, color 0.15s ease;
        }

        .modal-close-btn:hover {
            background: #eae4db;
            color: #241e1a;
        }

        .modal-body {
            padding: 14px 18px;
        }

        .modal-form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .modal-form-grid .full-width {
            grid-column: 1 / -1;
        }

        .modal-dialog .form-group label {
            margin-bottom: 4px;
            font-size: 13px;
        }

        .modal-dialog .form-group input {
            padding: 7px 11px;
            font-size: 13px;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 14px;
        }

        .btn-cancel {
            padding: 8px 16px;
            background: #f4efe8;
            color: #2e2620;
            border: 1px solid #d5cbbe;
            border-radius: 6px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            min-height: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: background 0.15s ease;
        }

        .btn-cancel:hover {
            background: #eae4db;
        }

        .modal-submit-btn {
            min-height: 38px;
            padding: 8px 16px;
            font-size: 13px;
        }

        @media (max-width: 640px) {
            .page-title {
                flex-direction: column;
                align-items: stretch;
            }
            .page-title .add-button {
                width: 100%;
            }
            .modal-form-grid {
                grid-template-columns: 1fr;
            }
            .modal-actions {
                flex-direction: column-reverse;
            }
            .modal-actions button {
                width: 100%;
            }
        }


        /* MESSAGES */

        .success {
            background: #edf7ed;
            color: #1e6b24;
            padding: 12px 16px;
            border: 1px solid #c8e6c9;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .error {
            background: #fdeeed;
            color: #c62828;
            padding: 12px 16px;
            border: 1px solid #ffcdd2;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: 500;
        }


        /* TABLE */

        .books-table {
            background: #ffffff;
            padding: 18px 20px;
            border-radius: 8px;
            border: 1px solid #e5dfd5;
            box-shadow: 0 1px 3px rgba(20, 14, 10, 0.04);
        }

        .inventory-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }

        .inventory-toolbar-left {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .inventory-toolbar-left h2 {
            margin: 0;
            font-size: 17px;
            color: #241e1a;
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .inventory-status-tabs {
            display: inline-flex;
            align-items: center;
            background: #f4efe8;
            padding: 2px;
            border-radius: 6px;
            border: 1px solid #dfd7cc;
            gap: 2px;
        }

        .status-tab {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 9px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            color: #6b625b;
            text-decoration: none;
            transition: background 0.15s ease, color 0.15s ease;
        }

        .status-tab:hover {
            color: #2e2620;
            background: rgba(255, 255, 255, 0.7);
            text-decoration: none;
        }

        .status-tab.active {
            background: #2e2620;
            color: #ffffff !important;
            box-shadow: 0 1px 3px rgba(46, 38, 32, 0.2);
        }

        .status-tab .tab-badge {
            font-size: 10.5px;
            padding: 1px 5px;
            border-radius: 8px;
            background: rgba(0, 0, 0, 0.08);
            color: inherit;
        }

        .status-tab.active .tab-badge {
            background: rgba(255, 255, 255, 0.25);
            color: #ffffff;
        }

        .inventory-toolbar-right {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .search-form {
            display: flex;
            align-items: center;
            gap: 6px;
            margin: 0;
        }

        .search-form input {
            width: 240px;
            max-width: 100%;
            height: 32px;
            padding: 5px 10px;
            border: 1px solid #d5cbbe;
            border-radius: 4px;
            font-size: 12.5px;
            background: #ffffff;
            color: #2e2620;
            outline: none;
            transition: border-color 0.15s ease, outline 0.15s ease;
            box-sizing: border-box;
        }

        .search-form input:focus {
            background: #ffffff;
            border-color: #2e2620;
            outline: 2px solid rgba(46, 38, 32, 0.2);
            outline-offset: 1px;
        }

        .search-form button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            padding: 5px 11px;
            background: #2e2620;
            color: white;
            border: 1px solid #241e1a;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            min-height: 32px;
            white-space: nowrap;
            transition: background 0.15s ease;
        }

        .search-form button:hover {
            background: #443830;
        }

        .clear-search-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 5px 9px;
            background: #f4efe8;
            color: #2e2620;
            text-decoration: none;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            border: 1px solid #d5cbbe;
            white-space: nowrap;
            min-height: 32px;
            transition: background 0.15s ease;
        }

        .clear-search-btn:hover {
            background: #eae4db;
            text-decoration: none;
        }

        .active-filter-banner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            background: #faf7f3;
            border: 1px solid #dfd7cc;
            border-radius: 6px;
            padding: 10px 16px;
            margin-bottom: 16px;
            font-size: 13px;
            color: #554b43;
        }

        .clear-all-filters-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            color: #b71c1c;
            font-size: 12.5px;
            font-weight: 600;
            text-decoration: none;
        }

        .clear-all-filters-btn:hover {
            text-decoration: underline;
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border: 1px solid #e5dfd5;
            border-radius: 6px;
            background: #ffffff;
        }

        .books-inventory-table {
            width: 100%;
            min-width: 860px;
            border-collapse: collapse;
            table-layout: auto;
        }

        .books-inventory-table th,
        .books-inventory-table td {
            padding: 7px 10px;
            border-bottom: 1px solid #eae4db;
            font-size: 13px;
            vertical-align: middle;
        }

        .books-inventory-table th {
            padding: 8px 10px;
            background: #f7f3ec;
            color: #2e2620;
            font-weight: 600;
            border-bottom: 1px solid #dfd7cc;
            font-size: 12px;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }

        .books-inventory-table tr:last-child td {
            border-bottom: none;
        }

        .books-inventory-table tr:hover td {
            background: #faf7f3;
        }

        /* Column Specific Alignments & Widths */
        .col-id {
            width: 44px;
            text-align: center !important;
        }

        .col-cover {
            width: 46px;
            text-align: center !important;
        }

        .col-title {
            min-width: 160px;
            text-align: left !important;
        }

        .col-author {
            min-width: 110px;
            text-align: left !important;
        }

        .col-category {
            width: 95px;
            text-align: left !important;
        }

        .col-isbn {
            width: 105px;
            text-align: left !important;
        }

        .col-qr {
            width: 72px;
            text-align: center !important;
        }

        .col-status {
            width: 92px;
            text-align: center !important;
        }

        .col-action {
            width: 136px;
            text-align: center !important;
        }

        .book-id-badge {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-weight: 700;
            color: #554b43;
            font-size: 12px;
        }

        .col-title strong,
        .book-title-cell strong,
        .book-title-cell {
            color: #241e1a;
            font-size: 13px;
            font-weight: 600;
        }

        .category-badge {
            display: inline-block;
            background: #f4efe8;
            color: #4a3f35;
            padding: 1px 6px;
            border-radius: 3px;
            font-size: 11.5px;
            font-weight: 500;
            border: 1px solid #dfd7cc;
        }

        .isbn-tag {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 12px;
            color: #6b625b;
        }

        .muted-text {
            color: #9e9389;
            font-size: 12px;
        }

        .qr-preview-box {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            gap: 2px;
        }

        .qr-thumb-img {
            display: block;
            width: 32px;
            height: 32px;
            border-radius: 3px;
            border: 1px solid #dcd4c8;
            background: #ffffff;
            transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
        }

        .qr-preview-box a:hover .qr-thumb-img {
            transform: scale(2.4);
            border-color: #2e2620;
            box-shadow: 0 4px 12px rgba(46, 38, 32, 0.22);
            position: relative;
            z-index: 20;
        }

        .qr-token-label {
            font-size: 10px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            color: #6b625b;
            background: #f4efe8;
            padding: 0 4px;
            border-radius: 2px;
            border: 1px solid #e3dbd0;
            font-weight: 500;
            white-space: nowrap;
            line-height: 1.3;
        }

        .qr-generate-link {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11.5px;
            color: #2e2620;
            text-decoration: underline;
            font-weight: 500;
        }

        .available {
            background: #edf7ed;
            color: #1e6b24;
            border: 1px solid #c8e6c9;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-size: 11px;
            padding: 2px 7px;
            border-radius: 3px;
            letter-spacing: 0.2px;
            white-space: nowrap;
        }

        .borrowed {
            background: #fff8e1;
            color: #8a5200;
            border: 1px solid #ffe082;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-size: 11px;
            padding: 2px 7px;
            border-radius: 3px;
            letter-spacing: 0.2px;
            white-space: nowrap;
        }

        .delete-button {
            background: #c62828;
            color: white;
            border: 1px solid #b71c1c;
            min-height: 28px;
            padding: 4px 8px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            white-space: nowrap;
            transition: background 0.15s ease;
        }

        .delete-button:hover {
            background: #b71c1c;
        }

        .edit-button {
            background: #f4efe8;
            color: #2e2620;
            border: 1px solid #d5cbbe;
            min-height: 28px;
            padding: 4px 8px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            white-space: nowrap;
            transition: background 0.15s ease, border-color 0.15s ease;
        }

        .edit-button:hover {
            background: #eae4db;
            border-color: #2e2620;
        }

        .table-actions-cell {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            margin: 0 auto;
            white-space: nowrap;
        }

        .active-borrow-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 3px;
            padding: 4px 7px;
            background: #f4efe8;
            color: #7a6e64;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            border: 1px solid #dfd7cc;
            white-space: nowrap;
            min-height: 28px;
            box-sizing: border-box;
        }

        /* Cover Column in Admin Table */
        .book-cover-cell {
            text-align: center;
        }

        .book-thumb-img {
            width: 28px;
            height: 38px;
            object-fit: cover;
            border-radius: 3px;
            border: 1px solid #dcd4c8;
            background: #ffffff;
            display: block;
            margin: 0 auto;
            box-shadow: 0 1px 2px rgba(46, 38, 32, 0.08);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .book-thumb-img:hover {
            transform: scale(2.2);
            box-shadow: 0 6px 16px rgba(46, 38, 32, 0.25);
            position: relative;
            z-index: 20;
        }

        .book-thumb-empty {
            width: 28px;
            height: 38px;
            border-radius: 3px;
            border: 1px dashed #d5cbbe;
            background: #faf7f3;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 1px;
            margin: 0 auto;
            color: #a89f91;
        }

        .book-thumb-empty svg {
            width: 14px;
            height: 14px;
            opacity: 0.6;
        }

        .book-thumb-empty span {
            font-size: 7.5px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.2px;
        }

        /* Cover Upload Dropzone & Previews */
        .cover-upload-area {
            border: 2px dashed #d5cbbe;
            border-radius: 8px;
            padding: 10px 14px;
            background: #faf7f3;
            text-align: center;
            position: relative;
            transition: border-color 0.15s ease, background 0.15s ease;
        }

        .cover-upload-area:hover {
            border-color: #2e2620;
            background: #f4efe8;
        }

        .cover-upload-prompt {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
        }

        .cover-upload-prompt .upload-icon {
            width: 24px;
            height: 24px;
            color: #8b7d72;
        }

        .upload-title {
            font-size: 13px;
            font-weight: 600;
            color: #2e2620;
        }

        .upload-hint {
            font-size: 11px;
            color: #8b7d72;
            margin-bottom: 2px;
        }

        .form-group label.btn-file-select,
        .cover-upload-prompt label.btn-file-select,
        .btn-file-select {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 6px !important;
            padding: 7px 16px !important;
            background: #2e2620 !important;
            color: #ffffff !important;
            border-radius: 6px !important;
            font-size: 13px !important;
            font-weight: 600 !important;
            cursor: pointer !important;
            margin: 0 !important;
            border: 1px solid #241e1a !important;
            line-height: 1.4 !important;
            box-shadow: 0 1px 3px rgba(46, 38, 32, 0.15) !important;
            transition: background 0.15s ease !important;
        }

        .form-group label.btn-file-select:hover,
        .cover-upload-prompt label.btn-file-select:hover,
        .btn-file-select:hover {
            background: #443830 !important;
            color: #ffffff !important;
        }

        .btn-file-select * {
            color: #ffffff !important;
        }

        .btn-file-select svg,
        .btn-file-select i {
            width: 15px !important;
            height: 15px !important;
            stroke: #ffffff !important;
            color: #ffffff !important;
            display: inline-block !important;
        }

        .sr-only-input {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            border: 0;
        }

        .cover-preview-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            max-width: 130px;
            margin: 0 auto;
        }

        .cover-preview-img {
            width: 95px;
            height: 130px;
            object-fit: cover;
            border-radius: 6px;
            border: 1px solid #d5cbbe;
            box-shadow: 0 4px 10px rgba(46, 38, 32, 0.12);
        }

        .remove-preview-btn {
            position: absolute;
            top: -8px;
            right: 2px;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: #c62828;
            color: #ffffff;
            border: 2px solid #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
            transition: background 0.15s ease, transform 0.15s ease;
        }

        .remove-preview-btn:hover {
            background: #b71c1c;
            transform: scale(1.1);
        }

        .remove-preview-btn svg {
            width: 14px;
            height: 14px;
        }

        @media (max-width: 768px) {
            .inventory-toolbar {
                flex-direction: column;
                align-items: stretch;
            }
            .inventory-toolbar-right {
                flex-direction: column;
                align-items: stretch;
            }
            .search-form {
                width: 100%;
            }
            .search-form input {
                width: 100%;
                flex: 1;
            }
            .inventory-toolbar-right .add-button {
                width: 100%;
                justify-content: center;
            }
        }

    </style>

</head>


<body>

<div class="admin-layout">

    <aside class="admin-sidebar">

        <div class="admin-sidebar-header">
            <a href="dashboard.php" class="brand-link">
                <div class="brand-emblem-wrap">
                    <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="Page Lounge Logo" class="brand-emblem-img" onerror="this.onerror=null; this.src='../logo.svg';">
                </div>
                <div class="brand-text-block">
                    <div class="brand-title-line">
                        <span class="brand-page">Page</span>
                        <span class="brand-lounge">Lounge</span>
                    </div>
                    <span class="brand-tagline">Book Café</span>
                </div>
            </a>

            <button type="button" class="admin-burger-btn" id="adminBurgerBtn" aria-label="Toggle navigation menu" aria-expanded="false" onclick="var s=document.querySelector('.admin-sidebar'); s.classList.toggle('nav-open'); this.setAttribute('aria-expanded', s.classList.contains('nav-open'));">
                <i data-heroicon="bars-3" class="burger-icon-bars"></i>
                <i data-heroicon="x-mark" class="burger-icon-close"></i>
                <span>Menu</span>
            </button>
        </div>

        <div class="admin-sidebar-collapse">
            <div class="sidebar-top">
                <div class="sidebar-subtitle">
                    Librarian Portal
                </div>

                <nav class="sidebar-nav">
                    <a href="dashboard.php">
                        <i data-heroicon="squares-2x2"></i> Dashboard
                    </a>
                    <a href="books.php" class="active">
                        <i data-heroicon="rectangle-stack"></i> Book Inventory
                    </a>
                    <a href="borrowings.php">
                        <i data-heroicon="clock"></i> Borrowing History
                    </a>
                    <a href="settings.php">
                        <i data-heroicon="cog-6-tooth"></i> Account Settings
                    </a>
                </nav>
            </div>

            <div class="sidebar-bottom">
                <a href="logout.php" class="sidebar-logout">
                    <i data-heroicon="arrow-left-on-rectangle"></i> Logout
                </a>
            </div>
        </div>

    </aside>

    <div class="admin-main">

        <main class="books-admin">


            <div class="page-title">
                <div class="page-title-text">
                    <h1>
                        <i data-heroicon="rectangle-stack"></i> Manage Books
                    </h1>
                    <p>
                        Add and manage books in the café library.
                    </p>
                </div>
            </div>


    <?php if ($message != ""): ?>

        <div class="<?php echo $message_type; ?>">

            <?php echo htmlspecialchars($message); ?>

        </div>

    <?php endif; ?>


    <!-- ADD BOOK MODAL -->
    <div class="modal-overlay" id="addBookModal" aria-hidden="true" onclick="if(event.target===this) closeAddBookModal();">
        <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="addBookModalTitle">
            <div class="modal-header">
                <h2 id="addBookModalTitle">
                    <i data-heroicon="plus-circle"></i> Add New Book
                </h2>
                <button type="button" class="modal-close-btn" onclick="closeAddBookModal()" aria-label="Close dialog">
                    <i data-heroicon="x-mark"></i>
                </button>
            </div>
            <div class="modal-body">
                <form method="POST" id="addBookForm" enctype="multipart/form-data" onsubmit="var b=this.querySelector('.modal-submit-btn'); if(b){b.innerHTML='<i data-heroicon=\'arrow-path\' class=\'heroicon-spin\'></i> Adding...'; if(window.heroicons){heroicons.createIcons({root:b});}}">
                    <input type="hidden" name="add_book" value="1">

                    <div class="modal-form-grid">
                        <div class="form-group full-width">
                            <label for="book-title">Book Title *</label>
                            <input
                                type="text"
                                id="book-title"
                                name="title"
                                placeholder="Enter book title"
                                value="<?php echo ($message_type === 'error' && isset($_POST['title']) && !isset($_POST['edit_book'])) ? htmlspecialchars($_POST['title']) : ''; ?>"
                                required
                            >
                        </div>

                        <div class="form-group full-width">
                            <label for="book-author">Author *</label>
                            <input
                                type="text"
                                id="book-author"
                                name="author"
                                placeholder="Enter author"
                                value="<?php echo ($message_type === 'error' && isset($_POST['author']) && !isset($_POST['edit_book'])) ? htmlspecialchars($_POST['author']) : ''; ?>"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="book-category">Category</label>
                            <input
                                type="text"
                                id="book-category"
                                name="category"
                                placeholder="e.g. Fiction"
                                value="<?php echo ($message_type === 'error' && isset($_POST['category']) && !isset($_POST['edit_book'])) ? htmlspecialchars($_POST['category']) : ''; ?>"
                            >
                        </div>

                        <div class="form-group">
                            <label for="book-isbn">ISBN</label>
                            <input
                                type="text"
                                id="book-isbn"
                                name="isbn"
                                placeholder="Enter ISBN"
                                value="<?php echo ($message_type === 'error' && isset($_POST['isbn']) && !isset($_POST['edit_book'])) ? htmlspecialchars($_POST['isbn']) : ''; ?>"
                            >
                        </div>

                        <div class="form-group full-width">
                            <label>Book Cover Image (Optional)</label>
                            <div class="cover-upload-area" id="addCoverDropzone">
                                <div class="cover-preview-wrapper" id="addCoverPreviewWrap" style="display: none;">
                                    <img src="" alt="Cover preview" id="addCoverPreviewImg" class="cover-preview-img">
                                    <button type="button" class="remove-preview-btn" onclick="clearCoverUpload('add')" aria-label="Remove image">
                                        <i data-heroicon="x-mark"></i>
                                    </button>
                                </div>
                                <div class="cover-upload-prompt" id="addCoverPrompt">
                                    <i data-heroicon="photo" class="upload-icon"></i>
                                    <span class="upload-title">Choose cover image</span>
                                    <span class="upload-hint">PNG, JPG, WEBP, or GIF up to 5MB</span>
                                    <label for="add-book-cover" class="btn-file-select">
                                        <i data-heroicon="arrow-up-tray"></i> Browse File
                                    </label>
                                    <input
                                        type="file"
                                        id="add-book-cover"
                                        name="cover_image"
                                        accept="image/png, image/jpeg, image/webp, image/gif"
                                        class="sr-only-input"
                                        onchange="previewCoverImage(this, 'add')"
                                    >
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-actions">
                        <button type="button" class="btn-cancel" onclick="closeAddBookModal()">
                            Cancel
                        </button>
                        <button type="submit" class="add-button modal-submit-btn">
                            <i data-heroicon="plus"></i> Add Book
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <!-- EDIT BOOK MODAL -->
    <div class="modal-overlay" id="editBookModal" aria-hidden="true" onclick="if(event.target===this) closeEditBookModal();">
        <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="editBookModalTitle">
            <div class="modal-header">
                <h2 id="editBookModalTitle">
                    <i data-heroicon="pencil-square"></i> Edit Book
                </h2>
                <button type="button" class="modal-close-btn" onclick="closeEditBookModal()" aria-label="Close dialog">
                    <i data-heroicon="x-mark"></i>
                </button>
            </div>
            <div class="modal-body">
                <form method="POST" id="editBookForm" enctype="multipart/form-data" onsubmit="var b=this.querySelector('.modal-submit-btn'); if(b){b.innerHTML='<i data-heroicon=\'arrow-path\' class=\'heroicon-spin\'></i> Saving...'; if(window.heroicons){heroicons.createIcons({root:b});}}">
                    <input type="hidden" name="edit_book" value="1">
                    <input type="hidden" name="book_id" id="edit-book-id" value="">
                    <input type="hidden" name="remove_image" id="editRemoveImage" value="0">

                    <div class="modal-form-grid">
                        <div class="form-group full-width">
                            <label for="edit-book-title">Book Title *</label>
                            <input
                                type="text"
                                id="edit-book-title"
                                name="title"
                                placeholder="Enter book title"
                                required
                            >
                        </div>

                        <div class="form-group full-width">
                            <label for="edit-book-author">Author *</label>
                            <input
                                type="text"
                                id="edit-book-author"
                                name="author"
                                placeholder="Enter author"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="edit-book-category">Category</label>
                            <input
                                type="text"
                                id="edit-book-category"
                                name="category"
                                placeholder="e.g. Fiction"
                            >
                        </div>

                        <div class="form-group">
                            <label for="edit-book-isbn">ISBN</label>
                            <input
                                type="text"
                                id="edit-book-isbn"
                                name="isbn"
                                placeholder="Enter ISBN"
                            >
                        </div>

                        <div class="form-group full-width">
                            <label>Book Cover Image</label>
                            <div class="cover-upload-area" id="editCoverDropzone">
                                <div class="cover-preview-wrapper" id="editCoverPreviewWrap" style="display: none;">
                                    <img src="" alt="Cover preview" id="editCoverPreviewImg" class="cover-preview-img">
                                    <button type="button" class="remove-preview-btn" onclick="clearCoverUpload('edit')" aria-label="Remove image">
                                        <i data-heroicon="x-mark"></i>
                                    </button>
                                </div>
                                <div class="cover-upload-prompt" id="editCoverPrompt">
                                    <i data-heroicon="photo" class="upload-icon"></i>
                                    <span class="upload-title">Change cover image</span>
                                    <span class="upload-hint">PNG, JPG, WEBP, or GIF up to 5MB</span>
                                    <label for="edit-book-cover" class="btn-file-select">
                                        <i data-heroicon="arrow-up-tray"></i> Browse File
                                    </label>
                                    <input
                                        type="file"
                                        id="edit-book-cover"
                                        name="cover_image"
                                        accept="image/png, image/jpeg, image/webp, image/gif"
                                        class="sr-only-input"
                                        onchange="previewCoverImage(this, 'edit')"
                                    >
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-actions">
                        <button type="button" class="btn-cancel" onclick="closeEditBookModal()">
                            Cancel
                        </button>
                        <button type="submit" class="add-button modal-submit-btn">
                            <i data-heroicon="check"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <!-- BOOK LIST -->

    <div class="books-table">

        <div class="inventory-toolbar">
            <div class="inventory-toolbar-left">
                <h2>
                    <i data-heroicon="book-open"></i> Book Inventory
                </h2>
                <div class="inventory-status-tabs">
                    <a href="books.php<?php echo !empty($search) ? '?search=' . urlencode($search) : ''; ?>" class="status-tab <?php echo empty($status_filter) ? 'active' : ''; ?>">
                        All <span class="tab-badge"><?php echo $stat_total; ?></span>
                    </a>
                    <a href="books.php?status=Available<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="status-tab <?php echo $status_filter === 'Available' ? 'active' : ''; ?>">
                        Available <span class="tab-badge"><?php echo $stat_available; ?></span>
                    </a>
                    <a href="books.php?status=Borrowed<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="status-tab <?php echo $status_filter === 'Borrowed' ? 'active' : ''; ?>">
                        Borrowed <span class="tab-badge"><?php echo $stat_borrowed; ?></span>
                    </a>
                </div>
            </div>

            <div class="inventory-toolbar-right">
                <form method="GET" class="search-form">
                    <?php if (!empty($status_filter)): ?>
                        <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                    <?php endif; ?>
                    <input
                        type="text"
                        name="search"
                        placeholder="Search title, author, category, ISBN..."
                        value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>"
                    >
                    <button type="submit">
                        <i data-heroicon="magnifying-glass"></i> Search
                    </button>
                    <?php if (!empty($search) || !empty($status_filter)): ?>
                        <a href="books.php" class="clear-search-btn" title="Reset all search & filters">
                            <i data-heroicon="x-mark"></i> Reset
                        </a>
                    <?php endif; ?>
                </form>

                <button type="button" class="add-button" onclick="openAddBookModal()">
                    <i data-heroicon="plus"></i> Add New Book
                </button>
            </div>
        </div>

        <?php if (!empty($search) || !empty($status_filter)): ?>
            <div class="active-filter-banner">
                <span>
                    Filtered by:
                    <?php if (!empty($search)): ?>
                        search <strong>"<?php echo htmlspecialchars($search); ?>"</strong>
                    <?php endif; ?>
                    <?php if (!empty($search) && !empty($status_filter)): ?> • <?php endif; ?>
                    <?php if (!empty($status_filter)): ?>
                        status <strong><?php echo htmlspecialchars($status_filter); ?></strong>
                    <?php endif; ?>
                    (<?php echo (int)$books->num_rows; ?> <?php echo $books->num_rows === 1 ? 'match' : 'matches'; ?>)
                </span>
                <a href="books.php" class="clear-all-filters-btn">
                    <i data-heroicon="x-mark"></i> Clear Filters
                </a>
            </div>
        <?php endif; ?>

        <div class="table-responsive">

            <table class="books-inventory-table">

                <thead>
                    <tr>
                        <th class="col-id">ID</th>
                        <th class="col-cover">Cover</th>
                        <th class="col-title">Title</th>
                        <th class="col-author">Author</th>
                        <th class="col-category">Category</th>
                        <th class="col-isbn">ISBN</th>
                        <th class="col-qr">QR Code</th>
                        <th class="col-status">Status</th>
                        <th class="col-action">Action</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if ($books->num_rows > 0): ?>

                        <?php while ($book = $books->fetch_assoc()): ?>

                            <tr>
                                <td class="col-id">
                                    <span class="book-id-badge">#<?php echo (int)$book['id']; ?></span>
                                </td>

                                <td class="col-cover">
                                    <?php if (!empty($book['image']) && file_exists(__DIR__ . '/../' . $book['image'])): ?>
                                        <img
                                            src="../<?php echo htmlspecialchars($book['image']); ?>"
                                            alt="Cover"
                                            class="book-thumb-img"
                                        >
                                    <?php else: ?>
                                        <div class="book-thumb-empty" title="No cover image">
                                            <i data-heroicon="photo"></i>
                                            <span>No img</span>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td class="col-title book-title-cell">
                                    <strong><?php echo htmlspecialchars($book['title']); ?></strong>
                                </td>

                                <td class="col-author">
                                    <?php echo htmlspecialchars($book['author']); ?>
                                </td>

                                <td class="col-category">
                                    <?php if (!empty($book['category'])): ?>
                                        <span class="category-badge"><?php echo htmlspecialchars($book['category']); ?></span>
                                    <?php else: ?>
                                        <span class="muted-text">—</span>
                                    <?php endif; ?>
                                </td>

                                <td class="col-isbn">
                                    <?php if (!empty($book['isbn'])): ?>
                                        <span class="isbn-tag"><?php echo htmlspecialchars($book['isbn']); ?></span>
                                    <?php else: ?>
                                        <span class="muted-text">—</span>
                                    <?php endif; ?>
                                </td>

                                <td class="col-qr">
                                    <?php if (!empty($book['qr_code'])): ?>
                                        <div class="qr-preview-box">
                                            <a href="../generate_qr.php?book_id=<?php echo $book['id']; ?>" target="_blank" title="View / Print Shelf QR Code">
                                                <img
                                                    src="../<?php echo htmlspecialchars($book['qr_code']); ?>"
                                                    alt="QR Code"
                                                    width="32"
                                                    height="32"
                                                    class="qr-thumb-img"
                                                >
                                            </a>
                                            <div class="qr-token-label">
                                                book_<?php echo (int)$book['id']; ?>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <a
                                            href="../generate_qr.php?book_id=<?php echo $book['id']; ?>"
                                            target="_blank"
                                            class="qr-generate-link"
                                        >
                                            <i data-heroicon="qr-code"></i> Generate
                                        </a>
                                    <?php endif; ?>
                                </td>

                                <td class="col-status">
                                    <?php if ($book['status'] === 'Available'): ?>
                                        <span class="available">
                                            <i data-heroicon="check-circle"></i> Available
                                        </span>
                                    <?php else: ?>
                                        <span class="borrowed">
                                            <i data-heroicon="clock"></i> Borrowed
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td class="col-action">
                                    <div class="table-actions-cell">
                                        <button
                                            type="button"
                                            class="edit-button"
                                            onclick='openEditBookModal(<?php echo htmlspecialchars(json_encode([
                                                "id" => (int)$book["id"],
                                                "title" => $book["title"],
                                                "author" => $book["author"],
                                                "category" => $book["category"] ?? "",
                                                "isbn" => $book["isbn"] ?? "",
                                                "image" => $book["image"] ?? "",
                                                "imageUrl" => (!empty($book["image"]) && file_exists(__DIR__ . "/../" . $book["image"])) ? "../" . htmlspecialchars($book["image"]) : ""
                                            ]), ENT_QUOTES, "UTF-8"); ?>)'
                                            title="Edit book details and cover"
                                        >
                                            <i data-heroicon="pencil-square"></i> Edit
                                        </button>

                                        <?php if ($book['status'] === 'Available'): ?>
                                            <form method="POST" style="margin: 0;">
                                                <input
                                                    type="hidden"
                                                    name="book_id"
                                                    value="<?php echo $book['id']; ?>"
                                                >
                                                <button
                                                    type="submit"
                                                    name="delete_book"
                                                    class="delete-button"
                                                    onclick="return confirm('Delete this book?');"
                                                    title="Delete book"
                                                >
                                                    <i data-heroicon="trash"></i> Delete
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="active-borrow-badge" title="Book is currently borrowed by a customer">
                                                <i data-heroicon="lock-closed"></i> Borrowed
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="9" style="text-align: center; padding: 40px 16px; color: #6b625b;">
                                <i data-heroicon="<?php echo (!empty($search) || !empty($status_filter)) ? 'magnifying-glass' : 'book-open'; ?>" style="font-size: 32px; opacity: 0.4; margin-bottom: 10px; display: inline-block;"></i>
                                <div style="font-size: 14.5px; font-weight: 600; color: #2e2620; margin-bottom: 4px;">
                                    <?php if (!empty($search) || !empty($status_filter)): ?>
                                        No matching books found
                                    <?php else: ?>
                                        No books found in inventory
                                    <?php endif; ?>
                                </div>
                                <div style="font-size: 13px; color: #8b7d72;">
                                    <?php if (!empty($search) || !empty($status_filter)): ?>
                                        Try adjusting your search query or status filter tab.
                                        <div style="margin-top: 10px;">
                                            <a href="books.php" class="clear-search-btn" style="min-height: 32px; padding: 6px 14px; font-size: 12.5px;">
                                                <i data-heroicon="x-mark"></i> Clear Filters
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        Click "Add New Book" above to populate the library catalog.
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>


</main>

    </div>

</div>

<script src="../heroicons.js?v=<?= @filemtime(__DIR__ . '/../heroicons.js') ?: time() ?>"></script>
<script>
    heroicons.createIcons();
    (function () {
        var toggle = document.getElementById('adminBurgerBtn');
        var sidebar = document.querySelector('.admin-sidebar');
        if (!toggle || !sidebar) return;
        document.addEventListener('click', function (e) {
            if (sidebar.classList.contains('nav-open') && !sidebar.contains(e.target)) {
                sidebar.classList.remove('nav-open');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
    })();

    function previewCoverImage(input, prefix) {
        if (input.files && input.files[0]) {
            var file = input.files[0];
            if (!file.type.match(/^image\//)) {
                alert('Please select a valid image file (PNG, JPG, WEBP, or GIF).');
                input.value = '';
                return;
            }
            var reader = new FileReader();
            reader.onload = function (e) {
                var img = document.getElementById(prefix + 'CoverPreviewImg');
                var wrap = document.getElementById(prefix + 'CoverPreviewWrap');
                var prompt = document.getElementById(prefix + 'CoverPrompt');
                if (img && wrap && prompt) {
                    img.src = e.target.result;
                    wrap.style.display = 'flex';
                    prompt.style.display = 'none';
                    if (window.heroicons) heroicons.createIcons({ root: wrap });
                }
                if (prefix === 'edit') {
                    var removeInput = document.getElementById('editRemoveImage');
                    if (removeInput) removeInput.value = '0';
                }
            };
            reader.readAsDataURL(file);
        }
    }

    function clearCoverUpload(prefix) {
        var input = document.getElementById(prefix + '-book-cover');
        var img = document.getElementById(prefix + 'CoverPreviewImg');
        var wrap = document.getElementById(prefix + 'CoverPreviewWrap');
        var prompt = document.getElementById(prefix + 'CoverPrompt');
        if (input) input.value = '';
        if (img) img.src = '';
        if (wrap) wrap.style.display = 'none';
        if (prompt) prompt.style.display = 'flex';
        if (prefix === 'edit') {
            var removeInput = document.getElementById('editRemoveImage');
            if (removeInput) removeInput.value = '1';
        }
    }

    function openAddBookModal() {
        var modal = document.getElementById('addBookModal');
        if (!modal) return;
        modal.classList.add('is-active');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        setTimeout(function () {
            var input = document.getElementById('book-title');
            if (input) input.focus();
            if (window.heroicons) heroicons.createIcons({ root: modal });
        }, 50);
    }

    function closeAddBookModal() {
        var modal = document.getElementById('addBookModal');
        if (!modal) return;
        modal.classList.remove('is-active');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    function openEditBookModal(book) {
        var modal = document.getElementById('editBookModal');
        if (!modal) return;

        document.getElementById('edit-book-id').value = book.id || '';
        document.getElementById('edit-book-title').value = book.title || '';
        document.getElementById('edit-book-author').value = book.author || '';
        document.getElementById('edit-book-category').value = book.category || '';
        document.getElementById('edit-book-isbn').value = book.isbn || '';
        document.getElementById('editRemoveImage').value = '0';

        var fileInput = document.getElementById('edit-book-cover');
        if (fileInput) fileInput.value = '';

        var previewImg = document.getElementById('editCoverPreviewImg');
        var previewWrap = document.getElementById('editCoverPreviewWrap');
        var promptBox = document.getElementById('editCoverPrompt');

        if (book.imageUrl) {
            previewImg.src = book.imageUrl;
            previewWrap.style.display = 'flex';
            promptBox.style.display = 'none';
        } else {
            previewImg.src = '';
            previewWrap.style.display = 'none';
            promptBox.style.display = 'flex';
        }

        modal.classList.add('is-active');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';

        setTimeout(function () {
            var input = document.getElementById('edit-book-title');
            if (input) input.focus();
            if (window.heroicons) heroicons.createIcons({ root: modal });
        }, 50);
    }

    function closeEditBookModal() {
        var modal = document.getElementById('editBookModal');
        if (!modal) return;
        modal.classList.remove('is-active');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeAddBookModal();
            closeEditBookModal();
        }
    });

    <?php if ($message != "" && isset($_POST['add_book']) && $message_type === 'error'): ?>
    document.addEventListener('DOMContentLoaded', function () {
        openAddBookModal();
    });
    <?php endif; ?>
</script>
</body>

</html>

