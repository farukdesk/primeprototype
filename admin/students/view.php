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
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/students/index.php">Students</a></li>
            <li class="breadcrumb-item active"><?= h($student['full_name']) ?></li>
        </ol>
    </nav>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (is_super_admin() || can_access('student-verification', 'can_create')): ?>
        <a href="<?= APP_URL ?>/student-verification/verify.php?student_id=<?= $id ?>"
           class="btn btn-outline-success btn-sm" style="border-radius:8px;">
            <i class="fas fa-shield-alt me-1"></i> Verify
        </a>
        <?php endif; ?>
        <?php if ($is_staff): ?>
        <a href="<?= APP_URL ?>/students/edit.php?id=<?= $id ?>"
           class="btn btn-outline-primary btn-sm" style="border-radius:8px;">
            <i class="fas fa-edit me-1"></i> Edit
        </a>
        <?php endif; ?>
        <?php if (sm_can_delete()): ?>
        <form method="POST" action="<?= APP_URL ?>/students/delete.php"
              onsubmit="return confirm('Delete this student permanently?');">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn btn-outline-danger btn-sm" style="border-radius:8px;">
                <i class="fas fa-trash me-1"></i> Delete
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     STUDENT PROFILE HEADER
═══════════════════════════════════════════════════════════ -->
<div class="card mb-4">
    <div class="card-body px-4 py-4">
        <div class="row align-items-center g-3">
            <div class="col-auto">
                <?php if ($student['photo']): ?>
                <img src="<?= sm_photo_url($student['photo']) ?>"
                     alt="Photo"
                     style="width:100px;height:120px;object-fit:cover;border-radius:10px;border:2px solid #dee2e6;">
                <?php else: ?>
                <div style="width:100px;height:120px;background:#e8eaf0;border-radius:10px;
                            display:flex;align-items:center;justify-content:center;font-size:2.5rem;color:#aaa;">
                    <i class="fas fa-user-graduate"></i>
                </div>
                <?php endif; ?>
            </div>
            <div class="col">
                <h4 class="fw-bold mb-1"><?= h($student['full_name']) ?></h4>
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <code class="bg-light px-2 py-1 rounded" style="font-size:.9rem;"><?= h($student['student_id']) ?></code>
                    <?= sm_status_badge($student['status']) ?>
                    <?php if ($student['sex']): ?>
                        <?= sm_sex_badge($student['sex']) ?>
                    <?php endif; ?>
                </div>
                <div class="text-muted" style="font-size:.875rem;">
                    <?php if (!empty($student['faculty_label'])): ?>
                        <strong style="color:#555;"><?= h($student['faculty_label']) ?></strong>
                        &nbsp;·&nbsp;
                    <?php endif; ?>
                    <strong><?= h($student['dept_name']) ?></strong>
                    <?php if ($student['program_name']): ?>
                        &nbsp;·&nbsp; <?= h($student['program_name']) ?>
                        <?php if (!empty($student['program_type'])): ?>
                            <span class="badge bg-info bg-opacity-15 text-info" style="font-size:.7rem;"><?= h($student['program_type']) ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                    &nbsp;·&nbsp; Admitted: <?= h($student['admitted_semester']) ?>
                    <?php
                    // Batch: prefer batch_name from JOIN, fall back to text
                    $batchLabel = $student['batch_name'] ?? $student['batch'] ?? null;
                    ?>
                    <?php if ($batchLabel): ?>
                        &nbsp;·&nbsp; Batch: <?= h($batchLabel) ?>
                    <?php endif; ?>
                    <?php if (!empty($student['year'])): ?>
                        &nbsp;·&nbsp; Year: <?= h($student['year']) ?>
                    <?php endif; ?>
                    <?php if (!empty($student['shift'])): ?>
                        &nbsp;·&nbsp; <?= h($student['shift']) ?> Shift
                    <?php endif; ?>
                </div>
                <?php if ($student['email'] || $student['phone']): ?>
                <div class="mt-1 text-muted" style="font-size:.875rem;">
                    <?php if ($student['email']): ?>
                    <i class="fas fa-envelope me-1"></i><?= h($student['email']) ?>
                    <?php endif; ?>
                    <?php if ($student['phone']): ?>
                    &nbsp;<i class="fas fa-phone me-1 ms-2"></i><?= h($student['phone']) ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     PERSONAL DETAILS
