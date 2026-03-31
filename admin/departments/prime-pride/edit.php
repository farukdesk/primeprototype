<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_super_admin();

$id      = (int)($_GET['id']      ?? 0);
$dept_id = (int)($_GET['dept_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM dept_prime_pride WHERE id = ?');
$stmt->execute([$id]);
$pp = $stmt->fetch();
if (!$pp) { flash_set('error', 'Entry not found.'); redirect(APP_URL . '/departments/index.php'); }

$dept_id = $dept_id ?: (int)$pp['dept_id'];
$dept    = db()->prepare('SELECT * FROM dept_departments WHERE id = ?');
$dept->execute([$dept_id]);
$dept    = $dept->fetch();
if (!$dept) { flash_set('error', 'Department not found.'); redirect(APP_URL . '/departments/index.php'); }

$page_title = 'Edit Prime Pride – ' . $pp['title'];
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

    $title        = trim($_POST['title']        ?? '');
    $description  = trim($_POST['description']  ?? '');
    $student_name = trim($_POST['student_name'] ?? '');
    $position     = trim($_POST['position']     ?? '');
    $batch_year   = trim($_POST['batch_year']   ?? '');
    $sort_order   = (int)($_POST['sort_order']  ?? 0);
    $is_active    = isset($_POST['is_active'])  ? 1 : 0;

    if ($title === '') $errors[] = 'Title is required.';

    $image = $pp['image'];
    if (!empty($_FILES['image']['name'])) {
        $uploaded = dept_upload_file(
            $_FILES['image'], 'departments',
            ['jpg','jpeg','png','gif','webp'],
            ['image/jpeg','image/png','image/gif','image/webp']
        );
        if ($uploaded === false) {
            $errors[] = 'Invalid image. Allowed: jpg, jpeg, png, gif, webp.';
        } else {
            if ($pp['image']) {
                $old = UPLOAD_DIR . '/departments/' . $pp['image'];
                if (file_exists($old)) @unlink($old);
            }
            $image = $uploaded;
        }
    }

    if (empty($errors)) {
        db()->prepare(
            'UPDATE dept_prime_pride SET
             title=?, description=?, image=?, student_name=?, position=?,
             batch_year=?, sort_order=?, is_active=?
             WHERE id=?'
        )->execute([$title, $description ?: null, $image,
                    $student_name ?: null, $position ?: null,
                    $batch_year ?: null, $sort_order, $is_active, $id]);

        flash_set('success', "Prime Pride entry <strong>" . h($title) . "</strong> updated.");
        redirect(APP_URL . '/departments/prime-pride/index.php?dept_id=' . $dept_id);
    }

    $pp = array_merge($pp, compact('title','description','student_name','position','batch_year','sort_order','is_active'));
    $pp['image'] = $image;
}

require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/index.php">Departments</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/view.php?id=<?= $dept_id ?>"><?= h($dept['name']) ?></a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/prime-pride/index.php?dept_id=<?= $dept_id ?>">Prime Pride</a></li>
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
        <h6 class="mb-0 fw-semibold"><i class="fas fa-star me-2 text-muted"></i>Edit Prime Pride Entry</h6>
    </div>
    <div class="card-body p-4">
        <form method="POST" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="dept_id" value="<?= $dept_id ?>">

            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-medium">Title <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control" style="border-radius:10px;"
                           value="<?= h($pp['title']) ?>" required maxlength="300">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Student Name</label>
                    <input type="text" name="student_name" class="form-control" style="border-radius:10px;"
                           value="<?= h($pp['student_name'] ?? '') ?>" maxlength="200">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Position / Achievement</label>
                    <input type="text" name="position" class="form-control" style="border-radius:10px;"
                           value="<?= h($pp['position'] ?? '') ?>" maxlength="200">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Batch Year</label>
                    <input type="text" name="batch_year" class="form-control" style="border-radius:10px;"
                           value="<?= h($pp['batch_year'] ?? '') ?>" maxlength="50">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Image</label>
                    <?php if ($pp['image']): ?>
                    <div class="mb-2">
                        <img src="<?= UPLOAD_URL ?>/departments/<?= h($pp['image']) ?>"
                             alt="" style="height:70px;border-radius:8px;object-fit:cover;">
                    </div>
                    <?php endif; ?>
                    <input type="file" name="image" class="form-control" style="border-radius:10px;"
                           accept=".jpg,.jpeg,.png,.gif,.webp">
                    <small class="text-muted">Leave blank to keep current image.</small>
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">Description</label>
                    <textarea name="description" class="form-control" style="border-radius:10px;" rows="4"><?= h($pp['description'] ?? '') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" style="border-radius:10px;"
                           value="<?= (int)$pp['sort_order'] ?>" min="0">
                </div>
                <div class="col-md-6 d-flex align-items-end pb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                               <?= $pp['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Update Entry
                </button>
                <a href="<?= APP_URL ?>/departments/prime-pride/index.php?dept_id=<?= $dept_id ?>"
                   class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
