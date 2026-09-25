<?php

session_start();

if (!isset($_SESSION['librarian'])) {
    header("Location: login.php");
    exit;
}

require_once "../database.php";

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
        ORDER BY borrowings.borrow_date DESC";

$borrowings = $conn->query($sql);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <title>Borrowing History - BookTrack</title>

    <link rel="stylesheet" href="../style.css">

</head>

<body>

<header>

    <div class="logo">
        📚 BookTrack
    </div>

    <div class="header-text">
        Borrowing History
    </div>

    <nav class="admin-nav">
        <a href="dashboard.php">📊 Dashboard</a>
        <a href="books.php">📖 Book Inventory</a>
        <a href="borrowings.php">👥 Borrowing History</a>
        <a href="logout.php">🚪 Logout</a>
    </nav>

</header>

<main>

    <section>

        <h1>👥 Borrowing History</h1>

        <p>
            View all book borrowing and return transactions.
        </p>

        <table class="history-table">

            <thead>

                <tr>
                    <th>ID</th>
                    <th>Customer</th>
                    <th>Contact</th>
                    <th>Book</th>
                    <th>Borrow Date</th>
                    <th>Due Date</th>
                    <th>Return Date</th>
                    <th>Status</th>
                </tr>

            </thead>

            <tbody>

                <?php if ($borrowings->num_rows > 0): ?>

                    <?php while ($row = $borrowings->fetch_assoc()): ?>

                        <tr>

                            <td>
                                <?php echo $row['id']; ?>
                            </td>

                            <td>
                                <?php echo htmlspecialchars($row['customer_name']); ?>
                            </td>

                            <td>
                                <?php echo htmlspecialchars($row['contact']); ?>
                            </td>

                            <td>
                                <?php echo htmlspecialchars($row['book_title']); ?>
                            </td>

                            <td>
                                <?php echo $row['borrow_date']; ?>
                            </td>

                            <td>
                                <?php echo $row['due_date']; ?>
                            </td>

                            <td>
                                <?php echo $row['return_date'] ?? '—'; ?>
                            </td>

                            <td>
                                <?php echo $row['status']; ?>
                            </td>

                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="8" style="text-align:center;">
                            No borrowing records yet.
                        </td>
                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

    <section class="history-container">

</main>

</body>
</html>