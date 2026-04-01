<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('jobs', 'can_create');

$page_title = 'New Job Posting';
$errors     = [];
clear_old();

function jobs_slug(string $title): string {
    $slug = mb_strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    return trim($slug, '-') ?: 'untitled';
}

function unique_job_slug(string $base, int $exclude_id = 0): string {
    $slug = $base;
    $i    = 2;
    $db   = db();
    while (true) {
        $st = $db->prepare('SELECT id FROM jobs WHERE slug = ? AND id != ?');
        $st->execute([$slug, $exclude_id]);
        if (!$st->fetch()) break;
        $slug = $base . '-' . $i++;
    }
    return $slug;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $title        = trim($_POST['title']        ?? '');
    $department   = trim($_POST['department']   ?? '');
    $job_type     = $_POST['job_type']          ?? 'full-time';
    $location     = trim($_POST['location']     ?? '');
    $description  = $_POST['description']       ?? '';
    $requirements = $_POST['requirements']      ?? '';
    $salary_range = trim($_POST['salary_range'] ?? '');
    $deadline     = trim($_POST['deadline']     ?? '') ?: null;
    $is_published = isset($_POST['is_published']) ? 1 : 0;

    if ($title === '') $errors[] = 'Title is required.';
    if (!in_array($job_type, ['full-time','part-time','contract','internship'], true)) {
        $job_type = 'full-time';
    }

    if (empty($errors)) {
        $slug = unique_job_slug(jobs_slug($title));

        db()->prepare(
            'INSERT INTO jobs (title, slug, department, job_type, location, description, requirements,
                               salary_range, deadline, is_published)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $title, $slug, $department, $job_type, $location,
            $description, $requirements ?: null,
            $salary_range ?: null, $deadline, $is_published,
        ]);

        flash_set('success', 'Job posting <strong>' . h($title) . '</strong> created.');
        redirect(APP_URL . '/jobs/index.php');
    }

    save_old(compact('title','department','job_type','location','salary_range','deadline','is_published'));
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/jobs/index.php">Jobs</a></li>
            <li class="breadcrumb-item active">New Job</li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" novalidate>
    <?= csrf_field() ?>

    <div class="row g-4">
        <!-- Left column -->
        <div class="col-lg-8">

            <div class="card mb-4" style="border-radius:12px;">
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control form-control-lg"
                               value="<?= old('title') ?>" required placeholder="e.g. Senior Software Engineer" maxlength="500">
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Department</label>
                            <input type="text" name="department" class="form-control"
                                   value="<?= old('department') ?>" placeholder="e.g. Computer Science" maxlength="200">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Location</label>
                            <input type="text" name="location" class="form-control"
                                   value="<?= old('location') ?>" placeholder="e.g. Dhaka, Bangladesh" maxlength="200">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-align-left me-2 text-muted"></i>Description <span class="text-danger">*</span></h6>
                </div>
                <div class="card-body p-4">
                    <textarea id="job_description" name="description" class="form-control" rows="12"><?= h(old('description','')) ?></textarea>
                </div>
            </div>

            <div class="card" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-list-check me-2 text-muted"></i>Requirements <span class="text-muted fw-normal">(optional)</span></h6>
                </div>
                <div class="card-body p-4">
                    <textarea id="job_requirements" name="requirements" class="form-control" rows="10"><?= h(old('requirements','')) ?></textarea>
                </div>
            </div>

        </div>

        <!-- Right column -->
        <div class="col-lg-4">

            <div class="card mb-4" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-cog me-2 text-muted"></i>Settings</h6>
                </div>
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Job Type</label>
                        <select name="job_type" class="form-select">
                            <option value="full-time"  <?= old('job_type','full-time') === 'full-time'  ? 'selected' : '' ?>>Full-time</option>
                            <option value="part-time"  <?= old('job_type','full-time') === 'part-time'  ? 'selected' : '' ?>>Part-time</option>
                            <option value="contract"   <?= old('job_type','full-time') === 'contract'   ? 'selected' : '' ?>>Contract</option>
                            <option value="internship" <?= old('job_type','full-time') === 'internship' ? 'selected' : '' ?>>Internship</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Salary Range</label>
                        <input type="text" name="salary_range" class="form-control"
                               value="<?= old('salary_range') ?>" placeholder="e.g. 50,000 – 70,000 BDT" maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Application Deadline</label>
                        <input type="date" name="deadline" class="form-control"
                               value="<?= old('deadline') ?>" min="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" id="is_published" name="is_published"
                               value="1" <?= old('is_published') ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="is_published">Publish</label>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i> Save Job
                        </button>
                        <a href="<?= APP_URL ?>/jobs/index.php" class="btn btn-light" style="border-radius:10px;">
                            Cancel
                        </a>
                    </div>
                </div>
            </div>

        </div>
    </div>
</form>

<script src="https://cdn.jsdelivr.net/npm/tinymce@5.10.9/tinymce.min.js" referrerpolicy="origin"></script>
<script>
tinymce.init({
    selector: '#job_description, #job_requirements',
    height: 380,
    menubar: false,
    plugins: 'advlist autolink lists link charmap preview anchor searchreplace visualblocks code fullscreen table help wordcount',
    toolbar: 'undo redo | blocks | bold italic underline strikethrough | ' +
             'alignleft aligncenter alignright alignjustify | ' +
             'bullist numlist outdent indent | removeformat | link | code fullscreen',
    content_style: 'body { font-family: Inter, sans-serif; font-size: 15px; }',
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
