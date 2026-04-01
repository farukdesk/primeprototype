<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_access('library');
require_once __DIR__ . '/../helpers.php';

$db     = db();
$errors = [];

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = trim($_POST['action'] ?? '');

    // ── Register ──────────────────────────────────────────────────────────────
    if ($action === 'register') {
        $member_type = trim($_POST['member_type'] ?? '');
        $student_fk  = (int)($_POST['student_id']  ?? 0);
        $name        = trim($_POST['name']          ?? '');
        $email       = trim($_POST['email']         ?? '');
        $phone       = trim($_POST['phone']         ?? '');
        $dept_id     = (int)($_POST['dept_id']      ?? 0);
        $is_active   = isset($_POST['is_active']) ? 1 : 0;

        if (!in_array($member_type, ['Student','Faculty','Staff'], true)) $errors[] = 'Invalid member type.';
        if ($name === '') $errors[] = 'Name is required.';

        if ($member_type === 'Student' && $student_fk) {
            $dup = $db->prepare('SELECT id FROM library_members WHERE student_id = ? LIMIT 1');
            $dup->execute([$student_fk]);
            if ($dup->fetch()) $errors[] = 'This student is already a library member.';
        }

        if (empty($errors)) {
            $member_code = lib_generate_member_code();
            $db->prepare(
                'INSERT INTO library_members
                 (member_type, student_id, member_code, name, email, phone, dept_id, is_active, joined_at, created_at)
                 VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())'
            )->execute([
                $member_type,
                ($member_type === 'Student' && $student_fk) ? $student_fk : null,
                $member_code,
                $name,
                $email   ?: null,
                $phone   ?: null,
                $dept_id ?: null,
                $is_active,
            ]);
            $new_id = (int)$db->lastInsertId();
            lib_audit('MEMBER_CREATED', 'members', $new_id, $member_code, "Registered {$member_type} member {$name}.");
            log_change('library', 'CREATE', $new_id, $member_code, null, null, null, "Registered library member {$name} ({$member_code}).");
            flash_set('success', "Member <strong>" . h($name) . "</strong> registered as <strong>" . h($member_code) . "</strong>.");
            redirect(APP_URL . '/library/members/index.php');
        }
        save_old(compact('member_type','student_fk','name','email','phone','dept_id','is_active'));
    }

    // ── Edit ──────────────────────────────────────────────────────────────────
    if ($action === 'edit' && lib_is_staff()) {
        $id        = (int)($_POST['id']        ?? 0);
        $name      = trim($_POST['name']       ?? '');
        $email     = trim($_POST['email']      ?? '');
        $phone     = trim($_POST['phone']      ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '') { flash_set('error', 'Name is required.'); redirect(APP_URL . '/library/members/index.php'); }
        $old = lib_get_member($id);
        $db->prepare('UPDATE library_members SET name=?,email=?,phone=?,is_active=? WHERE id=?')
           ->execute([$name, $email ?: null, $phone ?: null, $is_active, $id]);
        log_change('library','UPDATE',$id,$old['member_code'],'name',$old['name'],$name,"Updated member {$old['member_code']}.");
        lib_audit('MEMBER_UPDATED','members',$id,$old['member_code'],"Updated member details.");
        flash_set('success', "Member <strong>" . h($name) . "</strong> updated.");
        redirect(APP_URL . '/library/members/index.php');
    }

    // ── Toggle ────────────────────────────────────────────────────────────────
    if ($action === 'toggle' && lib_is_staff()) {
        $id  = (int)($_POST['id'] ?? 0);
        $old = lib_get_member($id);
        $new_val = $old['is_active'] ? 0 : 1;
        $db->prepare('UPDATE library_members SET is_active=? WHERE id=?')->execute([$new_val, $id]);
        lib_audit('MEMBER_TOGGLE','members',$id,$old['member_code'],"Set is_active={$new_val}.");
        flash_set('success', "Member status updated.");
        redirect(APP_URL . '/library/members/index.php');
    }

    // ── Delete ────────────────────────────────────────────────────────────────
    if ($action === 'delete' && lib_can_delete()) {
        $id  = (int)($_POST['id'] ?? 0);
        $old = lib_get_member($id);
        $active_circ = (int)$db->prepare("SELECT COUNT(*) FROM library_circulation WHERE member_id=? AND status IN ('Issued','Overdue')")->execute([$id]) ? $db->query("SELECT COUNT(*) FROM library_circulation WHERE member_id={$id} AND status IN ('Issued','Overdue')")->fetchColumn() : 0;
        // Re-query properly
        $s = $db->prepare("SELECT COUNT(*) FROM library_circulation WHERE member_id=? AND status IN ('Issued','Overdue')");
        $s->execute([$id]);
        $active_circ = (int)$s->fetchColumn();
        if ($active_circ > 0) {
            flash_set('error', "Cannot delete member with active circulations.");
            redirect(APP_URL . '/library/members/index.php');
        }
        $db->prepare('DELETE FROM library_members WHERE id=?')->execute([$id]);
        lib_audit('MEMBER_DELETED','members',$id,$old['member_code'],"Deleted member {$old['name']}.");
        log_change('library','DELETE',$id,$old['member_code'],null,null,null,"Deleted library member {$old['name']}.");
        flash_set('success', "Member deleted.");
        redirect(APP_URL . '/library/members/index.php');
    }
}

