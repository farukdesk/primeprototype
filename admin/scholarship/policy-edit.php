<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('scholarship-policies', 'can_edit');

$id     = (int)($_GET['id'] ?? 0);
$policy = sc_get_policy($id);
if (!$policy) { flash_set('error', 'Policy not found.'); redirect(APP_URL . '/scholarship/policies.php'); }

$page_title = 'Edit Scholarship Policy';
$errors     = [];
$db         = db();

$tiers = sc_get_tiers($id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name        = trim($_POST['name']        ?? '');
    $type        = $_POST['type']             ?? 'gpa_based';
    $description = trim($_POST['description'] ?? '');
    $is_active   = isset($_POST['is_active']) ? 1 : 0;
    $sort_order  = (int)($_POST['sort_order'] ?? 0);

    $deleted_tier_ids = array_filter(array_map('intval', (array)($_POST['deleted_tier_ids'] ?? [])));
    $tier_ids         = $_POST['tier_id']          ?? [];
    $tier_labels      = $_POST['tier_label']        ?? [];
    $tier_min_gpas    = $_POST['tier_min_gpa']      ?? [];
    $tier_max_gpas    = $_POST['tier_max_gpa']      ?? [];
    $tier_discounts   = $_POST['tier_discount']     ?? [];
    $tier_sorts       = $_POST['tier_sort']         ?? [];

    $flat_discount    = trim($_POST['flat_discount'] ?? '');

    if (!in_array($type, ['gpa_based', 'merit_based', 'flat'], true)) $type = 'gpa_based';
    if ($name === '') $errors[] = 'Policy name is required.';

    $valid_tiers = [];

    if ($type === 'flat') {
        if (!is_numeric($flat_discount) || (float)$flat_discount < 0 || (float)$flat_discount > 100) {
            $errors[] = 'Flat discount must be a number between 0 and 100.';
        } else {
            $valid_tiers[] = [
                'id'               => (int)($tier_ids[0] ?? 0),
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
                'id'               => (int)($tier_ids[$idx] ?? 0),
                'label'            => trim($lbl),
                'min_gpa'          => (float)$min,
                'max_gpa'          => (float)$max,
                'discount_percent' => (float)$dis,
                'sort_order'       => (int)($tier_sorts[$idx] ?? $idx),
            ];
        }
    }

    if (empty($errors)) {
        $db->prepare(
            'UPDATE sc_policies SET name=?, type=?, description=?, is_active=?, sort_order=? WHERE id=?'
        )->execute([$name, $type, $description ?: null, $is_active, $sort_order, $id]);

        if (!empty($deleted_tier_ids)) {
            $placeholders = implode(',', array_fill(0, count($deleted_tier_ids), '?'));
            $db->prepare("DELETE FROM sc_tiers WHERE id IN ($placeholders) AND policy_id = ?")
               ->execute([...$deleted_tier_ids, $id]);
        }

        // For flat type, delete any extra tiers (keep only 1)
        if ($type === 'flat') {
            $db->prepare("DELETE FROM sc_tiers WHERE policy_id = ? AND id != COALESCE(?, 0)")
               ->execute([$id, $valid_tiers[0]['id'] ?? 0]);
        }

        $upd = $db->prepare(
            'UPDATE sc_tiers SET label=?, min_gpa=?, max_gpa=?, discount_percent=?, sort_order=? WHERE id=? AND policy_id=?'
        );
        $ins = $db->prepare(
            'INSERT INTO sc_tiers (policy_id, label, min_gpa, max_gpa, discount_percent, sort_order) VALUES (?,?,?,?,?,?)'
        );

        foreach ($valid_tiers as $tier) {
            if ($tier['id'] > 0) {
                $upd->execute([
                    $tier['label'] ?: null,
                    $tier['min_gpa'] ?? null,
                    $tier['max_gpa'] ?? null,
                    $tier['discount_percent'],
                    $tier['sort_order'],
                    $tier['id'],
                    $id,
                ]);
            } else {
                $ins->execute([
                    $id,
                    $tier['label'] ?: null,
                    $tier['min_gpa'] ?? null,
                    $tier['max_gpa'] ?? null,
                    $tier['discount_percent'],
                    $tier['sort_order'],
                ]);
            }
        }

        log_change('scholarship-policies', 'UPDATE', $id, $name, null, null, null, 'Policy updated.');

        flash_set('success', 'Policy <strong>' . h($name) . '</strong> updated successfully.');
        redirect(APP_URL . '/scholarship/policies.php');
    }

    save_old($_POST);
    $tiers = sc_get_tiers($id);
}

