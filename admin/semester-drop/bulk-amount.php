<?php
/**
 * Semester Drop – Bulk Semester Drop by Amount (CSV)
 * ================================================================
 * Bulk-create semester drops from a CSV where each row carries only:
 *
 *     Student ID, Semester Drop Amount
 *
 * The number of dropped months is derived from the student's own fee package:
 *
 *     months = round(Semester Drop Amount ÷ monthly fee)
 *
 * Always a whole number (standard rounding, never fractions). The monthly fee
 * is the Semester 1 monthly fee from the student's fee summary, and the drop
 * window ALWAYS starts at the student's very first schedule month (the fee
 * package payment start).
 *
 * Workflow is two steps, mirroring the standard bulk upload:
 *
 *   1. Upload  → every row is validated and shown in a colour-coded preview
 *      with the full calculation (amount ÷ monthly fee → months → window).
 *   2. Confirm → only the rows that passed validation are created.
 *
 * A row is SKIPPED (never duplicated) when:
 *   • the student already has an ACTIVE semester drop overlapping the window,
 *   • the student's account is frozen by an active dropout, or
 *   • an earlier row in the same file already covers the student.
 * Re-uploading the same file is therefore safe.
 *
 * Evidence cannot be attached in bulk, so — matching the single-record policy
 * (evidence is mandatory unless recorded by a Super Administrator) — only a
 * Super Administrator can CONFIRM. Any user with create access can preview.
 */

require_once __DIR__ . '/../includes/auth.php';
require_access('semester-drop', 'can_create');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../accounting/helpers.php'; // acc_student_fee_summary(), acc_package_payment_start()

$page_title = 'Bulk Semester Drop by Amount';
$db       = db();
$is_super = is_super_admin();
$me       = auth_user();

