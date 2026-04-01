<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_super_admin();
require_once __DIR__ . '/helpers.php';

$page_title = 'New Knowledge Base Article';
$errors     = [];
clear_old();

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

    // Thumbnail upload (optional)
    $thumbnail = null;
    if (!empty($_FILES['thumbnail']['name'])) {
        $result = kb_upload_file($_FILES['thumbnail'], KB_THUMB_EXTS, KB_THUMB_MIMES);
        if ($result === false) {
            $errors[] = 'Thumbnail: invalid file. Allowed: JPG, PNG, GIF, WebP.';
        } else {
            $thumbnail = $result;
        }
    }

    // Document file upload (optional, document type only)
    $file_name     = null;
    $file_original = null;
    $file_mime     = null;
    $file_size     = null;
    if ($type === 'document' && !empty($_FILES['doc_file']['name'])) {
        $result = kb_upload_file($_FILES['doc_file'], KB_ALLOWED_EXTS, KB_ALLOWED_MIMES);
        if ($result === false) {
            $errors[] = 'Attachment: invalid file type or file too large (max 50 MB).';
        } else {
            $file_name     = $result;
            $file_original = $_FILES['doc_file']['name'];
            $finfo         = new finfo(FILEINFO_MIME_TYPE);
            $file_mime     = $finfo->file(UPLOAD_DIR . '/' . KB_UPLOAD_SUBDIR . '/' . $result);
            $file_size     = (int)$_FILES['doc_file']['size'];
        }
    }

    if (empty($errors)) {
        $user = auth_user();

        db()->prepare(
            'INSERT INTO kb_articles
               (title, type, description, content, video_url, thumbnail,
                file_name, file_original, file_mime, file_size, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $title, $type, $description ?: null,
            $type === 'document' ? $content : null,
            $type === 'video'    ? $video_url : null,
            $thumbnail,
            $file_name, $file_original, $file_mime, $file_size,
            $user['id'],
        ]);

        flash_set('success', 'Article <strong>' . h($title) . '</strong> created.');
        redirect(APP_URL . '/knowledge-base/index.php');
    }

    if ($thumbnail) kb_delete_file($thumbnail);
    if ($file_name) kb_delete_file($file_name);

    save_old(compact('title','type','description','content','video_url'));
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/knowledge-base/index.php">Knowledge Base</a></li>
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

        <!-- ── Left column ───────────────────────────────────────────────── -->
        <div class="col-lg-8">

            <div class="card mb-4" style="border-radius:12px;">
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control form-control-lg"
                               value="<?= old('title') ?>" required maxlength="255"
                               placeholder="Article title…">
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-medium">Short Description</label>
                        <textarea name="description" class="form-control" rows="3"
                                  maxlength="1000" placeholder="Brief summary shown in the article list…"><?= h(old('description','')) ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Video URL (shown when type = video) -->
            <div class="card mb-4" id="card_video" style="border-radius:12px;<?= old('type','document') === 'document' ? 'display:none;' : '' ?>">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-play-circle me-2 text-danger"></i>Video</h6>
                </div>
                <div class="card-body p-4">
                    <label class="form-label fw-medium">Video URL <span class="text-danger">*</span></label>
                    <input type="url" name="video_url" id="video_url" class="form-control"
                           value="<?= old('video_url') ?>"
                           placeholder="https://www.youtube.com/watch?v=… or https://vimeo.com/…">
                    <div class="form-text">Supports YouTube and Vimeo URLs. The video will be embedded directly on the view page.</div>
                </div>
            </div>

            <!-- Rich-text content (shown when type = document) -->
            <div class="card mb-4" id="card_document" style="border-radius:12px;<?= old('type','document') === 'video' ? 'display:none;' : '' ?>">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-align-left me-2 text-muted"></i>Content</h6>
                </div>
                <div class="card-body p-4">
                    <textarea id="kb_content" name="content" class="form-control"
                              rows="16"><?= h(old('content','')) ?></textarea>
                </div>
            </div>

            <!-- Document file upload -->
            <div class="card" id="card_doc_file" style="border-radius:12px;<?= old('type','document') === 'video' ? 'display:none;' : '' ?>">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-paperclip me-2 text-muted"></i>Attachment <span class="text-muted fw-normal">(optional)</span></h6>
                </div>
                <div class="card-body p-4">
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
                                       value="document" <?= old('type','document') === 'document' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="type_document">
                                    <i class="fas fa-file-alt me-1 text-primary"></i> Document / Guide
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="type" id="type_video"
                                       value="video" <?= old('type','document') === 'video' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="type_video">
                                    <i class="fas fa-play-circle me-1 text-danger"></i> Video Tutorial
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i> Save Article
                        </button>
                        <a href="<?= APP_URL ?>/knowledge-base/index.php" class="btn btn-light" style="border-radius:10px;">
                            Cancel
                        </a>
                    </div>
                </div>
            </div>

            <div class="card" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-image me-2 text-muted"></i>Thumbnail <span class="text-muted fw-normal">(optional)</span></h6>
                </div>
                <div class="card-body p-4">
                    <input type="file" name="thumbnail" class="form-control"
                           accept=".jpg,.jpeg,.png,.gif,.webp">
                    <div class="form-text mt-1">JPG, PNG, GIF, WebP. Shown as article preview image.</div>
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
