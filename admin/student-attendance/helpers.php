<?php
/**
 * Shared helpers for the Student Attendance module.
 *
 * Student lists are pulled from Course Offer registrations (co_registrations).
 * Subjects come from Course Offer (co_offer_subjects) and faculty assignment
 * from co_offer_subject_teachers → dept_faculty (linked to users via user_id).
 */

require_once __DIR__ . '/../includes/auth.php';

/** Attendance status → short label used in the date-wise sheet. */
const SA_STATUSES = [
    'present' => 'P',
    'absent'  => 'A',
    'late'    => 'L',
    'excused' => 'E',
];

// ── Permission helpers ────────────────────────────────────────────────────────

function sa_is_staff(): bool
{
    return is_super_admin() || can_access('student-attendance', 'can_edit');
}

function sa_can_create(): bool
{
    return is_super_admin() || can_access('student-attendance', 'can_create');
}

function sa_can_delete(): bool
{
    return is_super_admin() || can_access('student-attendance', 'can_delete');
}

// ── Faculty helpers ───────────────────────────────────────────────────────────

/**
 * The dept_faculty profile linked to the current user (null when the user has
 * no faculty profile). Used for the default department filter and to restrict
 * the subject list to the subjects the faculty member is assigned to teach.
 */
function sa_current_faculty(): ?array
{
    $user = auth_user();
    if (!$user) return null;
    static $cache = '__unset__';
    if ($cache !== '__unset__') return $cache;
    $st = db()->prepare(
        'SELECT id, dept_id, name FROM dept_faculty WHERE user_id = ? AND is_active = 1 LIMIT 1'
    );
    $st->execute([(int)$user['id']]);
    $cache = $st->fetch() ?: null;
    return $cache;
}

/** Whether the current user is assigned to teach the given offered subject. */
function sa_is_assigned_subject(int $offer_subject_id): bool
{
    $user = auth_user();
    if (!$user) return false;
    $st = db()->prepare(
        'SELECT 1
           FROM co_offer_subject_teachers t
           JOIN dept_faculty df ON df.id = t.faculty_id
          WHERE t.offer_subject_id = ? AND df.user_id = ?
          LIMIT 1'
    );
    $st->execute([$offer_subject_id, (int)$user['id']]);
    return (bool)$st->fetchColumn();
}

/** Whether the current user may view the sheet for / take attendance on a subject. */
function sa_can_manage_subject(int $offer_subject_id): bool
{
    return sa_is_staff() || sa_can_create() || sa_is_assigned_subject($offer_subject_id);
}

// ── Filter option helpers ─────────────────────────────────────────────────────

/** Active departments, restricted to the current user's department scope. */
function sa_departments(): array
{
    $rows = db()
        ->query('SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC')
        ->fetchAll();
    return array_values(array_filter($rows, fn($d) => can_access_dept((int)$d['id'])));
}

/** Active programs for a department. */
function sa_programs(int $dept_id): array
{
    $st = db()->prepare(
        'SELECT id, program_name
           FROM dept_academic_programs
          WHERE dept_id = ? AND is_active = 1
          ORDER BY sort_order ASC, program_name ASC'
    );
    $st->execute([$dept_id]);
    return $st->fetchAll();
}

/** Active student batches. */
function sa_batches(): array
{
    return db()
        ->query('SELECT id, name FROM student_batches WHERE is_active = 1 ORDER BY sort_order ASC, name ASC')
        ->fetchAll();
}

/** Distinct semesters that actually exist on course offers. */
function sa_semesters(): array
{
    return db()
        ->query("SELECT DISTINCT semester FROM co_offers WHERE semester <> '' ORDER BY semester ASC")
        ->fetchAll(PDO::FETCH_COLUMN);
}

/** Distinct academic intakes that actually exist on course offers. */
function sa_intakes(): array
{
    return db()
        ->query("SELECT DISTINCT academic_intake FROM co_offers WHERE academic_intake <> '' ORDER BY academic_intake ASC")
        ->fetchAll(PDO::FETCH_COLUMN);
}

// ── Subject listing ───────────────────────────────────────────────────────────

/**
 * Filtered + paginated offered subjects for the attendance dashboard.
 *
 * Supported $filters keys: dept_id, program_id, batch_id, semester,
 *                          academic_intake, search (course code/name).
 *
 * Staff (can_edit) and super admins see every subject within their department
 * scope. Everyone else (faculty) only sees the subjects they are assigned to
 * teach via co_offer_subject_teachers.
 *
 * Returns ['rows' => [...], 'total' => int].
 */
