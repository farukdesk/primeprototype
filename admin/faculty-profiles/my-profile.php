<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('faculty-profile', 'can_edit');

$user_id      = auth_user()['id'];
$current_user = auth_user();

$fp_stmt = db()->prepare('SELECT * FROM faculty_profiles WHERE user_id = ?');
$fp_stmt->execute([$user_id]);
$fp = $fp_stmt->fetch() ?: [];

// Load active departments for the dropdown
$departments = db()->query(
    'SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
)->fetchAll();

$page_title = 'My Faculty Profile';
$errors = [];
$success = false;

require_once __DIR__ . '/fp-helpers.php';
require_once __DIR__ . '/../course-curriculum/helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $fields = [
        'designation', 'qualification', 'official_email', 'personal_email', 'phone', 'bio',
        'research_interest', 'publications', 'experience', 'office_location', 'room_number',
        'office_hours', 'courses_taught', 'google_scholar', 'orcid', 'research_profiles',
        'awards', 'professional_memberships', 'social_links', 'projects_grants', 'supervision',
        'skills', 'languages',
    ];
    $data = [];
    foreach ($fields as $f) {
        $data[$f] = trim($_POST[$f] ?? '') ?: null;
    }

    $dept_id_val = (int)($_POST['dept_id'] ?? 0) ?: null;

    $photo = $fp['photo'] ?? null;
    if (!empty($_FILES['photo']['name'])) {
        $uploaded = fp_upload_file(
            $_FILES['photo'],
            ['jpg','jpeg','png','gif','webp'],
            ['image/jpeg','image/png','image/gif','image/webp']
        );
        if ($uploaded === false) {
            $errors[] = 'Invalid photo. Allowed: jpg, jpeg, png, gif, webp.';
        } else {
            if (!empty($fp['photo'])) {
                $old = UPLOAD_DIR . '/faculty-profiles/' . basename($fp['photo']);
                if (file_exists($old)) @unlink($old);
            }
            $photo = $uploaded;
        }
    }

    $cv_file = $fp['cv_file'] ?? null;
    if (!empty($_FILES['cv_file']['name'])) {
        $uploaded_cv = fp_upload_file(
            $_FILES['cv_file'],
            ['pdf'],
            ['application/pdf']
        );
        if ($uploaded_cv === false) {
            $errors[] = 'Invalid CV file. Only PDF is allowed.';
        } else {
            if (!empty($fp['cv_file'])) {
                $old_cv = UPLOAD_DIR . '/faculty-profiles/' . basename($fp['cv_file']);
                if (file_exists($old_cv)) @unlink($old_cv);
            }
            $cv_file = $uploaded_cv;
        }
    }

    if (empty($errors)) {
        db()->prepare(
            'INSERT INTO faculty_profiles
             (user_id, dept_id, photo, designation, qualification, official_email, personal_email, phone, bio,
              research_interest, publications, experience, office_location, room_number, office_hours,
              courses_taught, google_scholar, orcid, research_profiles, cv_file, awards,
              professional_memberships, social_links, projects_grants, supervision, skills, languages)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
              dept_id=VALUES(dept_id), photo=VALUES(photo), designation=VALUES(designation),
              qualification=VALUES(qualification),
              official_email=VALUES(official_email), personal_email=VALUES(personal_email), phone=VALUES(phone),
              bio=VALUES(bio), research_interest=VALUES(research_interest), publications=VALUES(publications),
              experience=VALUES(experience), office_location=VALUES(office_location), room_number=VALUES(room_number),
              office_hours=VALUES(office_hours), courses_taught=VALUES(courses_taught),
              google_scholar=VALUES(google_scholar), orcid=VALUES(orcid), research_profiles=VALUES(research_profiles),
              cv_file=VALUES(cv_file), awards=VALUES(awards), professional_memberships=VALUES(professional_memberships),
              social_links=VALUES(social_links), projects_grants=VALUES(projects_grants),
              supervision=VALUES(supervision), skills=VALUES(skills), languages=VALUES(languages)'
        )->execute([
            $user_id, $dept_id_val, $photo,
            $data['designation'], $data['qualification'], $data['official_email'], $data['personal_email'],
            $data['phone'], $data['bio'], $data['research_interest'], $data['publications'],
            $data['experience'], $data['office_location'], $data['room_number'], $data['office_hours'],
            $data['courses_taught'], $data['google_scholar'], $data['orcid'], $data['research_profiles'],
            $cv_file, $data['awards'], $data['professional_memberships'], $data['social_links'],
            $data['projects_grants'], $data['supervision'], $data['skills'], $data['languages'],
        ]);

        // Auto-sync name, designation and email to any existing dept_faculty records for this user.
        $sync_email = $data['official_email'] ?? $data['personal_email'] ?? null;
        db()->prepare(
            'UPDATE dept_faculty SET name=?, designation=?, email=? WHERE user_id=?'
        )->execute([
            $current_user['full_name'],
            $data['designation'],
            $sync_email,
            $user_id,
        ]);

        // If a department was selected, ensure a dept_faculty record exists for that dept.
        if ($dept_id_val) {
            $existing = db()->prepare('SELECT id FROM dept_faculty WHERE user_id=? AND dept_id=?');
            $existing->execute([$user_id, $dept_id_val]);
            if (!$existing->fetch()) {
                db()->prepare(
                    'INSERT INTO dept_faculty (dept_id, user_id, name, designation, email, is_head, sort_order, is_active)
                     VALUES (?,?,?,?,?,0,99,1)'
                )->execute([
                    $dept_id_val,
                    $user_id,
                    $current_user['full_name'],
                    $data['designation'],
                    $sync_email,
                ]);
            }
        }

        $success = true;
        // Refresh fp from DB
        $fp_stmt->execute([$user_id]);
        $fp = $fp_stmt->fetch() ?: [];
    } else {
        $fp = array_merge($fp, $data, ['photo' => $photo, 'cv_file' => $cv_file, 'dept_id' => $dept_id_val]);
    }
}

