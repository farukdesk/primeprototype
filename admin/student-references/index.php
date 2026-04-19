<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('student-references');

$page_title = 'Student References';

// ── Helpers ────────────────────────────────────────────────────────────────────
function sr_table_for(string $type): string {
    return match($type) {
        'batches'  => 'student_batches',
        'exams'    => 'student_exam_titles',
        'boards'   => 'student_boards',
        'groups'   => 'student_groups',
        default    => '',
    };
}

function sr_has_short(string $type): bool {
    return in_array($type, ['exams', 'boards'], true);
}

$valid_types = ['batches', 'exams', 'boards', 'groups'];
$active_tab  = $_GET['tab'] ?? 'batches';
if (!in_array($active_tab, $valid_types, true)) $active_tab = 'batches';

// ── Toggle status via POST ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'toggle') {
    csrf_check();
    $type  = $_POST['type'] ?? '';
    $tid   = (int)($_POST['id'] ?? 0);
    $table = sr_table_for($type);
    if ($table && $tid > 0) {
        db()->prepare("UPDATE `$table` SET is_active = 1 - is_active WHERE id = ?")->execute([$tid]);
        flash_set('success', 'Status updated.');
    }
    redirect(APP_URL . '/student-references/index.php?tab=' . urlencode($type));
}

// ── Load data for all tabs ────────────────────────────────────────────────────
$batches = db()->query('SELECT * FROM student_batches ORDER BY sort_order, name ASC')->fetchAll();
$exams   = db()->query('SELECT * FROM student_exam_titles ORDER BY sort_order, name ASC')->fetchAll();
$boards  = db()->query('SELECT * FROM student_boards ORDER BY sort_order, name ASC')->fetchAll();
$groups  = db()->query('SELECT * FROM student_groups ORDER BY sort_order, name ASC')->fetchAll();

$data_map = [
    'batches' => $batches,
    'exams'   => $exams,
    'boards'  => $boards,
    'groups'  => $groups,
];
$label_map = [
    'batches' => 'Batches',
    'exams'   => 'Exam Titles',
    'boards'  => 'Academic Boards',
    'groups'  => 'Academic Groups',
];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Student References</li>
        </ol>
    </nav>
</div>

<?php flash_show(); ?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4" id="refTabs">
    <?php foreach ($valid_types as $t): ?>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === $t ? 'active' : '' ?>"
           href="?tab=<?= $t ?>"><?= $label_map[$t] ?>
           <span class="badge bg-secondary ms-1"><?= count($data_map[$t]) ?></span>
        </a>
    </li>
    <?php endforeach; ?>
</ul>

<?php foreach ($valid_types as $type):
    $items    = $data_map[$type];
    $label    = $label_map[$type];
    $hasShort = sr_has_short($type);
    $display  = ($active_tab === $type) ? 'block' : 'none';
