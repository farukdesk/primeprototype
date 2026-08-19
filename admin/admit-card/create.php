<?php
/**
 * Admit Card – Generator (rebuilt, course-offer driven).
 *
 * The old exam-routine selection is gone. New flow:
 *
 *   1. Enter the exam name + semester and upload (or paste) the exam
 *      schedule: Course Title, Course Code, Date, Time (order free, tab or
 *      comma separated, extra columns ignored).
 *   2. Preview — ALL active course offers are loaded in one click. Every
 *      offered course that has registered (active) students is matched
 *      against the schedule by course code (fallback: course title, fuzzy).
 *      When the same course appears in several schedule rows (Day +
 *      Evening), the slot is chosen by the offer's shift:
 *      EVENING offers only take slots starting at or after 3:00 PM,
 *      DAY offers only take slots before 3:00 PM.
 *   3. Generate — ONE admit card per class group (department + program +
 *      batch + shift) containing that group's matched courses with date and
 *      time. Course rows are linked to the course-offer subjects, so every
 *      student sees only the courses they are actually registered in —
 *      students enrolled across different batches / sections (retakes) are
 *      merged onto one card by the student-centric resolution in
 *      helpers.php (ac_get_merged_courses_for_student).
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('admit-card', 'can_create');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../exam-routine/helpers.php'; // er_norm / er_parse_date / er_parse_time / er_fmt_time

$page_title = 'Generate Admit Cards';
$db         = db();
$errors     = [];
$preview    = null;

$exam_name     = trim((string)($_POST['exam_name'] ?? ''));
$semester      = trim((string)($_POST['semester']  ?? ''));
$is_active     = ($_SERVER['REQUEST_METHOD'] === 'POST') ? ((int)($_POST['is_active'] ?? 0) ? 1 : 0) : 1;
$schedule_text = '';

// Predefined semester options
$year = (int)date('Y');
$semester_opts = [];
foreach ([$year - 1, $year, $year + 1] as $y) {
    $semester_opts[] = "Spring $y";
    $semester_opts[] = "Summer $y";
    $semester_opts[] = "Fall $y";
}
if ($semester !== '' && !in_array($semester, $semester_opts, true)) array_unshift($semester_opts, $semester);

// Optional schema column (course rows are linked to offer subjects when present)
$has_subject_col = false;
try { $db->query('SELECT offer_subject_id FROM ac_admit_card_courses LIMIT 1'); $has_subject_col = true; } catch (Throwable $e) {}

/** Map a schedule header cell to an internal field name (fuzzy). */
function acg_col(string $header): ?string
{
    static $map = null;
    if ($map === null) {
        $defs = [
            'course_title' => ['course title', 'course name', 'course', 'title', 'subject'],
            'course_code'  => ['course code', 'code', 'coursecode'],
            'date'         => ['date', 'exam date'],
            'time'         => ['time', 'exam time', 'time slot', 'start time'],
        ];
        $map = [];
        foreach ($defs as $field => $aliases) {
            foreach ($aliases as $a) $map[$a] = $field;
        }
    }
    return $map[er_norm($header)] ?? null;
}

/** Split pasted/uploaded schedule text into rows (tab or comma separated). */
function acg_parse_text(string $text): array
{
    $text = (string)preg_replace('/^\xEF\xBB\xBF/', '', $text);
    $rows = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        if (trim($line) === '') continue;
        $delim  = (strpos($line, "\t") !== false) ? "\t" : ',';
        $rows[] = str_getcsv($line, $delim, '"', '');
    }
    return $rows;
}

/** "EEE 1105" → "eee1105" (lowercase alphanumerics only). */
function acg_code_key(string $s): string
{
    return strtolower((string)preg_replace('/[^a-z0-9]+/i', '', $s));
}

/** Parse a time cell ("11:00 AM - 1:00 PM") → [start 'H:i'|null, label]. */
function acg_parse_slot(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') return [null, ''];
    $startRaw = $raw;
    $endRaw   = '';
    if (preg_match('/^(.+?)\s*(?:-|\x{2013}|to)\s*(.+)$/iu', $raw, $m)) {
        $startRaw = $m[1];
        $endRaw   = $m[2];
    }
    $start = er_parse_time($startRaw);
    $end   = $endRaw !== '' ? er_parse_time($endRaw) : null;
    if ($start === null) return [null, $raw];
    $label = er_fmt_time($start . ':00') . ($end !== null ? ' - ' . er_fmt_time($end . ':00') : '');
    return [$start, $label];
}

