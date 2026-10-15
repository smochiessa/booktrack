<?php

session_start();

require_once "../database.php";

$logo_url = pagelounge_logo_url();

$book_id = filter_var($_GET['book_id'] ?? null, FILTER_VALIDATE_INT);
$init_error = "";
$book = null;
$message = "";
$message_type = "";
$due_date = "";

// 1. Must be logged in as customer
if (!isset($_SESSION['customer_id'])) {
    $redirect_url = "login.php" . ($book_id ? "?redirect=borrow&book_id=" . $book_id : "");
    header("Location: " . $redirect_url);
    exit();
}

$customer_id = (int)$_SESSION['customer_id'];

// Fetch latest customer details
$cust_stmt = $conn->prepare("SELECT id, name, email, contact, account_status FROM customers WHERE id = ?");
$cust_stmt->bind_param("i", $customer_id);
$cust_stmt->execute();
$current_customer = $cust_stmt->get_result()->fetch_assoc();

if (!$current_customer) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$_SESSION['account_status'] = $current_customer['account_status'];
$_SESSION['customer_name'] = $current_customer['name'];

// 2. Check customer approval status
$account_status = $current_customer['account_status'] ?? 'Pending';
if ($account_status !== 'Approved') {
    if ($account_status === 'Rejected') {
        $init_error = "Account Verification Rejected: Your uploaded valid ID was not approved by the librarian. Please contact library staff for assistance.";
    } else {
        $init_error = "Account Pending Verification: Your account is currently awaiting librarian ID verification. You will be able to borrow books once approved.";
    }
}

// 3. Check active borrowings limit (max 2 active borrowed books)
$active_loans_count = 0;
if (!$init_error) {
    $count_stmt = $conn->prepare("SELECT COUNT(*) AS active_count FROM borrowings WHERE customer_id = ? AND status IN ('Borrowed', 'Pending Return')");
    $count_stmt->bind_param("i", $customer_id);
    $count_stmt->execute();
    $active_loans_count = (int)$count_stmt->get_result()->fetch_assoc()['active_count'];

    if ($active_loans_count >= 2) {
        $init_error = "Borrow Limit Reached: You currently have 2 active borrowed books. Page Lounge policy restricts each customer to a maximum of 2 borrowed books at a time. Please return a book before borrowing another.";
    }
}

// 4. Validate selected book
if (!$init_error) {
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
            $available_copies = (int)($book['available_copies'] ?? 1);
            if ($available_copies <= 0 && $_SERVER["REQUEST_METHOD"] !== "POST") {
                $init_error = "Sorry, all copies of this book are currently borrowed.";
            }
        }
    }
}

