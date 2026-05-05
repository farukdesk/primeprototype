<?php
/**
 * CSV Upload & Column Mapping for Spring Result entries.
 *
 * Step 1: Upload CSV → detect headers → show mapping form
 * Step 2: Map columns → validate → import rows
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('spring-result', 'can_create');
require_once __DIR__ . '/helpers.php';

$result_id = (int)($_GET['result_id'] ?? $_POST['result_id'] ?? 0);
$result    = sr_get_result($result_id);

$page_title = 'CSV Upload – ' . $result['title'];

$errors   = [];
$step     = 'upload';       // 'upload' | 'map' | 'done'
$headers  = [];
$preview  = [];             // first 3 data rows for preview
$tmp_key  = 'sr_csv_' . $result_id;

// Handle reset before any output
if (isset($_GET['reset'])) {
    unset($_SESSION[$tmp_key]);
    redirect(APP_URL . '/spring-result/csv-upload.php?result_id=' . $result_id);
}

// ── STEP 2: Map & Import ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    csrf_check();

    $map = [
        'student_id'   => (int)($_POST['col_student_id']   ?? -1),
        'student_name' => (int)($_POST['col_student_name'] ?? -1),
        'course_code'  => (int)($_POST['col_course_code']  ?? -1),
        'course_title' => (int)($_POST['col_course_title'] ?? -1),
        'letter_grade' => (int)($_POST['col_letter_grade'] ?? -1),
        'grade_point'  => (int)($_POST['col_grade_point']  ?? -1),
    ];

    if ($map['student_id']   < 0) $errors[] = 'Please map the Student ID column.';
    if ($map['course_title'] < 0) $errors[] = 'Please map the Course Title column.';
    if ($map['letter_grade'] < 0) $errors[] = 'Please map the Letter Grade column.';

    // Retrieve cached CSV from session
    $csv_rows = $_SESSION[$tmp_key]['rows'] ?? [];
    $csv_headers = $_SESSION[$tmp_key]['headers'] ?? [];

    if (empty($csv_rows)) $errors[] = 'CSV data expired. Please upload again.';

    if (empty($errors)) {
        $inserted  = 0;
        $skipped   = 0;
        $skip_msgs = [];
        $stmt = db()->prepare(
            'INSERT INTO sr_result_entries
               (result_id, student_id, student_name, course_code, course_title, letter_grade, grade_point)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($csv_rows as $row_num => $row) {
            $student_id   = trim($row[$map['student_id']]   ?? '');
            $student_name = trim($row[$map['student_name']] ?? '');
            $course_code  = trim($row[$map['course_code']]  ?? '');
            $course_title = trim($row[$map['course_title']] ?? '');
            $letter_grade = strtoupper(trim($row[$map['letter_grade']] ?? ''));
            $grade_point  = trim($row[$map['grade_point']]  ?? '');

            // Strip parenthetical annotations added by manual exports, e.g. "A+(PLUS)" → "A+", "C(REGULAR)" → "C"
            $letter_grade = strtoupper(trim(preg_replace('/\s*\(.*?\)\s*/', '', $letter_grade)));

            // X in CSV means Incomplete; also normalise explicit INCOM to display form
            if ($letter_grade === 'X' || $letter_grade === 'INCOM') {
                $letter_grade = 'Incom';
            }

            if ($student_id === '' || $course_title === '') {
                $skipped++;
                continue;
            }
            if (!sr_valid_letter_grade($letter_grade)) {
                $skipped++;
                $skip_msgs[] = 'Row ' . ($row_num + 2) . ': Invalid grade "' . htmlspecialchars($letter_grade) . '" – skipped.';
                continue;
            }

            $gp = ($grade_point !== '' && strtoupper($letter_grade) !== 'INCOM') ? (float)$grade_point : sr_grade_point_from_letter($letter_grade);

            try {
                $stmt->execute([
                    $result_id,
                    $student_id,
                    $student_name ?: null,
                    $course_code  ?: null,
                    $course_title,
                    $letter_grade,
                    $gp,
                ]);
                $inserted++;
            } catch (Throwable $ex) {
                $skipped++;
            }
        }

        unset($_SESSION[$tmp_key]);
        $msg = $inserted . ' entr' . ($inserted === 1 ? 'y' : 'ies') . ' imported successfully.';
        if ($skipped > 0) $msg .= ' ' . $skipped . ' row(s) skipped.';
        foreach (array_slice($skip_msgs, 0, 5) as $sm) $msg .= '<br><small class="text-warning">' . $sm . '</small>';
        flash_set('success', $msg);
        redirect(APP_URL . '/spring-result/view.php?id=' . $result_id);
    }

    // Re-show map step on errors
    $step    = 'map';
    $headers = $_SESSION[$tmp_key]['headers'] ?? [];
    $preview = $_SESSION[$tmp_key]['preview'] ?? [];
}

