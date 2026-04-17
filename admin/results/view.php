<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('results');
require_once __DIR__ . '/helpers.php';

$id       = (int)($_GET['id'] ?? 0);
$exam     = rm_get_exam($id);
$subjects = rm_get_subjects($id);
$grades   = rm_get_grades($id);
$students = rm_get_exam_students($id);

$page_title = h($exam['exam_title']);

// Decide active tab
$tab = $_GET['tab'] ?? 'grades';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/results/index.php">Results</a></li>
            <li class="breadcrumb-item active"><?= h($exam['exam_title']) ?></li>
        </ol>
    </nav>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= APP_URL ?>/results/print.php?id=<?= $id ?>" target="_blank"
           class="btn btn-outline-secondary btn-sm" style="border-radius:10px;">
            <i class="fas fa-print me-1"></i> Print
        </a>
        <?php if (rm_is_staff()): ?>
        <a href="<?= APP_URL ?>/results/edit.php?id=<?= $id ?>"
           class="btn btn-outline-primary btn-sm" style="border-radius:10px;">
            <i class="fas fa-edit me-1"></i> Edit Exam
        </a>
        <?php endif; ?>
    </div>
</div>

<?php flash_show(); ?>

<!-- Exam header card -->
<div class="card mb-4" style="border-radius:12px; border-left:4px solid #002147;">
    <div class="card-body px-4 py-3">
        <div class="row g-2">
            <div class="col-md-8">
                <h5 class="mb-1 fw-bold" style="color:#002147;"><?= h($exam['exam_title']) ?></h5>
                <div class="text-muted small">
                    <i class="fas fa-university me-1"></i><?= h($exam['faculty_label'] ?? '') ?>
                    &nbsp;·&nbsp;
                    <strong><?= h($exam['dept_name']) ?></strong>
                    <?php if ($exam['program_name']): ?>
                    &nbsp;·&nbsp;<?= h($exam['program_name']) ?>
                    <?php endif; ?>
                </div>
                <?php if ($exam['exam_level']): ?>
                <div class="small text-muted mt-1"><?= h($exam['exam_level']) ?></div>
                <?php endif; ?>
            </div>
            <div class="col-md-4 text-md-end">
                <?php if ($exam['batch']): ?>
                <div class="small"><span class="text-muted">Batch:</span> <strong><?= h($exam['batch']) ?></strong></div>
                <?php endif; ?>
                <?php if ($exam['enrollment_semester']): ?>
                <div class="small"><span class="text-muted">Enrollment:</span> <?= h($exam['enrollment_semester']) ?></div>
                <?php endif; ?>
                <?php if ($exam['completion_semester']): ?>
                <div class="small"><span class="text-muted">Completion:</span> <?= h($exam['completion_semester']) ?></div>
                <?php endif; ?>
                <div class="mt-1">
                    <?php if ($exam['is_published']): ?>
                    <span class="badge bg-success">Published</span>
                    <?php else: ?>
                    <span class="badge bg-warning text-dark">Draft</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Stats row -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center border-0 shadow-sm" style="border-radius:10px;">
            <div class="card-body py-3">
                <div class="fw-bold fs-4" style="color:#002147;"><?= count($subjects) ?></div>
                <div class="small text-muted">Subjects</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center border-0 shadow-sm" style="border-radius:10px;">
            <div class="card-body py-3">
                <div class="fw-bold fs-4" style="color:#002147;"><?= count($students) ?></div>
                <div class="small text-muted">Students</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center border-0 shadow-sm" style="border-radius:10px;">
            <div class="card-body py-3">
                <?php $total_grades = array_sum(array_map('count', $grades)); ?>
                <div class="fw-bold fs-4" style="color:#D21034;"><?= $total_grades ?></div>
                <div class="small text-muted">Grade Entries</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center border-0 shadow-sm" style="border-radius:10px;">
            <div class="card-body py-3">
                <?php
                $expected = count($students) * count($subjects);
                $pct = $expected > 0 ? round(($total_grades / $expected) * 100) : 0;
                ?>
                <div class="fw-bold fs-4" style="color:#002147;"><?= $pct ?>%</div>
                <div class="small text-muted">Completion</div>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4" id="viewTabs">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'grades' ? 'active' : '' ?>"
           href="?id=<?= $id ?>&tab=grades">
            <i class="fas fa-table me-1"></i> Grade Matrix
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'subjects' ? 'active' : '' ?>"
           href="?id=<?= $id ?>&tab=subjects">
            <i class="fas fa-list-ol me-1"></i> Subjects
            <span class="badge bg-secondary ms-1"><?= count($subjects) ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'add_student' ? 'active' : '' ?>"
           href="?id=<?= $id ?>&tab=add_student">
            <i class="fas fa-user-plus me-1"></i> Add Student
        </a>
    </li>
