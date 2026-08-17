<?php
/**
 * Student Accounts – Bulk ERP Check Runner
 *
 * Mass OCR cross-check: goes through every package that has an OLD ERP proof
 * image attached but no stored Payable Amount yet, reads the "Payable Amount"
 * from the proof with client-side OCR (Tesseract.js), saves it via
 * save-erp-payable.php and compares it with the Grand Total (±50 BDT,
 * incl. the Project Fee / 1,000 BDT cross-checks).
 *
 * The run is resumable: checked accounts drop out of the queue automatically
 * (old_erp_payable_amount IS NULL filter), so the tab can be closed and the
 * runner restarted at any time. OCR failures are skipped and listed for
 * manual entry on the account page.
 *
 * ?action=list – JSON: next batch of unchecked packages (id, proof URL,
 *                grand total, project fee) + remaining count.
 */

ob_start();
ini_set('display_errors', '0');

require_once __DIR__ . '/../includes/auth.php';
require_access('student-accounts');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../accounting/helpers.php';
require_once __DIR__ . '/../students/helpers.php';  // sm_program_data(), sm_batches()

// ── Filters: Department / Program / Batch (apply to the queue and counters) ───
$f_dept    = (int)($_GET['dept']    ?? 0);
$f_program = (int)($_GET['program'] ?? 0);
$f_batch   = (int)($_GET['batch']   ?? 0);

$filter_sql    = '';
$filter_params = [];
if ($f_dept > 0)    { $filter_sql .= ' AND s.dept_id = ?';    $filter_params[] = $f_dept; }
if ($f_program > 0) { $filter_sql .= ' AND s.program_id = ?'; $filter_params[] = $f_program; }
if ($f_batch > 0)   { $filter_sql .= ' AND s.batch_id = ?';   $filter_params[] = $f_batch; }

// ── Shared: department scope (same restriction as index.php) ──────────────
$dept_scope   = get_dept_scope();
$scope_sql    = '';
$scope_params = [];
if ($dept_scope !== null) {
    if (empty($dept_scope)) {
        $scope_sql = ' AND 0 = 1';
    } else {
        $phs          = implode(',', array_fill(0, count($dept_scope), '?'));
        $scope_sql    = " AND s.dept_id IN ($phs)";
        $scope_params = array_values($dept_scope);
    }
}

$unchecked_where =
    "p.old_erp_payable_amount IS NULL
     AND EXISTS (SELECT 1 FROM student_files stf
                  WHERE stf.student_id = p.student_id
                    AND stf.file_name  = '" . SFP_OLD_ERP_PROOF_LABEL . "'
                    AND stf.mime_type LIKE 'image/%')";

