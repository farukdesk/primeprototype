<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('exam-invigilation');

$id = (int)($_GET['id'] ?? 0);
$exam_st = db()->prepare('SELECT * FROM ei_exams WHERE id = ?');
$exam_st->execute([$id]);
$exam = $exam_st->fetch();
if (!$exam) {
    flash_set('error', 'Exam not found.');
    redirect(APP_URL . '/exam-invigilation/index.php');
}

$page_title = h($exam['exam_name']) . ' – Invigilation';

// ── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    csrf_check();

    // ── Delete slot ──
    if ($_POST['_action'] === 'delete_slot') {
        require_access('exam-invigilation', 'can_delete');
        $sid = (int)($_POST['slot_id'] ?? 0);
        db()->prepare('DELETE FROM ei_slots WHERE id = ? AND exam_id = ?')->execute([$sid, $id]);
        flash_set('success', 'Slot deleted.');
        redirect(APP_URL . '/exam-invigilation/view.php?id=' . $id);
    }

    // ── Clear assignment for one slot ──
    if ($_POST['_action'] === 'clear_slot') {
        require_access('exam-invigilation', 'can_edit');
        $sid = (int)($_POST['slot_id'] ?? 0);
        db()->prepare('UPDATE ei_slots SET faculty1_id=NULL, faculty2_id=NULL WHERE id = ? AND exam_id = ?')
           ->execute([$sid, $id]);
        flash_set('success', 'Assignment cleared.');
        redirect(APP_URL . '/exam-invigilation/view.php?id=' . $id);
    }

    // ── Auto-assign faculty ───────────────────────────────────────────────────
    if ($_POST['_action'] === 'auto_assign') {
        require_access('exam-invigilation', 'can_edit');

        $scope = isset($_POST['reassign']) ? 'all' : 'unassigned';

        // Fetch all slots for this exam (optionally only unassigned)
        if ($scope === 'all') {
            $slots_st = db()->prepare(
                'SELECT * FROM ei_slots WHERE exam_id = ? ORDER BY slot_date ASC, time_slot ASC'
            );
        } else {
            $slots_st = db()->prepare(
                'SELECT * FROM ei_slots WHERE exam_id = ?
                 AND (faculty1_id IS NULL OR faculty2_id IS NULL)
                 ORDER BY slot_date ASC, time_slot ASC'
            );
        }
        $slots_st->execute([$id]);
        $slots = $slots_st->fetchAll();

        if (empty($slots)) {
            flash_set('info', 'No slots to assign.');
            redirect(APP_URL . '/exam-invigilation/view.php?id=' . $id);
        }

        // Load all active faculty grouped by dept
        $fac_st = db()->query(
            'SELECT f.*, d.name AS dept_name
             FROM ei_faculty f
             JOIN dept_departments d ON d.id = f.dept_id
             WHERE f.is_active = 1
             ORDER BY f.dept_id ASC, f.name ASC'
        );
        $all_faculty = $fac_st->fetchAll();

        if (empty($all_faculty)) {
            flash_set('error', 'No active faculty in the pool. Please add faculty first.');
            redirect(APP_URL . '/exam-invigilation/view.php?id=' . $id);
        }

        $faculty_weekend_map = [];
        foreach ($all_faculty as $f) {
            if (!empty($f['weekend_days'])) {
                $weekend_days = array_values(array_filter(array_map('intval', explode(',', (string)$f['weekend_days'])), static fn ($d) => $d >= 0 && $d <= 6));
            } else {
                $weekend_days = ((int)$f['weekend_available'] === 1) ? [] : [0, 6];
            }
            $faculty_weekend_map[(int)$f['id']] = $weekend_days;
        }

        // Build a map: date+time_slot → array of already-assigned faculty_ids
        // (this includes OTHER exams too, to avoid cross-exam conflicts)
        $busy_st = db()->query(
            "SELECT slot_date, time_slot, faculty1_id, faculty2_id
             FROM ei_slots
             WHERE faculty1_id IS NOT NULL OR faculty2_id IS NOT NULL"
        );
        $busy_map = [];
        foreach ($busy_st->fetchAll() as $r) {
            $key = $r['slot_date'] . '|' . $r['time_slot'];
            if ($r['faculty1_id']) $busy_map[$key][(int)$r['faculty1_id']] = true;
            if ($r['faculty2_id']) $busy_map[$key][(int)$r['faculty2_id']] = true;
        }

        // For "reassign all" mode: first clear all assignments for this exam
        // so they don't block themselves during this run
        if ($scope === 'all') {
            db()->prepare('UPDATE ei_slots SET faculty1_id=NULL, faculty2_id=NULL WHERE exam_id=?')->execute([$id]);
            // Re-build busy_map without this exam's slots
            $busy_st = db()->query(
                "SELECT slot_date, time_slot, faculty1_id, faculty2_id
                 FROM ei_slots
                 WHERE (faculty1_id IS NOT NULL OR faculty2_id IS NOT NULL)"
            );
            $busy_map = [];
            foreach ($busy_st->fetchAll() as $r) {
                $key = $r['slot_date'] . '|' . $r['time_slot'];
                if ($r['faculty1_id']) $busy_map[$key][(int)$r['faculty1_id']] = true;
                if ($r['faculty2_id']) $busy_map[$key][(int)$r['faculty2_id']] = true;
            }
        }

        $assigned_count = 0;
        $partial_count  = 0;
        $failed_count   = 0;

        foreach ($slots as $slot) {
            $slot_date  = $slot['slot_date'];
            $time_slot  = $slot['time_slot'];
            $key        = $slot_date . '|' . $time_slot;

            $day_of_week  = (int)date('w', strtotime($slot_date));

            // Filter eligible faculty
            $eligible = [];
            foreach ($all_faculty as $f) {
                $faculty_weekend_days = $faculty_weekend_map[(int)$f['id']] ?? [];
                if (in_array($day_of_week, $faculty_weekend_days, true)) continue;
                // Skip if already busy in this date+time_slot
                if (isset($busy_map[$key][(int)$f['id']])) continue;
                $eligible[] = $f;
            }

            // Group by department
            $by_dept = [];
            foreach ($eligible as $f) {
                $by_dept[(int)$f['dept_id']][] = $f;
            }

            $f1 = null;
            $f2 = null;

            $dept_ids = array_keys($by_dept);

            if (count($dept_ids) >= 2) {
                // Pick first faculty from dept_ids[0], second from dept_ids[1]
                $f1 = $by_dept[$dept_ids[0]][0];
                $f2 = $by_dept[$dept_ids[1]][0];
            } elseif (count($dept_ids) === 1) {
                // Only one department – pick up to 2 from it
                $pool = $by_dept[$dept_ids[0]];
                $f1   = $pool[0] ?? null;
                $f2   = $pool[1] ?? null;
            }
            // else: no eligible faculty → both remain null

            if ($f1 === null && $f2 === null) {
                $failed_count++;
                continue;
            }

            // Update the slot
            db()->prepare(
                'UPDATE ei_slots SET faculty1_id=?, faculty2_id=? WHERE id=?'
            )->execute([
                $f1 ? (int)$f1['id'] : null,
                $f2 ? (int)$f2['id'] : null,
                (int)$slot['id'],
            ]);

            // Mark these faculty as busy for subsequent slots in the same run
            if ($f1) $busy_map[$key][(int)$f1['id']] = true;
            if ($f2) $busy_map[$key][(int)$f2['id']] = true;

            if ($f1 && $f2) {
                $assigned_count++;
            } else {
                $partial_count++;
            }
        }

        $msg = "Auto-assign complete: {$assigned_count} slot(s) fully assigned";
        if ($partial_count > 0) $msg .= ", {$partial_count} partially assigned";
        if ($failed_count  > 0) $msg .= ", {$failed_count} could not be assigned (no available faculty)";
        $msg .= '.';
        flash_set($failed_count > 0 ? 'warning' : 'success', $msg);
        redirect(APP_URL . '/exam-invigilation/view.php?id=' . $id);
    }

    redirect(APP_URL . '/exam-invigilation/view.php?id=' . $id);
}

