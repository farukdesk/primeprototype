<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('office-of-chairman', 'can_edit');

$page_title = 'Office of Chairman – Settings';
$errors     = [];
$requested_tab = $_GET['tab'] ?? '';
$active_tab = in_array($requested_tab, ['page','chairman','ps','message']) ? $requested_tab : 'page';


// ── Load current settings ────────────────────────────────────────────────────
$s = [];
try {
    $rows = db()->query('SELECT setting_key, setting_val FROM ch_settings')->fetchAll();
    foreach ($rows as $r) $s[$r['setting_key']] = $r['setting_val'];
} catch (Throwable $e) {}

// ── Photo upload helper ──────────────────────────────────────────────────────
function ch_upload_photo(array $file): string|false
{
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    $allowed_exts  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_exts, true)) return false;
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, $allowed_mimes, true)) return false;
    $dir = UPLOAD_DIR . '/office-of-chairman';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return false;
    return $name;
}

// ── Save helper ──────────────────────────────────────────────────────────────
function ch_save(string $key, string $val): void
{
    db()->prepare(
        'INSERT INTO ch_settings (setting_key, setting_val) VALUES (?,?)
         ON DUPLICATE KEY UPDATE setting_val=VALUES(setting_val)'
    )->execute([$key, $val]);
}

// ── POST handling ────────────────────────────────────────────────────────────
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
            ch_save('hero_title',       $hero_title);
            ch_save('hero_subtitle',    $hero_subtitle);
            ch_save('hero_intro',       $hero_intro);
            ch_save('meta_description', $meta_desc);
            ch_save('is_published',     $is_published);
            flash_set('success', 'Page settings saved.');
            redirect(APP_URL . '/office-of-chairman/settings.php?tab=page');
        }

    } elseif ($tab === 'chairman') {
        $ch_name    = trim($_POST['ch_name']    ?? '');
        $ch_title   = trim($_POST['ch_title']   ?? '');
        $ch_email_1 = trim($_POST['ch_email_1'] ?? '');
        $ch_email_2 = trim($_POST['ch_email_2'] ?? '');
        $ch_phone   = trim($_POST['ch_phone']   ?? '');
        $ch_bio     = trim($_POST['ch_bio']     ?? '');

        if ($ch_name === '') $errors[] = 'Chairman name is required.';

        // Handle photo upload
        $ch_photo = $s['ch_photo'] ?? '';
        if (!empty($_FILES['ch_photo']['name'])) {
            $result = ch_upload_photo($_FILES['ch_photo']);
            if ($result === false) {
                $errors[] = 'Photo: invalid file. Allowed: JPG, PNG, GIF, WebP.';
            } else {
                if ($ch_photo) {
                    $old = UPLOAD_DIR . '/office-of-chairman/' . $ch_photo;
                    if (is_file($old)) @unlink($old);
                }
                $ch_photo = $result;
            }
        }

        if (empty($errors)) {
            ch_save('ch_name',    $ch_name);
            ch_save('ch_title',   $ch_title);
            ch_save('ch_email_1', $ch_email_1);
            ch_save('ch_email_2', $ch_email_2);
            ch_save('ch_phone',   $ch_phone);
            ch_save('ch_bio',     $ch_bio);
            ch_save('ch_photo',   $ch_photo);
            flash_set('success', 'Chairman profile saved.');
            redirect(APP_URL . '/office-of-chairman/settings.php?tab=chairman');
        }

    } elseif ($tab === 'ps') {
        $ps_name    = trim($_POST['ps_name']    ?? '');
        $ps_title   = trim($_POST['ps_title']   ?? '');
        $ps_email_1 = trim($_POST['ps_email_1'] ?? '');
        $ps_email_2 = trim($_POST['ps_email_2'] ?? '');
        $ps_phone   = trim($_POST['ps_phone']   ?? '');

        if ($ps_name === '') $errors[] = 'PS name is required.';

        // Handle PS photo upload
        $ps_photo = $s['ps_photo'] ?? '';
        if (!empty($_FILES['ps_photo']['name'])) {
            $result = ch_upload_photo($_FILES['ps_photo']);
            if ($result === false) {
                $errors[] = 'PS Photo: invalid file. Allowed: JPG, PNG, GIF, WebP.';
            } else {
                if ($ps_photo) {
                    $old = UPLOAD_DIR . '/office-of-chairman/' . $ps_photo;
                    if (is_file($old)) @unlink($old);
                }
                $ps_photo = $result;
            }
        }
        // Remove PS photo if requested
        if (isset($_POST['remove_ps_photo']) && $_POST['remove_ps_photo'] === '1') {
            if ($ps_photo) {
                $old = UPLOAD_DIR . '/office-of-chairman/' . $ps_photo;
                if (is_file($old)) @unlink($old);
            }
            $ps_photo = '';
        }

        if (empty($errors)) {
            ch_save('ps_name',    $ps_name);
            ch_save('ps_title',   $ps_title);
            ch_save('ps_email_1', $ps_email_1);
            ch_save('ps_email_2', $ps_email_2);
            ch_save('ps_phone',   $ps_phone);
            ch_save('ps_photo',   $ps_photo);
            flash_set('success', 'PS profile saved.');
            redirect(APP_URL . '/office-of-chairman/settings.php?tab=ps');
        }

    } elseif ($tab === 'message') {
        $message_title = trim($_POST['message_title'] ?? '');
        $message_body  = trim($_POST['message_body']  ?? '');

        if ($message_title === '') $errors[] = 'Message title is required.';

        if (empty($errors)) {
            ch_save('message_title', $message_title);
            ch_save('message_body',  $message_body);
            flash_set('success', 'Chairman message saved.');
            redirect(APP_URL . '/office-of-chairman/settings.php?tab=message');
        }
    }

    $active_tab = $tab;
    // Reload settings after error
    try {
        $rows = db()->query('SELECT setting_key, setting_val FROM ch_settings')->fetchAll();
        foreach ($rows as $r) $s[$r['setting_key']] = $r['setting_val'];
    } catch (Throwable $e) {}
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/office-of-chairman/index.php">Office of Chairman</a></li>
            <li class="breadcrumb-item active">Settings</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/office-of-chairman/index.php" class="btn btn-sm btn-light" style="border-radius:8px;">
        <i class="fas fa-arrow-left me-1"></i> Back
    </a>
