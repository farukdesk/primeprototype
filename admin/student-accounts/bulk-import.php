<?php
/**
 * Student Accounts – Bulk PDF Import (UI)
 *
 * Upload a ZIP file of Prime University old-ERP student payment PDFs.
 * Each PDF filename (without extension) must be the student ID,
 * e.g. "02826105101071.pdf".
 *
 * Processing is done in AJAX batches to handle 10,000+ files without
 * hitting PHP execution time limits.
 */

require_once __DIR__ . '/../includes/auth.php';
require_access('student-accounts', 'can_create');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../accounting/helpers.php';

$page_title    = 'Bulk PDF Import – Student Accounts';
$cash_accounts = acc_cash_accounts();
$income_accounts = acc_income_accounts();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0">
            <i class="fas fa-file-import me-2 text-success"></i>Bulk PDF Import
        </h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/student-accounts/index.php">Student Accounts</a></li>
            <li class="breadcrumb-item active">Bulk PDF Import</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/student-accounts/index.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i> Back
    </a>
</div>

<?= flash_show() ?>

<!-- ── How it works ───────────────────────────────────────────────────────── -->
<div class="alert alert-info alert-dismissible fade show mb-4">
    <div class="d-flex gap-3">
        <div class="fs-4 text-info"><i class="fas fa-info-circle"></i></div>
        <div>
            <strong>How it works</strong>
            <ol class="mb-0 mt-1 ps-3 small">
                <li>Export student payment PDFs from the old ERP. Each PDF must be named with the student ID (e.g. <code>02826105101071.pdf</code>).</li>
                <li>Bundle all PDFs into a single ZIP file.</li>
                <li>Choose the default cash/bank account and income account that will receive these historical payments in the ledger.</li>
                <li>Upload the ZIP. The system processes files in batches, creates each student's fee package, applies any concession as a scholarship, and records the transaction history.</li>
                <li>Students not found in the system, or those that already have a package (when overwrite is disabled), are reported as skipped.</li>
            </ol>
        </div>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     STEP 1 – Upload form  (hidden once processing starts)
