<?php
session_start();

require_once "../database.php";
$logo_url = pagelounge_logo_url();

if (isset($_SESSION['customer_id'])) {
    header("Location: books.php");
    exit();
}

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name = trim(preg_replace('/\s+/', ' ', $_POST['name'] ?? ''));
    $email = trim(strtolower($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $rawContact = trim($_POST['contact'] ?? '');
    $contact = pagelounge_normalize_contact($rawContact);

    if ($name === "" || $email === "" || $password === "") {
        $error = "Please fill in all required fields (Name, Email, and Password).";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please provide a valid email address.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } elseif (empty($_FILES['id_document']['tmp_name'])) {
        $error = "Please upload a photo of your valid ID for account verification.";
    } else {
        // Check for duplicate email
        $chk_stmt = $conn->prepare("SELECT id FROM customers WHERE email = ? LIMIT 1");
        $chk_stmt->bind_param("s", $email);
        $chk_stmt->execute();
        if ($chk_stmt->get_result()->num_rows > 0) {
            $error = "An account with this email address already exists. Please log in.";
        } else {
            // Handle ID file upload
            $file = $_FILES['id_document'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $error = "Error uploading ID document. Please try again.";
            } else {
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'webp'];
                $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp'];

                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

                $mime = '';
                if (class_exists('finfo')) {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->file($file['tmp_name']) ?: '';
                } elseif (function_exists('mime_content_type')) {
                    $mime = mime_content_type($file['tmp_name']) ?: '';
                }

                if (!in_array($ext, $allowed_extensions, true) || ($mime !== '' && !in_array($mime, $allowed_mimes, true))) {
                    $error = "Invalid image format. Please upload a JPG, PNG, or WEBP image.";
                } elseif ($file['size'] > 5 * 1024 * 1024) {
                    $error = "The uploaded file is too large. Maximum file size is 5MB.";
                } else {
                    $targetDir = __DIR__ . '/../uploads/customer_ids';
                    if (!is_dir($targetDir)) {
                        @mkdir($targetDir, 0755, true);
                    }

                    $fileName = 'id_' . uniqid() . '_' . time() . '.' . $ext;
                    $targetPath = $targetDir . '/' . $fileName;

                    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                        $id_relative_path = 'uploads/customer_ids/' . $fileName;
                        $pass_hash = password_hash($password, PASSWORD_DEFAULT);

                        $insert_stmt = $conn->prepare("INSERT INTO customers (name, email, password, contact, id_image, account_status) VALUES (?, ?, ?, ?, ?, 'Pending')");
                        $insert_stmt->bind_param("sssss", $name, $email, $pass_hash, $contact, $id_relative_path);

                        if ($insert_stmt->execute()) {
                            $success = "Registration successful! Your valid ID has been submitted for verification. A librarian will review your account before you can borrow books.";
                        } else {
                            $error = "Registration failed due to a database error: " . $conn->error;
                        }
                    } else {
                        $error = "Failed to save the uploaded ID. Please check server permissions.";
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
    <title>Customer Registration - Page Lounge</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Alex+Brush&family=Playfair+Display:ital,wght@0,400..700;1,400..700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../style.css">
    <style>
        .auth-container {
            max-width: 520px;
            margin: 40px auto;
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
        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group input[type="password"] {
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
        .file-upload-box {
            border: 2px dashed #d5cbbe;
            border-radius: 6px;
            padding: 16px;
            text-align: center;
            background: #faf7f3;
            cursor: pointer;
            transition: border-color 0.15s;
        }
        .file-upload-box:hover {
            border-color: #8c7355;
        }
        .file-upload-box input[type="file"] {
            display: none;
        }
        .file-label {
            cursor: pointer;
            font-size: 13px;
            color: #554b43;
            display: block;
        }
        .file-label strong {
            color: #2e2620;
            display: block;
            margin-bottom: 4px;
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
        .alert-success {
            background: #def7ec;
            color: #03543f;
            padding: 12px 14px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 13.5px;
            border: 1px solid #bcf0da;
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
            <a href="login.php" class="header-admin-btn">Log In</a>
            <a href="books.php" class="header-admin-btn" style="background:#f4efe8; color:#2e2620; border-color:#d5cbbe;">Catalog</a>
        </div>
    </header>

    <main class="auth-container">
        <div class="auth-card">
            <h1>Create Customer Account</h1>
            <p class="subtitle">Register to borrow books. An ID upload is required for verification.</p>

            <?php if (!empty($error)): ?>
                <div class="alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert-success">
                    <?php echo htmlspecialchars($success); ?>
                    <div style="margin-top: 12px;">
                        <a href="login.php" class="btn-submit" style="display:inline-block; text-align:center; text-decoration:none;">Proceed to Login</a>
                    </div>
                </div>
            <?php else: ?>
                <form action="register.php" method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="name">Full Name *</label>
                        <input type="text" id="name" name="name" placeholder="e.g. Maria Santos" required value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address *</label>
                        <input type="email" id="email" name="email" placeholder="e.g. maria@example.com" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="password">Password (min. 6 characters) *</label>
                        <input type="password" id="password" name="password" placeholder="Create a password" required minlength="6">
                    </div>

                    <div class="form-group">
                        <label for="contact">Contact Number</label>
                        <input type="text" id="contact" name="contact" placeholder="e.g. 09171234567" value="<?php echo htmlspecialchars($_POST['contact'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label>Valid ID Photo for Authentication *</label>
                        <div class="file-upload-box" onclick="document.getElementById('id_document').click();">
                            <input type="file" id="id_document" name="id_document" accept="image/png, image/jpeg, image/webp" required onchange="handleFileSelected(this);">
                            <span class="file-label" id="fileLabel">
                                <strong>Click to upload Valid ID</strong>
                                <span>Accepted: Student ID, Government ID, Driver's License (JPG, PNG, WEBP max 5MB)</span>
                            </span>
                        </div>
                    </div>

                    <button type="submit" class="btn-submit">Register Account</button>
                </form>
            <?php endif; ?>

            <div class="auth-footer">
                Already have an account? <a href="login.php">Log In here</a>
            </div>
        </div>
    </main>

    <script>
        function handleFileSelected(input) {
            var label = document.getElementById('fileLabel');
            if (input.files && input.files[0]) {
                label.innerHTML = '<strong>Selected: ' + input.files[0].name + '</strong><span>(' + (input.files[0].size / 1024 / 1024).toFixed(2) + ' MB)</span>';
            }
        }
    </script>
</body>
</html>
