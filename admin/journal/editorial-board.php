<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('journal');
require_once __DIR__ . '/helpers.php';
csrf_check();

$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save') {
            jm_require_write();
            $id         = (int)($_POST['id'] ?? 0);
            $journal_id = (int)($_POST['journal_id'] ?? 0);
            $name       = trim($_POST['name'] ?? '');
            if ($journal_id <= 0 || $name === '') throw new RuntimeException('Journal and name are required.');
            $fields = [
                $journal_id, $name,
                trim($_POST['role'] ?? 'Member') ?: 'Member',
                trim($_POST['affiliation'] ?? ''),
                trim($_POST['email'] ?? ''),
                (int)($_POST['sort_order'] ?? 0),
                isset($_POST['is_active']) ? 1 : 0,
            ];
            $photo = null;
            if (!empty($_FILES['photo']['name'])) {
                $photo = jm_upload_image($_FILES['photo'], 'board');
            }
            if ($id > 0) {
                $sql = 'UPDATE journal_editorial_board SET journal_id=?, name=?, role=?, affiliation=?, email=?, sort_order=?, is_active=?'
                     . ($photo ? ', photo=?' : '') . ' WHERE id=?';
                $params = $fields;
                if ($photo) $params[] = $photo;
                $params[] = $id;
                $db->prepare($sql)->execute($params);
                flash_set('success', 'Board member updated.');
            } else {
                $db->prepare(
                    'INSERT INTO journal_editorial_board
                     (journal_id, name, role, affiliation, email, sort_order, is_active, photo)
                     VALUES (?,?,?,?,?,?,?,?)'
                )->execute(array_merge($fields, [$photo]));
                flash_set('success', 'Board member added.');
            }
        } elseif ($action === 'delete') {
            if (!jm_can_delete()) throw new RuntimeException('No permission to delete.');
            $db->prepare('DELETE FROM journal_editorial_board WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
            flash_set('success', 'Board member removed.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect(APP_URL . '/journal/editorial-board.php' . (!empty($_POST['journal_id']) ? '?journal_id=' . (int)$_POST['journal_id'] : ''));
}

$journals   = jm_journals();
$journal_id = (int)($_GET['journal_id'] ?? ($journals[0]['id'] ?? 0));

$members = [];
if ($journal_id > 0) {
    $stmt = $db->prepare(
        'SELECT * FROM journal_editorial_board WHERE journal_id = ? ORDER BY sort_order ASC, name ASC'
    );
    $stmt->execute([$journal_id]);
    $members = $stmt->fetchAll();
}

$page_title = 'Editorial Board';
require_once __DIR__ . '/../includes/header.php';

function jm_board_form(array $journals, int $journal_id, array $m = []): void { ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($m['id'] ?? 0) ?>">
    <div class="mb-2"><label class="form-label">Journal *</label>
        <select class="form-select" name="journal_id" required>
            <?php foreach ($journals as $j): ?>
            <option value="<?= (int)$j['id'] ?>" <?= (int)($m['journal_id'] ?? $journal_id) === (int)$j['id'] ? 'selected' : '' ?>>
                <?= h($j['title']) ?></option>
            <?php endforeach; ?>
        </select></div>
    <div class="row g-2">
        <div class="col-md-6 mb-2"><label class="form-label">Name *</label>
            <input class="form-control" name="name" required value="<?= h($m['name'] ?? '') ?>"></div>
        <div class="col-md-6 mb-2"><label class="form-label">Role</label>
            <input class="form-control" name="role" list="jm-roles" value="<?= h($m['role'] ?? '') ?>" placeholder="e.g. Editor-in-Chief">
            <datalist id="jm-roles">
                <option value="Editor-in-Chief"><option value="Managing Editor"><option value="Associate Editor">
                <option value="Member"><option value="Advisor">
            </datalist></div>
        <div class="col-md-6 mb-2"><label class="form-label">Affiliation</label>
            <input class="form-control" name="affiliation" value="<?= h($m['affiliation'] ?? '') ?>"></div>
        <div class="col-md-6 mb-2"><label class="form-label">Email</label>
            <input type="email" class="form-control" name="email" value="<?= h($m['email'] ?? '') ?>"></div>
        <div class="col-md-4 mb-2"><label class="form-label">Sort Order</label>
            <input type="number" class="form-control" name="sort_order" value="<?= (int)($m['sort_order'] ?? 0) ?>"></div>
        <div class="col-md-4 mb-2"><label class="form-label">Photo</label>
            <input type="file" class="form-control" name="photo" accept="image/*"></div>
        <div class="col-md-4 mb-2 d-flex align-items-end">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_active" id="bactive<?= (int)($m['id'] ?? 0) ?>"
                       <?= !isset($m['is_active']) || $m['is_active'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="bactive<?= (int)($m['id'] ?? 0) ?>">Active</label>
            </div></div>
    </div>
<?php } ?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/journal/index.php">Journal Management</a></li>
        <li class="breadcrumb-item active">Editorial Board</li>
    </ol></nav>
    <?php if (jm_can_create() && $journals): ?>
    <button class="btn btn-primary btn-sm" style="border-radius:10px;" data-bs-toggle="modal" data-bs-target="#modal-new">
        <i class="fas fa-plus me-1"></i> Add Member</button>
    <?php endif; ?>
</div>

<?php flash_show(); ?>

<form method="get" class="mb-3" style="max-width:420px;">
    <label class="form-label small text-muted">Journal</label>
    <select class="form-select" name="journal_id" onchange="this.form.submit()">
        <?php foreach ($journals as $j): ?>
        <option value="<?= (int)$j['id'] ?>" <?= $journal_id === (int)$j['id'] ? 'selected' : '' ?>><?= h($j['title']) ?></option>
        <?php endforeach; ?>
    </select>
</form>

<div class="card border-0 shadow-sm" style="border-radius:12px;">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Name</th><th>Role</th><th>Affiliation</th><th>Email</th><th>Status</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
                <?php if (!$members): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No board members for this journal yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($members as $m): ?>
                <tr>
                    <td class="fw-semibold"><?= h($m['name']) ?></td>
                    <td><?= h($m['role']) ?></td>
                    <td><?= h($m['affiliation'] ?: '—') ?></td>
                    <td><?= h($m['email'] ?: '—') ?></td>
                    <td><span class="badge bg-<?= $m['is_active'] ? 'success' : 'secondary' ?>"><?= $m['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                    <td class="text-end">
                        <?php if (jm_is_staff()): ?>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modal-edit-<?= (int)$m['id'] ?>">
                            <i class="fas fa-pen"></i></button>
                        <?php endif; ?>
                        <?php if (jm_can_delete()): ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('Remove this board member?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                            <input type="hidden" name="journal_id" value="<?= $journal_id ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="modal-new" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="modal-header"><h5 class="modal-title">Add Board Member</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><?php jm_board_form($journals, $journal_id); ?></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary">Save</button></div>
        </form>
    </div></div>
</div>

<?php foreach ($members as $m): ?>
<div class="modal fade" id="modal-edit-<?= (int)$m['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="modal-header"><h5 class="modal-title">Edit Board Member</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><?php jm_board_form($journals, $journal_id, $m); ?></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary">Save Changes</button></div>
        </form>
    </div></div>
</div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
