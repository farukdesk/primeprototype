<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('office-of-coe', 'can_edit');

$page_title = 'Office of COE – Settings';
$errors     = [];
$requested_tab = $_GET['tab'] ?? '';
$active_tab = in_array($requested_tab, ['page','coe','message']) ? $requested_tab : 'page';

$s = [];
try {
    $rows = db()->query('SELECT setting_key, setting_val FROM coe_settings')->fetchAll();
    foreach ($rows as $r) $s[$r['setting_key']] = $r['setting_val'];
} catch (Throwable $e) {}

function coe_upload_photo(array $file): string|false
{
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    $allowed_exts  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_exts, true)) return false;
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, $allowed_mimes, true)) return false;
    $dir = UPLOAD_DIR . '/office-of-coe';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return false;
    return $name;
}

function coe_save(string $key, string $val): void
{
    db()->prepare(
        'INSERT INTO coe_settings (setting_key, setting_val) VALUES (?,?)
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
            coe_save('hero_title', $hero_title); coe_save('hero_subtitle', $hero_subtitle);
            coe_save('hero_intro', $hero_intro); coe_save('meta_description', $meta_desc);
            coe_save('is_published', $is_published);
            flash_set('success', 'Page settings saved.');
            redirect(APP_URL . '/office-of-coe/settings.php?tab=page');
        }

    } elseif ($tab === 'coe') {
        $coe_name    = trim($_POST['coe_name']    ?? '');
        $coe_title   = trim($_POST['coe_title']   ?? '');
        $coe_email_1 = trim($_POST['coe_email_1'] ?? '');
        $coe_email_2 = trim($_POST['coe_email_2'] ?? '');
        $coe_phone   = trim($_POST['coe_phone']   ?? '');
        $coe_bio     = trim($_POST['coe_bio']     ?? '');
        if ($coe_name === '') $errors[] = 'Controller of Examinations name is required.';
        $coe_photo = $s['coe_photo'] ?? '';
        if (!empty($_FILES['coe_photo']['name'])) {
            $result = coe_upload_photo($_FILES['coe_photo']);
            if ($result === false) { $errors[] = 'Photo: invalid file. Allowed: JPG, PNG, GIF, WebP.'; }
            else { if ($coe_photo) { $old = UPLOAD_DIR . '/office-of-coe/' . $coe_photo; if (is_file($old)) @unlink($old); } $coe_photo = $result; }
        }
        if (empty($errors)) {
            coe_save('coe_name', $coe_name); coe_save('coe_title', $coe_title);
            coe_save('coe_email_1', $coe_email_1); coe_save('coe_email_2', $coe_email_2);
            coe_save('coe_phone', $coe_phone); coe_save('coe_bio', $coe_bio);
            coe_save('coe_photo', $coe_photo);
            flash_set('success', 'COE profile saved.');
            redirect(APP_URL . '/office-of-coe/settings.php?tab=coe');
        }

    } elseif ($tab === 'message') {
        $message_title = trim($_POST['message_title'] ?? '');
        $message_body  = trim($_POST['message_body']  ?? '');
        if ($message_title === '') $errors[] = 'Message title is required.';
        if (empty($errors)) {
            coe_save('message_title', $message_title); coe_save('message_body', $message_body);
            flash_set('success', 'COE message saved.');
            redirect(APP_URL . '/office-of-coe/settings.php?tab=message');
        }
    }

    $active_tab = $tab;
    try {
        $rows = db()->query('SELECT setting_key, setting_val FROM coe_settings')->fetchAll();
        foreach ($rows as $r) $s[$r['setting_key']] = $r['setting_val'];
    } catch (Throwable $e) {}
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/office-of-coe/index.php">Office of COE</a></li>
            <li class="breadcrumb-item active">Settings</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/office-of-coe/index.php" class="btn btn-sm btn-light" style="border-radius:8px;">
        <i class="fas fa-arrow-left me-1"></i> Back
    </a>
</div>

<?php flash_show(); ?>
<?php if ($errors): ?>
<div class="alert alert-danger" style="border-radius:10px;"><ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<ul class="nav nav-tabs mb-4" style="border-bottom:2px solid #e2e8f0;">
<?php $tabs = ['page'=>['fas fa-cog','Page Settings'],'coe'=>['fas fa-scroll','COE Profile'],'message'=>['fas fa-quote-left','COE Message']];
foreach ($tabs as $key => [$icon, $label]): ?>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === $key ? 'active' : '' ?>"
           href="<?= APP_URL ?>/office-of-coe/settings.php?tab=<?= $key ?>"
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

