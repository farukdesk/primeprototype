<?php
/**
 * Student Accounts – Bulk OLD ERP Proof Upload (UI)
 *
 * Upload a single ZIP archive containing proof photos of old-ERP data. Each
 * image must be named with the student ID it belongs to (e.g.
 * 02826105101071.jpg). Every image is attached to that student's account and
 * can be viewed / downloaded from the student account page.
 *
 * Processing is done in AJAX batches (re-using bulk-import-process.php) so large
 * archives do not hit PHP execution-time limits.
 */

require_once __DIR__ . '/../includes/auth.php';
require_access('student-accounts', 'can_create');
require_once __DIR__ . '/helpers.php';

$page_title = 'Bulk OLD ERP Proof Upload – Student Accounts';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0">
            <i class="fas fa-images me-2 text-success"></i>Bulk OLD ERP Proof Upload
        </h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/student-accounts/index.php">Student Accounts</a></li>
            <li class="breadcrumb-item active">Bulk OLD ERP Proof Upload</li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/student-accounts/bulk-import.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Back to Bulk Import
        </a>
    </div>
</div>

<?= flash_show() ?>

<!-- ── How it works ───────────────────────────────────────────────────────── -->
<div class="alert alert-info alert-dismissible fade show mb-4">
    <div class="d-flex gap-3">
        <div class="fs-4 text-info"><i class="fas fa-info-circle"></i></div>
        <div>
            <strong>How it works</strong>
            <ol class="mb-0 mt-1 ps-3 small">
                <li>Collect the OLD ERP proof photos and name each image file with the student ID it belongs to (e.g. <code>02826105101071.jpg</code>). Accepted image types: JPG, PNG, GIF, WebP.</li>
                <li>Bundle all the photos into a single <strong>ZIP</strong> file.</li>
                <li>Upload the ZIP below. Each photo is matched to the student by ID and attached to their account as an <strong>OLD ERP Proof</strong>.</li>
                <li>The proof can then be viewed or downloaded from the student's account page.</li>
                <li>Photos whose student ID is not found are reported as failed. With <strong>overwrite</strong> off, a student who already has a proof is skipped.</li>
            </ol>
        </div>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>

<!-- STEP 1 – Upload form -->
<div id="step-upload">
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-success text-white fw-semibold py-3">
        <i class="fas fa-upload me-2"></i>Upload Proof Photos (ZIP)
    </div>
    <div class="card-body">
        <form id="proof-form" novalidate>
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-semibold">
                        Proof ZIP <span class="text-danger">*</span>
                        <small class="text-muted fw-normal ms-1">A ZIP of images named by student ID</small>
                    </label>
                    <input type="file" name="proof_file" id="proof_file" class="form-control" accept=".zip,application/zip" required>
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="overwrite" name="overwrite" value="1">
                        <label class="form-check-label" for="overwrite">
                            <strong>Overwrite</strong> existing proof for a student
                            <small class="text-muted d-block">
                                If unchecked, students who already have an OLD ERP proof are skipped.
                                If checked, the previous proof is replaced with the new image.
                            </small>
                        </label>
                    </div>
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" class="btn btn-success" id="upload-btn">
                    <i class="fas fa-upload me-2"></i>Upload &amp; Attach Proofs
                </button>
            </div>
        </form>
    </div>
</div>
</div><!-- /#step-upload -->

<!-- STEP 2 – Progress -->
<div id="step-progress" style="display:none;">
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold py-3">
        <i class="fas fa-spinner fa-spin me-2 text-success" id="progress-spinner"></i>
        <span id="progress-title">Processing…</span>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-2" id="progress-label">Uploading file…</p>
        <div class="progress mb-3" style="height:22px;">
            <div id="progress-bar" class="progress-bar progress-bar-striped progress-bar-animated bg-success"
                 role="progressbar" style="width:0%">0%</div>
        </div>
        <div class="d-flex gap-4 small text-muted" id="progress-stats" style="display:none!important;">
            <span><i class="fas fa-check-circle text-success me-1"></i>Attached: <strong id="cnt-created">0</strong></span>
            <span><i class="fas fa-forward text-warning me-1"></i>Skipped: <strong id="cnt-skipped">0</strong></span>
            <span><i class="fas fa-times-circle text-danger me-1"></i>Failed: <strong id="cnt-failed">0</strong></span>
        </div>
    </div>
</div>
</div><!-- /#step-progress -->

