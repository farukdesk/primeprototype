<?php
/**
 * Shared helpers for the Course Offer module.
 */

require_once __DIR__ . '/../includes/auth.php';

// ── Permission helpers ────────────────────────────────────────────────────────

function co_is_staff(): bool
{
    return is_super_admin() || can_access('course-offer', 'can_edit');
}

function co_can_create(): bool
{
    return is_super_admin() || can_access('course-offer', 'can_create');
}

function co_can_delete(): bool
{
    return is_super_admin() || can_access('course-offer', 'can_delete');
}

// ── Cascade data helpers ──────────────────────────────────────────────────────

/**
 * All active departments ordered by name.
 */
function co_departments(): array
{
    return db()
        ->query("SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC")
        ->fetchAll();
}

/**
 * Active programs for a given department.
 */
function co_programs(int $dept_id): array
{
    $st = db()->prepare(
        "SELECT id, program_name
           FROM dept_academic_programs
          WHERE dept_id = ? AND is_active = 1
          ORDER BY sort_order ASC, program_name ASC"
    );
    $st->execute([$dept_id]);
    return $st->fetchAll();
}

/**
 * All active student batches ordered by sort_order, name.
 * (Replaces program-scoped intake lookup — student profiles use student_batches.)
 */
function co_student_batches(): array
{
    return db()
        ->query("SELECT id, name FROM student_batches WHERE is_active = 1 ORDER BY sort_order ASC, name ASC")
        ->fetchAll();
}

/**
 * Return the batch display label from a row that contains a `batch_name` key.
 */
function co_batch_label(array $batch): string
{
    return $batch['batch_name'] ?? '';
}

/**
 * Predefined semester options (current year ± 1, three seasons each).
 */
function co_semester_options(): array
{
    $year  = (int)date('Y');
    $opts  = [];
    foreach ([$year - 1, $year, $year + 1] as $y) {
        $opts[] = "Spring $y";
        $opts[] = "Summer $y";
        $opts[] = "Fall $y";
    }
    return $opts;
}

/**
 * Predefined academic intake options.
 */
function co_academic_intake_options(): array
{
    return [
        '1st Year 1st Semester',
        '1st Year 2nd Semester',
        '2nd Year 1st Semester',
        '2nd Year 2nd Semester',
        '3rd Year 1st Semester',
        '3rd Year 2nd Semester',
        '4th Year 1st Semester',
        '4th Year 2nd Semester',
        '5th Year 1st Semester',
        '5th Year 2nd Semester',
    ];
}

// ── Offer record helpers ──────────────────────────────────────────────────────

/**
 * Fetch a single offer row with all joined data.
 */
