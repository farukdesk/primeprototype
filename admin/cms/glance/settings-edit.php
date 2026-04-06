<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../change-log/helpers.php';
require_once __DIR__ . '/helpers.php';
require_access('cms-glance', 'can_edit');

$page_title = 'PU At a Glance – Page Settings';
$errors     = [];

$settings = glance_get_settings();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Handle about image upload
    $new_image = null;
    if (!empty($_FILES['about_image_file']['name'])) {
        $result = glance_upload_image($_FILES['about_image_file']);
        if ($result === false) {
            $errors[] = 'About image: invalid file. Allowed: JPG, PNG, GIF, WebP.';
        } else {
            $new_image = $result;
        }
    }

    if (empty($errors)) {
        $text_keys = [
            'hero_tag', 'hero_title', 'hero_title_accent', 'hero_subtitle',
            'hero_cta_primary_label', 'hero_cta_primary_url',
            'hero_cta_secondary_label', 'hero_cta_secondary_url',
            'about_section_tag', 'about_section_title', 'about_description',
            'about_badge_text',
            'cta_title', 'cta_desc', 'cta_btn_label', 'cta_btn_url',
            'cta_btn2_label', 'cta_btn2_url',
        ];

        foreach ($text_keys as $key) {
            $val = trim($_POST[$key] ?? '');
            glance_save_setting($key, $val ?: null);
            $settings[$key] = $val;
        }

        if ($new_image !== null) {
            glance_save_setting('about_image', $new_image);
            $settings['about_image'] = $new_image;
        }

        log_change('cms-glance', 'UPDATE', null, 'Page Settings', null, null, null, 'PU At a Glance page settings updated.');
        flash_set('success', 'Page settings saved successfully.');
        redirect(APP_URL . '/cms/glance/index.php');
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/cms/glance/index.php">PU At a Glance</a></li>
            <li class="breadcrumb-item active">Page Settings</li>
        </ol>
    </nav>
</div>

<?php flash_show(); ?>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-lg-8">

            <!-- Hero Section -->
            <div class="card mb-4">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-image me-2 text-muted"></i>Hero Section</h6>
                </div>
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Tag Line (small text above title)</label>
                        <input type="text" name="hero_tag" class="form-control" maxlength="100"
                               value="<?= h($settings['hero_tag'] ?? '') ?>" placeholder="Est. 2002 · UGC Approved">
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-7">
                            <label class="form-label fw-medium">Title</label>
                            <input type="text" name="hero_title" class="form-control" maxlength="120"
                                   value="<?= h($settings['hero_title'] ?? '') ?>" placeholder="Prime University">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-medium">Title Accent (gold text)</label>
                            <input type="text" name="hero_title_accent" class="form-control" maxlength="80"
                                   value="<?= h($settings['hero_title_accent'] ?? '') ?>" placeholder="At a Glance">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Subtitle / Tagline</label>
                        <textarea name="hero_subtitle" class="form-control" rows="2" maxlength="400"><?= h($settings['hero_subtitle'] ?? '') ?></textarea>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Primary CTA Label</label>
                            <input type="text" name="hero_cta_primary_label" class="form-control" maxlength="60"
                                   value="<?= h($settings['hero_cta_primary_label'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Primary CTA URL</label>
                            <input type="text" name="hero_cta_primary_url" class="form-control" maxlength="300"
                                   value="<?= h($settings['hero_cta_primary_url'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Secondary CTA Label</label>
                            <input type="text" name="hero_cta_secondary_label" class="form-control" maxlength="60"
                                   value="<?= h($settings['hero_cta_secondary_label'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Secondary CTA URL</label>
                            <input type="text" name="hero_cta_secondary_url" class="form-control" maxlength="300"
                                   value="<?= h($settings['hero_cta_secondary_url'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- About Section -->
            <div class="card mb-4">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-info-circle me-2 text-muted"></i>About / Overview Section</h6>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-medium">Section Tag (small label)</label>
                            <input type="text" name="about_section_tag" class="form-control" maxlength="60"
                                   value="<?= h($settings['about_section_tag'] ?? '') ?>" placeholder="Who We Are">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-medium">Section Title</label>
                            <input type="text" name="about_section_title" class="form-control" maxlength="200"
                                   value="<?= h($settings['about_section_title'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Description Paragraph</label>
                        <textarea name="about_description" class="form-control" rows="4"><?= h($settings['about_description'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Image Badge Text</label>
                        <input type="text" name="about_badge_text" class="form-control" maxlength="100"
                               value="<?= h($settings['about_badge_text'] ?? '') ?>" placeholder="Est. 2002 · Dhaka, Bangladesh">
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-medium">About Image</label>
                        <?php $ci = $settings['about_image'] ?? ''; ?>
                        <?php if ($ci): ?>
                        <div class="mb-2">
                            <img src="<?= h(glance_img_url(basename($ci))) ?>" alt="" style="max-height:150px;border-radius:8px;object-fit:contain;">
                        </div>
                        <?php endif; ?>
                        <input type="file" name="about_image_file" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp">
                        <div class="form-text">JPG, PNG, GIF, WebP. Leave blank to keep the current image.</div>
                    </div>
                </div>
            </div>

            <!-- Bottom CTA Section -->
            <div class="card">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-bullhorn me-2 text-muted"></i>Bottom CTA Strip</h6>
                </div>
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-medium">CTA Heading</label>
                        <input type="text" name="cta_title" class="form-control" maxlength="200"
                               value="<?= h($settings['cta_title'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">CTA Description</label>
                        <input type="text" name="cta_desc" class="form-control" maxlength="300"
                               value="<?= h($settings['cta_desc'] ?? '') ?>">
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Primary Button Label</label>
                            <input type="text" name="cta_btn_label" class="form-control" maxlength="60"
                                   value="<?= h($settings['cta_btn_label'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Primary Button URL</label>
                            <input type="text" name="cta_btn_url" class="form-control" maxlength="300"
                                   value="<?= h($settings['cta_btn_url'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Secondary Button Label</label>
                            <input type="text" name="cta_btn2_label" class="form-control" maxlength="60"
                                   value="<?= h($settings['cta_btn2_label'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Secondary Button URL</label>
                            <input type="text" name="cta_btn2_url" class="form-control" maxlength="300"
                                   value="<?= h($settings['cta_btn2_url'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

        </div>
        <div class="col-lg-4">
            <div class="card sticky-top" style="top:80px;">
                <div class="card-body p-4">
                    <button type="submit" class="btn btn-primary w-100 mb-2" style="border-radius:10px;">
                        <i class="fas fa-save me-1"></i> Save Settings
                    </button>
                    <a href="<?= APP_URL ?>/cms/glance/index.php" class="btn btn-light w-100" style="border-radius:10px;">Cancel</a>
                    <hr>
                    <div class="form-text">
                        Changes are applied to the <a href="<?= SITE_URL ?>/pu-at-a-glance.php" target="_blank">public page</a> immediately on save.
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
