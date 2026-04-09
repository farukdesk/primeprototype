<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('course-fees', 'can_create');

$page_title = 'Add Fee Structure';
$errors     = [];
$db         = db();

$depts = $db->query('SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name')->fetchAll();
$progs = $db->query('SELECT id, dept_id, program_name FROM dept_academic_programs ORDER BY program_name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $dept_id       = (int)($_POST['dept_id']       ?? 0) ?: null;
    $program_id    = (int)($_POST['program_id']    ?? 0) ?: null;
    $degree_type   = $_POST['degree_type']          ?? 'bachelor';
    $credit_fee    = (int)($_POST['credit_fee']    ?? 0);
    $total_credits = trim($_POST['total_credits']  ?? '') !== '' ? (float)$_POST['total_credits'] : null;
    $duration      = trim($_POST['duration_years'] ?? '') !== '' ? (float)$_POST['duration_years'] : null;
    $num_semesters = trim($_POST['num_semesters']  ?? '') !== '' ? (int)$_POST['num_semesters'] : null;
    $is_active     = isset($_POST['is_active']) ? 1 : 0;
    $sort_order    = (int)($_POST['sort_order']    ?? 0);

    // Fixed fees
    $fee_names   = $_POST['fee_name']   ?? [];
    $fee_amounts = $_POST['fee_amount'] ?? [];
    $fee_types   = $_POST['fee_type']   ?? [];
    $fee_orders  = $_POST['fee_sort']   ?? [];

    $valid_degrees = ['bachelor','master','diploma','certificate'];
    if (!in_array($degree_type, $valid_degrees, true)) $errors[] = 'Invalid degree type.';
    if ($credit_fee < 0) $errors[] = 'Credit fee must be zero or positive.';

    if (empty($errors)) {
        $user = auth_user();

        $db->prepare(
            'INSERT INTO cf_programs (dept_id, program_id, degree_type, credit_fee, total_credits, duration_years, num_semesters, is_active, sort_order, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([$dept_id, $program_id, $degree_type, $credit_fee, $total_credits, $duration, $num_semesters, $is_active, $sort_order, $user['id']]);

        $new_id = (int)$db->lastInsertId();

        // Insert fixed fees
        $ins = $db->prepare(
            'INSERT INTO cf_fixed_fees (cf_program_id, fee_name, amount, fee_type, sort_order) VALUES (?,?,?,?,?)'
        );
        foreach ($fee_names as $idx => $fname) {
            $fname  = trim($fname);
            $amount = (int)($fee_amounts[$idx] ?? 0);
            $ftype  = in_array($fee_types[$idx] ?? '', ['one_time','per_semester','monthly'], true)
                      ? $fee_types[$idx] : 'one_time';
            $fsort  = (int)($fee_orders[$idx] ?? $idx);
            if ($fname !== '') {
                $ins->execute([$new_id, $fname, $amount, $ftype, $fsort]);
            }
        }

        $label = cf_program_label(cf_get_program($new_id) ?: ['id' => $new_id, 'program_name' => '', 'dept_name' => '', 'degree_type' => $degree_type]);
        log_change('course-fees', 'CREATE', $new_id, $label, null, null, null, "Fee structure created.");

        flash_set('success', 'Fee structure created successfully.');
        redirect(APP_URL . '/course-fees/view.php?id=' . $new_id);
    }

    save_old($_POST);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-plus-circle me-2 text-success"></i>Add Fee Structure</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/course-fees/index.php">Course Fees</a></li>
            <li class="breadcrumb-item active">Add</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/course-fees/index.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i> Back
    </a>
</div>

<?= flash_show() ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" novalidate id="cf-form">
    <?= csrf_field() ?>
    <div class="row g-4">

        <!-- Main -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-primary text-white fw-semibold py-3">
                    <i class="fas fa-graduation-cap me-2"></i>Programme Details
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Department</label>
                            <select name="dept_id" id="dept_id" class="form-select">
                                <option value="">— Select Department —</option>
                                <?php foreach ($depts as $d): ?>
                                <option value="<?= $d['id'] ?>" <?= old('dept_id') == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Programme</label>
                            <select name="program_id" id="program_id" class="form-select">
                                <option value="">— Select Programme —</option>
                                <?php foreach ($progs as $p): ?>
                                <option value="<?= $p['id'] ?>" data-dept="<?= $p['dept_id'] ?>"
                                    <?= old('program_id') == $p['id'] ? 'selected' : '' ?>><?= h($p['program_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Degree Type <span class="text-danger">*</span></label>
                            <select name="degree_type" class="form-select" required>
                                <?php foreach (['bachelor'=>'Bachelor','master'=>'Master','diploma'=>'Diploma','certificate'=>'Certificate'] as $v => $l): ?>
                                <option value="<?= $v ?>" <?= old('degree_type', 'bachelor') === $v ? 'selected' : '' ?>><?= $l ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Total Credits</label>
                            <input type="number" name="total_credits" class="form-control" min="0" max="999" step="0.25"
                                   value="<?= h(old('total_credits')) ?>" placeholder="e.g. 160.5">
                            <div class="form-text">Optional, supports decimals (e.g. 160.5).</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Duration (years)</label>
                            <input type="number" name="duration_years" class="form-control" min="0" max="10" step="0.5"
                                   value="<?= h(old('duration_years')) ?>" placeholder="e.g. 4">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Number of Semesters</label>
                            <input type="number" name="num_semesters" class="form-control" min="1" max="24"
                                   value="<?= h(old('num_semesters')) ?>" placeholder="e.g. 8 or 12">
                            <div class="form-text">Used to calculate total per-semester costs.</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Fixed Fees -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header fw-semibold py-3 d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-list-ul me-2 text-info"></i>Additional / Fixed Fees</span>
                    <button type="button" class="btn btn-sm btn-outline-info" id="add-fee-row">
                        <i class="fas fa-plus me-1"></i> Add Fee
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0" id="fees-table">
                            <thead class="table-light">
                                <tr>
                                    <th class="px-3">Fee Name</th>
                                    <th>Amount (BDT)</th>
                                    <th>Type</th>
                                    <th>Order</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="fees-body">
                                <?php
                                $old_names   = old_array('fee_name');
                                $old_amounts = old_array('fee_amount');
                                $old_types   = old_array('fee_type');
                                $old_sorts   = old_array('fee_sort');
                                $old_rows    = count($old_names) > 0 ? count($old_names) : 0;
                                ?>
                                <?php if ($old_rows > 0): ?>
                                <?php for ($ri = 0; $ri < $old_rows; $ri++): ?>
                                <tr class="fee-row">
                                    <td class="px-3"><input type="text" name="fee_name[]" class="form-control form-control-sm" value="<?= h($old_names[$ri] ?? '') ?>" placeholder="e.g. Library Fee"></td>
                                    <td><input type="number" name="fee_amount[]" class="form-control form-control-sm" value="<?= h($old_amounts[$ri] ?? 0) ?>" min="0"></td>
                                    <td>
                                        <select name="fee_type[]" class="form-select form-select-sm">
                                            <option value="one_time"     <?= ($old_types[$ri] ?? '') === 'one_time'     ? 'selected' : '' ?>>One-Time</option>
                                            <option value="per_semester" <?= ($old_types[$ri] ?? '') === 'per_semester' ? 'selected' : '' ?>>Per Semester</option>
                                            <option value="monthly"      <?= ($old_types[$ri] ?? '') === 'monthly'      ? 'selected' : '' ?>>Monthly (÷ months)</option>
                                        </select>
                                    </td>
                                    <td><input type="number" name="fee_sort[]" class="form-control form-control-sm" value="<?= h($old_sorts[$ri] ?? $ri) ?>" min="0" style="width:70px;"></td>
                                    <td><button type="button" class="btn btn-sm btn-outline-danger remove-fee-row"><i class="fas fa-times"></i></button></td>
                                </tr>
                                <?php endfor; ?>
                                <?php else: ?>
                                <!-- Default two rows -->
                                <tr class="fee-row">
                                    <td class="px-3"><input type="text" name="fee_name[]" class="form-control form-control-sm" value="Registration Fee" placeholder="Fee name"></td>
                                    <td><input type="number" name="fee_amount[]" class="form-control form-control-sm" value="5000" min="0"></td>
                                    <td><select name="fee_type[]" class="form-select form-select-sm"><option value="one_time">One-Time</option><option value="per_semester">Per Semester</option><option value="monthly">Monthly (÷ months)</option></select></td>
                                    <td><input type="number" name="fee_sort[]" class="form-control form-control-sm" value="0" min="0" style="width:70px;"></td>
                                    <td><button type="button" class="btn btn-sm btn-outline-danger remove-fee-row"><i class="fas fa-times"></i></button></td>
                                </tr>
                                <tr class="fee-row">
                                    <td class="px-3"><input type="text" name="fee_name[]" class="form-control form-control-sm" value="Student Activity Fee" placeholder="Fee name"></td>
                                    <td><input type="number" name="fee_amount[]" class="form-control form-control-sm" value="1500" min="0"></td>
                                    <td><select name="fee_type[]" class="form-select form-select-sm"><option value="one_time">One-Time</option><option value="per_semester">Per Semester</option><option value="monthly">Monthly (÷ months)</option></select></td>
                                    <td><input type="number" name="fee_sort[]" class="form-control form-control-sm" value="1" min="0" style="width:70px;"></td>
                                    <td><button type="button" class="btn btn-sm btn-outline-danger remove-fee-row"><i class="fas fa-times"></i></button></td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header fw-semibold py-3"><i class="fas fa-money-bill-wave me-2 text-success"></i>Fee per Credit Hour</div>
                <div class="card-body">
                    <label class="form-label fw-semibold">Credit Hour Fee (BDT) <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text">BDT</span>
                        <input type="number" name="credit_fee" class="form-control form-control-lg fw-bold"
                               value="<?= h(old('credit_fee', 0)) ?>" min="0" required>
                    </div>
                    <div class="form-text">Tuition fee charged per credit hour.</div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header fw-semibold py-3"><i class="fas fa-cog me-2"></i>Options</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control" value="<?= h(old('sort_order', 0)) ?>" min="0">
                        <div class="form-text">Lower numbers appear first on the public page.</div>
                    </div>
                    <div class="form-check form-switch">
                        <?php
                        $is_post      = $_SERVER['REQUEST_METHOD'] === 'POST';
                        $active_checked = $is_post ? (old('is_active', '') === '1') : true;
                        ?>
                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1"
                               <?= $active_checked ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="is_active">Active (visible on public page)</label>
                    </div>
                </div>
            </div>

            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-success btn-lg">
                    <i class="fas fa-save me-1"></i> Save Fee Structure
                </button>
                <a href="<?= APP_URL ?>/course-fees/index.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </div>
    </div>
</form>

<script>
// Filter programmes by department
document.getElementById('dept_id').addEventListener('change', function () {
    var deptId = this.value;
    document.querySelectorAll('#program_id option').forEach(function (opt) {
        if (!opt.value) { opt.style.display = ''; return; }
        opt.style.display = (!deptId || opt.dataset.dept === deptId) ? '' : 'none';
    });
    document.getElementById('program_id').value = '';
});

// Add fee row
var rowIdx = <?= $old_rows ?: 2 ?>;
document.getElementById('add-fee-row').addEventListener('click', function () {
    var tbody = document.getElementById('fees-body');
    var tr = document.createElement('tr');
    tr.className = 'fee-row';
    tr.innerHTML = `<td class="px-3"><input type="text" name="fee_name[]" class="form-control form-control-sm" placeholder="Fee name"></td>
        <td><input type="number" name="fee_amount[]" class="form-control form-control-sm" value="0" min="0"></td>
        <td><select name="fee_type[]" class="form-select form-select-sm"><option value="one_time">One-Time</option><option value="per_semester">Per Semester</option><option value="monthly">Monthly (÷ months)</option></select></td>
        <td><input type="number" name="fee_sort[]" class="form-control form-control-sm" value="${rowIdx}" min="0" style="width:70px;"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger remove-fee-row"><i class="fas fa-times"></i></button></td>`;
    tbody.appendChild(tr);
    rowIdx++;
});

// Remove fee row
document.addEventListener('click', function (e) {
    if (e.target.closest('.remove-fee-row')) {
        e.target.closest('tr').remove();
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
