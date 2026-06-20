<?php
/**
 * Apply the current user's signature to a Notes page.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('file-manager');
require_once __DIR__ . '/helpers.php';

$page_id = (int)($_GET['page_id'] ?? $_POST['page_id'] ?? 0);
if ($page_id < 1) { flash_set('error', 'Invalid page.'); redirect(APP_URL . '/file-manager/index.php'); }

$pg_stmt = db()->prepare(
    'SELECT p.*, f.file_name, f.id AS file_id
     FROM file_manager_pages p
     JOIN file_manager_files f ON f.id = p.file_id
     WHERE p.id = ?'
);
$pg_stmt->execute([$page_id]);
$page = $pg_stmt->fetch();
if (!$page) { flash_set('error', 'Page not found.'); redirect(APP_URL . '/file-manager/index.php'); }

$user = auth_user();

// Verify this is a Notes page requiring signature
if ($page['category'] !== 'Notes' || !$page['requires_signature']) {
    flash_set('error', 'This page does not require a signature.');
    redirect(APP_URL . '/file-manager/view.php?id=' . $page['file_id']);
}

// Check if user needs to sign
if (!fm_needs_to_sign_page($page_id)) {
    flash_set('info', 'You have already signed this page or are not a required signer.');
    redirect(APP_URL . '/file-manager/view.php?id=' . $page['file_id']);
}

// Check user has a signature image
$sig_stmt = db()->prepare('SELECT signature_file FROM users WHERE id = ?');
$sig_stmt->execute([$user['id']]);
$sig_file = $sig_stmt->fetchColumn();
if (!$sig_file) {
    flash_set('error', 'You have not uploaded a signature image. Please go to My Signature first.');
    redirect(APP_URL . '/my-signature/index.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Show confirm page
    $positions = fm_get_page_positions($page_id);
    $my_pos    = null;
    foreach ($positions as $pos) {
        if ((int)$pos['user_id'] === (int)$user['id'] && !$pos['sig_id']) {
            $my_pos = $pos;
            break;
        }
    }

    $page_title = 'Sign Page ' . $page['page_number'];
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>/file-manager/index.php">File Manager</a></li>
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>/file-manager/view.php?id=<?= $page['file_id'] ?>"><?= h($page['file_name']) ?></a></li>
                <li class="breadcrumb-item active">Sign Page <?= $page['page_number'] ?></li>
            </ol>
        </nav>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="card" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-pen-nib me-2 text-warning"></i>
                        Apply Your Signature – Page <?= $page['page_number'] ?>
                    </h6>
                </div>
                <div class="card-body p-4">

                    <?php if ($page['subject']): ?>
                    <div class="mb-3 p-3 bg-light rounded-3">
                        <strong>Subject:</strong> <?= h($page['subject']) ?>
                    </div>
                    <?php endif; ?>

                    <!-- Signature preview -->
                    <?php
                    $sig_dir = UPLOAD_DIR . '/signatures/' . $sig_file;
                    $sig_url = UPLOAD_URL . '/signatures/' . $sig_file;
                    ?>
                    <div class="mb-4 text-center">
                        <p class="text-muted mb-2" style="font-size:.85rem;">Your signature on file:</p>
                        <div style="border:2px dashed #dee2e6;border-radius:10px;padding:16px;display:inline-block;background:#fafafa;">
                            <img src="<?= $sig_url ?>" alt="Your signature" style="max-height:80px;max-width:300px;">
                        </div>
                    </div>

                    <!-- Document with position overlay -->
                    <?php if ($page['uploaded_file'] && $my_pos): ?>
                    <div class="mb-3">
                        <p class="text-muted" style="font-size:.85rem;">Your signature will be placed at the position shown below:</p>
                        <?php if (str_starts_with($page['mime_type'] ?? '', 'image/')): ?>
                        <div style="position:relative;border:1px solid #dee2e6;border-radius:8px;overflow:hidden;">
                            <img src="<?= UPLOAD_URL ?>/<?= FM_UPLOAD_SUBDIR ?>/<?= h($page['uploaded_file']) ?>"
                                 style="width:100%;display:block;" alt="Page">
                            <div style="position:absolute;
                                        left:<?= $my_pos['x_percent'] ?>%;
                                        top:<?= $my_pos['y_percent'] ?>%;
                                        transform:translate(-50%,-50%);
                                        background:rgba(255,255,255,.9);
                                        border:2px solid #e67e22;border-radius:6px;padding:6px 10px;text-align:center;">
                                <img src="<?= $sig_url ?>" style="max-height:40px;max-width:120px;display:block;" alt="Signature">
                                <?php if (!empty($my_pos['show_datetime'])): ?>
                                <div style="font-size:9px;color:#555;margin-top:3px;white-space:nowrap;">
                                    <i class="fas fa-clock" style="font-size:8px;"></i>
                                    Date &amp; time will appear here
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($my_pos['show_datetime'])): ?>
                    <div class="alert alert-info py-2 px-3 mb-3" style="border-radius:8px;font-size:.83rem;">
                        <i class="fas fa-clock me-1"></i>
                        The date &amp; time of your signature will be automatically recorded and shown below your signature.
                    </div>
                    <?php endif; ?>

                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="page_id" value="<?= $page_id ?>">
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-success btn-lg" style="border-radius:10px;">
                                <i class="fas fa-check me-1"></i> Confirm & Apply Signature
                            </button>
                            <a href="<?= APP_URL ?>/file-manager/view.php?id=<?= $page['file_id'] ?>"
                               class="btn btn-light" style="border-radius:10px;">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// ── POST: apply signature ─────────────────────────────────────────────────────
csrf_check();

$pos_stmt = db()->prepare(
    'SELECT id FROM file_manager_page_sign_positions WHERE page_id = ? AND user_id = ?'
);
$pos_stmt->execute([$page_id, $user['id']]);
$pos_id = $pos_stmt->fetchColumn() ?: null;

$ip = $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
if ($ip) {
    $ip = trim(explode(',', $ip)[0]);
    if (strlen($ip) > 45) $ip = null;
}

db()->prepare(
    'INSERT IGNORE INTO file_manager_page_signatures (page_id, user_id, position_id, ip_address)
     VALUES (?,?,?,?)'
)->execute([$page_id, $user['id'], $pos_id, $ip]);

log_change('file-manager', 'UPDATE', $page['file_id'],
    $user['full_name'] . ' signed Page ' . $page['page_number'] . ' of ' . $page['file_name']);

$pending = fm_page_pending_signers($page_id);
if ($pending === 0) {
    flash_set('success', 'Your signature has been applied. All required signatures collected for this page.');
} else {
    flash_set('success', 'Your signature has been applied. ' . $pending . ' signer(s) still pending.');
}

redirect(APP_URL . '/file-manager/view.php?id=' . $page['file_id']);
