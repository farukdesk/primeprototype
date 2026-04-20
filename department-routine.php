<?php
require_once __DIR__ . '/includes/config.php';

$slug = trim($_GET['slug'] ?? '');
if ($slug === '') {
    header('Location: index.php');
    exit;
}

$db       = front_db();
$dept     = null;
$routines = [];

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
        $st = $db->prepare('SELECT * FROM dept_routines WHERE dept_id = ? AND is_active = 1 ORDER BY type ASC, effective_from DESC, id DESC');
        $st->execute([$dept['id']]);
        $routines = $st->fetchAll();
    } catch (Throwable $e) {}
}

// Group routines by type
$grouped = [];
foreach ($routines as $r) {
    $type = $r['type'] ?? 'Other';
    $grouped[$type][] = $r;
}

$current_page = 'routine';
$base         = SITE_URL . '/department';
$dept_name    = fh($dept['name'] ?? 'Department');
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title>Class/Exam Routine – <?= $dept_name ?> – Prime University</title>
   <meta name="description" content="Class and exam routines for <?= $dept_name ?> at Prime University.">
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
   .routine-card { transition: transform 0.3s ease, box-shadow 0.3s ease; }
   .routine-card:hover { transform: translateY(-3px); box-shadow: 0 10px 30px rgba(0,33,71,0.12) !important; }
   .type-badge-class { background-color: #002147; color: #FFB81C; }
   .type-badge-exam  { background-color: #D21034; color: #FFFFFF; }
   .type-badge-other { background-color: #334155; color: #FFFFFF; }
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

   <!-- Banner -->
   <div style="background: linear-gradient(135deg, #002147 0%, #003366 100%); padding: 80px 0 60px;">
      <div class="container">
         <div class="row">
            <div class="col-12">
               <nav aria-label="breadcrumb" class="mb-20">
                  <ol class="breadcrumb" style="background:transparent; padding:0; margin:0;">
                     <li class="breadcrumb-item"><a href="<?= fh(SITE_URL) ?>/index.php" style="color:#FFB81C;">Home</a></li>
                     <li class="breadcrumb-item"><a href="<?= fh(SITE_URL) ?>/department/<?= urlencode($slug) ?>" style="color:#E8EEF4;"><?= $dept_name ?></a></li>
                     <li class="breadcrumb-item active" style="color:#E8EEF4;">Class/Exam Routine</li>
                  </ol>
               </nav>
               <h2 style="color:#FFFFFF; font-weight:700; margin-bottom:10px;">Class / Exam Routine</h2>
               <p style="color:#E8EEF4; font-size:16px;"><?= $dept_name ?></p>
            </div>
         </div>
      </div>
   </div>

   <!-- Sub-navigation -->
   <?php include __DIR__ . '/includes/dept-subnav.php'; ?>

   <!-- Routines -->
   <section class="pt-80 pb-100" style="background-color: #FFFFFF;">
      <div class="container">
         <div class="row justify-content-center mb-50">
            <div class="col-12 text-center">
               <span class="it-section-subtitle" style="color: #D21034;"><i class="fas fa-calendar-check"></i> Schedules</span>
               <h4 class="it-section-title" style="color: #002147;">Class &amp; Exam Routines</h4>
            </div>
         </div>

         <?php if (!empty($routines)): ?>
            <?php foreach ($grouped as $type => $items): ?>
            <div class="mb-40">
               <h5 class="mb-30 pb-15" style="color:#002147; font-weight:700; border-bottom:2px solid #FFB81C; display:inline-block; padding-right:20px;">
                  <i class="fas <?= strtolower($type) === 'exam' ? 'fa-file-alt' : 'fa-clock' ?> me-2" style="color:#D21034;"></i>
                  <?= fh(ucfirst($type)) ?> Routines
               </h5>
               <div class="row g-4">
                  <?php foreach ($items as $rt): ?>
                  <div class="col-xl-4 col-lg-6 wow itfadeUp" data-wow-duration=".9s">
                     <div class="card routine-card h-100 border-0 shadow-sm" style="border-left:4px solid <?= strtolower($type) === 'exam' ? '#D21034' : '#002147' ?> !important;">
                        <div class="card-body p-30">
                           <div class="d-flex align-items-start justify-content-between mb-15 flex-wrap gap-2">
                              <h6 style="color:#002147; font-weight:700; margin-bottom:0;"><?= fh($rt['title'] ?? '') ?></h6>
                              <span class="badge type-badge-<?= strtolower(preg_replace('/[^a-z]/i', '', $type)) === 'exam' ? 'exam' : 'class' ?>" style="font-size:11px; padding:5px 10px; border-radius:20px;">
                                 <?= fh(ucfirst($type)) ?>
                              </span>
                           </div>
                           <?php if (!empty($rt['semester'])): ?>
                           <p style="color:#334155; font-size:13px; margin-bottom:6px;"><i class="fas fa-book me-1" style="color:#FFB81C;"></i><strong>Semester:</strong> <?= fh($rt['semester']) ?></p>
                           <?php endif; ?>
                           <?php if (!empty($rt['section'])): ?>
                           <p style="color:#334155; font-size:13px; margin-bottom:6px;"><i class="fas fa-users me-1" style="color:#FFB81C;"></i><strong>Section:</strong> <?= fh($rt['section']) ?></p>
                           <?php endif; ?>
                           <?php if (!empty($rt['effective_from'])): ?>
                           <p style="color:#334155; font-size:13px; margin-bottom:6px;">
                              <i class="fas fa-calendar me-1" style="color:#FFB81C;"></i><strong>Effective:</strong>
                              <?= fh(date('d M, Y', strtotime($rt['effective_from']))) ?>
                           </p>
                           <?php endif; ?>
                           <?php if (!empty($rt['file_path'])): ?>
                           <div class="mt-20">
                              <a href="<?= fh(ADMIN_UPLOAD_URL . '/departments/' . $rt['file_path']) ?>"
                                 class="it-btn-yellow border-radius-100"
                                 style="font-size:13px; display:inline-flex; align-items:center; gap:6px;"
                                 target="_blank" rel="noopener">
                                 <i class="fas fa-download"></i>
                                 <span><span class="text-1">Download</span><span class="text-2">Download</span></span>
                              </a>
                           </div>
                           <?php endif; ?>
                        </div>
                     </div>
                  </div>
                  <?php endforeach; ?>
               </div>
            </div>
            <?php endforeach; ?>
         <?php else: ?>
         <div class="row">
            <div class="col-12 text-center py-80">
               <i class="fas fa-calendar-alt" style="font-size:64px; color:#002147; opacity:0.2; display:block; margin-bottom:20px;"></i>
               <p style="color:#334155; font-size:17px;">Routines are published at the start of each semester.</p>
               <p style="color:#334155; font-size:15px;">Please check back later or contact the department office.</p>
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
