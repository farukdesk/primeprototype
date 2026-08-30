<?php
/**
 * Student Accounts – Bulk Registration Fee (OLD ERP proof) CSV Import
 *
 * Upload a CSV of:
 *   Student ID | Registration Total | Registration Paid | Registration Dues
 * and merge it into the Registration Fee (proof) fields on each student's
 * fee package:
 *   old_erp_reg_payable_amount  ← Registration Total
 *   old_erp_reg_received_amount ← Registration Paid
 * The "Registration Fee – Due (proof)" shown on the account view is always
 * derived as Payable − Received, so it needs no column of its own — when the
 * CSV provides a Dues column it is used purely as a cross-check.
 *
 * Values imported here are stored with source 'manual', so the OCR
 * (view.php auto-check / Bulk ERP Check) will never overwrite them — the
 * same protection save-erp-payable.php applies to manual entries.
 *
 * Validation before anything is saved (financial amounts are never guessed):
 *   – amounts are parsed with thousands separators preserved ("8,000" = 8000)
 *   – Paid must not exceed Total
 *   – when a Dues column is present: Total − Paid must equal Dues (±1 BDT),
 *     otherwise the row is flagged for verification and skipped.
 */

require_once __DIR__ . '/../includes/auth.php';
require_access('student-accounts');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../change-log/helpers.php';

if (!sfp_can_edit()) {
    flash_set('error', 'You do not have permission to import registration fee values.');
    redirect(APP_URL . '/student-accounts/index.php');
}

// Make sure the Registration Fee (proof) columns exist before importing.
sfp_ensure_old_erp_reg_columns();

// ── Sample CSV download ──────────────────────────────────────────────
if (($_GET['sample'] ?? '') === '1') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="registration-fees-sample.csv"');
    echo "Student ID,Registration Total,Registration Paid,Registration Dues\r\n";
    echo "02826105101071,\"8,000.00\",\"1,000.00\",\"7,000.00\"\r\n";
    echo "02826105101072,\"16,000.00\",\"16,000.00\",\"0.00\"\r\n";
    exit;
}

/**
 * Money parser: strips the ৳ sign, "BDT"/"Tk" and spaces, preserves comma
 * thousands separators correctly ("1,000" = 1000, "53,919" = 53919).
 * Returns null when the cell is empty or not a number.
 */
function brg_num($s): ?float
{
    if ($s === null) return null;
    $s = trim(str_replace(["\xE0\xA7\xB3", 'BDT', 'bdt', 'Tk', 'tk', ' ', "\xC2\xA0"], '', (string)$s));
    if ($s === '' || $s === '-' || $s === "\xE2\x80\x94") return null;
    $s = str_replace(',', '', $s);
    if (!is_numeric($s)) return null;
    return (float)$s;
}

