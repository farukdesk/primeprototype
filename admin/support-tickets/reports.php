<?php
require_once __DIR__ . '/../includes/auth.php';
require_super_admin();
require_once __DIR__ . '/helpers.php';

$page_title = 'IT Support – Reports';

// ── Overall stats ─────────────────────────────────────────────────────────────
$overall = db()->query(
    "SELECT
        COUNT(*) AS total,
        SUM(status = 'Open')         AS open_count,
        SUM(status = 'In Progress')  AS in_progress,
        SUM(status = 'Pending')      AS pending_count,
        SUM(status = 'Resolved')     AS resolved_count,
        SUM(status = 'Closed')       AS closed_count,
        SUM(status = 'Reopened')     AS reopened_count,
        SUM(
            deadline IS NOT NULL
            AND deadline < NOW()
            AND status NOT IN ('Resolved','Closed')
        ) AS overdue_count,
        ROUND(
            AVG(CASE WHEN resolved_at IS NOT NULL
                THEN TIMESTAMPDIFF(HOUR, created_at, resolved_at) END), 1
        ) AS avg_resolve_hours
     FROM support_tickets"
)->fetch();

// ── By category ───────────────────────────────────────────────────────────────
$by_category = db()->query(
    "SELECT category, COUNT(*) AS cnt
     FROM support_tickets
     GROUP BY category
     ORDER BY cnt DESC"
)->fetchAll();

// ── By priority ───────────────────────────────────────────────────────────────
$by_priority = db()->query(
    "SELECT priority, COUNT(*) AS cnt
     FROM support_tickets
     GROUP BY priority
     ORDER BY FIELD(priority,'Critical','High','Medium','Low')"
)->fetchAll();

// ── By status ─────────────────────────────────────────────────────────────────
$by_status = db()->query(
    "SELECT status, COUNT(*) AS cnt
     FROM support_tickets
     GROUP BY status
     ORDER BY FIELD(status,'Open','In Progress','Pending','Reopened','Resolved','Closed')"
)->fetchAll();

// ── Monthly trend – last 6 months ─────────────────────────────────────────────
$monthly = db()->query(
    "SELECT DATE_FORMAT(created_at,'%b %Y') AS month_label,
            DATE_FORMAT(created_at,'%Y-%m') AS month_sort,
            COUNT(*) AS created_cnt,
            SUM(status IN ('Resolved','Closed')) AS resolved_cnt
     FROM support_tickets
     WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
     GROUP BY month_sort, month_label
     ORDER BY month_sort ASC"
)->fetchAll();

// ── Staff performance ─────────────────────────────────────────────────────────
$staff_perf = db()->query(
    "SELECT u.full_name,
            COUNT(t.id)                               AS assigned_total,
            SUM(t.status IN ('Resolved','Closed'))    AS resolved_total,
            ROUND(AVG(CASE WHEN t.resolved_at IS NOT NULL
                THEN TIMESTAMPDIFF(HOUR, t.created_at, t.resolved_at) END), 1) AS avg_hours
     FROM support_tickets t
     JOIN users u ON u.id = t.assigned_to
     WHERE t.assigned_to IS NOT NULL
     GROUP BY t.assigned_to, u.full_name
     ORDER BY assigned_total DESC
     LIMIT 20"
)->fetchAll();

// ── Recent overdue tickets ─────────────────────────────────────────────────────
$overdue_tickets = db()->query(
    "SELECT t.*, u.full_name AS creator_name, a.full_name AS assignee_name
     FROM support_tickets t
     JOIN users u ON u.id = t.created_by
     LEFT JOIN users a ON a.id = t.assigned_to
     WHERE t.deadline IS NOT NULL
       AND t.deadline < NOW()
       AND t.status NOT IN ('Resolved','Closed')
     ORDER BY t.deadline ASC
     LIMIT 20"
)->fetchAll();

// ── SLA compliance rate ───────────────────────────────────────────────────────
$sla = db()->query(
    "SELECT
        COUNT(*) AS total_resolved,
        SUM(resolved_at <= deadline) AS within_sla
     FROM support_tickets
     WHERE resolved_at IS NOT NULL AND deadline IS NOT NULL"
)->fetch();
$sla_rate = ($sla['total_resolved'] > 0)
    ? round(($sla['within_sla'] / $sla['total_resolved']) * 100, 1)
    : null;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/support-tickets/index.php">IT Support</a></li>
            <li class="breadcrumb-item active">Reports</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/support-tickets/index.php" class="btn btn-outline-secondary btn-sm" style="border-radius:8px;">
        <i class="fas fa-arrow-left me-1"></i> Back to Tickets
    </a>
</div>

