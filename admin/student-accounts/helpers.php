<?php
/**
 * Student Accounts – Shared Helpers
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../change-log/helpers.php';

// ── Constants ─────────────────────────────────────────────────────────────────

/**
 * Maximum number of semesters for bi-semester programs.
 * Programs with total_semesters <= this value are considered bi-semester (2 semesters/year).
 * Programs with total_semesters > this value are considered tri-semester (3 semesters/year).
 */
define('SFP_MAX_BI_SEMESTER_COUNT', 8);

// ── Permission helpers ────────────────────────────────────────────────────────

function sfp_can_edit(): bool
{
    return is_super_admin() || can_access('student-accounts', 'can_edit');
}

function sfp_can_create(): bool
{
    return is_super_admin() || can_access('student-accounts', 'can_create');
}

function sfp_can_delete(): bool
{
    return is_super_admin() || can_access('student-accounts', 'can_delete');
}

// ── Money format ──────────────────────────────────────────────────────────────

function sfp_money(float $n): string
{
    return number_format($n, 2) . ' BDT';
}

// ── Get a package with student / assigner joins ───────────────────────────────

function sfp_get_package(int $id): array|false
{
    $stmt = db()->prepare(
        'SELECT p.*,
                s.full_name       AS student_name,
                s.student_id      AS student_sid,
                s.admitted_semester,
                s.status          AS student_status,
                u.full_name       AS assigned_by_name
         FROM sfp_packages p
         JOIN students s   ON s.id = p.student_id
         LEFT JOIN users u ON u.id = p.assigned_by
         WHERE p.id = ?'
    );
    $stmt->execute([$id]);
    return $stmt->fetch();
}

// ── Get semester fees for a package, ordered by semester_number ───────────────

function sfp_get_semester_fees(int $package_id): array
{
    $stmt = db()->prepare(
        'SELECT sf.*
         FROM sfp_semester_fees sf
         WHERE sf.package_id = ?
         ORDER BY sf.semester_number ASC'
    );
    $stmt->execute([$package_id]);
    return $stmt->fetchAll();
}

// ── Get all active cf_programs for the assignment form ────────────────────────

function sfp_get_cf_programs(): array
{
    return db()->query(
        'SELECT p.id, p.program_name, p.program_slug,
                p.total_semesters, p.total_months,
                COALESCE(p.standard_tuition_full, p.tuition_full, 0) AS standard_tuition_full,
                COALESCE(
                    p.tuition_per_semester,
                    CASE
                        WHEN p.total_semesters > 0 AND p.tuition_full IS NOT NULL
                        THEN ROUND(p.tuition_full / p.total_semesters, 2)
                        ELSE NULL
                    END,
                    0
                ) AS tuition_per_semester,
                COALESCE(p.admission_fees, p.admission_fee_m, 0) AS admission_fees,
                p.admission_fee_m,
                COALESCE(p.fixed_institutional_fees, p.institutional_fees, 0) AS fixed_institutional_fees,
                COALESCE(p.english_course_fee, 0) AS english_course_fee,
                COALESCE(
                    p.reg_fee_per_semester,
                    CASE
                        WHEN p.total_semesters > 0 AND p.registration_fee IS NOT NULL
                        THEN ROUND(p.registration_fee / p.total_semesters, 2)
                        ELSE NULL
                    END,
                    0
                ) AS reg_fee_per_semester,
                p.form_id_fee,
                p.safety_net_cap, p.safety_net_per_semester,
                p.attendance_requirement, p.safety_net_gpa_threshold,
                dt.name AS degree_type_name, dt.slug AS degree_type_slug
         FROM cf_programs p
         JOIN cf_degree_types dt ON dt.id = p.degree_type_id
         WHERE p.is_active = 1
         ORDER BY dt.sort_order, p.sort_order, p.program_name'
    )->fetchAll();
}

// ── Generate per-semester fee rows when a package is first created ────────────

function sfp_generate_semester_fees(int $package_id, int $total_semesters, float $tuition_per_semester): void
{
    $stmt = db()->prepare(
        'INSERT INTO sfp_semester_fees
           (package_id, semester_number, tuition_fee, scholarship_discount_pct, scholarship_amount, tuition_payable)
         VALUES (?, ?, ?, 0, 0, ?)'
    );
    for ($i = 1; $i <= $total_semesters; $i++) {
        $stmt->execute([$package_id, $i, $tuition_per_semester, $tuition_per_semester]);
    }
}

// ── Per-semester portion of fixed institutional fees ─────────────────────────

function sfp_semester_fixed_portion(array $pkg): float
{
    $months = (float)($pkg['total_months'] ?? 0);
    if ($months <= 0) return 0.0;
    return (float)$pkg['fixed_institutional_fees'] / $months * (float)$pkg['months_per_semester'];
}

// ── Per-semester portion of English course fee ───────────────────────────────

