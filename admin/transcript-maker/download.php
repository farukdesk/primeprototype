<?php
/**
 * Transcript Maker – Word (.doc) export
 *
 * Streams the selected student's academic transcript as a Microsoft Word
 * document.  The file is generated as Word-compatible HTML served with the
 * application/msword MIME type — Word opens it as an editable .doc, so no extra
 * Word-generation library is required.
 *
 * Reads the parsed tabulation data cached in the session by index.php.  The
 * transcript header fields (Program, Admission Semester, Completion Semester,
 * Area of Concentration) may be overridden via query string.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_access('transcript-maker');
require_once __DIR__ . '/helpers.php';

const TM_SESSION_KEY = 'transcript_maker_data';

$data     = $_SESSION[TM_SESSION_KEY] ?? null;
$students = $data['students'] ?? [];
$meta     = $data['meta']     ?? [];

$sid = isset($_GET['sid']) ? (string)$_GET['sid'] : '';
if (!$data || $sid === '' || !isset($students[$sid])) {
    $_SESSION['flash_error'] = 'No transcript data available. Please upload a tabulation file and select a student first.';
    redirect(APP_URL . '/transcript-maker/index.php');
}

$student = $students[$sid];

/* ── Header field values (query overrides win over parsed metadata) ─────────── */
$program              = trim((string)($_GET['program']              ?? $meta['program']            ?? ''));
$admission_semester   = trim((string)($_GET['admission_semester']   ?? $meta['admission_semester'] ?? ''));
$completion_semester  = trim((string)($_GET['completion_semester']  ?? ''));
$area_of_concentration = trim((string)($_GET['area_of_concentration'] ?? ''));

$total_credit = rtrim(rtrim(number_format((float)$student['total_credit'], 2, '.', ''), '0'), '.');
if ($total_credit === '') {
    $total_credit = '0';
}
$cgpa = $student['cgpa'] !== null ? number_format((float)$student['cgpa'], 2) : '';

/* ── Build the subject rows ─────────────────────────────────────────────────── */
$rows = [];
$sl   = 0;
foreach ($student['subjects'] as $sub) {
    if ($sub['code'] === '' && $sub['grade'] === '') {
        continue;
    }
    $sl++;
    $rows[] = [
        'sl'          => $sl,
        'code'        => $sub['code'],
        'title'       => $sub['title'],
        'credit'      => $sub['credit'] !== null
            ? rtrim(rtrim(number_format((float)$sub['credit'], 2, '.', ''), '0'), '.')
            : '',
        'grade'       => tm_format_grade($sub['grade']),
        'grade_point' => $sub['grade_point'] !== null ? number_format((float)$sub['grade_point'], 2) : '',
    ];
}

/* ── Stream the Word document ───────────────────────────────────────────────── */
$safe_id  = preg_replace('/[^0-9A-Za-z_-]/', '', $student['id']);
$filename = 'transcript-' . ($safe_id !== '' ? $safe_id : 'student') . '.doc';

