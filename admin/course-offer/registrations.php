<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../change-log/helpers.php';

if (!can_access('course-offer')) {
    flash_set('error', 'You do not have permission to access this section.');
    redirect(APP_URL . '/index.php');
}

$offer_id = (int)($_GET['offer_id'] ?? $_POST['offer_id'] ?? 0);
$offer    = $offer_id > 0 ? co_get_offer($offer_id) : null;
if (!$offer) {
    flash_set('error', 'Course offer not found.');
    redirect(APP_URL . '/course-offer/index.php');
}

$subjects = co_get_subjects_with_teachers($offer_id);
$batch_id = (int)$offer['batch_id'];

// ── Actions ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (!co_is_staff()) {
        flash_set('error', 'You do not have permission to manage registrations.');
        redirect(APP_URL . '/course-offer/registrations.php?offer_id=' . $offer_id);
    }

    $action    = $_POST['action'] ?? '';
    $valid_sub = array_map(static fn($s) => (int)$s['id'], $subjects);
    $user      = auth_user();

    if ($action === 'toggle_open') {
        $open = (int)($_POST['registration_open'] ?? 0) === 1 ? 1 : 0;
        db()->prepare("UPDATE co_offers SET registration_open = ? WHERE id = ?")
            ->execute([$open, $offer_id]);
        log_change('course-offer', 'UPDATE', $offer_id, 'Offer #' . $offer_id,
            'registration_open', (string)(int)!$open, (string)$open,
            'Student self-registration ' . ($open ? 'opened' : 'closed'));
        flash_set('success', 'Self-registration ' . ($open ? 'opened' : 'closed') . '.');
        redirect(APP_URL . '/course-offer/registrations.php?offer_id=' . $offer_id);
    }

    if ($action === 'add') {
        $student_ids = array_values(array_filter(array_map('intval', (array)($_POST['student_ids'] ?? []))));
        $subject_ids = array_values(array_filter(array_map('intval', (array)($_POST['subject_ids'] ?? []))));
        $subject_ids = array_values(array_intersect($subject_ids, $valid_sub));

        if (empty($student_ids) || empty($subject_ids)) {
            flash_set('error', 'Select at least one student and one subject.');
            redirect(APP_URL . '/course-offer/registrations.php?offer_id=' . $offer_id);
        }

        // Only enrol students that actually belong to this offer's batch.
        $ph      = implode(',', array_fill(0, count($student_ids), '?'));
        $chk     = db()->prepare("SELECT id FROM students WHERE batch_id = ? AND id IN ($ph)");
        $chk->execute(array_merge([$batch_id], $student_ids));
        $allowed = array_map('intval', array_column($chk->fetchAll(), 'id'));

        $added = 0;
        foreach ($allowed as $sid) {
            foreach ($subject_ids as $osid) {
                if (co_register_student($osid, $sid, 'admin', (int)$user['id'])) $added++;
            }
        }
        $skipped = count($student_ids) - count($allowed);
        log_change('course-offer', 'CREATE', $offer_id, 'Offer #' . $offer_id,
            null, null, null,
            'Manually enrolled ' . count($allowed) . ' student(s) into ' . count($subject_ids) . ' subject(s) (' . $added . ' new)');

        $msg = "Added <strong>$added</strong> registration(s) for " . count($allowed) . ' student(s).';
        if ($skipped > 0) $msg .= " $skipped student(s) skipped (not in this batch).";
        flash_set('success', $msg);
        redirect(APP_URL . '/course-offer/registrations.php?offer_id=' . $offer_id);
    }

    if ($action === 'remove') {
        $osid = (int)($_POST['offer_subject_id'] ?? 0);
        $sid  = (int)($_POST['student_id'] ?? 0);
        if (in_array($osid, $valid_sub, true) && co_unregister_student($osid, $sid)) {
            log_change('course-offer', 'DELETE', $offer_id, 'Offer #' . $offer_id,
                null, null, null,
                'Removed student #' . $sid . ' from subject #' . $osid);
            flash_set('success', 'Registration removed.');
        } else {
            flash_set('error', 'Registration not found.');
        }
        redirect(APP_URL . '/course-offer/registrations.php?offer_id=' . $offer_id);
    }

    redirect(APP_URL . '/course-offer/registrations.php?offer_id=' . $offer_id);
}

// ── View data ────────────────────────────────────────────────────────────────
$reg_map     = co_registrations_by_subject($offer_id);
$total_regs  = 0;
foreach ($reg_map as $list) $total_regs += count($list);

$page_title = 'Course Registrations';
require_once __DIR__ . '/../includes/header.php';
echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css">';
echo '<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/course-offer/index.php">Course Offer</a></li>
            <li class="breadcrumb-item active">Registrations</li>
        </ol>
    </nav>
</div>

<?php flash_show(); ?>

