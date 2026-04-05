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

// Fetch departments and programs for student filters
$all_departments = db()->query(
    'SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name'
)->fetchAll();

$all_programs = db()->query(
    'SELECT p.id, p.program_name, p.dept_id, d.name AS dept_name
     FROM dept_academic_programs p
     JOIN dept_departments d ON d.id = p.dept_id
     WHERE p.is_active = 1
     ORDER BY d.name, p.program_name'
)->fetchAll();

require_once __DIR__ . '/../students/helpers.php';
$all_semesters = array_reverse(sm_semester_list()); // most-recent first

$student_statuses = ['Active', 'Inactive', 'Graduated', 'Dropped'];

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $subject        = trim($_POST['subject']         ?? '');
    $body_html      = $_POST['body_html']             ?? '';
    $recipient_type = $_POST['recipient_type']        ?? 'all';
    $user_id        = (int)($_POST['recipient_user_id']  ?? 0) ?: null;
    $group_id       = (int)($_POST['recipient_group_id'] ?? 0) ?: null;

    // Student-specific filters
    $student_dept_id    = (int)($_POST['student_dept_id']    ?? 0) ?: null;
    $student_program_id = (int)($_POST['student_program_id'] ?? 0) ?: null;
    $student_status     = in_array($_POST['student_status'] ?? '', ['Active', 'Inactive', 'Graduated', 'Dropped'], true)
                          ? $_POST['student_status'] : null;
    $student_semester   = trim($_POST['student_semester'] ?? '') ?: null;

    $valid_types = ['individual', 'group', 'all', 'students'];
    if (!in_array($recipient_type, $valid_types, true)) $recipient_type = 'all';

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
        $recipients = bc_resolve_recipients($recipient_type, $user_id, $group_id, $student_dept_id, $student_program_id, $student_status, $student_semester);
        if (empty($recipients)) {
            $errors[] = 'No active users found for the selected recipient.';
        }
    }

    if (empty($errors)) {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            // Individual sends go straight out; group/all/students require admin approval
            $initial_status = ($recipient_type === 'individual') ? 'draft' : 'pending_approval';

            $pdo->prepare(
                'INSERT INTO broadcasts
                    (subject, body_html, recipient_type, recipient_user_id, recipient_group_id,
                     student_dept_id, student_program_id, student_status, student_semester,
                     sent_by, status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $subject, $body_html, $recipient_type, $user_id, $group_id,
                $student_dept_id, $student_program_id, $student_status, $student_semester,
                $user['id'], $initial_status,
            ]);
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

            if ($recipient_type === 'individual') {
                // ── Send immediately for individual recipients ─────────────────
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
            } else {
                // ── Queue for admin approval (group / all users / students) ───────────────
                flash_set('info', 'Broadcast queued for admin approval. It will be sent once an administrator approves it.');
                redirect(APP_URL . '/broadcast/view.php?id=' . $broadcast_id);
            }

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
                                <input class="form-check-input" type="radio" name="recipient_type" id="rt_students"
                                       value="students" <?= old('recipient_type') === 'students' ? 'checked' : '' ?>
                                       onchange="toggleRecipientFields(this.value)">
                                <label class="form-check-label" for="rt_students">
                                    <i class="fas fa-user-graduate text-primary me-1"></i> Students
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
                        <select name="recipient_group_id" id="recipient_group_id" placeholder="Search groups…">
                            <option value=""></option>
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
                        <select name="recipient_user_id" id="recipient_user_id" placeholder="Search by name or email…">
                            <option value=""></option>
                            <?php foreach ($all_users as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= old('recipient_user_id') == $u['id'] ? 'selected' : '' ?>>
                                <?= h($u['full_name']) ?> (<?= h($u['email']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Student filters -->
                    <div id="field_students" style="display:none">
                        <hr class="my-2">
                        <p class="small fw-semibold text-primary mb-2"><i class="fas fa-filter me-1"></i>Student Filters <span class="fw-normal text-muted">(leave blank = all)</span></p>

                        <!-- Department -->
                        <div class="mb-2">
                            <label class="form-label small mb-1" for="student_dept_id">Department</label>
                            <select name="student_dept_id" id="student_dept_id" class="form-select form-select-sm" onchange="filterProgramsByDept(this.value)">
                                <option value="">— All Departments —</option>
                                <?php foreach ($all_departments as $d): ?>
                                <option value="<?= $d['id'] ?>" <?= old('student_dept_id') == $d['id'] ? 'selected' : '' ?>>
                                    <?= h($d['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Program -->
                        <div class="mb-2">
                            <label class="form-label small mb-1" for="student_program_id">Program</label>
                            <select name="student_program_id" id="student_program_id" class="form-select form-select-sm">
                                <option value="">— All Programs —</option>
                                <?php foreach ($all_programs as $p): ?>
                                <option value="<?= $p['id'] ?>"
                                        data-dept="<?= $p['dept_id'] ?>"
                                        <?= old('student_program_id') == $p['id'] ? 'selected' : '' ?>>
                                    <?= h($p['program_name']) ?>
                                    <?= $p['dept_id'] ? ' (' . h($p['dept_name']) . ')' : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Status -->
                        <div class="mb-2">
                            <label class="form-label small mb-1" for="student_status">Status</label>
                            <select name="student_status" id="student_status" class="form-select form-select-sm">
                                <option value="">— All Statuses —</option>
                                <?php foreach ($student_statuses as $st): ?>
                                <option value="<?= $st ?>" <?= old('student_status') === $st ? 'selected' : '' ?>>
                                    <?= h($st) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Semester -->
                        <div class="mb-1">
                            <label class="form-label small mb-1" for="student_semester">Admitted Semester</label>
                            <select name="student_semester" id="student_semester" class="form-select form-select-sm">
                                <option value="">— All Semesters —</option>
                                <?php foreach ($all_semesters as $sem): ?>
                                <option value="<?= h($sem) ?>" <?= old('student_semester') === $sem ? 'selected' : '' ?>>
                                    <?= h($sem) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Send button -->
            <div class="card shadow-sm">
                <div class="card-body">
                    <div id="alert-individual" class="alert alert-warning small mb-3 py-2" style="display:none;">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        This will send an email immediately to the selected recipient. This action cannot be undone.
                    </div>
                    <div id="alert-approval" class="alert alert-info small mb-3 py-2">
                        <i class="fas fa-clock me-1"></i>
                        Broadcasts to groups or all users require <strong>admin approval</strong> before sending.
                    </div>
                    <button type="submit" class="btn btn-primary w-100" id="send-btn">
                        <i class="fas fa-clock me-1" id="send-icon"></i>
                        <span id="send-label">Submit for Approval</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css">
<script src="https://cdn.jsdelivr.net/npm/tinymce@5.10.9/tinymce.min.js" referrerpolicy="origin"></script>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
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

// ── Tom Select: Group picker ──────────────────────────────────────────────────
const tsGroup = new TomSelect('#recipient_group_id', {
    placeholder: 'Search groups…',
    allowEmptyOption: true,
    maxOptions: null,
    sortField: { field: 'text', direction: 'asc' },
});

// ── Tom Select: Individual user picker ───────────────────────────────────────
// Each option text is "Full Name (email)" so searching by name OR email works
// through the single 'text' field without extra configuration.
const tsUser = new TomSelect('#recipient_user_id', {
    placeholder: 'Search by name or email…',
    allowEmptyOption: true,
    maxOptions: 50,
    searchField: ['text'],
    sortField: { field: 'text', direction: 'asc' },
});

// Recipient field toggling
function toggleRecipientFields(val) {
    document.getElementById('field_group').style.display    = (val === 'group')      ? '' : 'none';
    document.getElementById('field_user').style.display     = (val === 'individual') ? '' : 'none';
    document.getElementById('field_students').style.display = (val === 'students')   ? '' : 'none';
    // Sync Tom Select layout after display change
    if (val === 'group')      tsGroup.sync();
    if (val === 'individual') tsUser.sync();

    // Update send button and alert based on recipient type
    const isIndividual = (val === 'individual');
    document.getElementById('alert-individual').style.display = isIndividual ? '' : 'none';
    document.getElementById('alert-approval').style.display   = isIndividual ? 'none' : '';
    document.getElementById('send-icon').className  = isIndividual ? 'fas fa-paper-plane me-1' : 'fas fa-clock me-1';
    document.getElementById('send-label').textContent = isIndividual ? 'Send Broadcast' : 'Submit for Approval';
}

// Filter programs dropdown when a department is selected
function filterProgramsByDept(deptId) {
    const sel = document.getElementById('student_program_id');
    const currentProgramId = sel.value;
    [...sel.options].forEach(opt => {
        if (!opt.value) return; // keep "All Programs" option
        const match = !deptId || opt.dataset.dept === deptId;
        opt.hidden = !match;
        if (!match && opt.selected) opt.selected = false;
    });
    if (!currentProgramId || !sel.querySelector(`option[value="${currentProgramId}"]:not([hidden])`)) {
        sel.value = '';
    }
}
// Init on page load
const checkedType = document.querySelector('input[name="recipient_type"]:checked');
if (checkedType) toggleRecipientFields(checkedType.value);
// Init program filter based on restored department selection
filterProgramsByDept(document.getElementById('student_dept_id')?.value ?? '');

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
    const isIndividual = document.querySelector('input[name="recipient_type"]:checked')?.value === 'individual';
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> ' + (isIndividual ? 'Sending…' : 'Submitting…');
    // Sync TinyMCE content before submit
    tinymce.triggerSave();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
