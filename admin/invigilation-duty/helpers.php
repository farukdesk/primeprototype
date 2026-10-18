<?php
// Helpers for the faculty self-service "Invigilation Duty" module.
// Resolves the logged-in user's ei_faculty record(s) so they can see the
// invigilation slots assigned to them in the Exam Invigilation module.

/**
 * Best-effort auto-migration: add a nullable user_id link column to
 * ei_faculty so pool members can be tied to login accounts.
 */
function ivd_ensure_user_column(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $has = db()->query("SHOW COLUMNS FROM ei_faculty LIKE 'user_id'")->fetch();
        if (!$has) {
            db()->exec(
                "ALTER TABLE ei_faculty
                   ADD COLUMN user_id INT UNSIGNED DEFAULT NULL COMMENT 'Linked login account (users.id)' AFTER dept_id,
                   ADD KEY idx_ei_faculty_user (user_id)"
            );
        }
    } catch (Throwable $e) {
        // best effort — queries will surface real problems
    }
}

/**
 * Normalise a person name for matching: lowercase, strip common titles
 * ("Dr.", "Prof.", "Mr.", "Ms.", "Mrs.", "Md.") and punctuation, collapse
 * whitespace.
 */
function ivd_normalize_name(string $name): string
{
    $name = mb_strtolower(trim($name));
    $name = str_replace(['.', ','], ' ', $name);
    $name = preg_replace('/\b(dr|prof|professor|mr|ms|mrs|engr|md)\b/u', ' ', $name);
    $name = preg_replace('/\s+/u', ' ', (string)$name);
    return trim((string)$name);
}

/**
 * Resolve the ei_faculty row id(s) belonging to the given user.
 *
 * 1. Direct link via ei_faculty.user_id.
 * 2. Fallback: unique name match against the user's full name and their
 *    dept_faculty profile name(s). A confident unique match is persisted
 *    back to ei_faculty.user_id so future lookups are direct.
 *
 * @return int[] ei_faculty ids (may be empty)
 */
