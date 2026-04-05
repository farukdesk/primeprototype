<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('leads');
require_once __DIR__ . '/helpers.php';

if (!leads_can_create()) {
    flash_set('error', 'You do not have permission to create leads.');
    redirect(APP_URL . '/leads/index.php');
}

$page_title = 'Add Lead';
$user       = auth_user();
$errors     = [];
$old        = [];

// ── Departments & programs ────────────────────────────────────────────────────
$departments = db()->query(
    'SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
)->fetchAll();

$programs_by_dept = [];
$prog_stmt = db()->query(
    'SELECT id, dept_id, program_name, degree_level FROM dept_academic_programs WHERE is_active = 1 ORDER BY program_name ASC'
);
foreach ($prog_stmt->fetchAll() as $p) {
    $programs_by_dept[(int)$p['dept_id']][] = $p;
}

$semesters = leads_semester_list();
$staff_users = db()->query(
    "SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name"
)->fetchAll();

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $old = $_POST;

    $first_name  = trim($_POST['first_name']  ?? '');
    $last_name   = trim($_POST['last_name']   ?? '');
    $email       = trim($_POST['email']       ?? '');
    $phone       = trim($_POST['phone']       ?? '');
    $address     = trim($_POST['address']     ?? '');
    $current_city= trim($_POST['current_city']?? '');
    $degree_type = in_array($_POST['degree_type'] ?? '', ['bachelor', 'master'], true)
                   ? $_POST['degree_type'] : 'bachelor';
    $dept_id     = (int)($_POST['dept_id']    ?? 0) ?: null;
    $program_id  = (int)($_POST['program_id'] ?? 0) ?: null;
    $preferred_semester = trim($_POST['preferred_semester'] ?? '');
    $status      = in_array($_POST['status'] ?? '', ['fresh', 'unable_to_reach', 'converted'], true)
                   ? $_POST['status'] : 'fresh';
    $source      = in_array($_POST['source'] ?? '', ['online', 'campus_visit', 'agent', 'f2f_marketing'], true)
                   ? $_POST['source'] : 'online';
    $assigned_to = (int)($_POST['assigned_to'] ?? 0) ?: null;

    if ($first_name === '') $errors[] = 'First name is required.';
    if ($last_name  === '') $errors[] = 'Last name is required.';
    if ($phone      === '') $errors[] = 'Phone number is required.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';

    if (empty($errors)) {
        $lead_number = leads_generate_number();
        db()->prepare(
            'INSERT INTO leads
               (lead_number, first_name, last_name, email, phone, address, current_city,
                degree_type, dept_id, program_id, preferred_semester,
                status, source, assigned_to, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $lead_number, $first_name, $last_name, $email ?: null, $phone,
            $address ?: null, $current_city ?: null,
            $degree_type, $dept_id, $program_id,
            $preferred_semester ?: null,
            $status, $source, $assigned_to, $user['id'],
        ]);
        $lead_id = (int)db()->lastInsertId();

        // Primary assignment record
        if ($assigned_to) {
            db()->prepare('INSERT IGNORE INTO lead_assignments (lead_id, user_id, assigned_by) VALUES (?,?,?)')
                ->execute([$lead_id, $assigned_to, $user['id']]);
        }

        leads_log($lead_id, 'created', null, null, null, 'Lead created by ' . $user['full_name']);
        log_change('leads', 'CREATE', $lead_id, $first_name . ' ' . $last_name, null, null, $lead_number, 'Lead created');

        flash_set('success', 'Lead ' . $lead_number . ' created successfully.');
        redirect(APP_URL . '/leads/view.php?id=' . $lead_id);
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-plus-circle me-2 text-primary"></i>Add New Lead</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small"><li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li><li class="breadcrumb-item"><a href="<?= APP_URL ?>/leads/index.php">Leads</a></li><li class="breadcrumb-item active">Add Lead</li></ol></nav>
    </div>
    <a href="<?= APP_URL ?>/leads/index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" novalidate>
    <?= csrf_field() ?>
    <div class="row g-4">
        <!-- Personal Info -->
        <div class="col-12 col-lg-8">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold"><i class="fas fa-user me-2 text-primary"></i>Personal Information</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label">First Name <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" class="form-control" value="<?= h(old('first_name', $old)) ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" class="form-control" value="<?= h(old('last_name', $old)) ?>" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= h(old('email', $old)) ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Phone <span class="text-danger">*</span></label>
                            <input type="text" name="phone" class="form-control" value="<?= h(old('phone', $old)) ?>" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Current City</label>
                            <input type="text" name="current_city" class="form-control" value="<?= h(old('current_city', $old)) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address</label>
                            <textarea name="address" class="form-control" rows="2"><?= h(old('address', $old)) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Education Info -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold"><i class="fas fa-graduation-cap me-2 text-success"></i>Education Information</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label class="form-label">Applying For</label>
                            <select name="degree_type" class="form-select" id="degree_type">
                                <option value="bachelor" <?= old('degree_type', $old) !== 'master' ? 'selected' : '' ?>>Bachelor Degree</option>
                                <option value="master"   <?= old('degree_type', $old) === 'master' ? 'selected' : '' ?>>Master Degree</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Department</label>
                            <select name="dept_id" class="form-select" id="dept_id_select">
                                <option value="">— Select Department —</option>
                                <?php foreach ($departments as $d): ?>
                                <option value="<?= $d['id'] ?>" <?= old('dept_id', $old) == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Interested Program</label>
                            <select name="program_id" class="form-select" id="program_id_select">
                                <option value="">— Select Program —</option>
                                <?php
                                $sel_dept = (int)old('dept_id', $old);
                                if ($sel_dept && isset($programs_by_dept[$sel_dept])) {
                                    foreach ($programs_by_dept[$sel_dept] as $p) {
                                        $sel = old('program_id', $old) == $p['id'] ? 'selected' : '';
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
                                <option value="<?= h($sem) ?>" <?= old('preferred_semester', $old) === $sem ? 'selected' : '' ?>><?= h($sem) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar: Lead Info -->
        <div class="col-12 col-lg-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold"><i class="fas fa-tag me-2 text-warning"></i>Lead Information</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <?php foreach (['fresh' => 'Fresh', 'unable_to_reach' => 'Unable to Reach', 'converted' => 'Converted'] as $v => $l): ?>
                            <option value="<?= $v ?>" <?= old('status', $old) === $v ? 'selected' : '' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Lead Source</label>
                        <select name="source" class="form-select">
                            <?php foreach (['online' => 'Online', 'campus_visit' => 'Campus Visit', 'agent' => 'Agent', 'f2f_marketing' => 'F2F Marketing'] as $v => $l): ?>
                            <option value="<?= $v ?>" <?= old('source', $old) === $v ? 'selected' : '' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Assign To</label>
                        <select name="assigned_to" class="form-select">
                            <option value="">— Unassigned —</option>
                            <?php foreach ($staff_users as $su): ?>
                            <option value="<?= $su['id'] ?>" <?= old('assigned_to', $old) == $su['id'] ? 'selected' : '' ?>><?= h($su['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100"><i class="fas fa-save me-1"></i> Save Lead</button>
        </div>
    </div>
</form>

<script>
// Programs data by dept
const programsByDept = <?= json_encode($programs_by_dept) ?>;
const deptSelect    = document.getElementById('dept_id_select');
const programSelect = document.getElementById('program_id_select');

function updatePrograms() {
    const deptId = parseInt(deptSelect.value) || 0;
    const programs = programsByDept[deptId] || [];
    programSelect.innerHTML = '<option value="">— Select Program —</option>';
    programs.forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.id;
        opt.textContent = p.program_name;
        programSelect.appendChild(opt);
    });
}
deptSelect.addEventListener('change', updatePrograms);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
