<?php
/**
 * Student Attendance – subject dashboard.
 *
 * Faculty land here with their profile department pre-selected and only see
 * the offered subjects they are assigned to teach. Staff (can_edit) see all
 * subjects within their department scope.
 */
require_once __DIR__ . '/helpers.php';
require_access('student-attendance');

$page_title = 'Student Attendance';

$faculty     = sa_current_faculty();
$departments = sa_departments();
$batches     = sa_batches();
$semesters   = sa_semesters();
$intakes     = sa_intakes();
$sections    = sa_sections();
$shifts      = sa_shifts();

// Default department comes from the faculty profile when no filter is set.
$dept_id = isset($_GET['dept_id'])
    ? (int)$_GET['dept_id']
    : (int)($faculty['dept_id'] ?? 0);

$filters = [
    'dept_id'         => $dept_id,
    'program_id'      => (int)($_GET['program_id'] ?? 0),
    'batch_id'        => (int)($_GET['batch_id'] ?? 0),
    'semester'        => trim($_GET['semester'] ?? ''),
    'academic_intake' => trim($_GET['academic_intake'] ?? ''),
    'section'         => trim($_GET['section'] ?? ''),
    'shift'           => trim($_GET['shift'] ?? ''),
    'search'          => trim($_GET['search'] ?? ''),
];

$programs = $dept_id > 0 ? sa_programs($dept_id) : [];

$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$result   = sa_subjects_filtered($filters, $page, $per_page);
$subjects = $result['rows'];
$total    = $result['total'];
$pages    = (int)ceil($total / $per_page);

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1"><i class="fas fa-clipboard-user me-2 text-primary"></i>Student Attendance</h4>
        <p class="text-muted mb-0" style="font-size:.85rem;">
            <?= sa_is_staff()
                ? 'All offered subjects within your department scope.'
                : 'Subjects you are assigned to teach. Students are pulled from course offer registrations.' ?>
        </p>
    </div>
</div>

