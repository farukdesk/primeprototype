<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/fp-helpers.php';

$current_user = auth_user();
$is_faculty   = isset($current_user['group_name']) && $current_user['group_name'] === 'Faculty';

// Faculty can only see their own files
if ($is_faculty) {
    $user_id = $current_user['id'];
    require_access('faculty-files', 'can_view');
} else {
    // Admin / Register Office: needs can_view on faculty-files or super admin
    if (!is_super_admin() && !can_access('faculty-files', 'can_view')) {
        require_access('faculty-files', 'can_view'); // triggers redirect
    }
    $user_id = (int)($_GET['user_id'] ?? 0);
    if (!$user_id) {
        flash_set('error', 'No faculty user specified.');
        redirect(APP_URL . '/faculty-profiles/index.php');
    }
}

// Load the faculty user
$fac_user = db()->prepare('SELECT u.*, g.name AS group_name FROM users u JOIN user_groups g ON g.id = u.group_id WHERE u.id = ?');
$fac_user->execute([$user_id]);
$fac_user = $fac_user->fetch();

if (!$fac_user || $fac_user['group_name'] !== 'Faculty') {
    flash_set('error', 'Faculty member not found.');
    redirect(APP_URL . '/faculty-profiles/index.php');
}

$page_title = 'Files – ' . ($fac_user['full_name'] ?? 'Faculty');

// ── Handle POST ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $post_action = $_POST['action'] ?? '';

    if ($post_action === 'upload_file' && fp_can_manage_files()) {
        $file_name   = trim($_POST['file_name']   ?? '');
        $description = trim($_POST['description'] ?? '');
        $is_internal = isset($_POST['is_internal']) ? 1 : 0;

        if ($file_name === '') {
            flash_set('error', 'File name / label is required.');
        } elseif (empty($_FILES['file']['name'])) {
            flash_set('error', 'Please select a file to upload.');
        } else {
            $uploaded = fp_upload_faculty_file($_FILES['file']);
            if ($uploaded === false) {
                flash_set('error', 'Invalid file type or size (max 20 MB). Allowed: images, PDF, Word, Excel, PPT, ZIP, TXT.');
            } else {
                db()->prepare(
                    'INSERT INTO faculty_files
                       (user_id, file_name, description, is_internal, stored_name, original_name, mime_type, file_size, uploaded_by)
                     VALUES (?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $user_id,
                    $file_name,
                    $description ?: null,
                    $is_internal,
                    $uploaded['stored_name'],
                    $uploaded['original_name'],
                    $uploaded['mime_type'],
                    $uploaded['file_size'],
                    $current_user['id'],
                ]);
                log_change('faculty-files', 'CREATE', $user_id,
                    $fac_user['full_name'],
                    'file_upload', null, $file_name,
                    'File uploaded: ' . $file_name . ($is_internal ? ' [internal]' : ''));
                flash_set('success', 'File uploaded successfully.');
            }
        }
        if ($is_faculty) {
            redirect(APP_URL . '/faculty-profiles/files.php#files');
        } else {
            redirect(APP_URL . '/faculty-profiles/files.php?user_id=' . $user_id . '#files');
        }
    }
}

// ── Fetch files ────────────────────────────────────────────────────────────────
$files_stmt = db()->prepare(
    'SELECT ff.*, u.full_name AS uploader_name
     FROM faculty_files ff
     LEFT JOIN users u ON u.id = ff.uploaded_by
     WHERE ff.user_id = ?
     ORDER BY ff.created_at DESC'
);
$files_stmt->execute([$user_id]);
$all_files = $files_stmt->fetchAll();

// Filter: faculty members cannot see internal files (unless they are the uploader)
$files = array_filter($all_files, fn($f) => fp_can_view_file($f));
$files = array_values($files);

$back_url = $is_faculty
    ? APP_URL . '/faculty-profiles/my-profile.php'
    : APP_URL . '/faculty-profiles/edit.php?user_id=' . $user_id;

