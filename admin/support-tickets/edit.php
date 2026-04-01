<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';

$id     = (int)($_GET['id'] ?? 0);
$ticket = st_get_ticket($id);
$user   = auth_user();

// Only ticket creator or super admin can edit
if (!is_super_admin() && (int)$ticket['created_by'] !== (int)$user['id']) {
    flash_set('error', 'You do not have permission to edit this ticket.');
    redirect(APP_URL . '/support-tickets/view.php?id=' . $id);
}

$page_title = 'Edit Ticket #' . $ticket['ticket_number'];
$errors     = [];

// All active users for tagging
$all_users = db()->query(
    'SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name'
)->fetchAll();

// Currently tagged user IDs
$tagged_ids_stmt = db()->prepare(
    'SELECT user_id FROM support_ticket_user_tags WHERE ticket_id = ?'
);
$tagged_ids_stmt->execute([$id]);
$currently_tagged = array_map('intval', array_column($tagged_ids_stmt->fetchAll(), 'user_id'));

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $title       = trim($_POST['title']       ?? '');
    $description = $_POST['description']      ?? '';
    $category    = $_POST['category']         ?? 'Other';
    $priority    = $_POST['priority']         ?? 'Medium';
    $department  = trim($_POST['department']  ?? '');
    $deadline    = trim($_POST['deadline']    ?? '');
    $tag_users   = array_filter(array_map('intval', (array)($_POST['tag_users'] ?? [])));

    $valid_cats  = ['Hardware','Software','Network','Email','Other'];
    $valid_prios = ['Low','Medium','High','Critical'];

    if ($title === '')             $errors[] = 'Title is required.';
    if (mb_strlen($title) > 500)   $errors[] = 'Title must be 500 characters or less.';
    if (trim(strip_tags($description)) === '') $errors[] = 'Description is required.';
    if (!in_array($category, $valid_cats,  true)) $category = 'Other';
    if (!in_array($priority, $valid_prios, true)) $priority = 'Medium';

    $deadline_dt = $ticket['deadline']; // keep existing if blank
    if ($deadline !== '') {
        $ts = strtotime($deadline);
        if (!$ts) {
            $errors[] = 'Invalid deadline date/time.';
        } else {
            $deadline_dt = date('Y-m-d H:i:s', $ts);
        }
    }

    if (empty($errors)) {
        $pdo = db();
        $pdo->prepare(
            'UPDATE support_tickets
             SET title = ?, description = ?, category = ?, priority = ?, department = ?, deadline = ?
             WHERE id = ?'
        )->execute([$title, $description, $category, $priority, $department ?: null, $deadline_dt, $id]);

        // Additional attachments
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
                        $id, $file['name'], $stored,
                        $finfo->file(UPLOAD_DIR . '/support-tickets/' . $stored),
                        $file['size'], $user['id'],
                    ]);
                }
            }
        }

        // Sync tags: replace all existing tags
        $pdo->prepare('DELETE FROM support_ticket_user_tags WHERE ticket_id = ?')->execute([$id]);
        foreach ($tag_users as $tag_uid) {
            $pdo->prepare(
                'INSERT IGNORE INTO support_ticket_user_tags (ticket_id, user_id, tagged_by) VALUES (?,?,?)'
            )->execute([$id, $tag_uid, $user['id']]);
        }

        flash_set('success', 'Ticket updated successfully.');
        redirect(APP_URL . '/support-tickets/view.php?id=' . $id);
    }

    // Re-populate old() on validation failure
    save_old(compact('title','category','priority','department','deadline'));
    $ticket['description'] = $description; // keep typed description in textarea
} else {
    // Pre-fill old() from stored ticket on first load
    save_old([
        'title'      => $ticket['title'],
        'category'   => $ticket['category'],
        'priority'   => $ticket['priority'],
        'department' => $ticket['department'] ?? '',
        'deadline'   => $ticket['deadline']
            ? date('Y-m-d\TH:i', strtotime($ticket['deadline']))
            : '',
    ]);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/support-tickets/index.php">IT Support</a></li>
            <li class="breadcrumb-item">
                <a href="<?= APP_URL ?>/support-tickets/view.php?id=<?= $id ?>"><?= h($ticket['ticket_number']) ?></a>
            </li>
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

        <!-- ── Left column ─────────────────────────────────────────────── -->
        <div class="col-lg-8">

            <div class="card mb-4" style="border-radius:12px;">
                <div class="card-body p-4">
                    <div class="mb-0">
                        <label class="form-label fw-medium">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control form-control-lg"
                               value="<?= old('title') ?>" required maxlength="500">
                    </div>
                </div>
            </div>

            <div class="card mb-4" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold">Description <span class="text-danger">*</span></h6>
                </div>
                <div class="card-body p-4">
                    <textarea id="ticket_description" name="description"
                              class="form-control" rows="12"><?= h($ticket['description']) ?></textarea>
                </div>
            </div>

            <div class="card" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-paperclip me-2 text-muted"></i>Add More Attachments
                    </h6>
                </div>
                <div class="card-body p-4">
                    <input type="file" name="attachments[]" class="form-control" multiple
                           accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.txt">
                    <div class="form-text mt-1">Upload additional files. Existing attachments are not affected.</div>
                </div>
            </div>

        </div>

        <!-- ── Right column ────────────────────────────────────────────── -->
        <div class="col-lg-4">

            <div class="card" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold">Ticket Details</h6>
                </div>
                <div class="card-body p-4">

                    <div class="mb-3">
                        <label class="form-label fw-medium">Category</label>
                        <select name="category" class="form-select">
                            <?php foreach (['Hardware','Software','Network','Email','Other'] as $cat): ?>
                            <option value="<?= $cat ?>" <?= old('category') === $cat ? 'selected' : '' ?>><?= $cat ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Priority</label>
                        <select name="priority" class="form-select">
                            <?php foreach (['Low','Medium','High','Critical'] as $prio): ?>
                            <option value="<?= $prio ?>" <?= old('priority') === $prio ? 'selected' : '' ?>><?= $prio ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Department</label>
                        <input type="text" name="department" class="form-control"
                               value="<?= old('department') ?>" maxlength="200"
                               placeholder="e.g. Computer Science">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Deadline</label>
                        <input type="datetime-local" name="deadline" class="form-control"
                               value="<?= old('deadline') ?>">
                        <div class="form-text">Leave blank to keep existing deadline.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-medium">
                            <i class="fas fa-tags me-1 text-muted"></i> Tag Users
                        </label>
                        <select name="tag_users[]" class="form-select" multiple size="5">
                            <?php foreach ($all_users as $u): ?>
                            <?php if ((int)$u['id'] !== (int)$user['id']): ?>
                            <option value="<?= $u['id'] ?>"
                                <?= in_array((int)$u['id'], $currently_tagged, true) ? 'selected' : '' ?>>
                                <?= h($u['full_name']) ?>
                            </option>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Hold Ctrl/⌘ to select multiple.</div>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i> Save Changes
                        </button>
                        <a href="<?= APP_URL ?>/support-tickets/view.php?id=<?= $id ?>"
                           class="btn btn-light" style="border-radius:10px;">
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
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
