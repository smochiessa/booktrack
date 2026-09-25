
<?php

session_start();

require_once "../database.php";

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

    <title>Librarian Dashboard - BookTrack</title>

    <link rel="stylesheet" href="../style.css">


    <style>

        .dashboard {
            padding: 40px;
        }

        .dashboard-header {
            max-width: 1200px;
            margin: auto;
            margin-bottom: 30px;
        }

        .dashboard-header h1 {
            margin-bottom: 8px;
        }

        .dashboard-header p {
            color: #6b625b;
        }


        /* STAT CARDS */

        .stats {
            max-width: 1200px;
            margin: auto;

            display: grid;

            grid-template-columns:
                repeat(auto-fit, minmax(200px, 1fr));

            gap: 20px;
        }

        .stat-card {
            background: white;

            padding: 25px;

            border-radius: 12px;

            box-shadow:
                0 5px 15px rgba(0,0,0,0.08);
        }

        .stat-card h3 {
            font-size: 15px;

            color: #6b625b;

            margin-bottom: 10px;
        }

        .stat-number {
            font-size: 35px;

            font-weight: bold;

            color: #7b4f35;
        }


        /* TRANSACTIONS */

        .transactions {
            max-width: 1200px;

            margin: 40px auto;

            background: white;

            padding: 25px;

            border-radius: 12px;

            box-shadow:
                0 5px 15px rgba(0,0,0,0.08);

            overflow-x: auto;
        }

        .transactions h2 {
            margin-bottom: 20px;
        }

        table {
            width: 100%;

            border-collapse: collapse;
        }

        th,
        td {
            padding: 12px;

            border-bottom: 1px solid #eee;

            text-align: left;
        }

        th {
            background: #f6f1e8;
        }


        /* STATUS */

        .status {
            font-weight: bold;
        }

        .status-borrowed {
            color: #b36b00;
        }

        .status-returned {
            color: #2e7d32;
        }

        .status-overdue {
            color: #c62828;
        }


        /* LOGOUT */

        .logout {
            display: inline-block;

            margin-top: 25px;

            padding: 10px 18px;

            background: #7b4f35;

            color: white;

            text-decoration: none;

            border-radius: 7px;

            font-weight: bold;
        }

        .logout:hover {
            background: #5f3b27;
        }

    </style>

</head>


<body>
    


<header>

    <div class="logo">
        📚 BookTrack
    </div>

    <div class="header-text">
        Librarian Dashboard
    </div>
<nav class="admin-nav">
        <a href="dashboard.php">📊 Dashboard</a>
        <a href="books.php">📖 Book Inventory</a>
        <a href="borrowings.php">👥 Borrowing History</a>
        <a href="logout.php">🚪 Logout</a>
    </nav>
    

</header>


<main class="dashboard">


    <div class="dashboard-header">

        <h1>
            📊 Librarian Dashboard
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
                📚 Total Books
            </h3>

            <div class="stat-number">
                <?php echo $total_books; ?>
            </div>

        </div>


        <div class="stat-card">

            <h3>
                🟢 Available Books
            </h3>

            <div class="stat-number">
                <?php echo $available_books; ?>
            </div>

        </div>


        <div class="stat-card">

            <h3>
                🔴 Borrowed Books
            </h3>

            <div class="stat-number">
                <?php echo $borrowed_books; ?>
            </div>

        </div>


        <div class="stat-card">

            <h3>
                ⚠️ Overdue Books
            </h3>

            <div class="stat-number">
                <?php echo $overdue_books; ?>
            </div>

        </div>


    </div>


    <!-- TRANSACTIONS -->

    <div class="transactions">

        <h2>
            📋 Recent Borrowing Transactions
        </h2>


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

                                    —

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
                                            Overdue
                                          </span>';

                                } elseif ($status === 'Borrowed') {

                                    echo '<span class="status status-borrowed">
                                            Borrowed
                                          </span>';

                                } elseif ($status === 'Returned') {

                                    echo '<span class="status status-returned">
                                            Returned
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


        <a href="login.php" class="logout">
            Logout
        </a>

    </div>


</main>


<footer>

    <p>
        © 2026 BookTrack Café Library
    </p>

</footer>


</body>

</html>

