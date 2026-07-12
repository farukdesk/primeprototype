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
