<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('file-manager');
require_once __DIR__ . '/helpers.php';

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) { flash_set('error', 'Invalid file.'); redirect(APP_URL . '/file-manager/index.php'); }

$stmt = db()->prepare(
    'SELECT f.*, u.full_name AS creator_name, h.full_name AS holder_name
     FROM file_manager_files f
     LEFT JOIN users u ON u.id = f.creator_id
     LEFT JOIN users h ON h.id = f.current_holder_id
     WHERE f.id = ?'
);
$stmt->execute([$id]);
$file = $stmt->fetch();
if (!$file) { flash_set('error', 'File not found.'); redirect(APP_URL . '/file-manager/index.php'); }

if (!fm_can_view_file($file)) {
    flash_set('error', 'Access denied.'); redirect(APP_URL . '/file-manager/index.php');
}

$page_title = h($file['file_name']);

// Pages
$pages = fm_get_pages($id);

// Tagged users
$tagged = db()->prepare(
    'SELECT u.id, u.full_name, u.email, tb.full_name AS tagged_by_name, t.created_at
     FROM file_manager_tagged_users t
     JOIN users u  ON u.id  = t.user_id
     JOIN users tb ON tb.id = t.tagged_by
     WHERE t.file_id = ?
     ORDER BY t.created_at ASC'
);
$tagged->execute([$id]);
$tagged_users = $tagged->fetchAll();

// Transfer history
$xfers = db()->prepare(
    'SELECT t.*, fu.full_name AS from_name, fu.email AS from_email,
            tu.full_name AS to_name, tu.email AS to_email
     FROM file_manager_transfers t
     JOIN users fu ON fu.id = t.from_user_id
     JOIN users tu ON tu.id = t.to_user_id
     WHERE t.file_id = ?
     ORDER BY t.created_at DESC'
);
$xfers->execute([$id]);
$transfers = $xfers->fetchAll();

// Pending transfer to current user
$cur_user = auth_user();
$pending_my_transfer = null;
foreach ($transfers as $xf) {
    if ((int)$xf['to_user_id'] === (int)$cur_user['id'] && $xf['status'] === 'pending') {
        $pending_my_transfer = $xf;
        break;
    }
}

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
    <div class="d-flex gap-2 flex-wrap">
        <?php if (!empty($pages)): ?>
        <a href="<?= APP_URL ?>/file-manager/download-all.php?id=<?= $id ?>" target="_blank"
           class="btn btn-outline-success" style="border-radius:10px;">
            <i class="fas fa-file-pdf me-1"></i> Download All Pages
        </a>
        <?php endif; ?>
        <?php if (fm_can_edit() && (int)$file['current_holder_id'] === (int)$cur_user['id'] || is_super_admin()): ?>
        <a href="<?= APP_URL ?>/file-manager/transfer.php?file_id=<?= $id ?>" class="btn btn-outline-warning" style="border-radius:10px;">
            <i class="fas fa-exchange-alt me-1"></i> Transfer File
        </a>
        <?php endif; ?>
        <?php if (fm_can_edit()): ?>
        <a href="<?= APP_URL ?>/file-manager/edit.php?id=<?= $id ?>" class="btn btn-outline-secondary" style="border-radius:10px;">
            <i class="fas fa-edit me-1"></i> Edit
        </a>
        <?php endif; ?>
        <?php if (fm_can_delete()): ?>
        <button type="button" class="btn btn-outline-danger" style="border-radius:10px;"
                onclick="new bootstrap.Modal(document.getElementById('deleteModal')).show()">
            <i class="fas fa-trash me-1"></i> Delete
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Pending transfer banner -->
<?php if ($pending_my_transfer): ?>
<div class="alert alert-warning d-flex align-items-center gap-3 mb-4" style="border-radius:12px;">
    <i class="fas fa-exchange-alt fa-lg"></i>
    <div class="flex-grow-1">
        <strong>Incoming File Transfer</strong> –
        <strong><?= h($pending_my_transfer['from_name']) ?></strong> wants to transfer this file to you.
        <?php if ($pending_my_transfer['message']): ?>
        <br><span class="text-muted" style="font-size:.88rem;">Message: <?= h($pending_my_transfer['message']) ?></span>
        <?php endif; ?>
    </div>
    <form method="POST" action="<?= APP_URL ?>/file-manager/transfer.php" class="d-flex gap-2 flex-shrink-0">
        <?= csrf_field() ?>
        <input type="hidden" name="action"      value="respond">
        <input type="hidden" name="transfer_id" value="<?= $pending_my_transfer['id'] ?>">
        <button type="submit" name="decision" value="accepted"
                class="btn btn-success btn-sm" style="border-radius:8px;">
            <i class="fas fa-check me-1"></i> Accept
        </button>
        <button type="submit" name="decision" value="rejected"
                class="btn btn-danger btn-sm" style="border-radius:8px;">
            <i class="fas fa-times me-1"></i> Decline
        </button>
    </form>
