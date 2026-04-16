<?php
/**
 * Student Files – Smart PDF Upload
 *
 * Accepts one or more PDF files (printed or handwritten).
 * For each file the script:
 *  1. Validates the file is a genuine PDF.
 *  2. Extracts embedded text using a pure-PHP parser (handles FlateDecode
 *     compressed streams, plain BT/ET text blocks, and hex strings).
 *  3. Scans the extracted text for digit sequences that match a student_id
 *     in the `students` table.
 *  4a. Exactly one match  → saves the file to student_files automatically,
 *      renaming the stored file to <student_id>.pdf.
 *  4b. No text / no match → saves the file to student_pdf_pending so the
 *      admin can assign it manually via pending-assign.php.
 *
 * Handwritten / scanned PDFs typically contain no extractable text and will
 * therefore land in the pending queue.  Install pdftotext (poppler-utils) or
 * Tesseract OCR on the server and pipe the result through spu_extract_pdf_text()
 * to extend coverage.
 *
 * SQL prerequisite: run admin/students-smart-upload.sql once.
 */

require_once __DIR__ . '/../includes/auth.php';
require_access('students');
require_once __DIR__ . '/helpers.php';

if (!sm_can_create()) {
    flash_set('error', 'You do not have permission to upload files.');
    redirect(APP_URL . '/students/index.php');
}

$page_title = 'Smart PDF Upload';
$user       = auth_user();

// ── Pending-upload directory ──────────────────────────────────────────────────

$pending_dir = UPLOAD_DIR . '/students/pending';
if (!is_dir($pending_dir)) {
    mkdir($pending_dir, 0755, true);
}

// ── Pure-PHP PDF text extractor ───────────────────────────────────────────────

/**
 * Extract all readable text from a PDF file without any external command.
 *
 * Technique:
 *   • Finds every "stream … endstream" block in the raw PDF bytes.
 *   • Attempts zlib-inflate (FlateDecode) on each block; falls back to raw.
 *   • Within the decompressed content, parses BT … ET sections and picks
 *     up string arguments to Tj / TJ / ' / " operators.
 *   • Also collects <hexadecimal> strings and decodes them.
 *
 * Limitations: does not handle LZWDecode, ASCII85Decode, or encryption.
 * For encrypted / image-only PDFs the function returns an empty string and
 * the file lands in the manual-assignment queue.
 *
 * @param string $filepath Absolute path to the PDF file.
 * @return string All readable text, space-separated.
 */
function spu_extract_pdf_text(string $filepath): string
{
    // Read in 512 KB chunks; collect only enough bytes to extract text
    // without loading the full file into memory at once.
    $fh = @fopen($filepath, 'rb');
    if (!$fh) {
        return '';
    }
    $raw    = '';
    $budget = 10 * 1024 * 1024; // stop after 10 MB of content read
    $chunk  = 512 * 1024;
    while (!feof($fh) && strlen($raw) < $budget) {
        $raw .= fread($fh, $chunk);
    }
    fclose($fh);
    if (strlen($raw) < 5) {
        return '';
    }

    $text = '';

    // ── Walk every stream block ───────────────────────────────────────────────
    // We split on "stream\r\n" or "stream\n" to get the raw bytes, then
    // find the matching "endstream".
    $offset = 0;
    while (($pos = strpos($raw, 'stream', $offset)) !== false) {
        // Locate the newline immediately after the "stream" keyword.
        $nl_pos = strpos($raw, "\n", $pos + 6);
        if ($nl_pos === false) {
            $offset = $pos + 6;
            continue;
        }
        $stream_start = $nl_pos + 1;
        $end_pos = strpos($raw, 'endstream', $stream_start);
        if ($end_pos === false) {
            $offset = $stream_start;
            continue;
        }
        $stream_data = substr($raw, $stream_start, $end_pos - $stream_start);
        // Strip trailing \r\n or \n before endstream
        $stream_data = rtrim($stream_data, "\r\n");
        $offset = $end_pos + 9;

        // Try FlateDecode (zlib) decompress ────────────────────────────────
        $decoded = @gzuncompress($stream_data);
        if ($decoded === false) {
            // Some encoders omit the 2-byte zlib header; try raw inflate.
            $decoded = @gzinflate($stream_data);
        }
        if ($decoded === false) {
            // Not compressed – use as-is (plain content stream).
            $decoded = $stream_data;
        }

        $text .= spu_parse_content_stream($decoded) . ' ';
    }

    return $text;
}

