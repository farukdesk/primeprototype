<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_super_admin();

$program_id = (int)($_GET['program_id'] ?? $_POST['program_id'] ?? 0);
if (!$program_id) { flash_set('error', 'Invalid program.'); redirect(APP_URL . '/departments/index.php'); }

$program = db()->prepare('SELECT p.*, d.id AS dept_id, d.name AS dept_name FROM dept_academic_programs p JOIN dept_departments d ON d.id = p.dept_id WHERE p.id = ?');
$program->execute([$program_id]);
$program = $program->fetch();
if (!$program) { flash_set('error', 'Program not found.'); redirect(APP_URL . '/departments/index.php'); }

$dept_id = (int)$program['dept_id'];
$page_title = 'Add Eligibility Criterion – ' . $program['program_name'];
$errors = [];
clear_old();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $category   = trim($_POST['category']   ?? 'General');
    $criterion  = trim($_POST['criterion']  ?? '');
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $is_active  = isset($_POST['is_active']) ? 1 : 0;

    if ($category === '') $category = 'General';
    if ($criterion === '') $errors[] = 'Criterion text is required.';

    if (empty($errors)) {
        db()->prepare(
            'INSERT INTO program_eligibility_criteria (program_id, category, criterion, sort_order, is_active)
             VALUES (?,?,?,?,?)'
        )->execute([$program_id, $category, $criterion, $sort_order, $is_active]);

        flash_set('success', "Eligibility criterion added.");
        redirect(APP_URL . '/departments/academic-programs/eligibility/index.php?program_id=' . $program_id);
    }

    save_old(compact('category','criterion','sort_order'));
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
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/academic-programs/eligibility/index.php?program_id=<?= $program_id ?>">Eligibility Criteria</a></li>
            <li class="breadcrumb-item active">Add</li>
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
        <h6 class="mb-0 fw-semibold"><i class="fas fa-check-circle me-2 text-muted"></i>Add Eligibility Criterion – <span class="text-primary"><?= h($program['program_name']) ?></span></h6>
    </div>
    <div class="card-body p-4">
        <form method="POST" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="program_id" value="<?= $program_id ?>">

            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label fw-medium">Category</label>
                    <input type="text" name="category" class="form-control" style="border-radius:10px;"
                           value="<?= h(old('category', 'General')) ?>"
                           placeholder="e.g. Academic, English Proficiency" maxlength="150" list="category-suggestions">
                    <datalist id="category-suggestions">
                        <option value="Academic">
                        <option value="English Proficiency">
                        <option value="Age Limit">
                        <option value="Work Experience">
                        <option value="Documents Required">
                        <option value="General">
                    </datalist>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" style="border-radius:10px;"
                           value="<?= (int)old('sort_order', '0') ?>" min="0">
                </div>
                <div class="col-md-3 d-flex align-items-end pb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" checked>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">Criterion <span class="text-danger">*</span></label>
                    <textarea name="criterion" class="form-control" style="border-radius:10px;" rows="4"
                              placeholder="e.g. Minimum GPA of 2.50 in SSC and HSC or equivalent examinations."
                              required><?= h(old('criterion')) ?></textarea>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Save Criterion
                </button>
                <a href="<?= APP_URL ?>/departments/academic-programs/eligibility/index.php?program_id=<?= $program_id ?>"
                   class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
