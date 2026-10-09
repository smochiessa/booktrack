<?php
session_start();

if (!isset($_SESSION['librarian'])) {
    header("Location: login.php");
    exit();
}

require_once "../database.php";
$logo_url = pagelounge_logo_url();
$pending_count = pagelounge_pending_customers_count($conn);

$message = "";
$message_type = "";

// Handle Customer Approval / Rejection Actions
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST['action'] ?? '';
    $customer_id = filter_var($_POST['customer_id'] ?? null, FILTER_VALIDATE_INT);

    if ($customer_id) {
        if ($action === 'approve') {
            $stmt = $conn->prepare("UPDATE customers SET account_status = 'Approved' WHERE id = ?");
            $stmt->bind_param("i", $customer_id);
            if ($stmt->execute()) {
                $message = "Customer account #$customer_id approved successfully!";
                $message_type = "success";
            }
        } elseif ($action === 'reject') {
            $stmt = $conn->prepare("UPDATE customers SET account_status = 'Rejected' WHERE id = ?");
            $stmt->bind_param("i", $customer_id);
            if ($stmt->execute()) {
                $message = "Customer account #$customer_id has been rejected.";
                $message_type = "error";
            }
        }
        // Refresh pending count
        $pending_count = pagelounge_pending_customers_count($conn);
    }
}

// Filters & Search
$status_filter = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');

$sql = "SELECT id, name, email, contact, id_image, account_status, created_at FROM customers WHERE 1=1";
$types = "";
$params = [];

if ($status_filter !== '' && in_array($status_filter, ['Pending', 'Approved', 'Rejected'], true)) {
    $sql .= " AND account_status = ?";
    $types .= "s";
    $params[] = $status_filter;
}

if ($search !== '') {
    $sql .= " AND (name LIKE ? OR email LIKE ? OR contact LIKE ?)";
    $like = "%" . $search . "%";
    $types .= "sss";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY (account_status = 'Pending') DESC, created_at DESC";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $customers = $stmt->get_result();
} else {
    $customers = $conn->query($sql);
}

