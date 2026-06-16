<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/slot-helpers.php';
require_access('exam-invigilation');

$id = (int)($_GET['id'] ?? 0);
$exam_st = db()->prepare('SELECT * FROM ei_exams WHERE id = ?');
$exam_st->execute([$id]);
$exam = $exam_st->fetch();
if (!$exam) {
    flash_set('error', 'Exam not found.');
    redirect(APP_URL . '/exam-invigilation/index.php');
}

$page_title = h($exam['exam_name']) . ' – Invigilation';
function ei_time_order_expr(string $column = 'time_slot'): string
{
    $allowed = ['time_slot', 's.time_slot'];
    if (!in_array($column, $allowed, true)) {
        $column = 'time_slot';
    }

    return "COALESCE(
        STR_TO_DATE(TRIM(SUBSTRING_INDEX(REPLACE({$column}, '-', '–'), '–', 1)), '%h:%i %p'),
        STR_TO_DATE(TRIM(SUBSTRING_INDEX(REPLACE({$column}, '-', '–'), '–', 1)), '%H:%i')
    )";
}

$time_order_sql = ei_time_order_expr('time_slot');

$f_date       = trim((string)($_GET['slot_date'] ?? ''));
$f_dept       = (int)($_GET['dept'] ?? 0);
$f_room       = trim((string)($_GET['room'] ?? ''));
$f_time_slot  = trim((string)($_GET['time_slot'] ?? ''));
$f_invigilator = (int)($_GET['invigilator'] ?? 0);
$print_mode   = isset($_GET['print']) && $_GET['print'] === '1';

if ($f_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date)) {
    $f_date = '';
}

$filter_query = [];
if ($f_date !== '') $filter_query['slot_date'] = $f_date;
if ($f_dept > 0) $filter_query['dept'] = $f_dept;
if ($f_room !== '') $filter_query['room'] = $f_room;
if ($f_time_slot !== '') $filter_query['time_slot'] = $f_time_slot;
if ($f_invigilator > 0) $filter_query['invigilator'] = $f_invigilator;

$view_url  = APP_URL . '/exam-invigilation/view.php?' . http_build_query(array_merge(['id' => $id], $filter_query));
$print_url = APP_URL . '/exam-invigilation/view.php?' . http_build_query(array_merge(['id' => $id], $filter_query, ['print' => 1]));
$auto_assign_max_slots         = ei_get_auto_assign_max_slots();
$auto_assign_max_slots_per_day = ei_get_auto_assign_max_slots_per_day();

// ── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    csrf_check();

    // ── Delete slot ──
    if ($_POST['_action'] === 'delete_slot') {
        require_access('exam-invigilation', 'can_delete');
        $sid = (int)($_POST['slot_id'] ?? 0);
        db()->prepare('DELETE FROM ei_slots WHERE id = ? AND exam_id = ?')->execute([$sid, $id]);
        flash_set('success', 'Slot deleted.');
        redirect($view_url);
    }

    // ── Clear assignment for one slot ──
    if ($_POST['_action'] === 'clear_slot') {
        require_access('exam-invigilation', 'can_edit');
        $sid = (int)($_POST['slot_id'] ?? 0);
        db()->prepare('UPDATE ei_slots SET faculty1_id=NULL, faculty2_id=NULL WHERE id = ? AND exam_id = ?')
           ->execute([$sid, $id]);
        flash_set('success', 'Assignment cleared.');
        redirect($view_url);
    }

    // ── Auto-assign faculty ───────────────────────────────────────────────────
    if ($_POST['_action'] === 'auto_assign') {
        require_access('exam-invigilation', 'can_edit');

        $scope = isset($_POST['reassign']) ? 'all' : 'unassigned';

        // Fetch all slots for this exam (optionally only unassigned)
        if ($scope === 'all') {
            $slots_st = db()->prepare(
                "SELECT * FROM ei_slots
                 WHERE exam_id = ?
                 ORDER BY slot_date ASC, {$time_order_sql} ASC, time_slot ASC, room_number ASC"
            );
        } else {
            $slots_st = db()->prepare(
                "SELECT * FROM ei_slots WHERE exam_id = ?
                 AND (faculty1_id IS NULL OR faculty2_id IS NULL)
                 ORDER BY slot_date ASC, {$time_order_sql} ASC, time_slot ASC, room_number ASC"
            );
        }
        $slots_st->execute([$id]);
        $slots = $slots_st->fetchAll();

        if (empty($slots)) {
            flash_set('info', 'No slots to assign.');
            redirect($view_url);
        }

        // Load all active faculty grouped by dept
        $fac_st = db()->query(
            'SELECT f.*, d.name AS dept_name
             FROM ei_faculty f
             JOIN dept_departments d ON d.id = f.dept_id
             WHERE f.is_active = 1
             ORDER BY f.dept_id ASC, f.name ASC'
        );
        $all_faculty = $fac_st->fetchAll();

        if (empty($all_faculty)) {
            flash_set('error', 'No active faculty in the pool. Please add faculty first.');
            redirect($view_url);
        }

        $faculty_weekend_map = [];
        foreach ($all_faculty as $f) {
            $faculty_weekend_map[(int)$f['id']] = ei_get_faculty_weekend_days($f);
        }

        // Build a map: date+time_slot → array of already-assigned faculty_ids
        // (this includes OTHER exams too, to avoid cross-exam conflicts)
        $busy_st = db()->query(
            "SELECT slot_date, time_slot, faculty1_id, faculty2_id
             FROM ei_slots
             WHERE faculty1_id IS NOT NULL OR faculty2_id IS NOT NULL"
        );
        $busy_map = [];
        foreach ($busy_st->fetchAll() as $r) {
            $key = $r['slot_date'] . '|' . $r['time_slot'];
            if ($r['faculty1_id']) $busy_map[$key][(int)$r['faculty1_id']] = true;
            if ($r['faculty2_id']) $busy_map[$key][(int)$r['faculty2_id']] = true;
        }

        // For "reassign all" mode: first clear all assignments for this exam
        // so they don't block themselves during this run
        if ($scope === 'all') {
            db()->prepare('UPDATE ei_slots SET faculty1_id=NULL, faculty2_id=NULL WHERE exam_id=?')->execute([$id]);
            // Re-build busy_map without this exam's slots
            $busy_st = db()->query(
                "SELECT slot_date, time_slot, faculty1_id, faculty2_id
                 FROM ei_slots
                 WHERE (faculty1_id IS NOT NULL OR faculty2_id IS NOT NULL)"
            );
            $busy_map = [];
            foreach ($busy_st->fetchAll() as $r) {
                $key = $r['slot_date'] . '|' . $r['time_slot'];
                if ($r['faculty1_id']) $busy_map[$key][(int)$r['faculty1_id']] = true;
                if ($r['faculty2_id']) $busy_map[$key][(int)$r['faculty2_id']] = true;
            }
        }

        $assigned_count = 0;
        $partial_count  = 0;
        $failed_count   = 0;

        // Track how many times each faculty has been assigned in this run (for workload balancing)
        $workload = [];
        foreach ($all_faculty as $f) {
            $workload[(int)$f['id']] = 0;
        }

        // Track per-day assignments: [faculty_id][date] => count
        $daily_workload = [];

        $assignment_params = [];
        $assignment_scope_sql = '';
        if ($scope === 'all') {
            $assignment_scope_sql = ' AND s.exam_id != ?';
            $assignment_params = [$id, $id];
        }
        $assignment_count_st = db()->prepare(
            "SELECT faculty_id, COUNT(*) AS slot_count
             FROM (
                 SELECT s.faculty1_id AS faculty_id
                 FROM ei_slots s
                 JOIN ei_exams e ON e.id = s.exam_id
                 WHERE e.is_active = 1
                   AND s.faculty1_id IS NOT NULL{$assignment_scope_sql}
                 UNION ALL
                 SELECT s.faculty2_id AS faculty_id
                 FROM ei_slots s
                 JOIN ei_exams e ON e.id = s.exam_id
                 WHERE e.is_active = 1
                   AND s.faculty2_id IS NOT NULL{$assignment_scope_sql}
             ) assigned
             GROUP BY faculty_id"
        );
        $assignment_count_st->execute($assignment_params);
        foreach ($assignment_count_st->fetchAll() as $row) {
            $workload[(int)$row['faculty_id']] = (int)$row['slot_count'];
        }

        // Build daily workload map from existing assignments
        $daily_count_scope_sql = $assignment_scope_sql;
        $daily_count_st = db()->prepare(
            "SELECT faculty_id, slot_date, COUNT(*) AS c
             FROM (
                 SELECT s.faculty1_id AS faculty_id, s.slot_date
                 FROM ei_slots s
                 JOIN ei_exams e ON e.id = s.exam_id
                 WHERE e.is_active = 1
                   AND s.faculty1_id IS NOT NULL{$daily_count_scope_sql}
                 UNION ALL
                 SELECT s.faculty2_id, s.slot_date
                 FROM ei_slots s
                 JOIN ei_exams e ON e.id = s.exam_id
                 WHERE e.is_active = 1
                   AND s.faculty2_id IS NOT NULL{$daily_count_scope_sql}
             ) t
             GROUP BY faculty_id, slot_date"
        );
        $daily_count_st->execute($assignment_params);
        foreach ($daily_count_st->fetchAll() as $row) {
            $daily_workload[(int)$row['faculty_id']][(string)$row['slot_date']] = (int)$row['c'];
        }

        // Helper: sort a pool of faculty by workload (fewest assignments first), shuffle ties
        $sort_by_workload = static function (array $pool) use (&$workload): array {
            usort($pool, static function ($a, $b) use ($workload) {
                $wa = $workload[(int)$a['id']] ?? 0;
                $wb = $workload[(int)$b['id']] ?? 0;
                if ($wa !== $wb) return $wa <=> $wb;
                return random_int(0, 1) ? -1 : 1; // shuffle within same workload tier
            });
            return $pool;
        };

        foreach ($slots as $slot) {
            $slot_date       = $slot['slot_date'];
            $time_slot       = $slot['time_slot'];
            $key             = $slot_date . '|' . $time_slot;
            $slot_pref_dept  = isset($slot['dept_id']) ? (int)$slot['dept_id'] : 0;

            $day_of_week  = (int)date('w', strtotime($slot_date));

            $slot_starts_after_6pm = ei_slot_starts_after_6pm($time_slot);

            // Filter eligible faculty
            $eligible = [];
            foreach ($all_faculty as $f) {
                $faculty_weekend_days = $faculty_weekend_map[(int)$f['id']] ?? [];
                if (in_array($day_of_week, $faculty_weekend_days, true)) continue;
                // Skip if already busy in this date+time_slot
                if (isset($busy_map[$key][(int)$f['id']])) continue;
                // Skip if the faculty has already reached the configured total limit
                if (($workload[(int)$f['id']] ?? 0) >= $auto_assign_max_slots) continue;
                // Skip if the faculty has reached the per-day limit for this slot's date
                if (($daily_workload[(int)$f['id']][$slot_date] ?? 0) >= $auto_assign_max_slots_per_day) continue;
                // Female faculty are not assigned to slots starting at or after 6 PM
                if ($slot_starts_after_6pm && !empty($f['gender']) && $f['gender'] === 'Female') continue;
                $eligible[] = $f;
            }

            // Group by department
            $by_dept = [];
            foreach ($eligible as $f) {
                $by_dept[(int)$f['dept_id']][] = $f;
            }
            // Sort each department pool by workload
            foreach ($by_dept as $did => $pool) {
                $by_dept[$did] = $sort_by_workload($pool);
            }

            $f1 = null;
            $f2 = null;

            if ($slot_pref_dept > 0 && isset($by_dept[$slot_pref_dept])) {
                // Preferred dept set on the slot: f1 from that dept (least-loaded), f2 from any other dept
                $f1 = $by_dept[$slot_pref_dept][0];
                // Pick f2 from the other dept with the globally least-loaded available person
                $other_eligible = [];
                foreach ($by_dept as $did => $pool) {
                    if ($did !== $slot_pref_dept) {
                        $other_eligible[] = $pool[0];
                    }
                }
                if (!empty($other_eligible)) {
                    $other_eligible = $sort_by_workload($other_eligible);
                    $f2 = $other_eligible[0];
                }
                // If no other dept available, fall back to same dept (second least-loaded person)
                if ($f2 === null && isset($by_dept[$slot_pref_dept][1])) {
                    $f2 = $by_dept[$slot_pref_dept][1];
                }
            } else {
                // No preferred dept: pick two least-loaded faculty from different depts if possible
                // Build a single sorted list of all eligible, then pick two from different depts
                $all_eligible_sorted = $sort_by_workload($eligible);
                foreach ($all_eligible_sorted as $candidate) {
                    if ($f1 === null) {
                        $f1 = $candidate;
                    } elseif ((int)$candidate['dept_id'] !== (int)$f1['dept_id']) {
                        $f2 = $candidate;
                        break;
                    }
                }
                // If we couldn't find two from different depts, take same dept
                if ($f1 !== null && $f2 === null) {
                    foreach ($all_eligible_sorted as $candidate) {
                        if ((int)$candidate['id'] !== (int)$f1['id']) {
                            $f2 = $candidate;
                            break;
                        }
                    }
                }
            }

            if ($f1 === null && $f2 === null) {
                $failed_count++;
                continue;
            }

            // Update the slot
            db()->prepare(
                'UPDATE ei_slots SET faculty1_id=?, faculty2_id=? WHERE id=?'
            )->execute([
                $f1 ? (int)$f1['id'] : null,
                $f2 ? (int)$f2['id'] : null,
                (int)$slot['id'],
            ]);

            // Mark these faculty as busy for subsequent slots in the same run
            if ($f1) {
                $busy_map[$key][(int)$f1['id']] = true;
                $workload[(int)$f1['id']] = ($workload[(int)$f1['id']] ?? 0) + 1;
                $daily_workload[(int)$f1['id']][$slot_date] = ($daily_workload[(int)$f1['id']][$slot_date] ?? 0) + 1;
            }
            if ($f2) {
                $busy_map[$key][(int)$f2['id']] = true;
                $workload[(int)$f2['id']] = ($workload[(int)$f2['id']] ?? 0) + 1;
                $daily_workload[(int)$f2['id']][$slot_date] = ($daily_workload[(int)$f2['id']][$slot_date] ?? 0) + 1;
            }

            if ($f1 && $f2) {
                $assigned_count++;
            } else {
                $partial_count++;
            }
        }

        $msg = "Auto-assign complete: {$assigned_count} slot(s) fully assigned";
        if ($partial_count > 0) $msg .= ", {$partial_count} partially assigned";
        if ($failed_count  > 0) $msg .= ", {$failed_count} could not be assigned (insufficient eligible faculty within the current availability rules, {$auto_assign_max_slots}-slot total cap, and {$auto_assign_max_slots_per_day}-slot daily cap)";
        $msg .= '.';
        flash_set($failed_count > 0 ? 'warning' : 'success', $msg);
        redirect($view_url);
    }

    redirect($view_url);
}

