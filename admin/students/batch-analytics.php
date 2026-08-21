<?php
/**
 * Batch Analytics – Admission & Exam Attendance Report
 *
 * Department / Program / Batch-wise totals of admitted students (all
 * statuses: Active, Inactive, Graduated, Dropped, Not Admitted Yet),
 * how many attended a given exam as of today (based on an ACTIVE admit
 * card created for them), how many did not attend, and the percentages.
 * An exam filter narrows attendance to a specific exam name.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('students');
require_once __DIR__ . '/helpers.php';

$page_title = 'Batch Analytics';

// ── Department scope (null = unrestricted; int[] = allowed dept ids) ─────────
$dept_scope = get_dept_scope();

// ── Filters ───────────────────────────────────────────────────────────────────
$f_dept    = (int)($_GET['dept']    ?? 0);
$f_program = (int)($_GET['program'] ?? 0);
$f_batch   = (int)($_GET['batch']   ?? 0);
$f_sem     = trim($_GET['semester'] ?? '');
$f_exam    = trim($_GET['exam']     ?? '');
$group     = $_GET['group'] ?? 'dept';
if (!in_array($group, ['dept', 'program', 'batch'], true)) {
    $group = 'dept';
}

$where  = [];
$params = [];

if ($f_dept > 0) {
    $where[]  = 's.dept_id = ?';
    $params[] = $f_dept;
}
if ($f_program > 0) {
    $where[]  = 's.program_id = ?';
    $params[] = $f_program;
}
if ($f_batch > 0) {
    // Same rule as the student list: include students transferred into the batch
    $where[]  = '(s.batch_id = ? OR s.id IN (SELECT sbt.student_id FROM student_batch_transfers sbt WHERE sbt.to_batch_id = ? AND sbt.is_active = 1))';
    $params[] = $f_batch;
    $params[] = $f_batch;
}
if ($f_sem !== '') {
    $where[]  = 's.admitted_semester = ?';
    $params[] = $f_sem;
}

// Apply department scope restriction for non-super-admins
if ($dept_scope !== null) {
    if (empty($dept_scope)) {
        $where[] = '0 = 1';
    } else {
        $phs     = implode(',', array_fill(0, count($dept_scope), '?'));
        $where[] = "s.dept_id IN ($phs)";
        array_push($params, ...$dept_scope);
    }
}

$where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

// ── Exam attendance (distinct students with an ACTIVE admit card) ────────────
// A student "attended the exam" as of today when an active admit card was
// created covering them via: (a) a registered course-offer subject linked to
// the card, (b) dept + program (+ batch) fallback for manual/bulk cards
// without subject links, or (c) an admin override. When an exam is selected
// in the filter, only admit cards of that exam name are counted. Optional
// tables/columns are feature-probed exactly like admin/admit-card/index.php.
$has_cards       = false;
$has_subject_col = false;
$has_overrides   = false;
try { db()->query('SELECT 1 FROM ac_admit_cards LIMIT 1'); $has_cards = true; } catch (Throwable $e) {}
if ($has_cards) {
    try { db()->query('SELECT offer_subject_id FROM ac_admit_card_courses LIMIT 1'); $has_subject_col = true; } catch (Throwable $e) {}
    try { db()->query('SELECT 1 FROM ac_student_overrides LIMIT 1'); $has_overrides = true; } catch (Throwable $e) {}
}

$card_union  = [];
$card_params = [];
if ($has_cards) {
    $exam_cond1 = $f_exam !== '' ? ' AND ac.exam_name = ?'  : '';
    $exam_cond2 = $f_exam !== '' ? ' AND ac2.exam_name = ?' : '';
    $exam_cond3 = $f_exam !== '' ? ' AND ac3.exam_name = ?' : '';

    if ($has_subject_col) {
        $card_union[] = 'SELECT r.student_id AS student_id
                           FROM ac_admit_card_courses cc
                           JOIN co_registrations r ON r.offer_subject_id = cc.offer_subject_id
                           JOIN ac_admit_cards ac ON ac.id = cc.admit_card_id
                          WHERE ac.is_active = 1' . $exam_cond1;
        if ($f_exam !== '') $card_params[] = $f_exam;

        $card_union[] = 'SELECT s2.id AS student_id
                           FROM students s2
                           JOIN ac_admit_cards ac2
                             ON ac2.dept_id = s2.dept_id
                            AND ac2.program_id = s2.program_id
                            AND (ac2.batch_id IS NULL OR ac2.batch_id = s2.batch_id)
                          WHERE ac2.is_active = 1' . $exam_cond2 . '
                            AND NOT EXISTS (SELECT 1 FROM ac_admit_card_courses cx
                                             WHERE cx.admit_card_id = ac2.id
                                               AND cx.offer_subject_id IS NOT NULL)';
        if ($f_exam !== '') $card_params[] = $f_exam;
    } else {
        $card_union[] = 'SELECT s2.id AS student_id
                           FROM students s2
                           JOIN ac_admit_cards ac2
                             ON ac2.dept_id = s2.dept_id
                            AND ac2.program_id = s2.program_id
                            AND (ac2.batch_id IS NULL OR ac2.batch_id = s2.batch_id)
                          WHERE ac2.is_active = 1' . $exam_cond2;
        if ($f_exam !== '') $card_params[] = $f_exam;
    }
    if ($has_overrides) {
        $card_union[] = 'SELECT o.student_id AS student_id
                           FROM ac_student_overrides o
                           JOIN ac_admit_cards ac3 ON ac3.id = o.admit_card_id
                          WHERE ac3.is_active = 1' . $exam_cond3;
        if ($f_exam !== '') $card_params[] = $f_exam;
    }
}

$card_join = '';
$card_expr = '0';
if ($card_union) {
    $card_join = ' LEFT JOIN (' . implode(' UNION ', $card_union) . ') acs ON acs.student_id = s.id';
    $card_expr = 'SUM(acs.student_id IS NOT NULL)';
}

// ── Grouping ──────────────────────────────────────────────────────────────────
switch ($group) {
    case 'program':
        $sel = "p.id AS gid,
                CONCAT(COALESCE(p.program_name, '(No program)'), ' — ', d.name) AS glabel";
        $joins = ' JOIN dept_departments d ON d.id = s.dept_id
                   LEFT JOIN dept_academic_programs p ON p.id = s.program_id';
        $group_label = 'Program';
        break;
    case 'batch':
        $sel = "b.id AS gid, COALESCE(b.name, '(No batch)') AS glabel";
        $joins = ' LEFT JOIN student_batches b ON b.id = s.batch_id';
        $group_label = 'Batch';
        break;
    default: // dept
        $sel = 'd.id AS gid, d.name AS glabel';
        $joins = ' JOIN dept_departments d ON d.id = s.dept_id';
        $group_label = 'Department';
        break;
}

$sql = "SELECT $sel,
               COUNT(*)                          AS total_cnt,
               SUM(s.status = 'Active')          AS active_cnt,
               SUM(s.status = 'Graduated')       AS graduated_cnt,
               SUM(s.status = 'Inactive')        AS inactive_cnt,
               SUM(s.status = 'Dropped')         AS dropped_cnt,
               SUM(s.status = 'Not Admitted Yet') AS pending_cnt,
               $card_expr                        AS card_cnt
          FROM students s" . $joins . $card_join . $where_sql . '
         GROUP BY gid, glabel
         ORDER BY glabel ASC';

// Placeholder order: JOIN subquery params first, then WHERE params
$stmt = db()->prepare($sql);
$stmt->execute(array_merge($card_params, $params));
$rows = $stmt->fetchAll();

// ── Grand totals ──────────────────────────────────────────────────────────────
$tot = ['total' => 0, 'active' => 0, 'graduated' => 0, 'inactive' => 0, 'dropped' => 0, 'pending' => 0, 'card' => 0];
foreach ($rows as $r) {
    $tot['total']     += (int)$r['total_cnt'];
    $tot['active']    += (int)$r['active_cnt'];
    $tot['graduated'] += (int)$r['graduated_cnt'];
    $tot['inactive']  += (int)$r['inactive_cnt'];
    $tot['dropped']   += (int)$r['dropped_cnt'];
    $tot['pending']   += (int)$r['pending_cnt'];
    $tot['card']      += (int)$r['card_cnt'];
}

if (!function_exists('ba_pct')) {
    function ba_pct(int $n, int $d): string
    {
        return $d > 0 ? number_format($n / $d * 100, 1) . '%' : '—';
    }
}

// ── Filter dropdown data (scope-restricted, same as student list) ────────────
$departments = db()->query(
    'SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
)->fetchAll();
if ($dept_scope !== null) {
    $departments = array_values(array_filter(
        $departments,
        fn($d) => in_array((int)$d['id'], $dept_scope, true)
    ));
}

$all_programs = sm_program_data();
if ($dept_scope !== null) {
    $all_programs = array_values(array_filter(
        $all_programs,
        fn($p) => in_array((int)$p['dept_id'], $dept_scope, true)
    ));
}

$batches = sm_batches();

// Exam names for the exam filter (from admit cards)
$exam_names = [];
if ($has_cards) {
    $exam_names = db()->query(
        "SELECT DISTINCT exam_name FROM ac_admit_cards\n          WHERE exam_name IS NOT NULL AND exam_name <> ''\n          ORDER BY exam_name ASC"
    )->fetchAll(PDO::FETCH_COLUMN);
}

$exam_label = $f_exam !== '' ? $f_exam : 'Any Exam';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-chart-pie me-2 text-primary"></i>Batch Analytics</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/students/index.php">Students</a></li>
            <li class="breadcrumb-item active">Batch Analytics</li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle px-3 py-2">
            <i class="fas fa-file-signature me-1"></i>Exam: <?= h($exam_label) ?>
        </span>
        <span class="badge bg-light text-muted border px-3 py-2">
            <i class="fas fa-calendar-day me-1"></i>As of <?= date('d M Y') ?>
        </span>
    </div>
</div>

<!-- Summary -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="stat-card" style="background:linear-gradient(135deg,#4f8ef7,#3a6fd8);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= number_format($tot['total']) ?></div>
                    <div class="stat-label">Total Admitted (all statuses)</div>
                </div>
                <div class="stat-icon"><i class="fas fa-users"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="stat-card" style="background:linear-gradient(135deg,#28a745,#1d7a34);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= number_format($tot['card']) ?>
                        <small style="font-size:.85rem;">(<?= ba_pct($tot['card'], $tot['total']) ?>)</small>
                    </div>
                    <div class="stat-label">Exam Attended — <?= h($exam_label) ?></div>
                </div>
                <div class="stat-icon"><i class="fas fa-id-card"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="stat-card" style="background:linear-gradient(135deg,#dc3545,#a71d2a);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= number_format($tot['total'] - $tot['card']) ?>
                        <small style="font-size:.85rem;">(<?= ba_pct($tot['total'] - $tot['card'], $tot['total']) ?>)</small>
                    </div>
                    <div class="stat-label">Not Attended</div>
                </div>
                <div class="stat-icon"><i class="fas fa-user-times"></i></div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body py-3 px-4">
        <form method="GET" action="" class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Group by</label>
                <select name="group" class="form-select form-select-sm">
                    <option value="dept"    <?= $group === 'dept'    ? 'selected' : '' ?>>Department</option>
                    <option value="program" <?= $group === 'program' ? 'selected' : '' ?>>Program</option>
                    <option value="batch"   <?= $group === 'batch'   ? 'selected' : '' ?>>Batch</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Exam</label>
                <select name="exam" class="form-select form-select-sm">
                    <option value="">All Exams</option>
                    <?php foreach ($exam_names as $en): ?>
                    <option value="<?= h($en) ?>" <?= $f_exam === $en ? 'selected' : '' ?>><?= h($en) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Department</label>
                <select name="dept" id="filter_dept" class="form-select form-select-sm">
                    <option value="">All Depts</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $f_dept == $d['id'] ? 'selected' : '' ?>>
                        <?= h($d['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Program</label>
                <select name="program" id="filter_program" class="form-select form-select-sm">
                    <option value="">All Programs</option>
                    <?php foreach ($all_programs as $p): ?>
                    <option value="<?= $p['id'] ?>"
                            data-dept="<?= $p['dept_id'] ?>"
                            <?= $f_program == $p['id'] ? 'selected' : '' ?>>
                        <?= h($p['program_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Batch</label>
                <select name="batch" class="form-select form-select-sm">
                    <option value="">All Batches</option>
                    <?php foreach ($batches as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $f_batch == $b['id'] ? 'selected' : '' ?>>
                        <?= h($b['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Admitted Semester</label>
                <input type="text" name="semester" class="form-control form-control-sm"
                       placeholder="e.g. Fall 2025" value="<?= h($f_sem) ?>">
            </div>
            <div class="col-6 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-fill" style="border-radius:7px;">
                    <i class="fas fa-filter me-1"></i> Apply
                </button>
                <a href="<?= APP_URL ?>/students/batch-analytics.php" class="btn btn-outline-secondary btn-sm flex-fill" style="border-radius:7px;">
                    Reset
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Report table -->
<div class="card">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-table me-2 text-muted"></i><?= h($group_label) ?>-wise Admission &amp; Exam Attendance
            <span class="text-muted fw-normal">— <?= h($exam_label) ?></span>
        </h6>
        <span class="badge bg-primary bg-opacity-10 text-primary"><?= count($rows) ?> row<?= count($rows) !== 1 ? 's' : '' ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th><?= h($group_label) ?></th>
                        <th class="text-end">Total Admitted</th>
                        <th class="text-end">Exam Attended<br><small class="text-muted fw-normal"><?= h($exam_label) ?></small></th>
                        <th class="text-end">Attended %</th>
                        <th class="text-end">Not Attended</th>
                        <th class="text-end pe-4">Not Attended %</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-5">No students found for the selected filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $i => $r): ?>
                    <?php $t = (int)$r['total_cnt']; $att = (int)$r['card_cnt']; ?>
                    <tr>
                        <td class="px-4"><?= $i + 1 ?></td>
                        <td class="fw-medium"><?= h($r['glabel']) ?></td>
                        <td class="text-end fw-semibold"><?= number_format($t) ?></td>
                        <td class="text-end"><span class="text-success fw-semibold"><?= number_format($att) ?></span></td>
                        <td class="text-end text-success"><?= ba_pct($att, $t) ?></td>
                        <td class="text-end"><span class="text-danger fw-semibold"><?= number_format($t - $att) ?></span></td>
                        <td class="text-end pe-4 text-danger"><?= ba_pct($t - $att, $t) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
                <?php if (!empty($rows)): ?>
                <tfoot class="table-light fw-semibold">
                    <tr>
                        <td class="px-4"></td>
                        <td>Total</td>
                        <td class="text-end"><?= number_format($tot['total']) ?></td>
                        <td class="text-end text-success"><?= number_format($tot['card']) ?></td>
                        <td class="text-end text-success"><?= ba_pct($tot['card'], $tot['total']) ?></td>
                        <td class="text-end text-danger"><?= number_format($tot['total'] - $tot['card']) ?></td>
                        <td class="text-end pe-4 text-danger"><?= ba_pct($tot['total'] - $tot['card'], $tot['total']) ?></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
    <div class="card-footer py-2 px-4">
        <small class="text-muted">
            <i class="fas fa-info-circle me-1"></i>
            <strong>Total Admitted</strong> counts every student regardless of status (Active, Inactive, Graduated, Dropped, Not Admitted Yet).
            <strong>Exam Attended</strong> counts distinct students with an <strong>active</strong> admit card created for them
            for <strong><?= h($exam_label) ?></strong>
            (via registered courses, dept/program/batch match for manual cards, or admin override) as of today.
            <strong>Not Attended</strong> = Total Admitted − Exam Attended.
        </small>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<script>
(function () {
    var deptSel    = document.getElementById('filter_dept');
    var programSel = document.getElementById('filter_program');
    if (!deptSel || !programSel) return;

    function filterPrograms() {
        var deptId = deptSel.value;
        var opts   = programSel.querySelectorAll('option[data-dept]');
        opts.forEach(function (opt) {
            var show = !deptId || opt.dataset.dept === deptId;
            opt.hidden   = !show;
            opt.disabled = !show;
            if (!show && opt.selected) {
                programSel.value = '';
            }
        });
    }

    deptSel.addEventListener('change', filterPrograms);
    filterPrograms();
}());
</script>
