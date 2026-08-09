<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';

if (!can_access('course-offer')) {
    flash_set('error', 'You do not have permission to access this section.');
    redirect(APP_URL . '/index.php');
}

$page_title = 'Course Offer';

// ── Filters ───────────────────────────────────────────────────────────────────
$f_dept_id        = (int)($_GET['dept_id']         ?? 0);
$f_program_id     = (int)($_GET['program_id']      ?? 0);
$f_batch_id       = (int)($_GET['batch_id']        ?? 0);
$f_semester       = trim($_GET['semester']         ?? '');
$f_academic_intake = trim($_GET['academic_intake'] ?? '');
$f_status         = $_GET['status'] ?? '';
$f_search         = trim($_GET['search']           ?? '');
$per_page         = 50;
$cur_page         = max(1, (int)($_GET['page'] ?? 1));

$filters = [
    'dept_id'         => $f_dept_id,
    'program_id'      => $f_program_id,
    'batch_id'        => $f_batch_id,
    'semester'        => $f_semester,
    'academic_intake' => $f_academic_intake,
    'status'          => $f_status,
    'search'          => $f_search,
];

$result   = co_get_offers_filtered($filters, $cur_page, $per_page);
$offers   = $result['rows'];
$total    = $result['total'];
$pages    = (int)ceil($total / $per_page);

// Dropdown data
$departments      = co_departments();
$programs         = $f_dept_id > 0 ? co_programs($f_dept_id) : [];
$all_batches      = co_student_batches();
$semester_opts    = co_semester_options();
$intake_opts      = co_academic_intake_options();

// Pre-load subjects+teachers for each offer
$offer_ids    = array_column($offers, 'id');
$subjects_map = co_get_subjects_map($offer_ids);

// Offers that already have marks entered — these can never be deleted.
$marks_offer_ids = co_offers_with_marks($offer_ids);

// Group rows by batch for the grouped display
$grouped = [];
foreach ($offers as $row) {
    $key = (int)$row['batch_id'];
    if (!isset($grouped[$key])) {
        $grouped[$key] = ['batch_name' => $row['batch_name'], 'rows' => []];
    }
    $grouped[$key]['rows'][] = $row;
}