/** Day or Evening for an offer's shift value ('' when unknown). */
function acg_shift_kind(?string $shift): string
{
    $s = er_norm((string)$shift);
    if ($s === '') return '';
    if (strpos($s, 'even') !== false || strpos($s, 'night') !== false) return 'evening';
    if (strpos($s, 'day') !== false || strpos($s, 'morn') !== false) return 'day';
    return '';
}

/**
 * Parse the schedule → [entries, by_code, by_title].
 * Every entry: code, title, date (Y-m-d), slot (label), kind (day|evening|''),
 * line, used. Evening = start time at or after 3:00 PM, Day = before 3:00 PM.
 */
function acg_build_schedule(string $text, array &$errors): array
{
    $rows = acg_parse_text($text);
    if (count($rows) < 2) {
        $errors[] = 'The schedule needs a header row plus at least one data row.';
        return [[], [], []];
    }
    $fields = [];
    foreach ($rows[0] as $i => $cell) {
        $f = acg_col((string)$cell);
        if ($f !== null && !in_array($f, $fields, true)) $fields[$i] = $f;
    }
    if (!in_array('course_code', $fields, true) && !in_array('course_title', $fields, true)) {
        $errors[] = 'The schedule must contain a "Course Code" or "Course Title" column.';
    }
    if (!in_array('date', $fields, true)) $errors[] = 'The schedule must contain a "Date" column.';
    if ($errors) return [[], [], []];

    $entries = [];
    foreach (array_slice($rows, 1) as $n => $cells) {
        $row = ['course_title' => '', 'course_code' => '', 'date' => '', 'time' => ''];
        foreach ($fields as $i => $f) $row[$f] = trim((string)($cells[$i] ?? ''));
        if (implode('', $row) === '') continue;
        $line = $n + 2;
        $date = er_parse_date($row['date']);
        if (!$date) {
            $errors[] = 'Schedule line ' . $line . ': could not understand the date "' . $row['date'] . '".';
            continue;
        }
        [$start, $slot] = acg_parse_slot($row['time']);
        $kind = '';
        if ($start !== null) {
            [$h, $m] = explode(':', $start);
            $kind = ((int)$h * 60 + (int)$m) >= 15 * 60 ? 'evening' : 'day';
        }
        $entries[] = [
            'code'  => $row['course_code'],
            'title' => $row['course_title'],
            'date'  => $date,
            'slot'  => $slot,
            'kind'  => $kind,
            'line'  => $line,
            'used'  => false,
        ];
    }
    $by_code  = [];
    $by_title = [];
    foreach ($entries as $i => $en) {
        $ck = acg_code_key((string)$en['code']);
        if ($ck !== '') $by_code[$ck][] = $i;
        $tk = er_norm((string)$en['title']);
        if ($tk !== '') $by_title[$tk][] = $i;
    }
    return [$entries, $by_code, $by_title];
}

/**
 * Choose the schedule entry for one offered course. A slot of the OTHER
 * shift is never used when the offer's shift is known ("evening always
 * starts at or after 3:00 PM"). Sets $warn for advisory notes.
 */
function acg_pick(array $entries, array $idxs, string $shift_kind, ?string &$warn): ?int
{
    $warn  = null;
    $cands = [];
    foreach ($idxs as $i) {
        $k = $entries[$i]['kind'];
        if ($k === '' || $shift_kind === '' || $k === $shift_kind) $cands[] = $i;
    }
    if (!$cands) {
        $warn = $shift_kind === 'evening'
            ? 'the schedule only has a Day slot (before 3:00 PM) for this course'
            : 'the schedule only has an Evening slot (3:00 PM or later) for this course';
        return null;
    }
    if (count($cands) > 1) {
        $warn = $shift_kind === ''
            ? 'the offer has no shift and the schedule has ' . count($cands) . ' slots for this course — the first one was used, please verify'
            : count($cands) . ' ' . ucfirst($shift_kind) . ' slots exist in the schedule for this course — the first one was used, please verify';
    }
    return $cands[0];
}

/**
 * Load ALL active course offers, match every registered course against the
 * schedule and build one class group (dept + program + batch + shift) per
 * future admit card.
 */
