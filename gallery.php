<?php
require_once __DIR__ . '/includes/config.php';

$page_title = 'Gallery – Prime University';

// ── Data ──────────────────────────────────────────────────────────────────────
$albums = [];
$photos = [];
$depts  = [];
$progs  = [];

try {
    $db = front_db();
    if ($db) {
        // All active departments that have at least one active album
        $depts = $db->query(
            "SELECT DISTINCT d.id, d.name
             FROM dept_departments d
             INNER JOIN gallery_albums a ON a.dept_id = d.id AND a.is_active = 1
             ORDER BY d.name"
        )->fetchAll();

        // All active programs that have at least one active album
        $progs = $db->query(
            "SELECT DISTINCT p.id, p.dept_id, p.program_name
             FROM dept_academic_programs p
             INNER JOIN gallery_albums a ON a.program_id = p.id AND a.is_active = 1
             ORDER BY p.program_name"
        )->fetchAll();

        // Active albums with approved photo count
        $albums = $db->query(
            "SELECT a.*,
                    d.name AS dept_name,
                    p.program_name,
                    (SELECT COUNT(*) FROM gallery_photos gp WHERE gp.album_id = a.id AND gp.status='approved') AS photo_count
             FROM gallery_albums a
             LEFT JOIN dept_departments      d ON d.id = a.dept_id
             LEFT JOIN dept_academic_programs p ON p.id = a.program_id
             WHERE a.is_active = 1
             HAVING photo_count > 0
             ORDER BY a.sort_order ASC, a.event_date DESC, a.created_at DESC"
        )->fetchAll();

        // All approved photos for the masonry wall (limit 400 for perf)
        $photos = $db->query(
            "SELECT gp.*, a.title AS album_title, a.dept_id, a.program_id
             FROM gallery_photos gp
             JOIN gallery_albums a ON a.id = gp.album_id AND a.is_active = 1
             WHERE gp.status = 'approved'
             ORDER BY a.sort_order ASC, a.event_date DESC, gp.sort_order ASC, gp.created_at ASC
             LIMIT 400"
        )->fetchAll();
    }
} catch (Throwable $e) { /* silently fall through */ }
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title><?= fh($page_title) ?></title>
   <meta name="description" content="Browse event photos and moments captured at Prime University across departments and programs.">
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
   /* ─── Gallery Page Styles ──────────────────────────────── */
   :root {
      --gal-accent:  #7c3aed;
      --gal-accent2: #4f46e5;
   }

   /* Hero */
   .pu-gal-hero {
      background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 55%, #4f46e5 100%);
      padding: 90px 0 70px;
      position: relative;
      overflow: hidden;
   }
   .pu-gal-hero::before {
      content: '';
      position: absolute; inset: 0;
      background: url('/assets/img/shape/breadcrumb-1-bg.png') center/cover no-repeat;
      opacity: .05;
   }
   .pu-gal-hero h1 { font-size: clamp(2rem,5vw,3rem); font-weight: 800; color: #fff; margin-bottom: 12px; }
   .pu-gal-hero .tagline { font-size: 1.05rem; color: rgba(255,255,255,.75); }
   .pu-gal-hero .breadcrumb-nav a,
   .pu-gal-hero .breadcrumb-nav span { color: rgba(255,255,255,.7); font-size: .85rem; }
   .pu-gal-hero .breadcrumb-nav .sep { margin: 0 8px; color: rgba(255,255,255,.35); }

   /* Filter bar */
   .pu-gal-filter {
      background: #fff;
      border-radius: 18px;
      box-shadow: 0 6px 32px rgba(0,0,0,.10);
      padding: 28px 32px;
      margin-top: -50px;
      position: relative;
      z-index: 20;
      margin-bottom: 52px;
   }
   .pu-gal-filter .form-control,
   .pu-gal-filter .form-select {
      border-radius: 10px;
      border-color: #e2e8f0;
      padding: 11px 16px;
      font-size: .92rem;
   }
   .pu-gal-filter .btn-filter {
      background: var(--gal-accent);
      color: #fff;
      border-radius: 10px;
      padding: 11px 28px;
      font-weight: 600;
      border: none;
      transition: background .2s;
   }
   .pu-gal-filter .btn-filter:hover { background: var(--gal-accent2); }

   /* View toggle */
   .view-toggle .btn { border-radius: 8px; }
   .view-toggle .btn.active { background: var(--gal-accent); color: #fff; border-color: var(--gal-accent); }

   /* ── Album view ── */
   .album-card {
      border: none;
      border-radius: 18px;
      box-shadow: 0 4px 20px rgba(0,0,0,.07);
      overflow: hidden;
      transition: transform .28s, box-shadow .28s;
      cursor: pointer;
   }
   .album-card:hover { transform: translateY(-7px); box-shadow: 0 14px 40px rgba(79,70,229,.15); }
   .album-card .album-cover {
      height: 200px;
      object-fit: cover;
      width: 100%;
   }
   .album-card .album-cover-ph {
      height: 200px;
      background: linear-gradient(135deg, #1e1b4b, #4f46e5);
      display: flex; align-items: center; justify-content: center;
   }
   .album-card .card-body { padding: 18px 20px 20px; }
   .album-card .album-title { font-weight: 700; font-size: 1.05rem; color: #1a2e5a; margin-bottom: 4px; }
   .album-card .album-dept  { font-size: .78rem; color: var(--gal-accent); font-weight: 600; margin-bottom: 8px; }
   .album-card .album-meta  { font-size: .8rem; color: #6b7280; display: flex; gap: 14px; }

   /* ── Photo wall (masonry) ── */
   #photoWall { display: none; }
   .gal-masonry { column-count: 4; column-gap: 16px; }
   @media (max-width: 1199px) { .gal-masonry { column-count: 3; } }
   @media (max-width: 767px)  { .gal-masonry { column-count: 2; } }
   @media (max-width: 479px)  { .gal-masonry { column-count: 1; } }

   .gal-item {
      break-inside: avoid;
      margin-bottom: 16px;
      border-radius: 14px;
      overflow: hidden;
      position: relative;
      cursor: zoom-in;
   }
   .gal-item img {
      width: 100%;
      display: block;
      transition: transform .35s;
      border-radius: 14px;
   }
   .gal-item:hover img { transform: scale(1.04); }
   .gal-item .gal-overlay {
      position: absolute;
      inset: 0;
      background: linear-gradient(180deg, transparent 40%, rgba(15,10,50,.72) 100%);
      opacity: 0;
      transition: opacity .3s;
      border-radius: 14px;
      display: flex;
      flex-direction: column;
      justify-content: flex-end;
      padding: 18px 16px 14px;
   }
   .gal-item:hover .gal-overlay { opacity: 1; }
   .gal-item .gal-caption { color: #fff; font-size: .82rem; font-weight: 600; line-height: 1.4; }
   .gal-item .gal-album   { color: rgba(255,255,255,.65); font-size: .72rem; }
   .gal-item .zoom-icon {
      position: absolute;
      top: 12px; right: 12px;
      background: rgba(255,255,255,.9);
      color: var(--gal-accent);
      border-radius: 50%;
      width: 32px; height: 32px;
      display: flex; align-items: center; justify-content: center;
      font-size: .85rem;
      opacity: 0;
      transition: opacity .3s;
   }
   .gal-item:hover .zoom-icon { opacity: 1; }

   /* Filter chip pills */
   .filter-chips { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 28px; }
   .chip {
      padding: 5px 16px;
      border-radius: 20px;
      border: 1.5px solid #e2e8f0;
      background: #fff;
      font-size: .82rem;
      font-weight: 600;
      color: #64748b;
      cursor: pointer;
      transition: all .2s;
   }
   .chip.active, .chip:hover {
      background: var(--gal-accent);
      border-color: var(--gal-accent);
      color: #fff;
   }

   /* Lightbox overrides */
   .mfp-bottom-bar { padding-top: 6px; }
   .mfp-title { font-size: .9rem; color: #fff; }

   /* ─── Album Modal – override global main.css rules ─── */
   #albumModal.modal { overflow-y: auto; }
   #albumModal .modal-dialog {
      width: auto !important;
      max-width: 960px !important;
      margin: 1.75rem auto !important;
   }
   @media (max-width: 767px) {
      #albumModal .modal-dialog { max-width: calc(100% - 1rem) !important; margin: 0.5rem auto !important; }
   }
   #albumModal .modal-header {
      padding: 18px 22px !important;
      align-items: flex-start;
   }
   #albumModal .modal-body {
      padding: 20px !important;
   }
   /* Fix: modal title must be white over the purple gradient header */
   #albumModal .modal-header h5 { color: #fff !important; }

   /* Fix: metadata line – flex layout with balanced icon/text spacing */
   #albumModalMeta {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 6px 14px;
      margin-top: 6px;
      color: rgba(255,255,255,.85);
      font-size: .82rem;
   }
   #albumModalMeta .meta-sep { color: rgba(255,255,255,.35); user-select: none; }

   /* Custom close button (immune to the global .btn-close absolute positioning) */
   .pu-modal-close {
      position: static !important;
      flex-shrink: 0;
      width: 36px; height: 36px;
      border-radius: 50%;
      background: rgba(255,255,255,.28);
      border: 2px solid rgba(255,255,255,.7);
      color: #fff;
      display: flex; align-items: center; justify-content: center;
      cursor: pointer;
      transition: background .2s, border-color .2s;
      margin-left: auto;
      font-size: .9rem;
      font-weight: 700;
   }
   .pu-modal-close:hover { background: rgba(255,255,255,.5); border-color: #fff; }
   /* Album photo thumbnails */
   .album-modal-thumb {
      width: 100%;
      height: 160px;
      object-fit: cover;
      border-radius: 10px;
      display: block;
      transition: transform .3s;
   }
   @media (max-width: 575px) { .album-modal-thumb { height: 120px; } }
   .album-modal-link { display: block; }
   .album-modal-link:hover .album-modal-thumb { transform: scale(1.04); }

   /* Stats strip */
   .pu-gal-stats {
      background: linear-gradient(135deg, #7c3aed, #4f46e5);
      padding: 44px 0;
      margin: 60px 0 0;
   }
   .pu-gal-stats .stat-num   { font-size: 2.2rem; font-weight: 800; color: #fff; }
   .pu-gal-stats .stat-label { font-size: .9rem; color: rgba(255,255,255,.75); }

   /* Entrance animation */
   @keyframes fadeInUp {
      from { opacity: 0; transform: translateY(24px); }
      to   { opacity: 1; transform: translateY(0); }
   }
   .anim-in { animation: fadeInUp .5s ease both; }

   /* No results */
   .pu-no-results { text-align: center; padding: 80px 0; color: #9ca3af; }
   .pu-no-results i { font-size: 3rem; display: block; margin-bottom: 16px; opacity: .35; }
   </style>
<?php include __DIR__ . '/includes/meta-pixel.php'; ?>
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

   <!-- ── Hero ─────────────────────────────────────────────── -->
   <section class="pu-gal-hero">
      <div class="container position-relative">
         <nav class="breadcrumb-nav mb-3">
            <a href="/">Home</a><span class="sep">/</span>
            <span>Gallery</span>
         </nav>
         <h1>Photo Gallery</h1>
         <p class="tagline">Moments captured across departments, events, and programs at Prime University.</p>
      </div>
   </section>

   <!-- ── Filter bar ────────────────────────────────────────── -->
   <div class="container">
      <div class="pu-gal-filter">
         <div class="row g-3 align-items-end">
            <div class="col-md-4">
               <label class="form-label small fw-semibold text-muted mb-1">Search</label>
               <input type="text" id="searchInput" class="form-control" placeholder="Search albums or captions…">
            </div>
            <div class="col-md-3">
               <label class="form-label small fw-semibold text-muted mb-1">Department</label>
               <select id="deptFilter" class="form-select">
                  <option value="">All Departments</option>
                  <?php foreach ($depts as $d): ?>
                  <option value="<?= (int)$d['id'] ?>"><?= fh($d['name']) ?></option>
                  <?php endforeach; ?>
               </select>
            </div>
            <div class="col-md-3">
               <label class="form-label small fw-semibold text-muted mb-1">Program</label>
               <select id="progFilter" class="form-select">
                  <option value="">All Programs</option>
                  <?php foreach ($progs as $p): ?>
                  <option value="<?= (int)$p['id'] ?>" data-dept="<?= (int)$p['dept_id'] ?>"><?= fh($p['program_name']) ?></option>
                  <?php endforeach; ?>
               </select>
            </div>
            <div class="col-md-2">
               <button class="btn-filter w-100" id="applyFilter"><i class="fas fa-search me-2"></i>Filter</button>
            </div>
         </div>
      </div>
   </div>

   <!-- ── Main content ─────────────────────────────────────── -->
   <div class="container pb-80">

      <!-- View toggle -->
      <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
         <div>
            <h2 class="h4 fw-bold mb-0 text-dark" id="resultCount">
               <?= count($albums) ?> Album<?= count($albums) != 1 ? 's' : '' ?>
            </h2>
         </div>
         <div class="view-toggle btn-group" role="group">
            <button class="btn btn-outline-secondary active" id="viewAlbums" title="Albums view">
               <i class="fas fa-th-large me-1"></i> Albums
            </button>
            <button class="btn btn-outline-secondary" id="viewPhotos" title="Photo wall">
               <i class="fas fa-th me-1"></i> All Photos
            </button>
         </div>
      </div>

      <!-- ── Albums view ── -->
      <div id="albumView">
         <?php if (empty($albums)): ?>
         <div class="pu-no-results">
            <i class="fas fa-images"></i>
            <p class="fs-5">No gallery albums have been published yet.</p>
            <a href="/" class="btn btn-outline-secondary mt-2">Go Home</a>
         </div>
         <?php else: ?>
         <div class="row g-4" id="albumsGrid">
            <?php foreach ($albums as $idx => $a): ?>
            <div class="col-sm-6 col-lg-4 col-xl-3 album-item"
                 data-dept="<?= (int)$a['dept_id'] ?>"
                 data-prog="<?= (int)$a['program_id'] ?>"
                 data-title="<?= fh(mb_strtolower($a['title'])) ?>">
               <div class="album-card card anim-in" style="animation-delay:<?= $idx * 0.05 ?>s" onclick="openAlbum(<?= $a['id'] ?>)">
                  <?php if ($a['cover_photo']): ?>
                  <img src="<?= ADMIN_UPLOAD_URL ?>/gallery/covers/<?= fh($a['cover_photo']) ?>" class="album-cover" alt="<?= fh($a['title']) ?>" loading="lazy">
                  <?php else: ?>
                  <div class="album-cover-ph"><i class="fas fa-images fa-3x text-white opacity-40"></i></div>
                  <?php endif; ?>
                  <div class="card-body">
                     <div class="album-title"><?= fh($a['title']) ?></div>
                     <?php if ($a['dept_name']): ?>
                     <div class="album-dept"><i class="fas fa-building me-1"></i><?= fh($a['dept_name']) ?></div>
                     <?php endif; ?>
                     <?php if ($a['program_name']): ?>
                     <div class="mb-2"><span class="badge" style="background:#ede9fe;color:#5b21b6;font-weight:600;border-radius:8px;"><?= fh($a['program_name']) ?></span></div>
                     <?php endif; ?>
                     <div class="album-meta">
                        <span><i class="fas fa-images me-1" style="color:var(--gal-accent)"></i><?= (int)$a['photo_count'] ?> Photo<?= $a['photo_count'] != 1 ? 's' : '' ?></span>
                        <?php if ($a['event_date']): ?>
                        <span><i class="fas fa-calendar me-1" style="color:var(--gal-accent)"></i><?= date('d M Y', strtotime($a['event_date'])) ?></span>
                        <?php endif; ?>
                     </div>
                  </div>
               </div>
            </div>
            <?php endforeach; ?>
         </div>
         <?php endif; ?>
      </div>

      <!-- ── Photo wall (masonry) ── -->
      <div id="photoWall">
         <?php if (empty($photos)): ?>
         <div class="pu-no-results">
            <i class="fas fa-images"></i>
            <p class="fs-5">No photos to display.</p>
         </div>
         <?php else: ?>
         <div class="gal-masonry" id="masonryGrid">
            <?php foreach ($photos as $p): ?>
            <div class="gal-item gal-photo-item"
                 data-dept="<?= (int)$p['dept_id'] ?>"
                 data-prog="<?= (int)$p['program_id'] ?>"
                 data-album="<?= (int)$p['album_id'] ?>"
                 data-caption="<?= fh(mb_strtolower($p['caption'] ?? '')) ?>"
                 data-album-title="<?= fh(mb_strtolower($p['album_title'])) ?>">
               <a href="<?= ADMIN_UPLOAD_URL ?>/gallery/photos/<?= fh($p['stored_name']) ?>"
                  class="gal-lightbox-link"
                  data-mfp-src="<?= ADMIN_UPLOAD_URL ?>/gallery/photos/<?= fh($p['stored_name']) ?>"
                  title="<?= fh($p['caption'] ?? $p['album_title']) ?>">
                  <img src="<?= ADMIN_UPLOAD_URL ?>/gallery/photos/<?= fh($p['stored_name']) ?>"
                       alt="<?= fh($p['caption'] ?? '') ?>" loading="lazy">
                  <div class="gal-overlay">
                     <?php if ($p['caption']): ?>
                     <div class="gal-caption"><?= fh($p['caption']) ?></div>
                     <?php endif; ?>
                     <div class="gal-album"><i class="fas fa-folder me-1"></i><?= fh($p['album_title']) ?></div>
                  </div>
                  <div class="zoom-icon"><i class="fas fa-expand-alt"></i></div>
               </a>
            </div>
            <?php endforeach; ?>
         </div>
         <?php endif; ?>
      </div>

      <!-- Album lightbox modal (shown when an album card is clicked) -->
      <div id="albumModal" class="modal fade" tabindex="-1">
         <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg" style="border-radius:20px;overflow:hidden;">
               <div class="modal-header border-0 pb-0" style="background:linear-gradient(135deg,#1e1b4b,#4f46e5);color:#fff;">
                  <div>
                     <h5 class="modal-title fw-bold mb-0" id="albumModalTitle"></h5>
                     <div id="albumModalMeta"></div>
                  </div>
                  <button type="button" class="pu-modal-close" data-bs-dismiss="modal" aria-label="Close">
                     <i class="fas fa-times"></i>
                  </button>
               </div>
               <div class="modal-body p-3" id="albumModalBody">
                  <div class="text-center py-5"><div class="spinner-border text-primary"></div></div>
               </div>
            </div>
         </div>
      </div>

   </div><!-- /.container -->

   <!-- Stats strip -->
   <?php
   $total_albums = count($albums);
   $total_photos = count($photos);
   $total_depts  = count($depts);
   ?>
   <?php if ($total_albums > 0): ?>
   <section class="pu-gal-stats">
      <div class="container">
         <div class="row g-4 text-center">
            <div class="col-4">
               <div class="stat-num"><?= $total_albums ?>+</div>
               <div class="stat-label">Albums</div>
            </div>
            <div class="col-4">
               <div class="stat-num"><?= $total_photos ?>+</div>
               <div class="stat-label">Photos</div>
            </div>
            <div class="col-4">
               <div class="stat-num"><?= $total_depts ?>+</div>
               <div class="stat-label">Departments</div>
            </div>
         </div>
      </div>
   </section>
   <?php endif; ?>

   <?php include __DIR__ . '/includes/footer.php'; ?>
   <?php include __DIR__ . '/includes/scripts.php'; ?>

   <!-- Magnific Popup JS -->
   <script src="/assets/js/jquery.magnific-popup.min.js"></script>

   <script>
   (function ($) {
      // ── Album data from PHP ────────────────────────────────────────────
      const albumsData = <?= json_encode(array_map(function($a) {
         return [
            'id'          => (int)$a['id'],
            'dept_id'     => (int)$a['dept_id'],
            'program_id'  => (int)$a['program_id'],
            'title'       => $a['title'],
            'dept_name'   => $a['dept_name'] ?? '',
            'photo_count' => (int)$a['photo_count'],
            'event_date'  => $a['event_date'] ?? '',
         ];
      }, $albums), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

      // ── View toggle ────────────────────────────────────────────────────
      $('#viewAlbums').on('click', function () {
         $(this).addClass('active'); $('#viewPhotos').removeClass('active');
         $('#albumView').show(); $('#photoWall').hide();
         $('#resultCount').text($('.album-item:visible').length + ' Album' + ($('.album-item:visible').length !== 1 ? 's' : ''));
         initMasonry(); // recalculate when switching back
      });

      $('#viewPhotos').on('click', function () {
         $(this).addClass('active'); $('#viewAlbums').removeClass('active');
         $('#albumView').hide(); $('#photoWall').show();
         $('#resultCount').text($('.gal-photo-item:visible').length + ' Photo' + ($('.gal-photo-item:visible').length !== 1 ? 's' : ''));
         initMagnific();
      });

      // ── Department filter → cascade programs ──────────────────────────
      $('#deptFilter').on('change', function () {
         const deptId = $(this).val();
         $('#progFilter option').each(function () {
            const optDept = $(this).data('dept');
            if (!deptId || !optDept || String(optDept) === String(deptId)) {
               $(this).show();
            } else {
               $(this).hide();
            }
         });
         $('#progFilter').val('');
      });

      // ── Apply filter ───────────────────────────────────────────────────
      function applyFilter() {
         const q      = $('#searchInput').val().toLowerCase().trim();
         const deptId = $('#deptFilter').val();
         const progId = $('#progFilter').val();

         // Albums
         $('.album-item').each(function () {
            const title = $(this).data('title') || '';
            const dept  = String($(this).data('dept'));
            const prog  = String($(this).data('prog'));
            const matchQ    = !q || title.includes(q);
            const matchDept = !deptId || dept === deptId;
            const matchProg = !progId || prog === progId;
            $(this).toggle(matchQ && matchDept && matchProg);
         });

         // Photos
         $('.gal-photo-item').each(function () {
            const caption = $(this).data('caption') || '';
            const altitle = $(this).data('album-title') || '';
            const dept    = String($(this).data('dept'));
            const prog    = String($(this).data('prog'));
            const matchQ    = !q || caption.includes(q) || altitle.includes(q);
            const matchDept = !deptId || dept === deptId;
            const matchProg = !progId || prog === progId;
            $(this).toggle(matchQ && matchDept && matchProg);
         });

         // Update count
         if ($('#albumView').is(':visible')) {
            const visible = $('.album-item:visible').length;
            $('#resultCount').text(visible + ' Album' + (visible !== 1 ? 's' : ''));
         } else {
            const visible = $('.gal-photo-item:visible').length;
            $('#resultCount').text(visible + ' Photo' + (visible !== 1 ? 's' : ''));
         }
      }

      $('#applyFilter').on('click', applyFilter);
      $('#searchInput').on('keyup', function (e) { if (e.key === 'Enter') applyFilter(); });

      // ── Open album in modal ────────────────────────────────────────────
      window.openAlbum = function (albumId) {
         const album = albumsData.find(a => a.id === albumId);
         if (!album) return;

         $('#albumModalTitle').text(album.title);
         let meta = [];
         if (album.dept_name) meta.push('<i class="fas fa-building me-1"></i>' + album.dept_name);
         if (album.event_date) meta.push('<i class="fas fa-calendar me-1"></i>' + album.event_date);
         meta.push(album.photo_count + ' photo' + (album.photo_count !== 1 ? 's' : ''));
         $('#albumModalMeta').html(meta.join('  &nbsp;|&nbsp;  '));

         $('#albumModalBody').html('<div class="text-center py-5"><div class="spinner-border text-primary"></div></div>');
         const modal = new bootstrap.Modal(document.getElementById('albumModal'));
         modal.show();

         // Fetch album photos via embedded data
         const albumPhotos = <?= json_encode(array_map(function($p) {
            return [
               'album_id'    => (int)$p['album_id'],
               'src'         => ADMIN_UPLOAD_URL . '/gallery/photos/' . $p['stored_name'],
               'caption'     => $p['caption'] ?? '',
               'album_title' => $p['album_title'],
            ];
         }, $photos), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

         const filtered = albumPhotos.filter(p => p.album_id === albumId);
         if (filtered.length === 0) {
            $('#albumModalBody').html('<div class="text-center py-5 text-muted"><i class="fas fa-images fa-3x d-block mb-3 opacity-25"></i>No photos in this album.</div>');
            return;
         }

         let html = '<div class="row g-2">';
         filtered.forEach(function (p, i) {
            const safeCaption = $('<div>').text(p.caption || '').html();
            const safeTitle   = $('<div>').text(p.caption || p.album_title).html();
            html += '<div class="col-6 col-md-4 col-lg-3">'
               + '<a href="' + p.src + '" class="modal-gal-link album-modal-link" title="' + safeTitle + '">'
               + '<img src="' + p.src + '" loading="lazy" class="album-modal-thumb" alt="' + (safeCaption || safeTitle) + '">'
               + (p.caption ? '<div class="small text-muted mt-1 px-1" style="font-size:.74rem;">' + safeCaption + '</div>' : '')
               + '</a></div>';
         });
         html += '</div>';

         $('#albumModalBody').html(html);

         // Re-init magnific for modal links
         // Hide the Bootstrap album modal while the lightbox is open; restore on close
         var $albumModal = $('#albumModal');
         $('.modal-gal-link').magnificPopup({
            type: 'image',
            gallery: { enabled: true },
            image: { titleSrc: 'title' },
            callbacks: {
               beforeOpen: function () {
                  $albumModal.css('visibility', 'hidden');
               },
               afterClose: function () {
                  $albumModal.css('visibility', '');
               },
            },
         });
      };

      // ── Magnific Popup for photo wall ──────────────────────────────────
      function initMagnific() {
         $('.gal-masonry').magnificPopup({
            delegate: '.gal-lightbox-link:visible',
            type:     'image',
            gallery:  { enabled: true, navigateByImgClick: true, preload: [1, 2] },
            image:    { titleSrc: 'title', verticalFit: true },
            removalDelay: 300,
            mainClass: 'mfp-fade',
         });
      }

      // ── Init masonry (CSS columns – no JS lib needed) ──────────────────
      function initMasonry() { /* CSS columns handle layout */ }

      // ── Entrance animations on scroll ─────────────────────────────────
      const observer = new IntersectionObserver(function (entries) {
         entries.forEach(function (entry) {
            if (entry.isIntersecting) {
               entry.target.style.opacity = '1';
            }
         });
      }, { threshold: 0.1 });

      document.querySelectorAll('.album-card, .gal-item').forEach(el => {
         el.style.opacity = '0';
         observer.observe(el);
      });

      // Trigger anim-in items
      document.querySelectorAll('.anim-in').forEach(el => {
         el.style.opacity = '1';
      });

   })(jQuery);
   </script>
</body>
</html>
