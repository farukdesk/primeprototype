<?php
/**
 * Staff Attendance → Devices (ADMS / iclock).
 * Register ZKTeco Cloud-Server (ADMS) devices by serial number, map device PINs
 * to ERP users, and monitor last-seen / last-push status plus the recent punch
 * and request audit trail. Requires can_edit (module admin).
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('staff-attendance', 'can_edit');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/adms-helpers.php';

$page_title = 'Attendance Devices';
$db         = db();

// The device pushes to this (root-level) URL; the /iclock/ path is fixed by the
// device firmware. Port/scheme depend on how the app is exposed to the device.
$receiver_url = rtrim(SITE_URL, '/') . '/iclock/';

// ── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_device') {
        $id       = (int)($_POST['id'] ?? 0);
        $serial   = trim($_POST['serial_no'] ?? '');
        $name     = mb_substr(trim($_POST['name'] ?? ''), 0, 120);
        $location = mb_substr(trim($_POST['location'] ?? ''), 0, 160);
        $secret   = trim($_POST['secret_key'] ?? '');
        $ips      = trim($_POST['allowed_ips'] ?? '');
        $active   = isset($_POST['is_active']) ? 1 : 0;

        // Normalise the allow-list to a clean CSV of non-empty tokens.
        if ($ips !== '') {
            $parts = array_filter(array_map('trim', explode(',', $ips)), fn($p) => $p !== '');
            $ips   = implode(',', array_slice($parts, 0, 20));
        }
        $secret_val = $secret !== '' ? mb_substr($secret, 0, 128) : null;
        $ips_val    = $ips !== '' ? mb_substr($ips, 0, 255) : null;

        if ($serial === '') {
            flash_set('error', 'Serial number is required.');
        } else {
            // Enforce the unique serial number ourselves for a friendly message.
            $dup = $db->prepare('SELECT id FROM att_devices WHERE serial_no = ? AND id <> ?');
            $dup->execute([$serial, $id]);
            if ($dup->fetchColumn()) {
                flash_set('error', 'Another device already uses that serial number.');
            } elseif ($id > 0) {
                $stmt = $db->prepare(
                    'UPDATE att_devices
                        SET serial_no = ?, name = ?, location = ?, secret_key = ?,
                            allowed_ips = ?, is_active = ?
                      WHERE id = ?'
                );
                $stmt->execute([$serial, $name, $location, $secret_val, $ips_val, $active, $id]);
                log_change('staff-attendance', 'UPDATE', $id, 'Device ' . $serial);
                flash_set('success', 'Device updated.');
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO att_devices (serial_no, name, location, secret_key, allowed_ips, is_active)
                     VALUES (?,?,?,?,?,?)'
                );
                $stmt->execute([$serial, $name, $location, $secret_val, $ips_val, $active]);
                log_change('staff-attendance', 'CREATE', (int)$db->lastInsertId(), 'Device ' . $serial);
                flash_set('success', 'Device registered.');
            }
        }
    } elseif ($action === 'delete_device') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare('DELETE FROM att_devices WHERE id = ?')->execute([$id]);
            $db->prepare('DELETE FROM att_device_users WHERE device_id = ?')->execute([$id]);
            log_change('staff-attendance', 'DELETE', $id, 'Device');
            flash_set('success', 'Device removed.');
        }
    } elseif ($action === 'save_map') {
        $device_id = (int)($_POST['map_device_id'] ?? 0); // 0 = all devices
        $pin       = mb_substr(trim($_POST['pin'] ?? ''), 0, 32);
        $user_id   = (int)($_POST['user_id'] ?? 0);
        $valid_ids = array_map(fn($s) => (int)$s['id'], att_mappable_users());

        if ($pin === '' || $user_id < 1 || !in_array($user_id, $valid_ids, true)) {
            flash_set('error', 'Choose a valid Device User ID and staff member.');
        } else {
            $stmt = $db->prepare(
                'INSERT INTO att_device_users (device_id, pin, user_id, is_active)
                 VALUES (?,?,?,1)
                 ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), is_active = 1'
            );
            $stmt->execute([$device_id, $pin, $user_id]);
            log_change('staff-attendance', 'UPDATE', $user_id, 'Device user id map ' . $pin);
            flash_set('success', 'Device User ID mapping saved.');
        }
    } elseif ($action === 'delete_map') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare('DELETE FROM att_device_users WHERE id = ?')->execute([$id]);
            flash_set('success', 'Device User ID mapping removed.');
        }
    } elseif ($action === 'save_settings') {
        $sys_user = max(0, (int)($_POST['adms_system_user_id'] ?? 0));
        $tz_min   = (int)($_POST['adms_timezone_minutes'] ?? 360);
        if ($tz_min < -720 || $tz_min > 840) $tz_min = 360;
        att_save_setting('adms_system_user_id', (string)$sys_user);
        att_save_setting('adms_timezone_minutes', (string)$tz_min);
        flash_set('success', 'ADMS settings saved.');
    }

    redirect(APP_URL . '/staff-attendance/devices.php');
}

// ── Data for display ─────────────────────────────────────────────────────────
$devices = [];
try {
    $devices = $db->query(
        'SELECT d.*,
                (SELECT COUNT(*) FROM att_punch_log p WHERE p.device_id = d.id) AS punch_count
           FROM att_devices d
       ORDER BY d.name ASC, d.serial_no ASC'
    )->fetchAll();
} catch (Throwable $e) {
    flash_set('warning', 'Device tables are missing – run admin/staff-attendance-adms.sql.');
}

$device_names = [];
foreach ($devices as $d) $device_names[(int)$d['id']] = $d['name'] !== '' ? $d['name'] : $d['serial_no'];

$mappings = [];
try {
    $mappings = $db->query(
        'SELECT m.*, u.full_name, u.username, sp.employee_id
           FROM att_device_users m
      LEFT JOIN users u ON u.id = m.user_id
      LEFT JOIN staff_profiles sp ON sp.user_id = m.user_id
       ORDER BY m.device_id ASC, m.pin ASC'
    )->fetchAll();
} catch (Throwable $e) { /* table missing */ }

