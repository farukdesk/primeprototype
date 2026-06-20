<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../change-log/helpers.php';

// ── Permission helpers ────────────────────────────────────────────────────────

function sc_can_edit(): bool
{
    return is_super_admin() || can_access('scholarship', 'can_edit');
}

function sc_can_create(): bool
{
    return is_super_admin() || can_access('scholarship', 'can_create');
}

function sc_can_delete(): bool
{
    return is_super_admin() || can_access('scholarship', 'can_delete');
}

// ── Settings ──────────────────────────────────────────────────────────────────

function sc_get_settings(): array
{
    static $s = null;
    if ($s === null) {
        $s = db()->query('SELECT * FROM sc_settings WHERE id = 1')->fetch() ?: [];
    }
    return $s;
}

// ── Policy helpers ────────────────────────────────────────────────────────────

function sc_get_policy(int $id): array|false
{
    $stmt = db()->prepare('SELECT * FROM sc_policies WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function sc_get_tiers(int $policy_id): array
{
    $stmt = db()->prepare('SELECT * FROM sc_tiers WHERE policy_id = ? ORDER BY sort_order, min_gpa');
    $stmt->execute([$policy_id]);
    return $stmt->fetchAll();
}

function sc_find_tier(float $gpa, array $tiers): array|false
{
    foreach ($tiers as $tier) {
        if ($gpa >= (float)$tier['min_gpa'] && $gpa <= (float)$tier['max_gpa']) {
            return $tier;
        }
    }
    return false;
}

// ── Badge helpers ─────────────────────────────────────────────────────────────

function sc_type_badge(string $type): string
{
    return match ($type) {
        'gpa_based'    => '<span class="badge bg-info text-dark">GPA-Based</span>',
        'merit_based'  => '<span class="badge bg-primary">Merit-Based</span>',
        'flat'         => '<span class="badge bg-success">Flat Discount</span>',
        default        => '<span class="badge bg-secondary">' . h($type) . '</span>',
    };
}

function sc_status_badge(string $status): string
{
    return match ($status) {
        'active'  => '<span class="badge bg-success">Active</span>',
        'revoked' => '<span class="badge bg-danger">Revoked</span>',
        default   => '<span class="badge bg-secondary">' . h($status) . '</span>',
    };
}

// ── Award with joins ──────────────────────────────────────────────────────────

function sc_get_award_student(int $award_id): array|false
{
    $stmt = db()->prepare(
        'SELECT a.*,
                s.full_name, s.student_id AS student_sid, s.admitted_semester, s.status AS student_status,
                p.name AS policy_name, p.type AS policy_type,
                t.label AS tier_label,
                aw.username AS awarded_by_name,
                rv.username AS revoked_by_name
         FROM sc_awards a
         JOIN students s       ON s.id  = a.student_id
         JOIN sc_policies p    ON p.id  = a.policy_id
         LEFT JOIN sc_tiers t  ON t.id  = a.tier_id
         LEFT JOIN users aw    ON aw.id = a.awarded_by
         LEFT JOIN users rv    ON rv.id = a.revoked_by
         WHERE a.id = ?'
    );
    $stmt->execute([$award_id]);
    return $stmt->fetch();
}
