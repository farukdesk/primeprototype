<?php
/**
 * Third-party API – GET /admin/api/v1/reference-data.php
 * =======================================================
 * Returns every lookup value a partner needs to build a valid
 * POST /v1/students/create.php request: departments (with their programs),
 * semester strings, exam titles, boards/universities, academic groups,
 * enumerations and limits.
 *
 * Auth : X-API-Key with scope `reference:read`
 * Docs : API-GUIDE.md
 */

require_once __DIR__ . '/../includes/auth_client_api.php';
require_once __DIR__ . '/includes/student_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, OPTIONS');
    capi_error(405, 'method_not_allowed', 'Use GET.');
}

$client = capi_auth('reference:read');
capi_begin_request($client, 'v1/reference-data');

try {
    $programs_by_dept = [];
    foreach (capi_programs() as $p) {
        $programs_by_dept[(int)$p['dept_id']][] = [
            'id'   => (int)$p['id'],
            'name' => $p['program_name'],
            'type' => $p['program_type'],
        ];
    }

    $departments = [];
    foreach (capi_departments() as $d) {
        $departments[] = [
            'id'       => (int)$d['id'],
            'code'     => $d['code'],
            'name'     => $d['name'],
            'faculty'  => $d['faculty_label'],
            'programs' => $programs_by_dept[(int)$d['id']] ?? [],
        ];
    }

    $year      = (int)date('Y');
    $semesters = [];
    for ($y = $year - 1; $y <= $year + 2; $y++) {
        foreach (CAPI_TERMS as $term) {
            $semesters[] = $term . ' ' . $y;
        }
    }

    $with_short = static fn(array $rows): array => array_map(
        static fn(array $r): array => ['id' => (int)$r['id'], 'name' => $r['name'], 'short_name' => $r['short_name']],
        $rows
    );

    capi_ok([
        'data' => [
            'departments' => $departments,
            'semesters'   => $semesters,
            'exam_titles' => $with_short(capi_lookup_rows('exam')),
            'boards'      => $with_short(capi_lookup_rows('board')),
            'groups'      => array_map(
                static fn(array $r): array => ['id' => (int)$r['id'], 'name' => $r['name']],
                capi_lookup_rows('group')
            ),
            'enums' => [
                'sex'           => CAPI_SEXES,
                'status'        => CAPI_STATUSES,
                'shift'         => CAPI_SHIFTS,
                'section'       => CAPI_SECTIONS,
                'blood_group'   => CAPI_BLOOD_GROUPS,
                'semester_type' => CAPI_SEMESTER_TYPES,
            ],
            'limits' => [
                'photo_max_bytes'    => CAPI_PHOTO_MAX,
                'photo_types'        => array_keys(CAPI_PHOTO_MIMES),
                'max_qualifications' => CAPI_MAX_QUALIFICATIONS,
                'rate_limit_per_min' => (int)$client['rate_limit_per_min'],
            ],
        ],
    ]);
} catch (Throwable $e) {
    error_log('api/v1/reference-data: ' . $e->getMessage());
    capi_error(500, 'server_error', 'Reference data is temporarily unavailable.');
}
