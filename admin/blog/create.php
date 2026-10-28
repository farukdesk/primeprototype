<?php
/**
 * Blog — create post.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

auth_check();
blog_ensure_schema();
require_access('blog', 'can_create');

$page_title = 'New Blog Post';
$errors     = [];
$cats       = blog_categories();
clear_old();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $title          = trim($_POST['title'] ?? '');
    $excerpt        = trim($_POST['excerpt'] ?? '');
    $content        = $_POST['content'] ?? '';
    $content_type   = in_array($_POST['content_type'] ?? '', ['html', 'text'], true) ? $_POST['content_type'] : 'html';
    $category_id    = (int)($_POST['category_id'] ?? 0) ?: null;
    $tags           = trim($_POST['tags'] ?? '');
    $is_published   = isset($_POST['is_published']) ? 1 : 0;
    $is_pinned      = isset($_POST['is_pinned']) ? 1 : 0;
    $published_at   = $is_published ? (trim($_POST['published_at'] ?? '') ?: date('Y-m-d H:i:s')) : null;
    $allow_comments = isset($_POST['allow_comments']) ? 1 : 0;
    $meta_title     = trim($_POST['meta_title'] ?? '');
    $meta_desc      = trim($_POST['meta_description'] ?? '');

    if ($title === '') $errors[] = 'Title is required.';
    if ($category_id !== null) {
        $ok = false;
        foreach ($cats as $c) if ((int)$c['id'] === $category_id) { $ok = true; break; }
        if (!$ok) $errors[] = 'Invalid category.';
    }

    $featured_image = null;
    if (!empty($_FILES['featured_image']['name'])) {
        $result = blog_upload_image($_FILES['featured_image']);
        if ($result === false) {
            $errors[] = 'Featured image: invalid file. Allowed: JPG, PNG, GIF, WebP (max 5 MB).';
        } else {
            $featured_image = $result;
        }
    }

    if (empty($errors)) {
        $slug = blog_unique_slug('blog_posts', blog_slugify($title));
        $user = auth_user();

        db()->prepare(
            'INSERT INTO blog_posts
             (title, slug, excerpt, content, content_type, featured_image, category_id, tags,
              status, is_pinned, published_at, allow_comments, meta_title, meta_description, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $title, $slug, $excerpt !== '' ? $excerpt : null, $content, $content_type,
            $featured_image, $category_id, $tags !== '' ? $tags : null,
            $is_published ? 'published' : 'draft', $is_pinned, $published_at, $allow_comments,
            $meta_title !== '' ? $meta_title : null, $meta_desc !== '' ? $meta_desc : null,
            $user['id'],
        ]);

        flash_set('success', 'Blog post <strong>' . h($title) . '</strong> created.');
        redirect(APP_URL . '/blog/index.php');
    }

    save_old(compact('title', 'excerpt', 'content_type', 'tags', 'meta_title') + [
        'category_id'      => (string)($category_id ?? ''),
        'is_published'     => (string)$is_published,
        'is_pinned'        => (string)$is_pinned,
        'allow_comments'   => (string)$allow_comments,
        'meta_description' => $meta_desc,
    ]);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/blog/index.php">Blog</a></li>
            <li class="breadcrumb-item active">New Post</li>
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
                               value="<?= old('title') ?>" required placeholder="Post title…" maxlength="500">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Excerpt</label>
                        <textarea name="excerpt" class="form-control" rows="2" maxlength="1000"
                                  placeholder="Short summary shown on the blog list (optional)…"><?= old('excerpt') ?></textarea>
                    </div>

                    <!-- Content type toggle -->
                    <div class="mb-3 d-flex align-items-center gap-3">
                        <label class="form-label fw-medium mb-0">Content</label>
                        <div class="btn-group btn-group-sm" role="group">
                            <input type="radio" class="btn-check" name="content_type" id="ct_html" value="html"
                                   <?= old('content_type', 'html') === 'html' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-primary" for="ct_html">
                                <i class="fas fa-code me-1"></i>HTML / Rich Text
                            </label>
                            <input type="radio" class="btn-check" name="content_type" id="ct_text" value="text"
                                   <?= old('content_type', 'html') === 'text' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-secondary" for="ct_text">
                                <i class="fas fa-align-left me-1"></i>Plain Text
                            </label>
                        </div>
                    </div>

                    <textarea id="blog_content" name="content" class="form-control"
                              rows="16" style="font-family:monospace;"><?= h(old('content', '')) ?></textarea>
                </div>
            </div>

            <!-- SEO -->
            <div class="card">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-magnifying-glass me-2 text-muted"></i>SEO (optional)</h6>
                </div>
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Meta Title</label>
                        <input type="text" name="meta_title" class="form-control" maxlength="255"
                               value="<?= old('meta_title') ?>" placeholder="Defaults to post title">
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-medium">Meta Description</label>
                        <textarea name="meta_description" class="form-control" rows="2" maxlength="500"
                                  placeholder="Defaults to the excerpt / first 160 characters"><?= old('meta_description') ?></textarea>
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
                    <div class="mb-3" id="pubDateWrap">
                        <label class="form-label fw-medium">Publish Date &amp; Time</label>
                        <input type="datetime-local" name="published_at" class="form-control"
                               value="<?= date('Y-m-d\TH:i') ?>">
                        <div class="form-text">Leave blank to use current time.</div>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="is_pinned" name="is_pinned"
                               value="1" <?= old('is_pinned') ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="is_pinned">
                            <i class="fas fa-thumbtack me-1 text-warning"></i>Pin on blog page
                        </label>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="allow_comments" name="allow_comments"
                               value="1" <?= old('allow_comments', '1') ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="allow_comments">Allow comments</label>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i> Save Post
                        </button>
                        <a href="<?= APP_URL ?>/blog/index.php" class="btn btn-light" style="border-radius:10px;">
                            Cancel
                        </a>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-folder me-2 text-muted"></i>Category &amp; Tags</h6>
                </div>
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Category</label>
                        <select name="category_id" class="form-select">
                            <option value="">— None —</option>
                            <?php foreach ($cats as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= old('category_id') === (string)$c['id'] ? 'selected' : '' ?>>
                                <?= h($c['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            <a href="<?= APP_URL ?>/blog/categories.php">Manage categories</a>
                        </div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-medium">Tags</label>
                        <input type="text" name="tags" class="form-control" maxlength="500"
                               value="<?= old('tags') ?>" placeholder="comma, separated, tags">
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
                    <div class="form-text mt-2">JPG, PNG, GIF, WebP. Recommended 1200×630 px. Max 5 MB.</div>
                </div>
            </div>

        </div>
    </div>
</form>

<!-- TinyMCE via jsDelivr -->
<script src="https://cdn.jsdelivr.net/npm/tinymce@5.10.9/tinymce.min.js" referrerpolicy="origin"></script>
<script>
(function () {
    var htmlRadio = document.getElementById('ct_html');
    var textRadio = document.getElementById('ct_text');
    var ta        = document.getElementById('blog_content');
    var editorInit = false;

    function initTiny() {
        if (editorInit) return;
        editorInit = true;
        tinymce.init({
            selector: '#blog_content',
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
        tinymce.remove('#blog_content');
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

    var pubChk  = document.getElementById('is_published');
    var pubWrap = document.getElementById('pubDateWrap');
    function togglePubDate() { pubWrap.style.display = pubChk.checked ? '' : 'none'; }
    pubChk.addEventListener('change', togglePubDate);
    togglePubDate();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
