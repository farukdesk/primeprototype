<?php
/**
 * Student Files – Bulk ZIP Upload
 *
 * Accepts a ZIP of PDFs plus an optional CSV mapping file.
 *
 * CSV mapping (columns: New_Filename, All_IDs_In_File):
 *   New_Filename       – exact PDF filename as it appears inside the ZIP
 *                        (e.g. "report.pdf" or "Batch1/CSE/report.pdf")
 *   All_IDs_In_File    – comma-separated student IDs that should receive the file
 *
 * When a CSV is supplied the script resolves student IDs from the CSV instead
 * of from the PDF filename.  If no CSV is supplied the PDF filename itself
 * (without extension) is treated as one or more comma-separated student IDs –
 * the previous behaviour is fully preserved.
 */

require_once __DIR__ . '/../includes/auth.php';
require_access('students');
require_once __DIR__ . '/helpers.php';

if (!sm_can_create()) {
    flash_set('error', 'You do not have permission to upload files.');
    redirect(APP_URL . '/students/index.php');
}

if (!class_exists('ZipArchive')) {
    flash_set('error', 'PHP ZipArchive extension is not available on this server.');
    redirect(APP_URL . '/students/index.php');
}

$page_title = 'Bulk Student File Upload';
$user       = auth_user();

// ── Processing ────────────────────────────────────────────────────────────────

