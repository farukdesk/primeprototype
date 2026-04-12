<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('law-legal', 'can_edit');
require_once __DIR__ . '/helpers.php';

$page_title    = 'Law & Legal Affairs – Settings';
$errors        = [];
$active_tab    = in_array($_GET['tab'] ?? '', ['page', 'adviser', 'assistant', 'message'])
                 ? $_GET['tab']
                 : 'page';

// ── Load current settings ────────────────────────────────────────────────────
$s = ll_load_settings();

// ── Handle POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $tab = $_POST['tab'] ?? 'page';

    if ($tab === 'page') {
        $hero_title  = trim($_POST['hero_title']  ?? '');
        $hero_sub    = trim($_POST['hero_subtitle'] ?? '');
        $hero_intro  = trim($_POST['hero_intro']   ?? '');
        $meta_desc   = trim($_POST['meta_description'] ?? '');
        $is_pub      = isset($_POST['is_published']) ? '1' : '0';

        if ($hero_title === '') $errors[] = 'Hero title is required.';

        if (empty($errors)) {
            $upd = db()->prepare(
                'INSERT INTO ll_settings (setting_key, setting_val)
                 VALUES (?,?) ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)'
            );
            foreach ([
                'hero_title'       => $hero_title,
                'hero_subtitle'    => $hero_sub,
                'hero_intro'       => $hero_intro,
                'meta_description' => $meta_desc,
                'is_published'     => $is_pub,
            ] as $k => $v) {
                $upd->execute([$k, $v]);
            }
            flash_set('success', 'Page settings saved.');
            redirect(APP_URL . '/law-legal/settings.php?tab=page');
        }
    }

    elseif ($tab === 'adviser') {
        $adv_name  = trim($_POST['adviser_name']    ?? '');
        $adv_title = trim($_POST['adviser_title']   ?? '');
        $adv_email1= trim($_POST['adviser_email_1'] ?? '');
        $adv_email2= trim($_POST['adviser_email_2'] ?? '');
        $adv_phone = trim($_POST['adviser_phone']   ?? '');
        $adv_bio   = trim($_POST['adviser_bio']     ?? '');

        if ($adv_name === '') $errors[] = 'Adviser name is required.';

        $adv_photo = ll_s($s, 'adviser_photo', '');
        if (!empty($_FILES['adviser_photo']['name'])) {
            $res = ll_upload_photo($_FILES['adviser_photo']);
            if ($res === false) {
                $errors[] = 'Photo: invalid file. Allowed: JPG, PNG, GIF, WebP (max 5 MB).';
            } else {
                ll_delete_photo($adv_photo);
                $adv_photo = $res;
            }
        }

        if (empty($errors)) {
            $upd = db()->prepare(
                'INSERT INTO ll_settings (setting_key, setting_val)
                 VALUES (?,?) ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)'
            );
            foreach ([
                'adviser_name'    => $adv_name,
                'adviser_title'   => $adv_title,
                'adviser_email_1' => $adv_email1,
                'adviser_email_2' => $adv_email2,
                'adviser_phone'   => $adv_phone,
                'adviser_bio'     => $adv_bio,
                'adviser_photo'   => $adv_photo,
            ] as $k => $v) {
                $upd->execute([$k, $v]);
            }
            flash_set('success', 'Adviser profile saved.');
            redirect(APP_URL . '/law-legal/settings.php?tab=adviser');
        }
    }

    elseif ($tab === 'assistant') {
        $asst_name  = trim($_POST['assistant_name']    ?? '');
        $asst_title = trim($_POST['assistant_title']   ?? '');
        $asst_email1= trim($_POST['assistant_email_1'] ?? '');
        $asst_email2= trim($_POST['assistant_email_2'] ?? '');
        $asst_phone = trim($_POST['assistant_phone']   ?? '');
        $asst_bio   = trim($_POST['assistant_bio']     ?? '');

        if ($asst_name === '') $errors[] = 'Assistant Adviser name is required.';

        $asst_photo = ll_s($s, 'assistant_photo', '');
        if (!empty($_FILES['assistant_photo']['name'])) {
            $res = ll_upload_photo($_FILES['assistant_photo']);
            if ($res === false) {
                $errors[] = 'Photo: invalid file. Allowed: JPG, PNG, GIF, WebP (max 5 MB).';
            } else {
                ll_delete_photo($asst_photo);
                $asst_photo = $res;
            }
        }

        if (empty($errors)) {
            $upd = db()->prepare(
                'INSERT INTO ll_settings (setting_key, setting_val)
                 VALUES (?,?) ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)'
            );
            foreach ([
                'assistant_name'    => $asst_name,
                'assistant_title'   => $asst_title,
                'assistant_email_1' => $asst_email1,
                'assistant_email_2' => $asst_email2,
                'assistant_phone'   => $asst_phone,
                'assistant_bio'     => $asst_bio,
                'assistant_photo'   => $asst_photo,
            ] as $k => $v) {
                $upd->execute([$k, $v]);
            }
            flash_set('success', 'Assistant Adviser profile saved.');
            redirect(APP_URL . '/law-legal/settings.php?tab=assistant');
        }
    }

    elseif ($tab === 'message') {
        $msg_title = trim($_POST['message_title'] ?? '');
        $msg_body  = trim($_POST['message_body']  ?? '');

        if ($msg_title === '') $errors[] = 'Message title is required.';

        if (empty($errors)) {
            $upd = db()->prepare(
                'INSERT INTO ll_settings (setting_key, setting_val)
                 VALUES (?,?) ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)'
            );
            $upd->execute(['message_title', $msg_title]);
            $upd->execute(['message_body',  $msg_body]);
            flash_set('success', 'Message saved.');
            redirect(APP_URL . '/law-legal/settings.php?tab=message');
        }
    }

    $active_tab = $_POST['tab'] ?? $active_tab;
    $s = ll_load_settings(); // reload after errors
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/law-legal/index.php">Law &amp; Legal Affairs</a></li>
            <li class="breadcrumb-item active">Settings</li>
        </ol>
    </nav>
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
<ul class="nav nav-tabs mb-4">
    <?php foreach (['page' => 'Page', 'adviser' => 'Adviser', 'assistant' => 'Assistant Adviser', 'message' => 'Message'] as $k => $label): ?>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === $k ? 'active' : '' ?>"
           href="?tab=<?= $k ?>">
            <?php if ($k === 'page'): ?><i class="fas fa-cog me-1 text-muted"></i>
            <?php elseif ($k === 'adviser'): ?><i class="fas fa-gavel me-1 text-muted"></i>
            <?php elseif ($k === 'assistant'): ?><i class="fas fa-user me-1 text-muted"></i>
            <?php else: ?><i class="fas fa-quote-left me-1 text-muted"></i><?php endif; ?>
            <?= $label ?>
        </a>
    </li>
    <?php endforeach; ?>
