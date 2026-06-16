<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/slot-helpers.php';
require_access('exam-invigilation');

$page_title = 'Exam Invigilation';

// ── Handle inline actions ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    csrf_check();
    $eid = (int)($_POST['id'] ?? 0);

    if ($_POST['_action'] === 'toggle') {
        db()->prepare('UPDATE ei_exams SET is_active = 1 - is_active WHERE id = ?')->execute([$eid]);
        flash_set('success', 'Status updated.');
    } elseif ($_POST['_action'] === 'save_settings') {
        require_access('exam-invigilation', 'can_edit');
        $auto_assign_max_slots         = max(1, min(100, (int)($_POST['auto_assign_max_slots'] ?? 12)));
        $auto_assign_max_slots_per_day = max(1, min(50,  (int)($_POST['auto_assign_max_slots_per_day'] ?? 3)));
        ei_save_setting('auto_assign_max_slots',         (string)$auto_assign_max_slots);
        ei_save_setting('auto_assign_max_slots_per_day', (string)$auto_assign_max_slots_per_day);
        flash_set('success', 'Auto-assign slot caps updated.');    } elseif ($_POST['_action'] === 'delete') {
        require_access('exam-invigilation', 'can_delete');
        db()->prepare('DELETE FROM ei_exams WHERE id = ?')->execute([$eid]);
        flash_set('success', 'Exam deleted.');
    }
    redirect(APP_URL . '/exam-invigilation/index.php');
}

// ── Filters ──────────────────────────────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 20;

$where  = [];
$params = [];
if ($search !== '') {
    $where[]  = '(e.exam_name LIKE ? OR e.exam_year LIKE ?)';
    $s        = '%' . $search . '%';
    $params   = [$s, $s];
}

$sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$cnt_st = db()->prepare("SELECT COUNT(*) FROM ei_exams e $sql_where");
$cnt_st->execute($params);
$total = (int)$cnt_st->fetchColumn();

$pages  = max(1, (int)ceil($total / $per));
$page   = min($page, $pages);
$offset = ($page - 1) * $per;

$st = db()->prepare(
    "SELECT e.*,
            (SELECT COUNT(*) FROM ei_slots s WHERE s.exam_id = e.id) AS slot_count,
            (SELECT COUNT(*) FROM ei_slots s WHERE s.exam_id = e.id AND s.faculty1_id IS NOT NULL AND s.faculty2_id IS NOT NULL) AS assigned_count
     FROM ei_exams e $sql_where
     ORDER BY e.exam_year DESC, e.exam_name ASC
     LIMIT $per OFFSET $offset"
);
$st->execute($params);
$rows = $st->fetchAll();

// ── Faculty availability stats ────────────────────────────────────────────────
$total_active_faculty = (int)db()->query('SELECT COUNT(*) FROM ei_faculty WHERE is_active = 1')->fetchColumn();
// Faculty already assigned in at least one slot across all active exams
$assigned_faculty_count = (int)db()->query(
    'SELECT COUNT(DISTINCT fid) FROM (
        SELECT faculty1_id AS fid FROM ei_slots s JOIN ei_exams e ON e.id = s.exam_id WHERE e.is_active = 1 AND s.faculty1_id IS NOT NULL
        UNION
        SELECT faculty2_id FROM ei_slots s JOIN ei_exams e ON e.id = s.exam_id WHERE e.is_active = 1 AND s.faculty2_id IS NOT NULL
    ) t'
)->fetchColumn();
$available_backup_faculty = $total_active_faculty - $assigned_faculty_count;
$auto_assign_max_slots         = ei_get_auto_assign_max_slots();
$auto_assign_max_slots_per_day = ei_get_auto_assign_max_slots_per_day();

// Fetch the actual list of available/backup faculty (not yet assigned in any active exam)
$backup_faculty_rows = db()->query(
    "SELECT f.id, f.name, f.designation, f.gender, f.weekend_days, f.weekend_available, f.contact_number, d.name AS dept_name
     FROM ei_faculty f
     JOIN dept_departments d ON d.id = f.dept_id
     WHERE f.is_active = 1
       AND f.id NOT IN (
           SELECT DISTINCT fid FROM (
               SELECT faculty1_id AS fid FROM ei_slots s JOIN ei_exams e ON e.id = s.exam_id WHERE e.is_active = 1 AND s.faculty1_id IS NOT NULL
               UNION
               SELECT faculty2_id FROM ei_slots s JOIN ei_exams e ON e.id = s.exam_id WHERE e.is_active = 1 AND s.faculty2_id IS NOT NULL
           ) t
       )
     ORDER BY d.name ASC, f.name ASC"
)->fetchAll();

