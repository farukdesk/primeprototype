<?php
/**
 * Student Portal – Global Notice
 * Lets super admins publish a highlighted banner notice visible to ALL
 * students every time they open the portal.
 */
require_once __DIR__ . '/../includes/auth.php';
if (!is_super_admin()) {
    flash_set('error', 'You do not have permission to access this section.');
    redirect(APP_URL . '/index.php');
}

$page_title = 'Portal Global Notice';

// ── Helper: read the single notice row ──────────────────────────────────────
function pgn_get(): array
{
    try {
        $row = db()->query('SELECT * FROM portal_global_notice WHERE id = 1 LIMIT 1')->fetch();
        return $row ?: ['id' => 1, 'is_active' => 0, 'notice_type' => 'warning', 'title' => '', 'message' => ''];
    } catch (Throwable $e) {
        return ['id' => 1, 'is_active' => 0, 'notice_type' => 'warning', 'title' => '', 'message' => ''];
    }
}

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $is_active   = isset($_POST['is_active'])   ? 1 : 0;
    $notice_type = in_array($_POST['notice_type'] ?? '', ['info', 'warning', 'danger', 'success'], true)
                   ? $_POST['notice_type']
                   : 'warning';
    $title   = trim($_POST['title']   ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($is_active && $message === '') {
        flash_set('error', 'Please enter a notice message before activating the banner.');
        redirect(APP_URL . '/students/portal-global-notice.php');
    }

    try {
        db()->prepare(
            'INSERT INTO portal_global_notice (id, is_active, notice_type, title, message)
             VALUES (1, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               is_active   = VALUES(is_active),
               notice_type = VALUES(notice_type),
               title       = VALUES(title),
               message     = VALUES(message)'
        )->execute([$is_active, $notice_type, $title ?: null, $message]);

        flash_set('success', $is_active
            ? 'Global notice activated – all students will see it on the portal.'
            : 'Global notice saved (currently inactive).');
    } catch (Throwable $e) {
        flash_set('error', 'Could not save the notice. Please ensure the database migration has been run.');
    }

    redirect(APP_URL . '/students/portal-global-notice.php');
}

$notice = pgn_get();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-1 fw-semibold">
            <i class="fas fa-bullhorn me-2 text-warning"></i>Portal Global Notice
        </h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/students/index.php">Students</a></li>
            <li class="breadcrumb-item active">Global Notice</li>
        </ol></nav>
    </div>
    <span class="badge <?= $notice['is_active'] ? 'bg-success' : 'bg-secondary' ?> fs-6 px-3 py-2">
        <i class="fas <?= $notice['is_active'] ? 'fa-circle-dot' : 'fa-circle' ?> me-1"></i>
        <?= $notice['is_active'] ? 'Active' : 'Inactive' ?>
    </span>
</div>

<?= flash_show() ?>

<!-- Preview card (shown when a message exists) -->
<?php if ($notice['message'] !== ''): ?>
<?php
$_type_map = [
    'info'    => ['bg' => '#dbeafe', 'border' => '#3b82f6', 'icon' => 'fa-circle-info',       'text' => '#1e40af'],
    'warning' => ['bg' => '#fef9c3', 'border' => '#f59e0b', 'icon' => 'fa-triangle-exclamation','text' => '#78350f'],
    'danger'  => ['bg' => '#fee2e2', 'border' => '#ef4444', 'icon' => 'fa-circle-exclamation', 'text' => '#991b1b'],
    'success' => ['bg' => '#d1fae5', 'border' => '#10b981', 'icon' => 'fa-circle-check',       'text' => '#065f46'],
];
$_s = $_type_map[$notice['notice_type']] ?? $_type_map['warning'];
?>
<div class="card border-0 mb-4 shadow-sm">
    <div class="card-header py-3 px-4 border-0" style="background:#f8fafc;">
        <h6 class="mb-0 fw-semibold text-muted"><i class="fas fa-eye me-2"></i>Live Preview (as seen by students)</h6>
    </div>
    <div class="card-body px-4 py-3">
        <div style="background:<?= $_s['bg'] ?>;border-left:5px solid <?= $_s['border'] ?>;border-radius:0 10px 10px 0;padding:16px 20px;display:flex;gap:14px;align-items:flex-start;">
            <i class="fas <?= $_s['icon'] ?>" style="color:<?= $_s['border'] ?>;font-size:1.25rem;margin-top:2px;flex-shrink:0;"></i>
            <div>
                <?php if ($notice['title'] !== '' && $notice['title'] !== null): ?>
                <div style="font-weight:700;font-size:.95rem;color:<?= $_s['text'] ?>;margin-bottom:4px;"><?= h($notice['title']) ?></div>
                <?php endif; ?>
                <div style="font-size:.9rem;color:<?= $_s['text'] ?>;line-height:1.6;"><?= nl2br(h($notice['message'])) ?></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-pen-to-square me-2 text-muted"></i>Edit Global Notice</h6>
    </div>
    <div class="card-body p-4">
        <form method="POST" novalidate>
            <?= csrf_field() ?>

            <!-- Active toggle -->
            <div class="mb-4 p-3 rounded-3" style="background:#f8fafc;border:1px solid #e5e7eb;">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                           value="1" <?= $notice['is_active'] ? 'checked' : '' ?> style="width:2.5em;height:1.4em;">
                    <label class="form-check-label fw-semibold ms-2 fs-6" for="is_active">
                        Show notice to all students
                    </label>
                </div>
                <small class="text-muted d-block mt-1 ms-5">
                    When enabled, this banner appears at the top of every portal page for all students.
                </small>
            </div>

            <!-- Notice type -->
            <div class="mb-3">
                <label class="form-label fw-medium">Notice Type (colour)</label>
                <div class="d-flex flex-wrap gap-3">
                    <?php foreach ([
                        'warning' => ['label' => 'Warning',  'color' => '#f59e0b', 'icon' => 'fa-triangle-exclamation'],
                        'info'    => ['label' => 'Info',     'color' => '#3b82f6', 'icon' => 'fa-circle-info'],
                        'danger'  => ['label' => 'Urgent',   'color' => '#ef4444', 'icon' => 'fa-circle-exclamation'],
                        'success' => ['label' => 'Positive', 'color' => '#10b981', 'icon' => 'fa-circle-check'],
                    ] as $val => $meta): ?>
                    <label class="d-flex align-items-center gap-2 px-3 py-2 rounded-3 cursor-pointer type-option"
                           style="border:2px solid <?= $notice['notice_type'] === $val ? $meta['color'] : '#e5e7eb' ?>;cursor:pointer;background:<?= $notice['notice_type'] === $val ? 'rgba(0,0,0,.03)' : '#fff' ?>;"
                           data-color="<?= $meta['color'] ?>">
                        <input type="radio" name="notice_type" value="<?= $val ?>"
                               <?= $notice['notice_type'] === $val ? 'checked' : '' ?>
                               class="d-none type-radio">
                        <i class="fas <?= $meta['icon'] ?>" style="color:<?= $meta['color'] ?>;font-size:1.1rem;"></i>
                        <span class="fw-medium" style="font-size:.9rem;"><?= $meta['label'] ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Title -->
            <div class="mb-3">
                <label for="title" class="form-label fw-medium">
                    Notice Title <span class="text-muted fw-normal">(optional)</span>
                </label>
                <input type="text" id="title" name="title" class="form-control"
                       maxlength="255" placeholder="e.g. Important Announcement"
                       value="<?= h($notice['title'] ?? '') ?>">
                <small class="text-muted">Leave blank to show only the message without a heading.</small>
            </div>

            <!-- Message -->
            <div class="mb-4">
                <label for="message" class="form-label fw-medium">
                    Notice Message <span class="text-danger">*</span>
                </label>
                <textarea id="message" name="message" class="form-control" rows="4"
                          maxlength="1000" placeholder="Type your notice message here…"
                          style="resize:vertical;"><?= h($notice['message'] ?? '') ?></textarea>
                <small class="text-muted">Maximum 1000 characters. Line breaks are preserved.</small>
            </div>

            <div class="d-flex gap-2 flex-wrap">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Save Notice
                </button>
                <a href="<?= APP_URL ?>/students/index.php" class="btn btn-light" style="border-radius:10px;">
                    Cancel
                </a>
                <?php if ($notice['is_active'] && $notice['message'] !== ''): ?>
                <button type="button" class="btn btn-outline-secondary ms-auto" style="border-radius:10px;"
                        onclick="deactivateNotice()">
                    <i class="fas fa-eye-slash me-1"></i> Deactivate
                </button>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($notice['is_active'] && $notice['message'] !== ''): ?>
        <!-- Deactivate form lives outside the main form to comply with HTML spec -->
        <form id="deactivate-form" method="POST" style="display:none;">
            <?= csrf_field() ?>
            <input type="hidden" name="is_active"   value="0">
            <input type="hidden" name="notice_type" value="<?= h($notice['notice_type']) ?>">
            <input type="hidden" name="title"       value="<?= h($notice['title'] ?? '') ?>">
            <input type="hidden" name="message"     value="<?= h($notice['message']) ?>">
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- Help card -->
<div class="card border-0 shadow-sm mt-4">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-circle-info me-2 text-muted"></i>How It Works</h6>
    </div>
    <div class="card-body px-4 py-3" style="font-size:.875rem;color:#475569;">
        <ul class="mb-0 ps-3" style="line-height:2;">
            <li>When <strong>active</strong>, the notice banner appears at the top of every student portal page.</li>
            <li>Choose a <strong>type</strong> to control the colour: Warning (yellow), Info (blue), Urgent (red), or Positive (green).</li>
            <li>An optional <strong>title</strong> is shown in bold above the message.</li>
            <li>Use the <strong>Deactivate</strong> button to hide the banner instantly without deleting the text.</li>
            <li>Only <strong>super admins</strong> can edit this notice.</li>
        </ul>
    </div>
</div>

<style>
.type-option:hover { border-color: #9ca3af !important; }
</style>
<script>
function deactivateNotice() {
    if (!confirm('Deactivate the global notice? Students will no longer see it.')) return;
    document.getElementById('deactivate-form').submit();
}
document.querySelectorAll('.type-radio').forEach(function(radio) {
    radio.addEventListener('change', function() {
        document.querySelectorAll('.type-option').forEach(function(label) {
            label.style.borderColor  = '#e5e7eb';
            label.style.background   = '#fff';
        });
        if (this.checked) {
            var label = this.closest('.type-option');
            label.style.borderColor = label.dataset.color;
            label.style.background  = 'rgba(0,0,0,.03)';
        }
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
