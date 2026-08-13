<?php
require_once __DIR__ . '/../includes/auth.php';
require_super_admin();
require_once __DIR__ . '/helpers.php';

$page_title = 'System Backups';
$user = auth_user();

// Generate a cron key on first visit.
if ((string)bk_setting_get('cron_key', '') === '') {
    try { bk_setting_set('cron_key', bin2hex(random_bytes(24))); } catch (Throwable $e) {}
}

$table_missing = false;
try { db()->query('SELECT 1 FROM sys_backups LIMIT 1'); } catch (Throwable $e) { $table_missing = true; }

// Mark backups stuck in "running" (process died / timed out) as failed so
// the history reflects reality and new runs are not blocked.
if (!$table_missing) {
    $stale_marked = bk_mark_stale();
    if ($stale_marked > 0) {
        flash_set('error', $stale_marked . ' backup(s) were stuck in "running" and have been marked as failed. For large sites, prefer the CLI cron over running backups from the browser, and check PHP max_execution_time / memory_limit.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$table_missing) {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'settings') {
        $sa_json = trim((string)($_POST['drive_service_account_json'] ?? ''));
        if ($sa_json !== '') {
            $decoded = json_decode($sa_json, true);
            if (!is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
                flash_set('error', 'That does not look like a valid service account JSON key file.');
                redirect(APP_URL . '/backups/index.php');
            }
            bk_setting_set('drive_service_account_json', $sa_json);
            bk_setting_set('drive_token_cache', null);
        }
        bk_setting_set('drive_folder_id',  trim((string)($_POST['drive_folder_id'] ?? '')));
        bk_setting_set('auto_enabled',     !empty($_POST['auto_enabled']) ? '1' : '0');
        bk_setting_set('auto_scope',       in_array($_POST['auto_scope'] ?? '', ['db', 'files', 'full'], true) ? $_POST['auto_scope'] : 'full');
        bk_setting_set('keep_daily_days',  (string)max(1, (int)($_POST['keep_daily_days'] ?? 7)));
        bk_setting_set('keep_weekly_days', (string)max(1, (int)($_POST['keep_weekly_days'] ?? 30)));
        bk_setting_set('files_part_mb',    (string)min(1024, max(50, (int)($_POST['files_part_mb'] ?? 250))));
        bk_setting_set('exclude_paths',    trim((string)($_POST['exclude_paths'] ?? '')));
        bk_setting_set('oauth_client_id',  trim((string)($_POST['oauth_client_id'] ?? '')));
        $oauth_secret = trim((string)($_POST['oauth_client_secret'] ?? ''));
        if ($oauth_secret !== '') {
            bk_setting_set('oauth_client_secret', $oauth_secret);
        }
        flash_set('success', 'Backup settings saved.');
        redirect(APP_URL . '/backups/index.php');
    }

    if ($action === 'oauth_start') {
        $client_id = trim((string)bk_setting_get('oauth_client_id', ''));
        if ($client_id === '' || (string)bk_setting_get('oauth_client_secret', '') === '') {
            flash_set('error', 'Save the OAuth Client ID and Client Secret first, then click Connect.');
            redirect(APP_URL . '/backups/index.php');
        }
        $state = bin2hex(random_bytes(16));
        bk_setting_set('oauth_state', $state);
        redirect('https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'     => $client_id,
            'redirect_uri'  => APP_URL . '/backups/oauth-callback.php',
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/drive',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ]));
    }

    if ($action === 'oauth_disconnect') {
        bk_setting_set('oauth_refresh_token', null);
        bk_setting_set('drive_token_cache', null);
        flash_set('success', 'Google account disconnected. Backups will use the service account (if configured).');
        redirect(APP_URL . '/backups/index.php');
    }

    if ($action === 'test') {
        try {
            flash_set('success', bk_drive_test());
        } catch (Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        redirect(APP_URL . '/backups/index.php');
    }
}

$backups = [];
if (!$table_missing) {
    try {
        $backups = db()->query(
            'SELECT b.*, u.full_name AS creator_name, r.full_name AS restorer_name
             FROM sys_backups b
             LEFT JOIN users u ON u.id = b.created_by
             LEFT JOIN users r ON r.id = b.restored_by
             ORDER BY b.id DESC LIMIT 200'
        )->fetchAll();
    } catch (Throwable $e) {}
}

$sa_configured = (bool)json_decode((string)bk_setting_get('drive_service_account_json', ''), true);
$sa_email = '';
if ($sa_configured) {
    $sa_email = (string)(json_decode((string)bk_setting_get('drive_service_account_json'), true)['client_email'] ?? '');
}
$oauth_connected = (string)bk_setting_get('oauth_refresh_token', '') !== ''
                && (string)bk_setting_get('oauth_client_id', '') !== '';
$drive_ready = $sa_configured || $oauth_connected;
$cron_key = (string)bk_setting_get('cron_key', '');
$cron_url = APP_URL . '/backups/cron.php?key=' . $cron_key;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">System Backups</li>
        </ol>
    </nav>
    <span class="badge bg-dark"><i class="fas fa-user-shield me-1"></i> Super Admin Only</span>
</div>

<?php flash_show(); ?>

<?php if ($table_missing): ?>
<div class="alert alert-warning">
    <strong>Backup tables not found.</strong> Run the migration <code>admin/backups.sql</code> once, then reload this page.
</div>
<?php else: ?>

<div class="row g-3 mb-3">
    <!-- ── Manual backup ── -->
    <div class="col-lg-5">
        <div class="card h-100" style="border-radius:12px;">
            <div class="card-header py-3 px-4"><h6 class="mb-0 fw-semibold"><i class="fas fa-play me-2 text-primary"></i>Manual Backup</h6></div>
            <div class="card-body p-4">
                <p class="text-muted" style="font-size:.85rem;">
                    Creates the backup, uploads it to <strong>Google Drive</strong> and deletes the local copy
                    immediately – no space is used on this server. The files backup is split into small
                    <strong>parts</strong> uploaded one at a time, so even very large sites back up without
                    running out of memory. Large sites may take several minutes; keep the tab open (or use the cron).
                </p>
                <form method="POST" action="run.php" class="d-grid gap-2">
                    <?= csrf_field() ?>
                    <button name="scope" value="full"  class="btn btn-primary"        <?= $drive_ready ? '' : 'disabled' ?>><i class="fas fa-cloud-upload-alt me-1"></i> Full Backup (DB + Files)</button>
                    <button name="scope" value="db"    class="btn btn-outline-primary" <?= $drive_ready ? '' : 'disabled' ?>><i class="fas fa-database me-1"></i> Database Only</button>
                    <button name="scope" value="files" class="btn btn-outline-primary" <?= $drive_ready ? '' : 'disabled' ?>><i class="fas fa-folder me-1"></i> Files Only</button>
                </form>
                <?php if (!$drive_ready): ?>
                <div class="alert alert-warning mt-3 mb-0 py-2" style="font-size:.82rem;">Configure Google Drive first (right panel).</div>
                <?php endif; ?>

                <hr>
                <h6 class="fw-semibold" style="font-size:.85rem;"><i class="fas fa-clock me-1 text-muted"></i> Daily Auto Backup</h6>
                <p class="text-muted mb-1" style="font-size:.82rem;">Add ONE of these to your server's cron (cPanel → Cron Jobs), scheduled once per day:</p>
                <code style="font-size:.72rem;display:block;word-break:break-all;">php <?= h(realpath(__DIR__) ?: __DIR__) ?>/cron.php</code>
                <div class="text-muted my-1" style="font-size:.75rem;">or via URL:</div>
                <code style="font-size:.72rem;display:block;word-break:break-all;">wget -qO- "<?= h($cron_url) ?>"</code>
                <p class="text-muted mt-2 mb-0" style="font-size:.78rem;">
                    Daily backups are kept <strong><?= h(bk_setting_get('keep_daily_days', '7')) ?> days</strong>;
                    the Sunday backup is kept <strong><?= h(bk_setting_get('keep_weekly_days', '30')) ?> days</strong>.
                    Expired backups are removed from Google Drive automatically.
                </p>
            </div>
        </div>
    </div>

    <!-- ── Settings ── -->
    <div class="col-lg-7">
        <div class="card h-100" style="border-radius:12px;">
            <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-semibold"><i class="fab fa-google-drive me-2 text-success"></i>Google Drive &amp; Backup Settings</h6>
                <form method="POST" class="m-0"><?= csrf_field() ?><input type="hidden" name="action" value="test">
                    <button class="btn btn-sm btn-outline-success" <?= $drive_ready ? '' : 'disabled' ?>><i class="fas fa-plug me-1"></i> Test Connection</button>
                </form>
            </div>
            <div class="card-body p-4">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="settings">
                    <div class="mb-3">
                        <label class="form-label fw-medium" style="font-size:.85rem;">Service Account JSON key
                            <?php if ($sa_configured): ?><span class="badge bg-success ms-1">Configured</span><?php endif; ?>
                        </label>
                        <textarea name="drive_service_account_json" rows="3" class="form-control" style="font-size:.75rem;font-family:monospace;"
                                  placeholder="<?= $sa_configured ? 'Configured (' . h($sa_email) . ') – paste new JSON only to replace' : 'Paste the JSON key file of your Google Cloud service account here' ?>"></textarea>
                        <div class="form-text" style="font-size:.75rem;">
                            Google Cloud Console → create a project → enable <strong>Drive API</strong> → create a
                            <strong>Service Account</strong> → add a JSON key. Then <strong>share your Drive backup folder</strong>
                            with the service account email (Editor).
                        </div>
                    </div>
                    <div class="mb-3 p-3 rounded border" style="background:#f8f9fa;">
                        <label class="form-label fw-medium mb-1" style="font-size:.85rem;">
                            <i class="fab fa-google me-1"></i> Personal Google account (OAuth) – recommended for personal Gmail
                            <?php if ($oauth_connected): ?><span class="badge bg-success ms-1">Connected</span><?php endif; ?>
                        </label>
                        <div class="form-text mb-2" style="font-size:.75rem;">
                            Service accounts have <strong>no Drive storage quota</strong>, so uploads into a personal
                            “My Drive” folder fail with HTTP 403. Instead: Google Cloud Console → create an
                            <strong>OAuth client ID</strong> (type: <em>Web application</em>) → add
                            <code><?= h(APP_URL) ?>/backups/oauth-callback.php</code> as an authorised redirect URI →
                            paste the Client ID/Secret below, <strong>Save Settings</strong>, then click
                            <strong>Connect Google Account</strong>. Backups will upload using that account's own storage.
                        </div>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <input type="text" name="oauth_client_id" class="form-control form-control-sm"
                                       placeholder="OAuth Client ID" value="<?= h(bk_setting_get('oauth_client_id', '')) ?>">
                            </div>
                            <div class="col-md-6">
                                <input type="password" name="oauth_client_secret" class="form-control form-control-sm"
                                       placeholder="<?= (string)bk_setting_get('oauth_client_secret', '') !== '' ? 'Secret saved – type to replace' : 'OAuth Client Secret' ?>">
                            </div>
                        </div>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-medium" style="font-size:.85rem;">Drive Folder ID</label>
                            <input type="text" name="drive_folder_id" class="form-control form-control-sm"
                                   value="<?= h(bk_setting_get('drive_folder_id', '')) ?>" placeholder="e.g. 1AbCdEfGh… (from the folder URL)">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-medium" style="font-size:.85rem;">Auto backup scope</label>
                            <select name="auto_scope" class="form-select form-select-sm">
                                <?php $sc = bk_setting_get('auto_scope', 'full'); ?>
                                <option value="full"  <?= $sc === 'full' ? 'selected' : '' ?>>Full (DB + Files)</option>
                                <option value="db"    <?= $sc === 'db' ? 'selected' : '' ?>>Database only</option>
                                <option value="files" <?= $sc === 'files' ? 'selected' : '' ?>>Files only</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-2">
                            <label class="form-label fw-medium" style="font-size:.85rem;">Keep daily (days)</label>
                            <input type="number" min="1" name="keep_daily_days" class="form-control form-control-sm" value="<?= h(bk_setting_get('keep_daily_days', '7')) ?>">
                        </div>
                        <div class="col-md-3 mb-2">
                            <label class="form-label fw-medium" style="font-size:.85rem;">Keep weekly (days)</label>
                            <input type="number" min="1" name="keep_weekly_days" class="form-control form-control-sm" value="<?= h(bk_setting_get('keep_weekly_days', '30')) ?>">
                        </div>
                        <div class="col-md-3 mb-2">
                            <label class="form-label fw-medium" style="font-size:.85rem;" title="The files backup is split into zip parts of this size, uploaded one at a time – keeps memory and disk usage low">Files part size (MB)</label>
                            <input type="number" min="50" max="1024" name="files_part_mb" class="form-control form-control-sm" value="<?= h(bk_setting_get('files_part_mb', '250')) ?>">
                        </div>
                        <div class="col-md-3 mb-2 d-flex align-items-end">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="auto_enabled" id="auto_enabled" <?= bk_setting_get('auto_enabled', '0') === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label fw-medium" for="auto_enabled" style="font-size:.85rem;">Enable daily auto backup</label>
                            </div>
                        </div>
                        <div class="col-12 mb-2">
                            <label class="form-label fw-medium" style="font-size:.85rem;">Exclude paths from files backup (one per line, relative to site root)</label>
                            <textarea name="exclude_paths" rows="2" class="form-control" style="font-size:.78rem;font-family:monospace;" placeholder="e.g. uploads/tmp"><?= h(bk_setting_get('exclude_paths', '')) ?></textarea>
                        </div>
                    </div>
                    <button class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i> Save Settings</button>
                </form>
                <div class="d-flex gap-2 mt-2">
                    <form method="POST" class="m-0"><?= csrf_field() ?><input type="hidden" name="action" value="oauth_start">
                        <button class="btn btn-outline-primary btn-sm"><i class="fab fa-google me-1"></i> Connect Google Account</button>
                    </form>
                    <?php if ($oauth_connected): ?>
                    <form method="POST" class="m-0"><?= csrf_field() ?><input type="hidden" name="action" value="oauth_disconnect">
                        <button class="btn btn-outline-danger btn-sm">Disconnect Google Account</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Backup history ── -->
<div class="card" style="border-radius:12px;">
    <div class="card-header py-3 px-4"><h6 class="mb-0 fw-semibold"><i class="fas fa-history me-2 text-muted"></i>Backup History (stored in Google Drive)</h6></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size:.85rem;">
            <thead class="table-light">
                <tr>
                    <th>#</th><th>When</th><th>Type</th><th>Scope</th><th>Retention</th>
                    <th>DB</th><th>Files</th><th>Status</th><th>By</th><th class="text-end pe-3">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($backups)): ?>
                <tr><td colspan="10" class="text-center text-muted py-4">No backups yet. Run a manual backup or enable the daily cron.</td></tr>
            <?php endif; ?>
            <?php foreach ($backups as $b): ?>
                <tr>
                    <td class="text-muted"><?= (int)$b['id'] ?></td>
                    <td style="white-space:nowrap;"><?= h(date('d M Y H:i', strtotime($b['created_at']))) ?></td>
                    <td><?= $b['backup_type'] === 'auto' ? '<span class="badge bg-info text-dark">Auto</span>' : '<span class="badge bg-secondary">Manual</span>' ?></td>
                    <td><?= h(strtoupper($b['scope'])) ?></td>
                    <td class="text-muted"><?= h($b['retention_class']) ?></td>
                    <td><?= $b['db_drive_id'] ? h(bk_fmt_bytes($b['db_size_bytes'])) : '—' ?></td>
                    <td><?= $b['files_drive_id'] ? h(bk_fmt_bytes($b['files_size_bytes'])) : '—' ?></td>
                    <td><?= bk_status_badge($b['status']) ?>
                        <?php if ($b['restored_at']): ?><span class="badge bg-warning text-dark" title="Restored <?= h($b['restored_at']) ?> by <?= h($b['restorer_name'] ?? '') ?>">Restored</span><?php endif; ?>
                    </td>
                    <td><?= h($b['creator_name'] ?? 'Cron') ?></td>
                    <td class="text-end pe-3" style="white-space:nowrap;">
                        <?php if ($b['status'] === 'completed'): ?>
                        <form method="POST" action="restore.php" class="d-inline"
                              onsubmit="return confirm('RESTORE backup #<?= (int)$b['id'] ?>? This OVERWRITES current live data with the backup. Continue?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                            <?php if ($b['db_drive_id']): ?>
                            <button name="what" value="db" class="btn btn-sm btn-outline-warning" title="Restore database"><i class="fas fa-database"></i> Restore DB</button>
                            <?php endif; ?>
                            <?php if ($b['files_drive_id']): ?>
                            <button name="what" value="files" class="btn btn-sm btn-outline-warning" title="Restore files"><i class="fas fa-folder"></i> Restore Files</button>
                            <?php endif; ?>
                        </form>
                        <?php endif; ?>
                        <form method="POST" action="delete.php" class="d-inline"
                              onsubmit="return confirm('Delete backup #<?= (int)$b['id'] ?> from Google Drive permanently?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
                        </form>
                        <?php if (!empty($b['log'])): ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary" title="Log" onclick="alert(this.dataset.log)" data-log="<?= h($b['log']) ?>"><i class="fas fa-file-alt"></i></button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
