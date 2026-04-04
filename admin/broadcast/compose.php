<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('broadcast', 'can_create');
require_once __DIR__ . '/helpers.php';

$page_title = 'Compose Broadcast';
$user       = auth_user();
$errors     = [];
clear_old();

// Fetch all active users and groups for recipient dropdowns
$all_users  = db()->query(
    'SELECT id, full_name, email FROM users WHERE is_active = 1 ORDER BY full_name'
)->fetchAll();

$all_groups = db()->query(
    'SELECT id, name FROM user_groups WHERE is_active = 1 ORDER BY name'
)->fetchAll();

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $subject        = trim($_POST['subject']         ?? '');
    $body_html      = $_POST['body_html']             ?? '';
    $recipient_type = $_POST['recipient_type']        ?? 'all';
    $user_id        = (int)($_POST['recipient_user_id']  ?? 0) ?: null;
    $group_id       = (int)($_POST['recipient_group_id'] ?? 0) ?: null;

    $valid_types = ['individual', 'group', 'all'];
    if (!in_array($recipient_type, $valid_types, true)) $recipient_type = 'all';

    // Validate
    if ($subject === '')                             $errors[] = 'Subject is required.';
    if (mb_strlen($subject) > 255)                  $errors[] = 'Subject must be 255 characters or less.';
    if (trim(strip_tags($body_html)) === '')         $errors[] = 'Email body is required.';
    if ($recipient_type === 'individual' && !$user_id)  $errors[] = 'Please select a recipient user.';
    if ($recipient_type === 'group'      && !$group_id) $errors[] = 'Please select a recipient group.';

    // Process attachments
    $attach_stored = [];
    $total_size    = 0;
    if (!empty($_FILES['attachments']['name'][0])) {
        foreach ($_FILES['attachments']['name'] as $i => $orig_name) {
            if ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;

            $total_size += $_FILES['attachments']['size'][$i];
            if ($total_size > BC_MAX_TOTAL_SIZE) {
                $errors[] = 'Total attachment size exceeds the 20 MB limit.';
                break;
            }

            $file_arr = [
                'name'     => $orig_name,
                'tmp_name' => $_FILES['attachments']['tmp_name'][$i],
                'error'    => $_FILES['attachments']['error'][$i],
                'size'     => $_FILES['attachments']['size'][$i],
                'type'     => $_FILES['attachments']['type'][$i],
            ];
            $stored = bc_upload_file($file_arr);
            if ($stored) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $attach_stored[] = [
                    'original_name' => $orig_name,
                    'stored_name'   => $stored,
                    'mime_type'     => $finfo->file(UPLOAD_DIR . '/' . BC_UPLOAD_SUBDIR . '/' . $stored),
                    'file_size'     => $file_arr['size'],
                ];
            } else {
                $errors[] = 'File "' . h($orig_name) . '" was rejected (invalid type or too large).';
            }
        }
    }

    if (empty($errors)) {
        $pdo = db();

        // Resolve recipients first so we can bail if none
        $recipients = bc_resolve_recipients($recipient_type, $user_id, $group_id);
        if (empty($recipients)) {
            $errors[] = 'No active users found for the selected recipient.';
        }
    }

    if (empty($errors)) {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            // Insert broadcast record (draft initially)
            $pdo->prepare(
                'INSERT INTO broadcasts (subject, body_html, recipient_type, recipient_user_id, recipient_group_id, sent_by, status)
                 VALUES (?,?,?,?,?,?,\'draft\')'
            )->execute([$subject, $body_html, $recipient_type, $user_id, $group_id, $user['id']]);
            $broadcast_id = (int)$pdo->lastInsertId();

            // Save attachments
            $ins_att = $pdo->prepare(
                'INSERT INTO broadcast_attachments (broadcast_id, original_name, stored_name, mime_type, file_size)
                 VALUES (?,?,?,?,?)'
            );
            foreach ($attach_stored as $a) {
                $ins_att->execute([
                    $broadcast_id,
                    $a['original_name'],
                    $a['stored_name'],
                    $a['mime_type'],
                    $a['file_size'],
                ]);
            }

            $pdo->commit();

            // ── Send emails (outside transaction for performance) ──────────────
            $attach_rows = $pdo->prepare(
                'SELECT * FROM broadcast_attachments WHERE broadcast_id = ?'
            );
            $attach_rows->execute([$broadcast_id]);
            $attach_rows = $attach_rows->fetchAll();

            $result = bc_send_broadcast($broadcast_id, $recipients, $subject, $body_html, $attach_rows);

            // Determine final status
            $new_status = 'sent';
            if ($result['failed'] > 0 && $result['sent'] === 0) $new_status = 'draft';
            elseif ($result['failed'] > 0)                       $new_status = 'partial';

            $pdo->prepare(
                'UPDATE broadcasts SET sent_count=?, failed_count=?, status=?, sent_at=NOW() WHERE id=?'
            )->execute([$result['sent'], $result['failed'], $new_status, $broadcast_id]);

            $msg = 'Broadcast sent to ' . $result['sent'] . ' recipient(s).';
            if ($result['failed'] > 0) {
                $msg .= ' ' . $result['failed'] . ' delivery failure(s) – check the broadcast log.';
            }
            flash_set('success', $msg);
            redirect(APP_URL . '/broadcast/view.php?id=' . $broadcast_id);

        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'An unexpected error occurred. Please try again.';
        }
    }

    save_old($_POST);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-paper-plane me-2 text-primary"></i>Compose Broadcast</h1>
        <p class="text-muted small mb-0">All emails are sent from <strong>noreply@primeuniversity.ac.bd</strong></p>
    </div>
    <a href="<?= APP_URL ?>/broadcast/index.php" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i> Back to Broadcasts
    </a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" id="broadcast-form">
    <?= csrf_field() ?>

    <div class="row g-4">
        <!-- Left column -->
        <div class="col-lg-8">
            <!-- Subject -->
            <div class="card shadow-sm mb-4">
                <div class="card-header fw-semibold"><i class="fas fa-tag me-1"></i> Subject</div>
                <div class="card-body">
                    <input type="text" name="subject" id="subject"
                           class="form-control form-control-lg"
                           placeholder="Email subject…"
                           maxlength="255"
                           value="<?= h(old('subject')) ?>"
                           required>
                </div>
            </div>

            <!-- Body -->
            <div class="card shadow-sm mb-4">
                <div class="card-header fw-semibold"><i class="fas fa-align-left me-1"></i> Email Body</div>
                <div class="card-body">
                    <p class="text-muted small mb-2">
                        Tip: use <code>{{full_name}}</code> anywhere in the body – it will be replaced with each recipient's name.
                    </p>
                    <textarea name="body_html" id="body_html" rows="16"><?= h(old('body_html')) ?></textarea>
                </div>
            </div>

            <!-- Attachments -->
            <div class="card shadow-sm mb-4">
                <div class="card-header fw-semibold"><i class="fas fa-paperclip me-1"></i> Attachments <small class="text-muted fw-normal">(optional – max 5 MB each, 20 MB total)</small></div>
                <div class="card-body">
                    <input type="file" name="attachments[]" id="attachments" class="form-control" multiple
                           accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.txt">
                    <div id="file-preview" class="d-flex flex-wrap gap-2 mt-2"></div>
                </div>
            </div>
        </div>

        <!-- Right column -->
        <div class="col-lg-4">
            <!-- Recipients -->
            <div class="card shadow-sm mb-4">
                <div class="card-header fw-semibold"><i class="fas fa-users me-1"></i> Recipients</div>
                <div class="card-body">
                    <!-- Recipient type -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Send to</label>
                        <div class="d-flex flex-column gap-2">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="recipient_type" id="rt_all"
                                       value="all" <?= old('recipient_type', 'all') === 'all' ? 'checked' : '' ?>
                                       onchange="toggleRecipientFields(this.value)">
                                <label class="form-check-label" for="rt_all">
                                    <i class="fas fa-users text-success me-1"></i> All registered users
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="recipient_type" id="rt_group"
                                       value="group" <?= old('recipient_type') === 'group' ? 'checked' : '' ?>
                                       onchange="toggleRecipientFields(this.value)">
                                <label class="form-check-label" for="rt_group">
                                    <i class="fas fa-layer-group text-info me-1"></i> A user group
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="recipient_type" id="rt_individual"
                                       value="individual" <?= old('recipient_type') === 'individual' ? 'checked' : '' ?>
                                       onchange="toggleRecipientFields(this.value)">
                                <label class="form-check-label" for="rt_individual">
                                    <i class="fas fa-user text-warning me-1"></i> An individual user
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Group picker -->
                    <div id="field_group" class="mb-3" style="display:none">
                        <label class="form-label" for="recipient_group_id">Select Group</label>
                        <select name="recipient_group_id" id="recipient_group_id" class="form-select">
                            <option value="">— Choose group —</option>
                            <?php foreach ($all_groups as $g): ?>
                            <option value="<?= $g['id'] ?>" <?= old('recipient_group_id') == $g['id'] ? 'selected' : '' ?>>
                                <?= h($g['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Individual user picker -->
                    <div id="field_user" class="mb-1" style="display:none">
                        <label class="form-label" for="recipient_user_id">Select User</label>
                        <select name="recipient_user_id" id="recipient_user_id" class="form-select">
                            <option value="">— Choose user —</option>
                            <?php foreach ($all_users as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= old('recipient_user_id') == $u['id'] ? 'selected' : '' ?>>
                                <?= h($u['full_name']) ?> (<?= h($u['email']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Send button -->
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="alert alert-warning small mb-3 py-2">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        This will send an email immediately to all matched recipients. This action cannot be undone.
                    </div>
                    <button type="submit" class="btn btn-primary w-100" id="send-btn">
                        <i class="fas fa-paper-plane me-1"></i> Send Broadcast
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<script src="https://cdn.jsdelivr.net/npm/tinymce@5.10.9/tinymce.min.js" referrerpolicy="origin"></script>
<script>
tinymce.init({
    selector: '#body_html',
    height: 450,
    menubar: false,
    plugins: 'advlist autolink lists link image charmap preview anchor searchreplace visualblocks code fullscreen table help wordcount',
    toolbar: 'undo redo | blocks | bold italic underline strikethrough forecolor backcolor | ' +
             'alignleft aligncenter alignright | bullist numlist outdent indent | ' +
             'link image table | removeformat | code fullscreen',
    content_style: 'body { font-family: Inter, Arial, sans-serif; font-size: 15px; }',
});

// Recipient field toggling
function toggleRecipientFields(val) {
    document.getElementById('field_group').style.display = (val === 'group')      ? '' : 'none';
    document.getElementById('field_user').style.display  = (val === 'individual') ? '' : 'none';
}
// Init on page load
const checkedType = document.querySelector('input[name="recipient_type"]:checked');
if (checkedType) toggleRecipientFields(checkedType.value);

// File preview
document.getElementById('attachments').addEventListener('change', function () {
    const preview = document.getElementById('file-preview');
    preview.innerHTML = '';
    [...this.files].forEach(f => {
        const badge = document.createElement('span');
        badge.className = 'badge bg-light text-dark border';
        badge.style = 'font-size:.8rem;padding:6px 10px;border-radius:8px;';
        const size = f.size >= 1048576
            ? (f.size / 1048576).toFixed(1) + ' MB'
            : Math.round(f.size / 1024) + ' KB';
        badge.textContent = f.name + ' (' + size + ')';
        preview.appendChild(badge);
    });
});

// Prevent double-send
document.getElementById('broadcast-form').addEventListener('submit', function () {
    const btn = document.getElementById('send-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Sending…';
    // Sync TinyMCE content before submit
    tinymce.triggerSave();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
