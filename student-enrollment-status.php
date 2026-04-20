<?php
/**
 * Public Student Enrollment Status Page
 * Displays enrollment details for a student by Student ID lookup.
 * Standalone – uses the student_enrollment_status table only.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/seo.php';

$page_title = 'Student Enrollment Status – Prime University';

// ── Search state ──────────────────────────────────────────────────────────────
$form_error = '';
$submitted  = false;
$student    = null;
$sid_input  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sid_input = trim($_POST['student_id'] ?? '');

    if ($sid_input === '') {
        $form_error = 'Please enter a Student ID to check enrollment status.';
    } else {
        try {
            $db = front_db();
            if (!$db) {
                $form_error = 'Could not connect to the database. Please try again later.';
            } else {
                $stmt = $db->prepare(
                    'SELECT id, student_id, photo, full_name, department, program,
                            batch, enrollment_status, current_semester,
                            total_semesters, cgpa, completed_credits, total_credits
                     FROM   student_enrollment_status
                     WHERE  student_id = ?
                     LIMIT  1'
                );
                $stmt->execute([$sid_input]);
                $student  = $stmt->fetch() ?: null;
                $submitted = true;
            }
        } catch (Throwable $e) {
            $form_error = 'A database error occurred. Please try again later.';
        }
    }
}

// ── Photo URL helper ──────────────────────────────────────────────────────────
function ses_photo_url(?string $photo): string
{
    if (!$photo) return '';
    if (!preg_match('/\A[A-Za-z0-9_\-]+\.[a-z]{2,5}\z/', $photo)) return '';
    $path = __DIR__ . '/admin/uploads/students/photos/' . $photo;
    if (is_file($path)) {
        return ADMIN_UPLOAD_URL . '/students/photos/' . rawurlencode($photo);
    }
    return SITE_URL . '/upload_spic/' . rawurlencode($photo);
}

// ── Status helpers ────────────────────────────────────────────────────────────
function ses_badge_class(string $status): string
{
    return match($status) {
        'Active'    => 'ses-badge--active',
        'On Leave'  => 'ses-badge--leave',
        'Completed' => 'ses-badge--completed',
        'Dropped'   => 'ses-badge--dropped',
        default     => 'ses-badge--inactive',
    };
}
function ses_badge_icon(string $status): string
{
    return match($status) {
        'Active'    => 'fas fa-check-circle',
        'On Leave'  => 'fas fa-pause-circle',
        'Completed' => 'fas fa-graduation-cap',
        'Dropped'   => 'fas fa-times-circle',
        default     => 'fas fa-question-circle',
    };
}
function ses_ordinal(int $n): string
{
    $mod100 = $n % 100;
    // 11, 12, 13 are special-cased to 'th' (e.g. 11th, 12th, 13th)
    if ($mod100 >= 11 && $mod100 <= 13) {
        return $n . 'th';
    }
    $suffix = ['th','st','nd','rd'];
    $mod10  = $n % 10;
    return $n . ($suffix[$mod10] ?? 'th');
}
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <meta name="viewport" content="width=device-width, initial-scale=1">
<?php render_seo_meta('/student-enrollment-status.php', 'Student Enrollment Status', 'Check the current enrollment status of a Prime University student by entering their Student ID.'); ?>

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
   /* ── Student Enrollment Status – Custom Styles ────────────────────────── */

   /* Hero */
   .ses-hero {
      background: linear-gradient(135deg, #0f2c6e 0%, #1a6e3a 100%);
      padding: 90px 0 110px;
      position: relative;
      overflow: hidden;
   }
   .ses-hero::before {
      content: '';
      position: absolute;
      inset: 0;
      background: url('/assets/img/shape/breadcrumb-1-bg.png') center/cover no-repeat;
      opacity: .05;
   }
   .ses-hero::after {
      content: '';
      position: absolute;
      right: -120px; top: -120px;
      width: 440px; height: 440px;
      background: rgba(255,255,255,.03);
      border-radius: 50%;
      pointer-events: none;
   }
   .ses-hero .breadcrumb-nav a,
   .ses-hero .breadcrumb-nav span { color: rgba(255,255,255,.7); font-size: .85rem; }
   .ses-hero .breadcrumb-nav a:hover { color: #fff; }
   .ses-hero .breadcrumb-nav .sep  { margin: 0 8px; color: rgba(255,255,255,.4); }
   .ses-hero h1 {
      font-size: clamp(1.9rem, 5vw, 3rem);
      font-weight: 800;
      color: #fff;
      line-height: 1.25;
      margin-bottom: 14px;
   }
   .ses-hero .badge-pill {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      background: rgba(255,255,255,.12);
      border: 1px solid rgba(255,255,255,.2);
      color: #fff;
      font-size: .8rem;
      font-weight: 600;
      padding: 6px 16px;
      border-radius: 50px;
      margin-bottom: 18px;
      backdrop-filter: blur(4px);
   }
   .ses-hero .tagline {
      font-size: 1rem;
      color: rgba(255,255,255,.82);
      max-width: 540px;
      line-height: 1.7;
   }

   /* Section wrapper */
   .ses-section { background: #f4f7fb; padding: 0 0 90px; }

   /* Search card (overlaps hero) */
   .ses-search-card {
      background: #fff;
      border-radius: 20px;
      box-shadow: 0 10px 52px rgba(0,0,0,.1);
      padding: 44px 40px;
      margin-top: -60px;
      position: relative;
      z-index: 10;
   }
   @media (max-width:575px) { .ses-search-card { padding: 28px 20px; margin-top: -40px; } }

   .ses-card-label {
      font-size: .7rem;
      font-weight: 700;
      letter-spacing: .09em;
      text-transform: uppercase;
      color: #9ca3af;
      margin-bottom: 4px;
   }
   .ses-card-title {
      font-size: 1.25rem;
      font-weight: 800;
      color: #0f2c6e;
      margin-bottom: 24px;
   }

   /* Search input row */
   .ses-search-row {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
   }
   .ses-search-row .form-control {
      flex: 1;
      min-width: 200px;
      border: 2px solid #c7d2fe;
      border-radius: 12px;
      padding: 13px 18px;
      font-size: 1rem;
      font-weight: 600;
      letter-spacing: .04em;
      color: #1e3a8a;
      background: #f0f4ff;
      transition: border-color .2s, box-shadow .2s;
   }
   .ses-search-row .form-control:focus {
      border-color: #3b82f6;
      box-shadow: 0 0 0 3px rgba(59,130,246,.15);
      background: #fff;
      outline: none;
   }
   .ses-search-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: linear-gradient(135deg, #0f2c6e 0%, #2563eb 100%);
      color: #fff;
      font-weight: 700;
      font-size: .95rem;
      padding: 13px 30px;
      border-radius: 12px;
      border: none;
      cursor: pointer;
      white-space: nowrap;
      transition: opacity .25s, transform .2s;
      box-shadow: 0 4px 18px rgba(37,99,235,.3);
   }
   .ses-search-btn:hover { opacity: .88; transform: translateY(-2px); }

   /* Error alert */
   .ses-alert {
      background: #fff1f2;
      border: 1.5px solid #fda4af;
      border-radius: 12px;
      color: #be123c;
      padding: 14px 18px;
      font-size: .9rem;
      margin-bottom: 20px;
   }

   /* ── Enrollment Card ──────────────────────────────────────────────────── */
   .ses-result-card {
      background: #fff;
      border-radius: 20px;
      box-shadow: 0 10px 52px rgba(0,0,0,.09);
      overflow: hidden;
      margin-top: 28px;
   }

   /* Top accent bar */
   .ses-result-card__header {
      background: linear-gradient(90deg, #0f2c6e 0%, #1563c3 100%);
      padding: 18px 32px;
      display: flex;
      align-items: center;
      gap: 14px;
   }
   .ses-result-card__header-icon {
      width: 46px; height: 46px; flex-shrink: 0;
      border-radius: 12px;
      background: rgba(255,255,255,.15);
      display: flex; align-items: center; justify-content: center;
      font-size: 1.25rem; color: #fff;
   }
   .ses-result-card__header-text { flex: 1; }
   .ses-result-card__header-text .sup  { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: rgba(255,255,255,.65); }
   .ses-result-card__header-text .main { font-size: 1.05rem; font-weight: 800; color: #fff; }

   /* Card body */
   .ses-result-card__body { padding: 36px 32px; }
   @media (max-width:575px) { .ses-result-card__body { padding: 24px 20px; } }

   /* Student identity row */
   .ses-identity {
      display: flex;
      gap: 28px;
      align-items: flex-start;
      margin-bottom: 36px;
      flex-wrap: wrap;
   }
   .ses-photo {
      width: 115px; height: 138px;
      object-fit: cover;
      border-radius: 14px;
      border: 3px solid #e0e7ff;
      flex-shrink: 0;
      box-shadow: 0 4px 18px rgba(15,44,110,.12);
   }
   .ses-photo-placeholder {
      width: 115px; height: 138px;
      border-radius: 14px;
      background: linear-gradient(135deg, #e0e7ff 0%, #ede9fe 100%);
      display: flex; align-items: center; justify-content: center;
      font-size: 3rem; color: #6366f1;
      flex-shrink: 0;
      border: 3px solid #e0e7ff;
      box-shadow: 0 4px 18px rgba(15,44,110,.08);
   }
   .ses-identity-info { flex: 1; min-width: 0; }
   .ses-name {
      font-size: 1.55rem;
      font-weight: 800;
      color: #0f2c6e;
      margin-bottom: 6px;
      line-height: 1.25;
   }
   .ses-meta-line {
      font-size: .88rem;
      color: #6b7280;
      margin-bottom: 4px;
      display: flex;
      align-items: center;
      gap: 7px;
   }
   .ses-meta-line strong { color: #374151; }

   /* Status badge */
   .ses-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 16px;
      border-radius: 50px;
      font-size: .8rem;
      font-weight: 700;
      margin-top: 10px;
   }
   .ses-badge--active    { background: #dbeafe; color: #1d4ed8; }
   .ses-badge--leave     { background: #fef3c7; color: #92400e; }
   .ses-badge--completed { background: #d1fae5; color: #065f46; }
   .ses-badge--dropped   { background: #fee2e2; color: #b91c1c; }
   .ses-badge--inactive  { background: #f3f4f6; color: #6b7280; }

   /* Info grid */
   .ses-info-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
      gap: 16px;
      margin-bottom: 28px;
   }
   .ses-info-item {
      background: #f8fafc;
      border-radius: 14px;
      padding: 18px 20px;
      border-left: 4px solid transparent;
      transition: border-color .2s, box-shadow .2s;
   }
   .ses-info-item:hover {
      border-color: #3b82f6;
      box-shadow: 0 4px 18px rgba(59,130,246,.1);
   }
   .ses-info-item .lbl {
      font-size: .68rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .07em;
      color: #9ca3af;
      margin-bottom: 6px;
   }
   .ses-info-item .val {
      font-size: .97rem;
      font-weight: 700;
      color: #0f2c6e;
      word-break: break-word;
   }
   .ses-info-item .val.large {
      font-size: 1.35rem;
      color: #1d4ed8;
   }

   /* Progress bar (semester) */
   .ses-progress-wrap {
      margin-top: 6px;
   }
   .ses-progress-bar-outer {
      background: #e0e7ff;
      border-radius: 50px;
      height: 8px;
      overflow: hidden;
      margin-top: 8px;
   }
   .ses-progress-bar-inner {
      height: 100%;
      border-radius: 50px;
      background: linear-gradient(90deg, #2563eb, #7c3aed);
      transition: width .6s ease;
   }

   /* Note box */
   .ses-note {
      background: #fffbeb;
      border: 1.5px solid #fcd34d;
      border-radius: 14px;
      padding: 18px 22px;
      font-size: .88rem;
      color: #92400e;
      display: flex;
      gap: 12px;
      align-items: flex-start;
      line-height: 1.7;
   }
   .ses-note .note-icon { font-size: 1.1rem; flex-shrink: 0; margin-top: 2px; }

   /* Not-found card */
   .ses-not-found {
      background: #fff;
      border-radius: 20px;
      box-shadow: 0 10px 52px rgba(0,0,0,.09);
      padding: 60px 36px;
      margin-top: 28px;
      text-align: center;
   }
   .ses-not-found .nf-icon {
      width: 86px; height: 86px; border-radius: 50%;
      background: #fee2e2;
      display: flex; align-items: center; justify-content: center;
      font-size: 2.1rem; color: #ef4444;
      margin: 0 auto 22px;
   }
   .ses-not-found h3 { font-size: 1.4rem; font-weight: 800; color: #0f2c6e; margin-bottom: 10px; }
   .ses-not-found p  { font-size: .92rem; color: #6b7280; max-width: 460px; margin: 0 auto; line-height: 1.7; }

   /* Sidebar info card */
   .ses-sidebar {
      background: linear-gradient(155deg, #0f2c6e 0%, #1e5ba8 100%);
      border-radius: 20px;
      padding: 36px 28px;
      color: #fff;
      position: sticky;
      top: 24px;
   }
   .ses-sidebar h4 { font-size: 1.1rem; font-weight: 800; color: #fff; margin-bottom: 22px; }
   .ses-sb-item {
      display: flex; gap: 14px; margin-bottom: 22px; align-items: flex-start;
   }
   .ses-sb-item:last-child { margin-bottom: 0; }
   .ses-sb-item .sb-icon {
      width: 40px; height: 40px; flex-shrink: 0;
      border-radius: 10px;
      background: rgba(255,255,255,.12);
      display: flex; align-items: center; justify-content: center;
      font-size: .95rem;
   }
   .ses-sb-item .sb-text { font-size: .85rem; color: rgba(255,255,255,.82); line-height: 1.6; }
   .ses-sb-item .sb-text strong { color: #fff; display: block; margin-bottom: 2px; font-size: .9rem; }

   @media (max-width:991px) {
      .ses-sidebar { position: static; margin-top: 32px; }
   }

   /* Span 2 columns on wider grids */
   .ses-info-item--wide {
      grid-column: span 2;
   }
   @media (max-width: 479px) {
      .ses-info-item--wide { grid-column: span 1; }
   }

   /* Divider */
   .ses-divider {
      border: none;
      border-top: 1.5px dashed #e2e8f0;
      margin: 28px 0;
   }
   </style>
<?php include __DIR__ . '/includes/meta-pixel.php'; ?>
</head>
<body id="body" class="it-magic-cursor">

   <div id="preloader">
      <div class="preloader">
         <span></span>
         <span></span>
      </div>
   </div>

   <div id="magic-cursor">
      <div id="ball"></div>
   </div>

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
   <section class="ses-hero">
      <div class="container position-relative" style="z-index:2;">
         <nav class="breadcrumb-nav mb-20">
            <a href="<?= fh(SITE_URL) ?>/index.php">Home</a>
            <span class="sep">/</span>
            <span>Student Enrollment Status</span>
         </nav>
         <div class="badge-pill wow fadeInUp" data-wow-delay=".05s">
            <i class="fas fa-id-badge"></i> Official Enrollment Portal
         </div>
         <h1 class="wow fadeInUp" data-wow-delay=".1s">Student Enrollment<br>Status</h1>
         <p class="tagline wow fadeInUp" data-wow-delay=".2s">
            Check the current enrollment status, academic progress, and CGPA of a
            Prime University student by entering their Student ID below.
         </p>
      </div>
   </section>
   <!-- ── HERO END ───────────────────────────────────────────────────────────── -->

   <section class="ses-section">
      <div class="container">
         <div class="row g-4 align-items-start">

            <!-- ── Main column ──────────────────────────────────────────────── -->
            <div class="col-lg-8">

               <!-- ── Search Card ─────────────────────────────────────────── -->
               <div class="ses-search-card wow fadeInUp" data-wow-delay=".1s">

                  <?php if ($form_error): ?>
                  <div class="ses-alert">
                     <i class="fas fa-exclamation-triangle me-2"></i>
                     <?= fh($form_error) ?>
                  </div>
                  <?php endif; ?>

                  <div class="ses-card-label">Lookup</div>
                  <div class="ses-card-title">Enter Student ID</div>

                  <form method="POST" action="" id="sesForm" novalidate>
                     <div class="ses-search-row">
                        <input
                           type="text"
                           name="student_id"
                           id="studentIdInput"
                           class="form-control"
                           placeholder="e.g. 230101010001"
                           value="<?= fh($sid_input) ?>"
                           maxlength="30"
                           autocomplete="off"
                           spellcheck="false">
                        <button type="submit" class="ses-search-btn">
                           <i class="fas fa-search"></i>
                           <span>Check Status</span>
                        </button>
                     </div>
                  </form>
               </div>
               <!-- ── Search Card End ─────────────────────────────────────── -->

               <!-- ── Result Section ──────────────────────────────────────── -->
               <?php if ($submitted): ?>

                  <?php if ($student): ?>
                  <?php
                  // Computed display values
                  $photo_url      = ses_photo_url($student['photo'] ?? '');
                  $status         = $student['enrollment_status'];
                  $cur_sem        = (int)$student['current_semester'];
                  $total_sem      = (int)$student['total_semesters'];
                  $sem_pct        = $total_sem > 0 ? round($cur_sem / $total_sem * 100) : 0;
                  $cgpa           = $student['cgpa'] !== null ? number_format((float)$student['cgpa'], 2) : null;
                  $comp_credits   = (int)$student['completed_credits'];
                  $total_credits  = (int)$student['total_credits'];
                  ?>
                  <!-- ── Student Found ────────────────────────────────────── -->
                  <div class="ses-result-card wow fadeInUp" data-wow-delay=".05s" id="sesResult">

                     <!-- Header bar -->
                     <div class="ses-result-card__header">
                        <div class="ses-result-card__header-icon">
                           <i class="fas fa-id-card-alt"></i>
                        </div>
                        <div class="ses-result-card__header-text">
                           <div class="sup">Enrollment Status Record</div>
                           <div class="main">Prime University – Official Student Record</div>
                        </div>
                     </div>

                     <div class="ses-result-card__body">

                        <!-- Student identity -->
                        <div class="ses-identity">
                           <?php if ($photo_url): ?>
                           <img src="<?= fh($photo_url) ?>"
                                alt="Photo of <?= fh($student['full_name']) ?>"
                                class="ses-photo">
                           <?php else: ?>
                           <div class="ses-photo-placeholder">
                              <i class="fas fa-user-graduate"></i>
                           </div>
                           <?php endif; ?>

                           <div class="ses-identity-info">
                              <div class="ses-name"><?= fh($student['full_name']) ?></div>
                              <div class="ses-meta-line">
                                 <i class="fas fa-id-card text-muted"></i>
                                 <strong><?= fh($student['student_id']) ?></strong>
                              </div>
                              <div class="ses-meta-line">
                                 <i class="fas fa-university text-muted"></i>
                                 <?= fh($student['department']) ?>
                              </div>
                              <div class="ses-meta-line">
                                 <i class="fas fa-book-open text-muted"></i>
                                 <?= fh($student['program']) ?>
                              </div>
                              <div class="ses-meta-line">
                                 <i class="fas fa-users text-muted"></i>
                                 <?= fh($student['batch']) ?>
                              </div>
                              <span class="ses-badge <?= ses_badge_class($status) ?>">
                                 <i class="<?= ses_badge_icon($status) ?>"></i>
                                 <?= fh($status) ?>
                              </span>
                           </div>
                        </div><!-- /ses-identity -->

                        <hr class="ses-divider">

                        <!-- Info grid -->
                        <div class="ses-info-grid">

                           <div class="ses-info-item">
                              <div class="lbl">Student ID</div>
                              <div class="val"><?= fh($student['student_id']) ?></div>
                           </div>

                           <div class="ses-info-item">
                              <div class="lbl">Full Name</div>
                              <div class="val"><?= fh($student['full_name']) ?></div>
                           </div>

                           <div class="ses-info-item">
                              <div class="lbl">Department</div>
                              <div class="val"><?= fh($student['department']) ?></div>
                           </div>

                           <div class="ses-info-item">
                              <div class="lbl">Program</div>
                              <div class="val"><?= fh($student['program']) ?></div>
                           </div>

                           <div class="ses-info-item">
                              <div class="lbl">Batch</div>
                              <div class="val"><?= fh($student['batch']) ?></div>
                           </div>

                           <div class="ses-info-item">
                              <div class="lbl">Enrollment Status</div>
                              <div class="val">
                                 <span class="ses-badge <?= ses_badge_class($status) ?>" style="margin-top:0;font-size:.82rem;">
                                    <i class="<?= ses_badge_icon($status) ?>"></i>
                                    <?= fh($status) ?>
                                 </span>
                              </div>
                           </div>

                           <!-- Current semester with progress bar -->
                           <div class="ses-info-item ses-info-item--wide">
                              <div class="lbl">Current Semester</div>
                              <div class="val">
                                 <?= ses_ordinal($cur_sem) ?> Semester
                                 <span style="font-size:.8rem;font-weight:500;color:#6b7280;"> out of <?= $total_sem ?></span>
                              </div>
                              <div class="ses-progress-wrap">
                                 <div class="ses-progress-bar-outer">
                                    <div class="ses-progress-bar-inner" style="width:<?= $sem_pct ?>%;"></div>
                                 </div>
                                 <div style="font-size:.72rem;color:#9ca3af;margin-top:4px;text-align:right;">
                                    <?= $sem_pct ?>% of program completed
                                 </div>
                              </div>
                           </div>

                           <div class="ses-info-item">
                              <div class="lbl">CGPA (Completed Credits)</div>
                              <div class="val large">
                                 <?= $cgpa !== null ? fh($cgpa) : '<span style="font-size:.95rem;color:#9ca3af;">N/A</span>' ?>
                              </div>
                           </div>

                           <div class="ses-info-item">
                              <div class="lbl">Total Completed Credits</div>
                              <div class="val large">
                                 <?= $comp_credits ?> <span style="font-size:.85rem;font-weight:500;color:#6b7280;">/ <?= $total_credits ?></span>
                              </div>
                           </div>

                        </div><!-- /ses-info-grid -->

                        <!-- CGPA note -->
                        <div class="ses-note">
                           <span class="note-icon"><i class="fas fa-info-circle" style="color:#d97706;"></i></span>
                           <span>
                              <strong>Note:</strong>
                              CGPA is calculated based on completed credits only. The student is currently
                              enrolled and the final CGPA may change upon program completion.
                           </span>
                        </div>

                     </div><!-- /ses-result-card__body -->
                  </div>
                  <!-- ── Student Found End ───────────────────────────────── -->

                  <?php else: ?>
                  <!-- ── Not Found ───────────────────────────────────────── -->
                  <div class="ses-not-found wow fadeInUp" data-wow-delay=".05s">
                     <div class="nf-icon"><i class="fas fa-user-times"></i></div>
                     <h3>No Record Found</h3>
                     <p>
                        We could not find any enrollment record for Student ID
                        <strong>&ldquo;<?= fh($sid_input) ?>&rdquo;</strong>.
                        Please double-check the ID and try again, or contact the Registrar&rsquo;s Office for assistance.
                     </p>
                  </div>
                  <!-- ── Not Found End ───────────────────────────────────── -->
                  <?php endif; ?>

               <?php endif; ?>
               <!-- ── Result Section End ──────────────────────────────────── -->

            </div><!-- /col-lg-8 -->

            <!-- ── Sidebar ─────────────────────────────────────────────────── -->
            <div class="col-lg-4">
               <div class="ses-sidebar wow fadeInRight" data-wow-delay=".15s">
                  <h4><i class="fas fa-info-circle me-2"></i> About This Portal</h4>

                  <div class="ses-sb-item">
                     <div class="sb-icon"><i class="fas fa-shield-alt"></i></div>
                     <div class="sb-text">
                        <strong>Official Records Only</strong>
                        This portal displays official enrollment data maintained by
                        Prime University&rsquo;s Registrar&rsquo;s Office.
                     </div>
                  </div>

                  <div class="ses-sb-item">
                     <div class="sb-icon"><i class="fas fa-chart-line"></i></div>
                     <div class="sb-text">
                        <strong>Live CGPA</strong>
                        The displayed CGPA reflects completed courses only and will
                        be updated each semester upon grade publication.
                     </div>
                  </div>

                  <div class="ses-sb-item">
                     <div class="sb-icon"><i class="fas fa-id-card"></i></div>
                     <div class="sb-text">
                        <strong>Student ID Format</strong>
                        Enter your 12-digit Student ID exactly as printed on your
                        university ID card or admission letter.
                     </div>
                  </div>

                  <div class="ses-sb-item">
                     <div class="sb-icon"><i class="fas fa-phone-alt"></i></div>
                     <div class="sb-text">
                        <strong>Need Help?</strong>
                        Contact the Registrar&rsquo;s Office at
                        <a href="tel:+8801969955566" style="color:#93c5fd;">01969-955566</a>
                        or email
                        <a href="mailto:verification@primeuniversity.ac.bd" style="color:#93c5fd;">verification@primeuniversity.ac.bd</a>.
                     </div>
                  </div>
               </div>
            </div>
            <!-- ── Sidebar End ─────────────────────────────────────────────── -->

         </div><!-- /row -->
      </div><!-- /container -->
   </section>

   <?php include __DIR__ . '/includes/footer.php'; ?>
   <?php include __DIR__ . '/includes/scripts.php'; ?>
   <script>
   // Auto-scroll to result on page load if result is present
   document.addEventListener('DOMContentLoaded', function () {
      var result = document.getElementById('sesResult');
      if (result) {
         setTimeout(function () {
            result.scrollIntoView({ behavior: 'smooth', block: 'start' });
         }, 400);
      }
   });
   </script>
</body>
</html>
