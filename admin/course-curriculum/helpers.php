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
 * Fetch all curriculum rows for a program + intake, keyed by semester.
 * Returns [ semester => [ rows… ], … ]
 */
function cc_get_curriculum(int $program_id, int $intake_id): array
{
    $st = db()->prepare(
        "SELECT c.*, df.name AS faculty_name
           FROM course_curriculum c
           LEFT JOIN dept_faculty df ON df.id = c.assigned_faculty_id
          WHERE c.program_id = ? AND c.intake_id = ?
          ORDER BY c.semester ASC, c.sort_order ASC, c.sl_no ASC, c.id ASC"
    );
    $st->execute([$program_id, $intake_id]);
    $rows = $st->fetchAll();

    $grouped = [];
    foreach ($rows as $row) {
        $grouped[(int)$row['semester']][] = $row;
    }
    return $grouped;
}

/**
 * Fetch all curriculum rows for a program (ignoring intake), keyed by semester.
 * Used by the redesigned index that no longer requires an intake selection.
 * Returns [ semester => [ rows… ], … ]
 */
function cc_get_curriculum_by_program(int $program_id): array
{
    $st = db()->prepare(
        "SELECT c.*, df.name AS faculty_name
           FROM course_curriculum c
           LEFT JOIN dept_faculty df ON df.id = c.assigned_faculty_id
          WHERE c.program_id = ?
          ORDER BY c.semester ASC, c.sort_order ASC, c.sl_no ASC, c.id ASC"
    );
    $st->execute([$program_id]);
    $rows = $st->fetchAll();

    $grouped = [];
    foreach ($rows as $row) {
        $grouped[(int)$row['semester']][] = $row;
    }
    return $grouped;
}

/**
 * Fetch all curriculum rows for a program as a flat list (no semester grouping).
 * Returns a plain array of rows ordered by sort_order, sl_no, id.
 */
function cc_get_subjects_flat(int $program_id): array
{
    $st = db()->prepare(
        "SELECT c.*, df.name AS faculty_name
           FROM course_curriculum c
           LEFT JOIN dept_faculty df ON df.id = c.assigned_faculty_id
          WHERE c.program_id = ?
          ORDER BY c.sort_order ASC, c.sl_no ASC, c.id ASC"
    );
    $st->execute([$program_id]);
    return $st->fetchAll();
}

// ─────────────────────────────────────────────────────────────────────────────
// Marking Distribution helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Fetch all marking distributions for a single curriculum subject.
 */
function cc_get_mark_distributions(int $curriculum_id): array
{
    $st = db()->prepare(
        "SELECT * FROM cc_mark_distributions
          WHERE curriculum_id = ?
          ORDER BY sort_order ASC, id ASC"
    );
    $st->execute([$curriculum_id]);
    return $st->fetchAll();
}

/**
 * Fetch all marking distributions for a list of curriculum IDs,
 * keyed by curriculum_id: [ curriculum_id => [ distribution, … ] ]
 */
