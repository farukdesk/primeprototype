<?php
/**
 * Super Admin / module editor: global office-schedule settings.
 *   • Office start / close time
 *   • In-time and out-time grace buffers (minutes)
 *   • Weekly-off days
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('staff-attendance', 'can_edit');
require_once __DIR__ . '/helpers.php';

$page_title = 'Attendance Settings';

$weekday_names = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

// ── Save handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $start   = att_normalize_time($_POST['office_start_time'] ?? '');
    $close   = att_normalize_time($_POST['office_close_time'] ?? '');
    $in_buf  = max(0, min(600, (int)($_POST['in_buffer_minutes']  ?? 0)));
    $out_buf = max(0, min(600, (int)($_POST['out_buffer_minutes'] ?? 0)));
    $dedup   = max(0, min(240, (int)($_POST['punch_dedup_minutes'] ?? 15)));

    $off = [];
    foreach ((array)($_POST['weekly_off_days'] ?? []) as $d) {
        $d = (int)$d;
        if ($d >= 1 && $d <= 7) $off[] = $d;
    }
    $off = array_values(array_unique($off));

    if ($start === null || $close === null) {
        flash_set('error', 'Please provide valid office start and close times.');
    } elseif (att_time_to_minutes($close) <= att_time_to_minutes($start)) {
        flash_set('error', 'Office close time must be later than the start time.');
    } else {
        att_save_setting('office_start_time',  $start);
        att_save_setting('office_close_time',  $close);
        att_save_setting('in_buffer_minutes',  (string)$in_buf);
        att_save_setting('out_buffer_minutes', (string)$out_buf);
        att_save_setting('punch_dedup_minutes', (string)$dedup);
        att_save_setting('weekly_off_days',    implode(',', $off));
        log_change('staff-attendance', 'UPDATE', null, 'Global settings', null, null,
            "start=$start, close=$close, in_buf=$in_buf, out_buf=$out_buf, dedup=$dedup, off=" . implode(',', $off));
        flash_set('success', 'Attendance settings saved.');
    }
    redirect(APP_URL . '/staff-attendance/settings.php');
}

$g     = att_global_schedule();
$off   = att_weekly_off_days();
$dedup = max(0, min(240, (int)att_get_setting('punch_dedup_minutes', '15')));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/staff-attendance/index.php">Staff Attendance</a></li>
            <li class="breadcrumb-item active">Settings</li>
        </ol>
    </nav>
</div>

<?= flash_show() ?>

<div class="row">
    <div class="col-lg-8">
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-gear me-2 text-primary"></i>Global Office Schedule</h6>
            </div>
            <div class="card-body">
                <form method="POST" class="row g-3">
                    <?= csrf_field() ?>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small mb-1">Office Start Time</label>
                        <input type="time" name="office_start_time" class="form-control" value="<?= h($g['start_time']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small mb-1">Office Close Time</label>
                        <input type="time" name="office_close_time" class="form-control" value="<?= h($g['close_time']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small mb-1">In-Time Buffer (minutes)</label>
                        <input type="number" name="in_buffer_minutes" class="form-control" min="0" max="600" value="<?= (int)$g['in_buffer_minutes'] ?>">
                        <div class="form-text">e.g. office starts 09:00 with a 15-minute buffer → arriving by 09:15 is not late.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small mb-1">Out-Time Buffer (minutes)</label>
                        <input type="number" name="out_buffer_minutes" class="form-control" min="0" max="600" value="<?= (int)$g['out_buffer_minutes'] ?>">
                        <div class="form-text">Leaving within this many minutes before close is not counted as early-out.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small mb-1">Duplicate Punch Window (minutes)</label>
                        <input type="number" name="punch_dedup_minutes" class="form-control" min="0" max="240" value="<?= (int)$dedup ?>">
                        <div class="form-text">Repeated punches within this many minutes of the first punch of the day count as a single clock-in (day shows "No Out Time"). For clock-out, the last punch of the day always wins.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold small mb-1">Weekend Days (Weekly Off)</label>
                        <div class="d-flex flex-wrap gap-3">
                            <?php foreach ($weekday_names as $n => $name): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="weekly_off_days[]"
                                       id="wd<?= $n ?>" value="<?= $n ?>" <?= in_array($n, $off, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="wd<?= $n ?>"><?= h($name) ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text">Off days are excluded from absence counts.</div>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Settings</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <?= att_policy_rules_html() ?>
        <div class="card" style="border-radius:12px;">
            <div class="card-body">
                <h6 class="fw-semibold"><i class="fas fa-circle-info me-2 text-muted"></i>How this is used</h6>
                <p class="small text-muted mb-2">These global values apply to every staff member unless an individual override exists.</p>
                <p class="small text-muted mb-0">Set per-staff office hours on the
                    <a href="<?= APP_URL ?>/staff-attendance/schedules.php">Staff Schedules</a> page, and dated
                    holidays on the <a href="<?= APP_URL ?>/staff-attendance/holidays.php">Holidays</a> page.</p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
