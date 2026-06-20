<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_access('library');
require_once __DIR__ . '/../helpers.php';

// POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/library/books/index.php');
}

if (!lib_can_delete()) {
    flash_set('error', 'You do not have permission to delete books.');
    redirect(APP_URL . '/library/books/index.php');
}

csrf_check();

$id   = (int)($_POST['id'] ?? 0);
$book = lib_get_book($id);

$db = db();

// ── Check for any circulation records ─────────────────────────────────────────
$circ_check = $db->prepare(
    'SELECT COUNT(*) FROM library_circulation ci
     JOIN library_book_copies cp ON cp.id = ci.copy_id
     WHERE cp.book_id = ?'
);
$circ_check->execute([$id]);
$circ_count = (int)$circ_check->fetchColumn();

if ($circ_count > 0) {
    flash_set('error',
        "Cannot delete <strong>" . h($book['title']) . "</strong>: " .
        "it has <strong>{$circ_count}</strong> circulation record(s). " .
        "A book with any borrowing history cannot be deleted to preserve audit trails."
    );
    redirect(APP_URL . '/library/books/view.php?id=' . $id);
}

// ── Delete cover image file ───────────────────────────────────────────────────
if ($book['cover_image']) {
    lib_delete_file('covers', $book['cover_image']);
}

// ── Delete book (copies cascade via FK) ──────────────────────────────────────
$db->prepare('DELETE FROM library_books WHERE id = ?')->execute([$id]);

// ── Audit & change log ────────────────────────────────────────────────────────
log_change('library', 'DELETE', $id, $book['title'], null, null, null,
    "Deleted book \"{$book['title']}\" (ISBN: " . ($book['isbn'] ?: 'N/A') . ").");
lib_audit('BOOK_DELETED', 'books', $id, $book['title'],
    "Book deleted. ISBN: " . ($book['isbn'] ?: 'N/A') . ", copies: " . (int)$book['total_copies'] . ".");

flash_set('success', "Book <strong>" . h($book['title']) . "</strong> has been deleted.");
redirect(APP_URL . '/library/books/index.php');
