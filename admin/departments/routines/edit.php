<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('dept-routines', 'can_edit');

$id      = (int)($_GET['id']      ?? 0);
$dept_id = (int)($_GET['dept_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM dept_routines WHERE id = ?');
$stmt->execute([$id]);
$routine = $stmt->fetch();
if (!$routine) { flash_set('error', 'Routine not found.'); redirect(APP_URL . '/departments/index.php'); }

$dept_id = $dept_id ?: (int)$routine['dept_id'];
$dept    = db()->prepare('SELECT * FROM dept_departments WHERE id = ?');
$dept->execute([$dept_id]);
$dept    = $dept->fetch();
if (!$dept) { flash_set('error', 'Department not found.'); redirect(APP_URL . '/departments/index.php'); }
require_access_dept($dept_id);

$page_title = 'Edit Routine – ' . $routine['title'];
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

    $file_path = $routine['file_path'];
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
            if ($routine['file_path']) {
                $old = UPLOAD_DIR . '/departments/' . $routine['file_path'];
                if (file_exists($old)) @unlink($old);
            }
            $file_path = $uploaded;
        }
    }

    if (empty($errors)) {
        db()->prepare(
            'UPDATE dept_routines SET
             title=?, type=?, file_path=?, semester=?, section=?, effective_from=?, is_active=?
             WHERE id=?'
        )->execute([$title, $type, $file_path, $semester ?: null,
                    $section ?: null, $effective_from ?: null, $is_active, $id]);

        flash_set('success', "Routine <strong>" . h($title) . "</strong> updated.");
        redirect(APP_URL . '/departments/routines/index.php?dept_id=' . $dept_id);
    }

    $routine = array_merge($routine, compact('title','type','semester','section','effective_from','is_active'));
    $routine['file_path'] = $file_path;
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
<div class="col-lg-7">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-edit me-2 text-muted"></i>Edit Routine</h6>
    </div>
    <div class="card-body p-4">
        <form method="POST" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="dept_id" value="<?= $dept_id ?>">

            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-medium">Title <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control" style="border-radius:10px;"
                           value="<?= h($routine['title']) ?>" required maxlength="300">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Type</label>
                    <select name="type" class="form-select" style="border-radius:10px;">
                        <option value="class" <?= $routine['type'] === 'class' ? 'selected' : '' ?>>Class Routine</option>
                        <option value="exam"  <?= $routine['type'] === 'exam'  ? 'selected' : '' ?>>Exam Routine</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-medium">Semester</label>
                    <input type="text" name="semester" class="form-control" style="border-radius:10px;"
                           value="<?= h($routine['semester'] ?? '') ?>" maxlength="100">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-medium">Section</label>
                    <input type="text" name="section" class="form-control" style="border-radius:10px;"
                           value="<?= h($routine['section'] ?? '') ?>" maxlength="100">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Effective From</label>
                    <input type="date" name="effective_from" class="form-control" style="border-radius:10px;"
                           value="<?= h($routine['effective_from'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">File (PDF/DOC/DOCX)</label>
                    <?php if ($routine['file_path']): ?>
                    <div class="mb-1">
                        <a href="<?= UPLOAD_URL ?>/departments/<?= h($routine['file_path']) ?>" target="_blank"
                           class="badge bg-info text-dark text-decoration-none">
                            <i class="fas fa-file me-1"></i>Current File
                        </a>
                    </div>
                    <?php endif; ?>
                    <input type="file" name="file_path" class="form-control" style="border-radius:10px;"
                           accept=".pdf,.doc,.docx">
                    <small class="text-muted">Leave blank to keep current file.</small>
                </div>
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                               <?= $routine['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Update Routine
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
