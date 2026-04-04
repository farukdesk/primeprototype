<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../change-log/helpers.php';
require_access('cms-notice-board', 'can_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/cms/notice-board/index.php');
}
csrf_check();

$id           = (int)($_POST['id'] ?? 0);
$is_super     = is_super_admin();
$current_user = auth_user();

$stmt = db()->prepare('SELECT * FROM cms_notices WHERE id = ?');
$stmt->execute([$id]);
$notice = $stmt->fetch();

if (!$notice) {
    flash_set('error', 'Notice not found.');
    redirect(APP_URL . '/cms/notice-board/index.php');
}

// Non-super admins may only request deletion of their own notices
if (!$is_super && (int)($notice['created_by'] ?? 0) !== (int)$current_user['id']) {
    flash_set('error', 'You do not have permission to delete this notice.');
    redirect(APP_URL . '/cms/notice-board/index.php');
}

$db = db();

if ($is_super) {
    // ── Super admin: delete immediately ────────────────────────────────
    // Delete associated news row if any
    if ($notice['news_id']) {
        $db->prepare('DELETE FROM cms_news WHERE id = ?')->execute([$notice['news_id']]);
    }

    // Delete attachment file from disk
    if ($notice['attachment']) {
        $path = UPLOAD_DIR . '/notices/' . $notice['attachment'];
        if (file_exists($path)) @unlink($path);
    }

    log_change('cms-notice-board', 'DELETE', $id, $notice['title'], null, null, null,
        'Notice deleted directly by super admin.');

    // Delete the notice record
    $db->prepare('DELETE FROM cms_notices WHERE id = ?')->execute([$id]);

    flash_set('success', 'Notice <strong>' . h($notice['title']) . '</strong> deleted.');
} else {
    // ── Non-super admin: queue delete request for approval ───────────
    $existing = $db->prepare(
        "SELECT id FROM cms_pending_changes
         WHERE module='notice' AND record_id=? AND action='DELETE' AND status='pending'"
    );
    $existing->execute([$id]);
    if ($existing->fetch()) {
        flash_set('info', 'A deletion request for this notice is already awaiting approval.');
        redirect(APP_URL . '/cms/notice-board/index.php');
    }

    $db->prepare(
        "INSERT INTO cms_pending_changes
         (module, record_id, record_title, action, requested_by, payload)
         VALUES ('notice', ?, ?, 'DELETE', ?, ?)"
    )->execute([$id, $notice['title'], $current_user['id'],
        json_encode(['was_approved' => (int)$notice['is_approved']])]);

    // Hide notice from public website until super admin approves or rejects
    $db->prepare('UPDATE cms_notices SET is_approved=0 WHERE id=?')->execute([$id]);

    log_change('cms-notice-board', 'DELETE', $id, $notice['title'], null, null, null,
        'Delete request submitted by ' . $current_user['full_name'] . ' – awaiting super-admin approval.');

    // Notify super admins by email
    require_once __DIR__ . '/../../includes/mailer.php';
    $supers = db()->query(
        'SELECT u.full_name, u.email FROM users u
         JOIN user_groups g ON g.id = u.group_id
         WHERE g.is_super = 1 AND u.is_active = 1'
    )->fetchAll();
    foreach ($supers as $su) {
        send_template_email('notice_approval_needed', $su['email'], $su['full_name'], [
            'full_name'      => $su['full_name'],
            'requester_name' => $current_user['full_name'],
            'notice_title'   => $notice['title'],
            'action'         => 'DELETE',
            'pending_url'    => APP_URL . '/cms/pending-changes/index.php',
        ]);
    }

    flash_set('success', 'Deletion request for <strong>' . h($notice['title']) . '</strong> submitted for super-admin approval.');
}

redirect(APP_URL . '/cms/notice-board/index.php');
