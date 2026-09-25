
<?php

require_once "../database.php";

if (!isset($_GET['book_id'])) {
    die("No book selected.");
}

$book_id = intval($_GET['book_id']);

$sql = "SELECT * FROM books WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $book_id);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows == 0) {
    die("Book not found.");
}

$book = $result->fetch_assoc();

if ($book['status'] !== 'Available') {
    die("Sorry, this book is currently borrowed.");
}

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $name = trim($_POST['name']);
    $contact = trim($_POST['contact']);

    if ($name == "") {

        $message = "Please enter your name.";

    } else {

        // Check if customer already exists
        $customer_sql = "SELECT id FROM customers WHERE name = ? AND contact = ?";
        $customer_stmt = $conn->prepare($customer_sql);
        $customer_stmt->bind_param("ss", $name, $contact);
        $customer_stmt->execute();

        $customer_result = $customer_stmt->get_result();

        if ($customer_result->num_rows > 0) {

            $customer = $customer_result->fetch_assoc();
            $customer_id = $customer['id'];

        } else {

            // Create new customer
            $insert_customer = "INSERT INTO customers (name, contact) VALUES (?, ?)";

            $insert_stmt = $conn->prepare($insert_customer);
            $insert_stmt->bind_param("ss", $name, $contact);
            $insert_stmt->execute();

            $customer_id = $conn->insert_id;
        }


        // Due date: 7 days from now
        $due_date = date("Y-m-d H:i:s", strtotime("+7 days"));


        // Add borrowing record
        $borrow_sql = "INSERT INTO borrowings
                       (customer_id, book_id, due_date, status)
                       VALUES (?, ?, ?, 'Borrowed')";

        $borrow_stmt = $conn->prepare($borrow_sql);
        $borrow_stmt->bind_param(
            "iis",
            $customer_id,
            $book_id,
            $due_date
        );

        if ($borrow_stmt->execute()) {

            // Change book status
            $update_book = "UPDATE books
                            SET status = 'Borrowed'
                            WHERE id = ?";

            $update_stmt = $conn->prepare($update_book);
            $update_stmt->bind_param("i", $book_id);
            $update_stmt->execute();

            $message = "Book borrowed successfully!";

        } else {

            $message = "Something went wrong. Please try again.";

        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Borrow Book - BookTrack</title>

    <link rel="stylesheet" href="../style.css">

    <style>

        .borrow-page {
            max-width: 600px;
            margin: 60px auto;
            padding: 20px;
        }

        .borrow-card {
            background: white;
            padding: 35px;
            border-radius: 15px;

            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }

        .borrow-card h1 {
            margin-bottom: 10px;
        }

        .book-title {
            color: #7b4f35;
            margin-bottom: 25px;
        }

        label {
            display: block;
            margin-top: 15px;
            margin-bottom: 6px;
            font-weight: bold;
        }

        input {
            width: 100%;
            padding: 12px;

            border: 1px solid #ccc;
            border-radius: 7px;

            font-size: 15px;
        }

        .submit-button {
            width: 100%;
            margin-top: 25px;

            padding: 13px;

            background: #7b4f35;
            color: white;

            border: none;
            border-radius: 7px;

            font-size: 16px;
            font-weight: bold;

            cursor: pointer;
        }

        .submit-button:hover {
            background: #5f3b27;
        }

        .success {
            background: #e8f5e9;
            color: #2e7d32;

            padding: 15px;
            border-radius: 8px;

            margin-bottom: 20px;
        }

        .error {
            background: #ffebee;
            color: #c62828;

            padding: 15px;
            border-radius: 8px;

            margin-bottom: 20px;
        }

        .back-link {
            display: block;
            text-align: center;

            margin-top: 20px;

            color: #7b4f35;
            text-decoration: none;
            font-weight: bold;
        }

    </style>

</head>


<body>


<header>

    <div class="logo">
        📚 BookTrack
    </div>

    <div class="header-text">
        Café Library System
    </div>

</header>


<main class="borrow-page">

    <div class="borrow-card">

        <h1>📖 Borrow Book</h1>

        <p class="book-title">
            <strong>
                <?php echo htmlspecialchars($book['title']); ?>
            </strong>
        </p>

        <div class="book-info">
    <p><strong>Author:</strong> <?php echo htmlspecialchars($book['author']); ?></p>
    <p><strong>Category:</strong> <?php echo htmlspecialchars($book['category']); ?></p>
    <p><strong>ISBN:</strong> <?php echo htmlspecialchars($book['isbn']); ?></p>
</div>


        <?php if ($message == "Book borrowed successfully!"): ?>

            <div class="success">
                ✅ <?php echo $message; ?>

                <br><br>

                <strong>
                    Due Date:
                </strong>

                <?php echo date("F d, Y", strtotime($due_date)); ?>
            </div>

            <a href="books.php" class="back-link">
                ← Back to Books
            </a>


        <?php else: ?>


            <?php if ($message != ""): ?>

                <div class="error">
                    <?php echo htmlspecialchars($message); ?>
                </div>

            <?php endif; ?>


            <form method="POST">

                <label for="name">
                    Customer Name
                </label>

                <input
                    type="text"
                    id="name"
                    name="name"
                    placeholder="Enter your name"
                    required
                >


                <label for="contact">
                    Contact Number
                </label>

                <input
                    type="text"
                    id="contact"
                    name="contact"
                    placeholder="Enter your contact number"
                >


                <button
                    type="submit"
                    class="submit-button"
                >
                    Confirm Borrowing
                </button>

            </form>


            <a href="books.php" class="back-link">
                ← Cancel
            </a>


        <?php endif; ?>

    </div>

</main>


<footer>

    <p>
        © 2026 BookTrack Café Library
    </p>

    <a href="../admin/login.php">
        Librarian Login
    </a>

</footer>


</body>

</html>

