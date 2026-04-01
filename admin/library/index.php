<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('library');
require_once __DIR__ . '/helpers.php';

$page_title  = 'Library Dashboard';
$breadcrumbs = [['label' => 'Library Dashboard']];

// ── Statistics ────────────────────────────────────────────────────────────────
$db = db();

$total_books       = (int)$db->query('SELECT COUNT(*) FROM library_books')->fetchColumn();
$total_copies      = (int)$db->query('SELECT COUNT(*) FROM library_book_copies')->fetchColumn();
$available_copies  = (int)$db->query('SELECT COUNT(*) FROM library_book_copies WHERE is_available = 1')->fetchColumn();

$issued_today = (int)$db->query(
    "SELECT COUNT(*) FROM library_circulation
     WHERE DATE(issue_date) = CURDATE() AND status = 'Issued'"
)->fetchColumn();

$overdue_count = (int)$db->query(
    "SELECT COUNT(*) FROM library_circulation
     WHERE status = 'Overdue' OR (status = 'Issued' AND due_date < NOW())"
)->fetchColumn();

$total_members = (int)$db->query(
    "SELECT COUNT(*) FROM library_members WHERE is_active = 1"
)->fetchColumn();

$unpaid_fines = (float)$db->query(
    "SELECT COALESCE(SUM(amount), 0) FROM library_fines WHERE status = 'Unpaid'"
)->fetchColumn();

$digital_resources = (int)$db->query(
    "SELECT COUNT(*) FROM library_digital_resources WHERE is_active = 1"
)->fetchColumn();

// ── Recent circulations (last 10) ─────────────────────────────────────────────
$recent_circulations = $db->query(
    "SELECT c.id, c.issue_date, c.due_date, c.status,
            b.title AS book_title,
            COALESCE(s.full_name, u.full_name) AS member_name,
            m.member_code
     FROM library_circulation c
     JOIN library_book_copies cp ON cp.id = c.copy_id
     JOIN library_books       b  ON b.id  = cp.book_id
     JOIN library_members     m  ON m.id  = c.member_id
     LEFT JOIN students       s  ON s.id  = m.student_id
     LEFT JOIN users          u  ON u.id  = m.user_id
     ORDER BY c.id DESC
     LIMIT 10"
)->fetchAll();

// ── Recent unpaid fines (last 5) ──────────────────────────────────────────────
$recent_fines = $db->query(
    "SELECT f.id, f.amount, f.status, f.created_at,
            b.title AS book_title,
            COALESCE(s.full_name, u.full_name) AS member_name,
            m.member_code,
            ci.due_date
     FROM library_fines f
     JOIN library_members     m  ON m.id  = f.member_id
     JOIN library_circulation ci ON ci.id = f.circulation_id
     JOIN library_book_copies cp ON cp.id = ci.copy_id
     JOIN library_books       b  ON b.id  = cp.book_id
     LEFT JOIN students       s  ON s.id  = m.student_id
     LEFT JOIN users          u  ON u.id  = m.user_id
     WHERE f.status = 'Unpaid'
     ORDER BY f.id DESC
     LIMIT 5"
)->fetchAll();

// ── Library info from settings ────────────────────────────────────────────────
$lib_name    = lib_setting('lib_name',    'University Library');
$lib_address = lib_setting('lib_address', '');
$lib_room    = lib_setting('lib_room',    '');
$lib_hours   = lib_setting('lib_hours',   '');
$lib_email   = lib_setting('lib_email',   '');
$lib_phone   = lib_setting('lib_phone',   '');

// ── Active librarians ─────────────────────────────────────────────────────────
$librarians = $db->query(
    "SELECT id, full_name, designation, room, email, phone, photo
     FROM library_librarians
     WHERE is_active = 1
     ORDER BY sort_order ASC, full_name ASC"
)->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Breadcrumb -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Library</li>
        </ol>
    </nav>
    <?php if (lib_can_create()): ?>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/library/books/create.php"
           class="btn btn-primary btn-sm" style="border-radius:10px;">
            <i class="fas fa-plus me-1"></i> Add Book
        </a>
        <a href="<?= APP_URL ?>/library/circulation/issue.php"
           class="btn btn-success btn-sm" style="border-radius:10px;">
            <i class="fas fa-arrow-right me-1"></i> Issue Book
        </a>
    </div>
    <?php endif; ?>
