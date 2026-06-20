<?php
/**
 * Smart Bulk Upload – Main UI
 *
 * Upload OCR-scanned text files (.txt, .doc, .docx, .rtf, .odt) or paste
 * raw OCR text.  The system auto-detects student entries and grade data,
 * shows a preview, then imports into the selected result exam.
 */

require_once __DIR__ . '/../includes/auth.php';
require_access('results', 'can_create');
require_once __DIR__ . '/helpers.php';

$exam_id = (int)($_GET['exam_id'] ?? 0);
if (!$exam_id) {
    flash_set('error', 'No exam specified.');
    redirect(APP_URL . '/results/index.php');
}

$exam     = rm_get_exam($exam_id);
$subjects = rm_get_subjects($exam_id);

$page_title = 'Smart Bulk Upload';

require_once __DIR__ . '/../includes/header.php';
?>

<!-- ── Breadcrumb ──────────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/results/index.php">Results</a></li>
            <li class="breadcrumb-item">
                <a href="<?= APP_URL ?>/results/view.php?id=<?= $exam_id ?>">
                    <?= h($exam['exam_title']) ?>
                </a>
            </li>
            <li class="breadcrumb-item active">Smart Bulk Upload</li>
        </ol>
    </nav>
</div>

<!-- ── Exam info banner ───────────────────────────────────────────────────── -->
<div class="card mb-4" style="border-radius:12px; border-left:4px solid #002147;">
    <div class="card-body px-4 py-3">
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <div>
                <div class="fw-bold" style="color:#002147;"><?= h($exam['exam_title']) ?></div>
                <div class="small text-muted">
                    <?= h($exam['dept_name']) ?>
                    <?php if ($exam['program_name']): ?>
                    &nbsp;·&nbsp;<?= h($exam['program_name']) ?>
                    <?php endif; ?>
                    <?php if ($exam['batch']): ?>
                    &nbsp;·&nbsp;Batch: <?= h($exam['batch']) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="ms-auto d-flex gap-2">
                <span class="badge bg-info bg-opacity-15 text-info border border-info" style="font-size:.8rem;">
                    <i class="fas fa-list-ol me-1"></i><?= count($subjects) ?> subjects loaded
                </span>
            </div>
        </div>
    </div>
</div>

<?php flash_show(); ?>

<!-- ═══════════════════════════════════════════════════════════════════════════
     STEP 1 – Upload / Paste
════════════════════════════════════════════════════════════════════════════ -->
<div id="step1">

    <!-- How it works -->
    <div class="alert alert-info alert-dismissible fade show mb-4" style="border-radius:10px;">
        <div class="d-flex gap-3">
            <div class="fs-4 text-info"><i class="fas fa-magic"></i></div>
            <div>
                <strong>How Smart Bulk Upload works</strong>
                <ol class="mb-0 mt-1 ps-3" style="font-size:.875rem;">
                    <li>Upload an OCR-scanned file <em>or</em> paste the raw text below.</li>
                    <li>The system detects student names, IDs, CGPA, and all course grades automatically.</li>
                    <li>Review the preview, choose options, then click <strong>Import</strong>.</li>
                </ol>
                <div class="mt-2 small text-muted">
                    Supported file types: <code>.txt</code>, <code>.docx</code>, <code>.doc</code>,
                    <code>.rtf</code>, <code>.odt</code> — and any plain-text format.
                </div>
            </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>

    <form id="parse_form" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="exam_id" value="<?= $exam_id ?>">

        <div class="row g-4">

            <!-- Left: file upload -->
            <div class="col-lg-6">
                <div class="card h-100" style="border-radius:12px;">
                    <div class="card-header py-3 px-4">
                        <h6 class="mb-0 fw-semibold">
                            <i class="fas fa-file-upload me-2 text-muted"></i>Upload File
                        </h6>
                    </div>
                    <div class="card-body p-4 d-flex flex-column">

                        <div id="drop_zone"
                             class="border border-2 border-dashed rounded-3 p-5 text-center flex-grow-1"
                             style="border-color:#c8d0e0 !important; cursor:pointer; transition:background .2s;"
                             onclick="document.getElementById('ocr_file').click()">
                            <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-3"></i>
                            <div class="fw-medium">Drop file here or click to browse</div>
                            <div class="small text-muted mt-1">
                                .txt &nbsp;.docx &nbsp;.doc &nbsp;.rtf &nbsp;.odt &nbsp;or any text file
                            </div>
                            <div id="file_name_display" class="mt-3 small text-primary fw-medium"></div>
                        </div>

                        <input type="file" id="ocr_file" name="ocr_file" class="d-none"
                               accept=".txt,.text,.doc,.docx,.rtf,.odt,.csv">

                        <div class="text-center mt-3 small text-muted">Maximum file size: 10 MB</div>
                    </div>
                </div>
            </div>

            <!-- Right: paste text -->
            <div class="col-lg-6">
                <div class="card h-100" style="border-radius:12px;">
                    <div class="card-header py-3 px-4">
                        <h6 class="mb-0 fw-semibold">
                            <i class="fas fa-paste me-2 text-muted"></i>Or Paste OCR Text
                        </h6>
                    </div>
                    <div class="card-body p-4 d-flex flex-column">
                        <textarea id="raw_text" name="raw_text" class="form-control flex-grow-1"
                                  rows="10"
                                  placeholder="Paste your OCR-scanned result text here…

