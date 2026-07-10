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
 * All active departments ordered by name, restricted to the departments the
 * current user is scoped to (linked to their profile). Super admins and users
 * without a scope see all departments.
 */
function co_departments(): array
{
    $rows = db()
        ->query("SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC")
        ->fetchAll();

    return array_values(array_filter($rows, fn($d) => can_access_dept((int)$d['id'])));
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
 * Whether a department runs on a bi-semester (two semesters per year) calendar.
 * All departments are trimester (three semesters per year) except Law, which
 * runs on a bi-semester calendar.
 */
function co_dept_is_bi_semester(?string $dept_name): bool
{
    // Match "Law" as a whole word (e.g. "Department of Law", "Law & Justice")
    // so unrelated names containing the letters "law" (e.g. "Outlaw") are not
    // mistakenly treated as bi-semester.
    return $dept_name !== null && preg_match('/\blaw\b/i', $dept_name) === 1;
}

/**
 * Predefined academic intake options.
 *
 * Trimester departments (the default) have three semesters per year, e.g.
 * "1st Year 1st Semester", "1st Year 2nd Semester", "1st Year 3rd Semester".
 * Bi-semester departments (Law) have two semesters per year.
 */
function co_academic_intake_options(bool $bi_semester = false): array
{
    $years     = ['1st', '2nd', '3rd', '4th', '5th'];
    $semesters = $bi_semester
        ? ['1st Semester', '2nd Semester']
        : ['1st Semester', '2nd Semester', '3rd Semester'];

    $opts = [];
    foreach ($years as $y) {
        foreach ($semesters as $s) {
            $opts[] = "$y Year $s";
        }
    }
    return $opts;
}

// ── Offer record helpers ──────────────────────────────────────────────────────

/**
 * Fetch a single offer row with all joined data (no curriculum join — subjects
 * are stored in co_offer_subjects, not on the offer row itself).
 */
function co_get_offer(int $id): ?array
{
    $st = db()->prepare(
        "SELECT o.*,
                d.name AS dept_name,
                p.program_name,
                b.name AS batch_name
           FROM co_offers o
           JOIN dept_departments       d ON d.id = o.dept_id
           JOIN dept_academic_programs p ON p.id = o.program_id
           JOIN student_batches        b ON b.id = o.batch_id
          WHERE o.id = ?
          LIMIT 1"
    );
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/**
 * Fetch subjects with their teachers for a single offer.
 * Returns an array of:
 *   [id, offer_id, curriculum_id, sort_order, course_code, course_name,
 *    credit, program_name, dept_name,
 *    teachers => [[id, name, designation, dept_name], ...]]
 */
function co_get_subjects_with_teachers(int $offer_id): array
{
    $st = db()->prepare(
        "SELECT cos.id, cos.offer_id, cos.curriculum_id, cos.sort_order,
                c.course_code, c.course_name, c.credit,
                p.program_name, d.name AS dept_name
           FROM co_offer_subjects cos
           JOIN course_curriculum        c  ON c.id  = cos.curriculum_id
           JOIN dept_academic_programs   p  ON p.id  = c.program_id
           JOIN dept_departments         d  ON d.id  = p.dept_id
          WHERE cos.offer_id = ?
          ORDER BY cos.sort_order ASC, cos.id ASC"
    );
    $st->execute([$offer_id]);
    $subjects = $st->fetchAll();

    if (empty($subjects)) return [];

    // Load teachers for all subject rows in one query
    $sub_ids = array_column($subjects, 'id');
    $ph      = implode(',', array_fill(0, count($sub_ids), '?'));
    $tst     = db()->prepare(
        "SELECT t.offer_subject_id, f.id, f.name, f.designation, f.dept_id, fd.name AS dept_name
           FROM co_offer_subject_teachers t
           JOIN dept_faculty      f  ON f.id  = t.faculty_id
           JOIN dept_departments  fd ON fd.id = f.dept_id
          WHERE t.offer_subject_id IN ($ph)
          ORDER BY t.sort_order ASC, f.name ASC"
    );
    $tst->execute($sub_ids);
    $teacher_rows = $tst->fetchAll();

    // Index teachers by offer_subject_id
    $tmap = [];
    foreach ($teacher_rows as $tr) {
        $tmap[(int)$tr['offer_subject_id']][] = [
            'id'          => $tr['id'],
            'name'        => $tr['name'],
            'designation' => $tr['designation'],
            'dept_id'     => $tr['dept_id'],
            'dept_name'   => $tr['dept_name'],
        ];
    }

    foreach ($subjects as &$sub) {
        $sub['teachers'] = $tmap[(int)$sub['id']] ?? [];
    }
    unset($sub);

    return $subjects;
}

/**
 * Fetch subjects+teachers for multiple offers at once.
 * Returns an array keyed by offer_id, each value is an array of subject rows
 * (same shape as co_get_subjects_with_teachers).
 */
function co_get_subjects_map(array $offer_ids): array
{
    if (empty($offer_ids)) return [];

    $ph  = implode(',', array_fill(0, count($offer_ids), '?'));
    $st  = db()->prepare(
        "SELECT cos.id, cos.offer_id, cos.curriculum_id, cos.sort_order,
                c.course_code, c.course_name, c.credit,
                p.program_name, d.name AS dept_name
           FROM co_offer_subjects cos
           JOIN course_curriculum        c  ON c.id  = cos.curriculum_id
           JOIN dept_academic_programs   p  ON p.id  = c.program_id
           JOIN dept_departments         d  ON d.id  = p.dept_id
          WHERE cos.offer_id IN ($ph)
          ORDER BY cos.offer_id ASC, cos.sort_order ASC, cos.id ASC"
    );
    $st->execute($offer_ids);
    $subjects = $st->fetchAll();

    if (empty($subjects)) return [];

    // Load all teachers in one query
    $sub_ids = array_column($subjects, 'id');
    $tph     = implode(',', array_fill(0, count($sub_ids), '?'));
    $tst     = db()->prepare(
        "SELECT t.offer_subject_id, f.id, f.name, f.designation, fd.name AS dept_name
           FROM co_offer_subject_teachers t
           JOIN dept_faculty      f  ON f.id  = t.faculty_id
           JOIN dept_departments  fd ON fd.id = f.dept_id
          WHERE t.offer_subject_id IN ($tph)
          ORDER BY t.sort_order ASC, f.name ASC"
    );
    $tst->execute($sub_ids);
    $teacher_rows = $tst->fetchAll();

    $tmap = [];
    foreach ($teacher_rows as $tr) {
        $tmap[(int)$tr['offer_subject_id']][] = [
            'id'          => $tr['id'],
            'name'        => $tr['name'],
            'designation' => $tr['designation'],
            'dept_name'   => $tr['dept_name'],
        ];
    }

    $map = [];
    foreach ($subjects as $sub) {
        $sub['teachers'] = $tmap[(int)$sub['id']] ?? [];
        $map[(int)$sub['offer_id']][] = $sub;
    }

    return $map;
}

/**
 * Save (replace) the subject+teacher assignments for an offer.
 *
 * $rows is an array of:
 *   ['curriculum_id' => int, 'teacher_ids' => int[]]
 *
 * Any existing co_offer_subjects (and their cascaded co_offer_subject_teachers)
 * are deleted first, then the new rows are inserted. The caller is responsible
 * for wrapping this in a transaction when atomicity is required.
 */
function co_save_subjects_teachers(int $offer_id, array $rows): void
{
    $pdo = db();
    $pdo->prepare("DELETE FROM co_offer_subjects WHERE offer_id = ?")->execute([$offer_id]);

    if (empty($rows)) return;

    $insSub = $pdo->prepare(
        "INSERT INTO co_offer_subjects (offer_id, curriculum_id, sort_order) VALUES (?, ?, ?)"
    );
    $insTch = $pdo->prepare(
        "INSERT INTO co_offer_subject_teachers (offer_subject_id, faculty_id, sort_order) VALUES (?, ?, ?)"
    );

    foreach (array_values($rows) as $i => $row) {
        $cid = (int)($row['curriculum_id'] ?? 0);
        if ($cid <= 0) continue;

        $insSub->execute([$offer_id, $cid, $i]);
        $sub_id = (int)$pdo->lastInsertId();

        foreach (array_values((array)($row['teacher_ids'] ?? [])) as $j => $fid) {
            $fid = (int)$fid;
            if ($fid > 0) {
                $insTch->execute([$sub_id, $fid, $j]);
            }
        }
    }
}

// ── Registration helpers ──────────────────────────────────────────────────────

/**
 * Active students belonging to a batch (for manual enrollment search).
 * Optional $q filters by student_id or name.
 */
function co_batch_students(int $batch_id, string $q = '', int $limit = 50): array
{
    $params = [$batch_id];
    $where  = 's.batch_id = ?';
    if ($q !== '') {
        $where   .= ' AND (s.student_id LIKE ? OR s.full_name LIKE ?)';
        $like     = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
    }
    $limit = max(1, min(200, $limit));
    $st = db()->prepare(
        "SELECT s.id, s.student_id, s.full_name, s.section
           FROM students s
          WHERE $where
          ORDER BY s.student_id ASC
          LIMIT $limit"
    );
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Filtered + paginated students belonging to a batch (for bulk enrollment picker).
 *
 * Pass $batch_id <= 0 to search across every batch (used when an admin needs to
 * enrol a student who is continuing with a batch other than their own).
 *
 * Supported $filters keys: q (student_id/name), section, shift, dept_id, program_id.
 * Returns ['rows' => [...], 'total' => int] where each row contains
 * id, student_id, full_name, section, shift, batch_name, dept_name, program_name.
 */
function co_batch_students_filtered(int $batch_id, array $filters = [], int $page = 1, int $per_page = 25): array
{
    $where  = [];
    $params = [];
    if ($batch_id > 0) {
        $where[]  = 's.batch_id = ?';
        $params[] = $batch_id;
    }

    $dept_id = (int)($filters['dept_id'] ?? 0);
    if ($dept_id > 0) {
        $where[]  = 's.dept_id = ?';
        $params[] = $dept_id;
    }
    $program_id = (int)($filters['program_id'] ?? 0);
    if ($program_id > 0) {
        $where[]  = 's.program_id = ?';
        $params[] = $program_id;
    }

    $q = trim($filters['q'] ?? '');
    if ($q !== '') {
        $where[]  = '(s.student_id LIKE ? OR s.full_name LIKE ?)';
        $like     = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
    }
    $section = trim($filters['section'] ?? '');
    if ($section !== '') {
        $where[]  = 's.section = ?';
        $params[] = $section;
    }
    $shift = trim($filters['shift'] ?? '');
    if ($shift !== '') {
        $where[]  = 's.shift = ?';
        $params[] = $shift;
    }

    $whereSQL = $where ? implode(' AND ', $where) : '1=1';

    $countSt = db()->prepare("SELECT COUNT(*) FROM students s WHERE $whereSQL");
    $countSt->execute($params);
    $total = (int)$countSt->fetchColumn();

    $per_page   = max(1, min(200, $per_page));
    $limit_val  = (int)$per_page;
    $offset_val = (int)max(0, $page - 1) * $limit_val;

    $st = db()->prepare(
        "SELECT s.id, s.student_id, s.full_name, s.section, s.shift,
                d.name AS dept_name, p.program_name, b.name AS batch_name
           FROM students s
           LEFT JOIN dept_departments       d ON d.id = s.dept_id
           LEFT JOIN dept_academic_programs p ON p.id = s.program_id
           LEFT JOIN student_batches        b ON b.id = s.batch_id
          WHERE $whereSQL
          ORDER BY s.student_id ASC
          LIMIT {$limit_val} OFFSET {$offset_val}"
    );
    $st->execute($params);

    return ['rows' => $st->fetchAll(), 'total' => $total];
}

/**
 * Distinct non-empty sections present in a batch (for the enrollment filter).
 */
function co_batch_sections(int $batch_id): array
{
    $st = db()->prepare(
        "SELECT DISTINCT section
           FROM students
          WHERE batch_id = ? AND section IS NOT NULL AND section <> ''
          ORDER BY section ASC"
    );
    $st->execute([$batch_id]);
    return array_map('strval', array_column($st->fetchAll(), 'section'));
}

/**
 * Distinct non-empty shifts present in a batch (for the enrollment filter).
 */
function co_batch_shifts(int $batch_id): array
{
    $st = db()->prepare(
        "SELECT DISTINCT shift
           FROM students
          WHERE batch_id = ? AND shift IS NOT NULL AND shift <> ''
          ORDER BY shift ASC"
    );
    $st->execute([$batch_id]);
    return array_map('strval', array_column($st->fetchAll(), 'shift'));
}

/**
 * Registered students for every subject in an offer.
 * Returns array keyed by offer_subject_id → list of student rows.
 */
function co_registrations_by_subject(int $offer_id): array
{
    $st = db()->prepare(
        "SELECT r.id AS reg_id, r.offer_subject_id, r.source, r.created_at,
                s.id AS student_pk, s.student_id, s.full_name, s.section, s.shift,
                d.name AS dept_name, b.name AS batch_name
           FROM co_registrations   r
           JOIN co_offer_subjects  cos ON cos.id = r.offer_subject_id
           JOIN students           s   ON s.id  = r.student_id
           LEFT JOIN dept_departments d ON d.id = s.dept_id
           LEFT JOIN student_batches  b ON b.id = s.batch_id
          WHERE cos.offer_id = ?
          ORDER BY s.student_id ASC"
    );
    $st->execute([$offer_id]);

    $map = [];
    foreach ($st->fetchAll() as $row) {
        $map[(int)$row['offer_subject_id']][] = $row;
    }
    return $map;
}

/**
 * Register a student into a single offer subject.
 * Idempotent: existing rows are left untouched (INSERT IGNORE semantics).
 * Returns true when a new row was created.
 */
function co_register_student(int $offer_subject_id, int $student_id, string $source, ?int $by): bool
{
    $source = $source === 'admin' ? 'admin' : 'self';
    $st = db()->prepare(
        "INSERT IGNORE INTO co_registrations
             (offer_subject_id, student_id, source, registered_by)
         VALUES (?, ?, ?, ?)"
    );
    $st->execute([$offer_subject_id, $student_id, $source, $by]);
    return $st->rowCount() > 0;
}

/**
 * Remove a student's registration from a single offer subject.
 * Returns true when a row was removed.
 */
function co_unregister_student(int $offer_subject_id, int $student_id): bool
{
    $st = db()->prepare(
        "DELETE FROM co_registrations WHERE offer_subject_id = ? AND student_id = ?"
    );
    $st->execute([$offer_subject_id, $student_id]);
    return $st->rowCount() > 0;
}

/**
 * The offer subject IDs a student is registered for within a given offer.
 */
function co_student_registered_subject_ids(int $offer_id, int $student_id): array
{
    $st = db()->prepare(
        "SELECT r.offer_subject_id
           FROM co_registrations  r
           JOIN co_offer_subjects cos ON cos.id = r.offer_subject_id
          WHERE cos.offer_id = ? AND r.student_id = ?"
    );
    $st->execute([$offer_id, $student_id]);
    return array_map('intval', array_column($st->fetchAll(), 'offer_subject_id'));
}

// ── Paginated listing ─────────────────────────────────────────────────────────

/**
 * Fetch filtered + paginated offers.
 *
 * Supported $filters keys: dept_id, program_id, batch_id, semester,
 *                          academic_intake, status, search
 *
 * The 'search' filter matches against subjects inside the offer
 * (co_offer_subjects → course_curriculum).
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

    // Restrict to the departments the current user is scoped to (linked to
    // their profile). null = unrestricted; [] = no access to any department.
    $scope = get_dept_scope();
    if ($scope !== null) {
        if (empty($scope)) {
            return ['rows' => [], 'total' => 0];
        }
        $scope_ph = implode(',', array_fill(0, count($scope), '?'));
        $where[]  = "o.dept_id IN ($scope_ph)";
        foreach ($scope as $sid) {
            $params[] = (int)$sid;
        }
    }

    $search = trim($filters['search'] ?? '');
    $searchJoin = '';
    if ($search !== '') {
        $searchJoin = "JOIN co_offer_subjects _cos ON _cos.offer_id = o.id
                       JOIN course_curriculum  _cc  ON _cc.id = _cos.curriculum_id";
        $where[]  = '(_cc.course_code LIKE ? OR _cc.course_name LIKE ?)';
        $like     = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }

    $whereSQL = implode(' AND ', $where);

    $countSt = db()->prepare(
        "SELECT COUNT(DISTINCT o.id)
           FROM co_offers o
           $searchJoin
          WHERE $whereSQL"
    );
    $countSt->execute($params);
    $total = (int)$countSt->fetchColumn();

    $limit_val  = (int)$per_page;
    $offset_val = (int)max(0, $page - 1) * $limit_val;
    $rowsSt = db()->prepare(
        "SELECT DISTINCT o.id, o.dept_id, o.program_id, o.batch_id,
                o.status, o.semester, o.academic_intake, o.created_at,
                d.name AS dept_name,
                p.program_name,
                b.name AS batch_name
           FROM co_offers o
           JOIN dept_departments       d ON d.id = o.dept_id
           JOIN dept_academic_programs p ON p.id = o.program_id
           JOIN student_batches        b ON b.id = o.batch_id
           $searchJoin
          WHERE $whereSQL
          ORDER BY b.sort_order ASC, b.name ASC, o.id ASC
          LIMIT {$limit_val} OFFSET {$offset_val}"
    );
    $rowsSt->execute($params);

    return ['rows' => $rowsSt->fetchAll(), 'total' => $total];
}