$active_filters = count(array_filter($filters, fn($v) => $v !== '' && $v !== 0));

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ── Course Offer listing ─────────────────────────────────────────────── */
.co-card            { border: 0; border-radius: 14px; box-shadow: 0 1px 4px rgba(15,23,42,.07); }
.co-batch-card      { overflow: hidden; }
.co-batch-head      { background: linear-gradient(135deg, #0d6efd12, #0d6efd04); border-bottom: 1px solid #e9ecef; }
.co-batch-icon      { width: 2rem; height: 2rem; font-size: .8rem; }

.co-offer           { border-bottom: 1px solid #f0f2f5; }
.co-offer:last-child{ border-bottom: 0; }
.co-offer-head      { padding: .85rem 1.25rem; }
.co-offer-head:hover{ background: #f8fafc; }

.co-chip            { display: inline-flex; align-items: center; gap: .3rem; font-size: .72rem;
                      font-weight: 500; padding: .16rem .55rem; border-radius: 20rem;
                      background: #f1f3f7; color: #4b5563; border: 1px solid #e5e8ee; white-space: nowrap; }
.co-chip i          { opacity: .55; font-size: .65rem; }
.co-chip-semester   { background: #fff7e6; border-color: #ffe4b3; color: #92600a; }
.co-chip-intake     { background: #eef4ff; border-color: #d8e4fd; color: #2d4f9e; }
.co-chip-shift      { background: #f0fdf4; border-color: #d1f0da; color: #14713d; }
.co-chip-section    { background: #fdf2f8; border-color: #f8d7e8; color: #9d3a6d; }
.co-chip-teacher    { background: #eef4ff; border-color: #d8e4fd; color: #2d4f9e; }

.co-status-dot      { width: .5rem; height: .5rem; border-radius: 50%; display: inline-block; }

.co-subjects        { background: #fafbfd; border-top: 1px dashed #e5e8ee; }
.co-subject         { padding: .6rem 1.25rem .6rem 2.5rem; border-bottom: 1px solid #eef0f4; font-size: .85rem; }
.co-subject:last-child { border-bottom: 0; }
.co-code            { font-family: SFMono-Regular, Menlo, Consolas, monospace; font-size: .7rem;
                      background: #eef2f7; border: 1px solid #e0e6ef; border-radius: 6px;
                      padding: .12rem .45rem; color: #374151; white-space: nowrap; }

.co-toggle          { border: 0; background: transparent; color: #6b7280; font-size: .78rem; }
.co-toggle:hover    { color: #0d6efd; }
.co-toggle .fa-chevron-down { transition: transform .2s ease; }
.co-toggle[aria-expanded="true"] .fa-chevron-down { transform: rotate(180deg); }

.co-actions .btn    { --bs-btn-padding-y: .25rem; --bs-btn-padding-x: .55rem; --bs-btn-font-size: .78rem; }
</style>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
                <li class="breadcrumb-item active">Course Offer</li>
            </ol>
        </nav>
        <h5 class="mb-0 fw-bold">Course Offers</h5>
    </div>
    <?php if (co_can_create()): ?>
    <a href="<?= APP_URL ?>/course-offer/create.php" class="btn btn-primary" style="border-radius:10px;">
        <i class="fas fa-plus me-1"></i> New Course Offer
    </a>
    <?php endif; ?>
</div>

<?php flash_show(); ?>

<!-- ── Filters ────────────────────────────────────────────────────────────── -->
<div class="card co-card mb-4">
    <div class="card-body py-3 px-4">
        <form method="GET" class="row g-2 align-items-end" id="filter-form">
            <div class="col-6 col-md-2">
                <label class="form-label small fw-medium mb-1 text-muted">Department</label>
                <select name="dept_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $f_dept_id == $d['id'] ? 'selected' : '' ?>>
                        <?= h($d['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-medium mb-1 text-muted">Program</label>
                <select name="program_id" class="form-select form-select-sm" onchange="this.form.submit()"
                        <?= $f_dept_id > 0 ? '' : 'disabled title="Select a department first"' ?>>
                    <option value="">All Programs</option>
                    <?php foreach ($programs as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $f_program_id == $p['id'] ? 'selected' : '' ?>>
                        <?= h($p['program_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-medium mb-1 text-muted">Batch</label>
                <select name="batch_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All Batches</option>
                    <?php foreach ($all_batches as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $f_batch_id == $b['id'] ? 'selected' : '' ?>>
                        <?= h($b['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-medium mb-1 text-muted">Semester</label>
                <select name="semester" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All Semesters</option>
                    <?php foreach ($semester_opts as $s): ?>
                    <option value="<?= h($s) ?>" <?= $f_semester === $s ? 'selected' : '' ?>><?= h($s) ?></option>
                    <?php endforeach; ?>
                    <?php if ($f_semester && !in_array($f_semester, $semester_opts)): ?>
                    <option value="<?= h($f_semester) ?>" selected><?= h($f_semester) ?></option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-medium mb-1 text-muted">Academic Intake</label>
                <select name="academic_intake" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All</option>
                    <?php foreach ($intake_opts as $ai): ?>
                    <option value="<?= h($ai) ?>" <?= $f_academic_intake === $ai ? 'selected' : '' ?>><?= h($ai) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-medium mb-1 text-muted">Status</label>
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All</option>
                    <option value="active"   <?= $f_status === 'active'   ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $f_status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label small fw-medium mb-1 text-muted">Subject Search</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" name="search" class="form-control border-start-0"
                           value="<?= h($f_search) ?>" placeholder="Course code or name…">
                    <button type="submit" class="btn btn-primary">Search</button>
                </div>
            </div>
            <?php if ($active_filters > 0): ?>
            <div class="col-12 col-md-auto ms-md-auto">
                <a href="<?= APP_URL ?>/course-offer/index.php" class="btn btn-light btn-sm">
                    <i class="fas fa-times me-1"></i>Clear filters
                    <span class="badge text-bg-secondary ms-1"><?= $active_filters ?></span>
                </a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- ── Results ────────────────────────────────────────────────────────────── -->
<?php if (empty($offers)): ?>
<div class="card co-card">
    <div class="card-body text-center py-5 text-muted">
        <i class="fas fa-book-open fa-3x mb-3 opacity-25"></i>
        <p class="mb-0">No course offers found<?= $active_filters ? ' for the selected filters' : '' ?>.</p>
        <?php if ($active_filters): ?>
        <a href="<?= APP_URL ?>/course-offer/index.php" class="btn btn-light btn-sm mt-3">
            <i class="fas fa-times me-1"></i> Clear filters
        </a>
        <?php elseif (co_can_create()): ?>
        <a href="<?= APP_URL ?>/course-offer/create.php" class="btn btn-primary btn-sm mt-3">
            <i class="fas fa-plus me-1"></i> Create First Offer
        </a>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <small class="text-muted">
        Showing <strong><?= ($cur_page - 1) * $per_page + 1 ?>–<?= min($cur_page * $per_page, $total) ?></strong>
        of <strong><?= $total ?></strong> offer<?= $total != 1 ? 's' : '' ?>
    </small>
</div>

<?php // Global row counter across all batch groups, starting at the page offset. ?>
<?php $global_row = ($cur_page - 1) * $per_page + 1; ?>
<?php foreach ($grouped as $batch_id => $group): ?>
<div class="card co-card co-batch-card mb-4">
    <!-- Batch header -->
    <div class="card-header co-batch-head py-2 px-4 d-flex align-items-center gap-2">
        <span class="co-batch-icon d-inline-flex align-items-center justify-content-center rounded-circle bg-primary bg-opacity-10 text-primary">
            <i class="fas fa-users"></i>
        </span>
        <span class="fw-bold" style="font-size:1rem;"><?= h($group['batch_name']) ?></span>
        <span class="co-chip ms-1"><?= count($group['rows']) ?> offer<?= count($group['rows']) != 1 ? 's' : '' ?></span>
    </div>

    <?php foreach ($group['rows'] as $row):
          $offer_subjects = $subjects_map[(int)$row['id']] ?? [];
          $collapse_id    = 'co-subjects-' . (int)$row['id']; ?>
    <div class="co-offer">
        <!-- Offer summary row -->
        <div class="co-offer-head d-flex flex-wrap align-items-center gap-2">
            <span class="text-muted small" style="width:1.6rem;"><?= $global_row++ ?></span>

            <div class="flex-grow-1" style="min-width:220px;">
                <div class="fw-semibold">
                    <?= h($row['dept_name']) ?>
                    <span class="text-muted fw-normal">· <?= h($row['program_name']) ?></span>
                </div>
                <div class="d-flex flex-wrap gap-1 mt-1">
                    <?php if (!empty($row['semester'])): ?>
                    <span class="co-chip co-chip-semester"><i class="fas fa-calendar-alt"></i><?= h($row['semester']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($row['academic_intake'])): ?>
                    <span class="co-chip co-chip-intake"><i class="fas fa-graduation-cap"></i><?= h($row['academic_intake']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($row['shift'])): ?>
                    <span class="co-chip co-chip-shift"><i class="fas fa-clock"></i><?= h($row['shift']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($row['section'])): ?>
                    <span class="co-chip co-chip-section"><i class="fas fa-layer-group"></i>Section <?= h($row['section']) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Status -->
            <span class="co-chip">
                <span class="co-status-dot <?= $row['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>"></span>
                <?= $row['status'] === 'active' ? 'Active' : 'Inactive' ?>
            </span>

            <!-- Actions -->
            <div class="co-actions d-flex align-items-center gap-1">
                <a href="<?= APP_URL ?>/course-offer/registrations.php?offer_id=<?= $row['id'] ?>"
                   class="btn btn-outline-primary" title="Registrations">
                    <i class="fas fa-user-check"></i>
                </a>
                <?php if (co_is_staff()): ?>
                <a href="<?= APP_URL ?>/course-offer/edit.php?id=<?= $row['id'] ?>"
                   class="btn btn-outline-secondary" title="Edit">
                    <i class="fas fa-pen"></i>
                </a>
                <?php endif; ?>
                <?php if (co_can_delete()): ?>
                <a href="<?= APP_URL ?>/course-offer/delete.php?id=<?= $row['id'] ?>"
                   class="btn btn-outline-danger" title="Delete"
                   onclick="return confirm('Delete this course offer and all its subjects? This cannot be undone.')">
                    <i class="fas fa-trash"></i>
                </a>
                <?php endif; ?>
            </div>

            <!-- Subjects toggle -->
            <button class="co-toggle d-inline-flex align-items-center gap-1" type="button"
                    data-bs-toggle="collapse" data-bs-target="#<?= $collapse_id ?>"
                    aria-expanded="true" aria-controls="<?= $collapse_id ?>">
                <i class="fas fa-book"></i>
                <?= count($offer_subjects) ?> subject<?= count($offer_subjects) != 1 ? 's' : '' ?>
                <i class="fas fa-chevron-down"></i>
            </button>
        </div>

        <!-- Subjects -->
        <div class="collapse show co-subjects" id="<?= $collapse_id ?>">
            <?php if (empty($offer_subjects)): ?>
            <div class="co-subject text-muted fst-italic">No subjects added yet.</div>
            <?php else: ?>
            <?php foreach ($offer_subjects as $sub): ?>
            <div class="co-subject d-flex flex-wrap align-items-center gap-2">
                <?php if ($sub['course_code']): ?>
                <span class="co-code"><?= h($sub['course_code']) ?></span>
                <?php endif; ?>
                <div class="flex-grow-1" style="min-width:200px;">
                    <span class="fw-medium"><?= h($sub['course_name']) ?></span>
                    <span class="text-muted" style="font-size:.74rem;">
                        &nbsp;<?= h($sub['dept_name']) ?> &rsaquo; <?= h($sub['program_name']) ?>
                    </span>
                </div>
                <?php if ($sub['credit']): ?>
                <span class="co-chip"><i class="fas fa-star"></i><?= h($sub['credit']) ?> cr</span>
                <?php endif; ?>
                <?php if (!empty($sub['teachers'])): ?>
                <div class="d-flex flex-wrap gap-1">
                    <?php foreach ($sub['teachers'] as $t): ?>
                    <span class="co-chip co-chip-teacher">
                        <i class="fas fa-chalkboard-teacher"></i><?= h($t['name']) ?>
                        <?php if ($t['designation']): ?>
                        <span class="opacity-75">(<?= h($t['designation']) ?>)</span>
                        <?php endif; ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <span class="text-muted small">No teacher assigned</span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>

<?php if ($pages > 1): ?>
<nav class="d-flex justify-content-center mt-3">
    <ul class="pagination pagination-sm mb-0">
        <?php
        $base = '?' . http_build_query(array_filter([
            'dept_id'         => $f_dept_id         ?: null,
            'program_id'      => $f_program_id      ?: null,
            'batch_id'        => $f_batch_id        ?: null,
            'semester'        => $f_semester        ?: null,
            'academic_intake' => $f_academic_intake ?: null,
            'status'          => $f_status          ?: null,
            'search'          => $f_search          ?: null,
        ]));

        // Windowed page list: 1 … around current … last
        $win = [];
        for ($p = 1; $p <= $pages; $p++) {
            if ($p === 1 || $p === $pages || abs($p - $cur_page) <= 2) {
                $win[] = $p;
            }
        }
        ?>
        <li class="page-item <?= $cur_page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= $base ?>&page=<?= max(1, $cur_page - 1) ?>" aria-label="Previous">&laquo;</a>
        </li>
        <?php $prev = 0; foreach ($win as $p): ?>
            <?php if ($p - $prev > 1): ?>
            <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
            <?php endif; ?>
            <li class="page-item <?= $p === $cur_page ? 'active' : '' ?>">
                <a class="page-link" href="<?= $base ?>&page=<?= $p ?>"><?= $p ?></a>
            </li>
        <?php $prev = $p; endforeach; ?>
        <li class="page-item <?= $cur_page >= $pages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= $base ?>&page=<?= min($pages, $cur_page + 1) ?>" aria-label="Next">&raquo;</a>
        </li>
    </ul>
</nav>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
