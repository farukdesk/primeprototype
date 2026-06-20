<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('clubs');

$db   = db();
$id   = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare(
    'SELECT e.*, c.name AS club_name, c.id AS club_id
     FROM club_events e
     JOIN clubs c ON c.id = e.club_id
     WHERE e.id = ?'
);
$stmt->execute([$id]);
$event = $stmt->fetch();
if (!$event) { flash_set('error', 'Event not found.'); redirect(APP_URL . '/clubs/index.php'); }

$page_title = $event['title'];

// ── Filters for registrations ─────────────────────────────────────────────────
$status_filter = $_GET['status'] ?? '';
$where         = ['r.event_id = ?'];
$params        = [$id];
if (in_array($status_filter, ['pending','approved','rejected'], true)) {
    $where[]  = 'r.status = ?';
    $params[] = $status_filter;
}
$where_sql = implode(' AND ', $where);

$st = $db->prepare(
    "SELECT r.*, u.full_name AS reviewer_name
     FROM club_event_registrations r
     LEFT JOIN users u ON u.id = r.reviewed_by
     WHERE $where_sql
     ORDER BY r.created_at DESC"
);
$st->execute($params);
$registrations = $st->fetchAll();

// Counts
$cnt = $db->prepare('SELECT status, COUNT(*) AS cnt FROM club_event_registrations WHERE event_id=? GROUP BY status');
$cnt->execute([$id]);
$counts = ['pending'=>0,'approved'=>0,'rejected'=>0];
foreach ($cnt->fetchAll() as $row) $counts[$row['status']] = $row['cnt'];
$total_regs = array_sum($counts);

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-calendar-day me-2 text-primary"></i><?= h($event['title']) ?></h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/clubs/index.php">Clubs</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/clubs/view.php?id=<?= $event['club_id'] ?>"><?= h($event['club_name']) ?></a></li>
            <li class="breadcrumb-item active">Event</li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2">
        <?php if (clubs_is_staff()): ?>
        <a href="<?= APP_URL ?>/clubs/event-edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit me-1"></i>Edit</a>
        <?php endif; ?>
        <?php if (clubs_can_delete()): ?>
        <a href="<?= APP_URL ?>/clubs/event-delete.php?id=<?= $id ?>&club_id=<?= $event['club_id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this event?')"><i class="fas fa-trash me-1"></i>Delete</a>
        <?php endif; ?>
    </div>
</div>

<?= flash_show() ?>

<!-- Event Info -->
<div class="row g-4 mb-4">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm h-100">
            <?php if ($event['cover_photo']): ?>
            <img src="<?= CLUB_URL_EVENTS ?>/<?= h($event['cover_photo']) ?>" class="card-img-top rounded-top" style="max-height:260px;object-fit:cover;" alt="">
            <?php endif; ?>
            <div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <h5 class="mb-0 fw-bold"><?= h($event['title']) ?></h5>
                    <?= $event['is_published'] ? '<span class="badge bg-success">Published</span>' : '<span class="badge bg-warning text-dark">Draft</span>' ?>
                </div>
                <?php if ($event['description']): ?>
                <p class="text-muted"><?= nl2br(h($event['description'])) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header fw-semibold py-3"><i class="fas fa-info-circle me-2 text-muted"></i>Event Details</div>
            <div class="card-body">
                <ul class="list-unstyled mb-0">
                    <?php if ($event['event_date']): ?>
                    <li class="d-flex gap-2 mb-3">
                        <span class="text-muted" style="min-width:28px"><i class="fas fa-calendar-alt"></i></span>
                        <div><div class="fw-semibold small">Date &amp; Time</div>
                            <?= date('d M Y', strtotime($event['event_date'])) ?>
                            <?php if ($event['event_time']): ?> &nbsp;at&nbsp; <?= date('h:i A', strtotime($event['event_time'])) ?><?php endif; ?>
                        </div>
                    </li>
                    <?php endif; ?>
                    <?php if ($event['venue']): ?>
                    <li class="d-flex gap-2 mb-3">
                        <span class="text-muted" style="min-width:28px"><i class="fas fa-map-marker-alt"></i></span>
                        <div><div class="fw-semibold small">Venue</div><?= h($event['venue']) ?></div>
                    </li>
                    <?php endif; ?>
                    <li class="d-flex gap-2 mb-3">
                        <span class="text-muted" style="min-width:28px"><i class="fas fa-users"></i></span>
                        <div><div class="fw-semibold small">Capacity</div><?= $event['capacity'] ? $event['capacity'] . ' seats' : 'Unlimited' ?></div>
                    </li>
                    <?php if ($event['registration_deadline']): ?>
                    <li class="d-flex gap-2 mb-3">
                        <span class="text-muted" style="min-width:28px"><i class="fas fa-hourglass-end"></i></span>
                        <div><div class="fw-semibold small">Registration Deadline</div><?= date('d M Y', strtotime($event['registration_deadline'])) ?></div>
                    </li>
                    <?php endif; ?>
                    <li class="d-flex gap-2">
                        <span class="text-muted" style="min-width:28px"><i class="fas fa-clipboard-list"></i></span>
                        <div><div class="fw-semibold small">Club</div><a href="<?= APP_URL ?>/clubs/view.php?id=<?= $event['club_id'] ?>"><?= h($event['club_name']) ?></a></div>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Registration Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-3 fw-bold text-dark"><?= $total_regs ?></div>
            <div class="small text-muted">Total</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-3 fw-bold text-warning"><?= $counts['pending'] ?></div>
            <div class="small text-muted">Pending</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-3 fw-bold text-success"><?= $counts['approved'] ?></div>
            <div class="small text-muted">Approved</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-3 fw-bold text-danger"><?= $counts['rejected'] ?></div>
            <div class="small text-muted">Rejected</div>
        </div>
    </div>
