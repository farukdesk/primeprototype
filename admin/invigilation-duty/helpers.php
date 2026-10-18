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
