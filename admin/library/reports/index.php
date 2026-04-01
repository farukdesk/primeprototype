<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../helpers.php';
require_access('library');
if (!lib_is_staff()) {
    flash_set('error', 'Access denied.');
    redirect(APP_URL . '/library/index.php');
}

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');

$pdo = db();

// Summary stats
$books_added = $pdo->prepare('SELECT COUNT(*) FROM library_books WHERE DATE(created_at) BETWEEN ? AND ?');
$books_added->execute([$from, $to]);
$books_added = (int)$books_added->fetchColumn();

$members_reg = $pdo->prepare('SELECT COUNT(*) FROM library_members WHERE DATE(created_at) BETWEEN ? AND ?');
$members_reg->execute([$from, $to]);
$members_reg = (int)$members_reg->fetchColumn();

$issues_count = $pdo->prepare('SELECT COUNT(*) FROM library_circulation WHERE DATE(issue_date) BETWEEN ? AND ?');
$issues_count->execute([$from, $to]);
$issues_count = (int)$issues_count->fetchColumn();

$returns_count = $pdo->prepare('SELECT COUNT(*) FROM library_circulation WHERE DATE(return_date) BETWEEN ? AND ?');
$returns_count->execute([$from, $to]);
$returns_count = (int)$returns_count->fetchColumn();

$fines_collected = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM library_fines WHERE status='Paid' AND DATE(paid_at) BETWEEN ? AND ?");
$fines_collected->execute([$from, $to]);
$fines_collected = (float)$fines_collected->fetchColumn();

$active_overdues = $pdo->query("SELECT COUNT(*) FROM library_circulation WHERE status='Overdue' OR (status='Issued' AND due_date < NOW())")->fetchColumn();

