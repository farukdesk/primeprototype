<?php
/**
 * Course Curriculum – Bulk CSV Upload
 *
 * CSV columns (first row = header, ignored):
 *   sl_no, subject_title*, subject_code, credit, bnqf_code, semester,
 *   course_teacher, sort_order,
 *   dist1_name, dist1_marks, dist2_name, dist2_marks, dist3_name, dist3_marks,
 *   dist4_name, dist4_marks, dist5_name, dist5_marks
 *
 * * required
 *
 * Semester matching  : exact label (e.g. "1st Year 1st Semester") or number 1–12
 * Course Teacher     : exact name match (case-insensitive) within the selected dept
 */

require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../change-log/helpers.php';

if (!cc_is_staff()) {
    redirect(APP_URL . '/course-curriculum/index.php');
}

// ── Download sample CSV ───────────────────────────────────────────────────────
if (isset($_GET['download_sample'])) {
    $sample_headers = [
        'sl_no', 'subject_title', 'subject_code', 'credit', 'bnqf_code', 'semester',
        'course_teacher', 'sort_order',
        'dist1_name', 'dist1_marks',
        'dist2_name', 'dist2_marks',
        'dist3_name', 'dist3_marks',
        'dist4_name', 'dist4_marks',
        'dist5_name', 'dist5_marks',
    ];
    $sample_data = [
        [1, 'Introduction to Computer Science', 'CSE 101', 3.00, 'BNQ-1001', '1st Year 1st Semester', 'Dr. John Smith',  1, 'Attendance', 10, 'Class Test', 20, 'Mid Term', 30, 'Final', 40, '', ''],
        [2, 'Mathematics I',                    'MAT 101', 3.00, 'BNQ-1002', '1st Year 1st Semester', 'Prof. Jane Doe',   2, 'Attendance', 10, 'Class Test', 20, 'Mid Term', 30, 'Final', 40, '', ''],
        [3, 'English Language Skills',           'ENG 101', 2.00, '',          '1st Year 1st Semester', '',                3, '',           '',  '',           '',  '',          '',  '',      '', '', ''],
        [4, 'Physics Lab',                       'PHY 101L',1.50, '',          '1st Year 2nd Semester', '',                4, 'Lab Work',   40, 'Lab Report', 60,  '',          '',  '',      '', '', ''],
        [5, 'Data Structures',                   'CSE 201', 3.00, 'BNQ-2001', '2nd Year 1st Semester', 'Dr. John Smith',  5, 'Attendance', 10, 'Class Test', 20, 'Mid Term',  30, 'Final', 40, '', ''],
    ];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="course_curriculum_sample.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $sample_headers, ',', '"', '\\');
    foreach ($sample_data as $r) {
        fputcsv($out, $r, ',', '"', '\\');
    }
    fclose($out);
    exit;
}

// ── Context / state ───────────────────────────────────────────────────────────
$dept_id    = (int)($_POST['dept_id']    ?? $_GET['dept_id']    ?? 0);
$program_id = (int)($_POST['program_id'] ?? $_GET['program_id'] ?? 0);
$page_title = 'Bulk Upload Subjects';
$errors     = [];
$preview    = null;

// Resolve program
$program_row = null;
if ($program_id > 0 && $dept_id > 0) {
    $st = db()->prepare(
        "SELECT p.*, d.name AS dept_name
           FROM dept_academic_programs p
           JOIN dept_departments d ON d.id = p.dept_id
          WHERE p.id = ? AND p.dept_id = ? AND p.is_active = 1
          LIMIT 1"
    );
    $st->execute([$program_id, $dept_id]);
    $program_row = $st->fetch() ?: null;
}

$departments     = cc_departments();
$programs        = $dept_id > 0 ? cc_programs($dept_id) : [];
$semester_labels = cc_semester_labels();
$dept_faculty    = $program_row ? cc_get_dept_faculty($dept_id) : [];

// Reverse-lookup: lowercase faculty name → id  (scoped to selected dept only)
$faculty_map = [];
foreach ($dept_faculty as $f) {
    $faculty_map[mb_strtolower(trim($f['name']))] = ['id' => (int)$f['id'], 'name' => $f['name']];
}

// Reverse-lookup: lowercase semester label OR bare number → semester int
$semester_map = [];
foreach ($semester_labels as $n => $lbl) {
    $semester_map[mb_strtolower(trim($lbl))] = $n;
    $semester_map[(string)$n]                = $n;
}

