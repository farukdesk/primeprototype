<?php
/**
 * GPA Scholarship module helpers.
 *
 * Uses Exam Invigilation exams (ei_exams) as setup/selection source and
 * evaluates GPA from result_grades/result_subjects for the selected exam id.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../student-accounts/helpers.php';

function gs_can_edit(): bool
{
    return is_super_admin() || can_access('gpa-scholarship', 'can_edit');
}

function gs_can_create(): bool
{
    return is_super_admin() || can_access('gpa-scholarship', 'can_create');
}

function gs_can_delete(): bool
{
    return is_super_admin() || can_access('gpa-scholarship', 'can_delete');
}

function gs_mode_options(): array
{
    return [
        'gpa_only' => 'GPA tier only',
        'gpa_then_attendance' => 'GPA tier first, attendance fallback when GPA tier does not match',
        'attendance_only' => 'Attendance tier only',
        'min' => 'Minimum of GPA/Attendance percentages',
        'max' => 'Maximum of GPA/Attendance percentages',
    ];
}

function gs_payment_filter_options(): array
{
    return [
        'merit_only' => 'Merit / Merit-based only',
        'any' => 'Any payment type',
    ];
}

function gs_attendance_missing_policy_options(): array
{
    return [
        'exclude' => 'Exclude when attendance data is missing',
        'ignore' => 'Ignore attendance when attendance data is missing',
    ];
}

function gs_departments(): array
{
    $rows = db()->query('SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
    return array_values(array_filter($rows, static fn($d) => can_access_dept((int)$d['id'])));
}

function gs_programs(int $dept_id): array
{
    if ($dept_id <= 0) return [];
    $st = db()->prepare('SELECT id, program_name FROM dept_academic_programs WHERE dept_id = ? AND is_active = 1 ORDER BY sort_order ASC, program_name ASC');
    $st->execute([$dept_id]);
    return $st->fetchAll();
}

function gs_batches(): array
{
    return db()->query('SELECT id, name FROM student_batches WHERE is_active = 1 ORDER BY sort_order ASC, name ASC')->fetchAll();
}

function gs_format_exam_title(array $row): string
{
    $name = trim((string)($row['exam_name'] ?? $row['exam_title'] ?? ''));
    $year = trim((string)($row['exam_year'] ?? ''));
    if ($name !== '' && $year !== '') return $name . ' (' . $year . ')';
    if ($name !== '') return $name;
    if ($year !== '') return $year;
    return 'Exam #' . (int)($row['exam_id'] ?? $row['id'] ?? 0);
}

function gs_exam_options(): array
{
    $rows = db()->query(
        'SELECT e.id, e.exam_name, e.exam_year, e.start_date, e.end_date, e.is_active
         FROM ei_exams e
         ORDER BY e.exam_year DESC, e.start_date DESC, e.id DESC'
    )->fetchAll();

    $legacy_map = [];
    try {
        $legacy_rows = db()->query(
            'SELECT e.id, e.dept_id, e.program_id, e.batch, e.completion_semester, e.exam_title,
                    d.name AS dept_name, p.program_name
             FROM result_exams e
             LEFT JOIN dept_departments d ON d.id = e.dept_id
             LEFT JOIN dept_academic_programs p ON p.id = e.program_id'
        )->fetchAll();
        foreach ($legacy_rows as $lr) {
            $legacy_map[(int)$lr['id']] = $lr;
        }
    } catch (Throwable $e) {
        $legacy_map = [];
    }

    foreach ($rows as &$row) {
        $legacy = $legacy_map[(int)$row['id']] ?? null;
        $row['dept_id'] = $legacy['dept_id'] ?? null;
        $row['program_id'] = $legacy['program_id'] ?? null;
        $row['batch'] = $legacy['batch'] ?? null;
        $row['completion_semester'] = $legacy['completion_semester'] ?? null;
        $row['dept_name'] = $legacy['dept_name'] ?? null;
        $row['program_name'] = $legacy['program_name'] ?? null;
        $row['legacy_exam_title'] = $legacy['exam_title'] ?? null;
        $row['exam_title'] = gs_format_exam_title($row);
    }
    unset($row);
    return $rows;
}

function gs_get_exam(int $id): ?array
{
    $st = db()->prepare(
        'SELECT e.id, e.exam_name, e.exam_year, e.start_date, e.end_date, e.is_active
         FROM ei_exams e
         WHERE e.id = ? LIMIT 1'
    );
    $st->execute([$id]);
    $exam = $st->fetch() ?: null;
    if ($exam) {
        try {
            $legacy = db()->prepare(
                'SELECT e.dept_id, e.program_id, e.batch, e.completion_semester, e.exam_title,
                        d.name AS dept_name, p.program_name
                 FROM result_exams e
                 LEFT JOIN dept_departments d ON d.id = e.dept_id
                 LEFT JOIN dept_academic_programs p ON p.id = e.program_id
                 WHERE e.id = ? LIMIT 1'
            );
            $legacy->execute([$id]);
            $legacy_row = $legacy->fetch() ?: null;
            if ($legacy_row) {
                $exam = array_merge($exam, $legacy_row);
                $exam['legacy_exam_title'] = $legacy_row['exam_title'] ?? null;
            }
        } catch (Throwable $e) {
            // result_exams may be unavailable in some deployments.
        }
        $exam['exam_title'] = gs_format_exam_title($exam);
        return $exam;
    }

    // Backward compatibility for old configs that still point to result_exams.
    $legacy = db()->prepare(
        'SELECT e.id, e.exam_title, e.batch, e.completion_semester, e.dept_id, e.program_id,
                d.name AS dept_name, p.program_name
         FROM result_exams e
         LEFT JOIN dept_departments d ON d.id = e.dept_id
         LEFT JOIN dept_academic_programs p ON p.id = e.program_id
         WHERE e.id = ? LIMIT 1'
    );
    $legacy->execute([$id]);
    $row = $legacy->fetch() ?: null;
    if ($row) {
        $row['exam_name'] = (string)$row['exam_title'];
        $row['exam_year'] = null;
        $row['is_active'] = 1;
        $row['exam_title'] = gs_format_exam_title($row);
    }
    return $row;
}

function gs_get_config(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM gpa_scholarship_configs WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function gs_get_tiers(int $config_id, string $metric): array
{
    $st = db()->prepare('SELECT * FROM gpa_scholarship_tiers WHERE config_id = ? AND metric = ? ORDER BY priority ASC, min_value DESC, id ASC');
    $st->execute([$config_id, $metric]);
    return $st->fetchAll();
}

function gs_get_config_with_relations(int $id): ?array
{
    $st = db()->prepare(
        'SELECT c.*, d.name AS dept_name, p.program_name, b.name AS batch_name,
                NULL AS exam_name, NULL AS exam_year, NULL AS exam_is_active,
                NULL AS legacy_exam_title, NULL AS completion_semester
         FROM gpa_scholarship_configs c
         LEFT JOIN dept_departments d ON d.id = c.dept_id
         LEFT JOIN dept_academic_programs p ON p.id = c.program_id
         LEFT JOIN student_batches b ON b.id = c.batch_id
         WHERE c.id = ? LIMIT 1'
    );
    $st->execute([$id]);
    $row = $st->fetch() ?: null;
    if ($row) {
        $exam = gs_get_exam((int)$row['exam_id']);
        if ($exam) {
            $row['exam_name'] = $exam['exam_name'] ?? null;
            $row['exam_year'] = $exam['exam_year'] ?? null;
            $row['exam_is_active'] = $exam['is_active'] ?? null;
            $row['legacy_exam_title'] = $exam['legacy_exam_title'] ?? ($exam['exam_title'] ?? null);
            $row['completion_semester'] = $exam['completion_semester'] ?? null;
        }
        $row['exam_title'] = gs_format_exam_title($row);
    }
    return $row;
}

function gs_validate_tiers(array $tiers, string $metric, array &$errors): array
{
    $valid = [];
    foreach ($tiers as $idx => $tier) {
        $label = trim((string)($tier['label'] ?? ''));
        $min = (float)($tier['min_value'] ?? 0);
        $max = (float)($tier['max_value'] ?? 0);
        $pct = (float)($tier['scholarship_percent'] ?? 0);
        $priority = (int)($tier['priority'] ?? ($idx + 1));

        if ($max < $min) {
            $errors[] = strtoupper($metric) . ' tier #' . ($idx + 1) . ': max must be >= min.';
            continue;
        }
        if ($pct < 0 || $pct > 100) {
            $errors[] = strtoupper($metric) . ' tier #' . ($idx + 1) . ': scholarship percent must be between 0 and 100.';
            continue;
        }
        if ($metric === 'gpa' && ($max > 4.00 || $min > 4.00 || $min < 0 || $max < 0)) {
            $errors[] = 'GPA tier #' . ($idx + 1) . ': min/max must be between 0.00 and 4.00.';
            continue;
        }
        if ($metric === 'attendance' && ($max > 100 || $min > 100 || $min < 0 || $max < 0)) {
            $errors[] = 'Attendance tier #' . ($idx + 1) . ': min/max must be between 0 and 100.';
            continue;
        }

        $valid[] = [
            'label' => $label,
            'min_value' => $min,
            'max_value' => $max,
            'scholarship_percent' => $pct,
            'priority' => max(1, $priority),
        ];
    }

    usort($valid, static fn($a, $b) => ($a['priority'] <=> $b['priority']) ?: ($b['min_value'] <=> $a['min_value']));

    for ($i = 0; $i < count($valid); $i++) {
        for ($j = $i + 1; $j < count($valid); $j++) {
            $a = $valid[$i];
            $b = $valid[$j];
            $overlap = !($a['max_value'] < $b['min_value'] || $b['max_value'] < $a['min_value']);
            if ($overlap && $a['priority'] === $b['priority']) {
                $errors[] = strtoupper($metric) . ' tiers overlap with same priority (' . ($a['label'] ?: $a['min_value'] . '-' . $a['max_value']) . ' and ' . ($b['label'] ?: $b['min_value'] . '-' . $b['max_value']) . ').';
            }
        }
    }

    return $valid;
}

function gs_match_tier(float $value, array $tiers): ?array
{
    foreach ($tiers as $tier) {
        if ($value >= (float)$tier['min_value'] && $value <= (float)$tier['max_value']) {
            return $tier;
        }
    }
    return null;
}

function gs_payment_type_column_exists(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    $ok = (bool)db()->query("SHOW COLUMNS FROM sfp_packages LIKE 'payment_type'")->fetch();
    return $ok;
}

function gs_calculate_final_percent(array $cfg, ?array $gpa_tier, ?array $att_tier): ?float
{
    $mode = (string)$cfg['combine_mode'];
    $g = $gpa_tier ? (float)$gpa_tier['scholarship_percent'] : null;
    $a = $att_tier ? (float)$att_tier['scholarship_percent'] : null;

    $vals = array_values(array_filter([$g, $a], static fn($v) => $v !== null));
    if (empty($vals)) return null;

    if ($mode === 'gpa_only') return $g;
    if ($mode === 'gpa_then_attendance') return $g !== null ? $g : $a;
    if ($mode === 'attendance_only') return $a;
    if ($mode === 'min') return min($vals);
    if ($mode === 'max') return max($vals);

    return $g;
}

function gs_source_key(int $config_id, int $exam_id, int $student_id, int $target_semester_number): string
{
    return 'gpa-scholarship:' . $config_id . ':' . $exam_id . ':' . $student_id . ':' . $target_semester_number;
}

function gs_evaluate_config(array $cfg): array
{
    $db = db();
    $exam = gs_get_exam((int)$cfg['exam_id']);
    if (!$exam) {
        return ['rows' => [], 'meta' => ['error' => 'Selected exam is missing or inaccessible.']];
    }

    $gpa_tiers = gs_get_tiers((int)$cfg['id'], 'gpa');
    $att_tiers = gs_get_tiers((int)$cfg['id'], 'attendance');

    $where = ["s.status = 'Active'", 's.dept_id = ?'];
    $params = [(int)$cfg['dept_id']];

    if (!empty($cfg['program_id'])) {
        $where[] = 's.program_id = ?';
        $params[] = (int)$cfg['program_id'];
    }
    if (!empty($cfg['batch_id'])) {
        $where[] = 's.batch_id = ?';
        $params[] = (int)$cfg['batch_id'];
    }

    $students_sql = 'SELECT s.id AS student_pk, s.student_id, s.full_name, s.dept_id, s.program_id, s.batch_id,
                            p.id AS package_id, p.payment_type,
                            sf.id AS target_sf_id, sf.semester_number, sf.semester_label, sf.tuition_payable
                     FROM students s
                     LEFT JOIN sfp_packages p ON p.student_id = s.id
                     LEFT JOIN sfp_semester_fees sf ON sf.package_id = p.id AND sf.semester_number = ?
                     WHERE ' . implode(' AND ', $where) . '
                     ORDER BY s.student_id ASC';
    $student_params = array_merge([(int)$cfg['target_semester_number']], $params);
    $st = $db->prepare($students_sql);
    $st->execute($student_params);
    $students = $st->fetchAll();

    if (empty($students)) {
        return ['rows' => [], 'meta' => ['total' => 0, 'eligible' => 0, 'excluded' => 0, 'exam' => $exam]];
    }

    $student_ids = array_map(static fn($r) => (int)$r['student_pk'], $students);
    $ph = implode(',', array_fill(0, count($student_ids), '?'));

    // Keep GPA source on result_grades/result_subjects keyed by exam_id. If an
    // invigilation exam has no corresponding result rows yet, students remain
    // excluded with "No GPA found..." instead of silently mapping another exam.
    $gpa_sql = "SELECT rg.student_id,
                       ROUND(SUM(COALESCE(rg.grade_point,0) * CASE WHEN COALESCE(rs.credits,0) > 0 THEN rs.credits ELSE 1 END)
                           / NULLIF(SUM(CASE WHEN COALESCE(rs.credits,0) > 0 THEN rs.credits ELSE 1 END),0), 2) AS semester_gpa
                FROM result_grades rg
                JOIN result_subjects rs ON rs.id = rg.subject_id AND rs.exam_id = rg.exam_id
                WHERE rg.exam_id = ? AND rg.student_id IN ($ph)
                GROUP BY rg.student_id";
    $gpa_st = $db->prepare($gpa_sql);
    $gpa_st->execute(array_merge([(int)$cfg['exam_id']], $student_ids));
    $gpa_map = [];
    foreach ($gpa_st->fetchAll() as $row) {
        $gpa_map[(int)$row['student_id']] = $row['semester_gpa'] !== null ? (float)$row['semester_gpa'] : null;
    }

    $att_map = [];
    $attendance_sem = trim((string)($cfg['attendance_semester'] ?? ''));
    $att_where = ['o.dept_id = ?'];
    $att_params = [(int)$cfg['dept_id']];
    if (!empty($cfg['program_id'])) {
        $att_where[] = 'o.program_id = ?';
        $att_params[] = (int)$cfg['program_id'];
    }
    if (!empty($cfg['batch_id'])) {
        $att_where[] = 'o.batch_id = ?';
        $att_params[] = (int)$cfg['batch_id'];
    }
    if ($attendance_sem !== '') {
        $att_where[] = 'o.semester = ?';
        $att_params[] = $attendance_sem;
    }

    try {
        $att_sql = "SELECT r.student_id,
                           SUM(CASE WHEN ar.status IN ('present','late','excused') THEN 1 ELSE 0 END) AS present_like,
                           COUNT(*) AS total_marked
                    FROM co_offers o
                    JOIN co_offer_subjects cos ON cos.offer_id = o.id
                    JOIN co_registrations r ON r.offer_subject_id = cos.id
                    JOIN student_att_sessions sess ON sess.offer_subject_id = cos.id
                    JOIN student_att_records ar ON ar.session_id = sess.id AND ar.student_id = r.student_id
                    WHERE " . implode(' AND ', $att_where) . "
                      AND r.student_id IN ($ph)
                    GROUP BY r.student_id";
        $att_st = $db->prepare($att_sql);
        $att_st->execute(array_merge($att_params, $student_ids));
        foreach ($att_st->fetchAll() as $row) {
            $tot = (int)$row['total_marked'];
            $att_map[(int)$row['student_id']] = $tot > 0
                ? round(((int)$row['present_like'] * 100) / $tot, 2)
                : null;
        }
    } catch (Throwable $e) {
        $att_map = [];
    }

    $award_st = $db->prepare('SELECT * FROM gpa_scholarship_awards WHERE config_id = ? AND exam_id = ?');
    $award_st->execute([(int)$cfg['id'], (int)$cfg['exam_id']]);
    $existing_awards = [];
    foreach ($award_st->fetchAll() as $ar) {
        $existing_awards[(int)$ar['student_id']] = $ar;
    }

    $rows = [];
    $eligible = 0;
    $excluded = 0;
    $has_payment_col = gs_payment_type_column_exists();

    foreach ($students as $stu) {
        $sid = (int)$stu['student_pk'];
        $gpa = $gpa_map[$sid] ?? null;
        $attendance = array_key_exists($sid, $att_map) ? $att_map[$sid] : null;
        $gpa_tier = ($gpa !== null) ? gs_match_tier((float)$gpa, $gpa_tiers) : null;
        $att_tier = ($attendance !== null) ? gs_match_tier((float)$attendance, $att_tiers) : null;

        $reason = '';
        $eligible_row = true;

        if ((int)$cfg['require_merit_payment_type'] === 1) {
            if (!$has_payment_col) {
                $eligible_row = false;
                $reason = 'payment_type column missing in sfp_packages';
            } elseif (($stu['payment_type'] ?? 'merit') !== 'merit') {
                $eligible_row = false;
                $reason = 'Payment type is not merit';
            }
        }

        if ($eligible_row && empty($stu['package_id'])) {
            $eligible_row = false;
            $reason = 'Student has no fee package';
        }

        if ($eligible_row && empty($stu['target_sf_id'])) {
            $eligible_row = false;
            $reason = 'Target semester invoice row not found';
        }

        if ($eligible_row && $gpa === null) {
            $eligible_row = false;
            $reason = 'No GPA found for selected exam';
        }

        if ($eligible_row && (int)$cfg['require_gpa_tier'] === 1 && !$gpa_tier) {
            $eligible_row = false;
            $reason = 'No matching GPA tier';
        }

        $combine_mode = (string)$cfg['combine_mode'];
        $needs_att = ((int)$cfg['require_attendance_tier'] === 1)
            || in_array($combine_mode, ['attendance_only', 'min', 'max'], true)
            || ($combine_mode === 'gpa_then_attendance' && $gpa_tier === null);
        if ($eligible_row && $needs_att && empty($att_tiers)) {
            $eligible_row = false;
            $reason = 'Attendance tier setup missing';
        }

        if ($eligible_row && $needs_att && $attendance === null) {
            if ((string)$cfg['attendance_missing_policy'] === 'exclude') {
                $eligible_row = false;
                $reason = 'Attendance data unavailable';
            }
        }

        if ($eligible_row && (int)$cfg['require_attendance_tier'] === 1 && $att_tier === null) {
            $eligible_row = false;
            $reason = 'No matching attendance tier';
        }

        $final_pct = gs_calculate_final_percent($cfg, $gpa_tier, $att_tier);
        if ($eligible_row && ($final_pct === null || $final_pct <= 0)) {
            $eligible_row = false;
            $reason = 'No scholarship percentage resolved from rules';
        }

        $final_pct = $final_pct !== null ? min(100.0, max(0.0, round($final_pct, 2))) : null;
        $tuition = (float)($stu['tuition_payable'] ?? 0);
        $final_amount = ($final_pct !== null) ? round($tuition * $final_pct / 100, 2) : null;

        if ($eligible_row) $eligible++; else $excluded++;

        $rows[] = [
            'student_pk' => $sid,
            'student_id' => $stu['student_id'],
            'student_name' => $stu['full_name'],
            'dept_id' => (int)$stu['dept_id'],
            'program_id' => (int)$stu['program_id'],
            'batch_id' => (int)$stu['batch_id'],
            'package_id' => $stu['package_id'] ? (int)$stu['package_id'] : null,
            'payment_type' => $stu['payment_type'] ?? null,
            'target_sf_id' => $stu['target_sf_id'] ? (int)$stu['target_sf_id'] : null,
            'semester_number' => $stu['semester_number'] ? (int)$stu['semester_number'] : null,
            'semester_label' => $stu['semester_label'] ?? null,
            'tuition_payable' => $tuition,
            'semester_gpa' => $gpa,
            'attendance_pct' => $attendance,
            'gpa_tier' => $gpa_tier,
            'attendance_tier' => $att_tier,
            'final_percent' => $final_pct,
            'final_amount' => $final_amount,
            'eligible' => $eligible_row,
            'exclusion_reason' => $eligible_row ? '' : $reason,
            'existing_award' => $existing_awards[$sid] ?? null,
        ];
    }

    return [
        'rows' => $rows,
        'meta' => [
            'total' => count($rows),
            'eligible' => $eligible,
            'excluded' => $excluded,
            'exam' => $exam,
        ],
    ];
}

function gs_upsert_award_row(array $cfg, array $row, string $status, ?int $scholarship_row_id, ?string $error = null): void
{
    $db = db();
    $source_key = gs_source_key((int)$cfg['id'], (int)$cfg['exam_id'], (int)$row['student_pk'], (int)$cfg['target_semester_number']);
    $snapshot = [
        'combine_mode' => $cfg['combine_mode'],
        'require_gpa_tier' => (int)$cfg['require_gpa_tier'],
        'require_attendance_tier' => (int)$cfg['require_attendance_tier'],
        'attendance_missing_policy' => $cfg['attendance_missing_policy'],
        'gpa_tier' => $row['gpa_tier'] ? [
            'id' => (int)$row['gpa_tier']['id'],
            'label' => $row['gpa_tier']['label'],
            'percent' => (float)$row['gpa_tier']['scholarship_percent'],
        ] : null,
        'attendance_tier' => $row['attendance_tier'] ? [
            'id' => (int)$row['attendance_tier']['id'],
            'label' => $row['attendance_tier']['label'],
            'percent' => (float)$row['attendance_tier']['scholarship_percent'],
        ] : null,
    ];

    $sql = 'INSERT INTO gpa_scholarship_awards
            (config_id, exam_id, student_id, package_id, target_sf_id, target_semester_number,
             payment_type, semester_gpa, attendance_pct,
             matched_gpa_tier_id, matched_attendance_tier_id,
             final_percent, final_amount,
             status, exclusion_reason, source_key, scholarship_row_id,
             rule_snapshot_json, last_error, previewed_at, applied_at, updated_at)
            VALUES
            (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE
             package_id = VALUES(package_id),
             target_sf_id = VALUES(target_sf_id),
             payment_type = VALUES(payment_type),
             semester_gpa = VALUES(semester_gpa),
             attendance_pct = VALUES(attendance_pct),
             matched_gpa_tier_id = VALUES(matched_gpa_tier_id),
             matched_attendance_tier_id = VALUES(matched_attendance_tier_id),
             final_percent = VALUES(final_percent),
             final_amount = VALUES(final_amount),
             status = VALUES(status),
             exclusion_reason = VALUES(exclusion_reason),
             scholarship_row_id = VALUES(scholarship_row_id),
             rule_snapshot_json = VALUES(rule_snapshot_json),
             last_error = VALUES(last_error),
             previewed_at = VALUES(previewed_at),
             applied_at = VALUES(applied_at),
             updated_at = NOW()';

    $previewed_at = $status === 'previewed' ? date('Y-m-d H:i:s') : null;
    $applied_at = in_array($status, ['applied', 'reversed'], true) ? date('Y-m-d H:i:s') : null;

    $db->prepare($sql)->execute([
        (int)$cfg['id'],
        (int)$cfg['exam_id'],
        (int)$row['student_pk'],
        $row['package_id'],
        $row['target_sf_id'],
        (int)$cfg['target_semester_number'],
        $row['payment_type'],
        $row['semester_gpa'],
        $row['attendance_pct'],
        $row['gpa_tier']['id'] ?? null,
        $row['attendance_tier']['id'] ?? null,
        $row['final_percent'],
        $row['final_amount'],
        $status,
        $row['eligible'] ? null : $row['exclusion_reason'],
        $source_key,
        $scholarship_row_id,
        json_encode($snapshot, JSON_UNESCAPED_UNICODE),
        $error,
        $previewed_at,
        $applied_at,
    ]);
}

function gs_apply_evaluations(array $cfg, array $rows, int $user_id): array
{
    $db = db();
    $summary = ['applied' => 0, 'updated' => 0, 'reversed' => 0, 'skipped' => 0, 'failed' => 0];

    foreach ($rows as $row) {
        $scholarship_row_id = $row['existing_award']['scholarship_row_id'] ?? null;

        try {
            $db->beginTransaction();

            if ($row['eligible']) {
                $sf_id = (int)$row['target_sf_id'];
                $source_key = gs_source_key((int)$cfg['id'], (int)$cfg['exam_id'], (int)$row['student_pk'], (int)$cfg['target_semester_number']);
                $note = 'Module: GPA Scholarship | source_key=' . $source_key
                    . ' | GPA=' . ($row['semester_gpa'] === null ? 'n/a' : number_format((float)$row['semester_gpa'], 2))
                    . ' | Attendance=' . ($row['attendance_pct'] === null ? 'n/a' : number_format((float)$row['attendance_pct'], 2) . '%');

                $existing_sc = null;
                if ($scholarship_row_id) {
                    $chk = $db->prepare('SELECT * FROM sfp_semester_scholarships WHERE id = ? AND sf_id = ? LIMIT 1');
                    $chk->execute([(int)$scholarship_row_id, $sf_id]);
                    $existing_sc = $chk->fetch() ?: null;
                }
                if (!$existing_sc) {
                    $recover = $db->prepare('SELECT * FROM sfp_semester_scholarships WHERE sf_id = ? AND note LIKE ? ORDER BY id DESC LIMIT 1');
                    $recover->execute([$sf_id, '%' . $source_key . '%']);
                    $existing_sc = $recover->fetch() ?: null;
                }

                if ($existing_sc) {
                    $upd = $db->prepare('UPDATE sfp_semester_scholarships
                                         SET label = ?, discount_pct = ?, discount_type = "percentage",
                                             fixed_amount = NULL, note = ?, is_from_policy = 1,
                                             applies_to_fixed = 0, applies_to_english = 0
                                         WHERE id = ?');
                    $upd->execute(['GPA Scholarship', $row['final_percent'], $note, (int)$existing_sc['id']]);
                    $scholarship_row_id = (int)$existing_sc['id'];
                    $summary['updated']++;
                } else {
                    $amount = round(((float)$row['tuition_payable'] * (float)$row['final_percent']) / 100, 2);
                    $ins = $db->prepare('INSERT INTO sfp_semester_scholarships
                                         (sf_id, label, discount_pct, discount_type, fixed_amount, amount, note,
                                          is_from_policy, applies_to_fixed, applies_to_english, support_doc_id, created_by)
                                         VALUES (?, ?, ?, "percentage", NULL, ?, ?, 1, 0, 0, NULL, ?)');
                    $ins->execute([$sf_id, 'GPA Scholarship', $row['final_percent'], $amount, $note, $user_id]);
                    $scholarship_row_id = (int)$db->lastInsertId();
                    $summary['applied']++;
                }

                sfp_recalculate_semester($sf_id, $user_id);
                gs_upsert_award_row($cfg, $row, 'applied', $scholarship_row_id, null);
            } else {
                $existing_sc = null;
                if ($scholarship_row_id) {
                    $chk = $db->prepare('SELECT sf_id FROM sfp_semester_scholarships WHERE id = ? LIMIT 1');
                    $chk->execute([(int)$scholarship_row_id]);
                    $existing_sc = $chk->fetch() ?: null;
                }

                if ($existing_sc) {
                    $sf_id = (int)$existing_sc['sf_id'];
                    $db->prepare('DELETE FROM sfp_semester_scholarships WHERE id = ?')->execute([(int)$scholarship_row_id]);
                    sfp_recalculate_semester($sf_id, $user_id);
                    gs_upsert_award_row($cfg, $row, 'reversed', null, null);
                    $summary['reversed']++;
                } else {
                    gs_upsert_award_row($cfg, $row, 'skipped', null, null);
                    $summary['skipped']++;
                }
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            gs_upsert_award_row($cfg, $row, 'failed', $scholarship_row_id ? (int)$scholarship_row_id : null, $e->getMessage());
            $summary['failed']++;
        }
    }

    return $summary;
}

function gs_save_preview_snapshot(array $cfg, array $rows): void
{
    foreach ($rows as $row) {
        $status = $row['eligible'] ? 'previewed' : 'skipped';
        gs_upsert_award_row($cfg, $row, $status, $row['existing_award']['scholarship_row_id'] ?? null, null);
    }
}
