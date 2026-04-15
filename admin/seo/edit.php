<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('seo', 'can_edit');
require_once __DIR__ . '/helpers.php';

// ── Resolve entry ─────────────────────────────────────────────────────────────
$entry = null;

if (!empty($_GET['id'])) {
    $entry = seo_get((int)$_GET['id']);
    if (!$entry) {
        flash_set('error', 'SEO entry not found.');
        redirect(APP_URL . '/seo/index.php');
    }
} else {
    // Creating / opening via page_type+url params
    $page_type  = trim($_GET['page_type']  ?? 'static');
    $page_id    = $_GET['page_id'] !== '' ? (int)$_GET['page_id'] : null;
    $page_url   = trim($_GET['page_url']   ?? '');
    $page_label = trim($_GET['page_label'] ?? $page_url);

    if ($page_url === '') {
        flash_set('error', 'Page URL is required.');
        redirect(APP_URL . '/seo/index.php');
    }

    // Ensure the row exists (creates stub if missing)
    $entry_id = seo_ensure_entry($page_type, $page_id, $page_url, $page_label);
    $entry    = seo_get($entry_id);
}

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $data = [
        'id'               => (int)$entry['id'],
        'page_label'       => trim($_POST['page_label']       ?? $entry['page_label']),
        'meta_title'       => trim($_POST['meta_title']       ?? ''),
        'meta_description' => trim($_POST['meta_description'] ?? ''),
        'meta_keywords'    => trim($_POST['meta_keywords']    ?? ''),
        'og_title'         => trim($_POST['og_title']         ?? ''),
        'og_description'   => trim($_POST['og_description']   ?? ''),
        'og_image'         => trim($_POST['og_image']         ?? ''),
        'canonical_url'    => trim($_POST['canonical_url']    ?? ''),
        'robots'           => trim($_POST['robots']           ?? 'index,follow'),
        'schema_json'      => trim($_POST['schema_json']      ?? ''),
        'custom_head_code' => trim($_POST['custom_head_code'] ?? ''),
        'sitemap_include'  => isset($_POST['sitemap_include']) ? 1 : 0,
        'sitemap_priority' => (float)($_POST['sitemap_priority'] ?? 0.5),
        'sitemap_changefreq' => trim($_POST['sitemap_changefreq'] ?? 'weekly'),
        'is_active'        => 1,
    ];

    // Validate JSON-LD if provided
    if ($data['schema_json'] !== '') {
        json_decode($data['schema_json']);
        if (json_last_error() !== JSON_ERROR_NONE) {
            flash_set('error', 'Schema JSON is not valid JSON. Please fix it before saving.');
            save_old($_POST);
            redirect(APP_URL . '/seo/edit.php?id=' . $entry['id']);
        }
    }

    seo_save($data);
    flash_set('success', 'SEO settings saved successfully.');
    redirect(APP_URL . '/seo/edit.php?id=' . $entry['id']);
}

$page_title = 'Edit SEO: ' . $entry['page_label'];
$settings   = seo_settings();
$site_name  = $settings['site_name'] ?? 'Prime University';

// Compute preview title
$preview_title = $entry['meta_title'] ? seo_build_title($entry['page_label'], $entry['meta_title']) : seo_build_title($entry['page_label']);
$preview_desc  = $entry['meta_description'] ?? ($settings['default_meta_description'] ?? '');

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/seo/index.php">SEO Manager</a></li>
            <li class="breadcrumb-item active">Edit SEO</li>
        </ol>
    </nav>
    <a href="<?= h(SITE_URL . $entry['page_url']) ?>" target="_blank"
       class="btn btn-outline-secondary btn-sm" style="border-radius:8px;">
        <i class="fas fa-external-link-alt me-1"></i> View Live Page
    </a>
</div>

<?php flash_show(); ?>

