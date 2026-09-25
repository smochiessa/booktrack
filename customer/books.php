
<?php

require_once "../database.php";

$sql = "SELECT * FROM books ORDER BY title ASC";
$result = $conn->query($sql);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Browse Books - BookTrack</title>

    <link rel="stylesheet" href="../style.css">

    <style>

        .books-page {
            padding: 50px;
        }

        .books-page h1 {
            text-align: center;
            margin-bottom: 10px;
        }

        .books-page > p {
            text-align: center;
            color: #6b625b;
            margin-bottom: 40px;
        }

        .books-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            max-width: 1100px;
            margin: auto;
        }

        .book-card {
            background: white;
            padding: 25px;
            border-radius: 12px;

            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }

        .book-card h2 {
            margin-bottom: 15px;
        }

        .book-card p {
            margin: 8px 0;
            color: #6b625b;
        }

        .available {
            color: green !important;
            font-weight: bold;
        }

        .borrowed {
            color: #b33a3a !important;
            font-weight: bold;
        }

        .borrow-button {
            display: inline-block;
            margin-top: 15px;

            padding: 10px 18px;

            background: #7b4f35;
            color: white;

            text-decoration: none;

            border-radius: 7px;
        }

        .borrow-button:hover {
            background: #5f3b27;
        }

        .back-button {
            display: block;
            width: fit-content;
            margin: 40px auto 0;

            text-decoration: none;
            color: #7b4f35;

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


<main class="books-page">

    <h1>📚 Browse Books</h1>

    <p>
        Choose a book you would like to borrow.
    </p>


    <div class="books-container">

        <?php if ($result->num_rows > 0): ?>

            <?php while ($book = $result->fetch_assoc()): ?>

                <div class="book-card">

                    <h2>
                        <?php echo htmlspecialchars($book['title']); ?>
                    </h2>

                    <p>
                        <strong>Author:</strong>
                        <?php echo htmlspecialchars($book['author']); ?>
                    </p>

                    <p>
                        <strong>Category:</strong>
                        <?php echo htmlspecialchars($book['category']); ?>
                    </p>

                    <p>
                        <strong>ISBN:</strong>
                        <?php echo htmlspecialchars($book['isbn']); ?>
                    </p>


                    <?php if ($book['status'] === 'Available'): ?>

                        <p class="available">
                            ● Available
                        </p>

                        <a
                            href="borrow.php?book_id=<?php echo $book['id']; ?>"
                            class="borrow-button"
                        >
                            Borrow Book
                        </a>

                    <?php else: ?>

                        <p class="borrowed">
                            ● Currently Borrowed
                        </p>

                    <?php endif; ?>

                </div>

            <?php endwhile; ?>

        <?php else: ?>

            <p style="grid-column: 1 / -1; text-align:center;">
                No books are available yet.
            </p>

        <?php endif; ?>

    </div>


    <a href="../index.php" class="back-button">
        ← Back to Home
    </a>


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