function ivd_faculty_ids_for_user(int $user_id): array
{
    if ($user_id <= 0) return [];
    ivd_ensure_user_column();

    // 1. Direct link
    try {
        $st = db()->prepare('SELECT id FROM ei_faculty WHERE user_id = ?');
        $st->execute([$user_id]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if ($ids) return $ids;
    } catch (Throwable $e) {
        return [];
    }

    // 2. Fallback: match by name
    $candidates = [];
    try {
        $st = db()->prepare('SELECT full_name FROM users WHERE id = ?');
        $st->execute([$user_id]);
        $full_name = (string)$st->fetchColumn();
        if ($full_name !== '') $candidates[] = $full_name;
    } catch (Throwable $e) {}
    try {
        $st = db()->prepare('SELECT name FROM dept_faculty WHERE user_id = ? AND is_active = 1');
        $st->execute([$user_id]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) {
            if ((string)$n !== '') $candidates[] = (string)$n;
        }
    } catch (Throwable $e) {}

    $wanted = [];
    foreach ($candidates as $c) {
        $norm = ivd_normalize_name($c);
        if ($norm !== '') $wanted[$norm] = true;
    }
    if (!$wanted) return [];

    $matches = [];
    try {
        foreach (db()->query('SELECT id, name, user_id FROM ei_faculty WHERE is_active = 1')->fetchAll() as $row) {
            if (!empty($row['user_id'])) continue; // already linked to someone else
            if (isset($wanted[ivd_normalize_name((string)$row['name'])])) {
                $matches[] = (int)$row['id'];
            }
        }
    } catch (Throwable $e) {
        return [];
    }

    // Persist only a unique, unambiguous match
    if (count($matches) === 1) {
        try {
            db()->prepare('UPDATE ei_faculty SET user_id = ? WHERE id = ? AND user_id IS NULL')
                ->execute([$user_id, $matches[0]]);
        } catch (Throwable $e) {}
    }

    return $matches;
}

/**
 * Fetch all invigilation duty slots for the given ei_faculty ids, in date
 * order. Each row includes the exam, slot details, which seat
 * (Invigilator 1/2) is mine, the co-invigilator's name and my attendance
 * status for that slot (1 attended, 0 absent, null = not recorded).
 *
 * @param int[] $faculty_ids
 */
function ivd_duties_for_faculty(array $faculty_ids): array
{
    $faculty_ids = array_values(array_unique(array_map('intval', $faculty_ids)));
    if (!$faculty_ids) return [];

    $ph = implode(',', array_fill(0, count($faculty_ids), '?'));
    $st = db()->prepare(
        "SELECT s.id, s.slot_date, s.time_slot, s.room_number,
                s.faculty1_id, s.faculty2_id,
                e.id AS exam_id, e.exam_name, e.exam_year,
                f1.name AS faculty1_name, f2.name AS faculty2_name,
                d.name AS dept_name
         FROM ei_slots s
         JOIN ei_exams e        ON e.id = s.exam_id
         LEFT JOIN ei_faculty f1 ON f1.id = s.faculty1_id
         LEFT JOIN ei_faculty f2 ON f2.id = s.faculty2_id
         LEFT JOIN dept_departments d ON d.id = s.dept_id
         WHERE s.faculty1_id IN ($ph) OR s.faculty2_id IN ($ph)
         ORDER BY s.slot_date ASC, s.time_slot ASC, s.room_number ASC"
    );
    $st->execute(array_merge($faculty_ids, $faculty_ids));
    $rows = $st->fetchAll();
    if (!$rows) return [];

    // My attendance per slot
    $slot_ids = array_map(static fn ($r) => (int)$r['id'], $rows);
    $att = [];
    try {
        $sph = implode(',', array_fill(0, count($slot_ids), '?'));
        $fph = implode(',', array_fill(0, count($faculty_ids), '?'));
        $ast = db()->prepare(
            "SELECT slot_id, attended FROM ei_slot_attendance
             WHERE slot_id IN ($sph) AND faculty_id IN ($fph)"
        );
        $ast->execute(array_merge($slot_ids, $faculty_ids));
        foreach ($ast->fetchAll() as $a) {
            $att[(int)$a['slot_id']] = (int)$a['attended'];
        }
    } catch (Throwable $e) {}

    $mine = array_flip($faculty_ids);
    foreach ($rows as &$r) {
        $is_f1            = isset($mine[(int)$r['faculty1_id']]);
        $r['my_position'] = $is_f1 ? 1 : 2;
        $r['co_name']     = $is_f1 ? ($r['faculty2_name'] ?? null) : ($r['faculty1_name'] ?? null);
        $r['attended']    = $att[(int)$r['id']] ?? null;
    }
    unset($r);

    return $rows;
}

/**
 * Resolve the department id(s) of the given ei_faculty ids.
 *
 * @param int[] $faculty_ids
 * @return int[] dept ids (may be empty)
 */
function ivd_dept_ids_for_faculty(array $faculty_ids): array
{
    $faculty_ids = array_values(array_unique(array_map('intval', $faculty_ids)));
    if (!$faculty_ids) return [];

    $ph = implode(',', array_fill(0, count($faculty_ids), '?'));
    try {
        $st = db()->prepare(
            "SELECT DISTINCT dept_id FROM ei_faculty WHERE id IN ($ph) AND dept_id IS NOT NULL"
        );
        $st->execute($faculty_ids);
        return array_values(array_filter(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN))));
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Whether any of the given ei_faculty records holds a departmental oversight
 * role — i.e. its designation contains "coordinator", "head", "dean" or
 * "section officer". Only these roles may see the department-wide "My
 * Department's Duties" overview; ordinary faculty see only their own duties.
 *
 * @param int[] $faculty_ids
 */
function ivd_can_view_dept_duties(array $faculty_ids): bool
{
    $faculty_ids = array_values(array_unique(array_map('intval', $faculty_ids)));
    if (!$faculty_ids) return false;

    $ph = implode(',', array_fill(0, count($faculty_ids), '?'));
    try {
        $st = db()->prepare(
            "SELECT 1 FROM ei_faculty
             WHERE id IN ($ph) AND (
                 LOWER(COALESCE(designation, '')) LIKE '%coordinator%'
                 OR LOWER(COALESCE(designation, '')) LIKE '%head%'
                 OR LOWER(COALESCE(designation, '')) LIKE '%dean%'
                 OR LOWER(COALESCE(designation, '')) LIKE '%section officer%'
             )
             LIMIT 1"
        );
        $st->execute($faculty_ids);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Fetch all invigilation duty slots assigned to faculty of the given
 * department(s), in date order. Each row carries both invigilators' names,
 * the exam and slot details and the department name. Used for the faculty
 * self-service "My Department's Duties" overview.
 *
 * @param int[] $dept_ids
 */
function ivd_dept_duties(array $dept_ids): array
{
    $dept_ids = array_values(array_unique(array_filter(array_map('intval', $dept_ids))));
    if (!$dept_ids) return [];

    $ph = implode(',', array_fill(0, count($dept_ids), '?'));
    try {
        $st = db()->prepare(
            "SELECT s.id, s.slot_date, s.time_slot, s.room_number,
                    s.faculty1_id, s.faculty2_id,
                    e.exam_name, e.exam_year,
                    f1.name AS faculty1_name, f2.name AS faculty2_name,
                    d.name AS dept_name
             FROM ei_slots s
             JOIN ei_exams e        ON e.id = s.exam_id
             LEFT JOIN ei_faculty f1 ON f1.id = s.faculty1_id
             LEFT JOIN ei_faculty f2 ON f2.id = s.faculty2_id
             LEFT JOIN dept_departments d ON d.id = s.dept_id
             WHERE f1.dept_id IN ($ph) OR f2.dept_id IN ($ph)
             ORDER BY s.slot_date ASC, s.time_slot ASC, s.room_number ASC"
        );
        $st->execute(array_merge($dept_ids, $dept_ids));
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

// ── Duty swap / substitute requests ─────────────────────────────────────────

/**
 * Best-effort auto-migration: create the ivd_swap_requests table on first use
 * so existing deployments work without a manual migration step.
 */
function ivd_ensure_swap_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS ivd_swap_requests (
                id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                request_type ENUM('swap','substitute') NOT NULL,
                status ENUM('pending','accepted','rejected','cancelled') NOT NULL DEFAULT 'pending',
                requester_faculty_id INT(10) UNSIGNED NOT NULL,
                requester_slot_id INT(10) UNSIGNED NOT NULL,
                requester_position TINYINT(1) NOT NULL,
                target_faculty_id INT(10) UNSIGNED NOT NULL,
                target_slot_id INT(10) UNSIGNED DEFAULT NULL,
                target_position TINYINT(1) DEFAULT NULL,
                message VARCHAR(500) DEFAULT NULL,
                responded_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT current_timestamp(),
                updated_at DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (id),
                KEY idx_ivd_swap_requester (requester_faculty_id, status),
                KEY idx_ivd_swap_target (target_faculty_id, status),
                KEY idx_ivd_swap_req_slot (requester_slot_id),
                KEY idx_ivd_swap_tgt_slot (target_slot_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        // best effort — queries will surface real problems
    }
}

/**
 * Load a single ei_slots row (with exam name) by id, or null.
 */
function ivd_get_slot(int $slot_id): ?array
{
    if ($slot_id <= 0) return null;
    try {
        $st = db()->prepare(
            'SELECT s.*, e.exam_name
             FROM ei_slots s JOIN ei_exams e ON e.id = s.exam_id
             WHERE s.id = ?'
        );
        $st->execute([$slot_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Which seat (1 or 2) does the faculty hold on this slot? 0 if none.
 */
function ivd_slot_position_for_faculty(array $slot, int $faculty_id): int
{
    if ($faculty_id <= 0) return 0;
    if ((int)($slot['faculty1_id'] ?? 0) === $faculty_id) return 1;
    if ((int)($slot['faculty2_id'] ?? 0) === $faculty_id) return 2;
    return 0;
}

/**
 * Active faculty eligible to receive a request for the requester's slot.
 *
 * For 'substitute': anyone free at the slot's date+time (not already assigned
 * to a slot then). For 'swap': same free-at-slot rule — the target's own slot
 * is chosen later; we still exclude people busy at the requester's time so the
 * target can actually cover it.
 *
 * Excludes the requester faculty id(s) themselves.
 *
 * @param int[] $exclude_faculty_ids
 * @return array<int, array{id:int,name:string,designation:?string,dept_name:string}>
 */
function ivd_eligible_targets_for_slot(array $slot, array $exclude_faculty_ids = []): array
{
    require_once __DIR__ . '/../exam-invigilation/slot-helpers.php';
    $slot_date = (string)($slot['slot_date'] ?? '');
    $time_slot = (string)($slot['time_slot'] ?? '');
    if ($slot_date === '' || $time_slot === '') return [];

    $busy = ei_get_busy_faculty_ids($slot_date, $time_slot); // [faculty_id => true]
    $exclude = array_fill_keys(array_map('intval', $exclude_faculty_ids), true);

    $out = [];
    try {
        foreach (ei_get_faculty_list() as $f) {
            $fid = (int)$f['id'];
            if (isset($exclude[$fid]) || isset($busy[$fid])) continue;
            $out[$fid] = [
                'id'          => $fid,
                'name'        => (string)$f['name'],
                'designation' => $f['designation'] ?? null,
                'dept_name'   => (string)($f['dept_name'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

/**
 * Create a swap/substitute request. Returns [ok(bool), message(string)].
 *
 * @param int[] $requester_faculty_ids ei_faculty ids owned by the acting user
 */
function ivd_create_swap_request(
    array $requester_faculty_ids,
    int $requester_slot_id,
    string $request_type,
    int $target_faculty_id,
    string $message = ''
): array {
    ivd_ensure_swap_table();

    $request_type = $request_type === 'swap' ? 'swap' : 'substitute';
    $requester_faculty_ids = array_values(array_unique(array_map('intval', $requester_faculty_ids)));

    $slot = ivd_get_slot($requester_slot_id);
    if ($slot === null) return [false, 'The selected duty could not be found.'];

    // Verify the acting user actually holds a seat on this slot.
    $requester_position = 0;
    $requester_faculty_id = 0;
    foreach ($requester_faculty_ids as $fid) {
        $pos = ivd_slot_position_for_faculty($slot, $fid);
        if ($pos > 0) { $requester_position = $pos; $requester_faculty_id = $fid; break; }
    }
    if ($requester_position === 0) {
        return [false, 'You are not assigned to the selected duty.'];
    }

    if ((string)$slot['slot_date'] < date('Y-m-d')) {
        return [false, 'You cannot request changes for a past duty.'];
    }

    if ($target_faculty_id <= 0 || in_array($target_faculty_id, $requester_faculty_ids, true)) {
        return [false, 'Please choose a different faculty member.'];
    }

    // Target must be eligible (active + free at this slot time).
    $eligible = ivd_eligible_targets_for_slot($slot, $requester_faculty_ids);
    if (!isset($eligible[$target_faculty_id])) {
        return [false, 'The selected faculty is not available for this duty slot.'];
    }

    // No duplicate pending request for the same slot + target.
    try {
        $dup = db()->prepare(
            "SELECT id FROM ivd_swap_requests
             WHERE requester_slot_id = ? AND target_faculty_id = ? AND status = 'pending' LIMIT 1"
        );
        $dup->execute([$requester_slot_id, $target_faculty_id]);
        if ($dup->fetchColumn()) {
            return [false, 'A pending request for this duty and faculty already exists.'];
        }
    } catch (Throwable $e) {
        return [false, 'Could not create the request. Please try again.'];
    }

    try {
        $ins = db()->prepare(
            'INSERT INTO ivd_swap_requests
                (request_type, status, requester_faculty_id, requester_slot_id, requester_position,
                 target_faculty_id, message)
             VALUES (?, "pending", ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $request_type, $requester_faculty_id, $requester_slot_id, $requester_position,
            $target_faculty_id, ($message !== '' ? mb_substr($message, 0, 500) : null),
        ]);
    } catch (Throwable $e) {
        return [false, 'Could not create the request. Please try again.'];
    }

    ivd_push_to_faculty(
        $target_faculty_id,
        'Invigilation duty request',
        'A colleague has requested you to ' .
            ($request_type === 'swap' ? 'swap' : 'cover') .
            ' an invigilation duty on ' . date('d M Y', strtotime((string)$slot['slot_date'])) .
            '. Open Invigilation Duty in the app to respond.'
    );

    return [true, 'Your request has been sent.'];
}

/**
 * Pending requests addressed TO the given faculty (to accept/reject).
 *
 * @param int[] $faculty_ids
 */
function ivd_incoming_requests(array $faculty_ids): array
{
    ivd_ensure_swap_table();
    $faculty_ids = array_values(array_unique(array_map('intval', $faculty_ids)));
    if (!$faculty_ids) return [];
    $ph = implode(',', array_fill(0, count($faculty_ids), '?'));
    try {
        $st = db()->prepare(
            "SELECT r.*, rf.name AS requester_name,
                    s.slot_date, s.time_slot, s.room_number, e.exam_name
             FROM ivd_swap_requests r
             JOIN ei_faculty rf ON rf.id = r.requester_faculty_id
             JOIN ei_slots s ON s.id = r.requester_slot_id
             JOIN ei_exams e ON e.id = s.exam_id
             WHERE r.target_faculty_id IN ($ph) AND r.status = 'pending'
             ORDER BY r.created_at DESC"
        );
        $st->execute($faculty_ids);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Requests created BY the given faculty (status tracking).
 *
 * @param int[] $faculty_ids
 */
function ivd_outgoing_requests(array $faculty_ids): array
{
    ivd_ensure_swap_table();
    $faculty_ids = array_values(array_unique(array_map('intval', $faculty_ids)));
    if (!$faculty_ids) return [];
    $ph = implode(',', array_fill(0, count($faculty_ids), '?'));
    try {
        $st = db()->prepare(
            "SELECT r.*, tf.name AS target_name,
                    s.slot_date, s.time_slot, s.room_number, e.exam_name
             FROM ivd_swap_requests r
             JOIN ei_faculty tf ON tf.id = r.target_faculty_id
             JOIN ei_slots s ON s.id = r.requester_slot_id
             JOIN ei_exams e ON e.id = s.exam_id
             WHERE r.requester_faculty_id IN ($ph)
             ORDER BY r.created_at DESC
             LIMIT 50"
        );
        $st->execute($faculty_ids);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Accept a request addressed to the acting user, applying the change to
 * ei_slots inside a transaction. Returns [ok, message].
 *
 * @param int[] $faculty_ids acting user's ei_faculty ids
 */
function ivd_accept_request(int $request_id, array $faculty_ids): array
{
    ivd_ensure_swap_table();
    $faculty_ids = array_values(array_unique(array_map('intval', $faculty_ids)));

    try {
        $st = db()->prepare("SELECT * FROM ivd_swap_requests WHERE id = ? AND status = 'pending'");
        $st->execute([$request_id]);
        $req = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [false, 'Could not load the request.'];
    }
    if (!$req) return [false, 'This request is no longer available.'];

    $target_faculty_id = (int)$req['target_faculty_id'];
    if (!in_array($target_faculty_id, $faculty_ids, true)) {
        return [false, 'You are not authorised to respond to this request.'];
    }

    $slot = ivd_get_slot((int)$req['requester_slot_id']);
    if ($slot === null) return [false, 'The duty no longer exists.'];

    // Re-verify the requester still holds their seat.
    $requester_position = (int)$req['requester_position'];
    $requester_faculty_id = (int)$req['requester_faculty_id'];
    if (ivd_slot_position_for_faculty($slot, $requester_faculty_id) !== $requester_position) {
        return [false, 'The duty assignment has changed; this request is no longer valid.'];
    }

    require_once __DIR__ . '/../exam-invigilation/slot-helpers.php';

    // Target must still be free at this slot's time (ignore the requester's slot).
    if (ei_faculty_has_overlap($target_faculty_id, (string)$slot['slot_date'], (string)$slot['time_slot'], (int)$slot['id'])) {
        return [false, 'You now have another duty at that time and can no longer take this one.'];
    }

    $seat_col = $requester_position === 1 ? 'faculty1_id' : 'faculty2_id';

    try {
        db()->beginTransaction();
        $upd = db()->prepare("UPDATE ei_slots SET $seat_col = ? WHERE id = ?");
        $upd->execute([$target_faculty_id, (int)$slot['id']]);

        $done = db()->prepare(
            "UPDATE ivd_swap_requests
             SET status = 'accepted', responded_at = NOW()
             WHERE id = ? AND status = 'pending'"
        );
        $done->execute([$request_id]);

        // Cancel other pending requests that target the same seat/slot.
        $cancel = db()->prepare(
            "UPDATE ivd_swap_requests
             SET status = 'cancelled', responded_at = NOW()
             WHERE requester_slot_id = ? AND status = 'pending' AND id != ?"
        );
        $cancel->execute([(int)$slot['id'], $request_id]);

        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        return [false, 'Could not apply the change. Please try again.'];
    }

    // Snapshot for audit/version history (best effort).
    try {
        ei_save_assignment_snapshot(
            (int)$slot['exam_id'],
            'manual_edit',
            'Duty ' . ($req['request_type'] === 'swap' ? 'swap' : 'substitution') .
                ' accepted via self-service for ' . (string)$slot['slot_date']
        );
    } catch (Throwable $e) {}

    // Notify the original requester that it was accepted.
    ivd_push_to_faculty(
        $requester_faculty_id,
        'Invigilation duty request accepted',
        'Your invigilation duty on ' . date('d M Y', strtotime((string)$slot['slot_date'])) .
            ' has been accepted and reassigned.'
    );

    return [true, 'Request accepted. The duty has been reassigned.'];
}

/**
 * Reject a request addressed to the acting user. Returns [ok, message].
 *
 * @param int[] $faculty_ids acting user's ei_faculty ids
 */
function ivd_reject_request(int $request_id, array $faculty_ids): array
{
    ivd_ensure_swap_table();
    $faculty_ids = array_values(array_unique(array_map('intval', $faculty_ids)));
    try {
        $st = db()->prepare("SELECT * FROM ivd_swap_requests WHERE id = ? AND status = 'pending'");
        $st->execute([$request_id]);
        $req = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [false, 'Could not load the request.'];
    }
    if (!$req) return [false, 'This request is no longer available.'];
    if (!in_array((int)$req['target_faculty_id'], $faculty_ids, true)) {
        return [false, 'You are not authorised to respond to this request.'];
    }
    try {
        db()->prepare(
            "UPDATE ivd_swap_requests SET status = 'rejected', responded_at = NOW() WHERE id = ? AND status = 'pending'"
        )->execute([$request_id]);
    } catch (Throwable $e) {
        return [false, 'Could not update the request.'];
    }

    $slot = ivd_get_slot((int)$req['requester_slot_id']);
    ivd_push_to_faculty(
        (int)$req['requester_faculty_id'],
        'Invigilation duty request declined',
        'Your invigilation duty request' .
            ($slot ? ' for ' . date('d M Y', strtotime((string)$slot['slot_date'])) : '') .
            ' was declined.'
    );
    return [true, 'Request declined.'];
}

/**
 * Cancel a pending request created BY the acting user. Returns [ok, message].
 *
 * @param int[] $faculty_ids acting user's ei_faculty ids
 */
function ivd_cancel_request(int $request_id, array $faculty_ids): array
{
    ivd_ensure_swap_table();
    $faculty_ids = array_values(array_unique(array_map('intval', $faculty_ids)));
    if (!$faculty_ids) return [false, 'Not authorised.'];
    $ph = implode(',', array_fill(0, count($faculty_ids), '?'));
    try {
        $st = db()->prepare(
            "UPDATE ivd_swap_requests SET status = 'cancelled', responded_at = NOW()
             WHERE id = ? AND status = 'pending' AND requester_faculty_id IN ($ph)"
        );
        $st->execute(array_merge([$request_id], $faculty_ids));
        if ($st->rowCount() < 1) {
            return [false, 'This request can no longer be cancelled.'];
        }
    } catch (Throwable $e) {
        return [false, 'Could not cancel the request.'];
    }
    return [true, 'Request cancelled.'];
}

/**
 * Best-effort push notification to the login account linked to an ei_faculty.
 */
function ivd_push_to_faculty(int $faculty_id, string $title, string $body): void
{
    if ($faculty_id <= 0) return;
    try {
        ivd_ensure_user_column();
        $st = db()->prepare('SELECT user_id FROM ei_faculty WHERE id = ?');
        $st->execute([$faculty_id]);
        $user_id = (int)$st->fetchColumn();
        if ($user_id <= 0) return;

        require_once __DIR__ . '/../app-notifications/helpers.php';
        if (!function_exists('apn_fcm_is_configured') || !apn_fcm_is_configured()) return;
        apn_send_to_audience('user', $title, $body, null, $user_id);
    } catch (Throwable $e) {
        // delivery must never block the duty change
    }
}
