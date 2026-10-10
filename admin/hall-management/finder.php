<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('hall-management');
require_once __DIR__ . '/helpers.php';

$page_title = 'Room Finder';
hm_ensure_schedule_columns();
hm_ensure_assignments_table();

$find_date = trim((string)($_GET['exam_date'] ?? ''));
if ($find_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $find_date)) {
    $find_date = date('Y-m-d');
}
$find_time = trim((string)($_GET['exam_time'] ?? ''));
if ($find_time !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $find_time)) {
    $find_time = '';
}
$find_time_hm = $find_time !== '' ? substr($find_time, 0, 5) : '';
$filter_dept  = (int)($_GET['dept_id'] ?? 0);
$only_free    = (string)($_GET['availability'] ?? 'free');
if (!in_array($only_free, ['free', 'all'], true)) $only_free = 'free';

$departments = hm_departments(true);

/*
 * Build the list of known physical rooms. Every hm_halls row is one booking
 * of a room (room_number + exam_date + exam_time), so the set of distinct
 * room numbers across ALL departments is the catalogue of physical rooms.
 * The most recent row per room provides its layout/capacity and the
 * department the room belongs to.
 */
$rooms = [];        // key => room record (latest definition)
$all_rows = [];
try {
    $st = db()->query(
        'SELECT h.*, d.name AS dept_name
           FROM hm_halls h
           JOIN dept_departments d ON d.id = h.dept_id
          WHERE h.is_active = 1
          ORDER BY h.id ASC'
    );
    $all_rows = $st->fetchAll();
} catch (Throwable $e) {
    flash_set('error', 'Hall Management tables are missing. Please run admin/hall-management-schema.sql.');
}

foreach ($all_rows as $r) {
    $key = mb_strtoupper(trim((string)$r['room_number']));
    if ($key === '') continue;
    // Later rows (higher id) override — latest definition wins.
    $rooms[$key] = [
        'room_number'    => (string)$r['room_number'],
        'dept_id'        => (int)$r['dept_id'],
        'dept_name'      => (string)$r['dept_name'],
        'num_columns'    => (int)$r['num_columns'],
        'num_rows'       => (int)$r['num_rows'],
        'total_capacity' => (int)$r['total_capacity'],
        'latest_hall_id' => (int)$r['id'],
        'bookings'       => [],   // bookings on the selected date
    ];
}

// Attach every booking of each room on the selected date.
$date_hall_ids = [];
foreach ($all_rows as $r) {
    if ((string)($r['exam_date'] ?? '') !== $find_date) continue;
    $key = mb_strtoupper(trim((string)$r['room_number']));
    if ($key === '' || !isset($rooms[$key])) continue;
    $rooms[$key]['bookings'][] = [
        'hall_id'   => (int)$r['id'],
        'dept_id'   => (int)$r['dept_id'],
        'dept_name' => (string)$r['dept_name'],
        'exam_time' => $r['exam_time'] !== null ? (string)$r['exam_time'] : null,
        'capacity'  => (int)$r['total_capacity'],
    ];
    $date_hall_ids[] = (int)$r['id'];
}

// Seats filled per booking on the selected date.
$filled = [];
if ($date_hall_ids) {
    try {
        $ph = implode(',', array_fill(0, count($date_hall_ids), '?'));
        $st = db()->prepare(
            "SELECT hall_id, COUNT(*) AS cnt FROM hm_hall_assignments
              WHERE exam_date = ? AND hall_id IN ($ph)
              GROUP BY hall_id"
        );
        $st->execute(array_merge([$find_date], $date_hall_ids));
        foreach ($st->fetchAll() as $f) $filled[(int)$f['hall_id']] = (int)$f['cnt'];
    } catch (Throwable $e) {
        $filled = [];
    }
}

/*
 * Classify each room for the selected date (+ optional time):
 *   free_day   — no booking at all on that date
 *   free_slot  — booked on that date but not at the selected time
 *   partial    — booked at the selected time with seats still available
 *   occupied   — booked at the selected time (or on the date when no time
 *                was chosen) with no seats left / unknown time booking
 */
