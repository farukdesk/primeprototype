<?php
/**
 * Student Due List – exam-wise due report
 *
 * The due list is ALWAYS generated from exams, i.e. from the students the
 * admit cards were generated for:
 *   1. Pick an exam (exam_name + semester entered on the admit-card
 *      Generate page — one entry per exam with generated cards).
 *   2. Every student covered by that exam's admit cards (resolved the same
 *      way the card generation/download path does, via ac_card_student_ids)
 *      is checked for an outstanding due as of today
 *      (ac_student_due_today → acc_outstanding_through_current_month).
 *   3. Students with a due are listed grouped by department, program or
 *      batch (selectable), filterable by department, program, batch,
 *      section and shift — with a printable sheet.
 *
 * The module lives under the Accounts section of the admin sidebar.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('student-due-list');
require_once __DIR__ . '/../admit-card/helpers.php';
require_once __DIR__ . '/../accounting/helpers.php';

$page_title = 'Student Due List';
$db = db();

// Students owing more than this are flagged as "may be dropped".
const SDL_HIGH_DUE_THRESHOLD = 50000;

// Department scope: faculty users only see their own department(s)
$scope = ac_dept_scope();

// ── Exam list for the selector: exams admit cards were generated for ─────
$exam_scope_sql    = '';
$exam_scope_params = [];
if ($scope !== null) {
    if (empty($scope)) {
        $exam_scope_sql = ' WHERE 1 = 0';
    } else {
        $exam_scope_sql    = ' WHERE dept_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')';
        $exam_scope_params = $scope;
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

$f_exam    = trim((string)($_GET['exam'] ?? ''));
$f_dept    = (int)($_GET['dept'] ?? 0);
$f_program = (int)($_GET['program'] ?? 0);
$f_batch   = (int)($_GET['batch'] ?? 0);
$f_section = trim((string)($_GET['section'] ?? ''));
$f_shift   = trim((string)($_GET['shift'] ?? ''));
$f_show    = (string)($_GET['show'] ?? 'due');            // due | all | high
if (!in_array($f_show, ['due', 'all', 'high'], true)) $f_show = 'due';
$f_all     = $f_show === 'all';                            // default: only students with a due
$f_high    = $f_show === 'high';                           // only students with a high (৳50,000+) due
$f_group   = (string)($_GET['group'] ?? 'dept');           // dept | program | batch
if (!in_array($f_group, ['dept', 'program', 'batch'], true)) $f_group = 'dept';

$exam = null;
foreach ($exams as $e) {
    if ($e['exam_name'] . '|' . $e['semester'] === $f_exam) { $exam = $e; break; }
}

// ── Build the due list ────────────────────────────────────────────────────
// $report[group_key] = ['label', 'rows' => [...], 'checked' => int,
//                       'due_count' => int, 'due_total' => float]
$report       = [];
$dept_opts    = [];   // departments present on this exam's cards (for the filter)
$program_opts = [];   // programs of the covered students
$batch_opts   = [];   // batches of the covered students
$section_opts = [];   // sections of the covered students
$shift_opts   = [];   // shifts of the covered students
$tot          = ['checked' => 0, 'due_count' => 0, 'due_total' => 0.0, 'high_count' => 0];

if ($exam) {
    // 1) Admit cards of this exam → departments + covered students.
    $st = $db->prepare(
        'SELECT ac.id, ac.dept_id, d.name AS dept_name
           FROM ac_admit_cards ac
           JOIN dept_departments d ON d.id = ac.dept_id
          WHERE ac.exam_name = ? AND ac.semester = ?'
          . ($exam_scope_params ? ' AND ac.dept_id IN (' . implode(',', array_fill(0, count($exam_scope_params), '?')) . ')' : '') . '
          ORDER BY d.name ASC, ac.id ASC'
    );
    $st->execute(array_merge([$exam['exam_name'], $exam['semester']], $exam_scope_params));
    $cards = $st->fetchAll();

    $sid_depts = [];   // internal student id → [dept id => true]
    foreach ($cards as $row) {
        $did = (int)$row['dept_id'];
        $dept_opts[$did] = (string)$row['dept_name'];
        foreach (ac_card_student_ids((int)$row['id']) as $sid) {
            $sid_depts[$sid][$did] = true;
        }
    }
    asort($dept_opts, SORT_NATURAL | SORT_FLAG_CASE);

    // 2) Override holders on this exam's cards (marked on the list).
    $overrides = [];
    if ($cards) {
        try {
            $ph = implode(',', array_fill(0, count($cards), '?'));
            $st = $db->prepare(
                'SELECT DISTINCT student_id FROM ac_student_overrides
                  WHERE admit_card_id IN (' . $ph . ')'
            );
            $st->execute(array_map(static fn($c) => (int)$c['id'], $cards));
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $sid) $overrides[(int)$sid] = true;
        } catch (Throwable $e) {}
    }

    // 3) Student rows + due as of today, grouped by the selected dimension.
    if ($sid_depts) {
        $ids = array_keys($sid_depts);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $st  = $db->prepare(
            'SELECT s.id AS sid, s.student_id, s.full_name, s.phone,
                    s.program_id, s.batch_id, s.section, s.shift,
                    sb.name AS batch_name, p.program_name
               FROM students s
          LEFT JOIN student_batches sb        ON sb.id = s.batch_id
          LEFT JOIN dept_academic_programs p  ON p.id = s.program_id
              WHERE s.id IN (' . $ph . ')'
        );
        $st->execute($ids);
        $students = $st->fetchAll();

        // Filter dropdown options come from ALL covered students.
        foreach ($students as $row) {
            if ((int)$row['program_id'] > 0) $program_opts[(int)$row['program_id']] = (string)($row['program_name'] ?? ('Program #' . (int)$row['program_id']));
            if ((int)$row['batch_id'] > 0)   $batch_opts[(int)$row['batch_id']]     = (string)($row['batch_name'] ?? ('Batch #' . (int)$row['batch_id']));
            $sec = trim((string)($row['section'] ?? ''));
            $shf = trim((string)($row['shift'] ?? ''));
            if ($sec !== '') $section_opts[$sec] = $sec;
            if ($shf !== '') $shift_opts[$shf] = $shf;
        }
        asort($program_opts, SORT_NATURAL | SORT_FLAG_CASE);
        asort($batch_opts, SORT_NATURAL | SORT_FLAG_CASE);
        ksort($section_opts, SORT_NATURAL | SORT_FLAG_CASE);
        ksort($shift_opts, SORT_NATURAL | SORT_FLAG_CASE);

        foreach ($students as $row) {
            $sid = (int)$row['sid'];

            // Student-level filters
            if ($f_program > 0 && (int)$row['program_id'] !== $f_program) continue;
            if ($f_batch   > 0 && (int)$row['batch_id']   !== $f_batch)   continue;
            if ($f_section !== '' && strcasecmp(trim((string)($row['section'] ?? '')), $f_section) !== 0) continue;
            if ($f_shift   !== '' && strcasecmp(trim((string)($row['shift']   ?? '')), $f_shift)   !== 0) continue;

            $s_depts = array_keys($sid_depts[$sid]);
            if ($f_dept > 0) {
                $s_depts = array_values(array_filter($s_depts, static fn($d) => (int)$d === $f_dept));
                if (!$s_depts) continue;
            }

            $due = ac_student_due_today($sid);
            $row['due']      = $due;
            $row['override'] = isset($overrides[$sid]);
            $row['high']     = $due > SDL_HIGH_DUE_THRESHOLD;

            // Resolve the group bucket(s) for this student.
            if ($f_group === 'program') {
                $pid    = (int)$row['program_id'];
                $groups = [['p' . $pid, $pid > 0 ? ($program_opts[$pid] ?? ('Program #' . $pid)) : 'No program set']];
            } elseif ($f_group === 'batch') {
                $bid    = (int)$row['batch_id'];
                $groups = [['b' . $bid, $bid > 0 ? ($batch_opts[$bid] ?? ('Batch #' . $bid)) : 'No batch set']];
            } else {
                $groups = [];
                foreach ($s_depts as $did) {
                    $groups[] = ['d' . (int)$did, $dept_opts[(int)$did] ?? ('Dept #' . (int)$did)];
                }
            }

            foreach ($groups as [$gkey, $glabel]) {
                if (!isset($report[$gkey])) {
                    $report[$gkey] = [
                        'label'      => $glabel,
                        'rows'       => [],
                        'checked'    => 0,
                        'due_count'  => 0,
                        'due_total'  => 0.0,
                        'high_count' => 0,
                    ];
                }
                $report[$gkey]['checked']++;
                if ($due > 0) {
                    $report[$gkey]['due_count']++;
                    $report[$gkey]['due_total'] += $due;
                }
                if ($row['high']) $report[$gkey]['high_count']++;
                if ($f_high) {
                    if ($row['high']) $report[$gkey]['rows'][] = $row;
                } elseif ($due > 0 || $f_all) {
                    $report[$gkey]['rows'][] = $row;
                }
            }
        }
    }

    uasort($report, static fn($a, $b) => strnatcasecmp($a['label'], $b['label']));
    foreach ($report as &$dep) {
        usort($dep['rows'], static function ($a, $b) {
            $c = strnatcasecmp((string)($a['batch_name'] ?? ''), (string)($b['batch_name'] ?? ''));
            if ($c !== 0) return $c;
            return strcmp((string)$a['full_name'], (string)$b['full_name']);
        });
        $tot['checked']    += $dep['checked'];
        $tot['due_count']  += $dep['due_count'];
        $tot['due_total']  += $dep['due_total'];
        $tot['high_count'] += $dep['high_count'];
    }
    unset($dep);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 d-print-none">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-file-invoice-dollar me-2 text-primary"></i>Student Due List</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item active">Student Due List</li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($exam): ?>
        <button type="button" class="btn btn-outline-secondary" style="border-radius:10px;" onclick="window.print()">
            <i class="fas fa-print me-1"></i> Print
        </button>
        <?php endif; ?>
    </div>
</div>

<?php flash_show(); ?>

<!-- Filters -->
<div class="card mb-4 d-print-none">
    <div class="card-body py-3 px-4">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-6 col-lg-4">
                <label class="form-label small text-muted mb-1">Exam</label>
                <select name="exam" class="form-select" onchange="['dept','program','batch','section','shift'].forEach(n=>this.form[n].value='');this.form.submit()">
                    <option value="">— Select an exam —</option>
                    <?php foreach ($exams as $e): $val = $e['exam_name'] . '|' . $e['semester']; ?>
                        <option value="<?= h($val) ?>" <?= $f_exam === $val ? 'selected' : '' ?>>
                            <?= h($e['exam_name']) ?> — <?= h($e['semester']) ?> (<?= (int)$e['cards'] ?> card batch<?= (int)$e['cards'] === 1 ? '' : 'es' ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 col-lg-2">
                <label class="form-label small text-muted mb-1">Department</label>
                <select name="dept" class="form-select" <?= $exam ? '' : 'disabled' ?>>
                    <option value="">All departments</option>
                    <?php foreach ($dept_opts as $did => $dname): ?>
                        <option value="<?= (int)$did ?>" <?= $f_dept === (int)$did ? 'selected' : '' ?>><?= h($dname) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 col-lg-2">
                <label class="form-label small text-muted mb-1">Program</label>
                <select name="program" class="form-select" <?= $exam ? '' : 'disabled' ?>>
                    <option value="">All programs</option>
                    <?php foreach ($program_opts as $pid => $pname): ?>
                        <option value="<?= (int)$pid ?>" <?= $f_program === (int)$pid ? 'selected' : '' ?>><?= h($pname) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 col-lg-2">
                <label class="form-label small text-muted mb-1">Batch</label>
                <select name="batch" class="form-select" <?= $exam ? '' : 'disabled' ?>>
                    <option value="">All batches</option>
                    <?php foreach ($batch_opts as $bid => $bname): ?>
                        <option value="<?= (int)$bid ?>" <?= $f_batch === (int)$bid ? 'selected' : '' ?>><?= h($bname) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 col-lg-1">
                <label class="form-label small text-muted mb-1">Section</label>
                <select name="section" class="form-select" <?= $exam ? '' : 'disabled' ?>>
                    <option value="">All</option>
                    <?php foreach ($section_opts as $sec): ?>
                        <option value="<?= h($sec) ?>" <?= strcasecmp($f_section, $sec) === 0 ? 'selected' : '' ?>><?= h($sec) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 col-lg-1">
                <label class="form-label small text-muted mb-1">Shift</label>
                <select name="shift" class="form-select" <?= $exam ? '' : 'disabled' ?>>
                    <option value="">All</option>
                    <?php foreach ($shift_opts as $shf): ?>
                        <option value="<?= h($shf) ?>" <?= strcasecmp($f_shift, $shf) === 0 ? 'selected' : '' ?>><?= h($shf) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 col-lg-2">
                <label class="form-label small text-muted mb-1">Group by</label>
                <select name="group" class="form-select">
                    <option value="dept" <?= $f_group === 'dept' ? 'selected' : '' ?>>Department-wise</option>
                    <option value="program" <?= $f_group === 'program' ? 'selected' : '' ?>>Program-wise</option>
                    <option value="batch" <?= $f_group === 'batch' ? 'selected' : '' ?>>Batch-wise</option>
                </select>
            </div>
            <div class="col-md-3 col-lg-2">
                <label class="form-label small text-muted mb-1">Show</label>
                <select name="show" class="form-select">
                    <option value="due" <?= $f_show === 'due' ? 'selected' : '' ?>>Only students with due</option>
                    <option value="high" <?= $f_high ? 'selected' : '' ?>>Only high due (৳<?= number_format(SDL_HIGH_DUE_THRESHOLD) ?>+, may be dropped)</option>
                    <option value="all" <?= $f_all ? 'selected' : '' ?>>All admit-card students</option>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-primary"><i class="fas fa-magnifying-glass-dollar me-1"></i> Generate Due List</button>
            </div>
        </form>
    </div>
</div>

<?php if ($f_exam === ''): ?>
    <div class="alert alert-info"><i class="fas fa-circle-info me-2"></i>Select an exam above to generate the due list. Exams appear here once admit cards are generated for them.</div>
<?php elseif (!$exam): ?>
    <div class="alert alert-warning"><i class="fas fa-triangle-exclamation me-2"></i>Exam not found or you do not have access to any of its departments.</div>
<?php else: ?>

<?php
    $group_noun = ['dept' => 'department', 'program' => 'program', 'batch' => 'batch'][$f_group];
    $group_icon = ['dept' => 'fa-building', 'program' => 'fa-graduation-cap', 'batch' => 'fa-users'][$f_group];
?>
    <!-- Print-only letterhead -->
    <?php
    $print_logo    = acc_university_logo_url();
    $print_filters = [];
    if ($f_dept > 0 && isset($dept_opts[$f_dept]))          $print_filters[] = 'Dept: ' . $dept_opts[$f_dept];
    if ($f_program > 0 && isset($program_opts[$f_program])) $print_filters[] = 'Program: ' . $program_opts[$f_program];
    if ($f_batch > 0 && isset($batch_opts[$f_batch]))       $print_filters[] = 'Batch: ' . $batch_opts[$f_batch];
    if ($f_section !== '')                                  $print_filters[] = 'Section: ' . $f_section;
    if ($f_shift !== '')                                    $print_filters[] = 'Shift: ' . $f_shift;
    ?>
    <div class="d-none d-print-block sdl-print-head">
        <table class="sdl-ph-table">
            <tr>
                <td class="sdl-ph-logo">
                    <?php if ($print_logo): ?>
                    <img src="<?= h($print_logo) ?>" alt="Prime University Logo">
                    <?php endif; ?>
                </td>
                <td class="sdl-ph-center">
                    <div class="sdl-ph-uni">Prime University</div>
                    <div class="sdl-ph-addr"><?= h(acc_university_address()) ?></div>
                    <div class="sdl-ph-title">Student Due List (<?= h(ucfirst($group_noun)) ?>-wise)</div>
                    <div class="sdl-ph-exam"><?= h($exam['exam_name']) ?> &mdash; <?= h($exam['semester']) ?></div>
                    <?php if ($print_filters): ?>
                    <div class="sdl-ph-filters"><?= h(implode(' · ', $print_filters)) ?></div>
                    <?php endif; ?>
                </td>
                <td class="sdl-ph-meta">
                    Generated:<br><?= h(date('d M Y, h:i A')) ?><br>
                    <span>Dues as of <?= h(date('d M Y')) ?></span>
                </td>
            </tr>
        </table>
        <div class="sdl-ph-summary">
            <?= count($report) ?> <?= h($group_noun) ?>(s) &nbsp;·&nbsp;
            <?= (int)$tot['checked'] ?> student(s) checked &nbsp;·&nbsp;
            <?= (int)$tot['due_count'] ?> student(s) with due &nbsp;·&nbsp;
            <?= (int)$tot['high_count'] ?> student(s) over ৳<?= number_format(SDL_HIGH_DUE_THRESHOLD) ?> (may be dropped) &nbsp;·&nbsp;
            Total due ৳<?= number_format($tot['due_total'], 2) ?>
        </div>
    </div>

    <h5 class="fw-semibold mb-3 d-print-none"><?= h($exam['exam_name']) ?> — <?= h($exam['semester']) ?></h5>

    <!-- Totals -->
    <div class="d-flex flex-wrap gap-2 mb-3 d-print-none">
        <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle px-3 py-2">
            <i class="fas <?= $group_icon ?> me-1"></i><?= count($report) ?> <?= h($group_noun) ?>(s)
        </span>
        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle px-3 py-2">
            <i class="fas fa-users me-1"></i><?= (int)$tot['checked'] ?> admit-card student(s) checked
        </span>
        <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle px-3 py-2">
            <i class="fas fa-circle-xmark me-1"></i><?= (int)$tot['due_count'] ?> student(s) with due
        </span>
        <span class="badge bg-danger text-white border border-danger px-3 py-2">
            <i class="fas fa-triangle-exclamation me-1"></i><?= (int)$tot['high_count'] ?> student(s) over ৳<?= number_format(SDL_HIGH_DUE_THRESHOLD) ?> (may be dropped)
        </span>
        <span class="badge bg-dark-subtle text-dark-emphasis border border-dark-subtle px-3 py-2">
            <i class="fas fa-coins me-1"></i>Total due ৳<?= number_format($tot['due_total'], 2) ?>
        </span>
    </div>

    <?php if ((int)$tot['high_count'] > 0): ?>
    <div class="alert alert-danger py-2 d-print-none">
        <i class="fas fa-triangle-exclamation me-2"></i>
        <strong><?= (int)$tot['high_count'] ?> student(s)</strong> owe more than
        ৳<?= number_format(SDL_HIGH_DUE_THRESHOLD) ?> — these students may be dropped.
        They are highlighted in red below<?= $f_high ? '' : '; use Show → "Only high due" to list them separately' ?>.
    </div>
    <?php endif; ?>

    <?php if (!$report): ?>
        <div class="alert alert-secondary"><i class="fas fa-circle-info me-2"></i>No admit-card students found for this selection — generate admit cards for this exam first.</div>
    <?php else: ?>

    <?php foreach ($report as $gkey => $dep): ?>
    <div class="card mb-4 sdl-dept">
        <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="fw-semibold"><i class="fas <?= $group_icon ?> me-2 text-primary"></i><?= h($dep['label']) ?></span>
            <span class="small text-muted">
                <?= (int)$dep['checked'] ?> student(s) checked ·
                <span class="text-danger fw-semibold"><?= (int)$dep['due_count'] ?> with due</span> ·
                <?php if ((int)$dep['high_count'] > 0): ?>
                <span class="text-danger fw-bold"><?= (int)$dep['high_count'] ?> over ৳<?= number_format(SDL_HIGH_DUE_THRESHOLD) ?> (may be dropped)</span> ·
                <?php endif; ?>
                <?= h($group_noun) ?> due total <span class="fw-semibold">৳<?= number_format($dep['due_total'], 2) ?></span>
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:50px;">#</th>
                        <th>Student ID</th>
                        <th>Name</th>
                        <th>Program</th>
                        <th>Batch</th>
                        <th>Section</th>
                        <th>Shift</th>
                        <th>Phone</th>
                        <th class="text-end">Due as of Today (৳)</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$dep['rows']): ?>
                    <tr><td colspan="9" class="text-center text-muted py-3">No students with <?= $f_high ? 'a ৳' . number_format(SDL_HIGH_DUE_THRESHOLD) . '+ due' : 'due' ?> in this <?= h($group_noun) ?></td></tr>
                <?php else: foreach ($dep['rows'] as $i => $s): ?>
                    <tr<?= !empty($s['high']) ? ' class="sdl-high-due"' : '' ?>>
                        <td><?= $i + 1 ?></td>
                        <td><?= h($s['student_id']) ?></td>
                        <td>
                            <?= h($s['full_name']) ?>
                            <?php if (!empty($s['high'])): ?>
                                <span class="badge bg-danger text-white ms-1" title="Due exceeds ৳<?= number_format(SDL_HIGH_DUE_THRESHOLD) ?> — this student may be dropped">
                                    <i class="fas fa-triangle-exclamation me-1"></i>may be dropped
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($s['override'])): ?>
                                <span class="badge bg-warning-subtle text-warning-emphasis border ms-1" title="Has an admit-card download override">override</span>
                            <?php endif; ?>
                        </td>
                        <td><?= h($s['program_name'] ?? '—') ?></td>
                        <td><?= h($s['batch_name'] ?? '—') ?></td>
                        <td><?= h(trim((string)($s['section'] ?? '')) !== '' ? $s['section'] : '—') ?></td>
                        <td><?= h(trim((string)($s['shift'] ?? '')) !== '' ? $s['shift'] : '—') ?></td>
                        <td><?= h($s['phone'] ?? '—') ?></td>
                        <td class="text-end fw-semibold <?= (float)$s['due'] > 0 ? 'text-danger' : 'text-success' ?>">
                            <?= number_format((float)$s['due'], 2) ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
                <?php if ($dep['rows']): ?>
                <tfoot class="table-light fw-semibold">
                    <tr>
                        <td colspan="8" class="text-end"><?= h(ucfirst($group_noun)) ?> due total</td>
                        <td class="text-end">৳<?= number_format($dep['due_total'], 2) ?></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
    <?php endforeach; ?>

    <p class="text-muted small mt-2 mb-0 d-print-none">
        <i class="fas fa-circle-info me-1"></i>
        The list is generated from the students covered by this exam's admit cards —
        resolved exactly like the card generation/download path.
        "Due as of Today" counts fees fallen due through the current month minus payments
        (the same figure used by the admit-card download check and My Finances).
        Students highlighted in red owe more than ৳<?= number_format(SDL_HIGH_DUE_THRESHOLD) ?> and may be dropped.
    </p>

    <?php endif; ?>
<?php endif; ?>

<style>
/* ── High-due (may be dropped) row highlight ── */
.sdl-high-due > td { background-color: #fde2e4 !important; }
.sdl-high-due td, .sdl-high-due td.text-danger { color: #842029; }

/* ── Print-only letterhead (screen hidden via d-none d-print-block) ── */
.sdl-print-head { font-family: "Times New Roman", Georgia, serif; color: #000; margin-bottom: 10px; }
.sdl-ph-table { width: 100%; border-collapse: collapse; }
.sdl-ph-logo { width: 20%; vertical-align: middle; }
.sdl-ph-logo img { height: 58px; width: auto; }
.sdl-ph-center { width: 60%; text-align: center; vertical-align: middle; }
.sdl-ph-uni { font-size: 16pt; font-weight: 700; }
.sdl-ph-addr { font-size: 9pt; }
.sdl-ph-title { font-size: 12pt; font-weight: 700; margin-top: 3px; }
.sdl-ph-exam { font-size: 10.5pt; margin-top: 1px; }
.sdl-ph-filters { font-size: 9pt; margin-top: 1px; color: #333; }
.sdl-ph-meta { width: 20%; text-align: right; vertical-align: middle; font-size: 8pt; color: #555; }
.sdl-ph-meta span { color: #888; }
.sdl-ph-summary { text-align: center; font-size: 9pt; border-top: 1px solid #000; border-bottom: 1px solid #000; padding: 3px 0; margin-top: 6px; }

@media print {
    #sidebar, #topbar, .d-print-none { display: none !important; }
    #main-wrapper, .main-content, main { margin-left: 0 !important; padding: 0 !important; }
    body { background: #fff !important; font-size: 9pt; color: #000; }
    .container-fluid, .container { padding: 0 !important; max-width: 100% !important; }

    /* Group cards → clean bordered sections */
    .sdl-dept {
        page-break-inside: avoid;
        border: none !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        margin-bottom: 12px !important;
    }
    .sdl-dept .card-header {
        background: #fff !important;
        border: none !important;
        border-bottom: 2px solid #000 !important;
        border-radius: 0 !important;
        padding: 2px 0 3px !important;
        font-size: 10pt;
        color: #000 !important;
    }
    .sdl-dept .card-header .text-muted,
    .sdl-dept .card-header .text-danger { color: #000 !important; }
    .sdl-dept .card-header i { display: none; }
    .sdl-dept .table-responsive { overflow: visible !important; }

    /* Compact bordered table that fits A4 */
    .sdl-dept table { width: 100% !important; font-size: 8pt !important; border-collapse: collapse !important; margin-top: 3px; }
    .sdl-dept th, .sdl-dept td {
        border: 1px solid #999 !important;
        padding: 2.5px 4px !important;
        background: #fff !important;
        color: #000 !important;
        box-shadow: none !important;
        white-space: normal !important;
    }
    .sdl-dept thead th { background: #eee !important; font-weight: 700; }
    .sdl-dept tfoot td { background: #f5f5f5 !important; font-weight: 700; }
    .sdl-dept tbody tr { page-break-inside: avoid; }
    .sdl-dept .badge { border: 1px solid #999 !important; background: #fff !important; color: #000 !important; padding: 0 3px !important; font-size: 7pt !important; }
    .sdl-dept td.text-danger, .sdl-dept td.text-success { color: #000 !important; }
    .sdl-high-due > td { background: #f2d7d9 !important; font-weight: 700; }
    .sdl-high-due .badge { border: 1px solid #000 !important; font-weight: 700; }
}
@page { size: A4 portrait; margin: 12mm; }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