<!-- KPI row -->
<div class="row g-3 mb-4">
    <?php
    $kpis = [
        ['label' => 'Total Tickets',     'value' => $overall['total'],         'color' => '#4f8ef7', 'icon' => 'fas fa-ticket-alt'],
        ['label' => 'Open / Reopened',   'value' => ($overall['open_count'] + $overall['reopened_count']), 'color' => '#0d6efd', 'icon' => 'fas fa-folder-open'],
        ['label' => 'In Progress',       'value' => $overall['in_progress'],   'color' => '#0dcaf0', 'icon' => 'fas fa-spinner'],
        ['label' => 'Resolved / Closed', 'value' => ($overall['resolved_count'] + $overall['closed_count']), 'color' => '#198754', 'icon' => 'fas fa-check-circle'],
        ['label' => 'Overdue',           'value' => $overall['overdue_count'], 'color' => '#dc3545', 'icon' => 'fas fa-exclamation-triangle'],
        ['label' => 'Avg Resolve Time',  'value' => ($overall['avg_resolve_hours'] !== null ? $overall['avg_resolve_hours'] . 'h' : '—'), 'color' => '#6f42c1', 'icon' => 'fas fa-clock'],
        ['label' => 'SLA Compliance',    'value' => ($sla_rate !== null ? $sla_rate . '%' : '—'),  'color' => '#20c997', 'icon' => 'fas fa-shield-alt'],
        ['label' => 'Pending',           'value' => $overall['pending_count'], 'color' => '#fd7e14', 'icon' => 'fas fa-pause-circle'],
    ];
    ?>
    <?php foreach ($kpis as $kpi): ?>
    <div class="col-6 col-md-3">
        <div class="card h-100" style="border-radius:12px;border-left:4px solid <?= $kpi['color'] ?>;">
            <div class="card-body py-3 px-3">
                <div class="d-flex align-items-center gap-2">
                    <div style="width:34px;height:34px;border-radius:8px;background:<?= $kpi['color'] ?>20;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="<?= $kpi['icon'] ?>" style="color:<?= $kpi['color'] ?>;font-size:.85rem;"></i>
                    </div>
                    <div>
                        <div style="font-size:1.4rem;font-weight:700;color:<?= $kpi['color'] ?>;line-height:1.1;"><?= $kpi['value'] ?></div>
                        <div class="text-muted" style="font-size:.75rem;"><?= $kpi['label'] ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="row g-4 mb-4">

    <!-- By Category -->
    <div class="col-md-4">
        <div class="card h-100" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-tags me-2 text-muted"></i>By Category</h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0" style="font-size:.875rem;">
                    <thead class="table-light">
                        <tr><th class="px-4">Category</th><th class="px-4 text-end">Count</th><th class="px-4 text-end">%</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($by_category as $row): ?>
                    <?php $pct = $overall['total'] > 0 ? round($row['cnt'] / $overall['total'] * 100, 1) : 0; ?>
                    <tr>
                        <td class="px-4"><?= h($row['category']) ?></td>
                        <td class="px-4 text-end fw-semibold"><?= $row['cnt'] ?></td>
                        <td class="px-4 text-end text-muted"><?= $pct ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($by_category)): ?>
                    <tr><td colspan="3" class="text-center text-muted px-4 py-3">No data yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- By Priority -->
    <div class="col-md-4">
        <div class="card h-100" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-flag me-2 text-muted"></i>By Priority</h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0" style="font-size:.875rem;">
                    <thead class="table-light">
                        <tr><th class="px-4">Priority</th><th class="px-4 text-end">Count</th><th class="px-4 text-end">%</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($by_priority as $row): ?>
                    <?php $pct = $overall['total'] > 0 ? round($row['cnt'] / $overall['total'] * 100, 1) : 0; ?>
                    <tr>
                        <td class="px-4"><?= st_priority_badge($row['priority']) ?></td>
                        <td class="px-4 text-end fw-semibold"><?= $row['cnt'] ?></td>
                        <td class="px-4 text-end text-muted"><?= $pct ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($by_priority)): ?>
                    <tr><td colspan="3" class="text-center text-muted px-4 py-3">No data yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- By Status -->
    <div class="col-md-4">
        <div class="card h-100" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-tasks me-2 text-muted"></i>By Status</h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0" style="font-size:.875rem;">
                    <thead class="table-light">
                        <tr><th class="px-4">Status</th><th class="px-4 text-end">Count</th><th class="px-4 text-end">%</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($by_status as $row): ?>
                    <?php $pct = $overall['total'] > 0 ? round($row['cnt'] / $overall['total'] * 100, 1) : 0; ?>
                    <tr>
                        <td class="px-4"><?= st_status_badge($row['status']) ?></td>
                        <td class="px-4 text-end fw-semibold"><?= $row['cnt'] ?></td>
                        <td class="px-4 text-end text-muted"><?= $pct ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($by_status)): ?>
                    <tr><td colspan="3" class="text-center text-muted px-4 py-3">No data yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Monthly trend -->
