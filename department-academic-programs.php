<?php
require_once __DIR__ . '/includes/config.php';

$slug = trim($_GET['slug'] ?? '');
if ($slug === '') {
    header('Location: index.php');
    exit;
}

$db       = front_db();
$dept     = null;
$programs = [];

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
        $st = $db->prepare('SELECT * FROM dept_academic_programs WHERE dept_id = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC');
        $st->execute([$dept['id']]);
        $programs = $st->fetchAll();
    } catch (Throwable $e) {}
}

$current_page = 'academic-programs';
$base         = SITE_URL . '/department';
$dept_name    = fh($dept['name'] ?? 'Department');

// Badge colours per degree type
function degree_badge_color(string $type): string {
    return match (strtolower($type)) {
        'bachelor', 'bsc', 'bba', 'ba', 'llb', 'bed' => '#002147',
        'master', 'msc', 'mba', 'ma', 'llm', 'med'   => '#D21034',
        'phd', 'doctorate'                             => '#FFB81C',
        default                                        => '#334155',
    };
}
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title>Academic Programs – <?= $dept_name ?> – Prime University</title>
   <meta name="description" content="Academic programs offered by <?= $dept_name ?> at Prime University.">
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
   .it-dept-subnav { background-color: #002147; position: sticky; top: 0; z-index: 999; border-bottom: 3px solid #D21034; }
   .dept-subnav-inner { display: flex; overflow-x: auto; }
   .dept-subnav-inner ul { display: flex; list-style: none; margin: 0; padding: 0; flex-wrap: nowrap; gap: 0; }
   .dept-subnav-inner ul li a { display: block; color: #E8EEF4; text-decoration: none; padding: 14px 20px; font-size: 14px; font-weight: 500; white-space: nowrap; border-bottom: 3px solid transparent; transition: all 0.3s ease; }
   .dept-subnav-inner ul li a:hover, .dept-subnav-inner ul li a.active { color: #FFB81C; border-bottom-color: #FFB81C; background-color: rgba(255,255,255,0.05); }
   @media (max-width: 768px) { .dept-subnav-inner ul li a { padding: 12px 14px; font-size: 13px; } }
   .program-card { transition: transform 0.3s ease, box-shadow 0.3s ease; }
   .program-card:hover { transform: translateY(-5px); box-shadow: 0 15px 40px rgba(0,33,71,0.15) !important; }
   .prog-stat { text-align: center; }
   .prog-stat .value { font-size: 22px; font-weight: 700; color: #002147; display: block; }
   .prog-stat .label { font-size: 12px; color: #334155; font-weight: 500; }
   .prog-details-content table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
   .prog-details-content table th, .prog-details-content table td { border: 1px solid #dee2e6; padding: 10px 14px; font-size: 14px; }
   .prog-details-content table th { background-color: #002147; color: #fff; font-weight: 600; }
   .prog-details-content table tr:nth-child(even) td { background-color: #f8fafc; }
   .prog-details-content h4, .prog-details-content h5 { color: #002147; margin-top: 24px; margin-bottom: 12px; }
   .prog-details-content ul, .prog-details-content ol { padding-left: 24px; margin-bottom: 16px; }
   .prog-details-content li { margin-bottom: 6px; color: #334155; font-size: 14px; line-height: 1.7; }
   @media (max-width: 576px) { .p-30 { padding: 20px !important; } }
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
         <div class="row">
            <div class="col-12">
               <nav aria-label="breadcrumb" class="mb-20">
                  <ol class="breadcrumb" style="background:transparent; padding:0; margin:0;">
                     <li class="breadcrumb-item"><a href="<?= fh(SITE_URL) ?>/index.php" style="color:#FFB81C;">Home</a></li>
                     <li class="breadcrumb-item"><a href="<?= fh(SITE_URL) ?>/department/<?= urlencode($slug) ?>" style="color:#E8EEF4;"><?= $dept_name ?></a></li>
                     <li class="breadcrumb-item active" style="color:#E8EEF4;">Academic Programs</li>
                  </ol>
               </nav>
               <h2 style="color:#FFFFFF; font-weight:700; margin-bottom:10px;">Academic Programs</h2>
               <p style="color:#E8EEF4; font-size:16px;"><?= $dept_name ?></p>
            </div>
         </div>
      </div>
   </div>

   <!-- Sub-navigation -->
   <?php include __DIR__ . '/includes/dept-subnav.php'; ?>

   <!-- Programs -->
   <section class="pt-80 pb-100" style="background-color: #FFFFFF;">
      <div class="container">
         <div class="row justify-content-center mb-50">
            <div class="col-12 text-center">
               <span class="it-section-subtitle" style="color: #D21034;"><i class="fas fa-graduation-cap"></i> What We Offer</span>
               <h4 class="it-section-title" style="color: #002147;">Academic Programs</h4>
            </div>
         </div>

         <?php if (!empty($programs)): ?>
         <div class="d-flex flex-column gap-4">
            <?php foreach ($programs as $idx => $prog): ?>
            <?php $collapse_id = 'progDetails' . (int)$prog['id']; ?>
            <div class="card program-card border-0 shadow-sm" style="border-left:4px solid #002147 !important;">
               <div class="card-body p-0">

                  <!-- Program Header -->
                  <div class="p-30 p-md-40">
                     <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-20">
                        <div>
                           <?php if (!empty($prog['degree_type'])): ?>
                           <span class="badge mb-10 d-inline-block" style="background-color:<?= degree_badge_color($prog['degree_type']) ?>; font-size:12px; padding:6px 14px; border-radius:20px;">
                              <?= fh($prog['degree_type']) ?>
                           </span>
                           <?php endif; ?>
                           <h5 style="color:#002147; font-weight:700; margin-bottom:0;"><?= fh($prog['program_name'] ?? '') ?></h5>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                           <?php if (!empty($prog['attachment'])): ?>
                           <a href="<?= fh(ADMIN_UPLOAD_URL . '/departments/' . $prog['attachment']) ?>"
                              class="d-inline-flex align-items-center gap-2"
                              style="background:#D21034; color:#FFFFFF; padding:8px 18px; border-radius:25px; font-size:13px; font-weight:600; text-decoration:none;"
                              target="_blank" rel="noopener" download>
                              <i class="fas fa-download"></i> Download Brochure
                           </a>
                           <?php endif; ?>
                           <?php if (!empty($prog['details_content'])): ?>
                           <button class="btn btn-sm d-inline-flex align-items-center gap-2"
                                   style="background:#002147; color:#FFB81C; border:none; padding:8px 18px; border-radius:25px; font-size:13px; font-weight:600;"
                                   type="button" data-bs-toggle="collapse" data-bs-target="#<?= $collapse_id ?>"
                                   aria-expanded="false" aria-controls="<?= $collapse_id ?>">
                              <i class="fas fa-info-circle"></i> View Details
                              <i class="fas fa-chevron-down ms-1" style="font-size:11px;"></i>
                           </button>
                           <?php endif; ?>
                        </div>
                     </div>

                     <?php if (!empty($prog['duration']) || !empty($prog['total_credit'])): ?>
                     <div class="row g-3 mb-20">
                        <?php if (!empty($prog['duration'])): ?>
                        <div class="col-6 col-md-3">
                           <div class="prog-stat p-15 rounded" style="background:#F8FAFC;">
                              <span class="value"><?= fh($prog['duration']) ?></span>
                              <span class="label">Duration</span>
                           </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($prog['total_credit'])): ?>
                        <div class="col-6 col-md-3">
                           <div class="prog-stat p-15 rounded" style="background:#F8FAFC;">
                              <span class="value"><?= fh($prog['total_credit']) ?></span>
                              <span class="label">Total Credits</span>
                           </div>
                        </div>
                        <?php endif; ?>
                     </div>
                     <?php endif; ?>

                     <?php if (!empty($prog['description'])): ?>
                     <p style="color:#334155; font-size:15px; line-height:1.8; margin-bottom:0;"><?= nl2br(fh($prog['description'])) ?></p>
                     <?php endif; ?>
                  </div>

                  <!-- Collapsible Details -->
                  <?php if (!empty($prog['details_content'])): ?>
                  <div class="collapse" id="<?= $collapse_id ?>">
                     <div style="border-top:1px solid #E2E8F0; padding:30px 30px 30px; background:#F8FAFC;">
                        <div class="prog-details-content" style="color:#334155; font-size:15px; line-height:1.8;">
                           <?php /* Rich HTML authored by super-admin via TinyMCE – output as-is (trusted source). */ ?>
                           <?= $prog['details_content'] ?>
                        </div>
                     </div>
                  </div>
                  <?php endif; ?>

               </div>
            </div>
            <?php endforeach; ?>
         </div>
         <?php else: ?>
         <div class="row">
            <div class="col-12 text-center py-80">
               <i class="fas fa-graduation-cap" style="font-size:64px; color:#002147; opacity:0.2; display:block; margin-bottom:20px;"></i>
               <p style="color:#334155; font-size:17px;">Academic program information will be available soon.</p>
            </div>
         </div>
         <?php endif; ?>
      </div>
   </section>

   </main>

<?php include __DIR__ . '/includes/footer.php'; ?>

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
