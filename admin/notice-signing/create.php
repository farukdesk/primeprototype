<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('notice-signing', 'can_create');
require_once __DIR__ . '/helpers.php';

$page_title = 'New Notice';
$errors     = [];
clear_old();

// Fetch file manager files for linking
$fm_files = db()->query(
    "SELECT id, file_name, category FROM file_manager_files WHERE status = 'active' ORDER BY file_name ASC"
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $title       = trim($_POST['title']       ?? '');
    $description = trim($_POST['description'] ?? '');
    $status      = in_array($_POST['status'] ?? '', ['draft','active'], true) ? $_POST['status'] : 'draft';
    $fm_file_id  = (int)($_POST['fm_file_id'] ?? 0) ?: null;

    if ($title === '')           $errors[] = 'Title is required.';
    if (mb_strlen($title) > 255) $errors[] = 'Title must be 255 characters or less.';

    $doc_file     = null;
    $doc_original = null;
    $doc_type     = 'pdf';

    if (empty($_FILES['document_file']['name'])) {
        $errors[] = 'Notice document (PDF or image) is required.';
    } else {
        $result = ns_upload_document($_FILES['document_file']);
        if ($result === false) {
            $errors[] = 'Document: invalid file or too large (max 20 MB). Allowed: PDF, JPG, PNG, GIF, WebP.';
        } else {
            $doc_file     = $result;
            $doc_original = $_FILES['document_file']['name'];
            $doc_type     = ns_doc_type_from_file($result);
        }
    }

    if (empty($errors)) {
        $user = auth_user();
        db()->prepare(
            'INSERT INTO notice_documents (title, description, document_file, original_name, document_type, created_by, status, fm_file_id)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$title, $description ?: null, $doc_file, $doc_original, $doc_type, $user['id'], $status, $fm_file_id]);

        $new_id = (int)db()->lastInsertId();
        log_change('notice-signing', 'CREATE', $new_id, $title);
        flash_set('success', 'Notice <strong>' . h($title) . '</strong> created. Now add signers and their positions.');
        redirect(APP_URL . '/notice-signing/map-signers.php?id=' . $new_id);
    }

    if ($doc_file) ns_delete_document($doc_file);
    save_old(compact('title','description','status','fm_file_id'));
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/notice-signing/index.php">Notice Signing</a></li>
            <li class="breadcrumb-item active">New Notice</li>
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
        <div class="col-lg-8">
            <div class="card mb-4" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-info-circle me-2 text-muted"></i>Notice Details</h6>
                </div>
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control form-control-lg"
                               value="<?= old('title') ?>" required maxlength="255"
                               placeholder="e.g. Board Meeting Minutes – Approval Required">
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-medium">Description</label>
                        <textarea name="description" class="form-control" rows="3"
                                  placeholder="Brief description or instructions for signers…"><?= h(old('description','')) ?></textarea>
                    </div>
                </div>
            </div>

            <div class="card" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-upload me-2 text-muted"></i>Notice Document <span class="text-danger">*</span></h6>
                </div>
                <div class="card-body p-4">
                    <input type="file" name="document_file" class="form-control" required
                           accept=".pdf,.jpg,.jpeg,.png,.gif,.webp"
                           onchange="previewDoc(this)">
                    <div class="form-text mt-1">Max 20 MB. Accepted: PDF, JPG, PNG, GIF, WebP.</div>
                    <div id="docPreview" class="mt-3" style="display:none;">
                        <img id="docImg" src="" alt="Preview" style="max-width:100%;max-height:300px;border-radius:8px;">
                        <div id="docPdfNote" class="p-3 bg-light rounded-3 text-muted" style="display:none;">
                            <i class="fas fa-file-pdf text-danger me-2"></i> PDF selected. Preview available after upload.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-cog me-2 text-muted"></i>Settings</h6>
                </div>
                <div class="card-body p-4">
                    <div class="mb-4">
                        <label class="form-label fw-medium">Link to File Manager</label>
                        <select name="fm_file_id" class="form-select">
                            <option value="">— None —</option>
                            <?php foreach ($fm_files as $fmf): ?>
                            <option value="<?= $fmf['id'] ?>"
                                <?= (int)(old('fm_file_id', 0)) === (int)$fmf['id'] ? 'selected' : '' ?>>
                                <?= h($fmf['file_name']) ?><?= $fmf['category'] ? ' [' . h($fmf['category']) . ']' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Optionally link this notice to a file in the File Manager.</div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-medium">Initial Status</label>
                        <div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="status" id="st_draft" value="draft"
                                       <?= old('status','draft') === 'draft' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="st_draft">
                                    <strong>Draft</strong> – save first, activate later
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="status" id="st_active" value="active"
                                       <?= old('status','draft') === 'active' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="st_active">
                                    <strong>Active</strong> – signers can sign immediately
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="alert alert-info py-2 px-3" style="border-radius:8px;font-size:.83rem;">
                        <i class="fas fa-info-circle me-1"></i>
                        After saving, you will be taken to the signer mapping screen.
                    </div>
                    <div class="d-grid gap-2 mt-3">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-arrow-right me-1"></i> Save &amp; Map Signers
                        </button>
                        <a href="<?= APP_URL ?>/notice-signing/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
function previewDoc(input) {
    var preview = document.getElementById('docPreview');
    var img     = document.getElementById('docImg');
    var pdfNote = document.getElementById('docPdfNote');
    var file    = input.files[0];
    if (!file) { preview.style.display = 'none'; return; }

    preview.style.display = '';
    if (file.type === 'application/pdf') {
        img.style.display     = 'none';
        pdfNote.style.display = '';
    } else {
        pdfNote.style.display = 'none';
        img.style.display     = '';
        var reader = new FileReader();
        reader.onload = function (e) { img.src = e.target.result; };
        reader.readAsDataURL(file);
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