// ── Filter option data ────────────────────────────────────────────────────────
$departments = db()->query('SELECT id, name FROM dept_departments WHERE is_active=1 ORDER BY name ASC')->fetchAll();
$time_slots_st = db()->prepare('SELECT DISTINCT time_slot FROM ei_slots WHERE exam_id = ? ORDER BY ' . $time_order_sql . ' ASC, time_slot ASC');
$time_slots_st->execute([$id]);
$time_slots = array_values(array_filter(array_map(static fn ($r) => (string)$r['time_slot'], $time_slots_st->fetchAll()), static fn ($v) => trim($v) !== ''));
$invigilators = db()->query('SELECT id, name, designation FROM ei_faculty WHERE is_active = 1 ORDER BY name ASC')->fetchAll();

// ── Load slots ────────────────────────────────────────────────────────────────
$where = ['s.exam_id = ?'];
$params = [$id];
if ($f_date !== '') {
    $where[] = 's.slot_date = ?';
    $params[] = $f_date;
}
if ($f_dept > 0) {
    $where[] = '(s.dept_id = ? OR f1.dept_id = ? OR f2.dept_id = ?)';
    $params[] = $f_dept;
    $params[] = $f_dept;
    $params[] = $f_dept;
}
if ($f_room !== '') {
    $where[] = 's.room_number LIKE ?';
    $params[] = '%' . $f_room . '%';
}
if ($f_time_slot !== '') {
    $where[] = 's.time_slot = ?';
    $params[] = $f_time_slot;
}
if ($f_invigilator > 0) {
    $where[] = '(s.faculty1_id = ? OR s.faculty2_id = ?)';
    $params[] = $f_invigilator;
    $params[] = $f_invigilator;
}
$sql_where = 'WHERE ' . implode(' AND ', $where);

