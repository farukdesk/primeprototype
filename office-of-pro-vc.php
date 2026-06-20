<?php
require_once __DIR__ . '/includes/config.php';

/* ── Load all settings from DB ───────────────────────────────────────────── */
$s = [];
try {
    $db = front_db();
    if ($db) {
        $rows = $db->query('SELECT setting_key, setting_val FROM pvc_settings')->fetchAll();
        foreach ($rows as $r) $s[$r['setting_key']] = $r['setting_val'];
    }
} catch (Throwable $e) {}

/* ── Helper ──────────────────────────────────────────────────────────────── */
function pvs(array $s, string $key, string $default = ''): string {
    return isset($s[$key]) && $s[$key] !== '' ? $s[$key] : $default;
}

/* ── Redirect if unpublished ─────────────────────────────────────────────── */
if (pvs($s, 'is_published', '1') !== '1') {
    header('Location: /index.php');
    exit;
}

$page_title = pvs($s, 'hero_title', 'Office of the Pro Vice Chancellor') . ' – Prime University';
$meta_desc  = pvs($s, 'meta_description', 'Office of the Pro Vice Chancellor – Prime University');

/* ── Break message into paragraphs ──────────────────────────────────────── */
$message_paragraphs = [];
$raw_message = pvs($s, 'message_body', '');
if ($raw_message !== '') {
    $message_paragraphs = array_filter(
        array_map('trim', preg_split('/\n{2,}/', $raw_message)),
        fn($p) => $p !== ''
    );
}

$pvc_photo_url = !empty($s['pvc_photo'])
    ? ADMIN_UPLOAD_URL . '/office-of-pro-vc/' . $s['pvc_photo']
    : '';

$ps_photo_url = !empty($s['ps_photo'])
    ? ADMIN_UPLOAD_URL . '/office-of-pro-vc/' . $s['ps_photo']
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
   OFFICE OF PRO VICE CHANCELLOR – PAGE STYLES
   ════════════════════════════════════════════════════════ */
:root {
  --pvc-navy:    #002147;
  --pvc-gold:    #FFB81C;
  --pvc-blue:    #1a4faf;
  --pvc-red:     #D21034;
  --pvc-light:   #f4f7fb;
  --pvc-text:    #334155;
  --pvc-muted:   #64748b;
  --pvc-white:   #ffffff;
  --pvc-shadow:  0 8px 40px rgba(0,33,71,.10);
  --pvc-shadow-h:0 18px 60px rgba(0,33,71,.18);
  --pvc-trans:   .35s cubic-bezier(.4,0,.2,1);
}

