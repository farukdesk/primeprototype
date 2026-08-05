<?php
/**
 * Certificate Number Upload – Bulk CSV / Excel Import
 *
 * Imports a Student ID → Certificate Number mapping into the
 * student_certificates table (created automatically on first import).
 *
 * Once imported, students can be found by certificate number on:
 *   - the public certificate-verification page (certificate-verification.php),
 *   - the admin Student Verification search (student-verification/verify.php),
 *   - the admin student list search (students/index.php).
 *
 * Expected columns (header names are case-insensitive; spaces, hyphens and
 * punctuation are normalised automatically):
 *
 *   Student ID          – required. Matched BOTH with and without leading
 *                         zeros ("0123" matches "123" and vice versa).
 *   Certificate Number  – required. Must be unique – each certificate number
 *                         can belong to only one student.
 *   Student Name        – optional. Used only to cross-check the matched
 *                         student record (differences produce a warning).
 *
 * A full preview (with per-row errors/warnings) is always shown before
 * anything is written to the database.
 */

ini_set('memory_limit', '256M');

require_once __DIR__ . '/../includes/auth.php';
require_access('final-result-publish');
require_once __DIR__ . '/../change-log/helpers.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$page_title = 'Certificate Number Upload';
$user       = auth_user();

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Normalise an uploaded header cell to a canonical key. */
function cu_norm_header(string $s): string {
    $s = preg_replace('/^\xEF\xBB\xBF/', '', $s); // UTF-8 BOM
    $s = strtolower(trim($s));
    $s = preg_replace('/[\s\-]+/', '_', $s);
    return preg_replace('/[^a-z0-9_]/', '', $s);
}

/** Compact key used for loose name comparison. */
function cu_key(string $s): string {
    return preg_replace('/[^a-z0-9]/', '', strtolower(trim($s)));
}

/** Supported columns and their header aliases. */
function cu_columns(): array {
    return [
        'student_id'         => ['label' => 'Student ID',         'required' => true,  'aliases' => ['student_id', 'studentid', 'id', 'id_no', 'idno', 'sid']],
        'certificate_number' => ['label' => 'Certificate Number', 'required' => true,  'aliases' => ['certificate_number', 'certificate_no', 'certificateno', 'cert_no', 'certno', 'cert_number', 'certnumber', 'certificate', 'certificate_serial', 'serial_no', 'serialno', 'serial']],
        'student_name'       => ['label' => 'Student Name',       'required' => false, 'aliases' => ['student_name', 'name', 'full_name', 'fullname', 'studentname']],
    ];
}

