<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('journal');
require_once __DIR__ . '/helpers.php';
csrf_check();

$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    try {
        if ($action === 'publish') {
            jm_require_write();
            $db->prepare(
                "UPDATE journal_articles
                 SET status = 'published', published_date = COALESCE(published_date, CURDATE())
                 WHERE id = ?"
            )->execute([$id]);
            flash_set('success', 'Article published.');
        } elseif ($action === 'unpublish') {
            jm_require_write();
            $db->prepare("UPDATE journal_articles SET status = 'draft' WHERE id = ?")->execute([$id]);
            flash_set('success', 'Article moved back to draft.');
        } elseif ($action === 'delete') {
            if (!jm_can_delete()) throw new RuntimeException('No permission to delete.');
            $db->prepare('DELETE FROM journal_articles WHERE id = ?')->execute([$id]);
            flash_set('success', 'Article deleted.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect(APP_URL . '/journal/articles.php');
}

$journals   = jm_journals();
$journal_id = (int)($_GET['journal_id'] ?? 0);
$status     = in_array($_GET['status'] ?? '', ['draft', 'published'], true) ? $_GET['status'] : '';
$q          = trim($_GET['q'] ?? '');

$where  = [];
$params = [];
if ($journal_id > 0) { $where[] = 'j.id = ?';       $params[] = $journal_id; }
if ($status !== '')  { $where[] = 'a.status = ?';   $params[] = $status; }
if ($q !== '')       { $where[] = '(a.title LIKE ? OR a.keywords LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }

$sql = "SELECT a.*, i.issue_number, v.volume_number, v.year, j.title AS journal_title,
               (SELECT GROUP_CONCAT(au.full_name ORDER BY aa.author_order SEPARATOR ', ')
                FROM journal_article_authors aa JOIN journal_authors au ON au.id = aa.author_id
                WHERE aa.article_id = a.id) AS author_names
        FROM journal_articles a
        JOIN journal_issues   i ON i.id = a.issue_id
        JOIN journal_volumes  v ON v.id = i.volume_id
        JOIN journal_journals j ON j.id = v.journal_id"
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY a.id DESC LIMIT 300';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$articles = $stmt->fetchAll();

$page_title = 'Articles';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/journal/index.php">Journal Management</a></li>
        <li class="breadcrumb-item active">Articles</li>
    </ol></nav>
    <?php if (jm_can_create()): ?>
    <a href="<?= APP_URL ?>/journal/article-form.php" class="btn btn-primary btn-sm" style="border-radius:10px;">
        <i class="fas fa-plus me-1"></i> New Article</a>
    <?php endif; ?>
</div>

<?php flash_show(); ?>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-4">
        <select class="form-select" name="journal_id" onchange="this.form.submit()">
            <option value="0">All journals</option>
            <?php foreach ($journals as $j): ?>
            <option value="<?= (int)$j['id'] ?>" <?= $journal_id === (int)$j['id'] ? 'selected' : '' ?>><?= h($j['title']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <select class="form-select" name="status" onchange="this.form.submit()">
            <option value="">All statuses</option>
            <option value="draft"     <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option>
            <option value="published" <?= $status === 'published' ? 'selected' : '' ?>>Published</option>
        </select>
    </div>
    <div class="col-md-5 d-flex gap-2">
        <input class="form-control" name="q" placeholder="Search title or keywords…" value="<?= h($q) ?>">
        <button class="btn btn-outline-primary"><i class="fas fa-search"></i></button>
    </div>
</form>

<div class="card border-0 shadow-sm" style="border-radius:12px;">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Title / Authors</th><th>Journal</th><th>Vol / Issue</th><th>PDF</th><th>Status</th><th>Views / DL</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
                <?php if (!$articles): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No articles found.</td></tr>
                <?php endif; ?>
                <?php foreach ($articles as $a): ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= h(mb_strimwidth($a['title'], 0, 80, '…')) ?></div>
                        <div class="small text-muted"><?= h($a['author_names'] ?: 'No authors assigned') ?></div>
                    </td>
                    <td><?= h($a['journal_title']) ?></td>
                    <td>Vol. <?= (int)$a['volume_number'] ?>, No. <?= (int)$a['issue_number'] ?> (<?= (int)$a['year'] ?>)</td>
                    <td><?= $a['pdf_file'] ? '<i class="fas fa-file-pdf text-danger"></i>' : '<span class="text-muted">—</span>' ?></td>
                    <td><span class="badge bg-<?= $a['status'] === 'published' ? 'success' : 'secondary' ?>"><?= h(ucfirst($a['status'])) ?></span></td>
                    <td class="small"><?= (int)$a['views'] ?> / <?= (int)$a['downloads'] ?></td>
                    <td class="text-end" style="min-width:190px;">
                        <a class="btn btn-sm btn-outline-primary" href="<?= APP_URL ?>/journal/article-form.php?id=<?= (int)$a['id'] ?>" title="Edit">
                            <i class="fas fa-pen"></i></a>
                        <?php if ($a['status'] === 'published'): ?>
                        <a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?= h(jm_article_url($a['slug'])) ?>" title="Public page">
                            <i class="fas fa-external-link-alt"></i></a>
                        <?php endif; ?>
                        <?php if (jm_is_staff()): ?>
                        <form method="post" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="<?= $a['status'] === 'published' ? 'unpublish' : 'publish' ?>">
                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                            <button class="btn btn-sm btn-outline-<?= $a['status'] === 'published' ? 'warning' : 'success' ?>"
                                    title="<?= $a['status'] === 'published' ? 'Unpublish' : 'Publish' ?>">
                                <i class="fas fa-<?= $a['status'] === 'published' ? 'eye-slash' : 'globe' ?>"></i></button>
                        </form>
                        <?php endif; ?>
                        <?php if (jm_can_delete()): ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this article?');">
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
