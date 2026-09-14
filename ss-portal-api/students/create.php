<?php
/**
 * SS Portal – students/create.php
 * Create a student (or edit one that has not been sent yet) and optionally
 * push it straight to Prime University via POST /admin/api/v1/students/create.php.
 *
 * The record is ALWAYS stored locally first (ssp_students), then sent with an
 * idempotency key derived from its reference number.  Validation errors
 * returned by the university (422) are shown on this form so the operator can
 * correct them and send again; other failures are kept on the record and can
 * be retried from the student page.
 *
 * The "Internal (portal only)" section – yes/no flags, reference, notes and
 * document uploads – is stored beside the payload (internal_json /
 * ssp_student_files) and is never part of what is sent to the university.
 */
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/reference_data.php';
require_once __DIR__ . '/../includes/student_payload.php';
require_once __DIR__ . '/../includes/internal_data.php';
require_once __DIR__ . '/../includes/sync.php';

$user = ssp_require_login();
$db   = ssp_db();

$editId   = (int)($_GET['id'] ?? 0);
$existing = null;
if ($editId > 0) {
    $existing = ssp_student_find($editId);
    if ($existing === null) {
        ssp_flash('error', 'Student not found.');
        ssp_redirect('dashboard.php');
    }
    if ($existing['sync_status'] === 'deleted') {
        ssp_flash('warning', 'This student has been deleted and cannot be edited.');
        ssp_redirect('students/view.php?id=' . $editId);
    }
}
$isSynced = $existing !== null && $existing['sync_status'] === 'synced';

$ref     = ssp_reference_data();
$refData = $ref['data'] ?? [];

$errors        = [];
$form          = ['country' => 'Bangladesh', 'nationality' => 'Bangladeshi'];
$existingFiles = [];
if ($existing !== null) {
    $form = json_decode((string)$existing['payload_json'], true) ?: [];
    $form['result_enabled'] = isset($form['result']);
    $form['internal']       = ssp_internal_decode($existing['internal_json'] ?? null);
    $existingFiles          = ssp_student_files($editId);
}

