<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/includes/config.php';
$_apply_now_url = 'https://primeuniversity.ac.bd/apply-now.php';

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

// Fetch dynamic course curriculum from the database
$curriculum_data = [];
try {
    $db2 = front_db();
    if ($db2) {
        $cst = $db2->prepare(
            "SELECT semester, sl_no, bnqf_code, course_code, course_name, credit
               FROM course_curriculum
              WHERE program_id = ?
              ORDER BY semester ASC, sort_order ASC, sl_no ASC, id ASC"
        );
        $cst->execute([$id]);
        foreach ($cst->fetchAll() as $crow) {
            $curriculum_data[(int)$crow['semester']][] = $crow;
        }
    }
} catch (Throwable $e) {}

// Fetch intake periods
$intake_periods = [];
try {
    $db3 = front_db();
    if ($db3) {
        $ist = $db3->prepare(
            "SELECT * FROM program_intake_periods
              WHERE program_id = ? AND is_active = 1
              ORDER BY sort_order ASC, id DESC"
        );
        $ist->execute([$id]);
        $intake_periods = $ist->fetchAll();
    }
} catch (Throwable $e) {}

// Fetch eligibility criteria grouped by category
$eligibility_raw = [];
try {
    $db4 = front_db();
    if ($db4) {
        $est = $db4->prepare(
            "SELECT * FROM program_eligibility_criteria
              WHERE program_id = ? AND is_active = 1
              ORDER BY sort_order ASC, id ASC"
        );
        $est->execute([$id]);
        foreach ($est->fetchAll() as $er) {
            $eligibility_raw[$er['category']][] = $er;
        }
    }
} catch (Throwable $e) {}

function cc_pub_semester_label(int $n): string
{
    static $labels = [
        1  => '1st Year 1st Semester',
        2  => '1st Year 2nd Semester',
        3  => '1st Year 3rd Semester',
        4  => '2nd Year 1st Semester',
        5  => '2nd Year 2nd Semester',
        6  => '2nd Year 3rd Semester',
        7  => '3rd Year 1st Semester',
        8  => '3rd Year 2nd Semester',
        9  => '3rd Year 3rd Semester',
        10 => '4th Year 1st Semester',
        11 => '4th Year 2nd Semester',
        12 => '4th Year 3rd Semester',
    ];
    return $labels[$n] ?? "Semester $n";
}

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

function ap_intake_status_badge(string $status): string {
    return match ($status) {
        'open'     => '<span class="ap-intake-badge ap-badge-open"><i class="fas fa-circle-dot me-1"></i>Open</span>',
        'upcoming' => '<span class="ap-intake-badge ap-badge-upcoming"><i class="fas fa-clock me-1"></i>Upcoming</span>',
        'closed'   => '<span class="ap-intake-badge ap-badge-closed"><i class="fas fa-circle-xmark me-1"></i>Closed</span>',
        default    => ''
    };
}

