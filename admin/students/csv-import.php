<?php
/**
 * Student Management – Bulk CSV Import
 *
 * Accepts a CSV file with the following columns (header row required):
 *   ID_No          – Student ID (1–20 alphanumeric/hyphen chars). Leave blank to auto-generate.
 *   Student_Name   – Full name (required)
 *   Department     – Department name or code (must match dept_departments.name or .code)
 *   Program        – Program name (optional; matched against dept_academic_programs.program_name)
 *   Batch          – Batch name (optional; matched against student_batches.name)
 *
 * The page offers a two-step flow:
 *   Step 1 – Upload CSV  → preview table with per-row validation results.
 *   Step 2 – Confirm     → import all valid rows; report outcomes.
 *
 * Extra/unknown columns are silently ignored so users can keep a wider spreadsheet.
 */

require_once __DIR__ . '/../includes/auth.php';
require_access('students', 'can_create');
require_once __DIR__ . '/helpers.php';

$page_title = 'Bulk CSV Import';
$user       = auth_user();

// ── Load reference data ───────────────────────────────────────────────────────
$departments  = sm_dept_data();        // id, name, code, faculty_label
$all_programs = sm_program_data();     // id, dept_id, program_name, program_type
$batches      = sm_batches();          // id, name

// Build fast lookup maps (lower-cased for case-insensitive matching)
$dept_by_name = [];
$dept_by_code = [];
foreach ($departments as $d) {
    $dept_by_name[strtolower(trim($d['name']))] = $d;
    if ($d['code'] !== '') {
        $dept_by_code[strtolower(trim($d['code']))] = $d;
    }
}

$prog_by_name = []; // dept_id => [lower_program_name => program_row]
foreach ($all_programs as $p) {
    $prog_by_name[(int)$p['dept_id']][strtolower(trim($p['program_name']))] = $p;
}

