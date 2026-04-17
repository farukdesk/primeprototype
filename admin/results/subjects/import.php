<?php
/**
 * Bulk import subjects from course_curriculum into a result exam.
 * Only available when the exam has a program linked.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_access('results', 'can_create');
require_once __DIR__ . '/../helpers.php';

$exam_id = (int)($_GET['exam_id'] ?? $_POST['exam_id'] ?? 0);
if (!$exam_id) { flash_set('error', 'Invalid exam.'); redirect(APP_URL . '/results/index.php'); }
$exam = rm_get_exam($exam_id);

if (!$exam['program_id']) {
    flash_set('error', 'This result exam has no program linked. Cannot import from curriculum.');
    redirect(APP_URL . '/results/view.php?id=' . $exam_id . '&tab=subjects');
}

$page_title = 'Import Subjects from Curriculum';
$errors     = [];

// Load available curriculum courses for this program
$cc_stmt = db()->prepare(
    'SELECT cc.id, cc.course_code, cc.course_name, cc.credit, cc.semester
     FROM course_curriculum cc
     WHERE cc.program_id = ?
     ORDER BY cc.semester ASC, cc.sort_order ASC, cc.sl_no ASC'
);
$cc_stmt->execute([$exam['program_id']]);
$all_cc = $cc_stmt->fetchAll();

// Already-imported curriculum IDs
$already_stmt = db()->prepare(
    'SELECT curriculum_id FROM result_subjects WHERE exam_id = ? AND curriculum_id IS NOT NULL'
);
$already_stmt->execute([$exam_id]);
$already_ids = array_column($already_stmt->fetchAll(), 'curriculum_id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $selected = array_map('intval', (array)($_POST['cc_ids'] ?? []));
    if (empty($selected)) {
        $errors[] = 'Please select at least one course to import.';
    }

    if (empty($errors)) {
        $insert = db()->prepare(
            'INSERT IGNORE INTO result_subjects (exam_id, curriculum_id, course_code, course_title, credits, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $count = 0;
        foreach ($all_cc as $i => $cc) {
            if (in_array((int)$cc['id'], $selected, true)) {
                $insert->execute([
                    $exam_id,
                    $cc['id'],
                    $cc['course_code'] ?: null,
                    $cc['course_name'],
                    $cc['credit'] !== null ? (float)$cc['credit'] : null,
                    $i,
                ]);
                $count++;
            }
        }
        flash_set('success', $count . ' subject(s) imported from curriculum.');
        redirect(APP_URL . '/results/view.php?id=' . $exam_id . '&tab=subjects');
    }
}

// Group by semester for display
$by_sem = [];
foreach ($all_cc as $cc) {
    $by_sem[(int)$cc['semester']][] = $cc;
}

$sem_labels = [
    1=>'1st Year 1st Semester', 2=>'1st Year 2nd Semester', 3=>'1st Year 3rd Semester',
    4=>'2nd Year 1st Semester', 5=>'2nd Year 2nd Semester', 6=>'2nd Year 3rd Semester',
    7=>'3rd Year 1st Semester', 8=>'3rd Year 2nd Semester', 9=>'3rd Year 3rd Semester',
    10=>'4th Year 1st Semester', 11=>'4th Year 2nd Semester', 12=>'4th Year 3rd Semester',
];

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/results/index.php">Results</a></li>
            <li class="breadcrumb-item">
                <a href="<?= APP_URL ?>/results/view.php?id=<?= $exam_id ?>&tab=subjects">
                    <?= h($exam['exam_title']) ?>
                </a>
            </li>
            <li class="breadcrumb-item active">Import from Curriculum</li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="alert alert-info">
    <i class="fas fa-info-circle me-2"></i>
    Importing from <strong><?= h($exam['program_name'] ?? '') ?></strong> curriculum.
    Courses already imported are pre-checked and shown in <span class="text-success">green</span>.
</div>

<?php if (empty($all_cc)): ?>
<div class="alert alert-warning">No courses found in the curriculum for this program.</div>
<?php else: ?>

<form method="POST" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="exam_id" value="<?= $exam_id ?>">

    <div class="d-flex gap-2 mb-3">
        <button type="button" id="select_all" class="btn btn-sm btn-outline-secondary" style="border-radius:7px;">
            Select All
        </button>
        <button type="button" id="deselect_all" class="btn btn-sm btn-outline-secondary" style="border-radius:7px;">
            Deselect All
        </button>
    </div>

    <?php foreach ($by_sem as $sem_no => $rows): ?>
    <div class="card mb-3" style="border-radius:10px;">
        <div class="card-header py-2 px-4" style="background-color:#f8fafc; border-radius:10px 10px 0 0;">
            <strong class="small"><?= h($sem_labels[$sem_no] ?? 'Semester ' . $sem_no) ?></strong>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0 align-middle" style="font-size:.875rem;">
                <thead class="table-light">
                    <tr>
                        <th style="width:50px;" class="ps-4">Import</th>
                        <th style="width:100px;">Code</th>
                        <th>Course Name</th>
                        <th style="width:70px;" class="text-center">Credit</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $cc):
                    $already = in_array((int)$cc['id'], $already_ids, true);
                ?>
                <tr <?= $already ? 'class="table-success"' : '' ?>>
                    <td class="ps-4">
                        <input type="checkbox" name="cc_ids[]" value="<?= $cc['id'] ?>"
                               class="form-check-input cc-check"
                               <?= $already ? 'checked' : '' ?>>
                    </td>
                    <td><span class="badge bg-light text-dark border"><?= h($cc['course_code'] ?? '—') ?></span></td>
                    <td><?= h($cc['course_name']) ?></td>
                    <td class="text-center"><?= $cc['credit'] !== null ? h($cc['credit']) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn btn-success" style="border-radius:10px;">
            <i class="fas fa-file-import me-1"></i> Import Selected
        </button>
        <a href="<?= APP_URL ?>/results/view.php?id=<?= $exam_id ?>&tab=subjects"
           class="btn btn-light" style="border-radius:10px;">Cancel</a>
    </div>
</form>

<script>
document.getElementById('select_all').addEventListener('click', function () {
    document.querySelectorAll('.cc-check').forEach(function (c) { c.checked = true; });
});
document.getElementById('deselect_all').addEventListener('click', function () {
    document.querySelectorAll('.cc-check').forEach(function (c) { c.checked = false; });
});
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
