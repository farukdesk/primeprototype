<?php
require_once __DIR__ . '/includes/config.php';

$slug = trim($_GET['slug'] ?? '');
if ($slug === '') { header('Location: /clubs.php'); exit; }

$club   = null;
$members    = [];
$photos     = [];
$activities = [];
$events     = [];

try {
    $db = front_db();
    if ($db) {
        $st = $db->prepare(
            "SELECT c.*, d.name AS dept_name, p.name AS program_name
             FROM clubs c
             LEFT JOIN dept_departments d ON d.id = c.dept_id
             LEFT JOIN dept_academic_programs p ON p.id = c.program_id
             WHERE c.slug = ? AND c.is_active = 1"
        );
        $st->execute([$slug]);
        $club = $st->fetch();

        if ($club) {
            $cid = $club['id'];

            $st = $db->prepare('SELECT * FROM club_members WHERE club_id = ? ORDER BY sort_order, id');
            $st->execute([$cid]); $members = $st->fetchAll();

            $st = $db->prepare('SELECT * FROM club_photos WHERE club_id = ? ORDER BY sort_order, id');
            $st->execute([$cid]); $photos = $st->fetchAll();

            $st = $db->prepare('SELECT * FROM club_activities WHERE club_id = ? ORDER BY activity_date DESC, id DESC LIMIT 12');
            $st->execute([$cid]); $activities = $st->fetchAll();

            $st = $db->prepare(
                'SELECT e.*, (SELECT COUNT(*) FROM club_event_registrations r WHERE r.event_id = e.id AND r.status="approved") AS approved_count
                 FROM club_events e WHERE e.club_id = ? AND e.is_published = 1 ORDER BY e.event_date DESC, e.id DESC'
            );
            $st->execute([$cid]); $events = $st->fetchAll();
        }
    }
} catch (Throwable $e) { /* silently fall through */ }

if (!$club) { header('Location: /clubs.php'); exit; }

