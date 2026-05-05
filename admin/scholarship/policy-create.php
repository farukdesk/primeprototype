<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('scholarship-policies', 'can_create');

$page_title = 'Add Scholarship Policy';
$errors     = [];
$db         = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name        = trim($_POST['name']        ?? '');
    $type        = $_POST['type']             ?? 'gpa_based';
    $description = trim($_POST['description'] ?? '');
    $is_active   = isset($_POST['is_active']) ? 1 : 0;
    $sort_order  = (int)($_POST['sort_order'] ?? 0);

    $tier_ids       = $_POST['tier_id']          ?? [];
    $tier_labels    = $_POST['tier_label']        ?? [];
    $tier_min_gpas  = $_POST['tier_min_gpa']      ?? [];
    $tier_max_gpas  = $_POST['tier_max_gpa']      ?? [];
    $tier_discounts = $_POST['tier_discount']     ?? [];
    $tier_sorts     = $_POST['tier_sort']         ?? [];

    $flat_discount  = trim($_POST['flat_discount'] ?? '');

    if (!in_array($type, ['gpa_based', 'merit_based', 'flat'], true)) $type = 'gpa_based';
    if ($name === '') $errors[] = 'Policy name is required.';

    $valid_tiers = [];

    if ($type === 'flat') {
        if (!is_numeric($flat_discount) || (float)$flat_discount < 0 || (float)$flat_discount > 100) {
            $errors[] = 'Flat discount must be a number between 0 and 100.';
        } else {
            $valid_tiers[] = [
                'label'            => 'Flat',
                'min_gpa'          => null,
                'max_gpa'          => null,
                'discount_percent' => (float)$flat_discount,
                'sort_order'       => 0,
            ];
        }
    } else {
        foreach ($tier_labels as $idx => $lbl) {
            $min = trim($tier_min_gpas[$idx] ?? '');
            $max = trim($tier_max_gpas[$idx] ?? '');
            $dis = trim($tier_discounts[$idx] ?? '');
            if ($min === '' && $max === '' && $dis === '') continue;
            if (!is_numeric($min) || !is_numeric($max) || !is_numeric($dis)) {
                $errors[] = 'All tier GPA and discount values must be numeric.';
                break;
            }
            if ((float)$min > (float)$max) {
                $errors[] = 'Tier min GPA cannot be greater than max GPA.';
                break;
            }
            if ((float)$dis < 0 || (float)$dis > 100) {
                $errors[] = 'Tier discount must be between 0 and 100.';
                break;
            }
            $valid_tiers[] = [
                'label'            => trim($lbl),
                'min_gpa'          => (float)$min,
                'max_gpa'          => (float)$max,
                'discount_percent' => (float)$dis,
                'sort_order'       => (int)($tier_sorts[$idx] ?? $idx),
            ];
        }
    }

    if (empty($errors)) {
        $user = auth_user();

        $db->prepare(
            'INSERT INTO sc_policies (name, type, description, is_active, sort_order, created_by)
             VALUES (?,?,?,?,?,?)'
        )->execute([$name, $type, $description ?: null, $is_active, $sort_order, $user['id']]);

        $policy_id = (int)$db->lastInsertId();

        $ins = $db->prepare(
            'INSERT INTO sc_tiers (policy_id, label, min_gpa, max_gpa, discount_percent, sort_order)
             VALUES (?,?,?,?,?,?)'
        );
        foreach ($valid_tiers as $tier) {
            $ins->execute([
                $policy_id,
                $tier['label'] ?: null,
                $tier['min_gpa'] ?? null,
                $tier['max_gpa'] ?? null,
                $tier['discount_percent'],
                $tier['sort_order'],
            ]);
        }

        log_change('scholarship-policies', 'CREATE', $policy_id, $name, null, null, null, 'Policy created with ' . count($valid_tiers) . ' tier(s).');

        flash_set('success', 'Policy <strong>' . h($name) . '</strong> created successfully.');
        redirect(APP_URL . '/scholarship/policies.php');
    }

    save_old($_POST);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-plus-circle me-2 text-success"></i>Add Scholarship Policy</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/scholarship/index.php">Scholarships</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/scholarship/policies.php">Policies</a></li>
            <li class="breadcrumb-item active">Add</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/scholarship/policies.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i> Back
    </a>
