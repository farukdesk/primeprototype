<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../change-log/helpers.php';

if (!can_access('course-offer')) {
    flash_set('error', 'You do not have permission to access this section.');
    redirect(APP_URL . '/index.php');
}

$offer_id = (int)($_GET['offer_id'] ?? $_POST['offer_id'] ?? 0);
$offer    = $offer_id > 0 ? co_get_offer($offer_id) : null;
if (!$offer) {
    flash_set('error', 'Course offer not found.');
    redirect(APP_URL . '/course-offer/index.php');
}

$subjects = co_get_subjects_with_teachers($offer_id);
$batch_id = (int)$offer['batch_id'];
$batch_sections = co_batch_sections($batch_id);
$batch_shifts   = co_batch_shifts($batch_id);
$all_batches    = co_student_batches();

// ── Actions ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (!co_is_staff()) {
        flash_set('error', 'You do not have permission to manage registrations.');
        redirect(APP_URL . '/course-offer/registrations.php?offer_id=' . $offer_id);
    }

    $action    = $_POST['action'] ?? '';
    $valid_sub = array_map(static fn($s) => (int)$s['id'], $subjects);
    $user      = auth_user();

    if ($action === 'toggle_open') {
        $open = (int)($_POST['registration_open'] ?? 0) === 1 ? 1 : 0;
        db()->prepare("UPDATE co_offers SET registration_open = ? WHERE id = ?")
            ->execute([$open, $offer_id]);
        log_change('course-offer', 'UPDATE', $offer_id, 'Offer #' . $offer_id,
            'registration_open', (string)(int)!$open, (string)$open,
            'Student self-registration ' . ($open ? 'opened' : 'closed'));
        flash_set('success', 'Self-registration ' . ($open ? 'opened' : 'closed') . '.');
        redirect(APP_URL . '/course-offer/registrations.php?offer_id=' . $offer_id);
    }

    if ($action === 'add') {
        $student_ids = array_values(array_filter(array_map('intval', (array)($_POST['student_ids'] ?? []))));
        $subject_ids = array_values(array_filter(array_map('intval', (array)($_POST['subject_ids'] ?? []))));
        $subject_ids = array_values(array_intersect($subject_ids, $valid_sub));

        if (empty($student_ids) || empty($subject_ids)) {
            flash_set('error', 'Select at least one student and one subject.');
            redirect(APP_URL . '/course-offer/registrations.php?offer_id=' . $offer_id);
        }

        // Enrol any existing student. Students sometimes continue with a batch
        // other than their own, so enrollment is not restricted to this offer's
        // batch — we only verify each selected student actually exists.
        $ph      = implode(',', array_fill(0, count($student_ids), '?'));
        $chk     = db()->prepare("SELECT id FROM students WHERE id IN ($ph)");
        $chk->execute($student_ids);
        $allowed = array_map('intval', array_column($chk->fetchAll(), 'id'));

        $added = 0;
        foreach ($allowed as $sid) {
            foreach ($subject_ids as $osid) {
                if (co_register_student($osid, $sid, 'admin', (int)$user['id'])) $added++;
            }
        }
        $skipped = count($student_ids) - count($allowed);
        log_change('course-offer', 'CREATE', $offer_id, 'Offer #' . $offer_id,
            null, null, null,
            'Manually enrolled ' . count($allowed) . ' student(s) into ' . count($subject_ids) . ' subject(s) (' . $added . ' new)');

        $msg = "Added <strong>$added</strong> registration(s) for " . count($allowed) . ' student(s).';
        if ($skipped > 0) $msg .= " $skipped student(s) skipped (not found).";
        flash_set('success', $msg);
        redirect(APP_URL . '/course-offer/registrations.php?offer_id=' . $offer_id);
    }

    if ($action === 'remove') {
        $osid = (int)($_POST['offer_subject_id'] ?? 0);
        $sid  = (int)($_POST['student_id'] ?? 0);
        if (in_array($osid, $valid_sub, true) && co_unregister_student($osid, $sid)) {
            log_change('course-offer', 'DELETE', $offer_id, 'Offer #' . $offer_id,
                null, null, null,
                'Removed student #' . $sid . ' from subject #' . $osid);
            flash_set('success', 'Registration removed.');
        } else {
            flash_set('error', 'Registration not found.');
        }
        redirect(APP_URL . '/course-offer/registrations.php?offer_id=' . $offer_id);
    }

    redirect(APP_URL . '/course-offer/registrations.php?offer_id=' . $offer_id);
}

