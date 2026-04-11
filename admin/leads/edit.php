<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('leads');
require_once __DIR__ . '/helpers.php';

if (!leads_is_staff()) {
    flash_set('error', 'You do not have permission to edit leads.');
    redirect(APP_URL . '/leads/index.php');
}

$id   = (int)($_GET['id'] ?? 0);
$lead = leads_get($id);

$page_title = 'Edit Lead – ' . $lead['first_name'] . ' ' . $lead['last_name'];
$user       = auth_user();
$errors     = [];
$old        = [];

// ── Departments & programs ────────────────────────────────────────────────────
$departments = db()->query(
    'SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
)->fetchAll();

$programs_by_dept = [];
$prog_stmt = db()->query(
    'SELECT id, dept_id, program_name FROM dept_academic_programs WHERE is_active = 1 ORDER BY program_name ASC'
);
foreach ($prog_stmt->fetchAll() as $p) {
    $programs_by_dept[(int)$p['dept_id']][] = $p;
}

$semesters   = leads_semester_list();
$staff_users = db()->query(
    "SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name"
)->fetchAll();

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $old = $_POST;

    $first_name   = trim($_POST['first_name']  ?? '');
    $last_name    = trim($_POST['last_name']   ?? '');
    $email        = trim($_POST['email']       ?? '');
    $phone        = trim($_POST['phone']       ?? '');
    $address      = trim($_POST['address']     ?? '');
    $current_city = trim($_POST['current_city']?? '');
    $degree_type  = in_array($_POST['degree_type'] ?? '', ['bachelor', 'master'], true)
                    ? $_POST['degree_type'] : 'bachelor';
    $dept_id      = (int)($_POST['dept_id']    ?? 0) ?: null;
    $program_id   = (int)($_POST['program_id'] ?? 0) ?: null;
    $preferred_semester = trim($_POST['preferred_semester'] ?? '');
    $new_status   = in_array($_POST['status'] ?? '', ['fresh', 'unable_to_reach', 'converted'], true)
                    ? $_POST['status'] : $lead['status'];
    $source       = in_array($_POST['source'] ?? '', ['online', 'campus_visit', 'agent', 'f2f_marketing', 'facebook'], true)
                    ? $_POST['source'] : $lead['source'];
    $assigned_to  = (int)($_POST['assigned_to'] ?? 0) ?: null;

    if ($first_name === '') $errors[] = 'First name is required.';
    if ($last_name  === '') $errors[] = 'Last name is required.';
    if ($phone      === '') $errors[] = 'Phone number is required.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';

    if (empty($errors)) {
        // Track changes for history
        $changed_fields = [];
        $track = [
            'first_name'         => [$lead['first_name'],         $first_name],
            'last_name'          => [$lead['last_name'],          $last_name],
            'email'              => [$lead['email'] ?? '',        $email],
            'phone'              => [$lead['phone'],              $phone],
            'address'            => [$lead['address'] ?? '',      $address],
            'current_city'       => [$lead['current_city'] ?? '', $current_city],
            'degree_type'        => [$lead['degree_type'],        $degree_type],
            'dept_id'            => [$lead['dept_id'],            $dept_id],
            'program_id'         => [$lead['program_id'],         $program_id],
            'preferred_semester' => [$lead['preferred_semester'] ?? '', $preferred_semester],
            'status'             => [$lead['status'],             $new_status],
            'source'             => [$lead['source'],             $source],
            'assigned_to'        => [$lead['assigned_to'],        $assigned_to],
        ];

        db()->prepare(
            'UPDATE leads SET first_name=?, last_name=?, email=?, phone=?, address=?,
             current_city=?, degree_type=?, dept_id=?, program_id=?,
             preferred_semester=?, status=?, source=?, assigned_to=?, updated_by=?
             WHERE id=?'
        )->execute([
            $first_name, $last_name, $email ?: null, $phone,
            $address ?: null, $current_city ?: null,
            $degree_type, $dept_id, $program_id,
            $preferred_semester ?: null, $new_status, $source, $assigned_to,
            $user['id'], $id,
        ]);

        // Update primary assignment
        if ($assigned_to) {
            db()->prepare('INSERT IGNORE INTO lead_assignments (lead_id, user_id, assigned_by) VALUES (?,?,?)')
                ->execute([$id, $assigned_to, $user['id']]);
        }

        foreach ($track as $field => [$old_v, $new_v]) {
            if ((string)($old_v ?? '') !== (string)($new_v ?? '')) {
                $action = $field === 'status' ? 'status_changed' : 'updated';
                leads_log($id, $action, $field, $old_v, $new_v, 'Field updated by ' . $user['full_name']);
            }
        }

        log_change('leads', 'UPDATE', $id, $lead['first_name'] . ' ' . $lead['last_name'],
            null, null, null, 'Lead updated');

        flash_set('success', 'Lead updated successfully.');
        redirect(APP_URL . '/leads/view.php?id=' . $id);
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-edit me-2 text-primary"></i>Edit Lead</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small"><li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li><li class="breadcrumb-item"><a href="<?= APP_URL ?>/leads/index.php">Leads</a></li><li class="breadcrumb-item active">Edit</li></ol></nav>
    </div>
    <a href="<?= APP_URL ?>/leads/view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back to Lead</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php
// Use current lead values if not a POST
$v = function(string $field) use ($lead, $old): string {
    return $old ? ($old[$field] ?? '') : ($lead[$field] ?? '');
};
?>

<form method="post" novalidate>
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-12 col-lg-8">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold"><i class="fas fa-user me-2 text-primary"></i>Personal Information</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label">First Name <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" class="form-control" value="<?= h($v('first_name')) ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" class="form-control" value="<?= h($v('last_name')) ?>" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= h($v('email')) ?>" autocapitalize="off" autocorrect="off" spellcheck="false">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Phone <span class="text-danger">*</span></label>
                            <input type="text" name="phone" class="form-control" value="<?= h($v('phone')) ?>" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Current City</label>
                            <input type="text" name="current_city" class="form-control" value="<?= h($v('current_city')) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address</label>
                            <textarea name="address" class="form-control" rows="2"><?= h($v('address')) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold"><i class="fas fa-graduation-cap me-2 text-success"></i>Education Information</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label class="form-label">Applying For</label>
                            <select name="degree_type" class="form-select" id="degree_type">
                                <option value="bachelor" <?= $v('degree_type') !== 'master' ? 'selected' : '' ?>>Bachelor Degree</option>
                                <option value="master"   <?= $v('degree_type') === 'master' ? 'selected' : '' ?>>Master Degree</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Department</label>
                            <select name="dept_id" class="form-select" id="dept_id_select">
                                <option value="">— Select Department —</option>
                                <?php foreach ($departments as $d): ?>
                                <option value="<?= $d['id'] ?>" <?= $v('dept_id') == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Interested Program</label>
                            <select name="program_id" class="form-select" id="program_id_select">
                                <option value="">— Select Program —</option>
                                <?php
                                $cur_dept = (int)$v('dept_id');
                                $cur_prog = (int)$v('program_id');
                                if ($cur_dept && isset($programs_by_dept[$cur_dept])) {
                                    foreach ($programs_by_dept[$cur_dept] as $p) {
                                        $sel = $cur_prog == $p['id'] ? 'selected' : '';
                                        echo '<option value="' . $p['id'] . '" ' . $sel . '>' . h($p['program_name']) . '</option>';
                                    }
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Preferred Semester</label>
                            <select name="preferred_semester" class="form-select">
                                <option value="">— Select Semester —</option>
                                <?php foreach ($semesters as $sem): ?>
                                <option value="<?= h($sem) ?>" <?= $v('preferred_semester') === $sem ? 'selected' : '' ?>><?= h($sem) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold"><i class="fas fa-tag me-2 text-warning"></i>Lead Information</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <?php foreach (['fresh' => 'Fresh', 'unable_to_reach' => 'Unable to Reach', 'converted' => 'Converted'] as $sv => $sl): ?>
                            <option value="<?= $sv ?>" <?= $v('status') === $sv ? 'selected' : '' ?>><?= $sl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Lead Source</label>
                        <select name="source" class="form-select">
                            <?php foreach (['online' => 'Online', 'campus_visit' => 'Campus Visit', 'agent' => 'Agent', 'f2f_marketing' => 'F2F Marketing', 'facebook' => 'Facebook'] as $sv => $sl): ?>
                            <option value="<?= $sv ?>" <?= $v('source') === $sv ? 'selected' : '' ?>><?= $sl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Primary Assignee</label>
                        <select name="assigned_to" class="form-select">
                            <option value="">— Unassigned —</option>
                            <?php foreach ($staff_users as $su): ?>
                            <option value="<?= $su['id'] ?>" <?= $v('assigned_to') == $su['id'] ? 'selected' : '' ?>><?= h($su['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100"><i class="fas fa-save me-1"></i> Update Lead</button>
        </div>
    </div>
</form>

<script>
const programsByDept = <?= json_encode($programs_by_dept) ?>;
const deptSelect    = document.getElementById('dept_id_select');
const programSelect = document.getElementById('program_id_select');
const currentProgId = <?= (int)($lead['program_id'] ?? 0) ?>;

function updatePrograms(preserveSelection) {
    const deptId   = parseInt(deptSelect.value) || 0;
    const programs = programsByDept[deptId] || [];
    programSelect.innerHTML = '<option value="">— Select Program —</option>';
    programs.forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.id;
        opt.textContent = p.program_name;
        if (preserveSelection && p.id == currentProgId) opt.selected = true;
        programSelect.appendChild(opt);
    });
}
updatePrograms(true);
deptSelect.addEventListener('change', () => updatePrograms(false));
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
