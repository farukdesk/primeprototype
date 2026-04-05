<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('file-manager');
require_once __DIR__ . '/helpers.php';

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) { flash_set('error', 'Invalid file.'); redirect(APP_URL . '/file-manager/index.php'); }

$stmt = db()->prepare(
    'SELECT f.*, u.full_name AS creator_name
     FROM file_manager_files f
     LEFT JOIN users u ON u.id = f.creator_id
     WHERE f.id = ?'
);
$stmt->execute([$id]);
$file = $stmt->fetch();
if (!$file) { flash_set('error', 'File not found.'); redirect(APP_URL . '/file-manager/index.php'); }

$page_title = h($file['file_name']);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/file-manager/index.php">File Manager</a></li>
            <li class="breadcrumb-item active"><?= h($file['file_name']) ?></li>
        </ol>
    </nav>
    <div class="d-flex gap-2">
        <?php if (fm_can_edit()): ?>
        <a href="<?= APP_URL ?>/file-manager/edit.php?id=<?= $id ?>" class="btn btn-outline-secondary" style="border-radius:10px;">
            <i class="fas fa-edit me-1"></i> Edit
        </a>
        <?php endif; ?>
        <?php if (fm_can_delete()): ?>
        <button type="button" class="btn btn-outline-danger" style="border-radius:10px;"
                onclick="document.getElementById('deleteModal') && new bootstrap.Modal(document.getElementById('deleteModal')).show()">
            <i class="fas fa-trash me-1"></i> Delete
        </button>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4 d-flex align-items-center gap-3">
                <div style="width:48px;height:48px;border-radius:12px;background:#f0f4ff;display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-folder-open text-warning fa-lg"></i>
                </div>
                <div>
                    <h5 class="mb-0 fw-semibold"><?= h($file['file_name']) ?></h5>
                    <?php if ($file['category']): ?>
                    <span class="badge bg-light text-dark border"><?= h($file['category']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="ms-auto">
                    <?php if ($file['status'] === 'active'): ?>
                    <span class="badge bg-success fs-6">Active</span>
                    <?php else: ?>
                    <span class="badge bg-secondary fs-6">Archived</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body p-4">
                <?php if ($file['description']): ?>
                <div class="mb-4">
                    <h6 class="fw-semibold text-muted mb-2" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Description</h6>
                    <p class="mb-0"><?= nl2br(h($file['description'])) ?></p>
                </div>
                <?php endif; ?>

                <?php if ($file['file_location']): ?>
                <div class="mb-4">
                    <h6 class="fw-semibold text-muted mb-2" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Physical Location</h6>
                    <p class="mb-0"><i class="fas fa-map-marker-alt text-muted me-2"></i><?= h($file['file_location']) ?></p>
                </div>
                <?php endif; ?>

                <?php if ($file['notes']): ?>
                <div class="mb-0">
                    <h6 class="fw-semibold text-muted mb-2" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Notes</h6>
                    <p class="mb-0"><?= nl2br(h($file['notes'])) ?></p>
                </div>
                <?php endif; ?>

                <?php if (!$file['description'] && !$file['file_location'] && !$file['notes']): ?>
                <p class="text-muted mb-0">No additional details recorded.</p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($file['uploaded_file']): ?>
        <div class="card mt-4" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-paperclip me-2 text-muted"></i>Digital Copy</h6>
            </div>
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-3 p-3 bg-light rounded-3">
                    <i class="<?= fm_mime_icon($file['mime_type'] ?? '') ?> fa-2x"></i>
                    <div class="flex-grow-1 overflow-hidden">
                        <div class="fw-medium text-truncate"><?= h($file['original_name']) ?></div>
                        <div class="text-muted" style="font-size:.8rem">
                            <?= h(strtoupper(pathinfo($file['original_name'], PATHINFO_EXTENSION))) ?>
                            · <?= fm_format_size((int)$file['file_size']) ?>
                        </div>
                    </div>
                    <a href="<?= UPLOAD_URL ?>/<?= FM_UPLOAD_SUBDIR ?>/<?= h($file['uploaded_file']) ?>" target="_blank"
                       class="btn btn-primary" style="border-radius:8px;">
                        <i class="fas fa-download me-1"></i> Download
                    </a>
                </div>
                <?php if ($file['mime_type'] && str_starts_with($file['mime_type'], 'image/')): ?>
                <div class="mt-3 text-center">
                    <img src="<?= UPLOAD_URL ?>/<?= FM_UPLOAD_SUBDIR ?>/<?= h($file['uploaded_file']) ?>"
                         alt="Preview" style="max-width:100%;border-radius:8px;max-height:400px;object-fit:contain;">
                </div>
                <?php elseif ($file['mime_type'] === 'application/pdf'): ?>
                <div class="mt-3">
                    <iframe src="<?= UPLOAD_URL ?>/<?= FM_UPLOAD_SUBDIR ?>/<?= h($file['uploaded_file']) ?>"
                            style="width:100%;height:500px;border:none;border-radius:8px;"></iframe>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-info-circle me-2 text-muted"></i>Details</h6>
            </div>
            <ul class="list-group list-group-flush" style="border-radius:0 0 12px 12px;">
                <li class="list-group-item px-4 py-3 d-flex justify-content-between">
                    <span class="text-muted" style="font-size:.85rem">Created by</span>
                    <span class="fw-medium" style="font-size:.85rem"><?= h($file['creator_name'] ?? '—') ?></span>
                </li>
                <li class="list-group-item px-4 py-3 d-flex justify-content-between">
                    <span class="text-muted" style="font-size:.85rem">Created</span>
                    <span style="font-size:.85rem"><?= date('d M Y, g:i A', strtotime($file['created_at'])) ?></span>
                </li>
                <li class="list-group-item px-4 py-3 d-flex justify-content-between">
                    <span class="text-muted" style="font-size:.85rem">Last updated</span>
                    <span style="font-size:.85rem"><?= date('d M Y, g:i A', strtotime($file['updated_at'])) ?></span>
                </li>
                <?php if ($file['category']): ?>
                <li class="list-group-item px-4 py-3 d-flex justify-content-between">
                    <span class="text-muted" style="font-size:.85rem">Category</span>
                    <span class="badge bg-light text-dark border"><?= h($file['category']) ?></span>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<?php if (fm_can_delete()): ?>
<form id="deleteForm" method="POST" action="<?= APP_URL ?>/file-manager/delete.php">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
</form>
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title">Delete File</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete <strong><?= h($file['file_name']) ?></strong>? This will also remove the digital copy if present.</p>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" style="border-radius:8px;"
                        onclick="document.getElementById('deleteForm').submit()">
                    <i class="fas fa-trash me-1"></i> Delete
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
