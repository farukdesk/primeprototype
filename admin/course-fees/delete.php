<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('course-fees', 'can_delete');

$id   = (int)($_GET['id'] ?? 0);
$prog = cf_get_program($id);
if (!$prog) { flash_set('error', 'Fee structure not found.'); redirect(APP_URL . '/course-fees/index.php'); }

$label = cf_program_label($prog);

db()->prepare('DELETE FROM cf_programs WHERE id = ?')->execute([$id]);

log_change('course-fees', 'DELETE', $id, $label, null, null, null, "Fee structure deleted.");

flash_set('success', "Fee structure <strong>" . h($label) . "</strong> deleted.");
redirect(APP_URL . '/course-fees/index.php');
