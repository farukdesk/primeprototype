<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';
if (!cc_is_staff()) { redirect(APP_URL . '/course-curriculum/index.php'); }

$id         = (int)($_GET['id']         ?? 0);
$dept_id    = (int)($_POST['dept_id']    ?? $_GET['dept_id']    ?? 0);
$program_id = (int)($_POST['program_id'] ?? $_GET['program_id'] ?? 0);

$page_title = 'Edit Intake / Batch';
$errors     = [];
clear_old();

// Fetch the existing intake row
$intake = null;
if ($id > 0) {
    $intake = cc_get_intake($id);
}
if (!$intake) {
    flash_set('danger', 'Intake not found.');
    redirect(APP_URL . '/course-curriculum/index.php');
}

// Resolve program / dept from intake if not supplied
if ($program_id <= 0) $program_id = (int)$intake['program_id'];

// Verify program exists and belongs to dept
$program_row = null;
if ($program_id > 0) {
    $query = $dept_id > 0
        ? "SELECT p.*, d.name AS dept_name
             FROM dept_academic_programs p
             JOIN dept_departments d ON d.id = p.dept_id
            WHERE p.id = ? AND p.dept_id = ? LIMIT 1"
        : "SELECT p.*, d.name AS dept_name
             FROM dept_academic_programs p
             JOIN dept_departments d ON d.id = p.dept_id
            WHERE p.id = ? LIMIT 1";
    $args = $dept_id > 0 ? [$program_id, $dept_id] : [$program_id];
    $st = db()->prepare($query);
    $st->execute($args);
    $program_row = $st->fetch() ?: null;
    if ($program_row && $dept_id <= 0) {
        $dept_id = (int)$program_row['dept_id'];
    }
}
if (!$program_row) {
    flash_set('danger', 'Program not found.');
    redirect(APP_URL . '/course-curriculum/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $batch_name    = trim($_POST['batch_name']    ?? '');
    $intake_year   = trim($_POST['intake_year']   ?? '');
    $intake_season = trim($_POST['intake_season'] ?? '');
    $notes         = trim($_POST['notes']         ?? '');

    if ($batch_name === '')           $errors[] = 'Batch / Intake Name is required.';
    if (mb_strlen($batch_name) > 150) $errors[] = 'Batch / Intake Name must be 150 characters or less.';

    $year = null;
    if ($intake_year !== '') {
        if (!ctype_digit($intake_year) || (int)$intake_year < 1990 || (int)$intake_year > 2100) {
            $errors[] = 'Intake Year must be a valid 4-digit year.';
        } else {
            $year = (int)$intake_year;
        }
    }

    $season = in_array($intake_season, cc_intake_seasons(), true) ? $intake_season : null;

    if (empty($errors)) {
        db()->prepare(
            "UPDATE course_curriculum_intakes
                SET batch_name=?, intake_year=?, intake_season=?, notes=?
              WHERE id=?"
        )->execute([$batch_name, $year, $season, $notes ?: null, $id]);

        flash_set('success', 'Intake <strong>' . h($batch_name) . '</strong> updated.');
        redirect(APP_URL . '/course-curriculum/index.php?dept_id=' . $dept_id . '&program_id=' . $program_id);
    }

    save_old(compact('batch_name', 'intake_year', 'intake_season', 'notes'));
} else {
    save_old([
        'batch_name'    => $intake['batch_name'],
        'intake_year'   => $intake['intake_year']   ?? '',
        'intake_season' => $intake['intake_season'] ?? '',
        'notes'         => $intake['notes']         ?? '',
    ]);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item">
                <a href="<?= APP_URL ?>/course-curriculum/index.php?dept_id=<?= $dept_id ?>&program_id=<?= $program_id ?>">
                    Course Curriculum
                </a>
            </li>
            <li class="breadcrumb-item active">Edit Intake</li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <ul class="mb-0 ps-3">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Context -->
<div class="alert alert-light border mb-4 small py-2 px-3">
    <i class="fas fa-building me-1 text-muted"></i><?= h($program_row['dept_name']) ?>
    &nbsp;→&nbsp;
    <strong><?= h($program_row['program_name']) ?></strong>
    &nbsp;→&nbsp;
    <i class="fas fa-layer-group me-1 text-muted"></i><?= h($intake['batch_name']) ?>
</div>

<form method="POST" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="dept_id"    value="<?= $dept_id ?>">
    <input type="hidden" name="program_id" value="<?= $program_id ?>">

    <div class="row g-4">

        <!-- ── Left column ──────────────────────────────────────────────────── -->
        <div class="col-lg-8">
            <div class="card" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-layer-group me-2 text-muted"></i>Intake / Batch Details
                    </h6>
                </div>
                <div class="card-body p-4">

                    <div class="mb-3">
                        <label class="form-label fw-medium">
                            Batch / Intake Name <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="batch_name" class="form-control"
                               value="<?= old('batch_name') ?>" maxlength="150"
                               placeholder="e.g. Spring 2024 Intake, Batch 30, Fall 2025" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Notes</label>
                        <textarea name="notes" class="form-control" rows="3"
                                  placeholder="Optional notes…"><?= old('notes') ?></textarea>
                    </div>

                </div>
            </div>
        </div>

        <!-- ── Right column ─────────────────────────────────────────────────── -->
        <div class="col-lg-4">
            <div class="card" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-calendar-alt me-2 text-muted"></i>Year &amp; Season
                    </h6>
                </div>
                <div class="card-body p-4">

                    <div class="mb-3">
                        <label class="form-label fw-medium">Intake Year</label>
                        <input type="number" name="intake_year" class="form-control"
                               value="<?= old('intake_year') ?>" min="1990" max="2100"
                               placeholder="e.g. <?= date('Y') ?>">
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-medium">Intake Season</label>
                        <select name="intake_season" class="form-select">
                            <option value="">— None —</option>
                            <?php foreach (cc_intake_seasons() as $s): ?>
                            <option value="<?= $s ?>" <?= old('intake_season') === $s ? 'selected' : '' ?>>
                                <?= h($s) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Publish status (read-only; use the list page to toggle) -->
                    <div class="mb-4">
                        <label class="form-label fw-medium">Publish Status</label>
                        <div>
                            <?php if ($intake['is_published']): ?>
                            <span class="badge" style="background-color:#198754; font-size:13px;">
                                <i class="fas fa-globe me-1"></i>Published
                            </span>
                            <?php else: ?>
                            <span class="badge bg-secondary" style="font-size:13px;">Draft</span>
                            <?php endif; ?>
                            <div class="form-text mt-1">
                                To change publish status, use the
                                <a href="<?= APP_URL ?>/course-curriculum/index.php?dept_id=<?= $dept_id ?>&program_id=<?= $program_id ?>">
                                    intake list
                                </a>.
                            </div>
                        </div>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i> Update Intake
                        </button>
                        <a href="<?= APP_URL ?>/course-curriculum/index.php?dept_id=<?= $dept_id ?>&program_id=<?= $program_id ?>"
                           class="btn btn-light" style="border-radius:10px;">Cancel</a>
                    </div>

                </div>
            </div>
        </div>

    </div>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
