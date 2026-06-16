<?php
/**
 * Student List Verification Tool
 *
 * Compares a filtered set of students in the database against an uploaded CSV
 * list of "genuine" student IDs. Identifies:
 *   - Extra students in the system not present in the CSV
 *   - Students in the CSV missing from the system (under the chosen filter)
 *   - Records that exist in both but have differing field values
 *
 * Supports a department-name mapping step when the CSV uses different dept
 * names from the system, and allows bulk-updating mismatched fields.
 *
 * CSV columns recognised (case-insensitive, spaces/hyphens to underscores):
 *   Student_ID / ID_No  – student ID (required)
 *   Name / Student_Name – full name
 *   Department / Dept   – department name (mapped to system dept)
 *   Program             – program name
 *   Batch / Batch_Name  – batch name or number
 *   Status              – Active / Inactive / Graduated / Dropped / Not Admitted Yet
 *   Semester / Session  – admitted semester (e.g. "Fall 2024")
 *   Year                – admission year (used together with Session)
 *   Phone / Contact_No  – phone number
 *   Email               – email address
 *   Sex / Gender        – Male / Female / Other
 */

ini_set('memory_limit', '256M');

require_once __DIR__ . '/../includes/auth.php';
require_access('students');
require_once __DIR__ . '/helpers.php';

$page_title = 'Verify Student List';
$user       = auth_user();

// ── Reference data ────────────────────────────────────────────────────────────

$departments  = sm_dept_data();
$all_programs = sm_program_data();
$batches      = sm_batches();

$dept_by_id   = array_column($departments, null, 'id');
$dept_by_name = [];
$dept_by_code = [];
foreach ($departments as $d) {
    $dept_by_name[strtolower(trim($d['name']))] = $d;
    if ($d['code'] !== '') {
        $dept_by_code[strtolower(trim($d['code']))] = $d;
    }
}

$prog_by_id   = array_column($all_programs, null, 'id');
$prog_by_name = []; // dept_id => [lower_name => row]
foreach ($all_programs as $p) {
    $prog_by_name[(int)$p['dept_id']][strtolower(trim($p['program_name']))] = $p;
}

