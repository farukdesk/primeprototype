<?php

function ei_get_departments(): array
{
    return db()->query(
        "SELECT d.id, d.name
         FROM dept_departments d
         WHERE EXISTS (
             SELECT 1 FROM ei_faculty f WHERE f.dept_id = d.id AND f.is_active = 1
         )
         ORDER BY d.name ASC"
    )->fetchAll();
}

/**
 * Check whether a faculty member is already assigned to another slot at the
 * same date + time_slot, optionally excluding $exclude_slot_id (for edits).
 */
function ei_faculty_has_overlap(int $faculty_id, string $slot_date, string $time_slot, ?int $exclude_slot_id = null): bool
{
    if ($faculty_id <= 0) return false;
    $sql = 'SELECT id FROM ei_slots
            WHERE slot_date = ? AND time_slot = ?
              AND (faculty1_id = ? OR faculty2_id = ?)';
    $params = [$slot_date, $time_slot, $faculty_id, $faculty_id];
    if ($exclude_slot_id !== null) {
        $sql .= ' AND id != ?';
        $params[] = $exclude_slot_id;
    }
    $sql .= ' LIMIT 1';
    $st = db()->prepare($sql);
    $st->execute($params);
    return (bool)$st->fetchColumn();
}

function ei_get_faculty_list(): array
{
    return db()->query(
        "SELECT f.*, d.name AS dept_name
         FROM ei_faculty f
         JOIN dept_departments d ON d.id = f.dept_id
         WHERE f.is_active = 1
         ORDER BY d.name ASC, f.name ASC"
    )->fetchAll();
}

function ei_format_faculty_option_label(array $faculty): string
{
    $label = trim((string)($faculty['name'] ?? ''));
    if (!empty($faculty['designation'])) {
        $label .= ' (' . trim((string)$faculty['designation']) . ')';
    }
    if (!empty($faculty['dept_name'])) {
        $label .= ' — ' . trim((string)$faculty['dept_name']);
    }
    return $label;
}

function ei_parse_time_value(string $value): ?DateTimeImmutable
{
    $value = trim($value);
    if ($value === '') return null;

    $formats = ['H:i', 'G:i', 'h:i A', 'g:i A', 'h:iA', 'g:iA', 'h:i a', 'g:i a', 'h:ia', 'g:ia'];
    foreach ($formats as $format) {
        $parsed = DateTimeImmutable::createFromFormat($format, $value);
        if ($parsed instanceof DateTimeImmutable && $parsed->format($format) === $value) {
            return $parsed;
        }
    }

    return null;
}

function ei_normalize_time_slot_range(string $start_time, string $end_time): ?string
{
    $start = ei_parse_time_value($start_time);
    $end   = ei_parse_time_value($end_time);
    if (!$start || !$end) return null;
    if ($end <= $start) return null;

    return $start->format('h:i A') . ' – ' . $end->format('h:i A');
}

function ei_parse_time_slot_range(string $time_slot): array
{
    $time_slot = trim($time_slot);
    if ($time_slot === '') return ['', ''];

    if (!preg_match('/^\s*(.+?)\s*[–-]\s*(.+?)\s*$/u', $time_slot, $matches)) {
        return ['', ''];
    }

    $start = ei_parse_time_value($matches[1]);
    $end   = ei_parse_time_value($matches[2]);
    if (!$start || !$end) return ['', ''];

    return [$start->format('H:i'), $end->format('H:i')];
}

function ei_normalize_slot_date(string $value): ?string
{
    $value = trim($value);
    if ($value === '') return null;

    $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'm-d-Y'];
    foreach ($formats as $format) {
        $parsed = DateTimeImmutable::createFromFormat($format, $value);
        if ($parsed instanceof DateTimeImmutable && $parsed->format($format) === $value) {
            return $parsed->format('Y-m-d');
        }
    }

    return null;
}

function ei_get_setting(string $key, ?string $default = null): ?string
{
    static $settings = null;

    if ($settings === null) {
        $settings = [];
        try {
            $rows = db()->query('SELECT setting_key, setting_val FROM ei_settings')->fetchAll();
            foreach ($rows as $row) {
                $settings[(string)$row['setting_key']] = $row['setting_val'];
            }
        } catch (Throwable $e) {
            // Fall back to hard-coded defaults so the module still works before the
            // settings migration is applied on older deployments.
        }
    }

    return array_key_exists($key, $settings) ? $settings[$key] : $default;
}

function ei_save_setting(string $key, string $value): void
{
    db()->prepare(
        'INSERT INTO ei_settings (setting_key, setting_val) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_val = ?'
    )->execute([$key, $value, $value]);
}

function ei_get_auto_assign_max_slots(): int
{
    $value = (int)ei_get_setting('auto_assign_max_slots', '12');
    if ($value < 1) {
        $value = 1;
    }
    return $value;
}