$slots_st = db()->prepare(
    "SELECT s.*,
            f1.name AS f1_name, f1.designation AS f1_desig, d1.name AS f1_dept,
            f2.name AS f2_name, f2.designation AS f2_desig, d2.name AS f2_dept,
            dp.name AS pref_dept_name
     FROM ei_slots s
     LEFT JOIN ei_faculty f1 ON f1.id = s.faculty1_id
     LEFT JOIN dept_departments d1 ON d1.id = f1.dept_id
     LEFT JOIN ei_faculty f2 ON f2.id = s.faculty2_id
     LEFT JOIN dept_departments d2 ON d2.id = f2.dept_id
     LEFT JOIN dept_departments dp ON dp.id = s.dept_id
     $sql_where
     ORDER BY s.slot_date ASC,
              " . ei_time_order_expr('s.time_slot') . " ASC,
              s.time_slot ASC, s.room_number ASC"
);
$slots_st->execute($params);
$slots = $slots_st->fetchAll();

$total_slots    = count($slots);
$assigned_slots = 0;
$partial_slots  = 0;
foreach ($slots as $s) {
    if ($s['faculty1_id'] && $s['faculty2_id']) $assigned_slots++;
    elseif ($s['faculty1_id'] || $s['faculty2_id']) $partial_slots++;
}
$unassigned_slots = $total_slots - $assigned_slots - $partial_slots;

