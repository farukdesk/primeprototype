<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_super_admin();
require_once __DIR__ . '/helpers.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { flash_set('error', 'Article not found.'); redirect(APP_URL . '/knowledge-base/index.php'); }

$article = db()->prepare('SELECT * FROM kb_articles WHERE id = ?');
$article->execute([$id]);
$article = $article->fetch();
if (!$article) { flash_set('error', 'Article not found.'); redirect(APP_URL . '/knowledge-base/index.php'); }

$page_title = 'Edit Article';
$errors     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $title       = trim($_POST['title']       ?? '');
    $type        = in_array($_POST['type'] ?? '', ['video','document'], true) ? $_POST['type'] : 'document';
    $description = trim($_POST['description'] ?? '');
    $content     = $_POST['content']          ?? '';
    $video_url   = trim($_POST['video_url']   ?? '');

    if ($title === '')                  $errors[] = 'Title is required.';
    if (mb_strlen($title) > 255)        $errors[] = 'Title must be 255 characters or less.';
    if ($type === 'video' && $video_url === '') {
        $errors[] = 'Video URL is required for video articles.';
    }

    // Thumbnail: new upload or keep existing
    $thumbnail     = $article['thumbnail'];
    $new_thumbnail = null;
    if (!empty($_FILES['thumbnail']['name'])) {
        $result = kb_upload_file($_FILES['thumbnail'], KB_THUMB_EXTS, KB_THUMB_MIMES);
        if ($result === false) {
            $errors[] = 'Thumbnail: invalid file. Allowed: JPG, PNG, GIF, WebP.';
        } else {
            $new_thumbnail = $result;
        }
    }

    // Remove existing thumbnail if requested
    $remove_thumb = isset($_POST['remove_thumbnail']);

    // Document file: new upload or keep existing
    $file_name     = $article['file_name'];
    $file_original = $article['file_original'];
    $file_mime     = $article['file_mime'];
    $file_size     = $article['file_size'];
    $new_file      = null;
    if ($type === 'document' && !empty($_FILES['doc_file']['name'])) {
        $result = kb_upload_file($_FILES['doc_file'], KB_ALLOWED_EXTS, KB_ALLOWED_MIMES);
        if ($result === false) {
            $errors[] = 'Attachment: invalid file type or file too large (max 50 MB).';
        } else {
            $new_file      = $result;
            $file_original = $_FILES['doc_file']['name'];
            $finfo         = new finfo(FILEINFO_MIME_TYPE);
            $file_mime     = $finfo->file(UPLOAD_DIR . '/' . KB_UPLOAD_SUBDIR . '/' . $result);
            $file_size     = (int)$_FILES['doc_file']['size'];
        }
    }

    // Remove existing file if requested
    $remove_file = isset($_POST['remove_file']);

    if (empty($errors)) {
        // Swap / remove thumbnail
        if ($new_thumbnail) {
            kb_delete_file($article['thumbnail']);
            $thumbnail = $new_thumbnail;
        } elseif ($remove_thumb) {
            kb_delete_file($article['thumbnail']);
            $thumbnail = null;
        }

        // Swap / remove document file
        if ($new_file) {
            kb_delete_file($article['file_name']);
            $file_name = $new_file;
        } elseif ($remove_file || $type === 'video') {
            kb_delete_file($article['file_name']);
            $file_name = $file_original = $file_mime = $file_size = null;
        }

        db()->prepare(
            'UPDATE kb_articles SET
               title=?, type=?, description=?, content=?, video_url=?,
               thumbnail=?, file_name=?, file_original=?, file_mime=?, file_size=?
             WHERE id=?'
        )->execute([
            $title, $type, $description ?: null,
            $type === 'document' ? $content : null,
            $type === 'video'    ? $video_url : null,
            $thumbnail, $file_name, $file_original, $file_mime, $file_size,
            $id,
        ]);

        flash_set('success', 'Article <strong>' . h($title) . '</strong> updated.');
        redirect(APP_URL . '/knowledge-base/view.php?id=' . $id);
    }

    // Roll back any newly uploaded files on error
    if ($new_thumbnail) kb_delete_file($new_thumbnail);
    if ($new_file)      kb_delete_file($new_file);
}

