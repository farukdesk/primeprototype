<?php
require_once __DIR__ . '/includes/config.php';

$page_title = 'PU At a Glance – Prime University';

/* ── Fetch faculties/departments from DB ──────────────────────────────────── */
$_departments = [];
try {
    $db = front_db();
    if ($db) {
        $_departments = $db->query(
            'SELECT id, name, slug, code, hero_icon, hero_subtitle
             FROM dept_departments WHERE is_active = 1
             ORDER BY name'
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
   <meta name="description" content="An overview of Prime University – history, leadership, campus facilities, faculties and key facts since its establishment in 2002.">
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
/* ════════════════════════════════════════════════════════
   PU AT A GLANCE – PAGE STYLES
   ════════════════════════════════════════════════════════ */

/* ── CSS Variables ────────────────────────────────────── */
:root {
  --pu-navy:  #002147;
  --pu-gold:  #FFB81C;
  --pu-blue:  #1a4faf;
  --pu-light: #f4f7fb;
  --pu-text:  #334155;
  --pu-white: #ffffff;
  --pu-radius: 16px;
  --pu-shadow: 0 8px 40px rgba(0,33,71,.10);
  --pu-shadow-hover: 0 16px 56px rgba(0,33,71,.18);
  --pu-trans: .35s cubic-bezier(.4,0,.2,1);
}

/* ── Preloader ────────────────────────────────────────── */
.preloader { background: var(--pu-navy); }

/* ── Hero ─────────────────────────────────────────────── */
.glance-hero {
  background: linear-gradient(135deg, #001530 0%, #002f68 55%, #1a4faf 100%);
  padding: 110px 0 90px;
  position: relative;
  overflow: hidden;
}
.glance-hero::before {
  content: '';
  position: absolute; inset: 0;
  background:
    radial-gradient(ellipse 60% 80% at 80% 50%, rgba(255,184,28,.10) 0%, transparent 70%),
    radial-gradient(ellipse 40% 60% at 10% 80%, rgba(26,79,175,.35) 0%, transparent 60%);
  pointer-events: none;
}
/* Animated floating circles */
.glance-hero .hero-circle {
  position: absolute;
  border-radius: 50%;
  opacity: .08;
  animation: heroFloat 8s ease-in-out infinite;
}
.glance-hero .hero-circle.c1 { width:360px; height:360px; background:var(--pu-gold);   top:-80px;  right:-60px;  animation-delay:0s; }
.glance-hero .hero-circle.c2 { width:180px; height:180px; background:var(--pu-white);  bottom:30px; left:5%;    animation-delay:3s; }
.glance-hero .hero-circle.c3 { width:100px; height:100px; background:var(--pu-gold);   top:30%;  right:20%;     animation-delay:1.5s; }
@keyframes heroFloat {
  0%,100% { transform: translateY(0)   scale(1);   }
  50%      { transform: translateY(-22px) scale(1.05); }
}

.glance-hero .breadcrumb-nav { display:flex; align-items:center; gap:8px; font-size:.82rem; font-weight:600; letter-spacing:.05em; text-transform:uppercase; color:rgba(255,255,255,.65); margin-bottom:24px; }
.glance-hero .breadcrumb-nav a { color:var(--pu-gold); text-decoration:none; transition:color var(--pu-trans); }
.glance-hero .breadcrumb-nav a:hover { color:#fff; }
.glance-hero .breadcrumb-nav .sep { color:rgba(255,255,255,.35); }

.glance-hero .hero-tag {
  display:inline-flex; align-items:center; gap:8px;
  background:rgba(255,184,28,.18);
  color:var(--pu-gold);
  font-size:.78rem; font-weight:700; letter-spacing:.12em; text-transform:uppercase;
  padding:7px 18px; border-radius:50px;
  border:1px solid rgba(255,184,28,.35);
  margin-bottom:20px;
}
.glance-hero .hero-tag .dot { width:7px; height:7px; border-radius:50%; background:var(--pu-gold); animation:pulse 1.8s ease-in-out infinite; }
@keyframes pulse { 0%,100%{opacity:1;transform:scale(1);} 50%{opacity:.5;transform:scale(1.6);} }

.glance-hero h1 {
  font-size: clamp(2.2rem, 5.5vw, 3.6rem);
  font-weight: 800;
  color: var(--pu-white);
  line-height: 1.12;
  margin-bottom: 20px;
  font-family: var(--it-ff-heading, 'Spartan-Bold', sans-serif);
}
.glance-hero h1 .accent { color:var(--pu-gold); }

.glance-hero p.hero-sub {
  font-size: clamp(.95rem, 2vw, 1.15rem);
  color: rgba(255,255,255,.78);
  max-width: 560px;
  line-height: 1.75;
  margin-bottom: 36px;
}

.glance-hero-cta {
  display: inline-flex; gap: 14px; flex-wrap: wrap;
}
.glance-hero-cta .btn-outline-light {
  padding: 12px 28px; border-radius: 50px;
  font-weight: 600; font-size: .92rem;
  border: 2px solid rgba(255,255,255,.5);
  color: #fff;
  transition: background var(--pu-trans), border-color var(--pu-trans);
}
.glance-hero-cta .btn-outline-light:hover { background:rgba(255,255,255,.12); border-color:#fff; }

.glance-hero-cta .btn-gold {
  padding: 12px 30px; border-radius: 50px;
  font-weight: 700; font-size: .92rem;
  background: var(--pu-gold); color: var(--pu-navy);
  border: 2px solid var(--pu-gold);
  transition: transform var(--pu-trans), box-shadow var(--pu-trans);
}
.glance-hero-cta .btn-gold:hover { transform:translateY(-3px); box-shadow:0 8px 24px rgba(255,184,28,.45); }

/* ── Quick Stats Bar ─────────────────────────────────── */
.glance-stats-bar {
  background: var(--pu-navy);
  padding: 0;
  position: relative;
  z-index: 5;
}
.glance-stats-bar .stats-inner {
  display: flex; flex-wrap: wrap; justify-content: center;
  border-bottom: 3px solid var(--pu-gold);
}
.glance-stat-item {
  flex: 1 1 160px;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  padding: 22px 16px;
  border-right: 1px solid rgba(255,255,255,.1);
  transition: background var(--pu-trans);
  text-align: center;
}
.glance-stat-item:last-child { border-right: none; }
.glance-stat-item:hover { background: rgba(255,255,255,.05); }
.glance-stat-item .stat-icon { font-size:1.45rem; color:var(--pu-gold); margin-bottom:6px; }
.glance-stat-item .stat-num  { font-size:1.7rem; font-weight:800; color:#fff; line-height:1; }
.glance-stat-item .stat-label { font-size:.72rem; font-weight:600; color:rgba(255,255,255,.6); text-transform:uppercase; letter-spacing:.07em; margin-top:4px; }

/* ── Section Common ──────────────────────────────────── */
.glance-section { padding: 90px 0; }
.glance-section.bg-light { background: var(--pu-light); }
.glance-section.bg-navy  { background: var(--pu-navy); }
.glance-section.bg-white { background: var(--pu-white); }

.section-tag {
  display: inline-block;
  font-size: .72rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase;
  color: var(--pu-blue);
  background: rgba(26,79,175,.1);
  padding: 5px 16px; border-radius: 50px;
  margin-bottom: 14px;
}
.section-tag.gold { color: var(--pu-gold); background: rgba(255,184,28,.15); }
.section-tag.white { color:#fff; background:rgba(255,255,255,.15); }

.section-title {
  font-size: clamp(1.7rem, 3.5vw, 2.5rem);
  font-weight: 800;
  color: var(--pu-navy);
  line-height: 1.2;
  margin-bottom: 16px;
  font-family: var(--it-ff-heading, 'Spartan-Bold', sans-serif);
}
.section-title.white { color: var(--pu-white); }
.section-divider {
  width: 56px; height: 4px;
  background: linear-gradient(90deg, var(--pu-gold), var(--pu-blue));
  border-radius: 2px;
  margin-bottom: 20px;
}
.section-desc { font-size: 1.02rem; color: var(--pu-text); line-height: 1.8; }
.section-desc.white { color: rgba(255,255,255,.78); }

/* ── About Overview ──────────────────────────────────── */
.glance-about-img-wrap {
  position: relative; border-radius: 20px; overflow: hidden;
  box-shadow: var(--pu-shadow);
}
.glance-about-img-wrap img { width:100%; height:380px; object-fit:cover; display:block; }
.glance-about-img-wrap .about-badge {
  position: absolute; bottom: 24px; left: 24px;
  background: var(--pu-gold); color: var(--pu-navy);
  font-weight: 800; font-size: 1rem;
  padding: 12px 20px; border-radius: 12px;
  display: flex; align-items: center; gap: 8px;
  box-shadow: 0 4px 16px rgba(255,184,28,.4);
}
.glance-about-feat {
  display: flex; gap: 14px; align-items: flex-start;
  background: #fff; border-radius: 14px;
  padding: 18px 20px; margin-bottom: 14px;
  box-shadow: 0 2px 16px rgba(0,33,71,.07);
  transition: transform var(--pu-trans), box-shadow var(--pu-trans);
}
.glance-about-feat:hover { transform:translateX(6px); box-shadow:0 8px 28px rgba(0,33,71,.13); }
.glance-about-feat .feat-icon {
  width:44px; height:44px; border-radius:10px; flex-shrink:0;
  display:flex; align-items:center; justify-content:center;
  font-size:1.1rem;
}
.glance-about-feat .feat-icon.navy  { background:rgba(0,33,71,.1); color:var(--pu-navy); }
.glance-about-feat .feat-icon.gold  { background:rgba(255,184,28,.15); color:#c89000; }
.glance-about-feat .feat-icon.blue  { background:rgba(26,79,175,.12); color:var(--pu-blue); }
.glance-about-feat h6 { font-size:.88rem; font-weight:700; margin-bottom:2px; color:var(--pu-navy); }
.glance-about-feat p  { font-size:.82rem; color:var(--pu-text); margin:0; line-height:1.5; }

/* ── Leadership Cards ────────────────────────────────── */
.leader-card {
  background: #fff;
  border-radius: 20px;
  overflow: hidden;
  box-shadow: var(--pu-shadow);
  transition: transform var(--pu-trans), box-shadow var(--pu-trans);
  height: 100%;
  position: relative;
}
.leader-card::before {
  content:''; position:absolute; top:0; left:0; right:0; height:4px;
  background: linear-gradient(90deg, var(--pu-navy), var(--pu-gold));
}
.leader-card:hover { transform:translateY(-8px); box-shadow:var(--pu-shadow-hover); }
.leader-card-top {
  background: linear-gradient(135deg, var(--pu-navy) 0%, #1a4faf 100%);
  padding: 36px 28px 0;
  display: flex; flex-direction: column; align-items: center;
  text-align: center;
}
.leader-avatar {
  width: 100px; height: 100px; border-radius: 50%;
  border: 4px solid var(--pu-gold);
  object-fit: cover; background: rgba(255,255,255,.15);
  display: flex; align-items: center; justify-content: center;
  font-size: 2.2rem; color: #fff;
  box-shadow: 0 4px 24px rgba(0,0,0,.25);
  margin-bottom: 16px;
}
.leader-avatar.icon-avatar { width:100px; height:100px; border-radius:50%; border:4px solid var(--pu-gold); background:rgba(255,255,255,.1); display:flex; align-items:center; justify-content:center; font-size:2.5rem; color:rgba(255,255,255,.9); }
.leader-card-top h4 { font-size:1.05rem; font-weight:800; color:#fff; margin-bottom:4px; }
.leader-card-top span.role-tag { font-size:.75rem; font-weight:600; color:var(--pu-gold); letter-spacing:.08em; text-transform:uppercase; padding-bottom:24px; display:block; }
.leader-card-body { padding: 24px 28px; text-align: center; }
.leader-card-body p { font-size:.88rem; color:var(--pu-text); line-height:1.7; margin:0; }

/* ── Messages Tabs ───────────────────────────────────── */
.msg-tabs-nav {
  display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 32px;
  border-bottom: 2px solid #e5e9f0;
  padding-bottom: 0;
}
.msg-tab-btn {
  background: none; border: none; outline: none; cursor: pointer;
  font-size: .92rem; font-weight: 700; color: var(--pu-text);
  padding: 12px 24px; border-radius: 8px 8px 0 0;
  transition: background var(--pu-trans), color var(--pu-trans);
  position: relative; bottom: -2px;
  border-bottom: 3px solid transparent;
}
.msg-tab-btn:hover { color: var(--pu-navy); background: rgba(0,33,71,.05); }
.msg-tab-btn.active { color: var(--pu-navy); border-bottom-color: var(--pu-gold); background: var(--pu-white); }

.msg-tab-pane { display: none; }
.msg-tab-pane.active { display: block; animation: fadeInUp .4s ease; }
@keyframes fadeInUp { from { opacity:0; transform:translateY(16px); } to { opacity:1; transform:none; } }

.msg-card {
  background: #fff; border-radius: 20px;
  box-shadow: var(--pu-shadow);
  padding: 40px;
  display: flex; gap: 32px; align-items: flex-start;
  position: relative; overflow: hidden;
}
.msg-card::after {
  content:'\f10e'; font-family:'Font Awesome 6 Pro'; font-weight:900;
  position:absolute; bottom:-10px; right:20px;
  font-size:6rem; color:rgba(0,33,71,.04); line-height:1;
}
.msg-person-side { text-align:center; flex-shrink:0; min-width:140px; }
.msg-person-avatar {
  width:120px; height:120px; border-radius:50%;
  border:4px solid var(--pu-navy); object-fit:cover;
  background:rgba(0,33,71,.1);
  display:flex; align-items:center; justify-content:center;
  font-size:2.8rem; color:var(--pu-navy); margin:0 auto 14px;
  box-shadow:0 4px 20px rgba(0,33,71,.15);
}
.msg-person-side h5 { font-size:.95rem; font-weight:800; color:var(--pu-navy); margin-bottom:4px; }
.msg-person-side span { font-size:.78rem; color:var(--pu-blue); font-weight:600; text-transform:uppercase; letter-spacing:.06em; }
.msg-content-side { flex: 1; }
.msg-content-side .quote-icon { font-size:1.8rem; color:var(--pu-gold); margin-bottom:12px; }
.msg-content-side p { font-size:.96rem; color:var(--pu-text); line-height:1.85; font-style:italic; margin-bottom:16px; }
.msg-content-side p:last-of-type { font-style:normal; }
.msg-signature { margin-top:12px; }
.msg-signature .sig-name { font-size:1rem; font-weight:800; color:var(--pu-navy); }
.msg-signature .sig-role { font-size:.8rem; color:#6b7280; }

@media (max-width: 767px) {
  .msg-card { flex-direction:column; gap:20px; padding:28px 20px; }
  .msg-person-side { min-width:auto; width:100%; }
  .msg-person-avatar { width:90px; height:90px; font-size:2rem; }
}

/* ── Campus Highlights ───────────────────────────────── */
.highlight-card {
  background: #fff; border-radius: 20px;
  box-shadow: var(--pu-shadow); overflow: hidden;
  height: 100%;
  transition: transform var(--pu-trans), box-shadow var(--pu-trans);
  position: relative;
}
.highlight-card:hover { transform:translateY(-10px); box-shadow:var(--pu-shadow-hover); }
.highlight-card-icon-wrap {
  height: 130px;
  display: flex; align-items: center; justify-content: center;
  font-size: 3rem; position: relative;
  overflow: hidden;
}
.highlight-card-icon-wrap .icon-bg {
  position:absolute; inset:0;
  opacity:.15;
}
.highlight-card-icon-wrap i { position:relative; z-index:1; }
.highlight-card-body { padding: 24px 26px 28px; }
.highlight-card-body h4 { font-size:1.1rem; font-weight:800; color:var(--pu-navy); margin-bottom:10px; }
.highlight-card-body p { font-size:.87rem; color:var(--pu-text); line-height:1.75; margin-bottom:16px; }
.highlight-card-tag {
  display: inline-block;
  font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em;
  padding:4px 12px; border-radius:50px;
}

/* color themes for highlight cards */
.hc-blue .highlight-card-icon-wrap { background:linear-gradient(135deg,#dbeafe,#bfdbfe); color:#1d4ed8; }
.hc-blue .icon-bg { background:#1d4ed8; }
.hc-blue .highlight-card-tag { background:#dbeafe; color:#1d4ed8; }

.hc-green .highlight-card-icon-wrap { background:linear-gradient(135deg,#d1fae5,#a7f3d0); color:#047857; }
.hc-green .icon-bg { background:#047857; }
.hc-green .highlight-card-tag { background:#d1fae5; color:#047857; }

.hc-amber .highlight-card-icon-wrap { background:linear-gradient(135deg,#fef3c7,#fde68a); color:#b45309; }
.hc-amber .icon-bg { background:#b45309; }
.hc-amber .highlight-card-tag { background:#fef3c7; color:#b45309; }

.hc-purple .highlight-card-icon-wrap { background:linear-gradient(135deg,#ede9fe,#ddd6fe); color:#6d28d9; }
.hc-purple .icon-bg { background:#6d28d9; }
.hc-purple .highlight-card-tag { background:#ede9fe; color:#6d28d9; }

.hc-navy .highlight-card-icon-wrap { background:linear-gradient(135deg,#cce0ff,#99c0ff); color:var(--pu-navy); }
.hc-navy .icon-bg { background:var(--pu-navy); }
.hc-navy .highlight-card-tag { background:#cce0ff; color:var(--pu-navy); }

/* ── Faculties ───────────────────────────────────────── */
.faculty-card {
  background: #fff; border-radius: 20px;
  box-shadow: var(--pu-shadow); overflow: hidden;
  transition: transform var(--pu-trans), box-shadow var(--pu-trans);
  height: 100%;
  border-left: 4px solid var(--pu-navy);
}
.faculty-card:hover { transform:translateY(-8px); box-shadow:var(--pu-shadow-hover); border-left-color:var(--pu-gold); }
.faculty-card-body { padding: 28px 24px 24px; }
.faculty-icon { width:54px; height:54px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:1.4rem; margin-bottom:18px; background:rgba(0,33,71,.07); color:var(--pu-navy); transition:background var(--pu-trans), color var(--pu-trans); }
.faculty-card:hover .faculty-icon { background:var(--pu-navy); color:#fff; }
.faculty-card-body h4 { font-size:1rem; font-weight:800; color:var(--pu-navy); margin-bottom:8px; line-height:1.35; }
.faculty-card-body p { font-size:.83rem; color:var(--pu-text); line-height:1.65; margin-bottom:16px; }
.faculty-card-footer { padding:14px 24px; border-top:1px solid #f0f4f8; display:flex; align-items:center; justify-content:space-between; }
.faculty-card-footer a { font-size:.8rem; font-weight:700; color:var(--pu-blue); text-decoration:none; display:flex; align-items:center; gap:6px; transition:color var(--pu-trans), gap var(--pu-trans); }
.faculty-card-footer a:hover { color:var(--pu-navy); gap:10px; }

/* fallback faculty cards (static) */
.faculty-card.fc-1 { border-left-color:#002147; }
.faculty-card.fc-2 { border-left-color:#1a4faf; }
.faculty-card.fc-3 { border-left-color:#FFB81C; }
.faculty-card.fc-4 { border-left-color:#0ea5e9; }
.faculty-card.fc-5 { border-left-color:#10b981; }

/* ── Timeline ────────────────────────────────────────── */
.glance-timeline { list-style:none; padding:0; margin:0; position:relative; }
.glance-timeline::before {
  content:''; position:absolute; left:20px; top:0; bottom:0; width:2px;
  background:linear-gradient(180deg, var(--pu-gold), var(--pu-blue));
}
.glance-timeline li {
  display:flex; gap:24px; align-items:flex-start;
  margin-bottom:28px; position:relative;
}
.glance-timeline .tl-dot {
  width:40px; height:40px; border-radius:50%; border:3px solid var(--pu-gold);
  background:#fff; flex-shrink:0; z-index:1;
  display:flex; align-items:center; justify-content:center;
  font-size:.8rem; font-weight:800; color:var(--pu-navy);
}
.glance-timeline .tl-content { padding-top:6px; }
.glance-timeline .tl-year { font-size:.78rem; font-weight:700; color:var(--pu-blue); text-transform:uppercase; letter-spacing:.08em; margin-bottom:4px; }
.glance-timeline .tl-title { font-size:.95rem; font-weight:700; color:var(--pu-navy); margin-bottom:4px; }
.glance-timeline .tl-desc { font-size:.84rem; color:var(--pu-text); line-height:1.6; margin:0; }

/* ── UGC Banner ──────────────────────────────────────── */
.ugc-banner {
  background: linear-gradient(135deg, #002147 0%, #1a4faf 100%);
  border-radius: 20px;
  padding: 44px 40px;
  display: flex; align-items: center; gap: 32px; flex-wrap: wrap;
  position: relative; overflow: hidden;
  box-shadow: 0 12px 48px rgba(0,33,71,.22);
}
.ugc-banner::before {
  content:''; position:absolute; right:-40px; top:-40px;
  width:220px; height:220px; border-radius:50%;
  background:rgba(255,184,28,.12);
}
.ugc-icon { font-size:3.5rem; color:var(--pu-gold); flex-shrink:0; }
.ugc-text h3 { font-size:1.5rem; font-weight:800; color:#fff; margin-bottom:8px; }
.ugc-text p  { font-size:.92rem; color:rgba(255,255,255,.78); margin:0; }
.ugc-badge {
  margin-left:auto;
  background:var(--pu-gold); color:var(--pu-navy);
  font-weight:800; font-size:.88rem; padding:10px 22px;
  border-radius:50px; white-space:nowrap;
  display:flex; align-items:center; gap:8px;
  flex-shrink:0;
  box-shadow:0 4px 16px rgba(255,184,28,.4);
}
@media (max-width:767px) { .ugc-badge { margin-left:0; } }

/* ── CTA Footer Strip ─────────────────────────────────── */
.glance-cta-strip {
  background: linear-gradient(135deg, #002147, #1a4faf);
  padding: 70px 0;
  text-align: center;
  position: relative; overflow: hidden;
}
.glance-cta-strip::before {
  content:''; position:absolute; inset:0;
  background: radial-gradient(ellipse 50% 120% at 50% 50%, rgba(255,184,28,.1) 0%, transparent 70%);
}
.glance-cta-strip h2 { font-size:clamp(1.6rem,3.5vw,2.4rem); font-weight:800; color:#fff; margin-bottom:14px; }
.glance-cta-strip p  { color:rgba(255,255,255,.75); font-size:1rem; margin-bottom:32px; }
.cta-btn-primary {
  display:inline-flex; align-items:center; gap:8px;
  background:var(--pu-gold); color:var(--pu-navy);
  font-weight:800; font-size:.95rem; padding:15px 36px;
  border-radius:50px; text-decoration:none;
  transition:transform var(--pu-trans), box-shadow var(--pu-trans);
  box-shadow:0 4px 20px rgba(255,184,28,.4);
}
.cta-btn-primary:hover { transform:translateY(-4px); box-shadow:0 10px 32px rgba(255,184,28,.55); color:var(--pu-navy); }
.cta-btn-secondary {
  display:inline-flex; align-items:center; gap:8px;
  border:2px solid rgba(255,255,255,.5); color:#fff;
  font-weight:700; font-size:.95rem; padding:14px 32px;
  border-radius:50px; text-decoration:none;
  margin-left:14px;
  transition:background var(--pu-trans), border-color var(--pu-trans);
}
.cta-btn-secondary:hover { background:rgba(255,255,255,.1); border-color:#fff; color:#fff; }

/* ── Responsive Tweaks ───────────────────────────────── */
@media (max-width:991px) {
  .glance-hero { padding:80px 0 70px; }
  .glance-section { padding:70px 0; }
  .msg-tabs-nav .msg-tab-btn { font-size:.85rem; padding:10px 16px; }
  .ugc-banner { padding:32px 24px; }
}
@media (max-width:575px) {
  .glance-hero { padding:70px 0 60px; }
  .glance-section { padding:56px 0; }
  .glance-stat-item { flex:1 1 140px; }
  .glance-about-img-wrap img { height:240px; }
  .msg-tabs-nav { gap:6px; }
  .msg-tab-btn { font-size:.82rem; padding:8px 12px; }
  .ugc-banner { flex-direction:column; gap:18px; }
  .ugc-icon { font-size:2.5rem; }
  .cta-btn-secondary { margin-left:0; margin-top:10px; }
}

/* ── Scroll Reveal animations (WOW supplement) ────────── */
[data-glance-reveal] { opacity:0; transform:translateY(30px); transition:opacity .6s ease, transform .6s ease; }
[data-glance-reveal].is-visible { opacity:1; transform:none; }
[data-glance-reveal][data-delay="100"] { transition-delay:.1s; }
[data-glance-reveal][data-delay="200"] { transition-delay:.2s; }
[data-glance-reveal][data-delay="300"] { transition-delay:.3s; }
[data-glance-reveal][data-delay="400"] { transition-delay:.4s; }
[data-glance-reveal][data-delay="500"] { transition-delay:.5s; }
[data-glance-reveal][data-delay="600"] { transition-delay:.6s; }
</style>
</head>
<body id="body" class="it-magic-cursor">

<!-- preloader -->
<div id="preloader">
   <div class="preloader">
      <span></span><span></span>
   </div>
</div>
<!-- magic cursor -->
<div id="magic-cursor"><div id="ball"></div></div>
<!-- scroll-top -->
<button class="scroll-top scroll-to-target" data-target="html">
   <i class="far fa-angle-double-up"></i>
</button>
<!-- search popup -->
<div class="search-popup">
   <div class="search-popup-overlay search-toggler"></div>
   <div class="search-popup-content">
      <div class="container">
         <div class="row justify-content-center">
            <div class="col-xl-8">
               <div class="search-popup-form">
                  <form action="#">
                     <input type="text" placeholder="Search here…">
                     <button type="submit"><i class="fad fa-search"></i></button>
                  </form>
               </div>
            </div>
         </div>
      </div>
   </div>
</div>
<!-- offcanvas (mobile menu) -->
<div class="it-offcanvas-area">
   <div class="it-offcanvas-wrapper">
      <div class="it-offcanvas-close"><button class="close-btn"><i class="fal fa-xmark"></i></button></div>
      <div class="it-offcanvas-logo"><a href="/index.php"><img src="/assets/img/logo/logo-black.png" alt="Prime University"></a></div>
      <div class="it-offcanvas-menu">
         <nav id="mobile-menu"></nav>
      </div>
   </div>
</div>
<div class="body-overlay"></div>

<header class="it-header-height">
   <?php include __DIR__ . '/includes/header-top.php'; ?>
   <?php include __DIR__ . '/includes/nav-menu.php'; ?>
</header>

<!-- ════════════════════════════════════════════════════════
     HERO
════════════════════════════════════════════════════════ -->
<section class="glance-hero">
   <span class="hero-circle c1"></span>
   <span class="hero-circle c2"></span>
   <span class="hero-circle c3"></span>
   <div class="container position-relative" style="z-index:2;">
      <nav class="breadcrumb-nav" aria-label="breadcrumb">
         <a href="/index.php">Home</a>
         <span class="sep">/</span>
         <span>About</span>
         <span class="sep">/</span>
         <span>PU At a Glance</span>
      </nav>
      <div class="row align-items-center g-4">
         <div class="col-lg-7">
            <div class="hero-tag">
               <span class="dot"></span>
               Est. 2002 · UGC Approved
            </div>
            <h1>Prime University<br><span class="accent">At a Glance</span></h1>
            <p class="hero-sub">A comprehensive overview of Prime University — its vision, leadership, facilities, faculties, and achievements since its founding in 2002.</p>
            <div class="glance-hero-cta">
               <a href="/apply-now.php" class="btn-gold">Apply Now <i class="fas fa-arrow-right ms-2"></i></a>
               <a href="/contact.php" class="btn-outline-light">Contact Us</a>
            </div>
         </div>
         <div class="col-lg-5 d-none d-lg-flex justify-content-end">
            <!-- Decorative university emblem block -->
            <div style="text-align:center; position:relative;">
               <div style="width:220px; height:220px; border-radius:50%; border:6px solid rgba(255,184,28,.4); display:flex; align-items:center; justify-content:center; margin:0 auto;">
                  <div style="width:170px; height:170px; border-radius:50%; background:rgba(255,255,255,.08); backdrop-filter:blur(6px); border:2px solid rgba(255,255,255,.2); display:flex; align-items:center; justify-content:center; flex-direction:column; gap:8px;">
                     <i class="fas fa-university" style="font-size:3.5rem; color:var(--pu-gold);"></i>
                     <span style="font-size:.7rem; font-weight:800; letter-spacing:.15em; text-transform:uppercase; color:rgba(255,255,255,.8);">Prime University</span>
                  </div>
               </div>
               <!-- floating badges -->
               <div style="position:absolute; top:-10px; right:-20px; background:var(--pu-gold); color:var(--pu-navy); font-size:.75rem; font-weight:800; padding:8px 14px; border-radius:50px; white-space:nowrap; box-shadow:0 4px 16px rgba(0,0,0,.2);">
                  <i class="fas fa-check-circle me-1"></i> UGC Approved
               </div>
               <div style="position:absolute; bottom:0; left:-30px; background:#fff; color:var(--pu-navy); font-size:.72rem; font-weight:800; padding:8px 14px; border-radius:50px; white-space:nowrap; box-shadow:0 4px 16px rgba(0,0,0,.15);">
                  <i class="fas fa-calendar me-1" style="color:var(--pu-blue);"></i> Since 2002
               </div>
            </div>
         </div>
      </div>
   </div>
</section>

<!-- ════════════════════════════════════════════════════════
     QUICK STATS BAR
════════════════════════════════════════════════════════ -->
<div class="glance-stats-bar">
   <div class="container">
      <div class="stats-inner">
         <div class="glance-stat-item" data-glance-reveal data-delay="100">
            <div class="stat-icon"><i class="fas fa-calendar-alt"></i></div>
            <div class="stat-num" data-purecounter-start="0" data-purecounter-end="2002" data-purecounter-duration="2" data-purecounter-decimals="0" data-purecounter-separator="">2002</div>
            <div class="stat-label">Year Established</div>
         </div>
         <div class="glance-stat-item" data-glance-reveal data-delay="200">
            <div class="stat-icon"><i class="fas fa-book-open"></i></div>
            <div class="stat-num" data-purecounter-start="0" data-purecounter-end="30000" data-purecounter-duration="2" data-purecounter-decimals="0">30K+</div>
            <div class="stat-label">Library Books</div>
         </div>
         <div class="glance-stat-item" data-glance-reveal data-delay="300">
            <div class="stat-icon"><i class="fas fa-users"></i></div>
            <div class="stat-num" data-purecounter-start="0" data-purecounter-end="5000" data-purecounter-duration="2" data-purecounter-decimals="0">5000+</div>
            <div class="stat-label">Students</div>
         </div>
         <div class="glance-stat-item" data-glance-reveal data-delay="400">
            <div class="stat-icon"><i class="fas fa-chalkboard-teacher"></i></div>
            <div class="stat-num" data-purecounter-start="0" data-purecounter-end="200" data-purecounter-duration="2" data-purecounter-decimals="0">200+</div>
            <div class="stat-label">Faculty Members</div>
         </div>
         <div class="glance-stat-item" data-glance-reveal data-delay="500">
            <div class="stat-icon"><i class="fas fa-award"></i></div>
            <div class="stat-num">UGC</div>
            <div class="stat-label">Approved</div>
         </div>
         <div class="glance-stat-item" data-glance-reveal data-delay="600">
            <div class="stat-icon"><i class="fas fa-map-marker-alt"></i></div>
            <div class="stat-num">Dhaka</div>
            <div class="stat-label">Bangladesh</div>
         </div>
      </div>
   </div>
</div>

<!-- ════════════════════════════════════════════════════════
     ABOUT OVERVIEW
════════════════════════════════════════════════════════ -->
<section class="glance-section bg-white">
   <div class="container">
      <div class="row g-5 align-items-center">
         <div class="col-lg-5">
            <div class="glance-about-img-wrap" data-glance-reveal>
               <img src="/assets/img/logo/logo-black.png" alt="Prime University Campus"
                    style="width:100%; height:380px; object-fit:contain; background:#f0f4f8; padding:40px;"
                    onerror="this.style.padding='0'; this.style.objectFit='cover';">
               <div class="about-badge">
                  <i class="fas fa-star"></i>
                  Est. 2002 · Dhaka, Bangladesh
               </div>
            </div>
         </div>
         <div class="col-lg-7">
            <div data-glance-reveal data-delay="100">
               <span class="section-tag">Who We Are</span>
               <h2 class="section-title">A Legacy of Excellence<br>in Higher Education</h2>
               <div class="section-divider"></div>
               <p class="section-desc mb-4">Prime University is a University Grant Commission (UGC) approved private university established in 2002, located in Mirpur-1, Dhaka. It is committed to providing quality education in various disciplines including business, engineering, science, and arts — fostering academic excellence, research, and innovation.</p>
            </div>
            <div class="row g-3" data-glance-reveal data-delay="200">
               <div class="col-sm-6">
                  <div class="glance-about-feat">
                     <div class="feat-icon navy"><i class="fas fa-university"></i></div>
                     <div>
                        <h6>Year of Establishment</h6>
                        <p>Founded in 2002 as a private university under the Private University Act of Bangladesh.</p>
                     </div>
                  </div>
               </div>
               <div class="col-sm-6">
                  <div class="glance-about-feat">
                     <div class="feat-icon gold"><i class="fas fa-award"></i></div>
                     <div>
                        <h6>UGC Approved</h6>
                        <p>Recognized and approved by the University Grants Commission (UGC) of Bangladesh.</p>
                     </div>
                  </div>
               </div>
               <div class="col-sm-6">
                  <div class="glance-about-feat">
                     <div class="feat-icon blue"><i class="fas fa-map-marker-alt"></i></div>
                     <div>
                        <h6>Prime Location</h6>
                        <p>Located at 114/116 Mazar Road, Mirpur-1, Dhaka-1216, Bangladesh.</p>
                     </div>
                  </div>
               </div>
               <div class="col-sm-6">
                  <div class="glance-about-feat">
                     <div class="feat-icon navy"><i class="fas fa-flask"></i></div>
                     <div>
                        <h6>Innovation &amp; Research</h6>
                        <p>Home to an Innovation Hub and the CRHP research center promoting technology research.</p>
                     </div>
                  </div>
               </div>
            </div>
         </div>
      </div>
   </div>
</section>

<!-- ════════════════════════════════════════════════════════
     UGC APPROVED BANNER
════════════════════════════════════════════════════════ -->
<section style="background:var(--pu-light); padding:40px 0;">
   <div class="container">
      <div class="ugc-banner" data-glance-reveal>
         <div class="ugc-icon"><i class="fas fa-shield-check"></i></div>
         <div class="ugc-text">
            <h3>University Grants Commission (UGC) Approved</h3>
            <p>Prime University is fully recognized and accredited by the University Grants Commission of Bangladesh, ensuring quality education that meets national standards and international academic benchmarks.</p>
         </div>
         <div class="ugc-badge">
            <i class="fas fa-certificate"></i> Officially Accredited
         </div>
      </div>
   </div>
</section>

<!-- ════════════════════════════════════════════════════════
     LEADERSHIP
════════════════════════════════════════════════════════ -->
<section class="glance-section bg-white">
   <div class="container">
      <div class="text-center mb-56" data-glance-reveal>
         <span class="section-tag">Our Leadership</span>
         <h2 class="section-title">Key Administrative Officers</h2>
         <div class="section-divider mx-auto"></div>
         <p class="section-desc" style="max-width:560px; margin:0 auto;">The university is steered by experienced academics and administrators committed to excellence and governance.</p>
      </div>

      <div class="row g-4 justify-content-center">
         <!-- Treasurer -->
         <div class="col-lg-4 col-md-6" data-glance-reveal data-delay="100">
            <div class="leader-card">
               <div class="leader-card-top">
                  <div class="leader-avatar icon-avatar">
                     <i class="fas fa-user-tie"></i>
                  </div>
                  <h4>Prof Dr. Abdur Rahman</h4>
                  <span class="role-tag">Treasurer</span>
               </div>
               <div class="leader-card-body">
                  <p>The Treasurer oversees the financial affairs, budgeting, and fiscal management of Prime University, ensuring sound financial governance and transparency.</p>
               </div>
            </div>
         </div>

         <!-- Registrar -->
         <div class="col-lg-4 col-md-6" data-glance-reveal data-delay="200">
            <div class="leader-card">
               <div class="leader-card-top">
                  <div class="leader-avatar icon-avatar">
                     <i class="fas fa-user-cog"></i>
                  </div>
                  <h4>Prof. Dr. Md. Mustafa Kamal</h4>
                  <span class="role-tag">Registrar</span>
               </div>
               <div class="leader-card-body">
                  <p>The Registrar manages all academic and administrative records, oversees student enrollment, and ensures smooth functioning of university operations.</p>
               </div>
            </div>
         </div>
      </div>
   </div>
</section>

<!-- ════════════════════════════════════════════════════════
     MESSAGES (Chairman & VC)
════════════════════════════════════════════════════════ -->
<section class="glance-section bg-light">
   <div class="container">
      <div class="text-center mb-5" data-glance-reveal>
         <span class="section-tag">Leadership Messages</span>
         <h2 class="section-title">Words from Our Leadership</h2>
         <div class="section-divider mx-auto"></div>
      </div>

      <!-- Tab Navigation -->
      <div class="msg-tabs-nav justify-content-center" data-glance-reveal data-delay="100">
         <button class="msg-tab-btn active" data-tab="chairman">
            <i class="fas fa-star me-2" style="color:var(--pu-gold);"></i>Message from Chairman
         </button>
         <button class="msg-tab-btn" data-tab="vc">
            <i class="fas fa-university me-2" style="color:var(--pu-blue);"></i>Message from Vice Chancellor
         </button>
      </div>

      <!-- Chairman Message -->
      <div class="msg-tab-pane active" id="tab-chairman">
         <div class="msg-card" data-glance-reveal>
            <div class="msg-person-side">
               <div class="msg-person-avatar">
                  <i class="fas fa-user-tie"></i>
               </div>
               <h5>Honorable Chairman</h5>
               <span>Board of Trustees</span>
            </div>
            <div class="msg-content-side">
               <div class="quote-icon"><i class="fas fa-quote-left"></i></div>
               <p>Prime University has been a beacon of knowledge and opportunity since its establishment in 2002. Our vision has always been to create an inclusive academic environment where students can discover their potential, pursue their passions, and contribute meaningfully to society.</p>
               <p>We are proud to offer world-class education through our diverse faculties and dedicated faculty members. Our commitment to research, innovation, and community engagement remains unwavering as we strive to make quality education accessible to all aspiring students of Bangladesh.</p>
               <p>I invite students, scholars, and stakeholders to join us in this journey of academic excellence. Together, we shall build a future enriched by knowledge, empathy, and integrity.</p>
               <div class="msg-signature">
                  <div class="sig-name">Honorable Chairman</div>
                  <div class="sig-role">Board of Trustees, Prime University</div>
               </div>
            </div>
         </div>
      </div>

      <!-- VC Message -->
      <div class="msg-tab-pane" id="tab-vc">
         <div class="msg-card" data-glance-reveal>
            <div class="msg-person-side">
               <div class="msg-person-avatar">
                  <i class="fas fa-graduation-cap"></i>
               </div>
               <h5>Vice Chancellor</h5>
               <span>Prime University</span>
            </div>
            <div class="msg-content-side">
               <div class="quote-icon"><i class="fas fa-quote-left"></i></div>
               <p>As Vice Chancellor of Prime University, I extend a warm welcome to all students, faculty, staff, and guests. Prime University has consistently upheld the highest standards of academic excellence and has emerged as a center of learning that bridges tradition with modernity.</p>
               <p>Our academic programs are meticulously designed to equip students with critical thinking, professional skills, and ethical values essential for success in the 21st century. The university's Innovation Hub, CRHP Research Center, and state-of-the-art Library demonstrate our commitment to fostering a culture of inquiry and discovery.</p>
               <p>We believe in the transformative power of education and are dedicated to nurturing the next generation of leaders, innovators, and responsible citizens who will shape the future of Bangladesh and beyond.</p>
               <div class="msg-signature">
                  <div class="sig-name">Vice Chancellor</div>
                  <div class="sig-role">Prime University, Dhaka</div>
               </div>
            </div>
         </div>
      </div>
   </div>
</section>

<!-- ════════════════════════════════════════════════════════
     CAMPUS HIGHLIGHTS
════════════════════════════════════════════════════════ -->
<section class="glance-section bg-white">
   <div class="container">
      <div class="text-center mb-5" data-glance-reveal>
         <span class="section-tag">Campus Life</span>
         <h2 class="section-title">Campus Highlights &amp; Facilities</h2>
         <div class="section-divider mx-auto"></div>
         <p class="section-desc" style="max-width:580px; margin:0 auto;">Prime University offers a vibrant campus environment with cutting-edge facilities designed to support academic growth and holistic development.</p>
      </div>

      <div class="row g-4">

         <!-- Innovation Hub -->
         <div class="col-lg-4 col-md-6" data-glance-reveal data-delay="100">
            <div class="highlight-card hc-blue">
               <div class="highlight-card-icon-wrap">
                  <div class="icon-bg"></div>
                  <i class="fas fa-lightbulb"></i>
               </div>
               <div class="highlight-card-body">
                  <h4>Innovation Hub</h4>
                  <p>A dynamic platform comprising university teachers, students, and researchers, formed under the supervision of Innovation Lab to expand technology research at the university level. The hub fosters collaboration, creativity, and entrepreneurial thinking among the university community.</p>
                  <span class="highlight-card-tag" style="background:#dbeafe; color:#1d4ed8;">Research &amp; Technology</span>
               </div>
            </div>
         </div>

         <!-- Library -->
         <div class="col-lg-4 col-md-6" data-glance-reveal data-delay="200">
            <div class="highlight-card hc-green">
               <div class="highlight-card-icon-wrap">
                  <div class="icon-bg"></div>
                  <i class="fas fa-book-reader"></i>
               </div>
               <div class="highlight-card-body">
                  <h4>Prime University Library</h4>
                  <p>The library holds over <strong>30,000 books</strong> covering liberal arts, social sciences, business, management, engineering, computer science, and language courses. It also features a <strong>Digital Library</strong> with text, images, audio, video, CD-ROMs, and specialized databases.</p>
                  <span class="highlight-card-tag" style="background:#d1fae5; color:#047857;">30,000+ Books</span>
               </div>
            </div>
         </div>

         <!-- Research Center -->
         <div class="col-lg-4 col-md-6" data-glance-reveal data-delay="300">
            <div class="highlight-card hc-amber">
               <div class="highlight-card-icon-wrap">
                  <div class="icon-bg"></div>
                  <i class="fas fa-microscope"></i>
               </div>
               <div class="highlight-card-body">
                  <h4>Research Center (CRHP)</h4>
                  <p>PU's dedicated research center — the <strong>Center for Research, Human Resource Development &amp; Publications (CRHP)</strong> — drives scholarly research, human resource development initiatives, and academic publications across all disciplines.</p>
                  <span class="highlight-card-tag" style="background:#fef3c7; color:#b45309;">CRHP</span>
               </div>
            </div>
         </div>

         <!-- Alumni Association -->
         <div class="col-lg-4 col-md-6" data-glance-reveal data-delay="400">
            <div class="highlight-card hc-purple">
               <div class="highlight-card-icon-wrap">
                  <div class="icon-bg"></div>
                  <i class="fas fa-user-graduate"></i>
               </div>
               <div class="highlight-card-body">
                  <h4>Alumni Association (PUALUMNI)</h4>
                  <p>The <strong>Prime University Alumni Association (PUALUMNI)</strong> is located on campus and connects graduates across generations. It facilitates networking, mentorship, and professional development opportunities for alumni and current students alike.</p>
                  <span class="highlight-card-tag" style="background:#ede9fe; color:#6d28d9;">PUALUMNI</span>
               </div>
            </div>
         </div>

         <!-- Digital Library -->
         <div class="col-lg-4 col-md-6" data-glance-reveal data-delay="500">
            <div class="highlight-card hc-navy">
               <div class="highlight-card-icon-wrap">
                  <div class="icon-bg"></div>
                  <i class="fas fa-database"></i>
               </div>
               <div class="highlight-card-body">
                  <h4>Digital Library</h4>
                  <p>A focused digital collection of objects including text, images, audio, and video. The digital library ensures students and researchers have access to a wide range of academic resources available anytime, anywhere.</p>
                  <span class="highlight-card-tag" style="background:#cce0ff; color:#002147;">Digital Resources</span>
               </div>
            </div>
         </div>

         <!-- Campus Infrastructure -->
         <div class="col-lg-4 col-md-6" data-glance-reveal data-delay="600">
            <div class="highlight-card hc-blue" style="--pu-blue:#0369a1;">
               <div class="highlight-card-icon-wrap" style="background:linear-gradient(135deg,#e0f2fe,#bae6fd); color:#0369a1;">
                  <div class="icon-bg" style="background:#0369a1;"></div>
                  <i class="fas fa-building"></i>
               </div>
               <div class="highlight-card-body">
                  <h4>Modern Campus Infrastructure</h4>
                  <p>A purpose-built campus in Mirpur-1, Dhaka, equipped with modern classrooms, laboratories, seminar halls, and student amenities. The campus provides an inspiring environment that nurtures learning and personal growth.</p>
                  <span class="highlight-card-tag" style="background:#e0f2fe; color:#0369a1;">Mirpur-1, Dhaka</span>
               </div>
            </div>
         </div>

      </div>
   </div>
</section>

<!-- ════════════════════════════════════════════════════════
     FACULTIES
════════════════════════════════════════════════════════ -->
<section class="glance-section bg-light">
   <div class="container">
      <div class="text-center mb-5" data-glance-reveal>
         <span class="section-tag">Academics</span>
         <h2 class="section-title">Our Faculties &amp; Departments</h2>
         <div class="section-divider mx-auto"></div>
         <p class="section-desc" style="max-width:580px; margin:0 auto;">Prime University offers a wide range of programs through its specialized faculties, each committed to academic excellence and professional preparation.</p>
      </div>

      <?php if (!empty($_departments)): ?>
      <div class="row g-4">
         <?php
         $dept_colors = ['fc-1','fc-2','fc-3','fc-4','fc-5'];
         $dept_icons  = ['fas fa-calculator','fas fa-microchip','fas fa-atom','fas fa-palette','fas fa-scale-balanced','fas fa-stethoscope','fas fa-landmark','fas fa-seedling'];
         foreach ($_departments as $i => $dept):
            $ci = $i % count($dept_colors);
            $ii = $i % count($dept_icons);
         ?>
         <div class="col-lg-4 col-md-6" data-glance-reveal data-delay="<?= (($i % 3) + 1) * 100 ?>">
            <div class="faculty-card <?= $dept_colors[$ci] ?>">
               <div class="faculty-card-body">
                  <div class="faculty-icon">
                     <i class="<?= fh($dept['hero_icon'] ?: $dept_icons[$ii]) ?>"></i>
                  </div>
                  <h4><?= fh($dept['name']) ?></h4>
                  <p><?= fh($dept['hero_subtitle'] ?: 'Offering quality academic programs with experienced faculty and modern facilities.') ?></p>
               </div>
               <div class="faculty-card-footer">
                  <a href="/department.php?slug=<?= urlencode($dept['slug']) ?>">
                     Explore Department <i class="fas fa-arrow-right"></i>
                  </a>
               </div>
            </div>
         </div>
         <?php endforeach; ?>
      </div>
      <?php else: ?>
      <!-- Static faculty cards if DB is not available -->
      <div class="row g-4">
         <?php
         $static_faculties = [
            ['icon'=>'fas fa-chart-line',   'color'=>'fc-1', 'name'=>'Faculty of Business Administration', 'desc'=>'Offering BBA, MBA, and EMBA programs in Management, Marketing, Finance, Accounting and Human Resource Management.'],
            ['icon'=>'fas fa-microchip',    'color'=>'fc-2', 'name'=>'Faculty of Engineering &amp; Technology', 'desc'=>'Programs in Computer Science &amp; Engineering, Telecommunication Engineering, and Electrical &amp; Electronics Engineering.'],
            ['icon'=>'fas fa-book',         'color'=>'fc-3', 'name'=>'Faculty of Arts &amp; Social Sciences', 'desc'=>'Covering disciplines in English, Sociology, History, Culture, and Social Science with a focus on critical thinking and communication.'],
            ['icon'=>'fas fa-atom',         'color'=>'fc-4', 'name'=>'Faculty of Science', 'desc'=>'Exploring the fundamentals of natural sciences with programs that equip students for careers in research and applied sciences.'],
            ['icon'=>'fas fa-scale-balanced','color'=>'fc-5','name'=>'Faculty of Law', 'desc'=>'Providing comprehensive legal education to develop skilled legal professionals who uphold justice, ethics, and the rule of law.'],
         ];
         foreach ($static_faculties as $idx => $fac):
         ?>
         <div class="col-lg-4 col-md-6" data-glance-reveal data-delay="<?= (($idx % 3) + 1) * 100 ?>">
            <div class="faculty-card <?= $fac['color'] ?>">
               <div class="faculty-card-body">
                  <div class="faculty-icon">
                     <i class="<?= $fac['icon'] ?>"></i>
                  </div>
                  <h4><?= $fac['name'] ?></h4>
                  <p><?= $fac['desc'] ?></p>
               </div>
               <div class="faculty-card-footer">
                  <a href="/department.php">
                     Explore Programs <i class="fas fa-arrow-right"></i>
                  </a>
               </div>
            </div>
         </div>
         <?php endforeach; ?>
      </div>
      <?php endif; ?>
   </div>
</section>

<!-- ════════════════════════════════════════════════════════
     MILESTONES TIMELINE
════════════════════════════════════════════════════════ -->
<section class="glance-section bg-white">
   <div class="container">
      <div class="row g-5 align-items-start">
         <div class="col-lg-5" data-glance-reveal>
            <span class="section-tag">Our Journey</span>
            <h2 class="section-title">Key Milestones in<br>Our History</h2>
            <div class="section-divider"></div>
            <p class="section-desc">From a single campus in Mirpur, Dhaka to a thriving institution recognized by UGC, Prime University's journey spans over two decades of academic excellence, innovation, and growth.</p>
         </div>
         <div class="col-lg-7" data-glance-reveal data-delay="200">
            <ul class="glance-timeline">
               <li>
                  <div class="tl-dot">02</div>
                  <div class="tl-content">
                     <div class="tl-year">2002</div>
                     <div class="tl-title">Foundation &amp; UGC Approval</div>
                     <p class="tl-desc">Prime University was established in Mirpur-1, Dhaka, receiving UGC approval as a private university under the Private University Act of Bangladesh.</p>
                  </div>
               </li>
               <li>
                  <div class="tl-dot"><i class="fas fa-book" style="font-size:.65rem;"></i></div>
                  <div class="tl-content">
                     <div class="tl-year">Early Years</div>
                     <div class="tl-title">Library &amp; Academic Infrastructure</div>
                     <p class="tl-desc">Development of the university library with over 30,000 books, CD-ROMs, and databases covering diverse academic disciplines.</p>
                  </div>
               </li>
               <li>
                  <div class="tl-dot"><i class="fas fa-flask" style="font-size:.65rem;"></i></div>
                  <div class="tl-content">
                     <div class="tl-year">Growth Phase</div>
                     <div class="tl-title">CRHP Research Center Established</div>
                     <p class="tl-desc">Launch of the Center for Research, Human Resource Development &amp; Publications (CRHP) to advance scholarly research and academic publications.</p>
                  </div>
               </li>
               <li>
                  <div class="tl-dot"><i class="fas fa-lightbulb" style="font-size:.65rem;"></i></div>
                  <div class="tl-content">
                     <div class="tl-year">Innovation Era</div>
                     <div class="tl-title">Innovation Hub Launched</div>
                     <p class="tl-desc">Establishment of the Innovation Hub — a collaborative platform for teachers, students, and researchers to drive technology research and entrepreneurship.</p>
                  </div>
               </li>
               <li>
                  <div class="tl-dot"><i class="fas fa-users" style="font-size:.65rem;"></i></div>
                  <div class="tl-content">
                     <div class="tl-year">Community</div>
                     <div class="tl-title">Alumni Association (PUALUMNI)</div>
                     <p class="tl-desc">Formation of the Prime University Alumni Association (PUALUMNI) to connect graduates and support current students through mentorship and networking.</p>
                  </div>
               </li>
               <li>
                  <div class="tl-dot"><i class="fas fa-star" style="font-size:.65rem;"></i></div>
                  <div class="tl-content">
                     <div class="tl-year">Today</div>
                     <div class="tl-title">20+ Years of Excellence</div>
                     <p class="tl-desc">Continuing to grow with 5,000+ students, 200+ faculty members, and multiple faculties offering world-class programs recognized across Bangladesh.</p>
                  </div>
               </li>
            </ul>
         </div>
      </div>
   </div>
</section>

<!-- ════════════════════════════════════════════════════════
     CTA STRIP
════════════════════════════════════════════════════════ -->
<section class="glance-cta-strip">
   <div class="container position-relative" style="z-index:2;">
      <div data-glance-reveal>
         <h2>Begin Your Journey at Prime University</h2>
         <p>Join thousands of students pursuing their dreams. Applications are open for all programs.</p>
         <div style="display:flex; justify-content:center; flex-wrap:wrap; gap:14px;">
            <a href="/apply-now.php" class="cta-btn-primary">
               <i class="fas fa-pen-nib"></i> Apply Now
            </a>
            <a href="/contact.php" class="cta-btn-secondary">
               <i class="fas fa-phone"></i> Contact Admissions
            </a>
         </div>
      </div>
   </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
<?php include __DIR__ . '/includes/scripts.php'; ?>

<script>
/* ── Glance Page JS ───────────────────────────────────── */
(function() {
   'use strict';

   /* Scroll-reveal observer */
   var items = document.querySelectorAll('[data-glance-reveal]');
   if ('IntersectionObserver' in window) {
      var observer = new IntersectionObserver(function(entries) {
         entries.forEach(function(entry) {
            if (entry.isIntersecting) {
               entry.target.classList.add('is-visible');
               observer.unobserve(entry.target);
            }
         });
      }, { threshold: 0.12 });
      items.forEach(function(el) { observer.observe(el); });
   } else {
      items.forEach(function(el) { el.classList.add('is-visible'); });
   }

   /* Messages tab switcher */
   var tabBtns = document.querySelectorAll('.msg-tab-btn');
   var tabPanes = document.querySelectorAll('.msg-tab-pane');
   tabBtns.forEach(function(btn) {
      btn.addEventListener('click', function() {
         var target = btn.getAttribute('data-tab');
         tabBtns.forEach(function(b) { b.classList.remove('active'); });
         tabPanes.forEach(function(p) { p.classList.remove('active'); });
         btn.classList.add('active');
         var pane = document.getElementById('tab-' + target);
         if (pane) pane.classList.add('active');
      });
   });

   /* WOW.js init (if available) */
   if (typeof WOW === 'function') {
      new WOW({ offset: 60, mobile: false, live: false }).init();
   }

   /* PureCounter init (if available) */
   if (typeof PureCounter === 'function') {
      new PureCounter();
   }
})();
</script>

</body>
</html>
