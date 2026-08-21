<?php
/**
 * Admit Card – Admin Index
 * Lists all admit card batches with search/filter.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('admit-card');
require_once __DIR__ . '/helpers.php';

$page_title = 'Admit Cards';

// ── Bulk activate / deactivate ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['bulk_action'] ?? '') !== '')) {
    csrf_check();
    $ret = APP_URL . '/admit-card/index.php'
         . ((string)($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
    $bulk_ids = array_values(array_filter(array_map('intval', (array)($_POST['card_ids'] ?? [])), static fn($v) => $v > 0));
    $bulk_act = (string)$_POST['bulk_action'];
    if ($bulk_act === 'delete' ? !ac_can_delete() : !ac_can_edit()) {
        flash_set('danger', $bulk_act === 'delete'
            ? 'You do not have permission to delete admit cards.'
            : 'You do not have permission to change admit card status.');
        redirect($ret);
    }
    if (!$bulk_ids) {
        flash_set('warning', 'Select at least one admit card first.');
    } elseif ($bulk_act === 'activate' || $bulk_act === 'deactivate') {
        $ph = implode(',', array_fill(0, count($bulk_ids), '?'));
        $st = db()->prepare("UPDATE ac_admit_cards SET is_active = ? WHERE id IN ($ph)");
        $st->execute(array_merge([$bulk_act === 'activate' ? 1 : 0], $bulk_ids));
        flash_set('success', $st->rowCount() . ' admit card(s) ' . ($bulk_act === 'activate' ? 'activated' : 'deactivated') . '.');
    } elseif ($bulk_act === 'delete') {
        $ph = implode(',', array_fill(0, count($bulk_ids), '?'));
        $st = db()->prepare("DELETE FROM ac_admit_cards WHERE id IN ($ph)");
        $st->execute($bulk_ids);
        flash_set('success', $st->rowCount() . ' admit card(s) deleted.');
    }
    redirect($ret);
}

$f_search  = trim($_GET['search'] ?? '');
$f_student = trim($_GET['student_id'] ?? '');
$f_course  = trim($_GET['course'] ?? '');
$f_dept    = (int)($_GET['dept_id'] ?? 0);
$f_program = (int)($_GET['program_id'] ?? 0);
$f_batch   = (int)($_GET['batch_id'] ?? 0);
$f_sem     = trim($_GET['semester'] ?? '');
$f_status  = trim($_GET['status'] ?? '');      // '' = all, '1' = active, '0' = inactive
$f_from    = trim($_GET['date_from'] ?? '');   // exam date range (course rows)
$f_to      = trim($_GET['date_to'] ?? '');
$per_page  = 25;
$cur_page  = max(1, (int)($_GET['page'] ?? 1));
$offset    = ($cur_page - 1) * $per_page;

$db = db();
$where  = '1=1';
$params = [];

// ── Find a student's admit cards by their Student ID (includes inactive cards) ──
$f_student_row = null;
if ($f_student !== '') {
    $st = $db->prepare(
        'SELECT id, student_id, full_name, status, dept_id, program_id, batch_id
         FROM students WHERE student_id = ? LIMIT 1'
    );
    $st->execute([$f_student]);
    $f_student_row = $st->fetch();

    if ($f_student_row) {
        $sid = (int)$f_student_row['id'];

        // Optional routine link column (see admin/admit-card-routine-link.sql)
        $has_routine_col = false;
        $has_subject_col = false;
        try { $db->query('SELECT routine_id FROM ac_admit_cards LIMIT 1'); $has_routine_col = true; } catch (Throwable $e) {}
        try { $db->query('SELECT offer_subject_id FROM ac_admit_card_courses LIMIT 1'); $has_subject_col = true; } catch (Throwable $e) {}

        // Cards matching the student's dept + program (+ batch when the card is batch-specific)
        $cond    = '(ac.dept_id = ? AND ac.program_id = ? AND (ac.batch_id IS NULL OR ac.batch_id = ?))';
        $cparams = [(int)$f_student_row['dept_id'], (int)$f_student_row['program_id'], (int)($f_student_row['batch_id'] ?? 0)];

        // Routine-linked cards where the student is registered in a routine course
        if ($has_routine_col) {
            $cond .= ' OR (ac.routine_id IS NOT NULL AND ac.routine_id IN (
                            SELECT i.routine_id
                              FROM exam_routine_items i
                              JOIN co_registrations r ON r.offer_subject_id = i.offer_subject_id
                             WHERE r.student_id = ?))';
            $cparams[] = $sid;
        }

        // Cards where the student has an admin override
        $cond .= ' OR ac.id IN (SELECT admit_card_id FROM ac_student_overrides WHERE student_id = ?)';
        $cparams[] = $sid;

        $where .= " AND ($cond)";
        $params = array_merge($params, $cparams);

        // Enrollment restriction (same rule as the student portal): only show
        // admit cards for courses the student is actually registered in via a
        // course offer. Fully manual / bulk-imported cards (no routine, no
        // offer-subject links) stay visible; an admin override always bypasses.
        $enroll_parts  = [];
        $enroll_params = [];
        if ($has_routine_col) {
            $enroll_parts[] = '(ac.routine_id IS NULL OR EXISTS (
                    SELECT 1 FROM exam_routine_items i
                    JOIN co_registrations r ON r.offer_subject_id = i.offer_subject_id
                   WHERE i.routine_id = ac.routine_id AND r.student_id = ?))';
            $enroll_params[] = $sid;
        }
        if ($has_subject_col) {
            $enroll_parts[] = '(NOT EXISTS (
                    SELECT 1 FROM ac_admit_card_courses cc2
                   WHERE cc2.admit_card_id = ac.id AND cc2.offer_subject_id IS NOT NULL)
                OR EXISTS (
                    SELECT 1 FROM ac_admit_card_courses cc3
                    JOIN co_registrations r3 ON r3.offer_subject_id = cc3.offer_subject_id
                   WHERE cc3.admit_card_id = ac.id AND r3.student_id = ?))';
            $enroll_params[] = $sid;
        }
        if ($enroll_parts) {
            $where .= ' AND (ac.id IN (SELECT admit_card_id FROM ac_student_overrides WHERE student_id = ?)
                         OR (' . implode(' AND ', $enroll_parts) . '))';
            $params = array_merge($params, [$sid], $enroll_params);
        }
    } else {
        $where .= ' AND 1=0'; // unknown student → no results
    }
}
if ($f_search !== '') {
    $where .= ' AND (ac.exam_name LIKE ? OR ac.semester LIKE ? OR d.name LIKE ? OR p.program_name LIKE ?';
    $like = '%' . $f_search . '%';
    $params = array_merge($params, [$like, $like, $like, $like]);
    if (ctype_digit($f_search)) {
        // A purely numeric search also matches the card ID
        $where .= ' OR ac.id = ?';
        $params[] = (int)$f_search;
    }
    $where .= ')';
}

// ── Structured filters ─────────────────────────────────────────────────
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_from)) $f_from = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_to))   $f_to   = '';
if ($f_dept    > 0) { $where .= ' AND ac.dept_id = ?';    $params[] = $f_dept; }
if ($f_program > 0) { $where .= ' AND ac.program_id = ?'; $params[] = $f_program; }
if ($f_batch   > 0) { $where .= ' AND ac.batch_id = ?';   $params[] = $f_batch; }
if ($f_sem !== '')  { $where .= ' AND ac.semester = ?';   $params[] = $f_sem; }
if ($f_status === '1' || $f_status === '0') { $where .= ' AND ac.is_active = ?'; $params[] = (int)$f_status; }
if ($f_course !== '') {
    $where .= ' AND EXISTS (SELECT 1 FROM ac_admit_card_courses cf
                             WHERE cf.admit_card_id = ac.id
                               AND (cf.course_code LIKE ? OR cf.course_title LIKE ?))';
    $clike    = '%' . $f_course . '%';
    $params[] = $clike;
    $params[] = $clike;
}
if ($f_from !== '' || $f_to !== '') {
    $dcond = [];
    if ($f_from !== '') { $dcond[] = 'df.exam_date >= ?'; }
    if ($f_to   !== '') { $dcond[] = 'df.exam_date <= ?'; }
    $where .= ' AND EXISTS (SELECT 1 FROM ac_admit_card_courses df
                             WHERE df.admit_card_id = ac.id AND ' . implode(' AND ', $dcond) . ')';
    if ($f_from !== '') $params[] = $f_from;
    if ($f_to   !== '') $params[] = $f_to;
}

// Dropdown data for the filter bar
$flt_depts    = $db->query('SELECT id, name FROM dept_departments ORDER BY name ASC')->fetchAll();
$flt_programs = $db->query('SELECT id, program_name FROM dept_academic_programs ORDER BY program_name ASC')->fetchAll();
$flt_batches  = $db->query('SELECT id, name FROM student_batches ORDER BY sort_order ASC, name ASC')->fetchAll();
$flt_sems     = $db->query("SELECT DISTINCT semester FROM ac_admit_cards WHERE semester IS NOT NULL AND semester <> '' ORDER BY semester ASC")->fetchAll(PDO::FETCH_COLUMN);

// Query string with every active filter (pagination links reuse it)
$flt_qs = http_build_query(array_filter([
    'search'     => $f_search,
    'student_id' => $f_student,
    'course'     => $f_course,
    'dept_id'    => $f_dept    ?: '',
    'program_id' => $f_program ?: '',
    'batch_id'   => $f_batch   ?: '',
    'semester'   => $f_sem,
    'status'     => $f_status,
    'date_from'  => $f_from,
    'date_to'    => $f_to,
], static fn($v) => $v !== '' && $v !== null));
$has_filters = $flt_qs !== '';

$cnt_stmt = $db->prepare(
    "SELECT COUNT(*) FROM ac_admit_cards ac
     JOIN dept_departments d ON d.id = ac.dept_id
     JOIN dept_academic_programs p ON p.id = ac.program_id
     WHERE $where"
);
$cnt_stmt->execute($params);
$total = (int)$cnt_stmt->fetchColumn();
$pages = (int)ceil($total / $per_page);

// ── Student counts ──────────────────────────────────────────────────────
// Per card: registered ACTIVE students of the card's linked course-offer
// subjects. Manual / bulk cards without subject links fall back to the
// active students of the card's dept + program (+ batch when set).
$has_subject_col_g = false;
try { $db->query('SELECT offer_subject_id FROM ac_admit_card_courses LIMIT 1'); $has_subject_col_g = true; } catch (Throwable $e) {}
$student_cnt_sql = $has_subject_col_g
    ? "CASE WHEN EXISTS (SELECT 1 FROM ac_admit_card_courses ccx
                          WHERE ccx.admit_card_id = ac.id AND ccx.offer_subject_id IS NOT NULL)
            THEN (SELECT COUNT(DISTINCT r.student_id)
                    FROM ac_admit_card_courses cc
                    JOIN co_registrations r ON r.offer_subject_id = cc.offer_subject_id
                    JOIN students s ON s.id = r.student_id
                   WHERE cc.admit_card_id = ac.id AND s.status = 'Active')
            ELSE (SELECT COUNT(*) FROM students s2
                   WHERE s2.dept_id = ac.dept_id AND s2.program_id = ac.program_id
                     AND (ac.batch_id IS NULL OR s2.batch_id = ac.batch_id)
                     AND s2.status = 'Active')
       END"
    : "(SELECT COUNT(*) FROM students s2
         WHERE s2.dept_id = ac.dept_id AND s2.program_id = ac.program_id
           AND (ac.batch_id IS NULL OR s2.batch_id = ac.batch_id)
           AND s2.status = 'Active')";

$sum_stmt = $db->prepare(
    "SELECT COALESCE(SUM(sc), 0) FROM (
        SELECT $student_cnt_sql AS sc
          FROM ac_admit_cards ac
          JOIN dept_departments d ON d.id = ac.dept_id
          JOIN dept_academic_programs p ON p.id = ac.program_id
         WHERE $where) t"
);
$sum_stmt->execute($params);
$total_students = (int)$sum_stmt->fetchColumn();

$rows_stmt = $db->prepare(
    "SELECT ac.*,
            d.name AS dept_name,
            p.program_name,
            b.name AS batch_name_db,
            u.full_name AS created_by_name,
            (SELECT COUNT(*) FROM ac_admit_card_courses cc WHERE cc.admit_card_id = ac.id) AS course_count,
            $student_cnt_sql AS student_count
     FROM ac_admit_cards ac
     JOIN dept_departments d ON d.id = ac.dept_id
     JOIN dept_academic_programs p ON p.id = ac.program_id
     LEFT JOIN student_batches b ON b.id = ac.batch_id
     LEFT JOIN users u ON u.id = ac.created_by
     WHERE $where
     ORDER BY ac.created_at DESC
     LIMIT $per_page OFFSET $offset"
);
$rows_stmt->execute($params);
$rows = $rows_stmt->fetchAll();

// Bulk toolbar / checkboxes: visible with either edit (status) or delete permission
$can_bulk = ac_can_edit() || ac_can_delete();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-id-card me-2 text-primary"></i>Admit Cards</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item active">Admit Cards</li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= APP_URL ?>/admit-card/conflicts.php" class="btn btn-outline-warning" style="border-radius:10px;">
            <i class="fas fa-flag me-1"></i> Clash Report
        </a>
        <a href="<?= APP_URL ?>/admit-card/missing-schedule.php" class="btn btn-outline-danger" style="border-radius:10px;">
            <i class="fas fa-user-clock me-1"></i> Unscheduled Courses
        </a>
        <?php if (ac_can_create()): ?>
        <a href="<?= APP_URL ?>/admit-card/bulk-import.php" class="btn btn-outline-primary" style="border-radius:10px;">
            <i class="fas fa-file-csv me-1"></i> Bulk Import CSV
        </a>
        <a href="<?= APP_URL ?>/admit-card/create.php" class="btn btn-primary" style="border-radius:10px;">
            <i class="fas fa-plus me-1"></i> Generate Admit Cards
        </a>
        <?php endif; ?>
    </div>
</div>

<?php flash_show(); ?>

<!-- Totals -->
<div class="d-flex flex-wrap gap-2 mb-3">
    <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle px-3 py-2">
        <i class="fas fa-id-card me-1"></i><?= (int)$total ?> admit card batch(es)
    </span>
    <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle px-3 py-2">
        <i class="fas fa-user-graduate me-1"></i><?= number_format($total_students) ?> student admit card(s) generated
    </span>
</div>

<!-- Search -->
<div class="card mb-4">
    <div class="card-body py-3 px-4">
        <form method="get" class="row g-2">
            <div class="col-md-4">
                <label class="form-label small fw-semibold mb-1">Search</label>
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="Exam name, semester, dept, program or card #" value="<?= h($f_search) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-semibold mb-1">Course</label>
                <input type="text" name="course" class="form-control form-control-sm"
                       placeholder="Course code or title (e.g. EEE 1105)" value="<?= h($f_course) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-semibold mb-1">Student ID</label>
                <input type="text" name="student_id" class="form-control form-control-sm"
                       placeholder="e.g. 123-456-789" value="<?= h($f_student) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1">Department</label>
                <select name="dept_id" class="form-select form-select-sm">
                    <option value="">All departments</option>
                    <?php foreach ($flt_depts as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= $f_dept === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1">Program</label>
                <select name="program_id" class="form-select form-select-sm">
                    <option value="">All programs</option>
                    <?php foreach ($flt_programs as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= $f_program === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['program_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Batch</label>
                <select name="batch_id" class="form-select form-select-sm">
                    <option value="">All batches</option>
                    <?php foreach ($flt_batches as $b): ?>
                    <option value="<?= (int)$b['id'] ?>" <?= $f_batch === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Semester</label>
                <select name="semester" class="form-select form-select-sm">
                    <option value="">All semesters</option>
                    <?php foreach ($flt_sems as $s): ?>
                    <option value="<?= h($s) ?>" <?= $f_sem === $s ? 'selected' : '' ?>><?= h($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="1" <?= $f_status === '1' ? 'selected' : '' ?>>Active</option>
                    <option value="0" <?= $f_status === '0' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1">Exam date from</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= h($f_from) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1">Exam date to</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= h($f_to) ?>">
            </div>
            <div class="col-md-6 d-flex align-items-end gap-2">
                <button class="btn btn-sm btn-outline-primary" style="border-radius:8px;">
                    <i class="fas fa-filter me-1"></i>Apply Filters
                </button>
                <?php if ($has_filters): ?>
                <a href="?" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;">Clear</a>
                <?php endif; ?>
            </div>
        </form>
        <?php if ($f_student !== ''): ?>
            <?php if ($f_student_row): ?>
            <div class="alert alert-info small mt-3 mb-0">
                Showing all admit cards (including <strong>inactive</strong> ones) for
                <strong><?= h($f_student_row['full_name']) ?></strong>
                (ID: <strong><?= h($f_student_row['student_id']) ?></strong>,
                Status: <?= h($f_student_row['status']) ?>).
                Click <span class="badge bg-success"><i class="fas fa-download me-1"></i>PDF</span>
                on a row to download this student's admit card.
            </div>
            <?php else: ?>
            <div class="alert alert-warning small mt-3 mb-0">
                No student found with ID “<?= h($f_student) ?>”.
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Table -->
<form method="POST" action="?<?= h($flt_qs) ?>" id="bulk_form">
    <?= csrf_field() ?>
<div class="card">
    <?php if ($can_bulk): ?>
    <div class="card-header py-2 px-4 d-flex flex-wrap align-items-center gap-2">
        <span class="small fw-semibold text-muted"><i class="fas fa-tasks me-1"></i>Bulk actions:</span>
        <?php if (ac_can_edit()): ?>
        <button type="submit" name="bulk_action" value="activate" class="btn btn-sm btn-outline-success" style="border-radius:8px;"
                onclick="return confirm('Activate the selected admit card(s)? They become visible to students.')">
            <i class="fas fa-toggle-on me-1"></i>Activate selected
        </button>
        <button type="submit" name="bulk_action" value="deactivate" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;"
                onclick="return confirm('Deactivate the selected admit card(s)? They are hidden from students.')">
            <i class="fas fa-toggle-off me-1"></i>Deactivate selected
        </button>
        <?php endif; ?>
        <?php if (ac_can_delete()): ?>
        <button type="submit" name="bulk_action" value="delete" class="btn btn-sm btn-outline-danger" style="border-radius:8px;"
                onclick="return confirm('DELETE the selected admit card(s)? This permanently removes the card(s) and their course rows. This cannot be undone.')">
            <i class="fas fa-trash me-1"></i>Delete selected
        </button>
        <?php endif; ?>
        <span class="small text-muted ms-auto"><span id="bulk_count">0</span> selected</span>
    </div>
    <?php endif; ?>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <?php if ($can_bulk): ?>
                        <th class="ps-4" style="width:36px;">
                            <input type="checkbox" class="form-check-input" id="bulk_all" title="Select all on this page">
                        </th>
                        <?php endif; ?>
                        <th class="px-4">#</th>
                        <th>Exam Name</th>
                        <th>Semester</th>
                        <th>Dept / Program</th>
                        <th>Batch</th>
                        <th>Courses</th>
                        <th>Students</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="<?= $can_bulk ? 11 : 10 ?>" class="text-center text-muted py-5">No admit cards found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                    <tr>
                        <?php if ($can_bulk): ?>
                        <td class="ps-4">
                            <input type="checkbox" class="form-check-input bulk-sel" name="card_ids[]" value="<?= (int)$row['id'] ?>">
                        </td>
                        <?php endif; ?>
                        <td class="px-4"><?= (int)$row['id'] ?></td>
                        <td class="fw-semibold"><?= h($row['exam_name']) ?></td>
                        <td><?= h($row['semester']) ?></td>
                        <td>
                            <div><?= h($row['dept_name']) ?></div>
                            <small class="text-muted"><?= h($row['program_name']) ?></small>
                        </td>
                        <td><?= h($row['batch_label'] ?? ($row['batch_name_db'] ?? '—')) ?></td>
                        <td><span class="badge bg-secondary"><?= (int)$row['course_count'] ?></span></td>
                        <td>
                            <?php $elig = ac_card_eligibility_summary((int)$row['id']); ?>
                            <div class="d-flex flex-column gap-1">
                                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle"
                                      title="Total students covered by this admit card">
                                    <i class="fas fa-users me-1"></i>Total: <?= (int)$elig['total'] ?>
                                </span>
                                <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle"
                                      title="Due as of today is within ৳<?= number_format(AC_DUE_THRESHOLD) ?> — eligible to download">
                                    <i class="fas fa-circle-check me-1"></i>Eligible: <?= (int)$elig['eligible'] ?>
                                </span>
                                <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle"
                                      title="Due as of today exceeds ৳<?= number_format(AC_DUE_THRESHOLD) ?> — not eligible to download">
                                    <i class="fas fa-circle-xmark me-1"></i>Not eligible: <?= (int)$elig['blocked'] ?>
                                </span>
                            </div>
                        </td>
                        <td>
                            <?php if ($row['is_active']): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small"><?= date('d M Y', strtotime($row['created_at'])) ?></td>
                        <td>
                            <div class="d-flex gap-1">
                                <?php if ($f_student_row): ?>
                                <a href="<?= APP_URL ?>/admit-card/download.php?card=<?= $row['id'] ?>&student=<?= (int)$f_student_row['id'] ?>"
                                   class="btn btn-sm btn-success"
                                   title="Download <?= h($f_student_row['full_name']) ?>'s admit card PDF">
                                    <i class="fas fa-download me-1"></i>PDF
                                </a>
                                <?php endif; ?>
                                <a href="<?= APP_URL ?>/admit-card/view.php?id=<?= $row['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" title="View">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if (ac_can_edit()): ?>
                                <a href="<?= APP_URL ?>/admit-card/edit.php?id=<?= $row['id'] ?>"
                                   class="btn btn-sm btn-outline-secondary" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (ac_can_delete()): ?>
                                <a href="<?= APP_URL ?>/admit-card/delete.php?id=<?= $row['id'] ?>"
                                   class="btn btn-sm btn-outline-danger" title="Delete"
                                   onclick="return confirm('Delete this admit card? This cannot be undone.')">
                                    <i class="fas fa-trash"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($pages > 1): ?>
    <div class="card-footer d-flex align-items-center justify-content-between">
        <small class="text-muted">Showing page <?= $cur_page ?> of <?= $pages ?> (<?= $total ?> records)</small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php for ($p = 1; $p <= $pages; $p++): ?>
                <li class="page-item <?= $p === $cur_page ? 'active' : '' ?>">
                    <a class="page-link" href="?page=<?= $p ?><?= $flt_qs !== '' ? '&' . h($flt_qs) : '' ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>
</form>

<script>
(function () {
    var all   = document.getElementById('bulk_all');
    var boxes = document.querySelectorAll('.bulk-sel');
    var count = document.getElementById('bulk_count');
    function upd() {
        var n = 0;
        boxes.forEach(function (b) { if (b.checked) n++; });
        if (count) count.textContent = n;
    }
    if (all) all.addEventListener('change', function () {
        boxes.forEach(function (b) { b.checked = all.checked; });
        upd();
    });
    boxes.forEach(function (b) { b.addEventListener('change', upd); });
    var form = document.getElementById('bulk_form');
    if (form) form.addEventListener('submit', function (e) {
        var any = false;
        boxes.forEach(function (b) { if (b.checked) any = true; });
        if (!any) { e.preventDefault(); alert('Select at least one admit card first.'); }
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