<!-- STEP 3 – Results -->
<div id="step-results" style="display:none;">
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-success text-white fw-semibold py-3">
        <i class="fas fa-check-circle me-2"></i>Upload Complete
    </div>
    <div class="card-body">
        <div class="row g-3 mb-4" id="result-summary"></div>
        <div class="d-flex gap-2 mb-3">
            <button class="btn btn-outline-secondary btn-sm" onclick="filterRows('all')">All</button>
            <button class="btn btn-outline-success  btn-sm" onclick="filterRows('created')">Attached</button>
            <button class="btn btn-outline-warning  btn-sm" onclick="filterRows('skipped')">Skipped</button>
            <button class="btn btn-outline-danger   btn-sm" onclick="filterRows('failed')">Failed</button>
        </div>
        <div class="table-responsive" style="max-height:500px;overflow-y:auto;">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light sticky-top">
                    <tr><th>Student ID</th><th>Student Name</th><th>Status</th><th>Message</th></tr>
                </thead>
                <tbody id="results-body"></tbody>
            </table>
        </div>
        <div class="mt-3">
            <a href="<?= APP_URL ?>/student-accounts/index.php" class="btn btn-success">
                <i class="fas fa-list me-1"></i>View Student Accounts
            </a>
            <button class="btn btn-outline-secondary ms-2" onclick="location.reload()">
                <i class="fas fa-redo me-1"></i>New Upload
            </button>
        </div>
    </div>
</div>
</div><!-- /#step-results -->

<script>
(function () {
    'use strict';

    const BATCH_SIZE  = 20;
    const PROCESS_URL = '<?= APP_URL ?>/student-accounts/bulk-import-process.php';
    const CSRF_TOKEN  = <?= json_encode(csrf_token()) ?>;
    const CSRF_FIELD  = <?= json_encode(CSRF_TOKEN_NAME) ?>;

    let sessionKey = null, totalFiles = 0, offset = 0;
    let cntCreated = 0, cntSkipped = 0, cntFailed = 0, allRows = [];

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

    function post(data, callback) {
        data[CSRF_FIELD] = CSRF_TOKEN;
        const fd = new FormData();
        for (const [k, v] of Object.entries(data)) { fd.append(k, v); }
        fetch(PROCESS_URL, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(callback)
            .catch(err => callback({ success: false, error: String(err) }));
    }

    $('proof-form').addEventListener('submit', function (e) {
        e.preventDefault();
        const proofFile = $('proof_file').files[0];
        if (!proofFile) { showAlert('Please select a ZIP file.', 'danger'); return; }

        $('upload-btn').disabled = true;
        $('step-upload').style.display = 'none';
        $('step-progress').style.display = 'block';
        setProgress(2, 'Uploading file…');

        const fd = new FormData();
        fd.append('action', 'proof_upload');
        fd.append(CSRF_FIELD, CSRF_TOKEN);
        fd.append('proof_file', proofFile);
        fd.append('overwrite', $('overwrite').checked ? '1' : '0');

        fetch(PROCESS_URL, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(function (resp) {
                if (!resp.success) { onError(resp.error || 'Upload failed.'); return; }
                sessionKey = resp.session_key;
                totalFiles = resp.total;
                setProgress(5, 'Upload completed. Found ' + totalFiles + ' photo(s). Attaching…');
                $('progress-stats').style.removeProperty('display');
                processBatch();
            })
            .catch(err => onError(String(err)));
    });

    function processBatch() {
        if (offset >= totalFiles) { onComplete(); return; }
        post({
            action: 'proof_batch', session_key: sessionKey,
            offset: offset, batch_size: BATCH_SIZE,
        }, function (resp) {
            if (!resp.success) { onError(resp.error || 'Batch processing failed.'); return; }
            cntCreated += resp.created || 0;
            cntSkipped += resp.skipped || 0;
            cntFailed  += resp.failed  || 0;
            $('cnt-created').textContent = cntCreated;
            $('cnt-skipped').textContent = cntSkipped;
            $('cnt-failed').textContent  = cntFailed;
            if (Array.isArray(resp.rows)) { allRows = allRows.concat(resp.rows); }
            offset = resp.offset;
            const pct = totalFiles > 0 ? (offset / totalFiles) * 95 + 5 : 100;
            setProgress(pct, 'Processing… ' + offset + ' / ' + totalFiles + ' photos');
            if (resp.done || offset >= totalFiles) { onComplete(); }
            else { setTimeout(processBatch, 150); }
        });
    }

    function onComplete() {
        setProgress(100, 'Upload complete!');
        $('progress-spinner').className = 'fas fa-check-circle me-2 text-success';
        $('progress-title').textContent = 'Upload Complete';
        post({ action: 'cleanup', session_key: sessionKey }, function () {});
        $('result-summary').innerHTML = [
            makeCard(totalFiles, 'Total Photos', 'primary', 'fa-images'),
            makeCard(cntCreated, 'Attached',  'success', 'fa-check-circle'),
            makeCard(cntSkipped, 'Skipped',   'warning', 'fa-forward'),
            makeCard(cntFailed,  'Failed',    'danger',  'fa-times-circle'),
        ].join('');
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

    window.filterRows = function (status) {
        document.querySelectorAll('#results-body tr').forEach(function (tr) {
            tr.style.display = (status === 'all' || tr.dataset.status === status) ? '' : 'none';
        });
    };
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
