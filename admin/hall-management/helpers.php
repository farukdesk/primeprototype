<?php
/**
 * Hall Management – shared helpers.
 */
require_once __DIR__ . '/../includes/auth.php';

/**
 * Departments visible to the current user (dept scope aware).
 */
function hm_departments(): array
{
    $scope = get_dept_scope();
    if ($scope !== null && empty($scope)) return [];
    $sql    = 'SELECT id, name FROM dept_departments WHERE is_active = 1';
    $params = [];
    if ($scope !== null) {
        $ph  = implode(',', array_fill(0, count($scope), '?'));
        $sql .= " AND id IN ($ph)";
        $params = $scope;
    }
    $sql .= ' ORDER BY name ASC';
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Load one hall (with dept name) or null. Enforces dept scope.
 */
function hm_get_hall(int $id): ?array
{
    $st = db()->prepare(
        'SELECT h.*, d.name AS dept_name
           FROM hm_halls h
           JOIN dept_departments d ON d.id = h.dept_id
          WHERE h.id = ?'
    );
    $st->execute([$id]);
    $hall = $st->fetch();
    if (!$hall) return null;
    if (!can_access_dept((int)$hall['dept_id'])) return null;
    return $hall;
}

/**
 * Columns of a hall ordered by col_no.
 */
function hm_hall_columns(int $hall_id): array
{
    $st = db()->prepare(
        'SELECT col_no, seat_capacity FROM hm_hall_columns WHERE hall_id = ? ORDER BY col_no ASC'
    );
    $st->execute([$hall_id]);
    return $st->fetchAll();
}

/**
 * Validate + normalise the submitted column capacities.
 * Returns [list of int capacities (1-based order), error string or null].
 */
function hm_parse_columns(array $raw, int $expected): array
{
    $caps = [];
    for ($i = 0; $i < $expected; $i++) {
        $cap = (int)($raw[$i] ?? 0);
        if ($cap < 1 || $cap > 500) {
            return [[], 'Each column must have a seat capacity between 1 and 500.'];
        }
        $caps[] = $cap;
    }
    return [$caps, null];
}

/**
 * Replace the column rows of a hall.
 */
function hm_save_columns(int $hall_id, array $caps): void
{
    db()->prepare('DELETE FROM hm_hall_columns WHERE hall_id = ?')->execute([$hall_id]);
    $ins = db()->prepare('INSERT INTO hm_hall_columns (hall_id, col_no, seat_capacity) VALUES (?, ?, ?)');
    foreach ($caps as $i => $cap) {
        $ins->execute([$hall_id, $i + 1, $cap]);
    }
}

/**
 * Make sure hm_halls has the exam_date / exam_time columns and that the
 * unique key allows the same room on different dates/times (safe to run on
 * every request; mirrors admin/hall-management-schema.sql).
 */
function hm_ensure_schedule_columns(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $has = db()->query("SHOW COLUMNS FROM hm_halls LIKE 'exam_date'")->fetch();
        if (!$has) {
            db()->exec(
                'ALTER TABLE hm_halls
                   ADD COLUMN exam_date DATE DEFAULT NULL AFTER room_number,
                   ADD COLUMN exam_time TIME DEFAULT NULL AFTER exam_date'
            );
        }
        $idx = db()->query("SHOW INDEX FROM hm_halls WHERE Key_name = 'uq_hm_dept_room'")->fetch();
        if ($idx) {
            db()->exec(
                'ALTER TABLE hm_halls
                  DROP INDEX uq_hm_dept_room,
                   ADD UNIQUE KEY uq_hm_room_slot (dept_id, room_number, exam_date, exam_time)'
            );
        }
    } catch (Throwable $e) {
        // best effort — queries will surface real problems
    }
}

/**
 * True when another hall row already books the same room for the same exam
 * date AND the same exam time (same date + different time is allowed).
 */
function hm_room_slot_taken(string $room_number, string $exam_date, string $exam_time, int $exclude_id = 0): bool
{
    $sql    = 'SELECT COUNT(*) FROM hm_halls WHERE room_number = ? AND exam_date = ? AND exam_time = ?';
    $params = [$room_number, $exam_date, $exam_time];
    if ($exclude_id > 0) { $sql .= ' AND id <> ?'; $params[] = $exclude_id; }
    $st = db()->prepare($sql);
    $st->execute($params);
    return (int)$st->fetchColumn() > 0;
}

/** Human-readable "d M Y, g:i A" label for a hall's exam date/time. */
function hm_slot_label(?string $exam_date, ?string $exam_time): string
{
    if (!$exam_date) return '—';
    $label = date('d M Y', strtotime($exam_date));
    if ($exam_time) $label .= ', ' . date('g:i A', strtotime($exam_time));
    return $label;
}

/* ========================================================================
 * Student seat assignments (students come from generated admit cards)
 * ======================================================================== */

/**
 * Make sure the hm_hall_assignments table exists (mirror of
 * admin/hall-management-assignments.sql — safe to run on every request).
 */
function hm_ensure_assignments_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS `hm_hall_assignments` (
              `id`          INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
              `hall_id`     INT(10) UNSIGNED NOT NULL,
              `exam_date`   DATE NOT NULL,
              `student_id`  INT(10) UNSIGNED NOT NULL,
              `col_no`      INT(10) UNSIGNED NOT NULL,
              `seat_no`     INT(10) UNSIGNED NOT NULL,
              `dept_id`     INT(10) UNSIGNED DEFAULT NULL,
              `program_id`  INT(10) UNSIGNED DEFAULT NULL,
              `batch_id`    INT(10) UNSIGNED DEFAULT NULL,
              `section`     VARCHAR(100) DEFAULT NULL,
              `shift`       VARCHAR(25)  DEFAULT NULL,
              `assigned_by` INT(10) UNSIGNED DEFAULT NULL,
              `created_at`  TIMESTAMP NOT NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_hma_seat`    (`hall_id`, `exam_date`, `col_no`, `seat_no`),
              UNIQUE KEY `uq_hma_student` (`hall_id`, `exam_date`, `student_id`),
              KEY `idx_hma_date_student` (`exam_date`, `student_id`),
              CONSTRAINT `fk_hma_hall` FOREIGN KEY (`hall_id`)
                  REFERENCES `hm_halls` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_hma_student` FOREIGN KEY (`student_id`)
                  REFERENCES `students` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        // table probably exists with an older definition — queries will tell
    }
}

