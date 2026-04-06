<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../change-log/helpers.php';
require_once __DIR__ . '/helpers.php';
require_access('cms-glance', 'can_edit');

$key = trim($_GET['key'] ?? '');
if (!in_array($key, ['chairman', 'vc'], true)) {
    flash_set('error', 'Invalid message key.');
    redirect(APP_URL . '/cms/glance/index.php');
}

$row = db()->prepare('SELECT * FROM glance_messages WHERE msg_key = ?');
$row->execute([$key]);
$row = $row->fetch();
if (!$row) { flash_set('error', 'Message not found. Run the SQL migration first.'); redirect(APP_URL . '/cms/glance/index.php'); }

$page_title = 'Edit Message – ' . h($row['tab_label']);
$errors     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $tab_label   = trim($_POST['tab_label']   ?? '');
    $person_name = trim($_POST['person_name'] ?? '');
    $person_role = trim($_POST['person_role'] ?? '');
    $body        = trim($_POST['body']        ?? '');
    $sort_order  = (int)($_POST['sort_order'] ?? 0);
    $is_active   = isset($_POST['is_active'])  ? 1 : 0;

    if ($person_name === '') $errors[] = 'Person name is required.';
    if ($body === '')        $errors[] = 'Message body is required.';

    $photo = $row['photo'];
    if (!empty($_FILES['photo']['name'])) {
        $res = glance_upload_image($_FILES['photo']);
        if ($res === false) $errors[] = 'Photo: invalid file. Allowed: JPG, PNG, GIF, WebP.';
        else $photo = $res;
    }

    if (empty($errors)) {
        db()->prepare(
            'UPDATE glance_messages
             SET tab_label=?, person_name=?, person_role=?, photo=?, body=?, sort_order=?, is_active=?, updated_at=NOW()
             WHERE msg_key=?'
        )->execute([$tab_label, $person_name, $person_role, $photo, $body, $sort_order, $is_active, $key]);
        log_change('cms-glance', 'UPDATE', $row['id'], $tab_label, null, null, null, 'Message updated: ' . $key);
        flash_set('success', 'Message updated.');
        redirect(APP_URL . '/cms/glance/index.php');
    }
} else {
    $tab_label   = $row['tab_label'];
    $person_name = $row['person_name'];
    $person_role = $row['person_role'];
    $body        = $row['body'];
    $sort_order  = $row['sort_order'];
    $is_active   = $row['is_active'];
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/cms/glance/index.php">PU At a Glance</a></li>
            <li class="breadcrumb-item active">Edit Message</li>
        </ol>
    </nav>
</div>
<?php flash_show(); ?>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="row">
<div class="col-lg-8">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-quote-left me-2 text-muted"></i>
            Edit Message &mdash; <code><?= h($key) ?></code>
        </h6>
    </div>
    <div class="card-body p-4">
        <form method="POST" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label fw-medium">Tab Label (shown on the website tab button)</label>
                <input type="text" name="tab_label" class="form-control" value="<?= h($tab_label) ?>" maxlength="100">
                <div class="form-text">e.g. "Message from Chairman"</div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Person Name <span class="text-danger">*</span></label>
                    <input type="text" name="person_name" class="form-control" value="<?= h($person_name) ?>" maxlength="200" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Person Role / Designation</label>
                    <input type="text" name="person_role" class="form-control" value="<?= h($person_role) ?>" maxlength="200">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label fw-medium">Message Body <span class="text-danger">*</span></label>
                <textarea name="body" class="form-control" rows="10" required><?= h($body) ?></textarea>
                <div class="form-text">Separate paragraphs with a blank line. Each line break creates a new paragraph on the website.</div>
            </div>
            <div class="mb-3">
                <label class="form-label fw-medium">Photo</label>
                <?php if ($row['photo']): ?>
                <div class="mb-2"><img src="<?= h(glance_img_url($row['photo'])) ?>" alt="" style="height:80px;border-radius:50%;object-fit:cover;aspect-ratio:1;"></div>
                <?php endif; ?>
                <input type="file" name="photo" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp">
                <div class="form-text">Leave blank to keep the current photo.</div>
            </div>
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" value="<?= (int)$sort_order ?>" min="0">
                </div>
                <div class="col-md-4 d-flex align-items-end pb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" <?= $is_active ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="is_active">Active (show on website)</label>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;"><i class="fas fa-save me-1"></i> Update Message</button>
                <a href="<?= APP_URL ?>/cms/glance/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
