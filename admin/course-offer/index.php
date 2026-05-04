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
$f_dept_id    = (int)($_GET['dept_id']    ?? 0);
$f_program_id = (int)($_GET['program_id'] ?? 0);
$f_batch_id   = (int)($_GET['batch_id']   ?? 0);
$f_status     = $_GET['status'] ?? '';
$f_search     = trim($_GET['search']      ?? '');
$per_page     = 20;
$cur_page     = max(1, (int)($_GET['page'] ?? 1));

$filters = [
    'dept_id'    => $f_dept_id,
    'program_id' => $f_program_id,
    'batch_id'   => $f_batch_id,
    'status'     => $f_status,
    'search'     => $f_search,
];

$result   = co_get_offers_filtered($filters, $cur_page, $per_page);
$offers   = $result['rows'];
$total    = $result['total'];
$pages    = (int)ceil($total / $per_page);

// Dropdown data
$departments = co_departments();
$programs    = $f_dept_id    > 0 ? co_programs($f_dept_id)    : [];
$batches     = $f_program_id > 0 ? co_batches($f_program_id)  : [];

// Pre-load teacher names for each offer row
$offer_ids    = array_column($offers, 'id');
$teacher_map  = [];
if (!empty($offer_ids)) {
    $ph  = implode(',', array_fill(0, count($offer_ids), '?'));
    $tst = db()->prepare(
        "SELECT t.offer_id, f.name, f.designation
           FROM co_offer_teachers t
           JOIN dept_faculty f ON f.id = t.faculty_id
          WHERE t.offer_id IN ($ph)
          ORDER BY t.sort_order ASC, f.name ASC"
    );
    $tst->execute($offer_ids);
    foreach ($tst->fetchAll() as $tr) {
        $teacher_map[(int)$tr['offer_id']][] = $tr['name']
            . ($tr['designation'] ? ' (' . $tr['designation'] . ')' : '');
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Course Offer</li>
        </ol>
    </nav>
    <?php if (co_can_create()): ?>
    <a href="<?= APP_URL ?>/course-offer/create.php" class="btn btn-primary" style="border-radius:10px;">
        <i class="fas fa-plus me-1"></i> New Course Offer
    </a>
    <?php endif; ?>
</div>

<?php flash_show(); ?>

<!-- ── Filters ────────────────────────────────────────────────────────────── -->
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-body py-3 px-4">
        <form method="GET" class="row g-2 align-items-end" id="filter-form">
            <div class="col-md-2">
                <label class="form-label small fw-medium mb-1">Department</label>
                <select name="dept_id" id="f-dept" class="form-select form-select-sm" onchange="loadPrograms(this.value)">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $f_dept_id == $d['id'] ? 'selected' : '' ?>>
                        <?= h($d['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-medium mb-1">Program</label>
                <select name="program_id" id="f-program" class="form-select form-select-sm" onchange="loadBatches(this.value)">
                    <option value="">All Programs</option>
                    <?php foreach ($programs as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $f_program_id == $p['id'] ? 'selected' : '' ?>>
                        <?= h($p['program_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-medium mb-1">Batch</label>
                <select name="batch_id" id="f-batch" class="form-select form-select-sm">
                    <option value="">All Batches</option>
                    <?php foreach ($batches as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $f_batch_id == $b['id'] ? 'selected' : '' ?>>
                        <?= h(co_batch_label($b)) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-medium mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="active"   <?= $f_status === 'active'   ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $f_status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-medium mb-1">Subject Search</label>
                <input type="text" name="search" class="form-control form-control-sm"
                       value="<?= h($f_search) ?>" placeholder="Code or name…">
            </div>
            <div class="col-md-1 d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm flex-fill">
                    <i class="fas fa-search"></i>
                </button>
                <a href="<?= APP_URL ?>/course-offer/index.php" class="btn btn-light btn-sm flex-fill" title="Clear">
                    <i class="fas fa-times"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- ── Results ────────────────────────────────────────────────────────────── -->
<div class="card" style="border-radius:12px;">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-book-open me-2 text-muted"></i>Course Offers
            <span class="badge bg-secondary ms-2" style="font-size:.75rem;"><?= $total ?></span>
        </h6>
    </div>

    <?php if (empty($offers)): ?>
    <div class="card-body text-center py-5 text-muted">
        <i class="fas fa-book-open fa-3x mb-3 opacity-25"></i>
        <p class="mb-0">No course offers found.</p>
        <?php if (co_can_create()): ?>
        <a href="<?= APP_URL ?>/course-offer/create.php" class="btn btn-primary btn-sm mt-3">
            <i class="fas fa-plus me-1"></i> Create First Offer
        </a>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Subject</th>
                    <th>From</th>
                    <th>Offering Dept / Program</th>
                    <th>Batch</th>
                    <th>Teachers</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php $i = ($cur_page - 1) * $per_page + 1; foreach ($offers as $row): ?>
            <tr>
                <td class="text-muted small"><?= $i++ ?></td>
                <td>
                    <?php if ($row['course_code']): ?>
                    <span class="badge bg-light text-dark border me-1" style="font-size:.7rem;font-family:monospace;">
                        <?= h($row['course_code']) ?>
                    </span>
                    <?php endif; ?>
                    <strong><?= h($row['course_name']) ?></strong>
                    <?php if ($row['credit']): ?>
                    <span class="text-muted small ms-1">(<?= h($row['credit']) ?> cr)</span>
                    <?php endif; ?>
                </td>
                <td class="small text-muted">
                    <?= h($row['subject_dept_name']) ?><br>
                    <span class="text-secondary"><?= h($row['subject_program_name']) ?></span>
                </td>
                <td class="small">
                    <?= h($row['dept_name']) ?><br>
                    <span class="text-muted"><?= h($row['program_name']) ?></span>
                </td>
                <td class="small"><?= h(co_batch_label($row)) ?></td>
                <td>
                    <?php $teachers = $teacher_map[(int)$row['id']] ?? []; ?>
                    <?php if (empty($teachers)): ?>
                    <span class="text-muted small">—</span>
                    <?php else: ?>
                    <?php foreach ($teachers as $t): ?>
                    <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle me-1 mb-1" style="font-size:.72rem;">
                        <?= h($t) ?>
                    </span>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($row['status'] === 'active'): ?>
                    <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Active</span>
                    <?php else: ?>
                    <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">Inactive</span>
                    <?php endif; ?>
                </td>
                <td class="text-end">
                    <?php if (co_is_staff()): ?>
                    <a href="<?= APP_URL ?>/course-offer/edit.php?id=<?= $row['id'] ?>"
                       class="btn btn-sm btn-outline-secondary me-1" title="Edit">
                        <i class="fas fa-pen"></i>
                    </a>
                    <?php endif; ?>
                    <?php if (co_can_delete()): ?>
                    <a href="<?= APP_URL ?>/course-offer/delete.php?id=<?= $row['id'] ?>"
                       class="btn btn-sm btn-outline-danger"
                       title="Delete"
                       onclick="return confirm('Delete this course offer? This cannot be undone.')">
                        <i class="fas fa-trash"></i>
                    </a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
    <div class="card-footer py-2 px-4 d-flex justify-content-between align-items-center">
        <small class="text-muted">
            Showing <?= ($cur_page - 1) * $per_page + 1 ?>–<?= min($cur_page * $per_page, $total) ?> of <?= $total ?>
        </small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php
                $base = '?' . http_build_query(array_filter([
                    'dept_id'    => $f_dept_id    ?: null,
                    'program_id' => $f_program_id ?: null,
                    'batch_id'   => $f_batch_id   ?: null,
                    'status'     => $f_status      ?: null,
                    'search'     => $f_search      ?: null,
                ]));
                for ($p = 1; $p <= $pages; $p++):
                ?>
                <li class="page-item <?= $p === $cur_page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= $base ?>&page=<?= $p ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<script>
function loadPrograms(deptId) {
    var sel = document.getElementById('f-program');
    sel.innerHTML = '<option value="">All Programs</option>';
    document.getElementById('f-batch').innerHTML = '<option value="">All Batches</option>';
    if (!deptId) return;
    fetch('<?= APP_URL ?>/course-offer/get-programs.php?dept_id=' + encodeURIComponent(deptId))
        .then(r => r.json())
        .then(function(data) {
            data.forEach(function(p) {
                var opt = document.createElement('option');
                opt.value = p.id;
                opt.textContent = p.program_name;
                sel.appendChild(opt);
            });
        });
}

function loadBatches(programId) {
    var sel = document.getElementById('f-batch');
    sel.innerHTML = '<option value="">All Batches</option>';
    if (!programId) return;
    fetch('<?= APP_URL ?>/course-offer/get-batches.php?program_id=' + encodeURIComponent(programId))
        .then(r => r.json())
        .then(function(data) {
            data.forEach(function(b) {
                var opt = document.createElement('option');
                opt.value = b.id;
                opt.textContent = b.label;
                sel.appendChild(opt);
            });
        });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
