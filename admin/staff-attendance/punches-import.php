<?php
/**
 * Staff Attendance → Devices → Import Punches (CSV).
 *
 * Bulk-import historical punches from a CSV with the columns:
 *   Date (DD/MM/YYYY) , Employee ID , In Time , Out Time
 *
 * Employee IDs are matched against staff_profiles.employee_id. Two-step flow:
 *   1. Upload → a PREVIEW lists exactly which Employee ID resolved to which
 *      staff member and which punch date/times would be created. Nothing is
 *      written to the database at this stage.
 *   2. Confirm → punches are stored in att_punch_log (device_id = 0, serial
 *      "CSV-IMPORT"; the unique (device, pin, punch_time) key makes re-imports
 *      idempotent) and each affected user/day is re-folded into att_records
 *      (earliest punch = in, latest = out) via adms_fold_day().
 *
 * Requires can_edit (module admin), same as devices.php.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('staff-attendance', 'can_edit');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/adms-helpers.php';

$page_title = 'Import Punches (CSV)';
$db         = db();

const PUNCH_IMPORT_MAX_ROWS = 5000; // hard cap per upload

// ---------------------------------------------------------------------------
// Parsing helpers.
// ---------------------------------------------------------------------------

// Header aliases (normalised: lower-case, -/_ collapsed to spaces).
$normalize_header = static function (string $header): string {
    $h = strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $header)));
    $h = preg_replace('/[_\-]+/', ' ', $h);
    return preg_replace('/\s+/', ' ', (string)$h);
};
$date_aliases = ['date', 'punch date', 'work date', 'attendance date'];
$emp_aliases  = ['employee id', 'employeeid', 'employee', 'emp id', 'empid', 'staff id', 'id', 'employee no', 'employee number'];
$in_aliases   = ['in time', 'intime', 'in', 'time in', 'check in', 'checkin'];
$out_aliases  = ['out time', 'outtime', 'out', 'time out', 'check out', 'checkout'];

// DD/MM/YYYY (also D/M/YYYY, DD-MM-YYYY, DD.MM.YYYY) or ISO YYYY-MM-DD → Y-m-d.
$parse_date = static function (string $s): ?string {
    $s = trim($s);
    if ($s === '') return null;
    if (preg_match('~^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{4})$~', $s, $m)) {
        [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }
    if (preg_match('~^(\d{4})-(\d{1,2})-(\d{1,2})$~', $s, $m)) {
        [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }
    return null;
};

// HH:MM[:SS] with optional AM/PM → H:i:s. Empty cell returns '' (no punch).
$parse_time = static function (string $s): ?string {
    $s = strtoupper(trim($s));
    if ($s === '' || $s === '-') return '';
    if (preg_match('~^(\d{1,2})[:.](\d{2})(?:[:.](\d{2}))?\s*(AM|PM)?$~', $s, $m)) {
        $h   = (int)$m[1];
        $i   = (int)$m[2];
        $sec = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : 0;
        $ap  = $m[4] ?? '';
        if ($ap === 'PM' && $h < 12)  $h += 12;
        if ($ap === 'AM' && $h === 12) $h = 0;
        if ($h > 23 || $i > 59 || $sec > 59) return null;
        return sprintf('%02d:%02d:%02d', $h, $i, $sec);
    }
    return null;
};

// Canonicalise an employee id for matching: strip invisible characters
// (NBSP, zero-width, BOM), trim, lower-case, and drop leading zeros on purely
// numeric ids so a profile id of "028620" matches "28620" in the CSV (Excel
// strips leading zeros from numeric cells) and vice-versa.
$canon_emp = static function (string $s): string {
    $s = preg_replace('/[\x{00A0}\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', '', $s) ?? $s;
    $s = strtolower(trim($s));
    if ($s !== '' && ctype_digit($s)) {
        $s = ltrim($s, '0');
        if ($s === '') $s = '0';
    }
    return $s;
};

// Staff pool indexed by canonical employee id. Ambiguous ids are kept as
// multi-entry lists and flagged rather than guessed.
$emp_index   = [];
$valid_users = []; // uid => full_name (for re-validating the confirmed plan)
foreach (att_mappable_users() as $u) {
    $valid_users[(int)$u['id']] = (string)$u['full_name'];
    $eid = trim((string)($u['employee_id'] ?? ''));
    if ($eid === '') continue;
    $key = $canon_emp($eid);
    if ($key === '') continue;
    $emp_index[$key][] = ['id' => (int)$u['id'], 'name' => (string)$u['full_name'], 'eid' => $eid];
}

// Also index the device PIN mappings from the Devices page (att_device_users)
// so a CSV exported from the punch machine resolves even when the staff
// profile has no Employee ID (or a different one). A PIN never overrides a
// profile match for the same user; a PIN colliding with a DIFFERENT user's id
// becomes a multi-entry list and is flagged as Ambiguous, never guessed.
try {
    $pin_rows = $db->query(
        'SELECT pin, user_id FROM att_device_users WHERE is_active = 1'
    )->fetchAll();
    foreach ($pin_rows as $r) {
        $uid = (int)$r['user_id'];
        if (!isset($valid_users[$uid])) continue; // inactive / student accounts
        $pin = trim((string)$r['pin']);
        if ($pin === '') continue;
        $key = $canon_emp($pin);
        if ($key === '') continue;
        $dup = false;
        foreach ($emp_index[$key] ?? [] as $m) {
            if ($m['id'] === $uid) { $dup = true; break; }
        }
        if (!$dup) {
            $emp_index[$key][] = ['id' => $uid, 'name' => $valid_users[$uid], 'eid' => $pin];
        }
    }
} catch (Throwable $e) {
    // att_device_users may not exist yet (ADMS migration not applied) –
    // profile Employee IDs still resolve as before.
}

$preview = null;
$report  = null;

// ---------------------------------------------------------------------------
// STEP 2 — apply a previously previewed plan.
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply') {
    csrf_check();

    $plan = json_decode((string)($_POST['plan'] ?? '[]'), true);
    if (!is_array($plan)) $plan = [];
    $plan = array_slice($plan, 0, PUNCH_IMPORT_MAX_ROWS);

    $ins = $db->prepare(
        'INSERT INTO att_punch_log
            (device_id, serial_no, pin, user_id, punch_time, work_date,
             status_code, verify_mode, raw_line)
         VALUES (0, "CSV-IMPORT", ?, ?, ?, ?, ?, NULL, ?)
         ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)'
    );

    $new_punches = 0;
    $dup_punches = 0;
    $skipped     = 0;
    $fold_days   = []; // "uid|date" => [uid, date]

    foreach ($plan as $p) {
        $uid  = (int)($p['uid'] ?? 0);
        $pin  = mb_substr(trim((string)($p['pin'] ?? '')), 0, 32);
        $date = (string)($p['date'] ?? '');
        $in   = (string)($p['in'] ?? '');
        $out  = (string)($p['out'] ?? '');

        // Re-validate everything against live data / strict formats so a
        // tampered or stale hidden plan can never write arbitrary rows.
        if ($pin === '' || !isset($valid_users[$uid])) { $skipped++; continue; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $skipped++; continue; }
        $times = [];
        if ($in !== '')  { if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $in))  { $skipped++; continue; } $times[] = ['t' => $in,  's' => '0']; }
        if ($out !== '') { if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $out)) { $skipped++; continue; } $times[] = ['t' => $out, 's' => '1']; }
        if (empty($times)) { $skipped++; continue; }

        foreach ($times as $t) {
            try {
                $ins->execute([$pin, $uid, $date . ' ' . $t['t'], $date, $t['s'],
                               'csv-import ' . $pin . ' ' . $date . ' ' . $t['t']]);
                // rowCount(): 1 = fresh insert, 0/2 = duplicate.
                if ($ins->rowCount() === 1) $new_punches++; else $dup_punches++;
            } catch (Throwable $e) {
                $skipped++;
            }
        }
        $fold_days[$uid . '|' . $date] = [$uid, $date];
    }

    // Re-fold every affected user/day so att_records reflects the new punches.
    foreach ($fold_days as [$uid, $date]) {
        adms_fold_day($uid, $date);
    }

    if ($new_punches > 0) {
        log_change('staff-attendance', 'IMPORT', 0, 'CSV punch import (' . $new_punches . ' punches)');
    }

    $report = [
        'new'    => $new_punches,
        'dup'    => $dup_punches,
        'skip'   => $skipped,
        'days'   => count($fold_days),
    ];

    $msg = 'Import done: ' . $new_punches . ' punch(es) added, ' . $dup_punches
         . ' duplicate(s) skipped, ' . count($fold_days) . ' staff-day(s) recalculated.';
    if ($skipped > 0) {
        flash_set('warning', $msg . ' ' . $skipped . ' row(s) could not be applied.');
    } else {
        flash_set('success', $msg);
    }
}

// ---------------------------------------------------------------------------
// STEP 1 — parse the uploaded CSV and build a preview (no DB writes).
// ---------------------------------------------------------------------------
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $errors = [];

    if (!isset($_FILES['csv_file']) || (int)($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors[] = 'Please choose a CSV file to upload.';
    }

    $fh = null;
    if (empty($errors)) {
        $fh = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if (!$fh) $errors[] = 'Unable to read the uploaded CSV file.';
    }

    $date_idx = $emp_idx = $in_idx = $out_idx = null;
    if (empty($errors) && $fh) {
        $headers = fgetcsv($fh, 0, ',', '"', '');
        if ($headers === false || empty($headers)) {
            $errors[] = 'The CSV appears to be empty (no header row).';
        } else {
            foreach ($headers as $idx => $header) {
                $key = $normalize_header((string)$header);
                if ($date_idx === null && in_array($key, $date_aliases, true)) $date_idx = $idx;
                if ($emp_idx  === null && in_array($key, $emp_aliases,  true)) $emp_idx  = $idx;
                if ($in_idx   === null && in_array($key, $in_aliases,   true)) $in_idx   = $idx;
                if ($out_idx  === null && in_array($key, $out_aliases,  true)) $out_idx  = $idx;
            }
            if ($date_idx === null) $errors[] = 'Could not find a "Date" column in the CSV.';
            if ($emp_idx  === null) $errors[] = 'Could not find an "Employee ID" column in the CSV.';
            if ($in_idx === null && $out_idx === null) $errors[] = 'Could not find an "In Time" or "Out Time" column in the CSV.';
        }
    }

    if (empty($errors) && $fh) {
        // Existing-punch check so the preview can flag rows already imported.
        $exists_stmt = null;
        try {
            $exists_stmt = $db->prepare(
                'SELECT 1 FROM att_punch_log WHERE device_id = 0 AND pin = ? AND punch_time = ? LIMIT 1'
            );
        } catch (Throwable $e) { /* table missing – treated as "new" */ }
        $punch_exists = static function (string $pin, string $dt) use ($exists_stmt): bool {
            if (!$exists_stmt) return false;
            try { $exists_stmt->execute([$pin, $dt]); return (bool)$exists_stmt->fetchColumn(); }
            catch (Throwable $e) { return false; }
        };

        $to_import = [];   // rows with at least one NEW punch
        $already   = [];   // rows whose punches all exist already
        $missing   = [];   // employee ids not found
        $ambiguous = [];   // employee ids matching more than one account
        $invalid   = [];   // bad date / bad time / out before in / no times
        $plan      = [];   // confirmed-step payload
        $seen_rows = [];   // exact duplicate CSV lines, dropped silently
        $dup_skipped = 0;
        $new_punch_total = 0;
        $dup_punch_total = 0;
        $row_no = 1;
        $truncated = false;

        while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $row_no++;
            if (count($plan) >= PUNCH_IMPORT_MAX_ROWS) { $truncated = true; break; }

            $raw_date = trim((string)($row[$date_idx] ?? ''));
            $raw_emp  = trim((string)($row[$emp_idx]  ?? ''));
            $raw_in   = $in_idx  !== null ? trim((string)($row[$in_idx]  ?? '')) : '';
            $raw_out  = $out_idx !== null ? trim((string)($row[$out_idx] ?? '')) : '';

            if ($raw_date === '' && $raw_emp === '' && $raw_in === '' && $raw_out === '') continue; // blank line

            if ($raw_emp === '' || $raw_date === '') {
                $invalid[] = ['row' => $row_no, 'emp' => $raw_emp, 'date' => $raw_date, 'reason' => 'Missing Employee ID or Date'];
                continue;
            }

            $date = $parse_date($raw_date);
            if ($date === null) {
                $invalid[] = ['row' => $row_no, 'emp' => $raw_emp, 'date' => $raw_date, 'reason' => 'Unrecognised date (expected DD/MM/YYYY)'];
                continue;
            }
            $in  = $parse_time($raw_in);
            $out = $parse_time($raw_out);
            if ($in === null || $out === null) {
                $invalid[] = ['row' => $row_no, 'emp' => $raw_emp, 'date' => $raw_date, 'reason' => 'Unrecognised time (expected HH:MM, HH:MM:SS or hh:mm AM/PM)'];
                continue;
            }
            if ($in === '' && $out === '') {
                $invalid[] = ['row' => $row_no, 'emp' => $raw_emp, 'date' => $raw_date, 'reason' => 'No In Time or Out Time given'];
                continue;
            }
            if ($in !== '' && $out !== '' && $out < $in) {
                $invalid[] = ['row' => $row_no, 'emp' => $raw_emp, 'date' => $raw_date, 'reason' => 'Out Time (' . h($raw_out) . ') is before In Time (' . h($raw_in) . ')'];
                continue;
            }

            // Exact duplicate CSV lines: first occurrence wins.
            $line_key = $canon_emp($raw_emp) . '|' . $date . '|' . $in . '|' . $out;
            if (isset($seen_rows[$line_key])) { $dup_skipped++; continue; }
            $seen_rows[$line_key] = true;

            // Resolve the employee id (case- and leading-zero-tolerant).
            $matches = $emp_index[$canon_emp($raw_emp)] ?? [];
            if (empty($matches)) {
                $missing[] = ['row' => $row_no, 'emp' => $raw_emp, 'date' => date('d/m/Y', strtotime($date))];
                continue;
            }
            if (count($matches) > 1) {
                $ambiguous[] = ['row' => $row_no, 'emp' => $raw_emp, 'count' => count($matches)];
                continue;
            }
            $uid  = $matches[0]['id'];
            $name = $matches[0]['name'];
            // Use the staff profile's employee id as the punch PIN (not the raw
            // CSV value) so re-imports stay idempotent even when the CSV drops
            // leading zeros.
            $pin  = mb_substr($matches[0]['eid'], 0, 32);

            // Which of this row's punches already exist?
            $new_parts = [];
            $dup_parts = [];
            if ($in !== '') {
                if ($punch_exists($pin, $date . ' ' . $in)) { $dup_parts[] = 'In'; $dup_punch_total++; }
                else { $new_parts[] = 'In'; $new_punch_total++; }
            }
            if ($out !== '') {
                if ($punch_exists($pin, $date . ' ' . $out)) { $dup_parts[] = 'Out'; $dup_punch_total++; }
                else { $new_parts[] = 'Out'; $new_punch_total++; }
            }

            $entry = [
                'emp'  => $raw_emp,
                'name' => $name,
                'date' => date('d/m/Y', strtotime($date)),
                'in'   => $in  !== '' ? substr($in, 0, 5)  : '—',
                'out'  => $out !== '' ? substr($out, 0, 5) : '—',
            ];

            if (empty($new_parts)) {
                $already[] = $entry;
                continue;
            }
            $entry['new'] = implode(' + ', $new_parts)
                          . (!empty($dup_parts) ? ' (' . implode(' + ', $dup_parts) . ' already exists)' : '');
            $to_import[] = $entry;
            $plan[] = ['uid' => $uid, 'pin' => $pin, 'date' => $date, 'in' => $in, 'out' => $out];
        }

        $preview = [
            'to_import'   => $to_import,
            'already'     => $already,
            'missing'     => $missing,
            'ambiguous'   => $ambiguous,
            'invalid'     => $invalid,
            'dup_skipped' => $dup_skipped,
            'new_punches' => $new_punch_total,
            'dup_punches' => $dup_punch_total,
            'truncated'   => $truncated,
            'plan'        => $plan,
        ];

        $unresolved = count($missing) + count($ambiguous) + count($invalid);
        $msg = 'Preview ready: ' . count($to_import) . ' row(s) / ' . $new_punch_total
             . ' punch(es) to import, ' . count($already) . ' row(s) already imported.';
        if ($unresolved > 0) $msg .= ' ' . $unresolved . ' row(s) need attention.';
        $msg .= $new_punch_total > 0 ? ' Review below, then confirm to import.' : ' Nothing to import.';
        flash_set('info', $msg);
    }

    if ($fh) fclose($fh);
    foreach ($errors as $e) flash_set('error', $e);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/staff-attendance/index.php">Staff Attendance</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/staff-attendance/devices.php">Devices</a></li>
            <li class="breadcrumb-item active">Import Punches</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/staff-attendance/devices.php#tab-activity" class="btn btn-light btn-sm" style="border-radius:10px;">
        <i class="fas fa-arrow-left me-1"></i> Back to Devices
    </a>
