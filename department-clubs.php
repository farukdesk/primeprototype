<?php
require_once __DIR__ . '/includes/config.php';

$slug = trim($_GET['slug'] ?? '');
if ($slug === '') {
    header('Location: index.php');
    exit;
}

$db    = front_db();
$dept  = null;
$clubs = [];

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
        $st = $db->prepare('SELECT * FROM dept_clubs WHERE dept_id = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC');
        $st->execute([$dept['id']]);
        $clubs = $st->fetchAll();
    } catch (Throwable $e) {}
}

$current_page = 'clubs';
$base         = SITE_URL . '/department';
$dept_name    = fh($dept['name'] ?? 'Department');
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title>Clubs – <?= $dept_name ?> – Prime University</title>
   <meta name="description" content="Student clubs and organizations of <?= $dept_name ?> at Prime University.">
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
   .club-card { transition: transform 0.3s ease, box-shadow 0.3s ease; }
   .club-card:hover { transform: translateY(-5px); box-shadow: 0 15px 40px rgba(0,33,71,0.15) !important; }
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
               <div class="itoffcanvas__info-address"><span>Email</span><a href="mailto:info@primeuniversity.ac.bd">info@primeuniversity.ac.bd</a></div>
            </div>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon"><a href="#"><i class="fal fa-phone-alt"></i></a></div>
               <div class="itoffcanvas__info-address"><span>Phone</span><a href="tel:+8801969955566">01969-955566</a></div>
            </div>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon"><a href="#"><i class="fas fa-map-marker-alt"></i></a></div>
               <div class="itoffcanvas__info-address"><span>Location</span><a href="https://www.google.com/maps/@37.4801311,22.8928877,3z" target="_blank">114/116, Mazar Rd, Dhaka-1216</a></div>
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
                     <li class="breadcrumb-item active" style="color:#E8EEF4;">Clubs</li>
                  </ol>
               </nav>
               <h2 style="color:#FFFFFF; font-weight:700; margin-bottom:10px;">Student Clubs</h2>
               <p style="color:#E8EEF4; font-size:16px;"><?= $dept_name ?></p>
            </div>
         </div>
      </div>
   </div>

   <!-- Sub-navigation -->
   <?php include __DIR__ . '/includes/dept-subnav.php'; ?>

   <!-- Clubs Grid -->
   <section class="pt-100 pb-120" style="background-color: #FFFFFF;">
      <div class="container">
         <div class="row justify-content-center mb-60">
            <div class="col-12 text-center">
               <span class="it-section-subtitle" style="color: #D21034;"><i class="fas fa-users"></i> Student Life</span>
               <h4 class="it-section-title" style="color: #002147;">Clubs &amp; Organizations</h4>
            </div>
         </div>

         <?php if (!empty($clubs)): ?>
         <div class="row g-4">
            <?php foreach ($clubs as $club): ?>
            <div class="col-xl-4 col-lg-4 col-md-6 wow itfadeUp" data-wow-duration=".9s">
               <div class="card club-card h-100 border-0 shadow-sm" style="border-top:3px solid #D21034 !important;">
                  <div class="card-body p-30">
                     <div class="text-center mb-25">
                        <?php if (!empty($club['logo'])): ?>
                        <img src="<?= fh(ADMIN_UPLOAD_URL . '/departments/' . $club['logo']) ?>"
                             alt="<?= fh($club['name'] ?? '') ?>"
                             style="width:80px; height:80px; object-fit:contain; margin-bottom:15px;">
                        <?php else: ?>
                        <div class="mx-auto mb-15 d-flex align-items-center justify-content-center rounded-circle"
                             style="width:80px; height:80px; background:linear-gradient(135deg, #002147, #003366);">
                           <i class="fas fa-users" style="font-size:32px; color:#FFB81C;"></i>
                        </div>
                        <?php endif; ?>
                        <h5 style="color:#002147; font-weight:700; margin-bottom:5px;"><?= fh($club['name'] ?? '') ?></h5>
                     </div>

                     <?php if (!empty($club['description'])): ?>
                     <p style="color:#334155; font-size:14px; line-height:1.8; margin-bottom:15px;"><?= nl2br(fh($club['description'])) ?></p>
                     <?php endif; ?>

                     <?php if (!empty($club['president_name']) || !empty($club['email'])): ?>
                     <div style="background:#F8FAFC; border-radius:8px; padding:15px; margin-top:15px;">
                        <?php if (!empty($club['president_name'])): ?>
                        <p style="color:#334155; font-size:13px; margin-bottom:5px;">
                           <i class="fas fa-user-circle me-1" style="color:#FFB81C;"></i>
                           <strong>President:</strong> <?= fh($club['president_name']) ?>
                        </p>
                        <?php endif; ?>
                        <?php if (!empty($club['email'])): ?>
                        <p style="color:#334155; font-size:13px; margin-bottom:0;">
                           <i class="fas fa-envelope me-1" style="color:#FFB81C;"></i>
                           <a href="mailto:<?= fh($club['email']) ?>" style="color:#002147;"><?= fh($club['email']) ?></a>
                        </p>
                        <?php endif; ?>
                     </div>
                     <?php endif; ?>
                  </div>
               </div>
            </div>
            <?php endforeach; ?>
         </div>
         <?php else: ?>
         <div class="row">
            <div class="col-12 text-center py-80">
               <i class="fas fa-users" style="font-size:64px; color:#002147; opacity:0.2; display:block; margin-bottom:20px;"></i>
               <p style="color:#334155; font-size:17px;">Club information will be available soon.</p>
            </div>
         </div>
         <?php endif; ?>
      </div>
   </section>

   </main>

<?php include __DIR__ . '/includes/footer.php'; ?>

   <?php include __DIR__ . '/includes/scripts.php'; ?>
</body>
</html>
