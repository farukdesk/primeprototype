<?php
/**
 * Admit Card – Create
 *
 * An admit card can be seeded from an exam routine: the exam, department,
 * program, batch and course rows are loaded from the routine, and the card
 * is linked to it so only students actually enrolled (registered) in the
 * routine's courses can view / download the card.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('admit-card', 'can_create');
require_once __DIR__ . '/helpers.php';

$page_title = 'New Admit Card';
$db = db();

// Dropdown data
$depts = $db->query("SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$batches = $db->query("SELECT id, name FROM student_batches WHERE is_active = 1 ORDER BY sort_order ASC, name ASC")->fetchAll();

// Predefined semester options
$year = (int)date('Y');
$semester_opts = [];
foreach ([$year - 1, $year, $year + 1] as $y) {
    $semester_opts[] = "Spring $y";
    $semester_opts[] = "Summer $y";
    $semester_opts[] = "Fall $y";
}

// ── Exam routines that can seed an admit card ──
require_once __DIR__ . '/../exam-routine/helpers.php';
try {
    $routines = $db->query(
        "SELECT r.id, r.semester, r.shift,
                e.exam_name, e.exam_year,
                d.name AS dept_name, p.program_name, b.name AS batch_name
           FROM exam_routines r
           JOIN ei_exams e               ON e.id = r.exam_id
           JOIN dept_departments d       ON d.id = r.dept_id
      LEFT JOIN dept_academic_programs p ON p.id = r.program_id
      LEFT JOIN student_batches b        ON b.id = r.batch_id
          ORDER BY r.id DESC"
    )->fetchAll();
} catch (Throwable $e) {
    $routines = [];
}

// Optional schema columns (see admin/admit-card-routine-link.sql)
$has_routine_col = false;
$has_subject_col = false;
try { $db->query('SELECT routine_id FROM ac_admit_cards LIMIT 1'); $has_routine_col = true; } catch (Throwable $e) {}
try { $db->query('SELECT offer_subject_id FROM ac_admit_card_courses LIMIT 1'); $has_subject_col = true; } catch (Throwable $e) {}

// ── Prefill from an exam routine (?routine_id=N) ──
$prefill    = null;
$routine_id = (int)($_GET['routine_id'] ?? ($_POST['routine_id'] ?? 0));
if ($routine_id > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $routine = er_get_routine($routine_id);
    if ($routine) {
        $pf_courses = [];
        foreach (er_get_items($routine_id) as $it) {
            $slot = trim(er_fmt_time($it['start_time'] ?? null)
                . (($it['end_time'] ?? null) ? ' - ' . er_fmt_time($it['end_time']) : ''));
            $pf_courses[] = [
                'offer_subject_id' => (int)$it['offer_subject_id'],
                'course_code'      => (string)$it['course_code'],
                'course_title'     => (string)$it['course_title'],
                'exam_date'        => (string)($it['exam_date'] ?? ''),
                'time_slot'        => $slot,
                'section'          => trim((string)((($routine['section'] ?? '') !== '') ? $routine['section'] : ($routine['shift'] ?? ''))),
            ];
        }
        // Enrolled (registered + active) students covered by this routine
        $enr = $db->prepare(
            "SELECT COUNT(DISTINCT reg.student_id)
               FROM exam_routine_items i
               JOIN co_registrations reg ON reg.offer_subject_id = i.offer_subject_id
               JOIN students s           ON s.id = reg.student_id
              WHERE i.routine_id = ? AND s.status = 'Active'"
        );
        $enr->execute([$routine_id]);
        $prefill = [
            'exam_name'   => $routine['exam_name'] . ($routine['exam_year'] ? ' – ' . $routine['exam_year'] : ''),
            'semester'    => (string)($routine['semester'] ?? ''),
            'dept_id'     => (int)$routine['dept_id'],
            'program_id'  => (int)($routine['program_id'] ?? 0),
            'batch_id'    => (int)($routine['batch_id'] ?? 0),
            'batch_label' => (string)($routine['batch_name'] ?? ''),
            'courses'     => $pf_courses,
            'enrolled'    => (int)$enr->fetchColumn(),
        ];
        if ($prefill['semester'] !== '' && !in_array($prefill['semester'], $semester_opts, true)) {
            array_unshift($semester_opts, $prefill['semester']);
        }
    }
}

// Programs for the (pre)selected department so the select renders server-side
$pre_dept     = $prefill ? (int)$prefill['dept_id'] : (int)($_POST['dept_id'] ?? 0);
$programs_pre = [];
if ($pre_dept > 0) {
    $ps = $db->prepare('SELECT id, program_name FROM dept_academic_programs WHERE dept_id = ? AND is_active = 1 ORDER BY program_name ASC');
    $ps->execute([$pre_dept]);
    $programs_pre = $ps->fetchAll();
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $exam_name   = trim($_POST['exam_name']   ?? '');
    $semester    = trim($_POST['semester']    ?? '');
    $dept_id     = (int)($_POST['dept_id']    ?? 0);
    $program_id  = (int)($_POST['program_id'] ?? 0);
    $batch_id    = (int)($_POST['batch_id']   ?? 0) ?: null;
    $batch_label = trim($_POST['batch_label'] ?? '');
    $is_active   = isset($_POST['is_active']) ? 1 : 0;

    // Courses
    $course_codes    = $_POST['course_code']      ?? [];
    $course_titles   = $_POST['course_title']     ?? [];
    $course_dates    = $_POST['exam_date']        ?? [];
    $course_times    = $_POST['time_slot']        ?? [];
    $course_sections = $_POST['section']          ?? [];
    $course_subjects = $_POST['offer_subject_id'] ?? [];

    if ($exam_name === '') $errors[] = 'Exam name is required.';
    if ($semester === '')  $errors[] = 'Semester is required.';
    if ($dept_id <= 0)     $errors[] = 'Please select a department.';
    if ($program_id <= 0)  $errors[] = 'Please select a program.';

    if (empty($errors)) {
        // Validated exam-routine link (only stored when the column exists)
        $rid = (int)($_POST['routine_id'] ?? 0) ?: null;
        if ($rid) {
            try {
                $chk = $db->prepare('SELECT 1 FROM exam_routines WHERE id = ?');
                $chk->execute([$rid]);
                if (!$chk->fetchColumn()) $rid = null;
            } catch (Throwable $e) {
                $rid = null;
            }
        }

        if ($has_routine_col) {
            $db->prepare(
                'INSERT INTO ac_admit_cards (exam_name, semester, dept_id, program_id, batch_id, batch_label, routine_id, is_active, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([$exam_name, $semester, $dept_id, $program_id, $batch_id, $batch_label ?: null, $rid, $is_active, auth_user()['id']]);
        } else {
            $db->prepare(
                'INSERT INTO ac_admit_cards (exam_name, semester, dept_id, program_id, batch_id, batch_label, is_active, created_by)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([$exam_name, $semester, $dept_id, $program_id, $batch_id, $batch_label ?: null, $is_active, auth_user()['id']]);
        }
        $card_id = (int)$db->lastInsertId();

        // Insert courses
        foreach ($course_codes as $i => $code) {
            $code  = trim($code);
            $title = trim($course_titles[$i]  ?? '');
            if ($code === '' && $title === '') continue;
            $date  = trim($course_dates[$i]   ?? '') ?: null;
            $time  = trim($course_times[$i]   ?? '') ?: null;
            $sect  = trim($course_sections[$i] ?? '') ?: null;
            $osid  = (int)($course_subjects[$i] ?? 0) ?: null;
            if ($has_subject_col) {
                $db->prepare(
                    'INSERT INTO ac_admit_card_courses (admit_card_id, offer_subject_id, course_code, course_title, exam_date, time_slot, section, sort_order)
                     VALUES (?,?,?,?,?,?,?,?)'
                )->execute([$card_id, $osid, $code, $title, $date, $time, $sect, $i]);
            } else {
                $db->prepare(
                    'INSERT INTO ac_admit_card_courses (admit_card_id, course_code, course_title, exam_date, time_slot, section, sort_order)
                     VALUES (?,?,?,?,?,?,?)'
                )->execute([$card_id, $code, $title, $date, $time, $sect, $i]);
            }
        }

        flash_set('success', 'Admit card created successfully.');
        redirect(APP_URL . '/admit-card/view.php?id=' . $card_id);
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-plus me-2 text-primary"></i>New Admit Card</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admit-card/index.php">Admit Cards</a></li>
            <li class="breadcrumb-item active">New</li>
        </ol></nav>
    </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul>
</div>
<?php endif; ?>

<?php if ($routines): ?>
<div class="card mb-4">
    <div class="card-header py-3 px-4 fw-semibold">
        <i class="fas fa-calendar-alt me-2 text-primary"></i>Create from Exam Routine
    </div>
    <div class="card-body px-4">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-9">
                <label class="form-label fw-semibold small">Exam Routine</label>
                <select name="routine_id" class="form-select">
                    <option value="">— Select a routine —</option>
                    <?php foreach ($routines as $r): ?>
                    <option value="<?= (int)$r['id'] ?>" <?= $routine_id === (int)$r['id'] ? 'selected' : '' ?>>
                        <?= h($r['exam_name'] . ($r['exam_year'] ? ' – ' . $r['exam_year'] : '')
                            . ' | ' . $r['dept_name']
                            . ($r['program_name'] ? ' | ' . $r['program_name'] : '')
                            . ($r['batch_name'] ? ' | Batch ' . $r['batch_name'] : '')
                            . ($r['shift'] ? ' | ' . $r['shift'] : '')
                            . ($r['semester'] ? ' | ' . $r['semester'] : '')) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-outline-primary w-100">
                    <i class="fas fa-file-import me-1"></i> Load Routine
                </button>
            </div>
        </form>
        <?php if ($prefill): ?>
        <div class="alert alert-info small mt-3 mb-0">
            Loaded from the routine: <strong><?= count($prefill['courses']) ?></strong> course(s),
            <strong><?= (int)$prefill['enrolled'] ?></strong> enrolled (registered, active) student(s).
            Only these enrolled students will be able to view / download this admit card.
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<form method="post">
<?= csrf_field() ?>
<?php if ($prefill && $routine_id > 0): ?>
<input type="hidden" name="routine_id" value="<?= (int)$routine_id ?>">
<?php endif; ?>
<div class="row g-4">
    <!-- Left: Card Details -->
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header py-3 px-4 fw-semibold">
                <i class="fas fa-id-card me-2 text-primary"></i>Admit Card Details
            </div>
            <div class="card-body px-4">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Exam Name <span class="text-danger">*</span></label>
                    <input type="text" name="exam_name" class="form-control"
                           value="<?= $prefill ? h($prefill['exam_name']) : old('exam_name') ?>" placeholder="e.g. Mid Term-1 Exam" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Semester <span class="text-danger">*</span></label>
                    <select name="semester" class="form-select" required>
                        <option value="">— Select Semester —</option>
                        <?php foreach ($semester_opts as $s): ?>
                        <option value="<?= h($s) ?>" <?= ($prefill ? $prefill['semester'] === $s : old('semester') === $s) ? 'selected' : '' ?>><?= h($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Department <span class="text-danger">*</span></label>
                        <select name="dept_id" id="dept_id" class="form-select" required>
                            <option value="">— Select Dept —</option>
                            <?php foreach ($depts as $d): ?>
                            <option value="<?= $d['id'] ?>" <?= ($prefill ? $prefill['dept_id'] : (int)old('dept_id')) === (int)$d['id'] ? 'selected' : '' ?>>
                                <?= h($d['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Program <span class="text-danger">*</span></label>
                        <select name="program_id" id="program_id" class="form-select" required>
                            <option value="">— Select Program —</option>
                            <?php foreach ($programs_pre as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= ($prefill ? $prefill['program_id'] : (int)($_POST['program_id'] ?? 0)) === (int)$p['id'] ? 'selected' : '' ?>>
                                <?= h($p['program_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Batch (optional)</label>
                        <select name="batch_id" class="form-select">
                            <option value="">— All Batches —</option>
                            <?php foreach ($batches as $b): ?>
                            <option value="<?= $b['id'] ?>" <?= ($prefill ? $prefill['batch_id'] : (int)old('batch_id')) === (int)$b['id'] ? 'selected' : '' ?>>
                                <?= h($b['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Batch Label (for card display)</label>
                        <input type="text" name="batch_label" class="form-control"
                               value="<?= $prefill ? h($prefill['batch_label']) : old('batch_label') ?>" placeholder="e.g. 12/66">
                    </div>
                </div>
                <div class="mt-3 form-check">
                    <input type="checkbox" name="is_active" id="is_active" class="form-check-input" value="1"
                           <?= old('is_active', '1') ? 'checked' : '' ?>>
                    <label class="form-check-label" for="is_active">Active (visible to students)</label>
                </div>
            </div>
        </div>
    </div>

    <!-- Right: Actions -->
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header py-3 px-4 fw-semibold">
                <i class="fas fa-info-circle me-2 text-muted"></i>Note
            </div>
            <div class="card-body px-4">
                <p class="text-muted small mb-0">
                    Students whose department and program match this admit card will be able to view and download it,
                    provided their total outstanding dues are <strong>500 BDT or less</strong>.
                    When the card is created from an <strong>exam routine</strong>, it is additionally restricted to
                    students actually <strong>enrolled (registered)</strong> in the routine's courses, and each
                    student's card lists only the courses they registered for.
                    You can grant individual exceptions on the card's detail page.
                </p>
            </div>
        </div>
        <div class="mt-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary flex-fill">
                <i class="fas fa-save me-1"></i> Save Admit Card
            </button>
            <a href="<?= APP_URL ?>/admit-card/index.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </div>
</div>

<!-- Courses Section -->
<div class="card mt-4">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <span class="fw-semibold"><i class="fas fa-book me-2 text-success"></i>Courses</span>
        <button type="button" class="btn btn-sm btn-outline-success" id="addCourseRow">
            <i class="fas fa-plus me-1"></i> Add Course
        </button>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" id="coursesTable">
                <thead class="table-light">
                    <tr>
                        <th class="px-3">Course Code</th>
                        <th>Course Title</th>
                        <th>Exam Date</th>
                        <th>Time Slot</th>
                        <th>Section</th>
                        <th style="width:50px;"></th>
                    </tr>
                </thead>
                <tbody id="courseBody">
                    <?php $course_rows = ($prefill && $prefill['courses']) ? $prefill['courses'] : [null]; ?>
                    <?php foreach ($course_rows as $c): ?>
                    <tr class="course-row">
                        <td class="px-3">
                            <input type="hidden" name="offer_subject_id[]" value="<?= $c ? (int)$c['offer_subject_id'] : '' ?>">
                            <input type="text" name="course_code[]" class="form-control form-control-sm" placeholder="e.g. HRM-6273" value="<?= $c ? h($c['course_code']) : '' ?>">
                        </td>
                        <td><input type="text" name="course_title[]" class="form-control form-control-sm" placeholder="Course Title" value="<?= $c ? h($c['course_title']) : '' ?>"></td>
                        <td><input type="date" name="exam_date[]" class="form-control form-control-sm" value="<?= $c ? h($c['exam_date']) : '' ?>"></td>
                        <td><input type="text" name="time_slot[]" class="form-control form-control-sm" placeholder="e.g. 3:00pm-4:30pm" value="<?= $c ? h($c['time_slot']) : '' ?>"></td>
                        <td><input type="text" name="section[]" class="form-control form-control-sm" placeholder="e.g. A (69 Cr)" value="<?= $c ? h($c['section']) : '' ?>"></td>
                        <td><button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="fas fa-times"></i></button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</form>

<script>
document.getElementById('dept_id').addEventListener('change', function() {
    const deptId = this.value;
    const programSel = document.getElementById('program_id');
    programSel.innerHTML = '<option value="">Loading…</option>';
    if (!deptId) { programSel.innerHTML = '<option value="">— Select Program —</option>'; return; }
    fetch('<?= APP_URL ?>/course-offer/get-programs.php?dept_id=' + deptId)
        .then(r => r.json())
        .then(data => {
            programSel.innerHTML = '<option value="">— Select Program —</option>';
            data.forEach(p => {
                const opt = document.createElement('option');
                opt.value = p.id;
                opt.textContent = p.program_name;
                programSel.appendChild(opt);
            });
        });
});

document.getElementById('addCourseRow').addEventListener('click', function() {
    const tbody = document.getElementById('courseBody');
    const row = tbody.querySelector('.course-row').cloneNode(true);
    row.querySelectorAll('input').forEach(i => i.value = '');
    tbody.appendChild(row);
});

document.getElementById('courseBody').addEventListener('click', function(e) {
    if (e.target.closest('.remove-row')) {
        const rows = this.querySelectorAll('.course-row');
        if (rows.length > 1) e.target.closest('.course-row').remove();
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
