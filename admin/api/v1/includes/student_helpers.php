<?php
/**
 * Third-party Student API v1 – shared helpers
 * ============================================
 * Reference-data lookups, input normalisation, student-ID generation and
 * photo storage used by the endpoints under admin/api/v1/.
 *
 * NOTE: capi_generate_student_id() and the photo rules deliberately mirror
 * admin/students/helpers.php.  That file bootstraps the admin session
 * (includes/auth.php) so it cannot be loaded from a stateless API. Keep the
 * two implementations in sync if the ID format or upload rules ever change.
 */

const CAPI_PHOTO_MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
const CAPI_PHOTO_MAX   = 5 * 1024 * 1024; // 5 MB, same as the admin form

const CAPI_SECTIONS       = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
const CAPI_SHIFTS         = ['Morning', 'Day', 'Evening'];
const CAPI_SEXES          = ['Male', 'Female', 'Other'];
const CAPI_STATUSES       = ['Active', 'Inactive', 'Graduated', 'Dropped', 'Not Admitted Yet'];
const CAPI_BLOOD_GROUPS   = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
const CAPI_SEMESTER_TYPES = ['bi_semester', 'trimester'];
const CAPI_TERMS          = ['Spring', 'Summer', 'Fall'];
const CAPI_MAX_QUALIFICATIONS = 10;

// ── Reference data ───────────────────────────────────────────────────────────

/** Active departments: id, name, code, faculty_label. */
function capi_departments(): array
{
    static $rows = null;
    if ($rows === null) {
        $rows = db()->query(
            'SELECT id, name, code, faculty_label FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
        )->fetchAll();
    }
    return $rows;
}

/** Active academic programs: id, dept_id, program_name, program_type. */
function capi_programs(): array
{
    static $rows = null;
    if ($rows === null) {
        $rows = db()->query(
            'SELECT id, dept_id, program_name, program_type FROM dept_academic_programs WHERE is_active = 1 ORDER BY program_name ASC'
        )->fetchAll();
    }
    return $rows;
}

/** Resolve a department by numeric id, code or exact name (case-insensitive). */
function capi_resolve_department(mixed $input): ?array
{
    if ($input === null || is_array($input)) {
        return null;
    }
    $needle = trim((string)$input);
    if ($needle === '') {
        return null;
    }
    $by_id = ctype_digit($needle);
    foreach (capi_departments() as $d) {
        if ($by_id && (int)$d['id'] === (int)$needle) {
            return $d;
        }
        if (!$by_id && (strcasecmp((string)$d['code'], $needle) === 0 || strcasecmp((string)$d['name'], $needle) === 0)) {
            return $d;
        }
    }
    return null;
}

/**
 * Resolve a program by numeric id or exact name.  When several departments
 * share a program name, the one belonging to $dept_id wins.
 */
function capi_resolve_program(mixed $input, int $dept_id = 0): ?array
{
    if ($input === null || is_array($input)) {
        return null;
    }
    $needle = trim((string)$input);
    if ($needle === '') {
        return null;
    }
    $by_id   = ctype_digit($needle);
    $matches = [];
    foreach (capi_programs() as $p) {
        if ($by_id) {
            if ((int)$p['id'] === (int)$needle) {
                return $p;
            }
            continue;
        }
        if (strcasecmp((string)$p['program_name'], $needle) === 0) {
            $matches[] = $p;
        }
    }
    if (!$matches) {
        return null;
    }
    foreach ($matches as $p) {
        if ($dept_id > 0 && (int)$p['dept_id'] === $dept_id) {
            return $p;
        }
    }
    return $matches[0];
}

/** Lookup rows for 'exam' | 'board' | 'group' (id, name, short_name). */
function capi_lookup_rows(string $kind): array
{
    static $cache = [];
    if (!isset($cache[$kind])) {
        $sql = match ($kind) {
            'exam'  => 'SELECT id, name, short_name FROM student_exam_titles WHERE is_active = 1 ORDER BY sort_order, name ASC',
            'board' => 'SELECT id, name, short_name FROM student_boards      WHERE is_active = 1 ORDER BY sort_order, name ASC',
            'group' => 'SELECT id, name, NULL AS short_name FROM student_groups WHERE is_active = 1 ORDER BY sort_order, name ASC',
            default => throw new InvalidArgumentException('Unknown lookup kind: ' . $kind),
        };
        $cache[$kind] = db()->query($sql)->fetchAll();
    }
    return $cache[$kind];
}

