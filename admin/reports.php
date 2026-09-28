<?php
session_start();

require_once "../database.php";
$logo_url = pagelounge_logo_url();

if (!isset($_SESSION['librarian'])) {
    header("Location: login.php");
    exit();
}

$report_type = trim($_GET['type'] ?? 'borrowings');
$start_date = trim($_GET['start_date'] ?? '');
$end_date = trim($_GET['end_date'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$category_filter = trim($_GET['category'] ?? '');
$search = trim($_GET['search'] ?? '');

// Fetch categories for filter dropdown
$categories_res = $conn->query("SELECT DISTINCT category FROM books WHERE category IS NOT NULL AND category != '' ORDER BY category ASC");
$all_categories = [];
if ($categories_res) {
    while ($cat_row = $categories_res->fetch_assoc()) {
        $all_categories[] = $cat_row['category'];
    }
}

// Summary statistics defaults
$stat_total = 0;
$stat_active = 0;
$stat_overdue = 0;
$stat_returned = 0;
$stat_customers = 0;
$stat_available = 0;
$stat_borrowed = 0;
$max_days = 0;
$rows = [];

// -------------------------------------------------------------
// Report 1: Borrowing & Return Activity
// -------------------------------------------------------------
if ($report_type === 'borrowings') {
    $report_title = "Borrowing & Return Activity Report";
    $report_subtitle = "Detailed audit of café library checkouts, due dates, and return transactions.";

    $where = ["1=1"];
    $types = "";
    $params = [];

    if ($start_date !== '') {
        $where[] = "DATE(borrowings.borrow_date) >= ?";
        $types .= "s";
        $params[] = $start_date;
    }
    if ($end_date !== '') {
        $where[] = "DATE(borrowings.borrow_date) <= ?";
        $types .= "s";
        $params[] = $end_date;
    }
    if ($search !== '') {
        $where[] = "(customers.name LIKE ? OR customers.contact LIKE ? OR books.title LIKE ?)";
        $term = "%" . $search . "%";
        $types .= "sss";
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
    }
    if ($status_filter === 'Returned') {
        $where[] = "borrowings.status = 'Returned'";
    } elseif ($status_filter === 'Borrowed') {
        $where[] = "borrowings.status = 'Borrowed' AND borrowings.due_date >= NOW()";
    } elseif ($status_filter === 'Overdue') {
        $where[] = "borrowings.status = 'Borrowed' AND borrowings.due_date < NOW()";
    }

    $where_sql = implode(" AND ", $where);
    $sql = "SELECT
                borrowings.id,
                customers.name AS customer_name,
                customers.contact AS customer_contact,
                books.title AS book_title,
                books.category AS book_category,
                borrowings.borrow_date,
                borrowings.due_date,
                borrowings.return_date,
                borrowings.status,
                CASE
                    WHEN borrowings.status = 'Returned' THEN 'Returned'
                    WHEN borrowings.due_date < NOW() THEN 'Overdue'
                    ELSE 'Borrowed'
                END AS effective_status
            FROM borrowings
            INNER JOIN customers ON borrowings.customer_id = customers.id
            INNER JOIN books ON borrowings.book_id = books.id
            WHERE $where_sql
            ORDER BY borrowings.borrow_date DESC";

    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
    }

    // Summary statistics
    $stat_total = count($rows);
    $stat_active = 0;
    $stat_overdue = 0;
    $stat_returned = 0;
    foreach ($rows as $r) {
        if ($r['effective_status'] === 'Returned') {
            $stat_returned++;
        } elseif ($r['effective_status'] === 'Overdue') {
            $stat_overdue++;
        } else {
            $stat_active++;
        }
    }
}
// -------------------------------------------------------------
// Report 2: Overdue Borrowings Audit
// -------------------------------------------------------------
elseif ($report_type === 'overdue') {
    $report_title = "Overdue Accounts & Delinquent Books Report";
    $report_subtitle = "Listing of active borrowings that have passed their scheduled return due date.";

    $where = ["borrowings.status = 'Borrowed'", "borrowings.due_date < NOW()"];
    $types = "";
    $params = [];

    if ($search !== '') {
        $where[] = "(customers.name LIKE ? OR customers.contact LIKE ? OR books.title LIKE ?)";
        $term = "%" . $search . "%";
        $types .= "sss";
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
    }

    $where_sql = implode(" AND ", $where);
    $sql = "SELECT
                borrowings.id,
                customers.name AS customer_name,
                customers.contact AS customer_contact,
                books.title AS book_title,
                books.category AS book_category,
                borrowings.borrow_date,
                borrowings.due_date,
                DATEDIFF(NOW(), borrowings.due_date) AS days_overdue
            FROM borrowings
            INNER JOIN customers ON borrowings.customer_id = customers.id
            INNER JOIN books ON borrowings.book_id = books.id
            WHERE $where_sql
            ORDER BY borrowings.due_date ASC";

    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
    }

    $stat_total = count($rows);
    $stat_customers = count(array_unique(array_column($rows, 'customer_name')));
    $max_days = $stat_total > 0 ? max(array_column($rows, 'days_overdue')) : 0;
}
// -------------------------------------------------------------
// Report 3: Inventory Summary Report
// -------------------------------------------------------------
else {
    $report_title = "Book Inventory & Shelf Valuation Report";
    $report_subtitle = "Complete catalog status, category distributions, and barcode registration inventory.";

    $where = ["1=1"];
    $types = "";
    $params = [];

    if ($category_filter !== '') {
        $where[] = "category = ?";
        $types .= "s";
        $params[] = $category_filter;
    }
    if ($status_filter === 'Available' || $status_filter === 'Borrowed') {
        $where[] = "status = ?";
        $types .= "s";
        $params[] = $status_filter;
    }
    if ($search !== '') {
        $where[] = "(title LIKE ? OR author LIKE ? OR isbn LIKE ?)";
        $term = "%" . $search . "%";
        $types .= "sss";
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
    }

    $where_sql = implode(" AND ", $where);
    $sql = "SELECT id, title, author, category, isbn, status, created_at
            FROM books
            WHERE $where_sql
            ORDER BY category ASC, title ASC";

    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
    }

    $stat_total = count($rows);
    $stat_available = 0;
    $stat_borrowed = 0;
    foreach ($rows as $r) {
        if ($r['status'] === 'Available') {
            $stat_available++;
        } else {
            $stat_borrowed++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($report_title); ?> - Page Lounge</title>
    <link rel="stylesheet" href="../style.css">
    <style>
        .reports-page {
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
            margin-bottom: 22px;
            flex-wrap: wrap;
        }

        .page-title-text h1 {
            font-size: 26px;
            color: #241e1a;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
        }

        .page-title-text p {
            color: #6b625b;
            margin: 5px 0 0;
            font-size: 14.5px;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-print-primary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #2e2620;
            color: #ffffff;
            border: 1px solid #241e1a;
            padding: 9px 18px;
            border-radius: 6px;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s ease;
            text-decoration: none;
        }

        .btn-print-primary:hover {
            background: #443830;
        }

        /* Filter Toolbar */
        .filter-panel {
            background: #ffffff;
            border: 1px solid #e5dfd5;
            border-radius: 8px;
            padding: 16px 20px;
            margin-bottom: 22px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: flex-end;
            justify-content: space-between;
        }

        .filter-fields {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            flex: 1;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .filter-group label {
            font-size: 11.5px;
            font-weight: 600;
            color: #6b625b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filter-group input,
        .filter-group select {
            height: 36px;
            padding: 0 10px;
            border: 1px solid #d5cbbe;
            border-radius: 6px;
            font-size: 13px;
            background: #ffffff;
            color: #2e2620;
            outline: none;
            transition: border-color 0.15s ease;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            border-color: #2e2620;
            outline: 2px solid rgba(46, 38, 32, 0.2);
        }

        .filter-actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .btn-filter-apply {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #2e2620;
            color: #ffffff;
            border: 1px solid #241e1a;
            padding: 0 16px;
            height: 36px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s ease;
        }

        .btn-filter-apply:hover {
            background: #443830;
        }

        .btn-filter-reset {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f4efe8;
            color: #6b625b;
            border: 1px solid #d5cbbe;
            padding: 0 12px;
            height: 36px;
            border-radius: 6px;
            font-size: 12.5px;
            font-weight: 600;
            text-decoration: none;
            transition: background 0.15s ease;
        }

        .btn-filter-reset:hover {
            background: #eae4db;
            color: #2e2620;
        }

        /* Printable Document Sheet */
        .report-sheet {
            background: #ffffff;
            border: 1px solid #000000;
            border-radius: 0;
            padding: 24px 28px;
            box-shadow: none;
            margin-bottom: 24px;
            color: #000000;
            font-family: Arial, sans-serif;
            display: flex;
            flex-direction: column;
            min-height: 720px;
        }

        /* Letterhead */
        .report-letterhead {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1.5px solid #000000;
            padding-bottom: 10px;
            margin-bottom: 14px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .letterhead-brand {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .letterhead-emblem {
            width: 34px;
            height: 34px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .letterhead-emblem img {
            width: 30px;
            height: 30px;
            object-fit: contain;
        }

        .letterhead-title h2 {
            margin: 0;
            font-size: 17px;
            color: #000000;
            font-weight: 700;
            letter-spacing: 0.3px;
            line-height: 1.2;
        }

        .letterhead-title span {
            font-size: 10px;
            color: #000000;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            font-weight: 600;
        }

        .letterhead-meta {
            text-align: right;
            font-size: 11px;
            color: #000000;
            line-height: 1.4;
        }

        .letterhead-meta strong {
            color: #000000;
        }

        /* Report Title & Subtitle in Document */
        .report-doc-heading {
            margin-bottom: 12px;
        }

        .report-doc-heading h3 {
            margin: 0 0 2px;
            font-size: 15px;
            color: #000000;
            font-weight: 700;
        }

        .report-doc-heading p {
            margin: 0;
            color: #000000;
            font-size: 11.5px;
        }

        /* Summary Metrics - Inline Text Bar */
        .report-summary-bar {
            padding: 4px 0;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            font-size: 11px;
            color: #000000;
            flex-wrap: wrap;
            gap: 16px 24px;
        }

        .summary-bar-item {
            white-space: nowrap;
        }

        .summary-bar-item strong {
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        /* Report Table - Normal Compact Design */
        .report-table-wrap {
            overflow-x: auto;
            margin-bottom: 20px;
            flex: 1 0 auto;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11.5px;
            color: #000000;
            border: 1px solid #000000;
        }

        .report-table th,
        .report-table td {
            padding: 5px 7px;
            border: 1px solid #000000;
            text-align: left;
            vertical-align: middle;
            color: #000000;
        }

        .report-table th {
            background: #ffffff;
            color: #000000;
            font-weight: 700;
            font-size: 10.5px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }

        .report-table .col-nowrap {
            white-space: nowrap;
        }

        .report-table .col-center {
            text-align: center;
        }

        .report-table tbody tr:hover {
            background: transparent;
        }

        .badge-status {
            display: inline;
            padding: 0;
            border: none;
            background: transparent;
            font-size: 11px;
            font-weight: 700;
            color: #000000;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .empty-report {
            text-align: center;
            padding: 24px 16px;
            color: #000000;
            font-size: 12px;
            border: 1px solid #000000;
        }

        /* Sign-off Block */
        .report-signoff {
            display: flex;
            justify-content: space-between;
            margin-top: auto;
            padding-top: 24px;
            border-top: none;
            gap: 20px;
            flex-wrap: wrap;
        }

        .signoff-item {
            width: 200px;
            text-align: left;
        }

        .signoff-line {
            border-bottom: 1px solid #000000;
            height: 28px;
            margin-bottom: 4px;
        }

        .signoff-item span {
            font-size: 10px;
            color: #000000;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            font-weight: 700;
        }

        /* PRINT STYLES */
        @media print {
            @page {
                size: A4 portrait;
                margin: 8mm 10mm;
            }

            body {
                background: #ffffff !important;
                color: #000000 !important;
                margin: 0 !important;
                padding: 0 !important;
                font-family: Arial, sans-serif !important;
            }

            .admin-sidebar,
            .admin-sidebar-header,
            .admin-burger-btn,
            .no-print,
            .filter-panel,
            .page-title,
            header,
            footer {
                display: none !important;
            }

            .admin-layout {
                display: block !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            .admin-main {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
            }

            .reports-page {
                margin: 0 !important;
                padding: 0 !important;
            }

            .report-sheet {
                border: none !important;
                padding: 0 !important;
                box-shadow: none !important;
                margin: 0 !important;
                color: #000000 !important;
                display: flex !important;
                flex-direction: column !important;
                min-height: 255mm !important;
            }

            .report-letterhead {
                border-bottom: 1.5px solid #000000 !important;
                padding-bottom: 8px !important;
                margin-bottom: 10px !important;
            }

            .letterhead-title h2 {
                font-size: 13pt !important;
                color: #000000 !important;
            }

            .letterhead-title span {
                font-size: 7.5pt !important;
                color: #000000 !important;
            }

            .letterhead-meta {
                font-size: 7.5pt !important;
                line-height: 1.3 !important;
                color: #000000 !important;
            }

            .letterhead-meta strong {
                color: #000000 !important;
            }

            .report-doc-heading {
                margin-bottom: 10px !important;
            }

            .report-doc-heading h3 {
                font-size: 11pt !important;
                color: #000000 !important;
            }

            .report-doc-heading p {
                font-size: 8pt !important;
                color: #000000 !important;
            }

            .report-summary-bar {
                padding: 2px 0 !important;
                margin-bottom: 8px !important;
                font-size: 8pt !important;
                display: flex !important;
                align-items: center !important;
                gap: 6px 16px !important;
            }

            .summary-bar-item strong {
                font-weight: 700 !important;
            }

            .report-table {
                border: 1px solid #000000 !important;
                border-collapse: collapse !important;
                width: 100% !important;
            }

            .report-table th,
            .report-table td {
                background: #ffffff !important;
                color: #000000 !important;
                border: 1px solid #000000 !important;
                font-size: 8pt !important;
                padding: 3px 5px !important;
                line-height: 1.25 !important;
            }

            .report-table th {
                font-weight: 700 !important;
                font-size: 7.5pt !important;
                white-space: nowrap !important;
            }

            .report-table .col-nowrap {
                white-space: nowrap !important;
            }

            .report-table tr {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }

            .badge-status {
                border: none !important;
                background: transparent !important;
                color: #000000 !important;
                font-size: 8pt !important;
                font-weight: 700 !important;
                white-space: nowrap !important;
            }

            .report-table-wrap {
                flex: 1 0 auto !important;
                margin-bottom: 16px !important;
            }

            .report-signoff {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                border-top: none !important;
                margin-top: auto !important;
                padding-top: 16px !important;
            }

            .signoff-item {
                width: 180px !important;
            }

            .signoff-line {
                border-bottom: 1px solid #000000 !important;
                height: 24px !important;
            }

            .signoff-item span {
                font-size: 7.5pt !important;
                color: #000000 !important;
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
                    <a href="books.php">
                        <i data-heroicon="rectangle-stack"></i> Book Inventory
                    </a>
                    <a href="borrowings.php">
                        <i data-heroicon="clock"></i> Borrowing History
                    </a>
                    <a href="reports.php" class="active">
                        <i data-heroicon="chart-bar"></i> Reports
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

        <main class="reports-page">

            <div class="page-title no-print">
                <div class="page-title-text">
                    <h1>
                        <i data-heroicon="chart-bar"></i> System Reports
                    </h1>
                    <p>Generate, filter, and print official library reports.</p>
                </div>
                <div class="header-actions">
                    <button type="button" class="btn-print-primary" onclick="window.print();">
                        <i data-heroicon="printer"></i> Print Report
                    </button>
                </div>
            </div>

            <!-- Filter Toolbar (Screen Only) -->
            <form method="GET" action="reports.php" class="filter-panel no-print">
                <div class="filter-fields">
                    <div class="filter-group">
                        <label for="filterType">Report Scope</label>
                        <select id="filterType" name="type" onchange="this.form.submit();">
                            <option value="borrowings" <?php echo $report_type === 'borrowings' ? 'selected' : ''; ?>>Borrowing Activity</option>
                            <option value="overdue" <?php echo $report_type === 'overdue' ? 'selected' : ''; ?>>Overdue Audit</option>
                            <option value="inventory" <?php echo $report_type === 'inventory' ? 'selected' : ''; ?>>Book Inventory</option>
                        </select>
                    </div>

                    <?php if ($report_type === 'borrowings'): ?>
                        <div class="filter-group">
                            <label for="startDate">From Date</label>
                            <input type="date" id="startDate" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                        </div>
                        <div class="filter-group">
                            <label for="endDate">To Date</label>
                            <input type="date" id="endDate" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                        </div>
                        <div class="filter-group">
                            <label for="filterStatus">Status</label>
                            <select id="filterStatus" name="status">
                                <option value="">All Statuses</option>
                                <option value="Borrowed" <?php echo $status_filter === 'Borrowed' ? 'selected' : ''; ?>>Active Borrowed</option>
                                <option value="Overdue" <?php echo $status_filter === 'Overdue' ? 'selected' : ''; ?>>Overdue</option>
                                <option value="Returned" <?php echo $status_filter === 'Returned' ? 'selected' : ''; ?>>Returned</option>
                            </select>
                        </div>
                    <?php elseif ($report_type === 'inventory'): ?>
                        <div class="filter-group">
                            <label for="filterCategory">Category</label>
                            <select id="filterCategory" name="category">
                                <option value="">All Categories</option>
                                <?php foreach ($all_categories as $c): ?>
                                    <option value="<?php echo htmlspecialchars($c); ?>" <?php echo $category_filter === $c ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($c); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="filterStatus">Status</label>
                            <select id="filterStatus" name="status">
                                <option value="">All Statuses</option>
                                <option value="Available" <?php echo $status_filter === 'Available' ? 'selected' : ''; ?>>Available</option>
                                <option value="Borrowed" <?php echo $status_filter === 'Borrowed' ? 'selected' : ''; ?>>Borrowed</option>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="filter-group">
                        <label for="filterSearch">Search Keyword</label>
                        <input
                            type="text"
                            id="filterSearch"
                            name="search"
                            placeholder="Customer, title, ISBN..."
                            value="<?php echo htmlspecialchars($search); ?>"
                        >
                    </div>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn-filter-apply">
                        <i data-heroicon="magnifying-glass"></i> Filter
                    </button>
                    <a href="reports.php?type=<?php echo urlencode($report_type); ?>" class="btn-filter-reset">
                        <i data-heroicon="arrow-uturn-left"></i> Reset
                    </a>
                </div>
            </form>

            <!-- Printable Document Sheet -->
            <div class="report-sheet">

                <!-- Official Letterhead Header -->
                <div class="report-letterhead">
                    <div class="letterhead-brand">
                        <div class="letterhead-emblem">
                            <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="Logo" onerror="this.onerror=null; this.src='../logo.svg';">
                        </div>
                        <div class="letterhead-title">
                            <h2>Page Lounge</h2>
                            <span>Book Café &amp; Library System</span>
                        </div>
                    </div>
                    <div class="letterhead-meta">
                        <div><strong>Report Type:</strong> <?php echo htmlspecialchars($report_title); ?></div>
                        <div><strong>Generated:</strong> <?php echo date("F j, Y, g:i A"); ?></div>
                        <div><strong>Prepared by:</strong> <?php echo htmlspecialchars($_SESSION['librarian']); ?></div>
                        <?php if ($start_date !== '' || $end_date !== ''): ?>
                            <div>
                                <strong>Period:</strong>
                                <?php echo htmlspecialchars($start_date ?: 'All previous'); ?> &mdash; <?php echo htmlspecialchars($end_date ?: 'Current'); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="report-doc-heading">
                    <h3><?php echo htmlspecialchars($report_title); ?></h3>
                    <p><?php echo htmlspecialchars($report_subtitle); ?></p>
                </div>

                <!-- Summary Metrics Bar -->
                <div class="report-summary-bar">
                    <?php if ($report_type === 'borrowings'): ?>
                        <div class="summary-bar-item"><strong>Total Transactions:</strong> <?php echo $stat_total; ?></div>
                        <div class="summary-bar-item"><strong>Active Borrowed:</strong> <?php echo $stat_active; ?></div>
                        <div class="summary-bar-item"><strong>Overdue Records:</strong> <?php echo $stat_overdue; ?></div>
                        <div class="summary-bar-item"><strong>Completed Returns:</strong> <?php echo $stat_returned; ?></div>
                    <?php elseif ($report_type === 'overdue'): ?>
                        <div class="summary-bar-item"><strong>Total Overdue:</strong> <?php echo $stat_total; ?></div>
                        <div class="summary-bar-item"><strong>Delinquent Customers:</strong> <?php echo $stat_customers; ?></div>
                        <div class="summary-bar-item"><strong>Longest Delinquency:</strong> <?php echo $max_days; ?> days</div>
                    <?php else: ?>
                        <div class="summary-bar-item"><strong>Total Titles Listed:</strong> <?php echo $stat_total; ?></div>
                        <div class="summary-bar-item"><strong>Available on Shelf:</strong> <?php echo $stat_available; ?></div>
                        <div class="summary-bar-item"><strong>Currently Borrowed:</strong> <?php echo $stat_borrowed; ?></div>
                    <?php endif; ?>
                </div>

                <!-- Report Table -->
                <div class="report-table-wrap">
                    <?php if (empty($rows)): ?>
                        <div class="empty-report">
                            No records found matching the active report criteria.
                        </div>
                    <?php else: ?>
                        <table class="report-table">
                            <thead>
                                <?php if ($report_type === 'borrowings'): ?>
                                    <tr>
                                        <th class="col-center" style="width: 38px;">ID</th>
                                        <th>Customer</th>
                                        <th class="col-nowrap">Contact</th>
                                        <th>Book Title</th>
                                        <th class="col-nowrap">Borrowed On</th>
                                        <th class="col-nowrap">Due Date</th>
                                        <th class="col-nowrap">Return Date</th>
                                        <th class="col-nowrap">Status</th>
                                    </tr>
                                <?php elseif ($report_type === 'overdue'): ?>
                                    <tr>
                                        <th class="col-center" style="width: 38px;">ID</th>
                                        <th>Customer</th>
                                        <th class="col-nowrap">Contact</th>
                                        <th>Book Title</th>
                                        <th class="col-nowrap">Borrowed On</th>
                                        <th class="col-nowrap">Due Date</th>
                                        <th class="col-nowrap">Days Overdue</th>
                                    </tr>
                                <?php else: ?>
                                    <tr>
                                        <th class="col-center" style="width: 38px;">ID</th>
                                        <th>Title</th>
                                        <th>Author</th>
                                        <th>Category</th>
                                        <th class="col-nowrap">ISBN</th>
                                        <th class="col-nowrap">Status</th>
                                        <th class="col-nowrap">Added Date</th>
                                    </tr>
                                <?php endif; ?>
                            </thead>
                            <tbody>
                                <?php if ($report_type === 'borrowings'): ?>
                                    <?php foreach ($rows as $r): ?>
                                        <tr>
                                            <td class="col-center">#<?php echo (int)$r['id']; ?></td>
                                            <td><strong><?php echo htmlspecialchars($r['customer_name']); ?></strong></td>
                                            <td class="col-nowrap"><?php echo htmlspecialchars($r['customer_contact'] ?: '—'); ?></td>
                                            <td><?php echo htmlspecialchars($r['book_title']); ?></td>
                                            <td class="col-nowrap"><?php echo date("M d, Y", strtotime($r['borrow_date'])); ?></td>
                                            <td class="col-nowrap"><?php echo date("M d, Y", strtotime($r['due_date'])); ?></td>
                                            <td class="col-nowrap"><?php echo !empty($r['return_date']) ? date("M d, Y", strtotime($r['return_date'])) : '—'; ?></td>
                                            <td class="col-nowrap">
                                                <span class="badge-status <?php echo strtolower($r['effective_status']); ?>">
                                                    <?php echo htmlspecialchars($r['effective_status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php elseif ($report_type === 'overdue'): ?>
                                    <?php foreach ($rows as $r): ?>
                                        <tr>
                                            <td class="col-center">#<?php echo (int)$r['id']; ?></td>
                                            <td><strong><?php echo htmlspecialchars($r['customer_name']); ?></strong></td>
                                            <td class="col-nowrap"><?php echo htmlspecialchars($r['customer_contact'] ?: '—'); ?></td>
                                            <td><?php echo htmlspecialchars($r['book_title']); ?></td>
                                            <td class="col-nowrap"><?php echo date("M d, Y", strtotime($r['borrow_date'])); ?></td>
                                            <td class="col-nowrap"><?php echo date("M d, Y", strtotime($r['due_date'])); ?></td>
                                            <td class="col-nowrap">
                                                <span class="badge-status overdue">
                                                    +<?php echo (int)$r['days_overdue']; ?> days
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <?php foreach ($rows as $r): ?>
                                        <tr>
                                            <td class="col-center">#<?php echo (int)$r['id']; ?></td>
                                            <td><strong><?php echo htmlspecialchars($r['title']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($r['author']); ?></td>
                                            <td><?php echo htmlspecialchars($r['category'] ?: '—'); ?></td>
                                            <td class="col-nowrap"><?php echo htmlspecialchars($r['isbn'] ?: '—'); ?></td>
                                            <td class="col-nowrap">
                                                <span class="badge-status <?php echo strtolower($r['status']); ?>">
                                                    <?php echo htmlspecialchars($r['status']); ?>
                                                </span>
                                            </td>
                                            <td class="col-nowrap"><?php echo date("M d, Y", strtotime($r['created_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Sign-off Block for Formal Physical Filing -->
                <div class="report-signoff">
                    <div class="signoff-item">
                        <div class="signoff-line"></div>
                        <span>Prepared by (Librarian)</span>
                    </div>
                    <div class="signoff-item">
                        <div class="signoff-line"></div>
                        <span>Verified / Café Manager</span>
                    </div>
                </div>

            </div>

        </main>

    </div>

</div>

<script src="../heroicons.js?v=<?= @filemtime(__DIR__ . '/../heroicons.js') ?: time() ?>"></script>
<script>
    heroicons.createIcons();
</script>

</body>
</html>
