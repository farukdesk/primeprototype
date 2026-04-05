<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/sp-helpers.php';

if (!sp_can_manage_depts()) {
    require_access('staff-departments', 'can_view');
}

$page_title = 'Staff Departments';
$errors  = [];
$success = '';

// ── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // Add or edit a department
    if (in_array($action, ['add', 'edit'], true)) {
        $name    = trim($_POST['name'] ?? '');
        $type    = in_array($_POST['type'] ?? '', ['administrative', 'educational']) ? $_POST['type'] : '';
        $sort    = max(0, (int)($_POST['sort_order'] ?? 0));
        $active  = isset($_POST['is_active']) ? 1 : 0;
        $edit_id = (int)($_POST['edit_id'] ?? 0);
        $dept_id = ($type === 'educational') ? ((int)($_POST['dept_id'] ?? 0) ?: null) : null;

        if ($name === '') $errors[] = 'Department name is required.';
        if ($type === '') $errors[] = 'Department type is required.';

        if (empty($errors)) {
            if ($action === 'add') {
                db()->prepare(
                    'INSERT INTO staff_departments (name, type, dept_id, sort_order, is_active) VALUES (?,?,?,?,?)'
                )->execute([$name, $type, $dept_id, $sort, 1]);
                log_change('staff-departments', 'CREATE', (int)db()->lastInsertId(), 'Staff department created: ' . $name);
                $success = 'Department "' . h($name) . '" added successfully.';
            } else {
                db()->prepare(
                    'UPDATE staff_departments SET name=?, type=?, dept_id=?, sort_order=?, is_active=? WHERE id=?'
                )->execute([$name, $type, $dept_id, $sort, $active, $edit_id]);
                log_change('staff-departments', 'UPDATE', $edit_id, 'Staff department updated: ' . $name);
                $success = 'Department "' . h($name) . '" updated successfully.';
            }
        }
    }
}

// ── Load data ─────────────────────────────────────────────────────────────────
$departments = db()->query(
    "SELECT sd.*, dd.name AS mapped_dept_name
     FROM staff_departments sd
     LEFT JOIN dept_departments dd ON dd.id = sd.dept_id
     ORDER BY sd.type ASC, sd.sort_order ASC, sd.name ASC"
)->fetchAll();

$admin_depts = array_filter($departments, fn($d) => $d['type'] === 'administrative');
$edu_depts   = array_filter($departments, fn($d) => $d['type'] === 'educational');

// Academic departments (for mapping educational staff depts)
$academic_depts = db()->query(
    "SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC"
)->fetchAll();