/**
 * Resolve an exam title / board / group from an explicit id or a free-text
 * name.  A name that matches a known row (name or short_name) is linked to
 * that row; otherwise it is kept as free text, exactly like the admin form.
 *
 * @return array{id:?int,name:?string,error:?string}
 */
function capi_resolve_lookup(string $kind, mixed $id, string $name): array
{
    $label = ['exam' => 'exam title', 'board' => 'board/university', 'group' => 'academic group'][$kind] ?? $kind;
    $rows  = capi_lookup_rows($kind);

    if ($id !== null && $id !== '' && !is_array($id)) {
        if (!ctype_digit((string)$id) || (int)$id <= 0) {
            return ['id' => null, 'name' => null, 'error' => 'Invalid ' . $label . ' id.'];
        }
        foreach ($rows as $r) {
            if ((int)$r['id'] === (int)$id) {
                return ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'error' => null];
            }
        }
        return ['id' => null, 'name' => null, 'error' => 'Unknown ' . $label . ' id ' . (int)$id . '. See GET /v1/reference-data.php.'];
    }

    if ($name === '') {
        return ['id' => null, 'name' => null, 'error' => null];
    }
    foreach ($rows as $r) {
        if (strcasecmp((string)$r['name'], $name) === 0
            || ($r['short_name'] !== null && strcasecmp((string)$r['short_name'], $name) === 0)) {
            return ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'error' => null];
        }
    }
    return ['id' => null, 'name' => $name, 'error' => null];
}

// ── Normalisation / validation ───────────────────────────────────────────────

/**
 * Accepts "Spring 2026", "spring-2026", "2026 Fall", "Autumn 2026" … and
 * returns the canonical "<Term> <YYYY>" string, or null when invalid.
 */
function capi_normalize_semester(string $raw): ?string
{
    $raw = trim($raw);
    if (preg_match('/^(spring|summer|fall|autumn)[\s\-_\/,]*(\d{4})$/i', $raw, $m)) {
        $term = $m[1];
        $year = (int)$m[2];
    } elseif (preg_match('/^(\d{4})[\s\-_\/,]*(spring|summer|fall|autumn)$/i', $raw, $m)) {
        $term = $m[2];
        $year = (int)$m[1];
    } else {
        return null;
    }
    $term = ucfirst(strtolower($term));
    if ($term === 'Autumn') {
        $term = 'Fall';
    }
    if ($year < 2000 || $year > (int)date('Y') + 2) {
        return null;
    }
    return $term . ' ' . $year;
}

/** Case-insensitive match against an allowed list; returns the canonical value. */
function capi_normalize_enum(string $value, array $allowed): ?string
{
    $v = preg_replace('/\s+/', ' ', trim($value));
    foreach ($allowed as $a) {
        if (strcasecmp($a, $v) === 0) {
            return $a;
        }
    }
    return null;
}

