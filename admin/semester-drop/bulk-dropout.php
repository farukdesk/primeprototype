<?php
/**
 * Semester Drop – Bulk Dropout by Batch / Program / Department
 * ================================================================
 * Record official dropouts for a whole group of students at once, filtered by
 * department, program and/or batch. Workflow:
 *
 *   1. Filter  → choose department / program / batch and load the students.
 *   2. Select  → tick the students to drop. Students who already have an
 *      active dropout (or whose status is 'Dropped') are shown but cannot be
 *      selected again, so re-running the same filter is always safe.
 *   3. Confirm → every selected student gets a kind='dropout' record and
 *      their account is frozen (students.status becomes 'Dropped').
 *
 * Evidence cannot be attached per student in bulk, so — matching the
 * single-record policy (evidence is mandatory unless recorded by a Super
 * Administrator) — only a Super Administrator can CONFIRM a bulk dropout.
 * Any user with create access can still filter and preview.
 */

require_once __DIR__ . '/../includes/auth.php';
require_access('semester-drop', 'can_create');
require_once __DIR__ . '/helpers.php';

$page_title = 'Bulk Dropout';
$db         = db();
$is_super   = is_super_admin();
$me         = auth_user();

// ── Filters (GET, preserved through POST) ─────────────────────────────
$f_dept    = (int)($_REQUEST['dept']    ?? 0);
$f_program = (int)($_REQUEST['program'] ?? 0);
$f_batch   = (int)($_REQUEST['batch']   ?? 0);

$has_filter = $f_dept > 0 || $f_program > 0 || $f_batch > 0;
$filter_qs  = http_build_query(array_filter([
    'dept'    => $f_dept    ?: null,
    'program' => $f_program ?: null,
    'batch'   => $f_batch   ?: null,
]));
$self_url = APP_URL . '/semester-drop/bulk-dropout.php' . ($filter_qs !== '' ? '?' . $filter_qs : '');

// ── Handle POST (confirm) ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (!$is_super) {
        flash_set('error', 'Only a Super Administrator can confirm a bulk dropout, because evidence cannot be attached per student in bulk.');
        redirect($self_url);
    }

    $effective_date = trim($_POST['effective_date'] ?? '');
    $reason         = trim($_POST['reason'] ?? '');
    $ids            = array_values(array_unique(array_map('intval', (array)($_POST['student_ids'] ?? []))));
    $ids            = array_values(array_filter($ids, fn($v) => $v > 0));

    $eff_dt = false;
    if ($effective_date !== '') {
        $eff_dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $effective_date);
    }

    if (!($eff_dt instanceof \DateTimeImmutable) || $eff_dt->format('Y-m-d') !== $effective_date) {
        flash_set('error', 'Please provide a valid dropout effective date.');
        redirect($self_url);
    }
    if (empty($ids)) {
        flash_set('error', 'Please select at least one student.');
        redirect($self_url);
    }

    $created = 0;
    $skipped = 0;
    $lookup  = $db->prepare('SELECT id, full_name FROM students WHERE id = ?');

    foreach ($ids as $sid) {
        $lookup->execute([$sid]);
        $stu = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$stu || sd_student_dropped_out((int)$stu['id'])) {
            $skipped++;
            continue;
        }
        sd_create_dropout(
            (int)$stu['id'],
            $effective_date,
            $reason !== '' ? $reason : null,
            null,               // evidence cannot be attached in bulk (super admin only)
            (int)$me['id']
        );
        $created++;
    }

    $msg = $created . ' student' . ($created === 1 ? '' : 's') . ' recorded as official dropout' . ($created === 1 ? '' : 's') . '.';
    if ($skipped > 0) {
        $msg .= ' ' . $skipped . ' skipped (already dropped out or not found).';
    }
    flash_set($created > 0 ? 'success' : 'error', $msg);
    redirect(APP_URL . '/semester-drop/index.php?kind=dropout');
}

// ── Filter option data ─────────────────────────────────────────────
$departments = $db->query(
    'SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
)->fetchAll();
$programs = $db->query(
    'SELECT id, program_name, dept_id FROM dept_academic_programs WHERE is_active = 1 ORDER BY program_name ASC'
)->fetchAll();
$batches = $db->query(
    'SELECT id, name FROM student_batches WHERE is_active = 1 ORDER BY sort_order ASC, name ASC'
)->fetchAll();

