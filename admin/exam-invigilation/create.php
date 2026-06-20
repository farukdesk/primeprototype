<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('exam-invigilation', 'can_create');

$page_title = 'New Exam';
$errors     = [];
clear_old();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $exam_name  = trim($_POST['exam_name'] ?? '');
    $exam_year  = (int)($_POST['exam_year'] ?? 0);
    $start_date = trim($_POST['start_date'] ?? '');
    $end_date   = trim($_POST['end_date'] ?? '');
    $is_active  = isset($_POST['is_active']) ? 1 : 0;

    if ($exam_name === '') $errors[] = 'Exam name is required.';
    if ($exam_year < 2000 || $exam_year > 2100) $errors[] = 'Please enter a valid year (2000–2100).';
    if ($start_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) $errors[] = 'Invalid start date.';
    if ($end_date   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date))   $errors[] = 'Invalid end date.';
    if ($start_date !== '' && $end_date !== '' && $end_date < $start_date) $errors[] = 'End date must be on or after start date.';

    if (empty($errors)) {
        db()->prepare('INSERT INTO ei_exams (exam_name, exam_year, start_date, end_date, is_active) VALUES (?,?,?,?,?)')
           ->execute([$exam_name, $exam_year, $start_date ?: null, $end_date ?: null, $is_active]);
        $new_id = (int)db()->lastInsertId();
        flash_set('success', 'Exam <strong>' . h($exam_name) . '</strong> created.');
        redirect(APP_URL . '/exam-invigilation/view.php?id=' . $new_id);
    }
    save_old(compact('exam_name','exam_year','start_date','end_date','is_active'));
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/exam-invigilation/index.php">Exam Invigilation</a></li>
            <li class="breadcrumb-item active">New Exam</li>
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
        <h6 class="mb-0 fw-semibold"><i class="fas fa-plus me-2 text-muted"></i>New Exam</h6>
    </div>
    <div class="card-body p-4">
        <form method="POST" novalidate>
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-medium">Exam Name <span class="text-danger">*</span></label>
                    <input type="text" name="exam_name" class="form-control" style="border-radius:10px;"
                           value="<?= old('exam_name') ?>" required maxlength="200"
                           placeholder="e.g. Final Examination – Spring">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Year <span class="text-danger">*</span></label>
                    <input type="number" name="exam_year" class="form-control" style="border-radius:10px;"
                           value="<?= old('exam_year', date('Y')) ?>" required min="2000" max="2100"
                           placeholder="<?= date('Y') ?>">
                </div>
                <div class="col-md-6 d-flex align-items-end pb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                               <?= (old('is_active','1')) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Start Date</label>
                    <input type="date" name="start_date" class="form-control" style="border-radius:10px;"
                           value="<?= old('start_date') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">End Date</label>
                    <input type="date" name="end_date" class="form-control" style="border-radius:10px;"
                           value="<?= old('end_date') ?>">
                </div>
            </div>
            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Save Exam
                </button>
                <a href="<?= APP_URL ?>/exam-invigilation/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
