<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { flash_set('error', 'Article not found.'); redirect(APP_URL . '/knowledge-base/index.php'); }

$stmt = db()->prepare(
    'SELECT a.*, u.full_name AS author_name FROM kb_articles a JOIN users u ON u.id = a.created_by WHERE a.id = ?'
);
$stmt->execute([$id]);
$article = $stmt->fetch();

if (!$article) {
    flash_set('error', 'Article not found.');
    redirect(APP_URL . '/knowledge-base/index.php');
}

$page_title = h($article['title']);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/knowledge-base/index.php">Knowledge Base</a></li>
            <li class="breadcrumb-item active"><?= h($article['title']) ?></li>
        </ol>
    </nav>
    <?php if (is_super_admin()): ?>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/knowledge-base/edit.php?id=<?= $article['id'] ?>"
           class="btn btn-outline-secondary" style="border-radius:10px;font-size:.875rem;">
            <i class="fas fa-edit me-1"></i> Edit
        </a>
        <form method="POST" action="<?= APP_URL ?>/knowledge-base/delete.php" class="d-inline"
              onsubmit="return confirm('Permanently delete this article?');">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $article['id'] ?>">
            <button class="btn btn-outline-danger" style="border-radius:10px;font-size:.875rem;">
                <i class="fas fa-trash me-1"></i> Delete
            </button>
        </form>
    </div>
    <?php endif; ?>
</div>

<div class="row g-4">

    <!-- ── Main content ─────────────────────────────────────────────────── -->
    <div class="col-lg-8">

        <!-- Type badge + title -->
        <div class="mb-3">
            <?php if ($article['type'] === 'video'): ?>
            <span class="badge mb-2" style="background:#fff0f0;color:#dc3545;font-size:.8rem;">
                <i class="fas fa-play me-1"></i>Video Tutorial
            </span>
            <?php else: ?>
            <span class="badge mb-2" style="background:#f0f4ff;color:#4f8ef7;font-size:.8rem;">
                <i class="fas fa-file-alt me-1"></i>Document / Guide
            </span>
            <?php endif; ?>
            <h2 class="fw-bold mb-1" style="font-size:1.5rem;"><?= h($article['title']) ?></h2>
            <?php if ($article['description']): ?>
            <p class="text-muted mb-0" style="font-size:.9rem;"><?= h($article['description']) ?></p>
            <?php endif; ?>
        </div>

        <?php if ($article['type'] === 'video'): ?>

        <!-- Video embed -->
        <?php $embed_url = kb_embed_url($article['video_url']); ?>
        <div class="card mb-4" style="border-radius:12px;overflow:hidden;">
            <div style="position:relative;padding-bottom:56.25%;height:0;background:#000;">
                <iframe src="<?= h($embed_url) ?>"
                        style="position:absolute;top:0;left:0;width:100%;height:100%;border:0;"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                        allowfullscreen loading="lazy" title="<?= h($article['title']) ?>">
                </iframe>
            </div>
        </div>

        <?php else: ?>

        <!-- Thumbnail -->
        <?php if ($article['thumbnail']): ?>
        <div class="mb-4">
            <img src="<?= UPLOAD_URL ?>/<?= KB_UPLOAD_SUBDIR ?>/<?= h($article['thumbnail']) ?>"
                 class="img-fluid rounded" style="border-radius:12px;max-height:340px;width:100%;object-fit:cover;"
                 alt="<?= h($article['title']) ?>">
        </div>
        <?php endif; ?>

        <!-- Rich-text content -->
        <?php if ($article['content']): ?>
        <div class="card mb-4" style="border-radius:12px;">
            <div class="card-body p-4">
                <div class="kb-content" style="line-height:1.7;font-size:.95rem;">
                    <?= $article['content'] ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Document attachment -->
        <?php if ($article['file_name']): ?>
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-paperclip me-2 text-muted"></i>Attachment</h6>
            </div>
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-3">
                    <i class="<?= kb_mime_icon($article['file_mime'] ?? '') ?> fa-2x"></i>
                    <div class="flex-grow-1">
                        <div class="fw-medium"><?= h($article['file_original']) ?></div>
                        <div class="text-muted" style="font-size:.8rem;"><?= kb_format_size((int)$article['file_size']) ?></div>
                    </div>
                    <a href="<?= UPLOAD_URL ?>/<?= KB_UPLOAD_SUBDIR ?>/<?= h($article['file_name']) ?>"
                       class="btn btn-outline-primary" style="border-radius:10px;" target="_blank" download="<?= h($article['file_original']) ?>">
                        <i class="fas fa-download me-1"></i> Download
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; // end document ?>

    </div>

    <!-- ── Sidebar ───────────────────────────────────────────────────────── -->
    <div class="col-lg-4">

        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-info-circle me-2 text-muted"></i>Details</h6>
            </div>
            <div class="card-body p-4">
                <dl class="mb-0" style="font-size:.875rem;">
                    <dt class="text-muted fw-normal mb-1">Author</dt>
                    <dd class="mb-3 fw-medium"><?= h($article['author_name']) ?></dd>

                    <dt class="text-muted fw-normal mb-1">Published</dt>
                    <dd class="mb-3 fw-medium"><?= date('M d, Y', strtotime($article['created_at'])) ?></dd>

                    <?php if ($article['updated_at'] !== $article['created_at']): ?>
                    <dt class="text-muted fw-normal mb-1">Last Updated</dt>
                    <dd class="mb-3 fw-medium"><?= date('M d, Y', strtotime($article['updated_at'])) ?></dd>
                    <?php endif; ?>

                    <dt class="text-muted fw-normal mb-1">Type</dt>
                    <dd class="mb-0">
                        <?php if ($article['type'] === 'video'): ?>
                        <span class="badge" style="background:#fff0f0;color:#dc3545;">Video Tutorial</span>
                        <?php else: ?>
                        <span class="badge" style="background:#f0f4ff;color:#4f8ef7;">Document / Guide</span>
                        <?php endif; ?>
                    </dd>
                </dl>
            </div>
        </div>

        <div class="mt-3">
            <a href="<?= APP_URL ?>/knowledge-base/index.php" class="btn btn-light w-100" style="border-radius:10px;">
                <i class="fas fa-arrow-left me-1"></i> Back to Knowledge Base
            </a>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
