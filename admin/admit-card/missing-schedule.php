<?php
/**
 * Admit Card – Unscheduled Courses & Left-out Students
 *
 * After generating a batch's admit cards, courses left without an exam
 * DATE / TIME are skipped, so the enrolled students would sit an exam that
 * is missing from their card. This report finds every ACTIVE course offer
 * subject with registered (active) students that has no complete date/time
 * on any active admit card, lists the affected students, and offers a
 * quick inline fix:
 *   - row exists on a card but date/time is empty  → update the row;
 *   - course was skipped at generation             → add the row to the
 *     class group's newest active admit card.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('admit-card');
require_once __DIR__ . '/helpers.php';

$page_title = 'Unscheduled Courses';
$db = db();

// Optional schema column (course rows are linked to offer subjects when present)
$has_subject_col = false;
try { $db->query('SELECT offer_subject_id FROM ac_admit_card_courses LIMIT 1'); $has_subject_col = true; } catch (Throwable $e) {}

/** "EEE 1105" → "eee1105" (lowercase alphanumerics only). */
function acu_code_key(string $s): string
{
    return strtolower((string)preg_replace('/[^a-z0-9]+/i', '', $s));
}

/** "13:00" (+ optional end) → "1:00 PM - 3:00 PM". */
function acu_slot_label(string $start, string $end): string
{
    if ($start === '') return '';
    $fmt = static fn(string $t): string => date('g:i A', strtotime($t));
    return $fmt($start) . ($end !== '' ? ' - ' . $fmt($end) : '');
}

