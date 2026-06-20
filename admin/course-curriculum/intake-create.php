<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';
if (!cc_is_staff()) { redirect(APP_URL . '/course-curriculum/index.php'); }

$dept_id    = (int)($_POST['dept_id']    ?? $_GET['dept_id']    ?? 0);
$program_id = (int)($_POST['program_id'] ?? $_GET['program_id'] ?? 0);

$page_title = 'New Intake / Batch';
$errors     = [];
clear_old();

// Verify program exists and belongs to dept
$program_row = null;
if ($program_id > 0 && $dept_id > 0) {
    $st = db()->prepare(
        "SELECT p.*, d.name AS dept_name
           FROM dept_academic_programs p
           JOIN dept_departments d ON d.id = p.dept_id
          WHERE p.id = ? AND p.dept_id = ?
          LIMIT 1"
    );
    $st->execute([$program_id, $dept_id]);
    $program_row = $st->fetch() ?: null;
}
if (!$program_row) {
    flash_set('danger', 'Invalid department or program selection.');
    redirect(APP_URL . '/course-curriculum/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $batch_name     = trim($_POST['batch_name']     ?? '');
    $intake_year    = trim($_POST['intake_year']    ?? '');
    $intake_season  = trim($_POST['intake_season']  ?? '');
    $notes          = trim($_POST['notes']          ?? '');

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
            "INSERT INTO course_curriculum_intakes
               (program_id, batch_name, intake_year, intake_season, notes)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$program_id, $batch_name, $year, $season, $notes ?: null]);

        flash_set('success', 'Intake <strong>' . h($batch_name) . '</strong> created.');
        redirect(APP_URL . '/course-curriculum/index.php?dept_id=' . $dept_id . '&program_id=' . $program_id);
    }

    save_old(compact('batch_name', 'intake_year', 'intake_season', 'notes'));
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
            <li class="breadcrumb-item active">New Intake / Batch</li>
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
                        <div class="form-text">A descriptive label for this intake group.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Notes</label>
                        <textarea name="notes" class="form-control" rows="3"
                                  placeholder="Optional notes about this intake…"><?= old('notes') ?></textarea>
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

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i> Create Intake
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
