<?php
/**
 * Results – Split a mixed mark sheet (repair tool).
 *
 * When students from two different academic programs (e.g. Bachelor and
 * Masters) ended up merged into ONE draft sheet, this tool moves one
 * program's rows into a NEW draft sheet. Rows are moved with an UPDATE of
 * their sheet_id — nothing is re-entered — so every mark, absence flag and
 * remark is preserved exactly as typed.
 *
 * Access: super admins, or the sheet's owner. Draft/returned sheets only.
 * URL:    /results/sheet-split.php?id=SHEET_ID
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/workflow-helpers.php';

auth_check();

$sheet_id = (int)($_POST['sheet_id'] ?? $_GET['id'] ?? 0);
if ($sheet_id <= 0) {
    flash_set('error', 'Missing sheet id.');
    redirect(APP_URL . '/results/index.php');
}

$sheet = wf_get_sheet($sheet_id);   // redirects when not found
$user  = auth_user();

if (!is_super_admin() && (int)$sheet['created_by'] !== (int)$user['id']) {
    flash_set('error', 'You can only split your own mark sheets.');
    redirect(APP_URL . '/results/index.php');
}
if (!in_array($sheet['workflow_status'], ['draft', 'returned'], true)) {
    flash_set('error', 'Only draft or returned sheets can be split. Submitted/published sheets are locked.');
    redirect(APP_URL . '/results/index.php');
}

// ── Load rows + each student's own program ────────────────────────────────
$rows_stmt = db()->prepare(
    'SELECT g.id, g.student_id, g.student_sid, g.student_name,
            s.program_id AS s_program_id, s.dept_id AS s_dept_id,
            p.program_name AS s_program_name
       FROM result_sheet_grades g
       LEFT JOIN students s ON s.id = g.student_id
       LEFT JOIN dept_academic_programs p ON p.id = s.program_id
      WHERE g.sheet_id = ?
      ORDER BY g.student_sid ASC'
);
$rows_stmt->execute([$sheet_id]);
$rows = $rows_stmt->fetchAll();

// Group rows by the STUDENT's own program (0 = unknown / manually added row)
$groups = [];
foreach ($rows as $r) {
    $pid = (int)($r['s_program_id'] ?? 0);
    if (!isset($groups[$pid])) {
        $groups[$pid] = [
            'program_name' => ($r['s_program_name'] !== null && $r['s_program_name'] !== '')
                ? (string)$r['s_program_name']
                : ($pid ? ('Program #' . $pid) : 'Unknown program (manually added rows)'),
            'rows' => [],
        ];
    }
    $groups[$pid]['rows'][] = $r;
}
// Largest group first (keys preserved)
uasort($groups, static fn($a, $b) => count($b['rows']) <=> count($a['rows']));

/**
 * Offer-subject suggestions for a set of students (most-registered first),
 * so the split-off draft can be pointed straight at the correct course offer.
 */
