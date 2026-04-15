<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('seo-settings', 'can_edit');
require_once __DIR__ . '/helpers.php';

$page_title = 'SEO Settings';

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $fields = [
        'site_name', 'title_separator', 'title_format',
        'default_meta_description', 'default_meta_keywords', 'default_og_image',
        'google_analytics_id', 'google_search_console',
        'twitter_site', 'fb_app_id',
        'robots_txt', 'sitemap_base_url',
        'og_type_default', 'twitter_card_type',
    ];

    foreach ($fields as $key) {
        $val = trim($_POST[$key] ?? '');
        seo_save_setting($key, $val);
    }

    flash_set('success', 'SEO settings saved successfully.');
    redirect(APP_URL . '/seo/settings.php');
}

$s = seo_settings();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/seo/index.php">SEO Manager</a></li>
            <li class="breadcrumb-item active">SEO Settings</li>
        </ol>
    </nav>
</div>

<?php flash_show(); ?>

<form method="POST">
    <?= csrf_field() ?>

    <ul class="nav nav-tabs mb-0" style="border-bottom:none;" id="settingsTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-general" type="button" role="tab">
                <i class="fas fa-globe me-1"></i> General
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-social" type="button" role="tab">
                <i class="fas fa-share-alt me-1"></i> Social & Analytics
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-sitemap" type="button" role="tab">
                <i class="fas fa-sitemap me-1"></i> Sitemap & Robots
            </button>
        </li>
    </ul>

    <div class="card" style="border-radius:0 12px 12px 12px;">
        <div class="card-body p-4">
            <div class="tab-content">

                <!-- ── GENERAL ── -->
                <div class="tab-pane fade show active" id="tab-general" role="tabpanel">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Site Name</label>
                            <input type="text" name="site_name" class="form-control" style="border-radius:8px;"
                                   value="<?= h($s['site_name'] ?? 'Prime University') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Title Separator</label>
                            <input type="text" name="title_separator" class="form-control" style="border-radius:8px;"
                                   value="<?= h($s['title_separator'] ?? '–') ?>" maxlength="10">
                            <div class="form-text">Symbol between page title and site name.</div>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-semibold">Title Format</label>
                            <input type="text" name="title_format" class="form-control" style="border-radius:8px;"
                                   value="<?= h($s['title_format'] ?? '{page_title} {sep} {site_name}') ?>">
                            <div class="form-text">
                                Placeholders: <code>{page_title}</code>, <code>{sep}</code>, <code>{site_name}</code>.
                                Example: <code>{page_title} {sep} {site_name}</code> → "About Us – Prime University"
                            </div>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-semibold">Default Meta Description
                                <span class="text-muted fw-normal ms-1" style="font-size:.8rem;">(≤ 160 chars recommended)</span>
                            </label>
                            <textarea name="default_meta_description" class="form-control" style="border-radius:8px;" rows="3"
                                      maxlength="500"><?= h($s['default_meta_description'] ?? '') ?></textarea>
                            <div class="form-text">Used when no page-specific description is set.</div>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-semibold">Default Meta Keywords</label>
                            <input type="text" name="default_meta_keywords" class="form-control" style="border-radius:8px;"
                                   value="<?= h($s['default_meta_keywords'] ?? '') ?>" placeholder="keyword1, keyword2">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-semibold">Default OG Image URL</label>
                            <input type="text" name="default_og_image" class="form-control" style="border-radius:8px;"
                                   value="<?= h($s['default_og_image'] ?? '/assets/img/logo/logo-black.png') ?>">
                            <div class="form-text">Fallback social sharing image (1200×630 px recommended).</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Default OG Type</label>
                            <select name="og_type_default" class="form-select" style="border-radius:8px;">
                                <?php foreach (['website','article','profile','book'] as $t): ?>
                                <option value="<?= $t ?>" <?= ($s['og_type_default'] ?? 'website') === $t ? 'selected' : '' ?>>
                                    <?= ucfirst($t) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Twitter Card Type</label>
                            <select name="twitter_card_type" class="form-select" style="border-radius:8px;">
                                <?php foreach (['summary','summary_large_image','app','player'] as $t): ?>
                                <option value="<?= $t ?>" <?= ($s['twitter_card_type'] ?? 'summary_large_image') === $t ? 'selected' : '' ?>>
                                    <?= str_replace('_', ' ', ucfirst($t)) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- ── SOCIAL & ANALYTICS ── -->
                <div class="tab-pane fade" id="tab-social" role="tabpanel">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Google Analytics ID</label>
                            <input type="text" name="google_analytics_id" class="form-control" style="border-radius:8px;"
                                   placeholder="G-XXXXXXXXXX or UA-XXXXXXXX-X"
                                   value="<?= h($s['google_analytics_id'] ?? '') ?>">
                            <div class="form-text">GA4 Measurement ID or Universal Analytics tracking ID.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Google Search Console Verification</label>
                            <input type="text" name="google_search_console" class="form-control" style="border-radius:8px;"
                                   placeholder="Verification meta tag content value"
                                   value="<?= h($s['google_search_console'] ?? '') ?>">
                            <div class="form-text">The <code>content</code> value from the Google-site-verification meta tag.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Twitter / X Username</label>
                            <div class="input-group" style="border-radius:8px;overflow:hidden;">
                                <span class="input-group-text">@</span>
                                <input type="text" name="twitter_site" class="form-control"
                                       placeholder="primeuniversity"
                                       value="<?= h(ltrim($s['twitter_site'] ?? '', '@')) ?>">
                            </div>
                            <div class="form-text">Used in <code>twitter:site</code> meta tag.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Facebook App ID</label>
                            <input type="text" name="fb_app_id" class="form-control" style="border-radius:8px;"
                                   placeholder="123456789"
                                   value="<?= h($s['fb_app_id'] ?? '') ?>">
                        </div>
                    </div>

                    <?php if (!empty($s['google_analytics_id'])): ?>
                    <div class="alert alert-success mt-4 py-2 px-3" style="border-radius:8px;font-size:.875rem;">
                        <i class="fas fa-check-circle me-1"></i>
                        Google Analytics is configured. The tracking code will be injected on all public pages.
                    </div>
                    <?php endif; ?>
                </div>

                <!-- ── SITEMAP & ROBOTS ── -->
                <div class="tab-pane fade" id="tab-sitemap" role="tabpanel">
                    <div class="row g-4">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Sitemap Base URL</label>
                            <input type="text" name="sitemap_base_url" class="form-control" style="border-radius:8px;"
                                   placeholder="https://primeuniversity.ac.bd"
                                   value="<?= h($s['sitemap_base_url'] ?? SITE_URL) ?>">
                            <div class="form-text">The root URL prepended to all relative URLs in the sitemap.</div>
                        </div>
                        <div class="col-md-12">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label fw-semibold mb-0">Robots.txt Content</label>
                                <a href="<?= SITE_URL ?>/robots.php" target="_blank"
                                   class="btn btn-outline-secondary btn-sm" style="border-radius:6px;font-size:.78rem;">
                                    <i class="fas fa-external-link-alt me-1"></i> View Live
                                </a>
                            </div>
                            <textarea name="robots_txt" class="form-control" style="border-radius:8px;font-family:monospace;font-size:.82rem;" rows="10"><?= h($s['robots_txt'] ?? "User-agent: *\nAllow: /\nDisallow: /admin/\nSitemap: " . SITE_URL . "/sitemap.php") ?></textarea>
                            <div class="form-text">This is served as the live <code>/robots.php</code> file.</div>
                        </div>
                    </div>

                    <div class="alert alert-info mt-4 py-2 px-3" style="border-radius:8px;font-size:.875rem;">
                        <i class="fas fa-info-circle me-1"></i>
                        The XML sitemap is auto-generated at
                        <a href="<?= SITE_URL ?>/sitemap.php" target="_blank"><?= SITE_URL ?>/sitemap.php</a>
                        and includes all pages with SEO entries where "Include in Sitemap" is enabled.
                    </div>
                </div>

            </div><!-- /tab-content -->

            <hr class="mt-4">
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" style="border-radius:8px;padding:9px 28px;">
                    <i class="fas fa-save me-1"></i> Save Settings
                </button>
                <a href="<?= APP_URL ?>/seo/index.php" class="btn btn-outline-secondary" style="border-radius:8px;padding:9px 20px;">
                    <i class="fas fa-arrow-left me-1"></i> Back to SEO Manager
                </a>
            </div>
        </div>
    </div>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
