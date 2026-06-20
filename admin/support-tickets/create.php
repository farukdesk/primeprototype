<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';

$page_title = 'New Support Ticket';
$user       = auth_user();
$errors     = [];
clear_old();

// All active users for tagging (excludes self)
$all_users = db()->query(
    'SELECT id, full_name, username FROM users WHERE is_active = 1 ORDER BY full_name'
)->fetchAll();

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $title              = trim($_POST['title']              ?? '');
    $description        = $_POST['description']             ?? '';
    $category           = $_POST['category']                ?? 'Other';
    $priority           = $_POST['priority']                ?? 'Medium';
    $department         = trim($_POST['department']         ?? '');
    $deadline           = trim($_POST['deadline']           ?? '');
    $tag_users          = array_filter(array_map('intval', (array)($_POST['tag_users'] ?? [])));
    $user_type          = $_POST['user_type']               ?? '';
    $student_id         = trim($_POST['student_id']         ?? '');
    $student_department = trim($_POST['student_department'] ?? '');
    $student_program    = trim($_POST['student_program']    ?? '');
    $student_batch      = trim($_POST['student_batch']      ?? '');

    $valid_cats   = ['Hardware','Software','Network','Email','Other'];
    $valid_prios  = ['Low','Medium','High','Critical'];
    $valid_utypes = ['','Student','Faculty','Administrative Employee'];

    if ($title === '')             $errors[] = 'Title is required.';
    if (mb_strlen($title) > 500)   $errors[] = 'Title must be 500 characters or less.';
    if (trim(strip_tags($description)) === '') $errors[] = 'Description is required.';
    if (!in_array($category, $valid_cats,  true)) $category = 'Other';
    if (!in_array($priority, $valid_prios, true)) $priority = 'Medium';
    if (!in_array($user_type, $valid_utypes, true)) $user_type = '';

    // Deadline: auto-compute from SLA if not provided
    if ($deadline === '') {
        $deadline_dt = st_compute_deadline($priority);
    } else {
        $ts = strtotime($deadline);
        if (!$ts) {
            $errors[] = 'Invalid deadline date/time.';
            $deadline_dt = st_compute_deadline($priority);
        } else {
            $deadline_dt = date('Y-m-d H:i:s', $ts);
        }
    }

    if (empty($errors)) {
        $ticket_number = st_generate_ticket_number();
        $pdo           = db();

        $pdo->prepare(
            'INSERT INTO support_tickets
               (ticket_number, title, description, category, priority, status, department, deadline, created_by,
                user_type, student_id, student_department, student_program, student_batch)
             VALUES (?,?,?,?,?,\'Open\',?,?,?,?,?,?,?,?)'
        )->execute([
            $ticket_number, $title, $description, $category,
            $priority, $department ?: null, $deadline_dt, $user['id'],
            $user_type ?: null,
            $student_id ?: null,
            $student_department ?: null,
            $student_program ?: null,
            $student_batch ?: null,
        ]);
        $ticket_id = (int)$pdo->lastInsertId();

        // ── Attachments ───────────────────────────────────────────────────
        if (!empty($_FILES['attachments']['name'][0])) {
            foreach ($_FILES['attachments']['tmp_name'] as $i => $tmp) {
                if ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;
                $file = [
                    'name'     => $_FILES['attachments']['name'][$i],
                    'tmp_name' => $tmp,
                    'error'    => $_FILES['attachments']['error'][$i],
                    'size'     => $_FILES['attachments']['size'][$i],
                    'type'     => $_FILES['attachments']['type'][$i],
                ];
                $stored = st_upload_file($file);
                if ($stored) {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $pdo->prepare(
                        'INSERT INTO support_ticket_attachments
                           (ticket_id, original_name, stored_name, mime_type, file_size, uploaded_by)
                         VALUES (?,?,?,?,?,?)'
                    )->execute([
                        $ticket_id, $file['name'], $stored,
                        $finfo->file(UPLOAD_DIR . '/support-tickets/' . $stored),
                        $file['size'], $user['id'],
                    ]);
                }
            }
        }

        // ── User tags ─────────────────────────────────────────────────────
        foreach ($tag_users as $tag_uid) {
            $pdo->prepare(
                'INSERT IGNORE INTO support_ticket_user_tags (ticket_id, user_id, tagged_by) VALUES (?,?,?)'
            )->execute([$ticket_id, $tag_uid, $user['id']]);
        }

        // ── Notifications ─────────────────────────────────────────────────
        $fresh = $pdo->prepare('SELECT * FROM support_tickets WHERE id = ?');
        $fresh->execute([$ticket_id]);
        $ticket = $fresh->fetch();

        st_notify_ticket_created($ticket, $user);

        // ── Push notification to IT staff ─────────────────────────────────
        try {
            require_once __DIR__ . '/../api/includes/fcm.php';
            $staff_ids = db()->query(
                "SELECT DISTINCT u.id FROM users u
                 JOIN user_groups g ON g.id = u.group_id
                 WHERE u.is_active = 1 AND (g.is_super = 1
                       OR u.id IN (
                           SELECT uma.user_id FROM user_module_access uma
                           JOIN modules m ON m.id = uma.module_id
                           WHERE m.slug = 'support-tickets' AND uma.can_view = 1
                       )
                       OR g.id IN (
                           SELECT gma.group_id FROM group_module_access gma
                           JOIN modules m ON m.id = gma.module_id
                           WHERE m.slug = 'support-tickets' AND gma.can_view = 1
                       )
                 )"
            )->fetchAll(\PDO::FETCH_COLUMN);
            if (!empty($staff_ids)) {
                send_push_notification(
                    array_map('intval', $staff_ids),
                    'New Support Ticket',
                    '[' . $ticket['priority'] . '] ' . $ticket['title'],
                    ['type' => 'support_ticket', 'ticket_id' => (string)$ticket_id, 'ticket_number' => $ticket_number]
                );
            }
        } catch (Throwable $e) {
            error_log('PUMIS FCM support ticket push failed: ' . $e->getMessage());
        }

        foreach ($tag_users as $tag_uid) {
            if ((int)$tag_uid === (int)$user['id']) continue;
            $tu = $pdo->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
            $tu->execute([$tag_uid]);
            $tuser = $tu->fetch();
            if ($tuser) st_notify_tagged($ticket, $tuser, $user);
        }

        flash_set('success', 'Ticket <strong>#' . h($ticket_number) . '</strong> created successfully.');
        redirect(APP_URL . '/support-tickets/view.php?id=' . $ticket_id);
    }

    save_old(compact('title','description','category','priority','department','deadline',
                     'user_type','student_id','student_department','student_program','student_batch'));
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/support-tickets/index.php">IT Support</a></li>
            <li class="breadcrumb-item active">New Ticket</li>
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

        <!-- ── Left column ─────────────────────────────────────────────── -->
        <div class="col-lg-8">

            <div class="card mb-4" style="border-radius:12px;">
                <div class="card-body p-4">
                    <div class="mb-0">
                        <label class="form-label fw-medium">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control form-control-lg"
                               value="<?= old('title') ?>" required maxlength="500"
                               placeholder="Brief description of the issue">
                    </div>
                </div>
            </div>

            <div class="card mb-4" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-align-left me-2 text-muted"></i>Description <span class="text-danger">*</span>
                    </h6>
                </div>
                <div class="card-body p-4">
                    <textarea id="ticket_description" name="description"
                              class="form-control" rows="12"><?= h(old('description','')) ?></textarea>
                </div>
            </div>

            <div class="card" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-paperclip me-2 text-muted"></i>Attachments
                    </h6>
                </div>
                <div class="card-body p-4">
                    <input type="file" name="attachments[]" id="attachments" class="form-control" multiple
                           accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.txt">
                    <div class="form-text mt-1">
                        Max 10 MB per file. Allowed: images, PDF, Word, Excel, PowerPoint, ZIP, TXT.
                    </div>
                    <div id="file-preview" class="mt-3 d-flex flex-wrap gap-2"></div>
                </div>
            </div>

        </div>

        <!-- ── Right column ────────────────────────────────────────────── -->
        <div class="col-lg-4">

            <!-- Auto-filled submitter info (read-only) -->
            <div class="card mb-4" style="border-radius:12px;border-color:#d0e8ff;background:#f0f7ff;">
                <div class="card-body p-4">
                    <h6 class="fw-semibold mb-2" style="font-size:.875rem;">
                        <i class="fas fa-user-circle me-1 text-primary"></i>Submitting As
                    </h6>
                    <p class="mb-1" style="font-size:.9rem;"><strong><?= h($user['full_name']) ?></strong></p>
                    <p class="mb-0 text-muted" style="font-size:.82rem;"><?= h($user['email']) ?></p>
                    <?php if (!empty($user['username'])): ?>
                    <p class="mb-0 text-muted" style="font-size:.82rem;">@<?= h($user['username']) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-cog me-2 text-muted"></i>Ticket Details
                    </h6>
                </div>
                <div class="card-body p-4">

                    <div class="mb-3">
                        <label class="form-label fw-medium">Category <span class="text-danger">*</span></label>
                        <select name="category" class="form-select">
                            <?php foreach (['Hardware','Software','Network','Email','Other'] as $cat): ?>
                            <option value="<?= $cat ?>" <?= old('category','Other') === $cat ? 'selected' : '' ?>><?= $cat ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Priority <span class="text-danger">*</span></label>
                        <select name="priority" id="priority_select" class="form-select"
                                onchange="updateSlaHint(this.value)">
                            <?php foreach (['Low','Medium','High','Critical'] as $prio): ?>
                            <option value="<?= $prio ?>" <?= old('priority','Medium') === $prio ? 'selected' : '' ?>><?= $prio ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div id="sla_hint" class="form-text text-muted mt-1"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Department</label>
                        <input type="text" name="department" class="form-control"
                               value="<?= old('department') ?>" placeholder="e.g. Computer Science" maxlength="200">
                    </div>

                    <!-- User Type section -->
                    <div class="mb-3">
                        <label class="form-label fw-medium">User Type</label>
                        <select name="user_type" id="user_type_select" class="form-select"
                                onchange="toggleUserTypeFields(this.value)">
                            <option value="">— Select —</option>
                            <?php foreach (['Student','Faculty','Administrative Employee'] as $ut): ?>
                            <option value="<?= $ut ?>" <?= old('user_type') === $ut ? 'selected' : '' ?>><?= $ut ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Student fields -->
                    <div id="student_fields" style="display:none;">
                        <div class="mb-3">
                            <label class="form-label fw-medium">Student ID</label>
                            <input type="text" name="student_id" class="form-control"
                                   value="<?= old('student_id') ?>" placeholder="e.g. 2312345678" maxlength="20">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-medium">Department</label>
                            <input type="text" name="student_department" class="form-control"
                                   value="<?= old('student_department') ?>" placeholder="e.g. Computer Science" maxlength="200">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-medium">Program</label>
                            <input type="text" name="student_program" class="form-control"
                                   value="<?= old('student_program') ?>" placeholder="e.g. BSc in CSE" maxlength="200">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-medium">Batch</label>
                            <input type="text" name="student_batch" class="form-control"
                                   value="<?= old('student_batch') ?>" placeholder="e.g. Spring 2023" maxlength="100">
                        </div>
                    </div>

                    <!-- Faculty / Admin Employee fields -->
                    <div id="faculty_fields" style="display:none;">
                        <div class="mb-3">
                            <label class="form-label fw-medium">Department</label>
                            <input type="text" name="student_department" class="form-control"
                                   value="<?= old('student_department') ?>" placeholder="e.g. Computer Science" maxlength="200">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Custom Deadline</label>
                        <input type="datetime-local" name="deadline" class="form-control"
                               value="<?= old('deadline') ?>" min="<?= date('Y-m-d\TH:i') ?>">
                        <div class="form-text">Leave blank to use the SLA-based deadline.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-medium">
                            <i class="fas fa-tags me-1 text-muted"></i> Tag Users
                        </label>
                        <select name="tag_users[]" class="form-select" multiple size="5">
                            <?php foreach ($all_users as $u): ?>
                            <?php if ((int)$u['id'] !== (int)$user['id']): ?>
                            <option value="<?= $u['id'] ?>"><?= h($u['full_name']) ?><?= !empty($u['username']) ? ' (@' . h($u['username']) . ')' : '' ?></option>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Hold Ctrl/⌘ to select multiple. Tagged users receive an email notification.<br>You can also <code>@username</code> in comments to notify specific users.</div>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-paper-plane me-1"></i> Submit Ticket
                        </button>
                        <a href="<?= APP_URL ?>/support-tickets/index.php" class="btn btn-light" style="border-radius:10px;">
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
tinymce.init({
    selector: '#ticket_description',
    height: 380,
    menubar: false,
    plugins: 'advlist autolink lists link charmap preview anchor searchreplace visualblocks code fullscreen table help wordcount',
    toolbar: 'undo redo | blocks | bold italic underline strikethrough | ' +
             'alignleft aligncenter alignright | bullist numlist outdent indent | removeformat | link | code fullscreen',
    content_style: 'body { font-family: Inter, sans-serif; font-size: 15px; }',
});

const slaMap = { Low: '5 days', Medium: '3 days', High: '1 day', Critical: '4 hours' };
function updateSlaHint(priority) {
    const hint = document.getElementById('sla_hint');
    hint.textContent = slaMap[priority] ? 'SLA: ' + slaMap[priority] : '';
}
updateSlaHint(document.getElementById('priority_select').value);

document.getElementById('attachments').addEventListener('change', function () {
    const preview = document.getElementById('file-preview');
    preview.innerHTML = '';
    [...this.files].forEach(f => {
        const badge = document.createElement('span');
        badge.className = 'badge bg-light text-dark border';
        badge.style = 'font-size:.8rem;padding:6px 10px;border-radius:8px;';
        const size = f.size > 1048576
            ? (f.size / 1048576).toFixed(1) + ' MB'
            : Math.round(f.size / 1024) + ' KB';
        badge.textContent = f.name + ' (' + size + ')';
        preview.appendChild(badge);
    });
});

function toggleUserTypeFields(val) {
    document.getElementById('student_fields').style.display = (val === 'Student') ? '' : 'none';
    document.getElementById('faculty_fields').style.display = (val === 'Faculty' || val === 'Administrative Employee') ? '' : 'none';
}
// Init on page load
toggleUserTypeFields(document.getElementById('user_type_select').value);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