<?php flash_show(); ?>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label" style="font-size:.8rem;font-weight:600;">Department</label>
                <select name="dept_id" id="sa-dept" class="form-select form-select-sm">
                    <option value="0">All departments</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= $dept_id === (int)$d['id'] ? 'selected' : '' ?>>
                        <?= h($d['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" style="font-size:.8rem;font-weight:600;">Program</label>
                <select name="program_id" id="sa-program" class="form-select form-select-sm">
                    <option value="0">All programs</option>
                    <?php foreach ($programs as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= $filters['program_id'] === (int)$p['id'] ? 'selected' : '' ?>>
                        <?= h($p['program_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" style="font-size:.8rem;font-weight:600;">Batch</label>
                <select name="batch_id" class="form-select form-select-sm">
                    <option value="0">All batches</option>
                    <?php foreach ($batches as $b): ?>
                    <option value="<?= (int)$b['id'] ?>" <?= $filters['batch_id'] === (int)$b['id'] ? 'selected' : '' ?>>
                        <?= h($b['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" style="font-size:.8rem;font-weight:600;">Semester</label>
                <select name="semester" class="form-select form-select-sm">
                    <option value="">All semesters</option>
                    <?php foreach ($semesters as $s): ?>
                    <option value="<?= h($s) ?>" <?= $filters['semester'] === $s ? 'selected' : '' ?>><?= h($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" style="font-size:.8rem;font-weight:600;">Academic Intake</label>
                <select name="academic_intake" class="form-select form-select-sm">
                    <option value="">All intakes</option>
                    <?php foreach ($intakes as $i): ?>
                    <option value="<?= h($i) ?>" <?= $filters['academic_intake'] === $i ? 'selected' : '' ?>><?= h($i) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" style="font-size:.8rem;font-weight:600;">Section</label>
                <select name="section" class="form-select form-select-sm">
                    <option value="">All sections</option>
                    <?php foreach ($sections as $sec): ?>
                    <option value="<?= h($sec) ?>" <?= $filters['section'] === $sec ? 'selected' : '' ?>><?= h($sec) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" style="font-size:.8rem;font-weight:600;">Shift / Group</label>
                <select name="shift" class="form-select form-select-sm">
                    <option value="">All shifts</option>
                    <?php foreach ($shifts as $sh): ?>
                    <option value="<?= h($sh) ?>" <?= $filters['shift'] === $sh ? 'selected' : '' ?>><?= h($sh) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" style="font-size:.8rem;font-weight:600;">Subject Search</label>
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="Course code or name…" value="<?= h($filters['search']) ?>">
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fas fa-filter me-1"></i> Filter
                </button>
                <a href="<?= APP_URL ?>/student-attendance/index.php?dept_id=0" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Subject list -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong style="font-size:.9rem;">Offered Subjects</strong>
        <span class="text-muted" style="font-size:.8rem;"><?= (int)$total ?> subject<?= $total === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Subject</th>
                    <th>Department / Program</th>
                    <th>Batch</th>
                    <th>Semester</th>
                    <th>Intake</th>
                    <th>Teacher(s)</th>
                    <th class="text-center">Students</th>
                    <th class="text-center">Classes</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($subjects)): ?>
                <tr>
                    <td colspan="9" class="text-center text-muted py-4">
                        <i class="fas fa-inbox me-1"></i>
                        No subjects found<?= sa_is_staff() ? '' : ' — you have no assigned subjects matching these filters' ?>.
                    </td>
                </tr>
                <?php else: foreach ($subjects as $sub): ?>
                <tr>
                    <td>
                        <strong><?= h($sub['course_code']) ?></strong><br>
                        <span class="text-muted" style="font-size:.8rem;"><?= h($sub['course_name']) ?></span>
                    </td>
                    <td style="font-size:.8rem;">
                        <?= h($sub['dept_name']) ?><br>
                        <span class="text-muted"><?= h($sub['program_name']) ?></span>
                    </td>
                    <td><?= h($sub['batch_name']) ?></td>
                    <td><?= h($sub['semester']) ?></td>
                    <td style="font-size:.8rem;"><?= h($sub['academic_intake']) ?></td>
                    <td style="font-size:.8rem;">
                        <?= empty($sub['teachers'])
                            ? '<span class="text-muted">—</span>'
                            : h(implode(', ', $sub['teachers'])) ?>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-secondary"><?= (int)$sub['student_count'] ?></span>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-info text-dark"><?= (int)$sub['session_count'] ?></span>
                    </td>
                    <td class="text-end">
                        <a href="<?= APP_URL ?>/student-attendance/sheet.php?subject_id=<?= (int)$sub['id'] ?>"
                           class="btn btn-outline-primary btn-sm" title="Date-wise attendance sheet">
                            <i class="fas fa-table"></i> Sheet
                        </a>
                        <?php if (sa_can_manage_subject((int)$sub['id'])): ?>
                        <a href="<?= APP_URL ?>/student-attendance/take.php?subject_id=<?= (int)$sub['id'] ?>"
                           class="btn btn-success btn-sm" title="Take today's attendance">
                            <i class="fas fa-user-check"></i> Take
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1): ?>
    <div class="card-footer bg-white">
        <nav>
            <ul class="pagination pagination-sm mb-0 justify-content-center">
                <?php
                $qs = $_GET;
                for ($i = 1; $i <= $pages; $i++):
                    $qs['page'] = $i;
                ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= h(http_build_query($qs)) ?>"><?= $i ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<script>
// Department → Program cascade
document.getElementById('sa-dept').addEventListener('change', function () {
    const programSel = document.getElementById('sa-program');
    programSel.innerHTML = '<option value="0">All programs</option>';
    const deptId = parseInt(this.value, 10);
    if (!deptId) return;
    fetch('<?= APP_URL ?>/student-attendance/get-programs.php?dept_id=' + deptId)
        .then(r => r.json())
        .then(rows => {
            rows.forEach(p => {
                const opt = document.createElement('option');
                opt.value = p.id;
                opt.textContent = p.program_name;
                programSel.appendChild(opt);
            });
        })
        .catch(() => {});
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