// ── Load subject assignments for this faculty member ──────────────────────────
$subject_assignments = [];
$available_subjects  = [];

$my_dept_id = (int)($fp['dept_id'] ?? 0);

$sa_st = db()->prepare(
    "SELECT fsa.*,
            cc.course_name, cc.course_code, cc.credit, cc.semester,
            dap.program_name, d.name AS dept_name
       FROM faculty_subject_assignments fsa
       JOIN course_curriculum cc ON cc.id = fsa.course_id
       JOIN dept_academic_programs dap ON dap.id = cc.program_id
       JOIN dept_departments d ON d.id = dap.dept_id
      WHERE fsa.faculty_user_id = ?
      ORDER BY fsa.status ASC, cc.semester ASC, cc.course_name ASC"
);
$sa_st->execute([$user_id]);
$subject_assignments = $sa_st->fetchAll();

// Subjects available to assign (in this faculty's dept, not already requested)
$already_ids = array_column($subject_assignments, 'course_id');
if ($my_dept_id > 0) {
    $av_st = db()->prepare(
        "SELECT cc.id, cc.course_code, cc.course_name, cc.credit, cc.semester,
                dap.program_name
           FROM course_curriculum cc
           JOIN dept_academic_programs dap ON dap.id = cc.program_id
          WHERE dap.dept_id = ?
          ORDER BY cc.semester ASC, cc.course_name ASC"
    );
    $av_st->execute([$my_dept_id]);
    $all_subjects = $av_st->fetchAll();
    // Filter out already-requested subjects
    $available_subjects = array_filter($all_subjects, function ($s) use ($already_ids) {
        return !in_array((int)$s['id'], array_map('intval', $already_ids), true);
    });
}

// Active tab from hash (flash) — re-open subjects tab on redirect
$open_subjects_tab = isset($_GET['tab']) && $_GET['tab'] === 'subjects';

require_once __DIR__ . '/../includes/header.php';
echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css">';
echo '<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">My Faculty Profile</li>
        </ol>
    </nav>
</div>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle me-2"></i> <strong>Profile Updated!</strong> Your profile has been saved successfully.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="alert alert-info d-flex align-items-center gap-2 mb-3" style="border-radius:10px;">
    <i class="fas fa-info-circle"></i>
    <span>Your profile is visible on the public department page. Keep it up to date!</span>
</div>

<?php flash_show(); ?>

