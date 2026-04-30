<?php
require_once __DIR__ . '/../../includes/auth.php';
if (!is_super_admin() && !can_access('results-chains', 'can_delete')) {
    flash_set('error', 'Access denied.'); redirect(APP_URL . '/results/chains/index.php');
}
csrf_check();

$id = (int)($_POST['id'] ?? 0);

// Check for active sheets using this chain
$count = (int)db()->prepare(
    'SELECT COUNT(*) FROM result_mark_sheets WHERE chain_id = ?'
)->execute([$id]) ? db()->prepare(
    'SELECT COUNT(*) FROM result_mark_sheets WHERE chain_id = ?'
) : null;

$stmt = db()->prepare('SELECT COUNT(*) FROM result_mark_sheets WHERE chain_id = ?');
$stmt->execute([$id]);
if ((int)$stmt->fetchColumn() > 0) {
    flash_set('error', 'Cannot delete a chain that has mark sheets attached to it.');
    redirect(APP_URL . '/results/chains/index.php');
}

db()->prepare('DELETE FROM wf_chains WHERE id = ?')->execute([$id]);
flash_set('success', 'Chain deleted.');
redirect(APP_URL . '/results/chains/index.php');