$results        = null;  // null = not yet run
$imported       = [];
$auto_created   = [];   // PDFs whose student_id was not in DB → new stub student created
$skipped_dup    = [];   // PDFs already attached to the student (same original_name)
$skipped_no_map = [];   // PDFs present in ZIP but not found in the CSV mapping
$errors         = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $file_label  = trim($_POST['file_label']  ?? 'Student Document');
    $description = trim($_POST['description'] ?? '');

    if ($file_label === '') {
        $file_label = 'Student Document';
    }

    if (empty($_FILES['zip_file']['name']) || $_FILES['zip_file']['error'] !== UPLOAD_ERR_OK) {
        flash_set('error', 'Please choose a ZIP file to upload.');
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
            // ── Optional CSV mapping file ──────────────────────────────────
            // Build a map: lowercase(New_Filename) => [student_id, ...]
            // If no CSV is uploaded the map is null and IDs come from filenames.
            $csv_map = null;   // null = use filename mode
            $csv_parse_error = null;

            $csv_uploaded = !empty($_FILES['csv_map']['name'])
                            && $_FILES['csv_map']['error'] === UPLOAD_ERR_OK;

            if ($csv_uploaded) {
                $csv_ext = strtolower(pathinfo($_FILES['csv_map']['name'], PATHINFO_EXTENSION));
                if ($csv_ext !== 'csv') {
                    $csv_parse_error = 'The mapping file must be a .csv file.';
                } else {
                    $csv_handle = fopen($_FILES['csv_map']['tmp_name'], 'r');
                    if ($csv_handle === false) {
                        $csv_parse_error = 'Could not open the CSV mapping file.';
                    } else {
                        // Read header row; find the required column indices
                        $header = fgetcsv($csv_handle);
                        if ($header === false || $header === null) {
                            $csv_parse_error = 'The CSV file appears to be empty.';
                        } else {
                            // Normalise header names: trim + lower-case + collapse spaces
                            $norm = function (string $s): string {
                                return strtolower(trim(preg_replace('/\s+/', '_', $s)));
                            };
                            $header_norm = array_map($norm, $header);
                            $col_file = array_search('new_filename',    $header_norm, true);
                            $col_ids  = array_search('all_ids_in_file', $header_norm, true);

                            if ($col_file === false || $col_ids === false) {
                                $csv_parse_error = 'CSV must contain columns "New_Filename" and "All_IDs_In_File".';
                            } else {
                                $csv_map = [];
                                $row_num = 1;
                                while (($row = fgetcsv($csv_handle)) !== false) {
                                    $row_num++;
                                    $filename_val = trim($row[$col_file] ?? '');
                                    $ids_val      = trim($row[$col_ids]  ?? '');
                                    if ($filename_val === '') continue; // skip blank rows

                                    $sid_list = array_values(array_filter(
                                        array_map('trim', explode(',', $ids_val))
                                    ));
                                    if (empty($sid_list)) {
                                        $errors[] = [
                                            'path'   => "CSV row {$row_num}",
                                            'reason' => "New_Filename \"{$filename_val}\" has no student IDs in All_IDs_In_File.",
                                        ];
                                        continue;
                                    }
                                    // Store under the normalised value as given in the CSV.
                                    // Also store under just the bare filename so that a CSV entry
                                    // like "Batch1/CSE/report.pdf" still matches "report.pdf"
                                    // inside the ZIP (and vice-versa).
                                    $csv_map[strtolower($filename_val)] = $sid_list;
                                    $bare_key = strtolower(basename($filename_val));
                                    if ($bare_key !== strtolower($filename_val)) {
                                        // Only add the bare-filename alias if it is different; if two
                                        // CSV rows share the same bare filename the last one wins.
                                        $csv_map[$bare_key] = $sid_list;
                                    }
                                }
                                fclose($csv_handle);
                            }
                        }
                        if ($csv_parse_error !== null && isset($csv_handle) && is_resource($csv_handle)) {
                            fclose($csv_handle);
                        }
                    }
                }

                if ($csv_parse_error !== null) {
                    flash_set('error', 'CSV mapping error: ' . $csv_parse_error);
                    $csv_map = null; // prevent processing
                }
            }

            if ($csv_parse_error === null) {
                $zip = new ZipArchive();
                if ($zip->open($tmp_zip) !== true) {
                    flash_set('error', 'Could not open the ZIP file. It may be corrupt.');
                } else {
                // ── Pre-load all student IDs to avoid N+1 queries ──────────────
                $all_students_stmt = db()->query(
                    "SELECT id, student_id, full_name, dept_id FROM students"
                );
                $student_map = [];  // student_id (string) => row
                foreach ($all_students_stmt->fetchAll() as $row) {
                    $student_map[strtolower(trim($row['student_id']))] = $row;
                }

                // ── Fallback dept for auto-created stub students ────────────────
                $fallback_dept = db()->query(
                    "SELECT id FROM dept_departments WHERE is_active = 1 ORDER BY id ASC LIMIT 1"
                )->fetchColumn();
                if (!$fallback_dept) {
                    $fallback_dept = db()->query(
                        "SELECT id FROM dept_departments ORDER BY id ASC LIMIT 1"
                    )->fetchColumn();
                }

                // ── Prepared statement for auto-creating stub students ──────────
                $create_student_stmt = db()->prepare(
                    "INSERT INTO students
                       (student_id, dept_id, admitted_semester, full_name,
                        status, created_by)
                     VALUES (?, ?, 'Unknown', ?, 'Active', ?)"
                );

                // ── Pre-load already-attached files to detect duplicates ───────
                $existing_stmt = db()->query(
                    "SELECT student_id, original_name FROM student_files"
                );
                $existing_files = [];  // "student_pk:original_name" => true
                foreach ($existing_stmt->fetchAll() as $row) {
                    $existing_files[$row['student_id'] . ':' . $row['original_name']] = true;
                }

                $dest_dir = UPLOAD_DIR . '/students/files';
                if (!is_dir($dest_dir)) {
                    mkdir($dest_dir, 0755, true);
                }

                // ── Walk every entry in the ZIP ───────────────────────────────
                $insert_stmt = db()->prepare(
                    'INSERT INTO student_files
                       (student_id, file_name, description, stored_name,
                        original_name, mime_type, file_size, uploaded_by)
                     VALUES (?,?,?,?,?,?,?,?)'
                );

                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = $zip->getNameIndex($i);

                    // Skip directories and non-PDF entries
                    if (substr($entry, -1) === '/') continue;
                    if (strtolower(pathinfo($entry, PATHINFO_EXTENSION)) !== 'pdf') continue;

                    $original_name = basename($entry);

                    // ── Resolve student IDs ────────────────────────────────────
                    // Priority 1: CSV mapping  (New_Filename → All_IDs_In_File)
                    // Priority 2: Filename itself (comma-separated IDs)
                    if ($csv_map !== null) {
                        // Try bare filename first, then the full relative path
                        $map_key_bare = strtolower($original_name);
                        $map_key_full = strtolower($entry);
                        if (isset($csv_map[$map_key_bare])) {
                            $sid_raws = $csv_map[$map_key_bare];
                        } elseif (isset($csv_map[$map_key_full])) {
                            $sid_raws = $csv_map[$map_key_full];
                        } else {
                            $skipped_no_map[] = ['path' => $entry, 'reason' => 'File not found in CSV mapping. Skipped.'];
                            continue;
                        }
                    } else {
                        // Filename mode: parse comma-separated IDs from the filename
                        $filename_base = pathinfo($original_name, PATHINFO_FILENAME);
                        $sid_raws = array_values(array_filter(
                            array_map('trim', explode(',', $filename_base))
                        ));

                        if (empty($sid_raws)) {
                            $errors[] = ['path' => $entry, 'reason' => 'Could not derive any student ID from the filename. When no CSV mapping is provided the filename (without .pdf) must contain one or more comma-separated student IDs.'];
                            continue;
                        }
                    }

                    // Parse batch and dept from path parts for description context
                    $parts     = explode('/', $entry);
                    $batch_str = '';
                    $dept_str  = '';
                    if (count($parts) >= 3) {
                        $batch_str = $parts[0];
                        $dept_str  = $parts[count($parts) - 2];
                    } elseif (count($parts) === 2) {
                        $dept_str = $parts[0];
                    }

                    // ── Read & validate the PDF exactly once per ZIP entry ─────
                    $raw_content = $zip->getFromIndex($i);
                    if ($raw_content === false) {
                        $errors[] = ['path' => $entry, 'reason' => 'Could not read file from ZIP.'];
                        continue;
                    }

                    // Validate magic bytes: PDF starts with "%PDF"
                    if (substr($raw_content, 0, 4) !== '%PDF') {
                        $errors[] = ['path' => $entry, 'reason' => 'File does not appear to be a valid PDF.'];
                        continue;
                    }

                    $file_size = strlen($raw_content);

                    // ── Write physical file to disk exactly once ───────────────
                    // The same stored file is referenced by every student ID in
                    // the comma-separated filename, saving disk space.
                    $stored_name = bin2hex(random_bytes(12)) . '.pdf';
                    $dest_path   = $dest_dir . '/' . $stored_name;

                    if (file_put_contents($dest_path, $raw_content) === false) {
                        $errors[] = ['path' => $entry, 'reason' => 'Failed to write file to disk.'];
                        continue;
                    }

                    // ── Build description ──────────────────────────────────────
                    $desc_parts = array_filter([$description, $batch_str, $dept_str]);
                    $auto_desc  = implode(' – ', $desc_parts) ?: null;

                    // ── Process each student ID listed in the filename ─────────
                    $any_inserted = false;
                    foreach ($sid_raws as $sid_raw) {
                        $sid_key = strtolower($sid_raw);

                        // Match student; auto-create a stub if not found
                        if (!isset($student_map[$sid_key])) {
                            if (!$fallback_dept) {
                                $errors[] = ['path' => $entry, 'reason' => "Student ID \"{$sid_raw}\": no department exists in the database; cannot auto-create student."];
                                continue;
                            }
                            try {
                                $create_student_stmt->execute([
                                    $sid_raw,
                                    (int)$fallback_dept,
                                    $sid_raw,   // full_name = student_id as placeholder
                                    $user['id'],
                                ]);
                                $new_stu_pk = (int)db()->lastInsertId();
                            } catch (PDOException $e) {
                                $errors[] = ['path' => $entry, 'reason' => "Student ID \"{$sid_raw}\": failed to auto-create student: " . $e->getMessage()];
                                continue;
                            }
                            $student_map[$sid_key] = [
                                'id'         => $new_stu_pk,
                                'student_id' => $sid_raw,
                                'full_name'  => $sid_raw,
                                'dept_id'    => (int)$fallback_dept,
                            ];
                            $auto_created[] = [
                                'path'       => $entry,
                                'student_id' => $sid_raw,
                            ];
                        }

                        $student = $student_map[$sid_key];
                        $stu_pk  = (int)$student['id'];

                        // Duplicate check (per-student)
                        $dup_key = $stu_pk . ':' . $original_name;
                        if (isset($existing_files[$dup_key])) {
                            $skipped_dup[] = [
                                'path'       => $entry,
                                'student_id' => $sid_raw,
                                'name'       => $student['full_name'],
                            ];
                            continue;
                        }

                        // Insert DB row — all student IDs point to the same stored file
                        $insert_stmt->execute([
                            $stu_pk,
                            $file_label,
                            $auto_desc,
                            $stored_name,
                            $original_name,
                            'application/pdf',
                            $file_size,
                            $user['id'],
                        ]);

                        // Mark so we won't import duplicate within the same ZIP
                        $existing_files[$dup_key] = true;
                        $any_inserted = true;

                        $imported[] = [
                            'path'       => $entry,
                            'student_id' => $sid_raw,
                            'name'       => $student['full_name'],
                        ];
                    }

                    // If every student ID was a duplicate and the file was never
                    // used, remove the written file to avoid orphaned files.
                    if (!$any_inserted && is_file($dest_path)) {
                        if (!unlink($dest_path)) {
                            error_log("bulk-upload: could not delete unused file {$stored_name}");
                        }
                    }
                }

                $zip->close();
                $results = true;
                }   // end if ($zip->open)
            }   // end if ($csv_parse_error === null)
        }   // end if ZIP MIME ok
    }   // end if ZIP uploaded
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/students/index.php">Students</a></li>
            <li class="breadcrumb-item active">Bulk File Upload</li>
        </ol>
    </nav>
