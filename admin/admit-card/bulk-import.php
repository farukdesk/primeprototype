<?php
/**
 * Admit Card – Bulk CSV Import
 *
 * Step 1 (GET)      – Upload form: CSV file, Exam Name, Dept, Program
 * Step 2 (preview)  – Field-mapping summary + per-group course & student preview
 * Step 3 (import)   – Creates ac_admit_cards + ac_admit_card_courses records
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('admit-card', 'can_create');
require_once __DIR__ . '/helpers.php';

$page_title = 'Bulk Import Admit Cards';
$db = db();

$depts = $db->query(
    "SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC"
)->fetchAll();

$year = (int)date('Y');
$semester_opts = [];
foreach ([$year - 1, $year, $year + 1] as $y) {
    $semester_opts[] = "Spring $y";
    $semester_opts[] = "Summer $y";
    $semester_opts[] = "Fall $y";
}

// ── Helpers ──────────────────────────────────────────────────────────────────

/** Convert a date string from the CSV (e.g. 29-06-2026) → YYYY-MM-DD or null */
function ac_bi_parse_date(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') return null;
    foreach (['d-m-Y', 'd/m/Y', 'Y-m-d', 'd-M-Y'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $raw);
        if ($dt) return $dt->format('Y-m-d');
    }
    $ts = strtotime($raw);
    return ($ts !== false) ? date('Y-m-d', $ts) : null;
}

/** Returns true when a CSV row is a corrupted/garbage row that should be skipped */
function ac_bi_is_bad_row(array $row): bool
{
    $code  = trim($row['course_code']  ?? '');
    $title = trim($row['course_title'] ?? '');
    // Header row repeated in data
    if (strtolower($row['student_name'] ?? '') === 'student name') return true;
    // Title field contains the literal header text
    if (stripos($title, 'Course Code') !== false && stripos($title, 'Course Title') !== false) return true;
    // Course code suspiciously long or empty
    if ($code === '' || mb_strlen($code) > 30) return true;
    return false;
}

/**
 * Parse an uploaded CSV file into an array of normalised row maps.
 * Expects headers: Student Name, ID No, Batch, Semester, Course Code, Course Title, Date, Time Slot, Section
 */
function ac_bi_parse_csv(string $filepath): array
{
    $rows = [];
    if (!is_file($filepath)) return $rows;

    $handle = fopen($filepath, 'r');
    if (!$handle) return $rows;

    // Strip UTF-8 BOM
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($handle);

    $header = fgetcsv($handle, 4096, ',');
    if (!$header) { fclose($handle); return $rows; }

    // Normalise header names → snake_case
    $header = array_map(
        fn($h) => strtolower(trim(preg_replace('/\s+/', '_', trim($h)))),
        $header
    );

    // Find column indices for expected fields
    $want = ['student_name', 'id_no', 'batch', 'semester',
             'course_code', 'course_title', 'date', 'time_slot', 'section'];
    $idx = [];
    foreach ($want as $key) {
        $pos = array_search($key, $header);
        $idx[$key] = ($pos !== false) ? (int)$pos : -1;
    }

    while (($line = fgetcsv($handle, 4096, ',')) !== false) {
        if (count($line) < 2) continue;
        $get = fn($k) => ($idx[$k] >= 0 && isset($line[$idx[$k]])) ? trim($line[$idx[$k]]) : '';
        $row = [
            'student_name' => $get('student_name'),
            'id_no'        => $get('id_no'),
            'batch'        => $get('batch'),
            'semester'     => $get('semester'),
            'course_code'  => $get('course_code'),
            'course_title' => $get('course_title'),
            'date'         => $get('date'),
            'time_slot'    => $get('time_slot'),
            'section'      => $get('section'),
        ];
        if (!ac_bi_is_bad_row($row)) $rows[] = $row;
    }
    fclose($handle);
    return $rows;
}

/**
 * Group parsed rows by (Semester | Batch | Section), resolve courses & students.
 */
