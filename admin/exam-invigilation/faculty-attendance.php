<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('exam-invigilation', 'can_edit');

$fid = (int)($_GET['id'] ?? 0);

try {
    $fac_st = db()->prepare(
        "SELECT f.*, d.name AS dept_name, d.dept_type
         FROM ei_faculty f
         JOIN dept_departments d ON d.id = f.dept_id
         WHERE f.id = ?"
    );
    $fac_st->execute([$fid]);
    $fac = $fac_st->fetch();
} catch (Throwable $e) {
    // dept_type column missing (run ei-office-departments-v1.sql); treat as academic
    $fac_st = db()->prepare(
        "SELECT f.*, d.name AS dept_name, 'academic' AS dept_type
         FROM ei_faculty f
         JOIN dept_departments d ON d.id = f.dept_id
         WHERE f.id = ?"
    );
    $fac_st->execute([$fid]);
    $fac = $fac_st->fetch();
}

if (!$fac) {
    flash_set('error', 'Faculty not found.');
    redirect(APP_URL . '/exam-invigilation/faculty.php');
}
if (empty($fac['pay_by_unique_slot'])) {
    flash_set('error', 'Unique-slot attendance is only available for faculty paid by unique slot.');
    redirect(APP_URL . '/exam-invigilation/faculty-edit.php?id=' . $fid);
}

$page_title = h($fac['name']) . ' – Unique-Slot Attendance';

function ei_time_order_expr_fatt(string $column = 'time_slot'): string
{
    $allowed = ['time_slot', 's.time_slot'];
    if (!in_array($column, $allowed, true)) $column = 'time_slot';
    return "COALESCE(
        STR_TO_DATE(TRIM(SUBSTRING_INDEX(REPLACE({$column}, '-', '–'), '–', 1)), '%h:%i %p'),
        STR_TO_DATE(TRIM(SUBSTRING_INDEX(REPLACE({$column}, '-', '–'), '–', 1)), '%H:%i')
    )";
}

/**
 * Eligible sittings (date + time slot) for this payee within an exam.
 * Office payees count ALL sittings; department payees only their own department's sittings.
 */
function ei_payee_sittings(int $exam_id, array $fac): array
{
    $time_order = ei_time_order_expr_fatt('time_slot');
    $st = db()->prepare(
        "SELECT slot_date, time_slot, GROUP_CONCAT(DISTINCT dept_id) AS dept_ids
         FROM ei_slots
         WHERE exam_id = ?
         GROUP BY slot_date, time_slot
         ORDER BY slot_date ASC, {$time_order} ASC, time_slot ASC"
    );
    $st->execute([$exam_id]);
    $sittings = [];
    foreach ($st->fetchAll() as $row) {
        $dept_ids = array_map('intval', explode(',', (string)$row['dept_ids']));
        if (($fac['dept_type'] ?? 'academic') !== 'office' && !in_array((int)$fac['dept_id'], $dept_ids, true)) {
            continue;
        }
        $sittings[] = ['slot_date' => $row['slot_date'], 'time_slot' => $row['time_slot']];
    }
    return $sittings;
}

// ── Exams for the selector ────────────────────────────────────────────────────
$exams = db()->query(
    'SELECT id, exam_name, exam_year, is_active FROM ei_exams ORDER BY exam_year DESC, exam_name ASC'
)->fetchAll();

$exam_id = (int)($_GET['exam_id'] ?? 0);