$batch_by_id   = array_column($batches, null, 'id');
$batch_by_name = [];
foreach ($batches as $b) {
    $batch_by_name[strtolower(trim($b['name']))] = $b;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Normalise a CSV header to a lowercase_underscore key. */
function vl_norm(string $s): string {
    $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
    $s = strtolower(trim($s));
    $s = preg_replace('/[\s\-]+/', '_', $s);
    $s = preg_replace('/[^a-z0-9_]/', '', $s);
    return $s;
}

/** Resolve the student-ID field from a normalised row. */
function vl_get_id(array $row): string {
    $val = $row['student_id'] ?? $row['id_no'] ?? $row['id'] ?? $row['studentid'] ?? '';
    return strtoupper(trim((string)$val));
}

/** Resolve the name field from a normalised row. */
function vl_get_name(array $row): string {
    return trim($row['student_name'] ?? $row['name'] ?? $row['full_name'] ?? '');
}

/** Resolve department raw value from a normalised row. */
function vl_get_dept(array $row): string {
    return trim($row['department'] ?? $row['dept'] ?? '');
}

/** Resolve program raw value from a normalised row. */
function vl_get_prog(array $row): string {
    return trim($row['program'] ?? $row['program_name'] ?? '');
}

/** Resolve batch raw value from a normalised row. */
function vl_get_batch(array $row): string {
    return trim($row['batch_name'] ?? $row['batch'] ?? '');
}

/** Resolve status from a normalised row. */
function vl_get_status(array $row): string {
    return trim($row['status'] ?? '');
}

/** Resolve admitted_semester from a normalised row. */
function vl_get_semester(array $row): string {
    $s = trim($row['semester'] ?? $row['admitted_semester'] ?? '');
    if ($s !== '') return $s;
    // Build from session + year
    $session = trim($row['session'] ?? '');
    $year    = trim($row['year']    ?? '');
    if ($session !== '' && $year !== '') {
        if (preg_match('/\b(Summer|Fall|Spring)\b/i', $session, $m)) {
            return ucfirst(strtolower($m[1])) . ' ' . $year;
        }
        return $session . ' ' . $year;
    }
    return '';
}

/** Resolve phone from a normalised row. */
function vl_get_phone(array $row): string {
    return trim($row['phone'] ?? $row['contact_no'] ?? $row['mobile_number'] ?? $row['mobile'] ?? '');
}

/** Resolve email from a normalised row. */
function vl_get_email(array $row): string {
    return trim($row['email'] ?? '');
}

/** Resolve sex from normalised row. */
function vl_get_sex(array $row): string {
    $v = strtolower(trim($row['gender'] ?? $row['sex'] ?? ''));
    return match ($v) {
        'male', 'm'   => 'Male',
        'female', 'f' => 'Female',
        'other', 'o'  => 'Other',
        default       => '',
    };
}

/**
 * Resolve a batch from CSV raw value.
 * Accepts "48", "48th Batch", "48th batch" etc.
 */
function vl_resolve_batch(string $raw, array $batch_by_name): ?array {
    $key = strtolower(trim($raw));
    if ($key === '') return null;
    if (isset($batch_by_name[$key])) return $batch_by_name[$key];
    if (is_numeric($key)) {
        $n = (int)$key;
        $sfx = ($n % 100 >= 11 && $n % 100 <= 13) ? 'th'
             : match ($n % 10) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' };
        $try = $n . $sfx . ' batch';
        if (isset($batch_by_name[$try])) return $batch_by_name[$try];
    }
    return null;
}

/**
 * Parse rows from a CSV string or file pointer.
 * Returns [header => [normalised_key,...], rows => [[normalised_key => value,...], ...]]
 */
function vl_parse_csv(string $content): array {
    $content = ltrim($content, "\xEF\xBB\xBF"); // strip UTF-8 BOM
    $lines   = preg_split('/\r\n|\r|\n/', $content);
    if (empty($lines)) return ['headers' => [], 'rows' => []];

    // Auto-detect delimiter (tab vs comma vs semicolon)
    $sample   = implode("\n", array_slice($lines, 0, 5));
    $tab_cnt  = substr_count($sample, "\t");
    $sem_cnt  = substr_count($sample, ";");
    $com_cnt  = substr_count($sample, ",");
    $delim    = ($tab_cnt > $sem_cnt && $tab_cnt > $com_cnt) ? "\t"
              : ($sem_cnt > $com_cnt ? ";" : ",");

    $headers = [];
    $rows    = [];

    foreach ($lines as $i => $line) {
        $line = rtrim($line);
        if ($line === '') continue;
        $cells = str_getcsv($line, $delim);
        if ($i === 0 || empty($headers)) {
            // Treat first non-empty line as header
            foreach ($cells as $c) {
                $headers[] = vl_norm($c);
            }
            continue;
        }
        // Pad or trim cells to match header count
        while (count($cells) < count($headers)) $cells[] = '';
        $cells = array_slice($cells, 0, count($headers));
        $rows[] = array_combine($headers, $cells);
    }

    return ['headers' => $headers, 'rows' => $rows];
}

// ── Determine current step ────────────────────────────────────────────────────

$step   = $_POST['_step'] ?? 'upload';
$errors = [];
$info   = [];

// ── Session key for parsed CSV cache ─────────────────────────────────────────
const VL_SESSION_KEY = '_vl_csv_cache';

// ── Build DB filter from POST or GET ─────────────────────────────────────────
// Filter params may come from GET (initial load) or POST (subsequent steps)
$src        = ($_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : $_GET;
$f_dept     = (int)($src['dept']     ?? 0);
$f_program  = (int)($src['program']  ?? 0);
$f_batch    = (int)($src['batch']    ?? 0);
$f_status   = trim($src['status']    ?? '');
$f_sem      = trim($src['semester']  ?? '');
$f_sem_type = trim($src['sem_type']  ?? '');
$f_search   = trim($src['search']    ?? '');

$valid_statuses  = ['Active', 'Inactive', 'Graduated', 'Dropped', 'Not Admitted Yet'];
$valid_sem_types = ['bi_semester', 'trimester'];

// ── Step: parse ───────────────────────────────────────────────────────────────
// User submitted the file upload form

$csv_rows    = [];   // normalised-key rows from CSV
$csv_headers = [];
$dept_scope  = get_dept_scope();

if ($step === 'parse') {
    csrf_check();

    if (empty($_FILES['csv_file']['tmp_name'])) {
        $errors[] = 'Please select a CSV file to upload.';
        $step = 'upload';
    } else {
        $content = file_get_contents($_FILES['csv_file']['tmp_name']);
        if ($content === false || $content === '') {
            $errors[] = 'Could not read the uploaded file.';
            $step = 'upload';
        } else {
            $parsed      = vl_parse_csv($content);
            $csv_headers = $parsed['headers'];
            $csv_rows    = $parsed['rows'];

            if (empty($csv_rows)) {
                $errors[] = 'No data rows found in the CSV file.';
                $step = 'upload';
            } else {
                // Check that at least one ID-like column exists
                $has_id_col = false;
                foreach ($csv_headers as $h) {
                    if (in_array($h, ['student_id','id_no','id','studentid'], true)) {
                        $has_id_col = true;
                        break;
                    }
                }
                if (!$has_id_col) {
                    $errors[] = 'No Student ID column found. Expected a column named "Student_ID", "ID_No", or "ID".';
                    $step = 'upload';
                } else {
                    // Cache parsed rows in session for next step
                    $_SESSION[VL_SESSION_KEY] = [
                        'headers' => $csv_headers,
                        'rows'    => $csv_rows,
                    ];
                    $step = 'map';
                }
            }
        }
    }
}

// ── Step: verify ─────────────────────────────────────────────────────────────

$results = null;  // populated in verify step

if ($step === 'verify') {
    csrf_check();

    // Load cached CSV
    $cache = $_SESSION[VL_SESSION_KEY] ?? null;
    if (!$cache) {
        $errors[] = 'Session expired. Please re-upload the CSV file.';
        $step = 'upload';
    } else {
        $csv_headers = $cache['headers'];
        $csv_rows    = $cache['rows'];

        // ── Apply dept mapping ────────────────────────────────────────────
        // $_POST['dept_map'][csv_dept_lower] = system_dept_id
        $dept_map_post = $_POST['dept_map'] ?? [];

        // Build a lookup: original CSV dept string (lowercase) → system dept id
        $csv_dept_resolved = [];
        foreach ($dept_map_post as $csv_key => $sys_id) {
            $sys_id = (int)$sys_id;
            $csv_dept_resolved[$csv_key] = ($sys_id > 0 && isset($dept_by_id[$sys_id]))
                                           ? $dept_by_id[$sys_id]
                                           : null;
        }

        // ── Query all matching students from DB (no pagination) ───────────
        $where  = [];
        $params = [];

        if ($f_search !== '') {
            $like     = '%' . $f_search . '%';
            $where[]  = '(s.student_id LIKE ? OR s.full_name LIKE ? OR s.email LIKE ? OR s.phone LIKE ?)';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }
        if ($f_dept > 0)    { $where[] = 's.dept_id = ?';          $params[] = $f_dept; }
        if ($f_program > 0) { $where[] = 's.program_id = ?';       $params[] = $f_program; }
        if ($f_batch > 0)   { $where[] = 's.batch_id = ?';         $params[] = $f_batch; }
        if (in_array($f_status, $valid_statuses, true)) {
            $where[] = 's.status = ?'; $params[] = $f_status;
        }
        if ($f_sem !== '') {
            $where[] = 's.admitted_semester = ?'; $params[] = $f_sem;
        }
        if (in_array($f_sem_type, $valid_sem_types, true)) {
            $where[] = 's.semester_type = ?'; $params[] = $f_sem_type;
        }

        if ($dept_scope !== null) {
            if (empty($dept_scope)) {
                $where[] = '0 = 1';
            } else {
                $phs = implode(',', array_fill(0, count($dept_scope), '?'));
                $where[] = "s.dept_id IN ($phs)";
                array_push($params, ...$dept_scope);
            }
        }

        $where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $stmt = db()->prepare(
            'SELECT s.id, s.student_id, s.full_name, s.dept_id, s.program_id,
                    s.batch_id, s.status, s.admitted_semester, s.semester_type,
                    s.phone, s.email, s.sex,
                    d.name AS dept_name,
                    p.program_name,
                    b.name AS batch_name
             FROM students s
             JOIN dept_departments d ON d.id = s.dept_id
             LEFT JOIN dept_academic_programs p ON p.id = s.program_id
             LEFT JOIN student_batches b ON b.id = s.batch_id'
            . $where_sql
            . ' ORDER BY s.student_id ASC'
        );
        $stmt->execute($params);
        $db_students_raw = $stmt->fetchAll();

        // Index by normalised student_id (uppercase, trimmed)
        $db_by_id = [];
        foreach ($db_students_raw as $s) {
            $key = strtoupper(trim($s['student_id']));
            $db_by_id[$key] = $s;
        }

        // ── Build CSV index ───────────────────────────────────────────────
        $csv_by_id = [];
        foreach ($csv_rows as $row) {
            $sid = vl_get_id($row);
            if ($sid === '') continue;
            $csv_by_id[$sid] = $row;
        }

        $all_db_ids  = array_keys($db_by_id);
        $all_csv_ids = array_keys($csv_by_id);

        // ── Compare ───────────────────────────────────────────────────────
        $extra_in_system   = []; // in DB, not in CSV
        $missing_in_system = []; // in CSV, not in DB
        $matched_ok        = []; // in both, no relevant differences
        $field_mismatches  = []; // in both, but some fields differ

        foreach ($all_db_ids as $sid) {
            if (!isset($csv_by_id[$sid])) {
                $extra_in_system[$sid] = $db_by_id[$sid];
            }
        }

        foreach ($all_csv_ids as $sid) {
            if (!isset($db_by_id[$sid])) {
                $missing_in_system[$sid] = $csv_by_id[$sid];
            }
        }

        // For students in both: check field mismatches
        $has_name_col     = in_array('student_name', $csv_headers, true) || in_array('name', $csv_headers, true) || in_array('full_name', $csv_headers, true);
        $has_dept_col     = in_array('department', $csv_headers, true)   || in_array('dept', $csv_headers, true);
        $has_prog_col     = in_array('program', $csv_headers, true)      || in_array('program_name', $csv_headers, true);
        $has_batch_col    = in_array('batch', $csv_headers, true)        || in_array('batch_name', $csv_headers, true);
        $has_status_col   = in_array('status', $csv_headers, true);
        $has_sem_col      = in_array('semester', $csv_headers, true)     || in_array('admitted_semester', $csv_headers, true)
                         || (in_array('session', $csv_headers, true)     && in_array('year', $csv_headers, true));
        $has_phone_col    = in_array('phone', $csv_headers, true)        || in_array('contact_no', $csv_headers, true)
                         || in_array('mobile_number', $csv_headers, true)|| in_array('mobile', $csv_headers, true);
        $has_email_col    = in_array('email', $csv_headers, true);
        $has_sex_col      = in_array('sex', $csv_headers, true)          || in_array('gender', $csv_headers, true);

        foreach ($all_csv_ids as $sid) {
            if (!isset($db_by_id[$sid])) continue;
            $db  = $db_by_id[$sid];
            $csv = $csv_by_id[$sid];
            $diffs = [];

            if ($has_name_col) {
                $csv_name = vl_get_name($csv);
                if ($csv_name !== '' && strcasecmp($csv_name, $db['full_name']) !== 0) {
                    $diffs['full_name'] = ['csv' => $csv_name, 'db' => $db['full_name']];
                }
            }

            if ($has_dept_col) {
                $raw_dept = vl_get_dept($csv);
                $mapped   = $csv_dept_resolved[strtolower($raw_dept)] ?? null;
                if ($mapped === null) {
                    // Try direct system match
                    $mapped = $dept_by_name[strtolower($raw_dept)] ?? $dept_by_code[strtolower($raw_dept)] ?? null;
                }
                if ($mapped !== null && (int)$mapped['id'] !== (int)$db['dept_id']) {
                    $diffs['dept_id'] = [
                        'csv'    => (int)$mapped['id'],
                        'csv_label' => $mapped['name'],
                        'db'     => (int)$db['dept_id'],
                        'db_label'  => $db['dept_name'],
                    ];
                }
            }

            if ($has_prog_col) {
                $raw_prog  = vl_get_prog($csv);
                $dept_id_for_prog = isset($diffs['dept_id']) ? $diffs['dept_id']['csv'] : (int)$db['dept_id'];
                if ($raw_prog !== '') {
                    $prog_rec = $prog_by_name[$dept_id_for_prog][strtolower($raw_prog)] ?? null;
                    if ($prog_rec !== null && (int)$prog_rec['id'] !== (int)$db['program_id']) {
                        $diffs['program_id'] = [
                            'csv'    => (int)$prog_rec['id'],
                            'csv_label' => $prog_rec['program_name'],
                            'db'     => (int)$db['program_id'],
                            'db_label'  => $db['program_name'] ?? '',
                        ];
                    }
                }
            }

            if ($has_batch_col) {
                $raw_batch = vl_get_batch($csv);
                if ($raw_batch !== '') {
                    $batch_rec = vl_resolve_batch($raw_batch, $batch_by_name);
                    if ($batch_rec !== null && (int)$batch_rec['id'] !== (int)$db['batch_id']) {
                        $diffs['batch_id'] = [
                            'csv'    => (int)$batch_rec['id'],
                            'csv_label' => $batch_rec['name'],
                            'db'     => (int)$db['batch_id'],
                            'db_label'  => $db['batch_name'] ?? '',
                        ];
                    }
                }
            }

            if ($has_status_col) {
                $csv_status = vl_get_status($csv);
                if ($csv_status !== '' && in_array($csv_status, $valid_statuses, true) && $csv_status !== $db['status']) {
                    $diffs['status'] = ['csv' => $csv_status, 'db' => $db['status']];
                }
            }

            if ($has_sem_col) {
                $csv_sem = vl_get_semester($csv);
                if ($csv_sem !== '' && $csv_sem !== $db['admitted_semester']) {
                    $diffs['admitted_semester'] = ['csv' => $csv_sem, 'db' => $db['admitted_semester']];
                }
            }

            if ($has_phone_col) {
                $csv_phone = vl_get_phone($csv);
                $db_phone  = $db['phone'] ?? '';
                if ($csv_phone !== '' && $csv_phone !== $db_phone) {
                    $diffs['phone'] = ['csv' => $csv_phone, 'db' => $db_phone];
                }
            }

            if ($has_email_col) {
                $csv_email = vl_get_email($csv);
                $db_email  = $db['email'] ?? '';
                if ($csv_email !== '' && strcasecmp($csv_email, $db_email) !== 0) {
                    $diffs['email'] = ['csv' => $csv_email, 'db' => $db_email];
                }
            }

            if ($has_sex_col) {
                $csv_sex = vl_get_sex($csv);
                if ($csv_sex !== '' && $csv_sex !== ($db['sex'] ?? '')) {
                    $diffs['sex'] = ['csv' => $csv_sex, 'db' => $db['sex'] ?? ''];
                }
            }

            if (!empty($diffs)) {
                $field_mismatches[$sid] = ['db' => $db, 'diffs' => $diffs];
            } else {
                $matched_ok[$sid] = $db;
            }
        }

        $results = compact(
            'extra_in_system', 'missing_in_system',
            'matched_ok', 'field_mismatches',
            'has_name_col', 'has_dept_col', 'has_prog_col',
            'has_batch_col', 'has_status_col', 'has_sem_col',
            'has_phone_col', 'has_email_col', 'has_sex_col',
            'csv_dept_resolved', 'db_by_id', 'csv_by_id'
        );
    }
}

// ── Step: apply updates ───────────────────────────────────────────────────────

$update_summary = null;

if ($step === 'apply_updates') {
    csrf_check();

    if (!sm_is_staff()) {
        die('Permission denied.');
    }

    // Each checkbox: update[student_pk][field] = "1"
    $updates_requested = $_POST['update'] ?? [];
    // Values to apply: update_val[student_pk][field] = new_value
    $update_vals = $_POST['update_val'] ?? [];

    $applied = 0;
    $skipped = 0;

    // Explicit mapping to avoid any dynamic identifier interpolation risks
    $allowed_fields = [
        'full_name'         => 'full_name',
        'dept_id'           => 'dept_id',
        'program_id'        => 'program_id',
        'batch_id'          => 'batch_id',
        'status'            => 'status',
        'admitted_semester' => 'admitted_semester',
        'phone'             => 'phone',
        'email'             => 'email',
        'sex'               => 'sex',
    ];

    foreach ($updates_requested as $student_pk => $fields) {
        $student_pk = (int)$student_pk;
        if ($student_pk <= 0) continue;

        $set_parts  = [];
        $set_params = [];

        foreach ($fields as $field => $on) {
            if (!isset($allowed_fields[$field])) continue;
            $safe_col = $allowed_fields[$field]; // known-safe column name
            $new_val  = $update_vals[$student_pk][$field] ?? null;
            if ($new_val === null) continue;
            $set_parts[]  = "$safe_col = ?";
            $set_params[] = ($new_val === '') ? null : $new_val;
        }

        if (empty($set_parts)) continue;

        $set_params[] = $student_pk;
        try {
            db()->prepare(
                'UPDATE students SET ' . implode(', ', $set_parts) . ' WHERE id = ?'
            )->execute($set_params);
            $applied++;
        } catch (\Exception $e) {
            $skipped++;
        }
    }

    $update_summary = ['applied' => $applied, 'skipped' => $skipped];
    // Clear the cached CSV after applying updates
    unset($_SESSION[VL_SESSION_KEY]);
    $step = 'done';
}

// ── Collect unique dept names from CSV for the map step ───────────────────────

$csv_unique_depts = [];  // lowercase → original string (first seen)
if ($step === 'map' && !empty($csv_rows)) {
    foreach ($csv_rows as $row) {
        $raw = vl_get_dept($row);
        if ($raw === '') continue;
        $key = strtolower($raw);
        if (!isset($csv_unique_depts[$key])) {
            $csv_unique_depts[$key] = $raw;
        }
    }
}

// ── Output ────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/students/index.php">Students</a></li>
            <li class="breadcrumb-item active">Verify Student List</li>
        </ol>
    </nav>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if ($step === 'done' && $update_summary !== null): ?>
<div class="alert alert-success">
    <i class="fas fa-check-circle me-2"></i>
    <strong>Updates Applied.</strong>
    <?= $update_summary['applied'] ?> student record(s) updated<?= $update_summary['skipped'] > 0 ? ', ' . $update_summary['skipped'] . ' skipped due to errors' : '' ?>.
    <a href="<?= APP_URL ?>/students/verify-list.php" class="alert-link ms-2">Run another verification</a>
</div>
<?php endif; ?>

<?php
// ────────────────────────────────────────────────────────────────────────────
// STEP: upload  (initial form)
// ────────────────────────────────────────────────────────────────────────────
if ($step === 'upload' || $step === 'done'):
?>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-tasks me-2 text-muted"></i>Verify Student List Against CSV</h6>
            </div>
            <div class="card-body p-4">
                <p class="text-muted small mb-3">
                    Upload a CSV of <em>genuine</em> student IDs and choose filters to scope which students in the
                    system to compare against. The tool will report extra students (in the system but not in your
                    CSV), missing students (in the CSV but not in the system under the filter), and records with
                    differing field values.
                </p>

                <form method="POST" action="" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_step" value="parse">

                    <!-- Filters -->
                    <fieldset class="mb-4">
                        <legend class="fw-semibold" style="font-size:.9rem;">Step 1 – Scope (database filter)</legend>
                        <div class="row g-2 mt-1">
                            <div class="col-6 col-md-4">
                                <label class="form-label form-label-sm fw-semibold">Department</label>
                                <select name="dept" id="vl_dept" class="form-select form-select-sm">
                                    <option value="">All Departments</option>
                                    <?php foreach ($departments as $d): ?>
                                    <option value="<?= $d['id'] ?>" <?= $f_dept == $d['id'] ? 'selected' : '' ?>>
                                        <?= h($d['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label form-label-sm fw-semibold">Program</label>
                                <select name="program" id="vl_program" class="form-select form-select-sm">
                                    <option value="">All Programs</option>
                                    <?php foreach ($all_programs as $p): ?>
                                    <option value="<?= $p['id'] ?>"
                                            data-dept="<?= $p['dept_id'] ?>"
                                            <?= $f_program == $p['id'] ? 'selected' : '' ?>>
                                        <?= h($p['program_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label form-label-sm fw-semibold">Batch</label>
                                <select name="batch" class="form-select form-select-sm">
                                    <option value="">All Batches</option>
                                    <?php foreach ($batches as $b): ?>
                                    <option value="<?= $b['id'] ?>" <?= $f_batch == $b['id'] ? 'selected' : '' ?>>
                                        <?= h($b['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label form-label-sm fw-semibold">Status</label>
                                <select name="status" class="form-select form-select-sm">
                                    <option value="">All Statuses</option>
                                    <?php foreach ($valid_statuses as $s): ?>
                                    <option value="<?= $s ?>" <?= $f_status === $s ? 'selected' : '' ?>><?= $s ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label form-label-sm fw-semibold">Admitted Semester</label>
                                <input type="text" name="semester" class="form-control form-control-sm"
                                       placeholder="e.g. Fall 2024" value="<?= h($f_sem) ?>">
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label form-label-sm fw-semibold">Semester Type</label>
                                <select name="sem_type" class="form-select form-select-sm">
                                    <option value="">All Types</option>
                                    <option value="bi_semester" <?= $f_sem_type === 'bi_semester' ? 'selected' : '' ?>>Bi Semester</option>
                                    <option value="trimester"   <?= $f_sem_type === 'trimester'   ? 'selected' : '' ?>>Trimester</option>
                                </select>
                            </div>
                        </div>
                    </fieldset>

                    <!-- File upload -->
                    <fieldset class="mb-3">
                        <legend class="fw-semibold" style="font-size:.9rem;">Step 2 – Upload CSV</legend>
                        <div class="mt-2">
                            <input type="file" name="csv_file" id="vl_csv_file" accept=".csv,.txt"
                                   class="form-control form-control-sm" required>
                            <div class="form-text">
                                CSV must have a <code>Student_ID</code> (or <code>ID_No</code>) column.
                                Optional columns: <code>Name</code>, <code>Department</code>, <code>Program</code>,
                                <code>Batch</code>, <code>Status</code>, <code>Semester</code>, <code>Phone</code>, <code>Email</code>.
                            </div>
                        </div>
                    </fieldset>

                    <button type="submit" class="btn btn-primary btn-sm" style="border-radius:7px;">
                        <i class="fas fa-upload me-1"></i> Upload &amp; Parse
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card border-info">
            <div class="card-body p-4">
                <h6 class="fw-semibold text-info mb-2"><i class="fas fa-info-circle me-1"></i>How It Works</h6>
                <ol class="small text-muted mb-0 ps-3">
                    <li class="mb-1">Choose a <strong>database filter</strong> to scope the comparison (e.g. batch 71).</li>
                    <li class="mb-1">Upload your <strong>genuine student ID list</strong> as a CSV.</li>
                    <li class="mb-1">If the CSV uses <strong>different department names</strong>, map them to system departments on the next screen.</li>
                    <li class="mb-1">Review <strong>Extra</strong> (phantom), <strong>Missing</strong>, and <strong>Mismatch</strong> groups.</li>
                    <li>Optionally <strong>apply field updates</strong> to mismatched records.</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<?php
// ────────────────────────────────────────────────────────────────────────────
// STEP: map  (department name mapping)
// ────────────────────────────────────────────────────────────────────────────
elseif ($step === 'map'):
    // Preview: first 10 rows
    $preview_rows = array_slice($csv_rows, 0, 10);
    $total_csv    = count($csv_rows);
?>

<div class="card mb-4">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-map me-2 text-muted"></i>Step 3 – Department Name Mapping</h6>
    </div>
    <div class="card-body p-4">
        <p class="text-muted small mb-3">
            CSV parsed successfully — <strong><?= $total_csv ?></strong> rows found.
            <?php if (!empty($csv_unique_depts)): ?>
            Map the department names found in the CSV to the matching departments in the system.
            Leave blank to skip dept comparison for that name.
            <?php else: ?>
            No <em>Department</em> column detected in the CSV — department comparison will be skipped.
            <?php endif; ?>
        </p>

        <form method="POST" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="_step"    value="verify">
            <input type="hidden" name="dept"     value="<?= h($f_dept) ?>">
            <input type="hidden" name="program"  value="<?= h($f_program) ?>">
            <input type="hidden" name="batch"    value="<?= h($f_batch) ?>">
            <input type="hidden" name="status"   value="<?= h($f_status) ?>">
            <input type="hidden" name="semester" value="<?= h($f_sem) ?>">
            <input type="hidden" name="sem_type" value="<?= h($f_sem_type) ?>">

            <?php if (!empty($csv_unique_depts)): ?>
            <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered mb-0" style="max-width:640px;">
                    <thead class="table-light">
                        <tr>
                            <th>Department name in CSV</th>
                            <th>System department</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($csv_unique_depts as $key => $original): ?>
                    <?php
                        // Try auto-resolve
                        $auto = $dept_by_name[$key] ?? $dept_by_code[$key] ?? null;
                    ?>
                    <tr>
                        <td class="align-middle"><code><?= h($original) ?></code></td>
                        <td>
                            <select name="dept_map[<?= h($key) ?>]" class="form-select form-select-sm">
                                <option value="">— skip (no mapping) —</option>
                                <?php foreach ($departments as $d): ?>
                                <option value="<?= $d['id'] ?>"
                                    <?= ($auto && (int)$auto['id'] === (int)$d['id']) ? 'selected' : '' ?>>
                                    <?= h($d['name']) ?>
                                    <?php if ($d['code']): ?>(<?= h($d['code']) ?>)<?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary btn-sm" style="border-radius:7px;">
                <i class="fas fa-check me-1"></i> Run Verification
            </button>
        </form>
    </div>
</div>

<!-- CSV Preview -->
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-table me-2 text-muted"></i>CSV Preview (first 10 rows)</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" style="font-size:.8rem;">
                <thead class="table-light">
                    <tr>
                        <?php foreach ($csv_headers as $h_col): ?>
                        <th class="px-3"><?= h($h_col) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($preview_rows as $row): ?>
                    <tr>
                        <?php foreach ($csv_headers as $h_col): ?>
                        <td class="px-3"><?= h($row[$h_col] ?? '') ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
// ────────────────────────────────────────────────────────────────────────────
// STEP: verify  (results)
// ────────────────────────────────────────────────────────────────────────────
elseif ($step === 'verify' && $results !== null):
    extract($results);
    $total_csv_count = count($csv_by_id);
    $total_db_count  = count($db_by_id);

    // Reconstitute the dept_map for the update form hidden fields
    $dept_map_post = $_POST['dept_map'] ?? [];
?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#4f8ef7,#3a6fd8);">
            <div class="stat-val"><?= $total_db_count ?></div>
            <div class="stat-label">In System (filtered)</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#17a2b8,#117a8b);">
            <div class="stat-val"><?= $total_csv_count ?></div>
            <div class="stat-label">In CSV</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#28a745,#1d7a34);">
            <div class="stat-val"><?= count($matched_ok) + count($field_mismatches) ?></div>
            <div class="stat-label">Matched</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#dc3545,#a71d2a);">
            <div class="stat-val"><?= count($extra_in_system) ?></div>
            <div class="stat-label">Extra in System</div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-0" id="vl-tabs" role="tablist">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-extra">
            <i class="fas fa-exclamation-triangle me-1 text-danger"></i>
            Extra in System <span class="badge bg-danger ms-1"><?= count($extra_in_system) ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-missing">
            <i class="fas fa-question-circle me-1 text-warning"></i>
            Missing from System <span class="badge bg-warning text-dark ms-1"><?= count($missing_in_system) ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-mismatch">
            <i class="fas fa-not-equal me-1 text-info"></i>
            Field Mismatches <span class="badge bg-info text-dark ms-1"><?= count($field_mismatches) ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-ok">
            <i class="fas fa-check-circle me-1 text-success"></i>
            Matched OK <span class="badge bg-success ms-1"><?= count($matched_ok) ?></span>
        </button>
    </li>
</ul>
<div class="tab-content border border-top-0 rounded-bottom mb-4 bg-white">

    <!-- Extra in System -->
    <div class="tab-pane fade show active p-3" id="tab-extra">
        <?php if (empty($extra_in_system)): ?>
        <p class="text-success mb-0"><i class="fas fa-check-circle me-1"></i>No extra students found. All system students under this filter are present in the CSV.</p>
        <?php else: ?>
        <p class="text-muted small mb-2">
            These <strong><?= count($extra_in_system) ?></strong> student(s) exist in the system under the chosen filter
            but are <strong>not present in the CSV</strong>. They may be incorrectly enrolled, phantom entries, or the filter needs adjustment.
        </p>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" style="font-size:.8rem;">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Student ID</th>
                        <th>Name</th>
                        <th>Department</th>
                        <th>Program</th>
                        <th>Batch</th>
                        <th>Status</th>
                        <th>Admitted</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php $i = 1; foreach ($extra_in_system as $sid => $s): ?>
                <tr class="table-danger">
                    <td><?= $i++ ?></td>
                    <td><code class="text-danger"><?= h($s['student_id']) ?></code></td>
                    <td><?= h($s['full_name']) ?></td>
                    <td><?= h($s['dept_name']) ?></td>
                    <td><?= h($s['program_name'] ?? '—') ?></td>
                    <td><?= h($s['batch_name'] ?? '—') ?></td>
                    <td><?= sm_status_badge($s['status']) ?></td>
                    <td><?= h($s['admitted_semester'] ?? '—') ?></td>
                    <td>
                        <a href="<?= APP_URL ?>/students/view.php?id=<?= (int)$s['id'] ?>"
                           class="btn btn-xs btn-outline-secondary" target="_blank" style="font-size:.75rem;padding:1px 6px;border-radius:5px;">
                            View
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Missing from System -->
    <div class="tab-pane fade p-3" id="tab-missing">
        <?php if (empty($missing_in_system)): ?>
        <p class="text-success mb-0"><i class="fas fa-check-circle me-1"></i>All CSV students are present in the system under this filter.</p>
        <?php else: ?>
        <p class="text-muted small mb-2">
            These <strong><?= count($missing_in_system) ?></strong> student ID(s) appear in the CSV but
            <strong>were not found in the system</strong> under the chosen filter. They may be admitted in a
            different batch/dept, or not yet registered.
        </p>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" style="font-size:.8rem;">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Student ID (CSV)</th>
                        <?php if ($has_name_col): ?><th>Name (CSV)</th><?php endif; ?>
                        <?php if ($has_dept_col): ?><th>Department (CSV)</th><?php endif; ?>
                        <?php if ($has_batch_col): ?><th>Batch (CSV)</th><?php endif; ?>
                        <?php if ($has_status_col): ?><th>Status (CSV)</th><?php endif; ?>
                        <th>Search in system</th>
                    </tr>
                </thead>
                <tbody>
                <?php $i = 1; foreach ($missing_in_system as $sid => $csv_row): ?>
                <tr class="table-warning">
                    <td><?= $i++ ?></td>
                    <td><code class="text-warning"><?= h($sid) ?></code></td>
                    <?php if ($has_name_col): ?><td><?= h(vl_get_name($csv_row)) ?></td><?php endif; ?>
                    <?php if ($has_dept_col): ?><td><?= h(vl_get_dept($csv_row)) ?></td><?php endif; ?>
                    <?php if ($has_batch_col): ?><td><?= h(vl_get_batch($csv_row)) ?></td><?php endif; ?>
                    <?php if ($has_status_col): ?><td><?= h(vl_get_status($csv_row)) ?></td><?php endif; ?>
                    <td>
                        <a href="<?= APP_URL ?>/students/index.php?search=<?= urlencode($sid) ?>"
                           class="btn btn-xs btn-outline-secondary" target="_blank" style="font-size:.75rem;padding:1px 6px;border-radius:5px;">
                            Search
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Field Mismatches -->
    <div class="tab-pane fade p-3" id="tab-mismatch">
        <?php if (empty($field_mismatches)): ?>
        <p class="text-success mb-0"><i class="fas fa-check-circle me-1"></i>No field mismatches found.</p>
        <?php else: ?>
        <p class="text-muted small mb-2">
            These <strong><?= count($field_mismatches) ?></strong> student(s) exist in both the system and the CSV
            but have <strong>differing field values</strong>. Check the boxes for fields you want to update to the
            CSV value, then click <em>Apply Selected Updates</em>.
        </p>
        <?php if (sm_is_staff()): ?>
        <form method="POST" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="_step"    value="apply_updates">
            <input type="hidden" name="dept"     value="<?= h($f_dept) ?>">
            <input type="hidden" name="program"  value="<?= h($f_program) ?>">
            <input type="hidden" name="batch"    value="<?= h($f_batch) ?>">
            <input type="hidden" name="status"   value="<?= h($f_status) ?>">
            <input type="hidden" name="semester" value="<?= h($f_sem) ?>">
            <input type="hidden" name="sem_type" value="<?= h($f_sem_type) ?>">
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" style="font-size:.8rem;">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Student ID</th>
                        <th>Name</th>
                        <th>Field</th>
                        <th>Value in System</th>
                        <th>Value in CSV</th>
                        <?php if (sm_is_staff()): ?>
                        <th class="text-center">Update?</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php $i = 1; foreach ($field_mismatches as $sid => $entry):
                    $db   = $entry['db'];
                    $diffs= $entry['diffs'];
                    $pk   = (int)$db['id'];
                    $is_first = true;
                    foreach ($diffs as $field => $diff):
                        $label = match ($field) {
                            'full_name'        => 'Name',
                            'dept_id'          => 'Department',
                            'program_id'       => 'Program',
                            'batch_id'         => 'Batch',
                            'status'           => 'Status',
                            'admitted_semester'=> 'Admitted Semester',
                            'phone'            => 'Phone',
                            'email'            => 'Email',
                            'sex'              => 'Gender',
                            default            => $field,
                        };
                        $db_display  = is_array($diff) && isset($diff['db_label'])  ? $diff['db_label']  : ($diff['db']  ?? '');
                        $csv_display = is_array($diff) && isset($diff['csv_label']) ? $diff['csv_label'] : ($diff['csv'] ?? '');
                        $new_val     = is_array($diff) ? $diff['csv'] : $diff['csv'];
                ?>
                <tr class="table-info">
                    <?php if ($is_first): $is_first = false; ?>
                    <td rowspan="<?= count($diffs) ?>"><?= $i++ ?></td>
                    <td rowspan="<?= count($diffs) ?>">
                        <a href="<?= APP_URL ?>/students/view.php?id=<?= $pk ?>" target="_blank">
                            <code><?= h($db['student_id']) ?></code>
                        </a>
                    </td>
                    <td rowspan="<?= count($diffs) ?>"><?= h($db['full_name']) ?></td>
                    <?php endif; ?>
                    <td><?= h($label) ?></td>
                    <td class="text-muted"><?php $db_disp_str = h((string)$db_display); echo $db_disp_str !== '' ? $db_disp_str : '<em class="text-muted">empty</em>'; ?></td>
                    <td class="fw-semibold text-primary"><?= h((string)$csv_display) ?></td>
                    <?php if (sm_is_staff()): ?>
                    <td class="text-center">
                        <input type="checkbox" name="update[<?= $pk ?>][<?= h($field) ?>]"
                               value="1" class="form-check-input vl-update-cb">
                        <input type="hidden"   name="update_val[<?= $pk ?>][<?= h($field) ?>]"
                               value="<?= h((string)$new_val) ?>">
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (sm_is_staff()): ?>
        <div class="mt-3 d-flex gap-2 align-items-center">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="vl-select-all" style="border-radius:7px;">
                Select All
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="vl-deselect-all" style="border-radius:7px;">
                Deselect All
            </button>
            <button type="submit" class="btn btn-primary btn-sm ms-auto" style="border-radius:7px;"
                    onclick="return confirm('Apply the selected field updates to the student records?')">
                <i class="fas fa-save me-1"></i> Apply Selected Updates
            </button>
        </div>
        </form>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Matched OK -->
    <div class="tab-pane fade p-3" id="tab-ok">
        <?php if (empty($matched_ok)): ?>
        <p class="text-muted mb-0">No students matched with identical field values.</p>
        <?php else: ?>
        <p class="text-muted small mb-2">
            These <strong><?= count($matched_ok) ?></strong> student(s) are present in both the system and the CSV
            with no detected field differences.
        </p>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" style="font-size:.8rem;">
                <thead class="table-light">
                    <tr><th>#</th><th>Student ID</th><th>Name</th><th>Department</th><th>Status</th><th>Admitted</th></tr>
                </thead>
                <tbody>
                <?php $i = 1; foreach ($matched_ok as $sid => $s): ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><code class="text-success"><?= h($s['student_id']) ?></code></td>
                    <td><a href="<?= APP_URL ?>/students/view.php?id=<?= (int)$s['id'] ?>" target="_blank"><?= h($s['full_name']) ?></a></td>
                    <td><?= h($s['dept_name']) ?></td>
                    <td><?= sm_status_badge($s['status']) ?></td>
                    <td><?= h($s['admitted_semester'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /tab-content -->

<a href="<?= APP_URL ?>/students/verify-list.php" class="btn btn-outline-secondary btn-sm" style="border-radius:7px;">
    <i class="fas fa-redo me-1"></i> Start Over
</a>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var selectAll   = document.getElementById('vl-select-all');
    var deselectAll = document.getElementById('vl-deselect-all');
    if (selectAll) {
        selectAll.addEventListener('click', function () {
            document.querySelectorAll('.vl-update-cb').forEach(function (cb) { cb.checked = true; });
        });
    }
    if (deselectAll) {
        deselectAll.addEventListener('click', function () {
            document.querySelectorAll('.vl-update-cb').forEach(function (cb) { cb.checked = false; });
        });
    }
});
</script>

<?php endif; // end of step dispatch ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
