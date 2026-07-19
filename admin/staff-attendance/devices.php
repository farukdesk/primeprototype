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

// ── POST handlers ──────────────────────────────────────────────────────────
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
            log_change('staff-attendance', 'UPDATE', $user_id, 'Device User ID map ' . $pin);
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

// ── Data for display ─────────────────────────────────────────────────────
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

// Count of distinct Device User IDs with unresolved punches, used to prompt
// the 1-click Auto-Map tool below.
$unmapped_pin_count = 0;
try {
    $unmapped_pin_count = (int)$db->query(
        'SELECT COUNT(DISTINCT pin) FROM att_punch_log WHERE user_id IS NULL'
    )->fetchColumn();
} catch (Throwable $e) {
    $unmapped_pin_count = 0;
}

// Recent punches: searchable, date-filterable, paginated.
$p_search   = trim($_GET['p_search'] ?? '');
$p_from     = trim($_GET['p_from'] ?? '');
$p_to       = trim($_GET['p_to'] ?? '');
$p_page     = max(1, (int)($_GET['p_page'] ?? 1));
$p_per_page = 30;

$p_is_date = fn($d) => (bool)preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $d);
if ($p_from !== '' && !$p_is_date($p_from)) $p_from = '';
if ($p_to   !== '' && !$p_is_date($p_to))   $p_to   = '';

$p_where  = [];
$p_params = [];
if ($p_search !== '') {
    $p_where[]  = '(p.pin LIKE ? OR u.full_name LIKE ? OR u.username LIKE ?)';
    $p_like     = '%' . $p_search . '%';
    $p_params   = array_merge($p_params, [$p_like, $p_like, $p_like]);
}
if ($p_from !== '') { $p_where[] = 'p.punch_time >= ?'; $p_params[] = $p_from . ' 00:00:00'; }
if ($p_to   !== '') { $p_where[] = 'p.punch_time <= ?'; $p_params[] = $p_to . ' 23:59:59'; }
$p_where_sql = $p_where ? ' WHERE ' . implode(' AND ', $p_where) : '';

$recent_punches = [];
$p_total = 0;
$p_pages = 1;
try {
    $cnt = $db->prepare(
        'SELECT COUNT(*) FROM att_punch_log p LEFT JOIN users u ON u.id = p.user_id' . $p_where_sql
    );
    $cnt->execute($p_params);
    $p_total = (int)$cnt->fetchColumn();

    $p_pages  = max(1, (int)ceil($p_total / $p_per_page));
    if ($p_page > $p_pages) $p_page = $p_pages;
    $p_offset = ($p_page - 1) * $p_per_page;

    $stmt = $db->prepare(
        'SELECT p.*, u.full_name
           FROM att_punch_log p
      LEFT JOIN users u ON u.id = p.user_id'
        . $p_where_sql .
        ' ORDER BY p.punch_time DESC, p.id DESC
          LIMIT ' . (int)$p_per_page . ' OFFSET ' . (int)$p_offset
    );
    $stmt->execute($p_params);
    $recent_punches = $stmt->fetchAll();
} catch (Throwable $e) { /* table missing */ }

// Builds pagination / clear links that preserve the current punch filters.
$p_qs = function ($page) use ($p_search, $p_from, $p_to) {
    $q = array_filter(
        ['p_search' => $p_search, 'p_from' => $p_from, 'p_to' => $p_to],
        fn($v) => $v !== ''
    );
    $q['p_page'] = max(1, (int)$page);
    return APP_URL . '/staff-attendance/devices.php?' . http_build_query($q) . '#tab-activity';
};

$recent_requests = [];
try {
    $recent_requests = $db->query(
        'SELECT * FROM att_device_log ORDER BY id DESC LIMIT 30'
    )->fetchAll();
} catch (Throwable $e) { /* table missing */ }

$staff        = att_mappable_users();
$sys_user_id  = (int)att_get_setting('adms_system_user_id', '0');
$tz_minutes   = (int)att_get_setting('adms_timezone_minutes', '360');

// ── Presentation helpers ─────────────────────────────────────────────────
$now_ts = time();

// A device is considered online if it contacted the server in the last 15 min.
$is_online = function (array $d) use ($now_ts): bool {
    return !empty($d['last_seen_at']) && ($now_ts - strtotime($d['last_seen_at'])) < 900;
};