</div>

<?php
$error = flash_get('error');
$success = flash_get('success');
if ($error):   ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fas fa-circle-xmark me-2"></i><?= h($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif;
if ($success): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-circle-check me-2"></i><?= h($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ── Stats Row 1 ── -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#4f8ef7,#2d63e8);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= number_format($total_books) ?></div>
                    <div class="stat-label">Total Books</div>
                </div>
                <div class="stat-icon"><i class="fas fa-book"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#6f42c1,#4e2d8c);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= number_format($total_copies) ?></div>
                    <div class="stat-label">Total Copies</div>
                </div>
                <div class="stat-icon"><i class="fas fa-copy"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#11c48d,#0a9971);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= number_format($available_copies) ?></div>
                    <div class="stat-label">Available</div>
                </div>
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#f5a623,#d4870a);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= number_format($issued_today) ?></div>
                    <div class="stat-label">Issued Today</div>
                </div>
                <div class="stat-icon"><i class="fas fa-arrow-right"></i></div>
            </div>
        </div>
    </div>
</div>

<!-- ── Stats Row 2 ── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#e74c6e,#c42f52);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= number_format($overdue_count) ?></div>
                    <div class="stat-label">Overdue</div>
                </div>
                <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#0dcaf0,#0a9bb5);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= number_format($total_members) ?></div>
                    <div class="stat-label">Active Members</div>
                </div>
                <div class="stat-icon"><i class="fas fa-users"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#ffc107,#d4990a);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val">৳<?= number_format($unpaid_fines, 2) ?></div>
                    <div class="stat-label">Unpaid Fines</div>
                </div>
                <div class="stat-icon"><i class="fas fa-money-bill"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#7c3aed,#5b1fcc);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= number_format($digital_resources) ?></div>
                    <div class="stat-label">Digital Resources</div>
                </div>
                <div class="stat-icon"><i class="fas fa-file-pdf"></i></div>
            </div>
        </div>
    </div>
</div>

<!-- ── Quick Actions ── -->
<div class="card mb-4">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-bolt text-muted me-2"></i>Quick Actions</h6>
    </div>
    <div class="card-body py-3 px-4">
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= APP_URL ?>/library/circulation/issue.php"
               class="btn btn-success" style="border-radius:10px;">
                <i class="fas fa-arrow-right me-1"></i> Issue Book
            </a>
            <a href="<?= APP_URL ?>/library/circulation/return.php"
               class="btn btn-warning text-dark" style="border-radius:10px;">
                <i class="fas fa-undo me-1"></i> Return Book
            </a>
            <a href="<?= APP_URL ?>/library/books/create.php"
               class="btn btn-primary" style="border-radius:10px;">
                <i class="fas fa-plus me-1"></i> Add Book
            </a>
            <a href="<?= APP_URL ?>/library/books/index.php"
               class="btn btn-outline-primary" style="border-radius:10px;">
                <i class="fas fa-search me-1"></i> Search Books
            </a>
            <a href="<?= APP_URL ?>/library/members/create.php"
               class="btn btn-info text-white" style="border-radius:10px;">
                <i class="fas fa-user-plus me-1"></i> Add Member
            </a>
            <a href="<?= APP_URL ?>/library/reports/index.php"
               class="btn btn-secondary" style="border-radius:10px;">
                <i class="fas fa-chart-bar me-1"></i> View Reports
            </a>
        </div>
    </div>
</div>

