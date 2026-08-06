<?php
/**
 * Staff API – Student Attendance (Faculty)
 * =========================================
 * Faculty members take date-wise class attendance for the course-offer
 * subjects they are assigned to teach (co_offer_subject_teachers).
 *
 *   GET  ?action=subjects                    – assigned subjects + default department
 *   GET  ?action=students&subject_id=&date=  – roster + saved statuses for the date
 *   POST subject_id, class_date, statuses    – save (statuses = JSON {student_pk: status})
 *
 * Tables: student_att_sessions / student_att_records (admin/student-attendance.sql).
 */

require_once __DIR__ . '/includes/auth_staff_api.php';

$ctx = staff_api_auth();
$uid = (int)$ctx['user']['user_id'];

// ── Faculty profile (required – assignments hang off dept_faculty) ──────────
$faculty = null;
try {
    $st = db()->prepare(
        'SELECT df.id, df.dept_id, d.name AS dept_name
           FROM dept_faculty df
      LEFT JOIN dept_departments d ON d.id = df.dept_id
          WHERE df.user_id = ? AND df.is_active = 1
          ORDER BY df.id ASC
          LIMIT 1'
    );
    $st->execute([$uid]);
    $faculty = $st->fetch() ?: null;
} catch (Throwable $e) {
    $faculty = null;
}
if (!$faculty) {
    api_error(403, 'Student Attendance is available to faculty members only. No active faculty profile is linked to your account.', ['not_faculty' => true]);
}
$faculty_id = (int)$faculty['id'];

$valid_statuses = ['present', 'absent', 'late', 'excused'];

/** Whether the offered subject is assigned to this faculty member. */
function sa_api_assigned(int $subject_id, int $faculty_id): bool
{
    $st = db()->prepare(
        'SELECT 1 FROM co_offer_subject_teachers WHERE offer_subject_id = ? AND faculty_id = ? LIMIT 1'
    );
    $st->execute([$subject_id, $faculty_id]);
    return (bool)$st->fetchColumn();
}

