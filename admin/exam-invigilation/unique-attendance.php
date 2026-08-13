<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('exam-invigilation', 'can_edit');

$id = (int)($_GET['id'] ?? 0);
$exam_st = db()->prepare('SELECT * FROM ei_exams WHERE id = ?');
$exam_st->execute([$id]);
$exam = $exam_st->fetch();
if (!$exam) {
    flash_set('error', 'Exam not found.');
    redirect(APP_URL . '/exam-invigilation/index.php');
}

$page_title = h($exam['exam_name']) . ' – Officials (Unique-Slot) Attendance';

function ei_time_order_expr_uatt(string $column = 'time_slot'): string
{
    $allowed = ['time_slot', 's.time_slot'];
    if (!in_array($column, $allowed, true)) $column = 'time_slot';
    return "COALESCE(
        STR_TO_DATE(TRIM(SUBSTRING_INDEX(REPLACE({$column}, '-', '–'), '–', 1)), '%h:%i %p'),
        STR_TO_DATE(TRIM(SUBSTRING_INDEX(REPLACE({$column}, '-', '–'), '–', 1)), '%H:%i')
    )";
}

// ── Available dates ────────────────────────────────────────────────────────────────
$date_rows = db()->prepare(
    'SELECT DISTINCT slot_date FROM ei_slots WHERE exam_id = ? ORDER BY slot_date ASC'
);
$date_rows->execute([$id]);
$available_dates = array_column($date_rows->fetchAll(), 'slot_date');

$f_date = trim((string)($_GET['slot_date'] ?? ''));
if ($f_date === '' && !empty($available_dates)) {
    $f_date = $available_dates[0];
}
if ($f_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date)) {
    $f_date = '';
}

