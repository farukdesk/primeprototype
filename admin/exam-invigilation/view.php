<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/slot-helpers.php';
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
function ei_time_order_expr(string $column = 'time_slot'): string
{
    $allowed = ['time_slot', 's.time_slot'];
    if (!in_array($column, $allowed, true)) {
        $column = 'time_slot';
    }

    return "COALESCE(
        STR_TO_DATE(TRIM(SUBSTRING_INDEX(REPLACE({$column}, '-', '–'), '–', 1)), '%h:%i %p'),
        STR_TO_DATE(TRIM(SUBSTRING_INDEX(REPLACE({$column}, '-', '–'), '–', 1)), '%H:%i')
    )";
}

$time_order_sql = ei_time_order_expr('time_slot');

$f_date       = trim((string)($_GET['slot_date'] ?? ''));
$f_dept       = (int)($_GET['dept'] ?? 0);
$f_room       = trim((string)($_GET['room'] ?? ''));
$f_time_slot  = trim((string)($_GET['time_slot'] ?? ''));
$f_invigilator = (int)($_GET['invigilator'] ?? 0);
$print_mode   = isset($_GET['print']) && $_GET['print'] === '1';

if ($f_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date)) {
    $f_date = '';
}

$filter_query = [];
if ($f_date !== '') $filter_query['slot_date'] = $f_date;
if ($f_dept > 0) $filter_query['dept'] = $f_dept;
if ($f_room !== '') $filter_query['room'] = $f_room;
if ($f_time_slot !== '') $filter_query['time_slot'] = $f_time_slot;
if ($f_invigilator > 0) $filter_query['invigilator'] = $f_invigilator;

$view_url  = APP_URL . '/exam-invigilation/view.php?' . http_build_query(array_merge(['id' => $id], $filter_query));
$print_url = APP_URL . '/exam-invigilation/view.php?' . http_build_query(array_merge(['id' => $id], $filter_query, ['print' => 1]));
$auto_assign_max_slots         = ei_get_auto_assign_max_slots();
// Hard cap: no faculty may ever receive 3 or more shifts on the same day
$auto_assign_max_slots_per_day = min(ei_get_auto_assign_max_slots_per_day(), 2);

// ── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    csrf_check();

    // ── Delete slot ──
    if ($_POST['_action'] === 'delete_slot') {
        require_access('exam-invigilation', 'can_delete');
        $sid = (int)($_POST['slot_id'] ?? 0);
        db()->prepare('DELETE FROM ei_slots WHERE id = ? AND exam_id = ?')->execute([$sid, $id]);
        flash_set('success', 'Slot deleted.');
        redirect($view_url);
    }

    // ── Clear assignment for one slot ──
    if ($_POST['_action'] === 'clear_slot') {
        require_access('exam-invigilation', 'can_edit');
        $sid = (int)($_POST['slot_id'] ?? 0);
        $slot_row = db()->prepare('SELECT room_number, slot_date FROM ei_slots WHERE id = ? AND exam_id = ?');
        $slot_row->execute([$sid, $id]);
        $slot_info = $slot_row->fetch();
        db()->prepare('UPDATE ei_slots SET faculty1_id=NULL, faculty2_id=NULL WHERE id = ? AND exam_id = ?')
           ->execute([$sid, $id]);
        if ($slot_info) {
            $room_label = $slot_info['room_number'] . ' (' . date('d M Y', strtotime($slot_info['slot_date'])) . ')';
            ei_save_assignment_snapshot($id, 'clear_slot', "Cleared assignment – Room {$room_label}");
        }
        flash_set('success', 'Assignment cleared.');
        redirect($view_url);
    }

    // ── Revert to a saved version ──
    if ($_POST['_action'] === 'revert_version') {
        require_access('exam-invigilation', 'can_edit');
        $snap_id = (int)($_POST['snapshot_id'] ?? 0);
        if ($snap_id <= 0) {
            flash_set('error', 'Invalid version selected.');
            redirect($view_url);
        }

        // Verify the snapshot belongs to this exam
        $snap_st = db()->prepare(
            'SELECT s.*, COUNT(ss.id) AS slot_cnt
             FROM ei_assignment_snapshots s
             LEFT JOIN ei_assignment_snapshot_slots ss ON ss.snapshot_id = s.id
             WHERE s.id = ? AND s.exam_id = ?
             GROUP BY s.id'
        );
        $snap_st->execute([$snap_id, $id]);
        $snap = $snap_st->fetch();
        if (!$snap) {
            flash_set('error', 'Version not found.');
            redirect($view_url);
        }

        // Load the snapshot's slot data
        $snap_slots_st = db()->prepare(
            'SELECT slot_id, faculty1_id, faculty2_id FROM ei_assignment_snapshot_slots WHERE snapshot_id = ?'
        );
        $snap_slots_st->execute([$snap_id]);
        $snap_slots = $snap_slots_st->fetchAll();

        if (empty($snap_slots)) {
            flash_set('warning', 'Version has no slot data to restore.');
            redirect(APP_URL . '/exam-invigilation/versions.php?id=' . $id);
        }

        // Apply each slot's saved state
        $upd = db()->prepare(
            'UPDATE ei_slots SET faculty1_id=?, faculty2_id=? WHERE id=? AND exam_id=?'
        );
        foreach ($snap_slots as $ss) {
            $upd->execute([$ss['faculty1_id'], $ss['faculty2_id'], (int)$ss['slot_id'], $id]);
        }

        // Save a new snapshot recording this revert
        ei_save_assignment_snapshot($id, 'revert', "Reverted to V{$snap['version_number']} (saved " . date('d M Y h:i A', strtotime($snap['created_at'])) . ")");

        flash_set('success', "Successfully reverted to Version {$snap['version_number']}.");
        redirect($view_url);
    }

    // ── Auto-assign faculty ───────────────────────────────────────────────────
    if ($_POST['_action'] === 'auto_assign') {
        require_access('exam-invigilation', 'can_edit');

        $scope = isset($_POST['reassign']) ? 'all' : 'unassigned';

        // Fetch all slots for this exam (optionally only unassigned)
        if ($scope === 'all') {
            $slots_st = db()->prepare(
                "SELECT * FROM ei_slots
                 WHERE exam_id = ?
                 ORDER BY slot_date ASC, {$time_order_sql} ASC, time_slot ASC, room_number ASC"
            );
        } else {
            $slots_st = db()->prepare(
                "SELECT * FROM ei_slots WHERE exam_id = ?
                 AND (faculty1_id IS NULL OR faculty2_id IS NULL)
                 ORDER BY slot_date ASC, {$time_order_sql} ASC, time_slot ASC, room_number ASC"
            );
        }
        $slots_st->execute([$id]);
        $slots = $slots_st->fetchAll();

        if (empty($slots)) {
            flash_set('info', 'No slots to assign.');
            redirect($view_url);
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
            redirect($view_url);
        }

        // Designation-based weight: juniors get proportionally more slots than seniors
        $faculty_weight_map = [];
        foreach ($all_faculty as $f) {
            $faculty_weight_map[(int)$f['id']] = ei_designation_weight($f['designation'] ?? null);
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

        // Track how many times each faculty has been assigned in this run (for workload balancing)
        $workload = [];
        foreach ($all_faculty as $f) {
            $workload[(int)$f['id']] = 0;
        }

        // Track per-day assignments: [faculty_id][date] => count
        $daily_workload = [];

        $assignment_params = [];
        $assignment_scope_sql = '';
        if ($scope === 'all') {
            $assignment_scope_sql = ' AND s.exam_id != ?';
            $assignment_params = [$id, $id];
        }
        $assignment_count_st = db()->prepare(
            "SELECT faculty_id, COUNT(*) AS slot_count
             FROM (
                 SELECT s.faculty1_id AS faculty_id
                 FROM ei_slots s
                 JOIN ei_exams e ON e.id = s.exam_id
                 WHERE e.is_active = 1
                   AND s.faculty1_id IS NOT NULL{$assignment_scope_sql}
                 UNION ALL
                 SELECT s.faculty2_id AS faculty_id
                 FROM ei_slots s
                 JOIN ei_exams e ON e.id = s.exam_id
                 WHERE e.is_active = 1
                   AND s.faculty2_id IS NOT NULL{$assignment_scope_sql}
             ) assigned
             GROUP BY faculty_id"
        );
        $assignment_count_st->execute($assignment_params);
        foreach ($assignment_count_st->fetchAll() as $row) {
            $workload[(int)$row['faculty_id']] = (int)$row['slot_count'];
        }

        // Build daily workload map from existing assignments
        $daily_count_scope_sql = $assignment_scope_sql;
        $daily_count_st = db()->prepare(
            "SELECT faculty_id, slot_date, COUNT(*) AS c
             FROM (
                 SELECT s.faculty1_id AS faculty_id, s.slot_date
                 FROM ei_slots s
                 JOIN ei_exams e ON e.id = s.exam_id
                 WHERE e.is_active = 1
                   AND s.faculty1_id IS NOT NULL{$daily_count_scope_sql}
                 UNION ALL
                 SELECT s.faculty2_id, s.slot_date
                 FROM ei_slots s
                 JOIN ei_exams e ON e.id = s.exam_id
                 WHERE e.is_active = 1
                   AND s.faculty2_id IS NOT NULL{$daily_count_scope_sql}
             ) t
             GROUP BY faculty_id, slot_date"
        );
        $daily_count_st->execute($assignment_params);
        foreach ($daily_count_st->fetchAll() as $row) {
            $daily_workload[(int)$row['faculty_id']][(string)$row['slot_date']] = (int)$row['c'];
        }

        // Track per-day working time span: [faculty_id][date] => ['start' => min, 'end' => max] (minutes)
        $daily_span = [];
        $span_st = db()->prepare(
            "SELECT faculty_id, slot_date, time_slot
             FROM (
                 SELECT s.faculty1_id AS faculty_id, s.slot_date, s.time_slot
                 FROM ei_slots s
                 JOIN ei_exams e ON e.id = s.exam_id
                 WHERE e.is_active = 1
                   AND s.faculty1_id IS NOT NULL{$daily_count_scope_sql}
                 UNION ALL
                 SELECT s.faculty2_id, s.slot_date, s.time_slot
                 FROM ei_slots s
                 JOIN ei_exams e ON e.id = s.exam_id
                 WHERE e.is_active = 1
                   AND s.faculty2_id IS NOT NULL{$daily_count_scope_sql}
             ) t"
        );
        $span_st->execute($assignment_params);
        foreach ($span_st->fetchAll() as $row) {
            $mins = ei_time_slot_minutes((string)$row['time_slot']);
            if ($mins === null) continue;
            $fid = (int)$row['faculty_id'];
            $d   = (string)$row['slot_date'];
            if (!isset($daily_span[$fid][$d])) {
                $daily_span[$fid][$d] = ['start' => $mins[0], 'end' => $mins[1]];
            } else {
                $daily_span[$fid][$d]['start'] = min($daily_span[$fid][$d]['start'], $mins[0]);
                $daily_span[$fid][$d]['end']   = max($daily_span[$fid][$d]['end'], $mins[1]);
            }
        }

        // Helper: sort a pool by seniority-weighted workload (juniors get more slots),
        // then junior-first on ties, then shuffle
        $sort_by_workload = static function (array $pool) use (&$workload, $faculty_weight_map): array {
            usort($pool, static function ($a, $b) use (&$workload, $faculty_weight_map) {
                $ida = (int)$a['id'];
                $idb = (int)$b['id'];
                $ea  = ($workload[$ida] ?? 0) / max(1, $faculty_weight_map[$ida] ?? 1);
                $eb  = ($workload[$idb] ?? 0) / max(1, $faculty_weight_map[$idb] ?? 1);
                if ($ea !== $eb) return $ea <=> $eb;
                // Junior first on ties (higher rank number = more junior)
                $ra = ei_designation_rank($a['designation'] ?? null);
                $rb = ei_designation_rank($b['designation'] ?? null);
                if ($ra !== $rb) return $rb <=> $ra;
                return random_int(0, 1) ? -1 : 1; // shuffle within same tier
            });
            return $pool;
        };

        // Eligibility check: busy, total cap, daily cap, daily working-window rule
        $is_eligible = static function (array $f, string $slot_date, string $key, ?array $slot_minutes) use (
            &$busy_map, &$workload, &$daily_workload, &$daily_span,
            $auto_assign_max_slots, $auto_assign_max_slots_per_day
        ): bool {
            $fid = (int)$f['id'];
            if (isset($busy_map[$key][$fid])) return false;
            if (($workload[$fid] ?? 0) >= $auto_assign_max_slots) return false;
            if (($daily_workload[$fid][$slot_date] ?? 0) >= $auto_assign_max_slots_per_day) return false;
            // Daily working-window rule: combined with existing shifts that day,
            // a start before 2 PM must finish by 6 PM; a start at/after 2 PM by 10 PM
            if ($slot_minutes !== null && isset($daily_span[$fid][$slot_date])) {
                $span      = $daily_span[$fid][$slot_date];
                $new_start = min($span['start'], $slot_minutes[0]);
                $new_end   = max($span['end'], $slot_minutes[1]);
                if ($new_end > ei_day_window_end_minutes($new_start)) return false;
            }
            return true;
        };

        // Mark a faculty as assigned for the rest of this run
        $mark_assigned = static function (array $f, string $slot_date, string $key, ?array $slot_minutes) use (
            &$busy_map, &$workload, &$daily_workload, &$daily_span
        ): void {
            $fid = (int)$f['id'];
            $busy_map[$key][$fid] = true;
            $workload[$fid] = ($workload[$fid] ?? 0) + 1;
            $daily_workload[$fid][$slot_date] = ($daily_workload[$fid][$slot_date] ?? 0) + 1;
            if ($slot_minutes !== null) {
                if (!isset($daily_span[$fid][$slot_date])) {
                    $daily_span[$fid][$slot_date] = ['start' => $slot_minutes[0], 'end' => $slot_minutes[1]];
                } else {
                    $daily_span[$fid][$slot_date]['start'] = min($daily_span[$fid][$slot_date]['start'], $slot_minutes[0]);
                    $daily_span[$fid][$slot_date]['end']   = max($daily_span[$fid][$slot_date]['end'], $slot_minutes[1]);
                }
            }
        };

        // Group slots by date + time_slot so same-department invigilators can be
        // reserved for their own department's rooms before other rooms take them
        $slot_groups = [];
        foreach ($slots as $slot) {
            $slot_groups[$slot['slot_date'] . '|' . $slot['time_slot']][] = $slot;
        }

        $no_same_dept_count = 0;

        foreach ($slot_groups as $group) {
            $picks = [];

            // Phase 1: every room with a department gets one same-department invigilator FIRST
            foreach ($group as $i => $slot) {
                $picks[$i] = null;
                $slot_pref_dept = isset($slot['dept_id']) ? (int)$slot['dept_id'] : 0;
                if ($slot_pref_dept <= 0) continue;

                $slot_date    = $slot['slot_date'];
                $key          = $slot_date . '|' . $slot['time_slot'];
                $slot_minutes = ei_time_slot_minutes($slot['time_slot']);

                $same_dept_pool = [];
                foreach ($all_faculty as $f) {
                    if ((int)$f['dept_id'] !== $slot_pref_dept) continue;
                    if (!$is_eligible($f, $slot_date, $key, $slot_minutes)) continue;
                    $same_dept_pool[] = $f;
                }
                if (!empty($same_dept_pool)) {
                    $same_dept_pool = $sort_by_workload($same_dept_pool);
                    $picks[$i] = $same_dept_pool[0];
                    $mark_assigned($same_dept_pool[0], $slot_date, $key, $slot_minutes);
                }
            }

            // Phase 2: fill the remaining seats from any department
            foreach ($group as $i => $slot) {
                $slot_date      = $slot['slot_date'];
                $key            = $slot_date . '|' . $slot['time_slot'];
                $slot_minutes   = ei_time_slot_minutes($slot['time_slot']);
                $slot_pref_dept = isset($slot['dept_id']) ? (int)$slot['dept_id'] : 0;

                $eligible = [];
                foreach ($all_faculty as $f) {
                    if ($is_eligible($f, $slot_date, $key, $slot_minutes)) {
                        $eligible[] = $f;
                    }
                }
                $eligible = $sort_by_workload($eligible);

                // Room department first: candidates from the room's own department
                // take priority over other departments (workload order preserved
                // within each group)
                if ($slot_pref_dept > 0) {
                    $same_dept_first = [];
                    $other_depts     = [];
                    foreach ($eligible as $candidate) {
                        if ((int)$candidate['dept_id'] === $slot_pref_dept) {
                            $same_dept_first[] = $candidate;
                        } else {
                            $other_depts[] = $candidate;
                        }
                    }
                    $eligible = array_merge($same_dept_first, $other_depts);
                }

                $f1 = $picks[$i];
                $f2 = null;
                $f1_reserved = ($f1 !== null);

                if ($f1 === null) {
                    // No same-department faculty was available for this room
                    if ($slot_pref_dept > 0) {
                        $no_same_dept_count++;
                    }
                    // Pick two least-loaded faculty from different depts if possible
                    foreach ($eligible as $candidate) {
                        if ($f1 === null) {
                            $f1 = $candidate;
                        } elseif ((int)$candidate['dept_id'] !== (int)$f1['dept_id']) {
                            $f2 = $candidate;
                            break;
                        }
                    }
                } else {
                    // Same-dept invigilator reserved as f1: prefer f2 from another dept
                    foreach ($eligible as $candidate) {
                        if ((int)$candidate['dept_id'] !== (int)$f1['dept_id']) {
                            $f2 = $candidate;
                            break;
                        }
                    }
                }
                // Fallback: take same dept for f2 if no other dept is available
                if ($f1 !== null && $f2 === null) {
                    foreach ($eligible as $candidate) {
                        if ((int)$candidate['id'] !== (int)$f1['id']) {
                            $f2 = $candidate;
                            break;
                        }
                    }
                }

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

                // Mark as busy for subsequent slots (the phase-1 pick is already marked)
                if ($f1 !== null && !$f1_reserved) {
                    $mark_assigned($f1, $slot_date, $key, $slot_minutes);
                }
                if ($f2 !== null) {
                    $mark_assigned($f2, $slot_date, $key, $slot_minutes);
                }

                if ($f1 && $f2) {
                    $assigned_count++;
                } else {
                    $partial_count++;
                }
            }
        }

        $msg = "Auto-assign complete: {$assigned_count} slot(s) fully assigned";
        if ($partial_count > 0) $msg .= ", {$partial_count} partially assigned";
        if ($no_same_dept_count > 0) $msg .= ", {$no_same_dept_count} room(s) got no same-department invigilator (no eligible faculty left in that department)";
        if ($failed_count  > 0) $msg .= ", {$failed_count} could not be assigned (insufficient eligible faculty within the current availability rules, {$auto_assign_max_slots}-slot total cap, and {$auto_assign_max_slots_per_day}-slot daily cap)";
        $msg .= '.';
        flash_set(($failed_count > 0 || $no_same_dept_count > 0) ? 'warning' : 'success', $msg);

        // Save a version snapshot after the auto-assign operation
        $scope_label = ($scope === 'all') ? 'Re-assign all' : 'Assign unassigned';
        ei_save_assignment_snapshot($id, 'auto_assign', "{$scope_label} – {$assigned_count} fully, {$partial_count} partially, {$failed_count} failed");

        redirect($view_url);
    }

    redirect($view_url);
}

