<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_super_admin();

$id      = (int)($_GET['id']      ?? 0);
$dept_id = (int)($_GET['dept_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM dept_notices WHERE id = ?');
$stmt->execute([$id]);
$notice = $stmt->fetch();
if (!$notice) { flash_set('error', 'Notice not found.'); redirect(APP_URL . '/departments/index.php'); }

$dept_id = $dept_id ?: (int)$notice['dept_id'];
$dept    = db()->prepare('SELECT * FROM dept_departments WHERE id = ?');
$dept->execute([$dept_id]);
$dept    = $dept->fetch();
if (!$dept) { flash_set('error', 'Department not found.'); redirect(APP_URL . '/departments/index.php'); }

$page_title = 'Edit Notice – ' . $notice['title'];
$errors = [];
clear_old();

function dept_upload_file(array $file, string $subdir, array $allowed_exts, array $allowed_mimes): string|false {
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_exts, true)) return false;
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed_mimes, true)) return false;
    $dir = UPLOAD_DIR . '/' . $subdir;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return false;
    return $name;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $title       = trim($_POST['title']       ?? '');
    $content     = trim($_POST['content']     ?? '');
    $notice_date = trim($_POST['notice_date'] ?? '');
    $is_active   = isset($_POST['is_active']) ? 1 : 0;

    if ($title === '') $errors[] = 'Title is required.';

    $attachment = $notice['attachment'];
    if (!empty($_FILES['attachment']['name'])) {
        $uploaded = dept_upload_file(
            $_FILES['attachment'], 'departments',
            ['pdf','doc','docx','jpg','jpeg','png'],
            ['application/pdf','application/msword',
             'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
             'image/jpeg','image/png']
        );
        if ($uploaded === false) {
            $errors[] = 'Invalid attachment. Allowed: pdf, doc, docx, jpg, png.';
        } else {
            if ($notice['attachment']) {
                $old = UPLOAD_DIR . '/departments/' . $notice['attachment'];
                if (file_exists($old)) @unlink($old);
            }
            $attachment = $uploaded;
        }
    }

    if (empty($errors)) {
        db()->prepare(
            'UPDATE dept_notices SET title=?, content=?, attachment=?, notice_date=?, is_active=? WHERE id=?'
        )->execute([$title, $content ?: null, $attachment, $notice_date ?: null, $is_active, $id]);

        flash_set('success', "Notice <strong>" . h($title) . "</strong> updated.");
        redirect(APP_URL . '/departments/notices/index.php?dept_id=' . $dept_id);
    }

    $notice = array_merge($notice, compact('title','content','notice_date','is_active'));
    $notice['attachment'] = $attachment;
}

require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/index.php">Departments</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/view.php?id=<?= $dept_id ?>"><?= h($dept['name']) ?></a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/notices/index.php?dept_id=<?= $dept_id ?>">Notices</a></li>
            <li class="breadcrumb-item active">Edit</li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="row justify-content-center">
<div class="col-lg-7">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-bell me-2 text-muted"></i>Edit Notice</h6>
    </div>
    <div class="card-body p-4">
        <form method="POST" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="dept_id" value="<?= $dept_id ?>">

            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-medium">Title <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control" style="border-radius:10px;"
                           value="<?= h($notice['title']) ?>" required maxlength="300">
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">Content</label>
                    <textarea name="content" class="form-control" style="border-radius:10px;" rows="5"><?= h($notice['content'] ?? '') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Notice Date</label>
                    <input type="date" name="notice_date" class="form-control" style="border-radius:10px;"
                           value="<?= h($notice['notice_date'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Attachment</label>
                    <?php if ($notice['attachment']): ?>
                    <div class="mb-1">
                        <span class="badge bg-info text-dark"><i class="fas fa-paperclip me-1"></i><?= h($notice['attachment']) ?></span>
                    </div>
                    <?php endif; ?>
                    <input type="file" name="attachment" class="form-control" style="border-radius:10px;"
                           accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                    <small class="text-muted">Leave blank to keep current. Allowed: pdf, doc, docx, jpg, png</small>
                </div>
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                               <?= $notice['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Update Notice
                </button>
                <a href="<?= APP_URL ?>/departments/notices/index.php?dept_id=<?= $dept_id ?>"
                   class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
