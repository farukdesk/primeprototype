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

$page_title = 'Edit Faculty';
$errors     = [];
clear_old();

$departments = db()->query('SELECT id, name FROM dept_departments WHERE is_active=1 ORDER BY name ASC')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $dept_id           = (int)($_POST['dept_id'] ?? 0);
    $name              = trim($_POST['name'] ?? '');
    $designation       = trim($_POST['designation'] ?? '');
    $weekend_available = isset($_POST['weekend_available']) ? 1 : 0;
    $contact_number    = trim($_POST['contact_number'] ?? '');
    $is_active         = isset($_POST['is_active']) ? 1 : 0;

    if (!$dept_id)    $errors[] = 'Department is required.';
    if ($name === '') $errors[] = 'Name is required.';

    if (empty($errors)) {
        db()->prepare(
            'UPDATE ei_faculty SET dept_id=?, name=?, designation=?, weekend_available=?, contact_number=?, is_active=? WHERE id=?'
        )->execute([$dept_id, $name, $designation ?: null, $weekend_available, $contact_number ?: null, $is_active, $fid]);
        flash_set('success', 'Faculty updated.');
        redirect(APP_URL . '/exam-invigilation/faculty.php');
    }
    save_old(compact('dept_id','name','designation','weekend_available','contact_number','is_active'));
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/exam-invigilation/index.php">Exam Invigilation</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/exam-invigilation/faculty.php">Faculty Pool</a></li>
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
        <form method="POST" novalidate>
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-medium">Department <span class="text-danger">*</span></label>
                    <select name="dept_id" class="form-select" style="border-radius:10px;" required>
                        <option value="0">— Select Department —</option>
                        <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['id'] ?>"
                            <?= (int)(old('dept_id') ?: $fac['dept_id']) == $d['id'] ? 'selected' : '' ?>>
                            <?= h($d['name']) ?>
                        </option>
                        <?php endforeach; ?>
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
                    <label class="form-label fw-medium">Contact Number</label>
                    <input type="text" name="contact_number" class="form-control" style="border-radius:10px;"
                           value="<?= old('contact_number', $fac['contact_number'] ?? '') ?>" maxlength="50">
                </div>
                <div class="col-md-3 d-flex align-items-end pb-1">
                    <div class="form-check form-switch">
                        <?php
                        $we_val = array_key_exists('weekend_available', $_SESSION['old'] ?? [])
                            ? $_SESSION['old']['weekend_available']
                            : $fac['weekend_available'];
                        ?>
                        <input class="form-check-input" type="checkbox" id="weekend_available"
                               name="weekend_available" value="1"
                               <?= $we_val ? 'checked' : '' ?>>
                        <label class="form-check-label" for="weekend_available">Weekend Available</label>
                    </div>
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
                <a href="<?= APP_URL ?>/exam-invigilation/faculty.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
