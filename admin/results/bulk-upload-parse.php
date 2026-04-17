<?php
/**
 * Smart Bulk Upload – OCR Text Parser  (AJAX endpoint)
 *
 * POST fields:
 *   ocr_file   – uploaded file (.txt, .docx, .doc, .rtf, .odt or any text file)
 *   raw_text   – pasted text (alternative to file)
 *   _csrf_token
 *
 * Returns JSON:
 *   { students: [ { name, sid, cgpa, grades: [ { code, title, letter, gp } ] } ],
 *     warnings: [] }
 */

require_once __DIR__ . '/../includes/auth.php';
require_access('results', 'can_create');
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

csrf_check();

// ── 1. Extract raw text from upload or paste ──────────────────────────────────

$raw = '';

if (!empty($_FILES['ocr_file']['tmp_name']) && $_FILES['ocr_file']['error'] === UPLOAD_ERR_OK) {
    $f   = $_FILES['ocr_file'];
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    $tmp = (string)$f['tmp_name'];

    switch ($ext) {
        case 'docx':
            $raw = ocr_read_docx($tmp);
            break;
        case 'doc':
            $raw = ocr_read_doc($tmp);
            break;
        case 'rtf':
            $raw = ocr_read_rtf((string)file_get_contents($tmp));
            break;
        case 'odt':
            $raw = ocr_read_odt($tmp);
            break;
        default:
            // .txt, .text, .csv or any other text-based file
            $raw = (string)file_get_contents($tmp);
    }
} elseif (!empty($_POST['raw_text'])) {
    $raw = trim((string)$_POST['raw_text']);
} elseif (!empty($_FILES['ocr_file']['error']) && $_FILES['ocr_file']['error'] !== UPLOAD_ERR_NO_FILE) {
    $upload_errors = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload size limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder on server.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
    ];
    $code = (int)$_FILES['ocr_file']['error'];
    echo json_encode(['error' => $upload_errors[$code] ?? 'Upload error code ' . $code]);
    exit;
}

if (trim($raw) === '') {
    echo json_encode(['error' => 'No text could be extracted. Please upload a file or paste text.']);
    exit;
}

// Normalise encoding to UTF-8
if (!mb_check_encoding($raw, 'UTF-8')) {
    $detected = mb_detect_encoding($raw, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'UTF-16'], true);
    if (!$detected) {
        // Could not auto-detect encoding; defaulting to ISO-8859-1 may affect non-ASCII characters
        $detected = 'ISO-8859-1';
    }
    $raw = mb_convert_encoding($raw, 'UTF-8', $detected);
}

// ── 2. Parse and return ───────────────────────────────────────────────────────

