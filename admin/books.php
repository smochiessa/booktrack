
<?php

session_start();

require_once "../database.php";

require_once "../vendor/autoload.php";

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

if (!isset($_SESSION['librarian'])) {
    header("Location: login.php");
    exit();
}

$message = "";
$message_type = "";


// ADD BOOK
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_book'])) {

    $title = trim($_POST['title']);
    $author = trim($_POST['author']);
    $category = trim($_POST['category']);
    $isbn = trim($_POST['isbn']);

    if ($title == "" || $author == "") {

        $message = "Title and author are required.";
        $message_type = "error";

    } else {

        $sql = "INSERT INTO books
                (title, author, category, isbn, status)
                VALUES (?, ?, ?, ?, 'Available')";

        $stmt = $conn->prepare($sql);

        $stmt->bind_param(
            "ssss",
            $title,
            $author,
            $category,
            $isbn
        );

        if ($stmt->execute()) {


         $book_id = $conn->insert_id;

    $qrText = "http://192.168.1.12/BookTrack/customer/borrow.php?book_id=" . $book_id;

    $qrCode = new QrCode(
        data: $qrText,
        size: 300,
        margin: 10
    );

    $writer = new PngWriter();

    $result = $writer->write($qrCode);

    $fileName = "book_" . $book_id . ".png";
    $filePath = __DIR__ . "/../qrcodes/" . $fileName;

    $result->saveToFile($filePath);

    $qrPath = "qrcodes/" . $fileName;

    $updateSql = "UPDATE books SET qr_code = ? WHERE id = ?";
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->bind_param("si", $qrPath, $book_id);
    $updateStmt->execute();

            $message = "Book added successfully!";
            $message_type = "success";

        } else {

            $message = "Failed to add book.";
            $message_type = "error";
        }
    }
}


// DELETE BOOK
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_book'])) {

    $book_id = intval($_POST['book_id']);

    $sql = "DELETE FROM books WHERE id = ? AND status = 'Available'";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $book_id);

    if ($stmt->execute() && $stmt->affected_rows > 0) {

        $message = "Book deleted successfully!";
        $message_type = "success";

    } else {

        $message = "Borrowed books cannot be deleted.";
        $message_type = "error";
    }
}


// SEARCH / GET ALL BOOKS

$search = trim($_GET['search'] ?? '');

