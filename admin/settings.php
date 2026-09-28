<?php

session_start();

require_once "../database.php";

$logo_url = pagelounge_logo_url();

if (!isset($_SESSION['librarian'])) {
    header("Location: login.php");
    exit();
}

$current_admin_id = $_SESSION['admin_id'] ?? null;
if (!$current_admin_id && isset($_SESSION['librarian'])) {
    $findAdmin = $conn->prepare("SELECT id FROM admins WHERE username = ? LIMIT 1");
    if ($findAdmin) {
        $findAdmin->bind_param("s", $_SESSION['librarian']);
        $findAdmin->execute();
        $findRes = $findAdmin->get_result();
        if ($ar = $findRes->fetch_assoc()) {
            $current_admin_id = (int)$ar['id'];
            $_SESSION['admin_id'] = $current_admin_id;
        }
        $findAdmin->close();
    }
}

$settings_error = "";
$settings_success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'update_credentials') {
    $current_password = $_POST['current_password'] ?? '';
    $new_username = trim($_POST['new_username'] ?? '');
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($current_password === '') {
        $settings_error = "Current password is required to save changes.";
    } elseif ($new_username === '') {
        $settings_error = "Username cannot be empty.";
    } elseif (strlen($new_username) < 3) {
        $settings_error = "Username must be at least 3 characters.";
    } else {
        $curAdmin = null;
        if ($current_admin_id) {
            $curStmt = $conn->prepare("SELECT id, username, password FROM admins WHERE id = ? LIMIT 1");
            if ($curStmt) {
                $curStmt->bind_param("i", $current_admin_id);
                $curStmt->execute();
                $curAdmin = $curStmt->get_result()->fetch_assoc();
                $curStmt->close();
            }
        } else {
            $curStmt = $conn->prepare("SELECT id, username, password FROM admins WHERE username = ? LIMIT 1");
            if ($curStmt) {
                $curStmt->bind_param("s", $_SESSION['librarian']);
                $curStmt->execute();
                $curAdmin = $curStmt->get_result()->fetch_assoc();
                $curStmt->close();
            }
        }

        if (!$curAdmin) {
            $settings_error = "Admin account not found in database.";
        } else {
            $current_admin_id = (int)$curAdmin['id'];
            $_SESSION['admin_id'] = $current_admin_id;

            $verified = password_verify($current_password, $curAdmin['password']);
            if (!$verified && $current_password === $curAdmin['password']) {
                $verified = true;
            }

            if (!$verified) {
                $settings_error = "Current password is incorrect.";
            } else {
                $usernameTaken = false;
                if ($new_username !== $curAdmin['username']) {
                    $uCheck = $conn->prepare("SELECT id FROM admins WHERE username = ? AND id != ? LIMIT 1");
                    if ($uCheck) {
                        $uCheck->bind_param("si", $new_username, $current_admin_id);
                        $uCheck->execute();
                        if ($uCheck->get_result()->num_rows > 0) {
                            $usernameTaken = true;
                        }
                        $uCheck->close();
                    }
                }

                if ($usernameTaken) {
                    $settings_error = "Username '" . htmlspecialchars($new_username) . "' is already in use.";
                } else {
                    $update_password = false;
                    $password_hash = $curAdmin['password'];

                    if ($new_password !== '') {
                        if (strlen($new_password) < 6) {
                            $settings_error = "New password must be at least 6 characters.";
                        } elseif ($new_password !== $confirm_password) {
                            $settings_error = "New password and confirmation do not match.";
                        } else {
                            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                            $update_password = true;
                        }
                    }

                    if ($settings_error === '') {
                        $upStmt = $conn->prepare("UPDATE admins SET username = ?, password = ? WHERE id = ?");
                        if ($upStmt) {
                            $upStmt->bind_param("ssi", $new_username, $password_hash, $current_admin_id);
                            if ($upStmt->execute()) {
                                $_SESSION['librarian'] = $new_username;
                                $settings_success = $update_password
                                    ? "Username and password updated successfully."
                                    : "Username updated successfully.";
                            } else {
                                $settings_error = "Database update failed. Please try again.";
                            }
                            $upStmt->close();
                        }
                    }
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
    <title>Account Settings - Page Lounge</title>
    <link rel="stylesheet" href="../style.css">

    <style>
        .settings-page {
            width: 100%;
            max-width: 800px;
            margin: 0;
            padding: 0;
        }

        .page-header {
            margin-bottom: 24px;
        }

        .page-header h1 {
            font-size: 28px;
            color: #241e1a;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 6px;
        }

        .page-header p {
            color: #6b625b;
            font-size: 15px;
        }

        .settings-card {
            background: #ffffff;
            padding: 28px 32px;
            border-radius: 6px;
            border: 1px solid #e5dfd5;
        }

        .settings-card-header {
            margin-bottom: 22px;
        }

        .settings-card-header h2 {
            font-size: 20px;
            color: #241e1a;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 6px;
        }

        .settings-card-header p {
            color: #6b625b;
            font-size: 14px;
        }

        .settings-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            margin-bottom: 24px;
        }

        .settings-grid .full-width {
            grid-column: 1 / -1;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-size: 14px;
            font-weight: 600;
            color: #2e2620;
            margin-bottom: 6px;
        }

        .form-group input {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #d5cbbe;
            border-radius: 6px;
            font-size: 14px;
            background: #ffffff;
            color: #2e2620;
            transition: border-color 0.15s ease, outline 0.15s ease;
        }

        .form-group input:focus {
            border-color: #2e2620;
            outline: 2px solid rgba(46, 38, 32, 0.2);
            outline-offset: 1px;
        }

        .form-group input.input-disabled {
            background: #f4efe8;
            color: #6b625b;
            border-color: #e5dfd5;
            cursor: not-allowed;
        }

        .field-hint {
            font-size: 12px;
            color: #6b625b;
            margin-top: 5px;
        }

        .required-asterisk {
            color: #c62828;
        }

        .settings-form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .save-button {
            padding: 11px 24px;
            background: #2e2620;
            color: #ffffff;
            border: 1px solid #241e1a;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: background 0.15s ease;
        }

        .save-button:hover {
            background: #443830;
        }

        @media (max-width: 640px) {
            .settings-grid {
                grid-template-columns: 1fr;
            }
            .settings-form-actions {
                flex-direction: column;
            }
            .save-button {
                width: 100%;
            }
        }
    </style>
</head>

<body>

<div class="admin-layout">

    <aside class="admin-sidebar">

        <div class="admin-sidebar-header">
            <a href="dashboard.php" class="brand-link">
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

            <button type="button" class="admin-burger-btn" id="adminBurgerBtn" aria-label="Toggle navigation menu" aria-expanded="false" onclick="var s=document.querySelector('.admin-sidebar'); s.classList.toggle('nav-open'); this.setAttribute('aria-expanded', s.classList.contains('nav-open'));">
                <i data-heroicon="bars-3" class="burger-icon-bars"></i>
                <i data-heroicon="x-mark" class="burger-icon-close"></i>
                <span>Menu</span>
            </button>
        </div>

        <div class="admin-sidebar-collapse">
            <div class="sidebar-top">
                <div class="sidebar-subtitle">
                    Librarian Portal
                </div>

                <nav class="sidebar-nav">
                    <a href="dashboard.php">
                        <i data-heroicon="squares-2x2"></i> Dashboard
                    </a>
                    <a href="books.php">
                        <i data-heroicon="rectangle-stack"></i> Book Inventory
                    </a>
                    <a href="borrowings.php">
                        <i data-heroicon="clock"></i> Borrowing History
                    </a>
                    <a href="reports.php">
                        <i data-heroicon="chart-bar"></i> Reports
                    </a>
                    <a href="settings.php" class="active">
                        <i data-heroicon="cog-6-tooth"></i> Account Settings
                    </a>
                </nav>
            </div>

            <div class="sidebar-bottom">
                <a href="logout.php" class="sidebar-logout">
                    <i data-heroicon="arrow-left-on-rectangle"></i> Logout
                </a>
            </div>
        </div>

    </aside>

    <div class="admin-main">

        <main class="settings-page">

            <div class="page-header">
                <h1>
                    <i data-heroicon="cog-6-tooth"></i> Account Settings
                </h1>
                <p>
                    Manage your librarian portal login credentials and security.
                </p>
            </div>

            <div class="settings-card">

                <div class="settings-card-header">
                    <h2>
                        <i data-heroicon="shield-check"></i> Account Credentials
                    </h2>
                    <p>
                        Change the librarian username and password used to access this portal.
                    </p>
                </div>

                <?php if ($settings_error !== ""): ?>
                    <div class="error" role="alert">
                        <i data-heroicon="exclamation-circle"></i> <?php echo htmlspecialchars($settings_error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($settings_success !== ""): ?>
                    <div class="success" role="status">
                        <i data-heroicon="check-circle"></i> <?php echo htmlspecialchars($settings_success); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="settings.php" class="settings-form" onsubmit="var b=this.querySelector('.save-button'); if(b){b.innerHTML='<i data-heroicon=\'arrow-path\' class=\'heroicon-spin\'></i> Saving...'; if(window.heroicons){heroicons.createIcons({root:b});}}">
                    <input type="hidden" name="action" value="update_credentials">

                    <div class="settings-grid">
                        <div class="form-group">
                            <label for="current_username_display">Active Username</label>
                            <input
                                type="text"
                                id="current_username_display"
                                value="<?php echo htmlspecialchars($_SESSION['librarian']); ?>"
                                disabled
                                class="input-disabled"
                            >
                        </div>

                        <div class="form-group">
                            <label for="new_username">New Username <span class="required-asterisk">*</span></label>
                            <input
                                type="text"
                                id="new_username"
                                name="new_username"
                                value="<?php echo htmlspecialchars($_SESSION['librarian']); ?>"
                                required
                                autocomplete="username"
                                placeholder="Enter new username"
                            >
                        </div>

                        <div class="form-group">
                            <label for="new_password">New Password</label>
                            <input
                                type="password"
                                id="new_password"
                                name="new_password"
                                autocomplete="new-password"
                                placeholder="Leave blank to keep existing password"
                            >
                            <span class="field-hint">Minimum 6 characters (leave empty to keep current password)</span>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password</label>
                            <input
                                type="password"
                                id="confirm_password"
                                name="confirm_password"
                                autocomplete="new-password"
                                placeholder="Re-enter new password"
                            >
                        </div>

                        <div class="form-group full-width">
                            <label for="current_password">Current Password <span class="required-asterisk">*</span></label>
                            <input
                                type="password"
                                id="current_password"
                                name="current_password"
                                required
                                autocomplete="current-password"
                                placeholder="Enter current password to authorize changes"
                            >
                            <span class="field-hint">Required for security verification before saving changes.</span>
                        </div>
                    </div>

                    <div class="settings-form-actions">
                        <button type="submit" class="save-button">
                            <i data-heroicon="check"></i> Save Credentials
                        </button>
                    </div>
                </form>

            </div>

        </main>

    </div>

</div>

<script src="../heroicons.js?v=<?= @filemtime(__DIR__ . '/../heroicons.js') ?: time() ?>"></script>
<script>
    heroicons.createIcons();
    (function () {
        var toggle = document.getElementById('adminBurgerBtn');
        var sidebar = document.querySelector('.admin-sidebar');
        if (!toggle || !sidebar) return;
        document.addEventListener('click', function (e) {
            if (sidebar.classList.contains('nav-open') && !sidebar.contains(e.target)) {
                sidebar.classList.remove('nav-open');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
    })();
</script>
</body>

</html>
