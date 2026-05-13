<?php
/**
 * Public Spring Result Page
 * Students enter their Student ID to view their published results.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/seo.php';

$page_title = 'Result – Prime University';

if (session_status() === PHP_SESSION_NONE) session_start();

// ── State ─────────────────────────────────────────────────────────────────────
$form_error = '';
$submitted  = false;
$sid_input  = '';

// Optional: pre-filter to a specific result set
$filter_result_id = (int)($_GET['result_id'] ?? 0);

// Results found for the searched student
$result_sets = [];   // [ ['result' => row, 'entries' => [row, ...]], ... ]
$student_name_found = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sid_input = trim($_POST['student_id'] ?? '');

    if ($sid_input === '') {
        $form_error = 'Please enter your Student ID.';
    } elseif (!preg_match('/\A[A-Za-z0-9\-]{1,60}\z/', $sid_input)) {
        $form_error = 'Invalid Student ID format.';
    } else {
        $db = front_db();
        if (!$db) {
            $form_error = 'Could not connect to the database. Please try again later.';
        } else {
            try {
                // Get all published results that have entries for this student
                $extra_where = $filter_result_id > 0 ? 'AND r.id = ?' : '';
                $params_result = $filter_result_id > 0
                    ? [$sid_input, $filter_result_id]
                    : [$sid_input];

                $stmt = $db->prepare(
                    "SELECT r.id, r.title, r.semester
                     FROM sr_results r
                     WHERE r.is_published = 1
                       AND EXISTS (
                           SELECT 1 FROM sr_result_entries e
                           WHERE e.result_id = r.id AND e.student_id = ?
                       )
                     $extra_where
                     ORDER BY r.created_at DESC"
                );
                $stmt->execute($params_result);
                $results = $stmt->fetchAll();

                foreach ($results as $res) {
                    $estmt = $db->prepare(
                        'SELECT student_id, student_name, course_code, course_title, letter_grade, grade_point, credit
                         FROM sr_result_entries
                         WHERE result_id = ? AND student_id = ?
                         ORDER BY course_code ASC, course_title ASC'
                    );
                    $estmt->execute([$res['id'], $sid_input]);
                    $entries = $estmt->fetchAll();

                    if (!empty($entries)) {
                        // Capture student name from first row that has one
                        if (!$student_name_found) {
                            foreach ($entries as $e) {
                                if (!empty($e['student_name'])) {
                                    $student_name_found = $e['student_name'];
                                    break;
                                }
                            }
                        }
                        $result_sets[] = ['result' => $res, 'entries' => $entries];
                    }
                }

                $submitted = true;
            } catch (Throwable $e) {
                $form_error = 'A database error occurred. Please try again later.';
            }
        }
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function pub_grade_badge(string $grade): string
{
    $upper = strtoupper(trim($grade));
    $cls = match ($upper) {
        'A+', 'A'  => 'rp-grade--a-plus',
        'A-', 'B+' => 'rp-grade--a',
        'B', 'B-'  => 'rp-grade--b',
        'C+', 'C'  => 'rp-grade--c',
        'D'        => 'rp-grade--d',
        'F'        => 'rp-grade--f',
        'INCOM'    => 'rp-grade--other',
        default    => 'rp-grade--other',
    };
    $display = ($upper === 'INCOM') ? 'Incom' : fh($grade);
    return '<span class="rp-grade ' . $cls . '">' . $display . '</span>';
}

function pub_gp_color(float $gp): string
{
    if ($gp >= 3.75) return '#16a34a';
    if ($gp >= 3.00) return '#2563eb';
    if ($gp >= 2.50) return '#d97706';
    if ($gp >= 2.00) return '#ea580c';
    return '#dc2626';
}

function pub_has_fail_or_incom(array $entries): bool
{
    foreach ($entries as $e) {
        $g = strtoupper(trim((string)$e['letter_grade']));
        if ($g === 'F' || $g === 'INCOM') {
            return true;
        }
    }
    return false;
}

function pub_cgpa(array $entries): ?float
{
    // Credit-weighted GPA: Σ(grade_point × credit) / Σ(credit)
    $total_points  = 0.0;
    $total_credits = 0.0;
    foreach ($entries as $e) {
        if ($e['grade_point'] !== null && $e['credit'] !== null && (float)$e['credit'] > 0) {
            $credit = (float)$e['credit'];
            $total_points  += (float)$e['grade_point'] * $credit;
            $total_credits += $credit;
        }
    }
    if ($total_credits > 0) {
        return round($total_points / $total_credits, 2);
    }
    // Fallback: simple average when no credits are stored
    $total = 0.0;
    $count = 0;
    foreach ($entries as $e) {
        if ($e['grade_point'] !== null) {
            $total += (float)$e['grade_point'];
            $count++;
        }
    }
    return $count > 0 ? round($total / $count, 2) : null;
}
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <meta name="viewport" content="width=device-width, initial-scale=1">
<?php render_seo_meta('/spring-result.php', 'Semester Result', 'Check your semester result at Prime University by entering your Student ID.'); ?>

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
   /* ── Spring Result Page – Custom Styles ──────────────────────────────── */

   /* ─ Hero ─ */
   .rp-hero {
      background: linear-gradient(135deg, #0f2c6e 0%, #1a5e82 60%, #0f2c6e 100%);
      padding: 90px 0 120px;
      position: relative;
      overflow: hidden;
   }
   .rp-hero::before {
      content: '';
      position: absolute;
      inset: 0;
      background: url('/assets/img/shape/breadcrumb-1-bg.png') center/cover no-repeat;
      opacity: .05;
   }
   .rp-hero::after {
      content: '';
      position: absolute;
      right: -140px; bottom: -140px;
      width: 500px; height: 500px;
      background: rgba(255,255,255,.03);
      border-radius: 50%;
      pointer-events: none;
   }
   .rp-hero .rp-breadcrumb a,
   .rp-hero .rp-breadcrumb span { color: rgba(255,255,255,.7); font-size: .85rem; }
   .rp-hero .rp-breadcrumb a:hover { color: #fff; }
   .rp-hero .rp-breadcrumb .sep   { margin: 0 8px; color: rgba(255,255,255,.35); }
   .rp-hero h1 {
      font-size: clamp(2rem, 5vw, 3rem);
      font-weight: 800;
      color: #fff;
      line-height: 1.2;
      margin-bottom: 14px;
   }
   .rp-hero .badge-pill {
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
   .rp-hero .tagline {
      font-size: 1rem;
      color: rgba(255,255,255,.8);
      max-width: 520px;
      line-height: 1.75;
   }

   /* ─ Section ─ */
   .rp-section { background: #f3f6fb; padding: 0 0 100px; }

   /* ─ Search card (overlaps hero) ─ */
   .rp-search-card {
      background: #fff;
      border-radius: 22px;
      box-shadow: 0 14px 60px rgba(15,44,110,.12);
      padding: 46px 42px;
      margin-top: -70px;
      position: relative;
      z-index: 10;
   }
   @media (max-width: 575px) {
      .rp-search-card { padding: 28px 22px; margin-top: -48px; }
   }

   .rp-card-label {
      font-size: .68rem;
      font-weight: 700;
      letter-spacing: .1em;
      text-transform: uppercase;
      color: #9ca3af;
      margin-bottom: 4px;
   }
   .rp-card-title {
      font-size: 1.3rem;
      font-weight: 800;
      color: #0f2c6e;
      margin-bottom: 24px;
   }

   /* ─ Search row ─ */
   .rp-input-group {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
   }
   .rp-input-group input {
      flex: 1;
      min-width: 180px;
      height: 52px;
      border: 2px solid #e5e7eb;
      border-radius: 12px;
      padding: 0 18px;
      font-size: .95rem;
      outline: none;
      transition: border-color .2s, box-shadow .2s;
   }
   .rp-input-group input:focus {
      border-color: #3b82f6;
      box-shadow: 0 0 0 4px rgba(59,130,246,.1);
   }
   .rp-input-group button {
      height: 52px;
      padding: 0 28px;
      background: linear-gradient(135deg, #1d4ed8, #2563eb);
      color: #fff;
      border: none;
      border-radius: 12px;
      font-size: .9rem;
      font-weight: 700;
      cursor: pointer;
      transition: opacity .2s, transform .15s;
      white-space: nowrap;
   }
   .rp-input-group button:hover { opacity: .9; transform: translateY(-1px); }
   .rp-input-group button:active { transform: translateY(0); }

   /* ─ Error alert ─ */
   .rp-alert {
      background: #fef2f2;
      border: 1.5px solid #fca5a5;
      border-radius: 12px;
      color: #b91c1c;
      padding: 14px 18px;
      font-size: .88rem;
      margin-bottom: 22px;
      display: flex;
      gap: 10px;
      align-items: center;
   }

   /* ─ Result container ─ */
   .rp-results-wrap { margin-top: 32px; }

   /* ─ Student header ─ */
   .rp-student-header {
      display: flex;
      align-items: center;
      gap: 20px;
      background: linear-gradient(135deg, #0f2c6e, #1d4ed8);
      border-radius: 18px;
      padding: 28px 32px;
      color: #fff;
      margin-bottom: 28px;
   }
   .rp-student-avatar {
      width: 64px; height: 64px;
      border-radius: 50%;
      background: rgba(255,255,255,.2);
      display: flex; align-items: center; justify-content: center;
      font-size: 1.6rem;
      color: #fff;
      font-weight: 700;
      flex-shrink: 0;
      border: 3px solid rgba(255,255,255,.35);
   }
   .rp-student-info .rp-sid {
      font-size: .8rem;
      color: rgba(255,255,255,.7);
      font-weight: 600;
      letter-spacing: .06em;
      text-transform: uppercase;
      margin-bottom: 4px;
   }
   .rp-student-info .rp-sname {
      font-size: 1.25rem;
      font-weight: 800;
      color: #fff;
   }

   /* ─ Individual result card ─ */
   .rp-result-card {
      background: #fff;
      border-radius: 18px;
      box-shadow: 0 4px 24px rgba(15,44,110,.07);
      overflow: hidden;
      margin-bottom: 28px;
   }
   .rp-result-card-header {
      background: linear-gradient(135deg, #f0f4ff, #e8f5e9);
      padding: 22px 28px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 12px;
      border-bottom: 1px solid #e8edf5;
   }
   .rp-result-card-header .rp-result-title {
      font-size: 1.05rem;
      font-weight: 800;
      color: #0f2c6e;
      display: flex;
      align-items: center;
      gap: 10px;
   }
   .rp-result-card-header .rp-result-title .rp-icon {
      width: 36px; height: 36px;
      border-radius: 10px;
      background: #1d4ed8;
      display: flex; align-items: center; justify-content: center;
      color: #fff;
      font-size: .85rem;
      flex-shrink: 0;
   }
   .rp-result-card-header .rp-meta {
      font-size: .82rem;
      color: #6b7280;
      display: flex; gap: 16px; flex-wrap: wrap; align-items: center;
   }
   .rp-result-card-header .rp-meta .rp-meta-chip {
      display: inline-flex; align-items: center; gap: 5px;
      background: #fff;
      border: 1px solid #e5e7eb;
      border-radius: 50px;
      padding: 4px 12px;
      font-size: .78rem;
      color: #374151;
      font-weight: 600;
   }
   .rp-result-card-header .rp-meta .rp-meta-chip i { color: #6b7280; }

   /* ─ Table ─ */
   .rp-table-wrap { overflow-x: auto; }
   .rp-table {
      width: 100%;
      border-collapse: collapse;
      font-size: .88rem;
   }
   .rp-table thead th {
      background: #f8fafc;
      color: #6b7280;
      font-size: .72rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .06em;
      padding: 12px 20px;
      border-bottom: 2px solid #e5e7eb;
      white-space: nowrap;
   }
   .rp-table tbody tr { transition: background .15s; }
   .rp-table tbody tr:hover { background: #f8fafc; }
   .rp-table tbody td {
      padding: 13px 20px;
      border-bottom: 1px solid #f0f4f8;
      color: #374151;
      vertical-align: middle;
   }
   .rp-table tbody tr:last-child td { border-bottom: none; }
   .rp-course-code {
      display: inline-block;
      background: #f0f4ff;
      color: #1d4ed8;
      border-radius: 8px;
      padding: 3px 10px;
      font-size: .78rem;
      font-weight: 700;
   }
   .rp-course-title { font-weight: 600; color: #0f2c6e; }

   /* ─ Grade badges ─ */
   .rp-grade {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 46px; height: 34px;
      border-radius: 8px;
      font-size: .9rem;
      font-weight: 800;
   }
   .rp-grade--a-plus { background: #dcfce7; color: #15803d; }
   .rp-grade--a      { background: #dbeafe; color: #1d4ed8; }
   .rp-grade--b      { background: #e0f2fe; color: #0369a1; }
   .rp-grade--c      { background: #fef9c3; color: #a16207; }
   .rp-grade--d      { background: #ffedd5; color: #c2410c; }
   .rp-grade--f      { background: #fee2e2; color: #b91c1c; }
   .rp-grade--other  { background: #f3f4f6; color: #6b7280; }

   /* ─ GP value ─ */
   .rp-gp { font-weight: 700; font-size: .92rem; }

   /* ─ Footer strip ─ */
   .rp-card-footer {
      padding: 16px 28px;
      background: #f8fafc;
      border-top: 1px solid #e8edf5;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 10px;
   }
   .rp-card-footer .rp-avg {
      font-size: .88rem;
      color: #374151;
   }
   .rp-card-footer .rp-avg strong { color: #0f2c6e; font-weight: 800; font-size: 1.05rem; }

   /* ─ Not found / empty ─ */
   .rp-not-found {
      background: #fff;
      border-radius: 20px;
      box-shadow: 0 8px 40px rgba(0,0,0,.07);
      padding: 64px 40px;
      text-align: center;
      margin-top: 30px;
   }
   .rp-not-found .nf-icon {
      width: 86px; height: 86px; border-radius: 50%;
      background: #fee2e2;
      display: flex; align-items: center; justify-content: center;
      font-size: 2rem; color: #ef4444;
      margin: 0 auto 22px;
   }
   .rp-not-found h3 { font-size: 1.4rem; font-weight: 800; color: #0f2c6e; margin-bottom: 10px; }
   .rp-not-found p  { font-size: .92rem; color: #6b7280; max-width: 460px; margin: 0 auto; line-height: 1.75; }

   /* ─ Sidebar ─ */
   .rp-sidebar {
      background: linear-gradient(155deg, #0f2c6e, #1e5ba8);
      border-radius: 20px;
      padding: 36px 28px;
      color: #fff;
      position: sticky;
      top: 24px;
   }
   .rp-sidebar h4 { font-size: 1.05rem; font-weight: 800; color: #fff; margin-bottom: 22px; }
   .rp-sb-item {
      display: flex; gap: 14px; margin-bottom: 20px; align-items: flex-start;
   }
   .rp-sb-item:last-child { margin-bottom: 0; }
   .rp-sb-item .sb-icon {
      width: 40px; height: 40px; flex-shrink: 0;
      border-radius: 10px;
      background: rgba(255,255,255,.12);
      display: flex; align-items: center; justify-content: center;
      font-size: .95rem;
   }
   .rp-sb-item .sb-text { font-size: .85rem; color: rgba(255,255,255,.82); line-height: 1.6; }
   .rp-sb-item .sb-text strong { color: #fff; display: block; margin-bottom: 2px; font-size: .9rem; }

   @media (max-width: 991px) { .rp-sidebar { position: static; margin-top: 32px; } }

   /* ─ Print button ─ */
   .rp-print-btn {
      display: inline-flex; align-items: center; gap: 7px;
      background: #f8fafc;
      border: 1.5px solid #e5e7eb;
      color: #374151;
      border-radius: 10px;
      padding: 7px 16px;
      font-size: .82rem;
      font-weight: 600;
      text-decoration: none;
      transition: background .15s, border-color .15s;
      cursor: pointer;
   }
   .rp-print-btn:hover { background: #e8edf5; border-color: #d1d5db; color: #0f2c6e; }

   @media print {
      .rp-hero, .rp-search-card, .rp-sidebar, header, footer, .rp-print-btn { display: none !important; }
      .rp-section { padding: 0; background: #fff; }
      .rp-results-wrap { margin-top: 0; }
      .rp-result-card { box-shadow: none; border: 1px solid #ddd; margin-bottom: 16px; }
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
   <section class="rp-hero">
      <div class="container position-relative" style="z-index:2;">
         <nav class="rp-breadcrumb mb-20">
            <a href="<?= fh(SITE_URL) ?>/index.php">Home</a>
            <span class="sep">/</span>
            <span>Semester Result</span>
         </nav>
         <div class="badge-pill wow fadeInUp" data-wow-delay=".05s">
            <i class="fas fa-poll"></i> Official Result Portal
         </div>
         <h1 class="wow fadeInUp" data-wow-delay=".1s">Semester Result</h1>
         <p class="tagline wow fadeInUp" data-wow-delay=".2s">
            Enter your Student ID below to view your published semester results,
            course grades, and grade points from Prime University.
         </p>
      </div>
   </section>
   <!-- ── HERO END ──────────────────────────────────────────────────────────── -->

   <section class="rp-section">
      <div class="container">
         <div class="row g-4 align-items-start">

            <!-- ── Main column ────────────────────────────────────────────── -->
            <div class="col-lg-8">

               <!-- ── Search Card ──────────────────────────────────────── -->
               <div class="rp-search-card wow fadeInUp" data-wow-delay=".1s">

                  <?php if ($form_error): ?>
                  <div class="rp-alert">
                     <i class="fas fa-exclamation-triangle"></i>
                     <?= fh($form_error) ?>
                  </div>
                  <?php endif; ?>

                  <div class="rp-card-label">Result Lookup</div>
                  <div class="rp-card-title">Enter Your Student ID</div>

                  <form method="POST" action="" novalidate>
                     <?php if ($filter_result_id > 0): ?>
                     <input type="hidden" name="result_id_filter" value="<?= $filter_result_id ?>">
                     <?php endif; ?>
                     <div class="rp-input-group">
                        <input
                           type="text"
                           name="student_id"
                           id="studentIdInput"
                           placeholder="e.g. 193020101021"
                           value="<?= fh($sid_input) ?>"
                           maxlength="60"
                           autocomplete="off"
                           spellcheck="false"
                           required>
                        <button type="submit">
                           <i class="fas fa-search me-1"></i> Find Result
                        </button>
                     </div>
                     <div style="font-size:.8rem;color:#9ca3af;margin-top:10px;">
                        <i class="fas fa-lock me-1" style="color:#d1d5db;"></i>
                        Your search is private and not stored.
                     </div>
                  </form>
               </div>
               <!-- ── Search Card END ──────────────────────────────────── -->

               <!-- ── Results ──────────────────────────────────────────── -->
               <?php if ($submitted): ?>
               <div class="rp-results-wrap">

                  <?php if (empty($result_sets)): ?>
                  <!-- Not found -->
                  <div class="rp-not-found wow fadeInUp" data-wow-delay=".1s">
                     <div class="nf-icon"><i class="fas fa-search-minus"></i></div>
                     <h3>No Result Found</h3>
                     <p>
                        No published result was found for Student ID
                        <strong><?= fh($sid_input) ?></strong>.
                        Please verify your Student ID or contact the Controller of Examinations office.
                     </p>
                  </div>

                  <?php else: ?>
                  <!-- Student header -->
                  <div class="rp-student-header wow fadeInUp" data-wow-delay=".05s">
                     <div class="rp-student-avatar">
                        <?= strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $student_name_found ?: 'S'), 0, 1) ?: 'S') ?>
                     </div>
                     <div class="rp-student-info">
                        <div class="rp-sid"><i class="fas fa-id-card me-1"></i><?= fh($sid_input) ?></div>
                        <?php if ($student_name_found): ?>
                        <div class="rp-sname"><?= fh($student_name_found) ?></div>
                        <?php endif; ?>
                        <div style="font-size:.82rem;color:rgba(255,255,255,.7);margin-top:4px;">
                           <?= count($result_sets) ?> result set<?= count($result_sets) !== 1 ? 's' : '' ?> found
                        </div>
                     </div>
                     <div class="ms-auto d-none d-md-block">
                        <button class="rp-print-btn" onclick="window.print()">
                           <i class="fas fa-print"></i> Print
                        </button>
                     </div>
                  </div>

                  <!-- Each result set -->
                  <?php foreach ($result_sets as $idx => $rs):
                     $res     = $rs['result'];
                     $entries = $rs['entries'];
                  ?>
                  <div class="rp-result-card wow fadeInUp" data-wow-delay="<?= .1 + $idx * .05 ?>s">

                     <!-- Card header -->
                     <div class="rp-result-card-header">
                        <div class="rp-result-title">
                           <div class="rp-icon"><i class="fas fa-poll"></i></div>
                           <?= fh($res['title']) ?>
                        </div>
                        <div class="rp-meta">
                           <?php if ($res['semester']): ?>
                           <div class="rp-meta-chip">
                              <i class="fas fa-calendar-alt"></i>
                              <?= fh($res['semester']) ?>
                           </div>
                           <?php endif; ?>
                           <div class="rp-meta-chip">
                              <i class="fas fa-book"></i>
                              <?= count($entries) ?> Course<?= count($entries) !== 1 ? 's' : '' ?>
                           </div>
                        </div>
                     </div>

                     <!-- Table -->
                     <div class="rp-table-wrap">
                        <table class="rp-table">
                           <thead>
                              <tr>
                                 <th style="width:3rem;">#</th>
                                 <th>Course Code</th>
                                 <th>Course Title</th>
                                 <th style="text-align:center;">Credits</th>
                                 <th style="text-align:center;">Letter Grade</th>
                                 <th style="text-align:center;">Grade Point</th>
                              </tr>
                           </thead>
                           <tbody>
                              <?php foreach ($entries as $ei => $e): ?>
                              <tr>
                                 <td style="color:#9ca3af;"><?= $ei + 1 ?></td>
                                 <td>
                                    <?php if ($e['course_code']): ?>
                                    <span class="rp-course-code"><?= fh($e['course_code']) ?></span>
                                    <?php else: ?>
                                    <span style="color:#d1d5db;">—</span>
                                    <?php endif; ?>
                                 </td>
                                 <td class="rp-course-title"><?= fh($e['course_title']) ?></td>
                                 <td style="text-align:center;">
                                    <?php if ($e['credit'] !== null): ?>
                                    <span class="rp-gp" style="color:#374151;"><?= number_format((float)$e['credit'], 2) ?></span>
                                    <?php else: ?>
                                    <span style="color:#d1d5db;">—</span>
                                    <?php endif; ?>
                                 </td>
                                 <td style="text-align:center;">
                                    <?= pub_grade_badge($e['letter_grade']) ?>
                                 </td>
                                 <td style="text-align:center;">
                                    <?php if (strtoupper($e['letter_grade']) === 'INCOM'): ?>
                                    <span class="rp-gp" style="color:#6b7280;font-style:italic;">Incom</span>
                                    <?php elseif ($e['grade_point'] !== null): ?>
                                    <span class="rp-gp" style="color:<?= pub_gp_color((float)$e['grade_point']) ?>;">
                                       <?= number_format((float)$e['grade_point'], 2) ?>
                                    </span>
                                    <?php else: ?>
                                    <span style="color:#d1d5db;">—</span>
                                    <?php endif; ?>
                                 </td>
                              </tr>
                              <?php endforeach; ?>
                           </tbody>
                        </table>
                     </div>

                     <!-- Card footer: GPA + published indicator -->
                     <?php
                        $has_fail = pub_has_fail_or_incom($entries);
                        $gpa      = $has_fail ? null : pub_cgpa($entries);
                     ?>
                     <div class="rp-card-footer">
                        <div style="font-size:.8rem;color:#9ca3af;">
                           <i class="fas fa-check-circle me-1" style="color:#16a34a;"></i> Published
                        </div>
                        <?php if ($has_fail): ?>
                        <div class="rp-avg">
                           GPA:&nbsp;<strong style="color:#6b7280;font-style:italic;">Incom</strong>
                        </div>
                        <?php elseif ($gpa !== null): ?>
                        <div class="rp-avg">
                           GPA:&nbsp;<strong style="color:<?= pub_gp_color($gpa); ?>;"><?= number_format($gpa, 2) ?></strong>
                        </div>
                        <?php endif; ?>
                     </div>

                  </div><!-- /.rp-result-card -->
                  <?php endforeach; ?>

                  <?php endif; // end empty check ?>
               </div>
               <?php endif; // end submitted ?>
               <!-- ── Results END ────────────────────────────────────────── -->

            </div><!-- /.col-lg-8 -->

            <!-- ── Sidebar ────────────────────────────────────────────── -->
            <div class="col-lg-4">
               <div class="rp-sidebar wow fadeInRight" data-wow-delay=".15s">
                  <h4><i class="fas fa-info-circle me-2"></i>How to Check Results</h4>

                  <div class="rp-sb-item">
                     <div class="sb-icon"><i class="fas fa-id-card"></i></div>
                     <div class="sb-text">
                        <strong>Step 1 – Enter Student ID</strong>
                        Type your full Student ID in the search box on the left.
                     </div>
                  </div>

                  <div class="rp-sb-item">
                     <div class="sb-icon"><i class="fas fa-search"></i></div>
                     <div class="sb-text">
                        <strong>Step 2 – Click Find Result</strong>
                        Press the "Find Result" button to search for your grades.
                     </div>
                  </div>

                  <div class="rp-sb-item">
                     <div class="sb-icon"><i class="fas fa-table"></i></div>
                     <div class="sb-text">
                        <strong>Step 3 – View Your Grades</strong>
                        Your course-wise grades, letter grades, and grade points will appear below.
                     </div>
                  </div>

                  <div class="rp-sb-item">
                     <div class="sb-icon"><i class="fas fa-print"></i></div>
                     <div class="sb-text">
                        <strong>Save or Print</strong>
                        Use your browser's print function (Ctrl+P) to save as PDF.
                     </div>
                  </div>

                  <div style="margin-top:24px;padding-top:20px;border-top:1px solid rgba(255,255,255,.15);font-size:.8rem;color:rgba(255,255,255,.6);line-height:1.7;">
                     <i class="fas fa-phone-alt me-1"></i>
                     For result-related queries, contact the Controller of Examinations office.
                  </div>
               </div>
            </div>

         </div>
      </div>
   </section>

<?php include __DIR__ . '/includes/footer.php'; ?>
<?php include __DIR__ . '/includes/scripts.php'; ?>
</body>
</html>