function sfp_semester_english_portion(array $pkg): float
{
    $months = (float)($pkg['total_months'] ?? 0);
    if ($months <= 0) return 0.0;
    return (float)$pkg['english_course_fee'] / $months * (float)$pkg['months_per_semester'];
}

// ── Individual scholarships for a single semester row ────────────────────────

function sfp_get_semester_scholarships(int $sf_id): array
{
    $stmt = db()->prepare(
        'SELECT ss.*, u.full_name AS created_by_name,
                stf.stored_name   AS doc_stored_name,
                stf.original_name AS doc_original_name
         FROM sfp_semester_scholarships ss
         LEFT JOIN users u        ON u.id   = ss.created_by
         LEFT JOIN student_files stf ON stf.id = ss.support_doc_id
         WHERE ss.sf_id = ?
         ORDER BY ss.created_at ASC'
    );
    $stmt->execute([$sf_id]);
    return $stmt->fetchAll();
}

// ── All individual scholarships for a package, keyed by sf_id ────────────────

function sfp_get_all_semester_scholarships(int $package_id): array
{
    $stmt = db()->prepare(
        'SELECT ss.*,
                stf.stored_name   AS doc_stored_name,
                stf.original_name AS doc_original_name
         FROM sfp_semester_scholarships ss
         JOIN sfp_semester_fees sf  ON sf.id   = ss.sf_id
         LEFT JOIN student_files stf ON stf.id = ss.support_doc_id
         WHERE sf.package_id = ?
         ORDER BY ss.sf_id, ss.created_at ASC'
    );
    $stmt->execute([$package_id]);
    $rows = $stmt->fetchAll();

    $map = [];
    foreach ($rows as $row) {
        $map[$row['sf_id']][] = $row;
    }
    return $map;
}

// ── Fetch active scholarship policies with their GPA tiers ───────────────
// Used by the Add Scholarship modal to allow quick-fill from a policy.

function sfp_get_active_sc_policies_with_tiers(): array
{
    $policies = db()->query(
        'SELECT id, name, type, description, applies_to_fixed, applies_to_english
         FROM sc_policies
         WHERE is_active = 1
         ORDER BY sort_order, name'
    )->fetchAll();

    if (empty($policies)) {
        return [];
    }

    $ids          = array_map('intval', array_column($policies, 'id'));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt         = db()->prepare(
        'SELECT id, policy_id, label, min_gpa, max_gpa, discount_percent
         FROM sc_tiers
         WHERE policy_id IN (' . $placeholders . ')
         ORDER BY policy_id, sort_order, min_gpa'
    );
    $stmt->execute($ids);
    $tiers = $stmt->fetchAll();

    $tiers_map = [];
    foreach ($tiers as $t) {
        $tiers_map[(int)$t['policy_id']][] = $t;
    }

    foreach ($policies as &$pol) {
        $pol['tiers'] = $tiers_map[(int)$pol['id']] ?? [];
    }
    unset($pol);

    return $policies;
}

// ── Re-aggregate scholarship totals into sfp_semester_fees ───────────────────
// Call after any insert / delete in sfp_semester_scholarships.
// Each scholarship is applied to the *remaining* balance after previous
// scholarships, not the original tuition fee (cascading / stacking).
// Scholarships with applies_to_fixed / applies_to_english also cascade
// against the per-semester fixed / English fee portions.

