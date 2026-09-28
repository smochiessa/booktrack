<?php

session_start();

if (!isset($_SESSION['librarian'])) {
    header("Location: login.php");
    exit;
}

require_once "../database.php";

$logo_url = pagelounge_logo_url();

$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');

$sql = "SELECT
            borrowings.id,
            customers.name AS customer_name,
            customers.contact,
            books.title AS book_title,
            borrowings.borrow_date,
            borrowings.due_date,
            borrowings.return_date,
            borrowings.status
        FROM borrowings
        INNER JOIN customers
            ON borrowings.customer_id = customers.id
        INNER JOIN books
            ON borrowings.book_id = books.id
        WHERE 1=1";

$types = "";
$params = [];

if ($search !== '') {
    $sql .= " AND (customers.name LIKE ? OR customers.contact LIKE ? OR books.title LIKE ?)";
    $term = "%" . $search . "%";
    $types .= "sss";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

if ($status_filter === 'Returned') {
    $sql .= " AND borrowings.status = 'Returned'";
} elseif ($status_filter === 'Borrowed') {
    $sql .= " AND borrowings.status = 'Borrowed' AND borrowings.due_date >= NOW()";
} elseif ($status_filter === 'Overdue') {
    $sql .= " AND borrowings.status = 'Borrowed' AND borrowings.due_date < NOW()";
}

$sql .= " ORDER BY borrowings.borrow_date DESC";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $borrowings = $stmt->get_result();
} else {
    $borrowings = $conn->query($sql);
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Borrowing History - Page Lounge</title>
    <link rel="stylesheet" href="../style.css">
    <style>
        .borrowings-filter-bar {
            display: flex;
            gap: 12px;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .filter-search-input {
            flex: 1;
            min-width: 200px;
            padding: 10px 14px;
            border: 1px solid #d5cbbe;
            border-radius: 6px;
            font-size: 14px;
            background: #ffffff;
            color: #2e2620;
            outline: none;
            transition: border-color 0.15s ease, outline 0.15s ease;
        }

        .filter-search-input:focus {
            background: #ffffff;
            border-color: #2e2620;
            outline: 2px solid rgba(46, 38, 32, 0.2);
            outline-offset: 1px;
        }

        .filter-select {
            padding: 10px 14px;
            border: 1px solid #d5cbbe;
            border-radius: 6px;
            font-size: 14px;
            background: #ffffff;
            color: #2e2620;
            outline: none;
            cursor: pointer;
            min-height: 42px;
            transition: border-color 0.15s ease;
        }

        .filter-select:focus {
            border-color: #2e2620;
            outline: 2px solid rgba(46, 38, 32, 0.2);
            outline-offset: 1px;
        }

        .filter-submit-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 18px;
            background: #2e2620;
            color: white;
            border: 1px solid #241e1a;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            min-height: 42px;
            transition: background 0.15s ease;
        }

        .filter-submit-btn:hover {
            background: #443830;
        }

        .filter-clear-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 10px 14px;
            background: #f4efe8;
            color: #2e2620;
            text-decoration: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            border: 1px solid #d5cbbe;
            min-height: 42px;
            transition: background 0.15s ease;
        }

        .filter-clear-btn:hover {
            background: #eae4db;
        }

        .status-badge {
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 12px;
            padding: 3px 8px;
            border-radius: 4px;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }

        .status-badge.returned {
            background: #edf7ed;
            color: #1e6b24;
            border: 1px solid #c8e6c9;
        }

        .status-badge.borrowed {
            background: #fff8e1;
            color: #8a5200;
            border: 1px solid #ffe082;
        }

        .status-badge.overdue {
            background: #fdeeed;
            color: #c62828;
            border: 1px solid #ffcdd2;
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
                    <a href="borrowings.php" class="active">
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

        <main>

            <section class="history-container">

                <h1><i data-heroicon="clock"></i> Borrowing History</h1>
                <p>View all book borrowing and return transactions.</p>

                <form method="GET" class="borrowings-filter-bar">
                    <input
                        type="text"
                        name="search"
                        placeholder="Search by customer, contact, or book title..."
                        value="<?php echo htmlspecialchars($search); ?>"
                        class="filter-search-input"
                    >

                    <select name="status" class="filter-select">
                        <option value="">All Statuses</option>
                        <option value="Borrowed" <?php echo $status_filter === 'Borrowed' ? 'selected' : ''; ?>>Active Borrowed</option>
                        <option value="Overdue" <?php echo $status_filter === 'Overdue' ? 'selected' : ''; ?>>Overdue</option>
                        <option value="Returned" <?php echo $status_filter === 'Returned' ? 'selected' : ''; ?>>Returned</option>
                    </select>

                    <button type="submit" class="filter-submit-btn">
                        <i data-heroicon="magnifying-glass"></i> Filter
                    </button>

                    <?php if ($search !== '' || $status_filter !== ''): ?>
                        <a href="borrowings.php" class="filter-clear-btn">
                            <i data-heroicon="x-mark"></i> Reset
                        </a>
                    <?php endif; ?>
                </form>

                <div class="table-responsive">

                    <table class="history-table">

                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Customer</th>
                                <th>Contact</th>
                                <th>Book Title</th>
                                <th>Borrow Date</th>
                                <th>Due Date</th>
                                <th>Return Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php if ($borrowings && $borrowings->num_rows > 0): ?>

                                <?php while ($row = $borrowings->fetch_assoc()): ?>
                                    <?php
                                    $is_overdue = ($row['status'] === 'Borrowed' && strtotime($row['due_date']) < time());
                                    ?>
                                    <tr>
                                        <td>
                                            #<?php echo (int)$row['id']; ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($row['customer_name']); ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($row['contact'] ?: '-'); ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($row['book_title']); ?>
                                        </td>
                                        <td>
                                            <?php echo date("M d, Y h:i A", strtotime($row['borrow_date'])); ?>
                                        </td>
                                        <td>
                                            <?php echo date("M d, Y", strtotime($row['due_date'])); ?>
                                        </td>
                                        <td>
                                            <?php echo $row['return_date'] ? date("M d, Y h:i A", strtotime($row['return_date'])) : '<span style="color: #8b7d72;">Not returned</span>'; ?>
                                        </td>
                                        <td>
                                            <?php if ($row['status'] === 'Returned'): ?>
                                                <span class="status-badge returned">
                                                    <i data-heroicon="check-circle"></i> Returned
                                                </span>
                                            <?php elseif ($is_overdue): ?>
                                                <span class="status-badge overdue">
                                                    <i data-heroicon="exclamation-triangle"></i> Overdue
                                                </span>
                                            <?php else: ?>
                                                <span class="status-badge borrowed">
                                                    <i data-heroicon="clock"></i> Borrowed
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>

                                <?php endwhile; ?>

                            <?php else: ?>

                                <tr>
                                    <td colspan="8" style="text-align:center; padding: 30px;">
                                        No borrowing records found.
                                    </td>
                                </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </section>

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
</script>
</body>
</html>