<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-id-card me-2 text-muted"></i>My Faculty Profile</h6>
    </div>
    <div class="card-body p-4">
        <form method="POST" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>

            <!-- Nav tabs -->
            <ul class="nav nav-tabs mb-4" id="profileTabs">
                <li class="nav-item"><a class="nav-link <?= $open_subjects_tab ? '' : 'active' ?>" data-bs-toggle="tab" href="#tab-basic">Basic Info</a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-academic">Academic</a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-office">Office &amp; Contact</a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-online">Online Presence</a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-additional">Additional</a></li>
                <li class="nav-item">
                    <a class="nav-link <?= $open_subjects_tab ? 'active' : '' ?>" data-bs-toggle="tab" href="#tab-subjects">
                        <i class="fas fa-book me-1"></i>Subjects
                        <?php
                        $pending_cnt = count(array_filter($subject_assignments, fn($a) => $a['status'] === 'pending'));
                        if ($pending_cnt > 0): ?>
                        <span class="badge bg-warning text-dark ms-1"><?= $pending_cnt ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/faculty-profiles/files.php"><i class="fas fa-folder-open me-1"></i>Files</a></li>
            </ul>

            <div class="tab-content">

                <!-- Tab 1: Basic Info -->
                <div class="tab-pane fade <?= $open_subjects_tab ? '' : 'show active' ?>" id="tab-basic">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Photo</label>
                            <?php if (!empty($fp['photo'])): ?>
                            <div class="mb-2">
                                <img src="<?= UPLOAD_URL ?>/faculty-profiles/<?= h($fp['photo']) ?>"
                                     alt="" style="height:80px;width:80px;border-radius:50%;object-fit:cover;border:2px solid #4f8ef7;">
                            </div>
                            <?php endif; ?>
                            <input type="file" name="photo" class="form-control" style="border-radius:10px;"
                                   accept=".jpg,.jpeg,.png,.gif,.webp">
                            <small class="text-muted">Leave blank to keep current photo. Allowed: jpg, jpeg, png, gif, webp.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Designation</label>
                            <input type="text" name="designation" class="form-control" style="border-radius:10px;"
                                   value="<?= h($fp['designation'] ?? '') ?>" maxlength="200">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium">Qualifications</label>
                            <textarea name="qualification" class="form-control" style="border-radius:10px;" rows="3"><?= h($fp['qualification'] ?? '') ?></textarea>
                            <small class="text-muted">e.g. PhD (Computer Science), MSc (Software Engineering)</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Official Email</label>
                            <input type="email" name="official_email" class="form-control" style="border-radius:10px;"
                                   value="<?= h($fp['official_email'] ?? '') ?>" maxlength="200">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Personal Email</label>
                            <input type="email" name="personal_email" class="form-control" style="border-radius:10px;"
                                   value="<?= h($fp['personal_email'] ?? '') ?>" maxlength="200">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Phone</label>
                            <input type="text" name="phone" class="form-control" style="border-radius:10px;"
                                   value="<?= h($fp['phone'] ?? '') ?>" maxlength="50">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium">Bio / About</label>
                            <textarea name="bio" class="form-control" style="border-radius:10px;" rows="5"><?= h($fp['bio'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Department</label>
                            <select name="dept_id" class="form-control" style="border-radius:10px;">
                                <option value="0">— Select your department —</option>
                                <?php foreach ($departments as $dept): ?>
                                <option value="<?= (int)$dept['id'] ?>"
                                    <?= (int)($fp['dept_id'] ?? 0) === (int)$dept['id'] ? 'selected' : '' ?>>
                                    <?= h($dept['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Select your primary department. You will be added to its faculty list if not already present. Contact an administrator to be removed from a previous department.</small>
                        </div>
                    </div>
                </div>

                <!-- Tab 2: Academic -->
                <div class="tab-pane fade" id="tab-academic">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-medium">Research Interests</label>
                            <textarea name="research_interest" class="form-control" style="border-radius:10px;" rows="4"><?= h($fp['research_interest'] ?? '') ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium">Publications</label>
                            <textarea name="publications" class="form-control" style="border-radius:10px;" rows="6"><?= h($fp['publications'] ?? '') ?></textarea>
                            <small class="text-muted">List publications, one per line or formatted text.</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium">Experience</label>
                            <textarea name="experience" class="form-control" style="border-radius:10px;" rows="4"><?= h($fp['experience'] ?? '') ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium">Courses Taught</label>
                            <textarea name="courses_taught" class="form-control" style="border-radius:10px;" rows="4"><?= h($fp['courses_taught'] ?? '') ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium">Supervision</label>
                            <textarea name="supervision" class="form-control" style="border-radius:10px;" rows="4"><?= h($fp['supervision'] ?? '') ?></textarea>
                            <small class="text-muted">PhD, MSc, undergrad thesis supervision details.</small>
                        </div>
                    </div>
                </div>

                <!-- Tab 3: Office & Contact -->
                <div class="tab-pane fade" id="tab-office">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Office Location</label>
                            <input type="text" name="office_location" class="form-control" style="border-radius:10px;"
                                   value="<?= h($fp['office_location'] ?? '') ?>" maxlength="300">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Room Number</label>
                            <input type="text" name="room_number" class="form-control" style="border-radius:10px;"
                                   value="<?= h($fp['room_number'] ?? '') ?>" maxlength="100">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium">Office Hours</label>
                            <input type="text" name="office_hours" class="form-control" style="border-radius:10px;"
                                   value="<?= h($fp['office_hours'] ?? '') ?>" maxlength="300"
                                   placeholder="e.g. Sunday–Thursday 10:00–12:00">
                        </div>
                    </div>
                </div>

                <!-- Tab 4: Online Presence -->
                <div class="tab-pane fade" id="tab-online">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Google Scholar URL</label>
                            <input type="url" name="google_scholar" class="form-control" style="border-radius:10px;"
                                   value="<?= h($fp['google_scholar'] ?? '') ?>" maxlength="500">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">ORCID URL</label>
                            <input type="url" name="orcid" class="form-control" style="border-radius:10px;"
                                   value="<?= h($fp['orcid'] ?? '') ?>" maxlength="500">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium">Other Research Profiles</label>
                            <textarea name="research_profiles" class="form-control" style="border-radius:10px;" rows="3"><?= h($fp['research_profiles'] ?? '') ?></textarea>
                            <small class="text-muted">ResearchGate, Academia.edu, Scopus, etc. One URL per line.</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium">Social Links</label>
                            <textarea name="social_links" class="form-control" style="border-radius:10px;" rows="3"><?= h($fp['social_links'] ?? '') ?></textarea>
                            <small class="text-muted">LinkedIn, Twitter/X, personal website, etc. One URL per line.</small>
                        </div>
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
                            <input type="file" name="cv_file" class="form-control" style="border-radius:10px;" accept=".pdf">
                            <small class="text-muted">Leave blank to keep current CV. PDF only.</small>
                        </div>
                    </div>
                </div>

                <!-- Tab 5: Additional -->
                <div class="tab-pane fade" id="tab-additional">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-medium">Awards &amp; Honors</label>
                            <textarea name="awards" class="form-control" style="border-radius:10px;" rows="4"><?= h($fp['awards'] ?? '') ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium">Professional Memberships</label>
                            <textarea name="professional_memberships" class="form-control" style="border-radius:10px;" rows="4"><?= h($fp['professional_memberships'] ?? '') ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium">Projects &amp; Grants</label>
                            <textarea name="projects_grants" class="form-control" style="border-radius:10px;" rows="4"><?= h($fp['projects_grants'] ?? '') ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium">Skills &amp; Expertise</label>
                            <textarea name="skills" class="form-control" style="border-radius:10px;" rows="3"><?= h($fp['skills'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Languages</label>
                            <input type="text" name="languages" class="form-control" style="border-radius:10px;"
                                   value="<?= h($fp['languages'] ?? '') ?>" maxlength="500"
                                   placeholder="e.g. English (Fluent), Bengali (Native)">
                        </div>
                    </div>
                </div>

            </div><!-- /.tab-content -->

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Save Profile
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── Subjects Tab (outside main form so the nested forms work) ───────────── -->
<div id="tab-subjects-outer" style="<?= $open_subjects_tab ? '' : 'display:none;' ?>">
<div class="card mt-4">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-book me-2 text-muted"></i>Subjects I Teach</h6>
    </div>
    <div class="card-body p-4">

        <?php if ($my_dept_id <= 0): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            Please set your <strong>Department</strong> in the Basic Info tab before adding subjects.
        </div>
        <?php else: ?>

        <!-- Add Subject form -->
        <?php if (!empty($available_subjects)): ?>
        <form method="POST" action="<?= APP_URL ?>/faculty-profiles/subject-assign.php" class="mb-4" id="subject-add-form">
            <?= csrf_field() ?>
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-8">
                    <label class="form-label fw-medium">Add a Subject <span class="text-muted small">(from your department's curriculum)</span></label>
                    <select name="course_id" id="subject_select" class="form-select" required>
                        <option value="">— Select a subject to add —</option>
                        <?php foreach ($available_subjects as $s):
                            $sem_label = cc_semester_label((int)$s['semester']);
                        ?>
                        <option value="<?= $s['id'] ?>">
                            <?= h(($s['course_code'] ? '[' . $s['course_code'] . '] ' : '') . $s['course_name'])
                              . ' — ' . h($s['program_name'])
                              . ' (' . h($sem_label) . ')' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <button type="submit" class="btn btn-success w-100" style="border-radius:10px;">
                        <i class="fas fa-plus me-1"></i> Request Teaching Assignment
                    </button>
                </div>
            </div>
            <div class="form-text mt-1">
                <i class="fas fa-info-circle me-1 text-info"></i>
                Your request will be sent to the Head of Department for approval.
            </div>
        </form>
        <?php else: ?>
        <div class="alert alert-info small mb-4">
            <i class="fas fa-info-circle me-2"></i>
            All available subjects in your department have already been requested or there are no subjects in the curriculum yet.
        </div>
        <?php endif; ?>

        <!-- Assignments list -->
        <?php if (empty($subject_assignments)): ?>
        <div class="text-center text-muted py-4">
            <i class="fas fa-book fa-2x mb-2 d-block" style="opacity:.3;"></i>
            No subject assignments yet. Use the form above to request teaching assignment.
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle" style="font-size:14px;">
                <thead class="table-light">
                    <tr>
                        <th style="width:40px;" class="px-3">#</th>
                        <th>Subject</th>
                        <th>Program</th>
                        <th style="width:70px;" class="text-center">Credit</th>
                        <th>Status</th>
                        <th>Submitted</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($subject_assignments as $i => $asgn): ?>
                <tr>
                    <td class="px-3"><?= $i + 1 ?></td>
                    <td>
                        <?php if ($asgn['course_code']): ?>
                        <span class="badge bg-light text-dark border me-1"><?= h($asgn['course_code']) ?></span>
                        <?php endif; ?>
                        <span class="fw-medium"><?= h($asgn['course_name']) ?></span>
                        <div class="text-muted small"><?= h(cc_semester_label((int)$asgn['semester'])) ?></div>
                    </td>
                    <td><?= h($asgn['program_name']) ?></td>
                    <td class="text-center">
                        <?= $asgn['credit'] !== null
                            ? '<span class="badge bg-secondary">' . h(number_format((float)$asgn['credit'], 2)) . '</span>'
                            : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td>
                        <?php if ($asgn['status'] === 'approved'): ?>
                        <span class="badge bg-success"><i class="fas fa-check me-1"></i>Approved</span>
                        <?php elseif ($asgn['status'] === 'pending'): ?>
                        <span class="badge bg-warning text-dark"><i class="fas fa-hourglass-half me-1"></i>Awaiting Approval</span>
                        <?php else: ?>
                        <span class="badge bg-danger"><i class="fas fa-times me-1"></i>Rejected</span>
                        <?php if ($asgn['notes']): ?>
                        <div class="text-muted small mt-1 fst-italic"><?= h(mb_strimwidth($asgn['notes'], 0, 80, '…')) ?></div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted"><?= h(date('d M Y', strtotime($asgn['created_at']))) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php endif; // end dept check ?>
    </div>
</div>
</div><!-- /#tab-subjects-outer -->

<script>
(function () {
    // Activate Subjects tab when clicking the nav link
    var subjectsTabLink = document.querySelector('a[href="#tab-subjects"]');
    var subjectsOuter   = document.getElementById('tab-subjects-outer');

    if (subjectsTabLink && subjectsOuter) {
        subjectsTabLink.addEventListener('shown.bs.tab', function () {
            subjectsOuter.style.display = '';
        });
        subjectsTabLink.addEventListener('hide.bs.tab', function () {
            subjectsOuter.style.display = 'none';
        });
    }

    // Tom Select for subject dropdown
    var subjectSel = document.getElementById('subject_select');
    if (subjectSel) {
        new TomSelect('#subject_select', {
            placeholder: '— Select a subject to add —',
            sortField: 'text',
        });
    }
    <?php if ($open_subjects_tab): ?>
    // Auto-activate subjects tab on page load if flag set
    if (subjectsTabLink) {
        var bsTab = bootstrap.Tab.getOrCreateInstance(subjectsTabLink);
        bsTab.show();
    }
    <?php endif; ?>
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
