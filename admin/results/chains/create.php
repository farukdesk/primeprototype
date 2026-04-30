<?php
/**
 * Workflow Chain – Create
 */
require_once __DIR__ . '/../../includes/auth.php';
if (!is_super_admin() && !can_access('results-chains', 'can_create')) {
    flash_set('error', 'Access denied.'); redirect(APP_URL . '/results/chains/index.php');
}

$page_title = 'New Workflow Chain';
$errors     = [];
clear_old();

// Load departments + groups for dropdowns
$departments = db()->query(
    'SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
)->fetchAll();
$user_groups = db()->query(
    'SELECT id, name FROM user_groups WHERE is_active = 1 ORDER BY name ASC'
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name        = trim($_POST['name']        ?? '');
    $description = trim($_POST['description'] ?? '');
    $dept_id     = (int)($_POST['dept_id']    ?? 0);
    $program_id  = (int)($_POST['program_id'] ?? 0);
    $is_active   = isset($_POST['is_active']) ? 1 : 0;

    // Steps: sent as JSON from JS
    $steps_json = trim($_POST['steps_json'] ?? '[]');
    $steps      = json_decode($steps_json, true) ?: [];

    if ($name === '')               $errors[] = 'Chain name is required.';
    if (count($steps) < 2)         $errors[] = 'A chain must have at least 2 steps.';

    // Validate steps
    $has_entry = $has_final = false;
    foreach ($steps as $step) {
        if (!empty($step['is_entry'])) $has_entry = true;
        if (!empty($step['is_final'])) $has_final = true;
        if (empty($step['label']))    { $errors[] = 'Each step must have a label.'; break; }
        if (empty($step['group_id'])) { $errors[] = 'Each step must have a user group assigned.'; break; }
    }
    if (!$has_entry) $errors[] = 'One step must be marked as the Entry (submitter) step.';
    if (!$has_final) $errors[] = 'One step must be marked as the Final (publish) step.';

    if (empty($errors)) {
        $db = db();
        $db->prepare(
            'INSERT INTO wf_chains (name, description, dept_id, program_id, is_active, created_by)
             VALUES (?,?,?,?,?,?)'
        )->execute([
            $name, $description ?: null,
            $dept_id ?: null, $program_id ?: null,
            $is_active, auth_user()['id'],
        ]);
        $chain_id = (int)$db->lastInsertId();

        _save_chain_steps($chain_id, $steps, $db);

        flash_set('success', 'Workflow chain <strong>' . h($name) . '</strong> created.');
        redirect(APP_URL . '/results/chains/index.php');
    }

    save_old(compact('name','description','dept_id','program_id','is_active','steps_json'));
}

function _save_chain_steps(int $chain_id, array $steps, $db): void
{
    $db->prepare('DELETE FROM wf_chain_steps WHERE chain_id = ?')->execute([$chain_id]);
    $ins = $db->prepare(
        'INSERT INTO wf_chain_steps (chain_id, step_order, step_label, group_id, is_entry, is_final)
         VALUES (?,?,?,?,?,?)'
    );
    foreach ($steps as $i => $step) {
        $ins->execute([
            $chain_id,
            $i + 1,
            trim($step['label']),
            (int)$step['group_id'],
            !empty($step['is_entry']) ? 1 : 0,
            !empty($step['is_final']) ? 1 : 0,
        ]);
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/results/chains/index.php">Workflow Chains</a></li>
            <li class="breadcrumb-item active">New Chain</li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php
// Pass initial steps JSON back to template on validation failure
$init_steps_json = old('steps_json') ?: '[]';
$init_dept_id    = (int)(old('dept_id') ?: 0);
$init_prog_id    = (int)(old('program_id') ?: 0);
require __DIR__ . '/chain-form.php';
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
