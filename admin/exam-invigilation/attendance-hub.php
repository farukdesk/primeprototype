<?php
/**
 * Attendance Hub (UX redesign – Phase 2)
 *
 * One place to mark ALL attendance for a day:
 *   Step 1: pick exam  →  Step 2: pick date  →  mark everyone in one grid.
 *
 * Combines the invigilator grid (ei_slot_attendance, previously attendance.php)
 * and the officials / unique-slot payee grid (ei_unique_slot_attendance,
 * previously unique-attendance.php) into a single form with ONE save button.
 * The per-payee drill-down (faculty-attendance.php) stays available via the
 * payee name links.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('exam-invigilation', 'can_edit');

$page_title = 'Attendance Hub';

function ei_time_order_expr_hub(string $column = 'time_slot'): string
{
    $allowed = ['time_slot', 's.time_slot'];
    if (!in_array($column, $allowed, true)) $column = 'time_slot';
    return "COALESCE(
        STR_TO_DATE(TRIM(SUBSTRING_INDEX(REPLACE({$column}, '-', '–'), '–', 1)), '%h:%i %p'),
        STR_TO_DATE(TRIM(SUBSTRING_INDEX(REPLACE({$column}, '-', '–'), '–', 1)), '%H:%i')
    )";
}

// ── Step 1: exams ─────────────────────────────────────────────────────────────
$exams = db()->query(
    'SELECT id, exam_name, exam_year, is_active FROM ei_exams ORDER BY is_active DESC, exam_year DESC, exam_name ASC'
)->fetchAll();

$exam_id = (int)($_GET['exam_id'] ?? 0);
if (!$exam_id && !empty($exams)) {
    $exam_id = (int)$exams[0]['id']; // default: most recent active exam
}

$exam = null;
if ($exam_id) {
    $exam_st = db()->prepare('SELECT * FROM ei_exams WHERE id = ?');
    $exam_st->execute([$exam_id]);
    $exam = $exam_st->fetch() ?: null;
    if (!$exam) $exam_id = 0;
}

// ── Step 2: dates ─────────────────────────────────────────────────────────────
$available_dates = [];
$f_date = '';
if ($exam_id) {
    $date_rows = db()->prepare('SELECT DISTINCT slot_date FROM ei_slots WHERE exam_id = ? ORDER BY slot_date ASC');
    $date_rows->execute([$exam_id]);
    $available_dates = array_column($date_rows->fetchAll(), 'slot_date');

    $f_date = trim((string)($_GET['slot_date'] ?? ''));
    if ($f_date !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date) || !in_array($f_date, $available_dates, true))) {
        $f_date = '';
    }
    if ($f_date === '' && !empty($available_dates)) {
        // Default to today if it is an exam day, otherwise the next upcoming date,
        // otherwise the last date (exam already finished).
        $today = date('Y-m-d');
        if (in_array($today, $available_dates, true)) {
            $f_date = $today;
        } else {
            foreach ($available_dates as $dt) {
                if ($dt >= $today) { $f_date = $dt; break; }
            }
            if ($f_date === '') $f_date = end($available_dates);
        }
    }
}

// ── Handle POST – save BOTH grids in one submit ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'save_attendance') {
    csrf_check();
    $post_exam_id = (int)($_POST['exam_id'] ?? 0);
    $post_date    = trim((string)($_POST['slot_date'] ?? ''));

    $chk = db()->prepare('SELECT id FROM ei_exams WHERE id = ?');
    $chk->execute([$post_exam_id]);
    if (!$chk->fetch() || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $post_date)) {
        flash_set('error', 'Invalid exam or date.');
        redirect(APP_URL . '/exam-invigilation/attendance-hub.php');
    }

    // 1) Invigilators (same storage as attendance.php)
    $slots_st = db()->prepare(
        'SELECT s.id AS slot_id, s.faculty1_id, s.faculty2_id
         FROM ei_slots s
         WHERE s.exam_id = ? AND s.slot_date = ?
         AND (s.faculty1_id IS NOT NULL OR s.faculty2_id IS NOT NULL)'
    );
    $slots_st->execute([$post_exam_id, $post_date]);

    $attended_map = $_POST['attended'] ?? [];
    if (!is_array($attended_map)) $attended_map = [];

    $upsert = db()->prepare(
        'INSERT INTO ei_slot_attendance (exam_id, slot_id, faculty_id, attended, notes)
         VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE attended=VALUES(attended), notes=VALUES(notes), updated_at=NOW()'
    );
    foreach ($slots_st->fetchAll() as $slot) {
        foreach ([1, 2] as $pos) {
            $fac_id = (int)$slot['faculty' . $pos . '_id'];
            if (!$fac_id) continue;
            $key      = $slot['slot_id'] . '_' . $fac_id;
            $attended = isset($attended_map[$key]) ? 1 : 0;
            $notes    = trim((string)($_POST['notes'][$key] ?? ''));
            $upsert->execute([$post_exam_id, $slot['slot_id'], $fac_id, $attended, $notes ?: null]);
        }
    }

    // 2) Officials / unique-slot payees (same storage as unique-attendance.php)
    try {
        $u_payees = db()->query(
            'SELECT f.id, f.dept_id, d.dept_type
             FROM ei_faculty f
             JOIN dept_departments d ON d.id = f.dept_id
             WHERE f.pay_by_unique_slot = 1'
        )->fetchAll();
    } catch (Throwable $e) {
        $u_payees = []; // migrations ei-office-departments-v1 / ei-unique-slot-payees-v1 not run yet
    }

    if ($u_payees) {
        $sit_st = db()->prepare('SELECT DISTINCT time_slot, dept_id FROM ei_slots WHERE exam_id = ? AND slot_date = ?');
        $sit_st->execute([$post_exam_id, $post_date]);
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
                    $u_upsert->execute([$post_exam_id, (int)$p['id'], $post_date, $sit_ts, $u_att]);
                }
            }
        } catch (Throwable $e) {
            // ei-unique-slot-payees-v1.sql not run — invigilator attendance was still saved above
        }
    }

    flash_set('success', 'Attendance saved for ' . date('d M Y', strtotime($post_date)) . ' — invigilators and officials.');
    redirect(APP_URL . '/exam-invigilation/attendance-hub.php?exam_id=' . $post_exam_id . '&slot_date=' . urlencode($post_date));
}

// ── Fetch both grids for the selected exam + date ─────────────────────────────
$slots          = [];
$attendance_map = [];
$unique_payees  = [];
$sitting_depts  = [];
$u_att_map      = [];

if ($exam_id && $f_date !== '') {
    // Invigilator slots
    $time_order = ei_time_order_expr_hub('s.time_slot');
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
    $st->execute([$exam_id, $f_date]);
    $slots = $st->fetchAll();

    if (!empty($slots)) {
        $slot_ids     = array_column($slots, 'id');
        $placeholders = implode(',', array_fill(0, count($slot_ids), '?'));
        $att_st = db()->prepare(
            "SELECT slot_id, faculty_id, attended, notes FROM ei_slot_attendance WHERE exam_id = ? AND slot_id IN ($placeholders)"
        );
        $att_st->execute(array_merge([$exam_id], $slot_ids));
        foreach ($att_st->fetchAll() as $row) {
            $attendance_map[$row['slot_id'] . '_' . $row['faculty_id']] = $row;
        }
    }

    // Officials / unique-slot payees
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
        $unique_payees = [];
    }

    $sit_time_order = ei_time_order_expr_hub('time_slot');
    $sit_st = db()->prepare(
        "SELECT time_slot, GROUP_CONCAT(DISTINCT dept_id) AS dept_ids
         FROM ei_slots
         WHERE exam_id = ? AND slot_date = ?
         GROUP BY time_slot
         ORDER BY {$sit_time_order} ASC, time_slot ASC"
    );
    $sit_st->execute([$exam_id, $f_date]);
    foreach ($sit_st->fetchAll() as $sr) {
        $sitting_depts[$sr['time_slot']] = array_map('intval', explode(',', (string)$sr['dept_ids']));
    }

    if ($unique_payees && $sitting_depts) {
        try {
            $u_att_st = db()->prepare(
                'SELECT faculty_id, time_slot, attended FROM ei_unique_slot_attendance WHERE exam_id = ? AND slot_date = ?'
            );
            $u_att_st->execute([$exam_id, $f_date]);
            foreach ($u_att_st->fetchAll() as $ur) {
                $u_att_map[$ur['faculty_id'] . '|' . $ur['time_slot']] = (int)$ur['attended'];
            }
        } catch (Throwable $e) {
            $u_att_map = [];
        }
    }
}

// Group invigilator rows by sitting for display
$sitting_groups = [];
foreach ($slots as $slot) {
    $sitting_groups[$slot['time_slot']][] = $slot;
}

// Progress counters
$inv_total = 0;
$inv_present = 0;
foreach ($slots as $slot) {
    foreach ([1, 2] as $pos) {
        $fac_id = (int)($slot['faculty' . $pos . '_id'] ?? 0);
        if (!$fac_id || empty($slot['fac' . $pos . '_name'])) continue;
        $inv_total++;
        $att_row = $attendance_map[$slot['id'] . '_' . $fac_id] ?? null;
        if ($att_row && (int)$att_row['attended'] === 1) $inv_present++;
    }
}

$off_total = 0;
$off_present = 0;
foreach ($unique_payees as $p) {
    foreach ($sitting_depts as $sit_ts => $sit_dept_ids) {
        if ($p['dept_type'] === 'office' || in_array((int)$p['dept_id'], $sit_dept_ids, true)) {
            $off_total++;
            if (($u_att_map[$p['id'] . '|' . $sit_ts] ?? 1) === 1) $off_present++;
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/exam-invigilation/index.php">Exam Invigilation</a></li>
            <li class="breadcrumb-item active">Attendance</li>
        </ol>
    </nav>
    <?php if ($exam_id): ?>
    <a href="<?= APP_URL ?>/exam-invigilation/remuneration-bill.php?id=<?= $exam_id ?>"
       class="btn btn-outline-success btn-sm" style="border-radius:10px;">
        <i class="fas fa-file-invoice-dollar me-1"></i> Remuneration Bill
    </a>
    <?php endif; ?>
</div>

<?php flash_show(); ?>
<?php require __DIR__ . '/ei-nav.php'; ?>

<?php if (empty($exams)): ?>
<div class="card">
    <div class="card-body text-center text-muted py-5">
        <i class="fas fa-calendar-plus fa-2x mb-3 d-block"></i>
        <p class="mb-2">No exams yet. Create an exam first, then come back to mark attendance.</p>
        <a href="<?= APP_URL ?>/exam-invigilation/create.php" class="btn btn-primary btn-sm" style="border-radius:10px;">
            <i class="fas fa-plus me-1"></i> Create Exam
        </a>
    </div>
</div>
<?php else: ?>

<!-- Step 1 & 2: exam + date pickers -->
<div class="card mb-3">
    <div class="card-body py-3 px-3">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-auto d-flex align-items-center gap-2">
                <span class="badge rounded-pill bg-primary">1</span>
                <label class="form-label mb-0 fw-medium">Exam</label>
            </div>
            <div class="col-12 col-md-5">
                <select name="exam_id" class="form-select form-select-sm" style="border-radius:8px;" onchange="this.form.submit()">
                    <?php foreach ($exams as $ex): ?>
                    <option value="<?= (int)$ex['id'] ?>" <?= $exam_id === (int)$ex['id'] ? 'selected' : '' ?>>
                        <?= h($ex['exam_name']) ?> (<?= h($ex['exam_year']) ?>)<?= !(int)$ex['is_active'] ? ' — inactive' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <noscript><div class="col-auto"><button class="btn btn-sm btn-primary" style="border-radius:8px;">Go</button></div></noscript>
        </form>

        <?php if ($exam_id && !empty($available_dates)): ?>
        <hr class="my-3">
        <div class="d-flex align-items-start gap-2 flex-wrap">
            <span class="badge rounded-pill bg-primary mt-1">2</span>
            <label class="form-label mb-0 fw-medium mt-1 me-1">Date</label>
            <div class="d-flex flex-wrap gap-1">
                <?php $today = date('Y-m-d'); ?>
                <?php foreach ($available_dates as $dt): ?>
                <a href="?exam_id=<?= $exam_id ?>&slot_date=<?= urlencode($dt) ?>"
                   class="btn btn-sm <?= $f_date === $dt ? 'btn-primary' : 'btn-outline-secondary' ?>"
                   style="border-radius:20px;font-size:.8rem;">
                    <?= date('D, d M', strtotime($dt)) ?><?= $dt === $today ? ' · Today' : '' ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($exam_id && empty($available_dates)): ?>
<div class="alert alert-warning">
    This exam has no slots yet.
    <a href="<?= APP_URL ?>/exam-invigilation/slot-create.php?exam_id=<?= $exam_id ?>">Add slots</a> first.
</div>
<?php elseif ($exam_id && $f_date !== ''): ?>

<!-- Progress summary -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card text-center py-2" style="border-left:4px solid #4f8ef7;">
            <div style="font-size:1.4rem;font-weight:700;color:#4f8ef7;" id="stat-inv"><?= $inv_present ?> / <?= $inv_total ?></div>
            <div class="text-muted" style="font-size:.78rem;">Invigilators Present</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center py-2" style="border-left:4px solid #f39c12;">
            <div style="font-size:1.4rem;font-weight:700;color:#f39c12;" id="stat-off"><?= $off_present ?> / <?= $off_total ?></div>
            <div class="text-muted" style="font-size:.78rem;">Officials Present (per sitting)</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center py-2" style="border-left:4px solid #27ae60;">
            <div style="font-size:1.4rem;font-weight:700;color:#27ae60;"><?= count($sitting_groups) ?></div>
            <div class="text-muted" style="font-size:.78rem;">Sittings on <?= date('d M', strtotime($f_date)) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center py-2" style="border-left:4px solid #8e44ad;">
            <div style="font-size:1.4rem;font-weight:700;color:#8e44ad;"><?= count($slots) ?></div>
            <div class="text-muted" style="font-size:.78rem;">Rooms / Slots</div>
        </div>
    </div>
</div>

<?php if (empty($slots) && $off_total === 0): ?>
<div class="alert alert-info">Nothing to mark for this date — no assigned invigilators and no eligible officials.</div>
<?php else: ?>

<form method="POST" id="hub-form">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" value="save_attendance">
    <input type="hidden" name="exam_id" value="<?= $exam_id ?>">
    <input type="hidden" name="slot_date" value="<?= h($f_date) ?>">

    <!-- Section A: Invigilators, grouped by sitting -->
    <div class="card mb-4">
        <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <h6 class="mb-0 fw-semibold">
                <i class="fas fa-chalkboard-teacher me-2 text-muted"></i>
                Invigilators — <?= date('l, d F Y', strtotime($f_date)) ?>
            </h6>
            <span class="text-muted" style="font-size:.8rem;">Checked = present = paid per slot.</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th class="px-3" style="width:40px;">#</th>
                            <th style="width:90px;">Room</th>
                            <th>Faculty</th>
                            <th>Designation</th>
                            <th style="width:100px;">Rate (৳)</th>
                            <th class="text-center" style="width:100px;">Present</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php $row_no = 0; $sit_idx = 0; ?>
                    <?php foreach ($sitting_groups as $sit_ts => $sit_slots): $sit_idx++; ?>
                        <tr class="table-secondary">
                            <td colspan="7" class="px-3 py-2">
                                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                    <span class="fw-semibold"><i class="far fa-clock me-1"></i><?= h($sit_ts) ?></span>
                                    <button type="button" class="btn btn-sm btn-outline-success mark-all-btn"
                                            data-sitting="sit<?= $sit_idx ?>" style="border-radius:8px;font-size:.75rem;">
                                        <i class="fas fa-check-double me-1"></i> Mark all present
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php foreach ($sit_slots as $slot): ?>
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
                                        <input class="form-check-input inv-toggle sit<?= $sit_idx ?>" type="checkbox"
                                               name="attended[<?= h($key) ?>]" value="1"
                                               <?= $attended ? 'checked' : '' ?> style="cursor:pointer;">
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
                    <?php endforeach; ?>
                    <?php if ($row_no === 0): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No assigned invigilators for this date.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Section B: Officials / unique-slot payees -->
    <?php if (!empty($unique_payees) && !empty($sitting_depts)): ?>
    <div class="card mb-4">
        <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <h6 class="mb-0 fw-semibold">
                <i class="fas fa-user-clock me-2 text-muted"></i>
                Officials (Unique-Slot Payees) — per sitting
            </h6>
            <span class="text-muted" style="font-size:.8rem;">
                Paid per sitting (date + time). Unchecked = absent = not paid. Includes payees inactive for invigilation.
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
                                <a href="<?= APP_URL ?>/exam-invigilation/faculty-attendance.php?id=<?= (int)$p['id'] ?>&exam_id=<?= $exam_id ?>"
                                   class="fw-medium text-decoration-none" title="Open per-payee attendance for the whole exam">
                                    <?= h($p['name']) ?>
                                </a>
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
                                    <input class="form-check-input off-toggle" type="checkbox"
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
    </div>
    <?php endif; ?>

    <!-- Sticky save bar -->
    <div style="position:sticky;bottom:12px;z-index:100;">
        <div class="card shadow">
            <div class="card-body py-2 px-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
                <span class="text-muted" style="font-size:.85rem;">
                    <i class="fas fa-info-circle me-1"></i>
                    Saves invigilators <?= (!empty($unique_payees) && !empty($sitting_depts)) ? 'and officials ' : '' ?>for <?= date('d M Y', strtotime($f_date)) ?> in one go.
                </span>
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Save All Attendance
                </button>
            </div>
        </div>
    </div>
</form>

<script>
(function () {
    var invTotal = <?= (int)$inv_total ?>;
    var offTotal = <?= (int)$off_total ?>;

    function refreshStats() {
        var invPresent = document.querySelectorAll('.inv-toggle:checked').length;
        var offPresent = document.querySelectorAll('.off-toggle:checked').length;
        var invEl = document.getElementById('stat-inv');
        var offEl = document.getElementById('stat-off');
        if (invEl) invEl.textContent = invPresent + ' / ' + invTotal;
        if (offEl) offEl.textContent = offPresent + ' / ' + offTotal;
    }

    document.querySelectorAll('.inv-toggle').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var tr = this.closest('tr');
            if (tr) tr.classList.toggle('table-success', this.checked);
            refreshStats();
        });
    });

    document.querySelectorAll('.off-toggle').forEach(function (cb) {
        cb.addEventListener('change', refreshStats);
    });

    document.querySelectorAll('.mark-all-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var cls = this.getAttribute('data-sitting');
            var boxes = document.querySelectorAll('.inv-toggle.' + cls);
            var allChecked = Array.prototype.every.call(boxes, function (b) { return b.checked; });
            boxes.forEach(function (b) {
                b.checked = !allChecked;
                var tr = b.closest('tr');
                if (tr) tr.classList.toggle('table-success', b.checked);
            });
            refreshStats();
        });
    });
})();
</script>

<?php endif; /* slots/officials exist */ ?>
<?php endif; /* exam + date selected */ ?>
<?php endif; /* exams exist */ ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