</div>

<?= flash_show() ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" novalidate>
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-primary text-white fw-semibold py-3">
                    <i class="fas fa-info-circle me-2"></i>Policy Details
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Policy Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="<?= h(old('name')) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
                        <div class="d-flex gap-4 mt-1 flex-wrap">
                            <div class="form-check">
                                <input class="form-check-input policy-type-radio" type="radio" name="type" id="type_gpa" value="gpa_based"
                                       <?= old('type', 'gpa_based') === 'gpa_based' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="type_gpa">
                                    <span class="badge bg-info text-dark me-1">GPA-Based</span>
                                    First-semester students (SSC+HSC GPA)
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input policy-type-radio" type="radio" name="type" id="type_merit" value="merit_based"
                                       <?= old('type', 'gpa_based') === 'merit_based' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="type_merit">
                                    <span class="badge bg-primary me-1">Merit-Based</span>
                                    Continuing students (previous semester CGPA)
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input policy-type-radio" type="radio" name="type" id="type_flat" value="flat"
                                       <?= old('type', 'gpa_based') === 'flat' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="type_flat">
                                    <span class="badge bg-success me-1">Flat Discount</span>
                                    Fixed discount for all qualifying students
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea name="description" class="form-control" rows="3"><?= h(old('description')) ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Flat Discount -->
            <div class="card border-0 shadow-sm mb-4" id="flat-discount-section" style="display:none;">
                <div class="card-header fw-semibold py-3">
                    <i class="fas fa-tag me-2 text-success"></i>Flat Discount
                </div>
                <div class="card-body">
                    <div class="row g-3 align-items-end">
                        <div class="col-sm-4">
                            <label class="form-label fw-semibold">Discount % <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" name="flat_discount" id="flat_discount" class="form-control"
                                       value="<?= h(old('flat_discount', '')) ?>"
                                       step="0.01" min="0" max="100" placeholder="e.g. 25.00">
                                <span class="input-group-text">%</span>
                            </div>
                            <div class="form-text">Fixed discount applied to all qualifying students.</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tiers -->
            <div class="card border-0 shadow-sm" id="tiers-section">
                <div class="card-header fw-semibold py-3 d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-layer-group me-2 text-warning"></i>GPA / CGPA Tiers</span>
                    <button type="button" class="btn btn-sm btn-outline-warning" id="add-tier-row">
                        <i class="fas fa-plus me-1"></i> Add Tier
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0" id="tiers-table">
                            <thead class="table-light">
                                <tr>
                                    <th class="px-3">Label</th>
                                    <th>Min GPA</th>
                                    <th>Max GPA</th>
                                    <th>Discount %</th>
                                    <th>Order</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="tiers-body">
                            <?php
                            $old_tier_labels    = old_array('tier_label');
                            $old_tier_mins      = old_array('tier_min_gpa');
                            $old_tier_maxs      = old_array('tier_max_gpa');
                            $old_tier_discounts = old_array('tier_discount');
                            $old_tier_sorts     = old_array('tier_sort');
                            $old_tier_count     = count($old_tier_labels);
                            ?>
                            <?php if ($old_tier_count > 0): ?>
                                <?php for ($ri = 0; $ri < $old_tier_count; $ri++): ?>
                                <tr class="tier-row">
                                    <td class="px-3"><input type="text" name="tier_label[]" class="form-control form-control-sm" value="<?= h($old_tier_labels[$ri] ?? '') ?>" placeholder="e.g. Gold"></td>
                                    <td><input type="number" name="tier_min_gpa[]" class="form-control form-control-sm" value="<?= h($old_tier_mins[$ri] ?? '') ?>" step="0.01" min="0" placeholder="0.00"></td>
                                    <td><input type="number" name="tier_max_gpa[]" class="form-control form-control-sm" value="<?= h($old_tier_maxs[$ri] ?? '') ?>" step="0.01" min="0" placeholder="10.00"></td>
                                    <td><input type="number" name="tier_discount[]" class="form-control form-control-sm" value="<?= h($old_tier_discounts[$ri] ?? '') ?>" step="0.01" min="0" max="100" placeholder="50.00"></td>
                                    <td><input type="number" name="tier_sort[]" class="form-control form-control-sm" value="<?= h($old_tier_sorts[$ri] ?? $ri) ?>" min="0" style="width:70px;"></td>
                                    <td><button type="button" class="btn btn-sm btn-outline-danger remove-tier-row"><i class="fas fa-times"></i></button></td>
                                </tr>
                                <?php endfor; ?>
                            <?php else: ?>
                                <tr class="tier-row">
                                    <td class="px-3"><input type="text" name="tier_label[]" class="form-control form-control-sm" value="Gold" placeholder="e.g. Gold"></td>
                                    <td><input type="number" name="tier_min_gpa[]" class="form-control form-control-sm" value="9.00" step="0.01" min="0"></td>
                                    <td><input type="number" name="tier_max_gpa[]" class="form-control form-control-sm" value="10.00" step="0.01" min="0"></td>
                                    <td><input type="number" name="tier_discount[]" class="form-control form-control-sm" value="50.00" step="0.01" min="0" max="100"></td>
                                    <td><input type="number" name="tier_sort[]" class="form-control form-control-sm" value="0" min="0" style="width:70px;"></td>
                                    <td><button type="button" class="btn btn-sm btn-outline-danger remove-tier-row"><i class="fas fa-times"></i></button></td>
                                </tr>
                                <tr class="tier-row">
                                    <td class="px-3"><input type="text" name="tier_label[]" class="form-control form-control-sm" value="Silver" placeholder="e.g. Silver"></td>
                                    <td><input type="number" name="tier_min_gpa[]" class="form-control form-control-sm" value="7.00" step="0.01" min="0"></td>
                                    <td><input type="number" name="tier_max_gpa[]" class="form-control form-control-sm" value="8.99" step="0.01" min="0"></td>
                                    <td><input type="number" name="tier_discount[]" class="form-control form-control-sm" value="25.00" step="0.01" min="0" max="100"></td>
                                    <td><input type="number" name="tier_sort[]" class="form-control form-control-sm" value="1" min="0" style="width:70px;"></td>
                                    <td><button type="button" class="btn btn-sm btn-outline-danger remove-tier-row"><i class="fas fa-times"></i></button></td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header fw-semibold py-3"><i class="fas fa-cog me-2"></i>Options</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control" value="<?= h(old('sort_order', 0)) ?>" min="0">
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1"
                               <?= old('is_active', '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="is_active">Active</label>
                    </div>
                </div>
            </div>
            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-success btn-lg"><i class="fas fa-save me-1"></i> Save Policy</button>
                <a href="<?= APP_URL ?>/scholarship/policies.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </div>
    </div>
</form>

<script>
var tierIdx = <?= max($old_tier_count, 2) ?>;

function togglePolicySections() {
    var type = document.querySelector('input[name="type"]:checked')?.value;
    var isFlat = type === 'flat';
    document.getElementById('flat-discount-section').style.display = isFlat ? '' : 'none';
    document.getElementById('tiers-section').style.display          = isFlat ? 'none' : '';
}
document.querySelectorAll('.policy-type-radio').forEach(function (r) {
    r.addEventListener('change', togglePolicySections);
});
togglePolicySections();

document.getElementById('add-tier-row').addEventListener('click', function () {
    var tbody = document.getElementById('tiers-body');
    var tr = document.createElement('tr');
    tr.className = 'tier-row';
    tr.innerHTML = `
        <td class="px-3"><input type="text" name="tier_label[]" class="form-control form-control-sm" placeholder="e.g. Gold"></td>
        <td><input type="number" name="tier_min_gpa[]" class="form-control form-control-sm" step="0.01" min="0" placeholder="0.00"></td>
        <td><input type="number" name="tier_max_gpa[]" class="form-control form-control-sm" step="0.01" min="0" placeholder="10.00"></td>
        <td><input type="number" name="tier_discount[]" class="form-control form-control-sm" step="0.01" min="0" max="100" placeholder="50.00"></td>
        <td><input type="number" name="tier_sort[]" class="form-control form-control-sm" value="${tierIdx}" min="0" style="width:70px;"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger remove-tier-row"><i class="fas fa-times"></i></button></td>`;
    tbody.appendChild(tr);
    tierIdx++;
});
document.addEventListener('click', function (e) {
    if (e.target.closest('.remove-tier-row')) {
        e.target.closest('tr').remove();
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