// ── STEP 1: Upload CSV ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    csrf_check();

    $upload = $_FILES['csv_file'] ?? null;
    if (!$upload || $upload['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Please select a valid CSV file to upload.';
    } elseif (!in_array(strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)), ['csv','txt'], true)) {
        $errors[] = 'Only .csv or .txt files are accepted.';
    } elseif ($upload['size'] > 5 * 1024 * 1024) {
        $errors[] = 'File must be under 5 MB.';
    }

    if (empty($errors)) {
        $handle = fopen($upload['tmp_name'], 'r');
        if ($handle === false) {
            $errors[] = 'Could not read the uploaded file.';
        } else {
            $rows = [];
            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $rows[] = $row;
            }
            fclose($handle);

            if (count($rows) < 2) {
                $errors[] = 'The CSV file must have a header row and at least one data row.';
            } else {
                $headers = array_map('trim', $rows[0]);
                $data    = array_slice($rows, 1);
                $preview = array_slice($data, 0, 3);

                // Store in session (up to 10 000 rows to avoid memory issues)
                $_SESSION[$tmp_key] = [
                    'headers' => $headers,
                    'rows'    => array_slice($data, 0, 10000),
                    'preview' => $preview,
                ];
                $step = 'map';
            }
        }
    }
}

// Reload map step from session if navigating back
if ($step === 'upload' && !empty($_SESSION[$tmp_key])) {
    // Offer to continue with previously uploaded file
    $headers = $_SESSION[$tmp_key]['headers'];
    $preview = $_SESSION[$tmp_key]['preview'];
    $step    = 'map';
}

// Smart column auto-detection
function sr_guess_col(array $headers, array $keywords): int
{
    foreach ($headers as $i => $h) {
        $lh = strtolower($h);
        foreach ($keywords as $kw) {
            if (str_contains($lh, $kw)) return $i;
        }
    }
    return -1;
}

