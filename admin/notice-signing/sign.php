<?php
/**
 * Handles signing actions and status changes for notice documents.
 * Actions:
 *   sign          – Current user applies their signature
 *   change_status – Admin updates draft/active status
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('notice-signing');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/notice-signing/index.php');
}
csrf_check();

$action = $_POST['action'] ?? '';
$id     = (int)($_POST['id'] ?? 0);

if ($id < 1) { flash_set('error', 'Invalid notice.'); redirect(APP_URL . '/notice-signing/index.php'); }

$stmt = db()->prepare('SELECT * FROM notice_documents WHERE id = ?');
$stmt->execute([$id]);
$doc = $stmt->fetch();
if (!$doc) { flash_set('error', 'Notice not found.'); redirect(APP_URL . '/notice-signing/index.php'); }

$user = auth_user();

// ── Action: Apply signature ────────────────────────────────────────────────────
if ($action === 'sign') {
    if ($doc['status'] !== 'active') {
        flash_set('error', 'This notice is not active for signing.');
        redirect(APP_URL . '/notice-signing/view.php?id=' . $id);
    }

    if (!ns_needs_to_sign($id, $user['id'])) {
        flash_set('info', 'You have already signed this notice, or you are not a required signer.');
        redirect(APP_URL . '/notice-signing/view.php?id=' . $id);
    }

    // Check user has a signature image
    $sig_stmt = db()->prepare('SELECT signature_file FROM users WHERE id = ?');
    $sig_stmt->execute([$user['id']]);
    $sig_file = $sig_stmt->fetchColumn();
    if (!$sig_file) {
        flash_set('error', 'You have not uploaded a signature image. Please go to My Signature first.');
        redirect(APP_URL . '/my-signature/index.php');
    }

    // Get position ID for this user
    $pos_stmt = db()->prepare('SELECT id FROM notice_sign_positions WHERE document_id = ? AND user_id = ?');
    $pos_stmt->execute([$id, $user['id']]);
    $pos_id = $pos_stmt->fetchColumn() ?: null;

    // Determine IP
    $ip = $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    if ($ip) {
        $ip = trim(explode(',', $ip)[0]);
        if (strlen($ip) > 45) $ip = null;
    }

    // Record signature
    db()->prepare(
        'INSERT IGNORE INTO notice_signatures (document_id, user_id, position_id, ip_address)
         VALUES (?,?,?,?)'
    )->execute([$id, $user['id'], $pos_id, $ip]);

    log_change('notice-signing', 'UPDATE', $id, $doc['title'], 'signature', null, $user['full_name']);

    // Check if all signers have signed → auto-complete
    $pending = ns_pending_count($id);
    if ($pending === 0) {
        db()->prepare("UPDATE notice_documents SET status='completed', completed_at=NOW() WHERE id=?")
            ->execute([$id]);

        // Optionally save a copy to file manager
        db()->prepare(
            "INSERT INTO file_manager_files (file_name, description, creator_id, uploaded_file, original_name, mime_type, status)
             VALUES (?,?,?,?,?,?,?)"
        )->execute([
            'Signed Notice – ' . $doc['title'],
            'Auto-saved completed signed notice.',
            $user['id'],
            $doc['document_file'],
            $doc['original_name'],
            $doc['document_type'] === 'pdf' ? 'application/pdf' : 'image/jpeg',
            'active',
        ]);

        flash_set('success', 'You have signed the notice. All signatures collected – notice is now <strong>completed</strong> and saved to File Manager.');
    } else {
        flash_set('success', 'Your signature has been applied. ' . $pending . ' signer(s) still pending.');
    }

    redirect(APP_URL . '/notice-signing/view.php?id=' . $id);
}

// ── Action: Change status ──────────────────────────────────────────────────────
if ($action === 'change_status') {
    if (!ns_can_edit()) {
        flash_set('error', 'You do not have permission to change the status.');
        redirect(APP_URL . '/notice-signing/view.php?id=' . $id);
    }

    $new_status = in_array($_POST['new_status'] ?? '', ['draft','active'], true) ? $_POST['new_status'] : null;
    if ($new_status && $doc['status'] !== 'completed') {
        db()->prepare('UPDATE notice_documents SET status=? WHERE id=?')->execute([$new_status, $id]);
        log_change('notice-signing', 'UPDATE', $id, $doc['title'], 'status', $doc['status'], $new_status);
        flash_set('success', 'Notice status updated to <strong>' . h(ucfirst($new_status)) . '</strong>.');
    }
    redirect(APP_URL . '/notice-signing/view.php?id=' . $id);
}

// Unknown action
flash_set('error', 'Unknown action.');
redirect(APP_URL . '/notice-signing/view.php?id=' . $id);