// ── Filter option data ────────────────────────────────────────────────────────
$departments = db()->query('SELECT id, name FROM dept_departments WHERE is_active=1 ORDER BY name ASC')->fetchAll();
$time_slots_st = db()->prepare('SELECT DISTINCT time_slot FROM ei_slots WHERE exam_id = ? ORDER BY ' . $time_order_sql . ' ASC, time_slot ASC');
$time_slots_st->execute([$id]);
$time_slots = array_values(array_filter(array_map(static fn ($r) => (string)$r['time_slot'], $time_slots_st->fetchAll()), static fn ($v) => trim($v) !== ''));
$invigilators = db()->query('SELECT id, name, designation FROM ei_faculty WHERE is_active = 1 ORDER BY name ASC')->fetchAll();

// Label for the currently selected invigilator (searchable filter box)
$f_invigilator_label = '';
if ($f_invigilator > 0) {
    foreach ($invigilators as $inv) {
        if ((int)$inv['id'] === $f_invigilator) {
            $f_invigilator_label = $inv['name'] . ($inv['designation'] ? ' (' . $inv['designation'] . ')' : '');
            break;
        }
    }
}

// ── Load slots ────────────────────────────────────────────────────────────────
$where = ['s.exam_id = ?'];
$params = [$id];
if ($f_date !== '') {
    $where[] = 's.slot_date = ?';
    $params[] = $f_date;
}
if ($f_dept > 0) {
    // Room department only: show slots whose room belongs to the selected department
    $where[] = 's.dept_id = ?';
    $params[] = $f_dept;
}
if ($f_room !== '') {
    $where[] = 's.room_number LIKE ?';
    $params[] = '%' . $f_room . '%';
}
if ($f_time_slot !== '') {
    $where[] = 's.time_slot = ?';
    $params[] = $f_time_slot;
}
if ($f_invigilator > 0) {
    $where[] = '(s.faculty1_id = ? OR s.faculty2_id = ?)';
    $params[] = $f_invigilator;
    $params[] = $f_invigilator;
}
$sql_where = 'WHERE ' . implode(' AND ', $where);

