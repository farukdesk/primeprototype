<?php
/**
 * Staff API – GET /api/staff/invigilation-duty.php
 * ==================================================
 * The signed-in faculty member's invigilation duties (upcoming + past),
 * mirroring the web self-service page admin/invigilation-duty/index.php.
 * Reuses that module's helpers so the account→ei_faculty resolution and the
 * attendance lookup stay identical. Read-only (no POST).
 */

require_once __DIR__ . '/includes/auth_staff_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error(405, 'Method Not Allowed. Use GET.');
}

$ctx = staff_api_auth();
$uid = (int)$ctx['user']['user_id'];

// Reuse the Invigilation Duty module's resolution + duty helpers.
require_once dirname(__DIR__, 2) . '/invigilation-duty/helpers.php';

$faculty_ids = ivd_faculty_ids_for_user($uid);
$has_profile = !empty($faculty_ids);

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

foreach ($duties as $d) {
    if ((string)$d['slot_date'] >= $today) {
        $upcoming[] = $map($d);
    } else {
        $past[] = $map($d);
    }
}
// Most recent past duty first (the web page reverses past rows).
$past = array_reverse($past);

$attended_count = count(array_filter($duties, static fn ($d) => $d['attended'] === 1));

api_ok([
    'has_profile' => $has_profile,
    'summary'     => [
        'total'    => count($duties),
        'upcoming' => count($upcoming),
        'attended' => $attended_count,
    ],
    'upcoming' => $upcoming,
    'past'     => $past,
]);