// Group slots by date for display
$by_date = [];
foreach ($slots as $s) {
    $by_date[$s['slot_date']][] = $s;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/exam-invigilation/index.php">Exam Invigilation</a></li>
            <li class="breadcrumb-item active"><?= h($exam['exam_name']) ?></li>
        </ol>
    </nav>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (!$print_mode): ?>
        <a href="<?= h($print_url) ?>" target="_blank" class="btn btn-outline-dark btn-sm" style="border-radius:10px;">
            <i class="fas fa-file-pdf me-1"></i> A4 PDF Print
        </a>
        <a href="<?= APP_URL ?>/exam-invigilation/attendance.php?id=<?= $id ?>"
           class="btn btn-outline-info btn-sm" style="border-radius:10px;">
            <i class="fas fa-calendar-check me-1"></i> Attendance
        </a>
        <a href="<?= APP_URL ?>/exam-invigilation/remuneration-bill.php?id=<?= $id ?>"
           class="btn btn-outline-success btn-sm" style="border-radius:10px;">
            <i class="fas fa-file-invoice-dollar me-1"></i> Bill
        </a>
        <?php else: ?>
        <a href="<?= h($view_url) ?>" class="btn btn-outline-secondary btn-sm" style="border-radius:10px;">
            <i class="fas fa-arrow-left me-1"></i> Back to Interactive View
        </a>
        <?php endif; ?>
        <?php if (!$print_mode): ?>
        <?php if (is_super_admin() || can_access('exam-invigilation', 'can_create')): ?>
        <a href="<?= APP_URL ?>/exam-invigilation/slot-create.php?exam_id=<?= $id ?>#csv-upload"
           class="btn btn-outline-primary btn-sm" style="border-radius:10px;">
            <i class="fas fa-file-csv me-1"></i> Bulk Upload CSV
        </a>
        <a href="<?= APP_URL ?>/exam-invigilation/slot-create.php?exam_id=<?= $id ?>"
           class="btn btn-success btn-sm" style="border-radius:10px;">
            <i class="fas fa-plus me-1"></i> Add Slot
        </a>
        <?php endif; ?>
        <?php if (is_super_admin() || can_access('exam-invigilation', 'can_edit')): ?>
        <a href="<?= APP_URL ?>/exam-invigilation/edit.php?id=<?= $id ?>"
           class="btn btn-outline-primary btn-sm" style="border-radius:10px;">
            <i class="fas fa-edit me-1"></i> Edit Exam
        </a>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php flash_show(); ?>

<?php if ($print_mode): ?>
<style>
@media print {
    @page { size: A4; margin: 10mm; }
    .navbar, .breadcrumb, .btn, form, .card-footer, .no-print, .main-sidebar, .main-header, footer { display: none !important; }
    .content-wrapper, .content { margin: 0 !important; padding: 0 !important; }
    .card { border: 0 !important; box-shadow: none !important; }
}
</style>
<?php endif; ?>

<div class="card mb-3 no-print">
    <div class="card-body py-2 px-3">
        <form method="GET" class="row g-2 align-items-center">
            <input type="hidden" name="id" value="<?= $id ?>">
            <div class="col-12 col-md-2">
                <input type="date" name="slot_date" class="form-control form-control-sm" value="<?= h($f_date) ?>">
            </div>
            <div class="col-12 col-md-2">
                <select name="dept" class="form-select form-select-sm">
                    <option value="0">All Departments</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $f_dept === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <input type="text" name="room" class="form-control form-control-sm" placeholder="Room" value="<?= h($f_room) ?>">
            </div>
            <div class="col-12 col-md-2">
                <select name="time_slot" class="form-select form-select-sm">
                    <option value="">All Time Slots</option>
                    <?php foreach ($time_slots as $ts): ?>
                    <option value="<?= h($ts) ?>" <?= $f_time_slot === $ts ? 'selected' : '' ?>><?= h($ts) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <select name="invigilator" class="form-select form-select-sm">
                    <option value="0">All Invigilators</option>
                    <?php foreach ($invigilators as $inv): ?>
                    <option value="<?= $inv['id'] ?>" <?= $f_invigilator === (int)$inv['id'] ? 'selected' : '' ?>>
                        <?= h($inv['name']) ?><?= $inv['designation'] ? ' (' . h($inv['designation']) . ')' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto d-flex gap-2">
                <button class="btn btn-sm btn-primary">Filter</button>
                <a href="<?= APP_URL ?>/exam-invigilation/view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Exam summary card -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card text-center py-3">
            <div style="font-size:1.8rem;font-weight:700;color:#4f8ef7;"><?= $total_slots ?></div>
            <div class="text-muted" style="font-size:.8rem;">Total Slots</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center py-3">
            <div style="font-size:1.8rem;font-weight:700;color:#27ae60;"><?= $assigned_slots ?></div>
            <div class="text-muted" style="font-size:.8rem;">Fully Assigned</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center py-3">
            <div style="font-size:1.8rem;font-weight:700;color:#f39c12;"><?= $partial_slots ?></div>
            <div class="text-muted" style="font-size:.8rem;">Partially Assigned</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center py-3">
            <div style="font-size:1.8rem;font-weight:700;color:#e74c3c;"><?= $unassigned_slots ?></div>
            <div class="text-muted" style="font-size:.8rem;">Unassigned</div>
        </div>
    </div>
</div>

<!-- Auto-assign panel -->
<?php if (!$print_mode && (is_super_admin() || can_access('exam-invigilation', 'can_edit'))): ?>
<div class="card mb-4" style="border-left:4px solid #4f8ef7;">
    <div class="card-body py-3 px-4">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
                <h6 class="mb-1 fw-semibold"><i class="fas fa-magic me-2 text-primary"></i>Auto-Assign Invigilators</h6>
                <p class="mb-0 text-muted" style="font-size:.85rem;">
                    Automatically assigns 2 faculty per room slot — one from each department — without overlapping time slots and without crossing the current <?= $auto_assign_max_slots ?>-slot per teacher limit.
                </p>
            </div>
            <form method="POST" class="d-flex gap-2 align-items-center">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="auto_assign">
                <div class="form-check me-2">
                    <input class="form-check-input" type="checkbox" name="reassign" id="reassignAll" value="1">
                    <label class="form-check-label" for="reassignAll" style="font-size:.85rem;">Re-assign all (clear existing)</label>
                </div>
                <button type="submit" class="btn btn-primary btn-sm" style="border-radius:10px;"
                        onclick="return confirm('Run auto-assignment? This will fill unassigned slots (or all slots if \'Re-assign all\' is checked).');">
                    <i class="fas fa-magic me-1"></i> Auto-Assign
                </button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Slots by date -->
<?php if (empty($slots)): ?>
<div class="card">
    <div class="card-body text-center text-muted py-5">
        <i class="fas fa-calendar-times fa-3x mb-3 d-block" style="opacity:.2;"></i>
        No slots added yet.
        <?php if (is_super_admin() || can_access('exam-invigilation', 'can_create')): ?>
        <div class="mt-2">
            <a href="<?= APP_URL ?>/exam-invigilation/slot-create.php?exam_id=<?= $id ?>" class="btn btn-sm btn-primary" style="border-radius:10px;">
                <i class="fas fa-plus me-1"></i> Add First Slot
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<?php foreach ($by_date as $date => $date_slots): ?>
<div class="card mb-3">
    <div class="card-header py-2 px-4 d-flex align-items-center justify-content-between"
         style="background:#f8f9ff;border-bottom:1px solid #e8ecf5;">
        <span class="fw-semibold" style="font-size:.9rem;">
            <i class="fas fa-calendar-day me-2 text-primary"></i>
            <?= date('l, d F Y', strtotime($date)) ?>
        </span>
        <span class="badge bg-secondary bg-opacity-15 text-secondary"><?= count($date_slots) ?> room(s)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">Room</th>
                        <th>Time Slot</th>
                        <th>Invigilator 1</th>
                        <th>Invigilator 2</th>
                        <th>Status</th>
                        <?php if (!$print_mode && (is_super_admin() || can_access('exam-invigilation', 'can_edit') || can_access('exam-invigilation', 'can_delete'))): ?>
                        <th class="text-end pe-4">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($date_slots as $s): ?>
                <?php
                $fully_assigned  = $s['faculty1_id'] && $s['faculty2_id'];
                $partly_assigned = ($s['faculty1_id'] || $s['faculty2_id']) && !$fully_assigned;
                ?>
                <tr class="<?= $fully_assigned ? '' : ($partly_assigned ? 'table-warning' : 'table-danger bg-opacity-10') ?>">
                    <td class="px-4 fw-medium">
                        <?= h($s['room_number']) ?>
                        <?php if (!empty($s['pref_dept_name'])): ?>
                        <div><small class="text-muted" title="Preferred dept for Invigilator 1">
                            <i class="fas fa-tag me-1"></i><?= h($s['pref_dept_name']) ?>
                        </small></div>
                        <?php endif; ?>
                    </td>
                    <td><?= h($s['time_slot']) ?></td>
                    <td>
                        <?php if ($s['f1_name']): ?>
                        <div class="fw-medium"><?= h($s['f1_name']) ?></div>
                        <?php if ($s['f1_desig']): ?><small class="text-muted"><?= h($s['f1_desig']) ?></small><?php endif; ?>
                        <?php if ($s['f1_dept']): ?><small class="text-primary d-block"><?= h($s['f1_dept']) ?></small><?php endif; ?>
                        <?php else: ?>
                        <span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>Not assigned</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($s['f2_name']): ?>
                        <div class="fw-medium"><?= h($s['f2_name']) ?></div>
                        <?php if ($s['f2_desig']): ?><small class="text-muted"><?= h($s['f2_desig']) ?></small><?php endif; ?>
                        <?php if ($s['f2_dept']): ?><small class="text-primary d-block"><?= h($s['f2_dept']) ?></small><?php endif; ?>
                        <?php else: ?>
                        <span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>Not assigned</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($fully_assigned): ?>
                        <span class="badge bg-success">Assigned</span>
                        <?php elseif ($partly_assigned): ?>
                        <span class="badge bg-warning text-dark">Partial</span>
                        <?php else: ?>
                        <span class="badge bg-danger">Unassigned</span>
                        <?php endif; ?>
                    </td>
                    <?php if (!$print_mode && (is_super_admin() || can_access('exam-invigilation', 'can_edit') || can_access('exam-invigilation', 'can_delete'))): ?>
                    <td class="text-end pe-4">
                        <div class="d-flex gap-1 justify-content-end">
                            <?php if (is_super_admin() || can_access('exam-invigilation', 'can_edit')): ?>
                            <a href="<?= APP_URL ?>/exam-invigilation/slot-edit.php?id=<?= $s['id'] ?>&exam_id=<?= $id ?>"
                               class="btn btn-sm btn-outline-primary" style="border-radius:7px;" title="Edit Slot">
                                <i class="fas fa-edit"></i>
                            </a>
                            <?php if ($s['faculty1_id'] || $s['faculty2_id']): ?>
                            <form method="POST" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="clear_slot">
                                <input type="hidden" name="slot_id" value="<?= $s['id'] ?>">
                                <button class="btn btn-sm btn-outline-warning" style="border-radius:7px;" title="Clear Assignment"
                                        onclick="return confirm('Clear assignment for Room <?= h(addslashes($s['room_number'])) ?>?');">
                                    <i class="fas fa-user-times"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php endif; ?>
                            <?php if (is_super_admin() || can_access('exam-invigilation', 'can_delete')): ?>
                            <form method="POST" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="delete_slot">
                                <input type="hidden" name="slot_id" value="<?= $s['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" style="border-radius:7px;" title="Delete Slot"
                                        onclick="return confirm('Delete this slot (Room <?= h(addslashes($s['room_number'])) ?>)?');">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
