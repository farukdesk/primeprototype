<?php
/**
 * Legacy Photo Import
 *
 * Reads student_user.sql from the site root to build a mapping of old student
 * IDs (student_user_sid column) to photo filenames stored in the upload_spic/
 * folder (path column).  For each student in the new `students` table who has
 * no photo, the script looks up the old mapping and copies the matching file
 * from upload_spic/ into admin/uploads/students/photos/, then updates the
 * students.photo column.
 *
 * Prerequisites
 * ─────────────
 * 1. Place student_user.sql in the site root  (same folder as index.php).
 * 2. Place the upload_spic/ folder in the site root.
 *
 * The import can be run multiple times safely: students that already have a
 * photo are skipped unless "Overwrite existing photos" is checked.
 *
 * ZIP upload mode
 * ───────────────
 * Alternatively, a ZIP file of photos (up to several GB) can be uploaded
 * directly on this page.  Each image filename (without extension) must be the
 * student ID, e.g. "25010101.jpg".  Sub-folder names inside the ZIP are
 * ignored.  Matching is case-insensitive and tolerant of leading zeros.
 */

require_once __DIR__ . '/../includes/auth.php';
require_access('students');
require_once __DIR__ . '/helpers.php';

if (!sm_can_create()) {
    flash_set('error', 'You do not have permission to import photos.');
    redirect(APP_URL . '/students/index.php');
}

set_time_limit(0);
ini_set('memory_limit', '512M');

// ── Paths ─────────────────────────────────────────────────────────────────────
// UPLOAD_DIR = {site_root}/admin/uploads  →  site root is two levels up.
$site_root  = dirname(dirname(UPLOAD_DIR));
$sql_file   = $site_root . '/student_user.sql';
$spic_dir   = $site_root . '/upload_spic';
$photos_dir = UPLOAD_DIR . '/students/photos';

$page_title = 'Legacy Photo Import';
$user       = auth_user();

// ── SQL parser ────────────────────────────────────────────────────────────────

/**
 * Parse one MySQL VALUES row such as "(1, 'foo', NULL, 3.14, ...)".
 *
 * Returns a 0-based array of string|null values, or null if the line does not
 * look like a VALUES row.  Only the columns at $want_indices are populated;
 * all other entries are set to false so the caller can bail early once all
 * wanted columns have been read.
 *
 * @param string $line        A single line from the SQL dump.
 * @param int[]  $want_idxs  Column indices the caller needs.
 * @param int    $stop_after The highest index in $want_idxs; parsing stops here.
 * @return array|null
 */
function lpi_parse_row(string $line, array $want_idxs, int $stop_after): ?array
{
    $line = ltrim($line);
    if ($line === '' || $line[0] !== '(') {
        return null;
    }

    $len    = strlen($line);
    $col    = 0;
    $i      = 1;            // skip opening '('
    $result = [];

    while ($i < $len && $col <= $stop_after) {
        // skip leading whitespace inside the value list
        while ($i < $len && $line[$i] === ' ') {
            $i++;
        }
        if ($i >= $len) {
            break;
        }

        // ── Read the next value ───────────────────────────────────────────────
        $val = null;

        if ($line[$i] === "'") {
            // quoted string
            $i++;
            $buf = '';
            while ($i < $len) {
                $ch = $line[$i];
                if ($ch === '\\' && $i + 1 < $len) {
                    $i++;
                    $buf .= $line[$i];
                    $i++;
                } elseif ($ch === "'") {
                    $i++;
                    break;
                } else {
                    $buf .= $ch;
                    $i++;
                }
            }
            $val = $buf;
        } elseif (substr($line, $i, 4) === 'NULL') {
            $val = null;
            $i  += 4;
        } else {
            // unquoted token (integer, float, etc.)
            $start = $i;
            while ($i < $len && $line[$i] !== ',' && $line[$i] !== ')') {
                $i++;
            }
            $val = trim(substr($line, $start, $i - $start));
        }

        if (in_array($col, $want_idxs, true)) {
            $result[$col] = $val;
        }

        // advance past the separator (comma) to the next value
        while ($i < $len && $line[$i] !== ',' && $line[$i] !== ')') {
            $i++;
        }
        if ($i < $len && $line[$i] === ',') {
            $i++;
        }

        $col++;
    }

    return $result;
}

