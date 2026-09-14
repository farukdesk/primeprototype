<?php
/**
 * SS Portal – overview of local student records and their registration status
 * at Prime University.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/reference_data.php';

$user = ssp_require_login();
$db   = ssp_db();

$q      = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 25;

$where = [];
$args  = [];
if ($q !== '') {
    $where[] = '(full_name LIKE ? OR reference_no LIKE ? OR pu_student_id LIKE ? OR email LIKE ? OR contact_no LIKE ?)';
    $like    = '%' . $q . '%';
    array_push($args, $like, $like, $like, $like, $like);
}
if (in_array($status, ['draft', 'pending', 'synced', 'failed', 'deleted'], true)) {
    $where[] = 'sync_status = ?';
    $args[]  = $status;
} elseif ($status === 'update_pending') {
    $where[] = 'sync_status = "synced" AND pending_update = 1';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$st = $db->prepare('SELECT COUNT(*) FROM ssp_students' . $whereSql);
$st->execute($args);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$page  = min($page, $pages);

$st = $db->prepare('SELECT id, reference_no, full_name, department_label, program_label, admitted_semester, sync_status, pending_update,
                           pu_student_id, pu_status, pu_result_id, created_at, synced_at, deleted_at
                      FROM ssp_students' . $whereSql . '
                     ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
$st->execute($args);
$rows = $st->fetchAll();

$stats = ['draft' => 0, 'pending' => 0, 'synced' => 0, 'failed' => 0, 'deleted' => 0, 'update_pending' => 0];
foreach ($db->query('SELECT sync_status, COUNT(*) AS c FROM ssp_students GROUP BY sync_status') as $r) {
    $stats[$r['sync_status']] = (int)$r['c'];
}
$allCount = $stats['draft'] + $stats['pending'] + $stats['synced'] + $stats['failed'] + $stats['deleted'];
$stats['update_pending'] = (int)$db->query('SELECT COUNT(*) FROM ssp_students WHERE sync_status = "synced" AND pending_update = 1')->fetchColumn();

$ref = ssp_reference_data();

$statusLabels = ['draft' => 'Draft', 'pending' => 'Sending', 'synced' => 'Registered at PU', 'update_pending' => 'Update pending', 'failed' => 'Failed', 'deleted' => 'Deleted'];
$filtering    = $q !== '' || $status !== '';
$pageUrl      = static function (int $p) use ($q, $status): string {
    return ssp_url('dashboard.php?' . http_build_query(array_filter(['q' => $q, 'status' => $status, 'page' => $p > 1 ? $p : null])));
};
// Compact page list: first, last and ±2 around the current page.
$pageList = [];
for ($p = 1; $p <= $pages; $p++) {
    if ($p === 1 || $p === $pages || abs($p - $page) <= 2) {
        $pageList[] = $p;
    }
}

ssp_header('Students', $user);
?>
<div class="page-head">
  <div>
    <h1>Students</h1>
    <p class="page-sub"><?= $total ?> <?= $total === 1 ? 'record' : 'records' ?><?= $filtering ? ' match this filter' : ' in this portal' ?> · <?= (int)$stats['synced'] ?> registered at Prime University</p>
  </div>
  <a class="btn btn-primary" href="<?= e(ssp_url('students/create.php')) ?>"><?= ssp_icon('user-plus') ?> New student</a>
</div>

<?php if (!ssp_api()->isConfigured()): ?>
  <?= ssp_alert('error', 'The Prime University API key is not configured. Students can be saved as drafts but not sent. <a href="' . e(ssp_url('status.php')) . '">Open Connection</a>.', false) ?>
<?php elseif ($ref['error'] !== null): ?>
  <?= ssp_alert('warning', 'Reference data could not be refreshed from the university (' . e($ref['error']) . '). ' . ($ref['data'] ? 'Using the cached copy from ' . e(ssp_date_human($ref['fetched_at'])) . '.' : 'Department and program lists are unavailable until the connection works.'), false) ?>
<?php endif; ?>

<div class="stats">
  <a class="stat stat-all<?= $status === '' ? ' active' : '' ?>" href="<?= e(ssp_url('dashboard.php')) ?>"><span class="stat-num"><?= $allCount ?></span><span class="stat-label">All students</span></a>
  <?php foreach (['synced' => 'Registered at PU', 'update_pending' => 'Update pending', 'draft' => 'Drafts', 'failed' => 'Failed', 'deleted' => 'Deleted'] as $k => $label): ?>
  <a class="stat stat-<?= e($k) ?><?= $status === $k ? ' active' : '' ?>" href="<?= e(ssp_url('dashboard.php?status=' . $k)) ?>">
    <span class="stat-num"><?= (int)$stats[$k] ?></span><span class="stat-label"><?= e($label) ?></span>
  </a>
  <?php endforeach; ?>
</div>

<form class="toolbar" method="get" action="<?= e(ssp_url('dashboard.php')) ?>" role="search">
  <div class="search">
    <?= ssp_icon('search') ?>
    <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Search name, reference, PU student ID, e-mail, phone" aria-label="Search students">
    <kbd aria-hidden="true">/</kbd>
  </div>
  <select name="status" aria-label="Filter by status">
    <option value="">All statuses</option>
    <?php foreach ($statusLabels as $k => $label): ?>
    <option value="<?= e($k) ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn">Filter</button>
  <?php if ($filtering): ?><a class="btn btn-ghost" href="<?= e(ssp_url('dashboard.php')) ?>"><?= ssp_icon('x') ?> Clear</a><?php endif; ?>
</form>

<div class="card table-card">
<table class="table">
  <thead>
    <tr>
      <th>Student</th><th>Department / Program</th><th>Admitted</th><th>Status</th><th>PU Student ID</th><th>Result</th><th>Created</th><th><span class="sr-only">Actions</span></th>
    </tr>
  </thead>
  <tbody>
  <?php if (!$rows): ?>
    <tr><td colspan="8" class="empty">
      <div class="empty-state">
        <?= ssp_icon('inbox') ?>
        <strong>No students <?= $filtering ? 'match this filter' : 'yet' ?></strong>
        <?php if ($filtering): ?>
          <a href="<?= e(ssp_url('dashboard.php')) ?>">Clear the filter</a>
        <?php else: ?>
          <a class="btn btn-primary btn-sm" href="<?= e(ssp_url('students/create.php')) ?>"><?= ssp_icon('user-plus') ?> Create the first student</a>
        <?php endif; ?>
      </div>
    </td></tr>
  <?php endif; ?>
  <?php foreach ($rows as $r): $url = ssp_url('students/view.php?id=' . (int)$r['id']); ?>
    <tr data-href="<?= e($url) ?>">
      <td>
        <div class="who">
          <span class="avatar avatar-sm" aria-hidden="true"><?= e(ssp_initials((string)$r['full_name'])) ?></span>
          <div><a class="who-name" href="<?= e($url) ?>"><?= e($r['full_name']) ?></a><small><code><?= e($r['reference_no']) ?></code></small></div>
        </div>
      </td>
      <td class="cell-stack"><?= e($r['department_label'] ?? '—') ?><?= $r['program_label'] ? '<small>' . e($r['program_label']) . '</small>' : '' ?></td>
      <td><?= e($r['admitted_semester'] ?? '—') ?></td>
      <td><?= ssp_badge($r['sync_status']) ?><?= $r['sync_status'] === 'synced' && (int)$r['pending_update'] === 1 ? '<br>' . ssp_badge('update_pending') : '' ?></td>
      <td class="cell-stack"><?= $r['pu_student_id'] ? '<strong' . ($r['sync_status'] === 'deleted' ? ' class="strike"' : '') . '>' . e($r['pu_student_id']) . '</strong><small>' . e($r['sync_status'] === 'deleted' ? 'deleted ' . ssp_date_human($r['deleted_at']) : (string)$r['pu_status']) . '</small>' : '<span class="muted">—</span>' ?></td>
      <td><?= $r['pu_result_id'] ? '<span class="badge badge-synced">Published</span>' : '<span class="muted">—</span>' ?></td>
      <td><small class="muted"><?= e(ssp_date_human($r['created_at'])) ?></small></td>
      <td class="actions"><a class="btn btn-sm btn-ghost" href="<?= e($url) ?>">Open <?= ssp_icon('chevron-right') ?></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php if ($pages > 1): ?>
<nav class="pagination" aria-label="Pages">
  <?php if ($page > 1): ?><a href="<?= e($pageUrl($page - 1)) ?>" aria-label="Previous page"><?= ssp_icon('chevron-left') ?></a><?php endif; ?>
  <?php $last = 0; foreach ($pageList as $p): ?>
    <?php if ($p - $last > 1): ?><span class="gap">…</span><?php endif; $last = $p; ?>
    <a class="<?= $p === $page ? 'active' : '' ?>" href="<?= e($pageUrl($p)) ?>"<?= $p === $page ? ' aria-current="page"' : '' ?>><?= $p ?></a>
  <?php endforeach; ?>
  <?php if ($page < $pages): ?><a href="<?= e($pageUrl($page + 1)) ?>" aria-label="Next page"><?= ssp_icon('chevron-right') ?></a><?php endif; ?>
</nav>
<?php endif; ?>
<?php ssp_footer();