$results = [];
foreach ($rooms as $room) {
    if ($filter_dept > 0 && $room['dept_id'] !== $filter_dept) continue;

    $slot_bookings  = [];  // bookings clashing with the selected time
    $other_bookings = [];  // bookings on the date at other times
    foreach ($room['bookings'] as $b) {
        $b_hm = $b['exam_time'] !== null ? substr($b['exam_time'], 0, 5) : null;
        if ($find_time_hm === '') {
            // No time chosen: every booking on the date counts as a clash.
            $slot_bookings[] = $b;
        } elseif ($b_hm === null || $b_hm === $find_time_hm) {
            // A booking without a time could run at any time — treat as clash.
            $slot_bookings[] = $b;
        } else {
            $other_bookings[] = $b;
        }
    }

    $slot_capacity  = 0;
    $slot_filled    = 0;
    foreach ($slot_bookings as $b) {
        $slot_capacity += $b['capacity'];
        $slot_filled   += $filled[$b['hall_id']] ?? 0;
    }

    if (!$room['bookings']) {
        $status = 'free_day';
    } elseif (!$slot_bookings) {
        $status = 'free_slot';
    } elseif ($slot_filled < $slot_capacity) {
        $status = 'partial';
    } else {
        $status = 'occupied';
    }

    if ($only_free === 'free' && $status === 'occupied') continue;

    $room['status']         = $status;
    $room['slot_bookings']  = $slot_bookings;
    $room['other_bookings'] = $other_bookings;
    $room['slot_available'] = max(0, $slot_capacity - $slot_filled);
    $room['own_dept']       = can_access_dept($room['dept_id']);
    $results[] = $room;
}

// Sort: fully free first, then free at this time, partial, occupied;
// own-department rooms before other departments' within each group.
$status_rank = ['free_day' => 0, 'free_slot' => 1, 'partial' => 2, 'occupied' => 3];
usort($results, function ($a, $b) use ($status_rank) {
    $r = $status_rank[$a['status']] <=> $status_rank[$b['status']];
    if ($r !== 0) return $r;
    $r = ($b['own_dept'] ? 1 : 0) <=> ($a['own_dept'] ? 1 : 0);
    if ($r !== 0) return $r;
    return strnatcasecmp($a['room_number'], $b['room_number']);
});

$can_create = is_super_admin() || can_access('hall-management', 'can_create');
$time_label = $find_time_hm !== '' ? date('g:i A', strtotime($find_time_hm)) : '';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/hall-management/index.php">Hall Management</a></li>
            <li class="breadcrumb-item active">Room Finder</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/hall-management/index.php" class="btn btn-outline-secondary" style="border-radius:10px;">
        <i class="fas fa-arrow-left me-1"></i> Back to Halls
    </a>
</div>

<?php flash_show(); ?>

