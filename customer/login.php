<?php
session_start();

require_once "../database.php";
$logo_url = pagelounge_logo_url();

if (isset($_SESSION['customer_id'])) {
    header("Location: books.php");
    exit();
}

$error = "";
$redirect = trim($_GET['redirect'] ?? '');
$book_id = filter_var($_GET['book_id'] ?? null, FILTER_VALIDATE_INT);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim(strtolower($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $redirect = trim($_POST['redirect'] ?? '');
    $book_id = filter_var($_POST['book_id'] ?? null, FILTER_VALIDATE_INT);

    if ($email === "" || $password === "") {
        $error = "Please enter both email and password.";
    } else {
        $stmt = $conn->prepare("SELECT id, name, email, password, contact, account_status FROM customers WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($customer = $res->fetch_assoc()) {
            if (!empty($customer['password']) && password_verify($password, $customer['password'])) {
                $_SESSION['customer_id'] = (int)$customer['id'];
                $_SESSION['customer_name'] = $customer['name'];
                $_SESSION['customer_email'] = $customer['email'];
                $_SESSION['account_status'] = $customer['account_status'];

                if ($redirect === 'borrow' && $book_id) {
                    header("Location: borrow.php?book_id=" . $book_id);
                    exit();
                } else {
                    header("Location: books.php");
                    exit();
                }
            } else {
                $error = "Invalid email or password.";
            }
        } else {
            $error = "No account found with this email address.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Login - Page Lounge</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Alex+Brush&family=Playfair+Display:ital,wght@0,400..700;1,400..700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../style.css">
    <style>
        .auth-container {
            max-width: 440px;
            margin: 60px auto;
            padding: 20px;
        }
        .auth-card {
            background: #ffffff;
            padding: 36px;
            border-radius: 8px;
            border: 1px solid #e5dfd5;
            box-shadow: 0 4px 16px rgba(46, 38, 32, 0.04);
        }
        .auth-card h1 {
            font-size: 24px;
            margin-bottom: 6px;
            color: #241e1a;
            text-align: center;
        }
        .auth-card p.subtitle {
            color: #6b625b;
            text-align: center;
            font-size: 14px;
            margin-bottom: 24px;
        }
        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            font-size: 13px;
            color: #2e2620;
        }
        .form-group input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d5cbbe;
            border-radius: 6px;
            font-size: 14px;
            box-sizing: border-box;
            background: #ffffff;
            color: #2e2620;
            outline: none;
            transition: border-color 0.15s ease;
        }
        .form-group input:focus {
            border-color: #2e2620;
            outline: 2px solid rgba(46, 38, 32, 0.15);
        }
        .btn-submit {
            width: 100%;
            padding: 12px;
            background: #2e2620;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s ease;
            margin-top: 10px;
        }
        .btn-submit:hover {
            background: #443830;
        }
        .auth-footer {
            margin-top: 20px;
            text-align: center;
            font-size: 13.5px;
            color: #6b625b;
        }
        .auth-footer a {
            color: #8c7355;
            font-weight: 600;
            text-decoration: none;
        }
        .auth-footer a:hover {
            text-decoration: underline;
        }
        .alert-error {
            background: #fde8e8;
            color: #9b1c1c;
            padding: 12px 14px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 13.5px;
            border: 1px solid #f8b4b4;
        }
        .notice-box {
            background: #fff8f0;
            border: 1px solid #ebd7bf;
            color: #7c4a1e;
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 13px;
            margin-bottom: 18px;
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
        <div class="header-actions">
            <a href="register.php" class="header-admin-btn">Register</a>
            <a href="books.php" class="header-admin-btn" style="background:#f4efe8; color:#2e2620; border-color:#d5cbbe;">Catalog</a>
        </div>
    </header>

    <main class="auth-container">
        <div class="auth-card">
            <h1>Customer Login</h1>
            <p class="subtitle">Log in to manage your borrowings and checkout books.</p>

            <?php if ($redirect === 'borrow'): ?>
                <div class="notice-box">
                    Please log in to your approved account to proceed with borrowing.
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form action="login.php" method="POST">
                <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect); ?>">
                <?php if ($book_id): ?>
                    <input type="hidden" name="book_id" value="<?php echo (int)$book_id; ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" placeholder="e.g. maria@example.com" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                </div>

                <button type="submit" class="btn-submit">Sign In</button>
            </form>

            <div class="auth-footer">
                Don't have an account yet? <a href="register.php">Register with Valid ID</a>
            </div>
        </div>
    </main>
</body>
</html>