<?php elseif ($active_tab === 'coe'): ?>
<div class="row justify-content-center"><div class="col-lg-8">
<form method="POST" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?><input type="hidden" name="tab" value="coe">
    <div class="card mb-4" style="border-radius:12px;">
        <div class="card-header py-3 px-4"><h6 class="mb-0 fw-semibold"><i class="fas fa-scroll me-2 text-muted"></i>COE Profile</h6></div>
        <div class="card-body p-4">
            <div class="mb-4">
                <label class="form-label fw-medium">Profile Photo</label>
                <div class="d-flex align-items-center gap-3 mb-2">
                    <?php if (!empty($s['coe_photo'])): ?>
                    <img src="<?= UPLOAD_URL ?>/office-of-coe/<?= h($s['coe_photo']) ?>" id="coe-photo-preview"
                         style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid #e8eaf0;" alt="COE Profile Photo">
                    <?php else: ?>
                    <div id="coe-photo-preview-placeholder" style="width:80px;height:80px;border-radius:50%;background:#f1f5f9;display:flex;align-items:center;justify-content:center;border:2px dashed #cbd5e1;">
                        <i class="fas fa-scroll" style="color:#94a3b8;font-size:1.6rem;"></i></div>
                    <?php endif; ?>
                    <div>
                        <input type="file" name="coe_photo" id="coe_photo" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp" style="max-width:280px;">
                        <div class="form-text">JPG, PNG, GIF, WebP. Recommended: square, min 300×300px.</div>
                    </div>
                </div>
            </div>
            <div class="row g-3">
                <div class="col-md-8"><label class="form-label fw-medium">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="coe_name" class="form-control" required value="<?= h($s['coe_name'] ?? '') ?>"></div>
                <div class="col-md-4"><label class="form-label fw-medium">Title / Designation</label>
                    <input type="text" name="coe_title" class="form-control" value="<?= h($s['coe_title'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label fw-medium">Primary Email</label>
                    <input type="email" name="coe_email_1" class="form-control" value="<?= h($s['coe_email_1'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label fw-medium">Secondary Email</label>
                    <input type="email" name="coe_email_2" class="form-control" value="<?= h($s['coe_email_2'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label fw-medium">Phone</label>
                    <input type="text" name="coe_phone" class="form-control" value="<?= h($s['coe_phone'] ?? '') ?>"></div>
                <div class="col-12"><label class="form-label fw-medium">Bio / About</label>
                    <textarea name="coe_bio" class="form-control" rows="6"><?= h($s['coe_bio'] ?? '') ?></textarea></div>
            </div>
        </div>
    </div>
    <div class="d-flex gap-2"><button type="submit" class="btn btn-primary" style="border-radius:10px;"><i class="fas fa-save me-1"></i> Save COE Profile</button></div>
</form></div></div>

<?php elseif ($active_tab === 'message'): ?>
<div class="row justify-content-center"><div class="col-lg-8">
<form method="POST" novalidate>
    <?= csrf_field() ?><input type="hidden" name="tab" value="message">
    <div class="card mb-4" style="border-radius:12px;">
        <div class="card-header py-3 px-4"><h6 class="mb-0 fw-semibold"><i class="fas fa-quote-left me-2 text-muted"></i>COE's Message</h6></div>
        <div class="card-body p-4">
            <div class="mb-3"><label class="form-label fw-medium">Section Title <span class="text-danger">*</span></label>
                <input type="text" name="message_title" class="form-control" required value="<?= h($s['message_title'] ?? '') ?>">
                <div class="form-text">E.g. "Message from the Controller of Examinations"</div></div>
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
document.getElementById('coe_photo')?.addEventListener('change', function () {
    const file = this.files[0]; if (!file) return;
    const reader = new FileReader();
    reader.onload = function (e) {
        let img = document.getElementById('coe-photo-preview');
        if (!img) { img = document.createElement('img'); img.id = 'coe-photo-preview';
            img.style.cssText = 'width:80px;height:80px;border-radius:50%;object-fit:cover;object-position:top center;border:3px solid #e8eaf0;';
            const p = document.getElementById('coe-photo-preview-placeholder'); if (p) p.replaceWith(img); }
        img.src = e.target.result;
    }; reader.readAsDataURL(file);
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
