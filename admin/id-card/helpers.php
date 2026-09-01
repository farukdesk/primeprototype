<?php
/**
 * ID Card Module – Shared Helpers
 */

require_once __DIR__ . '/../includes/auth.php';

const IDC_TYPES = ['student' => 'Student', 'faculty' => 'Faculty', 'staff' => 'Staff'];

const IDC_BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

// ── Permission helpers ────────────────────────────────────────────────────────

function idc_can_view(): bool   { return is_super_admin() || can_access('id-card'); }
function idc_can_create(): bool { return is_super_admin() || can_access('id-card', 'can_create'); }
function idc_can_edit(): bool   { return is_super_admin() || can_access('id-card', 'can_edit'); }
function idc_can_delete(): bool { return is_super_admin() || can_access('id-card', 'can_delete'); }

// ── Data access ───────────────────────────────────────────────────────────────

function idc_get_card(int $id): array|false
{
    $st = db()->prepare(
        'SELECT c.*, u.full_name AS created_by_name
         FROM idc_cards c
         LEFT JOIN users u ON u.id = c.created_by
         WHERE c.id = ?'
    );
    $st->execute([$id]);
    return $st->fetch();
}

/**
 * Dynamic mode: look up a student by their printed Student ID and
 * return everything needed to prefill an ID card.
 */
function idc_find_student(string $student_id): array|false
{
    $st = db()->prepare(
        'SELECT s.id, s.student_id, s.full_name, s.photo, s.blood_group, s.dob, s.phone,
                s.present_address, s.permanent_address, s.status,
                d.name  AS dept_name,  d.code AS dept_code,
                p.program_name,
                b.name  AS batch_name
           FROM students s
           LEFT JOIN dept_departments        d ON d.id = s.dept_id
           LEFT JOIN dept_academic_programs  p ON p.id = s.program_id
           LEFT JOIN student_batches         b ON b.id = s.batch_id
          WHERE s.student_id = ?
          LIMIT 1'
    );
    $st->execute([trim($student_id)]);
    return $st->fetch();
}

// ── Presentation helpers ─────────────────────────────────────────────────────

/** Absolute URL for a stored photo path ('' when no photo). */
function idc_photo_url(?string $photo): string
{
    $photo = trim((string)$photo);
    if ($photo === '') return '';
    if (preg_match('#^https?://#i', $photo)) return $photo;
    $base = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
    return $base . '/' . ltrim($photo, '/');
}

/**
 * Public URL of the SVG template for a card type + side ('front'|'back').
 * Faculty/Staff templates are picked up automatically once the files
 * Faculty_ID_Front.svg / Staff_ID_Front.svg (and _Back) are added to the
 * "ID Card SVG" folder; until then the Student template is used as fallback.
 */
function idc_template_url(string $type, string $side): string
{
    $base   = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
    $side_l = ($side === 'back') ? 'Back' : 'Front';
    $prefix = IDC_TYPES[$type] ?? 'Student';
    $file   = $prefix . '_ID_' . $side_l . '.svg';

    $dir = dirname(__DIR__, 2) . '/ID Card SVG/';
    if (!is_file($dir . $file)) {
        $file = 'Student_ID_' . $side_l . '.svg';
    }
    return $base . '/' . rawurlencode('ID Card SVG') . '/' . rawurlencode($file);
}

/** dd/mm/YYYY or '' */
function idc_fmt_date(?string $d): string
{
    $d = trim((string)$d);
    if ($d === '' || $d === '0000-00-00') return '';
    $ts = strtotime($d);
    return $ts ? date('d/m/Y', $ts) : '';
}

/** dd-mm-YYYY (matches the SVG design's date format) or '' */
function idc_fmt_date_dash(?string $d): string
{
    $d = trim((string)$d);
    if ($d === '' || $d === '0000-00-00') return '';
    $ts = strtotime($d);
    return $ts ? date('d-m-Y', $ts) : '';
}

