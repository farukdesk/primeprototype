<?php
/**
 * Student Portal API – POST /api/student/course-register.php
 * ===========================================================
 * Lets a student self-register for (or drop) a subject of a course offer that
 * targets their batch, while self-registration is open.
 *
 * Body (form-urlencoded or JSON):
 *   offer_subject_id = <co_offer_subjects.id>   (required)
 *   action           = "register" | "drop"      (default "register")
 *
 * Success response:
 *   { "ok": true, "offer_subject_id": N, "registered": true|false }
 */

require_once __DIR__ . '/includes/auth_student_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sp_api_error(405, 'Method Not Allowed. Use POST.');
}

$ctx      = sp_api_auth();
$student  = $ctx['student'];
$batch_id = (int)($student['batch_id'] ?? 0);
$sid      = (int)$student['student_db_id'];
$my_shift   = trim((string)($student['shift'] ?? ''));
$my_section = trim((string)($student['section'] ?? ''));

// Shift/section targeting (admin/course-offer-shift-section.sql): students may
// only register for offers that match their shift/section (or that target the
// whole batch). Fails open when the columns are missing.
$offer_target_sql    = '';
$offer_target_params = [];
try {
    db()->query('SELECT shift, section FROM co_offers LIMIT 1');
    $offer_target_sql = " AND (o.shift IS NULL OR o.shift = '' OR o.shift = ?)
                          AND (o.section IS NULL OR o.section = '' OR o.section = ?)";
    $offer_target_params = [$my_shift, $my_section];
} catch (Throwable $e) {
}

// Accept JSON or form-encoded bodies.
$input = $_POST;
if (empty($input)) {
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $input = $decoded;
    }
}

$offer_subject_id = (int)($input['offer_subject_id'] ?? 0);
$offer_id         = (int)($input['offer_id'] ?? 0);
$action_in        = (string)($input['action'] ?? 'register');
$action           = in_array($action_in, ['drop', 'register_all'], true) ? $action_in : 'register';

if ($batch_id <= 0) {
    sp_api_error(403, 'No batch is assigned to your profile yet.');
}

// Approval workflow (admin/course-offer-approval-v1.sql): self-registrations
// are created as PENDING and must be approved by the department.
$has_status = false;
try {
    db()->query('SELECT status FROM co_registrations LIMIT 1');
    $has_status = true;
} catch (Throwable $e) {
}

// ── Dues check: registration is blocked when dues exceed 1,000 BDT ──────────
// Same "due as of today" figure as the Finances tab. Dropping a course stays
// allowed; the check fails open when the accounting module is unavailable.
if ($action !== 'drop') {
    $due_today = null;
    try {
        require_once dirname(__DIR__, 2) . '/accounting/helpers.php';
        $pkg_stmt = db()->prepare('SELECT id FROM sfp_packages WHERE student_id = ? LIMIT 1');
        $pkg_stmt->execute([$sid]);
        $pkg = $pkg_stmt->fetch();
        if ($pkg && function_exists('acc_outstanding_through_current_month')) {
            $d = acc_outstanding_through_current_month((int)$pkg['id']);
            if ($d !== null) {
                $due_today = round((float)$d, 2);
            }
        }
    } catch (Throwable $e) {
    }
    if ($due_today !== null && $due_today > 1000.0) {
        sp_api_error(
            403,
            'You cannot register while you have dues. Please clear your dues ('
                . number_format($due_today, 2) . ' BDT as of today) to register your courses.',
            ['dues_blocked' => true, 'dues_amount' => $due_today]
        );
    }
}

