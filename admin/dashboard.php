
<?php

session_start();

require_once "../database.php";

$logo_url = pagelounge_logo_url();

// Make sure librarian is logged in
if (!isset($_SESSION['librarian'])) {
    header("Location: login.php");
    exit();
}


// TOTAL BOOKS
$total_books_query = $conn->query(
    "SELECT COUNT(*) AS total FROM books"
);

$total_books = $total_books_query->fetch_assoc()['total'];


// AVAILABLE BOOKS
$available_query = $conn->query(
    "SELECT COUNT(*) AS total FROM books WHERE status = 'Available'"
);

$available_books = $available_query->fetch_assoc()['total'];


// BORROWED BOOKS
$borrowed_query = $conn->query(
    "SELECT COUNT(*) AS total FROM books WHERE status = 'Borrowed'"
);

$borrowed_books = $borrowed_query->fetch_assoc()['total'];


// OVERDUE BOOKS
$overdue_query = $conn->query(
    "SELECT COUNT(*) AS total
     FROM borrowings
     WHERE status = 'Borrowed'
     AND due_date < NOW()"
);

$overdue_books = $overdue_query->fetch_assoc()['total'];


// RECENT TRANSACTIONS
$transactions_query = $conn->query(
    "SELECT
        borrowings.id,
        customers.name AS customer_name,
        books.title AS book_title,
        borrowings.borrow_date,
        borrowings.due_date,
        borrowings.return_date,
        borrowings.status

     FROM borrowings

     JOIN customers
        ON borrowings.customer_id = customers.id

     JOIN books
        ON borrowings.book_id = books.id

     ORDER BY borrowings.borrow_date DESC

     LIMIT 10"
);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Librarian Dashboard - Page Lounge</title>

    <link rel="stylesheet" href="../style.css">


    <style>

        .dashboard {
            width: 100%;
            max-width: 100%;
            margin: 0;
            padding: 0;
        }

        .dashboard-header {
            margin-bottom: 24px;
        }

        .dashboard-header h1 {
            font-size: 28px;
            color: #241e1a;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 6px;
        }

        .dashboard-header p {
            color: #6b625b;
            font-size: 15px;
        }


        /* STAT CARDS */

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: #ffffff;
            padding: 24px;
            border-radius: 6px;
            border: 1px solid #e5dfd5;
        }

        .stat-card h3 {
            font-size: 14px;
            color: #6b625b;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
        }

        .stat-number {
            font-size: 36px;
            font-weight: 700;
            color: #241e1a;
            line-height: 1;
        }


        /* TRANSACTIONS */

        .transactions {
            background: #ffffff;
            padding: 24px 28px;
            border-radius: 6px;
            border: 1px solid #e5dfd5;
        }

        .transactions h2 {
            margin-bottom: 18px;
            font-size: 20px;
            color: #241e1a;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 12px 14px;
            border-bottom: 1px solid #eae4db;
            text-align: left;
            font-size: 14px;
        }

        th {
            background: #f4efe8;
            color: #2e2620;
            font-weight: 600;
            border-bottom: 1px solid #dfd7cc;
        }

        tr:hover td {
            background: #faf7f3;
        }


        /* STATUS */

        .status {
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

        .status-borrowed {
            background: #fff8e1;
            color: #8a5200;
            border: 1px solid #ffe082;
        }

        .status-returned {
            background: #edf7ed;
            color: #1e6b24;
            border: 1px solid #c8e6c9;
        }

        .status-overdue {
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
                    <a href="dashboard.php" class="active">
                        <i data-heroicon="squares-2x2"></i> Dashboard
                    </a>
                    <a href="books.php">
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

        <main class="dashboard">


            <div class="dashboard-header">

                <h1>
                    <i data-heroicon="squares-2x2"></i> Librarian Dashboard
                </h1>

        <p>
            Welcome, <?php echo htmlspecialchars($_SESSION['librarian']); ?>.
            Monitor your café library activity below.
        </p>

    </div>


    <!-- STATISTICS -->

    <div class="stats">


        <div class="stat-card">

            <h3>
                <i data-heroicon="book-open"></i> Total Books
            </h3>

            <div class="stat-number">
                <?php echo $total_books; ?>
            </div>

        </div>


        <div class="stat-card">

            <h3>
                <i data-heroicon="check-circle"></i> Available Books
            </h3>

            <div class="stat-number">
                <?php echo $available_books; ?>
            </div>

        </div>


        <div class="stat-card">

            <h3>
                <i data-heroicon="clock"></i> Borrowed Books
            </h3>

            <div class="stat-number">
                <?php echo $borrowed_books; ?>
            </div>

        </div>


        <div class="stat-card">

            <h3>
                <i data-heroicon="exclamation-triangle"></i> Overdue Books
            </h3>

            <div class="stat-number">
                <?php echo $overdue_books; ?>
            </div>

        </div>


    </div>


    <!-- TRANSACTIONS -->

    <div class="transactions">

        <h2>
            <i data-heroicon="clipboard-document-list"></i> Recent Borrowing Transactions
        </h2>

        <div class="table-responsive">

        <table>

            <thead>

                <tr>

                    <th>
                        Customer
                    </th>

                    <th>
                        Book
                    </th>

                    <th>
                        Borrow Date
                    </th>

                    <th>
                        Due Date
                    </th>

                    <th>
                        Return Date
                    </th>

                    <th>
                        Status
                    </th>

                </tr>

            </thead>


            <tbody>

                <?php if ($transactions_query->num_rows > 0): ?>


                    <?php while ($transaction = $transactions_query->fetch_assoc()): ?>

                        <tr>

                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $transaction['customer_name']
                                );
                                ?>
                            </td>


                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $transaction['book_title']
                                );
                                ?>
                            </td>


                            <td>
                                <?php
                                echo date(
                                    "M d, Y h:i A",
                                    strtotime(
                                        $transaction['borrow_date']
                                    )
                                );
                                ?>
                            </td>


                            <td>
                                <?php
                                echo date(
                                    "M d, Y",
                                    strtotime(
                                        $transaction['due_date']
                                    )
                                );
                                ?>
                            </td>


                            <td>

                                <?php if ($transaction['return_date']): ?>

                                    <?php
                                    echo date(
                                        "M d, Y h:i A",
                                        strtotime(
                                            $transaction['return_date']
                                        )
                                    );
                                    ?>

                                <?php else: ?>

                                    Not returned

                                <?php endif; ?>

                            </td>


                            <td>

                                <?php

                                $status = $transaction['status'];

                                if (
                                    $status === 'Borrowed'
                                    &&
                                    strtotime(
                                        $transaction['due_date']
                                    ) < time()
                                ) {

                                    echo '<span class="status status-overdue">
                                            <i data-heroicon="exclamation-triangle"></i> Overdue
                                          </span>';

                                } elseif ($status === 'Borrowed') {

                                    echo '<span class="status status-borrowed">
                                            <i data-heroicon="clock"></i> Borrowed
                                          </span>';

                                } elseif ($status === 'Returned') {

                                    echo '<span class="status status-returned">
                                            <i data-heroicon="check-circle"></i> Returned
                                          </span>';

                                }

                                ?>

                            </td>

                        </tr>


                    <?php endwhile; ?>


                <?php else: ?>

                    <tr>

                        <td
                            colspan="6"
                            style="text-align:center;"
                        >

                            No borrowing transactions yet.

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
</script>
</body>

</html>

