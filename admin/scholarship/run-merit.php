<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('scholarship', 'can_create');

$page_title = 'Run Merit Auto-Apply';
$db         = db();

$merit_policies = $db->query(
    'SELECT id, name FROM sc_policies WHERE type = \'merit_based\' AND is_active = 1 ORDER BY sort_order, name'
)->fetchAll();

$result_summary = null;
$errors         = [];
$preview_rows   = [];
$confirmed      = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $results_semester = trim($_POST['results_semester'] ?? '');
    $award_semester   = trim($_POST['award_semester']   ?? '');
    $policy_id        = (int)($_POST['policy_id']       ?? 0);
    $action           = $_POST['action']                ?? 'preview';

    if ($results_semester === '') $errors[] = 'Results semester is required.';
    if ($award_semester === '')   $errors[] = 'Award semester is required.';
    if ($policy_id <= 0)          $errors[] = 'Please select a merit policy.';

    $policy = null;
    $tiers  = [];
    if (empty($errors)) {
        $policy = sc_get_policy($policy_id);
        if (!$policy || $policy['type'] !== 'merit_based') $errors[] = 'Invalid or non-merit policy selected.';
        $tiers = sc_get_tiers($policy_id);
        if (empty($tiers)) $errors[] = 'Selected policy has no tiers defined.';
    }

    if (empty($errors)) {
        $students = $db->query(
            "SELECT s.id, s.student_id, s.full_name
             FROM students s
             WHERE s.status = 'Active'"
        )->fetchAll();

        $total     = count($students);
        $awarded   = 0;
        $skipped   = 0;
        $no_result = 0;
        $no_tier   = 0;

        $user = auth_user();

        foreach ($students as $stu) {
            $res_stmt = $db->prepare(
                'SELECT cgpa FROM student_results
                 WHERE student_id = ? AND semester = ?
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $res_stmt->execute([$stu['id'], $results_semester]);
            $result_row = $res_stmt->fetch();

            if (!$result_row || $result_row['cgpa'] === null) {
                $no_result++;
                continue;
            }

            $cgpa = (float)$result_row['cgpa'];
            $tier = sc_find_tier($cgpa, $tiers);
            if (!$tier) {
                $no_tier++;
                if ($action === 'preview') {
                    $preview_rows[] = [
                        'student'  => $stu,
                        'cgpa'     => $cgpa,
                        'outcome'  => 'no_tier',
                        'tier'     => null,
                        'discount' => null,
                    ];
                }
                continue;
            }

            $dup_stmt = $db->prepare(
                'SELECT COUNT(*) FROM sc_awards WHERE student_id = ? AND policy_id = ? AND semester = ? AND status = \'active\''
            );
            $dup_stmt->execute([$stu['id'], $policy_id, $award_semester]);
            if ((int)$dup_stmt->fetchColumn() > 0) {
                $skipped++;
                if ($action === 'preview') {
                    $preview_rows[] = [
                        'student'  => $stu,
                        'cgpa'     => $cgpa,
                        'outcome'  => 'skipped',
                        'tier'     => $tier,
                        'discount' => $tier['discount_percent'],
                    ];
                }
                continue;
            }

            if ($action === 'confirm') {
                $db->prepare(
                    'INSERT INTO sc_awards (student_id, policy_id, tier_id, semester, gpa_used, discount_percent, status, awarded_by)
                     VALUES (?,?,?,?,?,?,\'active\',?)'
                )->execute([
                    $stu['id'],
                    $policy_id,
                    $tier['id'],
                    $award_semester,
                    $cgpa,
                    $tier['discount_percent'],
                    $user['id'],
                ]);
                $awarded++;
            } else {
                $awarded++;
                $preview_rows[] = [
                    'student'  => $stu,
                    'cgpa'     => $cgpa,
                    'outcome'  => 'award',
                    'tier'     => $tier,
                    'discount' => $tier['discount_percent'],
                ];
            }
        }

        $result_summary = compact('total', 'awarded', 'skipped', 'no_result', 'no_tier');
        $confirmed = ($action === 'confirm');

        if ($confirmed) {
            log_change('scholarship', 'CREATE', null,
                'Merit Auto-Apply: ' . $policy['name'],
                null, null, null,
                "Semester: $award_semester. Results from: $results_semester. Awarded: $awarded, Skipped: $skipped, No result: $no_result, No tier: $no_tier."
            );
        }
    }

    save_old($_POST);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-bolt me-2 text-warning"></i>Merit Auto-Apply</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/scholarship/index.php">Scholarships</a></li>
            <li class="breadcrumb-item active">Run Merit Auto-Apply</li>
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

<?php if ($result_summary && $confirmed): ?>
<div class="alert alert-success">
    <h5 class="mb-2"><i class="fas fa-check-circle me-1"></i>Merit Auto-Apply Complete</h5>
    <div class="row g-2">
        <div class="col-auto"><strong><?= (int)$result_summary['total'] ?></strong> students processed</div>
        <div class="col-auto text-success"><strong><?= (int)$result_summary['awarded'] ?></strong> awarded</div>
        <div class="col-auto text-warning"><strong><?= (int)$result_summary['skipped'] ?></strong> skipped (already had award)</div>
        <div class="col-auto text-muted"><strong><?= (int)$result_summary['no_result'] ?></strong> no result for semester</div>
        <div class="col-auto text-muted"><strong><?= (int)$result_summary['no_tier'] ?></strong> CGPA didn't match any tier</div>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-5">
        <form method="post" novalidate id="merit-form">
            <?= csrf_field() ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-primary text-white fw-semibold py-3">
                    <i class="fas fa-cog me-2"></i>Configuration
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Merit Policy <span class="text-danger">*</span></label>
                        <select name="policy_id" class="form-select" required>
                            <option value="">— Select Policy —</option>
                            <?php foreach ($merit_policies as $pol): ?>
                            <option value="<?= $pol['id'] ?>" <?= old('policy_id') == $pol['id'] ? 'selected' : '' ?>><?= h($pol['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($merit_policies)): ?>
                        <div class="form-text text-danger">No active merit-based policies found. <a href="<?= APP_URL ?>/scholarship/policy-create.php">Create one</a>.</div>
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Results Semester <span class="text-danger">*</span></label>
                        <input type="text" name="results_semester" class="form-control" required
                               value="<?= h(old('results_semester', '')) ?>" placeholder="e.g. Fall 2024">
                        <div class="form-text">The semester whose CGPA data will be used to determine eligibility.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Award Semester <span class="text-danger">*</span></label>
                        <input type="text" name="award_semester" class="form-control" required
                               value="<?= h(old('award_semester', '')) ?>" placeholder="e.g. Spring 2025">
                        <div class="form-text">The next semester to apply the scholarship award to.</div>
                    </div>
                </div>
            </div>
            <div class="d-grid gap-2">
                <button type="submit" name="action" value="preview" class="btn btn-outline-primary btn-lg">
                    <i class="fas fa-eye me-1"></i> Preview
                </button>
                <?php if ($result_summary && !$confirmed): ?>
                <button type="submit" name="action" value="confirm" class="btn btn-success btn-lg"
                        onclick="return confirm('Apply merit scholarships to all eligible students? Awards will be created for matching students.')">
                    <i class="fas fa-check me-1"></i> Confirm & Apply (<?= (int)$result_summary['awarded'] ?> awards)
                </button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if ($result_summary && !$confirmed && !empty($preview_rows)): ?>
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header fw-semibold py-3">
                <i class="fas fa-list me-2 text-info"></i>Preview Results
                <span class="badge bg-primary ms-2"><?= count($preview_rows) ?> rows shown</span>
            </div>
            <div class="card-body p-0" style="max-height:500px;overflow-y:auto;">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th class="px-3">Student</th>
                            <th>CGPA</th>
                            <th>Tier</th>
                            <th>Discount</th>
                            <th>Outcome</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($preview_rows as $pr): ?>
                    <tr>
                        <td class="px-3">
                            <div class="fw-semibold small"><?= h($pr['student']['full_name']) ?></div>
                            <small class="text-muted"><?= h($pr['student']['student_id']) ?></small>
                        </td>
                        <td><?= number_format($pr['cgpa'], 2) ?></td>
                        <td><?= $pr['tier'] ? h($pr['tier']['label'] ?: $pr['tier']['min_gpa'] . '–' . $pr['tier']['max_gpa']) : '—' ?></td>
                        <td><?= $pr['discount'] !== null ? number_format($pr['discount'], 2) . '%' : '—' ?></td>
                        <td>
                            <?php if ($pr['outcome'] === 'award'): ?>
                            <span class="badge bg-success">Will Award</span>
                            <?php elseif ($pr['outcome'] === 'skipped'): ?>
                            <span class="badge bg-warning text-dark">Already Awarded</span>
                            <?php else: ?>
                            <span class="badge bg-secondary">No Tier Match</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($result_summary): ?>
            <div class="card-footer bg-transparent small text-muted">
                Total: <?= (int)$result_summary['total'] ?> &middot;
                Will Award: <span class="text-success fw-semibold"><?= (int)$result_summary['awarded'] ?></span> &middot;
                Skipped: <span class="text-warning fw-semibold"><?= (int)$result_summary['skipped'] ?></span> &middot;
                No result: <?= (int)$result_summary['no_result'] ?> &middot;
                No tier: <?= (int)$result_summary['no_tier'] ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php elseif ($result_summary && !$confirmed): ?>
    <div class="col-lg-7">
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-1"></i>
            No students matched for preview. Total processed: <?= (int)$result_summary['total'] ?>,
            No result: <?= (int)$result_summary['no_result'] ?>, No tier match: <?= (int)$result_summary['no_tier'] ?>.
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
