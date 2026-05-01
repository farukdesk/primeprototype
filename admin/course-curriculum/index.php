<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';

$page_title = 'Course Curriculum';

// Resolve selected department and program from GET
$sel_dept    = (int)($_GET['dept_id']    ?? 0);
$sel_program = (int)($_GET['program_id'] ?? 0);

$departments = cc_departments();
$programs    = $sel_dept > 0 ? cc_programs($sel_dept) : [];

// Validate program
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

// Load flat subject list for the selected program
$subjects = [];
$distributions = [];
if ($program_row) {
    $subjects = cc_get_subjects_flat($sel_program);
    if (!empty($subjects)) {
        $distributions = cc_get_all_mark_distributions(array_column($subjects, 'id'));
    }
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
$total_credits  = array_sum(array_column($subjects, 'credit'));
$total_subjects = count($subjects);
?>

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
</div>

<!-- ── Subject table ──────────────────────────────────────────────────────── -->
<div class="card" style="border-radius:12px;">
    <div class="card-header px-4 py-3" style="background-color:#002147; border-radius:12px 12px 0 0;">
        <span class="fw-semibold text-white">
            <i class="fas fa-list me-2"></i>Subjects
            <?php if ($total_subjects > 0): ?>
            <span class="badge bg-light text-dark ms-2 small">
                <?= $total_subjects ?> subject<?= $total_subjects !== 1 ? 's' : '' ?>
                &nbsp;·&nbsp;<?= number_format($total_credits, 2) ?> cr
            </span>
            <?php endif; ?>
        </span>
    </div>

    <?php if (empty($subjects)): ?>
    <div class="card-body text-center text-muted py-5">
        <i class="fas fa-book-open fa-2x mb-3 d-block" style="opacity:.3;"></i>
        No subjects added yet.
        <?php if (cc_is_staff()): ?>
        <a href="<?= APP_URL ?>/course-curriculum/create.php?dept_id=<?= $sel_dept ?>&program_id=<?= $sel_program ?>">Add the first subject</a>
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
                        <?= $total_subjects ?> subject<?= $total_subjects !== 1 ? 's' : '' ?>
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
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