/** Programs dropdown data. */
function hm_programs(): array
{
    return db()->query('SELECT id, program_name FROM dept_academic_programs ORDER BY program_name ASC')->fetchAll();
}

/** Batches dropdown data. */
function hm_batches(): array
{
    return db()->query('SELECT id, name FROM student_batches ORDER BY sort_order ASC, name ASC')->fetchAll();
}

/** Distinct shifts seen on course offers / students (Day / Evening …). */
function hm_shift_options(): array
{
    $opts = [];
    try {
        $opts = db()->query(
            "SELECT DISTINCT shift FROM co_offers WHERE shift IS NOT NULL AND shift <> '' ORDER BY shift ASC"
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {}
    if (!$opts) $opts = ['Day', 'Evening'];
    return $opts;
}

/** Distinct section labels used on admit card course rows. */
function hm_section_options(): array
{
    try {
        return db()->query(
            "SELECT DISTINCT section FROM ac_admit_card_courses WHERE section IS NOT NULL AND section <> '' ORDER BY section ASC"
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Courses (code + title) that have an exam on $exam_date according to the
 * generated (active) admit cards of the given dept / program (+ optional
 * batch / shift). Used as the Course filter options on the hall
 * "Assign Students" panel: once an exam date (and shift) is picked, only
 * the courses examined on that day are offered, and students can then be
 * narrowed down to those sitting a specific course.
 *
 * For subject-linked card rows the shift resolves from the course offer;
 * legacy rows without a subject link have no offer shift and are always
 * included. Returns rows: course_code, course_title (deduped by code).
 */
function hm_exam_course_options(int $dept_id, int $program_id, int $batch_id, string $exam_date, string $shift): array
{
    if ($dept_id <= 0 || $program_id <= 0 || $exam_date === '') return [];
    $db = db();

    $card_where = 'ac.is_active = 1 AND ac.dept_id = ? AND ac.program_id = ?';
    $params     = [$dept_id, $program_id];
    if ($batch_id > 0) { $card_where .= ' AND ac.batch_id = ?'; $params[] = $batch_id; }

    $has_subject_col = false;
    try { $db->query('SELECT offer_subject_id FROM ac_admit_card_courses LIMIT 1'); $has_subject_col = true; } catch (Throwable $e) {}

    $joins = '';
    $shift_cond = '';
    if ($has_subject_col) {
        $joins = ' LEFT JOIN co_offer_subjects cos ON cos.id = cc.offer_subject_id
                   LEFT JOIN co_offers o ON o.id = cos.offer_id';
        if ($shift !== '') { $shift_cond = ' AND (o.id IS NULL OR o.shift = ?)'; }
    }
    $sql = "SELECT DISTINCT cc.course_code, cc.course_title
              FROM ac_admit_cards ac
              JOIN ac_admit_card_courses cc ON cc.admit_card_id = ac.id
              $joins
             WHERE $card_where AND cc.exam_date = ?$shift_cond
             ORDER BY cc.course_code ASC, cc.course_title ASC";
    $params[] = $exam_date;
    if ($shift_cond !== '') $params[] = $shift;
    try {
        $st = $db->prepare($sql);
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $code = (string)$row['course_code'];
            if ($code === '' || isset($out[$code])) continue;
            $out[$code] = ['course_code' => $code, 'course_title' => (string)$row['course_title']];
        }
        return array_values($out);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Students sitting an exam on $exam_date, found through generated (active)
 * admit cards matching dept / program (+ optional batch / section / shift /
 * course).
 *
 * Cards whose course rows are linked to course-offer subjects resolve
 * students via co_registrations (shift from the offer); legacy cards
 * without subject links fall back to the card's dept/program/batch
 * students (shift / section from the students table). A non-empty
 * $course_code narrows both paths to students whose admit-card course row
 * for that date is the given course.
 *
 * Returns rows: id, student_id, full_name, batch_name, shift, section.
 */
function hm_find_exam_students(int $dept_id, int $program_id, int $batch_id, string $exam_date, string $section, string $shift, string $course_code = ''): array
{
    $db  = db();
    $out = [];

    $card_where  = 'ac.is_active = 1 AND ac.dept_id = ? AND ac.program_id = ?';
    $card_params = [$dept_id, $program_id];
    if ($batch_id > 0) { $card_where .= ' AND ac.batch_id = ?'; $card_params[] = $batch_id; }

    $has_subject_col = false;
    try { $db->query('SELECT offer_subject_id FROM ac_admit_card_courses LIMIT 1'); $has_subject_col = true; } catch (Throwable $e) {}

    // ── Subject-linked cards: students via their registrations ─────────
    if ($has_subject_col) {
        $sql = "SELECT DISTINCT s.id, s.student_id, s.full_name, s.batch_id,
                       b.name AS batch_name, o.shift, cc.section
                  FROM ac_admit_cards ac
                  JOIN ac_admit_card_courses cc ON cc.admit_card_id = ac.id
                  JOIN co_offer_subjects cos ON cos.id = cc.offer_subject_id
                  JOIN co_offers o ON o.id = cos.offer_id
                  JOIN co_registrations r ON r.offer_subject_id = cos.id
                  JOIN students s ON s.id = r.student_id AND s.status = 'Active'
                  LEFT JOIN student_batches b ON b.id = s.batch_id
                 WHERE $card_where AND cc.exam_date = ?";
        $params = array_merge($card_params, [$exam_date]);
        if ($section !== '')     { $sql .= ' AND cc.section = ?';     $params[] = $section; }
        if ($shift !== '')       { $sql .= ' AND o.shift = ?';        $params[] = $shift; }
        if ($course_code !== '') { $sql .= ' AND cc.course_code = ?'; $params[] = $course_code; }
        $st = $db->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll() as $row) $out[(int)$row['id']] = $row;
    }

    // ── Legacy / manual cards (no subject links): dept+program+batch ───
    $legacy_cond = $has_subject_col
        ? 'NOT EXISTS (SELECT 1 FROM ac_admit_card_courses cx
                        WHERE cx.admit_card_id = ac.id AND cx.offer_subject_id IS NOT NULL)'
        : '1=1';
    $sql = "SELECT DISTINCT s.id, s.student_id, s.full_name, s.batch_id,
                   b.name AS batch_name, s.shift, s.section
              FROM ac_admit_cards ac
              JOIN ac_admit_card_courses cc ON cc.admit_card_id = ac.id
              JOIN students s ON s.dept_id = ac.dept_id
                             AND s.program_id = ac.program_id
                             AND (ac.batch_id IS NULL OR s.batch_id = ac.batch_id)
                             AND s.status = 'Active'
              LEFT JOIN student_batches b ON b.id = s.batch_id
             WHERE $card_where AND $legacy_cond AND cc.exam_date = ?";
    $params = array_merge($card_params, [$exam_date]);
    if ($section !== '') { $sql .= ' AND (cc.section = ? OR s.section = ?)'; $params[] = $section; $params[] = $section; }
    if ($shift !== '')   { $sql .= ' AND s.shift = ?'; $params[] = $shift; }
    if ($course_code !== '') { $sql .= ' AND cc.course_code = ?'; $params[] = $course_code; }
    try {
        $st = $db->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll() as $row) {
            $sid = (int)$row['id'];
            if (!isset($out[$sid])) $out[$sid] = $row;
        }
    } catch (Throwable $e) {}

    $out = array_values($out);
    usort($out, static fn($a, $b) => strcmp((string)$a['student_id'], (string)$b['student_id']));
    return $out;
}

/**
 * Assignments of a hall on a date, keyed "col:seat".
 */
function hm_assignments(int $hall_id, string $exam_date): array
{
    hm_ensure_assignments_table();
    try {
        $st = db()->prepare(
            "SELECT a.*, s.student_id AS student_code, s.full_name,
                    s.batch_id AS student_batch_id, b.name AS batch_name,
                    COALESCE(NULLIF(a.section, ''), NULLIF(s.section, ''), '') AS student_section
               FROM hm_hall_assignments a
               JOIN students s ON s.id = a.student_id
               LEFT JOIN student_batches b ON b.id = s.batch_id
              WHERE a.hall_id = ? AND a.exam_date = ?
              ORDER BY a.col_no ASC, a.seat_no ASC"
        );
        $st->execute([$hall_id, $exam_date]);
        $map = [];
        foreach ($st->fetchAll() as $r) $map[$r['col_no'] . ':' . $r['seat_no']] = $r;
        return $map;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Students already seated in ANY hall on the date (same shift, or any
 * shift when none given) — they are skipped on a new assignment run.
 */
function hm_busy_student_ids(string $exam_date, string $shift): array
{
    hm_ensure_assignments_table();
    try {
        $sql    = 'SELECT DISTINCT student_id FROM hm_hall_assignments WHERE exam_date = ?';
        $params = [$exam_date];
        if ($shift !== '') { $sql .= " AND (shift = ? OR shift IS NULL OR shift = '')"; $params[] = $shift; }
        $st = db()->prepare($sql);
        $st->execute($params);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Map of student_id => hall/seat details for students already seated on the
 * given exam date (optionally narrowed by shift). Used to link "Already
 * seated" rows straight to the room where each student sits.
 */
function hm_busy_student_halls(string $exam_date, string $shift = ''): array
{
    hm_ensure_assignments_table();
    try {
        $sql = "SELECT a.student_id, a.hall_id, a.col_no, a.seat_no, h.room_number
                  FROM hm_hall_assignments a
                  JOIN hm_halls h ON h.id = a.hall_id
                 WHERE a.exam_date = ?";
        $params = [$exam_date];
        if ($shift !== '') { $sql .= " AND (a.shift = ? OR a.shift IS NULL OR a.shift = '')"; $params[] = $shift; }
        $st = db()->prepare($sql);
        $st->execute($params);
        $map = [];
        foreach ($st->fetchAll() as $r) {
            $map[(int)$r['student_id']] = [
                'hall_id'     => (int)$r['hall_id'],
                'room_number' => (string)$r['room_number'],
                'col_no'      => (int)$r['col_no'],
                'seat_no'     => (int)$r['seat_no'],
            ];
        }
        return $map;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Auto-assign students to seats column by column (front to back), keeping
 * ONE batch per column and alternating batches between adjacent columns:
 * two neighbouring columns never hold the same batch. If only the
 * neighbouring column's batch remains, the column is left empty (the gap
 * lets the batch be seated again in the column after it).
 * Returns [assigned_count, skipped_already_seated, left_over].
 */
function hm_assign_students(int $hall_id, string $exam_date, array $students, array $ctx): array
{
    hm_ensure_assignments_table();
    $taken = hm_assignments($hall_id, $exam_date);
    $busy  = array_flip(hm_busy_student_ids($exam_date, (string)($ctx['shift'] ?? '')));

    // Group the waiting students by their batch
    $groups  = [];   // batch key => list of student rows
    $skipped = 0;
    foreach ($students as $stu) {
        $sid = (int)$stu['id'];
        if (isset($busy[$sid])) { $skipped++; continue; }
        $bk = (int)($stu['batch_id'] ?? 0);
        $groups[$bk][] = $stu;
    }

    // Free seats + batch already seated in each column (columns stay single-batch)
    $cols = [];      // col_no => ['free' => [seat_no, …], 'batch' => int|null]
    foreach (hm_hall_columns($hall_id) as $col) {
        $c    = (int)$col['col_no'];
        $free = [];
        $cb   = null;
        for ($s = 1; $s <= (int)$col['seat_capacity']; $s++) {
            $occ = $taken[$c . ':' . $s] ?? null;
            if ($occ === null) {
                $free[] = $s;
            } elseif ($cb === null) {
                $cb = (int)($occ['student_batch_id'] ?? $occ['batch_id'] ?? 0);
            }
        }
        $cols[$c] = ['free' => $free, 'batch' => $cb];
    }

    $ins = db()->prepare(
        'INSERT INTO hm_hall_assignments
            (hall_id, exam_date, student_id, col_no, seat_no,
             dept_id, program_id, batch_id, section, shift, assigned_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    );

    $assigned   = 0;
    $prev_batch = null; // batch of the previous (occupied) column
    foreach ($cols as $c => $info) {
        if (!$info['free']) {                 // column already full
            if ($info['batch'] !== null) $prev_batch = $info['batch'];
            continue;
        }

        // Pick the batch for this column
        if ($info['batch'] !== null) {
            $pick = $info['batch'];           // partially filled: keep its batch
            if (empty($groups[$pick])) { $prev_batch = $pick; continue; }
        } else {
            $pick = null;
            $best = -1;
            foreach ($groups as $bk => $list) {   // largest batch different from neighbour
                if (!$list || $bk === $prev_batch) continue;
                if (count($list) > $best) { $best = count($list); $pick = $bk; }
            }
            if ($pick === null) {
                // Only the neighbouring column's batch remains — leave this
                // column empty so adjacent columns never share a batch. The
                // empty column acts as a separator, so the next column may
                // seat that batch again.
                $has_left = false;
                foreach ($groups as $list) {
                    if ($list) { $has_left = true; break; }
                }
                if (!$has_left) break;            // no students left at all
                $prev_batch = null;               // empty column breaks adjacency
                continue;
            }
        }

        foreach ($info['free'] as $s) {
            if (empty($groups[$pick])) break;     // batch exhausted — leave rest of column empty
            $stu = array_shift($groups[$pick]);
            try {
                $ins->execute([
                    $hall_id, $exam_date, (int)$stu['id'], $c, $s,
                    ($ctx['dept_id'] ?? null) ?: null,
                    ($ctx['program_id'] ?? null) ?: null,
                    ((int)($stu['batch_id'] ?? 0)) ?: (($ctx['batch_id'] ?? null) ?: null),
                    ($ctx['section'] ?? '') !== '' ? $ctx['section'] : null,
                    ($ctx['shift'] ?? '') !== '' ? $ctx['shift'] : null,
                    auth_user()['id'] ?? null,
                ]);
                $assigned++;
            } catch (Throwable $e) {
                $skipped++; // duplicate seat/student race — ignore
            }
        }
        $prev_batch = $pick;
    }

    $left = 0;
    foreach ($groups as $list) $left += count($list);
    return [$assigned, $skipped, $left];
}

/**
 * Manually seat ONE student on a specific column/seat of a hall.
 *
 * Hard checks (never overridable): the seat exists and is free, the
 * student is active, dept-accessible and not already seated in THIS hall
 * on that date. Soft rule conflicts (mixed batches in a column, same
 * batch in an adjacent column, already seated in another hall) are
 * returned as warnings; passing $force = true seats the student anyway.
 * Returns [bool ok, string message, string[] warnings] — a false result
 * with non-empty warnings means user confirmation is required.
 */
function hm_assign_single_student(int $hall_id, string $exam_date, int $student_id, int $col_no, int $seat_no, array $ctx, bool $force = false): array
{
    hm_ensure_assignments_table();
    $db = db();

    // Seat must exist in this hall
    $cap = null;
    foreach (hm_hall_columns($hall_id) as $col) {
        if ((int)$col['col_no'] === $col_no) { $cap = (int)$col['seat_capacity']; break; }
    }
    if ($cap === null || $seat_no < 1 || $seat_no > $cap) {
        return [false, 'That seat does not exist in this hall.', []];
    }

    // Student must exist, be active and belong to an accessible department
    $st = $db->prepare("SELECT s.id, s.student_id, s.full_name, s.dept_id, s.batch_id, b.name AS batch_name
                          FROM students s LEFT JOIN student_batches b ON b.id = s.batch_id
                         WHERE s.id = ? AND s.status = 'Active'");
    $st->execute([$student_id]);
    $stu = $st->fetch();
    if (!$stu) return [false, 'Student not found or not active.', []];
    if (!can_access_dept((int)$stu['dept_id'])) {
        return [false, 'You do not have permission for that student\'s department.', []];
    }

    // Seat must be free (a taken seat can never be overridden)
    $taken = hm_assignments($hall_id, $exam_date);
    if (isset($taken[$col_no . ':' . $seat_no])) {
        return [false, 'Seat C' . $col_no . '-S' . $seat_no . ' is already occupied.', []];
    }

    // ── Rule conflicts: manual seating MAY override them after a confirm ──
    $stu_batch = (int)($stu['batch_id'] ?? 0);
    $stu_bname = (string)($stu['batch_name'] ?? '') !== '' ? (string)$stu['batch_name'] : 'No batch';
    $warnings  = [];

    // 1) Column should hold a single batch
    foreach ($taken as $key => $occ) {
        if ((int)explode(':', (string)$key)[0] !== $col_no) continue;
        if ((int)($occ['student_batch_id'] ?? 0) !== $stu_batch) {
            $obn = (string)($occ['batch_name'] ?? '') !== '' ? (string)$occ['batch_name'] : 'No batch';
            $warnings[] = 'Column ' . $col_no . ' already seats batch "' . $obn . '" — this student is from batch "'
                        . $stu_bname . '", so the column would mix batches.';
        }
        break;
    }

    // 2) Adjacent columns should hold a DIFFERENT batch (no same batch side by side)
    foreach ([$col_no - 1, $col_no + 1] as $adj) {
        if ($adj < 1) continue;
        foreach ($taken as $key => $occ) {
            if ((int)explode(':', (string)$key)[0] !== $adj) continue;
            if ((int)($occ['student_batch_id'] ?? 0) === $stu_batch) {
                $warnings[] = 'Adjacent Column ' . $adj . ' already seats batch "' . $stu_bname
                            . '" — same-batch students would sit side by side.';
            }
            break;
        }
    }

    // 3) Student should not already hold a seat on this date (same shift logic as auto-assign)
    $shift = (string)($ctx['shift'] ?? '');
    try {
        $sql    = 'SELECT a.hall_id, a.col_no, a.seat_no, hl.room_number
                     FROM hm_hall_assignments a JOIN hm_halls hl ON hl.id = a.hall_id
                    WHERE a.exam_date = ? AND a.student_id = ?';
        $params = [$exam_date, $student_id];
        if ($shift !== '') { $sql .= " AND (a.shift = ? OR a.shift IS NULL OR a.shift = '')"; $params[] = $shift; }
        $bq = $db->prepare($sql);
        $bq->execute($params);
        foreach ($bq->fetchAll() as $seated) {
            if ((int)$seated['hall_id'] === $hall_id) {
                return [false, h($stu['full_name']) . ' is already seated in this hall on this date (C'
                             . (int)$seated['col_no'] . '-S' . (int)$seated['seat_no'] . ').', []];
            }
            $warnings[] = 'Already seated in Room ' . $seated['room_number'] . ' (C' . (int)$seated['col_no']
                        . '-S' . (int)$seated['seat_no'] . ') on this date — the student would hold two seats.';
        }
    } catch (Throwable $e) {}

    if ($warnings && !$force) {
        return [false, '', $warnings];
    }

    try {
        $db->prepare(
            'INSERT INTO hm_hall_assignments
                (hall_id, exam_date, student_id, col_no, seat_no,
                 dept_id, program_id, batch_id, section, shift, assigned_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $hall_id, $exam_date, $student_id, $col_no, $seat_no,
            ($ctx['dept_id'] ?? null) ?: null,
            ($ctx['program_id'] ?? null) ?: null,
            $stu_batch ?: (($ctx['batch_id'] ?? null) ?: null),
            ($ctx['section'] ?? '') !== '' ? $ctx['section'] : null,
            ($ctx['shift'] ?? '') !== '' ? $ctx['shift'] : null,
            auth_user()['id'] ?? null,
        ]);
    } catch (Throwable $e) {
        return [false, 'Could not assign the seat — it may have just been taken.', []];
    }
    $msg = h($stu['full_name']) . ' (' . h($stu['student_id']) . ') seated at C' . $col_no . '-S' . $seat_no . '.';
    if ($force && $warnings) $msg .= ' (rule override confirmed)';
    return [true, $msg, []];
}

/**
 * Exam courses (code, title, teacher(s), time slot) of the students seated
 * in a hall on a date, grouped by the students' batch_id AND section.
 *
 * Two sections of the same batch may sit the same course under different
 * course teachers (different offer subjects), so courses/teachers are
 * resolved per batch+section group. Group keys are "batch_id|section"
 * (section may be an empty string).
 *
 * Subject-linked admit-card course rows resolve through the students'
 * course registrations (teachers from co_offer_subject_teachers); legacy
 * card rows without subject links fall back to cards matching the
 * student's dept / program / batch (no teacher information available).
 *
 * Returns: "batch_id|section" => list of
 *          ['course_code','course_title','time_slot','teachers','section','student_count','student_ids'].
 * student_count always equals count(student_ids) — the students actually
 * seated in this hall on the date who sit that course.
 */
function hm_exam_courses_by_group(int $hall_id, string $exam_date): array
{
    hm_ensure_assignments_table();
    $db  = db();
    $out = [];   // "batch_id|section" => dedupe-key => row

    $has_subject_col = false;
    try { $db->query('SELECT offer_subject_id FROM ac_admit_card_courses LIMIT 1'); $has_subject_col = true; } catch (Throwable $e) {}
    try { $db->query('SET SESSION group_concat_max_len = 1000000'); } catch (Throwable $e) {}

    $sec_expr = $has_subject_col
        ? "COALESCE(NULLIF(a.section, ''), NULLIF(s.section, ''), NULLIF(cc.section, ''), '')"
        : "COALESCE(NULLIF(a.section, ''), NULLIF(s.section, ''), '')";

    // ── Subject-linked cards: via the seated students' registrations ───
    if ($has_subject_col) {
        try {
            $st = $db->prepare(
                "SELECT s.batch_id, $sec_expr AS grp_section,
                        cc.course_code, cc.course_title, cc.time_slot,
                        cos.id AS offer_subject_id,
                        COUNT(DISTINCT a.student_id) AS student_count,
                        GROUP_CONCAT(DISTINCT a.student_id) AS student_ids
                   FROM hm_hall_assignments a
                   JOIN students s ON s.id = a.student_id
                   JOIN co_registrations r ON r.student_id = s.id
                   JOIN co_offer_subjects cos ON cos.id = r.offer_subject_id
                   JOIN ac_admit_card_courses cc ON cc.offer_subject_id = cos.id AND cc.exam_date = a.exam_date
                   JOIN ac_admit_cards ac ON ac.id = cc.admit_card_id AND ac.is_active = 1
                  WHERE a.hall_id = ? AND a.exam_date = ?
                  GROUP BY s.batch_id, grp_section, cc.course_code, cc.course_title, cc.time_slot, cos.id"
            );
            $st->execute([$hall_id, $exam_date]);
            $rows = $st->fetchAll();

            // Teacher names per offer subject
            $teachers = [];
            $osids    = array_values(array_unique(array_map(static fn($r) => (int)$r['offer_subject_id'], $rows)));
            if ($osids) {
                $ph = implode(',', array_fill(0, count($osids), '?'));
                $ts = $db->prepare(
                    "SELECT t.offer_subject_id,
                            GROUP_CONCAT(f.name ORDER BY t.sort_order ASC, f.name ASC SEPARATOR ', ') AS teacher_names
                       FROM co_offer_subject_teachers t
                       JOIN dept_faculty f ON f.id = t.faculty_id
                      WHERE t.offer_subject_id IN ($ph)
                      GROUP BY t.offer_subject_id"
                );
                $ts->execute($osids);
                foreach ($ts->fetchAll() as $t) $teachers[(int)$t['offer_subject_id']] = (string)$t['teacher_names'];
            }

            foreach ($rows as $r) {
                $sec = (string)($r['grp_section'] ?? '');
                $gk  = (int)($r['batch_id'] ?? 0) . '|' . $sec;
                $key = mb_strtolower($r['course_code'] . '|' . ($r['time_slot'] ?? ''));
                $tn  = $teachers[(int)$r['offer_subject_id']] ?? '';
                $ids = array_values(array_filter(array_map('intval', explode(',', (string)($r['student_ids'] ?? '')))));
                if (isset($out[$gk][$key])) {
                    // Same course offered via multiple offer subjects within
                    // the group — combine seated students and teacher names.
                    $out[$gk][$key]['student_ids']   = array_values(array_unique(array_merge($out[$gk][$key]['student_ids'], $ids)));
                    $out[$gk][$key]['student_count'] = count($out[$gk][$key]['student_ids']);
                    if ($tn !== '') {
                        $names = $out[$gk][$key]['teachers'] !== ''
                            ? array_map('trim', explode(',', $out[$gk][$key]['teachers']))
                            : [];
                        foreach (array_map('trim', explode(',', $tn)) as $n) {
                            if ($n !== '' && !in_array($n, $names, true)) $names[] = $n;
                        }
                        $out[$gk][$key]['teachers'] = implode(', ', $names);
                    }
                    continue;
                }
                $out[$gk][$key] = [
                    'course_code'   => (string)$r['course_code'],
                    'course_title'  => (string)$r['course_title'],
                    'time_slot'     => (string)($r['time_slot'] ?? ''),
                    'teachers'      => $tn,
                    'section'       => $sec,
                    'student_count' => count($ids),
                    'student_ids'   => $ids,
                ];
            }
        } catch (Throwable $e) {}
    }

    // ── Legacy / manual cards (no subject links) ────────────────────────
    $legacy_cond = $has_subject_col ? 'cc.offer_subject_id IS NULL' : '1=1';
    $legacy_sec  = "COALESCE(NULLIF(a.section, ''), NULLIF(s.section, ''), '')";
    try {
        $st = $db->prepare(
            "SELECT s.batch_id, $legacy_sec AS grp_section,
                    cc.course_code, cc.course_title, cc.time_slot,
                    COUNT(DISTINCT a.student_id) AS student_count,
                    GROUP_CONCAT(DISTINCT a.student_id) AS student_ids
               FROM hm_hall_assignments a
               JOIN students s ON s.id = a.student_id
               JOIN ac_admit_cards ac ON ac.is_active = 1
                                     AND ac.dept_id = s.dept_id
                                     AND ac.program_id = s.program_id
                                     AND (ac.batch_id IS NULL OR ac.batch_id = s.batch_id)
               JOIN ac_admit_card_courses cc ON cc.admit_card_id = ac.id
                                            AND cc.exam_date = a.exam_date
                                            AND $legacy_cond
              WHERE a.hall_id = ? AND a.exam_date = ?
              GROUP BY s.batch_id, grp_section, cc.course_code, cc.course_title, cc.time_slot"
        );
        $st->execute([$hall_id, $exam_date]);
        foreach ($st->fetchAll() as $r) {
            $sec = (string)($r['grp_section'] ?? '');
            $gk  = (int)($r['batch_id'] ?? 0) . '|' . $sec;
            $key = mb_strtolower($r['course_code'] . '|' . ($r['time_slot'] ?? ''));
            if (isset($out[$gk][$key])) continue;
            $ids = array_values(array_filter(array_map('intval', explode(',', (string)($r['student_ids'] ?? '')))));
            $out[$gk][$key] = [
                'course_code'   => (string)$r['course_code'],
                'course_title'  => (string)$r['course_title'],
                'time_slot'     => (string)($r['time_slot'] ?? ''),
                'teachers'      => '',
                'section'       => $sec,
                'student_count' => count($ids),
                'student_ids'   => $ids,
            ];
        }
    } catch (Throwable $e) {}

    hm_fill_missing_teachers_from_registrations($out);

    foreach ($out as $gk => $rows) $out[$gk] = array_values($rows);
    return $out;
}

/**
 * Fill empty 'teachers' on exam-schedule rows by matching the seated
 * students' course registrations on course code.
 *
 * Legacy admit-card course rows carry no offer-subject link, so the main
 * query cannot reach co_offer_subject_teachers even when the teacher is
 * assigned on the course offer. Here each teacher-less row is resolved
 * through its own students' registrations (co_registrations →
 * co_offer_subjects → course_curriculum) whose curriculum course code
 * matches the row's course code, and the assigned teacher names of those
 * offer subjects are combined (deduplicated).
 *
 * $out is the grouped structure built by hm_exam_courses_by_group():
 * "batch_id|section" => dedupe-key => row. Modified in place.
 */
function hm_fill_missing_teachers_from_registrations(array &$out): void
{
    $codes = [];   // lowercase course code => true
    $sids  = [];   // student id => true
    foreach ($out as $rows) {
        foreach ($rows as $r) {
            if (($r['teachers'] ?? '') !== '' || $r['course_code'] === '' || empty($r['student_ids'])) continue;
            $codes[mb_strtolower(trim((string)$r['course_code']))] = true;
            foreach ($r['student_ids'] as $sid) $sids[(int)$sid] = true;
        }
    }
    if (!$codes || !$sids) return;

    $codes = array_keys($codes);
    $sids  = array_keys($sids);

    // student_id => code_lc => offer_subject_ids, via registrations.
    $reg_os = [];
    $osids  = [];
    try {
        $cph = implode(',', array_fill(0, count($codes), '?'));
        $sph = implode(',', array_fill(0, count($sids), '?'));
        $st  = db()->prepare(
            "SELECT r.student_id, LOWER(TRIM(c.course_code)) AS code_lc, cos.id AS osid
               FROM co_registrations r
               JOIN co_offer_subjects cos ON cos.id = r.offer_subject_id
               JOIN course_curriculum c   ON c.id = cos.curriculum_id
              WHERE r.student_id IN ($sph) AND LOWER(TRIM(c.course_code)) IN ($cph)"
        );
        $st->execute(array_merge($sids, $codes));
        foreach ($st->fetchAll() as $r) {
            $osid = (int)$r['osid'];
            $reg_os[(int)$r['student_id']][(string)$r['code_lc']][$osid] = true;
            $osids[$osid] = true;
        }
    } catch (Throwable $e) {
        return;
    }
    if (!$osids) return;

    // Teacher names per offer subject.
    $teachers = [];
    try {
        $osids = array_keys($osids);
        $ph    = implode(',', array_fill(0, count($osids), '?'));
        $ts    = db()->prepare(
            "SELECT t.offer_subject_id,
                    GROUP_CONCAT(f.name ORDER BY t.sort_order ASC, f.name ASC SEPARATOR ', ') AS teacher_names
               FROM co_offer_subject_teachers t
               JOIN dept_faculty f ON f.id = t.faculty_id
              WHERE t.offer_subject_id IN ($ph)
              GROUP BY t.offer_subject_id"
        );
        $ts->execute($osids);
        foreach ($ts->fetchAll() as $t) $teachers[(int)$t['offer_subject_id']] = (string)$t['teacher_names'];
    } catch (Throwable $e) {
        return;
    }
    if (!$teachers) return;

    foreach ($out as $gk => $rows) {
        foreach ($rows as $key => $r) {
            if (($r['teachers'] ?? '') !== '' || $r['course_code'] === '' || empty($r['student_ids'])) continue;
            $code_lc = mb_strtolower(trim((string)$r['course_code']));
            $names   = [];
            foreach ($r['student_ids'] as $sid) {
                foreach (array_keys($reg_os[(int)$sid][$code_lc] ?? []) as $osid) {
                    $tn = $teachers[$osid] ?? '';
                    if ($tn === '') continue;
                    foreach (array_map('trim', explode(',', $tn)) as $n) {
                        if ($n !== '' && !in_array($n, $names, true)) $names[] = $n;
                    }
                }
            }
            if ($names) $out[$gk][$key]['teachers'] = implode(', ', $names);
        }
    }
}

/**
 * Exam courses grouped by batch only — batch+section groups from
 * hm_exam_courses_by_group() merged per batch; teachers of all sections
 * are combined (deduplicated) per course.
 *
 * Returns: batch_id => list of ['course_code','course_title','time_slot','teachers','student_count','student_ids'].
 */
function hm_exam_courses_by_batch(int $hall_id, string $exam_date): array
{
    $out = [];   // batch_id => dedupe-key => row
    foreach (hm_exam_courses_by_group($hall_id, $exam_date) as $gk => $rows) {
        $bk = (int)strtok((string)$gk, '|');
        foreach ($rows as $r) {
            $key = mb_strtolower($r['course_code'] . '|' . ($r['time_slot'] ?? ''));
            if (!isset($out[$bk][$key])) {
                unset($r['section']);
                $out[$bk][$key] = $r;
                continue;
            }
            $out[$bk][$key]['student_ids']   = array_values(array_unique(array_merge($out[$bk][$key]['student_ids'] ?? [], $r['student_ids'] ?? [])));
            $out[$bk][$key]['student_count'] = count($out[$bk][$key]['student_ids']);
            if ($r['teachers'] !== '') {
                $names = $out[$bk][$key]['teachers'] !== ''
                    ? array_map('trim', explode(',', $out[$bk][$key]['teachers']))
                    : [];
                foreach (array_map('trim', explode(',', $r['teachers'])) as $n) {
                    if ($n !== '' && !in_array($n, $names, true)) $names[] = $n;
                }
                $out[$bk][$key]['teachers'] = implode(', ', $names);
            }
        }
    }
    foreach ($out as $bk => $rows) $out[$bk] = array_values($rows);
    return $out;
}

/**
 * Colour palette used to distinguish batches in the seat layout.
 * Each entry: ['bg' => ..., 'border' => ..., 'text' => ...].
 */
function hm_batch_palette(): array
{
    return [
        ['bg' => '#dcfce7', 'border' => '#86efac', 'text' => '#166534'], // green
        ['bg' => '#dbeafe', 'border' => '#93c5fd', 'text' => '#1e40af'], // blue
        ['bg' => '#fef9c3', 'border' => '#fde047', 'text' => '#854d0e'], // yellow
        ['bg' => '#fce7f3', 'border' => '#f9a8d4', 'text' => '#9d174d'], // pink
        ['bg' => '#ffedd5', 'border' => '#fdba74', 'text' => '#9a3412'], // orange
        ['bg' => '#ede9fe', 'border' => '#c4b5fd', 'text' => '#5b21b6'], // violet
        ['bg' => '#ccfbf1', 'border' => '#5eead4', 'text' => '#115e59'], // teal
        ['bg' => '#fee2e2', 'border' => '#fca5a5', 'text' => '#991b1b'], // red
    ];
}

/**
 * Derive the exam time for a hall on a date from the seated students'
 * admit-card course time slots (e.g. "1:00 PM - 3:00 PM" → "13:00:00").
 * The earliest start time wins; returns NULL when no slot can be parsed.
 */
function hm_derive_exam_time(int $hall_id, string $exam_date): ?string
{
    $times = [];
    foreach (hm_exam_courses_by_batch($hall_id, $exam_date) as $rows) {
        foreach ($rows as $r) {
            $slot = trim((string)($r['time_slot'] ?? ''));
            if ($slot === '') continue;
            $parts = preg_split('/\s*(?:-|–|—|\bto\b)\s*/iu', $slot);
            $start = trim((string)($parts[0] ?? ''));
            $ts    = $start !== '' ? strtotime($start) : false;
            if ($ts !== false) $times[] = date('H:i:s', $ts);
        }
    }
    if (!$times) return null;
    sort($times);
    return $times[0];
}

/**
 * Keep hm_halls.exam_date / exam_time in sync with the hall's seat
 * assignments: the schedule follows the assignment date nearest to today
 * and the earliest admit-card time slot of the seated students. Called
 * after every assign / unassign action so the index list updates
 * automatically once seats are assigned.
 */
function hm_sync_hall_schedule(int $hall_id): void
{
    hm_ensure_schedule_columns();
    hm_ensure_assignments_table();
    try {
        $st = db()->prepare(
            'SELECT exam_date FROM hm_hall_assignments
              WHERE hall_id = ?
              GROUP BY exam_date
              ORDER BY ABS(DATEDIFF(exam_date, CURDATE())) ASC, exam_date DESC
              LIMIT 1'
        );
        $st->execute([$hall_id]);
        $date = (string)($st->fetchColumn() ?: '');
        if ($date === '') {
            db()->prepare('UPDATE hm_halls SET exam_date = NULL, exam_time = NULL WHERE id = ?')
                ->execute([$hall_id]);
            return;
        }
        $time = hm_derive_exam_time($hall_id, $date);
        db()->prepare('UPDATE hm_halls SET exam_date = ?, exam_time = ? WHERE id = ?')
            ->execute([$date, $time, $hall_id]);
    } catch (Throwable $e) {
        // uq_hm_room_slot conflict or missing tables — keep the previous schedule
    }
}

/** Exam dates that already have assignments in this hall. */
function hm_hall_assignment_dates(int $hall_id): array
{
    hm_ensure_assignments_table();
    try {
        $st = db()->prepare(
            'SELECT DISTINCT exam_date FROM hm_hall_assignments WHERE hall_id = ? ORDER BY exam_date DESC'
        );
        $st->execute([$hall_id]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Students who have an active admit-card exam on $exam_date but NO seat in
 * any hall on that date — the "unseated" report.
 *
 * Mirrors hm_find_exam_students() (subject-linked cards via co_registrations,
 * legacy cards via dept/program/batch students) but runs across every
 * accessible department/program and excludes students already present in
 * hm_hall_assignments for the date. Dept-scope aware.
 *
 * Optional filters: dept_id, program_id, batch_id, section, shift, time_slot.
 *
 * Returns rows: id, student_id, full_name, dept_name, program_name,
 * batch_name, section, shift, time_slot, exam_name, semester, exam_date.
 */
function hm_unseated_students(string $exam_date, array $filters = []): array
{
    hm_ensure_assignments_table();
    $db  = db();
    $out = [];

    $scope = get_dept_scope();
    if ($scope !== null && empty($scope)) return [];

    $dept_id    = (int)($filters['dept_id'] ?? 0);
    $program_id = (int)($filters['program_id'] ?? 0);
    $batch_id   = (int)($filters['batch_id'] ?? 0);
    $section    = trim((string)($filters['section'] ?? ''));
    $shift      = trim((string)($filters['shift'] ?? ''));
    $time_slot  = trim((string)($filters['time_slot'] ?? ''));

    $card_where  = 'ac.is_active = 1';
    $card_params = [];
    if ($dept_id > 0)    { $card_where .= ' AND ac.dept_id = ?';    $card_params[] = $dept_id; }
    if ($program_id > 0) { $card_where .= ' AND ac.program_id = ?'; $card_params[] = $program_id; }
    if ($batch_id > 0)   { $card_where .= ' AND ac.batch_id = ?';   $card_params[] = $batch_id; }
    if ($scope !== null) {
        $ph = implode(',', array_fill(0, count($scope), '?'));
        $card_where .= " AND ac.dept_id IN ($ph)";
        $card_params = array_merge($card_params, $scope);
    }

    $not_seated = 'NOT EXISTS (SELECT 1 FROM hm_hall_assignments a
                                WHERE a.exam_date = cc.exam_date AND a.student_id = s.id)';

    $has_subject_col = false;
    try { $db->query('SELECT offer_subject_id FROM ac_admit_card_courses LIMIT 1'); $has_subject_col = true; } catch (Throwable $e) {}

    // ── Subject-linked cards: students via their registrations ─────────
    if ($has_subject_col) {
        $sql = "SELECT DISTINCT s.id, s.student_id, s.full_name,
                       d.name AS dept_name, p.program_name,
                       b.name AS batch_name, o.shift, cc.section,
                       cc.time_slot, cc.exam_date, ac.exam_name, ac.semester
                  FROM ac_admit_cards ac
                  JOIN ac_admit_card_courses cc ON cc.admit_card_id = ac.id
                  JOIN co_offer_subjects cos ON cos.id = cc.offer_subject_id
                  JOIN co_offers o ON o.id = cos.offer_id
                  JOIN co_registrations r ON r.offer_subject_id = cos.id
                  JOIN students s ON s.id = r.student_id AND s.status = 'Active'
                  JOIN dept_departments d ON d.id = ac.dept_id
             LEFT JOIN dept_academic_programs p ON p.id = ac.program_id
             LEFT JOIN student_batches b ON b.id = s.batch_id
                 WHERE $card_where AND cc.exam_date = ? AND $not_seated";
        $params = array_merge($card_params, [$exam_date]);
        if ($section !== '')   { $sql .= ' AND cc.section = ?';   $params[] = $section; }
        if ($shift !== '')     { $sql .= ' AND o.shift = ?';      $params[] = $shift; }
        if ($time_slot !== '') { $sql .= ' AND cc.time_slot = ?'; $params[] = $time_slot; }
        try {
            $st = $db->prepare($sql);
            $st->execute($params);
            foreach ($st->fetchAll() as $row) $out[(int)$row['id']] = $row;
        } catch (Throwable $e) {}
    }

    // ── Legacy / manual cards (no subject links): dept+program+batch ───
    $legacy_cond = $has_subject_col
        ? 'NOT EXISTS (SELECT 1 FROM ac_admit_card_courses cx
                        WHERE cx.admit_card_id = ac.id AND cx.offer_subject_id IS NOT NULL)'
        : '1=1';
    $sql = "SELECT DISTINCT s.id, s.student_id, s.full_name,
                   d.name AS dept_name, p.program_name,
                   b.name AS batch_name, s.shift, s.section,
                   cc.time_slot, cc.exam_date, ac.exam_name, ac.semester
              FROM ac_admit_cards ac
              JOIN ac_admit_card_courses cc ON cc.admit_card_id = ac.id
              JOIN students s ON s.dept_id = ac.dept_id
                             AND s.program_id = ac.program_id
                             AND (ac.batch_id IS NULL OR s.batch_id = ac.batch_id)
                             AND s.status = 'Active'
              JOIN dept_departments d ON d.id = ac.dept_id
         LEFT JOIN dept_academic_programs p ON p.id = ac.program_id
         LEFT JOIN student_batches b ON b.id = s.batch_id
             WHERE $card_where AND $legacy_cond AND cc.exam_date = ? AND $not_seated";
    $params = array_merge($card_params, [$exam_date]);
    if ($section !== '')   { $sql .= ' AND (cc.section = ? OR s.section = ?)'; $params[] = $section; $params[] = $section; }
    if ($shift !== '')     { $sql .= ' AND s.shift = ?';      $params[] = $shift; }
    if ($time_slot !== '') { $sql .= ' AND cc.time_slot = ?'; $params[] = $time_slot; }
    try {
        $st = $db->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll() as $row) {
            $sid = (int)$row['id'];
            if (!isset($out[$sid])) $out[$sid] = $row;
        }
    } catch (Throwable $e) {}

    $out = array_values($out);
    usort($out, static function ($a, $b) {
        return [$a['dept_name'], (string)$a['program_name'], (string)$a['batch_name'], (string)$a['student_id']]
           <=> [$b['dept_name'], (string)$b['program_name'], (string)$b['batch_name'], (string)$b['student_id']];
    });
    return $out;
}

/** Distinct time-slot labels on admit-card exams of a given date (scope aware). */
function hm_time_slot_options(string $exam_date): array
{
    $scope = get_dept_scope();
    if ($scope !== null && empty($scope)) return [];
    $sql = "SELECT DISTINCT cc.time_slot
              FROM ac_admit_card_courses cc
              JOIN ac_admit_cards ac ON ac.id = cc.admit_card_id AND ac.is_active = 1
             WHERE cc.exam_date = ? AND cc.time_slot IS NOT NULL AND cc.time_slot <> ''";
    $params = [$exam_date];
    if ($scope !== null) {
        $ph  = implode(',', array_fill(0, count($scope), '?'));
        $sql .= " AND ac.dept_id IN ($ph)";
        $params = array_merge($params, $scope);
    }
    $sql .= ' ORDER BY cc.time_slot ASC';
    try {
        $st = db()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return [];
    }
}
