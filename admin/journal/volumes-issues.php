<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('journal');
require_once __DIR__ . '/helpers.php';
csrf_check();

$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action'] ?? '';
    $journal_id = (int)($_POST['journal_id'] ?? 0);
    try {
        if ($action === 'save_volume') {
            jm_require_write();
            $vol  = (int)($_POST['volume_number'] ?? 0);
            $year = (int)($_POST['year'] ?? 0);
            if ($journal_id <= 0 || $vol <= 0 || $year < 1900) throw new RuntimeException('Journal, volume number and year are required.');
            $db->prepare('INSERT INTO journal_volumes (journal_id, volume_number, year) VALUES (?,?,?)')
               ->execute([$journal_id, $vol, $year]);
            flash_set('success', 'Volume created.');
        } elseif ($action === 'save_issue') {
            jm_require_write();
            $id        = (int)($_POST['id'] ?? 0);
            $volume_id = (int)($_POST['volume_id'] ?? 0);
            $num       = (int)($_POST['issue_number'] ?? 0);
            if ($volume_id <= 0 || $num <= 0) throw new RuntimeException('Volume and issue number are required.');
            $title     = trim($_POST['title'] ?? '');
            $pub_date  = trim($_POST['published_date'] ?? '') ?: null;
            $published = isset($_POST['is_published']) ? 1 : 0;
            $current   = isset($_POST['is_current']) ? 1 : 0;
            if ($id > 0) {
                $db->prepare('UPDATE journal_issues SET volume_id=?, issue_number=?, title=?, published_date=?, is_published=?, is_current=? WHERE id=?')
                   ->execute([$volume_id, $num, $title, $pub_date, $published, $current, $id]);
            } else {
                $db->prepare('INSERT INTO journal_issues (volume_id, issue_number, title, published_date, is_published, is_current) VALUES (?,?,?,?,?,?)')
                   ->execute([$volume_id, $num, $title, $pub_date, $published, $current]);
                $id = (int)$db->lastInsertId();
            }
            if ($current) {
                // Only one current issue per journal.
                $db->prepare(
                    'UPDATE journal_issues i
                     JOIN journal_volumes v  ON v.id = i.volume_id
                     JOIN journal_volumes v2 ON v2.journal_id = v.journal_id
                     SET i.is_current = 0
                     WHERE i.volume_id = v.id AND v.journal_id = v2.journal_id AND v2.id = ? AND i.id <> ?'
                )->execute([$volume_id, $id]);
            }
            flash_set('success', 'Issue saved.');
        } elseif ($action === 'delete_volume') {
            if (!jm_can_delete()) throw new RuntimeException('No permission to delete.');
            $db->prepare('DELETE FROM journal_volumes WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
            flash_set('success', 'Volume deleted.');
        } elseif ($action === 'delete_issue') {
            if (!jm_can_delete()) throw new RuntimeException('No permission to delete.');
            $db->prepare('DELETE FROM journal_issues WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
            flash_set('success', 'Issue deleted.');
        }
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'Duplicate'))    $msg = 'That volume/issue number already exists.';
        if (str_contains($msg, 'foreign key')) $msg = 'Cannot delete: articles are still attached. Delete or move them first.';
        flash_set('error', $msg);
    }
    redirect(APP_URL . '/journal/volumes-issues.php' . ($journal_id ? '?journal_id=' . $journal_id : ''));
}

$journals   = jm_journals();
$journal_id = (int)($_GET['journal_id'] ?? ($journals[0]['id'] ?? 0));

$volumes = [];
if ($journal_id > 0) {
    $stmt = $db->prepare('SELECT * FROM journal_volumes WHERE journal_id = ? ORDER BY volume_number DESC');
    $stmt->execute([$journal_id]);
    $volumes = $stmt->fetchAll();
    $istmt = $db->prepare(
        'SELECT i.*, (SELECT COUNT(*) FROM journal_articles a WHERE a.issue_id = i.id) AS article_count
         FROM journal_issues i WHERE i.volume_id = ? ORDER BY i.issue_number DESC'
    );
    foreach ($volumes as &$v) {
        $istmt->execute([$v['id']]);
        $v['issues'] = $istmt->fetchAll();
    }
    unset($v);
}

$page_title = 'Volumes & Issues';
require_once __DIR__ . '/../includes/header.php';

