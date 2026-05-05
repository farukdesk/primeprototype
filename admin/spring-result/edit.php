<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('spring-result', 'can_edit');
require_once __DIR__ . '/helpers.php';

$id     = (int)($_GET['id'] ?? 0);
$result = sr_get_result($id);

$page_title = 'Edit Result';
$errors     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $title        = trim($_POST['title']        ?? '');
    $semester     = trim($_POST['semester']     ?? '');
    $description  = trim($_POST['description']  ?? '');
    $is_published = isset($_POST['is_published']) ? 1 : 0;

    if ($title === '') $errors[] = 'Title is required.';

    if (empty($errors)) {
        $stmt = db()->prepare(
            'UPDATE sr_results SET title=?, semester=?, description=?, is_published=? WHERE id=?'
        );
        $stmt->execute([
            $title,
            $semester    ?: null,
            $description ?: null,
            $is_published,
            $id,
        ]);

        flash_set('success', 'Result updated successfully.');
        redirect(APP_URL . '/spring-result/view.php?id=' . $id);
    }

    $result['title']        = $title;
    $result['semester']     = $semester;
    $result['description']  = $description;
    $result['is_published'] = $is_published;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/spring-result/index.php">Spring Result</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/spring-result/view.php?id=<?= $id ?>"><?= h($result['title']) ?></a></li>
            <li class="breadcrumb-item active">Edit</li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <form method="POST" novalidate>
            <?= csrf_field() ?>
            <div class="card" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-edit me-2 text-muted"></i>Edit Result</h6>
                </div>
                <div class="card-body p-4">

                    <div class="mb-3">
                        <label class="form-label fw-medium">Result Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control"
                               value="<?= h($result['title']) ?>"
                               maxlength="300" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Semester</label>
                        <input type="text" name="semester" class="form-control"
                               value="<?= h($result['semester'] ?? '') ?>"
                               maxlength="100"
                               list="semester_list">
                        <datalist id="semester_list">
                            <?php foreach (sr_semester_list() as $s): ?>
                            <option value="<?= h($s) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Description / Notes</label>
                        <textarea name="description" class="form-control" rows="3"><?= h($result['description'] ?? '') ?></textarea>
                    </div>

                    <div class="form-check mb-4">
                        <input class="form-check-input" type="checkbox" name="is_published"
                               id="is_published" value="1"
                               <?= $result['is_published'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_published">
                            Published (visible to students on the public result page)
                        </label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i> Save Changes
                        </button>
                        <a href="<?= APP_URL ?>/spring-result/view.php?id=<?= $id ?>" class="btn btn-light" style="border-radius:10px;">Cancel</a>
                    </div>

                </div>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
