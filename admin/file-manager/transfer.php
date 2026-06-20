<?php
/**
 * File Transfer – initiate, view pending, and respond to transfers.
 *
 * GET  ?file_id=N           – initiate a new transfer form
 * GET  (no file_id)         – list all pending transfers for current user
 * POST action=initiate      – create a new transfer request
 * POST action=respond       – accept or reject a transfer
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('file-manager');
require_once __DIR__ . '/helpers.php';

$user    = auth_user();
$errors  = [];

// ── POST: Initiate transfer ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'initiate') {
    csrf_check();
    $file_id    = (int)($_POST['file_id']    ?? 0);
    $to_user_id = (int)($_POST['to_user_id'] ?? 0);
    $message    = trim($_POST['message']     ?? '');

    $f_stmt = db()->prepare('SELECT * FROM file_manager_files WHERE id = ?');
    $f_stmt->execute([$file_id]);
    $file = $f_stmt->fetch();

    if (!$file) {
        flash_set('error', 'File not found.');
        redirect(APP_URL . '/file-manager/index.php');
    }
    if ((int)$file['current_holder_id'] !== (int)$user['id'] && !is_super_admin()) {
        flash_set('error', 'You are not the current holder of this file.');
        redirect(APP_URL . '/file-manager/view.php?id=' . $file_id);
    }

    $tu_stmt = db()->prepare('SELECT id, full_name, email FROM users WHERE id = ? AND is_active = 1');
    $tu_stmt->execute([$to_user_id]);
    $to_user = $tu_stmt->fetch();

    if (!$to_user) {
        $errors[] = 'Please select a valid recipient user.';
    }

    if ((int)$to_user_id === (int)$user['id']) {
        $errors[] = 'You cannot transfer a file to yourself.';
    }

    // Check no pending transfer exists for this file to this user
    if (empty($errors)) {
        $exist = db()->prepare(
            "SELECT 1 FROM file_manager_transfers
             WHERE file_id = ? AND to_user_id = ? AND status = 'pending' LIMIT 1"
        );
        $exist->execute([$file_id, $to_user_id]);
        if ($exist->fetchColumn()) {
            $errors[] = 'A pending transfer to this user already exists.';
        }
    }

    if (empty($errors)) {
        db()->prepare(
            'INSERT INTO file_manager_transfers (file_id, from_user_id, to_user_id, message)
             VALUES (?,?,?,?)'
        )->execute([$file_id, $user['id'], $to_user_id, $message ?: null]);

        $transfer_id = (int)db()->lastInsertId();
        $transfer = ['id' => $transfer_id, 'message' => $message];

        fm_notify_transfer_request($transfer, $file, $to_user, $user);

        log_change('file-manager', 'UPDATE', $file_id,
            "Transfer initiated from {$user['full_name']} to {$to_user['full_name']}");
        flash_set('success', 'Transfer request sent to <strong>' . h($to_user['full_name']) . '</strong>.');
        redirect(APP_URL . '/file-manager/view.php?id=' . $file_id);
    }
}

// ── POST: Respond (accept/reject) ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'respond') {
    csrf_check();
    $transfer_id   = (int)($_POST['transfer_id']   ?? 0);
    $decision      = in_array($_POST['decision'] ?? '', ['accepted','rejected'], true) ? $_POST['decision'] : null;
    $response_note = trim($_POST['response_note']   ?? '');

    if (!$decision) {
        flash_set('error', 'Invalid decision.');
        redirect(APP_URL . '/file-manager/transfer.php');
    }

    $xf_stmt = db()->prepare('SELECT * FROM file_manager_transfers WHERE id = ?');
    $xf_stmt->execute([$transfer_id]);
    $xf = $xf_stmt->fetch();

    if (!$xf || (int)$xf['to_user_id'] !== (int)$user['id'] || $xf['status'] !== 'pending') {
        flash_set('error', 'Transfer not found or already processed.');
        redirect(APP_URL . '/file-manager/transfer.php');
    }

    db()->prepare(
        'UPDATE file_manager_transfers SET status=?, response_note=?, responded_at=NOW() WHERE id=?'
    )->execute([$decision, $response_note ?: null, $transfer_id]);

    // If accepted, update current_holder on the file
    if ($decision === 'accepted') {
        db()->prepare('UPDATE file_manager_files SET current_holder_id=? WHERE id=?')
            ->execute([$user['id'], $xf['file_id']]);
    }

    $f_stmt = db()->prepare('SELECT * FROM file_manager_files WHERE id = ?');
    $f_stmt->execute([$xf['file_id']]);
    $file = $f_stmt->fetch();

    $from_u = db()->prepare('SELECT id, full_name, email FROM users WHERE id = ?');
    $from_u->execute([$xf['from_user_id']]);
    $from_user = $from_u->fetch();

    $xf_full = array_merge($xf, ['response_note' => $response_note]);
    if ($decision === 'accepted') {
        fm_notify_transfer_accepted($xf_full, $file, $from_user, $user);
        log_change('file-manager', 'UPDATE', $xf['file_id'],
            "{$user['full_name']} accepted file transfer from {$from_user['full_name']}");
        flash_set('success', 'Transfer accepted. You are now the current holder.');
    } else {
        fm_notify_transfer_rejected($xf_full, $file, $from_user, $user);
        log_change('file-manager', 'UPDATE', $xf['file_id'],
            "{$user['full_name']} declined file transfer from {$from_user['full_name']}");
        flash_set('success', 'Transfer declined.');
    }

    redirect(APP_URL . '/file-manager/view.php?id=' . $xf['file_id']);
}

// ── GET: Show form or pending list ────────────────────────────────────────────
$file_id = (int)($_GET['file_id'] ?? 0);
$file    = null;

if ($file_id > 0) {
    $f_stmt = db()->prepare('SELECT * FROM file_manager_files WHERE id = ?');
    $f_stmt->execute([$file_id]);
    $file = $f_stmt->fetch();
    if (!$file) { flash_set('error', 'File not found.'); redirect(APP_URL . '/file-manager/index.php'); }
    if (!fm_can_view_file($file)) { flash_set('error', 'Access denied.'); redirect(APP_URL . '/file-manager/index.php'); }
}

// Pending transfers to current user
$pending_stmt = db()->prepare(
    'SELECT t.*, f.file_name, f.category,
            fu.full_name AS from_name, fu.email AS from_email
     FROM file_manager_transfers t
     JOIN file_manager_files f ON f.id = t.file_id
     JOIN users fu ON fu.id = t.from_user_id
     WHERE t.to_user_id = ? AND t.status = ?
     ORDER BY t.created_at DESC'
);
$pending_stmt->execute([$user['id'], 'pending']);
$pending_transfers = $pending_stmt->fetchAll();

$all_users_stmt = db()->prepare(
    'SELECT id, full_name, email FROM users WHERE is_active = 1 AND id <> ? ORDER BY full_name'
);
$all_users_stmt->execute([$user['id']]);
$all_users = $all_users_stmt->fetchAll();

$page_title = $file ? 'Transfer File – ' . h($file['file_name']) : 'Pending Transfers';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/file-manager/index.php">File Manager</a></li>
            <?php if ($file): ?>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/file-manager/view.php?id=<?= $file_id ?>"><?= h($file['file_name']) ?></a></li>
            <li class="breadcrumb-item active">Transfer File</li>
            <?php else: ?>
            <li class="breadcrumb-item active">Pending Transfers</li>
            <?php endif; ?>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<!-- Pending transfers -->
<?php if (!empty($pending_transfers)): ?>
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-inbox me-2 text-warning"></i>
            Your Pending Transfers
            <span class="badge bg-warning text-dark ms-1"><?= count($pending_transfers) ?></span>
        </h6>
    </div>
    <?php foreach ($pending_transfers as $pt): ?>
    <div class="card-body border-bottom p-4">
        <div class="row align-items-start g-3">
            <div class="col">
                <h6 class="fw-semibold mb-1"><?= h($pt['file_name']) ?></h6>
                <?php if ($pt['category']): ?>
                <span class="badge bg-light text-dark border mb-2"><?= h($pt['category']) ?></span>
                <?php endif; ?>
                <p class="text-muted mb-1" style="font-size:.85rem;">
                    <strong>From:</strong> <?= h($pt['from_name']) ?> · <?= date('d M Y', strtotime($pt['created_at'])) ?>
                </p>
                <?php if ($pt['message']): ?>
                <p class="mb-0" style="font-size:.85rem;"><strong>Message:</strong> <?= h($pt['message']) ?></p>
                <?php endif; ?>
            </div>
            <div class="col-auto">
                <form method="POST" class="d-flex gap-2 align-items-end flex-column">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action"      value="respond">
                    <input type="hidden" name="transfer_id" value="<?= $pt['id'] ?>">
                    <div class="mb-2">
                        <input type="text" name="response_note" class="form-control form-control-sm"
                               placeholder="Note (optional)" style="min-width:200px;">
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" name="decision" value="accepted"
                                class="btn btn-success btn-sm" style="border-radius:8px;">
                            <i class="fas fa-check me-1"></i> Accept
                        </button>
                        <button type="submit" name="decision" value="rejected"
                                class="btn btn-danger btn-sm" style="border-radius:8px;">
                            <i class="fas fa-times me-1"></i> Decline
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php elseif (!$file): ?>
<div class="card" style="border-radius:12px;">
    <div class="card-body text-center py-5 text-muted">
        <i class="fas fa-check-circle fa-3x mb-3 text-success"></i>
        <p>No pending file transfers.</p>
        <a href="<?= APP_URL ?>/file-manager/index.php" class="btn btn-outline-primary" style="border-radius:10px;">
            Back to File Manager
        </a>
    </div>
</div>
<?php endif; ?>

<!-- Initiate transfer form -->
<?php if ($file && ((int)$file['current_holder_id'] === (int)$user['id'] || is_super_admin())): ?>
<div class="row g-4">
    <div class="col-lg-6">
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold">
                    <i class="fas fa-exchange-alt me-2 text-warning"></i>
                    Transfer: <em><?= h($file['file_name']) ?></em>
                </h6>
            </div>
            <div class="card-body p-4">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action"  value="initiate">
                    <input type="hidden" name="file_id" value="<?= $file_id ?>">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Transfer To <span class="text-danger">*</span></label>
                        <select name="to_user_id" class="form-select" required>
                            <option value="">— Select recipient —</option>
                            <?php foreach ($all_users as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= h($u['full_name']) ?> &lt;<?= h($u['email']) ?>&gt;</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Message <span class="text-muted fw-normal">(optional)</span></label>
                        <textarea name="message" class="form-control" rows="3"
                                  placeholder="Reason for transfer or instructions…"></textarea>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-warning" style="border-radius:10px;">
                            <i class="fas fa-paper-plane me-1"></i> Send Transfer Request
                        </button>
                        <a href="<?= APP_URL ?>/file-manager/view.php?id=<?= $file_id ?>"
                           class="btn btn-light" style="border-radius:10px;">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php elseif ($file): ?>
<div class="alert alert-info" style="border-radius:12px;">
    <i class="fas fa-info-circle me-2"></i>
    You are not the current holder of this file, so you cannot initiate a transfer.
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