</div>

<?php flash_show(); ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-file-csv me-2 text-primary"></i>Upload Punches CSV</h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" enctype="multipart/form-data" class="row g-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="preview">
                    <div class="col-sm-8">
                        <label class="form-label small fw-semibold mb-1">CSV File</label>
                        <input type="file" name="csv_file" accept=".csv,text/csv" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-sm-4 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary btn-sm w-100" style="border-radius:10px;">
                            <i class="fas fa-eye me-1"></i> Preview
                        </button>
                    </div>
                    <div class="col-12">
                        <span class="small text-muted">Nothing is imported at this step – you will see exactly which staff and punch dates would be created before confirming.</span>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4"><h6 class="mb-0 fw-semibold">CSV Format</h6></div>
            <div class="card-body p-4">
                <ul class="mb-2 ps-3 small">
                    <li><code>Date</code> — <strong>DD/MM/YYYY</strong> (e.g. <code>26/06/2026</code>).</li>
                    <li><code>Employee ID</code> — as set on the staff profile.</li>
                    <li><code>In Time</code> / <code>Out Time</code> — <code>HH:MM</code>, <code>HH:MM:SS</code> or <code>hh:mm AM/PM</code>. Either may be left empty.</li>
                </ul>
                <p class="mb-2 small text-muted">Rows already imported are skipped automatically (safe to re-upload the same file). Unknown Employee IDs are reported, never guessed. Daily in/out records are recalculated after import.</p>
                <hr>
                <pre class="mb-0" style="font-size:.78rem;white-space:pre-wrap;">Date,Employee ID,In Time,Out Time