Example:
1. Student Name (ID: 193020101021)CGPA: 3.19 | Total Credits: 129
BEL-111English Reading & Public SpeakingB+3.25
...

2. Another Student (ID: 193020101022)CGPA: 3.49 | Total Credits: 129
Foundation: BEL-111 (A), BNG-112 (B+), CSE-113 (B+), …"
                                  style="font-size:.825rem; font-family:monospace; resize:vertical;"></textarea>
                    </div>
                </div>
            </div>

        </div>

        <div class="mt-4 text-center">
            <button type="submit" id="parse_btn" class="btn btn-primary btn-lg px-5" style="border-radius:10px;">
                <i class="fas fa-search me-2"></i>Parse &amp; Preview
            </button>
        </div>
    </form>
</div>


<!-- ═══════════════════════════════════════════════════════════════════════════
     STEP 2 – Preview & Import
════════════════════════════════════════════════════════════════════════════ -->
<div id="step2" style="display:none;">

    <!-- Summary bar -->
    <div id="summary_bar" class="alert mb-4" style="border-radius:10px;"></div>

    <!-- Warnings -->
    <div id="warnings_box" style="display:none;">
        <div class="alert alert-warning alert-dismissible fade show mb-4" style="border-radius:10px;">
            <strong><i class="fas fa-exclamation-triangle me-1"></i>Warnings</strong>
            <ul id="warnings_list" class="mb-0 mt-2 ps-3" style="font-size:.875rem;"></ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    </div>

    <!-- Students list -->
    <div id="students_preview" class="mb-4"></div>

    <!-- Import options card -->
    <div class="card" style="border-radius:12px;">
        <div class="card-body p-4">
            <div class="row g-4 align-items-center">

                <div class="col-md-7">
                    <h6 class="fw-semibold mb-3"><i class="fas fa-cog me-2 text-muted"></i>Import Options</h6>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="opt_create_subjects" checked>
                        <label class="form-check-label" for="opt_create_subjects">
                            <strong>Create missing subjects automatically</strong>
                            <div class="small text-muted">
                                Subjects found in the OCR data but not yet in this exam will be added.
                                Course names are auto-filled from the curriculum where available.
                            </div>
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="opt_overwrite" checked>
                        <label class="form-check-label" for="opt_overwrite">
                            <strong>Overwrite existing grades</strong>
                            <div class="small text-muted">
                                If a grade already exists for a student/subject pair it will be updated.
                                Uncheck to keep existing grades unchanged.
                            </div>
                        </label>
                    </div>
                </div>

                <div class="col-md-5 text-md-end d-flex flex-column align-items-md-end gap-2">
                    <button id="import_btn" class="btn btn-success btn-lg px-5" style="border-radius:10px;">
                        <i class="fas fa-file-import me-2"></i>
                        Import <span id="import_count">0</span> Student(s)
                    </button>
                    <button id="back_btn" class="btn btn-outline-secondary" style="border-radius:10px;">
                        <i class="fas fa-arrow-left me-1"></i>Upload Again
                    </button>
                </div>

            </div>
        </div>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════════════════════════════
     STEP 3 – Result