</div>

<?php flash_show(); ?>

<?php if ($errors): ?>
<div class="alert alert-danger" style="border-radius:10px;">
    <ul class="mb-0 ps-3">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- Tab Navigation -->
<ul class="nav nav-tabs mb-4" style="border-bottom:2px solid #e2e8f0;">
    <?php
    $tabs = [
        'page'     => ['icon' => 'fas fa-cog',       'label' => 'Page Settings'],
        'chairman' => ['icon' => 'fas fa-gavel',      'label' => 'Chairman Profile'],
        'ps'       => ['icon' => 'fas fa-id-badge',   'label' => 'PS Profile'],
        'message'  => ['icon' => 'fas fa-quote-left', 'label' => 'Chairman Message'],
    ];
    foreach ($tabs as $key => $t): ?>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === $key ? 'active' : '' ?>"
           href="<?= APP_URL ?>/office-of-chairman/settings.php?tab=<?= $key ?>"
           style="<?= $active_tab === $key ? 'border-bottom:2px solid #002147;color:#002147;font-weight:600;' : 'color:#64748b;' ?>">
            <i class="<?= $t['icon'] ?> me-1"></i> <?= $t['label'] ?>
        </a>
    </li>
    <?php endforeach; ?>
</ul>

<!-- ══════════════ PAGE SETTINGS TAB ══════════════ -->
<?php if ($active_tab === 'page'): ?>
<div class="row justify-content-center">
<div class="col-lg-8">
<form method="POST" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="tab" value="page">

    <div class="card mb-4" style="border-radius:12px;">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-cog me-2 text-muted"></i>Hero &amp; Meta Settings</h6>
        </div>
        <div class="card-body p-4">
            <div class="mb-3">
                <label class="form-label fw-medium">Hero Title <span class="text-danger">*</span></label>
                <input type="text" name="hero_title" class="form-control" required maxlength="255"
                       value="<?= h($s['hero_title'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label class="form-label fw-medium">Hero Subtitle</label>
                <input type="text" name="hero_subtitle" class="form-control" maxlength="255"
                       value="<?= h($s['hero_subtitle'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label class="form-label fw-medium">Hero Intro Text</label>
                <textarea name="hero_intro" class="form-control" rows="3"><?= h($s['hero_intro'] ?? '') ?></textarea>
                <div class="form-text">Short paragraph shown in the hero section.</div>
            </div>
            <div class="mb-3">
                <label class="form-label fw-medium">Meta Description</label>
                <textarea name="meta_desc" class="form-control" rows="2"><?= h($s['meta_description'] ?? '') ?></textarea>
                <div class="form-text">Recommended: 140–160 characters for SEO.</div>
            </div>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="is_published" name="is_published"
                       value="1" <?= ($s['is_published'] ?? '1') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label fw-medium" for="is_published">Published (visible on website)</label>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
            <i class="fas fa-save me-1"></i> Save Page Settings
        </button>
    </div>