echo json_encode(ocr_parse($raw), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;


// =============================================================================
// File-format text extractors
// =============================================================================

function ocr_read_docx(string $path): string
{
    if (!class_exists('ZipArchive')) return '';
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return '';
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) return '';

    // Paragraph breaks → newlines
    $xml = preg_replace('/<\/w:p>/',        "\n", $xml) ?? $xml;
    $xml = preg_replace('/<w:br[^>]*\/>/',  "\n", $xml) ?? $xml;
    $xml = preg_replace('/<w:tab[^>]*\/>/', ' ',  $xml) ?? $xml;
    $xml = strip_tags($xml);
    return html_entity_decode($xml, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function ocr_read_doc(string $path): string
{
    $raw = (string)file_get_contents($path);
    // Rough binary extraction: keep printable latin + common whitespace.
    // Preserves: tab (\x09), LF (\x0A), CR (\x0D), ASCII printable (\x20-\x7E),
    // Windows-1252 extended (\x80-\x9F), and ISO-8859-1 extended (\xA0-\xFF).
    $raw = preg_replace('/[^\x09\x0A\x0D\x20-\x7E\x80-\x9F\xA0-\xFF]/', ' ', $raw) ?? $raw;
    $raw = preg_replace('/\s{4,}/', "\n", $raw) ?? $raw;
    return trim($raw);
}

function ocr_read_rtf(string $s): string
{
    // Drop well-known non-text RTF groups
    $s = preg_replace('/\{\\\\(?:fonttbl|colortbl|stylesheet|info|pict|header|footer|headerf|footerf)[^{}]*\}/s', '', $s) ?? $s;
    // Paragraph / line breaks
    $s = str_replace(['\par', '\pard', '\line', '\sect'], ["\n", '', "\n", "\n"], $s);
    // Remove all remaining control words
    $s = preg_replace('/\\\\[a-z]+\-?\d*[ ]?/i', '', $s) ?? $s;
    $s = preg_replace('/\\\\[^a-z0-9\s]/i', '', $s) ?? $s;
    $s = str_replace(['{', '}'], '', $s);
    $s = preg_replace('/[^\x09\x0A\x0D\x20-\x7E\x80-\xFF]/', '', $s) ?? $s;
    return trim($s);
}

function ocr_read_odt(string $path): string
{
    if (!class_exists('ZipArchive')) return '';
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return '';
    $xml = $zip->getFromName('content.xml');
    $zip->close();
    if ($xml === false) return '';

    $xml = preg_replace('/<text:p[^>]*>/', "\n", $xml) ?? $xml;
    $xml = preg_replace('/<text:line-break[^>]*\/>/', "\n", $xml) ?? $xml;
    $xml = strip_tags($xml);
    return html_entity_decode($xml, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}


// =============================================================================
// OCR Result Parser
// =============================================================================

/**
 * Parse OCR-scanned result text into structured student/grade data.
 *
 * Handles two formats produced by different OCR scans:
 *
 *   Format A (tabular – fields concatenated):
 *     BEL-111English Reading & Public SpeakingB+3.25
 *
 *   Format B (inline / sectioned):
 *     Foundation: BEL-111 (A), BNG-112 (B+), …
 *
 * Student block header (both formats):
 *   1. Full Name (ID: 193020101021)CGPA: 3.19 | Total Credits: 129
 */
function ocr_parse(string $raw): array
{
    // Canonical grade-point map
    $GP = [
        'A+' => 4.00, 'A' => 3.75, 'A-' => 3.50,
        'B+' => 3.25, 'B' => 3.00, 'B-' => 2.75,
        'C+' => 2.50, 'C' => 2.25, 'D'  => 2.00, 'F' => 0.00,
    ];

    // Normalise line endings
    $text = preg_replace('/\r\n|\r/', "\n", $raw) ?? $raw;

    // ── Pre-process dense OCR text (no newlines between entries) ──────────────
    // Insert newline before each course-code token so the line-based
    // Format-A parser can work even when the scanner strips all line breaks.
    $text = preg_replace(
        '/(?<=[a-z0-9\)\s,\.])([A-Z]{2,6}-\d{3}[A-Z]?)/',
        "\n$1",
        $text
    ) ?? $text;

    // Insert newline before numbered student-entry lines "N. Name (ID:…)"
    $text = preg_replace(
        '/(?<!\n)(\d{1,3}\.\s+[A-Z][a-zA-Z\s.]+\(ID:)/',
        "\n$1",
        $text
    ) ?? $text;

    $students = [];
    $warnings = [];

    // ── Locate every student header ───────────────────────────────────────────
    // Pattern: "1. Full Name (ID: 193020101021)"
    preg_match_all(
        '/\d{1,3}\.\s+(.+?)\s*\(ID:\s*(\d{8,20})\)/u',
        $text,
        $hm,
        PREG_OFFSET_CAPTURE
    );

    $count = count($hm[0]);
    if ($count === 0) {
        return [
            'students' => [],
            'warnings' => [
                'No student entries found. ' .
                'Expected format: "1. Student Name (ID: xxxxxxxxxxxx)"'
            ],
        ];
    }

    for ($i = 0; $i < $count; $i++) {
        $start = (int)$hm[0][$i][1];
        $end   = ($i + 1 < $count) ? (int)$hm[0][$i + 1][1] : strlen($text);
        $block = substr($text, $start, $end - $start);

        $student_name = trim((string)$hm[1][$i][0]);
        $student_sid  = (string)$hm[2][$i][0];

        // Extract CGPA if present
        $cgpa = null;
        if (preg_match('/CGPA\s*:\s*([\d.]+)/i', $block, $cm)) {
            $cgpa = (float)$cm[1];
        }

        $grades = [];

        // ── Try Format B: "CODE (LETTER)" inline pairs ────────────────────────
        $n_inline = preg_match_all(
            '/([A-Z]{2,6}-\d{3}[A-Z]?|Internship|Viva\s*Voce)\s*\(\s*([A-Z][+-]?)\s*\)/u',
            $block,
            $bm
        );

        if ($n_inline > 0) {
            for ($j = 0; $j < $n_inline; $j++) {
                $code   = preg_replace('/\s+/', ' ', trim((string)$bm[1][$j]));
                $letter = strtoupper(trim((string)$bm[2][$j]));
                if (!array_key_exists($letter, $GP)) {
                    $warnings[] = "Unknown grade '$letter' for '$code' (student: $student_name)";
                    continue;
                }
                $grades[] = [
                    'code'   => $code,
                    'title'  => null,
                    'letter' => $letter,
                    'gp'     => $GP[$letter],
                ];
            }
        } else {
            // ── Format A: tabular lines ───────────────────────────────────────
            foreach (explode("\n", $block) as $line) {
                $line = trim($line);
                if (!$line) continue;

                // Skip known metadata / column-header lines
                if (preg_match(
                    '/^(?:Course\s*Code|Grade\s*GP|CGPA|Total\s*Credits?|Foundation|Core|Accounting|Finance|Major|Final|SL\.|#\s)/i',
                    $line
                )) continue;

                // Line must end with a valid grade-point value (X.XX)
                if (!preg_match('/((?:[0-4]\.\d{2}))\s*$/', $line, $gpm)) continue;

                $gp_raw  = (float)$gpm[1];
                $trim_at = strrpos($line, $gpm[0]);
                $line_x  = trim(substr($line, 0, (int)$trim_at));

                // Extract letter grade from the end (optionally followed by a separator dash)
                if (!preg_match('/(A\+|A-|A|B\+|B-|B|C\+|C|D|F)\s*-?\s*$/', $line_x, $grm)) continue;
                $letter = $grm[1];

                if (!array_key_exists($letter, $GP)) continue;

                // Remove grade (and any trailing separator) from the string
                $line_x = trim(preg_replace('/(A\+|A-|A|B\+|B-|B|C\+|C|D|F)\s*-?\s*$/', '', $line_x) ?? '');
                // Strip any remaining trailing separator dash
                $line_x = rtrim($line_x, ' -');

                // Use authoritative grade point from the map
                $gp = $GP[$letter];

                // Extract course code at start
                $code  = null;
                $title = $line_x;
                if (preg_match('/^([A-Z]{2,6}-\d{3}[A-Z]?)\s*/', $line_x, $cdm)) {
                    $code  = $cdm[1];
                    $title = trim(substr($line_x, strlen($cdm[0])));
                }

                if (!$code && strlen(trim($title)) < 2) continue;

                $grades[] = [
                    'code'   => $code,
                    'title'  => $title ?: ($code ?? ''),
                    'letter' => $letter,
                    'gp'     => $gp,
                ];
            }
        }

        if (empty($grades)) {
            $warnings[] = "No grades detected for $student_name (ID: $student_sid)";
            continue;
        }

        $students[] = [
            'name'   => $student_name,
            'sid'    => $student_sid,
            'cgpa'   => $cgpa,
            'grades' => $grades,
        ];
    }

    return ['students' => $students, 'warnings' => $warnings];
}