// ── Parse a single CSV data row ───────────────────────────────────────────────
function cc_bulk_parse_row(array $cols, array $faculty_map, array $semester_map, array $semester_labels): array
{
    $r = [
        'sl_no'          => null,
        'subject_title'  => '',
        'subject_code'   => '',
        'credit'         => null,
        'bnqf_code'      => '',
        'semester'       => 0,
        'semester_label' => '— Not Assigned —',
        'faculty_id'     => null,
        'faculty_name'   => '',
        'sort_order'     => 0,
        'distributions'  => [],
        'row_errors'     => [],
        'row_warnings'   => [],
        'status'         => 'ok',
    ];

    $sl_no        = trim($cols[0]  ?? '');
    $subj_title   = trim($cols[1]  ?? '');
    $subj_code    = trim($cols[2]  ?? '');
    $credit_raw   = trim($cols[3]  ?? '');
    $bnqf_code    = trim($cols[4]  ?? '');
    $semester_raw = trim($cols[5]  ?? '');
    $teacher_raw  = trim($cols[6]  ?? '');
    $sort_raw     = trim($cols[7]  ?? '');

    // Subject Title (required)
    if ($subj_title === '') {
        $r['row_errors'][] = 'Subject Title is required';
        $r['status'] = 'error';
    } elseif (mb_strlen($subj_title) > 300) {
        $r['row_errors'][] = 'Subject Title too long (max 300 chars)';
        $r['status'] = 'error';
    }
    $r['subject_title'] = $subj_title;
    $r['subject_code']  = mb_substr($subj_code, 0, 50);
    $r['bnqf_code']     = mb_substr($bnqf_code, 0, 50);

    // SL No (positive integer only)
    if ($sl_no !== '' && ctype_digit($sl_no) && (int)$sl_no > 0) {
        $r['sl_no'] = (int)$sl_no;
    }

    // Sort order
    $r['sort_order'] = ($sort_raw !== '' && is_numeric($sort_raw)) ? (int)$sort_raw : 0;

    // Credit
    if ($credit_raw !== '') {
        if (!is_numeric($credit_raw) || (float)$credit_raw < 0) {
            $r['row_errors'][] = 'Credit must be a non-negative number';
            $r['status'] = 'error';
        } else {
            $r['credit'] = (float)$credit_raw;
        }
    }

    // Semester – try label match first, then bare number
    if ($semester_raw !== '') {
        $key = mb_strtolower($semester_raw);
        if (isset($semester_map[$key])) {
            $r['semester']       = $semester_map[$key];
            $r['semester_label'] = $semester_labels[$r['semester']] ?? ('Semester ' . $r['semester']);
        } else {
            $r['row_warnings'][] = 'Semester "' . $semester_raw . '" not recognised — will be set to Not Assigned';
            if ($r['status'] !== 'error') {
                $r['status'] = 'warning';
            }
        }
    }

    // Course Teacher – exact name match (case-insensitive) within current dept
    if ($teacher_raw !== '') {
        $key = mb_strtolower(trim($teacher_raw));
        if (isset($faculty_map[$key])) {
            $r['faculty_id']   = $faculty_map[$key]['id'];
            $r['faculty_name'] = $faculty_map[$key]['name'];
        } else {
            $r['row_warnings'][] = 'Teacher "' . $teacher_raw . '" not found in this department — will be left unassigned';
            if ($r['status'] !== 'error') {
                $r['status'] = 'warning';
            }
        }
    }

    // Marking distributions: 5 pairs starting at col 8
    $dist_total = 0.0;
    for ($i = 0; $i < 5; $i++) {
        $dname  = trim($cols[8 + $i * 2]     ?? '');
        $dmarks = trim($cols[8 + $i * 2 + 1] ?? '');
        if ($dname === '') {
            continue;
        }
        if ($dmarks === '' || !is_numeric($dmarks) || (float)$dmarks <= 0) {
            $r['row_errors'][] = 'Distribution "' . $dname . '" has invalid or missing max marks (must be > 0)';
            $r['status'] = 'error';
        } else {
            $marks        = (float)$dmarks;
            $dist_total  += $marks;
            $r['distributions'][] = ['name' => $dname, 'marks' => $marks];
        }
    }
    if (!empty($r['distributions']) && $r['status'] !== 'error' && abs($dist_total - 100) > 0.01) {
        $r['row_warnings'][] = 'Marking distribution sums to ' . number_format($dist_total, 2) . ' (should be 100)';
        if ($r['status'] !== 'error') {
            $r['status'] = 'warning';
        }
    }

    return $r;
}

