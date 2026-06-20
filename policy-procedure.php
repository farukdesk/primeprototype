<?php
require_once __DIR__ . '/includes/config.php';

$page_title = 'Policy & Procedure – Prime University';

// Fetch active sections from DB
$sections = [];
try {
    $db = front_db();
    if ($db) {
        $sections = $db->query(
            'SELECT id, title, content FROM policy_procedure_sections WHERE is_active = 1 ORDER BY sort_order, id'
        )->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    // Fail silently on public page
}
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title><?= fh($page_title) ?></title>
   <meta name="description" content="Read the academic policies and procedures of Prime University, including grading system, attendance requirements, semester rules, and registration validity.">
   <meta name="viewport" content="width=device-width, initial-scale=1">

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
   /* ── Policy & Procedure Page Styles ──────────────────────────────────────── */

   /* Hero */
   .pp-hero {
      background: linear-gradient(135deg, #0f2d5e 0%, #1e4fa3 60%, #2563eb 100%);
      padding: 90px 0 80px;
      position: relative;
      overflow: hidden;
   }
   .pp-hero::before {
      content: '';
      position: absolute;
      inset: 0;
      background: url('/assets/img/shape/breadcrumb-1-bg.png') center/cover no-repeat;
      opacity: .06;
   }
   .pp-hero::after {
      content: '';
      position: absolute;
      right: -100px;
      top: -100px;
      width: 420px;
      height: 420px;
      background: rgba(255,255,255,.05);
      border-radius: 50%;
      pointer-events: none;
   }
   .pp-hero-blob {
      position: absolute;
      left: -80px;
      bottom: -120px;
      width: 340px;
      height: 340px;
      background: rgba(255,255,255,.04);
      border-radius: 50%;
      pointer-events: none;
   }
   .pp-hero .breadcrumb-nav { font-size: .85rem; margin-bottom: 16px; }
   .pp-hero .breadcrumb-nav a { color: rgba(255,255,255,.7); text-decoration: none; transition: color .2s; }
   .pp-hero .breadcrumb-nav a:hover { color: #fff; }
   .pp-hero .breadcrumb-nav .sep { margin: 0 8px; color: rgba(255,255,255,.4); }
   .pp-hero .breadcrumb-nav span { color: rgba(255,255,255,.9); }
   .pp-hero h1 {
      font-size: clamp(2rem, 5vw, 3rem);
      font-weight: 800;
      color: #fff;
      line-height: 1.2;
      margin-bottom: 16px;
   }
   .pp-hero .tagline {
      font-size: 1.05rem;
      color: rgba(255,255,255,.8);
      max-width: 600px;
      line-height: 1.7;
   }
   .pp-hero-badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: rgba(255,255,255,.12);
      border: 1px solid rgba(255,255,255,.2);
      color: rgba(255,255,255,.9);
      font-size: .8rem;
      font-weight: 600;
      letter-spacing: .04em;
      text-transform: uppercase;
      padding: 6px 16px;
      border-radius: 100px;
      margin-bottom: 20px;
   }

   /* ── Main Layout ────────────────────────────────────────────────────────── */
   .pp-wrapper {
      background: #f4f6fb;
      padding: 60px 0 80px;
   }

   /* Sidebar nav */
   .pp-sidebar {
      position: sticky;
      top: 100px;
   }
   .pp-sidebar-card {
      background: #fff;
      border-radius: 16px;
      box-shadow: 0 4px 24px rgba(0,0,0,.07);
      overflow: hidden;
   }
   .pp-sidebar-header {
      background: linear-gradient(135deg, #1e4fa3, #2563eb);
      padding: 18px 22px;
      color: #fff;
   }
   .pp-sidebar-header h5 {
      font-size: .9rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .06em;
      margin: 0;
   }
   .pp-nav-list {
      list-style: none;
      margin: 0;
      padding: 12px 0;
   }
   .pp-nav-list li a {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 10px 22px;
      font-size: .85rem;
      font-weight: 500;
      color: #4b5563;
      text-decoration: none;
      border-left: 3px solid transparent;
      transition: all .2s;
   }
   .pp-nav-list li a:hover,
   .pp-nav-list li a.active {
      background: #eff4ff;
      color: #1e4fa3;
      border-left-color: #2563eb;
   }
   .pp-nav-list li a .nav-num {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 22px;
      height: 22px;
      background: #e8eeff;
      color: #2563eb;
      border-radius: 50%;
      font-size: .72rem;
      font-weight: 700;
      flex-shrink: 0;
      transition: background .2s, color .2s;
   }
   .pp-nav-list li a:hover .nav-num,
   .pp-nav-list li a.active .nav-num {
      background: #2563eb;
      color: #fff;
   }

   /* Content cards */
   .pp-section-card {
      background: #fff;
      border-radius: 16px;
      box-shadow: 0 4px 24px rgba(0,0,0,.07);
      margin-bottom: 28px;
      overflow: hidden;
      transition: box-shadow .25s, transform .25s;
      scroll-margin-top: 110px;
   }
   .pp-section-card:hover {
      box-shadow: 0 8px 36px rgba(37,99,235,.13);
      transform: translateY(-2px);
   }
   .pp-section-header {
      display: flex;
      align-items: center;
      gap: 16px;
      padding: 22px 28px;
      background: linear-gradient(90deg, #f0f5ff 0%, #fff 100%);
      border-bottom: 1px solid #e8eeff;
      cursor: pointer;
      user-select: none;
   }
   .pp-section-icon {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 44px;
      height: 44px;
      background: linear-gradient(135deg, #1e4fa3, #2563eb);
      color: #fff;
      border-radius: 12px;
      font-size: 1rem;
      flex-shrink: 0;
      box-shadow: 0 4px 12px rgba(37,99,235,.3);
   }
   .pp-section-header h2 {
      font-size: 1.05rem;
      font-weight: 700;
      color: #1e2d5a;
      margin: 0;
      flex: 1;
   }
   .pp-section-header .pp-toggle {
      color: #9ca3af;
      transition: transform .3s;
      font-size: .9rem;
   }
   .pp-section-header.collapsed .pp-toggle { transform: rotate(-90deg); }
   .pp-section-body {
      padding: 28px;
   }
   .pp-section-body p {
      font-size: .95rem;
      line-height: 1.8;
      color: #374151;
      margin-bottom: 14px;
   }
   .pp-section-body ol, .pp-section-body ul {
      font-size: .95rem;
      line-height: 1.85;
      color: #374151;
      padding-left: 20px;
   }
   .pp-section-body li { margin-bottom: 8px; }

   /* Tables inside sections */
   .pp-section-body table {
      width: 100%;
      border-collapse: collapse;
      font-size: .9rem;
      margin-top: 8px;
   }
   .pp-section-body table th {
      background: linear-gradient(90deg, #1e4fa3, #2563eb);
      color: #fff;
      padding: 12px 16px;
      font-weight: 600;
      text-align: left;
   }
   .pp-section-body table td {
      padding: 11px 16px;
      border-bottom: 1px solid #e9edf5;
      color: #374151;
   }
   .pp-section-body table tr:last-child td { border-bottom: none; }
   .pp-section-body table tr:nth-child(even) td { background: #f8faff; }
   .pp-section-body table tr:hover td { background: #eff4ff; }

   /* Empty state */
   .pp-empty {
      text-align: center;
      padding: 80px 20px;
      color: #9ca3af;
   }
   .pp-empty i { font-size: 3rem; margin-bottom: 16px; opacity: .4; }

   /* Progress bar accent */
   .pp-progress-strip {
      height: 4px;
      background: linear-gradient(90deg, #2563eb, #60a5fa);
   }

   /* Mobile tweaks */
   @media (max-width: 991.98px) {
      .pp-sidebar { position: static; margin-bottom: 32px; }
      .pp-section-body { padding: 20px; }
      .pp-section-header { padding: 16px 20px; }
      .pp-hero { padding: 70px 0 60px; }
   }
   @media (max-width: 575.98px) {
      .pp-section-body table { display: block; overflow-x: auto; -webkit-overflow-scrolling: touch; }
   }
   </style>
</head>
<body>

   <!-- preloader -->
   <div id="preloader">
      <div class="preloader">
         <span></span>
         <span></span>
      </div>
   </div>
   <!-- preloader end -->

   <div id="ball"></div>

   <button class="scroll-top scroll-to-target" data-target="html">
      <i class="far fa-angle-double-up"></i>
   </button>

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

   <!-- ── HERO ──────────────────────────────────────────────────────────────── -->
   <section class="pp-hero">
      <div class="pp-hero-blob"></div>
      <div class="container position-relative" style="z-index:2;">
         <nav class="breadcrumb-nav mb-20">
            <a href="<?= fh(SITE_URL) ?>/index.php">Home</a>
            <span class="sep">/</span>
            <span>Policy &amp; Procedure</span>
         </nav>
         <div class="pp-hero-badge wow fadeInUp" data-wow-delay=".05s">
            <i class="fas fa-file-contract"></i> Academic Regulations
         </div>
         <h1 class="wow fadeInUp" data-wow-delay=".1s">Policy &amp; Procedure</h1>
         <p class="tagline wow fadeInUp" data-wow-delay=".2s">
            Academic policies, grading guidelines, attendance rules, and<br class="d-none d-lg-block">
            registration procedures for Prime University students.
         </p>
      </div>
   </section>
   <div class="pp-progress-strip"></div>
   <!-- ── HERO END ─────────────────────────────────────────────────────────── -->

   <!-- ── CONTENT ───────────────────────────────────────────────────────────── -->
   <section class="pp-wrapper">
      <div class="container">
         <?php if (empty($sections)): ?>
            <div class="pp-empty wow fadeIn">
               <i class="fas fa-file-alt d-block"></i>
               <h4 style="color:#6b7280;">No policy sections available.</h4>
               <p>Please check back later.</p>
            </div>
         <?php else: ?>
         <div class="row g-4">

            <!-- ── Sidebar ── -->
            <div class="col-lg-3 col-xl-3 d-none d-lg-block">
               <aside class="pp-sidebar wow fadeInLeft" data-wow-delay=".1s">
                  <div class="pp-sidebar-card">
                     <div class="pp-sidebar-header">
                        <h5><i class="fas fa-list me-2"></i>Quick Navigation</h5>
                     </div>
                     <ul class="pp-nav-list">
                        <?php foreach ($sections as $i => $s): ?>
                        <li>
                           <a href="#section-<?= $s['id'] ?>" class="pp-scroll-link <?= $i === 0 ? 'active' : '' ?>">
                              <span class="nav-num"><?= $i + 1 ?></span>
                              <?= fh($s['title']) ?>
                           </a>
                        </li>
                        <?php endforeach; ?>
                     </ul>
                  </div>
               </aside>
            </div>

            <!-- ── Sections ── -->
            <div class="col-lg-9 col-xl-9">
               <?php
               $icons = [
                  'fas fa-calendar-alt',
                  'fas fa-language',
                  'fas fa-calendar-minus',
                  'fas fa-book-open',
                  'fas fa-undo-alt',
                  'fas fa-star-half-alt',
                  'fas fa-exclamation-circle',
                  'fas fa-clipboard-check',
                  'fas fa-clock',
               ];
               foreach ($sections as $i => $s):
                  $icon   = $icons[$i] ?? 'fas fa-file-alt';
                  $delay  = '.1' . ($i % 5) . 's';
               ?>
               <div class="pp-section-card wow fadeInUp" data-wow-delay="<?= $delay ?>" id="section-<?= $s['id'] ?>">
                  <div class="pp-section-header" data-bs-toggle="collapse" data-bs-target="#pp-body-<?= $s['id'] ?>" aria-expanded="true">
                     <div class="pp-section-icon">
                        <i class="<?= $icon ?>"></i>
                     </div>
                     <h2><?= fh($s['title']) ?></h2>
                     <i class="fas fa-chevron-down pp-toggle"></i>
                  </div>
                  <div class="collapse show" id="pp-body-<?= $s['id'] ?>">
                     <div class="pp-section-body">
                        <?= $s['content'] ?>
                     </div>
                  </div>
               </div>
               <?php endforeach; ?>
            </div>

         </div><!-- /.row -->
         <?php endif; ?>
      </div>
   </section>
   <!-- ── CONTENT END ─────────────────────────────────────────────────────── -->

   <?php include __DIR__ . '/includes/footer.php'; ?>

   <?php include __DIR__ . '/includes/scripts.php'; ?>

   <script>
   (function () {
      'use strict';

      // ── Collapse toggle icon rotation ──────────────────────────────────────
      document.querySelectorAll('.pp-section-header').forEach(function (header) {
         var targetId = header.getAttribute('data-bs-target');
         var pane = document.querySelector(targetId);
         if (!pane) return;

         function updateIcon() {
            if (pane.classList.contains('show')) {
               header.classList.remove('collapsed');
            } else {
               header.classList.add('collapsed');
            }
         }

         pane.addEventListener('shown.bs.collapse',  updateIcon);
         pane.addEventListener('hidden.bs.collapse', updateIcon);
         updateIcon();
      });

      // ── Smooth scroll for sidebar nav links ────────────────────────────────
      document.querySelectorAll('.pp-scroll-link').forEach(function (link) {
         link.addEventListener('click', function (e) {
            var href = this.getAttribute('href');
            if (href && href.startsWith('#')) {
               e.preventDefault();
               var target = document.querySelector(href);
               if (target) {
                  var offset = 100;
                  var top = target.getBoundingClientRect().top + window.pageYOffset - offset;
                  window.scrollTo({ top: top, behavior: 'smooth' });
               }
            }
         });
      });

      // ── Highlight active sidebar link on scroll ─────────────────────────────
      var sidebarLinks = document.querySelectorAll('.pp-scroll-link');
      var sectionCards = document.querySelectorAll('.pp-section-card[id]');

      function onScroll() {
         var scrollY = window.pageYOffset + 120;
         var active  = null;
         sectionCards.forEach(function (card) {
            if (card.offsetTop <= scrollY) {
               active = card.id;
            }
         });
         sidebarLinks.forEach(function (link) {
            var href = link.getAttribute('href').replace('#', '');
            if (href === active) {
               link.classList.add('active');
            } else {
               link.classList.remove('active');
            }
         });
      }

      window.addEventListener('scroll', onScroll, { passive: true });
      onScroll();

   })();
   </script>

</body>
</html>
