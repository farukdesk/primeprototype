<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('alumni', 'can_create');

$page_title = 'Add Alumni';
$errors     = [];
clear_old();

$departments = db()->query('SELECT id, name FROM dept_departments WHERE is_active=1 ORDER BY name ASC')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $dept_id      = (int)($_POST['dept_id']      ?? 0);
    $student_id   = trim($_POST['student_id']    ?? '');
    $name         = trim($_POST['name']          ?? '');
    $batch        = trim($_POST['batch']         ?? '');
    $company      = trim($_POST['company']       ?? '');
    $position     = trim($_POST['position']      ?? '');
    $linkedin_url = trim($_POST['linkedin_url']  ?? '');
    $fb_url       = trim($_POST['fb_url']        ?? '');
    $status       = in_array($_POST['status'] ?? '', ['pending','approved','rejected']) ? $_POST['status'] : 'approved';
    $sort_order   = (int)($_POST['sort_order']   ?? 0);
    $is_active    = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') $errors[] = 'Name is required.';
    if ($linkedin_url !== '' && !filter_var($linkedin_url, FILTER_VALIDATE_URL))
        $errors[] = 'LinkedIn URL must be a valid URL.';
    if ($fb_url !== '' && !filter_var($fb_url, FILTER_VALIDATE_URL))
        $errors[] = 'Facebook URL must be a valid URL.';

    $photo = null;
    if (!empty($_FILES['photo']['name'])) {
        $f = $_FILES['photo'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Photo upload failed (code ' . $f['error'] . ').';
        } else {
            $ext  = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $exts = ['jpg','jpeg','png','gif','webp'];
            $mimes = ['image/jpeg','image/png','image/gif','image/webp'];
            if (!in_array($ext, $exts, true)) {
                $errors[] = 'Photo: unsupported format. Allowed: JPG, PNG, GIF, WebP.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($f['tmp_name']);
                if (!in_array($mime, $mimes, true)) {
                    $errors[] = 'Photo: MIME type not allowed.';
                } else {
                    $dir = UPLOAD_DIR . '/alumni';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $photo = bin2hex(random_bytes(12)) . '.' . $ext;
                    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $photo)) {
                        $errors[] = 'Failed to save photo. Check server permissions.';
                        $photo = null;
                    }
                }
            }
        }
    }

    if (empty($errors)) {
        db()->prepare(
            'INSERT INTO alumni (dept_id, student_id, name, batch, company, position, linkedin_url, fb_url, photo, status, sort_order, is_active)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $dept_id ?: null, $student_id ?: null, $name, $batch ?: null, $company ?: null,
            $position ?: null, $linkedin_url ?: null, $fb_url ?: null,
            $photo, $status, $sort_order, $is_active
        ]);
        flash_set('success', 'Alumni <strong>' . h($name) . '</strong> added successfully.');
        redirect(APP_URL . '/alumni/index.php?tab=' . $status);
    }

    save_old(compact('dept_id','student_id','name','batch','company','position','linkedin_url','fb_url','status','sort_order'));
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/alumni/index.php">Alumni</a></li>
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
<div class="col-lg-8">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-user-graduate me-2 text-muted"></i>Add Alumni</h6>
    </div>
    <div class="card-body p-4">
        <form method="POST" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" style="border-radius:10px;"
                           value="<?= old('name') ?>" required maxlength="200" placeholder="e.g. Md. Rahim Uddin">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Student ID</label>
                    <input type="text" name="student_id" class="form-control" style="border-radius:10px;"
                           value="<?= old('student_id') ?>" maxlength="50" placeholder="e.g. 201-15-2345">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Department</label>
                    <select name="dept_id" class="form-select" style="border-radius:10px;">
                        <option value="0">— Select Department —</option>
                        <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= old('dept_id') == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Batch</label>
                    <input type="text" name="batch" class="form-control" style="border-radius:10px;"
                           value="<?= old('batch') ?>" maxlength="100" placeholder="e.g. 26th or Spring 2018">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Current Company</label>
                    <input type="text" name="company" class="form-control" style="border-radius:10px;"
                           value="<?= old('company') ?>" maxlength="200" placeholder="e.g. BRAC Bank Limited">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Role / Position</label>
                    <input type="text" name="position" class="form-control" style="border-radius:10px;"
                           value="<?= old('position') ?>" maxlength="200" placeholder="e.g. Senior Software Engineer">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Photo</label>
                    <input type="file" name="photo" class="form-control" id="photoInput" style="border-radius:10px;"
                           accept=".jpg,.jpeg,.png,.gif,.webp">
                    <div class="form-text">JPG, PNG, GIF, WebP. Square 300×300 px recommended.</div>
                    <div id="photoPreviewWrap" class="mt-2" style="display:none;">
                        <img id="photoPreview" src="" alt="Preview"
                             style="width:100px;height:100px;border-radius:50%;object-fit:cover;border:3px solid #002147;">
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">LinkedIn URL</label>
                    <input type="url" name="linkedin_url" class="form-control" style="border-radius:10px;"
                           value="<?= old('linkedin_url') ?>" maxlength="500" placeholder="https://linkedin.com/in/…">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Facebook URL</label>
                    <input type="url" name="fb_url" class="form-control" style="border-radius:10px;"
                           value="<?= old('fb_url') ?>" maxlength="500" placeholder="https://facebook.com/…">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Status</label>
                    <select name="status" class="form-select" style="border-radius:10px;">
                        <option value="approved" <?= old('status','approved')==='approved'?'selected':'' ?>>Approved</option>
                        <option value="pending"  <?= old('status')==='pending'?'selected':'' ?>>Pending</option>
                        <option value="rejected" <?= old('status')==='rejected'?'selected':'' ?>>Rejected</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" style="border-radius:10px;"
                           value="<?= old('sort_order','0') ?>" min="0">
                </div>
                <div class="col-md-4 d-flex align-items-end pb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                               <?= old('is_active','1') ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Save Alumni
                </button>
                <a href="<?= APP_URL ?>/alumni/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<script>
document.getElementById('photoInput').addEventListener('change', function () {
    var wrap = document.getElementById('photoPreviewWrap');
    var img  = document.getElementById('photoPreview');
    if (this.files && this.files[0]) {
        var reader = new FileReader();
        reader.onload = function (e) { img.src = e.target.result; wrap.style.display = ''; };
        reader.readAsDataURL(this.files[0]);
    } else {
        wrap.style.display = 'none';
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