// ── Handle POST – save officials' per-sitting attendance ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'save_attendance') {
    csrf_check();
    $post_date = trim((string)($_POST['slot_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $post_date)) {
        flash_set('error', 'Invalid date.');
        redirect(APP_URL . '/exam-invigilation/unique-attendance.php?id=' . $id . '&slot_date=' . urlencode($f_date));
    }

    // All unique-slot payees — they are officials, NOT invigilators, so the
    // invigilation is_active flag does not exclude them here.
    try {
        $u_payees = db()->query(
            "SELECT f.id, f.dept_id, d.dept_type
             FROM ei_faculty f
             JOIN dept_departments d ON d.id = f.dept_id
             WHERE f.pay_by_unique_slot = 1"
        )->fetchAll();
    } catch (Throwable $e) {
        $u_payees = []; // migrations ei-office-departments-v1 / ei-unique-slot-payees-v1 not run yet
    }

    if ($u_payees) {
        $sit_st = db()->prepare('SELECT DISTINCT time_slot, dept_id FROM ei_slots WHERE exam_id = ? AND slot_date = ?');
        $sit_st->execute([$id, $post_date]);
        $sitting_dept_map = [];
        foreach ($sit_st->fetchAll() as $sr) {
            $sitting_dept_map[$sr['time_slot']][] = (int)$sr['dept_id'];
        }
        $u_attended_post = $_POST['uattended'] ?? [];
        if (!is_array($u_attended_post)) $u_attended_post = [];
        try {
            $u_upsert = db()->prepare(
                'INSERT INTO ei_unique_slot_attendance (exam_id, faculty_id, slot_date, time_slot, attended)
                 VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE attended = VALUES(attended), updated_at = NOW()'
            );
            foreach ($u_payees as $p) {
                foreach ($sitting_dept_map as $sit_ts => $sit_depts) {
                    $eligible = ($p['dept_type'] === 'office') || in_array((int)$p['dept_id'], $sit_depts, true);
                    if (!$eligible) continue;
                    $u_att = isset($u_attended_post[$p['id']][md5($sit_ts)]) ? 1 : 0;
                    $u_upsert->execute([$id, (int)$p['id'], $post_date, $sit_ts, $u_att]);
                }
            }
            flash_set('success', 'Officials attendance saved for ' . date('d M Y', strtotime($post_date)) . '.');
        } catch (Throwable $e) {
            flash_set('error', 'Could not save attendance. Make sure migration ei-unique-slot-payees-v1.sql has been run.');
        }
    } else {
        flash_set('error', 'No unique-slot payees found. Enable "Paid by Unique Slot" on the relevant employees first.');
    }
    redirect(APP_URL . '/exam-invigilation/unique-attendance.php?id=' . $id . '&slot_date=' . urlencode($post_date));
}

// ── Fetch payees, sittings & attendance for selected date ─────────────────────
$unique_payees = [];
$sitting_depts = [];
$u_att_map     = [];

if ($f_date !== '') {
    try {
        $unique_payees = db()->query(
            "SELECT f.id, f.name, f.designation, f.dept_id, f.is_active,
                    f.remuneration_per_slot AS rate,
                    d.name AS dept_name, d.dept_type
             FROM ei_faculty f
             JOIN dept_departments d ON d.id = f.dept_id
             WHERE f.pay_by_unique_slot = 1
             ORDER BY d.dept_type DESC, d.name ASC, f.name ASC"
        )->fetchAll();
    } catch (Throwable $e) {
        $unique_payees = []; // migrations not run yet
    }

    $time_order = ei_time_order_expr_uatt('time_slot');
    $sit_st = db()->prepare(
        "SELECT time_slot, GROUP_CONCAT(DISTINCT dept_id) AS dept_ids
         FROM ei_slots
         WHERE exam_id = ? AND slot_date = ?
         GROUP BY time_slot
         ORDER BY {$time_order} ASC, time_slot ASC"
    );
    $sit_st->execute([$id, $f_date]);
    foreach ($sit_st->fetchAll() as $sr) {
        $sitting_depts[$sr['time_slot']] = array_map('intval', explode(',', (string)$sr['dept_ids']));
    }

    if ($unique_payees && $sitting_depts) {
        try {
            $u_att_st = db()->prepare(
                'SELECT faculty_id, time_slot, attended FROM ei_unique_slot_attendance WHERE exam_id = ? AND slot_date = ?'
            );
            $u_att_st->execute([$id, $f_date]);
            foreach ($u_att_st->fetchAll() as $ur) {
                $u_att_map[$ur['faculty_id'] . '|' . $ur['time_slot']] = (int)$ur['attended'];
            }
        } catch (Throwable $e) {
            $u_att_map = [];
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/exam-invigilation/index.php">Exam Invigilation</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/exam-invigilation/view.php?id=<?= $id ?>"><?= h($exam['exam_name']) ?></a></li>
            <li class="breadcrumb-item active">Officials Attendance</li>
        </ol>
    </nav>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/exam-invigilation/attendance.php?id=<?= $id ?>&slot_date=<?= urlencode($f_date) ?>"
           class="btn btn-outline-primary btn-sm" style="border-radius:10px;">
            <i class="fas fa-calendar-check me-1"></i> Invigilator Attendance
        </a>
        <a href="<?= APP_URL ?>/exam-invigilation/remuneration-bill.php?id=<?= $id ?>"
           class="btn btn-outline-success btn-sm" style="border-radius:10px;">
            <i class="fas fa-file-invoice-dollar me-1"></i> Remuneration Bill
        </a>
    </div>
</div>

<?php flash_show(); ?>

<div class="card mb-3">
    <div class="card-body py-2 px-3">
        <form method="GET" class="row g-2 align-items-center">
            <input type="hidden" name="id" value="<?= $id ?>">
            <div class="col-12 col-md-auto">
                <label class="form-label mb-0 fw-medium me-2">Date:</label>
            </div>
            <div class="col-12 col-md-auto">
                <select name="slot_date" class="form-select form-select-sm" style="border-radius:8px;min-width:160px;" onchange="this.form.submit()">
                    <?php if (empty($available_dates)): ?>
                    <option value="">No slots available</option>
                    <?php else: ?>
                    <?php foreach ($available_dates as $dt): ?>
                    <option value="<?= h($dt) ?>" <?= $f_date === $dt ? 'selected' : '' ?>>
                        <?= date('D, d M Y', strtotime($dt)) ?>
                    </option>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-primary" style="border-radius:8px;">Go</button>
            </div>
        </form>
    </div>
</div>

<?php if (empty($available_dates)): ?>
<div class="alert alert-warning">This exam has no slots yet. <a href="<?= APP_URL ?>/exam-invigilation/slot-create.php?exam_id=<?= $id ?>">Add slots</a> first.</div>
<?php elseif ($f_date !== '' && empty($sitting_depts)): ?>
<div class="alert alert-info">No sittings found for this date.</div>
<?php elseif ($f_date !== '' && empty($unique_payees)): ?>
<div class="alert alert-info">
    No unique-slot payees found. Enable <strong>"Paid by Unique Slot"</strong> on the relevant employees in the
    <a href="<?= APP_URL ?>/exam-invigilation/faculty.php">Faculty Pool</a> first.
</div>
<?php elseif ($f_date !== ''): ?>

<form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" value="save_attendance">
    <input type="hidden" name="slot_date" value="<?= h($f_date) ?>">

    <div class="card">
        <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <h6 class="mb-0 fw-semibold">
                <i class="fas fa-user-clock me-2 text-muted"></i>
                Officials Attendance — <?= date('l, d F Y', strtotime($f_date)) ?>
            </h6>
            <span class="text-muted" style="font-size:.8rem;">
                Paid per sitting (date + time). Unchecked = absent = not paid.
                Separate from staff/invigilator attendance — includes inactive payees.
            </span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th class="px-3" style="width:40px;">#</th>
                            <th>Employee</th>
                            <th>Department / Office</th>
                            <th style="width:110px;">Rate (৳)</th>
                            <?php foreach (array_keys($sitting_depts) as $sit_ts): ?>
                            <th class="text-center"><?= h($sit_ts) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php $u_no = 0; foreach ($unique_payees as $p): ?>
                        <?php
                        $eligible_any = false;
                        foreach ($sitting_depts as $sit_dept_ids) {
                            if ($p['dept_type'] === 'office' || in_array((int)$p['dept_id'], $sit_dept_ids, true)) { $eligible_any = true; break; }
                        }
                        if (!$eligible_any) continue;
                        $u_no++;
                        ?>
                        <tr>
                            <td class="px-3"><?= $u_no ?></td>
                            <td>
                                <span class="fw-medium"><?= h($p['name']) ?></span>
                                <?php if ($p['designation']): ?><small class="text-muted">– <?= h($p['designation']) ?></small><?php endif; ?>
                                <?php if (!(int)$p['is_active']): ?>
                                <span class="badge bg-secondary bg-opacity-10 text-secondary ms-1" title="Not active for invigilation — still paid as official">Inactive (invigilation)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $p['dept_type'] === 'office' ? 'bg-warning bg-opacity-15 text-warning-emphasis' : 'bg-primary bg-opacity-10 text-primary' ?>"><?= h($p['dept_name']) ?></span>
                            </td>
                            <td><?= $p['rate'] > 0 ? '<span class="fw-medium text-success">৳' . number_format((float)$p['rate'], 2) . '</span>' : '<span class="text-muted">—</span>' ?></td>
                            <?php foreach ($sitting_depts as $sit_ts => $sit_dept_ids): ?>
                            <td class="text-center">
                                <?php if ($p['dept_type'] === 'office' || in_array((int)$p['dept_id'], $sit_dept_ids, true)): ?>
                                <?php $u_checked = $u_att_map[$p['id'] . '|' . $sit_ts] ?? 1; ?>
                                <div class="form-check form-switch d-inline-flex justify-content-center">
                                    <input class="form-check-input" type="checkbox"
                                           name="uattended[<?= (int)$p['id'] ?>][<?= md5($sit_ts) ?>]" value="1"
                                           <?= $u_checked ? 'checked' : '' ?> style="cursor:pointer;">
                                </div>
                                <?php else: ?>
                                <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($u_no === 0): ?>
                        <tr><td colspan="<?= 4 + count($sitting_depts) ?>" class="text-center text-muted py-4">No unique-slot payees eligible for this date.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if ($u_no > 0): ?>
        <div class="card-footer text-end py-2 px-4">
            <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                <i class="fas fa-save me-1"></i> Save Attendance
            </button>
        </div>
        <?php endif; ?>
    </div>
</form>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