function ac_bi_group_rows(array $rows, PDO $db): array
{
    $groups = [];
    foreach ($rows as $row) {
        $key = $row['semester'] . '||' . $row['batch'] . '||' . $row['section'];
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'semester'    => $row['semester'],
                'batch_label' => $row['batch'],
                'section'     => $row['section'],
                'courses'     => [],
                'students'    => [],
            ];
        }
        // Deduplicate courses by code
        $ck = $row['course_code'];
        if (!isset($groups[$key]['courses'][$ck])) {
            $groups[$key]['courses'][$ck] = [
                'course_code'  => $row['course_code'],
                'course_title' => $row['course_title'],
                'exam_date'    => ac_bi_parse_date($row['date']),
                'time_slot'    => $row['time_slot'],
                'section'      => $row['section'],
            ];
        }
        // Deduplicate students by CSV ID
        $sk = $row['id_no'];
        if (!isset($groups[$key]['students'][$sk])) {
            $groups[$key]['students'][$sk] = [
                'csv_name' => $row['student_name'],
                'csv_id'   => $row['id_no'],
                'db_id'    => null,
                'db_name'  => null,
                'matched'  => false,
            ];
        }
    }

    // Look up each student in the DB
    foreach ($groups as &$g) {
        foreach ($g['students'] as &$stu) {
            if ($stu['csv_id'] !== '') {
                $s = $db->prepare(
                    'SELECT id, full_name FROM students WHERE student_id = ? LIMIT 1'
                );
                $s->execute([$stu['csv_id']]);
                $found = $s->fetch();
                if ($found) {
                    $stu['db_id']   = (int)$found['id'];
                    $stu['db_name'] = $found['full_name'];
                    $stu['matched'] = true;
                }
            }
        }
        unset($stu);
        // Flatten to indexed arrays
        $g['courses']  = array_values($g['courses']);
        $g['students'] = array_values($g['students']);
    }
    unset($g);

    return $groups;
}

// ── Handle POST ───────────────────────────────────────────────────────────────

$step    = 'upload';
$errors  = [];
$groups  = [];
$created = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // ── Step 2: Preview ───────────────────────────────────────────────────────
    if ($action === 'preview') {
        $exam_name  = trim($_POST['exam_name']  ?? '');
        $dept_id    = (int)($_POST['dept_id']   ?? 0);
        $program_id = (int)($_POST['program_id'] ?? 0);
        $is_active  = isset($_POST['is_active']) ? 1 : 0;

        if ($exam_name === '') $errors[] = 'Exam name is required.';
        if ($dept_id   <= 0)  $errors[] = 'Please select a department.';
        if ($program_id <= 0) $errors[] = 'Please select a program.';

        $file = $_FILES['csv_file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Please upload a valid CSV file.';
        } elseif (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
            $errors[] = 'Only CSV files are accepted.';
        }

        if (empty($errors)) {
            $tmp = sys_get_temp_dir() . '/ac_bulk_' . bin2hex(random_bytes(8)) . '.csv';
            if (!move_uploaded_file($file['tmp_name'], $tmp)) {
                $errors[] = 'Could not save the uploaded file.';
            } else {
                $rows = ac_bi_parse_csv($tmp);
                if (empty($rows)) {
                    @unlink($tmp);
                    $errors[] = 'No valid data rows found. Make sure the CSV headers are exactly: '
                              . 'Student Name, ID No, Batch, Semester, Course Code, Course Title, Date, Time Slot, Section';
                } else {
                    $groups = ac_bi_group_rows($rows, $db);
                    // Store in session for the confirm step
                    $_SESSION['ac_bulk_import'] = [
                        'tmp_csv'    => $tmp,
                        'exam_name'  => $exam_name,
                        'dept_id'    => $dept_id,
                        'program_id' => $program_id,
                        'is_active'  => $is_active,
                        'groups'     => $groups,
                    ];
                    $step = 'preview';
                }
            }
        }
    }

    // ── Step 3: Import ────────────────────────────────────────────────────────
    if ($action === 'import') {
        $sess = $_SESSION['ac_bulk_import'] ?? null;
        if (!$sess) {
            flash_set('error', 'Session expired. Please re-upload the CSV.');
            redirect(APP_URL . '/admit-card/bulk-import.php');
        }

        $exam_name  = $sess['exam_name'];
        $dept_id    = $sess['dept_id'];
        $program_id = $sess['program_id'];
        $is_active  = $sess['is_active'];
        $groups     = $sess['groups'];

        // Only import selected groups
        $selected_keys = array_filter(array_keys($groups), function ($k) {
            $safe = 'grp_' . md5($k);
            return isset($_POST[$safe]) && $_POST[$safe] === '1';
        });
        if (empty($selected_keys)) {
            // If nothing ticked, import all
            $selected_keys = array_keys($groups);
        }

        $multi = count($selected_keys) > 1;

        foreach ($selected_keys as $key) {
            $g = $groups[$key];

            // Resolve batch_id
            $batch_id = null;
            if ($g['batch_label'] !== '') {
                $br = $db->prepare('SELECT id FROM student_batches WHERE name = ? LIMIT 1');
                $br->execute([$g['batch_label']]);
                $brow = $br->fetch();
                if ($brow) $batch_id = (int)$brow['id'];
            }

            $full_name = $exam_name;
            if ($multi && $g['section'] !== '') {
                $full_name = $exam_name . ' (' . $g['section'] . ')';
            }

            $db->prepare(
                'INSERT INTO ac_admit_cards
                 (exam_name, semester, dept_id, program_id, batch_id, batch_label, is_active, created_by)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([
                $full_name,
                $g['semester'],
                $dept_id,
                $program_id,
                $batch_id,
                $g['batch_label'] ?: null,
                $is_active,
                auth_user()['id'],
            ]);
            $card_id = (int)$db->lastInsertId();

            foreach ($g['courses'] as $i => $c) {
                $db->prepare(
                    'INSERT INTO ac_admit_card_courses
                     (admit_card_id, course_code, course_title, exam_date, time_slot, section, sort_order)
                     VALUES (?,?,?,?,?,?,?)'
                )->execute([
                    $card_id,
                    $c['course_code'],
                    $c['course_title'],
                    $c['exam_date'],
                    $c['time_slot'] ?: null,
                    $c['section']   ?: null,
                    $i,
                ]);
            }

            $matched = count(array_filter($g['students'], fn($s) => $s['matched']));
            $created[] = [
                'id'           => $card_id,
                'exam_name'    => $full_name,
                'semester'     => $g['semester'],
                'batch'        => $g['batch_label'],
                'section'      => $g['section'],
                'courses'      => count($g['courses']),
                'students_matched' => $matched,
                'students_total'   => count($g['students']),
            ];
        }

        // Clean up
        if (!empty($sess['tmp_csv']) && is_file($sess['tmp_csv'])) @unlink($sess['tmp_csv']);
        unset($_SESSION['ac_bulk_import']);

        $step = 'done';
    }
}

