<?php
/**
 * Workflow Chain – Edit
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/chains-helpers.php';
if (!is_super_admin() && !can_access('results-chains', 'can_edit')) {
    flash_set('error', 'Access denied.'); redirect(APP_URL . '/results/chains/index.php');
}

$id = (int)($_GET['id'] ?? 0);
$chain = db()->prepare(
    'SELECT * FROM wf_chains WHERE id = ?'
);
$chain->execute([$id]);
$chain = $chain->fetch();
if (!$chain) { flash_set('error', 'Chain not found.'); redirect(APP_URL . '/results/chains/index.php'); }

$page_title  = 'Edit Chain: ' . $chain['name'];
$errors      = [];
clear_old();

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
    $steps_json  = trim($_POST['steps_json']  ?? '[]');
    $steps       = json_decode($steps_json, true) ?: [];

    if ($name === '')        $errors[] = 'Chain name is required.';
    if (count($steps) < 2)  $errors[] = 'A chain must have at least 2 steps.';

    $has_entry = $has_final = false;
    foreach ($steps as $step) {
        if (!empty($step['is_entry'])) $has_entry = true;
        if (!empty($step['is_final'])) $has_final = true;
        if (empty($step['label']))    { $errors[] = 'Each step must have a label.'; break; }
        if (empty($step['group_id'])) { $errors[] = 'Each step must have a user group.'; break; }
    }
    if (!$has_entry) $errors[] = 'One step must be marked as Entry.';
    if (!$has_final) $errors[] = 'One step must be marked as Final.';

    if (empty($errors)) {
        db()->prepare(
            'UPDATE wf_chains SET name=?, description=?, dept_id=?, program_id=?,
             is_active=?, updated_at=NOW() WHERE id=?'
        )->execute([
            $name, $description ?: null,
            $dept_id ?: null, $program_id ?: null,
            $is_active, $id,
        ]);
        _save_chain_steps($id, $steps, db());

        flash_set('success', 'Chain updated successfully.');
        redirect(APP_URL . '/results/chains/index.php');
    }

    save_old(compact('name','description','dept_id','program_id','is_active','steps_json'));
}

// Build existing steps JSON for the JS form
$existing_steps = db()->prepare(
    'SELECT s.step_order, s.step_label, s.group_id, s.is_entry, s.is_final
     FROM wf_chain_steps s WHERE s.chain_id = ? ORDER BY s.step_order ASC'
);
$existing_steps->execute([$id]);
$existing_steps = $existing_steps->fetchAll();
$init_steps_json = old('steps_json')
    ?: json_encode(array_map(fn($s) => [
        'label'    => $s['step_label'],
        'group_id' => (string)$s['group_id'],
        'is_entry' => (bool)$s['is_entry'],
        'is_final' => (bool)$s['is_final'],
    ], $existing_steps));

$init_dept_id = (int)(old('dept_id') ?: $chain['dept_id']);
$init_prog_id = (int)(old('program_id') ?: $chain['program_id']);

// Use chain values as form defaults (the form partial reads $chain and old() values)
if (!$_POST) {
    $_POST['name']        = $chain['name'];
    $_POST['description'] = $chain['description'];
    $_POST['is_active']   = $chain['is_active'] ? '1' : '';
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/results/chains/index.php">Workflow Chains</a></li>
            <li class="breadcrumb-item active">Edit Chain</li>
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
require __DIR__ . '/chain-form.php';
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