/** Create the student_certificates mapping table when missing. */
function cu_ensure_table(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS student_certificates (
            id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
            student_ref_id     INT          NOT NULL,
            student_id         VARCHAR(30)  NOT NULL,
            certificate_number VARCHAR(60)  NOT NULL,
            uploaded_by        INT          NULL,
            created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_certificate_number (certificate_number),
            UNIQUE KEY uq_student_ref_id (student_ref_id),
            KEY idx_student_id (student_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

/** Whether the mapping table already exists. */
function cu_table_exists(PDO $pdo): bool {
    try {
        return (bool)$pdo->query("SHOW TABLES LIKE 'student_certificates'")->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Find a student by ID, matching both with and without leading zeros:
 *   CSV "0123" matches DB "123"  and  CSV "123" matches DB "0123".
 * An exact match always wins.
 */
function cu_find_student(PDO $pdo, string $sid): ?array {
    $trimmed = ltrim($sid, '0');
    if ($trimmed === '') $trimmed = $sid;

    $stmt = $pdo->prepare(
        "SELECT id, student_id, full_name, status
         FROM students
         WHERE student_id = ?
            OR student_id = ?
            OR TRIM(LEADING '0' FROM student_id) = ?
         ORDER BY (student_id = ?) DESC
         LIMIT 1"
    );
    $stmt->execute([$sid, $trimmed, $trimmed, $sid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Read a csv/xlsx/xls into rows of string arrays. */
function cu_read_spreadsheet(string $tmp_path, string $ext): array {
    if ($ext === 'csv') {
        $handle = fopen($tmp_path, 'r');
        if ($handle === false) {
            return ['rows' => [], 'error' => 'Could not open the uploaded file.'];
        }
        $first = fgets($handle);
        rewind($handle);
        $delim = ($first !== false && substr_count($first, "\t") > substr_count($first, ',')) ? "\t" : ',';
        $rows = [];
        while (($raw = fgetcsv($handle, 0, $delim, '"', '\\')) !== false) {
            $rows[] = array_map('strval', $raw);
        }
        fclose($handle);
        return ['rows' => $rows, 'error' => null];
    }
    try {
        $reader = IOFactory::createReaderForFile($tmp_path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($tmp_path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows  = [];
        foreach ($sheet->getRowIterator() as $row) {
            $cells = [];
            foreach ($row->getCellIterator() as $cell) {
                $cells[] = (string)($cell->getValue() ?? '');
            }
            $rows[] = $cells;
        }
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        return ['rows' => $rows, 'error' => null];
    } catch (\Exception $e) {
        return ['rows' => [], 'error' => 'Could not read file: ' . $e->getMessage()];
    }
}

/** Validate one mapped row. */
function cu_validate_row(array $row, PDO $pdo, bool $table_exists): array {
    $errors   = [];
    $warnings = [];

    // IDs / certificate numbers sometimes contain stray spaces – strip ALL whitespace.
    $sid_raw  = preg_replace('/\s+/u', '', trim($row['student_id']         ?? ''));
    $cert_raw = preg_replace('/\s+/u', '', trim($row['certificate_number'] ?? ''));
    $name_raw = trim($row['student_name'] ?? '');

    if ($sid_raw === '') {
        $errors[] = 'Student ID is required.';
    } elseif (!preg_match('/^[a-zA-Z0-9\-]{1,25}$/', $sid_raw)) {
        $errors[] = 'Student ID "' . h($sid_raw) . '" is invalid (1–25 alphanumeric/hyphen chars).';
    }

    if ($cert_raw === '') {
        $errors[] = 'Certificate Number is required.';
    } elseif (!preg_match('#^[a-zA-Z0-9/\-\._]{1,50}$#', $cert_raw)) {
        $errors[] = 'Certificate Number "' . h($cert_raw) . '" is invalid (1–50 chars: letters, digits, / - . _).';
    }

    // Existing student lookup (both with and without leading zeros)
    $existing = null;
    if (empty($errors)) {
        $existing = cu_find_student($pdo, $sid_raw);
        if (!$existing) {
            $errors[] = 'No student found with ID "' . h($sid_raw) . '" (checked with and without leading zeros).';
        } else {
            if ($existing['student_id'] !== $sid_raw) {
                $warnings[] = 'Matched by leading-zero variant: CSV "' . h($sid_raw)
                            . '" → existing "' . h($existing['student_id']) . '".';
            }
            if ($name_raw !== '' && cu_key($existing['full_name']) !== cu_key($name_raw)) {
                $warnings[] = 'Name in CSV ("' . h($name_raw) . '") differs from the record ("'
                            . h($existing['full_name']) . '"). The existing record is kept.';
            }
        }
    }

    // Current mapping checks
    $current_cert = null;
    if ($existing && $table_exists) {
        try {
            $c1 = $pdo->prepare('SELECT certificate_number FROM student_certificates WHERE student_ref_id = ? LIMIT 1');
            $c1->execute([(int)$existing['id']]);
            $current_cert = $c1->fetchColumn() ?: null;
            if ($current_cert !== null && strcasecmp($current_cert, $cert_raw) !== 0) {
                $warnings[] = 'Student already has certificate number "' . h($current_cert)
                            . '" – it will be replaced with "' . h($cert_raw) . '".';
            }

            $c2 = $pdo->prepare(
                'SELECT sc.student_ref_id, s.student_id, s.full_name
                 FROM student_certificates sc
                 JOIN students s ON s.id = sc.student_ref_id
                 WHERE sc.certificate_number = ?
                 LIMIT 1'
            );
            $c2->execute([$cert_raw]);
            $owner = $c2->fetch(PDO::FETCH_ASSOC);
            if ($owner && (int)$owner['student_ref_id'] !== (int)$existing['id']) {
                $errors[] = 'Certificate Number "' . h($cert_raw) . '" is already assigned to '
                          . h($owner['full_name']) . ' (' . h($owner['student_id']) . ').';
            }
        } catch (Throwable $e) {
            // best-effort checks only
        }
    }

    $action = 'skip';
    if (empty($errors)) {
        $action = ($current_cert !== null) ? 'update' : 'create';
    }

    return [
        'errors'       => $errors,
        'warnings'     => $warnings,
        'action'       => $action,
        'student_id'   => $sid_raw,
        'full_name'    => $name_raw,
        'certificate'  => $cert_raw,
        'current_cert' => $current_cert,
        'existing'     => $existing ? [
            'id'         => (int)$existing['id'],
            'student_id' => $existing['student_id'],
            'full_name'  => $existing['full_name'],
            'status'     => $existing['status'],
        ] : null,
    ];
}

// ── State ─────────────────────────────────────────────────────────────────────

$step         = 'upload';
$parse_error  = null;
$preview_rows = null;
$import_stats = [];

// ── STEP 1 → 2: Upload, parse & validate ─────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preview') {
    csrf_check();

    if (empty($_FILES['csv_file']['name']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $parse_error = 'Please choose a file to upload.';
    } else {
        $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xlsx', 'xls'], true)) {
            $parse_error = 'Only .csv, .xlsx and .xls files are accepted.';
        } else {
            $result = cu_read_spreadsheet($_FILES['csv_file']['tmp_name'], $ext);
            if ($result['error'] !== null) {
                $parse_error = $result['error'];
            } elseif (count($result['rows']) < 2) {
                $parse_error = 'The file must contain a header row and at least one data row.';
            } else {
                $all_rows   = $result['rows'];
                $header_raw = array_shift($all_rows);
                $header     = array_map('cu_norm_header', $header_raw);

                // Auto-map columns via aliases
                $col_map = [];
                $used    = [];
                foreach (cu_columns() as $key => $def) {
                    foreach (array_merge([$key], $def['aliases']) as $alias) {
                        $idx = array_search($alias, $header, true);
                        if ($idx !== false && !isset($used[$idx])) {
                            $col_map[$key] = (int)$idx;
                            $used[$idx]    = true;
                            break;
                        }
                    }
                }

                $missing = [];
                foreach (cu_columns() as $key => $def) {
                    if ($def['required'] && !isset($col_map[$key])) {
                        $missing[] = $def['label'];
                    }
                }

                if ($missing) {
                    $parse_error = 'Missing required column(s): ' . implode(', ', $missing)
                                 . '. Found headers: ' . h(implode(', ', array_filter($header_raw)));
                } else {
                    $pdo          = db();
                    $table_exists = cu_table_exists($pdo);
                    $preview_rows = [];
                    $seen_ids     = [];   // normalised sid → first row number
                    $seen_certs   = [];   // normalised certificate → first row number
                    $row_num      = 1;

                    foreach ($all_rows as $data) {
                        $row_num++;
                        if (count(array_filter(array_map('trim', $data))) === 0) continue; // skip empty rows

                        $assoc = [];
                        foreach ($col_map as $key => $idx) {
                            $assoc[$key] = $data[$idx] ?? '';
                        }

                        $validated = cu_validate_row($assoc, $pdo, $table_exists);
                        $validated['row_num'] = $row_num;

                        // Duplicate detection inside the file
                        $sid_norm  = ltrim($validated['student_id'], '0') ?: $validated['student_id'];
                        $cert_norm = strtoupper($validated['certificate']);
                        if ($sid_norm !== '') {
                            if (isset($seen_ids[$sid_norm])) {
                                $validated['errors'][] = 'Student ID appears twice in this file (first at row '
                                                       . $seen_ids[$sid_norm] . ').';
                                $validated['action'] = 'skip';
                            } else {
                                $seen_ids[$sid_norm] = $row_num;
                            }
                        }
                        if ($cert_norm !== '') {
                            if (isset($seen_certs[$cert_norm])) {
                                $validated['errors'][] = 'Certificate Number appears twice in this file (first at row '
                                                       . $seen_certs[$cert_norm] . ').';
                                $validated['action'] = 'skip';
                            } else {
                                $seen_certs[$cert_norm] = $row_num;
                            }
                        }

                        $preview_rows[] = $validated;
                    }

                    if (empty($preview_rows)) {
                        $parse_error = 'The file contains no data rows.';
                    } else {
                        $_SESSION['cu_rows'] = $preview_rows;
                        $step = 'preview';
                    }
                }
            }
        }
    }
}

// ── STEP 2 → 3: Confirm & import ─────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    csrf_check();

    if (!is_super_admin() && !can_access('final-result-publish', 'can_create')) {
        flash_set('error', 'You do not have permission to import certificate numbers.');
        redirect(APP_URL . '/final-result-publish/certificate-upload.php');
    }

    $rows = $_SESSION['cu_rows'] ?? [];
    unset($_SESSION['cu_rows']);

    if (empty($rows)) {
        flash_set('error', 'No import data found. Please re-upload the file.');
        redirect(APP_URL . '/final-result-publish/certificate-upload.php');
    }

    $pdo = db();
    cu_ensure_table($pdo);

    $created = 0;
    $updated = 0;
    $skipped = 0;
    $results = [];

    foreach ($rows as $r) {
        if (!empty($r['errors']) || $r['action'] === 'skip') {
            $results[] = [
                'row_num'     => $r['row_num'],
                'status'      => 'skipped',
                'student_id'  => $r['student_id'],
                'certificate' => $r['certificate'],
                'reason'      => implode('; ', $r['errors']) ?: 'Skipped.',
            ];
            $skipped++;
            continue;
        }

        try {
            // Re-check the student (session data may be stale)
            $existing = cu_find_student($pdo, $r['student_id']);
            if (!$existing) {
                throw new RuntimeException('Student no longer found.');
            }
            $student_pk  = (int)$existing['id'];
            $student_sid = $existing['student_id'];

            // The certificate number must not belong to another student
            $chk = $pdo->prepare('SELECT id, student_ref_id, certificate_number FROM student_certificates WHERE certificate_number = ? LIMIT 1');
            $chk->execute([$r['certificate']]);
            $cert_row = $chk->fetch(PDO::FETCH_ASSOC);
            if ($cert_row && (int)$cert_row['student_ref_id'] !== $student_pk) {
                throw new RuntimeException('Certificate number is already assigned to another student.');
            }

            // Existing mapping for this student?
            $own = $pdo->prepare('SELECT id, certificate_number FROM student_certificates WHERE student_ref_id = ? LIMIT 1');
            $own->execute([$student_pk]);
            $own_row = $own->fetch(PDO::FETCH_ASSOC);

            if ($own_row) {
                $pdo->prepare(
                    'UPDATE student_certificates
                     SET certificate_number = ?, student_id = ?, uploaded_by = ?
                     WHERE id = ?'
                )->execute([$r['certificate'], $student_sid, $user['id'], (int)$own_row['id']]);
                $status = 'updated';
            } elseif ($cert_row) {
                $pdo->prepare(
                    'UPDATE student_certificates
                     SET student_ref_id = ?, student_id = ?, uploaded_by = ?
                     WHERE id = ?'
                )->execute([$student_pk, $student_sid, $user['id'], (int)$cert_row['id']]);
                $status = 'updated';
            } else {
                $pdo->prepare(
                    'INSERT INTO student_certificates
                       (student_ref_id, student_id, certificate_number, uploaded_by)
                     VALUES (?,?,?,?)'
                )->execute([$student_pk, $student_sid, $r['certificate'], $user['id']]);
                $status = 'created';
            }

            log_change('students', 'UPDATE', $student_pk,
                       $existing['full_name'] . ' (' . $student_sid . ')',
                       'certificate_number',
                       $own_row ? $own_row['certificate_number'] : null,
                       $r['certificate'],
                       'Certificate number imported via Certificate Number Upload');

            $results[] = [
                'row_num'     => $r['row_num'],
                'status'      => $status,
                'student_id'  => $student_sid,
                'certificate' => $r['certificate'],
                'reason'      => '',
            ];
            if ($status === 'created') $created++; else $updated++;

        } catch (Throwable $e) {
            $results[] = [
                'row_num'     => $r['row_num'],
                'status'      => 'error',
                'student_id'  => $r['student_id'],
                'certificate' => $r['certificate'],
                'reason'      => 'Error: ' . h($e->getMessage()),
            ];
            $skipped++;
        }
    }

    $import_stats = [
        'created' => $created,
        'updated' => $updated,
        'skipped' => $skipped,
        'rows'    => $results,
    ];
    $step = 'done';
}

// ── HTML ──────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/final-result-publish/index.php">Final Result Publish</a></li>
            <li class="breadcrumb-item active">Certificate Number Upload</li>
        </ol>
    </nav>
    <a href="<?= SITE_URL ?>/certificate-verification.php" target="_blank"
       class="btn btn-outline-secondary btn-sm" style="border-radius:8px;">
        <i class="fas fa-external-link-alt me-1"></i> Public Verification Page
    </a>
</div>

<?php flash_show(); ?>

<?php if ($parse_error): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= h($parse_error) ?></div>
<?php endif; ?>

<?php /* ── STEP 1: Upload ─────────────────────────────────────── */ ?>
<?php if ($step === 'upload'): ?>

<div class="card mb-4">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-certificate me-2 text-muted"></i>Upload Certificate Numbers (CSV / Excel)</h6>
    </div>
    <div class="card-body">

        <div class="alert alert-info mb-4" style="font-size:.875rem;">
            <strong>Expected columns</strong>
            <small class="text-muted">(header names are case-insensitive; punctuation is normalised)</small>
            <ul class="mb-2 mt-2 ps-3">
                <li><code>Student ID</code> <span class="text-danger">*</span> – matched with and without leading zeros (e.g. <code>0123</code> ⇄ <code>123</code>)</li>
                <li><code>Certificate Number</code> <span class="text-danger">*</span> – must be unique; one certificate number per student</li>
                <li><code>Student Name</code> – optional, used only to cross-check the matched record</li>
            </ul>
            <div>
                After importing, students can be found by <strong>certificate number</strong> on the public
                certificate verification page, the admin student verification search and the student list search.
                A full preview is shown before anything is saved.
            </div>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="preview">
            <div class="row g-3 mb-3" style="max-width:760px;">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">File <span class="text-danger">*</span></label>
                    <input type="file" name="csv_file" class="form-control" accept=".csv,.xlsx,.xls" required>
                    <div class="form-text">.csv (comma or tab), .xlsx, .xls</div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary" style="border-radius:8px;">
                <i class="fas fa-search me-1"></i> Upload &amp; Preview
            </button>
        </form>
    </div>
</div>

<?php /* ── STEP 2: Preview ────────────────────────────────────── */ ?>
<?php elseif ($step === 'preview' && $preview_rows !== null): ?>

<?php
$n_create = 0; $n_update = 0; $n_skip = 0;
foreach ($preview_rows as $pr) {
    if (!empty($pr['errors']) || $pr['action'] === 'skip') $n_skip++;
    elseif ($pr['action'] === 'update') $n_update++;
    else $n_create++;
}
$n_ok = $n_create + $n_update;
?>

<div class="alert <?= $n_ok > 0 ? 'alert-success' : 'alert-warning' ?> mb-3">
    <strong><?= count($preview_rows) ?></strong> data row(s):
    <strong><?= $n_create ?></strong> new certificate number(s) will be added,
    <strong><?= $n_update ?></strong> existing mapping(s) will be updated,
    <strong><?= $n_skip ?></strong> will be skipped.
</div>

<div class="d-flex gap-2 mb-3">
    <?php if ($n_ok > 0): ?>
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="import">
        <button type="submit" class="btn btn-success" style="border-radius:8px;"
                onclick="return confirm('Save certificate numbers for <?= $n_ok ?> student(s) now?');">
            <i class="fas fa-certificate me-1"></i> Confirm &amp; Save <?= $n_ok ?> Certificate Number(s)
        </button>
    </form>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/final-result-publish/certificate-upload.php" class="btn btn-outline-secondary" style="border-radius:8px;">
        <i class="fas fa-redo me-1"></i> Re-upload
    </a>
</div>

<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-table me-2 text-muted"></i>Preview (<?= count($preview_rows) ?> rows)</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0" style="font-size:.8rem; white-space:nowrap;">
                <thead class="table-light">
                    <tr>
                        <th class="px-3">#</th>
                        <th>Row</th>
                        <th>Action</th>
                        <th>Status</th>
                        <th>CSV ID</th>
                        <th>Matched Student</th>
                        <th>Certificate Number</th>
                        <th>Current Certificate</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($preview_rows as $i => $r):
                    $has_errors   = !empty($r['errors']) || $r['action'] === 'skip';
                    $has_warnings = !empty($r['warnings']);
                    $row_cls = $has_errors ? 'table-danger' : ($has_warnings ? 'table-warning' : '');
                    $dash    = '<span class="text-muted">—</span>';
                ?>
                <tr class="<?= $row_cls ?>">
                    <td class="px-3"><?= $i + 1 ?></td>
                    <td><?= (int)$r['row_num'] ?></td>
                    <td>
                        <?php if ($has_errors): ?>
                            <span class="badge bg-danger">Skip</span>
                        <?php elseif ($r['action'] === 'update'): ?>
                            <span class="badge bg-info text-dark">Update</span>
                        <?php else: ?>
                            <span class="badge bg-success">Add</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($has_errors): ?>
                            <span class="text-danger fw-semibold"><i class="fas fa-times-circle me-1"></i>Error</span>
                        <?php elseif ($has_warnings): ?>
                            <span class="text-warning fw-semibold"><i class="fas fa-exclamation-triangle me-1"></i>Warning</span>
                        <?php else: ?>
                            <span class="text-success"><i class="fas fa-check-circle me-1"></i>OK</span>
                        <?php endif; ?>
                        <?php if (!empty($r['errors']) || !empty($r['warnings'])): ?>
                        <ul class="mb-0 ps-3 mt-1" style="font-size:.75rem;white-space:normal;min-width:240px;">
                            <?php foreach ($r['errors']   as $e): ?><li class="text-danger"><?= $e ?></li><?php endforeach; ?>
                            <?php foreach ($r['warnings'] as $w): ?><li><?= $w ?></li><?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </td>
                    <td><code style="font-size:.75rem;"><?= h($r['student_id']) ?></code></td>
                    <td>
                        <?php if ($r['existing']): ?>
                            <code style="font-size:.75rem;"><?= h($r['existing']['student_id']) ?></code>
                            <small class="text-muted d-block"><?= h($r['existing']['full_name']) ?> · <?= h($r['existing']['status']) ?></small>
                        <?php else: ?>
                            <?= $dash ?>
                        <?php endif; ?>
                    </td>
                    <td><strong><code style="font-size:.75rem;"><?= h($r['certificate']) ?></code></strong></td>
                    <td><?= $r['current_cert'] !== null ? '<code style="font-size:.75rem;">' . h($r['current_cert']) . '</code>' : $dash ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php /* ── STEP 3: Done ───────────────────────────────────────── */ ?>
<?php elseif ($step === 'done'): ?>

<div class="alert <?= ($import_stats['created'] + $import_stats['updated']) > 0 ? 'alert-success' : 'alert-warning' ?>">
    <i class="fas fa-certificate me-2"></i>
    <strong><?= $import_stats['created'] ?></strong> certificate number(s) added,
    <strong><?= $import_stats['updated'] ?></strong> updated,
    <strong><?= $import_stats['skipped'] ?></strong> row(s) skipped.
</div>

<div class="d-flex gap-2 mb-4">
    <a href="<?= SITE_URL ?>/certificate-verification.php" target="_blank" class="btn btn-primary" style="border-radius:8px;">
        <i class="fas fa-shield-alt me-1"></i> Check Verification Page
    </a>
    <a href="<?= APP_URL ?>/final-result-publish/certificate-upload.php" class="btn btn-outline-secondary" style="border-radius:8px;">
        <i class="fas fa-redo me-1"></i> Import Another File
    </a>
</div>

<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-list me-2 text-muted"></i>Import Results</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0" style="font-size:.85rem;">
                <thead class="table-light">
                    <tr>
                        <th class="px-3">Row</th>
                        <th>Student ID</th>
                        <th>Certificate Number</th>
                        <th>Result</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($import_stats['rows'] as $r):
                    $cls = in_array($r['status'], ['created', 'updated'], true) ? '' : 'table-danger';
                ?>
                <tr class="<?= $cls ?>">
                    <td class="px-3"><?= (int)$r['row_num'] ?></td>
                    <td><code><?= h($r['student_id']) ?></code></td>
                    <td><code><?= h($r['certificate']) ?></code></td>
                    <td>
                        <?php if ($r['status'] === 'created'): ?>
                            <span class="text-success"><i class="fas fa-plus-circle me-1"></i>Certificate number added</span>
                        <?php elseif ($r['status'] === 'updated'): ?>
                            <span class="text-info"><i class="fas fa-check-circle me-1"></i>Certificate number updated</span>
                        <?php else: ?>
                            <span class="text-danger"><i class="fas fa-times-circle me-1"></i>Skipped</span>
                            <?php if ($r['reason']): ?>
                            <small class="d-block text-muted"><?= $r['reason'] ?></small>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
