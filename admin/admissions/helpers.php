<?php
/**
 * Admissions Module – Shared Helpers
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../change-log/helpers.php';

// ── Upload sub-directories (relative to UPLOAD_DIR) ──────────────────────────
define('ADM_UPLOAD_SUBDIR', 'admissions');
define('ADM_PHOTO_SUBDIR',  'admissions/photos');
define('ADM_TPL_SUBDIR',    'admissions/templates');

// ── Size limits ───────────────────────────────────────────────────────────────
define('ADM_MAX_PHOTO', 2097152);   // 2 MB
define('ADM_MAX_TPL',   20971520);  // 20 MB

// ── Allowed extensions / MIME types ──────────────────────────────────────────
define('ADM_PHOTO_EXTS',  ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('ADM_TPL_EXTS',    ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf']);
define('ADM_PHOTO_MIMES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
define('ADM_TPL_MIMES',   ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf']);

// ── Permission helpers ────────────────────────────────────────────────────────

function adm_can_manage(): bool
{
    return is_super_admin() || can_access('admissions', 'can_create');
}

function adm_can_edit(): bool
{
    return is_super_admin() || can_access('admissions', 'can_edit');
}

function adm_can_delete(): bool
{
    return is_super_admin() || can_access('admissions', 'can_delete');
}

// ── Upload helpers ────────────────────────────────────────────────────────────

/**
 * Validate and store an applicant photo.
 * Returns the stored filename (relative to ADM_PHOTO_SUBDIR) or false on failure.
 */
function adm_upload_photo(array $file): string|false
{
    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }
    if ($file['size'] > ADM_MAX_PHOTO) {
        flash_set('error', 'Photo exceeds 2 MB limit.');
        return false;
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ADM_PHOTO_EXTS, true)) {
        flash_set('error', 'Invalid photo file type.');
        return false;
    }
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, ADM_PHOTO_MIMES, true)) {
        flash_set('error', 'Invalid photo MIME type.');
        return false;
    }
    $dir = UPLOAD_DIR . '/' . ADM_PHOTO_SUBDIR;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $stored = uniqid('photo_', true) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) {
        flash_set('error', 'Failed to save photo.');
        return false;
    }
    return $stored;
}

/**
 * Validate and store a template file (image or PDF).
 * Returns the stored filename (relative to ADM_TPL_SUBDIR) or false on failure.
 */
function adm_upload_template(array $file): string|false
{
    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }
    if ($file['size'] > ADM_MAX_TPL) {
        flash_set('error', 'Template file exceeds 20 MB limit.');
        return false;
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ADM_TPL_EXTS, true)) {
        flash_set('error', 'Invalid template file type.');
        return false;
    }
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, ADM_TPL_MIMES, true)) {
        flash_set('error', 'Invalid template MIME type.');
        return false;
    }
    $dir = UPLOAD_DIR . '/' . ADM_TPL_SUBDIR;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $stored = uniqid('tpl_', true) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) {
        flash_set('error', 'Failed to save template file.');
        return false;
    }
    return $stored;
}

// ── Settings helpers ──────────────────────────────────────────────────────────

