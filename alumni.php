<?php
require_once __DIR__ . '/includes/config.php';

$db          = front_db();
$departments = [];
$alumni      = [];
$total       = 0;

// ── Filters ──────────────────────────────────────────────────────────────────
$f_dept  = (int)($_GET['dept']   ?? 0);
$f_batch = trim($_GET['batch']   ?? '');
$search  = trim($_GET['q']       ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$per     = 12;

if ($db) {
    try {
        $departments = $db->query(
            "SELECT d.id, d.name,
                    (SELECT COUNT(*) FROM alumni a WHERE a.dept_id = d.id AND a.status='approved' AND a.is_active=1) AS alumni_count
             FROM dept_departments d
             WHERE d.is_active = 1
             ORDER BY d.name ASC"
        )->fetchAll();
    } catch (Throwable $e) {}

    try {
        $where  = ["a.status='approved'", "a.is_active=1"];
        $params = [];

        if ($f_dept) {
            $where[]  = 'a.dept_id = ?';
            $params[] = $f_dept;
        }
        if ($f_batch !== '') {
            $where[]  = 'a.batch = ?';
            $params[] = $f_batch;
        }
        if ($search !== '') {
            $where[]  = '(a.name LIKE ? OR a.company LIKE ? OR a.position LIKE ? OR a.batch LIKE ?)';
            $s = '%' . $search . '%';
            $params = array_merge($params, [$s, $s, $s, $s]);
        }

        $sql_where = 'WHERE ' . implode(' AND ', $where);

        $count_st = $db->prepare("SELECT COUNT(*) FROM alumni a $sql_where");
        $count_st->execute($params);
        $total = (int)$count_st->fetchColumn();

        $pages  = max(1, (int)ceil($total / $per));
        $page   = min($page, $pages);
        $offset = ($page - 1) * $per;

        $data_st = $db->prepare(
            "SELECT a.*, d.name AS dept_name
             FROM alumni a
             LEFT JOIN dept_departments d ON d.id = a.dept_id
             $sql_where
             ORDER BY a.sort_order ASC, a.id ASC
             LIMIT $per OFFSET $offset"
        );
        $data_st->execute($params);
        $alumni = $data_st->fetchAll();
    } catch (Throwable $e) {
        $alumni = [];
    }
}

$pages = max(1, (int)ceil($total / $per));

// Distinct batches for filter dropdown
$batches = [];
if ($db) {
    try {
        $batches = $db->query(
            "SELECT DISTINCT batch FROM alumni WHERE status='approved' AND is_active=1 AND batch IS NOT NULL ORDER BY batch ASC"
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {}
}
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <meta name="viewport" content="width=device-width, initial-scale=1">
   <title>Alumni Directory – Prime University</title>
   <meta name="description" content="Meet the distinguished alumni of Prime University. Filter by department to find graduates who are making a difference worldwide.">
   <link rel="shortcut icon" type="image/x-icon" href="/assets/img/logo/favicon.png">
   <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
   <link rel="stylesheet" href="/assets/css/font-awesome-pro.css">
   <link rel="stylesheet" href="/assets/css/custom-animation.css">
   <link rel="stylesheet" href="/assets/css/spacing.css">
   <link rel="stylesheet" href="/assets/css/main.css">
   <style>
      /* ── Hero ── */
      .alumni-hero {
         background: linear-gradient(135deg, #002147 0%, #003d82 50%, #D21034 100%);
         padding: 90px 0 70px;
         position: relative;
         overflow: hidden;
      }
      .alumni-hero::before {
         content:'';position:absolute;inset:0;
         background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
      }

      /* ── Sidebar ── */
      .alumni-sidebar .sidebar-card {
         background: #fff;
         border-radius: 16px;
         box-shadow: 0 4px 20px rgba(0,33,71,0.07);
         overflow: hidden;
         position: sticky;
         top: 20px;
      }
      .sidebar-dept-list { list-style: none; margin: 0; padding: 0; }
      .sidebar-dept-list li a {
         display: flex;
         justify-content: space-between;
         align-items: center;
         padding: 11px 20px;
         color: #4a5568;
         text-decoration: none;
         font-size: .9rem;
         border-left: 3px solid transparent;
         transition: all .2s;
      }
      .sidebar-dept-list li a:hover,
      .sidebar-dept-list li a.active {
         color: #002147;
         background: #f0f4fb;
         border-left-color: #002147;
         font-weight: 600;
      }
      .sidebar-dept-list li a .badge-count {
         background: #e8eef6;
         color: #002147;
         border-radius: 20px;
         padding: 2px 9px;
         font-size: .75rem;
         font-weight: 600;
      }
      .sidebar-dept-list li a.active .badge-count {
         background: #002147;
         color: #fff;
      }

      /* ── Search bar ── */
      .alumni-search-bar {
         background: rgba(255,255,255,.12);
         backdrop-filter: blur(10px);
         border-radius: 14px;
         padding: 16px 20px;
         display: flex;
         gap: 10px;
         align-items: center;
         flex-wrap: wrap;
      }
      .alumni-search-bar input[type="text"] {
         flex: 1 1 160px;
         min-width: 0;
         border: 1.5px solid rgba(255,255,255,.35);
         background: rgba(255,255,255,.15);
         color: #fff;
         border-radius: 10px;
         padding: 10px 16px;
         font-size: .95rem;
      }
      .alumni-search-bar input[type="text"]::placeholder { color: rgba(255,255,255,.65); }
      .alumni-search-bar input[type="text"]:focus {
         outline: none;
         border-color: #FFB81C;
         background: rgba(255,255,255,.22);
      }
      .alumni-search-bar select {
         flex: 1 1 160px;
         min-width: 0;
         background: #002147;
         color: #fff;
         border: 1.5px solid rgba(255,255,255,.35);
         border-radius: 10px;
         padding: 10px 14px;
         font-size: .88rem;
         cursor: pointer;
         -webkit-appearance: none;
         appearance: none;
      }
      .alumni-search-bar select option { background: #002147; color: #fff; }
      .alumni-search-bar .btn-search {
         flex-shrink: 0;
         background: #FFB81C;
         color: #002147;
         border: none;
         border-radius: 10px;
         padding: 10px 22px;
         font-weight: 700;
         font-size: .9rem;
         transition: background .2s;
         white-space: nowrap;
      }
      .alumni-search-bar .btn-search:hover { background: #e5a800; }
      .alumni-search-bar .btn-clear {
         flex-shrink: 0;
         color: rgba(255,255,255,.8);
         font-size: .875rem;
         text-decoration: none;
         white-space: nowrap;
         padding: 6px 4px;
      }
      .alumni-search-bar .btn-clear:hover { color: #fff; }
      @media (max-width: 575px) {
         .alumni-search-bar { padding: 14px 14px; gap: 8px; }
         .alumni-search-bar input[type="text"],
         .alumni-search-bar select { flex: 1 1 100%; }
         .alumni-search-bar .btn-search { flex: 1 1 auto; text-align: center; }
      }

      /* ── Cards ── */
      .alumni-card {
         background: #fff;
         border-radius: 18px;
         box-shadow: 0 4px 20px rgba(0,33,71,0.07);
         overflow: hidden;
         transition: transform .35s cubic-bezier(.25,.8,.25,1), box-shadow .35s;
         height: 100%;
         display: flex;
         flex-direction: column;
      }
      .alumni-card:hover {
         transform: translateY(-8px);
         box-shadow: 0 20px 50px rgba(0,33,71,0.16);
      }
      .alumni-card-photo {
         position: relative;
         text-align: center;
         padding: 28px 20px 0;
         background: linear-gradient(180deg, #f0f4fb 0%, #fff 100%);
      }
      .alumni-card-photo img,
      .alumni-card-photo .photo-placeholder {
         width: 110px;
         height: 110px;
         border-radius: 50%;
         object-fit: cover;
         border: 4px solid #fff;
         box-shadow: 0 6px 20px rgba(0,33,71,0.15);
         display: inline-block;
      }
      .alumni-card-photo .photo-placeholder {
         background: linear-gradient(135deg, #002147, #003d82);
         color: #fff;
         display: inline-flex;
         align-items: center;
         justify-content: center;
         font-size: 2.2rem;
         font-weight: 700;
      }
      .alumni-card-body {
         padding: 16px 20px 20px;
         text-align: center;
         flex: 1;
         display: flex;
         flex-direction: column;
      }
      .alumni-card-name {
         font-size: 1rem;
         font-weight: 700;
         color: #002147;
         margin-bottom: 4px;
         line-height: 1.3;
      }
      .alumni-card-position {
         font-size: .82rem;
         color: #D21034;
         font-weight: 600;
         margin-bottom: 2px;
      }
      .alumni-card-company {
         font-size: .82rem;
         color: #5a6a85;
         margin-bottom: 6px;
      }
      .alumni-card-meta {
         display: flex;
         gap: 6px;
         justify-content: center;
         flex-wrap: wrap;
         margin-bottom: 12px;
      }
      .alumni-badge {
         background: #f0f4fb;
         color: #002147;
         border-radius: 20px;
         padding: 3px 11px;
         font-size: .75rem;
         font-weight: 600;
      }
      .alumni-card-links {
         margin-top: auto;
         display: flex;
         gap: 8px;
         justify-content: center;
      }
      .alumni-card-links a {
         width: 36px;
         height: 36px;
         border-radius: 50%;
         display: inline-flex;
         align-items: center;
         justify-content: center;
         background: #f0f4fb;
         color: #002147;
         text-decoration: none;
         font-size: 1rem;
         transition: background .2s, color .2s, transform .2s;
      }
      .alumni-card-links a:hover {
         background: #002147;
         color: #fff;
         transform: scale(1.1);
      }
      .alumni-card-links a.fb:hover { background: #1877f2; }
      .alumni-card-links a.li:hover { background: #0a66c2; }

      /* ── Pagination ── */
      .alumni-pagination {
         display: flex;
         gap: 8px;
         justify-content: center;
         align-items: center;
         flex-wrap: wrap;
         margin-top: 50px;
      }
      .alumni-pagination a,
      .alumni-pagination span {
         display: inline-flex;
         align-items: center;
         justify-content: center;
         width: 44px;
         height: 44px;
         border-radius: 10px;
         font-size: .9rem;
         font-weight: 600;
         text-decoration: none;
         border: 2px solid #e0e6ef;
         color: #4a5568;
         transition: all .2s;
      }
      .alumni-pagination a:hover { border-color: #002147; color: #002147; background: #f0f4fb; }
      .alumni-pagination .current { background: #002147; border-color: #002147; color: #fff; }
      .alumni-pagination .prev-next { width: auto; padding: 0 16px; gap: 6px; }

      /* ── Empty state ── */
      .empty-state { text-align:center;padding:80px 20px;color:#8898aa; }
      .empty-state i { font-size:4rem;margin-bottom:20px;opacity:.25;display:block; }

      /* ── Stats bar ── */
      .alumni-stats-bar {
         background: #fff;
         border-radius: 14px;
         box-shadow: 0 4px 20px rgba(0,33,71,0.07);
         padding: 20px 28px;
         margin-bottom: 30px;
         display: flex;
         gap: 32px;
         align-items: center;
         flex-wrap: wrap;
      }
      .alumni-stats-bar .stat { text-align:center; }
      .alumni-stats-bar .stat .num { font-size:1.6rem;font-weight:800;color:#002147;line-height:1; }
      .alumni-stats-bar .stat .lbl { font-size:.78rem;color:#8898aa;margin-top:2px;font-weight:500; }

      /* ── Register CTA ── */
      .alumni-cta {
         background: linear-gradient(135deg, #002147 0%, #003d82 100%);
         border-radius: 18px;
         padding: 36px 32px;
         color: #fff;
         margin-top: 10px;
      }

      @media (max-width: 767px) {
         .alumni-hero { padding: 60px 0 50px; }
         .alumni-stats-bar { gap: 20px; padding: 16px 20px; }
         .alumni-stats-bar .stat .num { font-size: 1.3rem; }
         .alumni-sidebar { margin-bottom: 20px; }
         .alumni-sidebar .sidebar-card { position: static; }
         .alumni-cta { padding: 24px 20px; }
         .alumni-card-photo { padding: 18px 14px 0; }
         .alumni-card-photo img,
         .alumni-card-photo .photo-placeholder { width: 80px; height: 80px; font-size: 1.6rem; }
         .alumni-card-body { padding: 12px 12px 14px; }
         .alumni-card-name { font-size: .88rem; }
      }
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

   <!-- ══ Hero ══════════════════════════════════════════════════════════════ -->
   <div class="alumni-hero">
      <div class="container">
         <div class="row">
            <div class="col-12">
               <nav aria-label="breadcrumb" class="mb-20">
                  <ol class="breadcrumb" style="background:transparent;padding:0;margin:0;">
                     <li class="breadcrumb-item"><a href="<?= fh(SITE_URL) ?>/index.php" style="color:#FFB81C;">Home</a></li>
                     <li class="breadcrumb-item active" style="color:#E8EEF4;">Alumni</li>
                  </ol>
               </nav>
               <h2 style="color:#fff;font-weight:700;margin-bottom:12px;" class="wow fadeInUp" data-wow-delay=".1s">
                  Our Distinguished Alumni
               </h2>
               <p style="color:rgba(255,255,255,.75);font-size:1.05rem;max-width:520px;margin-bottom:28px;" class="wow fadeInUp" data-wow-delay=".2s">
                  Thousands of Prime University graduates are making their mark across industries and borders.
               </p>

               <!-- Search bar -->
               <form method="GET" class="alumni-search-bar wow fadeInUp" data-wow-delay=".3s">
                  <input type="text" name="q" value="<?= fh($search) ?>"
                         placeholder="Search by name, company, position…">
                  <select name="dept">
                     <option value="0">All Departments</option>
                     <?php foreach ($departments as $d): ?>
                     <?php if ((int)$d['alumni_count'] < 1) continue; ?>
                     <option value="<?= $d['id'] ?>" <?= $f_dept === (int)$d['id'] ? 'selected' : '' ?>><?= fh($d['name']) ?></option>
                     <?php endforeach; ?>
                  </select>
                  <?php if (!empty($batches)): ?>
                  <select name="batch">
                     <option value="">All Batches</option>
                     <?php foreach ($batches as $b): ?>
                     <option value="<?= fh($b) ?>" <?= $f_batch === $b ? 'selected' : '' ?>><?= fh($b) ?> Batch</option>
                     <?php endforeach; ?>
                  </select>
                  <?php endif; ?>
                  <button type="submit" class="btn-search"><i class="fas fa-search me-1"></i> Search</button>
                  <?php if ($search || $f_dept || $f_batch): ?>
                  <a href="/alumni.php" class="btn-clear">
                     <i class="fas fa-times me-1"></i> Clear
                  </a>
                  <?php endif; ?>
               </form>
            </div>
         </div>
      </div>
   </div>

   <!-- ══ Main Content ══════════════════════════════════════════════════════ -->
   <section style="background:#f4f6fb;padding:60px 0 100px;">
      <div class="container">
         <div class="row g-4">

            <!-- ── Sidebar (department filter) ── -->
            <div class="col-lg-3 col-md-4">
               <div class="alumni-sidebar">
                  <div class="sidebar-card">
                     <div style="background:#002147;padding:18px 20px;">
                        <h6 style="color:#fff;margin:0;font-weight:600;font-size:.9rem;">
                           <i class="fas fa-filter me-2" style="color:#FFB81C;"></i> Filter by Department
                        </h6>
                     </div>
                     <ul class="sidebar-dept-list">
                        <li>
                           <a href="?q=<?= urlencode($search) ?>&batch=<?= urlencode($f_batch) ?>&page=1"
                              class="<?= !$f_dept ? 'active' : '' ?>">
                              All Departments
                              <span class="badge-count"><?= $total ?></span>
                           </a>
                        </li>
                        <?php foreach ($departments as $d): ?>
                        <?php if ((int)$d['alumni_count'] < 1) continue; ?>
                        <li>
                           <a href="?dept=<?= $d['id'] ?>&q=<?= urlencode($search) ?>&batch=<?= urlencode($f_batch) ?>&page=1"
                              class="<?= $f_dept === (int)$d['id'] ? 'active' : '' ?>">
                              <?= fh($d['name']) ?>
                              <span class="badge-count"><?= (int)$d['alumni_count'] ?></span>
                           </a>
                        </li>
                        <?php endforeach; ?>
                     </ul>

                     <!-- Register CTA in sidebar -->
                     <div style="padding:20px;">
                        <a href="/alumni-register.php"
                           style="display:block;background:linear-gradient(135deg,#002147,#003d82);color:#fff;
                              border-radius:10px;padding:12px;text-align:center;text-decoration:none;
                              font-weight:600;font-size:.875rem;transition:transform .2s;"
                           onmouseover="this.style.transform='translateY(-2px)'"
                           onmouseout="this.style.transform=''">
                           <i class="fas fa-user-plus me-2"></i> Register as Alumni
                        </a>
                     </div>
                  </div>
               </div>
            </div>

            <!-- ── Alumni grid ── -->
            <div class="col-lg-9 col-md-8">

               <!-- Results info -->
               <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                  <div style="color:#5a6a85;font-size:.875rem;">
                     <?php if ($f_dept || $search || $f_batch): ?>
                        Showing <strong><?= $total ?></strong> result<?= $total !== 1 ? 's' : '' ?>
                        <?php if ($f_dept): ?>
                        in <strong><?= fh(array_column($departments, 'name', 'id')[$f_dept] ?? '') ?></strong>
                        <?php endif; ?>
                        <?php if ($search): ?> for "<em><?= fh($search) ?></em>"<?php endif; ?>
                     <?php else: ?>
                        <strong><?= $total ?></strong> approved alumni profiles
                     <?php endif; ?>
                  </div>
                  <?php if ($pages > 1): ?>
                  <div style="color:#8898aa;font-size:.8rem;">
                     Page <?= $page ?> of <?= $pages ?>
                  </div>
                  <?php endif; ?>
               </div>

               <?php if (empty($alumni)): ?>
               <!-- Empty state -->
               <div class="empty-state wow fadeIn">
                  <i class="fas fa-user-graduate"></i>
                  <h5 style="color:#4a5568;font-weight:600;margin-bottom:8px;">No alumni found</h5>
                  <?php if ($search || $f_batch): ?>
                  <p style="margin-bottom:20px;">Try a different search term or clear your filters.</p>
                  <a href="/alumni.php<?= $f_dept ? '?dept='.$f_dept : '' ?>" class="btn btn-primary" style="border-radius:10px;">
                     Clear Search
                  </a>
                  <?php else: ?>
                  <p>No alumni have been approved for this department yet.</p>
                  <?php endif; ?>
               </div>

               <?php else: ?>
               <!-- Card grid (12 per page) -->
               <div class="row g-3 g-md-4" id="alumniGrid">
                  <?php foreach ($alumni as $i => $al): ?>
                  <div class="col-6 col-md-4 col-xl-3 wow fadeInUp" data-wow-delay="<?= number_format($i * 0.05, 2) ?>s">
                     <div class="alumni-card">
                        <div class="alumni-card-photo">
                           <?php if ($al['photo']): ?>
                           <img src="<?= fh(ADMIN_UPLOAD_URL) ?>/alumni/<?= fh($al['photo']) ?>"
                                alt="<?= fh($al['name']) ?>"
                                onerror="this.style.display='none';this.nextElementSibling.style.display='inline-flex';">
                           <span class="photo-placeholder" style="display:none;"><?= strtoupper(mb_substr($al['name'],0,1)) ?></span>
                           <?php else: ?>
                           <span class="photo-placeholder"><?= strtoupper(mb_substr($al['name'],0,1)) ?></span>
                           <?php endif; ?>
                        </div>
                        <div class="alumni-card-body">
                           <div class="alumni-card-name"><?= fh($al['name']) ?></div>
                           <?php if ($al['position']): ?>
                           <div class="alumni-card-position"><?= fh($al['position']) ?></div>
                           <?php endif; ?>
                           <?php if ($al['company']): ?>
                           <div class="alumni-card-company"><?= fh($al['company']) ?></div>
                           <?php endif; ?>
                           <div class="alumni-card-meta">
                              <?php if ($al['batch']): ?>
                              <span class="alumni-badge"><i class="fas fa-graduation-cap me-1" style="font-size:.7rem;"></i><?= fh($al['batch']) ?> Batch</span>
                              <?php endif; ?>
                              <?php if ($al['dept_name']): ?>
                              <span class="alumni-badge" style="background:#fef3f3;color:#D21034;"><?= fh($al['dept_name']) ?></span>
                              <?php endif; ?>
                           </div>
                           <?php if ($al['linkedin_url'] || $al['fb_url']): ?>
                           <div class="alumni-card-links">
                              <?php if ($al['linkedin_url']): ?>
                              <a href="<?= fh($al['linkedin_url']) ?>" target="_blank" rel="noopener noreferrer"
                                 class="li" title="LinkedIn Profile"><i class="fab fa-linkedin-in"></i></a>
                              <?php endif; ?>
                              <?php if ($al['fb_url']): ?>
                              <a href="<?= fh($al['fb_url']) ?>" target="_blank" rel="noopener noreferrer"
                                 class="fb" title="Facebook Profile"><i class="fab fa-facebook-f"></i></a>
                              <?php endif; ?>
                           </div>
                           <?php endif; ?>
                        </div>
                     </div>
                  </div>
                  <?php endforeach; ?>
               </div>

               <!-- Pagination (prev / numbered / next) -->
               <?php if ($pages > 1):
                  $q_base = http_build_query(array_filter([
                     'dept'  => $f_dept  ?: '',
                     'q'     => $search,
                     'batch' => $f_batch,
                  ]));
                  $q_base = $q_base ? $q_base . '&' : '';
               ?>
               <div class="alumni-pagination wow fadeInUp" data-wow-delay=".2s">
                  <?php if ($page > 1): ?>
                  <a href="?<?= $q_base ?>page=<?= $page - 1 ?>" class="prev-next">
                     <i class="fas fa-chevron-left"></i> Prev
                  </a>
                  <?php endif; ?>

                  <?php
                  // Show window of pages
                  $start = max(1, $page - 2);
                  $end   = min($pages, $page + 2);
                  if ($start > 1): ?>
                  <a href="?<?= $q_base ?>page=1">1</a>
                  <?php if ($start > 2): ?><span style="border:none;width:auto;">…</span><?php endif; ?>
                  <?php endif; ?>

                  <?php for ($p = $start; $p <= $end; $p++): ?>
                  <?php if ($p === $page): ?>
                  <span class="current"><?= $p ?></span>
                  <?php else: ?>
                  <a href="?<?= $q_base ?>page=<?= $p ?>"><?= $p ?></a>
                  <?php endif; ?>
                  <?php endfor; ?>

                  <?php if ($end < $pages): ?>
                  <?php if ($end < $pages - 1): ?><span style="border:none;width:auto;">…</span><?php endif; ?>
                  <a href="?<?= $q_base ?>page=<?= $pages ?>"><?= $pages ?></a>
                  <?php endif; ?>

                  <?php if ($page < $pages): ?>
                  <a href="?<?= $q_base ?>page=<?= $page + 1 ?>" class="prev-next">
                     Next <i class="fas fa-chevron-right"></i>
                  </a>
                  <?php endif; ?>
               </div>
               <?php endif; ?>

               <?php endif; ?>
            </div><!-- /col -->
         </div><!-- /row -->
      </div><!-- /container -->
   </section>

   <!-- ══ Join Banner ══════════════════════════════════════════════════════ -->
   <section style="background:#fff;padding:60px 0;">
      <div class="container">
         <div class="alumni-cta wow fadeInUp" data-wow-delay=".1s">
            <div class="row align-items-center gy-3">
               <div class="col-lg-8">
                  <h4 style="font-weight:700;margin-bottom:8px;"><i class="fas fa-user-graduate me-2" style="color:#FFB81C;"></i> Are you a Prime University alumnus?</h4>
                  <p style="color:rgba(255,255,255,.8);margin:0;font-size:1rem;">
                     Join our alumni directory. Share your journey, inspire current students, and stay connected with your university.
                  </p>
               </div>
               <div class="col-lg-4 text-lg-end">
                  <a href="/alumni-register.php"
                     style="display:inline-block;background:#FFB81C;color:#002147;border-radius:12px;
                        padding:14px 32px;font-weight:700;font-size:.95rem;text-decoration:none;
                        transition:transform .2s,box-shadow .2s;"
                     onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 10px 30px rgba(0,0,0,.2)'"
                     onmouseout="this.style.transform='';this.style.boxShadow=''">
                     <i class="fas fa-paper-plane me-2"></i> Register Now
                  </a>
               </div>
            </div>
         </div>
      </div>
   </section>

   </main>

<?php include __DIR__ . '/includes/footer.php'; ?>
<?php include __DIR__ . '/includes/scripts.php'; ?>
<script>
// Re-init WOW animations when page ready
if (typeof WOW !== 'undefined') { new WOW({ offset: 30 }).init(); }
</script>
</body>
</html>
