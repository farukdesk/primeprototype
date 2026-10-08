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
 * Students sitting an exam on $exam_date, found through generated (active)
 * admit cards matching dept / program (+ optional batch / section / shift).
 *
 * Cards whose course rows are linked to course-offer subjects resolve
 * students via co_registrations (shift from the offer); legacy cards
 * without subject links fall back to the card's dept/program/batch
 * students (shift / section from the students table).
 *
 * Returns rows: id, student_id, full_name, batch_name, shift, section.
 */
function hm_find_exam_students(int $dept_id, int $program_id, int $batch_id, string $exam_date, string $section, string $shift): array
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
        $sql = "SELECT DISTINCT s.id, s.student_id, s.full_name,
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
        if ($section !== '') { $sql .= ' AND cc.section = ?'; $params[] = $section; }
        if ($shift !== '')   { $sql .= ' AND o.shift = ?';    $params[] = $shift; }
        $st = $db->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll() as $row) $out[(int)$row['id']] = $row;
    }

    // ── Legacy / manual cards (no subject links): dept+program+batch ───
    $legacy_cond = $has_subject_col
        ? 'NOT EXISTS (SELECT 1 FROM ac_admit_card_courses cx
                        WHERE cx.admit_card_id = ac.id AND cx.offer_subject_id IS NOT NULL)'
        : '1=1';
    $sql = "SELECT DISTINCT s.id, s.student_id, s.full_name,
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
            'SELECT a.*, s.student_id AS student_code, s.full_name
               FROM hm_hall_assignments a
               JOIN students s ON s.id = a.student_id
              WHERE a.hall_id = ? AND a.exam_date = ?
              ORDER BY a.col_no ASC, a.seat_no ASC'
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
 * Auto-assign students to the first free seats (column by column, front
 * to back). Returns [assigned_count, skipped_already_seated, left_over].
 */
function hm_assign_students(int $hall_id, string $exam_date, array $students, array $ctx): array
{
    hm_ensure_assignments_table();
    $taken = hm_assignments($hall_id, $exam_date);
    $busy  = array_flip(hm_busy_student_ids($exam_date, (string)($ctx['shift'] ?? '')));

    // Free seats in order
    $free = [];
    foreach (hm_hall_columns($hall_id) as $col) {
        $c = (int)$col['col_no'];
        for ($s = 1; $s <= (int)$col['seat_capacity']; $s++) {
            if (!isset($taken[$c . ':' . $s])) $free[] = [$c, $s];
        }
    }

    $ins = db()->prepare(
        'INSERT INTO hm_hall_assignments
            (hall_id, exam_date, student_id, col_no, seat_no,
             dept_id, program_id, batch_id, section, shift, assigned_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    );

    $assigned = 0; $skipped = 0; $left = 0;
    foreach ($students as $stu) {
        $sid = (int)$stu['id'];
        if (isset($busy[$sid])) { $skipped++; continue; }
        if (!$free) { $left++; continue; }
        [$c, $s] = array_shift($free);
        try {
            $ins->execute([
                $hall_id, $exam_date, $sid, $c, $s,
                ($ctx['dept_id'] ?? null) ?: null,
                ($ctx['program_id'] ?? null) ?: null,
                ($ctx['batch_id'] ?? null) ?: null,
                ($ctx['section'] ?? '') !== '' ? $ctx['section'] : null,
                ($ctx['shift'] ?? '') !== '' ? $ctx['shift'] : null,
                auth_user()['id'] ?? null,
            ]);
            $assigned++;
        } catch (Throwable $e) {
            $skipped++; // duplicate seat/student race — ignore
        }
    }
    return [$assigned, $skipped, $left];
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
