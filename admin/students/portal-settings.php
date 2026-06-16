<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('student-portal-settings');
require_once __DIR__ . '/../includes/mailer.php';

$page_title = 'Student Portal Settings';
$errors     = [];
$saved      = false;

// ── Setting helpers ──────────────────────────────────────────────────────────

function sp_get(string $key, string $default = ''): string
{
    static $cache = [];
    if (!isset($cache[$key])) {
        $stmt = db()->prepare('SELECT setting_value FROM student_portal_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        $cache[$key] = $row ? (string)$row['setting_value'] : $default;
    }
    return $cache[$key];
}

function sp_set(string $key, string $value): void
{
    db()->prepare(
        'INSERT INTO student_portal_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    )->execute([$key, $value]);
}

// ── Save settings ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $email_enabled      = isset($_POST['email_enabled'])  ? '1' : '0';
    $sms_enabled        = isset($_POST['sms_enabled'])    ? '1' : '0';
    $sms_template       = trim($_POST['sms_template']     ?? '');
    $default_group_name = trim($_POST['default_group_name'] ?? 'Students');
    $sms_sender_id      = trim($_POST['sms_sender_id']    ?? '');
    $new_api_key        = trim($_POST['sms_api_key']      ?? '');

    if ($sms_enabled === '1') {
        if ($sms_sender_id === '') {
            $errors[] = 'SMS Sender ID is required when SMS is enabled.';
        }
        if ($sms_template === '') {
            $errors[] = 'SMS template is required when SMS is enabled.';
        }
    }

    if (empty($errors)) {
        sp_set('email_enabled',       $email_enabled);
        sp_set('sms_enabled',         $sms_enabled);
        sp_set('sms_template',        $sms_template);
        sp_set('default_group_name',  $default_group_name);
        sp_set('sms_sender_id',       $sms_sender_id);

        // Only update API key if a new value was provided
        if ($new_api_key !== '') {
            sp_set('sms_api_key', $new_api_key);
        }

        flash_set('success', 'Student portal settings saved.');
        redirect(APP_URL . '/students/portal-settings.php');
    }
}

// ── Send test SMS ─────────────────────────────────────────────────────────────
$test_msg = '';
if (($_GET['action'] ?? '') === 'test_sms') {
    $test_phone = trim($_GET['phone'] ?? '');
    if ($test_phone !== '') {
        require_once __DIR__ . '/helpers.php';
        $ok = sp_send_sms($test_phone, 'Test message from ' . APP_NAME . ' Student Portal settings.');
        $test_msg = $ok
            ? '<div class="alert alert-success">Test SMS sent to ' . h($test_phone) . '.</div>'
            : '<div class="alert alert-warning">SMS could not be sent. Check API credentials and phone number.</div>';
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/students/index.php">Students</a></li>
            <li class="breadcrumb-item active">Portal Settings</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/email-templates/index.php?search=student_portal" class="btn btn-outline-secondary btn-sm" style="border-radius:10px;">
        <i class="fas fa-envelope me-1"></i> Edit Email Template
    </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger mb-3">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>
<?php flash_show(); ?>
<?= $test_msg ?>

<form method="POST" novalidate>
<?= csrf_field() ?>

<div class="row g-4">

<!-- Email Notifications -->
<div class="col-lg-6">
<div class="card h-100">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-envelope me-2 text-muted"></i>Email Notifications</h6>
    </div>
    <div class="card-body p-4">
        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" id="email_enabled" name="email_enabled"
                   value="1" <?= sp_get('email_enabled', '1') === '1' ? 'checked' : '' ?>>
            <label class="form-check-label fw-medium" for="email_enabled">
                Send welcome email when portal account is created
            </label>
        </div>
        <p class="text-muted" style="font-size:.84rem;">
            When enabled, a welcome email containing the login URL, username and temporary password is
            automatically sent to the student when their portal account is created.
        </p>
        <a href="<?= APP_URL ?>/email-templates/index.php?search=student_portal_welcome"
           class="btn btn-sm btn-outline-primary" style="border-radius:8px;">
            <i class="fas fa-edit me-1"></i> Customise Email Template
        </a>
        <div class="mt-3 p-3" style="background:#f1f5f9;border-radius:10px;font-size:.82rem;color:#475569;">
            <strong>Available variables:</strong><br>
            <code>{{full_name}}</code> &nbsp; <code>{{student_id}}</code> &nbsp;
            <code>{{username}}</code> &nbsp; <code>{{password}}</code> &nbsp;
            <code>{{login_url}}</code> &nbsp; <code>{{app_name}}</code>
        </div>
    </div>
</div>
</div>

<!-- SMS Notifications -->
<div class="col-lg-6">
<div class="card h-100">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-sms me-2 text-muted"></i>SMS Notifications</h6>
    </div>
    <div class="card-body p-4">
        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" id="sms_enabled" name="sms_enabled"
                   value="1" <?= sp_get('sms_enabled', '0') === '1' ? 'checked' : '' ?>>
            <label class="form-check-label fw-medium" for="sms_enabled">
                Send SMS when portal account is created
            </label>
        </div>
        <div class="row g-3">
            <div class="col-12">
                <label class="form-label fw-medium">FastSMS BD – API Key</label>
                <input type="password" name="sms_api_key" class="form-control"
                       placeholder="Leave blank to keep current key"
                       autocomplete="new-password" maxlength="200">
                <small class="text-muted">Enter a new key to update; leave blank to keep the existing key.</small>
            </div>
            <div class="col-12">
                <label class="form-label fw-medium">Sender ID</label>
                <input type="text" name="sms_sender_id" class="form-control"
                       value="<?= h(sp_get('sms_sender_id')) ?>" maxlength="20">
            </div>
            <div class="col-12">
                <label class="form-label fw-medium">SMS Template</label>
                <textarea name="sms_template" class="form-control" rows="4" maxlength="500"
                          style="resize:vertical;font-size:.875rem;"><?= h(sp_get('sms_template',
                    'Dear {{student_name}}, your Student Portal account is ready. Username: {{username}} Password: {{password}} Login: {{login_url}}')) ?></textarea>
                <small class="text-muted">Variables: <code>{{student_name}}</code> &nbsp; <code>{{username}}</code> &nbsp; <code>{{password}}</code> &nbsp; <code>{{login_url}}</code></small>
            </div>
            <!-- Test SMS -->
            <div class="col-12">
                <label class="form-label fw-medium">Test SMS</label>
                <div class="input-group">
                    <input type="text" id="testPhone" class="form-control"
                           placeholder="01xxxxxxxxx" maxlength="14">
                    <button type="button" class="btn btn-outline-secondary" style="border-radius:0 8px 8px 0;"
                            onclick="sendTestSms()">
                        <i class="fas fa-paper-plane me-1"></i> Send Test
                    </button>
                </div>
                <small class="text-muted">Save settings first, then test.</small>
            </div>
        </div>
    </div>
</div>
</div>

<!-- Default Group -->
<div class="col-lg-6">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-layer-group me-2 text-muted"></i>Default User Group</h6>
    </div>
    <div class="card-body p-4">
        <label class="form-label fw-medium">Group Name <span class="text-danger">*</span></label>
        <select name="default_group_name" class="form-select" required>
            <?php
            $groups = db()->query('SELECT name FROM user_groups WHERE is_active = 1 ORDER BY name')->fetchAll();
            $current_group = sp_get('default_group_name', 'Students');
            foreach ($groups as $g):
            ?>
            <option value="<?= h($g['name']) ?>" <?= $g['name'] === $current_group ? 'selected' : '' ?>>
                <?= h($g['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <small class="text-muted">
            Newly created portal accounts will be assigned to this group.
            Create a <strong>Students</strong> group in
            <a href="<?= APP_URL ?>/user-groups/index.php">User Groups</a> if it does not exist.
        </small>
    </div>
</div>
</div>

<!-- Help card -->
<div class="col-lg-6">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-info-circle me-2 text-muted"></i>How It Works</h6>
    </div>
    <div class="card-body p-4" style="font-size:.875rem;color:#475569;">
        <ol class="ps-3 mb-0" style="line-height:1.8;">
            <li>Open a student record and click <strong>Create Portal Account</strong>.</li>
            <li>A user account is created with the student's email and student ID as username.</li>
            <li>A random secure password is generated and (if enabled) emailed to the student.</li>
            <li>An SMS notification is sent to the student's phone number (if enabled).</li>
            <li>Students can log in using their <strong>Student ID</strong> or <strong>Email</strong> as username.</li>
            <li>Password reset is available via student ID or registered email at the login page.</li>
        </ol>
    </div>
</div>
</div>

</div><!-- /.row -->

<div class="mt-4">
    <button type="submit" class="btn btn-primary" style="border-radius:10px;">
        <i class="fas fa-save me-1"></i> Save Settings
    </button>
    <a href="<?= APP_URL ?>/students/index.php" class="btn btn-light ms-2" style="border-radius:10px;">Cancel</a>
</div>

</form>

<script>
function sendTestSms() {
    var phone = document.getElementById('testPhone').value.trim();
    if (!phone) { alert('Enter a phone number first.'); return; }
    window.location.href = '<?= APP_URL ?>/students/portal-settings.php?action=test_sms&phone=' + encodeURIComponent(phone);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
