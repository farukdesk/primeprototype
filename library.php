<?php
require_once __DIR__ . '/includes/config.php';

$page_title = 'Library – Prime University';

// Fetch library data (gracefully fail if tables don't exist yet)
$lib_settings = [];
$librarians   = [];
$books        = [];
$digital      = [];
$categories   = [];
$search_q     = trim($_GET['q'] ?? '');
$search_cat   = (int)($_GET['cat'] ?? 0);

try {
    $db = front_db();
    if ($db) {
        // Settings
        $rows = $db->query('SELECT setting_key, setting_val FROM library_settings')->fetchAll();
        foreach ($rows as $r) {
            $lib_settings[$r['setting_key']] = $r['setting_val'];
        }

        // Librarians
        $librarians = $db->query(
            'SELECT * FROM library_librarians WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
        )->fetchAll();

        // Featured / recent books (with search)
        $book_sql    = 'SELECT b.*, c.name AS category_name
                        FROM library_books b
                        LEFT JOIN library_categories c ON c.id = b.category_id
                        WHERE 1';
        $book_params = [];
        if ($search_q !== '') {
            $book_sql    .= ' AND (b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ?)';
            $like = '%' . $search_q . '%';
            $book_params  = array_merge($book_params, [$like, $like, $like]);
        }
        if ($search_cat > 0) {
            $book_sql    .= ' AND b.category_id = ?';
            $book_params[] = $search_cat;
        }
        $book_sql .= ' ORDER BY b.created_at DESC LIMIT 12';
        $bstmt = $db->prepare($book_sql);
        $bstmt->execute($book_params);
        $books = $bstmt->fetchAll();

        // Categories for filter
        $categories = $db->query(
            'SELECT id, name FROM library_categories WHERE parent_id IS NULL OR parent_id = 0 ORDER BY name ASC'
        )->fetchAll();

        // Public digital resources
        $digital = $db->query(
            "SELECT * FROM library_digital_resources
             WHERE is_active = 1 AND access_level = 'Public'
             ORDER BY created_at DESC LIMIT 6"
        )->fetchAll();
    }
} catch (Throwable $e) {
    // Tables may not exist yet; silently fall through
}

$lib_name    = $lib_settings['lib_name']        ?? 'Prime University Central Library';
$lib_address = $lib_settings['lib_address']     ?? '114, 116 Mazar Rd, Dhaka 1216';
$lib_room    = $lib_settings['lib_room']         ?? 'Block C, Room 101–105';
$lib_location= $lib_settings['lib_location']    ?? '1st Floor, Main Academic Building';
$lib_phone   = $lib_settings['lib_phone']        ?? '+880-2-9671074';
$lib_email   = $lib_settings['lib_email']        ?? 'library@primeuniversity.ac.bd';
$lib_hours   = $lib_settings['lib_hours']        ?? 'Sun–Thu: 8 AM–9 PM';
$lib_desc    = $lib_settings['lib_description']  ?? 'A modern academic library serving students, faculty and researchers.';
$lib_website = $lib_settings['lib_website']      ?? '#';

$resource_icons = [
    'E-Book'          => 'fas fa-book',
    'Journal'         => 'fas fa-newspaper',
    'Research Paper'  => 'fas fa-scroll',
    'Thesis'          => 'fas fa-graduation-cap',
    'Dissertation'    => 'fas fa-file-alt',
    'Other'           => 'fas fa-file',
];
?>
<!doctype html>
<html class="no-js" lang="en">

