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
        "SELECT t.offer_subject_id, f.id, f.name, f.designation, fd.name AS dept_name
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
 * Runs inside a transaction; any existing co_offer_subjects (and their
 * cascaded co_offer_subject_teachers) are replaced.
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