<!-- Page info card -->
<div class="card mb-4" style="border-radius:12px;border-left:4px solid #4f8ef7;">
    <div class="card-body py-3 px-4">
        <div class="d-flex align-items-start gap-3">
            <i class="fas fa-file-alt" style="color:#4f8ef7;font-size:1.2rem;margin-top:2px;"></i>
            <div>
                <div style="font-weight:700;color:#1a2e5a;"><?= h($entry['page_label']) ?></div>
                <div style="font-size:.82rem;color:#6b7280;font-family:monospace;"><?= h($entry['page_url']) ?></div>
                <div style="font-size:.78rem;margin-top:2px;">
                    <span style="background:#e0e7ff;color:#4338ca;border-radius:50px;padding:2px 8px;font-size:.72rem;font-weight:600;">
                        <?= h(ucfirst(str_replace('_','-',$entry['page_type']))) ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Google Preview -->
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-header" style="background:#fff;border-bottom:1px solid #f0f0f0;padding:14px 20px;">
        <span style="font-weight:600;font-size:.875rem;color:#374151;"><i class="fab fa-google me-2" style="color:#4285f4;"></i>Google Search Preview</span>
    </div>
    <div class="card-body p-4">
        <div id="seo-preview" style="max-width:600px;font-family:Arial,sans-serif;">
            <div style="font-size:.8rem;color:#006621;margin-bottom:2px;" id="preview-url"><?= h(SITE_URL . $entry['page_url']) ?></div>
            <div id="preview-title" style="font-size:1.1rem;color:#1a0dab;cursor:pointer;text-decoration:none;line-height:1.3;margin-bottom:4px;">
                <?= h($preview_title) ?>
            </div>
            <div id="preview-desc" style="font-size:.875rem;color:#4d5156;line-height:1.5;">
                <?= h(mb_substr($preview_desc, 0, 160)) ?>
            </div>
        </div>
    </div>
</div>

