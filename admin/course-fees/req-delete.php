<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('course-fees', 'can_delete');

$req_id  = (int)($_GET['id']   ?? 0);
$prog_id = (int)($_GET['prog'] ?? 0);

if ($req_id <= 0) {
    flash_set('error', 'Invalid request.');
    redirect(APP_URL . '/course-fees/index.php');
}

$db = db();

$req = $db->prepare('SELECT * FROM cf_admission_requirements WHERE id=?');
$req->execute([$req_id]);
$row = $req->fetch();

if (!$row) {
    flash_set('error', 'Requirement not found.');
    redirect(APP_URL . '/course-fees/view.php?id=' . $prog_id);
}

$db->prepare('DELETE FROM cf_admission_requirements WHERE id=?')->execute([$req_id]);

log_change('course-fees', 'UPDATE', $row['program_id'], 'Program #' . $row['program_id'], 'admission_requirements', $row['requirement_text'], null, 'Requirement removed.');

flash_set('success', 'Requirement removed.');
redirect(APP_URL . '/course-fees/view.php?id=' . ($prog_id ?: $row['program_id']));
