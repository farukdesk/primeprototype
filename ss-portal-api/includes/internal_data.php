<?php
/**
 * SS Portal – internal (portal-only) data attached to a student.
 *
 * Flags / texts live in ssp_students.internal_json and the uploaded documents
 * in ssp_student_files + storage/files/.  Both are kept completely separate
 * from payload_json, so nothing in this file is ever sent to Prime University.
 */

require_once __DIR__ . '/bootstrap.php';

/** Yes / No flags: key => label. Stored as 'yes' | 'no'; absent = not set. */
const SSP_INTERNAL_FLAGS = [
    'apostille'   => 'Apostille',
    'online_only' => 'Online only',
    'work_done'   => 'Work done',
];
/** Free-text fields: key => max length. */
const SSP_INTERNAL_TEXTS = ['reference' => 100, 'notes' => 2000];
/** Suggestions for the Reference field (any other text is accepted). */
const SSP_INTERNAL_REFERENCES = ['Bindu', 'Sir'];

/** Document slots: key => label. */
const SSP_FILE_KINDS = [
    'admission_form' => 'Admission form',
    'ssc'            => 'SSC',
    'hsc'            => 'HSC',
    'certificate'    => 'Certificate',
    'transcript'     => 'Transcript',
    'tabulation'     => 'Tabulation',
    'other'          => 'Other files',
];
const SSP_FILE_MIMES = [
    'application/pdf'    => 'pdf',
    'image/jpeg'         => 'jpg',
    'image/png'          => 'png',
    'image/gif'          => 'gif',
    'image/webp'         => 'webp',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
];
const SSP_FILE_ACCEPT    = '.pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx';
const SSP_FILES_PER_KIND = 10;

// ── Flags / texts ──────────────────────────────────────────────────────────────────────────

function ssp_internal_decode(?string $json): array
{
    $d = $json !== null && $json !== '' ? json_decode($json, true) : null;
    return is_array($d) ? $d : [];
}

/**
 * Build the internal data array from a submitted form ($_POST['internal']).
 * Blank values are dropped; $errors is filled with 'internal.<key>' => message.
 */
function ssp_build_internal_data(array $src, array &$errors): array
{
    $src  = is_array($src['internal'] ?? null) ? $src['internal'] : [];
    $data = [];
    foreach (SSP_INTERNAL_FLAGS as $k => $label) {
        $v = is_scalar($src[$k] ?? null) ? strtolower(trim((string)$src[$k])) : '';
        if ($v === 'yes' || $v === 'no') {
            $data[$k] = $v;
        } elseif ($v !== '') {
            $errors['internal.' . $k] = $label . ' must be Yes or No.';
        }
    }
    foreach (SSP_INTERNAL_TEXTS as $k => $max) {
        $v = is_scalar($src[$k] ?? null) ? trim((string)$src[$k]) : '';
        if ($v === '') {
            continue;
        }
        if (mb_strlen($v) > $max) {
            $errors['internal.' . $k] = 'At most ' . $max . ' characters.';
        }
        $data[$k] = $v;
    }
    return $data;
}

/** Small Yes / No / not-set badge for read-only pages. */
function ssp_yes_no_html(?string $v): string
{
    if ($v === 'yes') {
        return '<span class="badge badge-synced">Yes</span>';
    }
    if ($v === 'no') {
        return '<span class="badge badge-draft">No</span>';
    }
    return '<span class="muted">—</span>';
}

// ── Documents ──────────────────────────────────────────────────────────────────────────────

function ssp_files_dir(): string
{
    return SSP_ROOT . '/storage/files';
}

function ssp_file_path(string $storedName): string
{
    return ssp_files_dir() . '/' . basename($storedName);
}

function ssp_file_max_bytes(): int
{
    return (int)ssp_config('file_max_bytes', 10 * 1024 * 1024);
}

function ssp_file_size_human(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024) . ' KB';
    }
    return $bytes . ' B';
}

