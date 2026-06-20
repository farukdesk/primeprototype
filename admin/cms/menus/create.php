<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$page_title = 'Add Menu Item';
$errors     = [];
clear_old();

// Load all existing menu items to build a hierarchical parent selector.
// We include items at every depth so that 3rd-level (grandchild) links can be created
// under megamenu column headers.
$_all_menu_items = db()->query(
    "SELECT id, parent_id, label FROM cms_menus ORDER BY COALESCE(parent_id, id), sort_order, id"
)->fetchAll();

// Build a flat list with indented labels showing parent path.
function _build_parent_options(array $rows): array {
    // index by id
    $map = [];
    foreach ($rows as $r) {
        $map[$r['id']] = $r;
    }
    // produce flat list in tree order with depth-based prefix
    $result = [];
    $visited = [];
    // recursive helper
    $walk = function(int $parentId, int $depth) use (&$walk, $map, &$result, &$visited) {
        foreach ($map as $r) {
            if ((int)($r['parent_id'] ?? 0) !== $parentId) continue;
            if (isset($visited[$r['id']])) continue;
            $visited[$r['id']] = true;
            $prefix = $depth > 0 ? str_repeat('— ', $depth) : '';
            $result[] = ['id' => $r['id'], 'label' => $prefix . $r['label']];
            $walk((int)$r['id'], $depth + 1);
        }
    };
    $walk(0, 0);
    return $result;
}
$parents = _build_parent_options($_all_menu_items);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $label      = trim($_POST['label']      ?? '');
    $url        = trim($_POST['url']        ?? '#');
    $target     = in_array($_POST['target'] ?? '', ['_self','_blank']) ? $_POST['target'] : '_self';
    $type       = in_array($_POST['type']   ?? '', ['link','dropdown','megamenu']) ? $_POST['type'] : 'link';
    $icon       = trim($_POST['icon']       ?? '');
    $parent_id  = (int)($_POST['parent_id'] ?? 0) ?: null;
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $is_active  = isset($_POST['is_active']) ? 1 : 0;

    if ($label === '') $errors[] = 'Label is required.';

    // Megamenu / dropdown items must be top-level (no parent)
    if ($parent_id && in_array($type, ['dropdown', 'megamenu'])) {
        $errors[] = 'Dropdown and Megamenu items cannot have a parent – they are top-level items.';
    }

    if (empty($errors)) {
        db()->prepare(
            'INSERT INTO cms_menus (parent_id, label, url, target, type, icon, sort_order, is_active)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$parent_id, $label, $url, $target, $type, $icon ?: null, $sort_order, $is_active]);

        flash_set('success', 'Menu item <strong>' . h($label) . '</strong> created.');
        redirect(APP_URL . '/cms/menus/index.php');
    }

    save_old(compact('label','url','target','type','icon','sort_order'));
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/cms/menus/index.php">Navigation Menus</a></li>
            <li class="breadcrumb-item active">Add Item</li>
        </ol>
    </nav>
</div>

<div class="row justify-content-center">
<div class="col-lg-7">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-bars me-2 text-muted"></i>New Menu Item</h6>
    </div>
    <div class="card-body p-4">

        <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <?= csrf_field() ?>

            <div class="mb-3">
                <label class="form-label fw-medium">Label <span class="text-danger">*</span></label>
                <input type="text" name="label" class="form-control" value="<?= old('label') ?>"
                       required placeholder="e.g. About Us" maxlength="150">
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-8">
                    <label class="form-label fw-medium">URL</label>
                    <input type="text" name="url" class="form-control" value="<?= old('url', '#') ?>"
                           placeholder="https:// or /page or #" maxlength="500">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Target</label>
                    <select name="target" class="form-select">
                        <option value="_self"  <?= old('target','_self')  === '_self'  ? 'selected' : '' ?>>Same tab</option>
                        <option value="_blank" <?= old('target','_self')  === '_blank' ? 'selected' : '' ?>>New tab</option>
                    </select>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Type</label>
                    <select name="type" id="typeSelect" class="form-select">
                        <option value="link"     <?= old('type','link') === 'link'     ? 'selected' : '' ?>>Link</option>
                        <option value="dropdown" <?= old('type','link') === 'dropdown' ? 'selected' : '' ?>>Dropdown</option>
                        <option value="megamenu" <?= old('type','link') === 'megamenu' ? 'selected' : '' ?>>Megamenu</option>
                    </select>
                    <div class="form-text">Dropdown / Megamenu items are top-level parents.</div>
                </div>
                <div class="col-md-6" id="parentField">
                    <label class="form-label fw-medium">Parent Item</label>
                    <select name="parent_id" class="form-select">
                        <option value="">— None (top-level) —</option>
                        <?php foreach ($parents as $p): ?>
                        <option value="<?= $p['id'] ?>"
                            <?= (int)(old('parent_id',0)) === (int)$p['id'] ? 'selected' : '' ?>>
                            <?= h($p['label']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Icon Class
                    <small class="text-muted">(optional, e.g. <code>fas fa-home</code>)</small>
                </label>
                <input type="text" name="icon" class="form-control" value="<?= old('icon') ?>"
                       placeholder="fas fa-circle" maxlength="100">
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" value="<?= old('sort_order','0') ?>" min="0">
                </div>
                <div class="col-md-8 d-flex align-items-end mb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" checked>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Save Item
                </button>
                <a href="<?= APP_URL ?>/cms/menus/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<script>
(function () {
    var typeSelect   = document.getElementById('typeSelect');
    var parentField  = document.getElementById('parentField');

    function toggleParent() {
        var v = typeSelect.value;
        // Dropdown / Megamenu are always top-level → hide parent selector
        if (v === 'dropdown' || v === 'megamenu') {
            parentField.style.opacity = '.4';
            parentField.querySelector('select').value = '';
            parentField.querySelector('select').disabled = true;
        } else {
            parentField.style.opacity = '1';
            parentField.querySelector('select').disabled = false;
        }
    }
    typeSelect.addEventListener('change', toggleParent);
    toggleParent();
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
