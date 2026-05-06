<?php
/**
 * Student Management – Bulk CSV / Excel Import
 *
 * Accepts a CSV (.csv) or Excel (.xlsx / .xls) file with a header row.
 *
 * Supported columns (case-insensitive, spaces/hyphens normalised to underscores):
 *   ID_No          – Student ID (1–20 alphanumeric/hyphen chars). Leave blank to auto-generate.
 *   Student_Name   – Full name (required)
 *   Batch          – Batch name (optional; matched against student_batches.name)
 *   Country        – Country (optional; defaults to Bangladesh)
 *   District       – Bangladesh district name (optional)
 *   Thana          – Bangladesh thana/upazila name within the district (optional)
 *   Mobile_Number  – Student mobile / phone (optional)
 *   Email          – Student email address (optional)
 *   Blood_Group    – One of A+, A-, B+, B-, AB+, AB-, O+, O- (optional)
 *   Department     – Department name or code (required; must match dept_departments)
 *   Program        – Program name (optional; matched against dept_academic_programs)
 *
 * The page offers a two-step flow:
 *   Step 1 – Upload file → preview table with per-row validation results.
 *   Step 2 – Confirm     → import all valid rows; report outcomes.
 *
 * Extra/unknown columns are silently ignored so users can keep a wider spreadsheet.
 */

require_once __DIR__ . '/../includes/auth.php';
require_access('students', 'can_create');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$page_title = 'Bulk Import (CSV / Excel)';
$user       = auth_user();

// ── Load reference data ───────────────────────────────────────────────────────
$departments  = sm_dept_data();        // id, name, code, faculty_label
$all_programs = sm_program_data();     // id, dept_id, program_name, program_type
$batches      = sm_batches();          // id, name
$districts    = sm_bd_districts();     // id, name, division
$thanas       = sm_bd_thanas();        // id, district_id, name

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

$district_by_name = []; // lower_name => row
foreach ($districts as $d) {
    $district_by_name[strtolower(trim($d['name']))] = $d;
}

$thana_by_did_name = []; // district_id => [lower_name => row]
foreach ($thanas as $t) {
    $thana_by_did_name[(int)$t['district_id']][strtolower(trim($t['name']))] = $t;
}

const CI_BLOOD_GROUPS = ['A+','A-','B+','B-','AB+','AB-','O+','O-'];

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Normalise a header string to a usable key.
 * Strips BOM, trims whitespace, converts spaces/hyphens to underscores, lower-cases.
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

/**
 * Resolve a district record from user input.
 * Returns null when blank or unresolvable.
 */
function ci_resolve_district(string $input, array $district_by_name): ?array {
    $key = strtolower(trim($input));
    if ($key === '') return null;
    return $district_by_name[$key] ?? null;
}

/**
 * Resolve a thana record given a district_id and user-supplied thana name.
 * Returns null when blank or unresolvable.
 */
function ci_resolve_thana(string $input, int $district_id, array $thana_by_did_name): ?array {
    $key = strtolower(trim($input));
    if ($key === '') return null;
    return $thana_by_did_name[$district_id][$key] ?? null;
}

/**
 * Read the first sheet of a spreadsheet file (xlsx/xls/csv) and return
 * an array of rows where each row is an array of string cell values.
 * Returns ['rows' => [...], 'error' => null] or ['rows' => [], 'error' => 'message'].
 */