</form>
</div>
</div>

<!-- ══════════════ CHAIRMAN PROFILE TAB ══════════════ -->
<?php elseif ($active_tab === 'chairman'): ?>
<div class="row justify-content-center">
<div class="col-lg-8">
<form method="POST" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="tab" value="chairman">

    <div class="card mb-4" style="border-radius:12px;">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-gavel me-2 text-muted"></i>Chairman Profile</h6>
        </div>
        <div class="card-body p-4">

            <!-- Photo -->
            <div class="mb-4">
                <label class="form-label fw-medium">Profile Photo</label>
                <div class="d-flex align-items-center gap-3 mb-2">
                    <?php if (!empty($s['ch_photo'])): ?>
                    <img src="<?= UPLOAD_URL ?>/office-of-chairman/<?= h($s['ch_photo']) ?>"
                         id="ch-photo-preview"
                         style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid #e8eaf0;"
                         alt="Chairman Profile Photo">
                    <?php else: ?>
                    <div id="ch-photo-preview-placeholder"
                         style="width:80px;height:80px;border-radius:50%;background:#f1f5f9;display:flex;align-items:center;justify-content:center;border:2px dashed #cbd5e1;">
                        <i class="fas fa-gavel" style="color:#94a3b8;font-size:1.6rem;"></i>
                    </div>
                    <?php endif; ?>
                    <div>
                        <input type="file" name="ch_photo" id="ch_photo" class="form-control"
                               accept="image/jpeg,image/png,image/gif,image/webp"
                               style="max-width:280px;">
                        <div class="form-text">JPG, PNG, GIF, WebP. Recommended: square, min 300×300px.</div>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label fw-medium">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="ch_name" class="form-control" required
                           value="<?= h($s['ch_name'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Title / Designation</label>
                    <input type="text" name="ch_title" class="form-control"
                           value="<?= h($s['ch_title'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Primary Email</label>
                    <input type="email" name="ch_email_1" class="form-control"
                           value="<?= h($s['ch_email_1'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Secondary Email</label>
                    <input type="email" name="ch_email_2" class="form-control"
                           value="<?= h($s['ch_email_2'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Phone</label>
                    <input type="text" name="ch_phone" class="form-control"
                           value="<?= h($s['ch_phone'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">Bio / About</label>
                    <textarea name="ch_bio" class="form-control" rows="6"><?= h($s['ch_bio'] ?? '') ?></textarea>
                    <div class="form-text">Background, achievements, and notable positions.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
            <i class="fas fa-save me-1"></i> Save Chairman Profile
        </button>
    </div>
</form>
</div>
</div>

