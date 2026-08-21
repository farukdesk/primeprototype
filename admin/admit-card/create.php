<?php
/**
 * Admit Card – Generator (course-offer driven, batch wise).
 *
 * Rebuilt flow (the old CSV schedule upload is gone):
 *
 *   1. Enter the exam name + semester and load the registered courses of
 *      every ACTIVE course offer, grouped BATCH WISE (optional filters:
 *      offer semester, department, batch).
 *   2. Set the exam DATE and TIME for every course directly on the page.
 *      Batch-level quick-fill helpers copy one date/time to all courses of
 *      a batch. Courses left without a date are skipped.
 *   3. Generate — ONE admit card per class group (department + program +
 *      batch + shift) containing that group's dated courses. Course rows
 *      are linked to the course-offer subjects, so every student only sees
 *      the courses they are actually registered in (retakes across batches
 *      are merged onto one card by the student-centric resolution in
 *      helpers.php — ac_get_merged_courses_for_student).
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('admit-card', 'can_create');
require_once __DIR__ . '/helpers.php';

$page_title = 'Generate Admit Cards';
$db         = db();
$errors     = [];
$batches    = null;   // loaded batch-wise course data
$dup_cards  = [];

$exam_name   = trim((string)($_POST['exam_name'] ?? ''));
$semester    = trim((string)($_POST['semester'] ?? ''));
$is_active   = ($_SERVER['REQUEST_METHOD'] === 'POST') ? ((int)($_POST['is_active'] ?? 0) ? 1 : 0) : 1;
$f_offer_sem = trim((string)($_POST['offer_semester'] ?? ''));
$f_dept_id   = (int)($_POST['dept_id'] ?? 0);
$f_batch_id  = (int)($_POST['batch_id'] ?? 0);

$in_dates  = (array)($_POST['exam_date'] ?? []);
$in_starts = (array)($_POST['start_time'] ?? []);
$in_ends   = (array)($_POST['end_time'] ?? []);
$in_sel    = array_map('strval', (array)($_POST['sel'] ?? []));
$was_generate = ($_POST['action'] ?? '') === 'generate';

// Predefined semester options for the card label
$year = (int)date('Y');
$semester_opts = [];
foreach ([$year - 1, $year, $year + 1] as $y) {
    $semester_opts[] = "Spring $y";
    $semester_opts[] = "Summer $y";
    $semester_opts[] = "Fall $y";
}
if ($semester !== '' && !in_array($semester, $semester_opts, true)) array_unshift($semester_opts, $semester);

// Exam name options: ACTIVE exams from Exam Invigilation (ei_exams). The
// exam year is appended when the name does not already contain it, so two
// years of the same exam never collide on the card label.
$exam_name_opts = [];
try {
    foreach ($db->query("SELECT exam_name, exam_year FROM ei_exams WHERE is_active = 1 ORDER BY exam_year DESC, exam_name ASC")->fetchAll() as $ex) {
        $nm = trim((string)$ex['exam_name']);
        if ($nm === '') continue;
        $yr = (string)(int)$ex['exam_year'];
        $label = ($yr !== '0' && strpos($nm, $yr) === false) ? $nm . ' ' . $yr : $nm;
        if (!in_array($label, $exam_name_opts, true)) $exam_name_opts[] = $label;
    }
} catch (Throwable $e) {
    // ei_exams unavailable — the form falls back to the free-text input
}
// Keep a previously posted value selectable (e.g. the exam was deactivated
// between loading the courses and generating the cards).
if ($exam_name !== '' && $exam_name_opts && !in_array($exam_name, $exam_name_opts, true)) {
    array_unshift($exam_name_opts, $exam_name);
}

// Filter dropdown data
$filter_depts   = $db->query("SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$filter_batches = $db->query("SELECT id, name FROM student_batches WHERE is_active = 1 ORDER BY sort_order ASC, name ASC")->fetchAll();
$filter_sems    = $db->query("SELECT DISTINCT semester FROM co_offers WHERE status = 'active' AND semester IS NOT NULL AND semester <> '' ORDER BY semester ASC")->fetchAll(PDO::FETCH_COLUMN);

// Optional schema column (course rows are linked to offer subjects when present)
$has_subject_col = false;
try { $db->query('SELECT offer_subject_id FROM ac_admit_card_courses LIMIT 1'); $has_subject_col = true; } catch (Throwable $e) {}

/** "EEE 1105" → "eee1105" (lowercase alphanumerics only). */
function acg_code_key(string $s): string
{
    return strtolower((string)preg_replace('/[^a-z0-9]+/i', '', $s));
}

