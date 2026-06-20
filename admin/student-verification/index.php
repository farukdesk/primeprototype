<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('student-verification');
require_once __DIR__ . '/../change-log/helpers.php';

$page_title = 'Student Verification Log';
$user       = auth_user();

// ── Filters ───────────────────────────────────────────────────────────────────
$search   = trim($_GET['search']  ?? '');
$f_status = $_GET['status']       ?? '';
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;

$is_fake_view = ($f_status === 'Fake');

// ── Global stats (unfiltered) ─────────────────────────────────────────────────
try {
    $stats_rows = db()->query(
        "SELECT overall_status, COUNT(*) AS cnt FROM student_verifications GROUP BY overall_status"
    )->fetchAll();
    $stats = array_column($stats_rows, 'cnt', 'overall_status');
} catch (Throwable $e) {
    $stats = [];
}
$fake_total = 0;
try {
    $fake_total = (int)db()->query("SELECT COUNT(*) FROM fake_id_verifications")->fetchColumn();
} catch (Throwable $e) { $fake_total = 0; }
$grand_total = ($stats['Verified'] ?? 0) + ($stats['Failed'] ?? 0) + $fake_total;

// ── Query ─────────────────────────────────────────────────────────────────────
$records     = [];
$total_rows  = 0;
$total_pages = 1;
$offset      = 0;

