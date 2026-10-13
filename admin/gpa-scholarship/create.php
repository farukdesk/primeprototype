<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('gpa-scholarship', 'can_create');

$page_title = 'Run GPA Scholarship';
$db = db();

function gs_manual_fee_semester_options(int $dept_id, int $program_id, int $batch_id): array
{
    if ($dept_id <= 0 || $batch_id <= 0) return [];
    $where = ["s.status = 'Active'", 's.dept_id = ?', 's.batch_id = ?'];
    $params = [$dept_id, $batch_id];
    if ($program_id > 0) {
        $where[] = 's.program_id = ?';
        $params[] = $program_id;
    }

    $sql = 'SELECT DISTINCT sf.semester_number
            FROM students s
            JOIN sfp_packages p ON p.student_id = s.id
            JOIN sfp_semester_fees sf ON sf.package_id = p.id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY sf.semester_number ASC';
    $st = db()->prepare($sql);
    $st->execute($params);

    $rows = $st->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($rows as $r) {
        $n = (int)$r;
        if ($n > 0) $out[] = $n;
    }
    return array_values(array_unique($out));
}

function gs_manual_gpa_map(int $exam_id, array $student_ids): array
{
    if ($exam_id <= 0 || empty($student_ids)) return [];
    $db = db();
    $ph = implode(',', array_fill(0, count($student_ids), '?'));

    $fail_case = "(CASE
        WHEN UPPER(TRIM(COALESCE(sg.letter_grade, ''))) IN ('F','FAIL','FAILED') THEN 1
        WHEN sg.grade_point IS NOT NULL
             AND sg.grade_point <= 0
             AND UPPER(TRIM(COALESCE(sg.letter_grade, ''))) NOT IN ('INCOM','I','INC') THEN 1
        ELSE 0
    END)";
    $incom_case = "(CASE
        WHEN UPPER(TRIM(COALESCE(sg.letter_grade, ''))) IN ('INCOM','I','INC') OR sg.grade_point IS NULL THEN 1
        ELSE 0
    END)";

    $sql = "SELECT sg.student_id,
                   ROUND(
                     SUM(CASE WHEN {$fail_case} = 0 AND {$incom_case} = 0
                              THEN COALESCE(sg.grade_point,0) * CASE WHEN COALESCE(ms.credits, cc.credit, 0) > 0 THEN COALESCE(ms.credits, cc.credit, 0) ELSE 1 END
                              ELSE 0 END)
                     /
                     NULLIF(SUM(CASE WHEN {$fail_case} = 0 AND {$incom_case} = 0
                                     THEN CASE WHEN COALESCE(ms.credits, cc.credit, 0) > 0 THEN COALESCE(ms.credits, cc.credit, 0) ELSE 1 END
                                     ELSE 0 END), 0),
                   2) AS semester_gpa,
                   SUM({$fail_case}) AS fail_count,
                   SUM({$incom_case}) AS incom_count
            FROM result_mark_sheets ms
            JOIN result_sheet_grades sg ON sg.sheet_id = ms.id
            LEFT JOIN course_curriculum cc ON cc.id = ms.curriculum_id
            WHERE ms.workflow_status = 'published'
              AND ms.exam_id = ?
              AND sg.student_id IN ({$ph})
            GROUP BY sg.student_id";

    $st = $db->prepare($sql);
    $st->execute(array_merge([$exam_id], $student_ids));

    $out = [];
    foreach ($st->fetchAll() as $row) {
        $sid = (int)($row['student_id'] ?? 0);
        if ($sid <= 0) continue;
        $has_fail = (int)($row['fail_count'] ?? 0) > 0;
        $has_incom = (int)($row['incom_count'] ?? 0) > 0;
        $out[$sid] = ($has_fail || $has_incom) ? null : ((isset($row['semester_gpa']) && $row['semester_gpa'] !== null) ? (float)$row['semester_gpa'] : null);
    }

    $missing = [];
    foreach ($student_ids as $sid) {
        if (!array_key_exists($sid, $out)) $missing[] = (int)$sid;
    }
    if (empty($missing)) return $out;

    $ph2 = implode(',', array_fill(0, count($missing), '?'));
    $legacy_sql = "SELECT rg.student_id,
                          ROUND(SUM(COALESCE(rg.grade_point,0) * CASE WHEN COALESCE(rs.credits,0) > 0 THEN rs.credits ELSE 1 END)
                          / NULLIF(SUM(CASE WHEN COALESCE(rs.credits,0) > 0 THEN rs.credits ELSE 1 END),0), 2) AS semester_gpa
                   FROM result_grades rg
                   JOIN result_subjects rs ON rs.id = rg.subject_id AND rs.exam_id = rg.exam_id
                   WHERE rg.exam_id = ? AND rg.student_id IN ({$ph2})
                   GROUP BY rg.student_id";
    $legacy = $db->prepare($legacy_sql);
    $legacy->execute(array_merge([$exam_id], $missing));
    foreach ($legacy->fetchAll() as $row) {
        $sid = (int)($row['student_id'] ?? 0);
        if ($sid <= 0) continue;
        $out[$sid] = $row['semester_gpa'] !== null ? (float)$row['semester_gpa'] : null;
    }

    return $out;
}

