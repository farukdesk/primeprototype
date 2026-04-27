<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('student-verification');
require_once __DIR__ . '/../students/helpers.php';
require_once __DIR__ . '/../change-log/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../../vendor/autoload.php';

$id   = (int)($_GET['id'] ?? 0);
$user = auth_user();

// ── Fetch verification record ─────────────────────────────────────────────────
$stmt = db()->prepare(
    'SELECT sv.*,
            s.id AS s_id, s.student_id AS s_student_id, s.full_name AS s_full_name,
            s.email AS s_email, s.phone AS s_phone,
            s.status AS s_status,
            d.name AS dept_name, d.code AS dept_code,
            p.program_name,
            s.admitted_semester,
            s.batch,
            s.photo,
            u.full_name AS verifier_name, u.email AS verifier_user_email
     FROM student_verifications sv
     JOIN students s ON s.id = sv.student_id
     JOIN dept_departments d ON d.id = s.dept_id
     LEFT JOIN dept_academic_programs p ON p.id = s.program_id
     JOIN users u ON u.id = sv.verified_by
     WHERE sv.id = ?'
);
$stmt->execute([$id]);
$rec = $stmt->fetch();

if (!$rec) {
    flash_set('error', 'Verification record not found.');
    redirect(APP_URL . '/student-verification/index.php');
}

$page_title = 'Verification – ' . $rec['s_full_name'];

// ── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // Upload signed verified PDF
    if ($action === 'upload_verified_pdf') {
        if (empty($_FILES['verified_pdf']['name'])) {
            flash_set('error', 'Please select a PDF file to upload.');
        } else {
            $file = $_FILES['verified_pdf'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                flash_set('error', 'File upload error.');
            } elseif ($file['size'] > 20 * 1024 * 1024) {
                flash_set('error', 'File too large (max 20 MB).');
            } else {
                $ext   = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($file['tmp_name']);
                if ($ext !== 'pdf' || $mime !== 'application/pdf') {
                    flash_set('error', 'Only PDF files are accepted for the verified copy.');
                } else {
                    $dir = UPLOAD_DIR . '/student-verification';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $stored = bin2hex(random_bytes(12)) . '.pdf';
                    if (move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) {
                        // Delete old file if any
                        if ($rec['verified_pdf']) {
                            @unlink(UPLOAD_DIR . '/student-verification/' . $rec['verified_pdf']);
                        }
                        db()->prepare('UPDATE student_verifications SET verified_pdf = ? WHERE id = ?')
                            ->execute([$stored, $id]);
                        log_change('student-verification', 'UPDATE', $id,
                            $rec['s_full_name'] . ' (' . $rec['s_student_id'] . ')',
                            'verified_pdf', null, $stored,
                            'Signed verified PDF uploaded by ' . $user['full_name']);
                        flash_set('success', 'Verified PDF uploaded successfully.');
                    } else {
                        flash_set('error', 'Failed to save the uploaded file.');
                    }
                }
            }
        }
        redirect(APP_URL . '/student-verification/view.php?id=' . $id);
    }

    // Send email
    if ($action === 'send_email') {
        $to_email = trim($_POST['send_to_email'] ?? $rec['verifier_email'] ?? '');
        $to_name  = trim($_POST['send_to_name'] ?? '');

        if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
            flash_set('error', 'Please enter a valid email address.');
            redirect(APP_URL . '/student-verification/view.php?id=' . $id);
        }

        $status_text  = $rec['overall_status'] === 'Verified' ? 'VERIFIED ✔' : 'VERIFICATION FAILED ✘';
        $reasons_html = '';

        if ($rec['overall_status'] === 'Failed') {
            $reasons_html .= '<ul>';
            if (!$rec['cert_transcript_ok']) {
                $reasons_html .= '<li><strong>Certificate &amp; Transcript:</strong> ' . htmlspecialchars($rec['cert_transcript_issues'] ?? '') . '</li>';
            }
            if (!$rec['admission_form_ok']) {
                $reasons_html .= '<li><strong>Admission Form:</strong> ' . htmlspecialchars($rec['admission_form_issues'] ?? '') . '</li>';
            }
            if (!$rec['tabulation_ok']) {
                $reasons_html .= '<li><strong>Final Result Tabulation:</strong> ' . htmlspecialchars($rec['tabulation_issues'] ?? '') . '</li>';
            }
            $reasons_html .= '</ul>';
        }

        $visit_note = $rec['overall_status'] === 'Failed'
            ? '<p style="margin-top:16px;color:#555;">If you have questions regarding this verification result, please visit Prime University Bangladesh at 114/116 Mazar Road, Mirpur-1, Dhaka 1216, Bangladesh.</p>'
            : '';

        $subject  = 'Student Verification Result – ' . $rec['s_full_name'] . ' (' . $rec['s_student_id'] . ')';
        $ref_no_e = 'PU-VER-' . str_pad($id, 6, '0', STR_PAD_LEFT);
        $date_str_e = date('d F Y, H:i', strtotime($rec['created_at']));

        $body = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#333;max-width:640px;margin:auto;">'
              . '<div style="background:linear-gradient(135deg,#1a2e5a,#1e3a8a);color:#fff;padding:20px 28px;border-radius:10px 10px 0 0;">'
              . '<h2 style="margin:0;font-size:1.25rem;font-weight:800;">Prime University Bangladesh</h2>'
              . '<p style="margin:4px 0 0;font-size:.8rem;opacity:.75;">Student Verification Certificate &nbsp;|&nbsp; ' . htmlspecialchars($ref_no_e) . '</p>'
              . '</div>'
              . '<div style="padding:26px 28px;border:1px solid #e0e0e0;border-top:none;border-radius:0 0 10px 10px;">'
              . '<p style="margin:0 0 16px;">Dear ' . htmlspecialchars($to_name ?: 'Recipient') . ',</p>'
              . '<p style="margin:0 0 16px;">Please find below the official verification result for the following student. The Digital Signed Certificate is attached to this email.</p>'
              . '<table style="width:100%;border-collapse:collapse;margin:0 0 16px;">'
              . '<tr><td style="padding:7px 10px;background:#f5f7fa;font-weight:600;width:40%;border-bottom:1px solid #eee;">Student Name</td><td style="padding:7px 10px;border-bottom:1px solid #eee;">' . h($rec['s_full_name']) . '</td></tr>'
              . '<tr><td style="padding:7px 10px;background:#f5f7fa;font-weight:600;border-bottom:1px solid #eee;">Student ID</td><td style="padding:7px 10px;border-bottom:1px solid #eee;">' . h($rec['s_student_id']) . '</td></tr>'
              . '<tr><td style="padding:7px 10px;background:#f5f7fa;font-weight:600;border-bottom:1px solid #eee;">Department</td><td style="padding:7px 10px;border-bottom:1px solid #eee;">' . h($rec['dept_name']) . '</td></tr>'
              . '<tr><td style="padding:7px 10px;background:#f5f7fa;font-weight:600;">Verification Date</td><td style="padding:7px 10px;">' . $date_str_e . '</td></tr>'
              . '</table>'
              . '<p style="font-size:1.05rem;font-weight:700;padding:12px 16px;border-radius:8px;margin:0 0 16px;'
              . ($rec['overall_status'] === 'Verified' ? 'background:#d4edda;color:#155724;' : 'background:#f8d7da;color:#721c24;')
              . '">' . $status_text . '</p>'
              . $reasons_html
              . $visit_note
              . '<hr style="border:none;border-top:1px solid #eee;margin:20px 0;">'
              . '<p style="font-size:.8rem;color:#888;">This email was sent from <a href="mailto:verification@primeuniversity.ac.bd">verification@primeuniversity.ac.bd</a> by the Prime University Verification System.</p>'
              . '</div></body></html>';

        // Build digital certificate HTML for attachment
        $cert_status     = $rec['overall_status'] === 'Verified';
        $cert_date       = date('d F Y', strtotime($rec['created_at']));
        $cert_time       = date('H:i',   strtotime($rec['created_at']));
        $cert_s_status   = $rec['s_status'] ?? '';

        $has_sdo_col = (bool)db()->query("SHOW COLUMNS FROM student_verifications LIKE 'student_data_ok'")->fetchColumn();
        $online_ok   = $has_sdo_col ? (bool)($rec['student_data_ok'] ?? 1) : true;

        $chk_fn = function(bool $ok, string $label, ?string $issue) {
            $bg    = $ok ? '#d1fae5' : '#fee2e2';
            $bc    = $ok ? '#6ee7b7' : '#fca5a5';
            $ic    = $ok ? '#059669' : '#dc2626';
            $lc    = $ok ? '#065f46' : '#991b1b';
            $icon  = $ok ? '✔' : '✘';
            $issue_html = (!$ok && $issue) ? '<div style="font-size:7.5pt;color:#7f1d1d;margin-top:2px;">' . htmlspecialchars($issue) . '</div>' : '';
            return '<div style="display:flex;align-items:flex-start;gap:10px;padding:9px 14px;border-radius:8px;background:' . $bc . ';border:1.5px solid ' . $bc . ';margin-bottom:8px;">'
                 . '<span style="color:' . $ic . ';font-size:1rem;flex-shrink:0;margin-top:1px;">' . $icon . '</span>'
                 . '<div><div style="font-size:8.5pt;font-weight:700;color:' . $lc . ';">' . htmlspecialchars($label) . '</div>' . $issue_html . '</div>'
                 . '</div>';
        };

        $cert_checks = '';
        if ($has_sdo_col) {
            $cert_checks .= $chk_fn($online_ok, 'Online Record', $rec['student_data_issues'] ?? null);
        }
        $cert_checks .= $chk_fn((bool)$rec['cert_transcript_ok'], 'Certificate & Transcript – Visual Security Measures', $rec['cert_transcript_issues'] ?? null);
        $cert_checks .= $chk_fn((bool)$rec['admission_form_ok'],  'Admission Form (Hard Copy)',                          $rec['admission_form_issues'] ?? null);
        $cert_checks .= $chk_fn((bool)$rec['tabulation_ok'],      'Final Result Tabulation (Hard Copy)',                 $rec['tabulation_issues'] ?? null);

        $s_status_style = $cert_s_status === 'Graduated'
            ? 'background:#d1fae5;color:#065f46;'
            : ($cert_s_status === 'Active' ? 'background:#dbeafe;color:#1d4ed8;' : 'background:#f3f4f6;color:#6b7280;');

        $cert_attachment = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
            . '<title>Verification Certificate – ' . htmlspecialchars($rec['s_full_name']) . '</title></head>'
            . '<body style="font-family:Segoe UI,Arial,Helvetica,sans-serif;background:#eef2f7;margin:0;padding:20px 16px 40px;">'
            . '<div style="max-width:760px;margin:0 auto;">'
            . '<div style="background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,.12);border:1.5px solid #e2e8f0;">'
            . '<div style="height:7px;background:linear-gradient(90deg,#1a2e5a 0%,#2563eb 50%,#10b981 100%);"></div>'
            . '<div style="padding:20px 32px 16px;border-bottom:2px solid #e8edf5;position:relative;">'
            . '<div style="font-size:14pt;font-weight:800;color:#1a2e5a;margin-bottom:3px;">Prime University Bangladesh</div>'
            . '<div style="font-size:7.5pt;color:#4b5563;line-height:1.8;">114/116 Mazar Road, Mirpur-1, Dhaka 1216, Bangladesh &nbsp;|&nbsp; verification@primeuniversity.ac.bd</div>'
            . '<div style="position:absolute;right:32px;top:50%;transform:translateY(-50%);font-size:50pt;font-weight:900;color:rgba(26,46,90,.03);pointer-events:none;">PU</div>'
            . '</div>'
            . '<div style="background:linear-gradient(135deg,#1a2e5a,#1e3a8a);padding:12px 32px;display:flex;align-items:center;justify-content:space-between;">'
            . '<span style="font-size:10.5pt;font-weight:800;color:#fff;letter-spacing:.04em;text-transform:uppercase;">&#128737; Student Verification Certificate</span>'
            . '<span style="font-size:7.5pt;color:rgba(255,255,255,.65);font-family:monospace;">' . htmlspecialchars($ref_no_e) . '</span>'
            . '</div>'
            . '<div style="padding:24px 32px 28px;">'
            . '<div style="display:flex;gap:16px;align-items:flex-start;background:#f8faff;border:1.5px solid #dbe4f3;border-radius:12px;padding:16px 20px;margin-bottom:20px;flex-wrap:wrap;">'
            . '<div style="flex:1;min-width:200px;">'
            . '<div style="font-size:13pt;font-weight:800;color:#1a2e5a;margin-bottom:6px;">' . h($rec['s_full_name']) . '</div>'
            . '<div style="display:inline-flex;align-items:center;background:#1a2e5a;color:#fff;border-radius:5px;padding:3px 10px;font-size:8pt;font-weight:700;letter-spacing:.05em;margin-bottom:4px;">&#128196; ' . h($rec['s_student_id']) . '</div>'
            . '<span style="display:inline-flex;align-items:center;padding:3px 11px;border-radius:50px;font-size:8pt;font-weight:700;margin-left:6px;' . $s_status_style . '">' . h($cert_s_status ?: 'Active') . '</span>'
            . '<table style="border-collapse:collapse;width:100%;max-width:420px;margin-top:10px;">'
            . '<tr><td style="color:#9ca3af;font-size:7.5pt;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:3px 0;width:44%;">Department</td><td style="font-size:9.5pt;font-weight:700;color:#1a2e5a;">' . h($rec['dept_name']) . '</td></tr>'
            . ($rec['program_name'] ? '<tr><td style="color:#9ca3af;font-size:7.5pt;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:3px 0;">Obtained Degree</td><td style="font-size:9.5pt;font-weight:700;color:#1a2e5a;">' . h($rec['program_name']) . '</td></tr>' : '')
            . ($rec['admitted_semester'] ? '<tr><td style="color:#9ca3af;font-size:7.5pt;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:3px 0;">Enrolled Semester</td><td style="font-size:9.5pt;color:#1a2e5a;">' . h($rec['admitted_semester']) . '</td></tr>' : '')
            . '</table>'
            . '</div>'
            . '</div>'
            . '<div style="padding:13px 16px;border-radius:10px;background:' . ($cert_status ? '#d1fae5' : '#fee2e2') . ';border:2px solid ' . ($cert_status ? '#6ee7b7' : '#fca5a5') . ';margin-bottom:20px;display:flex;align-items:center;gap:12px;">'
            . '<span style="font-size:1.4rem;color:' . ($cert_status ? '#059669' : '#dc2626') . ';">' . ($cert_status ? '✔' : '✘') . '</span>'
            . '<div><div style="font-size:10pt;font-weight:800;color:' . ($cert_status ? '#065f46' : '#991b1b') . ';">' . ($cert_status ? 'VERIFIED – GENUINE &amp; AUTHENTIC' : 'VERIFICATION FAILED') . '</div>'
            . '<div style="font-size:8pt;color:' . ($cert_status ? '#065f46' : '#991b1b') . ';opacity:.85;margin-top:3px;">' . ($cert_status ? 'Credentials verified and found genuine.' : 'One or more checks did not pass.') . '</div>'
            . '</div></div>'
            . '<div style="font-size:8pt;font-weight:800;color:#2563eb;text-transform:uppercase;letter-spacing:.07em;border-bottom:1.5px solid #dbe4f3;padding-bottom:7px;margin-bottom:12px;">✓ Verification Checklist</div>'
            . $cert_checks
            . '<div style="display:flex;align-items:center;gap:14px;background:linear-gradient(135deg,#f0fdf4,#ecfdf5);border:1.5px solid #6ee7b7;border-radius:12px;padding:14px 18px;margin-top:20px;">'
            . '<span style="font-size:2rem;color:#059669;flex-shrink:0;">&#128737;</span>'
            . '<div><div style="font-size:9pt;font-weight:800;color:#065f46;margin-bottom:3px;">Digitally Authenticated by Prime University Bangladesh</div>'
            . '<div style="font-size:7.5pt;color:#059669;line-height:1.4;">'
            . 'Verified by: <strong>' . h($rec['verifier_name']) . '</strong> &nbsp;|&nbsp; Date: ' . $cert_date . ' at ' . $cert_time . ' &nbsp;|&nbsp; Ref: ' . htmlspecialchars($ref_no_e)
            . '</div></div></div>'
            . '</div>'
            . '<div style="background:#f8fafc;border-top:1.5px solid #e8edf5;padding:10px 32px;text-align:center;font-size:7pt;color:#9ca3af;">'
            . 'This certificate was generated by the Prime University Bangladesh Verification System. Reference: ' . htmlspecialchars($ref_no_e) . ' | Digital Signed Version'
            . '</div>'
            . '</div></div></body></html>';

        $from_email   = 'verification@primeuniversity.ac.bd';
        $from_name    = 'Prime University Verification';
        $encoded_from = '=?UTF-8?B?' . base64_encode($from_name) . '?=';
        $boundary     = '----=_Part_' . md5(uniqid('', true));
        $attach_name  = 'verification-certificate-' . preg_replace('/[^A-Za-z0-9\-]/', '', $rec['s_student_id']) . '.pdf';

        // Generate PDF from digital certificate HTML using Dompdf
        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
        $dompdf->loadHtml($cert_attachment, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $pdf_data = $dompdf->output();

        $headers  = 'MIME-Version: 1.0' . "\r\n";
        $headers .= 'Content-Type: multipart/mixed; boundary="' . $boundary . '"' . "\r\n";
        $headers .= 'From: ' . $encoded_from . ' <' . $from_email . '>' . "\r\n";
        $headers .= 'Reply-To: ' . $from_email . "\r\n";
        $headers .= 'X-Mailer: PHP/' . PHP_VERSION;

        $message  = '--' . $boundary . "\r\n";
        $message .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
        $message .= 'Content-Transfer-Encoding: quoted-printable' . "\r\n\r\n";
        $message .= quoted_printable_encode($body) . "\r\n";

        $message .= '--' . $boundary . "\r\n";
        $message .= 'Content-Type: application/pdf; name="' . $attach_name . '"' . "\r\n";
        $message .= 'Content-Transfer-Encoding: base64' . "\r\n";
        $message .= 'Content-Disposition: attachment; filename="' . $attach_name . '"' . "\r\n\r\n";
        $message .= chunk_split(base64_encode($pdf_data)) . "\r\n";
        $message .= '--' . $boundary . '--';

        $sent = mail($to_email, $subject, $message, $headers, '-f' . escapeshellarg($from_email));

        if ($sent) {
            db()->prepare('UPDATE student_verifications SET email_sent=1, email_sent_at=NOW(), verifier_email=? WHERE id=?')
               ->execute([$to_email, $id]);
            log_change('student-verification', 'UPDATE', $id,
                $rec['s_full_name'] . ' (' . $rec['s_student_id'] . ')',
                'email_sent', 0, 1,
                'Verification email sent to ' . $to_email . ' by ' . $user['full_name']);
            flash_set('success', 'Verification email sent to ' . $to_email . ' (with Digital Certificate PDF attached).');
        } else {
            flash_set('error', 'Failed to send the email. Please check the mail server configuration.');
        }
        redirect(APP_URL . '/student-verification/view.php?id=' . $id);
    }
}