</ul>

<?php if ($tab === 'subjects'): ?>
<!-- ══════════════════════════════ SUBJECTS TAB ══════════════════════════════ -->
<div class="card" style="border-radius:12px;">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-list-ol me-2 text-muted"></i>Subjects / Courses</h6>
        <?php if (rm_is_staff()): ?>
        <div class="d-flex gap-2">
            <?php if ($exam['program_id']): ?>
            <a href="<?= APP_URL ?>/results/subjects/import.php?exam_id=<?= $id ?>"
               class="btn btn-outline-success btn-sm" style="border-radius:7px;">
                <i class="fas fa-file-import me-1"></i> Import from Curriculum
            </a>
            <?php endif; ?>
            <a href="<?= APP_URL ?>/results/subjects/create.php?exam_id=<?= $id ?>"
               class="btn btn-primary btn-sm" style="border-radius:7px;">
                <i class="fas fa-plus me-1"></i> Add Subject
            </a>
        </div>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:50px;">#</th>
                        <th>Course Code</th>
                        <th>Course Title</th>
                        <th>Credits</th>
                        <th>From Curriculum</th>
                        <?php if (rm_is_staff()): ?><th class="text-end pe-4">Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($subjects)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">
                        No subjects added yet.
                        <?php if (rm_is_staff()): ?>
                        <a href="<?= APP_URL ?>/results/subjects/create.php?exam_id=<?= $id ?>">Add one</a>
                        <?php if ($exam['program_id']): ?>
                        or <a href="<?= APP_URL ?>/results/subjects/import.php?exam_id=<?= $id ?>">import from curriculum</a>.
                        <?php endif; ?>
                        <?php endif; ?>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($subjects as $i => $s): ?>
                    <tr>
                        <td class="px-4"><?= $i + 1 ?></td>
                        <td><span class="badge bg-light text-dark border"><?= h($s['course_code'] ?? '—') ?></span></td>
                        <td class="fw-medium"><?= h($s['course_title']) ?></td>
                        <td><?= $s['credits'] !== null ? h($s['credits']) : '—' ?></td>
                        <td>
                            <?php if ($s['curriculum_id']): ?>
                            <span class="badge bg-success bg-opacity-10 text-success border border-success" style="font-size:.75rem;">
                                <i class="fas fa-link me-1"></i>Linked
                            </span>
                            <?php else: ?>
                            <span class="text-muted small">Manual</span>
                            <?php endif; ?>
                        </td>
                        <?php if (rm_is_staff()): ?>
                        <td class="text-end pe-4">
                            <div class="d-flex gap-1 justify-content-end">
                                <a href="<?= APP_URL ?>/results/subjects/edit.php?id=<?= $s['id'] ?>&exam_id=<?= $id ?>"
                                   class="btn btn-sm btn-outline-primary" style="border-radius:7px;">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form method="POST" action="<?= APP_URL ?>/results/subjects/delete.php"
                                      onsubmit="return confirm('Delete subject &quot;<?= h($s['course_title']) ?>&quot;? All grades for this subject will also be removed.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                    <input type="hidden" name="exam_id" value="<?= $id ?>">
                                    <button class="btn btn-sm btn-outline-danger" style="border-radius:7px;">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
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

