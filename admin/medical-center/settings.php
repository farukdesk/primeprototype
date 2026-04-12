<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';
require_access('medical-center');

if (!mc_can_edit()) {
    flash_set('error', 'Permission denied.');
    redirect(APP_URL . '/medical-center/index.php');
}

$page_title = 'Medical Center Settings';
$db     = db();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $tab = $_POST['active_tab'] ?? 'general';

    if ($tab === 'general') {
        $keys = ['clinic_name','doctor_name','doctor_qualification','doctor_designation',
                 'clinic_location','clinic_hours_weekday','clinic_hours_weekend',
                 'contact_phone','contact_email','emergency_note'];
        foreach ($keys as $k) {
            $val = trim($_POST[$k] ?? '');
            $stmt = $db->prepare('INSERT INTO mc_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
            $stmt->execute([$k, $val]);
        }
        log_change('medical-center', 'UPDATE', null, 'Settings', 'general', null, null, 'General settings updated');
        flash_set('success', 'General settings saved.');
        redirect(APP_URL . '/medical-center/settings.php?tab=general');

    } elseif ($tab === 'schedule') {
        $days       = $_POST['day_of_week']  ?? [];
        $starts     = $_POST['start_time']   ?? [];
        $ends       = $_POST['end_time']     ?? [];
        $slots      = $_POST['max_slots']    ?? [];
        $available  = $_POST['is_available'] ?? [];

        foreach ($days as $idx => $dow) {
            $dow  = (int)$dow;
            $start= $starts[$idx]    ?? '09:00';
            $end  = $ends[$idx]      ?? '17:00';
            $slot = (int)($slots[$idx] ?? 10);
            $avail= isset($available[$idx]) ? 1 : 0;

            $db->prepare(
                'UPDATE mc_schedules SET start_time=?, end_time=?, max_slots=?, is_available=? WHERE day_of_week=?'
            )->execute([$start, $end, $slot, $avail, $dow]);
        }
        log_change('medical-center', 'UPDATE', null, 'Schedule', null, null, null, 'Schedule updated');
        flash_set('success', 'Schedule saved.');
        redirect(APP_URL . '/medical-center/settings.php?tab=schedule');

    } elseif ($tab === 'appointment') {
        $enabled = isset($_POST['appointment_enabled']) ? '1' : '0';
        $db->prepare('INSERT INTO mc_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
           ->execute(['appointment_enabled', $enabled]);
        log_change('medical-center', 'UPDATE', null, 'Appointment Settings', null, null, null, 'Appointment settings updated');
        flash_set('success', 'Appointment settings saved.');
        redirect(APP_URL . '/medical-center/settings.php?tab=appointment');
    }
}

// Load settings & schedules
$setting_rows = $db->query('SELECT `key`, `value` FROM mc_settings')->fetchAll();
$settings     = array_column($setting_rows, 'value', 'key');

$schedules    = $db->query('SELECT * FROM mc_schedules ORDER BY day_of_week')->fetchAll();
$sched_by_dow = array_column($schedules, null, 'day_of_week');

$active_tab   = $_GET['tab'] ?? 'general';

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-cog me-2 text-secondary"></i>Medical Center Settings</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/medical-center/index.php">Medical Center</a></li>
            <li class="breadcrumb-item active">Settings</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/medical-center/index.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i> Back
    </a>
</div>

<?= flash_show() ?>

<div class="card border-0 shadow-sm">
    <div class="card-header">
        <ul class="nav nav-tabs card-header-tabs" id="settingsTabs">
            <li class="nav-item">
                <a class="nav-link <?= $active_tab === 'general' ? 'active' : '' ?>"
                   href="<?= APP_URL ?>/medical-center/settings.php?tab=general">
                    <i class="fas fa-info-circle me-1"></i> General
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $active_tab === 'schedule' ? 'active' : '' ?>"
                   href="<?= APP_URL ?>/medical-center/settings.php?tab=schedule">
                    <i class="fas fa-calendar-alt me-1"></i> Doctor Schedule
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $active_tab === 'appointment' ? 'active' : '' ?>"
                   href="<?= APP_URL ?>/medical-center/settings.php?tab=appointment">
                    <i class="fas fa-calendar-check me-1"></i> Appointments
                </a>
            </li>
        </ul>
    </div>
    <div class="card-body p-4">

        <?php if ($active_tab === 'general'): ?>
        <!-- TAB 1: General -->
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="active_tab" value="general">
            <div class="row g-3">
                <div class="col-sm-6">
                    <label class="form-label fw-semibold">Clinic Name</label>
                    <input type="text" name="clinic_name" class="form-control"
                           value="<?= h($settings['clinic_name'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                    <label class="form-label fw-semibold">Clinic Location</label>
                    <input type="text" name="clinic_location" class="form-control"
                           value="<?= h($settings['clinic_location'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                    <label class="form-label fw-semibold">Doctor Name</label>
                    <input type="text" name="doctor_name" class="form-control"
                           value="<?= h($settings['doctor_name'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                    <label class="form-label fw-semibold">Doctor Designation</label>
                    <input type="text" name="doctor_designation" class="form-control"
                           value="<?= h($settings['doctor_designation'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Doctor Qualification</label>
                    <input type="text" name="doctor_qualification" class="form-control"
                           value="<?= h($settings['doctor_qualification'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                    <label class="form-label fw-semibold">Weekday Hours</label>
                    <input type="text" name="clinic_hours_weekday" class="form-control"
                           placeholder="e.g. 9:00 AM – 5:00 PM"
                           value="<?= h($settings['clinic_hours_weekday'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                    <label class="form-label fw-semibold">Weekend Hours</label>
                    <input type="text" name="clinic_hours_weekend" class="form-control"
                           placeholder="e.g. Closed or 9:00 AM – 1:00 PM"
                           value="<?= h($settings['clinic_hours_weekend'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                    <label class="form-label fw-semibold">Contact Phone</label>
                    <input type="text" name="contact_phone" class="form-control"
                           value="<?= h($settings['contact_phone'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                    <label class="form-label fw-semibold">Contact Email</label>
                    <input type="email" name="contact_email" class="form-control"
                           value="<?= h($settings['contact_email'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Emergency Note</label>
                    <textarea name="emergency_note" rows="2" class="form-control"><?= h($settings['emergency_note'] ?? '') ?></textarea>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Save General Settings
                    </button>
                </div>
            </div>
        </form>

        <?php elseif ($active_tab === 'schedule'): ?>
        <!-- TAB 2: Doctor Schedule -->
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="active_tab" value="schedule">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Day</th>
                            <th>Start Time</th>
                            <th>End Time</th>
                            <th>Max Slots</th>
                            <th>Available</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($dow = 0; $dow <= 6; $dow++): ?>
                        <?php $s = $sched_by_dow[$dow] ?? ['start_time'=>'09:00:00','end_time'=>'17:00:00','max_slots'=>10,'is_available'=>0]; ?>
                        <input type="hidden" name="day_of_week[]" value="<?= $dow ?>">
                        <tr>
                            <td class="fw-semibold"><?= mc_day_name($dow) ?></td>
                            <td>
                                <input type="time" name="start_time[]" class="form-control form-control-sm" style="width:130px"
                                       value="<?= h(substr($s['start_time'], 0, 5)) ?>">
                            </td>
                            <td>
                                <input type="time" name="end_time[]" class="form-control form-control-sm" style="width:130px"
                                       value="<?= h(substr($s['end_time'], 0, 5)) ?>">
                            </td>
                            <td>
                                <input type="number" name="max_slots[]" min="1" max="100"
                                       class="form-control form-control-sm" style="width:80px"
                                       value="<?= (int)$s['max_slots'] ?>">
                            </td>
                            <td>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox"
                                           name="is_available[<?= $dow ?>]" value="1"
                                           <?= $s['is_available'] ? 'checked' : '' ?>>
                                </div>
                            </td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save me-1"></i> Save Schedule
            </button>
        </form>

        <?php elseif ($active_tab === 'appointment'): ?>
        <!-- TAB 3: Appointment Settings -->
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="active_tab" value="appointment">
            <div class="mb-4">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="appointment_enabled" id="apt_enabled"
                           value="1" <?= ($settings['appointment_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label fw-semibold" for="apt_enabled">
                        Enable Online Appointment Booking
                    </label>
                </div>
                <div class="text-muted small mt-1">
                    When disabled, the appointment form on the public page will be hidden.
                </div>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save me-1"></i> Save Appointment Settings
            </button>
        </form>
        <?php endif; ?>

    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