// Most Borrowed Books
$top_books = $pdo->query("
    SELECT b.title, b.author, b.isbn, COUNT(c.id) as borrow_count
    FROM library_books b
    JOIN library_circulation c ON c.book_id = b.id
    GROUP BY b.id ORDER BY borrow_count DESC LIMIT 10
")->fetchAll();

// Overdue Report
$overdues = $pdo->query("
    SELECT c.*, b.title as book_title, b.isbn, m.name as member_name, m.member_code, m.member_type,
           DATEDIFF(NOW(), c.due_date) as days_overdue,
           (DATEDIFF(NOW(), c.due_date) * CAST(ls.setting_val AS DECIMAL(10,2))) as fine_amount
    FROM library_circulation c
    JOIN library_books b ON b.id = c.book_id
    JOIN library_members m ON m.id = c.member_id
    JOIN library_settings ls ON ls.setting_key = 'fine_per_day'
    WHERE c.status = 'Overdue' OR (c.status = 'Issued' AND c.due_date < NOW())
    ORDER BY days_overdue DESC
")->fetchAll();

// Monthly Fines
$monthly_fines = $pdo->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') as month,
           SUM(CASE WHEN status='Paid' THEN amount ELSE 0 END) as paid,
           SUM(CASE WHEN status='Unpaid' THEN amount ELSE 0 END) as unpaid,
           COUNT(*) as total_fines
    FROM library_fines
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY month ORDER BY month ASC
")->fetchAll();

// Top Active Members
$top_members = $pdo->query("
    SELECT m.name, m.member_code, m.member_type, COUNT(c.id) as borrow_count,
           SUM(CASE WHEN c.status IN ('Issued','Overdue') THEN 1 ELSE 0 END) as active_borrows
    FROM library_members m
    JOIN library_circulation c ON c.member_id = m.id
    GROUP BY m.id ORDER BY borrow_count DESC LIMIT 10
")->fetchAll();

// Department-wise Borrowing
$dept_borrowing = $pdo->query("
    SELECT d.name as dept_name, COUNT(c.id) as borrow_count
    FROM departments d
    JOIN library_members m ON m.dept_id = d.id
    JOIN library_circulation c ON c.member_id = m.id
    GROUP BY d.id ORDER BY borrow_count DESC
")->fetchAll();

// Daily Transactions
$daily_tx = $pdo->query("
    SELECT DATE(issue_date) as day,
           COUNT(CASE WHEN status IN ('Issued','Overdue') OR return_date IS NOT NULL THEN 1 END) as issues,
           COUNT(CASE WHEN return_date IS NOT NULL THEN 1 END) as returns
    FROM library_circulation
    WHERE issue_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY day ORDER BY day DESC
")->fetchAll();

// New Books
$new_books = $pdo->query("
    SELECT b.title, b.author, b.isbn, b.publisher, b.pub_year, b.total_copies, c.name as category
    FROM library_books b
    LEFT JOIN library_categories c ON c.id = b.category_id
    WHERE b.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY b.created_at DESC LIMIT 20
")->fetchAll();

$page_title  = 'Library Reports';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/admin/'],
    ['label' => 'Library',   'url' => APP_URL . '/admin/library/'],
    ['label' => 'Reports'],
];
require_once __DIR__ . '/../../includes/header.php';
?>
<style>
    @media print {
        .no-print { display: none !important; }
        .card { break-inside: avoid; }
        body { font-size: 12px; }
    }
    .bar-cell { position: relative; }
    .bar-fill { display: inline-block; height: 14px; background: #0d6efd; border-radius: 3px; vertical-align: middle; }
    .stat-card { border-left: 4px solid; }
    .stat-card.blue   { border-color: #0d6efd; }
    .stat-card.green  { border-color: #198754; }
    .stat-card.orange { border-color: #fd7e14; }
    .stat-card.red    { border-color: #dc3545; }
    .stat-card.teal   { border-color: #20c997; }
    .stat-card.purple { border-color: #6f42c1; }
</style>

<div class="container-fluid py-3">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <h4 class="mb-0"><i class="fa fa-chart-bar me-2 text-primary"></i>Library Reports</h4>
        <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
            <i class="fa fa-print me-1"></i>Print
        </button>
    </div>

    <?php if ($flash = flash_get('error')): ?>
        <div class="alert alert-danger no-print"><?= h($flash) ?></div>
    <?php endif; ?>

    <!-- Date Filter -->
    <div class="card mb-4 no-print">
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-auto">
                    <label class="form-label mb-1 small fw-semibold">From</label>
                    <input type="date" name="from" class="form-control form-control-sm" value="<?= h($from) ?>">
                </div>
                <div class="col-auto">
                    <label class="form-label mb-1 small fw-semibold">To</label>
                    <input type="date" name="to" class="form-control form-control-sm" value="<?= h($to) ?>">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fa fa-filter me-1"></i>Filter
                    </button>
                    <a href="?" class="btn btn-outline-secondary btn-sm ms-1">Reset</a>
                </div>
                <div class="col-auto ms-auto text-muted small">
                    Showing: <strong><?= h($from) ?></strong> to <strong><?= h($to) ?></strong>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Stats -->
    <div class="row g-3 mb-4">
        <div class="col-md-4 col-lg-2">
            <div class="card stat-card blue h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-3 fw-bold text-primary"><?= $books_added ?></div>
                    <div class="small text-muted">Books Added</div>
                    <i class="fa fa-book text-primary opacity-25 fa-2x mt-1"></i>
                </div>
            </div>
        </div>
        <div class="col-md-4 col-lg-2">
            <div class="card stat-card green h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-3 fw-bold text-success"><?= $members_reg ?></div>
                    <div class="small text-muted">Members Registered</div>
                    <i class="fa fa-users text-success opacity-25 fa-2x mt-1"></i>
                </div>
            </div>
        </div>
        <div class="col-md-4 col-lg-2">
            <div class="card stat-card orange h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-3 fw-bold text-warning"><?= $issues_count ?></div>
                    <div class="small text-muted">Issues</div>
                    <i class="fa fa-arrow-up text-warning opacity-25 fa-2x mt-1"></i>
                </div>
            </div>
        </div>
        <div class="col-md-4 col-lg-2">
            <div class="card stat-card teal h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-3 fw-bold text-info"><?= $returns_count ?></div>
                    <div class="small text-muted">Returns</div>
                    <i class="fa fa-arrow-down text-info opacity-25 fa-2x mt-1"></i>
                </div>
            </div>
        </div>
        <div class="col-md-4 col-lg-2">
            <div class="card stat-card purple h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-3 fw-bold text-purple" style="color:#6f42c1">৳<?= number_format($fines_collected,2) ?></div>
                    <div class="small text-muted">Fines Collected</div>
                    <i class="fa fa-coins opacity-25 fa-2x mt-1" style="color:#6f42c1"></i>
                </div>
            </div>
        </div>
        <div class="col-md-4 col-lg-2">
            <div class="card stat-card red h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-3 fw-bold text-danger"><?= $active_overdues ?></div>
                    <div class="small text-muted">Active Overdues</div>
                    <i class="fa fa-exclamation-triangle text-danger opacity-25 fa-2x mt-1"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Most Borrowed Books -->
    <div class="card mb-4" id="rpt-top-books">
        <div class="card-header bg-white fw-semibold">
            <i class="fa fa-trophy text-warning me-2"></i>Most Borrowed Books (All Time – Top 10)
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>#</th><th>Title</th><th>Author</th><th>ISBN</th><th class="text-end">Borrows</th></tr>
                    </thead>
                    <tbody>
                        <?php if ($top_books): foreach ($top_books as $i => $row): ?>
                        <tr>
                            <td><?= $i+1 ?></td>
                            <td><?= h($row['title']) ?></td>
                            <td><?= h($row['author']) ?></td>
                            <td><?= h($row['isbn']) ?></td>
                            <td class="text-end"><span class="badge bg-primary"><?= $row['borrow_count'] ?></span></td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="5" class="text-center text-muted py-3">No data</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Overdue Report -->
    <div class="card mb-4" id="rpt-overdues">
        <div class="card-header bg-white fw-semibold">
            <i class="fa fa-exclamation-circle text-danger me-2"></i>Current Overdue Books
            <span class="badge bg-danger ms-2"><?= count($overdues) ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Member</th><th>Code</th><th>Type</th><th>Book</th><th>ISBN</th><th>Due Date</th><th class="text-end">Days OD</th><th class="text-end">Fine</th></tr>
                    </thead>
                    <tbody>
                        <?php if ($overdues): foreach ($overdues as $row): ?>
                        <tr>
                            <td><?= h($row['member_name']) ?></td>
                            <td><?= h($row['member_code']) ?></td>
                            <td><span class="badge bg-secondary"><?= h($row['member_type']) ?></span></td>
                            <td><?= h($row['book_title']) ?></td>
                            <td><?= h($row['isbn']) ?></td>
                            <td><?= h($row['due_date']) ?></td>
                            <td class="text-end text-danger fw-bold"><?= (int)$row['days_overdue'] ?></td>
                            <td class="text-end text-danger fw-bold">৳<?= number_format((float)$row['fine_amount'],2) ?></td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="8" class="text-center text-muted py-3">No overdues</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Monthly Fines -->
    <?php
    $max_fine = 0;
    foreach ($monthly_fines as $mf) $max_fine = max($max_fine, (float)$mf['paid'] + (float)$mf['unpaid']);
    ?>
    <div class="card mb-4" id="rpt-monthly-fines">
        <div class="card-header bg-white fw-semibold">
            <i class="fa fa-coins text-warning me-2"></i>Monthly Fines (Last 6 Months)
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Month</th><th class="text-end">Paid (৳)</th><th class="text-end">Unpaid (৳)</th><th class="text-end">Total</th><th style="min-width:150px">Visual</th></tr>
                    </thead>
                    <tbody>
                        <?php if ($monthly_fines): foreach ($monthly_fines as $mf):
                            $total_amt = (float)$mf['paid'] + (float)$mf['unpaid'];
                            $bar_pct = $max_fine > 0 ? round(($total_amt / $max_fine) * 100) : 0;
                        ?>
                        <tr>
                            <td><?= h($mf['month']) ?></td>
                            <td class="text-end text-success">৳<?= number_format((float)$mf['paid'],2) ?></td>
                            <td class="text-end text-danger">৳<?= number_format((float)$mf['unpaid'],2) ?></td>
                            <td class="text-end"><?= (int)$mf['total_fines'] ?></td>
                            <td class="bar-cell">
                                <span class="bar-fill" style="width:<?= $bar_pct ?>%"></span>
                                <small class="ms-1"><?= $bar_pct ?>%</small>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="5" class="text-center text-muted py-3">No data</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Top Active Members -->
    <div class="card mb-4" id="rpt-top-members">
        <div class="card-header bg-white fw-semibold">
            <i class="fa fa-star text-warning me-2"></i>Top Active Members (Top 10)
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>#</th><th>Name</th><th>Code</th><th>Type</th><th class="text-end">Total Borrows</th><th class="text-end">Active</th></tr>
                    </thead>
                    <tbody>
                        <?php if ($top_members): foreach ($top_members as $i => $row): ?>
                        <tr>
                            <td><?= $i+1 ?></td>
                            <td><?= h($row['name']) ?></td>
                            <td><?= h($row['member_code']) ?></td>
                            <td><span class="badge bg-info text-dark"><?= h($row['member_type']) ?></span></td>
                            <td class="text-end"><span class="badge bg-primary"><?= $row['borrow_count'] ?></span></td>
                            <td class="text-end"><span class="badge bg-success"><?= $row['active_borrows'] ?></span></td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="6" class="text-center text-muted py-3">No data</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Department-wise Borrowing -->
    <div class="card mb-4" id="rpt-dept">
        <div class="card-header bg-white fw-semibold">
            <i class="fa fa-building text-secondary me-2"></i>Department-wise Borrowing
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>#</th><th>Department</th><th class="text-end">Borrow Count</th></tr>
                    </thead>
                    <tbody>
                        <?php if ($dept_borrowing): foreach ($dept_borrowing as $i => $row): ?>
                        <tr>
                            <td><?= $i+1 ?></td>
                            <td><?= h($row['dept_name']) ?></td>
                            <td class="text-end"><span class="badge bg-primary"><?= $row['borrow_count'] ?></span></td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="3" class="text-center text-muted py-3">No data</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Daily Transactions -->
    <div class="card mb-4" id="rpt-daily">
        <div class="card-header bg-white fw-semibold">
            <i class="fa fa-calendar-alt text-info me-2"></i>Daily Transactions (Last 30 Days)
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Date</th><th class="text-end">Issues</th><th class="text-end">Returns</th></tr>
                    </thead>
                    <tbody>
                        <?php if ($daily_tx): foreach ($daily_tx as $row): ?>
                        <tr>
                            <td><?= h($row['day']) ?></td>
                            <td class="text-end"><span class="badge bg-primary"><?= $row['issues'] ?></span></td>
                            <td class="text-end"><span class="badge bg-success"><?= $row['returns'] ?></span></td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="3" class="text-center text-muted py-3">No data</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- New Acquisitions -->
    <div class="card mb-4" id="rpt-new-books">
        <div class="card-header bg-white fw-semibold">
            <i class="fa fa-plus-circle text-success me-2"></i>New Acquisitions (Last 30 Days)
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Title</th><th>Author</th><th>ISBN</th><th>Publisher</th><th>Year</th><th>Category</th><th class="text-end">Copies</th></tr>
                    </thead>
                    <tbody>
                        <?php if ($new_books): foreach ($new_books as $row): ?>
                        <tr>
                            <td><?= h($row['title']) ?></td>
                            <td><?= h($row['author']) ?></td>
                            <td><?= h($row['isbn']) ?></td>
                            <td><?= h($row['publisher']) ?></td>
                            <td><?= h($row['pub_year']) ?></td>
                            <td><span class="badge bg-secondary"><?= h($row['category'] ?? '—') ?></span></td>
                            <td class="text-end"><?= (int)$row['total_copies'] ?></td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="7" class="text-center text-muted py-3">No new books in last 30 days</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div><!-- /container-fluid -->
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
