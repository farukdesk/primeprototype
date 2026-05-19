<?php
/**
 * Tabulation Sheet Checker
 *
 * Accepts an Excel / CSV tabulation file with up to 6 sheets.
 * Sheets 1-4 (or however many) contain per-subject columns:
 *   Grade | Grade Point | Cr. Hr. | Semester  (repeating for each subject)
 * The last data sheet contains a "Total Credits", "CGPA", and "Remarks" column.
 *
 * The checker re-calculates each student's CGPA from all subject sheets and
 * compares it to the declared CGPA.  Discrepancies are highlighted with the
 * corrected value.
 *
 * CGPA formula (per university specification)
 * ────────────────────────────────────────────
 *   Step 1 – Convert letter grade → grade point (A+ = 4.00, A = 3.75, …)
 *   Step 2 – Quality Points for a course = Credit Hours × Grade Point
 *   Step 3 – Total Quality Points = Σ(Quality Points across ALL courses)
 *   Step 4 – Total Credit Hours Attempted = Σ(Credit Hours of all graded courses)
 *             NOTE: "F" (0.00) is graded → included; "INCOM" (no grade point) → excluded
 *   Step 5 – CGPA = Total Quality Points / Total Credit Hours Attempted  (rounded to 2 dp)
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// PhpSpreadsheet is bundled via composer
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

$page_title = 'Tabulation Sheet Checker';

// Maximum sheets supported (documented in UI and enforced here)
const TC_MAX_SHEETS      = 6;
// Rounding tolerance when comparing declared vs calculated CGPA
const TC_CGPA_TOLERANCE  = 0.005;

/* ── helpers ────────────────────────────────────────────────────────────────── */

/**
 * Round a CGPA value to 2 decimal places using standard rounding.
 */
function tc_round(float $v): float
{
    return round($v, 2);
}

/**
 * Parse one subject block (4 columns: Grade, Grade Point, Cr. Hr., Semester)
 * starting at column offset $col_offset (0-based) in $row.
 * Returns ['grade_point' => float|null, 'credit' => float|null].
 */
function tc_parse_subject_block(array $row, int $col_offset): array
{
    $grade_point_raw = trim((string)($row[$col_offset + 1] ?? ''));
    $credit_raw      = trim((string)($row[$col_offset + 2] ?? ''));

    $grade_point = is_numeric($grade_point_raw) ? (float)$grade_point_raw : null;
    $credit      = is_numeric($credit_raw)      ? (float)$credit_raw      : null;

    return ['grade_point' => $grade_point, 'credit' => $credit];
}

/**
 * Given a worksheet, find all subject blocks and return per-student data.
 *
 * Layout detection strategy
 * ─────────────────────────
 * The tabulation format has header rows followed by data rows.
 * Data rows are identified by: column 0 (or 1) contains a recognisable
 * student ID (numeric string ≥ 8 chars) or the Sl.No. column is numeric.
 *
 * Subject blocks start after the ID + Name columns.
 * Each block is exactly 4 columns wide: Grade, Grade point, Cr. Hr., Semester.
 *
 * Returns array keyed by student ID:
 *   [ 'name' => string, 'subjects' => [['grade_point'=>float|null,'credit'=>float|null], ...] ]
 *
 * For the CGPA sheet it also returns 'total_credits', 'cgpa', 'remarks'.
 */
