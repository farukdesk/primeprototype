<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('journal');
require_once __DIR__ . '/helpers.php';
csrf_check();

$db = db();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        jm_require_write();
        $issue_id = (int)($_POST['issue_id'] ?? 0);
        $title    = trim($_POST['title'] ?? '');
        if ($issue_id <= 0) throw new RuntimeException('Please select an issue.');
        if ($title === '')  throw new RuntimeException('Article title is required.');

        $abstract  = trim($_POST['abstract'] ?? '');
        $keywords  = trim($_POST['keywords'] ?? '');
        $page_from = ($_POST['page_from'] ?? '') !== '' ? (int)$_POST['page_from'] : null;
        $page_to   = ($_POST['page_to'] ?? '')   !== '' ? (int)$_POST['page_to']   : null;
        $doi       = trim($_POST['doi'] ?? '');
        $publish   = ($_POST['submit_action'] ?? '') === 'publish';
        $pub_date  = trim($_POST['published_date'] ?? '') ?: null;

        $pdf = null;
        if (!empty($_FILES['pdf_file']['name'])) {
            $pdf = jm_upload_pdf($_FILES['pdf_file']);
        }

        if ($id > 0) {
            $sql = 'UPDATE journal_articles SET issue_id=?, title=?, abstract=?, keywords=?, page_from=?, page_to=?, doi=?, published_date=?'
                 . ($pdf ? ', pdf_file=?' : '')
                 . ($publish ? ", status='published'" : '')
                 . ' WHERE id=?';
            $params = [$issue_id, $title, $abstract, $keywords, $page_from, $page_to, $doi, $pub_date];
            if ($pdf) $params[] = $pdf;
            $params[] = $id;
            $db->prepare($sql)->execute($params);
            if ($publish) {
                $db->prepare('UPDATE journal_articles SET published_date = COALESCE(published_date, CURDATE()) WHERE id=?')->execute([$id]);
            }
        } else {
            $slug = jm_unique_slug('journal_articles', $title);
            $db->prepare(
                'INSERT INTO journal_articles
                 (issue_id, title, slug, abstract, keywords, page_from, page_to, doi, pdf_file, status, published_date)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $issue_id, $title, $slug, $abstract, $keywords, $page_from, $page_to, $doi, $pdf,
                $publish ? 'published' : 'draft',
                $publish ? ($pub_date ?: date('Y-m-d')) : $pub_date,
            ]);
            $id = (int)$db->lastInsertId();
        }

        // Sync authors (checkbox ids + per-author order values).
        $db->prepare('DELETE FROM journal_article_authors WHERE article_id = ?')->execute([$id]);
        $author_ids = array_map('intval', (array)($_POST['author_ids'] ?? []));
        $orders     = (array)($_POST['author_order'] ?? []);
        $ins = $db->prepare('INSERT INTO journal_article_authors (article_id, author_id, author_order) VALUES (?,?,?)');
        foreach ($author_ids as $aid) {
            if ($aid > 0) $ins->execute([$id, $aid, max(1, (int)($orders[$aid] ?? 1))]);
        }

        flash_set('success', $publish ? 'Article saved and published.' : 'Article saved.');
        redirect(APP_URL . '/journal/article-form.php?id=' . $id);
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        redirect(APP_URL . '/journal/article-form.php' . ($id ? '?id=' . $id : ''));
    }
}

// ── Load data ────────────────────────────────────────────────────────────────────
$article = null;
$selected_authors = []; // author_id => order
if ($id > 0) {
    $stmt = $db->prepare('SELECT * FROM journal_articles WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $article = $stmt->fetch();
    if (!$article) { flash_set('error', 'Article not found.'); redirect(APP_URL . '/journal/articles.php'); }
    $stmt = $db->prepare('SELECT author_id, author_order FROM journal_article_authors WHERE article_id = ?');
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll() as $r) $selected_authors[(int)$r['author_id']] = (int)$r['author_order'];
}

$issues = $db->query(
    'SELECT i.id, i.issue_number, i.is_published, v.volume_number, v.year, j.name AS journal_title
     FROM journal_issues i
     JOIN journal_volumes  v ON v.id = i.volume_id
     JOIN journal_journals j ON j.id = v.journal_id
     ORDER BY j.name ASC, v.volume_number DESC, i.issue_number DESC'
)->fetchAll();

$all_authors = $db->query('SELECT id, full_name, affiliation FROM journal_authors ORDER BY full_name ASC')->fetchAll();

$page_title = $article ? 'Edit Article' : 'New Article';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/journal/index.php">Journal Management</a></li>
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/journal/articles.php">Articles</a></li>
        <li class="breadcrumb-item active"><?= h($page_title) ?></li>
    </ol></nav>
    <?php if ($article && $article['status'] === 'published'): ?>
    <a class="btn btn-outline-secondary btn-sm" target="_blank" href="<?= h(jm_article_url($article['slug'])) ?>">
        <i class="fas fa-external-link-alt me-1"></i> View Public Page</a>
    <?php endif; ?>
</div>

<?php flash_show(); ?>

<?php if ($article && $article['status'] === 'published'): ?>
<div class="alert alert-info">
    <div><strong>Permanent article URL:</strong>
        <a href="<?= h(jm_article_url($article['slug'])) ?>" target="_blank"><?= h(jm_article_url($article['slug'])) ?></a></div>
    <?php if ($article['pdf_file']): ?>
    <div><strong>PDF URL (Google Scholar):</strong>
        <a href="<?= h(jm_pdf_url($article['slug'])) ?>" target="_blank"><?= h(jm_pdf_url($article['slug'])) ?></a></div>
    <?php else: ?>
    <div class="text-danger mt-1"><i class="fas fa-triangle-exclamation me-1"></i>
        No PDF uploaded yet – Google Scholar indexing works best with a full-text PDF.</div>
    <?php endif; ?>