// ── View data ────────────────────────────────────────────────────────────────
$reg_map     = co_registrations_by_subject($offer_id);
$total_regs  = 0;
foreach ($reg_map as $list) $total_regs += count($list);

$page_title = 'Course Registrations';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/course-offer/index.php">Course Offer</a></li>
            <li class="breadcrumb-item active">Registrations</li>
        </ol>
    </nav>
</div>

<?php flash_show(); ?>

<!-- Offer summary -->
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-body py-3 px-4 d-flex flex-wrap align-items-center gap-3">
        <div>
            <div class="fw-bold"><?= h($offer['batch_name']) ?></div>
            <div class="text-muted small">
                <?= h($offer['dept_name']) ?> &rsaquo; <?= h($offer['program_name']) ?>
                <?php if ($offer['semester']): ?> &middot; <?= h($offer['semester']) ?><?php endif; ?>
                <?php if ($offer['academic_intake']): ?> &middot; <?= h($offer['academic_intake']) ?><?php endif; ?>
            </div>
        </div>
        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle ms-auto">
            <?= (int)$total_regs ?> registration<?= $total_regs != 1 ? 's' : '' ?>
        </span>
        <?php if (co_is_staff()): ?>
        <form method="POST" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="offer_id" value="<?= $offer_id ?>">
            <input type="hidden" name="action" value="toggle_open">
            <input type="hidden" name="registration_open" value="<?= (int)$offer['registration_open'] ? 0 : 1 ?>">
            <button type="submit" class="btn btn-sm <?= (int)$offer['registration_open'] ? 'btn-success' : 'btn-outline-secondary' ?>"
                    style="border-radius:8px;">
                <i class="fas fa-toggle-<?= (int)$offer['registration_open'] ? 'on' : 'off' ?> me-1"></i>
                Self-registration: <?= (int)$offer['registration_open'] ? 'Open' : 'Closed' ?>
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($subjects)): ?>
<div class="alert alert-warning" style="border-radius:12px;">
    This offer has no subjects yet. <a href="<?= APP_URL ?>/course-offer/edit.php?id=<?= $offer_id ?>">Add subjects</a> first.
</div>
<?php else: ?>