function ci_read_spreadsheet(string $tmp_path, string $extension): array {
    try {
        $reader = IOFactory::createReaderForFile($tmp_path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($tmp_path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = [];
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

// ── Parse & validate a raw file row ──────────────────────────────────────────

/**
 * Given an associative row (already mapped to canonical keys), validate it
 * and resolve IDs.  Returns an array with:
 *   'errors'      => string[]   (empty = row is valid)
 *   'warnings'    => string[]   (non-blocking issues)
 *   'student_id'  => string     (resolved or blank-for-auto)
 *   'full_name'   => string
 *   'dept'        => array|null
 *   'program'     => array|null
 *   'batch_row'   => array|null
 *   'batch_raw'   => string
 *   'country'     => string
 *   'district'    => array|null
 *   'district_raw'=> string
 *   'thana'       => array|null
 *   'thana_raw'   => string
 *   'mobile'      => string
 *   'email'       => string
 *   'blood_group' => string
 *
 * Note: Duplicate checking is NOT performed here. The caller is responsible
 * for checking duplicates against the database and within the CSV file.
 */
function ci_validate_row(
    array $row,
    array $dept_by_name,
    array $dept_by_code,
    array $prog_by_name,
    array $batch_by_name,
    array $district_by_name,
    array $thana_by_did_name
): array {
    $errors   = [];
    $warnings = [];

    $id_raw       = trim($row['id_no']           ?? '');
    $name_raw     = trim($row['student_name']    ?? '');
    $dept_raw     = trim($row['department']      ?? '');
    $prog_raw     = trim($row['program']         ?? '');
    $batch_raw    = trim($row['batch']           ?? '');
    $country_raw  = trim($row['country']         ?? 'Bangladesh');
    $district_raw = trim($row['district']        ?? '');
    $thana_raw    = trim($row['thana']           ?? '');
    $mobile_raw   = trim($row['mobile_number']   ?? $row['mobile'] ?? '');
    $email_raw    = trim($row['email']           ?? '');
    $blood_raw    = trim($row['blood_group']     ?? '');

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
        // Note: Duplicate checking is performed by the caller using batch queries for performance
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

    // --- District ---
    $district = null;
    if ($district_raw !== '') {
        $district = ci_resolve_district($district_raw, $district_by_name);
        if ($district === null) {
            $warnings[] = 'District "' . htmlspecialchars($district_raw, ENT_QUOTES, 'UTF-8') . '" not found – will be left blank.';
        }
    }

    // --- Thana ---
    $thana = null;
    if ($thana_raw !== '' && $district !== null) {
        $thana = ci_resolve_thana($thana_raw, (int)$district['id'], $thana_by_did_name);
        if ($thana === null) {
            $warnings[] = 'Thana "' . htmlspecialchars($thana_raw, ENT_QUOTES, 'UTF-8') . '" not found in district – will be left blank.';
        }
    } elseif ($thana_raw !== '' && $district === null) {
        $warnings[] = 'Thana "' . htmlspecialchars($thana_raw, ENT_QUOTES, 'UTF-8') . '" ignored (district not resolved).';
    }

    // --- Email ---
    if ($email_raw !== '' && !filter_var($email_raw, FILTER_VALIDATE_EMAIL)) {
        $warnings[] = 'Email "' . htmlspecialchars($email_raw, ENT_QUOTES, 'UTF-8') . '" looks invalid – will be stored as-is.';
    }

    // --- Blood Group ---
    if ($blood_raw !== '' && !in_array($blood_raw, CI_BLOOD_GROUPS, true)) {
        $warnings[] = 'Blood_Group "' . htmlspecialchars($blood_raw, ENT_QUOTES, 'UTF-8') . '" not recognised – will be left blank.';
        $blood_raw = '';
    }

    return [
        'errors'       => $errors,
        'warnings'     => $warnings,
        'student_id'   => $id_raw,
        'full_name'    => $name_raw,
        'dept'         => $dept,
        'program'      => $prog,
        'batch_row'    => $batch,
        'batch_raw'    => $batch_raw,
        'country'      => $country_raw ?: 'Bangladesh',
        'district'     => $district,
        'district_raw' => $district_raw,
        'thana'        => $thana,
        'thana_raw'    => $thana_raw,
        'mobile'       => $mobile_raw,
        'email'        => $email_raw,
        'blood_group'  => $blood_raw,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// State vars
$preview_rows = null;  // null = not parsed yet
$parse_error  = null;
$import_done  = false;
$import_stats = [];
$step         = 'upload'; // 'upload' | 'preview' | 'done'

// ── STEP 1 – Upload and parse file ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'preview') {
    csrf_check();

    $allowed_exts = ['csv', 'xlsx', 'xls'];

    if (empty($_FILES['csv_file']['name']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $parse_error = 'Please choose a file to upload.';
    } else {
        $file_ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($file_ext, $allowed_exts, true)) {
            $parse_error = 'Only .csv, .xlsx, and .xls files are accepted.';
        } else {
            // ── Read rows from file ──────────────────────────────────────────
            $all_rows  = [];
            $read_err  = null;

            if ($file_ext === 'csv') {
                $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
                if ($handle === false) {
                    $read_err = 'Could not read the uploaded file.';
                } else {
                    while (($raw = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                        $all_rows[] = array_map('strval', $raw);
                    }
                    fclose($handle);
                }
            } else {
                $result   = ci_read_spreadsheet($_FILES['csv_file']['tmp_name'], $file_ext);
                $read_err = $result['error'];
                $all_rows = $result['rows'];
            }

            if ($read_err !== null) {
                $parse_error = $read_err;
            } elseif (empty($all_rows)) {
                $parse_error = 'The file is empty.';
            } else {
                // First non-empty row is the header
                $header_raw = array_shift($all_rows);
                if (empty($header_raw)) {
                    $parse_error = 'The file has no header row.';
                } else {
                    $header = array_map('ci_norm', $header_raw);

                    // Required column presence check
                    $required_cols = ['id_no', 'student_name', 'department'];
                    $missing = array_diff($required_cols, $header);
                    if ($missing) {
                        $parse_error = 'Missing required column(s): ' . implode(', ', array_map('strtoupper', $missing))
                                     . '. Expected header includes: ID_No, Student_Name, Department.';
                    } else {
                        $preview_rows = [];
                        $row_num = 1;
                        $pdo = db(); // Get database connection for duplicate checking
                        
                        // First pass: collect all student IDs from CSV for batch duplicate checking
                        $csv_student_ids = [];
                        $temp_rows = [];
                        foreach ($all_rows as $raw) {
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
                            $temp_rows[] = ['row_num' => $row_num, 'assoc' => $assoc];
                            
                            $sid = trim($assoc['id_no'] ?? '');
                            if ($sid !== '') {
                                $csv_student_ids[] = $sid;
                            }
                        }
                        
                        // Batch check for existing student IDs in database (chunked to avoid SQL limits)
                        $existing_ids = [];
                        if (!empty($csv_student_ids)) {
                            // Chunk into batches of 500 to avoid exceeding database placeholder limits
                            $chunks = array_chunk($csv_student_ids, 500);
                            foreach ($chunks as $chunk) {
                                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                                $stmt = $pdo->prepare("SELECT student_id FROM students WHERE student_id IN ($placeholders)");
                                $stmt->execute($chunk);
                                while ($row = $stmt->fetch(PDO::FETCH_COLUMN)) {
                                    $existing_ids[$row] = true;
                                }
                            }
                        }
                        
                        // Second pass: validate rows with pre-loaded duplicate info
                        $seen_student_ids = []; // Track student IDs within this CSV file
                        foreach ($temp_rows as $temp) {
                            $assoc = $temp['assoc'];
                            $row_num = $temp['row_num'];
                            
                            $validated = ci_validate_row(
                                $assoc,
                                $dept_by_name, $dept_by_code,
                                $prog_by_name, $batch_by_name,
                                $district_by_name, $thana_by_did_name
                            );
                            
                            // Check for duplicates using pre-loaded data
                            $sid = trim($assoc['id_no'] ?? '');
                            if ($sid !== '') {
                                // Check against database
                                if (isset($existing_ids[$sid])) {
                                    $validated['errors'][] = 'Student ID "' . htmlspecialchars($sid, ENT_QUOTES, 'UTF-8') . '" already exists in database (duplicate).';
                                }
                                // Check within CSV file
                                if (isset($seen_student_ids[$sid])) {
                                    $validated['errors'][] = 'Student ID "' . htmlspecialchars($sid, ENT_QUOTES, 'UTF-8') . '" appears multiple times in this CSV file (first seen in row ' . $seen_student_ids[$sid] . ').';
                                } else {
                                    $seen_student_ids[$sid] = $row_num;
                                }
                            }
                            
                            $validated['row_num'] = $row_num;
                            $preview_rows[] = $validated;
                        }

                        if (empty($preview_rows)) {
                            $parse_error  = 'The file contains no data rows.';
                            $preview_rows = null;
                        } else {
                            $step = 'preview';
                            // Encode preview rows in session for the confirm step
                            $_SESSION['csv_import_rows'] = $preview_rows;
                        }
                    }
                }
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
                    full_name, faculty_label, country, district_id, thana_id,
                    phone, email, blood_group, status, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $student_id,
                (int)$dept['id'],
                $prog   ? (int)$prog['id'] : null,
                $admitted_sem,
                $batch  ? $batch['name'] : ($r['batch_raw'] ?: null),
                $batch  ? (int)$batch['id'] : null,
                $r['full_name'],
                $dept['faculty_label'] ?: null,
                $r['country'] ?: 'Bangladesh',
                $r['district'] ? (int)$r['district']['id'] : null,
                $r['thana']    ? (int)$r['thana']['id']    : null,
                $r['mobile']   ?: null,
                $r['email']    ?: null,
                $r['blood_group'] ?: null,
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
            <li class="breadcrumb-item active">Bulk Import</li>
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
        <h6 class="mb-0 fw-semibold"><i class="fas fa-file-import me-2 text-muted"></i>Upload Student File (CSV or Excel)</h6>
    </div>
    <div class="card-body">

        <div class="alert alert-info mb-4" style="font-size:.875rem;">
            <strong>File Format:</strong> The file must have a header row. Supported columns (case-insensitive):
            <div class="row mt-2">
                <div class="col-md-6">
                    <ul class="mb-1">
                        <li><code>ID_No</code> – Student ID (leave blank to auto-generate)</li>
                        <li><code>Student_Name</code> – Full name <span class="text-danger">*</span></li>
                        <li><code>Batch</code> – Batch name (optional)</li>
                        <li><code>Country</code> – Country (optional, defaults to Bangladesh)</li>
                        <li><code>District</code> – Bangladesh district (optional)</li>
                        <li><code>Thana</code> – Thana/Upazila (optional)</li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <ul class="mb-1">
                        <li><code>Mobile_Number</code> – Student mobile/phone (also accepted as <code>Mobile</code>) (optional)</li>
                        <li><code>Email</code> – Email address (optional)</li>
                        <li><code>Blood_Group</code> – e.g. A+, B-, O+ (optional)</li>
                        <li><code>Department</code> – Dept name or code <span class="text-danger">*</span></li>
                        <li><code>Program</code> – Program name (optional)</li>
                    </ul>
                </div>
            </div>
            Extra columns are ignored. <span class="text-danger">*</span> Required.
        </div>

        <div class="mb-4">
            <a href="data:text/csv;charset=utf-8,ID_No%2CStudent_Name%2CBatch%2CCountry%2CDistrict%2CThana%2CMobile_Number%2CEmail%2CBlood_Group%2CDepartment%2CProgram%0A%2CJohn%20Doe%2C42nd%2CBangladesh%2CDhaka%2CDhanmondi%2C01711000000%2Cjohn%40example.com%2CA%2B%2CComputer%20Science%20%26%20Engineering%2CBSC%20CSE%0A2501030100012%2CJane%20Smith%2C43rd%2CBangladesh%2C%2C%2C01811000000%2Cjane%40example.com%2CB%2B%2CBusiness%20Administration%2CMBA"
               download="students_template.csv"
               class="btn btn-outline-secondary btn-sm" style="border-radius:8px;">
                <i class="fas fa-download me-1"></i> Download Sample CSV Template
            </a>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="preview">
            <div class="mb-3" style="max-width:480px;">
                <label class="form-label fw-semibold">Select File</label>
                <input type="file" name="csv_file" id="csv_file" class="form-control" accept=".csv,.xlsx,.xls" required>
                <div class="form-text">Accepted formats: .csv (UTF-8, comma-delimited), .xlsx, .xls</div>
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
    Please fix the errors in your file and re-upload.
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
            <table class="table table-hover table-sm mb-0" style="font-size:.82rem;">
                <thead class="table-light">
                    <tr>
                        <th class="px-3">#</th>
                        <th>Row</th>
                        <th>ID_No</th>
                        <th>Student_Name</th>
                        <th>Batch</th>
                        <th>Country</th>
                        <th>District</th>
                        <th>Thana</th>
                        <th>Mobile</th>
                        <th>Email</th>
                        <th>Blood</th>
                        <th>Department</th>
                        <th>Program</th>
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
                        <?php if ($r['batch_row']): ?>
                            <?= htmlspecialchars($r['batch_row']['name'], ENT_QUOTES, 'UTF-8') ?>
                        <?php elseif ($r['batch_raw'] !== ''): ?>
                            <span class="text-warning"><?= htmlspecialchars($r['batch_raw'], ENT_QUOTES, 'UTF-8') ?> <small>(text)</small></span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($r['country'] ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <?php if ($r['district']): ?>
                            <?= htmlspecialchars($r['district']['name'], ENT_QUOTES, 'UTF-8') ?>
                        <?php elseif ($r['district_raw'] !== ''): ?>
                            <span class="text-warning"><?= htmlspecialchars($r['district_raw'], ENT_QUOTES, 'UTF-8') ?> <small>(?)</small></span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($r['thana']): ?>
                            <?= htmlspecialchars($r['thana']['name'], ENT_QUOTES, 'UTF-8') ?>
                        <?php elseif ($r['thana_raw'] !== ''): ?>
                            <span class="text-warning"><?= htmlspecialchars($r['thana_raw'], ENT_QUOTES, 'UTF-8') ?> <small>(?)</small></span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($r['mobile'] !== ''): ?>
                            <?= htmlspecialchars($r['mobile'], ENT_QUOTES, 'UTF-8') ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($r['email'] !== ''): ?>
                            <?= htmlspecialchars($r['email'], ENT_QUOTES, 'UTF-8') ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($r['blood_group'] !== ''): ?>
                            <?= htmlspecialchars($r['blood_group'], ENT_QUOTES, 'UTF-8') ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
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
