<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('cert-verifiers');

$page_title = 'Certificate Verification Log';

// ── Filters ───────────────────────────────────────────────────────────────────
$search       = trim($_GET['search']  ?? '');
$f_type       = $_GET['type']         ?? '';
$f_found      = $_GET['found']        ?? '';
$page         = max(1, (int)($_GET['page'] ?? 1));
$per_page     = 25;

$where  = [];
$params = [];

if ($search !== '') {
    $like     = '%' . $search . '%';
    $where[]  = '(cvl.queried_student_id LIKE ? OR cvl.verifier_name LIKE ? OR cvl.verifier_email LIKE ? OR cvl.company_name LIKE ?)';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if (in_array($f_type, ['student', 'company'], true)) {
    $where[]  = 'cvl.verifier_type = ?';
    $params[] = $f_type;
}
if ($f_found === '1') {
    $where[]  = 'cvl.student_found = 1';
} elseif ($f_found === '0') {
    $where[]  = 'cvl.student_found = 0';
}

$where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$base_sql = 'FROM cert_verification_log cvl
             LEFT JOIN students s ON s.id = cvl.student_id';

$count_stmt = db()->prepare('SELECT COUNT(*)' . $base_sql . $where_sql);
$count_stmt->execute($params);
$total_rows  = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$sql = 'SELECT cvl.id, cvl.queried_student_id, cvl.student_found, cvl.verifier_type,
               cvl.verifier_name, cvl.verifier_email, cvl.verifier_phone,
               cvl.company_name, cvl.ip_address, cvl.created_at,
               s.full_name AS s_full_name, s.student_id AS s_student_id'
     . ' ' . $base_sql . $where_sql
     . ' ORDER BY cvl.created_at DESC'
     . ' LIMIT ' . $per_page . ' OFFSET ' . $offset;

$stmt = db()->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

// Stats
try {
    $total_all   = (int)db()->query('SELECT COUNT(*) FROM cert_verification_log')->fetchColumn();
    $total_found = (int)db()->query('SELECT COUNT(*) FROM cert_verification_log WHERE student_found = 1')->fetchColumn();
    $total_co    = (int)db()->query("SELECT COUNT(*) FROM cert_verification_log WHERE verifier_type = 'company'")->fetchColumn();
} catch (Throwable $e) {
    $total_all = $total_found = $total_co = 0;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Certificate Verification Log</li>
        </ol>
    </nav>
    <?php if (is_super_admin() || can_access('cert-verifiers', 'can_delete')): ?>
    <form method="POST" action="<?= APP_URL ?>/cert-verifiers/delete.php"
          onsubmit="return confirm('Delete ALL displayed records? This cannot be undone.');">
        <?= csrf_field() ?>
        <input type="hidden" name="delete_all" value="1">
        <input type="hidden" name="search"  value="<?= h($search) ?>">
        <input type="hidden" name="type"    value="<?= h($f_type) ?>">
        <input type="hidden" name="found"   value="<?= h($f_found) ?>">
        <button class="btn btn-outline-danger btn-sm" style="border-radius:10px;font-size:.875rem;">
            <i class="fas fa-trash-alt me-1"></i> Delete Filtered
        </button>
    </form>
    <?php endif; ?>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="stat-card" style="background:linear-gradient(135deg,#4f8ef7,#3a6fd8);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= $total_all ?></div>
                    <div class="stat-label">Total Requests</div>
                </div>
                <div class="stat-icon"><i class="fas fa-search-plus"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="stat-card" style="background:linear-gradient(135deg,#28a745,#1d7a34);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= $total_found ?></div>
                    <div class="stat-label">Student Found</div>
                </div>
                <div class="stat-icon"><i class="fas fa-user-check"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="stat-card" style="background:linear-gradient(135deg,#6f42c1,#4a1f8a);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= $total_co ?></div>
                    <div class="stat-label">Company Verifiers</div>
                </div>
                <div class="stat-icon"><i class="fas fa-building"></i></div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body py-3 px-4">
        <form method="GET" action="" class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Search</label>
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="Student ID, name, email, company…"
                       value="<?= h($search) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Type</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="student" <?= $f_type === 'student' ? 'selected' : '' ?>>Student</option>
                    <option value="company" <?= $f_type === 'company' ? 'selected' : '' ?>>Company</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Result</label>
                <select name="found" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="1" <?= $f_found === '1' ? 'selected' : '' ?>>Found</option>
                    <option value="0" <?= $f_found === '0' ? 'selected' : '' ?>>Not Found</option>
                </select>
            </div>
            <div class="col-12 col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-fill" style="border-radius:7px;">
                    <i class="fas fa-search me-1"></i> Filter
                </button>
                <a href="<?= APP_URL ?>/cert-verifiers/index.php" class="btn btn-outline-secondary btn-sm flex-fill" style="border-radius:7px;">
                    Reset
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Table -->
<div class="card">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-list me-2 text-muted"></i>Verification Requests</h6>
        <span class="badge bg-primary bg-opacity-10 text-primary"><?= $total_rows ?> record<?= $total_rows !== 1 ? 's' : '' ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Queried Student</th>
                        <th>Verifier</th>
                        <th>Type</th>
                        <th>Result</th>
                        <th>IP</th>
                        <th>Date</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($records)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No verification requests found.</td></tr>
                <?php else: ?>
                    <?php foreach ($records as $i => $r): ?>
                    <tr>
                        <td class="px-4"><?= $offset + $i + 1 ?></td>
                        <td>
                            <code style="font-size:.82rem;"><?= h($r['queried_student_id']) ?></code>
                            <?php if ($r['student_found'] && $r['s_full_name']): ?>
                            <div class="text-muted" style="font-size:.78rem;"><?= h($r['s_full_name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="fw-medium"><?= h($r['verifier_name']) ?></div>
                            <div class="text-muted" style="font-size:.78rem;">
                                <a href="mailto:<?= h($r['verifier_email']) ?>"><?= h($r['verifier_email']) ?></a>
                            </div>
                            <?php if ($r['company_name']): ?>
                            <div class="text-muted" style="font-size:.78rem;"><?= h($r['company_name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['verifier_type'] === 'company'): ?>
                            <span class="badge bg-purple" style="background:#6f42c1;font-size:.75rem;"><i class="fas fa-building me-1"></i>Company</span>
                            <?php else: ?>
                            <span class="badge bg-info text-dark" style="font-size:.75rem;"><i class="fas fa-user-graduate me-1"></i>Student</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['student_found']): ?>
                            <span class="badge bg-success"><i class="fas fa-check me-1"></i>Found</span>
                            <?php else: ?>
                            <span class="badge bg-secondary"><i class="fas fa-times me-1"></i>Not Found</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:.82rem; color:#6b7280;"><?= h($r['ip_address'] ?? '—') ?></td>
                        <td style="font-size:.82rem; white-space:nowrap;">
                            <?= date('d M Y', strtotime($r['created_at'])) ?><br>
                            <span class="text-muted"><?= date('H:i', strtotime($r['created_at'])) ?></span>
                        </td>
                        <td class="text-end pe-4">
                            <div class="d-flex gap-1 justify-content-end">
                                <a href="<?= APP_URL ?>/cert-verifiers/view.php?id=<?= $r['id'] ?>"
                                   class="btn btn-sm btn-outline-info" title="View" style="border-radius:7px;">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if (is_super_admin() || can_access('cert-verifiers', 'can_delete')): ?>
                                <form method="POST" action="<?= APP_URL ?>/cert-verifiers/delete.php"
                                      onsubmit="return confirm('Delete this record?');" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
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
