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

// ── Application number generator ─────────────────────────────────────────────

function adm_generate_number(): string
{
    $year = date('Y');
    $pfx  = 'ADM-' . $year . '-';
    $stmt = db()->prepare(
        "SELECT app_number FROM admissions_applications WHERE app_number LIKE ? ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$pfx . '%']);
    $last = $stmt->fetchColumn();
    $seq  = $last ? (int)substr($last, strrpos($last, '-') + 1) + 1 : 1;
    return $pfx . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}

// ── Status badge ──────────────────────────────────────────────────────────────

function adm_status_badge(string $status): string
{
    $map = [
        'draft'     => ['bg-secondary',   'Draft'],
        'submitted' => ['bg-primary',     'Submitted'],
        'approved'  => ['bg-success',     'Approved'],
        'rejected'  => ['bg-danger',      'Rejected'],
    ];
    [$cls, $label] = $map[$status] ?? ['bg-secondary', ucfirst($status)];
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

function adm_field_value(array $app, string $field_key): string
{
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
