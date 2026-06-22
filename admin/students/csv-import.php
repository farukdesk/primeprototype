<?php
/**
 * Student Management – Bulk CSV / Excel Import
 *
 * Accepts a CSV (.csv) or Excel (.xlsx / .xls) file with a header row.
 * Tab-delimited and comma-delimited CSV files are both supported (auto-detected).
 *
 * Supported columns (case-insensitive; spaces, hyphens, punctuation stripped;
 * spaces/hyphens converted to underscores before matching):
 *
 * IDENTITY
 *   Student_ID / ID_No              – Student ID (leave blank to auto-generate)
 *   Student_Name / Name             – Full name (required)
 *   Contact_No / Mobile_Number / Mobile – Phone number
 *   Email                           – Email address
 *   Address                         – Present address
 *   Photo_URL / Photo               – External URL for student photo
 *   Gender / Sex                    – Male / Female / Other
 *   Date_of_Birth / DOB             – Date (YYYY-MM-DD, MM/DD/YYYY, DD/MM/YYYY)
 *   Place_of_Birth                  – City/place of birth
 *   Marital_Status                  – e.g. Single, Married
 *   Nationality                     – e.g. Bangladeshi
 *   Religion                        – e.g. Islam
 *   Blood_Group                     – A+, A-, B+, B-, AB+, AB-, O+, O-
 *   NID_Birth_Certificate / NID     – National ID or birth certificate number
 *   Passport_No                     – Passport number
 *
 * ACADEMIC PLACEMENT
 *   Faculty                         – Faculty name (stored as label)
 *   Department                      – Department name or code (required)
 *   Program_Type                    – Informational (Undergraduate, Postgraduate …)
 *   Program                         – Program name (matched against dept programs)
 *   Year                            – Academic/admission year, e.g. 2018
 *   Session                         – Semester label, e.g. "Summer (May-August)"
 *   Batch_Name / Batch              – Batch name or number (e.g. "48" or "48th Batch")
 *
 * FAMILY
 *   Fathers_Name / Father_Name      – Father's name
 *   Mothers_Name / Mother_Name      – Mother's name
 *
 * GUARDIAN
 *   Guardian_Name / Guardian_Profession / Guardian_Address
 *   Guardian_Phone / Guardian_Relationship
 *
 * LOCAL GUARDIAN
 *   Local_Guardian_Name / Local_Guardian_Contact_No
 *   Local_Guardian_Address / Local_Guardian_Email
 *
 * REFERENCE
 *   Reference_Name / Reference_Address / Reference_Contact_No / Reference_Email
 *
 * QUALIFICATIONS & DOCUMENTS
 *   Academic_Qualifications  – JSON array:
 *     [{"exam_name":"…","board":"…","passing_year":"…",
 *       "academic_group":"…","grade":"…","session":"…","cgpa":"…"}, …]
 *   Waiver_Courses           – JSON array of waiver course objects (stored verbatim)
 *   Total_Waiver_Credits     – Numeric total of waived credits
 *   Attached_Certificates_Map – JSON array: [{"exam":"…","filename":"…"}, …]
 *                               Stored for later matching with bulk certificate upload
 *
 * Finance columns (Official_Discount, Package_Amount, etc.) are intentionally ignored.
 * Extra/unknown columns are silently ignored.
 *
 * UPSERT MODE
 * When "Update existing records" is checked, rows whose Student ID already exists
 * in the database will have their NULL/empty fields filled in from the CSV.
 * Existing non-empty values are never overwritten.
 * Academic qualifications are added only when the student currently has none.
 *
 * PREREQUISITE
 * Run admin/students-v5.sql once before using this import for the new columns
 * (marital_status, passport_no, guardian_*, reference_*, local_guardian_*,
 *  waiver_courses, total_waiver_credits, certificate_map).
 */

ini_set('memory_limit', '256M');

require_once __DIR__ . '/../includes/auth.php';
require_access('students', 'can_create');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$page_title = 'Bulk Import (CSV / Excel)';
$user       = auth_user();

// ── Load reference data ───────────────────────────────────────────────────────

$departments  = sm_dept_data();
$all_programs = sm_program_data();
$batches      = sm_batches();
$districts    = sm_bd_districts();
$thanas       = sm_bd_thanas();

$dept_by_name = [];
$dept_by_code = [];
foreach ($departments as $d) {
    $dept_by_name[strtolower(trim($d['name']))] = $d;
    if ($d['code'] !== '') {
        $dept_by_code[strtolower(trim($d['code']))] = $d;
    }
}

$prog_by_name = []; // dept_id => [lower_program_name => row]
foreach ($all_programs as $p) {
    $prog_by_name[(int)$p['dept_id']][strtolower(trim($p['program_name']))] = $p;
}

$batch_by_name = [];
foreach ($batches as $b) {
    $batch_by_name[strtolower(trim($b['name']))] = $b;
}

$district_by_name = [];
foreach ($districts as $d) {
    $district_by_name[strtolower(trim($d['name']))] = $d;
}

$thana_by_did_name = [];
foreach ($thanas as $t) {
    $thana_by_did_name[(int)$t['district_id']][strtolower(trim($t['name']))] = $t;
}

const CI_BLOOD_GROUPS        = ['A+','A-','B+','B-','AB+','AB-','O+','O-'];
const CI_STUDENT_ID_BATCH_SZ = 500;
const CI_PREVIEW_LIMIT       = 50;   // rows shown in preview table

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Normalise a header string to a canonical key.
 * Strips UTF-8 BOM, lowercases, converts spaces/hyphens to underscores,
 * then strips all remaining punctuation (apostrophes, slashes, etc.).
 *
 * Examples:
 *   "Father's Name"          → "fathers_name"
 *   "NID/Birth Certificate"  → "nidbirth_certificate"
 *   "ID_No"                  → "id_no"
 *   "Student ID"             → "student_id"
 *   "Contact No"             → "contact_no"
 */
function ci_norm(string $s): string {
    $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);   // UTF-8 BOM
    $s = strtolower(trim($s));
    $s = preg_replace('/[\s\-]+/', '_', $s);          // spaces/hyphens → _
    $s = preg_replace('/[^a-z0-9_]/', '', $s);        // strip punctuation
    return $s;
}

/**
 * Ordered list of system fields that an uploaded column can be mapped to.
 *
 * Each entry is keyed by the canonical key consumed by ci_validate_row() and
 * carries a human label, a list of normalised header aliases (used to
 * pre-select a column automatically) and whether the field is required.
 */
