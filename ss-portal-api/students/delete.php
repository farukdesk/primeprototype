<?php
/**
 * SS Portal – students/delete.php (administrators only)
 * Permanently deletes the student at Prime University (POST /students/delete.php:
 * student row, qualifications, final results, photo, files) and keeps the
 * local record marked as "deleted" for the portal's own history.
 */
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/sync.php';

$user = ssp_require_admin();
$id   = (int)($_GET['id'] ?? 0);
$s    = ssp_student_find($id);
if ($s === null) {
    ssp_flash('error', 'Student not found.');
    ssp_redirect('dashboard.php');
}
if ($s['sync_status'] === 'deleted') {
    ssp_flash('info', 'This student is already marked as deleted.');
    ssp_redirect('students/view.php?id=' . $id);
}

$atUniversity = $s['sync_status'] === 'synced' && !empty($s['pu_student_id']);
$error        = '';
$reason       = '';

if (ssp_is_post()) {
    ssp_csrf_verify();
    $reason  = trim((string)($_POST['reason'] ?? ''));
    $confirm = trim((string)($_POST['confirm_text'] ?? ''));
    if (strcasecmp($confirm, (string)$s['reference_no']) !== 0 && strcasecmp($confirm, 'DELETE') !== 0) {
        $error = 'Type the reference number ' . $s['reference_no'] . ' (or the word DELETE) to confirm.';
    } elseif (mb_strlen($reason) > 500) {
        $error = 'The reason must be 500 characters or fewer.';
    } else {
        $r = ssp_delete_student($id, (int)$user['id'], $reason);
        ssp_flash($r['ok'] ? 'success' : 'error', $r['message']);
        ssp_redirect('students/view.php?id=' . $id);
    }
}

ssp_header('Delete ' . $s['full_name'], $user);
?>
<div class="page-head">
  <div>
    <h1>Delete student</h1>
    <p class="muted"><?= e($s['full_name']) ?> · <code><?= e($s['reference_no']) ?></code> <?= ssp_badge($s['sync_status']) ?></p>
  </div>
  <a class="btn btn-ghost" href="<?= e(ssp_url('students/view.php?id=' . $id)) ?>">← Back to student</a>
</div>

<?php if ($error !== ''): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>

<div class="grid-2">
  <div class="card danger">
    <h2>What will happen</h2>
    <?php if ($atUniversity): ?>
    <p><strong>At Prime University</strong> (Student ID <strong><?= e($s['pu_student_id']) ?></strong>) the following is <strong>permanently deleted</strong> and cannot be recovered:</p>
    <ul>
      <li>the student record and profile photo</li>
      <li>all academic qualifications</li>
      <li>every final result / CGPA published for the student (they disappear from certificate verification)</li>
      <li>uploaded files and comments attached to the student</li>
    </ul>
    <p class="muted">The university refuses the deletion if the student has recorded payments or vouchers; in that case nothing changes and the error is shown here.</p>
    <?php else: ?>
    <p>This student was <strong>never registered</strong> at Prime University, so nothing is sent to the university.</p>
    <?php endif; ?>
    <p><strong>In this portal</strong> the record is kept for history, marked as <span class="badge badge-deleted">Deleted</span>, and can no longer be edited or sent.</p>
  </div>

  <form method="post" action="<?= e(ssp_url('students/delete.php?id=' . $id)) ?>" class="card" novalidate>
    <?= ssp_csrf_field() ?>
    <h2>Confirm</h2>
    <div class="field">
      <label for="reason">Reason <span class="muted">(optional, recorded in the university Change Log)</span></label>
      <textarea id="reason" name="reason" rows="3" maxlength="500" placeholder="e.g. Duplicate registration"><?= e($reason) ?></textarea>
    </div>
    <div class="field">
      <label for="confirm_text">Type <code><?= e($s['reference_no']) ?></code> to confirm</label>
      <input type="text" id="confirm_text" name="confirm_text" autocomplete="off" required>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-danger" data-confirm="<?= $atUniversity ? 'Permanently delete this student and all their data at Prime University?' : 'Mark this student as deleted?' ?>"<?= $atUniversity && !ssp_api()->isConfigured() ? ' disabled title="API key not configured"' : '' ?>>
        <?= $atUniversity ? 'Delete at university and mark deleted here' : 'Mark as deleted' ?>
      </button>
      <a class="btn btn-ghost" href="<?= e(ssp_url('students/view.php?id=' . $id)) ?>">Cancel</a>
    </div>
  </form>
</div>
<?php ssp_footer();
