<?php
/**
 * Student Portal – Bulk Account Creation
 *
 * Allows admins to select students via filters and create portal accounts
 * in bulk using the default password (12345678).
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('students');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';

if (!sm_is_staff()) {
    flash_set('error', 'You do not have permission to create portal accounts.');
    redirect(APP_URL . '/students/index.php');
}

$page_title = 'Bulk Create Portal Accounts';
$user       = auth_user();

// ── Filters ──────────────────────────────────────────────────────────────────
$search     = trim($_GET['search']   ?? '');
$f_dept     = (int)($_GET['dept']    ?? 0);
$f_status   = $_GET['status']        ?? '';
$f_sem      = trim($_GET['semester'] ?? '');
$f_sem_type = trim($_GET['sem_type'] ?? '');
$f_program  = (int)($_GET['program'] ?? 0);
$f_batch    = (int)($_GET['batch']   ?? 0);

$valid_statuses  = ['Active', 'Inactive', 'Graduated', 'Dropped', 'Not Admitted Yet'];
$valid_sem_types = ['bi_semester', 'trimester'];
$dept_scope      = get_dept_scope();

$where  = ['s.portal_user_id IS NULL', 's.email IS NOT NULL', "s.email != ''"];
$params = [];

if ($search !== '') {
    $like     = '%' . $search . '%';
    $where[]  = '(s.student_id LIKE ? OR s.full_name LIKE ? OR s.email LIKE ? OR s.phone LIKE ?)';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($f_dept > 0) {
    $where[]  = 's.dept_id = ?';
    $params[] = $f_dept;
}
if (in_array($f_status, $valid_statuses, true)) {
    $where[]  = 's.status = ?';
    $params[] = $f_status;
}
if ($f_sem !== '') {
    $where[]  = 's.admitted_semester = ?';
    $params[] = $f_sem;
}
if (in_array($f_sem_type, $valid_sem_types, true)) {
    $where[]  = 's.semester_type = ?';
    $params[] = $f_sem_type;
}
if ($f_program > 0) {
    $where[]  = 's.program_id = ?';
    $params[] = $f_program;
}
if ($f_batch > 0) {
    $where[]  = 's.batch_id = ?';
    $params[] = $f_batch;
}
if ($dept_scope !== null) {
    if (empty($dept_scope)) {
        $where[] = '0 = 1';
    } else {
        $phs     = implode(',', array_fill(0, count($dept_scope), '?'));
        $where[] = "s.dept_id IN ($phs)";
        array_push($params, ...$dept_scope);
    }
}

$where_sql = ' WHERE ' . implode(' AND ', $where);

// ── Execute bulk creation ─────────────────────────────────────────────────────
$results = null; // null = not yet run

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_create') {
    csrf_check();

    // Fetch all eligible students matching the filters
    $stmt = db()->prepare(
        'SELECT s.id, s.student_id, s.full_name, s.email, s.phone,
                d.name AS dept_name
         FROM students s
         JOIN dept_departments d ON d.id = s.dept_id'
        . $where_sql
        . ' ORDER BY s.student_id ASC'
    );
    $stmt->execute($params);
    $eligible = $stmt->fetchAll();

    // Determine target user group
    $group_name = sp_get_setting('default_group_name', 'Students');
    $grp_stmt   = db()->prepare('SELECT id FROM user_groups WHERE name = ? AND is_active = 1 LIMIT 1');
    $grp_stmt->execute([$group_name]);
    $grp = $grp_stmt->fetch();
    if (!$grp) {
        flash_set('error', 'User group "' . h($group_name) . '" not found. Please create it in User Groups or update Portal Settings.');
        redirect(APP_URL . '/students/portal-bulk-create.php?' . http_build_query($_GET));
    }
    $group_id = (int)$grp['id'];

    $plain_password = '12345678';
    $hash           = password_hash($plain_password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);

    $results = ['created' => [], 'skipped' => [], 'errors' => []];

    foreach ($eligible as $stu) {
        $sid = (int)$stu['id'];

        // Skip if email already used
        $dup = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $dup->execute([$stu['email']]);
        if ($dup->fetch()) {
            $results['skipped'][] = $stu['student_id'] . ' – email already used (' . $stu['email'] . ')';
            continue;
        }

        // Build username
        $base_username = preg_replace('/[^a-zA-Z0-9_]/', '', $stu['student_id']);
        if ($base_username === '') {
            $base_username = 'stu' . $sid;
        }
        $username = $base_username;
        $dup_user = db()->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $dup_user->execute([$username]);
        if ($dup_user->fetch()) {
            $username = $base_username . '_' . $sid;
        }

        try {
            $db = db();
            $db->prepare(
                'INSERT INTO users (group_id, username, email, password, full_name, phone, student_sid, is_active)
                 VALUES (?,?,?,?,?,?,?,1)'
            )->execute([
                $group_id,
                $username,
                $stu['email'],
                $hash,
                $stu['full_name'],
                $stu['phone'] ?: null,
                $stu['student_id'],
            ]);
            $new_user_id = (int)$db->lastInsertId();

            $db->prepare(
                'INSERT IGNORE INTO user_group_assignments (user_id, group_id, is_primary) VALUES (?,?,1)'
            )->execute([$new_user_id, $group_id]);

            $db->prepare('UPDATE students SET portal_user_id = ? WHERE id = ?')
               ->execute([$new_user_id, $sid]);

            log_change('students', 'UPDATE', $sid,
                $stu['full_name'] . ' (' . $stu['student_id'] . ')',
                'portal_user_id', null, (string)$new_user_id,
                'Bulk portal account created (user_id=' . $new_user_id . ', username=' . $username . ')');

            $email_sent = 0;
            $sms_sent   = 0;

            if (sp_get_setting('email_enabled', '1') === '1') {
                $email_sent = (int)send_template_email(
                    'student_portal_welcome',
                    $stu['email'],
                    $stu['full_name'],
                    [
                        'full_name'  => $stu['full_name'],
                        'student_id' => $stu['student_id'],
                        'username'   => $username,
                        'password'   => $plain_password,
                        'login_url'  => APP_URL . '/login.php',
                    ]
                );
            }

            if (sp_get_setting('sms_enabled', '0') === '1' && !empty($stu['phone'])) {
                $sms_sent = (int)sp_send_welcome_sms($stu, $username, $plain_password);
            }

            $db->prepare(
                'INSERT INTO student_portal_log (student_id, user_id, action, email_sent, sms_sent, created_by)
                 VALUES (?,?,?,?,?,?)'
            )->execute([$sid, $new_user_id, 'created', $email_sent, $sms_sent, $user['id']]);

            $results['created'][] = [
                'student_id' => $stu['student_id'],
                'name'       => $stu['full_name'],
                'username'   => $username,
                'email_sent' => $email_sent,
                'sms_sent'   => $sms_sent,
            ];
        } catch (Throwable $ex) {
            $results['errors'][] = $stu['student_id'] . ' – ' . $ex->getMessage();
        }
    }
}

// ── Departments / Programs / Batches for filter ───────────────────────────────
$departments = db()->query(
    'SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
)->fetchAll();
if ($dept_scope !== null) {
    $departments = array_values(array_filter(
        $departments,
        fn($d) => in_array((int)$d['id'], $dept_scope, true)
    ));
}

$all_programs = sm_program_data();
if ($dept_scope !== null) {
    $all_programs = array_values(array_filter(
        $all_programs,
        fn($p) => in_array((int)$p['dept_id'], $dept_scope, true)
    ));
}

$batches = sm_batches();

// Preview count (students eligible = no portal account yet, has email)
$count_stmt = db()->prepare('SELECT COUNT(*) FROM students s JOIN dept_departments d ON d.id = s.dept_id' . $where_sql);
$count_stmt->execute($params);
$preview_count = (int)$count_stmt->fetchColumn();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/students/index.php">Students</a></li>
            <li class="breadcrumb-item active">Bulk Create Portal Accounts</li>
        </ol>
    </nav>
    <div class="d-flex gap-2">
        <?php if (is_super_admin()): ?>
        <a href="<?= APP_URL ?>/users/sync-student-ids.php" class="btn btn-outline-primary btn-sm" style="border-radius:10px;">
            <i class="fas fa-sync-alt me-1"></i> Sync Student IDs
        </a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/students/portal-settings.php" class="btn btn-outline-secondary btn-sm" style="border-radius:10px;">
            <i class="fas fa-cog me-1"></i> Portal Settings
        </a>
    </div>
</div>

<?php flash_show(); ?>

<?php if ($results !== null): ?>
<!-- Results -->
<div class="card mb-4">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-check-circle me-2 text-success"></i>Bulk Creation Results</h6>
    </div>
    <div class="card-body p-4">
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="p-3 rounded-3" style="background:#f0fdf4;border:1px solid #bbf7d0;">
                    <div class="fw-bold text-success fs-4"><?= count($results['created']) ?></div>
                    <div class="text-muted" style="font-size:.85rem;">Accounts Created</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 rounded-3" style="background:#fefce8;border:1px solid #fde68a;">
                    <div class="fw-bold text-warning fs-4"><?= count($results['skipped']) ?></div>
                    <div class="text-muted" style="font-size:.85rem;">Skipped</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 rounded-3" style="background:#fef2f2;border:1px solid #fecaca;">
                    <div class="fw-bold text-danger fs-4"><?= count($results['errors']) ?></div>
                    <div class="text-muted" style="font-size:.85rem;">Errors</div>
                </div>
            </div>
        </div>

        <?php if (!empty($results['created'])): ?>
        <h6 class="fw-semibold mb-2 text-success"><i class="fas fa-check me-1"></i>Created (<?= count($results['created']) ?>)</h6>
        <div class="table-responsive mb-4">
            <table class="table table-sm table-hover">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Student ID</th>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>SMS</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($results['created'] as $i => $r): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><code class="text-primary"><?= h($r['student_id']) ?></code></td>
                    <td><?= h($r['name']) ?></td>
                    <td><code><?= h($r['username']) ?></code></td>
                    <td><?= $r['email_sent'] ? '<i class="fas fa-check text-success"></i>' : '<i class="fas fa-times text-muted"></i>' ?></td>
                    <td><?= $r['sms_sent']   ? '<i class="fas fa-check text-success"></i>' : '<i class="fas fa-times text-muted"></i>' ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if (!empty($results['skipped'])): ?>
        <h6 class="fw-semibold mb-2 text-warning"><i class="fas fa-exclamation-triangle me-1"></i>Skipped</h6>
        <ul class="list-unstyled" style="font-size:.875rem;">
            <?php foreach ($results['skipped'] as $s): ?>
            <li class="text-muted"><i class="fas fa-minus me-1"></i><?= h($s) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <?php if (!empty($results['errors'])): ?>
        <h6 class="fw-semibold mb-2 text-danger"><i class="fas fa-times-circle me-1"></i>Errors</h6>
        <ul class="list-unstyled" style="font-size:.875rem;">
            <?php foreach ($results['errors'] as $e): ?>
            <li class="text-danger"><i class="fas fa-exclamation-circle me-1"></i><?= h($e) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Filter Card -->
<div class="card mb-4">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-filter me-2 text-muted"></i>Filter Students Without Portal Account</h6>
    </div>
    <div class="card-body py-3 px-4">
        <form method="GET" action="" class="row g-2 align-items-end" id="filterForm">
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Search</label>
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="ID, name, email, phone…"
                       value="<?= h($search) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Department</label>
                <select name="dept" id="filter_dept" class="form-select form-select-sm">
                    <option value="">All Depts</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $f_dept == $d['id'] ? 'selected' : '' ?>>
                        <?= h($d['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Program</label>
                <select name="program" id="filter_program" class="form-select form-select-sm">
                    <option value="">All Programs</option>
                    <?php foreach ($all_programs as $p): ?>
                    <option value="<?= $p['id'] ?>"
                            data-dept="<?= $p['dept_id'] ?>"
                            <?= $f_program == $p['id'] ? 'selected' : '' ?>>
                        <?= h($p['program_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Batch</label>
                <select name="batch" class="form-select form-select-sm">
                    <option value="">All Batches</option>
                    <?php foreach ($batches as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $f_batch == $b['id'] ? 'selected' : '' ?>>
                        <?= h($b['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <?php foreach ($valid_statuses as $s): ?>
                    <option value="<?= $s ?>" <?= $f_status === $s ? 'selected' : '' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Semester</label>
                <input type="text" name="semester" class="form-control form-control-sm"
                       placeholder="e.g. Fall 2025" value="<?= h($f_sem) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Semester Type</label>
                <select name="sem_type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    <option value="bi_semester" <?= $f_sem_type === 'bi_semester' ? 'selected' : '' ?>>Bi Semester</option>
                    <option value="trimester"   <?= $f_sem_type === 'trimester'   ? 'selected' : '' ?>>Trimester</option>
                </select>
            </div>
            <div class="col-6 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-fill" style="border-radius:7px;">
                    <i class="fas fa-search me-1"></i> Filter
                </button>
                <a href="<?= APP_URL ?>/students/portal-bulk-create.php" class="btn btn-outline-secondary btn-sm flex-fill" style="border-radius:7px;">
                    Reset
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Preview and Action -->
<div class="card">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-user-plus me-2 text-muted"></i>Students Without Portal Account
        </h6>
        <span class="badge bg-primary bg-opacity-10 text-primary"><?= $preview_count ?> eligible</span>
    </div>
    <div class="card-body p-4">
        <?php if ($preview_count === 0): ?>
        <p class="text-muted mb-0">No students matching the current filter need a portal account, or all already have one.</p>
        <?php else: ?>
        <div class="alert alert-info mb-3" style="border-radius:10px;">
            <i class="fas fa-info-circle me-1"></i>
            <strong><?= $preview_count ?></strong> student<?= $preview_count !== 1 ? 's' : '' ?> match your filter and do not yet have a portal account.
            Accounts will be created with default password <code>12345678</code>.
            <?php if (sp_get_setting('email_enabled', '1') === '1'): ?>
            Welcome emails will be sent.
            <?php endif; ?>
            <?php if (sp_get_setting('sms_enabled', '0') === '1'): ?>
            SMS notifications will be sent.
            <?php endif; ?>
        </div>
        <form method="POST" action="<?= APP_URL ?>/students/portal-bulk-create.php?<?= h(http_build_query(array_filter($_GET, fn($v) => $v !== ''))) ?>"
              onsubmit="return confirm('Create portal accounts for <?= $preview_count ?> student<?= $preview_count !== 1 ? 's' : '' ?>?\n\nDefault password: 12345678\nThis cannot be undone.');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="bulk_create">
            <button type="submit" class="btn btn-success" style="border-radius:10px;">
                <i class="fas fa-user-plus me-1"></i> Create <?= $preview_count ?> Portal Account<?= $preview_count !== 1 ? 's' : '' ?> Now
            </button>
            <a href="<?= APP_URL ?>/students/index.php" class="btn btn-light ms-2" style="border-radius:10px;">Cancel</a>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<script>
(function () {
    var deptSel    = document.getElementById('filter_dept');
    var programSel = document.getElementById('filter_program');
    if (!deptSel || !programSel) return;

    function filterPrograms() {
        var deptId = deptSel.value;
        var opts   = programSel.querySelectorAll('option[data-dept]');
        opts.forEach(function (opt) {
            var show = !deptId || opt.dataset.dept === deptId;
            opt.hidden   = !show;
            opt.disabled = !show;
            if (!show && opt.selected) {
                programSel.value = '';
            }
        });
    }

    deptSel.addEventListener('change', filterPrograms);
    filterPrograms();
}());
</script>