function jm_issue_form(int $journal_id, int $volume_id, array $i = []): void { ?>
    <input type="hidden" name="action" value="save_issue">
    <input type="hidden" name="journal_id" value="<?= $journal_id ?>">
    <input type="hidden" name="volume_id" value="<?= $volume_id ?>">
    <input type="hidden" name="id" value="<?= (int)($i['id'] ?? 0) ?>">
    <div class="row g-2">
        <div class="col-md-4 mb-2"><label class="form-label">Issue Number *</label>
            <input type="number" min="1" class="form-control" name="issue_number" required value="<?= (int)($i['issue_number'] ?? '') ?>"></div>
        <div class="col-md-8 mb-2"><label class="form-label">Title (optional)</label>
            <input class="form-control" name="title" value="<?= h($i['title'] ?? '') ?>" placeholder="e.g. Special Issue on AI"></div>
        <div class="col-md-4 mb-2"><label class="form-label">Published Date</label>
            <input type="date" class="form-control" name="published_date" value="<?= h($i['published_date'] ?? '') ?>"></div>
        <div class="col-md-4 mb-2 d-flex align-items-end"><div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_published" id="ip<?= (int)($i['id'] ?? 0) ?>v<?= $volume_id ?>" <?= !empty($i['is_published']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="ip<?= (int)($i['id'] ?? 0) ?>v<?= $volume_id ?>">Published (visible)</label></div></div>
        <div class="col-md-4 mb-2 d-flex align-items-end"><div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_current" id="ic<?= (int)($i['id'] ?? 0) ?>v<?= $volume_id ?>" <?= !empty($i['is_current']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="ic<?= (int)($i['id'] ?? 0) ?>v<?= $volume_id ?>">Current issue</label></div></div>
    </div>
<?php } ?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/journal/index.php">Journal Management</a></li>
        <li class="breadcrumb-item active">Volumes &amp; Issues</li>
    </ol></nav>
    <?php if (jm_can_create() && $journal_id): ?>
    <button class="btn btn-primary btn-sm" style="border-radius:10px;" data-bs-toggle="modal" data-bs-target="#modal-new-volume">
        <i class="fas fa-plus me-1"></i> Add Volume</button>
    <?php endif; ?>
</div>

<?php flash_show(); ?>

<form method="get" class="mb-3" style="max-width:420px;">
    <label class="form-label small text-muted">Journal</label>
    <select class="form-select" name="journal_id" onchange="this.form.submit()">
        <?php foreach ($journals as $j): ?>
        <option value="<?= (int)$j['id'] ?>" <?= $journal_id === (int)$j['id'] ? 'selected' : '' ?>><?= h($j['name']) ?></option>
        <?php endforeach; ?>
    </select>
</form>

<?php if (!$volumes): ?>
<div class="alert alert-light border">No volumes yet for this journal.</div>
<?php endif; ?>

<?php foreach ($volumes as $v): ?>
<div class="card border-0 shadow-sm mb-3" style="border-radius:12px;">
    <div class="card-header bg-white d-flex justify-content-between align-items-center" style="border-radius:12px 12px 0 0;">
        <span class="fw-semibold">Volume <?= (int)$v['volume_number'] ?> (<?= (int)$v['year'] ?>)</span>
        <span>
            <?php if (jm_can_create()): ?>
            <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#modal-new-issue-<?= (int)$v['id'] ?>">
                <i class="fas fa-plus me-1"></i>Add Issue</button>
            <?php endif; ?>
            <?php if (jm_can_delete()): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Delete this volume and its issues?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_volume">
                <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                <input type="hidden" name="journal_id" value="<?= $journal_id ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
            </form>
            <?php endif; ?>
        </span>
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light">
                <tr><th>Issue</th><th>Title</th><th>Published Date</th><th>Articles</th><th>Status</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
                <?php if (!$v['issues']): ?>
                <tr><td colspan="6" class="text-muted text-center py-3">No issues in this volume.</td></tr>
                <?php endif; ?>
                <?php foreach ($v['issues'] as $i): ?>
                <tr>
                    <td>No. <?= (int)$i['issue_number'] ?></td>
                    <td><?= h($i['title'] ?: '—') ?></td>
                    <td><?= $i['published_date'] ? h(date('d M Y', strtotime($i['published_date']))) : '—' ?></td>
                    <td><?= (int)$i['article_count'] ?></td>
                    <td>
                        <span class="badge bg-<?= $i['is_published'] ? 'success' : 'secondary' ?>"><?= $i['is_published'] ? 'Published' : 'Draft' ?></span>
                        <?php if ($i['is_current']): ?><span class="badge bg-info text-dark">Current</span><?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if (jm_is_staff()): ?>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modal-edit-issue-<?= (int)$i['id'] ?>">
                            <i class="fas fa-pen"></i></button>
                        <?php endif; ?>
                        <?php if (jm_can_delete()): ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this issue?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_issue">
                            <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
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

<!-- Add issue modal for this volume -->
<div class="modal fade" id="modal-new-issue-<?= (int)$v['id'] ?>" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="post">
            <?= csrf_field() ?>
            <div class="modal-header"><h5 class="modal-title">Add Issue – Volume <?= (int)$v['volume_number'] ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><?php jm_issue_form($journal_id, (int)$v['id']); ?></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary">Save</button></div>
        </form>
    </div></div>
</div>

<?php foreach ($v['issues'] as $i): ?>
<div class="modal fade" id="modal-edit-issue-<?= (int)$i['id'] ?>" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="post">
            <?= csrf_field() ?>
            <div class="modal-header"><h5 class="modal-title">Edit Issue</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><?php jm_issue_form($journal_id, (int)$v['id'], $i); ?></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary">Save Changes</button></div>
        </form>
    </div></div>
</div>
<?php endforeach; ?>
<?php endforeach; ?>

<!-- New volume modal -->
<div class="modal fade" id="modal-new-volume" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_volume">
            <input type="hidden" name="journal_id" value="<?= $journal_id ?>">
            <div class="modal-header"><h5 class="modal-title">Add Volume</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-2"><label class="form-label">Volume Number *</label>
                    <input type="number" min="1" class="form-control" name="volume_number" required></div>
                <div class="mb-2"><label class="form-label">Year *</label>
                    <input type="number" min="1900" max="2100" class="form-control" name="year" value="<?= date('Y') ?>" required></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary">Save</button></div>
        </form>
    </div></div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