════════════════════════════════════════════════════════════════════════════ -->
<div id="step3" style="display:none;">
    <div id="result_card" class="card text-center p-5" style="border-radius:12px;"></div>
</div>


<!-- Loading overlay -->
<div id="loading_overlay"
     style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.45);
            z-index:9999; justify-content:center; align-items:center;">
    <div class="bg-white rounded-3 p-4 text-center shadow-lg" style="min-width:200px;">
        <div class="spinner-border text-primary mb-3"></div>
        <div class="fw-medium" id="loading_msg">Processing…</div>
    </div>
</div>


<script>
(function () {
    'use strict';

    var EXAM_ID      = <?= $exam_id ?>;
    var CSRF_TOKEN   = <?= json_encode(csrf_token()) ?>;
    var PARSE_URL    = '<?= APP_URL ?>/results/bulk-upload-parse.php';
    var SAVE_URL     = '<?= APP_URL ?>/results/bulk-upload-save.php';

    // ── Parsed data store ─────────────────────────────────────────────────────
    var parsedStudents = [];

    // ── DOM refs ──────────────────────────────────────────────────────────────
    var step1     = document.getElementById('step1');
    var step2     = document.getElementById('step2');
    var step3     = document.getElementById('step3');
    var overlay   = document.getElementById('loading_overlay');
    var loadMsg   = document.getElementById('loading_msg');
    var parseForm = document.getElementById('parse_form');
    var fileInput = document.getElementById('ocr_file');
    var dropZone  = document.getElementById('drop_zone');
    var rawText   = document.getElementById('raw_text');

    // ── Helpers ───────────────────────────────────────────────────────────────
    function esc(s) {
        return String(s ?? '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function showLoading(msg) {
        loadMsg.textContent = msg || 'Processing…';
        overlay.style.display = 'flex';
    }
    function hideLoading() {
        overlay.style.display = 'none';
    }

    function showStep(n) {
        step1.style.display = n === 1 ? '' : 'none';
        step2.style.display = n === 2 ? '' : 'none';
        step3.style.display = n === 3 ? '' : 'none';
    }

    function gradeColor(letter) {
        var c = { 'A+':'success','A':'success','A-':'success',
                  'B+':'primary','B':'primary','B-':'primary',
                  'C+':'warning','C':'warning',
                  'D':'danger', 'F':'danger' };
        return c[letter] || 'secondary';
    }

    // ── File drop-zone ────────────────────────────────────────────────────────
    fileInput.addEventListener('change', function () {
        var name = this.files[0] ? this.files[0].name : '';
        document.getElementById('file_name_display').textContent = name ? '📄 ' + name : '';
        if (name) rawText.value = '';   // clear paste box when file chosen
    });

    dropZone.addEventListener('dragover', function (e) {
        e.preventDefault();
        this.style.background = '#eef2ff';
    });
    dropZone.addEventListener('dragleave', function () {
        this.style.background = '';
    });
    dropZone.addEventListener('drop', function (e) {
        e.preventDefault();
        this.style.background = '';
        var files = e.dataTransfer.files;
        if (files.length) {
            fileInput.files = files;
            document.getElementById('file_name_display').textContent = '📄 ' + files[0].name;
            rawText.value = '';
        }
    });

    // ── STEP 1 → Parse ────────────────────────────────────────────────────────
    parseForm.addEventListener('submit', function (e) {
        e.preventDefault();

        var hasFile = fileInput.files && fileInput.files.length > 0;
        var hasPaste = rawText.value.trim().length > 10;

        if (!hasFile && !hasPaste) {
            alert('Please upload a file or paste OCR text first.');
            return;
        }

        showLoading('Parsing OCR text…');

        var fd = new FormData();
        fd.append(<?= json_encode(CSRF_TOKEN_NAME) ?>, CSRF_TOKEN);
        fd.append('exam_id', EXAM_ID);

        if (hasFile) {
            fd.append('ocr_file', fileInput.files[0]);
        } else {
            fd.append('raw_text', rawText.value);
        }

        fetch(PARSE_URL, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                hideLoading();
                if (data.error) { alert('Error: ' + data.error); return; }
                handleParseResult(data);
            })
            .catch(function (err) {
                hideLoading();
                alert('Network error: ' + err.message);
            });
    });

    // ── Build preview from parse result ───────────────────────────────────────
    function handleParseResult(data) {
        parsedStudents = data.students || [];
        var warnings   = data.warnings || [];

        if (parsedStudents.length === 0) {
            var msg = warnings.length ? warnings.join(' | ') : 'No student data could be detected.';
            alert('No results found. ' + msg);
            return;
        }

        // Summary
        var totalGrades = parsedStudents.reduce(function (n, s) { return n + (s.grades || []).length; }, 0);
        var summary = document.getElementById('summary_bar');
        summary.className = 'alert alert-success mb-4';
        summary.innerHTML =
            '<i class="fas fa-check-circle me-2"></i>' +
            '<strong>' + parsedStudents.length + ' student(s)</strong> detected &nbsp;·&nbsp; ' +
            '<strong>' + totalGrades + '</strong> grade entries found.' +
            (warnings.length
                ? ' &nbsp;·&nbsp; <span class="text-warning"><i class="fas fa-exclamation-triangle me-1"></i>' + warnings.length + ' warning(s)</span>'
                : '');

        // Warnings
        var warnBox  = document.getElementById('warnings_box');
        var warnList = document.getElementById('warnings_list');
        if (warnings.length) {
            warnList.innerHTML = warnings.map(function (w) { return '<li>' + esc(w) + '</li>'; }).join('');
            warnBox.style.display = '';
        } else {
            warnBox.style.display = 'none';
        }

        // Students
        var container = document.getElementById('students_preview');
        container.innerHTML = parsedStudents.map(function (s, idx) {
            return buildStudentCard(s, idx);
        }).join('');

        // Attach toggle listeners
        container.querySelectorAll('.grade-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = document.getElementById(this.dataset.target);
                var icon   = this.querySelector('i');
                if (target.classList.contains('d-none')) {
                    target.classList.remove('d-none');
                    icon.className = 'fas fa-chevron-up';
                } else {
                    target.classList.add('d-none');
                    icon.className = 'fas fa-chevron-down';
                }
            });
        });

        updateImportCount();

        // Student checkboxes → update count
        container.querySelectorAll('.stu-check').forEach(function (chk) {
            chk.addEventListener('change', updateImportCount);
        });

        showStep(2);
    }

    function buildStudentCard(s, idx) {
        var gradeRows = (s.grades || []).map(function (g, gi) {
            return '<tr>' +
                '<td class="ps-3 text-muted" style="width:35px;">' + (gi + 1) + '</td>' +
                '<td>' + (g.code ? '<span class="badge bg-light text-dark border">' + esc(g.code) + '</span>' : '<span class="text-muted small">—</span>') + '</td>' +
                '<td class="text-truncate" style="max-width:220px;">' + esc(g.title || '—') + '</td>' +
                '<td class="text-center"><span class="badge bg-' + gradeColor(g.letter) + '">' + esc(g.letter) + '</span></td>' +
                '<td class="text-center text-muted">' + (g.gp !== undefined ? g.gp.toFixed(2) : '—') + '</td>' +
                '</tr>';
        }).join('');

        var cgpaRow = (s.cgpa != null)
            ? '<tr class="table-light">' +
              '<td class="ps-3" style="width:35px;"></td>' +
              '<td colspan="3" class="fw-semibold text-end pe-3 text-muted small">CGPA</td>' +
              '<td class="text-center fw-bold">' + s.cgpa.toFixed(2) + '</td>' +
              '</tr>'
            : '';

        return '<div class="card mb-2" style="border-radius:10px;">' +
            '<div class="card-header py-2 px-3 d-flex align-items-center gap-2 flex-wrap">' +
            '<input type="checkbox" class="form-check-input stu-check" data-idx="' + idx + '" checked style="cursor:pointer;">' +
            '<div class="fw-semibold">' + esc(s.name) + '</div>' +
            '<code class="text-primary small">' + esc(s.sid) + '</code>' +
            (s.cgpa != null
                ? '<span class="badge bg-info text-dark ms-1">CGPA: ' + s.cgpa.toFixed(2) + '</span>'
                : '') +
            '<span class="badge bg-secondary ms-1">' + (s.grades || []).length + ' grades</span>' +
            '<button type="button" class="btn btn-sm btn-link py-0 ms-auto grade-toggle" data-target="grades_' + idx + '" title="Toggle grades">' +
            '<i class="fas fa-chevron-down"></i></button>' +
            '</div>' +
            '<div class="d-none" id="grades_' + idx + '">' +
            '<div class="table-responsive">' +
            '<table class="table table-sm mb-0 align-middle" style="font-size:.8rem;">' +
            '<thead class="table-light"><tr>' +
            '<th class="ps-3" style="width:35px;">#</th>' +
            '<th style="width:100px;">Code</th><th>Title</th>' +
            '<th class="text-center" style="width:70px;">Grade</th>' +
            '<th class="text-center" style="width:60px;">GP</th>' +
            '</tr></thead>' +
            '<tbody>' + gradeRows + cgpaRow + '</tbody>' +
            '</table></div></div></div>';
    }

    function updateImportCount() {
        var n = document.querySelectorAll('.stu-check:checked').length;
        document.getElementById('import_count').textContent = n;
        document.getElementById('import_btn').disabled = n === 0;
    }

    // ── STEP 2 → Import ───────────────────────────────────────────────────────
    document.getElementById('import_btn').addEventListener('click', function () {
        var selected = [];
        document.querySelectorAll('.stu-check:checked').forEach(function (chk) {
            selected.push(parsedStudents[parseInt(chk.dataset.idx, 10)]);
        });

        if (!selected.length) { alert('No students selected.'); return; }

        showLoading('Importing grades…');

        var fd = new FormData();
        fd.append(<?= json_encode(CSRF_TOKEN_NAME) ?>, CSRF_TOKEN);
        fd.append('exam_id', EXAM_ID);
        fd.append('create_subjects', document.getElementById('opt_create_subjects').checked ? '1' : '0');
        fd.append('overwrite',       document.getElementById('opt_overwrite').checked       ? '1' : '0');
        fd.append('students_json',   JSON.stringify(selected));

        fetch(SAVE_URL, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (result) {
                hideLoading();
                if (result.error) { alert('Import error: ' + result.error); return; }
                showImportResult(result);
            })
            .catch(function (err) {
                hideLoading();
                alert('Network error: ' + err.message);
            });
    });

    function showImportResult(r) {
        var errHtml = '';
        if (r.errors && r.errors.length) {
            errHtml = '<div class="alert alert-warning mt-3 text-start" style="font-size:.875rem;">' +
                '<strong>Notices:</strong><ul class="mb-0 mt-1 ps-3">' +
                r.errors.map(function (e) { return '<li>' + esc(e) + '</li>'; }).join('') +
                '</ul></div>';
        }

        var card = document.getElementById('result_card');
        card.innerHTML =
            '<i class="fas fa-check-circle fa-3x text-success mb-3"></i>' +
            '<h4 class="fw-bold text-success">Import Complete</h4>' +
            '<div class="row justify-content-center mt-3 g-3">' +
            stat('fas fa-star', r.saved,            'Grades Saved',     'success') +
            stat('fas fa-forward', r.skipped,        'Skipped',         'secondary') +
            stat('fas fa-plus-circle', r.created_subjects, 'Subjects Created', 'info') +
            '</div>' +
            errHtml +
            '<div class="mt-4 d-flex gap-2 justify-content-center">' +
            '<a href="' + esc(r.redirect) + '" class="btn btn-primary" style="border-radius:10px;">' +
            '<i class="fas fa-table me-1"></i>View Result Sheet</a>' +
            '<button onclick="window.location.reload()" class="btn btn-outline-secondary" style="border-radius:10px;">' +
            '<i class="fas fa-upload me-1"></i>Upload More</button>' +
            '</div>';

        showStep(3);
    }

    function stat(icon, value, label, color) {
        return '<div class="col-auto">' +
            '<div class="card border-0 shadow-sm px-4 py-3" style="border-radius:10px;">' +
            '<div class="fw-bold fs-3 text-' + color + '">' + (value ?? 0) + '</div>' +
            '<div class="small text-muted"><i class="' + icon + ' me-1"></i>' + label + '</div>' +
            '</div></div>';
    }

    // ── Back button ───────────────────────────────────────────────────────────
    document.getElementById('back_btn').addEventListener('click', function () {
        parsedStudents = [];
        document.getElementById('students_preview').innerHTML = '';
        fileInput.value = '';
        document.getElementById('file_name_display').textContent = '';
        showStep(1);
    });

})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