if ($search !== '') {

    $searchTerm = "%" . $search . "%";

    $sql = "SELECT * FROM books
            WHERE title LIKE ?
            OR author LIKE ?
            OR category LIKE ?
            OR isbn LIKE ?
            ORDER BY title ASC";

    $stmt = $conn->prepare($sql);

    $stmt->bind_param(
        "ssss",
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $searchTerm
    );

    $stmt->execute();

    $books = $stmt->get_result();

} else {

    $books = $conn->query(
        "SELECT * FROM books ORDER BY title ASC"
    );

}
?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Manage Books - BookTrack</title>

    <link rel="stylesheet" href="../style.css">

    <style>

        .books-admin {
            max-width: 1200px;
            margin: auto;
            padding: 40px 20px;
        }

        .page-title {
            margin-bottom: 25px;
        }

        .page-title p {
            color: #6b625b;
            margin-top: 8px;
        }


        /* FORM */

        .add-book-card {
            background: white;

            padding: 25px;

            border-radius: 12px;

            box-shadow: 0 5px 15px rgba(0,0,0,0.08);

            margin-bottom: 30px;
        }

        .add-book-card h2 {
            margin-bottom: 20px;
        }

        .form-grid {
            display: grid;

            grid-template-columns:
                repeat(auto-fit, minmax(200px, 1fr));

            gap: 15px;
        }

        .form-group label {
            display: block;

            margin-bottom: 6px;

            font-weight: bold;
        }

        .form-group input {
            width: 100%;

            padding: 11px;

            border: 1px solid #ccc;

            border-radius: 7px;
        }

        .add-button {
            margin-top: 20px;

            padding: 12px 20px;

            background: #7b4f35;

            color: white;

            border: none;

            border-radius: 7px;

            font-weight: bold;

            cursor: pointer;
        }

        .add-button:hover {
            background: #5f3b27;
        }


        /* MESSAGES */

        .success {
            background: #e8f5e9;

            color: #2e7d32;

            padding: 12px;

            border-radius: 7px;

            margin-bottom: 20px;
        }

        .error {
            background: #ffebee;

            color: #c62828;

            padding: 12px;

            border-radius: 7px;

            margin-bottom: 20px;
        }


        /* TABLE */

        .books-table {
            background: white;

            padding: 25px;

            border-radius: 12px;

            box-shadow: 0 5px 15px rgba(0,0,0,0.08);

            overflow-x: auto;
        }

        .books-table h2 {
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


        .available {
            color: #2e7d32;

            font-weight: bold;
        }

        .borrowed {
            color: #c62828;

            font-weight: bold;
        }


        .delete-button {
            background: #c62828;

            color: white;

            border: none;

            padding: 7px 12px;

            border-radius: 5px;

            cursor: pointer;
        }

        .delete-button:hover {
            background: #9e2020;
        }


        .navigation {
            margin-top: 25px;
        }

        .navigation a {
            margin-right: 15px;

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
        Book Management
    </div>
    <nav class="admin-nav">
    <a href="dashboard.php">📊 Dashboard</a>
    <a href="books.php">📖 Book Inventory</a>
    <a href="borrowings.php">👥 Borrowing History</a>
    <a href="logout.php">🚪 Logout</a>
</nav>

</header>


<main class="books-admin">


    <div class="page-title">

        <h1>
            📚 Manage Books
        </h1>

        <p>
            Add and manage books in the café library.
        </p>

    </div>


    <?php if ($message != ""): ?>

        <div class="<?php echo $message_type; ?>">

            <?php echo htmlspecialchars($message); ?>

        </div>

    <?php endif; ?>


    <!-- ADD BOOK -->

    <div class="add-book-card">

        <h2>
            ➕ Add New Book
        </h2>


        <form method="POST">

            <div class="form-grid">


                <div class="form-group">

                    <label>
                        Book Title
                    </label>

                    <input
                        type="text"
                        name="title"
                        placeholder="Enter book title"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Author
                    </label>

                    <input
                        type="text"
                        name="author"
                        placeholder="Enter author"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Category
                    </label>

                    <input
                        type="text"
                        name="category"
                        placeholder="e.g. Fiction"
                    >

                </div>


                <div class="form-group">

                    <label>
                        ISBN
                    </label>

                    <input
                        type="text"
                        name="isbn"
                        placeholder="Enter ISBN"
                    >

                </div>


            </div>


            <button
                type="submit"
                name="add_book"
                class="add-button"
            >
                Add Book
            </button>

        </form>

    </div>


    <!-- BOOK LIST -->

    <div class="books-table">

        <h2>
            📖 Book Inventory
        </h2>
        <form method="GET" class="search-form">

    <input
        type="text"
        name="search"
        placeholder="Search by title, author, category, or ISBN"
        value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>"
    >

    <button type="submit">
        🔍 Search
    </button>

</form>

        <table>

           <thead>

    <tr>

        <th>
            ID
        </th>

        <th>
            Title
        </th>

        <th>
            Author
        </th>

        <th>
            Category
        </th>

        <th>
            ISBN
        </th>

        <th>
            QR Code
        </th>

        <th>
            Status
        </th>

        <th>
            Action
        </th>

    </tr>

</thead>


           <tbody>

    <?php if ($books->num_rows > 0): ?>

        <?php while ($book = $books->fetch_assoc()): ?>

            <tr>

                <td>
                    <?php echo $book['id']; ?>
                </td>

                <td>
                    <?php echo htmlspecialchars($book['title']); ?>
                </td>

                <td>
                    <?php echo htmlspecialchars($book['author']); ?>
                </td>

                <td>
                    <?php echo htmlspecialchars($book['category']); ?>
                </td>

                <td>
                    <?php echo htmlspecialchars($book['isbn']); ?>
                </td>

                <td>
                    <?php if (!empty($book['qr_code'])): ?>

                        <img
                            src="../<?php echo htmlspecialchars($book['qr_code']); ?>"
                            alt="QR Code"
                            width="100"
                        >

                    <?php else: ?>

                        <a
                            href="../generate_qr.php?book_id=<?php echo $book['id']; ?>"
                            target="_blank"
                        >
                            Generate QR
                        </a>

                    <?php endif; ?>
                </td>

                <td>

                    <?php if ($book['status'] === 'Available'): ?>

                        <span class="available">
                            🟢 Available
                        </span>

                    <?php else: ?>

                        <span class="borrowed">
                            🔴 Borrowed
                        </span>

                    <?php endif; ?>

                </td>

                <td>

                    <?php if ($book['status'] === 'Available'): ?>

                        <form method="POST">

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
                            >
                                Delete
                            </button>

                        </form>

                    <?php else: ?>

                        —

                    <?php endif; ?>

                </td>

            </tr>

        <?php endwhile; ?>

    <?php else: ?>

        <tr>

            <td
                colspan="8"
                style="text-align:center;"
            >
                No books in inventory.
            </td>

        </tr>

    <?php endif; ?>

</tbody>

        </table>


        <div class="navigation">

            <a href="dashboard.php">
                ← Dashboard
            </a>

            <a href="login.php">
                Logout
            </a>

        </div>

    </div>


</main>


<footer>

    <p>
        © 2026 BookTrack Café Library
    </p>

</footer>


</body>

</html>