// ── Load matching students ─────────────────────────────────────────
$students    = [];
$dropped_map = [];

if ($has_filter) {
    $where  = [];
    $params = [];
    if ($f_dept > 0) {
        $where[]  = 's.dept_id = ?';
        $params[] = $f_dept;
    }
    if ($f_program > 0) {
        $where[]  = 's.program_id = ?';
        $params[] = $f_program;
    }
    if ($f_batch > 0) {
        // Match the home batch and active batch transfers, like the student list.
        $where[]  = '(s.batch_id = ? OR s.id IN (SELECT sbt.student_id FROM student_batch_transfers sbt WHERE sbt.to_batch_id = ? AND sbt.is_active = 1))';
        $params[] = $f_batch;
        $params[] = $f_batch;
    }
    $where_sql = implode(' AND ', $where);

    $stmt = $db->prepare(
        "SELECT s.id, s.student_id, s.full_name, s.status,
                dep.name AS dept_name, prog.program_name, b.name AS batch_name
           FROM students s
           LEFT JOIN dept_departments dep ON dep.id = s.dept_id
           LEFT JOIN dept_academic_programs prog ON prog.id = s.program_id
           LEFT JOIN student_batches b ON b.id = s.batch_id
          WHERE $where_sql
          ORDER BY LENGTH(s.student_id) ASC, s.student_id ASC"
    );
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Which of these already have an active dropout record?
    if ($students) {
        $sids = array_map('intval', array_column($students, 'id'));
        $phs  = implode(',', array_fill(0, count($sids), '?'));
        $q = $db->prepare(
            "SELECT student_id FROM semester_drops
              WHERE kind = 'dropout' AND status = 'active' AND student_id IN ($phs)"
        );
        $q->execute($sids);
        $dropped_map = array_fill_keys(array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)), true);
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0" style="font-size:.83rem;">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/semester-drop/index.php">Semester Drop / Dropout</a></li>
        <li class="breadcrumb-item active">Bulk Dropout</li>
    </ol>
</nav>

<h1 class="h3 mb-1"><i class="fas fa-users-slash me-2 text-dark"></i>Bulk Dropout</h1>
<p class="text-muted small mb-4">Record official dropouts batch, program or department wise. Filter the students, select them and confirm.</p>

<?= flash_show() ?>

