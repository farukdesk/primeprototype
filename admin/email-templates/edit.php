<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('email-templates', 'can_edit');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    flash_set('error', 'Invalid template.');
    redirect(APP_URL . '/email-templates/index.php');
}

$stmt = db()->prepare('SELECT * FROM email_templates WHERE id = ?');
$stmt->execute([$id]);
$tpl = $stmt->fetch();

if (!$tpl) {
    flash_set('error', 'Email template not found.');
    redirect(APP_URL . '/email-templates/index.php');
}

$page_title = 'Edit Email Template';
$errors     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name      = trim($_POST['name']      ?? '');
    $action    = trim($_POST['action']    ?? '');
    $subject   = trim($_POST['subject']   ?? '');
    $body_html = $_POST['body_html']      ?? '';
    $variables = trim($_POST['variables'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '')    $errors[] = 'Template name is required.';
    if ($action === '')  $errors[] = 'Action / trigger slug is required.';
    if (!preg_match('/^[a-z0-9_]+$/', $action)) $errors[] = 'Action must contain only lowercase letters, numbers, and underscores.';
    if ($subject === '') $errors[] = 'Subject is required.';
    if (trim(strip_tags($body_html)) === '') $errors[] = 'Email body is required.';

    if (empty($errors)) {
        $dup = db()->prepare('SELECT id FROM email_templates WHERE action = ? AND id != ?');
        $dup->execute([$action, $id]);
        if ($dup->fetch()) $errors[] = 'Another template with this action already exists.';
    }

    if (empty($errors)) {
        db()->prepare(
            'UPDATE email_templates
             SET name=?, action=?, subject=?, body_html=?, variables=?, is_active=?
             WHERE id=?'
        )->execute([$name, $action, $subject, $body_html, $variables ?: null, $is_active, $id]);

        flash_set('success', "Email template <strong>" . h($name) . "</strong> updated.");
        redirect(APP_URL . '/email-templates/index.php');
    }

    // Preserve posted values on error
    $tpl['name']      = $name;
    $tpl['action']    = $action;
    $tpl['subject']   = $subject;
    $tpl['body_html'] = $body_html;
    $tpl['variables'] = $variables;
    $tpl['is_active'] = $is_active;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/email-templates/index.php">Email Templates</a></li>
            <li class="breadcrumb-item active">Edit</li>
        </ol>
    </nav>
</div>

<div class="row">
<div class="col-lg-8">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-edit me-2 text-muted"></i>Edit Email Template</h6>
    </div>
    <div class="card-body p-4">

        <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <?= csrf_field() ?>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Template Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control"
                           value="<?= h($tpl['name']) ?>" required maxlength="150">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Action / Trigger Slug <span class="text-danger">*</span></label>
                    <input type="text" name="action" class="form-control"
                           value="<?= h($tpl['action']) ?>" required maxlength="100" autocomplete="off">
                    <small class="text-muted">Lowercase letters, numbers, underscores only.</small>
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">Email Subject <span class="text-danger">*</span></label>
                    <input type="text" name="subject" class="form-control"
                           value="<?= h($tpl['subject']) ?>" required maxlength="255">
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">Available Variables</label>
                    <input type="text" name="variables" class="form-control"
                           value="<?= h($tpl['variables'] ?? '') ?>" maxlength="500">
                    <small class="text-muted">Comma-separated list of placeholder names used in the template.</small>
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">HTML Body <span class="text-danger">*</span></label>
                    <textarea name="body_html" id="body_html" class="form-control font-monospace"
                              rows="18" required
                              style="font-size:.82rem;resize:vertical;"><?= htmlspecialchars($tpl['body_html'], ENT_QUOTES, 'UTF-8') ?></textarea>
                    <small class="text-muted">Full HTML email body. Use <code>{{variable}}</code> placeholders.</small>
                </div>
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                               <?= $tpl['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Update Template
                </button>
                <a href="<?= APP_URL ?>/email-templates/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>

<!-- Help card -->
<div class="col-lg-4">
    <div class="card">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-info-circle me-2 text-muted"></i>How It Works</h6>
        </div>
        <div class="card-body px-4">
            <p class="text-muted" style="font-size:.85rem;">
                Each template is identified by a unique <strong>action slug</strong>.
                When the system triggers an event (e.g. <code>forgot_password</code>), it fetches
                the matching active template, replaces <code>{{variable}}</code> placeholders, and sends the email.
            </p>
            <hr>
            <p class="fw-medium mb-1" style="font-size:.85rem;">Built-in variables (always available):</p>
            <ul style="font-size:.82rem;color:#6b7280;padding-left:1rem;">
                <li><code>{{app_name}}</code> – application name</li>
            </ul>
        </div>
    </div>
</div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
