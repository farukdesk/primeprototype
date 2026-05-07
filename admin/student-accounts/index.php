<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('student-accounts');
require_once __DIR__ . '/helpers.php';

$page_title = 'Student Accounts';
$db         = db();

// ── Filters ───────────────────────────────────────────────────────────────────
$search = trim($_GET['q'] ?? '');

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[]  = '(s.full_name LIKE ? OR s.student_id LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_sql = implode(' AND ', $where);

// ── Pagination ────────────────────────────────────────────────────────────────
$per_page = 25;
$page     = max(1, (int)($_GET['page'] ?? 1));

$cnt_stmt = $db->prepare(
    "SELECT COUNT(*)
     FROM sfp_packages p
     JOIN students s ON s.id = p.student_id
     WHERE $where_sql"
);
$cnt_stmt->execute($params);
$total = (int)$cnt_stmt->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));
$page  = min($page, $pages);
$off   = ($page - 1) * $per_page;

$stmt = $db->prepare(
    "SELECT p.*,
            s.full_name    AS student_name,
            s.student_id   AS student_sid,
            s.admitted_semester,
            s.status       AS student_status,
            sf1.tuition_payable        AS current_tuition_payable,
            sf1.fixed_discount_amount  AS current_fixed_discount,
            sf1.english_discount_amount AS current_english_discount
     FROM sfp_packages p
      JOIN students s ON s.id = p.student_id
      LEFT JOIN sfp_semester_fees sf1
             ON sf1.package_id = p.id
            AND sf1.semester_number = 1
      WHERE $where_sql
      ORDER BY p.created_at DESC
      LIMIT $per_page OFFSET $off"
);
$stmt->execute($params);
$packages = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-file-invoice-dollar me-2 text-success"></i>Student Accounts</h1>
        <p class="text-muted mb-0 small">Snapshotted fee structures assigned to students.</p>
    </div>
    <?php if (sfp_can_create()): ?>
    <a href="<?= APP_URL ?>/student-accounts/create.php" class="btn btn-success btn-sm">
        <i class="fas fa-plus me-1"></i> Assign Package
    </a>
    <?php endif; ?>
</div>

<?= flash_show() ?>

<!-- ── Search bar ── -->
<div class="card mb-4">
    <div class="card-body py-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-5">
                <input type="text" name="q" class="form-control" placeholder="Search by student name or ID…"
                       value="<?= h($search) ?>">
            </div>
            <div class="col-auto">
                <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-search me-1"></i>Search</button>
                <?php if ($search !== ''): ?>
                <a href="<?= APP_URL ?>/student-accounts/index.php" class="btn btn-outline-secondary btn-sm">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- ── Table ── -->
<div class="card">
    <div class="card-body p-0">
        <?php if (empty($packages)): ?>
        <div class="text-center text-muted py-5">
            <i class="fas fa-file-invoice-dollar fa-3x mb-3 opacity-25"></i>
            <p class="mb-0">No student accounts found.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Programme</th>
                        <th>Semesters</th>
                        <th>Tuition / Sem</th>
                        <th>Monthly Fixed</th>
                        <th>Assigned</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($packages as $pkg): ?>
                <tr>
                    <td>
                        <a href="<?= APP_URL ?>/students/view.php?id=<?= $pkg['student_id'] ?>"
                           class="fw-semibold text-decoration-none">
                            <?= h($pkg['student_name']) ?>
                        </a><br>
                        <small class="text-muted"><?= h($pkg['student_sid']) ?></small>
                    </td>
                    <td>
                        <?= h($pkg['program_name']) ?>
                    </td>
                    <?php
                    $months = (float)($pkg['total_months'] ?? 0);
                    $mps    = (float)($pkg['months_per_semester'] ?? 0);
                    $reg    = (float)($pkg['reg_fee_per_semester'] ?? 0);
                    $has_semester_months = ($months > 0 && $mps > 0);

                    $fixed_per_sem = $has_semester_months
                        ? round((float)$pkg['fixed_institutional_fees'] / $months * $mps, 2)
                        : 0.0;
                    $english_per_sem = $has_semester_months
                        ? round((float)$pkg['english_course_fee'] / $months * $mps, 2)
                        : 0.0;

                    $fixed_after_discount = max(0.0, $fixed_per_sem - (float)($pkg['current_fixed_discount'] ?? 0));
                    $english_after_discount = max(0.0, $english_per_sem - (float)($pkg['current_english_discount'] ?? 0));
                    $tuition_current = (float)($pkg['current_tuition_payable'] ?? $pkg['tuition_per_semester'] ?? 0);

                    $current_sem_total = $tuition_current + $fixed_after_discount + $english_after_discount + $reg;
                    $current_monthly_total = ($mps > 0) ? ($current_sem_total / $mps) : $current_sem_total;
                    ?>
                    <td class="text-center"><?= (int)$pkg['total_semesters'] ?></td>
                    <td><?= sfp_money($current_sem_total) ?></td>
                    <td><?= sfp_money($current_monthly_total) ?></td>
                    <td>
                        <small class="text-muted"><?= date('d M Y', strtotime($pkg['created_at'])) ?></small>
                    </td>
                    <td class="text-end">
                        <a href="<?= APP_URL ?>/student-accounts/view.php?id=<?= $pkg['id'] ?>"
                           class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-eye me-1"></i>View
                        </a>
                        <a href="<?= APP_URL ?>/student-accounts/statement.php?id=<?= $pkg['id'] ?>"
                           class="btn btn-outline-success btn-sm" target="_blank">
                            <i class="fas fa-file-invoice me-1"></i>Statement
                        </a>
                        <?php if (sfp_can_delete()): ?>
                        <form method="post" action="<?= APP_URL ?>/student-accounts/delete.php"
                              class="d-inline"
                              onsubmit="return confirm('Delete this student account? This cannot be undone.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $pkg['id'] ?>">
                            <button class="btn btn-outline-danger btn-sm">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($pages > 1): ?>
        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top">
            <small class="text-muted">
                Page <?= $page ?> of <?= $pages ?> &middot; <?= $total ?> total
            </small>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php for ($p = 1; $p <= $pages; $p++): ?>
                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                        <a class="page-link"
                           href="?<?= http_build_query(['q' => $search, 'page' => $p]) ?>">
                            <?= $p ?>
                        </a>
                    </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