/** Escape a string for embedding inside SVG/XML text nodes. */
function idc_xml(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Photo as a data URI for embedding into the SVG <image> element.
 * Resolves the stored path against the site root; falls back to a neutral
 * grey placeholder so the sample person's photo is never shown.
 */
function idc_photo_data_uri(?string $photo): string
{
    $photo = trim((string)$photo);
    if ($photo === '') return '';

    // Remote URL: fetch it (photos may be served from the public site)
    if (preg_match('#^https?://#i', $photo)) {
        $data = @file_get_contents($photo);
        if ($data === false || $data === '') return '';
        $mime = 'image/jpeg';
        if (class_exists('finfo')) {
            $m = (new finfo(FILEINFO_MIME_TYPE))->buffer($data);
            if (is_string($m) && strpos($m, 'image/') === 0) $mime = $m;
        }
        return 'data:' . $mime . ';base64,' . base64_encode($data);
    }

    $rel = ltrim($photo, '/');
    $candidates = [
        dirname(__DIR__, 2) . '/' . $rel,   // site root
        dirname(__DIR__) . '/' . $rel,      // admin/
    ];
    $doc_root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    if ($doc_root !== '') $candidates[] = $doc_root . '/' . $rel;

    foreach ($candidates as $file) {
        if (!is_file($file)) continue;
        $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                 'gif' => 'image/gif', 'webp' => 'image/webp'][$ext] ?? 'image/jpeg';
        $data = @file_get_contents($file);
        if ($data !== false && $data !== '') {
            return 'data:' . $mime . ';base64,' . base64_encode($data);
        }
    }
    return '';
}

/**
 * Code 39 barcode as SVG <rect> elements inside a 1×1 unit box.
 * Meant to be wrapped in a <g transform="matrix(w,0,0,h,x,y)"> so it fills
 * the exact area of the design's placeholder barcode.
 */
function idc_code39_rects(string $code): string
{
    $map = [
        '0' => '000110100', '1' => '100100001', '2' => '001100001', '3' => '101100000',
        '4' => '000110001', '5' => '100110000', '6' => '001110000', '7' => '000100101',
        '8' => '100100100', '9' => '001100100', 'A' => '100001001', 'B' => '001001001',
        'C' => '101001000', 'D' => '000011001', 'E' => '100011000', 'F' => '001011000',
        'G' => '000001101', 'H' => '100001100', 'I' => '001001100', 'J' => '000011100',
        'K' => '100000011', 'L' => '001000011', 'M' => '101000010', 'N' => '000010011',
        'O' => '100010010', 'P' => '001010010', 'Q' => '000000111', 'R' => '100000110',
        'S' => '001000110', 'T' => '000010110', 'U' => '110000001', 'V' => '011000001',
        'W' => '111000000', 'X' => '010010001', 'Y' => '110010000', 'Z' => '011010000',
        '-' => '010000101', '.' => '110000100', ' ' => '011000100', '$' => '010101000',
        '/' => '010100010', '+' => '010001010', '%' => '000101010', '*' => '010010100',
    ];

    $code = strtoupper(preg_replace('/[^0-9A-Za-z \-.$\/+%]/', '', $code));
    if ($code === '') $code = '0';
    $chars = str_split('*' . $code . '*');

    $narrow = 1.0;
    $wide   = 2.5;

    // Build alternating bar/space width sequence
    $seq = []; // [width, is_bar]
    $last = count($chars) - 1;
    foreach ($chars as $i => $ch) {
        $pat = $map[$ch] ?? $map['-'];
        for ($j = 0; $j < 9; $j++) {
            $seq[] = [$pat[$j] === '1' ? $wide : $narrow, $j % 2 === 0];
        }
        if ($i < $last) $seq[] = [$narrow, false]; // inter-character gap
    }

    $total = 0.0;
    foreach ($seq as $s) $total += $s[0];

    $x = 0.0;
    $rects = '';
    foreach ($seq as [$w, $bar]) {
        if ($bar) {
            $rects .= sprintf('<rect x="%.6F" y="0" width="%.6F" height="1" fill="#000000"/>', $x / $total, $w / $total);
        }
        $x += $w;
    }
    return $rects;
}

