<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$page_title = 'New News Article';
$errors     = [];
clear_old();

// ── Allowed file types ────────────────────────────────────────────────────────
const IMG_EXTS   = ['jpg','jpeg','png','gif','webp'];
const ATTACH_EXTS = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx','ppt','pptx','zip','txt'];
const IMG_MIMES  = ['image/jpeg','image/png','image/gif','image/webp'];
const ATTACH_MIMES = [
    'image/jpeg','image/png','image/gif','image/webp',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'application/zip','application/x-zip-compressed',
    'text/plain',
];

function cms_slug(string $title): string {
    $slug = mb_strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    return trim($slug, '-') ?: 'untitled';
}

function unique_news_slug(string $base, int $exclude_id = 0): string {
    $slug = $base;
    $i    = 2;
    $db   = db();
    while (true) {
        $st = $db->prepare('SELECT id FROM cms_news WHERE slug = ? AND id != ?');
        $st->execute([$slug, $exclude_id]);
        if (!$st->fetch()) break;
        $slug = $base . '-' . $i++;
    }
    return $slug;
}

function upload_file(array $file, string $subdir, array $allowed_exts, array $allowed_mimes): string|false {
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_exts, true)) return false;
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed_mimes, true)) return false;
    $dir  = UPLOAD_DIR . '/' . $subdir;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return false;
    return $name;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $title        = trim($_POST['title']        ?? '');
    $content      = $_POST['content']           ?? '';
    $content_type = in_array($_POST['content_type'] ?? '', ['html','text']) ? $_POST['content_type'] : 'html';
    $is_published = isset($_POST['is_published']) ? 1 : 0;
    $show_in_ticker = isset($_POST['show_in_ticker']) ? 1 : 0;
    $published_at = $is_published ? (trim($_POST['published_at'] ?? '') ?: date('Y-m-d H:i:s')) : null;

    if ($title === '') $errors[] = 'Title is required.';

    // Featured image upload
    $featured_image = null;
    if (!empty($_FILES['featured_image']['name'])) {
        $result = upload_file($_FILES['featured_image'], 'news', IMG_EXTS, IMG_MIMES);
        if ($result === false) {
            $errors[] = 'Featured image: invalid file. Allowed types: JPG, PNG, GIF, WebP.';
        } else {
            $featured_image = $result;
        }
    }

    if (empty($errors)) {
        $slug = unique_news_slug(cms_slug($title));

        $db = db();
        $db->prepare(
            'INSERT INTO cms_news (title, slug, content, content_type, featured_image, is_published, show_in_ticker, published_at)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$title, $slug, $content, $content_type, $featured_image, $is_published, $show_in_ticker, $published_at]);

        $news_id = (int)$db->lastInsertId();

        // Process attachments
        if (!empty($_FILES['attachments']['name'][0])) {
            foreach ($_FILES['attachments']['name'] as $k => $fname) {
                if ($_FILES['attachments']['error'][$k] !== UPLOAD_ERR_OK) continue;
                $file = [
                    'name'     => $fname,
                    'tmp_name' => $_FILES['attachments']['tmp_name'][$k],
                    'error'    => $_FILES['attachments']['error'][$k],
                    'size'     => $_FILES['attachments']['size'][$k],
                ];
                $stored = upload_file($file, 'news', ATTACH_EXTS, ATTACH_MIMES);
                if ($stored) {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime  = $finfo->file(UPLOAD_DIR . '/news/' . $stored);
                    $db->prepare(
                        'INSERT INTO cms_news_attachments (news_id, original_name, stored_name, mime_type, size)
                         VALUES (?,?,?,?,?)'
                    )->execute([$news_id, $fname, $stored, $mime, $file['size']]);
                }
            }
        }

        flash_set('success', 'Article <strong>' . h($title) . '</strong> created.');
        redirect(APP_URL . '/cms/news/index.php');
    }

    save_old(compact('title','content_type','is_published','show_in_ticker'));
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/cms/news/index.php">Latest News</a></li>
            <li class="breadcrumb-item active">New Article</li>
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

<form method="POST" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>

    <div class="row g-4">
        <!-- Left column: content -->
        <div class="col-lg-8">

            <div class="card mb-4">
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control form-control-lg"
                               value="<?= old('title') ?>" required placeholder="Article title…" maxlength="500">
                    </div>

                    <!-- Content type toggle -->
                    <div class="mb-3 d-flex align-items-center gap-3">
                        <label class="form-label fw-medium mb-0">Content</label>
                        <div class="btn-group btn-group-sm" role="group">
                            <input type="radio" class="btn-check" name="content_type" id="ct_html" value="html"
                                   <?= old('content_type','html') === 'html' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-primary" for="ct_html">
                                <i class="fas fa-code me-1"></i>HTML / Rich Text
                            </label>
                            <input type="radio" class="btn-check" name="content_type" id="ct_text" value="text"
                                   <?= old('content_type','html') === 'text' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-secondary" for="ct_text">
                                <i class="fas fa-align-left me-1"></i>Plain Text
                            </label>
                        </div>
                    </div>

                    <!-- Single textarea used for both modes; TinyMCE attaches when HTML selected -->
                    <textarea id="news_content" name="content" class="form-control"
                              rows="16" style="font-family:monospace;"><?= h(old('content','')) ?></textarea>
                </div>
            </div>

            <!-- Attachments -->
            <div class="card">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-paperclip me-2 text-muted"></i>Attachments</h6>
                </div>
                <div class="card-body p-4">
                    <input type="file" name="attachments[]" id="attachments" class="form-control" multiple
                           accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.txt">
                    <div class="form-text mt-2">
                        Allowed: images, PDF, Word, Excel, PowerPoint, ZIP, TXT. Multiple files accepted.
                    </div>
                </div>
            </div>

        </div>

        <!-- Right column: settings -->
        <div class="col-lg-4">

            <div class="card mb-4">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-cog me-2 text-muted"></i>Publish</h6>
                </div>
                <div class="card-body p-4">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="is_published" name="is_published"
                               value="1" <?= old('is_published') ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="is_published">Publish</label>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="show_in_ticker" name="show_in_ticker"
                               value="1" <?= old('show_in_ticker') ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="show_in_ticker">
                            <i class="fas fa-bullhorn me-1 text-warning"></i>Visible in News Ticker
                        </label>
                    </div>
                    <div class="mb-3" id="pubDateWrap">
                        <label class="form-label fw-medium">Publish Date &amp; Time</label>
                        <input type="datetime-local" name="published_at" class="form-control"
                               value="<?= date('Y-m-d\TH:i') ?>">
                        <div class="form-text">Leave blank to use current time.</div>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i> Save Article
                        </button>
                        <a href="<?= APP_URL ?>/cms/news/index.php" class="btn btn-light" style="border-radius:10px;">
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
                    <div class="form-text mt-2">JPG, PNG, GIF, WebP. Recommended 1200×630 px.</div>
                </div>
            </div>

        </div>
    </div>
</form>

<!-- TinyMCE via jsDelivr (no API key required for self-hosted/CDN bundle) -->
<script src="https://cdn.jsdelivr.net/npm/tinymce@5.10.9/tinymce.min.js" referrerpolicy="origin"></script>
<script>
(function () {
    var htmlRadio = document.getElementById('ct_html');
    var textRadio = document.getElementById('ct_text');
    var ta        = document.getElementById('news_content');
    var editorInit = false;

    function initTiny() {
        if (editorInit) return;
        editorInit = true;
        tinymce.init({
            selector: '#news_content',
            height: 480,
            menubar: true,
            plugins: 'advlist autolink lists link image charmap preview anchor ' +
                     'searchreplace visualblocks code fullscreen table help wordcount',
            toolbar: 'undo redo | blocks | bold italic underline strikethrough | ' +
                     'alignleft aligncenter alignright alignjustify | ' +
                     'bullist numlist outdent indent | removeformat | link image | code fullscreen',
            content_style: 'body { font-family: Inter, sans-serif; font-size: 15px; }',
        });
    }

    function destroyTiny() {
        if (!editorInit) return;
        tinymce.remove('#news_content');
        editorInit = false;
    }

    function applyMode() {
        if (htmlRadio.checked) {
            ta.style.fontFamily = '';
            initTiny();
        } else {
            destroyTiny();
            ta.style.fontFamily = 'monospace';
        }
    }

    htmlRadio.addEventListener('change', applyMode);
    textRadio.addEventListener('change', applyMode);
    applyMode();

    // Publish date visibility
    var pubChk  = document.getElementById('is_published');
    var pubWrap = document.getElementById('pubDateWrap');
    function togglePubDate() { pubWrap.style.display = pubChk.checked ? '' : 'none'; }
    pubChk.addEventListener('change', togglePubDate);
    togglePubDate();
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