// Human friendly relative time ("5 min ago") with exact time available on hover.
$rel_time = function (?string $ts) use ($now_ts): string {
    if (!$ts) return '<span class="text-muted">never</span>';
    $t    = strtotime($ts);
    $diff = $now_ts - $t;
    if ($diff < 60)        $label = 'just now';
    elseif ($diff < 3600)  $label = floor($diff / 60) . ' min ago';
    elseif ($diff < 86400) $label = floor($diff / 3600) . ' hr ago';
    elseif ($diff < 604800) { $dd = floor($diff / 86400); $label = $dd . ' day' . ($dd == 1 ? '' : 's') . ' ago'; }
    else                   $label = date('d M Y', $t);
    return '<span title="' . h(date('d M Y, H:i:s', $t)) . '">' . h($label) . '</span>';
};

$online_count  = 0;
$total_punches = 0;
foreach ($devices as $d) {
    if ($is_online($d)) $online_count++;
    $total_punches += (int)$d['punch_count'];
}
$mapping_count = count($mappings);

// Open the Activity tab automatically when punch filters / paging are in play.
$has_punch_filter = ($p_search !== '' || $p_from !== '' || $p_to !== '' || $p_page > 1);
$active_tab       = $has_punch_filter ? 'activity' : 'devices';

require_once __DIR__ . '/../includes/header.php';
?>