<?php if ($monthly): ?>
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-chart-line me-2 text-muted"></i>Monthly Trend (Last 6 Months)</h6>
    </div>
    <div class="card-body p-4">
        <canvas id="monthlyChart" height="90"></canvas>
    </div>
</div>
<?php endif; ?>

<!-- Staff performance -->
<?php if ($staff_perf): ?>
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-user-cog me-2 text-muted"></i>Staff Performance</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:.875rem;">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">Staff Member</th>
                        <th class="px-4 text-end">Assigned</th>
                        <th class="px-4 text-end">Resolved/Closed</th>
                        <th class="px-4 text-end">Resolution Rate</th>
                        <th class="px-4 text-end">Avg Resolve Time</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($staff_perf as $sp): ?>
                <?php $rate = $sp['assigned_total'] > 0
                    ? round($sp['resolved_total'] / $sp['assigned_total'] * 100, 1) : 0; ?>
                <tr>
                    <td class="px-4"><?= h($sp['full_name']) ?></td>
                    <td class="px-4 text-end"><?= $sp['assigned_total'] ?></td>
                    <td class="px-4 text-end"><?= $sp['resolved_total'] ?></td>
                    <td class="px-4 text-end">
                        <div class="d-flex align-items-center justify-content-end gap-2">
                            <div class="progress flex-grow-1" style="height:6px;max-width:80px;border-radius:3px;">
                                <div class="progress-bar bg-success" style="width:<?= $rate ?>%"></div>
                            </div>
                            <span><?= $rate ?>%</span>
                        </div>
                    </td>
                    <td class="px-4 text-end"><?= $sp['avg_hours'] !== null ? $sp['avg_hours'] . 'h' : '—' ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Overdue tickets -->
<?php if ($overdue_tickets): ?>
<div class="card" style="border-radius:12px;">
    <div class="card-header py-3 px-4 d-flex align-items-center gap-2">
        <h6 class="mb-0 fw-semibold text-danger">
            <i class="fas fa-exclamation-triangle me-2"></i>Overdue Tickets (<?= count($overdue_tickets) ?>)
        </h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:.875rem;">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">Ticket #</th>
                        <th class="px-4">Title</th>
                        <th class="px-4">Priority</th>
                        <th class="px-4">Submitted by</th>
                        <th class="px-4">Assigned to</th>
                        <th class="px-4">Deadline</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($overdue_tickets as $ot): ?>
                <tr style="background:#fff8f8;">
                    <td class="px-4">
                        <a href="<?= APP_URL ?>/support-tickets/view.php?id=<?= $ot['id'] ?>"
                           class="text-decoration-none fw-semibold text-danger">
                            <?= h($ot['ticket_number']) ?>
                        </a>
                    </td>
                    <td class="px-4"><?= h($ot['title']) ?></td>
                    <td class="px-4"><?= st_priority_badge($ot['priority']) ?></td>
                    <td class="px-4"><?= h($ot['creator_name']) ?></td>
                    <td class="px-4"><?= $ot['assignee_name'] ? h($ot['assignee_name']) : '<span class="text-muted">Unassigned</span>' ?></td>
                    <td class="px-4 text-danger fw-semibold" style="font-size:.82rem;">
                        <?= date('M d, Y H:i', strtotime($ot['deadline'])) ?>
                        <?php
                        $hours_late = (int)((time() - strtotime($ot['deadline'])) / 3600);
                        echo '<br><small>' . $hours_late . 'h overdue</small>';
                        ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($monthly): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function () {
    const labels   = <?= json_encode(array_column($monthly, 'month_label')) ?>;
    const created  = <?= json_encode(array_map('intval', array_column($monthly, 'created_cnt'))) ?>;
    const resolved = <?= json_encode(array_map('intval', array_column($monthly, 'resolved_cnt'))) ?>;

    new Chart(document.getElementById('monthlyChart'), {
        type: 'bar',
        data: {
            labels,
            datasets: [
                {
                    label: 'Created',
                    data: created,
                    backgroundColor: 'rgba(79,142,247,0.7)',
                    borderColor: '#4f8ef7',
                    borderWidth: 1,
                    borderRadius: 4,
                },
                {
                    label: 'Resolved/Closed',
                    data: resolved,
                    backgroundColor: 'rgba(25,135,84,0.7)',
                    borderColor: '#198754',
                    borderWidth: 1,
                    borderRadius: 4,
                },
            ],
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'top' } },
            scales: {
                y: { beginAtZero: true, ticks: { precision: 0 } },
            },
        },
    });
}());
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
