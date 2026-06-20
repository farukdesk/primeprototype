<?php
/**
 * Fake / Invalid ID Alert
 *
 * Allows staff to manually enter a Student ID and Name for a document that
 * could NOT be found in the system, generate a "FRAUDULENT / FAKE ID" PDF
 * certificate, and send it to the verifying organisation.
 *
 * Email is sent FROM: coe@primeuniversity.ac.bd
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('student-verification');
require_once __DIR__ . '/../change-log/helpers.php';
require_once __DIR__ . '/../../vendor/autoload.php';

$page_title = 'Fake / Invalid ID Alert';
$user       = auth_user();

$success_msg = '';
$error_msg   = '';

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $student_id   = trim($_POST['student_id']   ?? '');
    $student_name = trim($_POST['student_name'] ?? '');
    $to_email     = trim($_POST['to_email']     ?? '');
    $to_name      = trim($_POST['to_name']      ?? '');
    $notes        = trim($_POST['notes']        ?? '');

    // Basic validation
    if ($student_id === '' || $student_name === '') {
        $error_msg = 'Student ID and Student Name are required.';
    } elseif (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        $error_msg = 'Please enter a valid recipient email address.';
    } else {
        $ref_no   = 'PU-FAKE-' . strtoupper(substr(md5($student_id . microtime()), 0, 8));
        $date_str = date('d F Y');
        $time_str = date('H:i');

        // ── Embed logo as base64 for PDF (Dompdf cannot fetch remote URLs) ──
        $logo_path     = dirname(dirname(__DIR__)) . '/assets/img/logo/logo-black.png';
        $logo_data_uri = '';
        if (is_file($logo_path) && is_readable($logo_path)) {
            $logo_data_uri = 'data:image/png;base64,' . base64_encode(file_get_contents($logo_path));
        }
        $logo_img_html = $logo_data_uri
            ? '<img src="' . $logo_data_uri . '" style="height:60px;width:auto;display:block;flex-shrink:0;" alt="Prime University Bangladesh">'
            : '';

        // ── Build email body (HTML) ──────────────────────────────────────────
        $notes_html = $notes !== ''
            ? '<p style="margin:0 0 16px;"><strong>Additional Notes:</strong><br>' . nl2br(htmlspecialchars($notes)) . '</p>'
            : '';

        $body = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#333;max-width:640px;margin:auto;">'
              . '<div style="background:linear-gradient(135deg,#7f1d1d,#dc2626);color:#fff;padding:20px 28px;border-radius:10px 10px 0 0;">'
              . '<h2 style="margin:0;font-size:1.25rem;font-weight:800;">Prime University Bangladesh</h2>'
              . '<p style="margin:4px 0 0;font-size:.8rem;opacity:.75;">Controller of Examinations &nbsp;|&nbsp; Fake / Invalid ID Alert &nbsp;|&nbsp; ' . htmlspecialchars($ref_no) . '</p>'
              . '</div>'
              . '<div style="padding:26px 28px;border:1px solid #e0e0e0;border-top:none;border-radius:0 0 10px 10px;">'
              . '<p style="margin:0 0 16px;">Dear ' . htmlspecialchars($to_name ?: 'Recipient') . ',</p>'
              . '<p style="margin:0 0 16px;">We have investigated the credentials presented for the following individual and found them to be <strong>FAKE / FRAUDULENT / NOT GENUINE</strong>. This person does <strong>NOT</strong> appear in the records of Prime University Bangladesh.</p>'
              . '<table style="width:100%;border-collapse:collapse;margin:0 0 16px;">'
              . '<tr><td style="padding:7px 10px;background:#f5f7fa;font-weight:600;width:40%;border-bottom:1px solid #eee;">Presented Name</td><td style="padding:7px 10px;border-bottom:1px solid #eee;">' . htmlspecialchars($student_name) . '</td></tr>'
              . '<tr><td style="padding:7px 10px;background:#f5f7fa;font-weight:600;border-bottom:1px solid #eee;">Presented Student ID</td><td style="padding:7px 10px;border-bottom:1px solid #eee;">' . htmlspecialchars($student_id) . '</td></tr>'
              . '<tr><td style="padding:7px 10px;background:#f5f7fa;font-weight:600;">Date of Check</td><td style="padding:7px 10px;">' . $date_str . ' at ' . $time_str . '</td></tr>'
              . '</table>'
              . '<p style="font-size:1.05rem;font-weight:700;padding:12px 16px;border-radius:8px;margin:0 0 16px;background:#fee2e2;color:#7f1d1d;">&#9888; FRAUDULENT ID – NOT GENUINE</p>'
              . $notes_html
              . '<p style="margin:0 0 16px;color:#555;">The attached PDF is an official invalid-ID notification from Prime University Bangladesh. If you require further information or have any questions, please contact the Controller of Examinations at <a href="mailto:coe@primeuniversity.ac.bd">coe@primeuniversity.ac.bd</a> or visit us at 114/116 Mazar Road, Mirpur-1, Dhaka 1216, Bangladesh.</p>'
              . '<hr style="border:none;border-top:1px solid #eee;margin:20px 0;">'
              . '<p style="font-size:.8rem;color:#888;">This email was sent from <a href="mailto:coe@primeuniversity.ac.bd">coe@primeuniversity.ac.bd</a> by the Prime University Controller of Examinations.</p>'
              . '</div></body></html>';

        // ── Build PDF certificate HTML ───────────────────────────────────────
        $notes_cert_html = $notes !== ''
            ? '<div style="margin-top:14px;padding:10px 14px;background:#fff7ed;border:1.5px solid #fed7aa;border-radius:8px;">'
              . '<div style="font-size:8pt;font-weight:800;color:#92400e;margin-bottom:3px;">Additional Notes</div>'
              . '<div style="font-size:8pt;color:#78350f;">' . nl2br(htmlspecialchars($notes)) . '</div>'
              . '</div>'
            : '';

        // ── Build PDF certificate HTML (emoji-free for Dompdf compatibility) ─
        $cert_html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
            . '<title>Fake / Invalid ID Notification - ' . htmlspecialchars($student_name) . '</title></head>'
            . '<body style="font-family:Arial,Helvetica,sans-serif;background:#eef2f7;margin:0;padding:20px 16px 40px;">'
            . '<div style="max-width:760px;margin:0 auto;">'
            . '<div style="background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,.12);border:1.5px solid #e2e8f0;">'
            . '<div style="height:7px;background:linear-gradient(90deg,#7f1d1d 0%,#dc2626 50%,#991b1b 100%);"></div>'
            // Header with logo
            . '<div style="padding:18px 32px 14px;border-bottom:2px solid #e8edf5;display:table;width:100%;box-sizing:border-box;">'
            . '<div style="display:table-cell;vertical-align:middle;width:80px;">' . $logo_img_html . '</div>'
            . '<div style="display:table-cell;vertical-align:middle;padding-left:14px;">'
            . '<div style="font-size:14pt;font-weight:800;color:#1a2e5a;margin-bottom:3px;">Prime University Bangladesh</div>'
            . '<div style="font-size:7.5pt;color:#4b5563;line-height:1.8;">114/116 Mazar Road, Mirpur-1, Dhaka 1216, Bangladesh | coe@primeuniversity.ac.bd</div>'
            . '<div style="font-size:8pt;color:#6b7280;margin-top:2px;">Controller of Examinations</div>'
            . '</div>'
            . '</div>'
            // Title bar (no emoji - use plain text)
            . '<div style="background:linear-gradient(135deg,#7f1d1d,#dc2626);padding:12px 32px;">'
            . '<table style="width:100%;border-collapse:collapse;"><tr>'
            . '<td style="font-size:10.5pt;font-weight:800;color:#fff;letter-spacing:.04em;text-transform:uppercase;">FAKE / INVALID ID NOTIFICATION</td>'
            . '<td style="font-size:7.5pt;color:rgba(255,255,255,.65);font-family:monospace;text-align:right;">' . htmlspecialchars($ref_no) . '</td>'
            . '</tr></table>'
            . '</div>'
            // Body
            . '<div style="padding:24px 32px 28px;">'
            // Student info block
            . '<div style="background:#fff5f5;border:1.5px solid #fca5a5;border-radius:12px;padding:16px 20px;margin-bottom:20px;">'
            . '<div style="font-size:13pt;font-weight:800;color:#1a2e5a;margin-bottom:6px;">' . htmlspecialchars($student_name) . '</div>'
            . '<div style="display:inline-block;background:#7f1d1d;color:#fff;border-radius:5px;padding:3px 10px;font-size:8pt;font-weight:700;letter-spacing:.05em;margin-bottom:10px;">[ID] ' . htmlspecialchars($student_id) . '</div>'
            . '<table style="border-collapse:collapse;width:100%;max-width:420px;margin-top:6px;">'
            . '<tr><td style="color:#9ca3af;font-size:7.5pt;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:3px 0;width:44%;">Presented Student ID</td><td style="font-size:9.5pt;font-weight:700;color:#7f1d1d;">' . htmlspecialchars($student_id) . '</td></tr>'
            . '<tr><td style="color:#9ca3af;font-size:7.5pt;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:3px 0;">Presented Name</td><td style="font-size:9.5pt;font-weight:700;color:#1a2e5a;">' . htmlspecialchars($student_name) . '</td></tr>'
            . '<tr><td style="color:#9ca3af;font-size:7.5pt;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:3px 0;">Date of Check</td><td style="font-size:9pt;color:#1a2e5a;">' . $date_str . ' at ' . $time_str . '</td></tr>'
            . '</table>'
            . '</div>'
            // Status badge (no emoji icon span - use styled text block)
            . '<div style="padding:13px 16px;border-radius:10px;background:#fee2e2;border:2px solid #fca5a5;margin-bottom:20px;">'
            . '<div style="font-size:10pt;font-weight:800;color:#7f1d1d;">&#9888; FRAUDULENT ID &#8211; NOT GENUINE</div>'
            . '<div style="font-size:8pt;color:#991b1b;margin-top:5px;">This Student ID does NOT exist in the records of Prime University Bangladesh. The presented credentials are FAKE / INVALID.</div>'
            . '</div>'
            // Findings heading (use text X instead of &#10005; which may not render in Dompdf)
            . '<div style="font-size:8pt;font-weight:800;color:#dc2626;text-transform:uppercase;letter-spacing:.07em;border-bottom:1.5px solid #fca5a5;padding-bottom:7px;margin-bottom:12px;">[X] FINDINGS</div>'
            . '<table style="width:100%;border-collapse:collapse;margin-bottom:8px;">'
            . '<tr><td style="width:22px;vertical-align:top;padding:9px 0 9px 14px;background:#fee2e2;border:1.5px solid #fca5a5;border-radius:8px 0 0 8px;"><span style="color:#dc2626;font-size:9pt;font-weight:900;">[X]</span></td>'
            . '<td style="padding:9px 14px 9px 8px;background:#fee2e2;border:1.5px solid #fca5a5;border-left:none;border-radius:0 8px 8px 0;">'
            . '<div style="font-size:8.5pt;font-weight:700;color:#991b1b;">Student ID Not Found in System</div>'
            . '<div style="font-size:7.5pt;color:#7f1d1d;margin-top:2px;">The Student ID "' . htmlspecialchars($student_id) . '" does not match any record in the Prime University Bangladesh student database.</div>'
            . '</td></tr></table>'
            . '<table style="width:100%;border-collapse:collapse;margin-bottom:8px;">'
            . '<tr><td style="width:22px;vertical-align:top;padding:9px 0 9px 14px;background:#fee2e2;border:1.5px solid #fca5a5;border-radius:8px 0 0 8px;"><span style="color:#dc2626;font-size:9pt;font-weight:900;">[X]</span></td>'
            . '<td style="padding:9px 14px 9px 8px;background:#fee2e2;border:1.5px solid #fca5a5;border-left:none;border-radius:0 8px 8px 0;">'
            . '<div style="font-size:8.5pt;font-weight:700;color:#991b1b;">Presented Credentials Cannot Be Verified</div>'
            . '<div style="font-size:7.5pt;color:#7f1d1d;margin-top:2px;">No matching enrollment, academic records, or graduation data found for the presented identity.</div>'
            . '</td></tr></table>'
            . $notes_cert_html
            // Footer seal (no emoji)
            . '<div style="background:linear-gradient(135deg,#fff5f5,#fee2e2);border:1.5px solid #fca5a5;border-radius:12px;padding:14px 18px;margin-top:20px;">'
            . '<div style="font-size:9pt;font-weight:800;color:#7f1d1d;margin-bottom:3px;">Issued by Controller of Examinations &#8211; Prime University Bangladesh</div>'
            . '<div style="font-size:7.5pt;color:#dc2626;line-height:1.4;">'
            . 'Checked by: <strong>' . htmlspecialchars($user['full_name']) . '</strong> | Date: ' . $date_str . ' at ' . $time_str . ' | Ref: ' . htmlspecialchars($ref_no)
            . '</div></div>'
            . '</div>'
            // Footer bar
            . '<div style="background:#f8fafc;border-top:1.5px solid #e8edf5;padding:10px 32px;text-align:center;font-size:7pt;color:#9ca3af;">'
            . 'This notification was generated by the Prime University Bangladesh Verification System. Reference: ' . htmlspecialchars($ref_no) . ' | Fake / Invalid ID Notification'
            . '</div>'
            . '</div></div></body></html>';

        // ── Render PDF via Dompdf ────────────────────────────────────────────
        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
        $dompdf->loadHtml($cert_html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $pdf_data = $dompdf->output();

        // ── Build MIME multipart email with PDF attachment ───────────────────
        // $from_email is a hardcoded constant – not derived from user input.
        // escapeshellarg() is still applied below as a defence-in-depth measure.
        $from_email   = 'coe@primeuniversity.ac.bd';
        $from_name    = 'Prime University – Controller of Examinations';
        $encoded_from = '=?UTF-8?B?' . base64_encode($from_name) . '?=';
        $boundary     = '----=_Part_' . md5(uniqid('', true));
        $subject      = 'Fake / Invalid Student ID Notification – ' . $student_id . ' (' . $student_name . ')';
        $attach_name  = 'fake-id-notification-' . preg_replace('/[^A-Za-z0-9\-]/', '', $student_id) . '.pdf';

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

        // ── Save to fake_id_verifications log (regardless of email outcome) ──
        try {
            db()->prepare(
                'INSERT INTO fake_id_verifications
                   (student_id, student_name, to_email, to_name, notes, ref_no, email_sent, checked_by)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([
                $student_id, $student_name,
                $to_email ?: null, $to_name ?: null,
                $notes ?: null, $ref_no,
                $sent ? 1 : 0,
                $user['id'],
            ]);
        } catch (Throwable $e) {}

        if ($sent) {
            // Log the action
            try {
                log_change('student-verification', 'FAKE_ID_ALERT', 0,
                    $student_name . ' (' . $student_id . ')',
                    'fake_id_email', null, $to_email,
                    'Fake ID alert sent to ' . $to_email . ' by ' . $user['full_name'] . ' | Ref: ' . $ref_no);
            } catch (Throwable $e) {}

            $success_msg = 'Fake / Invalid ID alert email sent to <strong>' . h($to_email) . '</strong> with PDF attached. (Ref: ' . h($ref_no) . ')';
            // Clear form values on success
            $student_id = $student_name = $to_email = $to_name = $notes = '';
        } else {
            $error_msg = 'Failed to send the email. Please check the mail server configuration.';
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/student-verification/index.php">Student Verification</a></li>
            <li class="breadcrumb-item active">Fake / Invalid ID Alert</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/student-verification/index.php" class="btn btn-outline-secondary btn-sm" style="border-radius:8px;">
        <i class="fas fa-arrow-left me-1"></i> Back to Log
    </a>
</div>

<?php if ($success_msg): ?>
<div class="alert alert-success d-flex align-items-center gap-2 mb-4" style="border-radius:10px;">
    <i class="fas fa-check-circle"></i>
    <div><?= $success_msg ?></div>
</div>
<?php endif; ?>

<?php if ($error_msg): ?>
<div class="alert alert-danger d-flex align-items-center gap-2 mb-4" style="border-radius:10px;">
    <i class="fas fa-exclamation-circle"></i>
    <div><?= h($error_msg) ?></div>
</div>
<?php endif; ?>

<!-- Info banner -->
<div class="alert mb-4 d-flex align-items-start gap-3" style="background:#fff5f5;border:1.5px solid #fca5a5;border-radius:12px;">
    <i class="fas fa-exclamation-triangle text-danger mt-1" style="font-size:1.5rem;flex-shrink:0;"></i>
    <div>
        <div class="fw-bold text-danger mb-1">Send Fake / Invalid ID Notification</div>
        <div style="font-size:.875rem;color:#7f1d1d;">
            Use this form when a presented Student ID <strong>cannot be found</strong> in the system and the documents appear to be fake or fraudulent.
            Manually enter the ID and name as presented on the documents, provide the recipient's email, and the system will generate an official
            <strong>Fake / Invalid ID Notification PDF</strong> and send it from
            <strong>coe@primeuniversity.ac.bd</strong>.
        </div>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-12 col-lg-8">
        <div class="card border-danger border-opacity-50">
            <div class="card-header py-3 px-4 bg-danger bg-opacity-10">
                <h6 class="mb-0 fw-semibold text-danger">
                    <i class="fas fa-ban me-2"></i>Fake / Invalid ID Alert Form
                </h6>
            </div>
            <div class="card-body px-4 py-4">
                <form method="POST"
                      onsubmit="return confirm('Are you sure you want to send a Fake / Invalid ID alert email?\n\nThis will notify the recipient that the presented credentials are FRAUDULENT.\n\nThis action cannot be undone.');">
                    <?= csrf_field() ?>

                    <div class="mb-4">
                        <h6 class="fw-semibold text-muted mb-3" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;">
                            <i class="fas fa-id-card me-1"></i> Presented Credentials (as on the fake document)
                        </h6>
                        <div class="row g-3">
                            <div class="col-12 col-md-5">
                                <label class="form-label fw-semibold" style="font-size:.85rem;">
                                    Student ID <span class="text-danger">*</span>
                                </label>
                                <input type="text" name="student_id" class="form-control"
                                       placeholder="e.g. 201-15-3456"
                                       value="<?= h($student_id ?? '') ?>"
                                       required maxlength="50">
                                <div class="form-text">Enter the ID exactly as shown on the presented document.</div>
                            </div>
                            <div class="col-12 col-md-7">
                                <label class="form-label fw-semibold" style="font-size:.85rem;">
                                    Student Name <span class="text-danger">*</span>
                                </label>
                                <input type="text" name="student_name" class="form-control"
                                       placeholder="e.g. Mohammad Hasan"
                                       value="<?= h($student_name ?? '') ?>"
                                       required maxlength="200">
                                <div class="form-text">Enter the name exactly as shown on the presented document.</div>
                            </div>
                        </div>
                    </div>

                    <hr class="my-3">

                    <div class="mb-4">
                        <h6 class="fw-semibold text-muted mb-3" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;">
                            <i class="fas fa-envelope me-1"></i> Recipient (Verifying Organisation)
                        </h6>
                        <div class="row g-3">
                            <div class="col-12 col-md-5">
                                <label class="form-label fw-semibold" style="font-size:.85rem;">Recipient Name</label>
                                <input type="text" name="to_name" class="form-control"
                                       placeholder="e.g. HR Manager"
                                       value="<?= h($to_name ?? '') ?>"
                                       maxlength="200">
                            </div>
                            <div class="col-12 col-md-7">
                                <label class="form-label fw-semibold" style="font-size:.85rem;">
                                    Recipient Email <span class="text-danger">*</span>
                                </label>
                                <input type="email" name="to_email" class="form-control"
                                       placeholder="hr@organisation.com"
                                       value="<?= h($to_email ?? '') ?>"
                                       required maxlength="254">
                            </div>
                        </div>
                    </div>

                    <hr class="my-3">

                    <div class="mb-4">
                        <label class="form-label fw-semibold" style="font-size:.85rem;">
                            <i class="fas fa-sticky-note me-1 text-muted"></i> Additional Notes
                            <span class="text-muted fw-normal">(optional)</span>
                        </label>
                        <textarea name="notes" class="form-control" rows="3"
                                  placeholder="e.g. Presented as part of a job application on 29 April 2026. Documents appeared tampered."
                                  maxlength="1000"><?= h($notes ?? '') ?></textarea>
                        <div class="form-text">These notes will appear in both the email body and the PDF attachment.</div>
                    </div>

                    <div class="alert alert-secondary py-2 px-3 mb-4 d-flex align-items-center gap-2" style="font-size:.83rem;border-radius:8px;">
                        <i class="fas fa-info-circle text-primary flex-shrink-0"></i>
                        <div>
                            Email will be sent <strong>from:</strong> <code>coe@primeuniversity.ac.bd</code>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            A <strong>PDF certificate</strong> will be generated automatically and attached.
                        </div>
                    </div>

                    <button type="submit" class="btn btn-danger" style="border-radius:8px;">
                        <i class="fas fa-paper-plane me-1"></i> Send Fake ID Alert Email
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