function ci_system_fields(): array {
    return [
        // ── Identity ──────────────────────────────────────────────
        'student_id'            => ['label' => 'Student ID',            'group' => 'Identity', 'required' => false, 'aliases' => ['student_id', 'id_no', 'idno', 'studentid']],
        'student_name'          => ['label' => 'Name',                  'group' => 'Identity', 'required' => true,  'aliases' => ['student_name', 'name', 'full_name', 'fullname', 'student']],
        'contact_no'            => ['label' => 'Mobile / Contact No',   'group' => 'Identity', 'required' => false, 'aliases' => ['contact_no', 'mobile_number', 'mobile', 'phone', 'phone_no', 'cell']],
        'email'                 => ['label' => 'Email',                 'group' => 'Identity', 'required' => false, 'aliases' => ['email', 'email_address', 'mail']],
        'address'               => ['label' => 'Address',               'group' => 'Identity', 'required' => false, 'aliases' => ['address', 'present_address']],
        'photo_url'             => ['label' => 'Photo URL',             'group' => 'Identity', 'required' => false, 'aliases' => ['photo_url', 'photo', 'image', 'image_url']],
        'gender'                => ['label' => 'Gender',                'group' => 'Identity', 'required' => false, 'aliases' => ['gender', 'sex']],
        'date_of_birth'         => ['label' => 'Date of Birth',         'group' => 'Identity', 'required' => false, 'aliases' => ['date_of_birth', 'dob', 'birth_date', 'birthdate']],
        'place_of_birth'        => ['label' => 'Place of Birth',        'group' => 'Identity', 'required' => false, 'aliases' => ['place_of_birth', 'birth_place']],
        'marital_status'        => ['label' => 'Marital Status',        'group' => 'Identity', 'required' => false, 'aliases' => ['marital_status']],
        'nationality'           => ['label' => 'Nationality',           'group' => 'Identity', 'required' => false, 'aliases' => ['nationality']],
        'religion'              => ['label' => 'Religion',              'group' => 'Identity', 'required' => false, 'aliases' => ['religion']],
        'blood_group'           => ['label' => 'Blood Group',           'group' => 'Identity', 'required' => false, 'aliases' => ['blood_group', 'blood']],
        'nidbirth_certificate'  => ['label' => 'NID / Birth Certificate', 'group' => 'Identity', 'required' => false, 'aliases' => ['nidbirth_certificate', 'nid_birth_certificate', 'nid', 'birth_certificate', 'birth_cert']],
        'passport_no'           => ['label' => 'Passport No',           'group' => 'Identity', 'required' => false, 'aliases' => ['passport_no', 'passport']],

        // ── Academic & location ───────────────────────────────────
        'faculty'               => ['label' => 'Faculty',               'group' => 'Academic & Location', 'required' => false, 'aliases' => ['faculty']],
        'department'            => ['label' => 'Department',            'group' => 'Academic & Location', 'required' => true,  'aliases' => ['department', 'dept']],
        'program'               => ['label' => 'Program',               'group' => 'Academic & Location', 'required' => false, 'aliases' => ['program', 'programme']],
        'year'                  => ['label' => 'Year',                  'group' => 'Academic & Location', 'required' => false, 'aliases' => ['year', 'admission_year', 'academic_year']],
        'session'               => ['label' => 'Session / Semester',    'group' => 'Academic & Location', 'required' => false, 'aliases' => ['session', 'semester']],
        'batch_name'            => ['label' => 'Batch',                 'group' => 'Academic & Location', 'required' => false, 'aliases' => ['batch_name', 'batch']],
        'country'               => ['label' => 'Country',               'group' => 'Academic & Location', 'required' => false, 'aliases' => ['country']],
        'district'              => ['label' => 'District',              'group' => 'Academic & Location', 'required' => false, 'aliases' => ['district']],
        'thana'                 => ['label' => 'Thana / Upazila',       'group' => 'Academic & Location', 'required' => false, 'aliases' => ['thana', 'upazila']],

        // ── Family ────────────────────────────────────────────────
        'fathers_name'          => ['label' => "Father's Name",         'group' => 'Family', 'required' => false, 'aliases' => ['fathers_name', 'father_name', 'father']],
        'mothers_name'          => ['label' => "Mother's Name",         'group' => 'Family', 'required' => false, 'aliases' => ['mothers_name', 'mother_name', 'mother']],

        // ── Guardian ──────────────────────────────────────────────
        'guardian_name'         => ['label' => 'Guardian Name',         'group' => 'Guardian', 'required' => false, 'aliases' => ['guardian_name']],
        'guardian_profession'   => ['label' => 'Guardian Profession',   'group' => 'Guardian', 'required' => false, 'aliases' => ['guardian_profession']],
        'guardian_address'      => ['label' => 'Guardian Address',      'group' => 'Guardian', 'required' => false, 'aliases' => ['guardian_address']],
        'guardian_phone'        => ['label' => 'Guardian Phone',        'group' => 'Guardian', 'required' => false, 'aliases' => ['guardian_phone']],
        'guardian_relationship' => ['label' => 'Guardian Relationship', 'group' => 'Guardian', 'required' => false, 'aliases' => ['guardian_relationship']],

        // ── Reference ─────────────────────────────────────────────
        'reference_name'        => ['label' => 'Reference Name',        'group' => 'Reference', 'required' => false, 'aliases' => ['reference_name']],
        'reference_address'     => ['label' => 'Reference Address',     'group' => 'Reference', 'required' => false, 'aliases' => ['reference_address']],
        'reference_contact_no'  => ['label' => 'Reference Contact No',  'group' => 'Reference', 'required' => false, 'aliases' => ['reference_contact_no', 'reference_contact']],
        'reference_email'       => ['label' => 'Reference Email',       'group' => 'Reference', 'required' => false, 'aliases' => ['reference_email']],

        // ── Local guardian ────────────────────────────────────────
        'local_guardian_name'        => ['label' => 'Local Guardian Name',       'group' => 'Local Guardian', 'required' => false, 'aliases' => ['local_guardian_name']],
        'local_guardian_contact_no'  => ['label' => 'Local Guardian Contact No', 'group' => 'Local Guardian', 'required' => false, 'aliases' => ['local_guardian_contact_no', 'local_guardian_contact']],
        'local_guardian_address'     => ['label' => 'Local Guardian Address',    'group' => 'Local Guardian', 'required' => false, 'aliases' => ['local_guardian_address']],
        'local_guardian_email'       => ['label' => 'Local Guardian Email',      'group' => 'Local Guardian', 'required' => false, 'aliases' => ['local_guardian_email']],

        // ── Qualifications / documents ────────────────────────────
        'academic_qualifications'   => ['label' => 'Academic Qualifications (JSON)', 'group' => 'Qualifications & Documents', 'required' => false, 'aliases' => ['academic_qualifications']],
        'waiver_courses'            => ['label' => 'Waiver Courses (JSON)',          'group' => 'Qualifications & Documents', 'required' => false, 'aliases' => ['waiver_courses']],
        'total_waiver_credits'      => ['label' => 'Total Waiver Credits',          'group' => 'Qualifications & Documents', 'required' => false, 'aliases' => ['total_waiver_credits']],
        'attached_certificates_map' => ['label' => 'Attached Certificates Map (JSON)', 'group' => 'Qualifications & Documents', 'required' => false, 'aliases' => ['attached_certificates_map']],
    ];
}

/**
 * Auto-detect a column index for each system field from the normalised headers.
 * Returns canonical_key => column index (only for fields that matched a column).
 */
function ci_auto_map(array $norm_headers, array $fields): array {
    $map  = [];
    $used = [];
    foreach ($fields as $key => $def) {
        $candidates = array_merge([$key], $def['aliases']);
        foreach ($candidates as $alias) {
            $idx = array_search($alias, $norm_headers, true);
            if ($idx !== false && !isset($used[$idx])) {
                $map[$key]  = (int)$idx;
                $used[$idx] = true;
                break;
            }
        }
    }
    return $map;
}

function ci_resolve_dept(string $input, array $by_name, array $by_code): ?array {
    $key = strtolower(trim($input));
    return $by_name[$key] ?? $by_code[$key] ?? null;
}

function ci_resolve_prog(string $input, int $dept_id, array $prog_by_name): ?array {
    $key = strtolower(trim($input));
    if ($key === '') return null;
    return $prog_by_name[$dept_id][$key] ?? null;
}

/**
 * Resolve a batch record. Accepts both "48th Batch" and the bare number "48".
 */
function ci_resolve_batch(string $input, array $batch_by_name): ?array {
    $key = strtolower(trim($input));
    if ($key === '') return null;
    if (isset($batch_by_name[$key])) return $batch_by_name[$key];
    // Try bare number → "Nth Batch" variant
    if (is_numeric($key)) {
        $n = (int)$key;
        $suffix = ($n % 100 >= 11 && $n % 100 <= 13) ? 'th'
                : match ($n % 10) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' };
        $try = $n . $suffix . ' batch';
        if (isset($batch_by_name[$try])) return $batch_by_name[$try];
    }
    return null;
}

function ci_resolve_district(string $input, array $district_by_name): ?array {
    $key = strtolower(trim($input));
    if ($key === '') return null;
    return $district_by_name[$key] ?? null;
}

function ci_resolve_thana(string $input, int $district_id, array $thana_by_did_name): ?array {
    $key = strtolower(trim($input));
    if ($key === '') return null;
    return $thana_by_did_name[$district_id][$key] ?? null;
}

/**
 * Parse a date string into Y-m-d format.
 * Handles YYYY-MM-DD, MM/DD/YYYY, DD/MM/YYYY, DD-MM-YYYY.
 */
function ci_parse_date(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') return null;
    // ISO
    if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $raw)) {
        $dt = date_create($raw);
        return $dt ? date_format($dt, 'Y-m-d') : null;
    }
    // MM/DD/YYYY (common in exported spreadsheets)
    $dt = DateTime::createFromFormat('m/d/Y', $raw);
    if ($dt && $dt->format('m/d/Y') === $raw) return $dt->format('Y-m-d');
    // DD/MM/YYYY
    $dt = DateTime::createFromFormat('d/m/Y', $raw);
    if ($dt && $dt->format('d/m/Y') === $raw) return $dt->format('Y-m-d');
    // DD-MM-YYYY
    $dt = DateTime::createFromFormat('d-m-Y', $raw);
    if ($dt && $dt->format('d-m-Y') === $raw) return $dt->format('Y-m-d');
    // Last resort
    $ts = strtotime($raw);
    return ($ts !== false && $ts > 0) ? date('Y-m-d', $ts) : null;
}

/**
 * Normalise a Bangladesh phone number.
 * If the number is exactly 10 digits and starts with 1, prepend 0 (e.g. 1712345678 → 01712345678).
 * If it is already 11 digits, return as-is.
 */
function ci_normalize_phone(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return $raw;
    $digits = preg_replace('/\D/', '', $raw);
    if ($digits === '') return $raw;
    if (strlen($digits) === 10 && $digits[0] === '1') {
        return '0' . $digits;
    }
    return $digits;
}

/**
 * Normalise a gender string to Male/Female/Other or null.
 */
function ci_parse_sex(string $raw): ?string {
    return match (strtolower(trim($raw))) {
        'male', 'm'         => 'Male',
        'female', 'f'       => 'Female',
        'other', 'o'        => 'Other',
        default             => null,
    };
}

/**
 * Build an admitted_semester string from Session and Year CSV columns.
 * Session examples: "Summer (May-August)", "Summer", "Spring", "Fall"
 * Year example: "2018"
 */
