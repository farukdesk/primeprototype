<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('scholarship', 'can_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/scholarship/index.php');
}
csrf_check();

$id    = (int)($_POST['id'] ?? 0);
$award = sc_get_award_student($id);
if (!$award) { flash_set('error', 'Award not found.'); redirect(APP_URL . '/scholarship/index.php'); }

$label = $award['full_name'] . ' – ' . $award['policy_name'] . ' (' . $award['semester'] . ')';

db()->prepare('DELETE FROM sc_awards WHERE id = ?')->execute([$id]);

log_change('scholarship', 'DELETE', $id, $label, null, null, null, 'Award deleted.');

flash_set('success', 'Award for <strong>' . h($award['full_name']) . '</strong> deleted.');
redirect(APP_URL . '/scholarship/index.php');