function adm_get_setting(string $key, string $default = ''): string
{
    $stmt = db()->prepare('SELECT setting_value FROM admissions_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return ($val !== false) ? (string)$val : $default;
}

function adm_save_setting(string $key, string $value): void
{
    db()->prepare(
        'INSERT INTO admissions_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    )->execute([$key, $value]);
}

// ── Student ID helpers ────────────────────────────────────────────────────────

/**
 * Fetch student-ID settings for a given program.
 * Returns false if no settings row exists for that program.
 */
function adm_sid_get(int $program_id): array|false
{
    $stmt = db()->prepare(
        'SELECT * FROM adm_student_id_settings WHERE program_id = ? LIMIT 1'
    );
    $stmt->execute([$program_id]);
    return $stmt->fetch() ?: false;
}

/**
 * Generate a student ID for the given program and increment the serial counter.
 * Returns an empty string if no settings exist for the program.
 */
function adm_sid_generate(int $program_id): string
{
    $db = db();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            'SELECT * FROM adm_student_id_settings WHERE program_id = ? FOR UPDATE'
        );
        $stmt->execute([$program_id]);
        $settings = $stmt->fetch();
        if (!$settings) {
            $db->rollBack();
            return '';
        }
        $serial = (int)$settings['next_serial'];
        $digits = max(1, (int)$settings['serial_digits']);
        $db->prepare(
            'UPDATE adm_student_id_settings SET next_serial = next_serial + 1 WHERE program_id = ?'
        )->execute([$program_id]);
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return $settings['university_code']
         . $settings['year_code']
         . $settings['semester_code']
         . $settings['faculty_code']
         . $settings['subject_code']
         . $settings['type_of_program']
         . str_pad((string)$serial, $digits, '0', STR_PAD_LEFT);
}

/**
 * Preview a student ID from settings without incrementing the serial.
 */
function adm_sid_preview(array $settings): string
{
    $digits = max(1, (int)$settings['serial_digits']);
    return $settings['university_code']
         . $settings['year_code']
         . $settings['semester_code']
         . $settings['faculty_code']
         . $settings['subject_code']
         . $settings['type_of_program']
         . str_pad((string)$settings['next_serial'], $digits, '0', STR_PAD_LEFT);
}

// ── SMS helper ────────────────────────────────────────────────────────────────

/**
 * Send an SMS via the FastSMS BD API.
 * Uses sms_api_key and sms_sender_id from admissions_settings.
 * Returns true on success, false on failure or if SMS is disabled.
 */
function adm_send_sms(string $mobile, string $message): bool
{
    if (adm_get_setting('sms_enabled', '0') !== '1') {
        return false;
    }
    $api_key   = adm_get_setting('sms_api_key', '');
    $sender_id = adm_get_setting('sms_sender_id', '');
    if ($api_key === '' || $sender_id === '' || $mobile === '') {
        return false;
    }

    // Normalize to Bangladesh international format (880…)
    $mobile = preg_replace('/\D/', '', $mobile);
    if (str_starts_with($mobile, '0')) {
        $mobile = '880' . substr($mobile, 1);
    }

    $url = 'https://smsapi.fastsmsbd.com/smsapiv3?' . http_build_query([
        'apikey'  => $api_key,
        'sender'  => $sender_id,
        'msisdn'  => $mobile,
        'smstext' => $message,
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    $errno    = curl_errno($ch);
    curl_close($ch);

    return ($errno === 0 && $response !== false);
}

/**
 * Replace {{variable}} placeholders in a notification template string.
 */
function adm_render_template_string(string $template, array $vars): string
{
    $search  = [];
    $replace = [];
    foreach ($vars as $key => $val) {
        $search[]  = '{{' . $key . '}}';
        $replace[] = (string)$val;
    }
    return str_replace($search, $replace, $template);
}

// ── Application number generator ─────────────────────────────────────────────

function adm_generate_number(): string
{
    $db   = db();
    // Use a transaction to atomically read-and-increment the counter
    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            'SELECT setting_value FROM admissions_settings WHERE setting_key = ? FOR UPDATE'
        );
        $stmt->execute(['next_form_number']);
        $current = (int)($stmt->fetchColumn() ?: 1);

        $db->prepare(
            'INSERT INTO admissions_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        )->execute(['next_form_number', (string)($current + 1)]);

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return (string)$current;
}

// ── Status badge ──────────────────────────────────────────────────────────────

function adm_status_badge(string $status): string
{
    $map = [
        'draft'               => ['bg-secondary', 'Draft'],
        'ready_for_admission' => ['bg-success',   'Ready for Admission'],
        'cancelled'           => ['bg-danger',     'Cancelled'],
    ];
    [$cls, $label] = $map[$status] ?? ['bg-secondary', ucfirst(str_replace('_', ' ', $status))];
    return '<span class="badge ' . $cls . '">' . h($label) . '</span>';
}

// ── Fetch helpers ─────────────────────────────────────────────────────────────

function adm_get(int $id): array
{
    $stmt = db()->prepare(
        'SELECT a.*,
                d.name         AS dept_name,
                p.program_name
         FROM admissions_applications a
         LEFT JOIN dept_departments d        ON d.id = a.dept_id
         LEFT JOIN dept_academic_programs p  ON p.id = a.program_id
         WHERE a.id = ?'
    );
    $stmt->execute([$id]);
    $app = $stmt->fetch();
    if (!$app) {
        flash_set('error', 'Application not found.');
        redirect(APP_URL . '/admissions/index.php');
    }
    return $app;
}

function adm_get_academic_records(int $app_id): array
{
    $stmt = db()->prepare(
        'SELECT * FROM admissions_academic_records
         WHERE application_id = ?
         ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([$app_id]);
    return $stmt->fetchAll();
}

function adm_get_all_fields(): array
{
    return db()->query(
        'SELECT * FROM admissions_fields ORDER BY sort_order ASC'
    )->fetchAll();
}

function adm_get_mappings(int $page_number): array
{
    $stmt = db()->prepare(
        'SELECT * FROM admissions_field_mappings WHERE page_number = ?'
    );
    $stmt->execute([$page_number]);
    $rows = $stmt->fetchAll();
    $indexed = [];
    foreach ($rows as $row) {
        $indexed[$row['field_key']] = $row;
    }
    return $indexed;
}

function adm_get_template(int $page_number): array|false
{
    $stmt = db()->prepare(
        'SELECT * FROM admissions_templates WHERE page_number = ?'
    );
    $stmt->execute([$page_number]);
    return $stmt->fetch() ?: false;
}

// ── Field value resolver ──────────────────────────────────────────────────────

/**
 * Resolve a mapped field key to its printable string value for the given application.
 * $acad_records should be the indexed array from adm_get_academic_records() keyed 0-based.
 */
function adm_field_value(array $app, string $field_key, array $acad_records = []): string
{
    // Semester tick fields
    if ($field_key === 'semester_spring') {
        $sems = array_map('trim', explode(',', strtolower($app['semester'] ?? '')));
        return in_array('spring', $sems, true) ? '✓' : '';
    }
    if ($field_key === 'semester_summer') {
        $sems = array_map('trim', explode(',', strtolower($app['semester'] ?? '')));
        return in_array('summer', $sems, true) ? '✓' : '';
    }
    if ($field_key === 'semester_fall') {
        $sems = array_map('trim', explode(',', strtolower($app['semester'] ?? '')));
        return in_array('fall', $sems, true) ? '✓' : '';
    }

    // Sex tick fields
    if ($field_key === 'sex_male') {
        return ($app['sex'] ?? '') === 'Male' ? '✓' : '';
    }
    if ($field_key === 'sex_female') {
        return ($app['sex'] ?? '') === 'Female' ? '✓' : '';
    }

    // Expelled tick fields
    if ($field_key === 'expelled_yes') {
        return ($app['expelled_answer'] ?? 'No') === 'Yes' ? '✓' : '';
    }
    if ($field_key === 'expelled_no') {
        return ($app['expelled_answer'] ?? 'No') === 'No' ? '✓' : '';
    }

    // Current date
    if ($field_key === 'current_date') {
        return date('d/m/Y');
    }

    // Academic qualification fields: qual_{n}_{column}
    if (preg_match('/^qual_([1-5])_(.+)$/', $field_key, $m)) {
        $idx = (int)$m[1] - 1; // 0-based
        $col = $m[2];
        $col_map = [
            'exam_name' => 'exam_name',
            'session'   => 'session',
            'group'     => 'group_name',
            'board'     => 'board_university',
            'year'      => 'year_of_passing',
            'grade'     => 'division_grade',
            'marks'     => 'total_marks_cgpa',
        ];
        $db_col = $col_map[$col] ?? $col;
        return (string)($acad_records[$idx][$db_col] ?? '');
    }

    // Photo: return stored filename (print.php handles rendering)
    if ($field_key === 'photo') {
        return (string)($app['photo'] ?? '');
    }

    switch ($field_key) {
        case 'department':
            return (string)($app['dept_name'] ?? '');
        case 'program':
            return (string)($app['program_name'] ?? '');
        case 'app_number':
            return (string)($app['app_number'] ?? '');
        case 'date_of_birth':
            if (!empty($app['date_of_birth'])) {
                $dt = DateTime::createFromFormat('Y-m-d', $app['date_of_birth']);
                return $dt ? $dt->format('d/m/Y') : (string)$app['date_of_birth'];
            }
            return '';
        case 'sex':
            $s = $app['sex'] ?? '';
            if ($s === 'Male')   return 'M';
            if ($s === 'Female') return 'F';
            return (string)$s;
        default:
            return (string)($app[$field_key] ?? '');
    }
}