$recent_punches = [];
try {
    $recent_punches = $db->query(
        'SELECT p.*, u.full_name
           FROM att_punch_log p
      LEFT JOIN users u ON u.id = p.user_id
       ORDER BY p.punch_time DESC, p.id DESC
          LIMIT 30'
    )->fetchAll();
} catch (Throwable $e) { /* table missing */ }

$recent_requests = [];
try {
    $recent_requests = $db->query(
        'SELECT * FROM att_device_log ORDER BY id DESC LIMIT 30'
    )->fetchAll();
} catch (Throwable $e) { /* table missing */ }

$staff        = att_mappable_users();
$sys_user_id  = (int)att_get_setting('adms_system_user_id', '0');
$tz_minutes   = (int)att_get_setting('adms_timezone_minutes', '360');

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/staff-attendance/index.php">Staff Attendance</a></li>
            <li class="breadcrumb-item active">Devices</li>
        </ol>
    </nav>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#deviceModal"
            onclick="admsResetDeviceForm()">
        <i class="fas fa-plus me-1"></i> Register Device
    </button>
</div>

<?= flash_show() ?>

<div class="alert alert-info d-flex align-items-start gap-2" role="alert">
    <i class="fas fa-circle-info mt-1"></i>
    <div class="small">
        On the device set <strong>Comm. → Cloud Server (ADMS)</strong>:
        <strong>Server Address</strong> = this server's IP or domain,
        <strong>Server Port</strong> = the port this app is served on (usually <code>80</code>,
        or <code>443</code> behind a TLS proxy), <strong>Enable Domain Name</strong> = ON only when
        you enter a domain. The device pushes to
        <code><?= h($receiver_url) ?></code> automatically (the <code>/iclock/</code> path is fixed
        by the device and cannot be changed).
    </div>
</div>

