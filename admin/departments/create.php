<?php
require_once __DIR__ . '/../includes/auth.php';
require_super_admin();

$page_title = 'Create Department';
$errors = [];
clear_old();

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

    // Sanitize slug
    $slug = preg_replace('/[^a-z0-9-]/', '-', strtolower($slug));
    $slug = preg_replace('/-+/', '-', trim($slug, '-'));

    if ($name === '')       $errors[] = 'Department name is required.';
    if ($slug === '')       $errors[] = 'Slug is required.';
    if ($hero_title === '') $errors[] = 'Hero title is required.';

    // Handle card image upload
    $image = null;
    if (!empty($_FILES['image']['name'])) {
        $ext   = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        $mime  = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['image']['tmp_name']);
        $okExt  = ['jpg','jpeg','png','gif','webp'];
        $okMime = ['image/jpeg','image/png','image/gif','image/webp'];
        if ($_FILES['image']['error'] !== UPLOAD_ERR_OK || !in_array($ext, $okExt, true) || !in_array($mime, $okMime, true)) {
            $errors[] = 'Invalid card image. Allowed: jpg, jpeg, png, gif, webp.';
        } else {
            $dir = UPLOAD_DIR . '/departments';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fname = bin2hex(random_bytes(12)) . '.' . $ext;
            if (!move_uploaded_file($_FILES['image']['tmp_name'], $dir . '/' . $fname)) {
                $errors[] = 'Failed to save card image.';
            } else {
                $image = $fname;
            }
        }
    }

    if (empty($errors)) {
        $dup = db()->prepare('SELECT id FROM dept_departments WHERE slug = ?');
        $dup->execute([$slug]);
        if ($dup->fetch()) $errors[] = 'A department with this slug already exists.';
    }

    if (empty($errors)) {
        $pdo = db();
        $pdo->prepare(
            'INSERT INTO dept_departments
             (name, slug, code, faculty_label, hero_title, hero_subtitle, hero_description,
              hero_icon, image, cta_url, cta_text, cta_section_title, cta_section_text, is_active)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $name, $slug, $code, $faculty_label, $hero_title, $hero_subtitle, $hero_description,
            $hero_icon ?: 'fas fa-graduation-cap',
            $image,
            $cta_url ?: 'apply-now.html',
            $cta_text ?: 'Apply Now',
            $cta_section_title ?: null,
            $cta_section_text  ?: null,
            $is_active,
        ]);
        $dept_id = (int)$pdo->lastInsertId();

        // Create empty overview record
        $pdo->prepare('INSERT INTO dept_overview (dept_id) VALUES (?)')->execute([$dept_id]);

        flash_set('success', "Department <strong>" . h($name) . "</strong> created.");
        redirect(APP_URL . '/departments/index.php');
    }

    // Delete uploaded image if there were other validation errors
    if ($image) {
        @unlink(UPLOAD_DIR . '/departments/' . $image);
        $image = null;
    }

    save_old(compact(
        'name','slug','code','faculty_label','hero_title','hero_subtitle','hero_description',
        'hero_icon','cta_url','cta_text','cta_section_title','cta_section_text'
    ));
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/index.php">Departments</a></li>
            <li class="breadcrumb-item active">Create</li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>

    <!-- Department Info -->
    <div class="card mb-4">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-info-circle me-2 text-muted"></i>Department Information</h6>
        </div>
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Department Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="deptName" class="form-control" style="border-radius:10px;"
                           value="<?= old('name') ?>" required maxlength="200">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-medium">Slug <span class="text-danger">*</span></label>
                    <input type="text" name="slug" id="deptSlug" class="form-control" style="border-radius:10px;"
                           value="<?= old('slug') ?>" required maxlength="120"
                           placeholder="auto-generated">
                    <small class="text-muted">Used in URLs. Auto-generated from name.</small>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-medium">Code</label>
                    <input type="text" name="code" class="form-control" style="border-radius:10px;"
                           value="<?= old('code') ?>" maxlength="50" placeholder="e.g. CSE">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Faculty Label</label>
                    <input type="text" name="faculty_label" class="form-control" style="border-radius:10px;"
                           value="<?= old('faculty_label', 'Faculty of Science & Engineering') ?>" maxlength="200">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Hero Icon</label>
                    <input type="text" name="hero_icon" class="form-control" style="border-radius:10px;"
                           value="<?= old('hero_icon', 'fas fa-graduation-cap') ?>" maxlength="100"
                           placeholder="fas fa-graduation-cap">
                    <small class="text-muted">Font Awesome class, e.g. <code>fas fa-laptop-code</code></small>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Card Background Image</label>
                    <input type="file" name="image" class="form-control" style="border-radius:10px;"
                           accept=".jpg,.jpeg,.png,.gif,.webp">
                    <small class="text-muted">Displayed as the department card background on the homepage. Allowed: jpg, jpeg, png, gif, webp.</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Hero Section -->
    <div class="card mb-4">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-image me-2 text-muted"></i>Hero Section</h6>
        </div>
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Hero Title <span class="text-danger">*</span></label>
                    <input type="text" name="hero_title" class="form-control" style="border-radius:10px;"
                           value="<?= old('hero_title') ?>" required maxlength="300">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Hero Subtitle</label>
                    <input type="text" name="hero_subtitle" class="form-control" style="border-radius:10px;"
                           value="<?= old('hero_subtitle') ?>" maxlength="300">
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">Hero Description</label>
                    <textarea name="hero_description" class="form-control" style="border-radius:10px;" rows="3"><?= old('hero_description') ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <!-- CTA Section -->
    <div class="card mb-4">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-hand-pointer me-2 text-muted"></i>Call to Action</h6>
        </div>
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">CTA Button URL</label>
                    <input type="text" name="cta_url" class="form-control" style="border-radius:10px;"
                           value="<?= old('cta_url', 'apply-now.html') ?>" maxlength="300">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">CTA Button Text</label>
                    <input type="text" name="cta_text" class="form-control" style="border-radius:10px;"
                           value="<?= old('cta_text', 'Apply Now') ?>" maxlength="100">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">CTA Section Title</label>
                    <input type="text" name="cta_section_title" class="form-control" style="border-radius:10px;"
                           value="<?= old('cta_section_title') ?>" maxlength="300">
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">CTA Section Text</label>
                    <textarea name="cta_section_text" class="form-control" style="border-radius:10px;" rows="3"><?= old('cta_section_text') ?></textarea>
                </div>
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" checked>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
            <i class="fas fa-save me-1"></i> Create Department
        </button>
        <a href="<?= APP_URL ?>/departments/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
    </div>
</form>

<script>
(function () {
    const nameEl = document.getElementById('deptName');
    const slugEl = document.getElementById('deptSlug');
    let slugEdited = slugEl.value.length > 0;

    slugEl.addEventListener('input', () => { slugEdited = true; });

    nameEl.addEventListener('input', function () {
        if (slugEdited) return;
        slugEl.value = this.value
            .toLowerCase()
            .replace(/[^a-z0-9\s-]/g, '')
            .trim()
            .replace(/\s+/g, '-')
            .replace(/-+/g, '-');
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
