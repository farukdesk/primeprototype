<?php
/**
 * SS Portal – students/result.php
 * Publish (or correct) the final result / CGPA of a student that is already
 * registered at Prime University, via POST /admin/api/v1/results/create.php.
 * The endpoint is an upsert on (student, semester), so re-sending is safe.
 */
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/reference_data.php';
require_once __DIR__ . '/../includes/student_payload.php';
require_once __DIR__ . '/../includes/sync.php';

$user = ssp_require_login();
$id   = (int)($_GET['id'] ?? 0);
$s    = ssp_student_find($id);
if ($s === null) {
    ssp_flash('error', 'Student not found.');
    ssp_redirect('dashboard.php');
}
if ($s['sync_status'] !== 'synced') {
    ssp_flash('warning', 'Register the student at the university first; results can only be published for existing students.');
    ssp_redirect('students/view.php?id=' . $id);
}

$payload  = json_decode((string)$s['payload_json'], true) ?: [];
$existing = $s['result_json'] ? json_decode((string)$s['result_json'], true) : null;

$errors = [];
$form   = [
    'semester'      => $existing['semester'] ?? '',
    'cgpa'          => $existing['cgpa'] ?? '',
    'batch'         => $existing['batch'] ?? ($payload['batch'] ?? ''),
    'recorded_date' => $existing['recorded_date'] ?? date('Y-m-d'),
];

if (ssp_is_post()) {
    ssp_csrf_verify();
    $form   = array_merge($form, array_intersect_key($_POST, array_flip(['semester', 'cgpa', 'batch', 'recorded_date', 'mark_graduated'])));
    $result = ssp_build_result_fields($_POST, $errors, '');
    if (!$errors) {
        $r = ssp_publish_result($id, $result, (int)$user['id']);
        if ($r['ok']) {
            ssp_flash('success', $r['message']);
            foreach ($r['warnings'] ?? [] as $w) {
                ssp_flash('warning', 'University warning: ' . $w);
            }
            ssp_redirect('students/view.php?id=' . $id);
        }
        $errors = $r['errors'] ?: [];
        $errors['_form'] = 'The university did not accept the result: ' . (($r['response']['message'] ?? '') !== '' ? $r['response']['message'] : $r['message']);
    }
}

$semesters = (array)((ssp_reference_data()['data'] ?? [])['semesters'] ?? []);

ssp_header('Publish result · ' . $s['full_name'], $user);
?>
<div class="page-head">
  <div>
    <h1><?= $existing ? 'Update' : 'Publish' ?> final result</h1>
    <p class="muted"><?= e($s['full_name']) ?> · University Student ID <strong><?= e($s['pu_student_id']) ?></strong> · status <?= e($s['pu_status'] ?? '—') ?></p>
  </div>
  <a class="btn btn-ghost" href="<?= e(ssp_url('students/view.php?id=' . $id)) ?>">← Back to student</a>
</div>

<?php ssp_form_errors_summary($errors); ?>

<form method="post" action="<?= e(ssp_url('students/result.php?id=' . $id)) ?>" class="card card-narrow" novalidate>
  <?= ssp_csrf_field() ?>
  <?php ssp_input('semester', 'Completion / ending semester', $form, $errors, ['required' => true, 'list' => 'dl_semesters', 'placeholder' => 'Fall 2024', 'maxlength' => 30]); ?>
  <?php ssp_input('cgpa', 'Final CGPA', $form, $errors, ['required' => true, 'type' => 'number', 'step' => '0.01', 'min' => '0.01', 'max' => '4', 'placeholder' => '3.42', 'hint' => 'Only final results (0.01 – 4.00). Incomplete / withheld results are rejected by the university.']); ?>
  <?php ssp_input('batch', 'Batch', $form, $errors, ['maxlength' => 50, 'hint' => 'Defaults to the batch on the university record.']); ?>
  <?php ssp_input('recorded_date', 'Result publish date', $form, $errors, ['type' => 'date', 'max' => date('Y-m-d')]); ?>
  <div class="field">
    <label class="check"><input type="checkbox" name="mark_graduated" value="1"<?= !empty($form['mark_graduated']) ? ' checked' : '' ?>> Force status “Graduated”</label>
    <small class="hint">Students with status Active or Dropped are marked Graduated automatically.</small>
  </div>
  <?php ssp_datalist('dl_semesters', $semesters); ?>
  <p class="muted">Sending the same semester again updates the existing result instead of creating a duplicate.</p>
  <div class="form-actions">
    <button type="submit" class="btn btn-primary" data-confirm="Publish this result at Prime University?"><?= $existing ? 'Update result' : 'Publish result' ?></button>
    <a class="btn btn-ghost" href="<?= e(ssp_url('students/view.php?id=' . $id)) ?>">Cancel</a>
  </div>
</form>
<?php ssp_footer();
