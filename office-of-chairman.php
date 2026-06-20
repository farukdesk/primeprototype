<?php
require_once __DIR__ . '/includes/config.php';

/* ── Load all settings from DB ───────────────────────────────────────────── */
$s = [];
try {
    $db = front_db();
    if ($db) {
        $rows = $db->query('SELECT setting_key, setting_val FROM ch_settings')->fetchAll();
        foreach ($rows as $r) $s[$r['setting_key']] = $r['setting_val'];
    }
} catch (Throwable $e) {}

/* ── Helper ──────────────────────────────────────────────────────────────── */
function cs(array $s, string $key, string $default = ''): string {
    return isset($s[$key]) && $s[$key] !== '' ? $s[$key] : $default;
}

/* ── Redirect if unpublished ─────────────────────────────────────────────── */
if (cs($s, 'is_published', '1') !== '1') {
    header('Location: /index.php');
    exit;
}

$page_title = cs($s, 'hero_title', 'Office of the Chairman') . ' – Prime University';
$meta_desc  = cs($s, 'meta_description', 'Office of the Chairman – Prime University');

/* ── Break message into paragraphs ──────────────────────────────────────── */
$message_paragraphs = [];
$raw_message = cs($s, 'message_body', '');
if ($raw_message !== '') {
    $message_paragraphs = array_filter(
        array_map('trim', preg_split('/\n{2,}/', $raw_message)),
        fn($p) => $p !== ''
    );
}

$ch_photo_url = !empty($s['ch_photo'])
    ? ADMIN_UPLOAD_URL . '/office-of-chairman/' . $s['ch_photo']
    : '';

$ps_photo_url = !empty($s['ps_photo'])
    ? ADMIN_UPLOAD_URL . '/office-of-chairman/' . $s['ps_photo']
    : '';
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
   OFFICE OF CHAIRMAN – PAGE STYLES
   ════════════════════════════════════════════════════════ */
:root {
  --ch-navy:    #002147;
  --ch-gold:    #FFB81C;
  --ch-blue:    #1a4faf;
  --ch-red:     #D21034;
  --ch-light:   #f4f7fb;
  --ch-text:    #334155;
  --ch-muted:   #64748b;
  --ch-white:   #ffffff;
  --ch-radius:  18px;
  --ch-shadow:  0 8px 40px rgba(0,33,71,.10);
  --ch-shadow-h:0 18px 60px rgba(0,33,71,.18);
  --ch-trans:   .35s cubic-bezier(.4,0,.2,1);
}

