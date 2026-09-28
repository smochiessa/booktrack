# Page Lounge — Book Café Library Management System

A self-service café library and borrowing management system built with PHP, MySQL, and QR code integration. Designed for book cafés, reading lounges, and community libraries with mobile-first customer browsing and comprehensive administrative tools.

---

## Features

### ☕ Customer Self-Service Portal
- **Browse Books**: Mobile-optimized catalog with responsive 2x2 grid layout, live availability badges, book covers, and category tags.
- **Unified Search & QR Scanner**: Look up books by title, author, category, ISBN, or scan shelf QR codes via the device camera (HTML5 QR scanner).
- **Instant Borrowing**: Modal-driven borrowing loan checkout with customer contact lookup and automated 7-day loan calculation.
- **Self-Service Returns**: Look up active loans by name and phone number; return individual books or select from multiple active borrowings.

### 📚 Librarian Administrative Portal
- **Dashboard**: Key operational metrics (Total Books, Available, Borrowed, Overdue) in a compact 2x2 mobile layout, plus real-time recent transaction feeds.
- **Book Inventory Management**: Add, edit, and delete books with cover image uploads, category sorting, status toggling, and automatic QR code generation.
- **Borrowing Records & Audit**: Track loans, return dates, customer contact info, and overdue flags with multi-criteria filtering (status, search).
- **Printable Formal Reports**: Ink-friendly, plain black-and-white audit reports with letterheads, tabular grids, summary metrics, and anchored bottom signature blocks (Borrowing Activity, Overdue Delinquencies, and Shelf Inventory Valuation).
- **Account Settings**: Update librarian credentials (username and password) securely hashed via `password_hash()` (BCRYPT).

### 🏷️ QR Code Integration
- Automatic QR generation on book creation using `endroid/qr-code`.
- QR tokens encode direct lookup routes (`book_<id>`) readable by any phone camera or the in-app scanner.

---

## Tech Stack

- **Backend**: PHP 8.0+ (MySQLi prepared statements, atomic transactions)
- **Database**: MySQL / MariaDB (InnoDB, `utf8mb4_unicode_ci`)
- **Frontend**: Vanilla HTML5, CSS3 (CSS Grid, Flexbox, custom media queries), Vanilla JavaScript
- **Typography**: Google Fonts (*Playfair Display*, *Alex Brush*, *Plus Jakarta Sans*)
- **Icons**: Inline Heroicons SVG
- **Dependencies**: `endroid/qr-code: ^6.0` (managed via Composer)

---

## Project Structure

```text
booktrack/
├── admin/
│   ├── books.php          # Book inventory CRUD & QR code generation
│   ├── borrowings.php     # Loan transaction tracking & status filtering
│   ├── dashboard.php      # Librarian KPI dashboard & quick feed
│   ├── login.php          # Librarian authentication
│   ├── logout.php         # Session termination
│   ├── reports.php        # Printable ink-friendly audit reports
│   └── settings.php       # Account username & password management
├── customer/
│   ├── books.php          # Customer catalog, search, & QR scanner
│   ├── borrow.php         # Direct borrowing loan handler
│   └── return.php         # Customer return lookup & return submission
├── qrcodes/               # Generated PNG QR codes for each book
├── uploads/               # Book cover image uploads
├── database.php           # Database connection & auto-migration routines
├── heroicons.js           # Lightweight Heroicons renderer
├── index.php              # Public landing & welcome page
├── logo.svg               # Page Lounge Book Café SVG brand emblem
├── schema.sql             # Full database schema DDL
├── style.css              # Central application design system & responsive rules
└── composer.json          # PHP dependency manifest
```

---

## Getting Started

### Prerequisites
- **XAMPP**, **WampServer**, or **LAMP** stack with:
  - PHP 8.0 or higher (with `gd` and `mysqli` extensions enabled)
  - MySQL 5.7+ or MariaDB 10.4+
  - Apache HTTP Server
- **Composer** (optional, `vendor/` is included)

### Installation (XAMPP on Windows)

1. **Place Project in Web Root**:
   Clone or copy the repository into your web directory:
   ```text
   C:\xampp\htdocs\booktrack
   ```

2. **Start Services**:
   Open XAMPP Control Panel and start **Apache** and **MySQL**.

3. **Database Setup**:
   - Open **phpMyAdmin** (`http://localhost/phpmyadmin/`).
   - Create a database named `booktrack` (or import `schema.sql` directly):
     ```sql
     CREATE DATABASE booktrack CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
     ```
   - Import `schema.sql` using the phpMyAdmin **Import** tab.
   - *Note*: `database.php` also runs auto-migrations on first load to seed default accounts and ensure schema compatibility.

4. **Verify Database Configuration**:
   If your MySQL credentials differ from XAMPP defaults (`root` with no password), update `database.php`:
   ```php
   $host = "localhost";
   $username = "root";
   $password = "";
   $database = "booktrack";
   ```

5. **Launch Application**:
   - **Customer Portal**: `http://localhost/booktrack/` or `http://localhost/booktrack/customer/books.php`
   - **Librarian Portal**: `http://localhost/booktrack/admin/login.php`

---

## Default Credentials

| Portal | Username | Password |
|---|---|---|
| **Librarian Admin** | `librarian` | `booktrack123` |

*Credentials can be changed anytime via the **Account Settings** page in the Admin Portal.*

---

## Database Schema Overview

- **`books`**: Book catalogue (`title`, `author`, `category`, `isbn`, `image`, `qr_code`, `status`).
- **`customers`**: Registered borrowers (`name`, `contact`, `created_at`).
- **`borrowings`**: Loan transactions (`customer_id`, `book_id`, `borrow_date`, `due_date`, `return_date`, `status`).
- **`admins`**: Librarian accounts (`username`, `password` hash, `updated_at`).
