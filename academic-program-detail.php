<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/includes/config.php';

$id      = (int)($_GET['id'] ?? 0);
$program = null;
$dept    = null;

if ($id > 0) {
    try {
        $db = front_db();
        if ($db) {
            $st = $db->prepare(
                'SELECT p.*, d.name AS dept_name, d.slug AS dept_slug, d.code AS dept_code,
                        d.faculty_label
                 FROM dept_academic_programs p
                 JOIN dept_departments d ON d.id = p.dept_id
                 WHERE p.id = ? AND p.is_active = 1 AND d.is_active = 1
                 LIMIT 1'
            );
            $st->execute([$id]);
            $row = $st->fetch();
            if ($row) {
                $program = $row;
                $dept    = ['name' => $row['dept_name'], 'slug' => $row['dept_slug'],
                            'code' => $row['dept_code'], 'faculty_label' => $row['faculty_label']];
            }
        }
    } catch (Throwable $e) {}
}

if (!$program) {
    header('Location: index.php');
    exit;
}

$slug         = $program['dept_slug'];
$dept_name    = fh($program['dept_name'] ?? 'Department');
$current_page = 'academic-programs';

function ap_semester_label(string $type): string {
    return match ($type) {
        'trimester' => 'Trimester (Spring / Summer / Fall)',
        'semester'  => 'Semester (Spring / Fall)',
        'annual'    => 'Annual',
        default     => '',
    };
}

