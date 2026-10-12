<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('gpa-scholarship', 'can_edit');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$config = gs_get_config($id);
if (!$config) {
    flash_set('error', 'Setup not found.');
    redirect(APP_URL . '/gpa-scholarship/index.php');
}

if (!can_access_dept((int)$config['dept_id']) && !is_super_admin()) {
    flash_set('error', 'Access denied for this department scope.');
    redirect(APP_URL . '/gpa-scholarship/index.php');
}

$page_title = 'Edit GPA Scholarship Setup';
$db = db();

$exam_options = gs_exam_options();
$current_exam_id = (int)$config['exam_id'];
$has_current_exam = false;
foreach ($exam_options as $ex) {
    if ((int)$ex['id'] === $current_exam_id) {
        $has_current_exam = true;
        break;
    }
}
if (!$has_current_exam && $current_exam_id > 0) {
    $legacy_exam = gs_get_exam($current_exam_id);
    if ($legacy_exam) $exam_options[] = $legacy_exam;
}
$departments = gs_departments();
$batches = gs_batches();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name = trim((string)($_POST['name'] ?? ''));
    $exam_id = (int)($_POST['exam_id'] ?? 0);
    $dept_id = (int)($_POST['dept_id'] ?? 0);
    $program_id = (int)($_POST['program_id'] ?? 0);
    $batch_id = (int)($_POST['batch_id'] ?? 0);
    $target_semester_number = (int)($_POST['target_semester_number'] ?? 2);
    $combine_mode = (string)($_POST['combine_mode'] ?? 'gpa_only');
    $require_merit_payment_type = !empty($_POST['require_merit_payment_type']) ? 1 : 0;
    $require_gpa_tier = !empty($_POST['require_gpa_tier']) ? 1 : 0;
    $require_attendance_tier = !empty($_POST['require_attendance_tier']) ? 1 : 0;
    $attendance_missing_policy = (string)($_POST['attendance_missing_policy'] ?? 'exclude');
    $attendance_semester = trim((string)($_POST['attendance_semester'] ?? ''));

    if ($name === '') $errors[] = 'Setup name is required.';
    if ($exam_id <= 0) $errors[] = 'Exam is required.';
    if ($dept_id <= 0) $errors[] = 'Department is required.';
    if ($target_semester_number <= 0) $errors[] = 'Target semester number must be positive.';
    if (!isset(gs_mode_options()[$combine_mode])) $errors[] = 'Invalid combination mode.';
    if (!isset(gs_attendance_missing_policy_options()[$attendance_missing_policy])) $errors[] = 'Invalid attendance missing policy.';

    $exam = gs_get_exam($exam_id);
    if (!$exam) {
        $errors[] = 'Selected exam is invalid or inaccessible.';
    } elseif (!empty($exam['dept_id']) && (int)$exam['dept_id'] !== $dept_id) {
        $errors[] = 'Selected department does not match the selected exam.';
    }

    if (!can_access_dept($dept_id)) {
        $errors[] = 'You do not have access to the selected department.';
    }

    if ($program_id > 0) {
        $ps = $db->prepare('SELECT id FROM dept_academic_programs WHERE id = ? AND dept_id = ? AND is_active = 1');
        $ps->execute([$program_id, $dept_id]);
        if (!$ps->fetch()) $errors[] = 'Invalid program for selected department.';
    }

    if ($batch_id > 0) {
        $bs = $db->prepare('SELECT id FROM student_batches WHERE id = ? AND is_active = 1');
        $bs->execute([$batch_id]);
        if (!$bs->fetch()) $errors[] = 'Invalid batch selected.';
    }

    $posted_gpa_tiers = $_POST['gpa_tiers'] ?? [];
    $posted_att_tiers = $_POST['attendance_tiers'] ?? [];
    $gpa_tiers = gs_validate_tiers(is_array($posted_gpa_tiers) ? $posted_gpa_tiers : [], 'gpa', $errors);
    $att_tiers = gs_validate_tiers(is_array($posted_att_tiers) ? $posted_att_tiers : [], 'attendance', $errors);

    if (empty($gpa_tiers)) $errors[] = 'At least one GPA tier is required.';
    if (in_array($combine_mode, ['attendance_only', 'min', 'max'], true) && empty($att_tiers)) {
        $errors[] = 'Attendance tiers are required for the selected combination mode.';
    }

    if (empty($errors)) {
        try {
            $db->beginTransaction();
            $db->prepare('UPDATE gpa_scholarship_configs
                          SET name = ?, exam_id = ?, dept_id = ?, program_id = ?, batch_id = ?,
                              require_merit_payment_type = ?, combine_mode = ?,
                              require_gpa_tier = ?, require_attendance_tier = ?,
                              attendance_missing_policy = ?, attendance_semester = ?,
                              target_semester_number = ?, updated_by = ?, updated_at = NOW()
                          WHERE id = ?')
               ->execute([
                   $name,
                   $exam_id,
                   $dept_id,
                   $program_id ?: null,
                   $batch_id ?: null,
                   $require_merit_payment_type,
                   $combine_mode,
                   $require_gpa_tier,
                   $require_attendance_tier,
                   $attendance_missing_policy,
                   $attendance_semester !== '' ? $attendance_semester : null,
                   $target_semester_number,
                   (int)auth_user()['id'],
                   $id,
               ]);

            $db->prepare('DELETE FROM gpa_scholarship_tiers WHERE config_id = ?')->execute([$id]);
            $ins_tier = $db->prepare('INSERT INTO gpa_scholarship_tiers
                (config_id, metric, label, min_value, max_value, scholarship_percent, priority)
                VALUES (?,?,?,?,?,?,?)');

            foreach ($gpa_tiers as $tier) {
                $ins_tier->execute([$id, 'gpa', $tier['label'] ?: null, $tier['min_value'], $tier['max_value'], $tier['scholarship_percent'], $tier['priority']]);
            }
            foreach ($att_tiers as $tier) {
                $ins_tier->execute([$id, 'attendance', $tier['label'] ?: null, $tier['min_value'], $tier['max_value'], $tier['scholarship_percent'], $tier['priority']]);
            }

            $db->commit();
            flash_set('success', 'GPA Scholarship setup updated successfully.');
            redirect(APP_URL . '/gpa-scholarship/view.php?id=' . $id);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $errors[] = 'Failed to update setup: ' . $e->getMessage();
        }
    }
}

$config = gs_get_config($id);
$programs = gs_programs((int)$config['dept_id']);
$gpa_tiers = gs_get_tiers($id, 'gpa');
$att_tiers = gs_get_tiers($id, 'attendance');

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">Edit GPA Scholarship Setup</h1>
    <a href="<?= APP_URL ?>/gpa-scholarship/view.php?id=<?= (int)$id ?>" class="btn btn-outline-secondary btn-sm">Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" id="gs-form">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)$id ?>">
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label">Setup name</label><input type="text" name="name" class="form-control" value="<?= h($config['name']) ?>" required></div>
                <div class="col-md-4"><label class="form-label">Invigilation exam</label><select name="exam_id" class="form-select" required><?php foreach ($exam_options as $ex): ?><option value="<?= (int)$ex['id'] ?>" <?= (int)$config['exam_id'] === (int)$ex['id'] ? 'selected' : '' ?>><?= h($ex['exam_title']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-2"><label class="form-label">Target semester #</label><input type="number" min="1" max="20" name="target_semester_number" class="form-control" value="<?= (int)$config['target_semester_number'] ?>"></div>
                <div class="col-md-2"><label class="form-label">Attendance semester</label><input type="text" name="attendance_semester" class="form-control" value="<?= h($config['attendance_semester'] ?? '') ?>"></div>

                <div class="col-md-4"><label class="form-label">Department</label><select name="dept_id" id="gs-dept" class="form-select" required><?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>" <?= (int)$config['dept_id'] === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4"><label class="form-label">Program</label><select name="program_id" id="gs-program" class="form-select"><option value="0">All programs</option><?php foreach ($programs as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)$config['program_id'] === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['program_name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4"><label class="form-label">Batch</label><select name="batch_id" class="form-select"><option value="0">All batches</option><?php foreach ($batches as $b): ?><option value="<?= (int)$b['id'] ?>" <?= (int)$config['batch_id'] === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option><?php endforeach; ?></select></div>

                <div class="col-md-4"><label class="form-label">Combination mode</label><select name="combine_mode" class="form-select"><?php foreach (gs_mode_options() as $k => $v): ?><option value="<?= h($k) ?>" <?= $config['combine_mode'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4"><label class="form-label">Attendance missing policy</label><select name="attendance_missing_policy" class="form-select"><?php foreach (gs_attendance_missing_policy_options() as $k => $v): ?><option value="<?= h($k) ?>" <?= $config['attendance_missing_policy'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check me-3"><input class="form-check-input" type="checkbox" name="require_merit_payment_type" id="rq-merit" <?= (int)$config['require_merit_payment_type'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="rq-merit">Require merit payment type</label></div>
                    <div class="form-check me-3"><input class="form-check-input" type="checkbox" name="require_gpa_tier" id="rq-gpa" <?= (int)$config['require_gpa_tier'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="rq-gpa">Require GPA tier</label></div>
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="require_attendance_tier" id="rq-att" <?= (int)$config['require_attendance_tier'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="rq-att">Require attendance tier</label></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm"><div class="card-header d-flex justify-content-between align-items-center"><strong>GPA tiers</strong><button type="button" class="btn btn-sm btn-outline-primary" onclick="addTier('gpa')">Add tier</button></div><div class="card-body" id="gpa-tier-wrap"></div></div>
        </div>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm"><div class="card-header d-flex justify-content-between align-items-center"><strong>Attendance tiers</strong><button type="button" class="btn btn-sm btn-outline-primary" onclick="addTier('attendance')">Add tier</button></div><div class="card-body" id="attendance-tier-wrap"></div></div>
        </div>
    </div>

    <div class="mt-3"><button class="btn btn-primary">Save Changes</button></div>
</form>

<script>
const existingGpaTiers = <?= json_encode(array_values(array_map(static fn($t) => ['label' => $t['label'], 'min_value' => $t['min_value'], 'max_value' => $t['max_value'], 'scholarship_percent' => $t['scholarship_percent'], 'priority' => $t['priority']], $gpa_tiers))) ?>;
const existingAttTiers = <?= json_encode(array_values(array_map(static fn($t) => ['label' => $t['label'], 'min_value' => $t['min_value'], 'max_value' => $t['max_value'], 'scholarship_percent' => $t['scholarship_percent'], 'priority' => $t['priority']], $att_tiers))) ?>;

function tierRow(metric, data = {}) {
    const wrap = document.createElement('div');
    wrap.className = 'border rounded p-2 mb-2';
    wrap.innerHTML = `
      <div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label small">Label</label><input name="${metric}_tiers[][label]" class="form-control form-control-sm" value="${data.label || ''}"></div>
        <div class="col-md-2"><label class="form-label small">Min</label><input type="number" step="0.01" name="${metric}_tiers[][min_value]" class="form-control form-control-sm" value="${data.min_value || ''}" required></div>
        <div class="col-md-2"><label class="form-label small">Max</label><input type="number" step="0.01" name="${metric}_tiers[][max_value]" class="form-control form-control-sm" value="${data.max_value || ''}" required></div>
        <div class="col-md-3"><label class="form-label small">Scholarship %</label><input type="number" step="0.01" name="${metric}_tiers[][scholarship_percent]" class="form-control form-control-sm" value="${data.scholarship_percent || ''}" required></div>
        <div class="col-md-1"><label class="form-label small">Prio</label><input type="number" min="1" name="${metric}_tiers[][priority]" class="form-control form-control-sm" value="${data.priority || '1'}" required></div>
        <div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.border').remove()">×</button></div>
      </div>`;
    return wrap;
}

function addTier(metric, data = null) {
    document.getElementById(metric + '-tier-wrap').appendChild(tierRow(metric, data || {}));
}

(function init() {
    if (existingGpaTiers.length) existingGpaTiers.forEach(t => addTier('gpa', t)); else addTier('gpa');
    if (existingAttTiers.length) existingAttTiers.forEach(t => addTier('attendance', t));
})();

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
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