/** Registered students of an offered subject (Course Offer registrations). */
function sa_api_students(int $subject_id): array
{
    $st = db()->prepare(
        'SELECT s.id, s.student_id, s.full_name, s.section
           FROM co_registrations r
           JOIN students s ON s.id = r.student_id
          WHERE r.offer_subject_id = ?
          ORDER BY s.student_id ASC'
    );
    $st->execute([$subject_id]);
    return array_map(static fn($r) => [
        'id'         => (int)$r['id'],
        'student_id' => (string)$r['student_id'],
        'full_name'  => (string)$r['full_name'],
        'section'    => (string)($r['section'] ?? ''),
    ], $st->fetchAll());
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = (string)($_GET['action'] ?? 'subjects');

    if ($action === 'students') {
        $subject_id = (int)($_GET['subject_id'] ?? 0);
        $date       = (string)($_GET['date'] ?? date('Y-m-d'));
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            $date = date('Y-m-d');
        }

        if ($subject_id <= 0 || !sa_api_assigned($subject_id, $faculty_id)) {
            api_error(404, 'Subject not found or you are not assigned to teach it.');
        }

        $statuses    = [];
        $has_session = false;
        try {
            $st = db()->prepare(
                'SELECT ar.student_id, ar.status
                   FROM student_att_records  ar
                   JOIN student_att_sessions s ON s.id = ar.session_id
                  WHERE s.offer_subject_id = ? AND s.class_date = ?'
            );
            $st->execute([$subject_id, $date]);
            foreach ($st->fetchAll() as $row) {
                $statuses[(string)(int)$row['student_id']] = (string)$row['status'];
            }
            $has_session = !empty($statuses);
        } catch (Throwable $e) {
            // Attendance tables not installed yet – the roster still loads.
        }

        api_ok([
            'date'        => $date,
            'students'    => sa_api_students($subject_id),
            'statuses'    => (object)$statuses,
            'has_session' => $has_session,
        ]);
        exit;
    }

    // action=subjects (default): every offered subject assigned to this faculty.
    $st = db()->prepare(
        'SELECT cos.id, c.course_code, c.course_name, c.credit,
                o.dept_id, o.program_id, o.batch_id, o.semester, o.academic_intake,
                o.section, o.shift,
                d.name AS dept_name, p.program_name, b.name AS batch_name,
                (SELECT COUNT(*) FROM co_registrations r WHERE r.offer_subject_id = cos.id) AS student_count
           FROM co_offer_subject_teachers t
           JOIN co_offer_subjects cos      ON cos.id = t.offer_subject_id
           JOIN co_offers o                ON o.id = cos.offer_id
           JOIN course_curriculum c        ON c.id = cos.curriculum_id
           JOIN dept_departments d         ON d.id = o.dept_id
           JOIN dept_academic_programs p   ON p.id = o.program_id
           JOIN student_batches b          ON b.id = o.batch_id
          WHERE t.faculty_id = ?
          ORDER BY d.name ASC, b.sort_order ASC, b.name ASC, c.course_code ASC'
    );
    $st->execute([$faculty_id]);
    $subjects = $st->fetchAll();

    // Classes taken per subject (tolerate missing attendance tables).
    $session_counts = [];
    try {
        $rows = db()->query(
            'SELECT offer_subject_id, COUNT(*) AS n FROM student_att_sessions GROUP BY offer_subject_id'
        )->fetchAll();
        foreach ($rows as $row) {
            $session_counts[(int)$row['offer_subject_id']] = (int)$row['n'];
        }
    } catch (Throwable $e) {
        $session_counts = [];
    }

    api_ok([
        'faculty'  => [
            'dept_id'   => (int)$faculty['dept_id'],
            'dept_name' => (string)($faculty['dept_name'] ?? ''),
        ],
        'subjects' => array_map(static function ($s) use ($session_counts) {
            return [
                'id'              => (int)$s['id'],
                'course_code'     => (string)$s['course_code'],
                'course_name'     => (string)$s['course_name'],
                'credit'          => (string)($s['credit'] ?? ''),
                'dept_id'         => (int)$s['dept_id'],
                'dept_name'       => (string)$s['dept_name'],
                'program_id'      => (int)$s['program_id'],
                'program_name'    => (string)$s['program_name'],
                'batch_id'        => (int)$s['batch_id'],
                'batch_name'      => (string)$s['batch_name'],
                'semester'        => (string)($s['semester'] ?? ''),
                'academic_intake' => (string)($s['academic_intake'] ?? ''),
                'section'         => (string)($s['section'] ?? ''),
                'shift'           => (string)($s['shift'] ?? ''),
                'student_count'   => (int)$s['student_count'],
                'session_count'   => $session_counts[(int)$s['id']] ?? 0,
            ];
        }, $subjects),
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    $date       = (string)($_POST['class_date'] ?? '');
    $raw        = (string)($_POST['statuses'] ?? '');

    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) {
        api_error(422, 'Invalid class date. Use YYYY-MM-DD.');
    }
    if ($subject_id <= 0 || !sa_api_assigned($subject_id, $faculty_id)) {
        api_error(404, 'Subject not found or you are not assigned to teach it.');
    }

    $statuses = json_decode($raw, true);
    if (!is_array($statuses) || empty($statuses)) {
        api_error(422, 'Nothing to save – no students marked.');
    }

    $registered = array_flip(array_map(static fn($s) => $s['id'], sa_api_students($subject_id)));

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $pdo->prepare(
            'INSERT INTO student_att_sessions (offer_subject_id, class_date, taken_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE taken_by = VALUES(taken_by)'
        )->execute([$subject_id, $date, $uid]);

        $sid = $pdo->prepare(
            'SELECT id FROM student_att_sessions WHERE offer_subject_id = ? AND class_date = ?'
        );
        $sid->execute([$subject_id, $date]);
        $session_id = (int)$sid->fetchColumn();

        $pdo->prepare('DELETE FROM student_att_records WHERE session_id = ?')->execute([$session_id]);

        $ins = $pdo->prepare(
            'INSERT INTO student_att_records (session_id, student_id, status) VALUES (?, ?, ?)'
        );
        $saved = 0;
        foreach ($statuses as $student_pk => $status) {
            $student_pk = (int)$student_pk;
            if (!isset($registered[$student_pk]) || !in_array($status, $valid_statuses, true)) {
                continue;
            }
            $ins->execute([$session_id, $student_pk, $status]);
            $saved++;
        }

        $pdo->commit();
        api_ok(['message' => 'Attendance saved.', 'saved' => $saved, 'class_date' => $date]);
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('api student-attendance: save failed – ' . $e->getMessage());
        api_error(500, 'Could not save attendance. Make sure the attendance tables are installed (admin/student-attendance.sql).');
    }
}

api_error(405, 'Method Not Allowed.');
