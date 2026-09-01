<?php
/**
 * ID Card – Bulk Create (Batch / Department / Program wise)
 *
 * Two-step: Preview the matching students first, then Confirm. The student
 * list is recomputed server-side at confirm time — nothing from the browser
 * is trusted. Existing cards (same card_type + id_number) are refreshed via
 * the same upsert used by single creation, so re-running is always safe.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('id-card', 'can_create');
require_once __DIR__ . '/helpers.php';

$page_title = 'Bulk Create ID Cards';
$db = db();
$errors = [];

const IDC_BULK_MAX = 2000;

$depts = $db->query('SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
$programs = $db->query('SELECT id, dept_id, program_name FROM dept_academic_programs WHERE is_active = 1 ORDER BY program_name ASC')->fetchAll();
$batches = $db->query('SELECT id, name FROM student_batches WHERE is_active = 1 ORDER BY sort_order, name ASC')->fetchAll();

$f_dept        = (int)($_POST['dept_id'] ?? $_GET['dept_id'] ?? 0);
$f_program     = (int)($_POST['program_id'] ?? $_GET['program_id'] ?? 0);
$f_batch       = (int)($_POST['batch_id'] ?? $_GET['batch_id'] ?? 0);
$f_active_only = (int)($_POST['active_only'] ?? $_GET['active_only'] ?? 1) === 1;
$issue_date    = trim((string)($_POST['issue_date'] ?? date('Y-m-d')));
$expiry_date   = trim((string)($_POST['expiry_date'] ?? date('Y-m-d', strtotime('+4 years'))));

/**
 * Students matching the selected Batch / Department / Program. The batch
 * filter matches the home batch OR an active batch transfer (same rule as
 * Student Management). Includes any existing student card id for the preview.
 */