/**
 * Parse student_user.sql and return an array keyed by student_user_sid.
 * Each value is the bare filename extracted from the path column
 * (e.g. "260820151440581928.jpg" from "upload_spic/260820151440581928.jpg").
 *
 * Entries where the path is empty or does not start with "upload_spic/" are
 * skipped.
 *
 * @return array<string, string>  sid => filename
 */
function lpi_build_map(string $sql_file): array
{
    $map = [];

    $fh = @fopen($sql_file, 'r');
    if ($fh === false) {
        return $map;
    }

    $sid_idx  = -1;
    $path_idx = -1;
    $want     = [];
    $stop     = -1;

    while (($line = fgets($fh)) !== false) {
        $line = rtrim($line, "\r\n");

        // ── Extract column positions from the INSERT header ───────────────────
        if (str_starts_with($line, 'INSERT INTO `student_user`')) {
            // Parse column list between first '(' and first ')'
            $p1 = strpos($line, '(');
            $p2 = strpos($line, ')');
            if ($p1 !== false && $p2 !== false) {
                $cols_raw = substr($line, $p1 + 1, $p2 - $p1 - 1);
                $cols     = array_map(
                    fn($c) => trim(trim($c), '`'),
                    explode(',', $cols_raw)
                );
                $sid_idx  = array_search('student_user_sid', $cols, true);
                $path_idx = array_search('path', $cols, true);
                if ($sid_idx === false || $path_idx === false) {
                    // unexpected schema – stop trying
                    break;
                }
                $want = [(int)$sid_idx, (int)$path_idx];
                $stop = max($sid_idx, $path_idx);
            }
            continue;
        }

        // ── Parse a VALUES row ────────────────────────────────────────────────
        if ($sid_idx === -1 || $line === '' || $line[0] !== '(') {
            continue;
        }

        $row = lpi_parse_row($line, $want, $stop);
        if ($row === null) {
            continue;
        }

        $sid  = trim((string)($row[$sid_idx] ?? ''));
        $path = trim((string)($row[$path_idx] ?? ''));

        if ($sid === '' || $path === '') {
            continue;
        }

        // Extract just the filename from "upload_spic/xxxx.jpg"
        $filename = basename($path);
        if ($filename === '' || $filename === '.') {
            continue;
        }

        // Only keep entries that have a real image extension
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            continue;
        }

        // Normalise key to lowercase-trimmed for case-insensitive lookup.
        // Keep the first occurrence (some students appear in multiple inserts).
        $key = strtolower(trim($sid));
        if ($key !== '' && !isset($map[$key])) {
            $map[$key] = $filename;
        }
    }

    fclose($fh);
    return $map;
}