═══════════════════════════════════════════════════════════ -->
<div class="row g-4 mb-4">
    <!-- Personal -->
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-user me-2 text-muted"></i>Personal Details</h6>
            </div>
            <div class="card-body px-4">
                <?php
                $details = [
                    'Date of Birth'    => $student['dob'],
                    'Blood Group'      => $student['blood_group'],
                    'NID'              => $student['nid'],
                    'Place of Birth'   => $student['place_of_birth'],
                    'Nationality'      => $student['nationality'],
                    'Country'          => !empty($student['country']) && $student['country'] !== 'Bangladesh' ? $student['country'] : null,
                    'District'         => $student['district_name'] ?? null,
                    'Thana / Upazila'  => $student['thana_name'] ?? null,
                    'Religion'         => $student['religion'],
                    'Present Address'  => $student['present_address'],
                    'Permanent Address'=> $student['permanent_address'],
                ];
                foreach ($details as $label => $val):
                    if (!$val) continue;
                ?>
                <div class="d-flex mb-2 gap-2">
                    <div style="min-width:140px;font-size:.8rem;color:#6b7280;font-weight:600;"><?= $label ?></div>
                    <div style="font-size:.875rem;"><?= nl2br(h($val)) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Parents -->
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-users me-2 text-muted"></i>Parents' Information</h6>
            </div>
            <div class="card-body px-4">
                <h6 class="fw-semibold mb-2" style="font-size:.85rem;color:#555;">Father</h6>
                <?php
                $fdetails = [
                    'Name'         => $student['father_name'],
                    'Phone'        => $student['father_phone'],
                    'Occupation'   => $student['father_occupation'],
                    'Yearly Income'=> $student['father_yearly_income'] ? 'BDT ' . number_format($student['father_yearly_income'], 2) : null,
                ];
                foreach ($fdetails as $label => $val):
                    if (!$val) continue;
                ?>
                <div class="d-flex mb-1 gap-2">
                    <div style="min-width:120px;font-size:.78rem;color:#6b7280;font-weight:600;"><?= $label ?></div>
                    <div style="font-size:.875rem;"><?= h($val) ?></div>
                </div>
                <?php endforeach; ?>
                <hr class="my-2">
                <h6 class="fw-semibold mb-2" style="font-size:.85rem;color:#555;">Mother</h6>
                <?php
                $mdetails = [
                    'Name'         => $student['mother_name'],
                    'Phone'        => $student['mother_phone'],
                    'Occupation'   => $student['mother_occupation'],
                    'Yearly Income'=> $student['mother_yearly_income'] ? 'BDT ' . number_format($student['mother_yearly_income'], 2) : null,
                ];
                foreach ($mdetails as $label => $val):
                    if (!$val) continue;
                ?>
                <div class="d-flex mb-1 gap-2">
                    <div style="min-width:120px;font-size:.78rem;color:#6b7280;font-weight:600;"><?= $label ?></div>
                    <div style="font-size:.875rem;"><?= h($val) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     QUOTA & FEE INFORMATION
═══════════════════════════════════════════════════════════ -->
<?php
$has_fee = $student['form_fee'] !== null || $student['regi_fee'] !== null || $student['tuition_fee'] !== null ||
           $student['total_fee'] !== null || $student['total_payable'] !== null || $student['waiver_percent'] !== null ||
           $student['waiver_amount'] !== null || !empty($student['poor_meritorious']) || !empty($student['freedom_fighter_quota']) ||
           $student['ref_number'] !== null;
