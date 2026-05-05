<?php
/**
 * Legacy endpoint – redirect to add-scholarship.php.
 * The module now supports multiple scholarships per semester;
 * use add-scholarship.php to add and delete-scholarship.php to remove individual entries.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('student-fee-package', 'can_edit');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/student-fee-package/index.php');
}

csrf_check();

// Map legacy field names to the new handler's expected fields
$_POST['sc_label'] = trim($_POST['sc_label'] ?? $_POST['sc_note'] ?? 'Scholarship');

// Delegate to add-scholarship.php
require __DIR__ . '/add-scholarship.php';