function sa_subjects_filtered(array $filters = [], int $page = 1, int $per_page = 20): array
{
    $where  = ['1=1'];
    $params = [];

    foreach (['dept_id' => 'o.dept_id', 'program_id' => 'o.program_id', 'batch_id' => 'o.batch_id'] as $key => $col) {
        if (!empty($filters[$key])) {
            $where[]  = "$col = ?";
            $params[] = (int)$filters[$key];
        }
    }
    if (!empty($filters['semester'])) {
        $where[]  = 'o.semester = ?';
        $params[] = $filters['semester'];
    }
    if (!empty($filters['academic_intake'])) {
        $where[]  = 'o.academic_intake = ?';
        $params[] = $filters['academic_intake'];
    }

    $search = trim($filters['search'] ?? '');
    if ($search !== '') {
        $where[]  = '(c.course_code LIKE ? OR c.course_name LIKE ?)';
        $like     = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }

    // Department scope (same behaviour as the Course Offer module).
    $scope = get_dept_scope();
    if ($scope !== null) {
        if (empty($scope)) {
            return ['rows' => [], 'total' => 0];
        }
        $ph      = implode(',', array_fill(0, count($scope), '?'));
        $where[] = "o.dept_id IN ($ph)";
        foreach ($scope as $sid) {
            $params[] = (int)$sid;
        }
    }

    // Faculty members (non-staff) only see subjects assigned to them.
    if (!sa_is_staff()) {
        $user     = auth_user();
        $where[]  = 'cos.id IN (SELECT t.offer_subject_id\n                                  FROM co_offer_subject_teachers t\n                                  JOIN dept_faculty df ON df.id = t.faculty_id\n                                 WHERE df.user_id = ?)';
        $params[] = (int)($user['id'] ?? 0);
    }

    $whereSQL = implode(' AND ', $where);
    $base = "FROM co_offer_subjects cos
             JOIN co_offers                 o ON o.id = cos.offer_id
             JOIN course_curriculum         c ON c.id = cos.curriculum_id
             JOIN dept_departments          d ON d.id = o.dept_id
             JOIN dept_academic_programs    p ON p.id = o.program_id
             JOIN student_batches           b ON b.id = o.batch_id
            WHERE $whereSQL";

    $countSt = db()->prepare("SELECT COUNT(*) $base");
    $countSt->execute($params);
    $total = (int)$countSt->fetchColumn();

    $limit_val  = max(1, min(100, (int)$per_page));
    $offset_val = (int)max(0, $page - 1) * $limit_val;

    $rowsSt = db()->prepare(
        "SELECT cos.id, cos.offer_id,
                c.course_code, c.course_name, c.credit,
                o.dept_id, o.semester, o.academic_intake, o.shift, o.section,
                d.name AS dept_name, p.program_name, b.name AS batch_name,
                (SELECT COUNT(*) FROM co_registrations r WHERE r.offer_subject_id = cos.id) AS student_count,
                (SELECT COUNT(*) FROM att_sessions s WHERE s.offer_subject_id = cos.id)     AS session_count
           $base
          ORDER BY b.sort_order ASC, b.name ASC, c.course_code ASC, cos.id ASC
          LIMIT {$limit_val} OFFSET {$offset_val}"
    );
    $rowsSt->execute($params);
    $rows = $rowsSt->fetchAll();

    // Attach teacher names in a single query.
    if (!empty($rows)) {
        $ids = array_column($rows, 'id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $tst = db()->prepare(
            "SELECT t.offer_subject_id, f.name
               FROM co_offer_subject_teachers t
               JOIN dept_faculty f ON f.id = t.faculty_id
              WHERE t.offer_subject_id IN ($ph)
              ORDER BY t.sort_order ASC, f.name ASC"
        );
        $tst->execute($ids);
        $tmap = [];
        foreach ($tst->fetchAll() as $tr) {
            $tmap[(int)$tr['offer_subject_id']][] = $tr['name'];
        }
        foreach ($rows as &$row) {
            $row['teachers'] = $tmap[(int)$row['id']] ?? [];
        }
        unset($row);
    }

    return ['rows' => $rows, 'total' => $total];
}

