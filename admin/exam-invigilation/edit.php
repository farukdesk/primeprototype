<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('exam-invigilation', 'can_edit');

$id = (int)($_GET['id'] ?? 0);
$exam = db()->prepare('SELECT * FROM ei_exams WHERE id = ?');
$exam->execute([$id]);
$exam = $exam->fetch();
if (!$exam) {
    flash_set('error', 'Exam not found.');
    redirect(APP_URL . '/exam-invigilation/index.php');
}

$page_title = 'Edit Exam';
$errors     = [];
clear_old();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $exam_name = trim($_POST['exam_name'] ?? '');
    $exam_year = (int)($_POST['exam_year'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($exam_name === '') $errors[] = 'Exam name is required.';
    if ($exam_year < 2000 || $exam_year > 2100) $errors[] = 'Please enter a valid year (2000–2100).';

    if (empty($errors)) {
        db()->prepare('UPDATE ei_exams SET exam_name=?, exam_year=?, is_active=? WHERE id=?')
           ->execute([$exam_name, $exam_year, $is_active, $id]);
        flash_set('success', 'Exam updated.');
        redirect(APP_URL . '/exam-invigilation/view.php?id=' . $id);
    }
    save_old(compact('exam_name','exam_year','is_active'));
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/exam-invigilation/index.php">Exam Invigilation</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/exam-invigilation/view.php?id=<?= $id ?>"><?= h($exam['exam_name']) ?></a></li>
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
<div class="col-lg-6">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-edit me-2 text-muted"></i>Edit Exam</h6>
    </div>
    <div class="card-body p-4">
        <form method="POST" novalidate>
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-medium">Exam Name <span class="text-danger">*</span></label>
                    <input type="text" name="exam_name" class="form-control" style="border-radius:10px;"
                           value="<?= old('exam_name', $exam['exam_name']) ?>" required maxlength="200">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Year <span class="text-danger">*</span></label>
                    <input type="number" name="exam_year" class="form-control" style="border-radius:10px;"
                           value="<?= old('exam_year', $exam['exam_year']) ?>" required min="2000" max="2100">
                </div>
                <div class="col-md-6 d-flex align-items-end pb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                               <?= (old('is_active', (string)$exam['is_active']) ? 'checked' : '') ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Update Exam
                </button>
                <a href="<?= APP_URL ?>/exam-invigilation/view.php?id=<?= $id ?>" class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