</div>

<?php if ($results === true): ?>
<!-- ── Results ─────────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#28a745,#1e7e34);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= count($imported) ?></div>
                    <div class="stat-lbl">Imported</div>
                </div>
                <i class="fas fa-check-circle" style="font-size:2rem;opacity:.4"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#ffc107,#d39e00);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= count($auto_created) ?></div>
                    <div class="stat-lbl">Auto-Created</div>
                </div>
                <i class="fas fa-user-plus" style="font-size:2rem;opacity:.4"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#17a2b8,#117a8b);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= count($skipped_dup) ?></div>
                    <div class="stat-lbl">Already Exists</div>
                </div>
                <i class="fas fa-copy" style="font-size:2rem;opacity:.4"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#6c757d,#495057);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= count($skipped_no_map) ?></div>
                    <div class="stat-lbl">No CSV Mapping</div>
                </div>
                <i class="fas fa-unlink" style="font-size:2rem;opacity:.4"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#dc3545,#b02a37);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= count($errors) ?></div>
                    <div class="stat-lbl">Errors</div>
                </div>
                <i class="fas fa-times-circle" style="font-size:2rem;opacity:.4"></i>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($imported)): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-success text-white py-2">
        <i class="fas fa-check-circle me-1"></i> Successfully Imported (<?= count($imported) ?>)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light"><tr><th>#</th><th>Student ID</th><th>Student Name</th><th>File in ZIP</th></tr></thead>
                <tbody>
                <?php foreach ($imported as $k => $r): ?>
                    <tr>
                        <td class="text-muted"><?= $k + 1 ?></td>
                        <td><code><?= h($r['student_id']) ?></code></td>
                        <td><?= h($r['name']) ?></td>
                        <td class="text-muted small"><?= h($r['path']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($auto_created)): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-warning text-dark py-2">
        <i class="fas fa-user-plus me-1"></i> Auto-Created Students (<?= count($auto_created) ?>)
        <small class="d-block mt-1" style="font-weight:400">No existing student matched these filenames. A stub student record was created for each: Student ID and Name set to the filename, Department set to the first available department, Semester set to "Unknown". Please update their details.</small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light"><tr><th>#</th><th>Student ID (filename)</th><th>File in ZIP</th></tr></thead>
                <tbody>
                <?php foreach ($auto_created as $k => $r): ?>
                    <tr>
                        <td class="text-muted"><?= $k + 1 ?></td>
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

