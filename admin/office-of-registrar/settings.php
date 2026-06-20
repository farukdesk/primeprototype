<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('office-of-registrar', 'can_edit');

$page_title = 'Office of Registrar – Settings';
$errors     = [];
$requested_tab = $_GET['tab'] ?? '';
$active_tab = in_array($requested_tab, ['page','registrar','message']) ? $requested_tab : 'page';

$s = [];
try {
    $rows = db()->query('SELECT setting_key, setting_val FROM reg_settings')->fetchAll();
    foreach ($rows as $r) $s[$r['setting_key']] = $r['setting_val'];
} catch (Throwable $e) {}

function reg_upload_photo(array $file): string|false
{
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    $allowed_exts  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_exts, true)) return false;
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, $allowed_mimes, true)) return false;
    $dir = UPLOAD_DIR . '/office-of-registrar';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return false;
    return $name;
}

function reg_save(string $key, string $val): void
{
    db()->prepare(
        'INSERT INTO reg_settings (setting_key, setting_val) VALUES (?,?)
         ON DUPLICATE KEY UPDATE setting_val=VALUES(setting_val)'
    )->execute([$key, $val]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $tab = $_POST['tab'] ?? 'page';

    if ($tab === 'page') {
        $hero_title    = trim($_POST['hero_title']    ?? '');
        $hero_subtitle = trim($_POST['hero_subtitle'] ?? '');
        $hero_intro    = trim($_POST['hero_intro']    ?? '');
        $meta_desc     = trim($_POST['meta_desc']     ?? '');
        $is_published  = isset($_POST['is_published']) ? '1' : '0';
        if ($hero_title === '') $errors[] = 'Hero title is required.';
        if (empty($errors)) {
            reg_save('hero_title', $hero_title); reg_save('hero_subtitle', $hero_subtitle);
            reg_save('hero_intro', $hero_intro); reg_save('meta_description', $meta_desc);
            reg_save('is_published', $is_published);
            flash_set('success', 'Page settings saved.');
            redirect(APP_URL . '/office-of-registrar/settings.php?tab=page');
        }

    } elseif ($tab === 'registrar') {
        $reg_name    = trim($_POST['reg_name']    ?? '');
        $reg_title   = trim($_POST['reg_title']   ?? '');
        $reg_email_1 = trim($_POST['reg_email_1'] ?? '');
        $reg_email_2 = trim($_POST['reg_email_2'] ?? '');
        $reg_phone   = trim($_POST['reg_phone']   ?? '');
        $reg_bio     = trim($_POST['reg_bio']     ?? '');
        if ($reg_name === '') $errors[] = 'Registrar name is required.';
        $reg_photo = $s['reg_photo'] ?? '';
        if (!empty($_FILES['reg_photo']['name'])) {
            $result = reg_upload_photo($_FILES['reg_photo']);
            if ($result === false) { $errors[] = 'Photo: invalid file. Allowed: JPG, PNG, GIF, WebP.'; }
            else { if ($reg_photo) { $old = UPLOAD_DIR . '/office-of-registrar/' . $reg_photo; if (is_file($old)) @unlink($old); } $reg_photo = $result; }
        }
        if (empty($errors)) {
            reg_save('reg_name', $reg_name); reg_save('reg_title', $reg_title);
            reg_save('reg_email_1', $reg_email_1); reg_save('reg_email_2', $reg_email_2);
            reg_save('reg_phone', $reg_phone); reg_save('reg_bio', $reg_bio);
            reg_save('reg_photo', $reg_photo);
            flash_set('success', 'Registrar profile saved.');
            redirect(APP_URL . '/office-of-registrar/settings.php?tab=registrar');
        }

    } elseif ($tab === 'message') {
        $message_title = trim($_POST['message_title'] ?? '');
        $message_body  = trim($_POST['message_body']  ?? '');
        if ($message_title === '') $errors[] = 'Message title is required.';
        if (empty($errors)) {
            reg_save('message_title', $message_title); reg_save('message_body', $message_body);
            flash_set('success', 'Registrar message saved.');
            redirect(APP_URL . '/office-of-registrar/settings.php?tab=message');
        }
    }

    $active_tab = $tab;
    try {
        $rows = db()->query('SELECT setting_key, setting_val FROM reg_settings')->fetchAll();
        foreach ($rows as $r) $s[$r['setting_key']] = $r['setting_val'];
    } catch (Throwable $e) {}
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/office-of-registrar/index.php">Office of Registrar</a></li>
            <li class="breadcrumb-item active">Settings</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/office-of-registrar/index.php" class="btn btn-sm btn-light" style="border-radius:8px;">
        <i class="fas fa-arrow-left me-1"></i> Back
    </a>
</div>

<?php flash_show(); ?>
<?php if ($errors): ?>
<div class="alert alert-danger" style="border-radius:10px;"><ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<ul class="nav nav-tabs mb-4" style="border-bottom:2px solid #e2e8f0;">
<?php $tabs = ['page'=>['fas fa-cog','Page Settings'],'registrar'=>['fas fa-stamp','Registrar Profile'],'message'=>['fas fa-quote-left','Registrar Message']];
foreach ($tabs as $key => [$icon, $label]): ?>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === $key ? 'active' : '' ?>"
           href="<?= APP_URL ?>/office-of-registrar/settings.php?tab=<?= $key ?>"
           style="<?= $active_tab === $key ? 'border-bottom:2px solid #002147;color:#002147;font-weight:600;' : 'color:#64748b;' ?>">
            <i class="<?= $icon ?> me-1"></i> <?= $label ?>
        </a>
    </li>
<?php endforeach; ?>
</ul>

<?php if ($active_tab === 'page'): ?>
<div class="row justify-content-center"><div class="col-lg-8">
<form method="POST" novalidate>
    <?= csrf_field() ?><input type="hidden" name="tab" value="page">
    <div class="card mb-4" style="border-radius:12px;">
        <div class="card-header py-3 px-4"><h6 class="mb-0 fw-semibold"><i class="fas fa-cog me-2 text-muted"></i>Hero &amp; Meta Settings</h6></div>
        <div class="card-body p-4">
            <div class="mb-3"><label class="form-label fw-medium">Hero Title <span class="text-danger">*</span></label>
                <input type="text" name="hero_title" class="form-control" required maxlength="255" value="<?= h($s['hero_title'] ?? '') ?>"></div>
            <div class="mb-3"><label class="form-label fw-medium">Hero Subtitle</label>
                <input type="text" name="hero_subtitle" class="form-control" maxlength="255" value="<?= h($s['hero_subtitle'] ?? '') ?>"></div>
            <div class="mb-3"><label class="form-label fw-medium">Hero Intro Text</label>
                <textarea name="hero_intro" class="form-control" rows="3"><?= h($s['hero_intro'] ?? '') ?></textarea></div>
            <div class="mb-3"><label class="form-label fw-medium">Meta Description</label>
                <textarea name="meta_desc" class="form-control" rows="2"><?= h($s['meta_description'] ?? '') ?></textarea>
                <div class="form-text">Recommended: 140–160 characters for SEO.</div></div>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="is_published" name="is_published" value="1" <?= ($s['is_published'] ?? '1') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label fw-medium" for="is_published">Published (visible on website)</label>
            </div>
        </div>
    </div>
    <div class="d-flex gap-2"><button type="submit" class="btn btn-primary" style="border-radius:10px;"><i class="fas fa-save me-1"></i> Save Page Settings</button></div>
</form></div></div>

<?php elseif ($active_tab === 'registrar'): ?>
<div class="row justify-content-center"><div class="col-lg-8">
<form method="POST" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?><input type="hidden" name="tab" value="registrar">
    <div class="card mb-4" style="border-radius:12px;">
        <div class="card-header py-3 px-4"><h6 class="mb-0 fw-semibold"><i class="fas fa-stamp me-2 text-muted"></i>Registrar Profile</h6></div>
        <div class="card-body p-4">
            <div class="mb-4">
                <label class="form-label fw-medium">Profile Photo</label>
                <div class="d-flex align-items-center gap-3 mb-2">
                    <?php if (!empty($s['reg_photo'])): ?>
                    <img src="<?= UPLOAD_URL ?>/office-of-registrar/<?= h($s['reg_photo']) ?>" id="reg-photo-preview"
                         style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid #e8eaf0;" alt="Registrar Profile Photo">
                    <?php else: ?>
                    <div id="reg-photo-preview-placeholder" style="width:80px;height:80px;border-radius:50%;background:#f1f5f9;display:flex;align-items:center;justify-content:center;border:2px dashed #cbd5e1;">
                        <i class="fas fa-stamp" style="color:#94a3b8;font-size:1.6rem;"></i></div>
                    <?php endif; ?>
                    <div>
                        <input type="file" name="reg_photo" id="reg_photo" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp" style="max-width:280px;">
                        <div class="form-text">JPG, PNG, GIF, WebP. Recommended: square, min 300×300px.</div>
                    </div>
                </div>
            </div>
            <div class="row g-3">
                <div class="col-md-8"><label class="form-label fw-medium">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="reg_name" class="form-control" required value="<?= h($s['reg_name'] ?? '') ?>"></div>
                <div class="col-md-4"><label class="form-label fw-medium">Title / Designation</label>
                    <input type="text" name="reg_title" class="form-control" value="<?= h($s['reg_title'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label fw-medium">Primary Email</label>
                    <input type="email" name="reg_email_1" class="form-control" value="<?= h($s['reg_email_1'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label fw-medium">Secondary Email</label>
                    <input type="email" name="reg_email_2" class="form-control" value="<?= h($s['reg_email_2'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label fw-medium">Phone</label>
                    <input type="text" name="reg_phone" class="form-control" value="<?= h($s['reg_phone'] ?? '') ?>"></div>
                <div class="col-12"><label class="form-label fw-medium">Bio / About</label>
                    <textarea name="reg_bio" class="form-control" rows="6"><?= h($s['reg_bio'] ?? '') ?></textarea></div>
            </div>
        </div>
    </div>
    <div class="d-flex gap-2"><button type="submit" class="btn btn-primary" style="border-radius:10px;"><i class="fas fa-save me-1"></i> Save Registrar Profile</button></div>
</form></div></div>

<?php elseif ($active_tab === 'message'): ?>
<div class="row justify-content-center"><div class="col-lg-8">
<form method="POST" novalidate>
    <?= csrf_field() ?><input type="hidden" name="tab" value="message">
    <div class="card mb-4" style="border-radius:12px;">
        <div class="card-header py-3 px-4"><h6 class="mb-0 fw-semibold"><i class="fas fa-quote-left me-2 text-muted"></i>Registrar's Message</h6></div>
        <div class="card-body p-4">
            <div class="mb-3"><label class="form-label fw-medium">Section Title <span class="text-danger">*</span></label>
                <input type="text" name="message_title" class="form-control" required value="<?= h($s['message_title'] ?? '') ?>">
                <div class="form-text">E.g. "Message from the Registrar"</div></div>
            <div class="mb-3"><label class="form-label fw-medium">Message Body</label>
                <textarea name="message_body" class="form-control" rows="16"
                    placeholder="Enter the full message. Use blank lines between paragraphs."><?= h($s['message_body'] ?? '') ?></textarea>
                <div class="form-text">Separate paragraphs with a blank line. HTML is not allowed.</div></div>
        </div>
    </div>
    <div class="d-flex gap-2"><button type="submit" class="btn btn-primary" style="border-radius:10px;"><i class="fas fa-save me-1"></i> Save Message</button></div>
</form></div></div>
<?php endif; ?>

<script>
document.getElementById('reg_photo')?.addEventListener('change', function () {
    const file = this.files[0]; if (!file) return;
    const reader = new FileReader();
    reader.onload = function (e) {
        let img = document.getElementById('reg-photo-preview');
        if (!img) { img = document.createElement('img'); img.id = 'reg-photo-preview';
            img.style.cssText = 'width:80px;height:80px;border-radius:50%;object-fit:cover;object-position:top center;border:3px solid #e8eaf0;';
            const p = document.getElementById('reg-photo-preview-placeholder'); if (p) p.replaceWith(img); }
        img.src = e.target.result;
    }; reader.readAsDataURL(file);
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
