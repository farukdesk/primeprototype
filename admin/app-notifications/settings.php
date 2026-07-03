<?php
/**
 * App Notification – FCM Settings
 * ===============================
 * Store the Firebase service-account credential (JSON) used by the FCM HTTP v1
 * sender. Restricted to super admins because it holds a private key.
 */

require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';

if (!is_super_admin()) {
    flash_set('error', 'Only super administrators can manage FCM settings.');
    redirect(APP_URL . '/app-notifications/index.php');
}

$page_title = 'FCM Settings';
$errors     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $json = trim($_POST['service_account'] ?? '');

    if ($json === '') {
        // Empty submission clears the credential.
        apn_setting_set(APN_SETTING_KEY, '', 'FCM service account (HTTP v1)', 'push');
        flash_set('success', 'FCM credential cleared.');
        redirect(APP_URL . '/app-notifications/settings.php');
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        $errors[] = 'The service-account credential must be valid JSON.';
    } elseif (empty($decoded['client_email']) || empty($decoded['private_key']) || empty($decoded['project_id'])) {
        $errors[] = 'The JSON is missing required fields (project_id, client_email, private_key). Make sure you pasted a Firebase service-account key.';
    } elseif (($decoded['type'] ?? '') !== 'service_account') {
        $errors[] = 'This does not look like a service-account key ("type" must be "service_account").';
    }

    if (empty($errors)) {
        apn_setting_set(APN_SETTING_KEY, $json, 'FCM service account (HTTP v1)', 'push');
        flash_set('success', 'FCM credential saved. Push notifications are now enabled.');
        redirect(APP_URL . '/app-notifications/settings.php');
    }
}

$sa         = apn_fcm_service_account();
$configured = $sa !== null;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/app-notifications/index.php">App Notification</a></li>
            <li class="breadcrumb-item active">FCM Settings</li>
        </ol>
    </nav>
</div>

<?php flash_show(); ?>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><i class="fas fa-key me-2 text-primary"></i>Firebase Cloud Messaging Credential</h2>
            </div>
            <div class="card-body">
                <?php if ($configured): ?>
                <div class="alert alert-success d-flex align-items-center gap-2">
                    <i class="fas fa-check-circle"></i>
                    <div>
                        Configured for project <strong><?= h($sa['project_id']) ?></strong>
                        (<code><?= h($sa['client_email']) ?></code>).
                    </div>
                </div>
                <?php endif; ?>

                <form method="post" action="<?= APP_URL ?>/app-notifications/settings.php">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label">Service-account JSON</label>
                        <textarea name="service_account" class="form-control font-monospace" rows="12"
                                  placeholder='{&#10;  "type": "service_account",&#10;  "project_id": "…",&#10;  "private_key": "…",&#10;  "client_email": "…"&#10;}'></textarea>
                        <div class="form-text">
                            Paste the JSON downloaded from the Firebase console
                            (Project settings → Service accounts → Generate new private key).
                            Leave empty and save to remove the current credential.
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Save Credential
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><i class="fas fa-info-circle me-2 text-primary"></i>Setup Steps</h2>
            </div>
            <div class="card-body small">
                <ol class="ps-3 mb-0">
                    <li class="mb-2">Create a Firebase project and add the Android app (package <code>bd.ac.primeuniversity.studentportal</code>).</li>
                    <li class="mb-2">Download <code>google-services.json</code> into the app and rebuild.</li>
                    <li class="mb-2">In Firebase, open <em>Project settings → Service accounts</em> and generate a new private key.</li>
                    <li class="mb-0">Paste the downloaded JSON here and save.</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