════════════════════════════════════════════════════════════════════════════ -->
<div id="step-upload">
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-success text-white fw-semibold py-3">
        <i class="fas fa-upload me-2"></i>Upload ZIP File
    </div>
    <div class="card-body">
        <form id="upload-form" novalidate>
            <?= csrf_field() ?>

            <div class="row g-3">

                <!-- ZIP file -->
                <div class="col-12">
                    <label class="form-label fw-semibold">
                        ZIP File <span class="text-danger">*</span>
                        <small class="text-muted fw-normal ms-1">Each PDF inside must be named with the student ID</small>
                    </label>
                    <input type="file" name="zip_file" id="zip_file" class="form-control" accept=".zip" required>
                </div>

                <!-- Cash account -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="cash_account_id">
                        Received-Into Account <span class="text-danger">*</span>
                        <small class="text-muted fw-normal ms-1">(Cash or Bank)</small>
                    </label>
                    <select name="cash_account_id" id="cash_account_id" class="form-select" required>
                        <option value="">— Select account —</option>
                        <?php foreach ($cash_accounts as $a): ?>
                        <option value="<?= $a['id'] ?>"><?= h($a['code']) ?> – <?= h($a['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Income account -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="income_account_id">
                        Income Account <span class="text-danger">*</span>
                    </label>
                    <select name="income_account_id" id="income_account_id" class="form-select" required>
                        <option value="">— Select account —</option>
                        <?php foreach ($income_accounts as $a): ?>
                        <option value="<?= $a['id'] ?>"><?= h($a['code']) ?> – <?= h($a['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Overwrite option -->
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="overwrite" name="overwrite" value="1">
                        <label class="form-check-label" for="overwrite">
                            <strong>Overwrite</strong> existing student packages
                            <small class="text-muted d-block">
                                If unchecked, students who already have a fee package are skipped.
                                If checked, the existing package (and all its payments) will be deleted and re-created from the PDF.
                            </small>
                        </label>
                    </div>
                </div>

            </div><!-- /row -->

            <div class="mt-4">
                <button type="submit" class="btn btn-success" id="upload-btn">
                    <i class="fas fa-upload me-2"></i>Upload &amp; Start Import
                </button>
            </div>
        </form>
    </div>
</div>
</div><!-- /#step-upload -->

<!-- ══════════════════════════════════════════════════════════════════════════
     STEP 2 – Progress  (shown during processing)
════════════════════════════════════════════════════════════════════════════ -->
<div id="step-progress" style="display:none;">
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold py-3">
        <i class="fas fa-spinner fa-spin me-2 text-success" id="progress-spinner"></i>
        <span id="progress-title">Processing…</span>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-2" id="progress-label">Uploading ZIP file…</p>
        <div class="progress mb-3" style="height:22px;">
            <div id="progress-bar" class="progress-bar progress-bar-striped progress-bar-animated bg-success"
                 role="progressbar" style="width:0%">0%</div>
        </div>
        <div class="d-flex gap-4 small text-muted" id="progress-stats" style="display:none!important;">
            <span><i class="fas fa-check-circle text-success me-1"></i>Created: <strong id="cnt-created">0</strong></span>
            <span><i class="fas fa-forward text-warning me-1"></i>Skipped: <strong id="cnt-skipped">0</strong></span>
            <span><i class="fas fa-times-circle text-danger me-1"></i>Failed: <strong id="cnt-failed">0</strong></span>
        </div>
    </div>
</div>
</div><!-- /#step-progress -->

<!-- ══════════════════════════════════════════════════════════════════════════
     STEP 3 – Results  (shown after processing completes)
════════════════════════════════════════════════════════════════════════════ -->
<div id="step-results" style="display:none;">
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-success text-white fw-semibold py-3">
        <i class="fas fa-check-circle me-2"></i>Import Complete
    </div>
    <div class="card-body">
        <div class="row g-3 mb-4" id="result-summary">
            <!-- filled by JS -->
        </div>
        <div class="d-flex gap-2 mb-3">
            <button class="btn btn-outline-secondary btn-sm" id="filter-all" onclick="filterRows('all')">All</button>
            <button class="btn btn-outline-success  btn-sm" id="filter-created" onclick="filterRows('created')">Created</button>
            <button class="btn btn-outline-warning  btn-sm" id="filter-skipped" onclick="filterRows('skipped')">Skipped</button>
            <button class="btn btn-outline-danger   btn-sm" id="filter-failed" onclick="filterRows('failed')">Failed</button>
        </div>
        <div class="table-responsive" style="max-height:500px;overflow-y:auto;">
            <table class="table table-sm table-hover mb-0" id="results-table">
                <thead class="table-light sticky-top">
                    <tr>
                        <th>Student ID</th>
                        <th>Student Name</th>
                        <th>Status</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody id="results-body">
                </tbody>
            </table>
        </div>
        <div class="mt-3">
            <a href="<?= APP_URL ?>/student-accounts/index.php" class="btn btn-success">
                <i class="fas fa-list me-1"></i>View Student Accounts
            </a>
            <button class="btn btn-outline-secondary ms-2" onclick="location.reload()">
                <i class="fas fa-redo me-1"></i>New Import
            </button>
        </div>
    </div>
</div>
</div><!-- /#step-results -->

<script>
(function () {
    'use strict';

    const BATCH_SIZE = 20;          // PDFs per AJAX call
    const PROCESS_URL = '<?= APP_URL ?>/student-accounts/bulk-import-process.php';
    const CSRF_TOKEN  = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;

    let sessionKey  = null;
    let totalFiles  = 0;
    let offset      = 0;
    let cntCreated  = 0;
    let cntSkipped  = 0;
    let cntFailed   = 0;
    let allRows     = [];

    // ── DOM helpers ──────────────────────────────────────────────────────────

    function $(id) { return document.getElementById(id); }

    function setProgress(pct, label) {
        const bar = $('progress-bar');
        pct = Math.min(100, Math.max(0, Math.round(pct)));
        bar.style.width = pct + '%';
        bar.textContent = pct + '%';
        $('progress-label').textContent = label;
    }

    function showAlert(msg, type) {
        const div = document.createElement('div');
        div.className = 'alert alert-' + type + ' mt-3';
        div.textContent = msg;
        $('step-upload').appendChild(div);
    }

    // ── AJAX helper ──────────────────────────────────────────────────────────

    function post(data, callback) {
        data._token = CSRF_TOKEN;
        const fd = new FormData();
        for (const [k, v] of Object.entries(data)) {
            if (v instanceof File) { fd.append(k, v); }
            else { fd.append(k, v); }
        }

        fetch(PROCESS_URL, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(callback)
            .catch(err => callback({ success: false, error: String(err) }));
    }

    // ── Upload form submit ────────────────────────────────────────────────────

    $('upload-form').addEventListener('submit', function (e) {
        e.preventDefault();

        const zipFile = $('zip_file').files[0];
        if (!zipFile) { showAlert('Please select a ZIP file.', 'danger'); return; }

        const cashId   = $('cash_account_id').value;
        const incomeId = $('income_account_id').value;
        if (!cashId || !incomeId) {
            showAlert('Please select both a received-into account and an income account.', 'danger');
            return;
        }

        $('upload-btn').disabled = true;
        $('step-upload').style.display = 'none';
        $('step-progress').style.display = 'block';
        setProgress(2, 'Uploading ZIP file…');

        const fd = new FormData();
        fd.append('action', 'upload');
        fd.append('_token', CSRF_TOKEN);
        fd.append('zip_file', zipFile);
        fd.append('cash_account_id', cashId);
        fd.append('income_account_id', incomeId);
        fd.append('overwrite', $('overwrite').checked ? '1' : '0');

        fetch(PROCESS_URL, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(function (resp) {
                if (!resp.success) { onError(resp.error || 'Upload failed.'); return; }
                sessionKey = resp.session_key;
                totalFiles = resp.total;
                setProgress(5, 'ZIP uploaded. Found ' + totalFiles + ' PDF files. Starting import…');
                $('progress-stats').style.removeProperty('display');
                processBatch();
            })
            .catch(err => onError(String(err)));
    });

    // ── Batch processing loop ─────────────────────────────────────────────────

    function processBatch() {
        if (offset >= totalFiles) { onComplete(); return; }

        post({
            action:      'batch',
            session_key: sessionKey,
            offset:      offset,
            batch_size:  BATCH_SIZE,
        }, function (resp) {
            if (!resp.success) { onError(resp.error || 'Batch processing failed.'); return; }

            // Accumulate counts
            cntCreated += resp.created || 0;
            cntSkipped += resp.skipped || 0;
            cntFailed  += resp.failed  || 0;
            $('cnt-created').textContent = cntCreated;
            $('cnt-skipped').textContent = cntSkipped;
            $('cnt-failed').textContent  = cntFailed;

            // Store rows for results table
            if (Array.isArray(resp.rows)) {
                allRows = allRows.concat(resp.rows);
            }

            offset = resp.offset;

            const pct = totalFiles > 0 ? (offset / totalFiles) * 95 + 5 : 100;
            setProgress(pct, 'Processing… ' + offset + ' / ' + totalFiles + ' files');

            if (resp.done || offset >= totalFiles) {
                onComplete();
            } else {
                setTimeout(processBatch, 50);
            }
        });
    }

    // ── Complete ─────────────────────────────────────────────────────────────

    function onComplete() {
        setProgress(100, 'Import complete!');
        $('progress-spinner').className = 'fas fa-check-circle me-2 text-success';
        $('progress-title').textContent = 'Import Complete';

        // Cleanup temp dir
        post({ action: 'cleanup', session_key: sessionKey }, function () {});

        // Build summary cards
        const sumHtml = [
            makeCard(totalFiles, 'Total PDFs', 'primary', 'fa-file-pdf'),
            makeCard(cntCreated, 'Created',    'success', 'fa-check-circle'),
            makeCard(cntSkipped, 'Skipped',    'warning', 'fa-forward'),
            makeCard(cntFailed,  'Failed',     'danger',  'fa-times-circle'),
        ].join('');
        $('result-summary').innerHTML = sumHtml;

        // Build results table
        buildResultsTable(allRows);

        $('step-progress').style.display = 'none';
        $('step-results').style.display  = 'block';
    }

    function onError(msg) {
        $('progress-label').textContent = 'Error: ' + msg;
        $('progress-bar').classList.remove('progress-bar-animated');
        $('progress-bar').classList.add('bg-danger');
        $('upload-btn').disabled = false;
        $('step-upload').style.display = 'block';
    }

    // ── UI builders ──────────────────────────────────────────────────────────

    function makeCard(count, label, color, icon) {
        return '<div class="col-6 col-md-3">'
            + '<div class="card border-' + color + ' text-center py-3">'
            + '<div class="fs-2 text-' + color + '"><i class="fas ' + icon + '"></i></div>'
            + '<div class="fs-3 fw-bold">' + count + '</div>'
            + '<div class="text-muted small">' + label + '</div>'
            + '</div></div>';
    }

    function buildResultsTable(rows) {
        const tbody = $('results-body');
        tbody.innerHTML = '';
        rows.forEach(function (r) {
            const tr = document.createElement('tr');
            tr.dataset.status = r.status;
            const badgeClass = r.status === 'created' ? 'bg-success'
                             : r.status === 'skipped' ? 'bg-warning text-dark'
                             : 'bg-danger';
            tr.innerHTML = '<td><code>' + esc(r.sid) + '</code></td>'
                + '<td>' + esc(r.student_name || '–') + '</td>'
                + '<td><span class="badge ' + badgeClass + '">' + esc(r.status) + '</span></td>'
                + '<td class="small text-muted">' + esc(r.message || '') + '</td>';
            tbody.appendChild(tr);
        });
    }

    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── Filter results table ─────────────────────────────────────────────────

    window.filterRows = function (status) {
        const rows = document.querySelectorAll('#results-body tr');
        rows.forEach(function (tr) {
            tr.style.display = (status === 'all' || tr.dataset.status === status) ? '' : 'none';
        });
    };
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