if ($has_fee):
?>
<div class="card mb-4">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-money-bill-wave me-2 text-muted"></i>Quota &amp; Fee Information</h6>
    </div>
    <div class="card-body px-4">
        <div class="row g-3">
            <?php if ($student['poor_meritorious'] || $student['freedom_fighter_quota']): ?>
            <div class="col-12">
                <?php if ($student['poor_meritorious']): ?>
                <span class="badge bg-info me-1">Poor / Meritorious</span>
                <?php endif; ?>
                <?php if ($student['freedom_fighter_quota']): ?>
                <span class="badge bg-success">Freedom Fighter Family</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
        $feefields = [
            'Waiver %'            => $student['waiver_percent'],
            'Waiver Amount'       => $student['waiver_amount'] ? 'BDT ' . number_format($student['waiver_amount']) : null,
            'Form Fee'            => $student['form_fee'] ? 'BDT ' . number_format($student['form_fee']) : null,
            'Regi. Fee'           => $student['regi_fee'] ? 'BDT ' . number_format($student['regi_fee']) : null,
            'Tuition Fee'         => $student['tuition_fee'] ? 'BDT ' . number_format($student['tuition_fee']) : null,
            'Misc Fee'            => $student['misc_fee'],
            'Project Fee'         => $student['project_fee'] ? 'BDT ' . number_format($student['project_fee']) : null,
            'Total Fee'           => $student['total_fee'] ? 'BDT ' . number_format($student['total_fee']) : null,
            'Total Payable'       => $student['total_payable'],
            'Monthly Installment' => $student['monthly_installment'],
            'Ref / Receipt No.'   => $student['ref_number'],
        ];
        foreach ($feefields as $lbl => $val):
            if (!$val) continue;
        ?>
        <div class="d-flex mb-1 gap-2 mt-1">
            <div style="min-width:160px;font-size:.78rem;color:#6b7280;font-weight:600;"><?= $lbl ?></div>
            <div style="font-size:.875rem;"><?= h($val) ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════
     ACADEMIC QUALIFICATIONS
═══════════════════════════════════════════════════════════ -->
<div class="card mb-4">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-graduation-cap me-2 text-muted"></i>Academic Qualifications</h6>
    </div>
    <div class="card-body p-0">
        <?php if (empty($qualifications)): ?>
        <p class="text-muted px-4 py-3 mb-0">No academic qualifications recorded.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">Exam</th>
                        <th>Session</th>
                        <th>Group</th>
                        <th>Board / University</th>
                        <th>Year</th>
                        <th>Division / Grade</th>
                        <th>Marks / GPA</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($qualifications as $q):
                    // Prefer referenced name, fall back to free-text
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
                    <td class="px-4 fw-medium"><?= h($examLabel  ?: '—') ?></td>
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
</div>

<!-- ══════════════════════════════════════════════════════════
     STUDENT FILES
═══════════════════════════════════════════════════════════ -->
<div class="card mb-4" id="files">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-folder-open me-2 text-muted"></i>Student Files</h6>
        <?php if (sm_can_create()): ?>
        <button class="btn btn-sm btn-outline-primary" style="border-radius:8px;"
                data-bs-toggle="collapse" data-bs-target="#uploadFileForm">
            <i class="fas fa-upload me-1"></i> Upload File
        </button>
        <?php endif; ?>
    </div>

    <?php if (sm_can_create()): ?>
    <div class="collapse" id="uploadFileForm">
        <div class="border-bottom px-4 py-3" style="background:#fafafa;">
            <form method="POST" action="" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="upload_file">
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-md-3">
                        <label class="form-label fw-semibold" style="font-size:.85rem;">File Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" name="file_name"
                               placeholder="e.g. National ID Card" maxlength="200" required>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label fw-semibold" style="font-size:.85rem;">Description</label>
                        <input type="text" class="form-control form-control-sm" name="description"
                               placeholder="Optional description" maxlength="500">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label fw-semibold" style="font-size:.85rem;">File <span class="text-danger">*</span></label>
                        <input type="file" class="form-control form-control-sm" name="file" required>
                    </div>
                    <div class="col-12 col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100" style="border-radius:7px;">
                            <i class="fas fa-upload me-1"></i> Upload
                        </button>
                    </div>
                </div>
                <small class="text-muted">Max 20 MB – Images, PDF, Word, Excel, PPT, ZIP, TXT</small>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="card-body p-0">
        <?php if (empty($files)): ?>
        <p class="text-muted px-4 py-3 mb-0">No files attached to this student.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">File</th>
                        <th>Description</th>
                        <th>Type / Size</th>
                        <th>Uploaded By</th>
                        <th>Date</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($files as $f): ?>
                <?php $ext = strtolower(pathinfo($f['original_name'], PATHINFO_EXTENSION)); ?>
                <tr>
                    <td class="px-4">
                        <i class="<?= sm_file_icon($ext) ?> me-2"></i>
                        <strong><?= h($f['file_name']) ?></strong>
                        <div><small class="text-muted"><?= h($f['original_name']) ?></small></div>
                    </td>
                    <td><small><?= h($f['description'] ?? '—') ?></small></td>
                    <td>
                        <div style="font-size:.8rem;"><code><?= strtoupper($ext) ?></code></div>
                        <small class="text-muted"><?= $f['file_size'] ? sm_format_size((int)$f['file_size']) : '—' ?></small>
                    </td>
                    <td style="font-size:.875rem;"><?= h($f['uploader_name'] ?? '—') ?></td>
                    <td style="font-size:.8rem;color:#6b7280;"><?= date('M d, Y', strtotime($f['created_at'])) ?></td>
                    <td class="text-end pe-4">
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
</div>

