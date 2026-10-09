<?php

session_start();

if (!isset($_SESSION['librarian'])) {
    header("Location: login.php");
    exit;
}

require_once "../database.php";
$logo_url = pagelounge_logo_url();
$pending_count = pagelounge_pending_customers_count($conn);

// Include QR generator autoload if needed
$autoload_file = "../vendor/autoload.php";
if (file_exists($autoload_file)) {
    require_once $autoload_file;
}

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;

$search = trim($_GET['search'] ?? '');
$category = trim($_GET['category'] ?? '');

// Fetch distinct categories
$cat_res = $conn->query("SELECT DISTINCT category FROM books WHERE category IS NOT NULL AND category != '' ORDER BY category ASC");
$categories = [];
if ($cat_res) {
    while ($cr = $cat_res->fetch_assoc()) {
        $categories[] = $cr['category'];
    }
}

// Build books query
$sql = "SELECT id, title, author, category, isbn, total_copies, available_copies, qr_code, image FROM books WHERE 1=1";
$params = [];
$types = "";

if ($search !== '') {
    $sql .= " AND (title LIKE ? OR author LIKE ? OR isbn LIKE ?)";
    $term = "%" . $search . "%";
    $types .= "sss";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

if ($category !== '') {
    $sql .= " AND category = ?";
    $types .= "s";
    $params[] = $category;
}

$sql .= " ORDER BY id ASC";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $books_res = $stmt->get_result();
} else {
    $books_res = $conn->query($sql);
}

