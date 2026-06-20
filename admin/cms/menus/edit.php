<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$id   = (int)($_GET['id'] ?? 0);
$item = null;
$errors = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM cms_menus WHERE id = ?');
    $stmt->execute([$id]);
    $item = $stmt->fetch();
}
if (!$item) {
    flash_set('error', 'Menu item not found.');
    redirect(APP_URL . '/cms/menus/index.php');
}

clear_old();
$page_title = 'Edit Menu Item';

// Load all menu items for parent selector, excluding self and own descendants.
// This allows 3-level nesting: megamenu → column header → column link.
$_all_for_parent = db()->prepare(
    "SELECT id, parent_id, label FROM cms_menus WHERE id != ? ORDER BY COALESCE(parent_id, id), sort_order, id"
);
$_all_for_parent->execute([$id]);
$_all_for_parent = $_all_for_parent->fetchAll();

// Build a flat list with indented labels, excluding descendants of $id.
function _build_parent_options_edit(array $rows, int $excludeId): array {
    $map = [];
    foreach ($rows as $r) {
        $map[$r['id']] = $r;
    }
    // Find all descendants of $excludeId to skip them.
    $descendants = [];
    $findDesc = function(int $pid) use (&$findDesc, $map, &$descendants) {
        foreach ($map as $r) {
            if ((int)($r['parent_id'] ?? 0) === $pid && !isset($descendants[$r['id']])) {
                $descendants[$r['id']] = true;
                $findDesc((int)$r['id']);
            }
        }
    };
    $findDesc($excludeId);

    $result  = [];
    $visited = [];
    $walk = function(int $parentId, int $depth) use (&$walk, $map, &$result, &$visited, $descendants) {
        foreach ($map as $r) {
            if ((int)($r['parent_id'] ?? 0) !== $parentId) continue;
            if (isset($visited[$r['id']])) continue;
            if (isset($descendants[$r['id']])) continue;
            $visited[$r['id']] = true;
            $prefix = $depth > 0 ? str_repeat('— ', $depth) : '';
            $result[] = ['id' => $r['id'], 'label' => $prefix . $r['label']];
            $walk((int)$r['id'], $depth + 1);
        }
    };
    $walk(0, 0);
    return $result;
}
$parents = _build_parent_options_edit($_all_for_parent, $id);

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
    if ($parent_id === $id) $errors[] = 'An item cannot be its own parent.';

    if ($parent_id && in_array($type, ['dropdown', 'megamenu'])) {
        $errors[] = 'Dropdown and Megamenu items cannot have a parent – they are top-level items.';
    }

    if (empty($errors)) {
        db()->prepare(
            'UPDATE cms_menus
             SET parent_id=?, label=?, url=?, target=?, type=?, icon=?, sort_order=?, is_active=?
             WHERE id=?'
        )->execute([$parent_id, $label, $url, $target, $type, $icon ?: null, $sort_order, $is_active, $id]);

        flash_set('success', 'Menu item <strong>' . h($label) . '</strong> updated.');
        redirect(APP_URL . '/cms/menus/index.php');
    }

    $item = array_merge($item, compact('label','url','target','type','icon','sort_order','is_active'));
    $item['parent_id'] = $parent_id;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/cms/menus/index.php">Navigation Menus</a></li>
            <li class="breadcrumb-item active">Edit</li>
        </ol>
    </nav>
</div>

<div class="row justify-content-center">
<div class="col-lg-7">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-edit me-2 text-muted"></i>Edit: <?= h($item['label']) ?></h6>
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
                <input type="text" name="label" class="form-control" value="<?= h($item['label']) ?>"
                       required maxlength="150">
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-8">
                    <label class="form-label fw-medium">URL</label>
                    <input type="text" name="url" class="form-control" value="<?= h($item['url']) ?>"
                           maxlength="500">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Target</label>
                    <select name="target" class="form-select">
                        <option value="_self"  <?= $item['target'] === '_self'  ? 'selected' : '' ?>>Same tab</option>
                        <option value="_blank" <?= $item['target'] === '_blank' ? 'selected' : '' ?>>New tab</option>
                    </select>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Type</label>
                    <select name="type" id="typeSelect" class="form-select">
                        <option value="link"     <?= $item['type'] === 'link'     ? 'selected' : '' ?>>Link</option>
                        <option value="dropdown" <?= $item['type'] === 'dropdown' ? 'selected' : '' ?>>Dropdown</option>
                        <option value="megamenu" <?= $item['type'] === 'megamenu' ? 'selected' : '' ?>>Megamenu</option>
                    </select>
                    <div class="form-text">Dropdown / Megamenu items are top-level parents.</div>
                </div>
                <div class="col-md-6" id="parentField">
                    <label class="form-label fw-medium">Parent Item</label>
                    <select name="parent_id" class="form-select">
                        <option value="">— None (top-level) —</option>
                        <?php foreach ($parents as $p): ?>
                        <option value="<?= $p['id'] ?>"
                            <?= (int)$item['parent_id'] === (int)$p['id'] ? 'selected' : '' ?>>
                            <?= h($p['label']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Icon Class</label>
                <input type="text" name="icon" class="form-control" value="<?= h($item['icon'] ?? '') ?>"
                       placeholder="fas fa-circle" maxlength="100">
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" value="<?= (int)$item['sort_order'] ?>" min="0">
                </div>
                <div class="col-md-8 d-flex align-items-end mb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                               value="1" <?= $item['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Update Item
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
    var typeSelect  = document.getElementById('typeSelect');
    var parentField = document.getElementById('parentField');

    function toggleParent() {
        var v = typeSelect.value;
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
