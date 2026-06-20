<?php
/**
 * Admissions – Set / Update Scholarship for an application.
 * Stores a single scholarship entry on the application row.
 * Supports both percentage-based and fixed-amount discount types.
 *
 * POST params: id, scholarship_label, discount_type, discount_pct (pct),
 *              scholarship_amount (fixed), scholarship_scope,
 *              applies_to_fixed, applies_to_english, redirect_to
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('admissions', 'can_edit');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/admissions/index.php');
}

csrf_check();

$id           = (int)($_POST['id'] ?? 0);
$label        = trim($_POST['scholarship_label'] ?? '');
$type         = in_array($_POST['discount_type'] ?? '', ['percentage', 'fixed'], true)
                ? $_POST['discount_type']
                : 'fixed';
$pct          = (float)($_POST['discount_pct']       ?? 0);
$fixed_input  = (float)($_POST['scholarship_amount'] ?? 0);
$scope        = in_array($_POST['scholarship_scope'] ?? '', ['first_semester', 'all_semesters'], true)
                ? $_POST['scholarship_scope']
                : 'first_semester';
$applies_fixed   = isset($_POST['applies_to_fixed'])   ? 1 : 0;
$applies_english = isset($_POST['applies_to_english']) ? 1 : 0;
$redirect_to  = trim($_POST['redirect_to'] ?? '');

$errors = [];

if ($id <= 0) $errors[] = 'Invalid application.';
if ($label === '') $errors[] = 'Scholarship label is required.';

$app = null;
if (empty($errors)) {
    $stmt = db()->prepare(
        'SELECT id, financial_tuition_per_semester,
                financial_fixed_institutional_fees, financial_english_course_fee,
                financial_total_semesters
         FROM admissions_applications WHERE id = ?'
    );
    $stmt->execute([$id]);
    $app = $stmt->fetch();
    if (!$app) {
        $errors[] = 'Application not found.';
    }
}

// Resolve scholarship amount from type
$amount = 0.0;
if (empty($errors)) {
    $tuition_sem = (float)($app['financial_tuition_per_semester'] ?? 0);
    $total_sems  = (int)($app['financial_total_semesters'] ?? 0);
    $fixed_total = (float)($app['financial_fixed_institutional_fees'] ?? 0);
    $english_total = (float)($app['financial_english_course_fee'] ?? 0);
    $fixed_per_sem   = $total_sems > 0 ? round($fixed_total   / $total_sems, 2) : 0.0;
    $english_per_sem = $total_sems > 0 ? round($english_total / $total_sems, 2) : 0.0;

    if ($type === 'percentage') {
        if ($pct < 0.0001 || $pct > 100) {
            $errors[] = 'Discount percentage must be between 0.0001 and 100.';
        } else {
            $amount  = round($tuition_sem * $pct / 100, 2);
            if ($applies_fixed)   $amount += round($fixed_per_sem   * $pct / 100, 2);
            if ($applies_english) $amount += round($english_per_sem * $pct / 100, 2);
            $amount  = round($amount, 2);
        }
    } else {
        // fixed type
        if ($fixed_input < 0.01) {
            $errors[] = 'Scholarship amount must be greater than 0.';
        } else {
            $amount = round($fixed_input, 2);
            $pct    = 0.0;
        }
    }
}

if (empty($errors)) {
    db()->prepare(
        'UPDATE admissions_applications
            SET scholarship_label             = ?,
                scholarship_amount            = ?,
                scholarship_discount_type     = ?,
                scholarship_discount_pct      = ?,
                scholarship_scope             = ?,
                scholarship_applies_to_fixed  = ?,
                scholarship_applies_to_english = ?
          WHERE id = ?'
    )->execute([$label, $amount, $type, $pct, $scope, $applies_fixed, $applies_english, $id]);

    $scope_desc = $scope === 'all_semesters' ? 'all semesters' : 'first semester';

    $log_description = $type === 'percentage'
        ? $label . ' – ' . number_format($pct, 4) . '% (BDT ' . number_format($amount, 2) . ' per semester) for ' . $scope_desc
        : $label . ' – BDT ' . number_format($amount, 2) . ' for ' . $scope_desc;

    log_change(
        'admissions', 'UPDATE', $id,
        'Scholarship',
        'scholarship_set',
        null,
        $log_description,
        'Scholarship "' . $label . '" (' . $log_description . ') set for application #' . $id
    );

    flash_set('success', 'Scholarship <strong>' . h($label) . '</strong> saved for ' . h($scope_desc) . '.');
} else {
    flash_set('error', implode(' ', $errors));
}

$target = $redirect_to === 'statement' ? 'statement.php' : 'view.php';
redirect(APP_URL . '/admissions/' . $target . '?id=' . $id);
