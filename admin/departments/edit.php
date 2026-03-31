<?php
require_once __DIR__ . '/../includes/auth.php';
require_super_admin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { flash_set('error', 'Invalid department.'); redirect(APP_URL . '/departments/index.php'); }

$stmt = db()->prepare(
    'SELECT d.*, o.id AS ov_id, o.vision, o.mission,
            o.head_name, o.head_designation, o.head_photo,
            o.head_edu_qualifications, o.head_message
     FROM dept_departments d
     LEFT JOIN dept_overview o ON o.dept_id = d.id
     WHERE d.id = ?'
);
$stmt->execute([$id]);
$dept = $stmt->fetch();

if (!$dept) { flash_set('error', 'Department not found.'); redirect(APP_URL . '/departments/index.php'); }

$page_title = 'Edit: ' . $dept['name'];
$errors = [];
clear_old();

// ── Upload helper ───────────────────────────────────────────────────────────
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

// ── Handle POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name              = trim($_POST['name']              ?? '');
    $slug              = trim($_POST['slug']              ?? '');
    $code              = trim($_POST['code']              ?? '');
    $faculty_label     = trim($_POST['faculty_label']     ?? '');
    $hero_title        = trim($_POST['hero_title']        ?? '');
    $hero_subtitle     = trim($_POST['hero_subtitle']     ?? '');
    $hero_description  = trim($_POST['hero_description']  ?? '');
    $hero_icon         = trim($_POST['hero_icon']         ?? 'fas fa-graduation-cap');
    $cta_url           = trim($_POST['cta_url']           ?? 'apply-now.html');
    $cta_text          = trim($_POST['cta_text']          ?? 'Apply Now');
    $cta_section_title = trim($_POST['cta_section_title'] ?? '');
    $cta_section_text  = trim($_POST['cta_section_text']  ?? '');
    $is_active         = isset($_POST['is_active']) ? 1 : 0;

    $vision                 = trim($_POST['vision']                 ?? '');
    $mission                = trim($_POST['mission']                ?? '');
    $head_name              = trim($_POST['head_name']              ?? '');
    $head_designation       = trim($_POST['head_designation']       ?? '');
    $head_edu_qualifications = trim($_POST['head_edu_qualifications'] ?? '');
    $head_message           = trim($_POST['head_message']           ?? '');

    $slug = preg_replace('/[^a-z0-9-]/', '-', strtolower($slug));
    $slug = preg_replace('/-+/', '-', trim($slug, '-'));

    if ($name === '')       $errors[] = 'Department name is required.';
    if ($slug === '')       $errors[] = 'Slug is required.';
    if ($hero_title === '') $errors[] = 'Hero title is required.';

    if (empty($errors)) {
        $dup = db()->prepare('SELECT id FROM dept_departments WHERE slug = ? AND id != ?');
        $dup->execute([$slug, $id]);
        if ($dup->fetch()) $errors[] = 'Another department already uses this slug.';
    }

    // Handle head photo upload
    $head_photo = $dept['head_photo'];
    if (!empty($_FILES['head_photo']['name'])) {
        $uploaded = dept_upload_file(
            $_FILES['head_photo'], 'departments',
            ['jpg','jpeg','png','gif','webp'],
            ['image/jpeg','image/png','image/gif','image/webp']
        );
        if ($uploaded === false) {
            $errors[] = 'Invalid head photo file. Allowed: jpg, jpeg, png, gif, webp.';
        } else {
            // Remove old photo
            if ($dept['head_photo']) {
                $old_path = UPLOAD_DIR . '/departments/' . $dept['head_photo'];
                if (file_exists($old_path)) @unlink($old_path);
            }
            $head_photo = $uploaded;
        }
    }

    if (empty($errors)) {
        db()->prepare(
            'UPDATE dept_departments SET
             name=?, slug=?, code=?, faculty_label=?, hero_title=?, hero_subtitle=?,
             hero_description=?, hero_icon=?, cta_url=?, cta_text=?,
             cta_section_title=?, cta_section_text=?, is_active=?
             WHERE id=?'
        )->execute([
            $name, $slug, $code, $faculty_label, $hero_title, $hero_subtitle,
            $hero_description,
            $hero_icon ?: 'fas fa-graduation-cap',
            $cta_url ?: 'apply-now.html',
            $cta_text ?: 'Apply Now',
            $cta_section_title ?: null,
            $cta_section_text  ?: null,
            $is_active, $id,
        ]);

        // UPSERT overview
        db()->prepare(
            'INSERT INTO dept_overview
             (dept_id, vision, mission, head_name, head_designation, head_photo, head_edu_qualifications, head_message)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
             vision=VALUES(vision), mission=VALUES(mission),
             head_name=VALUES(head_name), head_designation=VALUES(head_designation),
             head_photo=VALUES(head_photo),
             head_edu_qualifications=VALUES(head_edu_qualifications),
             head_message=VALUES(head_message)'
        )->execute([
            $id, $vision ?: null, $mission ?: null,
            $head_name ?: null, $head_designation ?: null, $head_photo ?: null,
            $head_edu_qualifications ?: null, $head_message ?: null,
        ]);

        flash_set('success', "Department <strong>" . h($name) . "</strong> updated.");
        redirect(APP_URL . '/departments/edit.php?id=' . $id);
    }

    // Restore for re-display
    $dept = array_merge($dept, compact(
        'name','slug','code','faculty_label','hero_title','hero_subtitle','hero_description',
        'hero_icon','cta_url','cta_text','cta_section_title','cta_section_text','is_active',
        'vision','mission','head_name','head_designation','head_edu_qualifications','head_message'
    ));
    $dept['head_photo'] = $head_photo;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/index.php">Departments</a></li>
            <li class="breadcrumb-item active">Edit: <?= h($dept['name']) ?></li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/departments/view.php?id=<?= $id ?>" class="btn btn-outline-info btn-sm" style="border-radius:10px;">
        <i class="fas fa-folder-open me-1"></i> Manage Sub-sections
    </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>

    <!-- Section 1: Department Info -->
    <div class="card mb-4">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-info-circle me-2 text-muted"></i>Department Information</h6>
        </div>
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Department Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="deptName" class="form-control" style="border-radius:10px;"
                           value="<?= h($dept['name']) ?>" required maxlength="200">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-medium">Slug <span class="text-danger">*</span></label>
                    <input type="text" name="slug" id="deptSlug" class="form-control" style="border-radius:10px;"
                           value="<?= h($dept['slug']) ?>" required maxlength="120">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-medium">Code</label>
                    <input type="text" name="code" class="form-control" style="border-radius:10px;"
                           value="<?= h($dept['code']) ?>" maxlength="50">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Faculty Label</label>
                    <input type="text" name="faculty_label" class="form-control" style="border-radius:10px;"
                           value="<?= h($dept['faculty_label']) ?>" maxlength="200">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Hero Icon</label>
                    <input type="text" name="hero_icon" class="form-control" style="border-radius:10px;"
                           value="<?= h($dept['hero_icon']) ?>" maxlength="100">
                    <small class="text-muted">Font Awesome class, e.g. <code>fas fa-laptop-code</code></small>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Hero Title <span class="text-danger">*</span></label>
                    <input type="text" name="hero_title" class="form-control" style="border-radius:10px;"
                           value="<?= h($dept['hero_title']) ?>" required maxlength="300">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Hero Subtitle</label>
                    <input type="text" name="hero_subtitle" class="form-control" style="border-radius:10px;"
                           value="<?= h($dept['hero_subtitle'] ?? '') ?>" maxlength="300">
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">Hero Description</label>
                    <textarea name="hero_description" class="form-control" style="border-radius:10px;" rows="3"><?= h($dept['hero_description'] ?? '') ?></textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">CTA Button URL</label>
                    <input type="text" name="cta_url" class="form-control" style="border-radius:10px;"
                           value="<?= h($dept['cta_url']) ?>" maxlength="300">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">CTA Button Text</label>
                    <input type="text" name="cta_text" class="form-control" style="border-radius:10px;"
                           value="<?= h($dept['cta_text']) ?>" maxlength="100">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">CTA Section Title</label>
                    <input type="text" name="cta_section_title" class="form-control" style="border-radius:10px;"
                           value="<?= h($dept['cta_section_title'] ?? '') ?>" maxlength="300">
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">CTA Section Text</label>
                    <textarea name="cta_section_text" class="form-control" style="border-radius:10px;" rows="3"><?= h($dept['cta_section_text'] ?? '') ?></textarea>
                </div>
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                               <?= $dept['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 2: Vision & Mission -->
    <div class="card mb-4">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-eye me-2 text-muted"></i>Vision &amp; Mission</h6>
        </div>
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Vision</label>
                    <textarea name="vision" class="form-control" style="border-radius:10px;" rows="5"><?= h($dept['vision'] ?? '') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Mission</label>
                    <textarea name="mission" class="form-control" style="border-radius:10px;" rows="5"><?= h($dept['mission'] ?? '') ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 3: Department Head -->
    <div class="card mb-4">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-user-tie me-2 text-muted"></i>Department Head</h6>
        </div>
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Head Name</label>
                    <input type="text" name="head_name" class="form-control" style="border-radius:10px;"
                           value="<?= h($dept['head_name'] ?? '') ?>" maxlength="200">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Head Designation</label>
                    <input type="text" name="head_designation" class="form-control" style="border-radius:10px;"
                           value="<?= h($dept['head_designation'] ?? '') ?>" maxlength="200">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Head Photo</label>
                    <?php if (!empty($dept['head_photo'])): ?>
                    <div class="mb-2">
                        <img src="<?= UPLOAD_URL ?>/departments/<?= h($dept['head_photo']) ?>"
                             alt="Head Photo" style="height:80px;border-radius:8px;object-fit:cover;">
                    </div>
                    <?php endif; ?>
                    <input type="file" name="head_photo" class="form-control" style="border-radius:10px;"
                           accept=".jpg,.jpeg,.png,.gif,.webp">
                    <small class="text-muted">Allowed: jpg, jpeg, png, gif, webp. Leave blank to keep current.</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Educational Qualifications</label>
                    <textarea name="head_edu_qualifications" class="form-control" style="border-radius:10px;" rows="4"><?= h($dept['head_edu_qualifications'] ?? '') ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">Head's Message</label>
                    <textarea name="head_message" class="form-control" style="border-radius:10px;" rows="6"><?= h($dept['head_message'] ?? '') ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
            <i class="fas fa-save me-1"></i> Update Department
        </button>
        <a href="<?= APP_URL ?>/departments/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
    </div>
</form>

<script>
(function () {
    const nameEl = document.getElementById('deptName');
    const slugEl = document.getElementById('deptSlug');
    nameEl.addEventListener('input', function () {
        slugEl.value = this.value
            .toLowerCase()
            .replace(/[^a-z0-9\s-]/g, '')
            .trim()
            .replace(/\s+/g, '-')
            .replace(/-+/g, '-');
    });
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