<!-- ── Devices ─────────────────────────────────────────────────────────────── -->
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-fingerprint me-2 text-primary"></i>Registered Devices</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Device</th><th>Serial</th><th>Security</th>
                        <th>Last Seen</th><th>Last Push</th><th>Punches</th><th>Status</th><th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($devices)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No devices registered yet.</td></tr>
                <?php else: foreach ($devices as $d): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= h($d['name'] !== '' ? $d['name'] : '—') ?></div>
                            <?php if ($d['location'] !== ''): ?>
                                <div class="small text-muted"><?= h($d['location']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><code><?= h($d['serial_no']) ?></code></td>
                        <td class="small">
                            <span class="badge <?= !empty($d['secret_key']) ? 'bg-success' : 'bg-light text-dark border' ?>">
                                <?= !empty($d['secret_key']) ? 'Secret set' : 'No secret' ?>
                            </span>
                            <?php if (!empty($d['allowed_ips'])): ?>
                                <div class="text-muted mt-1">IP: <?= h($d['allowed_ips']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= $d['last_seen_at'] ? h(date('d M, H:i', strtotime($d['last_seen_at']))) : '<span class="text-muted">never</span>' ?></td>
                        <td class="small"><?= $d['last_push_at'] ? h(date('d M, H:i', strtotime($d['last_push_at']))) : '<span class="text-muted">never</span>' ?></td>
                        <td><?= (int)$d['punch_count'] ?></td>
                        <td>
                            <span class="badge <?= (int)$d['is_active'] === 1 ? 'bg-success' : 'bg-secondary' ?>">
                                <?= (int)$d['is_active'] === 1 ? 'Active' : 'Disabled' ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-secondary"
                                    onclick='admsEditDevice(<?= json_encode([
                                        'id' => (int)$d['id'], 'serial_no' => $d['serial_no'],
                                        'name' => $d['name'], 'location' => $d['location'],
                                        'secret_key' => $d['secret_key'], 'allowed_ips' => $d['allowed_ips'],
                                        'is_active' => (int)$d['is_active'],
                                    ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                <i class="fas fa-pen"></i>
                            </button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Remove this device and its user-id mappings?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_device">
                                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="row">
    <!-- ── Device user id → user mappings ──────────────────────────────────── -->
    <div class="col-lg-7 mb-4">
        <div class="card h-100" style="border-radius:12px;">
            <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-id-badge me-2 text-primary"></i>Device User ID → Staff Mapping</h6>
            </div>
            <div class="card-body">
                <form method="POST" class="row g-2 align-items-end mb-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_map">
                    <div class="col-sm-3">
                        <label class="form-label small fw-semibold mb-1">Device</label>
                        <select name="map_device_id" class="form-select form-select-sm">
                            <option value="0">All devices</option>
                            <?php foreach ($devices as $d): ?>
                                <option value="<?= (int)$d['id'] ?>"><?= h($d['name'] !== '' ? $d['name'] : $d['serial_no']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label small fw-semibold mb-1">Device User ID</label>
                        <input type="text" name="pin" class="form-control form-control-sm" maxlength="32" required>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small fw-semibold mb-1">Staff Member</label>
                        <select name="user_id" class="form-select form-select-sm" required>
                            <option value="">— select —</option>
                            <?php foreach ($staff as $s): ?>
                                <option value="<?= (int)$s['id'] ?>">
                                    <?= h($s['full_name']) ?><?= $s['employee_id'] ? ' (' . h($s['employee_id']) . ')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-2">
                        <button class="btn btn-primary btn-sm w-100"><i class="fas fa-plus"></i> Map</button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>Device</th><th>Device User ID</th><th>Staff</th><th></th></tr></thead>
                        <tbody>
                        <?php if (empty($mappings)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">No mappings yet.</td></tr>
                        <?php else: foreach ($mappings as $m): ?>
                            <tr>
                                <td class="small"><?= (int)$m['device_id'] === 0 ? '<em>All</em>' : h($device_names[(int)$m['device_id']] ?? ('#' . (int)$m['device_id'])) ?></td>
                                <td><code><?= h($m['pin']) ?></code></td>
                                <td class="small">
                                    <?= h($m['full_name'] ?? ('User #' . (int)$m['user_id'])) ?>
                                    <?= $m['employee_id'] ? '<span class="text-muted">(' . h($m['employee_id']) . ')</span>' : '' ?>
                                </td>
                                <td class="text-end">
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Remove this mapping?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_map">
                                        <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger"><i class="fas fa-times"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ── ADMS settings ───────────────────────────────────────────────────── -->
    <div class="col-lg-5 mb-4">
        <div class="card h-100" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-gear me-2 text-primary"></i>ADMS Settings</h6>
            </div>
            <div class="card-body">
                <form method="POST" class="row g-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_settings">
                    <div class="col-12">
                        <label class="form-label small fw-semibold mb-1">System user for device records</label>
                        <select name="adms_system_user_id" class="form-select form-select-sm">
                            <option value="0" <?= $sys_user_id === 0 ? 'selected' : '' ?>>None (0)</option>
                            <?php foreach ($staff as $s): ?>
                                <option value="<?= (int)$s['id'] ?>" <?= $sys_user_id === (int)$s['id'] ? 'selected' : '' ?>>
                                    <?= h($s['full_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Credited as <code>created_by</code> on auto-generated attendance rows.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold mb-1">Device time zone (minutes from UTC)</label>
                        <input type="number" name="adms_timezone_minutes" class="form-control form-control-sm"
                               min="-720" max="840" value="<?= $tz_minutes ?>">
                        <div class="form-text">Asia/Dhaka is <code>360</code> (+06:00). Sent to the device on handshake.</div>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i> Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ── Recent activity ─────────────────────────────────────────────────────── -->
<div class="row">
    <div class="col-lg-6 mb-4">
        <div class="card h-100" style="border-radius:12px;">
            <div class="card-header py-3 px-4"><h6 class="mb-0 fw-semibold"><i class="fas fa-clock-rotate-left me-2 text-primary"></i>Recent Punches</h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>Time</th><th>Device User ID</th><th>Staff</th></tr></thead>
                        <tbody>
                        <?php if (empty($recent_punches)): ?>
                            <tr><td colspan="3" class="text-center text-muted py-3">No punches received yet.</td></tr>
                        <?php else: foreach ($recent_punches as $p): ?>
                            <tr>
                                <td class="small"><?= h(date('d M, H:i:s', strtotime($p['punch_time']))) ?></td>
                                <td><code><?= h($p['pin']) ?></code></td>
                                <td class="small"><?= $p['user_id'] ? h($p['full_name'] ?? ('#' . (int)$p['user_id'])) : '<span class="badge bg-warning text-dark">Unmapped</span>' ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6 mb-4">
        <div class="card h-100" style="border-radius:12px;">
            <div class="card-header py-3 px-4"><h6 class="mb-0 fw-semibold"><i class="fas fa-shield-halved me-2 text-primary"></i>Recent Requests</h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>Time</th><th>Serial</th><th>IP</th><th>Endpoint</th><th>Result</th></tr></thead>
                        <tbody>
                        <?php if (empty($recent_requests)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">No requests logged yet.</td></tr>
                        <?php else: foreach ($recent_requests as $r):
                            $ok = in_array($r['result'], ['ok', 'handshake', 'ack_other'], true); ?>
                            <tr>
                                <td class="small"><?= h(date('d M, H:i:s', strtotime($r['created_at']))) ?></td>
                                <td class="small"><code><?= h($r['serial_no'] ?? '—') ?></code></td>
                                <td class="small"><?= h($r['ip'] ?? '—') ?></td>
                                <td class="small"><?= h($r['endpoint'] ?? '—') ?><?= $r['table_name'] ? ' / ' . h($r['table_name']) : '' ?></td>
                                <td><span class="badge <?= $ok ? 'bg-success' : 'bg-danger' ?>"><?= h($r['result'] ?? '—') ?></span></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Device modal ────────────────────────────────────────────────────────── -->
<div class="modal fade" id="deviceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius:12px;">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_device">
                <input type="hidden" name="id" id="dev_id" value="0">
                <div class="modal-header">
                    <h6 class="modal-title fw-semibold" id="deviceModalTitle">Register Device</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold mb-1">Serial Number (SN) <span class="text-danger">*</span></label>
                        <input type="text" name="serial_no" id="dev_serial" class="form-control" maxlength="64" required>
                        <div class="form-text">Exactly as shown on the device (Menu → System Info / About).</div>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-6 mb-3">
                            <label class="form-label small fw-semibold mb-1">Name</label>
                            <input type="text" name="name" id="dev_name" class="form-control" maxlength="120" placeholder="Main Gate">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label small fw-semibold mb-1">Location</label>
                            <input type="text" name="location" id="dev_location" class="form-control" maxlength="160">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold mb-1">Shared Secret <span class="text-muted">(optional)</span></label>
                        <input type="text" name="secret_key" id="dev_secret" class="form-control" maxlength="128" autocomplete="off">
                        <div class="form-text">When set, requests must present it as <code>?key=…</code> or an
                            <code>Authorization</code> header (usable behind a reverse proxy for defence in depth).</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold mb-1">Allowed Source IPs <span class="text-muted">(optional)</span></label>
                        <input type="text" name="allowed_ips" id="dev_ips" class="form-control" maxlength="255" placeholder="e.g. 203.0.113.10, 203.0.113.11">
                        <div class="form-text">Comma-separated. Leave empty to allow any IP. Only the real source IP (REMOTE_ADDR) is checked.</div>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" id="dev_active" value="1" checked>
                        <label class="form-check-label" for="dev_active">Active (accept punches)</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Device</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function admsResetDeviceForm() {
    document.getElementById('deviceModalTitle').textContent = 'Register Device';
    document.getElementById('dev_id').value = '0';
    document.getElementById('dev_serial').value = '';
    document.getElementById('dev_name').value = '';
    document.getElementById('dev_location').value = '';
    document.getElementById('dev_secret').value = '';
    document.getElementById('dev_ips').value = '';
    document.getElementById('dev_active').checked = true;
}
function admsEditDevice(d) {
    admsResetDeviceForm();
    document.getElementById('deviceModalTitle').textContent = 'Edit Device';
    document.getElementById('dev_id').value = d.id;
    document.getElementById('dev_serial').value = d.serial_no || '';
    document.getElementById('dev_name').value = d.name || '';
    document.getElementById('dev_location').value = d.location || '';
    document.getElementById('dev_secret').value = d.secret_key || '';
    document.getElementById('dev_ips').value = d.allowed_ips || '';
    document.getElementById('dev_active').checked = (d.is_active == 1);
    new bootstrap.Modal(document.getElementById('deviceModal')).show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
