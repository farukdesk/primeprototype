<?php
require_once __DIR__ . '/includes/config.php';

$user_id = (int)($_GET['id'] ?? 0);
if (!$user_id) { header('Location: index.php'); exit; }

$db       = front_db();
$user     = null;
$profile  = [];
$dept_info = null;

if ($db) {
    try {
        $st = $db->prepare('SELECT id, full_name, email, phone FROM users WHERE id = ? AND is_active = 1');
        $st->execute([$user_id]);
        $user = $st->fetch() ?: null;
    } catch (Throwable $e) {}

    if ($user) {
        try {
            $st = $db->prepare('SELECT * FROM faculty_profiles WHERE user_id = ?');
            $st->execute([$user_id]);
            $profile = $st->fetch() ?: [];
        } catch (Throwable $e) { $profile = []; }

        try {
            $st = $db->prepare(
                'SELECT df.*, dd.name AS dept_name, dd.slug AS dept_slug
                 FROM dept_faculty df
                 JOIN dept_departments dd ON dd.id = df.dept_id
                 WHERE df.user_id = ? AND df.is_active = 1 LIMIT 1'
            );
            $st->execute([$user_id]);
            $dept_info = $st->fetch() ?: null;
        } catch (Throwable $e) { $dept_info = null; }
    }
}

if (!$user) { header('Location: index.php'); exit; }

$name        = $user['full_name'] ?? '';
$designation = $profile['designation'] ?? $dept_info['designation'] ?? '';
$dept_name   = $dept_info['dept_name'] ?? '';
$dept_slug   = $dept_info['dept_slug'] ?? '';

$photo_url = null;
if (!empty($profile['photo'])) {
    $photo_url = ADMIN_UPLOAD_URL . '/faculty-profiles/' . $profile['photo'];
} elseif (!empty($dept_info['photo'])) {
    $photo_url = ADMIN_UPLOAD_URL . '/departments/' . $dept_info['photo'];
}

