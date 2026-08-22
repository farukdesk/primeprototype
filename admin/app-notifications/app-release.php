<?php
/**
 * App Notification – App Release (self-hosted update prompt)
 * ===========================================================
 * Publishes the latest Android app version for the self-hosted update
 * prompt. Installed apps poll admin/api/app-version.php on launch and show
 * an "Update available" dialog when the published versionCode is greater
 * than the installed build — but ONLY when the APK download URL is set.
 *
 * Values are stored in the `settings` table (group "app"):
 *   app_latest_version_code, app_latest_version_name, app_apk_url,
 *   app_update_notes, app_force_update
 */

require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('app-notifications');
require_once __DIR__ . '/helpers.php';

$page_title = 'App Release';
$errors     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $code  = (int)($_POST['version_code'] ?? 0);
    $name  = trim($_POST['version_name'] ?? '');
    $url   = trim($_POST['apk_url'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $force = isset($_POST['force_update']) ? '1' : '0';

    if ($code < 1) {
        $errors[] = 'Version code must be a positive integer (the versionCode from app/build.gradle, e.g. 8).';
    }
    if ($name === '') {
        $errors[] = 'Version name is required (e.g. 1.0.8).';
    }
    if ($url === '' ) {
        $errors[] = 'The APK download URL is required — the app never shows the update prompt while it is empty.';
    } elseif (!filter_var($url, FILTER_VALIDATE_URL)) {
        $errors[] = 'The APK download URL must be a valid absolute URL (https://…).';
    }

    if (empty($errors)) {
        apn_setting_set('app_latest_version_code', (string)$code, 'Latest app versionCode', 'app');
        apn_setting_set('app_latest_version_name', $name,         'Latest app version',     'app');
        apn_setting_set('app_apk_url',             $url,          'APK download URL',       'app');
        apn_setting_set('app_update_notes',        $notes,        'App update notes',       'app');
        apn_setting_set('app_force_update',        $force,        'Force app update',       'app');
        flash_set('success', 'App release published. Devices on older versions will see the update prompt on their next app launch.');
        redirect(APP_URL . '/app-notifications/app-release.php');
    }
}

$cur_code  = apn_setting_get('app_latest_version_code', '');
$cur_name  = apn_setting_get('app_latest_version_name', '');
$cur_url   = apn_setting_get('app_apk_url', '');
$cur_notes = apn_setting_get('app_update_notes', '');
$cur_force = apn_setting_get('app_force_update', '0') === '1';

$api_url = rtrim(defined('SITE_URL') ? SITE_URL : '', '/') . '/admin/api/app-version.php';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/app-notifications/index.php">App Notification</a></li>
            <li class="breadcrumb-item active">App Release</li>
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
                <h2 class="h6 mb-0"><i class="fas fa-rocket me-2 text-primary"></i>Publish App Release</h2>
            </div>
            <div class="card-body">
                <?php if ($cur_url === '' || (int)$cur_code < 1): ?>
                <div class="alert alert-warning d-flex align-items-center gap-2">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>No release is published yet — <strong>installed apps will never show the update prompt</strong> until a version code and APK URL are saved here.</div>
                </div>
                <?php endif; ?>

                <form method="post" action="<?= APP_URL ?>/app-notifications/app-release.php">
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Version code <span class="text-danger">*</span></label>
                            <input type="number" name="version_code" min="1" class="form-control"
                                   value="<?= h($cur_code) ?>" placeholder="e.g. 8" required>
                            <div class="form-text">The <code>versionCode</code> from app/build.gradle.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Version name <span class="text-danger">*</span></label>
                            <input type="text" name="version_name" class="form-control"
                                   value="<?= h($cur_name) ?>" placeholder="e.g. 1.0.8" required>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" id="force_update"
                                       name="force_update" value="1" <?= $cur_force ? 'checked' : '' ?>>
                                <label class="form-check-label" for="force_update">Force update</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">APK download URL <span class="text-danger">*</span></label>
                            <input type="url" name="apk_url" class="form-control"
                                   value="<?= h($cur_url) ?>"
                                   placeholder="https://primeuniversity.ac.bd/downloads/prime-portal-1.0.8.apk" required>
                            <div class="form-text">Absolute URL of the new APK. The update dialog opens this link. <strong>Required</strong> — the app skips the prompt while this is empty.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Release notes (optional)</label>
                            <textarea name="notes" class="form-control" rows="3"
                                      placeholder="What's new in this version…"><?= h($cur_notes) ?></textarea>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary mt-3">
                        <i class="fas fa-save me-1"></i> Publish Release
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><i class="fas fa-satellite-dish me-2 text-primary"></i>What devices see</h2>
            </div>
            <div class="card-body small">
                <p class="mb-2">Apps poll this endpoint on launch:</p>
                <p class="mb-2"><a href="<?= h($api_url) ?>" target="_blank" rel="noopener"><code><?= h($api_url) ?></code></a></p>
                <pre class="bg-light border rounded p-2 mb-0" style="white-space:pre-wrap;">{
  "version_code": <?= (int)$cur_code ?>,
  "version_name": "<?= h($cur_name) ?>",
  "apk_url": "<?= h($cur_url) ?>",
  "force": <?= $cur_force ? 'true' : 'false' ?>
}</pre>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><i class="fas fa-info-circle me-2 text-primary"></i>How the prompt works</h2>
            </div>
            <div class="card-body small">
                <ol class="ps-3 mb-0">
                    <li class="mb-2">The dialog shows when the published <strong>version code</strong> is greater than the installed build's.</li>
                    <li class="mb-2">Only <strong>self-hosted</strong> flavor builds show the prompt — Google Play builds never self-update (Play policy).</li>
                    <li class="mb-2">The check runs once per app process, on the home screen.</li>
                    <li class="mb-0"><strong>Force update</strong> makes the dialog non-dismissable.</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