/** All documents of a student, ordered by slot then upload order. */
function ssp_student_files(int $studentId): array
{
    if ($studentId <= 0) {
        return [];
    }
    $st = ssp_db()->prepare('SELECT f.*, u.full_name AS uploaded_by_name
                               FROM ssp_student_files f LEFT JOIN ssp_users u ON u.id = f.uploaded_by
                              WHERE f.student_id = ? ORDER BY f.kind, f.id');
    $st->execute([$studentId]);
    return $st->fetchAll();
}

function ssp_student_file_find(int $fileId): ?array
{
    if ($fileId <= 0) {
        return null;
    }
    $st = ssp_db()->prepare('SELECT * FROM ssp_student_files WHERE id = ? LIMIT 1');
    $st->execute([$fileId]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Flatten $_FILES['files'] (inputs named files[<kind>][], multiple) into a list of
 * ['kind', 'name', 'tmp_name', 'error', 'size'].  Empty slots are skipped.
 */
function ssp_normalize_file_uploads(?array $files): array
{
    if (!$files || !isset($files['name']) || !is_array($files['name'])) {
        return [];
    }
    $out = [];
    foreach ($files['name'] as $kind => $names) {
        if (!isset(SSP_FILE_KINDS[$kind])) {
            continue;
        }
        foreach ((array)$names as $i => $name) {
            $err = (int)(is_array($files['error'][$kind] ?? null) ? ($files['error'][$kind][$i] ?? UPLOAD_ERR_NO_FILE) : ($files['error'][$kind] ?? UPLOAD_ERR_NO_FILE));
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $tmp  = is_array($files['tmp_name'][$kind] ?? null) ? ($files['tmp_name'][$kind][$i] ?? '') : ($files['tmp_name'][$kind] ?? '');
            $size = is_array($files['size'][$kind] ?? null) ? ($files['size'][$kind][$i] ?? 0) : ($files['size'][$kind] ?? 0);
            $out[] = ['kind' => (string)$kind, 'name' => (string)$name, 'tmp_name' => (string)$tmp, 'error' => $err, 'size' => (int)$size];
        }
    }
    return $out;
}

/**
 * Validate uploads WITHOUT moving them (so the student can be rejected before
 * anything is written).  Fills $errors['files.<kind>'].
 */
function ssp_validate_file_uploads(array $uploads, array $existingFiles, array $removeIds, array &$errors): void
{
    if (!$uploads) {
        return;
    }
    $counts = [];
    foreach ($existingFiles as $f) {
        if (!in_array((int)$f['id'], $removeIds, true)) {
            $counts[$f['kind']] = ($counts[$f['kind']] ?? 0) + 1;
        }
    }
    $max   = ssp_file_max_bytes();
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    foreach ($uploads as $u) {
        $key = 'files.' . $u['kind'];
        $counts[$u['kind']] = ($counts[$u['kind']] ?? 0) + 1;
        if ($counts[$u['kind']] > SSP_FILES_PER_KIND) {
            $errors[$key] = 'At most ' . SSP_FILES_PER_KIND . ' files per slot.';
            continue;
        }
        if ($u['error'] !== UPLOAD_ERR_OK) {
            $errors[$key] = '"' . $u['name'] . '" could not be uploaded (error code ' . $u['error'] . ').';
            continue;
        }
        if ($u['size'] > $max) {
            $errors[$key] = '"' . $u['name'] . '" is too large; maximum ' . round($max / 1048576, 1) . ' MB per file.';
            continue;
        }
        $mime = $u['tmp_name'] !== '' && is_file($u['tmp_name']) ? (string)$finfo->file($u['tmp_name']) : '';
        if (!isset(SSP_FILE_MIMES[$mime])) {
            $errors[$key] = '"' . $u['name'] . '" must be a PDF, JPG, PNG, GIF, WEBP, DOC or DOCX file.';
        }
    }
}

/**
 * Move already-validated uploads into storage/files and record them.
 * Returns the number of files stored; problems are appended to $errors
 * ('files.<kind>' => message) but never throw.
 */
function ssp_store_student_files(int $studentId, int $userId, array $uploads, array &$errors): int
{
    if (!$uploads || $studentId <= 0) {
        return 0;
    }
    $dir = ssp_files_dir();
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        $errors['files'] = 'File storage folder is not writable (storage/files).';
        return 0;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $ins   = ssp_db()->prepare('INSERT INTO ssp_student_files (student_id, kind, original_name, stored_name, mime, size_bytes, uploaded_by)
                                VALUES (?,?,?,?,?,?,?)');
    $n = 0;
    foreach ($uploads as $u) {
        if ($u['error'] !== UPLOAD_ERR_OK || $u['tmp_name'] === '' || !is_uploaded_file($u['tmp_name'])) {
            continue;
        }
        $mime = (string)$finfo->file($u['tmp_name']);
        if (!isset(SSP_FILE_MIMES[$mime])) {
            continue;
        }
        $stored = date('Ymd') . '-' . $u['kind'] . '-' . bin2hex(random_bytes(8)) . '.' . SSP_FILE_MIMES[$mime];
        if (!move_uploaded_file($u['tmp_name'], $dir . '/' . $stored)) {
            $errors['files.' . $u['kind']] = '"' . $u['name'] . '" could not be saved on the server.';
            continue;
        }
        $orig = mb_substr((string)preg_replace('/[\x00-\x1f\/\\\\]+/u', '_', $u['name']), 0, 255);
        if ($orig === '') {
            $orig = $stored;
        }
        try {
            $ins->execute([$studentId, $u['kind'], $orig, $stored, $mime, $u['size'], $userId]);
            $n++;
        } catch (Throwable $e) {
            @unlink($dir . '/' . $stored);
            error_log('ss-portal student file: ' . $e->getMessage());
            $errors['files.' . $u['kind']] = '"' . $u['name'] . '" could not be recorded.';
        }
    }
    return $n;
}

/** Remove a document (row + file on disk). */
function ssp_delete_student_file(array $file): void
{
    ssp_db()->prepare('DELETE FROM ssp_student_files WHERE id = ?')->execute([(int)$file['id']]);
    $path = ssp_file_path((string)$file['stored_name']);
    if (is_file($path)) {
        @unlink($path);
    }
}