function idc_bulk_students(int $dept, int $program, int $batch, bool $active_only): array
{
    $where  = '';
    $params = [];
    if ($dept > 0)    { $where .= ' AND s.dept_id = ?';    $params[] = $dept; }
    if ($program > 0) { $where .= ' AND s.program_id = ?'; $params[] = $program; }
    if ($batch > 0) {
        $where .= ' AND (s.batch_id = ? OR s.id IN (SELECT sbt.student_id FROM student_batch_transfers sbt WHERE sbt.to_batch_id = ? AND sbt.is_active = 1))';
        $params[] = $batch;
        $params[] = $batch;
    }
    if ($active_only) { $where .= " AND s.status = 'Active'"; }

    $stmt = db()->prepare(
        "SELECT s.id, s.student_id, s.full_name, s.photo, s.blood_group, s.phone,
                s.present_address, s.permanent_address, s.status,
                d.name AS dept_name, p.program_name, b.name AS batch_name,
                c.id AS existing_card_id
         FROM students s
         LEFT JOIN dept_departments d       ON d.id = s.dept_id
         LEFT JOIN dept_academic_programs p ON p.id = s.program_id
         LEFT JOIN student_batches b        ON b.id = s.batch_id
         LEFT JOIN idc_cards c              ON c.card_type = 'student' AND c.id_number = s.student_id
         WHERE 1=1 $where
         ORDER BY s.student_id
         LIMIT " . IDC_BULK_MAX
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

$students = null;
$action = (string)($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if ($f_dept <= 0 && $f_program <= 0 && $f_batch <= 0) {
        $errors[] = 'Select at least one filter (Batch, Department or Program).';
    }
    if ($issue_date === '' || !strtotime($issue_date))   { $errors[] = 'Invalid issue date.'; }
    if ($expiry_date === '' || !strtotime($expiry_date)) { $errors[] = 'Invalid expiry date.'; }

    if (!$errors && $action === 'preview') {
        $students = idc_bulk_students($f_dept, $f_program, $f_batch, $f_active_only);
    } elseif (!$errors && $action === 'create') {
        $list = idc_bulk_students($f_dept, $f_program, $f_batch, $f_active_only);
        if (!$list) {
            $errors[] = 'No students match the selected filters.';
        } else {
            $created = 0;
            $updated = 0;
            $failed  = 0;
            $st = $db->prepare(
                'INSERT INTO idc_cards
                    (card_type, student_ref_id, id_number, full_name, program_name, dept_name,
                     designation, batch_name, blood_group, phone, address, photo,
                     issue_date, expiry_date, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    full_name=VALUES(full_name), program_name=VALUES(program_name),
                    dept_name=VALUES(dept_name), designation=VALUES(designation),
                    batch_name=VALUES(batch_name), blood_group=VALUES(blood_group),
                    phone=VALUES(phone), address=VALUES(address), photo=VALUES(photo),
                    issue_date=VALUES(issue_date), expiry_date=VALUES(expiry_date)'
            );
            $uid = auth_user()['id'];
            foreach ($list as $s) {
                try {
                    $st->execute([
                        'student', (int)$s['id'], (string)$s['student_id'], (string)$s['full_name'],
                        ($s['program_name'] ?? '') !== '' ? $s['program_name'] : null,
                        ($s['dept_name'] ?? '') !== '' ? $s['dept_name'] : null,
                        null,
                        ($s['batch_name'] ?? '') !== '' ? $s['batch_name'] : null,
                        ($s['blood_group'] ?? '') !== '' ? $s['blood_group'] : null,
                        ($s['phone'] ?? '') !== '' ? $s['phone'] : null,
                        (($s['present_address'] ?? '') !== '' ? $s['present_address'] : ($s['permanent_address'] ?? null)) ?: null,
                        ($s['photo'] ?? '') !== '' ? $s['photo'] : null,
                        $issue_date, $expiry_date, $uid,
                    ]);
                    if (!empty($s['existing_card_id'])) { $updated++; } else { $created++; }
                } catch (Throwable $e) {
                    $failed++;
                    error_log('id-card bulk-create failed for student ' . $s['student_id'] . ': ' . $e->getMessage());
                }
            }
            $msg = 'Bulk ID card creation complete: ' . $created . ' created, ' . $updated . ' refreshed.'
                 . ($failed > 0 ? ' ' . $failed . ' failed (see error log).' : '');
            flash_set($failed > 0 ? 'warning' : 'success', $msg);
            redirect(APP_URL . '/id-card/index.php');
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/id-card/index.php">ID Cards</a></li>
            <li class="breadcrumb-item active">Bulk Create</li>
        </ol>
    </nav>
</div>

<?= flash_show() ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-layer-group me-2 text-muted"></i>1 · Select Batch / Department / Program</h6>
    </div>
    <div class="card-body p-4">
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="preview">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-medium">Batch</label>
                    <select name="batch_id" class="form-select">
                        <option value="0">— All Batches —</option>
                        <?php foreach ($batches as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= $f_batch === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Department</label>
                    <select name="dept_id" class="form-select">
                        <option value="0">— All Departments —</option>
                        <?php foreach ($depts as $d): ?>
                        <option value="<?= (int)$d['id'] ?>" <?= $f_dept === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Program</label>
                    <select name="program_id" class="form-select">
                        <option value="0">— All Programs —</option>
                        <?php foreach ($programs as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= $f_program === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['program_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Issue Date</label>
                    <input type="date" name="issue_date" class="form-control" value="<?= h($issue_date) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Valid Until</label>
                    <input type="date" name="expiry_date" class="form-control" value="<?= h($expiry_date) ?>">
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="active_only" value="1"
                               id="idcActiveOnly" <?= $f_active_only ? 'checked' : '' ?>>
                        <label class="form-check-label" for="idcActiveOnly">Active students only</label>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary"><i class="fas fa-magnifying-glass me-1"></i> Preview Students</button>
                <a href="<?= APP_URL ?>/id-card/bulk-create.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<?php if ($students !== null): ?>
<div class="card">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-clipboard-check me-2 text-muted"></i>2 · Preview — <?= count($students) ?> student(s)
            <?php if (count($students) >= IDC_BULK_MAX): ?>
            <span class="badge bg-warning text-dark ms-1">limit <?= IDC_BULK_MAX ?> reached — narrow the filters</span>
            <?php endif; ?>
        </h6>
        <?php if ($students): ?>
        <form method="POST" onsubmit="return confirm('Create / refresh ID cards for <?= count($students) ?> student(s)?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="batch_id" value="<?= (int)$f_batch ?>">
            <input type="hidden" name="dept_id" value="<?= (int)$f_dept ?>">
            <input type="hidden" name="program_id" value="<?= (int)$f_program ?>">
            <input type="hidden" name="active_only" value="<?= $f_active_only ? 1 : 0 ?>">
            <input type="hidden" name="issue_date" value="<?= h($issue_date) ?>">
            <input type="hidden" name="expiry_date" value="<?= h($expiry_date) ?>">
            <button type="submit" class="btn btn-success"><i class="fas fa-id-card me-1"></i> Create <?= count($students) ?> ID Card(s)</button>
        </form>
        <?php endif; ?>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-sm align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Student ID</th>
                    <th>Name</th>
                    <th>Program (short on card)</th>
                    <th>Batch</th>
                    <th>Blood</th>
                    <th>Photo</th>
                    <th>Card</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$students): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No students match the selected filters.</td></tr>
            <?php endif; ?>
            <?php foreach ($students as $s): ?>
                <tr>
                    <td class="fw-semibold"><?= h($s['student_id']) ?></td>
                    <td><?= h($s['full_name']) ?></td>
                    <td class="small">
                        <?= h(idc_short_program_name((string)($s['program_name'] ?? ''))) ?>
                        <div class="text-muted" style="font-size:.7rem"><?= h((string)($s['program_name'] ?? '')) ?></div>
                    </td>
                    <td class="small"><?= h((string)($s['batch_name'] ?? '')) ?></td>
                    <td class="small"><?= h((string)($s['blood_group'] ?? '')) ?></td>
                    <td><?= trim((string)($s['photo'] ?? '')) !== '' ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?></td>
                    <td><?= !empty($s['existing_card_id']) ? '<span class="badge bg-info text-dark">Will refresh</span>' : '<span class="badge bg-primary">New</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
