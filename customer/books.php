<?php
session_start();

require_once "../database.php";

$logo_url = pagelounge_logo_url();
$search = trim($_GET['search'] ?? '');
$is_customer_logged_in = isset($_SESSION['customer_id']);

// Dynamically refresh customer details to reflect librarian approvals/updates in real-time
if ($is_customer_logged_in) {
    $cust_id = (int)$_SESSION['customer_id'];
    $cust_stmt = $conn->prepare("SELECT name, email, contact, account_status FROM customers WHERE id = ?");
    if ($cust_stmt) {
        $cust_stmt->bind_param("i", $cust_id);
        $cust_stmt->execute();
        $fresh_cust = $cust_stmt->get_result()->fetch_assoc();
        if ($fresh_cust) {
            $_SESSION['customer_name'] = $fresh_cust['name'];
            $_SESSION['customer_email'] = $fresh_cust['email'];
            $_SESSION['account_status'] = $fresh_cust['account_status'];
        }
    }
}

// Handle Customer Return Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_return') {
    if (!$is_customer_logged_in) {
        header("Location: login.php");
        exit;
    }
    $borrowing_id = filter_var($_POST['borrowing_id'] ?? null, FILTER_VALIDATE_INT);
    $cust_id = (int)$_SESSION['customer_id'];
    if ($borrowing_id) {
        $stmt = $conn->prepare("UPDATE borrowings SET status = 'Pending Return' WHERE id = ? AND customer_id = ? AND status = 'Borrowed'");
        $stmt->bind_param("ii", $borrowing_id, $cust_id);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            $_SESSION['return_notice'] = "Return requested! Please bring the book to the librarian desk for verification.";
        }
    }
    header("Location: books.php#myLoans");
    exit;
}

$return_notice = $_SESSION['return_notice'] ?? '';
unset($_SESSION['return_notice']);

// Fetch active borrowings for logged-in customer
$customer_active_borrowings = [];
if ($is_customer_logged_in) {
    $cust_id = (int)$_SESSION['customer_id'];
    $b_stmt = $conn->prepare("
        SELECT b.id AS borrowing_id, b.borrow_date, b.due_date, b.status AS borrowing_status,
               bk.id AS book_id, bk.title, bk.author, bk.category, bk.image
        FROM borrowings b
        JOIN books bk ON b.book_id = bk.id
        WHERE b.customer_id = ? AND b.status IN ('Borrowed', 'Pending Return')
        ORDER BY b.borrow_date DESC
    ");
    $b_stmt->bind_param("i", $cust_id);
    $b_stmt->execute();
    $b_res = $b_stmt->get_result();
    if ($b_res) {
        while ($b_row = $b_res->fetch_assoc()) {
            $customer_active_borrowings[] = $b_row;
        }
    }
}

// Handle AJAX Book Token/ID Lookup
if (isset($_GET['action']) && $_GET['action'] === 'lookup') {
    header('Content-Type: application/json; charset=utf-8');
    $token = trim($_GET['token'] ?? '');

    if ($token === '') {
        echo json_encode(['ok' => false, 'error' => 'Please provide a QR token, Book ID, or ISBN.']);
        exit;
    }

    $book_id = null;
    // Extract ID from full URL (e.g. ...borrow.php?book_id=5) or token (e.g. book_5 or 5)
    if (preg_match('/book_id=(\d+)/i', $token, $matches)) {
        $book_id = (int)$matches[1];
    } elseif (preg_match('/^book_?(\d+)/i', $token, $matches)) {
        $book_id = (int)$matches[1];
    } elseif (ctype_digit($token)) {
        $book_id = (int)$token;
    }

    $book = null;
    if ($book_id !== null) {
        $stmt = $conn->prepare("SELECT id, title, author, category, isbn, image, status, total_copies, available_copies FROM books WHERE id = ?");
        $stmt->bind_param("i", $book_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $book = $res->fetch_assoc();
        }
    }

    // Lookup by ISBN if not matched by ID
    if (!$book) {
        $cleanIsbn = preg_replace('/[^0-9X]/i', '', $token);
        $stmt = $conn->prepare("SELECT id, title, author, category, isbn, image, status, total_copies, available_copies FROM books WHERE isbn = ? OR REPLACE(isbn, '-', '') = ?");
        $stmt->bind_param("ss", $token, $cleanIsbn);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $book = $res->fetch_assoc();
        }
    }

    // Lookup by stored QR path if applicable
    if (!$book) {
        $likeToken = "%" . $token . "%";
        $stmt = $conn->prepare("SELECT id, title, author, category, isbn, image, status, total_copies, available_copies FROM books WHERE qr_code LIKE ?");
        $stmt->bind_param("s", $likeToken);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $book = $res->fetch_assoc();
        }
    }

    // Lookup by Title match
    if (!$book) {
        $likeTitle = "%" . $token . "%";
        $stmt = $conn->prepare("SELECT id, title, author, category, isbn, image, status, total_copies, available_copies FROM books WHERE title LIKE ? LIMIT 1");
        $stmt->bind_param("s", $likeTitle);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $book = $res->fetch_assoc();
        }
    }

    if ($book) {
        $imgUrl = (!empty($book['image']) && file_exists(__DIR__ . '/../' . $book['image']))
            ? '../' . $book['image']
            : null;

        echo json_encode([
            'ok' => true,
            'book' => [
                'id' => (int)$book['id'],
                'title' => $book['title'],
                'author' => $book['author'],
                'category' => $book['category'] ?: 'General',
                'isbn' => $book['isbn'] ?: 'N/A',
                'image' => $imgUrl,
                'status' => $book['status'],
                'total_copies' => (int)($book['total_copies'] ?? 1),
                'available_copies' => (int)($book['available_copies'] ?? 1),
                'is_available' => ((int)($book['available_copies'] ?? 1) > 0),
            ]
        ]);
    } else {
        echo json_encode([
            'ok' => false,
            'error' => 'No book found matching "' . htmlspecialchars($token, ENT_QUOTES) . '". Check the QR token or Book ID.'
        ]);
    }
    exit;
}

// Redirect any legacy borrow POST submissions to the customer checkout flow
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'borrow') {
    $book_id = filter_var($_POST['book_id'] ?? null, FILTER_VALIDATE_INT);
    if (!isset($_SESSION['customer_id'])) {
        header("Location: login.php" . ($book_id ? "?redirect=borrow&book_id=" . $book_id : ""));
        exit;
    }
    header("Location: borrow.php" . ($book_id ? "?book_id=" . $book_id : ""));
    exit;
}

