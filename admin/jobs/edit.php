<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('jobs', 'can_edit');

$id = (int)($_GET['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM jobs WHERE id = ?');
$stmt->execute([$id]);
$job = $stmt->fetch();

if (!$job) {
    flash_set('error', 'Job posting not found.');
    redirect(APP_URL . '/jobs/index.php');
}

$page_title = 'Edit Job: ' . $job['title'];
$errors     = [];

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
        // Regenerate slug only if title changed
        if (strtolower($title) !== strtolower($job['title'])) {
            $slug = unique_job_slug(jobs_slug($title), $id);
        } else {
            $slug = $job['slug'];
        }

        db()->prepare(
            'UPDATE jobs SET title=?, slug=?, department=?, job_type=?, location=?,
                             description=?, requirements=?, salary_range=?, deadline=?,
                             is_published=?, updated_at=NOW()
             WHERE id=?'
        )->execute([
            $title, $slug, $department, $job_type, $location,
            $description, $requirements ?: null,
            $salary_range ?: null, $deadline, $is_published,
            $id,
        ]);

        // Refresh job data
        $stmt = db()->prepare('SELECT * FROM jobs WHERE id = ?');
        $stmt->execute([$id]);
        $job = $stmt->fetch();

        flash_set('success', 'Job posting <strong>' . h($title) . '</strong> updated.');
        redirect(APP_URL . '/jobs/edit.php?id=' . $id);
    }
}

require_once __DIR__ . '/../includes/header.php';

// Use POST values on validation failure, otherwise use DB values
$v = fn(string $key) => $_SERVER['REQUEST_METHOD'] === 'POST' ? (trim($_POST[$key] ?? '')) : ($job[$key] ?? '');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/jobs/index.php">Jobs</a></li>
            <li class="breadcrumb-item active">Edit</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/jobs/applications.php?job_id=<?= $job['id'] ?>"
       class="btn btn-outline-secondary btn-sm" style="border-radius:10px;">
        <i class="fas fa-users me-1"></i> View Applications
    </a>
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
                               value="<?= h($v('title')) ?>" required placeholder="Job title…" maxlength="500">
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-medium text-muted" style="font-size:.8rem;">Slug</label>
                        <div class="form-control-plaintext ps-0" style="font-size:.85rem;color:#6b7280;">
                            <?= h($job['slug']) ?>
                            <small class="text-muted ms-1">(regenerated automatically if title changes)</small>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Department</label>
                            <input type="text" name="department" class="form-control"
                                   value="<?= h($v('department')) ?>" placeholder="e.g. Computer Science" maxlength="200">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Location</label>
                            <input type="text" name="location" class="form-control"
                                   value="<?= h($v('location')) ?>" placeholder="e.g. Dhaka, Bangladesh" maxlength="200">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-align-left me-2 text-muted"></i>Description <span class="text-danger">*</span></h6>
                </div>
                <div class="card-body p-4">
                    <textarea id="job_description" name="description" class="form-control" rows="12"><?= h($v('description')) ?></textarea>
                </div>
            </div>

            <div class="card" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-list-check me-2 text-muted"></i>Requirements <span class="text-muted fw-normal">(optional)</span></h6>
                </div>
                <div class="card-body p-4">
                    <textarea id="job_requirements" name="requirements" class="form-control" rows="10"><?= h($v('requirements')) ?></textarea>
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
                            <?php
                            $cur_type = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['job_type'] ?? '') : $job['job_type'];
                            foreach (['full-time'=>'Full-time','part-time'=>'Part-time','contract'=>'Contract','internship'=>'Internship'] as $val => $lbl):
                            ?>
                            <option value="<?= $val ?>" <?= $cur_type === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Salary Range</label>
                        <input type="text" name="salary_range" class="form-control"
                               value="<?= h($v('salary_range')) ?>" placeholder="e.g. 50,000 – 70,000 BDT" maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Application Deadline</label>
                        <input type="date" name="deadline" class="form-control"
                               value="<?= h($v('deadline')) ?>">
                    </div>
                    <?php
                    $pub_checked = $_SERVER['REQUEST_METHOD'] === 'POST'
                        ? isset($_POST['is_published'])
                        : (bool)$job['is_published'];
                    ?>
                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" id="is_published" name="is_published"
                               value="1" <?= $pub_checked ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="is_published">Publish</label>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i> Update Job
                        </button>
                        <a href="<?= APP_URL ?>/jobs/index.php" class="btn btn-light" style="border-radius:10px;">
                            Cancel
                        </a>
                    </div>
                </div>
            </div>

            <div class="card" style="border-radius:12px;">
                <div class="card-body p-3 px-4">
                    <div class="text-muted" style="font-size:.8rem;">
                        <div><strong>Created:</strong> <?= date('M d, Y H:i', strtotime($job['created_at'])) ?></div>
                        <div><strong>Updated:</strong> <?= date('M d, Y H:i', strtotime($job['updated_at'])) ?></div>
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