function tc_parse_sheet(array $rows, bool $is_cgpa_sheet = false): array
{
    $students = [];

    // ── Step 1: Detect ID / Name column and data start row ───────────────────
    $data_start = 0;
    $id_col     = -1;
    $name_col   = -1;

    // Primary scan: look for an explicit "ID No." header cell.
    foreach ($rows as $ri => $row) {
        foreach ($row as $ci => $cell) {
            $val = strtolower(trim((string)$cell));
            if ($val === 'id no.' || $val === 'id no') {
                $id_col     = $ci;
                $name_col   = $ci + 1;
                $data_start = $ri + 1;
                break 2;
            }
        }
    }

    // Fallback: some sheets omit the "ID No." header row.  Scan rows for the
    // first cell that looks like a long numeric student ID (possibly stored as
    // a float, e.g. "282210004081002.0") and treat that row as data start.
    if ($id_col < 0) {
        foreach ($rows as $ri => $row) {
            foreach ($row as $ci => $cell) {
                $val  = trim((string)$cell);
                // Normalize float-formatted IDs ("282210004081002.0" → "282210004081002")
                $norm = preg_match('/^(\d+)\.0+$/', $val, $m) ? $m[1] : $val;
                if (preg_match('/^\d{8,}$/', $norm)) {
                    $id_col     = $ci;
                    $name_col   = $ci + 1;
                    $data_start = $ri;   // this row is the first data row
                    break 2;
                }
            }
        }
    }

    if ($id_col < 0) {
        $id_col   = 0;
        $name_col = 1;
    }

    // ── Step 2: CGPA sheet – locate Total Credits, CGPA, Remarks columns ─────
    $total_credits_col = -1;
    $cgpa_col          = -1;
    $remarks_col       = -1;

    if ($is_cgpa_sheet) {
        foreach ($rows as $ri => $row) {
            foreach ($row as $ci => $cell) {
                $val = strtolower(trim((string)$cell));
                // Accept both "Total Credits" and plain "Credits" headings
                if (in_array($val, ['total credits', 'total credit', 'credits'], true)) {
                    $total_credits_col = $ci;
                }
                if ($val === 'cgpa') {
                    $cgpa_col = $ci;
                }
                if ($val === 'remarks') {
                    $remarks_col = $ci;
                }
            }
            if ($cgpa_col >= 0) break;
        }
    }

    // ── Step 3: Detect Grade point and Cr. Hr. column positions ──────────────
    // Scan every row that appears before the first actual student data row.
    // This handles sheets with variable block widths: the standard 4-column
    // layout (Grade | Grade point | Cr. Hr. | Semester) and the 7-column
    // major-course layout (Course | Title | blank | Grade | Grade point |
    // Cr. Hr. | Semester) both used in tabulation files from this university.
    $grade_point_cols = [];
    $credit_cols      = [];

    // Find the index of the first row that actually contains a student ID so
    // we know which rows are header rows.
    $first_data_row = PHP_INT_MAX;
    foreach ($rows as $ri => $row) {
        if ($ri < $data_start) continue;
        $id_raw = ltrim(trim((string)($row[$id_col] ?? '')), "'");
        $norm   = preg_match('/^(\d+)\.0+$/', $id_raw, $m) ? $m[1] : $id_raw;
        if ($norm !== '' && $norm !== '0' && preg_match('/^\d{8,}$/', $norm)) {
            $first_data_row = $ri;
            break;
        }
    }

    foreach ($rows as $ri => $row) {
        if ($ri >= $first_data_row) break;
        foreach ($row as $ci => $cell) {
            $val = strtolower(trim((string)$cell));
            if ($val === 'grade point') {
                $grade_point_cols[] = $ci;
            }
            if ($val === 'cr. hr.' || $val === 'cr.hr.' || $val === 'cr hr') {
                $credit_cols[] = $ci;
            }
        }
    }

    $subject_data_start_col = $name_col + 1;

    // ── Step 4: Process data rows ─────────────────────────────────────────────
    foreach ($rows as $ri => $row) {
        if ($ri < $data_start) continue;

        $id_val = trim((string)($row[$id_col] ?? ''));
        // Some Excel exports prefix IDs with an apostrophe (text marker).
        $id_val = ltrim($id_val, "'");
        // Excel stores numeric IDs as floats ("282210004081002.0"); normalise
        // to a plain integer string so the regex below matches correctly.
        if (preg_match('/^(\d+)\.0+$/', $id_val, $m)) {
            $id_val = $m[1];
        }

        // Skip empty or non-student rows
        if ($id_val === '' || $id_val === '0') continue;
        // Student IDs at Prime are long numeric strings (≥ 8 digits)
        if (!preg_match('/^\d{8,}$/', $id_val)) continue;

        $name = trim((string)($row[$name_col] ?? ''));

        // Determine the last column to consider for subject data
        $row_len = count($row);
        $end_col = $is_cgpa_sheet && $total_credits_col >= 0
            ? $total_credits_col
            : $row_len;

        // Collect grade_point × credit pairs
        $subjects = [];

        if (!empty($grade_point_cols)) {
            // Header-guided extraction: works with any block width.
            foreach ($grade_point_cols as $idx => $gp_col) {
                if ($gp_col >= $end_col) continue;
                $cr_col = $credit_cols[$idx] ?? ($gp_col + 1);
                if ($cr_col >= $end_col) continue;
                $gp_raw = trim((string)($row[$gp_col] ?? ''));
                $cr_raw = trim((string)($row[$cr_col] ?? ''));
                $grade_point = is_numeric($gp_raw) ? (float)$gp_raw : null;
                $credit      = is_numeric($cr_raw)  ? (float)$cr_raw  : null;
                if ($grade_point !== null || $credit !== null) {
                    $subjects[] = ['grade_point' => $grade_point, 'credit' => $credit];
                }
            }
        } else {
            // Fallback: stride 4 columns (Grade | Grade point | Cr. Hr. | Semester)
            $col = $subject_data_start_col;
            while ($col + 3 < $end_col) {
                $block = tc_parse_subject_block($row, $col);
                if ($block['grade_point'] !== null || $block['credit'] !== null) {
                    $subjects[] = $block;
                }
                $col += 4;
            }
        }

        $entry = [
            'name'     => $name,
            'subjects' => $subjects,
        ];

        if ($is_cgpa_sheet) {
            $entry['total_credits'] = ($total_credits_col >= 0 && is_numeric($row[$total_credits_col] ?? ''))
                ? (float)$row[$total_credits_col]
                : null;
            $entry['cgpa'] = ($cgpa_col >= 0 && is_numeric($row[$cgpa_col] ?? ''))
                ? (float)$row[$cgpa_col]
                : null;
            $entry['remarks'] = $remarks_col >= 0 ? trim((string)($row[$remarks_col] ?? '')) : '';
        }

        if (isset($students[$id_val])) {
            // Merge subjects from multiple sheets
            $students[$id_val]['subjects'] = array_merge(
                $students[$id_val]['subjects'],
                $subjects
            );
            if ($is_cgpa_sheet) {
                $students[$id_val]['total_credits'] = $entry['total_credits'];
                $students[$id_val]['cgpa']          = $entry['cgpa'];
                $students[$id_val]['remarks']       = $entry['remarks'];
            }
        } else {
            $students[$id_val] = $entry;
        }
    }

    return $students;
}

