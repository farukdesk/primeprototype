<?php
require_once __DIR__ . '/includes/config.php';

$s = [];
try {
    $db = front_db();
    if ($db) {
        $rows = $db->query('SELECT setting_key, setting_val FROM tr_settings')->fetchAll();
        foreach ($rows as $r) $s[$r['setting_key']] = $r['setting_val'];
    }
} catch (Throwable $e) {}

function trs(array $s, string $key, string $default = ''): string {
    return isset($s[$key]) && $s[$key] !== '' ? $s[$key] : $default;
}

if (trs($s, 'is_published', '1') !== '1') { header('Location: /index.php'); exit; }

$page_title = trs($s, 'hero_title', 'Office of the Treasurer') . ' – Prime University';
$meta_desc  = trs($s, 'meta_description', 'Office of the Treasurer – Prime University');

$message_paragraphs = [];
$raw_message = trs($s, 'message_body', '');
if ($raw_message !== '') {
    $message_paragraphs = array_filter(
        array_map('trim', preg_split('/\n{2,}/', $raw_message)),
        fn($p) => $p !== ''
    );
}

$tr_photo_url = !empty($s['tr_photo']) ? ADMIN_UPLOAD_URL . '/office-of-treasurer/' . $s['tr_photo'] : '';
$pa_photo_url = !empty($s['pa_photo']) ? ADMIN_UPLOAD_URL . '/office-of-treasurer/' . $s['pa_photo'] : '';
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
:root {
  --tr-navy:#002147;--tr-gold:#FFB81C;--tr-blue:#1a4faf;
  --tr-light:#f4f7fb;--tr-text:#334155;--tr-muted:#64748b;
  --tr-shadow:0 8px 40px rgba(0,33,71,.10);--tr-shadow-h:0 18px 60px rgba(0,33,71,.18);
  --tr-trans:.35s cubic-bezier(.4,0,.2,1);
}
.tr-hero{background:linear-gradient(135deg,#001530 0%,#002f68 55%,#1a4faf 100%);padding:110px 0 90px;position:relative;overflow:hidden;}
.tr-hero::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 60% 80% at 80% 50%,rgba(255,184,28,.10) 0%,transparent 70%),radial-gradient(ellipse 40% 60% at 10% 80%,rgba(26,79,175,.35) 0%,transparent 60%);pointer-events:none;}
.tr-circle{position:absolute;border-radius:50%;pointer-events:none;animation:trFloat 9s ease-in-out infinite;}
.tr-circle.c1{width:380px;height:380px;background:rgba(255,184,28,.07);top:-90px;right:-70px;}
.tr-circle.c2{width:200px;height:200px;background:rgba(255,255,255,.05);bottom:20px;left:4%;animation-delay:3s;}
.tr-circle.c3{width:120px;height:120px;background:rgba(255,184,28,.10);top:35%;right:22%;animation-delay:1.5s;}
@keyframes trFloat{0%,100%{transform:translateY(0) scale(1);}50%{transform:translateY(-22px) scale(1.05);}}
.tr-hero .breadcrumb-nav{display:flex;align-items:center;gap:8px;font-size:.82rem;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:rgba(255,255,255,.65);margin-bottom:24px;}
.tr-hero .breadcrumb-nav a{color:var(--tr-gold);text-decoration:none;}
.tr-hero .breadcrumb-nav .sep{color:rgba(255,255,255,.35);}
.tr-hero-tag{display:inline-flex;align-items:center;gap:8px;background:rgba(255,184,28,.18);color:var(--tr-gold);font-size:.77rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;padding:7px 18px;border-radius:50px;border:1px solid rgba(255,184,28,.35);margin-bottom:20px;}
.tr-hero-tag .dot{width:7px;height:7px;border-radius:50%;background:var(--tr-gold);animation:trPulse 1.8s ease-in-out infinite;}
@keyframes trPulse{0%,100%{opacity:1;transform:scale(1);}50%{opacity:.5;transform:scale(1.6);}}
.tr-hero h1{font-size:clamp(2rem,5vw,3.4rem);font-weight:800;color:#fff;line-height:1.15;margin-bottom:18px;}
.tr-hero h1 .accent{color:var(--tr-gold);}
.tr-hero .hero-sub{font-size:clamp(.93rem,1.8vw,1.1rem);color:rgba(255,255,255,.78);max-width:580px;line-height:1.75;}
.tr-facts-strip{background:var(--tr-navy);padding:24px 0;}
.tr-fact-item{display:flex;align-items:center;gap:12px;color:rgba(255,255,255,.85);padding:10px 20px;border-right:1px solid rgba(255,255,255,.12);}
.tr-fact-item:last-child{border-right:none;}
.tr-fact-icon{color:var(--tr-gold);font-size:1.2rem;flex-shrink:0;}
.tr-fact-text strong{display:block;font-size:.92rem;font-weight:700;color:#fff;}
.tr-fact-text span{font-size:.77rem;color:rgba(255,255,255,.55);letter-spacing:.04em;text-transform:uppercase;}
@media(max-width:767.98px){.tr-fact-item{border-right:none;border-bottom:1px solid rgba(255,255,255,.10);}.tr-fact-item:last-child{border-bottom:none;}}
.tr-profile-section{padding:80px 0 60px;background:#fff;}
.tr-profile-card{background:#fff;border-radius:24px;box-shadow:var(--tr-shadow);overflow:hidden;transition:box-shadow var(--tr-trans);border:1px solid rgba(0,33,71,.06);}
.tr-profile-card:hover{box-shadow:var(--tr-shadow-h);}
.tr-card-accent{height:6px;background:linear-gradient(90deg,var(--tr-navy) 0%,var(--tr-blue) 50%,var(--tr-gold) 100%);}
.tr-card-body{padding:40px;}
@media(max-width:767.98px){.tr-card-body{padding:28px 20px;}}
.tr-photo-wrap{position:relative;display:inline-block;}
.tr-photo-wrap img{width:160px;height:160px;border-radius:50%;object-fit:cover;object-position:top center;border:5px solid #fff;box-shadow:0 8px 32px rgba(0,33,71,.18);display:block;}
.tr-photo-badge{position:absolute;bottom:6px;right:6px;width:32px;height:32px;border-radius:50%;background:var(--tr-gold);border:3px solid #fff;display:flex;align-items:center;justify-content:center;}
.tr-photo-badge i{color:var(--tr-navy);font-size:.7rem;}
.tr-photo-placeholder{width:160px;height:160px;border-radius:50%;background:linear-gradient(135deg,var(--tr-navy),var(--tr-blue));display:flex;align-items:center;justify-content:center;box-shadow:0 8px 32px rgba(0,33,71,.18);border:5px solid #fff;}
.tr-photo-placeholder i{color:rgba(255,255,255,.75);font-size:3.5rem;}
.tr-name{font-size:clamp(1.3rem,3vw,1.75rem);font-weight:800;color:var(--tr-navy);margin-bottom:4px;}
.tr-designation{display:inline-flex;align-items:center;gap:8px;background:var(--tr-navy);color:var(--tr-gold);font-size:.8rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:5px 16px;border-radius:50px;margin-bottom:24px;}
.tr-contact-list{list-style:none;padding:0;margin:0;}
.tr-contact-list li{display:flex;align-items:flex-start;gap:12px;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:.9rem;color:var(--tr-text);}
.tr-contact-list li:last-child{border-bottom:none;}
.tr-contact-icon{width:34px;height:34px;border-radius:8px;flex-shrink:0;background:var(--tr-light);display:flex;align-items:center;justify-content:center;}
.tr-contact-icon i{color:var(--tr-blue);font-size:.85rem;}
.tr-contact-list a{color:var(--tr-blue);text-decoration:none;transition:color var(--tr-trans);}
.tr-contact-list a:hover{color:var(--tr-navy);text-decoration:underline;}
.tr-bio-section{background:var(--tr-light);padding:70px 0;}
.tr-bio-card{background:#fff;border-radius:20px;padding:44px 48px;box-shadow:var(--tr-shadow);border-left:5px solid var(--tr-gold);position:relative;}
@media(max-width:767.98px){.tr-bio-card{padding:28px 22px;}}
.tr-bio-card::before{content:'\201C';position:absolute;top:16px;left:28px;font-size:6rem;line-height:1;color:rgba(0,33,71,.06);font-family:Georgia,serif;pointer-events:none;}
.tr-bio-card p{font-size:1.02rem;color:var(--tr-text);line-height:1.85;margin-bottom:0;}
.tr-message-section{padding:80px 0;background:#fff;position:relative;overflow:hidden;}
.tr-message-section::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,var(--tr-navy),var(--tr-blue),var(--tr-gold));}
.tr-message-card{background:linear-gradient(135deg,#001e45 0%,#002f68 100%);border-radius:24px;padding:56px 56px 48px;position:relative;overflow:hidden;box-shadow:0 16px 56px rgba(0,33,71,.22);}
@media(max-width:767.98px){.tr-message-card{padding:36px 24px 32px;}}
.tr-message-card::before{content:'';position:absolute;top:-60px;right:-60px;width:280px;height:280px;border-radius:50%;background:rgba(255,184,28,.08);pointer-events:none;}
.tr-message-card::after{content:'';position:absolute;bottom:-80px;left:-40px;width:220px;height:220px;border-radius:50%;background:rgba(26,79,175,.3);pointer-events:none;}
.tr-msg-header{position:relative;z-index:1;margin-bottom:32px;}
.tr-msg-icon{width:56px;height:56px;border-radius:14px;background:rgba(255,184,28,.2);border:1px solid rgba(255,184,28,.4);display:flex;align-items:center;justify-content:center;margin-bottom:16px;}
.tr-msg-icon i{color:var(--tr-gold);font-size:1.4rem;}
.tr-msg-title{font-size:clamp(1.35rem,3vw,1.9rem);font-weight:800;color:#fff;margin-bottom:4px;}
.tr-msg-subtitle{color:rgba(255,255,255,.6);font-size:.9rem;}
.tr-msg-divider{height:2px;width:60px;background:linear-gradient(90deg,var(--tr-gold),transparent);margin:20px 0 28px;}
.tr-msg-body{position:relative;z-index:1;}
.tr-msg-body p{color:rgba(255,255,255,.88);font-size:1rem;line-height:1.9;margin-bottom:1.4rem;}
.tr-msg-body p:last-child{margin-bottom:0;}
.tr-msg-sig{position:relative;z-index:1;margin-top:36px;padding-top:24px;border-top:1px solid rgba(255,255,255,.12);display:flex;align-items:center;gap:16px;flex-wrap:wrap;}
.tr-msg-sig-photo{width:54px;height:54px;border-radius:50%;object-fit:cover;object-position:top center;border:2px solid rgba(255,184,28,.4);flex-shrink:0;}
.tr-msg-sig-placeholder{width:54px;height:54px;border-radius:50%;flex-shrink:0;background:rgba(255,255,255,.1);display:flex;align-items:center;justify-content:center;border:2px solid rgba(255,184,28,.3);}
.tr-msg-sig-placeholder i{color:rgba(255,255,255,.6);font-size:1.2rem;}
.tr-msg-sig-name{color:#fff;font-weight:700;font-size:1rem;margin-bottom:2px;}
.tr-msg-sig-role{color:var(--tr-gold);font-size:.8rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;}
.tr-pa-section{background:#fff;padding:70px 0;}
.tr-pa-card{background:#fff;border-radius:20px;box-shadow:var(--tr-shadow);overflow:hidden;border:1px solid rgba(0,33,71,.06);transition:box-shadow var(--tr-trans),transform var(--tr-trans);}
.tr-pa-card:hover{box-shadow:var(--tr-shadow-h);transform:translateY(-4px);}
.tr-pa-card-accent{height:4px;background:linear-gradient(90deg,var(--tr-blue),var(--tr-gold));}
.tr-pa-card-body{padding:32px 36px;}
@media(max-width:575.98px){.tr-pa-card-body{padding:24px 20px;}}
.tr-pa-photo{width:80px;height:80px;border-radius:50%;object-fit:cover;object-position:top center;border:3px solid #fff;box-shadow:0 4px 18px rgba(0,33,71,.15);flex-shrink:0;}
.tr-pa-avatar{width:80px;height:80px;border-radius:50%;flex-shrink:0;background:linear-gradient(135deg,var(--tr-blue),#3b82f6);display:flex;align-items:center;justify-content:center;box-shadow:0 4px 16px rgba(26,79,175,.25);}
.tr-pa-avatar i{color:#fff;font-size:1.7rem;}
.tr-pa-name{font-size:1.2rem;font-weight:700;color:var(--tr-navy);margin-bottom:4px;}
.tr-pa-designation{display:inline-block;background:rgba(26,79,175,.1);color:var(--tr-blue);font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;padding:3px 12px;border-radius:50px;}
.tr-section-tag{display:inline-flex;align-items:center;gap:8px;background:rgba(0,33,71,.07);color:var(--tr-navy);font-size:.76rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:6px 16px;border-radius:50px;margin-bottom:12px;}
.tr-section-tag .dot-sm{width:6px;height:6px;border-radius:50%;background:var(--tr-gold);}
.tr-section-title{font-size:clamp(1.5rem,3.5vw,2.2rem);font-weight:800;color:var(--tr-navy);margin-bottom:12px;line-height:1.2;}
.tr-divider{width:56px;height:4px;border-radius:2px;background:linear-gradient(90deg,var(--tr-gold),var(--tr-blue));margin:14px 0 0;}
.tr-fade{opacity:0;transform:translateY(30px);transition:opacity .7s ease,transform .7s ease;}
.tr-fade.visible{opacity:1;transform:translateY(0);}
.tr-fade-delay-1{transition-delay:.1s;}
</style>
</head>
<body>
<?php include __DIR__ . '/includes/header-top.php'; ?>
<?php include __DIR__ . '/includes/nav-menu.php'; ?>

<section class="tr-hero">
    <div class="tr-circle c1"></div><div class="tr-circle c2"></div><div class="tr-circle c3"></div>
    <div class="container" style="position:relative;z-index:2;">
        <div class="breadcrumb-nav">
            <a href="/index.php">Home</a><span class="sep">/</span>
            <a href="#">About</a><span class="sep">/</span>
            <span style="color:rgba(255,255,255,.85);"><?= fh(trs($s,'hero_title','Office of the Treasurer')) ?></span>
        </div>
        <div class="tr-hero-tag"><span class="dot"></span>Prime University · Bangladesh</div>
        <h1><?php $w=explode(' ',trs($s,'hero_title','Office of the Treasurer'));$l=array_pop($w);echo fh(implode(' ',$w)).' <span class="accent">'.fh($l).'</span>'; ?></h1>
        <?php if (trs($s,'hero_subtitle','')!==''): ?><p class="tr-designation" style="background:rgba(255,184,28,.18);color:var(--tr-gold);margin-bottom:16px;"><?= fh(trs($s,'hero_subtitle','')) ?></p><?php endif; ?>
        <?php if (trs($s,'hero_intro','')!==''): ?><p class="hero-sub"><?= fh(trs($s,'hero_intro','')) ?></p><?php endif; ?>
    </div>
</section>

<div class="tr-facts-strip">
    <div class="container"><div class="row g-0 justify-content-center">
        <?php if (trs($s,'tr_phone','')!==''): ?>
        <div class="col-sm-6 col-md-4 col-lg-3"><div class="tr-fact-item"><i class="fas fa-phone tr-fact-icon"></i><div class="tr-fact-text"><strong><?= fh(trs($s,'tr_phone','')) ?></strong><span>Treasurer Direct Line</span></div></div></div>
        <?php endif; ?>
        <?php if (trs($s,'tr_email_1','')!==''): ?>
        <div class="col-sm-6 col-md-4 col-lg-4"><div class="tr-fact-item"><i class="fas fa-envelope tr-fact-icon"></i><div class="tr-fact-text"><strong><?= fh(trs($s,'tr_email_1','')) ?></strong><span>Official Email</span></div></div></div>
        <?php endif; ?>
        <?php if (trs($s,'pa_phone','')!==''): ?>
        <div class="col-sm-6 col-md-4 col-lg-3"><div class="tr-fact-item"><i class="fas fa-user tr-fact-icon"></i><div class="tr-fact-text"><strong><?= fh(trs($s,'pa_name','PA')) ?></strong><span>PA to Treasurer</span></div></div></div>
        <?php endif; ?>
    </div></div>
</div>

<section class="tr-profile-section">
    <div class="container"><div class="row justify-content-center"><div class="col-xl-10 col-lg-11">
        <div class="tr-profile-card tr-fade">
            <div class="tr-card-accent"></div>
            <div class="tr-card-body">
                <div class="row align-items-center g-4">
                    <div class="col-md-auto text-center text-md-start">
                        <div class="tr-photo-wrap">
                            <?php if ($tr_photo_url): ?><img src="<?= fh($tr_photo_url) ?>" alt="<?= fh(trs($s,'tr_name','Treasurer')) ?>">
                            <?php else: ?><div class="tr-photo-placeholder"><i class="fas fa-coins"></i></div><?php endif; ?>
                            <div class="tr-photo-badge"><i class="fas fa-star"></i></div>
                        </div>
                    </div>
                    <div class="col-md">
                        <h2 class="tr-name"><?= fh(trs($s,'tr_name','Prof. Dr. Abdur Rahman')) ?></h2>
                        <div class="tr-designation"><i class="fas fa-coins me-1"></i><?= fh(trs($s,'tr_title','Treasurer')) ?></div>
                        <ul class="tr-contact-list">
                            <?php if (trs($s,'tr_email_1','')!==''): ?>
                            <li><div class="tr-contact-icon"><i class="fas fa-envelope"></i></div>
                                <div><div style="font-size:.72rem;color:var(--tr-muted);text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:2px;">Email</div>
                                    <a href="mailto:<?= fh(trs($s,'tr_email_1','')) ?>"><?= fh(trs($s,'tr_email_1','')) ?></a>
                                    <?php if (trs($s,'tr_email_2','')!==''): ?>&nbsp;&middot;&nbsp;<a href="mailto:<?= fh(trs($s,'tr_email_2','')) ?>"><?= fh(trs($s,'tr_email_2','')) ?></a><?php endif; ?>
                                </div>
                            </li>
                            <?php endif; ?>
                            <?php if (trs($s,'tr_phone','')!==''): ?>
                            <li><div class="tr-contact-icon"><i class="fas fa-phone"></i></div>
                                <div><div style="font-size:.72rem;color:var(--tr-muted);text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:2px;">Phone</div>
                                    <a href="tel:<?= fh(trs($s,'tr_phone','')) ?>"><?= fh(trs($s,'tr_phone','')) ?></a></div>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div></div></div>
</section>

<?php if (trs($s,'tr_bio','')!==''): ?>
<section class="tr-bio-section">
    <div class="container"><div class="row justify-content-center"><div class="col-xl-10 col-lg-11">
        <div class="tr-bio-card tr-fade tr-fade-delay-1"><p><?= nl2br(fh(trs($s,'tr_bio',''))) ?></p></div>
    </div></div></div>
</section>
<?php endif; ?>

<?php if (!empty($message_paragraphs)): ?>
<section class="tr-message-section">
    <div class="container"><div class="row justify-content-center"><div class="col-xl-10 col-lg-11">
        <div class="text-center mb-5 tr-fade">
            <div class="tr-section-tag" style="display:inline-flex;"><span class="dot-sm"></span>Leadership Message</div>
            <h2 class="tr-section-title"><?= fh(trs($s,'message_title','Message from the Treasurer')) ?></h2>
            <div class="tr-divider mx-auto"></div>
        </div>
        <div class="tr-message-card tr-fade tr-fade-delay-1">
            <div class="tr-msg-header">
                <div class="tr-msg-icon"><i class="fas fa-quote-left"></i></div>
                <h3 class="tr-msg-title"><?= fh(trs($s,'message_title','Message from the Treasurer')) ?></h3>
                <p class="tr-msg-subtitle"><?= fh(trs($s,'tr_name','Treasurer')) ?> &middot; Prime University</p>
                <div class="tr-msg-divider"></div>
            </div>
            <div class="tr-msg-body">
                <?php foreach ($message_paragraphs as $para): ?><p><?= fh($para) ?></p><?php endforeach; ?>
            </div>
            <div class="tr-msg-sig">
                <?php if ($tr_photo_url): ?><img src="<?= fh($tr_photo_url) ?>" class="tr-msg-sig-photo" alt="<?= fh(trs($s,'tr_name','')) ?>">
                <?php else: ?><div class="tr-msg-sig-placeholder"><i class="fas fa-coins"></i></div><?php endif; ?>
                <div><div class="tr-msg-sig-name"><?= fh(trs($s,'tr_name','Prof. Dr. Abdur Rahman')) ?></div>
                    <div class="tr-msg-sig-role"><?= fh(trs($s,'tr_title','Treasurer')) ?> &mdash; Prime University</div></div>
            </div>
        </div>
    </div></div></div>
</section>
<?php endif; ?>

<?php if (trs($s,'pa_name','')!==''): ?>
<section class="tr-pa-section">
    <div class="container"><div class="row justify-content-center"><div class="col-xl-10 col-lg-11">
        <div class="mb-5 tr-fade">
            <div class="tr-section-tag"><span class="dot-sm"></span>Office Staff</div>
            <h2 class="tr-section-title">Personal Assistant</h2>
            <div class="tr-divider"></div>
        </div>
        <div class="row justify-content-center"><div class="col-md-8 col-lg-7">
            <div class="tr-pa-card tr-fade tr-fade-delay-1">
                <div class="tr-pa-card-accent"></div>
                <div class="tr-pa-card-body">
                    <div class="d-flex align-items-center gap-4 mb-4">
                        <?php if ($pa_photo_url): ?><img src="<?= fh($pa_photo_url) ?>" alt="<?= fh(trs($s,'pa_name','')) ?>" class="tr-pa-photo">
                        <?php else: ?><div class="tr-pa-avatar"><i class="fas fa-user"></i></div><?php endif; ?>
                        <div><div class="tr-pa-name"><?= fh(trs($s,'pa_name','')) ?></div>
                            <div class="tr-pa-designation"><?= fh(trs($s,'pa_title','PA to Treasurer')) ?></div></div>
                    </div>
                    <ul class="tr-contact-list">
                        <?php if (trs($s,'pa_email_1','')!==''): ?>
                        <li><div class="tr-contact-icon"><i class="fas fa-envelope"></i></div>
                            <div><div style="font-size:.72rem;color:var(--tr-muted);text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:2px;">Email</div>
                                <a href="mailto:<?= fh(trs($s,'pa_email_1','')) ?>"><?= fh(trs($s,'pa_email_1','')) ?></a></div></li>
                        <?php endif; ?>
                        <?php if (trs($s,'pa_phone','')!==''): ?>
                        <li><div class="tr-contact-icon"><i class="fas fa-phone"></i></div>
                            <div><div style="font-size:.72rem;color:var(--tr-muted);text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:2px;">Phone</div>
                                <?= fh(trs($s,'pa_phone','')) ?></div></li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div></div>
    </div></div></div>
</section>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
<?php include __DIR__ . '/includes/scripts.php'; ?>
<script>
(function(){const els=document.querySelectorAll('.tr-fade');if(!els.length)return;
const io=new IntersectionObserver(function(entries){entries.forEach(function(e){if(e.isIntersecting){e.target.classList.add('visible');io.unobserve(e.target);}});},{threshold:0.12});
els.forEach(function(el){io.observe(el);});})();
</script>
</body>
</html>
