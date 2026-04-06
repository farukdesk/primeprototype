<?php
/**
 * Public Faculty Registration Page
 * Collects applicant info and queues it for admin approval.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/includes/config.php';

$page_title = 'Faculty Registration – Prime University';

// ── CSRF ──────────────────────────────────────────────────────────────────────
if (empty($_SESSION['fr_pub_csrf'])) {
    $_SESSION['fr_pub_csrf'] = bin2hex(random_bytes(32));
}
$pub_csrf = $_SESSION['fr_pub_csrf'];

// ── Helpers ───────────────────────────────────────────────────────────────────
function fr_h(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fr_send_mail(string $to, string $to_name, string $subject, string $body): void {
    $from  = 'noreply@primeuniversity.ac.bd';
    $fname = '=?UTF-8?B?' . base64_encode('Prime University') . '?=';
    $headers  = 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
    $headers .= 'From: ' . $fname . ' <' . $from . '>' . "\r\n";
    $headers .= 'Reply-To: ' . $from . "\r\n";
    @mail($to, $subject, $body, $headers, '-f' . $from);
}

// Fetch email template from DB and send
function fr_send_template(string $action, string $to_email, string $to_name, array $vars = []): void {
    $db = front_db();
    if (!$db) return;
    try {
        $stmt = $db->prepare('SELECT subject, body_html FROM email_templates WHERE action = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$action]);
        $tpl = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tpl) return;
        $vars['app_name']  = 'Prime University';
        $vars['logo_url']  = SITE_URL . '/assets/img/logo/logo-black.png';
        $subject  = $tpl['subject'];
        $body     = $tpl['body_html'];
        foreach ($vars as $k => $v) {
            $subject = str_replace('{{' . $k . '}}', fr_h((string)$v), $subject);
            $body    = str_replace('{{' . $k . '}}', fr_h((string)$v), $body);
        }
        // Remove unused mustache tags
        $subject = preg_replace('/\{\{[^}]+\}\}/', '', $subject);
        $body    = preg_replace('/\{\{[^}]+\}\}/', '', $body);
        fr_send_mail($to_email, $to_name, $subject, $body);
    } catch (Throwable $e) { /* silent */ }
}

