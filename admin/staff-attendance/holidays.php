<?php
/**
 * Super Admin / module editor: staff holidays.
 * Dated holidays suppress "Absent" for that day and are highlighted in reports.
 * A holiday can cover a date RANGE (one att_holidays row per day) and may be
 * limited to specific user groups: keep "All Staff" ticked to give everyone
 * the day(s) off (the default), or pick one or more groups so only their
 * members get the holiday (att_holiday_groups).
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('staff-attendance', 'can_edit');
require_once __DIR__ . '/helpers.php';

$page_title = 'Staff Holidays';
$db         = db();

// Longest allowed holiday range (days) to guard against date-picker mistakes.
const ATT_HOLIDAY_RANGE_MAX_DAYS = 92;

// ── Handlers ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'add';

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare('DELETE FROM att_holidays WHERE id = ?')->execute([$id]);
            try {
                $db->prepare('DELETE FROM att_holiday_groups WHERE holiday_id = ?')->execute([$id]);
            } catch (Throwable $e) {
                // staff-attendance-holiday-groups-v1.sql migration not applied yet.
            }
            log_change('staff-attendance', 'DELETE', $id, 'Holiday');
            flash_set('success', 'Holiday removed.');
        }
    } else {
        $from  = trim($_POST['holiday_from'] ?? '');
        $to    = trim($_POST['holiday_to'] ?? '');
        if ($to === '') $to = $from; // no "to" date → single-day holiday
        $title = trim($_POST['title'] ?? '');
        if ($title !== '') $title = mb_substr($title, 0, 200);

        // Selected user groups. Ticking "All Staff" (value 0) or selecting
        // nothing means the holiday applies to everyone.
        $group_ids = [];
        foreach ((array)($_POST['group_ids'] ?? []) as $gid) {
            $gid = (int)$gid;
            if ($gid < 1) { $group_ids = []; break; } // "All Staff" wins
            $group_ids[] = $gid;
        }
        $group_ids = array_values(array_unique($group_ids));

        if ($title === '') {
            flash_set('error', 'Please enter a holiday title.');
        } elseif ($from === '' || strtotime($from) === false || strtotime($to) === false) {
            flash_set('error', 'Please choose a valid date (or date range).');
        } else {
            $from = att_normalize_date($from);
            $to   = att_normalize_date($to);
            if ($to < $from) { [$from, $to] = [$to, $from]; }
            $days = (int)((strtotime($to) - strtotime($from)) / 86400) + 1;

            if ($days > ATT_HOLIDAY_RANGE_MAX_DAYS) {
                flash_set('error', 'The date range is too long — please keep it within ' . ATT_HOLIDAY_RANGE_MAX_DAYS . ' days.');
            } else {
                $upsert = $db->prepare(
                    'INSERT INTO att_holidays (holiday_date, title) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE title = VALUES(title)'
                );
                $find_id = $db->prepare('SELECT id FROM att_holidays WHERE holiday_date = ?');

                $saved        = 0;
                $groups_saved = true;
                $last_id      = 0;
                for ($ts = strtotime($from); $ts <= strtotime($to); $ts = strtotime('+1 day', $ts)) {
                    $date = date('Y-m-d', $ts);
                    $upsert->execute([$date, $title]);

                    // Resolve the holiday id (lastInsertId is 0 when an existing
                    // date was updated through ON DUPLICATE KEY UPDATE).
                    $hid = (int)$db->lastInsertId();
                    if ($hid < 1) {
                        $find_id->execute([$date]);
                        $hid = (int)$find_id->fetchColumn();
                    }
                    if ($hid < 1) continue;
                    $last_id = $hid;
                    $saved++;

                    // Replace the group restriction (no rows = applies to all staff).
                    try {
                        $db->prepare('DELETE FROM att_holiday_groups WHERE holiday_id = ?')->execute([$hid]);
                        if (!empty($group_ids)) {
                            $ins = $db->prepare('INSERT INTO att_holiday_groups (holiday_id, group_id) VALUES (?, ?)');
                            foreach ($group_ids as $gid) $ins->execute([$hid, $gid]);
                        }
                    } catch (Throwable $e) {
                        // Table missing – only a problem when a restriction was requested.
                        $groups_saved = empty($group_ids);
                    }
                }

                log_change('staff-attendance', 'CREATE', $last_id, $title, null, null,
                    $from . ($to !== $from ? ' – ' . $to : ''));
                if ($groups_saved) {
                    flash_set('success', 'Holiday saved for ' . $saved . ' day' . ($saved === 1 ? '' : 's')
                        . (!empty($group_ids) ? ' (selected user groups only)' : '') . '.');
                } else {
                    flash_set('error', 'Holiday saved, but the user-group selection could not be stored. Please run the staff-attendance-holiday-groups-v1.sql migration first.');
                }
            }
        }
    }
    redirect(APP_URL . '/staff-attendance/holidays.php');
}

// ── Listing (upcoming first, then past) ──────────────────────────────────────
$year = (int)($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) $year = (int)date('Y');
$stmt = $db->prepare('SELECT * FROM att_holidays WHERE YEAR(holiday_date) = ? ORDER BY holiday_date ASC');
$stmt->execute([$year]);
$holidays = $stmt->fetchAll();

// Group restriction per holiday (holiday_id => [group names]).
$holiday_groups = [];
try {
    $rows = $db->query(
        'SELECT hg.holiday_id, g.name FROM att_holiday_groups hg
           JOIN user_groups g ON g.id = hg.group_id ORDER BY g.name ASC'
    )->fetchAll();
    foreach ($rows as $r) $holiday_groups[(int)$r['holiday_id']][] = (string)$r['name'];
} catch (Throwable $e) {
    // staff-attendance-holiday-groups-v1.sql migration not applied yet.
}

// Active user groups for the "Applies To" picker.
$user_groups = [];
try {
    $user_groups = $db->query('SELECT id, name FROM user_groups WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
} catch (Throwable $e) {
    // ignore – the form falls back to "All Staff" only.
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/staff-attendance/index.php">Staff Attendance</a></li>
            <li class="breadcrumb-item active">Holidays</li>
        </ol>
    </nav>
</div>

<?= flash_show() ?>

<div class="row">
    <div class="col-lg-5">
        <div class="card mb-4" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-calendar-plus me-2 text-primary"></i>Add / Update Holiday</h6>
            </div>
            <div class="card-body">
                <form method="POST" class="row g-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">
                    <div class="col-6">
                        <label class="form-label fw-semibold small mb-1">Date From</label>
                        <input type="date" name="holiday_from" class="form-control" value="<?= h(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold small mb-1">Date To <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="date" name="holiday_to" class="form-control" value="">
                        <div class="form-text">Leave empty for a single-day holiday.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold small mb-1">Title</label>
                        <input type="text" name="title" class="form-control" maxlength="200" placeholder="e.g. Victory Day" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold small mb-1">Applies To</label>
                        <input type="text" id="groupSearch" class="form-control form-control-sm mb-2"
                               placeholder="Search user groups…" autocomplete="off">
                        <div class="border rounded p-2" style="max-height:220px;overflow-y:auto;" id="groupList">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="group_ids[]" value="0" id="grpAll" checked>
                                <label class="form-check-label fw-semibold" for="grpAll">All Staff (default)</label>
                            </div>
                            <hr class="my-2">
                            <?php foreach ($user_groups as $g): ?>
                            <div class="form-check group-item" data-name="<?= h(mb_strtolower($g['name'])) ?>">
                                <input class="form-check-input group-choice" type="checkbox" name="group_ids[]"
                                       value="<?= (int)$g['id'] ?>" id="grp<?= (int)$g['id'] ?>">
                                <label class="form-check-label" for="grp<?= (int)$g['id'] ?>"><?= h($g['name']) ?></label>
                            </div>
                            <?php endforeach; ?>
                            <div class="text-muted small px-1 d-none" id="groupNoMatch">No user group matches your search.</div>
                        </div>
                        <div class="form-text">Keep <strong>All Staff</strong> ticked to give everyone the day off, or untick it and pick one or more user groups — use the search box to find a group quickly.</div>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Holiday</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-calendar-day me-2 text-muted"></i>Holidays <?= $year ?>
                    <span class="badge bg-secondary ms-1"><?= count($holidays) ?></span>
                </h6>
                <form method="get" class="d-flex gap-2">
                    <input type="number" name="year" class="form-control form-control-sm" style="width:110px;"
                           value="<?= $year ?>" min="2000" max="2100" onchange="this.form.submit()">
                </form>
            </div>
            <div class="card-body p-0">
                <?php if (empty($holidays)): ?>
                    <p class="text-muted p-4 mb-0">No holidays defined for <?= $year ?>.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr><th class="px-3">Date</th><th>Day</th><th>Title</th><th>Applies To</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($holidays as $hd): ?>
                            <tr>
                                <td class="px-3"><?= h(date('d M Y', strtotime($hd['holiday_date']))) ?></td>
                                <td class="small text-muted"><?= h(date('l', strtotime($hd['holiday_date']))) ?></td>
                                <td><?= h($hd['title']) ?></td>
                                <td class="small">
                                    <?php $gnames = $holiday_groups[(int)$hd['id']] ?? []; ?>
                                    <?php if (empty($gnames)): ?>
                                        <span class="badge bg-light text-dark border">All Staff</span>
                                    <?php else: foreach ($gnames as $gn): ?>
                                        <span class="badge bg-info text-dark"><?= h($gn) ?></span>
                                    <?php endforeach; endif; ?>
                                </td>
                                <td class="text-end pe-3">
                                    <form method="POST" onsubmit="return confirm('Remove this holiday?');" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$hd['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" style="border-radius:8px;"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <p class="text-muted small mt-2 px-1">A date range creates one holiday entry per day. Re-saving an existing date updates its title and its "Applies To" groups. A group-restricted holiday only suppresses "Absent" for members of the selected user groups; everyone else is expected at the office as usual.</p>
    </div>
</div>

<script>
(function () {
    var search  = document.getElementById('groupSearch');
    var items   = Array.prototype.slice.call(document.querySelectorAll('#groupList .group-item'));
    var all     = document.getElementById('grpAll');
    var picks   = Array.prototype.slice.call(document.querySelectorAll('#groupList .group-choice'));
    var noMatch = document.getElementById('groupNoMatch');

    // Live search filter for the user-group list.
    search.addEventListener('input', function () {
        var q = this.value.trim().toLowerCase();
        var visible = 0;
        items.forEach(function (it) {
            var show = q === '' || it.getAttribute('data-name').indexOf(q) !== -1;
            it.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        noMatch.classList.toggle('d-none', visible > 0);
    });

    // Ticking a specific group unticks "All Staff" and vice versa; when no
    // group is left ticked, "All Staff" comes back on automatically.
    function anyPicked() {
        return picks.some(function (c) { return c.checked; });
    }
    picks.forEach(function (cb) {
        cb.addEventListener('change', function () {
            if (this.checked) all.checked = false;
            if (!anyPicked()) all.checked = true;
        });
    });
    all.addEventListener('change', function () {
        if (this.checked) {
            picks.forEach(function (c) { c.checked = false; });
        } else if (!anyPicked()) {
            this.checked = true;
        }
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
