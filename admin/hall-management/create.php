<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('hall-management', 'can_create');
require_once __DIR__ . '/helpers.php';

$page_title  = 'New Hall / Room';
hm_ensure_schedule_columns();
$departments = hm_departments();

$errors = [];
$old    = [
    'dept_id'     => (int)($_POST['dept_id'] ?? 0),
    'room_number' => trim($_POST['room_number'] ?? ''),
    'exam_date'   => trim($_POST['exam_date'] ?? ''),
    'exam_time'   => trim($_POST['exam_time'] ?? ''),
    'num_columns' => (int)($_POST['num_columns'] ?? 0),
    'num_rows'    => (int)($_POST['num_rows'] ?? 0),
    'notes'       => trim($_POST['notes'] ?? ''),
    'is_active'   => isset($_POST['is_active']) ? 1 : 1,
];
$old_caps = array_values(array_map('intval', (array)($_POST['col_capacity'] ?? [])));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $old['is_active'] = isset($_POST['is_active']) ? 1 : 0;

    if ($old['dept_id'] <= 0) {
        $errors[] = 'Please select a department.';
    } elseif (!can_access_dept($old['dept_id'])) {
        $errors[] = 'You do not have permission for that department.';
    }
    if ($old['room_number'] === '') $errors[] = 'Room number is required.';
    if ($old['exam_date'] === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['exam_date'])) {
        $errors[] = 'Exam date is required.';
    }
    if ($old['exam_time'] === '' || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $old['exam_time'])) {
        $errors[] = 'Exam time is required.';
    }
    if ($old['num_columns'] < 1 || $old['num_columns'] > 50) $errors[] = 'Number of columns must be between 1 and 50.';
    if ($old['num_rows'] < 1 || $old['num_rows'] > 500)      $errors[] = 'Number of rows must be between 1 and 500.';

    $caps = [];
    if (!$errors) {
        [$caps, $cap_err] = hm_parse_columns($old_caps, $old['num_columns']);
        if ($cap_err) $errors[] = $cap_err;
    }

    if (!$errors && hm_room_slot_taken($old['room_number'], $old['exam_date'], $old['exam_time'])) {
        $errors[] = 'Room ' . $old['room_number'] . ' is already booked on ' . $old['exam_date']
                  . ' at ' . date('g:i A', strtotime($old['exam_time']))
                  . '. The same room cannot be used twice on the same date and time — pick a different time or room.';
    }

    if (!$errors) {
        try {
            db()->beginTransaction();
            $st = db()->prepare(
                'INSERT INTO hm_halls
                        (dept_id, room_number, exam_date, exam_time, num_columns, num_rows, total_capacity, notes, is_active, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $st->execute([
                $old['dept_id'],
                $old['room_number'],
                $old['exam_date'],
                $old['exam_time'],
                $old['num_columns'],
                $old['num_rows'],
                array_sum($caps),
                $old['notes'] !== '' ? $old['notes'] : null,
                $old['is_active'],
                (int)(auth_user()['id'] ?? 0) ?: null,
            ]);
            $hall_id = (int)db()->lastInsertId();
            hm_save_columns($hall_id, $caps);
            db()->commit();
            flash_set('success', 'Hall "' . $old['room_number'] . '" created with ' . array_sum($caps) . ' seats.');
            redirect(APP_URL . '/hall-management/view.php?id=' . $hall_id);
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            $errors[] = 'Could not save the hall. Make sure admin/hall-management-schema.sql has been run.';
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/hall-management/index.php">Hall Management</a></li>
            <li class="breadcrumb-item active">New Hall / Room</li>
        </ol>
    </nav>
</div>

<?php flash_show(); ?>

<?php if ($errors): ?>
<div class="alert alert-danger" style="border-radius:12px;">
    <ul class="mb-0">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" id="hallForm">
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card" style="border-radius:12px;">
                <div class="card-header bg-white fw-semibold" style="border-radius:12px 12px 0 0;">
                    <i class="fas fa-door-open me-2 text-primary"></i>Hall Details
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Department <span class="text-danger">*</span></label>
                        <select name="dept_id" class="form-select" required>
                            <option value="">Select department…</option>
                            <?php foreach ($departments as $d): ?>
                            <option value="<?= $d['id'] ?>" <?= $old['dept_id'] === (int)$d['id'] ? 'selected' : '' ?>>
                                <?= h($d['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Room Number <span class="text-danger">*</span></label>
                        <input type="text" name="room_number" class="form-control" maxlength="100" required
                               value="<?= h($old['room_number']) ?>" placeholder="e.g. 401, Hall-A">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-7">
                            <label class="form-label fw-medium">Exam Date <span class="text-danger">*</span></label>
                            <input type="date" name="exam_date" class="form-control" required value="<?= h($old['exam_date']) ?>">
                        </div>
                        <div class="col-5">
                            <label class="form-label fw-medium">Exam Time <span class="text-danger">*</span></label>
                            <input type="time" name="exam_time" class="form-control" required value="<?= h($old['exam_time'] !== '' ? substr($old['exam_time'], 0, 5) : '') ?>">
                        </div>
                        <div class="form-text">A room can't be booked twice for the same date and time — the same date with a different time is okay.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Notes</label>
                        <textarea name="notes" class="form-control" rows="2" maxlength="500"
                                  placeholder="Optional notes (building, floor, facilities…)"><?= h($old['notes']) ?></textarea>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" id="isActive"
                               <?= $old['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="isActive">Active</label>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card" style="border-radius:12px;">
                <div class="card-header bg-white fw-semibold" style="border-radius:12px 12px 0 0;">
                    <i class="fas fa-th me-2 text-primary"></i>Seat Layout Generator
                </div>
                <div class="card-body">
                    <div class="row g-2 align-items-end mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-medium mb-1">How many columns? <span class="text-danger">*</span></label>
                            <input type="number" name="num_columns" id="numColumns" class="form-control"
                                   min="1" max="50" required value="<?= $old['num_columns'] ?: '' ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium mb-1">How many rows? <span class="text-danger">*</span></label>
                            <input type="number" name="num_rows" id="numRows" class="form-control"
                                   min="1" max="500" required value="<?= $old['num_rows'] ?: '' ?>">
                        </div>
                        <div class="col-md-4">
                            <button type="button" id="generateCols" class="btn btn-outline-primary w-100" style="border-radius:8px;">
                                <i class="fas fa-magic me-1"></i> Generate Columns
                            </button>
                        </div>
                    </div>
                    <div class="form-text mb-3">
                        Set the number of columns and rows, then click <strong>Generate Columns</strong> to set each
                        column's seat capacity (pre-filled with the row count).
                    </div>
                    <div id="columnsWrap" class="row g-2"></div>
                    <hr>
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="fw-semibold">Total Seat Capacity:
                            <span class="badge bg-primary" id="totalCapacity" style="font-size:.95rem;">0 seats</span>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="<?= APP_URL ?>/hall-management/index.php" class="btn btn-outline-secondary" style="border-radius:8px;">Cancel</a>
                            <button type="submit" class="btn btn-primary" style="border-radius:8px;">
                                <i class="fas fa-save me-1"></i> Save Hall
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
(function () {
    const numColumns = document.getElementById('numColumns');
    const numRows    = document.getElementById('numRows');
    const wrap       = document.getElementById('columnsWrap');
    const totalEl    = document.getElementById('totalCapacity');
    const initial    = <?= json_encode($old_caps) ?>;

    function updateTotal() {
        let total = 0;
        wrap.querySelectorAll('input[name="col_capacity[]"]').forEach(i => total += (parseInt(i.value, 10) || 0));
        totalEl.textContent = total + ' seats';
    }

    function buildColumns(caps) {
        const n = parseInt(numColumns.value, 10) || 0;
        const r = parseInt(numRows.value, 10) || 0;
        wrap.innerHTML = '';
        if (n < 1) { updateTotal(); return; }
        for (let i = 0; i < n; i++) {
            const col = document.createElement('div');
            col.className = 'col-md-3 col-sm-4 col-6';
            const cap = (caps && caps[i]) ? caps[i] : (r > 0 ? r : '');
            col.innerHTML =
                '<label class="form-label mb-1" style="font-size:.8rem;">Column ' + (i + 1) + ' seats</label>' +
                '<input type="number" name="col_capacity[]" class="form-control form-control-sm" min="1" max="500" required value="' + cap + '">';
            wrap.appendChild(col);
        }
        updateTotal();
    }

    document.getElementById('generateCols').addEventListener('click', () => buildColumns(null));
    wrap.addEventListener('input', updateTotal);

    if (initial.length > 0) buildColumns(initial);
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
