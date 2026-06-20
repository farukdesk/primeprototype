<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('dept-clubs', 'can_create');

$dept_id = (int)($_GET['dept_id'] ?? $_POST['dept_id'] ?? 0);
if (!$dept_id) { flash_set('error', 'Invalid department.'); redirect(APP_URL . '/departments/index.php'); }

$dept = db()->prepare('SELECT * FROM dept_departments WHERE id = ?');
$dept->execute([$dept_id]);
$dept = $dept->fetch();
if (!$dept) { flash_set('error', 'Department not found.'); redirect(APP_URL . '/departments/index.php'); }
require_access_dept($dept_id);

$page_title = 'Add Club – ' . $dept['name'];
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
    $description    = trim($_POST['description']    ?? '');
    $president_name = trim($_POST['president_name'] ?? '');
    $email          = trim($_POST['email']          ?? '');
    $sort_order     = (int)($_POST['sort_order']    ?? 0);
    $is_active      = isset($_POST['is_active'])    ? 1 : 0;

    if ($name === '') $errors[] = 'Club name is required.';

    $logo = null;
    if (!empty($_FILES['logo']['name'])) {
        $uploaded = dept_upload_file(
            $_FILES['logo'], 'departments',
            ['jpg','jpeg','png','gif','webp'],
            ['image/jpeg','image/png','image/gif','image/webp']
        );
        if ($uploaded === false) {
            $errors[] = 'Invalid logo. Allowed: jpg, jpeg, png, gif, webp.';
        } else {
            $logo = $uploaded;
        }
    }

    if (empty($errors)) {
        db()->prepare(
            'INSERT INTO dept_clubs
             (dept_id, name, description, logo, president_name, email, sort_order, is_active)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$dept_id, $name, $description ?: null, $logo,
                    $president_name ?: null, $email ?: null, $sort_order, $is_active]);

        flash_set('success', "Club <strong>" . h($name) . "</strong> added.");
        redirect(APP_URL . '/departments/clubs/index.php?dept_id=' . $dept_id);
    }

    save_old(compact('name','description','president_name','email','sort_order'));
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/index.php">Departments</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/view.php?id=<?= $dept_id ?>"><?= h($dept['name']) ?></a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/clubs/index.php?dept_id=<?= $dept_id ?>">Clubs</a></li>
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
        <h6 class="mb-0 fw-semibold"><i class="fas fa-users me-2 text-muted"></i>Add Club</h6>
    </div>
    <div class="card-body p-4">
        <form method="POST" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="dept_id" value="<?= $dept_id ?>">

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Club Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" style="border-radius:10px;"
                           value="<?= old('name') ?>" required maxlength="200">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">President Name</label>
                    <input type="text" name="president_name" class="form-control" style="border-radius:10px;"
                           value="<?= old('president_name') ?>" maxlength="200">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Email</label>
                    <input type="email" name="email" class="form-control" style="border-radius:10px;"
                           value="<?= old('email') ?>" maxlength="200">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Logo</label>
                    <input type="file" name="logo" class="form-control" style="border-radius:10px;"
                           accept=".jpg,.jpeg,.png,.gif,.webp">
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">Description</label>
                    <textarea name="description" class="form-control" style="border-radius:10px;" rows="4"><?= old('description') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" style="border-radius:10px;"
                           value="<?= old('sort_order', '0') ?>" min="0">
                </div>
                <div class="col-md-6 d-flex align-items-end pb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" checked>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Save Club
                </button>
                <a href="<?= APP_URL ?>/departments/clubs/index.php?dept_id=<?= $dept_id ?>"
                   class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
