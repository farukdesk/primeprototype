<?php
/**
 * Student Fee Package – Shared Helpers
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../change-log/helpers.php';

// ── Permission helpers ────────────────────────────────────────────────────────

function sfp_can_edit(): bool
{
    return is_super_admin() || can_access('student-fee-package', 'can_edit');
}

function sfp_can_create(): bool
{
    return is_super_admin() || can_access('student-fee-package', 'can_create');
}

function sfp_can_delete(): bool
{
    return is_super_admin() || can_access('student-fee-package', 'can_delete');
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
