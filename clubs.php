<?php
require_once __DIR__ . '/includes/config.php';

$page_title = 'Student Clubs – Prime University';

$clubs  = [];
$depts  = [];

try {
    $db = front_db();
    if ($db) {
        $clubs = $db->query(
            "SELECT c.*,
                    d.name AS dept_name,
                    p.name AS program_name,
                    (SELECT COUNT(*) FROM club_members cm WHERE cm.club_id = c.id) AS member_count,
                    (SELECT COUNT(*) FROM club_events  ce WHERE ce.club_id = c.id AND ce.is_published = 1 AND (ce.event_date IS NULL OR ce.event_date >= CURDATE())) AS upcoming_events
             FROM clubs c
             LEFT JOIN dept_departments d ON d.id = c.dept_id
             LEFT JOIN dept_academic_programs p ON p.id = c.program_id
             WHERE c.is_active = 1
             ORDER BY c.name ASC"
        )->fetchAll();

        $depts = $db->query("SELECT DISTINCT d.id, d.name FROM dept_departments d INNER JOIN clubs c ON c.dept_id = d.id WHERE c.is_active = 1 ORDER BY d.name")->fetchAll();
    }
} catch (Throwable $e) { /* silently fall through */ }
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title><?= fh($page_title) ?></title>
   <meta name="description" content="Explore student clubs at Prime University – join a community that matches your passion.">
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
   /* ── Clubs Page Styles ───────────────────────────────────── */
   .pu-clubs-hero {
      background: linear-gradient(135deg, #0f2027 0%, #203a43 50%, #1abc9c 100%);
      padding: 90px 0 70px;
      position: relative;
      overflow: hidden;
   }
   .pu-clubs-hero::before {
      content: '';
      position: absolute; inset: 0;
      background: url('/assets/img/shape/breadcrumb-1-bg.png') center/cover no-repeat;
      opacity: .06;
   }
   .pu-clubs-hero h1 { font-size: clamp(2rem,5vw,3rem); font-weight: 800; color: #fff; margin-bottom: 12px; }
   .pu-clubs-hero .tagline { font-size: 1.05rem; color: rgba(255,255,255,.8); }
   .pu-clubs-hero .breadcrumb-nav a, .pu-clubs-hero .breadcrumb-nav span { color: rgba(255,255,255,.7); font-size: .85rem; }
   .pu-clubs-hero .breadcrumb-nav .sep { margin: 0 8px; color: rgba(255,255,255,.35); }

   /* Filter bar */
   .pu-filter-bar { background: #fff; border-radius: 16px; box-shadow: 0 4px 24px rgba(0,0,0,.08); padding: 24px 28px; margin-top: -44px; position: relative; z-index: 10; margin-bottom: 48px; }
   .pu-filter-bar .form-control, .pu-filter-bar .form-select { border-radius: 10px; border-color: #e2e8f0; padding: 12px 16px; font-size: .92rem; }
   .pu-filter-bar .btn-filter { background: #1abc9c; color: #fff; border-radius: 10px; padding: 12px 28px; font-weight: 600; border: none; transition: background .2s; }
   .pu-filter-bar .btn-filter:hover { background: #16a085; }

   /* Club card */
   .pu-club-card { border: none; border-radius: 18px; box-shadow: 0 4px 20px rgba(0,0,0,.07); transition: transform .25s, box-shadow .25s; overflow: hidden; height: 100%; }
   .pu-club-card:hover { transform: translateY(-6px); box-shadow: 0 12px 36px rgba(0,0,0,.13); }
   .pu-club-card .club-cover { height: 180px; object-fit: cover; width: 100%; }
   .pu-club-card .club-cover-placeholder { height: 180px; background: linear-gradient(135deg,#1abc9c,#16a085); display:flex; align-items:center; justify-content:center; }
   .pu-club-card .club-logo { width: 60px; height: 60px; border-radius: 50%; border: 3px solid #fff; box-shadow: 0 2px 8px rgba(0,0,0,.15); object-fit: cover; margin-top: -30px; position: relative; z-index: 2; background: #fff; }
   .pu-club-card .club-logo-placeholder { width: 60px; height: 60px; border-radius: 50%; border: 3px solid #fff; box-shadow: 0 2px 8px rgba(0,0,0,.15); background: #e8f5e9; margin-top: -30px; position: relative; z-index: 2; display: flex; align-items:center; justify-content:center; }
   .pu-club-card .card-body { padding: 16px 20px 20px; }
   .pu-club-card .club-name { font-size: 1.1rem; font-weight: 700; color: #1a2e5a; margin-bottom: 4px; }
   .pu-club-card .dept-badge { font-size: .75rem; background: #f0faf8; color: #16a085; border-radius: 20px; padding: 3px 10px; font-weight: 600; display: inline-block; margin-bottom: 10px; }
   .pu-club-card .goal-text { font-size: .875rem; color: #6b7280; line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
   .pu-club-card .meta-row { display: flex; gap: 16px; margin-top: 14px; padding-top: 12px; border-top: 1px solid #f1f5f9; font-size: .8rem; color: #6b7280; }
   .pu-club-card .meta-row i { color: #1abc9c; margin-right: 4px; }
   .pu-club-card .btn-detail { background: #1abc9c; color: #fff; border-radius: 8px; padding: 9px 20px; font-size: .875rem; font-weight: 600; text-decoration: none; transition: background .2s; display: inline-block; margin-top: 14px; }
   .pu-club-card .btn-detail:hover { background: #16a085; color: #fff; }

   /* No results */
   .pu-no-results { text-align: center; padding: 80px 0; color: #9ca3af; }
   .pu-no-results i { font-size: 3rem; display: block; margin-bottom: 16px; opacity: .35; }

   /* Stats strip */
   .pu-stat-strip { background: linear-gradient(135deg,#1abc9c,#16a085); padding: 40px 0; margin: 60px 0; }
   .pu-stat-strip .stat-num { font-size: 2.5rem; font-weight: 800; color: #fff; }
   .pu-stat-strip .stat-label { font-size: .9rem; color: rgba(255,255,255,.8); }
   </style>
</head>
<body id="body" class="it-magic-cursor">
   <div id="preloader"><div class="preloader"><span></span><span></span></div></div>
   <div id="magic-cursor"><div id="ball"></div></div>
   <button class="scroll-top scroll-to-target" data-target="html"><i class="fa fa-angle-up"></i></button>
   <!-- Offcanvas mobile menu (same as other pages) -->
   <div class="offcanvas-overlay"></div>
   <div class="body-overlay"></div>

   <header class="it-header-height">
      <?php include __DIR__ . '/includes/header-top.php'; ?>
      <?php include __DIR__ . '/includes/nav-menu.php'; ?>
   </header>

   <!-- Hero -->
   <section class="pu-clubs-hero">
      <div class="container position-relative">
         <nav class="breadcrumb-nav mb-3">
            <a href="/">Home</a><span class="sep">/</span>
            <span>Clubs</span>
         </nav>
         <h1>Student Clubs</h1>
         <p class="tagline">Discover vibrant student communities at Prime University. Find a club that sparks your passion.</p>
      </div>
   </section>

   <!-- Filter Bar -->
   <div class="container">
      <div class="pu-filter-bar">
         <form method="get" id="filterForm">
            <div class="row g-3 align-items-end">
               <div class="col-md-5 col-lg-5">
                  <input type="text" name="q" id="filterSearch" class="form-control" placeholder="Search clubs…" value="<?= fh($_GET['q'] ?? '') ?>">
               </div>
               <div class="col-md-4 col-lg-4">
                  <select name="dept" id="filterDept" class="form-select">
                     <option value="">All Departments</option>
                     <?php foreach ($depts as $d): ?>
                     <option value="<?= (int)$d['id'] ?>" <?= ($_GET['dept'] ?? '') == $d['id'] ? 'selected' : '' ?>><?= fh($d['name']) ?></option>
                     <?php endforeach; ?>
                  </select>
               </div>
               <div class="col-md-3 col-lg-3">
                  <button type="submit" class="btn-filter w-100"><i class="fas fa-search me-2"></i>Search</button>
               </div>
            </div>
         </form>
      </div>
   </div>

   <!-- Clubs Grid -->
   <div class="container pb-80">
      <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
         <h2 class="h4 fw-bold mb-0 text-dark"><?= count($clubs) ?> Club<?= count($clubs) != 1 ? 's' : '' ?></h2>
      </div>

      <?php
      // Client-side filtering: filter from PHP then render all, use JS too
      $q       = trim($_GET['q']   ?? '');
      $dept_flt = (int)($_GET['dept'] ?? 0);
      $visible = array_filter($clubs, function($c) use ($q, $dept_flt) {
          if ($dept_flt && $c['dept_id'] != $dept_flt) return false;
          if ($q && stripos($c['name'],$q)===false && stripos($c['goal']??'',$q)===false) return false;
          return true;
      });
      ?>

      <?php if (empty($visible)): ?>
      <div class="pu-no-results">
         <i class="fas fa-search"></i>
         <p class="fs-5">No clubs found matching your search.</p>
         <a href="/clubs.php" class="btn btn-outline-secondary mt-2">Clear Filters</a>
      </div>
      <?php else: ?>
      <div class="row g-4" id="clubsGrid">
         <?php foreach ($visible as $c): ?>
         <div class="col-sm-6 col-lg-4 col-xl-3 club-item">
            <div class="pu-club-card card">
               <?php if ($c['cover_photo']): ?>
               <img src="<?= ADMIN_UPLOAD_URL ?>/clubs/covers/<?= fh($c['cover_photo']) ?>" class="club-cover" alt="<?= fh($c['name']) ?>">
               <?php else: ?>
               <div class="club-cover-placeholder"><i class="fas fa-users fa-3x text-white opacity-50"></i></div>
               <?php endif; ?>
               <div class="card-body">
                  <div class="d-flex align-items-center gap-3 mb-2">
                     <?php if ($c['logo']): ?>
                     <img src="<?= ADMIN_UPLOAD_URL ?>/clubs/logos/<?= fh($c['logo']) ?>" class="club-logo" alt="">
                     <?php else: ?>
                     <div class="club-logo-placeholder"><i class="fas fa-users text-success"></i></div>
                     <?php endif; ?>
                     <div class="flex-grow-1">
                        <div class="club-name"><?= fh($c['name']) ?></div>
                        <?php if ($c['dept_name']): ?>
                        <span class="dept-badge"><?= fh($c['dept_name']) ?></span>
                        <?php endif; ?>
                     </div>
                  </div>
                  <?php if ($c['goal']): ?>
                  <p class="goal-text"><?= fh($c['goal']) ?></p>
                  <?php endif; ?>
                  <div class="meta-row">
                     <span><i class="fas fa-users"></i><?= $c['member_count'] ?> Members</span>
                     <?php if ($c['upcoming_events'] > 0): ?>
                     <span><i class="fas fa-calendar"></i><?= $c['upcoming_events'] ?> Upcoming</span>
                     <?php endif; ?>
                  </div>
                  <a href="/club-detail.php?slug=<?= fh($c['slug']) ?>" class="btn-detail">View Club →</a>
               </div>
            </div>
         </div>
         <?php endforeach; ?>
      </div>
      <?php endif; ?>
   </div>

   <!-- Stats Strip -->
   <?php if (!empty($clubs)): ?>
   <div class="pu-stat-strip">
      <div class="container">
         <div class="row text-center g-4">
            <?php
            $total_members  = array_sum(array_column($clubs, 'member_count'));
            $total_events   = array_sum(array_column($clubs, 'upcoming_events'));
            ?>
            <div class="col-6 col-md-3"><div class="stat-num"><?= count($clubs) ?></div><div class="stat-label">Active Clubs</div></div>
            <div class="col-6 col-md-3"><div class="stat-num"><?= $total_members ?></div><div class="stat-label">Club Members</div></div>
            <div class="col-6 col-md-3"><div class="stat-num"><?= $total_events ?></div><div class="stat-label">Upcoming Events</div></div>
            <div class="col-6 col-md-3"><div class="stat-num"><?= count($depts) ?></div><div class="stat-label">Departments</div></div>
         </div>
      </div>
   </div>
   <?php endif; ?>

   <?php include __DIR__ . '/includes/footer.php'; ?>

   <?php include __DIR__ . '/includes/scripts.php'; ?>
</body>
</html>