</div>

<!-- Registrations Table -->
<div class="card shadow-sm border-0">
    <div class="card-header d-flex justify-content-between align-items-center py-3">
        <span class="fw-semibold"><i class="fas fa-clipboard-list me-2"></i>Registrations</span>
        <div class="d-flex gap-2">
            <a href="?id=<?= $id ?>" class="btn btn-sm <?= $status_filter==='' ? 'btn-dark' : 'btn-outline-dark' ?>">All</a>
            <a href="?id=<?= $id ?>&status=pending"  class="btn btn-sm <?= $status_filter==='pending'  ? 'btn-warning text-dark' : 'btn-outline-warning text-dark' ?>">Pending</a>
            <a href="?id=<?= $id ?>&status=approved" class="btn btn-sm <?= $status_filter==='approved' ? 'btn-success' : 'btn-outline-success' ?>">Approved</a>
            <a href="?id=<?= $id ?>&status=rejected" class="btn btn-sm <?= $status_filter==='rejected' ? 'btn-danger'  : 'btn-outline-danger' ?>">Rejected</a>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($registrations)): ?>
        <div class="text-center py-5 text-muted"><i class="fas fa-inbox fa-2x mb-2 d-block opacity-25"></i>No registrations found.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Applicant</th>
                        <th>Student ID</th>
                        <th>Department / Program</th>
                        <th>Contact</th>
                        <th class="text-center">Status</th>
                        <th>Registered</th>
                        <?php if (clubs_is_staff()): ?><th class="text-end">Action</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php $n=1; foreach ($registrations as $r): ?>
                <tr>
                    <td class="text-muted small"><?= $n++ ?></td>
                    <td>
                        <div class="fw-semibold"><?= h($r['full_name']) ?></div>
                        <?php if ($r['message']): ?><div class="small text-muted text-truncate" style="max-width:200px" title="<?= h($r['message']) ?>"><?= h($r['message']) ?></div><?php endif; ?>
                    </td>
                    <td><?= h($r['student_id_no'] ?? '—') ?></td>
                    <td>
                        <?php if ($r['department']): ?><div class="small"><?= h($r['department']) ?></div><?php endif; ?>
                        <?php if ($r['program']): ?><span class="badge bg-light text-dark border small"><?= h($r['program']) ?></span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($r['email']): ?><div class="small"><i class="fas fa-envelope me-1 text-muted"></i><?= h($r['email']) ?></div><?php endif; ?>
                        <?php if ($r['phone']): ?><div class="small"><i class="fas fa-phone me-1 text-muted"></i><?= h($r['phone']) ?></div><?php endif; ?>
                    </td>
                    <td class="text-center"><?= clubs_reg_status_badge($r['status']) ?></td>
                    <td class="small text-muted"><?= date('d M Y', strtotime($r['created_at'])) ?></td>
                    <?php if (clubs_is_staff()): ?>
                    <td class="text-end">
                        <?php if ($r['status'] !== 'approved'): ?>
                        <a href="<?= APP_URL ?>/clubs/registration-status.php?id=<?= $r['id'] ?>&event_id=<?= $id ?>&status=approved"
                           class="btn btn-sm btn-outline-success" title="Approve"><i class="fas fa-check"></i></a>
                        <?php endif; ?>
                        <?php if ($r['status'] !== 'rejected'): ?>
                        <a href="<?= APP_URL ?>/clubs/registration-status.php?id=<?= $r['id'] ?>&event_id=<?= $id ?>&status=rejected"
                           class="btn btn-sm btn-outline-danger" title="Reject" onclick="return confirm('Reject this registration?')"><i class="fas fa-times"></i></a>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
