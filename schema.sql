-- ============================================================
-- Page Lounge - Book Café Database Schema
-- System: Page Lounge Library & QR Borrowing Management
-- Database: booktrack
-- Charset: utf8mb4 / utf8mb4_unicode_ci
-- ============================================================

CREATE DATABASE IF NOT EXISTS `booktrack`
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `booktrack`;

-- ------------------------------------------------------------
-- 1. Books Table
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `books` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(255) NOT NULL,
    `author` VARCHAR(255) NOT NULL,
    `category` VARCHAR(100) DEFAULT NULL,
    `isbn` VARCHAR(50) DEFAULT NULL,
    `image` VARCHAR(255) DEFAULT NULL,
    `qr_code` VARCHAR(255) DEFAULT NULL,
    `status` ENUM('Available', 'Borrowed') NOT NULL DEFAULT 'Available',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_books_status` (`status`),
    KEY `idx_books_category` (`category`),
    KEY `idx_books_title` (`title`),
    KEY `idx_books_author` (`author`),
    KEY `idx_books_isbn` (`isbn`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci AUTO_INCREMENT=1;

-- ------------------------------------------------------------
-- 2. Customers Table
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `customers` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `contact` VARCHAR(100) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_customers_name` (`name`),
    KEY `idx_customers_contact` (`contact`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci AUTO_INCREMENT=1;

-- ------------------------------------------------------------
-- 3. Borrowings Table
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `borrowings` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `customer_id` INT NOT NULL,
    `book_id` INT NOT NULL,
    `borrow_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `due_date` DATETIME NOT NULL,
    `return_date` DATETIME DEFAULT NULL,
    `status` ENUM('Borrowed', 'Returned') NOT NULL DEFAULT 'Borrowed',
    PRIMARY KEY (`id`),
    KEY `idx_borrowings_customer` (`customer_id`),
    KEY `idx_borrowings_book` (`book_id`),
    KEY `idx_borrowings_status` (`status`),
    KEY `idx_borrowings_due_date` (`due_date`),
    KEY `idx_borrowings_borrow_date` (`borrow_date`),
    CONSTRAINT `fk_borrowings_customer` FOREIGN KEY (`customer_id`)
        REFERENCES `customers` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_borrowings_book` FOREIGN KEY (`book_id`)
        REFERENCES `books` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci AUTO_INCREMENT=1;
