<?php
require_once __DIR__ . '/includes/config.php';

$s = [];
$staff = [];
try {
    $db = front_db();
    if ($db) {
        $rows = $db->query('SELECT setting_key, setting_val FROM reg_settings')->fetchAll();
        foreach ($rows as $r) $s[$r['setting_key']] = $r['setting_val'];
        $staff = $db->query('SELECT * FROM reg_staff WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
    }
} catch (Throwable $e) {}

function regs(array $s, string $key, string $default = ''): string {
    return isset($s[$key]) && $s[$key] !== '' ? $s[$key] : $default;
}

if (regs($s, 'is_published', '1') !== '1') { header('Location: /index.php'); exit; }

$page_title = regs($s, 'hero_title', 'Office of the Registrar') . ' – Prime University';
$meta_desc  = regs($s, 'meta_description', 'Office of the Registrar – Prime University');

$message_paragraphs = [];
$raw_message = regs($s, 'message_body', '');
if ($raw_message !== '') {
    $message_paragraphs = array_filter(
        array_map('trim', preg_split('/\n{2,}/', $raw_message)),
        fn($p) => $p !== ''
    );
}

$reg_photo_url = !empty($s['reg_photo']) ? ADMIN_UPLOAD_URL . '/office-of-registrar/' . $s['reg_photo'] : '';
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
:root{
  --rg-navy:#002147;--rg-gold:#FFB81C;--rg-blue:#1a4faf;
  --rg-light:#f4f7fb;--rg-text:#334155;--rg-muted:#64748b;
  --rg-shadow:0 8px 40px rgba(0,33,71,.10);--rg-shadow-h:0 18px 60px rgba(0,33,71,.18);
  --rg-trans:.35s cubic-bezier(.4,0,.2,1);
}
.rg-hero{background:linear-gradient(135deg,#001530 0%,#002f68 55%,#1a4faf 100%);padding:110px 0 90px;position:relative;overflow:hidden;}
.rg-hero::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 60% 80% at 80% 50%,rgba(255,184,28,.10) 0%,transparent 70%),radial-gradient(ellipse 40% 60% at 10% 80%,rgba(26,79,175,.35) 0%,transparent 60%);pointer-events:none;}
.rg-circle{position:absolute;border-radius:50%;pointer-events:none;animation:rgFloat 9s ease-in-out infinite;}
.rg-circle.c1{width:380px;height:380px;background:rgba(255,184,28,.07);top:-90px;right:-70px;}
.rg-circle.c2{width:200px;height:200px;background:rgba(255,255,255,.05);bottom:20px;left:4%;animation-delay:3s;}
.rg-circle.c3{width:120px;height:120px;background:rgba(255,184,28,.10);top:35%;right:22%;animation-delay:1.5s;}
@keyframes rgFloat{0%,100%{transform:translateY(0) scale(1);}50%{transform:translateY(-22px) scale(1.05);}}
.rg-hero .breadcrumb-nav{display:flex;align-items:center;gap:8px;font-size:.82rem;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:rgba(255,255,255,.65);margin-bottom:24px;}
.rg-hero .breadcrumb-nav a{color:var(--rg-gold);text-decoration:none;}
.rg-hero .breadcrumb-nav .sep{color:rgba(255,255,255,.35);}
.rg-hero-tag{display:inline-flex;align-items:center;gap:8px;background:rgba(255,184,28,.18);color:var(--rg-gold);font-size:.77rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;padding:7px 18px;border-radius:50px;border:1px solid rgba(255,184,28,.35);margin-bottom:20px;}
.rg-hero-tag .dot{width:7px;height:7px;border-radius:50%;background:var(--rg-gold);animation:rgPulse 1.8s ease-in-out infinite;}
@keyframes rgPulse{0%,100%{opacity:1;transform:scale(1);}50%{opacity:.5;transform:scale(1.6);}}
.rg-hero h1{font-size:clamp(2rem,5vw,3.4rem);font-weight:800;color:#fff;line-height:1.15;margin-bottom:18px;}
.rg-hero h1 .accent{color:var(--rg-gold);}
.rg-hero .hero-sub{font-size:clamp(.93rem,1.8vw,1.1rem);color:rgba(255,255,255,.78);max-width:580px;line-height:1.75;}
.rg-facts-strip{background:var(--rg-navy);padding:24px 0;}
.rg-fact-item{display:flex;align-items:center;gap:12px;color:rgba(255,255,255,.85);padding:10px 20px;border-right:1px solid rgba(255,255,255,.12);}
.rg-fact-item:last-child{border-right:none;}
.rg-fact-icon{color:var(--rg-gold);font-size:1.2rem;flex-shrink:0;}
.rg-fact-text strong{display:block;font-size:.92rem;font-weight:700;color:#fff;}
.rg-fact-text span{font-size:.77rem;color:rgba(255,255,255,.55);letter-spacing:.04em;text-transform:uppercase;}
@media(max-width:767.98px){.rg-fact-item{border-right:none;border-bottom:1px solid rgba(255,255,255,.10);}.rg-fact-item:last-child{border-bottom:none;}}
.rg-profile-section{padding:80px 0 60px;background:#fff;}
.rg-profile-card{background:#fff;border-radius:24px;box-shadow:var(--rg-shadow);overflow:hidden;transition:box-shadow var(--rg-trans);border:1px solid rgba(0,33,71,.06);}
.rg-profile-card:hover{box-shadow:var(--rg-shadow-h);}
.rg-card-accent{height:6px;background:linear-gradient(90deg,var(--rg-navy) 0%,var(--rg-blue) 50%,var(--rg-gold) 100%);}
.rg-card-body{padding:40px;}
@media(max-width:767.98px){.rg-card-body{padding:28px 20px;}}
.rg-photo-wrap{position:relative;display:inline-block;}
.rg-photo-wrap img{width:160px;height:160px;border-radius:50%;object-fit:cover;object-position:top center;border:5px solid #fff;box-shadow:0 8px 32px rgba(0,33,71,.18);display:block;}
.rg-photo-badge{position:absolute;bottom:6px;right:6px;width:32px;height:32px;border-radius:50%;background:var(--rg-gold);border:3px solid #fff;display:flex;align-items:center;justify-content:center;}
.rg-photo-badge i{color:var(--rg-navy);font-size:.7rem;}
.rg-photo-placeholder{width:160px;height:160px;border-radius:50%;background:linear-gradient(135deg,var(--rg-navy),var(--rg-blue));display:flex;align-items:center;justify-content:center;box-shadow:0 8px 32px rgba(0,33,71,.18);border:5px solid #fff;}
.rg-photo-placeholder i{color:rgba(255,255,255,.75);font-size:3.5rem;}
.rg-name{font-size:clamp(1.3rem,3vw,1.75rem);font-weight:800;color:var(--rg-navy);margin-bottom:4px;}
.rg-designation{display:inline-flex;align-items:center;gap:8px;background:var(--rg-navy);color:var(--rg-gold);font-size:.8rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:5px 16px;border-radius:50px;margin-bottom:24px;}
.rg-contact-list{list-style:none;padding:0;margin:0;}
.rg-contact-list li{display:flex;align-items:flex-start;gap:12px;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:.9rem;color:var(--rg-text);}
.rg-contact-list li:last-child{border-bottom:none;}
.rg-contact-icon{width:34px;height:34px;border-radius:8px;flex-shrink:0;background:var(--rg-light);display:flex;align-items:center;justify-content:center;}
.rg-contact-icon i{color:var(--rg-blue);font-size:.85rem;}
.rg-contact-list a{color:var(--rg-blue);text-decoration:none;transition:color var(--rg-trans);}
.rg-contact-list a:hover{color:var(--rg-navy);text-decoration:underline;}
.rg-bio-section{background:var(--rg-light);padding:70px 0;}
.rg-bio-card{background:#fff;border-radius:20px;padding:44px 48px;box-shadow:var(--rg-shadow);border-left:5px solid var(--rg-gold);position:relative;}
@media(max-width:767.98px){.rg-bio-card{padding:28px 22px;}}
.rg-bio-card p{font-size:1.02rem;color:var(--rg-text);line-height:1.85;margin-bottom:0;}
.rg-message-section{padding:80px 0;background:#fff;position:relative;overflow:hidden;}
.rg-message-section::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,var(--rg-navy),var(--rg-blue),var(--rg-gold));}
.rg-message-card{background:linear-gradient(135deg,#001e45 0%,#002f68 100%);border-radius:24px;padding:56px 56px 48px;position:relative;overflow:hidden;box-shadow:0 16px 56px rgba(0,33,71,.22);}
@media(max-width:767.98px){.rg-message-card{padding:36px 24px 32px;}}
.rg-message-card::before{content:'';position:absolute;top:-60px;right:-60px;width:280px;height:280px;border-radius:50%;background:rgba(255,184,28,.08);pointer-events:none;}
.rg-message-card::after{content:'';position:absolute;bottom:-80px;left:-40px;width:220px;height:220px;border-radius:50%;background:rgba(26,79,175,.3);pointer-events:none;}
.rg-msg-header{position:relative;z-index:1;margin-bottom:32px;}
.rg-msg-icon{width:56px;height:56px;border-radius:14px;background:rgba(255,184,28,.2);border:1px solid rgba(255,184,28,.4);display:flex;align-items:center;justify-content:center;margin-bottom:16px;}
.rg-msg-icon i{color:var(--rg-gold);font-size:1.4rem;}
.rg-msg-title{font-size:clamp(1.35rem,3vw,1.9rem);font-weight:800;color:#fff;margin-bottom:4px;}
.rg-msg-subtitle{color:rgba(255,255,255,.6);font-size:.9rem;}
.rg-msg-divider{height:2px;width:60px;background:linear-gradient(90deg,var(--rg-gold),transparent);margin:20px 0 28px;}
.rg-msg-body{position:relative;z-index:1;}
.rg-msg-body p{color:rgba(255,255,255,.88);font-size:1rem;line-height:1.9;margin-bottom:1.4rem;}
.rg-msg-body p:last-child{margin-bottom:0;}
.rg-msg-sig{position:relative;z-index:1;margin-top:36px;padding-top:24px;border-top:1px solid rgba(255,255,255,.12);display:flex;align-items:center;gap:16px;flex-wrap:wrap;}
.rg-msg-sig-photo{width:54px;height:54px;border-radius:50%;object-fit:cover;object-position:top center;border:2px solid rgba(255,184,28,.4);flex-shrink:0;}
.rg-msg-sig-placeholder{width:54px;height:54px;border-radius:50%;flex-shrink:0;background:rgba(255,255,255,.1);display:flex;align-items:center;justify-content:center;border:2px solid rgba(255,184,28,.3);}
.rg-msg-sig-placeholder i{color:rgba(255,255,255,.6);font-size:1.2rem;}
.rg-msg-sig-name{color:#fff;font-weight:700;font-size:1rem;margin-bottom:2px;}
.rg-msg-sig-role{color:var(--rg-gold);font-size:.8rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;}
/* Staff Grid */
.rg-staff-section{background:var(--rg-light);padding:80px 0;}
.rg-staff-card{background:#fff;border-radius:18px;box-shadow:var(--rg-shadow);overflow:hidden;height:100%;transition:box-shadow var(--rg-trans),transform var(--rg-trans);border:1px solid rgba(0,33,71,.06);}
.rg-staff-card:hover{box-shadow:var(--rg-shadow-h);transform:translateY(-5px);}
.rg-staff-card-accent{height:4px;background:linear-gradient(90deg,var(--rg-blue),var(--rg-gold));}
.rg-staff-card-body{padding:24px;}
.rg-staff-photo{width:72px;height:72px;border-radius:50%;object-fit:cover;object-position:top center;border:3px solid #fff;box-shadow:0 4px 16px rgba(0,33,71,.12);}
.rg-staff-avatar{width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,var(--rg-blue),#3b82f6);display:flex;align-items:center;justify-content:center;box-shadow:0 4px 14px rgba(26,79,175,.2);}
.rg-staff-avatar i{color:#fff;font-size:1.5rem;}
.rg-staff-name{font-size:1rem;font-weight:700;color:var(--rg-navy);margin-bottom:4px;}
.rg-staff-title{display:inline-block;background:rgba(26,79,175,.1);color:var(--rg-blue);font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;padding:2px 10px;border-radius:50px;margin-bottom:12px;}
.rg-staff-meta{font-size:.82rem;color:var(--rg-muted);}
.rg-staff-meta a{color:var(--rg-blue);text-decoration:none;}
.rg-staff-meta a:hover{text-decoration:underline;}
.rg-section-tag{display:inline-flex;align-items:center;gap:8px;background:rgba(0,33,71,.07);color:var(--rg-navy);font-size:.76rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:6px 16px;border-radius:50px;margin-bottom:12px;}
.rg-section-tag .dot-sm{width:6px;height:6px;border-radius:50%;background:var(--rg-gold);}
.rg-section-title{font-size:clamp(1.5rem,3.5vw,2.2rem);font-weight:800;color:var(--rg-navy);margin-bottom:12px;line-height:1.2;}
.rg-divider{width:56px;height:4px;border-radius:2px;background:linear-gradient(90deg,var(--rg-gold),var(--rg-blue));margin:14px 0 0;}
.rg-fade{opacity:0;transform:translateY(30px);transition:opacity .7s ease,transform .7s ease;}
.rg-fade.visible{opacity:1;transform:translateY(0);}
.rg-fade-delay-1{transition-delay:.1s;}.rg-fade-delay-2{transition-delay:.2s;}.rg-fade-delay-3{transition-delay:.3s;}
</style>
</head>
<body>
<?php include __DIR__ . '/includes/header-top.php'; ?>
<?php include __DIR__ . '/includes/nav-menu.php'; ?>

<section class="rg-hero">
    <div class="rg-circle c1"></div><div class="rg-circle c2"></div><div class="rg-circle c3"></div>
    <div class="container" style="position:relative;z-index:2;">
        <div class="breadcrumb-nav">
            <a href="/index.php">Home</a><span class="sep">/</span>
            <a href="#">About</a><span class="sep">/</span>
            <span style="color:rgba(255,255,255,.85);"><?= fh(regs($s,'hero_title','Office of the Registrar')) ?></span>
        </div>
        <div class="rg-hero-tag"><span class="dot"></span>Prime University · Bangladesh</div>
        <h1><?php $w=explode(' ',regs($s,'hero_title','Office of the Registrar'));$l=array_pop($w);echo fh(implode(' ',$w)).' <span class="accent">'.fh($l).'</span>'; ?></h1>
        <?php if (regs($s,'hero_subtitle','')!==''): ?><p class="rg-designation" style="background:rgba(255,184,28,.18);color:var(--rg-gold);margin-bottom:16px;"><?= fh(regs($s,'hero_subtitle','')) ?></p><?php endif; ?>
        <?php if (regs($s,'hero_intro','')!==''): ?><p class="hero-sub"><?= fh(regs($s,'hero_intro','')) ?></p><?php endif; ?>
    </div>
</section>

<div class="rg-facts-strip">
    <div class="container"><div class="row g-0 justify-content-center">
        <?php if (regs($s,'reg_phone','')!==''): ?>
        <div class="col-sm-6 col-md-4 col-lg-3"><div class="rg-fact-item"><i class="fas fa-phone rg-fact-icon"></i><div class="rg-fact-text"><strong><?= fh(regs($s,'reg_phone','')) ?></strong><span>Registrar Direct Line</span></div></div></div>
        <?php endif; ?>
        <?php if (regs($s,'reg_email_1','')!==''): ?>
        <div class="col-sm-6 col-md-4 col-lg-4"><div class="rg-fact-item"><i class="fas fa-envelope rg-fact-icon"></i><div class="rg-fact-text"><strong><?= fh(regs($s,'reg_email_1','')) ?></strong><span>Official Email</span></div></div></div>
        <?php endif; ?>
        <?php if (count($staff)>0): ?>
        <div class="col-sm-6 col-md-4 col-lg-3"><div class="rg-fact-item"><i class="fas fa-users rg-fact-icon"></i><div class="rg-fact-text"><strong><?= count($staff) ?> Staff Members</strong><span>Office Team</span></div></div></div>
        <?php endif; ?>
    </div></div>
</div>

<section class="rg-profile-section">
    <div class="container"><div class="row justify-content-center"><div class="col-xl-10 col-lg-11">
        <div class="rg-profile-card rg-fade">
            <div class="rg-card-accent"></div>
            <div class="rg-card-body">
                <div class="row align-items-center g-4">
                    <div class="col-md-auto text-center text-md-start">
                        <div class="rg-photo-wrap">
                            <?php if ($reg_photo_url): ?><img src="<?= fh($reg_photo_url) ?>" alt="<?= fh(regs($s,'reg_name','Registrar')) ?>">
                            <?php else: ?><div class="rg-photo-placeholder"><i class="fas fa-stamp"></i></div><?php endif; ?>
                            <div class="rg-photo-badge"><i class="fas fa-star"></i></div>
                        </div>
                    </div>
                    <div class="col-md">
                        <h2 class="rg-name"><?= fh(regs($s,'reg_name','Prof. Dr. Md Mustafa Kamal')) ?></h2>
                        <div class="rg-designation"><i class="fas fa-stamp me-1"></i><?= fh(regs($s,'reg_title','Registrar')) ?></div>
                        <ul class="rg-contact-list">
                            <?php if (regs($s,'reg_email_1','')!==''): ?>
                            <li><div class="rg-contact-icon"><i class="fas fa-envelope"></i></div>
                                <div><div style="font-size:.72rem;color:var(--rg-muted);text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:2px;">Email</div>
                                    <a href="mailto:<?= fh(regs($s,'reg_email_1','')) ?>"><?= fh(regs($s,'reg_email_1','')) ?></a>
                                    <?php if (regs($s,'reg_email_2','')!==''): ?>&nbsp;&middot;&nbsp;<a href="mailto:<?= fh(regs($s,'reg_email_2','')) ?>"><?= fh(regs($s,'reg_email_2','')) ?></a><?php endif; ?>
                                </div>
                            </li>
                            <?php endif; ?>
                            <?php if (regs($s,'reg_phone','')!==''): ?>
                            <li><div class="rg-contact-icon"><i class="fas fa-phone"></i></div>
                                <div><div style="font-size:.72rem;color:var(--rg-muted);text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:2px;">Phone</div>
                                    <a href="tel:<?= fh(regs($s,'reg_phone','')) ?>"><?= fh(regs($s,'reg_phone','')) ?></a></div>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div></div></div>
</section>

<?php if (regs($s,'reg_bio','')!==''): ?>
<section class="rg-bio-section">
    <div class="container"><div class="row justify-content-center"><div class="col-xl-10 col-lg-11">
        <div class="rg-bio-card rg-fade rg-fade-delay-1"><p><?= nl2br(fh(regs($s,'reg_bio',''))) ?></p></div>
    </div></div></div>
</section>
<?php endif; ?>

<?php if (!empty($message_paragraphs)): ?>
<section class="rg-message-section">
    <div class="container"><div class="row justify-content-center"><div class="col-xl-10 col-lg-11">
        <div class="text-center mb-5 rg-fade">
            <div class="rg-section-tag" style="display:inline-flex;"><span class="dot-sm"></span>Leadership Message</div>
            <h2 class="rg-section-title"><?= fh(regs($s,'message_title','Message from the Registrar')) ?></h2>
            <div class="rg-divider mx-auto"></div>
        </div>
        <div class="rg-message-card rg-fade rg-fade-delay-1">
            <div class="rg-msg-header">
                <div class="rg-msg-icon"><i class="fas fa-quote-left"></i></div>
                <h3 class="rg-msg-title"><?= fh(regs($s,'message_title','Message from the Registrar')) ?></h3>
                <p class="rg-msg-subtitle"><?= fh(regs($s,'reg_name','Registrar')) ?> &middot; Prime University</p>
                <div class="rg-msg-divider"></div>
            </div>
            <div class="rg-msg-body">
                <?php foreach ($message_paragraphs as $para): ?><p><?= fh($para) ?></p><?php endforeach; ?>
            </div>
            <div class="rg-msg-sig">
                <?php if ($reg_photo_url): ?><img src="<?= fh($reg_photo_url) ?>" class="rg-msg-sig-photo" alt="<?= fh(regs($s,'reg_name','')) ?>">
                <?php else: ?><div class="rg-msg-sig-placeholder"><i class="fas fa-stamp"></i></div><?php endif; ?>
                <div><div class="rg-msg-sig-name"><?= fh(regs($s,'reg_name','Prof. Dr. Md Mustafa Kamal')) ?></div>
                    <div class="rg-msg-sig-role"><?= fh(regs($s,'reg_title','Registrar')) ?> &mdash; Prime University</div></div>
            </div>
        </div>
    </div></div></div>
</section>
<?php endif; ?>

<?php if (!empty($staff)): ?>
<section class="rg-staff-section">
    <div class="container"><div class="row justify-content-center"><div class="col-xl-10 col-lg-11">
        <div class="mb-5 rg-fade">
            <div class="rg-section-tag"><span class="dot-sm"></span>Office Team</div>
            <h2 class="rg-section-title">Office Staff Directory</h2>
            <div class="rg-divider"></div>
        </div>
        <div class="row g-4">
            <?php foreach ($staff as $i => $st):
                $st_photo = !empty($st['photo']) ? ADMIN_UPLOAD_URL . '/office-of-registrar/' . $st['photo'] : '';
                $delay_class = $i % 3 === 0 ? '' : ($i % 3 === 1 ? 'rg-fade-delay-1' : 'rg-fade-delay-2');
            ?>
            <div class="col-sm-6 col-md-4 col-lg-4">
                <div class="rg-staff-card rg-fade <?= $delay_class ?>">
                    <div class="rg-staff-card-accent"></div>
                    <div class="rg-staff-card-body">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <?php if ($st_photo): ?>
                            <img src="<?= fh($st_photo) ?>" alt="<?= fh($st['name']) ?>" class="rg-staff-photo">
                            <?php else: ?>
                            <div class="rg-staff-avatar"><i class="fas fa-user"></i></div>
                            <?php endif; ?>
                            <div>
                                <div class="rg-staff-name"><?= fh($st['name']) ?></div>
                                <?php if ($st['title']): ?><div class="rg-staff-title"><?= fh($st['title']) ?></div><?php endif; ?>
                            </div>
                        </div>
                        <div class="rg-staff-meta">
                            <?php if ($st['email_1']): ?>
                            <div class="mb-1"><i class="fas fa-envelope me-2" style="color:var(--rg-blue);font-size:.8rem;"></i>
                                <a href="mailto:<?= fh($st['email_1']) ?>"><?= fh($st['email_1']) ?></a></div>
                            <?php endif; ?>
                            <?php if ($st['email_2']): ?>
                            <div class="mb-1"><i class="fas fa-envelope me-2" style="color:var(--rg-blue);font-size:.8rem;"></i>
                                <a href="mailto:<?= fh($st['email_2']) ?>"><?= fh($st['email_2']) ?></a></div>
                            <?php endif; ?>
                            <?php if ($st['phone']): ?>
                            <div><i class="fas fa-phone me-2" style="color:var(--rg-blue);font-size:.8rem;"></i><?= fh($st['phone']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div></div></div>
</section>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
<?php include __DIR__ . '/includes/scripts.php'; ?>
<script>
(function(){const els=document.querySelectorAll('.rg-fade');if(!els.length)return;
const io=new IntersectionObserver(function(entries){entries.forEach(function(e){if(e.isIntersecting){e.target.classList.add('visible');io.unobserve(e.target);}});},{threshold:0.12});
els.forEach(function(el){io.observe(el);});})();
</script>
</body>
</html>
