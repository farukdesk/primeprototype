<?php
require_once __DIR__ . '/../includes/auth.php';
require_super_admin();

$page_title = 'New Page';
$errors     = [];
clear_old();

// ── Helpers ───────────────────────────────────────────────────────────────────
function pages_slug(string $title): string {
    $s = mb_strtolower(trim($title));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-') ?: 'untitled';
}

function unique_pages_slug(string $base, int $exclude_id = 0): string {
    $slug = $base;
    $i    = 2;
    $db   = db();
    while (true) {
        $st = $db->prepare('SELECT id FROM pages WHERE slug = ? AND id != ?');
        $st->execute([$slug, $exclude_id]);
        if (!$st->fetch()) break;
        $slug = $base . '-' . $i++;
    }
    return $slug;
}

function upload_page_image(array $file): string|false {
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    $allowed_exts  = ['jpg','jpeg','png','gif','webp'];
    $allowed_mimes = ['image/jpeg','image/png','image/gif','image/webp'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_exts, true)) return false;
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, $allowed_mimes, true)) return false;
    $dir = UPLOAD_DIR . '/pages';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return false;
    return $name;
}

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $title           = trim($_POST['title']           ?? '');
    $category        = in_array($_POST['category'] ?? '', ['general','profile','policy']) ? $_POST['category'] : 'general';
    $page_heading    = trim($_POST['page_heading']    ?? '');
    $page_intro      = trim($_POST['page_intro']      ?? '');
    $meta_description= trim($_POST['meta_description']?? '');
    $is_published    = isset($_POST['is_published']) ? 1 : 0;
    $published_at    = $is_published ? (trim($_POST['published_at'] ?? '') ?: date('Y-m-d H:i:s')) : null;
    $sort_order      = (int)($_POST['sort_order'] ?? 0);

    // category-specific fields
    $gjs_html        = ($category === 'general') ? ($_POST['gjs_html']  ?? '') : null;
    $gjs_css         = ($category === 'general') ? ($_POST['gjs_css']   ?? '') : null;
    $gjs_data        = ($category === 'general') ? ($_POST['gjs_data']  ?? '') : null;
    $content         = ($category === 'policy')  ? ($_POST['content']   ?? '') : null;
    $policy_type     = ($category === 'policy')  ? trim($_POST['policy_type']  ?? '') : null;
    $effective_date  = ($category === 'policy')  ? (trim($_POST['effective_date'] ?? '') ?: null) : null;
    $profile_subtitle= ($category === 'profile') ? trim($_POST['profile_subtitle'] ?? '') : null;

    if ($title === '') $errors[] = 'Title is required.';

    // Featured image
    $featured_image = null;
    if (!empty($_FILES['featured_image']['name'])) {
        $result = upload_page_image($_FILES['featured_image']);
        if ($result === false) {
            $errors[] = 'Featured image: invalid file. Allowed: JPG, PNG, GIF, WebP.';
        } else {
            $featured_image = $result;
        }
    }

    if (empty($errors)) {
        $slug = unique_pages_slug(pages_slug($title));

        db()->prepare(
            'INSERT INTO pages
             (title, slug, category, page_heading, page_intro, meta_description, featured_image,
              gjs_html, gjs_css, gjs_data, content, policy_type, effective_date, profile_subtitle,
              is_published, published_at, sort_order)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $title, $slug, $category, $page_heading ?: null, $page_intro ?: null,
            $meta_description ?: null, $featured_image,
            $gjs_html, $gjs_css, $gjs_data, $content,
            $policy_type ?: null, $effective_date, $profile_subtitle ?: null,
            $is_published, $published_at, $sort_order,
        ]);

        $new_id = (int)db()->lastInsertId();

        flash_set('success', 'Page <strong>' . h($title) . '</strong> created.');
        if ($category === 'profile') {
            redirect(APP_URL . '/pages/profiles/index.php?page_id=' . $new_id);
        }
        redirect(APP_URL . '/pages/index.php');
    }

    save_old(compact('title','category','page_heading','page_intro','meta_description',
                      'is_published','sort_order','policy_type','effective_date','profile_subtitle'));
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/index.php">Pages</a></li>
            <li class="breadcrumb-item active">New Page</li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" novalidate id="pageForm">
    <?= csrf_field() ?>

    <div class="row g-4">
        <!-- ── LEFT COLUMN ── -->
        <div class="col-lg-8">

            <!-- Basic info card -->
            <div class="card mb-4">
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" id="pageTitle" class="form-control form-control-lg"
                               value="<?= old('title') ?>" required placeholder="Page title…" maxlength="255">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Category <span class="text-danger">*</span></label>
                        <select name="category" id="catSelect" class="form-select" style="border-radius:10px;">
                            <option value="general" <?= old('category','general') === 'general' ? 'selected' : '' ?>>
                                🧩 General – drag &amp; drop page builder
                            </option>
                            <option value="profile" <?= old('category') === 'profile' ? 'selected' : '' ?>>
                                👤 Profile – person / leadership listing
                            </option>
                            <option value="policy" <?= old('category') === 'policy' ? 'selected' : '' ?>>
                                📄 Policy – policy &amp; procedure document
                            </option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Banner / Section Heading</label>
                        <input type="text" name="page_heading" class="form-control"
                               value="<?= old('page_heading') ?>" maxlength="255"
                               placeholder="Displayed as a hero heading above the content">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Introductory Text</label>
                        <textarea name="page_intro" class="form-control" rows="3"
                                  placeholder="Optional short description shown below the heading"><?= old('page_intro') ?></textarea>
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-medium">Meta Description</label>
                        <textarea name="meta_description" class="form-control" rows="2"
                                  maxlength="500"><?= old('meta_description') ?></textarea>
                    </div>
                </div>
            </div>

            <!-- ── GENERAL: GrapesJS page builder ── -->
            <div id="panel_general" class="cat-panel mb-4">
                <div class="card">
                    <div class="card-header py-3 px-4 d-flex align-items-center gap-3">
                        <h6 class="mb-0 fw-semibold flex-grow-1"><i class="fas fa-columns me-2 text-muted"></i>Page Builder (Drag &amp; Drop)</h6>
                        <span class="badge bg-primary-subtle text-primary" style="font-size:.75rem;">GrapesJS</span>
                    </div>
                    <div class="card-body p-0" style="overflow:hidden;border-radius:0 0 .75rem .75rem;">
                        <div id="gjs-wrapper" style="height:750px;position:relative;">
                            <div id="gjs" style="height:100%;"></div>
                        </div>
                    </div>
                </div>
                <input type="hidden" name="gjs_html" id="gjs_html">
                <input type="hidden" name="gjs_css"  id="gjs_css">
                <input type="hidden" name="gjs_data" id="gjs_data">
            </div>

            <!-- ── PROFILE: intro only – profiles managed separately ── -->
            <div id="panel_profile" class="cat-panel card mb-4" style="display:none;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-id-card me-2 text-muted"></i>Profile Page Settings</h6>
                </div>
                <div class="card-body p-4">
                    <div class="mb-0">
                        <label class="form-label fw-medium">Subtitle / Badge Label</label>
                        <input type="text" name="profile_subtitle" class="form-control"
                               value="<?= old('profile_subtitle','Leadership') ?>" maxlength="150"
                               placeholder="e.g. Leadership, Governance, Administration">
                        <div class="form-text">Shown as a small coloured label above the section title.</div>
                    </div>
                    <div class="alert alert-info mt-4 mb-0" style="border-radius:10px;">
                        <i class="fas fa-info-circle me-2"></i>
                        After saving, you will be taken to the <strong>Profile Members</strong> manager
                        where you can add, edit, and reorder the people displayed on this page.
                    </div>
                </div>
            </div>

            <!-- ── POLICY: TinyMCE rich text ── -->
            <div id="panel_policy" class="cat-panel card mb-4" style="display:none;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-file-contract me-2 text-muted"></i>Policy Content</h6>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Policy Type / Name</label>
                            <input type="text" name="policy_type" class="form-control"
                                   value="<?= old('policy_type') ?>" maxlength="100"
                                   placeholder="e.g. Privacy Policy, Terms of Use…">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Effective Date</label>
                            <input type="date" name="effective_date" class="form-control"
                                   value="<?= old('effective_date') ?>">
                        </div>
                    </div>
                    <label class="form-label fw-medium">Policy Body</label>
                    <textarea id="policy_content" name="content" class="form-control" rows="18"><?= h(old('content','')) ?></textarea>
                </div>
            </div>

        </div>

        <!-- ── RIGHT COLUMN ── -->
        <div class="col-lg-4">

            <div class="card mb-4">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-cog me-2 text-muted"></i>Publish</h6>
                </div>
                <div class="card-body p-4">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="is_published" name="is_published"
                               value="1" <?= old('is_published') ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="is_published">Publish immediately</label>
                    </div>
                    <div class="mb-3" id="pubDateWrap">
                        <label class="form-label fw-medium">Publish Date &amp; Time</label>
                        <input type="datetime-local" name="published_at" class="form-control"
                               value="<?= date('Y-m-d\TH:i') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control"
                               value="<?= old('sort_order', '0') ?>" min="0" style="width:100px;">
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i> Save Page
                        </button>
                        <a href="<?= APP_URL ?>/pages/index.php" class="btn btn-light" style="border-radius:10px;">
                            Cancel
                        </a>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-image me-2 text-muted"></i>Featured Image</h6>
                </div>
                <div class="card-body p-4">
                    <input type="file" name="featured_image" class="form-control"
                           accept=".jpg,.jpeg,.png,.gif,.webp">
                    <div class="form-text mt-2">JPG, PNG, GIF, WebP. Used as the page banner/hero image.</div>
                </div>
            </div>

        </div>
    </div>
