<?php
/**
 * Staff API – /api/staff/invigilation-duty.php
 * ==================================================
 * GET  : the signed-in faculty member's invigilation duties (upcoming + past)
 *        plus their swap/substitute requests (incoming + outgoing) and the
 *        eligible target faculty per upcoming slot.
 * POST : create / accept / reject / cancel a swap or substitute request.
 *
 * Mirrors the web self-service page admin/invigilation-duty/index.php and
 * reuses that module's helpers so the account→ei_faculty resolution, swap
 * rules and attendance lookup stay identical.
 */

require_once __DIR__ . '/includes/auth_staff_api.php';

$ctx = staff_api_auth();
$uid = (int)$ctx['user']['user_id'];

// Reuse the Invigilation Duty module's resolution + duty helpers.
require_once dirname(__DIR__, 2) . '/invigilation-duty/helpers.php';

$faculty_ids = ivd_faculty_ids_for_user($uid);
$has_profile = !empty($faculty_ids);

// ── Swap / substitute actions (create / accept / reject / cancel) ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$has_profile) {
        api_error(400, 'Your account is not linked to a faculty record.');
    }

    $input  = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $action = (string)($input['action'] ?? '');

    switch ($action) {
        case 'create':
            [$ok, $msg] = ivd_create_swap_request(
                $faculty_ids,
                (int)($input['slot_id'] ?? 0),
                (string)($input['request_type'] ?? 'substitute'),
                (int)($input['target_faculty_id'] ?? 0),
                trim((string)($input['message'] ?? ''))
            );
            break;
        case 'accept':
            [$ok, $msg] = ivd_accept_request((int)($input['request_id'] ?? 0), $faculty_ids);
            break;
        case 'reject':
            [$ok, $msg] = ivd_reject_request((int)($input['request_id'] ?? 0), $faculty_ids);
            break;
        case 'cancel':
            [$ok, $msg] = ivd_cancel_request((int)($input['request_id'] ?? 0), $faculty_ids);
            break;
        default:
            api_error(400, 'Unknown action.');
    }

    if (!$ok) api_error(400, $msg);
    api_ok(['message' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error(405, 'Method Not Allowed. Use GET or POST.');
}

$duties = $has_profile ? ivd_duties_for_faculty($faculty_ids) : [];

$today    = date('Y-m-d');
$upcoming = [];
$past     = [];

/** Shape one duty row for the app. */
$map = static function (array $d): array {
    $date = (string)$d['slot_date'];
    $ts   = strtotime($date);
    $attended = $d['attended'];
    $status = $attended === 1 ? 'attended' : ($attended === 0 ? 'absent' : 'pending');
    return [
        'id'          => (int)$d['id'],
        'date'        => $date,
        'date_label'  => $ts ? date('d M Y', $ts) : $date,
        'weekday'     => $ts ? date('l', $ts) : '',
        'time_slot'   => (string)$d['time_slot'],
        'exam_name'   => (string)$d['exam_name'],
        'exam_year'   => $d['exam_year'] !== null ? (string)$d['exam_year'] : null,
        'room_number' => (string)$d['room_number'],
        'dept_name'   => ($d['dept_name'] !== null && $d['dept_name'] !== '') ? (string)$d['dept_name'] : null,
        'my_position' => (int)$d['my_position'],
        'co_name'     => ($d['co_name'] !== null && $d['co_name'] !== '') ? (string)$d['co_name'] : null,
        'attended'    => $attended,
        'status'      => $status,
    ];
};

$upcoming_raw = [];
foreach ($duties as $d) {
    if ((string)$d['slot_date'] >= $today) {
        $upcoming[]     = $map($d);
        $upcoming_raw[] = $d;
    } else {
        $past[] = $map($d);
    }
}
// Most recent past duty first (the web page reverses past rows).
$past = array_reverse($past);

$attended_count = count(array_filter($duties, static fn ($d) => $d['attended'] === 1));

// ── Swap / substitute requests + eligible targets (same as the web page) ──────
/** Shape one swap/substitute request row for the app. */
$map_request = static function (array $r): array {
    $date = (string)($r['slot_date'] ?? '');
    $ts   = $date !== '' ? strtotime($date) : false;
    return [
        'id'             => (int)$r['id'],
        'request_type'   => (string)$r['request_type'],
        'status'         => (string)$r['status'],
        'requester_name' => isset($r['requester_name']) ? (string)$r['requester_name'] : null,
        'target_name'    => isset($r['target_name']) ? (string)$r['target_name'] : null,
        'exam_name'      => (string)($r['exam_name'] ?? ''),
        'date'           => $date,
        'date_label'     => $ts ? date('d M Y', $ts) : $date,
        'time_slot'      => (string)($r['time_slot'] ?? ''),
        'room_number'    => (string)($r['room_number'] ?? ''),
        'message'        => ($r['message'] !== null && $r['message'] !== '') ? (string)$r['message'] : null,
    ];
};

$incoming = $has_profile ? array_map($map_request, ivd_incoming_requests($faculty_ids)) : [];
$outgoing = $has_profile ? array_map($map_request, ivd_outgoing_requests($faculty_ids)) : [];

// Eligible swap/substitute targets per upcoming slot (for the request picker).
$eligible_targets = [];
foreach ($upcoming_raw as $d) {
    $targets = ivd_eligible_targets_for_slot($d, $faculty_ids);
    $eligible_targets[(string)(int)$d['id']] = array_values(array_map(
        static fn (array $t): array => [
            'id'          => (int)$t['id'],
            'name'        => (string)$t['name'],
            'designation' => ($t['designation'] !== null && $t['designation'] !== '') ? (string)$t['designation'] : null,
            'dept_name'   => ($t['dept_name'] !== '') ? (string)$t['dept_name'] : null,
        ],
        $targets
    ));
}

api_ok([
    'has_profile' => $has_profile,
    'summary'     => [
        'total'    => count($duties),
        'upcoming' => count($upcoming),
        'attended' => $attended_count,
    ],
    'upcoming'         => $upcoming,
    'past'             => $past,
    'incoming'         => $incoming,
    'outgoing'         => $outgoing,
    'eligible_targets' => (object)$eligible_targets,
]);
