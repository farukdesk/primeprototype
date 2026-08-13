<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('exam-invigilation', 'can_edit');

$fid    = (int)($_GET['id'] ?? 0);
$fac_st = db()->prepare('SELECT * FROM ei_faculty WHERE id = ?');
$fac_st->execute([$fid]);
$fac = $fac_st->fetch();
if (!$fac) {
    flash_set('error', 'Faculty not found.');
    redirect(APP_URL . '/exam-invigilation/faculty.php');
}

// Build return URL preserving page and filters from the list view
$_return_params = array_filter([
    'page'        => (int)($_GET['page'] ?? 1) > 1 ? (int)$_GET['page'] : null,
    'dept'        => (int)($_GET['dept'] ?? 0) ?: null,
    'q'           => trim($_GET['q'] ?? '') ?: null,
    'active'      => isset($_GET['active']) && $_GET['active'] !== '' ? $_GET['active'] : null,
    'designation' => trim($_GET['designation'] ?? '') ?: null,
], static fn($v) => $v !== null);
$_return_url = APP_URL . '/exam-invigilation/faculty.php' . ($_return_params ? '?' . http_build_query($_return_params) : '');

$page_title = 'Edit Faculty';
$errors     = [];
clear_old();

try {
    $departments = db()->query(
        "SELECT id, name, dept_type FROM dept_departments
         WHERE (is_active = 1 AND dept_type = 'academic') OR dept_type = 'office'
         ORDER BY dept_type ASC, name ASC"
    )->fetchAll();
} catch (Throwable $e) {
    // dept_type column missing (run ei-office-departments-v1.sql); academic only
    $departments = db()->query('SELECT id, name FROM dept_departments WHERE is_active=1 ORDER BY name ASC')->fetchAll();
}
$weekday_labels = [
    0 => 'Sunday',
    1 => 'Monday',
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday',
    6 => 'Saturday',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $dept_id           = (int)($_POST['dept_id'] ?? 0);
    $name              = trim($_POST['name'] ?? '');
    $designation       = trim($_POST['designation'] ?? '');
    $weekend_days_raw = $_POST['weekend_days'] ?? [];
    if (!is_array($weekend_days_raw)) $weekend_days_raw = [];
    $weekend_days_arr = array_values(array_unique(array_map('intval', $weekend_days_raw)));
    $weekend_days_arr = array_values(array_filter($weekend_days_arr, static fn ($d) => $d >= 0 && $d <= 6));
    sort($weekend_days_arr);
    $weekend_days = implode(',', $weekend_days_arr);
    $weekend_available = (in_array(0, $weekend_days_arr, true) || in_array(6, $weekend_days_arr, true)) ? 0 : 1;
    $gender                = in_array($_POST['gender'] ?? '', ['Male','Female'], true) ? $_POST['gender'] : null;
    $contact_number        = trim($_POST['contact_number'] ?? '');
    $remuneration_per_slot = max(0, (float)($_POST['remuneration_per_slot'] ?? 0));
    $pay_by_unique_slot    = isset($_POST['pay_by_unique_slot']) ? 1 : 0;
    $is_active             = isset($_POST['is_active']) ? 1 : 0;

    // Handle signature upload
    $signature = $fac['signature'] ?? null;
    if (!empty($_FILES['signature']['name']) && $_FILES['signature']['error'] === UPLOAD_ERR_OK) {
        $allowed_exts  = ['jpg','jpeg','png','gif','webp'];
        $allowed_mimes = ['image/jpeg','image/png','image/gif','image/webp'];
        $ext  = strtolower(pathinfo($_FILES['signature']['name'], PATHINFO_EXTENSION));
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['signature']['tmp_name']);
        if (!in_array($ext, $allowed_exts, true) || !in_array($mime, $allowed_mimes, true)) {
            $errors[] = 'Signature must be a JPG, PNG, GIF, or WebP image.';
        } else {
            $sig_dir = UPLOAD_DIR . '/exam-invigilation/signatures';
            $sig_name = bin2hex(random_bytes(12)) . '.' . $ext;
            if (!is_dir($sig_dir) && !mkdir($sig_dir, 0755, true)) {
                $errors[] = 'Failed to create signature upload directory.';
            } elseif (!move_uploaded_file($_FILES['signature']['tmp_name'], $sig_dir . '/' . $sig_name)) {
                $errors[] = 'Failed to upload signature image.';
            } else {
                // Remove old signature if exists
                if ($signature) {
                    $old_path = UPLOAD_DIR . '/exam-invigilation/signatures/' . $signature;
                    if (is_file($old_path)) @unlink($old_path);
                }
                $signature = $sig_name;
            }
        }
    } elseif (isset($_POST['remove_signature']) && $_POST['remove_signature'] === '1') {
        if ($signature) {
            $old_path = UPLOAD_DIR . '/exam-invigilation/signatures/' . $signature;
            if (is_file($old_path)) @unlink($old_path);
        }
        $signature = null;
    }

    if (!$dept_id)    $errors[] = 'Department is required.';
    if ($name === '') $errors[] = 'Name is required.';

    if (empty($errors)) {
        db()->prepare(
            'UPDATE ei_faculty SET dept_id=?, name=?, designation=?, gender=?, weekend_available=?, weekend_days=?, contact_number=?, remuneration_per_slot=?, pay_by_unique_slot=?, signature=?, is_active=? WHERE id=?'
        )->execute([$dept_id, $name, $designation ?: null, $gender, $weekend_available, $weekend_days, $contact_number ?: null, $remuneration_per_slot, $pay_by_unique_slot, $signature, $is_active, $fid]);
        flash_set('success', 'Faculty updated.');
        redirect($_return_url);
    }
    save_old(compact('dept_id','name','designation','gender','weekend_days_raw','contact_number','remuneration_per_slot','pay_by_unique_slot','is_active'));
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/exam-invigilation/index.php">Exam Invigilation</a></li>
            <li class="breadcrumb-item"><a href="<?= h($_return_url) ?>">Faculty Pool</a></li>
            <li class="breadcrumb-item active">Edit</li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="row justify-content-center">
