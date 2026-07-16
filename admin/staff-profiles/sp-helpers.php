<?php
/**
 * Shared helpers for the staff-profiles admin module.
 */

require_once __DIR__ . '/../change-log/helpers.php';

// ── Allowed photo types ───────────────────────────────────────────────────────
const SP_PHOTO_EXTS  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
const SP_PHOTO_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
const SP_PHOTO_MAX   = 5 * 1024 * 1024; // 5 MB

// ── Permission helpers ────────────────────────────────────────────────────────

/**
 * Returns true if the current user can manage staff profiles (admin functions).
 */
function sp_is_admin(): bool
{
    return is_super_admin() || can_access('staff-profile', 'can_edit');
}

/**
 * Returns true if the current user can manage the staff department list.
 */
function sp_can_manage_depts(): bool
{
    return is_super_admin() || can_access('staff-departments', 'can_edit');
}

// ── Upload helper ─────────────────────────────────────────────────────────────

/**
 * Upload a staff profile photo.
 * Returns the generated filename on success, false on failure.
 */
function sp_upload_photo(array $file): string|false
{
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    if ($file['size'] > SP_PHOTO_MAX)     return false;

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, SP_PHOTO_EXTS, true)) return false;

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, SP_PHOTO_MIMES, true)) return false;

    $dir = UPLOAD_DIR . '/staff-profiles';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return false;

    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return false;

    return $name;
}

// ── Option lists ──────────────────────────────────────────────────────────────

/** Employee Type options (department_type value => display label). */
const SP_EMPLOYEE_TYPES = [
    'administrative' => 'Administrative',
    'educational'    => 'Faculty',
];

/** Job Type / Category options. */
const SP_JOB_TYPES = [
    'Permanent', 'Contractual', 'Ad-hoc', 'Master Role', 'Daily Basis', 'Probationary',
];

/** Employee Status options. */
const SP_EMPLOYEE_STATUSES = [
    'Active', 'Inactive', 'On Leave', 'Study Leave', 'Closed',
];

/** Gender options. */
const SP_GENDERS = ['Male', 'Female', 'Other'];

/** Blood group options. */
const SP_BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

/** Human label for an employee (department) type value. */
function sp_employee_type_label(?string $type): string
{
    return SP_EMPLOYEE_TYPES[$type ?? ''] ?? '—';
}

// ── Related records ───────────────────────────────────────────────────────────

/** Fetch a user's academic qualifications, ordered. */
function sp_qualifications(int $user_id): array
{
    $st = db()->prepare(
        'SELECT * FROM staff_qualifications WHERE user_id = ? ORDER BY sort_order ASC, id ASC'
    );
    $st->execute([$user_id]);
    return $st->fetchAll();
}

/** Fetch a user's work experiences, ordered. */
function sp_experiences(int $user_id): array
{
    $st = db()->prepare(
        'SELECT * FROM staff_experiences WHERE user_id = ? ORDER BY sort_order ASC, id ASC'
    );
    $st->execute([$user_id]);
    return $st->fetchAll();
}

/**
 * Replace a user's qualification rows from parallel POST arrays.
 * Empty rows (all blank) are skipped.
 */
function sp_save_qualifications(int $user_id, array $post): void
{
    db()->prepare('DELETE FROM staff_qualifications WHERE user_id = ?')->execute([$user_id]);

    $degrees   = (array)($post['q_degree']           ?? []);
    $groups    = (array)($post['q_group']            ?? []);
    $boards    = (array)($post['q_board']            ?? []);
    $years     = (array)($post['q_year']             ?? []);
    $grades    = (array)($post['q_grade']            ?? []);
    $results   = (array)($post['q_result']           ?? []);

    $ins = db()->prepare(
        'INSERT INTO staff_qualifications
            (user_id, degree, group_name, board_university, passing_year, grade, gpa_result, sort_order)
         VALUES (?,?,?,?,?,?,?,?)'
    );
    $sort = 0;
    foreach ($degrees as $i => $_) {
        $degree = trim($degrees[$i] ?? '');
        $group  = trim($groups[$i]  ?? '');
        $board  = trim($boards[$i]  ?? '');
        $year   = trim($years[$i]   ?? '');
        $grade  = trim($grades[$i]  ?? '');
        $result = trim($results[$i] ?? '');
        if ($degree === '' && $group === '' && $board === '' && $year === '' && $grade === '' && $result === '') {
            continue; // skip empty row
        }
        $ins->execute([
            $user_id, $degree ?: null, $group ?: null, $board ?: null,
            $year ?: null, $grade ?: null, $result ?: null, $sort++,
        ]);
    }
}

