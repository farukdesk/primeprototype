<?php
require_once __DIR__ . '/includes/config.php';

// ── Fetch settings & active programs ─────────────────────────────────────────
$db       = front_db();
$settings = [];
$programs = [];
$degree_types = [];

if ($db) {
    try {
        $settings = $db->query('SELECT * FROM cf_settings WHERE id=1')->fetch() ?: [];

        $degree_types = $db->query(
            'SELECT * FROM cf_degree_types WHERE is_active=1 ORDER BY sort_order'
        )->fetchAll();

        if (!empty($settings['is_published'] ?? 1)) {
            $programs = $db->query(
                'SELECT p.*, dt.slug AS degree_type_slug
                 FROM cf_programs p
                 JOIN cf_degree_types dt ON dt.id = p.degree_type_id
                 WHERE p.is_active = 1
                 ORDER BY dt.sort_order, p.sort_order, p.id'
            )->fetchAll();

            if ($programs) {
                $ids  = implode(',', array_map(fn($r) => (int)$r['id'], $programs));
                $reqs = $db->query(
                    "SELECT * FROM cf_admission_requirements WHERE program_id IN ($ids) ORDER BY program_id, sort_order"
                )->fetchAll();
                $reqs_by_prog = [];
                foreach ($reqs as $r) {
                    $reqs_by_prog[$r['program_id']][] = $r['requirement_text'];
                }
            }
        }
    } catch (Throwable $e) {
        // silently fall through
    }
}

$session_label = $settings['session_label'] ?? 'Summer 2026';
$page_title    = ($settings['page_title']   ?? 'Course Fee Calculator') . ' – Prime University';
$disclaimer    = $settings['disclaimer']    ?? '';
$is_published  = (bool)($settings['is_published'] ?? true);

// ── Build JS data from DB ─────────────────────────────────────────────────────
$degree_subjects = [];
$js_constants    = [];
$js_requirements = [];

