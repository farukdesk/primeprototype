<?php
/**
 * Admit Card – Delete
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('admit-card', 'can_delete');
require_once __DIR__ . '/helpers.php';

$id   = (int)($_GET['id'] ?? 0);
$card = ac_get_card($id);
if (!$card) {
    flash_set('error', 'Admit card not found.');
    redirect(APP_URL . '/admit-card/index.php');
}

db()->prepare('DELETE FROM ac_admit_cards WHERE id = ?')->execute([$id]);

flash_set('success', 'Admit card "' . $card['exam_name'] . '" deleted.');
redirect(APP_URL . '/admit-card/index.php');
