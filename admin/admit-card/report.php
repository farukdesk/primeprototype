<?php
/**
 * Admit Card – Exam-wise Department Report
 *
 * Select an exam → one row per department showing:
 *   • department name
 *   • total number of batches + each batch name (comma separated,
 *     with the number of courses offered per batch)
 *   • number of batch courses offered in the exam
 *   • number of admit card batches created
 *   • number of eligible students (due as of today ≤ ৳500 or override)
 *   • number of NOT eligible students + the list of those students
 *
 * In this module the "routine" IS the schedule set on the Generate page
 * (create.php): exam dates/times saved on ac_admit_card_courses. So the
 * report is driven by the generated admit cards themselves
 * (ac_admit_cards → ac_admit_card_courses → co_registrations), the exact
 * data students see on their cards.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('admit-card');
require_once __DIR__ . '/helpers.php';

$page_title = 'Admit Card – Exam Report';
$db = db();

// Department scope: faculty users only see their own department(s)
$ac_scope = ac_dept_scope();

// ── Exam list for the selector: the exams admit cards were generated for ─
// (exam_name + semester entered on create.php), restricted to the scope.
$exam_scope_sql    = '';
$exam_scope_params = [];
if ($ac_scope !== null) {
    if (empty($ac_scope)) {
        $exam_scope_sql = ' WHERE 1 = 0';
    } else {
        $exam_scope_sql    = ' WHERE dept_id IN (' . implode(',', array_fill(0, count($ac_scope), '?')) . ')';
        $exam_scope_params = $ac_scope;
    }
}
$exams = [];
try {
    $st = $db->prepare(
        'SELECT exam_name, semester, COUNT(*) AS cards
           FROM ac_admit_cards' . $exam_scope_sql . '
          GROUP BY exam_name, semester
          ORDER BY MAX(created_at) DESC, exam_name ASC'
    );
    $st->execute($exam_scope_params);
    $exams = $st->fetchAll();
} catch (Throwable $e) {}

$f_exam = trim((string)($_GET['exam'] ?? ''));
$exam   = null;
foreach ($exams as $e) {
    if ($e['exam_name'] . '|' . $e['semester'] === $f_exam) { $exam = $e; break; }
}

// ── Build the report ─────────────────────────────────────────────────────
// $report[dept_id] = [
//   'dept_name', 'batches' => [batch_id => ['name','courses','cards']],
//   'courses', 'cards', 'students' => [sid => row], 'eligible', 'blocked',
//   'not_eligible' => [rows]
// ]
$report = [];

if ($exam) {
    // 1) Admit cards of this exam (one card per class group from create.php)
    //    → departments, batches, scheduled courses and card counts.
    $st = $db->prepare(
        'SELECT ac.id, ac.dept_id, d.name AS dept_name,
                ac.batch_id, ac.batch_label,
                b.name AS batch_name,
                (SELECT COUNT(*) FROM ac_admit_card_courses cc
                  WHERE cc.admit_card_id = ac.id) AS course_count
           FROM ac_admit_cards ac
           JOIN dept_departments d ON d.id = ac.dept_id
      LEFT JOIN student_batches b   ON b.id = ac.batch_id
          WHERE ac.exam_name = ? AND ac.semester = ?'
          . ($exam_scope_params ? ' AND ac.dept_id IN (' . implode(',', array_fill(0, count($exam_scope_params), '?')) . ')' : '') . '
          ORDER BY d.name ASC, b.sort_order ASC, b.name ASC, ac.id ASC'
    );
    $st->execute(array_merge([$exam['exam_name'], $exam['semester']], $exam_scope_params));
    $cards = $st->fetchAll();

    $card_dept = [];   // card id → dept id
    foreach ($cards as $row) {
        $did = (int)$row['dept_id'];
        if (!isset($report[$did])) {
            $report[$did] = [
                'dept_name'    => (string)$row['dept_name'],
                'batches'      => [],
                'courses'      => 0,
                'cards'        => 0,
                'students'     => [],
                'eligible'     => 0,
                'blocked'      => 0,
                'not_eligible' => [],
            ];
        }
        $bname = (string)($row['batch_label'] ?? '');
        if ($bname === '') $bname = (string)($row['batch_name'] ?? '');
        $bkey  = $row['batch_id'] !== null ? 'b' . (int)$row['batch_id'] : 'l' . $bname;
        if (!isset($report[$did]['batches'][$bkey])) {
            $report[$did]['batches'][$bkey] = [
                'name'    => $bname !== '' ? $bname : '—',
                'courses' => 0,
                'cards'   => 0,
            ];
        }
        $report[$did]['batches'][$bkey]['courses'] += (int)$row['course_count'];
        $report[$did]['batches'][$bkey]['cards']++;
        $report[$did]['courses'] += (int)$row['course_count'];
        $report[$did]['cards']++;
        $card_dept[(int)$row['id']] = $did;
    }

    // 2) Students covered by each card — resolved exactly like the card
    //    generation/download path (registered offer subjects, with the
    //    dept/program/batch fallback for manual cards).
    $sid_depts = [];    // internal student id → [dept id => true]
    foreach ($card_dept as $cid => $did) {
        foreach (ac_card_student_ids($cid) as $sid) {
            $sid_depts[$sid][$did] = true;
        }
    }
    if ($sid_depts) {
        $ids = array_keys($sid_depts);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $st  = $db->prepare(
            'SELECT s.id AS sid, s.student_id, s.full_name, sb.name AS batch_name
               FROM students s
          LEFT JOIN student_batches sb ON sb.id = s.batch_id
              WHERE s.id IN (' . $ph . ')'
        );
        $st->execute($ids);
        foreach ($st->fetchAll() as $row) {
            foreach (array_keys($sid_depts[(int)$row['sid']]) as $did) {
                $report[$did]['students'][(int)$row['sid']] = $row;
            }
        }
    }

    // 3) Override holders on this exam's cards: always eligible.
    $overrides = [];
    if ($card_dept) {
        try {
            $ph = implode(',', array_fill(0, count($card_dept), '?'));
            $st = $db->prepare(
                'SELECT DISTINCT student_id FROM ac_student_overrides
                  WHERE admit_card_id IN (' . $ph . ')'
            );
            $st->execute(array_keys($card_dept));
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $sid) $overrides[(int)$sid] = true;
        } catch (Throwable $e) {}
    }

    // 4) Eligibility per student (same rule as the download check:
    //    due as of today ≤ AC_DUE_THRESHOLD, or an admin override).
    foreach ($report as $did => &$dep) {
        foreach ($dep['students'] as $sid => $srow) {
            $due = isset($overrides[$sid]) ? 0.0 : ac_student_due_today($sid);
            if (isset($overrides[$sid]) || $due <= AC_DUE_THRESHOLD) {
                $dep['eligible']++;
            } else {
                $dep['blocked']++;
                $srow['due'] = $due;
                $dep['not_eligible'][] = $srow;
            }
        }
        usort($dep['not_eligible'], static fn($a, $b) => strcmp((string)$a['full_name'], (string)$b['full_name']));
    }
    unset($dep);
}

// Totals
$tot = ['batches' => 0, 'courses' => 0, 'cards' => 0, 'students' => 0, 'eligible' => 0, 'blocked' => 0];
foreach ($report as $dep) {
    $tot['batches']  += count($dep['batches']);
    $tot['courses']  += $dep['courses'];
    $tot['cards']    += $dep['cards'];
    $tot['students'] += count($dep['students']);
    $tot['eligible'] += $dep['eligible'];
    $tot['blocked']  += $dep['blocked'];
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-chart-pie me-2 text-primary"></i>Admit Card – Exam Report</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admit-card/index.php">Admit Cards</a></li>
            <li class="breadcrumb-item active">Exam Report</li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($exam): ?>
        <button type="button" class="btn btn-outline-secondary" style="border-radius:10px;" onclick="window.print()">
            <i class="fas fa-print me-1"></i> Print
        </button>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/admit-card/index.php" class="btn btn-outline-primary" style="border-radius:10px;">
            <i class="fas fa-arrow-left me-1"></i> Back to Admit Cards
        </a>
    </div>
</div>

<?php flash_show(); ?>

<!-- Exam selector -->
<div class="card mb-4 d-print-none">
    <div class="card-body py-3 px-4">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-6 col-lg-5">
                <label class="form-label small text-muted mb-1">Exam</label>
                <select name="exam" class="form-select">
                    <option value="">— Select an exam —</option>
                    <?php foreach ($exams as $e): $val = $e['exam_name'] . '|' . $e['semester']; ?>
                        <option value="<?= h($val) ?>" <?= $f_exam === $val ? 'selected' : '' ?>>
                            <?= h($e['exam_name']) ?> — <?= h($e['semester']) ?> (<?= (int)$e['cards'] ?> card<?= (int)$e['cards'] === 1 ? '' : 's' ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-primary"><i class="fas fa-magnifying-glass-chart me-1"></i> Show Report</button>
            </div>
        </form>
    </div>
</div>

<?php if ($f_exam === ''): ?>
    <div class="alert alert-info"><i class="fas fa-circle-info me-2"></i>Select an exam above to build the department-wise report. Exams appear here once admit cards are generated for them.</div>
<?php elseif (!$exam): ?>
    <div class="alert alert-warning"><i class="fas fa-triangle-exclamation me-2"></i>Exam not found or you do not have access to any of its departments.</div>
<?php else: ?>

    <h5 class="fw-semibold mb-3"><?= h($exam['exam_name']) ?> — <?= h($exam['semester']) ?></h5>

    <!-- Totals -->
    <div class="d-flex flex-wrap gap-2 mb-3">
        <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle px-3 py-2">
            <i class="fas fa-building me-1"></i><?= count($report) ?> department(s)
        </span>
        <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle px-3 py-2">
            <i class="fas fa-layer-group me-1"></i><?= (int)$tot['batches'] ?> batch(es)
        </span>
        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle px-3 py-2">
            <i class="fas fa-book me-1"></i><?= (int)$tot['courses'] ?> course(s) offered
        </span>
        <span class="badge bg-dark-subtle text-dark-emphasis border border-dark-subtle px-3 py-2">
            <i class="fas fa-id-card me-1"></i><?= (int)$tot['cards'] ?> admit card batch(es) created
        </span>
        <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle px-3 py-2">
            <i class="fas fa-circle-check me-1"></i><?= (int)$tot['eligible'] ?> eligible student(s)
        </span>
        <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle px-3 py-2">
            <i class="fas fa-circle-xmark me-1"></i><?= (int)$tot['blocked'] ?> not eligible
        </span>
    </div>

    <?php if (!$report): ?>
        <div class="alert alert-secondary"><i class="fas fa-circle-info me-2"></i>No admit cards found for this exam — generate them from the Generate page first.</div>
    <?php else: ?>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Department</th>
                        <th>Batches</th>
                        <th class="text-center">Courses Offered</th>
                        <th class="text-center">Admit Cards Created</th>
                        <th class="text-center">Students</th>
                        <th class="text-center">Eligible</th>
                        <th class="text-center">Not Eligible</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($report as $did => $dep): ?>
                    <tr>
                        <td class="fw-semibold"><?= h($dep['dept_name']) ?></td>
                        <td>
                            <span class="badge bg-secondary-subtle text-secondary-emphasis border me-1"><?= count($dep['batches']) ?></span>
                            <span class="small">
                                <?= h(implode(', ', array_map(
                                    static fn($b) => $b['name'] . ' (' . $b['courses'] . ' course' . ($b['courses'] === 1 ? '' : 's')
                                        . ', ' . $b['cards'] . ' card' . ($b['cards'] === 1 ? '' : 's') . ')',
                                    $dep['batches']
                                ))) ?>
                            </span>
                        </td>
                        <td class="text-center"><?= (int)$dep['courses'] ?></td>
                        <td class="text-center">
                            <?php if ($dep['cards'] > 0): ?>
                                <span class="badge bg-dark-subtle text-dark-emphasis border"><?= (int)$dep['cards'] ?></span>
                            <?php else: ?>
                                <span class="badge bg-warning-subtle text-warning-emphasis border" title="No admit cards generated for this exam yet">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><?= count($dep['students']) ?></td>
                        <td class="text-center">
                            <span class="badge bg-success-subtle text-success-emphasis border"><?= (int)$dep['eligible'] ?></span>
                        </td>
                        <td class="text-center">
                            <?php if ($dep['blocked'] > 0): ?>
                                <button class="btn btn-sm btn-outline-danger d-print-none" type="button"
                                        data-bs-toggle="collapse" data-bs-target="#ne-<?= (int)$did ?>">
                                    <i class="fas fa-circle-xmark me-1"></i><?= (int)$dep['blocked'] ?> — view list
                                </button>
                                <span class="d-none d-print-inline badge bg-danger-subtle text-danger-emphasis border"><?= (int)$dep['blocked'] ?></span>
                            <?php else: ?>
                                <span class="badge bg-success-subtle text-success-emphasis border">0</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($dep['blocked'] > 0): ?>
                    <tr class="collapse" id="ne-<?= (int)$did ?>">
                        <td colspan="7" class="bg-light p-3">
                            <div class="fw-semibold small mb-2 text-danger">
                                <i class="fas fa-user-xmark me-1"></i>Not eligible students — <?= h($dep['dept_name']) ?>
                                (due as of today exceeds ৳<?= number_format(AC_DUE_THRESHOLD) ?>)
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered bg-white mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width:50px;">#</th>
                                            <th>Student ID</th>
                                            <th>Name</th>
                                            <th>Batch</th>
                                            <th class="text-end">Due (৳)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($dep['not_eligible'] as $i => $s): ?>
                                        <tr>
                                            <td><?= $i + 1 ?></td>
                                            <td><?= h($s['student_id']) ?></td>
                                            <td><?= h($s['full_name']) ?></td>
                                            <td><?= h($s['batch_name'] ?? '—') ?></td>
                                            <td class="text-end text-danger fw-semibold"><?= number_format((float)$s['due'], 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light fw-semibold">
                    <tr>
                        <td>Total</td>
                        <td><?= (int)$tot['batches'] ?> batch(es)</td>
                        <td class="text-center"><?= (int)$tot['courses'] ?></td>
                        <td class="text-center"><?= (int)$tot['cards'] ?></td>
                        <td class="text-center"><?= (int)$tot['students'] ?></td>
                        <td class="text-center"><?= (int)$tot['eligible'] ?></td>
                        <td class="text-center"><?= (int)$tot['blocked'] ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <p class="text-muted small mt-2 mb-0">
        <i class="fas fa-circle-info me-1"></i>
        Students are counted through their course-offer registrations on this exam's admit cards —
        the schedule set on the Generate page.
        "Eligible" means the student's due as of today is within ৳<?= number_format(AC_DUE_THRESHOLD) ?> or an admin override exists —
        the same rule used for admit card downloads.
    </p>

    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
