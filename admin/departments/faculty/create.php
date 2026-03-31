<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_super_admin();

$dept_id = (int)($_GET['dept_id'] ?? $_POST['dept_id'] ?? 0);
if (!$dept_id) { flash_set('error', 'Invalid department.'); redirect(APP_URL . '/departments/index.php'); }

$dept = db()->prepare('SELECT * FROM dept_departments WHERE id = ?');
$dept->execute([$dept_id]);
$dept = $dept->fetch();
if (!$dept) { flash_set('error', 'Department not found.'); redirect(APP_URL . '/departments/index.php'); }

$page_title = 'Add Faculty – ' . $dept['name'];
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

    $name           = trim($_POST['name']           ?? '');
    $designation    = trim($_POST['designation']    ?? '');
    $specialization = trim($_POST['specialization'] ?? '');
    $email          = trim($_POST['email']          ?? '');
    $is_head        = isset($_POST['is_head'])   ? 1 : 0;
    $sort_order     = (int)($_POST['sort_order'] ?? 0);
    $is_active      = isset($_POST['is_active'])    ? 1 : 0;

    if ($name === '') $errors[] = 'Name is required.';

    $photo = null;
    if (!empty($_FILES['photo']['name'])) {
        $uploaded = dept_upload_file(
            $_FILES['photo'], 'departments',
            ['jpg','jpeg','png','gif','webp'],
            ['image/jpeg','image/png','image/gif','image/webp']
        );
        if ($uploaded === false) {
            $errors[] = 'Invalid photo. Allowed: jpg, jpeg, png, gif, webp.';
        } else {
            $photo = $uploaded;
        }
    }

    if (empty($errors)) {
        db()->prepare(
            'INSERT INTO dept_faculty
             (dept_id, name, designation, photo, specialization, email, is_head, sort_order, is_active)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([$dept_id, $name, $designation ?: null, $photo, $specialization ?: null,
                    $email ?: null, $is_head, $sort_order, $is_active]);

        flash_set('success', "Faculty member <strong>" . h($name) . "</strong> added.");
        redirect(APP_URL . '/departments/faculty/index.php?dept_id=' . $dept_id);
    }

    save_old(compact('name','designation','specialization','email','sort_order'));
}

require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/index.php">Departments</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/view.php?id=<?= $dept_id ?>"><?= h($dept['name']) ?></a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/faculty/index.php?dept_id=<?= $dept_id ?>">Faculty</a></li>
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
        <h6 class="mb-0 fw-semibold"><i class="fas fa-user-plus me-2 text-muted"></i>Add Faculty Member</h6>
    </div>
    <div class="card-body p-4">
        <form method="POST" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="dept_id" value="<?= $dept_id ?>">

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" style="border-radius:10px;"
                           value="<?= old('name') ?>" required maxlength="200">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Designation</label>
                    <input type="text" name="designation" class="form-control" style="border-radius:10px;"
                           value="<?= old('designation') ?>" maxlength="200">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Specialization</label>
                    <input type="text" name="specialization" class="form-control" style="border-radius:10px;"
                           value="<?= old('specialization') ?>" maxlength="300">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Email</label>
                    <input type="email" name="email" class="form-control" style="border-radius:10px;"
                           value="<?= old('email') ?>" maxlength="200">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Photo</label>
                    <input type="file" name="photo" class="form-control" style="border-radius:10px;"
                           accept=".jpg,.jpeg,.png,.gif,.webp">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" style="border-radius:10px;"
                           value="<?= old('sort_order', '0') ?>" min="0">
                </div>
                <div class="col-md-3 d-flex align-items-end gap-3 pb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_head" name="is_head" value="1">
                        <label class="form-check-label" for="is_head">Is Head</label>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" checked>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Save Faculty
                </button>
                <a href="<?= APP_URL ?>/departments/faculty/index.php?dept_id=<?= $dept_id ?>"
                   class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