// ── Handle POST – save per-sitting attendance ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'save_attendance') {
    csrf_check();
    $post_exam_id = (int)($_POST['exam_id'] ?? 0);
    $chk = db()->prepare('SELECT id FROM ei_exams WHERE id = ?');
    $chk->execute([$post_exam_id]);
    if (!$chk->fetch()) {
        flash_set('error', 'Exam not found.');
        redirect(APP_URL . '/exam-invigilation/faculty-attendance.php?id=' . $fid);
    }

    $sittings      = ei_payee_sittings($post_exam_id, $fac);
    $attended_post = $_POST['attended'] ?? [];
    if (!is_array($attended_post)) $attended_post = [];

    try {
        $upsert = db()->prepare(
            'INSERT INTO ei_unique_slot_attendance (exam_id, faculty_id, slot_date, time_slot, attended)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE attended = VALUES(attended), updated_at = NOW()'
        );
        foreach ($sittings as $sit) {
            $key = md5($sit['slot_date'] . '|' . $sit['time_slot']);
            $att = isset($attended_post[$key]) ? 1 : 0;
            $upsert->execute([$post_exam_id, $fid, $sit['slot_date'], $sit['time_slot'], $att]);
        }
        flash_set('success', 'Unique-slot attendance saved for ' . $fac['name'] . '.');
    } catch (Throwable $e) {
        flash_set('error', 'Could not save attendance. Make sure migration ei-unique-slot-payees-v1.sql has been run.');
    }
    redirect(APP_URL . '/exam-invigilation/faculty-attendance.php?id=' . $fid . '&exam_id=' . $post_exam_id);
}

// ── Load sittings + existing attendance for selected exam ────────────────────
$sittings = [];
$att_map  = [];
if ($exam_id) {
    $sittings = ei_payee_sittings($exam_id, $fac);
    try {
        $att_st = db()->prepare(
            'SELECT slot_date, time_slot, attended FROM ei_unique_slot_attendance WHERE exam_id = ? AND faculty_id = ?'
        );
        $att_st->execute([$exam_id, $fid]);
        foreach ($att_st->fetchAll() as $ar) {
            $att_map[$ar['slot_date'] . '|' . $ar['time_slot']] = (int)$ar['attended'];
        }
    } catch (Throwable $e) {
        $att_map = []; // migration not run yet
    }
}

$attended_count = 0;
foreach ($sittings as $sit) {
    if (($att_map[$sit['slot_date'] . '|' . $sit['time_slot']] ?? 0) === 1) $attended_count++;
}
$rate  = (float)($fac['remuneration_per_slot'] ?? 0);
$total = $attended_count * $rate;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/exam-invigilation/index.php">Exam Invigilation</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/exam-invigilation/faculty.php">Faculty Pool</a></li>
            <li class="breadcrumb-item active">Unique-Slot Attendance</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/exam-invigilation/faculty-edit.php?id=<?= $fid ?>"
       class="btn btn-outline-primary btn-sm" style="border-radius:10px;">
        <i class="fas fa-edit me-1"></i> Edit Faculty
    </a>
</div>

<?php flash_show(); ?>

<!-- Payee info -->
<div class="card mb-3">
    <div class="card-body py-3 px-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
            <span class="fw-semibold"><?= h($fac['name']) ?></span>
            <?php if (!empty($fac['designation'])): ?>
            <small class="text-muted">– <?= h($fac['designation']) ?></small>
            <?php endif; ?>
            <span class="badge <?= ($fac['dept_type'] ?? '') === 'office' ? 'bg-warning bg-opacity-15 text-warning-emphasis' : 'bg-primary bg-opacity-10 text-primary' ?> ms-2"><?= h($fac['dept_name']) ?></span>
            <span class="badge bg-info bg-opacity-15 text-info ms-1">Paid by Unique Slot</span>
            <?php if (!(int)$fac['is_active']): ?>
            <span class="badge bg-secondary ms-1">Inactive</span>
            <?php endif; ?>
        </div>
        <div class="text-muted" style="font-size:.85rem;">
            Rate/Sitting: <strong class="text-success">৳<?= number_format($rate, 2) ?></strong>
        </div>
    </div>
</div>