$backup_opportunities = [];
if (!empty($backup_faculty_rows)) {
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

    $time_slot_start_sql = "TRIM(SUBSTRING_INDEX(REPLACE(s.time_slot, '-', '–'), '–', 1))";
    $time_order_sql = "COALESCE(
        STR_TO_DATE({$time_slot_start_sql}, '%h:%i %p'),
        STR_TO_DATE({$time_slot_start_sql}, '%H:%i')
    )";
    $backup_slot_rows = db()->query(
        "SELECT s.id, s.slot_date, s.time_slot, s.room_number, e.exam_name
         FROM ei_slots s
         JOIN ei_exams e ON e.id = s.exam_id
         WHERE e.is_active = 1
         ORDER BY s.slot_date ASC, {$time_order_sql} ASC, s.time_slot ASC, s.room_number ASC"
    )->fetchAll();

    foreach ($backup_slot_rows as $slot) {
        $eligible_teachers = [];
        foreach ($backup_faculty_rows as $faculty) {
            if (ei_is_faculty_eligible_for_slot($faculty, $slot, $busy_map)) {
                $eligible_teachers[] = $faculty;
            }
        }
        if (!empty($eligible_teachers)) {
            $backup_opportunities[] = [
                'slot' => $slot,
                'teachers' => $eligible_teachers,
            ];
        }
    }
}

