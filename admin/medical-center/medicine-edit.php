<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';
require_access('medical-center');

if (!mc_can_edit()) {
    flash_set('error', 'Permission denied.');
    redirect(APP_URL . '/medical-center/medicines.php');
}

$page_title = 'Edit Medicine';
$db  = db();
$id  = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$med = $db->prepare('SELECT * FROM mc_medicines WHERE id = ?');
$med->execute([$id]);
$med = $med->fetch();

if (!$med) {
    flash_set('error', 'Medicine not found.');
    redirect(APP_URL . '/medical-center/medicines.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name              = trim($_POST['name']              ?? '');
    $generic_name      = trim($_POST['generic_name']      ?? '');
    $category          = trim($_POST['category']          ?? '');
    $unit              = trim($_POST['unit']              ?? 'tablet');
    $quantity_in_stock = (int)($_POST['quantity_in_stock'] ?? 0);
    $reorder_level     = (int)($_POST['reorder_level']    ?? 10);
    $supplier          = trim($_POST['supplier']          ?? '');
    $unit_cost         = trim($_POST['unit_cost']         ?? '') ?: null;
    $expiry_date       = trim($_POST['expiry_date']       ?? '') ?: null;
    $notes             = trim($_POST['notes']             ?? '');

    if ($name === '') $errors[] = 'Medicine name is required.';
    if ($unit === '') $unit = 'tablet';

    if (empty($errors)) {
        $db->prepare(
            'UPDATE mc_medicines SET name=?, generic_name=?, category=?, unit=?, quantity_in_stock=?,
             reorder_level=?, supplier=?, unit_cost=?, expiry_date=?, notes=?, updated_at=NOW()
             WHERE id=?'
        )->execute([$name, $generic_name, $category, $unit, $quantity_in_stock, $reorder_level,
                    $supplier, $unit_cost, $expiry_date, $notes, $id]);

        log_change('medical-center', 'UPDATE', $id, $name, null, null, null, 'Medicine updated');

        flash_set('success', 'Medicine updated successfully.');
        redirect(APP_URL . '/medical-center/medicines.php');
    }

    // Merge for re-display
    $med = array_merge($med, $_POST);
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-edit me-2 text-warning"></i>Edit Medicine</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/medical-center/index.php">Medical Center</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/medical-center/medicines.php">Medicine Stock</a></li>
            <li class="breadcrumb-item active">Edit</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/medical-center/medicines.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i> Back
    </a>
</div>

<?= flash_show() ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header py-3">
                <span class="fw-semibold">Edit: <?= h($med['name']) ?></span>
            </div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $med['id'] ?>">
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Medicine Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control"
                                   value="<?= h($med['name']) ?>" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Generic Name</label>
                            <input type="text" name="generic_name" class="form-control"
                                   value="<?= h($med['generic_name'] ?? '') ?>">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label fw-semibold">Category</label>
                            <input type="text" name="category" class="form-control"
                                   placeholder="e.g. Antibiotic, Analgesic"
                                   value="<?= h($med['category'] ?? '') ?>">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label fw-semibold">Unit</label>
                            <select name="unit" class="form-select">
                                <?php foreach (['tablet','capsule','syrup','injection','cream','drop','sachet','inhaler','other'] as $u): ?>
                                <option value="<?= $u ?>" <?= ($med['unit'] ?? 'tablet') === $u ? 'selected' : '' ?>><?= ucfirst($u) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label fw-semibold">Unit Cost (BDT)</label>
                            <input type="number" step="0.01" min="0" name="unit_cost" class="form-control"
                                   value="<?= h($med['unit_cost'] ?? '') ?>">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label fw-semibold">Quantity in Stock</label>
                            <input type="number" min="0" name="quantity_in_stock" class="form-control"
                                   value="<?= h($med['quantity_in_stock']) ?>">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label fw-semibold">Reorder Level</label>
                            <input type="number" min="0" name="reorder_level" class="form-control"
                                   value="<?= h($med['reorder_level']) ?>">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label fw-semibold">Expiry Date</label>
                            <input type="date" name="expiry_date" class="form-control"
                                   value="<?= h($med['expiry_date'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Supplier</label>
                            <input type="text" name="supplier" class="form-control"
                                   value="<?= h($med['supplier'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Notes</label>
                            <textarea name="notes" rows="3" class="form-control"><?= h($med['notes'] ?? '') ?></textarea>
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i> Update Medicine
                            </button>
                            <a href="<?= APP_URL ?>/medical-center/medicines.php" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