<!-- Exam selector -->
<div class="card mb-3">
    <div class="card-body py-2 px-3">
        <form method="GET" class="row g-2 align-items-center">
            <input type="hidden" name="id" value="<?= $fid ?>">
            <div class="col-12 col-md-auto">
                <label class="form-label mb-0 fw-medium me-2">Exam:</label>
            </div>
            <div class="col-12 col-md-auto">
                <select name="exam_id" class="form-select form-select-sm" style="border-radius:8px;min-width:260px;" onchange="this.form.submit()">
                    <option value="0">— Select Exam —</option>
                    <?php foreach ($exams as $ex): ?>
                    <option value="<?= (int)$ex['id'] ?>" <?= $exam_id === (int)$ex['id'] ? 'selected' : '' ?>>
                        <?= h($ex['exam_name']) ?> (<?= h($ex['exam_year']) ?>)<?= !(int)$ex['is_active'] ? ' — inactive' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-primary" style="border-radius:8px;">Go</button>
            </div>
        </form>
    </div>
</div>

<?php if (!$exam_id): ?>
<div class="card">
    <div class="card-body text-center text-muted py-5">
        <i class="fas fa-hand-pointer fa-2x mb-2 d-block"></i>
        Select an exam above to mark this payee's per-sitting attendance.
    </div>
</div>
<?php elseif (empty($sittings)): ?>
<div class="alert alert-info">
    No eligible sittings found for this exam.
    <?php if (($fac['dept_type'] ?? 'academic') !== 'office'): ?>
    Department payees only count sittings that include their own department (<?= h($fac['dept_name']) ?>).
    <?php endif; ?>
</div>
<?php else: ?>

<form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" value="save_attendance">
    <input type="hidden" name="exam_id" value="<?= $exam_id ?>">

    <div class="card">
        <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-user-clock me-2 text-muted"></i>Sitting Attendance</h6>
            <span class="text-muted" style="font-size:.8rem;">Checked = present = paid. Unchecked = absent = not paid. This does not affect staff attendance.</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th class="px-3" style="width:40px;">#</th>
                            <th>Date</th>
                            <th>Time Slot</th>
                            <th class="text-center" style="width:130px;">Status</th>
                            <th class="text-center" style="width:100px;">Present</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($sittings as $si => $sit): ?>
                        <?php
                        $map_key  = $sit['slot_date'] . '|' . $sit['time_slot'];
                        $post_key = md5($map_key);
                        $marked   = array_key_exists($map_key, $att_map);
                        $attended = $marked ? (int)$att_map[$map_key] : 0;
                        ?>
                        <tr class="<?= $attended ? 'table-success' : '' ?>">
                            <td class="px-3"><?= $si + 1 ?></td>
                            <td class="fw-medium"><?= date('D, d M Y', strtotime($sit['slot_date'])) ?></td>
                            <td><span class="badge bg-secondary bg-opacity-15 text-dark"><?= h($sit['time_slot']) ?></span></td>
                            <td class="text-center">
                                <?php if (!$marked): ?>
                                <span class="badge bg-secondary bg-opacity-10 text-secondary">Not marked</span>
                                <?php elseif ($attended): ?>
                                <span class="badge bg-success bg-opacity-15 text-success">Present</span>
                                <?php else: ?>
                                <span class="badge bg-danger bg-opacity-15 text-danger">Absent</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="form-check form-switch d-inline-flex justify-content-center">
                                    <input class="form-check-input" type="checkbox"
                                           name="attended[<?= $post_key ?>]" value="1"
                                           <?= $attended ? 'checked' : '' ?> style="cursor:pointer;">
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light fw-semibold">
                        <tr>
                            <td colspan="3" class="text-end px-3">Attended Sittings × Rate:</td>
                            <td class="text-center"><?= $attended_count ?> × ৳<?= number_format($rate, 2) ?></td>
                            <td class="text-center text-success">৳<?= number_format($total, 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <div class="card-footer text-end py-2 px-4">
            <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                <i class="fas fa-save me-1"></i> Save Attendance
            </button>
        </div>
    </div>
</form>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
