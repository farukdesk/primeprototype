<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('scholarship', 'can_edit');

$id    = (int)($_GET['id'] ?? 0);
$award = sc_get_award_student($id);
if (!$award) { flash_set('error', 'Award not found.'); redirect(APP_URL . '/scholarship/index.php'); }

$page_title = 'Edit Award';
$errors     = [];
$db         = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $status   = $_POST['status']           ?? 'active';
    $discount = trim($_POST['discount_percent'] ?? '');
    $semester = trim($_POST['semester']    ?? '');
    $note     = trim($_POST['note']        ?? '');
    $tier_id  = (int)($_POST['tier_id']    ?? 0) ?: null;
    $gpa_used = trim($_POST['gpa_used']    ?? '');

    if (!in_array($status, ['active', 'revoked'], true)) $status = 'active';
    if ($semester === '')                                 $errors[] = 'Semester is required.';
    if (!is_numeric($discount) || (float)$discount < 0 || (float)$discount > 100) $errors[] = 'Discount must be between 0 and 100.';

    if (empty($errors)) {
        $user      = auth_user();
        $revoked_by = null;
        $revoked_at = null;

        if ($status === 'revoked' && $award['status'] !== 'revoked') {
            $revoked_by = $user['id'];
            $revoked_at = date('Y-m-d H:i:s');
        }

        $db->prepare(
            'UPDATE sc_awards
             SET status=?, discount_percent=?, semester=?, note=?, tier_id=?, gpa_used=?,
                 revoked_by=COALESCE(?, revoked_by), revoked_at=COALESCE(?, revoked_at)
             WHERE id=?'
        )->execute([
            $status,
            (float)$discount,
            $semester,
            $note ?: null,
            $tier_id,
            $gpa_used !== '' && is_numeric($gpa_used) ? (float)$gpa_used : null,
            $revoked_by,
            $revoked_at,
            $id,
        ]);

        $label = $award['full_name'] . ' – ' . $award['policy_name'] . ' (' . $semester . ')';
        log_change('scholarship', 'UPDATE', $id, $label, null, null, null, 'Award updated. Status: ' . $status . '.');

        flash_set('success', 'Award updated successfully.');
        redirect(APP_URL . '/scholarship/index.php');
    }

    save_old($_POST);
    $award = sc_get_award_student($id);
}

$tiers = sc_get_tiers((int)$award['policy_id']);

$fv = [
    'status'           => old('status',           $award['status']),
    'discount_percent' => old('discount_percent',  $award['discount_percent']),
    'semester'         => old('semester',          $award['semester']),
    'note'             => old('note',              $award['note'] ?? ''),
    'tier_id'          => old('tier_id',           (string)($award['tier_id'] ?? '')),
    'gpa_used'         => old('gpa_used',          $award['gpa_used'] ?? ''),
];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-pencil me-2 text-primary"></i>Edit Award</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/scholarship/index.php">Scholarships</a></li>
            <li class="breadcrumb-item active">Edit Award</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/scholarship/index.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i> Back
    </a>
</div>

<?= flash_show() ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<!-- Student & Policy Info -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <div class="small text-muted fw-semibold mb-1">Student</div>
                <div class="fw-bold"><?= h($award['full_name']) ?></div>
                <div class="text-muted small"><?= h($award['student_sid']) ?></div>
            </div>
            <div class="col-md-4">
                <div class="small text-muted fw-semibold mb-1">Policy</div>
                <div><?= h($award['policy_name']) ?></div>
                <div><?= sc_type_badge($award['policy_type']) ?></div>
            </div>
            <div class="col-md-4">
                <div class="small text-muted fw-semibold mb-1">Awarded</div>
                <div><?= date('d M Y H:i', strtotime($award['awarded_at'])) ?></div>
                <div class="text-muted small">by <?= h($award['awarded_by_name'] ?? 'system') ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <form method="post" novalidate>
            <?= csrf_field() ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header fw-semibold py-3"><i class="fas fa-edit me-2 text-primary"></i>Update Award</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
                            <select name="status" class="form-select" id="status-select">
                                <option value="active"  <?= $fv['status'] === 'active'  ? 'selected' : '' ?>>Active</option>
                                <option value="revoked" <?= $fv['status'] === 'revoked' ? 'selected' : '' ?>>Revoked</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Semester <span class="text-danger">*</span></label>
                            <input type="text" name="semester" class="form-control" value="<?= h($fv['semester']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Tier</label>
                            <select name="tier_id" class="form-select">
                                <option value="">— No Specific Tier —</option>
                                <?php foreach ($tiers as $tier): ?>
                                <option value="<?= $tier['id'] ?>" <?= $fv['tier_id'] == $tier['id'] ? 'selected' : '' ?>>
                                    <?= h($tier['label'] ? $tier['label'] . ': ' : '') ?><?= h($tier['min_gpa']) ?>–<?= h($tier['max_gpa']) ?> → <?= h($tier['discount_percent']) ?>%
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">GPA Used</label>
                            <input type="number" name="gpa_used" class="form-control" step="0.01" min="0"
                                   value="<?= h($fv['gpa_used']) ?>" placeholder="e.g. 9.50">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Discount % <span class="text-danger">*</span></label>
                            <input type="number" name="discount_percent" class="form-control" step="0.01" min="0" max="100" required
                                   value="<?= h($fv['discount_percent']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Note</label>
                            <textarea name="note" class="form-control" rows="3"><?= h($fv['note']) ?></textarea>
                        </div>

                        <?php if ($fv['status'] === 'revoked' && $award['revoked_at']): ?>
                        <div class="col-12">
                            <div class="alert alert-warning mb-0 py-2">
                                <i class="fas fa-ban me-1"></i>
                                Revoked on <?= date('d M Y H:i', strtotime($award['revoked_at'])) ?>
                                by <?= h($award['revoked_by_name'] ?? 'system') ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-save me-1"></i> Update Award</button>
                <a href="<?= APP_URL ?>/scholarship/index.php" class="btn btn-outline-secondary btn-lg">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
