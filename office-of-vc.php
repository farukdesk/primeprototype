<?php
require_once __DIR__ . '/includes/config.php';

/* ── Load all settings from DB ───────────────────────────────────────────── */
$s = [];
try {
    $db = front_db();
    if ($db) {
        $rows = $db->query('SELECT setting_key, setting_val FROM vc_settings')->fetchAll();
        foreach ($rows as $r) $s[$r['setting_key']] = $r['setting_val'];
    }
} catch (Throwable $e) {}

/* ── Helper ──────────────────────────────────────────────────────────────── */
function vs(array $s, string $key, string $default = ''): string {
    return isset($s[$key]) && $s[$key] !== '' ? $s[$key] : $default;
}

/* ── Redirect if unpublished ─────────────────────────────────────────────── */
if (vs($s, 'is_published', '1') !== '1') {
    header('Location: /index.php');
    exit;
}

$page_title = vs($s, 'hero_title', 'Office of the Vice Chancellor') . ' – Prime University';
$meta_desc  = vs($s, 'meta_description', 'Office of the Vice Chancellor – Prime University');

/* ── Break message into paragraphs ──────────────────────────────────────── */
$message_paragraphs = [];
$raw_message = vs($s, 'message_body', '');
if ($raw_message !== '') {
    $message_paragraphs = array_filter(
        array_map('trim', preg_split('/\n{2,}/', $raw_message)),
        fn($p) => $p !== ''
    );
}

$vc_photo_url = !empty($s['vc_photo'])
    ? ADMIN_UPLOAD_URL . '/office-of-vc/' . $s['vc_photo']
    : '';

$ps_photo_url = !empty($s['ps_photo'])
    ? ADMIN_UPLOAD_URL . '/office-of-vc/' . $s['ps_photo']
    : '';