// Counts for filter tabs
$total_all = (int)$conn->query("SELECT COUNT(*) AS total FROM customers")->fetch_assoc()['total'];
$total_pending = (int)$conn->query("SELECT COUNT(*) AS total FROM customers WHERE account_status = 'Pending'")->fetch_assoc()['total'];
$total_approved = (int)$conn->query("SELECT COUNT(*) AS total FROM customers WHERE account_status = 'Approved'")->fetch_assoc()['total'];
$total_rejected = (int)$conn->query("SELECT COUNT(*) AS total FROM customers WHERE account_status = 'Rejected'")->fetch_assoc()['total'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer ID Verifications - Page Lounge</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Alex+Brush&family=Playfair+Display:ital,wght@0,400..700;1,400..700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../style.css">
    <style>
        .customers-page {
            width: 100%;
            max-width: 100%;
        }
        .customers-card {
            background: #ffffff;
            padding: 28px 32px;
            border-radius: var(--radius, 8px);
            border: 1px solid var(--border-warm, #e5dfd5);
            box-shadow: 0 2px 8px rgba(46, 38, 32, 0.03);
        }
        .page-header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .page-header-row h1 {
            font-size: 26px;
            color: #241e1a;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
        }
        .page-header-row p {
            color: #6b625b;
            margin: 6px 0 0;
            font-size: 14.5px;
        }
        .filter-tabs-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 22px;
            flex-wrap: wrap;
        }
        .filter-tabs-group {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .filter-tab {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            background: #ffffff;
            border: 1px solid #d5cbbe;
            border-radius: 6px;
            color: #554b43;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.15s ease;
        }
        .filter-tab:hover {
            border-color: #2e2620;
            color: #2e2620;
        }
        .filter-tab.active {
            background: #2e2620;
            color: #ffffff;
            border-color: #2e2620;
            font-weight: 600;
        }
        .tab-count {
            background: rgba(0,0,0,0.08);
            padding: 1px 7px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
        }
        .filter-tab.active .tab-count {
            background: rgba(255,255,255,0.25);
            color: #ffffff;
        }
        .tab-badge-pending {
            background: #ef4444;
            color: white;
            font-weight: 700;
        }
        .search-box {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-left: auto;
        }
        .search-box input {
            padding: 8px 12px;
            border: 1px solid #d5cbbe;
            border-radius: 6px;
            font-size: 13px;
            min-width: 240px;
            background: #ffffff;
            color: #2e2620;
            outline: none;
            transition: border-color 0.15s;
        }
        .search-box input:focus {
            border-color: #2e2620;
            outline: 2px solid rgba(46, 38, 32, 0.15);
        }
        .search-box button {
            padding: 8px 14px;
            background: #2e2620;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background 0.15s;
        }
        .search-box button:hover {
            background: #443830;
        }
        .search-clear-btn {
            padding: 8px 12px;
            background: #f4efe8;
            color: #554b43;
            border: 1px solid #d5cbbe;
            border-radius: 6px;
            font-size: 13px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.15s;
        }
        .search-clear-btn:hover {
            color: #2e2620;
            border-color: #2e2620;
        }
        .alert-box {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .alert-box.success {
            background: #edf7ed;
            color: #1e6b24;
            border: 1px solid #c8e6c9;
        }
        .alert-box.error {
            background: #fdeeed;
            color: #c62828;
            border: 1px solid #ffcdd2;
        }

        /* TABLE CARD & RESPONSIVE WRAPPER */
        .table-card {
            background: #ffffff;
            border: 1px solid #e5dfd5;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(46, 38, 32, 0.03);
        }
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .customers-table {
            width: 100%;
            min-width: 900px;
            border-collapse: collapse;
            background: #ffffff;
            text-align: left;
        }
        .customers-table th {
            background: #faf7f3;
            color: #2e2620;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.4px;
            text-transform: uppercase;
            padding: 14px 18px;
            border-bottom: 1px solid #e5dfd5;
            white-space: nowrap;
        }
        .customers-table td {
            padding: 14px 18px;
            border-bottom: 1px solid #efe7db;
            font-size: 13.5px;
            color: #2e2620;
            vertical-align: middle;
        }
        .customers-table tbody tr:hover td {
            background: #fdfbf7;
        }
        .customers-table tbody tr:last-child td {
            border-bottom: none;
        }

        /* Column Specifics */
        .col-id {
            width: 65px;
            text-align: center;
        }
        .col-customer {
            min-width: 200px;
        }
        .col-contact {
            min-width: 130px;
            white-space: nowrap;
        }
        .col-id-doc {
            width: 110px;
            text-align: center;
        }
        .col-status {
            width: 110px;
            text-align: center;
        }
        .col-registered {
            width: 120px;
            white-space: nowrap;
        }
        .col-actions {
            width: 180px;
            text-align: right;
        }

        .cust-id-pill {
            display: inline-block;
            padding: 3px 8px;
            background: #f7f3ec;
            border: 1px solid #e5dfd5;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            color: #8c7355;
        }
        .customer-info {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .customer-name {
            font-weight: 600;
            color: #241e1a;
            font-size: 14px;
        }
        .customer-email {
            font-size: 12.5px;
            color: #786e65;
        }
        .contact-text {
            font-size: 13px;
            color: #554b43;
        }
        .id-thumb-wrap {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            gap: 3px;
            cursor: pointer;
        }
        .id-thumbnail {
            width: 54px;
            height: 38px;
            object-fit: cover;
            border-radius: 4px;
            border: 1px solid #d5cbbe;
            box-shadow: 0 1px 3px rgba(46, 38, 32, 0.08);
            transition: transform 0.15s ease, border-color 0.15s ease;
            display: block;
        }
        .id-thumb-wrap:hover .id-thumbnail {
            transform: scale(1.08);
            border-color: #8c7355;
        }
        .id-view-hint {
            font-size: 10.5px;
            color: #8c7355;
            font-weight: 500;
        }
        .no-id-text {
            font-size: 12px;
            color: #a0978d;
            font-style: italic;
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 12px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .status-badge.pending {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        .status-badge.approved {
            background: #def7ec;
            color: #03543f;
            border: 1px solid #bcf0da;
        }
        .status-badge.rejected {
            background: #fde8e8;
            color: #9b1c1c;
            border: 1px solid #f8b4b4;
        }
        .actions-wrap {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
        }
        .btn-action-approve {
            padding: 6px 12px;
            background: #03543f;
            color: #ffffff;
            border: none;
            border-radius: 5px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: background 0.15s ease;
        }
        .btn-action-approve:hover {
            background: #046c4e;
        }
        .btn-action-reject {
            padding: 6px 12px;
            background: #9b1c1c;
            color: #ffffff;
            border: none;
            border-radius: 5px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: background 0.15s ease;
        }
        .btn-action-reject:hover {
            background: #b91c1c;
        }

        /* Lightbox modal */
        .lightbox-modal {
            display: none;
            position: fixed;
            z-index: 9999;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(20, 14, 10, 0.8);
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .lightbox-modal.active { display: flex; }
        .lightbox-content {
            max-width: 90vw;
            max-height: 90vh;
            background: white;
            padding: 16px;
            border-radius: 8px;
            position: relative;
            text-align: center;
        }
        .lightbox-content img {
            max-width: 100%;
            max-height: 75vh;
            object-fit: contain;
            border-radius: 4px;
        }
        .lightbox-close {
            position: absolute;
            top: 10px; right: 10px;
            background: #2e2620;
            color: white;
            border: none;
            width: 32px; height: 32px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 18px;
            line-height: 1;
        }
        .nav-badge-pending {
            background: #ef4444;
            color: white;
            border-radius: 10px;
            padding: 1px 6px;
            font-size: 11px;
            font-weight: 700;
            margin-left: 6px;
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
            <button type="button" class="admin-burger-btn" id="adminBurgerBtn" aria-label="Toggle navigation menu" onclick="var s=document.querySelector('.admin-sidebar'); s.classList.toggle('nav-open');">
                <i data-heroicon="bars-3" class="burger-icon-bars"></i>
                <i data-heroicon="x-mark" class="burger-icon-close"></i>
                <span>Menu</span>
            </button>
        </div>

        <div class="admin-sidebar-collapse">
            <div class="sidebar-top">
                <div class="sidebar-subtitle">Librarian Portal</div>
                <nav class="sidebar-nav">
                    <a href="dashboard.php">
                        <i data-heroicon="squares-2x2"></i> Dashboard
                    </a>
                    <a href="customers.php" class="active">
                        <i data-heroicon="user-group"></i> Customers
                        <?php if ($pending_count > 0): ?>
                            <span class="nav-badge-pending"><?php echo $pending_count; ?></span>
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
        <main class="customers-page">
            <div class="customers-card">
                <div class="page-header-row">
                    <div>
                        <h1><i data-heroicon="user-group"></i> Customer Verifications</h1>
                        <p>Review customer uploaded valid IDs and manage account borrowing permissions.</p>
                    </div>
                </div>

                <?php if (!empty($message)): ?>
                    <div class="alert-box <?php echo $message_type === 'success' ? 'success' : 'error'; ?>">
                        <i data-heroicon="<?php echo $message_type === 'success' ? 'check' : 'exclamation-circle'; ?>"></i>
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <div class="filter-tabs-bar">
                    <div class="filter-tabs-group">
                        <a href="customers.php" class="filter-tab <?php echo $status_filter === '' ? 'active' : ''; ?>">
                            All <span class="tab-count"><?php echo $total_all; ?></span>
                        </a>
                        <a href="customers.php?status=Pending" class="filter-tab <?php echo $status_filter === 'Pending' ? 'active' : ''; ?>">
                            Pending <span class="tab-count <?php echo $total_pending > 0 ? 'tab-badge-pending' : ''; ?>"><?php echo $total_pending; ?></span>
                        </a>
                        <a href="customers.php?status=Approved" class="filter-tab <?php echo $status_filter === 'Approved' ? 'active' : ''; ?>">
                            Approved <span class="tab-count"><?php echo $total_approved; ?></span>
                        </a>
                        <a href="customers.php?status=Rejected" class="filter-tab <?php echo $status_filter === 'Rejected' ? 'active' : ''; ?>">
                            Rejected <span class="tab-count"><?php echo $total_rejected; ?></span>
                        </a>
                    </div>

                    <form method="GET" class="search-box">
                        <?php if ($status_filter !== ''): ?>
                            <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                        <?php endif; ?>
                        <input type="text" name="search" placeholder="Search name, email, contact..." value="<?php echo htmlspecialchars($search); ?>">
                        <button type="submit"><i data-heroicon="magnifying-glass"></i> Search</button>
                        <?php if ($search !== ''): ?>
                            <a href="customers.php<?php echo $status_filter !== '' ? '?status=' . urlencode($status_filter) : ''; ?>" class="search-clear-btn" title="Clear search filter">
                                <i data-heroicon="x-mark"></i> Clear
                            </a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="table-card">
                    <div class="table-responsive">
                        <table class="customers-table">
                            <thead>
                                <tr>
                                    <th class="col-id">ID</th>
                                    <th class="col-customer">Customer</th>
                                    <th class="col-contact">Contact</th>
                                    <th class="col-id-doc">Uploaded ID</th>
                                    <th class="col-status">Status</th>
                                    <th class="col-registered">Registered</th>
                                    <th class="col-actions">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($customers && $customers->num_rows > 0): ?>
                                    <?php while ($c = $customers->fetch_assoc()): ?>
                                        <tr>
                                            <td class="col-id">
                                                <span class="cust-id-pill">#<?php echo (int)$c['id']; ?></span>
                                            </td>
                                            <td class="col-customer">
                                                <div class="customer-info">
                                                    <span class="customer-name"><?php echo htmlspecialchars($c['name']); ?></span>
                                                    <span class="customer-email"><?php echo htmlspecialchars($c['email'] ?? 'No email registered'); ?></span>
                                                </div>
                                            </td>
                                            <td class="col-contact">
                                                <span class="contact-text"><?php echo htmlspecialchars($c['contact'] ?: '—'); ?></span>
                                            </td>
                                            <td class="col-id-doc">
                                                <?php if (!empty($c['id_image']) && file_exists(__DIR__ . '/../' . $c['id_image'])): ?>
                                                    <div class="id-thumb-wrap" onclick="openLightbox('../<?php echo htmlspecialchars($c['id_image']); ?>', '<?php echo htmlspecialchars(addslashes($c['name'])); ?>');" title="Click to view full ID document">
                                                        <img
                                                            src="../<?php echo htmlspecialchars($c['id_image']); ?>"
                                                            alt="Valid ID Document"
                                                            class="id-thumbnail"
                                                        >
                                                        <span class="id-view-hint">View ID</span>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="no-id-text">No photo</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="col-status">
                                                <?php
                                                    $st = $c['account_status'];
                                                    $stClass = strtolower($st);
                                                ?>
                                                <span class="status-badge <?php echo $stClass; ?>">
                                                    <?php echo htmlspecialchars($st); ?>
                                                </span>
                                            </td>
                                            <td class="col-registered">
                                                <span style="font-size:13px; color:#6b625b;">
                                                    <?php echo date("M d, Y", strtotime($c['created_at'])); ?>
                                                </span>
                                            </td>
                                            <td class="col-actions">
                                                <div class="actions-wrap">
                                                    <?php if ($c['account_status'] !== 'Approved'): ?>
                                                        <form method="POST" style="margin:0;">
                                                            <input type="hidden" name="customer_id" value="<?php echo (int)$c['id']; ?>">
                                                            <input type="hidden" name="action" value="approve">
                                                            <button type="submit" class="btn-action-approve" title="Approve ID and grant borrow access">
                                                                <i data-heroicon="check"></i> Approve
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>

                                                    <?php if ($c['account_status'] !== 'Rejected'): ?>
                                                        <form method="POST" style="margin:0;" onsubmit="return confirm('Reject this customer account?');">
                                                            <input type="hidden" name="customer_id" value="<?php echo (int)$c['id']; ?>">
                                                            <input type="hidden" name="action" value="reject">
                                                            <button type="submit" class="btn-action-reject" title="Reject ID verification">
                                                                <i data-heroicon="x-mark"></i> Reject
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" style="text-align:center; padding:36px 20px; color:#6b625b;">
                                            <i data-heroicon="inbox" style="font-size:24px; color:#a0978d; display:block; margin:0 auto 8px;"></i>
                                            No customers found matching the selected filter.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Lightbox Modal -->
<div class="lightbox-modal" id="lightboxModal" onclick="if(event.target===this) closeLightbox();">
    <div class="lightbox-content">
        <button type="button" class="lightbox-close" aria-label="Close valid ID preview" onclick="closeLightbox();">&times;</button>
        <h3 id="lightboxTitle" style="font-size:16px; margin:0 0 12px; color:#2e2620;"></h3>
        <img id="lightboxImg" src="" alt="Valid ID Image">
    </div>
</div>

<script src="../heroicons.js?v=<?= @filemtime(__DIR__ . '/../heroicons.js') ?: time() ?>"></script>
<script>
    if (window.heroicons && window.heroicons.icons) {
        if (!window.heroicons.icons['user-group']) {
            window.heroicons.icons['user-group'] = '<path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.999-3.199a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />';
        }
        window.heroicons.icons['users'] = window.heroicons.icons['user-group'];
        window.heroicons.icons['customer'] = window.heroicons.icons['user-group'];
        window.heroicons.icons['customers'] = window.heroicons.icons['user-group'];
    }
    if (window.heroicons) {
        heroicons.createIcons();
    }

    function openLightbox(src, customerName) {
        document.getElementById('lightboxImg').src = src;
        document.getElementById('lightboxTitle').textContent = customerName + ' - Valid ID Document';
        document.getElementById('lightboxModal').classList.add('active');
    }

    function closeLightbox() {
        document.getElementById('lightboxModal').classList.remove('active');
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeLightbox();
    });
</script>
</body>
</html>
