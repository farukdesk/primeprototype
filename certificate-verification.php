<?php
/**
 * Public Certificate Verification Page
 * Allows students and companies to verify a student's academic record.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/seo.php';

$page_title = 'Certificate Verification – Prime University';

if (session_status() === PHP_SESSION_NONE) session_start();
$csrf_token = $_SESSION['pub_csrf'] ?? ($_SESSION['pub_csrf'] = bin2hex(random_bytes(16)));

// ── Form state ────────────────────────────────────────────────────────────────
$form_errors   = [];
$submitted      = false;
$student        = null;
$result_info    = null; // ['ending_semester', 'publish_date', 'final_cgpa']

$fd = [
    'verifier_type'   => '',
    // Student fields
    'st_name'         => '',
    'st_phone'        => '',
    'st_email'        => '',
    // Company fields
    'co_company_name' => '',
    'co_address'      => '',
    'co_your_name'    => '',
    'co_designation'  => '',
    'co_email'        => '',
    'co_phone'        => '',
    // Common
    'student_id'      => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check
    if (!hash_equals($_SESSION['pub_csrf'] ?? '', $_POST['_csrf'] ?? '')) {
        $form_errors[] = 'Invalid security token. Please refresh the page and try again.';
    } else {

        // Collect & sanitise form data
        $fd['verifier_type']   = trim($_POST['verifier_type']   ?? '');
        $fd['st_name']         = trim($_POST['st_name']         ?? '');
        $fd['st_phone']        = trim($_POST['st_phone']        ?? '');
        $fd['st_email']        = trim($_POST['st_email']        ?? '');
        $fd['co_company_name'] = trim($_POST['co_company_name'] ?? '');
        $fd['co_address']      = trim($_POST['co_address']      ?? '');
        $fd['co_your_name']    = trim($_POST['co_your_name']    ?? '');
        $fd['co_designation']  = trim($_POST['co_designation']  ?? '');
        $fd['co_email']        = trim($_POST['co_email']        ?? '');
        $fd['co_phone']        = trim($_POST['co_phone']        ?? '');
        $fd['student_id']      = trim($_POST['student_id']      ?? '');

        // Validate verifier type
        if (!in_array($fd['verifier_type'], ['student', 'company'], true)) {
            $form_errors[] = 'Please select who is verifying the certificate.';
        }

        // Validate verifier-specific fields
        if ($fd['verifier_type'] === 'student') {
            if ($fd['st_name']  === '') $form_errors[] = 'Your name is required.';
            if ($fd['st_phone'] === '') $form_errors[] = 'Your phone number is required.';
            if ($fd['st_email'] === '' || !filter_var($fd['st_email'], FILTER_VALIDATE_EMAIL)) {
                $form_errors[] = 'A valid email address is required.';
            }
        } elseif ($fd['verifier_type'] === 'company') {
            if ($fd['co_company_name'] === '') $form_errors[] = 'Company name is required.';
            if ($fd['co_address']      === '') $form_errors[] = 'Company address is required.';
            if ($fd['co_your_name']    === '') $form_errors[] = 'Your name is required.';
            if ($fd['co_designation']  === '') $form_errors[] = 'Your designation is required.';
            if ($fd['co_email']        === '' || !filter_var($fd['co_email'], FILTER_VALIDATE_EMAIL)) {
                $form_errors[] = 'A valid email address is required.';
            }
            if ($fd['co_phone'] === '') $form_errors[] = 'Phone number is required.';
        }

        // Validate student ID
        if ($fd['student_id'] === '') {
            $form_errors[] = 'Student ID is required.';
        }

        // Look up student if no errors
        if (empty($form_errors)) {
            $submitted = true;
            try {
                $db = front_db();
                if (!$db) {
                    $form_errors[] = 'Could not connect to the database. Please try again later.';
                    $submitted = false;
                } else {
                    // Fetch student record
                    $stmt = $db->prepare(
                        'SELECT s.id, s.student_id, s.full_name, s.batch,
                                s.admitted_semester, s.status, s.photo,
                                d.name  AS dept_name,
                                p.program_name
                         FROM   students s
                         JOIN   dept_departments d ON d.id = s.dept_id
                         LEFT JOIN dept_academic_programs p ON p.id = s.program_id
                         WHERE  s.student_id = ?
                         LIMIT  1'
                    );
                    $stmt->execute([$fd['student_id']]);
                    $student = $stmt->fetch() ?: null;

                    if ($student) {
                        // Try to find the most recent published result exam linked to this student
                        $res_stmt = $db->prepare(
                            'SELECT re.completion_semester, re.updated_at
                             FROM   result_grades rg
                             JOIN   result_exams re ON re.id = rg.exam_id
                             WHERE  rg.student_sid = ?
                               AND  re.is_published = 1
                             ORDER  BY re.updated_at DESC
                             LIMIT  1'
                        );
                        $res_stmt->execute([$student['student_id']]);
                        $exam_row = $res_stmt->fetch();

                        // Fall back to student_results table if no result_exam found
                        if (!$exam_row) {
                            $sr_stmt = $db->prepare(
                                'SELECT semester, semester_year, recorded_date
                                 FROM   student_results
                                 WHERE  student_id = ?
                                 ORDER  BY recorded_date DESC, semester_year DESC
                                 LIMIT  1'
                            );
                            $sr_stmt->execute([$student['id']]);
                            $sr_row = $sr_stmt->fetch();
                            if ($sr_row) {
                                $parts = array_filter([
                                    $sr_row['semester']      ?? '',
                                    $sr_row['semester_year'] ?? '',
                                ]);
                                $result_info = [
                                    'ending_semester' => $parts ? implode(' ', $parts) : null,
                                    'publish_date'    => $sr_row['recorded_date'] ? date('d M Y', strtotime($sr_row['recorded_date'])) : null,
                                    'final_cgpa'      => null,
                                ];
                            }
                        } else {
                            $result_info = [
                                'ending_semester' => $exam_row['completion_semester'] ?? null,
                                'publish_date'    => $exam_row['updated_at'] ? date('d M Y', strtotime($exam_row['updated_at'])) : null,
                                'final_cgpa'      => null,
                            ];
                        }

                        // Compute Final CGPA across all published result_exams
                        try {
                            $cgpa_stmt = $db->prepare(
                                'SELECT ROUND(
                                     SUM(rg.grade_point * COALESCE(rs.credits, 3)) /
                                     NULLIF(SUM(COALESCE(rs.credits, 3)), 0), 2
                                 ) AS cgpa
                                 FROM   result_grades   rg
                                 JOIN   result_exams    re ON re.id = rg.exam_id
                                 JOIN   result_subjects rs ON rs.id = rg.subject_id
                                 WHERE  rg.student_sid     = ?
                                   AND  re.is_published    = 1
                                   AND  rg.grade_point     IS NOT NULL
                                   AND  COALESCE(rs.credits, 3) > 0'
                            );
                            $cgpa_stmt->execute([$student['student_id']]);
                            $cgpa_val = $cgpa_stmt->fetchColumn();
                            if ($cgpa_val !== null && $cgpa_val !== false) {
                                if ($result_info === null) {
                                    $result_info = ['ending_semester' => null, 'publish_date' => null, 'final_cgpa' => null];
                                }
                                $result_info['final_cgpa'] = number_format((float)$cgpa_val, 2);
                            }
                        } catch (Throwable $cgpa_ex) {
                            // CGPA query failed silently; leave as null
                        }

                        // Fallback: if CGPA still not resolved, read it from
                        // student_results (the Academic Results table in the student module)
                        if ($result_info === null || $result_info['final_cgpa'] === null) {
                            try {
                                $sr_cgpa_stmt = $db->prepare(
                                    'SELECT MAX(CAST(cgpa AS DECIMAL(5,2))) AS final_cgpa
                                     FROM   student_results
                                     WHERE  student_id = ?
                                       AND  cgpa IS NOT NULL
                                       AND  TRIM(cgpa) != \'\''
                                );
                                $sr_cgpa_stmt->execute([$student['id']]);
                                $sr_cgpa = $sr_cgpa_stmt->fetchColumn();
                                if ($sr_cgpa !== null && (float)$sr_cgpa > 0) {
                                    if ($result_info === null) {
                                        $result_info = ['ending_semester' => null, 'publish_date' => null, 'final_cgpa' => null];
                                    }
                                    $result_info['final_cgpa'] = number_format((float)$sr_cgpa, 2);
                                }
                            } catch (Throwable $sr_cgpa_ex) {
                                // Silent fallback; leave as null
                            }
                        }
                    }

                    // Save verifier details to the log
                    try {
                        // Note: HTTP_X_FORWARDED_FOR may be spoofed; stored for informational purposes only.
                        $verifier_ip = $_SERVER['HTTP_X_FORWARDED_FOR']
                            ?? $_SERVER['HTTP_X_REAL_IP']
                            ?? $_SERVER['REMOTE_ADDR']
                            ?? null;
                        // Use only the first IP if comma-separated
                        if ($verifier_ip) {
                            $verifier_ip = trim(explode(',', $verifier_ip)[0]);
                        }

                        $log_stmt = $db->prepare(
                            'INSERT INTO cert_verification_log
                               (queried_student_id, student_id, student_found,
                                verifier_type, verifier_name, verifier_email, verifier_phone,
                                company_name, company_address, verifier_designation,
                                ip_address)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                        );
                        $log_stmt->execute([
                            $fd['student_id'],
                            $student ? (int)$student['id'] : null,
                            $student ? 1 : 0,
                            $fd['verifier_type'],
                            $fd['verifier_type'] === 'company' ? $fd['co_your_name']    : $fd['st_name'],
                            $fd['verifier_type'] === 'company' ? $fd['co_email']         : $fd['st_email'],
                            $fd['verifier_type'] === 'company' ? $fd['co_phone']         : $fd['st_phone'],
                            $fd['verifier_type'] === 'company' ? $fd['co_company_name']  : null,
                            $fd['verifier_type'] === 'company' ? $fd['co_address']       : null,
                            $fd['verifier_type'] === 'company' ? $fd['co_designation']   : null,
                            $verifier_ip,
                        ]);
                    } catch (Throwable $log_ex) {
                        // Log silently; do not surface to the user
                    }

                    // Refresh CSRF token after successful lookup
                    $_SESSION['pub_csrf'] = bin2hex(random_bytes(16));
                    $csrf_token           = $_SESSION['pub_csrf'];
                }
            } catch (Throwable $e) {
                $form_errors[] = 'A database error occurred. Please try again later.';
                $submitted     = false;
            }
        }
    }
}

// ── Photo URL helper (front-end equivalent of sm_photo_url) ───────────────────
function cert_photo_url(?string $photo): string
{
    if (!$photo) return '';
    // Reject filenames with path-traversal characters; only allow safe basenames
    if (!preg_match('/\A[A-Za-z0-9_\-]+\.[a-z]{2,5}\z/', $photo)) {
        return '';
    }
    // Check new upload location: admin/uploads/students/photos/
    $new_path = __DIR__ . '/admin/uploads/students/photos/' . $photo;
    if (is_file($new_path)) {
        return ADMIN_UPLOAD_URL . '/students/photos/' . rawurlencode($photo);
    }
    // Legacy location: upload_spic/ at site root
    return SITE_URL . '/upload_spic/' . rawurlencode($photo);
}
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <meta name="viewport" content="width=device-width, initial-scale=1">
<?php render_seo_meta('/certificate-verification.php', 'Certificate Verification', 'Verify the authenticity of a Prime University certificate or degree by entering the student ID below.'); ?>

   <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
   <link rel="stylesheet" href="/assets/css/font-awesome-pro.css">
   <link rel="stylesheet" href="/assets/css/swiper-bundle.min.css">
   <link rel="stylesheet" href="/assets/css/slick.css">
   <link rel="stylesheet" href="/assets/css/magnific-popup.css">
   <link rel="stylesheet" href="/assets/css/nice-select.css">
   <link rel="stylesheet" href="/assets/css/custom-animation.css">
   <link rel="stylesheet" href="/assets/css/spacing.css">
   <link rel="stylesheet" href="/assets/css/main.css">
   <style>
   /* ── Certificate Verification – Custom Styles ─────────────────────────── */

   .cv-hero {
      background: linear-gradient(135deg, #1a2e5a 0%, #0f6c3a 100%);
      padding: 90px 0 100px;
      position: relative;
      overflow: hidden;
   }
   .cv-hero::before {
      content: '';
      position: absolute;
      inset: 0;
      background: url('/assets/img/shape/breadcrumb-1-bg.png') center/cover no-repeat;
      opacity: .06;
   }
   .cv-hero::after {
      content: '';
      position: absolute;
      right: -100px;
      top: -100px;
      width: 400px;
      height: 400px;
      background: rgba(255,255,255,.04);
      border-radius: 50%;
      pointer-events: none;
   }
   .cv-hero .breadcrumb-nav a,
   .cv-hero .breadcrumb-nav span { color: rgba(255,255,255,.7); font-size: .85rem; }
   .cv-hero .breadcrumb-nav a:hover { color: #fff; }
   .cv-hero .breadcrumb-nav .sep { margin: 0 8px; color: rgba(255,255,255,.4); }
   .cv-hero h1 {
      font-size: clamp(1.9rem, 5vw, 3rem);
      font-weight: 800;
      color: #fff;
      line-height: 1.25;
      margin-bottom: 14px;
   }
   .cv-hero .badge-pill {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      background: rgba(255,255,255,.12);
      border: 1px solid rgba(255,255,255,.2);
      color: #fff;
      font-size: .8rem;
      font-weight: 600;
      padding: 6px 16px;
      border-radius: 50px;
      margin-bottom: 18px;
      backdrop-filter: blur(4px);
   }
   .cv-hero .tagline {
      font-size: 1rem;
      color: rgba(255,255,255,.82);
      max-width: 520px;
      line-height: 1.7;
   }

   /* ── Main section ─────────────────────────────────────────────────────── */
   .cv-section { background: #f4f7fb; padding: 0 0 80px; }

   .cv-card {
      background: #fff;
      border-radius: 20px;
      box-shadow: 0 10px 48px rgba(0,0,0,.09);
      padding: 44px 40px;
      margin-top: -56px;
      position: relative;
      z-index: 10;
   }
   @media (max-width: 575px) { .cv-card { padding: 28px 20px; margin-top: -40px; } }

   .cv-section-label {
      font-size: .72rem;
      font-weight: 700;
      letter-spacing: .08em;
      text-transform: uppercase;
      color: #9ca3af;
      margin-bottom: 6px;
   }
   .cv-section-title {
      font-size: 1.25rem;
      font-weight: 800;
      color: #1a2e5a;
      margin-bottom: 24px;
   }

   /* Verifier type toggle */
   .cv-type-toggle {
      display: flex;
      gap: 14px;
      margin-bottom: 28px;
      flex-wrap: wrap;
   }
   .cv-type-btn {
      flex: 1;
      min-width: 140px;
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 16px 20px;
      border: 2px solid #e2e8f0;
      border-radius: 14px;
      cursor: pointer;
      transition: border-color .2s, background .2s, box-shadow .2s;
      background: #f8fafc;
      user-select: none;
      font-weight: 600;
      color: #374151;
      font-size: .92rem;
   }
   .cv-type-btn:hover { border-color: #2563eb; background: #eef2ff; }
   .cv-type-btn.active {
      border-color: #2563eb;
      background: #eef2ff;
      color: #1d4ed8;
      box-shadow: 0 0 0 4px rgba(37,99,235,.1);
   }
   .cv-type-btn .icon-wrap {
      width: 40px; height: 40px;
      border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.1rem;
      flex-shrink: 0;
   }
   .cv-type-btn .icon-wrap.student { background: #dbeafe; color: #2563eb; }
   .cv-type-btn .icon-wrap.company { background: #d1fae5; color: #059669; }
   .cv-type-btn.active .icon-wrap.student { background: #2563eb; color: #fff; }
   .cv-type-btn.active .icon-wrap.company { background: #059669; color: #fff; }

   .cv-divider {
      border: none;
      border-top: 1.5px dashed #e2e8f0;
      margin: 28px 0;
   }

   /* Form fields */
   .cv-form .form-label {
      font-size: .78rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .04em;
      color: #374151;
      margin-bottom: 6px;
   }
   .cv-form .form-control,
   .cv-form .form-select {
      border: 1.5px solid #e2e8f0;
      border-radius: 10px;
      padding: 11px 15px;
      font-size: .92rem;
      color: #374151;
      background: #f8fafc;
      transition: border-color .2s, box-shadow .2s;
   }
   .cv-form .form-control:focus,
   .cv-form .form-select:focus {
      border-color: #2563eb;
      box-shadow: 0 0 0 3px rgba(37,99,235,.12);
      background: #fff;
      outline: none;
   }

   /* Student ID row */
   .cv-sid-wrap {
      background: #f0fdf4;
      border: 1.5px solid #bbf7d0;
      border-radius: 14px;
      padding: 22px 24px;
      margin-top: 28px;
   }
   .cv-sid-wrap label {
      font-size: .8rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .05em;
      color: #065f46;
      margin-bottom: 8px;
      display: block;
   }
   .cv-sid-row { display: flex; gap: 12px; }
   .cv-sid-row .form-control {
      border: 2px solid #6ee7b7;
      border-radius: 10px;
      padding: 12px 16px;
      font-size: 1rem;
      font-weight: 600;
      letter-spacing: .04em;
      background: #fff;
      color: #065f46;
      flex: 1;
   }
   .cv-sid-row .form-control:focus {
      border-color: #059669;
      box-shadow: 0 0 0 3px rgba(5,150,105,.15);
      outline: none;
   }
   .cv-verify-btn {
      background: linear-gradient(135deg, #1a2e5a 0%, #2563eb 100%);
      color: #fff;
      font-weight: 700;
      font-size: .92rem;
      padding: 12px 28px;
      border-radius: 10px;
      border: none;
      cursor: pointer;
      white-space: nowrap;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: opacity .25s, transform .2s;
   }
   .cv-verify-btn:hover { opacity: .88; transform: translateY(-2px); }

   /* Result card */
   .cv-result {
      background: #fff;
      border-radius: 20px;
      box-shadow: 0 10px 48px rgba(0,0,0,.09);
      padding: 40px 36px;
      margin-top: 32px;
   }
   @media (max-width: 575px) { .cv-result { padding: 24px 18px; } }

   .cv-result-header {
      display: flex;
      align-items: flex-start;
      gap: 24px;
      margin-bottom: 32px;
      flex-wrap: wrap;
   }
   .cv-student-photo {
      width: 110px;
      height: 130px;
      object-fit: cover;
      border-radius: 14px;
      border: 3px solid #e2e8f0;
      flex-shrink: 0;
   }
   .cv-student-photo-placeholder {
      width: 110px;
      height: 130px;
      border-radius: 14px;
      background: #e8edf5;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 3rem;
      color: #9ca3af;
      flex-shrink: 0;
      border: 3px solid #e2e8f0;
   }
   .cv-result-name { font-size: 1.5rem; font-weight: 800; color: #1a2e5a; margin-bottom: 6px; }
   .cv-result-meta { font-size: .88rem; color: #6b7280; margin-bottom: 4px; }
   .cv-result-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 5px 14px;
      border-radius: 50px;
      font-size: .78rem;
      font-weight: 700;
      margin-top: 8px;
   }
   .cv-result-badge.graduated { background: #d1fae5; color: #065f46; }
   .cv-result-badge.active    { background: #dbeafe; color: #1d4ed8; }
   .cv-result-badge.inactive  { background: #f3f4f6; color: #6b7280; }
   .cv-result-badge.dropped   { background: #fee2e2; color: #b91c1c; }

   .cv-info-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
      gap: 16px;
      margin-bottom: 28px;
   }
   .cv-info-item {
      background: #f8fafc;
      border-radius: 12px;
      padding: 16px 18px;
   }
   .cv-info-item .label {
      font-size: .7rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .06em;
      color: #9ca3af;
      margin-bottom: 5px;
   }
   .cv-info-item .value {
      font-size: .95rem;
      font-weight: 600;
      color: #1a2e5a;
      word-break: break-word;
   }

   .cv-manual-note {
      background: #fffbeb;
      border: 1.5px solid #fcd34d;
      border-radius: 12px;
      padding: 16px 20px;
      font-size: .88rem;
      color: #92400e;
      margin-top: 24px;
   }
   .cv-manual-note a { color: #b45309; font-weight: 700; }

   /* Print / PDF button */
   .cv-print-btn {
      display: inline-flex;
      align-items: center;
      gap: 9px;
      background: linear-gradient(135deg, #1a2e5a 0%, #2563eb 100%);
      color: #fff;
      font-weight: 700;
      font-size: .9rem;
      padding: 12px 26px;
      border-radius: 10px;
      text-decoration: none;
      box-shadow: 0 4px 18px rgba(37,99,235,.28);
      transition: opacity .25s, transform .2s;
   }
   .cv-print-btn:hover { opacity: .88; transform: translateY(-2px); color: #fff; }

   /* Not-found card */
   .cv-not-found {
      background: #fff;
      border-radius: 20px;
      box-shadow: 0 10px 48px rgba(0,0,0,.09);
      padding: 56px 36px;
      margin-top: 32px;
      text-align: center;
   }
   .cv-not-found .nf-icon {
      width: 80px; height: 80px;
      border-radius: 50%;
      background: #fee2e2;
      display: flex; align-items: center; justify-content: center;
      font-size: 2rem;
      color: #ef4444;
      margin: 0 auto 20px;
   }
   .cv-not-found h3 { font-size: 1.35rem; font-weight: 800; color: #1a2e5a; margin-bottom: 10px; }
   .cv-not-found p  { font-size: .92rem; color: #6b7280; max-width: 460px; margin: 0 auto 20px; line-height: 1.7; }
   .cv-not-found a  { color: #2563eb; font-weight: 700; }

   /* Alert */
   .cv-alert-danger {
      background: #fff1f2;
      border: 1.5px solid #fda4af;
      border-radius: 12px;
      color: #be123c;
      padding: 14px 18px;
      font-size: .9rem;
      margin-bottom: 20px;
   }

   /* Info sidebar card */
   .cv-info-sidebar {
      background: linear-gradient(135deg, #1a2e5a 0%, #1e4d8c 100%);
      border-radius: 20px;
      padding: 36px 30px;
      color: #fff;
      position: sticky;
      top: 24px;
   }
   .cv-info-sidebar h4 {
      font-size: 1.1rem;
      font-weight: 800;
      margin-bottom: 20px;
      color: #fff;
   }
   .cv-sidebar-item {
      display: flex;
      gap: 14px;
      margin-bottom: 20px;
      align-items: flex-start;
   }
   .cv-sidebar-item:last-child { margin-bottom: 0; }
   .cv-sidebar-item .si-icon {
      width: 38px; height: 38px; flex-shrink: 0;
      border-radius: 10px;
      background: rgba(255,255,255,.12);
      display: flex; align-items: center; justify-content: center;
      font-size: .9rem;
   }
   .cv-sidebar-item .si-text { font-size: .85rem; color: rgba(255,255,255,.82); line-height: 1.6; }
   .cv-sidebar-item .si-text strong { color: #fff; display: block; margin-bottom: 2px; font-size: .9rem; }
   .cv-sidebar-item .si-text a { color: #93c5fd; }

   @media (max-width: 991px) {
      .cv-info-sidebar { position: static; margin-top: 32px; }
   }
   </style>
<?php include __DIR__ . '/includes/meta-pixel.php'; ?>
</head>
<body id="body" class="it-magic-cursor">

   <div id="preloader">
      <div class="preloader">
         <span></span>
         <span></span>
      </div>
   </div>

   <div id="magic-cursor">
      <div id="ball"></div>
   </div>

   <button class="scroll-top scroll-to-target" data-target="html">
      <i class="far fa-angle-double-up"></i>
   </button>

   <div class="search-popup">
      <button class="close-search"><span class="flaticon-multiply"><i class="fal fa-times"></i></span></button>
      <form method="post" action="#">
         <div class="form-group">
            <input type="search" name="search-field" value="" placeholder="Search Here" required="">
            <button type="submit"><i class="fal fa-search"></i></button>
         </div>
      </form>
   </div>
<?php include __DIR__ . '/includes/offcanvas.php'; ?>

   <header class="it-header-height">
      <?php include __DIR__ . '/includes/header-top.php'; ?>
      <?php include __DIR__ . '/includes/nav-menu.php'; ?>
   </header>

   <!-- ── HERO ──────────────────────────────────────────────────────────────── -->
   <section class="cv-hero">
      <div class="container position-relative" style="z-index:2;">
         <nav class="breadcrumb-nav mb-20">
            <a href="<?= fh(SITE_URL) ?>/index.php">Home</a>
            <span class="sep">/</span>
            <span>Certificate Verification</span>
         </nav>
         <div class="badge-pill wow fadeInUp" data-wow-delay=".05s">
            <i class="fas fa-shield-alt"></i> Official Verification Portal
         </div>
         <h1 class="wow fadeInUp" data-wow-delay=".1s">Certificate<br>Verification</h1>
         <p class="tagline wow fadeInUp" data-wow-delay=".2s">
            Verify the authenticity of a Prime University degree or certificate
            instantly by entering the student&rsquo;s ID below.
         </p>
      </div>
   </section>
   <!-- ── HERO END ───────────────────────────────────────────────────────────── -->

   <section class="cv-section">
      <div class="container">
         <div class="row g-4 align-items-start">

            <!-- ── Main column ────────────────────────────────────────────── -->
            <div class="col-lg-8">

               <!-- ── Verification Form Card ───────────────────────────────── -->
               <div class="cv-card wow fadeInUp" data-wow-delay=".1s">

                  <?php if (!empty($form_errors)): ?>
                  <div class="cv-alert-danger">
                     <i class="fas fa-exclamation-triangle me-2"></i>
                     <?php foreach ($form_errors as $err): ?>
                        <?= fh($err) ?><br>
                     <?php endforeach; ?>
                  </div>
                  <?php endif; ?>

                  <form method="POST" action="" id="cvForm" class="cv-form" novalidate>
                     <input type="hidden" name="_csrf" value="<?= fh($csrf_token) ?>">

                     <!-- Step 1: Who is verifying -->
                     <div class="cv-section-label">Step 1</div>
                     <div class="cv-section-title">Who is verifying?</div>

                     <div class="cv-type-toggle" id="cvTypeToggle">
                        <label class="cv-type-btn <?= $fd['verifier_type'] === 'student' ? 'active' : '' ?>" id="btnStudent" for="vtStudent">
                           <div class="icon-wrap student"><i class="fas fa-user-graduate"></i></div>
                           <span>I&rsquo;m a Student</span>
                        </label>
                        <label class="cv-type-btn <?= $fd['verifier_type'] === 'company' ? 'active' : '' ?>" id="btnCompany" for="vtCompany">
                           <div class="icon-wrap company"><i class="fas fa-building"></i></div>
                           <span>I&rsquo;m a Company</span>
                        </label>
                     </div>
                     <input type="radio" name="verifier_type" id="vtStudent" value="student" class="d-none" <?= $fd['verifier_type'] === 'student' ? 'checked' : '' ?>>
                     <input type="radio" name="verifier_type" id="vtCompany" value="company" class="d-none" <?= $fd['verifier_type'] === 'company' ? 'checked' : '' ?>>

                     <!-- Student Fields -->
                     <div id="fieldsStudent" <?= $fd['verifier_type'] === 'student' ? '' : 'style="display:none;"' ?>>
                        <div class="row g-3">
                           <div class="col-md-12">
                              <label class="form-label">Your Full Name <span class="text-danger">*</span></label>
                              <input type="text" name="st_name" class="form-control"
                                     placeholder="Enter your full name"
                                     value="<?= fh($fd['st_name']) ?>">
                           </div>
                           <div class="col-sm-6">
                              <label class="form-label">Phone Number <span class="text-danger">*</span></label>
                              <input type="tel" name="st_phone" class="form-control"
                                     placeholder="e.g. 017XXXXXXXX"
                                     value="<?= fh($fd['st_phone']) ?>">
                           </div>
                           <div class="col-sm-6">
                              <label class="form-label">Email Address <span class="text-danger">*</span></label>
                              <input type="email" name="st_email" class="form-control"
                                     placeholder="your@email.com"
                                     value="<?= fh($fd['st_email']) ?>">
                           </div>
                        </div>
                     </div>

                     <!-- Company Fields -->
                     <div id="fieldsCompany" <?= $fd['verifier_type'] === 'company' ? '' : 'style="display:none;"' ?>>
                        <div class="row g-3">
                           <div class="col-md-6">
                              <label class="form-label">Company Name <span class="text-danger">*</span></label>
                              <input type="text" name="co_company_name" class="form-control"
                                     placeholder="Your company name"
                                     value="<?= fh($fd['co_company_name']) ?>">
                           </div>
                           <div class="col-md-6">
                              <label class="form-label">Company Address <span class="text-danger">*</span></label>
                              <input type="text" name="co_address" class="form-control"
                                     placeholder="Company address"
                                     value="<?= fh($fd['co_address']) ?>">
                           </div>
                           <div class="col-sm-6">
                              <label class="form-label">Your Name <span class="text-danger">*</span></label>
                              <input type="text" name="co_your_name" class="form-control"
                                     placeholder="Your full name"
                                     value="<?= fh($fd['co_your_name']) ?>">
                           </div>
                           <div class="col-sm-6">
                              <label class="form-label">Your Designation <span class="text-danger">*</span></label>
                              <input type="text" name="co_designation" class="form-control"
                                     placeholder="e.g. HR Manager"
                                     value="<?= fh($fd['co_designation']) ?>">
                           </div>
                           <div class="col-sm-6">
                              <label class="form-label">Email Address <span class="text-danger">*</span></label>
                              <input type="email" name="co_email" class="form-control"
                                     placeholder="company@example.com"
                                     value="<?= fh($fd['co_email']) ?>">
                           </div>
                           <div class="col-sm-6">
                              <label class="form-label">Phone Number <span class="text-danger">*</span></label>
                              <input type="tel" name="co_phone" class="form-control"
                                     placeholder="e.g. 017XXXXXXXX"
                                     value="<?= fh($fd['co_phone']) ?>">
                           </div>
                        </div>
                     </div>

                     <hr class="cv-divider">

                     <!-- Step 2: Student ID -->
                     <div class="cv-section-label">Step 2</div>
                     <div class="cv-section-title">Enter Student ID</div>

                     <div class="cv-sid-wrap">
                        <label for="studentIdInput">Student ID <span style="color:#dc2626;">*</span></label>
                        <div class="cv-sid-row">
                           <input type="text" name="student_id" id="studentIdInput"
                                  class="form-control"
                                  placeholder="e.g. 230101010001"
                                  value="<?= fh($fd['student_id']) ?>"
                                  maxlength="25"
                                  autocomplete="off"
                                  spellcheck="false">
                           <button type="submit" class="cv-verify-btn">
                              <i class="fas fa-search"></i>
                              <span>Verify</span>
                           </button>
                        </div>
                     </div>

                  </form>
               </div>
               <!-- ── Form Card End ────────────────────────────────────────── -->

               <!-- ── Result Section ───────────────────────────────────────── -->
               <?php if ($submitted): ?>

                  <?php if ($student): ?>
                  <!-- ── Student Found ─────────────────────────────────────── -->
                  <div class="cv-result wow fadeInUp" data-wow-delay=".05s" id="cvResult">

                     <!-- Verified banner -->
                     <div style="background:linear-gradient(90deg,#1a2e5a,#2563eb);border-radius:12px;padding:14px 20px;margin-bottom:28px;display:flex;align-items:center;gap:12px;">
                        <div style="width:42px;height:42px;border-radius:10px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:#fff;flex-shrink:0;">
                           <i class="fas fa-shield-alt"></i>
                        </div>
                        <div>
                           <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:rgba(255,255,255,.7);">Verification Result</div>
                           <div style="font-size:1rem;font-weight:800;color:#fff;">Record Found in Prime University Database</div>
                        </div>
                     </div>

                     <!-- Student header -->
                     <div class="cv-result-header">
                        <?php
                        $photo_url = $student['photo'] ? cert_photo_url($student['photo']) : '';
                        ?>
                        <?php if ($photo_url): ?>
                        <img src="<?= fh($photo_url) ?>"
                             alt="Photo of <?= fh($student['full_name']) ?>"
                             class="cv-student-photo">
                        <?php else: ?>
                        <div class="cv-student-photo-placeholder">
                           <i class="fas fa-user-graduate"></i>
                        </div>
                        <?php endif; ?>

                        <div>
                           <div class="cv-result-name"><?= fh($student['full_name']) ?></div>
                           <div class="cv-result-meta">
                              <i class="fas fa-id-card me-1 text-muted"></i>
                              <strong style="color:#1a2e5a;"><?= fh($student['student_id']) ?></strong>
                           </div>
                           <div class="cv-result-meta">
                              <i class="fas fa-university me-1 text-muted"></i>
                              <?= fh($student['dept_name']) ?>
                           </div>
                           <?php
                           $st_status = $student['status'] ?? '';
                           $badge_cls = match($st_status) {
                               'Graduated' => 'graduated',
                               'Active'    => 'active',
                               'Dropped'   => 'dropped',
                               default     => 'inactive',
                           };
                           $badge_icon = match($st_status) {
                               'Graduated' => 'fas fa-graduation-cap',
                               'Active'    => 'fas fa-check-circle',
                               'Dropped'   => 'fas fa-times-circle',
                               default     => 'fas fa-pause-circle',
                           };
                           ?>
                           <span class="cv-result-badge <?= $badge_cls ?>">
                              <i class="<?= $badge_icon ?>"></i>
                              <?= fh($st_status) ?>
                           </span>
                        </div>
                     </div>

                     <!-- Info grid -->
                     <div class="cv-info-grid">
                        <div class="cv-info-item">
                           <div class="label">Student ID</div>
                           <div class="value"><?= fh($student['student_id']) ?></div>
                        </div>
                        <div class="cv-info-item">
                           <div class="label">Full Name</div>
                           <div class="value"><?= fh($student['full_name']) ?></div>
                        </div>
                        <div class="cv-info-item">
                           <div class="label">Batch</div>
                           <div class="value"><?= $student['batch'] ? fh($student['batch']) : '—' ?></div>
                        </div>
                        <div class="cv-info-item">
                           <div class="label">Enrolled Semester</div>
                           <div class="value"><?= fh($student['admitted_semester']) ?></div>
                        </div>
                        <div class="cv-info-item">
                           <div class="label">Ending Semester</div>
                           <div class="value">
                              <?php
                              $ending = $result_info['ending_semester'] ?? null;
                              echo $ending ? fh($ending) : '—';
                              ?>
                           </div>
                        </div>
                        <div class="cv-info-item">
                           <div class="label">Department</div>
                           <div class="value"><?= fh($student['dept_name']) ?></div>
                        </div>
                        <div class="cv-info-item">
                           <div class="label">Obtained Degree</div>
                           <div class="value"><?= $student['program_name'] ? fh($student['program_name']) : '—' ?></div>
                        </div>
                        <div class="cv-info-item">
                           <div class="label">Graduated</div>
                           <div class="value">
                              <?php if ($st_status === 'Graduated'): ?>
                              <span style="color:#059669;font-weight:700;"><i class="fas fa-check me-1"></i>Yes</span>
                              <?php else: ?>
                              <span style="color:#6b7280;">No</span>
                              <?php endif; ?>
                           </div>
                        </div>
                        <div class="cv-info-item">
                           <div class="label">Result Publish Date</div>
                           <div class="value">
                              <?php
                              $pub_date = $result_info['publish_date'] ?? null;
                              echo $pub_date ? fh($pub_date) : '—';
                              ?>
                           </div>
                        </div>
                        <div class="cv-info-item">
                           <div class="label">Final CGPA</div>
                           <div class="value">
                              <?php
                              $final_cgpa = $result_info['final_cgpa'] ?? null;
                              if ($final_cgpa !== null): ?>
                              <strong style="color:#002147;font-size:1.05em;"><?= fh($final_cgpa) ?></strong>
                              <span style="color:#6b7280;font-size:.85em;">&nbsp;/ 4.00</span>
                              <?php else: echo '—'; endif; ?>
                           </div>
                        </div>
                     </div>

                     <!-- Print / Download PDF button -->
                     <div style="text-align:right;margin-bottom:16px;">
                        <a href="<?= fh(SITE_URL) ?>/result-print.php?sid=<?= urlencode($student['student_id']) ?>"
                           target="_blank"
                           rel="noopener noreferrer"
                           class="cv-print-btn">
                           <i class="fas fa-file-pdf"></i>
                           Print / Download PDF
                        </a>
                     </div>

                     <!-- Manual check note -->
                     <div class="cv-manual-note">
                        <i class="fas fa-envelope me-2"></i>
                        If you want us to do a manual check, please send an email to:
                        <a href="mailto:verification@primeuniversity.ac.bd">verification@primeuniversity.ac.bd</a>
                     </div>

                  </div>
                  <!-- ── Student Found End ─────────────────────────────────── -->

                  <?php else: ?>
                  <!-- ── Student Not Found ─────────────────────────────────── -->
                  <div class="cv-not-found wow fadeInUp" data-wow-delay=".05s" id="cvResult">
                     <div class="nf-icon">
                        <i class="fas fa-user-times"></i>
                     </div>
                     <h3>No Record Found</h3>
                     <p>
                        We could not find any student with the ID
                        <strong>&ldquo;<?= fh($fd['student_id']) ?>&rdquo;</strong>
                        in our database. Please double-check the ID and try again.
                     </p>
                     <p>
                        If you believe this is a mistake, please send us an email at:
                        <br>
                        <a href="mailto:verification@primeuniversity.ac.bd" style="font-size:1rem;">
                           <i class="fas fa-envelope me-1"></i>verification@primeuniversity.ac.bd
                        </a>
                     </p>
                  </div>
                  <!-- ── Student Not Found End ─────────────────────────────── -->
                  <?php endif; ?>

               <?php endif; ?>
               <!-- ── Result Section End ────────────────────────────────────── -->

            </div>
            <!-- ── Main column end ──────────────────────────────────────────── -->

            <!-- ── Sidebar ───────────────────────────────────────────────────── -->
            <div class="col-lg-4">
               <div class="cv-info-sidebar wow fadeInUp" data-wow-delay=".2s">
                  <h4><i class="fas fa-info-circle me-2"></i>About Verification</h4>

                  <div class="cv-sidebar-item">
                     <div class="si-icon"><i class="fas fa-shield-alt"></i></div>
                     <div class="si-text">
                        <strong>Official Service</strong>
                        This portal allows employers, institutions, and individuals to instantly verify Prime University credentials.
                     </div>
                  </div>

                  <div class="cv-sidebar-item">
                     <div class="si-icon"><i class="fas fa-id-card"></i></div>
                     <div class="si-text">
                        <strong>Where to Find the ID</strong>
                        The student ID is printed on the university ID card and all official academic documents.
                     </div>
                  </div>

                  <div class="cv-sidebar-item">
                     <div class="si-icon"><i class="fas fa-envelope"></i></div>
                     <div class="si-text">
                        <strong>Manual Verification</strong>
                        For a certified verification letter, email us at:<br>
                        <a href="mailto:verification@primeuniversity.ac.bd">verification@primeuniversity.ac.bd</a>
                     </div>
                  </div>

                  <div class="cv-sidebar-item">
                     <div class="si-icon"><i class="fas fa-clock"></i></div>
                     <div class="si-text">
                        <strong>Response Time</strong>
                        Manual requests are processed within 3–5 business days.
                     </div>
                  </div>

                  <div class="cv-sidebar-item">
                     <div class="si-icon"><i class="fas fa-lock"></i></div>
                     <div class="si-text">
                        <strong>Privacy &amp; Security</strong>
                        All verification requests are logged and kept confidential per our privacy policy.
                     </div>
                  </div>
               </div>
            </div>
            <!-- ── Sidebar end ───────────────────────────────────────────────── -->

         </div>
      </div>
   </section>

   <?php include __DIR__ . '/includes/footer.php'; ?>
   <?php include __DIR__ . '/includes/scripts.php'; ?>

   <script>
   (function () {
      // ── Verifier type toggle ────────────────────────────────────────────────
      const btnStudent     = document.getElementById('btnStudent');
      const btnCompany     = document.getElementById('btnCompany');
      const radioStudent   = document.getElementById('vtStudent');
      const radioCompany   = document.getElementById('vtCompany');
      const fieldsStudent  = document.getElementById('fieldsStudent');
      const fieldsCompany  = document.getElementById('fieldsCompany');

      function selectType(type) {
         if (type === 'student') {
            btnStudent.classList.add('active');
            btnCompany.classList.remove('active');
            radioStudent.checked = true;
            fieldsStudent.style.display = '';
            fieldsCompany.style.display = 'none';
         } else {
            btnCompany.classList.add('active');
            btnStudent.classList.remove('active');
            radioCompany.checked = true;
            fieldsCompany.style.display = '';
            fieldsStudent.style.display = 'none';
         }
      }

      btnStudent.addEventListener('click', function (e) { e.preventDefault(); selectType('student'); });
      btnCompany.addEventListener('click', function (e) { e.preventDefault(); selectType('company'); });

      // Client-side: require a type before submitting
      document.getElementById('cvForm').addEventListener('submit', function (e) {
         if (!radioStudent.checked && !radioCompany.checked) {
            e.preventDefault();
            btnStudent.scrollIntoView({ behavior: 'smooth', block: 'center' });
            btnStudent.style.outline = '3px solid #ef4444';
            btnCompany.style.outline = '3px solid #ef4444';
            setTimeout(function () {
               btnStudent.style.outline = '';
               btnCompany.style.outline = '';
            }, 2000);
         }
      });

      // Scroll to result after page reload
      <?php if ($submitted): ?>
      var result = document.getElementById('cvResult');
      if (result) {
         setTimeout(function () {
            result.scrollIntoView({ behavior: 'smooth', block: 'start' });
         }, 350);
      }
      <?php endif; ?>
   }());
   </script>

</body>
</html>