function ap_degree_color(string $type): string {
    return match (strtolower($type)) {
        'bachelor of science', 'b.sc.', 'bsc', 'bba', 'ba', 'llb', 'bed' => '#002147',
        'master', 'msc', 'm.sc.', 'mba', 'ma', 'llm', 'med'              => '#D21034',
        'phd', 'doctorate'                                                  => '#FFB81C',
        default                                                             => '#334155',
    };
}
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title><?= fh($program['program_name']) ?> – <?= $dept_name ?> – Prime University</title>
   <meta name="description" content="<?= fh(mb_substr(strip_tags($program['description'] ?? ''), 0, 160)) ?>">
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
   .prog-stat { text-align: center; padding: 20px 15px; background: #F8FAFC; border-radius: 10px; }
   .prog-stat .value { font-size: 22px; font-weight: 700; color: #002147; display: block; line-height: 1.2; }
   .prog-stat .label { font-size: 12px; color: #64748B; font-weight: 500; margin-top: 4px; display: block; }
   .prog-details-content table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
   .prog-details-content table th, .prog-details-content table td { border: 1px solid #dee2e6; padding: 10px 14px; font-size: 14px; }
   .prog-details-content table th { background-color: #002147; color: #fff; font-weight: 600; }
   .prog-details-content table tr:nth-child(even) td { background-color: #f8fafc; }
   .prog-details-content h4, .prog-details-content h5 { color: #002147; margin-top: 24px; margin-bottom: 12px; }
   .prog-details-content ul, .prog-details-content ol { padding-left: 24px; margin-bottom: 16px; }
   .prog-details-content li { margin-bottom: 6px; color: #334155; font-size: 14px; line-height: 1.7; }
   .prog-details-content p { color: #334155; font-size: 15px; line-height: 1.8; }
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
                     <li class="breadcrumb-item"><a href="<?= fh(SITE_URL) ?>/department-academic-programs.php?slug=<?= urlencode($slug) ?>" style="color:#E8EEF4;">Academic Programs</a></li>
                     <li class="breadcrumb-item active" style="color:#FFFFFF;"><?= fh($program['program_name']) ?></li>
                  </ol>
               </nav>
               <h2 style="color:#FFFFFF; font-weight:700; margin-bottom:10px;"><?= fh($program['program_name']) ?></h2>
               <p style="color:#E8EEF4; font-size:16px; margin-bottom:0;"><?= $dept_name ?></p>
            </div>
         </div>
      </div>
   </div>

   <!-- Sub-navigation -->
   <?php include __DIR__ . '/includes/dept-subnav.php'; ?>

   <!-- Program Content -->
   <section class="pt-80 pb-100" style="background-color:#FFFFFF;">
      <div class="container">

         <!-- Program Summary Card -->
         <div class="row mb-50">
            <div class="col-12">
               <div class="card border-0 shadow-sm" style="border-left:4px solid #002147 !important;">
                  <div class="card-body p-40">

                     <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-25">
                        <div>
                           <?php if (!empty($program['degree_type'])): ?>
                           <span class="badge mb-10 d-inline-block"
                                 style="background-color:<?= ap_degree_color($program['degree_type']) ?>; font-size:13px; padding:7px 16px; border-radius:20px;">
                              <?= fh($program['degree_type']) ?>
                           </span>
                           <?php endif; ?>
                           <h4 style="color:#002147; font-weight:700; margin-bottom:0;"><?= fh($program['program_name']) ?></h4>
                        </div>
                        <?php if (!empty($program['attachment'])): ?>
                        <a href="<?= fh(ADMIN_UPLOAD_URL . '/departments/' . $program['attachment']) ?>"
                           class="d-inline-flex align-items-center gap-2"
                           style="background:#D21034; color:#FFFFFF; padding:10px 22px; border-radius:25px; font-size:14px; font-weight:600; text-decoration:none;"
                           target="_blank" rel="noopener" download>
                           <i class="fas fa-download"></i> Download Brochure
                        </a>
                        <?php endif; ?>
                     </div>

                     <?php $has_stats = !empty($program['duration']) || !empty($program['total_credit']) || !empty($program['semester_type']); ?>
                     <?php if ($has_stats): ?>
                     <div class="row g-3 mb-25">
                        <?php if (!empty($program['duration'])): ?>
                        <div class="col-6 col-md-3">
                           <div class="prog-stat">
                              <span class="value"><?= fh($program['duration']) ?></span>
                              <span class="label"><i class="fas fa-clock me-1" style="color:#D21034;"></i>Duration</span>
                           </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($program['total_credit'])): ?>
                        <div class="col-6 col-md-3">
                           <div class="prog-stat">
                              <span class="value"><?= fh($program['total_credit']) ?></span>
                              <span class="label"><i class="fas fa-book me-1" style="color:#D21034;"></i>Total Credits</span>
                           </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($program['semester_type'])): ?>
                        <?php $semester_label = ap_semester_label($program['semester_type']); ?>
                        <?php if ($semester_label !== ''): ?>
                        <div class="col-6 col-md-3">
                           <div class="prog-stat">
                              <span class="value" style="font-size:16px;"><?= fh($semester_label) ?></span>
                              <span class="label"><i class="fas fa-calendar-alt me-1" style="color:#D21034;"></i>Semester System</span>
                           </div>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                        <div class="col-6 col-md-3">
                           <div class="prog-stat">
                              <span class="value" style="font-size:15px;"><?= $dept_name ?></span>
                              <span class="label"><i class="fas fa-university me-1" style="color:#D21034;"></i>Department</span>
                           </div>
                        </div>
                     </div>
                     <?php endif; ?>

                     <?php if (!empty($program['description'])): ?>
                     <p style="color:#334155; font-size:15px; line-height:1.8; margin-bottom:0;">
                        <?= nl2br(fh($program['description'])) ?>
                     </p>
                     <?php endif; ?>

                  </div>
               </div>
            </div>
         </div>

         <!-- Detailed Content -->
         <?php if (!empty($program['details_content'])): ?>
         <div class="row mb-40">
            <div class="col-12">
               <div class="card border-0 shadow-sm">
                  <div class="card-header" style="background-color:#002147; padding:22px 30px;">
                     <h5 class="mb-0" style="color:#FFFFFF;">
                        <i class="fas fa-info-circle" style="color:#FFB81C; margin-right:10px;"></i>
                        Program Details
                     </h5>
                  </div>
                  <div class="card-body" style="padding:30px;">
                     <div class="prog-details-content">
                        <?php /* Rich HTML authored by admin via TinyMCE – output as-is (trusted admin input). */ ?>
                        <?= $program['details_content'] ?>
                     </div>
                  </div>
               </div>
            </div>
         </div>
         <?php endif; ?>

         <?php
         $has_academic_info = !empty($program['admission_content'])
                           || !empty($program['fees_content'])
                           || !empty($program['curriculum_content']);
         ?>
         <?php if ($has_academic_info): ?>
         <!-- Academic Information Section -->
         <div class="row justify-content-center mb-40">
            <div class="col-12 text-center">
               <span style="color:#D21034; font-size:14px; font-weight:600;">
                  <i class="fas fa-book-open me-1"></i> Program Details
               </span>
               <h4 style="color:#002147; font-weight:700; margin-top:8px; margin-bottom:0;">Academic Information</h4>
            </div>
         </div>

         <?php if (!empty($program['admission_content'])): ?>
         <!-- Admission Intake & Requirements -->
         <div class="row mb-40">
            <div class="col-12">
               <div class="card border-0 shadow-sm">
                  <div class="card-header" style="background-color:#002147; padding:22px 30px;">
                     <h4 class="mb-0" style="color:#FFFFFF;">
                        <i class="fas fa-door-open" style="color:#FFB81C; margin-right:10px;"></i>
                        Admission Intake &amp; Requirements
                     </h4>
                  </div>
                  <div class="card-body" style="padding:30px;">
                     <div class="prog-details-content">
                        <?= $program['admission_content'] ?>
                     </div>
                  </div>
               </div>
            </div>
         </div>
         <?php endif; ?>

         <?php if (!empty($program['fees_content'])): ?>
         <!-- Fees Structure -->
         <div class="row mb-40">
            <div class="col-12">
               <div class="card border-0 shadow-sm">
                  <div class="card-header" style="background-color:#002147; padding:22px 30px;">
                     <h4 class="mb-0" style="color:#FFFFFF;">
                        <i class="fas fa-money-bill-wave" style="color:#FFB81C; margin-right:10px;"></i>
                        Fees Structure
                     </h4>
                  </div>
                  <div class="card-body" style="padding:30px;">
                     <div class="prog-details-content">
                        <?= $program['fees_content'] ?>
                     </div>
                  </div>
               </div>
            </div>
         </div>
         <?php endif; ?>

         <?php if (!empty($program['curriculum_content'])): ?>
         <!-- Course Curriculum -->
         <div class="row mb-40">
            <div class="col-12">
               <div class="card border-0 shadow-sm">
                  <div class="card-header" style="background-color:#002147; padding:22px 30px;">
                     <h4 class="mb-0" style="color:#FFFFFF;">
                        <i class="fas fa-graduation-cap" style="color:#FFB81C; margin-right:10px;"></i>
                        Course Curriculum
                        <?php if (!empty($program['total_credit']) || !empty($program['duration'])): ?>
                        <span style="font-size:14px; font-weight:400; color:#E8EEF4; margin-left:10px;">
                           (<?= implode(' &ndash; ', array_filter([fh($program['total_credit'] ?? ''), fh($program['duration'] ?? '')])) ?>)
                        </span>
                        <?php endif; ?>
                     </h4>
                  </div>
                  <div class="card-body" style="padding:30px;">
                     <div class="prog-details-content">
                        <?= $program['curriculum_content'] ?>
                     </div>
                  </div>
               </div>
            </div>
         </div>
         <?php endif; ?>
         <?php endif; ?>

         <!-- Back Link -->
         <div class="row">
            <div class="col-12">
               <a href="<?= fh(SITE_URL) ?>/department-academic-programs.php?slug=<?= urlencode($slug) ?>"
                  style="color:#002147; font-size:14px; font-weight:600; text-decoration:none;">
                  <i class="fas fa-arrow-left me-2"></i>Back to All Academic Programs
               </a>
            </div>
         </div>

      </div>
   </section>

   </main>

<?php include __DIR__ . '/includes/footer.php'; ?>

   <script src="/assets/js/jquery.js"></script>
   <script src="/assets/js/bootstrap.bundle.min.js"></script>
   <script src="/assets/js/purecounter.js"></script>
   <script src="/assets/js/range-slider.js"></script>
   <script src="/assets/js/nice-select.js"></script>
   <script src="/assets/js/swiper-bundle.min.js"></script>
   <script src="/assets/js/isotope-pkgd.js"></script>
   <script src="/assets/js/slick.min.js"></script>
   <script src="/assets/js/wow.js"></script>
   <script src="/assets/js/countdown.js"></script>
   <script src="/assets/js/magnific-popup.js"></script>
   <script src="/assets/js/imagesloaded-pkgd.js"></script>
   <script src="/assets/js/parallax.js"></script>
   <script src="/assets/js/slider.js"></script>
   <script src="/assets/js/main.js"></script>
</body>
</html>