/* ── Hero ──────────────────────────────────────────────────────────────────── */
.ch-hero {
  background: linear-gradient(135deg, #001530 0%, #002f68 55%, #1a4faf 100%);
  padding: 110px 0 90px;
  position: relative;
  overflow: hidden;
}
.ch-hero::before {
  content: '';
  position: absolute; inset: 0;
  background:
    radial-gradient(ellipse 60% 80% at 80% 50%, rgba(255,184,28,.10) 0%, transparent 70%),
    radial-gradient(ellipse 40% 60% at 10% 80%, rgba(26,79,175,.35) 0%, transparent 60%);
  pointer-events: none;
}
.ch-hero .ch-circle {
  position: absolute; border-radius: 50%; pointer-events: none;
  animation: chFloat 9s ease-in-out infinite;
}
.ch-hero .ch-circle.c1 { width:380px;height:380px;background:rgba(255,184,28,.07);top:-90px;right:-70px;animation-delay:0s; }
.ch-hero .ch-circle.c2 { width:200px;height:200px;background:rgba(255,255,255,.05);bottom:20px;left:4%;animation-delay:3s; }
.ch-hero .ch-circle.c3 { width:120px;height:120px;background:rgba(255,184,28,.10);top:35%;right:22%;animation-delay:1.5s; }
@keyframes chFloat {
  0%,100% { transform: translateY(0) scale(1); }
  50%      { transform: translateY(-22px) scale(1.05); }
}

.ch-hero .breadcrumb-nav {
  display: flex; align-items: center; gap: 8px;
  font-size: .82rem; font-weight: 600; letter-spacing: .05em;
  text-transform: uppercase; color: rgba(255,255,255,.65); margin-bottom: 24px;
}
.ch-hero .breadcrumb-nav a { color: var(--ch-gold); text-decoration: none; }
.ch-hero .breadcrumb-nav a:hover { color: #fff; }
.ch-hero .breadcrumb-nav .sep { color: rgba(255,255,255,.35); }

.ch-hero-tag {
  display: inline-flex; align-items: center; gap: 8px;
  background: rgba(255,184,28,.18); color: var(--ch-gold);
  font-size: .77rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase;
  padding: 7px 18px; border-radius: 50px; border: 1px solid rgba(255,184,28,.35); margin-bottom: 20px;
}
.ch-hero-tag .dot {
  width: 7px; height: 7px; border-radius: 50%; background: var(--ch-gold);
  animation: chPulse 1.8s ease-in-out infinite;
}
@keyframes chPulse { 0%,100%{opacity:1;transform:scale(1);} 50%{opacity:.5;transform:scale(1.6);} }

.ch-hero h1 {
  font-size: clamp(2rem, 5vw, 3.4rem);
  font-weight: 800; color: #fff; line-height: 1.15; margin-bottom: 18px;
}
.ch-hero h1 .accent { color: var(--ch-gold); }
.ch-hero .hero-sub {
  font-size: clamp(.93rem, 1.8vw, 1.1rem);
  color: rgba(255,255,255,.78); max-width: 580px; line-height: 1.75;
}

/* ── Profile Card ──────────────────────────────────────────────────────────── */
.ch-profile-section {
  padding: 80px 0 60px;
  background: var(--ch-white);
}
.ch-profile-card {
  background: var(--ch-white);
  border-radius: 24px;
  box-shadow: var(--ch-shadow);
  overflow: hidden;
  transition: box-shadow var(--ch-trans);
  border: 1px solid rgba(0,33,71,.06);
}
.ch-profile-card:hover { box-shadow: var(--ch-shadow-h); }

.ch-card-accent {
  height: 6px;
  background: linear-gradient(90deg, var(--ch-navy) 0%, var(--ch-blue) 50%, var(--ch-gold) 100%);
}
.ch-card-body { padding: 40px; }

@media (max-width: 767.98px) { .ch-card-body { padding: 28px 20px; } }

.ch-photo-wrap {
  position: relative; display: inline-block;
}
.ch-photo-wrap img {
  width: 160px; height: 160px; border-radius: 50%; object-fit: cover;
  object-position: top center;
  border: 5px solid #fff; box-shadow: 0 8px 32px rgba(0,33,71,.18);
  display: block;
}
.ch-photo-badge {
  position: absolute; bottom: 6px; right: 6px;
  width: 32px; height: 32px; border-radius: 50%;
  background: var(--ch-gold); border: 3px solid #fff;
  display: flex; align-items: center; justify-content: center;
}
.ch-photo-badge i { color: var(--ch-navy); font-size: .7rem; }

.ch-photo-placeholder {
  width: 160px; height: 160px; border-radius: 50%;
  background: linear-gradient(135deg, var(--ch-navy), var(--ch-blue));
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 8px 32px rgba(0,33,71,.18); border: 5px solid #fff;
}
.ch-photo-placeholder i { color: rgba(255,255,255,.75); font-size: 3.5rem; }

.ch-name { font-size: clamp(1.3rem, 3vw, 1.75rem); font-weight: 800; color: var(--ch-navy); margin-bottom: 4px; }
.ch-designation {
  display: inline-flex; align-items: center; gap: 8px;
  background: var(--ch-navy); color: var(--ch-gold);
  font-size: .8rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
  padding: 5px 16px; border-radius: 50px; margin-bottom: 24px;
}

.ch-contact-list { list-style: none; padding: 0; margin: 0; }
.ch-contact-list li {
  display: flex; align-items: flex-start; gap: 12px;
  padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: .9rem; color: var(--ch-text);
}
.ch-contact-list li:last-child { border-bottom: none; }
.ch-contact-icon {
  width: 34px; height: 34px; border-radius: 8px; flex-shrink: 0;
  background: var(--ch-light); display: flex; align-items: center; justify-content: center;
}
.ch-contact-icon i { color: var(--ch-blue); font-size: .85rem; }
.ch-contact-list a { color: var(--ch-blue); text-decoration: none; transition: color var(--ch-trans); }
.ch-contact-list a:hover { color: var(--ch-navy); text-decoration: underline; }

/* ── Bio strip ─────────────────────────────────────────────────────────────── */
.ch-bio-section {
  background: var(--ch-light);
  padding: 70px 0;
}
.ch-bio-card {
  background: var(--ch-white); border-radius: 20px; padding: 44px 48px;
  box-shadow: var(--ch-shadow); border-left: 5px solid var(--ch-gold);
  position: relative;
}
@media (max-width: 767.98px) { .ch-bio-card { padding: 28px 22px; } }
.ch-bio-card::before {
  content: '\201C';
  position: absolute; top: 16px; left: 28px;
  font-size: 6rem; line-height: 1; color: rgba(0,33,71,.06);
  font-family: Georgia, serif; pointer-events: none;
}
.ch-bio-section-tag {
  display: inline-flex; align-items: center; gap: 8px;
  background: rgba(0,33,71,.07); color: var(--ch-navy);
  font-size: .76rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
  padding: 6px 16px; border-radius: 50px; margin-bottom: 16px;
}
.ch-bio-card p {
  font-size: 1.02rem; color: var(--ch-text); line-height: 1.85; margin-bottom: 0;
}

/* ── Chairman Message ──────────────────────────────────────────────────────── */
.ch-message-section {
  padding: 80px 0;
  background: var(--ch-white);
  position: relative;
  overflow: hidden;
}
.ch-message-section::before {
  content: '';
  position: absolute; top: 0; left: 0; right: 0; height: 4px;
  background: linear-gradient(90deg, var(--ch-navy), var(--ch-blue), var(--ch-gold));
}
.ch-message-card {
  background: linear-gradient(135deg, #001e45 0%, #002f68 100%);
  border-radius: 24px; padding: 56px 56px 48px;
  position: relative; overflow: hidden;
  box-shadow: 0 16px 56px rgba(0,33,71,.22);
}
@media (max-width: 767.98px) { .ch-message-card { padding: 36px 24px 32px; } }
.ch-message-card::before {
  content: '';
  position: absolute; top: -60px; right: -60px;
  width: 280px; height: 280px; border-radius: 50%;
  background: rgba(255,184,28,.08); pointer-events: none;
}
.ch-message-card::after {
  content: '';
  position: absolute; bottom: -80px; left: -40px;
  width: 220px; height: 220px; border-radius: 50%;
  background: rgba(26,79,175,.3); pointer-events: none;
}
.ch-msg-header { position: relative; z-index: 1; margin-bottom: 32px; }
.ch-msg-icon {
  width: 56px; height: 56px; border-radius: 14px;
  background: rgba(255,184,28,.2); border: 1px solid rgba(255,184,28,.4);
  display: flex; align-items: center; justify-content: center; margin-bottom: 16px;
}
.ch-msg-icon i { color: var(--ch-gold); font-size: 1.4rem; }
.ch-msg-title {
  font-size: clamp(1.35rem, 3vw, 1.9rem); font-weight: 800;
  color: #fff; margin-bottom: 4px;
}
.ch-msg-subtitle { color: rgba(255,255,255,.6); font-size: .9rem; }
.ch-msg-divider {
  height: 2px; width: 60px;
  background: linear-gradient(90deg, var(--ch-gold), transparent);
  margin: 20px 0 28px;
}
.ch-msg-body { position: relative; z-index: 1; }
.ch-msg-body p {
  color: rgba(255,255,255,.88); font-size: 1rem; line-height: 1.9;
  margin-bottom: 1.4rem;
}
.ch-msg-body p:last-child { margin-bottom: 0; }
.ch-msg-sig {
  position: relative; z-index: 1;
  margin-top: 36px; padding-top: 24px;
  border-top: 1px solid rgba(255,255,255,.12);
  display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
}
.ch-msg-sig-photo {
  width: 54px; height: 54px; border-radius: 50%; object-fit: cover;
  object-position: top center;
  border: 2px solid rgba(255,184,28,.4); flex-shrink: 0;
}
.ch-msg-sig-placeholder {
  width: 54px; height: 54px; border-radius: 50%; flex-shrink: 0;
  background: rgba(255,255,255,.1); display: flex; align-items: center; justify-content: center;
  border: 2px solid rgba(255,184,28,.3);
}
.ch-msg-sig-placeholder i { color: rgba(255,255,255,.6); font-size: 1.2rem; }
.ch-msg-sig-name { color: #fff; font-weight: 700; font-size: 1rem; margin-bottom: 2px; }
.ch-msg-sig-role { color: var(--ch-gold); font-size: .8rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; }

/* ── PS Card ───────────────────────────────────────────────────────────────── */
.ch-ps-section {
  background: var(--ch-white);
  padding: 70px 0;
}
.ch-ps-card {
  background: var(--ch-white); border-radius: 20px;
  box-shadow: var(--ch-shadow); overflow: hidden;
  border: 1px solid rgba(0,33,71,.06);
  transition: box-shadow var(--ch-trans), transform var(--ch-trans);
}
.ch-ps-card:hover { box-shadow: var(--ch-shadow-h); transform: translateY(-4px); }
.ch-ps-card-accent {
  height: 4px;
  background: linear-gradient(90deg, var(--ch-blue), var(--ch-gold));
}
.ch-ps-card-body { padding: 32px 36px; }
@media (max-width: 575.98px) { .ch-ps-card-body { padding: 24px 20px; } }
.ch-ps-photo {
  width: 80px; height: 80px; border-radius: 50%; object-fit: cover;
  object-position: top center;
  border: 3px solid #fff; box-shadow: 0 4px 18px rgba(0,33,71,.15);
  flex-shrink: 0;
}
.ch-ps-avatar {
  width: 80px; height: 80px; border-radius: 50%; flex-shrink: 0;
  background: linear-gradient(135deg, var(--ch-blue), #3b82f6);
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 4px 16px rgba(26,79,175,.25);
}
.ch-ps-avatar i { color: #fff; font-size: 1.7rem; }
.ch-ps-name { font-size: 1.2rem; font-weight: 700; color: var(--ch-navy); margin-bottom: 4px; }
.ch-ps-designation {
  display: inline-block; background: rgba(26,79,175,.1); color: var(--ch-blue);
  font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em;
  padding: 3px 12px; border-radius: 50px; margin-bottom: 0;
}

/* ── Section header shared ─────────────────────────────────────────────────── */
.ch-section-tag {
  display: inline-flex; align-items: center; gap: 8px;
  background: rgba(0,33,71,.07); color: var(--ch-navy);
  font-size: .76rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
  padding: 6px 16px; border-radius: 50px; margin-bottom: 12px;
}
.ch-section-tag .dot-sm {
  width: 6px; height: 6px; border-radius: 50%; background: var(--ch-gold);
}
.ch-section-title {
  font-size: clamp(1.5rem, 3.5vw, 2.2rem); font-weight: 800; color: var(--ch-navy);
  margin-bottom: 12px; line-height: 1.2;
}
.ch-section-subtitle {
  font-size: 1.02rem; color: var(--ch-muted); max-width: 540px; line-height: 1.7;
}
.ch-divider {
  width: 56px; height: 4px; border-radius: 2px;
  background: linear-gradient(90deg, var(--ch-gold), var(--ch-blue));
  margin: 14px 0 0;
}

/* ── Quick facts strip ─────────────────────────────────────────────────────── */
.ch-facts-strip {
  background: var(--ch-navy);
  padding: 24px 0;
}
.ch-fact-item {
  display: flex; align-items: center; gap: 12px;
  color: rgba(255,255,255,.85);
  padding: 10px 20px;
  border-right: 1px solid rgba(255,255,255,.12);
}
.ch-fact-item:last-child { border-right: none; }
.ch-fact-icon { color: var(--ch-gold); font-size: 1.2rem; flex-shrink: 0; }
.ch-fact-text strong { display: block; font-size: .92rem; font-weight: 700; color: #fff; }
.ch-fact-text span { font-size: .77rem; color: rgba(255,255,255,.55); letter-spacing: .04em; text-transform: uppercase; }

@media (max-width: 767.98px) {
  .ch-fact-item { border-right: none; border-bottom: 1px solid rgba(255,255,255,.10); }
  .ch-fact-item:last-child { border-bottom: none; }
}

/* ── Fade-in animations ────────────────────────────────────────────────────── */
.ch-fade { opacity: 0; transform: translateY(30px); transition: opacity .7s ease, transform .7s ease; }
.ch-fade.visible { opacity: 1; transform: translateY(0); }
.ch-fade-delay-1 { transition-delay: .1s; }
.ch-fade-delay-2 { transition-delay: .2s; }
</style>

</head>
<body>

<?php include __DIR__ . '/includes/header-top.php'; ?>
<?php include __DIR__ . '/includes/nav-menu.php'; ?>

<!-- ══════════════ HERO ══════════════ -->
<section class="ch-hero">
    <div class="ch-circle c1"></div>
    <div class="ch-circle c2"></div>
    <div class="ch-circle c3"></div>
    <div class="container" style="position:relative;z-index:2;">
        <div class="breadcrumb-nav">
            <a href="/index.php">Home</a>
            <span class="sep">/</span>
            <a href="#">About</a>
            <span class="sep">/</span>
            <span style="color:rgba(255,255,255,.85);"><?= fh(cs($s,'hero_title','Office of the Chairman')) ?></span>
        </div>
        <div class="ch-hero-tag">
            <span class="dot"></span>
            Prime University · Bangladesh
        </div>
        <h1>
            <?php
            $htitle = cs($s, 'hero_title', 'Office of the Chairman');
            $words  = explode(' ', $htitle);
            $last   = array_pop($words);
            echo fh(implode(' ', $words)) . ' <span class="accent">' . fh($last) . '</span>';
            ?>
        </h1>
        <?php if (cs($s, 'hero_subtitle', '') !== ''): ?>
        <p class="ch-designation" style="background:rgba(255,184,28,.18);color:var(--ch-gold);margin-bottom:16px;">
            <?= fh(cs($s,'hero_subtitle','')) ?>
        </p>
        <?php endif; ?>
        <?php if (cs($s, 'hero_intro', '') !== ''): ?>
        <p class="hero-sub"><?= fh(cs($s,'hero_intro','')) ?></p>
        <?php endif; ?>
    </div>
</section>

<!-- ══════════════ QUICK FACTS STRIP ══════════════ -->
<div class="ch-facts-strip">
    <div class="container">
        <div class="row g-0 justify-content-center">
            <?php if (cs($s,'ch_phone','') !== ''): ?>
            <div class="col-sm-6 col-md-4 col-lg-3">
                <div class="ch-fact-item">
                    <i class="fas fa-phone ch-fact-icon"></i>
                    <div class="ch-fact-text">
                        <strong><?= fh(cs($s,'ch_phone','')) ?></strong>
                        <span>Chairman Direct Line</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php if (cs($s,'ch_email_1','') !== ''): ?>
            <div class="col-sm-6 col-md-4 col-lg-4">
                <div class="ch-fact-item">
                    <i class="fas fa-envelope ch-fact-icon"></i>
                    <div class="ch-fact-text">
                        <strong><?= fh(cs($s,'ch_email_1','')) ?></strong>
                        <span>Official Email</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php if (cs($s,'ps_phone','') !== ''): ?>
            <div class="col-sm-6 col-md-4 col-lg-3">
                <div class="ch-fact-item">
                    <i class="fas fa-user ch-fact-icon"></i>
                    <div class="ch-fact-text">
                        <strong><?= fh(cs($s,'ps_phone','')) ?></strong>
                        <span>PS to Chairman</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ══════════════ CHAIRMAN PROFILE ══════════════ -->
<section class="ch-profile-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-xl-10 col-lg-11">
                <div class="ch-profile-card ch-fade">
                    <div class="ch-card-accent"></div>
                    <div class="ch-card-body">
                        <div class="row align-items-center g-4">
                            <!-- Photo -->
                            <div class="col-md-auto text-center text-md-start">
                                <div class="ch-photo-wrap">
                                    <?php if ($ch_photo_url): ?>
                                    <img src="<?= fh($ch_photo_url) ?>"
                                         alt="<?= fh(cs($s,'ch_name','Chairman')) ?>">
                                    <?php else: ?>
                                    <div class="ch-photo-placeholder">
                                        <i class="fas fa-gavel"></i>
                                    </div>
                                    <?php endif; ?>
                                    <div class="ch-photo-badge">
                                        <i class="fas fa-star"></i>
                                    </div>
                                </div>
                            </div>
                            <!-- Info -->
                            <div class="col-md">
                                <h2 class="ch-name"><?= fh(cs($s,'ch_name','Anwar Kamal Pasha')) ?></h2>
                                <div class="ch-designation">
                                    <i class="fas fa-gavel me-1"></i>
                                    <?= fh(cs($s,'ch_title','Chairman, BOT')) ?>
                                </div>
                                <ul class="ch-contact-list">
                                    <?php if (cs($s,'ch_email_1','') !== ''): ?>
                                    <li>
                                        <div class="ch-contact-icon"><i class="fas fa-envelope"></i></div>
                                        <div>
                                            <div style="font-size:.72rem;color:var(--ch-muted);text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:2px;">Primary Email</div>
                                            <a href="mailto:<?= fh(cs($s,'ch_email_1','')) ?>"><?= fh(cs($s,'ch_email_1','')) ?></a>
                                            <?php if (cs($s,'ch_email_2','') !== ''): ?>
                                            &nbsp;&middot;&nbsp;
                                            <a href="mailto:<?= fh(cs($s,'ch_email_2','')) ?>"><?= fh(cs($s,'ch_email_2','')) ?></a>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                    <?php endif; ?>
                                    <?php if (cs($s,'ch_phone','') !== ''): ?>
                                    <li>
                                        <div class="ch-contact-icon"><i class="fas fa-phone"></i></div>
                                        <div>
                                            <div style="font-size:.72rem;color:var(--ch-muted);text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:2px;">Phone</div>
                                            <a href="tel:<?= fh(cs($s,'ch_phone','')) ?>"><?= fh(cs($s,'ch_phone','')) ?></a>
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

<!-- ══════════════ BIO ══════════════ -->
<?php if (cs($s,'ch_bio','') !== ''): ?>
<section class="ch-bio-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-xl-10 col-lg-11">
                <div class="ch-bio-section-tag ch-fade">
                    <i class="fas fa-user-tie"></i> Profile
                </div>
                <div class="ch-bio-card ch-fade ch-fade-delay-1">
                    <p><?= nl2br(fh(cs($s,'ch_bio',''))) ?></p>
                </div>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ══════════════ MESSAGE ══════════════ -->
<?php if (!empty($message_paragraphs)): ?>
<section class="ch-message-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-xl-10 col-lg-11">
                <!-- Section header -->
                <div class="text-center mb-5 ch-fade">
                    <div class="ch-section-tag" style="display:inline-flex;">
                        <span class="dot-sm"></span>
                        Leadership Message
                    </div>
                    <h2 class="ch-section-title"><?= fh(cs($s,'message_title','Message from the Chairman')) ?></h2>
                    <div class="ch-divider mx-auto"></div>
                </div>
                <!-- Message card -->
                <div class="ch-message-card ch-fade ch-fade-delay-1">
                    <div class="ch-msg-header">
                        <div class="ch-msg-icon"><i class="fas fa-quote-left"></i></div>
                        <h3 class="ch-msg-title"><?= fh(cs($s,'message_title','Message from the Chairman')) ?></h3>
                        <p class="ch-msg-subtitle"><?= fh(cs($s,'ch_name','Chairman')) ?> &middot; Prime University</p>
                        <div class="ch-msg-divider"></div>
                    </div>
                    <div class="ch-msg-body">
                        <?php foreach ($message_paragraphs as $para): ?>
                        <p><?= fh($para) ?></p>
                        <?php endforeach; ?>
                    </div>
                    <!-- Signature -->
                    <div class="ch-msg-sig">
                        <?php if ($ch_photo_url): ?>
                        <img src="<?= fh($ch_photo_url) ?>"
                             class="ch-msg-sig-photo"
                             alt="<?= fh(cs($s,'ch_name','')) ?>">
                        <?php else: ?>
                        <div class="ch-msg-sig-placeholder">
                            <i class="fas fa-gavel"></i>
                        </div>
                        <?php endif; ?>
                        <div>
                            <div class="ch-msg-sig-name"><?= fh(cs($s,'ch_name','Anwar Kamal Pasha')) ?></div>
                            <div class="ch-msg-sig-role"><?= fh(cs($s,'ch_title','Chairman, BOT')) ?> &mdash; Prime University</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ══════════════ PS PROFILE ══════════════ -->
<?php if (cs($s,'ps_name','') !== ''): ?>
<section class="ch-ps-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-xl-10 col-lg-11">
                <!-- Header -->
                <div class="mb-5 ch-fade">
                    <div class="ch-section-tag">
                        <span class="dot-sm"></span>
                        Office Staff
                    </div>
                    <h2 class="ch-section-title">Personal Secretary</h2>
                    <div class="ch-divider"></div>
                </div>
                <!-- PS Card -->
                <div class="row justify-content-center">
                    <div class="col-md-8 col-lg-7">
                        <div class="ch-ps-card ch-fade ch-fade-delay-1">
                            <div class="ch-ps-card-accent"></div>
                            <div class="ch-ps-card-body">
                                <div class="d-flex align-items-center gap-4 mb-4">
                                    <?php if ($ps_photo_url): ?>
                                    <img src="<?= fh($ps_photo_url) ?>"
                                         alt="<?= fh(cs($s,'ps_name','')) ?>"
                                         class="ch-ps-photo">
                                    <?php else: ?>
                                    <div class="ch-ps-avatar">
                                        <i class="fas fa-user"></i>
                                    </div>
                                    <?php endif; ?>
                                    <div>
                                        <div class="ch-ps-name"><?= fh(cs($s,'ps_name','')) ?></div>
                                        <div class="ch-ps-designation"><?= fh(cs($s,'ps_title','PS to Chairman, BOT')) ?></div>
                                    </div>
                                </div>
                                <ul class="ch-contact-list">
                                    <?php if (cs($s,'ps_email_1','') !== ''): ?>
                                    <li>
                                        <div class="ch-contact-icon"><i class="fas fa-envelope"></i></div>
                                        <div>
                                            <div style="font-size:.72rem;color:var(--ch-muted);text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:2px;">Email</div>
                                            <a href="mailto:<?= fh(cs($s,'ps_email_1','')) ?>"><?= fh(cs($s,'ps_email_1','')) ?></a>
                                            <?php if (cs($s,'ps_email_2','') !== ''): ?>
                                            &nbsp;&middot;&nbsp;
                                            <a href="mailto:<?= fh(cs($s,'ps_email_2','')) ?>"><?= fh(cs($s,'ps_email_2','')) ?></a>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                    <?php endif; ?>
                                    <?php if (cs($s,'ps_phone','') !== ''): ?>
                                    <li>
                                        <div class="ch-contact-icon"><i class="fas fa-phone"></i></div>
                                        <div>
                                            <div style="font-size:.72rem;color:var(--ch-muted);text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:2px;">Phone</div>
                                            <a href="tel:<?= fh(cs($s,'ps_phone','')) ?>"><?= fh(cs($s,'ps_phone','')) ?></a>
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

<?php include __DIR__ . '/includes/footer.php'; ?>
<?php include __DIR__ . '/includes/scripts.php'; ?>

<script>
/* ── Scroll-triggered fade-in ──────────────────────────────────────────────── */
(function () {
    const els = document.querySelectorAll('.ch-fade');
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
