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
            $name = trim($_POST['name'] ?? '');
            if ($name === '') throw new RuntimeException('Journal name is required.');

            $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
            $fields = [
                trim($_POST['short_name'] ?? ''),
                trim($_POST['issn'] ?? ''),
                trim($_POST['e_issn'] ?? ''),
                trim($_POST['description'] ?? ''),
                trim($_POST['publisher'] ?? ''),
                trim($_POST['department'] ?? ''),
                trim($_POST['frequency'] ?? ''),
                trim($_POST['language'] ?? '') ?: 'English',
                trim($_POST['contact_email'] ?? ''),
                trim($_POST['website_url'] ?? ''),
                $status,
                (int)($_POST['sort_order'] ?? 0),
            ];
            $logo = null;
            if (!empty($_FILES['logo']['name'])) {
                $logo = jm_upload_image($_FILES['logo'], 'logos');
            }

            if ($id > 0) {
                $sql = 'UPDATE journal_journals SET name=?, short_name=?, issn=?, e_issn=?, description=?,
                        publisher=?, department=?, frequency=?, language=?, contact_email=?, website_url=?,
                        status=?, sort_order=?'
                     . ($logo ? ', logo=?' : '') . ' WHERE id=?';
                $params = array_merge([$name], $fields);
                if ($logo) $params[] = $logo;
                $params[] = $id;
                $db->prepare($sql)->execute($params);
                flash_set('success', 'Journal updated.');
            } else {
                $slug = jm_unique_slug('journal_journals', $name);
                $db->prepare(
                    'INSERT INTO journal_journals
                     (name, slug, short_name, issn, e_issn, description, publisher, department,
                      frequency, language, contact_email, website_url, status, sort_order, logo)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute(array_merge([$name, $slug], $fields, [$logo]));
                flash_set('success', 'Journal created.');
            }
        } elseif ($action === 'delete') {
            if (!jm_can_delete()) throw new RuntimeException('No permission to delete.');
            $db->prepare('DELETE FROM journal_journals WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
            flash_set('success', 'Journal deleted.');
        }
    } catch (Throwable $e) {
        flash_set('error', str_contains($e->getMessage(), 'foreign key')
            ? 'Cannot delete: this journal still has articles. Delete or move them first.'
            : $e->getMessage());
    }
    redirect(APP_URL . '/journal/journals.php');
}

$journals = $db->query(
    'SELECT j.*,
            (SELECT COUNT(*) FROM journal_volumes v WHERE v.journal_id = j.id) AS volume_count
     FROM journal_journals j ORDER BY j.sort_order ASC, j.name ASC'
)->fetchAll();

$page_title = 'Journals';
require_once __DIR__ . '/../includes/header.php';