function ci_build_admitted_semester(string $session_raw, string $year_raw): string {
    if (preg_match('/\b(Summer|Fall|Spring)\b/i', $session_raw, $m)) {
        $season = ucfirst(strtolower($m[1]));
    } elseif (trim($session_raw) !== '') {
        $season = trim($session_raw);
    } else {
        $season = 'Fall';
    }
    $year = trim($year_raw);
    if (!preg_match('/^\d{4}$/', $year)) {
        $year = date('Y');
    }
    return $season . ' ' . $year;
}

/**
 * Parse the Academic_Qualifications JSON column.
 * Input: [{"exam_name":"…","board":"…","passing_year":"…",
 *           "academic_group":"…","grade":"…","session":"…","cgpa":"…"}, …]
 */
function ci_parse_qualifications(string $raw): array {
    $raw = trim($raw);
    if ($raw === '' || $raw === '[]') return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) return [];
    $result = [];
    $sort   = 0;
    foreach ($data as $item) {
        if (!is_array($item)) continue;
        $result[] = [
            'exam_name'            => trim($item['exam_name']      ?? $item['exam']  ?? ''),
            'board_university'     => trim($item['board']          ?? ''),
            'passing_year'         => trim($item['passing_year']   ?? ''),
            'group_name'           => trim($item['academic_group'] ?? $item['group'] ?? ''),
            'division_class_grade' => trim($item['grade']          ?? ''),
            'session'              => trim($item['session']        ?? ''),
            'obtained_marks_gpa'   => trim($item['cgpa']           ?? $item['gpa']   ?? ''),
            'sort_order'           => $sort++,
        ];
    }
    return $result;
}

/**
 * Insert academic qualifications for a student (called for new inserts and
 * for upserts when the student currently has no qualifications).
 */
function ci_insert_qualifications(PDO $pdo, int $student_pk, array $quals): void {
    $stmt = $pdo->prepare(
        'INSERT INTO student_academic_qualifications
           (student_id, exam_name, board_university, passing_year,
            group_name, division_class_grade, session, obtained_marks_gpa, sort_order)
         VALUES (?,?,?,?,?,?,?,?,?)'
    );
    foreach ($quals as $q) {
        $stmt->execute([
            $student_pk,
            $q['exam_name']            ?: null,
            $q['board_university']     ?: null,
            $q['passing_year']         ?: null,
            $q['group_name']           ?: null,
            $q['division_class_grade'] ?: null,
            $q['session']              ?: null,
            $q['obtained_marks_gpa']   ?: null,
            $q['sort_order'],
        ]);
    }
}

/**
 * Read a spreadsheet file (xlsx/xls/csv) and return rows as arrays of strings.
 */
