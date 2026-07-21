<?php
/**
 * Super Admin / module editor: customised (individual) staff in/out times.
 * Each row overrides the global office schedule for one staff member; empty
 * fields fall back to the global value.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('staff-attendance', 'can_edit');
require_once __DIR__ . '/helpers.php';

$page_title = 'Staff Schedules';
$db         = db();
$search     = trim($_GET['q'] ?? '');

// Self-applying migration: per-staff weekly-off days (comma-separated ISO day
// numbers 1=Mon … 7=Sun; NULL/empty = inherit the global Weekly Off setting).
try {
    $db->query('SELECT weekly_off_days FROM att_staff_schedule LIMIT 1');
} catch (Throwable $e) {
    try {
        $db->exec('ALTER TABLE att_staff_schedule ADD COLUMN weekly_off_days VARCHAR(20) NULL DEFAULT NULL');
    } catch (Throwable $e2) {
        // Table missing or no ALTER permission – the module keeps working with
        // global weekly-off days only.
    }
}

// ── Save handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $uid     = (int)($_POST['user_id'] ?? 0);
    $start   = att_normalize_time($_POST['start_time'] ?? '');
    $close   = att_normalize_time($_POST['close_time'] ?? '');
    $in_buf  = ($_POST['in_buffer_minutes']  ?? '') === '' ? null : max(0, min(600, (int)$_POST['in_buffer_minutes']));
    $out_buf = ($_POST['out_buffer_minutes'] ?? '') === '' ? null : max(0, min(600, (int)$_POST['out_buffer_minutes']));

    // Per-staff weekly-off days: none checked → NULL (inherit global setting).
    $off_sel = [];
    foreach ((array)($_POST['weekly_off_days'] ?? []) as $d) {
        $d = (int)$d;
        if ($d >= 1 && $d <= 7) $off_sel[] = $d;
    }
    $off_sel = array_values(array_unique($off_sel));
    sort($off_sel);
    $off_val = $off_sel ? implode(',', $off_sel) : null;

    $valid_ids = array_map(fn($s) => (int)$s['id'], att_staff_list());

    if ($uid < 1 || !in_array($uid, $valid_ids, true)) {
        flash_set('error', 'Invalid staff member.');
    } elseif ($start !== null && $close !== null && att_time_to_minutes($close) <= att_time_to_minutes($start)) {
        flash_set('error', 'Close time must be later than the start time.');
    } elseif ($start === null && $close === null && $in_buf === null && $out_buf === null && $off_val === null) {
        // All blank → remove any existing override so the staff uses global hours.
        $db->prepare('DELETE FROM att_staff_schedule WHERE user_id = ?')->execute([$uid]);
        log_change('staff-attendance', 'UPDATE', $uid, 'Schedule override', null, null, 'cleared (uses global)');
        flash_set('success', 'Override cleared; staff now uses the global schedule.');
    } else {
        try {
            $stmt = $db->prepare(
                'INSERT INTO att_staff_schedule (user_id, start_time, close_time, in_buffer_minutes, out_buffer_minutes, weekly_off_days, is_active)
                 VALUES (?,?,?,?,?,?,1)
                 ON DUPLICATE KEY UPDATE start_time = VALUES(start_time), close_time = VALUES(close_time),
                                         in_buffer_minutes = VALUES(in_buffer_minutes),
                                         out_buffer_minutes = VALUES(out_buffer_minutes),
                                         weekly_off_days = VALUES(weekly_off_days), is_active = 1'
            );
            $stmt->execute([$uid, $start, $close, $in_buf, $out_buf, $off_val]);
        } catch (Throwable $e) {
            // weekly_off_days column unavailable – save the rest of the override.
            $stmt = $db->prepare(
                'INSERT INTO att_staff_schedule (user_id, start_time, close_time, in_buffer_minutes, out_buffer_minutes, is_active)
                 VALUES (?,?,?,?,?,1)
                 ON DUPLICATE KEY UPDATE start_time = VALUES(start_time), close_time = VALUES(close_time),
                                         in_buffer_minutes = VALUES(in_buffer_minutes),
                                         out_buffer_minutes = VALUES(out_buffer_minutes), is_active = 1'
            );
            $stmt->execute([$uid, $start, $close, $in_buf, $out_buf]);
        }
        log_change('staff-attendance', 'UPDATE', $uid, 'Schedule override', null, null,
            "start=" . ($start ?? '—') . ", close=" . ($close ?? '—') . ", off=" . ($off_val ?? 'global'));
        flash_set('success', 'Individual schedule saved.');
    }
    redirect(APP_URL . '/staff-attendance/schedules.php' . ($search !== '' ? '?q=' . urlencode($search) : ''));
}

$staff     = att_staff_list(0, $search);
$overrides = att_all_overrides();
$g         = att_global_schedule();
$g_off     = att_weekly_off_days();
$day_abbr  = [1 => 'Mo', 2 => 'Tu', 3 => 'We', 4 => 'Th', 5 => 'Fr', 6 => 'Sa', 7 => 'Su'];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/staff-attendance/index.php">Staff Attendance</a></li>
            <li class="breadcrumb-item active">Staff Schedules</li>
        </ol>
    </nav>
</div>

<?= flash_show() ?>

<div class="card mb-3" style="border-radius:12px;">
    <div class="card-body py-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label fw-semibold small mb-1">Search staff</label>
                <input type="text" name="q" class="form-control" value="<?= h($search) ?>" placeholder="Name / username / employee ID">
            </div>
            <div class="col-md-3">
                <button class="btn btn-primary"><i class="fas fa-search me-1"></i> Filter</button>
                <a href="<?= APP_URL ?>/staff-attendance/schedules.php" class="btn btn-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-user-gear me-2 text-primary"></i>Individual In / Out Times</h6>
    </div>
    <div class="card-body p-0">
        <?php foreach ($staff as $s): ?>
            <form id="sf<?= (int)$s['id'] ?>" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="user_id" value="<?= (int)$s['id'] ?>">
                <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?= h($search) ?>"><?php endif; ?>
            </form>
        <?php endforeach; ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-3">Staff</th>
                        <th style="width:130px;">Start</th>
                        <th style="width:130px;">Close</th>
                        <th style="width:120px;">In Buffer</th>
                        <th style="width:120px;">Out Buffer</th>
                        <th style="width:230px;">Weekend (Weekly Off) <span class="fw-normal text-muted">(blank = global)</span></th>
                        <th style="width:120px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($staff)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No staff found.</td></tr>
                <?php else: foreach ($staff as $s):
                    $uid = (int)$s['id'];
                    $o   = $overrides[$uid] ?? null;
                    $ff  = 'sf' . $uid;
                ?>
                    <tr>
                        <td class="px-3">
                            <strong><?= h($s['full_name']) ?></strong>
                            <div class="text-muted small">
                                <?= h($s['employee_id'] ?? $s['username']) ?>
                                <?php if ($o): ?><span class="badge bg-info text-dark ms-1">Custom</span><?php endif; ?>
                            </div>
                        </td>
                        <td><input type="time" form="<?= $ff ?>" name="start_time" class="form-control form-control-sm"
                                   value="<?= h(att_normalize_time($o['start_time'] ?? '') ?? '') ?>" placeholder="<?= h($g['start_time']) ?>"></td>
                        <td><input type="time" form="<?= $ff ?>" name="close_time" class="form-control form-control-sm"
                                   value="<?= h(att_normalize_time($o['close_time'] ?? '') ?? '') ?>" placeholder="<?= h($g['close_time']) ?>"></td>
                        <td><input type="number" form="<?= $ff ?>" name="in_buffer_minutes" min="0" max="600" class="form-control form-control-sm"
                                   value="<?= $o && $o['in_buffer_minutes'] !== null ? (int)$o['in_buffer_minutes'] : '' ?>" placeholder="<?= (int)$g['in_buffer_minutes'] ?>"></td>
                        <td><input type="number" form="<?= $ff ?>" name="out_buffer_minutes" min="0" max="600" class="form-control form-control-sm"
                                   value="<?= $o && $o['out_buffer_minutes'] !== null ? (int)$o['out_buffer_minutes'] : '' ?>" placeholder="<?= (int)$g['out_buffer_minutes'] ?>"></td>
                        <td>
                            <?php $own_off = att_parse_off_days((string)($o['weekly_off_days'] ?? '')); ?>
                            <div class="d-flex flex-wrap gap-1">
                                <?php foreach ($day_abbr as $n => $ab): ?>
                                <label class="form-check form-check-inline m-0 me-1 small" title="<?= $ab ?>">
                                    <input class="form-check-input" type="checkbox" form="<?= $ff ?>"
                                           name="weekly_off_days[]" value="<?= $n ?>"
                                           <?= in_array($n, $own_off, true) ? 'checked' : '' ?>>
                                    <span class="form-check-label"><?= $ab ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <?php if (empty($own_off)): ?>
                                <div class="text-muted" style="font-size:.7rem;">Global: <?= h(implode(', ', array_map(fn($n) => $day_abbr[$n], $g_off)) ?: 'none') ?></div>
                            <?php else: ?>
                                <div style="font-size:.7rem;"><span class="badge bg-info text-dark">Own off days</span></div>
                            <?php endif; ?>
                        </td>
                        <td><button type="submit" form="<?= $ff ?>" class="btn btn-sm btn-success" style="border-radius:8px;"><i class="fas fa-save"></i></button></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<p class="text-muted small mt-2">
    Blank fields inherit the global schedule (shown as placeholders: <?= h($g['start_time']) ?>–<?= h($g['close_time']) ?>,
    buffers <?= (int)$g['in_buffer_minutes'] ?>/<?= (int)$g['out_buffer_minutes'] ?> min).
    Weekend (weekly off) with no day ticked inherits the global off days
    (<?= h(implode(', ', array_map(fn($n) => $day_abbr[$n], $g_off)) ?: 'none') ?>); ticked days replace them for that staff member
    and are used for their absence counts and working-day totals.
    Clearing every field (and every off day) for a staff member removes their override.
</p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