?>
<div style="display:<?= $display ?>;" id="tab_<?= $type ?>">

    <!-- Add / Edit form -->
    <div class="card mb-4">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-plus-circle me-2 text-muted"></i>Add / Edit <?= $label ?></h6>
        </div>
        <div class="card-body px-4 py-3">
            <form method="POST" action="<?= APP_URL ?>/student-references/save.php" class="row g-3 align-items-end">
                <?= csrf_field() ?>
                <input type="hidden" name="type" value="<?= $type ?>">
                <input type="hidden" name="id"   value="" id="edit_id_<?= $type ?>">

                <div class="col-12 col-md-<?= $hasShort ? '5' : '7' ?>">
                    <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="name" id="edit_name_<?= $type ?>"
                           maxlength="200" required placeholder="<?= $label ?> name">
                </div>
                <?php if ($hasShort): ?>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold">Short Name</label>
                    <input type="text" class="form-control" name="short_name" id="edit_short_<?= $type ?>"
                           maxlength="50" placeholder="Abbreviation">
                </div>
                <?php else: ?>
                <input type="hidden" name="short_name" value="">
                <?php endif; ?>
                <div class="col-12 col-md-2">
                    <label class="form-label fw-semibold">Sort Order</label>
                    <input type="number" class="form-control" name="sort_order" id="edit_sort_<?= $type ?>"
                           value="0" min="0">
                </div>
                <div class="col-12 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-fill" style="border-radius:8px;">
                        <i class="fas fa-save me-1"></i> Save
                    </button>
                    <button type="button" class="btn btn-outline-secondary" style="border-radius:8px;"
                            onclick="clearEditForm('<?= $type ?>')">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- List table -->
    <div class="card">
        <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-list me-2 text-muted"></i>All <?= $label ?></h6>
            <span class="badge bg-primary bg-opacity-10 text-primary"><?= count($items) ?> total</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="px-4" style="width:50px;">#</th>
                            <th>Name</th>
                            <?php if ($hasShort): ?>
                            <th>Short Name</th>
                            <?php endif; ?>
                            <th style="width:80px;">Order</th>
                            <th style="width:80px;">Status</th>
                            <th class="text-end pe-4" style="width:120px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($items)): ?>
                        <tr><td colspan="<?= $hasShort ? 6 : 5 ?>" class="text-center text-muted py-4">
                            No <?= strtolower($label) ?> yet. Add one above.
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($items as $i => $item): ?>
                        <tr>
                            <td class="px-4"><?= $i + 1 ?></td>
                            <td class="fw-medium"><?= h($item['name']) ?></td>
                            <?php if ($hasShort): ?>
                            <td><?= $item['short_name'] ? '<code>' . h($item['short_name']) . '</code>' : '<span class="text-muted">—</span>' ?></td>
                            <?php endif; ?>
                            <td><?= (int)$item['sort_order'] ?></td>
                            <td>
                                <form method="POST" style="display:inline;"
                                      onsubmit="return confirm('Toggle status?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="toggle">
                                    <input type="hidden" name="type" value="<?= $type ?>">
                                    <input type="hidden" name="id"   value="<?= $item['id'] ?>">
                                    <button type="submit"
                                            class="badge border-0 <?= $item['is_active'] ? 'bg-success' : 'bg-secondary' ?>"
                                            style="cursor:pointer;">
                                        <?= $item['is_active'] ? 'Active' : 'Inactive' ?>
                                    </button>
                                </form>
                            </td>
                            <td class="text-end pe-4">
                                <div class="d-flex gap-1 justify-content-end">
                                    <button type="button" class="btn btn-sm btn-outline-primary" style="border-radius:7px;"
                                            onclick="loadEdit('<?= $type ?>',
                                                <?= $item['id'] ?>,
                                                <?= json_encode($item['name']) ?>,
                                                <?= json_encode($item['short_name'] ?? '') ?>,
                                                <?= (int)$item['sort_order'] ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" action="<?= APP_URL ?>/student-references/delete.php"
                                          onsubmit="return confirm('Delete &quot;<?= h(addslashes($item['name'])) ?>&quot;?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="type" value="<?= $type ?>">
                                        <input type="hidden" name="id"   value="<?= $item['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" style="border-radius:7px;">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script>
function loadEdit(type, id, name, shortName, sortOrder) {
    document.getElementById('edit_id_'   + type).value   = id;
    document.getElementById('edit_name_' + type).value   = name;
    var shortEl = document.getElementById('edit_short_' + type);
    if (shortEl) shortEl.value = shortName || '';
    document.getElementById('edit_sort_' + type).value   = sortOrder;
    document.getElementById('edit_name_' + type).focus();
}

function clearEditForm(type) {
    document.getElementById('edit_id_'   + type).value   = '';
    document.getElementById('edit_name_' + type).value   = '';
    var shortEl = document.getElementById('edit_short_' + type);
    if (shortEl) shortEl.value = '';
    document.getElementById('edit_sort_' + type).value   = '0';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
