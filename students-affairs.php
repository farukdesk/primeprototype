<?php
require_once __DIR__ . '/includes/config.php';

$s = [];
$staff = [];
try {
    $db = front_db();
    if ($db) {
        $rows = $db->query('SELECT setting_key, setting_val FROM sa_settings')->fetchAll();
        foreach ($rows as $r) $s[$r['setting_key']] = $r['setting_val'];
        $staff = $db->query('SELECT * FROM sa_staff WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
    }
} catch (Throwable $e) {}

function sas(array $s, string $key, string $default = ''): string {
    return isset($s[$key]) && $s[$key] !== '' ? $s[$key] : $default;
}

if (sas($s, 'is_published', '1') !== '1') { header('Location: /index.php'); exit; }

$page_title = sas($s, 'hero_title', "Students' Affairs") . ' – Prime University';
$meta_desc  = sas($s, 'meta_description', "Students' Affairs – Prime University");

$message_paragraphs = [];
$raw_message = sas($s, 'message_body', '');
if ($raw_message !== '') {
    $message_paragraphs = array_filter(
        array_map('trim', preg_split('/\n{2,}/', $raw_message)),
        fn($p) => $p !== ''
    );
}

$head_photo_url = !empty($s['head_photo']) ? ADMIN_UPLOAD_URL . '/students-affairs/' . $s['head_photo'] : '';
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
  --sa-navy:#002147;--sa-gold:#FFB81C;--sa-blue:#1a4faf;
  --sa-green:#16a34a;--sa-light:#f4f7fb;--sa-text:#334155;--sa-muted:#64748b;
  --sa-shadow:0 8px 40px rgba(0,33,71,.10);--sa-shadow-h:0 18px 60px rgba(0,33,71,.18);
  --sa-trans:.35s cubic-bezier(.4,0,.2,1);
}
.sa-hero{background:linear-gradient(135deg,#001530 0%,#002f68 55%,#1a4faf 100%);padding:110px 0 90px;position:relative;overflow:hidden;}
.sa-hero::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 60% 80% at 80% 50%,rgba(255,184,28,.10) 0%,transparent 70%),radial-gradient(ellipse 40% 60% at 10% 80%,rgba(26,79,175,.35) 0%,transparent 60%);pointer-events:none;}
.sa-circle{position:absolute;border-radius:50%;pointer-events:none;animation:saFloat 9s ease-in-out infinite;}
.sa-circle.c1{width:380px;height:380px;background:rgba(255,184,28,.07);top:-90px;right:-70px;}
.sa-circle.c2{width:200px;height:200px;background:rgba(255,255,255,.05);bottom:20px;left:4%;animation-delay:3s;}
.sa-circle.c3{width:120px;height:120px;background:rgba(255,184,28,.10);top:35%;right:22%;animation-delay:1.5s;}
@keyframes saFloat{0%,100%{transform:translateY(0) scale(1);}50%{transform:translateY(-22px) scale(1.05);}}
.sa-hero .breadcrumb-nav{display:flex;align-items:center;gap:8px;font-size:.82rem;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:rgba(255,255,255,.65);margin-bottom:24px;}
.sa-hero .breadcrumb-nav a{color:var(--sa-gold);text-decoration:none;}
.sa-hero .breadcrumb-nav .sep{color:rgba(255,255,255,.35);}
.sa-hero-tag{display:inline-flex;align-items:center;gap:8px;background:rgba(255,184,28,.18);color:var(--sa-gold);font-size:.77rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;padding:7px 18px;border-radius:50px;border:1px solid rgba(255,184,28,.35);margin-bottom:20px;}
.sa-hero-tag .dot{width:7px;height:7px;border-radius:50%;background:var(--sa-gold);animation:saPulse 1.8s ease-in-out infinite;}
@keyframes saPulse{0%,100%{opacity:1;transform:scale(1);}50%{opacity:.5;transform:scale(1.6);}}
.sa-hero h1{font-size:clamp(2rem,5vw,3.4rem);font-weight:800;color:#fff;line-height:1.15;margin-bottom:18px;}
.sa-hero h1 .accent{color:var(--sa-gold);}
.sa-hero .hero-sub{font-size:clamp(.93rem,1.8vw,1.1rem);color:rgba(255,255,255,.78);max-width:580px;line-height:1.75;}
.sa-facts-strip{background:var(--sa-navy);padding:24px 0;}
.sa-fact-item{display:flex;align-items:center;gap:12px;color:rgba(255,255,255,.85);padding:10px 20px;border-right:1px solid rgba(255,255,255,.12);}
.sa-fact-item:last-child{border-right:none;}
.sa-fact-icon{color:var(--sa-gold);font-size:1.2rem;flex-shrink:0;}
.sa-fact-text strong{display:block;font-size:.92rem;font-weight:700;color:#fff;}
.sa-fact-text span{font-size:.77rem;color:rgba(255,255,255,.55);letter-spacing:.04em;text-transform:uppercase;}
@media(max-width:767.98px){.sa-fact-item{border-right:none;border-bottom:1px solid rgba(255,255,255,.10);}.sa-fact-item:last-child{border-bottom:none;}}
.sa-profile-section{padding:80px 0 60px;background:#fff;}
.sa-profile-card{background:#fff;border-radius:24px;box-shadow:var(--sa-shadow);overflow:hidden;transition:box-shadow var(--sa-trans);border:1px solid rgba(0,33,71,.06);}
.sa-profile-card:hover{box-shadow:var(--sa-shadow-h);}
.sa-card-accent{height:6px;background:linear-gradient(90deg,var(--sa-navy) 0%,var(--sa-blue) 50%,var(--sa-gold) 100%);}
.sa-card-body{padding:40px;}
@media(max-width:767.98px){.sa-card-body{padding:28px 20px;}}
.sa-photo-wrap{position:relative;display:inline-block;}
.sa-photo-wrap img{width:160px;height:160px;border-radius:50%;object-fit:cover;object-position:top center;border:5px solid #fff;box-shadow:0 8px 32px rgba(0,33,71,.18);display:block;}
.sa-photo-badge{position:absolute;bottom:6px;right:6px;width:32px;height:32px;border-radius:50%;background:var(--sa-gold);border:3px solid #fff;display:flex;align-items:center;justify-content:center;}
.sa-photo-badge i{color:var(--sa-navy);font-size:.7rem;}
.sa-photo-placeholder{width:160px;height:160px;border-radius:50%;background:linear-gradient(135deg,var(--sa-navy),var(--sa-blue));display:flex;align-items:center;justify-content:center;box-shadow:0 8px 32px rgba(0,33,71,.18);border:5px solid #fff;}
.sa-photo-placeholder i{color:rgba(255,255,255,.75);font-size:3.5rem;}
.sa-name{font-size:clamp(1.3rem,3vw,1.75rem);font-weight:800;color:var(--sa-navy);margin-bottom:4px;}
.sa-designation{display:inline-flex;align-items:center;gap:8px;background:var(--sa-navy);color:var(--sa-gold);font-size:.8rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:5px 16px;border-radius:50px;margin-bottom:24px;}
.sa-contact-list{list-style:none;padding:0;margin:0;}
.sa-contact-list li{display:flex;align-items:flex-start;gap:12px;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:.9rem;color:var(--sa-text);}
.sa-contact-list li:last-child{border-bottom:none;}
.sa-contact-icon{width:34px;height:34px;border-radius:8px;flex-shrink:0;background:var(--sa-light);display:flex;align-items:center;justify-content:center;}
.sa-contact-icon i{color:var(--sa-blue);font-size:.85rem;}
.sa-contact-list a{color:var(--sa-blue);text-decoration:none;transition:color var(--sa-trans);}
.sa-contact-list a:hover{color:var(--sa-navy);text-decoration:underline;}
.sa-bio-section{background:var(--sa-light);padding:70px 0;}
.sa-bio-card{background:#fff;border-radius:20px;padding:44px 48px;box-shadow:var(--sa-shadow);border-left:5px solid var(--sa-gold);position:relative;}
@media(max-width:767.98px){.sa-bio-card{padding:28px 22px;}}
.sa-bio-card p{font-size:1.02rem;color:var(--sa-text);line-height:1.85;margin-bottom:0;}
.sa-message-section{padding:80px 0;background:#fff;position:relative;overflow:hidden;}
.sa-message-section::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,var(--sa-navy),var(--sa-blue),var(--sa-gold));}
.sa-message-card{background:linear-gradient(135deg,#001e45 0%,#002f68 100%);border-radius:24px;padding:56px 56px 48px;position:relative;overflow:hidden;box-shadow:0 16px 56px rgba(0,33,71,.22);}
@media(max-width:767.98px){.sa-message-card{padding:36px 24px 32px;}}
.sa-message-card::before{content:'';position:absolute;top:-60px;right:-60px;width:280px;height:280px;border-radius:50%;background:rgba(255,184,28,.08);pointer-events:none;}
.sa-message-card::after{content:'';position:absolute;bottom:-80px;left:-40px;width:220px;height:220px;border-radius:50%;background:rgba(26,79,175,.3);pointer-events:none;}
.sa-msg-header{position:relative;z-index:1;margin-bottom:32px;}
.sa-msg-icon{width:56px;height:56px;border-radius:14px;background:rgba(255,184,28,.2);border:1px solid rgba(255,184,28,.4);display:flex;align-items:center;justify-content:center;margin-bottom:16px;}
.sa-msg-icon i{color:var(--sa-gold);font-size:1.4rem;}
.sa-msg-title{font-size:clamp(1.35rem,3vw,1.9rem);font-weight:800;color:#fff;margin-bottom:4px;}
.sa-msg-subtitle{color:rgba(255,255,255,.6);font-size:.9rem;}
.sa-msg-divider{height:2px;width:60px;background:linear-gradient(90deg,var(--sa-gold),transparent);margin:20px 0 28px;}
.sa-msg-body{position:relative;z-index:1;}
.sa-msg-body p{color:rgba(255,255,255,.88);font-size:1rem;line-height:1.9;margin-bottom:1.4rem;}
.sa-msg-body p:last-child{margin-bottom:0;}
.sa-msg-sig{position:relative;z-index:1;margin-top:36px;padding-top:24px;border-top:1px solid rgba(255,255,255,.12);display:flex;align-items:center;gap:16px;flex-wrap:wrap;}
.sa-msg-sig-photo{width:54px;height:54px;border-radius:50%;object-fit:cover;object-position:top center;border:2px solid rgba(255,184,28,.4);flex-shrink:0;}
.sa-msg-sig-placeholder{width:54px;height:54px;border-radius:50%;flex-shrink:0;background:rgba(255,255,255,.1);display:flex;align-items:center;justify-content:center;border:2px solid rgba(255,184,28,.3);}
.sa-msg-sig-placeholder i{color:rgba(255,255,255,.6);font-size:1.2rem;}
.sa-msg-sig-name{color:#fff;font-weight:700;font-size:1rem;margin-bottom:2px;}
.sa-msg-sig-role{color:var(--sa-gold);font-size:.8rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;}
/* Staff Grid */
.sa-staff-section{background:var(--sa-light);padding:80px 0;}
.sa-staff-card{background:#fff;border-radius:18px;box-shadow:var(--sa-shadow);overflow:hidden;height:100%;transition:box-shadow var(--sa-trans),transform var(--sa-trans);border:1px solid rgba(0,33,71,.06);}
.sa-staff-card:hover{box-shadow:var(--sa-shadow-h);transform:translateY(-5px);}
.sa-staff-card-accent{height:4px;background:linear-gradient(90deg,var(--sa-blue),var(--sa-gold));}
.sa-staff-card-body{padding:24px;}
.sa-staff-photo{width:72px;height:72px;border-radius:50%;object-fit:cover;object-position:top center;border:3px solid #fff;box-shadow:0 4px 16px rgba(0,33,71,.12);}
.sa-staff-avatar{width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,var(--sa-blue),#3b82f6);display:flex;align-items:center;justify-content:center;box-shadow:0 4px 14px rgba(26,79,175,.2);}
.sa-staff-avatar i{color:#fff;font-size:1.5rem;}
.sa-staff-name{font-size:1rem;font-weight:700;color:var(--sa-navy);margin-bottom:4px;}
.sa-staff-title{display:inline-block;background:rgba(26,79,175,.1);color:var(--sa-blue);font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;padding:2px 10px;border-radius:50px;margin-bottom:12px;}
.sa-staff-meta{font-size:.82rem;color:var(--sa-muted);}
.sa-staff-meta a{color:var(--sa-blue);text-decoration:none;}
.sa-staff-meta a:hover{text-decoration:underline;}
.sa-section-tag{display:inline-flex;align-items:center;gap:8px;background:rgba(0,33,71,.07);color:var(--sa-navy);font-size:.76rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:6px 16px;border-radius:50px;margin-bottom:12px;}
.sa-section-tag .dot-sm{width:6px;height:6px;border-radius:50%;background:var(--sa-gold);}
.sa-section-title{font-size:clamp(1.5rem,3.5vw,2.2rem);font-weight:800;color:var(--sa-navy);margin-bottom:12px;line-height:1.2;}
.sa-divider{width:56px;height:4px;border-radius:2px;background:linear-gradient(90deg,var(--sa-gold),var(--sa-blue));margin:14px 0 0;}
.sa-fade{opacity:0;transform:translateY(30px);transition:opacity .7s ease,transform .7s ease;}
.sa-fade.visible{opacity:1;transform:translateY(0);}
.sa-fade-delay-1{transition-delay:.1s;}.sa-fade-delay-2{transition-delay:.2s;}.sa-fade-delay-3{transition-delay:.3s;}
</style>
</head>
<body>
<?php include __DIR__ . '/includes/header-top.php'; ?>
<?php include __DIR__ . '/includes/nav-menu.php'; ?>

<section class="sa-hero">
    <div class="sa-circle c1"></div><div class="sa-circle c2"></div><div class="sa-circle c3"></div>
    <div class="container" style="position:relative;z-index:2;">
        <div class="breadcrumb-nav">
            <a href="/index.php">Home</a><span class="sep">/</span>
            <a href="#">About</a><span class="sep">/</span>
            <span style="color:rgba(255,255,255,.85);"><?= fh(sas($s,'hero_title',"Students' Affairs")) ?></span>
        </div>
        <div class="sa-hero-tag"><span class="dot"></span>Prime University · Bangladesh</div>
        <h1><?php $w=explode(' ',sas($s,'hero_title',"Students' Affairs"));$l=array_pop($w);echo fh(implode(' ',$w)).' <span class="accent">'.fh($l).'</span>'; ?></h1>
        <?php if (sas($s,'hero_subtitle','')!==''): ?><p class="sa-designation" style="background:rgba(255,184,28,.18);color:var(--sa-gold);margin-bottom:16px;"><?= fh(sas($s,'hero_subtitle','')) ?></p><?php endif; ?>
        <?php if (sas($s,'hero_intro','')!==''): ?><p class="hero-sub"><?= fh(sas($s,'hero_intro','')) ?></p><?php endif; ?>
    </div>
</section>

<div class="sa-facts-strip">
    <div class="container"><div class="row g-0 justify-content-center">
        <?php if (sas($s,'head_phone','')!==''): ?>
        <div class="col-sm-6 col-md-4 col-lg-3"><div class="sa-fact-item"><i class="fas fa-phone sa-fact-icon"></i><div class="sa-fact-text"><strong><?= fh(sas($s,'head_phone','')) ?></strong><span>Direct Line</span></div></div></div>
        <?php endif; ?>
        <?php if (sas($s,'head_email_1','')!==''): ?>
        <div class="col-sm-6 col-md-4 col-lg-4"><div class="sa-fact-item"><i class="fas fa-envelope sa-fact-icon"></i><div class="sa-fact-text"><strong><?= fh(sas($s,'head_email_1','')) ?></strong><span>Official Email</span></div></div></div>
        <?php endif; ?>
        <?php if (count($staff)>0): ?>
        <div class="col-sm-6 col-md-4 col-lg-3"><div class="sa-fact-item"><i class="fas fa-users sa-fact-icon"></i><div class="sa-fact-text"><strong><?= count($staff) ?> Staff Member<?= count($staff)!==1?'s':'' ?></strong><span>Office Team</span></div></div></div>
        <?php endif; ?>
    </div></div>
</div>

<section class="sa-profile-section">
    <div class="container"><div class="row justify-content-center"><div class="col-xl-10 col-lg-11">
        <div class="sa-profile-card sa-fade">
            <div class="sa-card-accent"></div>
            <div class="sa-card-body">
                <div class="row align-items-center g-4">
                    <div class="col-md-auto text-center text-md-start">
                        <div class="sa-photo-wrap">
                            <?php if ($head_photo_url): ?><img src="<?= fh($head_photo_url) ?>" alt="<?= fh(sas($s,'head_name',"Head of Students' Affairs")) ?>">
                            <?php else: ?><div class="sa-photo-placeholder"><i class="fas fa-user-graduate"></i></div><?php endif; ?>
                            <div class="sa-photo-badge"><i class="fas fa-star"></i></div>
                        </div>
                    </div>
                    <div class="col-md">
                        <h2 class="sa-name"><?= fh(sas($s,'head_name','—')) ?></h2>
                        <div class="sa-designation"><i class="fas fa-user-graduate me-1"></i><?= fh(sas($s,'head_title','')) ?></div>
                        <ul class="sa-contact-list">
                            <?php if (sas($s,'head_email_1','')!==''): ?><li><span class="sa-contact-icon"><i class="fas fa-envelope"></i></span><span><a href="mailto:<?= fh(sas($s,'head_email_1','')) ?>"><?= fh(sas($s,'head_email_1','')) ?></a></span></li><?php endif; ?>
                            <?php if (sas($s,'head_email_2','')!==''): ?><li><span class="sa-contact-icon"><i class="fas fa-envelope"></i></span><span><a href="mailto:<?= fh(sas($s,'head_email_2','')) ?>"><?= fh(sas($s,'head_email_2','')) ?></a></span></li><?php endif; ?>
                            <?php if (sas($s,'head_phone','')!==''): ?><li><span class="sa-contact-icon"><i class="fas fa-phone"></i></span><span><a href="tel:<?= fh(sas($s,'head_phone','')) ?>"><?= fh(sas($s,'head_phone','')) ?></a></span></li><?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div></div></div>
</section>

<?php if (sas($s,'head_bio','')!==''): ?>
<section class="sa-bio-section">
    <div class="container"><div class="row justify-content-center"><div class="col-xl-10 col-lg-11">
        <div class="sa-bio-card sa-fade">
            <p><?= nl2br(fh(sas($s,'head_bio',''))) ?></p>
        </div>
    </div></div></div>
</section>
<?php endif; ?>

<?php if (!empty($message_paragraphs)): ?>
<section class="sa-message-section">
    <div class="container"><div class="row justify-content-center"><div class="col-xl-10 col-lg-11">
        <div class="sa-message-card sa-fade">
            <div class="sa-msg-header">
                <div class="sa-msg-icon"><i class="fas fa-quote-left"></i></div>
                <h3 class="sa-msg-title"><?= fh(sas($s,'message_title',"Message from Students' Affairs")) ?></h3>
                <div class="sa-msg-divider"></div>
            </div>
            <div class="sa-msg-body">
                <?php foreach ($message_paragraphs as $p): ?><p><?= fh($p) ?></p><?php endforeach; ?>
            </div>
            <div class="sa-msg-sig">
                <?php if ($head_photo_url): ?>
                <img src="<?= fh($head_photo_url) ?>" class="sa-msg-sig-photo" alt="<?= fh(sas($s,'head_name','')) ?>">
                <?php else: ?><div class="sa-msg-sig-placeholder"><i class="fas fa-user-graduate"></i></div><?php endif; ?>
                <div>
                    <div class="sa-msg-sig-name"><?= fh(sas($s,'head_name','—')) ?></div>
                    <div class="sa-msg-sig-role"><?= fh(sas($s,'head_title','')) ?></div>
                </div>
            </div>
        </div>
    </div></div></div>
</section>
<?php endif; ?>

<?php if (!empty($staff)): ?>
<section class="sa-staff-section">
    <div class="container">
        <div class="text-center mb-5 sa-fade">
            <div class="sa-section-tag"><span class="dot-sm"></span>Our Team</div>
            <h2 class="sa-section-title">Office Staff Directory</h2>
            <div class="sa-divider mx-auto"></div>
        </div>
        <div class="row g-4">
            <?php foreach ($staff as $i => $st): ?>
            <div class="col-xl-3 col-lg-4 col-md-6 sa-fade sa-fade-delay-<?= ($i % 3) + 1 ?>">
                <div class="sa-staff-card">
                    <div class="sa-staff-card-accent"></div>
                    <div class="sa-staff-card-body">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <?php if (!empty($st['photo'])): ?>
                            <img src="<?= fh(ADMIN_UPLOAD_URL . '/students-affairs/' . $st['photo']) ?>" class="sa-staff-photo" alt="<?= fh($st['name']) ?>">
                            <?php else: ?>
                            <div class="sa-staff-avatar"><i class="fas fa-user"></i></div>
                            <?php endif; ?>
                            <div class="flex-grow-1 min-w-0">
                                <div class="sa-staff-name"><?= fh($st['name']) ?></div>
                                <?php if ($st['title']): ?><span class="sa-staff-title"><?= fh($st['title']) ?></span><?php endif; ?>
                            </div>
                        </div>
                        <?php if ($st['email_1'] || $st['phone']): ?>
                        <div class="sa-staff-meta">
                            <?php if ($st['email_1']): ?><div class="mb-1"><i class="fas fa-envelope me-1" style="color:#94a3b8;"></i><a href="mailto:<?= fh($st['email_1']) ?>"><?= fh($st['email_1']) ?></a></div><?php endif; ?>
                            <?php if ($st['email_2']): ?><div class="mb-1"><i class="fas fa-envelope me-1" style="color:#94a3b8;"></i><a href="mailto:<?= fh($st['email_2']) ?>"><?= fh($st['email_2']) ?></a></div><?php endif; ?>
                            <?php if ($st['phone']): ?><div><i class="fas fa-phone me-1" style="color:#94a3b8;"></i><a href="tel:<?= fh($st['phone']) ?>"><?= fh($st['phone']) ?></a></div><?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script src="/assets/js/jquery.js"></script>
<script src="/assets/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const observer = new IntersectionObserver(entries => {
        entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); observer.unobserve(e.target); } });
    }, { threshold: 0.12 });
    document.querySelectorAll('.sa-fade').forEach(el => observer.observe(el));
});
</script>
</body>
</html>
