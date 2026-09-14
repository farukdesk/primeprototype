<?php
/**
 * SS Portal – connection to the Prime University API: configuration summary,
 * reference-data cache, connectivity test and recent API calls.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/reference_data.php';

$user = ssp_require_login();

if (ssp_is_post()) {
    ssp_csrf_verify();
    $ref = ssp_reference_data(true);
    if ($ref['error'] === null) {
        ssp_flash('success', 'Connected. Reference data refreshed from the university (' . count($ref['data']['departments'] ?? []) . ' departments).');
    } else {
        ssp_flash('error', 'Connection test failed: ' . $ref['error']);
    }
    ssp_redirect('status.php');
}

$api = ssp_api();
$ref = ssp_reference_data();
$data = $ref['data'] ?? [];

$logs = ssp_db()->query('SELECT l.*, s.reference_no, s.full_name
                           FROM ssp_api_log l LEFT JOIN ssp_students s ON s.id = l.student_id
                          ORDER BY l.id DESC LIMIT 25')->fetchAll();

if (!$api->isConfigured()) {
    $connState = 'is-bad';
    $connIcon  = 'x-circle';
    $connTitle = 'Not configured';
    $connText  = 'Set the partner API key in config.php (or the PU_API_KEY environment variable). Students can be saved as drafts but not sent.';
} elseif ($ref['error'] !== null) {
    $connState = $data ? 'is-warn' : 'is-bad';
    $connIcon  = $data ? 'alert-triangle' : 'x-circle';
    $connTitle = $data ? 'Using cached reference data' : 'Connection failed';
    $connText  = 'Last error: ' . $ref['error'] . ($data && $ref['fetched_at'] ? ' · cache from ' . ssp_date_human($ref['fetched_at']) : '');
} else {
    $connState = 'is-ok';
    $connIcon  = 'check-circle';
    $connTitle = 'Connected to Prime University';
    $connText  = 'Reference data fetched ' . ($ref['fetched_at'] ? ssp_date_human($ref['fetched_at']) : 'just now') . ' · ' . count($data['departments'] ?? []) . ' departments available.';
}

ssp_header('Connection', $user);
?>
<div class="page-head">
  <div>
    <h1>University connection</h1>
    <p class="page-sub">The API key is used only from this server and never sent to the browser.</p>
  </div>
  <form method="post" action="<?= e(ssp_url('status.php')) ?>">
    <?= ssp_csrf_field() ?>
    <button type="submit" class="btn btn-primary"<?= $api->isConfigured() ? '' : ' disabled title="API key not configured"' ?>><?= ssp_icon('refresh') ?> Test connection &amp; refresh reference data</button>
  </form>
</div>

<div class="card conn-hero <?= e($connState) ?>">
  <div class="conn-icon"><?= ssp_icon($connIcon) ?></div>
  <div class="grow"><h2><?= e($connTitle) ?></h2><p class="muted" style="margin:2px 0 0"><?= e($connText) ?></p></div>
</div>

<div class="grid-2">
  <div class="card">
    <h2>Configuration</h2>
    <dl class="dl">
      <dt>API base URL</dt><dd><code><?= e($api->baseUrl() ?: '(not set)') ?></code></dd>
      <dt>API key</dt><dd><code><?= e($api->maskedKey()) ?></code> <?= $api->isConfigured() ? '<span class="badge badge-synced">configured</span>' : '<span class="badge badge-failed">missing</span>' ?></dd>
      <dt>Idempotency prefix</dt><dd><code><?= e((string)ssp_config('idempotency_prefix', 'ssp')) ?>-student-&lt;reference&gt;</code></dd>
      <dt>Reference cache</dt><dd><?= $ref['fetched_at'] ? 'fetched ' . e(ssp_date_human($ref['fetched_at'])) : 'never fetched' ?> (TTL <?= (int)ssp_config('pu_api.reference_cache_ttl', 21600) / 3600 ?> h)</dd>
    </dl>
    <p class="muted">Edit <code>config.php</code> on the server (or the <code>PU_API_KEY</code> environment variable) to change these. The key is never sent to the browser.</p>
    <?php if ($ref['error'] !== null): ?><div class="flash flash-error">Last error: <?= e($ref['error']) ?></div><?php endif; ?>
  </div>

  <div class="card">
    <h2>Reference data</h2>
    <?php if ($data): ?>
    <dl class="dl">
      <dt>Departments</dt><dd><?= count($data['departments'] ?? []) ?></dd>
      <dt>Programs</dt><dd><?= array_sum(array_map(static fn($d) => count($d['programs'] ?? []), $data['departments'] ?? [])) ?></dd>
      <dt>Semesters</dt><dd><?= e(implode(', ', array_slice($data['semesters'] ?? [], 0, 4))) ?>…</dd>
      <dt>Exam titles / Boards / Groups</dt><dd><?= count($data['exam_titles'] ?? []) ?> / <?= count($data['boards'] ?? []) ?> / <?= count($data['groups'] ?? []) ?></dd>
      <dt>Rate limit</dt><dd><?= (int)($data['limits']['rate_limit_per_min'] ?? 0) ?> requests / minute</dd>
      <dt>Photo limit</dt><dd><?= round((int)($data['limits']['photo_max_bytes'] ?? 0) / 1048576, 1) ?> MB</dd>
    </dl>
    <details><summary>Departments and programs</summary>
      <ul class="plain">
      <?php foreach ($data['departments'] ?? [] as $d): ?>
        <li><strong><?= e($d['code']) ?></strong> – <?= e($d['name']) ?> <small class="muted">(id <?= (int)$d['id'] ?>)</small>
          <?php if (!empty($d['programs'])): ?><ul><?php foreach ($d['programs'] as $p): ?><li><?= e($p['name']) ?> <small class="muted">(id <?= (int)$p['id'] ?>)</small></li><?php endforeach; ?></ul><?php endif; ?>
        </li>
      <?php endforeach; ?>
      </ul>
    </details>
    <?php else: ?>
      <p class="muted">No reference data yet. Run the connection test above.</p>
    <?php endif; ?>
  </div>
</div>

<div class="card table-card">
  <h2>Recent API calls</h2>
  <table class="table">
    <thead><tr><th>When</th><th>Endpoint</th><th>Student</th><th>HTTP</th><th>Code</th><th>Time</th><th>Idempotency key</th></tr></thead>
    <tbody>
    <?php if (!$logs): ?><tr><td colspan="7" class="empty">No API calls yet.</td></tr><?php endif; ?>
    <?php foreach ($logs as $l): ?>
      <tr>
        <td><small><?= e(ssp_date_human($l['created_at'])) ?></small></td>
        <td><code><?= e($l['endpoint']) ?></code></td>
        <td><?= $l['student_id'] ? '<a href="' . e(ssp_url('students/view.php?id=' . (int)$l['student_id'])) . '">' . e($l['full_name'] ?? $l['reference_no'] ?? ('#' . $l['student_id'])) . '</a>' : '—' ?></td>
        <td><span class="badge badge-<?= $l['ok'] ? 'synced' : 'failed' ?>"><?= (int)$l['http_status'] ?: 'net' ?></span></td>
        <td><code><?= e($l['response_code']) ?></code></td>
        <td><small><?= (int)$l['duration_ms'] ?> ms</small></td>
        <td><small><code><?= e($l['idempotency_key'] ?? '—') ?></code></small></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php ssp_footer();
