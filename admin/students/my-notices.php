<?php
/**
 * Student Portal – My Notices
 * Shows University-wide and Department notices for the logged-in student.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';

if (!is_portal_student()) {
    flash_set('error', 'You do not have permission to access this section.');
    redirect(APP_URL . '/index.php');
}

$user = auth_user();

// ── Identify the student record ───────────────────────────────────────────────
$student = null;
try {
    $stmt = db()->prepare(
        'SELECT s.id, s.dept_id, s.full_name, d.name AS dept_name
         FROM students s
         JOIN dept_departments d ON d.id = s.dept_id
         WHERE s.portal_user_id = ?
         LIMIT 1'
    );
    $stmt->execute([$user['id']]);
    $student = $stmt->fetch() ?: null;
} catch (Throwable $e) {}

if (!$student) {
    flash_set('error', 'No student profile is linked to your account. Please contact the administrator.');
    redirect(APP_URL . '/index.php');
}

// ── Active tab (university|department) ───────────────────────────────────────
$active_tab = in_array($_GET['tab'] ?? '', ['department'], true) ? 'department' : 'university';

// ── Pagination settings ───────────────────────────────────────────────────────
$per_page = 5;
$page     = max(1, (int)($_GET['page'] ?? 1));

// ── University Notices – total count (for badge) ─────────────────────────────
$university_total = 0;
try {
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM cms_notices WHERE is_published = 1 AND is_approved = 1'
    );
    $stmt->execute();
    $university_total = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}

// ── Department Notices – total count (for badge) ─────────────────────────────
$dept_total = 0;
try {
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM dept_notices WHERE dept_id = ? AND is_active = 1'
    );
    $stmt->execute([$student['dept_id']]);
    $dept_total = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}

// ── Clamp page to valid range for active tab ─────────────────────────────────
$active_total = $active_tab === 'university' ? $university_total : $dept_total;
$total_pages  = max(1, (int)ceil($active_total / $per_page));
$page         = min($page, $total_pages);
$offset       = ($page - 1) * $per_page;

// ── Helper: build page-number list with ellipsis ──────────────────────────────
function pagination_pages(int $current, int $total): array {
    if ($total <= 7) {
        return range(1, $total);
    }
    $window = 2; // pages on each side of current
    $pages  = [];
    $pages[] = 1;
    if ($current - $window > 2) {
        $pages[] = '…';
    }
    for ($i = max(2, $current - $window); $i <= min($total - 1, $current + $window); $i++) {
        $pages[] = $i;
    }
    if ($current + $window < $total - 1) {
        $pages[] = '…';
    }
    $pages[] = $total;
    return $pages;
}

// ── University Notices (published & approved, paginated) ─────────────────────
$university_notices = [];
if ($active_tab === 'university') {
    try {
        $stmt = db()->prepare(
            'SELECT id, title, content, content_type, attachment, attachment_original_name,
                    attachment_size, published_at, created_at
             FROM cms_notices
             WHERE is_published = 1 AND is_approved = 1
             ORDER BY published_at DESC, id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit',  $per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,   PDO::PARAM_INT);
        $stmt->execute();
        $university_notices = $stmt->fetchAll();
    } catch (Throwable $e) {}
}

// ── Department Notices (active, for student's department, paginated) ──────────
$dept_notices = [];
if ($active_tab === 'department') {
    try {
        $stmt = db()->prepare(
            'SELECT id, title, content, attachment, notice_date, created_at
             FROM dept_notices
             WHERE dept_id = :dept_id AND is_active = 1
             ORDER BY notice_date DESC, id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':dept_id', $student['dept_id'], PDO::PARAM_INT);
        $stmt->bindValue(':limit',   $per_page,           PDO::PARAM_INT);
        $stmt->bindValue(':offset',  $offset,             PDO::PARAM_INT);
        $stmt->execute();
        $dept_notices = $stmt->fetchAll();
    } catch (Throwable $e) {}
}

$page_title = 'My Notices';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold">
            <i class="fas fa-bell me-2 text-primary"></i>My Notices
        </h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Home</a></li>
            <li class="breadcrumb-item active">My Notices</li>
        </ol></nav>
    </div>
</div>

<?= flash_show() ?>

<!-- Tab Navigation -->
<ul class="nav nav-tabs mb-4" role="tablist" style="border-bottom:2px solid #e5e7eb;">
    <li class="nav-item" role="presentation">
        <a href="?tab=university"
           class="nav-link fw-medium <?= $active_tab === 'university' ? 'active' : '' ?>"
           style="border-radius:10px 10px 0 0; <?= $active_tab === 'university' ? 'background:#4f8ef7;color:#fff;border-color:#4f8ef7;' : '' ?>">
            <i class="fas fa-university me-2"></i>University Notice
            <span class="badge ms-1 <?= $active_tab === 'university' ? 'bg-white text-primary' : 'bg-primary' ?>">
                <?= $university_total ?>
            </span>
        </a>
    </li>
    <li class="nav-item" role="presentation">
        <a href="?tab=department"
           class="nav-link fw-medium <?= $active_tab === 'department' ? 'active' : '' ?>"
           style="border-radius:10px 10px 0 0; <?= $active_tab === 'department' ? 'background:#4f8ef7;color:#fff;border-color:#4f8ef7;' : '' ?>">
            <i class="fas fa-building me-2"></i><?= h($student['dept_name']) ?> Notice
            <span class="badge ms-1 <?= $active_tab === 'department' ? 'bg-white text-primary' : 'bg-primary' ?>">
                <?= $dept_total ?>
            </span>
        </a>
    </li>
</ul>

<?php if ($active_tab === 'university'): ?>
<!-- ── University Notices ──────────────────────────────────────────────────── -->
<?php if (empty($university_notices)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <div class="mb-3" style="font-size:2.5rem;opacity:.35;"><i class="fas fa-bell-slash"></i></div>
            <h6 class="text-muted fw-normal">No university notices at this time.</h6>
        </div>
    </div>
<?php else: ?>
    <div class="d-flex flex-column gap-3">
    <?php foreach ($university_notices as $n): ?>
        <div class="card border-0 shadow-sm notice-card" style="border-left:4px solid #4f8ef7 !important;">
            <div class="card-body px-4 py-3">
                <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                            <span class="badge bg-primary bg-opacity-10 text-primary"
                                  style="font-size:.72rem;"><i class="fas fa-university me-1"></i>University</span>
                            <span class="text-muted" style="font-size:.78rem;">
                                <i class="fas fa-calendar-days me-1"></i>
                                <?= $n['published_at']
                                    ? date('d M, Y', strtotime($n['published_at']))
                                    : date('d M, Y', strtotime($n['created_at'])) ?>
                            </span>
                        </div>
                        <h6 class="mb-1 fw-bold" style="font-size:1rem;"><?= h($n['title']) ?></h6>
                        <?php if ($n['content']): ?>
                        <p class="text-muted mb-0" style="font-size:.875rem;line-height:1.6;">
                            <?= h(mb_strimwidth(strip_tags($n['content']), 0, 200, '…')) ?>
                        </p>
                        <?php endif; ?>
                    </div>
                    <?php if ($n['attachment']): ?>
                    <div class="flex-shrink-0">
                        <a href="<?= UPLOAD_URL ?>/notices/<?= rawurlencode($n['attachment']) ?>"
                           target="_blank" rel="noopener"
                           class="btn btn-sm btn-outline-primary d-flex align-items-center gap-1"
                           style="border-radius:8px;white-space:nowrap;">
                            <i class="fas fa-paperclip"></i>
                            <span><?= h($n['attachment_original_name'] ?: 'Attachment') ?></span>
                            <?php if ($n['attachment_size']): ?>
                            <span class="text-muted" style="font-size:.75rem;">
                                (<?= number_format($n['attachment_size'] / 1024, 1) ?> KB)
                            </span>
                            <?php endif; ?>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Full content expand -->
                <?php if ($n['content'] && strlen(strip_tags($n['content'])) > 200): ?>
                <div class="mt-2">
                    <button class="btn btn-link btn-sm p-0 text-primary" style="font-size:.8rem;"
                            onclick="toggleNotice(this, 'uni-<?= $n['id'] ?>')">
                        <i class="fas fa-chevron-down me-1"></i>Read full notice
                    </button>
                    <div id="uni-<?= $n['id'] ?>" class="mt-2 pt-2" style="display:none;border-top:1px solid #e5e7eb;">
                        <?php if ($n['content_type'] === 'html'): ?>
                            <div style="font-size:.9rem;"><?= $n['content'] ?></div>
                        <?php else: ?>
                            <div style="font-size:.9rem;"><?= nl2br(h($n['content'])) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
// ── University Notices Pagination ─────────────────────────────────────────────
if ($active_tab === 'university' && $total_pages > 1):
    $base = '?tab=university&page=';
?>
<nav aria-label="University notices pagination" class="mt-4">
    <ul class="pagination pagination-sm justify-content-center flex-wrap gap-1 mb-0">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link rounded" href="<?= h($base . ($page - 1)) ?>" aria-label="Previous">
                <i class="fas fa-chevron-left"></i>
            </a>
        </li>
        <?php foreach (pagination_pages($page, $total_pages) as $p): ?>
            <?php if ($p === '…'): ?>
            <li class="page-item disabled"><span class="page-link">…</span></li>
            <?php else: ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                <a class="page-link rounded" href="<?= h($base . $p) ?>"><?= $p ?></a>
            </li>
            <?php endif; ?>
        <?php endforeach; ?>
        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
            <a class="page-link rounded" href="<?= h($base . ($page + 1)) ?>" aria-label="Next">
                <i class="fas fa-chevron-right"></i>
            </a>
        </li>
    </ul>
    <p class="text-center text-muted small mt-2 mb-0">
        Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $university_total) ?> of <?= $university_total ?> notices
    </p>
</nav>
<?php endif; ?>

<?php else: ?>
<!-- ── Department Notices ─────────────────────────────────────────────────── -->
<?php if (empty($dept_notices)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <div class="mb-3" style="font-size:2.5rem;opacity:.35;"><i class="fas fa-bell-slash"></i></div>
            <h6 class="text-muted fw-normal">No notices from <?= h($student['dept_name']) ?> at this time.</h6>
        </div>
    </div>
<?php else: ?>
    <div class="d-flex flex-column gap-3">
    <?php foreach ($dept_notices as $n): ?>
        <div class="card border-0 shadow-sm notice-card" style="border-left:4px solid #10b981 !important;">
            <div class="card-body px-4 py-3">
                <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                            <span class="badge bg-success bg-opacity-10 text-success"
                                  style="font-size:.72rem;"><i class="fas fa-building me-1"></i>Department</span>
                            <span class="text-muted" style="font-size:.78rem;">
                                <i class="fas fa-calendar-days me-1"></i>
                                <?= $n['notice_date']
                                    ? date('d M, Y', strtotime($n['notice_date']))
                                    : date('d M, Y', strtotime($n['created_at'])) ?>
                            </span>
                        </div>
                        <h6 class="mb-1 fw-bold" style="font-size:1rem;"><?= h($n['title']) ?></h6>
                        <?php if ($n['content']): ?>
                        <p class="text-muted mb-0" style="font-size:.875rem;line-height:1.6;">
                            <?= h(mb_strimwidth(strip_tags($n['content']), 0, 200, '…')) ?>
                        </p>
                        <?php endif; ?>
                    </div>
                    <?php if ($n['attachment']): ?>
                    <div class="flex-shrink-0">
                        <a href="<?= UPLOAD_URL ?>/departments/<?= rawurlencode($n['attachment']) ?>"
                           target="_blank" rel="noopener"
                           class="btn btn-sm btn-outline-success d-flex align-items-center gap-1"
                           style="border-radius:8px;white-space:nowrap;">
                            <i class="fas fa-paperclip"></i>
                            <span>Attachment</span>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Full content expand -->
                <?php if ($n['content'] && strlen(strip_tags($n['content'])) > 200): ?>
                <div class="mt-2">
                    <button class="btn btn-link btn-sm p-0 text-success" style="font-size:.8rem;"
                            onclick="toggleNotice(this, 'dept-<?= $n['id'] ?>')">
                        <i class="fas fa-chevron-down me-1"></i>Read full notice
                    </button>
                    <div id="dept-<?= $n['id'] ?>" class="mt-2 pt-2" style="display:none;border-top:1px solid #e5e7eb;">
                        <div style="font-size:.9rem;"><?= nl2br(h($n['content'])) ?></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
// ── Department Notices Pagination ─────────────────────────────────────────────
if ($active_tab === 'department' && $total_pages > 1):
    $base = '?tab=department&page=';
?>
<nav aria-label="Department notices pagination" class="mt-4">
    <ul class="pagination pagination-sm justify-content-center flex-wrap gap-1 mb-0">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link rounded" href="<?= h($base . ($page - 1)) ?>" aria-label="Previous">
                <i class="fas fa-chevron-left"></i>
            </a>
        </li>
        <?php foreach (pagination_pages($page, $total_pages) as $p): ?>
            <?php if ($p === '…'): ?>
            <li class="page-item disabled"><span class="page-link">…</span></li>
            <?php else: ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                <a class="page-link rounded" href="<?= h($base . $p) ?>"><?= $p ?></a>
            </li>
            <?php endif; ?>
        <?php endforeach; ?>
        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
            <a class="page-link rounded" href="<?= h($base . ($page + 1)) ?>" aria-label="Next">
                <i class="fas fa-chevron-right"></i>
            </a>
        </li>
    </ul>
    <p class="text-center text-muted small mt-2 mb-0">
        Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $dept_total) ?> of <?= $dept_total ?> notices
    </p>
</nav>
<?php endif; ?>
<?php endif; ?>

<style>
.notice-card { transition: box-shadow .15s; }
.notice-card:hover { box-shadow: 0 4px 20px rgba(0,0,0,.10) !important; }
.nav-tabs .nav-link:not(.active) { color: #6b7280; }
.nav-tabs .nav-link:not(.active):hover { background: #f3f4f6; border-color: #e5e7eb; }
.pagination .page-link {
    min-width: 36px;
    text-align: center;
    border-radius: 8px !important;
    font-size: .85rem;
}
.pagination .page-item.active .page-link {
    background-color: #4f8ef7;
    border-color: #4f8ef7;
}
@media (max-width: 480px) {
    .pagination .page-link { min-width: 32px; font-size: .78rem; padding: .3rem .5rem; }
}
</style>
<script>
function toggleNotice(btn, id) {
    const el = document.getElementById(id);
    const isOpen = el.style.display !== 'none';
    el.style.display = isOpen ? 'none' : 'block';
    btn.innerHTML = isOpen
        ? '<i class="fas fa-chevron-down me-1"></i>Read full notice'
        : '<i class="fas fa-chevron-up me-1"></i>Collapse';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
