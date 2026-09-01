<?php
/**
 * Student Portal API – GET /api/student/course-offers.php
 * ========================================================
 * Returns the course offers targeted at the logged-in student's batch, the
 * subjects in each offer (with teachers), whether self-registration is open,
 * and whether the student is already registered for each subject.
 *
 * Success response:
 *   {
 *     "ok": true,
 *     "offers": [
 *       {
 *         "id": 1, "semester": "...", "academic_intake": "...",
 *         "registration_open": true,
 *         "dept_name": "...", "program_name": "...", "batch_name": "...",
 *         "subjects": [
 *           { "offer_subject_id": 10, "course_code": "...", "course_name": "...",
 *             "credit": "3.00", "registered": true,
 *             "teachers": [ { "name": "...", "designation": "..." } ] }
 *         ],
 *         "registered_count": 2, "total_subjects": 5
 *       }
 *     ]
 *   }
 */

require_once __DIR__ . '/includes/auth_student_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sp_api_error(405, 'Method Not Allowed. Use GET.');
}

$ctx      = sp_api_auth();
$student  = $ctx['student'];
$batch_id = (int)($student['batch_id'] ?? 0);
$sid      = (int)$student['student_db_id'];

if ($batch_id <= 0) {
    sp_api_ok(['offers' => [], 'message' => 'No batch is assigned to your profile yet.']);
    return;
}

// ── Offers for this batch ──────────────────────────────────────────────────
$st = db()->prepare(
    "SELECT o.id, o.semester, o.academic_intake, o.registration_open,
            d.name AS dept_name, p.program_name, b.name AS batch_name
       FROM co_offers o
       JOIN dept_departments       d ON d.id = o.dept_id
       JOIN dept_academic_programs p ON p.id = o.program_id
       JOIN student_batches        b ON b.id = o.batch_id
      WHERE o.batch_id = ? AND o.status = 'active'
      ORDER BY o.id DESC"
);
$st->execute([$batch_id]);
$offers = $st->fetchAll();

if (empty($offers)) {
    sp_api_ok(['offers' => []]);
    return;
}

$offer_ids = array_map(static fn($o) => (int)$o['id'], $offers);
$in        = implode(',', array_fill(0, count($offer_ids), '?'));

// ── Subjects for these offers ──────────────────────────────────────────────
$sst = db()->prepare(
    "SELECT cos.id AS offer_subject_id, cos.offer_id, cos.sort_order,
            c.course_code, c.course_name, c.credit
       FROM co_offer_subjects cos
       JOIN course_curriculum c ON c.id = cos.curriculum_id
      WHERE cos.offer_id IN ($in)
      ORDER BY cos.offer_id ASC, cos.sort_order ASC, cos.id ASC"
);
$sst->execute($offer_ids);
$subject_rows = $sst->fetchAll();

$subject_ids = array_map(static fn($r) => (int)$r['offer_subject_id'], $subject_rows);

// ── Teachers for these subjects ────────────────────────────────────────────
$teacher_map = [];
if (!empty($subject_ids)) {
    $tin = implode(',', array_fill(0, count($subject_ids), '?'));
    $tst = db()->prepare(
        "SELECT t.offer_subject_id, f.name, f.designation
           FROM co_offer_subject_teachers t
           JOIN dept_faculty f ON f.id = t.faculty_id
          WHERE t.offer_subject_id IN ($tin)
          ORDER BY t.sort_order ASC, f.name ASC"
    );
    $tst->execute($subject_ids);
    foreach ($tst->fetchAll() as $tr) {
        $teacher_map[(int)$tr['offer_subject_id']][] = [
            'name'        => $tr['name'],
            'designation' => $tr['designation'],
        ];
    }
}

// ── This student's registrations (with approval status when available) ──────
$has_status = false;
try {
    db()->query('SELECT status FROM co_registrations LIMIT 1');
    $has_status = true;
} catch (Throwable $e) {
    // approval column missing (run admin/course-offer-approval-v1.sql)
}

$registered = []; // offer_subject_id => 'pending' | 'approved'
if (!empty($subject_ids)) {
    $rin = implode(',', array_fill(0, count($subject_ids), '?'));
    $rst = db()->prepare(
        'SELECT offer_subject_id' . ($has_status ? ', status' : '') . " FROM co_registrations
          WHERE student_id = ? AND offer_subject_id IN ($rin)"
    );
    $rst->execute(array_merge([$sid], $subject_ids));
    foreach ($rst->fetchAll() as $rr) {
        $registered[(int)$rr['offer_subject_id']] =
            $has_status ? (string)(($rr['status'] ?? '') ?: 'approved') : 'approved';
    }
}

// ── Assemble ───────────────────────────────────────────────────────────────
$subjects_by_offer = [];
foreach ($subject_rows as $row) {
    $osid = (int)$row['offer_subject_id'];
    $subjects_by_offer[(int)$row['offer_id']][] = [
        'offer_subject_id' => $osid,
        'course_code'      => $row['course_code'],
        'course_name'      => $row['course_name'],
        'credit'           => $row['credit'],
        'registered'       => isset($registered[$osid]),
        'approval_status'  => $registered[$osid] ?? null,
        'teachers'         => $teacher_map[$osid] ?? [],
    ];
}

$out = [];
foreach ($offers as $o) {
    $oid  = (int)$o['id'];
    $subs    = $subjects_by_offer[$oid] ?? [];
    $reg     = 0;
    $pending = 0;
    foreach ($subs as $s) {
        if ($s['registered']) $reg++;
        if (($s['approval_status'] ?? null) === 'pending') $pending++;
    }

    $out[] = [
        'id'                => $oid,
        'semester'          => $o['semester'],
        'academic_intake'   => $o['academic_intake'],
        'registration_open' => (bool)(int)$o['registration_open'],
        'dept_name'         => $o['dept_name'],
        'program_name'      => $o['program_name'],
        'batch_name'        => $o['batch_name'],
        'subjects'          => $subs,
        'registered_count'  => $reg,
        'pending_count'     => $pending,
        'total_subjects'    => count($subs),
    ];
}

sp_api_ok(['offers' => $out]);