/**
 * Parse a PDF content stream and return all text strings found.
 *
 * @param string $content Decompressed (or raw) PDF content stream.
 * @return string Extracted text with spaces between tokens.
 */
function spu_parse_content_stream(string $content): string
{
    $text = '';

    // ── BT … ET blocks ───────────────────────────────────────────────────────
    if (preg_match_all('/BT(.*?)ET/s', $content, $bt_blocks)) {
        foreach ($bt_blocks[1] as $bt) {
            // Literal strings:  (text) Tj / TJ / ' / "
            if (preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)\s*(?:Tj|TJ|\'|\")/s', $bt, $m)) {
                foreach ($m[1] as $s) {
                    $text .= spu_unescape_pdf_string($s) . ' ';
                }
            }
            // TJ arrays:  [(text)(text)] TJ
            // Each sub-string is emitted with a trailing space, AND the full
            // concatenation is also emitted so that IDs split across
            // kerning/spacing segments are still detectable.
            if (preg_match_all('/\[([^\]]*)\]\s*TJ/s', $bt, $m)) {
                foreach ($m[1] as $arr) {
                    if (preg_match_all('/\(([^)]*)\)/', $arr, $sub)) {
                        $concat = '';
                        foreach ($sub[1] as $s) {
                            $piece   = spu_unescape_pdf_string($s);
                            $text   .= $piece . ' ';
                            $concat .= $piece;
                        }
                        if (count($sub[1]) > 1) {
                            $text .= $concat . ' ';
                        }
                    }
                }
            }
            // Hex strings:  <4865...> Tj
            if (preg_match_all('/<([0-9a-fA-F\s]+)>\s*(?:Tj|TJ|\'|\")/', $bt, $m)) {
                foreach ($m[1] as $hex) {
                    $hex = preg_replace('/\s+/', '', $hex);
                    $text .= @hex2bin($hex) . ' ';
                }
            }
        }
    }

    // ── Hex strings outside BT/ET (rare but present in some PDFs) ────────────
    if (preg_match_all('/<([0-9a-fA-F\s]{4,})>/', $content, $m)) {
        foreach ($m[1] as $hex) {
            $hex = preg_replace('/\s+/', '', $hex);
            if (strlen($hex) % 2 === 0) {
                $decoded = @hex2bin($hex);
                if ($decoded !== false && ctype_print($decoded)) {
                    $text .= $decoded . ' ';
                }
            }
        }
    }

    return $text;
}

/**
 * Unescape a PDF literal string (handles \n, \r, \t, \\, \(, \), \NNN octal).
 *
 * @param string $s Raw content inside parentheses from a PDF literal string.
 * @return string   Unescaped string.
 */
function spu_unescape_pdf_string(string $s): string
{
    return preg_replace_callback('/\\\\([0-7]{1,3}|[nrtbf\\\\()])/', function ($m) {
        $c = $m[1];
        if (is_numeric($c[0])) {
            return chr(octdec($c));
        }
        return match ($c) {
            'n'  => "\n",
            'r'  => "\r",
            't'  => "\t",
            'b'  => "\x08",
            'f'  => "\x0C",
            '\\' => '\\',
            '('  => '(',
            ')'  => ')',
            default => $c,
        };
    }, $s);
}

/**
 * Extract digit sequences that could be student IDs (5–20 digits).
 *
 * Also handles common OCR look-alike substitutions found in scanned PDFs:
 *   O / o  →  0   (letter O mistaken for zero)
 *   I / l  →  1   (letter I or lowercase L mistaken for one)
 *
 * Additionally scans a "collapsed" version of the text where spaces and
 * hyphens between adjacent digits are removed.  This catches IDs that are
 * printed (or re-laid-out after a PDF margin operation) as digit groups,
 * e.g. "2401 0508 0001" or "2401-0508-0001", which would otherwise be split
 * into short fragments below the 5-digit minimum.
 *
 * @param string $text Extracted PDF text.
 * @return string[]    Unique digit sequences ordered longest-first.
 */
