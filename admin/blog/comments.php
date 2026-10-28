<?php
/**
 * Blog — comment moderation.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

auth_check();
blog_ensure_schema();
require_access('blog', 'can_view');

$page_title = 'Blog Comments';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    $return = preg_replace('/[^a-zA-Z0-9=&_%.-]/', '', (string)($_POST['return'] ?? ''));
    $back   = APP_URL . '/blog/comments.php' . ($return !== '' ? '?' . $return : '');

    if ($id > 0) {
        if ($action === 'approve' && blog_can('can_edit')) {
            db()->prepare("UPDATE blog_comments SET status = 'approved' WHERE id = ?")->execute([$id]);
            flash_set('success', 'Comment approved.');
        } elseif ($action === 'spam' && blog_can('can_edit')) {
            db()->prepare("UPDATE blog_comments SET status = 'spam' WHERE id = ?")->execute([$id]);
            flash_set('success', 'Comment marked as spam.');
        } elseif ($action === 'pending' && blog_can('can_edit')) {
            db()->prepare("UPDATE blog_comments SET status = 'pending' WHERE id = ?")->execute([$id]);
            flash_set('success', 'Comment moved back to pending.');
        } elseif ($action === 'delete' && blog_can('can_delete')) {
            db()->prepare('DELETE FROM blog_comments WHERE id = ?')->execute([$id]);
            flash_set('success', 'Comment deleted.');
        }
    }
    redirect($back);
}

$status  = in_array($_GET['status'] ?? '', ['pending', 'approved', 'spam'], true) ? $_GET['status'] : '';
$post_id = (int)($_GET['post_id'] ?? 0);

$where  = [];
$params = [];
if ($status !== '') {
    $where[]  = 'c.status = ?';
    $params[] = $status;
}
if ($post_id > 0) {
    $where[]  = 'c.post_id = ?';
    $params[] = $post_id;
}

$sql = 'SELECT c.*, p.title AS post_title, p.slug AS post_slug
        FROM blog_comments c
        JOIN blog_posts p ON p.id = c.post_id'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY c.created_at DESC LIMIT 500';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$comments = $stmt->fetchAll();

$counts = ['pending' => 0, 'approved' => 0, 'spam' => 0];
foreach (db()->query('SELECT status, COUNT(*) AS cnt FROM blog_comments GROUP BY status')->fetchAll() as $r) {
    $counts[$r['status']] = (int)$r['cnt'];
}

$return_qs = http_build_query(array_filter([
    'status'  => $status,
    'post_id' => $post_id ?: null,
]));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/blog/index.php">Blog</a></li>
            <li class="breadcrumb-item active">Comments</li>
        </ol>
    </nav>
</div>

<!-- Status tabs -->
<ul class="nav nav-pills mb-4 gap-2">
    <li class="nav-item">
        <a class="nav-link <?= $status === '' ? 'active' : '' ?>" style="border-radius:10px;"
           href="<?= APP_URL ?>/blog/comments.php">All</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $status === 'pending' ? 'active' : '' ?>" style="border-radius:10px;"
           href="<?= APP_URL ?>/blog/comments.php?status=pending">
            Pending <span class="badge bg-warning text-dark ms-1"><?= $counts['pending'] ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $status === 'approved' ? 'active' : '' ?>" style="border-radius:10px;"
           href="<?= APP_URL ?>/blog/comments.php?status=approved">
            Approved <span class="badge bg-success ms-1"><?= $counts['approved'] ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $status === 'spam' ? 'active' : '' ?>" style="border-radius:10px;"
           href="<?= APP_URL ?>/blog/comments.php?status=spam">
            Spam <span class="badge bg-danger ms-1"><?= $counts['spam'] ?></span>
        </a>
    </li>
</ul>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">Author</th>
                        <th>Comment</th>
                        <th>Post</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th style="width:180px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($comments)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No comments found.</td></tr>
                <?php else: ?>
                    <?php foreach ($comments as $c): ?>
                    <tr>
                        <td class="px-4">
                            <strong><?= h($c['author_name']) ?></strong>
                            <?php if ($c['author_email']): ?>
                            <div style="font-size:.75rem;color:#9ca3af;"><?= h($c['author_email']) ?></div>
                            <?php endif; ?>
                            <?php if ($c['ip_address']): ?>
                            <div style="font-size:.72rem;color:#b0b7c9;"><?= h($c['ip_address']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="max-width:360px;"><?= nl2br(h($c['content'])) ?></td>
                        <td>
                            <a href="<?= SITE_URL ?>/blog-detail.php?slug=<?= urlencode($c['post_slug']) ?>"
                               target="_blank" rel="noopener" style="font-size:.85rem;">
                                <?= h(mb_substr($c['post_title'], 0, 60)) ?>
                            </a>
                        </td>
                        <td>
                            <?php if ($c['status'] === 'approved'): ?>
                                <span class="badge bg-success">Approved</span>
                            <?php elseif ($c['status'] === 'spam'): ?>
                                <span class="badge bg-danger">Spam</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:.85rem;"><?= date('M d, Y H:i', strtotime($c['created_at'])) ?></td>
                        <td>
                            <div class="d-flex gap-1 flex-wrap">
                                <?php if (blog_can('can_edit') && $c['status'] !== 'approved'): ?>
                                <form method="POST">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                    <input type="hidden" name="return" value="<?= h($return_qs) ?>">
                                    <button class="btn btn-sm btn-success" title="Approve" style="border-radius:7px;">
                                        <i class="fas fa-check"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                                <?php if (blog_can('can_edit') && $c['status'] !== 'spam'): ?>
                                <form method="POST">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="spam">
                                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                    <input type="hidden" name="return" value="<?= h($return_qs) ?>">
                                    <button class="btn btn-sm btn-outline-warning" title="Mark as spam" style="border-radius:7px;">
                                        <i class="fas fa-ban"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                                <?php if (blog_can('can_edit') && $c['status'] !== 'pending'): ?>
                                <form method="POST">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="pending">
                                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                    <input type="hidden" name="return" value="<?= h($return_qs) ?>">
                                    <button class="btn btn-sm btn-outline-secondary" title="Move to pending" style="border-radius:7px;">
                                        <i class="fas fa-rotate-left"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                                <?php if (blog_can('can_delete')): ?>
                                <form method="POST" onsubmit="return confirm('Delete this comment permanently?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                    <input type="hidden" name="return" value="<?= h($return_qs) ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="Delete" style="border-radius:7px;">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
