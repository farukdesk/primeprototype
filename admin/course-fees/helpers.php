<?php
/**
 * Course Fees Calculator – Shared Helpers
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../change-log/helpers.php';

// ── Permission helpers ────────────────────────────────────────────────────────

function cf_can_edit(): bool
{
    return is_super_admin() || can_access('course-fees', 'can_edit');
}

function cf_can_create(): bool
{
    return is_super_admin() || can_access('course-fees', 'can_create');
}

function cf_can_delete(): bool
{
    return is_super_admin() || can_access('course-fees', 'can_delete');
}

// ── Degree type label ─────────────────────────────────────────────────────────

function cf_degree_label(string $type): string
{
    return match ($type) {
        'bachelor'    => 'Bachelor',
        'master'      => 'Master',
        'diploma'     => 'Diploma',
        'certificate' => 'Certificate',
        default       => ucfirst($type),
    };
}

function cf_degree_badge(string $type): string
{
    $cls = match ($type) {
        'bachelor'    => 'bg-primary',
        'master'      => 'bg-success',
        'diploma'     => 'bg-warning text-dark',
        'certificate' => 'bg-info text-dark',
        default       => 'bg-secondary',
    };
    return '<span class="badge ' . $cls . '">' . h(cf_degree_label($type)) . '</span>';
}

// ── Fee type label ────────────────────────────────────────────────────────────

function cf_fee_type_label(string $type): string
{
    return match ($type) {
        'one_time'     => 'One-Time',
        'per_semester' => 'Per Semester',
        'monthly'      => 'Monthly (÷ by program months)',
        default        => ucfirst($type),
    };
}

// ── Money format ──────────────────────────────────────────────────────────────

function cf_money(int $amount, string $currency = 'BDT'): string
{
    return $currency . ' ' . number_format($amount);
}

// ── Get global settings ───────────────────────────────────────────────────────

function cf_get_settings(): array
{
    static $s = null;
    if ($s === null) {
        $s = db()->query('SELECT * FROM cf_settings WHERE id = 1')->fetch() ?: [];
    }
    return $s;
}

// ── Get program with its dept / program name ──────────────────────────────────

function cf_get_program(int $id): array|false
{
    $stmt = db()->prepare(
        'SELECT p.*,
                d.name         AS dept_name,
                ap.program_name
         FROM cf_programs p
         LEFT JOIN dept_departments     d  ON d.id  = p.dept_id
         LEFT JOIN dept_academic_programs ap ON ap.id = p.program_id
         WHERE p.id = ?'
    );
    $stmt->execute([$id]);
    return $stmt->fetch();
}

// ── Get fixed fees for a program ──────────────────────────────────────────────

function cf_get_fixed_fees(int $cf_program_id): array
{
    $stmt = db()->prepare(
        'SELECT * FROM cf_fixed_fees WHERE cf_program_id = ? ORDER BY sort_order, id'
    );
    $stmt->execute([$cf_program_id]);
    return $stmt->fetchAll();
}

// ── Build a display label for a program row ───────────────────────────────────

function cf_program_label(array $row): string
{
    $parts = [];
    if (!empty($row['program_name'])) {
        $parts[] = $row['program_name'];
    } elseif (!empty($row['dept_name'])) {
        $parts[] = $row['dept_name'];
    } else {
        $parts[] = 'Programme #' . $row['id'];
    }
    $parts[] = '(' . cf_degree_label($row['degree_type']) . ')';
    return implode(' ', $parts);
}