function spu_find_id_candidates(string $text): array
{
    if ($text === '') {
        return [];
    }

    $ids = [];

    // Collect digit sequences from both the original text and a version with
    // inter-digit spaces/hyphens stripped so grouped IDs are reassembled.
    $sources = [$text];
    $collapsed = preg_replace('/(?<=\d)[ \-]+(?=\d)/', '', $text);
    if ($collapsed !== $text) {
        $sources[] = $collapsed;
    }

    foreach ($sources as $src) {
        // Standard pure-digit sequences.
        if (preg_match_all('/\b(\d{5,20})\b/', $src, $m)) {
            foreach ($m[1] as $id) {
                $ids[] = $id;
            }
        }

        // OCR-normalised sequences: sequences of digits plus commonly confused
        // letters are normalised (O→0, I→1, l→1) and added as extra candidates.
        if (preg_match_all('/\b([0-9OoIl]{5,20})\b/', $src, $m)) {
            foreach ($m[1] as $raw) {
                if (ctype_digit($raw)) {
                    continue; // already captured above
                }
                $norm = strtr($raw, ['O' => '0', 'o' => '0', 'I' => '1', 'l' => '1']);
                if (ctype_digit($norm)) {
                    $ids[] = $norm;
                }
            }
        }
    }

    // Also add leading-zero-stripped variants so that an ID stored without a
    // leading zero (e.g. "8084848") matches a PDF that prints "08084848", and
    // vice versa (handled on the DB side in spu_match_students).
    $stripped = [];
    foreach ($ids as $id) {
        $s = ltrim($id, '0');
        if ($s !== '' && $s !== $id && strlen($s) >= 5) {
            $stripped[] = $s;
        }
    }
    $ids = array_merge($ids, $stripped);

    $ids = array_unique($ids);
    // Longest sequences first (more specific IDs).
    usort($ids, fn($a, $b) => strlen($b) - strlen($a));
    return $ids;
}

/**
 * Given a list of candidate digit strings, return the students that match.
 *
 * Matching is performed in two ways:
 *  1. Exact string match  – handles all student_id formats.
 *  2. Numeric (UNSIGNED) match – handles leading-zero mismatches where the
 *     database stores "8084848" but the PDF printed "08084848" (or vice versa).
 *     Only applied when the candidate is a pure-digit string.
 *
 * @param string[] $candidates
 * @return array[]  Rows from `students` keyed by student_id string (lower).
 */
