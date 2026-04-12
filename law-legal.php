<?php
require_once __DIR__ . '/includes/config.php';

/* ── Load settings ───────────────────────────────────────────────────────── */
$s = [];
try {
    $db = front_db();
    if ($db) {
        $rows = $db->query('SELECT setting_key, setting_val FROM ll_settings')->fetchAll();
        foreach ($rows as $r) $s[$r['setting_key']] = $r['setting_val'];
    }
} catch (Throwable $e) {}

function lls(array $s, string $key, string $default = ''): string {
    return isset($s[$key]) && $s[$key] !== '' ? $s[$key] : $default;
}

/* ── Seeded defaults (shown when DB migration has not been run yet) ────────── */
$s += [
    'adviser_name'      => 'Md. Ashraf Ali',
    'adviser_title'     => 'Adviser',
    'adviser_bio'       => 'Advocate, District & Session Judge Court, Dhaka.',
    'adviser_email_1'   => 'md.aliashraf45@gmail.com',
    'assistant_name'    => 'Md. Yasin',
    'assistant_title'   => 'Assistant Adviser (Legal & Estate)',
    'assistant_email_1' => 'adv.yasin@primeuniversity.ac.bd',
    'assistant_phone'   => '01705-502190',
];

/* ── Redirect if unpublished ─────────────────────────────────────────────── */
if (lls($s, 'is_published', '1') !== '1') {
    header('Location: /index.php');
    exit;
}

$page_title = lls($s, 'hero_title', 'Law & Legal Affairs') . ' – Prime University';
$meta_desc  = lls($s, 'meta_description', 'Law & Legal Affairs – Prime University. Expert legal counsel and estate management.');

/* ── Message paragraphs ──────────────────────────────────────────────────── */
$message_paragraphs = [];
$raw_msg = lls($s, 'message_body', '');
if ($raw_msg !== '') {
    $message_paragraphs = array_filter(
        array_map('trim', preg_split('/\n{2,}/', $raw_msg)),
        fn($p) => $p !== ''
    );
}

/* ── Photo URLs ──────────────────────────────────────────────────────────── */
$adviser_photo_url   = !empty($s['adviser_photo'])
    ? ADMIN_UPLOAD_URL . '/law-legal/' . $s['adviser_photo'] : '';
$assistant_photo_url = !empty($s['assistant_photo'])
    ? ADMIN_UPLOAD_URL . '/law-legal/' . $s['assistant_photo'] : '';

/* ── Load staff, notices, services ──────────────────────────────────────── */
$staff    = [];
$notices  = [];
$services = [];
try {
    $db = front_db();
    if ($db) {
        $staff = $db->query(
            'SELECT * FROM ll_staff WHERE is_active = 1 ORDER BY sort_order ASC, name ASC'
        )->fetchAll();
        $notices = $db->query(
            'SELECT * FROM ll_notices WHERE is_active = 1 ORDER BY notice_date DESC, sort_order ASC, id DESC LIMIT 12'
        )->fetchAll();
        $services = $db->query(
            'SELECT * FROM ll_services WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
        )->fetchAll();
    }
} catch (Throwable $e) {}
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title><?= fh($page_title) ?></title>
   <meta name="description" content="<?= fh($meta_desc) ?>">
   <meta name="viewport" content="width=device-width, initial-scale=1">
   <meta property="og:title" content="<?= fh($page_title) ?>">
   <meta property="og:description" content="<?= fh($meta_desc) ?>">
   <meta property="og:type" content="website">

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
/* ════════════════════════════════════════════════════════════════════════════
   LAW & LEGAL AFFAIRS – PAGE STYLES
   ════════════════════════════════════════════════════════════════════════════ */
:root {
  --ll-navy:    #0f2044;
  --ll-gold:    #c8971a;
  --ll-blue:    #1e4fa8;
  --ll-accent:  #1a5276;
  --ll-light:   #f0f4f8;
  --ll-text:    #1e293b;
  --ll-muted:   #64748b;
  --ll-white:   #ffffff;
  --ll-radius:  16px;
  --ll-shadow:  0 8px 40px rgba(15,32,68,.11);
  --ll-shadow-h:0 18px 60px rgba(15,32,68,.20);
  --ll-trans:   .35s cubic-bezier(.4,0,.2,1);
}

