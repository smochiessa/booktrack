<?php

session_start();

if (!isset($_SESSION['librarian'])) {
    header("Location: login.php");
    exit;
}

require_once "../database.php";

$logo_url = pagelounge_logo_url();
$pending_count = pagelounge_pending_customers_count($conn);
$pending_returns_count = pagelounge_pending_returns_count($conn);

// Handle AJAX QR Return Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_qr_return') {
    header('Content-Type: application/json; charset=utf-8');

    $raw_token = trim($_POST['token'] ?? '');
    if ($raw_token === '') {
        echo json_encode(['ok' => false, 'message' => 'Empty QR code data received.']);
        exit;
    }

    // Parse book ID or ISBN from token
    $book_id = null;
    $isbn = null;

    if (preg_match('/book_id=(\d+)/i', $raw_token, $m)) {
        $book_id = (int)$m[1];
    } elseif (preg_match('/^book_?(\d+)$/i', $raw_token, $m)) {
        $book_id = (int)$m[1];
    } elseif (preg_match('/^#?(\d+)$/', $raw_token, $m) && strlen($m[1]) <= 6) {
        $book_id = (int)$m[1];
    } else {
        // Might be an ISBN
        $clean_isbn = preg_replace('/[^0-9X]/i', '', $raw_token);
        if (strlen($clean_isbn) >= 10) {
            $isbn = $raw_token;
        }
    }

    // Lookup book
    $book = null;
    if ($book_id) {
        $b_stmt = $conn->prepare("SELECT id, title, author, category, isbn, total_copies, available_copies, status, image FROM books WHERE id = ?");
        $b_stmt->bind_param("i", $book_id);
        $b_stmt->execute();
        $book = $b_stmt->get_result()->fetch_assoc();
    } elseif ($isbn) {
        $b_stmt = $conn->prepare("SELECT id, title, author, category, isbn, total_copies, available_copies, status, image FROM books WHERE isbn = ? OR isbn LIKE ? LIMIT 1");
        $like_isbn = "%" . $isbn . "%";
        $b_stmt->bind_param("ss", $isbn, $like_isbn);
        $b_stmt->execute();
        $book = $b_stmt->get_result()->fetch_assoc();
        if ($book) {
            $book_id = (int)$book['id'];
        }
    }

    if (!$book) {
        echo json_encode(['ok' => false, 'message' => "Book not found for scanned token: " . htmlspecialchars($raw_token)]);
        exit;
    }

    // Find active borrowing for this book (oldest active loan first)
    $stmt = $conn->prepare("SELECT
                                borrowings.id,
                                borrowings.customer_id,
                                borrowings.borrow_date,
                                borrowings.due_date,
                                customers.name AS customer_name,
                                customers.contact,
                                customers.email
                            FROM borrowings
                            JOIN customers ON borrowings.customer_id = customers.id
                            WHERE borrowings.book_id = ? AND borrowings.status IN ('Borrowed', 'Pending Return')
                            ORDER BY borrowings.borrow_date ASC
                            LIMIT 1");
    $stmt->bind_param("i", $book_id);
    $stmt->execute();
    $borrowing = $stmt->get_result()->fetch_assoc();

    if (!$borrowing) {
        $curr_avail = (int)($book['available_copies'] ?? 1);
        $curr_total = (int)($book['total_copies'] ?? 1);
        echo json_encode([
            'ok' => false,
            'message' => "No active borrowing found for \"" . $book['title'] . "\". All current copies (" . $curr_avail . " of " . $curr_total . ") are already marked available."
        ]);
        exit;
    }

    $borrowing_id = (int)$borrowing['id'];

    // Execute atomic return transaction
    $conn->begin_transaction();
    try {
        $upd_b = $conn->prepare("UPDATE borrowings SET return_date = NOW(), status = 'Returned' WHERE id = ?");
        $upd_b->bind_param("i", $borrowing_id);
        $upd_b->execute();

        $upd_bk = $conn->prepare("UPDATE books SET available_copies = LEAST(total_copies, available_copies + 1), status = 'Available' WHERE id = ?");
        $upd_bk->bind_param("i", $book_id);
        $upd_bk->execute();

        $conn->commit();

        // Refresh book copies
        $fresh_bk = $conn->query("SELECT total_copies, available_copies FROM books WHERE id = $book_id")->fetch_assoc();

        echo json_encode([
            'ok' => true,
            'message' => "Book returned successfully!",
            'book' => [
                'id' => $book_id,
                'title' => $book['title'],
                'author' => $book['author'],
                'available_copies' => (int)$fresh_bk['available_copies'],
                'total_copies' => (int)$fresh_bk['total_copies'],
                'image' => $book['image'] ? '../' . $book['image'] : ''
            ],
            'customer' => [
                'name' => $borrowing['customer_name'],
                'contact' => $borrowing['contact'] ?: $borrowing['email'] ?: 'No contact'
            ],
            'returned_at' => date("M d, Y h:i A")
        ]);
        exit;
    } catch (\Throwable $e) {
        $conn->rollback();
        echo json_encode(['ok' => false, 'message' => "Database error processing return: " . $e->getMessage()]);
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scan Return - Page Lounge</title>
    <link rel="stylesheet" href="../style.css">
    <style>
        .scan-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-top: 20px;
            max-width: 1080px;
            align-items: stretch;
        }

        .scan-card, .history-feed-card {
            background: #ffffff;
            border: 1px solid #e5dfd5;
            border-radius: 8px;
            padding: 24px;
            box-shadow: 0 4px 12px rgba(46, 38, 32, 0.04);
            display: flex;
            flex-direction: column;
        }

        .scan-card h2, .history-feed-card h2 {
            font-size: 18px;
            color: #241e1a;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 16px;
        }

        #qr-reader {
            width: 100%;
            max-width: 360px;
            aspect-ratio: 1 / 1;
            margin: 0 auto;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid #d5cbbe;
            background: #241e1a;
            position: relative;
            box-shadow: 0 2px 8px rgba(46, 38, 32, 0.08);
        }

        #qr-reader video,
        #qr-reader__scan_region video {
            width: 100% !important;
            height: 100% !important;
            object-fit: cover !important;
            border-radius: 6px;
            display: block;
        }

        #qr-reader__scan_region {
            width: 100% !important;
            height: 100% !important;
            overflow: hidden !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
        }

        #qr-reader__scan_region canvas {
            max-width: 100% !important;
            max-height: 100% !important;
            object-fit: cover !important;
        }

        #qr-shaded-region > div {
            background-color: #d8c29d !important;
            border-color: #d8c29d !important;
            border-radius: 2px !important;
        }

        .scanner-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-top: 14px;
            width: 100%;
            flex-wrap: wrap;
        }

        .scanner-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12.5px;
            padding: 6px 14px;
            border-radius: 20px;
            background: #f4efe8;
            color: #2e2620;
            font-weight: 600;
            height: 34px;
            box-sizing: border-box;
        }

        .scanner-status.scanning {
            background: #edf7ed;
            color: #1e6b24;
            border: 1px solid #c8e6c9;
        }

        .scanner-status.scanning::before {
            content: "";
            width: 8px;
            height: 8px;
            background: #2e7d32;
            border-radius: 50%;
            display: inline-block;
            animation: pulse-dot 1.5s infinite;
        }

        @keyframes pulse-dot {
            0% { transform: scale(0.9); opacity: 0.7; }
            50% { transform: scale(1.2); opacity: 1; }
            100% { transform: scale(0.9); opacity: 0.7; }
        }

        .manual-input-box {
            margin-top: 20px;
            padding-top: 18px;
            border-top: 1px solid #eae4db;
            width: 100%;
        }

        .manual-input-box label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 600;
            color: #2e2620;
            margin-bottom: 8px;
        }

        .manual-form-row {
            display: flex;
            gap: 8px;
            align-items: stretch;
        }

        .manual-input {
            flex: 1;
            height: 40px;
            padding: 0 14px;
            border: 1px solid #d5cbbe;
            border-radius: 6px;
            font-size: 13.5px;
            color: #2e2620;
            outline: none;
            background: #ffffff;
            box-sizing: border-box;
        }

        .manual-input:focus {
            border-color: #2e2620;
            outline: 2px solid rgba(46, 38, 32, 0.2);
        }

        .manual-btn {
            height: 40px;
            padding: 0 16px;
            background: #2e2620;
            color: #ffffff;
            border: 1px solid #241e1a;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            box-sizing: border-box;
            transition: background 0.15s ease;
        }

        .manual-btn:hover {
            background: #443830;
        }

        .ctrl-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            font-size: 12.5px;
            font-weight: 600;
            border-radius: 20px;
            height: 34px;
            cursor: pointer;
            box-sizing: border-box;
            transition: background 0.15s ease, border-color 0.15s ease;
        }

        .ctrl-btn.secondary {
            background: #f4efe8;
            color: #2e2620;
            border: 1px solid #d5cbbe;
        }

        .ctrl-btn.secondary:hover {
            background: #eae4db;
            border-color: #2e2620;
        }

        .feed-list {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 12px;
            max-height: 540px;
            overflow-y: auto;
        }

        #emptyFeedNotice {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 10px;
            color: #635b54;
            font-size: 13.5px;
            text-align: center;
        }

        .feed-item {
            background: #fbf9f6;
            border: 1px solid #e5dfd5;
            border-radius: 6px;
            padding: 14px;
            display: flex;
            gap: 12px;
            align-items: flex-start;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .feed-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #edf7ed;
            color: #1e6b24;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            border: 1px solid #c8e6c9;
        }

        .feed-content {
            flex: 1;
            min-width: 0;
        }

        .feed-title {
            font-size: 14px;
            font-weight: 700;
            color: #241e1a;
            margin-bottom: 2px;
        }

        .feed-meta {
            font-size: 12px;
            color: #6b625b;
            line-height: 1.4;
        }

        .feed-time {
            font-size: 11px;
            color: #635b54;
            margin-top: 4px;
        }

        .live-banner {
            padding: 12px 16px;
            border-radius: 6px;
            font-size: 14px;
            margin-bottom: 16px;
            display: none;
            align-items: center;
            gap: 8px;
        }

        .live-banner.success {
            background: #edf7ed;
            color: #1e6b24;
            border: 1px solid #c8e6c9;
        }

        .live-banner.error {
            background: #fdeeed;
            color: #c62828;
            border: 1px solid #ffcdd2;
        }

        @media (max-width: 900px) {
            .scan-container {
                grid-template-columns: 1fr;
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
                        <?php if ($pending_returns_count > 0): ?>
                            <span style="background: #0284c7; color: white; border-radius: 10px; padding: 1px 6px; font-size: 11px; font-weight: 700; margin-left: 6px;" title="<?php echo $pending_returns_count; ?> returns awaiting verification"><?php echo $pending_returns_count; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="scan_return.php" class="active">
                        <i data-heroicon="qr-code"></i> Scan Return
                    </a>
                    <a href="print_qrs.php">
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

            <div class="admin-header-title">
                <h1><i data-heroicon="qr-code"></i> Instant QR Return Scanner</h1>
                <p>Point the counter camera at the book's QR code to automatically mark it as returned.</p>
            </div>

            <div id="liveAlertBanner" class="live-banner"></div>

            <div class="scan-container">

                <div class="scan-card">
                    <h2><i data-heroicon="video-camera"></i> Camera Viewfinder</h2>

                    <div id="qr-reader"></div>

                    <div class="scanner-controls">
                        <span id="scannerStatusBadge" class="scanner-status scanning">Initializing camera...</span>
                        <button type="button" id="pauseResumeBtn" class="ctrl-btn secondary">
                            <i data-heroicon="pause"></i> Pause Camera
                        </button>
                    </div>

                    <div class="manual-input-box">
                        <label for="manualTokenInput"><i data-heroicon="keyboard"></i> Manual Entry Fallback (Book ID or URL):</label>
                        <form id="manualReturnForm" class="manual-form-row">
                            <input type="text" id="manualTokenInput" class="manual-input" placeholder="e.g. 15 or full borrow URL" required>
                            <button type="submit" class="manual-btn">
                                <i data-heroicon="arrow-uturn-left"></i> Return Book
                            </button>
                        </form>
                    </div>
                </div>

                <div class="history-feed-card">
                    <h2><i data-heroicon="clock"></i> Recent Returns Feed</h2>
                    <div id="returnsFeed" class="feed-list">
                        <div id="emptyFeedNotice">
                            <i data-heroicon="inbox" style="width: 36px; height: 36px; margin-bottom: 8px; opacity: 0.5;"></i>
                            <p>No returns scanned yet during this session.</p>
                        </div>
                    </div>
                </div>

            </div>

        </main>

    </div>

</div>

<script src="../html5-qrcode.min.js"></script>
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

    // Sound alert effect via Web Audio API (no external asset needed)
    function playBeep(success) {
        try {
            var ctx = new (window.AudioContext || window.webkitAudioContext)();
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.type = 'sine';
            if (success) {
                osc.frequency.setValueAtTime(587.33, ctx.currentTime); // D5
                osc.frequency.setValueAtTime(880, ctx.currentTime + 0.1); // A5
                gain.gain.setValueAtTime(0.2, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.3);
                osc.start();
                osc.stop(ctx.currentTime + 0.3);
            } else {
                osc.frequency.setValueAtTime(220, ctx.currentTime);
                gain.gain.setValueAtTime(0.2, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.25);
                osc.start();
                osc.stop(ctx.currentTime + 0.25);
            }
        } catch(e) {}
    }

    var isProcessing = false;
    var html5QrCode = null;
    var isCameraPaused = false;
    var lastScannedToken = "";
    var lastScanTime = 0;

    var banner = document.getElementById('liveAlertBanner');
    var statusBadge = document.getElementById('scannerStatusBadge');
    var pauseResumeBtn = document.getElementById('pauseResumeBtn');
    var feed = document.getElementById('returnsFeed');
    var emptyFeed = document.getElementById('emptyFeedNotice');

    function showBanner(type, message) {
        banner.className = 'live-banner ' + type;
        banner.style.display = 'flex';
        banner.innerHTML = '<i data-heroicon="' + (type === 'success' ? 'check-circle' : 'exclamation-circle') + '"></i> ' + message;
        if (window.heroicons) { heroicons.createIcons({ root: banner }); }
    }

    function addFeedItem(data) {
        if (emptyFeed) {
            emptyFeed.style.display = 'none';
        }
        var item = document.createElement('div');
        item.className = 'feed-item';
        item.innerHTML =
            '<div class="feed-icon"><i data-heroicon="check"></i></div>' +
            '<div class="feed-content">' +
                '<div class="feed-title">' + (data.book ? data.book.title : 'Book Returned') + '</div>' +
                '<div class="feed-meta">' +
                    'Customer: <strong>' + (data.customer ? data.customer.name : 'Unknown') + '</strong> (' + (data.customer ? data.customer.contact : '') + ')<br>' +
                    'Available Copies: <span style="color:#1e6b24; font-weight:600;">' + (data.book ? data.book.available_copies + ' of ' + data.book.total_copies : 'Updated') + '</span>' +
                '</div>' +
                '<div class="feed-time"><i data-heroicon="clock"></i> ' + (data.returned_at || 'Just now') + '</div>' +
            '</div>';
        feed.insertBefore(item, feed.firstChild);
        if (window.heroicons) { heroicons.createIcons({ root: item }); }
    }

    function processToken(token) {
        var now = Date.now();
        if (token === lastScannedToken && (now - lastScanTime) < 3000) {
            return; // debounce identical scans within 3 seconds
        }
        lastScannedToken = token;
        lastScanTime = now;

        if (isProcessing) return;
        isProcessing = true;
        statusBadge.textContent = "Processing return...";
        statusBadge.className = "scanner-status";

        var formData = new FormData();
        formData.append('action', 'process_qr_return');
        formData.append('token', token);

        fetch('scan_return.php', {
            method: 'POST',
            body: formData
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            isProcessing = false;
            statusBadge.textContent = "Camera active: Scanning...";
            statusBadge.className = "scanner-status scanning";

            if (data.ok) {
                playBeep(true);
                showBanner('success', data.message + ' ("' + data.book.title + '" from ' + data.customer.name + ')');
                addFeedItem(data);
            } else {
                playBeep(false);
                showBanner('error', data.message);
            }
        })
        .catch(function(err) {
            isProcessing = false;
            statusBadge.textContent = "Camera active: Scanning...";
            statusBadge.className = "scanner-status scanning";
            playBeep(false);
            showBanner('error', 'Network error communicating with return server.');
        });
    }

    // Manual Return Form Submission
    document.getElementById('manualReturnForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var val = document.getElementById('manualTokenInput').value.trim();
        if (val) {
            processToken(val);
            document.getElementById('manualTokenInput').value = '';
        }
    });

    // Initialize Camera Scanner
    function initScanner() {
        if (typeof Html5Qrcode === 'undefined') {
            statusBadge.textContent = "Camera library not found. Please use manual entry.";
            statusBadge.className = "scanner-status";
            return;
        }

        html5QrCode = new Html5Qrcode("qr-reader");

        var config = {
            fps: 10,
            qrbox: function (viewfinderWidth, viewfinderHeight) {
                var minEdge = Math.min(viewfinderWidth, viewfinderHeight);
                var boxSize = Math.max(160, Math.min(minEdge - 16, Math.floor(minEdge * 0.90)));
                return { width: boxSize, height: boxSize };
            },
            aspectRatio: 1.0
        };

        html5QrCode.start(
            { facingMode: "environment" },
            config,
            function onScanSuccess(decodedText) {
                processToken(decodedText);
            },
            function onScanError(errorMessage) {
                // Ignore per-frame decode failures
            }
        ).then(function() {
            statusBadge.textContent = "Camera active: Scanning...";
            statusBadge.className = "scanner-status scanning";
        }).catch(function(err) {
            statusBadge.textContent = "Camera inaccessible: " + (err.name || "Permission denied");
            statusBadge.className = "scanner-status";
        });
    }

    pauseResumeBtn.addEventListener('click', function() {
        if (!html5QrCode) return;
        if (isCameraPaused) {
            html5QrCode.resume();
            isCameraPaused = false;
            pauseResumeBtn.innerHTML = '<i data-heroicon="pause"></i> Pause Camera';
            statusBadge.textContent = "Camera active: Scanning...";
            statusBadge.className = "scanner-status scanning";
        } else {
            html5QrCode.pause();
            isCameraPaused = true;
            pauseResumeBtn.innerHTML = '<i data-heroicon="play"></i> Resume Camera';
            statusBadge.textContent = "Camera paused";
            statusBadge.className = "scanner-status";
        }
        if (window.heroicons) { heroicons.createIcons({ root: pauseResumeBtn }); }
    });

    window.addEventListener('DOMContentLoaded', function() {
        initScanner();
    });
</script>

</body>
</html>