// ── Stats ─────────────────────────────────────────────────────────────────────
$stat_total    = (int)$db->query("SELECT COUNT(*) FROM library_members")->fetchColumn();
$stat_students = (int)$db->query("SELECT COUNT(*) FROM library_members WHERE member_type='Student'")->fetchColumn();
$stat_faculty  = (int)$db->query("SELECT COUNT(*) FROM library_members WHERE member_type='Faculty'")->fetchColumn();
$stat_active   = (int)$db->query("SELECT COUNT(*) FROM library_members WHERE is_active=1")->fetchColumn();

// ── Filters ───────────────────────────────────────────────────────────────────
$search      = trim($_GET['q']           ?? '');
$type_f      = trim($_GET['member_type'] ?? '');
$dept_f      = (int)($_GET['dept_id']    ?? 0);
$active_f    = $_GET['is_active'] ?? '';

$per_page = 20;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

$where  = '1=1';
$params = [];
if ($search !== '') {
    $like = '%' . $search . '%';
    $where .= ' AND (m.name LIKE ? OR m.member_code LIKE ? OR m.email LIKE ?)';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($type_f !== '') { $where .= ' AND m.member_type=?'; $params[] = $type_f; }
if ($dept_f)        { $where .= ' AND m.dept_id=?';     $params[] = $dept_f; }
if ($active_f !== '') { $where .= ' AND m.is_active=?'; $params[] = (int)$active_f; }

$count_stmt = $db->prepare("SELECT COUNT(*) FROM library_members m WHERE $where");
$count_stmt->execute($params);
$total_rows  = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$sql = "SELECT m.*, d.name AS dept_name
        FROM library_members m
        LEFT JOIN dept_departments d ON d.id = m.dept_id
        WHERE $where ORDER BY m.id DESC LIMIT $per_page OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$members = $stmt->fetchAll();

// ── Dropdown data ─────────────────────────────────────────────────────────────
$departments = $db->query('SELECT id, name FROM dept_departments ORDER BY name ASC')->fetchAll();
$students_list = $db->query(
    "SELECT s.id, s.student_id, s.full_name, s.email, s.phone, s.dept_id
     FROM students s
     WHERE s.status='Active'
       AND s.id NOT IN (SELECT student_id FROM library_members WHERE student_id IS NOT NULL)
     ORDER BY s.full_name ASC"
)->fetchAll();

$page_title  = 'Library Members';
$breadcrumbs = [
    ['label' => 'Library', 'url' => APP_URL . '/library/index.php'],
    ['label' => 'Members'],
];
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Header row -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/library/index.php">Library</a></li>
            <li class="breadcrumb-item active">Members</li>
        </ol>
    </nav>
    <?php if (lib_is_staff()): ?>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#registerModal" style="border-radius:10px;">
        <i class="fas fa-user-plus me-1"></i> Register Member
    </button>
    <?php endif; ?>
</div>

<?php if (($flash_msg = flash_get('success')) !== null): ?>
<div class="alert alert-success alert-dismissible fade show">
    <?= $flash_msg ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php elseif (($flash_msg = flash_get('error')) !== null): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <?= $flash_msg ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($errors): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <strong>Error:</strong>
    <ul class="mb-0 mt-1 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="row g-3 mb-4">
    <?php
    $stats = [
        ['Total Members', $stat_total, 'fas fa-users', 'linear-gradient(135deg,#4f8ef7,#2d63e8)'],
        ['Students', $stat_students, 'fas fa-user-graduate', 'linear-gradient(135deg,#11c48d,#0a9971)'],
        ['Faculty', $stat_faculty, 'fas fa-chalkboard-teacher', 'linear-gradient(135deg,#f5a623,#d4870a)'],
        ['Active', $stat_active, 'fas fa-check-circle', 'linear-gradient(135deg,#6f42c1,#4e2d8c)'],
    ];
    foreach ($stats as [$label, $val, $icon, $bg]):
    ?>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:<?= $bg ?>;">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= number_format($val) ?></div>
                    <div class="stat-label"><?= $label ?></div>
                </div>
                <div class="stat-icon"><i class="<?= $icon ?>"></i></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Filter bar -->
<div class="card mb-3">
    <div class="card-body py-3 px-4">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Search name, code, email…" value="<?= h($search) ?>" style="border-radius:8px;">
            </div>
            <div class="col-md-2">
                <select name="member_type" class="form-select form-select-sm" style="border-radius:8px;">
                    <option value="">All Types</option>
                    <?php foreach (['Student','Faculty','Staff'] as $t): ?>
                    <option value="<?= $t ?>" <?= $type_f===$t?'selected':'' ?>><?= $t ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select name="dept_id" class="form-select form-select-sm" style="border-radius:8px;">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $dept_f==$d['id']?'selected':'' ?>><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select name="is_active" class="form-select form-select-sm" style="border-radius:8px;">
                    <option value="">All Status</option>
                    <option value="1" <?= $active_f==='1'?'selected':'' ?>>Active</option>
                    <option value="0" <?= $active_f==='0'?'selected':'' ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-fill" style="border-radius:8px;"><i class="fas fa-search"></i></button>
                <a href="?" class="btn btn-outline-secondary btn-sm flex-fill" style="border-radius:8px;"><i class="fas fa-times"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- Table -->
<div class="card">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-list me-2 text-muted"></i>Members
            <span class="badge bg-secondary ms-2"><?= number_format($total_rows) ?></span>
        </h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">#</th>
                        <th>Member Code</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Department</th>
                        <th>Contact</th>
                        <th class="text-center">Issues</th>
                        <th class="text-center">Fines</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($members)): ?>
                    <tr><td colspan="11" class="text-center py-4 text-muted">No members found.</td></tr>
                <?php else: ?>
                <?php foreach ($members as $i => $m):
                    $borrow_count = lib_member_borrow_count((int)$m['id']);
                    $unpaid_fines = lib_member_unpaid_fines((int)$m['id']);
                ?>
                <tr>
                    <td class="ps-4"><?= $offset + $i + 1 ?></td>
                    <td><code><?= h($m['member_code']) ?></code></td>
                    <td class="fw-medium"><?= h($m['name']) ?></td>
                    <td>
                        <?php
                        $tbadge = ['Student'=>'bg-primary','Faculty'=>'bg-info text-dark','Staff'=>'bg-secondary'];
                        $tc = $tbadge[$m['member_type']] ?? 'bg-secondary';
                        ?>
                        <span class="badge <?= $tc ?>"><?= h($m['member_type']) ?></span>
                    </td>
                    <td><?= $m['dept_name'] ? h($m['dept_name']) : '<span class="text-muted">—</span>' ?></td>
                    <td>
                        <?php if ($m['email']): ?><div class="small"><?= h($m['email']) ?></div><?php endif; ?>
                        <?php if ($m['phone']): ?><div class="small text-muted"><?= h($m['phone']) ?></div><?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($borrow_count > 0): ?>
                            <span class="badge bg-warning text-dark"><?= $borrow_count ?></span>
                        <?php else: echo '<span class="text-muted">0</span>'; endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($unpaid_fines > 0): ?>
                            <span class="badge bg-danger">৳<?= number_format($unpaid_fines, 2) ?></span>
                        <?php else: echo '<span class="text-muted">—</span>'; endif; ?>
                    </td>
                    <td>
                        <?php if ($m['is_active']): ?>
                            <span class="badge bg-success">Active</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted"><?= $m['joined_at'] ? date('d M Y', strtotime($m['joined_at'])) : '—' ?></td>
                    <td class="text-end pe-4">
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-info btn-sm"
                                    onclick="viewMember(<?= htmlspecialchars(json_encode($m), ENT_QUOTES) ?>)"
                                    title="View"><i class="fas fa-eye"></i></button>
                            <?php if (lib_is_staff()): ?>
                            <button class="btn btn-outline-primary btn-sm"
                                    onclick="editMember(<?= htmlspecialchars(json_encode($m), ENT_QUOTES) ?>)"
                                    title="Edit"><i class="fas fa-pencil"></i></button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Toggle status?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= $m['id'] ?>">
                                <button type="submit" class="btn btn-outline-<?= $m['is_active'] ? 'warning' : 'success' ?> btn-sm" title="<?= $m['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                    <i class="fas fa-<?= $m['is_active'] ? 'toggle-on' : 'toggle-off' ?>"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php if (lib_can_delete()): ?>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this member? This cannot be undone.')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $m['id'] ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete"><i class="fas fa-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($total_pages > 1): ?>
    <div class="card-footer py-3 px-4">
        <nav><ul class="pagination pagination-sm mb-0 justify-content-center">
            <?php for ($p = 1; $p <= $total_pages; $p++):
                $q_params = array_merge($_GET, ['page' => $p]);
            ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                <a class="page-link" href="?<?= http_build_query($q_params) ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<!-- Register Modal -->
