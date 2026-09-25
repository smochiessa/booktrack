
<?php

session_start();

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $username = $_POST['username'];
    $password = $_POST['password'];

    // Temporary librarian account
    if ($username === "librarian" && $password === "booktrack123") {

        $_SESSION['librarian'] = $username;

        header("Location: dashboard.php");
        exit();

    } else {

        $error = "Invalid username or password.";

    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Librarian Login - BookTrack</title>

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
            background: white;

            width: 400px;

            padding: 35px;

            border-radius: 15px;

            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }

        .login-card h1 {
            text-align: center;
            margin-bottom: 10px;
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

            font-weight: bold;
        }

        input {
            width: 100%;

            padding: 12px;

            border: 1px solid #ccc;

            border-radius: 7px;

            font-size: 15px;
        }

        .login-button {
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

        .login-button:hover {
            background: #5f3b27;
        }

        .error {
            background: #ffebee;

            color: #c62828;

            padding: 12px;

            border-radius: 7px;

            margin-bottom: 15px;

            text-align: center;
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
        Librarian Portal
    </div>

</header>


<main class="login-page">

    <div class="login-card">

        <h1>🔐 Librarian Login</h1>

        <p>
            Sign in to access the BookTrack dashboard.
        </p>


        <?php if ($error != ""): ?>

            <div class="error">
                <?php echo htmlspecialchars($error); ?>
            </div>

        <?php endif; ?>


        <form method="POST">

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
                Login
            </button>

        </form>


        

    </div>

</main>


<footer>

    <p>
        © 2026 BookTrack Café Library
    </p>

</footer>


</body>

</html>