/* ── Hero ──────────────────────────────────────────────────────────────────── */
.pvc-hero {
  background: linear-gradient(135deg, #001530 0%, #002f68 55%, #1a4faf 100%);
  padding: 110px 0 90px;
  position: relative;
  overflow: hidden;
}
.pvc-hero::before {
  content: '';
  position: absolute; inset: 0;
  background:
    radial-gradient(ellipse 60% 80% at 80% 50%, rgba(255,184,28,.10) 0%, transparent 70%),
    radial-gradient(ellipse 40% 60% at 10% 80%, rgba(26,79,175,.35) 0%, transparent 60%);
  pointer-events: none;
}
.pvc-hero .pvc-circle {
  position: absolute; border-radius: 50%; pointer-events: none;
  animation: pvcFloat 9s ease-in-out infinite;
}
.pvc-hero .pvc-circle.c1 { width:380px;height:380px;background:rgba(255,184,28,.07);top:-90px;right:-70px;animation-delay:0s; }
.pvc-hero .pvc-circle.c2 { width:200px;height:200px;background:rgba(255,255,255,.05);bottom:20px;left:4%;animation-delay:3s; }
.pvc-hero .pvc-circle.c3 { width:120px;height:120px;background:rgba(255,184,28,.10);top:35%;right:22%;animation-delay:1.5s; }
@keyframes pvcFloat {
  0%,100% { transform: translateY(0) scale(1); }
  50%      { transform: translateY(-22px) scale(1.05); }
}

.pvc-hero .breadcrumb-nav {
  display: flex; align-items: center; gap: 8px;
  font-size: .82rem; font-weight: 600; letter-spacing: .05em;
  text-transform: uppercase; color: rgba(255,255,255,.65); margin-bottom: 24px;
}
.pvc-hero .breadcrumb-nav a { color: var(--pvc-gold); text-decoration: none; }
.pvc-hero .breadcrumb-nav a:hover { color: #fff; }
.pvc-hero .breadcrumb-nav .sep { color: rgba(255,255,255,.35); }

.pvc-hero-tag {
  display: inline-flex; align-items: center; gap: 8px;
  background: rgba(255,184,28,.18); color: var(--pvc-gold);
  font-size: .77rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase;
  padding: 7px 18px; border-radius: 50px; border: 1px solid rgba(255,184,28,.35); margin-bottom: 20px;
}
.pvc-hero-tag .dot {
  width: 7px; height: 7px; border-radius: 50%; background: var(--pvc-gold);
  animation: pvcPulse 1.8s ease-in-out infinite;
}
@keyframes pvcPulse { 0%,100%{opacity:1;transform:scale(1);} 50%{opacity:.5;transform:scale(1.6);} }

.pvc-hero h1 {
  font-size: clamp(2rem, 5vw, 3.4rem);
  font-weight: 800; color: #fff; line-height: 1.15; margin-bottom: 18px;
}
.pvc-hero h1 .accent { color: var(--pvc-gold); }
.pvc-hero .hero-sub {
  font-size: clamp(.93rem, 1.8vw, 1.1rem);
  color: rgba(255,255,255,.78); max-width: 580px; line-height: 1.75;
}

/* ── Profile Card ──────────────────────────────────────────────────────────── */
.pvc-profile-section {
  padding: 80px 0 60px;
  background: var(--pvc-white);
}
.pvc-profile-card {
  background: var(--pvc-white);
  border-radius: 24px;
  box-shadow: var(--pvc-shadow);
  overflow: hidden;
  transition: box-shadow var(--pvc-trans);
  border: 1px solid rgba(0,33,71,.06);
}
.pvc-profile-card:hover { box-shadow: var(--pvc-shadow-h); }

.pvc-card-accent {
  height: 6px;
  background: linear-gradient(90deg, var(--pvc-navy) 0%, var(--pvc-blue) 50%, var(--pvc-gold) 100%);
}
.pvc-card-body { padding: 40px; }
@media (max-width: 767.98px) { .pvc-card-body { padding: 28px 20px; } }

.pvc-photo-wrap {
  position: relative; display: inline-block;
}
.pvc-photo-wrap img {
  width: 160px; height: 160px; border-radius: 50%; object-fit: cover;
  object-position: top center;
  border: 5px solid #fff; box-shadow: 0 8px 32px rgba(0,33,71,.18);
  display: block;
}
.pvc-photo-badge {
  position: absolute; bottom: 6px; right: 6px;
  width: 32px; height: 32px; border-radius: 50%;
  background: var(--pvc-gold); border: 3px solid #fff;
  display: flex; align-items: center; justify-content: center;
}
.pvc-photo-badge i { color: var(--pvc-navy); font-size: .7rem; }

.pvc-photo-placeholder {
  width: 160px; height: 160px; border-radius: 50%;
  background: linear-gradient(135deg, var(--pvc-navy), var(--pvc-blue));
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 8px 32px rgba(0,33,71,.18); border: 5px solid #fff;
}
.pvc-photo-placeholder i { color: rgba(255,255,255,.75); font-size: 3.5rem; }

.pvc-name { font-size: clamp(1.3rem, 3vw, 1.75rem); font-weight: 800; color: var(--pvc-navy); margin-bottom: 4px; }
.pvc-designation {
  display: inline-flex; align-items: center; gap: 8px;
  background: var(--pvc-navy); color: var(--pvc-gold);
  font-size: .8rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
  padding: 5px 16px; border-radius: 50px; margin-bottom: 24px;
}

.pvc-contact-list { list-style: none; padding: 0; margin: 0; }
.pvc-contact-list li {
  display: flex; align-items: flex-start; gap: 12px;
  padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: .9rem; color: var(--pvc-text);
}
.pvc-contact-list li:last-child { border-bottom: none; }
.pvc-contact-icon {
  width: 34px; height: 34px; border-radius: 8px; flex-shrink: 0;
  background: var(--pvc-light); display: flex; align-items: center; justify-content: center;
}
.pvc-contact-icon i { color: var(--pvc-blue); font-size: .85rem; }
.pvc-contact-list a { color: var(--pvc-blue); text-decoration: none; transition: color var(--pvc-trans); }
.pvc-contact-list a:hover { color: var(--pvc-navy); text-decoration: underline; }

/* ── Bio strip ─────────────────────────────────────────────────────────────── */
.pvc-bio-section {
  background: var(--pvc-light);
  padding: 70px 0;
}
.pvc-bio-card {
  background: var(--pvc-white); border-radius: 20px; padding: 44px 48px;
  box-shadow: var(--pvc-shadow); border-left: 5px solid var(--pvc-gold);
  position: relative;
}
@media (max-width: 767.98px) { .pvc-bio-card { padding: 28px 22px; } }
.pvc-bio-card::before {
  content: '\201C';
  position: absolute; top: 16px; left: 28px;
  font-size: 6rem; line-height: 1; color: rgba(0,33,71,.06);
  font-family: Georgia, serif; pointer-events: none;
}
.pvc-bio-section-tag {
  display: inline-flex; align-items: center; gap: 8px;
  background: rgba(0,33,71,.07); color: var(--pvc-navy);
  font-size: .76rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
  padding: 6px 16px; border-radius: 50px; margin-bottom: 16px;
}
.pvc-bio-card p {
  font-size: 1.02rem; color: var(--pvc-text); line-height: 1.85; margin-bottom: 0;
}

/* ── Message ───────────────────────────────────────────────────────────────── */
.pvc-message-section {
  padding: 80px 0;
  background: var(--pvc-white);
  position: relative;
  overflow: hidden;
}
.pvc-message-section::before {
  content: '';
  position: absolute; top: 0; left: 0; right: 0; height: 4px;
  background: linear-gradient(90deg, var(--pvc-navy), var(--pvc-blue), var(--pvc-gold));
}
.pvc-message-card {
  background: linear-gradient(135deg, #001e45 0%, #002f68 100%);
  border-radius: 24px; padding: 56px 56px 48px;
  position: relative; overflow: hidden;
  box-shadow: 0 16px 56px rgba(0,33,71,.22);
}
@media (max-width: 767.98px) { .pvc-message-card { padding: 36px 24px 32px; } }
.pvc-message-card::before {
  content: '';
  position: absolute; top: -60px; right: -60px;
  width: 280px; height: 280px; border-radius: 50%;
  background: rgba(255,184,28,.08); pointer-events: none;
}
.pvc-message-card::after {
  content: '';
  position: absolute; bottom: -80px; left: -40px;
  width: 220px; height: 220px; border-radius: 50%;
  background: rgba(26,79,175,.3); pointer-events: none;
}
.pvc-msg-header { position: relative; z-index: 1; margin-bottom: 32px; }
.pvc-msg-icon {
  width: 56px; height: 56px; border-radius: 14px;
  background: rgba(255,184,28,.2); border: 1px solid rgba(255,184,28,.4);
  display: flex; align-items: center; justify-content: center; margin-bottom: 16px;
}
.pvc-msg-icon i { color: var(--pvc-gold); font-size: 1.4rem; }
.pvc-msg-title {
  font-size: clamp(1.35rem, 3vw, 1.9rem); font-weight: 800;
  color: #fff; margin-bottom: 4px;
}
.pvc-msg-subtitle { color: rgba(255,255,255,.6); font-size: .9rem; }
.pvc-msg-divider {
  height: 2px; width: 60px;
  background: linear-gradient(90deg, var(--pvc-gold), transparent);
  margin: 20px 0 28px;
}
.pvc-msg-body { position: relative; z-index: 1; }
.pvc-msg-body p {
  color: rgba(255,255,255,.88); font-size: 1rem; line-height: 1.9;
  margin-bottom: 1.4rem;
}
.pvc-msg-body p:last-child { margin-bottom: 0; }
.pvc-msg-sig {
  position: relative; z-index: 1;
  margin-top: 36px; padding-top: 24px;
  border-top: 1px solid rgba(255,255,255,.12);
  display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
}
.pvc-msg-sig-photo {
  width: 54px; height: 54px; border-radius: 50%; object-fit: cover;
  object-position: top center;
  border: 2px solid rgba(255,184,28,.4); flex-shrink: 0;
}
.pvc-msg-sig-placeholder {
  width: 54px; height: 54px; border-radius: 50%; flex-shrink: 0;
  background: rgba(255,255,255,.1); display: flex; align-items: center; justify-content: center;
  border: 2px solid rgba(255,184,28,.3);
}
.pvc-msg-sig-placeholder i { color: rgba(255,255,255,.6); font-size: 1.2rem; }
.pvc-msg-sig-name { color: #fff; font-weight: 700; font-size: 1rem; margin-bottom: 2px; }
.pvc-msg-sig-role { color: var(--pvc-gold); font-size: .8rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; }

/* ── PS Card ───────────────────────────────────────────────────────────────── */
.pvc-ps-section {
  background: var(--pvc-white);
  padding: 70px 0;
}
.pvc-ps-card {
  background: var(--pvc-white); border-radius: 20px;
  box-shadow: var(--pvc-shadow); overflow: hidden;
  border: 1px solid rgba(0,33,71,.06);
  transition: box-shadow var(--pvc-trans), transform var(--pvc-trans);
}
.pvc-ps-card:hover { box-shadow: var(--pvc-shadow-h); transform: translateY(-4px); }
.pvc-ps-card-accent {
  height: 4px;
  background: linear-gradient(90deg, var(--pvc-blue), var(--pvc-gold));
}
.pvc-ps-card-body { padding: 32px 36px; }
@media (max-width: 575.98px) { .pvc-ps-card-body { padding: 24px 20px; } }
.pvc-ps-photo {
  width: 80px; height: 80px; border-radius: 50%; object-fit: cover;
  object-position: top center;
  border: 3px solid #fff; box-shadow: 0 4px 18px rgba(0,33,71,.15);
  flex-shrink: 0;
}
.pvc-ps-avatar {
  width: 80px; height: 80px; border-radius: 50%; flex-shrink: 0;
  background: linear-gradient(135deg, var(--pvc-blue), #3b82f6);
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 4px 16px rgba(26,79,175,.25);
}
.pvc-ps-avatar i { color: #fff; font-size: 1.7rem; }
.pvc-ps-name { font-size: 1.2rem; font-weight: 700; color: var(--pvc-navy); margin-bottom: 4px; }
.pvc-ps-designation {
  display: inline-block; background: rgba(26,79,175,.1); color: var(--pvc-blue);
  font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em;
  padding: 3px 12px; border-radius: 50px;
}

/* ── Section header shared ─────────────────────────────────────────────────── */
.pvc-section-tag {
  display: inline-flex; align-items: center; gap: 8px;
  background: rgba(0,33,71,.07); color: var(--pvc-navy);
  font-size: .76rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
  padding: 6px 16px; border-radius: 50px; margin-bottom: 12px;
}
.pvc-section-tag .dot-sm {
  width: 6px; height: 6px; border-radius: 50%; background: var(--pvc-gold);
}
.pvc-section-title {
  font-size: clamp(1.5rem, 3.5vw, 2.2rem); font-weight: 800; color: var(--pvc-navy);
  margin-bottom: 12px; line-height: 1.2;
}
.pvc-divider {
  width: 56px; height: 4px; border-radius: 2px;
  background: linear-gradient(90deg, var(--pvc-gold), var(--pvc-blue));
  margin: 14px 0 0;
}

/* ── Quick facts strip ─────────────────────────────────────────────────────── */
.pvc-facts-strip {
  background: var(--pvc-navy);
  padding: 24px 0;
}
.pvc-fact-item {
  display: flex; align-items: center; gap: 12px;
  color: rgba(255,255,255,.85);
  padding: 10px 20px;
  border-right: 1px solid rgba(255,255,255,.12);
}
.pvc-fact-item:last-child { border-right: none; }
.pvc-fact-icon { color: var(--pvc-gold); font-size: 1.2rem; flex-shrink: 0; }
.pvc-fact-text strong { display: block; font-size: .92rem; font-weight: 700; color: #fff; }
.pvc-fact-text span { font-size: .77rem; color: rgba(255,255,255,.55); letter-spacing: .04em; text-transform: uppercase; }

@media (max-width: 767.98px) {
  .pvc-fact-item { border-right: none; border-bottom: 1px solid rgba(255,255,255,.10); }
  .pvc-fact-item:last-child { border-bottom: none; }
}

/* ── Fade-in animations ────────────────────────────────────────────────────── */
.pvc-fade { opacity: 0; transform: translateY(30px); transition: opacity .7s ease, transform .7s ease; }
.pvc-fade.visible { opacity: 1; transform: translateY(0); }
.pvc-fade-delay-1 { transition-delay: .1s; }
.pvc-fade-delay-2 { transition-delay: .2s; }
</style>

</head>
<body>

<?php include __DIR__ . '/includes/header-top.php'; ?>
<?php include __DIR__ . '/includes/nav-menu.php'; ?>

<!-- ══════════════ HERO ══════════════ -->
<section class="pvc-hero">
    <div class="pvc-circle c1"></div>
    <div class="pvc-circle c2"></div>
    <div class="pvc-circle c3"></div>
    <div class="container" style="position:relative;z-index:2;">
        <div class="breadcrumb-nav">
            <a href="/index.php">Home</a>
            <span class="sep">/</span>
            <a href="#">About</a>
            <span class="sep">/</span>
            <span style="color:rgba(255,255,255,.85);"><?= fh(pvs($s,'hero_title','Office of the Pro Vice Chancellor')) ?></span>
        </div>
        <div class="pvc-hero-tag">
            <span class="dot"></span>
            Prime University · Bangladesh
        </div>
        <h1>
            <?php
            $htitle = pvs($s, 'hero_title', 'Office of the Pro Vice Chancellor');
            $words  = explode(' ', $htitle);
            $last   = array_pop($words);
            echo fh(implode(' ', $words)) . ' <span class="accent">' . fh($last) . '</span>';
            ?>
        </h1>
        <?php if (pvs($s, 'hero_subtitle', '') !== ''): ?>
        <p class="pvc-designation" style="background:rgba(255,184,28,.18);color:var(--pvc-gold);margin-bottom:16px;">
            <?= fh(pvs($s,'hero_subtitle','')) ?>
        </p>
        <?php endif; ?>
        <?php if (pvs($s, 'hero_intro', '') !== ''): ?>
        <p class="hero-sub"><?= fh(pvs($s,'hero_intro','')) ?></p>
        <?php endif; ?>
    </div>
</section>

<!-- ══════════════ QUICK FACTS STRIP ══════════════ -->
<div class="pvc-facts-strip">
    <div class="container">
        <div class="row g-0 justify-content-center">
            <?php if (pvs($s,'pvc_phone','') !== ''): ?>
            <div class="col-sm-6 col-md-4 col-lg-3">
                <div class="pvc-fact-item">
                    <i class="fas fa-phone pvc-fact-icon"></i>
                    <div class="pvc-fact-text">
                        <strong><?= fh(pvs($s,'pvc_phone','')) ?></strong>
                        <span>Pro VC Direct Line</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php if (pvs($s,'pvc_email_1','') !== ''): ?>
            <div class="col-sm-6 col-md-4 col-lg-4">
                <div class="pvc-fact-item">
                    <i class="fas fa-envelope pvc-fact-icon"></i>
                    <div class="pvc-fact-text">
                        <strong><?= fh(pvs($s,'pvc_email_1','')) ?></strong>
                        <span>Official Email</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php if (pvs($s,'ps_phone','') !== ''): ?>
            <div class="col-sm-6 col-md-4 col-lg-3">
                <div class="pvc-fact-item">
                    <i class="fas fa-user pvc-fact-icon"></i>
                    <div class="pvc-fact-text">
                        <strong><?= fh(pvs($s,'ps_phone','')) ?></strong>
                        <span>PS to Pro VC</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ══════════════ PRO VC PROFILE ══════════════ -->
<section class="pvc-profile-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-xl-10 col-lg-11">
                <div class="pvc-profile-card pvc-fade">
                    <div class="pvc-card-accent"></div>
                    <div class="pvc-card-body">
                        <div class="row align-items-center g-4">
                            <!-- Photo -->
                            <div class="col-md-auto text-center text-md-start">
                                <div class="pvc-photo-wrap">
                                    <?php if ($pvc_photo_url): ?>
                                    <img src="<?= fh($pvc_photo_url) ?>"
                                         alt="<?= fh(pvs($s,'pvc_name','Pro Vice Chancellor')) ?>">
                                    <?php else: ?>
                                    <div class="pvc-photo-placeholder">
                                        <i class="fas fa-user-graduate"></i>
                                    </div>
                                    <?php endif; ?>
                                    <div class="pvc-photo-badge">
                                        <i class="fas fa-star"></i>
                                    </div>
                                </div>
                            </div>
                            <!-- Info -->
                            <div class="col-md">
                                <h2 class="pvc-name"><?= fh(pvs($s,'pvc_name','Prof. Dr. Abdur Rahman')) ?></h2>
                                <div class="pvc-designation">
                                    <i class="fas fa-user-graduate me-1"></i>
                                    <?= fh(pvs($s,'pvc_title','Pro Vice Chancellor (Acting)')) ?>
                                </div>
                                <ul class="pvc-contact-list">
                                    <?php if (pvs($s,'pvc_email_1','') !== ''): ?>
                                    <li>
                                        <div class="pvc-contact-icon"><i class="fas fa-envelope"></i></div>
                                        <div>
                                            <div style="font-size:.72rem;color:var(--pvc-muted);text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:2px;">Primary Email</div>
                                            <a href="mailto:<?= fh(pvs($s,'pvc_email_1','')) ?>"><?= fh(pvs($s,'pvc_email_1','')) ?></a>
                                            <?php if (pvs($s,'pvc_email_2','') !== ''): ?>
                                            &nbsp;&middot;&nbsp;
                                            <a href="mailto:<?= fh(pvs($s,'pvc_email_2','')) ?>"><?= fh(pvs($s,'pvc_email_2','')) ?></a>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                    <?php endif; ?>
                                    <?php if (pvs($s,'pvc_phone','') !== ''): ?>
                                    <li>
                                        <div class="pvc-contact-icon"><i class="fas fa-phone"></i></div>
                                        <div>
                                            <div style="font-size:.72rem;color:var(--pvc-muted);text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:2px;">Phone</div>
                                            <a href="tel:<?= fh(pvs($s,'pvc_phone','')) ?>"><?= fh(pvs($s,'pvc_phone','')) ?></a>
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
<?php if (pvs($s,'pvc_bio','') !== ''): ?>
<section class="pvc-bio-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-xl-10 col-lg-11">
                <div class="pvc-bio-section-tag pvc-fade">
                    <i class="fas fa-user-graduate"></i> Profile
                </div>
                <div class="pvc-bio-card pvc-fade pvc-fade-delay-1">
                    <p><?= nl2br(fh(pvs($s,'pvc_bio',''))) ?></p>
                </div>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ══════════════ MESSAGE ══════════════ -->
<?php if (!empty($message_paragraphs)): ?>
<section class="pvc-message-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-xl-10 col-lg-11">
                <!-- Section header -->
                <div class="text-center mb-5 pvc-fade">
                    <div class="pvc-section-tag" style="display:inline-flex;">
                        <span class="dot-sm"></span>
                        Leadership Message
                    </div>
                    <h2 class="pvc-section-title"><?= fh(pvs($s,'message_title','Message from the Pro Vice Chancellor')) ?></h2>
                    <div class="pvc-divider mx-auto"></div>
                </div>
                <!-- Message card -->
                <div class="pvc-message-card pvc-fade pvc-fade-delay-1">
                    <div class="pvc-msg-header">
                        <div class="pvc-msg-icon"><i class="fas fa-quote-left"></i></div>
                        <h3 class="pvc-msg-title"><?= fh(pvs($s,'message_title','Message from the Pro Vice Chancellor')) ?></h3>
                        <p class="pvc-msg-subtitle"><?= fh(pvs($s,'pvc_name','Pro Vice Chancellor')) ?> &middot; Prime University</p>
                        <div class="pvc-msg-divider"></div>
                    </div>
                    <div class="pvc-msg-body">
                        <?php foreach ($message_paragraphs as $para): ?>
                        <p><?= fh($para) ?></p>
                        <?php endforeach; ?>
                    </div>
                    <!-- Signature -->
                    <div class="pvc-msg-sig">
                        <?php if ($pvc_photo_url): ?>
                        <img src="<?= fh($pvc_photo_url) ?>"
                             class="pvc-msg-sig-photo"
                             alt="<?= fh(pvs($s,'pvc_name','')) ?>">
                        <?php else: ?>
                        <div class="pvc-msg-sig-placeholder">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <?php endif; ?>
                        <div>
                            <div class="pvc-msg-sig-name"><?= fh(pvs($s,'pvc_name','Prof. Dr. Abdur Rahman')) ?></div>
                            <div class="pvc-msg-sig-role"><?= fh(pvs($s,'pvc_title','Pro Vice Chancellor (Acting)')) ?> &mdash; Prime University</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ══════════════ PS PROFILE ══════════════ -->
<?php if (pvs($s,'ps_name','') !== ''): ?>
<section class="pvc-ps-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-xl-10 col-lg-11">
                <!-- Header -->
                <div class="mb-5 pvc-fade">
                    <div class="pvc-section-tag">
                        <span class="dot-sm"></span>
                        Office Staff
                    </div>
                    <h2 class="pvc-section-title">Personal Secretary</h2>
                    <div class="pvc-divider"></div>
                </div>
                <!-- PS Card -->
                <div class="row justify-content-center">
                    <div class="col-md-8 col-lg-7">
                        <div class="pvc-ps-card pvc-fade pvc-fade-delay-1">
                            <div class="pvc-ps-card-accent"></div>
                            <div class="pvc-ps-card-body">
                                <div class="d-flex align-items-center gap-4 mb-4">
                                    <?php if ($ps_photo_url): ?>
                                    <img src="<?= fh($ps_photo_url) ?>"
                                         alt="<?= fh(pvs($s,'ps_name','')) ?>"
                                         class="pvc-ps-photo">
                                    <?php else: ?>
                                    <div class="pvc-ps-avatar">
                                        <i class="fas fa-user"></i>
                                    </div>
                                    <?php endif; ?>
                                    <div>
                                        <div class="pvc-ps-name"><?= fh(pvs($s,'ps_name','')) ?></div>
                                        <div class="pvc-ps-designation"><?= fh(pvs($s,'ps_title','PS to Pro Vice Chancellor')) ?></div>
                                    </div>
                                </div>
                                <ul class="pvc-contact-list">
                                    <?php if (pvs($s,'ps_email_1','') !== ''): ?>
                                    <li>
                                        <div class="pvc-contact-icon"><i class="fas fa-envelope"></i></div>
                                        <div>
                                            <div style="font-size:.72rem;color:var(--pvc-muted);text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:2px;">Email</div>
                                            <a href="mailto:<?= fh(pvs($s,'ps_email_1','')) ?>"><?= fh(pvs($s,'ps_email_1','')) ?></a>
                                            <?php if (pvs($s,'ps_email_2','') !== ''): ?>
                                            &nbsp;&middot;&nbsp;
                                            <a href="mailto:<?= fh(pvs($s,'ps_email_2','')) ?>"><?= fh(pvs($s,'ps_email_2','')) ?></a>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                    <?php endif; ?>
                                    <?php if (pvs($s,'ps_phone','') !== ''): ?>
                                    <li>
                                        <div class="pvc-contact-icon"><i class="fas fa-phone"></i></div>
                                        <div>
                                            <div style="font-size:.72rem;color:var(--pvc-muted);text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:2px;">Phone</div>
                                            <a href="tel:<?= fh(pvs($s,'ps_phone','')) ?>"><?= fh(pvs($s,'ps_phone','')) ?></a>
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
    const els = document.querySelectorAll('.pvc-fade');
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