// ── Load departments ──────────────────────────────────────────────────────────
$departments = [];
try {
    $db = front_db();
    if ($db) {
        $departments = $db->query(
            'SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) { /* silent */ }

// ── Allowed ID card file types ────────────────────────────────────────────────
$allowed_exts  = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
$allowed_mimes = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'application/pdf',
];
$max_size = 10 * 1024 * 1024; // 10 MB

// ── Handle form submission ────────────────────────────────────────────────────
$form_errors  = [];
$form_success = false;
$old          = ['full_name' => '', 'email' => '', 'phone' => '', 'dept_id' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'faculty_register') {
    if (!hash_equals($pub_csrf, $_POST['_csrf'] ?? '')) {
        $form_errors[] = 'Security token mismatch. Please refresh and try again.';
    } else {
        $full_name = trim($_POST['full_name'] ?? '');
        $email     = trim($_POST['email']     ?? '');
        $phone     = trim($_POST['phone']     ?? '');
        $dept_id   = (int)($_POST['dept_id']  ?? 0) ?: null;

        $old = compact('full_name', 'email', 'phone', 'dept_id');

        if ($full_name === '')                               $form_errors[] = 'Full name is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))     $form_errors[] = 'A valid email address is required.';
        if ($phone === '')                                   $form_errors[] = 'Phone number is required.';
        if (!$dept_id)                                      $form_errors[] = 'Please select a department.';

        // Duplicate email / phone check
        if (empty($form_errors)) {
            $db = front_db();
            if ($db) {
                $already_registered = false;

                // Check approved users by email or phone
                $dupUser = $db->prepare('SELECT id FROM users WHERE email = ? OR phone = ? LIMIT 1');
                $dupUser->execute([$email, $phone]);
                if ($dupUser->fetchColumn()) {
                    $already_registered = true;
                }

                // Check pending registrations by email or phone
                if (!$already_registered) {
                    $dupReg = $db->prepare("SELECT id FROM faculty_registrations WHERE (email = ? OR phone = ?) AND status = 'pending' LIMIT 1");
                    $dupReg->execute([$email, $phone]);
                    if ($dupReg->fetchColumn()) {
                        $already_registered = true;
                    }
                }

                if ($already_registered) {
                    $form_errors[] = '__already_registered__';
                }
            }
        }

        // ID card upload (optional but recommended)
        $id_card_stored   = null;
        $id_card_original = null;
        $id_card_mime     = null;
        $id_card_size     = 0;

        if (!empty($_FILES['id_card']['name'])) {
            $file = $_FILES['id_card'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $form_errors[] = 'File upload error. Please try again.';
            } elseif ($file['size'] > $max_size) {
                $form_errors[] = 'ID card file must be 10 MB or smaller.';
            } else {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed_exts, true)) {
                    $form_errors[] = 'Invalid file type. Allowed: JPG, PNG, PDF.';
                } else {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime  = $finfo->file($file['tmp_name']);
                    if (!in_array($mime, $allowed_mimes, true)) {
                        $form_errors[] = 'Invalid file content. Allowed: JPG, PNG, PDF.';
                    } else {
                        $upload_dir = __DIR__ . '/admin/uploads/faculty-registrations';
                        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                        $stored = bin2hex(random_bytes(12)) . '.' . $ext;
                        if (!move_uploaded_file($file['tmp_name'], $upload_dir . '/' . $stored)) {
                            $form_errors[] = 'Could not save uploaded file. Please try again.';
                        } else {
                            $id_card_stored   = $stored;
                            $id_card_original = $file['name'];
                            $id_card_mime     = $mime;
                            $id_card_size     = $file['size'];
                        }
                    }
                }
            }
        }

        if (empty($form_errors)) {
            try {
                $db = front_db();
                $db->prepare(
                    'INSERT INTO faculty_registrations
                       (full_name, email, phone, dept_id,
                        id_card_stored, id_card_original, id_card_mime, id_card_size)
                     VALUES (?,?,?,?,?,?,?,?)'
                )->execute([
                    $full_name, $email, $phone, $dept_id,
                    $id_card_stored, $id_card_original, $id_card_mime, $id_card_size,
                ]);

                // Fetch department name for notification
                $dept_name = '—';
                if ($dept_id) {
                    $ds = $db->prepare('SELECT name FROM dept_departments WHERE id = ? LIMIT 1');
                    $ds->execute([$dept_id]);
                    $dept_name = $ds->fetchColumn() ?: '—';
                }

                // Email to applicant
                fr_send_template('faculty_registration_received', $email, $full_name, [
                    'full_name' => $full_name,
                ]);

                // Email to admin
                $admin_url = SITE_URL . '/admin/faculty-profiles/pending.php';
                fr_send_template('faculty_registration_admin_notify', 'noreply@primeuniversity.ac.bd', 'Register Office', [
                    'full_name'       => $full_name,
                    'applicant_email' => $email,
                    'phone'           => $phone,
                    'department'      => $dept_name,
                    'admin_url'       => $admin_url,
                ]);

                $form_success = true;
                $old = ['full_name' => '', 'email' => '', 'phone' => '', 'dept_id' => ''];
                $_SESSION['fr_pub_csrf'] = bin2hex(random_bytes(32));
                $pub_csrf = $_SESSION['fr_pub_csrf'];
            } catch (Throwable $e) {
                $form_errors[] = 'Something went wrong. Please try again later.';
            }
        }
    }
}
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title><?= fr_h($page_title) ?></title>
   <meta name="description" content="Apply to join the faculty at Prime University. Submit your registration for review.">
   <meta name="viewport" content="width=device-width, initial-scale=1">
   <link rel="shortcut icon" type="image/x-icon" href="/assets/img/logo/favicon.png">
   <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
   <link rel="stylesheet" href="/assets/css/font-awesome-pro.css">
   <link rel="stylesheet" href="/assets/css/custom-animation.css">
   <link rel="stylesheet" href="/assets/css/spacing.css">
   <link rel="stylesheet" href="/assets/css/main.css">
   <style>
   .pu-reg-hero {
      background: linear-gradient(135deg, #1a2e5a 0%, #2563eb 100%);
      padding: 80px 0 70px;
      position: relative;
      overflow: hidden;
   }
   .pu-reg-hero::before {
      content: '';
      position: absolute;
      inset: 0;
      background: url('/assets/img/shape/breadcrumb-1-bg.png') center/cover no-repeat;
      opacity: .07;
   }
   .pu-reg-hero h1 { font-size: clamp(1.8rem,4vw,2.6rem); font-weight: 800; color: #fff; margin-bottom: 10px; }
   .pu-reg-hero .tagline { color: rgba(255,255,255,.82); font-size: 1rem; }
   .pu-reg-hero .breadcrumb-nav a, .pu-reg-hero .breadcrumb-nav span { color: rgba(255,255,255,.7); font-size: .85rem; }
   .pu-reg-hero .breadcrumb-nav a:hover { color: #fff; }
   .pu-reg-hero .sep { margin: 0 8px; color: rgba(255,255,255,.4); }

   .reg-card {
      background: #fff;
      border-radius: 18px;
      box-shadow: 0 8px 40px rgba(0,0,0,.10);
      padding: 40px 48px;
      margin-top: -40px;
      position: relative;
      z-index: 2;
   }
   @media(max-width:576px) { .reg-card { padding: 28px 18px; } }
   .reg-section-title {
      font-size: 1.35rem;
      font-weight: 700;
      color: #1a2e5a;
      margin-bottom: 6px;
   }
   .reg-required::after { content: ' *'; color: #dc3545; }
   .form-label { font-weight: 500; color: #374151; font-size: .93rem; }
   .form-control, .form-select {
      border-radius: 10px;
      border: 1.5px solid #e2e8f0;
      font-size: .95rem;
      transition: border-color .2s, box-shadow .2s;
   }
   .form-control:focus, .form-select:focus {
      border-color: #4f8ef7;
      box-shadow: 0 0 0 3px rgba(79,142,247,.12);
   }
   .btn-register {
      background: linear-gradient(135deg, #4f8ef7 0%, #2563eb 100%);
      color: #fff;
      font-weight: 700;
      font-size: 1rem;
      padding: 13px 40px;
      border-radius: 50px;
      border: none;
      transition: opacity .2s, transform .1s;
   }
   .btn-register:hover { opacity: .9; transform: translateY(-1px); color: #fff; }
   .note-box {
      background: #f0f6ff;
      border: 1px solid #bfdbfe;
      border-radius: 10px;
      padding: 14px 18px;
      font-size: .88rem;
      color: #1e40af;
   }
   .upload-hint { font-size: .8rem; color: #6b7280; margin-top: 4px; }
   </style>
<?php include __DIR__ . '/includes/meta-pixel.php'; ?>
</head>
<body>
<?php
require_once __DIR__ . '/includes/nav-menu.php';
?>

<!-- Hero -->
<section class="pu-reg-hero">
   <div class="container">
      <nav class="breadcrumb-nav mb-3">
         <a href="/">Home</a>
         <span class="sep">/</span>
         <span>Faculty Registration</span>
      </nav>
      <h1>Faculty Registration</h1>
      <p class="tagline">Submit your application to join the Prime University faculty. Your registration will be reviewed by the administration.</p>
   </div>
</section>

<!-- Form Section -->
<section class="pt-0 pb-80">
   <div class="container">
      <div class="row justify-content-center">
         <div class="col-lg-8 col-xl-7">

            <div class="reg-card">

               <?php if ($form_success): ?>
               <!-- Success message -->
               <div class="text-center py-4">
                  <div style="width:80px;height:80px;background:#d1fae5;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
                     <i class="fas fa-check-circle" style="font-size:2.5rem;color:#10b981;"></i>
                  </div>
                  <h3 style="font-weight:700;color:#1a2e5a;margin-bottom:10px;">Registration Submitted!</h3>
                  <p class="text-muted" style="font-size:.97rem;max-width:440px;margin:0 auto 20px;">
                     Your faculty registration has been received and is <strong>pending approval</strong>.
                     You will receive a confirmation email shortly. Once reviewed, you will be notified at <strong><?= fr_h($email ?? '') ?></strong>.
                  </p>
                  <a href="/" class="btn-register btn mt-2" style="text-decoration:none;">Back to Home</a>
               </div>

               <?php else: ?>

               <div class="mb-4">
                  <p class="reg-section-title"><i class="fas fa-user-tie me-2 text-primary"></i>Faculty Application Form</p>
                  <p class="text-muted" style="font-size:.9rem;">Fields marked with <span style="color:#dc3545;">*</span> are required.</p>
               </div>

               <?php if ($form_errors): ?>
               <?php if (in_array('__already_registered__', $form_errors, true)): ?>
               <div class="alert alert-warning mb-4" style="border-radius:10px;">
                  <p class="mb-2 fw-semibold"><i class="fas fa-exclamation-circle me-2"></i>You are already registered.</p>
                  <p class="mb-1" style="font-size:.92rem;">It looks like your email address or phone number already exists in our system.</p>
                  <ul class="mb-0 ps-3" style="font-size:.92rem;">
                     <li>If you forgot your password, please <a href="/admin/forgot-password.php" class="alert-link">reset it here</a>.</li>
                     <li>If you are totally unable to login, please <a href="/support-ticket.php" class="alert-link">create an IT support ticket here</a>.</li>
                  </ul>
               </div>
               <?php else: ?>
               <div class="alert alert-danger mb-4" style="border-radius:10px;">
                  <ul class="mb-0 ps-3">
                  <?php foreach ($form_errors as $e): ?>
                     <li><?= fr_h($e) ?></li>
                  <?php endforeach; ?>
                  </ul>
               </div>
               <?php endif; ?>
               <?php endif; ?>

               <div class="note-box mb-4">
                  <i class="fas fa-info-circle me-2"></i>
                  After submission your registration will be reviewed by the Register Office. Upon approval,
                  your account credentials will be sent to the email address you provide.
               </div>

               <form method="POST" enctype="multipart/form-data" novalidate>
                  <input type="hidden" name="_action" value="faculty_register">
                  <input type="hidden" name="_csrf"   value="<?= fr_h($pub_csrf) ?>">

                  <!-- Personal Information -->
                  <div class="row g-3">

                     <!-- Full Name -->
                     <div class="col-12">
                        <label class="form-label reg-required">Full Name</label>
                        <input type="text" name="full_name" class="form-control"
                               placeholder="Your full name as per official documents"
                               value="<?= fr_h($old['full_name']) ?>" required maxlength="150">
                     </div>

                     <!-- Email -->
                     <div class="col-md-6">
                        <label class="form-label reg-required">Email Address</label>
                        <input type="email" name="email" class="form-control"
                               placeholder="you@example.com"
                               value="<?= fr_h($old['email']) ?>" required maxlength="191">
                        <div class="upload-hint">Your login credentials will be sent to this address.</div>
                     </div>

                     <!-- Phone -->
                     <div class="col-md-6">
                        <label class="form-label reg-required">Phone Number</label>
                        <input type="text" name="phone" class="form-control"
                               placeholder="+880 1XXX-XXXXXX"
                               value="<?= fr_h($old['phone']) ?>" required maxlength="30">
                     </div>

                     <!-- Department -->
                     <div class="col-12">
                        <label class="form-label reg-required">Department</label>
                        <select name="dept_id" class="form-select" required>
                           <option value="">— Select your department —</option>
                           <?php foreach ($departments as $dept): ?>
                           <option value="<?= (int)$dept['id'] ?>"
                              <?= (string)($old['dept_id'] ?? '') === (string)$dept['id'] ? 'selected' : '' ?>>
                              <?= fr_h($dept['name']) ?>
                           </option>
                           <?php endforeach; ?>
                        </select>
                     </div>

                     <!-- User Group (hidden, auto-set to Faculty) -->
                     <input type="hidden" name="user_group" value="Faculty">

                     <!-- ID Card / Joining Letter -->
                     <div class="col-12">
                        <label class="form-label">Upload ID Card or Joining Letter <span style="color:#6b7280;font-weight:400;">(Optional)</span></label>
                        <input type="file" name="id_card" class="form-control"
                               accept=".jpg,.jpeg,.png,.gif,.webp,.pdf">
                        <div class="upload-hint">
                           <i class="fas fa-paperclip me-1"></i>
                           Accepted: JPG, PNG, PDF &mdash; max 10 MB. This document will be securely stored in your faculty file.
                        </div>
                     </div>

                  </div><!-- /row -->

                  <hr class="my-4">

                  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                     <a href="/" style="color:#6b7280;font-size:.9rem;text-decoration:none;">
                        <i class="fas fa-arrow-left me-1"></i>Back to Home
                     </a>
                     <button type="submit" class="btn-register btn">
                        <i class="fas fa-paper-plane me-2"></i>Submit Registration
                     </button>
                  </div>

               </form>
               <?php endif; ?>

            </div><!-- /reg-card -->
         </div>
      </div>
   </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<!-- JS Here -->
<script src="/assets/js/jquery.min.js"></script>
<script src="/assets/js/bootstrap.bundle.min.js"></script>
<script src="/assets/js/main.js"></script>
</body>
</html>