// ── JSON: next batch of unchecked packages ─────────────────────────────
if (($_GET['action'] ?? '') === 'list') {
    header('Content-Type: application/json; charset=UTF-8');

    // Packages the client already tried and failed (OCR could not read them)
    $exclude = array_filter(array_map('intval', explode(',', (string)($_GET['exclude'] ?? ''))));
    $exclude_sql = $exclude ? ' AND p.id NOT IN (' . implode(',', $exclude) . ')' : '';

    // mode=unchecked (default): only accounts without a stored Payable Amount.
    // mode=recheck: every account with a proof image – previous OCR values are
    //               re-read and overwritten (manual entries are excluded; the
    //               save endpoint protects them as well).
    // sid=<student id>: check / re-check one student regardless of state.
    // after_id: pagination cursor (p.id) so recheck runs cannot loop forever.
    $mode     = (($_GET['mode'] ?? '') === 'recheck') ? 'recheck' : 'unchecked';
    $sid      = trim((string)($_GET['sid'] ?? ''));
    $after_id = (int)($_GET['after_id'] ?? 0);

    $proof_exists =
        "EXISTS (SELECT 1 FROM student_files stf
                  WHERE stf.student_id = p.student_id
                    AND stf.file_name  = '" . SFP_OLD_ERP_PROOF_LABEL . "'
                    AND stf.mime_type LIKE 'image/%')";

    $queue_params = [];
    if ($sid !== '') {
        $queue_where    = "$proof_exists AND s.student_id = ?";
        $queue_params[] = $sid;
    } elseif ($mode === 'recheck') {
        $queue_where =
            "$proof_exists
             AND (p.old_erp_payable_source IS NULL OR p.old_erp_payable_source <> 'manual')";
    } else {
        $queue_where = $unchecked_where;
    }

    // Single-ID lookups ignore the Department / Program / Batch filters
    // (the department scope restriction still applies).
    $use_filter_sql    = ($sid === '') ? $filter_sql : '';
    $use_filter_params = ($sid === '') ? $filter_params : [];
    $cursor_sql        = ' AND p.id > ?';

    $db = db();

    $cnt_stmt = $db->prepare(
        "SELECT COUNT(*)
           FROM sfp_packages p
           JOIN students s ON s.id = p.student_id
          WHERE $queue_where $scope_sql $use_filter_sql $cursor_sql $exclude_sql"
    );
    $cnt_stmt->execute(array_merge($queue_params, $scope_params, $use_filter_params, [$after_id]));
    $remaining = (int)$cnt_stmt->fetchColumn();

    $stmt = $db->prepare(
        "SELECT p.*,
                s.full_name  AS student_name,
                s.student_id AS student_sid,
                (SELECT COALESCE(SUM(sf.tuition_payable), 0)
                   FROM sfp_semester_fees sf WHERE sf.package_id = p.id) AS erp_sum_tuition,
                (SELECT COALESCE(SUM(sf.fixed_discount_amount), 0)
                   FROM sfp_semester_fees sf WHERE sf.package_id = p.id) AS erp_sum_fixed_disc,
                (SELECT COALESCE(SUM(sf.english_discount_amount), 0)
                   FROM sfp_semester_fees sf WHERE sf.package_id = p.id) AS erp_sum_eng_disc,
                (SELECT COUNT(*)
                   FROM sfp_semester_fees sf WHERE sf.package_id = p.id) AS erp_sem_count,
                (SELECT stf.stored_name
                   FROM student_files stf
                  WHERE stf.student_id = p.student_id
                    AND stf.file_name  = '" . SFP_OLD_ERP_PROOF_LABEL . "'
                    AND stf.mime_type LIKE 'image/%'
                  ORDER BY stf.created_at DESC, stf.id DESC
                  LIMIT 1) AS proof_stored_name
           FROM sfp_packages p
           JOIN students s ON s.id = p.student_id
          WHERE $queue_where $scope_sql $use_filter_sql $cursor_sql $exclude_sql
          ORDER BY p.id ASC
          LIMIT 25"
    );
    $stmt->execute(array_merge($queue_params, $scope_params, $use_filter_params, [$after_id]));

    $items = [];
    foreach ($stmt->fetchAll() as $pkg) {
        if (empty($pkg['proof_stored_name'])) {
            continue;
        }
        $months = (float)($pkg['total_months'] ?? 0);
        $mps    = (float)($pkg['months_per_semester'] ?? 0);
        $fixed_ps = ($months > 0 && $mps > 0)
            ? round((float)$pkg['fixed_institutional_fees'] * $mps / $months, 2) : 0.0;
        $eng_ps   = ($months > 0 && $mps > 0)
            ? round((float)$pkg['english_course_fee'] * $mps / $months, 2) : 0.0;
        $sem_cnt  = (int)($pkg['erp_sem_count'] ?? 0);
        $proj_fee = acc_package_project_fee($pkg);
        $form_fee = acc_package_form_id_fee($pkg);

        $grand = (float)($pkg['erp_sum_tuition'] ?? 0)
               + max(0.0, $fixed_ps * $sem_cnt - (float)($pkg['erp_sum_fixed_disc'] ?? 0))
               + max(0.0, $eng_ps   * $sem_cnt - (float)($pkg['erp_sum_eng_disc']   ?? 0))
               + (float)($pkg['reg_fee_per_semester'] ?? 0) * $sem_cnt
               + (float)($pkg['admission_fees'] ?? 0)
               + $form_fee
               + $proj_fee
               + (float)($pkg['bi_tri_shift_fee'] ?? 0);

        $items[] = [
            'package_id'  => (int)$pkg['id'],
            'name'        => (string)$pkg['student_name'],
            'sid'         => (string)$pkg['student_sid'],
            'proof_url'   => UPLOAD_URL . '/students/files/' . rawurlencode($pkg['proof_stored_name']),
            'grand_total' => round($grand, 2),
            'project_fee' => round($proj_fee, 2),
            'form_id_fee' => round($form_fee, 2),
            'view_url'    => APP_URL . '/student-accounts/view.php?id=' . (int)$pkg['id'],
        ];
    }

    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode(['success' => true, 'remaining' => $remaining, 'items' => $items], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── HTML page ──────────────────────────────────────────────────────────────
$db = db();
$cnt_stmt = $db->prepare(
    "SELECT COUNT(*)
       FROM sfp_packages p
       JOIN students s ON s.id = p.student_id
      WHERE $unchecked_where $scope_sql $filter_sql"
);
$cnt_stmt->execute(array_merge($scope_params, $filter_params));
$unchecked_total = (int)$cnt_stmt->fetchColumn();

// ── Filter dropdown data (same sources as index.php) ──────────────────────
$departments = $db->query(
    'SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
)->fetchAll();
$all_programs = sm_program_data();
$batches      = sm_batches();
if ($dept_scope !== null) {
    $departments = array_values(array_filter(
        $departments,
        fn($d) => in_array((int)$d['id'], $dept_scope, true)
    ));
    $all_programs = array_values(array_filter(
        $all_programs,
        fn($p) => in_array((int)$p['dept_id'], $dept_scope, true)
    ));
}

$page_title = 'Bulk ERP Check – Student Accounts';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0">
            <i class="fas fa-wand-magic-sparkles me-2 text-success"></i>Bulk ERP Check
        </h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/student-accounts/index.php">Student Accounts</a></li>
            <li class="breadcrumb-item active">Bulk ERP Check</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/student-accounts/index.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i> Back to Student Accounts
    </a>
</div>

<?= flash_show() ?>

<div class="alert alert-info">
    <div class="d-flex gap-3">
        <div class="fs-4 text-info"><i class="fas fa-info-circle"></i></div>
        <div class="small">
            <strong>How it works:</strong> this page reads the <em>Payable Amount</em> from every
            OLD ERP proof screenshot with OCR, saves it, and compares it against the Grand Total
            (incl. Admission, Form &amp; ID Card &amp; Project fees) with a ±<?= number_format(SFP_OLD_ERP_TOLERANCE, 0) ?> BDT tolerance
            (also cross-checking Grand Total − Project Fee and − 1,000 BDT).
            <strong>Keep this tab open</strong> while it runs (≈ 2–6 seconds per student).
            You can stop at any time and continue later – already-checked students are skipped automatically.
            Proofs the OCR cannot read are listed for quick manual entry.
        </div>
    </div>
</div>

<!-- ── Filters ── -->
<div class="card mb-4">
    <div class="card-body py-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label fw-semibold small mb-1">Department</label>
                <select name="dept" id="filter_dept" class="form-select form-select-sm">
                    <option value="">All Depts</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $f_dept == $d['id'] ? 'selected' : '' ?>>
                        <?= h($d['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label fw-semibold small mb-1">Program</label>
                <select name="program" id="filter_program" class="form-select form-select-sm">
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
            <div class="col-6 col-md-3">
                <label class="form-label fw-semibold small mb-1">Batch</label>
                <select name="batch" class="form-select form-select-sm">
                    <option value="">All Batches</option>
                    <?php foreach ($batches as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $f_batch == $b['id'] ? 'selected' : '' ?>>
                        <?= h($b['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 d-flex gap-2">
                <button class="btn btn-primary btn-sm flex-fill" type="submit">
                    <i class="fas fa-filter me-1"></i>Apply Filter
                </button>
                <?php if ($f_dept || $f_program || $f_batch): ?>
                <a href="<?= APP_URL ?>/student-accounts/erp-check-runner.php" class="btn btn-outline-secondary btn-sm flex-fill">Clear</a>
                <?php endif; ?>
            </div>
        </form>
        <?php if ($f_dept || $f_program || $f_batch): ?>
        <div class="small text-muted mt-2">
            <i class="fas fa-filter me-1"></i>Filter active – only the <?= number_format($unchecked_total) ?> matching unchecked account(s) will be processed.
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var deptSel    = document.getElementById('filter_dept');
    var programSel = document.getElementById('filter_program');
    if (!deptSel || !programSel) return;
    function filterPrograms() {
        var deptId = deptSel.value;
        programSel.querySelectorAll('option[data-dept]').forEach(function (opt) {
            var show = !deptId || opt.dataset.dept === deptId;
            opt.hidden   = !show;
            opt.disabled = !show;
            if (!show && opt.selected) programSel.value = '';
        });
    }
    deptSel.addEventListener('change', filterPrograms);
    filterPrograms();
}());
</script>

<div class="card mb-4">
    <div class="card-body d-flex align-items-center gap-3 flex-wrap">
        <button type="button" class="btn btn-success" id="run-btn">
            <i class="fas fa-play me-1"></i>Start Checking (<?= number_format($unchecked_total) ?> unchecked)
        </button>
        <button type="button" class="btn btn-outline-danger" id="stop-btn" disabled>
            <i class="fas fa-stop me-1"></i>Stop
        </button>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="recheck-toggle">
            <label class="form-check-label small" for="recheck-toggle">
                <strong>Re-check</strong> already checked accounts
                <span class="text-muted d-block" style="font-size:.75rem;">OCR values are re-read and overwritten; manual entries are kept.</span>
            </label>
        </div>
        <div class="input-group input-group-sm" style="max-width:340px;">
            <input type="text" class="form-control" id="check-one-sid"
                   placeholder="Student ID (e.g. 02826105101071)">
            <button type="button" class="btn btn-outline-primary" id="check-one-btn"
                    title="Run the OCR check for this one student now, even if it was checked before.">
                <i class="fas fa-rotate me-1"></i>Check / Re-check ID
            </button>
        </div>
        <div class="flex-grow-1" style="min-width:240px;">
            <div class="progress" style="height:22px;">
                <div id="progress-bar" class="progress-bar progress-bar-striped bg-success" role="progressbar" style="width:0%">0%</div>
            </div>
            <div class="small text-muted mt-1" id="status-line">Idle.</div>
        </div>
    </div>
    <div class="card-footer py-2 d-flex gap-4 small">
        <span><i class="fas fa-check-circle text-success me-1"></i>Match: <strong id="cnt-match">0</strong></span>
        <span><i class="fas fa-triangle-exclamation text-danger me-1"></i>Mismatch: <strong id="cnt-mismatch">0</strong></span>
        <span><i class="fas fa-question-circle text-warning me-1"></i>OCR failed (manual): <strong id="cnt-failed">0</strong></span>
        <span><i class="fas fa-list text-muted me-1"></i>Processed: <strong id="cnt-done">0</strong> / <span id="cnt-total"><?= number_format($unchecked_total) ?></span></span>
    </div>
</div>

<div class="card">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-table-list me-2 text-muted"></i>Results</h6>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-secondary btn-sm" onclick="erpFilter('all')">All</button>
            <button class="btn btn-outline-danger btn-sm" onclick="erpFilter('mismatch')">Mismatches</button>
            <button class="btn btn-outline-warning btn-sm" onclick="erpFilter('failed')">OCR failed</button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive" style="max-height:520px;overflow-y:auto;">
            <table class="table table-sm table-hover mb-0" style="font-size:.85rem;">
                <thead class="table-light sticky-top">
                    <tr>
                        <th>Student</th>
                        <th class="text-end">ERP Payable</th>
                        <th class="text-end">Grand Total</th>
                        <th class="text-end">Δ</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="results-body">
                    <tr id="empty-row"><td colspan="6" class="text-center text-muted py-4">Not started yet.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var CFG = {
        listUrl:       '<?= APP_URL ?>/student-accounts/erp-check-runner.php?action=list&dept=<?= (int)$f_dept ?>&program=<?= (int)$f_program ?>&batch=<?= (int)$f_batch ?>',
        saveUrl:       '<?= APP_URL ?>/student-accounts/save-erp-payable.php',
        tolerance:     <?= json_encode((float)SFP_OLD_ERP_TOLERANCE) ?>,
        stdProjectFee: <?= json_encode((float)SFP_OLD_ERP_STANDARD_PROJECT_FEE) ?>,
        csrfField:     <?= json_encode(CSRF_TOKEN_NAME) ?>,
        csrfToken:     <?= json_encode(csrf_token()) ?>,
        total:         <?= (int)$unchecked_total ?>
    };

    var running = false, worker = null, queue = [], failedIds = [];
    var nMatch = 0, nMismatch = 0, nFailed = 0, nDone = 0;
    var afterId = 0, singleMode = false;

    function currentMode() {
        var t = document.getElementById('recheck-toggle');
        return (t && t.checked) ? 'recheck' : 'unchecked';
    }

    function $id(i) { return document.getElementById(i); }
    function setStatus(t) { $id('status-line').textContent = t; }

    function setProgress() {
        var pct = CFG.total > 0 ? Math.min(100, Math.round(nDone / CFG.total * 100)) : 100;
        var bar = $id('progress-bar');
        bar.style.width = pct + '%';
        bar.textContent = pct + '%';
        $id('cnt-match').textContent    = nMatch;
        $id('cnt-mismatch').textContent = nMismatch;
        $id('cnt-failed').textContent   = nFailed;
        $id('cnt-done').textContent     = nDone;
    }

    function fmt(n) { return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // Same match rules as sfp_old_erp_check() in helpers.php:
    // the OLD ERP payable excludes the Form, ID Card and Project fees.
    function evaluate(payable, grand, projectFee, formIdFee) {
        var base = grand - (formIdFee || 0);
        var cands = [];
        if (projectFee > 0) cands.push(base - projectFee);
        cands.push(base - CFG.stdProjectFee);
        cands.push(base);
        var best = null;
        cands.forEach(function (v) {
            var d = Math.abs(v - payable);
            if (best === null || d < best) best = d;
        });
        return { matched: best <= CFG.tolerance, diff: best };
    }

    // Accepted labels for the payable field, in priority order. Some OLD ERP
    // screenshots label the amount "Monthly Payment" (monthly fees) instead of
    // "Payable Amount" – both are read the same way.
    var PAYABLE_LABELS = [/pay\s*able\s*amount/i, /pay\s*able/i, /monthly\s*pay\s*ment/i];

    // Read the first number AFTER the label on the line, so other columns the
    // OCR merges into the same line (Total / Paid / Due amounts) are ignored
    // instead of accidentally picking the biggest number on the line.
    function amountAfterLabel(line, labelRe) {
        var m = labelRe.exec(line);
        if (!m) return null;
        var candidates = line.slice(m.index + m[0].length).match(/-?[\d,]+(?:\.\d+)?/g);
        if (!candidates) {
            // Rare OCR column swap: the amount sits before the label
            var before = line.slice(0, m.index).match(/-?[\d,]+(?:\.\d+)?/g);
            if (before) candidates = [before[before.length - 1]];
        }
        if (!candidates) return null;
        for (var i = 0; i < candidates.length; i++) {
            var v = parseFloat(candidates[i].replace(/,/g, ''));
            if (!isNaN(v) && v > 0) return v;
        }
        return null;
    }

    function parsePayable(text) {
        var lines = String(text).split(/\n/);
        for (var l = 0; l < PAYABLE_LABELS.length; l++) {
            for (var i = 0; i < lines.length; i++) {
                var val = amountAfterLabel(lines[i], PAYABLE_LABELS[l]);
                if (val !== null) return val;
            }
        }
        var m = String(text).match(/(?:pay\s*able|monthly\s*pay\s*ment)[^0-9\-]*(-?[\d,]+(?:\.\d+)?)/i);
        return m ? parseFloat(m[1].replace(/,/g, '')) : null;
    }

    function addRow(item, payable, res, status) {
        var er = $id('empty-row');
        if (er) er.remove();
        var tr = document.createElement('tr');
        tr.dataset.status = status;
        var badge = status === 'match'
            ? '<span class="badge bg-success">Match</span>'
            : status === 'mismatch'
                ? '<span class="badge bg-danger">MISMATCH</span>'
                : '<span class="badge bg-warning text-dark">OCR failed – manual</span>';
        if (status === 'mismatch') tr.className = 'table-danger';
        tr.innerHTML =
            '<td>' + esc(item.name) + '<br><small class="text-muted">' + esc(item.sid) + '</small></td>' +
            '<td class="text-end">' + (payable === null ? '—' : fmt(payable)) + '</td>' +
            '<td class="text-end">' + fmt(item.grand_total) + '</td>' +
            '<td class="text-end">' + (res ? fmt(res.diff) : '—') + '</td>' +
            '<td>' + badge + '</td>' +
            '<td class="text-end"><a href="' + esc(item.view_url) + '" target="_blank" class="btn btn-outline-primary btn-sm py-0">Open</a></td>';
        var body = $id('results-body');
        body.insertBefore(tr, body.firstChild);
    }

    window.erpFilter = function (status) {
        document.querySelectorAll('#results-body tr').forEach(function (tr) {
            if (!tr.dataset.status) return;
            tr.style.display = (status === 'all'
                || (status === 'mismatch' && tr.dataset.status === 'mismatch')
                || (status === 'failed'   && tr.dataset.status === 'failed')) ? '' : 'none';
        });
    };

    function save(packageId, amount, cb) {
        var fd = new FormData();
        fd.append(CFG.csrfField, CFG.csrfToken);
        fd.append('package_id', packageId);
        fd.append('amount', amount);
        fd.append('source', 'ocr');
        fetch(CFG.saveUrl, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (resp) { cb(!!resp.success); })
            .catch(function () { cb(false); });
    }

    function fetchBatch(cb) {
        var url = CFG.listUrl
            + '&mode=' + currentMode()
            + '&after_id=' + afterId
            + '&exclude=' + encodeURIComponent(failedIds.join(','));
        fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (!resp.success) { cb([]); return; }
                CFG.total = nDone + (resp.remaining || 0);
                $id('cnt-total').textContent = CFG.total.toLocaleString();
                cb(resp.items || []);
            })
            .catch(function () { cb([]); });
    }

    function processNext() {
        if (!running) { setStatus('Stopped. Click Start to continue – progress is saved.'); return; }
        if (queue.length === 0) {
            if (singleMode) {
                running = false;
                singleMode = false;
                $id('run-btn').disabled = false;
                $id('stop-btn').disabled = true;
                setStatus('Single ID check finished – see the top row of the results table.');
                return;
            }
            setStatus('Loading next batch…');
            fetchBatch(function (items) {
                if (items.length === 0) {
                    running = false;
                    $id('run-btn').disabled = false;
                    $id('stop-btn').disabled = true;
                    setStatus('Done. All readable proofs are checked. ' + nFailed + ' need manual entry (see "OCR failed" filter).');
                    setProgress();
                    return;
                }
                queue = items;
                processNext();
            });
            return;
        }

        var item = queue.shift();
        afterId = Math.max(afterId, item.package_id);
        setStatus('Reading proof for ' + item.name + ' (' + item.sid + ')…');

        worker.recognize(item.proof_url).then(function (res) {
            var val = parsePayable((res && res.data && res.data.text) || '');
            if (val === null) {
                nFailed++; nDone++;
                failedIds.push(item.package_id);
                addRow(item, null, null, 'failed');
                setProgress();
                setTimeout(processNext, 50);
                return;
            }
            save(item.package_id, val, function (ok) {
                var ev = evaluate(val, item.grand_total, item.project_fee, item.form_id_fee);
                if (!ok) {
                    // Could not persist – treat as failed so it is retried later
                    nFailed++; nDone++;
                    failedIds.push(item.package_id);
                    addRow(item, val, ev, 'failed');
                } else if (ev.matched) {
                    nMatch++; nDone++;
                    addRow(item, val, ev, 'match');
                } else {
                    nMismatch++; nDone++;
                    addRow(item, val, ev, 'mismatch');
                }
                setProgress();
                setTimeout(processNext, 50);
            });
        }).catch(function () {
            nFailed++; nDone++;
            failedIds.push(item.package_id);
            addRow(item, null, null, 'failed');
            setProgress();
            setTimeout(processNext, 50);
        });
    }

    function loadTesseract(cb) {
        if (window.Tesseract) { cb(); return; }
        var s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js';
        s.onload = cb;
        s.onerror = function () { setStatus('Could not load the OCR library (CDN unreachable).'); };
        document.head.appendChild(s);
    }

    function ensureWorker(cb) {
        loadTesseract(function () {
            if (worker) { cb(); return; }
            window.Tesseract.createWorker('eng').then(function (w) {
                worker = w;
                cb();
            }).catch(function (e) {
                running = false;
                singleMode = false;
                $id('run-btn').disabled = false;
                $id('stop-btn').disabled = true;
                setStatus('Could not start the OCR engine: ' + e);
            });
        });
    }

    $id('run-btn').addEventListener('click', function () {
        if (running) return;
        running = true;
        singleMode = false;
        afterId = 0;   // fresh run: start from the beginning of the queue
        queue = [];
        $id('run-btn').disabled = true;
        $id('stop-btn').disabled = false;
        setStatus('Starting OCR engine…');
        ensureWorker(processNext);
    });

    // ── Check / re-check a single student ID ──
    var oneBtn = $id('check-one-btn');
    if (oneBtn) {
        oneBtn.addEventListener('click', function () {
            if (running) return;
            var sid = ($id('check-one-sid').value || '').trim();
            if (sid === '') { setStatus('Enter a student ID first.'); return; }
            running = true;
            singleMode = true;
            $id('run-btn').disabled = true;
            $id('stop-btn').disabled = false;
            setStatus('Looking up ' + sid + '…');
            fetch(CFG.listUrl + '&sid=' + encodeURIComponent(sid))
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    var items = (resp && resp.items) || [];
                    if (items.length === 0) {
                        running = false;
                        singleMode = false;
                        $id('run-btn').disabled = false;
                        $id('stop-btn').disabled = true;
                        setStatus('No account with an OLD ERP proof image found for ID "' + sid + '".');
                        return;
                    }
                    queue = items;
                    ensureWorker(processNext);
                })
                .catch(function (e) {
                    running = false;
                    singleMode = false;
                    $id('run-btn').disabled = false;
                    $id('stop-btn').disabled = true;
                    setStatus('Lookup failed: ' + e);
                });
        });
    }

    $id('stop-btn').addEventListener('click', function () {
        running = false;
        $id('run-btn').disabled = false;
        $id('stop-btn').disabled = true;
    });

    window.addEventListener('beforeunload', function (e) {
        if (running) { e.preventDefault(); e.returnValue = ''; }
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
