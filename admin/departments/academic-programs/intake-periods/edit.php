<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_super_admin();

$id         = (int)($_GET['id']         ?? 0);
$program_id = (int)($_GET['program_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM program_intake_periods WHERE id = ?');
$stmt->execute([$id]);
$item = $stmt->fetch();
if (!$item) { flash_set('error', 'Intake period not found.'); redirect(APP_URL . '/departments/index.php'); }

$program_id = $program_id ?: (int)$item['program_id'];

$program = db()->prepare('SELECT p.*, d.id AS dept_id, d.name AS dept_name FROM dept_academic_programs p JOIN dept_departments d ON d.id = p.dept_id WHERE p.id = ?');
$program->execute([$program_id]);
$program = $program->fetch();
if (!$program) { flash_set('error', 'Program not found.'); redirect(APP_URL . '/departments/index.php'); }

$dept_id = (int)$program['dept_id'];
$page_title = 'Edit Intake Period';
$errors = [];
clear_old();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $intake_name   = trim($_POST['intake_name']   ?? '');
    $open_date     = trim($_POST['open_date']     ?? '');
    $close_date    = trim($_POST['close_date']    ?? '');
    $intake_status = trim($_POST['intake_status'] ?? 'upcoming');
    $notes         = trim($_POST['notes']         ?? '');
    $sort_order    = (int)($_POST['sort_order']   ?? 0);
    $is_active     = isset($_POST['is_active'])   ? 1 : 0;

    $allowed_statuses = ['open', 'upcoming', 'closed'];
    if (!in_array($intake_status, $allowed_statuses, true)) $intake_status = 'upcoming';

    if ($intake_name === '') $errors[] = 'Intake name is required.';

    if (empty($errors)) {
        db()->prepare(
            'UPDATE program_intake_periods SET intake_name=?, open_date=?, close_date=?, intake_status=?, notes=?, sort_order=?, is_active=? WHERE id=?'
        )->execute([
            $intake_name,
            $open_date ?: null,
            $close_date ?: null,
            $intake_status,
            $notes ?: null,
            $sort_order,
            $is_active,
            $id,
        ]);

        flash_set('success', "Intake period updated.");
        redirect(APP_URL . '/departments/academic-programs/intake-periods/index.php?program_id=' . $program_id);
    }

    $item = array_merge($item, compact('intake_name','open_date','close_date','intake_status','notes','sort_order','is_active'));
}

require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/index.php">Departments</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/view.php?id=<?= $dept_id ?>"><?= h($program['dept_name']) ?></a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/academic-programs/index.php?dept_id=<?= $dept_id ?>">Academic Programs</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/academic-programs/intake-periods/index.php?program_id=<?= $program_id ?>">Intake Periods</a></li>
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
<div class="col-lg-8">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-edit me-2 text-muted"></i>Edit Intake Period</h6>
    </div>
    <div class="card-body p-4">
        <form method="POST" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="program_id" value="<?= $program_id ?>">

            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-medium">Intake Name <span class="text-danger">*</span></label>
                    <input type="text" name="intake_name" class="form-control" style="border-radius:10px;"
                           value="<?= h($item['intake_name']) ?>" required maxlength="200">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Open Date</label>
                    <input type="date" name="open_date" class="form-control" style="border-radius:10px;"
                           value="<?= h($item['open_date'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Close Date</label>
                    <input type="date" name="close_date" class="form-control" style="border-radius:10px;"
                           value="<?= h($item['close_date'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Status</label>
                    <select name="intake_status" class="form-select" style="border-radius:10px;">
                        <?php foreach (['upcoming' => 'Upcoming', 'open' => 'Open', 'closed' => 'Closed'] as $val => $label): ?>
                        <option value="<?= $val ?>" <?= $item['intake_status'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" style="border-radius:10px;"
                           value="<?= (int)$item['sort_order'] ?>" min="0">
                </div>
                <div class="col-md-4 d-flex align-items-end pb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                               <?= $item['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">Notes</label>
                    <textarea name="notes" class="form-control" style="border-radius:10px;" rows="3"><?= h($item['notes'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Update
                </button>
                <a href="<?= APP_URL ?>/departments/academic-programs/intake-periods/index.php?program_id=<?= $program_id ?>"
                   class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
