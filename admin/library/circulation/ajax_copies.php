<?php
/**
 * AJAX endpoint: returns available copies for a book_id,
 * or finds a copy by barcode.
 */
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

$db = db();

if (isset($_GET['barcode'])) {
    $barcode = trim($_GET['barcode']);
    $stmt = $db->prepare(
        'SELECT cp.id, cp.book_id, cp.copy_number, cp.barcode, cp.condition_status
         FROM library_book_copies cp
         WHERE cp.barcode = ? AND cp.is_available = 1 LIMIT 1'
    );
    $stmt->execute([$barcode]);
    $row = $stmt->fetch();
    echo json_encode($row ? [$row] : []);
} elseif (isset($_GET['book_id'])) {
    $book_id = (int)$_GET['book_id'];
    $stmt = $db->prepare(
        'SELECT id, book_id, copy_number, barcode, condition_status
         FROM library_book_copies
         WHERE book_id = ? AND is_available = 1
         ORDER BY copy_number ASC'
    );
    $stmt->execute([$book_id]);
    echo json_encode($stmt->fetchAll());
} else {
    echo json_encode([]);
}