<!-- ── Main content: tables side by side ── -->
<div class="row g-4 mb-4">

    <!-- Recent Circulations -->
    <div class="col-12 col-xl-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center py-3 px-4">
                <h6 class="mb-0 fw-semibold">
                    <i class="fas fa-clock text-muted me-2"></i>Recent Circulations
                </h6>
                <a href="<?= APP_URL ?>/library/circulation/index.php"
                   class="btn btn-sm btn-outline-primary" style="border-radius:8px;font-size:.8rem;">
                    View All
                </a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" style="font-size:.875rem;">
                        <thead class="table-light">
                            <tr>
                                <th class="px-4">Book</th>
                                <th>Member</th>
                                <th>Issue Date</th>
                                <th>Due Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($recent_circulations)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    No circulation records yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recent_circulations as $c): ?>
                            <tr>
                                <td class="px-4">
                                    <div class="fw-medium" style="max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                                         title="<?= h($c['book_title']) ?>">
                                        <?= h($c['book_title']) ?>
                                    </div>
                                </td>
                                <td>
                                    <div><?= h($c['member_name'] ?? '—') ?></div>
                                    <small class="text-muted"><?= h($c['member_code']) ?></small>
                                </td>
                                <td><?= $c['issue_date'] ? date('M d, Y', strtotime($c['issue_date'])) : '—' ?></td>
                                <td>
                                    <?php
                                        $due_ts = $c['due_date'] ? strtotime($c['due_date']) : null;
                                        $is_past = $due_ts && $due_ts < time() && $c['status'] !== 'Returned';
                                    ?>
                                    <span class="<?= $is_past ? 'text-danger fw-semibold' : '' ?>">
                                        <?= $c['due_date'] ? date('M d, Y', $due_ts) : '—' ?>
                                    </span>
                                </td>
                                <td><?= lib_circulation_status_badge($c['status']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Unpaid Fines -->
    <div class="col-12 col-xl-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center py-3 px-4">
                <h6 class="mb-0 fw-semibold">
                    <i class="fas fa-money-bill-wave text-muted me-2"></i>Unpaid Fines
                </h6>
                <a href="<?= APP_URL ?>/library/fines/index.php"
                   class="btn btn-sm btn-outline-danger" style="border-radius:8px;font-size:.8rem;">
                    View All
                </a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" style="font-size:.875rem;">
                        <thead class="table-light">
                            <tr>
                                <th class="px-4">Member</th>
                                <th>Book</th>
                                <th>Amount</th>
                                <th>Days</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($recent_fines)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">
                                    No unpaid fines.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recent_fines as $f): ?>
                            <tr>
                                <td class="px-4">
                                    <div class="fw-medium" style="max-width:110px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                                         title="<?= h($f['member_name'] ?? '') ?>">
                                        <?= h($f['member_name'] ?? '—') ?>
                                    </div>
                                    <small class="text-muted"><?= h($f['member_code']) ?></small>
                                </td>
                                <td>
                                    <span style="max-width:100px;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                                          title="<?= h($f['book_title']) ?>">
                                        <?= h($f['book_title']) ?>
                                    </span>
                                </td>
                                <td class="text-danger fw-semibold">৳<?= number_format((float)$f['amount'], 2) ?></td>
                                <td>
                                    <?php $days = $f['due_date'] ? lib_overdue_days($f['due_date']) : 0; ?>
                                    <?php if ($days > 0): ?>
                                        <span class="badge bg-danger bg-opacity-10 text-danger"><?= $days ?>d</span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- ── Library Info + Librarians ── -->