// Renders the shared journal form fields inside a modal.
function jm_journal_form(array $j = []): void { ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($j['id'] ?? 0) ?>">
    <div class="row g-2">
        <div class="col-md-8 mb-2"><label class="form-label">Journal Name *</label>
            <input class="form-control" name="name" required value="<?= h($j['name'] ?? '') ?>"
                   placeholder="e.g. Journal of Prime University"></div>
        <div class="col-md-4 mb-2"><label class="form-label">Short Name</label>
            <input class="form-control" name="short_name" value="<?= h($j['short_name'] ?? '') ?>" placeholder="e.g. JPU"></div>
        <div class="col-md-6 mb-2"><label class="form-label">ISSN</label>
            <input class="form-control" name="issn" value="<?= h($j['issn'] ?? '') ?>" placeholder="XXXX-XXXX"></div>
        <div class="col-md-6 mb-2"><label class="form-label">e-ISSN</label>
            <input class="form-control" name="e_issn" value="<?= h($j['e_issn'] ?? '') ?>" placeholder="XXXX-XXXX"></div>
        <div class="col-md-6 mb-2"><label class="form-label">Publisher</label>
            <input class="form-control" name="publisher" value="<?= h($j['publisher'] ?? '') ?>" placeholder="e.g. Prime University"></div>
        <div class="col-md-6 mb-2"><label class="form-label">Department</label>
            <input class="form-control" name="department" value="<?= h($j['department'] ?? '') ?>"
                   placeholder="e.g. Research / Academic Affairs"></div>
        <div class="col-md-4 mb-2"><label class="form-label">Frequency</label>
            <input class="form-control" name="frequency" list="jm-frequencies" value="<?= h($j['frequency'] ?? '') ?>"
                   placeholder="e.g. Biannual">
            <datalist id="jm-frequencies">
                <option value="Annual"><option value="Biannual"><option value="Quarterly">
                <option value="Monthly"><option value="Irregular">
            </datalist></div>
        <div class="col-md-4 mb-2"><label class="form-label">Language</label>
            <input class="form-control" name="language" value="<?= h($j['language'] ?? 'English') ?>"></div>
        <div class="col-md-4 mb-2"><label class="form-label">Status</label>
            <select class="form-select" name="status">
                <option value="active"   <?= ($j['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= ($j['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select></div>
        <div class="col-md-6 mb-2"><label class="form-label">Contact Email</label>
            <input type="email" class="form-control" name="contact_email" value="<?= h($j['contact_email'] ?? '') ?>"></div>
        <div class="col-md-6 mb-2"><label class="form-label">Website URL</label>
            <input type="url" class="form-control" name="website_url" value="<?= h($j['website_url'] ?? '') ?>"
                   placeholder="https://…"></div>
        <div class="col-md-6 mb-2"><label class="form-label">Logo</label>
            <input type="file" class="form-control" name="logo" accept="image/*">
            <?php if (!empty($j['logo'])): ?>
            <div class="form-text">Current: <?= h($j['logo']) ?> (uploading a new file replaces it)</div>
            <?php endif; ?></div>
        <div class="col-md-6 mb-2"><label class="form-label">Sort Order</label>
            <input type="number" class="form-control" name="sort_order" value="<?= (int)($j['sort_order'] ?? 0) ?>"></div>
    </div>
    <div class="mb-2"><label class="form-label">Description / Aims &amp; Scope</label>
        <textarea class="form-control" rows="4" name="description"><?= h($j['description'] ?? '') ?></textarea></div>
<?php } ?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/journal/index.php">Journal Management</a></li>
        <li class="breadcrumb-item active">Journals</li>
    </ol></nav>
    <?php if (jm_can_create()): ?>
    <button class="btn btn-primary btn-sm" style="border-radius:10px;" data-bs-toggle="modal" data-bs-target="#modal-new">
        <i class="fas fa-plus me-1"></i> Add Journal
    </button>
    <?php endif; ?>
</div>

<?php flash_show(); ?>

<div class="card border-0 shadow-sm" style="border-radius:12px;">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Name</th><th>ISSN / e-ISSN</th><th>Department</th><th>Frequency</th><th>Volumes</th><th>Status</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
                <?php if (!$journals): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No journals yet. Create the first one.</td></tr>
                <?php endif; ?>
                <?php foreach ($journals as $j): ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= h($j['name']) ?>
                            <?php if ($j['short_name']): ?><span class="text-muted">(<?= h($j['short_name']) ?>)</span><?php endif; ?>
                        </div>
                        <div class="small text-muted">/journal.php?slug=<?= h($j['slug']) ?></div>
                    </td>
                    <td><?= h($j['issn'] ?: '—') ?> / <?= h($j['e_issn'] ?: '—') ?></td>
                    <td><?= h($j['department'] ?: '—') ?></td>
                    <td><?= h($j['frequency'] ?: '—') ?></td>
                    <td><?= (int)$j['volume_count'] ?></td>
                    <td><span class="badge bg-<?= $j['status'] === 'active' ? 'success' : 'secondary' ?>"><?= h(ucfirst($j['status'])) ?></span></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-secondary" target="_blank"
                           href="<?= h(SITE_URL . '/journal.php?slug=' . rawurlencode($j['slug'])) ?>" title="View public page">
                            <i class="fas fa-external-link-alt"></i></a>
                        <?php if (jm_is_staff()): ?>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modal-edit-<?= (int)$j['id'] ?>">
                            <i class="fas fa-pen"></i></button>
                        <?php endif; ?>
                        <?php if (jm_can_delete()): ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this journal and all its volumes/issues?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$j['id'] ?>">
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

<!-- Create modal -->
<div class="modal fade" id="modal-new" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="modal-header"><h5 class="modal-title">Add Journal</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><?php jm_journal_form(); ?></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary">Save</button></div>
        </form>
    </div></div>
</div>

<!-- Edit modals -->
<?php foreach ($journals as $j): ?>
<div class="modal fade" id="modal-edit-<?= (int)$j['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="modal-header"><h5 class="modal-title">Edit Journal</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><?php jm_journal_form($j); ?></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary">Save Changes</button></div>
        </form>
    </div></div>
</div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
