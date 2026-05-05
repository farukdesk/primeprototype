<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('spring-result');
require_once __DIR__ . '/helpers.php';

$id     = (int)($_GET['id'] ?? 0);
$result = sr_get_result($id);

$page_title = h($result['title']);

$entries  = sr_get_entries($id);

// Group entries by student for the summary view
$by_student = [];
foreach ($entries as $e) {
    $by_student[$e['student_id']][] = $e;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/spring-result/index.php">Spring Result</a></li>
            <li class="breadcrumb-item active"><?= h($result['title']) ?></li>
        </ol>
    </nav>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (sr_can_edit()): ?>
        <!-- Toggle publish -->
        <form method="POST" action="<?= APP_URL ?>/spring-result/toggle-publish.php" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <button type="submit" class="btn btn-sm <?= $result['is_published'] ? 'btn-warning' : 'btn-success' ?>" style="border-radius:8px;">
                <i class="fas <?= $result['is_published'] ? 'fa-eye-slash' : 'fa-globe' ?> me-1"></i>
                <?= $result['is_published'] ? 'Unpublish' : 'Publish' ?>
            </button>
        </form>
        <a href="<?= APP_URL ?>/spring-result/edit.php?id=<?= $id ?>"
           class="btn btn-sm btn-outline-secondary" style="border-radius:8px;">
            <i class="fas fa-edit me-1"></i> Edit Info
        </a>
        <?php endif; ?>
        <?php if (sr_can_create()): ?>
        <a href="<?= APP_URL ?>/spring-result/csv-upload.php?result_id=<?= $id ?>"
           class="btn btn-sm btn-outline-info" style="border-radius:8px;">
            <i class="fas fa-file-csv me-1"></i> CSV Upload
        </a>
        <button type="button" class="btn btn-sm btn-primary" style="border-radius:8px;"
                data-bs-toggle="modal" data-bs-target="#addEntryModal">
            <i class="fas fa-plus me-1"></i> Add Entry
        </button>
        <?php endif; ?>
    </div>
</div>

<?php flash_show(); ?>

<!-- Result info strip -->
<div class="card mb-4" style="border-radius:12px;border-left:4px solid <?= $result['is_published'] ? '#198754' : '#6c757d' ?>;">
    <div class="card-body py-3 px-4 d-flex flex-wrap gap-4 align-items-center">
        <div>
            <div class="text-muted small fw-semibold text-uppercase" style="font-size:.7rem;letter-spacing:.07em;">Result Title</div>
            <div class="fw-bold" style="font-size:1rem;"><?= h($result['title']) ?></div>
        </div>
        <?php if ($result['semester']): ?>
        <div>
            <div class="text-muted small fw-semibold text-uppercase" style="font-size:.7rem;letter-spacing:.07em;">Semester</div>
            <div><?= h($result['semester']) ?></div>
        </div>
        <?php endif; ?>
        <div>
            <div class="text-muted small fw-semibold text-uppercase" style="font-size:.7rem;letter-spacing:.07em;">Status</div>
            <div><?= sr_status_badge((int)$result['is_published']) ?></div>
        </div>
        <div>
            <div class="text-muted small fw-semibold text-uppercase" style="font-size:.7rem;letter-spacing:.07em;">Total Entries</div>
            <div><span class="badge bg-primary" style="font-size:.85rem;"><?= count($entries) ?></span></div>
        </div>
        <div>
            <div class="text-muted small fw-semibold text-uppercase" style="font-size:.7rem;letter-spacing:.07em;">Students</div>
            <div><span class="badge bg-secondary" style="font-size:.85rem;"><?= count($by_student) ?></span></div>
        </div>
        <?php if ($result['is_published']): ?>
        <div class="ms-auto">
            <a href="<?= SITE_URL ?>/spring-result.php?result_id=<?= $id ?>" target="_blank"
               class="btn btn-sm btn-outline-success" style="border-radius:8px;">
                <i class="fas fa-external-link-alt me-1"></i> Public Page
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Search / filter -->
<div class="card mb-3" style="border-radius:12px;">
    <div class="card-body py-2 px-4">
        <div class="row g-2 align-items-center">
            <div class="col-md-4">
                <input type="text" id="searchInput" class="form-control form-control-sm"
                       placeholder="Search by student ID, name, course…">
            </div>
            <div class="col-auto ms-auto text-muted small">
                Showing <span id="visibleCount"><?= count($entries) ?></span> of <?= count($entries) ?> entries
            </div>
        </div>
    </div>
</div>

<!-- Entries table -->
<div class="card" style="border-radius:12px;">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-list-ol me-2 text-muted"></i>Grade Entries</h6>
        <span class="badge bg-secondary"><?= count($entries) ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="entriesTable">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">#</th>
                        <th>Student ID</th>
                        <th>Student Name</th>
                        <th>Course Code</th>
                        <th>Course Title</th>
                        <th class="text-center">Letter Grade</th>
                        <th class="text-center">Grade Point</th>
                        <?php if (sr_can_edit() || sr_can_delete()): ?>
                        <th class="text-end pe-4">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody id="entriesBody">
                <?php if (empty($entries)): ?>
                    <tr id="emptyRow"><td colspan="8" class="text-center text-muted py-5">
                        <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                        No entries yet. Add manually or upload a CSV.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($entries as $i => $e): ?>
                    <tr class="entry-row">
                        <td class="px-4"><?= $i + 1 ?></td>
                        <td class="fw-medium"><?= h($e['student_id']) ?></td>
                        <td><?= $e['student_name'] ? h($e['student_name']) : '<span class="text-muted">—</span>' ?></td>
                        <td><?= $e['course_code']  ? '<span class="badge bg-light text-dark border">' . h($e['course_code']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
                        <td><?= h($e['course_title']) ?></td>
                        <td class="text-center">
                            <span class="badge <?= sr_grade_badge_class($e['letter_grade']) ?> px-2">
                                <?= h($e['letter_grade']) ?>
                            </span>
                        </td>
                        <td class="text-center"><?= $e['grade_point'] !== null ? number_format((float)$e['grade_point'], 2) : '—' ?></td>
                        <?php if (sr_can_edit() || sr_can_delete()): ?>
                        <td class="text-end pe-4">
                            <div class="d-flex gap-1 justify-content-end">
                                <?php if (sr_can_edit()): ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary edit-btn" style="border-radius:7px;"
                                        data-id="<?= $e['id'] ?>"
                                        data-student_id="<?= h($e['student_id']) ?>"
                                        data-student_name="<?= h($e['student_name'] ?? '') ?>"
                                        data-course_code="<?= h($e['course_code'] ?? '') ?>"
                                        data-course_title="<?= h($e['course_title']) ?>"
                                        data-letter_grade="<?= h($e['letter_grade']) ?>"
                                        data-grade_point="<?= h($e['grade_point'] ?? '') ?>"
                                        title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php endif; ?>
                                <?php if (sr_can_delete()): ?>
                                <a href="<?= APP_URL ?>/spring-result/entry-delete.php?id=<?= $e['id'] ?>&result_id=<?= $id ?>"
                                   class="btn btn-sm btn-outline-danger" style="border-radius:7px;" title="Delete"
                                   onclick="return confirm('Delete this grade entry?')">
                                    <i class="fas fa-trash"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ── Add Entry Modal ──────────────────────────────────────────────────── -->
<?php if (sr_can_create()): ?>
<div class="modal fade" id="addEntryModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius:16px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold" id="entryModalLabel">Add Grade Entry</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="<?= APP_URL ?>/spring-result/entry-save.php" id="entryForm">
                <?= csrf_field() ?>
                <input type="hidden" name="result_id" value="<?= $id ?>">
                <input type="hidden" name="entry_id" id="entry_id" value="">
                <div class="modal-body px-4 pt-3">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Student ID <span class="text-danger">*</span></label>
                            <input type="text" name="student_id" id="m_student_id" class="form-control"
                                   placeholder="e.g. 193020101021" maxlength="50" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Student Name</label>
                            <input type="text" name="student_name" id="m_student_name" class="form-control"
                                   placeholder="Full name" maxlength="200">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium">Course Code</label>
                            <input type="text" name="course_code" id="m_course_code" class="form-control"
                                   placeholder="e.g. CSE-101" maxlength="50">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-medium">Course Title <span class="text-danger">*</span></label>
                            <input type="text" name="course_title" id="m_course_title" class="form-control"
                                   placeholder="e.g. Introduction to Programming" maxlength="300" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium">Letter Grade <span class="text-danger">*</span></label>
                            <select name="letter_grade" id="m_letter_grade" class="form-select" required>
                                <option value="">— Select —</option>
                                <?php foreach (SR_VALID_GRADES as $g): ?>
                                <option value="<?= $g ?>"><?= $g ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium">Grade Point</label>
                            <input type="number" name="grade_point" id="m_grade_point" class="form-control"
                                   placeholder="Auto-filled" min="0" max="4" step="0.01" readonly>
                            <div class="form-text">Auto-set from letter grade.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal" style="border-radius:8px;">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="border-radius:8px;" id="entrySubmitBtn">
                        <i class="fas fa-save me-1"></i> <span id="entryBtnLabel">Save Entry</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(function () {

    // ── Grade point auto-fill ────────────────────────────────────────────────
    var gradePoints = {
        'A+': 4.00, 'A': 3.75, 'A-': 3.50,
        'B+': 3.25, 'B': 3.00, 'B-': 2.75,
        'C+': 2.50, 'C': 2.25, 'D': 2.00, 'F': 0.00
    };
    var lgSel = document.getElementById('m_letter_grade');
    var gpInp = document.getElementById('m_grade_point');
    if (lgSel) {
        lgSel.addEventListener('change', function () {
            var pt = gradePoints[this.value];
            gpInp.value = (pt !== undefined) ? pt.toFixed(2) : '';
        });
    }

    // ── Edit buttons ──────────────────────────────────────────────────────────
    document.querySelectorAll('.edit-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var d = this.dataset;
            document.getElementById('entry_id').value          = d.id;
            document.getElementById('m_student_id').value      = d.student_id;
            document.getElementById('m_student_name').value    = d.student_name;
            document.getElementById('m_course_code').value     = d.course_code;
            document.getElementById('m_course_title').value    = d.course_title;
            document.getElementById('m_letter_grade').value    = d.letter_grade;
            document.getElementById('m_grade_point').value     = d.grade_point;
            document.getElementById('entryModalLabel').textContent = 'Edit Grade Entry';
            document.getElementById('entryBtnLabel').textContent   = 'Update Entry';
            var modal = new bootstrap.Modal(document.getElementById('addEntryModal'));
            modal.show();
        });
    });

    // Reset modal on hide
    document.getElementById('addEntryModal')?.addEventListener('hidden.bs.modal', function () {
        document.getElementById('entryForm').reset();
        document.getElementById('entry_id').value = '';
        document.getElementById('entryModalLabel').textContent = 'Add Grade Entry';
        document.getElementById('entryBtnLabel').textContent   = 'Save Entry';
        if (gpInp) gpInp.value = '';
    });

    // ── Live search ───────────────────────────────────────────────────────────
    var searchInput  = document.getElementById('searchInput');
    var visibleCount = document.getElementById('visibleCount');
    var rows = document.querySelectorAll('.entry-row');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            var q = this.value.toLowerCase().trim();
            var visible = 0;
            rows.forEach(function (row) {
                var text = row.textContent.toLowerCase();
                var show = !q || text.indexOf(q) !== -1;
                row.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            if (visibleCount) visibleCount.textContent = visible;
        });
    }
})();
</script>

<?php
/**
 * Returns Bootstrap badge class based on letter grade.
 */
function sr_grade_badge_class(string $grade): string
{
    return match (strtoupper(trim($grade))) {
        'A+', 'A'  => 'bg-success',
        'A-', 'B+' => 'bg-primary',
        'B', 'B-'  => 'bg-info text-dark',
        'C+', 'C'  => 'bg-warning text-dark',
        'D'        => 'bg-orange text-dark',
        'F'        => 'bg-danger',
        default    => 'bg-secondary',
    };
}
?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