// Populate form from DB on GET (or re-show on POST error)
$f_title       = old('title',       $article['title']);
$f_type        = old('type',        $article['type']);
$f_description = old('description', $article['description'] ?? '');
$f_content     = old('content',     $article['content']     ?? '');
$f_video_url   = old('video_url',   $article['video_url']   ?? '');

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/knowledge-base/index.php">Knowledge Base</a></li>
            <li class="breadcrumb-item active">Edit Article</li>
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

        <!-- ── Left column ───────────────────────────────────────────────── -->
        <div class="col-lg-8">

            <div class="card mb-4" style="border-radius:12px;">
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control form-control-lg"
                               value="<?= h($f_title) ?>" required maxlength="255">
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-medium">Short Description</label>
                        <textarea name="description" class="form-control" rows="3"
                                  maxlength="1000"><?= h($f_description) ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Video URL -->
            <div class="card mb-4" id="card_video" style="border-radius:12px;<?= $f_type === 'document' ? 'display:none;' : '' ?>">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-play-circle me-2 text-danger"></i>Video</h6>
                </div>
                <div class="card-body p-4">
                    <label class="form-label fw-medium">Video URL <span class="text-danger">*</span></label>
                    <input type="url" name="video_url" id="video_url" class="form-control"
                           value="<?= h($f_video_url) ?>"
                           placeholder="https://www.youtube.com/watch?v=… or https://vimeo.com/…">
                    <div class="form-text">Supports YouTube and Vimeo URLs.</div>
                </div>
            </div>

            <!-- Rich-text content -->
            <div class="card mb-4" id="card_document" style="border-radius:12px;<?= $f_type === 'video' ? 'display:none;' : '' ?>">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-align-left me-2 text-muted"></i>Content</h6>
                </div>
                <div class="card-body p-4">
                    <textarea id="kb_content" name="content" class="form-control"
                              rows="16"><?= h($f_content) ?></textarea>
                </div>
            </div>

            <!-- Document file -->
            <div class="card" id="card_doc_file" style="border-radius:12px;<?= $f_type === 'video' ? 'display:none;' : '' ?>">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-paperclip me-2 text-muted"></i>Attachment</h6>
                </div>
                <div class="card-body p-4">
                    <?php if ($article['file_name']): ?>
                    <div class="d-flex align-items-center gap-3 mb-3 p-3"
                         style="background:#f8f9fa;border-radius:10px;">
                        <i class="<?= kb_mime_icon($article['file_mime'] ?? '') ?> fa-lg"></i>
                        <div class="flex-grow-1">
                            <div class="fw-medium" style="font-size:.875rem;"><?= h($article['file_original']) ?></div>
                            <div class="text-muted" style="font-size:.75rem;"><?= kb_format_size((int)$article['file_size']) ?></div>
                        </div>
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" name="remove_file" id="remove_file" value="1">
                            <label class="form-check-label text-danger" for="remove_file" style="font-size:.8rem;">Remove</label>
                        </div>
                    </div>
                    <label class="form-label fw-medium" style="font-size:.875rem;">Replace with new file</label>
                    <?php endif; ?>
                    <input type="file" name="doc_file" class="form-control"
                           accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.txt">
                    <div class="form-text mt-1">Max 50 MB. Allowed: PDF, Word, Excel, PowerPoint, ZIP, TXT.</div>
                </div>
            </div>

        </div>

        <!-- ── Right column ──────────────────────────────────────────────── -->
        <div class="col-lg-4">

            <div class="card mb-4" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-cog me-2 text-muted"></i>Settings</h6>
                </div>
                <div class="card-body p-4">

                    <div class="mb-4">
                        <label class="form-label fw-medium">Article Type <span class="text-danger">*</span></label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="type" id="type_document"
                                       value="document" <?= $f_type === 'document' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="type_document">
                                    <i class="fas fa-file-alt me-1 text-primary"></i> Document / Guide
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="type" id="type_video"
                                       value="video" <?= $f_type === 'video' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="type_video">
                                    <i class="fas fa-play-circle me-1 text-danger"></i> Video Tutorial
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i> Update Article
                        </button>
                        <a href="<?= APP_URL ?>/knowledge-base/view.php?id=<?= $id ?>" class="btn btn-light" style="border-radius:10px;">
                            Cancel
                        </a>
                    </div>
                </div>
            </div>

            <div class="card" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-image me-2 text-muted"></i>Thumbnail</h6>
                </div>
                <div class="card-body p-4">
                    <?php if ($article['thumbnail']): ?>
                    <img src="<?= UPLOAD_URL ?>/<?= KB_UPLOAD_SUBDIR ?>/<?= h($article['thumbnail']) ?>"
                         class="img-fluid rounded mb-3" style="max-height:120px;width:100%;object-fit:cover;" alt="">
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="remove_thumbnail" id="remove_thumbnail" value="1">
                        <label class="form-check-label text-danger" for="remove_thumbnail" style="font-size:.8rem;">Remove thumbnail</label>
                    </div>
                    <label class="form-label fw-medium" style="font-size:.875rem;">Replace with new image</label>
                    <?php endif; ?>
                    <input type="file" name="thumbnail" class="form-control"
                           accept=".jpg,.jpeg,.png,.gif,.webp">
                    <div class="form-text mt-1">JPG, PNG, GIF, WebP.</div>
                </div>
            </div>

        </div>
    </div>
</form>

<script src="https://cdn.jsdelivr.net/npm/tinymce@5.10.9/tinymce.min.js" referrerpolicy="origin"></script>
<script>
(function () {
    var radios    = document.querySelectorAll('input[name="type"]');
    var cardVideo = document.getElementById('card_video');
    var cardDoc   = document.getElementById('card_document');
    var cardFile  = document.getElementById('card_doc_file');
    var tinyReady = false;

    function initTiny() {
        if (tinyReady) return;
        tinyReady = true;
        tinymce.init({
            selector: '#kb_content',
            height: 420,
            menubar: false,
            plugins: 'advlist autolink lists link image charmap preview anchor ' +
                     'searchreplace visualblocks code fullscreen table help wordcount',
            toolbar: 'undo redo | blocks | bold italic underline strikethrough | ' +
                     'alignleft aligncenter alignright | bullist numlist outdent indent | ' +
                     'removeformat | link image | code fullscreen',
            content_style: 'body { font-family: Inter, sans-serif; font-size: 15px; }',
        });
    }

    function destroyTiny() {
        if (!tinyReady) return;
        tinymce.remove('#kb_content');
        tinyReady = false;
    }

    function applyType() {
        var isVideo = document.getElementById('type_video').checked;
        cardVideo.style.display = isVideo ? '' : 'none';
        cardDoc.style.display   = isVideo ? 'none' : '';
        cardFile.style.display  = isVideo ? 'none' : '';
        if (isVideo) { destroyTiny(); } else { initTiny(); }
    }

    radios.forEach(function (r) { r.addEventListener('change', applyType); });
    applyType();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
