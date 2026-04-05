<?php
/**
 * Shared helpers for faculty-profiles admin pages.
 */

require_once __DIR__ . '/../change-log/helpers.php';

// ── Allowed file types for faculty document files ─────────────────────────────
const FP_FILE_EXTS  = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'txt'];
const FP_FILE_MIMES = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'application/zip', 'application/x-zip-compressed',
    'text/plain',
];
const FP_FILE_MAX = 20 * 1024 * 1024; // 20 MB

// ── Permission helpers ────────────────────────────────────────────────────────

/**
 * Returns true if the current user belongs to the Register Office group.
 */
function fp_is_register_office(): bool
{
    $user = auth_user();
    return isset($user['group_name']) && $user['group_name'] === 'Register Office';
}

/**
 * Returns true if current user can manage (add/delete) faculty files.
 * Only super admins and Register Office staff can manage files.
 */
function fp_can_manage_files(): bool
{
    return is_super_admin() || (fp_is_register_office() && can_access('faculty-files', 'can_create'));
}

/**
 * Returns true if the current user can see the given file record.
 * Internal files are hidden from the owning faculty member,
 * but visible to: the uploader, super admins, and Register Office staff.
 *
 * @param array $file  A row from faculty_files (must include is_internal, uploaded_by).
 */
function fp_can_view_file(array $file): bool
{
    if (!$file['is_internal']) {
        return true; // public file – everyone with page access can see it
    }
    $user = auth_user();
    return is_super_admin()
        || fp_is_register_office()
        || ((int)$file['uploaded_by'] === (int)$user['id']);
}

/**
 * Returns true if the current user can request deletion of faculty files.
 * Register Office staff (with can_delete) and uploaders may submit delete
 * requests; super admins may delete directly without queuing.
 */
function fp_can_request_delete(): bool
{
    return is_super_admin() || (fp_is_register_office() && can_access('faculty-files', 'can_delete'));
}

/**
 * Returns true if current user can delete faculty files.
 * Kept for backward-compatibility; now only super admins delete directly —
 * others must go through the approval workflow (fp_can_request_delete()).
 */
function fp_can_delete_files(): bool
{
    return is_super_admin() || (fp_is_register_office() && can_access('faculty-files', 'can_delete'));
}

/**
 * Returns true if the current user can view/manage pending faculty registrations.
 */
function fp_can_manage_pending(): bool
{
    return is_super_admin() || can_access('faculty-pending', 'can_edit');
}

// ── Upload helpers ────────────────────────────────────────────────────────────

/**
 * Upload a file for the faculty-profiles module (photo, CV, etc.).
 * Returns the generated filename on success, false on failure.
 */
function fp_upload_file(array $file, array $allowed_exts, array $allowed_mimes): string|false {
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_exts, true)) return false;
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed_mimes, true)) return false;
    $dir = UPLOAD_DIR . '/faculty-profiles';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return false;
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return false;
    return $name;
}

/**
 * Upload a document file for the faculty files section.
 * Returns array with file metadata on success, false on failure.
 */
function fp_upload_faculty_file(array $file): array|false {
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    if ($file['size'] > FP_FILE_MAX)      return false;

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, FP_FILE_EXTS, true)) return false;

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, FP_FILE_MIMES, true)) return false;

    $dir = UPLOAD_DIR . '/faculty-profiles/files';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return false;

    $stored = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) return false;

    return [
        'stored_name'   => $stored,
        'original_name' => $file['name'],
        'mime_type'     => $mime,
        'file_size'     => $file['size'],
    ];
}

// ── Badge / display helpers ───────────────────────────────────────────────────

function fp_file_icon(string $ext): string
{
    return match (strtolower($ext)) {
        'pdf'              => 'fas fa-file-pdf text-danger',
        'doc', 'docx'      => 'fas fa-file-word text-primary',
        'xls', 'xlsx'      => 'fas fa-file-excel text-success',
        'ppt', 'pptx'      => 'fas fa-file-powerpoint text-warning',
        'zip'              => 'fas fa-file-archive text-secondary',
        'jpg', 'jpeg',
        'png', 'gif', 'webp' => 'fas fa-file-image text-info',
        'txt'              => 'fas fa-file-alt text-muted',
        default            => 'fas fa-file text-muted',
    };
}

function fp_format_size(int $bytes): string
{
    if ($bytes < 1024)    return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}
