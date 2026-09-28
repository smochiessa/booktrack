<?php

require_once "../database.php";

$logo_url = pagelounge_logo_url();

$message = "";
$message_type = "";
$multiple_borrowings = [];
$customer_name_input = "";
$customer_contact_input = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name = trim($_POST['name'] ?? '');
    $rawContact = trim($_POST['contact'] ?? '');
    $digits = preg_replace('/[^0-9]/', '', $rawContact);
    $contact = (str_starts_with($digits, '63') && strlen($digits) === 12) ? '0' . substr($digits, 2) : $digits;
    $customer_name_input = $name;
    $customer_contact_input = $contact;
    $selected_borrowing_id = filter_var($_POST['borrowing_id'] ?? null, FILTER_VALIDATE_INT);

    if ($name === "") {
        $message = "Please enter your name.";
        $message_type = "error";
    } else {
        // Find matching customer(s)
        if ($contact !== "") {
            $cust_stmt = $conn->prepare("SELECT id FROM customers WHERE name = ? AND contact = ?");
            $cust_stmt->bind_param("ss", $name, $contact);
            $cust_stmt->execute();
            $cust_res = $cust_stmt->get_result();
            if ($cust_res->num_rows === 0) {
                // Fallback: search by name in case contact was slightly formatted differently
                $cust_stmt = $conn->prepare("SELECT id FROM customers WHERE name = ?");
                $cust_stmt->bind_param("s", $name);
                $cust_stmt->execute();
                $cust_res = $cust_stmt->get_result();
            }
        } else {
            $cust_stmt = $conn->prepare("SELECT id FROM customers WHERE name = ?");
            $cust_stmt->bind_param("s", $name);
            $cust_stmt->execute();
            $cust_res = $cust_stmt->get_result();
        }

        if ($cust_res->num_rows === 0) {
            $message = "Customer record not found. Please verify your name.";
            $message_type = "error";
        } else {
            $customer_ids = [];
            while ($row = $cust_res->fetch_assoc()) {
                $customer_ids[] = (int)$row['id'];
            }

            // Fetch active borrowings with prepared statement
            $placeholders = implode(',', array_fill(0, count($customer_ids), '?'));
            $borrow_sql = "
                SELECT borrowings.id, borrowings.book_id, books.title, books.author, borrowings.borrow_date, borrowings.due_date
                FROM borrowings
                JOIN books ON borrowings.book_id = books.id
                WHERE borrowings.customer_id IN ($placeholders)
                AND borrowings.status = 'Borrowed'
                ORDER BY borrowings.borrow_date DESC
            ";
            $b_stmt = $conn->prepare($borrow_sql);
            $types = str_repeat('i', count($customer_ids));
            $b_stmt->bind_param($types, ...$customer_ids);
            $b_stmt->execute();
            $borrow_result = $b_stmt->get_result();

            if (!$borrow_result || $borrow_result->num_rows === 0) {
                $message = "You do not have any active borrowed books to return.";
                $message_type = "error";
            } else {
                $active_items = [];
                while ($b = $borrow_result->fetch_assoc()) {
                    $active_items[] = $b;
                }

                // If a specific borrowing was selected, or if there is only 1 book borrowed
                if ($selected_borrowing_id || count($active_items) === 1) {
                    $target = null;
                    if ($selected_borrowing_id) {
                        foreach ($active_items as $item) {
                            if ((int)$item['id'] === $selected_borrowing_id) {
                                $target = $item;
                                break;
                            }
                        }
                    } else {
                        $target = $active_items[0];
                    }

                    if ($target) {
                        $conn->begin_transaction();
                        try {
                            $ret_stmt = $conn->prepare("
                                UPDATE borrowings
                                SET return_date = NOW(), status = 'Returned'
                                WHERE id = ? AND status = 'Borrowed'
                            ");
                            $ret_stmt->bind_param("i", $target['id']);
                            $ret_stmt->execute();

                            if ($ret_stmt->affected_rows > 0) {
                                $bk_stmt = $conn->prepare("UPDATE books SET status = 'Available' WHERE id = ?");
                                $bk_stmt->bind_param("i", $target['book_id']);
                                $bk_stmt->execute();

                                $conn->commit();
                                $message = "Book returned successfully: " . $target['title'];
                                $message_type = "success";
                            } else {
                                $conn->rollback();
                                $message = "This book was already returned or could not be found.";
                                $message_type = "error";
                            }
                        } catch (\Throwable $e) {
                            $conn->rollback();
                            $message = "Something went wrong processing your return. Please try again.";
                            $message_type = "error";
                        }
                    } else {
                        $message = "Selected book borrowing not found.";
                        $message_type = "error";
                    }
                } else {
                    // Multiple borrowings found; prompt user to pick
                    $multiple_borrowings = $active_items;
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
    <title>Return Book - Page Lounge</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Alex+Brush&family=Playfair+Display:ital,wght@0,400..700;1,400..700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../style.css">
    <style>
        .return-page {
            max-width: 600px;
            margin: 50px auto;
            padding: 20px;
        }

        .return-card {
            background: #ffffff;
            padding: 35px;
            border-radius: 6px;
            border: 1px solid #e5dfd5;
        }

        .return-card h1 {
            margin-bottom: 10px;
            font-size: 26px;
            color: #241e1a;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .return-card > p {
            color: #6b625b;
            margin-bottom: 25px;
            font-size: 15px;
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
            font-size: 15px;
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

        .return-button {
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

        .return-button:hover {
            background: #443830;
        }

        .success {
            background: #edf7ed;
            color: #1e6b24;
            padding: 16px;
            border-radius: 6px;
            border: 1px solid #c8e6c9;
            margin-bottom: 20px;
            font-weight: 500;
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

        .borrowings-list {
            margin-top: 20px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .borrowing-item {
            background: #fbf9f6;
            padding: 16px;
            border-radius: 6px;
            border: 1px solid #e5dfd5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .borrowing-item h3 {
            font-size: 16px;
            color: #241e1a;
            margin-bottom: 4px;
        }

        .borrowing-item p {
            font-size: 13px;
            color: #6b625b;
            margin: 0;
        }

        .select-return-btn {
            background: #2e2620;
            color: white;
            border: 1px solid #241e1a;
            padding: 9px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            min-height: 44px;
            transition: background 0.15s ease;
        }

        .select-return-btn:hover {
            background: #443830;
        }

        .links-row {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 25px;
            flex-wrap: wrap;
        }

        .back-link {
            color: #2e2620;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 14px;
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
            Book Returns Desk
        </div>
    </header>

    <main class="return-page">
        <div class="return-card">
            <h1><i data-heroicon="arrow-uturn-left"></i> Return a Book</h1>
            <p>Enter your information to find and return your borrowed books.</p>

            <?php if ($message !== ""): ?>
                <div class="<?php echo htmlspecialchars($message_type); ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($multiple_borrowings)): ?>
                <p><strong>You have multiple books borrowed. Please select which one to return:</strong></p>
                <div class="borrowings-list">
                    <?php foreach ($multiple_borrowings as $b): ?>
                        <div class="borrowing-item">
                            <div>
                                <h3><?php echo htmlspecialchars($b['title']); ?></h3>
                                <p>by <?php echo htmlspecialchars($b['author']); ?> &bull; Due: <?php echo date("M d, Y", strtotime($b['due_date'])); ?></p>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="name" value="<?php echo htmlspecialchars($customer_name_input); ?>">
                                <input type="hidden" name="contact" value="<?php echo htmlspecialchars($customer_contact_input); ?>">
                                <input type="hidden" name="borrowing_id" value="<?php echo (int)$b['id']; ?>">
                                <button type="submit" class="select-return-btn">
                                    <i data-heroicon="arrow-uturn-left"></i> Return This
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($message_type !== "success"): ?>
                <form method="POST" onsubmit="var b=this.querySelector('.return-button'); if(b){b.innerHTML='<i data-heroicon=\'arrow-path\' class=\'heroicon-spin\'></i> Processing...'; if(window.heroicons){heroicons.createIcons({root:b});}}">
                    <label for="name">Customer Name *</label>
                    <input
                        type="text"
                        id="name"
                        name="name"
                        placeholder="Enter your name"
                        value="<?php echo htmlspecialchars($customer_name_input); ?>"
                        required
                    >

                    <label for="contact">Contact Number (Optional)</label>
                    <input
                        type="text"
                        id="contact"
                        name="contact"
                        placeholder="Enter your contact number or email"
                        value="<?php echo htmlspecialchars($customer_contact_input); ?>"
                    >

                    <button type="submit" class="return-button">
                        <i data-heroicon="check"></i> Confirm Return
                    </button>
                </form>
            <?php endif; ?>

            <div class="links-row">
                <a href="../index.php" class="back-link">
                    <i data-heroicon="arrow-left"></i> Home
                </a>
                <a href="books.php" class="back-link">
                    <i data-heroicon="bookmark"></i> Browse Catalog
                </a>
            </div>
        </div>
    </main>

    <script src="../heroicons.js?v=<?= @filemtime(__DIR__ . '/../heroicons.js') ?: time() ?>"></script>
    <script>
        heroicons.createIcons();
    </script>
</body>
</html>
