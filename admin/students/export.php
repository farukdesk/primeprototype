<?php
/**
 * Student List – Export (PDF / Excel / Word)
 *
 * Streams the filtered student list as a downloadable document.  The filters
 * are read from the query string and match the ones used on index.php (via the
 * shared sm_build_list_filter() helper), so "download based on filter" always
 * mirrors what the admin sees on screen.
 *
 * Layout (all formats):
 *   ┌────────────────────────────────────────────────┐
 *   │ [Logo]        Prime University                  │
 *   │               Student List                      │
 *   │               <active filters>                  │
 *   ├──────────────┬───────────┬───────────┬──────────┤
 *   │ Student Name │ Student ID│ Department│ Program   │
 *   │ …                                               │
 *   ├─────────────────────────────────────────────────┤
 *   │ Total: N students                               │
 *   └─────────────────────────────────────────────────┘
 *
 * Supported ?format= values: pdf (default), excel, word.
 */

require_once __DIR__ . '/../includes/auth.php';
require_access('students');
require_once __DIR__ . '/helpers.php';
require_once dirname(__DIR__) . '/../vendor/autoload.php';

$format = strtolower((string)($_GET['format'] ?? 'pdf'));
if (!in_array($format, ['pdf', 'excel', 'word'], true)) {
    $format = 'pdf';
}

// ── Build the same filter as the list page ────────────────────────────────────
$dept_scope = get_dept_scope();
$filter     = sm_build_list_filter($_GET, $dept_scope);

$sql = 'SELECT s.full_name,
               s.student_id,
               s.phone,
               d.name AS dept_name,
               p.program_name
        FROM students s
        JOIN dept_departments d ON d.id = s.dept_id
        LEFT JOIN dept_academic_programs p ON p.id = s.program_id'
     . $filter['where_sql']
     . ' ORDER BY LENGTH(s.student_id) ASC, s.student_id ASC';

$stmt = db()->prepare($sql);
$stmt->execute($filter['params']);
$rows  = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total = count($rows);

$university   = 'Prime University';
$doc_title    = 'Student List';
$generated_at = date('d M Y, h:i A');

// Human-readable filter summary line, e.g. "Department: CSE  •  Status: Active".
$filter_parts = [];
foreach ($filter['labels'] as [$label, $value]) {
    $filter_parts[] = $label . ': ' . $value;
}
$filter_summary = $filter_parts ? implode('  •  ', $filter_parts) : 'All students (no filter applied)';

// Logo (absolute filesystem path within the repository).
$logo_path = dirname(__DIR__, 2) . '/assets/img/logo/logo-black.png';
$logo_uri  = '';
if (is_file($logo_path)) {
    $logo_uri = 'data:image/png;base64,' . base64_encode((string)file_get_contents($logo_path));
}

$filename_base = 'student-list-' . date('Ymd-His');