/**
 * Convert a PhpSpreadsheet worksheet to a 2-D array (0-indexed rows and cols).
 */
function tc_worksheet_to_array(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws): array
{
    $data = $ws->toArray(null, true, false, false);
    // Normalise: make every row the same width
    $max_cols = 0;
    foreach ($data as $row) {
        $max_cols = max($max_cols, count($row));
    }
    foreach ($data as &$row) {
        while (count($row) < $max_cols) {
            $row[] = '';
        }
    }
    return $data;
}

/* ── request handling ───────────────────────────────────────────────────────── */

$errors  = [];
$results = [];   // per-student check results

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['tabulation_file'])) {
    $file = $_FILES['tabulation_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'File upload failed (error code ' . (int)$file['error'] . ').';
    } else {
        $allowed_ext = ['csv', 'xls', 'xlsx', 'ods'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed_ext, true)) {
            $errors[] = 'Unsupported file type. Please upload CSV, XLS, XLSX, or ODS.';
        } else {
            try {
                // Load spreadsheet.
                // IOFactory::load() uses the file path for format auto-detection;
                // PHP temp files have no extension, which can cause "File appears
                // to be corrupt" errors on some formats.  Use the extension the
                // user provided to pick the correct reader first, then fall back
                // to trying every other reader before giving up.
                $reader_class_map = [
                    'xlsx' => 'Xlsx',
                    'xls'  => 'Xls',
                    'ods'  => 'Ods',
                    'csv'  => 'Csv',
                ];

                // Try the extension-matched reader first, then the rest.
                $try_order = [$ext];
                foreach (array_keys($reader_class_map) as $_k) {
                    if ($_k !== $ext) $try_order[] = $_k;
                }

                $spreadsheet = null;
                $last_load_error = null;
                foreach ($try_order as $_try_ext) {
                    if (!isset($reader_class_map[$_try_ext])) continue;
                    try {
                        $reader = IOFactory::createReader($reader_class_map[$_try_ext]);
                        // readDataOnly skips formula/style parsing which can
                        // choke on non-standard files; we only need raw values.
                        if (method_exists($reader, 'setReadDataOnly')) {
                            $reader->setReadDataOnly(true);
                        }
                        $spreadsheet = $reader->load($file['tmp_name']);
                        break;
                    } catch (\Throwable $_re) {
                        $last_load_error = $_re;
                    }
                }

                if ($spreadsheet === null) {
                    throw $last_load_error ?? new \RuntimeException(
                        'Unable to read the uploaded file. Please verify the file is not corrupted and matches one of the supported formats: CSV, XLS, XLSX, or ODS.'
                    );
                }
                $sheet_count = $spreadsheet->getSheetCount();

                if ($sheet_count > TC_MAX_SHEETS) {
                    $errors[] = 'The file contains ' . $sheet_count . ' sheets. A maximum of ' . TC_MAX_SHEETS . ' sheets is supported. Please remove extra sheets and re-upload.';
                } else {

                // Merge student data across all sheets
                $all_students = [];

                for ($si = 0; $si < $sheet_count; $si++) {
                    $ws   = $spreadsheet->getSheet($si);
                    $rows = tc_worksheet_to_array($ws);

                    $is_cgpa_sheet = false;

                    // Detect CGPA sheet: look for "CGPA" column header
                    foreach ($rows as $row) {
                        foreach ($row as $cell) {
                            if (strtolower(trim((string)$cell)) === 'cgpa') {
                                $is_cgpa_sheet = true;
                                break 2;
                            }
                        }
                    }

                    $sheet_students = tc_parse_sheet($rows, $is_cgpa_sheet);

                    foreach ($sheet_students as $id => $data) {
                        if (!isset($all_students[$id])) {
                            $all_students[$id] = [
                                'name'     => $data['name'],
                                'subjects' => [],
                            ];
                        }
                        $all_students[$id]['subjects'] = array_merge(
                            $all_students[$id]['subjects'],
                            $data['subjects']
                        );
                        if ($is_cgpa_sheet) {
                            $all_students[$id]['declared_total_credits'] = $data['total_credits'] ?? null;
                            $all_students[$id]['declared_cgpa']          = $data['cgpa']          ?? null;
                            $all_students[$id]['remarks']                = $data['remarks']        ?? '';
                        }
                    }
                }

                if (empty($all_students)) {
                    $errors[] = 'No student data could be detected. Please check the file format matches the expected tabulation layout.';
                }

                // Calculate CGPA for each student
                // ─────────────────────────────────────────────────────────
                // Formula: CGPA = Total Quality Points / Total Credit Hours Attempted
                //   Quality Points for one course = Credit Hours × Grade Point
                //   "F" grade (0.00) counts as attempted → included
                //   "INCOM" (null grade point) → NOT yet graded → excluded
                foreach ($all_students as $id => &$student) {
                    $total_quality_points       = 0.0;
                    $total_credit_hours_attempted = 0.0;

                    foreach ($student['subjects'] as $subj) {
                        // Only include courses that have a numeric grade point (handles INCOM exclusion)
                        // F grade = 0.00 is numeric, so it is correctly included as attempted
                        if ($subj['grade_point'] !== null && $subj['credit'] !== null && $subj['credit'] > 0) {
                            $total_quality_points         += $subj['grade_point'] * $subj['credit'];
                            $total_credit_hours_attempted += $subj['credit'];
                        }
                    }

                    $calculated_cgpa = $total_credit_hours_attempted > 0
                        ? tc_round($total_quality_points / $total_credit_hours_attempted)
                        : null;
                    $declared_cgpa   = $student['declared_cgpa'] ?? null;

                    $diff = ($calculated_cgpa !== null && $declared_cgpa !== null)
                          ? abs($calculated_cgpa - $declared_cgpa)
                          : null;
                    $ok   = $diff !== null && $diff < TC_CGPA_TOLERANCE;

                    $student['calculated_cgpa']             = $calculated_cgpa;
                    $student['cgpa_ok']                     = $ok;
                    $student['cgpa_diff']                   = $diff;
                    $student['total_quality_points']        = $total_quality_points;
                    $student['total_credit_hours_attempted'] = $total_credit_hours_attempted;
                    // legacy keys kept for template
                    $student['sum_weighted']  = $total_quality_points;
                    $student['sum_credits']   = $total_credit_hours_attempted;
                    $student['subject_count'] = count($student['subjects']);
                }
                unset($student);

                $results = $all_students;

                } // end else (sheet_count <= TC_MAX_SHEETS)

            } catch (\Throwable $e) {
                $errors[] = 'Could not read the file: ' . h($e->getMessage());
            }
        }
    }
}

