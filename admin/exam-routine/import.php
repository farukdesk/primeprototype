<?php
/**
 * Exam Routine – CSV import with preview.
 *
 * Step 1: choose an active exam and upload (or paste) a CSV.
 * Step 2: every row is fuzzy-matched server-side — department (aliases such
 *         as CSE, EEE, Bangla, FDAE, Civil/CE, acronyms and parenthetical
 *         short names), program (BBA, CSE…), batch ("59" = "59th"; a list
 *         such as "68, 14, 67, 66" applies the row to every listed batch),
 *         shift (Day / Night from the course offer),
 *         course code / course title against the active course offers, and
 *         the exam date / time in many common spellings — and shown in a
 *         preview table with a per-row status.
 * Step 3: confirm — the selected matched rows are imported, grouped into one
 *         routine per course offer under the chosen exam. An existing routine
 *         for the same exam + offer is appended to; duplicate subjects are
 *         skipped.
 *
 * Expected CSV header (order free, extra columns ignored):
 *   Department, Program, Batch, Shift, Course Code, Course Title,
 *   Teacher, Date, Start Time, End Time, Room, Remarks
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('exam-routine', 'can_create');
require_once __DIR__ . '/helpers.php';

$page_title = 'Import Exam Routine (CSV)';
$exams      = er_active_exams();
$errors     = [];
$preview    = null;
$exam_id    = 0;

/** Map a CSV header cell to an internal field name (fuzzy). */
function er_csv_col(string $header): ?string
{
    static $map = null;
    if ($map === null) {
        $defs = [
            'department'   => ['department', 'dept'],
            'program'      => ['program', 'programme', 'prog'],
            'batch'        => ['batch'],
            'shift'        => ['shift', 'day night', 'day or night'],
            'course_code'  => ['course code', 'code', 'coursecode'],
            'course_title' => ['course title', 'course name', 'course', 'title', 'subject'],
            'teacher'      => ['course teacher', 'teacher', 'faculty', 'instructor'],
            'date'         => ['exam date', 'date'],
            'start'        => ['start time', 'start', 'time', 'exam time'],
            'end'          => ['end time', 'end'],
            'room'         => ['room', 'room no', 'room number'],
            'remarks'      => ['remarks', 'remark', 'notes', 'note'],
        ];
        $map = [];
        foreach ($defs as $field => $aliases) {
            foreach ($aliases as $a) $map[$a] = $field;
        }
    }
    return $map[er_norm($header)] ?? null;
}

/** Split CSV text into rows of cells (BOM stripped, blank lines skipped). */
function er_parse_csv_text(string $text): array
{
    $text = (string)preg_replace('/^\xEF\xBB\xBF/', '', $text);
    $rows = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        if (trim($line) === '') continue;
        $rows[] = str_getcsv($line, ',', '"', '');
    }
    return $rows;
}

