<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$id     = (int)($_GET['id'] ?? 0);
$notice = null;

if ($id) {
    $stmt = db()->prepare('SELECT * FROM cms_notices WHERE id = ?');
    $stmt->execute([$id]);
    $notice = $stmt->fetch();
}
if (!$notice) {
    flash_set('error', 'Notice not found.');
    redirect(APP_URL . '/cms/notice-board/index.php');
}

$page_title = 'View Notice';

// Formatted date
$pub_date = '';
if (!empty($notice['published_at'])) {
    $pub_date = date('d F, Y  H:i', strtotime($notice['published_at']));
} elseif (!empty($notice['created_at'])) {
    $pub_date = date('d F, Y  H:i', strtotime($notice['created_at']));
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/cms/notice-board/index.php">Notice Board</a></li>
            <li class="breadcrumb-item active">View Notice</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/cms/notice-board/edit.php?id=<?= $notice['id'] ?>"
       class="btn btn-primary" style="border-radius:10px;font-size:.875rem;">
        <i class="fas fa-edit me-1"></i> Edit
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-lg-9">
        <div class="card">
            <div class="card-body p-4 p-lg-5">

                <!-- Status badges -->
                <div class="d-flex gap-2 flex-wrap mb-3">
                    <?= $notice['is_published']
                        ? '<span class="badge bg-success">Published</span>'
                        : '<span class="badge bg-warning text-dark">Draft</span>' ?>
                    <?php if ($notice['publish_as_news']): ?>
                        <span class="badge bg-info text-dark">
                            <i class="fas fa-newspaper me-1"></i>Also in News
                        </span>
                    <?php endif; ?>
                </div>

                <!-- Title -->
                <h3 class="fw-bold mb-3"><?= h($notice['title']) ?></h3>

                <!-- Meta -->
                <div class="d-flex flex-wrap gap-3 text-muted mb-4" style="font-size:.875rem;">
                    <?php if ($pub_date): ?>
                    <span><i class="fas fa-calendar-days me-1"></i><?= h($pub_date) ?></span>
                    <?php endif; ?>
                    <span><i class="fas fa-link me-1"></i>
                        <code style="font-size:.8rem;"><?= h($notice['slug']) ?></code>
                    </span>
                    <span><i class="fas fa-file-code me-1"></i><?= strtoupper(h($notice['content_type'])) ?></span>
                </div>

                <hr class="mb-4">

                <!-- Content -->
                <div class="notice-content">
                    <?php if ($notice['content_type'] === 'html'): ?>
                        <?= $notice['content'] ?>
                    <?php else: ?>
                        <?= nl2br(h($notice['content'])) ?>
                    <?php endif; ?>
                </div>

                <!-- Attachment -->
                <?php if ($notice['attachment']): ?>
                <div class="mt-4 pt-3" style="border-top:1px solid #e5e7eb;">
                    <h6 class="fw-semibold mb-2"><i class="fas fa-paperclip me-2 text-muted"></i>Attachment</h6>
                    <a href="<?= UPLOAD_URL ?>/notices/<?= h($notice['attachment']) ?>"
                       target="_blank" rel="noopener"
                       class="btn btn-outline-secondary btn-sm" style="border-radius:8px;">
                        <i class="fas fa-download me-2"></i><?= h($notice['attachment_original_name']) ?>
                        <?php if ($notice['attachment_size']): ?>
                        <span class="text-muted ms-1" style="font-size:.8rem;">
                            (<?= number_format($notice['attachment_size'] / 1024, 1) ?> KB)
                        </span>
                        <?php endif; ?>
                    </a>
                </div>
                <?php endif; ?>

                <!-- Footer actions -->
                <div class="mt-5 pt-3 d-flex gap-2" style="border-top:1px solid #e5e7eb;">
                    <a href="<?= APP_URL ?>/cms/notice-board/edit.php?id=<?= $notice['id'] ?>"
                       class="btn btn-primary btn-sm" style="border-radius:8px;">
                        <i class="fas fa-edit me-1"></i> Edit Notice
                    </a>
                    <a href="<?= APP_URL ?>/cms/notice-board/index.php"
                       class="btn btn-light btn-sm" style="border-radius:8px;">
                        ← Back to List
                    </a>
                </div>

            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
