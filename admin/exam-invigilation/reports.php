<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/slot-helpers.php';
require_access('exam-invigilation');

$page_title = 'Invigilation Reports';
const EI_REPORT_MAX_DESIGNATION_WORD_LENGTH = 12;

function ei_index_time_order_expr(string $column = 'time_slot'): string
{
    $allowed = ['time_slot', 's.time_slot', 'duty.time_slot'];
    if (!in_array($column, $allowed, true)) {
        $column = 'time_slot';
    }

    return "COALESCE(
        STR_TO_DATE(TRIM(SUBSTRING_INDEX(REPLACE({$column}, '-', '–'), '–', 1)), '%h:%i %p'),
        STR_TO_DATE(TRIM(SUBSTRING_INDEX(REPLACE({$column}, '-', '–'), '–', 1)), '%H:%i')
    )";
}

function ei_report_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function ei_report_format_date(string $value, string $format = 'd M Y'): string
{
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date ? $date->format($format) : $value;
}

function ei_report_filename_part(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $cleaned_value = preg_replace('/[^\pL\pN\s-]+/u', ' ', $value);
    if ($cleaned_value !== null) {
        $value = $cleaned_value;
    }

    $collapsed_value = preg_replace('/\s+/u', ' ', trim($value));
    if ($collapsed_value !== null) {
        $value = $collapsed_value;
    }

    return $value;
}

function ei_report_designation_short(string $designation): string
{
    $normalized = preg_replace('/[^a-z0-9]+/i', ' ', $designation);
    if ($normalized === null) {
        $normalized = '';
    }
    $normalized = strtolower(trim($normalized));
    if ($normalized === '') {
        return '';
    }

    $map = [
        'professor' => 'Prof',
        'associate professor' => 'AssocProf',
        'assistant professor' => 'AsstProf',
        'senior lecturer' => 'SrLect',
        'assistant lecturer' => 'AsstLect',
        'lecturer' => 'Lect',
        'adjunct professor' => 'AdjProf',
        'adjunct faculty' => 'AdjFaculty',
        'chairman' => 'Chairman',
        'dean' => 'Dean',
        'head' => 'Head',
        'coordinator' => 'Coord',
    ];

    if (isset($map[$normalized])) {
        return $map[$normalized];
    }

    $parts = preg_split('/\s+/', $normalized) ?: [];
    if (count($parts) === 1) {
        $short_word = function_exists('mb_substr')
            ? mb_substr($parts[0], 0, EI_REPORT_MAX_DESIGNATION_WORD_LENGTH, 'UTF-8')
            : substr($parts[0], 0, EI_REPORT_MAX_DESIGNATION_WORD_LENGTH);
        return ucfirst($short_word);
    }

    $short = '';
    foreach ($parts as $part) {
        if ($part !== '') {
            $short .= function_exists('mb_substr') && function_exists('mb_strtoupper')
                ? mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8')
                : strtoupper(substr($part, 0, 1));
        }
    }

    return $short;
}

/**
 * HTML block for the 2nd invigilator cell in the duty report PDFs:
 * name, designation, department, and contact number.
 */
function ei_report_partner_cell_html(array $row): string
{
    if (empty($row['partner_name'])) {
        return '<span style="color:#dc2626;">Not assigned</span>';
    }
    $html = '<span style="font-weight:700;">' . ei_report_escape((string)$row['partner_name']) . '</span>';
    if (!empty($row['partner_designation'])) {
        $html .= '<br><span style="color:#64748b;font-size:8pt;">' . ei_report_escape((string)$row['partner_designation']) . '</span>';
    }
    if (!empty($row['partner_dept_name'])) {
        $html .= '<br><span style="color:#2563eb;font-size:8pt;">' . ei_report_escape((string)$row['partner_dept_name']) . '</span>';
    }
    if (!empty($row['partner_contact'])) {
        $html .= '<br><span style="color:#0f172a;font-size:8pt;">Contact: ' . ei_report_escape((string)$row['partner_contact']) . '</span>';
    }
    return $html;
}

/**
 * HTML block for the Room cell in the duty report PDFs: room number,
 * the department whose exam runs in that room, and that department's
 * course coordinator (name + contact number).
 */
function ei_report_room_cell_html(array $row, array $dept_coordinators): string
{
    $html = '<span style="font-weight:700;">' . ei_report_escape((string)$row['room_number']) . '</span>';
    if (!empty($row['room_dept_name'])) {
        $html .= '<br><span style="color:#2563eb;font-size:8pt;">' . ei_report_escape((string)$row['room_dept_name']) . '</span>';
        $coordinator = $dept_coordinators[(int)($row['room_dept_id'] ?? 0)] ?? null;
        if ($coordinator) {
            $html .= '<br><span style="color:#64748b;font-size:8pt;">Coordinator: ' . ei_report_escape((string)$coordinator['name']) . '</span>';
            if (!empty($coordinator['contact_number'])) {
                $html .= '<br><span style="color:#0f172a;font-size:8pt;">' . ei_report_escape((string)$coordinator['contact_number']) . '</span>';
            }
        }
    }
    return $html;
}

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
    $vsr_time_order = ei_index_time_order_expr('s.time_slot');

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

// ── Faculty Duty Report ────────────────────────────────────────────────────────
$report_exam_id   = max(0, (int)($_GET['report_exam_id'] ?? 0));
$report_dept_id   = max(0, (int)($_GET['report_dept_id'] ?? 0));
$report_faculty_id = max(0, (int)($_GET['report_faculty_id'] ?? 0));
$report_date_from = trim((string)($_GET['report_date_from'] ?? ''));
$report_date_to   = trim((string)($_GET['report_date_to'] ?? ''));
$report_export    = trim((string)($_GET['report_export'] ?? ''));
$report_date_range_note = '';

if ($report_date_from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date_from)) {
    $report_date_from = '';
}
if ($report_date_to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date_to)) {
    $report_date_to = '';
}
if ($report_date_from !== '' && $report_date_to !== '' && $report_date_from > $report_date_to) {
    [$report_date_from, $report_date_to] = [$report_date_to, $report_date_from];
    $report_date_range_note = 'Report dates were reordered because the start date was later than the end date.';
}

$report_exam_options = db()->query(
    'SELECT id, exam_name, exam_year, is_active, start_date, end_date
     FROM ei_exams
     ORDER BY is_active DESC, exam_year DESC, exam_name ASC'
)->fetchAll();
$report_exam_map = [];
foreach ($report_exam_options as $report_exam_row) {
    $report_exam_map[(int)$report_exam_row['id']] = $report_exam_row;
}

$report_departments = db()->query(
    'SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
)->fetchAll();
$report_department_map = [];
foreach ($report_departments as $report_department_row) {
    $report_department_map[(int)$report_department_row['id']] = $report_department_row['name'];
}

$report_faculty_options = db()->query(
    'SELECT f.id, f.name, f.designation, d.name AS dept_name, d.code AS dept_code
     FROM ei_faculty f
     JOIN dept_departments d ON d.id = f.dept_id
     WHERE f.is_active = 1
     ORDER BY f.name ASC'
)->fetchAll();
$report_faculty_map = [];
foreach ($report_faculty_options as $report_faculty_row) {
    $report_faculty_map[(int)$report_faculty_row['id']] = $report_faculty_row;
}

