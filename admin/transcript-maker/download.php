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
$university   = trim((string)($meta['university'] ?? 'Prime University'));
$department   = trim((string)($meta['department'] ?? ''));
$faculty      = trim((string)($meta['faculty']    ?? ''));

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
 * Render a "Label : value" row of the transcript header block.
 */
function tm_meta_row(string $label, string $value): string
{
    return '<tr>'
        . '<td style="width:180px;font-weight:bold;">' . h($label) . '</td>'
        . '<td style="width:14px;">:</td>'
        . '<td>' . h($value) . '</td>'
        . '</tr>';
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
        @page { size: A4 portrait; margin: 1.6cm 1.4cm; }
        body  { font-family: "Times New Roman", serif; font-size: 11pt; color: #000; }
        .center { text-align: center; }
        .univ  { font-size: 16pt; font-weight: bold; }
        .dept  { font-size: 12pt; font-weight: bold; }
        .fac   { font-size: 11pt; }
        .title { font-size: 13pt; font-weight: bold; text-decoration: underline; margin: 10pt 0; }
        table.meta { width: 100%; border-collapse: collapse; margin-bottom: 8pt; }
        table.meta td { padding: 1pt 3pt; vertical-align: top; }
        table.grades { width: 100%; border-collapse: collapse; }
        table.grades th, table.grades td { border: 1px solid #000; padding: 3pt 5pt; }
        table.grades th { background: #e8e8e8; text-align: center; font-weight: bold; }
        .c { text-align: center; }
        .cgpa-cell { text-align: center; vertical-align: middle; font-size: 14pt; font-weight: bold; }
    </style>
</head>
<body>
    <div class="center">
        <div class="univ"><?= h($university !== '' ? $university : 'Prime University') ?></div>
        <?php if ($department !== ''): ?><div class="dept"><?= h($department) ?></div><?php endif; ?>
        <?php if ($faculty !== ''): ?><div class="fac"><?= h($faculty) ?></div><?php endif; ?>
        <div class="title">Academic Transcript</div>
    </div>

    <table class="meta">
        <?= tm_meta_row('Program', $program) ?>
        <?= tm_meta_row('Name of Student', $student['name']) ?>
        <?= tm_meta_row('Admission Semester', $admission_semester) ?>
        <?= tm_meta_row('ID No.', $student['id']) ?>
        <?= tm_meta_row('Completion Semester', $completion_semester) ?>
        <?= tm_meta_row('Area of Concentration', $area_of_concentration) ?>
        <?= tm_meta_row('Total Credit', $total_credit) ?>
    </table>

    <table class="grades">
        <thead>
            <tr>
                <th style="width:44px;">Sl. No.</th>
                <th style="width:80px;">Course Code</th>
                <th>Course Title</th>
                <th style="width:52px;">Credit</th>
                <th style="width:110px;">Grade Obtained</th>
                <th style="width:60px;">Grade Point</th>
                <th style="width:64px;">CGPA</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
            <tr>
                <td colspan="6" class="c">No graded courses found.</td>
                <td class="cgpa-cell"><?= h($cgpa) ?></td>
            </tr>
            <?php else: ?>
                <?php foreach ($rows as $i => $r): ?>
                <tr>
                    <td class="c"><?= h((string)$r['sl']) ?></td>
                    <td><?= h($r['code']) ?></td>
                    <td><?= h($r['title']) ?></td>
                    <td class="c"><?= h($r['credit']) ?></td>
                    <td><?= h($r['grade']) ?></td>
                    <td class="c"><?= h($r['grade_point']) ?></td>
                    <?php if ($i === 0): ?>
                    <td class="cgpa-cell" rowspan="<?= $rowspan ?>"><?= h($cgpa) ?></td>
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
