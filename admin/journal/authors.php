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
            $id   = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['full_name'] ?? '');
            if ($name === '') throw new RuntimeException('Author name is required.');
            $fields = [
                $name,
                trim($_POST['email'] ?? ''),
                trim($_POST['affiliation'] ?? ''),
                trim($_POST['country'] ?? ''),
                trim($_POST['bio'] ?? ''),
            ];
            if ($id > 0) {
                $db->prepare('UPDATE journal_authors SET full_name=?, email=?, affiliation=?, country=?, bio=? WHERE id=?')
                   ->execute(array_merge($fields, [$id]));
                flash_set('success', 'Author updated.');
            } else {
                $db->prepare('INSERT INTO journal_authors (full_name, email, affiliation, country, bio) VALUES (?,?,?,?,?)')
                   ->execute($fields);
                flash_set('success', 'Author added.');
            }
        } elseif ($action === 'delete') {
            if (!jm_can_delete()) throw new RuntimeException('No permission to delete.');
            $id = (int)($_POST['id'] ?? 0);
            $used = $db->prepare('SELECT COUNT(*) FROM journal_article_authors WHERE author_id = ?');
            $used->execute([$id]);
            if ((int)$used->fetchColumn() > 0) {
                throw new RuntimeException('Cannot delete: this author is assigned to one or more articles.');
            }
            $db->prepare('DELETE FROM journal_authors WHERE id = ?')->execute([$id]);
            flash_set('success', 'Author deleted.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect(APP_URL . '/journal/authors.php');
}

$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $stmt = $db->prepare(
        'SELECT a.*, (SELECT COUNT(*) FROM journal_article_authors aa WHERE aa.author_id = a.id) AS article_count
         FROM journal_authors a
         WHERE a.full_name LIKE ? OR a.email LIKE ? OR a.affiliation LIKE ?
         ORDER BY a.full_name ASC LIMIT 300'
    );
    $like = '%' . $q . '%';
    $stmt->execute([$like, $like, $like]);
    $authors = $stmt->fetchAll();
} else {
    $authors = $db->query(
        'SELECT a.*, (SELECT COUNT(*) FROM journal_article_authors aa WHERE aa.author_id = a.id) AS article_count
         FROM journal_authors a ORDER BY a.full_name ASC LIMIT 300'
    )->fetchAll();
}

$page_title = 'Authors';
require_once __DIR__ . '/../includes/header.php';

function jm_author_form(array $a = []): void { ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($a['id'] ?? 0) ?>">
    <div class="row g-2">
        <div class="col-md-6 mb-2"><label class="form-label">Full Name *</label>
            <input class="form-control" name="full_name" required value="<?= h($a['full_name'] ?? '') ?>"></div>
        <div class="col-md-6 mb-2"><label class="form-label">Email</label>
            <input type="email" class="form-control" name="email" value="<?= h($a['email'] ?? '') ?>"></div>
        <div class="col-md-8 mb-2"><label class="form-label">Affiliation</label>
            <input class="form-control" name="affiliation" value="<?= h($a['affiliation'] ?? '') ?>" placeholder="Department, University"></div>
        <div class="col-md-4 mb-2"><label class="form-label">Country</label>
            <input class="form-control" name="country" value="<?= h($a['country'] ?? '') ?>"></div>
    </div>
    <div class="mb-2"><label class="form-label">Short Bio</label>
        <textarea class="form-control" rows="3" name="bio"><?= h($a['bio'] ?? '') ?></textarea></div>
<?php } ?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/journal/index.php">Journal Management</a></li>
        <li class="breadcrumb-item active">Authors</li>
    </ol></nav>
    <?php if (jm_can_create()): ?>
    <button class="btn btn-primary btn-sm" style="border-radius:10px;" data-bs-toggle="modal" data-bs-target="#modal-new">
        <i class="fas fa-plus me-1"></i> Add Author</button>
    <?php endif; ?>
</div>

<?php flash_show(); ?>

<form method="get" class="mb-3 d-flex gap-2" style="max-width:480px;">
    <input class="form-control" name="q" placeholder="Search name, email or affiliation…" value="<?= h($q) ?>">
    <button class="btn btn-outline-primary"><i class="fas fa-search"></i></button>
</form>

<div class="card border-0 shadow-sm" style="border-radius:12px;">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Name</th><th>Email</th><th>Affiliation</th><th>Country</th><th>Articles</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
                <?php if (!$authors): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No authors found.</td></tr>
                <?php endif; ?>
                <?php foreach ($authors as $a): ?>
                <tr>
                    <td class="fw-semibold"><?= h($a['full_name']) ?></td>
                    <td><?= h($a['email'] ?: '—') ?></td>
                    <td><?= h($a['affiliation'] ?: '—') ?></td>
                    <td><?= h($a['country'] ?: '—') ?></td>
                    <td><?= (int)$a['article_count'] ?></td>
                    <td class="text-end">
                        <?php if (jm_is_staff()): ?>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modal-edit-<?= (int)$a['id'] ?>">
                            <i class="fas fa-pen"></i></button>
                        <?php endif; ?>
                        <?php if (jm_can_delete()): ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this author?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
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
        <form method="post">
            <?= csrf_field() ?>
            <div class="modal-header"><h5 class="modal-title">Add Author</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><?php jm_author_form(); ?></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary">Save</button></div>
        </form>
    </div></div>
</div>

<?php foreach ($authors as $a): ?>
<div class="modal fade" id="modal-edit-<?= (int)$a['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <form method="post">
            <?= csrf_field() ?>
            <div class="modal-header"><h5 class="modal-title">Edit Author</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><?php jm_author_form($a); ?></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary">Save Changes</button></div>
        </form>
    </div></div>
</div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
