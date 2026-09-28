<?php
require_once __DIR__ . '/database.php';
$logo_url = pagelounge_logo_url();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Page Lounge - Book Café Library</title>

    <link rel="stylesheet" href="style.css">
</head>

<body>

    <header>

        <a href="index.php" class="brand-link">
            <div class="brand-emblem-wrap">
                <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="Page Lounge Logo" class="brand-emblem-img" onerror="this.onerror=null; this.src='logo.svg';">
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
            <span class="header-text">Where books meet comfort</span>
            <a href="admin/login.php" class="header-admin-btn">Librarian Login</a>
        </div>

    </header>


    <main>

        <section class="welcome">

            <div class="welcome-logo-badge">
                <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="Page Lounge Emblem" onerror="this.onerror=null; this.src='logo.svg';">
            </div>

            <h1>Welcome to Page <span class="script-word">Lounge</span></h1>

            <p>
                Browse, borrow, and return books through our
                self-service café library system.
            </p>


            <div class="buttons">

                <a href="customer/books.php" class="button">
                    <i data-heroicon="bookmark"></i> Browse Books
                </a>

                <a href="customer/return.php" class="button button-secondary">
                    <i data-heroicon="arrow-uturn-left"></i> Return a Book
                </a>

            </div>

        </section>

    </main>

    <script src="heroicons.js?v=<?= @filemtime(__DIR__ . '/heroicons.js') ?: time() ?>"></script>
    <script>
        heroicons.createIcons();
    </script>
</body>

</html>
