<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('students');
require_once __DIR__ . '/helpers.php';

$id      = (int)($_GET['id'] ?? 0);
$student = sm_get_student($id);
$user    = auth_user();
$is_staff = sm_is_staff();

$page_title = 'Student – ' . $student['full_name'];

// ── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // ── Upload file ───────────────────────────────────────────────────────
    if ($action === 'upload_file' && sm_can_create()) {
        $file_name   = trim($_POST['file_name']   ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($file_name === '') {
            flash_set('error', 'File name is required.');
        } elseif (empty($_FILES['file']['name'])) {
            flash_set('error', 'Please select a file to upload.');
        } else {
            $uploaded = sm_upload_file($_FILES['file']);
            if ($uploaded === false) {
                flash_set('error', 'Invalid file type or size (max 20 MB). Allowed: images, PDF, Word, Excel, PPT, ZIP, TXT.');
            } else {
                db()->prepare(
                    'INSERT INTO student_files
                       (student_id, file_name, description, stored_name, original_name, mime_type, file_size, uploaded_by)
                     VALUES (?,?,?,?,?,?,?,?)'
                )->execute([
                    $id,
                    $file_name,
                    $description ?: null,
                    $uploaded['stored_name'],
                    $uploaded['original_name'],
                    $uploaded['mime_type'],
                    $uploaded['file_size'],
                    $user['id'],
                ]);
                log_change('students', 'UPDATE', $id,
                    $student['full_name'] . ' (' . $student['student_id'] . ')',
                    'file_upload', null, $file_name,
                    'File uploaded: ' . $file_name);
                flash_set('success', 'File uploaded successfully.');
            }
        }
        redirect(APP_URL . '/students/view.php?id=' . $id . '#files');
    }

    // ── Add comment ───────────────────────────────────────────────────────
    if ($action === 'add_comment') {
        $comment = trim($_POST['comment'] ?? '');
        if ($comment === '') {
            flash_set('error', 'Comment cannot be empty.');
        } else {
            db()->prepare(
                'INSERT INTO student_comments (student_id, user_id, comment)
                 VALUES (?,?,?)'
            )->execute([$id, $user['id'], $comment]);
            log_change('students', 'UPDATE', $id,
                $student['full_name'] . ' (' . $student['student_id'] . ')',
                'comment', null, null,
                'Comment added by ' . $user['full_name']);
            flash_set('success', 'Comment posted.');
        }
        redirect(APP_URL . '/students/view.php?id=' . $id . '#comments');
    }
}

// ── Fetch related data ────────────────────────────────────────────────────────
$qualifications = db()->prepare(
    'SELECT q.*,
            et.name AS exam_title_name,
            b.name  AS board_name,
            g.name  AS group_ref_name
     FROM student_academic_qualifications q
     LEFT JOIN student_exam_titles et ON et.id = q.exam_title_id
     LEFT JOIN student_boards b ON b.id = q.board_id
     LEFT JOIN student_groups g ON g.id = q.group_id
     WHERE q.student_id = ? ORDER BY q.sort_order ASC, q.id ASC'
);
$qualifications->execute([$id]);
$qualifications = $qualifications->fetchAll();

$files_stmt = db()->prepare(
    'SELECT sf.*, u.full_name AS uploader_name
     FROM student_files sf
     LEFT JOIN users u ON u.id = sf.uploaded_by
     WHERE sf.student_id = ?
     ORDER BY sf.created_at DESC'
);
$files_stmt->execute([$id]);
$files = $files_stmt->fetchAll();

$comments_stmt = db()->prepare(
    'SELECT sc.*, u.full_name AS commenter_name
     FROM student_comments sc
     JOIN users u ON u.id = sc.user_id
     WHERE sc.student_id = ?
     ORDER BY sc.created_at ASC'
);
$comments_stmt->execute([$id]);
$comments = $comments_stmt->fetchAll();