$fv = [
    'name'        => old('name',        $policy['name']),
    'type'        => old('type',        $policy['type']),
    'description' => old('description', $policy['description'] ?? ''),
    'is_active'   => old('is_active',   (string)$policy['is_active']),
    'sort_order'  => old('sort_order',  (string)$policy['sort_order']),
];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-pencil me-2 text-primary"></i>Edit Policy</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/scholarship/index.php">Scholarships</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/scholarship/policies.php">Policies</a></li>
            <li class="breadcrumb-item active">Edit</li>
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
                        <input type="text" name="name" class="form-control" value="<?= h($fv['name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
                        <div class="d-flex gap-4 mt-1 flex-wrap">
                            <div class="form-check">
                                <input class="form-check-input policy-type-radio" type="radio" name="type" id="type_gpa" value="gpa_based"
                                       <?= $fv['type'] === 'gpa_based' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="type_gpa">
                                    <span class="badge bg-info text-dark me-1">GPA-Based</span>
                                    First-semester students (SSC+HSC GPA)
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input policy-type-radio" type="radio" name="type" id="type_merit" value="merit_based"
                                       <?= $fv['type'] === 'merit_based' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="type_merit">
                                    <span class="badge bg-primary me-1">Merit-Based</span>
                                    Continuing students (previous semester CGPA)
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input policy-type-radio" type="radio" name="type" id="type_flat" value="flat"
                                       <?= $fv['type'] === 'flat' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="type_flat">
                                    <span class="badge bg-success me-1">Flat Discount</span>
                                    Fixed discount for all qualifying students
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea name="description" class="form-control" rows="3"><?= h($fv['description']) ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Flat Discount -->
            <?php $flat_tier = ($fv['type'] === 'flat' && !empty($tiers)) ? $tiers[0] : null; ?>
            <div class="card border-0 shadow-sm mb-4" id="flat-discount-section" style="display:none;">
                <div class="card-header fw-semibold py-3">
                    <i class="fas fa-tag me-2 text-success"></i>Flat Discount
                </div>
                <div class="card-body">
                    <div class="row g-3 align-items-end">
                        <div class="col-sm-4">
                            <label class="form-label fw-semibold">Discount % <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <?php if ($flat_tier): ?>
                                <input type="hidden" name="tier_id[]" value="<?= $flat_tier['id'] ?>">
                                <?php endif; ?>
                                <input type="number" name="flat_discount" id="flat_discount" class="form-control"
                                       value="<?= h(old('flat_discount', $flat_tier ? $flat_tier['discount_percent'] : '')) ?>"
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
                        <table class="table mb-0">
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
                            <?php foreach ($tiers as $ri => $tier): ?>
                            <tr class="tier-row">
                                <input type="hidden" name="tier_id[]" value="<?= $tier['id'] ?>">
                                <td class="px-3"><input type="text" name="tier_label[]" class="form-control form-control-sm" value="<?= h($tier['label'] ?? '') ?>" placeholder="e.g. Gold"></td>
                                <td><input type="number" name="tier_min_gpa[]" class="form-control form-control-sm" value="<?= h($tier['min_gpa']) ?>" step="0.01" min="0"></td>
                                <td><input type="number" name="tier_max_gpa[]" class="form-control form-control-sm" value="<?= h($tier['max_gpa']) ?>" step="0.01" min="0"></td>
                                <td><input type="number" name="tier_discount[]" class="form-control form-control-sm" value="<?= h($tier['discount_percent']) ?>" step="0.01" min="0" max="100"></td>
                                <td><input type="number" name="tier_sort[]" class="form-control form-control-sm" value="<?= h($tier['sort_order']) ?>" min="0" style="width:70px;"></td>
                                <td><button type="button" class="btn btn-sm btn-outline-danger remove-tier-row" data-tier-id="<?= $tier['id'] ?>"><i class="fas fa-times"></i></button></td>
                            </tr>
                            <?php endforeach; ?>
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
                        <input type="number" name="sort_order" class="form-control" value="<?= h($fv['sort_order']) ?>" min="0">
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1"
                               <?= $fv['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="is_active">Active</label>
                    </div>
                </div>
            </div>
            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-save me-1"></i> Update Policy</button>
                <a href="<?= APP_URL ?>/scholarship/policies.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </div>
    </div>
</form>

<script>
var tierIdx = <?= count($tiers) ?>;
var deletedTierIds = [];

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
        <input type="hidden" name="tier_id[]" value="0">
        <td class="px-3"><input type="text" name="tier_label[]" class="form-control form-control-sm" placeholder="e.g. Gold"></td>
        <td><input type="number" name="tier_min_gpa[]" class="form-control form-control-sm" step="0.01" min="0" placeholder="0.00"></td>
        <td><input type="number" name="tier_max_gpa[]" class="form-control form-control-sm" step="0.01" min="0" placeholder="10.00"></td>
        <td><input type="number" name="tier_discount[]" class="form-control form-control-sm" step="0.01" min="0" max="100" placeholder="50.00"></td>
        <td><input type="number" name="tier_sort[]" class="form-control form-control-sm" value="${tierIdx}" min="0" style="width:70px;"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger remove-tier-row" data-tier-id="0"><i class="fas fa-times"></i></button></td>`;
    tbody.appendChild(tr);
    tierIdx++;
});

document.addEventListener('click', function (e) {
    var btn = e.target.closest('.remove-tier-row');
    if (!btn) return;
    var tierId = parseInt(btn.dataset.tierId, 10);
    if (tierId > 0) {
        deletedTierIds.push(tierId);
        var form = document.querySelector('form');
        var inp = document.createElement('input');
        inp.type  = 'hidden';
        inp.name  = 'deleted_tier_ids[]';
        inp.value = tierId;
        form.appendChild(inp);
    }
    btn.closest('tr').remove();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
