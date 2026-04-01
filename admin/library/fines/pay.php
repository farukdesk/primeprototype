<?php
require_once __DIR__ . '/../../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../helpers.php';

if (!lib_is_circulation_staff()) {
    flash_set('error', 'You do not have permission to manage fine payments.');
    redirect(APP_URL . '/library/fines/index.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/library/fines/index.php');
}

csrf_check();

$db      = db();
$user    = auth_user();
$action  = trim($_POST['action']  ?? '');
$fine_id = (int)($_POST['fine_id'] ?? 0);

if (!$fine_id) {
    flash_set('error', 'Invalid fine ID.');
    redirect(APP_URL . '/library/fines/index.php');
}

// Fetch fine record
$fine_stmt = $db->prepare(
    'SELECT f.*,
            m.member_code, m.member_type,
            COALESCE(s.full_name, u.full_name) AS member_name,
            b.title AS book_title
     FROM library_fines f
     JOIN library_members m ON m.id = f.member_id
     JOIN library_circulation c ON c.id = f.circulation_id
     JOIN library_book_copies cp ON cp.id = c.copy_id
     JOIN library_books b ON b.id = cp.book_id
     LEFT JOIN students s ON s.id = m.student_id
     LEFT JOIN users u ON u.id = m.user_id
     WHERE f.id = ?'
);
$fine_stmt->execute([$fine_id]);
$fine = $fine_stmt->fetch();

if (!$fine) {
    flash_set('error', 'Fine record not found.');
    redirect(APP_URL . '/library/fines/index.php');
}

// ── Pay ───────────────────────────────────────────────────────────────────────
if ($action === 'pay') {
    if ($fine['status'] !== 'Unpaid') {
        flash_set('error', 'This fine is already ' . strtolower($fine['status']) . '.');
        redirect(APP_URL . '/library/fines/index.php');
    }

    $receipt_number = trim($_POST['receipt_number'] ?? '');
    if ($receipt_number === '') {
        $receipt_number = lib_generate_receipt();
    }

    // Sanitize receipt number (alphanumeric + dash only)
    $receipt_number = preg_replace('/[^A-Za-z0-9\-]/', '', $receipt_number);
    if ($receipt_number === '') {
        flash_set('error', 'Invalid receipt number.');
        redirect(APP_URL . '/library/fines/index.php');
    }

    $db->prepare(
        "UPDATE library_fines
         SET status='Paid', paid_at=NOW(), collected_by=?, receipt_number=?
         WHERE id=? AND status='Unpaid'"
    )->execute([$user['id'], $receipt_number, $fine_id]);

    lib_audit('FINE_PAID', 'fines', $fine_id,
        'Fine #' . $fine_id . ' — ' . ($fine['member_name'] ?? '') . ' — ' . $fine['book_title'],
        'Amount: ৳' . number_format($fine['amount'], 2) . '. Receipt: ' . $receipt_number
    );

    flash_set('success',
        'Fine of ৳' . number_format($fine['amount'], 2) .
        ' collected from ' . ($fine['member_name'] ?? 'member') .
        '. Receipt: ' . $receipt_number
    );
    redirect(APP_URL . '/library/fines/index.php');

// ── Waive ─────────────────────────────────────────────────────────────────────
} elseif ($action === 'waive') {
    if (!lib_is_staff()) {
        flash_set('error', 'You do not have permission to waive fines.');
        redirect(APP_URL . '/library/fines/index.php');
    }

    if ($fine['status'] !== 'Unpaid') {
        flash_set('error', 'Only unpaid fines can be waived.');
        redirect(APP_URL . '/library/fines/index.php');
    }

    $waive_notes = trim($_POST['waive_notes'] ?? '');
    if ($waive_notes === '') {
        flash_set('error', 'Please provide a reason for waiving the fine.');
        redirect(APP_URL . '/library/fines/index.php');
    }

    $existing_notes = $fine['notes'] ? $fine['notes'] . ' | ' : '';
    $new_notes      = $existing_notes . 'Waived by ' . ($user['full_name'] ?? 'Staff') .
                      ' on ' . date('d M Y H:i') . ': ' . $waive_notes;

    $db->prepare(
        "UPDATE library_fines SET status='Waived', notes=? WHERE id=? AND status='Unpaid'"
    )->execute([$new_notes, $fine_id]);

    lib_audit('FINE_WAIVED', 'fines', $fine_id,
        'Fine #' . $fine_id . ' — ' . ($fine['member_name'] ?? '') . ' — ' . $fine['book_title'],
        'Amount waived: ৳' . number_format($fine['amount'], 2) . '. Reason: ' . $waive_notes
    );

    flash_set('success',
        'Fine of ৳' . number_format($fine['amount'], 2) .
        ' waived for ' . ($fine['member_name'] ?? 'member') . '.'
    );
    redirect(APP_URL . '/library/fines/index.php');

} else {
    flash_set('error', 'Invalid action.');
    redirect(APP_URL . '/library/fines/index.php');
}
