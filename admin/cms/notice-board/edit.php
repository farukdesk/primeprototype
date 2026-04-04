<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../change-log/helpers.php';
require_access('cms-notice-board', 'can_edit');

$is_super     = is_super_admin();
$current_user = auth_user();
$id     = (int)($_GET['id'] ?? 0);
$notice = null;
$errors = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM cms_notices WHERE id = ?');
    $stmt->execute([$id]);
    $notice = $stmt->fetch();
}
if (!$notice) {
    flash_set('error', 'Notice not found.');
    redirect(APP_URL . '/cms/notice-board/index.php');
}

// Non-super admins may only edit their own notices
if (!$is_super && (int)($notice['created_by'] ?? 0) !== (int)$current_user['id']) {
    flash_set('error', 'You do not have permission to edit this notice.');
    redirect(APP_URL . '/cms/notice-board/index.php');
}

clear_old();
$page_title = 'Edit Notice';

// ── Allowed attachment types ──────────────────────────────────────────────────
const NBE_EXTS = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','zip','txt'];
const NBE_MIMES = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'image/jpeg','image/png',
    'application/zip','application/x-zip-compressed',
    'text/plain',
];

function nbe_unique_notice_slug(string $base, int $exclude_id = 0): string {
    $slug = $base;
    $i    = 2;
    $db   = db();
    while (true) {
        $st = $db->prepare('SELECT id FROM cms_notices WHERE slug = ? AND id != ?');
        $st->execute([$slug, $exclude_id]);
        if (!$st->fetch()) break;
        $slug = $base . '-' . $i++;
    }
    return $slug;
}