function co_get_offer(int $id): ?array
{
    $st = db()->prepare(
        "SELECT o.*,
                d.name          AS dept_name,
                p.program_name,
                b.name          AS batch_name,
                c.course_code,  c.course_name, c.credit, c.semester AS curriculum_semester,
                cd.name         AS subject_dept_name,
                cp.program_name AS subject_program_name
           FROM co_offers o
           JOIN dept_departments        d  ON d.id  = o.dept_id
           JOIN dept_academic_programs  p  ON p.id  = o.program_id
           JOIN student_batches         b  ON b.id  = o.batch_id
           JOIN course_curriculum        c  ON c.id = o.curriculum_id
           JOIN dept_academic_programs  cp ON cp.id = c.program_id
           JOIN dept_departments        cd ON cd.id = cp.dept_id
          WHERE o.id = ?
          LIMIT 1"
    );
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/**
 * Fetch assigned teachers for an offer.
 */
function co_get_teachers(int $offer_id): array
{
    $st = db()->prepare(
        "SELECT f.id, f.name, f.designation, d.name AS dept_name
           FROM co_offer_teachers t
           JOIN dept_faculty      f ON f.id = t.faculty_id
           JOIN dept_departments  d ON d.id = f.dept_id
          WHERE t.offer_id = ?
          ORDER BY t.sort_order ASC, f.name ASC"
    );
    $st->execute([$offer_id]);
    return $st->fetchAll();
}

/**
 * Save (replace) the teacher assignments for an offer.
 * Deletes existing rows then inserts new ones in one transaction.
 */
function co_save_teachers(int $offer_id, array $faculty_ids): void
{
    $pdo = db();
    $pdo->prepare("DELETE FROM co_offer_teachers WHERE offer_id = ?")->execute([$offer_id]);
    if (empty($faculty_ids)) return;
    $ins = $pdo->prepare(
        "INSERT INTO co_offer_teachers (offer_id, faculty_id, sort_order) VALUES (?, ?, ?)"
    );
    foreach (array_values($faculty_ids) as $i => $fid) {
        $ins->execute([$offer_id, (int)$fid, $i]);
    }
}

// ── Paginated listing ─────────────────────────────────────────────────────────

/**
 * Fetch filtered + paginated offers.
 *
 * Supported $filters keys: dept_id, program_id, batch_id, search, status
 */
function co_get_offers_filtered(array $filters = [], int $page = 1, int $per_page = 20): array
{
    $where  = ['1=1'];
    $params = [];

    if (!empty($filters['dept_id'])) {
        $where[]  = 'o.dept_id = ?';
        $params[] = (int)$filters['dept_id'];
    }
    if (!empty($filters['program_id'])) {
        $where[]  = 'o.program_id = ?';
        $params[] = (int)$filters['program_id'];
    }
    if (!empty($filters['batch_id'])) {
        $where[]  = 'o.batch_id = ?';
        $params[] = (int)$filters['batch_id'];
    }
    if (!empty($filters['semester'])) {
        $where[]  = 'o.semester = ?';
        $params[] = $filters['semester'];
    }
    if (!empty($filters['academic_intake'])) {
        $where[]  = 'o.academic_intake = ?';
        $params[] = $filters['academic_intake'];
    }
    if (!empty($filters['status'])) {
        $where[]  = 'o.status = ?';
        $params[] = $filters['status'];
    }
    $search = trim($filters['search'] ?? '');
    if ($search !== '') {
        $where[]  = '(c.course_code LIKE ? OR c.course_name LIKE ?)';
        $like     = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }

    $whereSQL = implode(' AND ', $where);

    $countSt = db()->prepare(
        "SELECT COUNT(*)
           FROM co_offers o
           JOIN course_curriculum c ON c.id = o.curriculum_id
          WHERE $whereSQL"
    );
    $countSt->execute($params);
    $total = (int)$countSt->fetchColumn();

    $limit_val  = (int)$per_page;
    $offset_val = (int)max(0, $page - 1) * $limit_val;
    $rowsSt = db()->prepare(
        "SELECT o.id, o.status, o.semester, o.academic_intake, o.created_at, o.batch_id,
                d.name          AS dept_name,
                p.program_name,
                b.name          AS batch_name,
                c.course_code,  c.course_name, c.credit,
                cd.name         AS subject_dept_name,
                cp.program_name AS subject_program_name
           FROM co_offers o
           JOIN dept_departments        d  ON d.id  = o.dept_id
           JOIN dept_academic_programs  p  ON p.id  = o.program_id
           JOIN student_batches         b  ON b.id  = o.batch_id
           JOIN course_curriculum        c  ON c.id = o.curriculum_id
           JOIN dept_academic_programs  cp ON cp.id = c.program_id
           JOIN dept_departments        cd ON cd.id = cp.dept_id
          WHERE $whereSQL
          ORDER BY b.sort_order ASC, b.name ASC, o.id ASC
          LIMIT {$limit_val} OFFSET {$offset_val}"
    );
    $rowsSt->execute($params);

    return ['rows' => $rowsSt->fetchAll(), 'total' => $total];
}