function acg_build_preview(string $schedule_text, array &$errors): ?array
{
    [$entries, $by_code, $by_title] = acg_build_schedule($schedule_text, $errors);
    if (!$entries) return null;

    $db = db();
    $offers = $db->query(
        "SELECT o.id, o.dept_id, o.program_id, o.batch_id, o.shift, o.section,
                d.name AS dept_name, p.program_name, b.name AS batch_name
           FROM co_offers o
           JOIN dept_departments d            ON d.id = o.dept_id
      LEFT JOIN dept_academic_programs p      ON p.id = o.program_id
      LEFT JOIN student_batches b             ON b.id = o.batch_id
          WHERE o.status = 'active'
          ORDER BY d.name ASC, p.program_name ASC, b.sort_order ASC, b.name ASC, o.shift ASC"
    )->fetchAll();
    if (!$offers) {
        $errors[] = 'No active course offers found.';
        return null;
    }

    $ph = implode(',', array_fill(0, count($offers), '?'));
    $st = $db->prepare(
        "SELECT cos.id AS offer_subject_id, cos.offer_id, c.course_code, c.course_name,
                (SELECT COUNT(*)
                   FROM co_registrations r
                   JOIN students s ON s.id = r.student_id
                  WHERE r.offer_subject_id = cos.id AND s.status = 'Active') AS reg_count
           FROM co_offer_subjects cos
           JOIN course_curriculum c ON c.id = cos.curriculum_id
          WHERE cos.offer_id IN ($ph)"
    );
    $st->execute(array_map(fn($o) => (int)$o['id'], $offers));
    $subjects_by_offer = [];
    foreach ($st->fetchAll() as $s) $subjects_by_offer[(int)$s['offer_id']][] = $s;

    $groups         = [];
    $skipped_no_reg = 0;

    foreach ($offers as $o) {
        $key = implode('|', [
            (int)$o['dept_id'], (int)$o['program_id'], (int)$o['batch_id'],
            er_norm((string)($o['shift'] ?? '')),
        ]);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'hash'        => md5($key),
                'dept_id'     => (int)$o['dept_id'],
                'program_id'  => (int)$o['program_id'],
                'batch_id'    => (int)$o['batch_id'] ?: null,
                'batch_label' => (string)($o['batch_name'] ?? ''),
                'shift'       => (string)($o['shift'] ?? ''),
                'label'       => trim(
                    $o['dept_name']
                    . ($o['program_name'] ? ' — ' . $o['program_name'] : '')
                    . ($o['batch_name'] ? ' · Batch ' . $o['batch_name'] : '')
                    . ($o['shift'] ? ' · ' . $o['shift'] : '')
                ),
                'courses'     => [],
                'warnings'    => [],
                'osids'       => [],
                'students'    => 0,
            ];
            if ((int)$o['program_id'] <= 0) {
                $groups[$key]['warnings'][] = 'This offer has no program — an admit card cannot be created for this group.';
            }
        }
        $g    = &$groups[$key];
        $kind = acg_shift_kind($o['shift'] ?? null);

        foreach ($subjects_by_offer[(int)$o['id']] ?? [] as $s) {
            $osid = (int)$s['offer_subject_id'];
            if (isset($g['osids'][$osid])) continue;
            $label = trim((string)$s['course_code'] . ' ' . (string)$s['course_name']);

            // Match by course code first, then by title (exact → fuzzy).
            $idxs = [];
            $ck = acg_code_key((string)$s['course_code']);
            if ($ck !== '' && isset($by_code[$ck])) {
                $idxs = $by_code[$ck];
            } else {
                $tn = er_norm((string)$s['course_name']);
                if ($tn !== '' && isset($by_title[$tn])) {
                    $idxs = $by_title[$tn];
                } elseif (strlen($tn) >= 5) {
                    foreach ($by_title as $k => $list) {
                        if (strpos($k, $tn) !== false || strpos($tn, $k) !== false) { $idxs = $list; break; }
                        similar_text($k, $tn, $pct);
                        if ($pct >= 85) { $idxs = $list; break; }
                    }
                }
            }

            // Only courses that are actually part of the uploaded schedule
            // count as "skipped without registrations" — empty offer
            // subjects outside this exam's schedule are ignored silently.
            if ((int)$s['reg_count'] <= 0) {
                if ($idxs) $skipped_no_reg++;
                continue;
            }

            if (!$idxs) {
                $g['warnings'][] = $label . ' (' . (int)$s['reg_count'] . ' students): not in the schedule — skipped.';
                continue;
            }

            $warn = null;
            $pick = acg_pick($entries, $idxs, $kind, $warn);
            if ($pick === null) {
                $g['warnings'][] = $label . ' (' . (int)$s['reg_count'] . ' students): ' . $warn . ' — skipped.';
                continue;
            }
            if ($warn !== null) $g['warnings'][] = $label . ': ' . $warn . '.';
            $entries[$pick]['used'] = true;

            $g['osids'][$osid] = true;
            $g['courses'][]    = [
                'offer_subject_id' => $osid,
                'course_code'      => (string)$s['course_code'],
                'course_title'     => (string)$s['course_name'],
                'exam_date'        => (string)$entries[$pick]['date'],
                'time_slot'        => (string)$entries[$pick]['slot'],
                'section'          => trim((string)((($o['section'] ?? '') !== '') ? $o['section'] : ($o['shift'] ?? ''))),
                'reg_count'        => (int)$s['reg_count'],
            ];
        }
        unset($g);
    }

    foreach ($groups as $k => &$g) {
        if (!$g['courses'] && !$g['warnings']) { unset($groups[$k]); continue; }
        usort($g['courses'], static fn($a, $b) =>
            [$a['exam_date'], $a['course_code']] <=> [$b['exam_date'], $b['course_code']]);
        if ($g['osids']) {
            $ids = array_keys($g['osids']);
            $iph = implode(',', array_fill(0, count($ids), '?'));
            $cs  = db()->prepare(
                "SELECT COUNT(DISTINCT r.student_id)
                   FROM co_registrations r
                   JOIN students s ON s.id = r.student_id
                  WHERE r.offer_subject_id IN ($iph) AND s.status = 'Active'"
            );
            $cs->execute($ids);
            $g['students'] = (int)$cs->fetchColumn();
        }
    }
    unset($g);

    $unused = [];
    foreach ($entries as $en) {
        if ($en['used']) continue;
        $unused[] = 'Line ' . $en['line'] . ': ' . trim($en['code'] . ' ' . $en['title'])
            . ' (' . date('d M Y', strtotime($en['date'])) . ($en['slot'] !== '' ? ', ' . $en['slot'] : '') . ')';
    }

    return [
        'groups'         => array_values($groups),
        'unused'         => $unused,
        'skipped_no_reg' => $skipped_no_reg,
    ];
}

