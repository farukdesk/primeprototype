<?php
/**
 * SS Portal – students/view.php
 * One student: progress at Prime University, local record, internal
 * (portal-only) data and the audit trail of API calls.
 */
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/internal_data.php';
require_once __DIR__ . '/../includes/sync.php';

$user = ssp_require_login();
$id   = (int)($_GET['id'] ?? 0);
$s    = ssp_student_find($id);
if ($s === null) {
    ssp_flash('error', 'Student not found.');
    ssp_redirect('dashboard.php');
}

$payload  = json_decode((string)$s['payload_json'], true) ?: [];
$response = $s['last_response_json'] ? json_decode((string)$s['last_response_json'], true) : null;
$result   = $s['result_json'] ? json_decode((string)$s['result_json'], true) : null;
$internal = ssp_internal_decode($s['internal_json'] ?? null);   // portal-only data, never sent to the university
$files    = ssp_student_files($id);

$st = ssp_db()->prepare('SELECT l.*, u.full_name AS user_name FROM ssp_api_log l LEFT JOIN ssp_users u ON u.id = l.user_id
                         WHERE l.student_id = ? ORDER BY l.id DESC LIMIT 20');
$st->execute([$id]);
$logs = $st->fetchAll();

$isDeleted      = $s['sync_status'] === 'deleted';
$isSynced       = $s['sync_status'] === 'synced';
$pendingUpdate  = $isSynced && (int)$s['pending_update'] === 1;
$needsStudentId = $s['sync_status'] === 'draft' && (($response['code'] ?? '') === 'student_id_pattern_not_found');
$canEdit        = !$isDeleted;
$canSend        = !$isDeleted && (!$isSynced || $pendingUpdate) && ssp_api()->isConfigured();
$canDelete      = !$isDeleted && $user['role'] === 'admin';

$deletedBy = null;
if ($isDeleted && !empty($s['deleted_by'])) {
    $q = ssp_db()->prepare('SELECT full_name FROM ssp_users WHERE id = ?');
    $q->execute([(int)$s['deleted_by']]);
    $deletedBy = $q->fetchColumn() ?: null;
}

$sendLabel = $sendConfirm = '';
if ($canSend) {
    $sendLabel   = $s['sync_status'] === 'failed' ? 'Retry sending' : ($pendingUpdate ? 'Send update to university' : 'Send to university');
    $sendConfirm = $pendingUpdate ? 'Update this student\'s record at Prime University now?' : 'Send this student to Prime University now?';
}

// ── Progress steps (saved → sent → registered → result) ──
$attempts = (int)$s['sync_attempts'];
if ($isSynced) {
    $sent = 'done';
    $sentMeta = 'Accepted ' . ssp_date_human($s['synced_at']);
} elseif ($s['sync_status'] === 'pending') {
    $sent = 'current';
    $sentMeta = 'Sending…';
} elseif ($s['sync_status'] === 'failed') {
    $sent = 'failed';
    $sentMeta = 'Failed · ' . $attempts . ' attempt' . ($attempts === 1 ? '' : 's');
} elseif ($needsStudentId) {
    $sent = 'failed';
    $sentMeta = 'Student ID needed from the admin office';
} else {
    $sent = 'current';
    $sentMeta = 'Next step';
}
$steps = [
    ['Saved in portal', 'done', ssp_date_human($s['created_at'])],
    ['Sent to university', $sent, $sentMeta],
    ['Registered at PU', $isSynced ? ($pendingUpdate ? 'current' : 'done') : 'todo', $isSynced ? ($pendingUpdate ? 'Local changes not sent yet' : 'Student ID ' . $s['pu_student_id']) : 'After sending'],
    ['Result published', $s['pu_result_id'] ? 'done' : 'todo', $s['pu_result_id'] ? 'CGPA ' . ($result['cgpa'] ?? '') : ($isSynced ? 'Optional' : 'After registration')],
];

$meta = array_filter([
    $s['department_label'] ?? $payload['department'] ?? null,
    $s['program_label'] ?? $payload['program'] ?? null,
    $payload['semester'] ?? null,
]);

ssp_header($s['full_name'], $user);
?>
<p class="crumbs"><a href="<?= e(ssp_url('dashboard.php')) ?>"><?= ssp_icon('arrow-left') ?> Students</a></p>

<div class="card hero">
  <div class="hero-main">
    <?php if ($s['photo_path']): ?>
      <img class="avatar avatar-lg" src="<?= e(ssp_url('students/photo.php?id=' . $id)) ?>" alt="">
    <?php else: ?>
      <span class="avatar avatar-lg" aria-hidden="true"><?= e(ssp_initials((string)$s['full_name'])) ?></span>
    <?php endif; ?>
    <div>
      <div class="hero-title"><h1><?= e($s['full_name']) ?></h1><?= ssp_badge($s['sync_status']) ?><?= $pendingUpdate ? ssp_badge('update_pending') : '' ?></div>
      <p class="hero-meta"><code><?= e($s['reference_no']) ?></code><?= $meta ? ' · ' . e(implode(' · ', $meta)) : '' ?> · created <?= e(ssp_date_human($s['created_at'])) ?></p>
    </div>
  </div>
  <div class="hero-side">
    <?php if ($s['pu_student_id']): ?>
      <div class="kpi"><small>University Student ID</small><strong class="<?= $isDeleted ? 'strike' : '' ?>"><?= e($s['pu_student_id']) ?></strong></div>
    <?php endif; ?>
    <div class="btn-group">
      <?php if ($canSend): ?>
      <form method="post" action="<?= e(ssp_url('students/sync.php')) ?>">
        <?= ssp_csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
        <button type="submit" class="btn btn-primary" data-confirm="<?= e($sendConfirm) ?>"><?= ssp_icon('send') ?> <?= e($sendLabel) ?></button>
      </form>
      <?php endif; ?>
      <?php if ($canEdit): ?><a class="btn" href="<?= e(ssp_url('students/create.php?id=' . $id)) ?>"><?= ssp_icon('pencil') ?> Edit</a><?php endif; ?>
      <?php if ($isSynced): ?><a class="btn" href="<?= e(ssp_url('students/result.php?id=' . $id)) ?>"><?= ssp_icon('award') ?> <?= $s['pu_result_id'] ? 'Update result' : 'Publish result' ?></a><?php endif; ?>
      <?php if ($canDelete): ?><a class="btn btn-danger-outline" href="<?= e(ssp_url('students/delete.php?id=' . $id)) ?>"><?= ssp_icon('trash') ?> Delete</a><?php endif; ?>
    </div>
  </div>
</div>

<?php if (!$isDeleted): ?>
<ol class="steps" aria-label="Progress">
  <?php foreach ($steps as [$label, $state, $stepMeta]): ?>
  <li class="step <?= e($state) ?>"><strong><?= e($label) ?></strong><small><?= e($stepMeta) ?></small></li>
  <?php endforeach; ?>
</ol>
<?php endif; ?>

<?php if ($isDeleted): ?>
  <?= ssp_alert('error', '<strong>Deleted</strong> on ' . e(ssp_date_human($s['deleted_at'])) . ($deletedBy ? ' by ' . e($deletedBy) : '') . '. '
      . ($s['pu_student_id'] ? 'The student and all their data were permanently removed from Prime University (former Student ID ' . e($s['pu_student_id']) . ').' : 'The student was never registered at the university.')
      . ($s['delete_reason'] ? '<br>Reason: ' . e($s['delete_reason']) : '')
      . '<br><small>This record is kept in the portal for history only and cannot be edited or sent.</small>', false) ?>
<?php elseif ($s['sync_status'] === 'failed' && $s['last_error']): ?>
  <?= ssp_alert('error', '<strong>Last attempt failed:</strong> ' . e($s['last_error']) . ($canSend ? ' Use <strong>Retry sending</strong> above, or <a href="' . e(ssp_url('students/create.php?id=' . $id)) . '">edit the student</a> first.' : ''), false) ?>
<?php elseif ($pendingUpdate): ?>
  <?= ssp_alert('warning', '<strong>Local changes are not at the university yet.</strong> Click <strong>Send update to university</strong> to push them.' . ($s['last_error'] ? '<br>Last attempt failed: ' . e($s['last_error']) : ''), false) ?>
<?php elseif ($needsStudentId): ?>
  <?= ssp_alert('warning', '<strong>Student ID required – please contact the university admin.</strong> '
      . 'Prime University has no Student ID numbering yet for this semester / department / program and does not create one on its own, so the student was <strong>not</strong> created there (kept here as a draft). '
      . 'Ask the Prime University admin office for the Student ID, then <a href="' . e(ssp_url('students/create.php?id=' . $id)) . '">edit this student</a>, enter it in <strong>University Student ID</strong> and click <strong>Save and send to university</strong>.'
      . (!empty($payload['student_id']) ? '<br>Student ID currently entered: <code>' . e($payload['student_id']) . '</code> – click <strong>Send to university</strong> to create the student with it.' : ''), false) ?>
<?php elseif ($s['sync_status'] === 'draft'): ?>
  <?= ssp_alert('info', 'This student exists only in this portal. Click <strong>Send to university</strong> to register them at Prime University.', false) ?>
<?php endif; ?>

<div class="tabs" role="tablist" aria-label="Student details">
  <button type="button" class="tab" role="tab" id="tabbtn-overview" aria-controls="tab-overview" aria-selected="true">Overview</button>
  <button type="button" class="tab" role="tab" id="tabbtn-internal" aria-controls="tab-internal" aria-selected="false">Internal <span class="count"><?= count($files) ?></span></button>
  <button type="button" class="tab" role="tab" id="tabbtn-log" aria-controls="tab-log" aria-selected="false">API calls <span class="count"><?= count($logs) ?></span></button>
</div>

<div class="tab-panel" id="tab-overview" role="tabpanel" aria-labelledby="tabbtn-overview">
<div class="grid-2">
  <div class="card">
    <h2>Prime University</h2>
    <?php if ($isSynced): ?>
    <dl class="dl">
      <dt>Student ID</dt><dd><strong class="big"><?= e($s['pu_student_id']) ?></strong></dd>
      <dt>Internal id</dt><dd><?= (int)$s['pu_id'] ?></dd>
      <dt>Status</dt><dd><?= e($s['pu_status'] ?? '—') ?></dd>
      <dt>Registered</dt><dd><?= e(ssp_date_human($s['synced_at'])) ?></dd>
      <?php if ($s['pu_photo_url']): ?><dt>Photo</dt><dd><a href="<?= e($s['pu_photo_url']) ?>" target="_blank" rel="noopener">View on university server <?= ssp_icon('external') ?></a></dd><?php endif; ?>
      <?php if (!empty($response['warnings'])): ?>
      <dt>Warnings</dt><dd><ul class="plain"><?php foreach ($response['warnings'] as $w): ?><li class="warn"><?= e($w) ?></li><?php endforeach; ?></ul></dd>
      <?php endif; ?>
    </dl>
    <h3>Final result</h3>
    <?php if ($result): ?>
    <dl class="dl">
      <dt>CGPA</dt><dd><strong><?= e($result['cgpa'] ?? '') ?></strong></dd>
      <dt>Completion semester</dt><dd><?= e($result['semester'] ?? '') ?></dd>
      <dt>Batch</dt><dd><?= e($result['batch'] ?? '—') ?></dd>
      <dt>Publish date</dt><dd><?= e($result['recorded_date'] ?? '') ?></dd>
      <dt>Result id</dt><dd><?= (int)($result['result_id'] ?? $s['pu_result_id']) ?> (<?= e($result['action'] ?? 'created') ?>)</dd>
    </dl>
    <?php else: ?>
    <p class="muted">No result published yet. <a href="<?= e(ssp_url('students/result.php?id=' . $id)) ?>">Publish final result</a>.</p>
    <?php endif; ?>
    <?php elseif ($isDeleted): ?>
    <p class="muted"><?= $s['pu_student_id'] ? 'Former Student ID <strong>' . e($s['pu_student_id']) . '</strong> – deleted at the university on ' . e(ssp_date_human($s['deleted_at'])) . '.' : 'Never registered at the university.' ?></p>
    <?php else: ?>
    <p class="muted">Not registered yet. Attempts: <?= (int)$s['sync_attempts'] ?>.</p>
    <p class="muted">Idempotency key: <code><?= e(ssp_student_idempotency_key($s)) ?></code><br><small>Retrying with the same key can never create a duplicate student.</small></p>
    <?php endif; ?>
    <?php if ($response): ?><details><summary>Last API response</summary><pre class="code"><?= e(ssp_json_pretty($response)) ?></pre></details><?php endif; ?>
  </div>

  <div class="card">
    <h2>Local record</h2>
    <div class="record">
      <?php if ($s['photo_path']): ?><img class="photo-thumb" src="<?= e(ssp_url('students/photo.php?id=' . $id)) ?>" alt="Photo"><?php endif; ?>
      <dl class="dl">
        <dt>Department</dt><dd><?= e($s['department_label'] ?? $payload['department'] ?? '—') ?></dd>
        <dt>Program</dt><dd><?= e($s['program_label'] ?? $payload['program'] ?? '—') ?></dd>
        <dt>Admitted</dt><dd><?= e($payload['semester'] ?? '—') ?><?= !empty($payload['batch']) ? ' · ' . e($payload['batch']) : '' ?></dd>
        <?php if (!$isSynced && !empty($payload['student_id'])): ?><dt>Student ID to use</dt><dd><code><?= e($payload['student_id']) ?></code> <small class="muted">(issued by the university admin)</small></dd><?php endif; ?>
        <dt>Mobile / e-mail</dt><dd><?= e($payload['contact_no'] ?? '—') ?> / <?= e($payload['email'] ?? '—') ?></dd>
        <dt>Date of birth</dt><dd><?= e($payload['date_of_birth'] ?? '—') ?><?= !empty($payload['sex']) ? ' · ' . e($payload['sex']) : '' ?></dd>
        <dt>Father / Mother</dt><dd><?= e($payload['father_name'] ?? '—') ?> / <?= e($payload['mother_name'] ?? '—') ?></dd>
        <dt>Guardian</dt><dd><?= e($payload['guardian']['name'] ?? '—') ?><?= !empty($payload['guardian']['relationship']) ? ' (' . e($payload['guardian']['relationship']) . ')' : '' ?></dd>
        <dt>Qualifications</dt><dd><?= count($payload['academic_qualifications'] ?? []) ?></dd>
        <?php if (!empty($payload['result'])): ?><dt>Result in payload</dt><dd>CGPA <?= e($payload['result']['cgpa'] ?? '') ?>, <?= e($payload['result']['semester'] ?? '') ?></dd><?php endif; ?>
      </dl>
    </div>
    <details><summary>Payload sent to the API (JSON)</summary><pre class="code"><?= e(ssp_json_pretty($payload)) ?></pre></details>
  </div>
</div>
</div>

<div class="tab-panel" id="tab-internal" role="tabpanel" aria-labelledby="tabbtn-internal">
<div class="card">
  <div class="section-head">
    <div><h2>Internal</h2><p class="muted" style="margin:0">Portal only – never sent to the university.</p></div>
    <?php if ($canEdit): ?><a class="btn btn-sm" href="<?= e(ssp_url('students/create.php?id=' . $id . '#sec-internal')) ?>"><?= ssp_icon('pencil') ?> Edit</a><?php endif; ?>
  </div>
  <div class="grid-2">
    <dl class="dl">
      <?php foreach (SSP_INTERNAL_FLAGS as $k => $label): ?>
      <dt><?= e($label) ?></dt><dd><?= ssp_yes_no_html($internal[$k] ?? null) ?></dd>
      <?php endforeach; ?>
      <dt>Reference</dt><dd><?= e($internal['reference'] ?? '—') ?></dd>
      <dt>Notes</dt><dd class="prewrap"><?= !empty($internal['notes']) ? e($internal['notes']) : '<span class="muted">—</span>' ?></dd>
    </dl>
    <div>
      <h3 style="margin-top:0">Documents (<?= count($files) ?>)</h3>
      <?php if (!$files): ?>
      <p class="muted">No documents uploaded.<?= $canEdit ? ' <a href="' . e(ssp_url('students/create.php?id=' . $id . '#sec-internal')) . '">Edit</a> the student to upload.' : '' ?></p>
      <?php else: ?>
      <ul class="file-list">
        <?php foreach ($files as $f): ?>
        <li><span class="badge badge-plain"><?= e(SSP_FILE_KINDS[$f['kind']] ?? $f['kind']) ?></span>
          <a href="<?= e(ssp_url('students/file.php?id=' . (int)$f['id'])) ?>" target="_blank" rel="noopener"><?= e($f['original_name']) ?></a>
          <small class="muted"><?= e(ssp_file_size_human((int)$f['size_bytes'])) ?> · <?= e(ssp_date_human($f['created_at'])) ?><?= $f['uploaded_by_name'] ? ' · ' . e($f['uploaded_by_name']) : '' ?></small>
          <a class="btn btn-ghost btn-sm btn-icon" href="<?= e(ssp_url('students/file.php?id=' . (int)$f['id'] . '&download=1')) ?>" title="Download" aria-label="Download <?= e($f['original_name']) ?>"><?= ssp_icon('download') ?></a></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
  </div>
</div>
</div>

<div class="tab-panel" id="tab-log" role="tabpanel" aria-labelledby="tabbtn-log">
<div class="card table-card">
  <h2>API calls for this student</h2>
  <table class="table">
    <thead><tr><th>When</th><th>By</th><th>Endpoint</th><th>HTTP</th><th>Code</th><th>Time</th><th></th></tr></thead>
    <tbody>
    <?php if (!$logs): ?><tr><td colspan="7" class="empty">No API calls yet.</td></tr><?php endif; ?>
    <?php foreach ($logs as $l): ?>
      <tr>
        <td><small><?= e(ssp_date_human($l['created_at'])) ?></small></td>
        <td><?= e($l['user_name'] ?? '—') ?></td>
        <td><code><?= e($l['endpoint']) ?></code></td>
        <td><span class="badge badge-<?= $l['ok'] ? 'synced' : 'failed' ?>"><?= (int)$l['http_status'] ?: 'net' ?></span></td>
        <td><code><?= e($l['response_code']) ?></code></td>
        <td><small><?= (int)$l['duration_ms'] ?> ms</small></td>
        <td><details class="inline"><summary>details</summary>
          <pre class="code small"><?= e(ssp_json_pretty(json_decode((string)$l['response_json'], true))) ?></pre></details></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
</div>
<?php ssp_footer();