if ($is_fake_view) {
    // ── Fake / Invalid ID log ────────────────────────────────────────────────
    $where  = [];
    $params = [];
    if ($search !== '') {
        $like     = '%' . $search . '%';
        $where[]  = '(fv.student_id LIKE ? OR fv.student_name LIKE ? OR u.full_name LIKE ?)';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    $where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $base_sql  = 'FROM fake_id_verifications fv JOIN users u ON u.id = fv.checked_by';

    try {
        $count_stmt = db()->prepare('SELECT COUNT(*)' . $base_sql . $where_sql);
        $count_stmt->execute($params);
        $total_rows  = (int)$count_stmt->fetchColumn();
        $total_pages = max(1, (int)ceil($total_rows / $per_page));
        $page        = min($page, $total_pages);
        $offset      = ($page - 1) * $per_page;

        $sql = 'SELECT fv.id, fv.student_id AS s_student_id, fv.student_name AS s_full_name,
                       fv.to_email, fv.ref_no, fv.email_sent, fv.created_at,
                       u.full_name AS verified_by_name'
             . ' ' . $base_sql . $where_sql
             . ' ORDER BY fv.created_at DESC'
             . ' LIMIT ' . $per_page . ' OFFSET ' . $offset;
        $stmt    = db()->prepare($sql);
        $stmt->execute($params);
        $records = $stmt->fetchAll();
    } catch (Throwable $e) {
        $records = [];
    }

} else {
    // ── Regular student verifications ────────────────────────────────────────
    $where  = [];
    $params = [];

    if ($search !== '') {
        $like     = '%' . $search . '%';
        $where[]  = '(s.student_id LIKE ? OR s.full_name LIKE ? OR u.full_name LIKE ?)';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if (in_array($f_status, ['Verified', 'Failed'], true)) {
        $where[]  = 'sv.overall_status = ?';
        $params[] = $f_status;
    }

    $where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $base_sql = 'FROM student_verifications sv
                 JOIN students s ON s.id = sv.student_id
                 JOIN users u ON u.id = sv.verified_by
                 LEFT JOIN dept_departments d ON d.id = s.dept_id';

    $count_stmt = db()->prepare('SELECT COUNT(*)' . $base_sql . $where_sql);
    $count_stmt->execute($params);
    $total_rows  = (int)$count_stmt->fetchColumn();
    $total_pages = max(1, (int)ceil($total_rows / $per_page));
    $page        = min($page, $total_pages);
    $offset      = ($page - 1) * $per_page;

    $sql = 'SELECT sv.id, sv.overall_status, sv.verifier_email, sv.email_sent,
                   sv.cert_transcript_ok, sv.admission_form_ok, sv.tabulation_ok,
                   sv.created_at,
                   s.id AS s_id, s.student_id AS s_student_id, s.full_name AS s_full_name,
                   d.name AS dept_name,
                   u.full_name AS verified_by_name'
         . ' ' . $base_sql . $where_sql
         . ' ORDER BY sv.created_at DESC'
         . ' LIMIT ' . $per_page . ' OFFSET ' . $offset;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll();
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Student Verification</li>
        </ol>
    </nav>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (is_super_admin() || can_access('student-verification', 'can_create')): ?>
        <a href="<?= APP_URL ?>/student-verification/verify.php" class="btn btn-primary" style="border-radius:10px;font-size:.875rem;">
            <i class="fas fa-shield-alt me-1"></i> New Verification
        </a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/student-verification/fake-id-alert.php" class="btn btn-danger" style="border-radius:10px;font-size:.875rem;">
            <i class="fas fa-ban me-1"></i> Fake / Invalid ID Alert
        </a>
    </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#4f8ef7,#3a6fd8);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= $grand_total ?></div>
                    <div class="stat-label">Total Verifications</div>
                </div>
                <div class="stat-icon"><i class="fas fa-clipboard-check"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#28a745,#1d7a34);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= $stats['Verified'] ?? 0 ?></div>
                    <div class="stat-label">Verified</div>
                </div>
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#dc3545,#a71d2a);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= $stats['Failed'] ?? 0 ?></div>
                    <div class="stat-label">Failed</div>
                </div>
                <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#7f1d1d,#991b1b);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= $fake_total ?></div>
                    <div class="stat-label">Fake / Invalid</div>
                </div>
                <div class="stat-icon"><i class="fas fa-ban"></i></div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body py-3 px-4">
        <form method="GET" action="" class="row g-2 align-items-end">
            <div class="col-12 col-md-5">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Search</label>
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="Student ID, name, or verifier name…"
                       value="<?= h($search) ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="Verified" <?= $f_status === 'Verified' ? 'selected' : '' ?>>Verified</option>
                    <option value="Failed"   <?= $f_status === 'Failed'   ? 'selected' : '' ?>>Failed</option>
                    <option value="Fake"     <?= $f_status === 'Fake'     ? 'selected' : '' ?>>Fake / Invalid</option>
                </select>
            </div>
            <div class="col-6 col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-fill" style="border-radius:7px;">
                    <i class="fas fa-search me-1"></i> Filter
                </button>
                <a href="<?= APP_URL ?>/student-verification/index.php" class="btn btn-outline-secondary btn-sm flex-fill" style="border-radius:7px;">
                    Reset
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Table -->
<div class="card">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold">
            <?php if ($is_fake_view): ?>
            <i class="fas fa-ban me-2 text-danger"></i>Fake / Invalid ID Log
            <?php else: ?>
            <i class="fas fa-shield-alt me-2 text-muted"></i>Verification Log
            <?php endif; ?>
        </h6>
        <span class="badge bg-primary bg-opacity-10 text-primary"><?= $total_rows ?> record<?= $total_rows !== 1 ? 's' : '' ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <?php if ($is_fake_view): ?>
            <!-- Fake / Invalid ID table -->
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Presented Credentials</th>
                        <th>Status</th>
                        <th>Checked By</th>
                        <th>Sent To</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($records)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No fake / invalid ID records found.</td></tr>
                <?php else: ?>
                    <?php foreach ($records as $i => $r): ?>
                    <tr>
                        <td class="px-4"><?= $offset + $i + 1 ?></td>
                        <td>
                            <div class="fw-medium"><?= h($r['s_full_name']) ?></div>
                            <code class="text-muted" style="font-size:.78rem;"><?= h($r['s_student_id']) ?></code>
                            <?php if ($r['ref_no']): ?>
                            <div style="font-size:.74rem;color:#6b7280;"><?= h($r['ref_no']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge" style="background:#7f1d1d;"><i class="fas fa-ban me-1"></i>Fake / Invalid</span>
                        </td>
                        <td style="font-size:.85rem;"><?= h($r['verified_by_name']) ?></td>
                        <td style="font-size:.82rem;"><?= $r['to_email'] ? h($r['to_email']) : '<span class="text-muted">—</span>' ?></td>
                        <td style="font-size:.82rem; white-space:nowrap;">
                            <?= date('d M Y, H:i', strtotime($r['created_at'])) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            <?php else: ?>
            <!-- Regular verification table -->
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Student</th>
                        <th>Department</th>
                        <th style="white-space:nowrap;">
                            <span title="Certificate &amp; Transcript"><i class="fas fa-certificate text-muted"></i></span>
                            <span title="Admission Form"><i class="fas fa-file-alt text-muted ms-1"></i></span>
                            <span title="Tabulation"><i class="fas fa-table text-muted ms-1"></i></span>
                        </th>
                        <th>Status</th>
                        <th>Verified By</th>
                        <th>Date</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($records)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No verification records found.</td></tr>
                <?php else: ?>
                    <?php foreach ($records as $i => $r): ?>
                    <tr>
                        <td class="px-4"><?= $offset + $i + 1 ?></td>
                        <td>
                            <div class="fw-medium"><?= h($r['s_full_name']) ?></div>
                            <code class="text-muted" style="font-size:.78rem;"><?= h($r['s_student_id']) ?></code>
                        </td>
                        <td style="font-size:.85rem;"><?= h($r['dept_name'] ?? '—') ?></td>
                        <td>
                            <?php
                            $ci = $r['cert_transcript_ok'] ? 'fas fa-check-circle text-success' : 'fas fa-times-circle text-danger';
                            $ai = $r['admission_form_ok']  ? 'fas fa-check-circle text-success' : 'fas fa-times-circle text-danger';
                            $ti = $r['tabulation_ok']      ? 'fas fa-check-circle text-success' : 'fas fa-times-circle text-danger';
                            ?>
                            <i class="<?= $ci ?>" title="Certificate &amp; Transcript"></i>
                            <i class="<?= $ai ?> ms-1" title="Admission Form"></i>
                            <i class="<?= $ti ?> ms-1" title="Tabulation"></i>
                        </td>
                        <td>
                            <?php if ($r['overall_status'] === 'Verified'): ?>
                                <span class="badge bg-success"><i class="fas fa-shield-alt me-1"></i>Verified</span>
                            <?php else: ?>
                                <span class="badge bg-danger"><i class="fas fa-times me-1"></i>Failed</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:.85rem;"><?= h($r['verified_by_name']) ?></td>
                        <td style="font-size:.82rem; white-space:nowrap;">
                            <?= date('d M Y, H:i', strtotime($r['created_at'])) ?>
                        </td>
                        <td class="text-end pe-4">
                            <a href="<?= APP_URL ?>/student-verification/view.php?id=<?= $r['id'] ?>"
                               class="btn btn-sm btn-outline-info" title="View" style="border-radius:7px;">
                                <i class="fas fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="card-footer py-3 px-4 d-flex justify-content-between align-items-center">
        <small class="text-muted">
            Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_rows) ?> of <?= $total_rows ?>
        </small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php
                $qp = $_GET;
                for ($p = 1; $p <= $total_pages; $p++):
                    $qp['page'] = $p;
                    $active = $p === $page;
                ?>
                <li class="page-item <?= $active ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query($qp) ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