/** "13:00" (+ optional end) → "1:00 PM - 3:00 PM". */
function acg_slot_label(string $start, string $end): string
{
    if ($start === '') return '';
    $fmt = static fn(string $t): string => date('g:i A', strtotime($t));
    return $fmt($start) . ($end !== '' ? ' - ' . $fmt($end) : '');
}

/**
 * Load the registered courses of every matching ACTIVE course offer,
 * grouped BATCH WISE. Every batch bucket contains one class group per
 * department + program + batch + shift (= one future admit card).
 *
 * Returns a list of batches:
 *   [batch_id, batch_name, groups => [
 *       [hash, dept_id, program_id, batch_id, batch_label, shift, label,
 *        students, courses => [[offer_subject_id, course_code,
 *        course_title, section, reg_count], …]], …]]
 */
function acg_load_batches(string $offer_sem, int $dept_id, int $batch_id, array &$errors): array
{
    $db     = db();
    $where  = ["o.status = 'active'"];
    $params = [];
    if ($offer_sem !== '') { $where[] = 'o.semester = ?'; $params[] = $offer_sem; }
    if ($dept_id  > 0)     { $where[] = 'o.dept_id = ?';  $params[] = $dept_id; }
    if ($batch_id > 0)     { $where[] = 'o.batch_id = ?'; $params[] = $batch_id; }
    $whereSQL = implode(' AND ', $where);

    $st = $db->prepare(
        "SELECT o.id, o.dept_id, o.program_id, o.batch_id, o.shift, o.section, o.semester,
                d.name AS dept_name, p.program_name, b.name AS batch_name
           FROM co_offers o
           JOIN dept_departments d            ON d.id = o.dept_id
      LEFT JOIN dept_academic_programs p      ON p.id = o.program_id
      LEFT JOIN student_batches b             ON b.id = o.batch_id
          WHERE $whereSQL
          ORDER BY b.sort_order ASC, b.name ASC, d.name ASC, p.program_name ASC, o.shift ASC"
    );
    $st->execute($params);
    $offers = $st->fetchAll();
    if (!$offers) {
        $errors[] = 'No active course offers matched the filters.';
        return [];
    }

    // Offered subjects with their ACTIVE-student registration counts
    $ph = implode(',', array_fill(0, count($offers), '?'));
    $ss = $db->prepare(
        "SELECT cos.id AS offer_subject_id, cos.offer_id, c.course_code, c.course_name,
                (SELECT COUNT(*)
                   FROM co_registrations r
                   JOIN students s ON s.id = r.student_id
                  WHERE r.offer_subject_id = cos.id AND s.status = 'Active') AS reg_count
           FROM co_offer_subjects cos
           JOIN course_curriculum c ON c.id = cos.curriculum_id
          WHERE cos.offer_id IN ($ph)
          ORDER BY cos.sort_order ASC, cos.id ASC"
    );
    $ss->execute(array_map(static fn($o) => (int)$o['id'], $offers));
    $subjects_by_offer = [];
    foreach ($ss->fetchAll() as $s) $subjects_by_offer[(int)$s['offer_id']][] = $s;

    $batches = [];
    foreach ($offers as $o) {
        $bid = (int)$o['batch_id'];
        if (!isset($batches[$bid])) {
            $batches[$bid] = [
                'batch_id'   => $bid,
                'batch_name' => trim((string)($o['batch_name'] ?? '')) !== '' ? (string)$o['batch_name'] : '— No batch —',
                'groups'     => [],
            ];
        }
        $gkey = implode('|', [
            (int)$o['dept_id'], (int)$o['program_id'], $bid,
            strtolower(trim((string)($o['shift'] ?? ''))),
        ]);
        if (!isset($batches[$bid]['groups'][$gkey])) {
            $batches[$bid]['groups'][$gkey] = [
                'hash'        => md5($gkey),
                'dept_id'     => (int)$o['dept_id'],
                'program_id'  => (int)$o['program_id'],
                'batch_id'    => $bid ?: null,
                'batch_label' => (string)($o['batch_name'] ?? ''),
                'shift'       => (string)($o['shift'] ?? ''),
                'label'       => trim(
                    $o['dept_name']
                    . ($o['program_name'] ? ' — ' . $o['program_name'] : '')
                    . ($o['shift'] ? ' · ' . $o['shift'] : '')
                ),
                'no_program'  => (int)$o['program_id'] <= 0,
                'students'    => 0,
                'osids'       => [],
                'courses'     => [],
            ];
        }
        $g = &$batches[$bid]['groups'][$gkey];
        foreach ($subjects_by_offer[(int)$o['id']] ?? [] as $s) {
            $osid = (int)$s['offer_subject_id'];
            if (preg_match('/\blab\b/i', $s['course_name'] . ' ' . $s['course_code'])) continue; // lab courses excluded
            if ((int)$s['reg_count'] <= 0) continue;          // no registered students
            if (isset($g['osids'][$osid])) continue;
            $g['osids'][$osid] = true;
            $g['courses'][]    = [
                'offer_subject_id' => $osid,
                'course_code'      => (string)$s['course_code'],
                'course_title'     => (string)$s['course_name'],
                'section'          => trim((string)((($o['section'] ?? '') !== '') ? $o['section'] : ($o['shift'] ?? ''))),
                'reg_count'        => (int)$s['reg_count'],
            ];
        }
        unset($g);
    }

    // Unique enrolled student counts per group + drop empty groups/batches
    foreach ($batches as $bid => &$b) {
        foreach ($b['groups'] as $k => &$g) {
            if (!$g['courses']) { unset($b['groups'][$k]); continue; }
            $ids = array_keys($g['osids']);
            $iph = implode(',', array_fill(0, count($ids), '?'));
            $cs  = $db->prepare(
                "SELECT COUNT(DISTINCT r.student_id)
                   FROM co_registrations r
                   JOIN students s ON s.id = r.student_id
                  WHERE r.offer_subject_id IN ($iph) AND s.status = 'Active'"
            );
            $cs->execute($ids);
            $g['students'] = (int)$cs->fetchColumn();
        }
        unset($g);
        $b['groups'] = array_values($b['groups']);
        if (!$b['groups']) unset($batches[$bid]);
    }
    unset($b);

    if (!$batches) {
        $errors[] = 'No offered courses with registered (active) students were found for these filters.';
        return [];
    }
    return array_values($batches);
}