// ──────────────────────────────────────────────────────────────────────────────
// Excel export (PhpSpreadsheet)
// ──────────────────────────────────────────────────────────────────────────────
if ($format === 'excel') {
    $ss    = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle('Student List');

    // Column widths.
    $sheet->getColumnDimension('A')->setWidth(32);
    $sheet->getColumnDimension('B')->setWidth(18);
    $sheet->getColumnDimension('C')->setWidth(20);
    $sheet->getColumnDimension('D')->setWidth(30);
    $sheet->getColumnDimension('E')->setWidth(30);

    // Logo (rows are made tall enough for the header block).
    if ($logo_uri !== '' && is_file($logo_path)) {
        $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
        $drawing->setName('Logo');
        $drawing->setPath($logo_path);
        $drawing->setHeight(48);
        $drawing->setCoordinates('A1');
        $drawing->setOffsetX(4);
        $drawing->setOffsetY(4);
        $drawing->setWorksheet($sheet);
    }

    // Header block (centered across B:E so the logo stays in column A).
    $sheet->mergeCells('B1:E1');
    $sheet->setCellValue('B1', $university);
    $sheet->mergeCells('B2:E2');
    $sheet->setCellValue('B2', $doc_title);
    $sheet->mergeCells('B3:E3');
    $sheet->setCellValue('B3', $filter_summary);

    $sheet->getStyle('B1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('B2')->getFont()->setBold(true)->setSize(13);
    $sheet->getStyle('B3')->getFont()->setSize(10)->getColor()->setRGB('555555');
    foreach (['B1', 'B2', 'B3'] as $c) {
        $sheet->getStyle($c)->getAlignment()
              ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    }
    $sheet->getRowDimension(1)->setRowHeight(26);
    $sheet->getRowDimension(2)->setRowHeight(20);

    // Column headings.
    $head_row = 5;
    $headings = ['Student Name', 'Student ID', 'Phone', 'Department', 'Program'];
    foreach ($headings as $i => $text) {
        $col  = chr(ord('A') + $i);
        $cell = $col . $head_row;
        $sheet->setCellValue($cell, $text);
        $sheet->getStyle($cell)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($cell)->getFill()
              ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
              ->getStartColor()->setRGB('3A6FD8');
    }

    // Data rows.
    $r = $head_row + 1;
    foreach ($rows as $row) {
        $sheet->setCellValueExplicit('A' . $r, (string)$row['full_name'],
            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('B' . $r, (string)$row['student_id'],
            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('C' . $r, (string)($row['phone'] ?? ''),
            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('D' . $r, (string)$row['dept_name'],
            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('E' . $r, (string)($row['program_name'] ?? ''),
            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $r++;
    }

    // Borders around the table.
    $last_data_row = $r - 1;
    if ($last_data_row >= $head_row) {
        $sheet->getStyle('A' . $head_row . ':E' . $last_data_row)
              ->getBorders()->getAllBorders()
              ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
    }

    // Total row.
    $sheet->mergeCells('A' . $r . ':E' . $r);
    $sheet->setCellValue('A' . $r, 'Total: ' . $total . ' student' . ($total === 1 ? '' : 's'));
    $sheet->getStyle('A' . $r)->getFont()->setBold(true);
    $sheet->getStyle('A' . $r)->getAlignment()
          ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename_base . '.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);
    $writer->save('php://output');
    exit;
}

// ──────────────────────────────────────────────────────────────────────────────
// HTML body shared by the PDF and Word exports
// ──────────────────────────────────────────────────────────────────────────────
$body_rows = '';
if ($rows) {
    foreach ($rows as $row) {
        $body_rows .= '<tr>'
            . '<td>' . h($row['full_name']) . '</td>'
            . '<td>' . h($row['student_id']) . '</td>'
            . '<td>' . h($row['phone'] ?? '') . '</td>'
            . '<td>' . h($row['dept_name']) . '</td>'
            . '<td>' . h($row['program_name'] ?? '') . '</td>'
            . '</tr>';
    }
} else {
    $body_rows = '<tr><td colspan="5" style="text-align:center;color:#777;">No students match the selected filters.</td></tr>';
}

$logo_html = $logo_uri !== ''
    ? '<img src="' . $logo_uri . '" alt="Logo" style="height:60px;">'
    : '';

$html = '<!DOCTYPE html>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:w="urn:schemas-microsoft-com:office:word"
      xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="UTF-8">
<title>' . h($doc_title) . '</title>
<style>
    body   { font-family: Arial, sans-serif; font-size: 11px; color: #000; }
    .head  { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    .head td { vertical-align: middle; }
    .head .logo   { width: 90px; text-align: left; }
    .head .center { text-align: center; }
    .uni    { font-size: 20px; font-weight: bold; margin: 0; }
    .doc    { font-size: 15px; font-weight: bold; margin: 2px 0; }
    .filter { font-size: 11px; color: #555; margin: 2px 0; }
    .meta   { font-size: 10px; color: #888; margin-top: 2px; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data th, table.data td {
        border: 1px solid #888; padding: 5px 7px; font-size: 11px; text-align: left;
    }
    table.data th { background: #3A6FD8; color: #fff; }
    .total { margin-top: 10px; font-weight: bold; text-align: right; font-size: 12px; }
</style>
</head>
<body>
    <table class="head">
        <tr>
            <td class="logo">' . $logo_html . '</td>
            <td class="center">
                <p class="uni">' . h($university) . '</p>
                <p class="doc">' . h($doc_title) . '</p>
                <p class="filter">' . h($filter_summary) . '</p>
                <p class="meta">Generated: ' . h($generated_at) . '</p>
            </td>
            <td class="logo">&nbsp;</td>
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr>
                <th style="width:28%;">Student Name</th>
                <th style="width:15%;">Student ID</th>
                <th style="width:15%;">Phone</th>
                <th style="width:21%;">Department</th>
                <th style="width:21%;">Program</th>
            </tr>
        </thead>
        <tbody>' . $body_rows . '</tbody>
    </table>

    <p class="total">Total: ' . $total . ' student' . ($total === 1 ? '' : 's') . '</p>
</body>
</html>';

// ──────────────────────────────────────────────────────────────────────────────
// Word export (Word-compatible HTML served as .doc)
// ──────────────────────────────────────────────────────────────────────────────
if ($format === 'word') {
    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename_base . '.doc"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $html;
    exit;
}

// ──────────────────────────────────────────────────────────────────────────────
// PDF export (dompdf)
// ──────────────────────────────────────────────────────────────────────────────
$dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream($filename_base . '.pdf', ['Attachment' => true]);
exit;