<?php elseif ($tab === 'add_student'): ?>
<!-- ══════════════════════════════ ADD STUDENT TAB ══════════════════════════ -->
<?php if (!rm_is_staff()): ?>
<div class="alert alert-warning">You do not have permission to add students.</div>
<?php else: ?>
<div class="card" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-user-plus me-2 text-muted"></i>Add Student to Result Sheet</h6>
    </div>
    <div class="card-body p-4">

        <?php if (empty($subjects)): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            Please <a href="?id=<?= $id ?>&tab=subjects">add subjects</a> before adding students.
        </div>
        <?php else: ?>

        <!-- Search box – auto-fetch from students table -->
        <div class="row g-3 mb-4 align-items-end">
            <div class="col-md-5">
                <label class="form-label fw-medium">Search Student</label>
                <input type="text" id="student_search" class="form-control"
                       placeholder="Type student ID or name…">
                <div class="form-text">Searches automatically in the student database.</div>
            </div>
            <div class="col-md-5">
                <div id="student_results" class="list-group" style="max-height:220px;overflow-y:auto;"></div>
            </div>
        </div>

        <!-- Grade entry for selected student -->
        <div id="grade_form_wrap" style="display:none;">
            <hr>
            <h6 class="fw-semibold mb-3">
                Enter Grades for: <span id="sel_student_name" class="text-primary"></span>
                <small class="text-muted" id="sel_student_sid"></small>
            </h6>
            <form method="POST" action="<?= APP_URL ?>/results/grades/save.php" id="grade_entry_form">
                <?= csrf_field() ?>
                <input type="hidden" name="exam_id" value="<?= $id ?>">
                <input type="hidden" name="student_id"   id="inp_student_id"   value="">
                <input type="hidden" name="student_sid"  id="inp_student_sid"  value="">
                <input type="hidden" name="student_name" id="inp_student_name" value="">

                <div class="table-responsive mb-3">
                    <table class="table table-bordered align-middle" style="font-size:.875rem;">
                        <thead class="table-light">
                            <tr>
                                <th style="width:50px;">#</th>
                                <th>Course Code</th>
                                <th>Course Title</th>
                                <th style="width:110px;">Marks (0–100)</th>
                                <th style="width:90px;">Grade</th>
                                <th style="width:80px;">Point</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($subjects as $i => $s): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><span class="badge bg-light text-dark border"><?= h($s['course_code'] ?? '—') ?></span></td>
                            <td><?= h($s['course_title']) ?></td>
                            <td>
                                <input type="number"
                                       name="marks[<?= $s['id'] ?>]"
                                       class="form-control form-control-sm marks-input"
                                       data-subject="<?= $s['id'] ?>"
                                       min="0" max="100" step="0.01"
                                       placeholder="—"
                                       style="width:90px;">
                            </td>
                            <td>
                                <span class="grade-display fw-bold" id="grade_<?= $s['id'] ?>">—</span>
                            </td>
                            <td>
                                <span class="point-display" id="point_<?= $s['id'] ?>">—</span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-success" style="border-radius:10px;">
                        <i class="fas fa-save me-1"></i> Save Grades
                    </button>
                    <button type="button" id="clear_form_btn" class="btn btn-light" style="border-radius:10px;">
                        Clear
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php else: ?>
<!-- ══════════════════════════════ GRADE MATRIX TAB ═════════════════════════ -->
<?php if (empty($subjects)): ?>
<div class="alert alert-info">
    <i class="fas fa-info-circle me-2"></i>
    No subjects yet. Go to the
    <a href="?id=<?= $id ?>&tab=subjects" class="alert-link">Subjects tab</a> to add them.
</div>
<?php elseif (empty($students)): ?>
<div class="alert alert-info">
    <i class="fas fa-info-circle me-2"></i>
    No students yet. Go to the
    <a href="?id=<?= $id ?>&tab=add_student" class="alert-link">Add Student tab</a> to enter grades.
</div>
<?php else: ?>
<div class="card" style="border-radius:12px; overflow:hidden;">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-table me-2 text-muted"></i>Student Performance</h6>
        <div class="d-flex gap-2">
            <a href="?id=<?= $id ?>&tab=add_student" class="btn btn-sm btn-outline-success" style="border-radius:7px;">
                <i class="fas fa-user-plus me-1"></i> Add / Edit Student
            </a>
            <a href="<?= APP_URL ?>/results/print.php?id=<?= $id ?>" target="_blank"
               class="btn btn-sm btn-outline-secondary" style="border-radius:7px;">
                <i class="fas fa-print me-1"></i> Print
            </a>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered table-sm mb-0" style="font-size:.8rem; min-width:700px;">
                <thead>
                    <tr style="background-color:#002147; color:#fff;">
                        <th class="px-3" style="width:40px;">SL</th>
                        <th style="min-width:140px;">Student ID</th>
                        <th style="min-width:160px;">Name</th>
                        <?php foreach ($subjects as $s): ?>
                        <th class="text-center" style="min-width:90px;">
                            <?= h($s['course_code'] ?? $s['course_title']) ?>
                        </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($students as $idx => $st):
                    $sid = $st['student_sid'];
                    $name = $st['s_full_name'] ?: $st['student_name'];
                    $s_id_str = $st['s_student_id'] ?: $sid;
                ?>
                <tr>
                    <td class="px-3"><?= $idx + 1 ?></td>
                    <td><code class="text-primary"><?= h($s_id_str) ?></code></td>
                    <td class="fw-medium"><?= h($name) ?></td>
                    <?php foreach ($subjects as $s):
                        $g = $grades[$sid][$s['id']] ?? null;
                    ?>
                    <td class="text-center">
                        <?php if ($g): ?>
                        <strong><?= h($g['letter_grade']) ?></strong>
                        <br><small class="text-muted">(<?= h(number_format((float)$g['grade_point'], 2)) ?>)</small>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($tab === 'add_student' && rm_is_staff() && !empty($subjects)): ?>