// Student results (migrated from s_result_entry)
$results_stmt = db()->prepare(
    'SELECT * FROM student_results WHERE student_id = ? ORDER BY semester_year DESC, semester ASC, id ASC'
);
$results_stmt->execute([$id]);
$results = $results_stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';

// Batch label for display
$batchLabel = $student['batch_name'] ?? $student['batch'] ?? null;
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
.sv-photo {
    width: 110px; height: 130px;
    object-fit: cover;
    border-radius: 14px;
    border: 3px solid rgba(255,255,255,.35);
    box-shadow: 0 8px 24px rgba(0,0,0,.25);
    flex-shrink: 0;
}
.sv-photo-placeholder {
    width: 110px; height: 130px;
    background: rgba(255,255,255,.12);
    border-radius: 14px;
    border: 3px solid rgba(255,255,255,.2);
    display: flex; align-items: center; justify-content: center;
    font-size: 3rem; color: rgba(255,255,255,.5);
    flex-shrink: 0;
}
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
.sv-hero-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.sv-hero-actions .btn-hero {
    padding: 7px 16px; font-size: .82rem; font-weight: 600; border-radius: 10px;
    border: 1.5px solid rgba(255,255,255,.35);
    color: #fff; background: rgba(255,255,255,.12);
    transition: all .15s; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;
}
.sv-hero-actions .btn-hero:hover { background: rgba(255,255,255,.22); border-color: rgba(255,255,255,.5); color: #fff; }
.sv-hero-actions .btn-hero-edit { background: rgba(255,255,255,.18); }
.sv-hero-actions .btn-hero-danger { border-color: rgba(239,68,68,.5); background: rgba(239,68,68,.15); }
.sv-hero-actions .btn-hero-danger:hover { background: rgba(239,68,68,.28); }

/* ── Quick stats bar ──────────────────────────────────────────────────────── */
.sv-stats-bar {
    display: flex; flex-wrap: wrap; gap: 12px;
    margin-bottom: 24px;
}
.sv-stat-card {
    flex: 1 1 160px;
    background: #fff;
    border: 1px solid #e8edf3;
    border-radius: 14px;
    padding: 14px 18px;
    display: flex; align-items: center; gap: 12px;
    box-shadow: 0 1px 4px rgba(0,0,0,.04);
}
.sv-stat-icon {
    width: 40px; height: 40px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem; flex-shrink: 0;
}
.sv-stat-label { font-size: .72rem; color: #8a94a6; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
.sv-stat-value { font-size: .95rem; font-weight: 700; color: #1e293b; }

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

/* ── Comment item ──────────────────────────────────────────────────────────── */
.sv-comment { display: flex; gap: 12px; margin-bottom: 16px; }
.sv-comment-avatar {
    width: 36px; height: 36px; border-radius: 50%;
    background: linear-gradient(135deg,#4f8ef7,#2563eb);
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-weight: 700; font-size: .85rem; flex-shrink: 0;
}
.sv-comment-bubble { background: #f4f7fc; border-radius: 0 12px 12px 12px; padding: 10px 14px; font-size: .875rem; line-height: 1.5; flex: 1; }

/* ── Section anchor nav ─────────────────────────────────────────────────────── */
.sv-section-nav { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 20px; }
.sv-section-nav a {
    font-size: .78rem; font-weight: 600; padding: 5px 14px; border-radius: 20px;
    text-decoration: none; border: 1.5px solid #e2e8f0; color: #475569;
    background: #fff; transition: all .15s;
}
.sv-section-nav a:hover { background: #eff6ff; border-color: #93c5fd; color: #1d4ed8; }
</style>

<?php
$statusChipClass = match($student['status']) {
    'Active'    => 'chip-status-active',
    'Inactive'  => 'chip-status-inactive',
    'Graduated' => 'chip-status-graduated',
    'Dropped'   => 'chip-status-dropped',
    default     => '',
};
?>

<!-- ══════════════════════════════════════════════════════════
     BREADCRUMB
═══════════════════════════════════════════════════════════ -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0" style="font-size:.83rem;">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/students/index.php">Students</a></li>
        <li class="breadcrumb-item active"><?= h($student['full_name']) ?></li>
    </ol>
</nav>

<!-- ══════════════════════════════════════════════════════════
     HERO BANNER
═══════════════════════════════════════════════════════════ -->
<div class="sv-hero mb-4">
    <div class="d-flex align-items-start gap-4 flex-wrap">
        <!-- Photo -->
        <?php if ($student['photo']): ?>
        <img src="<?= sm_photo_url($student['photo']) ?>" alt="Photo" class="sv-photo">
        <?php else: ?>
        <div class="sv-photo-placeholder"><i class="fas fa-user-graduate"></i></div>
        <?php endif; ?>

        <!-- Info -->
        <div class="flex-fill" style="min-width:0;">
            <h2 class="sv-hero-name"><?= h($student['full_name']) ?></h2>

            <div class="sv-hero-chips">
                <span class="sv-chip chip-id"><i class="fas fa-id-card me-1" style="opacity:.7;"></i><?= h($student['student_id']) ?></span>
                <span class="sv-chip <?= $statusChipClass ?>"><?= h($student['status']) ?></span>
                <?php if ($student['sex']): ?>
                <span class="sv-chip"><?= h($student['sex']) ?></span>
                <?php endif; ?>
                <?php if (!empty($student['blood_group'])): ?>
                <span class="sv-chip"><i class="fas fa-tint me-1" style="opacity:.7;"></i><?= h($student['blood_group']) ?></span>
                <?php endif; ?>
            </div>

            <div class="sv-hero-meta">
                <?php if (!empty($student['faculty_label'])): ?>
                    <strong><?= h($student['faculty_label']) ?></strong> &nbsp;·&nbsp;
                <?php endif; ?>
                <strong><?= h($student['dept_name']) ?></strong>
                <?php if ($student['program_name']): ?>
                    &nbsp;·&nbsp; <?= h($student['program_name']) ?>
                    <?php if (!empty($student['program_type'])): ?>
                        <span style="opacity:.7;">(<?= h($student['program_type']) ?>)</span>
                    <?php endif; ?>
                <?php endif; ?>
                <br>
                <i class="fas fa-calendar-alt me-1" style="opacity:.6;"></i>Admitted: <strong><?= h($student['admitted_semester']) ?></strong>
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

        <!-- Actions -->
        <div class="sv-hero-actions align-self-start ms-auto flex-shrink-0">
            <a href="<?= APP_URL ?>/students/statement.php?id=<?= $id ?>" target="_blank" class="btn-hero"
               title="Download / Print student enrollment statement">
                <i class="fas fa-file-download"></i> Statement
            </a>
            <?php if (is_super_admin() || can_access('student-verification', 'can_create')): ?>
            <a href="<?= APP_URL ?>/student-verification/verify.php?student_id=<?= $id ?>" class="btn-hero">
                <i class="fas fa-shield-alt"></i> Verify
            </a>
            <?php endif; ?>
            <?php if ($is_staff): ?>
            <a href="<?= APP_URL ?>/students/edit.php?id=<?= $id ?>" class="btn-hero btn-hero-edit">
                <i class="fas fa-edit"></i> Edit
            </a>
            <?php endif; ?>
            <?php if (sm_can_delete()): ?>
            <form method="POST" action="<?= APP_URL ?>/students/delete.php" style="display:inline;"
                  onsubmit="return confirm('Delete this student permanently?');">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <button type="submit" class="btn-hero btn-hero-danger">
                    <i class="fas fa-trash"></i> Delete
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     SECTION NAV
═══════════════════════════════════════════════════════════ -->
<div class="sv-section-nav">
    <a href="#sv-personal"><i class="fas fa-user me-1"></i>Personal</a>
    <a href="#sv-parents"><i class="fas fa-users me-1"></i>Parents</a>
    <a href="#sv-quals"><i class="fas fa-graduation-cap me-1"></i>Qualifications</a>
    <a href="#sv-files"><i class="fas fa-folder-open me-1"></i>Files</a>
    <a href="#sv-comments"><i class="fas fa-comments me-1"></i>Comments</a>
    <?php if (!empty($results)): ?>
    <a href="#sv-results"><i class="fas fa-chart-bar me-1"></i>Results</a>
    <?php endif; ?>
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
                    ['fas fa-birthday-cake', 'Date of Birth',     $student['dob']],
                    ['fas fa-map-marker-alt','Place of Birth',    $student['place_of_birth']],
                    ['fas fa-id-card',       'NID',               $student['nid']],
                    ['fas fa-pray',          'Religion',          $student['religion']],
                    ['fas fa-globe',         'Nationality',       $student['nationality']],
                    ['fas fa-flag',          'Country',           (!empty($student['country']) && $student['country'] !== 'Bangladesh') ? $student['country'] : null],
                    ['fas fa-map',           'District',          $student['district_name'] ?? null],
                    ['fas fa-map-pin',       'Thana / Upazila',   $student['thana_name'] ?? null],
                    ['fas fa-home',          'Present Address',   $student['present_address']],
                    ['fas fa-map-marked-alt','Permanent Address', $student['permanent_address']],
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
                if (!$hasAny):
                ?>
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
                    $student['father_name'], $student['father_phone'],
                    $student['father_occupation'], $student['father_yearly_income'],
                ]);
                $motherInfo = array_filter([
                    $student['mother_name'], $student['mother_phone'],
                    $student['mother_occupation'], $student['mother_yearly_income'],
                ]);
                if ($fatherInfo):
                ?>
                <div class="sv-parent-block mb-3">
                    <div class="sv-parent-label" style="color:#2563eb;"><i class="fas fa-male me-1"></i>Father</div>
                    <?php if ($student['father_name']): ?>
                    <div class="sv-info-row">
                        <div class="sv-info-icon"><i class="fas fa-user"></i></div>
                        <div class="sv-info-label">Name</div>
                        <div class="sv-info-value fw-semibold"><?= h($student['father_name']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($student['father_phone']): ?>
                    <div class="sv-info-row">
                        <div class="sv-info-icon"><i class="fas fa-phone"></i></div>
                        <div class="sv-info-label">Phone</div>
                        <div class="sv-info-value"><?= h($student['father_phone']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($student['father_occupation']): ?>
                    <div class="sv-info-row">
                        <div class="sv-info-icon"><i class="fas fa-briefcase"></i></div>
                        <div class="sv-info-label">Occupation</div>
                        <div class="sv-info-value"><?= h($student['father_occupation']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($student['father_yearly_income']): ?>
                    <div class="sv-info-row">
                        <div class="sv-info-icon"><i class="fas fa-coins"></i></div>
                        <div class="sv-info-label">Yearly Income</div>
                        <div class="sv-info-value">BDT <?= number_format($student['father_yearly_income'], 2) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($motherInfo): ?>
                <div class="sv-parent-block">
                    <div class="sv-parent-label" style="color:#db2777;"><i class="fas fa-female me-1"></i>Mother</div>
                    <?php if ($student['mother_name']): ?>
                    <div class="sv-info-row">
                        <div class="sv-info-icon"><i class="fas fa-user"></i></div>
                        <div class="sv-info-label">Name</div>
                        <div class="sv-info-value fw-semibold"><?= h($student['mother_name']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($student['mother_phone']): ?>
                    <div class="sv-info-row">
                        <div class="sv-info-icon"><i class="fas fa-phone"></i></div>
                        <div class="sv-info-label">Phone</div>
                        <div class="sv-info-value"><?= h($student['mother_phone']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($student['mother_occupation']): ?>
                    <div class="sv-info-row">
                        <div class="sv-info-icon"><i class="fas fa-briefcase"></i></div>
                        <div class="sv-info-label">Occupation</div>
                        <div class="sv-info-value"><?= h($student['mother_occupation']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($student['mother_yearly_income']): ?>
                    <div class="sv-info-row">
                        <div class="sv-info-icon"><i class="fas fa-coins"></i></div>
                        <div class="sv-info-label">Yearly Income</div>
                        <div class="sv-info-value">BDT <?= number_format($student['mother_yearly_income'], 2) ?></div>
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

<!-- ══════════════════════════════════════════════════════════
     ACADEMIC QUALIFICATIONS
═══════════════════════════════════════════════════════════ -->
<div class="sv-card mb-2" id="sv-quals">
    <div class="sv-card-header">
        <div class="sv-card-header-icon" style="background:#ecfeff;color:#0891b2;"><i class="fas fa-graduation-cap"></i></div>
        <h6 class="sv-card-header-title">Academic Qualifications</h6>
        <?php if (!empty($qualifications)): ?>
        <span class="badge ms-auto" style="background:#ecfeff;color:#0891b2;font-size:.72rem;"><?= count($qualifications) ?> record<?= count($qualifications) > 1 ? 's' : '' ?></span>
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
     STUDENT FILES
═══════════════════════════════════════════════════════════ -->
<div class="sv-card mb-2" id="sv-files">
    <div class="sv-card-header">
        <div class="sv-card-header-icon" style="background:#f0fdf4;color:#16a34a;"><i class="fas fa-folder-open"></i></div>
        <h6 class="sv-card-header-title">Student Files</h6>
        <?php if (!empty($files)): ?>
        <span class="badge ms-2" style="background:#f0fdf4;color:#16a34a;font-size:.72rem;"><?= count($files) ?></span>
        <?php endif; ?>
        <?php if (sm_can_create()): ?>
        <button class="btn btn-sm ms-auto" style="background:#f0fdf4;color:#16a34a;border:1.5px solid #bbf7d0;border-radius:9px;font-size:.8rem;font-weight:600;"
                data-bs-toggle="collapse" data-bs-target="#uploadFileForm">
            <i class="fas fa-upload me-1"></i> Upload File
        </button>
        <?php endif; ?>
    </div>

    <?php if (sm_can_create()): ?>
    <div class="collapse" id="uploadFileForm">
        <div style="background:#f8fafb;border-bottom:1px solid #e8edf3;padding:16px 22px;">
            <form method="POST" action="" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="upload_file">
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-md-3">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">File Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" name="file_name"
                               placeholder="e.g. National ID Card" maxlength="200" required>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Description</label>
                        <input type="text" class="form-control form-control-sm" name="description"
                               placeholder="Optional description" maxlength="500">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">File <span class="text-danger">*</span></label>
                        <input type="file" class="form-control form-control-sm" name="file" required>
                    </div>
                    <div class="col-12 col-md-2">
                        <button type="submit" class="btn btn-success btn-sm w-100" style="border-radius:8px;">
                            <i class="fas fa-upload me-1"></i> Upload
                        </button>
                    </div>
                </div>
                <small class="text-muted" style="font-size:.75rem;">Max 20 MB – Images, PDF, Word, Excel, PPT, ZIP, TXT</small>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if (empty($files)): ?>
    <div class="sv-card-body">
        <p class="text-muted mb-0" style="font-size:.85rem;"><i class="fas fa-info-circle me-1"></i>No files attached to this student.</p>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="sv-table">
            <thead>
                <tr>
                    <th style="padding-left:22px;">File</th>
                    <th>Description</th>
                    <th>Type / Size</th>
                    <th>Uploaded By</th>
                    <th>Date</th>
                    <th style="text-align:right;padding-right:22px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($files as $f): ?>
            <?php $ext = strtolower(pathinfo($f['original_name'], PATHINFO_EXTENSION)); ?>
            <tr>
                <td style="padding-left:22px;">
                    <i class="<?= sm_file_icon($ext) ?> me-2"></i>
                    <strong><?= h($f['file_name']) ?></strong>
                    <div><small class="text-muted"><?= h($f['original_name']) ?></small></div>
                </td>
                <td><small class="text-muted"><?= h($f['description'] ?? '—') ?></small></td>
                <td>
                    <code style="font-size:.75rem;"><?= strtoupper($ext) ?></code>
                    <div><small class="text-muted"><?= $f['file_size'] ? sm_format_size((int)$f['file_size']) : '—' ?></small></div>
                </td>
                <td style="font-size:.85rem;"><?= h($f['uploader_name'] ?? '—') ?></td>
                <td style="font-size:.8rem;color:#6b7280;"><?= date('M d, Y', strtotime($f['created_at'])) ?></td>
                <td style="text-align:right;padding-right:22px;">
                    <div class="d-flex gap-1 justify-content-end">
                        <a href="<?= UPLOAD_URL ?>/students/files/<?= h($f['stored_name']) ?>"
                           target="_blank" class="btn btn-sm btn-outline-info" title="Download" style="border-radius:7px;">
                            <i class="fas fa-download"></i>
                        </a>
                        <?php if (sm_can_delete()): ?>
                        <form method="POST" action="<?= APP_URL ?>/students/file-delete.php"
                              onsubmit="return confirm('Delete this file?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id"         value="<?= $f['id'] ?>">
                            <input type="hidden" name="student_id" value="<?= $id ?>">
                            <button class="btn btn-sm btn-outline-danger" title="Delete" style="border-radius:7px;">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════
     COMMENTS
═══════════════════════════════════════════════════════════ -->
<div class="sv-card mb-2" id="sv-comments">
    <div class="sv-card-header">
        <div class="sv-card-header-icon" style="background:#fffbeb;color:#d97706;"><i class="fas fa-comments"></i></div>
        <h6 class="sv-card-header-title">Comments</h6>
        <?php if (!empty($comments)): ?>
        <span class="badge ms-2" style="background:#fffbeb;color:#d97706;font-size:.72rem;"><?= count($comments) ?></span>
        <?php endif; ?>
    </div>
    <div class="sv-card-body">
        <?php if (empty($comments)): ?>
        <p class="text-muted mb-3" style="font-size:.85rem;">No comments yet. Be the first to add one.</p>
        <?php else: ?>
        <?php foreach ($comments as $c): ?>
        <div class="sv-comment">
            <div class="sv-comment-avatar"><?= strtoupper(substr($c['commenter_name'], 0, 1)) ?></div>
            <div class="flex-fill">
                <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                    <strong style="font-size:.875rem;"><?= h($c['commenter_name']) ?></strong>
                    <small class="text-muted"><?= date('M d, Y H:i', strtotime($c['created_at'])) ?></small>
                    <?php if (sm_can_delete() || (int)$c['user_id'] === (int)$user['id']): ?>
                    <form method="POST" action="<?= APP_URL ?>/students/comment-delete.php" class="ms-auto"
                          onsubmit="return confirm('Delete this comment?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id"         value="<?= $c['id'] ?>">
                        <input type="hidden" name="student_id" value="<?= $id ?>">
                        <button class="btn btn-sm btn-link text-danger p-0" style="font-size:.8rem;" title="Delete comment">
                            <i class="fas fa-times"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
                <div class="sv-comment-bubble"><?= nl2br(h($c['comment'])) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        <hr style="border-color:#f0f3f8;margin:16px 0;">
        <?php endif; ?>

        <form method="POST" action="<?= APP_URL ?>/students/view.php?id=<?= $id ?>#sv-comments">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_comment">
            <div class="mb-2">
                <textarea class="form-control" name="comment" rows="3"
                          placeholder="Write a comment…" required
                          style="border-radius:10px;font-size:.875rem;resize:vertical;"></textarea>
            </div>
            <button type="submit" class="btn btn-primary btn-sm px-4" style="border-radius:9px;">
                <i class="fas fa-paper-plane me-1"></i> Post Comment
            </button>
        </form>
    </div>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