<div class="col-lg-7">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-edit me-2 text-muted"></i>Edit Faculty</h6>
    </div>
    <div class="card-body p-4">
        <form method="POST" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-medium">Department <span class="text-danger">*</span></label>
                    <?php
                    $dept_opts = ['academic' => [], 'office' => []];
                    foreach ($departments as $d) {
                        $dept_opts[(($d['dept_type'] ?? 'academic') === 'office') ? 'office' : 'academic'][] = $d;
                    }
                    ?>
                    <select name="dept_id" class="form-select" style="border-radius:10px;" required>
                        <option value="0">— Select Department —</option>
                        <optgroup label="Academic Departments">
                            <?php foreach ($dept_opts['academic'] as $d): ?>
                            <option value="<?= $d['id'] ?>"
                                <?= (int)(old('dept_id') ?: $fac['dept_id']) == $d['id'] ? 'selected' : '' ?>>
                                <?= h($d['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php if ($dept_opts['office']): ?>
                        <optgroup label="University Offices">
                            <?php foreach ($dept_opts['office'] as $d): ?>
                            <option value="<?= $d['id'] ?>"
                                <?= (int)(old('dept_id') ?: $fac['dept_id']) == $d['id'] ? 'selected' : '' ?>>
                                <?= h($d['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" style="border-radius:10px;"
                           value="<?= old('name', $fac['name']) ?>" required maxlength="200">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Designation</label>
                    <input type="text" name="designation" class="form-control" style="border-radius:10px;"
                           value="<?= old('designation', $fac['designation'] ?? '') ?>" maxlength="200">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Gender</label>
                    <select name="gender" class="form-select" style="border-radius:10px;">
                        <option value="">— Select Gender —</option>
                        <?php $cur_gender = old('gender', $fac['gender'] ?? ''); ?>
                        <option value="Male" <?= $cur_gender === 'Male' ? 'selected' : '' ?>>Male</option>
                        <option value="Female" <?= $cur_gender === 'Female' ? 'selected' : '' ?>>Female</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Contact Number</label>
                    <input type="text" name="contact_number" class="form-control" style="border-radius:10px;"
                           value="<?= old('contact_number', $fac['contact_number'] ?? '') ?>" maxlength="50">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Exam Remuneration <small class="text-muted fw-normal">(per slot, BDT)</small></label>
                    <div class="input-group">
                        <span class="input-group-text" style="border-radius:10px 0 0 10px;">৳</span>
                        <input type="number" name="remuneration_per_slot" class="form-control" style="border-radius:0 10px 10px 0;"
                               value="<?= number_format((float)old('remuneration_per_slot', $fac['remuneration_per_slot'] ?? 0), 2, '.', '') ?>"
                               min="0" step="0.01" placeholder="0.00">
                    </div>
                    <small class="text-muted">Amount paid to this faculty per attended invigilation slot.</small>
                </div>
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="pay_by_unique_slot"
                               name="pay_by_unique_slot" value="1"
                               <?= old('pay_by_unique_slot', $fac['pay_by_unique_slot'] ?? 0) ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="pay_by_unique_slot">Paid by Unique Slot <small class="text-muted fw-normal">(per exam sitting)</small></label>
                    </div>
                    <small class="text-muted">
                        Pays rate &times; attended <strong>sittings</strong> (date + time slot) instead of per room duty.
                        Office employees (Treasurer, Controller of Examinations, Accounts &amp; Audit) count <strong>all</strong>
                        sittings of the exam; department employees count only their <strong>own department's</strong> sittings.
                        Mark their per-sitting attendance on the exam's Attendance page — absent sittings are not paid.
                    </small>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Signature Image</label>
                    <?php $cur_sig = $fac['signature'] ?? null; ?>
                    <?php if ($cur_sig): ?>
                    <div class="mb-2">
                        <img src="<?= UPLOAD_URL ?>/exam-invigilation/signatures/<?= h($cur_sig) ?>"
                             alt="Signature" style="max-height:50px;border:1px solid #dee2e6;border-radius:6px;padding:2px;">
                        <div class="form-check mt-1">
                            <input class="form-check-input" type="checkbox" id="remove_signature" name="remove_signature" value="1">
                            <label class="form-check-label text-danger" for="remove_signature" style="font-size:.8rem;">Remove signature</label>
                        </div>
                    </div>
                    <?php endif; ?>
                    <input type="file" name="signature" class="form-control" style="border-radius:10px;"
                           accept="image/jpeg,image/png,image/gif,image/webp">
                    <small class="text-muted">JPG, PNG, GIF or WebP. Leave blank to keep current.</small>
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium mb-2">Weekly Weekend Day(s)</label>
                    <?php
                    if (array_key_exists('weekend_days_raw', $_SESSION['old'] ?? [])) {
                        $selected_weekend_days = array_map('intval', (array)$_SESSION['old']['weekend_days_raw']);
                    } elseif (!empty($fac['weekend_days'])) {
                        $selected_weekend_days = array_values(array_filter(array_map('intval', explode(',', (string)$fac['weekend_days'])), static fn ($d) => $d >= 0 && $d <= 6));
                    } else {
                        $selected_weekend_days = ((int)$fac['weekend_available'] === 1) ? [] : [0, 6];
                    }
                    ?>
                    <div class="d-flex flex-wrap gap-3">
                        <?php foreach ($weekday_labels as $day_no => $day_name): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="weekend_day_<?= $day_no ?>"
                                   name="weekend_days[]" value="<?= $day_no ?>"
                                   <?= in_array($day_no, $selected_weekend_days, true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="weekend_day_<?= $day_no ?>"><?= h($day_name) ?></label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <small class="text-muted">Selected days are treated as this faculty member's weekly weekend/off days.</small>
                </div>
                <div class="col-md-3 d-flex align-items-end pb-1">
                    <div class="form-check form-switch">
                        <?php
                        $act_val = array_key_exists('is_active', $_SESSION['old'] ?? [])
                            ? $_SESSION['old']['is_active']
                            : $fac['is_active'];
                        ?>
                        <input class="form-check-input" type="checkbox" id="is_active"
                               name="is_active" value="1"
                               <?= $act_val ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Update Faculty
                </button>
                <a href="<?= h($_return_url) ?>" class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