// Search Query
if ($search !== '') {
    $searchTerm = "%" . $search . "%";
    $sql = "SELECT * FROM books
            WHERE title LIKE ?
            OR author LIKE ?
            OR category LIKE ?
            OR isbn LIKE ?
            ORDER BY title ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssss", $searchTerm, $searchTerm, $searchTerm, $searchTerm);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $sql = "SELECT * FROM books ORDER BY title ASC";
    $result = $conn->query($sql);
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Books - Page Lounge</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Alex+Brush&family=Playfair+Display:ital,wght@0,400..700;1,400..700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../style.css">
    <style>
        .books-page {
            padding: clamp(20px, 4vw, 50px) 20px;
            max-width: 1140px;
            margin: 0 auto;
        }

        .books-page h1,
        .browse-catalog-header h1 {
            text-align: center;
            margin-bottom: 8px;
            font-size: 32px;
            color: #241e1a;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .books-page > p,
        .browse-catalog-header p {
            text-align: center;
            color: #6b625b;
            margin-bottom: 20px;
            font-size: 16px;
        }

        .browse-catalog-header {
            margin: 12px auto 6px;
            max-width: 820px;
        }

        .catalog-section-divider {
            max-width: 820px;
            margin: 44px auto 28px;
            display: flex;
            align-items: center;
            gap: 16px;
            width: 100%;
        }

        .catalog-section-divider::before,
        .catalog-section-divider::after {
            content: "";
            flex: 1;
            border-bottom: 1px solid #d5cbbe;
        }

        .catalog-section-divider span {
            font-size: 11.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            color: #7a6e64;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 14px;
            background: #fbf8f4;
            border: 1px solid #dcd3c7;
            border-radius: 20px;
            box-shadow: 0 1px 3px rgba(46, 38, 32, 0.04);
            white-space: nowrap;
        }

        .catalog-section-divider span svg {
            width: 14px;
            height: 14px;
            color: #8c7355;
        }

        /* Top Action Section: Unified Single-Line Search & Quick Lookup */
        .unified-search-form {
            max-width: 820px;
            margin: 0 auto 36px;
            width: 100%;
        }

        .unified-search-bar {
            display: flex !important;
            flex-direction: row !important;
            flex-wrap: nowrap !important;
            align-items: center;
            gap: 8px;
            width: 100%;
            box-sizing: border-box;
        }

        .btn-qr-icon-only {
            flex: 0 0 46px;
            width: 46px;
            height: 46px;
            min-height: 46px;
            min-width: 46px;
            background: #2e2620;
            color: #ffffff;
            border: 1px solid #241e1a;
            border-radius: 6px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            transition: background 0.15s ease;
            box-sizing: border-box;
        }

        .btn-qr-icon-only:hover {
            background: #443830;
        }

        .btn-qr-icon-only svg {
            width: 22px;
            height: 22px;
        }

        .unified-search-input {
            flex: 1 1 auto;
            min-width: 0;
            height: 46px;
            min-height: 46px;
            padding: 10px 14px;
            border: 1px solid #d5cbbe;
            border-radius: 6px;
            font-size: 16px;
            background: #ffffff;
            color: #2e2620;
            outline: none;
            box-sizing: border-box;
            transition: border-color 0.15s ease, outline 0.15s ease;
        }

        .unified-search-input:focus {
            border-color: #2e2620;
            outline: 2px solid rgba(46, 38, 32, 0.2);
            outline-offset: 1px;
        }

        .unified-search-btn {
            flex: 0 0 auto;
            background: #2e2620;
            color: white;
            border: 1px solid #241e1a;
            padding: 0 18px;
            height: 46px;
            min-height: 46px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            white-space: nowrap;
            box-sizing: border-box;
            transition: background 0.15s ease;
        }

        .unified-search-btn:hover {
            background: #443830;
        }

        .unified-clear-btn {
            flex: 0 0 46px;
            width: 46px;
            height: 46px;
            min-height: 46px;
            min-width: 46px;
            background: #f4efe8;
            color: #2e2620;
            border: 1px solid #d5cbbe;
            padding: 0;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            box-sizing: border-box;
            transition: background 0.15s ease;
        }

        .unified-clear-btn:hover {
            background: #eae4db;
        }

        /* Banner Flash Message */
        .page-banner {
            max-width: 820px;
            margin: 0 auto 24px;
            padding: 14px 18px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            font-weight: 500;
        }

        .page-banner.success {
            background: #edf7ed;
            color: #1e6b24;
            border: 1px solid #c8e6c9;
        }

        .page-banner.error {
            background: #fdeeed;
            color: #c62828;
            border: 1px solid #ffcdd2;
        }

        /* Books Grid & Glassmorphism Cards */
        .books-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 24px;
            margin: auto;
        }

        .book-card {
            position: relative;
            height: 440px;
            border-radius: 14px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            background: #241e1a;
            border: 1px solid rgba(229, 223, 213, 0.7);
            box-shadow: 0 8px 24px rgba(46, 38, 32, 0.12);
            transition: transform 0.25s cubic-bezier(0.2, 0, 0, 1), box-shadow 0.25s cubic-bezier(0.2, 0, 0, 1), border-color 0.25s ease;
            padding: 0 !important;
            box-sizing: border-box;
        }

        .book-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 14px 32px rgba(46, 38, 32, 0.22);
            border-color: #d8c29d;
        }

        .book-card-cover-bg {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
            z-index: 1;
            transition: transform 0.4s ease;
        }

        .book-card:hover .book-card-cover-bg {
            transform: scale(1.05);
        }

        .book-card-placeholder-bg {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(145deg, #3d322b 0%, #201915 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding-bottom: 120px;
            color: #d8c29d;
            z-index: 1;
        }

        .book-card-placeholder-bg svg {
            width: 64px;
            height: 64px;
            opacity: 0.35;
            margin-bottom: 8px;
        }

        .book-card-placeholder-bg span {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            opacity: 0.5;
        }

        .book-card-vignette {
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, rgba(14, 10, 8, 0.1) 0%, rgba(14, 10, 8, 0.2) 40%, rgba(14, 10, 8, 0.65) 70%, rgba(14, 10, 8, 0.92) 100%);
            z-index: 2;
            pointer-events: none;
        }

        .book-card-status-badge {
            position: absolute;
            top: 14px;
            right: 14px;
            z-index: 4;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
            letter-spacing: 0.4px;
            text-transform: uppercase;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
        }

        .book-card-status-badge.available {
            background: rgba(237, 247, 237, 0.92);
            color: #1e6b24 !important;
            border: 1px solid rgba(200, 230, 201, 0.9);
        }

        .book-card-status-badge.borrowed {
            background: rgba(255, 248, 225, 0.92);
            color: #8a5200 !important;
            border: 1px solid rgba(255, 224, 130, 0.9);
        }

        .book-card-overlay {
            position: relative;
            z-index: 3;
            padding: 12px 14px 14px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            background: transparent;
            box-sizing: border-box;
        }

        /* Title & Meta: Clean direct overlay on book cover with shadow (NO box background) */
        .book-card-title-box {
            background: transparent !important;
            border: none !important;
            padding: 0 !important;
            margin: 0 !important;
            box-shadow: none !important;
            backdrop-filter: none !important;
            -webkit-backdrop-filter: none !important;
        }

        .book-card-glass-title,
        .book-card-title-text {
            margin: 0;
            font-family: var(--font-serif);
            font-size: 15px;
            font-weight: 700;
            color: #ffffff;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.9), 0 2px 8px rgba(0, 0, 0, 0.7);
            line-height: 1.3;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .book-card-meta-box {
            background: transparent !important;
            border: none !important;
            padding: 0 !important;
            margin: 0 !important;
            box-shadow: none !important;
            backdrop-filter: none !important;
            -webkit-backdrop-filter: none !important;
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .meta-item-row {
            display: flex;
            align-items: baseline;
            gap: 4px;
            font-size: 11.5px;
            line-height: 1.25;
            color: #f4efe8;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.9);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .meta-item-row .meta-label {
            font-weight: 700;
            color: #d8c29d;
            font-size: 11px;
        }

        .meta-item-row .meta-val {
            overflow: hidden;
            text-overflow: ellipsis;
            color: #ffffff;
        }

        .glass-meta-pills,
        .book-meta-inline {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            color: #d8c29d;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.9);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            background: transparent !important;
            border: none !important;
            padding: 0 !important;
        }

        .glass-category-pill,
        .book-category-pill {
            background: transparent !important;
            border: none !important;
            padding: 0 !important;
            color: #d8c29d !important;
            font-size: 11px !important;
            font-weight: 600 !important;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.9) !important;
        }

        .glass-category-pill::after {
            content: ' •';
            color: rgba(255, 255, 255, 0.4);
            margin-left: 6px;
        }

        .glass-isbn-code,
        .book-isbn-code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 11px;
            color: #e5dfd5;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.9);
            background: transparent !important;
            border: none !important;
            padding: 0 !important;
        }

        .book-card-action,
        .glass-card-action {
            display: flex;
            margin-top: 4px;
        }

        /* ONLY the button has the box background */
        .glass-borrow-btn,
        .book-card-borrow-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            width: 100%;
            min-height: 36px;
            padding: 8px 14px;
            background: #2e2620;
            color: #ffffff !important;
            font-weight: 600;
            font-size: 12.5px;
            cursor: pointer;
            box-sizing: border-box;
            border-radius: 6px;
            border: 1px solid #483b32;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.4);
            backdrop-filter: none !important;
            -webkit-backdrop-filter: none !important;
            text-decoration: none;
            transition: background 0.15s ease, transform 0.1s ease, border-color 0.15s ease;
        }

        .glass-borrow-btn:hover,
        .book-card-borrow-btn:hover {
            background: #44372e;
            border-color: #d8c29d;
            color: #ffffff !important;
            text-decoration: none;
            transform: translateY(-1px);
        }

        .glass-unavailable-btn,
        .book-card-unavailable-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            min-height: 36px;
            padding: 8px 14px;
            text-align: center;
            color: #a89f91 !important;
            font-size: 12px;
            font-weight: 600;
            background: rgba(30, 24, 20, 0.85);
            border-radius: 6px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            box-sizing: border-box;
            backdrop-filter: none !important;
            -webkit-backdrop-filter: none !important;
        }

        .back-button-wrap {
            text-align: center;
            margin-top: 40px;
        }

        .back-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 10px 18px;
            text-decoration: none;
            color: #2e2620;
            font-weight: 600;
            gap: 6px;
        }

        .back-button:hover {
            text-decoration: underline;
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
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.28);
            transform: translateY(12px) scale(0.98);
            transition: transform 0.2s ease;
            box-sizing: border-box;
        }

        .modal-overlay.is-active .modal-dialog {
            transform: translateY(0) scale(1);
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 22px;
            border-bottom: 1px solid #eae4db;
            background: #faf7f3;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 19px;
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
            padding: 6px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            transition: background 0.15s ease, color 0.15s ease;
        }

        .modal-close-btn:hover {
            background: #eae4db;
            color: #241e1a;
        }

        .modal-body {
            padding: 20px;
        }

        #scannerModal .modal-dialog {
            max-width: 440px;
        }

        .scanner-help-text {
            color: #6b625b;
            font-size: 14px;
            margin: 0 auto 16px;
            text-align: center;
            line-height: 1.45;
            max-width: 340px;
        }

        /* QR Scanner Camera Box */
        .scanner-view-wrapper {
            position: relative;
            background: #140e0a;
            border-radius: 8px;
            overflow: hidden;
            width: 100%;
            max-width: 340px;
            aspect-ratio: 1 / 1;
            margin: 0 auto 18px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .qr-reader-box {
            width: 100% !important;
            height: 100% !important;
            border: none !important;
        }

        .qr-reader-box video {
            width: 100% !important;
            height: 100% !important;
            object-fit: cover !important;
        }

        /* Single native viewfinder styling */
        #qr-shaded-region > div {
            background-color: #d8c29d !important;
            border-color: #d8c29d !important;
            border-radius: 2px !important;
        }

        .scanner-idle-placeholder {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #f4efe8;
            gap: 8px;
            background: #23160d;
            text-align: center;
            padding: 20px;
        }

        .scanner-idle-placeholder span {
            font-weight: 600;
            font-size: 15px;
        }

        .scanner-idle-placeholder small {
            color: #b5a99d;
            font-size: 13px;
        }

        .scanner-controls {
            display: flex;
            gap: 10px;
            justify-content: center;
            width: 100%;
            max-width: 320px;
            margin: 0 auto;
        }

        .scanner-btn-primary {
            background: #2e2620;
            color: white;
            border: 1px solid #241e1a;
            padding: 11px 18px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-height: 44px;
            width: 100%;
            transition: background 0.15s ease;
        }

        .scanner-btn-primary:hover {
            background: #443830;
        }

        .scanner-btn-secondary {
            background: #f4efe8;
            color: #2e2620;
            border: 1px solid #d5cbbe;
            padding: 11px 18px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-height: 44px;
            width: 100%;
            transition: background 0.15s ease;
        }

        .scanner-btn-secondary:hover {
            background: #eae4db;
        }

        .scanner-feedback-msg {
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 13px;
            text-align: center;
            margin-bottom: 14px;
            font-weight: 500;
        }

        .scanner-feedback-msg.error {
            background: #fdeeed;
            color: #c62828;
            border: 1px solid #ffcdd2;
        }

        .scanner-feedback-msg.info {
            background: #f4efe8;
            color: #2e2620;
            border: 1px solid #d5cbbe;
        }

        /* CUSTOMER ACCOUNT & PROFILE HEADER */
        .customer-header-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .customer-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 4px 12px 4px 6px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 20px;
            color: #f5eedf;
            font-size: 13.5px;
        }

        .customer-pill .customer-pill-avatar {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: rgba(216, 194, 157, 0.22);
            border: 1px solid rgba(216, 194, 157, 0.38);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: #f5eedf;
        }

        .customer-pill .customer-pill-avatar svg,
        .customer-pill svg {
            width: 13px;
            height: 13px;
            color: #f5eedf;
            display: block;
        }

        .customer-pill .customer-name {
            font-weight: 600;
            color: #ffffff;
            max-width: 160px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .customer-pill .pill-badge {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 10px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .customer-pill .pill-badge.verified {
            background: #10b981;
            color: #ffffff;
        }

        .customer-pill .pill-badge.pending {
            background: #f59e0b;
            color: #ffffff;
        }

        .customer-pill .pill-badge.rejected {
            background: #ef4444;
            color: #ffffff;
        }

        .customer-logout-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 12px;
            font-size: 12.5px;
            font-weight: 600;
            color: #f5eedf;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 6px;
            text-decoration: none;
            cursor: pointer;
            font-family: inherit;
            transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
        }

        .customer-logout-btn:hover {
            background: rgba(239, 68, 68, 0.2);
            border-color: rgba(239, 68, 68, 0.4);
            color: #fca5a5;
        }

        #cancelLogoutBtn:hover {
            background: #e8ded1 !important;
        }

        #confirmLogoutLink:hover {
            background: #b91c1c !important;
        }

        .customer-auth-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-auth-login {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            font-size: 13px;
            font-weight: 600;
            color: #ffffff;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.22);
            border-radius: 6px;
            text-decoration: none;
            transition: background 0.15s ease, border-color 0.15s ease;
        }

        .btn-auth-login:hover {
            background: rgba(255, 255, 255, 0.16);
            border-color: rgba(255, 255, 255, 0.35);
        }

        .btn-auth-register {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            font-size: 13px;
            font-weight: 700;
            color: #23160d;
            background: #e8ded1;
            border: 1px solid #d5cbbe;
            border-radius: 6px;
            text-decoration: none;
            transition: background 0.15s ease;
        }

        .btn-auth-register:hover {
            background: #ffffff;
        }

        .btn-librarian-login {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            font-size: 13px;
            font-weight: 600;
            color: #d8c29d;
            background: rgba(216, 194, 157, 0.12);
            border: 1px solid rgba(216, 194, 157, 0.3);
            border-radius: 6px;
            text-decoration: none;
            transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
        }

        .btn-librarian-login svg {
            width: 14px;
            height: 14px;
            color: #d8c29d;
        }

        .btn-librarian-login:hover {
            background: rgba(216, 194, 157, 0.24);
            border-color: rgba(216, 194, 157, 0.55);
            color: #ffffff;
        }

        .btn-librarian-login:hover svg {
            color: #ffffff;
        }

        /* CUSTOMER ACCOUNT HUB BANNER (MAIN VIEW) */
        .customer-account-hub {
            max-width: 820px;
            margin: 0 auto 28px;
            background: #ffffff;
            border: 1px solid #e5dfd5;
            border-radius: 8px;
            padding: 18px 22px;
            box-shadow: 0 4px 14px rgba(46, 38, 32, 0.04);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }

        .customer-account-hub.status-pending {
            border-color: #fde68a;
            background: #fffdf5;
        }

        .customer-account-hub.status-rejected {
            border-color: #fecaca;
            background: #fff8f8;
        }

        .hub-left {
            display: flex;
            align-items: center;
            gap: 14px;
            flex: 1;
            min-width: 260px;
        }

        .hub-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: #f4efe8;
            border: 1px solid #dfd7cc;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #5a4a3e;
            flex-shrink: 0;
        }

        .hub-avatar svg {
            width: 24px;
            height: 24px;
        }

        .hub-details {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .hub-name-row {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .hub-customer-name {
            font-size: 16px;
            font-weight: 700;
            color: #241e1a;
            margin: 0;
        }

        .hub-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 12px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .hub-status-badge.verified {
            background: #def7ec;
            color: #03543f;
            border: 1px solid #bcf0da;
        }

        .hub-status-badge.pending {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        .hub-status-badge.rejected {
            background: #fde8e8;
            color: #9b1c1c;
            border: 1px solid #f8b4b4;
        }

        .hub-caption {
            font-size: 13px;
            color: #6b625b;
            margin: 0;
            line-height: 1.4;
        }

        .hub-right {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .hub-loan-metric {
            background: #fbf9f6;
            border: 1px solid #e5dfd5;
            padding: 8px 16px;
            border-radius: 6px;
            text-align: center;
            min-width: 95px;
        }

        .hub-loan-metric .metric-val {
            display: block;
            font-size: 18px;
            font-weight: 800;
            color: #241e1a;
            line-height: 1.1;
        }

        .hub-loan-metric .metric-lbl {
            font-size: 11px;
            color: #7a6e64;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        /* MY BORROWED BOOKS SECTION */
        .my-loans-container {
            max-width: 820px;
            margin: 0 auto 32px;
            background: #ffffff;
            border: 1px solid #e5dfd5;
            border-radius: 8px;
            padding: 22px 24px;
            box-shadow: 0 4px 14px rgba(46, 38, 32, 0.04);
        }

        .my-loans-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 8px;
            padding-bottom: 12px;
            border-bottom: 1px solid #eee7de;
        }

        .my-loans-title {
            font-size: 17px;
            font-weight: 700;
            color: #241e1a;
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
        }

        .my-loans-count-chip {
            background: #2e2620;
            color: #ffffff;
            padding: 2px 7px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 700;
        }

        .my-loans-subtitle {
            font-size: 12.5px;
            color: #6b625b;
        }

        .my-loans-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 16px;
        }

        .loan-item-card {
            border: 1px solid #eee7de;
            border-radius: 8px;
            padding: 14px;
            background: #fbf9f6;
            display: flex;
            gap: 14px;
            align-items: flex-start;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .loan-item-card:hover {
            border-color: #d5cbbe;
            box-shadow: 0 2px 8px rgba(46, 38, 32, 0.06);
        }

        .loan-cover-cell {
            flex: 0 0 60px;
            width: 60px;
            height: 84px;
            border-radius: 4px;
            overflow: hidden;
            border: 1px solid #dcd4c8;
            background: #ede6dc;
            box-shadow: 0 1px 4px rgba(46, 38, 32, 0.08);
        }

        .loan-cover-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .loan-cover-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #8c827a;
        }

        .loan-cover-placeholder svg {
            width: 28px;
            height: 28px;
            opacity: 0.6;
        }

        .loan-info-cell {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 84px;
        }

        .loan-book-title {
            font-size: 15px;
            font-weight: 700;
            color: #241e1a;
            margin: 0 0 2px 0;
            line-height: 1.3;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .loan-book-author {
            font-size: 13px;
            color: #6b625b;
            margin: 0 0 8px 0;
        }

        .loan-meta-row {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }

        .loan-badge-due {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11.5px;
            padding: 2.5px 8px;
            border-radius: 4px;
            font-weight: 600;
        }

        .loan-badge-due.borrowed {
            background: #fff8e1;
            color: #8a5200;
            border: 1px solid #ffe082;
        }

        .loan-badge-due.overdue {
            background: #fdeeed;
            color: #c62828;
            border: 1px solid #ffcdd2;
        }

        .loan-badge-due.pending-return {
            background: #e0f2fe;
            color: #0369a1;
            border: 1px solid #bae6fd;
        }

        .btn-loan-return-action {
            width: 100%;
            min-height: 34px;
            padding: 6px 12px;
            font-size: 12.5px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            background: #2e2620;
            color: #ffffff;
            border: 1px solid #241e1a;
            border-radius: 5px;
            cursor: pointer;
            transition: background 0.15s ease;
        }

        .btn-loan-return-action:hover {
            background: #443830;
        }

        .loan-pending-indicator {
            font-size: 11.5px;
            color: #0284c7;
            background: #f0f9ff;
            border: 1px dashed #bae6fd;
            padding: 6px 10px;
            border-radius: 4px;
            text-align: center;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }

        @media (max-width: 640px) {
            .customer-header-wrap {
                gap: 6px;
            }

            .customer-auth-actions {
                gap: 5px;
            }

            .btn-auth-login,
            .btn-auth-register,
            .btn-librarian-login {
                padding: 5px 9px;
                font-size: 11.5px;
            }

            .customer-pill .customer-name {
                max-width: 95px;
            }

            .customer-pill {
                padding: 4px 8px;
                font-size: 12px;
            }

            .customer-logout-btn {
                padding: 5px 8px;
                font-size: 11.5px;
            }

            .customer-account-hub {
                padding: 14px 16px;
                flex-direction: column;
                align-items: flex-start;
            }

            .hub-right {
                width: 100%;
                justify-content: flex-start;
            }

            .hub-loan-metric {
                width: 100%;
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 8px 12px;
            }

            .my-loans-container {
                padding: 16px 14px;
            }

            .my-loans-grid {
                grid-template-columns: 1fr;
            }

            .catalog-section-divider {
                margin: 32px auto 20px;
                gap: 10px;
            }

            .catalog-section-divider span {
                font-size: 10.5px;
                padding: 4px 10px;
                letter-spacing: 0.8px;
            }

            .unified-search-bar {
                gap: 6px;
            }

            .unified-search-btn .search-btn-label {
                display: none;
            }

            .unified-search-btn {
                padding: 0;
                width: 44px;
                min-width: 44px;
                height: 44px;
                min-height: 44px;
            }

            .btn-qr-icon-only,
            .unified-clear-btn {
                flex-basis: 44px;
                width: 44px;
                min-width: 44px;
                height: 44px;
                min-height: 44px;
            }

            .unified-search-input {
                height: 44px;
                min-height: 44px;
                padding: 8px 10px;
                font-size: 16px;
            }

            /* Responsive Modal Layout */
            .modal-overlay {
                padding: 12px;
                align-items: center;
                justify-content: center;
                -webkit-overflow-scrolling: touch;
            }

            .modal-dialog {
                width: 100%;
                max-width: 100%;
                max-height: calc(100dvh - 24px);
                border-radius: 8px;
            }

            #scannerModal .modal-dialog {
                max-width: 360px;
                margin: auto;
            }

            .modal-header {
                padding: 12px 16px;
            }

            .modal-header h2 {
                font-size: 16px;
                gap: 6px;
            }

            .modal-close-btn {
                width: 44px;
                height: 44px;
                min-width: 44px;
                min-height: 44px;
            }

            .modal-body {
                padding: 14px 16px 18px;
            }

            .scanner-help-text {
                font-size: 13px;
                margin-bottom: 12px;
            }

            .scanner-view-wrapper {
                width: 100%;
                max-width: 300px;
                aspect-ratio: 1 / 1;
                margin: 0 auto 14px;
            }

            .scanner-controls {
                width: 100%;
                max-width: 300px;
                margin: 0 auto;
                display: flex;
                justify-content: center;
            }

            .scanner-btn-primary,
            .scanner-btn-secondary {
                width: 100%;
                justify-content: center;
                min-height: 44px;
                padding: 11px 16px;
                font-size: 14px;
            }

            .scanner-feedback-msg {
                margin-top: 12px;
                margin-bottom: 0;
                font-size: 13px;
                padding: 10px 12px;
            }

            #borrowModal .modal-dialog {
                max-width: 440px;
                margin: auto;
            }

            .borrow-book-card {
                padding: 12px 14px;
                margin-bottom: 14px;
            }

            .borrow-book-title {
                font-size: 17px;
                margin-bottom: 6px;
            }

            .borrow-book-meta {
                grid-template-columns: 1fr;
                gap: 4px;
                font-size: 12.5px;
            }

            .borrow-policy-pill {
                padding: 10px 12px;
                margin-bottom: 14px;
                font-size: 12px;
            }

            .books-page {
                padding: 16px 12px 36px;
            }

            .books-container {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }

            .book-card {
                height: 310px;
                border-radius: 10px;
            }

            .book-card-status-badge {
                top: 8px;
                right: 8px;
                font-size: 9.5px;
                padding: 2.5px 7px;
                border-radius: 12px;
                gap: 3px;
            }

            .book-card-status-badge svg {
                width: 11px;
                height: 11px;
            }

            .badge-prefix-full {
                display: none;
            }

            .book-card-overlay {
                padding: 8px 8px 10px;
                gap: 4px;
            }

            .book-card-glass-title,
            .book-card-title-text {
                font-size: 13px;
                line-height: 1.25;
            }

            .meta-item-row {
                font-size: 10.5px;
                gap: 3px;
            }

            .meta-item-row .meta-label {
                font-size: 10px;
            }

            .glass-meta-pills,
            .book-meta-inline {
                font-size: 10px;
                gap: 4px;
            }

            .glass-category-pill,
            .book-category-pill,
            .glass-isbn-code,
            .book-isbn-code {
                font-size: 10px !important;
            }

            .glass-borrow-btn,
            .book-card-borrow-btn,
            .glass-unavailable-btn,
            .book-card-unavailable-btn {
                min-height: 32px;
                height: 32px;
                padding: 4px 6px;
                font-size: 11.5px;
                border-radius: 5px;
                gap: 4px;
            }

            .glass-borrow-btn svg,
            .book-card-borrow-btn svg {
                width: 12px;
                height: 12px;
            }

            .book-card-placeholder-bg svg {
                width: 40px;
                height: 40px;
            }

            .book-card-placeholder-bg span {
                font-size: 9px;
            }
        }

        @media (max-width: 360px) {
            .books-page {
                padding: 12px 8px 30px;
            }

            .books-container {
                gap: 8px;
            }

            .book-card {
                height: 290px;
            }

            .book-card-title-text {
                font-size: 12px;
            }

            .glass-borrow-btn,
            .book-card-borrow-btn {
                font-size: 11px;
            }
        }
    </style>
</head>

<body>

<header>
    <a href="books.php" class="brand-link">
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
    <div class="header-actions">
        <?php if ($is_customer_logged_in): ?>
            <div class="customer-header-wrap">
                <div class="customer-pill">
                    <span class="customer-pill-avatar" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                        </svg>
                    </span>
                    <span class="customer-name"><?php echo htmlspecialchars($_SESSION['customer_name']); ?></span>
                    <?php if (($_SESSION['account_status'] ?? '') === 'Approved'): ?>
                        <span class="pill-badge verified">Verified</span>
                    <?php elseif (($_SESSION['account_status'] ?? '') === 'Rejected'): ?>
                        <span class="pill-badge rejected">Rejected</span>
                    <?php else: ?>
                        <span class="pill-badge pending">Pending ID</span>
                    <?php endif; ?>
                </div>
                <button type="button" class="customer-logout-btn" id="openLogoutModalBtn" aria-label="Sign out of your account" title="Sign out of your account">
                    <i data-heroicon="arrow-right-on-rectangle"></i>
                    <span>Logout</span>
                </button>
            </div>
        <?php else: ?>
            <div class="customer-auth-actions">
                <a href="login.php" class="btn-auth-login">
                    <i data-heroicon="arrow-left-on-rectangle"></i> Log In
                </a>
                <a href="register.php" class="btn-auth-register">
                    <i data-heroicon="user-plus"></i> Register
                </a>
                <?php if (isset($_SESSION['librarian'])): ?>
                    <a href="../admin/dashboard.php" class="btn-librarian-login" title="Librarian Dashboard">
                        <i data-heroicon="squares-2x2"></i> Librarian Portal
                    </a>
                <?php else: ?>
                    <a href="../admin/login.php" class="btn-librarian-login" title="Staff &amp; Librarian Login">
                        <i data-heroicon="lock-closed"></i> Librarian Login
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</header>

<main class="books-page">

    <?php if ($is_customer_logged_in): ?>
        <?php
            $acc_status = $_SESSION['account_status'] ?? 'Pending';
            $status_class = strtolower($acc_status);
            $loans_count = count($customer_active_borrowings);
        ?>
        <section class="customer-account-hub status-<?php echo $status_class; ?>">
            <div class="hub-left">
                <div class="hub-avatar">
                    <i data-heroicon="user"></i>
                </div>
                <div class="hub-details">
                    <div class="hub-name-row">
                        <h2 class="hub-customer-name"><?php echo htmlspecialchars($_SESSION['customer_name']); ?></h2>
                        <?php if ($acc_status === 'Approved'): ?>
                            <span class="hub-status-badge verified"><i data-heroicon="check-circle"></i> Verified Account</span>
                        <?php elseif ($acc_status === 'Rejected'): ?>
                            <span class="hub-status-badge rejected"><i data-heroicon="x-circle"></i> ID Rejected</span>
                        <?php else: ?>
                            <span class="hub-status-badge pending"><i data-heroicon="clock"></i> Pending ID Verification</span>
                        <?php endif; ?>
                    </div>
                    <p class="hub-caption">
                        <?php if ($acc_status === 'Approved'): ?>
                            Your account is verified! You can borrow up to <strong>2 books</strong> simultaneously.
                        <?php elseif ($acc_status === 'Rejected'): ?>
                            Your uploaded ID was not approved by library staff. Please visit the front desk to update your ID.
                        <?php else: ?>
                            Your uploaded ID is awaiting librarian verification. Book borrowing will be unlocked once approved.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="hub-right">
                <div class="hub-loan-metric">
                    <span class="metric-val"><?php echo $loans_count; ?> / 2</span>
                    <span class="metric-lbl">Active Loans</span>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!empty($return_notice)): ?>
        <div style="background: #edf7ed; color: #1e6b24; padding: 12px 16px; border-radius: 6px; border: 1px solid #c8e6c9; margin: 15px auto 24px; max-width: 820px; font-weight: 500; font-size: 14px; display: flex; align-items: center; gap: 8px;">
            <i data-heroicon="check-circle"></i>
            <span><?php echo htmlspecialchars($return_notice); ?></span>
        </div>
    <?php endif; ?>

    <?php if (!empty($customer_active_borrowings)): ?>
        <section id="myLoans" class="my-loans-container">
            <div class="my-loans-header">
                <h2 class="my-loans-title">
                    <i data-heroicon="bookmark"></i>
                    <span>My Borrowed Books</span>
                    <span class="my-loans-count-chip"><?php echo count($customer_active_borrowings); ?> / 2</span>
                </h2>
                <span class="my-loans-subtitle">To return a book, click 'Return Book' and hand it to the librarian at the counter.</span>
            </div>
            <div class="my-loans-grid">
                <?php foreach ($customer_active_borrowings as $loan): ?>
                    <?php
                        $is_overdue = ($loan['borrowing_status'] === 'Borrowed' && strtotime($loan['due_date']) < time());
                        $is_pending = ($loan['borrowing_status'] === 'Pending Return');
                        $loanCoverUrl = '';
                        if (!empty($loan['image']) && file_exists(__DIR__ . '/../' . $loan['image'])) {
                            $loanCoverUrl = '../' . htmlspecialchars($loan['image']);
                        }
                    ?>
                    <div class="loan-item-card">
                        <div class="loan-cover-cell">
                            <?php if ($loanCoverUrl !== ''): ?>
                                <img src="<?php echo $loanCoverUrl; ?>" alt="Cover" class="loan-cover-img" loading="lazy">
                            <?php else: ?>
                                <div class="loan-cover-placeholder">
                                    <i data-heroicon="book-open"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="loan-info-cell">
                            <div>
                                <h3 class="loan-book-title" title="<?php echo htmlspecialchars($loan['title']); ?>"><?php echo htmlspecialchars($loan['title']); ?></h3>
                                <p class="loan-book-author">by <?php echo htmlspecialchars($loan['author']); ?></p>
                                <div class="loan-meta-row">
                                    <?php if ($is_pending): ?>
                                        <span class="loan-badge-due pending-return">
                                            <i data-heroicon="arrow-path" class="heroicon-spin"></i> Pending Verification
                                        </span>
                                    <?php elseif ($is_overdue): ?>
                                        <span class="loan-badge-due overdue">
                                            <i data-heroicon="exclamation-triangle"></i> Overdue (Due: <?php echo date("M d, Y", strtotime($loan['due_date'])); ?>)
                                        </span>
                                    <?php else: ?>
                                        <span class="loan-badge-due borrowed">
                                            <i data-heroicon="calendar"></i> Due: <?php echo date("M d, Y", strtotime($loan['due_date'])); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div>
                                <?php if ($is_pending): ?>
                                    <div class="loan-pending-indicator">
                                        <i data-heroicon="building-library"></i> Return requested &bull; Hand book to librarian
                                    </div>
                                <?php else: ?>
                                    <form method="POST" style="margin: 0;" onsubmit="return confirm('Request return for \'<?php echo htmlspecialchars(addslashes($loan['title'])); ?>\'? You will hand the book to the librarian for physical verification.');">
                                        <input type="hidden" name="action" value="request_return">
                                        <input type="hidden" name="borrowing_id" value="<?php echo (int)$loan['borrowing_id']; ?>">
                                        <button type="submit" class="btn-loan-return-action">
                                            <i data-heroicon="arrow-uturn-left"></i> Return Book
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($is_customer_logged_in): ?>
        <div class="catalog-section-divider" aria-hidden="true">
            <span><i data-heroicon="book-open"></i> Library Catalog</span>
        </div>
    <?php endif; ?>

    <div class="browse-catalog-header">
        <h1><i data-heroicon="bookmark"></i> Browse Books</h1>
        <?php if ($is_customer_logged_in): ?>
            <p>Choose a book to borrow, look up its token, or scan its shelf QR code.</p>
        <?php else: ?>
            <p>Browse our café library catalog. An approved customer account is required to borrow books.</p>
        <?php endif; ?>
    </div>

    <!-- Unified Single-Line Search & Quick Token Lookup -->
    <form method="GET" class="unified-search-form" id="unifiedSearchForm">
        <div class="unified-search-bar">
            <button type="button" class="btn-qr-icon-only" id="openScannerBtn" aria-label="Scan Book QR Code" title="Scan Book QR Code">
                <i data-heroicon="qr-code"></i>
            </button>
            <input
                type="text"
                name="search"
                id="unifiedSearchInput"
                class="unified-search-input"
                placeholder="Search books or enter QR token..."
                value="<?php echo htmlspecialchars($search); ?>"
                autocomplete="off"
            >
            <button type="submit" class="unified-search-btn" id="unifiedSubmitBtn" title="Search or Look Up">
                <i data-heroicon="magnifying-glass"></i>
                <span class="search-btn-label">Search</span>
            </button>
            <?php if ($search !== ''): ?>
                <a href="books.php" class="unified-clear-btn" aria-label="Clear search" title="Clear">
                    <i data-heroicon="x-mark"></i>
                </a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Catalog Grid -->
    <div class="books-container">

        <?php if ($result && $result->num_rows > 0): ?>

            <?php while ($book = $result->fetch_assoc()): ?>
                <?php
                    $coverUrl = '';
                    if (!empty($book['image']) && file_exists(__DIR__ . '/../' . $book['image'])) {
                        $coverUrl = '../' . htmlspecialchars($book['image']);
                    }
                ?>
                <div class="book-card" id="book-card-<?php echo (int)$book['id']; ?>">
                    <!-- Maximized Cover Image / Placeholder -->
                    <?php if ($coverUrl !== ''): ?>
                        <img src="<?php echo $coverUrl; ?>" alt="<?php echo htmlspecialchars($book['title']); ?> Cover" class="book-card-cover-bg" loading="lazy">
                    <?php else: ?>
                        <div class="book-card-placeholder-bg">
                            <i data-heroicon="book-open"></i>
                            <span>Page Lounge</span>
                        </div>
                    <?php endif; ?>

                    <!-- Subtle Vignette for Legibility -->
                    <div class="book-card-vignette"></div>

                    <?php
                        $avail_copies = (int)($book['available_copies'] ?? 1);
                        $tot_copies = (int)($book['total_copies'] ?? 1);
                        $is_book_available = ($avail_copies > 0);
                    ?>

                    <!-- Floating Top Status Badge -->
                    <?php if ($is_book_available): ?>
                        <div class="book-card-status-badge available status-indicator">
                            <i data-heroicon="check-circle"></i> <?php echo $avail_copies; ?> of <?php echo $tot_copies; ?> Available
                        </div>
                    <?php else: ?>
                        <div class="book-card-status-badge borrowed status-indicator">
                            <i data-heroicon="clock"></i> All <?php echo $tot_copies; ?> Borrowed
                        </div>
                    <?php endif; ?>

                    <!-- Text overlay: No box backgrounds, only button has a box background -->
                    <div class="book-card-overlay">
                        <!-- Title (No box background) -->
                        <div class="book-card-title-box">
                            <h2 class="book-card-title-text" title="<?php echo htmlspecialchars($book['title']); ?>">
                                <?php echo htmlspecialchars($book['title']); ?>
                            </h2>
                        </div>

                        <!-- Metadata (No box background) -->
                        <div class="book-card-meta-box">
                            <div class="meta-item-row">
                                <span class="meta-label">Author:</span>
                                <span class="meta-val"><?php echo htmlspecialchars($book['author']); ?></span>
                            </div>
                            <div class="book-meta-inline">
                                <span><?php echo htmlspecialchars($book['category'] ?: 'General'); ?></span>
                                <span class="meta-sep">•</span>
                                <span><?php echo htmlspecialchars($book['isbn'] ?: 'N/A'); ?></span>
                            </div>
                        </div>

                        <!-- Action Button -->
                        <div class="book-card-action">
                            <?php if ($is_book_available): ?>
                                <?php if ($is_customer_logged_in): ?>
                                    <a href="borrow.php?book_id=<?php echo (int)$book['id']; ?>" class="book-card-borrow-btn">
                                        <i data-heroicon="plus"></i> Borrow Book
                                    </a>
                                <?php else: ?>
                                    <a href="login.php?redirect=borrow&book_id=<?php echo (int)$book['id']; ?>" class="book-card-borrow-btn" title="Log in to your account to borrow this book">
                                        <i data-heroicon="arrow-right-on-rectangle"></i> Log In to Borrow
                                    </a>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="book-card-unavailable-btn">All Copies Borrowed</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            <?php endwhile; ?>

        <?php else: ?>

            <div style="grid-column: 1 / -1; text-align: center; padding: 50px 20px; background: #ffffff; border: 1px solid #e5dfd5; border-radius: 6px;">
                <h3 style="font-size: 20px; margin-bottom: 10px; color: #241e1a;">
                    <?php echo $search !== '' ? 'No Matching Books Found' : 'No Books in Catalog'; ?>
                </h3>
                <p style="color: #6b625b; max-width: 480px; margin: 0 auto 20px;">
                    <?php echo $search !== '' ? 'No books matched your search query "' . htmlspecialchars($search) . '". Try a different title or author.' : 'No books have been registered in the library yet.'; ?>
                </p>
                <?php if ($search !== ''): ?>
                    <a href="books.php" class="borrow-button" style="margin-top: 0;">
                        <i data-heroicon="arrow-path"></i> View All Books
                    </a>
                <?php endif; ?>
            </div>

        <?php endif; ?>

    </div>

</main>

<!-- Camera QR Scanner Modal -->
<div class="modal-overlay" id="scannerModal" aria-hidden="true">
    <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="scannerTitle">
        <div class="modal-header">
            <h2 id="scannerTitle">
                <i data-heroicon="camera"></i> Scan Book QR Code
            </h2>
            <button type="button" class="modal-close-btn js-close-scanner" aria-label="Close camera scanner">
                <i data-heroicon="x-mark"></i>
            </button>
        </div>
        <div class="modal-body">
            <p class="scanner-help-text">
                Align the book's shelf or cover QR code inside the camera preview.
            </p>

            <div class="scanner-view-wrapper" id="scannerWrapper">
                <div id="qrReader" class="qr-reader-box"></div>
                <div class="scanner-idle-placeholder" id="scannerPlaceholder">
                    <i data-heroicon="camera" style="width: 36px; height: 36px;"></i>
                    <span>Camera is inactive</span>
                    <small>Click "Start Camera" to begin scanning</small>
                </div>
            </div>

            <div class="scanner-controls">
                <button type="button" id="startCameraBtn" class="scanner-btn-primary">
                    <i data-heroicon="camera"></i> Start Camera
                </button>
                <button type="button" id="stopCameraBtn" class="scanner-btn-secondary" style="display: none;">
                    <i data-heroicon="x-mark"></i> Stop Camera
                </button>
            </div>

            <div id="scannerFeedback" class="scanner-feedback-msg" style="display: none;"></div>
        </div>
    </div>
</div>

<?php if ($is_customer_logged_in): ?>
<div class="modal-overlay" id="logoutModal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="logoutModalTitle" style="display: none;">
    <div class="modal-dialog" style="max-width: 400px; text-align: center; padding: 26px 24px; border-radius: 8px;">
        <div style="width: 52px; height: 52px; border-radius: 50%; background: #fee2e2; border: 1px solid #fecaca; color: #dc2626; display: inline-flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
            <i data-heroicon="arrow-right-on-rectangle" style="width: 26px; height: 26px;"></i>
        </div>
        <h2 id="logoutModalTitle" style="font-size: 20px; color: #241e1a; margin: 0 0 8px 0; font-family: 'Playfair Display', Georgia, serif; font-weight: 700;">Confirm Logout</h2>
        <p style="font-size: 14px; color: #6b625b; margin: 0 0 22px 0; line-height: 1.5;">
            Are you sure you want to sign out, <strong><?php echo htmlspecialchars($_SESSION['customer_name'] ?? ''); ?></strong>?
        </p>
        <div style="display: flex; gap: 10px; justify-content: center;">
            <button type="button" class="btn-modal-cancel" id="cancelLogoutBtn" style="flex: 1; padding: 10px 16px; font-size: 13.5px; font-weight: 600; background: #f4efe8; color: #2e2620; border: 1px solid #d5cbbe; border-radius: 6px; cursor: pointer; transition: background 0.15s ease;">
                Cancel
            </button>
            <a href="logout.php" id="confirmLogoutLink" style="flex: 1; padding: 10px 16px; font-size: 13.5px; font-weight: 600; background: #dc2626; color: #ffffff; border: 1px solid #b91c1c; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: background 0.15s ease;">
                <i data-heroicon="arrow-right-on-rectangle"></i>
                <span>Log Out</span>
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="../heroicons.js?v=<?= @filemtime(__DIR__ . '/../heroicons.js') ?: time() ?>"></script>
<script src="../html5-qrcode.min.js"></script>
<script>
    // Fallback load if parent path fails
    if (typeof Html5Qrcode === 'undefined') {
        var s = document.createElement('script');
        s.src = 'html5-qrcode.min.js';
        document.head.appendChild(s);
    }
</script>

<script>
    heroicons.createIcons();

    (function () {
        'use strict';

        var isCustomerLoggedIn = <?php echo $is_customer_logged_in ? 'true' : 'false'; ?>;

        // Elements
        var scannerModal = document.getElementById('scannerModal');
        var openScannerBtn = document.getElementById('openScannerBtn');
        var startCameraBtn = document.getElementById('startCameraBtn');
        var stopCameraBtn = document.getElementById('stopCameraBtn');
        var scannerWrapper = document.getElementById('scannerWrapper');
        var scannerPlaceholder = document.getElementById('scannerPlaceholder');
        var scannerFeedback = document.getElementById('scannerFeedback');

        var logoutModal = document.getElementById('logoutModal');
        var openLogoutModalBtn = document.getElementById('openLogoutModalBtn');
        var cancelLogoutBtn = document.getElementById('cancelLogoutBtn');

        var unifiedSearchForm = document.getElementById('unifiedSearchForm');
        var unifiedSearchInput = document.getElementById('unifiedSearchInput');
        var unifiedSubmitBtn = document.getElementById('unifiedSubmitBtn');

        var qrScanner = null;
        var isCameraRunning = false;
        var scanLock = false;

        // Modal Open / Close Helpers
        function showModal(modal) {
            modal.style.display = 'flex';
            modal.setAttribute('aria-hidden', 'false');
            setTimeout(function () {
                modal.classList.add('is-active');
            }, 10);
            document.body.style.overflow = 'hidden';
        }

        function hideModal(modal) {
            modal.classList.remove('is-active');
            modal.setAttribute('aria-hidden', 'true');
            setTimeout(function () {
                modal.style.display = 'none';
            }, 200);
            if (!document.querySelector('.modal-overlay.is-active')) {
                document.body.style.overflow = '';
            }
        }

        function showScannerFeedback(msg, type) {
            scannerFeedback.textContent = msg;
            scannerFeedback.className = 'scanner-feedback-msg ' + (type || 'info');
            scannerFeedback.style.display = 'block';
        }

        function hideScannerFeedback() {
            scannerFeedback.style.display = 'none';
            scannerFeedback.textContent = '';
        }

        // Camera Control
        async function startScanning() {
            if (isCameraRunning) return;
            hideScannerFeedback();

            if (typeof Html5Qrcode === 'undefined') {
                showScannerFeedback('QR Scanner library is still initializing. Please try again in a moment.', 'info');
                return;
            }

            try {
                if (!qrScanner) {
                    qrScanner = new Html5Qrcode('qrReader', { verbose: false });
                }

                await qrScanner.start(
                    { facingMode: 'environment' },
                    {
                        fps: 10,
                        aspectRatio: 1.0,
                        qrbox: function (viewfinderWidth, viewfinderHeight) {
                            var minEdge = Math.min(viewfinderWidth, viewfinderHeight);
                            var boxSize = Math.max(160, Math.min(minEdge - 16, Math.floor(minEdge * 0.90)));
                            return { width: boxSize, height: boxSize };
                        }
                    },
                    function onScanSuccess(decodedText) {
                        if (scanLock) return;
                        scanLock = true;
                        handleScannedToken(decodedText);
                    },
                    function onScanFailure() {
                        // Continuous scanning frames, ignore per-frame misses
                    }
                );

                isCameraRunning = true;
                scannerWrapper.classList.add('is-scanning');
                scannerPlaceholder.style.display = 'none';
                startCameraBtn.style.display = 'none';
                stopCameraBtn.style.display = 'inline-flex';
                heroicons.createIcons({ root: stopCameraBtn });
            } catch (err) {
                var message = 'Could not access camera. Check permissions or enter book token in the search bar.';
                if (err && err.name === 'NotAllowedError') {
                    message = 'Camera permission denied. Allow camera access or enter book token in the search bar.';
                } else if (err && err.name === 'NotFoundError') {
                    message = 'No camera found on this device. Enter the book token in the search bar.';
                }
                showScannerFeedback(message, 'error');
                stopScanning();
            }
        }

        async function stopScanning() {
            if (qrScanner && isCameraRunning) {
                try {
                    await qrScanner.stop();
                    qrScanner.clear();
                } catch (e) {}
            }
            isCameraRunning = false;
            scannerWrapper.classList.remove('is-scanning');
            scannerPlaceholder.style.display = 'flex';
            startCameraBtn.style.display = 'inline-flex';
            stopCameraBtn.style.display = 'none';
            heroicons.createIcons({ root: startCameraBtn });
        }

        // Lookup Book by Token / ID
        async function lookupBook(token, sourceElement) {
            var trimmed = (token || '').trim();
            if (!trimmed) {
                alert('Please enter a book token, ID, or ISBN.');
                return;
            }

            if (sourceElement) {
                sourceElement.disabled = true;
            }

            try {
                var response = await fetch('books.php?action=lookup&token=' + encodeURIComponent(trimmed), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                var data = await response.json();

                if (data.ok && data.book) {
                    stopScanning();
                    hideModal(scannerModal);
                    if (!data.book.is_available) {
                        alert('All copies of "' + data.book.title + '" are currently borrowed.');
                        return;
                    }
                    window.location.href = isCustomerLoggedIn
                        ? ('borrow.php?book_id=' + data.book.id)
                        : ('login.php?redirect=borrow&book_id=' + data.book.id);
                } else {
                    var errorMsg = data.error || 'Book not found for "' + trimmed + '".';
                    if (scannerModal.classList.contains('is-active')) {
                        showScannerFeedback(errorMsg, 'error');
                    } else {
                        alert(errorMsg);
                    }
                }
            } catch (e) {
                var netError = 'Failed to connect to library server. Please try again.';
                if (scannerModal.classList.contains('is-active')) {
                    showScannerFeedback(netError, 'error');
                } else {
                    alert(netError);
                }
            } finally {
                if (sourceElement) {
                    sourceElement.disabled = false;
                }
                scanLock = false;
            }
        }

        function handleScannedToken(text) {
            lookupBook(text, null);
        }

        // Trigger Scanner Modal
        openScannerBtn.addEventListener('click', function () {
            hideScannerFeedback();
            showModal(scannerModal);
            heroicons.createIcons({ root: scannerModal });
            startScanning();
        });

        startCameraBtn.addEventListener('click', startScanning);
        stopCameraBtn.addEventListener('click', stopScanning);

        // Close Buttons
        document.querySelectorAll('.js-close-scanner').forEach(function (btn) {
            btn.addEventListener('click', function () {
                stopScanning();
                hideModal(scannerModal);
            });
        });

        // Click outside modal dialog to dismiss
        scannerModal.addEventListener('click', function (e) {
            if (e.target === scannerModal) {
                stopScanning();
                hideModal(scannerModal);
            }
        });

        // Logout Modal Interactions
        if (openLogoutModalBtn && logoutModal) {
            openLogoutModalBtn.addEventListener('click', function (e) {
                e.preventDefault();
                showModal(logoutModal);
                heroicons.createIcons({ root: logoutModal });
            });
        }

        if (cancelLogoutBtn && logoutModal) {
            cancelLogoutBtn.addEventListener('click', function () {
                hideModal(logoutModal);
            });
        }

        if (logoutModal) {
            logoutModal.addEventListener('click', function (e) {
                if (e.target === logoutModal) {
                    hideModal(logoutModal);
                }
            });
        }

        // Escape Key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                if (scannerModal && scannerModal.classList.contains('is-active')) {
                    stopScanning();
                    hideModal(scannerModal);
                }
                if (logoutModal && logoutModal.classList.contains('is-active')) {
                    hideModal(logoutModal);
                }
            }
        });

        // Unified Search & Lookup Submission Handler
        function isTokenOrId(val) {
            var v = (val || '').trim();
            if (!v) return false;
            // Token/ID patterns: 1, #1, book_1, book-1, book 1, book_1.png, book_id=1, borrow.php?book_id=1
            if (/^(?:#|book[-_\s]?)?\d+(?:\.png|\.svg)?$/i.test(v)) return true;
            if (/book_id=\d+/i.test(v)) return true;
            var cleanIsbn = v.replace(/[-\s]/g, '');
            if (/^(?:\d{9}[\dX]|\d{13})$/i.test(cleanIsbn)) return true;
            return false;
        }

        if (unifiedSearchForm) {
            unifiedSearchForm.addEventListener('submit', function (e) {
                var query = unifiedSearchInput ? unifiedSearchInput.value.trim() : '';
                if (isTokenOrId(query)) {
                    e.preventDefault();
                    lookupBook(query, unifiedSubmitBtn);
                }
            });
        }
    })();
</script>
</body>
</html>
