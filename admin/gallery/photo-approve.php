<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('gallery', 'can_edit');

$db = db();

// ── Handle POST (approve / reject a single photo) ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $photo_id = (int)($_POST['photo_id'] ?? 0);
    $album_id = (int)($_POST['album_id'] ?? 0);
    $action   = $_POST['action'] ?? '';

    if (!in_array($action, ['approve', 'reject'], true)) {
        flash_set('error', 'Invalid action.');
        redirect(APP_URL . '/gallery/photo-approve.php');
    }

    $st = $db->prepare('SELECT * FROM gallery_photos WHERE id = ?');
    $st->execute([$photo_id]);
    $photo = $st->fetch();

    if ($photo) {
        $new_status = ($action === 'approve') ? 'approved' : 'rejected';
        $user = auth_user();
        $db->prepare(
            'UPDATE gallery_photos SET status=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?'
        )->execute([$new_status, $user['id'], $photo_id]);

        log_change('gallery', 'UPDATE', $photo_id, 'Photo #' . $photo_id, 'status', $photo['status'], $new_status, "Photo {$action}d.");
        flash_set('success', 'Photo ' . ($action === 'approve' ? 'approved' : 'rejected') . '.');
    }

    $back = $album_id > 0
        ? APP_URL . '/gallery/view.php?id=' . $album_id
        : APP_URL . '/gallery/photo-approve.php';
    redirect($back);
}

// ── Bulk approve/reject ───────────────────────────────────────────────────────
$page_title = 'Pending Photo Approvals';

$st = $db->query(
    "SELECT gp.*, a.title AS album_title, u.full_name AS uploader_name
     FROM gallery_photos gp
     JOIN gallery_albums a ON a.id = gp.album_id
     LEFT JOIN users u ON u.id = gp.uploaded_by
     WHERE gp.status = 'pending'
     ORDER BY gp.created_at ASC"
);
$pending = $st->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-clock me-2 text-warning"></i>Pending Photo Approvals</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/dashboard.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/gallery/index.php">Gallery</a></li>
            <li class="breadcrumb-item active">Pending Approvals</li>
        </ol></nav>
    </div>
</div>

<?= flash_show() ?>

<?php if (empty($pending)): ?>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5 text-muted">
        <i class="fas fa-check-circle fa-4x mb-3 d-block text-success opacity-50"></i>
        All caught up! No pending photos awaiting approval.
    </div>
</div>
<?php else: ?>
<div class="alert alert-warning">
    <i class="fas fa-clock me-2"></i><?= count($pending) ?> photo(s) are waiting for approval.
</div>

<div class="row g-3">
    <?php
    $current_album = null;
    foreach ($pending as $p):
        if ($current_album !== $p['album_id']):
            if ($current_album !== null) echo '</div><br>';
            $current_album = $p['album_id'];
            echo '<div class="col-12"><h6 class="fw-bold text-muted mb-1 border-bottom pb-1">'
               . '<i class="fas fa-folder-open me-1"></i>'
               . h($p['album_title'])
               . ' <a href="' . APP_URL . '/gallery/view.php?id=' . $p['album_id'] . '" class="btn btn-xs btn-outline-secondary ms-2" style="font-size:.7rem;padding:2px 6px;">View Album</a>'
               . '</h6></div>';
        endif;
    ?>
    <div class="col-6 col-md-4 col-lg-3 col-xl-2">
        <div class="card border-0 shadow-sm h-100" style="border-radius:10px;overflow:hidden;">
            <img src="<?= GAL_URL_PHOTOS ?>/<?= h($p['stored_name']) ?>" alt=""
                 style="width:100%;height:130px;object-fit:cover;display:block;">
            <div class="card-body p-2">
                <?php if ($p['caption']): ?>
                <p class="small text-muted mb-1" style="font-size:.75rem;"><?= h($p['caption']) ?></p>
                <?php endif; ?>
                <?php if ($p['uploader_name']): ?>
                <p class="small text-muted mb-1" style="font-size:.7rem;"><i class="fas fa-user me-1"></i><?= h($p['uploader_name']) ?></p>
                <?php endif; ?>
                <p class="small text-muted mb-2" style="font-size:.7rem;"><i class="fas fa-clock me-1"></i><?= date('d M, H:i', strtotime($p['created_at'])) ?></p>
                <div class="d-flex gap-1">
                    <form method="post" class="flex-grow-1">
                        <?= csrf_field() ?>
                        <input type="hidden" name="photo_id" value="<?= $p['id'] ?>">
                        <input type="hidden" name="album_id" value="<?= $p['album_id'] ?>">
                        <input type="hidden" name="action" value="approve">
                        <button class="btn btn-success w-100" style="font-size:.7rem;padding:3px 0;"><i class="fas fa-check me-1"></i>Approve</button>
                    </form>
                    <form method="post" class="flex-grow-1">
                        <?= csrf_field() ?>
                        <input type="hidden" name="photo_id" value="<?= $p['id'] ?>">
                        <input type="hidden" name="album_id" value="<?= $p['album_id'] ?>">
                        <input type="hidden" name="action" value="reject">
                        <button class="btn btn-danger w-100" style="font-size:.7rem;padding:3px 0;"><i class="fas fa-times me-1"></i>Reject</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