$cv_url = !empty($profile['cv_file']) ? ADMIN_UPLOAD_URL . '/faculty-profiles/' . $profile['cv_file'] : null;
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title><?= fh($name) ?><?= $designation ? ' – ' . fh($designation) : '' ?> – Prime University</title>
   <meta name="description" content="Faculty profile of <?= fh($name) ?> at Prime University.">
   <meta name="viewport" content="width=device-width, initial-scale=1">
   <link rel="shortcut icon" type="image/x-icon" href="assets/img/logo/favicon.png">
   <link rel="stylesheet" href="assets/css/bootstrap.min.css">
   <link rel="stylesheet" href="assets/css/font-awesome-pro.css">
   <link rel="stylesheet" href="assets/css/swiper-bundle.min.css">
   <link rel="stylesheet" href="assets/css/slick.css">
   <link rel="stylesheet" href="assets/css/magnific-popup.css">
   <link rel="stylesheet" href="assets/css/nice-select.css">
   <link rel="stylesheet" href="assets/css/custom-animation.css">
   <link rel="stylesheet" href="assets/css/spacing.css">
   <link rel="stylesheet" href="assets/css/main.css">
   <style>
      .fp-sidebar-card { background:#fff; border-radius:16px; box-shadow:0 4px 24px rgba(0,33,71,0.09); overflow:hidden; }
      .fp-section { background:#fff; border-radius:12px; box-shadow:0 2px 12px rgba(0,33,71,0.06); padding:28px 32px; margin-bottom:24px; }
      .fp-section-title { color:#002147; font-weight:700; font-size:1.05rem; border-left:4px solid #D21034; padding-left:12px; margin-bottom:16px; }
      .fp-info-row { display:flex; align-items:flex-start; gap:12px; margin-bottom:12px; font-size:14px; color:#334155; }
      .fp-info-row i { color:#D21034; width:18px; margin-top:3px; flex-shrink:0; }
      .fp-badge { display:inline-block; background:#002147; color:#FFB81C; font-size:11px; font-weight:700; padding:3px 10px; border-radius:20px; letter-spacing:.04em; margin-bottom:8px; }
      .fp-online-link { display:inline-flex; align-items:center; gap:8px; color:#002147; font-size:14px; text-decoration:none; padding:6px 14px; border:1px solid #e2e8f0; border-radius:8px; margin:4px; transition:all .2s; }
      .fp-online-link:hover { background:#002147; color:#FFB81C; border-color:#002147; }
      a.fp-social-link { display:inline-flex; align-items:center; gap:6px; color:#002147; font-size:13px; text-decoration:none; padding:4px 12px; border:1px solid #e2e8f0; border-radius:6px; margin:3px; transition:all .2s; word-break:break-all; }
      a.fp-social-link:hover { background:#002147; color:#FFB81C; border-color:#002147; }
   </style>
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

   <div class="it-offcanvas-area">
      <div class="itoffcanvas">
         <div class="itoffcanvas__close-btn"><button class="close-btn"><i class="fal fa-times"></i></button></div>
         <div class="itoffcanvas__logo">
            <a href="<?= fh(SITE_URL) ?>/index.php"><img src="assets/img/logo/logo-black.png" alt=""></a>
         </div>
         <div class="it-menu-mobile d-xl-none"></div>
         <div class="itoffcanvas__info">
            <h3 class="offcanva-title">Get In Touch</h3>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon"><a href="#"><i class="fal fa-envelope"></i></a></div>
               <div class="itoffcanvas__info-address"><span>Email</span><a href="mailto:info@primeuniversity.edu.bd">info@primeuniversity.edu.bd</a></div>
            </div>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon"><a href="#"><i class="fal fa-phone-alt"></i></a></div>
               <div class="itoffcanvas__info-address"><span>Phone</span><a href="tel:+8801710996196">+880-1710996196</a></div>
            </div>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon"><a href="#"><i class="fas fa-map-marker-alt"></i></a></div>
               <div class="itoffcanvas__info-address"><span>Location</span><a href="https://www.google.com/maps/@37.4801311,22.8928877,3z" target="_blank">114, 116 Mazar Rd, Dhaka 1216</a></div>
            </div>
         </div>
      </div>
   </div>
   <div class="body-overlay"></div>

   <header class="it-header-height">
      <?php include __DIR__ . '/includes/header-top.php'; ?>
      <?php include __DIR__ . '/includes/nav-menu.php'; ?>
   </header>

   <main>

   <!-- Banner -->
   <div style="background: linear-gradient(135deg, #002147 0%, #003366 100%); padding: 80px 0 60px;">
      <div class="container">
         <nav aria-label="breadcrumb" class="mb-20">
            <ol class="breadcrumb" style="background:transparent; padding:0; margin:0;">
               <li class="breadcrumb-item"><a href="<?= fh(SITE_URL) ?>/index.php" style="color:#FFB81C;">Home</a></li>
               <?php if ($dept_name && $dept_slug): ?>
               <li class="breadcrumb-item">
                  <a href="<?= fh(SITE_URL) ?>/department-faculty.php?slug=<?= urlencode($dept_slug) ?>" style="color:#E8EEF4;"><?= fh($dept_name) ?></a>
               </li>
               <?php endif; ?>
               <li class="breadcrumb-item active" style="color:#E8EEF4;"><?= fh($name) ?></li>
            </ol>
         </nav>
         <h2 style="color:#FFFFFF; font-weight:700; margin-bottom:6px;"><?= fh($name) ?></h2>
         <?php if ($designation): ?>
         <p style="color:#FFB81C; font-size:16px; font-weight:600; margin-bottom:4px;"><?= fh($designation) ?></p>
         <?php endif; ?>
         <?php if ($dept_name): ?>
         <p style="color:#E8EEF4; font-size:14px;"><?= fh($dept_name) ?></p>
         <?php endif; ?>
      </div>
   </div>

   <!-- Main Content -->
   <section class="pt-60 pb-100" style="background:#F8FAFC;">
      <div class="container">
         <div class="row g-4">

            <!-- Left Sidebar -->
            <div class="col-xl-3 col-lg-4">
               <div class="fp-sidebar-card">
                  <!-- Photo -->
                  <div style="background:linear-gradient(135deg,#002147,#003366); padding:32px; text-align:center;">
                     <?php if ($photo_url): ?>
                     <img src="<?= fh($photo_url) ?>" alt="<?= fh($name) ?>"
                          style="width:150px;height:150px;border-radius:50%;object-fit:cover;border:4px solid #FFB81C;display:block;margin:0 auto 16px;">
                     <?php else: ?>
                     <div style="width:150px;height:150px;border-radius:50%;background:rgba(255,255,255,0.1);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;border:4px solid #FFB81C;">
                        <i class="fas fa-user" style="font-size:60px;color:#FFB81C;"></i>
                     </div>
                     <?php endif; ?>
                     <h5 style="color:#fff;font-weight:700;margin-bottom:4px;"><?= fh($name) ?></h5>
                     <?php if ($designation): ?>
                     <p style="color:#FFB81C;font-size:13px;font-weight:600;margin-bottom:0;"><?= fh($designation) ?></p>
                     <?php endif; ?>
                     <?php if (!empty($dept_info['is_head'])): ?>
                     <span class="fp-badge mt-10" style="display:inline-block;"><i class="fas fa-star me-1"></i>Head of Department</span>
                     <?php endif; ?>
                  </div>

                  <!-- Contact info sidebar -->
                  <div style="padding:24px;">
                     <?php if (!empty($profile['official_email'])): ?>
                     <div class="fp-info-row">
                        <i class="fas fa-envelope"></i>
                        <a href="mailto:<?= fh($profile['official_email']) ?>" style="color:#002147;word-break:break-all;"><?= fh($profile['official_email']) ?></a>
                     </div>
                     <?php elseif (!empty($user['email'])): ?>
                     <div class="fp-info-row">
                        <i class="fas fa-envelope"></i>
                        <a href="mailto:<?= fh($user['email']) ?>" style="color:#002147;word-break:break-all;"><?= fh($user['email']) ?></a>
                     </div>
                     <?php endif; ?>

                     <?php if (!empty($profile['phone'])): ?>
                     <div class="fp-info-row">
                        <i class="fas fa-phone"></i>
                        <a href="tel:<?= fh($profile['phone']) ?>" style="color:#002147;"><?= fh($profile['phone']) ?></a>
                     </div>
                     <?php endif; ?>

                     <?php if (!empty($profile['office_location'])): ?>
                     <div class="fp-info-row">
                        <i class="fas fa-map-marker-alt"></i>
                        <span><?= fh($profile['office_location']) ?><?= !empty($profile['room_number']) ? ', Room ' . fh($profile['room_number']) : '' ?></span>
                     </div>
                     <?php endif; ?>

                     <?php if (!empty($profile['office_hours'])): ?>
                     <div class="fp-info-row">
                        <i class="fas fa-clock"></i>
                        <span><?= fh($profile['office_hours']) ?></span>
                     </div>
                     <?php endif; ?>

                     <?php if ($dept_name && $dept_slug): ?>
                     <div class="fp-info-row">
                        <i class="fas fa-university"></i>
                        <a href="<?= fh(SITE_URL) ?>/department-faculty.php?slug=<?= urlencode($dept_slug) ?>" style="color:#002147;"><?= fh($dept_name) ?></a>
                     </div>
                     <?php endif; ?>

                     <?php if ($cv_url): ?>
                     <a href="<?= fh($cv_url) ?>" target="_blank"
                        style="display:block;text-align:center;margin-top:16px;padding:10px 20px;background:#D21034;color:#fff;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;">
                        <i class="fas fa-file-pdf me-2"></i>Download CV
                     </a>
                     <?php endif; ?>
                  </div>
               </div>
            </div>

            <!-- Main Content -->
            <div class="col-xl-9 col-lg-8">

               <?php if (!empty($profile['bio'])): ?>
               <div class="fp-section">
                  <h6 class="fp-section-title"><i class="fas fa-user me-2"></i>About</h6>
                  <p style="color:#334155;line-height:1.9;font-size:15px;"><?= nl2br(fh($profile['bio'])) ?></p>
               </div>
               <?php endif; ?>

               <?php if (!empty($profile['qualification'])): ?>
               <div class="fp-section">
                  <h6 class="fp-section-title"><i class="fas fa-graduation-cap me-2"></i>Academic Qualifications</h6>
                  <p style="color:#334155;line-height:1.9;font-size:15px;"><?= nl2br(fh($profile['qualification'])) ?></p>
               </div>
               <?php endif; ?>

               <?php if (!empty($profile['research_interest'])): ?>
               <div class="fp-section">
                  <h6 class="fp-section-title"><i class="fas fa-flask me-2"></i>Research Interests</h6>
                  <p style="color:#334155;line-height:1.9;font-size:15px;"><?= nl2br(fh($profile['research_interest'])) ?></p>
               </div>
               <?php endif; ?>

               <?php if (!empty($profile['experience'])): ?>
               <div class="fp-section">
                  <h6 class="fp-section-title"><i class="fas fa-briefcase me-2"></i>Experience</h6>
                  <p style="color:#334155;line-height:1.9;font-size:15px;"><?= nl2br(fh($profile['experience'])) ?></p>
               </div>
               <?php endif; ?>

               <?php if (!empty($profile['courses_taught'])): ?>
               <div class="fp-section">
                  <h6 class="fp-section-title"><i class="fas fa-chalkboard me-2"></i>Courses Taught</h6>
                  <p style="color:#334155;line-height:1.9;font-size:15px;"><?= nl2br(fh($profile['courses_taught'])) ?></p>
               </div>
               <?php endif; ?>

               <?php if (!empty($profile['publications'])): ?>
               <div class="fp-section">
                  <h6 class="fp-section-title"><i class="fas fa-book-open me-2"></i>Publications</h6>
                  <div style="color:#334155;line-height:1.9;font-size:15px;"><?= nl2br(fh($profile['publications'])) ?></div>
               </div>
               <?php endif; ?>

               <?php if (!empty($profile['projects_grants'])): ?>
               <div class="fp-section">
                  <h6 class="fp-section-title"><i class="fas fa-project-diagram me-2"></i>Projects &amp; Grants</h6>
                  <p style="color:#334155;line-height:1.9;font-size:15px;"><?= nl2br(fh($profile['projects_grants'])) ?></p>
               </div>
               <?php endif; ?>

               <?php if (!empty($profile['supervision'])): ?>
               <div class="fp-section">
                  <h6 class="fp-section-title"><i class="fas fa-user-graduate me-2"></i>Supervision</h6>
                  <p style="color:#334155;line-height:1.9;font-size:15px;"><?= nl2br(fh($profile['supervision'])) ?></p>
               </div>
               <?php endif; ?>

               <?php if (!empty($profile['awards'])): ?>
               <div class="fp-section">
                  <h6 class="fp-section-title"><i class="fas fa-trophy me-2"></i>Awards &amp; Honors</h6>
                  <p style="color:#334155;line-height:1.9;font-size:15px;"><?= nl2br(fh($profile['awards'])) ?></p>
               </div>
               <?php endif; ?>

               <?php if (!empty($profile['professional_memberships'])): ?>
               <div class="fp-section">
                  <h6 class="fp-section-title"><i class="fas fa-id-badge me-2"></i>Professional Memberships</h6>
                  <p style="color:#334155;line-height:1.9;font-size:15px;"><?= nl2br(fh($profile['professional_memberships'])) ?></p>
               </div>
               <?php endif; ?>

               <?php if (!empty($profile['skills'])): ?>
               <div class="fp-section">
                  <h6 class="fp-section-title"><i class="fas fa-tools me-2"></i>Skills &amp; Expertise</h6>
                  <p style="color:#334155;line-height:1.9;font-size:15px;"><?= nl2br(fh($profile['skills'])) ?></p>
               </div>
               <?php endif; ?>

               <?php if (!empty($profile['languages'])): ?>
               <div class="fp-section">
                  <h6 class="fp-section-title"><i class="fas fa-language me-2"></i>Languages</h6>
                  <p style="color:#334155;font-size:15px;"><?= fh($profile['languages']) ?></p>
               </div>
               <?php endif; ?>

               <?php
               $has_online = !empty($profile['google_scholar']) || !empty($profile['orcid']) || !empty($profile['research_profiles']);
               if ($has_online):
               ?>
               <div class="fp-section">
                  <h6 class="fp-section-title"><i class="fas fa-globe me-2"></i>Online Profiles</h6>
                  <div>
                     <?php if (!empty($profile['google_scholar'])): ?>
                     <a href="<?= fh($profile['google_scholar']) ?>" target="_blank" rel="noopener" class="fp-online-link">
                        <i class="fas fa-graduation-cap"></i> Google Scholar
                     </a>
                     <?php endif; ?>
                     <?php if (!empty($profile['orcid'])): ?>
                     <a href="<?= fh($profile['orcid']) ?>" target="_blank" rel="noopener" class="fp-online-link">
                        <i class="fas fa-id-card"></i> ORCID
                     </a>
                     <?php endif; ?>
                     <?php if (!empty($profile['research_profiles'])): ?>
                     <?php foreach (array_filter(array_map('trim', explode("\n", $profile['research_profiles']))) as $rp): ?>
                     <a href="<?= fh($rp) ?>" target="_blank" rel="noopener" class="fp-online-link">
                        <?php $rp_label = parse_url($rp, PHP_URL_HOST); if ($rp_label === false || $rp_label === null) { $rp_label = parse_url($rp, PHP_URL_PATH); } if ($rp_label === false || $rp_label === null) { $rp_label = $rp; } ?>
                        <i class="fas fa-external-link-alt"></i> <?= fh($rp_label) ?>
                     </a>
                     <?php endforeach; ?>
                     <?php endif; ?>
                  </div>
               </div>
               <?php endif; ?>

               <?php if (!empty($profile['social_links'])): ?>
               <div class="fp-section">
                  <h6 class="fp-section-title"><i class="fas fa-share-alt me-2"></i>Social Links</h6>
                  <div>
                     <?php foreach (array_filter(array_map('trim', explode("\n", $profile['social_links']))) as $sl): ?>
                     <a href="<?= fh($sl) ?>" target="_blank" rel="noopener" class="fp-social-link">
                        <?php $sl_label = parse_url($sl, PHP_URL_HOST); if ($sl_label === false || $sl_label === null) { $sl_label = parse_url($sl, PHP_URL_PATH); } if ($sl_label === false || $sl_label === null) { $sl_label = $sl; } ?>
                        <i class="fas fa-link"></i> <?= fh($sl_label) ?>
                     </a>
                     <?php endforeach; ?>
                  </div>
               </div>
               <?php endif; ?>

               <?php if (!empty($profile['personal_email']) && $profile['personal_email'] !== ($profile['official_email'] ?? '')): ?>
               <div class="fp-section">
                  <h6 class="fp-section-title"><i class="fas fa-address-card me-2"></i>Contact Information</h6>
                  <?php if (!empty($profile['personal_email'])): ?>
                  <div class="fp-info-row">
                     <i class="fas fa-envelope"></i>
                     <span>Personal: <a href="mailto:<?= fh($profile['personal_email']) ?>" style="color:#002147;"><?= fh($profile['personal_email']) ?></a></span>
                  </div>
                  <?php endif; ?>
               </div>
               <?php endif; ?>

            </div>
         </div>
      </div>
   </section>

   </main>

   <footer>
   <section class="it-footer-wrap it-footer-style-2 fix">
      <div class="it-footer-area z-index-1 pt-200 pb-80" data-background="assets/img/shape/footer-bg-3-1.jpg">
         <img class="it-footer-shape-1 d-none d-xxl-block" src="assets/img/shape/footer-3-1.png" alt="">
         <img class="it-footer-shape-2" data-parallax='{"y": -200, "smoothness": 30}' src="assets/img/shape/footer-3-2.png" alt="">
         <div class="it-footer-border"><span></span></div>
         <div class="container">
            <div class="row">
               <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 mb-50">
                  <div class="it-footer-widget it-footer-col-1-1">
                     <div class="it-footer-widget-logo mb-30">
                        <a href="<?= fh(SITE_URL) ?>/index.php"><img src="assets/img/logo/logo-black.png" alt="Prime University"></a>
                     </div>
                     <div class="it-footer-widget-text">
                        <p>Access expert-led courses designed to help you succeed in your career, all from the comfort of your home.</p>
                     </div>
                     <div class="it-footer-widget-btn">
                        <a href="contact-us.html" class="it-btn-yellow theme-bg border-radius-100">
                           <span><span class="text-1">Contact Us</span><span class="text-2">Contact Us</span></span>
                           <i><svg width="16" height="15" viewBox="0 0 16 15" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M15.0544 8.1364C15.4058 7.78492 15.4058 7.21508 15.0544 6.8636L9.3268 1.13604C8.97533 0.784567 8.40548 0.784567 8.05401 1.13604C7.70254 1.48751 7.70254 2.05736 8.05401 2.40883L13.1452 7.5L8.05401 12.5912C7.70254 12.9426 7.70254 13.5125 8.05401 13.864C8.40548 14.2154 8.97533 14.2154 9.3268 13.864L15.0544 8.1364ZM0.417969 7.5V8.4H14.418V7.5V6.6H0.417969V7.5Z" fill="currentcolor"/></svg></i>
                        </a>
                     </div>
                  </div>
               </div>
               <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 mb-50">
                  <div class="it-footer-widget it-footer-col-1-2">
                     <h4 class="it-footer-widget-title">Useful Links</h4>
                     <div class="it-footer-widget-menu"><ul><li><a href="#">Marketplace</a></li><li><a href="#">University</a></li><li><a href="#">GYM Coaching</a></li><li><a href="#">Cooking</a></li></ul></div>
                  </div>
               </div>
               <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 mb-50">
                  <div class="it-footer-widget it-footer-col-1-3">
                     <h4 class="it-footer-widget-title">Our Company</h4>
                     <div class="it-footer-widget-menu"><ul><li><a href="#">Contact Us</a></li><li><a href="#">Become Teacher</a></li><li><a href="#">Blog</a></li><li><a href="#">Instructor</a></li><li><a href="#">Events</a></li></ul></div>
                  </div>
               </div>
               <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 mb-50">
                  <div class="it-footer-widget it-footer-col-1-4 d-flex justify-content-lg-end">
                     <div>
                        <h4 class="it-footer-widget-title">Get Contact</h4>
                        <div class="it-footer-widget-contact mb-25">
                           <ul>
                              <li><span>Phone:</span><a href="tel:01710996196">01710996196</a></li>
                              <li><span>Email:</span><a href="mailto:primeuniversity@gmail.com">primeuniversity@gmail.com</a></li>
                              <li><span>Location:</span><a target="_blank" href="https://www.google.com/maps/dir///@24.4503253,17.1644279,4.17z">114, 116 Mazar Rd, Dhaka 1216</a></li>
                           </ul>
                        </div>
                     </div>
                  </div>
               </div>
            </div>
         </div>
      </div>
      <div class="it-copyright-area it-copyright-ptb it-copyright-bg z-index-1 theme-bg">
         <div class="container">
            <div class="row align-items-center">
               <div class="col-12">
                  <div class="it-copyright-left style-2 text-center">
                     <p class="mb-0">Copyright &copy; <?= date('Y') ?> <a href="#">Prime University</a> All Rights Reserved</p>
                  </div>
               </div>
            </div>
         </div>
      </div>
   </section>
   </footer>

   <script src="assets/js/jquery.js"></script>
   <script src="assets/js/bootstrap.bundle.min.js"></script>
   <script src="assets/js/purecounter.js"></script>
   <script src="assets/js/range-slider.js"></script>
   <script src="assets/js/nice-select.js"></script>
   <script src="assets/js/swiper-bundle.min.js"></script>
   <script src="assets/js/isotope-pkgd.js"></script>
   <script src="assets/js/slick.min.js"></script>
   <script src="assets/js/wow.js"></script>
   <script src="assets/js/countdown.js"></script>
   <script src="assets/js/magnific-popup.js"></script>
   <script src="assets/js/imagesloaded-pkgd.js"></script>
   <script src="assets/js/parallax.js"></script>
   <script src="assets/js/slider.js"></script>
   <script src="assets/js/main.js"></script>
</body>
</html>