/** Strict YYYY-MM-DD check. */
function capi_valid_date(string $date): bool
{
    $d = DateTime::createFromFormat('!Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}

/** Loose international phone check: optional +, 6-20 digits/spaces/dashes/brackets. */
function capi_valid_phone(string $phone): bool
{
    return preg_match('/^\+?[0-9][0-9\s\-()]{5,19}$/', $phone) === 1;
}

// ── Student ID generator (mirrors admin/students/helpers.php) ────────────────

/** Summer=01, Fall=02, Spring=03 */
function capi_semester_code(string $semester): string
{
    $sem = strtolower(explode(' ', trim($semester))[0] ?? '');
    return match ($sem) {
        'summer' => '01',
        'fall'   => '02',
        'spring' => '03',
        default  => '00',
    };
}

/** "Summer 2025" → "25" */
function capi_semester_year(string $semester): string
{
    $parts = explode(' ', trim($semester));
    $year  = end($parts);
    return str_pad(substr($year, -2), 2, '0', STR_PAD_LEFT);
}

/**
 * Generate the next 12-digit student ID: [YY][SS][DD][PP][NNNN]
 *   YY = admission year, SS = semester code, DD = dept id, PP = program id,
 *   NNNN = sequence within that prefix.
 */
function capi_generate_student_id(string $admitted_semester, int $dept_id, int $program_id = 0): string
{
    $prefix = capi_semester_year($admitted_semester)
            . capi_semester_code($admitted_semester)
            . str_pad((string)$dept_id,    2, '0', STR_PAD_LEFT)
            . str_pad((string)$program_id, 2, '0', STR_PAD_LEFT);

    $stmt = db()->prepare(
        'SELECT student_id FROM students WHERE student_id LIKE ? ORDER BY student_id DESC LIMIT 1'
    );
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $seq  = $last ? (int)substr((string)$last, -4) + 1 : 1;

    return $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}

function capi_student_id_exists(string $student_id): bool
{
    $stmt = db()->prepare('SELECT 1 FROM students WHERE student_id = ? LIMIT 1');
    $stmt->execute([$student_id]);
    return (bool)$stmt->fetchColumn();
}

// ── Photo handling ───────────────────────────────────────────────────────────
// Validation and storage are split so nothing is written to disk until the
// whole request has passed validation.

/**
 * Validate an uploaded photo ($_FILES entry) without moving it.
 *
 * @return array{0:?array,1:?string} [prepared photo, error message]
 */
function capi_photo_from_upload(array $file): array
{
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return [null, 'Photo exceeds the upload size limit (max 5 MB).'];
    }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
        return [null, 'Photo upload failed.'];
    }
    if ((int)$file['size'] > CAPI_PHOTO_MAX) {
        return [null, 'Photo must be 5 MB or smaller.'];
    }
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(CAPI_PHOTO_MIMES[$mime])) {
        return [null, 'Photo must be a JPG, PNG, GIF or WEBP image.'];
    }
    return [['tmp_path' => $file['tmp_name'], 'bytes' => null, 'ext' => CAPI_PHOTO_MIMES[$mime]], null];
}

/**
 * Validate a base64 (optionally data-URI) encoded photo.
 *
 * @return array{0:?array,1:?string}
 */
function capi_photo_from_base64(string $data): array
{
    $data = trim($data);
    if (preg_match('/^data:image\/[a-z0-9.+-]+;base64,/i', $data, $m)) {
        $data = substr($data, strlen($m[0]));
    }
    // Base64 inflates by ~4/3: refuse obviously oversized input before decoding.
    if (strlen($data) > (int)(CAPI_PHOTO_MAX * 1.37) + 1024) {
        return [null, 'Photo must be 5 MB or smaller.'];
    }
    $bytes = base64_decode(preg_replace('/\s+/', '', $data), true);
    if ($bytes === false || $bytes === '') {
        return [null, 'photo_base64 is not valid base64 data.'];
    }
    if (strlen($bytes) > CAPI_PHOTO_MAX) {
        return [null, 'Photo must be 5 MB or smaller.'];
    }
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    if (!isset(CAPI_PHOTO_MIMES[$mime])) {
        return [null, 'Photo must be a JPG, PNG, GIF or WEBP image.'];
    }
    return [['tmp_path' => null, 'bytes' => $bytes, 'ext' => CAPI_PHOTO_MIMES[$mime]], null];
}

/** Write a prepared photo to admin/uploads/students/photos and return its stored name. */
function capi_photo_commit(array $photo): string
{
    $dir = UPLOAD_DIR . '/students/photos';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Photo directory is not writable.');
    }
    $name = bin2hex(random_bytes(12)) . '.' . $photo['ext'];
    $dest = $dir . '/' . $name;
    $ok   = $photo['tmp_path'] !== null
        ? move_uploaded_file($photo['tmp_path'], $dest)
        : (file_put_contents($dest, $photo['bytes']) !== false);
    if (!$ok) {
        throw new RuntimeException('Photo could not be stored.');
    }
    return $name;
}

function capi_photo_delete(?string $stored_name): void
{
    if ($stored_name) {
        @unlink(UPLOAD_DIR . '/students/photos/' . $stored_name);
    }
}

function capi_photo_url(?string $stored_name): ?string
{
    return $stored_name ? UPLOAD_URL . '/students/photos/' . $stored_name : null;
}
