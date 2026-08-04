<?php
/**
 * Final Result Publish – Bulk CSV / Excel Import
 *
 * Publishes students' final results (CGPA) so they show up on the public
 * certificate-verification page (certificate-verification.php), which reads
 * the Final CGPA / Ending Semester / Result Publish Date from the
 * student_results table.
 *
 * Expected columns (header names are case-insensitive; spaces, hyphens and
 * punctuation are normalised automatically):
 *
 *   Student Name           – required
 *   Student ID             – required
 *   Department             – optional (used when the program cannot be matched)
 *   Program                – required (fuzzy-matched, see alias table below)
 *   Batch                  – optional (auto-created when unknown)
 *   Enrollment Semester    – optional, e.g. \"Fall 2021\"
 *   Completion Semester    – required, e.g. \"Fall 2024\" (shown as Ending Semester)
 *   CGPA                   – required, 0.01 – 4.00.
 *                            Rows whose CGPA reads \"incom.\", \"incom\",
 *                            \"incomplete\" or \"withheld\" are IGNORED and
 *                            never published.
 *
 * MATCHING RULES
 *   1. Students are looked up by Student ID BOTH with and without leading
 *      zeros (\"0123\" matches \"123\" and vice versa). When found, that
 *      existing student record is used.
 *   2. When no student is found, a new student record is created so the
 *      result can be published.
 *   3. Program names are canonicalised through a known alias table
 *      (B.Ed, BBA, CSE, EEE, LL.B/LL.M variants, MBA credit variants, ...)
 *      and then fuzzy-matched against dept_academic_programs, so small
 *      spelling differences still resolve to the right degree.
 *
 * A full preview (with per-row errors/warnings) is always shown before
 * anything is written to the database.
 */

ini_set('memory_limit', '256M');

require_once __DIR__ . '/../includes/auth.php';
require_access('final-result-publish');
require_once __DIR__ . '/../students/helpers.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$page_title = 'Final Result Publish';
$user       = auth_user();

const FRP_SUBJECT_LABEL = 'Final Result';
const FRP_FUZZY_MIN_PCT = 60; // minimum similarity % for a fuzzy program/dept match

// CGPA values (letters only, lowercase) that mean \"do not publish this row\"
const FRP_IGNORED_CGPA = ['incom', 'incomp', 'incomplete', 'inc', 'withheld', 'withhold', 'wh'];

// ── Normalisation helpers ─────────────────────────────────────────────────────

/** Compact alias key: lowercase, strip everything except letters/digits. */
function frp_key(string $s): string {
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = strtolower(trim($s));
    return preg_replace('/[^a-z0-9]/', '', $s);
}

/** Normalise an uploaded header cell to a canonical key. */
function frp_norm_header(string $s): string {
    $s = preg_replace('/^\xEF\xBB\xBF/', '', $s); // UTF-8 BOM
    $s = strtolower(trim($s));
    $s = preg_replace('/[\s\-]+/', '_', $s);
    return preg_replace('/[^a-z0-9_]/', '', $s);
}

/** Supported columns and their header aliases. */
function frp_columns(): array {
    return [
        'student_name'        => ['label' => 'Student Name',        'required' => true,  'aliases' => ['student_name', 'name', 'full_name', 'fullname', 'studentname']],
        'student_id'          => ['label' => 'Student ID',          'required' => true,  'aliases' => ['student_id', 'studentid', 'id', 'id_no', 'idno', 'sid']],
        'department'          => ['label' => 'Department',          'required' => false, 'aliases' => ['department', 'dept', 'dept_name']],
        'program'             => ['label' => 'Program',             'required' => true,  'aliases' => ['program', 'programme', 'degree', 'program_name']],
        'batch'               => ['label' => 'Batch',               'required' => false, 'aliases' => ['batch', 'batch_name', 'batch_no']],
        'completion_semester' => ['label' => 'Completion Semester', 'required' => true,  'aliases' => ['completion_semester', 'completionsemester', 'ending_semester', 'endingsemester', 'final_semester', 'finalsemester', 'passing_semester', 'passingsemester', 'completion', 'ending']],
        'enrollment_semester' => ['label' => 'Enrollment Semester', 'required' => false, 'aliases' => ['enrollment_semester', 'enrolment_semester', 'admitted_semester', 'enrollmentsemester', 'session', 'semester']],
        'cgpa'                => ['label' => 'CGPA',                'required' => true,  'aliases' => ['cgpa', 'final_cgpa', 'gpa', 'result']],
    ];
}

// ── Program alias table (CSV name → system program name) ─────────────────────