$results = null;   // populated after a POST

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $overwrite = !empty($_POST['overwrite']);

    if (empty($_FILES['csv_file']['tmp_name']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        flash_set('error', 'Please choose a CSV file to upload.');
        redirect(APP_URL . '/student-accounts/bulk-reg-import.php');
    }

    $fh = fopen($_FILES['csv_file']['tmp_name'], 'rb');
    if (!$fh) {
        flash_set('error', 'Could not read the uploaded file.');
        redirect(APP_URL . '/student-accounts/bulk-reg-import.php');
    }

    // ── Header row: strip BOM, auto-detect the delimiter, map the columns ──
    $first = fgets($fh);
    if ($first === false) {
        fclose($fh);
        flash_set('error', 'The CSV file is empty.');
        redirect(APP_URL . '/student-accounts/bulk-reg-import.php');
    }
    $first  = preg_replace('/^\xEF\xBB\xBF/', '', $first);
    $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
    arsort($counts);
    $delim   = (string)array_key_first($counts);
    if ($counts[$delim] === 0) $delim = ',';
    $headers = str_getcsv($first, $delim);

    $col = ['sid' => null, 'total' => null, 'paid' => null, 'dues' => null];
    foreach ($headers as $i => $htxt) {
        $hh = strtolower(trim((string)$htxt));
        if ($col['sid'] === null && preg_match('/student\s*_?\s*id|^sid$|^id$/', $hh)) { $col['sid'] = $i; continue; }
        if ($col['dues'] === null && preg_match('/due/', $hh)) { $col['dues'] = $i; continue; }
        if ($col['total'] === null && preg_match('/total|payable/', $hh)) { $col['total'] = $i; continue; }
        if ($col['paid'] === null && preg_match('/paid|received/', $hh)) { $col['paid'] = $i; continue; }
    }

    if ($col['sid'] === null || $col['total'] === null || $col['paid'] === null) {
        fclose($fh);
        flash_set('error', 'Could not find the required columns. The CSV must contain "Student ID", "Registration Total" (or Payable) and "Registration Paid" (or Received) headers. Download the sample CSV for the expected format.');
        redirect(APP_URL . '/student-accounts/bulk-reg-import.php');
    }

    $db = db();
    $lookup = $db->prepare(
        'SELECT p.id AS package_id,
                p.old_erp_reg_payable_amount,
                p.old_erp_reg_received_amount,
                p.old_erp_reg_source,
                s.full_name
           FROM sfp_packages p
           JOIN students s ON s.id = p.student_id
          WHERE s.student_id = ?'
    );
    $update = $db->prepare(
        "UPDATE sfp_packages
            SET old_erp_reg_payable_amount  = ?,
                old_erp_reg_received_amount = ?,
                old_erp_reg_source          = 'manual',
                old_erp_checked_at          = NOW()
          WHERE id = ?"
    );

    $results = [
        'updated' => 0, 'skipped' => 0, 'mismatch' => 0,
        'invalid' => 0, 'not_found' => 0, 'rows' => [],
    ];

    $line_no = 1;
    while (($r = fgetcsv($fh, 0, $delim)) !== false) {
        $line_no++;
        $non_empty = 0;
        foreach ($r as $cell) { if (trim((string)$cell) !== '') { $non_empty++; break; } }
        if ($non_empty === 0) continue;   // blank line

        $sid   = trim((string)($r[$col['sid']] ?? ''));
        $total = brg_num($r[$col['total']] ?? null);
        $paid  = brg_num($r[$col['paid']]  ?? null);
        $dues  = ($col['dues'] !== null) ? brg_num($r[$col['dues']] ?? null) : null;

        $row = [
            'line' => $line_no, 'sid' => $sid, 'name' => '',
            'total' => $total, 'paid' => $paid,
            'due' => ($total !== null && $paid !== null) ? max(0.0, $total - $paid) : null,
        ];

        // ── Validate the row before touching anything ──
        if ($sid === '') {
            $results['invalid']++;
            $row['status'] = 'invalid'; $row['msg'] = 'Missing Student ID.';
            $results['rows'][] = $row; continue;
        }
        if ($total === null || $paid === null) {
            $results['invalid']++;
            $row['status'] = 'invalid'; $row['msg'] = 'Registration Total / Paid is missing or not a number.';
            $results['rows'][] = $row; continue;
        }
        if ($total < 0 || $paid < 0 || $total > 99999999 || $paid > 99999999) {
            $results['invalid']++;
            $row['status'] = 'invalid'; $row['msg'] = 'Amount out of range.';
            $results['rows'][] = $row; continue;
        }
        if ($paid > $total + 0.009) {
            $results['mismatch']++;
            $row['status'] = 'mismatch'; $row['msg'] = 'Paid exceeds Total – verify this row.';
            $results['rows'][] = $row; continue;
        }
        if ($dues !== null && abs(($total - $paid) - $dues) > 1.0) {
            $results['mismatch']++;
            $row['status'] = 'mismatch';
            $row['msg'] = 'Total − Paid = ' . number_format($total - $paid, 2)
                        . ' but the Dues column says ' . number_format($dues, 2) . ' – verify this row.';
            $results['rows'][] = $row; continue;
        }

        // ── Look up the student's fee package(s) ──
        $lookup->execute([$sid]);
        $pkgs = $lookup->fetchAll();
        if (!$pkgs) {
            $results['not_found']++;
            $row['status'] = 'not_found'; $row['msg'] = 'No student account found for this Student ID.';
            $results['rows'][] = $row; continue;
        }

        $row['name'] = (string)$pkgs[0]['full_name'];
        $did_update  = false;
        $was_skipped = false;

        foreach ($pkgs as $pkg) {
            $has_existing = ($pkg['old_erp_reg_received_amount'] !== null || $pkg['old_erp_reg_payable_amount'] !== null);
            if ($has_existing && !$overwrite) {
                $was_skipped = true;
                continue;
            }

            $update->execute([$total, $paid, (int)$pkg['package_id']]);
            $did_update = true;

            log_change(
                'student-accounts', 'UPDATE', (int)$pkg['package_id'],
                (string)$pkg['full_name'],
                'old_erp_reg_payable_amount',
                $pkg['old_erp_reg_payable_amount'] === null ? null : (string)$pkg['old_erp_reg_payable_amount'],
                (string)$total,
                'OLD ERP Registration Fee Payable Amount set to ' . number_format($total, 2) . ' BDT (CSV import)'
            );
            log_change(
                'student-accounts', 'UPDATE', (int)$pkg['package_id'],
                (string)$pkg['full_name'],
                'old_erp_reg_received_amount',
                $pkg['old_erp_reg_received_amount'] === null ? null : (string)$pkg['old_erp_reg_received_amount'],
                (string)$paid,
                'OLD ERP Registration Fee Received Amount set to ' . number_format($paid, 2) . ' BDT (CSV import)'
            );
        }

        if ($did_update) {
            $results['updated']++;
            $row['status'] = 'updated';
            $row['msg'] = 'Saved – Due (proof) is now ' . number_format(max(0.0, $total - $paid), 2) . ' BDT.';
        } elseif ($was_skipped) {
            $results['skipped']++;
            $row['status'] = 'skipped';
            $row['msg'] = 'Existing Registration Fee (proof) values kept – enable Overwrite to replace them.';
        } else {
            $results['not_found']++;
            $row['status'] = 'not_found';
            $row['msg'] = 'No updatable fee package found.';
        }
        $results['rows'][] = $row;
    }
    fclose($fh);
}

