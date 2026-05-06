<?php
/**
 * Accounting – AJAX: Load admission applicant info for the Collect Payment form.
 * Called from the "Admission Applicant" tab via fetch().
 * Returns JSON.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('accounting', 'can_create');
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json');

$app_number = trim($_GET['app_number'] ?? '');
if ($app_number === '') {
    echo json_encode(['error' => 'Application number is required.']);
    exit;
}

$applicant = acc_get_applicant_by_appnumber($app_number);
if (!$applicant) {
    echo json_encode(['error' => 'No application found with form number "' . htmlspecialchars($app_number, ENT_QUOTES) . '".']);
    exit;
}

// Suggested admission fee from cf_settings
$cf = db()->query('SELECT admission_fee_base FROM cf_settings WHERE id = 1')->fetch();
$suggested_fee = $cf ? (float)$cf['admission_fee_base'] : 0.0;

// Amount already collected for this application
$already_paid = acc_get_applicant_admission_paid((int)$applicant['id']);

// Income account for admission fees (code 4200)
$income_account_id = acc_income_account_id_by_code('4200');

// Check whether student-ID settings exist for this program
$has_sid_settings = false;
if ($applicant['program_id']) {
    $sid_chk = db()->prepare(
        'SELECT id FROM adm_student_id_settings WHERE program_id = ? LIMIT 1'
    );
    $sid_chk->execute([$applicant['program_id']]);
    $has_sid_settings = (bool)$sid_chk->fetchColumn();
}

echo json_encode([
    'applicant' => [
        'id'               => (int)$applicant['id'],
        'app_number'       => $applicant['app_number'],
        'student_name'     => $applicant['student_name'],
        'dept_name'        => $applicant['dept_name'] ?? '',
        'program_name'     => $applicant['program_name'] ?? '',
        'present_contact'  => $applicant['present_contact'] ?? '',
        'status'           => $applicant['status'],
        'office_student_id'=> $applicant['office_student_id'] ?? '',
    ],
    'suggested_fee'    => $suggested_fee,
    'already_paid'     => $already_paid,
    'income_account_id'=> $income_account_id,
    'can_assign_sid'   => ($has_sid_settings && empty($applicant['office_student_id'])),
]);