<!-- ══════════════════════════════════════════════════════════
     COMMENTS
═══════════════════════════════════════════════════════════ -->
<div class="card mb-5" id="comments">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-comments me-2 text-muted"></i>
            Comments
            <span class="badge bg-secondary ms-1"><?= count($comments) ?></span>
        </h6>
    </div>
    <div class="card-body px-4 py-3">

        <!-- Comment list -->
        <?php if (empty($comments)): ?>
        <p class="text-muted mb-3">No comments yet.</p>
        <?php else: ?>
        <?php foreach ($comments as $c): ?>
        <div class="d-flex gap-3 mb-3">
            <div style="width:36px;height:36px;background:#4f8ef7;border-radius:50%;
                        display:flex;align-items:center;justify-content:center;
                        color:#fff;font-weight:700;font-size:.85rem;flex-shrink:0;">
                <?= strtoupper(substr($c['commenter_name'], 0, 1)) ?>
            </div>
            <div class="flex-fill">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <strong style="font-size:.875rem;"><?= h($c['commenter_name']) ?></strong>
                    <small class="text-muted"><?= date('M d, Y H:i', strtotime($c['created_at'])) ?></small>
                    <?php if (sm_can_delete() || (int)$c['user_id'] === (int)$user['id']): ?>
                    <form method="POST" action="<?= APP_URL ?>/students/comment-delete.php" class="ms-auto"
                          onsubmit="return confirm('Delete this comment?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id"         value="<?= $c['id'] ?>">
                        <input type="hidden" name="student_id" value="<?= $id ?>">
                        <button class="btn btn-sm btn-link text-danger p-0" style="font-size:.8rem;">
                            <i class="fas fa-times"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
                <div style="background:#f8f9fa;border-radius:8px;padding:10px 14px;font-size:.875rem;line-height:1.5;">
                    <?= nl2br(h($c['comment'])) ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <hr>
        <?php endif; ?>

        <!-- Add comment form -->
        <form method="POST" action="<?= APP_URL ?>/students/view.php?id=<?= $id ?>#comments">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_comment">
            <div class="mb-2">
                <textarea class="form-control" name="comment" rows="3"
                          placeholder="Write a comment…" required></textarea>
            </div>
            <button type="submit" class="btn btn-primary btn-sm" style="border-radius:8px;">
                <i class="fas fa-paper-plane me-1"></i> Post Comment
            </button>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     RESULTS (migrated from s_result_entry)
═══════════════════════════════════════════════════════════ -->
<?php if (!empty($results)): ?>
<div class="card mb-5" id="results">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-chart-bar me-2 text-muted"></i>Academic Results
            <span class="badge bg-secondary ms-1"><?= count($results) ?></span>
        </h6>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0" style="font-size:.85rem;">
            <thead class="table-light">
                <tr>
                    <th>Semester</th>
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
                    <td><?= h($r['semester'] ?? '') ?></td>
                    <td><?= h($r['semester_year'] ?? '') ?></td>
                    <td><?= h($r['batch'] ?? '') ?></td>
                    <td><?= h($r['subject'] ?? '') ?></td>
                    <td><code><?= h($r['subject_code'] ?? '') ?></code></td>
                    <td><?= h($r['credits'] ?? '') ?></td>
                    <td><?= h($r['grade'] ?? '') ?></td>
                    <td><?= h($r['gpa'] ?? '') ?></td>
                    <td><?= h($r['cgpa'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