</form>

<!-- GrapesJS -->
<link rel="stylesheet" href="https://unpkg.com/grapesjs@0.21.13/dist/css/grapes.min.css">
<script src="https://unpkg.com/grapesjs@0.21.13/dist/grapes.min.js"></script>
<script src="https://unpkg.com/grapesjs-preset-webpage@1.0.3/dist/index.js"></script>
<style>
/* ── GrapesJS admin panel integration ───────────────────────────────── */
#gjs-wrapper .gjs-editor { border: none; }
#gjs-wrapper .gjs-pn-panels { border-bottom: 1px solid #dee2e6; }
#gjs-wrapper .gjs-pn-panel { padding: 4px; }
#gjs-wrapper .gjs-pn-commands { flex: 1; }
#gjs-wrapper .gjs-sm-sector-title { background: #f8f9fa; border-bottom: 1px solid #e9ecef; font-weight: 600; font-size: .8rem; letter-spacing: .05em; text-transform: uppercase; padding: 8px 14px; }
#gjs-wrapper .gjs-sm-properties { padding: 8px 10px; }
#gjs-wrapper .gjs-block { border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,.08); transition: box-shadow .15s; }
#gjs-wrapper .gjs-block:hover { box-shadow: 0 4px 12px rgba(0,0,0,.14); }
#gjs-wrapper .gjs-cv-canvas { background: #f1f3f5; }
</style>