$page_title = fh($club['name']) . ' – Prime University';
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title><?= $page_title ?></title>
   <meta name="description" content="<?= fh(mb_substr(strip_tags($club['goal'] ?? 'Learn about ' . $club['name']), 0, 160)) ?>">
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
   /* ── Club Detail Styles ───────────────────────────── */
   .pu-club-hero {
      position: relative;
      min-height: 340px;
      display: flex;
      align-items: flex-end;
      padding: 0 0 40px;
      background: linear-gradient(135deg,#0f2027,#203a43,#1abc9c);
   }
   .pu-club-hero .hero-bg {
      position: absolute; inset: 0;
      background-size: cover; background-position: center;
      opacity: .25;
   }
   .pu-club-hero .hero-content { position: relative; z-index: 2; }
   .pu-club-hero .club-logo-wrap { width: 100px; height: 100px; border-radius: 50%; border: 4px solid #fff; box-shadow: 0 4px 18px rgba(0,0,0,.25); overflow: hidden; background: #fff; flex-shrink: 0; }
   .pu-club-hero .club-logo-wrap img { width:100%; height:100%; object-fit:cover; }
   .pu-club-hero .club-logo-placeholder { width:100px; height:100px; border-radius:50%; border:4px solid #fff; box-shadow:0 4px 18px rgba(0,0,0,.25); background:#e8f5e9; flex-shrink:0; display:flex; align-items:center; justify-content:center; }
   .pu-club-hero h1 { font-size: clamp(1.7rem,4vw,2.4rem); font-weight: 800; color: #fff; margin-bottom: 10px; }
   .pu-club-hero .breadcrumb-nav a, .pu-club-hero .breadcrumb-nav span { color: rgba(255,255,255,.7); font-size:.84rem; }
   .pu-club-hero .breadcrumb-nav .sep { margin:0 8px; color:rgba(255,255,255,.35); }

   /* Info cards */
   .pu-info-card { border:none; border-radius:16px; box-shadow:0 4px 18px rgba(0,0,0,.07); height:100%; }
   .pu-info-card .card-header { background:none; border-bottom:1px solid #f1f5f9; padding:18px 22px 14px; font-weight:700; color:#1a2e5a; }
   .pu-info-card .card-body { padding:18px 22px; }

   /* Tabs */
   .pu-tabs .nav-tabs { border-bottom: 2px solid #e2e8f0; gap: 4px; }
   .pu-tabs .nav-link { color: #6b7280; font-weight: 600; padding: 12px 20px; border: none; border-radius: 8px 8px 0 0; transition: all .2s; }
   .pu-tabs .nav-link.active { color: #1abc9c; background: #f0faf8; border-bottom: 2px solid #1abc9c; margin-bottom: -2px; }
   .pu-tabs .nav-link:hover:not(.active) { color: #1a2e5a; background: #f8fafc; }
   .pu-tabs .tab-content { background: #fff; border: 1px solid #e2e8f0; border-top: none; border-radius: 0 0 16px 16px; padding: 28px; }

   /* Member card */
   .pu-member-card { border:none; border-radius:12px; background:#f8fafc; padding:16px 20px; display:flex; align-items:center; gap:14px; }
   .pu-member-avatar { width:46px; height:46px; border-radius:50%; background:linear-gradient(135deg,#1abc9c,#16a085); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
   .pu-member-avatar i { color:#fff; font-size:1rem; }
   .pu-member-name { font-weight:700; color:#1a2e5a; font-size:.95rem; }
   .pu-member-role { font-size:.8rem; color:#6b7280; }
   .pu-member-id { font-size:.78rem; background:#e8f5e9; color:#16a085; border-radius:20px; padding:2px 10px; display:inline-block; margin-top:3px; }

   /* Gallery */
   .pu-gallery-img { border-radius:12px; overflow:hidden; }
   .pu-gallery-img img { width:100%; height:200px; object-fit:cover; transition:transform .3s; display:block; }
   .pu-gallery-img:hover img { transform:scale(1.04); }

   /* Event card */
   .pu-event-card { border:none; border-radius:14px; box-shadow:0 3px 14px rgba(0,0,0,.07); overflow:hidden; height:100%; transition:transform .25s; }
   .pu-event-card:hover { transform:translateY(-4px); }
   .pu-event-card .event-cover { height:150px; object-fit:cover; width:100%; }
   .pu-event-card .event-cover-ph { height:100px; background:linear-gradient(135deg,#667eea,#764ba2); display:flex; align-items:center; justify-content:center; }
   .pu-event-card .event-date-badge { position:absolute; top:12px; left:12px; background:#fff; border-radius:10px; padding:6px 12px; font-size:.8rem; font-weight:700; color:#1a2e5a; box-shadow:0 2px 8px rgba(0,0,0,.15); }
   .pu-event-card .btn-register { background:#1abc9c; color:#fff; border-radius:8px; padding:9px 20px; font-size:.875rem; font-weight:600; text-decoration:none; transition:background .2s; display:inline-block; }
   .pu-event-card .btn-register:hover { background:#16a085; color:#fff; }
   .pu-event-card .btn-closed { background:#e5e7eb; color:#9ca3af; border-radius:8px; padding:9px 20px; font-size:.875rem; font-weight:600; display:inline-block; cursor:not-allowed; }

   /* Activity card */
   .pu-activity-img { height:180px; object-fit:cover; width:100%; border-radius:12px 12px 0 0; }
   .pu-activity-placeholder { height:90px; background:linear-gradient(135deg,#f093fb,#f5576c); border-radius:12px 12px 0 0; display:flex; align-items:center; justify-content:center; }
   </style>
</head>
<body id="body" class="it-magic-cursor">
   <div id="preloader"><div class="preloader"><span></span><span></span></div></div>
   <div id="magic-cursor"><div id="ball"></div></div>
   <button class="scroll-top scroll-to-target" data-target="html"><i class="fa fa-angle-up"></i></button>
   <div class="offcanvas-overlay"></div>
   <div class="body-overlay"></div>

   <header class="it-header-height">
      <?php include __DIR__ . '/includes/header-top.php'; ?>
      <?php include __DIR__ . '/includes/nav-menu.php'; ?>
   </header>

   <!-- Hero -->
   <section class="pu-club-hero">
      <?php if ($club['cover_photo']): ?>
      <div class="hero-bg" style="background-image:url('<?= ADMIN_UPLOAD_URL ?>/clubs/covers/<?= fh($club['cover_photo']) ?>')"></div>
      <?php endif; ?>
      <div class="container hero-content">
         <nav class="breadcrumb-nav mb-4">
            <a href="/">Home</a><span class="sep">/</span>
            <a href="/clubs.php">Clubs</a><span class="sep">/</span>
            <span><?= fh($club['name']) ?></span>
         </nav>
         <div class="d-flex align-items-center gap-4 flex-wrap">
            <?php if ($club['logo']): ?>
            <div class="club-logo-wrap"><img src="<?= ADMIN_UPLOAD_URL ?>/clubs/logos/<?= fh($club['logo']) ?>" alt="Logo"></div>
            <?php else: ?>
            <div class="club-logo-placeholder"><i class="fas fa-users fa-2x text-success"></i></div>
            <?php endif; ?>
            <div>
               <h1><?= fh($club['name']) ?></h1>
               <div class="d-flex flex-wrap gap-2">
                  <?php if ($club['dept_name']): ?><span class="badge bg-white bg-opacity-20 text-white border border-white border-opacity-25"><i class="fas fa-building-columns me-1"></i><?= fh($club['dept_name']) ?></span><?php endif; ?>
                  <?php if ($club['program_name']): ?><span class="badge bg-white bg-opacity-20 text-white border border-white border-opacity-25"><?= fh($club['program_name']) ?></span><?php endif; ?>
                  <span class="badge bg-success bg-opacity-80"><i class="fas fa-users me-1"></i><?= count($members) ?> Members</span>
               </div>
            </div>
         </div>
      </div>
   </section>

   <div class="container" style="padding-top:48px;padding-bottom:80px;">

      <!-- Quick info strip -->
      <?php if ($club['notice']): ?>
      <div class="alert d-flex align-items-center gap-3 mb-4" style="background:#fffbeb;border:1px solid #f59e0b;border-radius:12px;padding:16px 20px;">
         <i class="fas fa-bell fa-lg" style="color:#f59e0b;flex-shrink:0;"></i>
         <div><strong>Club Notice:</strong> <?= fh($club['notice']) ?></div>
      </div>
      <?php endif; ?>

      <!-- Goal / Facilities Row -->
      <?php if ($club['goal'] || $club['facilities']): ?>
      <div class="row g-4 mb-5">
         <?php if ($club['goal']): ?>
         <div class="col-md-<?= $club['facilities'] ? '6' : '12' ?>">
            <div class="pu-info-card card">
               <div class="card-header"><i class="fas fa-bullseye me-2 text-success"></i>Club Goal</div>
               <div class="card-body text-muted"><?= nl2br(fh($club['goal'])) ?></div>
            </div>
         </div>
         <?php endif; ?>
         <?php if ($club['facilities']): ?>
         <div class="col-md-<?= $club['goal'] ? '6' : '12' ?>">
            <div class="pu-info-card card">
               <div class="card-header"><i class="fas fa-tools me-2 text-primary"></i>Facilities</div>
               <div class="card-body text-muted"><?= nl2br(fh($club['facilities'])) ?></div>
            </div>
         </div>
         <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Tabs -->
      <div class="pu-tabs">
         <ul class="nav nav-tabs" id="clubDetailTabs" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#cd-members"><i class="fas fa-users me-1"></i>Members <span class="badge bg-secondary ms-1"><?= count($members) ?></span></button></li>
            <li class="nav-item"><button class="nav-link"        data-bs-toggle="tab" data-bs-target="#cd-events"><i class="fas fa-calendar me-1"></i>Events <span class="badge bg-secondary ms-1"><?= count($events) ?></span></button></li>
            <li class="nav-item"><button class="nav-link"        data-bs-toggle="tab" data-bs-target="#cd-gallery"><i class="fas fa-images me-1"></i>Gallery <span class="badge bg-secondary ms-1"><?= count($photos) ?></span></button></li>
            <li class="nav-item"><button class="nav-link"        data-bs-toggle="tab" data-bs-target="#cd-activities"><i class="fas fa-running me-1"></i>Activities <span class="badge bg-secondary ms-1"><?= count($activities) ?></span></button></li>
         </ul>
         <div class="tab-content">

            <!-- Members -->
            <div class="tab-pane fade show active" id="cd-members">
               <?php if (empty($members)): ?>
               <p class="text-center text-muted py-4">No members listed yet.</p>
               <?php else: ?>
               <div class="row g-3">
               <?php foreach ($members as $m): ?>
               <div class="col-sm-6 col-lg-4">
                  <div class="pu-member-card">
                     <div class="pu-member-avatar"><i class="fas fa-user"></i></div>
                     <div>
                        <div class="pu-member-name"><?= fh($m['full_name']) ?></div>
                        <?php if ($m['role_position']): ?><div class="pu-member-role"><?= fh($m['role_position']) ?></div><?php endif; ?>
                        <?php if ($m['student_id_no']): ?><span class="pu-member-id"><?= fh($m['student_id_no']) ?></span><?php endif; ?>
                     </div>
                  </div>
               </div>
               <?php endforeach; ?>
               </div>
               <?php endif; ?>
            </div>

            <!-- Events -->
            <div class="tab-pane fade" id="cd-events">
               <?php if (empty($events)): ?>
               <p class="text-center text-muted py-4">No upcoming events.</p>
               <?php else: ?>
               <div class="row g-4">
               <?php foreach ($events as $ev): ?>
               <?php
               $deadline_passed = $ev['registration_deadline'] && $ev['registration_deadline'] < date('Y-m-d');
               $is_full = $ev['capacity'] && ($ev['approved_count'] >= $ev['capacity']);
               $can_register = !$deadline_passed && !$is_full;
               ?>
               <div class="col-sm-6 col-lg-4">
                  <div class="pu-event-card card">
                     <?php if ($ev['cover_photo']): ?>
                     <div class="position-relative">
                        <img src="<?= ADMIN_UPLOAD_URL ?>/clubs/events/<?= fh($ev['cover_photo']) ?>" class="event-cover" alt="">
                        <?php if ($ev['event_date']): ?><span class="event-date-badge"><i class="fas fa-calendar me-1"></i><?= date('d M', strtotime($ev['event_date'])) ?></span><?php endif; ?>
                     </div>
                     <?php else: ?>
                     <div class="event-cover-ph"><i class="fas fa-calendar-day fa-2x text-white opacity-75"></i></div>
                     <?php endif; ?>
                     <div class="card-body p-3">
                        <h6 class="fw-bold mb-2"><?= fh($ev['title']) ?></h6>
                        <?php if ($ev['event_date']): ?><p class="small text-muted mb-1"><i class="fas fa-calendar me-1 text-success"></i><?= date('d M Y', strtotime($ev['event_date'])) ?><?php if ($ev['event_time']): ?>, <?= date('h:i A', strtotime($ev['event_time'])) ?><?php endif; ?></p><?php endif; ?>
                        <?php if ($ev['venue']): ?><p class="small text-muted mb-2"><i class="fas fa-map-marker-alt me-1 text-success"></i><?= fh($ev['venue']) ?></p><?php endif; ?>
                        <?php if ($ev['description']): ?><p class="small text-muted mb-3" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden"><?= fh($ev['description']) ?></p><?php endif; ?>
                        <?php if ($can_register): ?>
                        <a href="/club-event-detail.php?slug=<?= fh($ev['slug']) ?>" class="btn-register">Register Now</a>
                        <?php elseif ($is_full): ?>
                        <span class="btn-closed">Full</span>
                        <?php elseif ($deadline_passed): ?>
                        <span class="btn-closed">Registration Closed</span>
                        <?php endif; ?>
                     </div>
                  </div>
               </div>
               <?php endforeach; ?>
               </div>
               <?php endif; ?>
            </div>

            <!-- Gallery -->
            <div class="tab-pane fade" id="cd-gallery">
               <?php if (empty($photos)): ?>
               <p class="text-center text-muted py-4">No photos in gallery.</p>
               <?php else: ?>
               <div class="row g-3">
               <?php foreach ($photos as $ph): ?>
               <div class="col-6 col-md-4 col-lg-3">
                  <div class="pu-gallery-img">
                     <a href="<?= ADMIN_UPLOAD_URL ?>/clubs/gallery/<?= fh($ph['stored_name']) ?>" class="popup-image">
                        <img src="<?= ADMIN_UPLOAD_URL ?>/clubs/gallery/<?= fh($ph['stored_name']) ?>" alt="<?= fh($ph['caption'] ?? '') ?>" loading="lazy">
                     </a>
                  </div>
                  <?php if ($ph['caption']): ?><p class="small text-muted text-center mt-1 mb-0"><?= fh($ph['caption']) ?></p><?php endif; ?>
               </div>
               <?php endforeach; ?>
               </div>
               <?php endif; ?>
            </div>

            <!-- Activities -->
            <div class="tab-pane fade" id="cd-activities">
               <?php if (empty($activities)): ?>
               <p class="text-center text-muted py-4">No activities recorded.</p>
               <?php else: ?>
               <div class="row g-4">
               <?php foreach ($activities as $act): ?>
               <div class="col-sm-6 col-lg-4">
                  <div class="card border-0 shadow-sm h-100" style="border-radius:14px;overflow:hidden;">
                     <?php if ($act['photo']): ?>
                     <img src="<?= ADMIN_UPLOAD_URL ?>/clubs/activities/<?= fh($act['photo']) ?>" class="pu-activity-img" alt="">
                     <?php else: ?>
                     <div class="pu-activity-placeholder"><i class="fas fa-running fa-2x text-white opacity-75"></i></div>
                     <?php endif; ?>
                     <div class="card-body p-3">
                        <h6 class="fw-bold mb-1"><?= fh($act['title']) ?></h6>
                        <?php if ($act['activity_date']): ?><p class="small text-muted mb-2"><i class="fas fa-calendar me-1"></i><?= date('d M Y', strtotime($act['activity_date'])) ?></p><?php endif; ?>
                        <?php if ($act['description']): ?><p class="small text-muted mb-0"><?= nl2br(fh($act['description'])) ?></p><?php endif; ?>
                     </div>
                  </div>
               </div>
               <?php endforeach; ?>
               </div>
               <?php endif; ?>
            </div>

         </div>
      </div><!-- /.pu-tabs -->

   </div><!-- /.container -->

   <?php include __DIR__ . '/includes/footer.php'; ?>

   <?php include __DIR__ . \'/includes/scripts.php\'; ?>
   <script>
   // Activate gallery lightbox
   $(document).ready(function(){
      $('.popup-image').magnificPopup({ type: 'image', gallery: { enabled: true } });
   });
   // Hash-based tab activation
   const hash = window.location.hash.replace('#','');
   const tabMap = { members:'#cd-members', events:'#cd-events', gallery:'#cd-gallery', activities:'#cd-activities' };
   if (tabMap[hash]) {
      const el = document.querySelector('[data-bs-target="'+tabMap[hash]+'"]');
      if (el) new bootstrap.Tab(el).show();
   }
   </script>
</body>
</html>