// ── Vacant Slots Report ───────────────────────────────────────────────────────
// Slots in active exams that still have an empty invigilator seat, with
// a list of all faculty eligible to fill them (respects total & daily caps).
$vacant_slot_report = [];
{
    $time_slot_start_sql = "TRIM(SUBSTRING_INDEX(REPLACE(s.time_slot, '-', '–'), '–', 1))";
    $vsr_time_order = "COALESCE(
        STR_TO_DATE({$time_slot_start_sql}, '%h:%i %p'),
        STR_TO_DATE({$time_slot_start_sql}, '%H:%i')
    )";

    // All slots with at least one empty invigilator seat in active exams
    $vacant_slots = db()->query(
        "SELECT s.id, s.slot_date, s.time_slot, s.room_number, s.faculty1_id, s.faculty2_id, e.exam_name, e.id AS exam_id
         FROM ei_slots s
         JOIN ei_exams e ON e.id = s.exam_id
         WHERE e.is_active = 1
           AND (s.faculty1_id IS NULL OR s.faculty2_id IS NULL)
         ORDER BY s.slot_date ASC, {$vsr_time_order} ASC, s.time_slot ASC, s.room_number ASC"
    )->fetchAll();

    if (!empty($vacant_slots)) {
        // All active faculty
        $all_fac_rows = db()->query(
            "SELECT f.id, f.name, f.designation, f.gender, f.weekend_days, f.weekend_available, d.name AS dept_name, f.dept_id
             FROM ei_faculty f
             JOIN dept_departments d ON d.id = f.dept_id
             WHERE f.is_active = 1
             ORDER BY d.name ASC, f.name ASC"
        )->fetchAll();

        // busy map: date|time_slot → [faculty_id => true]
        $vsr_busy = [];
        foreach (db()->query(
            "SELECT slot_date, time_slot, faculty1_id, faculty2_id FROM ei_slots
             WHERE faculty1_id IS NOT NULL OR faculty2_id IS NOT NULL"
        )->fetchAll() as $r) {
            $k = $r['slot_date'] . '|' . $r['time_slot'];
            if ($r['faculty1_id']) $vsr_busy[$k][(int)$r['faculty1_id']] = true;
            if ($r['faculty2_id']) $vsr_busy[$k][(int)$r['faculty2_id']] = true;
        }

        // total slot count per faculty across all active exams
        $vsr_total_count = [];
        foreach (db()->query(
            "SELECT faculty_id, COUNT(*) AS c FROM (
                SELECT faculty1_id AS faculty_id FROM ei_slots s JOIN ei_exams e ON e.id=s.exam_id
                 WHERE e.is_active=1 AND s.faculty1_id IS NOT NULL
                UNION ALL
                SELECT faculty2_id FROM ei_slots s JOIN ei_exams e ON e.id=s.exam_id
                 WHERE e.is_active=1 AND s.faculty2_id IS NOT NULL
             ) t GROUP BY faculty_id"
        )->fetchAll() as $r) {
            $vsr_total_count[(int)$r['faculty_id']] = (int)$r['c'];
        }

        // per-day slot count per faculty across all active exams
        $vsr_day_count = [];
        foreach (db()->query(
            "SELECT faculty_id, slot_date, COUNT(*) AS c FROM (
                SELECT faculty1_id AS faculty_id, s.slot_date FROM ei_slots s JOIN ei_exams e ON e.id=s.exam_id
                 WHERE e.is_active=1 AND s.faculty1_id IS NOT NULL
                UNION ALL
                SELECT faculty2_id, s.slot_date FROM ei_slots s JOIN ei_exams e ON e.id=s.exam_id
                 WHERE e.is_active=1 AND s.faculty2_id IS NOT NULL
             ) t GROUP BY faculty_id, slot_date"
        )->fetchAll() as $r) {
            $vsr_day_count[(int)$r['faculty_id']][(string)$r['slot_date']] = (int)$r['c'];
        }

        foreach ($vacant_slots as $vs) {
            $eligible = [];
            $already_assigned_ids = array_filter([
                (int)$vs['faculty1_id'],
                (int)$vs['faculty2_id'],
            ]);
            foreach ($all_fac_rows as $f) {
                $fid = (int)$f['id'];
                // Skip if already assigned to this slot
                if (in_array($fid, $already_assigned_ids, true)) continue;
                // Weekend check
                $dow = (int)date('w', strtotime((string)$vs['slot_date']));
                if (in_array($dow, ei_get_faculty_weekend_days($f), true)) continue;
                // Overlap check (same date+time_slot)
                $bk = $vs['slot_date'] . '|' . $vs['time_slot'];
                if (isset($vsr_busy[$bk][$fid])) continue;
                // Late-slot female restriction
                if (ei_slot_starts_after_6pm((string)$vs['time_slot']) && ($f['gender'] ?? '') === 'Female') continue;
                // Total cap
                if (($vsr_total_count[$fid] ?? 0) >= $auto_assign_max_slots) continue;
                // Daily cap
                if (($vsr_day_count[$fid][$vs['slot_date']] ?? 0) >= $auto_assign_max_slots_per_day) continue;
                $eligible[] = $f;
            }
            $vacant_slot_report[] = [
                'slot'     => $vs,
                'teachers' => $eligible,
            ];
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Exam Invigilation</li>
        </ol>
    </nav>
    <div class="d-flex gap-2">
        <?php if (is_super_admin() || can_access('exam-invigilation', 'can_create')): ?>
        <a href="<?= APP_URL ?>/exam-invigilation/faculty.php" class="btn btn-outline-secondary btn-sm" style="border-radius:10px;">
            <i class="fas fa-users me-1"></i> Faculty Pool
        </a>
        <a href="<?= APP_URL ?>/exam-invigilation/create.php" class="btn btn-primary btn-sm" style="border-radius:10px;">
            <i class="fas fa-plus me-1"></i> New Exam
        </a>
        <?php endif; ?>
    </div>
</div>

<?php flash_show(); ?>

<?php if (is_super_admin() || can_access('exam-invigilation', 'can_edit')): ?>
<div class="card mb-3" style="border-left:4px solid #4f8ef7;">
    <div class="card-body py-3 px-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h6 class="mb-1 fw-semibold"><i class="fas fa-sliders-h me-2 text-primary"></i>Auto-Assign Slot Caps</h6>
                <p class="mb-0 text-muted" style="font-size:.85rem;">
                    Each teacher can receive up to <strong><?= $auto_assign_max_slots ?></strong> slots total
                    and up to <strong><?= $auto_assign_max_slots_per_day ?></strong> slot<?= $auto_assign_max_slots_per_day === 1 ? '' : 's' ?> per day during auto-assign.
                </p>
            </div>
            <form method="POST" class="d-flex align-items-center gap-3 flex-wrap">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="save_settings">
                <div class="d-flex align-items-center gap-2">
                    <label for="autoAssignMaxSlots" class="small text-muted mb-0 text-nowrap">Max slots (total)</label>
                    <input type="number" min="1" max="100" name="auto_assign_max_slots" id="autoAssignMaxSlots"
                           value="<?= $auto_assign_max_slots ?>" class="form-control form-control-sm" style="width:80px;">
                </div>
                <div class="d-flex align-items-center gap-2">
                    <label for="autoAssignMaxSlotsPerDay" class="small text-muted mb-0 text-nowrap">Max slots per day</label>
                    <input type="number" min="1" max="50" name="auto_assign_max_slots_per_day" id="autoAssignMaxSlotsPerDay"
                           value="<?= $auto_assign_max_slots_per_day ?>" class="form-control form-control-sm" style="width:80px;">
                </div>
                <button class="btn btn-sm btn-primary" style="border-radius:8px;">Save</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Faculty Availability / Backup Panel -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card text-center py-3" style="border-left:4px solid #27ae60;">
            <div style="font-size:1.8rem;font-weight:700;color:#27ae60;"><?= $total_active_faculty ?></div>
            <div class="text-muted" style="font-size:.8rem;">Total Active Faculty</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center py-3" style="border-left:4px solid #f39c12;">
            <div style="font-size:1.8rem;font-weight:700;color:#f39c12;"><?= $assigned_faculty_count ?></div>
            <div class="text-muted" style="font-size:.8rem;">Already Assigned (Active Exams)</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center py-3" style="border-left:4px solid #4f8ef7;">
            <div style="font-size:1.8rem;font-weight:700;color:#4f8ef7;"><?= max(0, $available_backup_faculty) ?></div>
            <div class="text-muted" style="font-size:.8rem;">Available for Backup / Extra Slots</div>
        </div>
    </div>
</div>
<div class="alert alert-info py-2 px-3 mb-3 d-flex align-items-center gap-2" style="font-size:.85rem;">
    <i class="fas fa-info-circle"></i>
    <span><strong><?= max(0, $available_backup_faculty) ?> faculty</strong> have not yet been assigned any invigilator duty in active exams and are available as backup if someone is absent or an extra slot is needed. Auto-assign respects the <?= $auto_assign_max_slots ?>-slot total cap and <?= $auto_assign_max_slots_per_day ?>-slot daily cap per teacher.</span>
    <?php if (!empty($backup_faculty_rows)): ?>
    <button class="ms-auto btn btn-sm btn-outline-info" style="border-radius:8px;white-space:nowrap;"
            type="button" data-bs-toggle="collapse" data-bs-target="#backupFacultyList" aria-expanded="false">
        <i class="fas fa-list me-1"></i> View List
    </button>
    <?php else: ?>
    <a href="<?= APP_URL ?>/exam-invigilation/faculty.php" class="ms-auto btn btn-sm btn-outline-info" style="border-radius:8px;white-space:nowrap;">View Faculty Pool</a>
    <?php endif; ?>
</div>

<?php if (!empty($backup_faculty_rows)): ?>
<div class="collapse mb-3" id="backupFacultyList">
    <div class="card">
        <div class="card-header py-2 px-4 d-flex align-items-center justify-content-between">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-user-clock me-2 text-muted"></i>Available for Backup / Extra Slots</h6>
            <span class="badge bg-info bg-opacity-15 text-info"><?= count($backup_faculty_rows) ?> faculty</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="px-4" style="width:40px;">#</th>
                            <th>Name</th>
                            <th>Department</th>
                            <th>Designation</th>
                            <th>Gender</th>
                            <th>Contact</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($backup_faculty_rows as $bi => $bf): ?>
                    <tr>
                        <td class="px-4"><?= $bi + 1 ?></td>
                        <td class="fw-medium"><?= h($bf['name']) ?></td>
                        <td><span class="badge bg-primary bg-opacity-10 text-primary"><?= h($bf['dept_name']) ?></span></td>
                        <td><?= $bf['designation'] ? h($bf['designation']) : '<span class="text-muted">—</span>' ?></td>
                        <td>
                            <?php if (!empty($bf['gender'])): ?>
                            <span class="badge" style="background:<?= $bf['gender'] === 'Female' ? '#e83e8c' : '#0dcaf0' ?>;color:#fff;">
                                <?= h($bf['gender']) ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $bf['contact_number'] ? h($bf['contact_number']) : '<span class="text-muted">—</span>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($backup_opportunities)): ?>
<div class="card mb-4">
    <div class="card-header py-2 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-user-shield me-2 text-muted"></i>Backup Coverage by Date / Time / Room</h6>
        <span class="badge bg-primary bg-opacity-10 text-primary"><?= count($backup_opportunities) ?> slot<?= count($backup_opportunities) === 1 ? '' : 's' ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">Date</th>
                        <th>Time Slot</th>
                        <th>Room</th>
                        <th>Exam</th>
                        <th>Eligible Backup Teachers</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($backup_opportunities as $entry): ?>
                <?php $slot = $entry['slot']; ?>
                <tr>
                    <td class="px-4"><?= date('d M Y', strtotime($slot['slot_date'])) ?></td>
                    <td><?= h($slot['time_slot']) ?></td>
                    <td class="fw-medium"><?= h($slot['room_number']) ?></td>
                    <td><?= h($slot['exam_name']) ?></td>
                    <td>
                        <div class="d-flex flex-wrap gap-1">
                            <?php foreach ($entry['teachers'] as $teacher): ?>
                            <span class="badge bg-success bg-opacity-10 text-success border border-success-subtle">
                                <?= h($teacher['name']) ?><?php if (!empty($teacher['dept_name'])): ?> · <?= h($teacher['dept_name']) ?><?php endif; ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($vacant_slot_report)): ?>