<form method="POST">
    <?= csrf_field() ?>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-0" style="border-bottom:none;" id="seoTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="basic-tab" data-bs-toggle="tab" data-bs-target="#tab-basic"
                    type="button" role="tab">
                <i class="fas fa-tag me-1"></i> Basic SEO
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="og-tab" data-bs-toggle="tab" data-bs-target="#tab-og"
                    type="button" role="tab">
                <i class="fas fa-share-alt me-1"></i> Open Graph
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="advanced-tab" data-bs-toggle="tab" data-bs-target="#tab-advanced"
                    type="button" role="tab">
                <i class="fas fa-code me-1"></i> Advanced
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="sitemap-tab" data-bs-toggle="tab" data-bs-target="#tab-sitemap"
                    type="button" role="tab">
                <i class="fas fa-sitemap me-1"></i> Sitemap
            </button>
        </li>
    </ul>

    <div class="card" style="border-radius:0 12px 12px 12px;">
        <div class="card-body p-4">
            <div class="tab-content" id="seoTabsContent">

                <!-- ── BASIC SEO ── -->
                <div class="tab-pane fade show active" id="tab-basic" role="tabpanel">
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Page Label</label>
                        <input type="text" name="page_label" class="form-control" style="border-radius:8px;"
                               value="<?= h(old('page_label', $entry['page_label'])) ?>">
                        <div class="form-text">Internal name used in the SEO Manager list only.</div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">
                            Meta Title
                            <span class="text-muted fw-normal ms-1" style="font-size:.8rem;">(recommended ≤ 60 chars)</span>
                        </label>
                        <input type="text" name="meta_title" id="meta-title-input" class="form-control" style="border-radius:8px;"
                               placeholder="<?= h(seo_build_title($entry['page_label'])) ?>"
                               value="<?= h(old('meta_title', $entry['meta_title'] ?? '')) ?>" maxlength="255">
                        <div class="d-flex justify-content-between mt-1">
                            <div class="form-text">Leave blank to use the default title format from SEO Settings.</div>
                            <small id="title-counter" style="font-size:.75rem;color:#6b7280;">0 / 60</small>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">
                            Meta Description
                            <span class="text-muted fw-normal ms-1" style="font-size:.8rem;">(recommended ≤ 160 chars)</span>
                        </label>
                        <textarea name="meta_description" id="meta-desc-input" class="form-control" style="border-radius:8px;" rows="3"
                                  placeholder="<?= h(mb_substr($settings['default_meta_description'] ?? '', 0, 80)) ?>…"
                                  maxlength="500"><?= h(old('meta_description', $entry['meta_description'] ?? '')) ?></textarea>
                        <div class="d-flex justify-content-between mt-1">
                            <div class="form-text">Leave blank to use the default description from SEO Settings.</div>
                            <small id="desc-counter" style="font-size:.75rem;color:#6b7280;">0 / 160</small>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">
                            Meta Keywords
                            <span class="text-muted fw-normal ms-1" style="font-size:.8rem;">(comma-separated)</span>
                        </label>
                        <input type="text" name="meta_keywords" class="form-control" style="border-radius:8px;"
                               placeholder="keyword1, keyword2, keyword3"
                               value="<?= h(old('meta_keywords', $entry['meta_keywords'] ?? '')) ?>" maxlength="500">
                        <div class="form-text">Optional. Most search engines ignore this, but some directories use it.</div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-semibold">Robots</label>
                        <select name="robots" class="form-select" style="border-radius:8px;max-width:360px;">
                            <?php foreach (seo_robots_options() as $val => $label): ?>
                            <option value="<?= h($val) ?>" <?= old('robots', $entry['robots'] ?? 'index,follow') === $val ? 'selected' : '' ?>>
                                <?= h($label) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Controls whether search engines index this page and follow its links.</div>
                    </div>
                </div>

                <!-- ── OPEN GRAPH ── -->
                <div class="tab-pane fade" id="tab-og" role="tabpanel">
                    <div class="alert alert-info py-2 px-3 mb-4" style="border-radius:8px;font-size:.875rem;">
                        <i class="fas fa-info-circle me-1"></i>
                        Open Graph tags control how this page appears when shared on <strong>Facebook, LinkedIn, WhatsApp</strong> etc.
                        Leave blank to inherit the meta title/description.
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">OG Title</label>
                        <input type="text" name="og_title" class="form-control" style="border-radius:8px;"
                               placeholder="Defaults to Meta Title"
                               value="<?= h(old('og_title', $entry['og_title'] ?? '')) ?>" maxlength="255">
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">OG Description</label>
                        <textarea name="og_description" class="form-control" style="border-radius:8px;" rows="3"
                                  placeholder="Defaults to Meta Description"
                                  maxlength="500"><?= h(old('og_description', $entry['og_description'] ?? '')) ?></textarea>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">OG Image URL</label>
                        <input type="text" name="og_image" class="form-control" style="border-radius:8px;"
                               placeholder="<?= h($settings['default_og_image'] ?? '/assets/img/logo/logo-black.png') ?>"
                               value="<?= h(old('og_image', $entry['og_image'] ?? '')) ?>" maxlength="500">
                        <div class="form-text">Recommended: 1200×630 px. Can be a relative path or full URL.</div>
                        <?php
                        $og_img_val = old('og_image', $entry['og_image'] ?? '');
                        if ($og_img_val): ?>
                        <div class="mt-2">
                            <img src="<?= h(strpos($og_img_val, 'http') === 0 ? $og_img_val : SITE_URL . $og_img_val) ?>"
                                 style="max-height:120px;border-radius:6px;border:1px solid #e5e7eb;" alt="OG preview"
                                 onerror="this.style.display='none'">
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-semibold">Twitter Card Preview</label>
                        <div style="border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;max-width:500px;">
                            <div style="background:#f9fafb;padding:12px 16px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;gap:8px;">
                                <i class="fab fa-x-twitter" style="color:#000;"></i>
                                <span style="font-size:.8rem;color:#6b7280;">Twitter/X Card Preview</span>
                            </div>
                            <div style="padding:12px 16px;">
                                <div style="font-weight:600;font-size:.9rem;color:#0f172a;">
                                    <?= h($entry['og_title'] ?: ($entry['meta_title'] ?: $entry['page_label'])) ?>
                                </div>
                                <div style="font-size:.8rem;color:#6b7280;margin-top:4px;">
                                    <?= h(mb_substr($entry['og_description'] ?: ($entry['meta_description'] ?? ''), 0, 100)) ?>
                                </div>
                                <div style="font-size:.75rem;color:#9ca3af;margin-top:4px;"><?= h(SITE_URL) ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── ADVANCED ── -->
                <div class="tab-pane fade" id="tab-advanced" role="tabpanel">
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Canonical URL</label>
                        <input type="text" name="canonical_url" class="form-control" style="border-radius:8px;"
                               placeholder="Leave blank to auto-generate from page URL"
                               value="<?= h(old('canonical_url', $entry['canonical_url'] ?? '')) ?>" maxlength="500">
                        <div class="form-text">Use when this page has duplicate content or is accessible via multiple URLs.</div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Schema / Structured Data (JSON-LD)</label>
                        <textarea name="schema_json" class="form-control" style="border-radius:8px;font-family:monospace;font-size:.82rem;" rows="10"
                                  placeholder='{"@context":"https://schema.org","@type":"WebPage","name":"Page Title"}'><?= h(old('schema_json', $entry['schema_json'] ?? '')) ?></textarea>
                        <div class="form-text">
                            Valid JSON-LD only. See <a href="https://schema.org" target="_blank" rel="noopener">schema.org</a> and
                            <a href="https://developers.google.com/search/docs/appearance/structured-data" target="_blank" rel="noopener">Google's rich results guide</a>.
                        </div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-semibold">Custom &lt;head&gt; Code</label>
                        <textarea name="custom_head_code" class="form-control" style="border-radius:8px;font-family:monospace;font-size:.82rem;" rows="5"
                                  placeholder="<!-- Custom meta tags, link tags, scripts, etc. -->"><?= h(old('custom_head_code', $entry['custom_head_code'] ?? '')) ?></textarea>
                        <div class="form-text">Raw HTML injected into <code>&lt;head&gt;</code> for this page only.</div>
                    </div>
                </div>

                <!-- ── SITEMAP ── -->
                <div class="tab-pane fade" id="tab-sitemap" role="tabpanel">
                    <div class="mb-4">
                        <div class="form-check form-switch" style="font-size:1rem;">
                            <input class="form-check-input" type="checkbox" name="sitemap_include" id="sitemap_include" role="switch"
                                   <?= (old('sitemap_include', (string)($entry['sitemap_include'] ?? '1')) === '1') ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="sitemap_include">Include in Sitemap</label>
                        </div>
                        <div class="form-text">Uncheck to exclude this page from the XML sitemap.</div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Priority</label>
                            <select name="sitemap_priority" class="form-select" style="border-radius:8px;">
                                <?php foreach (['1.0','0.9','0.8','0.7','0.6','0.5','0.4','0.3','0.2','0.1'] as $p): ?>
                                <option value="<?= $p ?>" <?= number_format((float)old('sitemap_priority', (string)($entry['sitemap_priority'] ?? '0.5')), 1) === $p ? 'selected' : '' ?>>
                                    <?= $p ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">1.0 = highest. Homepage should be 1.0.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Change Frequency</label>
                            <select name="sitemap_changefreq" class="form-select" style="border-radius:8px;">
                                <?php foreach (seo_changefreq_options() as $cf): ?>
                                <option value="<?= $cf ?>" <?= old('sitemap_changefreq', $entry['sitemap_changefreq'] ?? 'weekly') === $cf ? 'selected' : '' ?>>
                                    <?= ucfirst($cf) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

            </div><!-- /tab-content -->

            <hr class="mt-4">
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" style="border-radius:8px;padding:9px 28px;">
                    <i class="fas fa-save me-1"></i> Save SEO
                </button>
                <a href="<?= APP_URL ?>/seo/index.php" class="btn btn-outline-secondary" style="border-radius:8px;padding:9px 20px;">
                    <i class="fas fa-arrow-left me-1"></i> Back
                </a>
            </div>
        </div>
    </div><!-- /card -->