// ── Sample CSV template download ────────────────────────────────────────
if (isset($_GET['sample'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="semester-drop-by-amount-sample.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student ID', 'Semester Drop Amount'], ',', '"', '\\');
    fputcsv($out, ['02826105101071', '28200'], ',', '"', '\\');
    fputcsv($out, ['02826105101072', '18,800.00'], ',', '"', '\\');
    fclose($out);
    exit;
}

/**
 * Parse an amount cell ("28,200", "৳ 28200.50", "28200") into a positive float.
 */
function sdba_parse_amount(string $raw): ?float
{
    $s = preg_replace('/[^0-9.\-]/', '', trim($raw));
    if ($s === '' || !is_numeric($s)) {
        return null;
    }
    $v = (float)$s;
    return $v > 0 ? $v : null;
}

/**
 * Look up a student by ID, tolerant of leading zeros (the old ERP and the
 * current system sometimes store the same ID with or without them).
 */
function sdba_lookup_student(string $sid): ?array
{
    $sid = trim($sid);
    if ($sid === '') {
        return null;
    }
    $stmt = db()->prepare('SELECT id, student_id, full_name, status FROM students WHERE student_id = ? LIMIT 1');
    $stmt->execute([$sid]);
    $stu = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($stu) {
        return $stu;
    }
    $stmt = db()->prepare(
        "SELECT id, student_id, full_name, status
           FROM students
          WHERE TRIM(LEADING '0' FROM student_id) = TRIM(LEADING '0' FROM ?)
          LIMIT 1"
    );
    $stmt->execute([$sid]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Monthly fee, schedule start and programme length for a student, read from
 * their fee package. Cached per request (the fee summary is expensive).
 *
 * The monthly fee is the SEMESTER 1 monthly fee (tuition payable + fixed +
 * English portions ÷ months per semester) — the drop starts at the beginning
 * of the schedule, so Semester 1 is the correct basis.
 *
 * @return array{monthly_fee: float, start: string, total_months: int}|null
 *         null when no package is assigned or the fee cannot be determined.
 */
function sdba_student_fee_basis(int $student_pk): ?array
{
    static $cache = [];
    if (array_key_exists($student_pk, $cache)) {
        return $cache[$student_pk];
    }
    $basis = null;
    try {
        $summary = acc_student_fee_summary($student_pk);
        if ($summary && !empty($summary['semesters'])) {
            $pkg         = $summary['package'];
            $monthly_fee = (float)($summary['semesters'][0]['monthly_fee'] ?? 0);
            $start       = acc_package_payment_start($pkg, $summary['semesters']);
            if ($monthly_fee > 0 && (int)$start['year'] > 0 && (int)$start['month'] >= 1 && (int)$start['month'] <= 12) {
                $basis = [
                    'monthly_fee'  => $monthly_fee,
                    'start'        => sprintf('%04d-%02d-01', (int)$start['year'], (int)$start['month']),
                    'total_months' => (int)($pkg['total_months'] ?? 0),
                ];
            }
        }
    } catch (Throwable $e) {
        $basis = null;
    }
    return $cache[$student_pk] = $basis;
}

/**
 * Read CSV text into logical rows keyed by our fields.
 *
 * @return array{rows:array<int,array<string,mixed>>, error:?string}
 */
function sdba_read_csv(string $csv_text): array
{
    $csv_text = preg_replace("/^\xEF\xBB\xBF/", '', $csv_text);
    $csv_text = str_replace(["\r\n", "\r"], "\n", $csv_text);
    $lines = array_values(array_filter(explode("\n", $csv_text), static fn($l) => trim($l) !== ''));
    if (!$lines) {
        return ['rows' => [], 'error' => 'The CSV file is empty.'];
    }
    $parsed = array_map(static fn($l) => str_getcsv($l, ',', '"', ''), $lines);
    $header = array_map(static fn($c) => strtolower(trim((string)$c)), $parsed[0]);

    $find = static function (array $needles) use ($header): ?int {
        foreach ($header as $i => $name) {
            foreach ($needles as $needle) {
                if ($name === $needle || str_contains($name, $needle)) {
                    return $i;
                }
            }
        }
        return null;
    };
    $col_student = $find(['student id', 'studentid', 'student']);
    $col_amount  = $find(['semester drop amount', 'drop amount', 'amount']);

    $missing = [];
    if ($col_student === null) { $missing[] = 'Student ID'; }
    if ($col_amount === null)  { $missing[] = 'Semester Drop Amount'; }
    if ($missing) {
        return ['rows' => [], 'error' => 'The CSV header is missing required column(s): ' . implode(', ', $missing)
            . '. Expected columns: Student ID, Semester Drop Amount.'];
    }

    $rows  = [];
    $count = count($parsed);
    for ($i = 1; $i < $count; $i++) {
        $r = $parsed[$i];
        $rows[] = [
            'row_no'     => $i + 1,
            'student_id' => trim((string)($r[$col_student] ?? '')),
            'amount'     => trim((string)($r[$col_amount] ?? '')),
        ];
    }
    return ['rows' => $rows, 'error' => null];
}

/**
 * Validate every CSV row against the database and the rows before it.
 *
 * Statuses: 'create' (will be created), 'skip' (already covered — never
 * duplicated), 'invalid' (bad data).
 *
 * @return array{results:array<int,array<string,mixed>>, counts:array<string,int>}
 */
function sdba_validate_rows(array $rows): array
{
    $results = [];
    $counts  = ['create' => 0, 'skip' => 0, 'invalid' => 0];
    $lookup  = [];        // sid string => student row | null
    $file_windows = [];   // student_pk => [[start, end, row_no], ...]

    $ov_stmt = db()->prepare(
        "SELECT drop_start, drop_end
           FROM semester_drops
          WHERE student_id = ? AND status = 'active' AND kind = 'drop'
            AND drop_start <= ? AND drop_end >= ?
          LIMIT 1"
    );

    foreach ($rows as $row) {
        // Fully blank lines are silently ignored.
        if ($row['student_id'] === '' && $row['amount'] === '') {
            continue;
        }

        $notes  = [];
        $status = 'create';
        $res    = [
            'student_pk'   => null,
            'student_name' => '',
            'sid'          => $row['student_id'],
            'amount'       => null,
            'monthly_fee'  => null,
            'months_exact' => null,
            'months'       => null,
            'start'        => null,
            'end'          => null,
        ];

        if (!array_key_exists($row['student_id'], $lookup)) {
            $lookup[$row['student_id']] = sdba_lookup_student($row['student_id']);
        }
        $stu = $lookup[$row['student_id']];
        if (!$stu) {
            $status  = 'invalid';
            $notes[] = 'Student ID not found.';
        } else {
            $res['student_pk']   = (int)$stu['id'];
            $res['student_name'] = (string)$stu['full_name'];
            $res['sid']          = (string)$stu['student_id'];
        }

        $amount = sdba_parse_amount($row['amount']);
        if ($amount === null) {
            $status  = 'invalid';
            $notes[] = 'Semester Drop Amount must be a positive number.';
        } else {
            $res['amount'] = $amount;
        }

        if ($status === 'create') {
            $pk = (int)$res['student_pk'];

            // Frozen accounts never receive a drop.
            if (sd_student_dropped_out($pk)) {
                $status  = 'skip';
                $notes[] = 'Account is frozen by an active dropout — skipped.';
            }

            // Fee basis: monthly fee + schedule start from the fee package.
            $basis = null;
            if ($status === 'create') {
                $basis = sdba_student_fee_basis($pk);
                if (!$basis) {
                    $status  = 'invalid';
                    $notes[] = 'No fee package assigned (or monthly fee is zero) — cannot calculate months. Assign a package in Student Accounts first.';
                }
            }

            if ($status === 'create') {
                $res['monthly_fee']  = $basis['monthly_fee'];
                $exact               = $amount / $basis['monthly_fee'];
                $months              = (int)round($exact);
                $res['months_exact'] = $exact;

                if ($months < 1) {
                    $status  = 'invalid';
                    $notes[] = 'Amount (' . number_format($amount, 2) . ') is less than half of one monthly fee ('
                        . number_format($basis['monthly_fee'], 2) . ') — rounds to 0 months.';
                } elseif ($basis['total_months'] > 0 && $months > $basis['total_months']) {
                    $status  = 'invalid';
                    $notes[] = 'Calculated ' . $months . ' months exceeds the programme length ('
                        . $basis['total_months'] . ' months) — please check the amount.';
                } else {
                    $res['months'] = $months;
                    $res['start']  = $basis['start'];
                    $res['end']    = sd_compute_end_months($basis['start'], $months);
                }
            }

            // Already created: an active drop overlapping this window exists.
            if ($status === 'create') {
                $ov_stmt->execute([$pk, $res['end'], $res['start']]);
                $ov = $ov_stmt->fetch(PDO::FETCH_ASSOC);
                if ($ov) {
                    $status  = 'skip';
                    $notes[] = 'Already has an active semester drop overlapping this window ('
                        . date('d M Y', strtotime((string)$ov['drop_start'])) . ' → '
                        . date('d M Y', strtotime((string)$ov['drop_end'])) . ') — skipped.';
                }
            }

            // Duplicate within the same file: first row wins.
            if ($status === 'create') {
                foreach ($file_windows[$pk] ?? [] as $w) {
                    if ($w[0] <= $res['end'] && $w[1] >= $res['start']) {
                        $status  = 'skip';
                        $notes[] = 'Overlaps an earlier row in this file (row ' . $w[2] . ') — skipped.';
                        break;
                    }
                }
            }

            if ($status === 'create') {
                $file_windows[$pk][] = [$res['start'], $res['end'], $row['row_no']];
                $notes[] = number_format($amount, 2) . ' ÷ ' . number_format($basis['monthly_fee'], 2)
                    . '/month = ' . number_format($exact, 2) . ' → ' . $months . ' month(s), starting from the schedule start ('
                    . date('d M Y', strtotime((string)$res['start'])) . ' → ' . date('d M Y', strtotime((string)$res['end'])) . ').';
            }
        }

        $counts[$status]++;
        $results[] = [
            'row_no'   => (int)$row['row_no'],
            'input'    => $row,
            'status'   => $status,
            'notes'    => $notes,
            'resolved' => $res,
        ];
    }
    return ['results' => $results, 'counts' => $counts];
}

// ── Handle POST (preview / confirm) ─────────────────────────────────────
$errors         = [];
$results        = null;
$counts         = ['create' => 0, 'skip' => 0, 'invalid' => 0];
$csv_b64        = '';
$did_commit     = false;
$commit_summary = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action   = (string)($_POST['action'] ?? '');
    $csv_text = '';

    if ($action === 'preview') {
        if (!isset($_FILES['csv_file']) || ($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'Please choose a CSV file to upload.';
        } elseif ((int)$_FILES['csv_file']['size'] > 5 * 1024 * 1024) {
            $errors[] = 'The CSV file is larger than 5 MB.';
        } else {
            $csv_text = (string)file_get_contents($_FILES['csv_file']['tmp_name']);
        }
    } elseif ($action === 'confirm') {
        $csv_text = (string)base64_decode((string)($_POST['csv_data'] ?? ''), true);
        if ($csv_text === '') {
            $errors[] = 'The uploaded CSV could not be recovered. Please upload it again.';
        }
        if (!$is_super) {
            $errors[] = 'Only a Super Administrator can confirm a bulk upload — evidence cannot be attached in bulk, and evidence is mandatory for other users.';
        }
    }

    if (!$errors && $csv_text !== '') {
        $parsed = sdba_read_csv($csv_text);
        if ($parsed['error'] !== null) {
            $errors[] = $parsed['error'];
        } else {
            // Re-validation on confirm guarantees rows created in the meantime
            // (or by a double submit) are skipped, never duplicated.
            $validated = sdba_validate_rows($parsed['rows']);
            $results   = $validated['results'];
            $counts    = $validated['counts'];
            $csv_b64   = base64_encode($csv_text);

            if ($action === 'confirm') {
                $created = 0;
                $failed  = [];
                foreach ($results as $i => $r) {
                    if ($r['status'] !== 'create') {
                        continue;
                    }
                    $res = $r['resolved'];
                    $auto_reason = 'Bulk drop by amount: ' . number_format((float)$res['amount'], 2)
                        . ' ÷ ' . number_format((float)$res['monthly_fee'], 2) . '/month = '
                        . number_format((float)$res['months_exact'], 2) . ' → ' . (int)$res['months']
                        . ' month(s) from schedule start.';
                    try {
                        sd_create_drop_custom(
                            (int)$res['student_pk'],
                            (int)$res['months'],
                            (string)$res['start'],
                            $auto_reason,
                            null,           // no evidence in bulk (Super Admin only)
                            (int)$me['id']
                        );
                        $created++;
                        $results[$i]['notes'][] = 'Created.';
                    } catch (Throwable $e) {
                        $failed[] = 'Row ' . $r['row_no'] . ' (' . $res['sid'] . '): ' . $e->getMessage();
                        $results[$i]['status']  = 'invalid';
                        $results[$i]['notes'][] = 'Failed: ' . $e->getMessage();
                        $counts['create']--;
                        $counts['invalid']++;
                    }
                }
                $did_commit     = true;
                $commit_summary = ['created' => $created, 'failed' => $failed];
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0" style="font-size:.83rem;">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/semester-drop/index.php">Semester Drop</a></li>
        <li class="breadcrumb-item active">Bulk Drop by Amount</li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="h3 mb-0"><i class="fas fa-coins me-2 text-warning"></i>Bulk Semester Drop by Amount</h1>
    <a href="<?= APP_URL ?>/semester-drop/index.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i> Back to Semester Drop
    </a>
</div>

<?= flash_show() ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="alert alert-info">
    <div class="d-flex gap-3">
        <div class="fs-4 text-info"><i class="fas fa-info-circle"></i></div>
        <div class="small flex-grow-1">
            <strong>Create semester drops in bulk from a CSV, calculated by amount.</strong>
            <ul class="mb-2 mt-1 ps-3">
                <li>Columns: <code>Student ID</code>, <code>Semester Drop Amount</code>.</li>
                <li><strong>Months = Amount ÷ Monthly Fee, rounded to a whole number</strong> (the Semester 1 monthly fee from the student's fee package — no fractions, ever).</li>
                <li>The drop window always starts from the student's <strong>first schedule month</strong> (fee package payment start).</li>
                <li><strong>Safe to re-run:</strong> a row is skipped when the student already has an active semester drop overlapping the window, so nothing is ever duplicated.</li>
                <li>Students frozen by an active <strong>dropout</strong> are skipped; duplicate rows within the file are skipped (first row wins). Student IDs are matched with or without leading zeros.</li>
                <li>Dropped months are <strong>deferred, not waived</strong> — exactly like a drop recorded one-by-one.</li>
                <li>Evidence cannot be attached in bulk, so only a <strong>Super Administrator</strong> can confirm the upload.</li>
            </ul>
            <a href="<?= APP_URL ?>/semester-drop/bulk-amount.php?sample=1" class="alert-link">
                <i class="fas fa-download me-1"></i>Download a sample CSV template
            </a>
        </div>
    </div>
</div>

<!-- ── Upload form ── -->
<div class="card mb-4">
    <div class="card-header py-3 fw-semibold">
        <i class="fas fa-upload me-2 text-primary"></i>Upload CSV
    </div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="preview">
            <div class="col-md-8">
                <label class="form-label fw-semibold">CSV File <span class="text-danger">*</span></label>
                <input type="file" name="csv_file" accept=".csv,text/csv" class="form-control" required>
                <div class="form-text">Columns: Student ID, Semester Drop Amount. Max 5 MB.</div>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-warning w-100">
                    <i class="fas fa-search me-1"></i> Preview &amp; Validate
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($results !== null): ?>
<!-- ── Preview / result ── -->
<div class="card mb-4">
    <div class="card-header py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-semibold"><i class="fas fa-table me-2 text-primary"></i><?= $did_commit ? 'Upload Result' : 'Preview' ?></span>
        <div class="d-flex gap-2 small">
            <span class="badge bg-success">Will create: <?= (int)$counts['create'] ?></span>
            <span class="badge bg-warning text-dark">Skipped (already exists): <?= (int)$counts['skip'] ?></span>
            <span class="badge bg-danger">Invalid: <?= (int)$counts['invalid'] ?></span>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if ($did_commit && $commit_summary): ?>
        <div class="alert alert-<?= $commit_summary['failed'] ? 'warning' : 'success' ?> m-3">
            <strong><?= (int)$commit_summary['created'] ?></strong> semester drop(s) created.
            <?= (int)$counts['skip'] ?> row(s) skipped (already covered), <?= (int)$counts['invalid'] ?> invalid.
            <?php if (!empty($commit_summary['failed'])): ?>
            <div class="mt-2"><strong>Failures:</strong><ul class="mb-0"><?php foreach ($commit_summary['failed'] as $f): ?><li><?= h($f) ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="d-flex flex-wrap gap-2 align-items-center px-3 py-3 border-bottom">
            <div class="btn-group btn-group-sm" role="group" aria-label="Filter preview rows">
                <button type="button" class="btn btn-outline-secondary active" data-sdba-filter="all">All</button>
                <button type="button" class="btn btn-outline-success" data-sdba-filter="create">Create (<?= (int)$counts['create'] ?>)</button>
                <button type="button" class="btn btn-outline-warning" data-sdba-filter="skip">Skipped (<?= (int)$counts['skip'] ?>)</button>
                <button type="button" class="btn btn-outline-danger" data-sdba-filter="invalid">Invalid (<?= (int)$counts['invalid'] ?>)</button>
            </div>
            <input type="search" id="sdba-search" class="form-control form-control-sm" style="max-width: 260px;" placeholder="Search Student ID or name…" aria-label="Search preview rows">
            <span class="small text-muted ms-auto" id="sdba-filter-count"></span>
        </div>
        <div class="table-responsive" style="max-height: 65vh;">
            <table class="table table-sm table-hover mb-0 align-middle" id="sdba-preview-table">
                <thead class="table-light" style="position: sticky; top: 0; z-index: 1;">
                    <tr>
                        <th>#</th>
                        <th>Status</th>
                        <th>Student ID</th>
                        <th>Student</th>
                        <th class="text-end">Amount</th>
                        <th class="text-end">Monthly Fee</th>
                        <th class="text-center">Months</th>
                        <th>Drop Start</th>
                        <th>Drop End</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $r):
                        $res = $r['resolved'];
                        $row_class = match ($r['status']) {
                            'create' => 'table-success',
                            'skip'   => 'table-warning',
                            default  => 'table-danger',
                        };
                        $badge = match ($r['status']) {
                            'create' => '<span class="badge bg-success">' . ($did_commit ? 'Created' : 'Create') . '</span>',
                            'skip'   => '<span class="badge bg-warning text-dark">Skipped</span>',
                            default  => '<span class="badge bg-danger">Invalid</span>',
                        };
                    ?>
                    <tr class="<?= $row_class ?>" data-status="<?= h($r['status']) ?>" data-search="<?= h(strtolower((string)$res['sid'] . ' ' . (string)$res['student_name'])) ?>">
                        <td><?= (int)$r['row_no'] ?></td>
                        <td><?= $badge ?></td>
                        <td class="fw-semibold"><?= h((string)$res['sid']) ?></td>
                        <td><?= h($res['student_name'] !== '' ? $res['student_name'] : '—') ?></td>
                        <td class="text-end"><?= $res['amount'] !== null ? h(number_format((float)$res['amount'], 2)) : h((string)$r['input']['amount']) ?></td>
                        <td class="text-end"><?= $res['monthly_fee'] !== null ? h(number_format((float)$res['monthly_fee'], 2)) : '—' ?></td>
                        <td class="text-center">
                            <?php if ($res['months'] !== null): ?>
                            <span class="fw-semibold"><?= (int)$res['months'] ?></span>
                            <?php if ($res['months_exact'] !== null && abs($res['months_exact'] - (int)$res['months']) > 0.005): ?>
                            <small class="text-muted d-block">(<?= h(number_format((float)$res['months_exact'], 2)) ?>)</small>
                            <?php endif; ?>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><?= $res['start'] !== null ? h(date('d M Y', strtotime((string)$res['start']))) : '—' ?></td>
                        <td><?= $res['end'] !== null ? h(date('d M Y', strtotime((string)$res['end']))) : '—' ?></td>
                        <td class="small"><?= h(implode(' ', $r['notes'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="text-center py-2 border-top d-none" id="sdba-more-wrap">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="sdba-show-more"></button>
        </div>
    </div>
    <?php if (!$did_commit): ?>
    <div class="card-footer py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="text-muted small">
            Only the <strong><?= (int)$counts['create'] ?></strong> green row(s) will be created. Skipped and invalid rows are left untouched.
            <?php if (!$is_super): ?>
            <span class="text-danger d-block"><i class="fas fa-lock me-1"></i>Only a Super Administrator can confirm a bulk upload.</span>
            <?php endif; ?>
        </span>
        <form method="post" onsubmit="return confirm('Create <?= (int)$counts['create'] ?> semester drop(s) calculated by amount? Skipped and invalid rows will be left untouched.');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="confirm">
            <input type="hidden" name="csv_data" value="<?= h($csv_b64) ?>">
            <button type="submit" class="btn btn-warning" <?= ($counts['create'] > 0 && $is_super) ? '' : 'disabled' ?>>
                <i class="fas fa-check me-1"></i> Confirm &amp; Create <?= (int)$counts['create'] ?> Drop(s)
            </button>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
(function () {
    'use strict';
    var table = document.getElementById('sdba-preview-table');
    if (!table) { return; }
    var rows     = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
    var buttons  = Array.prototype.slice.call(document.querySelectorAll('[data-sdba-filter]'));
    var search   = document.getElementById('sdba-search');
    var countEl  = document.getElementById('sdba-filter-count');
    var moreWrap = document.getElementById('sdba-more-wrap');
    var moreBtn  = document.getElementById('sdba-show-more');
    var PAGE     = 300;
    var limit    = PAGE;
    var active   = 'all';
    var timer    = null;

    // Only PAGE matching rows are laid out at a time; "Show more" reveals the
    // next chunk, so even very large CSVs never freeze the page.
    function apply() {
        var q = (search && search.value ? search.value : '').trim().toLowerCase();
        var matched = 0, shown = 0;
        rows.forEach(function (tr) {
            var ok = (active === 'all' || tr.getAttribute('data-status') === active)
                && (q === '' || (tr.getAttribute('data-search') || '').indexOf(q) !== -1);
            var show = false;
            if (ok) {
                matched++;
                show = matched <= limit;
            }
            tr.style.display = show ? '' : 'none';
            if (show) { shown++; }
        });
        if (countEl) {
            countEl.textContent = 'Showing ' + shown + ' of ' + matched + ' matching row(s) — ' + rows.length + ' total';
        }
        if (moreWrap && moreBtn) {
            var left = matched - shown;
            moreWrap.classList.toggle('d-none', left <= 0);
            if (left > 0) {
                moreBtn.textContent = 'Show ' + Math.min(PAGE, left) + ' more row(s) (' + left + ' hidden)';
            }
        }
    }

    buttons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            active = btn.getAttribute('data-sdba-filter') || 'all';
            limit = PAGE;
            buttons.forEach(function (b) { b.classList.toggle('active', b === btn); });
            apply();
        });
    });
    if (search) {
        search.addEventListener('input', function () {
            if (timer) { clearTimeout(timer); }
            timer = setTimeout(function () { limit = PAGE; apply(); }, 200);
        });
    }
    if (moreBtn) {
        moreBtn.addEventListener('click', function () { limit += PAGE; apply(); });
    }
    apply();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
