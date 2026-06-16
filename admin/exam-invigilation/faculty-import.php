<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('exam-invigilation', 'can_create');

$page_title = 'Bulk Import Faculty (CSV)';
$errors = [];
$import_summary = null;

$day_name_map = [
    'sun' => 0, 'sunday' => 0,
    'mon' => 1, 'monday' => 1,
    'tue' => 2, 'tues' => 2, 'tuesday' => 2,
    'wed' => 3, 'wednesday' => 3,
    'thu' => 4, 'thur' => 4, 'thurs' => 4, 'thursday' => 4,
    'fri' => 5, 'friday' => 5,
    'sat' => 6, 'saturday' => 6,
];

$normalize_header = static function (string $header): string {
    return strtolower(trim(str_replace(['-', ' '], '_', $header)));
};

$parse_weekend_days = static function (string $value) use ($day_name_map): array {
    $value = trim($value);
    if ($value === '') return [];

    $tokens = preg_split('/[|,;\/]+/', $value) ?: [];
    $days = [];
    foreach ($tokens as $token) {
        $token = strtolower(trim($token));
        if ($token === '') continue;

        if (is_numeric($token)) {
            $day = (int)$token;
            if ($day >= 0 && $day <= 6) $days[] = $day;
            continue;
        }

        if (isset($day_name_map[$token])) {
            $days[] = (int)$day_name_map[$token];
        }
    }

    $days = array_values(array_unique($days));
    sort($days);
    return $days;
};

