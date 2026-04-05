<?php
/**
 * Shared helpers for the Course Curriculum module.
 */

require_once __DIR__ . '/../includes/auth.php';

/**
 * Return an ordered array of the 12 semester labels.
 * Index is 1-based (key 1 … 12).
 */
function cc_semester_labels(): array
{
    return [
        1  => '1st Year 1st Semester',
        2  => '1st Year 2nd Semester',
        3  => '1st Year 3rd Semester',
        4  => '2nd Year 1st Semester',
        5  => '2nd Year 2nd Semester',
        6  => '2nd Year 3rd Semester',
        7  => '3rd Year 1st Semester',
        8  => '3rd Year 2nd Semester',
        9  => '3rd Year 3rd Semester',
        10 => '4th Year 1st Semester',
        11 => '4th Year 2nd Semester',
        12 => '4th Year 3rd Semester',
    ];
}

/**
 * Return the label for a single semester number (1–12).
 */
function cc_semester_label(int $n): string
{
    return cc_semester_labels()[$n] ?? "Semester $n";
}

/**
 * Check whether the current user may manage (create / edit / delete) curricula.
 */
function cc_is_staff(): bool
{
    return is_super_admin() || can_access('course-curriculum', 'can_edit');
}

/**
 * Fetch all active departments ordered by name.
 */
function cc_departments(): array
{
    return db()
        ->query("SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC")
        ->fetchAll();
}

/**
 * Fetch all active programs for a given department, ordered by sort_order then name.
 */
function cc_programs(int $dept_id): array
{
    $st = db()->prepare(
        "SELECT id, program_name FROM dept_academic_programs
          WHERE dept_id = ? AND is_active = 1
          ORDER BY sort_order ASC, program_name ASC"
    );
    $st->execute([$dept_id]);
    return $st->fetchAll();
}

/**
 * Fetch all curriculum rows for a program, keyed by semester.
 * Returns [ semester => [ rows… ], … ]
 */
function cc_get_curriculum(int $program_id): array
{
    $st = db()->prepare(
        "SELECT * FROM course_curriculum
          WHERE program_id = ?
          ORDER BY semester ASC, sort_order ASC, sl_no ASC, id ASC"
    );
    $st->execute([$program_id]);
    $rows = $st->fetchAll();

    $grouped = [];
    foreach ($rows as $row) {
        $grouped[(int)$row['semester']][] = $row;
    }
    return $grouped;
}
