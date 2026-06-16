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

$page_title = h($exam['exam_name']) . ' – Attendance';

function ei_time_order_expr_att(string $column = 'time_slot'): string
{
    $allowed = ['time_slot', 's.time_slot'];
    if (!in_array($column, $allowed, true)) $column = 'time_slot';
    return "COALESCE(
        STR_TO_DATE(TRIM(SUBSTRING_INDEX(REPLACE({$column}, '-', '–'), '–', 1)), '%h:%i %p'),
        STR_TO_DATE(TRIM(SUBSTRING_INDEX(REPLACE({$column}, '-', '–'), '–', 1)), '%H:%i')
    )";
}

// ── Available dates ───────────────────────────────────────────────────────────
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

// ── Handle POST – save attendance ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'save_attendance') {
    csrf_check();
    $post_date = trim((string)($_POST['slot_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $post_date)) {
        flash_set('error', 'Invalid date.');
        redirect(APP_URL . '/exam-invigilation/attendance.php?id=' . $id . '&slot_date=' . urlencode($f_date));
    }

    // Fetch all slots on this date for this exam with assigned faculty
    $slots_st = db()->prepare(
        "SELECT s.id AS slot_id, s.faculty1_id, s.faculty2_id
         FROM ei_slots s
         WHERE s.exam_id = ? AND s.slot_date = ?
         AND (s.faculty1_id IS NOT NULL OR s.faculty2_id IS NOT NULL)"
    );
    $slots_st->execute([$id, $post_date]);
    $slots = $slots_st->fetchAll();

    $attended_map = $_POST['attended'] ?? [];  // ['slot_id_faculty_id' => '1']

    $upsert = db()->prepare(
        'INSERT INTO ei_slot_attendance (exam_id, slot_id, faculty_id, attended, notes)
         VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE attended=VALUES(attended), notes=VALUES(notes), updated_at=NOW()'
    );

    foreach ($slots as $slot) {
        foreach ([1, 2] as $pos) {
            $fac_id = (int)$slot['faculty' . $pos . '_id'];
            if (!$fac_id) continue;
            $key      = $slot['slot_id'] . '_' . $fac_id;
            $attended = isset($attended_map[$key]) ? 1 : 0;
            $notes    = trim((string)($_POST['notes'][$key] ?? ''));
            $upsert->execute([$id, $slot['slot_id'], $fac_id, $attended, $notes ?: null]);
        }
    }

    flash_set('success', 'Attendance saved for ' . date('d M Y', strtotime($post_date)) . '.');
    redirect(APP_URL . '/exam-invigilation/attendance.php?id=' . $id . '&slot_date=' . urlencode($post_date));
}

// ── Fetch slots for selected date ─────────────────────────────────────────────
$slots = [];
$attendance_map = [];

if ($f_date !== '') {
    $time_order = ei_time_order_expr_att('s.time_slot');
    $st = db()->prepare(
        "SELECT s.*,
                f1.name AS fac1_name, f1.designation AS fac1_designation, f1.remuneration_per_slot AS fac1_rate,
                f2.name AS fac2_name, f2.designation AS fac2_designation, f2.remuneration_per_slot AS fac2_rate
         FROM ei_slots s
         LEFT JOIN ei_faculty f1 ON f1.id = s.faculty1_id
         LEFT JOIN ei_faculty f2 ON f2.id = s.faculty2_id
         WHERE s.exam_id = ? AND s.slot_date = ?
         ORDER BY {$time_order} ASC, s.time_slot ASC, s.room_number ASC"
    );
    $st->execute([$id, $f_date]);
    $slots = $st->fetchAll();

    if (!empty($slots)) {
        $slot_ids = array_column($slots, 'id');
        $placeholders = implode(',', array_fill(0, count($slot_ids), '?'));
        $att_st = db()->prepare(
            "SELECT slot_id, faculty_id, attended, notes FROM ei_slot_attendance WHERE slot_id IN ($placeholders)"
        );
        $att_st->execute($slot_ids);
        foreach ($att_st->fetchAll() as $row) {
            $attendance_map[$row['slot_id'] . '_' . $row['faculty_id']] = $row;
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
            <li class="breadcrumb-item active">Attendance</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/exam-invigilation/remuneration-bill.php?id=<?= $id ?>"
       class="btn btn-outline-success btn-sm" style="border-radius:10px;">
        <i class="fas fa-file-invoice-dollar me-1"></i> Remuneration Bill
    </a>
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

<?php if ($f_date !== '' && empty($slots)): ?>
<div class="alert alert-info">No slots with assigned faculty found for this date.</div>
<?php elseif ($f_date !== ''): ?>

<form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" value="save_attendance">
    <input type="hidden" name="slot_date" value="<?= h($f_date) ?>">

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-calendar-check me-2 text-muted"></i>
            Attendance — <?= date('l, d F Y', strtotime($f_date)) ?>
        </h6>
        <button type="submit" class="btn btn-primary btn-sm" style="border-radius:10px;">
            <i class="fas fa-save me-1"></i> Save Attendance
        </button>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th class="px-3" style="width:40px;">#</th>
                            <th>Time Slot</th>
                            <th>Room</th>
                            <th>Faculty</th>
                            <th>Designation</th>
                            <th style="width:100px;">Rate (৳)</th>
                            <th class="text-center" style="width:100px;">Attended</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php $row_no = 0; ?>
                    <?php foreach ($slots as $slot): ?>
                        <?php foreach ([1, 2] as $pos): ?>
                        <?php
                        $fac_id   = (int)($slot['faculty' . $pos . '_id'] ?? 0);
                        $fac_name = $slot['fac' . $pos . '_name'] ?? null;
                        if (!$fac_id || !$fac_name) continue;
                        $fac_desig = $slot['fac' . $pos . '_designation'] ?? '';
                        $fac_rate  = (float)($slot['fac' . $pos . '_rate'] ?? 0);
                        $key       = $slot['id'] . '_' . $fac_id;
                        $att_row   = $attendance_map[$key] ?? null;
                        $attended  = $att_row ? (int)$att_row['attended'] : 0;
                        $notes_val = $att_row['notes'] ?? '';
                        $row_no++;
                        ?>
                        <tr class="<?= $attended ? 'table-success' : '' ?>">
                            <td class="px-3"><?= $row_no ?></td>
                            <td class="fw-medium"><?= h($slot['time_slot']) ?></td>
                            <td><span class="badge bg-secondary bg-opacity-15 text-dark"><?= h($slot['room_number']) ?></span></td>
                            <td>
                                <span class="fw-medium"><?= h($fac_name) ?></span>
                                <span class="badge bg-primary bg-opacity-10 text-primary ms-1" style="font-size:.7rem;">Inv <?= $pos ?></span>
                            </td>
                            <td class="text-muted" style="font-size:.85rem;"><?= $fac_desig ? h($fac_desig) : '—' ?></td>
                            <td>
                                <?php if ($fac_rate > 0): ?>
                                <span class="fw-medium text-success">৳<?= number_format($fac_rate, 2) ?></span>
                                <?php else: ?>
                                <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="form-check form-switch d-inline-flex justify-content-center">
                                    <input class="form-check-input attendance-toggle" type="checkbox"
                                           name="attended[<?= h($key) ?>]" value="1"
                                           id="att_<?= h($key) ?>"
                                           data-row="row_<?= h($key) ?>"
                                           <?= $attended ? 'checked' : '' ?>
                                           style="cursor:pointer;">
                                </div>
                            </td>
                            <td>
                                <input type="text" name="notes[<?= h($key) ?>]" class="form-control form-control-sm"
                                       style="border-radius:8px;min-width:140px;"
                                       value="<?= h($notes_val) ?>" placeholder="Optional note…" maxlength="300">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    <?php if ($row_no === 0): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">No assigned faculty for this date's slots.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if ($row_no > 0): ?>
        <div class="card-footer text-end py-2 px-4">
            <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                <i class="fas fa-save me-1"></i> Save Attendance
            </button>
        </div>
        <?php endif; ?>
    </div>
</form>

<script>
document.querySelectorAll('.attendance-toggle').forEach(function(cb) {
    cb.addEventListener('change', function() {
        var tr = this.closest('tr');
        if (this.checked) {
            tr.classList.add('table-success');
            tr.classList.remove('table-danger');
        } else {
            tr.classList.remove('table-success');
        }
    });
});
</script>

<?php elseif (empty($available_dates)): ?>
<div class="alert alert-warning">This exam has no slots yet. <a href="<?= APP_URL ?>/exam-invigilation/slot-create.php?exam_id=<?= $id ?>">Add slots</a> first.</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