function spu_match_students(array $candidates): array
{
    if (empty($candidates)) {
        return [];
    }

    // Exact-string candidates (all).
    $exact = array_values($candidates);
    $phs1  = implode(',', array_fill(0, count($exact), '?'));

    // Numeric candidates: pure-digit strings with leading zeros stripped.
    // Uses ltrim instead of int cast to avoid overflow on long sequences.
    $numeric = array_values(array_unique(array_filter(
        array_map(fn($c) => ctype_digit($c) ? (ltrim($c, '0') ?: '0') : null, $candidates),
        fn($v) => $v !== null && strlen($v) >= 5
    )));
    $params = $exact;
    $where  = "student_id IN ($phs1)";
    if (!empty($numeric)) {
        $phs2   = implode(',', array_fill(0, count($numeric), '?'));
        // Only test numeric match against digit-only stored IDs to avoid false
        // positives from alphanumeric IDs (e.g. "CSE-101") being treated as numbers.
        $where .= " OR (student_id REGEXP '^[0-9]+$' AND TRIM(LEADING '0' FROM student_id) IN ($phs2))";
        $params = array_merge($params, $numeric);
    }

    $stmt = db()->prepare(
        "SELECT id, student_id, full_name, dept_id
         FROM students
         WHERE $where"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $map  = [];
    foreach ($rows as $r) {
        $map[strtolower($r['student_id'])] = $r;
    }
    return $map;
}

// ── Processing ────────────────────────────────────────────────────────────────

$results        = null;  // null = not yet run
$imported       = [];    // auto-matched and saved
$pending_saved  = [];    // saved to student_pdf_pending for manual assignment
$errors         = [];    // validation / I/O errors
$batch_token    = '';

const SPU_MAX_FILES          = 30;
const SPU_MAX_PER_FILE       = 20 * 1024 * 1024; // 20 MB
const SPU_MAX_CANDIDATE_STORE   = 20; // stored in DB per PDF
const SPU_MAX_CANDIDATE_DISPLAY = 10; // shown in UI per PDF

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $file_label  = trim($_POST['file_label']  ?? 'Student Document');
    $description = trim($_POST['description'] ?? '');
    if ($file_label === '') {
        $file_label = 'Student Document';
    }

    $files = $_FILES['pdf_files'] ?? [];

    if (empty($files['name'][0])) {
        flash_set('error', 'Please choose at least one PDF file to upload.');
    } else {
        // Normalise $_FILES multi-upload into an array of individual file arrays.
        $file_list = [];
        $count     = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            $file_list[] = [
                'name'     => $files['name'][$i],
                'type'     => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error'    => $files['error'][$i],
                'size'     => $files['size'][$i],
            ];
        }

        if (count($file_list) > SPU_MAX_FILES) {
            flash_set('error', 'Maximum ' . SPU_MAX_FILES . ' files per upload batch.');
        } else {
            $batch_token = bin2hex(random_bytes(16));

            // Pre-load already-attached filenames to detect duplicates.
            $existing_stmt  = db()->query('SELECT student_id, original_name FROM student_files');
            $existing_files = [];
            foreach ($existing_stmt->fetchAll() as $row) {
                $existing_files[$row['student_id'] . ':' . $row['original_name']] = true;
            }

            $files_dir = UPLOAD_DIR . '/students/files';
            if (!is_dir($files_dir)) {
                mkdir($files_dir, 0755, true);
            }

            $insert_file_stmt = db()->prepare(
                'INSERT INTO student_files
                   (student_id, file_name, description, stored_name,
                    original_name, mime_type, file_size, uploaded_by)
                 VALUES (?,?,?,?,?,?,?,?)'
            );

            $insert_pending_stmt = db()->prepare(
                'INSERT INTO student_pdf_pending
                   (batch_token, original_name, stored_name, file_size,
                    extracted_text, candidate_ids, file_label, description,
                    status, uploaded_by)
                 VALUES (?,?,?,?,?,?,?,?,\'pending\',?)'
            );

            foreach ($file_list as $file) {
                $orig_name = basename($file['name']);

                // ── Basic upload error ──────────────────────────────────────
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $errors[] = [
                        'name'   => $orig_name,
                        'reason' => 'Upload error code: ' . $file['error'],
                    ];
                    continue;
                }

                // ── Size check ──────────────────────────────────────────────
                if ($file['size'] > SPU_MAX_PER_FILE) {
                    $errors[] = [
                        'name'   => $orig_name,
                        'reason' => 'File exceeds the 20 MB limit.',
                    ];
                    continue;
                }

                // ── Extension check ─────────────────────────────────────────
                $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
                if ($ext !== 'pdf') {
                    $errors[] = [
                        'name'   => $orig_name,
                        'reason' => 'Only PDF files are accepted.',
                    ];
                    continue;
                }

                // ── Magic-bytes check ───────────────────────────────────────
                $fh = @fopen($file['tmp_name'], 'rb');
                if (!$fh) {
                    $errors[] = ['name' => $orig_name, 'reason' => 'Could not read uploaded file.'];
                    continue;
                }
                $magic = fread($fh, 4);
                fclose($fh);
                if ($magic !== '%PDF') {
                    $errors[] = [
                        'name'   => $orig_name,
                        'reason' => 'File does not appear to be a valid PDF.',
                    ];
                    continue;
                }

                // ── Extract text ────────────────────────────────────────────
                $extracted_text = spu_extract_pdf_text($file['tmp_name']);
                $candidates     = spu_find_id_candidates($extracted_text);
                $matched_rows   = spu_match_students($candidates);

                // Determine unique student matches.
                $matched_students = array_values($matched_rows);

                // ── Store file on disk first (temp or final) ────────────────
                $stored_name = bin2hex(random_bytes(12)) . '.pdf';

                if (count($matched_students) === 1) {
                    // ── Auto-match path ─────────────────────────────────────
                    $stu     = $matched_students[0];
                    $stu_pk  = (int)$stu['id'];
                    $sid_raw = $stu['student_id'];

                    // Canonical original name: <student_id>.pdf
                    $canonical_orig = $sid_raw . '.pdf';

                    // Duplicate check
                    $dup_key = $stu_pk . ':' . $canonical_orig;
                    if (isset($existing_files[$dup_key])) {
                        $errors[] = [
                            'name'   => $orig_name,
                            'reason' => 'File already attached to student ' . $sid_raw . ' (duplicate skipped).',
                        ];
                        continue;
                    }

                    $dest_path = $files_dir . '/' . $stored_name;
                    if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
                        $errors[] = ['name' => $orig_name, 'reason' => 'Failed to move file to storage.'];
                        continue;
                    }

                    $insert_file_stmt->execute([
                        $stu_pk,
                        $file_label,
                        $description ?: null,
                        $stored_name,
                        $canonical_orig,
                        'application/pdf',
                        $file['size'],
                        $user['id'],
                    ]);

                    $existing_files[$dup_key] = true;

                    $imported[] = [
                        'original_name' => $orig_name,
                        'student_id'    => $sid_raw,
                        'student_name'  => $stu['full_name'],
                        'stored_as'     => $canonical_orig,
                    ];

                } else {
                    // ── Pending path (no match or ambiguous) ────────────────
                    $pending_path = $pending_dir . '/' . $stored_name;
                    if (!move_uploaded_file($file['tmp_name'], $pending_path)) {
                        $errors[] = ['name' => $orig_name, 'reason' => 'Failed to move file to pending storage.'];
                        continue;
                    }

                    $candidate_json = empty($candidates) ? null : json_encode(array_slice($candidates, 0, SPU_MAX_CANDIDATE_STORE));

                    $insert_pending_stmt->execute([
                        $batch_token,
                        $orig_name,
                        $stored_name,
                        $file['size'],
                        $extracted_text !== '' ? mb_substr($extracted_text, 0, 60000) : null,
                        $candidate_json,
                        $file_label,
                        $description ?: null,
                        $user['id'],
                    ]);

                    $reason = count($matched_students) > 1
                        ? 'Ambiguous: ' . count($matched_students) . ' student IDs found – please assign manually.'
                        : ($extracted_text === ''
                            ? 'No text extracted from PDF (possibly scanned/handwritten) – please assign manually.'
                            : 'No matching student ID found in PDF text – please assign manually.');

                    $pending_saved[] = [
                        'original_name'    => $orig_name,
                        'reason'           => $reason,
                        'candidates'       => array_slice($candidates, 0, SPU_MAX_CANDIDATE_DISPLAY),
                        'matched_students' => $matched_students,
                    ];
                }
            }

            $results = true;
        }
    }
}