header('Content-Type: application/msword; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

/**
 * Render one "Label : value | Label : value" line of the two-column transcript
 * header block.  The right-hand label/value pair is optional so the first line
 * (Program) can span the full width.  Layout, widths and bolding mirror the
 * reference document (Arial 10pt, tab-aligned two-column header).
 */
function tm_meta_row(string $lLabel, string $lValue, ?string $rLabel = null, string $rValue = ''): string
{
    $out = '<tr>'
        . '<td class="ml">' . h($lLabel) . '</td>'
        . '<td class="mc">:</td>';

    if ($rLabel === null) {
        // Program line – value spans the remaining four columns.
        $out .= '<td class="mv" colspan="4">' . h($lValue) . '</td>';
    } else {
        $out .= '<td class="mv">' . h($lValue) . '</td>'
            . '<td class="ml">' . h($rLabel) . '</td>'
            . '<td class="mc">:</td>'
            . '<td class="mv">' . h($rValue) . '</td>';
    }

    return $out . '</tr>';
}

$rowspan = max(1, count($rows));
?><!DOCTYPE html>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:w="urn:schemas-microsoft-com:office:word"
      xmlns="http://www.w3.org/TR/REC-html40">
<head>
    <meta charset="UTF-8">
    <title>Transcript <?= h($student['id']) ?></title>
    <!--[if gte mso 9]>
    <xml>
        <w:WordDocument>
            <w:View>Print</w:View>
            <w:Zoom>100</w:Zoom>
        </w:WordDocument>
    </xml>
    <![endif]-->
    <style>
        /* A4 with a tall top margin that leaves room for the pre-printed
           university letterhead, matching the reference transcript. */
        @page { size: 21cm 29.7cm; margin: 2.9in 0.5in 1.2in 0.6in; }
        body  { font-family: Arial, sans-serif; font-size: 10pt; color: #000; }

        /* Two-column tab-aligned header block. */
        table.meta { width: 517.5pt; border-collapse: collapse; margin-bottom: 6pt; }
        table.meta td { padding: 1pt 0; vertical-align: top; font-size: 10pt; }
        table.meta td.ml { font-weight: bold; white-space: nowrap; }
        table.meta td.mc { width: 8pt; }
        table.meta td.ml:first-child { width: 105pt; }

        /* Grades table – fixed column widths mirror the reference document
           (twips / 20 = points). */
        table.grades { width: 517.5pt; border-collapse: collapse; table-layout: fixed; }
        table.grades td, table.grades th {
            border: 0.5pt solid #000;
            padding: 0 5.4pt;
            font-size: 10pt;
            vertical-align: middle;
        }
        table.grades th { font-weight: bold; text-align: center; }
        .c { text-align: center; }
        .cgpa-cell { text-align: center; vertical-align: middle; }
    </style>
</head>
<body>
    <table class="meta">
        <?= tm_meta_row('Program', $program) ?>
        <?= tm_meta_row('Name of Student', $student['name'], 'Admission Semester', $admission_semester) ?>
        <?= tm_meta_row('ID No.', $student['id'], 'Completion Semester', $completion_semester) ?>
        <?= tm_meta_row('Area of Concentration', $area_of_concentration, 'Total Credit', $total_credit) ?>
    </table>

    <table class="grades" width="690" border="1" bordercolor="#000000" cellspacing="0">
        <thead>
            <tr style="height:25.45pt;" height="34">
                <th width="42" style="width:31.35pt;height:25.45pt;">Sl. No.</th>
                <th width="78" style="width:58.75pt;">Course Code</th>
                <th width="282" style="width:211.4pt;">Course Title</th>
                <th width="54" style="width:40.35pt;">Credit</th>
                <th width="85" style="width:63.8pt;">Grade Obtained</th>
                <th width="78" style="width:58.45pt;">Grade Point</th>
                <th width="71" style="width:53.4pt;">CGPA</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
            <tr style="height:17.25pt;" height="23">
                <td colspan="6" class="c" style="height:17.25pt;">No graded courses found.</td>
                <td class="cgpa-cell"><?= h($cgpa) ?></td>
            </tr>
            <?php else: ?>
                <?php foreach ($rows as $i => $r): ?>
                <tr style="height:17.25pt;" height="23">
                    <td class="c" width="42" style="width:31.35pt;height:17.25pt;"><?= h((string)$r['sl']) ?></td>
                    <td width="78" style="width:58.75pt;"><?= h($r['code']) ?></td>
                    <td width="282" style="width:211.4pt;"><?= h($r['title']) ?></td>
                    <td class="c" width="54" style="width:40.35pt;"><?= h($r['credit']) ?></td>
                    <td width="85" style="width:63.8pt;"><?= h($r['grade']) ?></td>
                    <td class="c" width="78" style="width:58.45pt;"><?= h($r['grade_point']) ?></td>
                    <?php if ($i === 0): ?>
                    <td class="cgpa-cell" width="71" style="width:53.4pt;" rowspan="<?= $rowspan ?>"><?= h($cgpa) ?></td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>
<?php
exit;
