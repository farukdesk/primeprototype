<?php
/**
 * Transcript Maker – parsing helpers
 *
 * Reads a Prime University tabulation sheet (CSV / XLS / XLSX / ODS) and turns
 * it into per-student transcript records.  The tabulation layout is the same one
 * consumed by the Tabulation Sheet Checker (admin/tabulation-checker/), but here
 * we additionally capture the Course Code and Course Title for every subject
 * block so a full academic transcript can be rendered.
 *
 * Tabulation layout (per subject sheet)
 * ─────────────────────────────────────
 *   Rows 0-3 : University / Department / Faculty / "Tabulation Sheet" heading
 *   A meta row with "Batch : …", "Program : …", "Enrollment Semester: …"
 *   Header row 1 : SL No. | ID No. | Name of the Student | <Course Code> …
 *   Header row 2 :                                        | <Course Title> …
 *   Header row 3 :                        | Grade | Grade point | Cr. Hr. | Semester (repeating)
 *   Data rows    : 1 | '0282…041 | Student Name | B(regular) | 3 | 3 | Fall-22 | …
 *
 * Each subject block is 4 columns wide (Grade | Grade point | Cr. Hr. | Semester)
 * and the Course Code / Course Title sit in the same column as the block's
 * "Grade" cell in the two header rows above it.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

/* ── grade scale ────────────────────────────────────────────────────────────── */

/**
 * Grade-point lookup table (Prime University grading scale).
 * Returns the expected grade point for a given letter grade, or null when the
 * grade is not on the standard scale (e.g. "INCOM", blank).
 */
function tm_expected_grade_point(string $grade): ?float
{
    static $map = [
        'A+' => 4.00, 'A' => 3.75, 'A-' => 3.50, 'A−' => 3.50,
        'B+' => 3.25, 'B' => 3.00, 'B-' => 2.75, 'B−' => 2.75,
        'C+' => 2.50, 'C' => 2.25, 'D' => 2.00, 'F' => 0.00,
    ];
    return $map[strtoupper(trim($grade))] ?? null;
}

/**
 * Normalise a raw tabulation grade string for display on the transcript.
 * Tabulation files store grades like "B(regular)", "B+(plus)", "C+(plus)";
 * the transcript prints them with a space before the parenthesis:
 * "B (regular)", "B+ (plus)", "C+ (plus)".  Plain grades such as "D" or "F"
 * are returned unchanged.
 */
function tm_format_grade(string $grade): string
{
    $grade = trim($grade);
    if ($grade === '') {
        return '';
    }
    // Insert a single space before an opening parenthesis if none is present.
    $grade = preg_replace('/\s*\(/', ' (', $grade, 1);
    return trim($grade);
}

/* ── spreadsheet loading ────────────────────────────────────────────────────── */

/**
 * Convert a worksheet to a 0-indexed 2-D array, fixing float-formatted integer
 * cells (e.g. student IDs stored as Excel numbers) so leading digits are not
 * lost to scientific notation.
 */
function tm_worksheet_to_array(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws): array
{
    $data     = $ws->toArray(null, true, false, false);
    $max_cols = 0;
    foreach ($data as $row) {
        $max_cols = max($max_cols, count($row));
    }
    foreach ($data as &$row) {
        while (count($row) < $max_cols) {
            $row[] = '';
        }
        foreach ($row as &$cell) {
            if (is_float($cell) && !is_nan($cell) && !is_infinite($cell)
                    && fmod($cell, 1.0) === 0.0) {
                $cell = sprintf('%.0f', $cell);
            }
        }
        unset($cell);
    }
    unset($row);
    return $data;
}

/**
 * Load a spreadsheet file, trying the extension-matched reader first and then
 * every other supported reader.  Throws on failure.
 */
