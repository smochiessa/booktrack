<?php

session_start();

require_once "../database.php";
$logo_url = pagelounge_logo_url();

if (isset($_SESSION['librarian'])) {
    header("Location: dashboard.php");
    exit();
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = "Please enter both username and password.";
    } else {
        $stmt = $conn->prepare("SELECT id, username, password FROM admins WHERE username = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($admin = $result->fetch_assoc()) {
                $authenticated = false;

                if (password_verify($password, $admin['password'])) {
                    $authenticated = true;
                } elseif ($password === $admin['password']) {
                    // Transparent migration to secure hash
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    $rehashStmt = $conn->prepare("UPDATE admins SET password = ? WHERE id = ?");
                    if ($rehashStmt) {
                        $rehashStmt->bind_param("si", $newHash, $admin['id']);
                        $rehashStmt->execute();
                        $rehashStmt->close();
                    }
                    $authenticated = true;
                }

                if ($authenticated) {
                    $_SESSION['librarian'] = $admin['username'];
                    $_SESSION['admin_id'] = (int)$admin['id'];
                    header("Location: dashboard.php");
                    exit();
                }
            }
            $stmt->close();
        }

        $error = "Invalid username or password.";
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Librarian Login - Page Lounge</title>

    <link rel="stylesheet" href="../style.css">

    <style>

        .login-page {
            min-height: 80vh;

            display: flex;
            justify-content: center;
            align-items: center;

            padding: 30px;
        }

        .login-card {
            background: #ffffff;
            width: 100%;
            max-width: 400px;
            padding: 35px;
            border-radius: 6px;
            border: 1px solid #e5dfd5;
        }

        .login-card h1 {
            text-align: center;
            margin-bottom: 10px;
            color: #241e1a;
        }

        .login-card p {
            text-align: center;
            color: #6b625b;
            margin-bottom: 25px;
        }

        label {
            display: block;
            margin-top: 15px;
            margin-bottom: 6px;
            font-weight: 600;
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

        .login-button {
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
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: background 0.15s ease;
        }

        .login-button:hover {
            background: #443830;
        }

        .error {
            background: #fdeeed;
            color: #c62828;
            padding: 12px;
            border-radius: 6px;
            border: 1px solid #ffcdd2;
            margin-bottom: 15px;
            text-align: center;
            font-weight: 500;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
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
        Librarian Portal
    </div>
</header>


<main class="login-page">

    <div class="login-card">

        <div class="welcome-logo-badge" style="width: 80px; height: 80px; margin: 0 auto 16px;">
            <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="Page Lounge Emblem" onerror="this.onerror=null; this.src='../logo.svg';">
        </div>

        <h1>Librarian Login</h1>

        <p>
            Sign in to access the Page Lounge librarian dashboard.
        </p>


        <?php if ($error != ""): ?>

            <div class="error">
                <?php echo htmlspecialchars($error); ?>
            </div>

        <?php endif; ?>


        <form method="POST" onsubmit="var b=this.querySelector('.login-button'); if(b){b.innerHTML='<i data-heroicon=\'arrow-path\' class=\'heroicon-spin\'></i> Signing in...'; if(window.heroicons){heroicons.createIcons({root:b});}}">

            <label for="username">
                Username
            </label>

            <input
                type="text"
                id="username"
                name="username"
                placeholder="Enter username"
                required
            >


            <label for="password">
                Password
            </label>

            <input
                type="password"
                id="password"
                name="password"
                placeholder="Enter password"
                required
            >


            <button
                type="submit"
                class="login-button"
            >
                <i data-heroicon="arrow-right-on-rectangle"></i> Login
            </button>

        </form>

        <a href="../index.php" class="back-link">
            <i data-heroicon="arrow-left"></i> Back to Home
        </a>

    </div>

</main>

<script src="../heroicons.js?v=<?= @filemtime(__DIR__ . '/../heroicons.js') ?: time() ?>"></script>
<script>
    heroicons.createIcons();
</script>
</body>

</html>