$normalize_active = static function ($value): int {
    $v = strtolower(trim((string)$value));
    if ($v === '' || $v === '1' || $v === 'yes' || $v === 'y' || $v === 'true' || $v === 'active') return 1;
    return 0;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (!isset($_FILES['csv_file']) || (int)($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors[] = 'Please upload a valid CSV file.';
    }

    if (empty($errors)) {
        $tmp = $_FILES['csv_file']['tmp_name'];
        $fh = fopen($tmp, 'r');
        if (!$fh) {
            $errors[] = 'Unable to read uploaded CSV file.';
        } else {
            $headers = fgetcsv($fh, 0, ',', '"', '');
            if ($headers === false || empty($headers)) {
                $errors[] = 'CSV header row is missing.';
            } else {
                $header_map = [];
                foreach ($headers as $idx => $header) {
                    $header_map[$normalize_header((string)$header)] = $idx;
                }

                $required = ['dept', 'name'];
                foreach ($required as $col) {
                    if (!array_key_exists($col, $header_map)) {
                        $errors[] = "Missing required column: {$col}";
                    }
                }

                if (empty($errors)) {
                    $dept_rows = db()->query('SELECT id, name FROM dept_departments')->fetchAll();
                    $dept_by_id = [];
                    $dept_by_name = [];
                    foreach ($dept_rows as $d) {
                        $dept_by_id[(int)$d['id']] = (int)$d['id'];
                        $dept_by_name[strtolower(trim((string)$d['name']))] = (int)$d['id'];
                    }

                    $insert_st = db()->prepare(
                        'INSERT INTO ei_faculty (dept_id, name, designation, weekend_available, weekend_days, contact_number, is_active) VALUES (?,?,?,?,?,?,?)'
                    );

                    $created = 0;
                    $failed = 0;
                    $row_no = 1;
                    $row_errors = [];

                    while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
                        $row_no++;
                        $dept_raw = trim((string)($row[$header_map['dept']] ?? ''));
                        $name = trim((string)($row[$header_map['name']] ?? ''));
                        $designation = trim((string)($row[$header_map['designation']] ?? ''));
                        $contact_number = trim((string)($row[$header_map['contact_number']] ?? ''));
                        $weekend_raw = trim((string)($row[$header_map['weekend_days']] ?? ''));
                        $is_active_raw = (string)($row[$header_map['is_active']] ?? '1');

                        if ($dept_raw === '' && $name === '' && $designation === '' && $contact_number === '' && $weekend_raw === '') {
                            continue;
                        }

                        if ($name === '') {
                            $failed++;
                            $row_errors[] = "Row {$row_no}: name is required.";
                            continue;
                        }

                        $dept_id = 0;
                        if ($dept_raw !== '' && ctype_digit($dept_raw) && isset($dept_by_id[(int)$dept_raw])) {
                            $dept_id = (int)$dept_raw;
                        } else {
                            $dept_key = strtolower($dept_raw);
                            if (isset($dept_by_name[$dept_key])) $dept_id = (int)$dept_by_name[$dept_key];
                        }

                        if ($dept_id <= 0) {
                            $failed++;
                            $row_errors[] = "Row {$row_no}: invalid department '{$dept_raw}'.";
                            continue;
                        }

                        $weekend_days_arr = $parse_weekend_days($weekend_raw);
                        if ($weekend_raw !== '' && empty($weekend_days_arr)) {
                            $failed++;
                            $row_errors[] = "Row {$row_no}: invalid weekend_days '{$weekend_raw}'.";
                            continue;
                        }

                        $weekend_days = implode(',', $weekend_days_arr);
                        $weekend_available = (in_array(0, $weekend_days_arr, true) || in_array(6, $weekend_days_arr, true)) ? 0 : 1;
                        $is_active = $normalize_active($is_active_raw);

                        $insert_st->execute([
                            $dept_id,
                            $name,
                            $designation !== '' ? $designation : null,
                            $weekend_available,
                            $weekend_days,
                            $contact_number !== '' ? $contact_number : null,
                            $is_active,
                        ]);
                        $created++;
                    }

                    $import_summary = [
                        'created' => $created,
                        'failed' => $failed,
                        'row_errors' => $row_errors,
                    ];

                    if ($created > 0) {
                        flash_set('success', "CSV import complete. Added {$created} faculty record(s)." . ($failed > 0 ? " {$failed} row(s) failed." : ''));
                    } elseif ($failed > 0) {
                        flash_set('warning', "No faculty imported. {$failed} row(s) failed validation.");
                    } else {
                        flash_set('info', 'No data rows found in CSV.');
                    }
                }
            }

            fclose($fh);
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/exam-invigilation/index.php">Exam Invigilation</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/exam-invigilation/faculty.php">Faculty Pool</a></li>
            <li class="breadcrumb-item active">Bulk Import CSV</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/exam-invigilation/faculty.php" class="btn btn-light btn-sm" style="border-radius:10px;">
        <i class="fas fa-arrow-left me-1"></i> Back to Faculty Pool
    </a>
</div>

<?php flash_show(); ?>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-file-csv me-2 text-muted"></i>Import Faculty CSV</h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label fw-medium">CSV File</label>
                        <input type="file" name="csv_file" accept=".csv,text/csv" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                        <i class="fas fa-upload me-1"></i> Import CSV
                    </button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold">CSV Format</h6>
            </div>
            <div class="card-body p-4">
                <p class="mb-2">Required columns: <code>dept</code>, <code>name</code></p>
                <p class="mb-2">Optional columns: <code>designation</code>, <code>contact_number</code>, <code>weekend_days</code>, <code>is_active</code></p>
                <p class="mb-2"><code>dept</code> can be department ID or exact department name.</p>
                <p class="mb-2"><code>weekend_days</code> supports day numbers (<code>0-6</code>) or names (<code>Fri,Sat</code>).</p>
                <p class="mb-0"><code>is_active</code> supports: <code>1/0</code>, <code>yes/no</code>, <code>true/false</code>.</p>
                <hr>
                <pre class="mb-0" style="font-size:.78rem;white-space:pre-wrap;">dept,name,designation,contact_number,weekend_days,is_active
CSE,Dr. A Rahman,Professor,01700000000,"Fri,Sat",1
12,Ms. N Islam,Lecturer,01800000000,"0,6",yes</pre>
            </div>
        </div>
    </div>
</div>

<?php if ($import_summary && !empty($import_summary['row_errors'])): ?>
<div class="card mt-3">
    <div class="card-header py-2 px-4"><strong>Row Errors</strong></div>
    <div class="card-body">
        <ul class="mb-0 ps-3">
            <?php foreach ($import_summary['row_errors'] as $row_error): ?>
            <li><?= h($row_error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
