<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('gallery');

$db  = db();
$id  = (int)($_GET['id'] ?? 0);
$st  = $db->prepare(
    'SELECT a.*, d.name AS dept_name, p.program_name
     FROM gallery_albums a
     LEFT JOIN dept_departments      d ON d.id = a.dept_id
     LEFT JOIN dept_academic_programs p ON p.id = a.program_id
     WHERE a.id = ?'
);
$st->execute([$id]);
$album = $st->fetch();

if (!$album) {
    flash_set('error', 'Album not found.');
    redirect(APP_URL . '/gallery/index.php');
}

$page_title = h($album['title']) . ' – Gallery';

// ── Photos (approved) ─────────────────────────────────────────────────────────
$approved = $db->prepare(
    'SELECT * FROM gallery_photos WHERE album_id = ? AND status = "approved" ORDER BY sort_order ASC, created_at ASC'
);
$approved->execute([$id]);
$approved_photos = $approved->fetchAll();

// ── Photos (pending) ──────────────────────────────────────────────────────────
$pending = $db->prepare(
    'SELECT gp.*, u.full_name AS uploader_name
     FROM gallery_photos gp
     LEFT JOIN users u ON u.id = gp.uploaded_by
     WHERE gp.album_id = ? AND gp.status = "pending"
     ORDER BY gp.created_at ASC'
);
$pending->execute([$id]);
$pending_photos = $pending->fetchAll();

// ── Photos (rejected) ─────────────────────────────────────────────────────────
$rejected = $db->prepare(
    'SELECT * FROM gallery_photos WHERE album_id = ? AND status = "rejected" ORDER BY created_at DESC'
);
$rejected->execute([$id]);
$rejected_photos = $rejected->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-images me-2 text-primary"></i><?= h($album['title']) ?></h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/dashboard.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/gallery/index.php">Gallery</a></li>
            <li class="breadcrumb-item active"><?= h($album['title']) ?></li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (gallery_can_edit()): ?>
        <a href="<?= APP_URL ?>/gallery/photo-upload.php?album_id=<?= $id ?>" class="btn btn-success">
            <i class="fas fa-upload me-1"></i> Upload Photos
        </a>
        <a href="<?= APP_URL ?>/gallery/edit.php?id=<?= $id ?>" class="btn btn-outline-primary">
            <i class="fas fa-edit me-1"></i> Edit Album
        </a>
        <?php endif; ?>
        <?php if (gallery_can_delete()): ?>
        <a href="<?= APP_URL ?>/gallery/delete.php?id=<?= $id ?>" class="btn btn-outline-danger"
           onclick="return confirm('Delete this album and ALL its photos?')">
            <i class="fas fa-trash me-1"></i> Delete Album
        </a>
        <?php endif; ?>
    </div>
</div>

<?= flash_show() ?>

<!-- Album meta -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
        <div class="row g-3 align-items-center">
            <?php if ($album['cover_photo']): ?>
            <div class="col-auto">
                <img src="<?= GAL_URL_COVERS ?>/<?= h($album['cover_photo']) ?>" alt=""
                     class="rounded" style="height:80px;width:120px;object-fit:cover;">
            </div>
            <?php endif; ?>
            <div class="col">
                <div class="d-flex flex-wrap gap-3">
                    <?php if ($album['dept_name']): ?>
                    <span><i class="fas fa-building text-muted me-1"></i><?= h($album['dept_name']) ?></span>
                    <?php endif; ?>
                    <?php if ($album['program_name']): ?>
                    <span><span class="badge bg-light text-dark border"><?= h($album['program_name']) ?></span></span>
                    <?php endif; ?>
                    <?php if ($album['event_date']): ?>
                    <span><i class="fas fa-calendar text-muted me-1"></i><?= date('d M Y', strtotime($album['event_date'])) ?></span>
                    <?php endif; ?>
                    <?= gallery_active_badge($album['is_active']) ?>
                </div>
                <?php if ($album['description']): ?>
                <p class="text-muted small mt-2 mb-0"><?= h($album['description']) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4" id="photoTabs">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-approved">
            <i class="fas fa-check-circle text-success me-1"></i>
            Approved <span class="badge bg-success ms-1"><?= count($approved_photos) ?></span>
        </button>
    </li>
    <?php if (gallery_can_edit()): ?>
    <li class="nav-item">
        <button class="nav-link <?= count($pending_photos) > 0 ? 'text-warning' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-pending">
            <i class="fas fa-clock me-1"></i>
            Pending <span class="badge bg-warning text-dark ms-1"><?= count($pending_photos) ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-rejected">
            <i class="fas fa-times-circle text-danger me-1"></i>
            Rejected <span class="badge bg-danger ms-1"><?= count($rejected_photos) ?></span>
        </button>
    </li>
    <?php endif; ?>
</ul>