function gs_manual_students(int $dept_id, int $program_id, int $batch_id, int $target_semester_number, int $exam_id): array
{
    if ($dept_id <= 0 || $batch_id <= 0 || $target_semester_number <= 0 || $exam_id <= 0) return [];

    $where = ["s.status = 'Active'", 's.dept_id = ?', 's.batch_id = ?'];
    $params = [$target_semester_number, $dept_id, $batch_id];
    if ($program_id > 0) {
        $where[] = 's.program_id = ?';
        $params[] = $program_id;
    }

    $sql = 'SELECT s.id AS student_pk, s.student_id, s.full_name,
                   p.id AS package_id,
                   sf.id AS target_sf_id,
                   sf.semester_number,
                   sf.semester_label,
                   sf.tuition_fee,
                   sf.tuition_payable
            FROM students s
            LEFT JOIN sfp_packages p ON p.student_id = s.id
            LEFT JOIN sfp_semester_fees sf ON sf.package_id = p.id AND sf.semester_number = ?
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY s.student_id ASC';

    $st = db()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    if (empty($rows)) return [];

    $student_ids = array_map(static fn($r) => (int)$r['student_pk'], $rows);
    $gpa_map = gs_manual_gpa_map($exam_id, $student_ids);

    foreach ($rows as &$row) {
        $sid = (int)$row['student_pk'];
        $row['semester_gpa'] = $gpa_map[$sid] ?? null;
        $row['tuition_fee'] = (float)($row['tuition_fee'] ?? 0);
        $row['tuition_payable'] = (float)($row['tuition_payable'] ?? 0);
    }
    unset($row);

    return $rows;
}

$departments = gs_departments();
$batches = gs_batches();
$exam_options = gs_exam_options();
$errors = [];

$dept_id = (int)($_REQUEST['dept_id'] ?? 0);
$program_id = (int)($_REQUEST['program_id'] ?? 0);
$batch_id = (int)($_REQUEST['batch_id'] ?? 0);
$exam_id = (int)($_REQUEST['exam_id'] ?? 0);
$target_semester_number = (int)($_REQUEST['target_semester_number'] ?? 0);
$programs = $dept_id > 0 ? gs_programs($dept_id) : [];
$students = [];

if ($dept_id > 0 && !can_access_dept($dept_id)) {
    $errors[] = 'You do not have access to the selected department.';
}

if ($program_id > 0 && $dept_id > 0) {
    $ps = $db->prepare('SELECT id FROM dept_academic_programs WHERE id = ? AND dept_id = ? AND is_active = 1');
    $ps->execute([$program_id, $dept_id]);
    if (!$ps->fetch()) $errors[] = 'Invalid program for selected department.';
}

if ($batch_id > 0) {
    $bs = $db->prepare('SELECT id FROM student_batches WHERE id = ? AND is_active = 1');
    $bs->execute([$batch_id]);
    if (!$bs->fetch()) $errors[] = 'Invalid batch selected.';
}

$exam = null;
if ($exam_id > 0) {
    $exam = gs_get_exam($exam_id);
    if (!$exam) {
        $errors[] = 'Selected exam is invalid.';
    } elseif (!empty($exam['dept_id']) && (int)$exam['dept_id'] !== $dept_id) {
        $errors[] = 'Selected department does not match the selected exam.';
    }
}

$semester_options = gs_manual_fee_semester_options($dept_id, $program_id, $batch_id);
if ($target_semester_number <= 0 && !empty($semester_options)) {
    $target_semester_number = (int)$semester_options[0];
}

