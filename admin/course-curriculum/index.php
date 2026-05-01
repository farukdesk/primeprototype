<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';

$page_title = 'Course Curriculum';

// ── Resolve selected department and program from GET ─────────────────────────
$sel_dept    = (int)($_GET['dept_id']    ?? 0);
$sel_program = (int)($_GET['program_id'] ?? 0);

$departments = cc_departments();
$programs    = $sel_dept > 0 ? cc_programs($sel_dept) : [];

// ── Validate program ─────────────────────────────────────────────────────────
$program_row = null;
if ($sel_program > 0 && $sel_dept > 0) {
    $st = db()->prepare(
        "SELECT p.*, d.name AS dept_name
           FROM dept_academic_programs p
           JOIN dept_departments d ON d.id = p.dept_id
          WHERE p.id = ? AND p.dept_id = ? AND p.is_active = 1
          LIMIT 1"
    );
    $st->execute([$sel_program, $sel_dept]);
    $program_row = $st->fetch() ?: null;
}

// ── Search / Filter params ────────────────────────────────────────────────────
$search          = trim($_GET['search']          ?? '');
$semester_filter = (int)($_GET['semester_filter'] ?? 0);  // 0=all, -1=unassigned, 1-12
$teacher_filter  = (int)($_GET['teacher_filter']  ?? 0);  // 0=all, -1=unassigned, >0 faculty id
$per_page        = 20;
$cur_page        = max(1, (int)($_GET['page'] ?? 1));

// ── Load data for the selected program ───────────────────────────────────────
$subjects         = [];
$distributions    = [];
$total_subjects   = 0;
$total_credits    = 0;
$filtered_result  = ['rows' => [], 'total' => 0];
$teacher_report   = [];
$dept_faculty     = [];
$semester_labels  = cc_semester_labels();

if ($program_row) {
    // Overall totals (unfiltered, for the stats row)
    $prog_stats = db()->prepare(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(credit), 0) AS creds
           FROM course_curriculum WHERE program_id = ?"
    );
    $prog_stats->execute([$sel_program]);
    $prog_stats = $prog_stats->fetch();
    $total_subjects = (int)$prog_stats['cnt'];
    $total_credits  = (float)$prog_stats['creds'];

    // Filtered + paginated subject list
    $filters = [
        'search'     => $search,
        'semester'   => $semester_filter,
        'teacher_id' => $teacher_filter,
    ];
    $filtered_result = cc_get_subjects_filtered($sel_program, $filters, $cur_page, $per_page);
    $subjects        = $filtered_result['rows'];

    if (!empty($subjects)) {
        $distributions = cc_get_all_mark_distributions(array_column($subjects, 'id'));
    }

    // Faculty list for filter dropdown
    $dept_faculty = cc_get_dept_faculty($sel_dept);

    // Teacher report
    $teacher_report = cc_teacher_report($sel_program);
}

require_once __DIR__ . '/../includes/header.php';
?>

<!-- ── Breadcrumb & top action ────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Course Curriculum</li>
        </ol>
    </nav>
    <?php if ($program_row && cc_is_staff()): ?>
    <a href="<?= APP_URL ?>/course-curriculum/create.php?dept_id=<?= $sel_dept ?>&program_id=<?= $sel_program ?>"
       class="btn btn-primary btn-sm">
        <i class="fas fa-plus me-1"></i> Add Subject
    </a>
    <?php endif; ?>
</div>

<?php flash_show(); ?>