<script>
(function () {
    var gradeScale = <?= json_encode(array_map(
        fn($r) => ['min' => $r[0], 'max' => $r[1] === PHP_INT_MAX ? 10000 : $r[1], 'letter' => $r[2], 'point' => $r[3]],
        rm_grading_scale()
    )) ?>;

    function computeGrade(marks) {
        for (var i = 0; i < gradeScale.length; i++) {
            var r = gradeScale[i];
            if (marks >= r.min && marks < r.max) {
                return { letter: r.letter, point: r.point };
            }
        }
        return { letter: 'F', point: 0 };
    }

    // Marks input → auto-compute grade
    document.querySelectorAll('.marks-input').forEach(function (inp) {
        inp.addEventListener('input', function () {
            var sid = this.dataset.subject;
            var raw = parseFloat(this.value);
            if (isNaN(raw) || this.value === '') {
                document.getElementById('grade_' + sid).textContent = '—';
                document.getElementById('point_' + sid).textContent = '—';
            } else {
                var g = computeGrade(raw);
                document.getElementById('grade_' + sid).textContent = g.letter;
                document.getElementById('point_' + sid).textContent = g.point.toFixed(2);
            }
        });
    });

    // Student search (auto-fetch from students table)
    var searchInput  = document.getElementById('student_search');
    var resultsDiv   = document.getElementById('student_results');
    var formWrap     = document.getElementById('grade_form_wrap');
    var searchTimer  = null;

    // Pre-fill grades for a student already in this exam
    function prefillGrades(sid) {
        fetch('<?= APP_URL ?>/results/grades/get.php?exam_id=<?= $id ?>&student_sid=' + encodeURIComponent(sid))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                data.forEach(function (g) {
                    var inp = document.querySelector('.marks-input[data-subject="' + g.subject_id + '"]');
                    if (inp && g.marks !== null) {
                        inp.value = g.marks;
                        inp.dispatchEvent(new Event('input'));
                    }
                });
            });
    }

    function selectStudent(id, sid, name) {
        document.getElementById('inp_student_id').value   = id;
        document.getElementById('inp_student_sid').value  = sid;
        document.getElementById('inp_student_name').value = name;
        document.getElementById('sel_student_name').textContent = name;
        document.getElementById('sel_student_sid').textContent  = ' (' + sid + ')';
        formWrap.style.display = '';
        resultsDiv.innerHTML = '';
        searchInput.value = name + ' (' + sid + ')';
        // Reset marks
        document.querySelectorAll('.marks-input').forEach(function (inp) {
            inp.value = '';
            inp.dispatchEvent(new Event('input'));
        });
        if (sid) prefillGrades(sid);
    }

    searchInput.addEventListener('input', function () {
        var q = this.value.trim();
        clearTimeout(searchTimer);
        if (q.length < 2) { resultsDiv.innerHTML = ''; return; }
        searchTimer = setTimeout(function () {
            fetch('<?= APP_URL ?>/results/get-students.php?q=' + encodeURIComponent(q)
                + '&dept_id=<?= (int)$exam['dept_id'] ?>'
                + '&program_id=<?= (int)($exam['program_id'] ?? 0) ?>'
            )
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    resultsDiv.innerHTML = '';
                    if (!data.length) {
                        resultsDiv.innerHTML = '<div class="list-group-item text-muted small">No students found</div>';
                        return;
                    }
                    data.forEach(function (s) {
                        var a = document.createElement('button');
                        a.type = 'button';
                        a.className = 'list-group-item list-group-item-action py-2';
                        a.style.fontSize = '.85rem';
                        a.innerHTML = '<strong>' + s.student_id + '</strong> — ' + s.full_name
                                    + (s.batch ? ' <span class="badge bg-light text-dark border ms-1">' + s.batch + '</span>' : '');
                        a.addEventListener('click', function () {
                            selectStudent(s.id, s.student_id, s.full_name);
                        });
                        resultsDiv.appendChild(a);
                    });
                });
        }, 300);
    });

    document.getElementById('clear_form_btn').addEventListener('click', function () {
        document.getElementById('inp_student_id').value   = '';
        document.getElementById('inp_student_sid').value  = '';
        document.getElementById('inp_student_name').value = '';
        document.getElementById('sel_student_name').textContent = '';
        document.getElementById('sel_student_sid').textContent  = '';
        formWrap.style.display = 'none';
        searchInput.value = '';
        document.querySelectorAll('.marks-input').forEach(function (inp) {
            inp.value = '';
            inp.dispatchEvent(new Event('input'));
        });
    });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