// ── Quick fix: set date/time on an existing row OR add the missing row ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_schedule') {
    csrf_check();
    $ret = APP_URL . '/admit-card/missing-schedule.php'
         . ((string)($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
    if (!ac_can_edit()) {
        flash_set('danger', 'You do not have permission to edit admit cards.');
        redirect($ret);
    }

    $cc_id   = (int)($_POST['cc_id'] ?? 0);            // existing course row on a card
    $card_id = (int)($_POST['card_id'] ?? 0);          // target card when the row must be created
    $osid    = (int)($_POST['offer_subject_id'] ?? 0);
    $date    = trim((string)($_POST['exam_date'] ?? ''));
    $start   = trim((string)($_POST['start_time'] ?? ''));
    $end     = trim((string)($_POST['end_time'] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
        flash_set('danger', 'A valid exam date is required.');
        redirect($ret);
    }
    if ($start !== '' && !preg_match('/^\d{1,2}:\d{2}$/', $start)) { flash_set('danger', 'Invalid start time.'); redirect($ret); }
    if ($end   !== '' && !preg_match('/^\d{1,2}:\d{2}$/', $end))   { flash_set('danger', 'Invalid end time.');   redirect($ret); }
    if ($start === '') $end = '';
    $slot = acu_slot_label($start, $end);

    if ($cc_id > 0) {
        // Update the existing row on the card
        $db->prepare('UPDATE ac_admit_card_courses SET exam_date = ?, time_slot = ? WHERE id = ?')
           ->execute([$date, $slot !== '' ? $slot : null, $cc_id]);
        flash_set('success', 'Exam date/time saved on the admit card.');
        redirect($ret);
    }

    // Insert the skipped course into the target card
    if ($card_id <= 0 || $osid <= 0) {
        flash_set('danger', 'No target admit card for this course. Generate the card first.');
        redirect($ret);
    }
    $card = ac_get_card($card_id);
    if (!$card) {
        flash_set('danger', 'Target admit card no longer exists.');
        redirect($ret);
    }
    $st = $db->prepare(
        'SELECT cos.id, c.course_code, c.course_name, o.section, o.shift
           FROM co_offer_subjects cos
           JOIN course_curriculum c ON c.id = cos.curriculum_id
           JOIN co_offers o ON o.id = cos.offer_id
          WHERE cos.id = ?'
    );
    $st->execute([$osid]);
    $subj = $st->fetch();
    if (!$subj) {
        flash_set('danger', 'Course offer subject not found.');
        redirect($ret);
    }
    $so = $db->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM ac_admit_card_courses WHERE admit_card_id = ?');
    $so->execute([$card_id]);
    $sort = (int)$so->fetchColumn();
    $sect = trim((string)((($subj['section'] ?? '') !== '') ? $subj['section'] : ($subj['shift'] ?? '')));

    if ($has_subject_col) {
        $db->prepare(
            'INSERT INTO ac_admit_card_courses
               (admit_card_id, offer_subject_id, course_code, course_title, exam_date, time_slot, section, sort_order)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([
            $card_id, $osid, $subj['course_code'], $subj['course_name'],
            $date, $slot !== '' ? $slot : null, $sect !== '' ? $sect : null, $sort,
        ]);
    } else {
        $db->prepare(
            'INSERT INTO ac_admit_card_courses
               (admit_card_id, course_code, course_title, exam_date, time_slot, section, sort_order)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $card_id, $subj['course_code'], $subj['course_name'],
            $date, $slot !== '' ? $slot : null, $sect !== '' ? $sect : null, $sort,
        ]);
    }
    flash_set('success', trim($subj['course_code'] . ' ' . $subj['course_name'])
        . ' added to admit card #' . $card_id . ' with the exam date/time.');
    redirect($ret);
}

// ── Filters (same shape as the generator) ───────────────────────────────
$f_sem   = trim($_GET['offer_semester'] ?? '');
$f_dept  = (int)($_GET['dept_id'] ?? 0);
$f_batch = (int)($_GET['batch_id'] ?? 0);

$filter_depts   = $db->query("SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$filter_batches = $db->query("SELECT id, name FROM student_batches WHERE is_active = 1 ORDER BY sort_order ASC, name ASC")->fetchAll();
$filter_sems    = $db->query("SELECT DISTINCT semester FROM co_offers WHERE status = 'active' AND semester IS NOT NULL AND semester <> '' ORDER BY semester ASC")->fetchAll(PDO::FETCH_COLUMN);

// ── Load active offers + their registered subjects (labs excluded, exactly
//    like the generator, so this report mirrors what generation skips) ────
$where  = ["o.status = 'active'"];
$params = [];
if ($f_sem   !== '') { $where[] = 'o.semester = ?'; $params[] = $f_sem; }
if ($f_dept  > 0)    { $where[] = 'o.dept_id = ?';  $params[] = $f_dept; }
if ($f_batch > 0)    { $where[] = 'o.batch_id = ?'; $params[] = $f_batch; }
$whereSQL = implode(' AND ', $where);

$st = $db->prepare(
    "SELECT o.id, o.dept_id, o.program_id, o.batch_id, o.shift, o.section, o.semester,
            d.name AS dept_name, p.program_name, b.name AS batch_name
       FROM co_offers o
       JOIN dept_departments d       ON d.id = o.dept_id
  LEFT JOIN dept_academic_programs p ON p.id = o.program_id
  LEFT JOIN student_batches b        ON b.id = o.batch_id
      WHERE $whereSQL
      ORDER BY b.sort_order ASC, b.name ASC, d.name ASC, p.program_name ASC, o.shift ASC"
);
$st->execute($params);
$offers = $st->fetchAll();

$subjects = [];        // osid => subject info (+ group)
if ($offers) {
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
    $offer_by_id = [];
    foreach ($offers as $o) $offer_by_id[(int)$o['id']] = $o;
    foreach ($ss->fetchAll() as $s) {
        if ((int)$s['reg_count'] <= 0) continue;                                            // nobody registered
        if (preg_match('/\\blab\\b/i', $s['course_name'] . ' ' . $s['course_code'])) continue; // labs are never on admit cards
        $o = $offer_by_id[(int)$s['offer_id']];
        $osid = (int)$s['offer_subject_id'];
        if (isset($subjects[$osid])) continue;
        $subjects[$osid] = [
            'osid'         => $osid,
            'course_code'  => (string)$s['course_code'],
            'course_title' => (string)$s['course_name'],
            'reg_count'    => (int)$s['reg_count'],
            'dept_id'      => (int)$o['dept_id'],
            'program_id'   => (int)$o['program_id'],
            'batch_id'     => (int)$o['batch_id'],
            'batch_name'   => trim((string)($o['batch_name'] ?? '')) !== '' ? (string)$o['batch_name'] : '— No batch —',
            'group_label'  => trim($o['dept_name']
                                . ($o['program_name'] ? ' — ' . $o['program_name'] : '')
                                . ($o['shift'] ? ' · ' . $o['shift'] : '')),
        ];
    }
}

// ── Scheduling status of every subject on the ACTIVE admit cards ────────
$linked_rows = [];      // osid => list of course rows on active cards
if ($subjects && $has_subject_col) {
    $ids = array_keys($subjects);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $lr  = $db->prepare(
        "SELECT cc.id, cc.admit_card_id, cc.offer_subject_id, cc.exam_date, cc.time_slot, a.exam_name
           FROM ac_admit_card_courses cc
           JOIN ac_admit_cards a ON a.id = cc.admit_card_id
          WHERE a.is_active = 1 AND cc.offer_subject_id IN ($ph)"
    );
    $lr->execute($ids);
    foreach ($lr->fetchAll() as $r) $linked_rows[(int)$r['offer_subject_id']][] = $r;
}

// All active cards + their (code-keyed) rows: fallback matching for manual /
// bulk-imported cards without subject links, and target cards for inserts.
$active_cards = $db->query(
    'SELECT a.id, a.exam_name, a.semester, a.dept_id, a.program_id, a.batch_id, a.created_at
       FROM ac_admit_cards a WHERE a.is_active = 1 ORDER BY a.created_at DESC, a.id DESC'
)->fetchAll();
$card_rows_by_code = [];   // card_id => code_key => row
if ($active_cards) {
    $cids = array_map(static fn($c) => (int)$c['id'], $active_cards);
    $ph   = implode(',', array_fill(0, count($cids), '?'));
    $cr   = $db->prepare("SELECT id, admit_card_id, course_code, exam_date, time_slot FROM ac_admit_card_courses WHERE admit_card_id IN ($ph)");
    $cr->execute($cids);
    foreach ($cr->fetchAll() as $r) {
        $card_rows_by_code[(int)$r['admit_card_id']][acu_code_key((string)$r['course_code'])] = $r;
    }
}

/** Newest active card matching the subject's class group, or null. */
function acu_target_card(array $subj, array $active_cards): ?array
{
    foreach ($active_cards as $c) {
        if ((int)$c['dept_id'] !== $subj['dept_id'])       continue;
        if ((int)$c['program_id'] !== $subj['program_id']) continue;
        $cb = (int)($c['batch_id'] ?? 0);
        if ($cb > 0 && $cb !== $subj['batch_id'])          continue;
        return $c;
    }
    return null;
}

$problems = [];   // list of subjects with a missing/incomplete schedule
foreach ($subjects as $osid => $subj) {
    $rows = $linked_rows[$osid] ?? [];

    // Fallback: code-matched rows inside the group's active cards
    if (!$rows) {
        $ck = acu_code_key($subj['course_code']);
        foreach ($active_cards as $c) {
            if ((int)$c['dept_id'] !== $subj['dept_id'] || (int)$c['program_id'] !== $subj['program_id']) continue;
            $cb = (int)($c['batch_id'] ?? 0);
            if ($cb > 0 && $cb !== $subj['batch_id']) continue;
            if (isset($card_rows_by_code[(int)$c['id']][$ck])) {
                $r = $card_rows_by_code[(int)$c['id']][$ck];
                $r['admit_card_id'] = (int)$c['id'];
                $r['exam_name']     = (string)$c['exam_name'];
                $rows[] = $r;
            }
        }
    }

    // Fully scheduled anywhere? Then the students are fine — skip.
    $bad_row = null;
    foreach ($rows as $r) {
        $has_date = trim((string)($r['exam_date'] ?? '')) !== '';
        $has_time = trim((string)($r['time_slot'] ?? '')) !== '';
        if ($has_date && $has_time) { $bad_row = false; break; }
        if ($bad_row === null) $bad_row = $r;
    }
    if ($bad_row === false) continue;   // at least one complete row exists

    if ($bad_row !== null) {
        $has_date = trim((string)($bad_row['exam_date'] ?? '')) !== '';
        $subj['status']    = $has_date ? 'No time set' : 'No date set';
        $subj['cc_id']     = (int)$bad_row['id'];
        $subj['card_id']   = (int)$bad_row['admit_card_id'];
        $subj['card_name'] = (string)($bad_row['exam_name'] ?? '');
        $subj['exam_date'] = (string)($bad_row['exam_date'] ?? '');
    } else {
        $target = acu_target_card($subj, $active_cards);
        $subj['status']    = 'Not on any admit card';
        $subj['cc_id']     = 0;
        $subj['card_id']   = $target ? (int)$target['id'] : 0;
        $subj['card_name'] = $target ? (string)$target['exam_name'] : '';
        $subj['exam_date'] = '';
    }
    $problems[] = $subj;
}

// ── Affected students of every problem course (one query) ───────────────
$students_by_osid = [];
$affected_ids     = [];
if ($problems) {
    $ids = array_map(static fn($p) => $p['osid'], $problems);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $sq  = $db->prepare(
        "SELECT r.offer_subject_id, s.id AS sid, s.student_id, s.full_name, b.name AS batch_name
           FROM co_registrations r
           JOIN students s ON s.id = r.student_id
      LEFT JOIN student_batches b ON b.id = s.batch_id
          WHERE r.offer_subject_id IN ($ph) AND s.status = 'Active'
          ORDER BY s.student_id ASC"
    );
    $sq->execute($ids);
    foreach ($sq->fetchAll() as $r) {
        $students_by_osid[(int)$r['offer_subject_id']][] = $r;
        $affected_ids[(int)$r['sid']] = true;
    }
}
$total_students = count($affected_ids);

// Group problems batch-wise for display
$by_batch = [];
foreach ($problems as $p) $by_batch[$p['batch_name']][] = $p;

$can_edit = ac_can_edit();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-user-clock me-2 text-danger"></i>Unscheduled Courses</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admit-card/index.php">Admit Cards</a></li>
            <li class="breadcrumb-item active">Unscheduled Courses</li>
        </ol></nav>
    </div>
    <?php if (ac_can_create()): ?>
    <a href="<?= APP_URL ?>/admit-card/create.php" class="btn btn-primary" style="border-radius:10px;">
        <i class="fas fa-plus me-1"></i> Generate Admit Cards
    </a>
    <?php endif; ?>
</div>

<?php flash_show(); ?>

<?php if (!$has_subject_col): ?>
<div class="alert alert-warning">
    <i class="fas fa-triangle-exclamation me-1"></i>
    The <code>offer_subject_id</code> link column is missing on <code>ac_admit_card_courses</code>
    (see <code>admin/admit-card-routine-link.sql</code>). The report falls back to course-code matching,
    which is less precise.
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-body py-3 px-4">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small fw-semibold mb-1">Offer Semester</label>
                <select name="offer_semester" class="form-select form-select-sm">
                    <option value="">All semesters</option>
                    <?php foreach ($filter_sems as $s): ?>
                    <option value="<?= h($s) ?>" <?= $f_sem === $s ? 'selected' : '' ?>><?= h($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1">Department</label>
                <select name="dept_id" class="form-select form-select-sm">
                    <option value="">All departments</option>
                    <?php foreach ($filter_depts as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= $f_dept === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1">Batch</label>
                <select name="batch_id" class="form-select form-select-sm">
                    <option value="">All batches</option>
                    <?php foreach ($filter_batches as $b): ?>
                    <option value="<?= (int)$b['id'] ?>" <?= $f_batch === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-sm btn-primary flex-fill"><i class="fas fa-filter me-1"></i>Filter</button>
                <a href="<?= APP_URL ?>/admit-card/missing-schedule.php" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Totals -->
<div class="d-flex flex-wrap gap-2 mb-3">
    <span class="badge <?= $problems ? 'bg-danger-subtle text-danger-emphasis border-danger-subtle' : 'bg-success-subtle text-success-emphasis border-success-subtle' ?> border px-3 py-2">
        <i class="fas fa-book me-1"></i><?= count($problems) ?> course(s) without a complete exam date/time
    </span>
    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-3 py-2">
        <i class="fas fa-user-graduate me-1"></i><?= number_format($total_students) ?> enrolled student(s) affected
    </span>
</div>

<?php if (!$problems): ?>
<div class="card" style="border-radius:12px;">
    <div class="card-body text-center py-5 text-muted">
        <i class="fas fa-circle-check fa-2x text-success mb-3 d-block"></i>
        Every registered course of the matching active offers has an exam date &amp; time on an active admit card.
        No student is left out. 🎉
    </div>
</div>
<?php else: ?>

<?php foreach ($by_batch as $batch_name => $items): ?>
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-header py-3 px-4 fw-semibold">
        <i class="fas fa-users me-2 text-primary"></i><?= h($batch_name) ?>
        <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle ms-2"><?= count($items) ?> course(s)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="min-width:220px;">Course</th>
                    <th>Class Group</th>
                    <th>Status</th>
                    <th>Enrolled Students</th>
                    <th style="min-width:400px;">Quick Fix (date &amp; time)</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $p): $studs = $students_by_osid[$p['osid']] ?? []; ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= h($p['course_code']) ?></div>
                        <div class="small text-muted"><?= h($p['course_title']) ?></div>
                    </td>
                    <td class="small"><?= h($p['group_label']) ?></td>
                    <td>
                        <?php if ($p['status'] === 'Not on any admit card'): ?>
                        <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle"><i class="fas fa-ban me-1"></i><?= h($p['status']) ?></span>
                        <?php else: ?>
                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"><i class="fas fa-clock me-1"></i><?= h($p['status']) ?></span>
                        <?php endif; ?>
                        <?php if ($p['card_id']): ?>
                        <div class="small mt-1">
                            <a href="<?= APP_URL ?>/admit-card/view.php?id=<?= (int)$p['card_id'] ?>">Card #<?= (int)$p['card_id'] ?></a>
                            <span class="text-muted"><?= h($p['card_name']) ?></span>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <details>
                            <summary class="small fw-semibold text-primary" style="cursor:pointer;">
                                <?= count($studs) ?> student(s)
                            </summary>
                            <div class="small mt-2" style="max-height:220px;overflow-y:auto;">
                                <?php foreach ($studs as $s): ?>
                                <div class="border-bottom py-1">
                                    <span class="fw-semibold"><?= h($s['student_id']) ?></span>
                                    — <?= h($s['full_name']) ?>
                                    <?php if (($s['batch_name'] ?? '') !== ''): ?>
                                    <span class="text-muted">(<?= h($s['batch_name']) ?>)</span>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    </td>
                    <td>
                        <?php if ($can_edit && ($p['cc_id'] || $p['card_id'])): ?>
                        <form method="post" class="d-flex gap-1 align-items-center flex-wrap">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="set_schedule">
                            <input type="hidden" name="offer_subject_id" value="<?= (int)$p['osid'] ?>">
                            <?php if ($p['cc_id']): ?>
                            <input type="hidden" name="cc_id" value="<?= (int)$p['cc_id'] ?>">
                            <?php else: ?>
                            <input type="hidden" name="card_id" value="<?= (int)$p['card_id'] ?>">
                            <?php endif; ?>
                            <input type="date" name="exam_date" class="form-control form-control-sm" style="width:150px;"
                                   value="<?= h($p['exam_date']) ?>" required>
                            <input type="time" name="start_time" class="form-control form-control-sm" style="width:110px;" title="Start time">
                            <input type="time" name="end_time" class="form-control form-control-sm" style="width:110px;" title="End time">
                            <button class="btn btn-sm btn-primary"><i class="fas fa-check me-1"></i>Save</button>
                            <?php if ($p['card_id']): ?>
                            <a href="<?= APP_URL ?>/admit-card/edit.php?id=<?= (int)$p['card_id'] ?>" class="btn btn-sm btn-outline-secondary" title="Full edit">
                                <i class="fas fa-edit"></i>
                            </a>
                            <?php endif; ?>
                        </form>
                        <?php elseif (!$p['cc_id'] && !$p['card_id']): ?>
                        <span class="small text-muted">No active admit card exists for this class group.</span>
                        <?php if (ac_can_create()): ?>
                        <a href="<?= APP_URL ?>/admit-card/create.php" class="btn btn-sm btn-outline-primary ms-1">Generate</a>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="small text-muted">You do not have edit permission.</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