<?php if (co_is_staff()): ?>
<!-- Bulk manual enrollment -->
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-user-plus me-2 text-muted"></i>Enroll Students</h6>
    </div>
    <div class="card-body p-4">
        <form method="POST" id="enroll-form">
            <?= csrf_field() ?>
            <input type="hidden" name="offer_id" value="<?= $offer_id ?>">
            <input type="hidden" name="action" value="add">
            <div id="selected-inputs"></div>

            <!-- Subjects -->
            <div class="mb-4">
                <label class="form-label fw-medium d-flex align-items-center gap-2">
                    Subjects <span class="text-danger">*</span>
                    <button type="button" class="btn btn-link btn-sm p-0" onclick="toggleAllSubs(true)">Select all</button>
                    <span class="text-muted">/</span>
                    <button type="button" class="btn btn-link btn-sm p-0" onclick="toggleAllSubs(false)">none</button>
                </label>
                <div class="row g-2">
                    <?php foreach ($subjects as $s): ?>
                    <div class="col-md-6">
                        <div class="form-check">
                            <input class="form-check-input sub-check" type="checkbox"
                                   name="subject_ids[]" value="<?= (int)$s['id'] ?>" id="sub-<?= (int)$s['id'] ?>">
                            <label class="form-check-label small" for="sub-<?= (int)$s['id'] ?>">
                                <?php if ($s['course_code']): ?>
                                <span class="badge bg-light text-dark border me-1" style="font-family:monospace;"><?= h($s['course_code']) ?></span>
                                <?php endif; ?>
                                <?= h($s['course_name']) ?>
                            </label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Student filters -->
            <label class="form-label fw-medium">Students <span class="text-danger">*</span></label>
            <div class="form-text mb-2">
                Students of batch <strong><?= h($offer['batch_name']) ?></strong> are listed by default.
                Switch the <strong>Batch</strong> filter to pull a student who is continuing with another
                batch, or choose <strong>All batches</strong> to search everyone.
            </div>
            <div class="row g-2 align-items-end mb-3">
                <div class="col-md-4">
                    <label class="form-label small mb-1">Batch</label>
                    <select id="stu-batch" class="form-select form-select-sm">
                        <option value="0">All batches</option>
                        <?php foreach ($all_batches as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= (int)$b['id'] === $batch_id ? 'selected' : '' ?>>
                            <?= h($b['name']) ?><?= (int)$b['id'] === $batch_id ? ' (offer batch)' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small mb-1">Search</label>
                    <input type="text" id="stu-q" class="form-control form-control-sm" placeholder="Student ID or name…">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Section</label>
                    <select id="stu-section" class="form-select form-select-sm">
                        <option value="">All sections</option>
                        <?php foreach ($batch_sections as $sec): ?>
                        <option value="<?= h($sec) ?>"><?= h($sec) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label small mb-1">Shift</label>
                    <select id="stu-shift" class="form-select form-select-sm">
                        <option value="">All shifts</option>
                        <?php foreach ($batch_shifts as $sh): ?>
                        <option value="<?= h($sh) ?>"><?= h($sh) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1 d-grid">
                    <button type="button" id="stu-reset" class="btn btn-light btn-sm" title="Reset filters">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>

            <!-- Selection summary -->
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">
                    <span id="sel-count">0</span> student(s) selected
                </span>
                <button type="button" class="btn btn-link btn-sm p-0" id="clear-sel">Clear selection</button>
            </div>

            <!-- Student table -->
            <div class="table-responsive border rounded" style="max-height:22rem; overflow:auto;">
                <table class="table table-sm table-hover align-middle mb-0" style="font-size:.85rem;">
                    <thead class="table-light" style="position:sticky; top:0; z-index:1;">
                        <tr>
                            <th style="width:2.5rem;" class="text-center">
                                <input type="checkbox" class="form-check-input" id="stu-check-all" title="Select all on this page">
                            </th>
                            <th>Student</th>
                            <th>Batch</th>
                            <th style="width:5rem;">Section</th>
                            <th style="width:6rem;">Shift</th>
                        </tr>
                    </thead>
                    <tbody id="stu-tbody">
                        <tr><td colspan="5" class="text-center text-muted py-3">Loading…</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="d-flex justify-content-between align-items-center mt-2">
                <small class="text-muted" id="stu-meta"></small>
                <nav><ul class="pagination pagination-sm mb-0" id="stu-pager"></ul></nav>
            </div>

            <button type="submit" class="btn btn-primary mt-3" style="border-radius:10px;">
                <i class="fas fa-plus me-1"></i>Enroll Selected
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Registrations per subject -->
<?php foreach ($subjects as $s): $osid = (int)$s['id']; $regs = $reg_map[$osid] ?? []; ?>
<div class="card mb-3" style="border-radius:12px;">
    <div class="card-header py-2 px-4 d-flex align-items-center gap-2">
        <?php if ($s['course_code']): ?>
        <span class="badge bg-light text-dark border" style="font-family:monospace;"><?= h($s['course_code']) ?></span>
        <?php endif; ?>
        <span class="fw-semibold"><?= h($s['course_name']) ?></span>
        <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle ms-auto">
            <?= count($regs) ?> student<?= count($regs) != 1 ? 's' : '' ?>
        </span>
    </div>
    <?php if (empty($regs)): ?>
    <div class="card-body py-3 px-4 text-muted small fst-italic">No students registered yet.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0" style="font-size:.85rem;">
            <thead class="table-light">
                <tr>
                    <th style="width:2.5rem;">#</th>
                    <th>Student</th>
                    <th>Department</th>
                    <th style="width:7rem;">Batch</th>
                    <th style="width:5rem;">Section</th>
                    <th style="width:6rem;">Shift</th>
                    <th style="width:6rem;">Source</th>
                    <?php if (co_is_staff()): ?><th style="width:4rem;"></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($regs as $i => $r): ?>
                <tr>
                    <td class="text-muted"><?= $i + 1 ?></td>
                    <td>
                        <div class="fw-medium"><?= h($r['full_name']) ?></div>
                        <div class="text-muted font-monospace" style="font-size:.78rem;"><?= h($r['student_id']) ?></div>
                    </td>
                    <td><?= h($r['dept_name'] ?: '—') ?></td>
                    <td><?= h($r['batch_name'] ?: '—') ?></td>
                    <td><?= h($r['section'] ?: '—') ?></td>
                    <td><?= h($r['shift'] ?: '—') ?></td>
                    <td>
                        <?php if ($r['source'] === 'admin'): ?>
                        <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">Admin</span>
                        <?php else: ?>
                        <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Self</span>
                        <?php endif; ?>
                    </td>
                    <?php if (co_is_staff()): ?>
                    <td>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Remove this registration?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="offer_id" value="<?= $offer_id ?>">
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="offer_subject_id" value="<?= $osid ?>">
                            <input type="hidden" name="student_id" value="<?= (int)$r['student_pk'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove">
                                <i class="fas fa-times"></i>
                            </button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php endif; ?>

<script>
function toggleAllSubs(on) {
    document.querySelectorAll('.sub-check').forEach(function (c) { c.checked = on; });
}
<?php if (co_is_staff() && !empty($subjects)): ?>
(function () {
    var API      = '<?= APP_URL ?>/course-offer/get-students.php';
    var PER_PAGE = 25;

    var batchSel = document.getElementById('stu-batch');
    var qInput   = document.getElementById('stu-q');
    var secSel   = document.getElementById('stu-section');
    var shiftSel = document.getElementById('stu-shift');
    var resetBtn = document.getElementById('stu-reset');
    var tbody    = document.getElementById('stu-tbody');
    var pager    = document.getElementById('stu-pager');
    var meta     = document.getElementById('stu-meta');
    var checkAll = document.getElementById('stu-check-all');
    var selCount = document.getElementById('sel-count');
    var clearBtn = document.getElementById('clear-sel');
    var hidden   = document.getElementById('selected-inputs');
    var form     = document.getElementById('enroll-form');

    // Persist selection across pages/filters: map of student PK -> label
    var selected = {};
    var curPage  = 1;
    var debounce = null;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function updateCount() {
        var n = Object.keys(selected).length;
        selCount.textContent = n;
    }

    function syncHiddenInputs() {
        hidden.innerHTML = '';
        Object.keys(selected).forEach(function (id) {
            var inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'student_ids[]';
            inp.value = id;
            hidden.appendChild(inp);
        });
    }

    function refreshCheckAll() {
        var boxes = tbody.querySelectorAll('.stu-check');
        if (!boxes.length) { checkAll.checked = false; return; }
        var all = true;
        boxes.forEach(function (b) { if (!b.checked) all = false; });
        checkAll.checked = all;
    }

    function render(data) {
        tbody.innerHTML = '';
        if (!data.rows || !data.rows.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">No students found.</td></tr>';
            meta.textContent = '0 students';
            pager.innerHTML = '';
            checkAll.checked = false;
            return;
        }
        data.rows.forEach(function (r) {
            var tr = document.createElement('tr');
            var checked = selected.hasOwnProperty(r.id) ? 'checked' : '';
            tr.innerHTML =
                '<td class="text-center"><input type="checkbox" class="form-check-input stu-check" value="' + r.id + '" ' + checked + '></td>' +
                '<td><div class="fw-medium">' + esc(r.full_name) + '</div>' +
                '<div class="text-muted font-monospace" style="font-size:.78rem;">' + esc(r.student_id) + '</div></td>' +
                '<td>' + esc(r.batch_name || '—') + '</td>' +
                '<td>' + esc(r.section || '—') + '</td>' +
                '<td>' + esc(r.shift || '—') + '</td>';
            tbody.appendChild(tr);
        });

        tbody.querySelectorAll('.stu-check').forEach(function (b) {
            b.addEventListener('change', function () {
                if (b.checked) { selected[b.value] = true; }
                else { delete selected[b.value]; }
                updateCount();
                refreshCheckAll();
            });
        });

        var start = (data.page - 1) * data.per_page + 1;
        var end   = Math.min(data.page * data.per_page, data.total);
        meta.textContent = start + '–' + end + ' of ' + data.total + ' students';
        renderPager(data.page, data.pages);
        refreshCheckAll();
    }

    function renderPager(page, pages) {
        pager.innerHTML = '';
        if (pages <= 1) return;
        function item(label, target, disabled, active) {
            var li = document.createElement('li');
            li.className = 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');
            var a = document.createElement('a');
            a.className = 'page-link';
            a.href = '#';
            a.textContent = label;
            a.addEventListener('click', function (e) {
                e.preventDefault();
                if (disabled || active) return;
                curPage = target;
                load();
            });
            li.appendChild(a);
            return li;
        }
        pager.appendChild(item('«', page - 1, page <= 1, false));
        var from = Math.max(1, page - 2);
        var to   = Math.min(pages, page + 2);
        if (from > 1) pager.appendChild(item('1', 1, false, page === 1));
        if (from > 2) pager.appendChild(item('…', page, true, false));
        for (var p = from; p <= to; p++) pager.appendChild(item(String(p), p, false, p === page));
        if (to < pages - 1) pager.appendChild(item('…', page, true, false));
        if (to < pages) pager.appendChild(item(String(pages), pages, false, page === pages));
        pager.appendChild(item('»', page + 1, page >= pages, false));
    }

    function load() {
        var params = new URLSearchParams({
            batch_id: batchSel.value,
            q: qInput.value.trim(),
            section: secSel.value,
            shift: shiftSel.value,
            page: curPage,
            per_page: PER_PAGE
        });
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">Loading…</td></tr>';
        fetch(API + '?' + params.toString())
            .then(function (r) { return r.json(); })
            .then(render)
            .catch(function () {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-danger py-3">Failed to load students.</td></tr>';
            });
    }

    function reloadFirstPage() { curPage = 1; load(); }

    batchSel.addEventListener('change', reloadFirstPage);
    qInput.addEventListener('input', function () {
        clearTimeout(debounce);
        debounce = setTimeout(reloadFirstPage, 300);
    });
    secSel.addEventListener('change', reloadFirstPage);
    shiftSel.addEventListener('change', reloadFirstPage);
    resetBtn.addEventListener('click', function () {
        batchSel.value = '<?= (int)$batch_id ?>';
        qInput.value = ''; secSel.value = ''; shiftSel.value = '';
        reloadFirstPage();
    });

    checkAll.addEventListener('change', function () {
        tbody.querySelectorAll('.stu-check').forEach(function (b) {
            b.checked = checkAll.checked;
            if (checkAll.checked) { selected[b.value] = true; }
            else { delete selected[b.value]; }
        });
        updateCount();
    });

    clearBtn.addEventListener('click', function () {
        selected = {};
        updateCount();
        tbody.querySelectorAll('.stu-check').forEach(function (b) { b.checked = false; });
        checkAll.checked = false;
    });

    form.addEventListener('submit', function (e) {
        syncHiddenInputs();
        if (Object.keys(selected).length === 0) {
            e.preventDefault();
            alert('Select at least one student to enroll.');
            return;
        }
        var anySub = document.querySelector('.sub-check:checked');
        if (!anySub) {
            e.preventDefault();
            alert('Select at least one subject.');
        }
    });

    load();
})();
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
