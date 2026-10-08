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
 * Everything is resolved through the exam's routines (exam_routines →
 * exam_routine_items → co_registrations), the same path used when the
 * admit cards are generated, so the report reflects reality.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('admit-card');
require_once __DIR__ . '/helpers.php';

$page_title = 'Admit Card – Exam Report';
$db = db();

// Department scope: faculty users only see their own department(s)
$ac_scope = ac_dept_scope();

// ── Exam list for the selector ───────────────────────────────────────────
$exams = [];
try {
    $exams = $db->query(
        'SELECT id, exam_name, exam_year
           FROM ei_exams
          ORDER BY exam_year DESC, exam_name ASC'
    )->fetchAll();
} catch (Throwable $e) {}

$f_exam = (int)($_GET['exam_id'] ?? 0);
$exam   = null;
foreach ($exams as $e) {
    if ((int)$e['id'] === $f_exam) { $exam = $e; break; }
}

// Optional routine-link column on ac_admit_cards (admit-card-routine-link.sql)
$has_routine_col = false;
try { $db->query('SELECT routine_id FROM ac_admit_cards LIMIT 1'); $has_routine_col = true; } catch (Throwable $e) {}

// ── Build the report ─────────────────────────────────────────────────────
// $report[dept_id] = [
//   'dept_name', 'batches' => [batch_id => ['name','courses','cards']],
//   'courses', 'cards', 'students' => [sid => row], 'eligible', 'blocked',
//   'not_eligible' => [rows]
// ]
$report = [];

$scope_sql    = '';
$scope_params = [];
if ($exam && $ac_scope !== null) {
    if (empty($ac_scope)) {
        $exam = null; // nothing visible for this user
    } else {
        $scope_sql    = ' AND r.dept_id IN (' . implode(',', array_fill(0, count($ac_scope), '?')) . ')';
        $scope_params = $ac_scope;
    }
}

if ($exam) {
    // 1) Departments + batches + courses offered (from the exam's routines).
    //    A routine without its own batch falls back to its offer's batch.
    $st = $db->prepare(
        'SELECT r.dept_id,
                d.name AS dept_name,
                COALESCE(r.batch_id, o.batch_id) AS batch_id,
                b.name AS batch_name,
                COUNT(DISTINCT i.id) AS course_count
           FROM exam_routines r
           JOIN dept_departments d ON d.id = r.dept_id
      LEFT JOIN co_offers o         ON o.id = r.offer_id
      LEFT JOIN student_batches b   ON b.id = COALESCE(r.batch_id, o.batch_id)
      LEFT JOIN exam_routine_items i ON i.routine_id = r.id
          WHERE r.exam_id = ?' . $scope_sql . '
          GROUP BY r.dept_id, COALESCE(r.batch_id, o.batch_id)
          ORDER BY d.name ASC, b.sort_order ASC, b.name ASC'
    );
    $st->execute(array_merge([$f_exam], $scope_params));
    foreach ($st->fetchAll() as $row) {
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
        $bid = (int)($row['batch_id'] ?? 0);
        $report[$did]['batches'][$bid] = [
            'name'    => (string)($row['batch_name'] ?? '') !== '' ? (string)$row['batch_name'] : '—',
            'courses' => (int)$row['course_count'],
            'cards'   => 0,
        ];
        $report[$did]['courses'] += (int)$row['course_count'];
    }

    // 2) Admit card batches created from this exam's routines.
    if ($has_routine_col && $report) {
        $st = $db->prepare(
            'SELECT ac.dept_id, ac.batch_id, COUNT(*) AS cards
               FROM ac_admit_cards ac
               JOIN exam_routines r ON r.id = ac.routine_id
              WHERE r.exam_id = ?' . $scope_sql . '
              GROUP BY ac.dept_id, ac.batch_id'
        );
        $st->execute(array_merge([$f_exam], $scope_params));
        foreach ($st->fetchAll() as $row) {
            $did = (int)$row['dept_id'];
            if (!isset($report[$did])) continue;
            $report[$did]['cards'] += (int)$row['cards'];
            $bid = (int)($row['batch_id'] ?? 0);
            if (isset($report[$did]['batches'][$bid])) {
                $report[$did]['batches'][$bid]['cards'] += (int)$row['cards'];
            }
        }
    }

    // 3) Students covered by the exam (registered in the routines' subjects),
    //    deduplicated per department.
    $st = $db->prepare(
        'SELECT DISTINCT r.dept_id, s.id AS sid, s.student_id, s.full_name,
                sb.name AS batch_name
           FROM exam_routines r
           JOIN exam_routine_items i ON i.routine_id = r.id
           JOIN co_registrations reg ON reg.offer_subject_id = i.offer_subject_id
           JOIN students s           ON s.id = reg.student_id
      LEFT JOIN student_batches sb   ON sb.id = s.batch_id
          WHERE r.exam_id = ? AND s.status = \'Active\'' . $scope_sql
    );
    $st->execute(array_merge([$f_exam], $scope_params));
    foreach ($st->fetchAll() as $row) {
        $did = (int)$row['dept_id'];
        if (!isset($report[$did])) continue;
        $report[$did]['students'][(int)$row['sid']] = $row;
    }

    // 4) Override holders on this exam's cards: always eligible.
    $overrides = [];
    if ($has_routine_col) {
        try {
            $st = $db->prepare(
                'SELECT DISTINCT ov.student_id
                   FROM ac_student_overrides ov
                   JOIN ac_admit_cards ac ON ac.id = ov.admit_card_id
                   JOIN exam_routines r   ON r.id  = ac.routine_id
                  WHERE r.exam_id = ?'
            );
            $st->execute([$f_exam]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $sid) $overrides[(int)$sid] = true;
        } catch (Throwable $e) {}
    }

    // 5) Eligibility per student (same rule as the download check:
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
                <select name="exam_id" class="form-select">
                    <option value="">— Select an exam —</option>
                    <?php foreach ($exams as $e): ?>
                        <option value="<?= (int)$e['id'] ?>" <?= $f_exam === (int)$e['id'] ? 'selected' : '' ?>>
                            <?= h($e['exam_name']) ?> (<?= h($e['exam_year']) ?>)
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

<?php if (!$f_exam): ?>
    <div class="alert alert-info"><i class="fas fa-circle-info me-2"></i>Select an exam above to build the department-wise report.</div>
<?php elseif (!$exam): ?>
    <div class="alert alert-warning"><i class="fas fa-triangle-exclamation me-2"></i>Exam not found or you do not have access to any of its departments.</div>
<?php else: ?>

    <h5 class="fw-semibold mb-3"><?= h($exam['exam_name']) ?> (<?= h($exam['exam_year']) ?>)</h5>

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
        <div class="alert alert-secondary"><i class="fas fa-circle-info me-2"></i>No exam routines found for this exam — build routines first, then generate admit cards.</div>
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
                                <span class="badge bg-warning-subtle text-warning-emphasis border" title="No admit cards generated from this exam's routines yet">0</span>
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
        Students are counted through their course-offer registrations in this exam's routines.
        "Eligible" means the student's due as of today is within ৳<?= number_format(AC_DUE_THRESHOLD) ?> or an admin override exists —
        the same rule used for admit card downloads.
    </p>

    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