$filters_ready = $dept_id > 0 && $batch_id > 0 && $exam_id > 0 && $target_semester_number > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    csrf_check();

    if (!$filters_ready) {
        $errors[] = 'Department, batch, exam and semester are required.';
    }

    if (empty($errors)) {
        $students = gs_manual_students($dept_id, $program_id, $batch_id, $target_semester_number, $exam_id);
        $student_map = [];
        foreach ($students as $st_row) {
            $student_map[(int)$st_row['student_pk']] = $st_row;
        }

        $posted = $_POST['scholarship_pct'] ?? [];
        if (!is_array($posted)) $posted = [];

        $user_id = (int)auth_user()['id'];
        $exam_label = trim((string)($exam['exam_title'] ?? ('Exam #' . $exam_id)));

        $summary = ['applied' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($posted as $student_pk_raw => $pct_raw) {
            $student_pk = (int)$student_pk_raw;
            $pct_text = trim((string)$pct_raw);
            if ($pct_text === '') {
                $summary['skipped']++;
                continue;
            }

            if (!isset($student_map[$student_pk])) {
                $summary['failed']++;
                continue;
            }

            if (!is_numeric($pct_text)) {
                $summary['failed']++;
                continue;
            }

            $pct = round((float)$pct_text, 2);
            if ($pct <= 0 || $pct > 100) {
                $summary['failed']++;
                continue;
            }

            $row = $student_map[$student_pk];
            $sf_id = (int)($row['target_sf_id'] ?? 0);
            if ($sf_id <= 0) {
                $summary['failed']++;
                continue;
            }

            if (!isset($row['semester_gpa']) || $row['semester_gpa'] === null) {
                $summary['failed']++;
                continue;
            }

            $source_key = 'gpa-scholarship:manual:' . $exam_id . ':' . $target_semester_number . ':' . $student_pk;
            $label = 'GPA Scholarship';
            $note = 'Module: GPA Scholarship Manual | source_key=' . $source_key
                . ' | Exam=' . $exam_label
                . ' | Semester#=' . $target_semester_number
                . ' | GPA=' . number_format((float)$row['semester_gpa'], 2);

            try {
                $db->beginTransaction();

                $find = $db->prepare('SELECT id FROM sfp_semester_scholarships WHERE sf_id = ? AND note LIKE ? ORDER BY id DESC LIMIT 1');
                $find->execute([$sf_id, '%' . $source_key . '%']);
                $existing_id = (int)($find->fetchColumn() ?: 0);

                if ($existing_id > 0) {
                    $upd = $db->prepare('UPDATE sfp_semester_scholarships
                                         SET label = ?, discount_pct = ?, discount_type = "percentage",
                                             fixed_amount = NULL, note = ?, is_from_policy = 1,
                                             applies_to_fixed = 0, applies_to_english = 0
                                         WHERE id = ?');
                    $upd->execute([$label, $pct, $note, $existing_id]);
                    $summary['updated']++;
                } else {
                    $amount = round((float)$row['tuition_fee'] * $pct / 100, 2);
                    $ins = $db->prepare('INSERT INTO sfp_semester_scholarships
                                        (sf_id, label, discount_pct, discount_type, fixed_amount, amount, note,
                                         is_from_policy, applies_to_fixed, applies_to_english, support_doc_id, created_by)
                                        VALUES (?, ?, ?, "percentage", NULL, ?, ?, 1, 0, 0, NULL, ?)');
                    $ins->execute([$sf_id, $label, $pct, $amount, $note, $user_id]);
                    $summary['applied']++;
                }

                sfp_recalculate_semester($sf_id, $user_id);
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                $summary['failed']++;
            }
        }

        flash_set('success', 'Scholarship save complete: applied ' . $summary['applied'] . ', updated ' . $summary['updated'] . ', skipped ' . $summary['skipped'] . ', failed ' . $summary['failed'] . '.');
        redirect(APP_URL . '/gpa-scholarship/create.php?dept_id=' . $dept_id . '&program_id=' . $program_id . '&batch_id=' . $batch_id . '&exam_id=' . $exam_id . '&target_semester_number=' . $target_semester_number);
    }
}

if ($filters_ready && empty($errors)) {
    $students = gs_manual_students($dept_id, $program_id, $batch_id, $target_semester_number, $exam_id);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">Run GPA Scholarship</h1>
    <a href="<?= APP_URL ?>/gpa-scholarship/index.php" class="btn btn-outline-secondary btn-sm">Back</a>
</div>

<?= flash_show() ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end" id="gs-filter-form">
            <div class="col-md-3">
                <label class="form-label">Department <span class="text-danger">*</span></label>
                <select name="dept_id" id="gs-dept" class="form-select" required>
                    <option value="">Select department</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= $dept_id === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Program</label>
                <select name="program_id" id="gs-program" class="form-select">
                    <option value="0">All programs</option>
                    <?php foreach ($programs as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= $program_id === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['program_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Batch <span class="text-danger">*</span></label>
                <select name="batch_id" class="form-select" required>
                    <option value="">Select batch</option>
                    <?php foreach ($batches as $b): ?>
                    <option value="<?= (int)$b['id'] ?>" <?= $batch_id === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Exam <span class="text-danger">*</span></label>
                <select name="exam_id" id="gs-exam" class="form-select" required>
                    <option value="">Select exam</option>
                    <?php foreach ($exam_options as $ex): ?>
                    <option value="<?= (int)$ex['id'] ?>"
                            data-dept="<?= (int)($ex['dept_id'] ?? 0) ?>"
                            data-program="<?= (int)($ex['program_id'] ?? 0) ?>"
                            <?= $exam_id === (int)$ex['id'] ? 'selected' : '' ?>>
                        <?= h($ex['exam_title']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Semester <span class="text-danger">*</span></label>
                <select name="target_semester_number" class="form-select" required>
                    <option value="">Select semester</option>
                    <?php foreach ($semester_options as $sn): ?>
                    <option value="<?= (int)$sn ?>" <?= $target_semester_number === (int)$sn ? 'selected' : '' ?>>Semester <?= (int)$sn ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <button class="btn btn-primary">Load Students</button>
            </div>
        </form>
    </div>
</div>

<?php if ($filters_ready && empty($errors)): ?>
<div class="card border-0 shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Students</strong>
        <span class="small text-muted">Total: <?= count($students) ?></span>
    </div>
    <div class="card-body p-0">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="dept_id" value="<?= (int)$dept_id ?>">
            <input type="hidden" name="program_id" value="<?= (int)$program_id ?>">
            <input type="hidden" name="batch_id" value="<?= (int)$batch_id ?>">
            <input type="hidden" name="exam_id" value="<?= (int)$exam_id ?>">
            <input type="hidden" name="target_semester_number" value="<?= (int)$target_semester_number ?>">

            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Student</th>
                            <th>Semester GPA</th>
                            <th>Fee Semester</th>
                            <th>Tuition</th>
                            <th style="width:170px;">Scholarship %</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($students)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No students found for selected filters.</td></tr>
                    <?php else: foreach ($students as $i => $st):
                        $sid = (int)$st['student_pk'];
                        $gpa = $st['semester_gpa'];
                        $ready = !empty($st['target_sf_id']) && $gpa !== null;
                        $prefill = trim((string)($_POST['scholarship_pct'][$sid] ?? ''));
                    ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <div class="fw-semibold"><?= h($st['full_name']) ?></div>
                                <div class="small text-muted"><?= h($st['student_id']) ?></div>
                            </td>
                            <td>
                                <?php if ($gpa !== null): ?>
                                    <span class="fw-semibold"><?= number_format((float)$gpa, 2) ?></span>
                                <?php else: ?>
                                    <span class="text-danger">Not available</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($st['target_sf_id'])): ?>
                                    <span>Semester <?= (int)$st['semester_number'] ?></span>
                                    <?php if (!empty($st['semester_label'])): ?><div class="small text-muted"><?= h($st['semester_label']) ?></div><?php endif; ?>
                                <?php else: ?>
                                    <span class="text-danger">Missing fee semester row</span>
                                <?php endif; ?>
                            </td>
                            <td><?= number_format((float)$st['tuition_payable'], 2) ?></td>
                            <td>
                                <input type="number" step="0.01" min="0" max="100"
                                       class="form-control form-control-sm"
                                       name="scholarship_pct[<?= $sid ?>]"
                                       value="<?= h($prefill) ?>"
                                       <?= $ready ? '' : 'disabled' ?>
                                       placeholder="e.g. 25">
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!empty($students)): ?>
            <div class="p-3 border-top">
                <button class="btn btn-success" type="submit">Save Scholarships</button>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
document.getElementById('gs-dept').addEventListener('change', function(){
    const dept = parseInt(this.value || '0', 10);
    const sel = document.getElementById('gs-program');
    sel.innerHTML = '<option value="0">All programs</option>';
    if (!dept) return;
    fetch('<?= APP_URL ?>/gpa-scholarship/get-programs.php?dept_id=' + dept)
      .then(r => r.json()).then(rows => rows.forEach(p => {
        const o = document.createElement('option'); o.value = p.id; o.textContent = p.program_name; sel.appendChild(o);
      }));
});

document.getElementById('gs-exam').addEventListener('change', function(){
    const opt = this.options[this.selectedIndex];
    if (!opt) return;
    const dept = parseInt(opt.getAttribute('data-dept') || '0', 10);
    const program = parseInt(opt.getAttribute('data-program') || '0', 10);
    if (dept > 0) {
        document.getElementById('gs-dept').value = String(dept);
        document.getElementById('gs-dept').dispatchEvent(new Event('change'));
        setTimeout(() => { if (program > 0) document.getElementById('gs-program').value = String(program); }, 200);
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