$auto = [];
if ($step === 'map') {
    $auto = [
        'student_id'   => sr_guess_col($headers, ['student id', 'studentid', 'student_id', 'id']),
        'student_name' => sr_guess_col($headers, ['student name', 'name', 'full name']),
        'course_code'  => sr_guess_col($headers, ['course code', 'coursecode', 'code']),
        'course_title' => sr_guess_col($headers, ['course title', 'coursetitle', 'title', 'subject']),
        'letter_grade' => sr_guess_col($headers, ['letter grade', 'lettergrade', 'grade', 'letter']),
        'grade_point'  => sr_guess_col($headers, ['grade point', 'gradepoint', 'point', 'gp']),
    ];
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/spring-result/index.php">Spring Result</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/spring-result/view.php?id=<?= $result_id ?>"><?= h($result['title']) ?></a></li>
            <li class="breadcrumb-item active">CSV Upload</li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Progress indicator -->
<div class="d-flex align-items-center gap-3 mb-4 fs-sm" style="font-size:.85rem;">
    <div class="d-flex align-items-center gap-2">
        <span class="badge rounded-pill <?= $step === 'upload' ? 'bg-primary' : 'bg-success' ?>" style="width:26px;height:26px;line-height:16px;">1</span>
        <span class="<?= $step === 'upload' ? 'fw-semibold' : 'text-muted' ?>">Upload CSV</span>
    </div>
    <div class="border-top flex-grow-1" style="max-width:60px;"></div>
    <div class="d-flex align-items-center gap-2">
        <span class="badge rounded-pill <?= $step === 'map' ? 'bg-primary' : 'bg-secondary' ?>" style="width:26px;height:26px;line-height:16px;">2</span>
        <span class="<?= $step === 'map' ? 'fw-semibold' : 'text-muted' ?>">Map Columns</span>
    </div>
    <div class="border-top flex-grow-1" style="max-width:60px;"></div>
    <div class="d-flex align-items-center gap-2">
        <span class="badge rounded-pill bg-secondary" style="width:26px;height:26px;line-height:16px;">3</span>
        <span class="text-muted">Import</span>
    </div>
</div>

<?php if ($step === 'upload'): ?>
<!-- ── STEP 1: Upload ── -->
<div class="row g-4">
    <div class="col-lg-7">
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-upload me-2 text-muted"></i>Upload CSV File</h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" enctype="multipart/form-data" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="result_id" value="<?= $result_id ?>">
                    <input type="hidden" name="action"    value="upload">

                    <div class="mb-4">
                        <label class="form-label fw-medium">CSV File <span class="text-danger">*</span></label>
                        <input type="file" name="csv_file" class="form-control" accept=".csv,.txt" required>
                        <div class="form-text">Max 5 MB. First row must be headers.</div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                        <i class="fas fa-arrow-right me-1"></i> Next: Map Columns
                    </button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-table me-2 text-muted"></i>CSV Format Reference</h6>
            </div>
            <div class="card-body p-4">
                <p class="text-muted small mb-3">Your CSV should contain the following columns (header row required, order can vary):</p>
                <table class="table table-sm mb-3" style="font-size:.8rem;">
                    <thead class="table-light"><tr><th>Column</th><th>Example</th><th>Required</th></tr></thead>
                    <tbody>
                        <tr><td>Student ID</td><td>193020101021</td><td><span class="badge bg-danger">Yes</span></td></tr>
                        <tr><td>Student Name</td><td>John Doe</td><td><span class="badge bg-secondary">No</span></td></tr>
                        <tr><td>Course Code</td><td>CSE-101</td><td><span class="badge bg-secondary">No</span></td></tr>
                        <tr><td>Course Title</td><td>Intro to Programming</td><td><span class="badge bg-danger">Yes</span></td></tr>
                        <tr><td>Letter Grade</td><td>A+</td><td><span class="badge bg-danger">Yes</span></td></tr>
                        <tr><td>Grade Point</td><td>4.00</td><td><span class="badge bg-secondary">No</span></td></tr>
                    </tbody>
                </table>
                <div class="alert alert-info py-2 px-3 mb-0" style="font-size:.8rem;">
                    <i class="fas fa-info-circle me-1"></i>
                    Grade Point is auto-calculated from Letter Grade if not provided.
                    Valid grades: A+, A, A-, B+, B, B-, C+, C, D, F.
                    Use <strong>X</strong> for Incomplete (stored as <em>Incom</em>).
                </div>
            </div>
        </div>
    </div>
</div>

<?php elseif ($step === 'map'): ?>
<!-- ── STEP 2: Map Columns ── -->
<div class="row g-4">
    <div class="col-lg-8">
        <form method="POST" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="result_id" value="<?= $result_id ?>">
            <input type="hidden" name="action"    value="import">

            <div class="card mb-4" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-sliders-h me-2 text-muted"></i>Map CSV Columns</h6>
                </div>
                <div class="card-body p-4">
                    <p class="text-muted small mb-4">
                        Match each required field to the corresponding column in your CSV.
                        Auto-detection has pre-selected the best match where possible.
                    </p>

                    <?php
                    $field_labels = [
                        'student_id'   => ['Student ID',   true],
                        'student_name' => ['Student Name', false],
                        'course_code'  => ['Course Code',  false],
                        'course_title' => ['Course Title', true],
                        'letter_grade' => ['Letter Grade', true],
                        'grade_point'  => ['Grade Point',  false],
                    ];
                    ?>
                    <div class="row g-3">
                    <?php foreach ($field_labels as $key => [$label, $required]): ?>
                    <div class="col-md-6">
                        <label class="form-label fw-medium">
                            <?= $label ?>
                            <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                        </label>
                        <select name="col_<?= $key ?>" class="form-select" <?= $required ? 'required' : '' ?>>
                            <option value="-1">— Not Mapped —</option>
                            <?php foreach ($headers as $i => $h): ?>
                            <option value="<?= $i ?>" <?= ($auto[$key] === $i) ? 'selected' : '' ?>>
                                <?= h($h) ?> (col <?= $i + 1 ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Preview -->
            <?php if (!empty($preview)): ?>
            <div class="card mb-4" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-eye me-2 text-muted"></i>Data Preview (first <?= count($preview) ?> rows)</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0" style="font-size:.8rem;">
                            <thead class="table-light">
                                <tr>
                                    <?php foreach ($headers as $h): ?>
                                    <th class="px-3"><?= h($h) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($preview as $row): ?>
                                <tr>
                                    <?php foreach ($row as $cell): ?>
                                    <td class="px-3"><?= h($cell) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-success" style="border-radius:10px;">
                    <i class="fas fa-cloud-upload-alt me-1"></i>
                    Import <?= count($_SESSION[$tmp_key]['rows'] ?? []) ?> Rows
                </button>
                <a href="<?= APP_URL ?>/spring-result/csv-upload.php?result_id=<?= $result_id ?>&reset=1"
                   class="btn btn-light" style="border-radius:10px;"
                   onclick="return confirm('Discard uploaded file and start over?')">
                    <i class="fas fa-redo me-1"></i> Upload Different File
                </a>
            </div>
        </form>
    </div>

    <div class="col-lg-4">
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-info-circle me-2 text-muted"></i>Import Notes</h6>
            </div>
            <div class="card-body p-4 text-muted" style="font-size:.85rem;line-height:1.8;">
                <ul class="ps-3 mb-0">
                    <li>Rows with empty Student ID or Course Title are skipped.</li>
                    <li>Rows with invalid Letter Grade are skipped.</li>
                    <li>Grade Point is auto-computed if not mapped.</li>
                    <li>Duplicate rows (same student + course) are inserted as separate entries.</li>
                    <li>Up to 10,000 rows per upload.</li>
                </ul>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