foreach ($programs as $prog) {
    $slug    = $prog['program_slug'];
    $dtype   = $prog['degree_type_slug'];

    // Degree subjects map
    $degree_subjects[$dtype][] = ['value' => $slug, 'name' => $prog['program_name']];

    // Constants
    $c = [
        'TOTAL_CREDITS'   => $prog['total_credits']   !== null ? (float)$prog['total_credits']   : null,
        'DURATION_YEARS'  => $prog['duration_years']  !== null ? (float)$prog['duration_years']  : null,
        'TOTAL_SEMESTERS' => $prog['total_semesters'] !== null ? (int)$prog['total_semesters']   : null,
        'TOTAL_MONTHS'    => $prog['total_months']    !== null ? (int)$prog['total_months']       : null,
    ];

    if ($dtype === 'masters') {
        $c['TUITION_FULL']      = $prog['tuition_full']       !== null ? (int)$prog['tuition_full']       : null;
        $c['ADMISSION_FEE']     = $prog['admission_fee_m']    !== null ? (int)$prog['admission_fee_m']    : null;
        $c['REGISTRATION_FEE']  = $prog['registration_fee']   !== null ? (int)$prog['registration_fee']   : null;
        $c['INSTITUTIONAL_FEES']= $prog['institutional_fees'] !== null ? (int)$prog['institutional_fees'] : null;
        if ($prog['campaign_waiver'] !== null)   $c['CAMPAIGN_WAIVER']    = (int)$prog['campaign_waiver'];
        $c['TOTAL_PROGRAM_COST']= $prog['total_program_cost'] !== null ? (int)$prog['total_program_cost'] : null;
        if ($prog['total_after_waiver'] !== null) $c['TOTAL_AFTER_WAIVER'] = (int)$prog['total_after_waiver'];
        if ($prog['monthly_fixed'] !== null)      $c['MONTHLY_FIXED']      = (float)$prog['monthly_fixed'];
        if ($prog['external_waiver'] !== null)  { $c['EXTERNAL_WAIVER']   = (int)$prog['external_waiver'];  }
        if ($prog['external_final']  !== null)  { $c['EXTERNAL_FINAL']    = (int)$prog['external_final'];   }
        if ($prog['external_monthly']!== null)  { $c['EXTERNAL_MONTHLY']  = (float)$prog['external_monthly']; }
        if ($prog['internal_waiver'] !== null)  { $c['INTERNAL_WAIVER']   = (int)$prog['internal_waiver'];  }
        if ($prog['internal_final']  !== null)  { $c['INTERNAL_FINAL']    = (int)$prog['internal_final'];   }
        if ($prog['internal_monthly']!== null)  { $c['INTERNAL_MONTHLY']  = (float)$prog['internal_monthly']; }
    } else {
        $c['STANDARD_TUITION_FULL']   = $prog['standard_tuition_full']    !== null ? (int)$prog['standard_tuition_full']    : null;
        $c['TUITION_PER_SEMESTER']    = $prog['tuition_per_semester']     !== null ? (float)$prog['tuition_per_semester']   : null;
        $c['ADMISSION_FEES']          = $prog['admission_fees']           !== null ? (int)$prog['admission_fees']           : null;
        $c['FIXED_INSTITUTIONAL_FEES']= $prog['fixed_institutional_fees'] !== null ? (int)$prog['fixed_institutional_fees'] : null;
        $c['ENGLISH_COURSE_FEE']      = (int)($prog['english_course_fee'] ?? 0);
        $c['SAFETY_NET_CAP']          = $prog['safety_net_cap']           !== null ? (int)$prog['safety_net_cap']           : null;
        $c['SAFETY_NET_PER_SEMESTER'] = $prog['safety_net_per_semester']  !== null ? (float)$prog['safety_net_per_semester']: null;
        $c['ATTENDANCE_REQUIREMENT']  = (int)($prog['attendance_requirement']    ?? 70);
        $c['SAFETY_NET_GPA_THRESHOLD']= (float)($prog['safety_net_gpa_threshold'] ?? 3.00);
        $c['SCHOLARSHIP_TYPE']        = $prog['scholarship_type'] ?? 'regular_bachelor';
        $c['INITIAL_WAIVER_TIERS']    = $prog['initial_waiver_tiers'] ? json_decode($prog['initial_waiver_tiers'], true) : [];
        $c['MERIT_WAIVER_TIERS']      = $prog['merit_waiver_tiers']   ? json_decode($prog['merit_waiver_tiers'],   true) : [];
    }

    // Remove null values
    $c = array_filter($c, fn($v) => $v !== null);
    $js_constants[$slug] = $c;

    // Requirements
    if (!empty($reqs_by_prog[$prog['id']])) {
        $js_requirements[$slug] = [
            'title'        => fh($prog['program_name']) . ' – Admission Requirements',
            'requirements' => $reqs_by_prog[$prog['id']],
        ];
    }
}
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title><?= fh($page_title) ?></title>
   <meta name="description" content="Estimate your total program cost, scholarship waivers and monthly payment for any degree at Prime University, Bangladesh.">
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
/* ── Hero ──────────────────────────────────────────────────────────────────── */
.pu-fees-hero {
   background: linear-gradient(135deg, #1a2e5a 0%, #2563eb 100%);
   padding: 90px 0 80px;
   position: relative;
   overflow: hidden;
}
.pu-fees-hero::before {
   content: '';
   position: absolute;
   inset: 0;
   background: url('/assets/img/shape/breadcrumb-1-bg.png') center/cover no-repeat;
   opacity: .07;
}
.pu-fees-hero::after {
   content: '';
   position: absolute;
   right: -80px; top: -80px;
   width: 360px; height: 360px;
   background: rgba(255,255,255,.05);
   border-radius: 50%;
}
.pu-fees-hero .hero-inner { position: relative; z-index: 2; }
.pu-fees-hero h1 {
   color: #fff;
   font-size: clamp(28px, 5vw, 42px);
   font-weight: 800;
   margin-bottom: 10px;
}
.pu-fees-hero .breadcrumb-nav a,
.pu-fees-hero .breadcrumb-nav span { color: rgba(255,255,255,.7); font-size: 14px; }
.pu-fees-hero .breadcrumb-nav a:hover { color: #fff; }
.pu-fees-hero .breadcrumb-sep { margin: 0 8px; color: rgba(255,255,255,.4); }

/* ── Widget shell ──────────────────────────────────────────────────────────── */
.cf-widget {
   --p: #4f46e5; --p-dark: #4338ca; --p-light: #eef2ff;
   --p-ring: rgba(79,70,229,0.18); --v: #7c3aed;
   --teal: #0d9488; --green: #059669;
   --warn-bg: #fffbeb; --warn-bd: #fde68a; --warn-text: #92400e;
   --info-bg: #eff6ff; --info-bd: #bfdbfe; --info-text: #1e40af; --info-head: #1d4ed8;
   --bg: #f1f5f9; --surface: #ffffff; --bd: #e2e8f0;
   --text: #0f172a; --text2: #475569; --text3: #94a3b8;
   --r: 16px; --r-sm: 10px; --r-xs: 6px;
   --sh-sm: 0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
   --sh: 0 4px 20px rgba(0,0,0,.08);
   --sh-lg: 0 20px 60px rgba(0,0,0,.12);
   --sh-p: 0 4px 14px rgba(79,70,229,.35);
   --sh-g: 0 4px 14px rgba(5,150,105,.35);
   font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
   font-size: 15px;
   line-height: 1.6;
   color: var(--text);
   max-width: 800px;
   margin: 0 auto;
}
.cf-widget .hidden { display: none !important; }
.cf-widget .cfw-header {
   background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
   color: white;
   text-align: center;
   padding: 44px 32px 36px;
   border-radius: var(--r) var(--r) 0 0;
   position: relative;
   overflow: hidden;
}
.cf-widget .cfw-header::before {
   content: ''; position: absolute; top: -60px; right: -60px;
   width: 260px; height: 260px;
   background: rgba(255,255,255,.06); border-radius: 50%; pointer-events: none;
}
.cf-widget .cfw-header::after {
   content: ''; position: absolute; bottom: -80px; left: -40px;
   width: 220px; height: 220px;
   background: rgba(255,255,255,.05); border-radius: 50%; pointer-events: none;
}
.cf-widget .header-icon {
   font-size: 52px; display: block; margin-bottom: 12px; position: relative; z-index: 1;
   animation: cfwFloat 3s ease-in-out infinite;
}
@keyframes cfwFloat { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-6px)} }
.cf-widget .cfw-header h2 {
   font-size: clamp(22px, 5vw, 30px); font-weight: 800; letter-spacing: -.5px;
   margin-bottom: 8px; position: relative; z-index: 1;
}
.cf-widget .cfw-header .header-sub { font-size: 14px; opacity: .85; font-weight: 500; position: relative; z-index: 1; margin-bottom: 12px; }
.cf-widget .badge-pill {
   display: inline-block; background: rgba(255,255,255,.18);
   border: 1px solid rgba(255,255,255,.3); border-radius: 100px;
   padding: 4px 16px; font-size: 13px; font-weight: 600; position: relative; z-index: 1;
}
.cf-widget .progress-bar {
   background: var(--surface); border-bottom: 1px solid var(--bd);
   padding: 14px 24px; display: flex; align-items: center; justify-content: center;
}
.cf-widget .ps { display: flex; align-items: center; gap: 8px; padding: 6px 14px; border-radius: 100px; opacity: .4; transition: all .3s; white-space: nowrap; }
.cf-widget .ps.active { opacity: 1; background: var(--p-light); }
.cf-widget .ps.done   { opacity: .7; }
.cf-widget .ps-num {
   width: 26px; height: 26px; min-width: 26px; border-radius: 50%;
   background: var(--bd); color: var(--text3); font-size: 12px; font-weight: 700;
   display: flex; align-items: center; justify-content: center; transition: all .3s;
}
.cf-widget .ps.active .ps-num { background: var(--p); color: white; box-shadow: var(--sh-p); }
.cf-widget .ps.done  .ps-num  { background: var(--green); color: white; }
.cf-widget .ps-label { font-size: 12.5px; font-weight: 600; color: var(--text2); }
.cf-widget .ps.active .ps-label { color: var(--p); }
.cf-widget .ps-conn  { flex: 1; max-width: 44px; height: 2px; background: var(--bd); margin: 0 4px; }
.cf-widget .cfw-body {
   background: var(--surface); border-radius: 0 0 var(--r) var(--r);
   padding: 28px 24px 36px; box-shadow: var(--sh-lg);
}
.cf-widget .panel { background: var(--surface); border: 1.5px solid var(--bd); border-radius: var(--r-sm); padding: 22px 20px; margin-bottom: 18px; }
.cf-widget .panel:last-child { margin-bottom: 0; }
.cf-widget .panel-reveal { animation: cfwReveal .4s cubic-bezier(.22,1,.36,1); }
@keyframes cfwReveal { from{opacity:0;transform:translateY(18px)} to{opacity:1;transform:translateY(0)} }
.cf-widget .panel-head { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 18px; }
.cf-widget .step-dot {
   width: 30px; height: 30px; min-width: 30px; border-radius: 50%;
   background: linear-gradient(135deg, var(--p), var(--v)); color: white;
   font-size: 13px; font-weight: 700; display: flex; align-items: center; justify-content: center;
   box-shadow: 0 2px 8px rgba(79,70,229,.3); flex-shrink: 0; margin-top: 2px;
}
.cf-widget .step-dot.green { background: linear-gradient(135deg, var(--green), var(--teal)); box-shadow: 0 2px 8px rgba(5,150,105,.3); }
.cf-widget .panel-title { font-size: 16px; font-weight: 700; color: var(--text); letter-spacing: -.2px; }
.cf-widget .panel-sub   { font-size: 12.5px; color: var(--text3); margin-top: 2px; }
.cf-widget .degree-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 12px; }
.cf-widget .deg-card {
   border: 2px solid var(--bd); border-radius: var(--r-sm); padding: 18px 14px;
   cursor: pointer; text-align: center; transition: all .22s; user-select: none; background: #fcfcfd;
}
.cf-widget .deg-card:hover { border-color: #a5b4fc; background: var(--p-light); transform: translateY(-3px); box-shadow: var(--sh); }
.cf-widget .deg-card.selected { border-color: var(--p); background: var(--p-light); box-shadow: 0 0 0 4px var(--p-ring); transform: translateY(-2px); }
.cf-widget .deg-icon { font-size: 30px; display: block; margin-bottom: 8px; }
.cf-widget .deg-name { font-size: 13.5px; font-weight: 700; color: var(--text); line-height: 1.3; margin-bottom: 5px; }
.cf-widget .deg-card.selected .deg-name { color: var(--p); }
.cf-widget .deg-desc { font-size: 11.5px; color: var(--text3); font-weight: 500; }
.cf-widget .divider { border: none; border-top: 1px solid var(--bd); margin: 20px 0; }
.cf-widget .fg { margin-bottom: 16px; }
.cf-widget .fg:last-of-type { margin-bottom: 0; }
.cf-widget .fg label { display: block; font-size: 13.5px; font-weight: 600; color: var(--text); margin-bottom: 6px; }
.cf-widget .fg label .req { color: #ef4444; margin-left: 3px; }
.cf-widget .fg input[type=number],
.cf-widget .fg select {
   width: 100%; padding: 11px 14px; border: 1.5px solid var(--bd); border-radius: var(--r-sm);
   font-size: 15px; font-family: inherit; color: var(--text); background: var(--surface);
   transition: border-color .2s, box-shadow .2s; -webkit-appearance: none; appearance: none; outline: none;
}
.cf-widget .fg select {
   background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
   background-repeat: no-repeat; background-position: right 12px center; padding-right: 40px; cursor: pointer;
}
.cf-widget .fg input[type=number]:focus,
.cf-widget .fg select:focus { border-color: var(--p); box-shadow: 0 0 0 3px var(--p-ring); }
.cf-widget .fg .hint { font-size: 12px; color: var(--text3); margin-top: 5px; line-height: 1.5; }
.cf-widget .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.cf-widget .btn {
   display: inline-flex; align-items: center; justify-content: center; gap: 8px;
   padding: 13px 22px; border: none; border-radius: var(--r-sm);
   font-size: 15px; font-weight: 700; font-family: inherit;
   cursor: pointer; transition: all .2s; letter-spacing: .1px;
}
.cf-widget .btn-w { width: 100%; }
.cf-widget .btn-lg { padding: 15px 26px; font-size: 16px; }
.cf-widget .btn-primary { background: linear-gradient(135deg, var(--p), var(--v)); color: white; box-shadow: var(--sh-p); }
.cf-widget .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(79,70,229,.4); }
.cf-widget .btn-success { background: linear-gradient(135deg, var(--green), var(--teal)); color: white; box-shadow: var(--sh-g); }
.cf-widget .btn-success:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(5,150,105,.4); }
.cf-widget .cta-box {
   background: linear-gradient(135deg, #f0f4ff, #faf5ff);
   border: 1.5px solid rgba(79,70,229,.2); border-radius: var(--r-sm);
   padding: 28px 24px; text-align: center; margin-top: 4px;
}
.cf-widget .cta-icon { font-size: 40px; display: block; margin-bottom: 10px; }
.cf-widget .cta-box h3 { font-size: 18px; font-weight: 700; margin-bottom: 8px; }
.cf-widget .cta-box p  { font-size: 13.5px; color: var(--text2); margin-bottom: 18px; line-height: 1.6; }
.cf-widget .forecast-panel {
   background: linear-gradient(135deg, #faf5ff, #f0f4ff);
   border: 1.5px solid rgba(124,58,237,.2); border-radius: var(--r-sm);
   padding: 22px 20px;
}
.cf-widget .rc {
   border: 1.5px solid var(--bd); border-top: 4px solid var(--p); border-radius: var(--r-sm);
   padding: 20px 18px; margin-bottom: 16px; background: var(--surface); box-shadow: var(--sh-sm);
}
.cf-widget .rc:last-child { margin-bottom: 0; }
.cf-widget .rc.green  { border-top-color: var(--green); }
.cf-widget .rc.orange { border-top-color: #f59e0b; }
.cf-widget .rc.violet { border-top-color: var(--v); }
.cf-widget .rc-title {
   font-size: 13.5px; font-weight: 700; color: var(--text);
   text-transform: uppercase; letter-spacing: .7px;
   margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid var(--bd);
}
.cf-widget .rr {
   display: flex; justify-content: space-between; align-items: baseline;
   gap: 10px; padding: 8px 0; border-bottom: 1px solid var(--bd); font-size: 14px;
}
.cf-widget .rr:last-child { border-bottom: none; }
.cf-widget .rl { color: var(--text2); flex: 1; }
.cf-widget .rv { font-weight: 600; color: var(--text); text-align: right; white-space: nowrap; }
.cf-widget .rr.total { border-top: 2px solid var(--p); border-bottom: none; padding-top: 12px; margin-top: 6px; font-weight: 700; font-size: 15px; }
.cf-widget .rr.total .rl, .cf-widget .rr.total .rv { color: var(--p); font-weight: 700; }
.cf-widget .rr.highlight { background: var(--warn-bg); border: 1.5px solid var(--warn-bd); border-radius: var(--r-xs); padding: 9px 12px; margin: 6px 0; }
.cf-widget .rr.highlight .rl, .cf-widget .rr.highlight .rv { color: var(--warn-text); }
.cf-widget .rr.indent { padding-left: 14px; }
.cf-widget .rr.indent .rl { font-size: 13px; color: var(--text3); }
.cf-widget .sum-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px,1fr)); gap: 10px; }
.cf-widget .sum-tile { background: var(--p-light); border: 1.5px solid rgba(79,70,229,.15); border-radius: var(--r-sm); padding: 14px 10px; text-align: center; }
.cf-widget .sum-tile .tl { font-size: 10.5px; text-transform: uppercase; letter-spacing: .7px; font-weight: 600; color: var(--text3); margin-bottom: 5px; }
.cf-widget .sum-tile .tv { font-size: 17px; font-weight: 800; color: var(--p); overflow-wrap: break-word; }
.cf-widget .info-box { background: var(--info-bg); border: 1px solid var(--info-bd); border-left: 4px solid #3b82f6; padding: 14px 16px; border-radius: var(--r-sm); margin-top: 12px; }
.cf-widget .info-box h4 { font-size: 13.5px; font-weight: 700; color: var(--info-head); margin-bottom: 8px; }
.cf-widget .info-box p { font-size: 13px; color: var(--info-text); line-height: 1.65; margin-bottom: 6px; }
.cf-widget .info-box p:last-child { margin-bottom: 0; }

/* ── Section wrapper ────────────────────────────────────────────────────────── */
.pu-fees-section { background: #f4f6fb; }

/* ── Disclaimer ─────────────────────────────────────────────────────────────── */
.pu-disclaimer {
   background: #fff8e1; border: 1px solid #ffe082; border-radius: 10px;
   padding: 18px 22px; font-size: 13px; color: #795548; line-height: 1.6; margin-top: 24px;
}
.pu-disclaimer i { color: #f59e0b; margin-right: 6px; }

@media (max-width: 600px) {
   .cf-widget .cfw-header { border-radius: 0; padding: 36px 20px 30px; }
   .cf-widget .progress-bar { padding: 12px 16px; }
   .cf-widget .ps-label { display: none; }
   .cf-widget .ps { padding: 6px 10px; }
   .cf-widget .cfw-body { border-radius: 0; padding: 20px 16px 32px; box-shadow: none; }
   .cf-widget .panel, .cf-widget .rc { padding: 18px 14px; }
   .cf-widget .rr { flex-wrap: wrap; gap: 2px; }
   .cf-widget .rl { width: 100%; font-size: 13px; }
   .cf-widget .rv { width: 100%; text-align: left; }
   .cf-widget .sum-grid { grid-template-columns: repeat(2,1fr); }
   .cf-widget .degree-grid { grid-template-columns: 1fr; gap: 10px; }
   .cf-widget .form-row { grid-template-columns: 1fr; }
   .cf-widget .cta-box { padding: 22px 16px; }
}
@media (max-width: 380px) { .cf-widget .sum-tile .tv { font-size: 14px; } }
   </style>
</head>
<body>
<?php include __DIR__ . '/includes/header-top.php'; ?>
<?php include __DIR__ . '/includes/nav-menu.php'; ?>

<!-- Hero -->
<div class="pu-fees-hero">
    <div class="container">
        <div class="hero-inner text-center">
            <h1>Course Fee Calculator</h1>
            <nav class="breadcrumb-nav">
                <a href="/">Home</a>
                <span class="breadcrumb-sep">/</span>
                <span><?= fh($settings['page_title'] ?? 'Course Fee Calculator') ?></span>
            </nav>
        </div>
    </div>
</div>

<!-- Calculator Section -->
<section class="pu-fees-section py-70">
    <div class="container">

    <?php if (!$is_published || empty($programs)): ?>
    <div class="alert alert-info text-center py-4">
        <i class="fas fa-tools fa-2x mb-3 d-block text-primary"></i>
        <h5>Calculator Coming Soon</h5>
        <p class="mb-0">The course fee calculator is currently being updated. Please check back soon or contact the Admissions Office for fee information.</p>
    </div>
    <?php else: ?>

    <div class="cf-widget">
        <!-- Widget Header -->
        <div class="cfw-header">
            <span class="header-icon">&#127979;</span>
            <h2><?= fh($settings['page_title'] ?? 'Course Fee Calculator') ?></h2>
            <p class="header-sub">Estimate your total program cost, scholarships &amp; monthly payments</p>
            <span class="badge-pill" id="badgePill"><?= fh($session_label) ?></span>
        </div>

        <!-- Progress Steps -->
        <div class="progress-bar">
            <div class="ps active" id="ps1"><div class="ps-num">1</div><div class="ps-label">Degree &amp; GPA</div></div>
            <div class="ps-conn"></div>
            <div class="ps" id="ps2"><div class="ps-num">2</div><div class="ps-label">Program Preview</div></div>
            <div class="ps-conn"></div>
            <div class="ps" id="ps3"><div class="ps-num">3</div><div class="ps-label">Full Forecast</div></div>
        </div>

        <!-- Widget Body -->
        <div class="cfw-body">
            <!-- Step 1: Select Degree & Program -->
            <div class="panel" id="inputPanel">
                <div class="panel-head">
                    <div class="step-dot">1</div>
                    <div>
                        <div class="panel-title">Select Your Degree Type</div>
                        <div class="panel-sub">Choose the program category you want to enroll in</div>
                    </div>
                </div>
                <div class="degree-grid">
                    <?php
                    $card_map = [
                        'regular-bachelor'      => ['id' => 'dc-regular', 'icon' => '📚'],
                        'bachelor-from-diploma' => ['id' => 'dc-diploma', 'icon' => '🔧'],
                        'masters'               => ['id' => 'dc-masters', 'icon' => '🏛'],
                    ];
                    foreach ($degree_types as $dt):
                        $card_info = $card_map[$dt['slug']] ?? ['id' => 'dc-' . $dt['slug'], 'icon' => $dt['icon'] ?? '🎓'];
                    ?>
                    <div class="deg-card" id="<?= fh($card_info['id']) ?>"
                         onclick="selectDegree('<?= fh($dt['slug']) ?>')">
                        <span class="deg-icon"><?= $card_info['icon'] ?></span>
                        <div class="deg-name"><?= fh($dt['name']) ?></div>
                        <div class="deg-desc"><?= fh($dt['description'] ?? '') ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div id="step2Area" class="hidden">
                    <hr class="divider">
                    <div class="panel-head">
                        <div class="step-dot">2</div>
                        <div>
                            <div class="panel-title">Select Subject &amp; Enter GPA</div>
                            <div class="panel-sub" id="step2Sub">Choose your program and enter your academic GPA</div>
                        </div>
                    </div>
                    <div class="fg">
                        <label for="subject">Subject / Program <span class="req">*</span></label>
                        <select id="subject" onchange="handleSubjectChange()">
                            <option value="">-- Select your program --</option>
                        </select>
                    </div>
                    <div class="fg hidden" id="combinedGPAGroup">
                        <label for="combinedGPA">Combined SSC + HSC GPA (out of 10.0) <span class="req">*</span></label>
                        <input type="number" id="combinedGPA" min="0" max="10" step="0.01" placeholder="e.g., 8.50">
                        <div class="hint">Total GPA from SSC and HSC combined (0.00 – 10.00). Determines your 1st semester scholarship.</div>
                    </div>
                    <div class="fg hidden" id="diplomaGPAGroup">
                        <label for="diplomaCombinedGPA">SSC GPA + Diploma CGPA Combined (out of 10.0) <span class="req">*</span></label>
                        <input type="number" id="diplomaCombinedGPA" min="0" max="10" step="0.01" placeholder="e.g., 7.50">
                        <div class="hint">Add your SSC GPA (out of 5.0) and Diploma CGPA (out of 4.0) together (0.00 – 10.00). Determines your 1st semester scholarship.</div>
                    </div>
                    <button class="btn btn-primary btn-w" id="previewBtn" onclick="showPreview()" style="margin-top:8px;">
                        &#128202; Show Program Preview &rarr;
                    </button>
                </div>
            </div>

            <!-- Preview Results -->
            <div id="previewResults" class="hidden panel-reveal"></div>

            <!-- Forecast CTA -->
            <div id="forecastCTA" class="hidden cta-box panel-reveal">
                <span class="cta-icon">&#128302;</span>
                <h3>Calculate Total <?= fh((string)round((float)($degree_types[0]['description'] ?? 4))) ?>-Year Forecast</h3>
                <h3 style="display:none" id="forecastCtaTitle">Calculate Total Forecast</h3>
                <p>Enter your expected semester GPA to see full scholarship projections, total program cost, and monthly payment estimates.</p>
                <button class="btn btn-success btn-lg" onclick="showForecastInputs()">
                    &#128200; Calculate Total Forecast &rarr;
                </button>
            </div>

            <!-- Forecast Inputs -->
            <div id="forecastInputSection" class="hidden forecast-panel panel-reveal" style="margin-top:18px;">
                <div class="panel-head">
                    <div class="step-dot green">3</div>
                    <div>
                        <div class="panel-title">Full Forecast Inputs</div>
                        <div class="panel-sub">Tell us your expected academic performance</div>
                    </div>
                </div>
                <div class="fg">
                    <label for="expectedGPA">Expected Semester GPA (out of 4.0) <span class="req">*</span></label>
                    <input type="number" id="expectedGPA" min="0" max="4" step="0.01"
                           placeholder="e.g., 3.70" oninput="checkAttendanceVisibility()">
                    <div class="hint">Your projected average GPA per semester — determines merit scholarship from semester 2 onwards.</div>
                </div>
                <div class="fg hidden" id="attendanceGroup">
                    <label for="attendance" id="attendanceLabel">Will you maintain 70%+ attendance? <span class="req">*</span></label>
                    <select id="attendance" onchange="checkAttendanceVisibility()">
                        <option value="">-- Select --</option>
                        <option value="yes" id="attendanceYes">Yes, I maintained/will maintain 70%+ attendance</option>
                        <option value="no"  id="attendanceNo">No, I may not maintain 70% attendance</option>
                    </select>
                    <div class="hint" id="attendanceHint"></div>
                </div>
                <button class="btn btn-success btn-w btn-lg" style="margin-top:10px;" onclick="calculateForecast()">
                    &#9889; Calculate Full Forecast &rarr;
                </button>
            </div>

            <!-- Forecast Results -->
            <div id="forecastResults" class="hidden panel-reveal" style="margin-top:18px;"></div>

        </div><!-- /cfw-body -->
    </div><!-- /cf-widget -->

    <?php if ($disclaimer): ?>
    <div class="pu-disclaimer" style="max-width:800px;margin:24px auto 0;">
        <i class="fas fa-info-circle"></i>
        <?= nl2br(fh($disclaimer)) ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>
    </div><!-- /container -->
</section>

<?php include __DIR__ . '/includes/scripts.php'; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
// ============================================================
// DATA FROM DATABASE (PHP → JS)
// ============================================================
var SESSION_LABEL = <?= json_encode($session_label) ?>;

var DEGREE_SUBJECTS = <?= json_encode($degree_subjects, JSON_UNESCAPED_UNICODE) ?>;

var CONSTANTS = <?= json_encode($js_constants, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?>;

var ADMISSION_REQUIREMENTS = <?= json_encode($js_requirements, JSON_UNESCAPED_UNICODE) ?>;

// ============================================================
// HELPERS
// ============================================================
var MASTERS_LIST = [];
var DIPLOMA_LIST = [];
(function() {
    var subjects = DEGREE_SUBJECTS['masters'] || [];
    for (var i = 0; i < subjects.length; i++) MASTERS_LIST.push(subjects[i].value);
    var dips = DEGREE_SUBJECTS['bachelor-from-diploma'] || [];
    for (var i = 0; i < dips.length; i++) DIPLOMA_LIST.push(dips[i].value);
})();

function isMasters(s) { return MASTERS_LIST.indexOf(s) !== -1; }
function isDiploma(s)  { return DIPLOMA_LIST.indexOf(s)  !== -1; }

function fmtBDT(n) {
    return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' BDT';
}

function getFixedMonthlyNonTuition(subject) {
    var c = CONSTANTS[subject];
    return ((c.FIXED_INSTITUTIONAL_FEES || 0) + (c.ENGLISH_COURSE_FEE || 0)) / c.TOTAL_MONTHS;
}

// ============================================================
// WAIVER CALCULATIONS (using DB tier data)
// ============================================================
function calculateInitialWaiver(gpa, subject) {
    var c = CONSTANTS[subject];
    if (!c || !c.INITIAL_WAIVER_TIERS) return 0;
    var tiers = c.INITIAL_WAIVER_TIERS;
    for (var i = 0; i < tiers.length; i++) {
        var t = tiers[i];
        if (gpa >= t.min && gpa <= t.max) return t.pct;
    }
    return 0;
}

function calculateMeritWaiver(gpa, subject) {
    var c = CONSTANTS[subject];
    if (!c || !c.MERIT_WAIVER_TIERS) return 0;
    var tiers = c.MERIT_WAIVER_TIERS;
    // Sort descending by min for correct matching
    var sorted = tiers.slice().sort(function(a,b){ return b.min - a.min; });
    for (var i = 0; i < sorted.length; i++) {
        var t = sorted[i];
        if (gpa >= t.min && gpa <= t.max) return t.pct;
    }
    return 0;
}

function calculateTotalTuition(combinedGPA, expectedGPA, maintainsAttendance, subject) {
    var c = CONSTANTS[subject];
    var initialWaiverPercent = calculateInitialWaiver(combinedGPA, subject);
    var firstSemTuition      = c.TUITION_PER_SEMESTER * (1 - initialWaiverPercent / 100);
    var meritWaiverPercent   = calculateMeritWaiver(expectedGPA, subject);
    var remainingSemTuition  = c.TUITION_PER_SEMESTER * (1 - meritWaiverPercent / 100);
    var remainingSemestersTotal = remainingSemTuition * (c.TOTAL_SEMESTERS - 1);
    var totalTuition = firstSemTuition + remainingSemestersTotal;
    var cappedRemaining = remainingSemestersTotal;
    var safetyNetApplied = false;

    var attReq  = c.ATTENDANCE_REQUIREMENT  || 70;
    var gpaThresh = c.SAFETY_NET_GPA_THRESHOLD || 3.0;

    if (!isMasters(subject) && expectedGPA < gpaThresh && maintainsAttendance) {
        if (totalTuition > c.SAFETY_NET_CAP) {
            cappedRemaining   = c.SAFETY_NET_CAP - firstSemTuition;
            totalTuition      = firstSemTuition + cappedRemaining;
            safetyNetApplied  = true;
        }
    }

    return {
        firstSemesterTuition:      firstSemTuition,
        initialWaiverPercent:      initialWaiverPercent,
        remainingSemestersTuition: safetyNetApplied ? cappedRemaining : remainingSemestersTotal,
        meritWaiverPercent:        meritWaiverPercent,
        totalTuition:              totalTuition,
        safetyNetApplied:          safetyNetApplied
    };
}

// ============================================================
// PROGRESS
// ============================================================
function setProgress(step) {
    ['ps1','ps2','ps3'].forEach(function(id, i) {
        var el = document.getElementById(id);
        el.classList.remove('active','done');
        if (i + 1 === step)     el.classList.add('active');
        else if (i + 1 < step)  el.classList.add('done');
    });
}

// ============================================================
// UX
// ============================================================
function selectDegree(type) {
    document.querySelectorAll('.cf-widget .deg-card').forEach(function(c) { c.classList.remove('selected'); });
    var cardIds = {
        'regular-bachelor':      'dc-regular',
        'bachelor-from-diploma': 'dc-diploma',
        'masters':               'dc-masters'
    };
    var cardEl = document.getElementById(cardIds[type]);
    if (cardEl) cardEl.classList.add('selected');

    var sel = document.getElementById('subject');
    sel.innerHTML = '<option value="">-- Select your program --</option>';
    (DEGREE_SUBJECTS[type] || []).forEach(function(s) {
        var o = document.createElement('option');
        o.value = s.value; o.textContent = s.name;
        sel.appendChild(o);
    });

    var subs = {
        'regular-bachelor':      'Choose your bachelor program and enter Combined SSC + HSC GPA',
        'bachelor-from-diploma': 'Choose your program and enter combined SSC + Diploma CGPA (out of 10)',
        'masters':               'Choose your masters program — no GPA entry required'
    };
    document.getElementById('step2Sub').textContent = subs[type] || 'Choose your program';

    document.getElementById('combinedGPAGroup').classList.add('hidden');
    document.getElementById('diplomaGPAGroup').classList.add('hidden');
    document.getElementById('step2Area').classList.remove('hidden');
    hideResults();

    setTimeout(function() {
        document.getElementById('step2Area').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }, 120);
}

function handleSubjectChange() {
    var sub = document.getElementById('subject').value;
    document.getElementById('combinedGPAGroup').classList.add('hidden');
    document.getElementById('diplomaGPAGroup').classList.add('hidden');

    if (sub && !isMasters(sub) && !isDiploma(sub)) {
        document.getElementById('combinedGPAGroup').classList.remove('hidden');
    } else if (isDiploma(sub)) {
        document.getElementById('diplomaGPAGroup').classList.remove('hidden');
    }

    var btn = document.getElementById('previewBtn');
    btn.innerHTML = isMasters(sub)
        ? '&#128202; Show Fee Breakdown &rarr;'
        : '&#128202; Show Program Preview &rarr;';

    hideResults();
}

function hideResults() {
    document.getElementById('previewResults').classList.add('hidden');
    document.getElementById('forecastCTA').classList.add('hidden');
    document.getElementById('forecastInputSection').classList.add('hidden');
    document.getElementById('forecastResults').classList.add('hidden');
    setProgress(1);
}

function showPreview() {
    var sub = document.getElementById('subject').value;
    if (!sub) { alert('Please select a subject/program first.'); return; }

    var combinedGPA = 0;
    if (!isMasters(sub)) {
        if (isDiploma(sub)) {
            combinedGPA = parseFloat(document.getElementById('diplomaCombinedGPA').value);
            if (isNaN(combinedGPA) || combinedGPA < 0 || combinedGPA > 10) {
                alert('Please enter a valid combined SSC + Diploma CGPA (0.00 - 10.00).'); return;
            }
        } else {
            combinedGPA = parseFloat(document.getElementById('combinedGPA').value);
            if (isNaN(combinedGPA) || combinedGPA < 0 || combinedGPA > 10) {
                alert('Please enter a valid Combined GPA (0.00 - 10.00).'); return;
            }
        }
    }

    var prevDiv = document.getElementById('previewResults');

    if (isMasters(sub)) {
        prevDiv.innerHTML = generateMastersHTML(sub);
        prevDiv.classList.remove('hidden');
        document.getElementById('forecastCTA').classList.add('hidden');
        document.getElementById('forecastInputSection').classList.add('hidden');
        document.getElementById('forecastResults').classList.add('hidden');
        setProgress(3);
    } else {
        prevDiv.innerHTML = generatePreviewHTML(combinedGPA, sub);
        prevDiv.classList.remove('hidden');
        document.getElementById('forecastCTA').classList.remove('hidden');
        document.getElementById('forecastInputSection').classList.add('hidden');
        document.getElementById('forecastResults').classList.add('hidden');
        setProgress(2);
    }

    var subLabel = document.getElementById('subject').selectedOptions[0].text;
    var shortLabel = subLabel.replace(/[(][^)]*[)]/g, '').trim().split('-')[0].trim();
    document.getElementById('badgePill').textContent = shortLabel + ' - ' + SESSION_LABEL;

    setTimeout(function() { prevDiv.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 120);
}

function showForecastInputs() {
    var sec = document.getElementById('forecastInputSection');
    sec.classList.remove('hidden');
    document.getElementById('forecastResults').classList.add('hidden');
    document.getElementById('expectedGPA').value = '';
    document.getElementById('attendanceGroup').classList.add('hidden');
    document.getElementById('attendance').value = '';
    setTimeout(function() { sec.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 120);
}

function checkAttendanceVisibility() {
    var sub = document.getElementById('subject').value;
    var gpa = parseFloat(document.getElementById('expectedGPA').value);
    var grp = document.getElementById('attendanceGroup');
    var sel = document.getElementById('attendance');

    if (isNaN(gpa) || !sub || isMasters(sub)) {
        grp.classList.add('hidden'); sel.removeAttribute('required'); return;
    }

    var c        = CONSTANTS[sub] || {};
    var attReq   = c.ATTENDANCE_REQUIREMENT   || 70;
    var gpaThresh= c.SAFETY_NET_GPA_THRESHOLD || 3.0;
    var show     = gpa < gpaThresh;

    if (show) {
        document.getElementById('attendanceLabel').textContent =
            'Did you (or will you) maintain at least ' + attReq + '% attendance in the previous semester?';
        document.getElementById('attendanceYes').textContent =
            'Yes, I maintained/will maintain ' + attReq + '%+ attendance';
        document.getElementById('attendanceNo').textContent =
            'No, I may not maintain ' + attReq + '% attendance';
        document.getElementById('attendanceHint').textContent =
            'Safety Net: If your GPA falls below ' + gpaThresh + ' but you maintained ' + attReq +
            '%+ attendance in the previous semester, the Safety Net scholarship applies from the next semester, capping your remaining tuition.';
        grp.classList.remove('hidden');
        sel.setAttribute('required', 'required');
    } else {
        grp.classList.add('hidden');
        sel.removeAttribute('required');
        sel.value = '';
    }
}

function calculateForecast() {
    var sub    = document.getElementById('subject').value;
    var attGrp = document.getElementById('attendanceGroup');
    var attVal = document.getElementById('attendance').value;
    var expGPA = parseFloat(document.getElementById('expectedGPA').value);

    if (isNaN(expGPA) || expGPA < 0 || expGPA > 4) {
        alert('Please enter a valid Expected Semester GPA (0.00 - 4.00).'); return;
    }
    if (!attGrp.classList.contains('hidden') && !attVal) {
        alert('Please select your attendance status.'); return;
    }

    var maintainsAtt = attGrp.classList.contains('hidden') ? true : attVal === 'yes';
    var combinedGPA  = isDiploma(sub)
        ? parseFloat(document.getElementById('diplomaCombinedGPA').value) || 0
        : parseFloat(document.getElementById('combinedGPA').value) || 0;

    var bd   = calculateTotalTuition(combinedGPA, expGPA, maintainsAtt, sub);
    var fDiv = document.getElementById('forecastResults');
    fDiv.innerHTML = generateForecastHTML(combinedGPA, expGPA, maintainsAtt, bd, sub);
    fDiv.classList.remove('hidden');
    setProgress(3);
    setTimeout(function() { fDiv.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 120);
}

// ============================================================
// HTML GENERATION: PREVIEW
// ============================================================
function generatePreviewHTML(combinedGPA, subject) {
    var c        = CONSTANTS[subject];
    var dp       = isDiploma(subject);
    var initPct  = calculateInitialWaiver(combinedGPA, subject);
    var firstSem = c.TUITION_PER_SEMESTER * (1 - initPct / 100);

    var firstSemMonths         = Math.round(c.TOTAL_MONTHS / c.TOTAL_SEMESTERS);
    var firstSemTuitionMonthly = firstSem / firstSemMonths;
    var fixedMonthly           = getFixedMonthlyNonTuition(subject);
    var totalMonthlyFirst      = fixedMonthly + firstSemTuitionMonthly;

    var initLabel = dp
        ? ('SSC+Diploma GPA ' + combinedGPA.toFixed(2))
        : ('SSC+HSC GPA '     + combinedGPA.toFixed(2));

    var admReq  = ADMISSION_REQUIREMENTS[subject];
    var admHtml = admReq ? (
        '<div style="margin-top:16px;">' +
        '<div style="font-size:13px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">Admission Requirements</div>' +
        '<ul style="margin-left:18px;line-height:1.9;font-size:13.5px;color:#475569;">' +
        admReq.requirements.map(function(r){ return '<li>' + r + '</li>'; }).join('') +
        '</ul></div>'
    ) : '';

    var schHtml = initPct > 0 ? (
        '<div class="rr highlight"><span class="rl">Scholarship (' + initLabel + ' - ' + initPct + '% off):</span>' +
        '<span class="rv"><strong>- ' + fmtBDT(c.TUITION_PER_SEMESTER * initPct / 100) + '</strong></span></div>' +
        '<div class="rr"><span class="rl">Tuition after Scholarship (1st Semester):</span><span class="rv">' + fmtBDT(firstSem) + '</span></div>'
    ) : '';

    return '' +
        '<div class="rc panel-reveal">' +
        '<div class="rc-title">Program Overview</div>' +
        '<div class="sum-grid">' +
        '<div class="sum-tile"><div class="tl">Total Credits</div><div class="tv">' + c.TOTAL_CREDITS + '</div></div>' +
        '<div class="sum-tile"><div class="tl">Total Semesters</div><div class="tv">' + c.TOTAL_SEMESTERS + '</div></div>' +
        '<div class="sum-tile"><div class="tl">Duration</div><div class="tv">' + c.DURATION_YEARS + ' Yrs</div></div>' +
        '<div class="sum-tile"><div class="tl">Total Months</div><div class="tv">' + c.TOTAL_MONTHS + '</div></div>' +
        '</div>' + admHtml + '</div>' +

        '<div class="rc panel-reveal">' +
        '<div class="rc-title">Regular Program Cost</div>' +
        '<div class="rr"><span class="rl">Tuition Fees (Regular):</span><span class="rv">' + fmtBDT(c.STANDARD_TUITION_FULL) + '</span></div>' +
        '<div class="rr"><span class="rl">Administrative Fees:</span><span class="rv">' + fmtBDT(c.FIXED_INSTITUTIONAL_FEES) + '</span></div>' +
        (c.ENGLISH_COURSE_FEE > 0 ? '<div class="rr"><span class="rl">English Course:</span><span class="rv">' + fmtBDT(c.ENGLISH_COURSE_FEE) + '</span></div>' : '') +
        '<div class="rr"><span class="rl">Admission Fee (One-time):</span><span class="rv">' + fmtBDT(10000) + '</span></div>' +
        '<div class="rr"><span class="rl">Registration Fees (' + c.TOTAL_SEMESTERS + ' Semesters x 1,000):</span><span class="rv">' + fmtBDT(c.TOTAL_SEMESTERS * 1000) + '</span></div>' +
        '<div class="rr"><span class="rl">Admission Form + ID Card (Extra):</span><span class="rv">' + fmtBDT(1000) + '</span></div>' +
        '<div class="rr total"><span class="rl">Total Regular Program Cost:</span><span class="rv">' + fmtBDT(c.STANDARD_TUITION_FULL + c.FIXED_INSTITUTIONAL_FEES + c.ENGLISH_COURSE_FEE + 10000 + c.TOTAL_SEMESTERS * 1000) + '</span></div>' +
        '<div class="info-box" style="margin-top:10px;"><p>Admission Form + ID Card (1,000 BDT) is <strong>not included</strong> in the Total Regular Program Cost above. Payable separately at admission.</p></div>' +
        '</div>' +

        '<div class="rc orange panel-reveal">' +
        '<div class="rc-title">Admission Day Payment</div>' +
        '<div class="rr indent"><span class="rl">Admission Fee:</span><span class="rv">' + fmtBDT(10000) + '</span></div>' +
        '<div class="rr indent"><span class="rl">Registration Fee (1st Semester):</span><span class="rv">' + fmtBDT(1000) + '</span></div>' +
        '<div class="rr indent"><span class="rl">Admission Form + ID Card:</span><span class="rv">' + fmtBDT(1000) + '</span></div>' +
        '<div class="rr total"><span class="rl">Total Admission Day Payment:</span><span class="rv">' + fmtBDT(c.ADMISSION_FEES) + '</span></div>' +
        '</div>' +

        '<div class="rc green panel-reveal">' +
        '<div class="rc-title">1st Semester Monthly Breakdown</div>' +
        '<div class="rr"><span class="rl">Regular Tuition (1st Semester):</span><span class="rv">' + fmtBDT(c.TUITION_PER_SEMESTER) + '</span></div>' +
        schHtml +
        '<div class="rr"><span class="rl">Tuition per Month (1st Semester, ÷ ' + firstSemMonths + ' months):</span><span class="rv">' + fmtBDT(firstSemTuitionMonthly) + '</span></div>' +
        '<div class="rr"><span class="rl">Monthly Administrative Cost:</span><span class="rv">' + fmtBDT(fixedMonthly) + '</span></div>' +
        '<div class="rr total"><span class="rl">Total Monthly (1st Semester – ' + firstSemMonths + ' months):</span><span class="rv">' + fmtBDT(totalMonthlyFirst) + '</span></div>' +
        '</div>';
}

// ============================================================
// HTML GENERATION: FULL FORECAST
// ============================================================
function generateForecastHTML(combinedGPA, expectedGPA, maintainsAttendance, bd, subject) {
    var c  = CONSTANTS[subject];
    var dp = isDiploma(subject);

    var totalReg           = c.TOTAL_SEMESTERS * 1000;
    var totalInstitutional = (c.FIXED_INSTITUTIONAL_FEES || 0) + (c.ENGLISH_COURSE_FEE || 0);
    var grandTotal         = 10000 + totalReg + (dp ? 0 : 1000) + totalInstitutional + bd.totalTuition;
    var firstSemMonths     = Math.round(c.TOTAL_MONTHS / c.TOTAL_SEMESTERS);
    var remainingMonths    = c.TOTAL_MONTHS - firstSemMonths;
    var monthlyAvg         = totalInstitutional / c.TOTAL_MONTHS
                           + (bd.totalTuition - bd.firstSemesterTuition) / remainingMonths;

    var attRate    = (c.ATTENDANCE_REQUIREMENT   || 70).toString();
    var safetyThresh = (c.SAFETY_NET_GPA_THRESHOLD || 3.0).toString();

    var tuitionRows = bd.safetyNetApplied
        ? '<div class="rr highlight"><span class="rl">Safety Net Applied (Prev-Sem Attendance ≥ ' + attRate + '%, GPA &lt; ' + safetyThresh + ') – Tuition capped from 2nd semester at:</span>' +
          '<span class="rv">' + fmtBDT(c.SAFETY_NET_CAP) + '</span></div>'
        : '<div class="rr indent"><span class="rl">1st Semester (' + bd.initialWaiverPercent + '% scholarship):</span><span class="rv">' + fmtBDT(bd.firstSemesterTuition) + '</span></div>' +
          '<div class="rr indent"><span class="rl">Remaining ' + (c.TOTAL_SEMESTERS - 1) + ' Semesters (' + bd.meritWaiverPercent + '% scholarship):</span><span class="rv">' + fmtBDT(bd.remainingSemestersTuition) + '</span></div>';

    var summaryGPAHtml = dp
        ? '<div class="sum-tile"><div class="tl">SSC+Diploma GPA</div><div class="tv">' + combinedGPA.toFixed(2) + '</div></div>'
        : '<div class="sum-tile"><div class="tl">Combined GPA</div><div class="tv">' + combinedGPA.toFixed(2) + '</div></div>';

    return '' +
        '<div class="rc violet panel-reveal">' +
        '<div class="rc-title">Total ' + c.DURATION_YEARS + '-Year Forecast</div>' +
        '<div class="rr"><span class="rl">Admission Fee (One-time):</span><span class="rv">' + fmtBDT(10000) + '</span></div>' +
        '<div class="rr"><span class="rl">Registration Fees (' + c.TOTAL_SEMESTERS + ' x 1,000):</span><span class="rv">' + fmtBDT(totalReg) + '</span></div>' +
        (!dp ? '<div class="rr"><span class="rl">Admission Form + ID Card (Extra):</span><span class="rv">' + fmtBDT(1000) + '</span></div>' : '') +
        '<div class="rr"><span class="rl">Total Administrative Fees:</span><span class="rv">' + fmtBDT(totalInstitutional) + '</span></div>' +
        '<div class="rr indent"><span class="rl">Fixed Institutional Fees:</span><span class="rv">' + fmtBDT(c.FIXED_INSTITUTIONAL_FEES || 0) + '</span></div>' +
        (c.ENGLISH_COURSE_FEE > 0 ? '<div class="rr indent"><span class="rl">English Course Fee:</span><span class="rv">' + fmtBDT(c.ENGLISH_COURSE_FEE) + '</span></div>' : '') +
        '<div class="rr"><span class="rl">Regular Tuition (All ' + c.TOTAL_SEMESTERS + ' Semesters):</span><span class="rv">' + fmtBDT(c.STANDARD_TUITION_FULL) + '</span></div>' +
        (bd.initialWaiverPercent > 0 ? '<div class="rr indent"><span class="rl">' + (dp ? 'SSC+Diploma' : 'SSC+HSC') + ' Scholarship (1st Sem, ' + bd.initialWaiverPercent + '%):</span><span class="rv">- ' + fmtBDT(c.TUITION_PER_SEMESTER * bd.initialWaiverPercent / 100) + '</span></div>' : '') +
        (bd.meritWaiverPercent  > 0 ? '<div class="rr indent"><span class="rl">Merit Scholarship (Sems 2–' + c.TOTAL_SEMESTERS + ', ' + bd.meritWaiverPercent + '%):</span><span class="rv">- ' + fmtBDT(c.TUITION_PER_SEMESTER * bd.meritWaiverPercent / 100 * (c.TOTAL_SEMESTERS - 1)) + '</span></div>' : '') +
        '<div class="rr"><span class="rl">Payable Tuition (After Scholarships):</span><span class="rv">' + fmtBDT(bd.totalTuition) + '</span></div>' +
        tuitionRows +
        '<div class="rr total"><span class="rl">GRAND TOTAL (' + c.DURATION_YEARS + ' Years, approx.):</span><span class="rv">approx. ' + fmtBDT(grandTotal) + '</span></div>' +
        '<div class="rr"><span class="rl">Approx. Average Monthly Payment:</span><span class="rv">approx. ' + fmtBDT(monthlyAvg) + '</span></div>' +
        '<div class="info-box" style="margin-top:16px;"><h4>Important Note</h4>' +
        '<p><strong>One-time Admission Fees, Registration Fees, and Admission Form + ID Card are included in the Grand Total but not in the Approx. Average Monthly Payment.</strong></p>' +
        '<p>The Approx. Average Monthly Payment is calculated over the remaining <strong>' + remainingMonths + ' months</strong> (total ' + c.TOTAL_MONTHS + ' months minus the 1st semester of ' + firstSemMonths + ' months).</p>' +
        '</div></div>' +

        '<div class="rc panel-reveal"><div class="rc-title">Your Summary</div>' +
        '<div class="sum-grid">' + summaryGPAHtml +
        '<div class="sum-tile"><div class="tl">Expected GPA</div><div class="tv">' + expectedGPA.toFixed(2) + '</div></div>' +
        '<div class="sum-tile"><div class="tl">1st Sem Scholarship</div><div class="tv">' + bd.initialWaiverPercent + '%</div></div>' +
        '<div class="sum-tile"><div class="tl">Merit Scholarship</div><div class="tv">' + bd.meritWaiverPercent + '%</div></div>' +
        '<div class="sum-tile"><div class="tl">' + attRate + '% Attendance</div><div class="tv">' + (maintainsAttendance ? 'Yes' : 'No') + '</div></div>' +
        '<div class="sum-tile"><div class="tl">Safety Net</div><div class="tv">' + (bd.safetyNetApplied ? 'Applied' : 'N/A') + '</div></div>' +
        '</div></div>' +

        '<div class="info-box panel-reveal" style="margin-top:4px;"><h4>Key Definitions</h4>' +
        '<p><strong>Administrative Fees:</strong> Cover operational costs including staff salaries, utilities, maintenance, library resources, and administrative services.</p>' +
        '<p><strong>' + (dp ? 'SSC + Diploma CGPA Based Scholarship (1st Semester):' : 'SSC + HSC Result Based Scholarship (1st Semester):') + '</strong> ' +
        (dp ? 'Awarded in the 1st semester based on SSC GPA and Diploma CGPA. Fixed for the 1st semester and not renewable.' : 'Awarded in the 1st semester based on combined SSC+HSC GPA. Fixed for the 1st semester and not renewable.') + '</p>' +
        '<p><strong>Merit Scholarship (Sems 2–' + c.TOTAL_SEMESTERS + '):</strong> Merit-based scholarship from the 2nd semester, calculated from the previous semester GPA. Minimum qualifying GPA: <strong>' + safetyThresh + '</strong>. Valid for one semester only and must be re-earned each semester.</p>' +
        '<p><strong>Safety Net (Attendance-based):</strong> If semester GPA falls below ' + safetyThresh + ' but the student maintained ≥ ' + attRate + '% attendance in the previous semester, total tuition is capped at <strong>' + fmtBDT(c.SAFETY_NET_CAP) + '</strong> for the entire program (applies from 2nd semester onward).</p>' +
        '</div>';
}

// ============================================================
// HTML GENERATION: MASTERS
// ============================================================
function generateMastersHTML(subject) {
    var c    = CONSTANTS[subject];
    var dual = c.EXTERNAL_WAIVER !== undefined;

    var subjectEl  = document.getElementById('subject');
    var progName   = subjectEl ? subjectEl.selectedOptions[0].text : subject;
    var durText    = c.DURATION_YEARS + ' Year' + (c.DURATION_YEARS > 1 ? 's' : '') +
                     ' (' + c.TOTAL_SEMESTERS + ' Semesters)';

    var admReq  = ADMISSION_REQUIREMENTS[subject];
    var admHtml = admReq
        ? '<div class="rc panel-reveal"><div class="rc-title">Admission Requirements</div>' +
          '<ul style="margin:4px 0 0 18px;line-height:1.9;font-size:13.5px;color:#475569;">' +
          admReq.requirements.map(function(r){ return '<li>' + r + '</li>'; }).join('') +
          '</ul></div>'
        : '';

    var campaignHtml;
    if (dual) {
        campaignHtml =
            '<div class="rc green panel-reveal"><div class="rc-title">Summer Admission Campaign</div>' +
            '<div class="rr"><span class="rl">Regular Program Cost:</span><span class="rv">' + fmtBDT(c.TOTAL_PROGRAM_COST) + '</span></div>' +
            '<div style="margin:10px 0 6px;font-weight:600;font-size:13.5px;color:#475569;">Option A: External Student Discount</div>' +
            '<div class="rr highlight"><span class="rl">External Waiver:</span><span class="rv"><strong>- ' + fmtBDT(c.EXTERNAL_WAIVER) + '</strong></span></div>' +
            '<div class="rr"><span class="rl">Final Payable:</span><span class="rv">' + fmtBDT(c.EXTERNAL_FINAL) + '</span></div>' +
            '<div class="rr"><span class="rl">Monthly:</span><span class="rv">' + fmtBDT(c.EXTERNAL_MONTHLY) + '</span></div>' +
            '<div style="margin:10px 0 6px;font-weight:600;font-size:13.5px;color:#475569;">Option B: Internal (Alumni) Discount</div>' +
            '<div class="rr highlight"><span class="rl">Alumni Loyalty Waiver:</span><span class="rv"><strong>- ' + fmtBDT(c.INTERNAL_WAIVER) + '</strong></span></div>' +
            '<div class="rr total"><span class="rl">Final Payable:</span><span class="rv">' + fmtBDT(c.INTERNAL_FINAL) + '</span></div>' +
            '<div class="rr"><span class="rl">Monthly:</span><span class="rv">' + fmtBDT(c.INTERNAL_MONTHLY) + '</span></div>' +
            '</div>';
    } else if (c.CAMPAIGN_WAIVER === undefined) {
        campaignHtml =
            '<div class="rc green panel-reveal"><div class="rc-title">Program Fee Summary</div>' +
            '<div class="rr total"><span class="rl">Total Program Cost:</span><span class="rv">' + fmtBDT(c.TOTAL_PROGRAM_COST) + '</span></div>' +
            '<div class="rr"><span class="rl">Monthly Fixed Fees:</span><span class="rv">' + fmtBDT(c.MONTHLY_FIXED) + '</span></div>' +
            '</div>';
    } else {
        campaignHtml =
            '<div class="rc green panel-reveal"><div class="rc-title">Summer Admission Campaign</div>' +
            '<div class="rr"><span class="rl">Regular Program Cost:</span><span class="rv">' + fmtBDT(c.TOTAL_PROGRAM_COST) + '</span></div>' +
            '<div class="rr highlight"><span class="rl">Summer Campaign Fixed Waiver:</span><span class="rv"><strong>- ' + fmtBDT(c.CAMPAIGN_WAIVER) + '</strong></span></div>' +
            '<div class="rr total"><span class="rl">Total After Waiver:</span><span class="rv">' + fmtBDT(c.TOTAL_AFTER_WAIVER) + '</span></div>' +
            '<div class="rr"><span class="rl">Monthly Fixed Fees:</span><span class="rv">' + fmtBDT(c.MONTHLY_FIXED) + '</span></div>' +
            '</div>';
    }

    var noWaiver        = c.CAMPAIGN_WAIVER === undefined;
    var fourthTileLabel = dual ? 'From (After Waiver)' : (noWaiver ? 'Monthly Fixed' : 'After Waiver');
    var fourthTileValue = dual
        ? fmtBDT(c.INTERNAL_FINAL)
        : (noWaiver ? fmtBDT(c.MONTHLY_FIXED) : fmtBDT(c.TOTAL_AFTER_WAIVER));

    return '' +
        '<div class="rc panel-reveal">' +
        '<div class="rc-title">Program Overview – ' + progName + '</div>' +
        '<div class="sum-grid">' +
        '<div class="sum-tile"><div class="tl">Total Credits</div><div class="tv">' + c.TOTAL_CREDITS + '</div></div>' +
        '<div class="sum-tile"><div class="tl">Duration</div><div class="tv">' + durText + '</div></div>' +
        '<div class="sum-tile"><div class="tl">Program Cost</div><div class="tv">' + fmtBDT(c.TOTAL_PROGRAM_COST) + '</div></div>' +
        '<div class="sum-tile"><div class="tl">' + fourthTileLabel + '</div><div class="tv">' + fourthTileValue + '</div></div>' +
        '</div></div>' +
        '<div class="rc panel-reveal"><div class="rc-title">Regular Program Cost</div>' +
        '<div class="rr"><span class="rl">Tuition Fee:</span><span class="rv">' + fmtBDT(c.TUITION_FULL) + '</span></div>' +
        '<div class="rr"><span class="rl">Admission Fee:</span><span class="rv">' + fmtBDT(c.ADMISSION_FEE) + '</span></div>' +
        '<div class="rr"><span class="rl">Registration Fee (' + c.TOTAL_SEMESTERS + ' Semesters):</span><span class="rv">' + fmtBDT(c.REGISTRATION_FEE) + '</span></div>' +
        '<div class="rr"><span class="rl">Institutional Fees:</span><span class="rv">' + fmtBDT(c.INSTITUTIONAL_FEES) + '</span></div>' +
        '<div class="rr total"><span class="rl">Regular Program Cost:</span><span class="rv">' + fmtBDT(c.TOTAL_PROGRAM_COST) + '</span></div>' +
        '</div>' +
        campaignHtml + admHtml;
}
</script>
</body>
</html>