function tm_load_spreadsheet(string $tmp_path, string $ext): \PhpOffice\PhpSpreadsheet\Spreadsheet
{
    $reader_class_map = ['xlsx' => 'Xlsx', 'xls' => 'Xls', 'ods' => 'Ods', 'csv' => 'Csv'];

    $try_order = [$ext];
    foreach (array_keys($reader_class_map) as $k) {
        if ($k !== $ext) {
            $try_order[] = $k;
        }
    }

    $last_error = null;
    foreach ($try_order as $try_ext) {
        if (!isset($reader_class_map[$try_ext])) {
            continue;
        }
        try {
            $reader = IOFactory::createReader($reader_class_map[$try_ext]);
            if (method_exists($reader, 'setReadDataOnly')) {
                $reader->setReadDataOnly(true);
            }
            return $reader->load($tmp_path);
        } catch (\Throwable $e) {
            $last_error = $e;
        }
    }
    throw $last_error ?? new \RuntimeException(
        'Unable to read the uploaded file. Please verify it is a valid CSV, XLS, XLSX, or ODS tabulation sheet.'
    );
}

/* ── metadata extraction ────────────────────────────────────────────────────── */

/**
 * Pull a "Label : value" style value out of the header area of the sheet.
 * Scans every cell in the rows before the data table for one that starts with
 * one of the supplied label prefixes (case-insensitive) and returns the text
 * after the first colon.  Returns '' when nothing matches.
 */
function tm_extract_meta(array $rows, int $data_start, array $labels): string
{
    foreach ($rows as $ri => $row) {
        if ($ri >= $data_start) {
            break;
        }
        foreach ($row as $cell) {
            $val = trim((string)$cell);
            if ($val === '') {
                continue;
            }
            foreach ($labels as $label) {
                // Match "Label:" or "Label :" at the very start of the cell.
                if (preg_match('/^' . preg_quote($label, '/') . '\s*:\s*(.+)$/i', $val, $m)) {
                    return trim($m[1]);
                }
            }
        }
    }
    return '';
}

/**
 * Find a heading line (University / Department / Faculty) in the top rows.
 * Returns the first cell text that contains the given keyword, else ''.
 */
function tm_find_heading(array $rows, int $data_start, string $keyword): string
{
    foreach ($rows as $ri => $row) {
        if ($ri >= $data_start) {
            break;
        }
        foreach ($row as $cell) {
            $val = trim((string)$cell);
            if ($val !== '' && stripos($val, $keyword) !== false
                    && stripos($val, ':') === false) {
                return $val;
            }
        }
    }
    return '';
}

/* ── ID normalisation ───────────────────────────────────────────────────────── */

/**
 * Normalise a raw ID cell: strip the Excel text-marker apostrophe and reduce a
 * float-formatted integer ("282210004081073.0") to a plain digit string.
 */
function tm_normalize_id(string $raw): string
{
    $id = ltrim(trim($raw), "'");
    if (preg_match('/^(\d+)\.0+$/', $id, $m)) {
        $id = $m[1];
    }
    return $id;
}

/* ── sheet parsing ──────────────────────────────────────────────────────────── */

/**
 * Parse a single worksheet.  Returns:
 *   [
 *     'meta'     => ['program'=>…, 'batch'=>…, 'admission_semester'=>…,
 *                    'university'=>…, 'department'=>…, 'faculty'=>…],
 *     'students' => [ id => ['name'=>…, 'subjects'=>[ ['code','title','grade',
 *                    'grade_point','credit','semester'], … ]] ],
 *   ]
 */