<div class="tab-content">

    <!-- Approved Photos -->
    <div class="tab-pane fade show active" id="tab-approved">
        <?php if (empty($approved_photos)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-images fa-3x mb-3 d-block opacity-25"></i>No approved photos yet.
            <?php if (gallery_can_edit()): ?>
            <br><a href="<?= APP_URL ?>/gallery/photo-upload.php?album_id=<?= $id ?>">Upload photos</a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="row g-3">
            <?php foreach ($approved_photos as $p): ?>
            <div class="col-6 col-md-4 col-lg-3 col-xl-2">
                <div class="card border-0 shadow-sm h-100" style="border-radius:10px;overflow:hidden;">
                    <a href="<?= GAL_URL_PHOTOS ?>/<?= h($p['stored_name']) ?>" target="_blank">
                        <img src="<?= GAL_URL_PHOTOS ?>/<?= h($p['stored_name']) ?>" alt=""
                             style="width:100%;height:110px;object-fit:cover;display:block;">
                    </a>
                    <div class="card-body p-2">
                        <?php if ($p['caption']): ?>
                        <p class="small text-muted mb-1" style="font-size:.75rem;overflow:hidden;max-height:2.4em;"><?= h($p['caption']) ?></p>
                        <?php endif; ?>
                        <?php if (gallery_can_delete()): ?>
                        <a href="<?= APP_URL ?>/gallery/photo-delete.php?id=<?= $p['id'] ?>&album_id=<?= $id ?>"
                           class="btn btn-xs btn-outline-danger w-100" style="font-size:.7rem;padding:2px 6px;"
                           onclick="return confirm('Delete this photo?')"><i class="fas fa-trash me-1"></i>Delete</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Pending Photos -->
    <?php if (gallery_can_edit()): ?>
    <div class="tab-pane fade" id="tab-pending">
        <?php if (empty($pending_photos)): ?>
        <div class="text-center py-5 text-muted"><i class="fas fa-clock fa-3x mb-3 d-block opacity-25"></i>No pending photos.</div>
        <?php else: ?>
        <div class="row g-3">
            <?php foreach ($pending_photos as $p): ?>
            <div class="col-6 col-md-4 col-lg-3 col-xl-2">
                <div class="card border-0 shadow-sm h-100" style="border-radius:10px;overflow:hidden;">
                    <img src="<?= GAL_URL_PHOTOS ?>/<?= h($p['stored_name']) ?>" alt=""
                         style="width:100%;height:110px;object-fit:cover;display:block;">
                    <div class="card-body p-2">
                        <?php if ($p['caption']): ?>
                        <p class="small text-muted mb-1" style="font-size:.75rem;"><?= h($p['caption']) ?></p>
                        <?php endif; ?>
                        <?php if ($p['uploader_name']): ?>
                        <p class="small text-muted mb-1" style="font-size:.7rem;">By: <?= h($p['uploader_name']) ?></p>
                        <?php endif; ?>
                        <div class="d-flex gap-1 mt-1">
                            <form method="post" action="<?= APP_URL ?>/gallery/photo-approve.php" class="flex-grow-1">
                                <?= csrf_field() ?>
                                <input type="hidden" name="photo_id" value="<?= $p['id'] ?>">
                                <input type="hidden" name="album_id" value="<?= $id ?>">
                                <input type="hidden" name="action" value="approve">
                                <button class="btn btn-xs btn-success w-100" style="font-size:.7rem;padding:2px 6px;"><i class="fas fa-check me-1"></i>Approve</button>
                            </form>
                            <form method="post" action="<?= APP_URL ?>/gallery/photo-approve.php" class="flex-grow-1">
                                <?= csrf_field() ?>
                                <input type="hidden" name="photo_id" value="<?= $p['id'] ?>">
                                <input type="hidden" name="album_id" value="<?= $id ?>">
                                <input type="hidden" name="action" value="reject">
                                <button class="btn btn-xs btn-danger w-100" style="font-size:.7rem;padding:2px 6px;"><i class="fas fa-times me-1"></i>Reject</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Rejected Photos -->
    <div class="tab-pane fade" id="tab-rejected">
        <?php if (empty($rejected_photos)): ?>
        <div class="text-center py-5 text-muted"><i class="fas fa-times-circle fa-3x mb-3 d-block opacity-25"></i>No rejected photos.</div>
        <?php else: ?>
        <div class="row g-3">
            <?php foreach ($rejected_photos as $p): ?>
            <div class="col-6 col-md-4 col-lg-3 col-xl-2">
                <div class="card border-0 shadow-sm h-100" style="border-radius:10px;overflow:hidden;opacity:.6;">
                    <img src="<?= GAL_URL_PHOTOS ?>/<?= h($p['stored_name']) ?>" alt=""
                         style="width:100%;height:110px;object-fit:cover;display:block;">
                    <div class="card-body p-2">
                        <?php if ($p['caption']): ?>
                        <p class="small text-muted mb-1" style="font-size:.75rem;"><?= h($p['caption']) ?></p>
                        <?php endif; ?>
                        <div class="d-flex gap-1 mt-1">
                            <form method="post" action="<?= APP_URL ?>/gallery/photo-approve.php" class="flex-grow-1">
                                <?= csrf_field() ?>
                                <input type="hidden" name="photo_id" value="<?= $p['id'] ?>">
                                <input type="hidden" name="album_id" value="<?= $id ?>">
                                <input type="hidden" name="action" value="approve">
                                <button class="btn btn-xs btn-success w-100" style="font-size:.7rem;padding:2px 6px;"><i class="fas fa-check me-1"></i>Approve</button>
                            </form>
                            <?php if (gallery_can_delete()): ?>
                            <a href="<?= APP_URL ?>/gallery/photo-delete.php?id=<?= $p['id'] ?>&album_id=<?= $id ?>"
                               class="btn btn-xs btn-outline-danger flex-grow-1" style="font-size:.7rem;padding:2px 6px;"
                               onclick="return confirm('Delete this photo?')"><i class="fas fa-trash me-1"></i>Del</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div><!-- /.tab-content -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
