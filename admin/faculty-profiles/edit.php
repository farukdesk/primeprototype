<?php
require_once __DIR__ . '/../includes/auth.php';
require_super_admin();

$user_id = (int)($_GET['user_id'] ?? 0);
if (!$user_id) { flash_set('error', 'Invalid user.'); redirect(APP_URL . '/faculty-profiles/index.php'); }

$user = db()->prepare('SELECT * FROM users WHERE id = ?');
$user->execute([$user_id]);
$user = $user->fetch();
if (!$user) { flash_set('error', 'User not found.'); redirect(APP_URL . '/faculty-profiles/index.php'); }

$fp_stmt = db()->prepare('SELECT * FROM faculty_profiles WHERE user_id = ?');
$fp_stmt->execute([$user_id]);
$fp = $fp_stmt->fetch() ?: [];

$page_title = 'Edit Profile – ' . ($user['full_name'] ?? '');
$errors = [];

require_once __DIR__ . '/fp-helpers.php';

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

        // Auto-sync name, designation and email to any existing dept_faculty records for this user.
        $sync_email = $data['official_email'] ?? $data['personal_email'] ?? null;
        db()->prepare(
            'UPDATE dept_faculty SET name=?, designation=?, email=? WHERE user_id=?'
        )->execute([
            $user['full_name'],
            $data['designation'],
            $sync_email,
            $user_id,
        ]);

        flash_set('success', 'Profile for <strong>' . h($user['full_name']) . '</strong> updated successfully.');
        redirect(APP_URL . '/faculty-profiles/index.php');
    }

    // Re-populate fp with POST data for re-display
    $fp = array_merge($fp, $data, ['photo' => $photo, 'cv_file' => $cv_file]);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/faculty-profiles/index.php">Faculty Profiles</a></li>
            <li class="breadcrumb-item active">Edit</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/faculty-profiles/index.php" class="btn btn-outline-secondary btn-sm" style="border-radius:10px;">
        <i class="fas fa-arrow-left me-1"></i> Back
    </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-body py-3 px-4 d-flex align-items-center gap-3">
        <div style="width:44px;height:44px;border-radius:50%;background:#4f8ef7;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.1rem;font-weight:700;flex-shrink:0;">
            <?= strtoupper(substr($user['full_name'] ?? 'F', 0, 1)) ?>
        </div>
        <div>
            <div class="fw-semibold"><?= h($user['full_name']) ?></div>
            <small class="text-muted"><?= h($user['email']) ?></small>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-id-card me-2 text-muted"></i>Edit Faculty Profile</h6>
    </div>
    <div class="card-body p-4">
        <form method="POST" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>

            <!-- Nav tabs -->
            <ul class="nav nav-tabs mb-4" id="profileTabs">
                <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-basic">Basic Info</a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-academic">Academic</a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-office">Office &amp; Contact</a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-online">Online Presence</a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-additional">Additional</a></li>
            </ul>

            <div class="tab-content">

                <!-- Tab 1: Basic Info -->
                <div class="tab-pane fade show active" id="tab-basic">
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
                    <i class="fas fa-save me-1"></i> Update Profile
                </button>
                <a href="<?= APP_URL ?>/faculty-profiles/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
