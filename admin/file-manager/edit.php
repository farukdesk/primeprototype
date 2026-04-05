<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('file-manager', 'can_edit');
require_once __DIR__ . '/helpers.php';

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) { flash_set('error', 'Invalid file.'); redirect(APP_URL . '/file-manager/index.php'); }

$stmt = db()->prepare('SELECT * FROM file_manager_files WHERE id = ?');
$stmt->execute([$id]);
$file = $stmt->fetch();
if (!$file) { flash_set('error', 'File not found.'); redirect(APP_URL . '/file-manager/index.php'); }

if (!fm_can_view_file($file)) {
    flash_set('error', 'Access denied.'); redirect(APP_URL . '/file-manager/index.php');
}

$page_title = 'Edit File';
$errors     = [];
clear_old();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $file_name             = trim($_POST['file_name']             ?? '');
    $description           = trim($_POST['description']           ?? '');
    $category              = trim($_POST['category']              ?? '');
    $file_location         = trim($_POST['file_location']         ?? '');
    $notes                 = trim($_POST['notes']                 ?? '');
    $proposal              = trim($_POST['proposal']              ?? '');
    $page_number           = trim($_POST['page_number']           ?? '');
    $initiator_name        = trim($_POST['initiator_name']        ?? '');
    $initiator_department  = trim($_POST['initiator_department']  ?? '');
    $initiator_designation = trim($_POST['initiator_designation'] ?? '');
    $status                = in_array($_POST['status'] ?? '', ['active','archived'], true) ? $_POST['status'] : 'active';
    $remove_file           = !empty($_POST['remove_file']);

    if ($file_name === '')           $errors[] = 'File name is required.';
    if (mb_strlen($file_name) > 255) $errors[] = 'File name must be 255 characters or less.';

    $new_uploaded  = null;
    $new_original  = null;
    $new_mime      = null;
    $new_size      = null;
    if (!empty($_FILES['uploaded_file']['name'])) {
        $result = fm_upload_file($_FILES['uploaded_file']);
        if ($result === false) {
            $errors[] = 'Digital copy: invalid file type or file too large (max 50 MB).';
        } else {
            $new_uploaded = $result;
            $new_original = $_FILES['uploaded_file']['name'];
            $finfo        = new finfo(FILEINFO_MIME_TYPE);
            $new_mime     = $finfo->file(UPLOAD_DIR . '/' . FM_UPLOAD_SUBDIR . '/' . $result);
            $new_size     = (int)$_FILES['uploaded_file']['size'];
        }
    }

    if (empty($errors)) {
        if ($new_uploaded) {
            fm_delete_file($file['uploaded_file']);
            $final_uploaded = $new_uploaded;
            $final_original = $new_original;
            $final_mime     = $new_mime;
            $final_size     = $new_size;
        } elseif ($remove_file) {
            fm_delete_file($file['uploaded_file']);
            $final_uploaded = null;
            $final_original = null;
            $final_mime     = null;
            $final_size     = null;
        } else {
            $final_uploaded = $file['uploaded_file'];
            $final_original = $file['original_name'];
            $final_mime     = $file['mime_type'];
            $final_size     = $file['file_size'];
        }

        db()->prepare(
            'UPDATE file_manager_files
             SET file_name=?, description=?, category=?, file_location=?,
                 uploaded_file=?, original_name=?, mime_type=?, file_size=?,
                 notes=?, proposal=?, page_number=?,
                 initiator_name=?, initiator_department=?, initiator_designation=?,
                 status=?
             WHERE id=?'
        )->execute([
            $file_name,
            $description           ?: null,
            $category              ?: null,
            $file_location         ?: null,
            $final_uploaded,
            $final_original,
            $final_mime,
            $final_size,
            $notes                 ?: null,
            $proposal              ?: null,
            $page_number           ?: null,
            $initiator_name        ?: null,
            $initiator_department  ?: null,
            $initiator_designation ?: null,
            $status,
            $id,
        ]);

        log_change('file-manager', 'UPDATE', $id, $file_name);
        flash_set('success', 'File <strong>' . h($file_name) . '</strong> updated.');
        redirect(APP_URL . '/file-manager/view.php?id=' . $id);
    }

    if ($new_uploaded) fm_delete_file($new_uploaded);
    save_old(compact(
        'file_name','description','category','file_location','notes','proposal','page_number',
        'initiator_name','initiator_department','initiator_designation','status'
    ));
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/file-manager/index.php">File Manager</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/file-manager/view.php?id=<?= $id ?>"><?= h($file['file_name']) ?></a></li>
            <li class="breadcrumb-item active">Edit</li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <div class="row g-4">

        <!-- Left column -->
        <div class="col-lg-8">

            <!-- Initiator Info -->
            <div class="card mb-4" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-user-tie me-2 text-primary"></i>Initiator Information</h6>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-medium">Initiator Name</label>
                            <input type="text" name="initiator_name" class="form-control"
                                   value="<?= old('initiator_name', $file['initiator_name'] ?? '') ?>"
                                   maxlength="150">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium">Department</label>
                            <input type="text" name="initiator_department" class="form-control"
                                   value="<?= old('initiator_department', $file['initiator_department'] ?? '') ?>"
                                   maxlength="200">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium">Designation</label>
                            <input type="text" name="initiator_designation" class="form-control"
                                   value="<?= old('initiator_designation', $file['initiator_designation'] ?? '') ?>"
                                   maxlength="200">
                        </div>
                    </div>
                </div>
            </div>

            <!-- File Details -->
            <div class="card mb-4" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-info-circle me-2 text-muted"></i>File Details</h6>
                </div>
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-medium">File Name <span class="text-danger">*</span></label>
                        <input type="text" name="file_name" class="form-control"
                               value="<?= old('file_name', $file['file_name']) ?>"
                               required maxlength="255">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Description</label>
                        <textarea name="description" class="form-control" rows="3"><?= h(old('description', $file['description'] ?? '')) ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Category</label>
                        <input type="text" name="category" class="form-control"
                               value="<?= old('category', $file['category'] ?? '') ?>" maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Physical / Cabinet Location</label>
                        <input type="text" name="file_location" class="form-control"
                               value="<?= old('file_location', $file['file_location'] ?? '') ?>" maxlength="500">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Proposal / Purpose</label>
                        <textarea name="proposal" class="form-control" rows="3"><?= h(old('proposal', $file['proposal'] ?? '')) ?></textarea>
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-medium">Page / Reference Number</label>
                        <input type="text" name="page_number" class="form-control"
                               value="<?= old('page_number', $file['page_number'] ?? '') ?>" maxlength="50">
                    </div>
                </div>
            </div>

            <!-- Digital Copy -->
            <div class="card mb-4" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-upload me-2 text-muted"></i>Digital Copy</h6>
                </div>
                <div class="card-body p-4">
                    <?php if ($file['uploaded_file']): ?>
                    <div class="d-flex align-items-center gap-3 mb-3 p-3 bg-light rounded-3">
                        <i class="<?= fm_mime_icon($file['mime_type'] ?? '') ?> fa-lg"></i>
                        <div class="flex-grow-1 overflow-hidden">
                            <div class="fw-medium text-truncate"><?= h($file['original_name']) ?></div>
                            <div class="text-muted" style="font-size:.8rem"><?= fm_format_size((int)$file['file_size']) ?></div>
                        </div>
                        <a href="<?= UPLOAD_URL ?>/<?= FM_UPLOAD_SUBDIR ?>/<?= h($file['uploaded_file']) ?>" target="_blank"
                           class="btn btn-sm btn-outline-primary" style="border-radius:6px;">
                            <i class="fas fa-download"></i>
                        </a>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="remove_file" id="remove_file" value="1">
                        <label class="form-check-label text-danger" for="remove_file">Remove current file</label>
                    </div>
                    <label class="form-label text-muted" style="font-size:.85rem">Replace with a new file (optional):</label>
                    <?php endif; ?>
                    <input type="file" name="uploaded_file" class="form-control"
                           accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.txt,.jpg,.jpeg,.png,.gif,.webp">
                    <div class="form-text mt-1">Max 50 MB.</div>
                </div>
            </div>

            <!-- Notes -->
            <div class="card" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-sticky-note me-2 text-muted"></i>Notes</h6>
                </div>
                <div class="card-body p-4">
                    <textarea name="notes" class="form-control" rows="4"><?= h(old('notes', $file['notes'] ?? '')) ?></textarea>
                </div>
            </div>
        </div>

        <!-- Right column -->
        <div class="col-lg-4">
            <div class="card" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-cog me-2 text-muted"></i>Settings</h6>
                </div>
                <div class="card-body p-4">
                    <div class="mb-4">
                        <label class="form-label fw-medium">Status</label>
                        <select name="status" class="form-select">
                            <option value="active"   <?= old('status', $file['status']) === 'active'   ? 'selected' : '' ?>>Active</option>
                            <option value="archived" <?= old('status', $file['status']) === 'archived' ? 'selected' : '' ?>>Archived</option>
                        </select>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i> Save Changes
                        </button>
                        <a href="<?= APP_URL ?>/file-manager/view.php?id=<?= $id ?>" class="btn btn-light" style="border-radius:10px;">Cancel</a>
                    </div>
                </div>
            </div>
        </div>

    </div>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
