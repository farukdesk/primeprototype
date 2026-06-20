<?php
/**
 * Manage pages for a file.
 * GET  ?file_id=N  – list and add pages
 * POST             – handle add page
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('file-manager', 'can_create');
require_once __DIR__ . '/helpers.php';

$file_id = (int)($_GET['file_id'] ?? $_POST['file_id'] ?? 0);
if ($file_id < 1) { flash_set('error', 'Invalid file.'); redirect(APP_URL . '/file-manager/index.php'); }

$stmt = db()->prepare('SELECT * FROM file_manager_files WHERE id = ?');
$stmt->execute([$file_id]);
$file = $stmt->fetch();
if (!$file) { flash_set('error', 'File not found.'); redirect(APP_URL . '/file-manager/index.php'); }

if (!fm_can_view_file($file)) {
    flash_set('error', 'Access denied.'); redirect(APP_URL . '/file-manager/index.php');
}

$page_title = 'Pages – ' . h($file['file_name']);
$errors     = [];
$user       = auth_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $title              = trim($_POST['title']    ?? '');
    $category           = in_array($_POST['category'] ?? '', ['Document','Notes'], true) ? $_POST['category'] : 'Document';
    $subject            = trim($_POST['subject']  ?? '');
    $requires_signature = !empty($_POST['requires_signature']) ? 1 : 0;
    $page_number        = fm_next_page_number($file_id);

    if ($category === 'Notes' && $subject === '') {
        $errors[] = 'Subject is required for Notes pages.';
    }

    $stored_file   = null;
    $original_name = null;
    $mime_type     = null;
    $file_size     = null;
    if (!empty($_FILES['page_file']['name'])) {
        $result = fm_upload_file($_FILES['page_file']);
        if ($result === false) {
            $errors[] = 'File: invalid type or too large (max 50 MB).';
        } else {
            $stored_file   = $result;
            $original_name = $_FILES['page_file']['name'];
            $finfo         = new finfo(FILEINFO_MIME_TYPE);
            $mime_type     = $finfo->file(UPLOAD_DIR . '/' . FM_UPLOAD_SUBDIR . '/' . $result);
            $file_size     = (int)$_FILES['page_file']['size'];
        }
    }

    if (empty($errors)) {
        db()->prepare(
            'INSERT INTO file_manager_pages
               (file_id, page_number, title, category, subject,
                uploaded_file, original_name, mime_type, file_size,
                requires_signature, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $file_id, $page_number,
            $title        ?: null,
            $category,
            ($category === 'Notes' ? $subject : null),
            $stored_file, $original_name, $mime_type, $file_size,
            $requires_signature,
            $user['id'],
        ]);

        $new_page_id = (int)db()->lastInsertId();
        log_change('file-manager', 'CREATE', $file_id, "Page {$page_number} added to {$file['file_name']}");

        // If notes requires signature, notify existing tagged users to sign
        if ($category === 'Notes' && $requires_signature) {
            $new_page = db()->prepare('SELECT * FROM file_manager_pages WHERE id = ?');
            $new_page->execute([$new_page_id]);
            $new_page_data = $new_page->fetch();

            $tagged_stmt = db()->prepare(
                'SELECT u.id, u.full_name, u.email
                 FROM file_manager_tagged_users t
                 JOIN users u ON u.id = t.user_id
                 WHERE t.file_id = ?'
            );
            $tagged_stmt->execute([$file_id]);
            foreach ($tagged_stmt->fetchAll() as $tu) {
                fm_notify_sign_request($file, $new_page_data, $tu, $user);
            }
        }

        flash_set('success', "Page {$page_number} added successfully.");
        redirect(APP_URL . '/file-manager/view.php?id=' . $file_id);
    }

    if ($stored_file) fm_delete_file($stored_file);
}

$pages = fm_get_pages($file_id);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/file-manager/index.php">File Manager</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/file-manager/view.php?id=<?= $file_id ?>"><?= h($file['file_name']) ?></a></li>
            <li class="breadcrumb-item active">Add Page</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/file-manager/view.php?id=<?= $file_id ?>" class="btn btn-outline-secondary" style="border-radius:10px;">
        <i class="fas fa-arrow-left me-1"></i> Back to File
    </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold">
                    <i class="fas fa-plus-circle me-2 text-primary"></i>
                    Add Page <?= fm_next_page_number($file_id) ?>
                </h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" enctype="multipart/form-data" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="file_id" value="<?= $file_id ?>">

                    <div class="mb-3">
                        <label class="form-label fw-medium">Page Category <span class="text-danger">*</span></label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="category" id="cat_doc"
                                       value="Document" checked onchange="toggleSubject(this.value)">
                                <label class="form-check-label" for="cat_doc">
                                    <i class="fas fa-file-alt me-1 text-info"></i> Document
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="category" id="cat_note"
                                       value="Notes" onchange="toggleSubject(this.value)">
                                <label class="form-check-label" for="cat_note">
                                    <i class="fas fa-sticky-note me-1 text-warning"></i> Notes
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Page Title</label>
                        <input type="text" name="title" class="form-control" maxlength="255"
                               placeholder="e.g. Meeting Minutes, Approval Letter…">
                    </div>

                    <div class="mb-3" id="subjectRow" style="display:none;">
                        <label class="form-label fw-medium">Subject <span class="text-danger">*</span></label>
                        <input type="text" name="subject" class="form-control" maxlength="300"
                               placeholder="Subject of this note…">
                        <div class="form-text">Required for Notes pages.</div>
                    </div>

                    <div class="mb-3" id="sigRow" style="display:none;">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="requires_signature"
                                   id="requires_signature" value="1">
                            <label class="form-check-label" for="requires_signature">
                                This note requires signatures
                            </label>
                        </div>
                        <div class="form-text">You can place signer positions after saving via <strong>Manage Signers</strong>.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Upload File <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="file" name="page_file" class="form-control"
                               accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.gif,.webp,.txt">
                        <div class="form-text">Max 50 MB. Images and PDFs can be combined into the download.</div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                        <i class="fas fa-plus me-1"></i> Add Page
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold">
                    <i class="fas fa-layer-group me-2 text-info"></i>Existing Pages
                    <span class="badge bg-secondary ms-1"><?= count($pages) ?></span>
                </h6>
            </div>
            <?php if (empty($pages)): ?>
            <div class="card-body px-4 py-3 text-muted" style="font-size:.85rem;">No pages yet.</div>
            <?php else: ?>
            <ul class="list-group list-group-flush" style="border-radius:0 0 12px 12px;">
                <?php foreach ($pages as $pg): ?>
                <li class="list-group-item px-4 py-3 d-flex align-items-center gap-3">
                    <span class="badge bg-secondary" style="min-width:34px;">P<?= $pg['page_number'] ?></span>
                    <div class="flex-grow-1 overflow-hidden">
                        <div class="fw-medium" style="font-size:.88rem;"><?= h($pg['title'] ?: 'Page ' . $pg['page_number']) ?></div>
                        <?php if ($pg['subject']): ?>
                        <div class="text-muted" style="font-size:.77rem;"><?= h($pg['subject']) ?></div>
                        <?php endif; ?>
                    </div>
                    <span class="badge <?= $pg['category'] === 'Notes' ? 'bg-warning text-dark' : 'bg-info text-dark' ?>"><?= $pg['category'] ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function toggleSubject(val) {
    var subjectRow = document.getElementById('subjectRow');
    var sigRow     = document.getElementById('sigRow');
    if (val === 'Notes') {
        subjectRow.style.display = '';
        sigRow.style.display     = '';
    } else {
        subjectRow.style.display = 'none';
        sigRow.style.display     = 'none';
        document.getElementById('requires_signature').checked = false;
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