// ── Count all pending (for nav badge) ─────────────────────────────────────────
$pending_count = (int)db()->query(
    "SELECT COUNT(*) FROM student_pdf_pending WHERE status = 'pending'"
)->fetchColumn();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/students/index.php">Students</a></li>
            <li class="breadcrumb-item active">Smart PDF Upload</li>
        </ol>
    </nav>
    <?php if ($pending_count > 0): ?>
    <a href="<?= APP_URL ?>/students/pending-assign.php" class="btn btn-warning btn-sm" style="border-radius:9px;">
        <i class="fas fa-clock me-1"></i>
        <?= $pending_count ?> Pending Assignment<?= $pending_count !== 1 ? 's' : '' ?>
    </a>
    <?php endif; ?>
</div>

<?php if ($results === true): ?>
<!-- ── Results ─────────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#28a745,#1e7e34);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= count($imported) ?></div>
                    <div class="stat-lbl">Auto-Matched</div>
                </div>
                <i class="fas fa-check-circle" style="font-size:2rem;opacity:.4"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#ffc107,#d39e00);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= count($pending_saved) ?></div>
                    <div class="stat-lbl">Needs Manual Assignment</div>
                </div>
                <i class="fas fa-user-edit" style="font-size:2rem;opacity:.4"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#dc3545,#b02a37);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= count($errors) ?></div>
                    <div class="stat-lbl">Errors</div>
                </div>
                <i class="fas fa-times-circle" style="font-size:2rem;opacity:.4"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#6c757d,#495057);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= count($imported) + count($pending_saved) + count($errors) ?></div>
                    <div class="stat-lbl">Total Processed</div>
                </div>
                <i class="fas fa-file-pdf" style="font-size:2rem;opacity:.4"></i>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($imported)): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-success text-white py-2">
        <i class="fas fa-check-circle me-1"></i>
        Auto-Matched &amp; Saved (<?= count($imported) ?>)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Original Filename</th>
                        <th>Student ID Found</th>
                        <th>Student Name</th>
                        <th>Saved As</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($imported as $k => $r): ?>
                    <tr>
                        <td class="text-muted"><?= $k + 1 ?></td>
                        <td class="text-muted small"><?= h($r['original_name']) ?></td>
                        <td><code class="text-success"><?= h($r['student_id']) ?></code></td>
                        <td><?= h($r['student_name']) ?></td>
                        <td class="text-muted small"><?= h($r['stored_as']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($pending_saved)): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-warning text-dark py-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span><i class="fas fa-user-edit me-1"></i> Needs Manual Assignment (<?= count($pending_saved) ?>)</span>
        <a href="<?= APP_URL ?>/students/pending-assign.php" class="btn btn-sm btn-dark" style="border-radius:7px;">
            <i class="fas fa-tasks me-1"></i> Assign Now
        </a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Original Filename</th>
                        <th>Reason</th>
                        <th>IDs Found in Text</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pending_saved as $k => $r): ?>
                    <tr>
                        <td class="text-muted"><?= $k + 1 ?></td>
                        <td><?= h($r['original_name']) ?></td>
                        <td class="text-warning-emphasis small"><?= h($r['reason']) ?></td>
                        <td class="small">
                            <?php if (!empty($r['candidates'])): ?>
                                <?php foreach ($r['candidates'] as $c): ?>
                                    <code><?= h($c) ?></code>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
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