26/06/2026,EMP-1042,09:02,17:31
26/06/2026,EMP-1044,09:15,</pre>
            </div>
        </div>
    </div>
</div>

<?php if ($preview !== null): ?>
<div class="card mt-3" style="border-radius:12px;">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-eye me-2 text-primary"></i>Preview – nothing has been imported yet</h6>
        <?php if ($preview['new_punches'] > 0): ?>
            <form method="POST" class="m-0">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="apply">
                <input type="hidden" name="plan" value="<?= h(json_encode($preview['plan'])) ?>">
                <button type="submit" class="btn btn-success btn-sm" style="border-radius:10px;">
                    <i class="fas fa-check me-1"></i> Confirm &amp; Import (<?= (int)$preview['new_punches'] ?> punches)
                </button>
            </form>
        <?php else: ?>
            <span class="badge bg-secondary">Nothing to import</span>
        <?php endif; ?>
    </div>
    <div class="card-body p-4">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <span class="badge bg-success">To import: <?= count($preview['to_import']) ?> rows / <?= (int)$preview['new_punches'] ?> punches</span>
            <span class="badge bg-secondary">Already imported <?= count($preview['already']) ?></span>
            <span class="badge bg-danger">Unknown Employee IDs <?= count($preview['missing']) ?></span>
            <span class="badge bg-warning text-dark">Ambiguous IDs <?= count($preview['ambiguous']) ?></span>
            <span class="badge bg-danger">Invalid rows <?= count($preview['invalid']) ?></span>
            <span class="badge bg-light text-dark border">Duplicate lines ignored <?= (int)$preview['dup_skipped'] ?></span>
        </div>
        <?php if ($preview['truncated']): ?>
            <div class="alert alert-warning small py-2">The file has more than <?= PUNCH_IMPORT_MAX_ROWS ?> importable rows – only the first <?= PUNCH_IMPORT_MAX_ROWS ?> are shown/imported. Split the file and upload the rest separately.</div>
        <?php endif; ?>

        <?php if (!empty($preview['to_import'])): ?>
        <div class="mb-3">
            <div class="fw-semibold mb-1 text-success">Will Be Imported (<?= count($preview['to_import']) ?>)</div>
            <div class="table-responsive" style="max-height:420px;overflow-y:auto;">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light" style="position:sticky;top:0;"><tr>
                        <th>Employee ID</th><th>Staff Member</th><th>Date</th><th>In</th><th>Out</th><th>New Punches</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($preview['to_import'] as $r): ?>
                        <tr>
                            <td><code><?= h($r['emp']) ?></code></td>
                            <td class="small"><?= h($r['name']) ?></td>
                            <td class="small"><?= h($r['date']) ?></td>
                            <td class="small"><?= h($r['in']) ?></td>
                            <td class="small"><?= h($r['out']) ?></td>
                            <td class="small text-success"><?= h($r['new']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($preview['already'])): ?>
        <div class="mb-3">
            <div class="fw-semibold mb-1 text-secondary">Already Imported – skipped (<?= count($preview['already']) ?>)</div>
            <div class="table-responsive" style="max-height:260px;overflow-y:auto;">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light" style="position:sticky;top:0;"><tr>
                        <th>Employee ID</th><th>Staff Member</th><th>Date</th><th>In</th><th>Out</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($preview['already'] as $r): ?>
                        <tr>
                            <td><code><?= h($r['emp']) ?></code></td>
                            <td class="small"><?= h($r['name']) ?></td>
                            <td class="small"><?= h($r['date']) ?></td>
                            <td class="small"><?= h($r['in']) ?></td>
                            <td class="small"><?= h($r['out']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($preview['missing'])): ?>
        <div class="mb-3">
            <div class="fw-semibold mb-1 text-danger">Unknown Employee IDs – not found on any staff profile (<?= count($preview['missing']) ?>)</div>
            <div class="table-responsive" style="max-height:260px;overflow-y:auto;">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light" style="position:sticky;top:0;"><tr><th>CSV Row</th><th>Employee ID</th><th>Date</th></tr></thead>
                    <tbody>
                    <?php foreach ($preview['missing'] as $r): ?>
                        <tr><td class="small"><?= (int)$r['row'] ?></td><td><code><?= h($r['emp']) ?></code></td><td class="small"><?= h($r['date']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($preview['ambiguous'])): ?>
        <div class="mb-3">
            <div class="fw-semibold mb-1 text-warning">Ambiguous Employee IDs – matched more than one account (<?= count($preview['ambiguous']) ?>)</div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>CSV Row</th><th>Employee ID</th><th>Matches</th></tr></thead>
                    <tbody>
                    <?php foreach ($preview['ambiguous'] as $r): ?>
                        <tr><td class="small"><?= (int)$r['row'] ?></td><td><code><?= h($r['emp']) ?></code></td><td class="small"><?= (int)$r['count'] ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($preview['invalid'])): ?>
        <div class="mb-3">
            <div class="fw-semibold mb-1 text-danger">Invalid Rows – fix these in the CSV and re-upload (<?= count($preview['invalid']) ?>)</div>
            <div class="table-responsive" style="max-height:260px;overflow-y:auto;">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light" style="position:sticky;top:0;"><tr><th>CSV Row</th><th>Employee ID</th><th>Date</th><th>Problem</th></tr></thead>
                    <tbody>
                    <?php foreach ($preview['invalid'] as $r): ?>
                        <tr>
                            <td class="small"><?= (int)$r['row'] ?></td>
                            <td><code><?= h($r['emp']) ?></code></td>
                            <td class="small"><?= h($r['date']) ?></td>
                            <td class="small text-danger"><?= $r['reason'] /* pre-escaped where needed */ ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($preview['new_punches'] > 0): ?>
            <div class="d-flex justify-content-end pt-2">
                <form method="POST" class="m-0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="apply">
                    <input type="hidden" name="plan" value="<?= h(json_encode($preview['plan'])) ?>">
                    <button type="submit" class="btn btn-success btn-sm" style="border-radius:10px;">
                        <i class="fas fa-check me-1"></i> Confirm &amp; Import (<?= (int)$preview['new_punches'] ?> punches)
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($report !== null): ?>
<div class="card mt-3" style="border-radius:12px;">
    <div class="card-header py-3 px-4"><h6 class="mb-0 fw-semibold"><i class="fas fa-clipboard-list me-2 text-primary"></i>Import Report</h6></div>
    <div class="card-body p-4">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <span class="badge bg-success">Punches added <?= (int)$report['new'] ?></span>
            <span class="badge bg-secondary">Duplicates skipped <?= (int)$report['dup'] ?></span>
            <span class="badge bg-danger">Rows not applied <?= (int)$report['skip'] ?></span>
            <span class="badge bg-info">Staff-days recalculated <?= (int)$report['days'] ?></span>
        </div>
        <div class="small text-muted">
            The imported punches are now visible in
            <a href="<?= APP_URL ?>/staff-attendance/devices.php#tab-activity">Recent Punches</a>,
            and the daily attendance records (in/out) for each affected staff-day have been recalculated.
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