// Helper to ensure QR image exists on disk
function ensure_qr_file(mysqli $conn, array $b): string {
    $qrPath = $b['qr_code'] ?? '';
    if ($qrPath !== '' && file_exists(__DIR__ . '/../' . $qrPath)) {
        return '../' . $qrPath;
    }

    $qrDir = __DIR__ . "/../qrcodes";
    if (!is_dir($qrDir)) {
        @mkdir($qrDir, 0755, true);
    }

    if (class_exists('Endroid\QrCode\QrCode')) {
        try {
            $base_url = pagelounge_base_url();
            $qrText = $base_url . "/customer/borrow.php?book_id=" . $b['id'];

            $qrCode = new QrCode(data: $qrText, size: 300, margin: 10);
            if (extension_loaded('gd')) {
                $writer = new PngWriter();
                $ext = "png";
            } else {
                $writer = new SvgWriter();
                $ext = "svg";
            }
            $result = $writer->write($qrCode);
            $fileName = "book_" . $b['id'] . "." . $ext;
            $filePath = $qrDir . "/" . $fileName;
            $result->saveToFile($filePath);

            $newQrPath = "qrcodes/" . $fileName;
            $upd = $conn->prepare("UPDATE books SET qr_code = ? WHERE id = ?");
            $upd->bind_param("si", $newQrPath, $b['id']);
            $upd->execute();

            return '../' . $newQrPath;
        } catch (\Throwable) {}
    }

    return '';
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Printable QR Codes - Page Lounge</title>
    <link rel="stylesheet" href="../style.css">
    <style>
        .print-toolbar {
            background: #ffffff;
            border: 1px solid #e5dfd5;
            border-radius: 8px;
            padding: 18px 20px;
            display: flex;
            gap: 14px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(46, 38, 32, 0.04);
        }

        .print-toolbar-search {
            flex: 1;
            min-width: 200px;
            padding: 10px 14px;
            border: 1px solid #d5cbbe;
            border-radius: 6px;
            font-size: 14px;
            color: #2e2620;
            outline: none;
        }

        .print-toolbar-search:focus {
            border-color: #2e2620;
            outline: 2px solid rgba(46, 38, 32, 0.2);
        }

        .print-toolbar-select {
            padding: 10px 14px;
            border: 1px solid #d5cbbe;
            border-radius: 6px;
            font-size: 14px;
            color: #2e2620;
            outline: none;
            cursor: pointer;
            min-height: 42px;
        }

        .print-toolbar-select:focus,
        .print-toolbar-select:focus-visible {
            border-color: #2e2620;
            outline: 2px solid rgba(46, 38, 32, 0.2);
            outline-offset: 1px;
        }

        .btn-print-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 20px;
            background: #2e2620;
            color: #ffffff;
            border: 1px solid #241e1a;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            min-height: 42px;
            transition: background 0.15s ease;
        }

        .btn-print-action:hover {
            background: #443830;
        }

        .btn-print-action:focus-visible {
            outline: 2px solid #2e2620;
            outline-offset: 2px;
        }

        .qr-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 16px;
        }

        .qr-sticker-card {
            background: #ffffff;
            border: 1.5px dashed #c5baa8;
            border-radius: 8px;
            padding: 16px;
            text-align: center;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            page-break-inside: avoid;
            break-inside: avoid;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .qr-sticker-card:hover {
            box-shadow: 0 4px 12px rgba(46, 38, 32, 0.08);
            border-color: #2e2620;
        }

        .sticker-brand-header {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin-bottom: 10px;
            width: 100%;
            border-bottom: 1px solid #f0ebe4;
            padding-bottom: 6px;
        }

        .sticker-brand-logo {
            width: 18px;
            height: 18px;
            object-fit: contain;
        }

        .sticker-brand-name {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.5px;
            color: #241e1a;
            text-transform: uppercase;
        }

        .sticker-qr-img {
            width: 140px;
            height: 140px;
            object-fit: contain;
            display: block;
            margin: 6px auto;
            border-radius: 4px;
            border: 1px solid #f0ebe4;
            padding: 4px;
            background: #ffffff;
        }

        .sticker-book-title {
            font-size: 13.5px;
            font-weight: 700;
            color: #241e1a;
            margin-top: 6px;
            line-height: 1.3;
            max-height: 36px;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            line-clamp: 2;
            -webkit-box-orient: vertical;
        }

        .sticker-book-author {
            font-size: 12px;
            color: #6b625b;
            margin-top: 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            width: 100%;
        }

        .sticker-details-row {
            display: flex;
            justify-content: space-between;
            width: 100%;
            margin-top: 8px;
            padding-top: 6px;
            border-top: 1px dashed #e5dfd5;
            font-size: 11px;
            color: #635b54;
        }

        .sticker-id-badge {
            font-weight: 700;
            color: #2e2620;
            background: #f4efe8;
            padding: 1px 6px;
            border-radius: 3px;
        }

        .sticker-cut-guide {
            position: absolute;
            top: -7px;
            right: 12px;
            background: #ffffff;
            padding: 0 4px;
            font-size: 9px;
            color: #635b54;
            text-transform: uppercase;
            font-weight: 600;
        }

        /* PRINT STYLESHEET */
        @media print {
            @page {
                size: A4 portrait;
                margin: 8mm;
            }

            body {
                background: #ffffff !important;
                color: #000000 !important;
                font-family: Arial, sans-serif !important;
            }

            .admin-sidebar,
            .admin-sidebar-header,
            .admin-burger-btn,
            .print-toolbar,
            .no-print,
            header,
            footer {
                display: none !important;
            }

            .admin-layout, .admin-main, main {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
            }

            .admin-header-title {
                display: none !important;
            }

            .qr-cards-grid {
                display: grid !important;
                grid-template-columns: repeat(3, 1fr) !important;
                gap: 6mm !important;
                width: 100% !important;
            }

            .qr-sticker-card {
                border: 1px dashed #444444 !important;
                border-radius: 4px !important;
                padding: 10px !important;
                box-shadow: none !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }

            .sticker-qr-img {
                width: 120px !important;
                height: 120px !important;
                border: 1px solid #cccccc !important;
            }

            .sticker-cut-guide {
                display: block !important;
            }
        }
    </style>
</head>

<body>

<div class="admin-layout">

    <aside class="admin-sidebar no-print">

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
                    <a href="customers.php">
                        <i data-heroicon="user-group"></i> Customers
                        <?php if ($pending_count > 0): ?>
                            <span style="background: #ef4444; color: white; border-radius: 10px; padding: 1px 6px; font-size: 11px; font-weight: 700; margin-left: 6px;"><?php echo $pending_count; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="books.php">
                        <i data-heroicon="rectangle-stack"></i> Book Inventory
                    </a>
                    <a href="borrowings.php">
                        <i data-heroicon="clock"></i> Borrowing History
                    </a>
                    <a href="scan_return.php">
                        <i data-heroicon="qr-code"></i> Scan Return
                    </a>
                    <a href="print_qrs.php" class="active">
                        <i data-heroicon="printer"></i> Print QRs
                    </a>
                    <a href="reports.php">
                        <i data-heroicon="chart-bar"></i> Reports
                    </a>
                    <a href="settings.php">
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

        <main>

            <div class="admin-header-title no-print">
                <h1><i data-heroicon="printer"></i> Printable Book QR Cards</h1>
                <p>Generate, preview, and print sticker labels or shelf cards with high-resolution QR codes.</p>
            </div>

            <form method="GET" class="print-toolbar no-print">
                <input
                    type="text"
                    name="search"
                    placeholder="Search by title, author, or ISBN..."
                    value="<?php echo htmlspecialchars($search); ?>"
                    class="print-toolbar-search"
                >

                <select name="category" class="print-toolbar-select">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category === $cat ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="btn-print-action" style="background: #443830;">
                    <i data-heroicon="magnifying-glass"></i> Filter
                </button>

                <?php if ($search !== '' || $category !== ''): ?>
                    <a href="print_qrs.php" class="btn-print-action" style="background: #f4efe8; color: #2e2620; text-decoration: none; border: 1px solid #d5cbbe;">
                        <i data-heroicon="x-mark"></i> Reset
                    </a>
                <?php endif; ?>

                <button type="button" class="btn-print-action" style="margin-left: auto;" onclick="window.print();">
                    <i data-heroicon="printer"></i> Print All Labels
                </button>
            </form>

            <div class="qr-cards-grid">

                <?php if ($books_res && $books_res->num_rows > 0): ?>

                    <?php while ($b = $books_res->fetch_assoc()): ?>
                        <?php
                            $qrImgUrl = ensure_qr_file($conn, $b);
                            $formatted_id = sprintf("#%04d", (int)$b['id']);
                        ?>
                        <div class="qr-sticker-card">
                            <span class="sticker-cut-guide no-print">✂ Cut Line</span>

                            <div class="sticker-brand-header">
                                <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="Logo" class="sticker-brand-logo" onerror="this.onerror=null; this.src='../logo.svg';">
                                <span class="sticker-brand-name">Page Lounge Book Café</span>
                            </div>

                            <?php if ($qrImgUrl !== ''): ?>
                                <img src="<?php echo htmlspecialchars($qrImgUrl); ?>" alt="QR Code" class="sticker-qr-img">
                            <?php else: ?>
                                <div class="sticker-qr-img" style="display:flex;align-items:center;justify-content:center;color:#635b54;font-size:11px;">
                                    No QR
                                </div>
                            <?php endif; ?>

                            <div class="sticker-book-title" title="<?php echo htmlspecialchars($b['title']); ?>">
                                <?php echo htmlspecialchars($b['title']); ?>
                            </div>

                            <div class="sticker-book-author">
                                By <?php echo htmlspecialchars($b['author']); ?>
                            </div>

                            <div class="sticker-details-row">
                                <span class="sticker-id-badge"><?php echo $formatted_id; ?></span>
                                <span><?php echo htmlspecialchars($b['category'] ?: 'General'); ?></span>
                                <span>Copies: <?php echo (int)($b['total_copies'] ?? 1); ?></span>
                            </div>
                        </div>
                    <?php endwhile; ?>

                <?php else: ?>

                    <div style="grid-column: 1 / -1; text-align: center; padding: 60px 20px; background: #ffffff; border: 1px solid #e5dfd5; border-radius: 8px; color: #635b54;">
                        <i data-heroicon="book-open" style="width: 40px; height: 40px; margin-bottom: 12px; opacity: 0.5;"></i>
                        <p style="font-size: 15px; font-weight: 600;">No books match your search or filter.</p>
                    </div>

                <?php endif; ?>

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
        if (toggle && sidebar) {
            document.addEventListener('click', function (e) {
                if (sidebar.classList.contains('nav-open') && !sidebar.contains(e.target)) {
                    sidebar.classList.remove('nav-open');
                    toggle.setAttribute('aria-expanded', 'false');
                }
            });
        }
    })();
</script>

</body>
</html>