// Edit mode
$editing = null;
if (isset($_GET['edit'])) {
    $stmt = db()->prepare(
        'SELECT sd.*, dd.name AS mapped_dept_name
         FROM staff_departments sd
         LEFT JOIN dept_departments dd ON dd.id = sd.dept_id
         WHERE sd.id = ?'
    );
    $stmt->execute([(int)$_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Staff Departments</li>
        </ol>
    </nav>
</div>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle me-2"></i> <?= $success ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="row g-4">

    <!-- ── Add / Edit Form ───────────────────────────────────────────────────── -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold">
                    <i class="fas fa-<?= $editing ? 'pencil-alt' : 'plus' ?> me-2 text-muted"></i>
                    <?= $editing ? 'Edit Department' : 'Add Department' ?>
                </h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action"  value="<?= $editing ? 'edit' : 'add' ?>">
                    <?php if ($editing): ?>
                    <input type="hidden" name="edit_id" value="<?= (int)$editing['id'] ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Department Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" style="border-radius:10px;"
                               value="<?= h($editing['name'] ?? '') ?>" maxlength="150" required
                               placeholder="e.g. HR, Finance, Computer Science">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Type <span class="text-danger">*</span></label>
                        <select name="type" id="dept_type_select" class="form-select" style="border-radius:10px;" required
                                onchange="document.getElementById('dept_id_row').style.display=this.value==='educational'?'':'none'">
                            <option value="">— Select type —</option>
                            <option value="administrative" <?= ($editing['type'] ?? '') === 'administrative' ? 'selected' : '' ?>>
                                Administrative Department
                            </option>
                            <option value="educational" <?= ($editing['type'] ?? '') === 'educational' ? 'selected' : '' ?>>
                                Educational Department
                            </option>
                        </select>
                    </div>

                    <div class="mb-3" id="dept_id_row"
                         style="display:<?= ($editing['type'] ?? '') === 'educational' ? '' : 'none' ?>">
                        <label class="form-label fw-medium">Maps to Academic Department</label>
                        <select name="dept_id" class="form-select" style="border-radius:10px;">
                            <option value="">— None —</option>
                            <?php foreach ($academic_depts as $ad): ?>
                            <option value="<?= (int)$ad['id'] ?>"
                                <?= (int)($editing['dept_id'] ?? 0) === (int)$ad['id'] ? 'selected' : '' ?>>
                                <?= h($ad['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Link this staff department to an academic department so General Staff appear there.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control" style="border-radius:10px;"
                               value="<?= (int)($editing['sort_order'] ?? 0) ?>" min="0">
                        <small class="text-muted">Lower numbers appear first.</small>
                    </div>

                    <?php if ($editing): ?>
                    <div class="mb-3 form-check">
                        <input type="checkbox" name="is_active" id="is_active" class="form-check-input"
                               value="1" <?= ($editing['is_active'] ?? 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                    <?php endif; ?>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i> <?= $editing ? 'Update' : 'Add Department' ?>
                        </button>
                        <?php if ($editing): ?>
                        <a href="<?= APP_URL ?>/staff-profiles/departments.php" class="btn btn-secondary" style="border-radius:10px;">
                            Cancel
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ── Department Lists ──────────────────────────────────────────────────── -->
    <div class="col-lg-8">

        <!-- Administrative -->
        <div class="card mb-4">
            <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-semibold">
                    <i class="fas fa-building me-2 text-primary"></i>Administrative Departments
                    <span class="badge bg-primary ms-1"><?= count($admin_depts) ?></span>
                </h6>
            </div>
            <div class="card-body p-0">
                <?php if (empty($admin_depts)): ?>
                <p class="text-muted p-4 mb-0">No administrative departments yet.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Sort</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($admin_depts as $dept): ?>
                            <tr>
                                <td><?= h($dept['name']) ?></td>
                                <td><?= (int)$dept['sort_order'] ?></td>
                                <td>
                                    <?php if ($dept['is_active']): ?>
                                    <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a href="<?= APP_URL ?>/staff-profiles/departments.php?edit=<?= (int)$dept['id'] ?>"
                                       class="btn btn-sm btn-outline-primary" style="border-radius:8px;">
                                        <i class="fas fa-pencil-alt"></i>
                                    </a>
                                    <a href="<?= APP_URL ?>/staff-profiles/department-delete.php?id=<?= (int)$dept['id'] ?>"
                                       class="btn btn-sm btn-outline-danger ms-1" style="border-radius:8px;"
                                       onclick="return confirm('Delete this department? Staff profiles linked to it will have their department cleared.')">
                                        <i class="fas fa-trash-alt"></i>
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

        <!-- Educational -->
        <div class="card">
            <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-semibold">
                    <i class="fas fa-graduation-cap me-2 text-success"></i>Educational Departments
                    <span class="badge bg-success ms-1"><?= count($edu_depts) ?></span>
                </h6>
            </div>
            <div class="card-body p-0">
                <?php if (empty($edu_depts)): ?>
                <p class="text-muted p-4 mb-0">No educational departments yet. Use the form on the left to add one.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Maps To</th>
                                <th>Sort</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($edu_depts as $dept): ?>
                            <tr>
                                <td><?= h($dept['name']) ?></td>
                                <td>
                                    <?php if (!empty($dept['mapped_dept_name'])): ?>
                                    <a href="<?= APP_URL ?>/departments/view.php?id=<?= (int)$dept['dept_id'] ?>"
                                       class="text-decoration-none small">
                                        <i class="fas fa-link me-1 text-muted"></i><?= h($dept['mapped_dept_name']) ?>
                                    </a>
                                    <?php else: ?>
                                    <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= (int)$dept['sort_order'] ?></td>
                                <td>
                                    <?php if ($dept['is_active']): ?>
                                    <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a href="<?= APP_URL ?>/staff-profiles/departments.php?edit=<?= (int)$dept['id'] ?>"
                                       class="btn btn-sm btn-outline-primary" style="border-radius:8px;">
                                        <i class="fas fa-pencil-alt"></i>
                                    </a>
                                    <a href="<?= APP_URL ?>/staff-profiles/department-delete.php?id=<?= (int)$dept['id'] ?>"
                                       class="btn btn-sm btn-outline-danger ms-1" style="border-radius:8px;"
                                       onclick="return confirm('Delete this department? Staff profiles linked to it will have their department cleared.')">
                                        <i class="fas fa-trash-alt"></i>
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

    </div><!-- /.col -->
</div><!-- /.row -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
