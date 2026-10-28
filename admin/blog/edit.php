<?php
/**
 * Blog — edit post.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

auth_check();
blog_ensure_schema();
require_access('blog', 'can_edit');

$id   = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$post = $id > 0 ? blog_find_post($id) : null;
if (!$post) {
    flash_set('error', 'Blog post not found.');
    redirect(APP_URL . '/blog/index.php');
}

$page_title = 'Edit Blog Post';
$errors     = [];
$cats       = blog_categories();

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
    $published_at   = $is_published
        ? (trim($_POST['published_at'] ?? '') ?: ($post['published_at'] ?: date('Y-m-d H:i:s')))
        : null;
    $allow_comments = isset($_POST['allow_comments']) ? 1 : 0;
    $meta_title     = trim($_POST['meta_title'] ?? '');
    $meta_desc      = trim($_POST['meta_description'] ?? '');
    $regen_slug     = isset($_POST['regenerate_slug']);

    if ($title === '') $errors[] = 'Title is required.';
    if ($category_id !== null) {
        $ok = false;
        foreach ($cats as $c) if ((int)$c['id'] === $category_id) { $ok = true; break; }
        if (!$ok) $errors[] = 'Invalid category.';
    }

    $featured_image = $post['featured_image'];
    if (!empty($_POST['remove_image'])) {
        blog_delete_image($featured_image);
        $featured_image = null;
    }
    if (!empty($_FILES['featured_image']['name'])) {
        $result = blog_upload_image($_FILES['featured_image']);
        if ($result === false) {
            $errors[] = 'Featured image: invalid file. Allowed: JPG, PNG, GIF, WebP (max 5 MB).';
        } else {
            blog_delete_image($featured_image);
            $featured_image = $result;
        }
    }

    if (empty($errors)) {
        $slug = $regen_slug
            ? blog_unique_slug('blog_posts', blog_slugify($title), $id)
            : $post['slug'];

        db()->prepare(
            'UPDATE blog_posts SET
                title = ?, slug = ?, excerpt = ?, content = ?, content_type = ?,
                featured_image = ?, category_id = ?, tags = ?, status = ?, is_pinned = ?, published_at = ?,
                allow_comments = ?, meta_title = ?, meta_description = ?
             WHERE id = ?'
        )->execute([
            $title, $slug, $excerpt !== '' ? $excerpt : null, $content, $content_type,
            $featured_image, $category_id, $tags !== '' ? $tags : null,
            $is_published ? 'published' : 'draft', $is_pinned, $published_at, $allow_comments,
            $meta_title !== '' ? $meta_title : null, $meta_desc !== '' ? $meta_desc : null,
            $id,
        ]);

        flash_set('success', 'Blog post <strong>' . h($title) . '</strong> updated.');
        redirect(APP_URL . '/blog/index.php');
    }

    // Keep the submitted values on validation failure
    $post = array_merge($post, [
        'title'            => $title,
        'excerpt'          => $excerpt,
        'content'          => $content,
        'content_type'     => $content_type,
        'category_id'      => $category_id,
        'tags'             => $tags,
        'status'           => $is_published ? 'published' : 'draft',
        'is_pinned'        => $is_pinned,
        'allow_comments'   => $allow_comments,
        'meta_title'       => $meta_title,
        'meta_description' => $meta_desc,
        'featured_image'   => $featured_image,
    ]);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/blog/index.php">Blog</a></li>
            <li class="breadcrumb-item active">Edit Post</li>
        </ol>
    </nav>
    <?php if ($post['status'] === 'published'): ?>
    <a href="<?= SITE_URL ?>/blog-detail.php?slug=<?= urlencode($post['slug']) ?>"
       target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm" style="border-radius:10px;">
        <i class="fas fa-external-link-alt me-1"></i> View on website
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

<form method="POST" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $post['id'] ?>">

    <div class="row g-4">
        <!-- Left column: content -->
        <div class="col-lg-8">

            <div class="card mb-4">
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control form-control-lg"
                               value="<?= h($post['title']) ?>" required maxlength="500">
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" id="regenerate_slug" name="regenerate_slug" value="1">
                            <label class="form-check-label" for="regenerate_slug" style="font-size:.85rem;">
                                Regenerate slug from title
                                <span class="text-muted">(current: <code><?= h($post['slug']) ?></code>)</span>
                            </label>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Excerpt</label>
                        <textarea name="excerpt" class="form-control" rows="2" maxlength="1000"><?= h($post['excerpt'] ?? '') ?></textarea>
                    </div>

                    <!-- Content type toggle -->
                    <div class="mb-3 d-flex align-items-center gap-3">
                        <label class="form-label fw-medium mb-0">Content</label>
                        <div class="btn-group btn-group-sm" role="group">
                            <input type="radio" class="btn-check" name="content_type" id="ct_html" value="html"
                                   <?= $post['content_type'] === 'html' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-primary" for="ct_html">
                                <i class="fas fa-code me-1"></i>HTML / Rich Text
                            </label>
                            <input type="radio" class="btn-check" name="content_type" id="ct_text" value="text"
                                   <?= $post['content_type'] === 'text' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-secondary" for="ct_text">
                                <i class="fas fa-align-left me-1"></i>Plain Text
                            </label>
                        </div>
                    </div>

                    <textarea id="blog_content" name="content" class="form-control"
                              rows="16" style="font-family:monospace;"><?= h($post['content'] ?? '') ?></textarea>
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
                               value="<?= h($post['meta_title'] ?? '') ?>" placeholder="Defaults to post title">
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-medium">Meta Description</label>
                        <textarea name="meta_description" class="form-control" rows="2" maxlength="500"
                                  placeholder="Defaults to the excerpt / first 160 characters"><?= h($post['meta_description'] ?? '') ?></textarea>
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
                               value="1" <?= $post['status'] === 'published' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="is_published">Publish</label>
                    </div>
                    <div class="mb-3" id="pubDateWrap">
                        <label class="form-label fw-medium">Publish Date &amp; Time</label>
                        <input type="datetime-local" name="published_at" class="form-control"
                               value="<?= $post['published_at'] ? date('Y-m-d\TH:i', strtotime($post['published_at'])) : date('Y-m-d\TH:i') ?>">
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="is_pinned" name="is_pinned"
                               value="1" <?= $post['is_pinned'] ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="is_pinned">
                            <i class="fas fa-thumbtack me-1 text-warning"></i>Pin on blog page
                        </label>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="allow_comments" name="allow_comments"
                               value="1" <?= $post['allow_comments'] ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="allow_comments">Allow comments</label>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i> Update Post
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
                            <option value="<?= $c['id'] ?>" <?= (int)($post['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                                <?= h($c['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-medium">Tags</label>
                        <input type="text" name="tags" class="form-control" maxlength="500"
                               value="<?= h($post['tags'] ?? '') ?>" placeholder="comma, separated, tags">
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-image me-2 text-muted"></i>Featured Image</h6>
                </div>
                <div class="card-body p-4">
                    <?php if ($post['featured_image']): ?>
                    <img src="<?= UPLOAD_URL ?>/blog/<?= h($post['featured_image']) ?>"
                         class="img-fluid rounded mb-3" alt="Featured image"
                         onerror="this.style.display='none'">
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="remove_image" name="remove_image" value="1">
                        <label class="form-check-label" for="remove_image">Remove current image</label>
                    </div>
                    <?php endif; ?>
                    <input type="file" name="featured_image" class="form-control"
                           accept=".jpg,.jpeg,.png,.gif,.webp">
                    <div class="form-text mt-2">Uploading a new image replaces the current one.</div>
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