/* ── Load Former VCs ─────────────────────────────────────────────────────── */
$former_vcs = [];
try {
    $db = front_db();
    if ($db) {
        $former_vcs = $db->query(
            "SELECT * FROM vc_former_vcs WHERE is_active = 1 ORDER BY sort_order ASC, id ASC"
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
/* ════════════════════════════════════════════════════════
   OFFICE OF VICE CHANCELLOR – PAGE STYLES
   ════════════════════════════════════════════════════════ */
:root {
  --vc-navy:    #002147;
  --vc-gold:    #FFB81C;
  --vc-blue:    #1a4faf;
  --vc-red:     #D21034;
  --vc-light:   #f4f7fb;
  --vc-text:    #334155;
  --vc-muted:   #64748b;
  --vc-white:   #ffffff;
  --vc-radius:  18px;
  --vc-shadow:  0 8px 40px rgba(0,33,71,.10);
  --vc-shadow-h:0 18px 60px rgba(0,33,71,.18);
  --vc-trans:   .35s cubic-bezier(.4,0,.2,1);
}

/* ── Hero ──────────────────────────────────────────────────────────────────── */
.vc-hero {
  background: linear-gradient(135deg, #001530 0%, #002f68 55%, #1a4faf 100%);
  padding: 110px 0 90px;
  position: relative;
  overflow: hidden;
}
.vc-hero::before {
  content: '';
  position: absolute; inset: 0;
  background:
    radial-gradient(ellipse 60% 80% at 80% 50%, rgba(255,184,28,.10) 0%, transparent 70%),
    radial-gradient(ellipse 40% 60% at 10% 80%, rgba(26,79,175,.35) 0%, transparent 60%);
  pointer-events: none;
}
.vc-hero .vc-circle {
  position: absolute; border-radius: 50%; pointer-events: none;
  animation: vcFloat 9s ease-in-out infinite;
}
.vc-hero .vc-circle.c1 { width:380px;height:380px;background:rgba(255,184,28,.07);top:-90px;right:-70px;animation-delay:0s; }
.vc-hero .vc-circle.c2 { width:200px;height:200px;background:rgba(255,255,255,.05);bottom:20px;left:4%;animation-delay:3s; }
.vc-hero .vc-circle.c3 { width:120px;height:120px;background:rgba(255,184,28,.10);top:35%;right:22%;animation-delay:1.5s; }
@keyframes vcFloat {
  0%,100% { transform: translateY(0) scale(1); }
  50%      { transform: translateY(-22px) scale(1.05); }
}

.vc-hero .breadcrumb-nav {
  display: flex; align-items: center; gap: 8px;
  font-size: .82rem; font-weight: 600; letter-spacing: .05em;
  text-transform: uppercase; color: rgba(255,255,255,.65); margin-bottom: 24px;
}
.vc-hero .breadcrumb-nav a { color: var(--vc-gold); text-decoration: none; }
.vc-hero .breadcrumb-nav a:hover { color: #fff; }
.vc-hero .breadcrumb-nav .sep { color: rgba(255,255,255,.35); }

.vc-hero-tag {
  display: inline-flex; align-items: center; gap: 8px;
  background: rgba(255,184,28,.18); color: var(--vc-gold);
  font-size: .77rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase;
  padding: 7px 18px; border-radius: 50px; border: 1px solid rgba(255,184,28,.35); margin-bottom: 20px;
}
.vc-hero-tag .dot {
  width: 7px; height: 7px; border-radius: 50%; background: var(--vc-gold);
  animation: vcPulse 1.8s ease-in-out infinite;
}
@keyframes vcPulse { 0%,100%{opacity:1;transform:scale(1);} 50%{opacity:.5;transform:scale(1.6);} }

.vc-hero h1 {
  font-size: clamp(2rem, 5vw, 3.4rem);
  font-weight: 800; color: #fff; line-height: 1.15; margin-bottom: 18px;
}
.vc-hero h1 .accent { color: var(--vc-gold); }
.vc-hero .hero-sub {
  font-size: clamp(.93rem, 1.8vw, 1.1rem);
  color: rgba(255,255,255,.78); max-width: 580px; line-height: 1.75;
}

/* ── Profile Card ──────────────────────────────────────────────────────────── */
.vc-profile-section {
  padding: 80px 0 60px;
  background: var(--vc-white);
}
.vc-profile-card {
  background: var(--vc-white);
  border-radius: 24px;
  box-shadow: var(--vc-shadow);
  overflow: hidden;
  transition: box-shadow var(--vc-trans);
  border: 1px solid rgba(0,33,71,.06);
}
.vc-profile-card:hover { box-shadow: var(--vc-shadow-h); }

.vc-card-accent {
  height: 6px;
  background: linear-gradient(90deg, var(--vc-navy) 0%, var(--vc-blue) 50%, var(--vc-gold) 100%);
}
.vc-card-body { padding: 40px; }

@media (max-width: 767.98px) { .vc-card-body { padding: 28px 20px; } }

.vc-photo-wrap {
  position: relative; display: inline-block;
}
.vc-photo-wrap img {
  width: 160px; height: 160px; border-radius: 50%; object-fit: cover;
  object-position: top center;
  border: 5px solid #fff; box-shadow: 0 8px 32px rgba(0,33,71,.18);
  display: block;
}
.vc-photo-badge {
  position: absolute; bottom: 6px; right: 6px;
  width: 32px; height: 32px; border-radius: 50%;
  background: var(--vc-gold); border: 3px solid #fff;
  display: flex; align-items: center; justify-content: center;
}
.vc-photo-badge i { color: var(--vc-navy); font-size: .7rem; }

.vc-photo-placeholder {
  width: 160px; height: 160px; border-radius: 50%;
  background: linear-gradient(135deg, var(--vc-navy), var(--vc-blue));
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 8px 32px rgba(0,33,71,.18); border: 5px solid #fff;
}
.vc-photo-placeholder i { color: rgba(255,255,255,.75); font-size: 3.5rem; }

.vc-name { font-size: clamp(1.3rem, 3vw, 1.75rem); font-weight: 800; color: var(--vc-navy); margin-bottom: 4px; }
.vc-designation {
  display: inline-flex; align-items: center; gap: 8px;
  background: var(--vc-navy); color: var(--vc-gold);
  font-size: .8rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
  padding: 5px 16px; border-radius: 50px; margin-bottom: 24px;
}

.vc-contact-list { list-style: none; padding: 0; margin: 0; }
.vc-contact-list li {
  display: flex; align-items: flex-start; gap: 12px;
  padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: .9rem; color: var(--vc-text);
}
.vc-contact-list li:last-child { border-bottom: none; }
.vc-contact-icon {
  width: 34px; height: 34px; border-radius: 8px; flex-shrink: 0;
  background: var(--vc-light); display: flex; align-items: center; justify-content: center;
}
.vc-contact-icon i { color: var(--vc-blue); font-size: .85rem; }
.vc-contact-list a { color: var(--vc-blue); text-decoration: none; transition: color var(--vc-trans); }
.vc-contact-list a:hover { color: var(--vc-navy); text-decoration: underline; }

.vc-action-btn {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 10px 22px; border-radius: 50px; font-size: .83rem; font-weight: 700;
  text-decoration: none; transition: all var(--vc-trans); letter-spacing: .03em;
}
.vc-action-btn.scholar {
  background: #4285f4; color: #fff; box-shadow: 0 4px 16px rgba(66,133,244,.3);
}
.vc-action-btn.scholar:hover { background: #3367d6; transform: translateY(-2px); box-shadow: 0 8px 24px rgba(66,133,244,.4); }

/* ── Bio strip ─────────────────────────────────────────────────────────────── */
.vc-bio-section {
  background: var(--vc-light);
  padding: 70px 0;
}
.vc-bio-card {
  background: var(--vc-white); border-radius: 20px; padding: 44px 48px;
  box-shadow: var(--vc-shadow); border-left: 5px solid var(--vc-gold);
  position: relative;
}
@media (max-width: 767.98px) { .vc-bio-card { padding: 28px 22px; } }
.vc-bio-card::before {
  content: '\201C';
  position: absolute; top: 16px; left: 28px;
  font-size: 6rem; line-height: 1; color: rgba(0,33,71,.06);
  font-family: Georgia, serif; pointer-events: none;
}
.vc-bio-section-tag {
  display: inline-flex; align-items: center; gap: 8px;
  background: rgba(0,33,71,.07); color: var(--vc-navy);
  font-size: .76rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
  padding: 6px 16px; border-radius: 50px; margin-bottom: 16px;
}
.vc-bio-card p {
  font-size: 1.02rem; color: var(--vc-text); line-height: 1.85; margin-bottom: 0;
}

/* ── VC Message ────────────────────────────────────────────────────────────── */
.vc-message-section {
  padding: 80px 0;
  background: var(--vc-white);
  position: relative;
  overflow: hidden;
}
.vc-message-section::before {
  content: '';
  position: absolute; top: 0; left: 0; right: 0; height: 4px;
  background: linear-gradient(90deg, var(--vc-navy), var(--vc-blue), var(--vc-gold));
}
.vc-message-card {
  background: linear-gradient(135deg, #001e45 0%, #002f68 100%);
  border-radius: 24px; padding: 56px 56px 48px;
  position: relative; overflow: hidden;
  box-shadow: 0 16px 56px rgba(0,33,71,.22);
}
@media (max-width: 767.98px) { .vc-message-card { padding: 36px 24px 32px; } }
.vc-message-card::before {
  content: '';
  position: absolute; top: -60px; right: -60px;
  width: 280px; height: 280px; border-radius: 50%;
  background: rgba(255,184,28,.08); pointer-events: none;
}
.vc-message-card::after {
  content: '';
  position: absolute; bottom: -80px; left: -40px;
  width: 220px; height: 220px; border-radius: 50%;
  background: rgba(26,79,175,.3); pointer-events: none;
}
.vc-msg-header { position: relative; z-index: 1; margin-bottom: 32px; }
.vc-msg-icon {
  width: 56px; height: 56px; border-radius: 14px;
  background: rgba(255,184,28,.2); border: 1px solid rgba(255,184,28,.4);
  display: flex; align-items: center; justify-content: center; margin-bottom: 16px;
}
.vc-msg-icon i { color: var(--vc-gold); font-size: 1.4rem; }
.vc-msg-title {
  font-size: clamp(1.35rem, 3vw, 1.9rem); font-weight: 800;
  color: #fff; margin-bottom: 4px;
}
.vc-msg-subtitle { color: rgba(255,255,255,.6); font-size: .9rem; }
.vc-msg-divider {
  height: 2px; width: 60px;
  background: linear-gradient(90deg, var(--vc-gold), transparent);
  margin: 20px 0 28px;
}
.vc-msg-body { position: relative; z-index: 1; }
.vc-msg-body p {
  color: rgba(255,255,255,.88); font-size: 1rem; line-height: 1.9;
  margin-bottom: 1.4rem;
}
.vc-msg-body p:last-child { margin-bottom: 0; }
.vc-msg-sig {
  position: relative; z-index: 1;
  margin-top: 36px; padding-top: 24px;
  border-top: 1px solid rgba(255,255,255,.12);
  display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
}
.vc-msg-sig-photo {
  width: 54px; height: 54px; border-radius: 50%; object-fit: cover;
  object-position: top center;
  border: 2px solid rgba(255,184,28,.4); flex-shrink: 0;
}
.vc-msg-sig-placeholder {
  width: 54px; height: 54px; border-radius: 50%; flex-shrink: 0;
  background: rgba(255,255,255,.1); display: flex; align-items: center; justify-content: center;
  border: 2px solid rgba(255,184,28,.3);
}
.vc-msg-sig-placeholder i { color: rgba(255,255,255,.6); font-size: 1.2rem; }
.vc-msg-sig-name { color: #fff; font-weight: 700; font-size: 1rem; margin-bottom: 2px; }
.vc-msg-sig-role { color: var(--vc-gold); font-size: .8rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; }

/* ── PS Card ───────────────────────────────────────────────────────────────── */
.vc-ps-section {
  background: var(--vc-white);
  padding: 70px 0;
}
.vc-ps-card {
  background: var(--vc-white); border-radius: 20px;
  box-shadow: var(--vc-shadow); overflow: hidden;
  border: 1px solid rgba(0,33,71,.06);
  transition: box-shadow var(--vc-trans), transform var(--vc-trans);
}
.vc-ps-card:hover { box-shadow: var(--vc-shadow-h); transform: translateY(-4px); }
.vc-ps-card-accent {
  height: 4px;
  background: linear-gradient(90deg, var(--vc-blue), var(--vc-gold));
}
.vc-ps-card-body { padding: 32px 36px; }
@media (max-width: 575.98px) { .vc-ps-card-body { padding: 24px 20px; } }
.vc-ps-photo {
  width: 80px; height: 80px; border-radius: 50%; object-fit: cover;
  object-position: top center;
  border: 3px solid #fff; box-shadow: 0 4px 18px rgba(0,33,71,.15);
  flex-shrink: 0;
}
.vc-ps-avatar {
  width: 80px; height: 80px; border-radius: 50%; flex-shrink: 0;
  background: linear-gradient(135deg, var(--vc-blue), #3b82f6);
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 4px 16px rgba(26,79,175,.25);
}
.vc-ps-avatar i { color: #fff; font-size: 1.7rem; }
.vc-ps-name { font-size: 1.2rem; font-weight: 700; color: var(--vc-navy); margin-bottom: 4px; }
.vc-ps-designation {
  display: inline-block; background: rgba(26,79,175,.1); color: var(--vc-blue);
  font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em;
  padding: 3px 12px; border-radius: 50px; margin-bottom: 0;
}

/* ── Section header shared ─────────────────────────────────────────────────── */
.vc-section-tag {
  display: inline-flex; align-items: center; gap: 8px;
  background: rgba(0,33,71,.07); color: var(--vc-navy);
  font-size: .76rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
  padding: 6px 16px; border-radius: 50px; margin-bottom: 12px;
}
.vc-section-tag .dot-sm {
  width: 6px; height: 6px; border-radius: 50%; background: var(--vc-gold);
}
.vc-section-title {
  font-size: clamp(1.5rem, 3.5vw, 2.2rem); font-weight: 800; color: var(--vc-navy);
  margin-bottom: 12px; line-height: 1.2;
}
.vc-section-subtitle {
  font-size: 1.02rem; color: var(--vc-muted); max-width: 540px; line-height: 1.7;
}
.vc-divider {
  width: 56px; height: 4px; border-radius: 2px;
  background: linear-gradient(90deg, var(--vc-gold), var(--vc-blue));
  margin: 14px 0 0;
}

/* ── Fade-in animations ────────────────────────────────────────────────────── */
.vc-fade { opacity: 0; transform: translateY(30px); transition: opacity .7s ease, transform .7s ease; }
.vc-fade.visible { opacity: 1; transform: translateY(0); }
.vc-fade-delay-1 { transition-delay: .1s; }
.vc-fade-delay-2 { transition-delay: .2s; }
.vc-fade-delay-3 { transition-delay: .3s; }
.vc-fade-delay-4 { transition-delay: .4s; }

/* ── Quick facts strip ─────────────────────────────────────────────────────── */
.vc-facts-strip {
  background: var(--vc-navy);
  padding: 24px 0;
}
.vc-fact-item {
  display: flex; align-items: center; gap: 12px;
  color: rgba(255,255,255,.85);
  padding: 10px 20px;
  border-right: 1px solid rgba(255,255,255,.12);
}
.vc-fact-item:last-child { border-right: none; }
.vc-fact-icon { color: var(--vc-gold); font-size: 1.2rem; flex-shrink: 0; }
.vc-fact-text strong { display: block; font-size: .92rem; font-weight: 700; color: #fff; }
.vc-fact-text span { font-size: .77rem; color: rgba(255,255,255,.55); letter-spacing: .04em; text-transform: uppercase; }

@media (max-width: 767.98px) {
  .vc-fact-item { border-right: none; border-bottom: 1px solid rgba(255,255,255,.10); }
  .vc-fact-item:last-child { border-bottom: none; }
}

/* ── Former VCs ────────────────────────────────────────────────────────────── */
.vc-former-section {
  background: var(--vc-light);
  padding: 80px 0;
  position: relative;
}
.vc-former-section::before {
  content: '';
  position: absolute; top: 0; left: 0; right: 0; height: 3px;
  background: linear-gradient(90deg, var(--vc-gold), var(--vc-navy), var(--vc-blue));
}
.fvc-card {
  background: var(--vc-white);
  border-radius: 20px;
  box-shadow: var(--vc-shadow);
  overflow: hidden;
  border: 1px solid rgba(0,33,71,.06);
  transition: box-shadow var(--vc-trans), transform var(--vc-trans);
  height: 100%;
  display: flex; flex-direction: column;
}
.fvc-card:hover { box-shadow: var(--vc-shadow-h); transform: translateY(-6px); }
.fvc-card-accent {
  height: 5px;
  background: linear-gradient(90deg, var(--vc-navy), var(--vc-blue));
}
.fvc-card-body { padding: 32px 28px 28px; flex: 1; display: flex; flex-direction: column; align-items: center; }
@media (max-width: 575.98px) { .fvc-card-body { padding: 24px 18px 20px; } }
.fvc-photo-wrap {
  position: relative; display: inline-block; margin-bottom: 18px;
}
.fvc-photo {
  width: 110px; height: 110px; border-radius: 50%; object-fit: cover;
  object-position: top center;
  border: 4px solid #fff; box-shadow: 0 6px 22px rgba(0,33,71,.18);
  display: block;
}
.fvc-photo-placeholder {
  width: 110px; height: 110px; border-radius: 50%;
  background: linear-gradient(135deg, var(--vc-navy), var(--vc-blue));
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 6px 22px rgba(0,33,71,.18);
  border: 4px solid #fff;
}
.fvc-photo-placeholder i { color: rgba(255,255,255,.75); font-size: 2.4rem; }
.fvc-tenure-badge {
  display: inline-flex; align-items: center; gap: 6px;
  background: rgba(0,33,71,.08); color: var(--vc-navy);
  font-size: .72rem; font-weight: 700; letter-spacing: .07em; text-transform: uppercase;
  padding: 4px 14px; border-radius: 50px; margin-bottom: 10px;
}
.fvc-name { font-size: 1.08rem; font-weight: 800; color: var(--vc-navy); margin-bottom: 5px; text-align: center; }
.fvc-title { font-size: .82rem; color: var(--vc-muted); text-align: center; }
.fvc-bio {
  font-size: .87rem; color: var(--vc-text); line-height: 1.75;
  margin-top: 14px; padding-top: 14px;
  border-top: 1px solid #f1f5f9; text-align: center;
  display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;
}
</style>

</head>
<body>

<?php include __DIR__ . '/includes/header-top.php'; ?>
<?php include __DIR__ . '/includes/nav-menu.php'; ?>

<!-- ══════════════ HERO ══════════════ -->
<section class="vc-hero">
    <div class="vc-circle c1"></div>
    <div class="vc-circle c2"></div>
    <div class="vc-circle c3"></div>
    <div class="container" style="position:relative;z-index:2;">
        <div class="breadcrumb-nav">
            <a href="/index.php">Home</a>
            <span class="sep">/</span>
            <a href="#">About</a>
            <span class="sep">/</span>
            <span style="color:rgba(255,255,255,.85);"><?= fh(vs($s,'hero_title','Office of the Vice Chancellor')) ?></span>
        </div>
        <div class="vc-hero-tag">
            <span class="dot"></span>
            Prime University · Bangladesh
        </div>
        <h1>
            <?php
            $htitle = vs($s, 'hero_title', 'Office of the Vice Chancellor');
            $words  = explode(' ', $htitle);
            $last   = array_pop($words);
            echo fh(implode(' ', $words)) . ' <span class="accent">' . fh($last) . '</span>';
            ?>
        </h1>
        <?php if (vs($s, 'hero_subtitle', '') !== ''): ?>
        <p class="vc-designation" style="background:rgba(255,184,28,.18);color:var(--vc-gold);margin-bottom:16px;">
            <?= fh(vs($s,'hero_subtitle','')) ?>
        </p>
        <?php endif; ?>
        <?php if (vs($s, 'hero_intro', '') !== ''): ?>
        <p class="hero-sub"><?= fh(vs($s,'hero_intro','')) ?></p>
        <?php endif; ?>
    </div>
</section>

<!-- ══════════════ QUICK FACTS STRIP ══════════════ -->
<div class="vc-facts-strip">
    <div class="container">
        <div class="row g-0 justify-content-center">
            <?php if (vs($s,'vc_phone','') !== ''): ?>
            <div class="col-sm-6 col-md-4 col-lg-3">
                <div class="vc-fact-item">
                    <i class="fas fa-phone vc-fact-icon"></i>
                    <div class="vc-fact-text">
                        <strong><?= fh(vs($s,'vc_phone','')) ?></strong>
                        <span>VC Direct Line</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php if (vs($s,'vc_email_1','') !== ''): ?>
            <div class="col-sm-6 col-md-4 col-lg-4">
                <div class="vc-fact-item">
                    <i class="fas fa-envelope vc-fact-icon"></i>
                    <div class="vc-fact-text">
                        <strong><?= fh(vs($s,'vc_email_1','')) ?></strong>
                        <span>Official Email</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php if (vs($s,'ps_phone','') !== ''): ?>
            <div class="col-sm-6 col-md-4 col-lg-3">
                <div class="vc-fact-item">
                    <i class="fas fa-user vc-fact-icon"></i>
                    <div class="vc-fact-text">
                        <strong><?= fh(vs($s,'ps_phone','')) ?></strong>
                        <span>PS to VC</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ══════════════ VC PROFILE ══════════════ -->
<section class="vc-profile-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-xl-10 col-lg-11">
                <div class="vc-profile-card vc-fade">
                    <div class="vc-card-accent"></div>
                    <div class="vc-card-body">
                        <div class="row align-items-center g-4">
                            <!-- Photo -->
                            <div class="col-md-auto text-center text-md-start">
                                <div class="vc-photo-wrap">
                                    <?php if ($vc_photo_url): ?>
                                    <img src="<?= fh($vc_photo_url) ?>"
                                         alt="<?= fh(vs($s,'vc_name','Vice Chancellor')) ?>">
                                    <?php else: ?>
                                    <div class="vc-photo-placeholder">
                                        <i class="fas fa-user-tie"></i>
                                    </div>
                                    <?php endif; ?>
                                    <div class="vc-photo-badge">
                                        <i class="fas fa-star"></i>
                                    </div>
                                </div>
                            </div>
                            <!-- Info -->
                            <div class="col-md">
                                <h2 class="vc-name"><?= fh(vs($s,'vc_name','Prof. Dr. Quazi Deen Mohd Khosru')) ?></h2>
                                <div class="vc-designation">
                                    <i class="fas fa-university me-1"></i>
                                    <?= fh(vs($s,'vc_title','Vice Chancellor')) ?>
                                </div>
                                <ul class="vc-contact-list">
                                    <?php if (vs($s,'vc_email_1','') !== ''): ?>
                                    <li>
                                        <div class="vc-contact-icon"><i class="fas fa-envelope"></i></div>
                                        <div>
                                            <div style="font-size:.72rem;color:var(--vc-muted);text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:2px;">Primary Email</div>
                                            <a href="mailto:<?= fh(vs($s,'vc_email_1','')) ?>"><?= fh(vs($s,'vc_email_1','')) ?></a>
                                            <?php if (vs($s,'vc_email_2','') !== ''): ?>
                                            &nbsp;&middot;&nbsp;
                                            <a href="mailto:<?= fh(vs($s,'vc_email_2','')) ?>"><?= fh(vs($s,'vc_email_2','')) ?></a>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                    <?php endif; ?>
                                    <?php if (vs($s,'vc_phone','') !== ''): ?>
                                    <li>
                                        <div class="vc-contact-icon"><i class="fas fa-phone"></i></div>
                                        <div>
                                            <div style="font-size:.72rem;color:var(--vc-muted);text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:2px;">Phone</div>
                                            <a href="tel:<?= fh(vs($s,'vc_phone','')) ?>"><?= fh(vs($s,'vc_phone','')) ?></a>
                                        </div>
                                    </li>
                                    <?php endif; ?>
                                </ul>
                                <?php if (vs($s,'vc_scholar_url','') !== ''): ?>
                                <div class="mt-4">
                                    <a href="<?= fh(vs($s,'vc_scholar_url','')) ?>"
                                       target="_blank" rel="noopener noreferrer"
                                       class="vc-action-btn scholar">
                                        <i class="fab fa-google"></i>
                                        Google Scholar Profile
                                        <i class="fas fa-external-link-alt" style="font-size:.7rem;"></i>
                                    </a>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ══════════════ BIO ══════════════ -->
<?php if (vs($s,'vc_bio','') !== ''): ?>
<section class="vc-bio-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-xl-10 col-lg-11">
                <div class="vc-bio-section-tag vc-fade">
                    <i class="fas fa-graduation-cap"></i> Academic Profile
                </div>
                <div class="vc-bio-card vc-fade vc-fade-delay-1">
                    <p><?= nl2br(fh(vs($s,'vc_bio',''))) ?></p>
                </div>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ══════════════ MESSAGE ══════════════ -->
<?php if (!empty($message_paragraphs)): ?>
<section class="vc-message-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-xl-10 col-lg-11">
                <!-- Section header -->
                <div class="text-center mb-5 vc-fade">
                    <div class="vc-section-tag" style="display:inline-flex;">
                        <span class="dot-sm"></span>
                        Leadership Message
                    </div>
                    <h2 class="vc-section-title"><?= fh(vs($s,'message_title','Message from the Vice Chancellor')) ?></h2>
                    <div class="vc-divider mx-auto"></div>
                </div>
                <!-- Message card -->
                <div class="vc-message-card vc-fade vc-fade-delay-1">
                    <div class="vc-msg-header">
                        <div class="vc-msg-icon"><i class="fas fa-quote-left"></i></div>
                        <h3 class="vc-msg-title"><?= fh(vs($s,'message_title','Message from the Vice Chancellor')) ?></h3>
                        <p class="vc-msg-subtitle"><?= fh(vs($s,'vc_name','Vice Chancellor')) ?> &middot; Prime University</p>
                        <div class="vc-msg-divider"></div>
                    </div>
                    <div class="vc-msg-body">
                        <?php foreach ($message_paragraphs as $para): ?>
                        <p><?= fh($para) ?></p>
                        <?php endforeach; ?>
                    </div>
                    <!-- Signature -->
                    <div class="vc-msg-sig">
                        <?php if ($vc_photo_url): ?>
                        <img src="<?= fh($vc_photo_url) ?>"
                             class="vc-msg-sig-photo"
                             alt="<?= fh(vs($s,'vc_name','')) ?>">
                        <?php else: ?>
                        <div class="vc-msg-sig-placeholder">
                            <i class="fas fa-user-tie"></i>
                        </div>
                        <?php endif; ?>
                        <div>
                            <div class="vc-msg-sig-name"><?= fh(vs($s,'vc_name','Prof. Dr. Quazi Deen Mohd Khosru')) ?></div>
                            <div class="vc-msg-sig-role"><?= fh(vs($s,'vc_title','Vice Chancellor')) ?> &mdash; Prime University</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ══════════════ PS PROFILE ══════════════ -->
<?php if (vs($s,'ps_name','') !== ''): ?>
<section class="vc-ps-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-xl-10 col-lg-11">
                <!-- Header -->
                <div class="mb-5 vc-fade">
                    <div class="vc-section-tag">
                        <span class="dot-sm"></span>
                        Office Staff
                    </div>
                    <h2 class="vc-section-title">Personal Secretary</h2>
                    <div class="vc-divider"></div>
                </div>
                <!-- PS Card -->
                <div class="row justify-content-center">
                    <div class="col-md-8 col-lg-7">
                        <div class="vc-ps-card vc-fade vc-fade-delay-1">
                            <div class="vc-ps-card-accent"></div>
                            <div class="vc-ps-card-body">
                                <div class="d-flex align-items-center gap-4 mb-4">
                                    <?php if ($ps_photo_url): ?>
                                    <img src="<?= fh($ps_photo_url) ?>"
                                         alt="<?= fh(vs($s,'ps_name','')) ?>"
                                         class="vc-ps-photo">
                                    <?php else: ?>
                                    <div class="vc-ps-avatar">
                                        <i class="fas fa-user"></i>
                                    </div>
                                    <?php endif; ?>
                                    <div>
                                        <div class="vc-ps-name"><?= fh(vs($s,'ps_name','')) ?></div>
                                        <div class="vc-ps-designation"><?= fh(vs($s,'ps_title','PS to Vice Chancellor')) ?></div>
                                    </div>
                                </div>
                                <ul class="vc-contact-list">
                                    <?php if (vs($s,'ps_email_1','') !== ''): ?>
                                    <li>
                                        <div class="vc-contact-icon"><i class="fas fa-envelope"></i></div>
                                        <div>
                                            <div style="font-size:.72rem;color:var(--vc-muted);text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:2px;">Email</div>
                                            <a href="mailto:<?= fh(vs($s,'ps_email_1','')) ?>"><?= fh(vs($s,'ps_email_1','')) ?></a>
                                            <?php if (vs($s,'ps_email_2','') !== ''): ?>
                                            &nbsp;&middot;&nbsp;
                                            <a href="mailto:<?= fh(vs($s,'ps_email_2','')) ?>"><?= fh(vs($s,'ps_email_2','')) ?></a>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                    <?php endif; ?>
                                    <?php if (vs($s,'ps_phone','') !== ''): ?>
                                    <li>
                                        <div class="vc-contact-icon"><i class="fas fa-phone"></i></div>
                                        <div>
                                            <div style="font-size:.72rem;color:var(--vc-muted);text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:2px;">Phone</div>
                                            <a href="tel:<?= fh(vs($s,'ps_phone','')) ?>"><?= fh(vs($s,'ps_phone','')) ?></a>
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
<?php endif; ?>

<!-- ══════════════ FORMER VICE CHANCELLORS ══════════════ -->
<?php if (!empty($former_vcs)): ?>
<section class="vc-former-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-xl-10 col-lg-11">
                <!-- Section header -->
                <div class="mb-5 vc-fade">
                    <div class="vc-section-tag">
                        <span class="dot-sm"></span>
                        Historical Leadership
                    </div>
                    <h2 class="vc-section-title">Former Vice Chancellors</h2>
                    <div class="vc-divider"></div>
                </div>
                <!-- Cards grid -->
                <div class="row g-4 justify-content-center">
                    <?php foreach ($former_vcs as $i => $fvc): ?>
                    <?php
                        $fvc_photo_url = !empty($fvc['photo'])
                            ? ADMIN_UPLOAD_URL . '/office-of-vc/' . $fvc['photo']
                            : '';
                        $delay_class = 'vc-fade-delay-' . (($i % 4) + 1);
                    ?>
                    <div class="col-sm-6 col-md-4 col-lg-3">
                        <div class="fvc-card vc-fade <?= $delay_class ?>">
                            <div class="fvc-card-accent"></div>
                            <div class="fvc-card-body">
                                <div class="fvc-photo-wrap">
                                    <?php if ($fvc_photo_url): ?>
                                    <img src="<?= fh($fvc_photo_url) ?>"
                                         alt="<?= fh($fvc['name']) ?>"
                                         class="fvc-photo">
                                    <?php else: ?>
                                    <div class="fvc-photo-placeholder">
                                        <i class="fas fa-user-tie"></i>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($fvc['tenure'])): ?>
                                <div class="fvc-tenure-badge">
                                    <i class="fas fa-calendar-alt" style="font-size:.65rem;"></i>
                                    <?= fh($fvc['tenure']) ?>
                                </div>
                                <?php endif; ?>
                                <div class="fvc-name"><?= fh($fvc['name']) ?></div>
                                <div class="fvc-title"><?= fh($fvc['title']) ?></div>
                                <?php if (!empty($fvc['bio'])): ?>
                                <div class="fvc-bio"><?= fh($fvc['bio']) ?></div>
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

<?php include __DIR__ . '/includes/footer.php'; ?>
<?php include __DIR__ . '/includes/scripts.php'; ?>

<script>
/* ── Scroll-triggered fade-in ──────────────────────────────────────────────── */
(function () {
    const els = document.querySelectorAll('.vc-fade');
    if (!els.length) return;
    const io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
                io.unobserve(entry.target);
            }
        });
    }, { threshold: 0.12 });
    els.forEach(function (el) { io.observe(el); });
})();
</script>

</body>
</html>
