<?php
/**
 * Spring Result Module – Shared Helpers
 */

require_once __DIR__ . '/../includes/auth.php';

// ── Permission helpers ────────────────────────────────────────────────────────

function sr_can_view(): bool
{
    return is_super_admin() || can_access('spring-result', 'can_view');
}

function sr_can_create(): bool
{
    return is_super_admin() || can_access('spring-result', 'can_create');
}

function sr_can_edit(): bool
{
    return is_super_admin() || can_access('spring-result', 'can_edit');
}

function sr_can_delete(): bool
{
    return is_super_admin() || can_access('spring-result', 'can_delete');
}

// ── Grade validation ──────────────────────────────────────────────────────────

const SR_VALID_GRADES = ['A+','A','A-','B+','B','B-','C+','C','D','F','INCOM'];

function sr_valid_letter_grade(string $g): bool
{
    return in_array(strtoupper(trim($g)), SR_VALID_GRADES, true);
}

function sr_grade_point_from_letter(string $g): ?float
{
    return match (strtoupper(trim($g))) {
        'A+'    => 4.00,
        'A'     => 3.75,
        'A-'    => 3.50,
        'B+'    => 3.25,
        'B'     => 3.00,
        'B-'    => 2.75,
        'C+'    => 2.50,
        'C'     => 2.25,
        'D'     => 2.00,
        'F'     => 0.00,
        'INCOM' => null,
        default => null,
    };
}

// ── Status badge ──────────────────────────────────────────────────────────────

function sr_status_badge(int $is_published): string
{
    return $is_published
        ? '<span class="badge bg-success">Published</span>'
        : '<span class="badge bg-secondary">Draft</span>';
}

// ── Data fetchers ─────────────────────────────────────────────────────────────

function sr_get_result(int $id): array
{
    $stmt = db()->prepare('SELECT * FROM sr_results WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        flash_set('error', 'Result not found.');
        redirect(APP_URL . '/spring-result/index.php');
    }
    return $row;
}

function sr_entry_count(int $result_id): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM sr_result_entries WHERE result_id = ?');
    $stmt->execute([$result_id]);
    return (int)$stmt->fetchColumn();
}

function sr_get_entries(int $result_id): array
{
    $stmt = db()->prepare(
        'SELECT * FROM sr_result_entries
         WHERE result_id = ?
         ORDER BY student_id ASC, course_code ASC, id ASC'
    );
    $stmt->execute([$result_id]);
    return $stmt->fetchAll();
}

function sr_get_entry(int $id): array|false
{
    $stmt = db()->prepare('SELECT * FROM sr_result_entries WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function sr_semester_list(): array
{
    $list = [];
    $end_year = (int)date('Y') + 5;
    for ($y = 2015; $y <= $end_year; $y++) {
        $list[] = 'Spring ' . $y;
        $list[] = 'Summer ' . $y;
        $list[] = 'Fall '   . $y;
    }
    return $list;
}