<div class="card mb-4">
    <div class="card-header py-2 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-exclamation-circle me-2 text-warning"></i>Vacant Slots — Available Teachers (incl. if faculty absent)</h6>
        <button class="btn btn-sm btn-outline-warning" style="border-radius:8px;white-space:nowrap;"
                type="button" data-bs-toggle="collapse" data-bs-target="#vacantSlotReport" aria-expanded="false">
            <i class="fas fa-list me-1"></i> <?= count($vacant_slot_report) ?> slot<?= count($vacant_slot_report) === 1 ? '' : 's' ?> with vacancy
        </button>
    </div>
    <div class="collapse" id="vacantSlotReport">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="px-4">Date</th>
                            <th>Time Slot</th>
                            <th>Room</th>
                            <th>Exam</th>
                            <th>Vacant Seat</th>
                            <th>Eligible Teachers (within caps)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($vacant_slot_report as $entry): ?>
                    <?php $vs = $entry['slot']; ?>
                    <tr>
                        <td class="px-4"><?= date('d M Y', strtotime($vs['slot_date'])) ?></td>
                        <td><?= h($vs['time_slot']) ?></td>
                        <td class="fw-medium"><?= h($vs['room_number']) ?></td>
                        <td><a href="<?= APP_URL ?>/exam-invigilation/view.php?id=<?= (int)$vs['exam_id'] ?>" class="text-decoration-none"><?= h($vs['exam_name']) ?></a></td>
                        <td>
                            <?php if ($vs['faculty1_id'] === null): ?>
                            <span class="badge bg-danger bg-opacity-15 text-danger">Invigilator 1 &amp; 2 missing</span>
                            <?php elseif ($vs['faculty2_id'] === null): ?>
                            <span class="badge bg-warning bg-opacity-15 text-warning">Invigilator 2 missing</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($entry['teachers'])): ?>
                            <div class="d-flex flex-wrap gap-1">
                                <?php foreach ($entry['teachers'] as $t): ?>
                                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle" title="<?= h($t['dept_name'] ?? '') ?>">
                                    <?= h($t['name']) ?><?php if (!empty($t['dept_name'])): ?> · <?= h($t['dept_name']) ?><?php endif; ?>
                                </span>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <span class="text-muted small">No eligible teacher available (caps reached or weekend)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-2 text-muted" style="font-size:.78rem;">
                <i class="fas fa-info-circle me-1"></i>
                Eligible teachers respect the <?= $auto_assign_max_slots ?>-slot total cap, <?= $auto_assign_max_slots_per_day ?>-slot daily cap, weekend rules, and the after-6 PM female restriction.
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Search -->
<div class="card mb-3">
    <div class="card-body py-2 px-3">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-12 col-md-6">
                <input type="text" name="q" class="form-control form-control-sm" style="border-radius:8px;"
                       placeholder="Search exam name or year…" value="<?= h($search) ?>">
            </div>
            <div class="col-auto d-flex gap-2">
                <button class="btn btn-sm btn-primary" style="border-radius:8px;">Filter</button>
                <a href="?" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-user-check me-2 text-muted"></i>Exams</h6>
        <span class="badge bg-primary bg-opacity-10 text-primary"><?= $total ?> total</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Exam Name</th>
                        <th>Year</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Slots</th>
                        <th>Assigned</th>
                        <th>Active</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No exams found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $i => $e): ?>
                    <tr>
                        <td class="px-4"><?= $offset + $i + 1 ?></td>
                        <td>
                            <a href="<?= APP_URL ?>/exam-invigilation/view.php?id=<?= $e['id'] ?>" class="fw-medium text-decoration-none">
                                <?= h($e['exam_name']) ?>
                            </a>
                        </td>
                        <td><?= h($e['exam_year']) ?></td>
                        <td><?= $e['start_date'] ? date('d M Y', strtotime($e['start_date'])) : '<span class="text-muted">—</span>' ?></td>
                        <td><?= $e['end_date']   ? date('d M Y', strtotime($e['end_date']))   : '<span class="text-muted">—</span>' ?></td>
                        <td>
                            <span class="badge bg-secondary bg-opacity-15 text-secondary"><?= $e['slot_count'] ?> slots</span>
                        </td>
                        <td>
                            <?php if ($e['slot_count'] > 0): ?>
                            <?php $pct = round($e['assigned_count'] / $e['slot_count'] * 100); ?>
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress flex-grow-1" style="height:6px;min-width:60px;">
                                    <div class="progress-bar <?= $pct === 100 ? 'bg-success' : 'bg-warning' ?>"
                                         style="width:<?= $pct ?>%"></div>
                                </div>
                                <small class="text-muted"><?= $e['assigned_count'] ?>/<?= $e['slot_count'] ?></small>
                            </div>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="toggle">
                                <input type="hidden" name="id" value="<?= $e['id'] ?>">
                                <button class="btn btn-sm <?= $e['is_active'] ? 'btn-success' : 'btn-secondary' ?>"
                                        style="border-radius:6px;font-size:.75rem;padding:2px 8px;">
                                    <?= $e['is_active'] ? 'On' : 'Off' ?>
                                </button>
                            </form>
                        </td>
                        <td class="text-end pe-4">
                            <div class="d-flex gap-1 justify-content-end">
                                <a href="<?= APP_URL ?>/exam-invigilation/view.php?id=<?= $e['id'] ?>"
                                   class="btn btn-sm btn-outline-secondary" style="border-radius:7px;" title="View">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="<?= APP_URL ?>/exam-invigilation/attendance.php?id=<?= $e['id'] ?>"
                                   class="btn btn-sm btn-outline-info" style="border-radius:7px;" title="Attendance">
                                    <i class="fas fa-calendar-check"></i>
                                </a>
                                <a href="<?= APP_URL ?>/exam-invigilation/remuneration-bill.php?id=<?= $e['id'] ?>"
                                   class="btn btn-sm btn-outline-success" style="border-radius:7px;" title="Remuneration Bill">
                                    <i class="fas fa-file-invoice-dollar"></i>
                                </a>
                                <?php if (is_super_admin() || can_access('exam-invigilation', 'can_edit')): ?>
                                <a href="<?= APP_URL ?>/exam-invigilation/edit.php?id=<?= $e['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" style="border-radius:7px;" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (is_super_admin() || can_access('exam-invigilation', 'can_delete')): ?>
                                <form method="POST" style="display:inline;"
                                      onsubmit="return confirm('Delete exam &quot;<?= h(addslashes($e['exam_name'])) ?>&quot;? All slots and assignments will be removed.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="delete">
                                    <input type="hidden" name="id" value="<?= $e['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" style="border-radius:7px;" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($pages > 1): ?>
    <div class="card-footer d-flex justify-content-between align-items-center py-2 px-4">
        <small class="text-muted">Showing <?= $offset+1 ?>–<?= min($offset+$per,$total) ?> of <?= $total ?></small>
        <nav><ul class="pagination pagination-sm mb-0">
            <?php for ($p = 1; $p <= $pages; $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                <a class="page-link" href="?q=<?= urlencode($search) ?>&page=<?= $p ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