// ── Determine dept name & program name for preview header ─────────────────────
$dept_name_preview    = '';
$program_name_preview = '';
if ($step === 'preview' && isset($_SESSION['ac_bulk_import'])) {
    $d = $db->prepare('SELECT name FROM dept_departments WHERE id = ? LIMIT 1');
    $d->execute([$_SESSION['ac_bulk_import']['dept_id']]);
    $dept_name_preview = $d->fetchColumn() ?: '';
    $p = $db->prepare('SELECT program_name FROM dept_academic_programs WHERE id = ? LIMIT 1');
    $p->execute([$_SESSION['ac_bulk_import']['program_id']]);
    $program_name_preview = $p->fetchColumn() ?: '';
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-file-csv me-2 text-primary"></i>Bulk Import Admit Cards</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admit-card/index.php">Admit Cards</a></li>
            <li class="breadcrumb-item active">Bulk Import</li>
        </ol></nav>
    </div>
</div>

<?php flash_show(); ?>

<!-- Progress Steps -->
<div class="d-flex align-items-center gap-3 mb-4">
    <span class="badge <?= $step === 'upload'  ? 'bg-primary' : ($step === 'done' ? 'bg-success' : 'bg-success') ?> rounded-pill px-3 py-2">
        <i class="fas fa-<?= $step === 'upload' ? 'upload' : 'check' ?> me-1"></i> 1. Upload
    </span>
    <i class="fas fa-chevron-right text-muted"></i>
    <span class="badge <?= $step === 'preview' ? 'bg-primary' : ($step === 'done' ? 'bg-success' : 'bg-secondary') ?> rounded-pill px-3 py-2">
        <i class="fas fa-<?= $step === 'preview' ? 'eye' : ($step === 'done' ? 'check' : 'eye') ?> me-1"></i> 2. Preview
    </span>
    <i class="fas fa-chevron-right text-muted"></i>
    <span class="badge <?= $step === 'done' ? 'bg-success' : 'bg-secondary' ?> rounded-pill px-3 py-2">
        <i class="fas fa-<?= $step === 'done' ? 'check-circle' : 'database' ?> me-1"></i> 3. Import
    </span>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════════ -->
<!-- STEP 1 – Upload Form                                               -->
<!-- ══════════════════════════════════════════════════════════════════ -->
<?php if ($step === 'upload'): ?>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header py-3 px-4 fw-semibold">
                <i class="fas fa-upload me-2 text-primary"></i>Upload CSV File
            </div>
            <div class="card-body px-4">
                <form method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="preview">

                    <!-- CSV File -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold">CSV File <span class="text-danger">*</span></label>
                        <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                        <div class="form-text">
                            The CSV must have the following columns in any order:
                            <code>Student Name, ID No, Batch, Semester, Course Code, Course Title, Date, Time Slot, Section</code>
                        </div>
                    </div>

                    <hr class="my-4">

                    <!-- Exam Details -->
                    <div class="row g-3 mb-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Exam Name <span class="text-danger">*</span></label>
                            <input type="text" name="exam_name" class="form-control"
                                   value="<?= h($_POST['exam_name'] ?? '') ?>"
                                   placeholder="e.g. Final Exam – Summer 2026" required>
                            <div class="form-text">
                                When the CSV contains multiple sections, each admit card will have the section name appended automatically (e.g. "Final Exam – Summer 2026 (Day)").
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Department <span class="text-danger">*</span></label>
                            <select name="dept_id" id="dept_id" class="form-select" required>
                                <option value="">— Select Department —</option>
                                <?php foreach ($depts as $d): ?>
                                <option value="<?= $d['id'] ?>" <?= (int)($_POST['dept_id'] ?? 0) === (int)$d['id'] ? 'selected' : '' ?>>
                                    <?= h($d['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Program <span class="text-danger">*</span></label>
                            <select name="program_id" id="program_id" class="form-select" required>
                                <option value="">— Select Program —</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-4 form-check">
                        <input type="checkbox" name="is_active" id="is_active" class="form-check-input" value="1"
                               <?= isset($_POST['is_active']) || !isset($_POST['action']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">
                            Activate immediately (visible to students)
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-eye me-1"></i> Preview Import
                    </button>
                    <a href="<?= APP_URL ?>/admit-card/index.php" class="btn btn-outline-secondary ms-2">Cancel</a>
                </form>
            </div>
        </div>
    </div>

    <!-- CSV Format Info -->
    <div class="col-lg-4">
        <div class="card border-info-subtle">
            <div class="card-header py-3 px-4 fw-semibold bg-info-subtle">
                <i class="fas fa-info-circle me-2 text-info"></i>Expected CSV Format
            </div>
            <div class="card-body px-4 small">
                <p class="mb-2">The CSV must contain these column headers (case-insensitive):</p>
                <ul class="mb-3">
                    <li><strong>Student Name</strong> – Full name</li>
                    <li><strong>ID No</strong> – Student ID number</li>
                    <li><strong>Batch</strong> – e.g. 12/66</li>
                    <li><strong>Semester</strong> – e.g. Summer-2026</li>
                    <li><strong>Course Code</strong> – e.g. EEE 2203</li>
                    <li><strong>Course Title</strong> – e.g. Electrical Machines II</li>
                    <li><strong>Date</strong> – e.g. 25-06-2026</li>
                    <li><strong>Time Slot</strong> – e.g. 3:00pm-4:30pm</li>
                    <li><strong>Section</strong> – e.g. Day</li>
                </ul>
                <p class="mb-1">Each row = one student × one course.
                   The importer groups rows by <strong>Semester + Batch + Section</strong>
                   and creates one admit card per group.</p>
                <p class="mb-0 text-muted">Corrupted or duplicate rows are automatically skipped.</p>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════ -->
<!-- STEP 2 – Preview                                                    -->
<!-- ══════════════════════════════════════════════════════════════════ -->
<?php elseif ($step === 'preview'):
    $sess = $_SESSION['ac_bulk_import'];
    $groups = $sess['groups'];
?>

<div class="alert alert-info d-flex gap-2 align-items-start mb-4">
    <i class="fas fa-info-circle mt-1"></i>
    <div>
        Review the field mapping and grouped data below.
        Tick the groups you want to import, then click <strong>Confirm Import</strong>.
        You can go back and re-upload if something looks wrong.
    </div>
</div>

<!-- Field Mapping Summary -->
<div class="card mb-4">
    <div class="card-header py-3 px-4 fw-semibold">
        <i class="fas fa-table me-2 text-muted"></i>Field Mapping
    </div>
    <div class="card-body px-4">
        <div class="row g-3">
            <div class="col-md-6">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>CSV Column</th><th>→</th><th>System Field</th></tr></thead>
                    <tbody>
                        <tr><td><code>Student Name</code></td><td>→</td><td>Student lookup (by ID No)</td></tr>
                        <tr><td><code>ID No</code></td><td>→</td><td><code>students.student_id</code></td></tr>
                        <tr><td><code>Batch</code></td><td>→</td><td><code>ac_admit_cards.batch_label</code></td></tr>
                        <tr><td><code>Semester</code></td><td>→</td><td><code>ac_admit_cards.semester</code></td></tr>
                    </tbody>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>CSV Column</th><th>→</th><th>System Field</th></tr></thead>
                    <tbody>
                        <tr><td><code>Course Code</code></td><td>→</td><td><code>ac_admit_card_courses.course_code</code></td></tr>
                        <tr><td><code>Course Title</code></td><td>→</td><td><code>ac_admit_card_courses.course_title</code></td></tr>
                        <tr><td><code>Date</code></td><td>→</td><td><code>ac_admit_card_courses.exam_date</code></td></tr>
                        <tr><td><code>Time Slot</code></td><td>→</td><td><code>ac_admit_card_courses.time_slot</code></td></tr>
                        <tr><td><code>Section</code></td><td>→</td><td><code>ac_admit_card_courses.section</code></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-3 row g-2 small text-muted">
            <div class="col-auto"><strong>Exam Name:</strong> <?= h($sess['exam_name']) ?></div>
            <div class="col-auto">|</div>
            <div class="col-auto"><strong>Department:</strong> <?= h($dept_name_preview) ?></div>
            <div class="col-auto">|</div>
            <div class="col-auto"><strong>Program:</strong> <?= h($program_name_preview) ?></div>
            <div class="col-auto">|</div>
            <div class="col-auto"><strong>Status:</strong> <?= $sess['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></div>
        </div>
    </div>
</div>

<!-- Groups Preview -->
<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="import">

    <?php foreach ($groups as $key => $g):
        $safe_key    = 'grp_' . md5($key);
        $matched_cnt = count(array_filter($g['students'], fn($s) => $s['matched']));
        $total_cnt   = count($g['students']);
        $unmatch_cnt = $total_cnt - $matched_cnt;
    ?>
    <div class="card mb-3">
        <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-3">
                <input type="hidden" name="<?= h($safe_key) ?>" value="1">
                <div>
                    <span class="fw-semibold"><?= h($g['semester']) ?> — Batch <?= h($g['batch_label']) ?></span>
                    <?php if ($g['section'] !== ''): ?>
                        <span class="badge bg-info-subtle text-info border ms-2"><?= h($g['section']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="d-flex gap-3 small">
                <span><i class="fas fa-book text-success me-1"></i><?= count($g['courses']) ?> courses</span>
                <span><i class="fas fa-users text-primary me-1"></i><?= $total_cnt ?> students
                    (<span class="text-success"><?= $matched_cnt ?> matched</span><?php if ($unmatch_cnt > 0): ?>,
                    <span class="text-danger"><?= $unmatch_cnt ?> unmatched</span><?php endif; ?>)
                </span>
            </div>
        </div>
        <div class="card-body px-4 pb-3">
            <div class="row g-3">
                <!-- Courses -->
                <div class="col-md-6">
                    <h6 class="text-muted fw-semibold small mb-2">COURSES</h6>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                                <tr><th>Code</th><th>Title</th><th>Date</th><th>Time</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($g['courses'] as $c): ?>
                            <tr>
                                <td><code><?= h($c['course_code']) ?></code></td>
                                <td class="small"><?= h($c['course_title']) ?></td>
                                <td class="small text-nowrap">
                                    <?= $c['exam_date'] ? date('d-m-Y', strtotime($c['exam_date'])) : '<span class="text-warning">?</span>' ?>
                                </td>
                                <td class="small text-nowrap"><?= h($c['time_slot'] ?? '—') ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Students -->
                <div class="col-md-6">
                    <h6 class="text-muted fw-semibold small mb-2">STUDENTS</h6>
                    <div class="table-responsive" style="max-height:220px;overflow-y:auto;">
                        <table class="table table-sm mb-0">
                            <thead class="table-light sticky-top">
                                <tr><th>CSV ID</th><th>CSV Name</th><th>Match</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($g['students'] as $s): ?>
                            <tr>
                                <td><code class="small"><?= h($s['csv_id']) ?></code></td>
                                <td class="small"><?= h($s['csv_name']) ?></td>
                                <td>
                                    <?php if ($s['matched']): ?>
                                        <span class="badge bg-success"><i class="fas fa-check"></i></span>
                                        <small class="text-muted ms-1"><?= h($s['db_name']) ?></small>
                                    <?php else: ?>
                                        <span class="badge bg-danger"><i class="fas fa-times"></i></span>
                                        <small class="text-danger ms-1">Not found</small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="d-flex gap-2 mt-3 mb-4">
        <button type="submit" class="btn btn-success">
            <i class="fas fa-database me-1"></i> Confirm Import (<?= count($groups) ?> admit card<?= count($groups) !== 1 ? 's' : '' ?>)
        </button>
        <a href="<?= APP_URL ?>/admit-card/bulk-import.php" class="btn btn-outline-secondary">
            <i class="fas fa-redo me-1"></i> Start Over
        </a>
    </div>
</form>

<!-- ══════════════════════════════════════════════════════════════════ -->
<!-- STEP 3 – Done                                                       -->
<!-- ══════════════════════════════════════════════════════════════════ -->
<?php elseif ($step === 'done'): ?>

<div class="alert alert-success mb-4">
    <i class="fas fa-check-circle me-2"></i>
    <strong>Import complete!</strong> <?= count($created) ?> admit card<?= count($created) !== 1 ? 's' : '' ?> created.
</div>

<div class="card">
    <div class="card-header py-3 px-4 fw-semibold">Created Admit Cards</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">#</th>
                        <th>Exam Name</th>
                        <th>Semester</th>
                        <th>Batch</th>
                        <th>Section</th>
                        <th>Courses</th>
                        <th>Students matched</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($created as $c): ?>
                <tr>
                    <td class="px-4"><?= (int)$c['id'] ?></td>
                    <td class="fw-semibold"><?= h($c['exam_name']) ?></td>
                    <td><?= h($c['semester']) ?></td>
                    <td><?= h($c['batch']) ?></td>
                    <td><?= h($c['section']) ?></td>
                    <td><span class="badge bg-secondary"><?= (int)$c['courses'] ?></span></td>
                    <td>
                        <span class="text-success fw-semibold"><?= (int)$c['students_matched'] ?></span>
                        / <?= (int)$c['students_total'] ?>
                    </td>
                    <td>
                        <a href="<?= APP_URL ?>/admit-card/view.php?id=<?= $c['id'] ?>"
                           class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-eye me-1"></i> View
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="mt-3">
    <a href="<?= APP_URL ?>/admit-card/index.php" class="btn btn-primary">
        <i class="fas fa-list me-1"></i> Back to Admit Cards
    </a>
    <a href="<?= APP_URL ?>/admit-card/bulk-import.php" class="btn btn-outline-secondary ms-2">
        <i class="fas fa-upload me-1"></i> Import Another CSV
    </a>
</div>

<?php endif; ?>

<script>
// Dynamic program dropdown (same as create.php)
const deptSel = document.getElementById('dept_id');
if (deptSel) {
    deptSel.addEventListener('change', function () {
        const programSel = document.getElementById('program_id');
        programSel.innerHTML = '<option value="">Loading…</option>';
        if (!this.value) { programSel.innerHTML = '<option value="">— Select Program —</option>'; return; }
        fetch('<?= APP_URL ?>/course-offer/get-programs.php?dept_id=' + this.value)
            .then(r => r.json())
            .then(data => {
                programSel.innerHTML = '<option value="">— Select Program —</option>';
                data.forEach(p => {
                    const opt = document.createElement('option');
                    opt.value = p.id;
                    opt.textContent = p.program_name;
                    programSel.appendChild(opt);
                });
            });
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