<!-- Offer summary -->
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-body py-3 px-4 d-flex flex-wrap align-items-center gap-3">
        <div>
            <div class="fw-bold"><?= h($offer['batch_name']) ?></div>
            <div class="text-muted small">
                <?= h($offer['dept_name']) ?> &rsaquo; <?= h($offer['program_name']) ?>
                <?php if ($offer['semester']): ?> &middot; <?= h($offer['semester']) ?><?php endif; ?>
                <?php if ($offer['academic_intake']): ?> &middot; <?= h($offer['academic_intake']) ?><?php endif; ?>
            </div>
        </div>
        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle ms-auto">
            <?= (int)$total_regs ?> registration<?= $total_regs != 1 ? 's' : '' ?>
        </span>
        <?php if (co_is_staff()): ?>
        <form method="POST" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="offer_id" value="<?= $offer_id ?>">
            <input type="hidden" name="action" value="toggle_open">
            <input type="hidden" name="registration_open" value="<?= (int)$offer['registration_open'] ? 0 : 1 ?>">
            <button type="submit" class="btn btn-sm <?= (int)$offer['registration_open'] ? 'btn-success' : 'btn-outline-secondary' ?>"
                    style="border-radius:8px;">
                <i class="fas fa-toggle-<?= (int)$offer['registration_open'] ? 'on' : 'off' ?> me-1"></i>
                Self-registration: <?= (int)$offer['registration_open'] ? 'Open' : 'Closed' ?>
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($subjects)): ?>
<div class="alert alert-warning" style="border-radius:12px;">
    This offer has no subjects yet. <a href="<?= APP_URL ?>/course-offer/edit.php?id=<?= $offer_id ?>">Add subjects</a> first.
</div>
<?php else: ?>

<?php if (co_is_staff()): ?>
<!-- Quick manual enrollment -->
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-user-plus me-2 text-muted"></i>Quick Enroll Students</h6>
    </div>
    <div class="card-body p-4">
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="offer_id" value="<?= $offer_id ?>">
            <input type="hidden" name="action" value="add">
            <div class="mb-3">
                <label class="form-label fw-medium">Students <span class="text-danger">*</span></label>
                <select name="student_ids[]" id="sel-students" multiple placeholder="Type student ID or name…"></select>
                <div class="form-text">Only students of batch <strong><?= h($offer['batch_name']) ?></strong> can be enrolled.</div>
            </div>
            <div class="mb-3">
                <label class="form-label fw-medium d-flex align-items-center gap-2">
                    Subjects <span class="text-danger">*</span>
                    <button type="button" class="btn btn-link btn-sm p-0" onclick="toggleAllSubs(true)">Select all</button>
                    <span class="text-muted">/</span>
                    <button type="button" class="btn btn-link btn-sm p-0" onclick="toggleAllSubs(false)">none</button>
                </label>
                <div class="row g-2">
                    <?php foreach ($subjects as $s): ?>
                    <div class="col-md-6">
                        <div class="form-check">
                            <input class="form-check-input sub-check" type="checkbox"
                                   name="subject_ids[]" value="<?= (int)$s['id'] ?>" id="sub-<?= (int)$s['id'] ?>">
                            <label class="form-check-label small" for="sub-<?= (int)$s['id'] ?>">
                                <?php if ($s['course_code']): ?>
                                <span class="badge bg-light text-dark border me-1" style="font-family:monospace;"><?= h($s['course_code']) ?></span>
                                <?php endif; ?>
                                <?= h($s['course_name']) ?>
                            </label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                <i class="fas fa-plus me-1"></i>Enroll
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Registrations per subject -->
<?php foreach ($subjects as $s): $osid = (int)$s['id']; $regs = $reg_map[$osid] ?? []; ?>
<div class="card mb-3" style="border-radius:12px;">
    <div class="card-header py-2 px-4 d-flex align-items-center gap-2">
        <?php if ($s['course_code']): ?>
        <span class="badge bg-light text-dark border" style="font-family:monospace;"><?= h($s['course_code']) ?></span>
        <?php endif; ?>
        <span class="fw-semibold"><?= h($s['course_name']) ?></span>
        <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle ms-auto">
            <?= count($regs) ?> student<?= count($regs) != 1 ? 's' : '' ?>
        </span>
    </div>
    <?php if (empty($regs)): ?>
    <div class="card-body py-3 px-4 text-muted small fst-italic">No students registered yet.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0" style="font-size:.85rem;">
            <thead class="table-light">
                <tr>
                    <th style="width:2.5rem;">#</th>
                    <th>Student ID</th>
                    <th>Name</th>
                    <th style="width:5rem;">Section</th>
                    <th style="width:6rem;">Source</th>
                    <?php if (co_is_staff()): ?><th style="width:4rem;"></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($regs as $i => $r): ?>
                <tr>
                    <td class="text-muted"><?= $i + 1 ?></td>
                    <td><span class="font-monospace"><?= h($r['student_id']) ?></span></td>
                    <td><?= h($r['full_name']) ?></td>
                    <td><?= h($r['section'] ?: '—') ?></td>
                    <td>
                        <?php if ($r['source'] === 'admin'): ?>
                        <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">Admin</span>
                        <?php else: ?>
                        <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Self</span>
                        <?php endif; ?>
                    </td>
                    <?php if (co_is_staff()): ?>
                    <td>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Remove this registration?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="offer_id" value="<?= $offer_id ?>">
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="offer_subject_id" value="<?= $osid ?>">
                            <input type="hidden" name="student_id" value="<?= (int)$r['student_pk'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove">
                                <i class="fas fa-times"></i>
                            </button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php endif; ?>

<script>
function toggleAllSubs(on) {
    document.querySelectorAll('.sub-check').forEach(function (c) { c.checked = on; });
}
<?php if (co_is_staff() && !empty($subjects)): ?>
new TomSelect('#sel-students', {
    plugins: ['remove_button'],
    valueField: 'id',
    labelField: 'text',
    searchField: ['text'],
    load: function (query, callback) {
        fetch('<?= APP_URL ?>/course-offer/get-students.php?batch_id=<?= $batch_id ?>&q=' + encodeURIComponent(query))
            .then(function (r) { return r.json(); })
            .then(function (data) { callback(data); })
            .catch(function () { callback(); });
    },
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
