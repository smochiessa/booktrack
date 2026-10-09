<?php
session_start();
require_once __DIR__ . '/database.php';
$logo_url = pagelounge_logo_url();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Page Lounge - Book Café Library</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Alex+Brush&family=Playfair+Display:ital,wght@0,400..700;1,400..700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
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
            <?php if (isset($_SESSION['customer_id'])): ?>
                <span style="font-size: 13px; color: #554b43;">
                    Hello, <strong><?php echo htmlspecialchars($_SESSION['customer_name']); ?></strong>
                </span>
                <a href="customer/logout.php" class="header-admin-btn" style="background:#f4efe8; color:#2e2620; border-color:#d5cbbe;">Logout</a>
            <?php else: ?>
                <a href="customer/login.php" class="header-admin-btn" style="background:#f4efe8; color:#2e2620; border-color:#d5cbbe;">Customer Login</a>
            <?php endif; ?>
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