function frp_program_alias_map(): array {
    static $map = null;
    if ($map === null) {
        $pairs = [
            'B.Ed'                        => 'Bachelor of Education (B.Ed)- 1 Year',
            'BA (Hons.) in English'       => 'Bachelor of Arts in English',
            'BA (Hons) in English'        => 'Bachelor of Arts in English',
            'BBA'                         => 'Bachelor of Business Administration (BBA)- 4 Years',
            'CSE'                         => 'BSc in Computer Science & Engineering (CSE)',
            'B.Sc in CSE'                 => 'BSc in Computer Science & Engineering (CSE)',
            'BSc in CSE'                  => 'BSc in Computer Science & Engineering (CSE)',
            'EEE'                         => 'BSc in Electrical and Electronic Engineering',
            'B.Sc in EEE'                 => 'BSc in Electrical and Electronic Engineering',
            'BSc in EEE'                  => 'BSc in Electrical and Electronic Engineering',
            'LL.B (Hons.)'                => 'Bachelor of Laws (LL.B. Hons.)',
            'LLB (Hons)'                  => 'Bachelor of Laws (LL.B. Hons.)',
            'LLB'                         => 'Bachelor of Laws (LL.B. Hons.)',
            'LL.M (Regular) (33 Credits)' => 'Master of Laws (LLM)- 1 Year',
            'LLM (Regular) (33 Credits)'  => 'Master of Laws (LLM)- 1 Year',
            'LLM (Regular)'               => 'Master of Laws (LLM)- 1 Year',
            'LLM'                         => 'Master of Laws (LLM) Preli & Final- 2 Years',
            'LL.M'                        => 'Master of Laws (LLM) Preli & Final- 2 Years',
            'M.Ed'                        => 'Master of Education (M.Ed)-1 Year',
            'MA in English (Final)'       => 'Master of Arts in English (1 Year)',
            'MA in English'               => 'Master of Arts in English (2 Years)',
            'MBA (39cr)'                  => 'Masters of Business Administration (MBA)-1 Year',
            'MBA (36cr)'                  => 'Masters of Business Administration (MBA)-1 Year',
            'MBA (51cr)'                  => 'Executive Master of Business Administration (EMBA)-1.5 Years',
            'MBA (48cr)'                  => 'Executive Master of Business Administration (EMBA)-1.5 Years',
            'MBA (60 credit)'             => 'Masters of Business Administration (MBA)- 2 Years',
            'MBA (60cr)'                  => 'Masters of Business Administration (MBA)- 2 Years',
            'MBA'                         => 'Masters of Business Administration (MBA)- 2 Years',
        ];
        $map = [];
        foreach ($pairs as $from => $to) {
            $map[frp_key($from)] = $to;
        }
    }
    return $map;
}

/**
 * Resolve a raw CSV program name to a dept_academic_programs row.
 * Returns ['program' => row, 'confidence' => int(0-100), 'method' => alias|exact|fuzzy]
 * or null when nothing acceptable was found.
 */
function frp_resolve_program(string $raw): ?array {
    $key = frp_key($raw);
    if ($key === '') return null;

    $alias_map = frp_program_alias_map();
    $canonical = $alias_map[$key] ?? $raw;
    $canon_key = frp_key($canonical);

    // Exact match against active programs (any department)
    foreach (sm_program_data() as $p) {
        if (frp_key($p['program_name']) === $canon_key) {
            return [
                'program'    => $p,
                'confidence' => 100,
                'method'     => isset($alias_map[$key]) ? 'alias' : 'exact',
            ];
        }
    }

    // Closest-match fallback so \"something that will match closest one\" works.
    $best = null;
    $best_pct = 0.0;
    foreach (sm_program_data() as $p) {
        similar_text($canon_key, frp_key($p['program_name']), $pct);
        if ($pct > $best_pct) {
            $best_pct = $pct;
            $best = $p;
        }
    }
    if ($best !== null && $best_pct >= FRP_FUZZY_MIN_PCT) {
        return ['program' => $best, 'confidence' => (int)round($best_pct), 'method' => 'fuzzy'];
    }
    return null;
}

/** Resolve a department by name/code, with a fuzzy fallback. */
function frp_resolve_dept(string $raw): ?array {
    $key = frp_key($raw);
    if ($key === '') return null;

    foreach (sm_dept_data() as $d) {
        if (frp_key($d['name']) === $key || ($d['code'] !== '' && frp_key($d['code']) === $key)) {
            return $d;
        }
    }
    $best = null;
    $best_pct = 0.0;
    foreach (sm_dept_data() as $d) {
        similar_text($key, frp_key($d['name']), $pct);
        if ($pct > $best_pct) {
            $best_pct = $pct;
            $best = $d;
        }
    }
    return ($best !== null && $best_pct >= FRP_FUZZY_MIN_PCT) ? $best : null;
}

/**
 * Find a student by ID, matching both with and without leading zeros:
 *   CSV \"0123\" matches DB \"123\"  and  CSV \"123\" matches DB \"0123\".
 * An exact match always wins.
 */
