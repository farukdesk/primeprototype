<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('results', 'can_create');
require_once __DIR__ . '/helpers.php';

$page_title = 'New Result Exam';
$errors     = [];
clear_old();

// Auto-populate: departments and programs from existing tables
$departments = db()->query(
    'SELECT id, name, faculty_label FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
)->fetchAll();

$semesters = rm_semester_list();

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $dept_id             = (int)($_POST['dept_id'] ?? 0);
    $program_id          = (int)($_POST['program_id'] ?? 0);
    $batch               = trim($_POST['batch'] ?? '');
    $enrollment_semester = trim($_POST['enrollment_semester'] ?? '');
    $completion_semester = trim($_POST['completion_semester'] ?? '');
    $exam_title          = trim($_POST['exam_title'] ?? '');
    $exam_level          = trim($_POST['exam_level'] ?? '');
    $notes               = trim($_POST['notes'] ?? '');
    $is_published        = isset($_POST['is_published']) ? 1 : 0;

    if ($dept_id <= 0)     $errors[] = 'Department is required.';
    if ($exam_title === '') $errors[] = 'Exam Title is required.';

    // Verify dept exists
    if ($dept_id > 0) {
        $dept = db()->prepare('SELECT id FROM dept_departments WHERE id = ? AND is_active = 1');
        $dept->execute([$dept_id]);
        if (!$dept->fetch()) $errors[] = 'Invalid department selected.';
    }

    // Verify program belongs to dept (if provided)
    if (empty($errors) && $program_id > 0) {
        $prog = db()->prepare(
            'SELECT id FROM dept_academic_programs WHERE id = ? AND dept_id = ? AND is_active = 1'
        );
        $prog->execute([$program_id, $dept_id]);
        if (!$prog->fetch()) { $program_id = 0; }
    }

    if (empty($errors)) {
        $user = auth_user();
        $stmt = db()->prepare(
            'INSERT INTO result_exams
               (dept_id, program_id, batch, enrollment_semester, completion_semester,
                exam_title, exam_level, notes, is_published, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $dept_id,
            $program_id ?: null,
            $batch               ?: null,
            $enrollment_semester ?: null,
            $completion_semester ?: null,
            $exam_title,
            $exam_level ?: null,
            $notes      ?: null,
            $is_published,
            $user['id'] ?? null,
        ]);
        $new_id = (int)db()->lastInsertId();

        flash_set('success', 'Result exam <strong>' . h($exam_title) . '</strong> created. Now add subjects and grades.');
        redirect(APP_URL . '/results/view.php?id=' . $new_id);
    }

    save_old(compact('dept_id','program_id','batch','enrollment_semester','completion_semester',
                     'exam_title','exam_level','notes','is_published'));
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/results/index.php">Results</a></li>
            <li class="breadcrumb-item active">New Exam</li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<form method="POST" novalidate>
    <?= csrf_field() ?>
    <div class="row g-4">

        <!-- ── Left column ── -->
        <div class="col-lg-8">

            <!-- Exam Info -->
            <div class="card mb-4" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-info-circle me-2 text-muted"></i>Exam Information</h6>
                </div>
                <div class="card-body p-4">

                    <div class="mb-3">
                        <label class="form-label fw-medium">Exam Title <span class="text-danger">*</span></label>
                        <input type="text" name="exam_title" class="form-control"
                               value="<?= old('exam_title') ?>"
                               placeholder="e.g. Foundation Courses Result – Batch 52"
                               maxlength="300" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Level / Category</label>
                        <input type="text" name="exam_level" class="form-control"
                               value="<?= old('exam_level') ?>"
                               placeholder="e.g. Foundation Courses, Year 1 – Semester 1"
                               maxlength="100">
                        <div class="form-text">Optional label shown on the result sheet header.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Notes / Remarks</label>
                        <textarea name="notes" class="form-control" rows="3"
                                  placeholder="Any internal notes about this result exam…"><?= old('notes') ?></textarea>
                    </div>

                </div>
            </div>

            <!-- Institution Info (auto from existing tables) -->
            <div class="card mb-4" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-university me-2 text-muted"></i>
                        Department &amp; Program
                        <small class="text-muted fw-normal ms-2">— auto-loaded from database</small>
                    </h6>
                </div>
                <div class="card-body p-4">

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Department <span class="text-danger">*</span></label>
                            <select name="dept_id" id="dept_select" class="form-select" required>
                                <option value="">— Select Department —</option>
                                <?php foreach ($departments as $d): ?>
                                <option value="<?= $d['id'] ?>"
                                        data-faculty="<?= h($d['faculty_label']) ?>"
                                        <?= (int)old('dept_id') === (int)$d['id'] ? 'selected' : '' ?>>
                                    <?= h($d['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Program</label>
                            <select name="program_id" id="prog_select" class="form-select"
                                    <?= old('dept_id') ? '' : 'disabled' ?>>
                                <option value="">— Select Program —</option>
                            </select>
                            <div class="form-text">Programs load automatically when a department is chosen.</div>
                        </div>
                    </div>

                    <!-- Faculty label preview -->
                    <div id="faculty_preview" class="text-muted small" style="min-height:1.2em;"></div>

                </div>
            </div>

        </div>

        <!-- ── Right column ── -->
        <div class="col-lg-4">
            <div class="card mb-4" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-calendar-alt me-2 text-muted"></i>Batch &amp; Semesters</h6>
                </div>
                <div class="card-body p-4">

                    <div class="mb-3">
                        <label class="form-label fw-medium">Batch</label>
                        <input type="text" name="batch" class="form-control"
                               value="<?= old('batch') ?>" maxlength="50"
                               placeholder="e.g. 52nd" list="batch_list">
                        <!-- Auto-suggest batches from students table -->
                        <datalist id="batch_list">
                            <?php
                            $batches = db()->query(
                                "SELECT DISTINCT batch FROM students WHERE batch IS NOT NULL AND batch != ''
                                 ORDER BY batch DESC"
                            )->fetchAll(PDO::FETCH_COLUMN);
                            foreach ($batches as $b): ?>
                            <option value="<?= h($b) ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <div class="form-text">Existing batches suggested from the student database.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Enrollment Semester</label>
                        <input type="text" name="enrollment_semester" class="form-control"
                               value="<?= old('enrollment_semester') ?>" maxlength="50"
                               placeholder="e.g. Fall-2019" list="sem_list">
                        <datalist id="sem_list">
                            <?php foreach (array_reverse($semesters) as $s): ?>
                            <option value="<?= h($s) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-medium">Completion Semester</label>
                        <input type="text" name="completion_semester" class="form-control"
                               value="<?= old('completion_semester') ?>" maxlength="50"
                               placeholder="e.g. Summer-2023" list="sem_list">
                    </div>

                    <div class="form-check mb-4">
                        <input class="form-check-input" type="checkbox" name="is_published"
                               id="is_published" value="1"
                               <?= old('is_published') ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_published">
                            Publish (visible to students)
                        </label>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i> Create &amp; Add Subjects
                        </button>
                        <a href="<?= APP_URL ?>/results/index.php"
                           class="btn btn-light" style="border-radius:10px;">Cancel</a>
                    </div>

                </div>
            </div>

            <!-- Grading scale reference -->
            <div class="card" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-star me-2 text-muted"></i>Grading Scale</h6>
                </div>
                <div class="card-body p-3">
                    <table class="table table-sm mb-0" style="font-size:.8rem;">
                        <thead class="table-light">
                            <tr><th>Marks</th><th>Grade</th><th>Point</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach (rm_grading_scale() as [$min, $max, $letter, $point]):
                                $range = ($max === PHP_INT_MAX) ? '≥ ' . $min : $min . ' – &lt;' . $max;
                            ?>
                            <tr>
                                <td><?= $range ?></td>
                                <td><strong><?= h($letter) ?></strong></td>
                                <td><?= number_format($point, 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</form>

<script>
(function () {
    var deptSel    = document.getElementById('dept_select');
    var progSel    = document.getElementById('prog_select');
    var facPreview = document.getElementById('faculty_preview');
    var savedProg  = <?= (int)old('program_id') ?>;
    var savedDept  = <?= (int)old('dept_id') ?>;

    function loadPrograms(deptId, selectId) {
        progSel.innerHTML = '<option value="">Loading…</option>';
        progSel.disabled  = true;
        if (!deptId) {
            progSel.innerHTML = '<option value="">— Select Program —</option>';
            return;
        }
        fetch('<?= APP_URL ?>/results/get-programs.php?dept_id=' + encodeURIComponent(deptId))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                progSel.innerHTML = '<option value="">— Select Program —</option>';
                data.forEach(function (p) {
                    var opt = document.createElement('option');
                    opt.value = p.id;
                    opt.textContent = p.program_name;
                    if (p.id == selectId) opt.selected = true;
                    progSel.appendChild(opt);
                });
                progSel.disabled = false;
            });
    }

    deptSel.addEventListener('change', function () {
        var sel = this.options[this.selectedIndex];
        facPreview.textContent = sel && sel.dataset.faculty ? sel.dataset.faculty : '';
        loadPrograms(this.value, 0);
    });

    // On page load with old values
    if (savedDept) {
        var sel = deptSel.querySelector('option[value="' + savedDept + '"]');
        if (sel) facPreview.textContent = sel.dataset.faculty || '';
        loadPrograms(savedDept, savedProg);
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