</div>
<?php endif; ?>

<div class="row g-4">
    <!-- Main content -->
    <div class="col-lg-8">

        <!-- File header card -->
        <div class="card mb-4" style="border-radius:12px;">
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

                <!-- Initiator info -->
                <?php if ($file['initiator_name'] || $file['initiator_department'] || $file['initiator_designation']): ?>
                <div class="mb-4 p-3 bg-light rounded-3">
                    <h6 class="fw-semibold text-muted mb-2" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">
                        <i class="fas fa-user-tie me-1"></i> Initiator
                    </h6>
                    <div class="row g-2">
                        <?php if ($file['initiator_name']): ?>
                        <div class="col-auto"><strong style="font-size:.88rem;"><?= h($file['initiator_name']) ?></strong></div>
                        <?php endif; ?>
                        <?php if ($file['initiator_designation']): ?>
                        <div class="col-auto text-muted" style="font-size:.85rem;"><?= h($file['initiator_designation']) ?></div>
                        <?php endif; ?>
                        <?php if ($file['initiator_department']): ?>
                        <div class="col-auto text-muted" style="font-size:.85rem;"><i class="fas fa-building me-1"></i><?= h($file['initiator_department']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

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

                <?php if ($file['proposal'] ?? null): ?>
                <div class="mb-4">
                    <h6 class="fw-semibold text-muted mb-2" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Proposal / Purpose</h6>
                    <p class="mb-0"><?= nl2br(h($file['proposal'])) ?></p>
                </div>
                <?php endif; ?>

                <?php if ($file['notes']): ?>
                <div class="mb-0">
                    <h6 class="fw-semibold text-muted mb-2" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Notes</h6>
                    <p class="mb-0"><?= nl2br(h($file['notes'])) ?></p>
                </div>
                <?php endif; ?>

                <?php if (!$file['description'] && !$file['file_location'] && !($file['proposal'] ?? null) && !$file['notes'] && !$file['initiator_name']): ?>
                <p class="text-muted mb-0">No additional details recorded.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Main digital copy -->
        <?php if ($file['uploaded_file']): ?>
        <div class="card mb-4" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-paperclip me-2 text-muted"></i>Main Digital Copy</h6>
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

        <!-- Pages -->
        <div class="card mb-4" style="border-radius:12px;">
            <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-semibold">
                    <i class="fas fa-layer-group me-2 text-info"></i>Pages
                    <span class="badge bg-secondary ms-1"><?= count($pages) ?></span>
                </h6>
                <?php if (fm_can_manage()): ?>
                <a href="<?= APP_URL ?>/file-manager/pages.php?file_id=<?= $id ?>" class="btn btn-sm btn-primary" style="border-radius:8px;">
                    <i class="fas fa-plus me-1"></i> Add Page
                </a>
                <?php endif; ?>
            </div>
            <?php if (empty($pages)): ?>
            <div class="card-body px-4 py-4 text-muted">No pages added yet. Click <strong>Add Page</strong> to upload pages.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:80px">Page</th>
                            <th>Title / Subject</th>
                            <th>Category</th>
                            <th>File</th>
                            <th>Signatures</th>
                            <th style="width:130px">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pages as $pg): ?>
                    <tr>
                        <td class="text-center fw-bold" style="font-size:.9rem;">P<?= $pg['page_number'] ?></td>
                        <td>
                            <div class="fw-medium"><?= h($pg['title'] ?: 'Page ' . $pg['page_number']) ?></div>
                            <?php if ($pg['subject']): ?>
                            <div class="text-muted" style="font-size:.8rem;"><i class="fas fa-tag me-1"></i><?= h($pg['subject']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($pg['category'] === 'Notes'): ?>
                            <span class="badge bg-warning text-dark">Notes</span>
                            <?php else: ?>
                            <span class="badge bg-info text-dark">Document</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($pg['uploaded_file']): ?>
                            <a href="<?= UPLOAD_URL ?>/<?= FM_UPLOAD_SUBDIR ?>/<?= h($pg['uploaded_file']) ?>" target="_blank"
                               class="btn btn-sm btn-outline-secondary" style="border-radius:6px;" title="<?= h($pg['original_name']) ?>">
                                <i class="fas fa-download"></i>
                            </a>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($pg['requires_signature']): ?>
                            <?php
                            $total_sig = db()->prepare('SELECT COUNT(*) FROM file_manager_page_sign_positions WHERE page_id = ?');
                            $total_sig->execute([$pg['id']]);
                            $total_sig_count = (int)$total_sig->fetchColumn();
                            $done_sig = db()->prepare('SELECT COUNT(*) FROM file_manager_page_signatures WHERE page_id = ?');
                            $done_sig->execute([$pg['id']]);
                            $done_sig_count = (int)$done_sig->fetchColumn();
                            ?>
                            <span class="badge <?= $done_sig_count >= $total_sig_count && $total_sig_count > 0 ? 'bg-success' : 'bg-warning text-dark' ?>">
                                <?= $done_sig_count ?>/<?= $total_sig_count ?> signed
                            </span>
                            <?php else: ?>
                            <span class="text-muted" style="font-size:.8rem;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex gap-1 flex-wrap">
                                <?php if ($pg['category'] === 'Notes' && $pg['requires_signature']): ?>
                                <a href="<?= APP_URL ?>/file-manager/page-sign-map.php?page_id=<?= $pg['id'] ?>"
                                   class="btn btn-sm btn-outline-warning" style="border-radius:6px;" title="Manage Signers">
                                    <i class="fas fa-signature"></i>
                                </a>
                                <?php if (fm_needs_to_sign_page($pg['id'])): ?>
                                <a href="<?= APP_URL ?>/file-manager/page-sign.php?page_id=<?= $pg['id'] ?>"
                                   class="btn btn-sm btn-success" style="border-radius:6px;" title="Sign Now">
                                    <i class="fas fa-pen-nib"></i>
                                </a>
                                <?php endif; ?>
                                <?php endif; ?>
                                <?php if (fm_can_delete()): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger" style="border-radius:6px;"
                                        onclick="confirmPageDelete(<?= $pg['id'] ?>, <?= json_encode($pg['title'] ?: 'Page ' . $pg['page_number']) ?>)">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Transfer history -->
        <?php if (!empty($transfers)): ?>
        <div class="card mb-4" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-exchange-alt me-2 text-warning"></i>Transfer History</h6>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>From</th>
                            <th>To</th>
                            <th>Status</th>
                            <th>Message</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($transfers as $xf): ?>
                    <tr>
                        <td style="font-size:.85rem"><?= h($xf['from_name']) ?></td>
                        <td style="font-size:.85rem"><?= h($xf['to_name']) ?></td>
                        <td>
                            <?php if ($xf['status'] === 'pending'): ?>
                            <span class="badge bg-warning text-dark">Pending</span>
                            <?php elseif ($xf['status'] === 'accepted'): ?>
                            <span class="badge bg-success">Accepted</span>
                            <?php else: ?>
                            <span class="badge bg-danger">Declined</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:.82rem;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?= h($xf['message'] ?: '—') ?>
                        </td>
                        <td style="font-size:.8rem"><?= date('d M Y', strtotime($xf['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- Right column -->
    <div class="col-lg-4">

        <!-- Details -->
        <div class="card mb-4" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-info-circle me-2 text-muted"></i>Details</h6>
            </div>
            <ul class="list-group list-group-flush" style="border-radius:0 0 12px 12px;">
                <li class="list-group-item px-4 py-3 d-flex justify-content-between">
                    <span class="text-muted" style="font-size:.85rem">Created by</span>
                    <span class="fw-medium" style="font-size:.85rem"><?= h($file['creator_name'] ?? '—') ?></span>
                </li>
                <li class="list-group-item px-4 py-3 d-flex justify-content-between">
                    <span class="text-muted" style="font-size:.85rem">Current Holder</span>
                    <span class="fw-medium" style="font-size:.85rem"><?= h($file['holder_name'] ?? '—') ?></span>
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
                <?php if ($file['page_number'] ?? null): ?>
                <li class="list-group-item px-4 py-3 d-flex justify-content-between">
                    <span class="text-muted" style="font-size:.85rem">Ref. No.</span>
                    <span style="font-size:.85rem"><?= h($file['page_number']) ?></span>
                </li>
                <?php endif; ?>
            </ul>
        </div>

        <!-- Tagged users -->
        <div class="card mb-4" style="border-radius:12px;">
            <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-tags me-2 text-warning"></i>Access List</h6>
                <?php if (fm_can_edit()): ?>
                <a href="<?= APP_URL ?>/file-manager/tag-users.php?file_id=<?= $id ?>"
                   class="btn btn-sm btn-outline-primary" style="border-radius:6px;">
                    <i class="fas fa-user-plus me-1"></i> Manage
                </a>
                <?php endif; ?>
            </div>
            <?php if (empty($tagged_users)): ?>
            <div class="card-body px-4 py-3 text-muted" style="font-size:.85rem;">
                No users tagged. Only the creator and super admins can see this file.
            </div>
            <?php else: ?>
            <ul class="list-group list-group-flush" style="border-radius:0 0 12px 12px;">
                <?php foreach ($tagged_users as $tu): ?>
                <li class="list-group-item px-4 py-2 d-flex align-items-center gap-2">
                    <div style="width:30px;height:30px;border-radius:50%;background:#f0f4ff;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-user text-primary" style="font-size:.7rem;"></i>
                    </div>
                    <div class="flex-grow-1 overflow-hidden">
                        <div class="fw-medium" style="font-size:.85rem;"><?= h($tu['full_name']) ?></div>
                        <div class="text-muted" style="font-size:.75rem;"><?= h($tu['email']) ?></div>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- Page delete modal -->
<form id="pageDeleteForm" method="POST" action="<?= APP_URL ?>/file-manager/page-delete.php">
    <?= csrf_field() ?>
    <input type="hidden" name="page_id" id="pageDeleteId">
    <input type="hidden" name="file_id" value="<?= $id ?>">
</form>
<div class="modal fade" id="pageDeleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title">Delete Page</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Delete <strong id="pageDeleteLabel"></strong>? This cannot be undone.</p>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" style="border-radius:8px;"
                        onclick="document.getElementById('pageDeleteForm').submit()">
                    <i class="fas fa-trash me-1"></i> Delete
                </button>
            </div>
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
                <p>Delete <strong><?= h($file['file_name']) ?></strong> and all its pages? This cannot be undone.</p>
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

<script>
function confirmPageDelete(id, label) {
    document.getElementById('pageDeleteId').value = id;
    document.getElementById('pageDeleteLabel').textContent = label;
    new bootstrap.Modal(document.getElementById('pageDeleteModal')).show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