<style>
    .adms-stat {
        border: 1px solid #e9ecf3; border-radius: 14px; background: #fff;
        padding: 18px 20px; height: 100%; display: flex; align-items: center; gap: 14px;
        transition: box-shadow .15s ease, transform .15s ease;
    }
    .adms-stat:hover { box-shadow: 0 6px 18px rgba(26,31,54,.08); transform: translateY(-1px); }
    .adms-stat .icon {
        width: 44px; height: 44px; border-radius: 12px; flex: 0 0 44px;
        display: flex; align-items: center; justify-content: center; font-size: 1.1rem;
    }
    .adms-stat .num  { font-size: 1.45rem; font-weight: 700; line-height: 1.1; color: #1a1f36; }
    .adms-stat .lbl  { font-size: .78rem; color: #8a92a9; font-weight: 500; }
    a.adms-stat { text-decoration: none; }

    .adms-tabs .nav-link {
        color: #6b7280; font-weight: 600; font-size: .875rem; border: none;
        border-bottom: 2px solid transparent; border-radius: 0; padding: .7rem 1rem;
        background: transparent;
    }
    .adms-tabs .nav-link:hover  { color: #1a1f36; }
    .adms-tabs .nav-link.active { color: var(--accent, #4f8ef7); border-bottom-color: var(--accent, #4f8ef7); background: transparent; }

    .status-dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-right: 6px; vertical-align: 1px; }
    .status-dot.online  { background: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,.18); }
    .status-dot.offline { background: #cbd5e1; }

    .adms-empty { padding: 48px 20px; text-align: center; color: #9aa1b5; }
    .adms-empty i { font-size: 2.2rem; margin-bottom: 12px; display: block; opacity: .5; }

    .adms-step { display: flex; gap: 12px; margin-bottom: 14px; }
    .adms-step .n {
        flex: 0 0 26px; width: 26px; height: 26px; border-radius: 50%;
        background: #eef4fe; color: #4f8ef7; font-weight: 700; font-size: .8rem;
        display: flex; align-items: center; justify-content: center;
    }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>/staff-attendance/index.php">Staff Attendance</a></li>
                <li class="breadcrumb-item active">Devices</li>
            </ol>
        </nav>
        <h5 class="fw-bold mb-0" style="color:#1a1f36;"><i class="fas fa-fingerprint me-2 text-primary"></i>Attendance Devices</h5>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#deviceModal" onclick="admsResetDeviceForm()">
        <i class="fas fa-plus me-1"></i> Register Device
    </button>
</div>

<?= flash_show() ?>

<!-- ── At-a-glance stats ─────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="adms-stat">
            <div class="icon" style="background:#eef4fe;color:#4f8ef7;"><i class="fas fa-fingerprint"></i></div>
            <div>
                <div class="num"><?= count($devices) ?></div>
                <div class="lbl">
                    Devices &middot;
                    <span class="<?= $online_count > 0 ? 'text-success' : 'text-muted' ?> fw-semibold"><?= (int)$online_count ?> online</span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <?php if ($unmapped_pin_count > 0): ?>
        <a class="adms-stat" href="<?= APP_URL ?>/staff-attendance/devices-auto-map.php"
           title="Click to run 1-click Auto-Map">
            <div class="icon" style="background:#fff4e0;color:#e6a817;"><i class="fas fa-triangle-exclamation"></i></div>
            <div>
                <div class="num text-warning"><?= (int)$unmapped_pin_count ?></div>
                <div class="lbl">Unmapped IDs &middot; <span class="fw-semibold" style="color:#e6a817;">Auto-Map <i class="fas fa-arrow-right fa-xs"></i></span></div>
            </div>
        </a>
        <?php else: ?>
        <div class="adms-stat">
            <div class="icon" style="background:#e8f8ee;color:#22c55e;"><i class="fas fa-circle-check"></i></div>
            <div>
                <div class="num">0</div>
                <div class="lbl">Unmapped IDs &middot; all good</div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <div class="col-6 col-lg-3">
        <div class="adms-stat">
            <div class="icon" style="background:#f0edfe;color:#7c6cf5;"><i class="fas fa-id-badge"></i></div>
            <div>
                <div class="num"><?= (int)$mapping_count ?></div>
                <div class="lbl">Staff Mappings</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="adms-stat">
            <div class="icon" style="background:#e7f6fb;color:#0ea5c9;"><i class="fas fa-clock-rotate-left"></i></div>
            <div>
                <div class="num"><?= number_format($total_punches) ?></div>
                <div class="lbl">Total Punches</div>
            </div>
        </div>
    </div>
</div>

<!-- ── Tabs ───────────────────────────────────────────────────────────────── -->
<ul class="nav nav-tabs adms-tabs mb-0" id="admsTabs" role="tablist" style="border-bottom:1px solid #e9ecf3;">
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $active_tab === 'devices' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-devices" type="button" role="tab">
            <i class="fas fa-fingerprint me-1"></i> Devices
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-mapping" type="button" role="tab">
            <i class="fas fa-id-badge me-1"></i> Staff Mapping
            <?php if ($unmapped_pin_count > 0): ?>
                <span class="badge bg-warning text-dark ms-1"><?= (int)$unmapped_pin_count ?></span>
            <?php endif; ?>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $active_tab === 'activity' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-activity" type="button" role="tab">
            <i class="fas fa-clock-rotate-left me-1"></i> Activity Log
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-settings" type="button" role="tab">
            <i class="fas fa-gear me-1"></i> Settings &amp; Setup
        </button>
    </li>
</ul>

<div class="tab-content pt-4">

    <!-- ── TAB: Devices ─────────────────────────────────────────────────────── -->
    <div class="tab-pane fade <?= $active_tab === 'devices' ? 'show active' : '' ?>" id="tab-devices" role="tabpanel">
        <div class="card" style="border-radius:12px;">
            <div class="card-body p-0">
            <?php if (empty($devices)): ?>
                <div class="adms-empty">
                    <i class="fas fa-fingerprint"></i>
                    <div class="fw-semibold mb-1" style="color:#6b7280;">No devices registered yet</div>
                    <div class="small mb-3">Register your ZKTeco (ADMS) device by its serial number to start receiving punches.</div>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#deviceModal" onclick="admsResetDeviceForm()">
                        <i class="fas fa-plus me-1"></i> Register your first device
                    </button>
                    <div class="small mt-3">
                        Not sure how to configure the device itself?
                        <a href="#tab-settings" onclick="admsGoTab('#tab-settings');return false;">See the setup guide</a>.
                    </div>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="min-width:220px;">Device</th>
                                <th>Connection</th>
                                <th>Security</th>
                                <th class="text-center">Punches</th>
                                <th>Status</th>
                                <th class="text-end" style="width:110px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($devices as $d): $online = $is_online($d); ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold">
                                        <span class="status-dot <?= $online ? 'online' : 'offline' ?>"></span>
                                        <?= h($d['name'] !== '' ? $d['name'] : $d['serial_no']) ?>
                                    </div>
                                    <div class="small text-muted mt-1">
                                        <code><?= h($d['serial_no']) ?></code>
                                        <?php if ($d['location'] !== ''): ?>
                                            &middot; <i class="fas fa-location-dot fa-xs"></i> <?= h($d['location']) ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="small">
                                    <div><span class="text-muted">Seen:</span> <?= $rel_time($d['last_seen_at']) ?></div>
                                    <div><span class="text-muted">Push:</span> <?= $rel_time($d['last_push_at']) ?></div>
                                </td>
                                <td class="small">
                                    <span class="badge <?= !empty($d['secret_key']) ? 'bg-success' : 'bg-light text-dark border' ?>">
                                        <i class="fas fa-key fa-xs me-1"></i><?= !empty($d['secret_key']) ? 'Secret set' : 'No secret' ?>
                                    </span>
                                    <?php if (!empty($d['allowed_ips'])): ?>
                                        <div class="text-muted mt-1" title="Allowed source IPs"><i class="fas fa-lock fa-xs me-1"></i><?= h($d['allowed_ips']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?= number_format((int)$d['punch_count']) ?></td>
                                <td>
                                    <span class="badge <?= (int)$d['is_active'] === 1 ? 'bg-success' : 'bg-secondary' ?>">
                                        <?= (int)$d['is_active'] === 1 ? 'Active' : 'Disabled' ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-secondary" title="Edit device"
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
                                        <button class="btn btn-sm btn-outline-danger" title="Remove device"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            </div>
        </div>
        <div class="small text-muted mt-2"><span class="status-dot online"></span>Online = contacted the server within the last 15 minutes. Hover a time for the exact timestamp.</div>
    </div>

    <!-- ── TAB: Staff Mapping ───────────────────────────────────────────────── -->
    <div class="tab-pane fade" id="tab-mapping" role="tabpanel">
        <?php if ($unmapped_pin_count > 0): ?>
        <div class="alert alert-warning d-flex align-items-center justify-content-between flex-wrap gap-2" role="alert">
            <div class="d-flex align-items-start gap-2">
                <i class="fas fa-triangle-exclamation mt-1"></i>
                <div class="small">
                    <strong><?= (int)$unmapped_pin_count ?> Device User ID<?= $unmapped_pin_count === 1 ? '' : 's' ?></strong>
                    <?= $unmapped_pin_count === 1 ? 'has' : 'have' ?> punches but no staff mapping yet
                    (common right after updating/re-importing staff).
                </div>
            </div>
            <a href="<?= APP_URL ?>/staff-attendance/devices-auto-map.php" class="btn btn-warning btn-sm text-dark fw-semibold" style="border-radius:10px;">
                <i class="fas fa-bolt me-1"></i> Auto-Map Now
            </a>
        </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card h-100" style="border-radius:12px;">
                    <div class="card-header py-3 px-4">
                        <h6 class="mb-0 fw-semibold"><i class="fas fa-plus me-2 text-primary"></i>Add a Mapping</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save_map">
                            <div class="mb-3">
                                <label class="form-label small fw-semibold mb-1">Device</label>
                                <select name="map_device_id" class="form-select form-select-sm">
                                    <option value="0">All devices</option>
                                    <?php foreach ($devices as $d): ?>
                                        <option value="<?= (int)$d['id'] ?>"><?= h($d['name'] !== '' ? $d['name'] : $d['serial_no']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">“All devices” works for staff who punch on any device.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-semibold mb-1">Device User ID</label>
                                <input type="text" name="pin" class="form-control form-control-sm" maxlength="32" required
                                       placeholder="The ID enrolled on the device">
                            </div>
                            <div class="mb-3">
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
                            <button class="btn btn-primary btn-sm w-100"><i class="fas fa-plus me-1"></i> Save Mapping</button>
                        </form>
                        <hr>
                        <div class="d-grid gap-2">
                            <a href="<?= APP_URL ?>/staff-attendance/devices-auto-map.php" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-bolt me-1"></i> Auto-Map (1-Click)
                                <?php if ($unmapped_pin_count > 0): ?>
                                    <span class="badge bg-warning text-dark ms-1"><?= (int)$unmapped_pin_count ?></span>
                                <?php endif; ?>
                            </a>
                            <a href="<?= APP_URL ?>/staff-attendance/devices-bulk-map.php" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-file-csv me-1"></i> Bulk Map (CSV)
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="card h-100" style="border-radius:12px;">
                    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h6 class="mb-0 fw-semibold"><i class="fas fa-id-badge me-2 text-primary"></i>Current Mappings
                            <span class="badge bg-light text-dark border ms-1"><?= (int)$mapping_count ?></span>
                        </h6>
                        <div class="input-group input-group-sm" style="max-width:240px;">
                            <span class="input-group-text"><i class="fas fa-search fa-xs"></i></span>
                            <input type="text" id="mapSearch" class="form-control" placeholder="Filter by ID, staff, device…">
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive" style="max-height:520px;overflow-y:auto;">
                            <table class="table table-sm table-hover align-middle mb-0" id="mapTable">
                                <thead class="table-light" style="position:sticky;top:0;z-index:1;">
                                    <tr><th>Device</th><th>Device User ID</th><th>Staff</th><th class="text-end" style="width:60px;"></th></tr>
                                </thead>
                                <tbody>
                                <?php if (empty($mappings)): ?>
                                    <tr class="adms-no-filter"><td colspan="4">
                                        <div class="adms-empty py-4">
                                            <i class="fas fa-id-badge"></i>
                                            <div class="small">No mappings yet. Add one on the left, or use <strong>Auto-Map</strong> to match device IDs to staff automatically.</div>
                                        </div>
                                    </td></tr>
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
                                                <button class="btn btn-sm btn-outline-danger" title="Remove mapping"><i class="fas fa-times"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                <tr id="mapNoResults" style="display:none;"><td colspan="4" class="text-center text-muted py-3 small">No mappings match your filter.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── TAB: Activity Log ────────────────────────────────────────────────── -->
    <div class="tab-pane fade <?= $active_tab === 'activity' ? 'show active' : '' ?>" id="tab-activity" role="tabpanel">
        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card h-100" style="border-radius:12px;">
                    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h6 class="mb-0 fw-semibold"><i class="fas fa-clock-rotate-left me-2 text-primary"></i>Recent Punches</h6>
                        <span class="badge bg-light text-dark border"><?= (int)$p_total ?> total</span>
                    </div>
                    <div class="card-body p-0">
                        <form method="GET" class="d-flex gap-2 flex-wrap align-items-center px-3 py-2 border-bottom">
                            <input type="text" name="p_search" class="form-control form-control-sm" style="max-width:170px;"
                                   placeholder="Search ID or staff…" value="<?= h($p_search) ?>">
                            <input type="date" name="p_from" class="form-control form-control-sm" style="max-width:150px;"
                                   value="<?= h($p_from) ?>" title="From date">
                            <input type="date" name="p_to" class="form-control form-control-sm" style="max-width:150px;"
                                   value="<?= h($p_to) ?>" title="To date">
                            <button class="btn btn-outline-primary btn-sm"><i class="fas fa-search me-1"></i>Filter</button>
                            <?php if ($p_search !== '' || $p_from !== '' || $p_to !== ''): ?>
                                <a href="<?= APP_URL ?>/staff-attendance/devices.php#tab-activity" class="btn btn-light btn-sm">Clear</a>
                            <?php endif; ?>
                        </form>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="table-light"><tr><th>Time</th><th>Device User ID</th><th>Staff</th></tr></thead>
                                <tbody>
                                <?php if (empty($recent_punches)): ?>
                                    <tr><td colspan="3" class="text-center text-muted py-4">No punches found<?= $has_punch_filter ? ' for this filter' : ' yet' ?>.</td></tr>
                                <?php else: foreach ($recent_punches as $p): ?>
                                    <tr>
                                        <td class="small"><?= h(date('d M Y, H:i:s', strtotime($p['punch_time']))) ?></td>
                                        <td><code><?= h($p['pin']) ?></code></td>
                                        <td class="small"><?= $p['user_id'] ? h($p['full_name'] ?? ('#' . (int)$p['user_id'])) : '<span class="badge bg-warning text-dark">Unmapped</span>' ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($p_pages > 1): ?>
                        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top small">
                            <span class="text-muted">Page <?= (int)$p_page ?> of <?= (int)$p_pages ?></span>
                            <div class="btn-group">
                                <a class="btn btn-sm btn-outline-secondary <?= $p_page <= 1 ? 'disabled' : '' ?>"
                                   href="<?= h($p_qs($p_page - 1)) ?>">&laquo; Prev</a>
                                <a class="btn btn-sm btn-outline-secondary <?= $p_page >= $p_pages ? 'disabled' : '' ?>"
                                   href="<?= h($p_qs($p_page + 1)) ?>">Next &raquo;</a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100" style="border-radius:12px;">
                    <div class="card-header py-3 px-4">
                        <h6 class="mb-0 fw-semibold"><i class="fas fa-shield-halved me-2 text-primary"></i>Recent Device Requests</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="table-light"><tr><th>Time</th><th>Serial</th><th>IP</th><th>Endpoint</th><th>Result</th></tr></thead>
                                <tbody>
                                <?php if (empty($recent_requests)): ?>
                                    <tr><td colspan="5" class="text-center text-muted py-4">No requests logged yet. Once a device connects, its handshakes and pushes appear here.</td></tr>
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
    </div>

    <!-- ── TAB: Settings & Setup ────────────────────────────────────────────── -->
    <div class="tab-pane fade" id="tab-settings" role="tabpanel">
        <div class="row g-4">
            <div class="col-lg-5">
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
                                <button class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i> Save Settings</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="card h-100" style="border-radius:12px;">
                    <div class="card-header py-3 px-4">
                        <h6 class="mb-0 fw-semibold"><i class="fas fa-circle-info me-2 text-primary"></i>Device Setup Guide (ZKTeco ADMS)</h6>
                    </div>
                    <div class="card-body">
                        <div class="adms-step">
                            <div class="n">1</div>
                            <div class="small">
                                On the device open <strong>Comm. → Cloud Server (ADMS)</strong> and set
                                <strong>Server Address</strong> to this server's IP or domain, and
                                <strong>Server Port</strong> to the port this app is served on
                                (usually <code>80</code>, or <code>443</code> behind a TLS proxy).
                                Turn <strong>Enable Domain Name</strong> ON only when you enter a domain.
                            </div>
                        </div>
                        <div class="adms-step">
                            <div class="n">2</div>
                            <div class="small">
                                The device pushes to the URL below automatically – the <code>/iclock/</code>
                                path is fixed by the firmware and cannot be changed:
                                <div class="input-group input-group-sm mt-2" style="max-width:420px;">
                                    <input type="text" class="form-control" id="receiverUrl" value="<?= h($receiver_url) ?>" readonly>
                                    <button class="btn btn-outline-secondary" type="button" id="copyReceiverBtn" onclick="admsCopyReceiver()" title="Copy URL">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="adms-step">
                            <div class="n">3</div>
                            <div class="small">
                                Click <strong>Register Device</strong> above and enter the device's
                                <strong>Serial Number</strong> exactly as shown on the device
                                (Menu → System Info / About). Once the device connects you'll see it
                                turn <span class="text-success fw-semibold">online</span> in the Devices tab.
                            </div>
                        </div>
                        <div class="adms-step mb-0">
                            <div class="n">4</div>
                            <div class="small">
                                Map each <strong>Device User ID</strong> to a staff member in the
                                <a href="#tab-mapping" onclick="admsGoTab('#tab-mapping');return false;">Staff Mapping</a> tab
                                – or just run <strong>Auto-Map (1-Click)</strong> after the first punches arrive.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div><!-- /tab-content -->

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

// ── Tab deep-linking: keep the active tab in the URL hash ───────────────────
function admsGoTab(target) {
    var trigger = document.querySelector('#admsTabs button[data-bs-target="' + target + '"]');
    if (trigger) bootstrap.Tab.getOrCreateInstance(trigger).show();
}
(function () {
    document.querySelectorAll('#admsTabs button[data-bs-toggle="tab"]').forEach(function (btn) {
        btn.addEventListener('shown.bs.tab', function (e) {
            history.replaceState(null, '', e.target.dataset.bsTarget);
        });
    });
    if (window.location.hash) admsGoTab(window.location.hash);
})();

// ── Live filter for the mapping table ───────────────────────────────────────────────
(function () {
    var input = document.getElementById('mapSearch');
    if (!input) return;
    input.addEventListener('input', function () {
        var q = this.value.trim().toLowerCase();
        var rows = document.querySelectorAll('#mapTable tbody tr:not(#mapNoResults):not(.adms-no-filter)');
        var visible = 0;
        rows.forEach(function (row) {
            var match = q === '' || row.textContent.toLowerCase().indexOf(q) !== -1;
            row.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        var none = document.getElementById('mapNoResults');
        if (none) none.style.display = (visible === 0 && rows.length > 0) ? '' : 'none';
    });
})();

// ── Copy the receiver URL in the setup guide ──────────────────────────────────────
function admsCopyReceiver() {
    var el  = document.getElementById('receiverUrl');
    var btn = document.getElementById('copyReceiverBtn');
    var done = function () {
        btn.innerHTML = '<i class="fas fa-check text-success"></i>';
        setTimeout(function () { btn.innerHTML = '<i class="fas fa-copy"></i>'; }, 1500);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(el.value).then(done).catch(function () { el.select(); document.execCommand('copy'); done(); });
    } else {
        el.select(); document.execCommand('copy'); done();
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
