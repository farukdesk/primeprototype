<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('leads');
require_once __DIR__ . '/helpers.php';

$page_title = 'FB Messenger Analytics';
$user       = auth_user();

// ── Custom date range filter (defaults to the last 30 days) ────────────
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to'] ?? '');
if ($date_from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = '';
if ($date_to   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = '';
if ($date_from !== '' && $date_to !== '' && $date_from > $date_to) {
    [$date_from, $date_to] = [$date_to, $date_from];
}
$range_filtered = ($date_from !== '' || $date_to !== '');

$from_date = $date_from !== '' ? $date_from : date('Y-m-d', strtotime('-29 days'));
$to_date   = $date_to   !== '' ? $date_to   : date('Y-m-d');
if ($from_date > $to_date) $from_date = $to_date;
// Keep the daily chart usable – cap the range at 366 days
if ((strtotime($to_date) - strtotime($from_date)) > 366 * 86400) {
    $from_date = date('Y-m-d', strtotime($to_date . ' -366 days'));
}

$since       = $from_date . ' 00:00:00';
$until       = $to_date   . ' 23:59:59';
$range_label = date('d M Y', strtotime($from_date)) . ' – ' . date('d M Y', strtotime($to_date));

// ── Totals (selected range) ────────────────────────────────────────────
$tq = db()->prepare('SELECT direction, COUNT(*) AS cnt FROM lead_fb_messages WHERE created_at BETWEEN ? AND ? GROUP BY direction');
$tq->execute([$since, $until]);
$totals    = $tq->fetchAll(PDO::FETCH_KEY_PAIR);
$in_total  = (int)($totals['in'] ?? 0);
$out_total = (int)($totals['out'] ?? 0);

$ncq = db()->prepare('SELECT COUNT(*) FROM lead_fb_contacts WHERE first_seen BETWEEN ? AND ?');
$ncq->execute([$since, $until]);
$new_contacts = (int)$ncq->fetchColumn();

$lkq = db()->prepare('SELECT COUNT(*) FROM lead_fb_contacts WHERE lead_id IS NOT NULL AND first_seen BETWEEN ? AND ?');
$lkq->execute([$since, $until]);
$linked_total = (int)$lkq->fetchColumn();

// ── Messages per day ────────────────────────────────────────────────────
$dq = db()->prepare(
    "SELECT DATE(created_at) AS d,
            SUM(direction = 'in')  AS incoming,
            SUM(direction = 'out') AS outgoing
     FROM lead_fb_messages WHERE created_at BETWEEN ? AND ? GROUP BY DATE(created_at)"
);
$dq->execute([$since, $until]);
$per_day_raw = [];
foreach ($dq->fetchAll() as $r) { $per_day_raw[$r['d']] = $r; }

$day_labels = $day_in = $day_out = [];
for ($ts = strtotime($from_date); $ts <= strtotime($to_date); $ts = strtotime('+1 day', $ts)) {
    $d = date('Y-m-d', $ts);
    $day_labels[] = date('d M', $ts);
    $day_in[]  = (int)($per_day_raw[$d]['incoming'] ?? 0);
    $day_out[] = (int)($per_day_raw[$d]['outgoing'] ?? 0);
}

// ── Peak hours (incoming) ───────────────────────────────────────────────
$hq = db()->prepare("SELECT HOUR(created_at) AS h, COUNT(*) AS cnt FROM lead_fb_messages WHERE created_at BETWEEN ? AND ? AND direction = 'in' GROUP BY HOUR(created_at)");
$hq->execute([$since, $until]);
$hour_raw    = $hq->fetchAll(PDO::FETCH_KEY_PAIR);
$hour_labels = $hour_counts = [];
for ($h = 0; $h < 24; $h++) {
    $hour_labels[] = date('g A', mktime($h, 0));
    $hour_counts[] = (int)($hour_raw[$h] ?? 0);
}

// ── Replies per agent ───────────────────────────────────────────────────
$aq = db()->prepare(
    "SELECT COALESCE(u.full_name, 'Auto-reply') AS agent, COUNT(*) AS cnt
     FROM lead_fb_messages m LEFT JOIN users u ON u.id = m.sent_by
     WHERE m.direction = 'out' AND m.created_at BETWEEN ? AND ?
     GROUP BY m.sent_by ORDER BY cnt DESC LIMIT 10"
);
$aq->execute([$since, $until]);
$agents       = $aq->fetchAll();
$agent_labels = array_column($agents, 'agent');
$agent_counts = array_map('intval', array_column($agents, 'cnt'));

// ── Average first-response time ──────────────────────────────────────────
$rq = db()->prepare('SELECT contact_id, direction, created_at FROM lead_fb_messages WHERE created_at BETWEEN ? AND ? ORDER BY contact_id ASC, id ASC');
$rq->execute([$since, $until]);
$pending = [];
$diffs   = [];
foreach ($rq->fetchAll() as $m) {
    $cid = $m['contact_id'];
    if ($m['direction'] === 'in') {
        if (!isset($pending[$cid])) $pending[$cid] = strtotime($m['created_at']);
    } elseif (isset($pending[$cid])) {
        $diffs[] = strtotime($m['created_at']) - $pending[$cid];
        unset($pending[$cid]);
    }
}
$avg_response = $diffs ? array_sum($diffs) / count($diffs) : null;

function fban_duration(?float $sec): string
{
    if ($sec === null)  return '–';
    if ($sec < 60)      return round($sec) . 's';
    if ($sec < 3600)    return round($sec / 60) . 'm';
    if ($sec < 86400)   return round($sec / 3600, 1) . 'h';
    return round($sec / 86400, 1) . 'd';
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-chart-line me-2 text-info"></i>FB Messenger Analytics <span class="text-muted fs-6">(<?= h($range_label) ?>)</span></h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/leads/fb-inbox.php">FB Inbox</a></li>
            <li class="breadcrumb-item active">Analytics</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/leads/fb-inbox.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back to Inbox</a>
</div>

<!-- Date range filter -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label small text-muted mb-1">From date</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= h($from_date) ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small text-muted mb-1">To date</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= h($to_date) ?>">
            </div>
            <div class="col-6 col-md-2">
                <button type="submit" class="btn btn-sm btn-primary w-100"><i class="fas fa-filter me-1"></i> Apply</button>
            </div>
            <?php if ($range_filtered): ?>
            <div class="col-6 col-md-2">
                <a href="<?= APP_URL ?>/leads/fb-analytics.php" class="btn btn-sm btn-outline-secondary w-100">Reset (last 30 days)</a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Stat cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm h-100"><div class="card-body p-3 d-flex align-items-center gap-2">
            <div class="rounded-3 p-2" style="background:#e7f3ff"><i class="fas fa-inbox fa-lg" style="color:#1877F2"></i></div>
            <div><div class="fw-bold fs-5"><?= number_format($in_total) ?></div><div class="text-muted small">Incoming</div></div>
        </div></div>
    </div>
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm h-100"><div class="card-body p-3 d-flex align-items-center gap-2">
            <div class="rounded-3 p-2" style="background:#e8f5e9"><i class="fas fa-paper-plane fa-lg text-success"></i></div>
            <div><div class="fw-bold fs-5"><?= number_format($out_total) ?></div><div class="text-muted small">Replies Sent</div></div>
        </div></div>
    </div>
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm h-100"><div class="card-body p-3 d-flex align-items-center gap-2">
            <div class="rounded-3 p-2" style="background:#fff8e1"><i class="fas fa-stopwatch fa-lg text-warning"></i></div>
            <div><div class="fw-bold fs-5"><?= h(fban_duration($avg_response)) ?></div><div class="text-muted small">Avg First Response</div></div>
        </div></div>
    </div>
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm h-100"><div class="card-body p-3 d-flex align-items-center gap-2">
            <div class="rounded-3 p-2" style="background:#e0f7fa"><i class="fas fa-user-plus fa-lg text-info"></i></div>
            <div><div class="fw-bold fs-5"><?= number_format($new_contacts) ?></div><div class="text-muted small">New Contacts</div></div>
        </div></div>
    </div>
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm h-100"><div class="card-body p-3 d-flex align-items-center gap-2">
            <div class="rounded-3 p-2" style="background:#f3e5f5"><i class="fas fa-link fa-lg" style="color:#6f42c1"></i></div>
            <div><div class="fw-bold fs-5"><?= number_format($linked_total) ?></div><div class="text-muted small">Linked to Leads</div></div>
        </div></div>
    </div>
</div>

<div class="row g-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="fas fa-chart-area me-2 text-primary"></i>Message Volume (daily)</div>
            <div class="card-body"><canvas id="chart-daily" height="90"></canvas></div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="fas fa-clock me-2 text-warning"></i>Peak Hours (incoming)</div>
            <div class="card-body"><canvas id="chart-hours" height="180"></canvas></div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="fas fa-headset me-2 text-success"></i>Replies per Agent</div>
            <div class="card-body">
                <?php if ($agent_labels): ?>
                <canvas id="chart-agents" height="180"></canvas>
                <?php else: ?>
                <p class="text-muted small mb-0">No outbound replies in the last 30 days.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    if (typeof Chart === 'undefined') return;

    new Chart(document.getElementById('chart-daily'), {
        type: 'line',
        data: {
            labels: <?= json_encode($day_labels) ?>,
            datasets: [
                { label: 'Incoming', data: <?= json_encode($day_in) ?>, borderColor: '#1877F2', backgroundColor: 'rgba(24,119,242,.12)', fill: true, tension: .3 },
                { label: 'Replies',  data: <?= json_encode($day_out) ?>, borderColor: '#198754', backgroundColor: 'rgba(25,135,84,.10)', fill: true, tension: .3 }
            ]
        },
        options: { plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
    });

    new Chart(document.getElementById('chart-hours'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($hour_labels) ?>,
            datasets: [{ label: 'Incoming messages', data: <?= json_encode($hour_counts) ?>, backgroundColor: '#fd7e14' }]
        },
        options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
    });

    <?php if ($agent_labels): ?>
    new Chart(document.getElementById('chart-agents'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($agent_labels) ?>,
            datasets: [{ label: 'Replies sent', data: <?= json_encode($agent_counts) ?>, backgroundColor: '#198754' }]
        },
        options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } }
    });
    <?php endif; ?>
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