$report_where = [];
$report_params = [];
if ($report_exam_id > 0) {
    $report_where[] = 'e.id = ?';
    $report_params[] = $report_exam_id;
} else {
    $report_where[] = 'e.is_active = 1';
}
if ($report_dept_id > 0) {
    $report_where[] = 'f.dept_id = ?';
    $report_params[] = $report_dept_id;
}
if ($report_faculty_id > 0) {
    $report_where[] = 'f.id = ?';
    $report_params[] = $report_faculty_id;
}
if ($report_date_from !== '') {
    $report_where[] = 's.slot_date >= ?';
    $report_params[] = $report_date_from;
}
if ($report_date_to !== '') {
    $report_where[] = 's.slot_date <= ?';
    $report_params[] = $report_date_to;
}

$report_sql_where = $report_where ? 'WHERE ' . implode(' AND ', $report_where) : '';
$report_time_order = ei_index_time_order_expr('duty.time_slot');
$report_query = "
    SELECT duty.*
    FROM (
        SELECT
            e.id AS exam_id,
            e.exam_name,
            e.exam_year,
            s.slot_date,
            s.time_slot,
            s.room_number,
            f.id AS faculty_id,
            f.name AS faculty_name,
            f.designation,
            d.name AS dept_name,
            d.code AS dept_code,
            p.name AS partner_name,
            p.designation AS partner_designation,
            p.contact_number AS partner_contact,
            pd.name AS partner_dept_name,
            s.dept_id AS room_dept_id,
            rd.name AS room_dept_name
        FROM ei_slots s
        JOIN ei_exams e ON e.id = s.exam_id
        JOIN ei_faculty f ON f.id = s.faculty1_id
        JOIN dept_departments d ON d.id = f.dept_id
        LEFT JOIN ei_faculty p ON p.id = s.faculty2_id
        LEFT JOIN dept_departments pd ON pd.id = p.dept_id
        LEFT JOIN dept_departments rd ON rd.id = s.dept_id
        {$report_sql_where}

        UNION ALL

        SELECT
            e.id AS exam_id,
            e.exam_name,
            e.exam_year,
            s.slot_date,
            s.time_slot,
            s.room_number,
            f.id AS faculty_id,
            f.name AS faculty_name,
            f.designation,
            d.name AS dept_name,
            d.code AS dept_code,
            p.name AS partner_name,
            p.designation AS partner_designation,
            p.contact_number AS partner_contact,
            pd.name AS partner_dept_name,
            s.dept_id AS room_dept_id,
            rd.name AS room_dept_name
        FROM ei_slots s
        JOIN ei_exams e ON e.id = s.exam_id
        JOIN ei_faculty f ON f.id = s.faculty2_id
        JOIN dept_departments d ON d.id = f.dept_id
        LEFT JOIN ei_faculty p ON p.id = s.faculty1_id
        LEFT JOIN dept_departments pd ON pd.id = p.dept_id
        LEFT JOIN dept_departments rd ON rd.id = s.dept_id
        {$report_sql_where}
    ) duty
    ORDER BY duty.faculty_name ASC,
             duty.slot_date ASC,
             {$report_time_order} ASC,
             duty.time_slot ASC,
             duty.room_number ASC
";
$report_st = db()->prepare($report_query);
$report_query_params = array_merge($report_params, $report_params); // Duplicate params because each UNION branch repeats the same filtered placeholders.
$report_st->execute($report_query_params);
$faculty_duty_rows = $report_st->fetchAll();

$report_total_rows = count($faculty_duty_rows);
$report_unique_faculty = [];
$report_unique_dates = [];
$report_unique_exams = [];
foreach ($faculty_duty_rows as $report_row) {
    $report_unique_faculty[(int)$report_row['faculty_id']] = true;
    $report_unique_dates[(string)$report_row['slot_date']] = true;
    $report_unique_exams[(int)$report_row['exam_id']] = true;
}
$report_faculty_count = count($report_unique_faculty);
$report_date_count = count($report_unique_dates);
$report_exam_count = count($report_unique_exams);

$report_scope_label = 'All Active Exams';
if ($report_exam_id > 0 && isset($report_exam_map[$report_exam_id])) {
    $report_scope_label = $report_exam_map[$report_exam_id]['exam_name'] . ' (' . $report_exam_map[$report_exam_id]['exam_year'] . ')';
}

$report_filter_parts = ['Exam: ' . $report_scope_label];
if ($report_dept_id > 0 && isset($report_department_map[$report_dept_id])) {
    $report_filter_parts[] = 'Department: ' . $report_department_map[$report_dept_id];
}
if ($report_faculty_id > 0 && isset($report_faculty_map[$report_faculty_id])) {
    $report_filter_parts[] = 'Faculty: ' . $report_faculty_map[$report_faculty_id]['name'];
}
if ($report_date_from !== '' && $report_date_to !== '') {
    $report_filter_parts[] = 'Duty Dates: ' . ei_report_format_date($report_date_from) . ' to ' . ei_report_format_date($report_date_to);
} elseif ($report_date_from !== '') {
    $report_filter_parts[] = 'Duty Dates: From ' . ei_report_format_date($report_date_from);
} elseif ($report_date_to !== '') {
    $report_filter_parts[] = 'Duty Dates: Up to ' . ei_report_format_date($report_date_to);
}
$report_filter_summary = implode(' | ', $report_filter_parts);
$report_date_summary_label = 'Duty Date' . ($report_date_count === 1 ? '' : 's');
if ($report_exam_count > 1) {
    $report_date_summary_label .= ' • ' . $report_exam_count . ' exams';
}

$report_filter_query = [];
if ($report_exam_id > 0) $report_filter_query['report_exam_id'] = $report_exam_id;
if ($report_dept_id > 0) $report_filter_query['report_dept_id'] = $report_dept_id;
if ($report_faculty_id > 0) $report_filter_query['report_faculty_id'] = $report_faculty_id;
if ($report_date_from !== '') $report_filter_query['report_date_from'] = $report_date_from;
if ($report_date_to !== '') $report_filter_query['report_date_to'] = $report_date_to;
$report_pdf_url = APP_URL . '/exam-invigilation/reports.php?' . http_build_query(array_merge($report_filter_query, ['report_export' => 'pdf']));

// Course coordinator per department: faculty whose designation contains
// "coordinator". Inactive coordinators are included too (their name and
// contact still print in the Room cell), but active ones are preferred
// when a department has more than one.
$dept_coordinators = [];
foreach (db()->query(
    "SELECT f.dept_id, f.name, f.designation, f.contact_number
     FROM ei_faculty f
     WHERE LOWER(COALESCE(f.designation, '')) LIKE '%coordinator%'
     ORDER BY f.dept_id ASC, f.is_active DESC, f.name ASC"
)->fetchAll() as $coordinator_row) {
    $coordinator_dept = (int)$coordinator_row['dept_id'];
    if (!isset($dept_coordinators[$coordinator_dept])) {
        $dept_coordinators[$coordinator_dept] = $coordinator_row;
    }
}