function ci_read_spreadsheet(string $tmp_path, string $extension): array {
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

/**
 * Validate one CSV row (header-mapped associative array).
 *
 * Returns a result array with:
 *   'errors'    – blocking validation errors
 *   'warnings'  – non-blocking issues
 *   'action'    – 'insert' (default; set to 'update' by caller for upsert)
 *   + all validated/parsed field values
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

    // ── Identity ──────────────────────────────────────────────
    $id_raw      = trim($row['student_id']           ?? $row['id_no']              ?? '');
    $name_raw    = trim($row['student_name']         ?? $row['name']               ?? '');
    $sex_raw     = trim($row['gender']               ?? $row['sex']                ?? '');
    $dob_raw     = trim($row['date_of_birth']        ?? $row['dob']                ?? '');
    $pob_raw     = trim($row['place_of_birth']       ?? '');
    $marital_raw = trim($row['marital_status']       ?? '');
    $nat_raw     = trim($row['nationality']          ?? '');
    $rel_raw     = trim($row['religion']             ?? '');
    $blood_raw   = trim($row['blood_group']          ?? '');
    $nid_raw     = trim($row['nidbirth_certificate'] ?? $row['nid']                ?? '');
    $pass_raw    = trim($row['passport_no']          ?? '');
    $photo_raw   = trim($row['photo_url']            ?? $row['photo']              ?? '');
    $addr_raw    = trim($row['address']              ?? '');
    $mob_raw     = ci_normalize_phone((string)($row['contact_no'] ?? $row['mobile_number'] ?? $row['mobile'] ?? ''));
    $email_raw   = trim($row['email']                ?? '');

    // ── Academic placement ────────────────────────────────────
    $faculty_raw = trim($row['faculty']              ?? '');
    $dept_raw    = trim($row['department']           ?? '');
    $prog_raw    = trim($row['program']              ?? '');
    $year_raw    = trim($row['year']                 ?? '');
    $session_raw = trim($row['session']              ?? '');
    $batch_raw   = trim($row['batch_name']           ?? $row['batch']              ?? '');

    // ── Family ────────────────────────────────────────────────
    $father_raw  = trim($row['fathers_name']         ?? $row['father_name']        ?? $row['father'] ?? '');
    $mother_raw  = trim($row['mothers_name']         ?? $row['mother_name']        ?? $row['mother'] ?? '');

    // ── Guardian ──────────────────────────────────────────────
    $gn_raw   = trim($row['guardian_name']           ?? '');
    $gpr_raw  = trim($row['guardian_profession']     ?? '');
    $gad_raw  = trim($row['guardian_address']        ?? '');
    $gph_raw  = trim($row['guardian_phone']          ?? '');
    $grl_raw  = trim($row['guardian_relationship']   ?? '');

    // ── Reference ─────────────────────────────────────────────
    $rn_raw   = trim($row['reference_name']          ?? '');
    $ra_raw   = trim($row['reference_address']       ?? '');
    $rc_raw   = trim($row['reference_contact_no']    ?? $row['reference_contact']  ?? '');
    $re_raw   = trim($row['reference_email']         ?? '');

    // ── Local guardian ────────────────────────────────────────
    $ln_raw   = trim($row['local_guardian_name']     ?? '');
    $lc_raw   = trim($row['local_guardian_contact_no'] ?? $row['local_guardian_contact'] ?? '');
    $la_raw   = trim($row['local_guardian_address']  ?? '');
    $le_raw   = trim($row['local_guardian_email']    ?? '');

    // ── Qualifications / Certs ────────────────────────────────
    $quals_raw  = trim($row['academic_qualifications']     ?? '');
    $wcr_raw    = trim($row['waiver_courses']              ?? '');
    $wcc_raw    = trim($row['total_waiver_credits']        ?? '');
    $cert_raw   = trim($row['attached_certificates_map']   ?? '');

    // ── Location ──────────────────────────────────────────────
    $country_raw  = trim($row['country']   ?? 'Bangladesh');
    $district_raw = trim($row['district']  ?? '');
    $thana_raw    = trim($row['thana']     ?? '');

    // ── Validate: Name ────────────────────────────────────────
    if ($name_raw === '') {
        $errors[] = 'Student Name is required.';
    }

    // ── Validate: Student ID ──────────────────────────────────
    if ($id_raw !== '' && !preg_match('/^[a-zA-Z0-9\-]{1,20}$/', $id_raw)) {
        $errors[] = 'Student ID "' . h($id_raw) . '" is invalid (1–20 alphanumeric/hyphen chars).';
        $id_raw = '';
    }

    // ── Validate: Department (required) ──────────────────────
    $dept = null;
    if ($dept_raw === '') {
        $errors[] = 'Department is required.';
    } else {
        $dept = ci_resolve_dept($dept_raw, $dept_by_name, $dept_by_code);
        if ($dept === null) {
            $errors[] = 'Department "' . h($dept_raw) . '" not found.';
        }
    }

    // ── Resolve: Program ─────────────────────────────────────
    $prog = null;
    if ($dept && $prog_raw !== '') {
        $prog = ci_resolve_prog($prog_raw, (int)$dept['id'], $prog_by_name);
        if ($prog === null) {
            $warnings[] = 'Program "' . h($prog_raw) . '" not found for this department – will be stored as text.';
        }
    }

    // ── Resolve: Batch ────────────────────────────────────────
    $batch = null;
    if ($batch_raw !== '') {
        $batch = ci_resolve_batch($batch_raw, $batch_by_name);
        if ($batch === null) {
            $warnings[] = 'Batch "' . h($batch_raw) . '" not found – will be inserted as a new batch.';
        }
    }

    // ── Resolve: District / Thana ─────────────────────────────
    $district = null;
    if ($district_raw !== '') {
        $district = ci_resolve_district($district_raw, $district_by_name);
        if ($district === null) {
            $warnings[] = 'District "' . h($district_raw) . '" not found – will be inserted as new.';
        }
    }
    $thana = null;
    if ($thana_raw !== '' && $district !== null) {
        $thana = ci_resolve_thana($thana_raw, (int)$district['id'], $thana_by_did_name);
        if ($thana === null) {
            $warnings[] = 'Thana "' . h($thana_raw) . '" not found in district – will be inserted as new.';
        }
    }

    // ── Parse: Date of birth ──────────────────────────────────
    $dob = ci_parse_date($dob_raw);
    if ($dob_raw !== '' && $dob === null) {
        $warnings[] = 'Date of Birth "' . h($dob_raw) . '" could not be parsed – will be left blank.';
    }

    // ── Parse: Sex ────────────────────────────────────────────
    $sex = ci_parse_sex($sex_raw);
    if ($sex_raw !== '' && $sex === null) {
        $warnings[] = 'Gender "' . h($sex_raw) . '" not recognised (Male/Female/Other) – will be left blank.';
    }

    // ── Validate: Email ───────────────────────────────────────
    if ($email_raw !== '' && !filter_var($email_raw, FILTER_VALIDATE_EMAIL)) {
        $warnings[] = 'Email "' . h($email_raw) . '" looks invalid – will be stored as-is.';
    }
    if ($re_raw !== '' && !filter_var($re_raw, FILTER_VALIDATE_EMAIL)) {
        $warnings[] = 'Reference Email "' . h($re_raw) . '" looks invalid – will be stored as-is.';
    }

    // ── Validate: Blood group ─────────────────────────────────
    if ($blood_raw !== '' && !in_array($blood_raw, CI_BLOOD_GROUPS, true)) {
        $warnings[] = 'Blood Group "' . h($blood_raw) . '" not recognised – will be left blank.';
        $blood_raw = '';
    }

    // ── Parse: Admitted semester ──────────────────────────────
    $admitted_semester = ci_build_admitted_semester($session_raw, $year_raw);

    // ── Parse: Academic qualifications JSON ───────────────────
    $qualifications = ci_parse_qualifications($quals_raw);
    if ($quals_raw !== '' && $quals_raw !== '[]' && empty($qualifications)) {
        $warnings[] = 'Academic Qualifications could not be parsed as JSON – will be skipped.';
    }

    // ── Parse: Waiver credits ─────────────────────────────────
    $waiver_credits = null;
    if ($wcc_raw !== '') {
        $wc = filter_var($wcc_raw, FILTER_VALIDATE_FLOAT);
        $waiver_credits = ($wc !== false) ? $wc : null;
    }

    return [
        'errors'             => $errors,
        'warnings'           => $warnings,
        'action'             => 'insert',
        // Identity
        'student_id'         => $id_raw,
        'full_name'          => $name_raw,
        'sex'                => $sex,
        'dob'                => $dob,
        'place_of_birth'     => $pob_raw   ?: null,
        'marital_status'     => $marital_raw ?: null,
        'nationality'        => $nat_raw   ?: null,
        'religion'           => $rel_raw   ?: null,
        'blood_group'        => $blood_raw ?: null,
        'nid'                => $nid_raw   ?: null,
        'passport_no'        => $pass_raw  ?: null,
        'photo'              => $photo_raw ?: null,
        'present_address'    => $addr_raw  ?: null,
        'phone'              => $mob_raw   ?: null,
        'email'              => $email_raw ?: null,
        // Academic
        'faculty_label'      => $faculty_raw ?: null,
        'dept'               => $dept,
        'program'            => $prog,
        'program_raw'        => $prog_raw,
        'year'               => $year_raw  ?: null,
        'admitted_semester'  => $admitted_semester,
        'batch_row'          => $batch,
        'batch_raw'          => $batch_raw,
        // Location
        'country'            => $country_raw ?: 'Bangladesh',
        'district'           => $district,
        'district_raw'       => $district_raw,
        'thana'              => $thana,
        'thana_raw'          => $thana_raw,
        // Family
        'father_name'        => $father_raw ?: null,
        'mother_name'        => $mother_raw ?: null,
        // Guardian
        'guardian_name'       => $gn_raw  ?: null,
        'guardian_profession' => $gpr_raw ?: null,
        'guardian_address'    => $gad_raw ?: null,
        'guardian_phone'      => $gph_raw ?: null,
        'guardian_relationship' => $grl_raw ?: null,
        // Reference
        'reference_name'     => $rn_raw   ?: null,
        'reference_address'  => $ra_raw   ?: null,
        'reference_contact'  => $rc_raw   ?: null,
        'reference_email'    => $re_raw   ?: null,
        // Local guardian
        'local_guardian_name'    => $ln_raw ?: null,
        'local_guardian_contact' => $lc_raw ?: null,
        'local_guardian_address' => $la_raw ?: null,
        'local_guardian_email'   => $le_raw ?: null,
        // Qualifications / documents
        'qualifications'      => $qualifications,
        'waiver_courses'      => $wcr_raw  ?: null,
        'total_waiver_credits' => $waiver_credits,
        'certificate_map'     => $cert_raw ?: null,
    ];
}

// ── State vars ────────────────────────────────────────────────────────────────

$preview_rows = null;
$parse_error  = null;
$import_done  = false;
$import_stats = [];
$step         = 'upload';

// ── STEP 1 – Upload, parse and show field mapping ─────────────────────────────

$sys_fields    = ci_system_fields();
$map_headers   = [];   // raw header labels for the mapping UI
$auto_map      = [];   // canonical_key => column index (pre-selected)
$map_sample    = [];   // first data row, for preview hints in the mapping UI

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'map') {
    csrf_check();

    $upsert_mode  = !empty($_POST['upsert_mode']);
    $allowed_exts = ['csv', 'xlsx', 'xls'];

    if (empty($_FILES['csv_file']['name']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $parse_error = 'Please choose a file to upload.';
    } else {
        $file_ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($file_ext, $allowed_exts, true)) {
            $parse_error = 'Only .csv, .xlsx, and .xls files are accepted.';
        } else {
            $all_rows = [];
            $read_err = null;

            if ($file_ext === 'csv') {
                $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
                if ($handle === false) {
                    $read_err = 'Could not open the uploaded file.';
                } else {
                    // Auto-detect delimiter: tabs vs commas in first line (default to comma)
                    $first_line = fgets($handle);
                    rewind($handle);
                    $delim = ($first_line !== false && $first_line !== ''
                              && substr_count($first_line, "\t") > substr_count($first_line, ','))
                             ? "\t" : ',';
                    while (($raw = fgetcsv($handle, 0, $delim, '"', '\\')) !== false) {
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
                $header_raw = array_shift($all_rows);
                if (empty($header_raw)) {
                    $parse_error = 'The file has no header row.';
                } else {
                    // Drop fully-empty data rows up front.
                    $data_rows = [];
                    foreach ($all_rows as $raw) {
                        if (count(array_filter(array_map('trim', $raw))) === 0) continue;
                        $data_rows[] = array_map('strval', $raw);
                    }

                    if (empty($data_rows)) {
                        $parse_error = 'The file contains no data rows.';
                    } else {
                        $header_norm = array_map('ci_norm', $header_raw);
                        $auto_map    = ci_auto_map($header_norm, $sys_fields);

                        // Persist the raw file content for the mapping → preview step.
                        $_SESSION['csv_import_raw'] = [
                            'header' => $header_raw,
                            'rows'   => $data_rows,
                            'upsert' => $upsert_mode,
                        ];

                        $map_headers = $header_raw;
                        $map_sample  = $data_rows[0];
                        $step        = 'map';
                    }
                }
            }
        }
    }
}

// ── STEP 2 – Apply mapping, validate and preview ──────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preview') {
    csrf_check();

    $raw         = $_SESSION['csv_import_raw'] ?? null;
    $upsert_mode = !empty($raw['upsert']);
    $posted_map  = $_POST['map'] ?? [];

    // Normalise the posted mapping to canonical_key => int column index.
    $field_map = [];
    foreach ($sys_fields as $key => $def) {
        if (isset($posted_map[$key]) && $posted_map[$key] !== '') {
            $field_map[$key] = (int)$posted_map[$key];
        }
    }

    if (!$raw || empty($raw['rows'])) {
        $parse_error = 'Your upload session has expired. Please upload the file again.';
    } elseif (!isset($field_map['student_name']) || !isset($field_map['department'])) {
        $missing = [];
        if (!isset($field_map['student_name'])) $missing[] = 'Name';
        if (!isset($field_map['department']))   $missing[] = 'Department';
        $parse_error = 'Please map the required field(s): ' . implode(', ', $missing) . '.';
        // Re-render the mapping form with what the user already chose.
        $map_headers = $raw['header'];
        $map_sample  = $raw['rows'][0];
        $auto_map    = $field_map;
        $step        = 'map';
    } else {
        $pdo = db();
        if (!$pdo) {
            $parse_error = 'Database connection failed.';
        } else {
            // First pass: build mapped associative rows and collect student IDs.
            $csv_sids  = [];
            $temp_rows = [];
            $row_num   = 1;
            foreach ($raw['rows'] as $data) {
                $row_num++;
                $assoc = [];
                foreach ($field_map as $key => $idx) {
                    $assoc[$key] = $data[$idx] ?? '';
                }
                $temp_rows[] = ['row_num' => $row_num, 'assoc' => $assoc];
                $sid = trim($assoc['student_id'] ?? '');
                if ($sid !== '') $csv_sids[] = $sid;
            }

            // Batch-fetch existing student records by student_id
            $existing = []; // student_id => ['id' => pk, ...]
            if (!empty($csv_sids)) {
                foreach (array_chunk(array_unique($csv_sids), CI_STUDENT_ID_BATCH_SZ) as $chunk) {
                    $ph   = implode(',', array_fill(0, count($chunk), '?'));
                    $stmt = $pdo->prepare("SELECT id, student_id FROM students WHERE student_id IN ($ph)");
                    $stmt->execute($chunk);
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $er) {
                        $existing[$er['student_id']] = $er;
                    }
                }
            }

            // Second pass: validate rows
            $preview_rows = [];
            $seen_ids     = [];
            foreach ($temp_rows as $temp) {
                $validated = ci_validate_row(
                    $temp['assoc'],
                    $dept_by_name, $dept_by_code,
                    $prog_by_name, $batch_by_name,
                    $district_by_name, $thana_by_did_name
                );
                $validated['row_num'] = $temp['row_num'];

                $sid = $validated['student_id'];
                if ($sid !== '') {
                    if (isset($existing[$sid])) {
                        if ($upsert_mode) {
                            $validated['warnings'][] = 'Student ID exists – missing fields will be updated.';
                            $validated['action'] = 'update';
                        } else {
                            $validated['errors'][] = 'Student ID "' . h($sid) . '" already exists (tick "Update existing" to fill missing fields instead).';
                        }
                    }
                    if (isset($seen_ids[$sid])) {
                        $validated['errors'][] = 'Student ID "' . h($sid) . '" appears twice in this file (first at row ' . $seen_ids[$sid] . ').';
                    } else {
                        $seen_ids[$sid] = $temp['row_num'];
                    }
                }

                $preview_rows[] = $validated;
            }

            if (empty($preview_rows)) {
                $parse_error  = 'The file contains no data rows.';
                $preview_rows = null;
            } else {
                $step = 'preview';
                $_SESSION['csv_import_rows']   = $preview_rows;
                $_SESSION['csv_import_upsert'] = $upsert_mode;
            }
        }
    }
}

// ── STEP 3 – Confirm and import ───────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    csrf_check();

    $rows_to_import = $_SESSION['csv_import_rows']   ?? [];
    $upsert_mode    = $_SESSION['csv_import_upsert'] ?? false;
    unset($_SESSION['csv_import_rows'], $_SESSION['csv_import_upsert'], $_SESSION['csv_import_raw']);

    if (empty($rows_to_import)) {
        flash_set('error', 'No import data found. Please re-upload the file.');
        redirect(APP_URL . '/students/csv-import.php');
    }

    $inserted    = 0;
    $updated     = 0;
    $skipped     = 0;
    $row_results = [];

    $pdo = db();

    foreach ($rows_to_import as $r) {
        if (!empty($r['errors'])) {
            $row_results[] = [
                'row_num'    => $r['row_num'],
                'status'     => 'skipped',
                'student_id' => $r['student_id'],
                'full_name'  => $r['full_name'],
                'reason'     => implode('; ', $r['errors']),
            ];
            $skipped++;
            continue;
        }

        $dept      = $r['dept'];
        $prog      = $r['program'];
        $batch     = $r['batch_row'];
        $sid_in    = $r['student_id'];
        $is_upsert = ($r['action'] === 'update') && $upsert_mode;

        // ── Auto-create missing batch ─────────────────────────────────────────
        if ($batch === null && ($r['batch_raw'] ?? '') !== '') {
            $b_chk = $pdo->prepare(
                'SELECT id, name FROM student_batches WHERE LOWER(name) = LOWER(?) LIMIT 1'
            );
            $b_chk->execute([$r['batch_raw']]);
            $b_row = $b_chk->fetch(PDO::FETCH_ASSOC);
            if ($b_row) {
                $batch = $b_row;
            } else {
                try {
                    $pdo->prepare(
                        'INSERT INTO student_batches (name, is_active, sort_order) VALUES (?, 1, 0)'
                    )->execute([$r['batch_raw']]);
                    $batch = ['id' => (int)$pdo->lastInsertId(), 'name' => $r['batch_raw']];
                } catch (PDOException $e) {
                    // Concurrent insert – re-fetch
                    $b_chk->execute([$r['batch_raw']]);
                    $batch = $b_chk->fetch(PDO::FETCH_ASSOC) ?: null;
                }
            }
            $r['batch_row'] = $batch;
        }

        // ── Auto-create missing district ──────────────────────────────────────
        if ($r['district'] === null && ($r['district_raw'] ?? '') !== '') {
            $d_chk = $pdo->prepare(
                'SELECT id, name, division FROM bd_districts WHERE LOWER(name) = LOWER(?) LIMIT 1'
            );
            $d_chk->execute([$r['district_raw']]);
            $d_row = $d_chk->fetch(PDO::FETCH_ASSOC);
            if ($d_row) {
                $r['district'] = $d_row;
            } else {
                try {
                    $pdo->prepare(
                        "INSERT INTO bd_districts (name, division) VALUES (?, '')"
                    )->execute([$r['district_raw']]);
                    $r['district'] = [
                        'id'       => (int)$pdo->lastInsertId(),
                        'name'     => $r['district_raw'],
                        'division' => '',
                    ];
                } catch (PDOException $e) {
                    // Concurrent insert – re-fetch
                    $d_chk->execute([$r['district_raw']]);
                    $r['district'] = $d_chk->fetch(PDO::FETCH_ASSOC) ?: null;
                }
            }
        }

        // ── Auto-create missing thana ─────────────────────────────────────────
        if ($r['thana'] === null && ($r['thana_raw'] ?? '') !== '' && $r['district'] !== null) {
            $t_chk = $pdo->prepare(
                'SELECT id, name FROM bd_thanas WHERE district_id = ? AND LOWER(name) = LOWER(?) LIMIT 1'
            );
            $t_chk->execute([(int)$r['district']['id'], $r['thana_raw']]);
            $t_row = $t_chk->fetch(PDO::FETCH_ASSOC);
            if ($t_row) {
                $r['thana'] = $t_row;
            } else {
                try {
                    $pdo->prepare(
                        'INSERT INTO bd_thanas (district_id, name) VALUES (?, ?)'
                    )->execute([(int)$r['district']['id'], $r['thana_raw']]);
                    $r['thana'] = [
                        'id'   => (int)$pdo->lastInsertId(),
                        'name' => $r['thana_raw'],
                    ];
                } catch (PDOException $e) {
                    // Concurrent insert – re-fetch
                    $t_chk->execute([(int)$r['district']['id'], $r['thana_raw']]);
                    $r['thana'] = $t_chk->fetch(PDO::FETCH_ASSOC) ?: null;
                }
            }
        }

        // Re-check whether the student already exists (session data may be stale)
        $existing_pk = false;
        if ($sid_in !== '') {
            $chk = $pdo->prepare('SELECT id FROM students WHERE student_id = ?');
            $chk->execute([$sid_in]);
            $existing_pk = $chk->fetchColumn();
        }

        if ($existing_pk && !$upsert_mode) {
            $row_results[] = [
                'row_num'    => $r['row_num'],
                'status'     => 'skipped',
                'student_id' => $sid_in,
                'full_name'  => $r['full_name'],
                'reason'     => 'Student ID already exists.',
            ];
            $skipped++;
            continue;
        }

        try {
            if ($existing_pk && $upsert_mode) {
                // ── UPSERT: fill NULL/empty fields only ────────────────────
                $db_pk = (int)$existing_pk;

                // String / text columns: set only when DB value IS NULL or ''
                $str_fields = [
                    'full_name'             => $r['full_name'],
                    'faculty_label'         => $r['faculty_label'],
                    'admitted_semester'     => $r['admitted_semester'],
                    'year'                  => $r['year'],
                    'batch'                 => $batch ? $batch['name'] : ($r['batch_raw'] ?: null),
                    'sex'                   => $r['sex'],
                    'place_of_birth'        => $r['place_of_birth'],
                    'marital_status'        => $r['marital_status'],
                    'nationality'           => $r['nationality'],
                    'religion'              => $r['religion'],
                    'blood_group'           => $r['blood_group'],
                    'nid'                   => $r['nid'],
                    'passport_no'           => $r['passport_no'],
                    'photo'                 => $r['photo'],
                    'present_address'       => $r['present_address'],
                    'phone'                 => $r['phone'],
                    'email'                 => $r['email'],
                    'country'               => $r['country'],
                    'father_name'           => $r['father_name'],
                    'mother_name'           => $r['mother_name'],
                    'guardian_name'         => $r['guardian_name'],
                    'guardian_profession'   => $r['guardian_profession'],
                    'guardian_address'      => $r['guardian_address'],
                    'guardian_phone'        => $r['guardian_phone'],
                    'guardian_relationship' => $r['guardian_relationship'],
                    'reference_name'        => $r['reference_name'],
                    'reference_address'     => $r['reference_address'],
                    'reference_contact'     => $r['reference_contact'],
                    'reference_email'       => $r['reference_email'],
                    'local_guardian_name'   => $r['local_guardian_name'],
                    'local_guardian_contact'=> $r['local_guardian_contact'],
                    'local_guardian_address'=> $r['local_guardian_address'],
                    'local_guardian_email'  => $r['local_guardian_email'],
                    'waiver_courses'        => $r['waiver_courses'],
                    'certificate_map'       => $r['certificate_map'],
                ];
                // Integer FK columns: set only when DB value IS NULL
                $int_fields = [
                    'dept_id'    => $dept   ? (int)$dept['id']    : null,
                    'program_id' => $prog   ? (int)$prog['id']    : null,
                    'batch_id'   => $batch  ? (int)$batch['id']   : null,
                    'district_id'=> $r['district'] ? (int)$r['district']['id'] : null,
                    'thana_id'   => $r['thana']    ? (int)$r['thana']['id']    : null,
                ];
                // Numeric / decimal columns
                $num_fields = [
                    'dob'                 => $r['dob'],
                    'total_waiver_credits'=> $r['total_waiver_credits'],
                ];

                $set_parts = [];
                $params    = [];

                // Allowed column names for dynamic SQL (whitelist prevents accidental injection
                // if the $str_fields/$int_fields/$num_fields arrays are ever modified)
                static $allowed_cols = null;
                if ($allowed_cols === null) {
                    $allowed_cols = array_fill_keys([
                        'full_name','faculty_label','admitted_semester','year','batch','sex',
                        'place_of_birth','marital_status','nationality','religion','blood_group',
                        'nid','passport_no','photo','present_address','phone','email','country',
                        'father_name','mother_name',
                        'guardian_name','guardian_profession','guardian_address',
                        'guardian_phone','guardian_relationship',
                        'reference_name','reference_address','reference_contact','reference_email',
                        'local_guardian_name','local_guardian_contact',
                        'local_guardian_address','local_guardian_email',
                        'waiver_courses','certificate_map',
                        'dept_id','program_id','batch_id','district_id','thana_id',
                        'dob','total_waiver_credits',
                    ], true);
                }

                foreach ($str_fields as $col => $val) {
                    if (!isset($allowed_cols[$col]) || $val === null || $val === '') continue;
                    $set_parts[] = "`$col` = CASE WHEN (`$col` IS NULL OR `$col` = '') THEN ? ELSE `$col` END";
                    $params[]    = $val;
                }
                foreach ($int_fields as $col => $val) {
                    if (!isset($allowed_cols[$col]) || $val === null) continue;
                    $set_parts[] = "`$col` = CASE WHEN `$col` IS NULL THEN ? ELSE `$col` END";
                    $params[]    = $val;
                }
                foreach ($num_fields as $col => $val) {
                    if (!isset($allowed_cols[$col]) || $val === null || $val === '') continue;
                    $set_parts[] = "`$col` = CASE WHEN `$col` IS NULL THEN ? ELSE `$col` END";
                    $params[]    = $val;
                }

                if (!empty($set_parts)) {
                    $params[] = $db_pk;
                    $pdo->prepare('UPDATE students SET ' . implode(', ', $set_parts) . ' WHERE id = ?')
                        ->execute($params);
                }

                // Insert qualifications only when student has none yet
                if (!empty($r['qualifications'])) {
                    $cnt_stmt = $pdo->prepare(
                        'SELECT COUNT(*) FROM student_academic_qualifications WHERE student_id = ?'
                    );
                    $cnt_stmt->execute([$db_pk]);
                    if ((int)$cnt_stmt->fetchColumn() === 0) {
                        ci_insert_qualifications($pdo, $db_pk, $r['qualifications']);
                    }
                }

                log_change('students', 'UPDATE', $db_pk,
                           $r['full_name'] . ' (' . $sid_in . ')',
                           null, null, null, 'Bulk CSV upsert');

                $row_results[] = [
                    'row_num'    => $r['row_num'],
                    'status'     => 'updated',
                    'student_id' => $sid_in,
                    'full_name'  => $r['full_name'],
                    'reason'     => '',
                ];
                $updated++;

            } else {
                // ── INSERT new student ──────────────────────────────────────
                if ($sid_in !== '') {
                    $student_id = $sid_in;
                } else {
                    $student_id = sm_generate_student_id(
                        $r['admitted_semester'],
                        (int)$dept['id'],
                        $prog ? (int)$prog['id'] : 0
                    );
                }

                $pdo->prepare(
                    'INSERT INTO students
                       (student_id, dept_id, program_id, admitted_semester, year,
                        batch, batch_id, full_name, faculty_label,
                        sex, dob, place_of_birth, marital_status,
                        nationality, religion, blood_group, nid, passport_no,
                        photo, present_address, phone, email,
                        country, district_id, thana_id,
                        father_name, mother_name,
                        guardian_name, guardian_profession, guardian_address,
                        guardian_phone, guardian_relationship,
                        reference_name, reference_address,
                        reference_contact, reference_email,
                        local_guardian_name, local_guardian_contact,
                        local_guardian_address, local_guardian_email,
                        waiver_courses, total_waiver_credits, certificate_map,
                        status, created_by)
                     VALUES
                       (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,
                        ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $student_id,
                    (int)$dept['id'],
                    $prog  ? (int)$prog['id']  : null,
                    $r['admitted_semester'],
                    $r['year'],
                    $batch ? $batch['name']     : ($r['batch_raw'] ?: null),
                    $batch ? (int)$batch['id']  : null,
                    $r['full_name'],
                    $r['faculty_label'] ?? $dept['faculty_label'] ?? null,
                    $r['sex'],
                    $r['dob'],
                    $r['place_of_birth'],
                    $r['marital_status'],
                    $r['nationality'],
                    $r['religion'],
                    $r['blood_group'],
                    $r['nid'],
                    $r['passport_no'],
                    $r['photo'],
                    $r['present_address'],
                    $r['phone'],
                    $r['email'],
                    $r['country'],
                    $r['district'] ? (int)$r['district']['id'] : null,
                    $r['thana']    ? (int)$r['thana']['id']    : null,
                    $r['father_name'],
                    $r['mother_name'],
                    $r['guardian_name'],
                    $r['guardian_profession'],
                    $r['guardian_address'],
                    $r['guardian_phone'],
                    $r['guardian_relationship'],
                    $r['reference_name'],
                    $r['reference_address'],
                    $r['reference_contact'],
                    $r['reference_email'],
                    $r['local_guardian_name'],
                    $r['local_guardian_contact'],
                    $r['local_guardian_address'],
                    $r['local_guardian_email'],
                    $r['waiver_courses'],
                    $r['total_waiver_credits'],
                    $r['certificate_map'],
                    'Active',
                    $user['id'],
                ]);

                $new_pk = (int)$pdo->lastInsertId();

                if (!empty($r['qualifications'])) {
                    ci_insert_qualifications($pdo, $new_pk, $r['qualifications']);
                }

                log_change('students', 'CREATE', $new_pk,
                           $r['full_name'] . ' (' . $student_id . ')',
                           null, null, null, 'Bulk CSV import');

                $row_results[] = [
                    'row_num'    => $r['row_num'],
                    'status'     => 'inserted',
                    'student_id' => $student_id,
                    'full_name'  => $r['full_name'],
                    'reason'     => '',
                ];
                $inserted++;
            }
        } catch (PDOException $e) {
            $row_results[] = [
                'row_num'    => $r['row_num'],
                'status'     => 'error',
                'student_id' => $sid_in,
                'full_name'  => $r['full_name'],
                'reason'     => 'DB error: ' . h($e->getMessage()),
            ];
            $skipped++;
        }
    }

    $import_stats = [
        'inserted' => $inserted,
        'updated'  => $updated,
        'skipped'  => $skipped,
        'rows'     => $row_results,
    ];
    $import_done = true;
    $step        = 'done';
}

// ── HTML output ───────────────────────────────────────────────────────────────

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
<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= h($parse_error) ?></div>
<?php endif; ?>

<?php /* ── STEP 1: Upload form ─────────────────────────────────── */ ?>
<?php if ($step === 'upload'): ?>

<div class="card mb-4">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-file-import me-2 text-muted"></i>Upload Student File (CSV, TSV or Excel)</h6>
    </div>
    <div class="card-body">

        <div class="alert alert-info mb-4" style="font-size:.875rem;">
            <strong>Supported columns</strong> <small class="text-muted">(header names are case-insensitive; spaces, hyphens and punctuation are normalised automatically)</small>
            <div class="row mt-2 g-1" style="font-size:.82rem;">
                <div class="col-lg-4">
                    <strong class="d-block mb-1">Identity</strong>
                    <ul class="mb-2 ps-3">
                        <li><code>Student_ID</code> / <code>ID_No</code> – Student ID (blank = auto-generate)</li>
                        <li><code>Name</code> / <code>Student_Name</code> <span class="text-danger">*</span></li>
                        <li><code>Contact_No</code> / <code>Mobile_Number</code></li>
                        <li><code>Email</code></li>
                        <li><code>Address</code> – present address</li>
                        <li><code>Photo_URL</code> – external photo URL</li>
                        <li><code>Gender</code> – Male / Female / Other</li>
                        <li><code>Date_of_Birth</code> – YYYY-MM-DD, MM/DD/YYYY or DD/MM/YYYY</li>
                        <li><code>Place_of_Birth</code>, <code>Marital_Status</code></li>
                        <li><code>Nationality</code>, <code>Religion</code></li>
                        <li><code>Blood_Group</code> – A+, A-, B+, …</li>
                        <li><code>NID_Birth_Certificate</code> / <code>NID</code></li>
                        <li><code>Passport_No</code></li>
                    </ul>
                </div>
                <div class="col-lg-4">
                    <strong class="d-block mb-1">Academic &amp; Location</strong>
                    <ul class="mb-2 ps-3">
                        <li><code>Faculty</code></li>
                        <li><code>Department</code> <span class="text-danger">*</span></li>
                        <li><code>Program_Type</code> (informational)</li>
                        <li><code>Program</code></li>
                        <li><code>Year</code> – e.g. 2018</li>
                        <li><code>Session</code> – e.g. <em>Summer (May-August)</em></li>
                        <li><code>Batch_Name</code> / <code>Batch</code> – name or number</li>
                        <li><code>Country</code>, <code>District</code>, <code>Thana</code></li>
                        <li><code>Fathers_Name</code>, <code>Mothers_Name</code></li>
                    </ul>
                    <strong class="d-block mb-1">Guardian</strong>
                    <ul class="mb-2 ps-3">
                        <li><code>Guardian_Name</code>, <code>Guardian_Profession</code></li>
                        <li><code>Guardian_Address</code>, <code>Guardian_Phone</code></li>
                        <li><code>Guardian_Relationship</code></li>
                    </ul>
                </div>
                <div class="col-lg-4">
                    <strong class="d-block mb-1">Reference &amp; Local Guardian</strong>
                    <ul class="mb-2 ps-3">
                        <li><code>Reference_Name</code>, <code>Reference_Address</code></li>
                        <li><code>Reference_Contact_No</code>, <code>Reference_Email</code></li>
                        <li><code>Local_Guardian_Name</code></li>
                        <li><code>Local_Guardian_Contact_No</code></li>
                        <li><code>Local_Guardian_Address</code>, <code>Local_Guardian_Email</code></li>
                    </ul>
                    <strong class="d-block mb-1">Qualifications &amp; Documents</strong>
                    <ul class="mb-0 ps-3">
                        <li><code>Academic_Qualifications</code> – JSON array</li>
                        <li><code>Waiver_Courses</code> – JSON array (stored verbatim)</li>
                        <li><code>Total_Waiver_Credits</code> – numeric</li>
                        <li><code>Attached_Certificates_Map</code> – JSON, stored for later bulk certificate upload</li>
                    </ul>
                </div>
            </div>
            <div class="mt-2">
                <span class="text-danger">*</span> Required. Finance columns (<em>Official Discount, Package Amount</em>, etc.) are intentionally ignored.
            </div>
        </div>

        <div class="alert alert-warning mb-4" style="font-size:.875rem;">
            <strong>Before first use:</strong> run <code>admin/students-v5.sql</code> once to add the new columns
            (guardian, reference, local guardian, passport, marital status, waiver courses, certificate map).
        </div>

        <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="map">

            <div class="mb-3" style="max-width:480px;">
                <label class="form-label fw-semibold">Select File</label>
                <input type="file" name="csv_file" id="csv_file" class="form-control"
                       accept=".csv,.xlsx,.xls" required>
                <div class="form-text">Accepted: .csv (comma or tab delimited, UTF-8), .xlsx, .xls</div>
            </div>

            <div class="mb-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="upsert_mode" id="upsert_mode" value="1">
                    <label class="form-check-label" for="upsert_mode">
                        <strong>Update existing records</strong>
                        <span class="text-muted" style="font-size:.875rem;">
                            – if a Student ID already exists, fill in any NULL/empty fields from this file
                            (existing data is never overwritten)
                        </span>
                    </label>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="border-radius:8px;">
                <i class="fas fa-arrow-right me-1"></i> Continue to Field Mapping
            </button>
        </form>
    </div>
</div>

<?php /* ── STEP 2: Field mapping ───────────────────────────────── */ ?>
<?php elseif ($step === 'map'): ?>

<?php
// Group system fields for display.
$grouped = [];
foreach ($sys_fields as $key => $def) {
    $grouped[$def['group']][$key] = $def;
}
?>

<div class="alert alert-info mb-3" style="font-size:.875rem;">
    <i class="fas fa-info-circle me-2"></i>
    Match each <strong>system field</strong> to a column from your file. We have pre-selected likely
    matches based on your column names – review them, adjust as needed, then continue to preview.
    Fields marked <span class="text-danger">*</span> are required.
</div>

<form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="preview">

    <div class="d-flex gap-2 mb-3">
        <button type="submit" class="btn btn-primary" style="border-radius:8px;">
            <i class="fas fa-search me-1"></i> Preview Import
        </button>
        <a href="<?= APP_URL ?>/students/csv-import.php" class="btn btn-outline-secondary" style="border-radius:8px;">
            <i class="fas fa-redo me-1"></i> Re-upload
        </a>
    </div>

    <?php foreach ($grouped as $group_name => $fields): ?>
    <div class="card mb-3">
        <div class="card-header py-2 px-4">
            <h6 class="mb-0 fw-semibold"><?= h($group_name) ?></h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <?php foreach ($fields as $key => $def): ?>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label mb-1" style="font-size:.85rem;">
                        <?= h($def['label']) ?>
                        <?php if (!empty($def['required'])): ?><span class="text-danger">*</span><?php endif; ?>
                    </label>
                    <select name="map[<?= h($key) ?>]" class="form-select form-select-sm"
                            <?= !empty($def['required']) ? 'required' : '' ?>>
                        <option value="">— Not mapped —</option>
                        <?php foreach ($map_headers as $i => $hdr):
                            $label = trim((string)$hdr) !== '' ? $hdr : ('Column ' . ($i + 1));
                            $sample = isset($map_sample[$i]) ? trim((string)$map_sample[$i]) : '';
                            if ($sample !== '') {
                                $sample = ' (e.g. ' . mb_strimwidth($sample, 0, 30, '…') . ')';
                            }
                            $selected = (isset($auto_map[$key]) && (int)$auto_map[$key] === (int)$i) ? 'selected' : '';
                        ?>
                        <option value="<?= (int)$i ?>" <?= $selected ?>><?= h($label . $sample) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="d-flex gap-2 mb-4">
        <button type="submit" class="btn btn-primary" style="border-radius:8px;">
            <i class="fas fa-search me-1"></i> Preview Import
        </button>
        <a href="<?= APP_URL ?>/students/csv-import.php" class="btn btn-outline-secondary" style="border-radius:8px;">
            <i class="fas fa-redo me-1"></i> Re-upload
        </a>
    </div>
</form>

<?php /* ── STEP 3: Preview ─────────────────────────────────────── */ ?>
<?php elseif ($step === 'preview' && $preview_rows !== null): ?>

<?php
$valid_count   = 0;
$update_count  = 0;
$invalid_count = 0;
foreach ($preview_rows as $pr) {
    if (!empty($pr['errors'])) { $invalid_count++; }
    elseif ($pr['action'] === 'update') { $update_count++; $valid_count++; }
    else { $valid_count++; }
}
$total_count   = count($preview_rows);
$upsert_active = $_SESSION['csv_import_upsert'] ?? false;
?>

<div class="alert <?= $valid_count > 0 ? 'alert-success' : 'alert-warning' ?> mb-3">
    <strong><?= $total_count ?></strong> data row(s) found:
    <strong><?= $valid_count - $update_count ?></strong> new insert(s),
    <?php if ($upsert_active): ?>
    <strong><?= $update_count ?></strong> update(s),
    <?php endif; ?>
    <strong><?= $invalid_count ?></strong> skipped (errors).
    <?php if ($valid_count === 0): ?> Fix the errors and re-upload.<?php endif; ?>
</div>

<?php if ($valid_count > 0): ?>
<form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="import">
    <div class="d-flex gap-2 mb-3">
        <button type="submit" class="btn btn-success" style="border-radius:8px;"
                onclick="return confirm('Process <?= $valid_count ?> record(s) now?');">
            <i class="fas fa-file-import me-1"></i>
            Confirm &amp; Import <?= $valid_count ?> Record(s)
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
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-table me-2 text-muted"></i>
            Preview (<?= $total_count ?> row<?= $total_count !== 1 ? 's' : '' ?>)
        </h6>
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
                        <th>ID</th>
                        <th>Name</th>
                        <th>Gender</th>
                        <th>DOB</th>
                        <th>Place of Birth</th>
                        <th>Marital Status</th>
                        <th>Nationality</th>
                        <th>Religion</th>
                        <th>Blood Group</th>
                        <th>NID / Birth Cert</th>
                        <th>Passport No</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Address</th>
                        <th>Country</th>
                        <th>District</th>
                        <th>Thana</th>
                        <th>Faculty</th>
                        <th>Dept</th>
                        <th>Program</th>
                        <th>Year</th>
                        <th>Semester</th>
                        <th>Batch</th>
                        <th>Father's Name</th>
                        <th>Mother's Name</th>
                        <th>Guardian Name</th>
                        <th>Guardian Profession</th>
                        <th>Guardian Address</th>
                        <th>Guardian Phone</th>
                        <th>Guardian Relationship</th>
                        <th>Reference Name</th>
                        <th>Reference Address</th>
                        <th>Reference Contact</th>
                        <th>Reference Email</th>
                        <th>Local Guardian Name</th>
                        <th>Local Guardian Contact</th>
                        <th>Local Guardian Address</th>
                        <th>Local Guardian Email</th>
                        <th>Qualifications</th>
                        <th>Waiver Courses</th>
                        <th>Total Waiver Credits</th>
                        <th>Certificate Map</th>
                        <th>Photo URL</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($preview_rows as $i => $r):
                    $has_errors   = !empty($r['errors']);
                    $has_warnings = !empty($r['warnings']);
                    $row_cls = $has_errors ? 'table-danger' : ($has_warnings ? 'table-warning' : '');
                    $dash    = '<span class="text-muted">—</span>';
                    $cell    = fn($v) => $v !== null && $v !== '' ? h($v) : $dash;
                ?>
                <tr class="<?= $row_cls ?>">
                    <td class="px-3"><?= $i + 1 ?></td>
                    <td><?= (int)$r['row_num'] ?></td>
                    <td>
                        <?php if ($r['action'] === 'update'): ?>
                            <span class="badge bg-info text-dark">Update</span>
                        <?php elseif ($has_errors): ?>
                            <span class="badge bg-danger">Skip</span>
                        <?php else: ?>
                            <span class="badge bg-success">Insert</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($has_errors): ?>
                            <span class="text-danger fw-semibold"><i class="fas fa-times-circle me-1"></i>Error</span>
                            <ul class="mb-0 ps-3 mt-1" style="font-size:.75rem;white-space:normal;min-width:200px;">
                                <?php foreach ($r['errors'] as $e): ?><li><?= $e ?></li><?php endforeach; ?>
                            </ul>
                        <?php elseif ($has_warnings): ?>
                            <span class="text-warning fw-semibold"><i class="fas fa-exclamation-triangle me-1"></i>Warning</span>
                            <ul class="mb-0 ps-3 mt-1" style="font-size:.75rem;white-space:normal;min-width:200px;">
                                <?php foreach ($r['warnings'] as $w): ?><li><?= $w ?></li><?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <span class="text-success"><i class="fas fa-check-circle me-1"></i>OK</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($r['student_id'] !== ''): ?>
                            <code style="font-size:.75rem;"><?= h($r['student_id']) ?></code>
                        <?php else: ?>
                            <span class="text-muted fst-italic">auto</span>
                        <?php endif; ?>
                    </td>
                    <td><?= h($r['full_name']) ?></td>
                    <td><?= $cell($r['sex']) ?></td>
                    <td><?= $cell($r['dob']) ?></td>
                    <td><?= $cell($r['place_of_birth']) ?></td>
                    <td><?= $cell($r['marital_status']) ?></td>
                    <td><?= $cell($r['nationality']) ?></td>
                    <td><?= $cell($r['religion']) ?></td>
                    <td><?= $cell($r['blood_group']) ?></td>
                    <td><?= $cell($r['nid']) ?></td>
                    <td><?= $cell($r['passport_no']) ?></td>
                    <td><?= $cell($r['phone']) ?></td>
                    <td><?= $cell($r['email']) ?></td>
                    <td style="white-space:normal;min-width:160px;"><?= $cell($r['present_address']) ?></td>
                    <td><?= $cell($r['country']) ?></td>
                    <td>
                        <?php if ($r['district']): ?>
                            <?= h($r['district']['name'] ?? $r['district_raw']) ?>
                        <?php elseif ($r['district_raw'] !== ''): ?>
                            <span class="text-warning" title="Not matched"><?= h($r['district_raw']) ?></span>
                        <?php else: ?>
                            <?= $dash ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($r['thana']): ?>
                            <?= h($r['thana']['name'] ?? $r['thana_raw']) ?>
                        <?php elseif ($r['thana_raw'] !== ''): ?>
                            <span class="text-warning" title="Not matched"><?= h($r['thana_raw']) ?></span>
                        <?php else: ?>
                            <?= $dash ?>
                        <?php endif; ?>
                    </td>
                    <td><?= $cell($r['faculty_label']) ?></td>
                    <td>
                        <?php if ($r['dept']): ?>
                            <?= h($r['dept']['name']) ?>
                        <?php elseif (isset($r['dept'])): ?>
                            <span class="text-danger">—</span>
                        <?php else: ?>
                            <?= $dash ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($r['program']): ?>
                            <?= h($r['program']['program_name']) ?>
                        <?php elseif ($r['program_raw'] !== ''): ?>
                            <span class="text-warning" title="Not matched"><?= h($r['program_raw']) ?></span>
                        <?php else: ?>
                            <?= $dash ?>
                        <?php endif; ?>
                    </td>
                    <td><?= $cell($r['year']) ?></td>
                    <td><?= $cell($r['admitted_semester']) ?></td>
                    <td>
                        <?php if ($r['batch_row']): ?>
                            <?= h($r['batch_row']['name']) ?>
                        <?php elseif ($r['batch_raw'] !== ''): ?>
                            <span class="text-warning"><?= h($r['batch_raw']) ?></span>
                        <?php else: ?>
                            <?= $dash ?>
                        <?php endif; ?>
                    </td>
                    <td><?= $cell($r['father_name']) ?></td>
                    <td><?= $cell($r['mother_name']) ?></td>
                    <td><?= $cell($r['guardian_name']) ?></td>
                    <td><?= $cell($r['guardian_profession']) ?></td>
                    <td style="white-space:normal;min-width:140px;"><?= $cell($r['guardian_address']) ?></td>
                    <td><?= $cell($r['guardian_phone']) ?></td>
                    <td><?= $cell($r['guardian_relationship']) ?></td>
                    <td><?= $cell($r['reference_name']) ?></td>
                    <td style="white-space:normal;min-width:140px;"><?= $cell($r['reference_address']) ?></td>
                    <td><?= $cell($r['reference_contact']) ?></td>
                    <td><?= $cell($r['reference_email']) ?></td>
                    <td><?= $cell($r['local_guardian_name']) ?></td>
                    <td><?= $cell($r['local_guardian_contact']) ?></td>
                    <td style="white-space:normal;min-width:140px;"><?= $cell($r['local_guardian_address']) ?></td>
                    <td><?= $cell($r['local_guardian_email']) ?></td>
                    <td>
                        <?php if (!empty($r['qualifications'])): ?>
                            <span class="badge bg-secondary"><?= count($r['qualifications']) ?> record(s)</span>
                        <?php else: ?>
                            <?= $dash ?>
                        <?php endif; ?>
                    </td>
                    <td><?= $r['waiver_courses'] !== null && $r['waiver_courses'] !== '' ? '<span class="badge bg-secondary">yes</span>' : $dash ?></td>
                    <td><?= $r['total_waiver_credits'] !== null && $r['total_waiver_credits'] !== '' ? h($r['total_waiver_credits']) : $dash ?></td>
                    <td><?= $r['certificate_map'] !== null && $r['certificate_map'] !== '' ? '<span class="badge bg-secondary">yes</span>' : $dash ?></td>
                    <td>
                        <?php if (!empty($r['photo'])): ?>
                            <a href="<?= h($r['photo']) ?>" target="_blank" style="font-size:.75rem;">link</a>
                        <?php else: ?>
                            <?= $dash ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php /* ── STEP 3: Done ─────────────────────────────────────────── */ ?>
<?php elseif ($step === 'done'): ?>

<div class="alert <?= ($import_stats['inserted'] + $import_stats['updated']) > 0 ? 'alert-success' : 'alert-warning' ?>">
    <?php if ($import_stats['inserted'] > 0): ?>
        <strong><?= $import_stats['inserted'] ?></strong> student(s) imported.
    <?php endif; ?>
    <?php if ($import_stats['updated'] > 0): ?>
        <strong><?= $import_stats['updated'] ?></strong> student record(s) updated (missing fields filled).
    <?php endif; ?>
    <?php if ($import_stats['skipped'] > 0): ?>
        <strong><?= $import_stats['skipped'] ?></strong> row(s) skipped.
    <?php endif; ?>
</div>

<?php if ($import_stats['updated'] > 0): ?>
<div class="alert alert-info mb-3" style="font-size:.875rem;">
    <i class="fas fa-info-circle me-1"></i>
    <strong>Certificate import:</strong> Students whose <code>Attached_Certificates_Map</code> was saved can have
    their certificate files imported later using
    <a href="<?= APP_URL ?>/students/bulk-upload.php">Bulk ZIP Upload</a> – use the CSV mapping option
    to match each PDF/image filename to the correct student.
</div>
<?php endif; ?>

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
                <?php foreach ($import_stats['rows'] as $r):
                    $cls = in_array($r['status'], ['inserted', 'updated'], true) ? '' : 'table-danger';
                ?>
                <tr class="<?= $cls ?>">
                    <td class="px-3"><?= (int)$r['row_num'] ?></td>
                    <td><code><?= h($r['student_id']) ?></code></td>
                    <td><?= h($r['full_name']) ?></td>
                    <td>
                        <?php if ($r['status'] === 'inserted'): ?>
                            <span class="text-success"><i class="fas fa-check-circle me-1"></i>Imported</span>
                        <?php elseif ($r['status'] === 'updated'): ?>
                            <span class="text-info"><i class="fas fa-sync-alt me-1"></i>Updated</span>
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