function ei_get_auto_assign_max_slots_per_day(): int
{
    $value = (int)ei_get_setting('auto_assign_max_slots_per_day', '3');
    if ($value < 1) {
        $value = 1;
    }
    return $value;
}

function ei_get_faculty_weekend_days(array $faculty): array
{
    if (!empty($faculty['weekend_days'])) {
        return array_values(array_filter(
            array_map('intval', explode(',', (string)$faculty['weekend_days'])),
            static fn ($day) => $day >= 0 && $day <= 6
        ));
    }

    return ((int)($faculty['weekend_available'] ?? 0) === 1) ? [] : [0, 6];
}

function ei_slot_starts_after_6pm(string $time_slot): bool
{
    if (!preg_match('/^\s*(.+?)\s*[–-]/u', $time_slot, $matches)) {
        return false;
    }

    $parsed_start = ei_parse_time_value(trim($matches[1]));
    return $parsed_start ? ((int)$parsed_start->format('H') >= 18) : false;
}

function ei_is_faculty_eligible_for_slot(array $faculty, array $slot, array $busy_map = []): bool
{
    $slot_date = (string)($slot['slot_date'] ?? '');
    $time_slot = (string)($slot['time_slot'] ?? '');
    $faculty_id = (int)($faculty['id'] ?? 0);

    if ($faculty_id <= 0 || $slot_date === '' || $time_slot === '') {
        return false;
    }

    $day_of_week = (int)date('w', strtotime($slot_date));
    if (in_array($day_of_week, ei_get_faculty_weekend_days($faculty), true)) {
        return false;
    }

    $busy_key = $slot_date . '|' . $time_slot;
    if (isset($busy_map[$busy_key][$faculty_id])) {
        return false;
    }

    if (ei_slot_starts_after_6pm($time_slot) && (($faculty['gender'] ?? '') === 'Female')) {
        return false;
    }

    return true;
}

// ── Version-control helpers ────────────────────────────────────────────────────

/**
 * Save a full snapshot of all current slot assignments for the given exam.
 *
 * This is called AFTER each operation that modifies assignments so that every
 * saved version reflects a real, usable state the user can revert to.
 *
 * @param  int    $exam_id
 * @param  string $change_type    One of: auto_assign, manual_edit, clear_slot, revert
 * @param  string $summary        Human-readable description of what was changed
 * @return int    The new snapshot ID
 */
function ei_save_assignment_snapshot(int $exam_id, string $change_type, string $summary): int
{
    $user      = auth_user();
    $user_id   = $user ? (int)$user['id'] : null;
    $user_name = $user ? trim((string)($user['full_name'] ?? '')) : '';
    if ($user_name === '') {
        $user_name = 'System';
    }

    // Next sequential version number for this exam
    $ver_st = db()->prepare(
        'SELECT COALESCE(MAX(version_number), 0) + 1 FROM ei_assignment_snapshots WHERE exam_id = ?'
    );
    $ver_st->execute([$exam_id]);
    $version_number = (int)$ver_st->fetchColumn();

    // Insert snapshot header
    $ins = db()->prepare(
        'INSERT INTO ei_assignment_snapshots
             (exam_id, version_number, change_type, change_summary, changed_by_id, changed_by_name, slots_count)
         VALUES (?, ?, ?, ?, ?, ?, 0)'
    );
    $ins->execute([$exam_id, $version_number, $change_type, $summary, $user_id, $user_name]);
    $snapshot_id = (int)db()->lastInsertId();

    // Copy current slot states into the snapshot
    $slots_st = db()->prepare(
        'SELECT id, faculty1_id, faculty2_id FROM ei_slots WHERE exam_id = ?'
    );
    $slots_st->execute([$exam_id]);
    $slot_rows = $slots_st->fetchAll();

    if (!empty($slot_rows)) {
        $ins_slot = db()->prepare(
            'INSERT INTO ei_assignment_snapshot_slots (snapshot_id, slot_id, faculty1_id, faculty2_id)
             VALUES (?, ?, ?, ?)'
        );
        foreach ($slot_rows as $row) {
            $ins_slot->execute([$snapshot_id, (int)$row['id'], $row['faculty1_id'], $row['faculty2_id']]);
        }
        db()->prepare('UPDATE ei_assignment_snapshots SET slots_count = ? WHERE id = ?')
            ->execute([count($slot_rows), $snapshot_id]);
    }

    return $snapshot_id;
}

/**
 * SQL CASE expression that ranks free-text designations so people lists can be
 * ordered by seniority within a department:
 * Dean → Head → Professor → Associate Professor → Assistant Professor →
 * Lecturer → Section Officer → MLSS → Cleaner.
 * Unknown designations sort between Section Officer and MLSS.
 */