/**
 * Load the front SVG template and replace ALL sample/default content
 * (photo, name, ID, program line, blood group, dates, barcode) with the
 * generated card data.
 */
function idc_render_front_svg(array $card): string
{
    $dir  = dirname(__DIR__, 2) . '/ID Card SVG/';
    $file = (IDC_TYPES[$card['card_type']] ?? 'Student') . '_ID_Front.svg';
    if (!is_file($dir . $file)) $file = 'Student_ID_Front.svg';
    $svg = @file_get_contents($dir . $file);
    if ($svg === false) return '';

    $name = trim((string)$card['full_name']);
    $idno = trim((string)$card['id_number']);
    $bg   = trim((string)$card['blood_group']);

    // Program / designation line (e.g. "BSc in CSE, 67th Batch").
    // Program names are shortened for the card (e.g. "BSc in Computer
    // Science & Engineering (CSE)" -> "BSc in CSE") so they fit the design;
    // when even the short name is too long the font auto-shrinks (step 4).
    if ($card['card_type'] === 'student') {
        $line = idc_short_program_name(trim((string)$card['program_name']));
        $batch = trim((string)$card['batch_name']);
        if ($batch !== '') {
            if (stripos($batch, 'batch') === false) $batch .= ' Batch';
            $line .= ($line !== '' ? ', ' : '') . $batch;
        }
    } else {
        $line = trim((string)$card['designation']);
        $dept = trim((string)$card['dept_name']);
        if ($dept !== '') $line .= ($line !== '' ? ', ' : '') . $dept;
    }

    $issue  = idc_fmt_date_dash($card['issue_date']);
    $expiry = idc_fmt_date_dash($card['expiry_date']);

    // ── 1. Sample photo (the <image> masked by #mask487) ────────────────────
    // The sample portrait is the large embedded 495x600 PNG. It is identified
    // by its unique base64 header, so the university logo (a different,
    // masked image) and all other design artwork are left untouched.
    $photo_uri = idc_photo_data_uri($card['photo']);
    $svg = preg_replace_callback(
        '/<image\b[^>]*?xlink:href="data:image\/png;base64,iVBORw0KGgoAAAANSUhEUgAAAe8AAAJY[^"]*"[^>]*?\/>/s',
        static function ($m) use ($photo_uri) {
            if ($photo_uri !== '') {
                // Swap only the image data; keep the design's position, mask and clip.
                return preg_replace('/xlink:href="[^"]*"/s', 'xlink:href="' . $photo_uri . '"', $m[0], 1);
            }
            // No photo on record -> neutral "No Image Found" box in the same place
            if (preg_match('/transform="matrix\(([-0-9.]+),[-0-9.]+,[-0-9.]+,([-0-9.]+),([-0-9.]+),([-0-9.]+)\)"/', $m[0], $t)) {
                $w = (float)$t[1]; $h = (float)$t[2]; $x = (float)$t[3]; $y = (float)$t[4];
                $cx = $x + $w / 2; $cy = $y + $h / 2;
                return '<g>'
                     . '<rect x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $h . '" fill="#e4e4e4" stroke="#bbbbbb" stroke-width="0.5"/>'
                     . '<text x="' . $cx . '" y="' . ($cy - 2) . '" text-anchor="middle" font-family="Arial" font-size="8" fill="#888888">No Image</text>'
                     . '<text x="' . $cx . '" y="' . ($cy + 8) . '" text-anchor="middle" font-family="Arial" font-size="8" fill="#888888">Found</text>'
                     . '</g>';
            }
            return $m[0];
        },
        $svg, 1
    );

    // ── 2. Sample name (auto-shrink for long names) ─────────────────────────
    if (mb_strlen($name) > 20) {
        $newSize = max(7.5, 12.8994 * 20 / mb_strlen($name));
        $svg = str_replace('font-size:12.8994px', 'font-size:' . round($newSize, 2) . 'px', $svg);
    }
    $svg = str_replace('>Md Mohiuddin Gazi<', '>' . idc_xml($name) . '<', $svg);

    // ── 3. Sample ID number ─────────────────────────────────────────────────
    $svg = str_replace('>02825205101167<', '>' . idc_xml($idno) . '<', $svg);

    // ── 4. Sample program line ("BSc in CSE, 67" + superscript "th " + "Batch") ─
    // Auto-shrink: when even the shortened program line is too long for the
    // design, scale down the font of the program text block so it still fits
    // (never below 55% so it stays readable).
    if (mb_strlen($line) > 22) {
        $scale = max(0.55, 22 / mb_strlen($line));
        $svg = preg_replace_callback(
            '/<text\b[^>]*>(?:(?!<\/text>).)*?BSc in CSE, 67(?:(?!<\/text>).)*?<\/text>/s',
            static function ($m) use ($scale) {
                return preg_replace_callback(
                    '/font-size:([0-9.]+)px/',
                    static function ($f) use ($scale) {
                        return 'font-size:' . round((float)$f[1] * $scale, 2) . 'px';
                    },
                    $m[0]
                );
            },
            $svg, 1
        );
    }
    $svg = str_replace('>BSc in CSE, 67<', '>' . idc_xml($line) . '<', $svg);
    $svg = str_replace('>th <', '><', $svg);
    $svg = str_replace('>Batch<', '><', $svg);

    // ── 5. Sample blood group ───────────────────────────────────────────────
    $bg_text = $bg !== '' ? 'Blood Group : ' . $bg : '';
    $svg = str_replace('>Blood Group : B+ve<', '>' . idc_xml($bg_text) . '<', $svg);

    // ── 6. Sample dates ─────────────────────────────────────────────────────
    $svg = preg_replace('/>Date of Issue\s*: 01-07-2025</',
        '>' . idc_xml($issue !== '' ? 'Date of Issue   : ' . $issue : '') . '<', $svg, 1);
    $svg = preg_replace('/>Date of Expiry\s*: 31-07-2029</',
        '>' . idc_xml($expiry !== '' ? 'Date of Expiry: ' . $expiry : '') . '<', $svg, 1);

    // ── 7. Barcode: replace the placeholder image with a real Code 39 barcode
    //      that encodes the ID number, in the exact same position/size. ───────
    $svg = preg_replace_callback(
        '/<image\b[^>]*?id="image495"[^>]*?\/>/s',
        static function ($m) use ($idno) {
            // Reuse the placeholder's transform so position/size match the design
            $transform = 'matrix(143.05477,0,0,15.124583,118.25223,185.38558)';
            if (preg_match('/transform="([^"]+)"/', $m[0], $t)) $transform = $t[1];
            return '<g transform="' . $transform . '">' . idc_code39_rects($idno) . '</g>';
        },
        $svg, 1
    );

    return $svg;
}