<!-- Step 1: filters -->
<form method="get" class="card mb-4">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label fw-semibold small">Department</label>
                <select name="dept" id="f_dept" class="form-select form-select-sm">
                    <option value="">All departments</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= $f_dept === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold small">Program</label>
                <select name="program" id="f_program" class="form-select form-select-sm">
                    <option value="">All programs</option>
                    <?php foreach ($programs as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" data-dept="<?= (int)$p['dept_id'] ?>" <?= $f_program === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['program_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold small">Batch</label>
                <select name="batch" class="form-select form-select-sm">
                    <option value="">All batches</option>
                    <?php foreach ($batches as $b): ?>
                    <option value="<?= (int)$b['id'] ?>" <?= $f_batch === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-dark btn-sm"><i class="fas fa-search me-1"></i>Load Students</button>
                <?php if ($has_filter): ?>
                <a href="<?= APP_URL ?>/semester-drop/bulk-dropout.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                <?php endif; ?>
            </div>
        </div>
        <small class="text-muted d-block mt-2">Choose at least one filter. Combine department, program and batch to narrow the group.</small>
    </div>
</form>

<?php if ($has_filter): ?>
<?php if (empty($students)): ?>
<div class="alert alert-info"><i class="fas fa-info-circle me-1"></i>No students match the selected filters.</div>
<?php else: ?>

<?php if (!$is_super): ?>
<div class="alert alert-warning">
    <i class="fas fa-lock me-1"></i>
    Evidence cannot be attached per student in bulk, so only a <strong>Super Administrator</strong> can confirm a bulk dropout.
    You can preview the list below, but the confirm button is disabled for your account.
</div>
<?php endif; ?>

<!-- Step 2 + 3: select & confirm -->
<form method="post" action="<?= h($self_url) ?>" class="card">
    <?= csrf_field() ?>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <div class="col-sm-4">
                <label class="form-label fw-semibold">Dropout Effective Date <span class="text-danger">*</span></label>
                <input type="date" name="effective_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                <small class="text-muted">From this date each account is frozen and no longer counted as a due.</small>
            </div>
            <div class="col-sm-8">
                <label class="form-label fw-semibold">Reason <span class="text-muted">(optional, applied to all)</span></label>
                <textarea name="reason" class="form-control" rows="2" placeholder="Why are these students dropping out?"></textarea>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
                <thead>
                    <tr>
                        <th style="width:36px;"><input type="checkbox" id="check_all" class="form-check-input"></th>
                        <th>Student ID</th>
                        <th>Name</th>
                        <th>Department</th>
                        <th>Program</th>
                        <th>Batch</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $s): ?>
                    <?php $already = isset($dropped_map[(int)$s['id']]) || $s['status'] === 'Dropped'; ?>
                    <tr class="<?= $already ? 'table-secondary' : '' ?>">
                        <td>
                            <?php if ($already): ?>
                            <input type="checkbox" class="form-check-input" disabled title="Already dropped out">
                            <?php else: ?>
                            <input type="checkbox" name="student_ids[]" value="<?= (int)$s['id'] ?>" class="form-check-input row-check">
                            <?php endif; ?>
                        </td>
                        <td class="font-monospace"><?= h($s['student_id']) ?></td>
                        <td><?= h($s['full_name']) ?></td>
                        <td><?= h($s['dept_name'] ?? '—') ?></td>
                        <td><?= h($s['program_name'] ?? '—') ?></td>
                        <td><?= h($s['batch_name'] ?? '—') ?></td>
                        <td>
                            <?php if ($already): ?>
                            <span class="badge bg-dark"><i class="fas fa-user-slash me-1"></i>Dropped Out</span>
                            <?php else: ?>
                            <?= h($s['status']) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <small class="text-muted"><?= count($students) ?> student(s) matched; already dropped-out students cannot be selected again.</small>
    </div>
    <div class="card-footer d-flex gap-2 align-items-center">
        <button type="submit" class="btn btn-dark" id="confirm_btn" <?= $is_super ? '' : 'disabled' ?>
                onclick="return confirm('Record the selected students as official dropouts? Their accounts will be frozen and their status set to Dropped.');">
            <i class="fas fa-user-slash me-1"></i>Record Dropout for <span id="sel_count">0</span> Student(s)
        </button>
        <a href="<?= APP_URL ?>/semester-drop/index.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<script>
(function () {
    // ── Program dropdown follows the selected department ──────────────────
    var deptSel = document.getElementById('f_dept');
    var progSel = document.getElementById('f_program');
    function filterPrograms() {
        if (!deptSel || !progSel) return;
        var dept = deptSel.value;
        Array.prototype.forEach.call(progSel.options, function (opt) {
            if (!opt.value) return;
            var show = !dept || opt.getAttribute('data-dept') === dept;
            opt.hidden = !show;
            if (!show && opt.selected) progSel.value = '';
        });
    }
    if (deptSel) deptSel.addEventListener('change', filterPrograms);
    filterPrograms();

    // ── Select-all + live selected count ─────────────────────────────────
    var all    = document.getElementById('check_all');
    var boxes  = Array.prototype.slice.call(document.querySelectorAll('.row-check'));
    var count  = document.getElementById('sel_count');
    var btn    = document.getElementById('confirm_btn');
    var superOk = <?= $is_super ? 'true' : 'false' ?>;

    function refresh() {
        var n = boxes.filter(function (b) { return b.checked; }).length;
        if (count) count.textContent = n;
        if (btn) btn.disabled = !superOk || n === 0;
        if (all) all.checked = boxes.length > 0 && n === boxes.length;
    }
    if (all) {
        all.addEventListener('change', function () {
            boxes.forEach(function (b) { b.checked = all.checked; });
            refresh();
        });
    }
    boxes.forEach(function (b) { b.addEventListener('change', refresh); });
    refresh();
}());
</script>
