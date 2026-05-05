<?php
/**
 * Student Accounts – Shared Helpers
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../change-log/helpers.php';

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
                p.standard_tuition_full, p.tuition_per_semester,
                p.admission_fees, p.fixed_institutional_fees, p.english_course_fee,
                p.safety_net_cap, p.safety_net_per_semester,
                p.attendance_requirement, p.safety_net_gpa_threshold,
                dt.name AS degree_type_name, dt.slug AS degree_type_slug
         FROM cf_programs p
         JOIN cf_degree_types dt ON dt.id = p.degree_type_id
         WHERE p.is_active = 1
           AND p.degree_type_id != (SELECT id FROM cf_degree_types WHERE slug = \'masters\' LIMIT 1)
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
        'SELECT ss.*, u.full_name AS created_by_name
         FROM sfp_semester_scholarships ss
         LEFT JOIN users u ON u.id = ss.created_by
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
        'SELECT ss.*
         FROM sfp_semester_scholarships ss
         JOIN sfp_semester_fees sf ON sf.id = ss.sf_id
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
        'SELECT id, name, type, description
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

function sfp_recalculate_semester(int $sf_id, int $updated_by): void
{
    $db = db();

    // Fetch the current tuition_fee for this semester
    $sf_stmt = $db->prepare('SELECT tuition_fee FROM sfp_semester_fees WHERE id = ?');
    $sf_stmt->execute([$sf_id]);
    $sf = $sf_stmt->fetch();
    if (!$sf) return;

    $tuition_fee = (float)$sf['tuition_fee'];

    // Fetch all scholarship rows ordered by creation date (oldest first)
    $rows_stmt = $db->prepare(
        'SELECT id, discount_pct
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

    foreach ($scholarships as $sc) {
        $pct    = (float)$sc['discount_pct'];
        $amount = round($running_bal * $pct / 100, 2);
        $amount = min($amount, $running_bal); // never exceed remaining balance
        $update_stmt->execute([$amount, $sc['id']]);
        $running_bal  -= $amount;
        $total_amount += $amount;
        $total_pct    += $pct;
    }

    $tuition_payable = max(0.0, $running_bal);

    $db->prepare(
        'UPDATE sfp_semester_fees
         SET scholarship_discount_pct = ?,
             scholarship_amount       = ?,
             tuition_payable          = ?,
             updated_by               = ?,
             updated_at               = NOW()
         WHERE id = ?'
    )->execute([
        round($total_pct, 2),
        round($total_amount, 2),
        round($tuition_payable, 2),
        $updated_by,
        $sf_id,
    ]);
}
