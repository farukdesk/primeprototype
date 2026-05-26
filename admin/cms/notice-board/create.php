<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../change-log/helpers.php';
require_access('cms-notice-board', 'can_create');

$page_title = 'Add Notice';
$errors     = [];
clear_old();

// ── Allowed attachment types ──────────────────────────────────────────────────
const NB_EXTS = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','zip','txt'];
const NB_MIMES = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'image/jpeg','image/png',
    'application/zip','application/x-zip-compressed',
    'text/plain',
];

function nb_cms_slug(string $title): string {
    $slug = mb_strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    return trim($slug, '-') ?: 'untitled';
}

function nb_unique_notice_slug(string $base, int $exclude_id = 0): string {
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

function nb_unique_news_slug(string $base, int $exclude_id = 0): string {
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

function nb_upload_attachment(array $file): string|false {
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, NB_EXTS, true)) return false;
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, NB_MIMES, true)) return false;
    $dir = UPLOAD_DIR . '/notices';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return false;
    return $name;
}

/**
 * 🚀 Firebase HTTP v1 API Broadcast Function
 * এই ফাংশনটি ফায়ারবেসের মাধ্যমে মোবাইল অ্যাপে পুশ নোটিফিকেশন পাঠাবে।
 */
function send_fcm_v1_notification($title, $body) {
    // ⚠️ এখানে আপনার ফায়ারবেস প্রজেক্টের সঠিক Project ID-টি বসাবেন
    $project_id = 'YOUR_FIREBASE_PROJECT_ID_HERE'; 
    $url = "https://fcm.googleapis.com/v1/projects/{$project_id}/messages:send";

    // ⚠️ আপনার Firebase Service Account এর ওআউথ২ (OAuth2) অ্যাক্সেস টোকেন
    $access_token = 'YOUR_OAUTH2_ACCESS_TOKEN_HERE';

    $payload = [
        'message' => [
            'topic' => 'pumis_broadcast',
            'notification' => [
                'title' => $title,
                'body' => strlen($body) > 120 ? substr(strip_tags($body), 0, 120) . '...' : strip_tags($body)
            ],
            'android' => [
                'notification' => [
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    'sound' => 'default'
                ]
            ]
        ]
    ];

    $headers = [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $response = curl_exec($ch);
    curl_close($ch);

    return $response;
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

    // Attachment upload
    $attachment               = null;
    $attachment_original_name = null;
    $attachment_mime          = null;
    $attachment_size          = null;

    if (!empty($_FILES['attachment']['name'])) {
        $file   = $_FILES['attachment'];
        $result = nb_upload_attachment($file);
        if ($result === false) {
            $errors[] = 'Attachment: invalid file. Allowed types: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, ZIP, TXT.';
        } else {
            $attachment               = $result;
            $attachment_original_name = $file['name'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $attachment_mime = $finfo->file(UPLOAD_DIR . '/notices/' . $result);
            $attachment_size = (int)$file['size'];
        }
    }

    if (empty($errors)) {
        $db   = db();
        $slug = nb_unique_notice_slug(nb_cms_slug($title));
        $current_user = auth_user();
        $is_super    = is_super_admin();
        $is_approved  = $is_super ? 1 : 0;
        $approved_by  = $is_super ? $current_user['id'] : null;
        $approved_at  = $is_super ? date('Y-m-d H:i:s') : null;

        $db->prepare(
            'INSERT INTO cms_notices
             (title, slug, content, content_type, attachment, attachment_original_name,
              attachment_mime, attachment_size, publish_as_news, is_published, published_at,
              created_by, is_approved, approved_by, approved_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $title, $slug, $content, $content_type,
            $attachment, $attachment_original_name, $attachment_mime, $attachment_size,
            $publish_as_news, $is_published, $published_at,
            $current_user['id'], $is_approved, $approved_by, $approved_at,
        ]);

        $notice_id = (int)$db->lastInsertId();

        // Also insert into cms_news if requested
        if ($publish_as_news) {
            $st = $db->prepare('SELECT id FROM cms_news WHERE slug = ?');
            $st->execute([$slug]);
            $news_slug = $st->fetch() ? nb_unique_news_slug($slug . '-notice') : $slug;

            $db->prepare(
                'INSERT INTO cms_news
                 (title, slug, content, content_type, featured_image, is_published, published_at,
                  created_by, is_approved, approved_by, approved_at)
                 VALUES (?,?,?,?,NULL,?,?,?,?,?,?)'
            )->execute([
                $title, $news_slug, $content, $content_type, $is_published, $published_at,
                $current_user['id'], $is_approved, $approved_by, $approved_at,
            ]);

            $news_id = (int)$db->lastInsertId();
            $db->prepare('UPDATE cms_notices SET news_id = ? WHERE id = ?')->execute([$news_id, $notice_id]);
        }

        log_change('cms-notice-board', 'CREATE', $notice_id, $title, null, null, null,
            $is_super ? 'Notice created and approved.' : 'Notice created – pending super-admin approval.');

        if ($is_super) {
            // 🔥 নোটিশটি সুপার-অ্যাডমিন দ্বারা সরাসরি পাবলিশ হলে সাথে সাথে পুশ নোটিফিকেশন যাবে
            if ($is_published) {
                send_fcm_v1_notification($title, $content);
            }
            flash_set('success', 'Notice <strong>' . h($title) . '</strong> created.');
        } else {
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
                    'notice_title'   => $title,
                    'action'         => 'CREATE',
                    'pending_url'    => APP_URL . '/cms/pending-changes/index.php',
                ]);
            }
            flash_set('success', 'Notice <strong>' . h($title) . '</strong> submitted for super-admin approval.');
        }
        redirect(APP_URL . '/cms/notice-board/index.php');
    }

     Ester_old(compact('title', 'content_type', 'is_published', 'publish_as_news'));
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/cms/notice-board/index.php">Notice Board</a></li>
            <li class="breadcrumb-item active">Add Notice</li>
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
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control form-control-lg"
                               value="<?= old('title') ?>" required placeholder="Notice title…" maxlength="255">
                    </div>
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
                    <textarea id="notice_content" name="content" class="form-control"
                              rows="16"><?= h(old('content','')) ?></textarea>
                </div>
            </div>
            <div class="card">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-paperclip me-2 text-muted"></i>Attachment</h6>
                </div>
                <div class="card-body p-4">
                    <input type="file" name="attachment" id="attachment" class="form-control"
                           accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.zip,.txt">
                    <div class="form-text mt-2">
                        Allowed: PDF, Word, Excel, JPG, PNG, ZIP, TXT. Single file only.
                    </div>
                </div>
            </div>
        </div>
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
                    <div class="mb-4" id="pubDateWrap">
                        <label class="form-label fw-medium">Publish Date &amp; Time</label>
                        <input type="datetime-local" name="published_at" class="form-control"
                               value="<?= date('Y-m-d\TH:i') ?>">
                        <div class="form-text">Leave blank to use current time.</div>
                    </div>
                    <hr class="my-3">
                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" id="publish_as_news" name="publish_as_news"
                               value="1" <?= old('publish_as_news') ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="publish_as_news">
                            Also publish as Latest News
                            <div class="form-text mt-1 fw-normal">Appears in the news section.</div>
                        </label>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i>
                            <?= is_super_admin() ? 'Save Notice' : 'Submit for Approval' ?>
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

<script src="https://cdn.jsdelivr.net/npm/tinymce@5.10.9/tinymce.min.js" referrerpolicy="origin"></script>
<script>
(function () {
    var htmlRadio = document.getElementById('ct_html');
    var textRadio = document.getElementById('ct_text');
    var ta        = document.getElementById('notice_content');
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

    var pubChk  = document.getElementById('is_published');
    var pubWrap = document.getElementById('pubDateWrap');
    function togglePubDate() { pubWrap.style.display = pubChk.checked ? '' : 'none'; }
    pubChk.addEventListener('change', togglePubDate);
    togglePubDate();
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