// Pending delete requests for files visible on this page (super admin sees all)
$pending_deletes = [];
if (!$is_faculty && (is_super_admin() || fp_is_register_office())) {
    $pd_stmt = db()->prepare(
        'SELECT fdr.file_id, fdr.status, fdr.id AS req_id, u.full_name AS requester_name, fdr.created_at AS req_date
         FROM faculty_file_delete_requests fdr
         LEFT JOIN users u ON u.id = fdr.requested_by
         WHERE fdr.faculty_user_id = ?
         ORDER BY fdr.created_at DESC'
    );
    $pd_stmt->execute([$user_id]);
    foreach ($pd_stmt->fetchAll() as $row) {
        // Keep only the most recent request per file
        if (!isset($pending_deletes[$row['file_id']])) {
            $pending_deletes[$row['file_id']] = $row;
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <?php if (!$is_faculty): ?>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/faculty-profiles/index.php">Faculty Profiles</a></li>
            <li class="breadcrumb-item"><a href="<?= $back_url ?>">Edit Profile</a></li>
            <?php else: ?>
            <li class="breadcrumb-item"><a href="<?= $back_url ?>">My Profile</a></li>
            <?php endif; ?>
            <li class="breadcrumb-item active">Files</li>
        </ol>
    </nav>
    <div class="d-flex gap-2">
        <?php if (is_super_admin()): ?>
        <?php
            $pending_count_stmt = db()->prepare(
                "SELECT COUNT(*) FROM faculty_file_delete_requests WHERE status = 'pending'"
            );
            $pending_count_stmt->execute();
            $pending_count = (int)$pending_count_stmt->fetchColumn();
        ?>
        <a href="<?= APP_URL ?>/faculty-profiles/pending-deletes.php" class="btn btn-outline-warning position-relative" style="border-radius:10px;font-size:.875rem;">
            <i class="fas fa-clock me-1"></i>Delete Requests
            <?php if ($pending_count > 0): ?>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.65rem;">
                <?= $pending_count ?>
            </span>
            <?php endif; ?>
        </a>
        <?php endif; ?>
        <a href="<?= $back_url ?>" class="btn btn-light" style="border-radius:10px;font-size:.875rem;">
            <i class="fas fa-arrow-left me-1"></i>Back to Profile
        </a>
    </div>
</div>

<div class="row g-4">

    <!-- Upload Form (Register Office & Super Admin only) -->
    <?php if (fp_can_manage_files()): ?>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold">
                    <i class="fas fa-upload me-2 text-muted"></i>Upload File
                </h6>
            </div>
            <div class="card-body p-4">
                <p class="text-muted small mb-3">
                    Upload documents for <strong><?= h($fac_user['full_name']) ?></strong>.
                    Max 20 MB. Accepted: images, PDF, Word, Excel, PPT, ZIP, TXT.
                </p>
                <form method="POST" enctype="multipart/form-data"
                      action="<?= APP_URL ?>/faculty-profiles/files.php<?= $is_faculty ? '' : '?user_id=' . $user_id ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="upload_file">

                    <div class="mb-3">
                        <label class="form-label fw-medium">File Name / Label <span class="text-danger">*</span></label>
                        <input type="text" name="file_name" class="form-control" required
                               placeholder="e.g. Employment Contract" maxlength="255">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Description</label>
                        <textarea name="description" class="form-control" rows="2"
                                  placeholder="Optional description…"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Choose File <span class="text-danger">*</span></label>
                        <input type="file" name="file" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_internal" id="is_internal" value="1">
                            <label class="form-check-label" for="is_internal">
                                <i class="fas fa-lock text-secondary me-1"></i>Internal only
                                <small class="text-muted d-block">Faculty member will not see this file.</small>
                            </label>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100" style="border-radius:10px;">
                        <i class="fas fa-upload me-1"></i>Upload File
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Files List -->
    <div class="<?= fp_can_manage_files() ? 'col-lg-8' : 'col-12' ?>">
        <div class="card" id="files">
            <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-semibold">
                    <i class="fas fa-folder-open me-2 text-muted"></i>Files for <?= h($fac_user['full_name']) ?>
                </h6>
                <span class="badge bg-primary bg-opacity-10 text-primary"><?= count($files) ?> file<?= count($files) !== 1 ? 's' : '' ?></span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($files)): ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-folder-open fa-2x mb-3 opacity-25"></i>
                    <p class="mb-0">No files uploaded yet.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="px-4" style="width:40px;">#</th>
                                <th>File</th>
                                <th>Description</th>
                                <th>Size</th>
                                <th>Uploaded By</th>
                                <th>Date</th>
                                <?php if (fp_can_request_delete()): ?><th class="text-end pe-4">Actions</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($files as $fi => $f): ?>
                        <?php $ext = strtolower(pathinfo($f['original_name'], PATHINFO_EXTENSION)); ?>
                        <?php $del_req = $pending_deletes[$f['id']] ?? null; ?>
                        <tr>
                            <td class="px-4"><?= $fi + 1 ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <i class="<?= fp_file_icon($ext) ?> fa-lg"></i>
                                    <div>
                                        <a href="<?= UPLOAD_URL ?>/faculty-profiles/files/<?= h($f['stored_name']) ?>"
                                           target="_blank" rel="noopener"
                                           class="fw-medium text-decoration-none">
                                            <?= h($f['file_name']) ?>
                                        </a>
                                        <?php if ($f['is_id_card']): ?>
                                        <span class="badge bg-info ms-1" style="font-size:.65rem;">ID Card</span>
                                        <?php endif; ?>
                                        <?php if ($f['is_internal']): ?>
                                        <span class="badge bg-secondary ms-1" style="font-size:.65rem;" title="Hidden from faculty member"><i class="fas fa-lock me-1"></i>Internal</span>
                                        <?php endif; ?>
                                        <div class="small text-muted"><?= h($f['original_name']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="text-muted small"><?= $f['description'] ? h($f['description']) : '—' ?></td>
                            <td class="text-muted small"><?= fp_format_size((int)$f['file_size']) ?></td>
                            <td class="small"><?= h($f['uploader_name'] ?? '—') ?></td>
                            <td class="text-muted small"><?= h(date('d M Y', strtotime($f['created_at']))) ?></td>
                            <?php if (fp_can_request_delete()): ?>
                            <td class="text-end pe-4">
                                <?php if ($del_req && $del_req['status'] === 'pending'): ?>
                                <span class="badge bg-warning text-dark" title="Delete requested by <?= h($del_req['requester_name']) ?> on <?= h(date('d M Y', strtotime($del_req['req_date']))) ?>">
                                    <i class="fas fa-clock me-1"></i>Pending Deletion
                                </span>
                                <?php elseif (is_super_admin()): ?>
                                <form method="POST" action="<?= APP_URL ?>/faculty-profiles/file-delete.php"
                                      onsubmit="return confirm('Delete file \'<?= addslashes(h($f['file_name'])) ?>\'? This cannot be undone.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="file_id"  value="<?= (int)$f['id'] ?>">
                                    <input type="hidden" name="user_id"  value="<?= $user_id ?>">
                                    <button class="btn btn-sm btn-outline-danger" style="border-radius:7px;" title="Delete immediately">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                                <?php else: ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary" style="border-radius:7px;"
                                        title="Request deletion"
                                        data-bs-toggle="modal"
                                        data-bs-target="#requestDeleteModal"
                                        data-file-id="<?= (int)$f['id'] ?>"
                                        data-file-name="<?= h($f['file_name']) ?>"
                                        data-user-id="<?= $user_id ?>">
                                    <i class="fas fa-trash-alt me-1"></i>Request Delete
                                </button>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>


<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<!-- Request Delete Modal (non-super-admin Register Office) -->
<?php if (!is_super_admin() && fp_can_request_delete()): ?>
<div class="modal fade" id="requestDeleteModal" tabindex="-1" aria-labelledby="requestDeleteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= APP_URL ?>/faculty-profiles/file-delete.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action"   value="request_delete">
                <input type="hidden" name="file_id"  id="rdFileId">
                <input type="hidden" name="user_id"  id="rdUserId">
                <div class="modal-header">
                    <h5 class="modal-title" id="requestDeleteModalLabel">
                        <i class="fas fa-trash-alt me-2 text-secondary"></i>Request File Deletion
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted mb-3">
                        You are requesting deletion of <strong id="rdFileName"></strong>.
                        A super administrator will review and approve this request.
                    </p>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Reason <span class="text-muted fw-normal">(optional)</span></label>
                        <textarea name="request_note" class="form-control" rows="3"
                                  placeholder="Briefly explain why this file should be deleted…"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-secondary">
                        <i class="fas fa-paper-plane me-1"></i>Submit Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
document.getElementById('requestDeleteModal').addEventListener('show.bs.modal', function(e) {
    var btn = e.relatedTarget;
    document.getElementById('rdFileId').value   = btn.dataset.fileId;
    document.getElementById('rdUserId').value   = btn.dataset.userId;
    document.getElementById('rdFileName').textContent = btn.dataset.fileName;
});
</script>
<?php endif; ?>