</form>

<script>
(function () {
    // Character counters
    function updateCounter(input, counter, max) {
        var len = input.value.length;
        counter.textContent = len + ' / ' + max;
        counter.style.color = len > max ? '#dc2626' : (len > max * 0.9 ? '#f59e0b' : '#6b7280');
    }

    var titleInput   = document.getElementById('meta-title-input');
    var titleCounter = document.getElementById('title-counter');
    var descInput    = document.getElementById('meta-desc-input');
    var descCounter  = document.getElementById('desc-counter');

    if (titleInput && titleCounter) {
        updateCounter(titleInput, titleCounter, 60);
        titleInput.addEventListener('input', function () {
            updateCounter(titleInput, titleCounter, 60);
            updatePreview();
        });
    }
    if (descInput && descCounter) {
        updateCounter(descInput, descCounter, 160);
        descInput.addEventListener('input', function () {
            updateCounter(descInput, descCounter, 160);
            updatePreview();
        });
    }

    // Live Google preview
    function updatePreview() {
        var titleEl = document.getElementById('preview-title');
        var descEl  = document.getElementById('preview-desc');
        if (!titleEl || !descEl) return;

        var rawTitle = titleInput ? titleInput.value.trim() : '';
        var rawDesc  = descInput  ? descInput.value.trim()  : '';

        var siteName  = <?= json_encode($site_name) ?>;
        var sep       = <?= json_encode($settings['title_separator'] ?? '–') ?>;
        var pageLabel = <?= json_encode($entry['page_label']) ?>;
        var fmt       = <?= json_encode($settings['title_format'] ?? '{page_title} {sep} {site_name}') ?>;
        var defaultDesc = <?= json_encode(mb_substr($settings['default_meta_description'] ?? '', 0, 160)) ?>;

        var displayTitle = rawTitle
            ? fmt.replace('{page_title}', rawTitle).replace('{sep}', sep).replace('{site_name}', siteName)
            : fmt.replace('{page_title}', pageLabel).replace('{sep}', sep).replace('{site_name}', siteName);

        var displayDesc = rawDesc
            ? rawDesc.substring(0, 160)
            : defaultDesc.substring(0, 160);

        titleEl.textContent = displayTitle;
        descEl.textContent  = displayDesc;
    }

    updatePreview();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