// Re-fetch after possible update
$stmt->execute([$id]);
$rec = $stmt->fetch();

// Compute Final CGPA for display
$view_cgpa = null;
try {
    $cq = db()->prepare(
        'SELECT ROUND(SUM(rg.grade_point * COALESCE(rs.credits,3)) /
             NULLIF(SUM(COALESCE(rs.credits,3)),0), 2) AS cgpa
         FROM result_grades rg
         JOIN result_exams re ON re.id = rg.exam_id
         JOIN result_subjects rs ON rs.id = rg.subject_id
         WHERE rg.student_sid=? AND re.is_published=1
           AND rg.grade_point IS NOT NULL AND COALESCE(rs.credits,3)>0'
    );
    $cq->execute([$rec['s_student_id']]);
    $cv = $cq->fetchColumn();
    if ($cv !== null && $cv !== false) $view_cgpa = number_format((float)$cv, 2);
} catch (Throwable $e) {}
if ($view_cgpa === null) {
    try {
        $sr = db()->prepare(
            'SELECT MAX(CAST(cgpa AS DECIMAL(5,2))) FROM student_results
             WHERE student_id=? AND cgpa IS NOT NULL AND TRIM(cgpa)!=""'
        );
        $sr->execute([$rec['s_id']]);
        $sv2 = $sr->fetchColumn();
        if ($sv2 !== null && (float)$sv2 > 0) $view_cgpa = number_format((float)$sv2, 2);
    } catch (Throwable $e) {}
}