<div class="row g-4">

    <!-- Library Info -->
    <div class="col-12 col-md-4">
        <div class="card h-100">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold">
                    <i class="fas fa-building-columns text-muted me-2"></i>Library Info
                </h6>
            </div>
            <div class="card-body px-4">
                <div class="mb-3">
                    <div style="width:56px;height:56px;border-radius:14px;
                                background:linear-gradient(135deg,#4f8ef7,#2d63e8);
                                display:flex;align-items:center;justify-content:center;
                                font-size:1.5rem;color:#fff;" class="mb-3">
                        <i class="fas fa-book-open"></i>
                    </div>
                    <h5 class="fw-bold mb-1"><?= h($lib_name) ?></h5>
                </div>
                <?php if ($lib_address): ?>
                <div class="d-flex gap-2 mb-2" style="font-size:.875rem;">
                    <i class="fas fa-map-marker-alt text-muted mt-1" style="width:16px;"></i>
                    <span><?= h($lib_address) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($lib_room): ?>
                <div class="d-flex gap-2 mb-2" style="font-size:.875rem;">
                    <i class="fas fa-door-open text-muted mt-1" style="width:16px;"></i>
                    <span>Room: <?= h($lib_room) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($lib_hours): ?>
                <div class="d-flex gap-2 mb-2" style="font-size:.875rem;">
                    <i class="fas fa-clock text-muted mt-1" style="width:16px;"></i>
                    <span><?= nl2br(h($lib_hours)) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($lib_email): ?>
                <div class="d-flex gap-2 mb-2" style="font-size:.875rem;">
                    <i class="fas fa-envelope text-muted mt-1" style="width:16px;"></i>
                    <a href="mailto:<?= h($lib_email) ?>"><?= h($lib_email) ?></a>
                </div>
                <?php endif; ?>
                <?php if ($lib_phone): ?>
                <div class="d-flex gap-2 mb-2" style="font-size:.875rem;">
                    <i class="fas fa-phone text-muted mt-1" style="width:16px;"></i>
                    <span><?= h($lib_phone) ?></span>
                </div>
                <?php endif; ?>
                <?php if (lib_is_staff()): ?>
                <div class="mt-3 pt-3 border-top">
                    <a href="<?= APP_URL ?>/library/settings.php"
                       class="btn btn-sm btn-outline-secondary w-100" style="border-radius:8px;">
                        <i class="fas fa-cog me-1"></i> Edit Settings
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Librarians -->
    <div class="col-12 col-md-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center py-3 px-4">
                <h6 class="mb-0 fw-semibold">
                    <i class="fas fa-user-tie text-muted me-2"></i>Librarians
                </h6>
                <?php if (lib_is_staff()): ?>
                <a href="<?= APP_URL ?>/library/librarians/index.php"
                   class="btn btn-sm btn-outline-primary" style="border-radius:8px;font-size:.8rem;">
                    Manage
                </a>
                <?php endif; ?>
            </div>
            <div class="card-body px-4">
                <?php if (empty($librarians)): ?>
                    <p class="text-muted mb-0">No librarians on record.</p>
                <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($librarians as $lib): ?>
                    <div class="col-12 col-md-6 col-lg-4">
                        <div class="d-flex align-items-center gap-3 p-3 rounded-3"
                             style="background:#f8f9fa;border:1px solid #e9ecef;">
                            <?php if ($lib['photo']): ?>
                            <img src="<?= UPLOAD_URL ?>/library/librarians/<?= h($lib['photo']) ?>"
                                 alt="<?= h($lib['full_name']) ?>"
                                 style="width:48px;height:48px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                            <?php else: ?>
                            <div style="width:48px;height:48px;border-radius:50%;
                                        background:linear-gradient(135deg,#4f8ef7,#2d63e8);
                                        color:#fff;display:flex;align-items:center;
                                        justify-content:center;font-size:1.1rem;
                                        font-weight:600;flex-shrink:0;">
                                <?= strtoupper(substr($lib['full_name'], 0, 1)) ?>
                            </div>
                            <?php endif; ?>
                            <div style="min-width:0;">
                                <div class="fw-semibold text-truncate" style="font-size:.875rem;">
                                    <?= h($lib['full_name']) ?>
                                </div>
                                <?php if ($lib['designation']): ?>
                                <div class="text-muted text-truncate" style="font-size:.775rem;">
                                    <?= h($lib['designation']) ?>
                                </div>
                                <?php endif; ?>
                                <?php if ($lib['room']): ?>
                                <div class="text-muted" style="font-size:.775rem;">
                                    <i class="fas fa-door-open fa-xs me-1"></i><?= h($lib['room']) ?>
                                </div>
                                <?php endif; ?>
                                <?php if ($lib['email']): ?>
                                <div style="font-size:.775rem;">
                                    <a href="mailto:<?= h($lib['email']) ?>" class="text-truncate d-block">
                                        <i class="fas fa-envelope fa-xs me-1 text-muted"></i><?= h($lib['email']) ?>
                                    </a>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
