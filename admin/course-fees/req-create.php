<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('course-fees', 'can_edit');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/course-fees/index.php');
}

csrf_check();

$program_id = (int)($_POST['program_id'] ?? 0);
$text       = trim($_POST['requirement_text'] ?? '');

if ($program_id <= 0 || $text === '') {
    flash_set('error', 'Invalid input.');
    redirect(APP_URL . '/course-fees/index.php');
}

// Verify program exists
$prog = cf_get_program($program_id);
if (!$prog) {
    flash_set('error', 'Program not found.');
    redirect(APP_URL . '/course-fees/index.php');
}

if (strlen($text) > 500) {
    flash_set('error', 'Requirement text is too long (max 500 characters).');
    redirect(APP_URL . '/course-fees/view.php?id=' . $program_id);
}

$db = db();

// Get next sort order
$max_sort = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM cf_admission_requirements WHERE program_id=?');
$max_sort->execute([$program_id]);
$sort = (int)$max_sort->fetchColumn();

$db->prepare(
    'INSERT INTO cf_admission_requirements (program_id, requirement_text, sort_order) VALUES (?,?,?)'
)->execute([$program_id, $text, $sort]);

log_change('course-fees', 'UPDATE', $program_id, $prog['program_name'], 'admission_requirements', null, $text, 'Requirement added.');

flash_set('success', 'Requirement added.');
redirect(APP_URL . '/course-fees/view.php?id=' . $program_id . '#tabReqs');