// ── POST ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($exam_name === '') $errors[] = 'Exam name is required.';
    if ($semester === '')  $errors[] = 'Semester is required.';

    if (!empty($_FILES['schedule_file']['tmp_name']) && is_uploaded_file($_FILES['schedule_file']['tmp_name'])) {
        $schedule_text = (string)file_get_contents($_FILES['schedule_file']['tmp_name']);
    } elseif (trim((string)($_POST['schedule_text'] ?? '')) !== '') {
        $schedule_text = (string)$_POST['schedule_text'];
    }
    if (trim($schedule_text) === '') {
        $errors[] = 'Upload or paste the exam schedule (Course Title, Course Code, Date, Time).';
    }

    if (empty($errors)) {
        $preview = acg_build_preview($schedule_text, $errors);
    }

    if ($action === 'generate' && empty($errors) && $preview) {
        $sel      = array_map('strval', (array)($_POST['sel'] ?? []));
        $created  = 0;
        $rows_n   = 0;
        foreach ($preview['groups'] as $g) {
            if (!in_array($g['hash'], $sel, true)) continue;
            if (!$g['courses'] || (int)$g['program_id'] <= 0) continue;

            $db->prepare(
                'INSERT INTO ac_admit_cards
                   (exam_name, semester, dept_id, program_id, batch_id, batch_label, is_active, created_by)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([
                $exam_name, $semester, $g['dept_id'], $g['program_id'],
                $g['batch_id'], $g['batch_label'] !== '' ? $g['batch_label'] : null,
                $is_active, auth_user()['id'],
            ]);
            $card_id = (int)$db->lastInsertId();

            foreach ($g['courses'] as $i => $c) {
                if ($has_subject_col) {
                    $db->prepare(
                        'INSERT INTO ac_admit_card_courses
                           (admit_card_id, offer_subject_id, course_code, course_title, exam_date, time_slot, section, sort_order)
                         VALUES (?,?,?,?,?,?,?,?)'
                    )->execute([
                        $card_id, $c['offer_subject_id'], $c['course_code'], $c['course_title'],
                        $c['exam_date'], $c['time_slot'] !== '' ? $c['time_slot'] : null,
                        $c['section'] !== '' ? $c['section'] : null, $i,
                    ]);
                } else {
                    $db->prepare(
                        'INSERT INTO ac_admit_card_courses
                           (admit_card_id, course_code, course_title, exam_date, time_slot, section, sort_order)
                         VALUES (?,?,?,?,?,?,?)'
                    )->execute([
                        $card_id, $c['course_code'], $c['course_title'],
                        $c['exam_date'], $c['time_slot'] !== '' ? $c['time_slot'] : null,
                        $c['section'] !== '' ? $c['section'] : null, $i,
                    ]);
                }
                $rows_n++;
            }
            $created++;
        }
        if ($created === 0) {
            $errors[] = 'Select at least one class group with matched courses.';
        } else {
            flash_set('success', $created . ' admit card(s) created with ' . $rows_n
                . ' course row(s). Every student only sees the courses they are registered in.');
            redirect(APP_URL . '/admit-card/index.php');
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-id-card me-2 text-primary"></i>Generate Admit Cards</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admit-card/index.php">Admit Cards</a></li>
            <li class="breadcrumb-item active">Generate</li>
        </ol></nav>
    </div>
</div>

<?php flash_show(); ?>

<?php if ($errors): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ── Step 1: exam details + schedule ── -->
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-header py-3 px-4 fw-semibold">
        <i class="fas fa-file-csv me-2 text-primary"></i>Exam Details &amp; Schedule
    </div>
    <div class="card-body px-4 py-4">
        <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="preview">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Exam Name <span class="text-danger">*</span></label>
                    <input type="text" name="exam_name" class="form-control" value="<?= h($exam_name) ?>"
                           placeholder="e.g. Mid Term-1 Exam – Summer 2026" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Semester <span class="text-danger">*</span></label>
                    <select name="semester" class="form-select" required>
                        <option value="">— Select Semester —</option>
                        <?php foreach ($semester_opts as $s): ?>
                        <option value="<?= h($s) ?>" <?= $semester === $s ? 'selected' : '' ?>><?= h($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <div class="form-check mb-2">
                        <input type="checkbox" name="is_active" id="is_active" class="form-check-input" value="1" <?= $is_active ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="is_active">Active (visible to students)</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Schedule file (CSV / TSV)</label>
                    <input type="file" name="schedule_file" class="form-control" accept=".csv,.tsv,.txt,text/csv,text/plain">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">…or paste the schedule</label>
                    <textarea name="schedule_text" class="form-control font-monospace" rows="6"
                              placeholder="Course Title,Course Code,Date,Time&#10;Electrical Circuits I,EEE 1105,27.08.2026,11:00 AM - 1:00 PM"><?= h($schedule_text) ?></textarea>
                </div>
            </div>
            <div class="form-text mt-2">
                Columns (order free, tab or comma separated, extra columns ignored):
                <code>Course Title, Course Code, Date, Time</code>.
                Courses are matched against <strong>every active course offer</strong> by course code
                (title as fallback). When the same course has a Day and an Evening slot,
                <strong>Evening</strong> offers only take slots starting <strong>at or after 3:00 PM</strong>;
                <strong>Day</strong> offers only take slots <strong>before 3:00 PM</strong>.
            </div>
            <button type="submit" class="btn btn-primary mt-3" style="border-radius:10px;">
                <i class="fas fa-search me-1"></i> Load All Course Offers &amp; Preview
            </button>
        </form>
    </div>
</div>

<?php if ($preview !== null): ?>
<?php
    $selectable = array_values(array_filter($preview['groups'], static fn($g) => $g['courses'] && (int)$g['program_id'] > 0));
?>
<!-- ── Step 2: preview & generate ── -->
<form method="POST" id="generate_form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="generate">
    <input type="hidden" name="exam_name" value="<?= h($exam_name) ?>">
    <input type="hidden" name="semester" value="<?= h($semester) ?>">
    <input type="hidden" name="is_active" value="<?= (int)$is_active ?>">
    <textarea name="schedule_text" hidden><?= h($schedule_text) ?></textarea>

    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <h5 class="mb-0 fw-semibold"><i class="fas fa-eye me-2 text-muted"></i>Preview</h5>
        <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle"><?= count($selectable) ?> card(s) ready</span>
        <?php if ($preview['skipped_no_reg'] > 0): ?>
        <span class="badge bg-light text-muted border"><?= (int)$preview['skipped_no_reg'] ?> offered course(s) without registrations skipped</span>
        <?php endif; ?>
        <span class="ms-auto small">
            <button type="button" class="btn btn-link btn-sm p-0" id="sel_all">Select all</button>
            <span class="text-muted">/</span>
            <button type="button" class="btn btn-link btn-sm p-0" id="sel_none">none</button>
        </span>
    </div>

    <?php if ($preview['unused']): ?>
    <div class="alert alert-warning small">
        <strong><?= count($preview['unused']) ?> schedule row(s) matched no offered course</strong> (check for typos in the code / title):
        <ul class="mb-0 ps-3">
            <?php foreach ($preview['unused'] as $u): ?><li><?= h($u) ?></li><?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php foreach ($preview['groups'] as $g): $ok = $g['courses'] && (int)$g['program_id'] > 0; ?>
    <div class="card mb-3" style="border-radius:12px;">
        <div class="card-header py-3 px-4 d-flex flex-wrap align-items-center gap-2">
            <?php if ($ok): ?>
            <input type="checkbox" class="form-check-input mt-0 grp-sel" name="sel[]" value="<?= h($g['hash']) ?>" checked>
            <?php endif; ?>
            <span class="fw-semibold"><?= h($g['label']) ?></span>
            <span class="badge bg-secondary"><?= count($g['courses']) ?> course(s)</span>
            <span class="badge bg-light text-dark border"><?= (int)$g['students'] ?> enrolled student(s)</span>
            <?php if (!$ok): ?>
            <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">Nothing to generate</span>
            <?php endif; ?>
        </div>
        <?php if ($g['courses']): ?>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0" style="font-size:.85rem;">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">Code</th>
                            <th>Course Title</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Section / Shift</th>
                            <th class="text-center pe-4">Students</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($g['courses'] as $c): ?>
                        <tr>
                            <td class="ps-4"><span class="badge bg-light text-dark border" style="font-family:monospace;"><?= h($c['course_code']) ?></span></td>
                            <td><?= h($c['course_title']) ?></td>
                            <td><?= h(date('d M Y (D)', strtotime($c['exam_date']))) ?></td>
                            <td><?= $c['time_slot'] !== '' ? h($c['time_slot']) : '<span class="text-muted">—</span>' ?></td>
                            <td><?= $c['section'] !== '' ? h($c['section']) : '<span class="text-muted">—</span>' ?></td>
                            <td class="text-center pe-4"><?= (int)$c['reg_count'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($g['warnings']): ?>
        <div class="card-footer py-2 px-4">
            <?php foreach ($g['warnings'] as $w): ?>
            <div class="small text-warning-emphasis"><i class="fas fa-exclamation-triangle me-1"></i><?= h($w) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <div class="d-flex gap-2 mb-5">
        <button type="submit" class="btn btn-primary" style="border-radius:10px;" <?= $selectable ? '' : 'disabled' ?>>
            <i class="fas fa-id-card me-1"></i> Generate Admit Cards for Selected Groups
        </button>
        <a href="<?= APP_URL ?>/admit-card/create.php" class="btn btn-light" style="border-radius:10px;">Start Over</a>
    </div>
</form>

<script>
(function () {
    var boxes = document.querySelectorAll('.grp-sel');
    var all   = document.getElementById('sel_all');
    var none  = document.getElementById('sel_none');
    if (all)  all.addEventListener('click',  function () { boxes.forEach(function (b) { b.checked = true;  }); });
    if (none) none.addEventListener('click', function () { boxes.forEach(function (b) { b.checked = false; }); });
    document.getElementById('generate_form').addEventListener('submit', function (e) {
        var any = false;
        boxes.forEach(function (b) { if (b.checked) any = true; });
        if (!any) { e.preventDefault(); alert('Select at least one class group to generate.'); }
    });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
