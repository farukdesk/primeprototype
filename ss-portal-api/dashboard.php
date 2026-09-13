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
if (in_array($status, ['draft', 'pending', 'synced', 'failed'], true)) {
    $where[] = 'sync_status = ?';
    $args[]  = $status;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$st = $db->prepare('SELECT COUNT(*) FROM ssp_students' . $whereSql);
$st->execute($args);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$page  = min($page, $pages);

$st = $db->prepare('SELECT id, reference_no, full_name, department_label, program_label, admitted_semester, sync_status,
                           pu_student_id, pu_status, pu_result_id, created_at, synced_at
                      FROM ssp_students' . $whereSql . '
                     ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
$st->execute($args);
$rows = $st->fetchAll();

$stats = ['draft' => 0, 'pending' => 0, 'synced' => 0, 'failed' => 0];
foreach ($db->query('SELECT sync_status, COUNT(*) AS c FROM ssp_students GROUP BY sync_status') as $r) {
    $stats[$r['sync_status']] = (int)$r['c'];
}

$ref = ssp_reference_data();

ssp_header('Dashboard', $user);
?>
<div class="page-head">
  <h1>Students</h1>
  <a class="btn btn-primary" href="<?= e(ssp_url('students/create.php')) ?>">+ New student</a>
</div>

<?php if (!ssp_api()->isConfigured()): ?>
  <div class="flash flash-error">The Prime University API key is not configured. Students can be saved as drafts but not sent. See <a href="<?= e(ssp_url('status.php')) ?>">Connection</a>.</div>
<?php elseif ($ref['error'] !== null): ?>
  <div class="flash flash-warning">Reference data could not be refreshed from the university (<?= e($ref['error']) ?>). <?= $ref['data'] ? 'Using the cached copy from ' . e(ssp_date_human($ref['fetched_at'])) . '.' : 'Department and program lists are unavailable until the connection works.' ?></div>
<?php endif; ?>

<div class="stats">
  <?php foreach (['synced' => 'Registered at PU', 'draft' => 'Drafts', 'failed' => 'Failed', 'pending' => 'Sending'] as $k => $label): ?>
  <a class="stat stat-<?= e($k) ?><?= $status === $k ? ' active' : '' ?>" href="<?= e(ssp_url('dashboard.php?status=' . $k)) ?>">
    <span class="stat-num"><?= (int)$stats[$k] ?></span><span class="stat-label"><?= e($label) ?></span>
  </a>
  <?php endforeach; ?>
</div>

<form class="filters" method="get" action="<?= e(ssp_url('dashboard.php')) ?>">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, reference, PU student ID, e-mail, phone">
  <select name="status">
    <option value="">All statuses</option>
    <?php foreach (['draft' => 'Draft', 'pending' => 'Sending', 'synced' => 'Registered at PU', 'failed' => 'Failed'] as $k => $label): ?>
    <option value="<?= e($k) ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn">Filter</button>
  <?php if ($q !== '' || $status !== ''): ?><a class="btn btn-ghost" href="<?= e(ssp_url('dashboard.php')) ?>">Clear</a><?php endif; ?>
</form>

<div class="card table-card">
<table class="table">
  <thead>
    <tr>
      <th>Reference</th><th>Name</th><th>Department / Program</th><th>Admitted</th><th>Status</th><th>PU Student ID</th><th>Result</th><th>Created</th><th></th>
    </tr>
  </thead>
  <tbody>
  <?php if (!$rows): ?>
    <tr><td colspan="9" class="empty">No students found<?= $q !== '' || $status !== '' ? ' for this filter' : '' ?>. <a href="<?= e(ssp_url('students/create.php')) ?>">Create the first one</a>.</td></tr>
  <?php endif; ?>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><a href="<?= e(ssp_url('students/view.php?id=' . (int)$r['id'])) ?>"><code><?= e($r['reference_no']) ?></code></a></td>
      <td><?= e($r['full_name']) ?></td>
      <td><?= e($r['department_label'] ?? '—') ?><?= $r['program_label'] ? '<br><small class="muted">' . e($r['program_label']) . '</small>' : '' ?></td>
      <td><?= e($r['admitted_semester'] ?? '—') ?></td>
      <td><?= ssp_badge($r['sync_status']) ?></td>
      <td><?= $r['pu_student_id'] ? '<strong>' . e($r['pu_student_id']) . '</strong><br><small class="muted">' . e($r['pu_status']) . '</small>' : '—' ?></td>
      <td><?= $r['pu_result_id'] ? '<span class="badge badge-synced">Published</span>' : '—' ?></td>
      <td><small><?= e(ssp_date_human($r['created_at'])) ?></small></td>
      <td class="actions">
        <a class="btn btn-sm" href="<?= e(ssp_url('students/view.php?id=' . (int)$r['id'])) ?>">Open</a>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php if ($pages > 1): ?>
<nav class="pagination">
  <?php for ($p = 1; $p <= $pages; $p++): ?>
    <?php $qs = http_build_query(array_filter(['q' => $q, 'status' => $status, 'page' => $p])); ?>
    <a class="<?= $p === $page ? 'active' : '' ?>" href="<?= e(ssp_url('dashboard.php?' . $qs)) ?>"><?= $p ?></a>
  <?php endfor; ?>
</nav>
<?php endif; ?>
<?php ssp_footer();