$report_pdf_all_query = $report_filter_query;
unset($report_pdf_all_query['report_faculty_id']);
$report_pdf_all_url = APP_URL . '/exam-invigilation/reports.php?' . http_build_query(array_merge($report_pdf_all_query, ['report_export' => 'pdf_all']));

// ── Export ALL faculty individual duty reports (one PDF per faculty) ────────
if ($report_export === 'pdf_all') {
    require_once __DIR__ . '/../../vendor/autoload.php';

    $generated_at_label = date('d M Y, h:i A');
    $logo_path = dirname(dirname(__DIR__)) . '/assets/img/logo/logo-black.png';
    $logo_data_uri = '';
    if (is_file($logo_path) && is_readable($logo_path)) {
        $logo_binary = file_get_contents($logo_path);
        if ($logo_binary !== false) {
            $logo_data_uri = 'data:image/png;base64,' . base64_encode($logo_binary);
        }
    }
    $logo_html = $logo_data_uri !== ''
        ? '<img src="' . $logo_data_uri . '" alt="Prime University" style="height:56px;width:auto;">'
        : '';

    // Group the filtered duty rows by faculty (faculty filter is ignored for "export all")
    $duties_by_faculty = [];
    foreach ($faculty_duty_rows as $duty_row) {
        $duties_by_faculty[(int)$duty_row['faculty_id']][] = $duty_row;
    }

    if (empty($duties_by_faculty)) {
        flash_set('warning', 'No faculty duties found for the selected report filters.');
        redirect(APP_URL . '/exam-invigilation/reports.php?' . http_build_query($report_pdf_all_query));
    }

    // Builds the white report card for one faculty (same layout as the single export)
    $build_faculty_card = static function (array $rows) use ($logo_html, $generated_at_label, $report_scope_label, $dept_coordinators): string {
        $first = $rows[0];
        $cell  = 'padding:10px 12px;border:1px solid #d7deea;color:#0f172a;vertical-align:top;';

        $rows_html = '';
        $total = count($rows);
        $i = 0;
        while ($i < $total) {
            $slot_date = (string)$rows[$i]['slot_date'];
            $run_start = $i;
            while ($i < $total && (string)$rows[$i]['slot_date'] === $slot_date) {
                $i++;
            }
            $rowspan = $i - $run_start;
            for ($j = $run_start; $j < $i; $j++) {
                $r = $rows[$j];
                $rows_html .= '<tr>';
                if ($j === $run_start) {
                    $rows_html .= '<td rowspan="' . $rowspan . '" style="padding:10px 12px;border:1px solid #d7deea;vertical-align:middle;font-weight:700;color:#0f172a;background:#f8fafc;">'
                        . ei_report_escape(ei_report_format_date((string)$r['slot_date'], 'd F Y'))
                        . '</td>';
                }
                $rows_html .= '<td style="' . $cell . '">' . ei_report_escape((string)$r['time_slot']) . '</td>'
                    . '<td style="' . $cell . '">' . ei_report_room_cell_html($r, $dept_coordinators) . '</td>'
                    . '<td style="' . $cell . '">' . ei_report_partner_cell_html($r) . '</td>'
                    . '</tr>';
            }
        }

        $designation = trim((string)($first['designation'] ?? '')) ?: '—';

        return '<div style="background:#ffffff;border:1px solid #d7deea;border-radius:16px;overflow:hidden;">'
            . '<div style="padding:18px 24px 14px;border-bottom:1px solid #d7deea;">'
            . '<table style="width:100%;border-collapse:collapse;"><tr>'
            . '<td style="width:90px;vertical-align:middle;">' . $logo_html . '</td>'
            . '<td style="vertical-align:middle;text-align:center;">'
            . '<div style="font-size:16pt;font-weight:800;color:#0f172a;line-height:1.2;">Faculty Invigilation Duty Schedule</div>'
            . '</td>'
            . '<td style="width:90px;"></td>'
            . '</tr></table>'
            . '<table style="width:100%;border-collapse:collapse;margin-top:12px;font-size:9.5pt;">'
            . '<tr><td style="width:24%;padding:6px 0;color:#475569;font-weight:700;">Exam Title</td><td style="width:76%;padding:6px 0;color:#0f172a;">: ' . ei_report_escape($report_scope_label) . '</td></tr>'
            . '<tr><td style="padding:6px 0;color:#475569;font-weight:700;">Department Name</td><td style="padding:6px 0;color:#0f172a;">: ' . ei_report_escape((string)$first['dept_name']) . '</td></tr>'
            . '<tr><td style="padding:6px 0;color:#475569;font-weight:700;">Faculty Name</td><td style="padding:6px 0;color:#0f172a;">: ' . ei_report_escape((string)$first['faculty_name']) . '</td></tr>'
            . '<tr><td style="padding:6px 0;color:#475569;font-weight:700;">Faculty Designation</td><td style="padding:6px 0;color:#0f172a;">: ' . ei_report_escape($designation) . '</td></tr>'
            . '<tr><td style="padding:6px 0;color:#475569;font-weight:700;">Assigned Slots</td><td style="padding:6px 0;color:#0f172a;">: ' . $total . '</td></tr>'
            . '</table>'
            . '</div>'
            . '<div style="padding:14px 24px 14px;">'
            . '<table style="width:100%;border-collapse:collapse;font-size:9pt;">'
            . '<thead><tr style="background:#0f172a;color:#ffffff;">'
            . '<th style="padding:9px 10px;border:1px solid #0f172a;text-align:left;width:17%;">Date</th>'
            . '<th style="padding:9px 10px;border:1px solid #0f172a;text-align:left;width:17%;">Duty Time</th>'
            . '<th style="padding:9px 10px;border:1px solid #0f172a;text-align:left;width:30%;">Room (Dept &amp; Coordinator)</th>'
            . '<th style="padding:9px 10px;border:1px solid #0f172a;text-align:left;">2nd Invigilator</th>'
            . '</tr></thead>'
            . '<tbody>' . $rows_html . '</tbody>'
            . '</table>'
            . '<div style="margin:14px 0 0;font-size:8.5pt;color:#64748b;text-align:center;">This is a software-generated schedule. If you have any issues, please contact the Controller of Examinations.</div>'
            . '<div style="margin:4px 0 0;font-size:8.5pt;color:#64748b;text-align:center;">Generated ' . ei_report_escape($generated_at_label) . '</div>'
            . '</div>'
            . '</div>';
    };

    $wrap_pdf_doc = static function (string $inner): string {
        return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Faculty Invigilation Duty Schedule</title></head>'
            . '<body style="font-family:DejaVu Sans, Arial, sans-serif;background:#eef3f8;margin:0;padding:18px;">'
            . $inner
            . '</body></html>';
    };

    // One card + one file per faculty, grouped into a folder per department:
    // "Department Name/Faculty name_Designation.pdf"
    $faculty_pdfs = [];
    $used_filenames = [];
    foreach ($duties_by_faculty as $fid => $rows) {
        $dept_folder = ei_report_filename_part((string)($rows[0]['dept_name'] ?? ''));
        if ($dept_folder === '') {
            $dept_folder = 'No Department';
        }
        $name_part  = ei_report_filename_part((string)$rows[0]['faculty_name']);
        $desig_part = ei_report_filename_part((string)($rows[0]['designation'] ?? ''));
        $filename = $name_part !== '' ? $name_part : ('Faculty-' . $fid);
        if ($desig_part !== '') {
            $filename .= '_' . $desig_part;
        }
        $filename_base = $filename;
        $dupe = 2;
        while (isset($used_filenames[$dept_folder][$filename])) {
            $filename = $filename_base . '_' . $dupe;
            $dupe++;
        }
        $used_filenames[$dept_folder][$filename] = true;
        $faculty_pdfs[$dept_folder . '/' . $filename . '.pdf'] = $build_faculty_card($rows);
    }
    // Alphabetical department folders (and faculty inside each folder)
    ksort($faculty_pdfs, SORT_NATURAL | SORT_FLAG_CASE);

    if (class_exists('ZipArchive')) {
        $zip_path = tempnam(sys_get_temp_dir(), 'eiduty');
        $zip = new ZipArchive();
        if ($zip_path === false || $zip->open($zip_path, ZipArchive::OVERWRITE) !== true) {
            flash_set('error', 'Could not create the ZIP archive on the server.');
            redirect(APP_URL . '/exam-invigilation/reports.php?' . http_build_query($report_pdf_all_query));
        }
        foreach ($faculty_pdfs as $pdf_name => $card_html) {
            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
            $dompdf->loadHtml($wrap_pdf_doc($card_html), 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            $zip->addFromString($pdf_name, $dompdf->output());
        }
        $zip->close();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="faculty-duty-reports-' . date('Ymd') . '.zip"');
        header('Content-Length: ' . (string)filesize($zip_path));
        readfile($zip_path);
        @unlink($zip_path);
        exit;
    }

    // Fallback without ZipArchive: one combined PDF, one faculty per page
    $combined_html = $wrap_pdf_doc(implode('<div style="page-break-before:always;"></div>', array_values($faculty_pdfs)));
    $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
    $dompdf->loadHtml($combined_html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream('faculty-duty-reports-' . date('Ymd') . '.pdf', ['Attachment' => true]);
    exit;
}

if ($report_export === 'pdf') {
    require_once __DIR__ . '/../../vendor/autoload.php';
    $generated_at_label = date('d M Y, h:i A');

    $logo_path = dirname(dirname(__DIR__)) . '/assets/img/logo/logo-black.png';
    $logo_data_uri = '';
    if (is_file($logo_path) && is_readable($logo_path)) {
        $logo_binary = file_get_contents($logo_path);
        if ($logo_binary !== false) {
            $logo_data_uri = 'data:image/png;base64,' . base64_encode($logo_binary);
        }
    }

    $report_header_exam = $report_scope_label;
    $report_header_department = 'All Departments';
    if ($report_dept_id > 0 && isset($report_department_map[$report_dept_id])) {
        $report_header_department = $report_department_map[$report_dept_id];
    } elseif ($report_total_rows > 0) {
        $report_header_department = (string)$faculty_duty_rows[0]['dept_name'];
    }

    $report_header_faculty = 'All Faculty';
    $report_header_designation = '—';
    $report_filename_faculty = '';
    $report_filename_dept_code = '';
    $report_filename_designation = '';
    if ($report_faculty_id > 0 && isset($report_faculty_map[$report_faculty_id])) {
        $report_header_faculty = (string)$report_faculty_map[$report_faculty_id]['name'];
        $report_header_designation = trim((string)($report_faculty_map[$report_faculty_id]['designation'] ?? '')) ?: '—';
        $report_filename_faculty = (string)$report_faculty_map[$report_faculty_id]['name'];
        $report_filename_dept_code = trim((string)($report_faculty_map[$report_faculty_id]['dept_code'] ?? ''));
        $report_filename_designation = trim((string)($report_faculty_map[$report_faculty_id]['designation'] ?? ''));
    } elseif ($report_total_rows > 0 && $report_faculty_count === 1) {
        $report_header_faculty = (string)$faculty_duty_rows[0]['faculty_name'];
        $report_header_designation = trim((string)($faculty_duty_rows[0]['designation'] ?? '')) ?: '—';
        $report_filename_faculty = (string)$faculty_duty_rows[0]['faculty_name'];
        $report_filename_dept_code = trim((string)($faculty_duty_rows[0]['dept_code'] ?? ''));
        $report_filename_designation = trim((string)($faculty_duty_rows[0]['designation'] ?? ''));
    } elseif ($report_faculty_count > 1) {
        $report_header_faculty = 'Multiple Faculty (' . $report_faculty_count . ')';
    }

    $report_rows_html = '';
    if (empty($faculty_duty_rows)) {
        $report_rows_html = '<tr><td colspan="4" style="padding:30px 14px;text-align:center;color:#6b7280;">No invigilation schedule found for the selected filters.</td></tr>';
    } else {
        $report_row_total = count($faculty_duty_rows);
        $row_index = 0;
        while ($row_index < $report_row_total) {
            $slot_date = (string)$faculty_duty_rows[$row_index]['slot_date'];
            $run_start = $row_index;
            while ($row_index < $report_row_total && (string)$faculty_duty_rows[$row_index]['slot_date'] === $slot_date) {
                $row_index++;
            }
            $rowspan = $row_index - $run_start;

            for ($i = $run_start; $i < $row_index; $i++) {
                $report_row = $faculty_duty_rows[$i];
                $report_rows_html .= '<tr>';
                if ($i === $run_start) {
                    $report_rows_html .= '<td rowspan="' . $rowspan . '" style="padding:10px 12px;border:1px solid #d7deea;vertical-align:middle;font-weight:700;color:#0f172a;background:#f8fafc;">'
                        . ei_report_escape(ei_report_format_date((string)$report_row['slot_date'], 'd F Y'))
                        . '</td>';
                }
                $report_rows_html .= '<td style="padding:10px 12px;border:1px solid #d7deea;color:#0f172a;">' . ei_report_escape((string)$report_row['time_slot']) . '</td>'
                    . '<td style="padding:10px 12px;border:1px solid #d7deea;color:#0f172a;vertical-align:top;">' . ei_report_room_cell_html($report_row, $dept_coordinators) . '</td>'
                    . '<td style="padding:10px 12px;border:1px solid #d7deea;color:#0f172a;vertical-align:top;">' . ei_report_partner_cell_html($report_row) . '</td>'
                    . '</tr>';
            }
        }
    }

    $logo_html = $logo_data_uri !== ''
        ? '<img src="' . $logo_data_uri . '" alt="Prime University" style="height:56px;width:auto;">'
        : '';

    $pdf_html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Faculty Invigilation Duty Schedule</title></head>'
        . '<body style="font-family:DejaVu Sans, Arial, sans-serif;background:#eef3f8;margin:0;padding:18px;">'
        . '<div style="background:#ffffff;border:1px solid #d7deea;border-radius:16px;overflow:hidden;">'
        . '<div style="padding:18px 24px 14px;border-bottom:1px solid #d7deea;">'
        . '<table style="width:100%;border-collapse:collapse;"><tr>'
        . '<td style="width:90px;vertical-align:middle;">' . $logo_html . '</td>'
        . '<td style="vertical-align:middle;text-align:center;">'
        . '<div style="font-size:16pt;font-weight:800;color:#0f172a;line-height:1.2;">Faculty Invigilation Duty Schedule</div>'
        . '</td>'
        . '<td style="width:90px;"></td>'
        . '</tr></table>'
        . '<table style="width:100%;border-collapse:collapse;margin-top:12px;font-size:9.5pt;">'
        . '<tr>'
            . '<td style="width:24%;padding:6px 0;color:#475569;font-weight:700;">Exam Title</td>'
            . '<td style="width:76%;padding:6px 0;color:#0f172a;">: ' . ei_report_escape($report_header_exam) . '</td>'
        . '</tr>'
        . '<tr>'
            . '<td style="padding:6px 0;color:#475569;font-weight:700;">Department Name</td>'
            . '<td style="padding:6px 0;color:#0f172a;">: ' . ei_report_escape($report_header_department) . '</td>'
        . '</tr>'
        . '<tr>'
            . '<td style="padding:6px 0;color:#475569;font-weight:700;">Faculty Name</td>'
            . '<td style="padding:6px 0;color:#0f172a;">: ' . ei_report_escape($report_header_faculty) . '</td>'
        . '</tr>'
        . '<tr>'
            . '<td style="padding:6px 0;color:#475569;font-weight:700;">Faculty Designation</td>'
            . '<td style="padding:6px 0;color:#0f172a;">: ' . ei_report_escape($report_header_designation) . '</td>'
        . '</tr>'
        . '<tr>'
            . '<td style="padding:6px 0;color:#475569;font-weight:700;">Assigned Slots</td>'
            . '<td style="padding:6px 0;color:#0f172a;">: ' . $report_total_rows . '</td>'
        . '</tr>'
        . '</table>'
        . '</div>'
        . '<div style="padding:14px 24px 14px;">'
        . '<table style="width:100%;border-collapse:collapse;font-size:9pt;">'
        . '<thead>'
        . '<tr style="background:#0f172a;color:#ffffff;">'
        . '<th style="padding:9px 10px;border:1px solid #0f172a;text-align:left;width:17%;">Date</th>'
        . '<th style="padding:9px 10px;border:1px solid #0f172a;text-align:left;width:17%;">Duty Time</th>'
        . '<th style="padding:9px 10px;border:1px solid #0f172a;text-align:left;width:30%;">Room (Dept &amp; Coordinator)</th>'
        . '<th style="padding:9px 10px;border:1px solid #0f172a;text-align:left;">2nd Invigilator</th>'
        . '</tr>'
        . '</thead>'
        . '<tbody>' . $report_rows_html . '</tbody>'
        . '</table>'
        . '<div style="margin:14px 0 0;font-size:8.5pt;color:#64748b;text-align:center;">This is a software-generated schedule. If you have any issues, please contact the Controller of Examinations.</div>'
        . '<div style="margin:4px 0 0;font-size:8.5pt;color:#64748b;text-align:center;">Generated ' . ei_report_escape($generated_at_label) . '</div>'
        . '</div>'
        . '</div>'
        . '</body></html>';

    $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
    $dompdf->loadHtml($pdf_html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $filename_parts = array_filter([
        ei_report_filename_part($report_filename_faculty),
        ei_report_filename_part($report_filename_dept_code),
        ei_report_filename_part(ei_report_designation_short($report_filename_designation)),
    ], fn($value): bool => $value !== '');

    if (!empty($filename_parts)) {
        $filename_suffix = implode('_', $filename_parts);
    } else {
        $filename_suffix = $report_exam_id > 0 && isset($report_exam_map[$report_exam_id])
            ? (string)$report_exam_map[$report_exam_id]['exam_name']
            : 'active-exams';
        $filename_suffix = preg_replace('/[^A-Za-z0-9\-]+/', '-', strtolower((string)$filename_suffix));
        $filename_suffix = trim($filename_suffix, '-');
    }
    if ($filename_suffix === '') {
        $filename_suffix = 'report-' . date('Ymd-His');
    }
    $download_filename = !empty($filename_parts)
        ? $filename_suffix . '.pdf'
        : 'faculty-duty-report-' . $filename_suffix . '.pdf';
    $dompdf->stream($download_filename, ['Attachment' => true]);
    exit;
}

// ── Department Room Invigilation Report ──────────────────────────────
// For one department: the rooms where that department's exams are held and
// both invigilators of each room, with each invigilator's own department.
$dept_report_id        = max(0, (int)($_GET['dept_report_id'] ?? 0));
$dept_report_exam_id   = max(0, (int)($_GET['dept_report_exam_id'] ?? 0));
$dept_report_date_from = trim((string)($_GET['dept_report_date_from'] ?? ''));
$dept_report_date_to   = trim((string)($_GET['dept_report_date_to'] ?? ''));
$dept_report_export    = trim((string)($_GET['dept_report_export'] ?? ''));

if ($dept_report_date_from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dept_report_date_from)) {
    $dept_report_date_from = '';
}
if ($dept_report_date_to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dept_report_date_to)) {
    $dept_report_date_to = '';
}
if ($dept_report_date_from !== '' && $dept_report_date_to !== '' && $dept_report_date_from > $dept_report_date_to) {
    [$dept_report_date_from, $dept_report_date_to] = [$dept_report_date_to, $dept_report_date_from];
}

$dept_report_rows = [];
if ($dept_report_id > 0) {
    $dr_where  = ['s.dept_id = ?'];
    $dr_params = [$dept_report_id];
    if ($dept_report_exam_id > 0) {
        $dr_where[]  = 'e.id = ?';
        $dr_params[] = $dept_report_exam_id;
    } else {
        $dr_where[] = 'e.is_active = 1';
    }
    if ($dept_report_date_from !== '') { $dr_where[] = 's.slot_date >= ?'; $dr_params[] = $dept_report_date_from; }
    if ($dept_report_date_to !== '')   { $dr_where[] = 's.slot_date <= ?'; $dr_params[] = $dept_report_date_to; }

    $dr_time_order = ei_index_time_order_expr('s.time_slot');
    $dr_st = db()->prepare(
        "SELECT s.slot_date, s.time_slot, s.room_number,
                e.exam_name, e.exam_year,
                dp.name AS room_dept_name,
                f1.name AS f1_name, f1.designation AS f1_desig, d1.name AS f1_dept,
                f2.name AS f2_name, f2.designation AS f2_desig, d2.name AS f2_dept
         FROM ei_slots s
         JOIN ei_exams e ON e.id = s.exam_id
         JOIN dept_departments dp ON dp.id = s.dept_id
         LEFT JOIN ei_faculty f1 ON f1.id = s.faculty1_id
         LEFT JOIN dept_departments d1 ON d1.id = f1.dept_id
         LEFT JOIN ei_faculty f2 ON f2.id = s.faculty2_id
         LEFT JOIN dept_departments d2 ON d2.id = f2.dept_id
         WHERE " . implode(' AND ', $dr_where) . "
         ORDER BY s.slot_date ASC, {$dr_time_order} ASC, s.time_slot ASC, s.room_number ASC"
    );
    $dr_st->execute($dr_params);
    $dept_report_rows = $dr_st->fetchAll();
}

$dept_report_name = ($dept_report_id > 0 && isset($report_department_map[$dept_report_id]))
    ? (string)$report_department_map[$dept_report_id]
    : '';

$dept_report_query = [];
if ($dept_report_id > 0)             $dept_report_query['dept_report_id'] = $dept_report_id;
if ($dept_report_exam_id > 0)        $dept_report_query['dept_report_exam_id'] = $dept_report_exam_id;
if ($dept_report_date_from !== '')   $dept_report_query['dept_report_date_from'] = $dept_report_date_from;
if ($dept_report_date_to !== '')     $dept_report_query['dept_report_date_to'] = $dept_report_date_to;
$dept_report_pdf_url = APP_URL . '/exam-invigilation/reports.php?' . http_build_query(array_merge($dept_report_query, ['dept_report_export' => 'pdf']));

if ($dept_report_export === 'pdf' && $dept_report_id > 0) {
    require_once __DIR__ . '/../../vendor/autoload.php';

    $generated_at_label = date('d M Y, h:i A');
    $logo_path = dirname(dirname(__DIR__)) . '/assets/img/logo/logo-black.png';
    $logo_data_uri = '';
    if (is_file($logo_path) && is_readable($logo_path)) {
        $logo_binary = file_get_contents($logo_path);
        if ($logo_binary !== false) {
            $logo_data_uri = 'data:image/png;base64,' . base64_encode($logo_binary);
        }
    }

    $dr_exam_label = 'All Active Exams';
    if ($dept_report_exam_id > 0 && isset($report_exam_map[$dept_report_exam_id])) {
        $dr_exam_label = $report_exam_map[$dept_report_exam_id]['exam_name'] . ' (' . $report_exam_map[$dept_report_exam_id]['exam_year'] . ')';
    }
    $dr_date_label = '';
    if ($dept_report_date_from !== '' && $dept_report_date_to !== '') {
        $dr_date_label = ei_report_format_date($dept_report_date_from) . ' to ' . ei_report_format_date($dept_report_date_to);
    } elseif ($dept_report_date_from !== '') {
        $dr_date_label = 'From ' . ei_report_format_date($dept_report_date_from);
    } elseif ($dept_report_date_to !== '') {
        $dr_date_label = 'Up to ' . ei_report_format_date($dept_report_date_to);
    }

    $dr_cell = 'padding:8px 10px;border:1px solid #d7deea;color:#0f172a;vertical-align:top;';
    $dr_invigilator_html = static function (?string $name, ?string $desig, ?string $dept): string {
        if (!$name) {
            return '<span style="color:#dc2626;">Not assigned</span>';
        }
        $html = '<span style="font-weight:700;">' . ei_report_escape((string)$name) . '</span>';
        if ($desig) {
            $html .= '<br><span style="color:#64748b;font-size:8pt;">' . ei_report_escape((string)$desig) . '</span>';
        }
        if ($dept) {
            $html .= '<br><span style="color:#2563eb;font-size:8pt;">' . ei_report_escape((string)$dept) . '</span>';
        }
        return $html;
    };

    $dr_rows_html = '';
    if (empty($dept_report_rows)) {
        $dr_rows_html = '<tr><td colspan="5" style="padding:30px 14px;text-align:center;color:#6b7280;">No invigilation rooms found for this department with the selected filters.</td></tr>';
    } else {
        foreach ($dept_report_rows as $dr_row) {
            $dr_rows_html .= '<tr>'
                . '<td style="' . $dr_cell . 'white-space:nowrap;">' . ei_report_escape(ei_report_format_date((string)$dr_row['slot_date'], 'd M Y')) . '</td>'
                . '<td style="' . $dr_cell . '">' . ei_report_escape((string)$dr_row['time_slot']) . '</td>'
                . '<td style="' . $dr_cell . 'font-weight:700;">' . ei_report_escape((string)$dr_row['room_number']) . '</td>'
                . '<td style="' . $dr_cell . '">' . $dr_invigilator_html($dr_row['f1_name'], $dr_row['f1_desig'], $dr_row['f1_dept']) . '</td>'
                . '<td style="' . $dr_cell . '">' . $dr_invigilator_html($dr_row['f2_name'], $dr_row['f2_desig'], $dr_row['f2_dept']) . '</td>'
                . '</tr>';
        }
    }

    $logo_html = $logo_data_uri !== ''
        ? '<img src="' . $logo_data_uri . '" alt="Prime University" style="height:56px;width:auto;">'
        : '';

    $dr_meta_rows = '<tr>'
            . '<td style="width:24%;padding:6px 0;color:#475569;font-weight:700;">Department</td>'
            . '<td style="width:76%;padding:6px 0;color:#0f172a;">: ' . ei_report_escape($dept_report_name) . '</td>'
        . '</tr>'
        . '<tr>'
            . '<td style="padding:6px 0;color:#475569;font-weight:700;">Exam Title</td>'
            . '<td style="padding:6px 0;color:#0f172a;">: ' . ei_report_escape($dr_exam_label) . '</td>'
        . '</tr>';
    if ($dr_date_label !== '') {
        $dr_meta_rows .= '<tr>'
            . '<td style="padding:6px 0;color:#475569;font-weight:700;">Duty Dates</td>'
            . '<td style="padding:6px 0;color:#0f172a;">: ' . ei_report_escape($dr_date_label) . '</td>'
        . '</tr>';
    }
    $dr_meta_rows .= '<tr>'
            . '<td style="padding:6px 0;color:#475569;font-weight:700;">Total Room Slots</td>'
            . '<td style="padding:6px 0;color:#0f172a;">: ' . count($dept_report_rows) . '</td>'
        . '</tr>';

    $dr_pdf_html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Department Invigilation Room Report</title></head>'
        . '<body style="font-family:DejaVu Sans, Arial, sans-serif;background:#eef3f8;margin:0;padding:18px;">'
        . '<div style="background:#ffffff;border:1px solid #d7deea;border-radius:16px;overflow:hidden;">'
        . '<div style="padding:18px 24px 14px;border-bottom:1px solid #d7deea;">'
        . '<table style="width:100%;border-collapse:collapse;"><tr>'
        . '<td style="width:90px;vertical-align:middle;">' . $logo_html . '</td>'
        . '<td style="vertical-align:middle;text-align:center;">'
        . '<div style="font-size:16pt;font-weight:800;color:#0f172a;line-height:1.2;">Department Invigilation Room Report</div>'
        . '</td>'
        . '<td style="width:90px;"></td>'
        . '</tr></table>'
        . '<table style="width:100%;border-collapse:collapse;margin-top:12px;font-size:9.5pt;">'
        . $dr_meta_rows
        . '</table>'
        . '</div>'
        . '<div style="padding:14px 24px 14px;">'
        . '<table style="width:100%;border-collapse:collapse;font-size:9pt;">'
        . '<thead>'
        . '<tr style="background:#0f172a;color:#ffffff;">'
        . '<th style="padding:9px 10px;border:1px solid #0f172a;text-align:left;width:14%;">Date</th>'
        . '<th style="padding:9px 10px;border:1px solid #0f172a;text-align:left;width:18%;">Duty Time</th>'
        . '<th style="padding:9px 10px;border:1px solid #0f172a;text-align:left;width:14%;">Room</th>'
        . '<th style="padding:9px 10px;border:1px solid #0f172a;text-align:left;">Invigilator 1</th>'
        . '<th style="padding:9px 10px;border:1px solid #0f172a;text-align:left;">Invigilator 2</th>'
        . '</tr>'
        . '</thead>'
        . '<tbody>' . $dr_rows_html . '</tbody>'
        . '</table>'
        . '<div style="margin:14px 0 0;font-size:8.5pt;color:#64748b;text-align:center;">This is a software-generated schedule. If you have any issues, please contact the Controller of Examinations.</div>'
        . '<div style="margin:4px 0 0;font-size:8.5pt;color:#64748b;text-align:center;">Generated ' . ei_report_escape($generated_at_label) . '</div>'
        . '</div>'
        . '</div>'
        . '</body></html>';

    $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
    $dompdf->loadHtml($dr_pdf_html, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();

    $dr_filename = preg_replace('/[^A-Za-z0-9\-]+/', '-', strtolower($dept_report_name));
    $dr_filename = trim((string)$dr_filename, '-');
    if ($dr_filename === '') {
        $dr_filename = 'department';
    }
    $dompdf->stream('dept-invigilation-' . $dr_filename . '-' . date('Ymd') . '.pdf', ['Attachment' => true]);
    exit;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/exam-invigilation/index.php">Exam Invigilation</a></li>
            <li class="breadcrumb-item active">Reports</li>
        </ol>
    </nav>
</div>

<?php flash_show(); ?>

<?php require __DIR__ . '/ei-nav.php'; ?>

<div class="card mb-4" style="border-left:4px solid #0d6efd;">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
            <h6 class="mb-1 fw-semibold"><i class="fas fa-file-pdf me-2 text-primary"></i>Faculty Duty Report</h6>
            <p class="mb-0 text-muted" style="font-size:.85rem;">
                Export a clean faculty-wise invigilation sheet with date, time, and room for print handover.
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="<?= h($report_pdf_url) ?>" target="_blank" class="btn btn-primary btn-sm" style="border-radius:10px;">
                <i class="fas fa-file-pdf me-1"></i> Export PDF
            </a>
            <a href="<?= h($report_pdf_all_url) ?>" class="btn btn-outline-primary btn-sm" style="border-radius:10px;"
               title="Downloads a ZIP with a folder per department, each containing one PDF per invigilator (Faculty name_Designation.pdf)">
                <i class="fas fa-file-archive me-1"></i> Export All Faculty PDFs
            </a>
            <a href="<?= APP_URL ?>/exam-invigilation/reports.php" class="btn btn-outline-secondary btn-sm" style="border-radius:10px;">
                <i class="fas fa-undo me-1"></i> Reset Report
            </a>
        </div>
    </div>
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end mb-4">
            <div class="col-12 col-lg-3">
                <label class="form-label small text-muted mb-1">Exam</label>
                <select name="report_exam_id" class="form-select form-select-sm">
                    <option value="0">All Active Exams</option>
                    <?php foreach ($report_exam_options as $report_exam_option): ?>
                    <option value="<?= (int)$report_exam_option['id'] ?>" <?= $report_exam_id === (int)$report_exam_option['id'] ? 'selected' : '' ?>>
                        <?= h($report_exam_option['exam_name']) ?> (<?= h($report_exam_option['exam_year']) ?>)<?= (int)$report_exam_option['is_active'] === 1 ? '' : ' • Inactive' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-lg-3">
                <label class="form-label small text-muted mb-1">Department</label>
                <select name="report_dept_id" class="form-select form-select-sm">
                    <option value="0">All Departments</option>
                    <?php foreach ($report_departments as $report_department): ?>
                    <option value="<?= (int)$report_department['id'] ?>" <?= $report_dept_id === (int)$report_department['id'] ? 'selected' : '' ?>>
                        <?= h($report_department['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-lg-3">
                <label class="form-label small text-muted mb-1">Faculty</label>
                <select name="report_faculty_id" class="form-select form-select-sm">
                    <option value="0">All Faculty</option>
                    <?php foreach ($report_faculty_options as $report_faculty): ?>
                    <option value="<?= (int)$report_faculty['id'] ?>" <?= $report_faculty_id === (int)$report_faculty['id'] ? 'selected' : '' ?>>
                        <?= h($report_faculty['name']) ?><?= $report_faculty['designation'] ? ' • ' . h($report_faculty['designation']) : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-1">
                <label class="form-label small text-muted mb-1">From</label>
                <input type="date" name="report_date_from" class="form-control form-control-sm" value="<?= h($report_date_from) ?>">
            </div>
            <div class="col-6 col-lg-1">
                <label class="form-label small text-muted mb-1">To</label>
                <input type="date" name="report_date_to" class="form-control form-control-sm" value="<?= h($report_date_to) ?>">
            </div>
            <div class="col-12 col-lg-1 d-grid">
                <button class="btn btn-sm btn-outline-primary" style="border-radius:8px;">
                    <i class="fas fa-filter me-1"></i> View
                </button>
            </div>
        </form>

        <div class="alert alert-light border d-flex flex-wrap align-items-center gap-2 mb-4" style="font-size:.85rem;">
            <span class="fw-semibold text-dark">Current Report:</span>
            <span class="text-muted"><?= h($report_filter_summary) ?></span>
            <span class="ms-auto text-muted">Defaults to active exams when no specific exam is selected.</span>
        </div>
        <?php if ($report_date_range_note !== ''): ?>
        <div class="alert alert-warning py-2 px-3 mb-4" style="font-size:.85rem;">
            <i class="fas fa-exclamation-triangle me-1"></i><?= h($report_date_range_note) ?>
        </div>
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card text-center py-3 h-100" style="border-left:4px solid #0d6efd;">
                    <div style="font-size:1.8rem;font-weight:700;color:#0d6efd;"><?= $report_total_rows ?></div>
                    <div class="text-muted" style="font-size:.8rem;">Duty Entries</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center py-3 h-100" style="border-left:4px solid #198754;">
                    <div style="font-size:1.8rem;font-weight:700;color:#198754;"><?= $report_faculty_count ?></div>
                    <div class="text-muted" style="font-size:.8rem;">Faculty Covered</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center py-3 h-100" style="border-left:4px solid #fd7e14;">
                    <div style="font-size:1.8rem;font-weight:700;color:#fd7e14;"><?= $report_date_count ?></div>
                    <div class="text-muted" style="font-size:.8rem;"><?= h($report_date_summary_label) ?></div>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover table-bordered align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-3 text-center" style="width:50px;">#</th>
                        <th>Faculty Name</th>
                        <th>Designation</th>
                        <th>Department</th>
                        <th>Invigilation Duty</th>
                        <th>Duty Time</th>
                        <th>Room Number</th>
                        <th>2nd Invigilator</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($faculty_duty_rows)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-5">
                            <i class="fas fa-inbox fa-2x mb-2 d-block text-muted"></i>
                            No faculty duty found for the selected report filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($faculty_duty_rows as $report_index => $report_row): ?>
                    <tr>
                        <td class="px-3 text-center"><?= $report_index + 1 ?></td>
                        <td class="fw-medium"><?= h($report_row['faculty_name']) ?></td>
                        <td><?= $report_row['designation'] ? h($report_row['designation']) : '<span class="text-muted">—</span>' ?></td>
                        <td><span class="badge bg-primary bg-opacity-10 text-primary"><?= h($report_row['dept_name']) ?></span></td>
                        <td>
                            <div class="fw-semibold"><?= h(ei_report_format_date((string)$report_row['slot_date'], 'd F Y')) ?></div>
                            <?php if ($report_exam_id === 0): ?>
                            <small class="text-muted"><?= h($report_row['exam_name']) ?> (<?= h($report_row['exam_year']) ?>)</small>
                            <?php endif; ?>
                        </td>
                        <td><?= h($report_row['time_slot']) ?></td>
                        <td class="fw-medium"><?= h($report_row['room_number']) ?></td>
                        <td>
                            <?php if (!empty($report_row['partner_name'])): ?>
                            <div class="fw-medium"><?= h($report_row['partner_name']) ?></div>
                            <?php if (!empty($report_row['partner_designation'])): ?><small class="text-muted"><?= h($report_row['partner_designation']) ?></small><?php endif; ?>
                            <?php if (!empty($report_row['partner_dept_name'])): ?><small class="text-primary d-block"><?= h($report_row['partner_dept_name']) ?></small><?php endif; ?>
                            <?php if (!empty($report_row['partner_contact'])): ?><small class="d-block text-muted"><i class="fas fa-phone me-1"></i><?= h($report_row['partner_contact']) ?></small><?php endif; ?>
                            <?php else: ?>
                            <span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>Not assigned</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Department Room Invigilation Report -->
<div class="card mb-4" id="dept-room-report" style="border-left:4px solid #198754;">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
            <h6 class="mb-1 fw-semibold"><i class="fas fa-building me-2 text-success"></i>Department Room Invigilation Report</h6>
            <p class="mb-0 text-muted" style="font-size:.85rem;">
                Pick a department to see the rooms where its exams run and who invigilates each room (with each invigilator's own department).
            </p>
        </div>
        <?php if ($dept_report_id > 0): ?>
        <a href="<?= h($dept_report_pdf_url) ?>" target="_blank" class="btn btn-success btn-sm" style="border-radius:10px;">
            <i class="fas fa-file-pdf me-1"></i> Export PDF
        </a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <form method="GET" action="<?= APP_URL ?>/exam-invigilation/reports.php#dept-room-report" class="row g-2 align-items-end mb-3">
            <div class="col-12 col-lg-4">
                <label class="form-label small text-muted mb-1">Department</label>
                <select name="dept_report_id" class="form-select form-select-sm" required>
                    <option value="">Select a department…</option>
                    <?php foreach ($report_departments as $dr_dept): ?>
                    <option value="<?= (int)$dr_dept['id'] ?>" <?= $dept_report_id === (int)$dr_dept['id'] ? 'selected' : '' ?>>
                        <?= h($dr_dept['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-lg-3">
                <label class="form-label small text-muted mb-1">Exam</label>
                <select name="dept_report_exam_id" class="form-select form-select-sm">
                    <option value="0">All Active Exams</option>
                    <?php foreach ($report_exam_options as $dr_exam): ?>
                    <option value="<?= (int)$dr_exam['id'] ?>" <?= $dept_report_exam_id === (int)$dr_exam['id'] ? 'selected' : '' ?>>
                        <?= h($dr_exam['exam_name']) ?> (<?= h($dr_exam['exam_year']) ?>)<?= (int)$dr_exam['is_active'] === 1 ? '' : ' • Inactive' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label small text-muted mb-1">From</label>
                <input type="date" name="dept_report_date_from" class="form-control form-control-sm" value="<?= h($dept_report_date_from) ?>">
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label small text-muted mb-1">To</label>
                <input type="date" name="dept_report_date_to" class="form-control form-control-sm" value="<?= h($dept_report_date_to) ?>">
            </div>
            <div class="col-12 col-lg-1 d-grid">
                <button class="btn btn-sm btn-outline-success" style="border-radius:8px;">
                    <i class="fas fa-filter me-1"></i> View
                </button>
            </div>
        </form>

        <?php if ($dept_report_id > 0): ?>
        <div class="alert alert-light border d-flex flex-wrap align-items-center gap-2 mb-3" style="font-size:.85rem;">
            <span class="fw-semibold text-dark"><?= h($dept_report_name) ?></span>
            <span class="text-muted">• <?= count($dept_report_rows) ?> room slot<?= count($dept_report_rows) === 1 ? '' : 's' ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-bordered align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-3 text-center" style="width:50px;">#</th>
                        <th>Date</th>
                        <th>Duty Time</th>
                        <th>Room</th>
                        <th>Invigilator 1</th>
                        <th>Invigilator 2</th>
                        <?php if ($dept_report_exam_id === 0): ?>
                        <th>Exam</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($dept_report_rows)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <i class="fas fa-inbox fa-2x mb-2 d-block text-muted"></i>
                            No invigilation rooms found for this department with the selected filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($dept_report_rows as $dr_index => $dr_row): ?>
                    <tr>
                        <td class="px-3 text-center"><?= $dr_index + 1 ?></td>
                        <td class="fw-semibold"><?= h(ei_report_format_date((string)$dr_row['slot_date'], 'd M Y')) ?></td>
                        <td><?= h($dr_row['time_slot']) ?></td>
                        <td class="fw-medium"><?= h($dr_row['room_number']) ?></td>
                        <td>
                            <?php if ($dr_row['f1_name']): ?>
                            <div class="fw-medium"><?= h($dr_row['f1_name']) ?></div>
                            <?php if ($dr_row['f1_desig']): ?><small class="text-muted"><?= h($dr_row['f1_desig']) ?></small><?php endif; ?>
                            <?php if ($dr_row['f1_dept']): ?><small class="text-primary d-block"><?= h($dr_row['f1_dept']) ?></small><?php endif; ?>
                            <?php else: ?>
                            <span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>Not assigned</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($dr_row['f2_name']): ?>
                            <div class="fw-medium"><?= h($dr_row['f2_name']) ?></div>
                            <?php if ($dr_row['f2_desig']): ?><small class="text-muted"><?= h($dr_row['f2_desig']) ?></small><?php endif; ?>
                            <?php if ($dr_row['f2_dept']): ?><small class="text-primary d-block"><?= h($dr_row['f2_dept']) ?></small><?php endif; ?>
                            <?php else: ?>
                            <span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>Not assigned</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($dept_report_exam_id === 0): ?>
                        <td><small class="text-muted"><?= h($dr_row['exam_name']) ?> (<?= h($dr_row['exam_year']) ?>)</small></td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center text-muted py-4">
            <i class="fas fa-building fa-2x mb-2 d-block" style="opacity:.2;"></i>
            Select a department above to view its room invigilation report.
        </div>
        <?php endif; ?>
    </div>
</div>

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
<div class="card mb-4" id="vacant-slots">
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