if (ssp_is_post()) {
    ssp_csrf_verify();
    $action  = (($_POST['action'] ?? 'save') === 'send') ? 'send' : 'save';
    $payload = ssp_build_student_payload($_POST, $errors);
    $form    = $payload;
    $form['result_enabled'] = isset($payload['result']);

    // Portal-only data: validated here, stored next to (never inside) the payload.
    $internal         = ssp_build_internal_data($_POST, $errors);
    $form['internal'] = $internal;
    $removeFileIds    = array_map('intval', (array)($_POST['remove_files'] ?? []));
    $uploads          = ssp_normalize_file_uploads($_FILES['files'] ?? null);
    ssp_validate_file_uploads($uploads, $existingFiles, $removeFileIds, $errors);

    $newPhoto     = ssp_store_photo_upload($_FILES['photo'] ?? null, $errors);
    $removePhoto  = !empty($_POST['remove_photo']);
    $id           = $editId;
    $fileWarnings = [];

    if (!$errors) {
        $labels    = ssp_ref_labels($refData, $payload);
        $photoPath = $existing['photo_path'] ?? null;
        if ($newPhoto !== null) {
            $photoPath = $newPhoto;
        } elseif ($removePhoto) {
            $photoPath = null;
        }
        $json         = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $internalJson = $internal ? json_encode($internal, JSON_UNESCAPED_UNICODE) : null;
        try {
            if ($existing !== null) {
                // Registered students stay "synced" and are flagged as having a pending update;
                // unsent ones go back to draft.
                $db->prepare('UPDATE ssp_students
                        SET full_name = ?, email = ?, contact_no = ?, department_label = ?, program_label = ?, admitted_semester = ?,
                            payload_json = ?, internal_json = ?, photo_path = ?, last_error = NULL,
                            pending_update = IF(sync_status = "synced", 1, 0),
                            sync_status    = IF(sync_status = "synced", "synced", "draft")
                      WHERE id = ?')
                   ->execute([$payload['name'], $payload['email'] ?? null, $payload['contact_no'] ?? null, $labels['department'],
                              $labels['program'], $payload['semester'], $json, $internalJson, $photoPath, $editId]);
                if (!empty($existing['photo_path']) && $existing['photo_path'] !== $photoPath) {
                    ssp_delete_photo($existing['photo_path']);
                }
            } else {
                $db->prepare('INSERT INTO ssp_students
                        (reference_no, full_name, email, contact_no, department_label, program_label, admitted_semester,
                         payload_json, internal_json, photo_path, sync_status, created_by)
                      VALUES (?,?,?,?,?,?,?,?,?,?,"draft",?)')
                   ->execute([ssp_generate_reference_no($db), $payload['name'], $payload['email'] ?? null, $payload['contact_no'] ?? null,
                              $labels['department'], $labels['program'], $payload['semester'], $json, $internalJson, $photoPath, (int)$user['id']]);
                $id = (int)$db->lastInsertId();
            }

            // Internal documents: drop the ticked ones, then store the new uploads.
            // Problems here never block the (already saved) student; they are shown as warnings.
            foreach ($existingFiles as $f) {
                if (in_array((int)$f['id'], $removeFileIds, true)) {
                    ssp_delete_student_file($f);
                }
            }
            $fileErrors = [];
            ssp_store_student_files($id, (int)$user['id'], $uploads, $fileErrors);
            $fileWarnings = array_values($fileErrors);
        } catch (Throwable $ex) {
            error_log('ss-portal students/create: ' . $ex->getMessage());
            $errors['_form'] = 'The student could not be saved locally. Please try again.';
            ssp_delete_photo($newPhoto);
        }
    } elseif ($newPhoto !== null) {
        ssp_delete_photo($newPhoto);   // validation failed – do not keep orphaned uploads
    }

    if (!$errors) {
        foreach ($fileWarnings as $w) {
            ssp_flash('warning', 'Document upload: ' . $w);
        }
        if ($action === 'send') {
            $result = ssp_sync_student($id, (int)$user['id']);
            if ($result['ok']) {
                ssp_flash('success', $result['message'] . ($isSynced ? '' : ' University Student ID: ' . ($result['student_id'] ?? 'n/a') . '.'));
                foreach ($result['warnings'] ?? [] as $w) {
                    ssp_flash('warning', 'University warning: ' . $w);
                }
                ssp_redirect('students/view.php?id=' . $id);
            }
            if (!empty($result['needs_student_id'])) {
                // The university has no ID numbering for this cohort and created nothing:
                // the record stays a draft until the admin-issued Student ID is entered.
                ssp_flash('warning', $result['message']);
                ssp_redirect('students/view.php?id=' . $id);
            }
            if (!empty($result['errors'])) {
                // 422 from the university – show its field errors here so they can be fixed immediately
                $errors          = $result['errors'];
                $errors['_form'] = 'Prime University rejected the request: '
                    . (($result['response']['message'] ?? '') !== '' ? $result['response']['message'] : 'validation failed')
                    . ' Correct the highlighted fields and send again.';
                $editId        = $id;
                $existing      = ssp_student_find($id);
                $existingFiles = ssp_student_files($id);
            } else {
                ssp_flash('error', 'Saved locally, but ' . ($isSynced ? 'updating the university record' : 'sending to the university') . ' failed: ' . $result['message']
                    . (!empty($result['retryable']) ? ' You can retry from this page.' : ''));
                ssp_redirect('students/view.php?id=' . $id);
            }
        } else {
            ssp_flash('success', $isSynced
                ? 'Changes saved locally. The university record is NOT updated until you click "Send update to university".'
                : 'Student saved as a draft. Open it and click "Send to university" when ready.');
            ssp_redirect('students/view.php?id=' . $id);
        }
    }
}

// ── Options from reference data (with sensible fallbacks when it is unavailable) ─────────────────────────
$deptOptions = [];
$progOptions = [];
foreach ((array)($refData['departments'] ?? []) as $d) {
    $deptOptions[] = ['value' => (string)$d['id'], 'label' => trim(($d['code'] ?? '') . ' – ' . ($d['name'] ?? ''), ' –')];
    foreach ((array)($d['programs'] ?? []) as $p) {
        $progOptions[] = ['value' => (string)$p['id'], 'label' => (string)$p['name'] . (!empty($p['type']) ? ' (' . $p['type'] . ')' : ''),
                          'attrs' => ['data-dept' => (string)$d['id']]];
    }
}
$enums = (array)($refData['enums'] ?? []);
$enumOptions = static function (string $key, array $fallback) use ($enums): array {
    return array_map(static fn($v) => ['value' => (string)$v, 'label' => (string)$v], (array)($enums[$key] ?? $fallback));
};
$examNames = [];
foreach ((array)($refData['exam_titles'] ?? []) as $x) {
    $examNames[] = $x['short_name'] ?? '';
    $examNames[] = $x['name'] ?? '';
}
$boardNames = array_column((array)($refData['boards'] ?? []), 'name');
$groupNames = array_column((array)($refData['groups'] ?? []), 'name');
$semesters  = (array)($refData['semesters'] ?? []);
$yesNo      = [['value' => 'yes', 'label' => 'Yes'], ['value' => 'no', 'label' => 'No']];

$qualRows = array_values((array)($form['academic_qualifications'] ?? []));
if (!$qualRows) {
    $qualRows = [[]];
}

$renderQualRow = static function ($i) use ($form, $errors): void {
    $p = "academic_qualifications.$i.";
    echo '<div class="qual-row">';
    ssp_input($p . 'name_of_examination', 'Examination', $form, $errors, ['list' => 'dl_exams', 'placeholder' => 'HSC', 'maxlength' => 100]);
    ssp_input($p . 'session', 'Session', $form, $errors, ['placeholder' => '2019-2020', 'maxlength' => 30]);
    ssp_input($p . 'group', 'Group', $form, $errors, ['list' => 'dl_groups', 'placeholder' => 'Science']);
    ssp_input($p . 'board_university', 'Board / University', $form, $errors, ['list' => 'dl_boards', 'placeholder' => 'Dhaka Board']);
    ssp_input($p . 'year_of_passing', 'Passing year', $form, $errors, ['placeholder' => '2024', 'maxlength' => 4]);
    ssp_input($p . 'division_grade', 'Division / Grade', $form, $errors, ['placeholder' => 'A+', 'maxlength' => 50]);
    ssp_input($p . 'obtained_marks_cgpa', 'Marks / GPA', $form, $errors, ['placeholder' => '5.00', 'maxlength' => 50]);
    echo '<button type="button" class="btn btn-ghost btn-remove-row" title="Remove this row" aria-label="Remove this row">', ssp_icon('x'), '</button>';
    echo '</div>';
};

// Internal document slots: existing files (with a remove tick box) + a multi-file input per slot.
$filesByKind = [];
foreach ($existingFiles as $f) {
    $filesByKind[$f['kind']][] = $f;
}
$renderFileSlot = static function (string $kind, string $label) use ($filesByKind, $errors): void {
    $err = (string)($errors['files.' . $kind] ?? '');
    $id  = 'f_files_' . $kind;
    echo '<div class="field file-slot', $err !== '' ? ' has-error' : '', '">';
    echo '<label for="', e($id), '">', e($label), '</label>';
    if (!empty($filesByKind[$kind])) {
        echo '<ul class="file-list">';
        foreach ($filesByKind[$kind] as $f) {
            echo '<li>', ssp_icon('file'), '<a href="', e(ssp_url('students/file.php?id=' . (int)$f['id'])), '" target="_blank" rel="noopener">', e($f['original_name']), '</a>',
                 ' <small class="muted">', e(ssp_file_size_human((int)$f['size_bytes'])), '</small>',
                 ' <label class="check inline"><input type="checkbox" name="remove_files[]" value="', (int)$f['id'], '"> remove</label></li>';
        }
        echo '</ul>';
    }
    echo '<input type="file" id="', e($id), '" name="files[', e($kind), '][]" multiple accept="', e(SSP_FILE_ACCEPT), '">';
    echo '<small class="file-chosen"></small>';
    if ($err !== '') {
        echo '<small class="error">', e($err), '</small>';
    }
    echo '</div>';
};
$fileMaxMb = round(ssp_file_max_bytes() / 1048576, 1);

// ── Section navigation: labels, descriptions and error counts per section ───────────────────────────────
$sections = [
    'enrollment'     => ['Enrollment', 'Department, program and admitted semester'],
    'student'        => ['Student & parents', 'Personal details, contacts and parents'],
    'guardian'       => ['Guardian', 'Optional'],
    'qualifications' => ['Academic qualifications', 'SSC, HSC and other examinations'],
    'photo'          => ['Photo', 'Sent to the university with the record'],
    'internal'       => ['Internal', 'Portal only – never sent to the university'],
    'result'         => ['Final result', $isSynced ? 'Managed from the student page' : 'Optional – sent in the same call'],
];
$enrollmentKeys = ['department', 'program', 'semester', 'student_id', 'year', 'batch', 'semester_type', 'shift', 'section', 'status'];
$sectionOf = static function (string $key) use ($enrollmentKeys): string {
    if (strpos($key, 'guardian.') === 0) {
        return 'guardian';
    }
    if (strpos($key, 'academic_qualifications') === 0) {
        return 'qualifications';
    }
    if ($key === 'photo') {
        return 'photo';
    }
    if (strpos($key, 'internal.') === 0 || strpos($key, 'files') === 0) {
        return 'internal';
    }
    if (strpos($key, 'result') === 0) {
        return 'result';
    }
    return in_array($key, $enrollmentKeys, true) ? 'enrollment' : 'student';
};
$sectionErrors = [];
foreach (array_keys($errors) as $k) {
    if ($k !== '_form') {
        $sec = $sectionOf((string)$k);
        $sectionErrors[$sec] = ($sectionErrors[$sec] ?? 0) + 1;
    }
}
$sectionNo   = 0;
$sectionHead = static function (string $key, string $extraHtml = '') use ($sections, &$sectionNo): void {
    $sectionNo++;
    echo '<div class="section-head"><div class="section-title"><span class="num" aria-hidden="true">', $sectionNo, '</span><div><h2>', e($sections[$key][0]), '</h2><p>', e($sections[$key][1]), '</p></div></div>', $extraHtml, '</div>';
};

$title = $existing ? 'Edit student' : 'New student';
ssp_header($title, $user);
?>
<p class="crumbs"><a href="<?= e(ssp_url($existing ? 'students/view.php?id=' . $editId : 'dashboard.php')) ?>"><?= ssp_icon('arrow-left') ?> <?= $existing ? 'Back to student' : 'Students' ?></a></p>
<div class="page-head">
  <div>
    <h1><?= e($title) ?><?php if ($existing): ?> <code><?= e($existing['reference_no']) ?></code><?php endif; ?></h1>
    <p class="page-sub"><?php if ($isSynced): ?>Registered at Prime University as <strong><?= e($existing['pu_student_id']) ?></strong>. Changes are sent with <code>students/update.php</code>; the Student ID stays the same.<?php else: ?>Fields marked <span class="req">*</span> are required. Everything is saved in this portal first; sending to the university is a separate step.<?php endif; ?></p>
  </div>
</div>

<?php if (!$refData): ?>
  <?= ssp_alert('warning', 'Reference data from the university is unavailable' . ($ref['error'] ? ' (' . e($ref['error']) . ')' : '') . '. You can still type department / program as an ID, code or exact name; the university validates them when the student is sent.', false) ?>
<?php elseif ($ref['error'] !== null): ?>
  <?= ssp_alert('info', 'Using cached reference data from ' . ssp_date_human($ref['fetched_at']) . '.') ?>
<?php endif; ?>

<?php ssp_form_errors_summary($errors); ?>

<form method="post" action="<?= e(ssp_url('students/create.php' . ($editId ? '?id=' . $editId : ''))) ?>" enctype="multipart/form-data" class="student-form" id="student-form" novalidate>
  <?= ssp_csrf_field() ?>
  <div class="form-layout">
    <aside class="form-nav" aria-label="Form sections">
      <ol>
        <?php foreach ($sections as $key => [$label]): ?>
        <li><a href="#sec-<?= e($key) ?>" data-target="sec-<?= e($key) ?>"><span class="dot" aria-hidden="true"></span><?= e($label) ?><?php if (!empty($sectionErrors[$key])): ?><span class="count" title="Problems in this section"><?= (int)$sectionErrors[$key] ?></span><?php endif; ?></a></li>
        <?php endforeach; ?>
      </ol>
      <p class="form-nav-hint">Use the buttons at the bottom to save. Nothing is sent to the university until you choose to.</p>
    </aside>

    <div class="form-main">
      <section class="card section" id="sec-enrollment">
        <?php $sectionHead('enrollment'); ?>
        <div class="grid">
          <?php if ($deptOptions): ?>
            <?php ssp_select('department', 'Department', $form, $errors, $deptOptions, ['required' => true, 'attrs' => ['data-role' => 'department']]); ?>
            <?php ssp_select('program', 'Program', $form, $errors, $progOptions, ['attrs' => ['data-role' => 'program'], 'placeholder' => '— optional —', 'hint' => 'Only programs of the selected department are shown.']); ?>
          <?php else: ?>
            <?php ssp_input('department', 'Department', $form, $errors, ['required' => true, 'placeholder' => 'CSE', 'hint' => 'ID, code or exact name']); ?>
            <?php ssp_input('program', 'Program', $form, $errors, ['placeholder' => 'B.Sc. in CSE', 'hint' => 'ID or exact name']); ?>
          <?php endif; ?>
          <?php ssp_input('semester', 'Admitted semester', $form, $errors, ['required' => true, 'list' => 'dl_semesters', 'placeholder' => 'Spring 2026', 'maxlength' => 30]); ?>
          <?php if (!$isSynced): ?>
            <?php ssp_input('student_id', 'University Student ID', $form, $errors, ['placeholder' => 'Leave empty – assigned by the university', 'maxlength' => 20,
                'hint' => 'Normally leave empty: the university continues the numbering of this semester / department / program. Fill in ONLY the ID issued by the university admin when the portal reports that no numbering exists yet.']); ?>
          <?php endif; ?>
          <?php ssp_input('year', 'Academic year', $form, $errors, ['placeholder' => '1st', 'maxlength' => 20]); ?>
          <?php ssp_input('batch', 'Batch', $form, $errors, ['placeholder' => '52nd Batch', 'maxlength' => 50]); ?>
          <?php ssp_select('semester_type', 'Semester type', $form, $errors, $enumOptions('semester_type', ['bi_semester', 'trimester']), ['placeholder' => '— default —']); ?>
          <?php ssp_select('shift', 'Shift', $form, $errors, $enumOptions('shift', ['Morning', 'Day', 'Evening']), ['placeholder' => '— optional —']); ?>
          <?php ssp_select('section', 'Section', $form, $errors, $enumOptions('section', ['A', 'B', 'C', 'D', 'E', 'F', 'G']), ['placeholder' => '— optional —']); ?>
          <?php ssp_select('status', 'Status at university', $form, $errors, $enumOptions('status', ['Active', 'Inactive', 'Graduated', 'Dropped', 'Not Admitted Yet']), ['placeholder' => 'University default', 'hint' => 'Choose "Active" when sending a final result so the student is marked Graduated.']); ?>
        </div>
      </section>

      <section class="card section" id="sec-student">
        <?php $sectionHead('student'); ?>
        <div class="grid">
          <?php ssp_input('name', 'Full name', $form, $errors, ['required' => true, 'maxlength' => 255, 'wide' => true, 'autocomplete' => 'off']); ?>
          <?php ssp_input('date_of_birth', 'Date of birth', $form, $errors, ['type' => 'date', 'max' => date('Y-m-d')]); ?>
          <?php ssp_select('sex', 'Sex', $form, $errors, $enumOptions('sex', ['Male', 'Female', 'Other'])); ?>
          <?php ssp_select('blood_group', 'Blood group', $form, $errors, $enumOptions('blood_group', ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])); ?>
          <?php ssp_input('religion', 'Religion', $form, $errors, ['maxlength' => 50]); ?>
          <?php ssp_input('nationality', 'Nationality', $form, $errors, ['maxlength' => 100]); ?>
          <?php ssp_input('country', 'Country', $form, $errors, ['maxlength' => 100]); ?>
          <?php ssp_input('place_of_birth', 'Place of birth', $form, $errors, ['maxlength' => 150]); ?>
          <?php ssp_input('nid', 'National ID / Birth reg. no.', $form, $errors, ['maxlength' => 50]); ?>
          <?php ssp_input('contact_no', 'Mobile', $form, $errors, ['type' => 'tel', 'placeholder' => '+8801711000000']); ?>
          <?php ssp_input('email', 'E-mail', $form, $errors, ['type' => 'email']); ?>
          <?php ssp_input('present_address', 'Present address', $form, $errors, ['type' => 'textarea', 'maxlength' => 1000, 'wide' => true]); ?>
          <?php ssp_input('permanent_address', 'Permanent address', $form, $errors, ['type' => 'textarea', 'maxlength' => 1000, 'wide' => true]); ?>
          <?php ssp_input('permanent_contact_no', 'Permanent phone', $form, $errors, ['type' => 'tel']); ?>
          <?php ssp_input('permanent_email', 'Permanent e-mail', $form, $errors, ['type' => 'email']); ?>
        </div>
        <h3>Parents</h3>
        <div class="grid">
          <?php ssp_input('father_name', "Father's name", $form, $errors, ['maxlength' => 255]); ?>
          <?php ssp_input('father_phone', "Father's phone", $form, $errors, ['type' => 'tel']); ?>
          <?php ssp_input('father_occupation', "Father's occupation", $form, $errors, ['maxlength' => 150]); ?>
          <?php ssp_input('mother_name', "Mother's name", $form, $errors, ['maxlength' => 255]); ?>
          <?php ssp_input('mother_phone', "Mother's phone", $form, $errors, ['type' => 'tel']); ?>
          <?php ssp_input('mother_occupation', "Mother's occupation", $form, $errors, ['maxlength' => 150]); ?>
        </div>
      </section>

      <section class="card section" id="sec-guardian">
        <?php $sectionHead('guardian'); ?>
        <div class="grid">
          <?php ssp_input('guardian.name', 'Name', $form, $errors, ['maxlength' => 255]); ?>
          <?php ssp_input('guardian.relationship', 'Relationship', $form, $errors, ['placeholder' => 'Father', 'maxlength' => 100]); ?>
          <?php ssp_input('guardian.phone', 'Phone', $form, $errors, ['type' => 'tel']); ?>
          <?php ssp_input('guardian.email', 'E-mail', $form, $errors, ['type' => 'email']); ?>
          <?php ssp_input('guardian.profession', 'Profession', $form, $errors, ['maxlength' => 150]); ?>
          <?php ssp_input('guardian.yearly_income', 'Yearly income (BDT)', $form, $errors, ['placeholder' => '650000']); ?>
          <?php ssp_input('guardian.address', 'Address', $form, $errors, ['type' => 'textarea', 'maxlength' => 1000, 'wide' => true]); ?>
        </div>
      </section>

      <section class="card section" id="sec-qualifications">
        <?php $sectionHead('qualifications', '<button type="button" class="btn btn-sm" id="add-qual" data-max="' . SSP_MAX_QUALIFICATIONS . '">' . ssp_icon('plus') . ' Add row</button>'); ?>
        <?php if (!empty($errors['academic_qualifications'])): ?><small class="error"><?= e($errors['academic_qualifications']) ?></small><?php endif; ?>
        <div id="qual-rows">
          <?php foreach ($qualRows as $i => $row) { $renderQualRow($i); } ?>
        </div>
        <template id="qual-template"><?php $renderQualRow('__i__'); ?></template>
        <p class="muted small">Blank rows are ignored. Up to <?= SSP_MAX_QUALIFICATIONS ?> rows.</p>
      </section>

      <section class="card section" id="sec-photo">
        <?php $sectionHead('photo'); ?>
        <div class="photo-row">
          <?php if ($existing && !empty($existing['photo_path'])): ?>
            <figure class="photo-current">
              <img src="<?= e(ssp_url('students/photo.php?id=' . $editId)) ?>" alt="Current photo">
              <label class="check"><input type="checkbox" name="remove_photo" value="1"> Remove current photo</label>
            </figure>
          <?php endif; ?>
          <div style="flex:1;min-width:240px">
            <?php ssp_input('photo', $existing && !empty($existing['photo_path']) ? 'Replace photo' : 'Photo', $form, $errors, ['type' => 'file', 'accept' => 'image/jpeg,image/png,image/gif,image/webp', 'hint' => 'JPG, PNG, GIF or WEBP, max 5 MB. A 300×400 JPEG is plenty.']); ?>
            <img id="photo-preview" class="photo-preview" alt="" hidden>
          </div>
        </div>
      </section>

      <section class="card section" id="sec-internal">
        <?php $sectionHead('internal', '<span class="badge badge-plain">' . ssp_icon('shield') . ' Portal only</span>'); ?>
        <div class="grid">
          <?php ssp_select('internal.apostille', 'Apostille', $form, $errors, $yesNo, ['placeholder' => '— not set —']); ?>
          <?php ssp_select('internal.online_only', 'Online only', $form, $errors, $yesNo, ['placeholder' => '— not set —']); ?>
          <?php ssp_select('internal.work_done', 'Work done', $form, $errors, $yesNo, ['placeholder' => '— not set —']); ?>
          <?php ssp_input('internal.reference', 'Reference', $form, $errors, ['list' => 'dl_reference', 'placeholder' => 'Bindu / Sir', 'maxlength' => SSP_INTERNAL_TEXTS['reference'], 'hint' => 'Who referred the student.']); ?>
          <?php ssp_input('internal.notes', 'Internal notes', $form, $errors, ['type' => 'textarea', 'rows' => 3, 'maxlength' => SSP_INTERNAL_TEXTS['notes'], 'wide' => true]); ?>
        </div>
        <h3>Documents</h3>
        <?php if (!empty($errors['files'])): ?><small class="error"><?= e($errors['files']) ?></small><?php endif; ?>
        <div class="file-slots">
          <?php foreach (SSP_FILE_KINDS as $kind => $label) { $renderFileSlot($kind, $label); } ?>
        </div>
        <p class="muted small">PDF, JPG, PNG, GIF, WEBP, DOC or DOCX; max <?= e($fileMaxMb) ?> MB per file, up to <?= SSP_FILES_PER_KIND ?> files per slot. Several files can be selected at once. Stored on this server only.</p>
      </section>

      <section class="card section" id="sec-result">
        <?php if ($isSynced): ?>
          <?php $sectionHead('result'); ?>
          <p class="muted">Results of a registered student are managed separately: use <a href="<?= e(ssp_url('students/result.php?id=' . $editId)) ?>">Publish / update final result</a> on the student page.</p>
        <?php else: ?>
          <?php $sectionHead('result', '<label class="check"><input type="checkbox" name="result_enabled" value="1" id="result-enabled"' . (!empty($form['result_enabled']) ? ' checked' : '') . '> Include final result</label>'); ?>
          <fieldset id="result-fields"<?= empty($form['result_enabled']) ? ' disabled' : '' ?>>
            <div class="grid">
              <?php ssp_input('result.semester', 'Completion semester', $form, $errors, ['required' => true, 'list' => 'dl_semesters', 'placeholder' => 'Fall 2024']); ?>
              <?php ssp_input('result.cgpa', 'Final CGPA', $form, $errors, ['required' => true, 'type' => 'number', 'step' => '0.01', 'min' => '0.01', 'max' => '4', 'placeholder' => '3.42']); ?>
              <?php ssp_input('result.recorded_date', 'Result publish date', $form, $errors, ['type' => 'date', 'max' => date('Y-m-d'), 'hint' => 'Defaults to today.']); ?>
              <?php ssp_input('result.batch', 'Batch', $form, $errors, ['maxlength' => 50, 'hint' => 'Defaults to the student batch above.']); ?>
              <div class="field">
                <label>&nbsp;</label>
                <label class="check"><input type="checkbox" name="result[mark_graduated]" value="1"<?= !empty($form['result']['mark_graduated']) ? ' checked' : '' ?>> Force status “Graduated”</label>
                <small class="hint">Applied automatically when status is Active or Dropped.</small>
              </div>
            </div>
            <p class="muted small">Requires the <code>results:create</code> scope on the partner key. If the result is invalid the university creates nothing and returns the errors here.</p>
          </fieldset>
        <?php endif; ?>
      </section>

      <?php ssp_datalist('dl_semesters', $semesters); ?>
      <?php ssp_datalist('dl_exams', $examNames); ?>
      <?php ssp_datalist('dl_boards', $boardNames); ?>
      <?php ssp_datalist('dl_groups', $groupNames); ?>
      <?php ssp_datalist('dl_reference', SSP_INTERNAL_REFERENCES); ?>

      <div class="form-bar">
        <button type="submit" name="action" value="save" class="btn"><?= $isSynced ? 'Save locally only' : 'Save draft' ?></button>
        <button type="submit" name="action" value="send" class="btn btn-primary" data-confirm="<?= $isSynced ? 'Update this student\'s record at Prime University now?' : 'Send this student to Prime University now?' ?>"<?= ssp_api()->isConfigured() ? '' : ' disabled title="API key not configured"' ?>><?= ssp_icon('send') ?> <?= $isSynced ? 'Save and update at university' : 'Save and send to university' ?></button>
        <a class="btn btn-ghost" href="<?= e(ssp_url($existing ? 'students/view.php?id=' . $editId : 'dashboard.php')) ?>">Cancel</a>
        <span class="spacer"></span>
        <span class="form-dirty" id="form-dirty" hidden><?= ssp_icon('alert-triangle') ?> Unsaved changes</span>
      </div>
    </div>
  </div>
</form>
<?php ssp_footer();
