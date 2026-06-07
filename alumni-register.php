<?php
require_once __DIR__ . '/includes/config.php';

$db           = front_db();
$departments  = [];
$success      = false;
$errors       = [];
$form_values  = [];

if ($db) {
    try {
        $departments = $db->query(
            'SELECT id, name FROM dept_departments WHERE is_active=1 ORDER BY name ASC'
        )->fetchAll();
    } catch (Throwable $e) {}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dept_id      = (int)($_POST['dept_id']     ?? 0);
    $name         = trim($_POST['name']         ?? '');
    $batch        = trim($_POST['batch']        ?? '');
    $company      = trim($_POST['company']      ?? '');
    $position     = trim($_POST['position']     ?? '');
    $linkedin_url = trim($_POST['linkedin_url'] ?? '');
    $fb_url       = trim($_POST['fb_url']       ?? '');

    $form_values = compact('dept_id','name','batch','company','position','linkedin_url','fb_url');

    // Basic validation
    if ($name === '')         $errors[] = 'Full name is required.';
    if (mb_strlen($name) > 200) $errors[] = 'Name must be 200 characters or fewer.';
    if ($linkedin_url !== '' && !filter_var($linkedin_url, FILTER_VALIDATE_URL))
        $errors[] = 'LinkedIn URL must be a valid URL (include https://).';
    if ($fb_url !== '' && !filter_var($fb_url, FILTER_VALIDATE_URL))
        $errors[] = 'Facebook URL must be a valid URL (include https://).';

    // Photo upload
    $photo = null;
    if (!empty($_FILES['photo']['name'])) {
        $f = $_FILES['photo'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Photo upload failed.';
        } else {
            if ($f['size'] > 5 * 1024 * 1024) {
                $errors[] = 'Photo must be 5 MB or smaller.';
            } else {
                $ext  = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                $exts  = ['jpg','jpeg','png','gif','webp'];
                $mimes = ['image/jpeg','image/png','image/gif','image/webp'];
                if (!in_array($ext, $exts, true)) {
                    $errors[] = 'Photo: unsupported format. Allowed: JPG, PNG, GIF, WebP.';
                } else {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime  = $finfo->file($f['tmp_name']);
                    if (!in_array($mime, $mimes, true)) {
                        $errors[] = 'Photo: file type not allowed.';
                    } else {
                        // Upload to admin/uploads/alumni/
                        $dir = __DIR__ . '/admin/uploads/alumni';
                        if (!is_dir($dir)) @mkdir($dir, 0755, true);
                        $photo = bin2hex(random_bytes(12)) . '.' . $ext;
                        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $photo)) {
                            $errors[] = 'Could not save photo. Please try again.';
                            $photo = null;
                        }
                    }
                }
            }
        }
    }

    if (empty($errors) && $db) {
        try {
            $db->prepare(
                'INSERT INTO alumni (dept_id, name, batch, company, position, linkedin_url, fb_url, photo, status, is_active)
                 VALUES (?,?,?,?,?,?,?,?,\'pending\',0)'
            )->execute([
                $dept_id ?: null, $name, $batch ?: null, $company ?: null,
                $position ?: null, $linkedin_url ?: null, $fb_url ?: null, $photo
            ]);
            $success    = true;
            $form_values = [];
        } catch (Throwable $e) {
            $errors[] = 'Could not submit your profile. Please try again.';
        }
    }
}
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <meta name="viewport" content="width=device-width, initial-scale=1">
   <title>Alumni Registration – Prime University</title>
   <meta name="description" content="Register as a Prime University alumnus and join our growing alumni network.">
   <link rel="shortcut icon" type="image/x-icon" href="/assets/img/logo/favicon.png">
   <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
   <link rel="stylesheet" href="/assets/css/font-awesome-pro.css">
   <link rel="stylesheet" href="/assets/css/custom-animation.css">
   <link rel="stylesheet" href="/assets/css/spacing.css">
   <link rel="stylesheet" href="/assets/css/main.css">
   <style>
      .reg-hero {
         background: linear-gradient(135deg, #002147 0%, #003d82 60%, #D21034 100%);
         padding: 80px 0 60px;
      }
      .reg-card {
         background: #fff;
         border-radius: 20px;
         box-shadow: 0 20px 60px rgba(0,33,71,0.12);
         overflow: hidden;
      }
      .reg-card .form-control, .reg-card .form-select {
         border-radius: 10px;
         padding: 12px 16px;
         border: 1.5px solid #e0e6ef;
         font-size: .95rem;
         transition: border-color .2s;
      }
      .reg-card .form-control:focus, .reg-card .form-select:focus {
         border-color: #002147;
         box-shadow: 0 0 0 3px rgba(0,33,71,0.08);
      }
      .reg-card label { font-weight: 600; color: #002147; margin-bottom: 6px; font-size: .88rem; }
      .reg-card .form-text { color: #8898aa; font-size: .8rem; }
      .btn-submit {
         background: linear-gradient(135deg, #002147, #003d82);
         color: #fff;
         border: none;
         border-radius: 12px;
         padding: 14px 40px;
         font-size: 1rem;
         font-weight: 600;
         letter-spacing: .5px;
         transition: transform .2s, box-shadow .2s;
      }
      .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,33,71,0.25); color:#fff; }
      .photo-preview { width:110px;height:110px;border-radius:50%;object-fit:cover;border:4px solid #002147; }
      .steps-bar { display:flex; gap:0; margin-bottom:36px; }
      .steps-bar .step { flex:1;text-align:center;font-size:.8rem;color:#8898aa;font-weight:500;position:relative; }
      .steps-bar .step.active { color:#002147; font-weight:700; }
      .steps-bar .step::before {
         content:'';display:block;width:32px;height:32px;border-radius:50%;
         background:#e0e6ef;margin:0 auto 6px;
         font-size:.9rem;line-height:32px;font-weight:700;color:#8898aa;
      }
      .steps-bar .step.active::before { background:#002147;color:#fff; }
      .steps-bar .step.done::before { background:#27ae60;color:#fff; }
      .success-card { text-align:center;padding:60px 30px; }
      .success-card .success-icon { width:90px;height:90px;border-radius:50%;background:#e8f8ef;
         display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:2.5rem;color:#27ae60; }
   </style>
<?php include __DIR__ . '/includes/meta-pixel.php'; ?>
</head>
<body id="body" class="it-magic-cursor">

   <div id="preloader"><div class="preloader"><span></span><span></span></div></div>
   <div id="magic-cursor"><div id="ball"></div></div>
   <button class="scroll-top scroll-to-target" data-target="html"><i class="far fa-angle-double-up"></i></button>

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

   <main>

   <!-- Hero Banner -->
   <div class="reg-hero">
      <div class="container">
         <div class="row">
            <div class="col-12 text-center">
               <nav aria-label="breadcrumb" class="mb-20">
                  <ol class="breadcrumb justify-content-center" style="background:transparent;padding:0;margin:0;">
                     <li class="breadcrumb-item"><a href="<?= fh(SITE_URL) ?>/index.php" style="color:#FFB81C;">Home</a></li>
                     <li class="breadcrumb-item"><a href="<?= fh(SITE_URL) ?>/alumni.php" style="color:#E8EEF4;">Alumni</a></li>
                     <li class="breadcrumb-item active" style="color:#fff;">Registration</li>
                  </ol>
               </nav>
               <h2 style="color:#fff;font-weight:700;margin-bottom:12px;" class="wow fadeInUp" data-wow-delay=".2s">
                  Alumni Registration
               </h2>
               <p style="color:rgba(255,255,255,.8);font-size:1.05rem;max-width:560px;margin:0 auto;" class="wow fadeInUp" data-wow-delay=".3s">
                  Join the Prime University alumni network. Your profile will appear after admin review.
               </p>
            </div>
         </div>
      </div>
   </div>

   <!-- Form Section -->
   <section style="background:#f4f6fb;padding:80px 0 100px;">
      <div class="container">
         <div class="row justify-content-center">
            <div class="col-lg-8">

               <?php if ($success): ?>
               <!-- Success state -->
               <div class="reg-card wow fadeInUp" data-wow-delay=".1s">
                  <div class="success-card">
                     <div class="success-icon"><i class="fas fa-check"></i></div>
                     <h3 style="color:#002147;font-weight:700;margin-bottom:12px;">Registration Submitted!</h3>
                     <p style="color:#5a6a85;font-size:1.05rem;max-width:480px;margin:0 auto 28px;">
                        Thank you, your alumni profile has been submitted for review. Our team will approve it shortly and your profile will be visible on the alumni page.
                     </p>
                     <div class="d-flex gap-3 justify-content-center flex-wrap">
                        <a href="<?= fh(SITE_URL) ?>/alumni.php" class="btn btn-submit">
                           <i class="fas fa-users me-2"></i> View Alumni
                        </a>
                        <a href="<?= fh(SITE_URL) ?>/alumni-register.php" class="btn btn-outline-secondary" style="border-radius:12px;padding:14px 30px;font-weight:600;">
                           Register Another
                        </a>
                     </div>
                  </div>
               </div>
               <?php else: ?>
               <!-- Registration form -->
               <div class="reg-card wow fadeInUp" data-wow-delay=".1s">
                  <div style="background:#002147;padding:28px 36px;">
                     <h5 style="color:#fff;margin:0;font-weight:600;"><i class="fas fa-user-graduate me-2" style="color:#FFB81C;"></i> Alumni Profile Form</h5>
                     <p style="color:rgba(255,255,255,.65);margin:4px 0 0;font-size:.875rem;">All approved profiles appear on the alumni directory.</p>
                  </div>

                  <div class="p-4 p-md-5">
                     <?php if (!empty($errors)): ?>
                     <div class="alert alert-danger border-0 rounded-3 mb-4">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <strong>Please fix the following:</strong>
                        <ul class="mb-0 mt-2 ps-3">
                           <?php foreach ($errors as $e): ?><li><?= fh($e) ?></li><?php endforeach; ?>
                        </ul>
                     </div>
                     <?php endif; ?>

                     <form method="POST" enctype="multipart/form-data" novalidate>

                        <!-- Photo upload row -->
                        <div class="text-center mb-4">
                           <div class="position-relative d-inline-block">
                              <img id="photoPreview" src="/assets/img/logo/logo-black.png" alt=""
                                   class="photo-preview" style="opacity:.25;" onerror="this.style.opacity='.15'">
                              <label for="photoInput" style="position:absolute;bottom:0;right:0;width:34px;height:34px;
                                 border-radius:50%;background:#002147;color:#fff;cursor:pointer;
                                 display:flex;align-items:center;justify-content:center;border:3px solid #fff;">
                                 <i class="fas fa-camera" style="font-size:.8rem;"></i>
                              </label>
                           </div>
                           <input type="file" name="photo" id="photoInput" class="d-none"
                                  accept=".jpg,.jpeg,.png,.gif,.webp">
                           <p class="form-text mt-2">Click camera icon to upload your photo<br>(JPG/PNG, max 5 MB, square preferred)</p>
                        </div>

                        <div class="row g-4">
                           <div class="col-md-6">
                              <label>Full Name <span class="text-danger">*</span></label>
                              <input type="text" name="name" class="form-control" required maxlength="200"
                                     placeholder="e.g. Md. Rahim Uddin"
                                     value="<?= fh($form_values['name'] ?? '') ?>">
                           </div>
                           <div class="col-md-6">
                              <label>Department</label>
                              <select name="dept_id" class="form-select">
                                 <option value="0">— Select Your Department —</option>
                                 <?php foreach ($departments as $d): ?>
                                 <option value="<?= $d['id'] ?>"
                                    <?= ($form_values['dept_id'] ?? 0) == $d['id'] ? 'selected' : '' ?>>
                                    <?= fh($d['name']) ?>
                                 </option>
                                 <?php endforeach; ?>
                              </select>
                           </div>
                           <div class="col-md-6">
                              <label>Batch</label>
                              <input type="text" name="batch" class="form-control" maxlength="100"
                                     placeholder="e.g. 26th or Spring 2018"
                                     value="<?= fh($form_values['batch'] ?? '') ?>">
                           </div>
                           <div class="col-md-6">
                              <label>Current Company / Organisation</label>
                              <input type="text" name="company" class="form-control" maxlength="200"
                                     placeholder="e.g. BRAC Bank Limited"
                                     value="<?= fh($form_values['company'] ?? '') ?>">
                           </div>
                           <div class="col-12">
                              <label>Role / Position</label>
                              <input type="text" name="position" class="form-control" maxlength="200"
                                     placeholder="e.g. Senior Software Engineer"
                                     value="<?= fh($form_values['position'] ?? '') ?>">
                           </div>
                           <div class="col-md-6">
                              <label><i class="fab fa-linkedin text-primary me-1"></i> LinkedIn Profile URL</label>
                              <input type="url" name="linkedin_url" class="form-control" maxlength="500"
                                     placeholder="https://linkedin.com/in/your-profile"
                                     value="<?= fh($form_values['linkedin_url'] ?? '') ?>">
                           </div>
                           <div class="col-md-6">
                              <label><i class="fab fa-facebook text-primary me-1"></i> Facebook Profile URL</label>
                              <input type="url" name="fb_url" class="form-control" maxlength="500"
                                     placeholder="https://facebook.com/your.profile"
                                     value="<?= fh($form_values['fb_url'] ?? '') ?>">
                           </div>
                        </div>

                        <div class="d-flex gap-3 align-items-center mt-5 flex-wrap">
                           <button type="submit" class="btn btn-submit">
                              <i class="fas fa-paper-plane me-2"></i> Submit for Approval
                           </button>
                           <a href="<?= fh(SITE_URL) ?>/alumni.php" style="color:#5a6a85;font-weight:500;">
                              <i class="fas fa-arrow-left me-1"></i> Back to Alumni
                           </a>
                        </div>

                        <p class="form-text mt-3">
                           <i class="fas fa-info-circle me-1"></i>
                           Your profile will be reviewed by our team before appearing on the alumni directory.
                        </p>
                     </form>
                  </div>
               </div>
               <?php endif; ?>

            </div>
         </div>
      </div>
   </section>

   </main>

<?php include __DIR__ . '/includes/footer.php'; ?>
<?php include __DIR__ . '/includes/scripts.php'; ?>
<script>
document.getElementById('photoInput').addEventListener('change', function () {
   var preview = document.getElementById('photoPreview');
   if (this.files && this.files[0]) {
      var reader = new FileReader();
      reader.onload = function (e) { preview.src = e.target.result; preview.style.opacity = '1'; };
      reader.readAsDataURL(this.files[0]);
   }
});
</script>
</body>
</html>
