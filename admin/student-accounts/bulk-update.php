<?php
/**
 * Bulk update student fee packages - SUPER ADMIN ONLY.
 *
 * Applies any combination of the following to the selected packages:
 *   - Programme (cf_program_id + snapshotted program_name)
 *   - Department (students.dept_id on the student record)
 *   - Total semesters (sfp_semester_fees rows are added / removed to match;
 *     fixed-amount scholarships are redistributed so the grand total stays
 *     the same, split evenly across the new semester count, and all-semester
 *     percentage scholarships are extended to the new semesters)
 *   - Tuition per semester (applied to every semester row, scholarships recalculated)
 *   - Monthly fixed fee (fixed_institutional_fees re-derived from total_months)
 *   - Fixed institutional fees total (monthly_fixed_fee re-derived)
 *   - "Select all pages": when select_all_matching = 1, package IDs are
 *     resolved server-side from the posted list filters
 *   - Project fee (requires the project_fee column - see
 *     admin/student-accounts-project-fee.sql)
 *   - Target monthly total: rebalances Fixed Institutional Fees so the
 *     student's monthly total (sem-1 tuition payable / months-per-semester
 *     + monthly fixed + monthly English) equals the given figure, moving the
 *     difference into the one-time Project Fee so the Grand Total
 *     (incl. Admission, Form & ID Card & Project Fees) stays unchanged
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

// Preserve the list filters and page across the redirect back to the index,
// so applying a bulk edit does not reset the user's search/filter state.
$back_qs = http_build_query(array_filter([
    'q'       => trim((string)($_POST['flt_q'] ?? '')),
    'dept'    => (int)($_POST['flt_dept']    ?? 0) ?: null,
    'program' => (int)($_POST['flt_program'] ?? 0) ?: null,
    'batch'   => (int)($_POST['flt_batch']   ?? 0) ?: null,
    'sems'    => (int)($_POST['flt_sems']    ?? 0) ?: null,
    'page'    => (int)($_POST['flt_page']    ?? 0) ?: null,
], fn($v) => $v !== null && $v !== ''));
$back_url = APP_URL . '/student-accounts/index.php' . ($back_qs !== '' ? '?' . $back_qs : '');

$ids = array_values(array_unique(array_filter(
    array_map('intval', (array)($_POST['package_ids'] ?? [])),
    fn($v) => $v > 0
)));

// "Select all pages": resolve the package IDs server-side from the same
// filters used on the index page, instead of only the checkboxes rendered
// on the current page.
if ((int)($_POST['select_all_matching'] ?? 0) === 1) {
    $flt_q       = trim((string)($_POST['flt_q'] ?? ''));
    $flt_dept    = (int)($_POST['flt_dept']    ?? 0);
    $flt_program = (int)($_POST['flt_program'] ?? 0);
    $flt_batch   = (int)($_POST['flt_batch']   ?? 0);
    $flt_sems    = (int)($_POST['flt_sems']    ?? 0);

    $flt_where  = ['1=1'];
    $flt_params = [];
    if ($flt_q !== '') {
        $flt_where[]  = '(s.full_name LIKE ? OR s.student_id LIKE ?)';
        $flt_params[] = "%$flt_q%";
        $flt_params[] = "%$flt_q%";
    }
    if ($flt_dept > 0)    { $flt_where[] = 's.dept_id = ?';          $flt_params[] = $flt_dept; }
    if ($flt_program > 0) { $flt_where[] = 's.program_id = ?';       $flt_params[] = $flt_program; }
    if ($flt_batch > 0)   { $flt_where[] = 's.batch_id = ?';         $flt_params[] = $flt_batch; }
    if ($flt_sems > 0)    { $flt_where[] = 'p.total_semesters = ?';  $flt_params[] = $flt_sems; }

    $flt_stmt = $db->prepare(
        'SELECT p.id
         FROM sfp_packages p
         JOIN students s ON s.id = p.student_id
         WHERE ' . implode(' AND ', $flt_where)
    );
    $flt_stmt->execute($flt_params);
    $ids = array_map('intval', $flt_stmt->fetchAll(PDO::FETCH_COLUMN));
}

$cf_program_id      = (int)($_POST['bulk_cf_program_id']      ?? 0);
$student_program_id = (int)($_POST['bulk_student_program_id'] ?? 0);
$dept_id            = (int)($_POST['bulk_dept_id']            ?? 0);
$semesters_raw = trim((string)($_POST['bulk_total_semesters']      ?? ''));
$tuition_raw   = trim((string)($_POST['bulk_tuition_per_semester'] ?? ''));
$monthly_raw   = trim((string)($_POST['bulk_monthly_fixed']        ?? ''));
$project_raw   = trim((string)($_POST['bulk_project_fee']          ?? ''));
$fixed_total_raw = trim((string)($_POST['bulk_fixed_institutional_fees'] ?? ''));
$target_monthly_raw = trim((string)($_POST['bulk_target_monthly_total'] ?? ''));

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
$fixed_total     = $fixed_total_raw !== '' ? round((float)$fixed_total_raw, 2) : null;
$target_monthly  = $target_monthly_raw !== '' ? round((float)$target_monthly_raw, 2) : null;
$monthly_payment = $monthly_payment_raw !== '' ? round((float)$monthly_payment_raw, 2) : null;
$total_months    = $total_months_raw !== '' ? (int)$total_months_raw            : null;
$mps             = $mps_raw          !== '' ? round((float)$mps_raw, 2)         : null;
$std_tuition     = $std_tuition_raw  !== '' ? round((float)$std_tuition_raw, 2) : null;
$reg_fee         = $reg_fee_raw      !== '' ? round((float)$reg_fee_raw, 2)     : null;

$errors = [];

if (empty($ids)) {
    $errors[] = 'No student accounts selected.';
}
if ($cf_program_id <= 0 && $student_program_id <= 0 && $dept_id <= 0 && $total_semesters === null
    && $tuition === null && $monthly_fixed === null && $project_fee === null
    && $fixed_total === null
    && $payment_type === '' && $monthly_payment === null
    && $bi_start_month <= 0 && $tri_start_month <= 0
    && $total_months === null && $mps === null && $std_tuition === null && $reg_fee === null
    && $target_monthly === null) {
    $errors[] = 'No changes specified. Set at least one field.';
}
if ($target_monthly !== null && $target_monthly < 0) {
    $errors[] = 'Target monthly total cannot be negative.';
}
if ($target_monthly !== null && ($monthly_fixed !== null || $fixed_total !== null || $project_fee !== null)) {
    $errors[] = 'Target Monthly Total cannot be combined with Monthly Fixed, Fixed Institutional Fees, or Project Fee.';
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
if ($fixed_total   !== null && $fixed_total   < 0)      $errors[] = 'Fixed institutional fees cannot be negative.';
if ($fixed_total !== null && $monthly_fixed !== null) {
    $errors[] = 'Set either Monthly Fixed or Fixed Institutional Fees (total), not both.';
}
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

$student_program = null;
if ($student_program_id > 0 && empty($errors)) {
    $st = $db->prepare('SELECT id, program_name FROM dept_academic_programs WHERE id = ? AND is_active = 1');
    $st->execute([$student_program_id]);
    $student_program = $st->fetch();
    if (!$student_program) $errors[] = 'Selected student programme not found or inactive.';
}

// The project_fee column is added by admin/student-accounts-project-fee.sql
// (also required for the Target Monthly Total rebalance, which shifts the
// fixed-fee difference into the one-time project fee)
if (($project_fee !== null || $target_monthly !== null) && empty($errors)) {
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
                if ($monthly_fixed === null && $fixed_total === null) {
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

            if ($fixed_total !== null) {
                $set[]     = 'fixed_institutional_fees = ?';
                $params[]  = $fixed_total;
                $set[]     = 'monthly_fixed_fee = ?';
                $params[]  = $new_months > 0 ? round($fixed_total / $new_months, 4) : 0;
                $changes[] = 'fixed institutional fees -> ' . number_format($fixed_total, 2);
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

            // Add / remove per-semester fee rows to match the new semester count.
            // Scholarships are redistributed over the new semester count:
            //   - Fixed-amount scholarships spanning 2+ semesters keep the same
            //     grand total, split evenly across ALL semesters (e.g. 100 over
            //     4 semesters -> 16.66 per semester over 6; the last semester
            //     absorbs the rounding remainder).
            //   - Percentage scholarships that covered every semester are
            //     extended to the newly added semesters at the same percentage.
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
                $old_sem_count = count($rows);

                // Snapshot existing scholarships (grouped by identity) BEFORE
                // semester rows are added/removed, so fixed-amount totals can
                // be redistributed over the new semester count.
                $sc_groups = [];
                if (!empty($rows)) {
                    $snap_sf_ids = array_map(fn($r) => (int)$r['id'], $rows);
                    $snap_phs    = implode(',', array_fill(0, count($snap_sf_ids), '?'));
                    $sc_stmt = $db->prepare(
                        "SELECT * FROM sfp_semester_scholarships
                         WHERE sf_id IN ($snap_phs)
                         ORDER BY created_at ASC, id ASC"
                    );
                    $sc_stmt->execute($snap_sf_ids);
                    foreach ($sc_stmt->fetchAll() as $sc) {
                        $key = implode('|', [
                            (string)$sc['label'],
                            (string)($sc['discount_type'] ?? 'percentage'),
                            (string)$sc['discount_pct'],
                            (string)($sc['note'] ?? ''),
                            (string)(int)$sc['is_from_policy'],
                            (string)(int)$sc['applies_to_fixed'],
                            (string)(int)$sc['applies_to_english'],
                            (string)($sc['support_doc_id'] ?? ''),
                        ]);
                        if (!isset($sc_groups[$key])) {
                            $sc_groups[$key] = [
                                'sample'      => $sc,
                                'total_fixed' => 0.0,
                                'row_ids'     => [],
                                'sf_ids'      => [],
                            ];
                        }
                        $sc_groups[$key]['total_fixed'] += (float)($sc['fixed_amount'] ?? 0);
                        $sc_groups[$key]['row_ids'][]    = (int)$sc['id'];
                        $sc_groups[$key]['sf_ids'][]     = (int)$sc['sf_id'];
                    }
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

                // Redistribute the snapshotted scholarships across the
                // (possibly changed) set of semester rows.
                if (!empty($sc_groups)) {
                    $cur->execute([$package_id]);
                    $new_rows   = $cur->fetchAll();
                    $new_sf_ids = array_map(fn($r) => (int)$r['id'], $new_rows);
                    $sem_count  = count($new_sf_ids);

                    $ins_sc = $db->prepare(
                        'INSERT INTO sfp_semester_scholarships
                           (sf_id, label, discount_pct, discount_type, fixed_amount, amount, note,
                            is_from_policy, applies_to_fixed, applies_to_english,
                            support_doc_id, created_by)
                         VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)'
                    );

                    foreach ($sc_groups as $grp) {
                        $sc      = $grp['sample'];
                        $sc_type = $sc['discount_type'] ?? 'percentage';

                        if ($sc_type === 'fixed') {
                            // Single-semester fixed scholarships are left where
                            // they are; only multi-semester ones are respread.
                            if ($sem_count <= 0 || count($grp['row_ids']) < 2) {
                                continue;
                            }

                            // Remove the old rows, then re-insert one row per
                            // semester with the grand total split evenly.
                            $del_phs = implode(',', array_fill(0, count($grp['row_ids']), '?'));
                            $db->prepare("DELETE FROM sfp_semester_scholarships WHERE id IN ($del_phs)")
                               ->execute($grp['row_ids']);

                            $sc_total = round((float)$grp['total_fixed'], 2);
                            $per_sem  = floor($sc_total / $sem_count * 100) / 100;
                            $assigned = 0.0;

                            foreach ($new_sf_ids as $idx => $target_sf_id) {
                                $amt = ($idx === $sem_count - 1)
                                    ? round($sc_total - $assigned, 2)
                                    : $per_sem;
                                $assigned += $amt;

                                $ins_sc->execute([
                                    $target_sf_id, $sc['label'], 0, 'fixed', $amt,
                                    $sc['note'] ?? null,
                                    (int)$sc['is_from_policy'],
                                    (int)$sc['applies_to_fixed'],
                                    (int)$sc['applies_to_english'],
                                    $sc['support_doc_id'] ?? null,
                                    (int)$sc['created_by'],
                                ]);
                            }
                        } else {
                            // Percentage scholarship: only extend it when it
                            // covered every semester before the change, then
                            // copy the same percentage onto the new rows.
                            if (count($grp['sf_ids']) < $old_sem_count) {
                                continue;
                            }
                            foreach ($new_sf_ids as $target_sf_id) {
                                if (in_array($target_sf_id, $grp['sf_ids'], true)) {
                                    continue;
                                }
                                $ins_sc->execute([
                                    $target_sf_id, $sc['label'], $sc['discount_pct'], 'percentage', null,
                                    $sc['note'] ?? null,
                                    (int)$sc['is_from_policy'],
                                    (int)$sc['applies_to_fixed'],
                                    (int)$sc['applies_to_english'],
                                    $sc['support_doc_id'] ?? null,
                                    (int)$sc['created_by'],
                                ]);
                            }
                        }
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
            if ($tuition !== null || $monthly_fixed !== null || $fixed_total !== null
                || $total_semesters !== null
                || $total_months !== null || $mps !== null) {
                $sf_stmt = $db->prepare('SELECT id FROM sfp_semester_fees WHERE package_id = ?');
                $sf_stmt->execute([$package_id]);
                foreach ($sf_stmt->fetchAll() as $sf) {
                    sfp_recalculate_semester((int)$sf['id'], (int)$user['id']);
                }
            }

            // Keep the student's monthly total at the target figure WITHOUT
            // changing the Grand Total (incl. Admission, Form & ID Card &
            // Project Fees). The Fixed Institutional Fees are rebalanced so
            //   sem-1 tuition payable / mps + monthly fixed + monthly English = target
            // and the removed (or added) fixed amount is shifted into the
            // one-time Project Fee, keeping the Grand Total identical.
            if ($target_monthly !== null) {
                $fresh    = sfp_get_package($package_id);
                $t_months = (float)($fresh['total_months'] ?? 0);
                $t_mps    = (float)($fresh['months_per_semester'] ?? 0);

                if ($fresh && $t_months > 0 && $t_mps > 0) {
                    $sf1_stmt = $db->prepare(
                        'SELECT tuition_payable FROM sfp_semester_fees
                         WHERE package_id = ? AND semester_number = 1 LIMIT 1'
                    );
                    $sf1_stmt->execute([$package_id]);
                    $sf1_row = $sf1_stmt->fetch();

                    $monthly_tuition = $sf1_row ? (float)$sf1_row['tuition_payable'] / $t_mps : 0.0;
                    $monthly_english = (float)($fresh['english_course_fee'] ?? 0) / $t_months;

                    $new_monthly_fixed_val = $target_monthly - $monthly_tuition - $monthly_english;
                    if ($new_monthly_fixed_val < 0) {
                        // Tuition + English alone already exceed the target;
                        // the closest we can get is a zero fixed fee.
                        $new_monthly_fixed_val = 0.0;
                    }

                    $old_fixed_total_val = (float)$fresh['fixed_institutional_fees'];
                    $old_project_val     = (float)($fresh['project_fee'] ?? 0);
                    $new_fixed_total_val = round($new_monthly_fixed_val * $t_months, 2);
                    $shift               = round($old_fixed_total_val - $new_fixed_total_val, 2);

                    // When the target monthly is HIGHER than the current one,
                    // fixed fees grow and the project fee shrinks - never let
                    // the project fee go below zero.
                    if ($shift < 0 && $old_project_val + $shift < 0) {
                        $shift                 = -$old_project_val;
                        $new_fixed_total_val   = round($old_fixed_total_val - $shift, 2);
                        $new_monthly_fixed_val = $new_fixed_total_val / $t_months;
                    }

                    if (abs($shift) >= 0.01) {
                        $db->prepare(
                            'UPDATE sfp_packages
                             SET fixed_institutional_fees = ?, monthly_fixed_fee = ?, project_fee = ?
                             WHERE id = ?'
                        )->execute([
                            $new_fixed_total_val,
                            round($new_monthly_fixed_val, 4),
                            round($old_project_val + $shift, 2),
                            $package_id,
                        ]);

                        $changes[] = 'monthly total kept at ' . number_format($target_monthly, 2)
                            . ': fixed fees ' . number_format($old_fixed_total_val, 2)
                            . ' -> ' . number_format($new_fixed_total_val, 2)
                            . ', project fee ' . number_format($old_project_val, 2)
                            . ' -> ' . number_format($old_project_val + $shift, 2);

                        // The per-semester fixed portion changed - refresh the
                        // scholarship cascades / payables.
                        $sf_stmt2 = $db->prepare('SELECT id FROM sfp_semester_fees WHERE package_id = ?');
                        $sf_stmt2->execute([$package_id]);
                        foreach ($sf_stmt2->fetchAll() as $sf2) {
                            sfp_recalculate_semester((int)$sf2['id'], (int)$user['id']);
                        }
                    }
                }
            }

            // Department lives on the student record
            if ($dept) {
                $db->prepare('UPDATE students SET dept_id = ? WHERE id = ?')
                   ->execute([(int)$dept['id'], (int)$pkg['student_id']]);
                $changes[] = 'department -> ' . $dept['name'];
            }

            // Academic programme lives on the student record (students.program_id),
            // shown on the students list at admin/students/index.php
            if ($student_program) {
                $db->prepare('UPDATE students SET program_id = ? WHERE id = ?')
                   ->execute([(int)$student_program['id'], (int)$pkg['student_id']]);
                $changes[] = 'student programme -> ' . $student_program['program_name'];
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

redirect($back_url);