function ei_designation_rank_sql(string $col = 'f.designation'): string
{
    $c = "LOWER(COALESCE($col, ''))";
    return "(CASE
        WHEN $c LIKE '%dean%' THEN 1
        WHEN $c LIKE '%head%' OR $c LIKE '%chairman%' OR $c LIKE '%chairperson%' THEN 2
        WHEN $c LIKE '%associate professor%' THEN 4
        WHEN $c LIKE '%assistant professor%' THEN 5
        WHEN $c LIKE '%professor%' THEN 3
        WHEN $c LIKE '%senior lecturer%' THEN 6
        WHEN $c LIKE '%lecturer%' THEN 7
        WHEN $c LIKE '%senior section officer%' THEN 8
        WHEN $c LIKE '%section officer%' THEN 9
        WHEN $c LIKE '%mlss%' OR $c LIKE '%peon%' THEN 30
        WHEN $c LIKE '%cleaner%' THEN 31
        ELSE 20
    END)";
}

// ── Remuneration helpers ───────────────────────────────────────────────────────

/**
 * SQL CASE expression that ranks free-text designations so people lists can be
 * ordered by seniority within a department (Professor → … → Lecturer →
 * officers → MLSS). Unknown designations sort before MLSS/support staff.
 */
function ei_designation_rank_sql(string $col = 'f.designation'): string
{
    $c = "LOWER(COALESCE($col, ''))";
    return "(CASE
        WHEN $c LIKE '%vice%chancellor%' THEN 1
        WHEN $c LIKE '%dean%' THEN 2
        WHEN $c LIKE '%associate professor%' THEN 4
        WHEN $c LIKE '%assistant professor%' THEN 5
        WHEN $c LIKE '%professor%' THEN 3
        WHEN $c LIKE '%senior lecturer%' THEN 6
        WHEN $c LIKE '%lecturer%' THEN 7
        WHEN $c LIKE '%additional controller%' THEN 9
        WHEN $c LIKE '%deputy controller%' THEN 10
        WHEN $c LIKE '%assistant controller%' THEN 11
        WHEN $c LIKE '%controller%' THEN 8
        WHEN $c LIKE '%additional registrar%' THEN 13
        WHEN $c LIKE '%deputy registrar%' THEN 14
        WHEN $c LIKE '%assistant registrar%' THEN 15
        WHEN $c LIKE '%registrar%' THEN 12
        WHEN $c LIKE '%deputy director%' THEN 17
        WHEN $c LIKE '%assistant director%' THEN 18
        WHEN $c LIKE '%director%' THEN 16
        WHEN $c LIKE '%administrative officer%' THEN 19
        WHEN $c LIKE '%senior section officer%' THEN 20
        WHEN $c LIKE '%section officer%' THEN 21
        WHEN $c LIKE '%senior officer%' THEN 22
        WHEN $c LIKE '%accounts officer%' THEN 23
        WHEN $c LIKE '%assistant officer%' THEN 25
        WHEN $c LIKE '%officer%' THEN 24
        WHEN $c LIKE '%senior assistant%' THEN 26
        WHEN $c LIKE '%computer operator%' THEN 27
        WHEN $c LIKE '%office assistant%' THEN 28
        WHEN $c LIKE '%lab%' THEN 29
        WHEN $c LIKE '%assistant%' THEN 30
        WHEN $c LIKE '%driver%' THEN 33
        WHEN $c LIKE '%mlss%' OR $c LIKE '%peon%' OR $c LIKE '%cleaner%' THEN 34
        ELSE 32
    END)";
}

/** Normalised key used to recognise the same person across departments/lists. */
function ei_norm_person_key(?string $name): string
{
    $name = preg_replace('/\s+/', ' ', trim((string)$name));
    return strtolower((string)$name);
}

/**
 * Keep only ONE payable row per person (matched by normalised name).
 * People already present in $paid_keys are dropped entirely; among duplicates
 * inside $rows the row with the highest total is kept, so a person appearing
 * in several departments is paid in one department only.
 */
function ei_dedupe_pay_rows(array $rows, string $name_key, array &$paid_keys, string $total_key = 'total_remuneration'): array
{
    $best = [];
    foreach ($rows as $i => $row) {
        $k = ei_norm_person_key($row[$name_key] ?? '');
        if ($k === '') { $best['#' . $i] = $i; continue; }
        if (isset($paid_keys[$k])) continue;
        if (!isset($best[$k]) || (float)($row[$total_key] ?? 0) > (float)($rows[$best[$k]][$total_key] ?? 0)) {
            $best[$k] = $i;
        }
    }
    $keep = array_flip($best);
    $out  = [];
    foreach ($rows as $i => $row) {
        if (isset($keep[$i])) $out[] = $row;
    }
    foreach ($best as $k => $i) {
        $k = (string)$k;
        if ($k !== '' && $k[0] !== '#') $paid_keys[$k] = true;
    }
    return $out;
}
