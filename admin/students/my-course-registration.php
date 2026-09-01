<?php
/**
 * Student Portal – Course Registration
 * =====================================
 * Lists the course offers of the student's batch that are open for
 * self-registration (Course Offer module). The student must select ALL
 * offered courses and submit – the registration is stored as PENDING and
 * approved by the department in Course Offer → Registrations.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';

if (!is_portal_student()) {
    flash_set('error', 'You do not have permission to access this section.');
    redirect(APP_URL . '/index.php');
}

$user = auth_user();

// ── Identify the student record ───────────────────────────────────────────
$student = null;
try {
    $stmt = db()->prepare(
        'SELECT s.id, s.student_id, s.full_name, s.batch_id
         FROM students s
         WHERE s.portal_user_id = ?
         LIMIT 1'
    );
    $stmt->execute([$user['id']]);
    $student = $stmt->fetch() ?: null;
} catch (Throwable $e) {}

if (!$student) {
    flash_set('error', 'No student profile is linked to your account. Please contact the administrator.');
    redirect(APP_URL . '/index.php');
}

$sid      = (int)$student['id'];
$batch_id = (int)$student['batch_id'];
$self_url = APP_URL . '/students/my-course-registration.php';

// Approval workflow column (admin/course-offer-approval-v1.sql)
$has_status = false;
try {
    db()->query('SELECT status FROM co_registrations LIMIT 1');
    $has_status = true;
} catch (Throwable $e) {}

// ── Submit registration (ALL offered courses required) ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register_all') {
    csrf_check();
    $offer_id = (int)($_POST['offer_id'] ?? 0);

    $ost = db()->prepare(
        "SELECT id, registration_open FROM co_offers
          WHERE id = ? AND batch_id = ? AND status = 'active'
          LIMIT 1"
    );
    $ost->execute([$offer_id, $batch_id]);
    $offer = $ost->fetch();

    if (!$offer) {
        flash_set('error', 'This course offer is not available for your batch.');
        redirect($self_url);
    }
    if ((int)$offer['registration_open'] !== 1) {
        flash_set('error', 'Self-registration is currently closed for this offer.');
        redirect($self_url);
    }

    $sst = db()->prepare('SELECT id FROM co_offer_subjects WHERE offer_id = ?');
    $sst->execute([$offer_id]);
    $all_subject_ids = array_map('intval', $sst->fetchAll(PDO::FETCH_COLUMN));

    if (empty($all_subject_ids)) {
        flash_set('error', 'This offer has no courses yet.');
        redirect($self_url);
    }

    // The student must register for EVERY offered course.
    $selected = array_map('intval', (array)($_POST['subject_ids'] ?? []));
    if (count(array_intersect($all_subject_ids, $selected)) !== count($all_subject_ids)) {
        flash_set('error', 'You must select ALL offered courses to submit your registration.');
        redirect($self_url);
    }

    $ins = $has_status
        ? db()->prepare(
            "INSERT IGNORE INTO co_registrations
                 (offer_subject_id, student_id, source, registered_by, status)
             VALUES (?, ?, 'self', NULL, 'pending')"
        )
        : db()->prepare(
            "INSERT IGNORE INTO co_registrations
                 (offer_subject_id, student_id, source, registered_by)
             VALUES (?, ?, 'self', NULL)"
        );
    foreach ($all_subject_ids as $osid) {
        $ins->execute([$osid, $sid]);
    }

    flash_set('success', $has_status
        ? 'Your course registration has been submitted and is awaiting departmental approval.'
        : 'Your course registration has been submitted.');
    redirect($self_url);
}

// ── Load offers + subjects + my registrations ────────────────────────────────
$offers = [];
try {
    $ost = db()->prepare(
        "SELECT o.id, o.semester, o.academic_intake, o.registration_open,
                d.name AS dept_name, p.program_name, b.name AS batch_name
           FROM co_offers o
           JOIN dept_departments       d ON d.id = o.dept_id
           JOIN dept_academic_programs p ON p.id = o.program_id
           JOIN student_batches        b ON b.id = o.batch_id
          WHERE o.batch_id = ? AND o.status = 'active'
          ORDER BY o.id DESC"
    );
    $ost->execute([$batch_id]);
    $offers = $ost->fetchAll();
} catch (Throwable $e) {}

$subjects_by_offer = [];
$my_regs = []; // offer_subject_id => 'pending' | 'approved'
if ($offers) {
    $offer_ids = array_map(static fn($o) => (int)$o['id'], $offers);
    $in = implode(',', array_fill(0, count($offer_ids), '?'));

    $sst = db()->prepare(
        "SELECT cos.id AS offer_subject_id, cos.offer_id,
                c.course_code, c.course_name, c.credit
           FROM co_offer_subjects cos
           JOIN course_curriculum c ON c.id = cos.curriculum_id
          WHERE cos.offer_id IN ($in)
          ORDER BY cos.offer_id ASC, cos.sort_order ASC, cos.id ASC"
    );
    $sst->execute($offer_ids);
    $subject_ids = [];
    foreach ($sst->fetchAll() as $row) {
        $subjects_by_offer[(int)$row['offer_id']][] = $row;
        $subject_ids[] = (int)$row['offer_subject_id'];
    }

    if ($subject_ids) {
        $rin = implode(',', array_fill(0, count($subject_ids), '?'));
        $rst = db()->prepare(
            'SELECT offer_subject_id' . ($has_status ? ', status' : '') . "
               FROM co_registrations
              WHERE student_id = ? AND offer_subject_id IN ($rin)"
        );
        $rst->execute(array_merge([$sid], $subject_ids));
        foreach ($rst->fetchAll() as $rr) {
            $my_regs[(int)$rr['offer_subject_id']] =
                $has_status ? (string)(($rr['status'] ?? '') ?: 'approved') : 'approved';
        }
    }
}

$page_title = 'Course Registration';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">Course Registration</h4>
</div>

<?php flash_show(); ?>

<?php
$visible = array_filter($offers, function ($o) use ($subjects_by_offer, $my_regs) {
    $subs = $subjects_by_offer[(int)$o['id']] ?? [];
    $mine = 0;
    foreach ($subs as $s) {
        if (isset($my_regs[(int)$s['offer_subject_id']])) $mine++;
    }
    return (int)$o['registration_open'] === 1 || $mine > 0;
});
?>

<?php if (empty($visible)): ?>
<div class="alert alert-info" style="border-radius:12px;">
    No course registration is open for your batch right now. When your department opens
    self-registration, the offered courses will appear here.
</div>
<?php endif; ?>

<?php foreach ($visible as $o): ?>
<?php
    $oid   = (int)$o['id'];
    $subs  = $subjects_by_offer[$oid] ?? [];
    $total = count($subs);
    $mine = 0; $pending = 0;
    foreach ($subs as $s) {
        $st = $my_regs[(int)$s['offer_subject_id']] ?? null;
        if ($st !== null) { $mine++; if ($st === 'pending') $pending++; }
    }
?>
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-header py-3 px-4 d-flex flex-wrap align-items-center gap-2">
        <div>
            <div class="fw-bold">
                <?= h($o['semester'] ?: 'Course Offer') ?>
                <?php if ($o['academic_intake']): ?> &middot; <?= h($o['academic_intake']) ?><?php endif; ?>
            </div>
            <div class="text-muted small">
                <?= h($o['dept_name']) ?> &rsaquo; <?= h($o['program_name']) ?> &middot; <?= h($o['batch_name']) ?>
            </div>
        </div>
        <?php if ($mine > 0): ?>
            <?php if ($pending > 0): ?>
            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle ms-auto">Pending approval</span>
            <?php else: ?>
            <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle ms-auto">Approved</span>
            <?php endif; ?>
        <?php elseif ((int)$o['registration_open'] === 1): ?>
        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle ms-auto">Registration open</span>
        <?php endif; ?>
    </div>
    <div class="card-body p-4">
        <?php if (empty($subs)): ?>
        <div class="text-muted fst-italic">No courses have been added to this offer yet.</div>
        <?php elseif ($mine > 0): ?>
        <!-- Already submitted: course list with per-course status -->
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="font-size:.875rem;">
                <thead class="table-light">
                    <tr><th>Course</th><th style="width:6rem;">Credit</th><th style="width:11rem;">Status</th></tr>
                </thead>
                <tbody>
                <?php foreach ($subs as $s): $st = $my_regs[(int)$s['offer_subject_id']] ?? null; ?>
                    <tr>
                        <td>
                            <?php if ($s['course_code']): ?>
                            <span class="badge bg-light text-dark border me-1" style="font-family:monospace;"><?= h($s['course_code']) ?></span>
                            <?php endif; ?>
                            <?= h($s['course_name']) ?>
                        </td>
                        <td><?= h($s['credit']) ?></td>
                        <td>
                            <?php if ($st === 'pending'): ?>
                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Pending approval</span>
                            <?php elseif ($st !== null): ?>
                            <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Approved</span>
                            <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">Not registered</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pending > 0): ?>
        <div class="text-muted small mt-3">
            <i class="fas fa-info-circle me-1"></i>
            Your registration is awaiting departmental approval. The status will update here once it is approved.
        </div>
        <?php endif; ?>
        <?php elseif ((int)$o['registration_open'] === 1): ?>
        <!-- Registration form: ALL courses must be selected -->
        <form method="POST" class="course-reg-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="register_all">
            <input type="hidden" name="offer_id" value="<?= $oid ?>">

            <div class="form-check mb-2 fw-semibold">
                <input class="form-check-input reg-select-all" type="checkbox" id="all-<?= $oid ?>">
                <label class="form-check-label" for="all-<?= $oid ?>">Select all courses</label>
            </div>
            <div class="row g-2 mb-3">
                <?php foreach ($subs as $s): $osid = (int)$s['offer_subject_id']; ?>
                <div class="col-md-6">
                    <div class="form-check">
                        <input class="form-check-input reg-check" type="checkbox"
                               name="subject_ids[]" value="<?= $osid ?>" id="c-<?= $osid ?>">
                        <label class="form-check-label" for="c-<?= $osid ?>">
                            <?php if ($s['course_code']): ?>
                            <span class="badge bg-light text-dark border me-1" style="font-family:monospace;"><?= h($s['course_code']) ?></span>
                            <?php endif; ?>
                            <?= h($s['course_name']) ?>
                            <span class="text-muted">(<?= h($s['credit']) ?> Cr)</span>
                        </label>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="text-muted small mb-3">
                <i class="fas fa-info-circle me-1"></i>
                You must select <strong>all <?= $total ?> offered courses</strong> to submit your registration.
                It will then be sent to your department for approval.
            </div>
            <button type="submit" class="btn btn-primary reg-submit" disabled style="border-radius:10px;">
                <i class="fas fa-check me-1"></i>Register (<?= $total ?> courses)
            </button>
        </form>
        <?php else: ?>
        <div class="text-muted fst-italic">Self-registration is currently closed for this offer.</div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<script>
document.querySelectorAll('.course-reg-form').forEach(function (form) {
    var checks = Array.prototype.slice.call(form.querySelectorAll('.reg-check'));
    var all    = form.querySelector('.reg-select-all');
    var submit = form.querySelector('.reg-submit');

    function refresh() {
        var allChecked = checks.length > 0 && checks.every(function (c) { return c.checked; });
        submit.disabled = !allChecked;
        all.checked = allChecked;
    }
    checks.forEach(function (c) { c.addEventListener('change', refresh); });
    all.addEventListener('change', function () {
        checks.forEach(function (c) { c.checked = all.checked; });
        refresh();
    });
    form.addEventListener('submit', function (e) {
        if (submit.disabled) { e.preventDefault(); return; }
        if (!confirm('Register for all ' + checks.length + ' offered courses? Your registration will be sent for departmental approval.')) {
            e.preventDefault();
        }
    });
    refresh();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
