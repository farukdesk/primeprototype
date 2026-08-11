<?php
/**
 * Bulk update student fee packages - SUPER ADMIN ONLY.
 *
 * Applies any combination of the following to the selected packages:
 *   - Programme (cf_program_id + snapshotted program_name)
 *   - Department (students.dept_id on the student record)
 *   - Total semesters (sfp_semester_fees rows are added / removed to match)
 *   - Tuition per semester (applied to every semester row, scholarships recalculated)
 *   - Monthly fixed fee (fixed_institutional_fees re-derived from total_months)
 *   - Project fee (requires the project_fee column - see
 *     admin/student-accounts-project-fee.sql)
 *
 * Fields left blank are not changed.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('student-accounts', 'can_edit');
require_once __DIR__ . '/helpers.php';

if (!is_super_admin()) {
    flash_set('error', 'Bulk edit is available to super admins only.');
    redirect(APP_URL . '/student-accounts/index.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/student-accounts/index.php');
}

csrf_check();

$db   = db();
$user = auth_user();

$ids = array_values(array_unique(array_filter(
    array_map('intval', (array)($_POST['package_ids'] ?? [])),
    fn($v) => $v > 0
)));

$cf_program_id = (int)($_POST['bulk_cf_program_id'] ?? 0);
$dept_id       = (int)($_POST['bulk_dept_id']       ?? 0);
$semesters_raw = trim((string)($_POST['bulk_total_semesters']      ?? ''));
$tuition_raw   = trim((string)($_POST['bulk_tuition_per_semester'] ?? ''));
$monthly_raw   = trim((string)($_POST['bulk_monthly_fixed']        ?? ''));
$project_raw   = trim((string)($_POST['bulk_project_fee']          ?? ''));

$payment_type       = trim((string)($_POST['bulk_payment_type'] ?? ''));    // '' = no change
$monthly_payment_raw = trim((string)($_POST['bulk_monthly_payment'] ?? ''));
$bi_start_month     = (int)($_POST['bulk_bi_start_month']  ?? 0);           // 0 = no change
$tri_start_month    = (int)($_POST['bulk_tri_start_month'] ?? 0);           // 0 = no change
$total_months_raw   = trim((string)($_POST['bulk_total_months']          ?? ''));
$mps_raw            = trim((string)($_POST['bulk_months_per_semester']   ?? ''));
$std_tuition_raw    = trim((string)($_POST['bulk_standard_tuition_full'] ?? ''));
$reg_fee_raw        = trim((string)($_POST['bulk_reg_fee_per_semester']  ?? ''));

$total_semesters = $semesters_raw !== '' ? (int)$semesters_raw           : null;
$tuition         = $tuition_raw   !== '' ? round((float)$tuition_raw, 2) : null;
$monthly_fixed   = $monthly_raw   !== '' ? round((float)$monthly_raw, 2) : null;
$project_fee     = $project_raw   !== '' ? round((float)$project_raw, 2) : null;
$monthly_payment = $monthly_payment_raw !== '' ? round((float)$monthly_payment_raw, 2) : null;
$total_months    = $total_months_raw !== '' ? (int)$total_months_raw            : null;
$mps             = $mps_raw          !== '' ? round((float)$mps_raw, 2)         : null;
$std_tuition     = $std_tuition_raw  !== '' ? round((float)$std_tuition_raw, 2) : null;
$reg_fee         = $reg_fee_raw      !== '' ? round((float)$reg_fee_raw, 2)     : null;

$errors = [];

if (empty($ids)) {
    $errors[] = 'No student accounts selected.';
}
if ($cf_program_id <= 0 && $dept_id <= 0 && $total_semesters === null
    && $tuition === null && $monthly_fixed === null && $project_fee === null
    && $payment_type === '' && $monthly_payment === null
    && $bi_start_month <= 0 && $tri_start_month <= 0
    && $total_months === null && $mps === null && $std_tuition === null && $reg_fee === null) {
    $errors[] = 'No changes specified. Set at least one field.';
}
if ($payment_type !== '' && !in_array($payment_type, ['merit', 'fixed'], true)) {
    $errors[] = 'Payment type must be merit or fixed.';
}
if ($bi_start_month !== 0 && ($bi_start_month < 1 || $bi_start_month > 12)) {
    $errors[] = 'Bi-semester start month must be between 1 and 12.';
}
if ($tri_start_month !== 0 && ($tri_start_month < 1 || $tri_start_month > 12)) {
    $errors[] = 'Tri-semester start month must be between 1 and 12.';
}
if ($monthly_payment !== null && $monthly_payment < 0) $errors[] = 'Monthly payment cannot be negative.';
if ($total_months !== null && $total_months <= 0) $errors[] = 'Total months must be greater than 0.';
if ($mps          !== null && $mps          <= 0) $errors[] = 'Months per semester must be greater than 0.';
if ($std_tuition  !== null && $std_tuition  < 0)  $errors[] = 'Standard tuition cannot be negative.';
if ($reg_fee      !== null && $reg_fee      < 0)  $errors[] = 'Registration fee per semester cannot be negative.';
if ($total_semesters !== null && $total_semesters <= 0) $errors[] = 'Total semesters must be greater than 0.';
if ($tuition       !== null && $tuition       < 0)      $errors[] = 'Tuition per semester cannot be negative.';
if ($monthly_fixed !== null && $monthly_fixed < 0)      $errors[] = 'Monthly fixed fee cannot be negative.';
if ($project_fee   !== null && $project_fee   < 0)      $errors[] = 'Project fee cannot be negative.';

// -- Validate lookups ---------------------------------------------------------
$cf_program = null;
if ($cf_program_id > 0 && empty($errors)) {
    $st = $db->prepare('SELECT id, program_name FROM cf_programs WHERE id = ? AND is_active = 1');
    $st->execute([$cf_program_id]);
    $cf_program = $st->fetch();
    if (!$cf_program) $errors[] = 'Selected programme not found or inactive.';
}

$dept = null;
if ($dept_id > 0 && empty($errors)) {
    $st = $db->prepare('SELECT id, name FROM dept_departments WHERE id = ? AND is_active = 1');
    $st->execute([$dept_id]);
    $dept = $st->fetch();
    if (!$dept) $errors[] = 'Selected department not found or inactive.';
}

// The project_fee column is added by admin/student-accounts-project-fee.sql
if ($project_fee !== null && empty($errors)) {
    $col = $db->query("SHOW COLUMNS FROM sfp_packages LIKE 'project_fee'")->fetch();
    if (!$col) {
        $errors[] = 'The project_fee column does not exist yet. Run admin/student-accounts-project-fee.sql first.';
    }
}

// payment_type / monthly_payment columns come from admin/student-accounts-payment-type.sql
if (($payment_type !== '' || $monthly_payment !== null) && empty($errors)) {
    $col = $db->query("SHOW COLUMNS FROM sfp_packages LIKE 'payment_type'")->fetch();
    if (!$col) {
        $errors[] = 'The payment_type column does not exist yet. Run admin/student-accounts-payment-type.sql first.';
    }
}

// -- Apply changes -------------------------------------------------------------
$updated = 0;

if (empty($errors)) {
    $db->beginTransaction();
    try {
        foreach ($ids as $package_id) {
            $pkg = sfp_get_package($package_id);
            if (!$pkg) continue;

            $set     = [];
            $params  = [];
            $changes = [];

            if ($cf_program) {
                $set[]     = 'cf_program_id = ?';
                $params[]  = (int)$cf_program['id'];
                $set[]     = 'program_name = ?';
                $params[]  = $cf_program['program_name'];
                $changes[] = 'programme -> ' . $cf_program['program_name'];
            }

            $new_months = $total_months !== null ? (float)$total_months : (float)$pkg['total_months'];
            $new_sems   = $total_semesters ?? (int)$pkg['total_semesters'];

            if ($total_semesters !== null && $total_semesters !== (int)$pkg['total_semesters']) {
                $set[]     = 'total_semesters = ?';
                $params[]  = $total_semesters;
                $changes[] = 'semesters ' . (int)$pkg['total_semesters'] . ' -> ' . $total_semesters;
            }

            if ($total_months !== null && $total_months !== (int)$pkg['total_months']) {
                $set[]     = 'total_months = ?';
                $params[]  = $total_months;
                $changes[] = 'total months ' . (int)$pkg['total_months'] . ' -> ' . $total_months;
                // Re-derive monthly rates from the snapshotted totals
                // (monthly fixed is overridden further below when explicitly provided)
                if ($monthly_fixed === null) {
                    $set[]    = 'monthly_fixed_fee = ?';
                    $params[] = $total_months > 0 ? round((float)$pkg['fixed_institutional_fees'] / $total_months, 4) : 0;
                }
                $set[]    = 'monthly_english_fee = ?';
                $params[] = $total_months > 0 ? round((float)$pkg['english_course_fee'] / $total_months, 4) : 0;
            }

            // Months per semester: explicit value wins, otherwise re-derive when inputs changed
            if ($mps !== null) {
                $set[]     = 'months_per_semester = ?';
                $params[]  = $mps;
                $changes[] = 'months/semester -> ' . number_format($mps, 2);
            } elseif ($total_semesters !== null || $total_months !== null) {
                $set[]    = 'months_per_semester = ?';
                $params[] = $new_sems > 0 ? round($new_months / $new_sems, 2) : 0;
            }

            if ($tuition !== null) {
                $set[]     = 'tuition_per_semester = ?';
                $params[]  = $tuition;
                $changes[] = 'tuition/semester -> ' . number_format($tuition, 2);
            }

            if ($monthly_fixed !== null) {
                $set[]     = 'monthly_fixed_fee = ?';
                $params[]  = $monthly_fixed;
                $set[]     = 'fixed_institutional_fees = ?';
                $params[]  = round($monthly_fixed * $new_months, 2);
                $changes[] = 'monthly fixed -> ' . number_format($monthly_fixed, 2);
            }

            if ($project_fee !== null) {
                $set[]     = 'project_fee = ?';
                $params[]  = $project_fee;
                $changes[] = 'project fee -> ' . number_format($project_fee, 2);
            }

            if ($payment_type !== '') {
                $set[]     = 'payment_type = ?';
                $params[]  = $payment_type;
                $changes[] = 'payment type -> ' . $payment_type;
            }

            if ($monthly_payment !== null) {
                $set[]     = 'monthly_payment = ?';
                $params[]  = $monthly_payment;
                $changes[] = 'monthly payment -> ' . number_format($monthly_payment, 2);
            }

            if ($std_tuition !== null) {
                $set[]     = 'standard_tuition_full = ?';
                $params[]  = $std_tuition;
                $changes[] = 'standard tuition -> ' . number_format($std_tuition, 2);
            }

            if ($reg_fee !== null) {
                $set[]     = 'reg_fee_per_semester = ?';
                $params[]  = $reg_fee;
                $changes[] = 'reg fee/semester -> ' . number_format($reg_fee, 2);
            }

            if ($bi_start_month >= 1 && $bi_start_month <= 12) {
                $set[]     = 'bi_semester_start_month = ?';
                $params[]  = $bi_start_month;
                $changes[] = 'bi-semester start month -> ' . sfp_get_month_name(1, $bi_start_month);
            }

            if ($tri_start_month >= 1 && $tri_start_month <= 12) {
                $set[]     = 'tri_semester_start_month = ?';
                $params[]  = $tri_start_month;
                $changes[] = 'tri-semester start month -> ' . sfp_get_month_name(1, $tri_start_month);
            }

            if (!empty($set)) {
                $params[] = $package_id;
                $db->prepare(
                    'UPDATE sfp_packages SET ' . implode(', ', $set) . ' WHERE id = ?'
                )->execute($params);
            }

            // Add / remove per-semester fee rows to match the new semester count
            if ($total_semesters !== null) {
                $cur = $db->prepare(
                    'SELECT id, semester_number FROM sfp_semester_fees WHERE package_id = ? ORDER BY semester_number'
                );
                $cur->execute([$package_id]);
                $rows = $cur->fetchAll();

                $max_existing = 0;
                foreach ($rows as $r) {
                    $max_existing = max($max_existing, (int)$r['semester_number']);
                }

                if ($total_semesters > $max_existing) {
                    $per = $tuition ?? (float)$pkg['tuition_per_semester'];
                    $ins = $db->prepare(
                        'INSERT INTO sfp_semester_fees
                           (package_id, semester_number, tuition_fee, scholarship_discount_pct, scholarship_amount, tuition_payable)
                         VALUES (?, ?, ?, 0, 0, ?)'
                    );
                    for ($i = $max_existing + 1; $i <= $total_semesters; $i++) {
                        $ins->execute([$package_id, $i, $per, $per]);
                    }
                } elseif ($total_semesters < $max_existing) {
                    $remove_ids = [];
                    foreach ($rows as $r) {
                        if ((int)$r['semester_number'] > $total_semesters) {
                            $remove_ids[] = (int)$r['id'];
                        }
                    }
                    if (!empty($remove_ids)) {
                        $phs = implode(',', array_fill(0, count($remove_ids), '?'));
                        $db->prepare("DELETE FROM sfp_semester_scholarships WHERE sf_id IN ($phs)")
                           ->execute($remove_ids);
                        $db->prepare("DELETE FROM sfp_semester_fees WHERE id IN ($phs)")
                           ->execute($remove_ids);
                    }
                }
            }

            // Apply the new tuition to every remaining semester row
            if ($tuition !== null) {
                $db->prepare(
                    'UPDATE sfp_semester_fees
                     SET tuition_fee = ?, updated_by = ?, updated_at = NOW()
                     WHERE package_id = ?'
                )->execute([$tuition, $user['id'], $package_id]);
            }

            // Recalculate scholarship cascades / payables when fee inputs changed
            if ($tuition !== null || $monthly_fixed !== null || $total_semesters !== null
                || $total_months !== null || $mps !== null) {
                $sf_stmt = $db->prepare('SELECT id FROM sfp_semester_fees WHERE package_id = ?');
                $sf_stmt->execute([$package_id]);
                foreach ($sf_stmt->fetchAll() as $sf) {
                    sfp_recalculate_semester((int)$sf['id'], (int)$user['id']);
                }
            }

            // Department lives on the student record
            if ($dept) {
                $db->prepare('UPDATE students SET dept_id = ? WHERE id = ?')
                   ->execute([(int)$dept['id'], (int)$pkg['student_id']]);
                $changes[] = 'department -> ' . $dept['name'];
            }

            if (!empty($changes)) {
                log_change(
                    'student-accounts', 'UPDATE', $package_id,
                    ($pkg['student_name'] ?? '') . ' - ' . $pkg['program_name'],
                    null, null, null,
                    'Bulk edit: ' . implode('; ', $changes)
                );
                $updated++;
            }
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        $errors[] = 'Bulk update failed: ' . h($e->getMessage());
    }
}

if (!empty($errors)) {
    flash_set('error', implode(' ', $errors));
} else {
    flash_set('success', 'Bulk edit applied to <strong>' . $updated . '</strong> student account(s).');
}

redirect(APP_URL . '/student-accounts/index.php');