<!-- ── Selector card ───────────────────────────────────────────────────────── -->
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-filter me-2 text-muted"></i>Select Department &amp; Program
        </h6>
    </div>
    <div class="card-body p-4">
        <form method="GET" action="" id="selector-form" class="row g-3 align-items-end">
            <div class="col-12 col-md-5">
                <label class="form-label fw-medium">Department <span class="text-danger">*</span></label>
                <select name="dept_id" id="dept_select" class="form-select" required>
                    <option value="">— Select Department —</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $d['id'] == $sel_dept ? 'selected' : '' ?>>
                        <?= h($d['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-5">
                <label class="form-label fw-medium">Program <span class="text-danger">*</span></label>
                <select name="program_id" id="prog_select" class="form-select" required
                        <?= $sel_dept ? '' : 'disabled' ?>>
                    <option value="">— Select Program —</option>
                    <?php foreach ($programs as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $p['id'] == $sel_program ? 'selected' : '' ?>>
                        <?= h($p['program_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-eye me-1"></i> View
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($sel_dept && $sel_program && !$program_row): ?>
<div class="alert alert-warning">Program not found or inactive.</div>
<?php endif; ?>

<?php if ($program_row): ?>
<!-- ══════════════════════════════════════════════════════════════════════════
     CURRICULUM VIEW — flat subject list
     ══════════════════════════════════════════════════════════════════════════ -->

<!-- Program header -->
<div class="card mb-4" style="border-radius:12px; border-left:4px solid #002147;">
    <div class="card-body px-4 py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="mb-1 fw-bold" style="color:#002147;"><?= h($program_row['program_name']) ?></h5>
            <span class="text-muted small">
                <i class="fas fa-building me-1"></i><?= h($program_row['dept_name']) ?>
                <?php if (!empty($program_row['degree_type'])): ?>
                &nbsp;·&nbsp;<span class="badge bg-secondary"><?= h($program_row['degree_type']) ?></span>
                <?php endif; ?>
                <?php if (!empty($program_row['duration'])): ?>
                &nbsp;·&nbsp;<?= h($program_row['duration']) ?>
                <?php endif; ?>
            </span>
        </div>
        <?php if (cc_is_staff()): ?>
        <a href="<?= APP_URL ?>/course-curriculum/create.php?dept_id=<?= $sel_dept ?>&program_id=<?= $sel_program ?>"
           class="btn btn-success btn-sm">
            <i class="fas fa-plus me-1"></i> Add Subject
        </a>
        <?php endif; ?>
    </div>
</div>

<?php
// $total_subjects and $total_credits are already set from the DB query above.
$filtered_total = $filtered_result['total'];
$total_pages    = (int)ceil($filtered_total / $per_page);
$has_filters    = ($search !== '' || $semester_filter !== 0 || $teacher_filter !== 0);
// Build base URL for pagination/filter links
$base_url_parts = ['dept_id' => $sel_dept, 'program_id' => $sel_program];
if ($search !== '')         $base_url_parts['search']          = $search;
if ($semester_filter !== 0) $base_url_parts['semester_filter'] = $semester_filter;
if ($teacher_filter  !== 0) $base_url_parts['teacher_filter']  = $teacher_filter;
function cc_page_url(array $base, int $page): string {
    return '?' . http_build_query(array_merge($base, ['page' => $page]));
}
?>

<!-- ── Search / Filter Bar ───────────────────────────────────────────────── -->
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-search me-2 text-muted"></i>Search &amp; Filter Subjects
        </h6>
    </div>
    <div class="card-body p-4">
        <form method="GET" action="" id="filter-form" class="row g-3 align-items-end">
            <input type="hidden" name="dept_id"    value="<?= $sel_dept ?>">
            <input type="hidden" name="program_id" value="<?= $sel_program ?>">

            <!-- Search by code or title -->
            <div class="col-12 col-md-4">
                <label class="form-label fw-medium">Search by Code / Title</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" name="search" id="search-input" class="form-control"
                           placeholder="e.g. CSE 101 or Algorithms"
                           value="<?= h($search) ?>"
                           autocomplete="off">
                    <?php if ($search !== ''): ?>
                    <button type="button" class="btn btn-outline-secondary" id="clear-search" title="Clear search">
                        <i class="fas fa-times"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Filter by semester -->
            <div class="col-12 col-md-3">
                <label class="form-label fw-medium">Semester</label>
                <select name="semester_filter" class="form-select">
                    <option value="0" <?= $semester_filter === 0  ? 'selected' : '' ?>>— All Semesters —</option>
                    <option value="-1" <?= $semester_filter === -1 ? 'selected' : '' ?>>Unassigned</option>
                    <?php foreach ($semester_labels as $n => $lbl): ?>
                    <option value="<?= $n ?>" <?= $semester_filter === $n ? 'selected' : '' ?>><?= h($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Filter by teacher -->
            <div class="col-12 col-md-3">
                <label class="form-label fw-medium">Course Teacher</label>
                <select name="teacher_filter" class="form-select" id="teacher-filter-select">
                    <option value="0"  <?= $teacher_filter === 0  ? 'selected' : '' ?>>— All Teachers —</option>
                    <option value="-1" <?= $teacher_filter === -1 ? 'selected' : '' ?>>Not Assigned</option>
                    <?php foreach ($dept_faculty as $f): ?>
                    <option value="<?= $f['id'] ?>" <?= $teacher_filter === (int)$f['id'] ? 'selected' : '' ?>>
                        <?= h($f['name']) ?><?= $f['designation'] ? ' — ' . h($f['designation']) : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Buttons -->
            <div class="col-12 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill">
                    <i class="fas fa-filter me-1"></i> Apply
                </button>
                <?php if ($has_filters): ?>
                <a href="?dept_id=<?= $sel_dept ?>&program_id=<?= $sel_program ?>"
                   class="btn btn-outline-secondary" title="Clear filters">
                    <i class="fas fa-times"></i>
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Stats row -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="card text-center border-0 shadow-sm" style="border-radius:10px;">
            <div class="card-body py-3">
                <div class="fw-bold fs-4" style="color:#002147;"><?= $total_subjects ?></div>
                <div class="small text-muted">Total Subjects</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card text-center border-0 shadow-sm" style="border-radius:10px;">
            <div class="card-body py-3">
                <div class="fw-bold fs-4" style="color:#D21034;"><?= number_format($total_credits, 2) ?></div>
                <div class="small text-muted">Total Credits</div>
            </div>
        </div>
    </div>
    <?php if ($has_filters): ?>
    <div class="col-6 col-md-4">
        <div class="card text-center border-0 shadow-sm" style="border-radius:10px; border:1px solid #c3d8f5;">
            <div class="card-body py-3">
                <div class="fw-bold fs-4" style="color:#4f8ef7;"><?= $filtered_total ?></div>
                <div class="small text-muted">Filtered Results</div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ── Subject table ──────────────────────────────────────────────────────── -->
<div class="card" style="border-radius:12px;">
    <div class="card-header px-4 py-3" style="background-color:#002147; border-radius:12px 12px 0 0;">
        <span class="fw-semibold text-white">
            <i class="fas fa-list me-2"></i>Subjects
            <?php if ($filtered_total > 0): ?>
            <span class="badge bg-light text-dark ms-2 small">
                <?= $filtered_total ?> subject<?= $filtered_total !== 1 ? 's' : '' ?>
                <?php if (!$has_filters): ?>
                &nbsp;·&nbsp;<?= number_format($total_credits, 2) ?> cr
                <?php endif; ?>
            </span>
            <?php endif; ?>
            <?php if ($has_filters): ?>
            <span class="badge bg-warning text-dark ms-1 small">
                <i class="fas fa-filter me-1"></i>Filtered
            </span>
            <?php endif; ?>
        </span>
    </div>

    <?php if (empty($subjects)): ?>
    <div class="card-body text-center text-muted py-5">
        <i class="fas fa-book-open fa-2x mb-3 d-block" style="opacity:.3;"></i>
        <?php if ($has_filters): ?>
        No subjects match your search or filter criteria.
        <div class="mt-2">
            <a href="?dept_id=<?= $sel_dept ?>&program_id=<?= $sel_program ?>" class="btn btn-sm btn-outline-secondary">
                <i class="fas fa-times me-1"></i>Clear filters
            </a>
        </div>
        <?php else: ?>
        No subjects added yet.
        <?php if (cc_is_staff()): ?>
        <a href="<?= APP_URL ?>/course-curriculum/create.php?dept_id=<?= $sel_dept ?>&program_id=<?= $sel_program ?>">Add the first subject</a>
        <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle" style="font-size:14px;">
            <thead style="background-color:#F1F5F9;">
                <tr>
                    <th style="width:50px;" class="ps-4">SL</th>
                    <th style="width:110px;">Subject Code</th>
                    <th>Title</th>
                    <th style="width:180px;">Course Teacher</th>
                    <th style="width:70px;" class="text-center">Total Credit</th>
                    <th>Marking Distribution</th>
                    <?php if (cc_is_staff()): ?>
                    <th style="width:100px;" class="text-end pe-4">Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($subjects as $row): ?>
                <?php $dists = $distributions[(int)$row['id']] ?? []; ?>
                <tr>
                    <td class="ps-4"><?= h($row['sl_no']) ?></td>
                    <td><?= $row['course_code'] ? '<span class="badge bg-light text-dark border">' . h($row['course_code']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
                    <td class="fw-medium">
                        <?= h($row['course_name']) ?>
                        <?php if ($row['bnqf_code']): ?>
                        <span class="text-muted small ms-1">(<?= h($row['bnqf_code']) ?>)</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($row['faculty_name'])): ?>
                        <span class="badge bg-info text-dark">
                            <i class="fas fa-user-tie me-1"></i><?= h($row['faculty_name']) ?>
                        </span>
                        <?php else: ?>
                        <span class="text-muted small">— not assigned —</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?= $row['credit'] !== null
                            ? '<span class="badge" style="background-color:#002147;">' . h(rtrim(rtrim(number_format((float)$row['credit'], 2), '0'), '.')) . '</span>'
                            : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td>
                        <?php if (!empty($dists)): ?>
                        <div class="d-flex flex-wrap gap-1">
                            <?php foreach ($dists as $dist): ?>
                            <span class="badge rounded-pill" style="background-color:#EEF2FF; color:#3730A3; font-size:11px;">
                                <?= h($dist['distribution_name']) ?>:&nbsp;<?= h(rtrim(rtrim(number_format((float)$dist['max_marks'], 2), '0'), '.')) ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <span class="text-muted small">— not set —</span>
                        <?php endif; ?>
                    </td>
                    <?php if (cc_is_staff()): ?>
                    <td class="text-end pe-4">
                        <a href="<?= APP_URL ?>/course-curriculum/edit.php?id=<?= $row['id'] ?>&dept_id=<?= $sel_dept ?>&program_id=<?= $sel_program ?>"
                           class="btn btn-sm btn-outline-primary me-1" title="Edit">
                            <i class="fas fa-edit"></i>
                        </a>
                        <button type="button"
                                class="btn btn-sm btn-outline-danger"
                                title="Delete"
                                data-bs-toggle="modal" data-bs-target="#deleteModal"
                                data-id="<?= $row['id'] ?>"
                                data-name="<?= h($row['course_name']) ?>">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot style="background-color:#F8FAFC;">
                <tr>
                    <td colspan="4" class="ps-4 text-muted small">
                        <?php if ($has_filters): ?>
                        <?= $filtered_total ?> result<?= $filtered_total !== 1 ? 's' : '' ?> (of <?= $total_subjects ?> total)
                        <?php else: ?>
                        <?= $total_subjects ?> subject<?= $total_subjects !== 1 ? 's' : '' ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-center fw-bold small" style="color:#002147;">
                        <?= number_format($total_credits, 2) ?>
                    </td>
                    <td></td>
                    <?php if (cc_is_staff()): ?><td></td><?php endif; ?>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- ── Pagination ──────────────────────────────────────────────────────── -->
    <?php if ($total_pages > 1): ?>
    <div class="card-footer py-3 px-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <small class="text-muted">
                Showing <?= (($cur_page - 1) * $per_page) + 1 ?>–<?= min($cur_page * $per_page, $filtered_total) ?>
                of <?= $filtered_total ?> subject<?= $filtered_total !== 1 ? 's' : '' ?>
            </small>
            <nav aria-label="Subject pagination">
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= $cur_page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= h(cc_page_url($base_url_parts, $cur_page - 1)) ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    </li>
                    <?php
                    $start_p = max(1, $cur_page - 2);
                    $end_p   = min($total_pages, $cur_page + 2);
                    if ($start_p > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="<?= h(cc_page_url($base_url_parts, 1)) ?>">1</a>
                    </li>
                    <?php if ($start_p > 2): ?>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php for ($p = $start_p; $p <= $end_p; $p++): ?>
                    <li class="page-item <?= $p === $cur_page ? 'active' : '' ?>">
                        <a class="page-link" href="<?= h(cc_page_url($base_url_parts, $p)) ?>"><?= $p ?></a>
                    </li>
                    <?php endfor; ?>
                    <?php if ($end_p < $total_pages): ?>
                    <?php if ($end_p < $total_pages - 1): ?>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                    <?php endif; ?>
                    <li class="page-item">
                        <a class="page-link" href="<?= h(cc_page_url($base_url_parts, $total_pages)) ?>"><?= $total_pages ?></a>
                    </li>
                    <?php endif; ?>
                    <li class="page-item <?= $cur_page >= $total_pages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= h(cc_page_url($base_url_parts, $cur_page + 1)) ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php if (cc_is_staff()): ?>
<!-- Delete subject confirmation modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:14px;">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteModalLabel">
                    <i class="fas fa-exclamation-triangle text-danger me-2"></i>Delete Subject
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                Are you sure you want to delete <strong id="del-name"></strong>? This action cannot be undone.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="<?= APP_URL ?>/course-curriculum/delete.php" id="del-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id"         id="del-id">
                    <input type="hidden" name="dept_id"    value="<?= $sel_dept ?>">
                    <input type="hidden" name="program_id" value="<?= $sel_program ?>">
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-1"></i>Delete
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Teacher Course-Load Report ─────────────────────────────────────────── -->
<?php if (!empty($teacher_report)): ?>
<div class="card mt-4" style="border-radius:12px;">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center"
         style="cursor:pointer;" data-bs-toggle="collapse" data-bs-target="#teacherReportBody"
         aria-expanded="false" aria-controls="teacherReportBody">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-chart-bar me-2 text-muted"></i>Teacher Course-Load Report
            <span class="badge bg-secondary ms-2 small"><?= count($teacher_report) ?> teacher<?= count($teacher_report) !== 1 ? 's' : '' ?></span>
        </h6>
        <i class="fas fa-chevron-down text-muted small" id="report-chevron"></i>
    </div>
    <div class="collapse" id="teacherReportBody">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle" style="font-size:14px;">
                <thead style="background-color:#F1F5F9;">
                    <tr>
                        <th class="ps-4" style="width:40px;">#</th>
                        <th>Teacher Name</th>
                        <th>Designation</th>
                        <th class="text-center" style="width:120px;">Courses Assigned</th>
                        <th class="text-center" style="width:120px;">Total Credits</th>
                        <th style="width:200px;">Distribution</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($teacher_report as $ri => $tr_row): ?>
                    <?php $max_count = (int)$teacher_report[0]['course_count']; ?>
                    <tr>
                        <td class="ps-4 text-muted"><?= $ri + 1 ?></td>
                        <td class="fw-medium">
                            <?php if ($tr_row['faculty_name']): ?>
                            <i class="fas fa-user-tie me-1 text-muted"></i><?= h($tr_row['faculty_name']) ?>
                            <?php else: ?>
                            <span class="text-muted fst-italic">— Unassigned —</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small"><?= $tr_row['designation'] ? h($tr_row['designation']) : '—' ?></td>
                        <td class="text-center">
                            <span class="badge" style="background-color:#002147; font-size:13px;">
                                <?= (int)$tr_row['course_count'] ?>
                            </span>
                            <?php if ($tr_row['faculty_id']): ?>
                            <a href="?dept_id=<?= $sel_dept ?>&program_id=<?= $sel_program ?>&teacher_filter=<?= (int)$tr_row['faculty_id'] ?>"
                               class="ms-1 btn btn-xs btn-outline-secondary" style="font-size:12px; padding:1px 5px;"
                               title="View this teacher's subjects">
                                <i class="fas fa-filter"></i>
                            </a>
                            <?php else: ?>
                            <a href="?dept_id=<?= $sel_dept ?>&program_id=<?= $sel_program ?>&teacher_filter=-1"
                               class="ms-1 btn btn-xs btn-outline-secondary" style="font-size:12px; padding:1px 5px;"
                               title="View unassigned subjects">
                                <i class="fas fa-filter"></i>
                            </a>
                            <?php endif; ?>
                        </td>
                        <td class="text-center text-muted small">
                            <?= number_format((float)$tr_row['total_credits'], 2) ?>
                        </td>
                        <td>
                            <?php if ($max_count > 0): ?>
                            <div class="progress" style="height:8px; border-radius:4px;">
                                <div class="progress-bar" role="progressbar"
                                     style="width:<?= round((int)$tr_row['course_count'] / $max_count * 100) ?>%;
                                            background-color:<?= $tr_row['faculty_name'] ? '#002147' : '#adb5bd' ?>;"
                                     title="<?= (int)$tr_row['course_count'] ?> course<?= (int)$tr_row['course_count'] !== 1 ? 's' : '' ?>">
                                </div>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot style="background-color:#F8FAFC;">
                    <tr>
                        <td colspan="3" class="ps-4 text-muted small">
                            <?= count($teacher_report) ?> teacher<?= count($teacher_report) !== 1 ? 's' : '' ?>
                            (including unassigned)
                        </td>
                        <td class="text-center fw-bold small" style="color:#002147;">
                            <?= $total_subjects ?>
                        </td>
                        <td class="text-center fw-bold small" style="color:#D21034;">
                            <?= number_format($total_credits, 2) ?>
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php elseif (!$sel_dept || !$sel_program): ?>
<div class="text-center py-5 text-muted">
    <i class="fas fa-graduation-cap fa-3x mb-3 d-block" style="color:#002147; opacity:.3;"></i>
    Select a department and program above to view or manage its course curriculum.
</div>
<?php endif; ?>

<script>
(function () {
    var deptSel = document.getElementById('dept_select');
    var progSel = document.getElementById('prog_select');
    var savedProg = <?= $sel_program ?: 0 ?>;

    deptSel.addEventListener('change', function () {
        var deptId = this.value;
        progSel.innerHTML = '<option value="">Loading…</option>';
        progSel.disabled = true;
        if (!deptId) {
            progSel.innerHTML = '<option value="">— Select Program —</option>';
            return;
        }
        fetch('<?= APP_URL ?>/course-curriculum/get-programs.php?dept_id=' + encodeURIComponent(deptId))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                progSel.innerHTML = '<option value="">— Select Program —</option>';
                data.forEach(function (p) {
                    var opt = document.createElement('option');
                    opt.value = p.id;
                    opt.textContent = p.program_name;
                    if (p.id == savedProg) opt.selected = true;
                    progSel.appendChild(opt);
                });
                progSel.disabled = false;
            })
            .catch(function () {
                progSel.innerHTML = '<option value="">— Error loading programs —</option>';
                progSel.disabled = false;
            });
    });

    <?php if ($sel_dept): ?>
    progSel.disabled = false;
    <?php endif; ?>

    // Delete modal population
    var delModal = document.getElementById('deleteModal');
    if (delModal) {
        delModal.addEventListener('show.bs.modal', function (e) {
            var btn = e.relatedTarget;
            document.getElementById('del-id').value         = btn.dataset.id;
            document.getElementById('del-name').textContent = btn.dataset.name;
        });
    }

    // ── Debounced auto-submit for search input ────────────────────────────────
    var searchInput = document.getElementById('search-input');
    if (searchInput) {
        var debounceTimer;
        searchInput.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function () {
                document.getElementById('filter-form').submit();
            }, 300);
        });
    }

    // Clear search button
    var clearBtn = document.getElementById('clear-search');
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            if (searchInput) searchInput.value = '';
            document.getElementById('filter-form').submit();
        });
    }

    // Teacher filter dropdown auto-submit
    var teacherFilter = document.getElementById('teacher-filter-select');
    if (teacherFilter) {
        teacherFilter.addEventListener('change', function () {
            document.getElementById('filter-form').submit();
        });
    }

    // Teacher report chevron toggle
    var reportBody = document.getElementById('teacherReportBody');
    if (reportBody) {
        var reportHeader = reportBody.previousElementSibling;
        reportBody.addEventListener('show.bs.collapse', function () {
            document.getElementById('report-chevron').style.transform = 'rotate(180deg)';
            if (reportHeader) reportHeader.setAttribute('aria-expanded', 'true');
        });
        reportBody.addEventListener('hide.bs.collapse', function () {
            document.getElementById('report-chevron').style.transform = '';
            if (reportHeader) reportHeader.setAttribute('aria-expanded', 'false');
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