<?php if (lib_is_staff()): ?>
<div class="modal fade" id="registerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Register Library Member</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="register">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-medium">Member Type <span class="text-danger">*</span></label>
                            <select name="member_type" id="reg_member_type" class="form-select" required onchange="toggleStudentField(this.value)">
                                <option value="">— Select —</option>
                                <option value="Student" <?= old('member_type')==='Student'?'selected':'' ?>>Student</option>
                                <option value="Faculty" <?= old('member_type')==='Faculty'?'selected':'' ?>>Faculty</option>
                                <option value="Staff"   <?= old('member_type')==='Staff'?'selected':'' ?>>Staff</option>
                            </select>
                        </div>
                        <div class="col-md-8" id="student_field" style="display:none;">
                            <label class="form-label fw-medium">Link Student Record</label>
                            <select name="student_id" id="reg_student_select" class="form-select" onchange="autofillStudent(this)">
                                <option value="">— Search & select student —</option>
                                <?php foreach ($students_list as $s): ?>
                                <option value="<?= $s['id'] ?>"
                                    data-name="<?= h($s['full_name']) ?>"
                                    data-email="<?= h($s['email'] ?? '') ?>"
                                    data-phone="<?= h($s['phone'] ?? '') ?>"
                                    data-dept="<?= (int)($s['dept_id'] ?? 0) ?>">
                                    <?= h($s['student_id']) ?> – <?= h($s['full_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="reg_name" class="form-control" required value="<?= h(old('name')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Email</label>
                            <input type="email" name="email" id="reg_email" class="form-control" value="<?= h(old('email')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Phone</label>
                            <input type="text" name="phone" id="reg_phone" class="form-control" value="<?= h(old('phone')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Department</label>
                            <select name="dept_id" id="reg_dept" class="form-select">
                                <option value="">— Select Department —</option>
                                <?php foreach ($departments as $d): ?>
                                <option value="<?= $d['id'] ?>" <?= old('dept_id')==$d['id']?'selected':'' ?>><?= h($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="reg_is_active" <?= old('is_active','1')?'checked':'' ?>>
                                <label class="form-check-label fw-medium" for="reg_is_active">Active</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Register</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-id-card me-2"></i>Member Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewModalBody"></div>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<?php if (lib_is_staff()): ?>
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-pencil me-2"></i>Edit Member</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-medium">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="edit_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Email</label>
                            <input type="email" name="email" id="edit_email" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Phone</label>
                            <input type="text" name="phone" id="edit_phone" class="form-control">
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active">
                                <label class="form-check-label" for="edit_is_active">Active</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function toggleStudentField(type) {
    document.getElementById('student_field').style.display = (type === 'Student') ? '' : 'none';
}
function autofillStudent(sel) {
    const opt = sel.options[sel.selectedIndex];
    if (!opt.value) return;
    document.getElementById('reg_name').value  = opt.dataset.name  || '';
    document.getElementById('reg_email').value = opt.dataset.email || '';
    document.getElementById('reg_phone').value = opt.dataset.phone || '';
    const deptSel = document.getElementById('reg_dept');
    for (let o of deptSel.options) { if (o.value == opt.dataset.dept) { o.selected = true; break; } }
}
function viewMember(m) {
    const typeBadge = {Student:'primary',Faculty:'info',Staff:'secondary'};
    const tc = typeBadge[m.member_type] || 'secondary';
    document.getElementById('viewModalBody').innerHTML = `
        <dl class="row mb-0">
            <dt class="col-sm-4">Member Code</dt><dd class="col-sm-8"><code>${m.member_code}</code></dd>
            <dt class="col-sm-4">Name</dt><dd class="col-sm-8">${m.name}</dd>
            <dt class="col-sm-4">Type</dt><dd class="col-sm-8"><span class="badge bg-${tc}">${m.member_type}</span></dd>
            <dt class="col-sm-4">Email</dt><dd class="col-sm-8">${m.email||'—'}</dd>
            <dt class="col-sm-4">Phone</dt><dd class="col-sm-8">${m.phone||'—'}</dd>
            <dt class="col-sm-4">Status</dt><dd class="col-sm-8"><span class="badge bg-${m.is_active=='1'?'success':'secondary'}">${m.is_active=='1'?'Active':'Inactive'}</span></dd>
            <dt class="col-sm-4">Joined</dt><dd class="col-sm-8">${m.joined_at||'—'}</dd>
        </dl>`;
    new bootstrap.Modal(document.getElementById('viewModal')).show();
}
function editMember(m) {
    document.getElementById('edit_id').value    = m.id;
    document.getElementById('edit_name').value  = m.name;
    document.getElementById('edit_email').value = m.email || '';
    document.getElementById('edit_phone').value = m.phone || '';
    document.getElementById('edit_is_active').checked = m.is_active == '1';
    new bootstrap.Modal(document.getElementById('editModal')).show();
}
<?php if (!empty(old('member_type'))): ?>
document.addEventListener('DOMContentLoaded', function() {
    toggleStudentField('<?= h(old('member_type')) ?>');
    new bootstrap.Modal(document.getElementById('registerModal')).show();
});
<?php endif; ?>
</script>

<?php
clear_old();
require_once __DIR__ . '/../../includes/footer.php';
?>