// ── Program short names ──────────────────────────────────────────────────────

/**
 * Short display name for a program on the ID card.
 *
 * Uses the official mapping first (normalised: case, spacing and dashes are
 * ignored). Unknown programs fall back to light generic shortening
 * (BSc/MSc prefixes, then the parenthesised acronym when still too long).
 */
function idc_short_program_name(string $program): string
{
    $norm = static function (string $s): string {
        $s = mb_strtolower(trim($s));
        $s = str_replace(['\u2013', '\u2014'], '-', $s);
        $s = preg_replace('/\s*-\s*/', '-', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return $s;
    };

    static $map = null;
    if ($map === null) {
        $pairs = [
            'BSc in Computer Science & Engineering (CSE)'                 => 'BSc in CSE',
            'BSc in Computer Science and Engineering (CSE)'               => 'BSc in CSE',
            'BSc in Electrical and Electronic Engineering'                => 'BSc in EEE',
            'BSc in Electrical & Electronic Engineering'                  => 'BSc in EEE',
            'Bachelor of Science in Civil Engineering (CE)'               => 'BSc in CE',
            'B.Sc in Fashion Design and Apparel Engineering'              => 'BSc in FDAE',
            'Bachelor of Laws (LL.B. Hons.)'                              => 'LL.B. Hons.',
            'Master of Laws (LLM)- 1 Year'                                => 'LLM Regular',
            'Master of Laws (LLM) Preli & Final- 2 Years'                 => 'LLM Preli & Final',
            'Bachelor of Business Administration (BBA)- 4 Years'          => 'BBA',
            'Masters of Business Administration (MBA)'                    => 'MBA (1 Year)',
            'Masters of Business Administration (MBA)- 1 Year'            => 'MBA (1 Year)',
            'Executive Master of Business Administration (EMBA)-1.5 Years'=> 'EMBA (1.5 Years)',
            'Masters of Business Administration (MBA)- 2 Years'           => 'MBA (2 Years)',
            'Bachelor of Arts in Bangla'                                  => 'B.A. (Hons.) in Bangla',
            'Master of Arts in Bangla (MA)- 1 Year'                       => 'M.A. in Bangla (1 Year)',
            'Master of Arts in Bangla (MA)- 2 Years'                      => 'M.A. in Bangla (2 Years)',
            'Bachelor of Arts in English'                                 => 'B.A. (Hons.) in English',
            'Master of Arts in English (1 Year)'                          => 'M.A. in English (1 Year)',
            'Master of Arts in English (2 Years)'                         => 'M.A. in English (2 Years)',
            'Bachelor of Education (B.Ed)- 1 Year'                        => 'B.Ed',
            'Master of Education (M.Ed)-1 Year'                           => 'M.Ed',
        ];
        $map = [];
        foreach ($pairs as $k => $v) {
            $map[$norm($k)] = $v;
        }
    }

    $p = trim($program);
    if ($p === '') return '';

    $key = $norm($p);
    if (isset($map[$key])) {
        return $map[$key];
    }

    // Generic fallbacks for programs not in the official map
    $short = preg_replace('/^bachelor of science in\s+/i', 'BSc in ', $p);
    $short = preg_replace('/^b\.?\s?sc\.?\s+in\s+/i', 'BSc in ', $short);
    $short = preg_replace('/^master of science in\s+/i', 'MSc in ', $short);
    if (mb_strlen($short) > 26 && preg_match('/\(([A-Za-z.&\s]{2,14})\)/', $short, $m)) {
        $short = trim($m[1]);
    }
    return $short;
}

// ── Photo upload ────────────────────────────────────────────────────────────

/**
 * Validate and store an uploaded ID-card photo.
 * Returns the stored relative path (e.g. 'uploads/id-cards/abc.jpg'),
 * or null when no file was supplied. Throws RuntimeException on invalid uploads.
 */
function idc_store_photo(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Photo upload failed (error ' . (int)$file['error'] . ').');
    }
    if (($file['size'] ?? 0) > 3 * 1024 * 1024) {
        throw new RuntimeException('Photo must be 3 MB or smaller.');
    }
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        throw new RuntimeException('Photo must be a JPG, PNG or WEBP image.');
    }
    if (class_exists('finfo')) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!is_string($mime) || strpos($mime, 'image/') !== 0) {
            throw new RuntimeException('Uploaded file is not a valid image.');
        }
    }
    $dir = UPLOAD_DIR . '/id-cards';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the ID card photo directory.');
    }
    $name = bin2hex(random_bytes(10)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('Could not save the uploaded photo.');
    }
    return 'uploads/id-cards/' . $name;
}