function tm_parse_sheet(array $rows): array
{
    $id_header_labels = ['id no.', 'id no', 'id', 'id.', 'student id', 'student id no.', 'student id no'];

    // ── Locate the ID header row / column ────────────────────────────────────
    $id_col     = -1;
    $name_col   = -1;
    $header_row = -1;
    foreach ($rows as $ri => $row) {
        foreach ($row as $ci => $cell) {
            $val = strtolower(trim((string)$cell));
            if (in_array($val, $id_header_labels, true)) {
                $id_col     = $ci;
                $name_col   = $ci + 1;
                $header_row = $ri;
                break 2;
            }
        }
    }

    // Fallback: first cell that looks like a long numeric student ID.
    $data_start = 0;
    if ($id_col < 0) {
        foreach ($rows as $ri => $row) {
            foreach ($row as $ci => $cell) {
                $norm = tm_normalize_id((string)$cell);
                if (preg_match('/^\d{8,}$/', $norm)) {
                    $id_col     = $ci;
                    $name_col   = $ci + 1;
                    $data_start = $ri;
                    break 2;
                }
            }
        }
    } else {
        $data_start = $header_row + 1;
    }

    if ($id_col < 0) {
        // No student data on this sheet.
        return ['meta' => [], 'students' => []];
    }

    // Name column: the header cell to the right of the ID header, else id_col+1.
    if ($header_row >= 0) {
        foreach (($rows[$header_row] ?? []) as $ci => $cell) {
            if ($ci <= $id_col) {
                continue;
            }
            if (stripos((string)$cell, 'name') !== false) {
                $name_col = $ci;
                break;
            }
        }
    }

    // ── Detect the "Grade / Grade point / Cr. Hr. / Semester" header row ──────
    // First find the first actual student data row so we know which rows above
    // it are header rows.
    $first_data_row = null;
    foreach ($rows as $ri => $row) {
        if ($ri < $data_start) {
            continue;
        }
        $norm = tm_normalize_id((string)($row[$id_col] ?? ''));
        if ($norm !== '' && $norm !== '0' && preg_match('/^\d{8,}$/', $norm)) {
            $first_data_row = $ri;
            break;
        }
    }

    $grade_row  = -1;
    $grade_cols = [];
    $scan_limit = $first_data_row ?? count($rows);
    for ($ri = 0; $ri < $scan_limit; $ri++) {
        $cols = [];
        foreach (($rows[$ri] ?? []) as $ci => $cell) {
            if (strtolower(trim((string)$cell)) === 'grade') {
                $cols[] = $ci;
            }
        }
        if (!empty($cols)) {
            $grade_row  = $ri;
            $grade_cols = $cols;
            break;
        }
    }

    // Course-code and course-title rows sit directly above the grade-header row.
    $code_row  = $grade_row >= 2 ? $grade_row - 2 : -1;
    $title_row = $grade_row >= 1 ? $grade_row - 1 : -1;

    // Build the block descriptors.  When no "Grade" header is found, fall back
    // to a fixed 4-column stride starting right after the Name column.
    $blocks = [];
    if (!empty($grade_cols)) {
        foreach ($grade_cols as $gc) {
            $blocks[] = [
                'grade_col'       => $gc,
                'grade_point_col' => $gc + 1,
                'credit_col'      => $gc + 2,
                'semester_col'    => $gc + 3,
                'code'            => $code_row  >= 0 ? trim((string)($rows[$code_row][$gc]  ?? '')) : '',
                'title'           => $title_row >= 0 ? trim((string)($rows[$title_row][$gc] ?? '')) : '',
            ];
        }
    } else {
        $start = $name_col + 1;
        $width = isset($rows[$data_start]) ? count($rows[$data_start]) : 0;
        for ($c = $start; $c + 3 < $width; $c += 4) {
            $blocks[] = [
                'grade_col'       => $c,
                'grade_point_col' => $c + 1,
                'credit_col'      => $c + 2,
                'semester_col'    => $c + 3,
                'code'            => '',
                'title'           => '',
            ];
        }
    }

    // ── Metadata ─────────────────────────────────────────────────────────────
    $meta = [
        'program'            => tm_extract_meta($rows, $data_start, ['program']),
        'batch'              => tm_extract_meta($rows, $data_start, ['batch']),
        'admission_semester' => tm_extract_meta($rows, $data_start, ['enrollment semester', 'admission semester', 'enrolment semester']),
        'university'         => tm_find_heading($rows, $data_start, 'University'),
        'department'         => tm_find_heading($rows, $data_start, 'Department'),
        'faculty'            => tm_find_heading($rows, $data_start, 'Faculty'),
    ];

    // ── Data rows ────────────────────────────────────────────────────────────
    $students = [];
    foreach ($rows as $ri => $row) {
        if ($ri < $data_start) {
            continue;
        }
        $id = tm_normalize_id((string)($row[$id_col] ?? ''));
        if ($id === '' || $id === '0' || !preg_match('/^\d{8,}$/', $id)) {
            continue;
        }
        $name = trim((string)($row[$name_col] ?? ''));

        $subjects = [];
        foreach ($blocks as $blk) {
            $grade_raw = trim((string)($row[$blk['grade_col']]       ?? ''));
            $gp_raw    = trim((string)($row[$blk['grade_point_col']] ?? ''));
            $cr_raw    = trim((string)($row[$blk['credit_col']]      ?? ''));
            $sem_raw   = trim((string)($row[$blk['semester_col']]    ?? ''));

            $grade_point = is_numeric($gp_raw) ? (float)$gp_raw : null;
            $credit      = is_numeric($cr_raw) ? (float)$cr_raw : null;

            // Skip completely empty blocks (no code and no data of any kind).
            if ($blk['code'] === '' && $grade_raw === '' && $grade_point === null && $credit === null) {
                continue;
            }

            $subjects[] = [
                'code'        => $blk['code'],
                'title'       => $blk['title'],
                'grade'       => $grade_raw,
                'grade_point' => $grade_point,
                'credit'      => $credit,
                'semester'    => $sem_raw,
            ];
        }

        if (isset($students[$id])) {
            $students[$id]['subjects'] = array_merge($students[$id]['subjects'], $subjects);
            if ($students[$id]['name'] === '' && $name !== '') {
                $students[$id]['name'] = $name;
            }
        } else {
            $students[$id] = ['name' => $name, 'subjects' => $subjects];
        }
    }

    return ['meta' => $meta, 'students' => $students];
}

