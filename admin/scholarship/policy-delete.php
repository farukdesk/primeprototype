<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('scholarship-policies', 'can_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/scholarship/policies.php');
}
csrf_check();

$id     = (int)($_POST['id'] ?? 0);
$policy = sc_get_policy($id);
if (!$policy) { flash_set('error', 'Policy not found.'); redirect(APP_URL . '/scholarship/policies.php'); }

$cnt_stmt = db()->prepare("SELECT COUNT(*) FROM sc_awards WHERE policy_id = ? AND status = 'active'");
$cnt_stmt->execute([$id]);
$active_count = (int)$cnt_stmt->fetchColumn();

if ($active_count > 0) {
    flash_set('error', 'Cannot delete policy <strong>' . h($policy['name']) . '</strong>: it has ' . $active_count . ' active award(s). Revoke all awards first.');
    redirect(APP_URL . '/scholarship/policies.php');
}

$name = $policy['name'];
db()->prepare('DELETE FROM sc_policies WHERE id = ?')->execute([$id]);

log_change('scholarship-policies', 'DELETE', $id, $name, null, null, null, 'Policy deleted.');

flash_set('success', 'Policy <strong>' . h($name) . '</strong> deleted.');
redirect(APP_URL . '/scholarship/policies.php');
