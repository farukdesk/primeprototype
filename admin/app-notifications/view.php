<?php
/**
 * App Notification – View a sent notification
 */

require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('app-notifications');
require_once __DIR__ . '/helpers.php';

$id = (int)($_GET['id'] ?? 0);
$n  = $id ? apn_find($id) : null;
if (!$n) {
    flash_set('error', 'Notification not found.');
    redirect(APP_URL . '/app-notifications/index.php');
}

// ── Recipient list (paginated, 25 per page) ─────────────────────────────
$rp_per_page  = 25;
$rp_page      = max(1, (int)($_GET['page'] ?? 1));
$rp_total     = 0;
$rp_pages     = 1;
$recipients   = [];
$rp_summary   = [];
$rp_available = true;
try {
    $rp_total = apn_recipients_count($id);
    $rp_pages = max(1, (int)ceil($rp_total / $rp_per_page));
    if ($rp_page > $rp_pages) {
        $rp_page = $rp_pages;
    }
    $recipients = apn_recipients($id, $rp_per_page, ($rp_page - 1) * $rp_per_page);
    $rp_summary = apn_recipient_summary($id);
} catch (Throwable $e) {
    $rp_available = false; // recipients table missing (app-notifications-recipients.sql not applied)
}
$rp_url = APP_URL . '/app-notifications/view.php?id=' . (int)$id . '&page=';

$page_title = 'Notification – ' . $n['title'];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/app-notifications/index.php">App Notification</a></li>
            <li class="breadcrumb-item active"><?= h($n['title']) ?></li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/app-notifications/index.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i> Back
    </a>
</div>

<?php flash_show(); ?>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="d-flex align-items-start justify-content-between">
            <h1 class="h4 mb-2"><?= h($n['title']) ?></h1>
            <?= apn_status_badge($n['status']) ?>
        </div>
        <p class="text-muted small mb-2">
            Sent by <?= h($n['sender_name'] ?? '—') ?> on
            <?= h(date('d M Y, H:i', strtotime($n['created_at']))) ?>
        </p>
        <?php if (!empty($n['audience'])): ?>
        <p class="mb-3">
            <span class="badge bg-light text-dark border">
                <i class="fas fa-bullseye me-1 text-muted"></i>Audience: <?= h($n['audience']) ?>
            </span>
        </p>
        <?php endif; ?>

        <div class="mb-4" style="white-space:pre-wrap;"><?= h($n['body']) ?></div>

        <?php if (!empty($n['url'])): ?>
        <p class="mb-4">
            <i class="fas fa-link me-1 text-muted"></i>
            <a href="<?= h($n['url']) ?>" target="_blank" rel="noopener"><?= h($n['url']) ?></a>
        </p>
        <?php endif; ?>

        <div class="row text-center g-3">
            <div class="col">
                <div class="border rounded py-3">
                    <div class="h4 mb-0"><?= (int)$n['total_tokens'] ?></div>
                    <div class="text-muted small">Targeted</div>
                </div>
            </div>
            <div class="col">
                <div class="border rounded py-3">
                    <div class="h4 mb-0 text-success"><?= (int)$n['sent_count'] ?></div>
                    <div class="text-muted small">Delivered</div>
                </div>
            </div>
            <div class="col">
                <div class="border rounded py-3">
                    <div class="h4 mb-0 text-danger"><?= (int)$n['failed_count'] ?></div>
                    <div class="text-muted small">Failed</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Recipients -->