/**
 * Replace a user's experience rows from parallel POST arrays.
 * Empty rows (all blank) are skipped.
 */
function sp_save_experiences(int $user_id, array $post): void
{
    db()->prepare('DELETE FROM staff_experiences WHERE user_id = ?')->execute([$user_id]);

    $positions = (array)($post['x_position']     ?? []);
    $orgs      = (array)($post['x_organization'] ?? []);
    $depts     = (array)($post['x_department']   ?? []);
    $joins     = (array)($post['x_joining']      ?? []);
    $resigns   = (array)($post['x_resign']       ?? []);

    $ins = db()->prepare(
        'INSERT INTO staff_experiences
            (user_id, position, organization, department, joining_date, resign_date, sort_order)
         VALUES (?,?,?,?,?,?,?)'
    );
    $sort = 0;
    foreach ($positions as $i => $_) {
        $pos    = trim($positions[$i] ?? '');
        $org    = trim($orgs[$i]      ?? '');
        $dept   = trim($depts[$i]     ?? '');
        $join   = trim($joins[$i]     ?? '') ?: null;
        $resign = trim($resigns[$i]   ?? '') ?: null;
        if ($pos === '' && $org === '' && $dept === '' && $join === null && $resign === null) {
            continue; // skip empty row
        }
        $ins->execute([$user_id, $pos ?: null, $org ?: null, $dept ?: null, $join, $resign, $sort++]);
    }
}

// ── Badge helpers ─────────────────────────────────────────────────────────────

function sp_dept_type_badge(string $type): string
{
    return match ($type) {
        'administrative' => '<span class="badge bg-primary">Administrative</span>',
        'educational'    => '<span class="badge bg-success">Faculty</span>',
        default          => '<span class="badge bg-secondary">' . h($type) . '</span>',
    };
}

function sp_status_badge(?string $status): string
{
    return match ($status) {
        'Active'      => '<span class="badge bg-success">Active</span>',
        'Inactive'    => '<span class="badge bg-secondary">Inactive</span>',
        'On Leave'    => '<span class="badge bg-warning text-dark">On Leave</span>',
        'Study Leave' => '<span class="badge bg-info text-dark">Study Leave</span>',
        'Closed'      => '<span class="badge bg-dark">Closed</span>',
        default       => '<span class="badge bg-light text-muted">—</span>',
    };
}

// ── Full profile loading & CV rendering ──────────────────────────────────────

/**
 * Load a complete employee profile (user + staff_profiles + qualifications +
 * experiences + faculty_profiles) for the given user id.
 *
 * Returns null if the user does not exist. The `staff` and `faculty` keys are
 * empty arrays when no corresponding row exists.
 */
function sp_load_full_profile(int $user_id): ?array
{
    $us = db()->prepare('SELECT id, full_name, username, email, phone FROM users WHERE id = ?');
    $us->execute([$user_id]);
    $user = $us->fetch();
    if (!$user) {
        return null;
    }

    $sp = db()->prepare(
        'SELECT sp.*, sd.name AS dept_name, sd.type AS dept_kind
         FROM staff_profiles sp
         LEFT JOIN staff_departments sd ON sd.id = sp.staff_dept_id
         WHERE sp.user_id = ?'
    );
    $sp->execute([$user_id]);
    $staff = $sp->fetch() ?: [];

    $faculty = [];
    try {
        $fp = db()->prepare(
            'SELECT fp.*, dd.name AS academic_dept_name
             FROM faculty_profiles fp
             LEFT JOIN dept_departments dd ON dd.id = fp.dept_id
             WHERE fp.user_id = ?'
        );
        $fp->execute([$user_id]);
        $faculty = $fp->fetch() ?: [];
    } catch (Throwable $e) {
        $faculty = [];
    }

    return [
        'user'    => $user,
        'staff'   => $staff,
        'quals'   => sp_qualifications($user_id),
        'exps'    => sp_experiences($user_id),
        'faculty' => $faculty,
    ];
}

/**
 * Return a base64 data URI for a profile photo stored under uploads/$subdir,
 * or an empty string when the file is missing/unreadable. Used so photos embed
 * correctly in the generated PDF (which runs with remote loading disabled).
 */