// ── Register ALL subjects of an offer at once (mobile app / web portal) ──────
if ($action === 'register_all') {
    if ($offer_id <= 0) {
        sp_api_error(422, 'A valid offer_id is required.');
    }
    $ost = db()->prepare(
        "SELECT o.registration_open FROM co_offers o
          WHERE o.id = ? AND o.batch_id = ? AND o.status = 'active'{$offer_target_sql}
          LIMIT 1"
    );
    $ost->execute(array_merge([$offer_id, $batch_id], $offer_target_params));
    $offer = $ost->fetch();
    if (!$offer) {
        sp_api_error(404, 'This course offer is not available for your batch.');
    }
    if ((int)$offer['registration_open'] !== 1) {
        sp_api_error(403, 'Registration is currently closed for this offer.');
    }

    $sst = db()->prepare('SELECT id FROM co_offer_subjects WHERE offer_id = ?');
    $sst->execute([$offer_id]);
    $all_subject_ids = array_map('intval', $sst->fetchAll(PDO::FETCH_COLUMN));
    if (empty($all_subject_ids)) {
        sp_api_error(422, 'This offer has no courses yet.');
    }

    // Students may select freely: register only the subjects they chose. When
    // no selection is sent, fall back to registering every offered subject
    // (keeps older clients working).
    $selected = array_map('intval', (array)($input['subject_ids'] ?? []));
    $selected = array_values(array_intersect($all_subject_ids, $selected));
    $target_subject_ids = empty($selected) && empty($input['subject_ids'])
        ? $all_subject_ids
        : $selected;
    if (empty($target_subject_ids)) {
        sp_api_error(422, 'Please select at least one course to register.');
    }

    $ins = $has_status
        ? db()->prepare(
            "INSERT IGNORE INTO co_registrations
                 (offer_subject_id, student_id, source, registered_by, status)
             VALUES (?, ?, 'self', NULL, 'pending')"
        )
        : db()->prepare(
            "INSERT IGNORE INTO co_registrations
                 (offer_subject_id, student_id, source, registered_by)
             VALUES (?, ?, 'self', NULL)"
        );
    $added = 0;
    foreach ($target_subject_ids as $osid) {
        $ins->execute([$osid, $sid]);
        $added += (int)$ins->rowCount();
    }

    sp_api_ok([
        'offer_id'   => $offer_id,
        'registered' => count($target_subject_ids),
        'new'        => $added,
        'status'     => $has_status ? 'pending' : 'approved',
        'message'    => $has_status
            ? 'Your registration has been submitted and is awaiting departmental approval.'
            : 'Your registration has been submitted.',
    ]);
    return;
}

if ($offer_subject_id <= 0) {
    sp_api_error(422, 'A valid offer_subject_id is required.');
}

// Verify the subject belongs to an active offer for THIS student's batch and
// that self-registration is currently open.
$st = db()->prepare(
    "SELECT o.registration_open
       FROM co_offer_subjects cos
       JOIN co_offers o ON o.id = cos.offer_id
      WHERE cos.id = ? AND o.batch_id = ? AND o.status = 'active'{$offer_target_sql}
      LIMIT 1"
);
$st->execute(array_merge([$offer_subject_id, $batch_id], $offer_target_params));
$row = $st->fetch();

if (!$row) {
    sp_api_error(404, 'This course is not available for your batch.');
}
if ((int)$row['registration_open'] !== 1) {
    sp_api_error(403, 'Registration is currently closed for this course.');
}

if ($action === 'drop') {
    $del = db()->prepare(
        "DELETE FROM co_registrations WHERE offer_subject_id = ? AND student_id = ?"
    );
    $del->execute([$offer_subject_id, $sid]);
    sp_api_ok(['offer_subject_id' => $offer_subject_id, 'registered' => false]);
    return;
}

// Register (idempotent). Self-registrations await departmental approval.
$ins = $has_status
    ? db()->prepare(
        "INSERT IGNORE INTO co_registrations
             (offer_subject_id, student_id, source, registered_by, status)
         VALUES (?, ?, 'self', NULL, 'pending')"
    )
    : db()->prepare(
        "INSERT IGNORE INTO co_registrations
             (offer_subject_id, student_id, source, registered_by)
         VALUES (?, ?, 'self', NULL)"
    );
$ins->execute([$offer_subject_id, $sid]);

sp_api_ok([
    'offer_subject_id' => $offer_subject_id,
    'registered'       => true,
    'status'           => $has_status ? 'pending' : 'approved',
]);