$slots_st = db()->prepare(
    "SELECT s.*,
            f1.name AS f1_name, f1.designation AS f1_desig, d1.name AS f1_dept,
            f2.name AS f2_name, f2.designation AS f2_desig, d2.name AS f2_dept,
            dp.name AS pref_dept_name
     FROM ei_slots s
     LEFT JOIN ei_faculty f1 ON f1.id = s.faculty1_id
     LEFT JOIN dept_departments d1 ON d1.id = f1.dept_id
     LEFT JOIN ei_faculty f2 ON f2.id = s.faculty2_id
     LEFT JOIN dept_departments d2 ON d2.id = f2.dept_id
     LEFT JOIN dept_departments dp ON dp.id = s.dept_id
     $sql_where
     ORDER BY s.slot_date ASC,
              " . ei_time_order_expr('s.time_slot') . " ASC,
              s.time_slot ASC, s.room_number ASC"
);
$slots_st->execute($params);
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
        <?php if (!$print_mode): ?>
        <a href="<?= h($print_url) ?>" target="_blank" class="btn btn-outline-dark btn-sm" style="border-radius:10px;">
            <i class="fas fa-file-pdf me-1"></i> A4 PDF Print
        </a>
        <?php else: ?>
        <a href="<?= h($view_url) ?>" class="btn btn-outline-secondary btn-sm" style="border-radius:10px;">
            <i class="fas fa-arrow-left me-1"></i> Back to Interactive View
        </a>
        <?php endif; ?>
        <?php if (!$print_mode): ?>
        <?php if (is_super_admin() || can_access('exam-invigilation', 'can_create')): ?>
        <a href="<?= APP_URL ?>/exam-invigilation/slot-create.php?exam_id=<?= $id ?>#csv-upload"
           class="btn btn-outline-primary btn-sm" style="border-radius:10px;">
            <i class="fas fa-file-csv me-1"></i> Bulk Upload CSV
        </a>
        <a href="<?= APP_URL ?>/exam-invigilation/slot-create.php?exam_id=<?= $id ?>"
           class="btn btn-success btn-sm" style="border-radius:10px;">
            <i class="fas fa-plus me-1"></i> Add Slot
        </a>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php flash_show(); ?>

