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
$start_month   = (int)($_POST['bulk_payment_start_month'] ?? 0); // 0 = no change

$total_semesters = $semesters_raw !== '' ? (int)$semesters_raw           : null;
$tuition         = $tuition_raw   !== '' ? round((float)$tuition_raw, 2) : null;
$monthly_fixed   = $monthly_raw   !== '' ? round((float)$monthly_raw, 2) : null;
$project_fee     = $project_raw   !== '' ? round((float)$project_raw, 2) : null;

$errors = [];

if (empty($ids)) {
    $errors[] = 'No student accounts selected.';
}
if ($cf_program_id <= 0 && $dept_id <= 0 && $total_semesters === null
    && $tuition === null && $monthly_fixed === null && $project_fee === null
    && $start_month <= 0) {
    $errors[] = 'No changes specified. Set at least one field.';
}
if ($start_month !== 0 && ($start_month < 1 || $start_month > 12)) {
    $errors[] = 'Payment start month must be between 1 and 12.';
}
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

            if ($total_semesters !== null && $total_semesters !== (int)$pkg['total_semesters']) {
                $months    = (float)$pkg['total_months'];
                $set[]     = 'total_semesters = ?';
                $params[]  = $total_semesters;
                $set[]     = 'months_per_semester = ?';
                $params[]  = $months > 0 ? round($months / $total_semesters, 2) : 0;
                $changes[] = 'semesters ' . (int)$pkg['total_semesters'] . ' -> ' . $total_semesters;
            }

            if ($tuition !== null) {
                $set[]     = 'tuition_per_semester = ?';
                $params[]  = $tuition;
                $changes[] = 'tuition/semester -> ' . number_format($tuition, 2);
            }

            if ($monthly_fixed !== null) {
                $months    = (float)$pkg['total_months'];
                $set[]     = 'monthly_fixed_fee = ?';
                $params[]  = $monthly_fixed;
                $set[]     = 'fixed_institutional_fees = ?';
                $params[]  = round($monthly_fixed * $months, 2);
                $changes[] = 'monthly fixed -> ' . number_format($monthly_fixed, 2);
            }

            if ($project_fee !== null) {
                $set[]     = 'project_fee = ?';
                $params[]  = $project_fee;
                $changes[] = 'project fee -> ' . number_format($project_fee, 2);
            }

            if ($start_month >= 1 && $start_month <= 12) {
                // Bi-semester packages use bi_semester_start_month,
                // tri-semester packages use tri_semester_start_month.
                $sem_count = $total_semesters ?? (int)$pkg['total_semesters'];
                $month_col = $sem_count > SFP_MAX_BI_SEMESTER_COUNT
                    ? 'tri_semester_start_month'
                    : 'bi_semester_start_month';
                $set[]     = $month_col . ' = ?';
                $params[]  = $start_month;
                $changes[] = 'payment start month -> ' . sfp_get_month_name(1, $start_month);
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
            if ($tuition !== null || $monthly_fixed !== null || $total_semesters !== null) {
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
