<?php
/**
 * SS Portal – turn the create-student form into the JSON document expected by
 * POST /admin/api/v1/students/create.php, with light local validation so the
 * operator gets instant feedback. Full validation happens at the university;
 * its 422 `errors` map uses the same dotted keys as $errors here
 * (e.g. guardian.email, academic_qualifications.1.year_of_passing, result.cgpa),
 * so both can be rendered on the form the same way.
 */

require_once __DIR__ . '/bootstrap.php';

const SSP_STUDENT_SCALARS = [
    // enrollment
    'department', 'program', 'semester', 'year', 'batch', 'semester_type', 'shift', 'section', 'status',
    // student
    'name', 'father_name', 'father_phone', 'father_occupation', 'mother_name', 'mother_phone', 'mother_occupation',
    'present_address', 'contact_no', 'email', 'permanent_address', 'permanent_contact_no', 'permanent_email',
    'nationality', 'country', 'place_of_birth', 'date_of_birth', 'religion', 'sex', 'blood_group', 'nid',
];
const SSP_GUARDIAN_FIELDS      = ['name', 'profession', 'address', 'phone', 'relationship', 'email', 'yearly_income'];
const SSP_QUALIFICATION_FIELDS = ['name_of_examination', 'session', 'group', 'board_university', 'year_of_passing', 'division_grade', 'obtained_marks_cgpa'];
const SSP_RESULT_FIELDS        = ['semester', 'cgpa', 'batch', 'recorded_date', 'subject'];
const SSP_MAX_QUALIFICATIONS   = 10;
const SSP_PHOTO_MIMES          = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

function ssp_valid_phone(string $v): bool
{
    return (bool)preg_match('/^\+?\d{6,20}$/', $v);
}

function ssp_valid_date(string $v): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return $d instanceof DateTime && $d->format('Y-m-d') === $v;
}

/**
 * Build the API payload from a submitted form ($_POST).  Blank values are
 * dropped; $errors is filled with dotted field keys => message.
 */
