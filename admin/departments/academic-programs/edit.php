<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_super_admin();

$id      = (int)($_GET['id']      ?? 0);
$dept_id = (int)($_GET['dept_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM dept_academic_programs WHERE id = ?');
$stmt->execute([$id]);
$program = $stmt->fetch();
if (!$program) { flash_set('error', 'Program not found.'); redirect(APP_URL . '/departments/index.php'); }

$dept_id = $dept_id ?: (int)$program['dept_id'];
$dept    = db()->prepare('SELECT * FROM dept_departments WHERE id = ?');
$dept->execute([$dept_id]);
$dept    = $dept->fetch();
if (!$dept) { flash_set('error', 'Department not found.'); redirect(APP_URL . '/departments/index.php'); }

$page_title = 'Edit Program – ' . $program['program_name'];
$errors = [];
clear_old();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $program_name = trim($_POST['program_name'] ?? '');
    $degree_type  = trim($_POST['degree_type']  ?? '');
    $duration     = trim($_POST['duration']     ?? '');
    $total_credit = trim($_POST['total_credit'] ?? '');
    $description  = trim($_POST['description']  ?? '');
    $sort_order   = (int)($_POST['sort_order']  ?? 0);
    $is_active    = isset($_POST['is_active'])  ? 1 : 0;

    if ($program_name === '') $errors[] = 'Program name is required.';

    if (empty($errors)) {
        db()->prepare(
            'UPDATE dept_academic_programs SET
             program_name=?, degree_type=?, duration=?, total_credit=?,
             description=?, sort_order=?, is_active=?
             WHERE id=?'
        )->execute([$program_name, $degree_type ?: null, $duration ?: null,
                    $total_credit ?: null, $description ?: null, $sort_order, $is_active, $id]);

        flash_set('success', "Program <strong>" . h($program_name) . "</strong> updated.");
        redirect(APP_URL . '/departments/academic-programs/index.php?dept_id=' . $dept_id);
    }

    $program = array_merge($program, compact(
        'program_name','degree_type','duration','total_credit','description','sort_order','is_active'
    ));
}

require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/index.php">Departments</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/view.php?id=<?= $dept_id ?>"><?= h($dept['name']) ?></a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/academic-programs/index.php?dept_id=<?= $dept_id ?>">Academic Programs</a></li>
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
        <h6 class="mb-0 fw-semibold"><i class="fas fa-edit me-2 text-muted"></i>Edit Program</h6>
    </div>
    <div class="card-body p-4">
        <form method="POST" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="dept_id" value="<?= $dept_id ?>">

            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-medium">Program Name <span class="text-danger">*</span></label>
                    <input type="text" name="program_name" class="form-control" style="border-radius:10px;"
                           value="<?= h($program['program_name']) ?>" required maxlength="300">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Degree Type</label>
                    <input type="text" name="degree_type" class="form-control" style="border-radius:10px;"
                           value="<?= h($program['degree_type'] ?? '') ?>" maxlength="100">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Duration</label>
                    <input type="text" name="duration" class="form-control" style="border-radius:10px;"
                           value="<?= h($program['duration'] ?? '') ?>" maxlength="100">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Total Credit</label>
                    <input type="text" name="total_credit" class="form-control" style="border-radius:10px;"
                           value="<?= h($program['total_credit'] ?? '') ?>" maxlength="50">
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">Description</label>
                    <textarea name="description" class="form-control" style="border-radius:10px;" rows="4"><?= h($program['description'] ?? '') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" style="border-radius:10px;"
                           value="<?= (int)$program['sort_order'] ?>" min="0">
                </div>
                <div class="col-md-6 d-flex align-items-end pb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                               <?= $program['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Update Program
                </button>
                <a href="<?= APP_URL ?>/departments/academic-programs/index.php?dept_id=<?= $dept_id ?>"
                   class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