function sp_photo_data_uri(?string $file, string $subdir = 'staff-profiles'): string
{
    if (empty($file)) {
        return '';
    }
    $abs = UPLOAD_DIR . '/' . $subdir . '/' . basename($file);
    if (!is_file($abs) || !is_readable($abs)) {
        return '';
    }
    $bytes = file_get_contents($abs);
    if ($bytes === false) {
        return '';
    }
    $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mime = match ($ext) {
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        default => 'image/jpeg',
    };
    return 'data:' . $mime . ';base64,' . base64_encode($bytes);
}

/**
 * Format a date value (Y-m-d) as a human-readable string, or a dash when empty.
 */
function sp_fmt_date(?string $val): string
{
    if (empty($val) || $val === '0000-00-00') {
        return '—';
    }
    $ts = strtotime($val);
    return $ts ? date('d M Y', $ts) : h($val);
}

/**
 * Render the complete employee profile / CV as self-contained HTML (inline
 * styles only). The same markup is used for the on-screen "view" page and the
 * downloadable PDF, so it must not depend on Bootstrap.
 *
 * @param array $data Result of sp_load_full_profile().
 * @param bool  $for_pdf When true, photos are embedded as data URIs.
 */
function sp_render_cv_html(array $data, bool $for_pdf = false): string
{
    $user    = $data['user']    ?? [];
    $sp      = $data['staff']   ?? [];
    $fp      = $data['faculty'] ?? [];
    $quals   = $data['quals']   ?? [];
    $exps    = $data['exps']    ?? [];

    $is_faculty = (($sp['department_type'] ?? '') === 'educational');

    // Resolve the display photo (staff photo first, then faculty photo).
    if ($for_pdf) {
        $photo_src = sp_photo_data_uri($sp['photo'] ?? null, 'staff-profiles');
        if ($photo_src === '') {
            $photo_src = sp_photo_data_uri($fp['photo'] ?? null, 'faculty-profiles');
        }
    } else {
        $photo_src = '';
        if (!empty($sp['photo'])) {
            $photo_src = UPLOAD_URL . '/staff-profiles/' . rawurlencode($sp['photo']);
        } elseif (!empty($fp['photo'])) {
            $photo_src = UPLOAD_URL . '/faculty-profiles/' . rawurlencode($fp['photo']);
        }
    }

    // ── Small inline render helpers ─────────────────────────────────────────
    $rows = static function (array $pairs): string {
        $out = '';
        foreach ($pairs as $label => $value) {
            $value = trim((string)$value);
            if ($value === '' || $value === '—') {
                continue;
            }
            $out .= '<tr>'
                . '<td style="padding:4px 10px;color:#555;width:38%;vertical-align:top;font-weight:bold;">' . h($label) . '</td>'
                . '<td style="padding:4px 10px;color:#111;vertical-align:top;">' . $value . '</td>'
                . '</tr>';
        }
        return $out === '' ? '' : '<table style="width:100%;border-collapse:collapse;font-size:12px;">' . $out . '</table>';
    };

    $section = static function (string $title, string $body): string {
        if (trim($body) === '') {
            return '';
        }
        return '<div style="margin-top:16px;">'
            . '<div style="font-size:13px;font-weight:bold;color:#1a3e72;text-transform:uppercase;'
            . 'letter-spacing:.5px;border-bottom:2px solid #1a3e72;padding-bottom:4px;margin-bottom:8px;">'
            . h($title) . '</div>' . $body . '</div>';
    };

    $textBlock = static function (?string $label, ?string $value): string {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        $body = nl2br(h($value));
        $lbl  = $label ? '<span style="font-weight:bold;color:#555;">' . h($label) . ': </span>' : '';
        return '<div style="font-size:12px;color:#111;margin-bottom:6px;">' . $lbl . $body . '</div>';
    };

    // ── Header ──────────────────────────────────────────────────────────────
    $name        = h($user['full_name'] ?? '');
    $designation = trim((string)($sp['designation'] ?? $fp['designation'] ?? ''));
    $dept_name   = trim((string)($sp['dept_name'] ?? $fp['academic_dept_name'] ?? ''));
    $emp_type    = sp_employee_type_label($sp['department_type'] ?? null);

    $photo_html = $photo_src !== ''
        ? '<img src="' . h($photo_src) . '" alt="" style="width:96px;height:96px;border-radius:8px;object-fit:cover;border:1px solid #ccc;">'
        : '<div style="width:96px;height:96px;border-radius:8px;border:1px solid #ccc;background:#eee;"></div>';

    $head_meta = '';
    if ($designation !== '') {
        $head_meta .= '<div style="font-size:14px;color:#333;margin-top:2px;">' . h($designation) . '</div>';
    }
    if ($dept_name !== '') {
        $head_meta .= '<div style="font-size:12px;color:#555;margin-top:2px;">' . h($dept_name) . '</div>';
    }
    $head_meta .= '<div style="font-size:11px;color:#777;margin-top:6px;">'
        . 'Employee Type: ' . h($emp_type);
    if (!empty($sp['employee_id'])) {
        $head_meta .= ' &nbsp;|&nbsp; Employee ID: ' . h($sp['employee_id']);
    }
    if (!empty($sp['employee_status'])) {
        $head_meta .= ' &nbsp;|&nbsp; Status: ' . h($sp['employee_status']);
    }
    $head_meta .= '</div>';

    $header = '<table style="width:100%;border-collapse:collapse;">'
        . '<tr>'
        . '<td style="width:110px;vertical-align:top;">' . $photo_html . '</td>'
        . '<td style="vertical-align:top;padding-left:14px;">'
        . '<div style="font-size:22px;font-weight:bold;color:#1a3e72;">' . $name . '</div>'
        . $head_meta
        . '</td>'
        . '</tr></table>';

    // ── Contact ─────────────────────────────────────────────────────────────
    $contact = $rows([
        'Email'            => h($user['email'] ?? ''),
        'Phone'            => h($user['phone'] ?? ''),
        'Official Email'   => h($fp['official_email'] ?? ''),
        'Personal Email'   => h($fp['personal_email'] ?? ''),
        'Faculty Phone'    => h($fp['phone'] ?? ''),
        'Office Location'  => h($fp['office_location'] ?? ''),
        'Room Number'      => h($fp['room_number'] ?? ''),
        'Office Hours'     => h($fp['office_hours'] ?? ''),
    ]);

    // ── Personal information ────────────────────────────────────────────────
    $personal = $rows([
        "Father's Name" => h($sp['father_name'] ?? ''),
        "Mother's Name" => h($sp['mother_name'] ?? ''),
        'Gender'        => h($sp['gender'] ?? ''),
        'Date of Birth' => sp_fmt_date($sp['date_of_birth'] ?? null),
        'Birth Place'   => h($sp['birth_place'] ?? ''),
        'Religion'      => h($sp['religion'] ?? ''),
        'Blood Group'   => h($sp['blood_group'] ?? ''),
        'Nationality'   => h($sp['nationality'] ?? ''),
        'National ID'   => h($sp['national_id'] ?? ''),
    ]);

    // ── Employment ──────────────────────────────────────────────────────────
    $employment = $rows([
        'Department'   => h($dept_name),
        'Designation'  => h($designation),
        'Job Type'     => h($sp['job_type'] ?? ''),
        'Joining Date' => sp_fmt_date($sp['joining_date'] ?? null),
        'Finger / Device ID' => h($sp['finger_id'] ?? ''),
    ]);

    // ── Emergency contact ───────────────────────────────────────────────────
    $emergency = $rows([
        'Contact Name' => h($sp['emergency_contact_name'] ?? ''),
        'Relationship' => h($sp['emergency_contact_relation'] ?? ''),
        'Address'      => h($sp['emergency_contact_address'] ?? ''),
    ]);

    // ── Academic qualifications ─────────────────────────────────────────────
    $quals_body = '';
    if (!empty($quals)) {
        $qr = '';
        foreach ($quals as $q) {
            $qr .= '<tr>'
                . '<td style="padding:5px 8px;border:1px solid #ddd;">' . h($q['degree'] ?? '') . '</td>'
                . '<td style="padding:5px 8px;border:1px solid #ddd;">' . h($q['group_name'] ?? '') . '</td>'
                . '<td style="padding:5px 8px;border:1px solid #ddd;">' . h($q['board_university'] ?? '') . '</td>'
                . '<td style="padding:5px 8px;border:1px solid #ddd;">' . h($q['passing_year'] ?? '') . '</td>'
                . '<td style="padding:5px 8px;border:1px solid #ddd;">' . h($q['grade'] ?? '') . '</td>'
                . '<td style="padding:5px 8px;border:1px solid #ddd;">' . h($q['gpa_result'] ?? '') . '</td>'
                . '</tr>';
        }
        $quals_body = '<table style="width:100%;border-collapse:collapse;font-size:11px;">'
            . '<tr style="background:#f0f3f9;">'
            . '<th style="padding:5px 8px;border:1px solid #ddd;text-align:left;">Degree</th>'
            . '<th style="padding:5px 8px;border:1px solid #ddd;text-align:left;">Group / Major</th>'
            . '<th style="padding:5px 8px;border:1px solid #ddd;text-align:left;">Board / University</th>'
            . '<th style="padding:5px 8px;border:1px solid #ddd;text-align:left;">Year</th>'
            . '<th style="padding:5px 8px;border:1px solid #ddd;text-align:left;">Grade</th>'
            . '<th style="padding:5px 8px;border:1px solid #ddd;text-align:left;">GPA / Result</th>'
            . '</tr>' . $qr . '</table>';
    }

    // ── Work experience ─────────────────────────────────────────────────────
    $exps_body = '';
    if (!empty($exps)) {
        $xr = '';
        foreach ($exps as $x) {
            $period = sp_fmt_date($x['joining_date'] ?? null) . ' – '
                . (!empty($x['resign_date']) ? sp_fmt_date($x['resign_date']) : 'Present');
            $xr .= '<tr>'
                . '<td style="padding:5px 8px;border:1px solid #ddd;">' . h($x['position'] ?? '') . '</td>'
                . '<td style="padding:5px 8px;border:1px solid #ddd;">' . h($x['organization'] ?? '') . '</td>'
                . '<td style="padding:5px 8px;border:1px solid #ddd;">' . h($x['department'] ?? '') . '</td>'
                . '<td style="padding:5px 8px;border:1px solid #ddd;white-space:nowrap;">' . $period . '</td>'
                . '</tr>';
        }
        $exps_body = '<table style="width:100%;border-collapse:collapse;font-size:11px;">'
            . '<tr style="background:#f0f3f9;">'
            . '<th style="padding:5px 8px;border:1px solid #ddd;text-align:left;">Position</th>'
            . '<th style="padding:5px 8px;border:1px solid #ddd;text-align:left;">Organization</th>'
            . '<th style="padding:5px 8px;border:1px solid #ddd;text-align:left;">Department</th>'
            . '<th style="padding:5px 8px;border:1px solid #ddd;text-align:left;">Period</th>'
            . '</tr>' . $xr . '</table>';
    }

    // ── Faculty (academic) details ──────────────────────────────────────────
    $faculty_body = '';
    if ($is_faculty) {
        $faculty_body .= $textBlock('Qualifications', $fp['qualification'] ?? '');
        $faculty_body .= $textBlock('Languages', $fp['languages'] ?? '');
        $faculty_body .= $textBlock(null, $fp['bio'] ?? '');
    }

    $research = '';
    if ($is_faculty) {
        $research .= $textBlock('Research Interests', $fp['research_interest'] ?? '');
        $research .= $textBlock('Publications', $fp['publications'] ?? '');
        $research .= $textBlock('Academic Experience', $fp['experience'] ?? '');
        $research .= $textBlock('Courses Taught', $fp['courses_taught'] ?? '');
        $research .= $textBlock('Supervision', $fp['supervision'] ?? '');
        $research .= $textBlock('Projects & Grants', $fp['projects_grants'] ?? '');
        $research .= $textBlock('Awards & Honors', $fp['awards'] ?? '');
        $research .= $textBlock('Professional Memberships', $fp['professional_memberships'] ?? '');
        $research .= $textBlock('Skills & Expertise', $fp['skills'] ?? '');
    }

    $links = '';
    if ($is_faculty) {
        $links .= $rows([
            'Google Scholar' => h($fp['google_scholar'] ?? ''),
            'ORCID'          => h($fp['orcid'] ?? ''),
        ]);
        $links .= $textBlock('Other Research Profiles', $fp['research_profiles'] ?? '');
        $links .= $textBlock('Social Links', $fp['social_links'] ?? '');
    }

    // ── Assemble ────────────────────────────────────────────────────────────
    $html = $header;
    $html .= $section('Contact', $contact);
    $html .= $section('Personal Information', $personal);
    $html .= $section('Employment Details', $employment);
    $html .= $section('Emergency Contact', $emergency);
    $html .= $section('Academic Qualifications', $quals_body);
    $html .= $section('Work Experience', $exps_body);
    if ($is_faculty) {
        $html .= $section('Faculty Details', $faculty_body);
        $html .= $section('Research & Teaching', $research);
        $html .= $section('Profiles & Links', $links);
    }

    return $html;
}