function frp_find_student(PDO $pdo, string $sid): ?array {
    $trimmed = ltrim($sid, '0');
    if ($trimmed === '') $trimmed = $sid;

    $stmt = $pdo->prepare(
        "SELECT id, student_id, full_name, dept_id, program_id, batch, batch_id, status
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

/** Parse \"Fall-2021\" / \"fall 2021\" / \"Autumn, 2021\" → \"Fall 2021\". */
function frp_parse_semester(string $raw): string {
    $season = '';
    if (preg_match('/\b(spring|summer|fall|autumn)\b/i', $raw, $m)) {
        $season = ucfirst(strtolower($m[1]));
        if ($season === 'Autumn') $season = 'Fall';
    }
    $year = preg_match('/\b(19|20)\d{2}\b/', $raw, $y) ? $y[0] : '';
    if ($season !== '' && $year !== '') {
        return $season . ' ' . $year;
    }
    return trim($raw);
}

/** Extract the 4-digit year from a semester string, or ''. */
function frp_semester_year(string $semester): string {
    return preg_match('/\b(19|20)\d{2}\b/', $semester, $m) ? $m[0] : '';
}

/** Resolve a batch record; accepts \"48th Batch\" or bare \"48\". */
function frp_resolve_batch(string $raw): ?array {
    $key = strtolower(trim($raw));
    if ($key === '') return null;
    foreach (sm_batches() as $b) {
        if (strtolower(trim($b['name'])) === $key) return $b;
    }
    if (is_numeric($key)) {
        $n = (int)$key;
        $suffix = ($n % 100 >= 11 && $n % 100 <= 13) ? 'th'
                : match ($n % 10) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' };
        $try = strtolower($n . $suffix . ' batch');
        foreach (sm_batches() as $b) {
            if (strtolower(trim($b['name'])) === $try) return $b;
        }
    }
    return null;
}

/** Read a csv/xlsx/xls into rows of string arrays. */
function frp_read_spreadsheet(string $tmp_path, string $ext): array {
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

/** Validate one mapped row. Returns errors/warnings + parsed fields. */
function frp_validate_row(array $row, PDO $pdo): array {
    $errors   = [];
    $warnings = [];

    $name_raw  = trim($row['student_name']        ?? '');
    $sid_raw   = trim($row['student_id']          ?? '');
    $dept_raw  = trim($row['department']          ?? '');
    $prog_raw  = trim($row['program']             ?? '');
    $batch_raw = trim($row['batch']               ?? '');
    $sem_raw   = trim($row['enrollment_semester'] ?? '');
    $comp_raw  = trim($row['completion_semester'] ?? '');
    $cgpa_raw  = trim($row['cgpa']                ?? '');

    if ($name_raw === '') $errors[] = 'Student Name is required.';

    if ($sid_raw === '') {
        $errors[] = 'Student ID is required.';
    } elseif (!preg_match('/^[a-zA-Z0-9\-]{1,25}$/', $sid_raw)) {
        $errors[] = 'Student ID "' . h($sid_raw) . '" is invalid (1–25 alphanumeric/hyphen chars).';
    }

    // Completion (ending) semester – required per row
    $comp_season = '';
    $comp_year   = '';
    if ($comp_raw === '') {
        $errors[] = 'Completion Semester is required.';
    } else {
        $parsed = frp_parse_semester($comp_raw);
        if (preg_match('/^(Spring|Summer|Fall) ((19|20)\d{2})$/', $parsed, $m)) {
            $comp_season = $m[1];
            $comp_year   = $m[2];
        } else {
            $errors[] = 'Completion Semester "' . h($comp_raw)
                      . '" could not be parsed (expected e.g. "Fall 2024").';
        }
    }

    // CGPA – values like "incom.", "incomplete" or "withheld" mean the result
    // must NOT be published; those rows are ignored entirely.
    $cgpa     = null;
    $ignored  = false;
    $cgpa_key = preg_replace('/[^a-z]/', '', strtolower($cgpa_raw));
    if ($cgpa_key !== '' && in_array($cgpa_key, FRP_IGNORED_CGPA, true)) {
        $ignored    = true;
        $warnings[] = 'CGPA is "' . h($cgpa_raw) . '" – this result will NOT be published.';
    } elseif ($cgpa_raw === '') {
        $errors[] = 'CGPA is required.';
    } else {
        $val = filter_var($cgpa_raw, FILTER_VALIDATE_FLOAT);
        if ($val === false) {
            $errors[] = 'CGPA "' . h($cgpa_raw) . '" is not a number.';
        } elseif ($val <= 0 || $val > 4.0) {
            $errors[] = 'CGPA "' . h($cgpa_raw) . '" is outside the valid range (0.01 – 4.00).';
        } else {
            $cgpa = number_format($val, 2, '.', '');
        }
    }

    // Program (fuzzy)
    $prog_match = null;
    if ($prog_raw !== '') {
        $prog_match = frp_resolve_program($prog_raw);
        if ($prog_match === null) {
            $warnings[] = 'Program "' . h($prog_raw) . '" could not be matched to any degree.';
        } elseif ($prog_match['method'] === 'fuzzy') {
            $warnings[] = 'Program "' . h($prog_raw) . '" fuzzy-matched to "'
                        . h($prog_match['program']['program_name']) . '" ('
                        . $prog_match['confidence'] . '% similar) – please verify.';
        }
    }

    // Department: prefer the matched program's department
    $dept = null;
    if ($prog_match !== null) {
        foreach (sm_dept_data() as $d) {
            if ((int)$d['id'] === (int)$prog_match['program']['dept_id']) { $dept = $d; break; }
        }
    }
    if ($dept === null && $dept_raw !== '') {
        $dept = frp_resolve_dept($dept_raw);
        if ($dept === null) {
            $warnings[] = 'Department "' . h($dept_raw) . '" not found.';
        }
    }

    // Existing student lookup (both with and without leading zeros)
    $existing = null;
    if ($sid_raw !== '' && preg_match('/^[a-zA-Z0-9\-]{1,25}$/', $sid_raw)) {
        $existing = frp_find_student($pdo, $sid_raw);
    }

    $action = 'skip';
    if (empty($errors)) {
        if ($existing) {
            $action = 'existing';
            if ($existing['student_id'] !== $sid_raw) {
                $warnings[] = 'Matched by leading-zero variant: CSV "' . h($sid_raw)
                            . '" → existing "' . h($existing['student_id']) . '".';
            }
            if ($name_raw !== '' && frp_key($existing['full_name']) !== frp_key($name_raw)) {
                $warnings[] = 'Name in CSV ("' . h($name_raw) . '") differs from the record ("'
                            . h($existing['full_name']) . '"). The existing record is kept.';
            }
        } else {
            $action = 'create';
            if ($dept === null) {
                $errors[] = 'Cannot create a new student: neither the Program nor the Department could be matched.';
                $action = 'skip';
            }
        }
    }

    // Incomplete / withheld CGPA overrides everything – never publish.
    if ($ignored) {
        $action = 'ignore';
    }

    // Batch
    $batch = null;
    if ($batch_raw !== '') {
        $batch = frp_resolve_batch($batch_raw);
        if ($batch === null && !$ignored) {
            $warnings[] = 'Batch "' . h($batch_raw) . '" not found – it will be created.';
        }
    }

    $enroll_sem = $sem_raw !== '' ? frp_parse_semester($sem_raw) : '';

    return [
        'errors'              => $errors,
        'warnings'            => $warnings,
        'action'              => $action,
        'student_id'          => $sid_raw,
        'full_name'           => $name_raw,
        'existing'            => $existing ? [
            'id'         => (int)$existing['id'],
            'student_id' => $existing['student_id'],
            'full_name'  => $existing['full_name'],
            'status'     => $existing['status'],
            'program_id' => $existing['program_id'] !== null ? (int)$existing['program_id'] : null,
            'batch'      => $existing['batch'],
        ] : null,
        'dept'                => $dept ? ['id' => (int)$dept['id'], 'name' => $dept['name']] : null,
        'dept_raw'            => $dept_raw,
        'program'             => $prog_match ? [
            'id'         => (int)$prog_match['program']['id'],
            'name'       => $prog_match['program']['program_name'],
            'dept_id'    => (int)$prog_match['program']['dept_id'],
            'confidence' => $prog_match['confidence'],
            'method'     => $prog_match['method'],
        ] : null,
        'program_raw'         => $prog_raw,
        'batch_row'           => $batch ? ['id' => (int)$batch['id'], 'name' => $batch['name']] : null,
        'batch_raw'           => $batch_raw,
        'enroll_semester'     => $enroll_sem,
        'completion_raw'      => $comp_raw,
        'completion_season'   => $comp_season,
        'completion_year'     => $comp_year,
        'completion_semester' => ($comp_season !== '' && $comp_year !== '') ? $comp_season . ' ' . $comp_year : $comp_raw,
        'cgpa'                => $cgpa,
        'cgpa_raw'            => $cgpa_raw,
    ];
}

// ── State ─────────────────────────────────────────────────────────────────────

$step         = 'upload';
$parse_error  = null;
$preview_rows = null;
$settings     = null;
$import_stats = [];

// ── STEP 1 → 2: Upload, parse & validate ─────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preview') {
    csrf_check();

    $publish_date   = trim($_POST['publish_date'] ?? '');
    $mark_graduated = !empty($_POST['mark_graduated']);
    $fill_missing   = !empty($_POST['fill_missing']);

    if ($publish_date !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $publish_date) || !strtotime($publish_date))) {
        $parse_error = 'The result publish date is invalid – leave it empty or pick a valid date.';
    } elseif (empty($_FILES['csv_file']['name']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $parse_error = 'Please choose a file to upload.';
    } else {
        $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xlsx', 'xls'], true)) {
            $parse_error = 'Only .csv, .xlsx and .xls files are accepted.';
        } else {
            $result = frp_read_spreadsheet($_FILES['csv_file']['tmp_name'], $ext);
            if ($result['error'] !== null) {
                $parse_error = $result['error'];
            } elseif (count($result['rows']) < 2) {
                $parse_error = 'The file must contain a header row and at least one data row.';
            } else {
                $all_rows   = $result['rows'];
                $header_raw = array_shift($all_rows);
                $header     = array_map('frp_norm_header', $header_raw);

                // Auto-map columns via aliases
                $col_map = [];
                $used    = [];
                foreach (frp_columns() as $key => $def) {
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
                foreach (frp_columns() as $key => $def) {
                    if ($def['required'] && !isset($col_map[$key])) {
                        $missing[] = $def['label'];
                    }
                }

                if ($missing) {
                    $parse_error = 'Missing required column(s): ' . implode(', ', $missing)
                                 . '. Found headers: ' . h(implode(', ', array_filter($header_raw)));
                } else {
                    $pdo = db();
                    $preview_rows = [];
                    $seen_ids     = [];   // normalised sid → first row number
                    $row_num      = 1;

                    foreach ($all_rows as $data) {
                        $row_num++;
                        if (count(array_filter(array_map('trim', $data))) === 0) continue; // skip empty rows

                        $assoc = [];
                        foreach ($col_map as $key => $idx) {
                            $assoc[$key] = $data[$idx] ?? '';
                        }

                        $validated = frp_validate_row($assoc, $pdo);
                        $validated['row_num'] = $row_num;

                        // Duplicate detection using leading-zero-insensitive key
                        // (ignored rows are excluded – they are never imported)
                        $sid_norm = ltrim($validated['student_id'], '0') ?: $validated['student_id'];
                        if ($sid_norm !== '' && $validated['action'] !== 'ignore') {
                            if (isset($seen_ids[$sid_norm])) {
                                $validated['errors'][] = 'Student ID appears twice in this file (first at row '
                                                       . $seen_ids[$sid_norm] . ').';
                                $validated['action'] = 'skip';
                            } else {
                                $seen_ids[$sid_norm] = $row_num;
                            }
                        }

                        $preview_rows[] = $validated;
                    }

                    if (empty($preview_rows)) {
                        $parse_error = 'The file contains no data rows.';
                    } else {
                        $settings = [
                            'publish_date'   => $publish_date,
                            'mark_graduated' => $mark_graduated,
                            'fill_missing'   => $fill_missing,
                        ];
                        $_SESSION['frp_rows']     = $preview_rows;
                        $_SESSION['frp_settings'] = $settings;
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
        flash_set('error', 'You do not have permission to import results.');
        redirect(APP_URL . '/final-result-publish/index.php');
    }

    $rows     = $_SESSION['frp_rows']     ?? [];
    $settings = $_SESSION['frp_settings'] ?? null;
    unset($_SESSION['frp_rows'], $_SESSION['frp_settings']);

    if (empty($rows) || $settings === null) {
        flash_set('error', 'No import data found. Please re-upload the file.');
        redirect(APP_URL . '/final-result-publish/index.php');
    }

    $pdo          = db();
    $publish_date = ($settings['publish_date'] !== '') ? $settings['publish_date'] : null;
    $created      = 0;
    $updated      = 0;
    $skipped      = 0;
    $ignored      = 0;
    $results      = [];

    foreach ($rows as $r) {
        // Ignored rows: CGPA marked incomplete/withheld – never published.
        if ($r['action'] === 'ignore') {
            $results[] = [
                'row_num'    => $r['row_num'],
                'status'     => 'ignored',
                'student_id' => $r['student_id'],
                'full_name'  => $r['full_name'],
                'reason'     => 'CGPA "' . h($r['cgpa_raw']) . '" – result not published.',
            ];
            $ignored++;
            continue;
        }

        if (!empty($r['errors']) || $r['action'] === 'skip') {
            $results[] = [
                'row_num'    => $r['row_num'],
                'status'     => 'skipped',
                'student_id' => $r['student_id'],
                'full_name'  => $r['full_name'],
                'reason'     => implode('; ', $r['errors']) ?: 'Skipped.',
            ];
            $skipped++;
            continue;
        }

        try {
            // ── Resolve / auto-create batch ───────────────────────────────
            $batch = $r['batch_row'];
            if ($batch === null && $r['batch_raw'] !== '') {
                $b_chk = $pdo->prepare('SELECT id, name FROM student_batches WHERE LOWER(name) = LOWER(?) LIMIT 1');
                $b_chk->execute([$r['batch_raw']]);
                $b_row = $b_chk->fetch(PDO::FETCH_ASSOC);
                if ($b_row) {
                    $batch = $b_row;
                } else {
                    try {
                        $pdo->prepare('INSERT INTO student_batches (name, is_active, sort_order) VALUES (?, 1, 0)')
                            ->execute([$r['batch_raw']]);
                        $batch = ['id' => (int)$pdo->lastInsertId(), 'name' => $r['batch_raw']];
                    } catch (PDOException $e) {
                        $b_chk->execute([$r['batch_raw']]);
                        $batch = $b_chk->fetch(PDO::FETCH_ASSOC) ?: null;
                    }
                }
            }
            $batch_name = $batch ? $batch['name'] : ($r['batch_raw'] ?: null);

            // ── Re-check the student (session data may be stale) ──────────
            $existing = frp_find_student($pdo, $r['student_id']);

            if ($existing) {
                $student_pk  = (int)$existing['id'];
                $student_sid = $existing['student_id'];

                $set    = [];
                $params = [];
                // A "Dropped" student with a valid final CGPA has actually
                // graduated – always correct the status to "Graduated".
                if ($settings['mark_graduated'] || ($existing['status'] ?? '') === 'Dropped') {
                    $set[]    = "status = 'Graduated'";
                }
                if ($settings['fill_missing']) {
                    if ($r['program'] !== null) {
                        $set[]    = 'program_id = CASE WHEN program_id IS NULL THEN ? ELSE program_id END';
                        $params[] = $r['program']['id'];
                    }
                    if ($batch !== null) {
                        $set[]    = "batch = CASE WHEN (batch IS NULL OR batch = '') THEN ? ELSE batch END";
                        $params[] = $batch['name'];
                        $set[]    = 'batch_id = CASE WHEN batch_id IS NULL THEN ? ELSE batch_id END';
                        $params[] = $batch['id'];
                    }
                }
                if ($set) {
                    $params[] = $student_pk;
                    $pdo->prepare('UPDATE students SET ' . implode(', ', $set) . ' WHERE id = ?')
                        ->execute($params);
                }
                $status = 'updated';
            } else {
                // ── Create the student so the result can be published ─────
                $dept_id    = $r['program'] !== null ? $r['program']['dept_id'] : ($r['dept']['id'] ?? null);
                if ($dept_id === null) {
                    throw new RuntimeException('No department could be resolved.');
                }
                $enroll_sem = $r['enroll_semester'] !== '' ? $r['enroll_semester'] : null;
                $year       = $enroll_sem ? (frp_semester_year($enroll_sem) ?: null) : null;

                $pdo->prepare(
                    'INSERT INTO students
                       (student_id, dept_id, program_id, admitted_semester, year,
                        batch, batch_id, full_name, status, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $r['student_id'],
                    (int)$dept_id,
                    $r['program'] !== null ? $r['program']['id'] : null,
                    $enroll_sem,
                    $year,
                    $batch_name,
                    $batch ? (int)$batch['id'] : null,
                    $r['full_name'],
                    $settings['mark_graduated'] ? 'Graduated' : 'Active',
                    $user['id'],
                ]);
                $student_pk  = (int)$pdo->lastInsertId();
                $student_sid = $r['student_id'];

                log_change('students', 'CREATE', $student_pk,
                           $r['full_name'] . ' (' . $student_sid . ')',
                           null, null, null, 'Created via Final Result Publish import');
                $status = 'created';
            }

            // ── Upsert the final result row (read by certificate page) ────
            $chk = $pdo->prepare(
                'SELECT id FROM student_results
                 WHERE student_id = ? AND subject = ? AND semester = ? AND semester_year = ?
                 LIMIT 1'
            );
            $chk->execute([
                $student_pk, FRP_SUBJECT_LABEL,
                $r['completion_season'], $r['completion_year'],
            ]);
            $result_id = $chk->fetchColumn();

            if ($result_id) {
                $pdo->prepare(
                    'UPDATE student_results
                     SET cgpa = ?, batch = ?, recorded_date = ?
                     WHERE id = ?'
                )->execute([$r['cgpa'], $batch_name, $publish_date, (int)$result_id]);
            } else {
                $pdo->prepare(
                    'INSERT INTO student_results
                       (student_id, semester, semester_year, batch, subject, cgpa, recorded_date)
                     VALUES (?,?,?,?,?,?,?)'
                )->execute([
                    $student_pk,
                    $r['completion_season'],
                    $r['completion_year'],
                    $batch_name,
                    FRP_SUBJECT_LABEL,
                    $r['cgpa'],
                    $publish_date,
                ]);
            }

            log_change('students', 'UPDATE', $student_pk,
                       $r['full_name'] . ' (' . $student_sid . ')',
                       'final_result', null, $r['cgpa'],
                       'Final result published (' . $r['completion_semester']
                       . ', CGPA ' . $r['cgpa'] . ')');

            $results[] = [
                'row_num'    => $r['row_num'],
                'status'     => $status,
                'student_id' => $student_sid,
                'full_name'  => $r['full_name'],
                'reason'     => '',
            ];
            if ($status === 'created') $created++; else $updated++;

        } catch (Throwable $e) {
            $results[] = [
                'row_num'    => $r['row_num'],
                'status'     => 'error',
                'student_id' => $r['student_id'],
                'full_name'  => $r['full_name'],
                'reason'     => 'Error: ' . h($e->getMessage()),
            ];
            $skipped++;
        }
    }

    $import_stats = [
        'created'  => $created,
        'updated'  => $updated,
        'skipped'  => $skipped,
        'ignored'  => $ignored,
        'rows'     => $results,
        'settings' => $settings,
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
            <li class="breadcrumb-item active">Final Result Publish</li>
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
        <h6 class="mb-0 fw-semibold"><i class="fas fa-award me-2 text-muted"></i>Publish Final Results (CSV / Excel)</h6>
    </div>
    <div class="card-body">

        <div class="alert alert-info mb-4" style="font-size:.875rem;">
            <strong>Expected columns</strong>
            <small class="text-muted">(header names are case-insensitive; punctuation is normalised)</small>
            <ul class="mb-2 mt-2 ps-3">
                <li><code>Student Name</code> <span class="text-danger">*</span></li>
                <li><code>Student ID</code> <span class="text-danger">*</span> – matched with and without leading zeros (e.g. <code>0123</code> ⇄ <code>123</code>)</li>
                <li><code>Department</code> – used when the program cannot be matched</li>
                <li><code>Program</code> <span class="text-danger">*</span> – short names are auto-mapped, e.g. <code>BBA</code>, <code>CSE</code>, <code>MBA (39cr)</code>, <code>LL.M (Regular) (33 Credits)</code></li>
                <li><code>Batch</code> – name or number (auto-created when unknown)</li>
                <li><code>Enrollment Semester</code> – e.g. <em>Fall 2021</em></li>
                <li><code>Completion Semester</code> <span class="text-danger">*</span> – e.g. <em>Fall 2024</em> (shown as “Ending Semester” on the public page)</li>
                <li><code>CGPA</code> <span class="text-danger">*</span> – 0.01 to 4.00.
                    Rows with <code>incom.</code>, <code>incom</code>, <code>incomplete</code> or <code>withheld</code>
                    are <strong>ignored</strong> and never published.</li>
            </ul>
            <div>
                Students found by ID are <strong>reused</strong>; unknown IDs get a <strong>new student record</strong>
                so the result appears on the certificate verification page.
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
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Result Publish Date <span class="text-muted">(optional)</span></label>
                    <input type="date" name="publish_date" class="form-control" value="">
                    <div class="form-text">Leave empty to keep the publish date blank</div>
                </div>
            </div>

            <div class="mb-2">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="mark_graduated" id="mark_graduated" value="1" checked>
                    <label class="form-check-label" for="mark_graduated">
                        <strong>Mark students as Graduated</strong>
                        <span class="text-muted" style="font-size:.875rem;">– sets student status to “Graduated” (existing and new)</span>
                    </label>
                </div>
            </div>
            <div class="mb-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="fill_missing" id="fill_missing" value="1" checked>
                    <label class="form-check-label" for="fill_missing">
                        <strong>Fill missing program / batch on existing students</strong>
                        <span class="text-muted" style="font-size:.875rem;">– only empty fields are filled; existing data is never overwritten</span>
                    </label>
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
$n_create = 0; $n_exist = 0; $n_skip = 0; $n_ignore = 0;
foreach ($preview_rows as $pr) {
    if ($pr['action'] === 'ignore') $n_ignore++;
    elseif (!empty($pr['errors']) || $pr['action'] === 'skip') $n_skip++;
    elseif ($pr['action'] === 'create') $n_create++;
    else $n_exist++;
}
$n_ok = $n_create + $n_exist;
?>

<div class="alert <?= $n_ok > 0 ? 'alert-success' : 'alert-warning' ?> mb-3">
    <strong><?= count($preview_rows) ?></strong> data row(s):
    <strong><?= $n_exist ?></strong> matched existing student(s),
    <strong><?= $n_create ?></strong> new student(s) will be created,
    <strong><?= $n_ignore ?></strong> ignored (incomplete/withheld CGPA),
    <strong><?= $n_skip ?></strong> will be skipped.
    <div class="mt-1" style="font-size:.875rem;">
        Publish Date: <strong><?= $settings['publish_date'] !== '' ? h(date('d M Y', strtotime($settings['publish_date']))) : '— (empty)' ?></strong> ·
        Mark Graduated: <strong><?= $settings['mark_graduated'] ? 'Yes' : 'No' ?></strong>
    </div>
</div>

<div class="d-flex gap-2 mb-3">
    <?php if ($n_ok > 0): ?>
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="import">
        <button type="submit" class="btn btn-success" style="border-radius:8px;"
                onclick="return confirm('Publish final results for <?= $n_ok ?> student(s) now?');">
            <i class="fas fa-award me-1"></i> Confirm &amp; Publish <?= $n_ok ?> Result(s)
        </button>
    </form>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/final-result-publish/index.php" class="btn btn-outline-secondary" style="border-radius:8px;">
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
                        <th>Name</th>
                        <th>Department</th>
                        <th>Program (matched)</th>
                        <th>Batch</th>
                        <th>Enrollment Sem.</th>
                        <th>Completion Sem.</th>
                        <th>CGPA</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($preview_rows as $i => $r):
                    $is_ignored   = $r['action'] === 'ignore';
                    $has_errors   = !$is_ignored && (!empty($r['errors']) || $r['action'] === 'skip');
                    $has_warnings = !empty($r['warnings']);
                    $row_cls = $is_ignored ? 'table-secondary' : ($has_errors ? 'table-danger' : ($has_warnings ? 'table-warning' : ''));
                    $dash    = '<span class="text-muted">—</span>';
                ?>
                <tr class="<?= $row_cls ?>">
                    <td class="px-3"><?= $i + 1 ?></td>
                    <td><?= (int)$r['row_num'] ?></td>
                    <td>
                        <?php if ($is_ignored): ?>
                            <span class="badge bg-secondary">Ignored</span>
                        <?php elseif ($has_errors): ?>
                            <span class="badge bg-danger">Skip</span>
                        <?php elseif ($r['action'] === 'existing'): ?>
                            <span class="badge bg-info text-dark">Use Existing</span>
                        <?php else: ?>
                            <span class="badge bg-success">Create New</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($is_ignored): ?>
                            <span class="text-secondary fw-semibold"><i class="fas fa-ban me-1"></i>Not published</span>
                        <?php elseif ($has_errors): ?>
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
                        <?php elseif ($is_ignored): ?>
                            <?= $dash ?>
                        <?php else: ?>
                            <span class="text-muted fst-italic">new record</span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:normal;min-width:160px;"><?= h($r['full_name']) ?></td>
                    <td style="white-space:normal;min-width:150px;">
                        <?php if ($r['dept']): ?>
                            <?= h($r['dept']['name']) ?>
                        <?php elseif ($r['dept_raw'] !== ''): ?>
                            <span class="text-warning" title="Not matched"><?= h($r['dept_raw']) ?></span>
                        <?php else: ?>
                            <?= $dash ?>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:normal;min-width:200px;">
                        <?php if ($r['program']): ?>
                            <?= h($r['program']['name']) ?>
                            <?php if ($r['program']['method'] === 'fuzzy'): ?>
                                <span class="badge bg-warning text-dark" style="font-size:.65rem;"><?= $r['program']['confidence'] ?>%</span>
                            <?php endif; ?>
                            <small class="text-muted d-block">from “<?= h($r['program_raw']) ?>”</small>
                        <?php elseif ($r['program_raw'] !== ''): ?>
                            <span class="text-warning" title="Not matched"><?= h($r['program_raw']) ?></span>
                        <?php else: ?>
                            <?= $dash ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($r['batch_row']): ?>
                            <?= h($r['batch_row']['name']) ?>
                        <?php elseif ($r['batch_raw'] !== ''): ?>
                            <span class="text-warning"><?= h($r['batch_raw']) ?> <small>(new)</small></span>
                        <?php else: ?>
                            <?= $dash ?>
                        <?php endif; ?>
                    </td>
                    <td><?= $r['enroll_semester'] !== '' ? h($r['enroll_semester']) : $dash ?></td>
                    <td>
                        <?php if ($r['completion_season'] !== ''): ?>
                            <strong><?= h($r['completion_semester']) ?></strong>
                        <?php elseif ($r['completion_raw'] !== ''): ?>
                            <span class="text-danger" title="Could not parse"><?= h($r['completion_raw']) ?></span>
                        <?php else: ?>
                            <?= $dash ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($is_ignored): ?>
                            <span class="badge bg-secondary" title="Not published"><?= h($r['cgpa_raw']) ?></span>
                        <?php else: ?>
                            <strong><?= $r['cgpa'] !== null ? h($r['cgpa']) : '—' ?></strong>
                        <?php endif; ?>
                    </td>
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
    <i class="fas fa-award me-2"></i>
    <strong><?= $import_stats['updated'] ?></strong> existing student(s) updated,
    <strong><?= $import_stats['created'] ?></strong> new student(s) created,
    <strong><?= $import_stats['ignored'] ?></strong> ignored (incomplete/withheld CGPA),
    <strong><?= $import_stats['skipped'] ?></strong> row(s) skipped.
    <?php if ($import_stats['settings']['publish_date'] !== ''): ?>
    <div class="mt-1" style="font-size:.875rem;">
        Publish date: <strong><?= h(date('d M Y', strtotime($import_stats['settings']['publish_date']))) ?></strong>
    </div>
    <?php endif; ?>
</div>

<div class="d-flex gap-2 mb-4">
    <a href="<?= SITE_URL ?>/certificate-verification.php" target="_blank" class="btn btn-primary" style="border-radius:8px;">
        <i class="fas fa-shield-alt me-1"></i> Check Verification Page
    </a>
    <a href="<?= APP_URL ?>/final-result-publish/index.php" class="btn btn-outline-secondary" style="border-radius:8px;">
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
                <?php foreach ($import_stats['rows'] as $r):
                    $cls = match (true) {
                        in_array($r['status'], ['created', 'updated'], true) => '',
                        $r['status'] === 'ignored'                          => 'table-secondary',
                        default                                             => 'table-danger',
                    };
                ?>
                <tr class="<?= $cls ?>">
                    <td class="px-3"><?= (int)$r['row_num'] ?></td>
                    <td><code><?= h($r['student_id']) ?></code></td>
                    <td><?= h($r['full_name']) ?></td>
                    <td>
                        <?php if ($r['status'] === 'created'): ?>
                            <span class="text-success"><i class="fas fa-user-plus me-1"></i>Student created &amp; result published</span>
                        <?php elseif ($r['status'] === 'updated'): ?>
                            <span class="text-info"><i class="fas fa-check-circle me-1"></i>Result published</span>
                        <?php elseif ($r['status'] === 'ignored'): ?>
                            <span class="text-secondary"><i class="fas fa-ban me-1"></i>Ignored – not published</span>
                            <?php if ($r['reason']): ?>
                            <small class="d-block text-muted"><?= $r['reason'] ?></small>
                            <?php endif; ?>
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
