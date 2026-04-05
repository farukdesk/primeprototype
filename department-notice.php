<?php
require_once __DIR__ . '/includes/config.php';

$slug = trim($_GET['slug'] ?? '');
if ($slug === '') {
    header('Location: index.php');
    exit;
}

$db      = front_db();
$dept    = null;
$notices = [];

if ($db) {
    try {
        $st = $db->prepare('SELECT * FROM dept_departments WHERE slug = ? AND is_active = 1 LIMIT 1');
        $st->execute([$slug]);
        $dept = $st->fetch() ?: null;
    } catch (Throwable $e) {}
}

if (!$dept) {
    header('Location: index.php');
    exit;
}

if ($db) {
    try {
        $st = $db->prepare('SELECT * FROM dept_notices WHERE dept_id = ? AND is_active = 1 ORDER BY notice_date DESC, created_at DESC');
        $st->execute([$dept['id']]);
        $notices = $st->fetchAll();
    } catch (Throwable $e) {}
}

$current_page = 'notice';
$base         = SITE_URL . '/department';
$dept_name    = fh($dept['name'] ?? 'Department');
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title>Notice Board – <?= $dept_name ?> – Prime University</title>
   <meta name="description" content="Official notices and announcements from <?= $dept_name ?> at Prime University.">
   <meta name="viewport" content="width=device-width, initial-scale=1">
   <link rel="shortcut icon" type="image/x-icon" href="/assets/img/logo/favicon.png">
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
   .it-dept-subnav { background-color: #002147; position: sticky; top: 0; z-index: 999; border-bottom: 3px solid #D21034; }
   .dept-subnav-inner { display: flex; overflow-x: auto; }
   .dept-subnav-inner ul { display: flex; list-style: none; margin: 0; padding: 0; flex-wrap: nowrap; gap: 0; }
   .dept-subnav-inner ul li a { display: block; color: #E8EEF4; text-decoration: none; padding: 14px 20px; font-size: 14px; font-weight: 500; white-space: nowrap; border-bottom: 3px solid transparent; transition: all 0.3s ease; }
   .dept-subnav-inner ul li a:hover, .dept-subnav-inner ul li a.active { color: #FFB81C; border-bottom-color: #FFB81C; background-color: rgba(255,255,255,0.05); }
   @media (max-width: 768px) { .dept-subnav-inner ul li a { padding: 12px 14px; font-size: 13px; } }
   .notice-card { transition: transform 0.3s ease, box-shadow 0.3s ease; border-left: 4px solid #002147; }
   .notice-card:hover { transform: translateX(4px); box-shadow: 0 10px 30px rgba(0,33,71,0.12) !important; }
   .notice-date-badge { min-width: 64px; text-align: center; background: #002147; border-radius: 8px; padding: 10px 12px; flex-shrink: 0; }
   .notice-date-badge .day { font-size: 26px; font-weight: 700; color: #FFB81C; line-height: 1; display: block; }
   .notice-date-badge .month { font-size: 11px; font-weight: 600; color: #E8EEF4; letter-spacing: 1px; display: block; }
   .notice-date-badge .year { font-size: 11px; color: #E8EEF4; display: block; }
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
            <a href="<?= fh(SITE_URL) ?>/index.php"><img src="/assets/img/logo/logo-black.png" alt=""></a>
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
         <div class="row">
            <div class="col-12">
               <nav aria-label="breadcrumb" class="mb-20">
                  <ol class="breadcrumb" style="background:transparent; padding:0; margin:0;">
                     <li class="breadcrumb-item"><a href="<?= fh(SITE_URL) ?>/index.php" style="color:#FFB81C;">Home</a></li>
                     <li class="breadcrumb-item"><a href="<?= fh(SITE_URL) ?>/department/<?= urlencode($slug) ?>" style="color:#E8EEF4;"><?= $dept_name ?></a></li>
                     <li class="breadcrumb-item active" style="color:#E8EEF4;">Notice Board</li>
                  </ol>
               </nav>
               <h2 style="color:#FFFFFF; font-weight:700; margin-bottom:10px;">Notice Board</h2>
               <p style="color:#E8EEF4; font-size:16px;"><?= $dept_name ?></p>
            </div>
         </div>
      </div>
   </div>

   <!-- Sub-navigation -->
   <?php include __DIR__ . '/includes/dept-subnav.php'; ?>

   <!-- Notices List -->
   <section class="pt-80 pb-100" style="background-color: #F8FAFC;">
      <div class="container">
         <div class="row justify-content-center mb-50">
            <div class="col-12 text-center">
               <span class="it-section-subtitle" style="color: #D21034;"><i class="fas fa-bell"></i> Announcements</span>
               <h4 class="it-section-title" style="color: #002147;">Official Notices</h4>
            </div>
         </div>

         <?php if (!empty($notices)): ?>
         <div class="row">
            <div class="col-xl-10 col-lg-12 mx-auto">
               <div class="d-flex flex-column gap-3">
                  <?php foreach ($notices as $notice): ?>
                  <?php
                     $n_date  = !empty($notice['notice_date']) ? strtotime($notice['notice_date']) : null;
                     $n_day   = $n_date ? date('d', $n_date) : '--';
                     $n_month = $n_date ? date('M', $n_date) : '';
                     $n_year  = $n_date ? date('Y', $n_date) : '';
                  ?>
                  <div class="card notice-card border-0 shadow-sm wow itfadeUp" data-wow-duration=".9s">
                     <div class="card-body p-3 p-md-4">
                        <div class="d-flex align-items-start gap-3 gap-md-4">
                           <div class="notice-date-badge flex-shrink-0">
                              <span class="day"><?= fh($n_day) ?></span>
                              <span class="month"><?= fh($n_month) ?></span>
                              <span class="year"><?= fh($n_year) ?></span>
                           </div>
                           <div class="flex-grow-1 min-w-0">
                              <h6 style="color:#002147; font-weight:700; margin-bottom:8px; font-size:16px;">
                                 <?= fh($notice['title'] ?? '') ?>
                              </h6>
                              <?php if (!empty($notice['content'])): ?>
                              <div style="color:#334155; font-size:14px; line-height:1.8;">
                                 <?= nl2br(fh($notice['content'])) ?>
                              </div>
                              <?php endif; ?>
                              <?php if (!empty($notice['attachment'])): ?>
                              <?php
                                 $att_ext = strtolower(pathinfo($notice['attachment'], PATHINFO_EXTENSION));
                                 $att_icon = match($att_ext) {
                                     'pdf'  => 'fa-file-pdf',
                                     'doc', 'docx' => 'fa-file-word',
                                     'jpg', 'jpeg', 'png' => 'fa-file-image',
                                     default => 'fa-file-alt',
                                 };
                              ?>
                              <div class="mt-15">
                                 <a href="<?= fh(ADMIN_UPLOAD_URL . '/departments/' . $notice['attachment']) ?>"
                                    class="d-inline-flex align-items-center gap-2"
                                    style="background:#002147; color:#FFB81C; padding:8px 18px; border-radius:25px; font-size:13px; font-weight:600; text-decoration:none;"
                                    target="_blank" rel="noopener" download>
                                    <i class="fas <?= $att_icon ?>"></i> Download Attachment
                                 </a>
                              </div>
                              <?php endif; ?>
                           </div>
                        </div>
                     </div>
                  </div>
                  <?php endforeach; ?>
               </div>
            </div>
         </div>
         <?php else: ?>
         <div class="row">
            <div class="col-12 text-center py-80">
               <i class="fas fa-bell-slash" style="font-size:64px; color:#002147; opacity:0.2; display:block; margin-bottom:20px;"></i>
               <p style="color:#334155; font-size:17px;">No notices at this time.</p>
               <p style="color:#334155; font-size:15px;">Please check back later for updates from the department.</p>
            </div>
         </div>
         <?php endif; ?>
      </div>
   </section>

   </main>

<?php include __DIR__ . '/includes/footer.php'; ?>

   <?php include __DIR__ . \'/includes/scripts.php\'; ?>
</body>
</html>