// Fetch Ending Semester for display
$view_ending_sem = null;
try {
    $eq = db()->prepare(
        'SELECT re.completion_semester
         FROM result_grades rg
         JOIN result_exams re ON re.id = rg.exam_id
         WHERE rg.student_sid = ? AND re.is_published = 1
           AND re.completion_semester IS NOT NULL
         ORDER BY re.updated_at DESC LIMIT 1'
    );
    $eq->execute([$rec['s_student_id']]);
    $erow = $eq->fetchColumn();
    if ($erow) $view_ending_sem = $erow;
} catch (Throwable $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/student-verification/index.php">Student Verification</a></li>
            <li class="breadcrumb-item active"><?= h($rec['s_full_name']) ?></li>
        </ol>
    </nav>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= APP_URL ?>/student-verification/verify.php?student_id=<?= $rec['s_id'] ?>"
           class="btn btn-outline-primary btn-sm" style="border-radius:8px;">
            <i class="fas fa-redo me-1"></i> Re-Verify
        </a>
        <a href="<?= APP_URL ?>/student-verification/index.php" class="btn btn-outline-secondary btn-sm" style="border-radius:8px;">
            <i class="fas fa-list me-1"></i> Log
        </a>
        <div class="btn-group">
            <a href="<?= APP_URL ?>/student-verification/certificate.php?id=<?= $id ?>&mode=digital"
               target="_blank" class="btn btn-outline-dark btn-sm" style="border-radius:8px 0 0 8px;">
                <i class="fas fa-print me-1"></i> Print Certificate
            </a>
            <button type="button" class="btn btn-outline-dark btn-sm dropdown-toggle dropdown-toggle-split"
                    data-bs-toggle="dropdown" aria-expanded="false" style="border-radius:0 8px 8px 0;">
                <span class="visually-hidden">Toggle Dropdown</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li>
                    <a href="<?= APP_URL ?>/student-verification/certificate.php?id=<?= $id ?>&mode=digital"
                       target="_blank" class="dropdown-item">
                        <i class="fas fa-shield-alt me-2 text-primary"></i> Digital Signed Version
                    </a>
                </li>
                <li>
                    <a href="<?= APP_URL ?>/student-verification/certificate.php?id=<?= $id ?>&mode=hand"
                       target="_blank" class="dropdown-item">
                        <i class="fas fa-pen-nib me-2 text-success"></i> Hand Signed Version
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a href="<?= APP_URL ?>/student-verification/certificate.php?id=<?= $id ?>"
                       target="_blank" class="dropdown-item text-muted" style="font-size:.82rem;">
                        <i class="fas fa-th-large me-2"></i> Choose Version…
                    </a>
                </li>
            </ul>
        </div>
    </div>
</div>

<?php flash_show(); ?>

<!-- ══════════════════════════════════════════════════════════
     STATUS BANNER
═══════════════════════════════════════════════════════════ -->
<?php if ($rec['overall_status'] === 'Verified'): ?>
<div class="alert mb-4 d-flex align-items-center gap-3" style="background:#d4edda;border:1.5px solid #c3e6cb;border-radius:12px;">
    <i class="fas fa-shield-alt text-success" style="font-size:2rem;"></i>
    <div>
        <div class="fw-bold text-success fs-5">Verification Passed</div>
        <div class="text-success" style="font-size:.9rem;">All three checks passed. The student's credentials are verified.</div>
    </div>
</div>
<?php else: ?>
<div class="alert mb-4 d-flex align-items-center gap-3" style="background:#f8d7da;border:1.5px solid #f5c6cb;border-radius:12px;">
    <i class="fas fa-times-circle text-danger" style="font-size:2rem;"></i>
    <div>
        <div class="fw-bold text-danger fs-5">Verification Failed</div>
        <div class="text-danger" style="font-size:.9rem;">One or more checks did not pass. See details below.</div>
        <div class="mt-2" style="font-size:.85rem;color:#6c3333;">
            <strong>Note:</strong> If you have questions about this result, please visit our university for further assistance.
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════
     STUDENT INFO
═══════════════════════════════════════════════════════════ -->
<div class="row g-4 mb-4">
    <div class="col-12 col-md-6">
        <div class="card h-100">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-user-graduate me-2 text-muted"></i>Student Information</h6>
            </div>
            <div class="card-body px-4 py-3">
                <table class="table table-sm mb-0" style="font-size:.875rem;">
                    <tr><th class="text-muted fw-normal" style="width:40%;">Full Name</th><td class="fw-semibold"><?= h($rec['s_full_name']) ?></td></tr>
                    <tr><th class="text-muted fw-normal">Student ID</th><td><code><?= h($rec['s_student_id']) ?></code></td></tr>
                    <tr><th class="text-muted fw-normal">Department</th><td><?= h($rec['dept_name']) ?></td></tr>
                    <?php if ($rec['program_name']): ?>
                    <tr><th class="text-muted fw-normal">Obtained Degree</th><td><?= h($rec['program_name']) ?></td></tr>
                    <?php endif; ?>
                    <tr><th class="text-muted fw-normal">Enrolled Semester</th><td><?= h($rec['admitted_semester']) ?></td></tr>
                    <tr><th class="text-muted fw-normal">Ending Semester</th><td><?= $view_ending_sem ? h($view_ending_sem) : '<span class="text-muted">—</span>' ?></td></tr>
                    <?php if (!empty($rec['batch'])): ?>
                    <tr><th class="text-muted fw-normal">Batch</th><td><?= h($rec['batch']) ?></td></tr>
                    <?php endif; ?>
                    <tr><th class="text-muted fw-normal">Graduated</th>
                        <td><?= (($rec['s_status'] ?? '') === 'Graduated') ? '<span class="text-success fw-semibold">Yes</span>' : '<span class="text-muted">No</span>' ?></td></tr>
                    <?php if ($view_cgpa): ?>
                    <tr><th class="text-muted fw-normal">Final CGPA</th><td><strong><?= h($view_cgpa) ?></strong></td></tr>
                    <?php endif; ?>
                    <?php if ($rec['s_email']): ?>
                    <tr><th class="text-muted fw-normal">Email</th><td><?= h($rec['s_email']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($rec['s_phone']): ?>
                    <tr><th class="text-muted fw-normal">Phone</th><td><?= h($rec['s_phone']) ?></td></tr>
                    <?php endif; ?>
                </table>
                <div class="mt-3">
                    <a href="<?= APP_URL ?>/students/view.php?id=<?= $rec['s_id'] ?>"
                       class="btn btn-sm btn-outline-secondary" style="border-radius:7px;font-size:.8rem;">
                        <i class="fas fa-external-link-alt me-1"></i> Full Student Profile
                    </a>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="card h-100">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-clipboard-check me-2 text-muted"></i>Verification Details</h6>
            </div>
            <div class="card-body px-4 py-3">
                <table class="table table-sm mb-0" style="font-size:.875rem;">
                    <tr><th class="text-muted fw-normal" style="width:45%;">Verified By</th><td><?= h($rec['verifier_name']) ?></td></tr>
                    <tr><th class="text-muted fw-normal">Date &amp; Time</th><td><?= date('d F Y, H:i', strtotime($rec['created_at'])) ?></td></tr>
                    <tr><th class="text-muted fw-normal">Overall Status</th>
                        <td>
                            <?php if ($rec['overall_status'] === 'Verified'): ?>
                                <span class="badge bg-success"><i class="fas fa-shield-alt me-1"></i>Verified</span>
                            <?php else: ?>
                                <span class="badge bg-danger"><i class="fas fa-times me-1"></i>Failed</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr><th class="text-muted fw-normal">Email Sent</th>
                        <td>
                            <?php if ($rec['email_sent']): ?>
                                <span class="text-success"><i class="fas fa-check me-1"></i>Yes</span>
                                <?php if ($rec['email_sent_at']): ?>
                                    <small class="text-muted ms-1">(<?= date('d M Y, H:i', strtotime($rec['email_sent_at'])) ?>)</small>
                                <?php endif; ?>
                                <?php if ($rec['verifier_email']): ?>
                                    <br><small class="text-muted">To: <?= h($rec['verifier_email']) ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">Not sent</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr><th class="text-muted fw-normal">Signed PDF</th>
                        <td>
                            <?php if ($rec['verified_pdf']): ?>
                                <a href="<?= UPLOAD_URL ?>/student-verification/<?= h($rec['verified_pdf']) ?>"
                                   target="_blank" class="text-success text-decoration-none" style="font-size:.82rem;">
                                    <i class="fas fa-file-pdf me-1"></i>Download
                                </a>
                            <?php else: ?>
                                <span class="text-muted" style="font-size:.85rem;">Not uploaded yet</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     CHECKS DETAIL
═══════════════════════════════════════════════════════════ -->
<div class="card mb-4">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-tasks me-2 text-muted"></i>Verification Checks</h6>
    </div>
    <div class="card-body px-4 py-3">
        <div class="row g-3">
            <!-- Step 1: Student record confirmation (shown only when column exists) -->
            <?php
            $has_sdo_col = (bool)db()->query("SHOW COLUMNS FROM student_verifications LIKE 'student_data_ok'")->fetchColumn();
            if ($has_sdo_col && array_key_exists('student_data_ok', $rec)):
                $sdo = (bool)($rec['student_data_ok'] ?? 1);
            ?>
            <div class="col-12">
                <div class="p-3 rounded-3 border <?= $sdo ? 'border-success bg-success bg-opacity-10' : 'border-danger bg-danger bg-opacity-10' ?>">
                    <div class="d-flex align-items-start gap-2">
                        <i class="fas <?= $sdo ? 'fa-check-circle text-success' : 'fa-times-circle text-danger' ?> mt-1" style="font-size:1.1rem;"></i>
                        <div>
                            <div class="fw-semibold">Step 1 – Student Record Details</div>
                            <?php if ($sdo): ?>
                                <div class="text-success" style="font-size:.85rem;">Student details match the presented documents.</div>
                            <?php else: ?>
                                <div class="text-danger mt-1" style="font-size:.85rem;"><strong>Data mismatch:</strong> <?= nl2br(h($rec['student_data_issues'])) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <!-- Check 1 -->
            <div class="col-12">
                <div class="p-3 rounded-3 border <?= $rec['cert_transcript_ok'] ? 'border-success bg-success bg-opacity-10' : 'border-danger bg-danger bg-opacity-10' ?>">
                    <div class="d-flex align-items-start gap-2">
                        <i class="fas <?= $rec['cert_transcript_ok'] ? 'fa-check-circle text-success' : 'fa-times-circle text-danger' ?> mt-1" style="font-size:1.1rem;"></i>
                        <div>
                            <div class="fw-semibold">Certificate &amp; Transcript Visual Security Measures</div>
                            <?php if ($rec['cert_transcript_ok']): ?>
                                <div class="text-success" style="font-size:.85rem;">All visual security features verified as correct.</div>
                            <?php else: ?>
                                <div class="text-danger mt-1" style="font-size:.85rem;"><strong>Issues found:</strong> <?= nl2br(h($rec['cert_transcript_issues'])) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Check 2 -->
            <div class="col-12">
                <div class="p-3 rounded-3 border <?= $rec['admission_form_ok'] ? 'border-success bg-success bg-opacity-10' : 'border-danger bg-danger bg-opacity-10' ?>">
                    <div class="d-flex align-items-start gap-2">
                        <i class="fas <?= $rec['admission_form_ok'] ? 'fa-check-circle text-success' : 'fa-times-circle text-danger' ?> mt-1" style="font-size:1.1rem;"></i>
                        <div class="flex-grow-1">
                            <div class="fw-semibold">Admission Form Check</div>
                            <?php if ($rec['admission_form_ok']): ?>
                                <div class="text-success" style="font-size:.85rem;">Scanned admission form matches the student records.</div>
                            <?php else: ?>
                                <div class="text-danger mt-1" style="font-size:.85rem;"><strong>Issues found:</strong> <?= nl2br(h($rec['admission_form_issues'])) ?></div>
                            <?php endif; ?>
                            <?php
                            // Show file link if recorded
                            if ($rec['admission_form_file_id']) {
                                $fstmt = db()->prepare('SELECT file_name, stored_name, original_name FROM student_files WHERE id=?');
                                $fstmt->execute([$rec['admission_form_file_id']]);
                                $ff = $fstmt->fetch();
                                if ($ff): ?>
                                <div class="mt-1" style="font-size:.8rem;">
                                    <a href="<?= UPLOAD_URL ?>/students/files/<?= h($ff['stored_name']) ?>" target="_blank" class="text-primary">
                                        <i class="fas fa-file-alt me-1"></i><?= h($ff['file_name']) ?>
                                    </a>
                                </div>
                                <?php endif;
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Check 3 -->
            <div class="col-12">
                <div class="p-3 rounded-3 border <?= $rec['tabulation_ok'] ? 'border-success bg-success bg-opacity-10' : 'border-danger bg-danger bg-opacity-10' ?>">
                    <div class="d-flex align-items-start gap-2">
                        <i class="fas <?= $rec['tabulation_ok'] ? 'fa-check-circle text-success' : 'fa-times-circle text-danger' ?> mt-1" style="font-size:1.1rem;"></i>
                        <div class="flex-grow-1">
                            <div class="fw-semibold">Final Result Tabulation Check</div>
                            <?php if ($rec['tabulation_ok']): ?>
                                <div class="text-success" style="font-size:.85rem;">Student found in the Final Result Tabulation.</div>
                            <?php else: ?>
                                <div class="text-danger mt-1" style="font-size:.85rem;"><strong>Reason / Issues:</strong> <?= nl2br(h($rec['tabulation_issues'])) ?></div>
                            <?php endif; ?>
                            <?php
                            if ($rec['tabulation_file_id']) {
                                $tfstmt = db()->prepare('SELECT file_name, stored_name, original_name FROM student_files WHERE id=?');
                                $tfstmt->execute([$rec['tabulation_file_id']]);
                                $tff = $tfstmt->fetch();
                                if ($tff): ?>
                                <div class="mt-1" style="font-size:.8rem;">
                                    <a href="<?= UPLOAD_URL ?>/students/files/<?= h($tff['stored_name']) ?>" target="_blank" class="text-danger">
                                        <i class="fas fa-file-pdf me-1"></i><?= h($tff['file_name']) ?>
                                    </a>
                                </div>
                                <?php endif;
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     ACTIONS (only for Verified)
═══════════════════════════════════════════════════════════ -->
<?php if ($rec['overall_status'] === 'Verified'): ?>
<div class="row g-4 mb-4">
    <!-- Upload Signed Copy -->
    <div class="col-12 col-md-6">
        <div class="card">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-upload me-2 text-muted"></i>Upload Signed Verified Copy</h6>
            </div>
            <div class="card-body px-4 py-3">
                <p class="text-muted" style="font-size:.875rem;">
                    Print the certificate, get authorised signatures, scan and upload the signed PDF here.
                </p>
                <form method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="upload_verified_pdf">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="font-size:.85rem;">Signed PDF File</label>
                        <input type="file" name="verified_pdf" class="form-control form-control-sm" accept="application/pdf,.pdf" required>
                        <div class="form-text">Only PDF, max 20 MB.</div>
                    </div>
                    <button type="submit" class="btn btn-outline-success btn-sm" style="border-radius:8px;">
                        <i class="fas fa-upload me-1"></i> Upload
                    </button>
                    <?php if ($rec['verified_pdf']): ?>
                    <a href="<?= UPLOAD_URL ?>/student-verification/<?= h($rec['verified_pdf']) ?>"
                       target="_blank" class="btn btn-sm btn-outline-secondary ms-2" style="border-radius:8px;">
                        <i class="fas fa-file-pdf me-1"></i> View Current
                    </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <!-- Send Email -->
    <div class="col-12 col-md-6">
        <div class="card">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-envelope me-2 text-muted"></i>Send Verification Email</h6>
            </div>
            <div class="card-body px-4 py-3">
                <p class="text-muted" style="font-size:.875rem;">
                    Send the verification result email from
                    <strong>verification@primeuniversity.ac.bd</strong>.
                </p>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="send_email">
                    <div class="mb-2">
                        <label class="form-label fw-semibold" style="font-size:.85rem;">Recipient Name</label>
                        <input type="text" name="send_to_name" class="form-control form-control-sm"
                               placeholder="e.g. HR Manager" value="">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="font-size:.85rem;">Recipient Email</label>
                        <input type="email" name="send_to_email" class="form-control form-control-sm"
                               placeholder="recipient@example.com"
                               value="<?= h($rec['verifier_email'] ?? '') ?>" required>
                    </div>
                    <button type="submit" class="btn btn-outline-primary btn-sm" style="border-radius:8px;">
                        <i class="fas fa-paper-plane me-1"></i> Send Email
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php else: ?>
<!-- Failed: show note and email option -->
<div class="card mb-4 border-danger border-opacity-50">
    <div class="card-header py-3 px-4 bg-danger bg-opacity-10">
        <h6 class="mb-0 fw-semibold text-danger"><i class="fas fa-envelope me-2"></i>Send Failure Notification</h6>
    </div>
    <div class="card-body px-4 py-3">
        <div class="alert alert-warning d-flex align-items-start gap-2 mb-3 py-2 px-3" style="font-size:.85rem;">
            <i class="fas fa-exclamation-triangle mt-1 flex-shrink-0"></i>
            <div><strong>Warning:</strong> This verification has <strong>failed</strong>. Only send this notification if you intend to inform the recipient of the failure result.</div>
        </div>
        <p class="text-muted" style="font-size:.875rem;">
            The email will be sent from <strong>verification@primeuniversity.ac.bd</strong> and will include the reasons for failure and a note to visit the university.
        </p>
        <form method="POST" class="row g-2 align-items-end"
              onsubmit="return confirm('This verification has FAILED.\n\nAre you sure you want to send a failure notification email to the recipient?\n\nThis action cannot be undone.');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send_email">
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold" style="font-size:.85rem;">Recipient Name</label>
                <input type="text" name="send_to_name" class="form-control form-control-sm" placeholder="e.g. HR Manager">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold" style="font-size:.85rem;">Recipient Email</label>
                <input type="email" name="send_to_email" class="form-control form-control-sm"
                       placeholder="recipient@example.com"
                       value="<?= h($rec['verifier_email'] ?? '') ?>" required>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-danger btn-sm" style="border-radius:8px;">
                    <i class="fas fa-paper-plane me-1"></i> Send Failure Notification
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