<?php if (!$print_mode): ?>
<?php require __DIR__ . '/ei-nav.php'; ?>
<?php require __DIR__ . '/exam-tabs.php'; ?>
<?php endif; ?>

<?php if ($print_mode): ?>
<style>
@media print {
    @page { size: A4; margin: 10mm; }
    .navbar, .breadcrumb, .btn, form, .card-footer, .no-print, .main-sidebar, .main-header, footer { display: none !important; }
    .content-wrapper, .content { margin: 0 !important; padding: 0 !important; }
    .card { border: 0 !important; box-shadow: none !important; }
}
</style>
<?php endif; ?>

<div class="card mb-3 no-print">
    <div class="card-body py-2 px-3">
        <form method="GET" class="row g-2 align-items-center">
            <input type="hidden" name="id" value="<?= $id ?>">
            <div class="col-12 col-md-2">
                <input type="date" name="slot_date" class="form-control form-control-sm" value="<?= h($f_date) ?>">
            </div>
            <div class="col-12 col-md-2">
                <select name="dept" class="form-select form-select-sm">
                    <option value="0">All Departments</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $f_dept === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <input type="text" name="room" class="form-control form-control-sm" placeholder="Room" value="<?= h($f_room) ?>">
            </div>
            <div class="col-12 col-md-2">
                <select name="time_slot" class="form-select form-select-sm">
                    <option value="">All Time Slots</option>
                    <?php foreach ($time_slots as $ts): ?>
                    <option value="<?= h($ts) ?>" <?= $f_time_slot === $ts ? 'selected' : '' ?>><?= h($ts) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2 position-relative">
                <input type="hidden" name="invigilator" id="invFilterValue" value="<?= $f_invigilator ?>">
                <input type="text" id="invFilterSearch" class="form-control form-control-sm"
                       placeholder="All Invigilators — type to search" autocomplete="off"
                       value="<?= h($f_invigilator_label) ?>">
                <div id="invFilterList" class="dropdown-menu w-100 shadow-sm" style="max-height:240px;overflow-y:auto;">
                    <button type="button" class="dropdown-item inv-filter-item" data-id="0" data-name="">All Invigilators</button>
                    <?php foreach ($invigilators as $inv): ?>
                    <?php $inv_label = $inv['name'] . ($inv['designation'] ? ' (' . $inv['designation'] . ')' : ''); ?>
                    <button type="button" class="dropdown-item inv-filter-item<?= $f_invigilator === (int)$inv['id'] ? ' active' : '' ?>"
                            data-id="<?= (int)$inv['id'] ?>" data-name="<?= h($inv_label) ?>"><?= h($inv_label) ?></button>
                    <?php endforeach; ?>
                    <div id="invFilterEmpty" class="dropdown-item text-muted d-none">No match found</div>
                </div>
                <script>
                (function () {
                    var search = document.getElementById('invFilterSearch');
                    var hidden = document.getElementById('invFilterValue');
                    var list   = document.getElementById('invFilterList');
                    if (!search || !hidden || !list) return;
                    var items = Array.prototype.slice.call(list.querySelectorAll('.inv-filter-item'));
                    var empty = document.getElementById('invFilterEmpty');

                    function open()  { list.classList.add('show'); }
                    function close() { list.classList.remove('show'); }

                    function filter() {
                        var q = search.value.trim().toLowerCase();
                        var visible = 0;
                        items.forEach(function (btn) {
                            var match = q === '' || btn.textContent.toLowerCase().indexOf(q) !== -1;
                            btn.classList.toggle('d-none', !match);
                            if (match) visible++;
                        });
                        if (empty) empty.classList.toggle('d-none', visible > 0);
                    }

                    search.addEventListener('focus', function () { filter(); open(); });
                    search.addEventListener('input', function () {
                        hidden.value = '0'; // typing invalidates previous selection
                        filter();
                        open();
                    });
                    items.forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            hidden.value = btn.getAttribute('data-id');
                            search.value = btn.getAttribute('data-name');
                            close();
                        });
                    });
                    document.addEventListener('click', function (e) {
                        if (!list.contains(e.target) && e.target !== search) close();
                    });
                })();
                </script>
            </div>
            <div class="col-auto d-flex gap-2">
                <button class="btn btn-sm btn-primary">Filter</button>
                <a href="<?= APP_URL ?>/exam-invigilation/view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

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
<?php if (!$print_mode && (is_super_admin() || can_access('exam-invigilation', 'can_edit'))): ?>
<div class="card mb-4" style="border-left:4px solid #4f8ef7;">
    <div class="card-body py-3 px-4">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
                <h6 class="mb-1 fw-semibold"><i class="fas fa-magic me-2 text-primary"></i>Auto-Assign Invigilators</h6>
                <p class="mb-0 text-muted" style="font-size:.85rem;">
                    Automatically assigns 2 faculty per room slot — one from each department — without overlapping time slots and without crossing the current <?= $auto_assign_max_slots ?>-slot per teacher limit.
                    All changes are version-controlled and can be reverted at any time.
                </p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <!-- Assign unassigned only (safe, single confirm) -->
                <form method="POST" id="formAutoAssignUnassigned">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="auto_assign">
                    <button type="button" class="btn btn-primary btn-sm" style="border-radius:10px;"
                            onclick="if(confirm('Fill only unassigned slots with auto-assignment? Existing assignments will not be changed.')) document.getElementById('formAutoAssignUnassigned').submit();">
                        <i class="fas fa-magic me-1"></i> Auto-Assign Empty Slots
                    </button>
                </form>
                <!-- Re-assign ALL (destructive — protected modal) -->
                <button type="button" class="btn btn-danger btn-sm" style="border-radius:10px;"
                        data-bs-toggle="modal" data-bs-target="#modalReassignAll">
                    <i class="fas fa-redo me-1"></i> Re-assign All
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Re-assign All confirmation modal -->
<div class="modal fade" id="modalReassignAll" tabindex="-1" aria-labelledby="modalReassignAllLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-danger">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="modalReassignAllLabel"><i class="fas fa-exclamation-triangle me-2"></i>Re-assign All Invigilators</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger py-2 mb-3">
                    <strong>This will clear ALL existing assignments</strong> for this exam and re-run the auto-assignment algorithm from scratch.
                    Any manual adjustments you have made will be lost.
                </div>
                <p class="mb-1" style="font-size:.9rem;">A version snapshot will be saved automatically so you can <strong>revert</strong> if needed.</p>
                <p class="mb-2 mt-3" style="font-size:.9rem;">To confirm, type <strong>REASSIGN ALL</strong> in the box below:</p>
                <input type="text" id="reassignConfirmText" class="form-control" placeholder="Type REASSIGN ALL to confirm"
                       oninput="document.getElementById('btnConfirmReassign').disabled = this.value.trim() !== 'REASSIGN ALL';">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" id="formReassignAll" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="auto_assign">
                    <input type="hidden" name="reassign" value="1">
                    <button type="submit" id="btnConfirmReassign" class="btn btn-danger" disabled>
                        <i class="fas fa-redo me-1"></i> Yes, Re-assign All
                    </button>
                </form>
            </div>
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
                        <?php if (!$print_mode && (is_super_admin() || can_access('exam-invigilation', 'can_edit') || can_access('exam-invigilation', 'can_delete'))): ?>
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
                    <td class="px-4 fw-medium">
                        <?= h($s['room_number']) ?>
                        <?php if (!empty($s['pref_dept_name'])): ?>
                        <div><small class="text-muted" title="Preferred dept for Invigilator 1">
                            <i class="fas fa-tag me-1"></i><?= h($s['pref_dept_name']) ?>
                        </small></div>
                        <?php endif; ?>
                    </td>
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
                    <?php if (!$print_mode && (is_super_admin() || can_access('exam-invigilation', 'can_edit') || can_access('exam-invigilation', 'can_delete'))): ?>
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
