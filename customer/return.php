
<?php

require_once "../database.php";

$message = "";
$message_type = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $name = trim($_POST['name']);
    $contact = trim($_POST['contact']);

    if ($name == "") {

        $message = "Please enter your name.";
        $message_type = "error";

    } else {

        // Find the customer
        $customer_sql = "SELECT id FROM customers WHERE name = ? AND contact = ?";
        $customer_stmt = $conn->prepare($customer_sql);
        $customer_stmt->bind_param("ss", $name, $contact);
        $customer_stmt->execute();

        $customer_result = $customer_stmt->get_result();

        if ($customer_result->num_rows == 0) {

            $message = "Customer record not found.";
            $message_type = "error";

        } else {

            $customer = $customer_result->fetch_assoc();
            $customer_id = $customer['id'];

            // Find the customer's active borrowing
            $borrow_sql = "
                SELECT borrowings.id, borrowings.book_id, books.title
                FROM borrowings
                JOIN books ON borrowings.book_id = books.id
                WHERE borrowings.customer_id = ?
                AND borrowings.status = 'Borrowed'
                LIMIT 1
            ";

            $borrow_stmt = $conn->prepare($borrow_sql);
            $borrow_stmt->bind_param("i", $customer_id);
            $borrow_stmt->execute();

            $borrow_result = $borrow_stmt->get_result();

            if ($borrow_result->num_rows == 0) {

                $message = "You do not have an active borrowed book.";
                $message_type = "error";

            } else {

                $borrowing = $borrow_result->fetch_assoc();

                $borrowing_id = $borrowing['id'];
                $book_id = $borrowing['book_id'];
                $book_title = $borrowing['title'];


                // Mark borrowing as returned
                $return_sql = "
                    UPDATE borrowings
                    SET return_date = NOW(),
                        status = 'Returned'
                    WHERE id = ?
                ";

                $return_stmt = $conn->prepare($return_sql);
                $return_stmt->bind_param("i", $borrowing_id);


                if ($return_stmt->execute()) {

                    // Make the book available again
                    $book_sql = "
                        UPDATE books
                        SET status = 'Available'
                        WHERE id = ?
                    ";

                    $book_stmt = $conn->prepare($book_sql);
                    $book_stmt->bind_param("i", $book_id);
                    $book_stmt->execute();

                    $message = "Book returned successfully: " . $book_title;
                    $message_type = "success";

                } else {

                    $message = "Something went wrong. Please try again.";
                    $message_type = "error";
                }
            }
        }
    }
}

?>


<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Return Book - BookTrack</title>

    <link rel="stylesheet" href="../style.css">


    <style>

        .return-page {
            max-width: 600px;
            margin: 60px auto;
            padding: 20px;
        }

        .return-card {
            background: white;
            padding: 35px;
            border-radius: 15px;

            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }

        .return-card h1 {
            margin-bottom: 10px;
        }

        .return-card > p {
            color: #6b625b;
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

        .return-button {
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

        .return-button:hover {
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


<main class="return-page">

    <div class="return-card">

        <h1>🔄 Return a Book</h1>

        <p>
            Enter your information to return your borrowed book.
        </p>


        <?php if ($message != ""): ?>

            <div class="<?php echo $message_type; ?>">

                <?php echo htmlspecialchars($message); ?>

            </div>

        <?php endif; ?>


        <?php if ($message_type != "success"): ?>

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
                    class="return-button"
                >
                    Confirm Return
                </button>

            </form>

        <?php endif; ?>


        <a href="../index.php" class="back-link">
            ← Back to Home
        </a>

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