// ── Processing ────────────────────────────────────────────────────────────────
$assigned    = [];
$overwritten = [];
$skipped_dup = [];
$skipped_no_map  = [];   // student not found in old DB mapping
$skipped_no_file = [];   // mapping found but file missing from upload_spic/
$skipped_nostu   = [];   // ZIP mode: no matching student for this filename
$errors      = [];
$results     = null;     // null = not yet run

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'zip') {
    // ── ZIP upload mode ───────────────────────────────────────────────────────
    csrf_check();

    $overwrite = isset($_POST['overwrite']) && $_POST['overwrite'] === '1';

    if (!class_exists('ZipArchive')) {
        flash_set('error', 'PHP ZipArchive extension is not available on this server.');
    } elseif (empty($_FILES['zip_file']['name']) || ($_FILES['zip_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $err_code = $_FILES['zip_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        if (in_array($err_code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            flash_set('error', 'The ZIP file is too large for the server upload limit. '
                . 'Current limits: upload_max_filesize=' . ini_get('upload_max_filesize')
                . ', post_max_size=' . ini_get('post_max_size') . '.');
        } else {
            flash_set('error', 'Please choose a ZIP file to upload.');
        }
    } else {
        $tmp_zip  = $_FILES['zip_file']['tmp_name'];
        $zip_mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp_zip);
        $zip_ext  = strtolower(pathinfo($_FILES['zip_file']['name'], PATHINFO_EXTENSION));

        $valid_zip_mimes = [
            'application/zip',
            'application/x-zip-compressed',
            'application/octet-stream',
            'multipart/x-zip',
        ];

        if ($zip_ext !== 'zip' || !in_array($zip_mime, $valid_zip_mimes, true)) {
            flash_set('error', 'Only ZIP files are accepted.');
        } else {
            $zip = new ZipArchive();
            if ($zip->open($tmp_zip) !== true) {
                flash_set('error', 'Could not open the ZIP file. It may be corrupt.');
            } else {
                // ── Pre-load all students (case-insensitive, leading-zero
                //    tolerant lookup) to avoid N+1 queries ────────────────────
                $all_students_stmt = db()->query(
                    "SELECT id, student_id, full_name, photo FROM students"
                );
                $student_map = [];  // student_id (lowercase) => row
                $zero_map    = [];  // student_id w/o leading zeros => lowercase key
                foreach ($all_students_stmt->fetchAll() as $row) {
                    $key = strtolower(trim((string)$row['student_id']));
                    if ($key === '') continue;
                    $student_map[$key] = $row;
                    $zkey = ltrim($key, '0');
                    if ($zkey !== '' && $zkey !== $key && !isset($zero_map[$zkey])) {
                        $zero_map[$zkey] = $key;
                    }
                }

                if (!is_dir($photos_dir)) {
                    mkdir($photos_dir, 0755, true);
                }

                $finfo       = new finfo(FILEINFO_MIME_TYPE);
                $update_stmt = db()->prepare(
                    "UPDATE students SET photo = ? WHERE id = ?"
                );

                // ── Walk every entry in the ZIP ────────────────────────────────
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = $zip->getNameIndex($i);

                    // Skip directory entries
                    if (substr($entry, -1) === '/') continue;

                    $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                    if (!in_array($ext, SM_PHOTO_EXTS, true)) continue;

                    $original_name  = basename($entry);
                    $student_id_raw = pathinfo($original_name, PATHINFO_FILENAME);
                    $sid_key        = strtolower(trim($student_id_raw));

                    if ($sid_key === '') {
                        $errors[] = ['student_id' => $entry, 'name' => '', 'reason' => 'Could not derive a student ID from the filename.'];
                        continue;
                    }

                    // ── Match student (exact, then leading-zero tolerant) ──────
                    if (!isset($student_map[$sid_key])) {
                        $zkey = ltrim($sid_key, '0');
                        if ($zkey !== '' && isset($zero_map[$zkey])) {
                            $sid_key = $zero_map[$zkey];
                        } elseif ($zkey !== '' && isset($student_map[$zkey])) {
                            $sid_key = $zkey;
                        }
                    }

                    if (!isset($student_map[$sid_key])) {
                        $skipped_nostu[] = [
                            'path'       => $entry,
                            'student_id' => $student_id_raw,
                        ];
                        continue;
                    }

                    $student = $student_map[$sid_key];
                    $stu_pk  = (int)$student['id'];

                    // ── Duplicate / overwrite logic ────────────────────────────
                    if ($student['photo'] && !$overwrite) {
                        $skipped_dup[] = [
                            'student_id' => $student['student_id'],
                            'name'       => $student['full_name'],
                        ];
                        continue;
                    }

                    // ── Read image content from ZIP ────────────────────────────
                    $raw_content = $zip->getFromIndex($i);
                    if ($raw_content === false) {
                        $errors[] = ['student_id' => $student['student_id'], 'name' => $student['full_name'], 'reason' => 'Could not read file from ZIP.'];
                        continue;
                    }

                    // ── Validate MIME type via magic bytes ─────────────────────
                    $mime = $finfo->buffer($raw_content);
                    if (!in_array($mime, SM_PHOTO_MIMES, true)) {
                        $errors[] = ['student_id' => $student['student_id'], 'name' => $student['full_name'], 'reason' => 'File does not appear to be a valid image (detected MIME: ' . $mime . ').'];
                        unset($raw_content);
                        continue;
                    }

                    // ── Save new photo to disk ─────────────────────────────────
                    do {
                        $stored_name = bin2hex(random_bytes(12)) . '.' . $ext;
                        $dest_path   = $photos_dir . '/' . $stored_name;
                    } while (file_exists($dest_path));

                    if (file_put_contents($dest_path, $raw_content) === false) {
                        $errors[] = ['student_id' => $student['student_id'], 'name' => $student['full_name'], 'reason' => 'Failed to write file to disk.'];
                        unset($raw_content);
                        continue;
                    }
                    unset($raw_content);

                    // ── Remove old photo file (if replacing) ───────────────────
                    $old_photo = $student['photo'];
                    if ($old_photo) {
                        $old_path = $photos_dir . '/' . $old_photo;
                        if (is_file($old_path)) {
                            @unlink($old_path);
                        }
                    }

                    // ── Update database ────────────────────────────────────────
                    $update_stmt->execute([$stored_name, $stu_pk]);

                    // Update local map so the same student isn't double-processed
                    $student_map[$sid_key]['photo'] = $stored_name;

                    if ($old_photo) {
                        $overwritten[] = [
                            'student_id' => $student['student_id'],
                            'name'       => $student['full_name'],
                        ];
                    } else {
                        $assigned[] = [
                            'student_id' => $student['student_id'],
                            'name'       => $student['full_name'],
                        ];
                    }
                }

                $zip->close();
                $results = true;
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $overwrite = isset($_POST['overwrite']) && $_POST['overwrite'] === '1';

    // ── Validate prerequisites ────────────────────────────────────────────────
    if (!is_file($sql_file)) {
        flash_set('error', 'student_user.sql not found at: ' . $sql_file);
    } elseif (!is_dir($spic_dir)) {
        flash_set('error', 'upload_spic/ folder not found at: ' . $spic_dir);
    } else {
        // ── Build the legacy mapping ──────────────────────────────────────────
        $legacy_map = lpi_build_map($sql_file);
        if (empty($legacy_map)) {
            flash_set('error', 'Could not extract any records from student_user.sql. '
                . 'Check the file is a valid phpMyAdmin dump.');
        } else {
            // ── Prepare destination directory ─────────────────────────────────
            if (!is_dir($photos_dir)) {
                mkdir($photos_dir, 0755, true);
            }

            // ── Load all students from new DB ─────────────────────────────────
            $stmt = db()->query(
                "SELECT id, student_id, full_name, photo FROM students"
            );
            $students = $stmt->fetchAll();

            $update_stmt = db()->prepare(
                "UPDATE students SET photo = ? WHERE id = ?"
            );

            $finfo = new finfo(FILEINFO_MIME_TYPE);

            foreach ($students as $stu) {
                $new_sid = trim((string)$stu['student_id']);
                $stu_pk  = (int)$stu['id'];

                // ── Skip if already has a photo and overwrite is off ──────────
                if ($stu['photo'] && !$overwrite) {
                    $skipped_dup[] = [
                        'student_id' => $stu['student_id'],
                        'name'       => $stu['full_name'],
                    ];
                    continue;
                }

                // ── Look up legacy mapping (case-insensitive O(1)) ────────────
                $legacy_filename = $legacy_map[strtolower($new_sid)] ?? null;

                if ($legacy_filename === null) {
                    $skipped_no_map[] = [
                        'student_id' => $stu['student_id'],
                        'name'       => $stu['full_name'],
                    ];
                    continue;
                }

                // ── Check file exists in upload_spic/ ─────────────────────────
                $src_path = $spic_dir . '/' . $legacy_filename;
                if (!is_file($src_path)) {
                    $skipped_no_file[] = [
                        'student_id'      => $stu['student_id'],
                        'name'            => $stu['full_name'],
                        'legacy_filename' => $legacy_filename,
                    ];
                    continue;
                }

                // ── Validate MIME type ────────────────────────────────────────
                $mime = $finfo->file($src_path);
                $valid_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (!in_array($mime, $valid_mimes, true)) {
                    $errors[] = [
                        'student_id' => $stu['student_id'],
                        'name'       => $stu['full_name'],
                        'reason'     => 'File does not appear to be a valid image (detected MIME: ' . $mime . ').',
                    ];
                    continue;
                }

                // ── Copy file to new photos directory ─────────────────────────
                $ext = strtolower(pathinfo($legacy_filename, PATHINFO_EXTENSION));
                do {
                    $stored_name = bin2hex(random_bytes(12)) . '.' . $ext;
                    $dest_path   = $photos_dir . '/' . $stored_name;
                } while (file_exists($dest_path));

                if (!copy($src_path, $dest_path)) {
                    $errors[] = [
                        'student_id' => $stu['student_id'],
                        'name'       => $stu['full_name'],
                        'reason'     => 'Failed to copy file to destination directory.',
                    ];
                    continue;
                }

                // ── Remove old photo file (if replacing) ──────────────────────
                $old_photo = $stu['photo'];
                if ($old_photo) {
                    $old_path = $photos_dir . '/' . $old_photo;
                    if (is_file($old_path)) {
                        @unlink($old_path);
                    }
                }

                // ── Update database ───────────────────────────────────────────
                $update_stmt->execute([$stored_name, $stu_pk]);

                if ($old_photo) {
                    $overwritten[] = [
                        'student_id' => $stu['student_id'],
                        'name'       => $stu['full_name'],
                    ];
                } else {
                    $assigned[] = [
                        'student_id' => $stu['student_id'],
                        'name'       => $stu['full_name'],
                    ];
                }
            }

            $results = true;
        }
    }
}

// ── Preflight check (GET) ─────────────────────────────────────────────────────
$preflight = null;
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $preflight = [
        'sql_exists'  => is_file($sql_file),
        'spic_exists' => is_dir($spic_dir),
        'spic_count'  => is_dir($spic_dir) ? count(glob($spic_dir . '/*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE)) : 0,
        'sql_size'    => is_file($sql_file) ? filesize($sql_file) : 0,
    ];

    if ($preflight['sql_exists'] && $preflight['spic_exists']) {
        // Quick count of students without photos
        $preflight['no_photo_count'] = (int)db()
            ->query("SELECT COUNT(*) FROM students WHERE photo IS NULL OR photo = ''")
            ->fetchColumn();
        $preflight['total_students'] = (int)db()
            ->query("SELECT COUNT(*) FROM students")
            ->fetchColumn();
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/students/index.php">Students</a></li>
            <li class="breadcrumb-item active">Legacy Photo Import</li>
        </ol>
    </nav>
</div>

<?php if ($results === true): ?>
<!-- ── Results ─────────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-success text-center py-3">
            <div class="fs-2 fw-bold text-success"><?= count($assigned) ?></div>
            <div class="text-muted small">Newly Assigned</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-warning text-center py-3">
            <div class="fs-2 fw-bold text-warning"><?= count($overwritten) ?></div>
            <div class="text-muted small">Overwritten</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-secondary text-center py-3">
            <div class="fs-2 fw-bold text-secondary"><?= count($skipped_dup) ?></div>
            <div class="text-muted small">Skipped (had photo)</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-danger text-center py-3">
            <div class="fs-2 fw-bold text-danger"><?= count($errors) ?></div>
            <div class="text-muted small">Errors</div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-info text-center py-3">
            <div class="fs-2 fw-bold text-info"><?= count($skipped_no_map) ?></div>
            <div class="text-muted small">No Legacy Mapping</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-secondary text-center py-3">
            <div class="fs-2 fw-bold text-secondary"><?= count($skipped_no_file) ?></div>
            <div class="text-muted small">File Missing in upload_spic/</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-secondary text-center py-3">
            <div class="fs-2 fw-bold text-secondary"><?= count($skipped_nostu) ?></div>
            <div class="text-muted small">No Matching Student</div>
        </div>
    </div>
</div>

<?php if (!empty($skipped_nostu)): ?>
<div class="card mb-3">
    <div class="card-header bg-secondary text-white">
        <i class="fas fa-user-slash me-1"></i> No Matching Student (<?= count($skipped_nostu) ?>)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive" style="max-height:300px;overflow-y:auto;">
            <table class="table table-sm table-striped mb-0">
                <thead class="table-light sticky-top">
                    <tr><th>Student ID (from filename)</th><th>File in ZIP</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($skipped_nostu as $r): ?>
                    <tr>
                        <td><code><?= h($r['student_id']) ?></code></td>
                        <td class="text-muted small"><?= h($r['path']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($assigned)): ?>
<div class="card mb-3">
    <div class="card-header bg-success text-white">
        <i class="fas fa-check-circle me-1"></i> Newly Assigned (<?= count($assigned) ?>)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive" style="max-height:300px;overflow-y:auto;">
            <table class="table table-sm table-striped mb-0">
                <thead class="table-light sticky-top">
                    <tr><th>Student ID</th><th>Name</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($assigned as $r): ?>
                    <tr>
                        <td><code><?= h($r['student_id']) ?></code></td>
                        <td><?= h($r['name']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($overwritten)): ?>
<div class="card mb-3">
    <div class="card-header bg-warning text-dark">
        <i class="fas fa-sync-alt me-1"></i> Overwritten (<?= count($overwritten) ?>)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive" style="max-height:300px;overflow-y:auto;">
            <table class="table table-sm table-striped mb-0">
                <thead class="table-light sticky-top">
                    <tr><th>Student ID</th><th>Name</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($overwritten as $r): ?>
                    <tr>
                        <td><code><?= h($r['student_id']) ?></code></td>
                        <td><?= h($r['name']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($skipped_no_file)): ?>
<div class="card mb-3">
    <div class="card-header bg-secondary text-white">
        <i class="fas fa-file-slash me-1"></i> File Missing in upload_spic/ (<?= count($skipped_no_file) ?>)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive" style="max-height:300px;overflow-y:auto;">
            <table class="table table-sm table-striped mb-0">
                <thead class="table-light sticky-top">
                    <tr><th>Student ID</th><th>Name</th><th>Expected Filename</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($skipped_no_file as $r): ?>
                    <tr>
                        <td><code><?= h($r['student_id']) ?></code></td>
                        <td><?= h($r['name']) ?></td>
                        <td><code><?= h($r['legacy_filename']) ?></code></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="card mb-3">
    <div class="card-header bg-danger text-white">
        <i class="fas fa-exclamation-triangle me-1"></i> Errors (<?= count($errors) ?>)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive" style="max-height:300px;overflow-y:auto;">
            <table class="table table-sm table-striped mb-0">
                <thead class="table-light sticky-top">
                    <tr><th>Student ID</th><th>Name</th><th>Reason</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($errors as $r): ?>
                    <tr>
                        <td><code><?= h($r['student_id']) ?></code></td>
                        <td><?= h($r['name']) ?></td>
                        <td><?= h($r['reason']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="d-flex gap-2 mb-4">
    <a href="<?= APP_URL ?>/students/legacy-photo-import.php" class="btn btn-outline-secondary">
        <i class="fas fa-redo me-1"></i> Run Again
    </a>
    <a href="<?= APP_URL ?>/students/index.php" class="btn btn-primary">
        <i class="fas fa-arrow-left me-1"></i> Back to Students
    </a>
</div>

<?php else: ?>
<!-- ── Pre-run form ─────────────────────────────────────────────────────────── -->
<div class="row justify-content-center">
    <div class="col-lg-8">

        <!-- ── Option 1: ZIP upload ─────────────────────────────────────────── -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-file-archive me-2"></i>Upload ZIP of Photos</h5>
            </div>
            <div class="card-body">
                <p class="text-muted">
                    Upload a ZIP file containing student photos. Each image filename
                    (without the extension) must be the <strong>Student ID</strong>,
                    e.g. <code>25010101.jpg</code>. Sub-folder names inside the ZIP are
                    ignored. Matching is case-insensitive and tolerant of leading zeros.
                </p>
                <p class="text-muted">
                    Supported formats: JPG, JPEG, PNG, GIF, WEBP.
                    Large ZIP files (1&nbsp;GB+) are supported &mdash; the upload may take several
                    minutes depending on your connection. Do not close this page while uploading.
                </p>

                <form method="post" enctype="multipart/form-data" id="zip-upload-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="mode" value="zip">

                    <div class="mb-3">
                        <label for="zip_file" class="form-label fw-semibold">ZIP File</label>
                        <input type="file" name="zip_file" id="zip_file" class="form-control"
                               accept=".zip,application/zip" required>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="overwrite"
                               value="1" id="zip_overwrite">
                        <label class="form-check-label" for="zip_overwrite">
                            <strong>Overwrite existing photos</strong>
                            <span class="text-muted d-block" style="font-size:.85rem;">
                                By default students who already have a photo are skipped.
                                Check this to replace their photo with the one from the ZIP.
                            </span>
                        </label>
                    </div>

                    <button type="submit" class="btn btn-success" id="zip-upload-btn">
                        <i class="fas fa-upload me-1"></i> Upload &amp; Import
                    </button>
                </form>

                <script>
                document.getElementById('zip-upload-form').addEventListener('submit', function () {
                    var btn = document.getElementById('zip-upload-btn');
                    btn.disabled = true;
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Uploading&hellip; please wait';
                });
                </script>
            </div>
        </div>

        <div class="text-center text-muted mb-4">— or —</div>

        <!-- ── Option 2: legacy SQL + upload_spic import ───────────────────── -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-history me-2"></i>Legacy Photo Import from upload_spic/</h5>
            </div>
            <div class="card-body">
                <p class="text-muted">
                    This tool reads the old <code>student_user.sql</code> database export to find
                    each student&rsquo;s original photo path, then copies the matching file from
                    the <code>upload_spic/</code> folder into the new photo store and links it to
                    the student record.
                </p>
                <p class="text-muted mb-0">
                    <strong>Students who already have a photo are skipped by default.</strong>
                    Tick &ldquo;Overwrite&rdquo; below only if you want to replace them.
                </p>
            </div>
        </div>

        <!-- ── Preflight status ───────────────────────────────────────────── -->
        <?php if ($preflight): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-clipboard-check me-1"></i> Pre-flight Check</h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr>
                            <td class="ps-3"><code>student_user.sql</code></td>
                            <td>
                                <?php if ($preflight['sql_exists']): ?>
                                    <span class="badge bg-success">Found</span>
                                    <span class="text-muted ms-1"><?= number_format($preflight['sql_size'] / 1024, 1) ?> KB</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Not found</span>
                                    <span class="text-muted ms-2"><?= h($sql_file) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="ps-3"><code>upload_spic/</code> folder</td>
                            <td>
                                <?php if ($preflight['spic_exists']): ?>
                                    <span class="badge bg-success">Found</span>
                                    <span class="text-muted ms-1"><?= number_format($preflight['spic_count']) ?> image(s) detected</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Not found</span>
                                    <span class="text-muted ms-2"><?= h($spic_dir) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if (isset($preflight['total_students'])): ?>
                        <tr>
                            <td class="ps-3">Students without a photo</td>
                            <td>
                                <strong><?= number_format($preflight['no_photo_count']) ?></strong>
                                <span class="text-muted">/ <?= number_format($preflight['total_students']) ?> total</span>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php
        $can_run = $preflight
            && $preflight['sql_exists']
            && $preflight['spic_exists'];
        ?>

        <?php if (!$can_run && $preflight): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle me-1"></i>
            One or more prerequisites are missing. Please upload
            <code>student_user.sql</code> and/or the <code>upload_spic/</code>
            folder to the site root before running the import.
        </div>
        <?php endif; ?>

        <!-- ── Run form ───────────────────────────────────────────────────── -->
        <form method="post">
            <?= csrf_field() ?>

            <div class="card mb-3">
                <div class="card-body">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="overwrite"
                               value="1" id="overwrite">
                        <label class="form-check-label" for="overwrite">
                            <strong>Overwrite existing photos</strong>
                            <span class="text-muted d-block" style="font-size:.85rem;">
                                By default students who already have a photo are skipped.
                                Check this to replace their photo with the legacy one.
                            </span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-success<?= $can_run ? '' : ' disabled' ?>"
                    <?= $can_run ? '' : 'disabled' ?>>
                    <i class="fas fa-play me-1"></i> Run Import
                </button>
                <a href="<?= APP_URL ?>/students/index.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-1"></i> Cancel
                </a>
            </div>
        </form>

        <div class="mt-4">
            <h6>How it works</h6>
            <ol class="text-muted" style="font-size:.9rem;">
                <li>Parses <code>student_user.sql</code> to build a lookup table:
                    <code>student_user_sid → photo filename</code> (from the <code>path</code> column).</li>
                <li>For every student in the new database:
                    <ul>
                        <li>If the student already has a photo → <em>skipped</em> (unless Overwrite is on).</li>
                        <li>If the student is not found in the old database → <em>skipped (no legacy mapping)</em>.</li>
                        <li>If the student is found but the file is missing from <code>upload_spic/</code> → <em>skipped (file missing)</em>.</li>
                        <li>Otherwise the file is copied to <code>admin/uploads/students/photos/</code>
                            and the student record is updated.</li>
                    </ul>
                </li>
            </ol>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
