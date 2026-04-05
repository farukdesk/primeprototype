<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('file-manager', 'can_create');
require_once __DIR__ . '/helpers.php';

$page_title = 'New File';
$errors     = [];
clear_old();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $file_name     = trim($_POST['file_name']     ?? '');
    $description   = trim($_POST['description']   ?? '');
    $category      = trim($_POST['category']      ?? '');
    $file_location = trim($_POST['file_location'] ?? '');
    $notes         = trim($_POST['notes']         ?? '');
    $status        = in_array($_POST['status'] ?? '', ['active','archived'], true) ? $_POST['status'] : 'active';

    if ($file_name === '')          $errors[] = 'File name is required.';
    if (mb_strlen($file_name) > 255) $errors[] = 'File name must be 255 characters or less.';

    // Optional digital copy upload
    $uploaded_file = null;
    $original_name = null;
    $mime_type     = null;
    $file_size     = null;
    if (!empty($_FILES['uploaded_file']['name'])) {
        $result = fm_upload_file($_FILES['uploaded_file']);
        if ($result === false) {
            $errors[] = 'Digital copy: invalid file type or file too large (max 50 MB).';
        } else {
            $uploaded_file = $result;
            $original_name = $_FILES['uploaded_file']['name'];
            $finfo         = new finfo(FILEINFO_MIME_TYPE);
            $mime_type     = $finfo->file(UPLOAD_DIR . '/' . FM_UPLOAD_SUBDIR . '/' . $result);
            $file_size     = (int)$_FILES['uploaded_file']['size'];
        }
    }

    if (empty($errors)) {
        $user = auth_user();
        db()->prepare(
            'INSERT INTO file_manager_files
               (file_name, description, category, creator_id, file_location,
                uploaded_file, original_name, mime_type, file_size, notes, status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $file_name,
            $description ?: null,
            $category    ?: null,
            $user['id'],
            $file_location ?: null,
            $uploaded_file,
            $original_name,
            $mime_type,
            $file_size,
            $notes ?: null,
            $status,
        ]);

        log_change('file-manager', 'CREATE', (int)db()->lastInsertId(), $file_name);
        flash_set('success', 'File <strong>' . h($file_name) . '</strong> created.');
        redirect(APP_URL . '/file-manager/index.php');
    }

    if ($uploaded_file) fm_delete_file($uploaded_file);
    save_old(compact('file_name','description','category','file_location','notes','status'));
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/file-manager/index.php">File Manager</a></li>
            <li class="breadcrumb-item active">New File</li>
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
            <div class="card mb-4" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-info-circle me-2 text-muted"></i>File Details</h6>
                </div>
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-medium">File Name <span class="text-danger">*</span></label>
                        <input type="text" name="file_name" class="form-control" value="<?= old('file_name') ?>"
                               required maxlength="255" placeholder="e.g. Budget Report 2024-Q1">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Description</label>
                        <textarea name="description" class="form-control" rows="3"
                                  placeholder="Brief description of this file…"><?= h(old('description','')) ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Category</label>
                        <input type="text" name="category" class="form-control" value="<?= old('category') ?>"
                               maxlength="100" placeholder="e.g. Finance, HR, Legal…">
                        <div class="form-text">Helps with filtering and organisation.</div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-medium">Physical / Cabinet Location</label>
                        <input type="text" name="file_location" class="form-control" value="<?= old('file_location') ?>"
                               maxlength="500" placeholder="e.g. Cabinet A3 – Shelf 2, Room 204">
                        <div class="form-text">Where the physical document is stored in the office.</div>
                    </div>
                </div>
            </div>

            <div class="card mb-4" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-upload me-2 text-muted"></i>Digital Copy <span class="text-muted fw-normal">(optional)</span></h6>
                </div>
                <div class="card-body p-4">
                    <input type="file" name="uploaded_file" class="form-control"
                           accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.txt,.jpg,.jpeg,.png,.gif,.webp">
                    <div class="form-text mt-1">Max 50 MB. Allowed: PDF, Word, Excel, PowerPoint, ZIP, TXT, images.</div>
                </div>
            </div>

            <div class="card" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-sticky-note me-2 text-muted"></i>Notes</h6>
                </div>
                <div class="card-body p-4">
                    <textarea name="notes" class="form-control" rows="4"
                              placeholder="Any additional notes about this file…"><?= h(old('notes','')) ?></textarea>
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
                            <option value="active"   <?= old('status','active') === 'active'   ? 'selected' : '' ?>>Active</option>
                            <option value="archived" <?= old('status','active') === 'archived' ? 'selected' : '' ?>>Archived</option>
                        </select>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i> Save File
                        </button>
                        <a href="<?= APP_URL ?>/file-manager/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
                    </div>
                </div>
            </div>
        </div>

    </div>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
