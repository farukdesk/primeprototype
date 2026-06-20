<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('dept-routines', 'can_create');

$dept_id = (int)($_GET['dept_id'] ?? $_POST['dept_id'] ?? 0);
if (!$dept_id) { flash_set('error', 'Invalid department.'); redirect(APP_URL . '/departments/index.php'); }

$dept = db()->prepare('SELECT * FROM dept_departments WHERE id = ?');
$dept->execute([$dept_id]);
$dept = $dept->fetch();
if (!$dept) { flash_set('error', 'Department not found.'); redirect(APP_URL . '/departments/index.php'); }
require_access_dept($dept_id);

$page_title = 'Add Routine – ' . $dept['name'];
$errors = [];
clear_old();

function dept_upload_file(array $file, string $subdir, array $allowed_exts, array $allowed_mimes): string|false {
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

    $title          = trim($_POST['title']          ?? '');
    $type           = trim($_POST['type']           ?? 'class');
    $semester       = trim($_POST['semester']       ?? '');
    $section        = trim($_POST['section']        ?? '');
    $effective_from = trim($_POST['effective_from'] ?? '');
    $is_active      = isset($_POST['is_active']) ? 1 : 0;

    if ($title === '') $errors[] = 'Title is required.';
    if (!in_array($type, ['class','exam'], true)) $type = 'class';

    $file_path = null;
    if (!empty($_FILES['file_path']['name'])) {
        $uploaded = dept_upload_file(
            $_FILES['file_path'], 'departments',
            ['pdf','doc','docx'],
            ['application/pdf','application/msword',
             'application/vnd.openxmlformats-officedocument.wordprocessingml.document']
        );
        if ($uploaded === false) {
            $errors[] = 'Invalid file. Allowed: pdf, doc, docx.';
        } else {
            $file_path = $uploaded;
        }
    }

    if (empty($errors)) {
        db()->prepare(
            'INSERT INTO dept_routines
             (dept_id, title, type, file_path, semester, section, effective_from, is_active)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$dept_id, $title, $type, $file_path, $semester ?: null,
                    $section ?: null, $effective_from ?: null, $is_active]);

        flash_set('success', "Routine <strong>" . h($title) . "</strong> added.");
        redirect(APP_URL . '/departments/routines/index.php?dept_id=' . $dept_id);
    }

    save_old(compact('title','type','semester','section','effective_from'));
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/index.php">Departments</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/view.php?id=<?= $dept_id ?>"><?= h($dept['name']) ?></a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/routines/index.php?dept_id=<?= $dept_id ?>">Routines</a></li>
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
        <h6 class="mb-0 fw-semibold"><i class="fas fa-clock me-2 text-muted"></i>Add Routine</h6>
    </div>
    <div class="card-body p-4">
        <form method="POST" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="dept_id" value="<?= $dept_id ?>">

            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-medium">Title <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control" style="border-radius:10px;"
                           value="<?= old('title') ?>" required maxlength="300">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Type</label>
                    <select name="type" class="form-select" style="border-radius:10px;">
                        <option value="class"  <?= old('type','class') === 'class'  ? 'selected' : '' ?>>Class Routine</option>
                        <option value="exam"   <?= old('type','class') === 'exam'   ? 'selected' : '' ?>>Exam Routine</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-medium">Semester</label>
                    <input type="text" name="semester" class="form-control" style="border-radius:10px;"
                           value="<?= old('semester') ?>" maxlength="100" placeholder="e.g. Spring 2025">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-medium">Section</label>
                    <input type="text" name="section" class="form-control" style="border-radius:10px;"
                           value="<?= old('section') ?>" maxlength="100">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Effective From</label>
                    <input type="date" name="effective_from" class="form-control" style="border-radius:10px;"
                           value="<?= old('effective_from') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">File (PDF/DOC/DOCX)</label>
                    <input type="file" name="file_path" class="form-control" style="border-radius:10px;"
                           accept=".pdf,.doc,.docx">
                </div>
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" checked>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Save Routine
                </button>
                <a href="<?= APP_URL ?>/departments/routines/index.php?dept_id=<?= $dept_id ?>"
                   class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