/** Match every CSV data row against the university data → preview rows. */
function er_build_preview(array $csv, array &$errors): array
{
    if (count($csv) < 2) {
        $errors[] = 'The CSV needs a header row plus at least one data row.';
        return [];
    }

    $fields = [];
    foreach ($csv[0] as $i => $cell) {
        $f = er_csv_col((string)$cell);
        if ($f !== null && !in_array($f, $fields, true)) $fields[$i] = $f;
    }
    if (!in_array('department', $fields, true)) $errors[] = 'The CSV must contain a "Department" column.';
    if (!in_array('course_code', $fields, true) && !in_array('course_title', $fields, true)) {
        $errors[] = 'The CSV must contain a "Course Code" or "Course Title" column.';
    }
    if (!in_array('date', $fields, true)) $errors[] = 'The CSV must contain a "Date" column.';
    if ($errors) return [];

    $departments = db()->query('SELECT id, name FROM dept_departments WHERE is_active = 1')->fetchAll();
    $batches     = db()->query('SELECT id, name FROM student_batches WHERE is_active = 1')->fetchAll();
    $prog_cache  = [];

    $out = [];
    foreach (array_slice($csv, 1) as $line_no => $cells) {
        $row = [
            'department' => '', 'program' => '', 'batch' => '', 'shift' => '',
            'course_code' => '', 'course_title' => '', 'teacher' => '',
            'date' => '', 'start' => '', 'end' => '', 'room' => '', 'remarks' => '',
        ];
        foreach ($fields as $i => $f) $row[$f] = trim((string)($cells[$i] ?? ''));
        if (implode('', $row) === '') continue;

        $p = ['line' => $line_no + 2, 'raw' => $row, 'ok' => false, 'messages' => [], 'resolved' => []];

        // ── Department ──
        [$dept, $amb] = er_match_by_name($departments, $row['department']);
        if (!$dept) {
            $p['messages'][] = $amb
                ? 'Department "' . $row['department'] . '" is ambiguous.'
                : 'Department "' . $row['department'] . '" not recognised.';
            $out[] = $p;
            continue;
        }
        $p['resolved']['dept'] = $dept['name'];

        // ── Program (optional – narrows the offer search) ──
        $program = null;
        if ($row['program'] !== '') {
            $did = (int)$dept['id'];
            if (!isset($prog_cache[$did])) {
                $st = db()->prepare('SELECT id, program_name FROM dept_academic_programs WHERE dept_id = ? AND is_active = 1');
                $st->execute([$did]);
                $prog_cache[$did] = $st->fetchAll();
            }
            [$program, $pamb] = er_match_by_name($prog_cache[$did], $row['program'], 'program_name');
            if (!$program) {
                $p['messages'][] = ($pamb
                    ? 'Program "' . $row['program'] . '" is ambiguous'
                    : 'Program "' . $row['program'] . '" not recognised')
                    . ' — searching all programs of the department.';
            } else {
                $p['resolved']['program'] = $program['program_name'];
            }
        }

        // ── Batch(es) (optional – narrows the offer search) ──
        // Several batches may share one row ("68, 14, 67, 66") — the same
        // routine row is then applied to every listed batch.
        $batch_ids   = [];
        $batch_names = [];
        if ($row['batch'] !== '') {
            foreach (preg_split('/[,;\/&+]+|\band\b/i', $row['batch']) as $tok) {
                $tok = trim((string)$tok);
                if ($tok === '') continue;
                [$b, $bamb] = er_match_batch($batches, $tok);
                if ($b) {
                    if (!in_array((int)$b['id'], $batch_ids, true)) {
                        $batch_ids[]   = (int)$b['id'];
                        $batch_names[] = (string)$b['name'];
                    }
                } else {
                    $p['messages'][] = ($bamb
                        ? 'Batch "' . $tok . '" is ambiguous'
                        : 'Batch "' . $tok . '" not recognised')
                        . ' — this batch was skipped.';
                }
            }
            if ($batch_ids) $p['resolved']['batch'] = implode(', ', $batch_names);
            else $p['messages'][] = 'No listed batch recognised — searching all batches.';
        }

        // ── Candidate active offers ──
        $sql  = "SELECT o.id, o.section, o.shift, o.semester, o.academic_intake,
                        b.name AS batch_name, p.program_name
                   FROM co_offers o
              LEFT JOIN student_batches b        ON b.id = o.batch_id
              LEFT JOIN dept_academic_programs p ON p.id = o.program_id
                  WHERE o.status = 'active' AND o.dept_id = ?";
        $args = [(int)$dept['id']];
        if ($program) { $sql .= ' AND o.program_id = ?'; $args[] = (int)$program['id']; }
        if ($batch_ids) {
            $sql .= ' AND o.batch_id IN (' . implode(',', array_fill(0, count($batch_ids), '?')) . ')';
            $args = array_merge($args, $batch_ids);
        }
        $st = db()->prepare($sql);
        $st->execute($args);
        $offers = $st->fetchAll();
        if (!$offers) {
            $p['messages'][] = 'No active course offer found for this department / program / batch.';
            $out[] = $p;
            continue;
        }

        // ── Shift(s) (Day / Night) narrow when they match at least one offer ──
        // "Day/Night" or "Day, Night" keeps the offers of both shifts.
        $shift_toks = [];
        if ($row['shift'] !== '') {
            foreach (preg_split('/[,;\/&+]+|\band\b/i', $row['shift']) as $tok) {
                $t = er_norm((string)$tok);
                if ($t !== '') $shift_toks[] = $t;
            }
            $with = array_values(array_filter($offers, function ($o) use ($shift_toks) {
                $os = er_norm((string)($o['shift'] ?? ''));
                if ($os === '') return false;
                foreach ($shift_toks as $sh) {
                    if ($os === $sh || $os[0] === $sh) return true; // "d" → Day, "n" → Night
                }
                return false;
            }));
            if ($with) $offers = $with;
            elseif ($shift_toks) $p['messages'][] = 'Shift "' . $row['shift'] . '" matched no offer — shift ignored.';
        }

        // ── Subjects of the candidate offers ──
        $ph  = implode(',', array_fill(0, count($offers), '?'));
        $sst = db()->prepare(
            "SELECT cos.id AS offer_subject_id, cos.offer_id, c.course_code, c.course_name,
                    (SELECT GROUP_CONCAT(f.name ORDER BY t.sort_order SEPARATOR ', ')
                       FROM co_offer_subject_teachers t
                       JOIN dept_faculty f ON f.id = t.faculty_id
                      WHERE t.offer_subject_id = cos.id) AS teachers
               FROM co_offer_subjects cos
               JOIN course_curriculum c ON c.id = cos.curriculum_id
              WHERE cos.offer_id IN ($ph)"
        );
        $sst->execute(array_map(fn($o) => (int)$o['id'], $offers));
        $subjects = $sst->fetchAll();

        $codeKey = static fn($s) => strtolower((string)preg_replace('/[^a-z0-9]+/i', '', (string)$s));

        $matches = [];
        if ($row['course_code'] !== '') {
            $ck = $codeKey($row['course_code']);
            if ($ck !== '') {
                $matches = array_values(array_filter($subjects, fn($s) => $codeKey($s['course_code']) === $ck));
            }
        }
        if (!$matches && $row['course_title'] !== '') {
            $tn      = er_norm($row['course_title']);
            $matches = array_values(array_filter($subjects, fn($s) => er_norm((string)$s['course_name']) === $tn));
            if (!$matches && strlen($tn) >= 5) {
                $matches = array_values(array_filter($subjects, function ($s) use ($tn) {
                    $n = er_norm((string)$s['course_name']);
                    if (strpos($n, $tn) !== false || strpos($tn, $n) !== false) return true;
                    similar_text($n, $tn, $pct);
                    return $pct >= 85;
                }));
            }
        }
        if (!$matches) {
            $p['messages'][] = 'Course "' . trim($row['course_code'] . ' ' . $row['course_title'])
                . '" not found in the matching offer(s).';
            $out[] = $p;
            continue;
        }
        // One preview row per matching offer when several batches / shifts
        // were listed — the same routine row is applied to each of them.
        $multi     = count($batch_ids) > 1 || count($shift_toks) > 1;
        $per_offer = [];
        foreach ($matches as $m) {
            $oid = (int)$m['offer_id'];
            if (!isset($per_offer[$oid])) $per_offer[$oid] = $m;
        }
        $per_offer = array_values($per_offer);
        if (!$multi && count($per_offer) > 1) {
            $p['messages'][] = count($per_offer) . ' offers carry this course — the first match was used. '
                . 'Add Batch / Shift columns to disambiguate.';
            $per_offer = [$per_offer[0]];
        }

        // ── Date & time ──
        $date = er_parse_date($row['date']);
        if (!$date) {
            $p['messages'][] = 'Could not understand the date "' . $row['date'] . '".';
            $out[] = $p;
            continue;
        }
        $startRaw = $row['start'];
        $endRaw   = $row['end'];
        if ($endRaw === '' && preg_match('/^(.+?)\s*(?:-|\x{2013}|to)\s*(.+)$/iu', $startRaw, $m)) {
            $startRaw = $m[1];
            $endRaw   = $m[2];
        }
        $start = $startRaw !== '' ? er_parse_time($startRaw) : null;
        $end   = $endRaw   !== '' ? er_parse_time($endRaw)   : null;
        if ($startRaw !== '' && $start === null) $p['messages'][] = 'Start time "' . $row['start'] . '" not understood — left blank.';
        if ($endRaw   !== '' && $end   === null) $p['messages'][] = 'End time "' . $endRaw . '" not understood — left blank.';
        if ($start && $end && $end <= $start) {
            $p['messages'][] = 'End time is not after start time — times left blank.';
            $start = $end = null;
        }

        foreach ($per_offer as $subject) {
            $offer = null;
            foreach ($offers as $o) {
                if ((int)$o['id'] === (int)$subject['offer_id']) { $offer = $o; break; }
            }
            $q = $p;
            $q['ok'] = true;
            $q['resolved'] = array_merge($q['resolved'], [
                'offer_id'         => (int)$subject['offer_id'],
                'offer_subject_id' => (int)$subject['offer_subject_id'],
                'offer_label'      => trim(
                    ($offer && $offer['batch_name'] ? 'Batch ' . $offer['batch_name'] : 'Offer #' . $subject['offer_id'])
                    . ($offer && $offer['semester'] ? ' · ' . $offer['semester'] : '')
                    . ($offer && $offer['shift']    ? ' · ' . $offer['shift'] : '')
                ),
                'program'          => $p['resolved']['program'] ?? (string)($offer['program_name'] ?? ''),
                'course_code'      => (string)$subject['course_code'],
                'course_title'     => (string)$subject['course_name'],
                'teachers'         => (string)($subject['teachers'] ?? ''),
                'students'         => er_registered_count((int)$subject['offer_subject_id']),
                'date'             => $date,
                'start'            => $start,
                'end'              => $end,
                'room'             => $row['room'],
                'remarks'          => $row['remarks'],
            ]);
            $out[] = $q;
        }
    }

    if (!$out) $errors[] = 'No data rows found in the CSV.';
    return $out;
}

// ── POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action  = $_POST['action'] ?? '';
    $exam_id = (int)($_POST['exam_id'] ?? 0);

    $exam = null;
    if ($exam_id > 0) {
        $st = db()->prepare('SELECT id, exam_name, exam_year FROM ei_exams WHERE id = ? AND is_active = 1');
        $st->execute([$exam_id]);
        $exam = $st->fetch();
    }
    if (!$exam) $errors[] = 'Please choose an active exam.';

    if ($action === 'preview' && empty($errors)) {
        $text = '';
        if (!empty($_FILES['csv_file']['tmp_name']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
            $text = (string)file_get_contents($_FILES['csv_file']['tmp_name']);
        } elseif (trim((string)($_POST['csv_text'] ?? '')) !== '') {
            $text = (string)$_POST['csv_text'];
        }
        if (trim($text) === '') {
            $errors[] = 'Upload a CSV file or paste CSV rows.';
        } else {
            $preview = er_build_preview(er_parse_csv_text($text), $errors);
        }
    }

    if ($action === 'import' && empty($errors)) {
        $sel   = (array)($_POST['sel'] ?? []);
        $osids = (array)($_POST['row_subject'] ?? []);
        $oids  = (array)($_POST['row_offer'] ?? []);
        $dts   = (array)($_POST['row_date'] ?? []);
        $sts   = (array)($_POST['row_start'] ?? []);
        $ens   = (array)($_POST['row_end'] ?? []);
        $rooms = (array)($_POST['row_room'] ?? []);
        $rmks  = (array)($_POST['row_remarks'] ?? []);

        $time_ok = static fn(string $v): bool =>
            $v === '' || (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v);

        $by_offer = [];
        foreach ($sel as $i) {
            $i    = (int)$i;
            $osid = (int)($osids[$i] ?? 0);
            $oid  = (int)($oids[$i] ?? 0);
            $date = trim((string)($dts[$i] ?? ''));
            if ($osid <= 0 || $oid <= 0) continue;
            $d = DateTimeImmutable::createFromFormat('Y-m-d', $date);
            if (!$d || $d->format('Y-m-d') !== $date) continue;
            $start = trim((string)($sts[$i] ?? ''));
            $end   = trim((string)($ens[$i] ?? ''));
            if (!$time_ok($start)) $start = '';
            if (!$time_ok($end))   $end = '';
            if ($start !== '' && $end !== '' && $end <= $start) { $start = ''; $end = ''; }
            $by_offer[$oid][] = [
                'osid'    => $osid,
                'date'    => $date,
                'start'   => $start,
                'end'     => $end,
                'room'    => trim((string)($rooms[$i] ?? '')),
                'remarks' => trim((string)($rmks[$i] ?? '')),
            ];
        }
        if (!$by_offer) $errors[] = 'No valid rows were selected for import.';

        if (empty($errors)) {
            $user     = auth_user();
            $imported = 0;
            $skipped  = 0;
            $new_routines = 0;

            foreach ($by_offer as $oid => $rows) {
                $st = db()->prepare('SELECT * FROM co_offers WHERE id = ?');
                $st->execute([$oid]);
                $offer = $st->fetch();
                if (!$offer) continue;

                // Authoritative subject data comes from the DB, never the client.
                $ph  = implode(',', array_fill(0, count($rows), '?'));
                $sst = db()->prepare(
                    "SELECT cos.id, c.course_code, c.course_name
                       FROM co_offer_subjects cos
                       JOIN course_curriculum c ON c.id = cos.curriculum_id
                      WHERE cos.offer_id = ? AND cos.id IN ($ph)"
                );
                $sst->execute(array_merge([$oid], array_map(fn($r) => $r['osid'], $rows)));
                $smap = [];
                foreach ($sst->fetchAll() as $s) $smap[(int)$s['id']] = $s;

                // Reuse the routine of this exam + offer, or create a new one.
                $st = db()->prepare('SELECT id FROM exam_routines WHERE exam_id = ? AND offer_id = ? LIMIT 1');
                $st->execute([$exam_id, $oid]);
                $rid = (int)($st->fetchColumn() ?: 0);
                if ($rid <= 0) {
                    db()->prepare(
                        'INSERT INTO exam_routines
                           (exam_id, offer_id, dept_id, program_id, batch_id,
                            semester, academic_intake, shift, section, notes, created_by)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                    )->execute([
                        $exam_id, $oid, (int)$offer['dept_id'],
                        !empty($offer['program_id']) ? (int)$offer['program_id'] : null,
                        !empty($offer['batch_id'])   ? (int)$offer['batch_id']   : null,
                        ($offer['semester']        ?? '') !== '' ? $offer['semester']        : null,
                        ($offer['academic_intake'] ?? '') !== '' ? $offer['academic_intake'] : null,
                        ($offer['shift']           ?? '') !== '' ? $offer['shift']           : null,
                        ($offer['section']         ?? '') !== '' ? $offer['section']         : null,
                        null, (int)$user['id'],
                    ]);
                    $rid = (int)db()->lastInsertId();
                    $new_routines++;
                }

                $st = db()->prepare('SELECT offer_subject_id FROM exam_routine_items WHERE routine_id = ?');
                $st->execute([$rid]);
                $existing = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
                $st = db()->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM exam_routine_items WHERE routine_id = ?');
                $st->execute([$rid]);
                $sort = (int)$st->fetchColumn() + 1;

                $ist = db()->prepare(
                    'INSERT INTO exam_routine_items
                       (routine_id, offer_subject_id, course_code, course_title, student_count,
                        exam_date, start_time, end_time, room_number, remarks, sort_order)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                );
                foreach ($rows as $r) {
                    if (!isset($smap[$r['osid']])) continue;
                    if (in_array($r['osid'], $existing, true)) { $skipped++; continue; }
                    $ist->execute([
                        $rid, $r['osid'],
                        $smap[$r['osid']]['course_code'], $smap[$r['osid']]['course_name'],
                        er_registered_count($r['osid']),
                        $r['date'],
                        $r['start'] !== '' ? $r['start'] . ':00' : null,
                        $r['end']   !== '' ? $r['end']   . ':00' : null,
                        $r['room']    !== '' ? $r['room']    : null,
                        $r['remarks'] !== '' ? $r['remarks'] : null,
                        $sort++,
                    ]);
                    $existing[] = $r['osid'];
                    $imported++;
                }
            }

            $msg = 'Imported <strong>' . $imported . '</strong> routine row(s)'
                 . ($new_routines > 0 ? ' (' . $new_routines . ' new routine(s) created)' : '')
                 . '.' . ($skipped > 0 ? ' ' . $skipped . ' duplicate row(s) skipped.' : '');
            flash_set('success', $msg);
            redirect(APP_URL . '/exam-routine/index.php?exam_id=' . $exam_id);
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/exam-routine/index.php">Exam Routine</a></li>
            <li class="breadcrumb-item active">Import CSV</li>
        </ol>
    </nav>
</div>

<?php flash_show(); ?>

<?php if ($errors): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ── Step 1: upload ── -->
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-file-csv me-2 text-muted"></i>Upload CSV</h6>
    </div>
    <div class="card-body p-4">
        <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="preview">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Exam <span class="text-danger">*</span></label>
                    <select name="exam_id" class="form-select" required>
                        <option value="">— Select Active Exam —</option>
                        <?php foreach ($exams as $e): ?>
                        <option value="<?= $e['id'] ?>" <?= $exam_id === (int)$e['id'] ? 'selected' : '' ?>>
                            <?= h($e['exam_name']) ?><?= $e['exam_year'] ? ' – ' . h($e['exam_year']) : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">CSV file</label>
                    <input type="file" name="csv_file" class="form-control" accept=".csv,text/csv,text/plain">
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">…or paste CSV rows</label>
                    <textarea name="csv_text" class="form-control font-monospace" rows="5"
                              placeholder="Department,Program,Batch,Shift,Course Code,Course Title,Date,Start Time,End Time&#10;CSE,CSE,59,Day,CSE-101,Introduction to Programming,05/01/2026,9:30 AM,12:30 PM"></textarea>
                </div>
            </div>
            <div class="form-text mt-2">
                Expected columns (order free, extra columns ignored):
                <code>Department, Program, Batch, Shift, Course Code, Course Title, Teacher, Date, Start Time, End Time, Room, Remarks</code>.
                Short names are understood — e.g. <strong>CSE</strong>, <strong>EEE</strong>, <strong>Bangla</strong>,
                <strong>FDAE</strong>, <strong>Civil</strong>/<strong>CE</strong>, <strong>BBA</strong>;
                shift is <strong>Day</strong> or <strong>Night</strong> (also <strong>D</strong>/<strong>N</strong>);
                batch <strong>59</strong> matches “59th”; several batches in one cell
                (e.g. <strong>“68, 14, 67, 66”</strong>) apply the same row to every listed batch;
                dates and times in most common formats are accepted.
                The teacher, student count, course code and title always come from the matched course offer.
            </div>
            <button type="submit" class="btn btn-primary mt-3" style="border-radius:10px;">
                <i class="fas fa-search me-1"></i> Preview
            </button>
        </form>
    </div>
</div>

<?php if ($preview !== null): ?>
<?php
    $ok_count  = count(array_filter($preview, static fn($p) => $p['ok']));
    $bad_count = count($preview) - $ok_count;
?>
<!-- ── Step 2: preview ── -->
<form method="POST" id="import_form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="import">
    <input type="hidden" name="exam_id" value="<?= $exam_id ?>">

    <div class="card mb-4" style="border-radius:12px;">
        <div class="card-header py-3 px-4 d-flex flex-wrap align-items-center gap-2">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-eye me-2 text-muted"></i>Preview</h6>
            <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle"><?= $ok_count ?> matched</span>
            <?php if ($bad_count > 0): ?>
            <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle"><?= $bad_count ?> not matched</span>
            <?php endif; ?>
            <span class="ms-auto small">
                <button type="button" class="btn btn-link btn-sm p-0" id="sel_all">Select all</button>
                <span class="text-muted">/</span>
                <button type="button" class="btn btn-link btn-sm p-0" id="sel_none">none</button>
            </span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0" style="font-size:.85rem;">
                    <thead class="table-light">
                        <tr>
                            <th class="text-center" style="width:2.5rem;"></th>
                            <th style="width:3rem;">Line</th>
                            <th>Department / Program</th>
                            <th>Batch / Class</th>
                            <th>Course</th>
                            <th>Teacher</th>
                            <th class="text-center">Students</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($preview as $i => $p): $r = $p['resolved']; ?>
                        <tr class="<?= $p['ok'] ? '' : 'table-danger' ?>">
                            <td class="text-center">
                                <?php if ($p['ok']): ?>
                                <input type="checkbox" class="form-check-input row-sel" name="sel[]" value="<?= $i ?>" checked>
                                <input type="hidden" name="row_offer[<?= $i ?>]"   value="<?= (int)$r['offer_id'] ?>">
                                <input type="hidden" name="row_subject[<?= $i ?>]" value="<?= (int)$r['offer_subject_id'] ?>">
                                <input type="hidden" name="row_date[<?= $i ?>]"    value="<?= h($r['date']) ?>">
                                <input type="hidden" name="row_start[<?= $i ?>]"   value="<?= h($r['start'] ?? '') ?>">
                                <input type="hidden" name="row_end[<?= $i ?>]"     value="<?= h($r['end'] ?? '') ?>">
                                <input type="hidden" name="row_room[<?= $i ?>]"    value="<?= h($r['room']) ?>">
                                <input type="hidden" name="row_remarks[<?= $i ?>]" value="<?= h($r['remarks']) ?>">
                                <?php endif; ?>
                            </td>
                            <td class="text-muted"><?= (int)$p['line'] ?></td>
                            <td>
                                <?php if (isset($r['dept'])): ?>
                                <div class="fw-medium"><?= h($r['dept']) ?></div>
                                <?php if (!empty($r['program'])): ?><div class="small text-muted"><?= h($r['program']) ?></div><?php endif; ?>
                                <?php else: ?>
                                <span class="text-muted"><?= h($p['raw']['department']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?= $p['ok'] ? h($r['offer_label']) : h(trim($p['raw']['batch'] . ' ' . $p['raw']['shift'])) ?></td>
                            <td>
                                <?php if ($p['ok']): ?>
                                <?php if ($r['course_code']): ?><span class="badge bg-light text-dark border me-1" style="font-family:monospace;"><?= h($r['course_code']) ?></span><?php endif; ?>
                                <?= h($r['course_title']) ?>
                                <?php else: ?>
                                <span class="text-muted"><?= h(trim($p['raw']['course_code'] . ' ' . $p['raw']['course_title'])) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?= $p['ok'] ? (h($r['teachers']) ?: '<span class="text-muted">—</span>') : '<span class="text-muted">—</span>' ?></td>
                            <td class="text-center"><?= $p['ok'] ? (int)$r['students'] : '—' ?></td>
                            <td class="small"><?= $p['ok'] ? h(date('d M Y (D)', strtotime($r['date']))) : h($p['raw']['date']) ?></td>
                            <td class="small">
                                <?php if ($p['ok'] && ($r['start'] || $r['end'])): ?>
                                <?= h(er_fmt_time($r['start'] ? $r['start'] . ':00' : null)) ?><?= $r['end'] ? ' – ' . h(er_fmt_time($r['end'] . ':00')) : '' ?>
                                <?php else: ?><span class="text-muted"><?= h(trim($p['raw']['start'] . ' ' . $p['raw']['end'])) ?: '—' ?></span><?php endif; ?>
                            </td>
                            <td class="small">
                                <?php if ($p['ok'] && empty($p['messages'])): ?>
                                <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">OK</span>
                                <?php elseif ($p['ok']): ?>
                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Check</span>
                                <?php else: ?>
                                <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">Not matched</span>
                                <?php endif; ?>
                                <?php foreach ($p['messages'] as $msg): ?>
                                <div class="text-muted" style="font-size:.75rem;"><?= h($msg) ?></div>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mb-5">
        <button type="submit" class="btn btn-primary" style="border-radius:10px;" <?= $ok_count === 0 ? 'disabled' : '' ?>>
            <i class="fas fa-file-import me-1"></i> Import Selected
        </button>
        <a href="<?= APP_URL ?>/exam-routine/import.php" class="btn btn-light" style="border-radius:10px;">Start Over</a>
    </div>
</form>

<script>
(function () {
    var boxes = document.querySelectorAll('.row-sel');
    var all   = document.getElementById('sel_all');
    var none  = document.getElementById('sel_none');
    if (all)  all.addEventListener('click',  function () { boxes.forEach(function (b) { b.checked = true;  }); });
    if (none) none.addEventListener('click', function () { boxes.forEach(function (b) { b.checked = false; }); });
    document.getElementById('import_form').addEventListener('submit', function (e) {
        var any = false;
        boxes.forEach(function (b) { if (b.checked) any = true; });
        if (!any) { e.preventDefault(); alert('Select at least one matched row to import.'); }
    });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