<?php if (!empty($errors)): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-danger text-white py-2">
        <i class="fas fa-times-circle me-1"></i> Errors (<?= count($errors) ?>)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr><th>#</th><th>Filename</th><th>Reason</th></tr>
                </thead>
                <tbody>
                <?php foreach ($errors as $k => $r): ?>
                    <tr>
                        <td class="text-muted"><?= $k + 1 ?></td>
                        <td class="text-muted small"><?= h($r['name']) ?></td>
                        <td class="text-danger"><?= h($r['reason']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="d-flex gap-2 mb-4">
    <a href="<?= APP_URL ?>/students/smart-upload.php" class="btn btn-outline-primary">
        <i class="fas fa-upload me-1"></i> Upload More PDFs
    </a>
    <?php if (!empty($pending_saved)): ?>
    <a href="<?= APP_URL ?>/students/pending-assign.php" class="btn btn-warning">
        <i class="fas fa-tasks me-1"></i> Assign Pending Files
    </a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/students/index.php" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i> Back to Students
    </a>
</div>

<?php else: ?>
<!-- ── Upload form ─────────────────────────────────────────────────────────── -->
<div class="row justify-content-center">
    <div class="col-12 col-lg-8">

        <div class="card shadow-sm mb-4">
            <div class="card-header py-3">
                <h5 class="mb-0">
                    <i class="fas fa-magic me-2 text-primary"></i>Smart PDF Upload
                    <small class="text-muted fw-normal ms-2" style="font-size:.85rem;">
                        — reads PDF content to find Student IDs automatically
                    </small>
                </h5>
            </div>
            <div class="card-body">

                <div class="alert alert-info small" role="alert">
                    <strong><i class="fas fa-info-circle me-1"></i> How it works:</strong>
                    <ol class="mb-0 mt-2 ps-3">
                        <li>Upload one or more PDF files — file names do <strong>not</strong> need to be correct.</li>
                        <li>The system reads each PDF and searches the content for a Student ID.</li>
                        <li>Files where a unique Student ID is found are <strong>automatically saved</strong> and renamed to <code>&lt;StudentID&gt;.pdf</code>.</li>
                        <li>Files where no ID is found (e.g. handwritten / scanned PDFs without OCR text) are placed in the <strong>pending queue</strong> for manual assignment.</li>
                    </ol>
                    <div class="mt-2">
                        <i class="fas fa-lightbulb me-1 text-warning"></i>
                        <strong>Tip:</strong> Printed digital PDFs work best. For handwritten exams, you may need to assign them manually via the
                        <a href="<?= APP_URL ?>/students/pending-assign.php">Pending Assignments</a> page.
                    </div>
                </div>

                <form method="post" enctype="multipart/form-data" id="smart-upload-form">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="file_label">
                            Document Label <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="file_label" name="file_label"
                               value="Student Document" required
                               placeholder="e.g. Admission Form, ID Copy, Exam Script">
                        <div class="form-text">Applied to every imported file as its label in the student record.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="description">
                            Description <span class="text-muted fw-normal">(optional)</span>
                        </label>
                        <input type="text" class="form-control" id="description" name="description"
                               placeholder="e.g. Scanned originals – Summer 2025">
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="pdf_files">
                            PDF Files <span class="text-danger">*</span>
                        </label>
                        <input type="file" class="form-control" id="pdf_files" name="pdf_files[]"
                               accept=".pdf,application/pdf" multiple required>
                        <div class="form-text">
                            Up to <?= SPU_MAX_FILES ?> files at once, max 20 MB each.
                            Mix of printed and handwritten PDFs is supported.
                        </div>
                    </div>

                    <!-- File list preview -->
                    <div id="file-preview" class="mb-3" style="display:none;">
                        <div class="fw-semibold mb-2 small">Selected files:</div>
                        <ul id="file-list" class="list-group list-group-flush small"></ul>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary" id="submit-btn">
                            <i class="fas fa-magic me-1"></i> Process &amp; Upload
                        </button>
                        <a href="<?= APP_URL ?>/students/index.php" class="btn btn-outline-secondary">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($pending_count > 0): ?>
        <div class="alert alert-warning" role="alert">
            <i class="fas fa-clock me-1"></i>
            <strong><?= $pending_count ?></strong> PDF<?= $pending_count !== 1 ? 's are' : ' is' ?> waiting for manual assignment.
            <a href="<?= APP_URL ?>/students/pending-assign.php" class="alert-link">Assign them now →</a>
        </div>
        <?php endif; ?>

    </div>
</div>

<script>
document.getElementById('pdf_files').addEventListener('change', function () {
    const preview = document.getElementById('file-preview');
    const list    = document.getElementById('file-list');
    list.innerHTML = '';
    if (this.files.length === 0) { preview.style.display = 'none'; return; }
    for (const f of this.files) {
        const li = document.createElement('li');
        li.className = 'list-group-item py-1 px-2';
        const size = f.size < 1048576
            ? (f.size / 1024).toFixed(1) + ' KB'
            : (f.size / 1048576).toFixed(1) + ' MB';
        li.innerHTML = '<i class="fas fa-file-pdf text-danger me-2"></i>'
            + '<span>' + f.name + '</span>'
            + '<small class="text-muted ms-2">(' + size + ')</small>';
        list.appendChild(li);
    }
    preview.style.display = 'block';
});

document.getElementById('smart-upload-form').addEventListener('submit', function () {
    const btn = document.getElementById('submit-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Processing…';
});
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