<!-- ══════════════ PS PROFILE TAB ══════════════ -->
<?php elseif ($active_tab === 'ps'): ?>
<div class="row justify-content-center">
<div class="col-lg-8">
<form method="POST" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="tab" value="ps">

    <div class="card mb-4" style="border-radius:12px;">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-id-badge me-2 text-muted"></i>Personal Secretary (PS) Profile</h6>
        </div>
        <div class="card-body p-4">

            <!-- PS Photo -->
            <div class="mb-4">
                <label class="form-label fw-medium">Profile Photo</label>
                <div class="d-flex align-items-center gap-3 mb-2">
                    <?php if (!empty($s['ps_photo'])): ?>
                    <img src="<?= UPLOAD_URL ?>/office-of-chairman/<?= h($s['ps_photo']) ?>"
                         id="ps-photo-preview"
                         style="width:80px;height:80px;border-radius:50%;object-fit:cover;object-position:top center;border:3px solid #e8eaf0;"
                         alt="PS Profile Photo">
                    <?php else: ?>
                    <div id="ps-photo-preview-placeholder"
                         style="width:80px;height:80px;border-radius:50%;background:#f1f5f9;display:flex;align-items:center;justify-content:center;border:2px dashed #cbd5e1;">
                        <i class="fas fa-id-badge" style="color:#94a3b8;font-size:1.6rem;"></i>
                    </div>
                    <?php endif; ?>
                    <div>
                        <input type="file" name="ps_photo" id="ps_photo" class="form-control mb-2"
                               accept="image/jpeg,image/png,image/gif,image/webp"
                               style="max-width:280px;">
                        <div class="form-text mb-2">JPG, PNG, GIF, WebP. Recommended: square, min 300×300px.</div>
                        <?php if (!empty($s['ps_photo'])): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="remove_ps_photo"
                                   name="remove_ps_photo" value="1">
                            <label class="form-check-label text-danger" for="remove_ps_photo"
                                   style="font-size:.83rem;">Remove current photo</label>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label fw-medium">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="ps_name" class="form-control" required
                           value="<?= h($s['ps_name'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Title / Designation</label>
                    <input type="text" name="ps_title" class="form-control"
                           value="<?= h($s['ps_title'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Primary Email</label>
                    <input type="email" name="ps_email_1" class="form-control"
                           value="<?= h($s['ps_email_1'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Secondary Email</label>
                    <input type="email" name="ps_email_2" class="form-control"
                           value="<?= h($s['ps_email_2'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Phone</label>
                    <input type="text" name="ps_phone" class="form-control"
                           value="<?= h($s['ps_phone'] ?? '') ?>">
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
            <i class="fas fa-save me-1"></i> Save PS Profile
        </button>
    </div>
</form>
</div>
</div>

<!-- ══════════════ MESSAGE TAB ══════════════ -->
<?php elseif ($active_tab === 'message'): ?>
<div class="row justify-content-center">
<div class="col-lg-8">
<form method="POST" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="tab" value="message">

    <div class="card mb-4" style="border-radius:12px;">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-quote-left me-2 text-muted"></i>Chairman's Message</h6>
        </div>
        <div class="card-body p-4">
            <div class="mb-3">
                <label class="form-label fw-medium">Section Title <span class="text-danger">*</span></label>
                <input type="text" name="message_title" class="form-control" required
                       value="<?= h($s['message_title'] ?? '') ?>">
                <div class="form-text">E.g. "Message from the Chairman"</div>
            </div>
            <div class="mb-3">
                <label class="form-label fw-medium">Message Body</label>
                <textarea name="message_body" class="form-control" rows="16"
                          placeholder="Enter the full message. Use blank lines between paragraphs."><?= h($s['message_body'] ?? '') ?></textarea>
                <div class="form-text">Separate paragraphs with a blank line. HTML is not allowed.</div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
            <i class="fas fa-save me-1"></i> Save Message
        </button>
    </div>
</form>
</div>
</div>
<?php endif; ?>

<script>
// Live photo preview – Chairman
document.getElementById('ch_photo')?.addEventListener('change', function () {
    const file = this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function (e) {
        let img = document.getElementById('ch-photo-preview');
        if (!img) {
            img = document.createElement('img');
            img.id = 'ch-photo-preview';
            img.style.cssText = 'width:80px;height:80px;border-radius:50%;object-fit:cover;object-position:top center;border:3px solid #e8eaf0;';
            const placeholder = document.getElementById('ch-photo-preview-placeholder');
            if (placeholder) placeholder.replaceWith(img);
        }
        img.src = e.target.result;
    };
    reader.readAsDataURL(file);
});

// Live photo preview – PS
document.getElementById('ps_photo')?.addEventListener('change', function () {
    const file = this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function (e) {
        let img = document.getElementById('ps-photo-preview');
        if (!img) {
            img = document.createElement('img');
            img.id = 'ps-photo-preview';
            img.style.cssText = 'width:80px;height:80px;border-radius:50%;object-fit:cover;object-position:top center;border:3px solid #e8eaf0;';
            const placeholder = document.getElementById('ps-photo-preview-placeholder');
            if (placeholder) placeholder.replaceWith(img);
        }
        img.src = e.target.result;
    };
    reader.readAsDataURL(file);
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
