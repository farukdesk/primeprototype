<?php
/**
 * Shared helpers for the "standard CV / profile" section on the user
 * create & edit pages.
 *
 * The section is driven by the Employee Type:
 *   • administrative → show the administrative (HR) CV fields.
 *   • educational (Faculty) → show the administrative CV fields PLUS the
 *     academic faculty CV fields.
 *
 * Administrative / personal HR data + academic qualifications + work
 * experiences are stored in staff_profiles / staff_qualifications /
 * staff_experiences (see admin/staff-profiles/sp-helpers.php).
 * Faculty academic data is stored in faculty_profiles
 * (see admin/faculty-profiles/fp-helpers.php).
 */

require_once __DIR__ . '/../staff-profiles/sp-helpers.php';
require_once __DIR__ . '/../faculty-profiles/fp-helpers.php';

// Faculty text fields captured on this page (posted with an `fp_` prefix to
// avoid colliding with the administrative field names).
const CV_FACULTY_FIELDS = [
    'designation', 'qualification', 'official_email', 'personal_email', 'phone', 'bio',
    'research_interest', 'publications', 'experience', 'office_location', 'room_number',
    'office_hours', 'courses_taught', 'google_scholar', 'orcid', 'research_profiles',
    'awards', 'professional_memberships', 'social_links', 'projects_grants', 'supervision',
    'skills', 'languages',
];

/**
 * List of active staff (employee) departments for the department dropdown.
 */
function cv_staff_departments(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = db()->query(
            "SELECT id, name, type FROM staff_departments WHERE is_active = 1
             ORDER BY type ASC, sort_order ASC, name ASC"
        )->fetchAll();
    }
    return $cache;
}

/**
 * Validate the optional uploaded files (photo + faculty CV PDF) without moving
 * them. Returns a list of human-readable error messages (empty if OK).
 */