function cc_get_all_mark_distributions(array $ids): array
{
    if (empty($ids)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare(
        "SELECT * FROM cc_mark_distributions
          WHERE curriculum_id IN ($placeholders)
          ORDER BY curriculum_id ASC, sort_order ASC, id ASC"
    );
    $st->execute(array_values($ids));
    $rows = $st->fetchAll();

    $grouped = [];
    foreach ($rows as $row) {
        $grouped[(int)$row['curriculum_id']][] = $row;
    }
    return $grouped;
}

/**
 * Fetch active faculty for a given department, ordered by name.
 * Includes only rows that have a linked user_id (real system users).
 * Returns rows with: id, name, designation, user_id
 */
function cc_get_dept_faculty(int $dept_id): array
{
    $st = db()->prepare(
        "SELECT id, name, designation
           FROM dept_faculty
          WHERE dept_id = ? AND is_active = 1
          ORDER BY sort_order ASC, name ASC"
    );
    $st->execute([$dept_id]);
    return $st->fetchAll();
}

// ─────────────────────────────────────────────────────────────────────────────
// Intake helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Fetch all intakes for a program, newest first.
 * Each row also carries a course count and total credits.
 */
function cc_get_intakes(int $program_id): array
{
    $st = db()->prepare(
        "SELECT i.*,
                COUNT(c.id)       AS course_count,
                SUM(c.credit)     AS total_credits
           FROM course_curriculum_intakes i
           LEFT JOIN course_curriculum c ON c.intake_id = i.id
          WHERE i.program_id = ?
          GROUP BY i.id
          ORDER BY i.intake_year DESC, i.created_at DESC"
    );
    $st->execute([$program_id]);
    return $st->fetchAll();
}

/**
 * Fetch a single intake row by ID.
 */
function cc_get_intake(int $intake_id): ?array
{
    $st = db()->prepare("SELECT * FROM course_curriculum_intakes WHERE id = ? LIMIT 1");
    $st->execute([$intake_id]);
    return $st->fetch() ?: null;
}

/**
 * Season options used in create / edit forms.
 */
function cc_intake_seasons(): array
{
    return ['Spring', 'Summer', 'Fall', 'Winter'];
}

// ─────────────────────────────────────────────────────────────────────────────
// Search / Filter / Pagination helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Fetch filtered + paginated subjects for a program.
 *
 * Supported $filters keys:
 *   search     (string)  – partial match on course_code or course_name
 *   semester   (int)     – exact semester (0 = all, -1 = unassigned)
 *   teacher_id (int)     – assigned_faculty_id (0 = all, -1 = unassigned/null)
 *
 * Returns ['rows' => [...], 'total' => int]
 */
function cc_get_subjects_filtered(int $program_id, array $filters = [], int $page = 1, int $per_page = 20): array
{
    $where  = ['c.program_id = ?'];
    $params = [$program_id];

    $search = trim($filters['search'] ?? '');
    if ($search !== '') {
        $where[]  = '(c.course_code LIKE ? OR c.course_name LIKE ?)';
        $like     = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }

    $semester = (int)($filters['semester'] ?? 0);
    if ($semester === -1) {
        $where[] = '(c.semester IS NULL OR c.semester = 0)';
    } elseif ($semester > 0) {
        $where[]  = 'c.semester = ?';
        $params[] = $semester;
    }

    $teacher_id = (int)($filters['teacher_id'] ?? 0);
    if ($teacher_id === -1) {
        $where[] = 'c.assigned_faculty_id IS NULL';
    } elseif ($teacher_id > 0) {
        $where[]  = 'c.assigned_faculty_id = ?';
        $params[] = $teacher_id;
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $where);

    // Count total matching rows and sum their credits
    $countSt = db()->prepare(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(c.credit), 0) AS creds
           FROM course_curriculum c $whereSQL"
    );
    $countSt->execute($params);
    $agg           = $countSt->fetch(PDO::FETCH_ASSOC);
    $total         = (int)$agg['cnt'];
    $total_credits = (float)$agg['creds'];

    // Fetch the requested page
    $offset = max(0, $page - 1) * $per_page;
    $limit  = (int)$per_page;
    $off    = (int)$offset;
    $sql    = "SELECT c.*, df.name AS faculty_name
                 FROM course_curriculum c
                 LEFT JOIN dept_faculty df ON df.id = c.assigned_faculty_id
                $whereSQL
                ORDER BY c.sort_order ASC, c.sl_no ASC, c.id ASC
                LIMIT {$limit} OFFSET {$off}";
    $rowsSt = db()->prepare($sql);
    $rowsSt->execute($params);

    return [
        'rows'         => $rowsSt->fetchAll(),
        'total'        => $total,
        'total_credits' => $total_credits,
    ];
}

/**
 * Teacher course-load report for a program.
 *
 * Returns rows ordered by course_count DESC:
 *   faculty_id, faculty_name, designation, course_count, total_credits
 *
 * A NULL faculty_id row represents subjects with no assigned teacher.
 */
function cc_teacher_report(int $program_id): array
{
    $st = db()->prepare(
        "SELECT df.id           AS faculty_id,
                df.name         AS faculty_name,
                df.designation,
                COUNT(c.id)                AS course_count,
                COALESCE(SUM(c.credit), 0) AS total_credits
           FROM course_curriculum c
           LEFT JOIN dept_faculty df ON df.id = c.assigned_faculty_id
          WHERE c.program_id = ?
          GROUP BY df.id, df.name, df.designation
          ORDER BY course_count DESC, df.name ASC"
    );
    $st->execute([$program_id]);
    return $st->fetchAll();
}