// ── Handle POST ───────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if ($action === 'preview' && $program_row) {
        if (empty($_FILES['csv_file']['name']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Please choose a CSV file to upload.';
        } else {
            $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['csv', 'txt'], true)) {
                $errors[] = 'Only CSV files are accepted (.csv or .txt).';
            } else {
                $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
                // Detect and strip UTF-8 BOM if present
                $bom = fread($handle, 3);
                if ($bom !== "\xEF\xBB\xBF") {
                    rewind($handle);
                }
                $parsed_rows = [];
                $csv_row_num = 0;
                $header_skipped = false;
                while (($cols = fgetcsv($handle, 0, ',')) !== false) {
                    $csv_row_num++;
                    if (!$header_skipped) {
                        $header_skipped = true;
                        continue; // skip header
                    }
                    // skip fully blank rows
                    if (array_filter($cols, static fn($v) => trim($v) !== '') === []) {
                        continue;
                    }
                    $parsed = cc_bulk_parse_row($cols, $faculty_map, $semester_map, $semester_labels);
                    $parsed['csv_row'] = $csv_row_num;
                    $parsed_rows[] = $parsed;
                }
                fclose($handle);

                if (empty($parsed_rows)) {
                    $errors[] = 'The CSV file contains no data rows (or only a header).';
                } else {
                    $preview = $parsed_rows;
                }
            }
        }

    } elseif ($action === 'import' && $program_row) {
        $rows_json   = $_POST['rows_json'] ?? '';
        $parsed_rows = json_decode($rows_json, true) ?? [];

        if (empty($parsed_rows)) {
            $errors[] = 'No rows to import. Please re-upload the CSV.';
        } else {
            $imported    = 0;
            $skipped     = 0;
            $auto_sl_no  = 1; // counter for rows that have no explicit sl_no

            $insert_st = db()->prepare(
                "INSERT INTO course_curriculum
                   (program_id, semester, sl_no, bnqf_code, course_code, course_name,
                    credit, assigned_faculty_id, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $dist_st = db()->prepare(
                "INSERT INTO cc_mark_distributions
                   (curriculum_id, distribution_name, max_marks, sort_order)
                 VALUES (?, ?, ?, ?)"
            );

            foreach ($parsed_rows as $row) {
                if ($row['status'] === 'error') {
                    $skipped++;
                    continue;
                }

                $insert_st->execute([
                    $program_id,
                    (int)($row['semester'] ?? 0),
                    $row['sl_no'] ?? $auto_sl_no++,
                    $row['bnqf_code'] ?: null,
                    $row['subject_code'] ?: null,
                    $row['subject_title'],
                    isset($row['credit']) ? $row['credit'] : null,
                    $row['faculty_id'] ?: null,
                    (int)($row['sort_order'] ?? 0),
                ]);
                $new_id = (int)db()->lastInsertId();

                foreach (($row['distributions'] ?? []) as $di => $dist) {
                    $dist_st->execute([$new_id, $dist['name'], $dist['marks'], $di]);
                }

                log_change(
                    'course-curriculum', 'CREATE', $new_id, $row['subject_title'],
                    null, null, null,
                    'Bulk upload: subject "' . $row['subject_title'] . '" added to program #' . $program_id
                );
                $imported++;
            }

            $msg  = 'Bulk upload complete: <strong>' . $imported . '</strong> subject' . ($imported !== 1 ? 's' : '') . ' imported';
            $msg .= $skipped > 0 ? ', <strong>' . $skipped . '</strong> row' . ($skipped !== 1 ? 's' : '') . ' skipped (errors)' : '';
            $msg .= '.';
            flash_set('success', $msg);
            redirect(APP_URL . '/course-curriculum/index.php?dept_id=' . $dept_id . '&program_id=' . $program_id);
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<!-- ── Breadcrumb ─────────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item">
                <a href="<?= APP_URL ?>/course-curriculum/index.php<?= $program_row ? '?dept_id=' . $dept_id . '&program_id=' . $program_id : '' ?>">
                    Course Curriculum
                </a>
            </li>
            <li class="breadcrumb-item active">Bulk Upload</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/course-curriculum/bulk-upload.php?download_sample=1"
       class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-download me-1"></i> Download Sample CSV
    </a>
</div>

<?php flash_show(); ?>

<!-- ── Error alert ────────────────────────────────────────────────────────── -->
<?php if (!empty($errors)): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <ul class="mb-0 ps-3">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════════════════
     STEP 1 – Select Department & Program
════════════════════════════════════════════════════════════════════════════ -->
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-filter me-2 text-muted"></i>
            Step 1 — Select Department &amp; Program
        </h6>
    </div>
    <div class="card-body p-4">
        <form method="GET" action="" id="selector-form" class="row g-3 align-items-end">
            <div class="col-12 col-md-5">
                <label class="form-label fw-medium">Department <span class="text-danger">*</span></label>
                <select name="dept_id" id="dept_select" class="form-select" required>
                    <option value="">— Select Department —</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= (int)$d['id'] === $dept_id ? 'selected' : '' ?>>
                        <?= h($d['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-5">
                <label class="form-label fw-medium">Program <span class="text-danger">*</span></label>
                <select name="program_id" id="prog_select" class="form-select" required
                        <?= $dept_id ? '' : 'disabled' ?>>
                    <option value="">— Select Program —</option>
                    <?php foreach ($programs as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= (int)$p['id'] === $program_id ? 'selected' : '' ?>>
                        <?= h($p['program_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-check me-1"></i> Select
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($dept_id && $program_id && !$program_row): ?>
<div class="alert alert-warning">Program not found or inactive.</div>
<?php endif; ?>

<?php if ($program_row): ?>

<!-- Program context banner -->
<div class="alert alert-light border mb-4 small py-2 px-3">
    <i class="fas fa-building me-1 text-muted"></i><?= h($program_row['dept_name']) ?>
    &nbsp;→&nbsp;<strong><?= h($program_row['program_name']) ?></strong>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     STEP 2 – CSV Format Guide + Upload Form
════════════════════════════════════════════════════════════════════════════ -->
<?php if ($preview === null): ?>

<div class="card mb-4" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-info-circle me-2 text-muted"></i>CSV Format Guide
        </h6>
    </div>
    <div class="card-body p-4">
        <p class="small text-muted mb-3">
            The first row is treated as a header and is always skipped.
            Columns must be in this exact order:
        </p>
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0" style="font-size:13px;">
                <thead style="background-color:#F1F5F9;">
                    <tr>
                        <th style="width:30px;">#</th>
                        <th>Column Name</th>
                        <th style="width:80px;" class="text-center">Required</th>
                        <th>Description / Accepted Values</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td>1</td><td><code>sl_no</code></td><td class="text-center">—</td><td>Serial number (integer). If blank, defaults to 1.</td></tr>
                    <tr class="table-warning"><td>2</td><td><code>subject_title</code></td><td class="text-center text-danger fw-bold">✓</td><td>Full subject name, e.g. <em>Introduction to Computer Science</em>. Max 300 chars.</td></tr>
                    <tr><td>3</td><td><code>subject_code</code></td><td class="text-center">—</td><td>Short code, e.g. <em>CSE 101</em>. Max 50 chars.</td></tr>
                    <tr><td>4</td><td><code>credit</code></td><td class="text-center">—</td><td>Numeric credit hours, e.g. <em>3.00</em>. Leave blank for none.</td></tr>
                    <tr><td>5</td><td><code>bnqf_code</code></td><td class="text-center">—</td><td>BNQF code string, e.g. <em>BNQ-1001</em>. Max 50 chars.</td></tr>
                    <tr><td>6</td><td><code>semester</code></td><td class="text-center">—</td><td>
                        Exact semester label <strong>or</strong> number 1–12.<br>
                        Labels:
                        <?php foreach ($semester_labels as $n => $lbl): ?>
                        <span class="badge bg-secondary me-1" style="font-size:11px;"><?= $n ?>&nbsp;=&nbsp;<?= h($lbl) ?></span>
                        <?php endforeach; ?>
                        <br>Leave blank for <em>Not Assigned</em>.
                    </td></tr>
                    <tr><td>7</td><td><code>course_teacher</code></td><td class="text-center">—</td><td>
                        <strong>Exact</strong> teacher name (case-insensitive) from the <strong><?= h($program_row['dept_name']) ?></strong> department.
                        Leave blank to leave unassigned.
                        <?php if (!empty($dept_faculty)): ?>
                        <details class="mt-1"><summary class="small text-primary" style="cursor:pointer;">View available teachers (<?= count($dept_faculty) ?>)</summary>
                        <ul class="mb-0 mt-1 small">
                            <?php foreach ($dept_faculty as $f): ?>
                            <li><?= h($f['name']) ?><?= $f['designation'] ? ' — ' . h($f['designation']) : '' ?></li>
                            <?php endforeach; ?>
                        </ul></details>
                        <?php endif; ?>
                    </td></tr>
                    <tr><td>8</td><td><code>sort_order</code></td><td class="text-center">—</td><td>Display order integer. Lower = shown first. Defaults to 0.</td></tr>
                    <tr><td>9–10</td><td><code>dist1_name</code>, <code>dist1_marks</code></td><td class="text-center">—</td><td rowspan="5">Up to 5 marking distribution entries. Provide name + max marks in pairs. If any distributions are provided, their marks <strong>must sum to 100</strong>.</td></tr>
                    <tr><td>11–12</td><td><code>dist2_name</code>, <code>dist2_marks</code></td><td class="text-center">—</td></tr>
                    <tr><td>13–14</td><td><code>dist3_name</code>, <code>dist3_marks</code></td><td class="text-center">—</td></tr>
                    <tr><td>15–16</td><td><code>dist4_name</code>, <code>dist4_marks</code></td><td class="text-center">—</td></tr>
                    <tr><td>17–18</td><td><code>dist5_name</code>, <code>dist5_marks</code></td><td class="text-center">—</td></tr>
                </tbody>
            </table>
        </div>
        <div class="mt-3">
            <a href="<?= APP_URL ?>/course-curriculum/bulk-upload.php?download_sample=1"
               class="btn btn-sm btn-outline-secondary">
                <i class="fas fa-download me-1"></i> Download Sample CSV
            </a>
        </div>
    </div>
</div>

<!-- Upload form -->
<div class="card" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-upload me-2 text-muted"></i>Step 2 — Upload CSV File
        </h6>
    </div>
    <div class="card-body p-4">
        <form method="POST" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action"     value="preview">
            <input type="hidden" name="dept_id"    value="<?= $dept_id ?>">
            <input type="hidden" name="program_id" value="<?= $program_id ?>">

            <div class="mb-4">
                <label class="form-label fw-medium">
                    CSV File <span class="text-danger">*</span>
                </label>
                <input type="file" name="csv_file" class="form-control" accept=".csv,.txt" required>
                <div class="form-text">Accepted: <code>.csv</code> or <code>.txt</code>. UTF-8 encoding recommended.</div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-eye me-1"></i> Preview Import
            </button>
            <a href="<?= APP_URL ?>/course-curriculum/index.php?dept_id=<?= $dept_id ?>&program_id=<?= $program_id ?>"
               class="btn btn-light ms-2">Cancel</a>
        </form>
    </div>
</div>

<?php else: /* ── STEP 3 – Preview ───────────────────────────────────────────── */ ?>

<?php
$cnt_ok      = count(array_filter($preview, fn($r) => $r['status'] === 'ok'));
$cnt_warn    = count(array_filter($preview, fn($r) => $r['status'] === 'warning'));
$cnt_error   = count(array_filter($preview, fn($r) => $r['status'] === 'error'));
$cnt_import  = $cnt_ok + $cnt_warn; // errors are skipped
?>

<!-- Summary badges -->
<div class="d-flex gap-3 mb-4 flex-wrap">
    <div class="card border-0 shadow-sm text-center" style="border-radius:10px; min-width:120px;">
        <div class="card-body py-2 px-3">
            <div class="fw-bold fs-5"><?= count($preview) ?></div>
            <div class="small text-muted">Total Rows</div>
        </div>
    </div>
    <div class="card border-0 shadow-sm text-center" style="border-radius:10px; min-width:120px; border-left:3px solid #198754 !important;">
        <div class="card-body py-2 px-3">
            <div class="fw-bold fs-5 text-success"><?= $cnt_ok ?></div>
            <div class="small text-muted">Ready</div>
        </div>
    </div>
    <?php if ($cnt_warn): ?>
    <div class="card border-0 shadow-sm text-center" style="border-radius:10px; min-width:120px; border-left:3px solid #ffc107 !important;">
        <div class="card-body py-2 px-3">
            <div class="fw-bold fs-5 text-warning"><?= $cnt_warn ?></div>
            <div class="small text-muted">Warnings</div>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($cnt_error): ?>
    <div class="card border-0 shadow-sm text-center" style="border-radius:10px; min-width:120px; border-left:3px solid #dc3545 !important;">
        <div class="card-body py-2 px-3">
            <div class="fw-bold fs-5 text-danger"><?= $cnt_error ?></div>
            <div class="small text-muted">Errors (will skip)</div>
        </div>
    </div>
    <?php endif; ?>
    <div class="card border-0 shadow-sm text-center" style="border-radius:10px; min-width:120px; border-left:3px solid #002147 !important;">
        <div class="card-body py-2 px-3">
            <div class="fw-bold fs-5" style="color:#002147;"><?= $cnt_import ?></div>
            <div class="small text-muted">Will Import</div>
        </div>
    </div>
</div>

<!-- Preview table -->
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-header py-3 px-4" style="background-color:#002147; border-radius:12px 12px 0 0;">
        <span class="fw-semibold text-white">
            <i class="fas fa-list me-2"></i>Preview — <?= count($preview) ?> row<?= count($preview) !== 1 ? 's' : '' ?>
        </span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle" style="font-size:13px;">
            <thead style="background-color:#F1F5F9;">
                <tr>
                    <th style="width:45px;" class="ps-3">Row</th>
                    <th style="width:50px;" class="text-center">Status</th>
                    <th style="width:40px;">SL</th>
                    <th>Subject Title</th>
                    <th style="width:90px;">Code</th>
                    <th style="width:60px;" class="text-center">Credit</th>
                    <th style="width:160px;">Semester</th>
                    <th style="width:160px;">Course Teacher</th>
                    <th style="width:90px;">BNQF</th>
                    <th>Marking Distribution</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($preview as $row):
                    $row_class = match($row['status']) {
                        'error'   => 'table-danger',
                        'warning' => 'table-warning',
                        default   => '',
                    };
                ?>
                <tr class="<?= $row_class ?>">
                    <td class="ps-3 text-muted small"><?= (int)($row['csv_row'] ?? 0) ?></td>
                    <td class="text-center">
                        <?php if ($row['status'] === 'ok'): ?>
                        <span class="badge bg-success" title="Ready to import"><i class="fas fa-check"></i></span>
                        <?php elseif ($row['status'] === 'warning'): ?>
                        <span class="badge bg-warning text-dark" title="Will import with warnings"><i class="fas fa-exclamation-triangle"></i></span>
                        <?php else: ?>
                        <span class="badge bg-danger" title="Will be skipped"><i class="fas fa-times"></i></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted"><?= $row['sl_no'] !== null ? h($row['sl_no']) : '—' ?></td>
                    <td class="fw-medium">
                        <?= $row['subject_title'] !== '' ? h($row['subject_title']) : '<span class="text-danger fst-italic">missing</span>' ?>
                    </td>
                    <td>
                        <?= $row['subject_code'] !== '' ? '<span class="badge bg-light text-dark border">' . h($row['subject_code']) . '</span>' : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td class="text-center">
                        <?= $row['credit'] !== null ? '<span class="badge" style="background-color:#002147;">' . h(number_format((float)$row['credit'], 2)) . '</span>' : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td class="small">
                        <?php if ($row['semester'] > 0): ?>
                        <span class="badge bg-info text-dark"><?= h($row['semester_label']) ?></span>
                        <?php else: ?>
                        <span class="text-muted">— Not Assigned —</span>
                        <?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if ($row['faculty_name'] !== ''): ?>
                        <span class="badge bg-info text-dark"><i class="fas fa-user-tie me-1"></i><?= h($row['faculty_name']) ?></span>
                        <?php else: ?>
                        <span class="text-muted">— unassigned —</span>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted"><?= $row['bnqf_code'] !== '' ? h($row['bnqf_code']) : '—' ?></td>
                    <td>
                        <?php if (!empty($row['distributions'])): ?>
                        <div class="d-flex flex-wrap gap-1">
                            <?php foreach ($row['distributions'] as $dist): ?>
                            <span class="badge rounded-pill" style="background-color:#EEF2FF; color:#3730A3; font-size:11px;">
                                <?= h($dist['name']) ?>:&nbsp;<?= h(number_format((float)$dist['marks'], 2)) ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if (!empty($row['row_errors'])): ?>
                        <ul class="mb-0 ps-3 text-danger">
                            <?php foreach ($row['row_errors'] as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                        <?php if (!empty($row['row_warnings'])): ?>
                        <ul class="mb-0 ps-3 text-warning">
                            <?php foreach ($row['row_warnings'] as $w): ?><li><?= h($w) ?></li><?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                        <?php if (empty($row['row_errors']) && empty($row['row_warnings'])): ?>
                        <span class="text-success small">✓</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Confirm import form -->
<?php if ($cnt_import > 0): ?>
<div class="card" style="border-radius:12px;">
    <div class="card-body p-4">
        <?php if ($cnt_error > 0): ?>
        <div class="alert alert-warning py-2 mb-3">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong><?= $cnt_error ?></strong> row<?= $cnt_error !== 1 ? 's' : '' ?> with errors will be skipped.
            Only the <strong><?= $cnt_import ?></strong> valid row<?= $cnt_import !== 1 ? 's' : '' ?> will be imported.
        </div>
        <?php endif; ?>
        <form method="POST" id="import-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action"     value="import">
            <input type="hidden" name="dept_id"    value="<?= $dept_id ?>">
            <input type="hidden" name="program_id" value="<?= $program_id ?>">
            <input type="hidden" name="rows_json"  id="rows-json-input" value="">

            <div class="d-flex gap-2 flex-wrap">
                <button type="submit" class="btn btn-success" id="confirm-import-btn">
                    <i class="fas fa-file-import me-1"></i>
                    Confirm &amp; Import <?= $cnt_import ?> Subject<?= $cnt_import !== 1 ? 's' : '' ?>
                </button>
                <a href="<?= APP_URL ?>/course-curriculum/bulk-upload.php?dept_id=<?= $dept_id ?>&program_id=<?= $program_id ?>"
                   class="btn btn-outline-secondary">
                    <i class="fas fa-redo me-1"></i> Re-upload CSV
                </a>
                <a href="<?= APP_URL ?>/course-curriculum/index.php?dept_id=<?= $dept_id ?>&program_id=<?= $program_id ?>"
                   class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
// Embed the parsed rows as JSON so the import step doesn't re-parse the CSV
document.getElementById('import-form').addEventListener('submit', function () {
    var rows = <?= json_encode($preview, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?>;
    document.getElementById('rows-json-input').value = JSON.stringify(rows);
});
</script>

<?php else: ?>
<div class="alert alert-danger">
    All rows have errors. Please fix your CSV and re-upload.
    <a href="<?= APP_URL ?>/course-curriculum/bulk-upload.php?dept_id=<?= $dept_id ?>&program_id=<?= $program_id ?>"
       class="btn btn-sm btn-outline-danger ms-2">Re-upload</a>
</div>
<?php endif; ?>

<?php endif; /* end preview vs form */ ?>

<?php endif; /* end program_row check */ ?>

<!-- Dynamic program loading (reuse from index.php) -->
<script>
(function () {
    var deptSel = document.getElementById('dept_select');
    var progSel = document.getElementById('prog_select');
    if (!deptSel || !progSel) return;

    deptSel.addEventListener('change', function () {
        var deptId = this.value;
        progSel.innerHTML = '<option value="">Loading…</option>';
        progSel.disabled = true;
        if (!deptId) {
            progSel.innerHTML = '<option value="">— Select Program —</option>';
            return;
        }
        fetch('<?= APP_URL ?>/course-curriculum/get-programs.php?dept_id=' + encodeURIComponent(deptId))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var opts = '<option value="">— Select Program —</option>';
                (data.programs || []).forEach(function (p) {
                    opts += '<option value="' + p.id + '">' + p.program_name + '</option>';
                });
                progSel.innerHTML = opts;
                progSel.disabled = false;
            })
            .catch(function () {
                progSel.innerHTML = '<option value="">— Error loading programs —</option>';
                progSel.disabled = false;
            });
    });
}());
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