/* ── summary counts ─────────────────────────────────────────────────────────── */
$count_ok    = 0;
$count_wrong = 0;
$count_missing = 0;

foreach ($results as $s) {
    if ($s['declared_cgpa'] === null || $s['calculated_cgpa'] === null) {
        $count_missing++;
    } elseif ($s['cgpa_ok']) {
        $count_ok++;
    } else {
        $count_wrong++;
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Tabulation Sheet Checker</li>
        </ol>
    </nav>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $err): ?>
        <li><?= h($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- Upload card -->
<div class="card mb-4">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-file-excel me-2 text-success"></i>Upload Tabulation File</h6>
    </div>
    <div class="card-body p-4">
        <form method="post" enctype="multipart/form-data">
            <?php csrf_field(); ?>
            <div class="row g-3 align-items-end">
                <div class="col-md-8">
                    <label for="tabulation_file" class="form-label fw-medium">Select File
                        <span class="text-muted fw-normal">(CSV, XLS, XLSX, ODS — up to 6 sheets supported)</span>
                    </label>
                    <input type="file" class="form-control" id="tabulation_file" name="tabulation_file"
                           accept=".csv,.xls,.xlsx,.ods" required>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100" style="border-radius:10px;">
                        <i class="fas fa-search me-1"></i> Check CGPA
                    </button>
                </div>
            </div>
            <div class="mt-3 text-muted" style="font-size:.82rem;">
                <i class="fas fa-info-circle me-1"></i>
                Expected layout: subject sheets contain repeating blocks of
                <strong>Grade | Grade Point | Cr. Hr. | Semester</strong> columns,
                and the last data sheet has <strong>Total Credits</strong>, <strong>CGPA</strong>, and <strong>Remarks</strong> columns.
            </div>
        </form>
    </div>
</div>

<!-- CGPA Formula Reference Card -->
<div class="card mb-4" style="border-left:4px solid #4f8ef7;">
    <div class="card-body py-3 px-4">
        <div class="d-flex align-items-center gap-2 mb-3">
            <i class="fas fa-calculator text-primary fs-5"></i>
            <h6 class="mb-0 fw-semibold">CGPA Calculation Formula</h6>
            <button class="btn btn-sm btn-outline-secondary ms-auto" type="button"
                    data-bs-toggle="collapse" data-bs-target="#formula-steps" style="border-radius:8px;font-size:.75rem;">
                <i class="fas fa-chevron-down me-1"></i>Show / Hide Steps
            </button>
        </div>

        <div class="p-3 mb-3 rounded" style="background:#f0f4ff;font-family:monospace;font-size:.92rem;">
            <strong>CGPA</strong> = Total Quality Points Earned &divide; Total Credit Hours Attempted
            <br><small class="text-muted" style="font-family:sans-serif;">
                where <em>Quality Points for one course</em> = Credit Hours &times; Grade Point
            </small>
        </div>

        <div class="collapse show" id="formula-steps">
            <ol class="mb-0" style="font-size:.875rem;line-height:1.8;">
                <li><strong>Step 1 — Convert grades to numbers:</strong>
                    A+ = 4.00 &nbsp;|&nbsp; A = 3.75 &nbsp;|&nbsp; A− = 3.50 &nbsp;|&nbsp;
                    B+ = 3.25 &nbsp;|&nbsp; B = 3.00 &nbsp;|&nbsp; B− = 2.75 &nbsp;|&nbsp;
                    C+ = 2.50 &nbsp;|&nbsp; C = 2.25 &nbsp;|&nbsp; D = 2.00 &nbsp;|&nbsp;
                    F = 0.00
                </li>
                <li><strong>Step 2 — Quality Points per course:</strong>
                    Multiply each course's Credit Hours by its Grade Point.
                </li>
                <li><strong>Step 3 — Total Quality Points:</strong>
                    Add up all Quality Points across every course in every semester.
                </li>
                <li><strong>Step 4 — Total Credit Hours Attempted:</strong>
                    Add up the Credit Hours of every <em>graded</em> course
                    (<span class="badge bg-danger" style="font-size:.7rem;">F (0.00) is included</span>
                     &nbsp;—&nbsp;
                     <span class="badge bg-secondary" style="font-size:.7rem;">INCOM is excluded</span>).
                </li>
                <li><strong>Step 5 — Divide and round:</strong>
                    CGPA = Total Quality Points ÷ Total Credit Hours Attempted, rounded to 2 decimal places.
                </li>
            </ol>
        </div>
    </div>
</div>

<?php if (!empty($results)): ?>

<!-- Summary -->
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card text-center py-3 border-0" style="background:#eafaf1;border-radius:12px;">
            <div class="fw-bold fs-3 text-success"><?= $count_ok ?></div>
            <div class="text-muted small"><i class="fas fa-check-circle text-success me-1"></i>CGPA Correct</div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card text-center py-3 border-0" style="background:#fef9e7;border-radius:12px;">
            <div class="fw-bold fs-3 text-danger"><?= $count_wrong ?></div>
            <div class="text-muted small"><i class="fas fa-exclamation-triangle text-danger me-1"></i>CGPA Mismatch</div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card text-center py-3 border-0" style="background:#fdf2f8;border-radius:12px;">
            <div class="fw-bold fs-3 text-secondary"><?= $count_missing ?></div>
            <div class="text-muted small"><i class="fas fa-question-circle text-secondary me-1"></i>Cannot Verify</div>
        </div>
    </div>
</div>

<!-- Results table -->
<div class="card">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-table me-2 text-muted"></i>CGPA Verification Results</h6>
        <span class="badge bg-secondary"><?= count($results) ?> students</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="cgpa-table">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">#</th>
                        <th>Student ID</th>
                        <th>Name</th>
                        <th class="text-center">Courses Found</th>
                        <th class="text-center">Total Quality Points</th>
                        <th class="text-center">Credit Hrs Attempted</th>
                        <th class="text-center">Declared CGPA</th>
                        <th class="text-center">Calculated CGPA</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Diff</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                <?php $i = 1; foreach ($results as $id => $s): ?>
                <?php
                    $declared   = $s['declared_cgpa']   ?? null;
                    $calculated = $s['calculated_cgpa']  ?? null;
                    $ok         = $s['cgpa_ok'] ?? false;
                    $diff       = $s['cgpa_diff'] ?? null;

                    if ($declared === null || $calculated === null) {
                        $row_class = '';
                        $badge     = '<span class="badge bg-secondary">No Data</span>';
                    } elseif ($ok) {
                        $row_class = '';
                        $badge     = '<span class="badge bg-success"><i class="fas fa-check me-1"></i>Correct</span>';
                    } else {
                        $row_class = 'table-danger';
                        $badge     = '<span class="badge bg-danger"><i class="fas fa-times me-1"></i>Mismatch</span>';
                    }
                ?>
                <tr class="<?= $row_class ?>">
                    <td class="px-4"><?= $i++ ?></td>
                    <td><code><?= h($id) ?></code></td>
                    <td><?= h($s['name']) ?></td>
                    <td class="text-center"><?= $s['subject_count'] ?></td>
                    <td class="text-center"><?= $s['total_quality_points'] > 0 ? number_format($s['total_quality_points'], 4) : '—' ?></td>
                    <td class="text-center"><?= $s['total_credit_hours_attempted'] > 0 ? number_format($s['total_credit_hours_attempted'], 2) : '—' ?></td>
                    <td class="text-center fw-medium">
                        <?= $declared !== null ? number_format($declared, 2) : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td class="text-center fw-bold <?= (!$ok && $calculated !== null && $declared !== null) ? 'text-danger' : 'text-success' ?>">
                        <?= $calculated !== null ? number_format($calculated, 2) : '<span class="text-muted fw-normal">—</span>' ?>
                    </td>
                    <td class="text-center"><?= $badge ?></td>
                    <td class="text-center text-muted small">
                        <?php if ($diff !== null): ?>
                            <?= $diff < 0.005 ? '0.00' : number_format($diff, 4) ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small"><?= h($s['remarks'] ?? '') ?></td>
                </tr>
                <?php if (!$ok && $calculated !== null && $declared !== null): ?>
                <tr class="table-warning">
                    <td colspan="11" class="px-4 py-2">
                        <i class="fas fa-exclamation-triangle text-danger me-1"></i>
                        <strong><?= h($s['name'] ?: $id) ?>:</strong>
                        Declared CGPA is <strong><?= number_format($declared, 2) ?></strong>
                        — recalculated as
                        <strong class="text-danger"><?= number_format($calculated, 2) ?></strong>
                        &nbsp;(Total Quality Points = <?= number_format($s['total_quality_points'], 4) ?> &nbsp;÷&nbsp;
                         Credit Hrs Attempted = <?= number_format($s['total_credit_hours_attempted'], 2) ?>).
                        &nbsp;<span class="badge bg-danger fs-6 px-3 py-1">
                            Corrected CGPA: <?= number_format($calculated, 2) ?>
                        </span>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Filter buttons -->
<div class="mt-3 d-flex gap-2 flex-wrap">
    <button class="btn btn-sm btn-outline-secondary" onclick="filterTable('all')">Show All</button>
    <button class="btn btn-sm btn-outline-danger"    onclick="filterTable('mismatch')">Mismatches Only</button>
    <button class="btn btn-sm btn-outline-success"   onclick="filterTable('correct')">Correct Only</button>
</div>

<script>
function filterTable(mode) {
    const rows = document.querySelectorAll('#cgpa-table tbody tr');
    rows.forEach(function(row) {
        if (mode === 'all') {
            row.style.display = '';
            return;
        }
        const isMismatch = row.classList.contains('table-danger') || row.classList.contains('table-warning');
        if (mode === 'mismatch') {
            row.style.display = isMismatch ? '' : 'none';
        } else {
            // correct: hide mismatch rows
            row.style.display = isMismatch ? 'none' : '';
        }
    });
}
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