$page_title = 'Bulk Registration Fee CSV Import – Student Accounts';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0">
            <i class="fas fa-file-csv me-2 text-success"></i>Bulk Registration Fee CSV Import
        </h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/student-accounts/index.php">Student Accounts</a></li>
            <li class="breadcrumb-item active">Bulk Registration Fee CSV Import</li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/student-accounts/erp-check-runner.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Back to Bulk ERP Check
        </a>
    </div>
</div>

<?= flash_show() ?>

<div class="alert alert-info">
    <div class="d-flex gap-3">
        <div class="fs-4 text-info"><i class="fas fa-info-circle"></i></div>
        <div class="small">
            <strong>How it works:</strong> upload a CSV with the columns
            <code>Student ID</code>, <code>Registration Total</code>, <code>Registration Paid</code>
            and optionally <code>Registration Dues</code>. Each row is merged into the
            <em>Registration Fee (proof)</em> values on the student's account
            (Payable ← Total, Received ← Paid; the Due is always derived as Payable − Received).
            Imported values are stored as <strong>manual</strong> entries, so the OCR will never
            overwrite them, and they drive the Old ERP Totals Merge paid/dues split.
            Comma-separated amounts (“8,000”) are read correctly. Rows where
            <em>Total − Paid</em> does not equal the Dues column (±1 BDT), or Paid exceeds
            Total, are <strong>flagged for verification and skipped</strong> — nothing incorrect
            is ever saved silently.
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-success text-white fw-semibold py-3">
        <i class="fas fa-upload me-2"></i>Upload Registration Fees (CSV)
    </div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-semibold">
                        CSV file <span class="text-danger">*</span>
                        <small class="text-muted fw-normal ms-1">Student ID, Registration Total, Registration Paid, Registration Dues</small>
                    </label>
                    <input type="file" name="csv_file" class="form-control" accept=".csv,text/csv" required>
                    <div class="form-text">
                        <a href="<?= APP_URL ?>/student-accounts/bulk-reg-import.php?sample=1">
                            <i class="fas fa-download me-1"></i>Download sample CSV
                        </a>
                        · Delimiter (comma / semicolon / tab) is detected automatically.
                    </div>
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="overwrite" name="overwrite" value="1">
                        <label class="form-check-label" for="overwrite">
                            <strong>Overwrite</strong> existing Registration Fee (proof) values
                            <small class="text-muted d-block">
                                If unchecked, students who already have a Registration Fee reading
                                (OCR or manual) are skipped. If checked, the CSV replaces it.
                            </small>
                        </label>
                    </div>
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-file-import me-2"></i>Import &amp; Merge
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($results !== null): ?>
<div class="card">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-table-list me-2 text-muted"></i>Import Results</h6>
        <div class="d-flex gap-3 small">
            <span><i class="fas fa-check-circle text-success me-1"></i>Updated: <strong><?= (int)$results['updated'] ?></strong></span>
            <span><i class="fas fa-forward text-secondary me-1"></i>Skipped: <strong><?= (int)$results['skipped'] ?></strong></span>
            <span><i class="fas fa-triangle-exclamation text-warning me-1"></i>Verify: <strong><?= (int)$results['mismatch'] ?></strong></span>
            <span><i class="fas fa-times-circle text-danger me-1"></i>Invalid: <strong><?= (int)$results['invalid'] ?></strong></span>
            <span><i class="fas fa-user-slash text-muted me-1"></i>Not found: <strong><?= (int)$results['not_found'] ?></strong></span>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive" style="max-height:520px;overflow-y:auto;">
            <table class="table table-sm table-hover mb-0" style="font-size:.85rem;">
                <thead class="table-light sticky-top">
                    <tr>
                        <th style="width:60px;">Line</th>
                        <th>Student ID</th>
                        <th>Name</th>
                        <th class="text-end">Reg Total</th>
                        <th class="text-end">Reg Paid</th>
                        <th class="text-end">Reg Due</th>
                        <th>Status</th>
                        <th>Note</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($results['rows'] as $row):
                    $badge = [
                        'updated'   => '<span class="badge bg-success">Updated</span>',
                        'skipped'   => '<span class="badge bg-secondary">Skipped</span>',
                        'mismatch'  => '<span class="badge bg-warning text-dark">Verify</span>',
                        'invalid'   => '<span class="badge bg-danger">Invalid</span>',
                        'not_found' => '<span class="badge bg-dark">Not found</span>',
                    ][$row['status']] ?? h($row['status']);
                ?>
                <tr class="<?= $row['status'] === 'mismatch' ? 'table-warning' : ($row['status'] === 'invalid' ? 'table-danger' : '') ?>">
                    <td class="text-muted"><?= (int)$row['line'] ?></td>
                    <td><?= h($row['sid']) ?></td>
                    <td><?= h($row['name']) ?></td>
                    <td class="text-end"><?= $row['total'] !== null ? number_format($row['total'], 2) : '—' ?></td>
                    <td class="text-end"><?= $row['paid']  !== null ? number_format($row['paid'],  2) : '—' ?></td>
                    <td class="text-end"><?= $row['due']   !== null ? number_format($row['due'],   2) : '—' ?></td>
                    <td><?= $badge ?></td>
                    <td class="text-muted"><?= h($row['msg'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($results['rows'])): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">The CSV contained no data rows.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
