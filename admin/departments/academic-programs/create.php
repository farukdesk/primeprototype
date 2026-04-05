<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$dept_id = (int)($_GET['dept_id'] ?? $_POST['dept_id'] ?? 0);
if (!$dept_id) { flash_set('error', 'Invalid department.'); redirect(APP_URL . '/departments/index.php'); }

$dept = db()->prepare('SELECT * FROM dept_departments WHERE id = ?');
$dept->execute([$dept_id]);
$dept = $dept->fetch();
if (!$dept) { flash_set('error', 'Department not found.'); redirect(APP_URL . '/departments/index.php'); }

$page_title = 'Add Academic Program – ' . $dept['name'];
$errors = [];
clear_old();

function dept_prog_upload_file(array $file, string $subdir, array $allowed_exts, array $allowed_mimes): string|false {
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
    $semester_type   = trim($_POST['semester_type']   ?? '');
    $description     = trim($_POST['description']     ?? '');
    $details_content = trim($_POST['details_content'] ?? '');
    $sort_order      = (int)($_POST['sort_order']     ?? 0);
    $is_active       = isset($_POST['is_active'])     ? 1 : 0;

    $allowed_semester_types = ['trimester', 'semester', 'annual', ''];
    if (!in_array($semester_type, $allowed_semester_types, true)) $semester_type = '';

    if ($program_name === '') $errors[] = 'Program name is required.';

    $attachment = null;
    if (!empty($_FILES['attachment']['name'])) {
        $uploaded = dept_prog_upload_file(
            $_FILES['attachment'], 'departments',
            ['pdf','doc','docx'],
            ['application/pdf','application/msword',
             'application/vnd.openxmlformats-officedocument.wordprocessingml.document']
        );
        if ($uploaded === false) {
            $errors[] = 'Invalid attachment. Allowed: pdf, doc, docx.';
        } else {
            $attachment = $uploaded;
        }
    }

    if (empty($errors)) {
        db()->prepare(
            'INSERT INTO dept_academic_programs
             (dept_id, program_name, degree_type, duration, total_credit, semester_type, description, details_content, attachment, sort_order, is_active)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([$dept_id, $program_name, $degree_type ?: null, $duration ?: null,
                    $total_credit ?: null, $semester_type ?: null, $description ?: null,
                    $details_content ?: null, $attachment, $sort_order, $is_active]);

        flash_set('success', "Program <strong>" . h($program_name) . "</strong> added.");
        redirect(APP_URL . '/departments/academic-programs/index.php?dept_id=' . $dept_id);
    }

    save_old(compact('program_name','degree_type','duration','total_credit','semester_type','description','details_content','sort_order'));
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
            <li class="breadcrumb-item active">Add</li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="row justify-content-center">
<div class="col-lg-7">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-book-open me-2 text-muted"></i>Add Academic Program</h6>
    </div>
    <div class="card-body p-4">
        <form method="POST" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="dept_id" value="<?= $dept_id ?>">

            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-medium">Program Name <span class="text-danger">*</span></label>
                    <input type="text" name="program_name" class="form-control" style="border-radius:10px;"
                           value="<?= old('program_name') ?>" required maxlength="300">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Degree Type</label>
                    <input type="text" name="degree_type" class="form-control" style="border-radius:10px;"
                           value="<?= old('degree_type') ?>" maxlength="100" placeholder="e.g. B.Sc., M.Sc.">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Duration</label>
                    <input type="text" name="duration" class="form-control" style="border-radius:10px;"
                           value="<?= old('duration') ?>" maxlength="100" placeholder="e.g. 4 Years">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Total Credit</label>
                    <input type="text" name="total_credit" class="form-control" style="border-radius:10px;"
                           value="<?= old('total_credit') ?>" maxlength="50" placeholder="e.g. 136">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Semester Type</label>
                    <select name="semester_type" class="form-select" style="border-radius:10px;">
                        <option value="">— Select —</option>
                        <option value="trimester" <?= old('semester_type') === 'trimester' ? 'selected' : '' ?>>Trimester (Spring / Summer / Fall)</option>
                        <option value="semester"  <?= old('semester_type') === 'semester'  ? 'selected' : '' ?>>Semester (Spring / Fall)</option>
                        <option value="annual"    <?= old('semester_type') === 'annual'    ? 'selected' : '' ?>>Annual</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">Short Description</label>
                    <textarea name="description" class="form-control" style="border-radius:10px;" rows="3"><?= old('description') ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">Detailed Program Information</label>
                    <small class="text-muted d-block mb-2">Add admission requirements, fees structure, curriculum, and any other details students should know.</small>
                    <textarea name="details_content" id="details_content" class="form-control" style="border-radius:10px;" rows="10"><?= old('details_content') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Brochure / Attachment</label>
                    <input type="file" name="attachment" class="form-control" style="border-radius:10px;"
                           accept=".pdf,.doc,.docx">
                    <small class="text-muted">Downloadable file for students. Allowed: pdf, doc, docx</small>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" style="border-radius:10px;"
                           value="<?= old('sort_order', '0') ?>" min="0">
                </div>
                <div class="col-md-3 d-flex align-items-end pb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" checked>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Save Program
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
    height: 450,
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