<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title><?= fh($page_title) ?></title>
   <meta name="description" content="Explore the <?= fh($lib_name) ?> – books, digital resources, and more.">
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
      /* ── Hero ─────────────────────────────────────────────── */
      .lib-hero {
         background: linear-gradient(135deg, #1a1f36 0%, #2d3561 60%, #4f8ef7 100%);
         color: #fff;
         padding: 80px 0 60px;
         position: relative;
         z-index: 10;
         overflow: visible;
      }
      .lib-hero h1 {
         font-size: 2.8rem; font-weight: 800; line-height: 1.2;
         color: #ffffff;
         text-shadow: 0 2px 12px rgba(0,0,0,.45);
      }
      .lib-hero p  { font-size: 1.1rem; color: rgba(255,255,255,.95); }
      .lib-hero .hero-meta span { color: #fff; font-size: .85rem; }

      /* ── Search box ────────────────────────────────────────── */
      .lib-search-box {
         background: rgba(255,255,255,.15);
         border-radius: 16px;
         padding: 28px 32px;
         border: 1px solid rgba(255,255,255,.2);
         position: relative;
         z-index: 100;
      }
      .lib-search-box input,
      .lib-search-box select { border-radius: 8px !important; height: 48px; font-size: .95rem; }
      .lib-search-box .btn-search {
         height: 48px; padding: 0 28px; background: #f7a91e; border: none;
         border-radius: 8px; font-weight: 600; color: #fff; white-space: nowrap;
         width: 100%;
      }
      .lib-search-box .btn-search:hover { background: #e09700; }

      /* Fix nice-select dropdown staying above the books section */
      .lib-search-box .nice-select { height: 48px; line-height: 48px; border-radius: 8px !important; width: 100%; }
      .lib-search-box .nice-select .list { z-index: 9999; max-height: 260px; overflow-y: auto; }

      /* ── Stat strip ────────────────────────────────────────── */
      .stat-strip { background: #f7a91e; padding: 20px 0; }
      .stat-strip .stat-item { text-align: center; color: #1a1f36; padding: 8px 4px; }
      .stat-strip .stat-item .num { font-size: 2rem; font-weight: 700; line-height: 1; }
      .stat-strip .stat-item .lbl { font-size: .8rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; margin-top: 4px; }

      /* ── Section headings ──────────────────────────────────── */
      .section-heading { margin-bottom: 40px; }
      .section-heading h2 { font-size: 2rem; font-weight: 700; color: #1a1f36; }
      .section-heading p  { color: #6b7280; margin-bottom: 0; }

      /* ── Book cards ────────────────────────────────────────── */
      .book-card {
         border: 1px solid #e8eaf0; border-radius: 14px; background: #fff;
         overflow: hidden; transition: box-shadow .2s, transform .2s; height: 100%;
      }
      .book-card:hover { box-shadow: 0 8px 28px rgba(0,0,0,.12); transform: translateY(-4px); }
      .book-card .book-cover {
         height: 180px; background: #f0f3fa;
         display: flex; align-items: center; justify-content: center;
         color: #c5cbe8; font-size: 3rem;
      }
      .book-card .book-cover img { width: 100%; height: 100%; object-fit: cover; }
      .book-card .book-body { padding: 16px; }
      .book-card .book-title { font-size: .95rem; font-weight: 600; color: #1a1f36; margin-bottom: 4px;
         display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
      .book-card .book-author { font-size: .8rem; color: #6b7280; margin-bottom: 8px; }
      .availability-badge { font-size: .72rem; padding: 3px 8px; border-radius: 20px; }
      .avail-yes { background: #d1fae5; color: #065f46; }
      .avail-no  { background: #fee2e2; color: #991b1b; }

      /* ── Info cards ────────────────────────────────────────── */
      .info-card {
         background: #fff; border-radius: 14px; border: 1px solid #e8eaf0;
         padding: 28px; height: 100%;
      }
      .info-card .icon-box {
         width: 52px; height: 52px; border-radius: 12px; background: #eef2ff;
         color: #4f8ef7; display: flex; align-items: center; justify-content: center;
         font-size: 1.4rem; margin-bottom: 16px;
      }

      /* ── Librarian cards ───────────────────────────────────── */
      .librarian-card {
         text-align: center; background: #fff; border: 1px solid #e8eaf0;
         border-radius: 14px; padding: 28px 20px; height: 100%;
         transition: box-shadow .2s;
      }
      .librarian-card:hover { box-shadow: 0 6px 24px rgba(0,0,0,.1); }
      .lib-avatar {
         width: 90px; height: 90px; border-radius: 50%; object-fit: cover;
         margin: 0 auto 16px; display: block;
      }
      .lib-avatar-initials {
         width: 90px; height: 90px; border-radius: 50%; background: #4f8ef7;
         color: #fff; font-size: 2rem; font-weight: 700;
         display: flex; align-items: center; justify-content: center;
         margin: 0 auto 16px;
      }
      .librarian-card h5 { font-size: 1rem; font-weight: 700; color: #1a1f36; margin-bottom: 4px; }
      .librarian-card .designation { font-size: .83rem; color: #4f8ef7; font-weight: 600; margin-bottom: 10px; }
      .librarian-card .meta { font-size: .8rem; color: #6b7280; margin-bottom: 4px; }

      /* ── Digital resource cards ────────────────────────────── */
      .digital-card {
         background: #fff; border: 1px solid #e8eaf0; border-radius: 14px;
         padding: 22px; display: flex; gap: 16px; align-items: flex-start;
         transition: box-shadow .2s; height: 100%;
      }
      .digital-card:hover { box-shadow: 0 4px 18px rgba(0,0,0,.08); }
      .digital-card .dc-icon {
         width: 48px; height: 48px; border-radius: 10px; background: #eef2ff;
         color: #4f8ef7; flex-shrink: 0;
         display: flex; align-items: center; justify-content: center; font-size: 1.3rem;
      }
      .digital-card h6 { font-size: .9rem; font-weight: 600; margin-bottom: 4px; color: #1a1f36; }
      .digital-card p  { font-size: .78rem; color: #6b7280; margin: 0; }

      /* ── Responsive overrides ──────────────────────────────── */
      @media (max-width: 991.98px) {
         .lib-hero h1 { font-size: 2.2rem; }
         .lib-search-box { margin-top: 32px; }
      }
      @media (max-width: 767.98px) {
         .lib-hero { padding: 50px 0 40px; }
         .lib-hero h1 { font-size: 1.85rem; }
         .lib-hero p  { font-size: 1rem; }
         .lib-search-box { padding: 20px; }
         .section-heading h2 { font-size: 1.6rem; }
         .stat-strip .stat-item .num { font-size: 1.5rem; }
         .pt-100 { padding-top: 60px !important; }
         .pt-80  { padding-top: 50px !important; }
         .pb-80  { padding-bottom: 50px !important; }
         .pb-100 { padding-bottom: 60px !important; }
      }
      @media (max-width: 575.98px) {
         .lib-hero { padding: 40px 0 32px; }
         .lib-hero h1 { font-size: 1.55rem; }
         .lib-search-box { padding: 16px; border-radius: 12px; }
         .lib-search-box input,
         .lib-search-box select,
         .lib-search-box .nice-select { height: 44px; line-height: 44px; }
         .lib-search-box .btn-search { height: 44px; }
         .stat-strip .stat-item .num { font-size: 1.3rem; }
         .stat-strip .stat-item .lbl { font-size: .7rem; }
         .section-heading { margin-bottom: 28px; }
         .section-heading h2 { font-size: 1.45rem; }
         .info-card { padding: 20px; }
         .pt-100 { padding-top: 48px !important; }
         .pt-80  { padding-top: 40px !important; }
         .pb-80  { padding-bottom: 40px !important; }
         .pb-100 { padding-bottom: 48px !important; }
         .book-card .book-cover { height: 140px; font-size: 2.2rem; }
      }
   </style>
</head>

<body id="body" class="it-magic-cursor">

   <!-- preloader -->
   <div id="preloader">
      <div class="preloader"><span></span><span></span></div>
   </div>

   <div id="magic-cursor"><div id="ball"></div></div>

   <!-- back to top -->
   <button class="scroll-top scroll-to-target" data-target="html">
      <i class="far fa-angle-double-up"></i>
   </button>

   <!-- search popup -->
   <div class="search-popup">
      <button class="close-search"><span class="flaticon-multiply"><i class="fal fa-times"></i></span></button>
      <form method="post" action="#">
         <div class="form-group">
            <input type="search" name="search-field" value="" placeholder="Search Here" required="">
            <button type="submit"><i class="fal fa-search"></i></button>
         </div>
      </form>
   </div>

   <!-- offcanvas -->
   <div class="it-offcanvas-area">
      <div class="itoffcanvas">
         <div class="itoffcanvas__close-btn"><button class="close-btn"><i class="fal fa-times"></i></button></div>
         <div class="itoffcanvas__logo">
            <a href="<?= fh(SITE_URL) ?>/index.php">
               <img src="assets/img/logo/logo-black.png" alt="Prime University">
            </a>
         </div>
         <div class="it-menu-mobile d-xl-none"></div>
         <div class="itoffcanvas__info">
            <h3 class="offcanva-title">Get In Touch</h3>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon"><a href="#"><i class="fal fa-envelope"></i></a></div>
               <div class="itoffcanvas__info-address">
                  <span>Email</span>
                  <a href="mailto:<?= fh($lib_email) ?>"><?= fh($lib_email) ?></a>
               </div>
            </div>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon"><a href="#"><i class="fal fa-phone-alt"></i></a></div>
               <div class="itoffcanvas__info-address">
                  <span>Phone</span>
                  <a href="tel:<?= fh(sanitize_phone($lib_phone)) ?>"><?= fh($lib_phone) ?></a>
               </div>
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

   <?php include __DIR__ . '/includes/news-ticker.php'; ?>

   <!-- ── Hero ─────────────────────────────────────────── -->
   <section class="lib-hero">
      <div class="container">
         <div class="row align-items-center gy-4">
            <div class="col-lg-6">
               <div class="mb-3">
                  <span style="background:rgba(247,169,30,.25);color:#f7c948;padding:6px 16px;border-radius:20px;font-size:.82rem;font-weight:600;">
                     <i class="fas fa-book-open me-2"></i>Prime University Library
                  </span>
               </div>
               <h1><?= fh($lib_name) ?></h1>
               <p class="mt-3 mb-4"><?= fh($lib_desc) ?></p>
               <div class="d-flex flex-wrap gap-3 hero-meta" style="font-size:.85rem;">
                  <span><i class="fas fa-map-marker-alt me-2" style="color:#f7a91e;"></i><?= fh($lib_room) ?></span>
                  <span><i class="fas fa-clock me-2" style="color:#f7a91e;"></i>Open Today</span>
               </div>
            </div>
            <div class="col-lg-6">
               <div class="lib-search-box">
                  <h5 class="text-white mb-20" style="font-weight:600;font-size:1.05rem;">
                     <i class="fas fa-search me-2" style="color:#f7a91e;"></i>Search the Catalogue
                  </h5>
                  <form method="GET" action="library.php">
                     <div class="row g-2">
                        <div class="col-12">
                           <input type="text" name="q" class="form-control" placeholder="Title, author, ISBN…"
                                  value="<?= fh($search_q) ?>">
                        </div>
                        <div class="col-12">
                           <select name="cat" class="form-select">
                              <option value="0">All Categories</option>
                              <?php foreach ($categories as $cat): ?>
                              <option value="<?= (int)$cat['id'] ?>" <?= $search_cat === (int)$cat['id'] ? 'selected' : '' ?>>
                                 <?= fh($cat['name']) ?>
                              </option>
                              <?php endforeach; ?>
                           </select>
                        </div>
                        <div class="col-12">
                           <button type="submit" class="btn-search">
                              <i class="fas fa-search me-2"></i>Search the Catalogue
                           </button>
                        </div>
                     </div>
                  </form>
               </div>
            </div>
         </div>
      </div>
   </section>

   <!-- ── Stats strip ───────────────────────────────────── -->
   <?php
   $total_books  = 0; $total_copies = 0; $avail_copies = 0; $total_members = 0; $total_digital = 0;
   try {
      $db2 = front_db();
      if ($db2) {
         $total_books   = (int)$db2->query('SELECT COUNT(*) FROM library_books')->fetchColumn();
         $total_copies  = (int)$db2->query('SELECT COUNT(*) FROM library_book_copies')->fetchColumn();
         $avail_copies  = (int)$db2->query('SELECT COUNT(*) FROM library_book_copies WHERE is_available = 1')->fetchColumn();
         $total_members = (int)$db2->query('SELECT COUNT(*) FROM library_members WHERE is_active = 1')->fetchColumn();
         $total_digital = (int)$db2->query("SELECT COUNT(*) FROM library_digital_resources WHERE is_active=1 AND access_level='Public'")->fetchColumn();
      }
   } catch (Throwable $e) {}
   ?>
   <div class="stat-strip">
      <div class="container">
         <div class="row g-3 justify-content-center">
            <div class="col-6 col-md-3 col-lg-2">
               <div class="stat-item"><div class="num"><?= number_format($total_books) ?></div><div class="lbl">Books</div></div>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
               <div class="stat-item"><div class="num"><?= number_format($total_copies) ?></div><div class="lbl">Copies</div></div>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
               <div class="stat-item"><div class="num"><?= number_format($avail_copies) ?></div><div class="lbl">Available</div></div>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
               <div class="stat-item"><div class="num"><?= number_format($total_members) ?></div><div class="lbl">Members</div></div>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
               <div class="stat-item"><div class="num"><?= number_format($total_digital) ?></div><div class="lbl">E-Resources</div></div>
            </div>
         </div>
      </div>
   </div>

   <!-- ── Books catalogue ───────────────────────────────── -->
   <section class="pt-100 pb-80">
      <div class="container">
         <div class="row">
            <div class="col-12 section-heading text-center">
               <?php if ($search_q !== '' || $search_cat > 0): ?>
               <h2>Search Results</h2>
               <p>
                  <?= empty($books) ? 'No books matched your search.' : count($books) . ' book(s) found.' ?>
                  <a href="library.php" class="ms-2" style="font-size:.85rem;">Clear filters</a>
               </p>
               <?php else: ?>
               <h2>Recently Added Books</h2>
               <p>Browse the latest additions to our collection.</p>
               <?php endif; ?>
            </div>
         </div>

         <?php if (!empty($books)): ?>
         <div class="row g-4">
            <?php foreach ($books as $book):
               $cover_url = !empty($book['cover_image'])
                  ? fh(ADMIN_UPLOAD_URL . '/library/covers/' . $book['cover_image'])
                  : null;
               $avail = (int)$book['available_copies'] > 0;
            ?>
            <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-6">
               <div class="book-card">
                  <div class="book-cover">
                     <?php if ($cover_url): ?>
                     <img src="<?= $cover_url ?>" alt="<?= fh($book['title']) ?>">
                     <?php else: ?>
                     <i class="fas fa-book"></i>
                     <?php endif; ?>
                  </div>
                  <div class="book-body">
                     <div class="book-title" title="<?= fh($book['title']) ?>"><?= fh($book['title']) ?></div>
                     <div class="book-author"><?= fh($book['author']) ?></div>
                     <?php if ($book['category_name']): ?>
                     <div style="font-size:.75rem;color:#9ca3af;margin-bottom:8px;">
                        <i class="fas fa-tag me-1"></i><?= fh($book['category_name']) ?>
                     </div>
                     <?php endif; ?>
                     <div class="d-flex justify-content-between align-items-center">
                        <span class="availability-badge <?= $avail ? 'avail-yes' : 'avail-no' ?>">
                           <?= $avail ? 'Available' : 'Issued' ?>
                        </span>
                        <?php if ($book['pub_year']): ?>
                        <span style="font-size:.72rem;color:#9ca3af;"><?= (int)$book['pub_year'] ?></span>
                        <?php endif; ?>
                     </div>
                  </div>
               </div>
            </div>
            <?php endforeach; ?>
         </div>
         <?php else: ?>
         <div class="text-center py-60">
            <i class="fas fa-book-open fa-3x mb-3" style="color:#d1d5db;"></i>
            <p class="text-muted">No books found<?= ($search_q !== '' ? ' for "' . fh($search_q) . '"' : '') ?>.</p>
         </div>
         <?php endif; ?>
      </div>
   </section>

   <!-- ── Library Information ──────────────────────────── -->
   <section class="pt-80 pb-80" style="background:#f7f9fc;">
      <div class="container">
         <div class="row">
            <div class="col-12 section-heading text-center">
               <h2>Library Information</h2>
               <p>Everything you need to know about visiting and using the library.</p>
            </div>
         </div>
         <div class="row g-4">
            <div class="col-md-6 col-lg-3">
               <div class="info-card">
                  <div class="icon-box"><i class="fas fa-map-marker-alt"></i></div>
                  <h5 style="font-weight:700;color:#1a1f36;margin-bottom:8px;">Location</h5>
                  <p style="font-size:.875rem;color:#6b7280;margin:0;"><?= fh($lib_location) ?></p>
                  <p style="font-size:.875rem;color:#6b7280;margin-top:6px;"><?= fh($lib_address) ?></p>
               </div>
            </div>
            <div class="col-md-6 col-lg-3">
               <div class="info-card">
                  <div class="icon-box"><i class="fas fa-door-open"></i></div>
                  <h5 style="font-weight:700;color:#1a1f36;margin-bottom:8px;">Room / Floor</h5>
                  <p style="font-size:.875rem;color:#6b7280;margin:0;"><?= fh($lib_room) ?></p>
               </div>
            </div>
            <div class="col-md-6 col-lg-3">
               <div class="info-card">
                  <div class="icon-box"><i class="fas fa-clock"></i></div>
                  <h5 style="font-weight:700;color:#1a1f36;margin-bottom:8px;">Opening Hours</h5>
                  <?php foreach (explode('|', $lib_hours) as $line): ?>
                  <p style="font-size:.85rem;color:#6b7280;margin:0 0 4px;"><?= fh(trim($line)) ?></p>
                  <?php endforeach; ?>
               </div>
            </div>
            <div class="col-md-6 col-lg-3">
               <div class="info-card">
                  <div class="icon-box"><i class="fas fa-phone-alt"></i></div>
                  <h5 style="font-weight:700;color:#1a1f36;margin-bottom:8px;">Contact</h5>
                  <p style="font-size:.875rem;color:#6b7280;margin:0;">
                     <i class="fas fa-phone me-2"></i>
                     <a href="tel:<?= fh(sanitize_phone($lib_phone)) ?>" class="text-muted text-decoration-none"><?= fh($lib_phone) ?></a>
                  </p>
                  <p style="font-size:.875rem;color:#6b7280;margin-top:6px;">
                     <i class="fas fa-envelope me-2"></i>
                     <a href="mailto:<?= fh($lib_email) ?>" class="text-muted text-decoration-none"><?= fh($lib_email) ?></a>
                  </p>
               </div>
            </div>
         </div>
      </div>
   </section>

   <!-- ── Borrowing Rules ───────────────────────────────── -->
   <section class="pt-80 pb-80">
      <div class="container">
         <div class="row">
            <div class="col-12 section-heading text-center">
               <h2>Borrowing Rules &amp; Policies</h2>
               <p>Know the library rules before you borrow.</p>
            </div>
         </div>
         <?php
         $borrow_student  = (int)($lib_settings['borrow_limit_student']  ?? 3);
         $borrow_faculty  = (int)($lib_settings['borrow_limit_faculty']  ?? 10);
         $days_student    = (int)($lib_settings['borrow_days_student']   ?? 14);
         $days_faculty    = (int)($lib_settings['borrow_days_faculty']   ?? 30);
         $fine_per_day    = number_format((float)($lib_settings['fine_per_day'] ?? 5), 2);
         $max_renewals    = (int)($lib_settings['max_renewals']          ?? 2);
         $max_reservations= (int)($lib_settings['max_reservations']      ?? 3);
         ?>
         <div class="row g-4">
            <div class="col-md-4">
               <div class="info-card text-center">
                  <div class="icon-box mx-auto" style="margin:0 auto 16px;"><i class="fas fa-user-graduate"></i></div>
                  <h5 style="font-weight:700;color:#1a1f36;">Students</h5>
                  <ul class="list-unstyled" style="font-size:.875rem;color:#6b7280;margin:0;">
                     <li class="mb-1"><strong><?= $borrow_student ?></strong> books at a time</li>
                     <li class="mb-1"><strong><?= $days_student ?></strong> days borrowing period</li>
                     <li class="mb-1">Up to <strong><?= $max_renewals ?></strong> renewals</li>
                  </ul>
               </div>
            </div>
            <div class="col-md-4">
               <div class="info-card text-center">
                  <div class="icon-box mx-auto" style="background:#fff3e0;color:#e07b00;margin:0 auto 16px;"><i class="fas fa-chalkboard-teacher"></i></div>
                  <h5 style="font-weight:700;color:#1a1f36;">Faculty / Staff</h5>
                  <ul class="list-unstyled" style="font-size:.875rem;color:#6b7280;margin:0;">
                     <li class="mb-1"><strong><?= $borrow_faculty ?></strong> books at a time</li>
                     <li class="mb-1"><strong><?= $days_faculty ?></strong> days borrowing period</li>
                     <li class="mb-1">Up to <strong><?= $max_renewals ?></strong> renewals</li>
                  </ul>
               </div>
            </div>
            <div class="col-md-4">
               <div class="info-card text-center">
                  <div class="icon-box mx-auto" style="background:#fee2e2;color:#dc2626;margin:0 auto 16px;"><i class="fas fa-exclamation-triangle"></i></div>
                  <h5 style="font-weight:700;color:#1a1f36;">Late Fines</h5>
                  <ul class="list-unstyled" style="font-size:.875rem;color:#6b7280;margin:0;">
                     <li class="mb-1"><strong>৳<?= $fine_per_day ?></strong> per day overdue</li>
                     <li class="mb-1">Up to <strong><?= $max_reservations ?></strong> reserves allowed</li>
                     <li class="mb-1">Fines must be cleared to borrow again</li>
                  </ul>
               </div>
            </div>
         </div>
      </div>
   </section>

   <!-- ── Digital Resources ─────────────────────────────── -->
   <?php if (!empty($digital)): ?>
   <section class="pt-80 pb-80" style="background:#f7f9fc;">
      <div class="container">
         <div class="row">
            <div class="col-12 section-heading text-center">
               <h2>Digital Resources</h2>
               <p>Free access to publicly available e-books, journals, and research papers.</p>
            </div>
         </div>
         <div class="row g-4">
            <?php foreach ($digital as $res):
               $icon = $resource_icons[$res['resource_type']] ?? 'fas fa-file';
            ?>
            <div class="col-md-6 col-lg-4">
               <div class="digital-card">
                  <div class="dc-icon"><i class="<?= fh($icon) ?>"></i></div>
                  <div>
                     <h6><?= fh($res['title']) ?></h6>
                     <p>
                        <?= fh($res['resource_type']) ?>
                        <?php if ($res['author']): ?> &middot; <?= fh($res['author']) ?><?php endif; ?>
                        <?php if ($res['pub_year']): ?> &middot; <?= (int)$res['pub_year'] ?><?php endif; ?>
                     </p>
                  </div>
               </div>
            </div>
            <?php endforeach; ?>
         </div>
      </div>
   </section>
   <?php endif; ?>

   <!-- ── Librarians ────────────────────────────────────── -->
   <?php if (!empty($librarians)): ?>
   <section class="pt-80 pb-100">
      <div class="container">
         <div class="row">
            <div class="col-12 section-heading text-center">
               <h2>Meet Our Librarians</h2>
               <p>Our dedicated team is here to help you find what you need.</p>
            </div>
         </div>
         <div class="row g-4 justify-content-center">
            <?php foreach ($librarians as $lib):
               $photo_url = !empty($lib['photo'])
                  ? fh(ADMIN_UPLOAD_URL . '/library/librarians/' . $lib['photo'])
                  : null;
               $initials = strtoupper(substr($lib['name'], 0, 1));
            ?>
            <div class="col-md-6 col-lg-4 col-xl-3">
               <div class="librarian-card">
                  <?php if ($photo_url): ?>
                  <img src="<?= $photo_url ?>" alt="<?= fh($lib['name']) ?>" class="lib-avatar">
                  <?php else: ?>
                  <div class="lib-avatar-initials"><?= fh($initials) ?></div>
                  <?php endif; ?>
                  <h5><?= fh($lib['name']) ?></h5>
                  <div class="designation"><?= fh($lib['designation']) ?></div>
                  <?php if ($lib['room_number']): ?>
                  <div class="meta"><i class="fas fa-door-open me-1"></i>Room: <?= fh($lib['room_number']) ?></div>
                  <?php endif; ?>
                  <?php if ($lib['email']): ?>
                  <div class="meta">
                     <i class="fas fa-envelope me-1"></i>
                     <a href="mailto:<?= fh($lib['email']) ?>" class="text-muted text-decoration-none" style="font-size:.8rem;">
                        <?= fh($lib['email']) ?>
                     </a>
                  </div>
                  <?php endif; ?>
                  <?php if ($lib['phone']): ?>
                  <div class="meta">
                     <i class="fas fa-phone me-1"></i>
                     <a href="tel:<?= fh(sanitize_phone($lib['phone'])) ?>" class="text-muted text-decoration-none" style="font-size:.8rem;">
                        <?= fh($lib['phone']) ?>
                     </a>
                  </div>
                  <?php endif; ?>
                  <?php if ($lib['bio']): ?>
                  <p style="font-size:.78rem;color:#9ca3af;margin-top:12px;margin-bottom:0;">
                     <?= fh(mb_strimwidth($lib['bio'], 0, 120, '…')) ?>
                  </p>
                  <?php endif; ?>
               </div>
            </div>
            <?php endforeach; ?>
         </div>
      </div>
   </section>
   <?php endif; ?>

   </main>

   <footer>
   <section class="it-footer-wrap it-footer-style-2 fix">
      <div class="it-footer-area z-index-1 pt-120 pb-80" data-background="assets/img/shape/footer-bg-3-1.jpg">
         <img class="it-footer-shape-1 d-none d-xxl-block" src="assets/img/shape/footer-3-1.png" alt="">
         <img class="it-footer-shape-2" data-parallax='{"y": -200, "smoothness": 30}' src="assets/img/shape/footer-3-2.png" alt="">
         <div class="it-footer-border"><span></span></div>
         <div class="container">
            <div class="row">
               <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 mb-50">
                  <div class="it-footer-widget">
                     <div class="it-footer-widget-logo mb-30">
                        <a href="<?= fh(SITE_URL) ?>/index.php"><img src="assets/img/logo/logo-black.png" alt="Prime University"></a>
                     </div>
                     <div class="it-footer-widget-text">
                        <p><?= fh($lib_name) ?> – serving students, faculty and researchers.</p>
                     </div>
                  </div>
               </div>
               <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 mb-50">
                  <div class="it-footer-widget">
                     <h4 class="it-footer-widget-title">Library</h4>
                     <div class="it-footer-widget-menu">
                        <ul>
                           <li><a href="library.php">Home</a></li>
                           <li><a href="library.php?q=">Book Catalogue</a></li>
                        </ul>
                     </div>
                  </div>
               </div>
               <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 mb-50">
                  <div class="it-footer-widget">
                     <h4 class="it-footer-widget-title">Useful Links</h4>
                     <div class="it-footer-widget-menu">
                        <ul>
                           <li><a href="<?= fh(SITE_URL) ?>/index.php">Home</a></li>
                           <li><a href="contact-us.html">Contact Us</a></li>
                        </ul>
                     </div>
                  </div>
               </div>
               <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 mb-50">
                  <div class="it-footer-widget d-flex justify-content-lg-end">
                     <div>
                        <h4 class="it-footer-widget-title">Contact Library</h4>
                        <div class="it-footer-widget-contact mb-25">
                           <ul>
                              <li><span>Phone:</span><a href="tel:<?= fh(sanitize_phone($lib_phone)) ?>"><?= fh($lib_phone) ?></a></li>
                              <li><span>Email:</span><a href="mailto:<?= fh($lib_email) ?>"><?= fh($lib_email) ?></a></li>
                              <li><span>Room:</span><?= fh($lib_room) ?></li>
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

   <!-- JS Libraries -->
   <script src="assets/js/jquery.js"></script>
   <script src="assets/js/bootstrap.bundle.min.js"></script>
   <script src="assets/js/purecounter.js"></script>
   <script src="assets/js/nice-select.js"></script>
   <script src="assets/js/swiper-bundle.min.js"></script>
   <script src="assets/js/slick.min.js"></script>
   <script src="assets/js/wow.js"></script>
   <script src="assets/js/magnific-popup.js"></script>
   <script src="assets/js/parallax.js"></script>
   <script src="assets/js/main.js"></script>
</body>
</html>
