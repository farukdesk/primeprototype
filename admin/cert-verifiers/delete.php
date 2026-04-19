<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('cert-verifiers', 'can_delete');

csrf_check();

// ── Single record delete ──────────────────────────────────────────────────────
if (!empty($_POST['id'])) {
    $id = (int)$_POST['id'];
    db()->prepare('DELETE FROM cert_verification_log WHERE id = ?')->execute([$id]);
    flash_set('success', 'Record deleted.');
    redirect(APP_URL . '/cert-verifiers/index.php');
}

// ── Delete all matching the current filter ────────────────────────────────────
if (!empty($_POST['delete_all'])) {
    $search  = trim($_POST['search']  ?? '');
    $f_type  = $_POST['type']         ?? '';
    $f_found = $_POST['found']        ?? '';

    $where  = [];
    $params = [];

    if ($search !== '') {
        $like     = '%' . $search . '%';
        $where[]  = '(queried_student_id LIKE ? OR verifier_name LIKE ? OR verifier_email LIKE ? OR company_name LIKE ?)';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if (in_array($f_type, ['student', 'company'], true)) {
        $where[]  = 'verifier_type = ?';
        $params[] = $f_type;
    }
    if ($f_found === '1') {
        $where[] = 'student_found = 1';
    } elseif ($f_found === '0') {
        $where[] = 'student_found = 0';
    }

    $where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    db()->prepare('DELETE FROM cert_verification_log' . $where_sql)->execute($params);
    flash_set('success', 'Records deleted.');
    redirect(APP_URL . '/cert-verifiers/index.php');
}

flash_set('error', 'Invalid request.');
redirect(APP_URL . '/cert-verifiers/index.php');
