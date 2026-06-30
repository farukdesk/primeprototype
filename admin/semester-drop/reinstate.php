<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('semester-drop', 'can_delete');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/semester-drop/index.php');
}
csrf_check();

$id      = (int)($_POST['id'] ?? 0);
$comment = trim($_POST['reinstate_comment'] ?? '');
$me      = auth_user();

$drop = sd_get_drop($id);
if (!$drop || ($drop['kind'] ?? 'drop') !== 'dropout') {
    flash_set('error', 'Dropout record not found.');
    redirect(APP_URL . '/semester-drop/index.php');
}

if ($drop['status'] !== 'active') {
    flash_set('warning', 'This dropout has already been re-instated.');
    redirect(APP_URL . '/semester-drop/view.php?id=' . $id);
}

$errors = [];

// Comment is mandatory when re-instating a dropout.
if ($comment === '') {
    $errors[] = 'A comment is required to re-instate a dropout.';
}

// Evidence is mandatory when re-instating a dropout.
$has_upload = isset($_FILES['evidence']) && ($_FILES['evidence']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
if (!$has_upload) {
    $errors[] = 'Evidence is required to re-instate a dropout. Please upload a supporting document.';
}

$evidence_file_id = null;
if (empty($errors)) {
    $evidence_file_id = sd_store_evidence($_FILES['evidence'], (int)$drop['student_id'], (int)$me['id']);
    if ($evidence_file_id === null) {
        $errors[] = 'Could not upload evidence. Allowed: images, PDF or Word documents up to 20 MB.';
    }
}

if (!empty($errors)) {
    foreach ($errors as $e) {
        flash_set('error', $e);
    }
    redirect(APP_URL . '/semester-drop/view.php?id=' . $id);
}

sd_reinstate_dropout($id, (int)$drop['student_id'], (int)$me['id'], $comment, (int)$evidence_file_id);
flash_set('success', 'Dropout re-instated. The student is active again and their account is no longer frozen.');
redirect(APP_URL . '/semester-drop/view.php?id=' . $id);
