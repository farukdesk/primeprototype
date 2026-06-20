<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('spring-result', 'can_create');
require_once __DIR__ . '/helpers.php';

$page_title = 'New Result';
$errors     = [];
clear_old();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $title        = trim($_POST['title']        ?? '');
    $semester     = trim($_POST['semester']     ?? '');
    $description  = trim($_POST['description']  ?? '');
    $is_published = isset($_POST['is_published']) ? 1 : 0;

    if ($title === '') $errors[] = 'Title is required.';

    if (empty($errors)) {
        $user = auth_user();
        $stmt = db()->prepare(
            'INSERT INTO sr_results (title, semester, description, is_published, created_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $title,
            $semester  ?: null,
            $description ?: null,
            $is_published,
            $user['id'] ?? null,
        ]);
        $new_id = (int)db()->lastInsertId();

        flash_set('success', 'Result <strong>' . h($title) . '</strong> created. You can now add entries.');
        redirect(APP_URL . '/spring-result/view.php?id=' . $new_id);
    }

    save_old(compact('title','semester','description','is_published'));
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/spring-result/index.php">Spring Result</a></li>
            <li class="breadcrumb-item active">New Result</li>
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
            <div class="card mb-4" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-info-circle me-2 text-muted"></i>Result Information</h6>
                </div>
                <div class="card-body p-4">

                    <div class="mb-3">
                        <label class="form-label fw-medium">Result Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control"
                               value="<?= old('title', 'Spring 2026 Result') ?>"
                               placeholder="e.g. Spring 2026 Result"
                               maxlength="300" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Semester</label>
                        <input type="text" name="semester" class="form-control"
                               value="<?= old('semester', 'Spring 2026') ?>"
                               placeholder="e.g. Spring 2026"
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
                        <textarea name="description" class="form-control" rows="3"
                                  placeholder="Optional internal notes…"><?= old('description') ?></textarea>
                    </div>

                    <div class="form-check mb-4">
                        <input class="form-check-input" type="checkbox" name="is_published"
                               id="is_published" value="1"
                               <?= old('is_published') ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_published">
                            Publish immediately (visible to students on the public result page)
                        </label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-success" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i> Create &amp; Add Entries
                        </button>
                        <a href="<?= APP_URL ?>/spring-result/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
                    </div>

                </div>
            </div>
        </form>
    </div>

    <div class="col-lg-5">
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-lightbulb me-2 text-muted"></i>How It Works</h6>
            </div>
            <div class="card-body p-4">
                <ol class="ps-3 mb-0" style="font-size:.875rem;line-height:2;">
                    <li>Create a result with a title (e.g. <em>Spring 2026 Result</em>).</li>
                    <li>Add student grade entries manually or upload a CSV file.</li>
                    <li>Each entry contains: Student ID, Name, Course Code, Course Title, Letter Grade, Grade Point.</li>
                    <li>Publish when ready — students can then search by their ID on the public result page.</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