$batch_by_name = []; // lower_name => row
foreach ($batches as $b) {
    $batch_by_name[strtolower(trim($b['name']))] = $b;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Normalise a CSV header string to a usable key.
 * Strips BOM, trims whitespace, converts to lower_snake_case.
 */
function ci_norm(string $s): string {
    $s = preg_replace('/^\xEF\xBB\xBF/', '', $s); // UTF-8 BOM
    return strtolower(trim(preg_replace('/[\s\-]+/', '_', $s)));
}

/**
 * Resolve a department record from user input (name or code).
 * Returns null when unresolvable.
 */
function ci_resolve_dept(string $input, array $by_name, array $by_code): ?array {
    $key = strtolower(trim($input));
    return $by_name[$key] ?? $by_code[$key] ?? null;
}

/**
 * Resolve a program record given a dept_id and user-supplied program name.
 * Returns null when unresolvable or when input is blank.
 */
function ci_resolve_prog(string $input, int $dept_id, array $prog_by_name): ?array {
    $key = strtolower(trim($input));
    if ($key === '') return null;
    return $prog_by_name[$dept_id][$key] ?? null;
}

/**
 * Resolve a batch record from user input.
 * Returns null when blank or unresolvable.
 */
function ci_resolve_batch(string $input, array $batch_by_name): ?array {
    $key = strtolower(trim($input));
    if ($key === '') return null;
    return $batch_by_name[$key] ?? null;
}

// ── Parse & validate a raw CSV row ────────────────────────────────────────────

/**
 * Given an associative CSV row (already mapped to canonical keys), validate it
 * and resolve IDs.  Returns an array with:
 *   'errors'     => string[]   (empty = row is valid)
 *   'warnings'   => string[]   (non-blocking issues)
 *   'student_id' => string     (resolved or blank-for-auto)
 *   'full_name'  => string
 *   'dept'       => array|null
 *   'program'    => array|null
 *   'batch'      => array|null
 */
function ci_validate_row(
    array $row,
    array $dept_by_name,
    array $dept_by_code,
    array $prog_by_name,
    array $batch_by_name
): array {
    $errors   = [];
    $warnings = [];

    $id_raw   = trim($row['id_no']          ?? '');
    $name_raw = trim($row['student_name']   ?? '');
    $dept_raw = trim($row['department']     ?? '');
    $prog_raw = trim($row['program']        ?? '');
    $batch_raw= trim($row['batch']          ?? '');

    // --- Student Name ---
    if ($name_raw === '') {
        $errors[] = 'Student_Name is required.';
    }

    // --- Student ID ---
    if ($id_raw !== '') {
        if (!preg_match('/^[a-zA-Z0-9\-]{1,20}$/', $id_raw)) {
            $errors[] = 'ID_No "' . htmlspecialchars($id_raw, ENT_QUOTES, 'UTF-8') . '" is invalid (1–20 alphanumeric/hyphen chars).';
            $id_raw = '';
        }
        // Duplicate check is deferred to import phase to avoid redundant queries during preview.
    }

    // --- Department ---
    $dept = null;
    if ($dept_raw === '') {
        $errors[] = 'Department is required.';
    } else {
        $dept = ci_resolve_dept($dept_raw, $dept_by_name, $dept_by_code);
        if ($dept === null) {
            $errors[] = 'Department "' . htmlspecialchars($dept_raw, ENT_QUOTES, 'UTF-8') . '" not found.';
        }
    }

    // --- Program ---
    $prog = null;
    if ($dept && $prog_raw !== '') {
        $prog = ci_resolve_prog($prog_raw, (int)$dept['id'], $prog_by_name);
        if ($prog === null) {
            $warnings[] = 'Program "' . htmlspecialchars($prog_raw, ENT_QUOTES, 'UTF-8') . '" not found for this department – will be left blank.';
        }
    }

    // --- Batch ---
    $batch = null;
    if ($batch_raw !== '') {
        $batch = ci_resolve_batch($batch_raw, $batch_by_name);
        if ($batch === null) {
            $warnings[] = 'Batch "' . htmlspecialchars($batch_raw, ENT_QUOTES, 'UTF-8') . '" not found – will be stored as text.';
        }
    }

    return [
        'errors'     => $errors,
        'warnings'   => $warnings,
        'student_id' => $id_raw,
        'full_name'  => $name_raw,
        'dept'       => $dept,
        'program'    => $prog,
        'batch_row'  => $batch,
        'batch_raw'  => $batch_raw,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// State vars
$preview_rows = null;  // null = not parsed yet
$parse_error  = null;
$import_done  = false;
$import_stats = [];
$step         = 'upload'; // 'upload' | 'preview' | 'done'

// ── STEP 1 – Upload and parse CSV ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'preview') {
    csrf_check();

    if (empty($_FILES['csv_file']['name']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $parse_error = 'Please choose a CSV file to upload.';
    } else {
        $csv_ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
        if ($csv_ext !== 'csv') {
            $parse_error = 'Only .csv files are accepted.';
        } else {
            $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
            if ($handle === false) {
                $parse_error = 'Could not read the uploaded file.';
            } else {
                // Read header
                $header_raw = fgetcsv($handle, 0, ',', '"', '\\');
                if ($header_raw === false || $header_raw === null) {
                    $parse_error = 'The CSV file is empty.';
                } else {
                    $header = array_map('ci_norm', $header_raw);

                    // Required column presence check
                    $required_cols = ['id_no', 'student_name', 'department'];
                    $missing = array_diff($required_cols, $header);
                    if ($missing) {
                        $parse_error = 'Missing required column(s): ' . implode(', ', array_map('strtoupper', $missing))
                                     . '. Expected header: ID_No, Student_Name, Department (plus optional Batch, Program).';
                    } else {
                        $preview_rows = [];
                        $row_num = 1;
                        while (($raw = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                            $row_num++;
                            // Skip completely blank rows
                            if (count(array_filter(array_map('trim', $raw))) === 0) {
                                continue;
                            }
                            // Map header keys → values
                            $assoc = [];
                            foreach ($header as $i => $key) {
                                $assoc[$key] = $raw[$i] ?? '';
                            }
                            $validated = ci_validate_row(
                                $assoc,
                                $dept_by_name, $dept_by_code,
                                $prog_by_name, $batch_by_name
                            );
                            $validated['row_num'] = $row_num;
                            $preview_rows[] = $validated;
                        }

                        if (empty($preview_rows)) {
                            $parse_error = 'The CSV contains no data rows.';
                            $preview_rows = null;
                        } else {
                            $step = 'preview';
                            // Encode preview rows in session for the confirm step
                            $_SESSION['csv_import_rows'] = $preview_rows;
                        }
                    }
                }
                fclose($handle);
            }
        }
    }
}

// ── STEP 2 – Confirm and import ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import') {
    csrf_check();

    $rows_to_import = $_SESSION['csv_import_rows'] ?? [];
    unset($_SESSION['csv_import_rows']);

    if (empty($rows_to_import)) {
        flash_set('error', 'No import data found. Please re-upload the CSV.');
        redirect(APP_URL . '/students/csv-import.php');
    }

    $inserted = 0;
    $skipped  = 0;
    $row_results = [];

    $pdo = db();

    foreach ($rows_to_import as $r) {
        if (!empty($r['errors'])) {
            $row_results[] = [
                'row_num'    => $r['row_num'],
                'status'     => 'skipped',
                'student_id' => '',
                'full_name'  => $r['full_name'],
                'reason'     => implode('; ', $r['errors']),
            ];
            $skipped++;
            continue;
        }

        $dept    = $r['dept'];
        $prog    = $r['program'];
        $batch   = $r['batch_row'];
        $sid_in  = $r['student_id'];

        // Determine admitted_semester: we do not have it in CSV, default to current.
        // A sensible default: "Fall YYYY" based on current date.
        $admitted_sem = 'Fall ' . date('Y');

        // Resolve or generate student_id
        if ($sid_in !== '') {
            // Check uniqueness
            $chk = $pdo->prepare('SELECT id FROM students WHERE student_id = ?');
            $chk->execute([$sid_in]);
            if ($chk->fetchColumn()) {
                $row_results[] = [
                    'row_num'    => $r['row_num'],
                    'status'     => 'skipped',
                    'student_id' => $sid_in,
                    'full_name'  => $r['full_name'],
                    'reason'     => 'Student ID "' . htmlspecialchars($sid_in, ENT_QUOTES, 'UTF-8') . '" already exists.',
                ];
                $skipped++;
                continue;
            }
            $student_id = $sid_in;
        } else {
            $student_id = sm_generate_student_id(
                $admitted_sem,
                (int)$dept['id'],
                $prog ? (int)$prog['id'] : 0
            );
        }

        try {
            $pdo->prepare(
                'INSERT INTO students
                   (student_id, dept_id, program_id, admitted_semester, batch, batch_id,
                    full_name, faculty_label, status, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $student_id,
                (int)$dept['id'],
                $prog   ? (int)$prog['id'] : null,
                $admitted_sem,
                $batch  ? $batch['name'] : ($r['batch_raw'] ?: null),
                $batch  ? (int)$batch['id'] : null,
                $r['full_name'],
                $dept['faculty_label'] ?: null,
                'Active',
                $user['id'],
            ]);

            $new_pk = (int)$pdo->lastInsertId();

            log_change('students', 'CREATE', $new_pk,
                       $r['full_name'] . ' (' . $student_id . ')',
                       null, null, null,
                       'Bulk CSV import');

            $row_results[] = [
                'row_num'    => $r['row_num'],
                'status'     => 'inserted',
                'student_id' => $student_id,
                'full_name'  => $r['full_name'],
                'reason'     => '',
            ];
            $inserted++;
        } catch (PDOException $e) {
            $row_results[] = [
                'row_num'    => $r['row_num'],
                'status'     => 'error',
                'student_id' => $student_id,
                'full_name'  => $r['full_name'],
                'reason'     => 'DB error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'),
            ];
            $skipped++;
        }
    }

    $import_stats = ['inserted' => $inserted, 'skipped' => $skipped, 'rows' => $row_results];
    $import_done  = true;
    $step         = 'done';
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/students/index.php">Students</a></li>
            <li class="breadcrumb-item active">Bulk CSV Import</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/students/index.php" class="btn btn-outline-secondary btn-sm" style="border-radius:8px;">
        <i class="fas fa-arrow-left me-1"></i> Back to Students
    </a>
</div>

<?php if ($parse_error): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($parse_error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php /* ── STEP 1: Upload form ─────────────────────────────────────────── */ ?>
<?php if ($step === 'upload'): ?>

<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-file-csv me-2 text-muted"></i>Upload Student CSV</h6>
    </div>
    <div class="card-body">

        <div class="alert alert-info mb-4" style="font-size:.875rem;">
            <strong>CSV Format:</strong> The file must have a header row with these columns:
            <ul class="mb-1 mt-1">
                <li><code>ID_No</code> – Student ID (leave blank to auto-generate)</li>
                <li><code>Student_Name</code> – Full name <span class="text-danger">*</span></li>
                <li><code>Department</code> – Exact department name or code <span class="text-danger">*</span></li>
                <li><code>Program</code> – Program name (optional)</li>
                <li><code>Batch</code> – Batch name (optional)</li>
            </ul>
            Extra columns are ignored. Column names are case-insensitive.
        </div>

        <div class="mb-4">
            <a href="data:text/csv;charset=utf-8,ID_No%2CStudent_Name%2CDepartment%2CProgram%2CBatch%0A%2CJohn%20Doe%2CComputer%20Science%20%26%20Engineering%2CBSC%20CSE%2C42nd%0A2501030100012%2CJane%20Smith%2CBusiness%20Administration%2CMBA%2C"
               download="students_template.csv"
               class="btn btn-outline-secondary btn-sm" style="border-radius:8px;">
                <i class="fas fa-download me-1"></i> Download Sample CSV
            </a>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="preview">
            <div class="mb-3" style="max-width:480px;">
                <label class="form-label fw-semibold">Select CSV File</label>
                <input type="file" name="csv_file" id="csv_file" class="form-control" accept=".csv" required>
                <div class="form-text">Accepted format: .csv (UTF-8, comma-delimited)</div>
            </div>
            <button type="submit" class="btn btn-primary" style="border-radius:8px;">
                <i class="fas fa-search me-1"></i> Preview Import
            </button>
        </form>
    </div>
</div>

<?php /* ── STEP 2: Preview ────────────────────────────────────────────────── */ ?>
<?php elseif ($step === 'preview' && $preview_rows !== null): ?>

<?php
$valid_count   = count(array_filter($preview_rows, fn($r) => empty($r['errors'])));
$invalid_count = count($preview_rows) - $valid_count;
?>

<div class="alert <?= $valid_count > 0 ? 'alert-success' : 'alert-warning' ?> mb-4">
    <strong><?= $valid_count ?></strong> row(s) ready to import,
    <strong><?= $invalid_count ?></strong> row(s) will be skipped (errors).
    <?php if ($valid_count === 0): ?>
    Please fix the errors in your CSV and re-upload.
    <?php endif; ?>
</div>

<?php if ($valid_count > 0): ?>
<form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="import">
    <div class="d-flex gap-2 mb-3">
        <button type="submit" class="btn btn-success" style="border-radius:8px;"
                onclick="return confirm('Import <?= $valid_count ?> student(s) now?');">
            <i class="fas fa-file-import me-1"></i> Confirm &amp; Import <?= $valid_count ?> Student(s)
        </button>
        <a href="<?= APP_URL ?>/students/csv-import.php" class="btn btn-outline-secondary" style="border-radius:8px;">
            <i class="fas fa-redo me-1"></i> Re-upload
        </a>
    </div>
</form>
<?php else: ?>
<div class="mb-3">
    <a href="<?= APP_URL ?>/students/csv-import.php" class="btn btn-outline-secondary" style="border-radius:8px;">
        <i class="fas fa-redo me-1"></i> Re-upload
    </a>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-table me-2 text-muted"></i>Preview (<?= count($preview_rows) ?> rows)</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0" style="font-size:.85rem;">
                <thead class="table-light">
                    <tr>
                        <th class="px-3">#</th>
                        <th>Row</th>
                        <th>ID_No</th>
                        <th>Student_Name</th>
                        <th>Department</th>
                        <th>Program</th>
                        <th>Batch</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($preview_rows as $i => $r): ?>
                <?php
                    $has_errors   = !empty($r['errors']);
                    $has_warnings = !empty($r['warnings']);
                    $row_cls = $has_errors ? 'table-danger' : ($has_warnings ? 'table-warning' : '');
                ?>
                <tr class="<?= $row_cls ?>">
                    <td class="px-3"><?= $i + 1 ?></td>
                    <td><?= (int)$r['row_num'] ?></td>
                    <td>
                        <?php if ($r['student_id'] !== ''): ?>
                            <code><?= htmlspecialchars($r['student_id'], ENT_QUOTES, 'UTF-8') ?></code>
                        <?php else: ?>
                            <span class="text-muted fst-italic">auto</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($r['full_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <?php if ($r['dept']): ?>
                            <?= htmlspecialchars($r['dept']['name'], ENT_QUOTES, 'UTF-8') ?>
                        <?php else: ?>
                            <span class="text-danger">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($r['program']): ?>
                            <?= htmlspecialchars($r['program']['program_name'], ENT_QUOTES, 'UTF-8') ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($r['batch_row']): ?>
                            <?= htmlspecialchars($r['batch_row']['name'], ENT_QUOTES, 'UTF-8') ?>
                        <?php elseif ($r['batch_raw'] !== ''): ?>
                            <span class="text-warning"><?= htmlspecialchars($r['batch_raw'], ENT_QUOTES, 'UTF-8') ?> <small>(text)</small></span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($has_errors): ?>
                            <span class="text-danger fw-semibold"><i class="fas fa-times-circle me-1"></i>Error</span>
                            <ul class="mb-0 ps-3 mt-1" style="font-size:.78rem;">
                                <?php foreach ($r['errors'] as $e): ?>
                                <li><?= $e ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php elseif ($has_warnings): ?>
                            <span class="text-warning fw-semibold"><i class="fas fa-exclamation-triangle me-1"></i>Warning</span>
                            <ul class="mb-0 ps-3 mt-1" style="font-size:.78rem;">
                                <?php foreach ($r['warnings'] as $w): ?>
                                <li><?= $w ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <span class="text-success"><i class="fas fa-check-circle me-1"></i>OK</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php /* ── STEP 3: Done ──────────────────────────────────────────────────── */ ?>
<?php elseif ($step === 'done'): ?>

<div class="alert <?= $import_stats['inserted'] > 0 ? 'alert-success' : 'alert-warning' ?>">
    <strong><?= $import_stats['inserted'] ?></strong> student(s) imported successfully.
    <?php if ($import_stats['skipped'] > 0): ?>
    <strong><?= $import_stats['skipped'] ?></strong> row(s) skipped.
    <?php endif; ?>
</div>

<div class="d-flex gap-2 mb-4">
    <a href="<?= APP_URL ?>/students/index.php" class="btn btn-primary" style="border-radius:8px;">
        <i class="fas fa-users me-1"></i> View All Students
    </a>
    <a href="<?= APP_URL ?>/students/csv-import.php" class="btn btn-outline-secondary" style="border-radius:8px;">
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
                        <th>Name</th>
                        <th>Result</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($import_stats['rows'] as $r): ?>
                <?php $cls = $r['status'] === 'inserted' ? '' : 'table-danger'; ?>
                <tr class="<?= $cls ?>">
                    <td class="px-3"><?= (int)$r['row_num'] ?></td>
                    <td><code><?= htmlspecialchars($r['student_id'], ENT_QUOTES, 'UTF-8') ?></code></td>
                    <td><?= htmlspecialchars($r['full_name'],   ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <?php if ($r['status'] === 'inserted'): ?>
                            <span class="text-success"><i class="fas fa-check-circle me-1"></i>Imported</span>
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