// ── POST ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($exam_name === '') $errors[] = 'Exam name is required.';
    if ($semester === '')  $errors[] = 'Semester is required.';

    if (empty($errors)) {
        $batches = acg_load_batches($f_offer_sem, $f_dept_id, $f_batch_id, $errors);
        if (!$batches) $batches = null;
    }

    // ACTIVE cards with the same exam name already exist? Student PDFs merge
    // the courses of every active card with the same exam name, so a stale
    // earlier generation would mix its old dates/times into the new cards.
    if ($batches !== null && $exam_name !== '') {
        $nx = acg_code_key($exam_name);
        foreach ($db->query('SELECT id, exam_name, semester FROM ac_admit_cards WHERE is_active = 1')->fetchAll() as $c) {
            if (acg_code_key((string)$c['exam_name']) === $nx) $dup_cards[] = $c;
        }
    }

    if ($action === 'generate' && empty($errors) && $batches !== null) {
        // ── Build the generation plan from the posted dates / times ─────
        $plan = []; // group hash => ['group' => …, 'courses' => […]]
        foreach ($batches as $b) {
            foreach ($b['groups'] as $g) {
                if (!in_array($g['hash'], $in_sel, true)) continue;
                if ($g['no_program']) continue;
                $courses = [];
                foreach ($g['courses'] as $c) {
                    $osid = (int)$c['offer_subject_id'];
                    $date = trim((string)($in_dates[$osid] ?? ''));
                    if ($date === '') continue; // no date set — skipped
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
                        $errors[] = trim($c['course_code'] . ' ' . $c['course_title']) . ': invalid exam date.';
                        continue;
                    }
                    $start = trim((string)($in_starts[$osid] ?? ''));
                    $end   = trim((string)($in_ends[$osid] ?? ''));
                    if ($start !== '' && !preg_match('/^\d{1,2}:\d{2}$/', $start)) {
                        $errors[] = trim($c['course_code'] . ' ' . $c['course_title']) . ': invalid start time.';
                        continue;
                    }
                    if ($end !== '' && !preg_match('/^\d{1,2}:\d{2}$/', $end)) {
                        $errors[] = trim($c['course_code'] . ' ' . $c['course_title']) . ': invalid end time.';
                        continue;
                    }
                    if ($start === '') $end = ''; // an end time needs a start time
                    $c['exam_date'] = $date;
                    $c['time_slot'] = acg_slot_label($start, $end);
                    $courses[] = $c;
                }
                if ($courses) $plan[$g['hash']] = ['group' => $g, 'courses' => $courses];
            }
        }

        if (empty($plan) && empty($errors)) {
            $errors[] = 'Set an exam date for at least one course in a selected group.';
        }

        // ── Create the admit cards ───────────────────────────────────────
        if (empty($errors)) {
            $created = 0;
            $rows_n  = 0;
            foreach ($plan as $p) {
                $g = $p['group'];
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

                $courses = $p['courses'];
                usort($courses, static fn($a, $b) =>
                    [$a['exam_date'], $a['course_code']] <=> [$b['exam_date'], $b['course_code']]);

                foreach ($courses as $i => $c) {
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
            flash_set('success', $created . ' admit card(s) created with ' . $rows_n
                . ' course row(s). Every student only sees the courses they are registered in.'
                . ' Courses left without a date were skipped — the report below shows any student'
                . ' still enrolled in a course with no exam date/time.');
            redirect(APP_URL . '/admit-card/missing-schedule.php');
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

<!-- ── Step 1: exam details + filters ── -->
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-header py-3 px-4 fw-semibold">
        <i class="fas fa-layer-group me-2 text-primary"></i>Exam Details &amp; Course Filters
    </div>
    <div class="card-body px-4 py-4">
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="load">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Exam Name <span class="text-danger">*</span></label>
                    <?php if ($exam_name_opts): ?>
                    <select name="exam_name" class="form-select" required>
                        <option value="">— Select Exam —</option>
                        <?php foreach ($exam_name_opts as $opt): ?>
                        <option value="<?= h($opt) ?>" <?= $exam_name === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Active exams from <a href="<?= APP_URL ?>/exam-invigilation/index.php" target="_blank">Exam Invigilation</a>.</div>
                    <?php else: ?>
                    <input type="text" name="exam_name" class="form-control" value="<?= h($exam_name) ?>"
                           placeholder="e.g. Mid Term-1 Exam – Summer 2026" required>
                    <div class="form-text text-warning">No active exam found in
                        <a href="<?= APP_URL ?>/exam-invigilation/index.php" target="_blank">Exam Invigilation</a>
                        — type the exam name manually.</div>
                    <?php endif; ?>
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
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Offer Semester <span class="text-muted fw-normal">(optional filter)</span></label>
                    <select name="offer_semester" class="form-select">
                        <option value="">— All semesters —</option>
                        <?php foreach ($filter_sems as $s): ?>
                        <option value="<?= h($s) ?>" <?= $f_offer_sem === $s ? 'selected' : '' ?>><?= h($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Department <span class="text-muted fw-normal">(optional filter)</span></label>
                    <select name="dept_id" class="form-select">
                        <option value="">— All departments —</option>
                        <?php foreach ($filter_depts as $d): ?>
                        <option value="<?= (int)$d['id'] ?>" <?= $f_dept_id === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Batch <span class="text-muted fw-normal">(optional filter)</span></label>
                    <select name="batch_id" class="form-select">
                        <option value="">— All batches —</option>
                        <?php foreach ($filter_batches as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= $f_batch_id === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-text mt-2">
                Loads every offered course with <strong>registered (active) students</strong> from the
                Course Offer module, grouped <strong>batch wise</strong>. You then set the exam
                <strong>date</strong> and <strong>time</strong> per course and generate the admit cards.
            </div>
            <button type="submit" class="btn btn-primary mt-3" style="border-radius:10px;">
                <i class="fas fa-search me-1"></i> Load Registered Courses (Batch Wise)
            </button>
        </form>
    </div>
</div>

<?php if ($batches !== null): ?>
<?php
    $total_groups  = 0;
    $total_courses = 0;
    foreach ($batches as $b) {
        $total_groups += count($b['groups']);
        foreach ($b['groups'] as $g) $total_courses += count($g['courses']);
    }
?>
<!-- ── Step 2: set dates/times batch wise & generate ── -->
<form method="POST" id="generate_form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="generate">
    <input type="hidden" name="exam_name" value="<?= h($exam_name) ?>">
    <input type="hidden" name="semester" value="<?= h($semester) ?>">
    <input type="hidden" name="is_active" value="<?= (int)$is_active ?>">
    <input type="hidden" name="offer_semester" value="<?= h($f_offer_sem) ?>">
    <input type="hidden" name="dept_id" value="<?= (int)$f_dept_id ?>">
    <input type="hidden" name="batch_id" value="<?= (int)$f_batch_id ?>">

    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <h5 class="mb-0 fw-semibold"><i class="fas fa-calendar-alt me-2 text-muted"></i>Set Exam Dates &amp; Times</h5>
        <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle"><?= count($batches) ?> batch(es)</span>
        <span class="badge bg-light text-dark border"><?= $total_groups ?> class group(s)</span>
        <span class="badge bg-light text-dark border"><?= $total_courses ?> registered course(s)</span>
        <span class="ms-auto small">
            <button type="button" class="btn btn-link btn-sm p-0" id="sel_all">Select all</button>
            <span class="text-muted">/</span>
            <button type="button" class="btn btn-link btn-sm p-0" id="sel_none">none</button>
        </span>
    </div>

    <!-- ── CSV schedule fill ── -->
    <div class="card mb-3" style="border-radius:12px;">
        <div class="card-body py-3 px-4">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="fw-semibold small"><i class="fas fa-file-csv me-1 text-success"></i>Fill from CSV</span>
                <input type="file" id="csv_file" class="form-control form-control-sm" style="max-width:320px;" accept=".csv,.txt,text/csv,text/plain">
                <button type="button" class="btn btn-sm btn-outline-success" id="csv_apply" style="border-radius:8px;">
                    <i class="fas fa-fill-drip me-1"></i>Apply CSV
                </button>
                <span class="small text-muted" id="csv_status"></span>
            </div>
            <div class="form-text mt-1">
                Columns: <strong>Batch, Course Code, Course Title, Exam Date, Start Time, End Time</strong>
                (comma or tab separated, first row = header). Rows are matched to the loaded courses by
                <strong>batch + course code</strong> and the date/time fields below are filled in.
                Leave the Batch cell empty to apply a row to every batch. Nothing is generated until you
                review and press <strong>Generate Admit Cards</strong>.
            </div>
        </div>
    </div>

    <?php if ($dup_cards): ?>
    <div class="alert alert-danger small">
        <strong><?= count($dup_cards) ?> ACTIVE admit card(s) with this exam name already exist.</strong>
        Student PDFs merge the courses of every active card with the same exam name, so generating
        again now would mix the old dates/times into the new cards. Deactivate or delete these first:
        <ul class="mb-0 ps-3">
            <?php foreach ($dup_cards as $c): ?>
            <li><a href="<?= APP_URL ?>/admit-card/view.php?id=<?= (int)$c['id'] ?>">Card #<?= (int)$c['id'] ?></a>
                — <?= h($c['exam_name']) ?> (<?= h($c['semester']) ?>)</li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php foreach ($batches as $bi => $b): ?>
    <div class="card mb-4" style="border-radius:12px;" data-batch="<?= $bi ?>" data-batch-name="<?= h(acg_code_key($b['batch_name'])) ?>">
        <div class="card-header py-3 px-4 d-flex flex-wrap align-items-center gap-2">
            <span class="fw-bold"><i class="fas fa-users me-2 text-primary"></i>Batch <?= h($b['batch_name']) ?></span>
            <span class="badge bg-secondary"><?= count($b['groups']) ?> class group(s)</span>
            <div class="ms-auto d-flex flex-wrap align-items-center gap-2 small">
                <span class="text-muted">Quick fill:</span>
                <input type="date" class="form-control form-control-sm qf-date" style="width:auto;">
                <input type="time" class="form-control form-control-sm qf-start" style="width:auto;" title="Start time">
                <input type="time" class="form-control form-control-sm qf-end" style="width:auto;" title="End time">
                <button type="button" class="btn btn-sm btn-outline-primary qf-apply" style="border-radius:8px;">
                    <i class="fas fa-fill-drip me-1"></i>Apply to batch
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <?php foreach ($b['groups'] as $g): ?>
            <?php $checked = !$was_generate || in_array($g['hash'], $in_sel, true); ?>
            <div class="border-bottom">
                <div class="px-4 py-2 d-flex flex-wrap align-items-center gap-2 bg-light-subtle">
                    <?php if (!$g['no_program']): ?>
                    <input type="checkbox" class="form-check-input mt-0 grp-sel" name="sel[]"
                           value="<?= h($g['hash']) ?>" <?= $checked ? 'checked' : '' ?>>
                    <?php endif; ?>
                    <span class="fw-semibold small"><?= h($g['label']) ?></span>
                    <span class="badge bg-light text-dark border"><?= count($g['courses']) ?> course(s)</span>
                    <span class="badge bg-light text-dark border"><?= (int)$g['students'] ?> enrolled student(s)</span>
                    <?php if ($g['no_program']): ?>
                    <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">No program — cannot generate</span>
                    <?php endif; ?>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0" style="font-size:.85rem;">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4" style="width:110px;">Code</th>
                                <th>Course Title</th>
                                <th class="text-center" style="width:80px;">Students</th>
                                <th style="width:160px;">Exam Date</th>
                                <th style="width:130px;">Start Time</th>
                                <th class="pe-4" style="width:130px;">End Time</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($g['courses'] as $c): $osid = (int)$c['offer_subject_id']; ?>
                            <tr data-code="<?= h(acg_code_key($c['course_code'])) ?>">
                                <td class="ps-4"><span class="badge bg-light text-dark border" style="font-family:monospace;"><?= h($c['course_code']) ?></span></td>
                                <td>
                                    <?= h($c['course_title']) ?>
                                    <?php if ($c['section'] !== ''): ?>
                                    <span class="text-muted small">· <?= h($c['section']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?= (int)$c['reg_count'] ?></td>
                                <td>
                                    <input type="date" name="exam_date[<?= $osid ?>]" class="form-control form-control-sm ac-date"
                                           value="<?= h((string)($in_dates[$osid] ?? '')) ?>">
                                </td>
                                <td>
                                    <input type="time" name="start_time[<?= $osid ?>]" class="form-control form-control-sm ac-start"
                                           value="<?= h((string)($in_starts[$osid] ?? '')) ?>">
                                </td>
                                <td class="pe-4">
                                    <input type="time" name="end_time[<?= $osid ?>]" class="form-control form-control-sm ac-end"
                                           value="<?= h((string)($in_ends[$osid] ?? '')) ?>">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="d-flex gap-2 mb-5">
        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
            <i class="fas fa-id-card me-1"></i> Generate Admit Cards
        </button>
        <a href="<?= APP_URL ?>/admit-card/create.php" class="btn btn-light" style="border-radius:10px;">Start Over</a>
        <span class="text-muted small align-self-center">Courses without an exam date are skipped.</span>
    </div>
</form>

<script>
(function () {
    var boxes = document.querySelectorAll('.grp-sel');
    var all   = document.getElementById('sel_all');
    var none  = document.getElementById('sel_none');
    if (all)  all.addEventListener('click',  function () { boxes.forEach(function (b) { b.checked = true;  }); });
    if (none) none.addEventListener('click', function () { boxes.forEach(function (b) { b.checked = false; }); });

    // Batch-level quick fill: copy the header date/time to every course
    // row of the batch (only overwrites empty fields when nothing is set
    // in the quick-fill input).
    document.querySelectorAll('[data-batch]').forEach(function (card) {
        var btn = card.querySelector('.qf-apply');
        if (!btn) return;
        btn.addEventListener('click', function () {
            var d = card.querySelector('.qf-date').value;
            var s = card.querySelector('.qf-start').value;
            var e = card.querySelector('.qf-end').value;
            if (d) card.querySelectorAll('.ac-date').forEach(function (i)  { i.value = d; });
            if (s) card.querySelectorAll('.ac-start').forEach(function (i) { i.value = s; });
            if (e) card.querySelectorAll('.ac-end').forEach(function (i)   { i.value = e; });
        });
    });

    document.getElementById('generate_form').addEventListener('submit', function (e) {
        var any = false;
        boxes.forEach(function (b) { if (b.checked) any = true; });
        if (!any) { e.preventDefault(); alert('Select at least one class group to generate.'); return; }
        var dated = false;
        document.querySelectorAll('.ac-date').forEach(function (i) { if (i.value) dated = true; });
        if (!dated) { e.preventDefault(); alert('Set an exam date for at least one course.'); }
    });

    // ── CSV schedule fill ──────────────────────────────────────────────
    // Columns: Batch | Course Code | Course Title | Exam Date | Start Time | End Time
    function csvNorm(s) { return String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, ''); }
    function csvPad(n)  { n = String(parseInt(n, 10)); return n.length < 2 ? '0' + n : n; }

    function csvParse(text) {
        text = String(text || '').replace(/^\uFEFF/, '');
        var first = text.split(/\r?\n/, 1)[0] || '';
        var d = first.indexOf('\t') !== -1 ? '\t' : ',';
        var rows = [], row = [], cur = '', q = false;
        for (var i = 0; i < text.length; i++) {
            var ch = text[i];
            if (q) {
                if (ch === '"') { if (text[i + 1] === '"') { cur += '"'; i++; } else q = false; }
                else cur += ch;
            } else if (ch === '"') q = true;
            else if (ch === d) { row.push(cur); cur = ''; }
            else if (ch === '\n' || ch === '\r') {
                if (ch === '\r' && text[i + 1] === '\n') i++;
                row.push(cur); cur = '';
                if (row.length > 1 || row[0].trim() !== '') rows.push(row);
                row = [];
            } else cur += ch;
        }
        row.push(cur);
        if (row.length > 1 || row[0].trim() !== '') rows.push(row);
        return rows;
    }

    function csvDate(s) {
        s = String(s || '').trim();
        if (!s) return '';
        var m;
        if ((m = s.match(/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/)))  return m[1] + '-' + csvPad(m[2]) + '-' + csvPad(m[3]);
        if ((m = s.match(/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/)))  return m[3] + '-' + csvPad(m[2]) + '-' + csvPad(m[1]); // DD/MM/YYYY
        var mo = {jan:1,feb:2,mar:3,apr:4,may:5,jun:6,jul:7,aug:8,sep:9,oct:10,nov:11,dec:12};
        if ((m = s.match(/^(\d{1,2})[\s-]+([a-z]{3,})[\s,-]+(\d{4})$/i)) && mo[m[2].slice(0, 3).toLowerCase()])
            return m[3] + '-' + csvPad(mo[m[2].slice(0, 3).toLowerCase()]) + '-' + csvPad(m[1]); // 5 Jan 2026
        if ((m = s.match(/^([a-z]{3,})[\s-]+(\d{1,2})[\s,-]+(\d{4})$/i)) && mo[m[1].slice(0, 3).toLowerCase()])
            return m[3] + '-' + csvPad(mo[m[1].slice(0, 3).toLowerCase()]) + '-' + csvPad(m[2]); // Jan 5, 2026
        return '';
    }

    function csvTime(s) {
        s = String(s || '').trim();
        if (!s) return '';
        var m = s.match(/^(\d{1,2})(?:[:.](\d{2}))?(?:[:.]\d{2})?\s*(AM|PM)?$/i);
        if (!m) return '';
        var h = parseInt(m[1], 10), mi = m[2] || '00', ap = (m[3] || '').toUpperCase();
        if (ap === 'PM' && h < 12) h += 12;
        if (ap === 'AM' && h === 12) h = 0;
        if (h > 23 || parseInt(mi, 10) > 59) return '';
        return csvPad(h) + ':' + mi;
    }

    var csvBtn = document.getElementById('csv_apply');
    if (csvBtn) csvBtn.addEventListener('click', function () {
        var input  = document.getElementById('csv_file');
        var status = document.getElementById('csv_status');
        var f = input.files && input.files[0];
        if (!f) { alert('Choose a CSV file first.'); return; }
        var reader = new FileReader();
        reader.onload = function () {
            var rows = csvParse(reader.result);
            if (!rows.length) { status.textContent = 'Empty file.'; return; }
            var start = 0;
            var head = rows[0].map(csvNorm).join('|');
            if (head.indexOf('coursecode') !== -1 || head.indexOf('examdate') !== -1 || head.indexOf('batch') !== -1) start = 1;
            var applied = 0, badDate = 0, unmatched = [];
            for (var r = start; r < rows.length; r++) {
                var cells = rows[r];
                var batch = csvNorm(cells[0]);
                var code  = csvNorm(cells[1]);
                if (!code) continue;
                var dateV  = csvDate(cells[3]);
                var startV = csvTime(cells[4]);
                var endV   = csvTime(cells[5]);
                if (String(cells[3] || '').trim() !== '' && !dateV) badDate++;
                var hit = 0;
                document.querySelectorAll('[data-batch]').forEach(function (card) {
                    if (batch) {
                        var bn = card.getAttribute('data-batch-name') || '';
                        if (bn !== batch && !(batch.length >= 3 && bn.indexOf(batch) !== -1)) return;
                    }
                    card.querySelectorAll('tr[data-code="' + code + '"]').forEach(function (tr) {
                        if (dateV)  tr.querySelector('.ac-date').value  = dateV;
                        if (startV) tr.querySelector('.ac-start').value = startV;
                        if (endV)   tr.querySelector('.ac-end').value   = endV;
                        hit++;
                    });
                });
                if (hit) applied++;
                else unmatched.push(((cells[0] || '').trim() ? (cells[0] || '').trim() + ' / ' : '') + (cells[1] || '').trim());
            }
            var msg = applied + ' row(s) applied.';
            if (badDate)          msg += ' ' + badDate + ' row(s) had an unreadable date.';
            if (unmatched.length) msg += ' Unmatched: ' + unmatched.slice(0, 10).join(', ') + (unmatched.length > 10 ? ' …' : '') + '.';
            status.textContent = msg;
        };
        reader.readAsText(f);
    });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