function _split_suggest_offers(array $student_pks): array
{
    $student_pks = array_values(array_filter(array_map('intval', $student_pks)));
    if (empty($student_pks)) return [];
    $phs = implode(',', array_fill(0, count($student_pks), '?'));
    try {
        $st = db()->prepare(
            "SELECT cos.id AS offer_subject_id, o.semester, o.academic_intake,
                    cc.course_code, cc.course_name, COUNT(DISTINCT r.student_id) AS cnt
               FROM co_registrations r
               JOIN co_offer_subjects cos ON cos.id = r.offer_subject_id
               JOIN co_offers o           ON o.id  = cos.offer_id
               LEFT JOIN course_curriculum cc ON cc.id = cos.curriculum_id
              WHERE r.student_id IN ($phs)
              GROUP BY cos.id, o.semester, o.academic_intake, cc.course_code, cc.course_name
              ORDER BY cnt DESC
              LIMIT 30"
        );
        $st->execute($student_pks);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

$errors = [];

// ── POST: perform the split ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $move_pid = isset($_POST['move_program_id']) ? (int)$_POST['move_program_id'] : -1;
    $offer_id = (int)($_POST['offer_subject_for'][$move_pid] ?? 0);

    if (count($groups) < 2) {
        $errors[] = 'This sheet only contains students of one program — nothing to split.';
    } elseif (!isset($groups[$move_pid])) {
        $errors[] = 'Please choose which group of students to move out.';
    } elseif (count($groups[$move_pid]['rows']) === count($rows)) {
        $errors[] = 'You cannot move ALL rows out of the sheet.';
    }

    if (empty($errors)) {
        $move_rows = $groups[$move_pid]['rows'];
        $move_ids  = array_map(static fn($r) => (int)$r['id'], $move_rows);

        // New sheet header: start from the source sheet, override with the
        // moved students' own program/dept, then — when an offer is chosen —
        // with the authoritative data of that course offer.
        $new = [
            'dept_id'          => (int)$sheet['dept_id'],
            'program_id'       => $move_pid ?: null,
            'exam_id'          => $sheet['exam_id'] ?: null,
            'semester'         => $sheet['semester'],
            'curriculum_id'    => null,
            'offer_subject_id' => null,
            'subject_code'     => $sheet['subject_code'],
            'subject_title'    => $sheet['subject_title'],
            'credits'          => $sheet['credits'],
        ];

        // Majority department of the moved students
        $dept_counts = [];
        foreach ($move_rows as $r) {
            if (!empty($r['s_dept_id'])) {
                $d = (int)$r['s_dept_id'];
                $dept_counts[$d] = ($dept_counts[$d] ?? 0) + 1;
            }
        }
        if ($dept_counts) { arsort($dept_counts); $new['dept_id'] = (int)array_key_first($dept_counts); }

        if ($offer_id > 0) {
            $ost = db()->prepare(
                'SELECT cos.id, cos.curriculum_id, o.dept_id, o.program_id,
                        o.semester, o.academic_intake,
                        cc.course_code, cc.course_name, cc.credit
                   FROM co_offer_subjects cos
                   JOIN co_offers o ON o.id = cos.offer_id
                   LEFT JOIN course_curriculum cc ON cc.id = cos.curriculum_id
                  WHERE cos.id = ?
                  LIMIT 1'
            );
            $ost->execute([$offer_id]);
            if ($or = $ost->fetch()) {
                $new['offer_subject_id'] = (int)$or['id'];
                $new['curriculum_id']    = (int)$or['curriculum_id'] ?: null;
                $new['dept_id']          = (int)$or['dept_id'];
                $new['program_id']       = (int)$or['program_id'] ?: null;
                $sem = trim((string)($or['semester'] ?: $or['academic_intake']));
                if ($sem !== '') $new['semester'] = $sem;
                if (!empty($or['course_code'])) $new['subject_code']  = $or['course_code'];
                if (!empty($or['course_name'])) $new['subject_title'] = $or['course_name'];
                if ($or['credit'] !== null && $or['credit'] !== '') $new['credits'] = $or['credit'];
            }
        }

        $db     = db();
        $new_id = 0;
        $db->beginTransaction();
        try {
            $db->prepare(
                "INSERT INTO result_mark_sheets
                   (dept_id, program_id, exam_id, semester, curriculum_id, offer_subject_id,
                    subject_code, subject_title, credits, workflow_status, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,'draft',?)"
            )->execute([
                $new['dept_id'], $new['program_id'], $new['exam_id'], $new['semester'],
                $new['curriculum_id'], $new['offer_subject_id'],
                $new['subject_code'] ?: null,
                ($new['subject_title'] !== null && $new['subject_title'] !== '') ? $new['subject_title'] : 'Split sheet',
                ($new['credits'] !== null && $new['credits'] !== '') ? $new['credits'] : null,
                (int)$sheet['created_by'] ?: (int)$user['id'],
            ]);
            $new_id = (int)$db->lastInsertId();

            // MOVE the rows — marks/absents/remarks columns stay untouched.
            $phs = implode(',', array_fill(0, count($move_ids), '?'));
            $db->prepare(
                "UPDATE result_sheet_grades SET sheet_id = ? WHERE sheet_id = ? AND id IN ($phs)"
            )->execute(array_merge([$new_id, $sheet_id], $move_ids));

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            $new_id   = 0;
            $errors[] = 'Split failed: ' . h($e->getMessage());
        }

        if (empty($errors) && $new_id > 0) {
            try {
                log_change(
                    'results', 'UPDATE', $sheet_id,
                    trim(($sheet['subject_code'] ? $sheet['subject_code'] . ' — ' : '') . (string)$sheet['subject_title']),
                    'split', null,
                    json_encode([
                        'moved_to_sheet' => $new_id,
                        'moved_sids'     => array_map(static fn($r) => $r['student_sid'], $move_rows),
                    ]),
                    sprintf('Sheet split: moved %d student(s) of "%s" into new draft sheet #%d (marks preserved).',
                            count($move_ids), $groups[$move_pid]['program_name'], $new_id)
                );
            } catch (Throwable $_e) {}

            flash_set('success', sprintf(
                '%d student(s) moved into a new draft sheet — all entered marks were preserved. '
              . 'Review the new sheet below, adjust the subject if needed, then Save Draft.',
                count($move_ids)
            ));
            redirect(APP_URL . '/results/mark-entry.php?id=' . $new_id);
        }
    }
}

$page_title = 'Split Mark Sheet';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/results/index.php">Results</a></li>
            <li class="breadcrumb-item active">Split Sheet</li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php flash_show(); ?>

<div class="card mb-4" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-code-branch me-2 text-muted"></i>Split Mixed Mark Sheet</h6>
    </div>
    <div class="card-body p-4">
        <div class="row g-3" style="font-size:.9rem;">
            <div class="col-md-4">
                <div class="text-muted small">Subject</div>
                <strong><?= h(trim(($sheet['subject_code'] ? $sheet['subject_code'] . ' — ' : '') . (string)$sheet['subject_title'])) ?></strong>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Department / Program (current header)</div>
                <strong><?= h($sheet['dept_name']) ?><?= $sheet['program_name'] ? ' · ' . h($sheet['program_name']) : '' ?></strong>
            </div>
            <div class="col-md-2">
                <div class="text-muted small">Status</div>
                <?= wf_status_badge($sheet['workflow_status']) ?>
            </div>
            <div class="col-md-2">
                <div class="text-muted small">Students</div>
                <strong><?= count($rows) ?></strong>
            </div>
        </div>
    </div>
</div>

<?php if (count($groups) < 2): ?>
<div class="alert alert-info">
    <i class="fas fa-info-circle me-1"></i>
    All students in this sheet belong to the same program — there is nothing to split.
    <a href="<?= APP_URL ?>/results/mark-entry.php?id=<?= (int)$sheet_id ?>" class="alert-link">Back to the editor</a>.
</div>
<?php else: ?>

<div class="alert alert-warning" style="font-size:.9rem;">
    <strong><i class="fas fa-exclamation-triangle me-1"></i> How this works:</strong>
    choose ONE group below to move into a <strong>new separate draft sheet</strong>. The rows are
    moved as-is — every entered mark, absence flag and remark is preserved. The group you do
    <em>not</em> select stays in this sheet. Pick the correct course offer for the moved group so
    the new draft points at the right subject.
</div>

<form method="POST" onsubmit="return confirm('Move the selected group into a new draft sheet? Marks are preserved.');">
    <?= csrf_field() ?>
    <input type="hidden" name="sheet_id" value="<?= (int)$sheet_id ?>">

    <?php foreach ($groups as $pid => $grp):
        $pks     = array_map(static fn($r) => (int)$r['student_id'], $grp['rows']);
        $offers  = _split_suggest_offers($pks);
        $samples = array_slice(array_map(static fn($r) => (string)$r['student_sid'], $grp['rows']), 0, 10);
    ?>
    <div class="card mb-3" style="border-radius:12px;">
        <div class="card-body p-4">
            <div class="form-check mb-2">
                <input class="form-check-input" type="radio" name="move_program_id"
                       id="move_grp_<?= (int)$pid ?>" value="<?= (int)$pid ?>">
                <label class="form-check-label fw-semibold" for="move_grp_<?= (int)$pid ?>">
                    Move out: <?= h($grp['program_name']) ?>
                    <span class="badge bg-secondary ms-1"><?= count($grp['rows']) ?> student<?= count($grp['rows']) === 1 ? '' : 's' ?></span>
                </label>
            </div>
            <div class="text-muted mb-3" style="font-size:.8rem;">
                IDs: <?= h(implode(', ', $samples)) ?><?= count($grp['rows']) > 10 ? ' … (+' . (count($grp['rows']) - 10) . ' more)' : '' ?>
            </div>
            <label class="form-label fw-medium" style="font-size:.85rem;">
                Course offer for the new draft <small class="text-muted">(suggested from these students' registrations)</small>
            </label>
            <select name="offer_subject_for[<?= (int)$pid ?>]" class="form-select form-select-sm" style="max-width:640px;">
                <option value="0">— Keep the current subject header (fix it later in the editor) —</option>
                <?php foreach ($offers as $of):
                    $label = trim(((($of['course_code'] ?? '') !== '') ? $of['course_code'] . ' – ' : '') . (string)($of['course_name'] ?? ('Course offer #' . $of['offer_subject_id'])));
                    $ctx   = trim((string)($of['semester'] ?: $of['academic_intake']));
                    if ($ctx !== '') $label .= ' (' . $ctx . ')';
                    $label .= ' • ' . (int)$of['cnt'] . ' of these students registered';
                ?>
                <option value="<?= (int)$of['offer_subject_id'] ?>"><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
            <i class="fas fa-code-branch me-1"></i> Split into Separate Draft
        </button>
        <a href="<?= APP_URL ?>/results/mark-entry.php?id=<?= (int)$sheet_id ?>" class="btn btn-light" style="border-radius:10px;">Cancel</a>
    </div>
</form>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