// ── Load slots ────────────────────────────────────────────────────────────────
$slots_st = db()->prepare(
    "SELECT s.*,
            f1.name AS f1_name, f1.designation AS f1_desig, d1.name AS f1_dept,
            f2.name AS f2_name, f2.designation AS f2_desig, d2.name AS f2_dept
     FROM ei_slots s
     LEFT JOIN ei_faculty f1 ON f1.id = s.faculty1_id
     LEFT JOIN dept_departments d1 ON d1.id = f1.dept_id
     LEFT JOIN ei_faculty f2 ON f2.id = s.faculty2_id
     LEFT JOIN dept_departments d2 ON d2.id = f2.dept_id
     WHERE s.exam_id = ?
     ORDER BY s.slot_date ASC, s.time_slot ASC, s.room_number ASC"
);
$slots_st->execute([$id]);
$slots = $slots_st->fetchAll();

$total_slots    = count($slots);
$assigned_slots = 0;
$partial_slots  = 0;
foreach ($slots as $s) {
    if ($s['faculty1_id'] && $s['faculty2_id']) $assigned_slots++;
    elseif ($s['faculty1_id'] || $s['faculty2_id']) $partial_slots++;
}
$unassigned_slots = $total_slots - $assigned_slots - $partial_slots;

// Group slots by date for display
$by_date = [];
foreach ($slots as $s) {
    $by_date[$s['slot_date']][] = $s;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/exam-invigilation/index.php">Exam Invigilation</a></li>
            <li class="breadcrumb-item active"><?= h($exam['exam_name']) ?></li>
        </ol>
    </nav>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (is_super_admin() || can_access('exam-invigilation', 'can_create')): ?>
        <a href="<?= APP_URL ?>/exam-invigilation/slot-create.php?exam_id=<?= $id ?>"
           class="btn btn-success btn-sm" style="border-radius:10px;">
            <i class="fas fa-plus me-1"></i> Add Slot
        </a>
        <?php endif; ?>
        <?php if (is_super_admin() || can_access('exam-invigilation', 'can_edit')): ?>
        <a href="<?= APP_URL ?>/exam-invigilation/edit.php?id=<?= $id ?>"
           class="btn btn-outline-primary btn-sm" style="border-radius:10px;">
            <i class="fas fa-edit me-1"></i> Edit Exam
        </a>
        <?php endif; ?>
    </div>
</div>

<?php flash_show(); ?>

<!-- Exam summary card -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card text-center py-3">
            <div style="font-size:1.8rem;font-weight:700;color:#4f8ef7;"><?= $total_slots ?></div>
            <div class="text-muted" style="font-size:.8rem;">Total Slots</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center py-3">
            <div style="font-size:1.8rem;font-weight:700;color:#27ae60;"><?= $assigned_slots ?></div>
            <div class="text-muted" style="font-size:.8rem;">Fully Assigned</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center py-3">
            <div style="font-size:1.8rem;font-weight:700;color:#f39c12;"><?= $partial_slots ?></div>
            <div class="text-muted" style="font-size:.8rem;">Partially Assigned</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center py-3">
            <div style="font-size:1.8rem;font-weight:700;color:#e74c3c;"><?= $unassigned_slots ?></div>
            <div class="text-muted" style="font-size:.8rem;">Unassigned</div>
        </div>
    </div>
</div>

<!-- Auto-assign panel -->
<?php if (is_super_admin() || can_access('exam-invigilation', 'can_edit')): ?>
<div class="card mb-4" style="border-left:4px solid #4f8ef7;">
    <div class="card-body py-3 px-4">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
                <h6 class="mb-1 fw-semibold"><i class="fas fa-magic me-2 text-primary"></i>Auto-Assign Invigilators</h6>
                <p class="mb-0 text-muted" style="font-size:.85rem;">
                    Automatically assigns 2 faculty per room slot — one from each department — without overlapping time slots.
                </p>
            </div>
            <form method="POST" class="d-flex gap-2 align-items-center">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="auto_assign">
                <div class="form-check me-2">
                    <input class="form-check-input" type="checkbox" name="reassign" id="reassignAll" value="1">
                    <label class="form-check-label" for="reassignAll" style="font-size:.85rem;">Re-assign all (clear existing)</label>
                </div>
                <button type="submit" class="btn btn-primary btn-sm" style="border-radius:10px;"
                        onclick="return confirm('Run auto-assignment? This will fill unassigned slots (or all slots if \'Re-assign all\' is checked).');">
                    <i class="fas fa-magic me-1"></i> Auto-Assign
                </button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Slots by date -->
<?php if (empty($slots)): ?>
<div class="card">
    <div class="card-body text-center text-muted py-5">
        <i class="fas fa-calendar-times fa-3x mb-3 d-block" style="opacity:.2;"></i>
        No slots added yet.
        <?php if (is_super_admin() || can_access('exam-invigilation', 'can_create')): ?>
        <div class="mt-2">
            <a href="<?= APP_URL ?>/exam-invigilation/slot-create.php?exam_id=<?= $id ?>" class="btn btn-sm btn-primary" style="border-radius:10px;">
                <i class="fas fa-plus me-1"></i> Add First Slot
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<?php foreach ($by_date as $date => $date_slots): ?>
<div class="card mb-3">
    <div class="card-header py-2 px-4 d-flex align-items-center justify-content-between"
         style="background:#f8f9ff;border-bottom:1px solid #e8ecf5;">
        <span class="fw-semibold" style="font-size:.9rem;">
            <i class="fas fa-calendar-day me-2 text-primary"></i>
            <?= date('l, d F Y', strtotime($date)) ?>
        </span>
        <span class="badge bg-secondary bg-opacity-15 text-secondary"><?= count($date_slots) ?> room(s)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">Room</th>
                        <th>Time Slot</th>
                        <th>Invigilator 1</th>
                        <th>Invigilator 2</th>
                        <th>Status</th>
                        <?php if (is_super_admin() || can_access('exam-invigilation', 'can_edit') || can_access('exam-invigilation', 'can_delete')): ?>
                        <th class="text-end pe-4">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($date_slots as $s): ?>
                <?php
                $fully_assigned  = $s['faculty1_id'] && $s['faculty2_id'];
                $partly_assigned = ($s['faculty1_id'] || $s['faculty2_id']) && !$fully_assigned;
                ?>
                <tr class="<?= $fully_assigned ? '' : ($partly_assigned ? 'table-warning' : 'table-danger bg-opacity-10') ?>">
                    <td class="px-4 fw-medium"><?= h($s['room_number']) ?></td>
                    <td><?= h($s['time_slot']) ?></td>
                    <td>
                        <?php if ($s['f1_name']): ?>
                        <div class="fw-medium"><?= h($s['f1_name']) ?></div>
                        <?php if ($s['f1_desig']): ?><small class="text-muted"><?= h($s['f1_desig']) ?></small><?php endif; ?>
                        <?php if ($s['f1_dept']): ?><small class="text-primary d-block"><?= h($s['f1_dept']) ?></small><?php endif; ?>
                        <?php else: ?>
                        <span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>Not assigned</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($s['f2_name']): ?>
                        <div class="fw-medium"><?= h($s['f2_name']) ?></div>
                        <?php if ($s['f2_desig']): ?><small class="text-muted"><?= h($s['f2_desig']) ?></small><?php endif; ?>
                        <?php if ($s['f2_dept']): ?><small class="text-primary d-block"><?= h($s['f2_dept']) ?></small><?php endif; ?>
                        <?php else: ?>
                        <span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>Not assigned</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($fully_assigned): ?>
                        <span class="badge bg-success">Assigned</span>
                        <?php elseif ($partly_assigned): ?>
                        <span class="badge bg-warning text-dark">Partial</span>
                        <?php else: ?>
                        <span class="badge bg-danger">Unassigned</span>
                        <?php endif; ?>
                    </td>
                    <?php if (is_super_admin() || can_access('exam-invigilation', 'can_edit') || can_access('exam-invigilation', 'can_delete')): ?>
                    <td class="text-end pe-4">
                        <div class="d-flex gap-1 justify-content-end">
                            <?php if (is_super_admin() || can_access('exam-invigilation', 'can_edit')): ?>
                            <a href="<?= APP_URL ?>/exam-invigilation/slot-edit.php?id=<?= $s['id'] ?>&exam_id=<?= $id ?>"
                               class="btn btn-sm btn-outline-primary" style="border-radius:7px;" title="Edit Slot">
                                <i class="fas fa-edit"></i>
                            </a>
                            <?php if ($s['faculty1_id'] || $s['faculty2_id']): ?>
                            <form method="POST" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="clear_slot">
                                <input type="hidden" name="slot_id" value="<?= $s['id'] ?>">
                                <button class="btn btn-sm btn-outline-warning" style="border-radius:7px;" title="Clear Assignment"
                                        onclick="return confirm('Clear assignment for Room <?= h(addslashes($s['room_number'])) ?>?');">
                                    <i class="fas fa-user-times"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php endif; ?>
                            <?php if (is_super_admin() || can_access('exam-invigilation', 'can_delete')): ?>
                            <form method="POST" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="delete_slot">
                                <input type="hidden" name="slot_id" value="<?= $s['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" style="border-radius:7px;" title="Delete Slot"
                                        onclick="return confirm('Delete this slot (Room <?= h(addslashes($s['room_number'])) ?>)?');">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