<?php if (!empty($skipped_dup)): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-info text-white py-2">
        <i class="fas fa-copy me-1"></i> Already Exists – Skipped (<?= count($skipped_dup) ?>)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light"><tr><th>#</th><th>Student ID</th><th>Student Name</th><th>File in ZIP</th></tr></thead>
                <tbody>
                <?php foreach ($skipped_dup as $k => $r): ?>
                    <tr>
                        <td class="text-muted"><?= $k + 1 ?></td>
                        <td><code><?= h($r['student_id']) ?></code></td>
                        <td><?= h($r['name']) ?></td>
                        <td class="text-muted small"><?= h($r['path']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($skipped_no_map)): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-secondary text-white py-2">
        <i class="fas fa-unlink me-1"></i> Not Found in CSV Mapping – Skipped (<?= count($skipped_no_map) ?>)
        <small class="d-block mt-1" style="font-weight:400">These PDF files exist in the ZIP but have no matching row in the uploaded CSV mapping file. Add them to the CSV and re-upload to import them.</small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light"><tr><th>#</th><th>File in ZIP</th></tr></thead>
                <tbody>
                <?php foreach ($skipped_no_map as $k => $r): ?>
                    <tr>
                        <td class="text-muted"><?= $k + 1 ?></td>
                        <td class="text-muted small"><?= h($r['path']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-danger text-white py-2">
        <i class="fas fa-times-circle me-1"></i> Errors (<?= count($errors) ?>)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light"><tr><th>#</th><th>File in ZIP</th><th>Reason</th></tr></thead>
                <tbody>
                <?php foreach ($errors as $k => $r): ?>
                    <tr>
                        <td class="text-muted"><?= $k + 1 ?></td>
                        <td class="text-muted small"><?= h($r['path']) ?></td>
                        <td class="text-danger"><?= h($r['reason']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="d-flex gap-2 mb-4">
    <a href="<?= APP_URL ?>/students/bulk-upload.php" class="btn btn-outline-primary">
        <i class="fas fa-upload me-1"></i> Upload Another ZIP
    </a>
    <a href="<?= APP_URL ?>/students/index.php" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i> Back to Students
    </a>
</div>

<?php else: ?>
<!-- ── Upload form ─────────────────────────────────────────────────────────── -->
<div class="row justify-content-center">
    <div class="col-12 col-lg-8">

        <div class="card shadow-sm mb-4">
            <div class="card-header py-3">
                <h5 class="mb-0"><i class="fas fa-file-archive me-2 text-primary"></i>Bulk Upload Student Files via ZIP</h5>
            </div>
            <div class="card-body">

                <div class="alert alert-info" role="alert">
                    <strong><i class="fas fa-info-circle me-1"></i> Two ways to map PDFs to students:</strong>

                    <div class="mt-2">
                        <strong>Option A – CSV Mapping file <span class="badge bg-primary" style="font-size:.75rem;">Recommended</span></strong><br>
                        <span class="small">Upload a <code>.csv</code> file alongside the ZIP. The CSV must contain two columns:</span>
                        <ul class="mb-1 mt-1 small">
                            <li><code>New_Filename</code> – the PDF filename exactly as it appears inside the ZIP (e.g. <code>report.pdf</code> or <code>Batch1/CSE/report.pdf</code>)</li>
                            <li><code>All_IDs_In_File</code> – comma-separated student IDs that should receive the file, e.g. <code>25010101,25010102,25010103</code></li>
                        </ul>
                        <span class="small text-muted">When a CSV is provided the PDF filename can be anything — only the CSV mapping is used to resolve student IDs.</span>
                    </div>

                    <div class="mt-2">
                        <strong>Option B – Filename-based (no CSV)</strong><br>
                        <span class="small">Name each PDF after the student ID(s), e.g. <code>25010101.pdf</code> or <code>25010101,25010102.pdf</code> for a shared file. Sub-folder names (batch, department) are ignored.</span>
                    </div>

                    <pre class="mb-0 mt-2" style="font-size:.82rem;background:transparent;border:none;padding:0">Example CSV:
New_Filename,All_IDs_In_File
report.pdf,"25010101,25010102,25010103"
transcript.pdf,25020101</pre>

                    <p class="mb-0 mt-2 small">If no matching student is found a <strong>stub record</strong> is auto-created. If the same file is already linked to a student it will be <strong>skipped</strong>.</p>
                </div>

                <form method="post" enctype="multipart/form-data" id="bulk-upload-form">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="file_label">
                            Document Label <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="file_label" name="file_label"
                               value="Student Document" required
                               placeholder="e.g. Admission Form, ID Copy, Transcript">
                        <div class="form-text">This label is applied to every imported file (e.g. "Admission Form").</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="description">
                            Extra Description <span class="text-muted fw-normal">(optional)</span>
                        </label>
                        <input type="text" class="form-control" id="description" name="description"
                               placeholder="e.g. Scanned originals – Summer 2025">
                        <div class="form-text">Appended to the auto-generated "Batch – Dept" description.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="csv_map">
                            CSV Mapping File <span class="text-muted fw-normal">(optional)</span>
                        </label>
                        <input type="file" class="form-control" id="csv_map" name="csv_map"
                               accept=".csv,text/csv">
                        <div class="form-text">
                            Columns required: <code>New_Filename</code> and <code>All_IDs_In_File</code>.
                            If not provided, student IDs are read from the PDF filenames directly.
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="zip_file">
                            ZIP File <span class="text-danger">*</span>
                        </label>
                        <input type="file" class="form-control" id="zip_file" name="zip_file"
                               accept=".zip,application/zip" required>
                        <div class="form-text">Maximum upload size: 200 GB (configured for large ZIP files).</div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary" id="submit-btn">
                            <i class="fas fa-upload me-1"></i> Start Import
                        </button>
                        <a href="<?= APP_URL ?>/students/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-times me-1"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>

    </div>
</div>
<?php endif; ?>

<script>
document.getElementById('bulk-upload-form')?.addEventListener('submit', function () {
    var btn = document.getElementById('submit-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Importing…';
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