function sfp_recalculate_semester(int $sf_id, int $updated_by): void
{
    $db = db();

    // Fetch the current tuition_fee plus package fixed/English per-semester portions
    $sf_stmt = $db->prepare(
        'SELECT sf.tuition_fee,
                p.fixed_institutional_fees, p.english_course_fee,
                p.total_months, p.months_per_semester
         FROM sfp_semester_fees sf
         JOIN sfp_packages p ON p.id = sf.package_id
         WHERE sf.id = ?'
    );
    $sf_stmt->execute([$sf_id]);
    $sf = $sf_stmt->fetch();
    if (!$sf) return;

    $tuition_fee = (float)$sf['tuition_fee'];
    $months      = (float)$sf['total_months'];
    $mps         = (float)$sf['months_per_semester'];
    $fixed_per_sem   = ($months > 0) ? round((float)$sf['fixed_institutional_fees'] / $months * $mps, 2) : 0.0;
    $english_per_sem = ($months > 0) ? round((float)$sf['english_course_fee']        / $months * $mps, 2) : 0.0;

    // Fetch all scholarship rows ordered by creation date (oldest first)
    $rows_stmt = $db->prepare(
        'SELECT id, discount_pct, discount_type, fixed_amount, applies_to_fixed, applies_to_english
         FROM sfp_semester_scholarships
         WHERE sf_id = ?
         ORDER BY created_at ASC, id ASC'
    );
    $rows_stmt->execute([$sf_id]);
    $scholarships = $rows_stmt->fetchAll();

    // Cascading calculation: each discount applies to the running remaining balance.
    // Update each row's stored amount so the view shows correct per-scholarship amounts.
    $update_stmt  = $db->prepare('UPDATE sfp_semester_scholarships SET amount = ? WHERE id = ?');
    $running_bal  = $tuition_fee;
    $total_pct    = 0.0;
    $total_amount = 0.0;

    // Cascading for fixed and English fee portions
    $running_fixed   = $fixed_per_sem;
    $running_english = $english_per_sem;
    $total_fixed_discount   = 0.0;
    $total_english_discount = 0.0;

    foreach ($scholarships as $sc) {
        $pct  = (float)$sc['discount_pct'];
        $type = $sc['discount_type'] ?? 'percentage';

        // Tuition discount
        if ($type === 'fixed') {
            // Fixed-amount scholarship: use stored fixed_amount, capped at running balance.
            // discount_pct is stored as 0 for fixed-type; it is not included in total_pct
            // so percentage-based summaries remain accurate.
            $amount = min((float)($sc['fixed_amount'] ?? 0), $running_bal);
            $amount = round($amount, 2);
        } else {
            $amount = round($running_bal * $pct / 100, 2);
            $amount = min($amount, $running_bal);
            $total_pct += $pct;
        }

        $update_stmt->execute([$amount, $sc['id']]);
        $running_bal  -= $amount;
        $total_amount += $amount;

        // Fixed institutional fee and English course fee discounts only apply
        // to percentage-type scholarships with the respective scope flags set.
        if ($type === 'percentage') {
            if ((int)$sc['applies_to_fixed'] && $running_fixed > 0) {
                $fixed_amt = round($running_fixed * $pct / 100, 2);
                $fixed_amt = min($fixed_amt, $running_fixed);
                $running_fixed        -= $fixed_amt;
                $total_fixed_discount += $fixed_amt;
            }

            if ((int)$sc['applies_to_english'] && $running_english > 0) {
                $eng_amt = round($running_english * $pct / 100, 2);
                $eng_amt = min($eng_amt, $running_english);
                $running_english        -= $eng_amt;
                $total_english_discount += $eng_amt;
            }
        }
    }

    $tuition_payable = max(0.0, $running_bal);

    $db->prepare(
        'UPDATE sfp_semester_fees
         SET scholarship_discount_pct = ?,
             scholarship_amount       = ?,
             tuition_payable          = ?,
             fixed_discount_amount    = ?,
             english_discount_amount  = ?,
             updated_by               = ?,
             updated_at               = NOW()
         WHERE id = ?'
    )->execute([
        round($total_pct, 2),
        round($total_amount, 2),
        round($tuition_payable, 2),
        round($total_fixed_discount, 2),
        round($total_english_discount, 2),
        $updated_by,
        $sf_id,
    ]);
}

// ── Generate semester names based on semester type and starting semester ────
/**
 * Generates semester names based on semester type.
 * 
 * @param string $semester_type 'bi_semester' or 'trimester'
 * @param string $first_semester The first semester name (e.g., "Spring 2026")
 * @param int $total_semesters Total number of semesters
 * @return array Array of semester names
 */
function sfp_generate_semester_names(string $semester_type, string $first_semester, int $total_semesters): array
{
    $names = [];
    
    // Parse the first semester to get the starting season and year
    $parts = explode(' ', trim($first_semester));
    if (count($parts) < 2) {
        // Invalid format, return empty array
        return array_fill(0, $total_semesters, '');
    }
    
    $season = strtolower($parts[0]);
    $year = (int)$parts[1];
    
    // Define semester sequences
    if ($semester_type === 'trimester') {
        // Tri-semester: Spring, Summer, Fall
        $seasons = ['spring', 'summer', 'fall'];
    } else {
        // Bi-semester: Spring, Fall (Summer is skipped)
        $seasons = ['spring', 'fall'];
    }
    
    // Find the starting position in the sequence
    $season_index = array_search($season, $seasons);
    if ($season_index === false) {
        // Invalid season, return empty array
        return array_fill(0, $total_semesters, '');
    }
    
    // Generate semester names
    $current_year = $year;
    $current_season_index = $season_index;
    
    for ($i = 0; $i < $total_semesters; $i++) {
        $names[] = ucfirst($seasons[$current_season_index]) . ' ' . $current_year;
        
        // Move to next semester
        $current_season_index++;
        if ($current_season_index >= count($seasons)) {
            $current_season_index = 0;
            $current_year++;
        }
    }
    
    return $names;
}

// ── Get month name from month number ────────────────────────────────────────
/**
 * Returns the month name for a given month number.
 * 
 * @param int $month_num Month number (1-12)
 * @param int $start_month Starting month of the semester (1-12)
 * @return string Month name
 */
function sfp_get_month_name(int $month_num, int $start_month): string
{
    $months = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
    ];
    
    // Calculate actual month based on start month and month number
    $actual_month = (($start_month - 1 + $month_num - 1) % 12) + 1;
    
    return $months[$actual_month] ?? '';
}
