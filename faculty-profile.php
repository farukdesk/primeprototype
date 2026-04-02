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
      .fp-section { background:#fff; border-radius:0 0 12px 12px; padding:28px 32px; }
      .fp-section-title { color:#002147; font-weight:700; font-size:1.05rem; border-left:4px solid #D21034; padding-left:12px; margin-bottom:16px; }
      .fp-info-row { display:flex; align-items:flex-start; gap:12px; margin-bottom:12px; font-size:14px; color:#334155; }
      .fp-info-row i { color:#D21034; width:18px; margin-top:3px; flex-shrink:0; }
      .fp-badge { display:inline-block; background:#002147; color:#FFB81C; font-size:11px; font-weight:700; padding:3px 10px; border-radius:20px; letter-spacing:.04em; margin-bottom:8px; }
      .fp-online-link { display:inline-flex; align-items:center; gap:8px; color:#002147; font-size:14px; text-decoration:none; padding:6px 14px; border:1px solid #e2e8f0; border-radius:8px; margin:4px; transition:all .2s; }
      .fp-online-link:hover { background:#002147; color:#FFB81C; border-color:#002147; }
      a.fp-social-link { display:inline-flex; align-items:center; gap:6px; color:#002147; font-size:13px; text-decoration:none; padding:4px 12px; border:1px solid #e2e8f0; border-radius:6px; margin:3px; transition:all .2s; word-break:break-all; }
      a.fp-social-link:hover { background:#002147; color:#FFB81C; border-color:#002147; }
      /* Tab styles */
      .fp-tabs-wrap { background:#fff; border-radius:12px; box-shadow:0 2px 12px rgba(0,33,71,0.06); overflow:hidden; }
      .fp-tabs-wrap .nav-tabs { background:#F1F5F9; border-bottom:2px solid #e2e8f0; padding:0 8px; flex-wrap:nowrap; overflow-x:auto; scrollbar-width:none; }
      .fp-tabs-wrap .nav-tabs::-webkit-scrollbar { display:none; }
      .fp-tabs-wrap .nav-tabs .nav-link { color:#64748b; font-size:13px; font-weight:600; border:none; border-bottom:3px solid transparent; border-radius:0; padding:14px 16px; white-space:nowrap; transition:all .2s; background:transparent; }
      .fp-tabs-wrap .nav-tabs .nav-link:hover { color:#002147; background:transparent; }
      .fp-tabs-wrap .nav-tabs .nav-link.active { color:#002147; border-bottom-color:#D21034; background:transparent; }
      .fp-tabs-wrap .tab-content { padding:0; }
      .fp-tabs-wrap .fp-section { border-radius:0; box-shadow:none; }
      /* Responsive banner */
      .fp-banner { background: linear-gradient(135deg, #002147 0%, #003366 100%); padding: 80px 0 60px; }
      /* Responsive photo area inside sidebar */
      .fp-photo-area { background: linear-gradient(135deg, #002147, #003366); padding: 32px; text-align: center; }
      /* Responsive contact/info area inside sidebar */
      .fp-contact-area { padding: 24px; }
      @media (max-width: 991px) {
         .fp-sidebar-card { margin-bottom: 24px; }
         .fp-banner { padding: 60px 0 40px; }
      }
      @media (max-width: 767px) {
         .fp-banner { padding: 40px 0 30px; }
         .fp-photo-area { padding: 24px 16px; }
         .fp-contact-area { padding: 16px; }
         .fp-section { padding: 20px 16px; }
         .fp-info-row { font-size: 13px; }
         .fp-online-link { font-size: 12px; padding: 5px 10px; margin: 3px; }
         a.fp-social-link { font-size: 12px; padding: 4px 10px; margin: 2px; }
         .fp-tabs-wrap .nav-tabs .nav-link { font-size: 12px; padding: 12px 10px; }
      }
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
   <div class="fp-banner">
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
                  <div class="fp-photo-area">
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
                  <div class="fp-contact-area">
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

               <?php
               /* Build the list of tabs that have content */
               $tabs = [];
               if (!empty($profile['bio']))
                  $tabs[] = ['id'=>'about',       'icon'=>'fas fa-user',         'label'=>'About'];
               if (!empty($profile['qualification']))
                  $tabs[] = ['id'=>'qualification','icon'=>'fas fa-graduation-cap','label'=>'Qualifications'];
               if (!empty($profile['research_interest']))
                  $tabs[] = ['id'=>'research',    'icon'=>'fas fa-flask',        'label'=>'Research'];
               if (!empty($profile['experience']))
                  $tabs[] = ['id'=>'experience',  'icon'=>'fas fa-briefcase',    'label'=>'Experience'];
               if (!empty($profile['courses_taught']))
                  $tabs[] = ['id'=>'courses',     'icon'=>'fas fa-chalkboard',   'label'=>'Courses'];
               if (!empty($profile['publications']))
                  $tabs[] = ['id'=>'publications','icon'=>'fas fa-book-open',    'label'=>'Publications'];
               if (!empty($profile['projects_grants']))
                  $tabs[] = ['id'=>'projects',    'icon'=>'fas fa-project-diagram','label'=>'Projects'];
               if (!empty($profile['supervision']))
                  $tabs[] = ['id'=>'supervision', 'icon'=>'fas fa-user-graduate','label'=>'Supervision'];
               if (!empty($profile['awards']))
                  $tabs[] = ['id'=>'awards',      'icon'=>'fas fa-trophy',       'label'=>'Awards'];
               if (!empty($profile['professional_memberships']))
                  $tabs[] = ['id'=>'memberships', 'icon'=>'fas fa-id-badge',     'label'=>'Memberships'];
               if (!empty($profile['skills']))
                  $tabs[] = ['id'=>'skills',      'icon'=>'fas fa-tools',        'label'=>'Skills'];
               if (!empty($profile['languages']))
                  $tabs[] = ['id'=>'languages',   'icon'=>'fas fa-language',     'label'=>'Languages'];
               $has_online = !empty($profile['google_scholar']) || !empty($profile['orcid']) || !empty($profile['research_profiles']);
               if ($has_online)
                  $tabs[] = ['id'=>'online',      'icon'=>'fas fa-globe',        'label'=>'Online Profiles'];
               if (!empty($profile['social_links']))
                  $tabs[] = ['id'=>'social',      'icon'=>'fas fa-share-alt',    'label'=>'Social Links'];
               $has_contact = !empty($profile['personal_email']) && $profile['personal_email'] !== ($profile['official_email'] ?? '');
               if ($has_contact)
                  $tabs[] = ['id'=>'contact',     'icon'=>'fas fa-address-card', 'label'=>'Contact'];
               ?>

               <?php if (!empty($tabs)):
                  $first_tab_id = $tabs[0]['id'];
               ?>
               <div class="fp-tabs-wrap">

                  <!-- Tab Navigation -->
                  <ul class="nav nav-tabs" id="fpProfileTabs" role="tablist">
                     <?php foreach ($tabs as $tab): ?>
                     <li class="nav-item" role="presentation">
                        <button class="nav-link <?= $tab['id'] === $first_tab_id ? 'active' : '' ?>"
                                id="fp-tab-<?= $tab['id'] ?>"
                                data-bs-toggle="tab"
                                data-bs-target="#fp-pane-<?= $tab['id'] ?>"
                                type="button" role="tab"
                                aria-controls="fp-pane-<?= $tab['id'] ?>"
                                aria-selected="<?= $tab['id'] === $first_tab_id ? 'true' : 'false' ?>">
                           <i class="<?= $tab['icon'] ?> me-1"></i><?= $tab['label'] ?>
                        </button>
                     </li>
                     <?php endforeach; ?>
                  </ul>

                  <!-- Tab Panes -->
                  <div class="tab-content" id="fpProfileTabsContent">

                     <?php if (!empty($profile['bio'])): ?>
                     <div class="tab-pane fade <?= $first_tab_id === 'about' ? 'show active' : '' ?>" id="fp-pane-about" role="tabpanel" aria-labelledby="fp-tab-about">
                        <div class="fp-section">
                           <p style="color:#334155;line-height:1.9;font-size:15px;"><?= nl2br(fh($profile['bio'])) ?></p>
                        </div>
                     </div>
                     <?php endif; ?>

                     <?php if (!empty($profile['qualification'])): ?>
                     <div class="tab-pane fade <?= $first_tab_id === 'qualification' ? 'show active' : '' ?>" id="fp-pane-qualification" role="tabpanel" aria-labelledby="fp-tab-qualification">
                        <div class="fp-section">
                           <p style="color:#334155;line-height:1.9;font-size:15px;"><?= nl2br(fh($profile['qualification'])) ?></p>
                        </div>
                     </div>
                     <?php endif; ?>

                     <?php if (!empty($profile['research_interest'])): ?>
                     <div class="tab-pane fade <?= $first_tab_id === 'research' ? 'show active' : '' ?>" id="fp-pane-research" role="tabpanel" aria-labelledby="fp-tab-research">
                        <div class="fp-section">
                           <p style="color:#334155;line-height:1.9;font-size:15px;"><?= nl2br(fh($profile['research_interest'])) ?></p>
                        </div>
                     </div>
                     <?php endif; ?>

                     <?php if (!empty($profile['experience'])): ?>
                     <div class="tab-pane fade <?= $first_tab_id === 'experience' ? 'show active' : '' ?>" id="fp-pane-experience" role="tabpanel" aria-labelledby="fp-tab-experience">
                        <div class="fp-section">
                           <p style="color:#334155;line-height:1.9;font-size:15px;"><?= nl2br(fh($profile['experience'])) ?></p>
                        </div>
                     </div>
                     <?php endif; ?>

                     <?php if (!empty($profile['courses_taught'])): ?>
                     <div class="tab-pane fade <?= $first_tab_id === 'courses' ? 'show active' : '' ?>" id="fp-pane-courses" role="tabpanel" aria-labelledby="fp-tab-courses">
                        <div class="fp-section">
                           <p style="color:#334155;line-height:1.9;font-size:15px;"><?= nl2br(fh($profile['courses_taught'])) ?></p>
                        </div>
                     </div>
                     <?php endif; ?>

                     <?php if (!empty($profile['publications'])): ?>
                     <div class="tab-pane fade <?= $first_tab_id === 'publications' ? 'show active' : '' ?>" id="fp-pane-publications" role="tabpanel" aria-labelledby="fp-tab-publications">
                        <div class="fp-section">
                           <div style="color:#334155;line-height:1.9;font-size:15px;"><?= nl2br(fh($profile['publications'])) ?></div>
                        </div>
                     </div>
                     <?php endif; ?>

                     <?php if (!empty($profile['projects_grants'])): ?>
                     <div class="tab-pane fade <?= $first_tab_id === 'projects' ? 'show active' : '' ?>" id="fp-pane-projects" role="tabpanel" aria-labelledby="fp-tab-projects">
                        <div class="fp-section">
                           <p style="color:#334155;line-height:1.9;font-size:15px;"><?= nl2br(fh($profile['projects_grants'])) ?></p>
                        </div>
                     </div>
                     <?php endif; ?>

                     <?php if (!empty($profile['supervision'])): ?>
                     <div class="tab-pane fade <?= $first_tab_id === 'supervision' ? 'show active' : '' ?>" id="fp-pane-supervision" role="tabpanel" aria-labelledby="fp-tab-supervision">
                        <div class="fp-section">
                           <p style="color:#334155;line-height:1.9;font-size:15px;"><?= nl2br(fh($profile['supervision'])) ?></p>
                        </div>
                     </div>
                     <?php endif; ?>

                     <?php if (!empty($profile['awards'])): ?>
                     <div class="tab-pane fade <?= $first_tab_id === 'awards' ? 'show active' : '' ?>" id="fp-pane-awards" role="tabpanel" aria-labelledby="fp-tab-awards">
                        <div class="fp-section">
                           <p style="color:#334155;line-height:1.9;font-size:15px;"><?= nl2br(fh($profile['awards'])) ?></p>
                        </div>
                     </div>
                     <?php endif; ?>

                     <?php if (!empty($profile['professional_memberships'])): ?>
                     <div class="tab-pane fade <?= $first_tab_id === 'memberships' ? 'show active' : '' ?>" id="fp-pane-memberships" role="tabpanel" aria-labelledby="fp-tab-memberships">
                        <div class="fp-section">
                           <p style="color:#334155;line-height:1.9;font-size:15px;"><?= nl2br(fh($profile['professional_memberships'])) ?></p>
                        </div>
                     </div>
                     <?php endif; ?>

                     <?php if (!empty($profile['skills'])): ?>
                     <div class="tab-pane fade <?= $first_tab_id === 'skills' ? 'show active' : '' ?>" id="fp-pane-skills" role="tabpanel" aria-labelledby="fp-tab-skills">
                        <div class="fp-section">
                           <p style="color:#334155;line-height:1.9;font-size:15px;"><?= nl2br(fh($profile['skills'])) ?></p>
                        </div>
                     </div>
                     <?php endif; ?>

                     <?php if (!empty($profile['languages'])): ?>
                     <div class="tab-pane fade <?= $first_tab_id === 'languages' ? 'show active' : '' ?>" id="fp-pane-languages" role="tabpanel" aria-labelledby="fp-tab-languages">
                        <div class="fp-section">
                           <p style="color:#334155;font-size:15px;"><?= fh($profile['languages']) ?></p>
                        </div>
                     </div>
                     <?php endif; ?>

                     <?php if ($has_online): ?>
                     <div class="tab-pane fade <?= $first_tab_id === 'online' ? 'show active' : '' ?>" id="fp-pane-online" role="tabpanel" aria-labelledby="fp-tab-online">
                        <div class="fp-section">
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
                     <div class="tab-pane fade <?= $first_tab_id === 'social' ? 'show active' : '' ?>" id="fp-pane-social" role="tabpanel" aria-labelledby="fp-tab-social">
                        <div class="fp-section">
                           <?php foreach (array_filter(array_map('trim', explode("\n", $profile['social_links']))) as $sl): ?>
                           <a href="<?= fh($sl) ?>" target="_blank" rel="noopener" class="fp-social-link">
                              <?php $sl_label = parse_url($sl, PHP_URL_HOST); if ($sl_label === false || $sl_label === null) { $sl_label = parse_url($sl, PHP_URL_PATH); } if ($sl_label === false || $sl_label === null) { $sl_label = $sl; } ?>
                              <i class="fas fa-link"></i> <?= fh($sl_label) ?>
                           </a>
                           <?php endforeach; ?>
                        </div>
                     </div>
                     <?php endif; ?>

                     <?php if ($has_contact): ?>
                     <div class="tab-pane fade <?= $first_tab_id === 'contact' ? 'show active' : '' ?>" id="fp-pane-contact" role="tabpanel" aria-labelledby="fp-tab-contact">
                        <div class="fp-section">
                           <div class="fp-info-row">
                              <i class="fas fa-envelope"></i>
                              <span>Personal: <a href="mailto:<?= fh($profile['personal_email']) ?>" style="color:#002147;"><?= fh($profile['personal_email']) ?></a></span>
                           </div>
                        </div>
                     </div>
                     <?php endif; ?>

                  </div><!-- /.tab-content -->
               </div><!-- /.fp-tabs-wrap -->
               <?php endif; ?>

            </div>
         </div>
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