</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-3" style="border-radius:12px;">
                <div class="card-body">
                    <div class="mb-3"><label class="form-label">Title *</label>
                        <input class="form-control" name="title" required value="<?= h($article['title'] ?? '') ?>"></div>
                    <div class="mb-3"><label class="form-label">Abstract</label>
                        <textarea class="form-control" rows="8" name="abstract"><?= h($article['abstract'] ?? '') ?></textarea></div>
                    <div class="mb-3"><label class="form-label">Keywords</label>
                        <input class="form-control" name="keywords" value="<?= h($article['keywords'] ?? '') ?>"
                               placeholder="Comma-separated, e.g. machine learning, education"></div>
                    <div class="row g-2">
                        <div class="col-md-3 mb-2"><label class="form-label">Page From</label>
                            <input type="number" min="1" class="form-control" name="page_from" value="<?= h($article['page_from'] ?? '') ?>"></div>
                        <div class="col-md-3 mb-2"><label class="form-label">Page To</label>
                            <input type="number" min="1" class="form-control" name="page_to" value="<?= h($article['page_to'] ?? '') ?>"></div>
                        <div class="col-md-6 mb-2"><label class="form-label">DOI (optional)</label>
                            <input class="form-control" name="doi" value="<?= h($article['doi'] ?? '') ?>" placeholder="10.xxxx/xxxxx"></div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3" style="border-radius:12px;">
                <div class="card-header bg-white fw-semibold" style="border-radius:12px 12px 0 0;">
                    Authors
                    <a class="small ms-2" href="<?= APP_URL ?>/journal/authors.php" target="_blank">Manage authors</a>
                </div>
                <div class="card-body" style="max-height:340px; overflow-y:auto;">
                    <?php if (!$all_authors): ?>
                    <div class="text-muted">No authors exist yet. Add authors first, then assign them here.</div>
                    <?php endif; ?>
                    <?php foreach ($all_authors as $au):
                        $aid = (int)$au['id'];
                        $checked = isset($selected_authors[$aid]); ?>
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <input class="form-check-input" type="checkbox" name="author_ids[]" value="<?= $aid ?>"
                               id="au<?= $aid ?>" <?= $checked ? 'checked' : '' ?>>
                        <input type="number" min="1" class="form-control form-control-sm" style="width:70px;"
                               name="author_order[<?= $aid ?>]" value="<?= $checked ? (int)$selected_authors[$aid] : 1 ?>" title="Author order">
                        <label class="form-check-label" for="au<?= $aid ?>">
                            <?= h($au['full_name']) ?>
                            <?php if ($au['affiliation']): ?><span class="text-muted small">– <?= h($au['affiliation']) ?></span><?php endif; ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3" style="border-radius:12px;">
                <div class="card-header bg-white fw-semibold" style="border-radius:12px 12px 0 0;">Publication</div>
                <div class="card-body">
                    <div class="mb-3"><label class="form-label">Issue *</label>
                        <select class="form-select" name="issue_id" required>
                            <option value="">– Select –</option>
                            <?php
                            $group = '';
                            foreach ($issues as $i):
                                if ($group !== $i['journal_title']) {
                                    if ($group !== '') echo '</optgroup>';
                                    $group = $i['journal_title'];
                                    echo '<optgroup label="' . h($group) . '">';
                                } ?>
                            <option value="<?= (int)$i['id'] ?>" <?= (int)($article['issue_id'] ?? 0) === (int)$i['id'] ? 'selected' : '' ?>>
                                Vol. <?= (int)$i['volume_number'] ?>, No. <?= (int)$i['issue_number'] ?> (<?= (int)$i['year'] ?>)<?= $i['is_published'] ? '' : ' [draft]' ?>
                            </option>
                            <?php endforeach;
                            if ($group !== '') echo '</optgroup>'; ?>
                        </select></div>
                    <div class="mb-3"><label class="form-label">Published Date</label>
                        <input type="date" class="form-control" name="published_date" value="<?= h($article['published_date'] ?? '') ?>">
                        <div class="form-text">Used in Google Scholar metadata. Defaults to today when publishing.</div></div>
                    <div class="mb-3"><label class="form-label">Article PDF</label>
                        <input type="file" class="form-control" name="pdf_file" accept="application/pdf">
                        <?php if (!empty($article['pdf_file'])): ?>
                        <div class="form-text">Current: <?= h($article['pdf_file']) ?> (uploading replaces it)</div>
                        <?php endif; ?></div>
                    <div class="mb-2">
                        Status:
                        <span class="badge bg-<?= ($article['status'] ?? 'draft') === 'published' ? 'success' : 'secondary' ?>">
                            <?= h(ucfirst($article['status'] ?? 'draft')) ?></span>
                    </div>
                    <div class="d-grid gap-2">
                        <button class="btn btn-primary" name="submit_action" value="save">
                            <i class="fas fa-save me-1"></i> Save</button>
                        <?php if (($article['status'] ?? 'draft') !== 'published'): ?>
                        <button class="btn btn-success" name="submit_action" value="publish"
                                onclick="return confirm('Publish this article? It becomes publicly visible.');">
                            <i class="fas fa-globe me-1"></i> Save &amp; Publish</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
