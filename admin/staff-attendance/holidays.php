<?php
/**
 * Super Admin / module editor: staff holidays.
 * Dated holidays suppress "Absent" for that day and are highlighted in reports.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('staff-attendance', 'can_edit');
require_once __DIR__ . '/helpers.php';

$page_title = 'Staff Holidays';
$db         = db();

// ── Handlers ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'add';

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare('DELETE FROM att_holidays WHERE id = ?')->execute([$id]);
            log_change('staff-attendance', 'DELETE', $id, 'Holiday');
            flash_set('success', 'Holiday removed.');
        }
    } else {
        $date  = att_normalize_date($_POST['holiday_date'] ?? '');
        $title = trim($_POST['title'] ?? '');
        if ($title !== '') $title = mb_substr($title, 0, 200);

        if ($title === '') {
            flash_set('error', 'Please enter a holiday title.');
        } elseif (($_POST['holiday_date'] ?? '') === '' || strtotime($_POST['holiday_date']) === false) {
            flash_set('error', 'Please choose a valid date.');
        } else {
            $stmt = $db->prepare(
                'INSERT INTO att_holidays (holiday_date, title) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE title = VALUES(title)'
            );
            $stmt->execute([$date, $title]);
            log_change('staff-attendance', 'CREATE', (int)$db->lastInsertId(), $title, null, null, $date);
            flash_set('success', 'Holiday saved.');
        }
    }
    redirect(APP_URL . '/staff-attendance/holidays.php');
}

// ── Listing (upcoming first, then past) ──────────────────────────────────────
$year = (int)($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) $year = (int)date('Y');
$stmt = $db->prepare('SELECT * FROM att_holidays WHERE YEAR(holiday_date) = ? ORDER BY holiday_date ASC');
$stmt->execute([$year]);
$holidays = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/staff-attendance/index.php">Staff Attendance</a></li>
            <li class="breadcrumb-item active">Holidays</li>
        </ol>
    </nav>
</div>

<?= flash_show() ?>

<div class="row">
    <div class="col-lg-5">
        <div class="card mb-4" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-calendar-plus me-2 text-primary"></i>Add / Update Holiday</h6>
            </div>
            <div class="card-body">
                <form method="POST" class="row g-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">
                    <div class="col-12">
                        <label class="form-label fw-semibold small mb-1">Date</label>
                        <input type="date" name="holiday_date" class="form-control" value="<?= h(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold small mb-1">Title</label>
                        <input type="text" name="title" class="form-control" maxlength="200" placeholder="e.g. Victory Day" required>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Holiday</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-calendar-day me-2 text-muted"></i>Holidays <?= $year ?>
                    <span class="badge bg-secondary ms-1"><?= count($holidays) ?></span>
                </h6>
                <form method="get" class="d-flex gap-2">
                    <input type="number" name="year" class="form-control form-control-sm" style="width:110px;"
                           value="<?= $year ?>" min="2000" max="2100" onchange="this.form.submit()">
                </form>
            </div>
            <div class="card-body p-0">
                <?php if (empty($holidays)): ?>
                    <p class="text-muted p-4 mb-0">No holidays defined for <?= $year ?>.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr><th class="px-3">Date</th><th>Day</th><th>Title</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($holidays as $hd): ?>
                            <tr>
                                <td class="px-3"><?= h(date('d M Y', strtotime($hd['holiday_date']))) ?></td>
                                <td class="small text-muted"><?= h(date('l', strtotime($hd['holiday_date']))) ?></td>
                                <td><?= h($hd['title']) ?></td>
                                <td class="text-end pe-3">
                                    <form method="POST" onsubmit="return confirm('Remove this holiday?');" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$hd['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" style="border-radius:8px;"><i class="fas fa-trash"></i></button>
                                    </form>
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
