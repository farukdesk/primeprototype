<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$id      = (int)($_GET['id']      ?? 0);
$dept_id = (int)($_GET['dept_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM dept_academic_programs WHERE id = ?');
$stmt->execute([$id]);
$program = $stmt->fetch();
if (!$program) { flash_set('error', 'Program not found.'); redirect(APP_URL . '/departments/index.php'); }

$dept_id = $dept_id ?: (int)$program['dept_id'];
$dept    = db()->prepare('SELECT * FROM dept_departments WHERE id = ?');
$dept->execute([$dept_id]);
$dept    = $dept->fetch();
if (!$dept) { flash_set('error', 'Department not found.'); redirect(APP_URL . '/departments/index.php'); }

$page_title = 'Edit Program – ' . $program['program_name'];
$errors = [];
clear_old();

function dept_prog_upload_file_edit(array $file, string $subdir, array $allowed_exts, array $allowed_mimes): string|false {
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_exts, true)) return false;
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed_mimes, true)) return false;
    $dir = UPLOAD_DIR . '/' . $subdir;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return false;
    return $name;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $program_name    = trim($_POST['program_name']    ?? '');
    $degree_type     = trim($_POST['degree_type']     ?? '');
    $duration        = trim($_POST['duration']        ?? '');
    $total_credit    = trim($_POST['total_credit']    ?? '');
    $description     = trim($_POST['description']     ?? '');
    $details_content = trim($_POST['details_content'] ?? '');
    $sort_order      = (int)($_POST['sort_order']     ?? 0);
    $is_active       = isset($_POST['is_active'])     ? 1 : 0;

    if ($program_name === '') $errors[] = 'Program name is required.';

    $attachment = $program['attachment'] ?? null;
    if (!empty($_FILES['attachment']['name'])) {
        $uploaded = dept_prog_upload_file_edit(
            $_FILES['attachment'], 'departments',
            ['pdf','doc','docx'],
            ['application/pdf','application/msword',
             'application/vnd.openxmlformats-officedocument.wordprocessingml.document']
        );
        if ($uploaded === false) {
            $errors[] = 'Invalid attachment. Allowed: pdf, doc, docx.';
        } else {
            if (!empty($program['attachment'])) {
                $old = UPLOAD_DIR . '/departments/' . $program['attachment'];
                if (file_exists($old)) @unlink($old);
            }
            $attachment = $uploaded;
        }
    } elseif (isset($_POST['remove_attachment']) && $_POST['remove_attachment'] === '1') {
        if (!empty($program['attachment'])) {
            $old = UPLOAD_DIR . '/departments/' . $program['attachment'];
            if (file_exists($old)) @unlink($old);
        }
        $attachment = null;
    }

    if (empty($errors)) {
        db()->prepare(
            'UPDATE dept_academic_programs SET
             program_name=?, degree_type=?, duration=?, total_credit=?,
             description=?, details_content=?, attachment=?, sort_order=?, is_active=?
             WHERE id=?'
        )->execute([$program_name, $degree_type ?: null, $duration ?: null,
                    $total_credit ?: null, $description ?: null, $details_content ?: null,
                    $attachment, $sort_order, $is_active, $id]);

        flash_set('success', "Program <strong>" . h($program_name) . "</strong> updated.");
        redirect(APP_URL . '/departments/academic-programs/index.php?dept_id=' . $dept_id);
    }

    $program = array_merge($program, compact(
        'program_name','degree_type','duration','total_credit','description','details_content','sort_order','is_active'
    ));
    $program['attachment'] = $attachment;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/index.php">Departments</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/view.php?id=<?= $dept_id ?>"><?= h($dept['name']) ?></a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/academic-programs/index.php?dept_id=<?= $dept_id ?>">Academic Programs</a></li>
            <li class="breadcrumb-item active">Edit</li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="row justify-content-center">
<div class="col-lg-10">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-edit me-2 text-muted"></i>Edit Program</h6>
    </div>
    <div class="card-body p-4">
        <form method="POST" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="dept_id" value="<?= $dept_id ?>">

            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-medium">Program Name <span class="text-danger">*</span></label>
                    <input type="text" name="program_name" class="form-control" style="border-radius:10px;"
                           value="<?= h($program['program_name']) ?>" required maxlength="300">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Degree Type</label>
                    <input type="text" name="degree_type" class="form-control" style="border-radius:10px;"
                           value="<?= h($program['degree_type'] ?? '') ?>" maxlength="100">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Duration</label>
                    <input type="text" name="duration" class="form-control" style="border-radius:10px;"
                           value="<?= h($program['duration'] ?? '') ?>" maxlength="100">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Total Credit</label>
                    <input type="text" name="total_credit" class="form-control" style="border-radius:10px;"
                           value="<?= h($program['total_credit'] ?? '') ?>" maxlength="50">
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">Short Description</label>
                    <textarea name="description" class="form-control" style="border-radius:10px;" rows="3"><?= h($program['description'] ?? '') ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">Detailed Program Information</label>
                    <small class="text-muted d-block mb-2">Add admission requirements, fees structure, curriculum, and any other details students should know.</small>
                    <textarea name="details_content" id="details_content" class="form-control" style="border-radius:10px;" rows="12"><?= h($program['details_content'] ?? '') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Brochure / Attachment</label>
                    <?php if (!empty($program['attachment'])): ?>
                    <div class="mb-2 d-flex align-items-center gap-2">
                        <span class="badge bg-info text-dark">
                            <i class="fas fa-paperclip me-1"></i><?= h($program['attachment']) ?>
                        </span>
                        <a href="<?= UPLOAD_URL ?>/departments/<?= h($program['attachment']) ?>"
                           target="_blank" class="btn btn-sm btn-outline-secondary" style="border-radius:7px;" title="Preview">
                            <i class="fas fa-eye"></i>
                        </a>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="remove_attachment" name="remove_attachment" value="1">
                        <label class="form-check-label text-danger" for="remove_attachment">Remove current attachment</label>
                    </div>
                    <?php endif; ?>
                    <input type="file" name="attachment" class="form-control" style="border-radius:10px;"
                           accept=".pdf,.doc,.docx">
                    <small class="text-muted">Upload new file to replace. Allowed: pdf, doc, docx</small>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" style="border-radius:10px;"
                           value="<?= (int)$program['sort_order'] ?>" min="0">
                </div>
                <div class="col-md-3 d-flex align-items-end pb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                               <?= $program['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Update Program
                </button>
                <a href="<?= APP_URL ?>/departments/academic-programs/index.php?dept_id=<?= $dept_id ?>"
                   class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tinymce@5.10.9/tinymce.min.js" referrerpolicy="origin"></script>
<script>
tinymce.init({
    selector: '#details_content',
    height: 500,
    menubar: false,
    plugins: 'advlist autolink lists link image charmap preview anchor ' +
             'searchreplace visualblocks code fullscreen table help wordcount',
    toolbar: 'undo redo | blocks | bold italic underline strikethrough | ' +
             'alignleft aligncenter alignright | bullist numlist outdent indent | ' +
             'table | removeformat | link | code fullscreen',
    content_style: 'body { font-family: Inter, sans-serif; font-size: 15px; }',
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