// 5. Handle Borrow Form Submission
if (!$init_error && $_SERVER["REQUEST_METHOD"] === "POST") {
    $conn->begin_transaction();
    try {
        // Double-check active limit with lock
        $chk_stmt = $conn->prepare("SELECT COUNT(*) AS active_count FROM borrowings WHERE customer_id = ? AND status = 'Borrowed' FOR UPDATE");
        $chk_stmt->bind_param("i", $customer_id);
        $chk_stmt->execute();
        $fresh_active = (int)$chk_stmt->get_result()->fetch_assoc()['active_count'];

        if ($fresh_active >= 2) {
            $conn->rollback();
            $message = "Borrow limit reached: You currently have 2 active borrowed books. Please return a book first.";
            $message_type = "error";
        } else {
            // Atomic decrement of available_copies and status update
            $update_stmt = $conn->prepare("UPDATE books SET available_copies = available_copies - 1, status = IF(available_copies - 1 <= 0, 'Borrowed', 'Available') WHERE id = ? AND available_copies > 0");
            $update_stmt->bind_param("i", $book_id);
            $update_stmt->execute();

            if ($update_stmt->affected_rows === 0) {
                $conn->rollback();
                $message = "Sorry, all copies of this book were just borrowed by another customer.";
                $message_type = "error";
                $book['available_copies'] = 0;
                $book['status'] = 'Borrowed';
            } else {
                $due_date = date("Y-m-d H:i:s", strtotime("+7 days"));

                $borrow_stmt = $conn->prepare("INSERT INTO borrowings (customer_id, book_id, due_date, status) VALUES (?, ?, ?, 'Borrowed')");
                $borrow_stmt->bind_param("iis", $customer_id, $book_id, $due_date);
                $borrow_stmt->execute();

                $conn->commit();
                $message = "Book borrowed successfully!";
                $message_type = "success";
                $active_loans_count++;
                $book['available_copies'] = max(0, (int)$book['available_copies'] - 1);
                if ($book['available_copies'] <= 0) {
                    $book['status'] = 'Borrowed';
                }
            }
        }
    } catch (\Throwable $e) {
        $conn->rollback();
        $message = "An error occurred while processing your borrowing. Please try again.";
        $message_type = "error";
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
            max-width: 620px;
            margin: 40px auto;
            padding: 20px;
        }

        .borrow-card {
            background: #ffffff;
            padding: 32px;
            border-radius: 8px;
            border: 1px solid #e5dfd5;
            box-shadow: 0 4px 14px rgba(46, 38, 32, 0.05);
        }

        .borrow-card h1 {
            margin-bottom: 8px;
            color: #241e1a;
            font-size: 24px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .customer-pill {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #fbf9f6;
            border: 1px solid #e5dfd5;
            padding: 10px 14px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 13px;
            color: #2e2620;
        }

        .customer-pill strong {
            color: #241e1a;
        }

        .loan-counter-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            background: #f4efe8;
            border: 1px solid #d5cbbe;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
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

        .copies-indicator {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 8px;
            background: #edf7ed;
            color: #1e6b24;
            border: 1px solid #c8e6c9;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }

        .submit-button {
            width: 100%;
            margin-top: 20px;
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
            min-height: 46px;
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
            font-size: 14px;
            line-height: 1.5;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            text-align: center;
            margin-top: 16px;
            color: #2e2620;
            text-decoration: none;
            font-weight: 600;
            width: 100%;
            min-height: 42px;
            font-size: 14px;
        }

        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <header>
        <a href="books.php" class="brand-link">
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
                    <small style="color: #43a047;">Please return your book within 7 days at our counter return desk or via QR scan.</small>
                </div>
                <a href="books.php" class="back-link">
                    <i data-heroicon="arrow-left"></i> Back to Catalog
                </a>
            <?php else: ?>
                <h1><i data-heroicon="bookmark"></i> Borrow Book</h1>

                <div class="customer-pill">
                    <div>
                        <span>Customer: </span>
                        <strong><?php echo htmlspecialchars($current_customer['name']); ?></strong>
                        <span style="color: #635b54;"> (<?php echo htmlspecialchars($current_customer['email'] ?: $current_customer['contact']); ?>)</span>
                    </div>
                    <div class="loan-counter-badge">
                        <i data-heroicon="clock"></i> Active Loans: <?php echo $active_loans_count; ?> / 2
                    </div>
                </div>

                <?php
                    $coverUrl = '';
                    if (!empty($book['image']) && file_exists(__DIR__ . '/../' . $book['image'])) {
                        $coverUrl = '../' . htmlspecialchars($book['image']);
                    }
                    $avail = (int)($book['available_copies'] ?? 1);
                    $total = (int)($book['total_copies'] ?? 1);
                ?>
                <div class="book-info" style="display: flex; gap: 16px; align-items: flex-start;">
                    <div style="flex: 0 0 72px; width: 72px; height: 98px; border-radius: 6px; overflow: hidden; border: 1px solid #dcd4c8; background: #f4efe8; box-shadow: 0 2px 6px rgba(46, 38, 32, 0.08);">
                        <?php if ($coverUrl !== ''): ?>
                            <img src="<?php echo $coverUrl; ?>" alt="Cover" style="width: 100%; height: 100%; object-fit: cover; display: block;">
                        <?php else: ?>
                            <div style="width: 100%; height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #635b54; background: #efe9e0; gap: 2px;">
                                <i data-heroicon="book-open" style="width: 24px; height: 24px; opacity: 0.6;"></i>
                                <span style="font-size: 8px; font-weight: 700; text-transform: uppercase;">Page Lounge</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div style="flex: 1; min-width: 0;">
                        <p style="font-size: 16px; font-weight: 700; color: #241e1a; margin-bottom: 4px;">
                            <?php echo htmlspecialchars($book['title']); ?>
                        </p>
                        <p><strong>Author:</strong> <?php echo htmlspecialchars($book['author']); ?></p>
                        <p><strong>Category:</strong> <?php echo htmlspecialchars($book['category'] ?: 'Uncategorized'); ?></p>
                        <p><strong>Copies Available:</strong> <span class="copies-indicator"><i data-heroicon="check"></i> <?php echo $avail; ?> of <?php echo $total; ?> available</span></p>
                    </div>
                </div>

                <?php if ($message !== ""): ?>
                    <div class="error">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" onsubmit="var b=this.querySelector('.submit-button'); if(b){b.innerHTML='<i data-heroicon=\'arrow-path\' class=\'heroicon-spin\'></i> Processing...'; if(window.heroicons){heroicons.createIcons({root:b});}}">
                    <p style="font-size: 13.5px; color: #6b625b; line-height: 1.5; margin-bottom: 16px;">
                        By confirming, you agree to return this book within 7 days. Your borrowing limit is strictly 2 active borrowed books at a time.
                    </p>

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

    <script src="../heroicons.js?v=<?= @filemtime(__DIR__ . '/../heroicons.js') ?: time() ?>"></script>
    <script>
        heroicons.createIcons();
    </script>
</body>
</html>
