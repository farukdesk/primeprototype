<?php
/**
 * Student Portal API – GET /api/student/admit-cards.php
 * ======================================================
 * Lists the active admit cards available to the signed-in student, with a
 * per-card download eligibility flag. Delegates to the admit-card module
 * helpers so the mobile app follows exactly the same rules as the web
 * portal (batch restriction, exam enrollment, due-amount threshold,
 * admin overrides).
 *
 * Success response:
 *   { "ok": true, "admit_cards": [ { id, exam_name, semester, batch,
 *     dept_name, program_name, allowed, reason, created_at }, ... ] }
 */

require_once __DIR__ . '/includes/auth_student_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sp_api_error(405, 'Method Not Allowed. Use GET.');
}

$ctx     = sp_api_auth();
$student = $ctx['student'];
$sid     = (int)$student['student_db_id'];
$sbatch  = (int)($student['batch_id'] ?? 0);

try {
    require_once dirname(__DIR__, 2) . '/admit-card/helpers.php';
} catch (Throwable $e) {
    sp_api_error(503, 'Admit card module is not available.');
}

try {
    // Same visibility rule as the web portal: the card must match the
    // student's dept + program AND either be batch-agnostic or belong to
    // the student's own batch. Cards of the student's batch are preferred
    // when several siblings of the same exam exist.
    $stmt = db()->prepare(
        'SELECT ac.*,
                d.name AS dept_name,
                p.program_name,
                b.name AS batch_name_db
         FROM ac_admit_cards ac
         JOIN dept_departments d       ON d.id = ac.dept_id
         JOIN dept_academic_programs p ON p.id = ac.program_id
         LEFT JOIN student_batches b   ON b.id = ac.batch_id
         WHERE ac.is_active = 1
           AND ac.dept_id = ? AND ac.program_id = ?
           AND (ac.batch_id IS NULL OR ac.batch_id = ?)
         ORDER BY (ac.batch_id = ?) DESC, ac.id DESC'
    );
    $stmt->execute([(int)$student['dept_id'], (int)$student['program_id'], $sbatch, $sbatch]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Student admit-cards: query failed – ' . $e->getMessage());
    sp_api_error(500, 'Could not load admit cards. Please try again.');
}

$cards     = [];
$seen_keys = [];
$norm_exam = static fn($s) => strtolower((string)preg_replace('/[^a-z0-9]+/i', '', (string)$s));

foreach ($rows as $card) {
    $card_id = (int)$card['id'];

    // Bulk creation makes one card per exam routine, so one exam may have
    // several sibling cards. The PDF already merges the student's courses
    // across siblings (see ac_get_merged_courses_for_student), so only ONE
    // card per exam is listed to the app. Siblings are grouped by both the
    // linked exam id and the normalised exam name (the same key the
    // sibling-merge uses); the row order above makes the student's own
    // batch card win the group.
    $keys    = [];
    $nk      = $norm_exam($card['exam_name']);
    if ($nk !== '') {
        $keys[] = 'name:' . $nk;
    }
    $exam_id = ac_card_exam_id($card_id);
    if ($exam_id > 0) {
        $keys[] = 'exam:' . $exam_id;
    }

    $dup = false;
    foreach ($keys as $k) {
        if (isset($seen_keys[$k])) {
            $dup = true;
            break;
        }
    }
    if ($dup) {
        continue;
    }

    // Hide cards of exams the student is not enrolled in (unless the
    // student has an admin override for this card).
    if (!ac_has_override($card_id, $sid)) {
        if ($exam_id > 0) {
            if (!ac_resolve_student_courses($exam_id, $sid)) {
                continue;
            }
        } else {
            $routine_id = ac_card_routine_id($card_id);
            if ($routine_id > 0 && !ac_is_enrolled_in_routine($routine_id, $sid)) {
                continue;
            }
        }
    }

    // Same eligibility rule as the web portal (due-amount check, overrides).
    $access = ac_check_access($card_id, $sid);

    foreach ($keys as $k) {
        $seen_keys[$k] = true;
    }

    $cards[] = [
        'id'           => $card_id,
        'exam_name'    => (string)$card['exam_name'],
        'semester'     => (string)($card['semester'] ?? ''),
        'batch'        => (string)(($card['batch_label'] ?? '') !== '' ? $card['batch_label'] : ($card['batch_name_db'] ?? '')),
        'dept_name'    => (string)$card['dept_name'],
        'program_name' => (string)$card['program_name'],
        'allowed'      => (bool)$access['allowed'],
        'reason'       => $access['allowed'] ? null : (string)($access['reason'] ?? ''),
        'created_at'   => (string)($card['created_at'] ?? ''),
    ];
}

sp_api_ok(['admit_cards' => $cards]);