/** Single offered subject with joined offer/course data (null when missing). */
function sa_subject(int $offer_subject_id): ?array
{
    $st = db()->prepare(
        'SELECT cos.id, cos.offer_id,
                c.course_code, c.course_name, c.credit,
                o.dept_id, o.program_id, o.batch_id, o.semester, o.academic_intake, o.shift, o.section,
                d.name AS dept_name, p.program_name, b.name AS batch_name
           FROM co_offer_subjects cos
           JOIN co_offers                 o ON o.id = cos.offer_id
           JOIN course_curriculum         c ON c.id = cos.curriculum_id
           JOIN dept_departments          d ON d.id = o.dept_id
           JOIN dept_academic_programs    p ON p.id = o.program_id
           JOIN student_batches           b ON b.id = o.batch_id
          WHERE cos.id = ?
          LIMIT 1'
    );
    $st->execute([$offer_subject_id]);
    return $st->fetch() ?: null;
}

// ── Students & sessions ───────────────────────────────────────────────────────

/**
 * Registered students of an offered subject (pulled from Course Offer
 * registrations), ordered by student ID.
 */
function sa_students(int $offer_subject_id): array
{
    $st = db()->prepare(
        'SELECT s.id AS student_pk, s.student_id, s.full_name, s.section, s.shift
           FROM co_registrations r
           JOIN students s ON s.id = r.student_id
          WHERE r.offer_subject_id = ?
          ORDER BY s.student_id ASC'
    );
    $st->execute([$offer_subject_id]);
    return $st->fetchAll();
}

/** Attendance sessions (dates) of a subject, oldest first. */
function sa_sessions(int $offer_subject_id): array
{
    $st = db()->prepare(
        'SELECT id, class_date, taken_by
           FROM att_sessions
          WHERE offer_subject_id = ?
          ORDER BY class_date ASC'
    );
    $st->execute([$offer_subject_id]);
    return $st->fetchAll();
}

/**
 * Attendance matrix for a subject:
 * [student_id (PK)][class_date (Y-m-d)] => status.
 */
function sa_matrix(int $offer_subject_id): array
{
    $st = db()->prepare(
        'SELECT ar.student_id, ar.status, s.class_date
           FROM att_records  ar
           JOIN att_sessions s ON s.id = ar.session_id
          WHERE s.offer_subject_id = ?'
    );
    $st->execute([$offer_subject_id]);
    $matrix = [];
    foreach ($st->fetchAll() as $row) {
        $matrix[(int)$row['student_id']][$row['class_date']] = $row['status'];
    }
    return $matrix;
}

/** Existing statuses for one date: [student_id] => status (empty when no session). */
function sa_statuses_for_date(int $offer_subject_id, string $date): array
{
    $st = db()->prepare(
        'SELECT ar.student_id, ar.status
           FROM att_records  ar
           JOIN att_sessions s ON s.id = ar.session_id
          WHERE s.offer_subject_id = ? AND s.class_date = ?'
    );
    $st->execute([$offer_subject_id, $date]);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $out[(int)$row['student_id']] = $row['status'];
    }
    return $out;
}

/**
 * Save (upsert) an attendance session and replace its records.
 * $statuses is [student_pk => status]; only students registered on the subject
 * and valid statuses are stored. Wrapped in a transaction.
 */
function sa_save_attendance(int $offer_subject_id, string $date, array $statuses, int $taken_by): void
{
    $registered = array_map(
        fn($s) => (int)$s['student_pk'],
        sa_students($offer_subject_id)
    );
    $registered = array_flip($registered);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO att_sessions (offer_subject_id, class_date, taken_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE taken_by = VALUES(taken_by)'
        )->execute([$offer_subject_id, $date, $taken_by]);

        $sid = $pdo->prepare('SELECT id FROM att_sessions WHERE offer_subject_id = ? AND class_date = ?');
        $sid->execute([$offer_subject_id, $date]);
        $session_id = (int)$sid->fetchColumn();

        $pdo->prepare('DELETE FROM att_records WHERE session_id = ?')->execute([$session_id]);

        $ins = $pdo->prepare(
            'INSERT INTO att_records (session_id, student_id, status) VALUES (?, ?, ?)'
        );
        foreach ($statuses as $student_pk => $status) {
            $student_pk = (int)$student_pk;
            if (!isset($registered[$student_pk]) || !isset(SA_STATUSES[$status])) {
                continue;
            }
            $ins->execute([$session_id, $student_pk, $status]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Delete one attendance session (and its records via cascade). */
function sa_delete_session(int $offer_subject_id, string $date): bool
{
    $st = db()->prepare('DELETE FROM att_sessions WHERE offer_subject_id = ? AND class_date = ?');
    $st->execute([$offer_subject_id, $date]);
    return $st->rowCount() > 0;
}

/** Validate a Y-m-d date string. */
function sa_valid_date(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}