function nbe_unique_news_slug(string $base, int $exclude_id = 0): string {
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

function nbe_upload_attachment(array $file): string|false {
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, NBE_EXTS, true)) return false;
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, NBE_MIMES, true)) return false;
    $dir = UPLOAD_DIR . '/notices';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return false;
    return $name;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $title           = trim($_POST['title']           ?? '');
    $content         = $_POST['content']              ?? '';
    $content_type    = in_array($_POST['content_type'] ?? '', ['html','text']) ? $_POST['content_type'] : 'html';
    $publish_as_news = isset($_POST['publish_as_news']) ? 1 : 0;
    $is_published    = isset($_POST['is_published'])    ? 1 : 0;
    $published_at    = $is_published ? (trim($_POST['published_at'] ?? '') ?: date('Y-m-d H:i:s')) : null;

    if ($title === '') $errors[] = 'Title is required.';

    // Attachment handling
    $attachment               = $notice['attachment'];
    $attachment_original_name = $notice['attachment_original_name'];
    $attachment_mime          = $notice['attachment_mime'];
    $attachment_size          = $notice['attachment_size'];
    $remove_attachment        = isset($_POST['remove_attachment']);
    $new_attachment           = null;

    if (!empty($_FILES['attachment']['name'])) {
        $file   = $_FILES['attachment'];
        $result = nbe_upload_attachment($file);
        if ($result === false) {
            $errors[] = 'Attachment: invalid file. Allowed types: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, ZIP, TXT.';
        } else {
            $new_attachment = $result;
            $attachment_original_name = $file['name'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $attachment_mime = $finfo->file(UPLOAD_DIR . '/notices/' . $result);
            $attachment_size = (int)$file['size'];
        }
    }

    if (empty($errors)) {
        $db = db();

        if ($is_super) {
            // ── Super admin: apply change directly ──────────────────────
            if ($remove_attachment && $attachment) {
                $old_path = UPLOAD_DIR . '/notices/' . $attachment;
                if (file_exists($old_path)) @unlink($old_path);
                $attachment = $attachment_original_name = $attachment_mime = $attachment_size = null;
            }
            if ($new_attachment !== null) {
                if ($attachment) {
                    $old_path = UPLOAD_DIR . '/notices/' . $attachment;
                    if (file_exists($old_path)) @unlink($old_path);
                }
                $attachment = $new_attachment;
            }

            $old_publish_as  = (int)$notice['publish_as_news'];
            $old_news_id     = (int)($notice['news_id'] ?? 0);

            $db->prepare(
                'UPDATE cms_notices SET
                 title=?, content=?, content_type=?, attachment=?, attachment_original_name=?,
                 attachment_mime=?, attachment_size=?, publish_as_news=?, is_published=?, published_at=?,
                 updated_at=NOW()
                 WHERE id=?'
            )->execute([
                $title, $content, $content_type,
                $attachment, $attachment_original_name, $attachment_mime, $attachment_size,
                $publish_as_news, $is_published, $published_at,
                $id,
            ]);

            // Handle publish_as_news state changes
            if ($publish_as_news && !$old_news_id) {
                $st = $db->prepare('SELECT id FROM cms_news WHERE slug = ?');
                $st->execute([$notice['slug']]);
                $news_slug = $st->fetch()
                    ? nbe_unique_news_slug($notice['slug'] . '-notice')
                    : $notice['slug'];
                $db->prepare(
                    'INSERT INTO cms_news (title, slug, content, content_type, featured_image, is_published, published_at)
                     VALUES (?,?,?,?,NULL,?,?)'
                )->execute([$title, $news_slug, $content, $content_type, $is_published, $published_at]);
                $new_news_id = (int)$db->lastInsertId();
                $db->prepare('UPDATE cms_notices SET news_id = ? WHERE id = ?')->execute([$new_news_id, $id]);
            } elseif (!$publish_as_news && $old_news_id) {
                $db->prepare('DELETE FROM cms_news WHERE id = ?')->execute([$old_news_id]);
                $db->prepare('UPDATE cms_notices SET news_id = NULL WHERE id = ?')->execute([$id]);
            } elseif ($publish_as_news && $old_news_id) {
                $db->prepare(
                    'UPDATE cms_news SET title=?, content=?, content_type=?, is_published=?, published_at=?, updated_at=NOW()
                     WHERE id=?'
                )->execute([$title, $content, $content_type, $is_published, $published_at, $old_news_id]);
            }

            log_change('cms-notice-board', 'UPDATE', $id, $title, null, null, null,
                'Notice updated directly by super admin.');

            flash_set('success', 'Notice <strong>' . h($title) . '</strong> updated.');
            redirect(APP_URL . '/cms/notice-board/index.php');

        } else {
            // ── Non-super admin: queue edit request for approval ─────────
            $payload = [
                'title'            => $title,
                'content'          => $content,
                'content_type'     => $content_type,
                'publish_as_news'  => $publish_as_news,
                'is_published'     => $is_published,
                'published_at'     => $published_at,
                'attachment_new'           => $new_attachment,
                'attachment_original_name' => $attachment_original_name,
                'attachment_mime'          => $attachment_mime,
                'attachment_size'          => $attachment_size,
                'attachment_remove'        => $remove_attachment,
                'old_attachment'           => $notice['attachment'],
                'was_approved'             => (int)$notice['is_approved'],
            ];

            // Replace any existing pending EDIT for this record by this user
            $db->prepare(
                "DELETE FROM cms_pending_changes
                 WHERE module='notice' AND record_id=? AND action='EDIT'
                   AND requested_by=? AND status='pending'"
            )->execute([$id, $current_user['id']]);

            $db->prepare(
                "INSERT INTO cms_pending_changes
                 (module, record_id, record_title, action, requested_by, payload)
                 VALUES ('notice', ?, ?, 'EDIT', ?, ?)"
            )->execute([$id, $notice['title'], $current_user['id'], json_encode($payload)]);

            // Hide notice from public website until super admin approves the edit
            $db->prepare('UPDATE cms_notices SET is_approved=0 WHERE id=?')->execute([$id]);

            log_change('cms-notice-board', 'UPDATE', $id, $title, null, null, null,
                'Edit request submitted by ' . $current_user['full_name'] . ' – awaiting super-admin approval.');

            // Notify super admins by email
            require_once __DIR__ . '/../../includes/mailer.php';
            $supers = db()->query(
                'SELECT u.full_name, u.email FROM users u
                 JOIN user_groups g ON g.id = u.group_id
                 WHERE g.is_super = 1 AND u.is_active = 1'
            )->fetchAll();
            foreach ($supers as $su) {
                send_template_email('notice_approval_needed', $su['email'], $su['full_name'], [
                    'full_name'      => $su['full_name'],
                    'requester_name' => $current_user['full_name'],
                    'notice_title'   => $notice['title'],
                    'action'         => 'EDIT',
                    'pending_url'    => APP_URL . '/cms/pending-changes/index.php',
                ]);
            }

            flash_set('success', 'Edit request submitted for super-admin approval.');
            redirect(APP_URL . '/cms/notice-board/index.php');
        }
    }

    // Merge posted values back for form re-display
    $notice = array_merge($notice, compact(
        'title','content','content_type','publish_as_news','is_published','published_at',
        'attachment','attachment_original_name','attachment_mime','attachment_size'
    ));
}

require_once __DIR__ . '/../../includes/header.php';