<div class="card mb-4" style="border-radius:12px;">
    <div class="card-body p-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label fw-medium mb-1">Exam Date <span class="text-danger">*</span></label>
                <input type="date" name="exam_date" class="form-control form-control-sm" required value="<?= h($find_date) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label fw-medium mb-1">Exam Time <span class="text-muted fw-normal">(optional)</span></label>
                <input type="time" name="exam_time" class="form-control form-control-sm" value="<?= h($find_time_hm) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-medium mb-1">Department</label>
                <select name="dept_id" class="form-select form-select-sm">
                    <option value="">All departments</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $filter_dept === (int)$d['id'] ? 'selected' : '' ?>>
                        <?= h($d['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-medium mb-1">Show</label>
                <select name="availability" class="form-select form-select-sm">
                    <option value="free" <?= $only_free === 'free' ? 'selected' : '' ?>>Rooms with space only</option>
                    <option value="all" <?= $only_free === 'all' ? 'selected' : '' ?>>All rooms</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-sm btn-primary" style="border-radius:8px;"><i class="fas fa-search me-1"></i> Find Rooms</button>
            </div>
        </form>
        <div class="form-text mt-2">
            Searches every room known to Hall Management — including other departments' rooms — and shows which
            ones are empty or have free seats on
            <strong><?= h(date('d M Y', strtotime($find_date))) ?><?= $time_label !== '' ? ' at ' . h($time_label) : '' ?></strong>.
            <?php if ($find_time_hm === ''): ?>
            No time selected — availability is shown for the whole day.
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card" style="border-radius:12px;">
    <div class="card-body p-0">
        <?php if (empty($results)): ?>
        <div class="text-center text-muted py-5">
            <i class="fas fa-search fa-2x mb-3 d-block"></i>
            <?php if (empty($rooms)): ?>
            No rooms exist yet. Create a hall / room first to build the room catalogue.
            <?php else: ?>
            No rooms with space found for this date<?= $time_label !== '' ? ' and time' : '' ?>.
            Try another time or switch "Show" to <em>All rooms</em>.
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:50px;">#</th>
                        <th>Room Number</th>
                        <th>Department</th>
                        <th class="text-center">Capacity</th>
                        <th class="text-center">Availability at this slot</th>
                        <th>Bookings on <?= h(date('d M', strtotime($find_date))) ?></th>
                        <th class="text-end" style="width:140px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $i => $rm):
                        $is_other   = !$rm['own_dept'];
                        $fully_free = $rm['status'] === 'free_day';
                    ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td>
                            <span class="fw-semibold"><i class="fas fa-door-open me-1 text-muted"></i><?= h($rm['room_number']) ?></span>
                            <?php if ($is_other): ?>
                            <span class="badge bg-info text-dark ms-1" title="This room belongs to another department">Other dept</span>
                            <?php endif; ?>
                        </td>
                        <td><?= h($rm['dept_name']) ?></td>
                        <td class="text-center"><span class="badge bg-primary"><?= (int)$rm['total_capacity'] ?> seats</span></td>
                        <td class="text-center">
                            <?php if ($rm['status'] === 'free_day'): ?>
                            <span class="badge bg-success">Fully empty all day</span>
                            <?php elseif ($rm['status'] === 'free_slot'): ?>
                            <span class="badge bg-success">Free at this time</span>
                            <?php elseif ($rm['status'] === 'partial'): ?>
                            <span class="badge bg-warning text-dark"><?= (int)$rm['slot_available'] ?> seats available</span>
                            <?php else: ?>
                            <span class="badge bg-danger">Occupied</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:.85rem;">
                            <?php if (!$rm['bookings']): ?>
                            <span class="text-muted fst-italic">No bookings on this date</span>
                            <?php else: ?>
                                <?php foreach (array_merge($rm['slot_bookings'], $rm['other_bookings']) as $b): ?>
                                <div>
                                    <i class="far fa-clock me-1 text-muted"></i>
                                    <?= $b['exam_time'] !== null ? h(date('g:i A', strtotime($b['exam_time']))) : '<em>time not set</em>' ?>
                                    — <?= h($b['dept_name']) ?>
                                    (<?= (int)($filled[$b['hall_id']] ?? 0) ?>/<?= (int)$b['capacity'] ?> seated)
                                    <a href="<?= APP_URL ?>/hall-management/view.php?id=<?= (int)$b['hall_id'] ?>" class="text-decoration-none ms-1" title="Open booking"><i class="fas fa-eye"></i></a>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if ($is_other && $fully_free): ?>
                            <div class="alert alert-warning py-1 px-2 mt-1 mb-0" style="font-size:.8rem; border-radius:8px;">
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                This room belongs to the <strong><?= h($rm['dept_name']) ?></strong> department and looks
                                fully empty on this date. Please communicate with that department to make sure the room is
                                really free and they are not planning to use it on this date before booking it.
                            </div>
                            <?php elseif ($is_other && $rm['status'] !== 'occupied'): ?>
                            <div class="text-warning mt-1" style="font-size:.8rem;">
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                Other department's room — please confirm with <?= h($rm['dept_name']) ?> before using it.
                            </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if ($can_create && $rm['status'] !== 'occupied'): ?>
                            <a href="<?= APP_URL ?>/hall-management/create.php?room_number=<?= urlencode($rm['room_number']) ?>&amp;exam_date=<?= urlencode($find_date) ?><?= $find_time_hm !== '' ? '&amp;exam_time=' . urlencode($find_time_hm) : '' ?>&amp;from_hall_id=<?= (int)$rm['latest_hall_id'] ?>"
                               class="btn btn-sm btn-outline-success" style="border-radius:8px;" title="Book this room for the selected date &amp; time">
                                <i class="fas fa-calendar-plus me-1"></i> Book
                            </a>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