</ul>

<div class="row justify-content-center">
<div class="col-lg-9">

<!-- ══ PAGE TAB ══════════════════════════════════════════════════════════════ -->
<?php if ($active_tab === 'page'): ?>
<form method="POST" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="tab" value="page">
    <div class="card mb-4" style="border-radius:12px;">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-cog me-2 text-muted"></i>Page Settings</h6>
        </div>
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-medium">Hero Title <span class="text-danger">*</span></label>
                    <input type="text" name="hero_title" class="form-control" required maxlength="200"
                           value="<?= h(ll_s($s, 'hero_title', 'Law & Legal Affairs')) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">Hero Subtitle</label>
                    <input type="text" name="hero_subtitle" class="form-control" maxlength="300"
                           value="<?= h(ll_s($s, 'hero_subtitle', '')) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">Hero Intro / Description</label>
                    <textarea name="hero_intro" class="form-control" rows="4"><?= h(ll_s($s, 'hero_intro', '')) ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">Meta Description (SEO)</label>
                    <textarea name="meta_description" class="form-control" rows="2" maxlength="300"><?= h(ll_s($s, 'meta_description', '')) ?></textarea>
                    <div class="form-text">Keep under 160 characters for best SEO results.</div>
                </div>
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_published" name="is_published"
                               value="1" <?= ll_s($s, 'is_published', '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="is_published">
                            Published (visible to public)
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
            <i class="fas fa-save me-1"></i> Save
        </button>
        <a href="<?= APP_URL ?>/law-legal/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
    </div>
</form>

<!-- ══ ADVISER TAB ═══════════════════════════════════════════════════════════ -->
<?php elseif ($active_tab === 'adviser'): ?>
<form method="POST" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="tab" value="adviser">
    <div class="card mb-4" style="border-radius:12px;">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-gavel me-2 text-muted"></i>Adviser Profile</h6>
        </div>
        <div class="card-body p-4">
            <!-- Photo -->
            <div class="mb-4">
                <label class="form-label fw-medium">Profile Photo</label>
                <div class="d-flex align-items-center gap-3 mb-2">
                    <?php $ap = ll_s($s, 'adviser_photo', ''); ?>
                    <?php if ($ap): ?>
                    <img id="ll-adv-preview" src="<?= UPLOAD_URL ?>/law-legal/<?= h($ap) ?>"
                         style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:2px solid #e8eaf0;flex-shrink:0;" alt="">
                    <?php else: ?>
                    <div id="ll-adv-placeholder"
                         style="width:80px;height:80px;border-radius:50%;background:#f1f5f9;display:flex;align-items:center;justify-content:center;border:2px dashed #cbd5e1;flex-shrink:0;">
                        <i class="fas fa-user-tie" style="color:#94a3b8;font-size:1.6rem;"></i>
                    </div>
                    <?php endif; ?>
                    <div>
                        <input type="file" name="adviser_photo" id="ll_adv_photo" class="form-control"
                               accept="image/jpeg,image/png,image/gif,image/webp" style="max-width:300px;">
                        <div class="form-text">JPG, PNG, GIF, WebP. Square, min 300×300px recommended.</div>
                    </div>
                </div>
            </div>
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label fw-medium">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="adviser_name" class="form-control" required maxlength="200"
                           value="<?= h(ll_s($s, 'adviser_name', '')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Title / Designation</label>
                    <input type="text" name="adviser_title" class="form-control" maxlength="200"
                           value="<?= h(ll_s($s, 'adviser_title', 'Adviser')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Primary Email</label>
                    <input type="email" name="adviser_email_1" class="form-control" maxlength="200"
                           value="<?= h(ll_s($s, 'adviser_email_1', '')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Secondary Email</label>
                    <input type="email" name="adviser_email_2" class="form-control" maxlength="200"
                           value="<?= h(ll_s($s, 'adviser_email_2', '')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Phone</label>
                    <input type="text" name="adviser_phone" class="form-control" maxlength="50"
                           value="<?= h(ll_s($s, 'adviser_phone', '')) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">Bio / About</label>
                    <textarea name="adviser_bio" class="form-control" rows="5"
                              placeholder="Professional background, qualifications, chamber…"><?= h(ll_s($s, 'adviser_bio', '')) ?></textarea>
                </div>
            </div>
        </div>
    </div>
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
            <i class="fas fa-save me-1"></i> Save
        </button>
        <a href="<?= APP_URL ?>/law-legal/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
    </div>
</form>

<!-- ══ ASSISTANT TAB ═════════════════════════════════════════════════════════ -->
<?php elseif ($active_tab === 'assistant'): ?>
<form method="POST" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="tab" value="assistant">
    <div class="card mb-4" style="border-radius:12px;">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-user me-2 text-muted"></i>Assistant Adviser Profile</h6>
        </div>
        <div class="card-body p-4">
            <!-- Photo -->
            <div class="mb-4">
                <label class="form-label fw-medium">Profile Photo</label>
                <div class="d-flex align-items-center gap-3 mb-2">
                    <?php $aph = ll_s($s, 'assistant_photo', ''); ?>
                    <?php if ($aph): ?>
                    <img id="ll-asst-preview" src="<?= UPLOAD_URL ?>/law-legal/<?= h($aph) ?>"
                         style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:2px solid #e8eaf0;flex-shrink:0;" alt="">
                    <?php else: ?>
                    <div id="ll-asst-placeholder"
                         style="width:80px;height:80px;border-radius:50%;background:#f1f5f9;display:flex;align-items:center;justify-content:center;border:2px dashed #cbd5e1;flex-shrink:0;">
                        <i class="fas fa-user" style="color:#94a3b8;font-size:1.6rem;"></i>
                    </div>
                    <?php endif; ?>
                    <div>
                        <input type="file" name="assistant_photo" id="ll_asst_photo" class="form-control"
                               accept="image/jpeg,image/png,image/gif,image/webp" style="max-width:300px;">
                        <div class="form-text">JPG, PNG, GIF, WebP. Square, min 300×300px recommended.</div>
                    </div>
                </div>
            </div>
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label fw-medium">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="assistant_name" class="form-control" required maxlength="200"
                           value="<?= h(ll_s($s, 'assistant_name', '')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Title / Designation</label>
                    <input type="text" name="assistant_title" class="form-control" maxlength="200"
                           value="<?= h(ll_s($s, 'assistant_title', 'Assistant Advisor (Legal & Estate)')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Primary Email</label>
                    <input type="email" name="assistant_email_1" class="form-control" maxlength="200"
                           value="<?= h(ll_s($s, 'assistant_email_1', '')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Secondary Email</label>
                    <input type="email" name="assistant_email_2" class="form-control" maxlength="200"
                           value="<?= h(ll_s($s, 'assistant_email_2', '')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Phone</label>
                    <input type="text" name="assistant_phone" class="form-control" maxlength="50"
                           value="<?= h(ll_s($s, 'assistant_phone', '')) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">Bio / About</label>
                    <textarea name="assistant_bio" class="form-control" rows="4"><?= h(ll_s($s, 'assistant_bio', '')) ?></textarea>
                </div>
            </div>
        </div>
    </div>
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
            <i class="fas fa-save me-1"></i> Save
        </button>
        <a href="<?= APP_URL ?>/law-legal/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
    </div>
</form>

<!-- ══ MESSAGE TAB ═══════════════════════════════════════════════════════════ -->
<?php else: ?>
<form method="POST" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="tab" value="message">
    <div class="card mb-4" style="border-radius:12px;">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-quote-left me-2 text-muted"></i>Adviser's Message</h6>
        </div>
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-medium">Message Title <span class="text-danger">*</span></label>
                    <input type="text" name="message_title" class="form-control" required maxlength="300"
                           value="<?= h(ll_s($s, 'message_title', 'Message from the Adviser')) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">Message Body</label>
                    <textarea name="message_body" class="form-control" rows="14"
                              placeholder="Enter the adviser's message. Use double line breaks to separate paragraphs."><?= h(ll_s($s, 'message_body', '')) ?></textarea>
                    <div class="form-text">Use blank lines between paragraphs. They will be displayed as separate paragraphs on the public page.</div>
                </div>
            </div>
        </div>
    </div>
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
            <i class="fas fa-save me-1"></i> Save
        </button>
        <a href="<?= APP_URL ?>/law-legal/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
    </div>
</form>
<?php endif; ?>

</div>
</div>

<script>
// Photo preview – Adviser
document.getElementById('ll_adv_photo')?.addEventListener('change', function () {
    const file = this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function (e) {
        let img = document.getElementById('ll-adv-preview');
        if (!img) {
            img = document.createElement('img');
            img.id = 'll-adv-preview';
            img.style.cssText = 'width:80px;height:80px;border-radius:50%;object-fit:cover;border:2px solid #e8eaf0;flex-shrink:0;';
            const ph = document.getElementById('ll-adv-placeholder');
            if (ph) ph.replaceWith(img);
        }
        img.src = e.target.result;
    };
    reader.readAsDataURL(file);
});
// Photo preview – Assistant
document.getElementById('ll_asst_photo')?.addEventListener('change', function () {
    const file = this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function (e) {
        let img = document.getElementById('ll-asst-preview');
        if (!img) {
            img = document.createElement('img');
            img.id = 'll-asst-preview';
            img.style.cssText = 'width:80px;height:80px;border-radius:50%;object-fit:cover;border:2px solid #e8eaf0;flex-shrink:0;';
            const ph = document.getElementById('ll-asst-placeholder');
            if (ph) ph.replaceWith(img);
        }
        img.src = e.target.result;
    };
    reader.readAsDataURL(file);
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
