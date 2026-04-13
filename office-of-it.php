<?php
require_once __DIR__ . '/includes/config.php';

$s = [];
$staff = [];
try {
    $db = front_db();
    if ($db) {
        $rows = $db->query('SELECT setting_key, setting_val FROM it_settings')->fetchAll();
        foreach ($rows as $r) $s[$r['setting_key']] = $r['setting_val'];
        $staff = $db->query('SELECT * FROM it_staff WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
    }
} catch (Throwable $e) {}

function its(array $s, string $key, string $default = ''): string {
    return isset($s[$key]) && $s[$key] !== '' ? $s[$key] : $default;
}

if (its($s, 'is_published', '1') !== '1') { header('Location: /index.php'); exit; }

$page_title = its($s, 'hero_title', 'Office of IT') . ' – Prime University';
$meta_desc  = its($s, 'meta_description', 'Office of IT – Prime University');

$message_paragraphs = [];
$raw_message = its($s, 'message_body', '');
if ($raw_message !== '') {
    $message_paragraphs = array_filter(
        array_map('trim', preg_split('/\n{2,}/', $raw_message)),
        fn($p) => $p !== ''
    );
}

$head_photo_url = !empty($s['head_photo']) ? ADMIN_UPLOAD_URL . '/office-of-it/' . $s['head_photo'] : '';
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
  --it-navy:#002147;--it-gold:#FFB81C;--it-blue:#1a4faf;
  --it-light:#f4f7fb;--it-text:#334155;--it-muted:#64748b;
  --it-shadow:0 8px 40px rgba(0,33,71,.10);--it-shadow-h:0 18px 60px rgba(0,33,71,.18);
  --it-trans:.35s cubic-bezier(.4,0,.2,1);
}
.it-hero{background:linear-gradient(135deg,#001530 0%,#002f68 55%,#1a4faf 100%);padding:110px 0 90px;position:relative;overflow:hidden;}
.it-hero::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 60% 80% at 80% 50%,rgba(255,184,28,.10) 0%,transparent 70%),radial-gradient(ellipse 40% 60% at 10% 80%,rgba(26,79,175,.35) 0%,transparent 60%);pointer-events:none;}
.it-circle{position:absolute;border-radius:50%;pointer-events:none;animation:itFloat 9s ease-in-out infinite;}
.it-circle.c1{width:380px;height:380px;background:rgba(255,184,28,.07);top:-90px;right:-70px;}
.it-circle.c2{width:200px;height:200px;background:rgba(255,255,255,.05);bottom:20px;left:4%;animation-delay:3s;}
.it-circle.c3{width:120px;height:120px;background:rgba(255,184,28,.10);top:35%;right:22%;animation-delay:1.5s;}
@keyframes itFloat{0%,100%{transform:translateY(0) scale(1);}50%{transform:translateY(-22px) scale(1.05);}}
.it-hero .breadcrumb-nav{display:flex;align-items:center;gap:8px;font-size:.82rem;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:rgba(255,255,255,.65);margin-bottom:24px;}
.it-hero .breadcrumb-nav a{color:var(--it-gold);text-decoration:none;}
.it-hero .breadcrumb-nav .sep{color:rgba(255,255,255,.35);}
.it-hero-tag{display:inline-flex;align-items:center;gap:8px;background:rgba(255,184,28,.18);color:var(--it-gold);font-size:.77rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;padding:7px 18px;border-radius:50px;border:1px solid rgba(255,184,28,.35);margin-bottom:20px;}
.it-hero-tag .dot{width:7px;height:7px;border-radius:50%;background:var(--it-gold);animation:itPulse 1.8s ease-in-out infinite;}
@keyframes itPulse{0%,100%{opacity:1;transform:scale(1);}50%{opacity:.5;transform:scale(1.6);}}
.it-hero h1{font-size:clamp(2rem,5vw,3.4rem);font-weight:800;color:#fff;line-height:1.15;margin-bottom:18px;}
.it-hero h1 .accent{color:var(--it-gold);}
.it-hero .hero-sub{font-size:clamp(.93rem,1.8vw,1.1rem);color:rgba(255,255,255,.78);max-width:580px;line-height:1.75;}
.it-facts-strip{background:var(--it-navy);padding:24px 0;}
.it-fact-item{display:flex;align-items:center;gap:12px;color:rgba(255,255,255,.85);padding:10px 20px;border-right:1px solid rgba(255,255,255,.12);}
.it-fact-item:last-child{border-right:none;}
.it-fact-icon{color:var(--it-gold);font-size:1.2rem;flex-shrink:0;}
.it-fact-text strong{display:block;font-size:.92rem;font-weight:700;color:#fff;}
.it-fact-text span{font-size:.77rem;color:rgba(255,255,255,.55);letter-spacing:.04em;text-transform:uppercase;}
@media(max-width:767.98px){.it-fact-item{border-right:none;border-bottom:1px solid rgba(255,255,255,.10);}.it-fact-item:last-child{border-bottom:none;}}
.it-profile-section{padding:80px 0 60px;background:#fff;}
.it-profile-card{background:#fff;border-radius:24px;box-shadow:var(--it-shadow);overflow:hidden;transition:box-shadow var(--it-trans);border:1px solid rgba(0,33,71,.06);}
.it-profile-card:hover{box-shadow:var(--it-shadow-h);}
.it-card-accent{height:6px;background:linear-gradient(90deg,var(--it-navy) 0%,var(--it-blue) 50%,var(--it-gold) 100%);}
.it-card-body{padding:40px;}
@media(max-width:767.98px){.it-card-body{padding:28px 20px;}}
.it-photo-wrap{position:relative;display:inline-block;}
.it-photo-wrap img{width:160px;height:160px;border-radius:50%;object-fit:cover;object-position:top center;border:5px solid #fff;box-shadow:0 8px 32px rgba(0,33,71,.18);display:block;}
.it-photo-badge{position:absolute;bottom:6px;right:6px;width:32px;height:32px;border-radius:50%;background:var(--it-gold);border:3px solid #fff;display:flex;align-items:center;justify-content:center;}
.it-photo-badge i{color:var(--it-navy);font-size:.7rem;}
.it-photo-placeholder{width:160px;height:160px;border-radius:50%;background:linear-gradient(135deg,var(--it-navy),var(--it-blue));display:flex;align-items:center;justify-content:center;box-shadow:0 8px 32px rgba(0,33,71,.18);border:5px solid #fff;}
.it-photo-placeholder i{color:rgba(255,255,255,.75);font-size:3.5rem;}
.it-name{font-size:clamp(1.3rem,3vw,1.75rem);font-weight:800;color:var(--it-navy);margin-bottom:4px;}
.it-designation{display:inline-flex;align-items:center;gap:8px;background:var(--it-navy);color:var(--it-gold);font-size:.8rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:5px 16px;border-radius:50px;margin-bottom:24px;}
.it-contact-list{list-style:none;padding:0;margin:0;}
.it-contact-list li{display:flex;align-items:flex-start;gap:12px;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:.9rem;color:var(--it-text);}
.it-contact-list li:last-child{border-bottom:none;}
.it-contact-icon{width:34px;height:34px;border-radius:8px;flex-shrink:0;background:var(--it-light);display:flex;align-items:center;justify-content:center;}
.it-contact-icon i{color:var(--it-blue);font-size:.85rem;}
.it-contact-list a{color:var(--it-blue);text-decoration:none;transition:color var(--it-trans);}
.it-contact-list a:hover{color:var(--it-navy);text-decoration:underline;}
.it-bio-section{background:var(--it-light);padding:70px 0;}
.it-bio-card{background:#fff;border-radius:20px;padding:44px 48px;box-shadow:var(--it-shadow);border-left:5px solid var(--it-gold);position:relative;}
@media(max-width:767.98px){.it-bio-card{padding:28px 22px;}}
.it-bio-card p{font-size:1.02rem;color:var(--it-text);line-height:1.85;margin-bottom:0;}
.it-message-section{padding:80px 0;background:#fff;position:relative;overflow:hidden;}
.it-message-section::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,var(--it-navy),var(--it-blue),var(--it-gold));}
.it-message-card{background:linear-gradient(135deg,#001e45 0%,#002f68 100%);border-radius:24px;padding:56px 56px 48px;position:relative;overflow:hidden;box-shadow:0 16px 56px rgba(0,33,71,.22);}
@media(max-width:767.98px){.it-message-card{padding:36px 24px 32px;}}
.it-message-card::before{content:'';position:absolute;top:-60px;right:-60px;width:280px;height:280px;border-radius:50%;background:rgba(255,184,28,.08);pointer-events:none;}
.it-message-card::after{content:'';position:absolute;bottom:-80px;left:-40px;width:220px;height:220px;border-radius:50%;background:rgba(26,79,175,.3);pointer-events:none;}
.it-msg-header{position:relative;z-index:1;margin-bottom:32px;}
.it-msg-icon{width:56px;height:56px;border-radius:14px;background:rgba(255,184,28,.2);border:1px solid rgba(255,184,28,.4);display:flex;align-items:center;justify-content:center;margin-bottom:16px;}
.it-msg-icon i{color:var(--it-gold);font-size:1.4rem;}
.it-msg-title{font-size:clamp(1.35rem,3vw,1.9rem);font-weight:800;color:#fff;margin-bottom:4px;}
.it-msg-subtitle{color:rgba(255,255,255,.6);font-size:.9rem;}
.it-msg-divider{height:2px;width:60px;background:linear-gradient(90deg,var(--it-gold),transparent);margin:20px 0 28px;}
.it-msg-body{position:relative;z-index:1;}
.it-msg-body p{color:rgba(255,255,255,.88);font-size:1rem;line-height:1.9;margin-bottom:1.4rem;}
.it-msg-body p:last-child{margin-bottom:0;}
.it-msg-sig{position:relative;z-index:1;margin-top:36px;padding-top:24px;border-top:1px solid rgba(255,255,255,.12);display:flex;align-items:center;gap:16px;flex-wrap:wrap;}
.it-msg-sig-photo{width:54px;height:54px;border-radius:50%;object-fit:cover;object-position:top center;border:2px solid rgba(255,184,28,.4);flex-shrink:0;}
.it-msg-sig-placeholder{width:54px;height:54px;border-radius:50%;flex-shrink:0;background:rgba(255,255,255,.1);display:flex;align-items:center;justify-content:center;border:2px solid rgba(255,184,28,.3);}
.it-msg-sig-placeholder i{color:rgba(255,255,255,.6);font-size:1.2rem;}
.it-msg-sig-name{color:#fff;font-weight:700;font-size:1rem;margin-bottom:2px;}
.it-msg-sig-role{color:var(--it-gold);font-size:.8rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;}
/* Staff Grid */
.it-staff-section{background:var(--it-light);padding:80px 0;}
.it-staff-card{background:#fff;border-radius:18px;box-shadow:var(--it-shadow);overflow:hidden;height:100%;transition:box-shadow var(--it-trans),transform var(--it-trans);border:1px solid rgba(0,33,71,.06);}
.it-staff-card:hover{box-shadow:var(--it-shadow-h);transform:translateY(-5px);}
.it-staff-card-accent{height:4px;background:linear-gradient(90deg,var(--it-blue),var(--it-gold));}
.it-staff-card-body{padding:24px;}
.it-staff-photo{width:72px;height:72px;border-radius:50%;object-fit:cover;object-position:top center;border:3px solid #fff;box-shadow:0 4px 16px rgba(0,33,71,.12);}
.it-staff-avatar{width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,var(--it-blue),#3b82f6);display:flex;align-items:center;justify-content:center;box-shadow:0 4px 14px rgba(26,79,175,.2);}
.it-staff-avatar i{color:#fff;font-size:1.5rem;}
.it-staff-name{font-size:1rem;font-weight:700;color:var(--it-navy);margin-bottom:4px;}
.it-staff-title{display:inline-block;background:rgba(26,79,175,.1);color:var(--it-blue);font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;padding:2px 10px;border-radius:50px;margin-bottom:12px;}
.it-staff-meta{font-size:.82rem;color:var(--it-muted);}
.it-staff-meta a{color:var(--it-blue);text-decoration:none;}
.it-staff-meta a:hover{text-decoration:underline;}
.it-section-tag{display:inline-flex;align-items:center;gap:8px;background:rgba(0,33,71,.07);color:var(--it-navy);font-size:.76rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:6px 16px;border-radius:50px;margin-bottom:12px;}
.it-section-tag .dot-sm{width:6px;height:6px;border-radius:50%;background:var(--it-gold);}
.it-section-title{font-size:clamp(1.5rem,3.5vw,2.2rem);font-weight:800;color:var(--it-navy);margin-bottom:12px;line-height:1.2;}
.it-divider{width:56px;height:4px;border-radius:2px;background:linear-gradient(90deg,var(--it-gold),var(--it-blue));margin:14px 0 0;}
.it-fade{opacity:0;transform:translateY(30px);transition:opacity .7s ease,transform .7s ease;}
.it-fade.visible{opacity:1;transform:translateY(0);}
.it-fade-delay-1{transition-delay:.1s;}.it-fade-delay-2{transition-delay:.2s;}.it-fade-delay-3{transition-delay:.3s;}
</style>
</head>
<body>
<?php include __DIR__ . '/includes/header-top.php'; ?>
<?php include __DIR__ . '/includes/nav-menu.php'; ?>

<section class="it-hero">
    <div class="it-circle c1"></div><div class="it-circle c2"></div><div class="it-circle c3"></div>
    <div class="container" style="position:relative;z-index:2;">
        <div class="breadcrumb-nav">
            <a href="/index.php">Home</a><span class="sep">/</span>
            <a href="#">About</a><span class="sep">/</span>
            <span style="color:rgba(255,255,255,.85);"><?= fh(its($s,'hero_title','Office of IT')) ?></span>
        </div>
        <div class="it-hero-tag"><span class="dot"></span>Prime University · Bangladesh</div>
        <h1><?php $w=explode(' ',its($s,'hero_title','Office of IT'));$l=array_pop($w);echo fh(implode(' ',$w)).' <span class="accent">'.fh($l).'</span>'; ?></h1>
        <?php if (its($s,'hero_subtitle','')!==''): ?><p class="it-designation" style="background:rgba(255,184,28,.18);color:var(--it-gold);margin-bottom:16px;"><?= fh(its($s,'hero_subtitle','')) ?></p><?php endif; ?>
        <?php if (its($s,'hero_intro','')!==''): ?><p class="hero-sub"><?= fh(its($s,'hero_intro','')) ?></p><?php endif; ?>
    </div>
</section>

<div class="it-facts-strip">
    <div class="container"><div class="row g-0 justify-content-center">
        <?php if (its($s,'head_phone','')!==''): ?>
        <div class="col-sm-6 col-md-4 col-lg-3"><div class="it-fact-item"><i class="fas fa-phone it-fact-icon"></i><div class="it-fact-text"><strong><?= fh(its($s,'head_phone','')) ?></strong><span>Direct Line</span></div></div></div>
        <?php endif; ?>
        <?php if (its($s,'head_email_1','')!==''): ?>
        <div class="col-sm-6 col-md-4 col-lg-4"><div class="it-fact-item"><i class="fas fa-envelope it-fact-icon"></i><div class="it-fact-text"><strong><?= fh(its($s,'head_email_1','')) ?></strong><span>Official Email</span></div></div></div>
        <?php endif; ?>
        <?php if (count($staff)>0): ?>
        <div class="col-sm-6 col-md-4 col-lg-3"><div class="it-fact-item"><i class="fas fa-users it-fact-icon"></i><div class="it-fact-text"><strong><?= count($staff) ?> Staff Member<?= count($staff)!==1?'s':'' ?></strong><span>Office Team</span></div></div></div>
        <?php endif; ?>
    </div></div>
</div>

<section class="it-profile-section">
    <div class="container"><div class="row justify-content-center"><div class="col-xl-10 col-lg-11">
        <div class="it-profile-card it-fade">
            <div class="it-card-accent"></div>
            <div class="it-card-body">
                <div class="row align-items-center g-4">
                    <div class="col-md-auto text-center text-md-start">
                        <div class="it-photo-wrap">
                            <?php if ($head_photo_url): ?><img src="<?= fh($head_photo_url) ?>" alt="<?= fh(its($s,'head_name','Head of IT')) ?>">
                            <?php else: ?><div class="it-photo-placeholder"><i class="fas fa-laptop-code"></i></div><?php endif; ?>
                            <div class="it-photo-badge"><i class="fas fa-star"></i></div>
                        </div>
                    </div>
                    <div class="col-md">
                        <h2 class="it-name"><?= fh(its($s,'head_name','Md Omar Faruk')) ?></h2>
                        <div class="it-designation"><i class="fas fa-laptop-code me-1"></i><?= fh(its($s,'head_title','Deputy Director')) ?></div>
                        <ul class="it-contact-list">
                            <?php if (its($s,'head_email_1','')!==''): ?>
                            <li><div class="it-contact-icon"><i class="fas fa-envelope"></i></div>
                                <div><div style="font-size:.72rem;color:var(--it-muted);text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:2px;">Email</div>
                                    <a href="mailto:<?= fh(its($s,'head_email_1','')) ?>"><?= fh(its($s,'head_email_1','')) ?></a>
                                    <?php if (its($s,'head_email_2','')!==''): ?>&nbsp;&middot;&nbsp;<a href="mailto:<?= fh(its($s,'head_email_2','')) ?>"><?= fh(its($s,'head_email_2','')) ?></a><?php endif; ?>
                                </div>
                            </li>
                            <?php endif; ?>
                            <?php if (its($s,'head_phone','')!==''): ?>
                            <li><div class="it-contact-icon"><i class="fas fa-phone"></i></div>
                                <div><div style="font-size:.72rem;color:var(--it-muted);text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:2px;">Phone</div>
                                    <a href="tel:<?= fh(its($s,'head_phone','')) ?>"><?= fh(its($s,'head_phone','')) ?></a></div>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div></div></div>
</section>

<?php if (its($s,'head_bio','')!==''): ?>
<section class="it-bio-section">
    <div class="container"><div class="row justify-content-center"><div class="col-xl-10 col-lg-11">
        <div class="it-bio-card it-fade it-fade-delay-1"><p><?= nl2br(fh(its($s,'head_bio',''))) ?></p></div>
    </div></div></div>
</section>
<?php endif; ?>

<?php if (!empty($message_paragraphs)): ?>
<section class="it-message-section">
    <div class="container"><div class="row justify-content-center"><div class="col-xl-10 col-lg-11">
        <div class="text-center mb-5 it-fade">
            <div class="it-section-tag" style="display:inline-flex;"><span class="dot-sm"></span>Leadership Message</div>
            <h2 class="it-section-title"><?= fh(its($s,'message_title','Message from the Office of IT')) ?></h2>
            <div class="it-divider mx-auto"></div>
        </div>
        <div class="it-message-card it-fade it-fade-delay-1">
            <div class="it-msg-header">
                <div class="it-msg-icon"><i class="fas fa-quote-left"></i></div>
                <h3 class="it-msg-title"><?= fh(its($s,'message_title','Message from the Office of IT')) ?></h3>
                <p class="it-msg-subtitle"><?= fh(its($s,'head_name','IT Office')) ?> &middot; Prime University</p>
                <div class="it-msg-divider"></div>
            </div>
            <div class="it-msg-body">
                <?php foreach ($message_paragraphs as $para): ?><p><?= fh($para) ?></p><?php endforeach; ?>
            </div>
            <div class="it-msg-sig">
                <?php if ($head_photo_url): ?><img src="<?= fh($head_photo_url) ?>" class="it-msg-sig-photo" alt="<?= fh(its($s,'head_name','')) ?>">
                <?php else: ?><div class="it-msg-sig-placeholder"><i class="fas fa-laptop-code"></i></div><?php endif; ?>
                <div><div class="it-msg-sig-name"><?= fh(its($s,'head_name','Md Omar Faruk')) ?></div>
                    <div class="it-msg-sig-role"><?= fh(its($s,'head_title','Deputy Director')) ?> &mdash; Prime University</div></div>
            </div>
        </div>
    </div></div></div>
</section>
<?php endif; ?>

<?php if (!empty($staff)): ?>
<section class="it-staff-section">
    <div class="container"><div class="row justify-content-center"><div class="col-xl-10 col-lg-11">
        <div class="mb-5 it-fade">
            <div class="it-section-tag"><span class="dot-sm"></span>Office Team</div>
            <h2 class="it-section-title">IT Staff Directory</h2>
            <div class="it-divider"></div>
        </div>
        <div class="row g-4">
            <?php foreach ($staff as $i => $st):
                $st_photo = !empty($st['photo']) ? ADMIN_UPLOAD_URL . '/office-of-it/' . $st['photo'] : '';
                $delay_class = $i % 3 === 0 ? '' : ($i % 3 === 1 ? 'it-fade-delay-1' : 'it-fade-delay-2');
            ?>
            <div class="col-sm-6 col-md-4 col-lg-4">
                <div class="it-staff-card it-fade <?= $delay_class ?>">
                    <div class="it-staff-card-accent"></div>
                    <div class="it-staff-card-body">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <?php if ($st_photo): ?>
                            <img src="<?= fh($st_photo) ?>" alt="<?= fh($st['name']) ?>" class="it-staff-photo">
                            <?php else: ?>
                            <div class="it-staff-avatar"><i class="fas fa-user"></i></div>
                            <?php endif; ?>
                            <div>
                                <div class="it-staff-name"><?= fh($st['name']) ?></div>
                                <?php if ($st['title']): ?><div class="it-staff-title"><?= fh($st['title']) ?></div><?php endif; ?>
                            </div>
                        </div>
                        <div class="it-staff-meta">
                            <?php if ($st['email_1']): ?>
                            <div class="mb-1"><i class="fas fa-envelope me-2" style="color:var(--it-blue);font-size:.8rem;"></i>
                                <a href="mailto:<?= fh($st['email_1']) ?>"><?= fh($st['email_1']) ?></a></div>
                            <?php endif; ?>
                            <?php if ($st['email_2']): ?>
                            <div class="mb-1"><i class="fas fa-envelope me-2" style="color:var(--it-blue);font-size:.8rem;"></i>
                                <a href="mailto:<?= fh($st['email_2']) ?>"><?= fh($st['email_2']) ?></a></div>
                            <?php endif; ?>
                            <?php if ($st['phone']): ?>
                            <div><i class="fas fa-phone me-2" style="color:var(--it-blue);font-size:.8rem;"></i><?= fh($st['phone']) ?></div>
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
(function(){const els=document.querySelectorAll('.it-fade');if(!els.length)return;
const io=new IntersectionObserver(function(entries){entries.forEach(function(e){if(e.isIntersecting){e.target.classList.add('visible');io.unobserve(e.target);}});},{threshold:0.12});
els.forEach(function(el){io.observe(el);});})();
</script>
</body>
</html>
