<?php
require_once __DIR__ . '/../includes/auth.php';
require_super_admin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { flash_set('error', 'Invalid page.'); redirect(APP_URL . '/pages/index.php'); }

$page = db()->prepare('SELECT * FROM pages WHERE id = ?');
$page->execute([$id]);
$page = $page->fetch();
if (!$page) { flash_set('error', 'Page not found.'); redirect(APP_URL . '/pages/index.php'); }

$page_title = 'Edit Page: ' . $page['title'];
$errors     = [];
clear_old();

// ── Helpers (same as create.php) ──────────────────────────────────────────────
function edit_pages_slug(string $title): string {
    $s = mb_strtolower(trim($title));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-') ?: 'untitled';
}

function edit_unique_pages_slug(string $base, int $exclude_id = 0): string {
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

function edit_upload_page_image(array $file): string|false {
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

    $title            = trim($_POST['title']            ?? '');
    $category         = in_array($_POST['category'] ?? '', ['general','profile','policy']) ? $_POST['category'] : 'general';
    $page_heading     = trim($_POST['page_heading']     ?? '');
    $page_intro       = trim($_POST['page_intro']       ?? '');
    $meta_description = trim($_POST['meta_description'] ?? '');
    $is_published     = isset($_POST['is_published']) ? 1 : 0;
    $published_at     = $is_published ? (trim($_POST['published_at'] ?? '') ?: date('Y-m-d H:i:s')) : null;
    $sort_order       = (int)($_POST['sort_order'] ?? 0);

    $gjs_html        = ($category === 'general') ? ($_POST['gjs_html']  ?? '') : null;
    $gjs_css         = ($category === 'general') ? ($_POST['gjs_css']   ?? '') : null;
    $gjs_data        = ($category === 'general') ? ($_POST['gjs_data']  ?? '') : null;
    $content         = ($category === 'policy')  ? ($_POST['content']   ?? '') : null;
    $policy_type     = ($category === 'policy')  ? trim($_POST['policy_type']   ?? '') : null;
    $effective_date  = ($category === 'policy')  ? (trim($_POST['effective_date'] ?? '') ?: null) : null;
    $profile_subtitle= ($category === 'profile') ? trim($_POST['profile_subtitle'] ?? '') : null;

    if ($title === '') $errors[] = 'Title is required.';

    // Featured image
    $featured_image = $page['featured_image'];
    if (!empty($_FILES['featured_image']['name'])) {
        $result = edit_upload_page_image($_FILES['featured_image']);
        if ($result === false) {
            $errors[] = 'Featured image: invalid file. Allowed: JPG, PNG, GIF, WebP.';
        } else {
            // delete old image
            if ($featured_image) {
                $old_path = UPLOAD_DIR . '/pages/' . $featured_image;
                if (is_file($old_path)) @unlink($old_path);
            }
            $featured_image = $result;
        }
    }
    // Allow removing image
    if (isset($_POST['remove_image']) && $_POST['remove_image'] === '1') {
        if ($featured_image) {
            $old_path = UPLOAD_DIR . '/pages/' . $featured_image;
            if (is_file($old_path)) @unlink($old_path);
        }
        $featured_image = null;
    }

    if (empty($errors)) {
        $slug = edit_unique_pages_slug(edit_pages_slug($title), $id);

        db()->prepare(
            'UPDATE pages SET
             title=?, slug=?, category=?, page_heading=?, page_intro=?, meta_description=?,
             featured_image=?, gjs_html=?, gjs_css=?, gjs_data=?, content=?,
             policy_type=?, effective_date=?, profile_subtitle=?,
             is_published=?, published_at=?, sort_order=?
             WHERE id=?'
        )->execute([
            $title, $slug, $category, $page_heading ?: null, $page_intro ?: null,
            $meta_description ?: null, $featured_image,
            $gjs_html, $gjs_css, $gjs_data, $content,
            $policy_type ?: null, $effective_date, $profile_subtitle ?: null,
            $is_published, $published_at, $sort_order,
            $id,
        ]);

        flash_set('success', 'Page <strong>' . h($title) . '</strong> updated.');
        redirect(APP_URL . '/pages/index.php');
    }

    save_old(compact('title','category','page_heading','page_intro','meta_description',
                      'is_published','sort_order','policy_type','effective_date','profile_subtitle'));
    // preserve submitted content after errors
    $page['gjs_html']  = $gjs_html;
    $page['gjs_css']   = $gjs_css;
    $page['gjs_data']  = $gjs_data;
    $page['content']   = $content ?? $page['content'];
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/index.php">Pages</a></li>
            <li class="breadcrumb-item active">Edit: <?= h($page['title']) ?></li>
        </ol>
    </nav>
    <?php if ($page['category'] === 'profile'): ?>
    <a href="<?= APP_URL ?>/pages/profiles/index.php?page_id=<?= $id ?>"
       class="btn btn-outline-secondary" style="border-radius:10px;font-size:.875rem;">
        <i class="fas fa-users me-1"></i> Manage Profiles
    </a>
    <?php endif; ?>
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
    <input type="hidden" name="remove_image" id="remove_image" value="0">

    <div class="row g-4">
        <!-- ── LEFT COLUMN ── -->
        <div class="col-lg-8">

            <div class="card mb-4">
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control form-control-lg"
                               value="<?= old('title', $page['title']) ?>" required maxlength="255">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Category <span class="text-danger">*</span></label>
                        <select name="category" id="catSelect" class="form-select" style="border-radius:10px;">
                            <option value="general" <?= (old('category',$page['category'])) === 'general' ? 'selected' : '' ?>>
                                🧩 General – drag &amp; drop page builder
                            </option>
                            <option value="profile" <?= (old('category',$page['category'])) === 'profile' ? 'selected' : '' ?>>
                                👤 Profile – person / leadership listing
                            </option>
                            <option value="policy"  <?= (old('category',$page['category'])) === 'policy'  ? 'selected' : '' ?>>
                                📄 Policy – policy &amp; procedure document
                            </option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Banner / Section Heading</label>
                        <input type="text" name="page_heading" class="form-control"
                               value="<?= old('page_heading', $page['page_heading'] ?? '') ?>" maxlength="255">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Introductory Text</label>
                        <textarea name="page_intro" class="form-control" rows="3"><?= old('page_intro', $page['page_intro'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-medium">Meta Description</label>
                        <textarea name="meta_description" class="form-control" rows="2" maxlength="500"><?= old('meta_description', $page['meta_description'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <!-- ── GENERAL: GrapesJS ── -->
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
                <input type="hidden" name="gjs_html" id="gjs_html" value="<?= h($page['gjs_html'] ?? '') ?>">
                <input type="hidden" name="gjs_css"  id="gjs_css"  value="<?= h($page['gjs_css']  ?? '') ?>">
                <input type="hidden" name="gjs_data" id="gjs_data" value="<?= h($page['gjs_data'] ?? '') ?>">
            </div>

            <!-- ── PROFILE ── -->
            <div id="panel_profile" class="cat-panel card mb-4" style="display:none;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-id-card me-2 text-muted"></i>Profile Page Settings</h6>
                </div>
                <div class="card-body p-4">
                    <div class="mb-0">
                        <label class="form-label fw-medium">Subtitle / Badge Label</label>
                        <input type="text" name="profile_subtitle" class="form-control"
                               value="<?= old('profile_subtitle', $page['profile_subtitle'] ?? 'Leadership') ?>" maxlength="150">
                    </div>
                    <div class="alert alert-info mt-4 mb-0" style="border-radius:10px;">
                        <i class="fas fa-info-circle me-2"></i>
                        Use the <strong><a href="<?= APP_URL ?>/pages/profiles/index.php?page_id=<?= $id ?>">Profile Members</a></strong>
                        manager to add, edit, and reorder people displayed on this page.
                    </div>
                </div>
            </div>

            <!-- ── POLICY ── -->
            <div id="panel_policy" class="cat-panel card mb-4" style="display:none;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-file-contract me-2 text-muted"></i>Policy Content</h6>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Policy Type / Name</label>
                            <input type="text" name="policy_type" class="form-control"
                                   value="<?= old('policy_type', $page['policy_type'] ?? '') ?>" maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Effective Date</label>
                            <input type="date" name="effective_date" class="form-control"
                                   value="<?= old('effective_date', $page['effective_date'] ?? '') ?>">
                        </div>
                    </div>
                    <label class="form-label fw-medium">Policy Body</label>
                    <textarea id="policy_content" name="content" class="form-control" rows="18"><?= h(old('content', $page['content'] ?? '')) ?></textarea>
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
                    <?php $is_pub_checked = old('is_published') !== '' ? (bool)old('is_published') : (bool)$page['is_published']; ?>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="is_published" name="is_published"
                               value="1" <?= $is_pub_checked ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="is_published">Published</label>
                    </div>
                    <div class="mb-3" id="pubDateWrap">
                        <label class="form-label fw-medium">Publish Date &amp; Time</label>
                        <input type="datetime-local" name="published_at" class="form-control"
                               value="<?= $page['published_at'] ? date('Y-m-d\TH:i', strtotime($page['published_at'])) : date('Y-m-d\TH:i') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control"
                               value="<?= old('sort_order', (string)$page['sort_order']) ?>" min="0" style="width:100px;">
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i> Update Page
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
                    <?php if ($page['featured_image']): ?>
                    <div class="mb-3" id="currentImgWrap">
                        <img src="<?= UPLOAD_URL ?>/pages/<?= h($page['featured_image']) ?>"
                             style="width:100%;border-radius:8px;object-fit:cover;max-height:180px;" alt="">
                        <div class="mt-2">
                            <button type="button" class="btn btn-sm btn-outline-danger" style="border-radius:7px;"
                                    onclick="document.getElementById('remove_image').value='1';
                                             document.getElementById('currentImgWrap').style.display='none';">
                                <i class="fas fa-times me-1"></i>Remove image
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                    <input type="file" name="featured_image" class="form-control"
                           accept=".jpg,.jpeg,.png,.gif,.webp">
                    <div class="form-text mt-2">JPG, PNG, GIF, WebP. Leave blank to keep current image.</div>
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
    var catSelect = document.getElementById('catSelect');
    var panels    = { general: document.getElementById('panel_general'),
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

    var pubChk  = document.getElementById('is_published');
    var pubWrap = document.getElementById('pubDateWrap');
    function togglePubDate() { pubWrap.style.display = pubChk.checked ? '' : 'none'; }
    pubChk.addEventListener('change', togglePubDate);
    togglePubDate();

    // TinyMCE
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

    // GrapesJS
    var gjsEditor = null;
    var gjsInitialHtml = <?= json_encode($page['gjs_html'] ?? '') ?>;
    var gjsInitialCss  = <?= json_encode($page['gjs_css']  ?? '') ?>;

    function initGjs() {
        if (gjsEditor) return;
        gjsEditor = grapesjs.init({
            container: '#gjs',
            fromElement: false,
            height: '100%',
            storageManager: false,
            plugins: ['gjs-preset-webpage'],
            pluginsOpts: {
                'gjs-preset-webpage': {
                    blocksBasicOpts: {
                        blocks: ['column1','column2','column3','column3-7','text','link','image','video','map'],
                        flexGrid: true,
                    },
                    navbar: false,
                    countdownOpts: false,
                    formsOpts: { blocks: ['form','input','textarea','select','button','label','checkbox','radio'] },
                }
            },
            components: gjsInitialHtml || '',
            style:      gjsInitialCss  || '',
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
