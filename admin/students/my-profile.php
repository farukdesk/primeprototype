<?php
/**
 * Student Portal – My Profile
 * Allows the logged-in student to view their own profile and update
 * photo, phone number and email.  Admin-only sections (files, comments,
 * portal account) are intentionally hidden.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';

$user = auth_user();

// ── Find the student record linked to this portal user ────────────────────────
$student = null;
try {
    $stmt = db()->prepare(
        'SELECT s.*,
                d.name          AS dept_name,
                d.code          AS dept_code,
                d.faculty_label AS dept_faculty_label,
                p.program_name,
                p.program_type,
                b.name          AS batch_name,
                dist.name       AS district_name,
                dist.division   AS district_division,
                th.name         AS thana_name
         FROM students s
         JOIN dept_departments d ON d.id = s.dept_id
         LEFT JOIN dept_academic_programs p ON p.id = s.program_id
         LEFT JOIN student_batches b ON b.id = s.batch_id
         LEFT JOIN bd_districts dist ON dist.id = s.district_id
         LEFT JOIN bd_thanas th ON th.id = s.thana_id
         WHERE s.portal_user_id = ?
         LIMIT 1'
    );
    $stmt->execute([$user['id']]);
    $student = $stmt->fetch() ?: null;
} catch (Throwable $e) {}

if (!$student) {
    flash_set('error', 'No student profile is linked to your account. Please contact the administrator.');
    redirect(APP_URL . '/index.php');
}

$id = (int)$student['id']; // Always scoped to the authenticated portal user – never taken from user input
$page_title = 'My Profile';

// ── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // ── Update contact details (phone, email & present address) ─────────
    if ($action === 'update_contact') {
        $phone           = trim($_POST['phone'] ?? '');
        $email           = trim($_POST['email'] ?? '');
        $present_address = trim($_POST['present_address'] ?? '');

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash_set('error', 'Invalid email address.');
        } else {
            db()->prepare('UPDATE students SET phone = ?, email = ?, present_address = ? WHERE id = ?')
               ->execute([$phone ?: null, $email ?: null, $present_address ?: null, $id]);
            // Keep portal user account in sync
            db()->prepare('UPDATE users SET phone = ?, email = ? WHERE id = ?')
               ->execute([$phone ?: null, $email ?: null, $user['id']]);
            flash_set('success', 'Contact details updated successfully.');
        }
        redirect(APP_URL . '/students/my-profile.php');
    }

    // ── Upload / change photo ─────────────────────────────────────────────
    if ($action === 'upload_photo') {
        if (empty($_FILES['photo']['name'])) {
            flash_set('error', 'Please select a photo to upload.');
        } else {
            $uploaded = sm_upload_photo($_FILES['photo']);
            if ($uploaded === false) {
                flash_set('error', 'Invalid file type or size (max 5 MB). Allowed: JPG, PNG, GIF, WEBP.');
            } else {
                // Remove old photo file if it exists in the new upload directory
                if ($student['photo']) {
                    $old = UPLOAD_DIR . '/students/photos/' . $student['photo'];
                    if (is_file($old)) @unlink($old);
                }
                db()->prepare('UPDATE students SET photo = ? WHERE id = ?')
                   ->execute([$uploaded, $id]);
                flash_set('success', 'Photo updated successfully.');
            }
        }
        redirect(APP_URL . '/students/my-profile.php');
    }
}

// ── Fetch related data ────────────────────────────────────────────────────────
$qualifications = db()->prepare(
    'SELECT q.*, et.name AS exam_title_name, b.name AS board_name, g.name AS group_ref_name
     FROM student_academic_qualifications q
     LEFT JOIN student_exam_titles et ON et.id = q.exam_title_id
     LEFT JOIN student_boards b ON b.id = q.board_id
     LEFT JOIN student_groups g ON g.id = q.group_id
     WHERE q.student_id = ? ORDER BY q.sort_order ASC, q.id ASC'
);
$qualifications->execute([$id]);
$qualifications = $qualifications->fetchAll();

$results_stmt = db()->prepare(
    'SELECT * FROM student_results WHERE student_id = ? ORDER BY semester_year DESC, semester ASC, id ASC'
);
$results_stmt->execute([$id]);
$results = $results_stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';

$batchLabel = $student['batch_name'] ?? $student['batch'] ?? null;

$statusChipClass = match($student['status'] ?? '') {
    'Active'    => 'chip-status-active',
    'Inactive'  => 'chip-status-inactive',
    'Graduated' => 'chip-status-graduated',
    'Dropped'   => 'chip-status-dropped',
    default     => '',
};
?>

<style>
/* ── Student Profile Hero ──────────────────────────────────────────────────── */
.sv-hero {
    background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 60%, #3b82f6 100%);
    border-radius: 18px;
    padding: 28px 32px;
    margin-bottom: 24px;
    position: relative;
    overflow: hidden;
}
.sv-hero::before {
    content: '';
    position: absolute; top: -40px; right: -40px;
    width: 200px; height: 200px;
    background: rgba(255,255,255,.05);
    border-radius: 50%;
}
.sv-hero::after {
    content: '';
    position: absolute; bottom: -60px; right: 40px;
    width: 140px; height: 140px;
    background: rgba(255,255,255,.04);
    border-radius: 50%;
}
.sv-photo-wrap { position: relative; flex-shrink: 0; }
.sv-photo {
    width: 110px; height: 130px;
    object-fit: cover;
    border-radius: 14px;
    border: 3px solid rgba(255,255,255,.35);
    box-shadow: 0 8px 24px rgba(0,0,0,.25);
    display: block;
}
.sv-photo-placeholder {
    width: 110px; height: 130px;
    background: rgba(255,255,255,.12);
    border-radius: 14px;
    border: 3px solid rgba(255,255,255,.2);
    display: flex; align-items: center; justify-content: center;
    font-size: 3rem; color: rgba(255,255,255,.5);
}
.sv-photo-change-btn {
    position: absolute; bottom: -8px; right: -8px;
    width: 30px; height: 30px;
    background: #2563eb; color: #fff;
    border-radius: 50%; border: 2px solid #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: .72rem; cursor: pointer;
    box-shadow: 0 2px 8px rgba(0,0,0,.25);
    transition: background .15s;
}
.sv-photo-change-btn:hover { background: #1d4ed8; }
.sv-hero-name { color: #fff; font-size: 1.5rem; font-weight: 700; margin: 0 0 6px; }
.sv-hero-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
.sv-chip {
    background: rgba(255,255,255,.15);
    color: #fff; font-size: .75rem; font-weight: 600;
    padding: 3px 12px; border-radius: 20px;
    border: 1px solid rgba(255,255,255,.2);
    backdrop-filter: blur(4px);
}
.sv-chip.chip-id { font-family: monospace; font-size: .82rem; background: rgba(255,255,255,.22); }
.sv-chip.chip-status-active  { background: rgba(34,197,94,.25); border-color: rgba(34,197,94,.4); }
.sv-chip.chip-status-inactive { background: rgba(107,114,128,.25); border-color: rgba(107,114,128,.4); }
.sv-chip.chip-status-graduated { background: rgba(6,182,212,.25); border-color: rgba(6,182,212,.4); }
.sv-chip.chip-status-dropped  { background: rgba(239,68,68,.25); border-color: rgba(239,68,68,.4); }
.sv-hero-meta { color: rgba(255,255,255,.75); font-size: .82rem; }
.sv-hero-meta strong { color: rgba(255,255,255,.95); }
.sv-hero-contact { color: rgba(255,255,255,.65); font-size: .82rem; margin-top: 6px; }

/* ── Section cards ─────────────────────────────────────────────────────────── */
.sv-card {
    background: #fff;
    border: 1px solid #e8edf3;
    border-radius: 16px;
    margin-bottom: 20px;
    overflow: hidden;
    box-shadow: 0 1px 6px rgba(0,0,0,.04);
}
.sv-card-header {
    display: flex; align-items: center; gap: 10px;
    padding: 14px 22px;
    border-bottom: 1px solid #f0f3f8;
}
.sv-card-header-icon {
    width: 32px; height: 32px; border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    font-size: .85rem; flex-shrink: 0;
}
.sv-card-header-title { font-size: .9rem; font-weight: 700; color: #1e293b; margin: 0; }
.sv-card-body { padding: 18px 22px; }

/* ── Info rows ──────────────────────────────────────────────────────────────── */
.sv-info-row {
    display: flex; align-items: flex-start; gap: 8px;
    padding: 7px 0;
    border-bottom: 1px solid #f5f7fb;
}
.sv-info-row:last-child { border-bottom: none; }
.sv-info-icon { width: 18px; font-size: .78rem; color: #94a3b8; margin-top: 2px; flex-shrink: 0; text-align: center; }
.sv-info-label { min-width: 130px; font-size: .78rem; color: #7c8da6; font-weight: 600; flex-shrink: 0; }
.sv-info-value { font-size: .875rem; color: #1e293b; }

/* ── Parent sub-sections ────────────────────────────────────────────────────── */
.sv-parent-block { background: #f9fafc; border-radius: 12px; padding: 14px 16px; border: 1px solid #eef0f6; }
.sv-parent-label { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 10px; }

/* ── Table styles ───────────────────────────────────────────────────────────── */
.sv-table { width: 100%; font-size: .84rem; border-collapse: collapse; }
.sv-table thead th { background: #f4f7fc; color: #64748b; font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: 10px 14px; border-bottom: 1px solid #e8edf3; }
.sv-table tbody tr { border-bottom: 1px solid #f0f3f8; }
.sv-table tbody tr:last-child { border-bottom: none; }
.sv-table tbody td { padding: 10px 14px; color: #1e293b; vertical-align: middle; }
.sv-table tbody tr:hover td { background: #f8faff; }

/* ── Section anchor nav ─────────────────────────────────────────────────────── */
.sv-section-nav { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 20px; }
.sv-section-nav a {
    font-size: .78rem; font-weight: 600; padding: 5px 14px; border-radius: 20px;
    text-decoration: none; border: 1.5px solid #e2e8f0; color: #475569;
    background: #fff; transition: all .15s;
}
.sv-section-nav a:hover { background: #eff6ff; border-color: #93c5fd; color: #1d4ed8; }

/* ── Photo upload modal ─────────────────────────────────────────────────────── */
#photoDropZone {
    border: 2px dashed #cbd5e1;
    border-radius: 12px;
    padding: 28px;
    text-align: center;
    cursor: pointer;
    transition: border-color .2s, background .2s;
}
#photoDropZone:hover, #photoDropZone.drag-over {
    border-color: #2563eb;
    background: #eff6ff;
}
#photoPreview { max-height: 180px; border-radius: 10px; display: none; margin: 12px auto 0; }
</style>

<!-- ══════════════════════════════════════════════════════════
     HERO BANNER
═══════════════════════════════════════════════════════════ -->
<div class="sv-hero mb-4">
    <div class="d-flex align-items-start gap-4 flex-wrap">

        <!-- Photo (click to change) -->
        <div class="sv-photo-wrap">
            <?php if ($student['photo']): ?>
            <img src="<?= sm_photo_url($student['photo']) ?>" alt="Photo" class="sv-photo" id="heroPhoto">
            <?php else: ?>
            <div class="sv-photo-placeholder" id="heroPhoto"><i class="fas fa-user-graduate"></i></div>
            <?php endif; ?>
            <button type="button" class="sv-photo-change-btn" data-bs-toggle="modal" data-bs-target="#photoModal"
                    title="Change photo">
                <i class="fas fa-camera"></i>
            </button>
        </div>

        <!-- Info -->
        <div class="flex-fill" style="min-width:0;">
            <h2 class="sv-hero-name"><?= h($student['full_name']) ?></h2>

            <div class="sv-hero-chips">
                <span class="sv-chip chip-id">
                    <i class="fas fa-id-card me-1" style="opacity:.7;"></i><?= h($student['student_id']) ?>
                </span>
                <span class="sv-chip <?= $statusChipClass ?>"><?= h($student['status'] ?? '') ?></span>
                <?php if ($student['sex'] ?? null): ?>
                <span class="sv-chip"><?= h($student['sex']) ?></span>
                <?php endif; ?>
                <?php if (!empty($student['blood_group'])): ?>
                <span class="sv-chip"><i class="fas fa-tint me-1" style="opacity:.7;"></i><?= h($student['blood_group']) ?></span>
                <?php endif; ?>
            </div>

            <div class="sv-hero-meta">
                <?php if (!empty($student['dept_faculty_label'])): ?>
                    <strong><?= h($student['dept_faculty_label']) ?></strong> &nbsp;·&nbsp;
                <?php endif; ?>
                <strong><?= h($student['dept_name'] ?? '') ?></strong>
                <?php if ($student['program_name'] ?? null): ?>
                    &nbsp;·&nbsp; <?= h($student['program_name']) ?>
                    <?php if (!empty($student['program_type'])): ?>
                        <span style="opacity:.7;">(<?= h($student['program_type']) ?>)</span>
                    <?php endif; ?>
                <?php endif; ?>
                <br>
                <i class="fas fa-calendar-alt me-1" style="opacity:.6;"></i>Admitted:
                <strong><?= h($student['admitted_semester'] ?? '') ?></strong>
                <?php if ($batchLabel): ?>
                    &nbsp;·&nbsp; <i class="fas fa-layer-group me-1" style="opacity:.6;"></i>Batch: <strong><?= h($batchLabel) ?></strong>
                <?php endif; ?>
                <?php if (!empty($student['shift'])): ?>
                    &nbsp;·&nbsp; <?= h($student['shift']) ?> Shift
                <?php endif; ?>
                <?php if (!empty($student['semester_type'])): ?>
                    &nbsp;·&nbsp; <span style="opacity:.75;"><?= h(sm_semester_type_label($student['semester_type'], true)) ?></span>
                <?php endif; ?>
            </div>

            <?php if ($student['email'] || $student['phone']): ?>
            <div class="sv-hero-contact">
                <?php if ($student['email']): ?>
                    <i class="fas fa-envelope me-1"></i><?= h($student['email']) ?>
                <?php endif; ?>
                <?php if ($student['phone']): ?>
                    &nbsp; <i class="fas fa-phone me-1 ms-2"></i><?= h($student['phone']) ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     SECTION NAV
═══════════════════════════════════════════════════════════ -->
<div class="sv-section-nav">
    <a href="#sv-contact"><i class="fas fa-pen me-1"></i>Edit Contact</a>
    <a href="#sv-personal"><i class="fas fa-user me-1"></i>Personal</a>
    <a href="#sv-parents"><i class="fas fa-users me-1"></i>Parents</a>
    <a href="#sv-guardian"><i class="fas fa-user-shield me-1"></i>Guardian</a>
    <a href="#sv-quals"><i class="fas fa-graduation-cap me-1"></i>Qualifications</a>
    <?php if (!empty($results)): ?>
    <a href="#sv-results"><i class="fas fa-chart-bar me-1"></i>Results</a>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════
     EDIT CONTACT DETAILS (phone & email)
═══════════════════════════════════════════════════════════ -->
<div class="sv-card mb-4" id="sv-contact">
    <div class="sv-card-header">
        <div class="sv-card-header-icon" style="background:#eff6ff;color:#2563eb;"><i class="fas fa-pen"></i></div>
        <h6 class="sv-card-header-title">Update Contact Details</h6>
        <small class="ms-auto text-muted" style="font-size:.75rem;">You can update your phone, email and present address</small>
    </div>
    <div class="sv-card-body">
        <form method="POST" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_contact">
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold" style="font-size:.82rem;">
                        <i class="fas fa-phone me-1 text-muted"></i>Phone Number
                    </label>
                    <input type="text" class="form-control" name="phone"
                           value="<?= h($student['phone'] ?? '') ?>"
                           placeholder="+880…" maxlength="30">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold" style="font-size:.82rem;">
                        <i class="fas fa-envelope me-1 text-muted"></i>Email Address
                    </label>
                    <input type="email" class="form-control" name="email"
                           value="<?= h($student['email'] ?? '') ?>"
                           placeholder="your@email.com" maxlength="150">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold" style="font-size:.82rem;">
                        <i class="fas fa-home me-1 text-muted"></i>Present Address
                    </label>
                    <textarea class="form-control" name="present_address" rows="3"
                              placeholder="Enter your present address…" maxlength="500"><?= h($student['present_address'] ?? '') ?></textarea>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary px-4" style="border-radius:9px;font-weight:600;">
                        <i class="fas fa-save me-1"></i> Save Changes
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     PERSONAL DETAILS + PARENTS (two-column)
═══════════════════════════════════════════════════════════ -->
<div class="row g-4 mb-2" id="sv-personal">
    <!-- Personal Details -->
    <div class="col-12 col-lg-6">
        <div class="sv-card h-100">
            <div class="sv-card-header">
                <div class="sv-card-header-icon" style="background:#eff6ff;color:#2563eb;"><i class="fas fa-user"></i></div>
                <h6 class="sv-card-header-title">Personal Details</h6>
            </div>
            <div class="sv-card-body">
                <?php
                $personalInfo = [
                    ['fas fa-birthday-cake', 'Date of Birth',     $student['dob'] ?? null],
                    ['fas fa-map-marker-alt','Place of Birth',    $student['place_of_birth'] ?? null],
                    ['fas fa-id-card',       'NID',               $student['nid'] ?? null],
                    ['fas fa-ring',          'Marital Status',    $student['marital_status'] ?? null],
                    ['fas fa-passport',      'Passport No.',      $student['passport_no'] ?? null],
                    ['fas fa-pray',          'Religion',          $student['religion'] ?? null],
                    ['fas fa-globe',         'Nationality',       $student['nationality'] ?? null],
                    ['fas fa-flag',          'Country',           (!empty($student['country']) && $student['country'] !== 'Bangladesh') ? $student['country'] : null],
                    ['fas fa-map',           'District',          $student['district_name'] ?? null],
                    ['fas fa-map-pin',       'Thana / Upazila',   $student['thana_name'] ?? null],
                    ['fas fa-home',          'Present Address',   $student['present_address'] ?? null],
                    ['fas fa-map-marked-alt','Permanent Address', $student['permanent_address'] ?? null],
                ];
                $hasAny = false;
                foreach ($personalInfo as [$icon, $lbl, $val]) {
                    if (!$val) continue;
                    $hasAny = true;
                    echo '<div class="sv-info-row">';
                    echo '<div class="sv-info-icon"><i class="' . $icon . '"></i></div>';
                    echo '<div class="sv-info-label">' . $lbl . '</div>';
                    echo '<div class="sv-info-value">' . nl2br(h($val)) . '</div>';
                    echo '</div>';
                }
                if (!$hasAny): ?>
                <p class="text-muted mb-0" style="font-size:.85rem;">No personal details recorded.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Parents' Information -->
    <div class="col-12 col-lg-6" id="sv-parents">
        <div class="sv-card h-100">
            <div class="sv-card-header">
                <div class="sv-card-header-icon" style="background:#faf5ff;color:#7c3aed;"><i class="fas fa-users"></i></div>
                <h6 class="sv-card-header-title">Parents' Information</h6>
            </div>
            <div class="sv-card-body">
                <?php
                $fatherInfo = array_filter([
                    $student['father_name'] ?? null, $student['father_phone'] ?? null,
                    $student['father_occupation'] ?? null, $student['father_yearly_income'] ?? null,
                ]);
                $motherInfo = array_filter([
                    $student['mother_name'] ?? null, $student['mother_phone'] ?? null,
                    $student['mother_occupation'] ?? null, $student['mother_yearly_income'] ?? null,
                ]);
                if ($fatherInfo): ?>
                <div class="sv-parent-block mb-3">
                    <div class="sv-parent-label" style="color:#2563eb;"><i class="fas fa-male me-1"></i>Father</div>
                    <?php if ($student['father_name'] ?? null): ?>
                    <div class="sv-info-row">
                        <div class="sv-info-icon"><i class="fas fa-user"></i></div>
                        <div class="sv-info-label">Name</div>
                        <div class="sv-info-value fw-semibold"><?= h($student['father_name']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($student['father_phone'] ?? null): ?>
                    <div class="sv-info-row">
                        <div class="sv-info-icon"><i class="fas fa-phone"></i></div>
                        <div class="sv-info-label">Phone</div>
                        <div class="sv-info-value"><?= h($student['father_phone']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($student['father_occupation'] ?? null): ?>
                    <div class="sv-info-row">
                        <div class="sv-info-icon"><i class="fas fa-briefcase"></i></div>
                        <div class="sv-info-label">Occupation</div>
                        <div class="sv-info-value"><?= h($student['father_occupation']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($student['father_yearly_income'] ?? null): ?>
                    <div class="sv-info-row">
                        <div class="sv-info-icon"><i class="fas fa-coins"></i></div>
                        <div class="sv-info-label">Yearly Income</div>
                        <div class="sv-info-value">BDT <?= number_format((float)$student['father_yearly_income'], 2) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($motherInfo): ?>
                <div class="sv-parent-block">
                    <div class="sv-parent-label" style="color:#db2777;"><i class="fas fa-female me-1"></i>Mother</div>
                    <?php if ($student['mother_name'] ?? null): ?>
                    <div class="sv-info-row">
                        <div class="sv-info-icon"><i class="fas fa-user"></i></div>
                        <div class="sv-info-label">Name</div>
                        <div class="sv-info-value fw-semibold"><?= h($student['mother_name']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($student['mother_phone'] ?? null): ?>
                    <div class="sv-info-row">
                        <div class="sv-info-icon"><i class="fas fa-phone"></i></div>
                        <div class="sv-info-label">Phone</div>
                        <div class="sv-info-value"><?= h($student['mother_phone']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($student['mother_occupation'] ?? null): ?>
                    <div class="sv-info-row">
                        <div class="sv-info-icon"><i class="fas fa-briefcase"></i></div>
                        <div class="sv-info-label">Occupation</div>
                        <div class="sv-info-value"><?= h($student['mother_occupation']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($student['mother_yearly_income'] ?? null): ?>
                    <div class="sv-info-row">
                        <div class="sv-info-icon"><i class="fas fa-coins"></i></div>
                        <div class="sv-info-label">Yearly Income</div>
                        <div class="sv-info-value">BDT <?= number_format((float)$student['mother_yearly_income'], 2) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (!$fatherInfo && !$motherInfo): ?>
                <p class="text-muted mb-0" style="font-size:.85rem;">No parent information recorded.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$hasGuardian      = !empty($student['guardian_name']) || !empty($student['guardian_phone']) || !empty($student['guardian_address']);
$hasReference     = !empty($student['reference_name']) || !empty($student['reference_contact']) || !empty($student['reference_email']);
$hasLocalGuardian = !empty($student['local_guardian_name']) || !empty($student['local_guardian_contact']);
?>
<?php if ($hasGuardian || $hasReference || $hasLocalGuardian): ?>
<!-- ══════════════════════════════════════════════════════════
     GUARDIAN & REFERENCE
═══════════════════════════════════════════════════════════ -->
<div class="sv-card mb-2" id="sv-guardian">
    <div class="sv-card-header">
        <div class="sv-card-header-icon" style="background:#ecfeff;color:#0891b2;"><i class="fas fa-user-shield"></i></div>
        <h6 class="sv-card-header-title">Guardian &amp; Reference</h6>
    </div>
    <div class="sv-card-body">
        <div class="row g-4">
            <?php if ($hasGuardian): ?>
            <div class="col-12 col-lg-4">
                <div class="sv-parent-block h-100">
                    <div class="sv-parent-label" style="color:#0891b2;"><i class="fas fa-user-shield me-1"></i>Guardian</div>
                    <?php if ($student['guardian_name'] ?? null): ?>
                    <div class="sv-info-row">
                        <div class="sv-info-icon"><i class="fas fa-user"></i></div>
                        <div class="sv-info-label">Name</div>
                        <div class="sv-info-value fw-semibold"><?= h($student['guardian_name']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($student['guardian_relationship'] ?? null): ?>
                    <div class="sv-info-row">
                        <div class="sv-info-icon"><i class="fas fa-link"></i></div>
                        <div class="sv-info-label">Relationship</div>
                        <div class="sv-info-value"><?= h($student['guardian_relationship']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($student['guardian_phone'] ?? null): ?>
                    <div class="sv-info-row">
                        <div class="sv-info-icon"><i class="fas fa-phone"></i></div>
                        <div class="sv-info-label">Phone</div>
                        <div class="sv-info-value"><?= h($student['guardian_phone']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($student['guardian_address'] ?? null): ?>
                    <div class="sv-info-row">
                        <div class="sv-info-icon"><i class="fas fa-map-marker-alt"></i></div>
                        <div class="sv-info-label">Address</div>
                        <div class="sv-info-value"><?= nl2br(h($student['guardian_address'])) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($hasReference): ?>
            <div class="col-12 col-lg-4">
                <div class="sv-parent-block h-100">
                    <div class="sv-parent-label" style="color:#7c3aed;"><i class="fas fa-address-card me-1"></i>Reference Person</div>
                    <?php if ($student['reference_name'] ?? null): ?>
                    <div class="sv-info-row">
                        <div class="sv-info-icon"><i class="fas fa-user"></i></div>
                        <div class="sv-info-label">Name</div>
                        <div class="sv-info-value fw-semibold"><?= h($student['reference_name']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($student['reference_contact'] ?? null): ?>
                    <div class="sv-info-row">
                        <div class="sv-info-icon"><i class="fas fa-phone"></i></div>
                        <div class="sv-info-label">Contact</div>
                        <div class="sv-info-value"><?= h($student['reference_contact']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($student['reference_email'] ?? null): ?>
                    <div class="sv-info-row">
                        <div class="sv-info-icon"><i class="fas fa-envelope"></i></div>
                        <div class="sv-info-label">Email</div>
                        <div class="sv-info-value"><?= h($student['reference_email']) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($hasLocalGuardian): ?>
            <div class="col-12 col-lg-4">
                <div class="sv-parent-block h-100">
                    <div class="sv-parent-label" style="color:#16a34a;"><i class="fas fa-home me-1"></i>Local Guardian</div>
                    <?php if ($student['local_guardian_name'] ?? null): ?>
                    <div class="sv-info-row">
                        <div class="sv-info-icon"><i class="fas fa-user"></i></div>
                        <div class="sv-info-label">Name</div>
                        <div class="sv-info-value fw-semibold"><?= h($student['local_guardian_name']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($student['local_guardian_contact'] ?? null): ?>
                    <div class="sv-info-row">
                        <div class="sv-info-icon"><i class="fas fa-phone"></i></div>
                        <div class="sv-info-label">Contact</div>
                        <div class="sv-info-value"><?= h($student['local_guardian_contact']) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════
     ACADEMIC QUALIFICATIONS
═══════════════════════════════════════════════════════════ -->
<div class="sv-card mb-2" id="sv-quals">
    <div class="sv-card-header">
        <div class="sv-card-header-icon" style="background:#ecfeff;color:#0891b2;"><i class="fas fa-graduation-cap"></i></div>
        <h6 class="sv-card-header-title">Academic Qualifications</h6>
        <?php if (!empty($qualifications)): ?>
        <span class="badge ms-auto" style="background:#ecfeff;color:#0891b2;font-size:.72rem;">
            <?= count($qualifications) ?> record<?= count($qualifications) > 1 ? 's' : '' ?>
        </span>
        <?php endif; ?>
    </div>
    <?php if (empty($qualifications)): ?>
    <div class="sv-card-body">
        <p class="text-muted mb-0" style="font-size:.85rem;"><i class="fas fa-info-circle me-1"></i>No academic qualifications recorded.</p>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="sv-table">
            <thead>
                <tr>
                    <th style="padding-left:22px;">Exam</th>
                    <th>Session</th>
                    <th>Group</th>
                    <th>Board / University</th>
                    <th>Year</th>
                    <th>Grade / Division</th>
                    <th>Marks / GPA</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($qualifications as $q):
                $examLabel  = !empty($q['exam_title_id'])
                    ? ($q['exam_title_name'] ?? $q['exam_name'] ?? '—')
                    : ($q['exam_name'] ?? '—');
                $boardLabel = !empty($q['board_id'])
                    ? ($q['board_name'] ?? $q['board_university'] ?? '—')
                    : ($q['board_university'] ?? '—');
                $groupLabel = !empty($q['group_id'])
                    ? ($q['group_ref_name'] ?? $q['group_name'] ?? '—')
                    : ($q['group_name'] ?? '—');
            ?>
            <tr>
                <td style="padding-left:22px;"><strong><?= h($examLabel ?: '—') ?></strong></td>
                <td><?= h($q['session'] ?? '—') ?></td>
                <td><?= h($groupLabel ?: '—') ?></td>
                <td><?= h($boardLabel ?: '—') ?></td>
                <td><?= h($q['passing_year'] ?? '—') ?></td>
                <td><?= h($q['division_class_grade'] ?? '—') ?></td>
                <td><?= h($q['obtained_marks_gpa'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════
     ACADEMIC RESULTS
═══════════════════════════════════════════════════════════ -->
<?php if (!empty($results)): ?>
<div class="sv-card mb-4" id="sv-results">
    <div class="sv-card-header">
        <div class="sv-card-header-icon" style="background:#eff6ff;color:#2563eb;"><i class="fas fa-chart-bar"></i></div>
        <h6 class="sv-card-header-title">Academic Results</h6>
        <span class="badge ms-2" style="background:#eff6ff;color:#2563eb;font-size:.72rem;"><?= count($results) ?></span>
    </div>
    <div class="table-responsive">
        <table class="sv-table">
            <thead>
                <tr>
                    <th style="padding-left:22px;">Semester</th>
                    <th>Year</th>
                    <th>Batch</th>
                    <th>Subject</th>
                    <th>Code</th>
                    <th>Credits</th>
                    <th>Grade</th>
                    <th>GPA</th>
                    <th>CGPA</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($results as $r): ?>
            <tr>
                <td style="padding-left:22px;"><?= h($r['semester'] ?? '') ?></td>
                <td><?= h($r['semester_year'] ?? '') ?></td>
                <td><?= h($r['batch'] ?? '') ?></td>
                <td><?= h($r['subject'] ?? '') ?></td>
                <td><code style="font-size:.78rem;"><?= h($r['subject_code'] ?? '') ?></code></td>
                <td><?= h($r['credits'] ?? '') ?></td>
                <td><strong><?= h($r['grade'] ?? '') ?></strong></td>
                <td><?= h($r['gpa'] ?? '') ?></td>
                <td><strong><?= h($r['cgpa'] ?? '') ?></strong></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════
     PHOTO UPLOAD MODAL
═══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="photoModal" tabindex="-1" aria-labelledby="photoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:440px;">
        <div class="modal-content" style="border-radius:18px;border:none;overflow:hidden;">
            <div class="modal-header border-0 pb-0">
                <h6 class="modal-title fw-bold" id="photoModalLabel">
                    <i class="fas fa-camera me-2 text-primary"></i>Change Profile Photo
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-2">
                <form method="POST" action="" enctype="multipart/form-data" id="photoForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="upload_photo">
                    <div id="photoDropZone" onclick="document.getElementById('photoInput').click()">
                        <i class="fas fa-cloud-upload-alt fa-2x text-muted mb-2"></i>
                        <p class="mb-0 text-muted" style="font-size:.88rem;">Click to select or drag a photo here</p>
                        <p class="mb-0 text-muted" style="font-size:.75rem;">JPG, PNG, GIF, WEBP · Max 5 MB</p>
                    </div>
                    <input type="file" id="photoInput" name="photo" accept="image/*" class="d-none" required>
                    <img id="photoPreview" alt="Preview" class="d-block mx-auto">
                    <button type="submit" class="btn btn-primary w-100 mt-3" style="border-radius:10px;font-weight:600;display:none;" id="photoSubmitBtn">
                        <i class="fas fa-upload me-1"></i> Upload Photo
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Photo preview
document.getElementById('photoInput').addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function(e) {
        const prev = document.getElementById('photoPreview');
        prev.src = e.target.result;
        prev.style.display = 'block';
        document.getElementById('photoSubmitBtn').style.display = 'block';
    };
    reader.readAsDataURL(file);
});

// Drag and drop
const dz = document.getElementById('photoDropZone');
['dragenter','dragover'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.add('drag-over'); }));
['dragleave','drop'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.remove('drag-over'); }));
dz.addEventListener('drop', function(e) {
    const files = e.dataTransfer.files;
    if (files.length) {
        document.getElementById('photoInput').files = files;
        document.getElementById('photoInput').dispatchEvent(new Event('change'));
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