function ssp_build_student_payload(array $src, array &$errors): array
{
    $errors  = [];
    $payload = [];
    $str = static function ($v): string {
        return is_scalar($v) ? trim((string)$v) : '';
    };

    foreach (SSP_STUDENT_SCALARS as $f) {
        $v = $str($src[$f] ?? '');
        if ($v !== '') {
            $payload[$f] = $v;
        }
    }

    if (($payload['department'] ?? '') === '') {
        $errors['department'] = 'Department is required.';
    }
    if (($payload['semester'] ?? '') === '') {
        $errors['semester'] = 'Admitted semester is required, e.g. "Spring 2026".';
    } elseif (!preg_match('/^(spring|summer|fall)[\s-]+\d{4}$/i', $payload['semester']) && !preg_match('/^\d{4}[\s-]+(spring|summer|fall)$/i', $payload['semester'])) {
        $errors['semester'] = 'Use "<Spring|Summer|Fall> <YYYY>", e.g. "Fall 2026".';
    }
    $nameLen = mb_strlen($payload['name'] ?? '');
    if ($nameLen < 2 || $nameLen > 255) {
        $errors['name'] = 'Full name must be between 2 and 255 characters.';
    }
    foreach (['email', 'permanent_email'] as $f) {
        if (isset($payload[$f]) && !filter_var($payload[$f], FILTER_VALIDATE_EMAIL)) {
            $errors[$f] = 'Must be a valid e-mail address.';
        }
    }
    foreach (['contact_no', 'permanent_contact_no', 'father_phone', 'mother_phone'] as $f) {
        if (isset($payload[$f])) {
            $payload[$f] = preg_replace('/[\s()-]+/', '', $payload[$f]);
            if (!ssp_valid_phone($payload[$f])) {
                $errors[$f] = 'Digits only with an optional leading +, 6-20 characters (e.g. +8801711000000).';
            }
        }
    }
    if (isset($payload['date_of_birth'])) {
        if (!ssp_valid_date($payload['date_of_birth'])) {
            $errors['date_of_birth'] = 'Use the format YYYY-MM-DD.';
        } elseif ($payload['date_of_birth'] > date('Y-m-d')) {
            $errors['date_of_birth'] = 'Date of birth cannot be in the future.';
        }
    }

    // ── Guardian ────────────────────────────────────────────────────────────────────
    $guardianSrc = is_array($src['guardian'] ?? null) ? $src['guardian'] : [];
    $guardian    = [];
    foreach (SSP_GUARDIAN_FIELDS as $f) {
        $v = $str($guardianSrc[$f] ?? '');
        if ($v !== '') {
            $guardian[$f] = $v;
        }
    }
    if (isset($guardian['email']) && !filter_var($guardian['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['guardian.email'] = 'Must be a valid e-mail address.';
    }
    if (isset($guardian['phone'])) {
        $guardian['phone'] = preg_replace('/[\s()-]+/', '', $guardian['phone']);
        if (!ssp_valid_phone($guardian['phone'])) {
            $errors['guardian.phone'] = 'Digits only with an optional leading +, 6-20 characters.';
        }
    }
    if (isset($guardian['yearly_income'])) {
        $n = str_replace(',', '', $guardian['yearly_income']);
        if (!is_numeric($n) || (float)$n < 0) {
            $errors['guardian.yearly_income'] = 'Must be a number greater than or equal to 0.';
        } else {
            $guardian['yearly_income'] = $n + 0;
        }
    }
    if ($guardian) {
        $payload['guardian'] = $guardian;
    }

    // ── Academic qualifications (blank rows are skipped) ───────────────────────────────────
    $quals = [];
    foreach ((array)($src['academic_qualifications'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $q = [];
        foreach (SSP_QUALIFICATION_FIELDS as $f) {
            $v = $str($row[$f] ?? '');
            if ($v !== '') {
                $q[$f] = $v;
            }
        }
        if (!$q) {
            continue;
        }
        $i = count($quals);
        if (!isset($q['name_of_examination'])) {
            $errors["academic_qualifications.$i.name_of_examination"] = 'Examination name is required (e.g. SSC, HSC).';
        }
        if (isset($q['year_of_passing']) && !preg_match('/^\d{4}$/', $q['year_of_passing'])) {
            $errors["academic_qualifications.$i.year_of_passing"] = 'Must be a 4-digit year.';
        }
        $quals[] = $q;
    }
    if (count($quals) > SSP_MAX_QUALIFICATIONS) {
        $errors['academic_qualifications'] = 'At most ' . SSP_MAX_QUALIFICATIONS . ' qualifications can be sent.';
    }
    if ($quals) {
        $payload['academic_qualifications'] = $quals;
    }

    // ── Optional final result (sent in the same call, see API guide §6.8) ────────────────────────
    if (!empty($src['result_enabled'])) {
        $resultSrc = is_array($src['result'] ?? null) ? $src['result'] : [];
        $result    = ssp_build_result_fields($resultSrc, $errors, 'result.');
        $payload['result'] = $result;
    }

    return $payload;
}

/**
 * Validate the result fields shared by "create with result" and "publish result".
 * $prefix is prepended to error keys ('result.' or '').
 */
function ssp_build_result_fields(array $src, array &$errors, string $prefix = ''): array
{
    $str = static function ($v): string {
        return is_scalar($v) ? trim((string)$v) : '';
    };
    $r = [];
    foreach (SSP_RESULT_FIELDS as $f) {
        $v = $str($src[$f] ?? '');
        if ($v !== '') {
            $r[$f] = $v;
        }
    }
    if (!empty($src['mark_graduated'])) {
        $r['mark_graduated'] = true;
    }
    if (($r['semester'] ?? '') === '') {
        $errors[$prefix . 'semester'] = 'Completion semester is required, e.g. "Fall 2024".';
    }
    if (!isset($r['cgpa']) || !is_numeric($r['cgpa']) || (float)$r['cgpa'] < 0.01 || (float)$r['cgpa'] > 4.0) {
        $errors[$prefix . 'cgpa'] = 'CGPA must be a number between 0.01 and 4.00 (incomplete / withheld results cannot be published).';
    } else {
        $r['cgpa'] = round((float)$r['cgpa'], 2);
    }
    if (isset($r['recorded_date'])) {
        if (!ssp_valid_date($r['recorded_date'])) {
            $errors[$prefix . 'recorded_date'] = 'Use the format YYYY-MM-DD.';
        } elseif ($r['recorded_date'] > date('Y-m-d')) {
            $errors[$prefix . 'recorded_date'] = 'Publish date cannot be in the future.';
        }
    }
    return $r;
}

// ── Photo storage ───────────────────────────────────────────────────────────────────────────

function ssp_photo_dir(): string
{
    return SSP_ROOT . '/storage/photos';
}

function ssp_photo_file(string $name): string
{
    return ssp_photo_dir() . '/' . basename($name);
}

function ssp_photo_mime(string $file): ?string
{
    if (!is_file($file)) {
        return null;
    }
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($file);
    return isset(SSP_PHOTO_MIMES[$mime]) ? $mime : null;
}

/**
 * Validate and store an uploaded photo. Returns the stored file name, or null
 * when nothing was uploaded / the upload was rejected ($errors['photo'] set).
 */
function ssp_store_photo_upload(?array $file, array &$errors): ?string
{
    if (!$file || !isset($file['error']) || (int)$file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        $errors['photo'] = 'The photo could not be uploaded (error code ' . (int)$file['error'] . ').';
        return null;
    }
    $max = (int)ssp_config('photo_max_bytes', 5 * 1024 * 1024);
    if ((int)$file['size'] > $max) {
        $errors['photo'] = 'Photo is too large; maximum ' . round($max / 1048576, 1) . ' MB.';
        return null;
    }
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
    if (!isset(SSP_PHOTO_MIMES[$mime])) {
        $errors['photo'] = 'Photo must be a JPG, PNG, GIF or WEBP image.';
        return null;
    }
    $dir = ssp_photo_dir();
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        $errors['photo'] = 'Photo storage folder is not writable (storage/photos).';
        return null;
    }
    $name = date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . SSP_PHOTO_MIMES[$mime];
    if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $name)) {
        $errors['photo'] = 'Photo could not be saved on the server.';
        return null;
    }
    return $name;
}

function ssp_delete_photo(?string $name): void
{
    if ($name !== null && $name !== '' && is_file(ssp_photo_file($name))) {
        @unlink(ssp_photo_file($name));
    }
}