/* ── Hero ─────────────────────────────────────────────────────────────────── */
.ll-hero {
  background: linear-gradient(135deg, #070e1a 0%, #0f2044 45%, #1e4fa8 100%);
  padding: 110px 0 96px;
  position: relative;
  overflow: hidden;
}
.ll-hero::before {
  content:'';
  position:absolute;inset:0;pointer-events:none;
  background:
    radial-gradient(ellipse 55% 75% at 85% 50%, rgba(200,151,26,.12) 0%, transparent 70%),
    radial-gradient(ellipse 45% 65% at 5%  80%, rgba(30,79,168,.30) 0%, transparent 60%);
}
.ll-hero .ll-orb {
  position:absolute;border-radius:50%;pointer-events:none;
  animation: llFloat 10s ease-in-out infinite;
}
.ll-hero .ll-orb.o1 { width:420px;height:420px;background:rgba(200,151,26,.07);top:-80px;right:-60px;animation-delay:0s; }
.ll-hero .ll-orb.o2 { width:180px;height:180px;background:rgba(255,255,255,.05);bottom:30px;left:5%;animation-delay:3.5s; }
.ll-hero .ll-orb.o3 { width:100px;height:100px;background:rgba(200,151,26,.12);top:38%;right:25%;animation-delay:1.8s; }
.ll-hero .ll-orb.o4 { width:60px;height:60px;background:rgba(255,255,255,.08);top:22%;left:20%;animation-delay:5s; }
@keyframes llFloat {
  0%,100%{ transform:translateY(0) scale(1); }
  50%     { transform:translateY(-20px) scale(1.04); }
}

.ll-hero .ll-breadcrumb {
  display:flex;align-items:center;gap:8px;
  font-size:.8rem;font-weight:600;letter-spacing:.05em;text-transform:uppercase;
  color:rgba(255,255,255,.6);margin-bottom:22px;
}
.ll-hero .ll-breadcrumb a { color:var(--ll-gold);text-decoration:none;transition:color .2s; }
.ll-hero .ll-breadcrumb a:hover { color:#fff; }
.ll-hero .ll-breadcrumb .sep { color:rgba(255,255,255,.3); }

.ll-hero-tag {
  display:inline-flex;align-items:center;gap:8px;
  background:rgba(200,151,26,.18);color:var(--ll-gold);
  font-size:.75rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;
  padding:7px 18px;border-radius:50px;border:1px solid rgba(200,151,26,.4);margin-bottom:20px;
}
.ll-hero-tag .dot {
  width:7px;height:7px;border-radius:50%;background:var(--ll-gold);
  animation:llPulse 2s ease-in-out infinite;
}
@keyframes llPulse { 0%,100%{opacity:1;transform:scale(1);} 50%{opacity:.5;transform:scale(1.7);} }

.ll-hero h1 {
  font-size:clamp(2.1rem,5vw,3.5rem);
  font-weight:800;color:#fff;line-height:1.13;margin-bottom:18px;
}
.ll-hero h1 .accent { color:var(--ll-gold); }
.ll-hero-subtitle {
  font-size:clamp(.95rem,2vw,1.12rem);
  color:rgba(255,255,255,.72);line-height:1.65;max-width:620px;margin-bottom:32px;
}
.ll-hero-stat {
  display:inline-flex;align-items:center;gap:9px;
  background:rgba(255,255,255,.09);
  border:1px solid rgba(255,255,255,.14);
  backdrop-filter:blur(8px);
  border-radius:12px;padding:10px 20px;
  color:#fff;font-size:.85rem;font-weight:600;
  margin-right:10px;margin-bottom:10px;
  transition:background var(--ll-trans);
}
.ll-hero-stat:hover { background:rgba(255,255,255,.16); }
.ll-hero-stat i { color:var(--ll-gold);font-size:1rem; }

/* ── Scroll-fade utility ─────────────────────────────────────────────────── */
.ll-fade {
  transition:opacity .65s ease,transform .65s ease;
}
.ll-fade.ll-hidden { opacity:0;transform:translateY(28px); }
.ll-fade.visible { opacity:1;transform:translateY(0); }
.ll-fade-d1 { transition-delay:.1s; }
.ll-fade-d2 { transition-delay:.2s; }
.ll-fade-d3 { transition-delay:.3s; }
.ll-fade-d4 { transition-delay:.4s; }

/* ── Section helpers ─────────────────────────────────────────────────────── */
.ll-section { padding:80px 0; }
.ll-section-alt { background:var(--ll-light); }
.ll-section-dark { background:var(--ll-navy);color:#fff; }

.ll-section-tag {
  display:inline-flex;align-items:center;gap:8px;
  background:rgba(30,79,168,.10);color:var(--ll-blue);
  font-size:.72rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;
  padding:5px 14px;border-radius:50px;border:1px solid rgba(30,79,168,.18);
  margin-bottom:14px;
}
.ll-section-tag .dot-sm {
  width:6px;height:6px;border-radius:50%;background:var(--ll-blue);
}
.ll-section-dark .ll-section-tag {
  background:rgba(200,151,26,.18);color:var(--ll-gold);border-color:rgba(200,151,26,.35);
}
.ll-section-dark .ll-section-tag .dot-sm { background:var(--ll-gold); }

.ll-section-title {
  font-size:clamp(1.65rem,3.5vw,2.3rem);
  font-weight:800;color:var(--ll-navy);line-height:1.2;margin-bottom:10px;
}
.ll-section-dark .ll-section-title { color:#fff; }

.ll-divider {
  width:54px;height:4px;border-radius:2px;
  background:linear-gradient(90deg,var(--ll-blue),var(--ll-gold));
  margin-top:14px;
}

/* ── Profile card (adviser, assistant) ───────────────────────────────────── */
.ll-profile-card {
  background:#fff;border-radius:var(--ll-radius);
  box-shadow:var(--ll-shadow);overflow:hidden;
  transition:transform var(--ll-trans),box-shadow var(--ll-trans);
  position:relative;
}
.ll-profile-card:hover {
  transform:translateY(-5px);
  box-shadow:var(--ll-shadow-h);
}
.ll-profile-accent {
  height:5px;
  background:linear-gradient(90deg, var(--ll-navy), var(--ll-blue), var(--ll-gold));
}
.ll-profile-body { padding:36px 32px; }

.ll-profile-photo-wrap {
  position:relative;width:120px;height:120px;
  margin:0 auto 20px;flex-shrink:0;
}
.ll-profile-photo-wrap img {
  width:120px;height:120px;border-radius:50%;object-fit:cover;
  border:4px solid var(--ll-white);
  box-shadow:0 4px 24px rgba(15,32,68,.18);
}
.ll-profile-placeholder {
  width:120px;height:120px;border-radius:50%;
  background:linear-gradient(135deg, var(--ll-navy), var(--ll-blue));
  display:flex;align-items:center;justify-content:center;
  border:4px solid rgba(255,255,255,.4);
  box-shadow:0 4px 24px rgba(15,32,68,.18);
}
.ll-profile-placeholder i { color:#fff;font-size:2.4rem; }
.ll-profile-badge {
  position:absolute;bottom:4px;right:4px;
  width:30px;height:30px;border-radius:50%;
  background:linear-gradient(135deg,var(--ll-gold),#e8b43a);
  display:flex;align-items:center;justify-content:center;
  box-shadow:0 2px 8px rgba(200,151,26,.4);border:2px solid #fff;
}
.ll-profile-badge i { color:#fff;font-size:.7rem; }

.ll-profile-name {
  font-size:1.22rem;font-weight:800;color:var(--ll-navy);
  margin-bottom:4px;text-align:center;
}
.ll-profile-title {
  font-size:.82rem;font-weight:600;color:var(--ll-blue);
  text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px;text-align:center;
}
.ll-profile-sub {
  font-size:.82rem;color:var(--ll-muted);margin-bottom:18px;text-align:center;line-height:1.5;
}

.ll-contact-list {
  list-style:none;padding:0;margin:0 0 0 0;display:flex;flex-direction:column;gap:10px;
}
.ll-contact-list li {
  display:flex;align-items:flex-start;gap:10px;
  font-size:.85rem;color:var(--ll-text);
}
.ll-contact-icon {
  width:30px;height:30px;border-radius:8px;
  background:linear-gradient(135deg, rgba(30,79,168,.12), rgba(30,79,168,.06));
  display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.ll-contact-icon i { color:var(--ll-blue);font-size:.8rem; }
.ll-contact-list a { color:var(--ll-blue);text-decoration:none;font-weight:500; }
.ll-contact-list a:hover { text-decoration:underline; }

.ll-label {
  font-size:.7rem;color:var(--ll-muted);text-transform:uppercase;
  letter-spacing:.06em;font-weight:600;display:block;margin-bottom:1px;
}

/* ── Services grid ───────────────────────────────────────────────────────── */
.ll-service-card {
  background:#fff;border-radius:var(--ll-radius);
  box-shadow:0 4px 20px rgba(15,32,68,.07);
  padding:32px 26px;
  transition:transform var(--ll-trans),box-shadow var(--ll-trans),border-color var(--ll-trans);
  border:1px solid rgba(30,79,168,.08);
  position:relative;overflow:hidden;height:100%;
}
.ll-service-card::before {
  content:'';position:absolute;top:0;left:0;right:0;
  height:3px;
  background:linear-gradient(90deg, var(--ll-navy), var(--ll-blue));
  transform:scaleX(0);transform-origin:left;
  transition:transform var(--ll-trans);
}
.ll-service-card:hover::before { transform:scaleX(1); }
.ll-service-card:hover {
  transform:translateY(-6px);
  box-shadow:0 16px 48px rgba(15,32,68,.13);
  border-color:rgba(30,79,168,.15);
}
.ll-service-icon {
  width:58px;height:58px;border-radius:14px;
  background:linear-gradient(135deg, var(--ll-navy) 0%, var(--ll-blue) 100%);
  display:flex;align-items:center;justify-content:center;
  margin-bottom:18px;
  box-shadow:0 6px 20px rgba(30,79,168,.25);
  transition:transform var(--ll-trans),box-shadow var(--ll-trans);
}
.ll-service-card:hover .ll-service-icon {
  transform:scale(1.10);
  box-shadow:0 10px 30px rgba(30,79,168,.35);
}
.ll-service-icon i { color:#fff;font-size:1.4rem; }
.ll-service-title {
  font-size:1rem;font-weight:700;color:var(--ll-navy);margin-bottom:10px;
}
.ll-service-desc {
  font-size:.87rem;color:var(--ll-muted);line-height:1.65;
}

/* ── Staff cards ──────────────────────────────────────────────────────────── */
.ll-staff-card {
  background:#fff;border-radius:var(--ll-radius);
  box-shadow:0 4px 20px rgba(15,32,68,.07);
  padding:28px 22px 24px;text-align:center;
  transition:transform var(--ll-trans),box-shadow var(--ll-trans);
  border:1px solid rgba(30,79,168,.07);height:100%;
}
.ll-staff-card:hover {
  transform:translateY(-5px);
  box-shadow:0 14px 44px rgba(15,32,68,.14);
}
.ll-staff-photo {
  width:88px;height:88px;border-radius:50%;object-fit:cover;
  border:3px solid var(--ll-white);
  box-shadow:0 3px 14px rgba(15,32,68,.16);
  margin:0 auto 14px;display:block;
}
.ll-staff-placeholder {
  width:88px;height:88px;border-radius:50%;
  background:linear-gradient(135deg, var(--ll-navy), var(--ll-blue));
  display:flex;align-items:center;justify-content:center;
  margin:0 auto 14px;
  box-shadow:0 3px 14px rgba(15,32,68,.16);
}
.ll-staff-placeholder i { color:#fff;font-size:1.8rem; }
.ll-staff-name {
  font-size:.97rem;font-weight:700;color:var(--ll-navy);margin-bottom:3px;
}
.ll-staff-designation {
  font-size:.78rem;color:var(--ll-blue);font-weight:600;
  text-transform:uppercase;letter-spacing:.06em;margin-bottom:12px;
}
.ll-staff-contact {
  font-size:.82rem;color:var(--ll-muted);
}
.ll-staff-contact a { color:var(--ll-blue);text-decoration:none; }
.ll-staff-contact a:hover { text-decoration:underline; }

/* ── Notice board ────────────────────────────────────────────────────────── */
.ll-notice-item {
  display:flex;align-items:flex-start;gap:14px;
  padding:18px 22px;
  background:#fff;border-radius:12px;
  box-shadow:0 2px 12px rgba(15,32,68,.06);
  border:1px solid rgba(30,79,168,.07);
  transition:transform var(--ll-trans),box-shadow var(--ll-trans),border-color var(--ll-trans);
  margin-bottom:14px;
}
.ll-notice-item:hover {
  transform:translateX(4px);
  box-shadow:0 6px 24px rgba(15,32,68,.12);
  border-color:rgba(30,79,168,.18);
}
.ll-notice-icon {
  width:40px;height:40px;border-radius:10px;
  display:flex;align-items:center;justify-content:center;flex-shrink:0;
  margin-top:2px;
}
.ll-notice-icon.cat-notice       { background:rgba(30,79,168,.12); }
.ll-notice-icon.cat-notice i     { color:var(--ll-blue); }
.ll-notice-icon.cat-circular     { background:rgba(209,16,52,.10); }
.ll-notice-icon.cat-circular i   { color:#D21034; }
.ll-notice-icon.cat-policy       { background:rgba(5,150,105,.10); }
.ll-notice-icon.cat-policy i     { color:#059669; }
.ll-notice-icon.cat-announcement { background:rgba(200,151,26,.14); }
.ll-notice-icon.cat-announcement i { color:var(--ll-gold); }

.ll-notice-title {
  font-size:.92rem;font-weight:600;color:var(--ll-text);
  margin-bottom:5px;line-height:1.45;
}
.ll-notice-meta {
  display:flex;align-items:center;gap:10px;flex-wrap:wrap;
  font-size:.77rem;color:var(--ll-muted);
}
.ll-notice-badge {
  display:inline-block;padding:2px 10px;border-radius:20px;
  font-size:.72rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;
}
.ll-notice-badge.cat-notice       { background:#dbeafe;color:#1e40af; }
.ll-notice-badge.cat-circular     { background:#fce7f3;color:#9d174d; }
.ll-notice-badge.cat-policy       { background:#d1fae5;color:#065f46; }
.ll-notice-badge.cat-announcement { background:#fef3c7;color:#92400e; }

/* ── Message section ─────────────────────────────────────────────────────── */
.ll-message-section { background:#fff; }
.ll-message-card {
  background:linear-gradient(150deg, var(--ll-navy) 0%, #1a3a6e 100%);
  border-radius:var(--ll-radius);
  box-shadow:var(--ll-shadow-h);
  overflow:hidden;position:relative;padding:52px 48px;
  color:#fff;
}
.ll-message-card::before {
  content:'';position:absolute;top:-80px;right:-80px;
  width:320px;height:320px;border-radius:50%;
  background:rgba(200,151,26,.08);pointer-events:none;
}
.ll-message-card::after {
  content:'';position:absolute;bottom:-60px;left:-60px;
  width:200px;height:200px;border-radius:50%;
  background:rgba(255,255,255,.04);pointer-events:none;
}
.ll-message-quote-icon {
  font-size:3.5rem;color:var(--ll-gold);opacity:.85;line-height:1;
  margin-bottom:20px;display:block;
}
.ll-message-title {
  font-size:1.5rem;font-weight:800;color:#fff;margin-bottom:26px;
  padding-bottom:16px;border-bottom:1px solid rgba(255,255,255,.12);
}
.ll-message-body p {
  font-size:.97rem;color:rgba(255,255,255,.85);line-height:1.85;margin-bottom:18px;
}
.ll-message-sig {
  margin-top:32px;padding-top:20px;border-top:1px solid rgba(255,255,255,.12);
  display:flex;align-items:center;gap:14px;
}
.ll-message-sig-photo {
  width:52px;height:52px;border-radius:50%;object-fit:cover;
  border:2px solid rgba(200,151,26,.5);flex-shrink:0;
}
.ll-message-sig-placeholder {
  width:52px;height:52px;border-radius:50%;
  background:rgba(255,255,255,.15);
  display:flex;align-items:center;justify-content:center;flex-shrink:0;
  border:2px solid rgba(200,151,26,.35);
}
.ll-message-sig-placeholder i { color:rgba(255,255,255,.7);font-size:1.1rem; }
.ll-message-sig-name  { font-weight:700;color:#fff;font-size:.95rem; }
.ll-message-sig-title { font-size:.78rem;color:var(--ll-gold);font-weight:600; }

/* ── Stats bar ───────────────────────────────────────────────────────────── */
.ll-stats-bar {
  background:linear-gradient(135deg, var(--ll-navy) 0%, var(--ll-blue) 100%);
  padding:50px 0;
}
.ll-stat-item { text-align:center;padding:0 16px; }
.ll-stat-num {
  font-size:clamp(1.9rem,4vw,2.8rem);font-weight:900;
  color:var(--ll-gold);line-height:1;margin-bottom:8px;
}
.ll-stat-lbl {
  font-size:.82rem;font-weight:600;color:rgba(255,255,255,.75);
  text-transform:uppercase;letter-spacing:.08em;
}

/* ── Responsive tweaks ───────────────────────────────────────────────────── */
@media (max-width:767px) {
  .ll-hero { padding:80px 0 72px; }
  .ll-section { padding:56px 0; }
  .ll-profile-body { padding:24px 20px; }
  .ll-message-card { padding:32px 24px; }
}
@media (max-width:575px) {
  .ll-hero-stat { width:100%;justify-content:center; }
}
</style>
</head>
<body>
<?php include __DIR__ . '/includes/header-top.php'; ?>
<?php include __DIR__ . '/includes/nav-menu.php'; ?>

<!-- ══════════════════════════════════════════════════════════════════════════
     HERO
══════════════════════════════════════════════════════════════════════════ -->
<section class="ll-hero">
    <div class="ll-orb o1"></div>
    <div class="ll-orb o2"></div>
    <div class="ll-orb o3"></div>
    <div class="ll-orb o4"></div>

    <div class="container position-relative">
        <div class="ll-breadcrumb">
            <a href="/index.php">Home</a>
            <span class="sep">/</span>
            <span><?= fh(lls($s, 'hero_title', 'Law &amp; Legal Affairs')) ?></span>
        </div>

        <div class="row align-items-center g-5">
            <div class="col-lg-7">
                <div class="ll-hero-tag">
                    <span class="dot"></span>
                    Prime University
                </div>
                <h1>
                    <?php
                        $ht = fh(lls($s, 'hero_title', 'Law & Legal Affairs'));
                        // Highlight "Legal" in gold
                        echo preg_replace('/\b(Legal)\b/i', '<span class="accent">$1</span>', $ht);
                    ?>
                </h1>
                <p class="ll-hero-subtitle">
                    <?= fh(lls($s, 'hero_subtitle', 'Legal Counsel & Estate Management – Prime University')) ?>
                </p>
                <?php if (lls($s, 'hero_intro', '') !== ''): ?>
                <p class="ll-hero-subtitle" style="opacity:.65;font-size:.93rem;">
                    <?= fh(lls($s, 'hero_intro', '')) ?>
                </p>
                <?php endif; ?>
                <div class="d-flex flex-wrap mt-4">
                    <span class="ll-hero-stat"><i class="fas fa-gavel"></i> Expert Legal Counsel</span>
                    <span class="ll-hero-stat"><i class="fas fa-building"></i> Estate Management</span>
                    <span class="ll-hero-stat"><i class="fas fa-shield-alt"></i> Regulatory Compliance</span>
                </div>
            </div>
            <div class="col-lg-5 text-center d-none d-lg-flex justify-content-center">
                <div style="position:relative;display:inline-block;">
                    <div style="width:240px;height:240px;border-radius:50%;background:rgba(200,151,26,.13);border:2px solid rgba(200,151,26,.3);display:flex;align-items:center;justify-content:center;box-shadow:0 20px 60px rgba(15,32,68,.35);">
                        <div style="width:180px;height:180px;border-radius:50%;background:rgba(255,255,255,.07);display:flex;align-items:center;justify-content:center;">
                            <i class="fas fa-balance-scale" style="font-size:5rem;color:var(--ll-gold);opacity:.9;"></i>
                        </div>
                    </div>
                    <div style="position:absolute;top:-10px;right:-10px;width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,#c8971a,#e8b43a);display:flex;align-items:center;justify-content:center;box-shadow:0 6px 20px rgba(200,151,26,.4);">
                        <i class="fas fa-gavel" style="color:#fff;font-size:1.2rem;"></i>
                    </div>
                    <div style="position:absolute;bottom:-8px;left:-8px;width:44px;height:44px;border-radius:50%;background:rgba(30,79,168,.7);display:flex;align-items:center;justify-content:center;box-shadow:0 4px 16px rgba(30,79,168,.5);border:2px solid rgba(255,255,255,.2);">
                        <i class="fas fa-file-contract" style="color:#fff;font-size:.9rem;"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════════════════════════════════
     STATS BAR
══════════════════════════════════════════════════════════════════════════ -->
<div class="ll-stats-bar">
    <div class="container">
        <div class="row g-4 justify-content-center text-center">
            <div class="col-6 col-md-3">
                <div class="ll-stat-item ll-fade">
                    <div class="ll-stat-num"><?= count($services) ?: '6' ?>+</div>
                    <div class="ll-stat-lbl">Legal Services</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="ll-stat-item ll-fade ll-fade-d1">
                    <div class="ll-stat-num"><?= max(count($staff) + 2, 2) ?>+</div>
                    <div class="ll-stat-lbl">Legal Officers</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="ll-stat-item ll-fade ll-fade-d2">
                    <div class="ll-stat-num">24/7</div>
                    <div class="ll-stat-lbl">Advisory Support</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="ll-stat-item ll-fade ll-fade-d3">
                    <div class="ll-stat-num">100%</div>
                    <div class="ll-stat-lbl">Compliance Focus</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     ADVISER PROFILE
══════════════════════════════════════════════════════════════════════════ -->
<section class="ll-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-xl-10 col-lg-11">
                <div class="text-center mb-5 ll-fade">
                    <div class="ll-section-tag"><span class="dot-sm"></span>Legal Leadership</div>
                    <h2 class="ll-section-title">Office Bearers</h2>
                    <div class="ll-divider mx-auto"></div>
                </div>

                <div class="row g-4 justify-content-center">

                    <!-- Adviser -->
                    <div class="col-lg-6 col-md-10">
                        <div class="ll-profile-card ll-fade ll-fade-d1">
                            <div class="ll-profile-accent"></div>
                            <div class="ll-profile-body">
                                <div class="text-center mb-3">
                                    <div class="ll-profile-photo-wrap">
                                        <?php if ($adviser_photo_url): ?>
                                        <img src="<?= fh($adviser_photo_url) ?>"
                                             alt="<?= fh(lls($s,'adviser_name','Adviser')) ?>">
                                        <?php else: ?>
                                        <div class="ll-profile-placeholder">
                                            <i class="fas fa-user-tie"></i>
                                        </div>
                                        <?php endif; ?>
                                        <div class="ll-profile-badge">
                                            <i class="fas fa-star"></i>
                                        </div>
                                    </div>
                                    <div class="ll-profile-name"><?= fh(lls($s,'adviser_name','Md. Ashraf Ali')) ?></div>
                                    <div class="ll-profile-title"><?= fh(lls($s,'adviser_title','Adviser')) ?></div>
                                    <?php if (lls($s,'adviser_bio','') !== ''): ?>
                                    <div class="ll-profile-sub"><?= fh(lls($s,'adviser_bio','')) ?></div>
                                    <?php endif; ?>
                                </div>
                                <ul class="ll-contact-list">
                                    <?php if (lls($s,'adviser_email_1','') !== ''): ?>
                                    <li>
                                        <div class="ll-contact-icon"><i class="fas fa-envelope"></i></div>
                                        <div>
                                            <span class="ll-label">Email</span>
                                            <a href="mailto:<?= fh(lls($s,'adviser_email_1','')) ?>"><?= fh(lls($s,'adviser_email_1','')) ?></a>
                                            <?php if (lls($s,'adviser_email_2','') !== ''): ?>
                                            &nbsp;&middot;&nbsp;
                                            <a href="mailto:<?= fh(lls($s,'adviser_email_2','')) ?>"><?= fh(lls($s,'adviser_email_2','')) ?></a>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                    <?php endif; ?>
                                    <?php if (lls($s,'adviser_phone','') !== ''): ?>
                                    <li>
                                        <div class="ll-contact-icon"><i class="fas fa-phone"></i></div>
                                        <div>
                                            <span class="ll-label">Phone</span>
                                            <a href="tel:<?= fh(lls($s,'adviser_phone','')) ?>"><?= fh(lls($s,'adviser_phone','')) ?></a>
                                        </div>
                                    </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- Assistant Adviser -->
                    <div class="col-lg-6 col-md-10">
                        <div class="ll-profile-card ll-fade ll-fade-d2">
                            <div class="ll-profile-accent" style="background:linear-gradient(90deg,var(--ll-blue),var(--ll-gold),var(--ll-blue));"></div>
                            <div class="ll-profile-body">
                                <div class="text-center mb-3">
                                    <div class="ll-profile-photo-wrap">
                                        <?php if ($assistant_photo_url): ?>
                                        <img src="<?= fh($assistant_photo_url) ?>"
                                             alt="<?= fh(lls($s,'assistant_name','Assistant Adviser')) ?>">
                                        <?php else: ?>
                                        <div class="ll-profile-placeholder" style="background:linear-gradient(135deg,var(--ll-blue),#3b73e8);">
                                            <i class="fas fa-user"></i>
                                        </div>
                                        <?php endif; ?>
                                        <div class="ll-profile-badge" style="background:linear-gradient(135deg,var(--ll-blue),#3b73e8);">
                                            <i class="fas fa-id-badge"></i>
                                        </div>
                                    </div>
                                    <div class="ll-profile-name"><?= fh(lls($s,'assistant_name','Md. Yasin')) ?></div>
                                    <div class="ll-profile-title" style="color:var(--ll-blue);"><?= fh(lls($s,'assistant_title','Assistant Adviser (Legal & Estate)')) ?></div>
                                    <?php if (lls($s,'assistant_bio','') !== ''): ?>
                                    <div class="ll-profile-sub"><?= fh(lls($s,'assistant_bio','')) ?></div>
                                    <?php endif; ?>
                                </div>
                                <ul class="ll-contact-list">
                                    <?php if (lls($s,'assistant_email_1','') !== ''): ?>
                                    <li>
                                        <div class="ll-contact-icon"><i class="fas fa-envelope"></i></div>
                                        <div>
                                            <span class="ll-label">Email</span>
                                            <a href="mailto:<?= fh(lls($s,'assistant_email_1','')) ?>"><?= fh(lls($s,'assistant_email_1','')) ?></a>
                                            <?php if (lls($s,'assistant_email_2','') !== ''): ?>
                                            &nbsp;&middot;&nbsp;
                                            <a href="mailto:<?= fh(lls($s,'assistant_email_2','')) ?>"><?= fh(lls($s,'assistant_email_2','')) ?></a>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                    <?php endif; ?>
                                    <?php if (lls($s,'assistant_phone','') !== ''): ?>
                                    <li>
                                        <div class="ll-contact-icon"><i class="fas fa-phone"></i></div>
                                        <div>
                                            <span class="ll-label">Phone</span>
                                            <a href="tel:<?= fh(lls($s,'assistant_phone','')) ?>"><?= fh(lls($s,'assistant_phone','')) ?></a>
                                        </div>
                                    </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════════════════════════════════
     LEGAL SERVICES
══════════════════════════════════════════════════════════════════════════ -->
<?php if (!empty($services)): ?>
<section class="ll-section ll-section-alt">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-xl-10 col-lg-11">
                <div class="text-center mb-5 ll-fade">
                    <div class="ll-section-tag"><span class="dot-sm"></span>What We Do</div>
                    <h2 class="ll-section-title">Legal Services</h2>
                    <div class="ll-divider mx-auto"></div>
                    <p style="color:var(--ll-muted);margin-top:16px;font-size:.95rem;max-width:520px;margin-left:auto;margin-right:auto;">
                        Comprehensive legal support to safeguard the university's interests and ensure full regulatory compliance.
                    </p>
                </div>
                <div class="row g-4">
                    <?php foreach ($services as $i => $svc): ?>
                    <?php $dc = 'll-fade-d' . (($i % 4) + 1); ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="ll-service-card ll-fade <?= $dc ?>">
                            <div class="ll-service-icon">
                                <i class="<?= h($svc['icon']) ?>"></i>
                            </div>
                            <div class="ll-service-title"><?= fh($svc['title']) ?></div>
                            <?php if (!empty($svc['description'])): ?>
                            <div class="ll-service-desc"><?= fh($svc['description']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════════════════
     MESSAGE
══════════════════════════════════════════════════════════════════════════ -->
<?php if (!empty($message_paragraphs)): ?>
<section class="ll-section ll-message-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-xl-10 col-lg-11">
                <div class="text-center mb-5 ll-fade">
                    <div class="ll-section-tag"><span class="dot-sm"></span>From the Desk</div>
                    <h2 class="ll-section-title"><?= fh(lls($s,'message_title','Message from the Adviser')) ?></h2>
                    <div class="ll-divider mx-auto"></div>
                </div>
                <div class="ll-message-card ll-fade">
                    <span class="ll-message-quote-icon"><i class="fas fa-quote-left"></i></span>
                    <h3 class="ll-message-title"><?= fh(lls($s,'message_title','Message from the Adviser')) ?></h3>
                    <div class="ll-message-body">
                        <?php foreach ($message_paragraphs as $para): ?>
                        <p><?= fh($para) ?></p>
                        <?php endforeach; ?>
                    </div>
                    <div class="ll-message-sig">
                        <?php if ($adviser_photo_url): ?>
                        <img src="<?= fh($adviser_photo_url) ?>" class="ll-message-sig-photo"
                             alt="<?= fh(lls($s,'adviser_name','Adviser')) ?>">
                        <?php else: ?>
                        <div class="ll-message-sig-placeholder"><i class="fas fa-user-tie"></i></div>
                        <?php endif; ?>
                        <div>
                            <div class="ll-message-sig-name"><?= fh(lls($s,'adviser_name','Md. Ashraf Ali')) ?></div>
                            <div class="ll-message-sig-title"><?= fh(lls($s,'adviser_title','Adviser')) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════════════════
     LEGAL STAFF
══════════════════════════════════════════════════════════════════════════ -->
<?php if (!empty($staff)): ?>
<section class="ll-section ll-section-alt">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-xl-10 col-lg-11">
                <div class="text-center mb-5 ll-fade">
                    <div class="ll-section-tag"><span class="dot-sm"></span>Our Team</div>
                    <h2 class="ll-section-title">Legal Team</h2>
                    <div class="ll-divider mx-auto"></div>
                </div>
                <div class="row g-4 justify-content-center">
                    <?php foreach ($staff as $i => $m): ?>
                    <?php $dc = 'll-fade-d' . (($i % 4) + 1); ?>
                    <?php $ph_url = !empty($m['photo']) ? ADMIN_UPLOAD_URL . '/law-legal/' . $m['photo'] : ''; ?>
                    <div class="col-sm-6 col-md-4 col-lg-3">
                        <div class="ll-staff-card ll-fade <?= $dc ?>">
                            <?php if ($ph_url): ?>
                            <img src="<?= fh($ph_url) ?>" class="ll-staff-photo"
                                 alt="<?= fh($m['name']) ?>">
                            <?php else: ?>
                            <div class="ll-staff-placeholder">
                                <i class="fas fa-user"></i>
                            </div>
                            <?php endif; ?>
                            <div class="ll-staff-name"><?= fh($m['name']) ?></div>
                            <?php if (!empty($m['designation'])): ?>
                            <div class="ll-staff-designation"><?= fh($m['designation']) ?></div>
                            <?php endif; ?>
                            <div class="ll-staff-contact">
                                <?php if (!empty($m['email'])): ?>
                                <div><a href="mailto:<?= fh($m['email']) ?>"><i class="fas fa-envelope me-1" style="font-size:.75rem;"></i><?= fh($m['email']) ?></a></div>
                                <?php endif; ?>
                                <?php if (!empty($m['phone'])): ?>
                                <div class="mt-1"><a href="tel:<?= fh($m['phone']) ?>"><i class="fas fa-phone me-1" style="font-size:.75rem;"></i><?= fh($m['phone']) ?></a></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════════════════
     NOTICES & CIRCULARS
══════════════════════════════════════════════════════════════════════════ -->
<?php if (!empty($notices)): ?>
<section class="ll-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-xl-10 col-lg-11">
                <div class="text-center mb-5 ll-fade">
                    <div class="ll-section-tag"><span class="dot-sm"></span>Announcements</div>
                    <h2 class="ll-section-title">Notices &amp; Circulars</h2>
                    <div class="ll-divider mx-auto"></div>
                </div>
                <div class="ll-fade ll-fade-d1">
                    <?php
                    $cat_icons = [
                        'notice'       => 'fas fa-bell',
                        'circular'     => 'fas fa-envelope-open-text',
                        'policy'       => 'fas fa-file-alt',
                        'announcement' => 'fas fa-bullhorn',
                    ];
                    foreach ($notices as $n):
                        $cat   = $n['category'] ?? 'notice';
                        $icon  = $cat_icons[$cat] ?? 'fas fa-bell';
                        $ddisp = !empty($n['notice_date']) ? date('d M Y', strtotime($n['notice_date'])) : '';
                    ?>
                    <div class="ll-notice-item">
                        <div class="ll-notice-icon cat-<?= fh($cat) ?>">
                            <i class="<?= fh($icon) ?>"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="ll-notice-title"><?= fh($n['title']) ?></div>
                            <div class="ll-notice-meta">
                                <span class="ll-notice-badge cat-<?= fh($cat) ?>"><?= ucfirst(fh($cat)) ?></span>
                                <?php if ($ddisp): ?>
                                <span><i class="fas fa-calendar-alt" style="font-size:.7rem;margin-right:3px;"></i><?= fh($ddisp) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!empty($n['body'])): ?>
                        <div>
                            <button type="button"
                                    class="btn btn-sm btn-outline-primary"
                                    style="border-radius:8px;font-size:.77rem;padding:4px 12px;"
                                    data-bs-toggle="modal"
                                    data-bs-target="#noticeModal<?= (int)$n['id'] ?>">
                                View
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Notice modals -->
<?php foreach ($notices as $n): ?>
<?php if (!empty($n['body'])): ?>
<div class="modal fade" id="noticeModal<?= (int)$n['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;">
            <div class="modal-header" style="background:var(--ll-navy);border:none;">
                <h5 class="modal-title text-white fw-bold"><?= fh($n['title']) ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" style="font-size:.95rem;line-height:1.8;color:var(--ll-text);">
                <?= nl2br(fh($n['body'])) ?>
            </div>
            <?php if (!empty($n['notice_date'])): ?>
            <div class="modal-footer" style="border-top:1px solid #f0f4f8;">
                <span style="font-size:.82rem;color:var(--ll-muted);">
                    <i class="fas fa-calendar-alt me-1"></i>
                    <?= date('d M Y', strtotime($n['notice_date'])) ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endforeach; ?>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════════════════
     CONTACT CTA
══════════════════════════════════════════════════════════════════════════ -->
<section class="ll-section" style="background:linear-gradient(135deg, var(--ll-navy) 0%, var(--ll-accent) 100%);">
    <div class="container">
        <div class="row justify-content-center text-center">
            <div class="col-lg-8 ll-fade">
                <div style="width:72px;height:72px;border-radius:50%;background:rgba(200,151,26,.18);border:2px solid rgba(200,151,26,.4);display:flex;align-items:center;justify-content:center;margin:0 auto 24px;">
                    <i class="fas fa-envelope-open-text" style="font-size:1.8rem;color:var(--ll-gold);"></i>
                </div>
                <h2 style="font-size:clamp(1.5rem,4vw,2.1rem);font-weight:800;color:#fff;margin-bottom:14px;">
                    Need Legal Assistance?
                </h2>
                <p style="color:rgba(255,255,255,.72);font-size:.97rem;line-height:1.7;margin-bottom:32px;max-width:500px;margin-left:auto;margin-right:auto;">
                    Our legal team is here to assist university stakeholders with compliance matters, contract reviews, estate issues, and more. Reach out today.
                </p>
                <div class="d-flex flex-wrap gap-3 justify-content-center">
                    <?php if (lls($s,'adviser_email_1','') !== ''): ?>
                    <a href="mailto:<?= fh(lls($s,'adviser_email_1','')) ?>"
                       style="display:inline-flex;align-items:center;gap:9px;background:var(--ll-gold);color:var(--ll-navy);font-weight:700;font-size:.9rem;padding:13px 28px;border-radius:50px;text-decoration:none;transition:transform .25s,box-shadow .25s;"
                       onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 8px 28px rgba(200,151,26,.4)'"
                       onmouseout="this.style.transform='';this.style.boxShadow=''">
                        <i class="fas fa-envelope"></i>
                        Email Adviser
                    </a>
                    <?php endif; ?>
                    <?php if (lls($s,'assistant_email_1','') !== ''): ?>
                    <a href="mailto:<?= fh(lls($s,'assistant_email_1','')) ?>"
                       style="display:inline-flex;align-items:center;gap:9px;background:rgba(255,255,255,.12);color:#fff;font-weight:600;font-size:.9rem;padding:13px 28px;border-radius:50px;text-decoration:none;border:2px solid rgba(255,255,255,.25);transition:transform .25s,background .25s,box-shadow .25s;"
                       onmouseover="this.style.transform='translateY(-2px)';this.style.background='rgba(255,255,255,.22)'"
                       onmouseout="this.style.transform='';this.style.background='rgba(255,255,255,.12)'">
                        <i class="fas fa-user"></i>
                        Email Assistant
                    </a>
                    <?php endif; ?>
                    <?php if (lls($s,'assistant_phone','') !== ''): ?>
                    <a href="tel:<?= fh(lls($s,'assistant_phone','')) ?>"
                       style="display:inline-flex;align-items:center;gap:9px;background:rgba(255,255,255,.12);color:#fff;font-weight:600;font-size:.9rem;padding:13px 28px;border-radius:50px;text-decoration:none;border:2px solid rgba(255,255,255,.25);transition:transform .25s,background .25s,box-shadow .25s;"
                       onmouseover="this.style.transform='translateY(-2px)';this.style.background='rgba(255,255,255,.22)'"
                       onmouseout="this.style.transform='';this.style.background='rgba(255,255,255,.12)'">
                        <i class="fas fa-phone"></i>
                        <?= fh(lls($s,'assistant_phone','')) ?>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
<?php include __DIR__ . '/includes/scripts.php'; ?>

<script>
/* ── Scroll-triggered fade-in ──────────────────────────────────────────────── */
(function () {
    var els = document.querySelectorAll('.ll-fade');
    if (!els.length) return;
    // Apply initial hidden state via JS (progressive enhancement — visible without JS)
    els.forEach(function (el) { el.classList.add('ll-hidden'); });
    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                entry.target.classList.remove('ll-hidden');
                entry.target.classList.add('visible');
                io.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1 });
    els.forEach(function (el) { io.observe(el); });
})();
</script>

</body>
</html>