/**
 * Parse an entire uploaded tabulation file into transcript records.
 *
 * Returns:
 *   [
 *     'meta'     => [program, batch, admission_semester, university, department, faculty],
 *     'students' => [ id => [
 *         'id', 'name', 'subjects' => [ … ],
 *         'total_credit'  => float,   // Σ credits of graded courses
 *         'cgpa'          => float|null,
 *     ] ],
 *   ]
 */
function tm_parse_file(string $tmp_path, string $ext): array
{
    $spreadsheet = tm_load_spreadsheet($tmp_path, $ext);
    $sheet_count = $spreadsheet->getSheetCount();

    $meta     = [];
    $students = [];

    for ($si = 0; $si < $sheet_count; $si++) {
        $ws     = $spreadsheet->getSheet($si);
        $rows   = tm_worksheet_to_array($ws);
        $parsed = tm_parse_sheet($rows);

        // Keep the first non-empty value for each metadata field.
        foreach ($parsed['meta'] as $k => $v) {
            if (($meta[$k] ?? '') === '' && $v !== '') {
                $meta[$k] = $v;
            }
        }

        foreach ($parsed['students'] as $id => $data) {
            if (!isset($students[$id])) {
                $students[$id] = ['name' => $data['name'], 'subjects' => []];
            }
            if ($students[$id]['name'] === '' && $data['name'] !== '') {
                $students[$id]['name'] = $data['name'];
            }
            $students[$id]['subjects'] = array_merge($students[$id]['subjects'], $data['subjects']);
        }
    }

    // Compute CGPA and total credit for every student.
    foreach ($students as $id => &$student) {
        $student['id'] = $id;
        $qp = 0.0;
        $cr = 0.0;
        foreach ($student['subjects'] as $s) {
            if ($s['grade_point'] !== null && $s['credit'] !== null && $s['credit'] > 0) {
                $qp += $s['grade_point'] * $s['credit'];
                $cr += $s['credit'];
            }
        }
        $student['total_credit'] = $cr;
        $student['cgpa']         = $cr > 0 ? round($qp / $cr, 2) : null;
    }
    unset($student);

    // Stable ordering by student ID for a predictable dropdown.
    ksort($students);

    return ['meta' => $meta, 'students' => $students];
}