<div class="card shadow-sm mt-4">
    <div class="card-header bg-white d-flex align-items-center justify-content-between">
        <h2 class="h6 mb-0"><i class="fas fa-users me-2 text-primary"></i>Recipients</h2>
        <div class="d-flex gap-2">
            <?php if (($rp_summary['student'] ?? 0) > 0): ?>
            <span class="badge bg-primary"><?= (int)$rp_summary['student'] ?> student<?= (int)$rp_summary['student'] === 1 ? '' : 's' ?></span>
            <?php endif; ?>
            <?php if (($rp_summary['user'] ?? 0) > 0): ?>
            <span class="badge bg-info text-dark"><?= (int)$rp_summary['user'] ?> employee<?= (int)$rp_summary['user'] === 1 ? '' : 's' ?> / user<?= (int)$rp_summary['user'] === 1 ? '' : 's' ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (!$rp_available): ?>
        <div class="text-muted small p-4">
            Recipient logging is not set up yet. Run <code>admin/app-notifications-recipients.sql</code>;
            recipients of future notifications will then be listed here.
        </div>
        <?php elseif ($rp_total === 0): ?>
        <div class="text-muted small p-4">
            No recipient details were recorded for this notification
            (it was sent before recipient logging was enabled, or no device matched the audience).
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Type</th>
                        <th>Name</th>
                        <th>ID / Username</th>
                        <th>Department</th>
                        <th>Email</th>
                        <th>Devices</th>
                        <th>Delivery</th>
                    </tr>
                </thead>
                <tbody>
                <?php $i = ($rp_page - 1) * $rp_per_page; foreach ($recipients as $r): $i++; ?>
                <?php
                    $is_student = $r['source'] === 'student';
                    $type = $is_student ? 'Student'
                          : (($r['department_type'] ?? '') === 'educational' ? 'Faculty'
                          : ((($r['department_type'] ?? '') === 'administrative') ? 'Administrative' : 'User'));
                    $type_class = $is_student ? 'bg-primary'
                                : ($type === 'Faculty' ? 'bg-success'
                                : ($type === 'Administrative' ? 'bg-secondary' : 'bg-info text-dark'));
                    $name  = $is_student ? ($r['student_name'] ?? $r['user_name'] ?? '—') : ($r['user_name'] ?? '—');
                    $email = $is_student ? (string)($r['student_email'] ?? $r['user_email'] ?? '') : (string)($r['user_email'] ?? '');
                    $delivered = (int)$r['sent_devices'] > 0;
                ?>
                <tr>
                    <td class="text-muted"><?= $i ?></td>
                    <td><span class="badge <?= $type_class ?>"><?= h($type) ?></span></td>
                    <td class="fw-semibold">
                        <?php if ($is_student && !empty($r['student_db_id'])): ?>
                        <a href="<?= APP_URL ?>/students/view.php?id=<?= (int)$r['student_db_id'] ?>" class="text-decoration-none"><?= h($name) ?></a>
                        <?php elseif (!$is_student && !empty($r['recipient_user_id'])): ?>
                        <a href="<?= APP_URL ?>/users/edit.php?id=<?= (int)$r['recipient_user_id'] ?>" class="text-decoration-none"><?= h($name) ?></a>
                        <?php else: ?>
                        <?= h($name) ?>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= h($is_student ? ($r['student_id'] ?? '—') : ($r['username'] ?? '—')) ?></td>
                    <td class="small"><?= h($r['dept_name'] ?? '—') ?></td>
                    <td class="small"><?= $email !== '' ? '<a href="mailto:' . h($email) . '">' . h($email) . '</a>' : '—' ?></td>
                    <td class="small"><?= (int)$r['device_count'] ?></td>
                    <td>
                        <?php if ($delivered && (int)$r['sent_devices'] === (int)$r['device_count']): ?>
                        <span class="badge bg-success">Delivered</span>
                        <?php elseif ($delivered): ?>
                        <span class="badge bg-warning text-dark">Partial (<?= (int)$r['sent_devices'] ?>/<?= (int)$r['device_count'] ?>)</span>
                        <?php else: ?>
                        <span class="badge bg-danger">Failed</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($rp_pages > 1): ?>
        <div class="d-flex align-items-center justify-content-between border-top px-3 py-2">
            <span class="text-muted small">
                Showing <?= (($rp_page - 1) * $rp_per_page) + 1 ?>–<?= min($rp_page * $rp_per_page, $rp_total) ?> of <?= $rp_total ?> recipient<?= $rp_total === 1 ? '' : 's' ?>
            </span>
            <nav aria-label="Recipient pages">
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= $rp_page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= $rp_url . ($rp_page - 1) ?>">&laquo;</a>
                    </li>
                    <?php $win_start = max(1, $rp_page - 2); $win_end = min($rp_pages, $rp_page + 2); ?>
                    <?php if ($win_start > 1): ?>
                    <li class="page-item"><a class="page-link" href="<?= $rp_url ?>1">1</a></li>
                    <?php if ($win_start > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                    <?php endif; ?>
                    <?php for ($p = $win_start; $p <= $win_end; $p++): ?>
                    <li class="page-item <?= $p === $rp_page ? 'active' : '' ?>">
                        <a class="page-link" href="<?= $rp_url . $p ?>"><?= $p ?></a>
                    </li>
                    <?php endfor; ?>
                    <?php if ($win_end < $rp_pages): ?>
                    <?php if ($win_end < $rp_pages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                    <li class="page-item"><a class="page-link" href="<?= $rp_url . $rp_pages ?>"><?= $rp_pages ?></a></li>
                    <?php endif; ?>
                    <li class="page-item <?= $rp_page >= $rp_pages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= $rp_url . ($rp_page + 1) ?>">&raquo;</a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
