<?php
/**
 * Admit Card – View (admin)
 * Shows card details, eligible students list, overrides management.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('admit-card');
require_once __DIR__ . '/helpers.php';

$id   = (int)($_GET['id'] ?? 0);
$card = ac_get_card($id);
if (!$card) {
    flash_set('error', 'Admit card not found.');
    redirect(APP_URL . '/admit-card/index.php');
}

$page_title = 'Admit Card – ' . $card['exam_name'];
$db = db();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // Toggle active status
    if ($action === 'toggle_active' && ac_can_edit()) {
        $new = $card['is_active'] ? 0 : 1;
        $db->prepare('UPDATE ac_admit_cards SET is_active=? WHERE id=?')->execute([$new, $id]);
        flash_set('success', 'Status updated.');
        redirect(APP_URL . '/admit-card/view.php?id=' . $id);
    }

    // Add override
    if ($action === 'add_override' && ac_can_edit()) {
        $sid  = (int)($_POST['student_id'] ?? 0);
        $note = trim($_POST['override_note'] ?? '');
        if ($sid > 0) {
            try {
                $db->prepare(
                    'INSERT INTO ac_student_overrides (admit_card_id, student_id, allowed_by, note)
                     VALUES (?,?,?,?)
                     ON DUPLICATE KEY UPDATE allowed_by=VALUES(allowed_by), note=VALUES(note), created_at=NOW()'
                )->execute([$id, $sid, auth_user()['id'], $note ?: null]);
                flash_set('success', 'Override granted for student.');
            } catch (Throwable $e) {
                flash_set('error', 'Could not add override: ' . $e->getMessage());
            }
        }
        redirect(APP_URL . '/admit-card/view.php?id=' . $id);
    }

    // Remove override
    if ($action === 'remove_override' && ac_can_edit()) {
        $sid = (int)($_POST['student_id'] ?? 0);
        $db->prepare('DELETE FROM ac_student_overrides WHERE admit_card_id=? AND student_id=?')->execute([$id, $sid]);
        flash_set('success', 'Override removed.');
        redirect(APP_URL . '/admit-card/view.php?id=' . $id);
    }
}

$courses = ac_get_courses($id);

// Students matching this card's dept+program (and optionally batch)
$batch_cond = '';
$batch_params = [];
if ($card['batch_id']) {
    $batch_cond   = ' AND s.batch_id = ?';
    $batch_params = [$card['batch_id']];
}

$routine_id = (int)($card['routine_id'] ?? 0);
if ($routine_id > 0) {
    // Routine-linked card: only students enrolled (registered) in the
    // routine's courses are eligible.
    $students_stmt = $db->prepare(
        "SELECT s.id, s.student_id, s.full_name, s.status, s.photo,
                p.id AS pkg_id
         FROM students s
         JOIN co_registrations reg ON reg.student_id = s.id
         JOIN exam_routine_items i ON i.offer_subject_id = reg.offer_subject_id AND i.routine_id = ?
         LEFT JOIN sfp_packages p ON p.student_id = s.id
         WHERE s.status NOT IN ('Withdrawn','Expelled')
         GROUP BY s.id
         ORDER BY s.full_name ASC"
    );
    $students_stmt->execute([$routine_id]);
} else {
    $students_stmt = $db->prepare(
        "SELECT s.id, s.student_id, s.full_name, s.status, s.photo,
                p.id AS pkg_id
         FROM students s
         LEFT JOIN sfp_packages p ON p.student_id = s.id
         WHERE s.dept_id = ? AND s.program_id = ? $batch_cond
           AND s.status NOT IN ('Withdrawn','Expelled')
         GROUP BY s.id
         ORDER BY s.full_name ASC"
    );
    $students_stmt->execute(array_merge([$card['dept_id'], $card['program_id']], $batch_params));
}
$students = $students_stmt->fetchAll();

// Existing overrides
$ov_stmt = $db->prepare(
    'SELECT ov.*, s.full_name AS student_name, s.student_id AS student_sid, u.full_name AS allowed_by_name
     FROM ac_student_overrides ov
     JOIN students s ON s.id = ov.student_id
     JOIN users u ON u.id = ov.allowed_by
     WHERE ov.admit_card_id = ?
     ORDER BY ov.created_at DESC'
);
$ov_stmt->execute([$id]);
$overrides = $ov_stmt->fetchAll();
$override_ids = array_column($overrides, 'student_id');

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-id-card me-2 text-primary"></i><?= h($card['exam_name']) ?></h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admit-card/index.php">Admit Cards</a></li>
            <li class="breadcrumb-item active"><?= h($card['exam_name']) ?></li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (ac_can_edit()): ?>
        <form method="post" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_active">
            <button class="btn btn-sm <?= $card['is_active'] ? 'btn-outline-secondary' : 'btn-outline-success' ?>">
                <i class="fas fa-<?= $card['is_active'] ? 'pause' : 'play' ?> me-1"></i>
                <?= $card['is_active'] ? 'Deactivate' : 'Activate' ?>
            </button>
        </form>
        <a href="<?= APP_URL ?>/admit-card/edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-edit me-1"></i> Edit
        </a>
        <?php endif; ?>
    </div>
</div>

<?php flash_show(); ?>

<div class="row g-4">
    <!-- Card info -->
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header py-3 px-4 fw-semibold">Card Details</div>
            <div class="card-body px-4">
                <table class="table table-sm mb-0">
                    <tr><th class="text-muted fw-normal" style="width:40%">Exam Name</th><td class="fw-semibold"><?= h($card['exam_name']) ?></td></tr>
                    <tr><th class="text-muted fw-normal">Semester</th><td><?= h($card['semester']) ?></td></tr>
                    <tr><th class="text-muted fw-normal">Department</th><td><?= h($card['dept_name']) ?></td></tr>
                    <tr><th class="text-muted fw-normal">Program</th><td><?= h($card['program_name']) ?></td></tr>
                    <tr><th class="text-muted fw-normal">Batch</th><td><?= h($card['batch_label'] ?? ($card['batch_name_db'] ?? '—')) ?></td></tr>
                    <tr><th class="text-muted fw-normal">Status</th>
                        <td><?= $card['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
                    </tr>
                    <tr><th class="text-muted fw-normal">Created by</th><td><?= h($card['created_by_name'] ?? '—') ?></td></tr>
                    <tr><th class="text-muted fw-normal">Created</th><td><?= date('d M Y, H:i', strtotime($card['created_at'])) ?></td></tr>
                </table>
            </div>
        </div>

        <!-- Courses -->
        <div class="card mt-3">
            <div class="card-header py-3 px-4 fw-semibold">Courses (<?= count($courses) ?>)</div>
            <div class="card-body p-0">
                <?php if (empty($courses)): ?>
                <p class="text-muted text-center py-4 mb-0">No courses added yet.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr><th class="px-3">Code</th><th>Title</th><th>Date</th><th>Time</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($courses as $c): ?>
                        <tr>
                            <td class="px-3"><?= h($c['course_code']) ?></td>
                            <td><?= h($c['course_title']) ?></td>
                            <td><?= $c['exam_date'] ? date('d-m-Y', strtotime($c['exam_date'])) : '—' ?></td>
                            <td><?= h($c['time_slot'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right: Students & Overrides -->
    <div class="col-lg-7">
        <!-- Add override -->
        <?php if (ac_can_edit()): ?>
        <div class="card mb-3">
            <div class="card-header py-3 px-4 fw-semibold">
                <i class="fas fa-unlock-alt me-2 text-warning"></i>Grant Download Override
            </div>
            <div class="card-body px-4">
                <p class="text-muted small">Allow a specific student to download this admit card even if their dues exceed ৳500.</p>
                <form method="post" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_override">
                    <div class="col-md-5">
                        <select name="student_id" class="form-select form-select-sm" required>
                            <option value="">— Select Student —</option>
                            <?php foreach ($students as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= in_array((int)$s['id'], array_map('intval', $override_ids)) ? 'disabled' : '' ?>>
                                <?= h($s['student_id'] . ' – ' . $s['full_name']) ?>
                                <?= in_array((int)$s['id'], array_map('intval', $override_ids)) ? ' (already overridden)' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <input type="text" name="override_note" class="form-control form-control-sm" placeholder="Reason (optional)">
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-sm btn-warning"><i class="fas fa-plus me-1"></i>Grant Override</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Current overrides -->
        <?php if (!empty($overrides)): ?>
        <div class="card mb-3">
            <div class="card-header py-3 px-4 fw-semibold">
                <i class="fas fa-unlock me-2 text-warning"></i>Active Overrides (<?= count($overrides) ?>)
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr><th class="px-3">Student</th><th>Granted by</th><th>Note</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($overrides as $ov): ?>
                        <tr>
                            <td class="px-3">
                                <div class="fw-semibold"><?= h($ov['student_name']) ?></div>
                                <small class="text-muted"><?= h($ov['student_sid']) ?></small>
                            </td>
                            <td><?= h($ov['allowed_by_name']) ?></td>
                            <td class="text-muted small"><?= h($ov['note'] ?? '—') ?></td>
                            <td>
                                <?php if (ac_can_edit()): ?>
                                <form method="post" class="d-inline"
                                      onsubmit="return confirm('Remove this override?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="remove_override">
                                    <input type="hidden" name="student_id" value="<?= $ov['student_id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger"><i class="fas fa-times"></i></button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Students list -->
        <div class="card">
            <div class="card-header py-3 px-4 fw-semibold">
                <i class="fas fa-users me-2 text-info"></i>Eligible Students (<?= count($students) ?>)
            </div>
            <div class="card-body p-0">
                <?php if (empty($students)): ?>
                <p class="text-muted text-center py-4 mb-0">No students found for this dept/program combination.</p>
                <?php else: ?>
                <div class="table-responsive" style="max-height:400px;overflow-y:auto;">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th class="px-3">Student</th>
                                <th>Status</th>
                                <th>Due Check</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($students as $s):
                            $access = ac_check_access($id, (int)$s['id']);
                        ?>
                        <tr>
                            <td class="px-3">
                                <div class="fw-semibold"><?= h($s['full_name']) ?></div>
                                <small class="text-muted"><?= h($s['student_id']) ?></small>
                            </td>
                            <td><span class="badge bg-info-subtle text-info border"><?= h($s['status']) ?></span></td>
                            <td>
                                <?php if ($access['allowed']): ?>
                                    <span class="badge bg-success"><i class="fas fa-check me-1"></i>Allowed</span>
                                <?php else: ?>
                                    <span class="badge bg-danger" title="<?= h($access['reason'] ?? '') ?>">
                                        <i class="fas fa-lock me-1"></i>Blocked (৳<?= number_format($access['due'] ?? 0, 0) ?>)
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?= APP_URL ?>/admit-card/download.php?card=<?= $id ?>&student=<?= $s['id'] ?>"
                                   class="btn btn-xs btn-outline-primary" style="font-size:.75rem;padding:2px 8px;"
                                   target="_blank">
                                    <i class="fas fa-download me-1"></i>PDF
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