function cv_validate_uploads(array $files): array
{
    $errors = [];

    if (!empty($files['sp_photo']['name'])) {
        $ext = strtolower(pathinfo($files['sp_photo']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, SP_PHOTO_EXTS, true)) {
            $errors[] = 'Invalid profile photo. Allowed: ' . implode(', ', SP_PHOTO_EXTS) . '.';
        } elseif (($files['sp_photo']['size'] ?? 0) > SP_PHOTO_MAX) {
            $errors[] = 'Profile photo is too large (max 5 MB).';
        }
    }

    if (!empty($files['fp_cv_file']['name'])) {
        $ext = strtolower(pathinfo($files['fp_cv_file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'pdf') {
            $errors[] = 'Invalid CV file. Only PDF is allowed.';
        }
    }

    return $errors;
}

/**
 * Persist the CV / profile data for a user based on the selected employee type.
 * Should be called only after cv_validate_uploads() reports no errors.
 *
 * @param int    $user_id  Target user id.
 * @param string $emp_type 'administrative' | 'educational' | '' (skip).
 * @param array  $post     The POST payload.
 * @param array  $files    The FILES payload.
 */
function cv_save_profiles(int $user_id, string $emp_type, array $post, array $files): void
{
    if (!in_array($emp_type, ['administrative', 'educational'], true)) {
        return; // "Not an employee" – nothing to store.
    }

    $db = db();

    // ── Administrative / personal HR data (staff_profiles) ──────────────────
    $existing = $db->prepare('SELECT photo FROM staff_profiles WHERE user_id = ?');
    $existing->execute([$user_id]);
    $photo = (string)($existing->fetchColumn() ?: '') ?: null;

    if (!empty($files['sp_photo']['name'])) {
        $uploaded = sp_upload_photo($files['sp_photo']);
        if ($uploaded !== false) {
            if ($photo) {
                $old = UPLOAD_DIR . '/staff-profiles/' . basename($photo);
                if (is_file($old)) @unlink($old);
            }
            $photo = $uploaded;
        }
    }

    $staff_dept_id = (int)($post['sp_staff_dept_id'] ?? 0) ?: null;

    $sp_val = static function (string $key) use ($post): ?string {
        $v = trim($post['sp_' . $key] ?? '');
        return $v === '' ? null : $v;
    };

    $job_type = $sp_val('job_type');
    if ($job_type !== null && !in_array($job_type, SP_JOB_TYPES, true)) {
        $job_type = null;
    }
    $gender = $sp_val('gender');
    if ($gender !== null && !in_array($gender, SP_GENDERS, true)) {
        $gender = null;
    }
    $employee_status = $sp_val('employee_status');
    if ($employee_status === null || !in_array($employee_status, SP_EMPLOYEE_STATUSES, true)) {
        $employee_status = 'Active';
    }

    $db->prepare(
        'INSERT INTO staff_profiles
            (user_id, department_type, photo, employee_id, staff_dept_id, designation,
             finger_id, father_name, mother_name, job_type, gender, religion, blood_group,
             national_id, date_of_birth, joining_date, nationality, birth_place, employee_status,
             emergency_contact_name, emergency_contact_relation, emergency_contact_address)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            department_type = VALUES(department_type),
            photo = VALUES(photo),
            employee_id = VALUES(employee_id),
            staff_dept_id = VALUES(staff_dept_id),
            designation = VALUES(designation),
            finger_id = VALUES(finger_id),
            father_name = VALUES(father_name),
            mother_name = VALUES(mother_name),
            job_type = VALUES(job_type),
            gender = VALUES(gender),
            religion = VALUES(religion),
            blood_group = VALUES(blood_group),
            national_id = VALUES(national_id),
            date_of_birth = VALUES(date_of_birth),
            joining_date = VALUES(joining_date),
            nationality = VALUES(nationality),
            birth_place = VALUES(birth_place),
            employee_status = VALUES(employee_status),
            emergency_contact_name = VALUES(emergency_contact_name),
            emergency_contact_relation = VALUES(emergency_contact_relation),
            emergency_contact_address = VALUES(emergency_contact_address)'
    )->execute([
        $user_id, $emp_type, $photo, $sp_val('employee_id'), $staff_dept_id, $sp_val('designation'),
        $sp_val('finger_id'), $sp_val('father_name'), $sp_val('mother_name'), $job_type, $gender,
        $sp_val('religion'), $sp_val('blood_group'), $sp_val('national_id'),
        $sp_val('date_of_birth'), $sp_val('joining_date'), $sp_val('nationality'),
        $sp_val('birth_place'), $employee_status,
        $sp_val('emergency_contact_name'), $sp_val('emergency_contact_relation'),
        $sp_val('emergency_contact_address'),
    ]);

    // Academic qualifications & work experiences (shared by all employees).
    sp_save_qualifications($user_id, $post);
    sp_save_experiences($user_id, $post);

    // ── Faculty academic data (faculty_profiles) ────────────────────────────
    if ($emp_type === 'educational') {
        cv_save_faculty($user_id, $post, $files);
    }
}

/**
 * Persist the faculty (academic) CV data into faculty_profiles.
 */
function cv_save_faculty(int $user_id, array $post, array $files): void
{
    $db = db();

    $fp = $db->prepare('SELECT photo, cv_file FROM faculty_profiles WHERE user_id = ?');
    $fp->execute([$user_id]);
    $current = $fp->fetch() ?: ['photo' => null, 'cv_file' => null];

    $data = [];
    foreach (CV_FACULTY_FIELDS as $f) {
        $data[$f] = trim($post['fp_' . $f] ?? '') ?: null;
    }

    $photo = $current['photo'] ?: null;
    if (!empty($files['fp_photo']['name'])) {
        $uploaded = fp_upload_file(
            $files['fp_photo'],
            ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            ['image/jpeg', 'image/png', 'image/gif', 'image/webp']
        );
        if ($uploaded !== false) {
            if ($photo) {
                $old = UPLOAD_DIR . '/faculty-profiles/' . basename($photo);
                if (is_file($old)) @unlink($old);
            }
            $photo = $uploaded;
        }
    }

    $cv_file = $current['cv_file'] ?: null;
    if (!empty($files['fp_cv_file']['name'])) {
        $uploaded_cv = fp_upload_file($files['fp_cv_file'], ['pdf'], ['application/pdf']);
        if ($uploaded_cv !== false) {
            if ($cv_file) {
                $old_cv = UPLOAD_DIR . '/faculty-profiles/' . basename($cv_file);
                if (is_file($old_cv)) @unlink($old_cv);
            }
            $cv_file = $uploaded_cv;
        }
    }

    $db->prepare(
        'INSERT INTO faculty_profiles
         (user_id, photo, designation, qualification, official_email, personal_email, phone, bio,
          research_interest, publications, experience, office_location, room_number, office_hours,
          courses_taught, google_scholar, orcid, research_profiles, cv_file, awards,
          professional_memberships, social_links, projects_grants, supervision, skills, languages)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
          photo=VALUES(photo), designation=VALUES(designation), qualification=VALUES(qualification),
          official_email=VALUES(official_email), personal_email=VALUES(personal_email), phone=VALUES(phone),
          bio=VALUES(bio), research_interest=VALUES(research_interest), publications=VALUES(publications),
          experience=VALUES(experience), office_location=VALUES(office_location), room_number=VALUES(room_number),
          office_hours=VALUES(office_hours), courses_taught=VALUES(courses_taught),
          google_scholar=VALUES(google_scholar), orcid=VALUES(orcid), research_profiles=VALUES(research_profiles),
          cv_file=VALUES(cv_file), awards=VALUES(awards), professional_memberships=VALUES(professional_memberships),
          social_links=VALUES(social_links), projects_grants=VALUES(projects_grants),
          supervision=VALUES(supervision), skills=VALUES(skills), languages=VALUES(languages)'
    )->execute([
        $user_id, $photo,
        $data['designation'], $data['qualification'], $data['official_email'], $data['personal_email'],
        $data['phone'], $data['bio'], $data['research_interest'], $data['publications'],
        $data['experience'], $data['office_location'], $data['room_number'], $data['office_hours'],
        $data['courses_taught'], $data['google_scholar'], $data['orcid'], $data['research_profiles'],
        $cv_file, $data['awards'], $data['professional_memberships'], $data['social_links'],
        $data['projects_grants'], $data['supervision'], $data['skills'], $data['languages'],
    ]);

    // Keep any linked dept_faculty records in sync with the edited designation/email.
    $user = $db->prepare('SELECT full_name FROM users WHERE id = ?');
    $user->execute([$user_id]);
    $full_name = (string)($user->fetchColumn() ?: '');
    $sync_email = $data['official_email'] ?? $data['personal_email'] ?? null;
    $db->prepare('UPDATE dept_faculty SET name=?, designation=?, email=? WHERE user_id=?')
       ->execute([$full_name, $data['designation'], $sync_email, $user_id]);
}

// ── Rendering ──────────────────────────────────────────────────────────────

/**
 * Rebuild repeatable rows (qualifications / experiences) from parallel POST
 * arrays so typed values survive a validation error. $map is a list of
 * [normalised_key => post_field_name]. Empty rows are dropped.
 */
function cv_posted_rows(array $post, array $map): array
{
    $cols = [];
    $count = 0;
    foreach ($map as $key => $field) {
        $cols[$key] = (array)($post[$field] ?? []);
        $count = max($count, count($cols[$key]));
    }
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $row      = [];
        $has_data = false;
        foreach ($cols as $key => $vals) {
            $v = trim($vals[$i] ?? '');
            $row[$key] = $v;
            if ($v !== '') $has_data = true;
        }
        if ($has_data) $rows[] = $row;
    }
    return $rows;
}

/**
 * Build the arrays needed to render the CV section for a given user.
 * Returns [$sp, $quals, $exps, $fp] where each element is already normalised
 * for cv_render_section().
 */
function cv_load_for_user(int $user_id): array
{
    $sp_stmt = db()->prepare('SELECT * FROM staff_profiles WHERE user_id = ?');
    $sp_stmt->execute([$user_id]);
    $sp = $sp_stmt->fetch() ?: [];

    $fp_stmt = db()->prepare('SELECT * FROM faculty_profiles WHERE user_id = ?');
    $fp_stmt->execute([$user_id]);
    $fp = $fp_stmt->fetch() ?: [];

    return [$sp, sp_qualifications($user_id), sp_experiences($user_id), $fp];
}

/** Small helper: render a text/date/select input for a staff field. */
function cv_sp_input(string $key, string $label, array $sp, string $type = 'text', string $attrs = ''): string
{
    $val = h($sp['sp_' . $key] ?? $sp[$key] ?? '');
    return '<div class="col-md-4">'
        . '<label class="form-label fw-medium">' . h($label) . '</label>'
        . '<input type="' . h($type) . '" name="sp_' . h($key) . '" class="form-control" '
        . 'style="border-radius:10px;" value="' . $val . '" ' . $attrs . '>'
        . '</div>';
}

/** Small helper: render a <select> for a staff field. */
function cv_sp_select(string $key, string $label, array $options, array $sp, string $placeholder = '— Select —'): string
{
    $cur = (string)($sp['sp_' . $key] ?? $sp[$key] ?? '');
    $out = '<div class="col-md-4"><label class="form-label fw-medium">' . h($label) . '</label>'
        . '<select name="sp_' . h($key) . '" class="form-select" style="border-radius:10px;">'
        . '<option value="">' . h($placeholder) . '</option>';
    foreach ($options as $opt) {
        $sel = ($cur === (string)$opt) ? ' selected' : '';
        $out .= '<option value="' . h($opt) . '"' . $sel . '>' . h($opt) . '</option>';
    }
    return $out . '</select></div>';
}

/** Small helper: render a faculty textarea. */
function cv_fp_textarea(string $key, string $label, array $fp, int $rows = 3, string $hint = ''): string
{
    $val = h($fp['fp_' . $key] ?? $fp[$key] ?? '');
    $h   = $hint !== '' ? '<small class="text-muted">' . h($hint) . '</small>' : '';
    return '<div class="col-12"><label class="form-label fw-medium">' . h($label) . '</label>'
        . '<textarea name="fp_' . h($key) . '" class="form-control" style="border-radius:10px;" rows="' . $rows . '">'
        . $val . '</textarea>' . $h . '</div>';
}

/** Small helper: render a faculty text/url/email input. */
function cv_fp_input(string $key, string $label, array $fp, string $type = 'text', string $attrs = ''): string
{
    $val = h($fp['fp_' . $key] ?? $fp[$key] ?? '');
    return '<div class="col-md-6"><label class="form-label fw-medium">' . h($label) . '</label>'
        . '<input type="' . h($type) . '" name="fp_' . h($key) . '" class="form-control" '
        . 'style="border-radius:10px;" value="' . $val . '" ' . $attrs . '></div>';
}

/**
 * Render the full "standard CV / profile" section.
 *
 * @param string $emp_type Currently selected employee type (drives visibility).
 * @param array  $sp       staff_profiles row (or POST-derived values).
 * @param array  $quals    List of qualification rows.
 * @param array  $exps     List of experience rows.
 * @param array  $fp       faculty_profiles row (or POST-derived values).
 */
function cv_render_section(string $emp_type, array $sp, array $quals, array $exps, array $fp): void
{
    $depts       = cv_staff_departments();
    $show_admin  = in_array($emp_type, ['administrative', 'educational'], true);
    $show_fac    = ($emp_type === 'educational');
    $sp_dept_cur = (int)($sp['sp_staff_dept_id'] ?? $sp['staff_dept_id'] ?? 0);

    if (empty($quals)) $quals = [[]];
    if (empty($exps))  $exps  = [[]];
    ?>
    <hr class="my-4">
    <h6 class="fw-semibold mb-1"><i class="fas fa-id-card me-2 text-muted"></i>Standard CV / Profile</h6>
    <p class="text-muted small mb-3" id="cv_hint_none" <?= $show_admin ? 'hidden' : '' ?>>
        Select an <strong>Employee Type</strong> above to add CV / profile details.
    </p>

    <!-- Administrative / personal CV -->
    <div id="cv-admin-section" <?= $show_admin ? '' : 'hidden' ?>>
        <div class="card mb-3">
            <div class="card-header py-2 px-3 bg-light">
                <span class="fw-semibold small"><i class="fas fa-user me-1 text-muted"></i>Personal Information</span>
            </div>
            <div class="card-body p-3">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-medium">Profile Photo</label>
                        <?php if (!empty($sp['photo'])): ?>
                        <div class="mb-2">
                            <img src="<?= UPLOAD_URL ?>/staff-profiles/<?= h($sp['photo']) ?>" alt=""
                                 style="height:64px;width:64px;border-radius:50%;object-fit:cover;border:2px solid #4f8ef7;">
                        </div>
                        <?php endif; ?>
                        <input type="file" name="sp_photo" class="form-control" style="border-radius:10px;"
                               accept=".jpg,.jpeg,.png,.gif,.webp">
                        <small class="text-muted">jpg, png, gif, webp. Max 5 MB.</small>
                    </div>
                    <?= cv_sp_input('father_name', "Father's Name", $sp, 'text', 'maxlength="200"') ?>
                    <?= cv_sp_input('mother_name', "Mother's Name", $sp, 'text', 'maxlength="200"') ?>
                    <?= cv_sp_select('gender', 'Gender', SP_GENDERS, $sp) ?>
                    <?= cv_sp_input('date_of_birth', 'Date of Birth', $sp, 'date') ?>
                    <?= cv_sp_input('birth_place', 'Birth Place', $sp, 'text', 'maxlength="200"') ?>
                    <?= cv_sp_input('religion', 'Religion', $sp, 'text', 'maxlength="100"') ?>
                    <?= cv_sp_select('blood_group', 'Blood Group', SP_BLOOD_GROUPS, $sp) ?>
                    <?= cv_sp_input('nationality', 'Nationality', $sp, 'text', 'maxlength="100"') ?>
                    <?= cv_sp_input('national_id', 'National ID', $sp, 'text', 'maxlength="100"') ?>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header py-2 px-3 bg-light">
                <span class="fw-semibold small"><i class="fas fa-briefcase me-1 text-muted"></i>Employment Details</span>
            </div>
            <div class="card-body p-3">
                <div class="row g-3">
                    <?= cv_sp_input('employee_id', 'Employee ID', $sp, 'text', 'maxlength="100"') ?>
                    <?= cv_sp_input('designation', 'Designation', $sp, 'text', 'maxlength="200"') ?>
                    <div class="col-md-4">
                        <label class="form-label fw-medium">Department</label>
                        <select name="sp_staff_dept_id" class="form-select" style="border-radius:10px;">
                            <option value="0">— Select —</option>
                            <?php foreach ($depts as $d): ?>
                            <option value="<?= (int)$d['id'] ?>" <?= $sp_dept_cur === (int)$d['id'] ? 'selected' : '' ?>>
                                <?= h($d['name']) ?> (<?= h(sp_employee_type_label($d['type'])) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?= cv_sp_select('job_type', 'Job Type / Category', SP_JOB_TYPES, $sp) ?>
                    <?= cv_sp_select('employee_status', 'Employee Status', SP_EMPLOYEE_STATUSES, $sp, 'Active') ?>
                    <?= cv_sp_input('joining_date', 'Joining Date', $sp, 'date') ?>
                    <?= cv_sp_input('finger_id', 'Finger / Device ID', $sp, 'text', 'maxlength="100"') ?>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header py-2 px-3 bg-light">
                <span class="fw-semibold small"><i class="fas fa-phone me-1 text-muted"></i>Emergency Contact</span>
            </div>
            <div class="card-body p-3">
                <div class="row g-3">
                    <?= cv_sp_input('emergency_contact_name', 'Contact Name', $sp, 'text', 'maxlength="150"') ?>
                    <?= cv_sp_input('emergency_contact_relation', 'Relationship', $sp, 'text', 'maxlength="100"') ?>
                    <div class="col-md-4">
                        <label class="form-label fw-medium">Address</label>
                        <input type="text" name="sp_emergency_contact_address" class="form-control"
                               style="border-radius:10px;"
                               value="<?= h($sp['sp_emergency_contact_address'] ?? $sp['emergency_contact_address'] ?? '') ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header py-2 px-3 bg-light d-flex justify-content-between align-items-center">
                <span class="fw-semibold small"><i class="fas fa-graduation-cap me-1 text-muted"></i>Academic Qualifications</span>
                <button type="button" class="btn btn-sm btn-outline-primary" style="border-radius:8px;"
                        onclick="cvAddRow('cv-quals')"><i class="fas fa-plus"></i> Add</button>
            </div>
            <div class="card-body p-3">
                <div id="cv-quals">
                    <?php foreach ($quals as $q): ?>
                    <div class="row g-2 mb-2 cv-repeat-row">
                        <div class="col-md-3"><input type="text" name="q_degree[]" class="form-control form-control-sm"
                            placeholder="Degree" value="<?= h($q['degree'] ?? '') ?>"></div>
                        <div class="col-md-2"><input type="text" name="q_group[]" class="form-control form-control-sm"
                            placeholder="Group / Major" value="<?= h($q['group_name'] ?? '') ?>"></div>
                        <div class="col-md-3"><input type="text" name="q_board[]" class="form-control form-control-sm"
                            placeholder="Board / University" value="<?= h($q['board_university'] ?? '') ?>"></div>
                        <div class="col-md-1"><input type="text" name="q_year[]" class="form-control form-control-sm"
                            placeholder="Year" value="<?= h($q['passing_year'] ?? '') ?>"></div>
                        <div class="col-md-1"><input type="text" name="q_grade[]" class="form-control form-control-sm"
                            placeholder="Grade" value="<?= h($q['grade'] ?? '') ?>"></div>
                        <div class="col-md-1"><input type="text" name="q_result[]" class="form-control form-control-sm"
                            placeholder="GPA" value="<?= h($q['gpa_result'] ?? '') ?>"></div>
                        <div class="col-md-1 d-flex align-items-center">
                            <button type="button" class="btn btn-sm btn-outline-danger" style="border-radius:8px;"
                                    onclick="cvRemoveRow(this)"><i class="fas fa-times"></i></button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header py-2 px-3 bg-light d-flex justify-content-between align-items-center">
                <span class="fw-semibold small"><i class="fas fa-building me-1 text-muted"></i>Work Experience</span>
                <button type="button" class="btn btn-sm btn-outline-primary" style="border-radius:8px;"
                        onclick="cvAddRow('cv-exps')"><i class="fas fa-plus"></i> Add</button>
            </div>
            <div class="card-body p-3">
                <div id="cv-exps">
                    <?php foreach ($exps as $x): ?>
                    <div class="row g-2 mb-2 cv-repeat-row">
                        <div class="col-md-3"><input type="text" name="x_position[]" class="form-control form-control-sm"
                            placeholder="Position" value="<?= h($x['position'] ?? '') ?>"></div>
                        <div class="col-md-3"><input type="text" name="x_organization[]" class="form-control form-control-sm"
                            placeholder="Organization" value="<?= h($x['organization'] ?? '') ?>"></div>
                        <div class="col-md-2"><input type="text" name="x_department[]" class="form-control form-control-sm"
                            placeholder="Department" value="<?= h($x['department'] ?? '') ?>"></div>
                        <div class="col-md-2"><input type="date" name="x_joining[]" class="form-control form-control-sm"
                            value="<?= h($x['joining_date'] ?? '') ?>"></div>
                        <div class="col-md-1"><input type="date" name="x_resign[]" class="form-control form-control-sm"
                            value="<?= h($x['resign_date'] ?? '') ?>"></div>
                        <div class="col-md-1 d-flex align-items-center">
                            <button type="button" class="btn btn-sm btn-outline-danger" style="border-radius:8px;"
                                    onclick="cvRemoveRow(this)"><i class="fas fa-times"></i></button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Faculty (academic) CV -->
    <div id="cv-faculty-section" <?= $show_fac ? '' : 'hidden' ?>>
        <div class="card mb-3 border-success">
            <div class="card-header py-2 px-3" style="background:#e8f5e9;">
                <span class="fw-semibold small"><i class="fas fa-chalkboard-teacher me-1 text-success"></i>Faculty Information</span>
            </div>
            <div class="card-body p-3">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-medium">Faculty Photo</label>
                        <?php if (!empty($fp['photo'])): ?>
                        <div class="mb-2">
                            <img src="<?= UPLOAD_URL ?>/faculty-profiles/<?= h($fp['photo']) ?>" alt=""
                                 style="height:64px;width:64px;border-radius:50%;object-fit:cover;border:2px solid #28a745;">
                        </div>
                        <?php endif; ?>
                        <input type="file" name="fp_photo" class="form-control" style="border-radius:10px;"
                               accept=".jpg,.jpeg,.png,.gif,.webp">
                        <small class="text-muted">Leave blank to keep current photo.</small>
                    </div>
                    <?= cv_fp_input('designation', 'Designation', $fp, 'text', 'maxlength="200"') ?>
                    <?= cv_fp_textarea('qualification', 'Qualifications', $fp, 3, 'e.g. PhD (Computer Science), MSc (Software Engineering)') ?>
                    <?= cv_fp_input('official_email', 'Official Email', $fp, 'email', 'maxlength="200"') ?>
                    <?= cv_fp_input('personal_email', 'Personal Email', $fp, 'email', 'maxlength="200"') ?>
                    <?= cv_fp_input('phone', 'Phone', $fp, 'text', 'maxlength="50"') ?>
                    <?= cv_fp_input('languages', 'Languages', $fp, 'text', 'maxlength="500"') ?>
                    <?= cv_fp_textarea('bio', 'Bio / About', $fp, 4) ?>
                    <?= cv_fp_textarea('research_interest', 'Research Interests', $fp, 3) ?>
                    <?= cv_fp_textarea('publications', 'Publications', $fp, 5, 'One per line or formatted text.') ?>
                    <?= cv_fp_textarea('experience', 'Academic Experience', $fp, 3) ?>
                    <?= cv_fp_textarea('courses_taught', 'Courses Taught', $fp, 3) ?>
                    <?= cv_fp_textarea('supervision', 'Supervision', $fp, 3, 'PhD, MSc, undergrad thesis supervision.') ?>
                    <?= cv_fp_input('office_location', 'Office Location', $fp, 'text', 'maxlength="300"') ?>
                    <?= cv_fp_input('room_number', 'Room Number', $fp, 'text', 'maxlength="100"') ?>
                    <?= cv_fp_input('office_hours', 'Office Hours', $fp, 'text', 'maxlength="300"') ?>
                    <?= cv_fp_input('google_scholar', 'Google Scholar URL', $fp, 'url', 'maxlength="500"') ?>
                    <?= cv_fp_input('orcid', 'ORCID URL', $fp, 'url', 'maxlength="500"') ?>
                    <?= cv_fp_textarea('research_profiles', 'Other Research Profiles', $fp, 2, 'ResearchGate, Scopus, etc. One URL per line.') ?>
                    <?= cv_fp_textarea('social_links', 'Social Links', $fp, 2, 'LinkedIn, personal website, etc. One URL per line.') ?>
                    <?= cv_fp_textarea('awards', 'Awards & Honors', $fp, 3) ?>
                    <?= cv_fp_textarea('professional_memberships', 'Professional Memberships', $fp, 3) ?>
                    <?= cv_fp_textarea('projects_grants', 'Projects & Grants', $fp, 3) ?>
                    <?= cv_fp_textarea('skills', 'Skills & Expertise', $fp, 2) ?>
                    <div class="col-12">
                        <label class="form-label fw-medium">CV / Resume (PDF)</label>
                        <?php if (!empty($fp['cv_file'])): ?>
                        <div class="mb-2">
                            <a href="<?= UPLOAD_URL ?>/faculty-profiles/<?= h($fp['cv_file']) ?>" target="_blank"
                               class="btn btn-sm btn-outline-secondary" style="border-radius:8px;">
                                <i class="fas fa-file-pdf me-1 text-danger"></i> View Current CV
                            </a>
                        </div>
                        <?php endif; ?>
                        <input type="file" name="fp_cv_file" class="form-control" style="border-radius:10px;" accept=".pdf">
                        <small class="text-muted">Leave blank to keep current CV. PDF only.</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Emit the JS that toggles the CV sections based on the Employee Type select
 * and powers the repeatable qualification / experience rows.
 */
function cv_render_script(): void
{
    ?>
    <script>
    function cvToggleSections() {
        var sel = document.getElementById('employee_type');
        if (!sel) return;
        var v = sel.value;
        var admin = document.getElementById('cv-admin-section');
        var fac   = document.getElementById('cv-faculty-section');
        var hint  = document.getElementById('cv_hint_none');
        var showAdmin = (v === 'administrative' || v === 'educational');
        if (admin) admin.hidden = !showAdmin;
        if (fac)   fac.hidden   = (v !== 'educational');
        if (hint)  hint.hidden  = showAdmin;
    }
    function cvAddRow(containerId) {
        var c = document.getElementById(containerId);
        if (!c) return;
        var rows = c.querySelectorAll('.cv-repeat-row');
        if (!rows.length) return;
        var clone = rows[0].cloneNode(true);
        clone.querySelectorAll('input').forEach(function (i) { i.value = ''; });
        c.appendChild(clone);
    }
    function cvRemoveRow(btn) {
        var row = btn.closest('.cv-repeat-row');
        if (!row) return;
        var container = row.parentNode;
        if (container.querySelectorAll('.cv-repeat-row').length > 1) {
            row.remove();
        } else {
            row.querySelectorAll('input').forEach(function (i) { i.value = ''; });
        }
    }
    document.addEventListener('DOMContentLoaded', function () {
        var sel = document.getElementById('employee_type');
        if (sel) sel.addEventListener('change', cvToggleSections);
        cvToggleSections();
    });
    </script>
    <?php
}
