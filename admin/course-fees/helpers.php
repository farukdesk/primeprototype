<?php
/**
 * Course Fees Calculator v4 – Shared Helpers
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../change-log/helpers.php';

// ── Permission helpers ────────────────────────────────────────────────────────
function cf_can_edit(): bool   { return is_super_admin() || can_access('course-fees', 'can_edit'); }
function cf_can_create(): bool { return is_super_admin() || can_access('course-fees', 'can_create'); }
function cf_can_delete(): bool { return is_super_admin() || can_access('course-fees', 'can_delete'); }

// ── Money format ──────────────────────────────────────────────────────────────
function cf_money(float $n): string {
    return number_format($n) . ' BDT';
}

// ── Global settings ───────────────────────────────────────────────────────────
function cf_get_settings(): array {
    static $s = null;
    if ($s === null) {
        $s = db()->query('SELECT * FROM cf_settings WHERE id=1')->fetch() ?: [];
    }
    return $s;
}

// ── Degree types ─────────────────────────────────────────────────────────────
function cf_get_degree_types(): array {
    return db()->query('SELECT * FROM cf_degree_types ORDER BY sort_order')->fetchAll();
}

// ── Single program by ID ──────────────────────────────────────────────────────
function cf_get_program(int $id): array|false {
    $stmt = db()->prepare(
        'SELECT p.*, dt.slug AS degree_type_slug, dt.name AS degree_type_name
         FROM cf_programs p
         JOIN cf_degree_types dt ON dt.id = p.degree_type_id
         WHERE p.id = ?'
    );
    $stmt->execute([$id]);
    return $stmt->fetch();
}

// ── Admission requirements for a program ─────────────────────────────────────
function cf_get_requirements(int $program_id): array {
    $stmt = db()->prepare(
        'SELECT * FROM cf_admission_requirements WHERE program_id=? ORDER BY sort_order, id'
    );
    $stmt->execute([$program_id]);
    return $stmt->fetchAll();
}

// ── Is a program a masters degree? ───────────────────────────────────────────
function cf_is_masters(array $prog): bool {
    return ($prog['degree_type_slug'] ?? '') === 'masters';
}

function cf_is_diploma(array $prog): bool {
    return ($prog['degree_type_slug'] ?? '') === 'bachelor-from-diploma';
}

// ── Category badge ────────────────────────────────────────────────────────────
function cf_type_badge(array $prog): string {
    $cls = match($prog['degree_type_slug'] ?? '') {
        'masters'               => 'bg-success',
        'bachelor-from-diploma' => 'bg-warning text-dark',
        default                 => 'bg-primary',
    };
    return '<span class="badge ' . $cls . '">' . h($prog['degree_type_name']) . '</span>';
}