// Format datetime-local value
$pub_dt_val = '';
if (!empty($notice['published_at'])) {
    $pub_dt_val = date('Y-m-d\TH:i', strtotime($notice['published_at']));
} else {
    $pub_dt_val = date('Y-m-d\TH:i');
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/cms/notice-board/index.php">Notice Board</a></li>
            <li class="breadcrumb-item active">Edit Notice</li>
        </ol>
    </nav>
</div>

<?php if (!$is_super): ?>
<div class="alert alert-info py-2 mb-4" style="border-radius:10px;font-size:.875rem;">
    <i class="fas fa-info-circle me-1"></i>
    Your changes will be queued for super-admin approval and will not take effect immediately.
</div>
<?php endif; ?>

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
                               value="<?= h($notice['title']) ?>" required placeholder="Notice title…" maxlength="255">
                    </div>

                    <!-- Content type toggle -->
                    <div class="mb-3 d-flex align-items-center gap-3">
                        <label class="form-label fw-medium mb-0">Content</label>
                        <div class="btn-group btn-group-sm" role="group">
                            <input type="radio" class="btn-check" name="content_type" id="ct_html" value="html"
                                   <?= $notice['content_type'] === 'html' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-primary" for="ct_html">
                                <i class="fas fa-code me-1"></i>HTML / Rich Text
                            </label>
                            <input type="radio" class="btn-check" name="content_type" id="ct_text" value="text"
                                   <?= $notice['content_type'] === 'text' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-secondary" for="ct_text">
                                <i class="fas fa-align-left me-1"></i>Plain Text
                            </label>
                        </div>
                    </div>

                    <textarea id="notice_content" name="content" class="form-control"
                              rows="16"><?= h($notice['content']) ?></textarea>
                </div>
            </div>

            <!-- Attachment -->
            <div class="card">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-paperclip me-2 text-muted"></i>Attachment</h6>
                </div>
                <div class="card-body p-4">
                    <?php if ($notice['attachment']): ?>
                    <div class="mb-3 p-3 bg-light rounded d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <i class="fas fa-file me-2 text-primary"></i>
                            <strong><?= h($notice['attachment_original_name']) ?></strong>
                            <?php if ($notice['attachment_size']): ?>
                            <span class="text-muted ms-2" style="font-size:.8rem;">
                                (<?= number_format($notice['attachment_size'] / 1024, 1) ?> KB)
                            </span>
                            <?php endif; ?>
                        </div>
                        <div class="form-check form-check-inline mb-0">
                            <input class="form-check-input" type="checkbox" name="remove_attachment" id="remove_attachment" value="1">
                            <label class="form-check-label text-danger" for="remove_attachment">Remove</label>
                        </div>
                    </div>
                    <?php endif; ?>
                    <input type="file" name="attachment" id="attachmentInput" class="form-control"
                           accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.zip,.txt">
                    <div class="form-text mt-2">
                        <?= $notice['attachment'] ? 'Upload a new file to replace the existing one.' : 'Allowed: PDF, Word, Excel, JPG, PNG, ZIP, TXT. Single file only.' ?>
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
                               value="1" <?= $notice['is_published'] ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="is_published">Publish</label>
                    </div>
                    <div class="mb-4" id="pubDateWrap">
                        <label class="form-label fw-medium">Publish Date &amp; Time</label>
                        <input type="datetime-local" name="published_at" class="form-control"
                               value="<?= h($pub_dt_val) ?>">
                        <div class="form-text">Leave blank to use current time.</div>
                    </div>

                    <hr class="my-3">

                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" id="publish_as_news" name="publish_as_news"
                               value="1" <?= $notice['publish_as_news'] ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="publish_as_news">
                            Also publish as Latest News
                            <div class="form-text mt-1 fw-normal">Appears in the news section.</div>
                        </label>
                    </div>

                    <?php if ($notice['slug']): ?>
                    <div class="mb-3">
                        <label class="form-label fw-medium text-muted" style="font-size:.8rem;">Slug</label>
                        <div class="text-muted" style="font-size:.8rem;word-break:break-all;"><?= h($notice['slug']) ?></div>
                    </div>
                    <?php endif; ?>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i>
                            <?= $is_super ? 'Update Notice' : 'Submit Edit Request' ?>
                        </button>
                        <a href="<?= APP_URL ?>/cms/notice-board/index.php" class="btn btn-light" style="border-radius:10px;">
                            Cancel
                        </a>
                    </div>
                </div>
            </div>

        </div>
    </div>
</form>

<script src="https://cdn.tiny.cloud/1/0fkb6zlxpds5u8r0f2l2votobmdgvvf2izxzbndjv3dgl73f/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
<script>
(function () {
    var htmlRadio  = document.getElementById('ct_html');
    var textRadio  = document.getElementById('ct_text');
    var ta         = document.getElementById('notice_content');
    var editorInit = false;

    function initTiny() {
        if (editorInit) return;
        editorInit = true;
        tinymce.init({
            selector: '#notice_content',
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
        tinymce.remove('#notice_content');
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
