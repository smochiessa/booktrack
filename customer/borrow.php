<?php

require_once "../database.php";

$logo_url = pagelounge_logo_url();

$book_id = filter_var($_GET['book_id'] ?? null, FILTER_VALIDATE_INT);
$init_error = "";
$book = null;
$message = "";
$message_type = "";
$due_date = "";

if (!$book_id) {
    $init_error = "No book selected. Please choose a book from the catalog.";
} else {
    $stmt = $conn->prepare("SELECT * FROM books WHERE id = ?");
    $stmt->bind_param("i", $book_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $init_error = "Book not found in library catalog.";
    } else {
        $book = $result->fetch_assoc();
        if ($book['status'] !== 'Available' && $_SERVER["REQUEST_METHOD"] !== "POST") {
            $init_error = "Sorry, this book is currently borrowed and unavailable.";
        }
    }
}

if (!$init_error && $_SERVER["REQUEST_METHOD"] === "POST") {
    $name = trim($_POST['name'] ?? '');
    $contact = trim($_POST['contact'] ?? '');

    if ($name === "") {
        $message = "Please enter your name.";
        $message_type = "error";
    } else {
        $conn->begin_transaction();
        try {
            // Atomic update of book status
            $update_stmt = $conn->prepare("UPDATE books SET status = 'Borrowed' WHERE id = ? AND status = 'Available'");
            $update_stmt->bind_param("i", $book_id);
            $update_stmt->execute();

            if ($update_stmt->affected_rows === 0) {
                $conn->rollback();
                $message = "Sorry, this book was just borrowed by another customer.";
                $message_type = "error";
                $book['status'] = 'Borrowed';
            } else {
                // Check if customer already exists
                $customer_stmt = $conn->prepare("SELECT id FROM customers WHERE name = ? AND contact = ?");
                $customer_stmt->bind_param("ss", $name, $contact);
                $customer_stmt->execute();
                $customer_res = $customer_stmt->get_result();

                if ($customer_res->num_rows > 0) {
                    $customer_id = $customer_res->fetch_assoc()['id'];
                } else {
                    $insert_cust = $conn->prepare("INSERT INTO customers (name, contact) VALUES (?, ?)");
                    $insert_cust->bind_param("ss", $name, $contact);
                    $insert_cust->execute();
                    $customer_id = $conn->insert_id;
                }

                // Due date: 7 days from now
                $due_date = date("Y-m-d H:i:s", strtotime("+7 days"));

                $borrow_stmt = $conn->prepare("INSERT INTO borrowings (customer_id, book_id, due_date, status) VALUES (?, ?, ?, 'Borrowed')");
                $borrow_stmt->bind_param("iis", $customer_id, $book_id, $due_date);
                $borrow_stmt->execute();

                $conn->commit();
                $message = "Book borrowed successfully!";
                $message_type = "success";
            }
        } catch (\Throwable $e) {
            $conn->rollback();
            $message = "An error occurred while processing your borrowing. Please try again.";
            $message_type = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Borrow Book - Page Lounge</title>
    <link rel="stylesheet" href="../style.css">
    <style>
        .borrow-page {
            max-width: 600px;
            margin: 50px auto;
            padding: 20px;
        }

        .borrow-card {
            background: #ffffff;
            padding: 35px;
            border-radius: 6px;
            border: 1px solid #e5dfd5;
        }

        .borrow-card h1 {
            margin-bottom: 12px;
            color: #241e1a;
            font-size: 26px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .book-title {
            color: #241e1a;
            margin-bottom: 20px;
            font-size: 18px;
            font-weight: 600;
        }

        .book-info {
            background: #fbf9f6;
            padding: 16px;
            border-radius: 6px;
            border: 1px solid #e5dfd5;
            margin-bottom: 24px;
        }

        .book-info p {
            margin: 6px 0;
            color: #6b625b;
            font-size: 14px;
        }

        label {
            display: block;
            margin-top: 15px;
            margin-bottom: 6px;
            font-weight: 600;
            font-size: 14px;
            color: #2e2620;
        }

        input {
            width: 100%;
            padding: 11px 14px;
            border: 1px solid #d5cbbe;
            border-radius: 6px;
            font-size: 16px;
            background: #ffffff;
            color: #2e2620;
            outline: none;
            transition: border-color 0.15s ease, outline 0.15s ease;
        }

        input:focus {
            background: #ffffff;
            border-color: #2e2620;
            outline: 2px solid rgba(46, 38, 32, 0.2);
            outline-offset: 1px;
        }

        .submit-button {
            width: 100%;
            margin-top: 25px;
            padding: 13px;
            background: #2e2620;
            color: white;
            border: 1px solid #241e1a;
            border-radius: 6px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-height: 44px;
            transition: background 0.15s ease;
        }

        .submit-button:hover {
            background: #443830;
        }

        .success {
            background: #edf7ed;
            color: #1e6b24;
            padding: 16px;
            border-radius: 6px;
            border: 1px solid #c8e6c9;
            margin-bottom: 20px;
            line-height: 1.6;
        }

        .error {
            background: #fdeeed;
            color: #c62828;
            padding: 14px;
            border-radius: 6px;
            border: 1px solid #ffcdd2;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            text-align: center;
            margin-top: 20px;
            color: #2e2620;
            text-decoration: none;
            font-weight: 600;
            width: 100%;
            min-height: 44px;
        }

        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <header>
        <a href="../index.php" class="brand-link">
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
        <div class="header-text">
            Self-Service Borrowing
        </div>
    </header>

    <main class="borrow-page">
        <div class="borrow-card">
            <?php if ($init_error !== ""): ?>
                <h1><i data-heroicon="exclamation-triangle"></i> Notice</h1>
                <div class="error">
                    <?php echo htmlspecialchars($init_error); ?>
                </div>
                <a href="books.php" class="back-link">
                    <i data-heroicon="arrow-left"></i> Back to Catalog
                </a>
            <?php elseif ($message_type === "success"): ?>
                <h1><i data-heroicon="check-circle"></i> Success</h1>
                <div class="success">
                    <strong><?php echo htmlspecialchars($message); ?></strong>
                    <br><br>
                    <strong>Book:</strong> <?php echo htmlspecialchars($book['title']); ?>
                    <br>
                    <strong>Due Date:</strong> <?php echo date("F d, Y", strtotime($due_date)); ?>
                    <br>
                    <small style="color: #43a047;">Please return your book within 7 days at our return desk.</small>
                </div>
                <a href="books.php" class="back-link">
                    <i data-heroicon="arrow-left"></i> Back to Catalog
                </a>
            <?php else: ?>
                <h1><i data-heroicon="bookmark"></i> Borrow Book</h1>
                <p class="book-title">
                    <strong><?php echo htmlspecialchars($book['title']); ?></strong>
                </p>

                <?php
                    $coverUrl = '';
                    if (!empty($book['image']) && file_exists(__DIR__ . '/../' . $book['image'])) {
                        $coverUrl = '../' . htmlspecialchars($book['image']);
                    }
                ?>
                <div class="book-info" style="display: flex; gap: 16px; align-items: flex-start;">
                    <div style="flex: 0 0 72px; width: 72px; height: 98px; border-radius: 6px; overflow: hidden; border: 1px solid #dcd4c8; background: #f4efe8; box-shadow: 0 2px 6px rgba(46, 38, 32, 0.08);">
                        <?php if ($coverUrl !== ''): ?>
                            <img src="<?php echo $coverUrl; ?>" alt="Cover" style="width: 100%; height: 100%; object-fit: cover; display: block;">
                        <?php else: ?>
                            <div style="width: 100%; height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #8b7d72; background: #efe9e0; gap: 2px;">
                                <i data-heroicon="book-open" style="width: 24px; height: 24px; opacity: 0.6;"></i>
                                <span style="font-size: 8px; font-weight: 700; text-transform: uppercase;">Page Lounge</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div style="flex: 1; min-width: 0;">
                        <p><strong>Author:</strong> <?php echo htmlspecialchars($book['author']); ?></p>
                        <p><strong>Category:</strong> <?php echo htmlspecialchars($book['category'] ?: 'Uncategorized'); ?></p>
                        <p><strong>ISBN:</strong> <?php echo htmlspecialchars($book['isbn'] ?: 'N/A'); ?></p>
                    </div>
                </div>

                <?php if ($message !== ""): ?>
                    <div class="error">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" onsubmit="var b=this.querySelector('.submit-button'); if(b){b.innerHTML='<i data-heroicon=\'arrow-path\' class=\'heroicon-spin\'></i> Processing...'; if(window.heroicons){heroicons.createIcons({root:b});}}">
                    <label for="name">Customer Name *</label>
                    <input
                        type="text"
                        id="name"
                        name="name"
                        placeholder="Enter your name"
                        value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                        required
                    >

                    <label for="contact">Contact Number (Optional)</label>
                    <input
                        type="text"
                        id="contact"
                        name="contact"
                        placeholder="Enter your contact number or email"
                        value="<?php echo htmlspecialchars($_POST['contact'] ?? ''); ?>"
                    >

                    <button type="submit" class="submit-button">
                        <i data-heroicon="check"></i> Confirm Borrowing
                    </button>
                </form>

                <a href="books.php" class="back-link">
                    <i data-heroicon="x-mark"></i> Cancel
                </a>
            <?php endif; ?>
        </div>
    </main>

    <script src="../heroicons.js"></script>
    <script>
        heroicons.createIcons();
    </script>
</body>
</html>
