<?php
/**
 * Exam Routine – builder (create + edit).
 *
 * Flow: choose an active exam → department → program → course offer (the offer
 * carries batch / semester / section / shift / intake automatically) → add one
 * row per registered course. Course code, title and enrolled-student count
 * auto-fill from the offer's registered courses; each row takes an exam date,
 * start/end time, room number and remarks.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

$routine_id = (int)($_GET['id'] ?? 0);
$routine    = null;
$items      = [];

if ($routine_id > 0) {
    require_access('exam-routine', 'can_edit');
    $routine = er_get_routine($routine_id);
    if (!$routine) { flash_set('error', 'Routine not found.'); redirect(APP_URL . '/exam-routine/index.php'); }
    $items = er_get_items($routine_id);
} else {
    require_access('exam-routine', 'can_create');
}

$page_title  = $routine ? 'Edit Exam Routine' : 'New Exam Routine';
$errors      = [];
$preload     = null;
$exams       = er_active_exams();
$departments = db()->query('SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC')->fetchAll();

// ── POST ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $exam_id  = (int)($_POST['exam_id']  ?? 0);
    $offer_id = (int)($_POST['offer_id'] ?? 0);
    $notes    = trim($_POST['notes'] ?? '');

    // Exam must exist and be active.
    $exam = null;
    if ($exam_id > 0) {
        $st = db()->prepare('SELECT id FROM ei_exams WHERE id = ? AND is_active = 1');
        $st->execute([$exam_id]);
        $exam = $st->fetch();
    }
    if (!$exam) $errors[] = 'Please choose an active exam.';

    // Offer must exist – it is the single source of the class context.
    $offer = null;
    if ($offer_id > 0) {
        $st = db()->prepare('SELECT * FROM co_offers WHERE id = ?');
        $st->execute([$offer_id]);
        $offer = $st->fetch();
    }
    if (!$offer) $errors[] = 'Please choose the class (department → program → course offer).';

    // ── Item rows ──
    $subj_ids = (array)($_POST['item_subject_id'] ?? []);
    $dates    = (array)($_POST['item_date']       ?? []);
    $starts   = (array)($_POST['item_start']      ?? []);
    $ends     = (array)($_POST['item_end']        ?? []);
    $rooms    = (array)($_POST['item_room']       ?? []);
    $rmk      = (array)($_POST['item_remarks']    ?? []);

    $rows = [];
    if ($offer) {
        // Authoritative subject data comes from the DB, never from the client.
        $sst = db()->prepare(
            'SELECT cos.id, c.course_code, c.course_name
               FROM co_offer_subjects cos
               JOIN course_curriculum c ON c.id = cos.curriculum_id
              WHERE cos.offer_id = ?'
        );
        $sst->execute([$offer_id]);
        $subject_map = [];
        foreach ($sst->fetchAll() as $s) $subject_map[(int)$s['id']] = $s;

        $time_ok = static fn(string $v): bool =>
            $v === '' || (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v);

        $seen = [];
        foreach ($subj_ids as $i => $raw) {
            $sid  = (int)$raw;
            $date = trim((string)($dates[$i] ?? ''));
            if ($sid <= 0 && $date === '') continue; // fully blank row – skip

            $line = 'Row ' . (count($rows) + 1) . ': ';
            if ($sid <= 0 || !isset($subject_map[$sid])) {
                $errors[] = $line . 'choose a subject from the registered courses of this offer.';
                continue;
            }
            if (isset($seen[$sid])) { $errors[] = $line . 'this subject was added more than once.'; continue; }
            $seen[$sid] = true;

            $d = DateTimeImmutable::createFromFormat('Y-m-d', $date);
            if (!$d || $d->format('Y-m-d') !== $date) { $errors[] = $line . 'a valid exam date is required.'; continue; }

            $start = trim((string)($starts[$i] ?? ''));
            $end   = trim((string)($ends[$i]   ?? ''));
            if (!$time_ok($start) || !$time_ok($end)) { $errors[] = $line . 'invalid time format.'; continue; }
            if ($start !== '' && $end !== '' && $end <= $start) { $errors[] = $line . 'end time must be after start time.'; continue; }

            $rows[] = [
                'offer_subject_id' => $sid,
                'course_code'      => $subject_map[$sid]['course_code'],
                'course_title'     => $subject_map[$sid]['course_name'],
                'student_count'    => er_registered_count($sid),
                'exam_date'        => $date,
                'start_time'       => $start !== '' ? $start . ':00' : null,
                'end_time'         => $end   !== '' ? $end   . ':00' : null,
                'room_number'      => (trim((string)($rooms[$i] ?? '')) !== '') ? trim((string)$rooms[$i]) : null,
                'remarks'          => (trim((string)($rmk[$i]   ?? '')) !== '') ? trim((string)$rmk[$i])   : null,
            ];
        }
        if (empty($rows) && empty($errors)) $errors[] = 'Add at least one subject row to the routine.';
    }

    if (empty($errors)) {
        $user = auth_user();
        $head = [
            $exam_id,
            $offer_id,
            (int)$offer['dept_id'],
            !empty($offer['program_id']) ? (int)$offer['program_id'] : null,
            !empty($offer['batch_id'])   ? (int)$offer['batch_id']   : null,
            ($offer['semester']        ?? '') !== '' ? $offer['semester']        : null,
            ($offer['academic_intake'] ?? '') !== '' ? $offer['academic_intake'] : null,
            ($offer['shift']           ?? '') !== '' ? $offer['shift']           : null,
            ($offer['section']         ?? '') !== '' ? $offer['section']         : null,
            $notes !== '' ? $notes : null,
        ];

        if ($routine) {
            db()->prepare(
                'UPDATE exam_routines
                    SET exam_id = ?, offer_id = ?, dept_id = ?, program_id = ?, batch_id = ?,
                        semester = ?, academic_intake = ?, shift = ?, section = ?, notes = ?
                  WHERE id = ?'
            )->execute(array_merge($head, [$routine_id]));
            db()->prepare('DELETE FROM exam_routine_items WHERE routine_id = ?')->execute([$routine_id]);
            $rid = $routine_id;
        } else {
            db()->prepare(
                'INSERT INTO exam_routines
                   (exam_id, offer_id, dept_id, program_id, batch_id,
                    semester, academic_intake, shift, section, notes, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            )->execute(array_merge($head, [(int)$user['id']]));
            $rid = (int)db()->lastInsertId();
        }

        $ist = db()->prepare(
            'INSERT INTO exam_routine_items
               (routine_id, offer_subject_id, course_code, course_title, student_count,
                exam_date, start_time, end_time, room_number, remarks, sort_order)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ($rows as $n => $r) {
            $ist->execute([
                $rid, $r['offer_subject_id'], $r['course_code'], $r['course_title'],
                $r['student_count'], $r['exam_date'], $r['start_time'], $r['end_time'],
                $r['room_number'], $r['remarks'], $n,
            ]);
        }

        flash_set('success', 'Exam routine saved.');
        redirect(APP_URL . '/exam-routine/view.php?id=' . $rid);
    }

    // Validation failed – rebuild the form state so nothing typed is lost.
    $preload = [
        'exam_id'    => $exam_id,
        'dept_id'    => (int)($_POST['dept_id']    ?? 0),
        'program_id' => (int)($_POST['program_id'] ?? 0),
        'offer_id'   => $offer_id,
        'notes'      => $notes,
        'items'      => array_values(array_map(static fn($i) => [
            'offer_subject_id' => (int)($subj_ids[$i] ?? 0),
            'exam_date' => (string)($dates[$i]  ?? ''),
            'start'     => (string)($starts[$i] ?? ''),
            'end'       => (string)($ends[$i]   ?? ''),
            'room'      => (string)($rooms[$i]  ?? ''),
            'remarks'   => (string)($rmk[$i]    ?? ''),
        ], array_keys($subj_ids))),
    ];
}

// GET edit mode – preload from the stored routine.
if ($preload === null && $routine) {
    $preload = [
        'exam_id'    => (int)$routine['exam_id'],
        'dept_id'    => (int)$routine['dept_id'],
        'program_id' => (int)($routine['program_id'] ?? 0),
        'offer_id'   => (int)$routine['offer_id'],
        'notes'      => (string)($routine['notes'] ?? ''),
        'items'      => array_map(static fn($it) => [
            'offer_subject_id' => (int)($it['offer_subject_id'] ?? 0),
            'exam_date' => (string)$it['exam_date'],
            'start'     => $it['start_time'] ? substr($it['start_time'], 0, 5) : '',
            'end'       => $it['end_time']   ? substr($it['end_time'],   0, 5) : '',
            'room'      => (string)($it['room_number'] ?? ''),
            'remarks'   => (string)($it['remarks'] ?? ''),
        ], $items),
    ];
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/exam-routine/index.php">Exam Routine</a></li>
            <li class="breadcrumb-item active"><?= $routine ? 'Edit' : 'New' ?></li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<form method="POST" novalidate id="routine_form">
    <?= csrf_field() ?>

    <!-- ── Exam & class selection ── -->
    <div class="card mb-4" style="border-radius:12px;">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-graduation-cap me-2 text-muted"></i>Exam &amp; Class</h6>
        </div>
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Exam <span class="text-danger">*</span></label>
                    <select name="exam_id" id="exam_sel" class="form-select" required>
                        <option value="">— Select Active Exam —</option>
                        <?php foreach ($exams as $e): ?>
                        <option value="<?= $e['id'] ?>"><?= h($e['exam_name']) ?><?= $e['exam_year'] ? ' – ' . h($e['exam_year']) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Department <span class="text-danger">*</span></label>
                    <select name="dept_id" id="dept_sel" class="form-select" required>
                        <option value="">— Select Department —</option>
                        <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= h($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Program <span class="text-danger">*</span></label>
                    <select name="program_id" id="prog_sel" class="form-select" required disabled>
                        <option value="">— Select Program —</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Batch / Semester (Course Offer) <span class="text-danger">*</span></label>
                    <select name="offer_id" id="offer_sel" class="form-select" required disabled>
                        <option value="">— Select Offer —</option>
                    </select>
                    <div class="form-text">Batch, semester, section, shift and intake come from the selected offer.</div>
                </div>
            </div>
            <div id="ctx_badges" class="mt-3"></div>
        </div>
    </div>

    <!-- ── Subjects ── -->
    <div class="card mb-4" style="border-radius:12px;">
        <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-book me-2 text-muted"></i>Routine Subjects</h6>
            <button type="button" id="add_row_btn" class="btn btn-sm btn-outline-primary" style="border-radius:8px;" disabled>
                <i class="fas fa-plus me-1"></i> Add Subject
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0" id="routine_table">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4" style="min-width:260px;">Subject (registered course) <span class="text-danger">*</span></th>
                            <th>Code</th>
                            <th class="text-center">Students</th>
                            <th style="min-width:150px;">Date <span class="text-danger">*</span></th>
                            <th>Start</th>
                            <th>End</th>
                            <th style="min-width:110px;">Room</th>
                            <th style="min-width:160px;">Remarks</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="routine_rows">
                        <tr id="empty_hint"><td colspan="9" class="text-center text-muted py-4">
                            Select the exam and class above, then add subjects.
                        </td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card mb-4" style="border-radius:12px;">
        <div class="card-body p-4">
            <label class="form-label fw-medium">Notes</label>
            <textarea name="notes" class="form-control" rows="2"
                      placeholder="Optional notes printed under the routine…"><?= h($preload['notes'] ?? '') ?></textarea>
        </div>
    </div>

    <div class="d-flex gap-2 mb-5">
        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
            <i class="fas fa-save me-1"></i> <?= $routine ? 'Update Routine' : 'Save Routine' ?>
        </button>
        <a href="<?= APP_URL ?>/exam-routine/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
    </div>
</form>

<script>
(function () {
    'use strict';
    const APP     = <?= json_encode(APP_URL) ?>;
    const PRELOAD = <?= json_encode($preload) ?>;

    const examSel  = document.getElementById('exam_sel');
    const deptSel  = document.getElementById('dept_sel');
    const progSel  = document.getElementById('prog_sel');
    const offerSel = document.getElementById('offer_sel');
    const rowsBody = document.getElementById('routine_rows');
    const addBtn   = document.getElementById('add_row_btn');
    const badges   = document.getElementById('ctx_badges');
    const hint     = document.getElementById('empty_hint');

    let SUBJECTS = []; // registered courses of the selected offer

    const esc = s => String(s ?? '').replace(/[&<>"']/g,
        c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    async function getJSON(url) {
        const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
        if (!res.ok) return [];
        return res.json();
    }

    function resetSelect(sel, placeholder) {
        sel.innerHTML = '<option value="">' + placeholder + '</option>';
        sel.disabled = true;
    }

    async function loadPrograms(deptId, selected) {
        resetSelect(progSel, '— Select Program —');
        resetSelect(offerSel, '— Select Offer —');
        setOfferContext(null);
        if (!deptId) return;
        const list = await getJSON(APP + '/exam-routine/get-programs.php?dept_id=' + deptId);
        for (const p of list) {
            const o = document.createElement('option');
            o.value = p.id; o.textContent = p.program_name;
            if (selected && Number(selected) === Number(p.id)) o.selected = true;
            progSel.appendChild(o);
        }
        progSel.disabled = false;
    }

    function offerLabel(o) {
        const bits = [];
        if (o.batch_name)      bits.push('Batch ' + o.batch_name);
        if (o.semester)        bits.push(o.semester);
        if (o.section)         bits.push('Sec ' + o.section);
        if (o.shift)           bits.push(o.shift);
        if (o.academic_intake) bits.push(o.academic_intake);
        return (bits.join(' · ') || ('Offer #' + o.id)) + ' (' + o.subject_count + ' subjects)';
    }

    async function loadOffers(deptId, progId, selected) {
        resetSelect(offerSel, '— Select Offer —');
        setOfferContext(null);
        if (!deptId || !progId) return;
        const list = await getJSON(APP + '/exam-routine/get-offers.php?dept_id=' + deptId + '&program_id=' + progId);
        for (const of of list) {
            const o = document.createElement('option');
            o.value = of.id;
            o.textContent = offerLabel(of);
            o.dataset.meta = JSON.stringify(of);
            if (selected && Number(selected) === Number(of.id)) o.selected = true;
            offerSel.appendChild(o);
        }
        offerSel.disabled = false;
        if (selected) setOfferContext(currentOfferMeta());
    }

    function currentOfferMeta() {
        const opt = offerSel.selectedOptions[0];
        return (opt && opt.dataset.meta) ? JSON.parse(opt.dataset.meta) : null;
    }

    function setOfferContext(meta) {
        badges.innerHTML = '';
        if (!meta) { addBtn.disabled = true; return; }
        const pairs = [
            ['Batch',    meta.batch_name],
            ['Semester', meta.semester],
            ['Section',  meta.section],
            ['Shift',    meta.shift],
            ['Intake',   meta.academic_intake],
        ];
        for (const [label, val] of pairs) {
            if (!val) continue;
            badges.insertAdjacentHTML('beforeend',
                '<span class="badge bg-light text-dark border me-2 mb-1">' + esc(label) + ': <strong>' + esc(val) + '</strong></span>');
        }
        addBtn.disabled = false;
    }

    async function loadSubjects(offerId) {
        SUBJECTS = offerId
            ? await getJSON(APP + '/exam-routine/get-offer-subjects.php?offer_id=' + offerId)
            : [];
        // Refresh subject dropdowns of any existing rows.
        rowsBody.querySelectorAll('select[name="item_subject_id[]"]').forEach(sel => {
            const cur = sel.value;
            fillSubjectOptions(sel, cur);
            sel.dispatchEvent(new Event('change'));
        });
    }

    function fillSubjectOptions(sel, selected) {
        sel.innerHTML = '<option value="">— Select Subject —</option>';
        for (const s of SUBJECTS) {
            const o = document.createElement('option');
            o.value = s.offer_subject_id;
            o.textContent = (s.course_code ? s.course_code + ' — ' : '') + s.course_name;
            if (selected && Number(selected) === Number(s.offer_subject_id)) o.selected = true;
            sel.appendChild(o);
        }
    }

    function addRow(data) {
        data = data || {};
        if (hint) hint.style.display = 'none';
        const tr = document.createElement('tr');
        tr.innerHTML =
            '<td class="ps-4"><select name="item_subject_id[]" class="form-select form-select-sm" required></select></td>' +
            '<td class="small text-muted js-code">—</td>' +
            '<td class="text-center js-count">—</td>' +
            '<td><input type="date" name="item_date[]" class="form-control form-control-sm" value="' + esc(data.exam_date) + '" required></td>' +
            '<td><input type="time" name="item_start[]" class="form-control form-control-sm" value="' + esc(data.start) + '"></td>' +
            '<td><input type="time" name="item_end[]" class="form-control form-control-sm" value="' + esc(data.end) + '"></td>' +
            '<td><input type="text" name="item_room[]" class="form-control form-control-sm" maxlength="100" placeholder="e.g. 402" value="' + esc(data.room) + '"></td>' +
            '<td><input type="text" name="item_remarks[]" class="form-control form-control-sm" maxlength="500" value="' + esc(data.remarks) + '"></td>' +
            '<td class="text-end pe-3"><button type="button" class="btn btn-sm btn-light text-danger js-remove" title="Remove"><i class="fas fa-times"></i></button></td>';

        const sel = tr.querySelector('select');
        fillSubjectOptions(sel, data.offer_subject_id);
        sel.addEventListener('change', () => {
            const s = SUBJECTS.find(x => Number(x.offer_subject_id) === Number(sel.value));
            tr.querySelector('.js-code').textContent  = s ? (s.course_code || '—') : '—';
            tr.querySelector('.js-count').textContent = s ? s.registered_count : '—';
        });
        sel.dispatchEvent(new Event('change'));

        tr.querySelector('.js-remove').addEventListener('click', () => {
            tr.remove();
            if (!rowsBody.querySelector('tr:not(#empty_hint)') && hint) hint.style.display = '';
        });

        rowsBody.appendChild(tr);
    }

    // ── Events ──
    deptSel.addEventListener('change', () => loadPrograms(deptSel.value, null));
    progSel.addEventListener('change', () => loadOffers(deptSel.value, progSel.value, null));
    offerSel.addEventListener('change', async () => {
        setOfferContext(currentOfferMeta());
        await loadSubjects(offerSel.value);
    });
    addBtn.addEventListener('click', () => addRow());

    // ── Preload (edit mode / failed validation) ──
    (async function init() {
        if (!PRELOAD) return;
        if (PRELOAD.exam_id) examSel.value = String(PRELOAD.exam_id);
        if (PRELOAD.dept_id) {
            deptSel.value = String(PRELOAD.dept_id);
            await loadPrograms(PRELOAD.dept_id, PRELOAD.program_id);
            if (PRELOAD.program_id) {
                await loadOffers(PRELOAD.dept_id, PRELOAD.program_id, PRELOAD.offer_id);
                if (PRELOAD.offer_id) {
                    await loadSubjects(PRELOAD.offer_id);
                    setOfferContext(currentOfferMeta());
                }
            }
        }
        for (const it of (PRELOAD.items || [])) addRow(it);
    })();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
