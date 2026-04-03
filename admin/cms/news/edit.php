<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$id     = (int)($_GET['id'] ?? 0);
$news   = null;
$errors = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM cms_news WHERE id = ?');
    $stmt->execute([$id]);
    $news = $stmt->fetch();
}
if (!$news) {
    flash_set('error', 'News article not found.');
    redirect(APP_URL . '/cms/news/index.php');
}

clear_old();
$page_title = 'Edit Article';

// Load attachments
$attachments = db()->prepare('SELECT * FROM cms_news_attachments WHERE news_id = ? ORDER BY id');
$attachments->execute([$id]);
$attachments = $attachments->fetchAll();

// ── Helpers ───────────────────────────────────────────────────────────────────
const IMG_EXTS_E    = ['jpg','jpeg','png','gif','webp'];
const ATTACH_EXTS_E = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx','ppt','pptx','zip','txt'];
const IMG_MIMES_E   = ['image/jpeg','image/png','image/gif','image/webp'];
const ATTACH_MIMES_E = [
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

function cms_slug_e(string $title): string {
    $slug = mb_strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    return trim($slug, '-') ?: 'untitled';
}

function unique_news_slug_e(string $base, int $exclude_id = 0): string {
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

function upload_file_e(array $file, string $subdir, array $allowed_exts, array $allowed_mimes): string|false {
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

    // Featured image upload (optional replacement) or removal
    $featured_image = $news['featured_image'];
    if (!empty($_FILES['featured_image']['name'])) {
        $result = upload_file_e($_FILES['featured_image'], 'news', IMG_EXTS_E, IMG_MIMES_E);
        if ($result === false) {
            $errors[] = 'Featured image: invalid file. Allowed types: JPG, PNG, GIF, WebP.';
        } else {
            // Delete old featured image
            if ($featured_image && file_exists(UPLOAD_DIR . '/news/' . $featured_image)) {
                @unlink(UPLOAD_DIR . '/news/' . $featured_image);
            }
            $featured_image = $result;
        }
    } elseif (isset($_POST['remove_featured'])) {
        if ($featured_image && file_exists(UPLOAD_DIR . '/news/' . $featured_image)) {
            @unlink(UPLOAD_DIR . '/news/' . $featured_image);
        }
        $featured_image = null;
    }

    if (empty($errors)) {
        // Regenerate slug only if title changed
        $slug = $news['slug'];
        if ($title !== $news['title']) {
            $slug = unique_news_slug_e(cms_slug_e($title), $id);
        }

        db()->prepare(
            'UPDATE cms_news
             SET title=?, slug=?, content=?, content_type=?, featured_image=?, is_published=?, show_in_ticker=?, published_at=?
             WHERE id=?'
        )->execute([$title, $slug, $content, $content_type, $featured_image, $is_published, $show_in_ticker, $published_at, $id]);

        // Process new attachments
        $db = db();
        if (!empty($_FILES['attachments']['name'][0])) {
            foreach ($_FILES['attachments']['name'] as $k => $fname) {
                if ($_FILES['attachments']['error'][$k] !== UPLOAD_ERR_OK) continue;
                $file = [
                    'name'     => $fname,
                    'tmp_name' => $_FILES['attachments']['tmp_name'][$k],
                    'error'    => $_FILES['attachments']['error'][$k],
                    'size'     => $_FILES['attachments']['size'][$k],
                ];
                $stored = upload_file_e($file, 'news', ATTACH_EXTS_E, ATTACH_MIMES_E);
                if ($stored) {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime  = $finfo->file(UPLOAD_DIR . '/news/' . $stored);
                    $db->prepare(
                        'INSERT INTO cms_news_attachments (news_id, original_name, stored_name, mime_type, size)
                         VALUES (?,?,?,?,?)'
                    )->execute([$id, $fname, $stored, $mime, $file['size']]);
                }
            }
        }

        flash_set('success', 'Article <strong>' . h($title) . '</strong> updated.');
        redirect(APP_URL . '/cms/news/edit.php?id=' . $id);
    }

    $news = array_merge($news, compact('title','content','content_type','is_published','show_in_ticker','featured_image'));
    $news['published_at'] = $_POST['published_at'] ?? $news['published_at'];
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/cms/news/index.php">Latest News</a></li>
            <li class="breadcrumb-item active">Edit</li>
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
                               value="<?= h($news['title']) ?>" required maxlength="500">
                    </div>

                    <!-- Content type toggle -->
                    <div class="mb-3 d-flex align-items-center gap-3">
                        <label class="form-label fw-medium mb-0">Content</label>
                        <div class="btn-group btn-group-sm" role="group">
                            <input type="radio" class="btn-check" name="content_type" id="ct_html" value="html"
                                   <?= $news['content_type'] === 'html' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-primary" for="ct_html">
                                <i class="fas fa-code me-1"></i>HTML / Rich Text
                            </label>
                            <input type="radio" class="btn-check" name="content_type" id="ct_text" value="text"
                                   <?= $news['content_type'] === 'text' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-secondary" for="ct_text">
                                <i class="fas fa-align-left me-1"></i>Plain Text
                            </label>
                        </div>
                    </div>

                    <textarea id="news_content" name="content" class="form-control"
                              rows="16"><?= h($news['content'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- Existing attachments -->
            <?php if ($attachments): ?>
            <div class="card mb-4">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-paperclip me-2 text-muted"></i>Existing Attachments</h6>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="px-4">File</th>
                                <th>Type</th>
                                <th>Size</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($attachments as $att): ?>
                        <tr>
                            <td class="px-4">
                                <?php
                                $img_mimes = ['image/jpeg','image/png','image/gif','image/webp'];
                                if (in_array($att['mime_type'], $img_mimes)):
                                ?>
                                <a href="<?= UPLOAD_URL ?>/news/<?= h($att['stored_name']) ?>" target="_blank">
                                    <img src="<?= UPLOAD_URL ?>/news/<?= h($att['stored_name']) ?>"
                                         style="height:36px;border-radius:4px;object-fit:cover;" alt="">
                                </a>
                                <?php else: ?>
                                <a href="<?= UPLOAD_URL ?>/news/<?= h($att['stored_name']) ?>" target="_blank">
                                    <i class="fas fa-file me-1"></i><?= h($att['original_name']) ?>
                                </a>
                                <?php endif; ?>
                            </td>
                            <td><code style="font-size:.75rem;"><?= h($att['mime_type']) ?></code></td>
                            <td><?= number_format($att['size'] / 1024, 1) ?> KB</td>
                            <td>
                                <form method="POST" action="<?= APP_URL ?>/cms/news/delete-attachment.php"
                                      onsubmit="return confirm('Delete this attachment?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id"      value="<?= $att['id'] ?>">
                                    <input type="hidden" name="news_id" value="<?= $id ?>">
                                    <button class="btn btn-sm btn-outline-danger" style="border-radius:7px;" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- New attachments -->
            <div class="card">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-plus me-2 text-muted"></i>Add Attachments</h6>
                </div>
                <div class="card-body p-4">
                    <input type="file" name="attachments[]" class="form-control" multiple
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
                               value="1" <?= $news['is_published'] ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="is_published">Published</label>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="show_in_ticker" name="show_in_ticker"
                               value="1" <?= !empty($news['show_in_ticker']) ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="show_in_ticker">
                            <i class="fas fa-bullhorn me-1 text-warning"></i>Visible in News Ticker
                        </label>
                    </div>
                    <div class="mb-3" id="pubDateWrap">
                        <label class="form-label fw-medium">Publish Date &amp; Time</label>
                        <?php
                        $pub_val = $news['published_at']
                            ? date('Y-m-d\TH:i', strtotime($news['published_at']))
                            : date('Y-m-d\TH:i');
                        ?>
                        <input type="datetime-local" name="published_at" class="form-control"
                               value="<?= h($pub_val) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium text-muted" style="font-size:.8rem;">Slug</label>
                        <code style="display:block;font-size:.78rem;word-break:break-all;"><?= h($news['slug']) ?></code>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i> Update Article
                        </button>
                        <a href="<?= APP_URL ?>/cms/news/index.php" class="btn btn-light" style="border-radius:10px;">
                            Cancel
                        </a>
                    </div>
                </div>
            </div>

            <!-- Featured image -->
            <div class="card">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-image me-2 text-muted"></i>Featured Image</h6>
                </div>
                <div class="card-body p-4">
                    <?php if ($news['featured_image']): ?>
                    <img src="<?= UPLOAD_URL ?>/news/<?= h($news['featured_image']) ?>"
                         class="img-fluid rounded mb-3" alt="Featured image"
                         style="max-height:180px;object-fit:cover;width:100%;"
                         onerror="this.style.display='none'">
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="remove_featured" id="rm_feat" value="1">
                        <label class="form-check-label text-danger" for="rm_feat">Remove featured image</label>
                    </div>
                    <?php endif; ?>
                    <input type="file" name="featured_image" class="form-control"
                           accept=".jpg,.jpeg,.png,.gif,.webp">
                    <div class="form-text mt-2">JPG, PNG, GIF, WebP. Upload to replace current image.</div>
                </div>
            </div>

        </div>
    </div>
</form>

<!-- Handle "remove featured image" checkbox -->
<script>
document.querySelector('form').addEventListener('submit', function () {
    var rmChk = document.querySelector('[name="remove_featured"]');
    // Nothing extra needed - the server reads the checkbox
});
</script>

<!-- TinyMCE -->
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