function ap_safe_date(mixed $val): string {
    if (empty($val) || $val === '0000-00-00') return '';
    $ts = strtotime((string)$val);
    return $ts !== false ? date('d M Y', $ts) : '';
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
   /* ── Sub-nav ── */
   .it-dept-subnav { background-color: #002147; position: sticky; top: 0; z-index: 999; border-bottom: 3px solid #D21034; }
   .dept-subnav-inner { display: flex; overflow-x: auto; }
   .dept-subnav-inner ul { display: flex; list-style: none; margin: 0; padding: 0; flex-wrap: nowrap; gap: 0; }
   .dept-subnav-inner ul li a { display: block; color: #E8EEF4; text-decoration: none; padding: 14px 20px; font-size: 14px; font-weight: 500; white-space: nowrap; border-bottom: 3px solid transparent; transition: all 0.3s ease; }
   .dept-subnav-inner ul li a:hover, .dept-subnav-inner ul li a.active { color: #FFB81C; border-bottom-color: #FFB81C; background-color: rgba(255,255,255,0.05); }
   @media (max-width: 768px) { .dept-subnav-inner ul li a { padding: 12px 14px; font-size: 13px; } }

   /* ── Program stat boxes ── */
   .prog-stat { text-align: center; padding: 18px 12px; background: #F8FAFC; border-radius: 10px; border: 1px solid #E2E8F0; }
   .prog-stat .value { font-size: 20px; font-weight: 700; color: #002147; display: block; line-height: 1.2; }
   .prog-stat .label { font-size: 11px; color: #64748B; font-weight: 500; margin-top: 4px; display: block; text-transform: uppercase; letter-spacing: .04em; }

   /* ── Rich content (TinyMCE output) ── */
   .prog-details-content table { width: 100%; border-collapse: collapse; margin-bottom: 20px; overflow-x: auto; display: block; }
   .prog-details-content table th, .prog-details-content table td { border: 1px solid #dee2e6; padding: 10px 14px; font-size: 14px; }
   .prog-details-content table th { background-color: #002147; color: #fff; font-weight: 600; }
   .prog-details-content table tr:nth-child(even) td { background-color: #f8fafc; }
   .prog-details-content h4, .prog-details-content h5 { color: #002147; margin-top: 24px; margin-bottom: 12px; }
   .prog-details-content ul, .prog-details-content ol { padding-left: 24px; margin-bottom: 16px; }
   .prog-details-content li { margin-bottom: 6px; color: #334155; font-size: 14px; line-height: 1.7; }
   .prog-details-content p { color: #334155; font-size: 15px; line-height: 1.8; }

   /* ── Section card headers ── */
   .ap-section-header { background: linear-gradient(90deg, #002147 0%, #003366 100%); padding: 18px 24px; border-radius: 10px 10px 0 0; display: flex; align-items: center; justify-content: space-between; cursor: pointer; user-select: none; }
   .ap-section-header h5 { color: #fff; margin: 0; font-size: 17px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
   .ap-section-header .ap-toggle-icon { color: #FFB81C; font-size: 16px; transition: transform .3s; }
   .ap-section-header.collapsed .ap-toggle-icon { transform: rotate(-90deg); }
   .ap-section-body { border: 1px solid #E2E8F0; border-top: none; border-radius: 0 0 10px 10px; }

   /* ── Curriculum accordion ── */
   .ap-sem-header { background: #F1F5F9; border-left: 4px solid #D21034; padding: 12px 18px; cursor: pointer; display: flex; align-items: center; justify-content: space-between; transition: background .2s; }
   .ap-sem-header:hover { background: #E8EEF4; }
   .ap-sem-header .ap-sem-title { display: flex; align-items: center; gap: 10px; font-size: 14px; font-weight: 700; color: #002147; }
   .ap-sem-header .ap-sem-badge { background: #D21034; color: #fff; border-radius: 50%; width: 24px; height: 24px; display: inline-flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; flex-shrink: 0; }
   .ap-sem-header .ap-sem-meta { font-size: 12px; color: #64748B; font-weight: 400; }
   .ap-sem-header .ap-sem-arrow { color: #94A3B8; font-size: 13px; transition: transform .3s; flex-shrink: 0; }
   .ap-sem-header.open .ap-sem-arrow { transform: rotate(180deg); }
   .ap-sem-body { display: none; }
   .ap-sem-body.open { display: block; }

   /* ── Intake periods ── */
   .ap-intake-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; padding: 24px; }
   .ap-intake-card { border: 1px solid #E2E8F0; border-radius: 10px; padding: 20px; background: #FAFBFC; position: relative; overflow: hidden; }
   .ap-intake-card::before { content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%; background: #002147; }
   .ap-intake-card.status-open::before { background: #16A34A; }
   .ap-intake-card.status-upcoming::before { background: #D97706; }
   .ap-intake-card.status-closed::before { background: #64748B; }
   .ap-intake-name { font-size: 16px; font-weight: 700; color: #002147; margin-bottom: 10px; }
   .ap-intake-dates { font-size: 13px; color: #64748B; margin-bottom: 8px; }
   .ap-intake-notes { font-size: 13px; color: #475569; margin-top: 8px; line-height: 1.6; }
   .ap-intake-badge { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 600; padding: 4px 12px; border-radius: 20px; }
   .ap-badge-open { background: #DCFCE7; color: #16A34A; }
   .ap-badge-upcoming { background: #FEF3C7; color: #D97706; }
   .ap-badge-closed { background: #F1F5F9; color: #64748B; }

   /* ── Eligibility criteria ── */
   .ap-elig-category { margin-bottom: 20px; }
   .ap-elig-cat-label { font-size: 13px; font-weight: 700; color: #002147; text-transform: uppercase; letter-spacing: .06em; padding: 6px 14px; background: #EEF2FF; border-radius: 20px; display: inline-block; margin-bottom: 12px; }
   .ap-elig-list { list-style: none; padding: 0; margin: 0; }
   .ap-elig-list li { display: flex; align-items: flex-start; gap: 10px; padding: 10px 0; border-bottom: 1px dashed #E2E8F0; font-size: 14px; color: #334155; line-height: 1.65; }
   .ap-elig-list li:last-child { border-bottom: none; }
   .ap-elig-list li::before { content: '\f058'; font-family: "Font Awesome 5 Pro", "Font Awesome 5 Free"; font-weight: 900; color: #16A34A; font-size: 15px; flex-shrink: 0; margin-top: 1px; }

   /* ── Sidebar ── */
   .ap-sidebar-card { border: 1px solid #E2E8F0; border-radius: 12px; overflow: hidden; margin-bottom: 20px; }
   .ap-sidebar-card-header { background: #002147; padding: 14px 20px; }
   .ap-sidebar-card-header h6 { color: #FFB81C; margin: 0; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; }
   .ap-sidebar-card-body { padding: 16px 20px; background: #fff; }
   .ap-sidebar-stat { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid #F1F5F9; }
   .ap-sidebar-stat:last-child { border-bottom: none; }
   .ap-sidebar-stat .icon { width: 36px; height: 36px; border-radius: 8px; background: #EEF2FF; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
   .ap-sidebar-stat .icon i { color: #002147; font-size: 15px; }
   .ap-sidebar-stat .info .label { font-size: 11px; color: #94A3B8; text-transform: uppercase; letter-spacing: .05em; }
   .ap-sidebar-stat .info .value { font-size: 14px; font-weight: 600; color: #1E293B; line-height: 1.3; }

   /* ── Apply button ── */
   .ap-apply-btn { display: block; width: 100%; background: linear-gradient(135deg, #D21034, #b30029); color: #fff; text-align: center; padding: 14px 20px; border-radius: 10px; font-size: 15px; font-weight: 700; text-decoration: none; transition: opacity .2s; }
   .ap-apply-btn:hover { opacity: .9; color: #fff; }

   /* ── Responsive tweaks ── */
   @media (max-width: 991.98px) {
       .ap-sticky-sidebar { position: static !important; }
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
            <a href="<?= fh(SITE_URL) ?>/index.php"><img src="/assets/img/logo/logo-black.png" alt=""></a>
         </div>
         <div class="it-menu-mobile d-xl-none"></div>
         <div class="itoffcanvas__info">
            <h3 class="offcanva-title">Get In Touch</h3>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon"><a href="#"><i class="fal fa-envelope"></i></a></div>
               <div class="itoffcanvas__info-address"><span>Email</span><a href="mailto:info@primeuniversity.ac.bd">info@primeuniversity.ac.bd</a></div>
            </div>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon"><a href="#"><i class="fal fa-phone-alt"></i></a></div>
               <div class="itoffcanvas__info-address"><span>Phone</span><a href="tel:+8801969955566">01969-955566</a></div>
            </div>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon"><a href="#"><i class="fas fa-map-marker-alt"></i></a></div>
               <div class="itoffcanvas__info-address"><span>Location</span><a href="https://www.google.com/maps/@37.4801311,22.8928877,3z" target="_blank">114/116, Mazar Rd, Dhaka-1216</a></div>
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

   <!-- Hero Banner -->
   <div style="background: linear-gradient(135deg, #001a3a 0%, #002e6b 100%); padding: 70px 0 50px; position: relative; overflow: hidden;">
      <div style="position:absolute;top:0;right:0;width:400px;height:100%;background:rgba(255,255,255,.03);clip-path:polygon(30% 0,100% 0,100% 100%,0 100%);pointer-events:none;"></div>
      <div class="container">
         <div class="row align-items-end">
            <div class="col-lg-9">
               <nav aria-label="breadcrumb" class="mb-3">
                  <ol class="breadcrumb" style="background:transparent; padding:0; margin:0; flex-wrap:wrap;">
                     <li class="breadcrumb-item"><a href="<?= fh(SITE_URL) ?>/index.php" style="color:#FFB81C;">Home</a></li>
                     <li class="breadcrumb-item"><a href="<?= fh(SITE_URL) ?>/department/<?= urlencode($slug) ?>" style="color:#9CB3CC;"><?= $dept_name ?></a></li>
                     <li class="breadcrumb-item"><a href="<?= fh(SITE_URL) ?>/department-academic-programs.php?slug=<?= urlencode($slug) ?>" style="color:#9CB3CC;">Academic Programs</a></li>
                     <li class="breadcrumb-item active" style="color:#FFFFFF;"><?= fh($program['program_name']) ?></li>
                  </ol>
               </nav>
               <?php if (!empty($program['degree_type'])): ?>
               <span style="background:<?= ap_degree_color($program['degree_type']) ?>; color:#fff; font-size:12px; padding:5px 16px; border-radius:20px; font-weight:600; display:inline-block; margin-bottom:12px;"><?= fh($program['degree_type']) ?></span>
               <?php endif; ?>
               <h1 style="color:#FFFFFF; font-weight:800; margin-bottom:10px; font-size:clamp(24px,4vw,38px); line-height:1.2;"><?= fh($program['program_name']) ?></h1>
               <p style="color:#9CB3CC; font-size:15px; margin-bottom:0;"><?= $dept_name ?> &mdash; Prime University</p>
            </div>
            <?php if (!empty($program['attachment'])): ?>
            <div class="col-lg-3 mt-4 mt-lg-0 text-lg-end">
               <a href="<?= fh(ADMIN_UPLOAD_URL . '/departments/' . $program['attachment']) ?>"
                  class="d-inline-flex align-items-center gap-2"
                  style="background:#D21034; color:#FFFFFF; padding:12px 24px; border-radius:25px; font-size:14px; font-weight:700; text-decoration:none; white-space:nowrap;"
                  target="_blank" rel="noopener" download>
                  <i class="fas fa-download"></i> Download Brochure
               </a>
            </div>
            <?php endif; ?>
         </div>
      </div>
   </div>

   <!-- Sub-navigation -->
   <?php include __DIR__ . '/includes/dept-subnav.php'; ?>

   <!-- Quick stats bar -->
   <?php $has_stats = !empty($program['duration']) || !empty($program['total_credit']) || !empty($program['semester_type']); ?>
   <?php if ($has_stats): ?>
   <div style="background:#F8FAFC; border-bottom:1px solid #E2E8F0; padding:0;">
      <div class="container">
         <div class="row g-0">
            <?php if (!empty($program['duration'])): ?>
            <div class="col-6 col-md-3" style="border-right:1px solid #E2E8F0;">
               <div style="padding:16px 20px; text-align:center;">
                  <div style="font-size:18px; font-weight:800; color:#002147;"><?= fh($program['duration']) ?></div>
                  <div style="font-size:11px; color:#64748B; text-transform:uppercase; letter-spacing:.05em;"><i class="fas fa-clock me-1" style="color:#D21034;"></i>Duration</div>
               </div>
            </div>
            <?php endif; ?>
            <?php if (!empty($program['total_credit'])): ?>
            <div class="col-6 col-md-3" style="border-right:1px solid #E2E8F0;">
               <div style="padding:16px 20px; text-align:center;">
                  <div style="font-size:18px; font-weight:800; color:#002147;"><?= fh($program['total_credit']) ?></div>
                  <div style="font-size:11px; color:#64748B; text-transform:uppercase; letter-spacing:.05em;"><i class="fas fa-book me-1" style="color:#D21034;"></i>Total Credits</div>
               </div>
            </div>
            <?php endif; ?>
            <?php if (!empty($program['semester_type'])): ?>
            <?php $semester_label = ap_semester_label($program['semester_type']); ?>
            <?php if ($semester_label !== ''): ?>
            <div class="col-6 col-md-3" style="border-right:1px solid #E2E8F0;">
               <div style="padding:16px 20px; text-align:center;">
                  <div style="font-size:14px; font-weight:700; color:#002147;"><?= fh($semester_label) ?></div>
                  <div style="font-size:11px; color:#64748B; text-transform:uppercase; letter-spacing:.05em;"><i class="fas fa-calendar-alt me-1" style="color:#D21034;"></i>Semester System</div>
               </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
            <div class="col-6 col-md-3">
               <div style="padding:16px 20px; text-align:center;">
                  <div style="font-size:14px; font-weight:700; color:#002147; line-height:1.3;"><?= $dept_name ?></div>
                  <div style="font-size:11px; color:#64748B; text-transform:uppercase; letter-spacing:.05em;"><i class="fas fa-university me-1" style="color:#D21034;"></i>Department</div>
               </div>
            </div>
         </div>
      </div>
   </div>
   <?php endif; ?>

   <!-- Main content -->
   <section style="background:#F4F6F9; padding:48px 0 72px;">
      <div class="container">
         <div class="row g-4">

            <!-- Main column -->
            <div class="col-lg-8">

               <?php if (!empty($program['description'])): ?>
               <!-- Overview -->
               <div style="background:#fff; border-radius:12px; padding:28px 30px; margin-bottom:24px; box-shadow:0 1px 4px rgba(0,0,0,.06);">
                  <h5 style="color:#002147; font-weight:700; margin-bottom:14px; display:flex; align-items:center; gap:8px;">
                     <i class="fas fa-info-circle" style="color:#D21034;"></i> Program Overview
                  </h5>
                  <p style="color:#475569; font-size:15px; line-height:1.85; margin:0;"><?= nl2br(fh($program['description'])) ?></p>
               </div>
               <?php endif; ?>

               <?php if (!empty($program['details_content'])): ?>
               <!-- Program Details -->
               <div style="background:#fff; border-radius:12px; margin-bottom:24px; box-shadow:0 1px 4px rgba(0,0,0,.06); overflow:hidden;">
                  <div class="ap-section-header" data-bs-toggle="collapse" data-bs-target="#sec-details" aria-expanded="true">
                     <h5><i class="fas fa-file-alt" style="color:#FFB81C;"></i> Program Details</h5>
                     <i class="fas fa-chevron-down ap-toggle-icon"></i>
                  </div>
                  <div id="sec-details" class="collapse show ap-section-body">
                     <div class="prog-details-content" style="padding:24px 28px;">
                        <?= $program['details_content'] ?>
                     </div>
                  </div>
               </div>
               <?php endif; ?>

               <?php if (!empty($intake_periods)): ?>
               <!-- Intake Periods -->
               <div style="background:#fff; border-radius:12px; margin-bottom:24px; box-shadow:0 1px 4px rgba(0,0,0,.06); overflow:hidden;">
                  <div class="ap-section-header" data-bs-toggle="collapse" data-bs-target="#sec-intake" aria-expanded="true">
                     <h5><i class="fas fa-calendar-alt" style="color:#FFB81C;"></i> Intake Periods</h5>
                     <i class="fas fa-chevron-down ap-toggle-icon"></i>
                  </div>
                  <div id="sec-intake" class="collapse show ap-section-body">
                     <div class="ap-intake-grid">
                        <?php foreach ($intake_periods as $ip): ?>
                        <div class="ap-intake-card status-<?= fh($ip['intake_status']) ?>">
                           <div class="ap-intake-name"><?= fh($ip['intake_name']) ?></div>
                           <?= ap_intake_status_badge($ip['intake_status']) ?>
                           <?php $od = ap_safe_date($ip['open_date']); $cd = ap_safe_date($ip['close_date']); ?>
                           <?php if ($od !== '' || $cd !== ''): ?>
                           <div class="ap-intake-dates mt-2">
                              <?php if ($od !== ''): ?>
                              <span><i class="fas fa-play-circle me-1" style="color:#16A34A;"></i>Opens: <?= fh($od) ?></span>
                              <?php endif; ?>
                              <?php if ($cd !== ''): ?>
                              <span class="ms-2"><i class="fas fa-stop-circle me-1" style="color:#D21034;"></i>Closes: <?= fh($cd) ?></span>
                              <?php endif; ?>
                           </div>
                           <?php endif; ?>
                           <?php if (!empty($ip['notes'])): ?>
                           <div class="ap-intake-notes"><?= nl2br(fh($ip['notes'])) ?></div>
                           <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                     </div>
                  </div>
               </div>
               <?php endif; ?>

               <?php if (!empty($eligibility_raw)): ?>
               <!-- Eligibility Criteria -->
               <div style="background:#fff; border-radius:12px; margin-bottom:24px; box-shadow:0 1px 4px rgba(0,0,0,.06); overflow:hidden;">
                  <div class="ap-section-header" data-bs-toggle="collapse" data-bs-target="#sec-elig" aria-expanded="true">
                     <h5><i class="fas fa-user-check" style="color:#FFB81C;"></i> Eligibility Criteria</h5>
                     <i class="fas fa-chevron-down ap-toggle-icon"></i>
                  </div>
                  <div id="sec-elig" class="collapse show ap-section-body">
                     <div style="padding:24px 28px;">
                        <?php foreach ($eligibility_raw as $cat => $criteria): ?>
                        <div class="ap-elig-category">
                           <div class="ap-elig-cat-label"><?= fh($cat) ?></div>
                           <ul class="ap-elig-list">
                              <?php foreach ($criteria as $cr): ?>
                              <li><?= nl2br(fh($cr['criterion'])) ?></li>
                              <?php endforeach; ?>
                           </ul>
                        </div>
                        <?php endforeach; ?>
                     </div>
                  </div>
               </div>
               <?php endif; ?>

               <?php if (!empty($program['fees_content'])): ?>
               <!-- Fees Structure -->
               <div style="background:#fff; border-radius:12px; margin-bottom:24px; box-shadow:0 1px 4px rgba(0,0,0,.06); overflow:hidden;">
                  <div class="ap-section-header" data-bs-toggle="collapse" data-bs-target="#sec-fees" aria-expanded="true">
                     <h5><i class="fas fa-money-bill-wave" style="color:#FFB81C;"></i> Fees Structure</h5>
                     <i class="fas fa-chevron-down ap-toggle-icon"></i>
                  </div>
                  <div id="sec-fees" class="collapse show ap-section-body">
                     <div class="prog-details-content" style="padding:24px 28px;">
                        <?= $program['fees_content'] ?>
                     </div>
                  </div>
               </div>
               <?php endif; ?>

               <?php if (!empty($curriculum_data)): ?>
               <!-- Dynamic Course Curriculum -->
               <div style="background:#fff; border-radius:12px; margin-bottom:24px; box-shadow:0 1px 4px rgba(0,0,0,.06); overflow:hidden;">
                  <div style="background: linear-gradient(90deg, #002147 0%, #003366 100%); padding: 18px 24px; border-radius: 10px 10px 0 0;">
                     <h5 style="color:#fff; margin:0; font-size:17px; font-weight:700; display:flex; align-items:center; gap:10px;">
                        <i class="fas fa-graduation-cap" style="color:#FFB81C;"></i>
                        Course Curriculum
                        <?php if (!empty($program['total_credit']) || !empty($program['duration'])): ?>
                        <span style="font-size:13px; font-weight:400; color:#9CB3CC; margin-left:4px;">
                           (<?= implode(' &ndash; ', array_filter([fh($program['total_credit'] ?? ''), fh($program['duration'] ?? '')])) ?>)
                        </span>
                        <?php endif; ?>
                     </h5>
                  </div>
                  <div style="border:1px solid #E2E8F0; border-top:none; border-radius:0 0 10px 10px; overflow:hidden;">
                     <?php $sem_index = 0; ?>
                     <?php for ($sem_n = 1; $sem_n <= 12; $sem_n++): ?>
                     <?php $sem_rows = $curriculum_data[$sem_n] ?? []; if (empty($sem_rows)) continue; ?>
                     <?php $sem_id = 'sem-' . $sem_n; $sem_open = ($sem_index === 0); $sem_index++; ?>
                     <div style="border-bottom:1px solid #E2E8F0;">
                        <div class="ap-sem-header <?= $sem_open ? 'open' : '' ?>" data-target="<?= $sem_id ?>">
                           <div class="ap-sem-title">
                              <span class="ap-sem-badge"><?= $sem_n ?></span>
                              <?= fh(cc_pub_semester_label($sem_n)) ?>
                              <span class="ap-sem-meta">
                                 &nbsp;&middot; <?= count($sem_rows) ?> course<?= count($sem_rows) !== 1 ? 's' : '' ?>
                                 &nbsp;&middot; <?= number_format((float)array_sum(array_column($sem_rows, 'credit')), 2) ?> credits
                              </span>
                           </div>
                           <i class="fas fa-chevron-down ap-sem-arrow"></i>
                        </div>
                        <div class="ap-sem-body <?= $sem_open ? 'open' : '' ?>" id="<?= $sem_id ?>">
                           <div class="table-responsive">
                              <table style="width:100%; border-collapse:collapse; font-size:13px;">
                                 <thead>
                                    <tr style="background-color:#002147;">
                                       <th style="color:#fff; padding:10px 14px; font-weight:600; width:48px;">SL</th>
                                       <th style="color:#fff; padding:10px 14px; font-weight:600; width:100px;">BNQF Code</th>
                                       <th style="color:#fff; padding:10px 14px; font-weight:600; width:110px;">Course Code</th>
                                       <th style="color:#fff; padding:10px 14px; font-weight:600;">Course Name</th>
                                       <th style="color:#fff; padding:10px 14px; font-weight:600; width:70px; text-align:center;">Credit</th>
                                    </tr>
                                 </thead>
                                 <tbody>
                                    <?php foreach ($sem_rows as $i => $cr): ?>
                                    <tr style="<?= $i % 2 === 0 ? 'background:#fff;' : 'background:#F8FAFC;' ?>">
                                       <td style="padding:10px 14px; color:#334155; border-bottom:1px solid #E2E8F0;"><?= fh($cr['sl_no']) ?></td>
                                       <td style="padding:10px 14px; color:#334155; border-bottom:1px solid #E2E8F0; font-size:12px;">
                                          <?= !empty($cr['bnqf_code']) ? fh($cr['bnqf_code']) : '<span style="color:#94A3B8;">—</span>' ?>
                                       </td>
                                       <td style="padding:10px 14px; border-bottom:1px solid #E2E8F0;">
                                          <?php if (!empty($cr['course_code'])): ?>
                                          <span style="background:#EEF2FF; color:#4F46E5; padding:3px 8px; border-radius:4px; font-size:12px; font-weight:600;"><?= fh($cr['course_code']) ?></span>
                                          <?php else: ?><span style="color:#94A3B8;">—</span><?php endif; ?>
                                       </td>
                                       <td style="padding:10px 14px; color:#1E293B; font-weight:500; border-bottom:1px solid #E2E8F0;"><?= fh($cr['course_name']) ?></td>
                                       <td style="padding:10px 14px; text-align:center; border-bottom:1px solid #E2E8F0;">
                                          <?php if ($cr['credit'] !== null): ?>
                                          <span style="background:#002147; color:#fff; padding:3px 10px; border-radius:12px; font-size:12px; font-weight:600;"><?= fh(rtrim(rtrim(number_format((float)$cr['credit'], 2), '0'), '.')) ?></span>
                                          <?php else: ?><span style="color:#94A3B8;">—</span><?php endif; ?>
                                       </td>
                                    </tr>
                                    <?php endforeach; ?>
                                 </tbody>
                                 <tfoot>
                                    <tr style="background-color:#F1F5F9;">
                                       <td colspan="4" style="padding:10px 14px; font-size:12px; color:#64748B; font-weight:600;">
                                          Semester Total
                                       </td>
                                       <td style="padding:10px 14px; text-align:center; font-weight:700; color:#002147; font-size:13px;">
                                          <?= number_format((float)array_sum(array_column($sem_rows, 'credit')), 2) ?>
                                       </td>
                                    </tr>
                                 </tfoot>
                              </table>
                           </div>
                        </div>
                     </div>
                     <?php endfor; ?>
                  </div>
               </div>
               <?php elseif (!empty($program['curriculum_content'])): ?>
               <!-- Fallback: static curriculum -->
               <div style="background:#fff; border-radius:12px; margin-bottom:24px; box-shadow:0 1px 4px rgba(0,0,0,.06); overflow:hidden;">
                  <div class="ap-section-header" data-bs-toggle="collapse" data-bs-target="#sec-curriculum" aria-expanded="true">
                     <h5><i class="fas fa-graduation-cap" style="color:#FFB81C;"></i> Course Curriculum</h5>
                     <i class="fas fa-chevron-down ap-toggle-icon"></i>
                  </div>
                  <div id="sec-curriculum" class="collapse show ap-section-body">
                     <div class="prog-details-content" style="padding:24px 28px;">
                        <?= $program['curriculum_content'] ?>
                     </div>
                  </div>
               </div>
               <?php endif; ?>

               <!-- Back link -->
               <div style="padding-top:8px;">
                  <a href="<?= fh(SITE_URL) ?>/department-academic-programs.php?slug=<?= urlencode($slug) ?>"
                     style="color:#002147; font-size:14px; font-weight:600; text-decoration:none;">
                     <i class="fas fa-arrow-left me-2"></i>Back to All Academic Programs
                  </a>
               </div>

            </div><!-- /main column -->

            <!-- Sidebar -->
            <div class="col-lg-4">
               <div class="ap-sticky-sidebar" style="position:sticky; top:80px;">

                  <!-- Apply CTA -->
                  <div style="background:linear-gradient(135deg,#002147,#003366); border-radius:12px; padding:24px; margin-bottom:20px; text-align:center;">
                     <div style="font-size:13px; color:#9CB3CC; margin-bottom:6px; text-transform:uppercase; letter-spacing:.06em;">Ready to join?</div>
                     <div style="font-size:20px; font-weight:800; color:#fff; margin-bottom:16px;">Apply Now</div>
                     <a href="https://primeuniversity.ac.bd/apply-now.php" class="ap-apply-btn">
                        <i class="fas fa-paper-plane me-2"></i>Start Application
                     </a>
                     <div style="margin-top:14px;">
                        <a href="<?= fh(SITE_URL) ?>/contact.php" style="color:#9CB3CC; font-size:13px; text-decoration:none;">
                           <i class="fas fa-question-circle me-1"></i>Have questions? Contact us
                        </a>
                     </div>
                  </div>

                  <!-- Program at a Glance -->
                  <div class="ap-sidebar-card">
                     <div class="ap-sidebar-card-header">
                        <h6><i class="fas fa-list-ul me-2"></i>Program at a Glance</h6>
                     </div>
                     <div class="ap-sidebar-card-body">
                        <?php if (!empty($program['degree_type'])): ?>
                        <div class="ap-sidebar-stat">
                           <div class="icon"><i class="fas fa-graduation-cap"></i></div>
                           <div class="info">
                              <div class="label">Degree Type</div>
                              <div class="value"><?= fh($program['degree_type']) ?></div>
                           </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($program['duration'])): ?>
                        <div class="ap-sidebar-stat">
                           <div class="icon"><i class="fas fa-clock"></i></div>
                           <div class="info">
                              <div class="label">Duration</div>
                              <div class="value"><?= fh($program['duration']) ?></div>
                           </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($program['total_credit'])): ?>
                        <div class="ap-sidebar-stat">
                           <div class="icon"><i class="fas fa-book-open"></i></div>
                           <div class="info">
                              <div class="label">Total Credits</div>
                              <div class="value"><?= fh($program['total_credit']) ?></div>
                           </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($program['semester_type'])): ?>
                        <?php $sl = ap_semester_label($program['semester_type']); ?>
                        <?php if ($sl !== ''): ?>
                        <div class="ap-sidebar-stat">
                           <div class="icon"><i class="fas fa-calendar-alt"></i></div>
                           <div class="info">
                              <div class="label">Semester System</div>
                              <div class="value"><?= fh($sl) ?></div>
                           </div>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                        <div class="ap-sidebar-stat">
                           <div class="icon"><i class="fas fa-university"></i></div>
                           <div class="info">
                              <div class="label">Department</div>
                              <div class="value"><?= $dept_name ?></div>
                           </div>
                        </div>
                     </div>
                  </div>

                  <?php if (!empty($intake_periods)): ?>
                  <!-- Current / Upcoming Intakes sidebar widget -->
                  <?php $active_intakes = array_filter($intake_periods, fn($i) => in_array($i['intake_status'], ['open','upcoming'])); ?>
                  <?php if (!empty($active_intakes)): ?>
                  <div class="ap-sidebar-card">
                     <div class="ap-sidebar-card-header">
                        <h6><i class="fas fa-calendar-check me-2"></i>Current / Upcoming Intakes</h6>
                     </div>
                     <div class="ap-sidebar-card-body">
                        <?php foreach ($active_intakes as $ip): ?>
                        <div style="padding:10px 0; border-bottom:1px solid #F1F5F9;">
                           <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:4px;">
                              <span style="font-size:14px; font-weight:600; color:#1E293B;"><?= fh($ip['intake_name']) ?></span>
                              <?= ap_intake_status_badge($ip['intake_status']) ?>
                           </div>
                           <?php $cd2 = ap_safe_date($ip['close_date']); if ($cd2 !== ''): ?>
                           <div style="font-size:12px; color:#64748B;"><i class="fas fa-hourglass-end me-1" style="color:#D21034;"></i>Deadline: <?= fh($cd2) ?></div>
                           <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                     </div>
                  </div>
                  <?php endif; ?>
                  <?php endif; ?>

               </div>
            </div><!-- /sidebar -->

         </div>
      </div>
   </section>

   </main>

<?php include __DIR__ . '/includes/footer.php'; ?>

   <?php include __DIR__ . '/includes/scripts.php'; ?>
   <script>
   // Toggle chevron direction on Bootstrap collapse
   document.querySelectorAll('[data-bs-toggle="collapse"]').forEach(function(trigger) {
       var target = document.querySelector(trigger.getAttribute('data-bs-target'));
       if (!target) return;
       target.addEventListener('show.bs.collapse', function() {
           trigger.querySelector('.ap-toggle-icon').style.transform = 'rotate(0deg)';
       });
       target.addEventListener('hide.bs.collapse', function() {
           trigger.querySelector('.ap-toggle-icon').style.transform = 'rotate(-90deg)';
       });
   });

   // Custom accordion for course curriculum semesters
   document.querySelectorAll('.ap-sem-header').forEach(function(header) {
       header.addEventListener('click', function() {
           var targetId = this.getAttribute('data-target');
           var body = document.getElementById(targetId);
           if (!body) return;
           var isOpen = body.classList.contains('open');
           body.classList.toggle('open', !isOpen);
           this.classList.toggle('open', !isOpen);
       });
   });
   </script>
</body>
</html>
