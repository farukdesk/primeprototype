<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('leads');
require_once __DIR__ . '/helpers.php';

if (!is_super_admin()) {
    flash_set('error', 'Only super-admins can manage Facebook settings.');
    redirect(APP_URL . '/leads/index.php');
}

$page_title = 'Facebook Messenger Settings';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $page_id           = trim($_POST['page_id']           ?? '');
    $page_access_token = trim($_POST['page_access_token'] ?? '');
    $verify_token      = trim($_POST['verify_token']      ?? '');
    $app_secret        = trim($_POST['app_secret']        ?? '');

    if ($verify_token === '') $errors[] = 'Verify Token is required.';

    if (empty($errors)) {
        leads_fb_setting_set('page_id',           $page_id);
        leads_fb_setting_set('page_access_token', $page_access_token);
        leads_fb_setting_set('verify_token',      $verify_token);
        leads_fb_setting_set('app_secret',        $app_secret);
        flash_set('success', 'Facebook settings saved.');
        redirect(APP_URL . '/leads/fb-settings.php');
    }
}

// Current values
$cur = [
    'page_id'           => leads_fb_setting('page_id'),
    'page_access_token' => leads_fb_setting('page_access_token'),
    'verify_token'      => leads_fb_setting('verify_token'),
    'app_secret'        => leads_fb_setting('app_secret'),
];

$webhook_url = SITE_URL . '/fb-webhook.php';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fab fa-facebook-messenger me-2" style="color:#1877F2"></i>Facebook Messenger Settings</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/leads/index.php">Leads</a></li>
            <li class="breadcrumb-item active">FB Settings</li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/leads/fb-inbox.php" class="btn btn-outline-primary btn-sm"><i class="fab fa-facebook-messenger me-1"></i> FB Inbox</a>
        <a href="<?= APP_URL ?>/leads/index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
    </div>
</div>

<?= flash_show() ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-12 col-lg-7">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="fab fa-facebook me-2 text-primary"></i>Facebook App Credentials</div>
            <div class="card-body">
                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Page ID</label>
                        <input type="text" name="page_id" class="form-control" value="<?= h($cur['page_id']) ?>" placeholder="e.g. 123456789012345">
                        <div class="form-text">Your Facebook Page's numeric ID.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Page Access Token <span class="text-danger">*</span></label>
                        <input type="text" name="page_access_token" class="form-control font-monospace" value="<?= h($cur['page_access_token']) ?>" placeholder="EAAxxxxx…" autocomplete="off">
                        <div class="form-text">Generate a never-expiring token from the Facebook Developer Console → Messenger → Settings → Access Tokens.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Webhook Verify Token <span class="text-danger">*</span></label>
                        <input type="text" name="verify_token" class="form-control font-monospace" value="<?= h($cur['verify_token']) ?>" placeholder="your_secret_verify_token" autocomplete="off">
                        <div class="form-text">A string you choose. Enter the same value in your Facebook App webhook settings.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">App Secret</label>
                        <input type="text" name="app_secret" class="form-control font-monospace" value="<?= h($cur['app_secret']) ?>" placeholder="Facebook App Secret (optional but recommended)" autocomplete="off">
                        <div class="form-text">Used to verify the <code>X-Hub-Signature-256</code> header on incoming webhooks. Leave blank to skip verification.</div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Settings</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        <!-- Webhook info -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="fas fa-link me-2 text-info"></i>Webhook Configuration</div>
            <div class="card-body">
                <p class="small text-muted mb-2">Enter these values in your <strong>Facebook App → Messenger → Webhooks</strong> settings:</p>
                <table class="table table-sm small mb-3">
                    <tr>
                        <td class="text-muted fw-semibold" style="width:140px">Callback URL</td>
                        <td>
                            <code class="user-select-all"><?= h($webhook_url) ?></code>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 ms-2" onclick="navigator.clipboard.writeText('<?= h($webhook_url) ?>')"><i class="fas fa-copy fa-xs"></i></button>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold">Verify Token</td>
                        <td><?= $cur['verify_token'] !== '' ? '<code>' . h($cur['verify_token']) . '</code>' : '<span class="text-danger">Not set</span>' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold">Subscriptions</td>
                        <td><code>messages</code>, <code>messaging_postbacks</code></td>
                    </tr>
                </table>
                <div class="alert alert-warning small mb-0 py-2">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    The webhook URL must be publicly accessible over HTTPS for Facebook to reach it.
                </div>
            </div>
        </div>

        <!-- Setup steps -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="fas fa-list-ol me-2 text-success"></i>Setup Steps</div>
            <div class="card-body">
                <ol class="small ps-3 mb-0">
                    <li class="mb-2">Go to <a href="https://developers.facebook.com" target="_blank">developers.facebook.com</a> and create/open your App.</li>
                    <li class="mb-2">Under <strong>Messenger → Settings</strong>, generate a Page Access Token for your Page.</li>
                    <li class="mb-2">Paste the token and set a Verify Token above, then save.</li>
                    <li class="mb-2">In the Facebook webhook settings, enter the Callback URL and your Verify Token, and subscribe to <em>messages</em> &amp; <em>messaging_postbacks</em>.</li>
                    <li class="mb-2">Facebook will send a GET request to verify — it will succeed once your settings are saved.</li>
                    <li>Test by sending a message to your Page. It will appear in the <a href="<?= APP_URL ?>/leads/fb-inbox.php">FB Inbox</a>.</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