<!-- TinyMCE -->
<script src="https://cdn.jsdelivr.net/npm/tinymce@5.10.9/tinymce.min.js" referrerpolicy="origin"></script>

<script>
(function () {
    // ── Category panel switching ──────────────────────────────────────────────
    var catSelect  = document.getElementById('catSelect');
    var panels     = { general: document.getElementById('panel_general'),
                       profile: document.getElementById('panel_profile'),
                       policy:  document.getElementById('panel_policy') };

    function showPanel(cat) {
        Object.keys(panels).forEach(function(k) {
            panels[k].style.display = (k === cat) ? '' : 'none';
        });
        if (cat === 'policy') initTiny(); else destroyTiny();
        if (cat === 'general') initGjs(); else destroyGjs();
    }

    catSelect.addEventListener('change', function () { showPanel(this.value); });
    showPanel(catSelect.value);

    // ── Publish date visibility ───────────────────────────────────────────────
    var pubChk  = document.getElementById('is_published');
    var pubWrap = document.getElementById('pubDateWrap');
    function togglePubDate() { pubWrap.style.display = pubChk.checked ? '' : 'none'; }
    pubChk.addEventListener('change', togglePubDate);
    togglePubDate();

    // ── TinyMCE ──────────────────────────────────────────────────────────────
    var tinyInited = false;
    function initTiny() {
        if (tinyInited) return;
        tinyInited = true;
        tinymce.init({
            selector: '#policy_content',
            height: 520,
            menubar: true,
            plugins: 'advlist autolink lists link image charmap preview anchor ' +
                     'searchreplace visualblocks code fullscreen table help wordcount',
            toolbar: 'undo redo | blocks | bold italic underline | ' +
                     'alignleft aligncenter alignright alignjustify | ' +
                     'bullist numlist outdent indent | link image | code fullscreen',
            content_style: 'body { font-family: Inter, sans-serif; font-size: 15px; }',
        });
    }
    function destroyTiny() {
        if (!tinyInited) return;
        tinymce.remove('#policy_content');
        tinyInited = false;
    }

    // ── GrapesJS ─────────────────────────────────────────────────────────────
    var gjsEditor = null;
    function initGjs() {
        if (gjsEditor) return;
        gjsEditor = grapesjs.init({
            container: '#gjs',
            fromElement: false,
            height: '100%',
            storageManager: false,
            plugins: ['grapesjs-preset-webpage'],
            pluginsOpts: {
                'grapesjs-preset-webpage': {
                    blocksBasicOpts: {
                        blocks: ['column1','column2','column3','column3-7','text','link','image','video','map'],
                        flexGrid: true,
                    },
                    navbar: false,
                    countdownOpts: false,
                    formsOpts: { blocks: ['form','input','textarea','select','button','label','checkbox','radio'] },
                }
            },
            styleManager: {
                sectors: [
                    {
                        name: 'Layout',
                        open: true,
                        buildProps: ['display','position','float'],
                    },
                    {
                        name: 'Dimensions',
                        open: false,
                        buildProps: ['width','height','max-width','min-height','margin','padding'],
                    },
                    {
                        name: 'Typography',
                        open: false,
                        buildProps: ['font-family','font-size','font-weight','letter-spacing','color','line-height','text-align','text-decoration','text-shadow'],
                    },
                    {
                        name: 'Decorations',
                        open: false,
                        buildProps: ['opacity','background-color','border-radius','border','box-shadow','background'],
                    },
                    {
                        name: 'Extra',
                        open: false,
                        buildProps: ['transition','transform'],
                    },
                ],
            },
            deviceManager: {
                devices: [
                    { name: 'Desktop', width: '' },
                    { name: 'Tablet', width: '768px', widthMedia: '992px' },
                    { name: 'Mobile', width: '375px', widthMedia: '480px' },
                ],
            },
            canvas: {
                styles: ['https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css'],
            },
        });
    }
    function destroyGjs() {
        if (!gjsEditor) return;
        gjsEditor.destroy();
        gjsEditor = null;
    }

    // Before form submit – extract GrapesJS content
    document.getElementById('pageForm').addEventListener('submit', function () {
        if (gjsEditor) {
            document.getElementById('gjs_html').value = gjsEditor.getHtml();
            document.getElementById('gjs_css').value  = gjsEditor.getCss();
            document.getElementById('gjs_data').value = JSON.stringify(gjsEditor.getComponents());
        }
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
