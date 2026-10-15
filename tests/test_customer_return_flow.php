<?php
require_once __DIR__ . '/../database.php';

// 1. Setup book: total 2 copies, currently 1 available (1 borrowed)
$conn->query("INSERT INTO books (title, author, total_copies, available_copies, status) VALUES ('Customer Return Test Book', 'Author Test', 2, 1, 'Available')");
$book_id = $conn->insert_id;

// Setup customer
$conn->query("INSERT INTO customers (name, contact, account_status) VALUES ('Return Flow Tester', '09199998888', 'Approved')");
$customer_id = $conn->insert_id;

$due_date = date("Y-m-d H:i:s", strtotime("+7 days"));
$conn->query("INSERT INTO borrowings (customer_id, book_id, due_date, status) VALUES ($customer_id, $book_id, '$due_date', 'Borrowed')");
$borrowing_id = $conn->insert_id;

$initial_pending = pagelounge_pending_returns_count($conn);

// 2. Customer requests return from customer/books.php
$stmt = $conn->prepare("UPDATE borrowings SET status = 'Pending Return' WHERE id = ? AND customer_id = ? AND status = 'Borrowed'");
$stmt->bind_param("ii", $borrowing_id, $customer_id);
$stmt->execute();
assert($stmt->affected_rows === 1, "Customer return request must transition status to 'Pending Return'");

// Verify pending count increased by 1
$after_request_pending = pagelounge_pending_returns_count($conn);
assert($after_request_pending === $initial_pending + 1, "pagelounge_pending_returns_count must increment after return request");

// Verify book copies are NOT restored before librarian verification
$book_pre = $conn->query("SELECT available_copies, status FROM books WHERE id = $book_id")->fetch_assoc();
assert((int)$book_pre['available_copies'] === 1, "available_copies must remain 1 while return is pending verification");

// Verify pending return still counts towards active borrowing limit
$active_check = $conn->query("SELECT COUNT(*) AS active_count FROM borrowings WHERE customer_id = $customer_id AND status IN ('Borrowed', 'Pending Return')")->fetch_assoc();
assert((int)$active_check['active_count'] === 1, "Pending return must still count towards active loan limit");

// 3. Librarian verifies return (simulating admin/borrowings.php action)
$conn->begin_transaction();
$b_stmt = $conn->prepare("SELECT book_id, status FROM borrowings WHERE id = ? FOR UPDATE");
$b_stmt->bind_param("i", $borrowing_id);
$b_stmt->execute();
$b_row = $b_stmt->get_result()->fetch_assoc();

assert($b_row && $b_row['status'] === 'Pending Return', "Borrowing record must be in Pending Return status");

$upd_b = $conn->prepare("UPDATE borrowings SET return_date = NOW(), status = 'Returned' WHERE id = ?");
$upd_b->bind_param("i", $borrowing_id);
$upd_b->execute();

$upd_bk = $conn->prepare("UPDATE books SET available_copies = LEAST(total_copies, available_copies + 1), status = 'Available' WHERE id = ?");
$upd_bk->bind_param("i", $book_id);
$upd_bk->execute();

$conn->commit();

// 4. Verification assertions
$book_post = $conn->query("SELECT available_copies, status FROM books WHERE id = $book_id")->fetch_assoc();
assert((int)$book_post['available_copies'] === 2, "available_copies must be restored to 2 after librarian verification");
assert($book_post['status'] === 'Available', "Book status must be Available");

$borrowing_post = $conn->query("SELECT status, return_date FROM borrowings WHERE id = $borrowing_id")->fetch_assoc();
assert($borrowing_post['status'] === 'Returned', "Borrowing status must be Returned");
assert(!empty($borrowing_post['return_date']), "return_date must be populated");

$final_pending = pagelounge_pending_returns_count($conn);
assert($final_pending === $initial_pending, "Pending returns count must return to initial value");

// 5. Cleanup
$conn->query("DELETE FROM borrowings WHERE id = $borrowing_id");
$conn->query("DELETE FROM customers WHERE id = $customer_id");
$conn->query("DELETE FROM books WHERE id = $book_id");

echo "PASS: Customer return request and admin verification lifecycle verified\n";
