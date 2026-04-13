<?php
require_once __DIR__ . '/includes/config.php';

$s = [];
$staff = [];
try {
    $db = front_db();
    if ($db) {
        $rows = $db->query('SELECT setting_key, setting_val FROM aa_settings')->fetchAll();
        foreach ($rows as $r) $s[$r['setting_key']] = $r['setting_val'];
        $staff = $db->query('SELECT * FROM aa_staff WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
    }
} catch (Throwable $e) {}

function aas(array $s, string $key, string $default = ''): string {
    return isset($s[$key]) && $s[$key] !== '' ? $s[$key] : $default;
}

if (aas($s, 'is_published', '1') !== '1') { header('Location: /index.php'); exit; }

$page_title = aas($s, 'hero_title', 'Office of Accounts & Audit') . ' – Prime University';
$meta_desc  = aas($s, 'meta_description', 'Office of Accounts & Audit – Prime University');

$message_paragraphs = [];
$raw_message = aas($s, 'message_body', '');
if ($raw_message !== '') {
    $message_paragraphs = array_filter(
        array_map('trim', preg_split('/\n{2,}/', $raw_message)),
        fn($p) => $p !== ''
    );
}

$head_photo_url = !empty($s['head_photo']) ? ADMIN_UPLOAD_URL . '/office-of-accounts-audit/' . $s['head_photo'] : '';
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
  --aa-navy:#002147;--aa-gold:#FFB81C;--aa-blue:#1a4faf;
  --aa-green:#16a34a;--aa-light:#f4f7fb;--aa-text:#334155;--aa-muted:#64748b;
  --aa-shadow:0 8px 40px rgba(0,33,71,.10);--aa-shadow-h:0 18px 60px rgba(0,33,71,.18);
  --aa-trans:.35s cubic-bezier(.4,0,.2,1);
}
.aa-hero{background:linear-gradient(135deg,#001530 0%,#002f68 55%,#1a4faf 100%);padding:110px 0 90px;position:relative;overflow:hidden;}
.aa-hero::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 60% 80% at 80% 50%,rgba(255,184,28,.10) 0%,transparent 70%),radial-gradient(ellipse 40% 60% at 10% 80%,rgba(26,79,175,.35) 0%,transparent 60%);pointer-events:none;}
.aa-circle{position:absolute;border-radius:50%;pointer-events:none;animation:aaFloat 9s ease-in-out infinite;}
.aa-circle.c1{width:380px;height:380px;background:rgba(255,184,28,.07);top:-90px;right:-70px;}
.aa-circle.c2{width:200px;height:200px;background:rgba(255,255,255,.05);bottom:20px;left:4%;animation-delay:3s;}
.aa-circle.c3{width:120px;height:120px;background:rgba(255,184,28,.10);top:35%;right:22%;animation-delay:1.5s;}
@keyframes aaFloat{0%,100%{transform:translateY(0) scale(1);}50%{transform:translateY(-22px) scale(1.05);}}
.aa-hero .breadcrumb-nav{display:flex;align-items:center;gap:8px;font-size:.82rem;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:rgba(255,255,255,.65);margin-bottom:24px;}
.aa-hero .breadcrumb-nav a{color:var(--aa-gold);text-decoration:none;}
.aa-hero .breadcrumb-nav .sep{color:rgba(255,255,255,.35);}
.aa-hero-tag{display:inline-flex;align-items:center;gap:8px;background:rgba(255,184,28,.18);color:var(--aa-gold);font-size:.77rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;padding:7px 18px;border-radius:50px;border:1px solid rgba(255,184,28,.35);margin-bottom:20px;}
.aa-hero-tag .dot{width:7px;height:7px;border-radius:50%;background:var(--aa-gold);animation:aaPulse 1.8s ease-in-out infinite;}
@keyframes aaPulse{0%,100%{opacity:1;transform:scale(1);}50%{opacity:.5;transform:scale(1.6);}}
.aa-hero h1{font-size:clamp(2rem,5vw,3.4rem);font-weight:800;color:#fff;line-height:1.15;margin-bottom:18px;}
.aa-hero h1 .accent{color:var(--aa-gold);}
.aa-hero .hero-sub{font-size:clamp(.93rem,1.8vw,1.1rem);color:rgba(255,255,255,.78);max-width:580px;line-height:1.75;}
.aa-facts-strip{background:var(--aa-navy);padding:24px 0;}
.aa-fact-item{display:flex;align-items:center;gap:12px;color:rgba(255,255,255,.85);padding:10px 20px;border-right:1px solid rgba(255,255,255,.12);}
.aa-fact-item:last-child{border-right:none;}
.aa-fact-icon{color:var(--aa-gold);font-size:1.2rem;flex-shrink:0;}
.aa-fact-text strong{display:block;font-size:.92rem;font-weight:700;color:#fff;}
.aa-fact-text span{font-size:.77rem;color:rgba(255,255,255,.55);letter-spacing:.04em;text-transform:uppercase;}
@media(max-width:767.98px){.aa-fact-item{border-right:none;border-bottom:1px solid rgba(255,255,255,.10);}.aa-fact-item:last-child{border-bottom:none;}}
.aa-profile-section{padding:80px 0 60px;background:#fff;}
.aa-profile-card{background:#fff;border-radius:24px;box-shadow:var(--aa-shadow);overflow:hidden;transition:box-shadow var(--aa-trans);border:1px solid rgba(0,33,71,.06);}
.aa-profile-card:hover{box-shadow:var(--aa-shadow-h);}
.aa-card-accent{height:6px;background:linear-gradient(90deg,var(--aa-navy) 0%,var(--aa-blue) 50%,var(--aa-gold) 100%);}
.aa-card-body{padding:40px;}
@media(max-width:767.98px){.aa-card-body{padding:28px 20px;}}
.aa-photo-wrap{position:relative;display:inline-block;}
.aa-photo-wrap img{width:160px;height:160px;border-radius:50%;object-fit:cover;object-position:top center;border:5px solid #fff;box-shadow:0 8px 32px rgba(0,33,71,.18);display:block;}
.aa-photo-badge{position:absolute;bottom:6px;right:6px;width:32px;height:32px;border-radius:50%;background:var(--aa-gold);border:3px solid #fff;display:flex;align-items:center;justify-content:center;}
.aa-photo-badge i{color:var(--aa-navy);font-size:.7rem;}
.aa-photo-placeholder{width:160px;height:160px;border-radius:50%;background:linear-gradient(135deg,var(--aa-navy),var(--aa-blue));display:flex;align-items:center;justify-content:center;box-shadow:0 8px 32px rgba(0,33,71,.18);border:5px solid #fff;}
.aa-photo-placeholder i{color:rgba(255,255,255,.75);font-size:3.5rem;}
.aa-name{font-size:clamp(1.3rem,3vw,1.75rem);font-weight:800;color:var(--aa-navy);margin-bottom:4px;}
.aa-designation{display:inline-flex;align-items:center;gap:8px;background:var(--aa-navy);color:var(--aa-gold);font-size:.8rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:5px 16px;border-radius:50px;margin-bottom:24px;}
.aa-contact-list{list-style:none;padding:0;margin:0;}
.aa-contact-list li{display:flex;align-items:flex-start;gap:12px;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:.9rem;color:var(--aa-text);}
.aa-contact-list li:last-child{border-bottom:none;}
.aa-contact-icon{width:34px;height:34px;border-radius:8px;flex-shrink:0;background:var(--aa-light);display:flex;align-items:center;justify-content:center;}
.aa-contact-icon i{color:var(--aa-blue);font-size:.85rem;}
.aa-contact-list a{color:var(--aa-blue);text-decoration:none;transition:color var(--aa-trans);}
.aa-contact-list a:hover{color:var(--aa-navy);text-decoration:underline;}
.aa-bio-section{background:var(--aa-light);padding:70px 0;}
.aa-bio-card{background:#fff;border-radius:20px;padding:44px 48px;box-shadow:var(--aa-shadow);border-left:5px solid var(--aa-gold);position:relative;}
@media(max-width:767.98px){.aa-bio-card{padding:28px 22px;}}
.aa-bio-card p{font-size:1.02rem;color:var(--aa-text);line-height:1.85;margin-bottom:0;}
.aa-message-section{padding:80px 0;background:#fff;position:relative;overflow:hidden;}
.aa-message-section::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,var(--aa-navy),var(--aa-blue),var(--aa-gold));}
.aa-message-card{background:linear-gradient(135deg,#001e45 0%,#002f68 100%);border-radius:24px;padding:56px 56px 48px;position:relative;overflow:hidden;box-shadow:0 16px 56px rgba(0,33,71,.22);}
@media(max-width:767.98px){.aa-message-card{padding:36px 24px 32px;}}
.aa-message-card::before{content:'';position:absolute;top:-60px;right:-60px;width:280px;height:280px;border-radius:50%;background:rgba(255,184,28,.08);pointer-events:none;}
.aa-message-card::after{content:'';position:absolute;bottom:-80px;left:-40px;width:220px;height:220px;border-radius:50%;background:rgba(26,79,175,.3);pointer-events:none;}
.aa-msg-header{position:relative;z-index:1;margin-bottom:32px;}
.aa-msg-icon{width:56px;height:56px;border-radius:14px;background:rgba(255,184,28,.2);border:1px solid rgba(255,184,28,.4);display:flex;align-items:center;justify-content:center;margin-bottom:16px;}
.aa-msg-icon i{color:var(--aa-gold);font-size:1.4rem;}
.aa-msg-title{font-size:clamp(1.35rem,3vw,1.9rem);font-weight:800;color:#fff;margin-bottom:4px;}
.aa-msg-subtitle{color:rgba(255,255,255,.6);font-size:.9rem;}
.aa-msg-divider{height:2px;width:60px;background:linear-gradient(90deg,var(--aa-gold),transparent);margin:20px 0 28px;}
.aa-msg-body{position:relative;z-index:1;}
.aa-msg-body p{color:rgba(255,255,255,.88);font-size:1rem;line-height:1.9;margin-bottom:1.4rem;}
.aa-msg-body p:last-child{margin-bottom:0;}
.aa-msg-sig{position:relative;z-index:1;margin-top:36px;padding-top:24px;border-top:1px solid rgba(255,255,255,.12);display:flex;align-items:center;gap:16px;flex-wrap:wrap;}
.aa-msg-sig-photo{width:54px;height:54px;border-radius:50%;object-fit:cover;object-position:top center;border:2px solid rgba(255,184,28,.4);flex-shrink:0;}
.aa-msg-sig-placeholder{width:54px;height:54px;border-radius:50%;flex-shrink:0;background:rgba(255,255,255,.1);display:flex;align-items:center;justify-content:center;border:2px solid rgba(255,184,28,.3);}
.aa-msg-sig-placeholder i{color:rgba(255,255,255,.6);font-size:1.2rem;}
.aa-msg-sig-name{color:#fff;font-weight:700;font-size:1rem;margin-bottom:2px;}
.aa-msg-sig-role{color:var(--aa-gold);font-size:.8rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;}
/* Staff Grid */
.aa-staff-section{background:var(--aa-light);padding:80px 0;}
.aa-staff-card{background:#fff;border-radius:18px;box-shadow:var(--aa-shadow);overflow:hidden;height:100%;transition:box-shadow var(--aa-trans),transform var(--aa-trans);border:1px solid rgba(0,33,71,.06);}
.aa-staff-card:hover{box-shadow:var(--aa-shadow-h);transform:translateY(-5px);}
.aa-staff-card-accent{height:4px;background:linear-gradient(90deg,var(--aa-blue),var(--aa-gold));}
.aa-staff-card-body{padding:24px;}
.aa-staff-photo{width:72px;height:72px;border-radius:50%;object-fit:cover;object-position:top center;border:3px solid #fff;box-shadow:0 4px 16px rgba(0,33,71,.12);}
.aa-staff-avatar{width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,var(--aa-blue),#3b82f6);display:flex;align-items:center;justify-content:center;box-shadow:0 4px 14px rgba(26,79,175,.2);}
.aa-staff-avatar i{color:#fff;font-size:1.5rem;}
.aa-staff-name{font-size:1rem;font-weight:700;color:var(--aa-navy);margin-bottom:4px;}
.aa-staff-title{display:inline-block;background:rgba(26,79,175,.1);color:var(--aa-blue);font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;padding:2px 10px;border-radius:50px;margin-bottom:12px;}
.aa-staff-meta{font-size:.82rem;color:var(--aa-muted);}
.aa-staff-meta a{color:var(--aa-blue);text-decoration:none;}
.aa-staff-meta a:hover{text-decoration:underline;}
.aa-section-tag{display:inline-flex;align-items:center;gap:8px;background:rgba(0,33,71,.07);color:var(--aa-navy);font-size:.76rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:6px 16px;border-radius:50px;margin-bottom:12px;}
.aa-section-tag .dot-sm{width:6px;height:6px;border-radius:50%;background:var(--aa-gold);}
.aa-section-title{font-size:clamp(1.5rem,3.5vw,2.2rem);font-weight:800;color:var(--aa-navy);margin-bottom:12px;line-height:1.2;}
.aa-divider{width:56px;height:4px;border-radius:2px;background:linear-gradient(90deg,var(--aa-gold),var(--aa-blue));margin:14px 0 0;}
.aa-fade{opacity:0;transform:translateY(30px);transition:opacity .7s ease,transform .7s ease;}
.aa-fade.visible{opacity:1;transform:translateY(0);}
.aa-fade-delay-1{transition-delay:.1s;}.aa-fade-delay-2{transition-delay:.2s;}.aa-fade-delay-3{transition-delay:.3s;}
</style>
</head>
<body>
<?php include __DIR__ . '/includes/header-top.php'; ?>
<?php include __DIR__ . '/includes/nav-menu.php'; ?>

<section class="aa-hero">
    <div class="aa-circle c1"></div><div class="aa-circle c2"></div><div class="aa-circle c3"></div>
    <div class="container" style="position:relative;z-index:2;">
        <div class="breadcrumb-nav">
            <a href="/index.php">Home</a><span class="sep">/</span>
            <a href="#">About</a><span class="sep">/</span>
            <span style="color:rgba(255,255,255,.85);"><?= fh(aas($s,'hero_title','Office of Accounts &amp; Audit')) ?></span>
        </div>
        <div class="aa-hero-tag"><span class="dot"></span>Prime University · Bangladesh</div>
        <h1><?php $w=explode(' ',aas($s,'hero_title','Office of Accounts & Audit'));$l=array_pop($w);echo fh(implode(' ',$w)).' <span class="accent">'.fh($l).'</span>'; ?></h1>
        <?php if (aas($s,'hero_subtitle','')!==''): ?><p class="aa-designation" style="background:rgba(255,184,28,.18);color:var(--aa-gold);margin-bottom:16px;"><?= fh(aas($s,'hero_subtitle','')) ?></p><?php endif; ?>
        <?php if (aas($s,'hero_intro','')!==''): ?><p class="hero-sub"><?= fh(aas($s,'hero_intro','')) ?></p><?php endif; ?>
    </div>
</section>

<div class="aa-facts-strip">
    <div class="container"><div class="row g-0 justify-content-center">
        <?php if (aas($s,'head_phone','')!==''): ?>
        <div class="col-sm-6 col-md-4 col-lg-3"><div class="aa-fact-item"><i class="fas fa-phone aa-fact-icon"></i><div class="aa-fact-text"><strong><?= fh(aas($s,'head_phone','')) ?></strong><span>Direct Line</span></div></div></div>
        <?php endif; ?>
        <?php if (aas($s,'head_email_1','')!==''): ?>
        <div class="col-sm-6 col-md-4 col-lg-4"><div class="aa-fact-item"><i class="fas fa-envelope aa-fact-icon"></i><div class="aa-fact-text"><strong><?= fh(aas($s,'head_email_1','')) ?></strong><span>Official Email</span></div></div></div>
        <?php endif; ?>
        <?php if (count($staff)>0): ?>
        <div class="col-sm-6 col-md-4 col-lg-3"><div class="aa-fact-item"><i class="fas fa-users aa-fact-icon"></i><div class="aa-fact-text"><strong><?= count($staff) ?> Staff Member<?= count($staff)!==1?'s':'' ?></strong><span>Office Team</span></div></div></div>
        <?php endif; ?>
    </div></div>
</div>

<section class="aa-profile-section">
    <div class="container"><div class="row justify-content-center"><div class="col-xl-10 col-lg-11">
        <div class="aa-profile-card aa-fade">
            <div class="aa-card-accent"></div>
            <div class="aa-card-body">
                <div class="row align-items-center g-4">
                    <div class="col-md-auto text-center text-md-start">
                        <div class="aa-photo-wrap">
                            <?php if ($head_photo_url): ?><img src="<?= fh($head_photo_url) ?>" alt="<?= fh(aas($s,'head_name','Head of Accounts & Audit')) ?>">
                            <?php else: ?><div class="aa-photo-placeholder"><i class="fas fa-file-invoice-dollar"></i></div><?php endif; ?>
                            <div class="aa-photo-badge"><i class="fas fa-star"></i></div>
                        </div>
                    </div>
                    <div class="col-md">
                        <h2 class="aa-name"><?= fh(aas($s,'head_name','—')) ?></h2>
                        <div class="aa-designation"><i class="fas fa-file-invoice-dollar me-1"></i><?= fh(aas($s,'head_title','')) ?></div>
                        <ul class="aa-contact-list">
                            <?php if (aas($s,'head_email_1','')!==''): ?><li><span class="aa-contact-icon"><i class="fas fa-envelope"></i></span><span><a href="mailto:<?= fh(aas($s,'head_email_1','')) ?>"><?= fh(aas($s,'head_email_1','')) ?></a></span></li><?php endif; ?>
                            <?php if (aas($s,'head_email_2','')!==''): ?><li><span class="aa-contact-icon"><i class="fas fa-envelope"></i></span><span><a href="mailto:<?= fh(aas($s,'head_email_2','')) ?>"><?= fh(aas($s,'head_email_2','')) ?></a></span></li><?php endif; ?>
                            <?php if (aas($s,'head_phone','')!==''): ?><li><span class="aa-contact-icon"><i class="fas fa-phone"></i></span><span><a href="tel:<?= fh(aas($s,'head_phone','')) ?>"><?= fh(aas($s,'head_phone','')) ?></a></span></li><?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div></div></div>
</section>

<?php if (aas($s,'head_bio','')!==''): ?>
<section class="aa-bio-section">
    <div class="container"><div class="row justify-content-center"><div class="col-xl-10 col-lg-11">
        <div class="aa-bio-card aa-fade">
            <p><?= nl2br(fh(aas($s,'head_bio',''))) ?></p>
        </div>
    </div></div></div>
</section>
<?php endif; ?>

<?php if (!empty($message_paragraphs)): ?>
<section class="aa-message-section">
    <div class="container"><div class="row justify-content-center"><div class="col-xl-10 col-lg-11">
        <div class="aa-message-card aa-fade">
            <div class="aa-msg-header">
                <div class="aa-msg-icon"><i class="fas fa-quote-left"></i></div>
                <h3 class="aa-msg-title"><?= fh(aas($s,'message_title','Message from the Office of Accounts & Audit')) ?></h3>
                <div class="aa-msg-divider"></div>
            </div>
            <div class="aa-msg-body">
                <?php foreach ($message_paragraphs as $p): ?><p><?= fh($p) ?></p><?php endforeach; ?>
            </div>
            <div class="aa-msg-sig">
                <?php if ($head_photo_url): ?>
                <img src="<?= fh($head_photo_url) ?>" class="aa-msg-sig-photo" alt="<?= fh(aas($s,'head_name','')) ?>">
                <?php else: ?><div class="aa-msg-sig-placeholder"><i class="fas fa-file-invoice-dollar"></i></div><?php endif; ?>
                <div>
                    <div class="aa-msg-sig-name"><?= fh(aas($s,'head_name','—')) ?></div>
                    <div class="aa-msg-sig-role"><?= fh(aas($s,'head_title','')) ?></div>
                </div>
            </div>
        </div>
    </div></div></div>
</section>
<?php endif; ?>

<?php if (!empty($staff)): ?>
<section class="aa-staff-section">
    <div class="container">
        <div class="text-center mb-5 aa-fade">
            <div class="aa-section-tag"><span class="dot-sm"></span>Our Team</div>
            <h2 class="aa-section-title">Office Staff Directory</h2>
            <div class="aa-divider mx-auto"></div>
        </div>
        <div class="row g-4">
            <?php foreach ($staff as $i => $st): ?>
            <div class="col-xl-3 col-lg-4 col-md-6 aa-fade aa-fade-delay-<?= ($i % 3) + 1 ?>">
                <div class="aa-staff-card">
                    <div class="aa-staff-card-accent"></div>
                    <div class="aa-staff-card-body">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <?php if (!empty($st['photo'])): ?>
                            <img src="<?= fh(ADMIN_UPLOAD_URL . '/office-of-accounts-audit/' . $st['photo']) ?>" class="aa-staff-photo" alt="<?= fh($st['name']) ?>">
                            <?php else: ?>
                            <div class="aa-staff-avatar"><i class="fas fa-user"></i></div>
                            <?php endif; ?>
                            <div class="flex-grow-1 min-w-0">
                                <div class="aa-staff-name"><?= fh($st['name']) ?></div>
                                <?php if ($st['title']): ?><span class="aa-staff-title"><?= fh($st['title']) ?></span><?php endif; ?>
                            </div>
                        </div>
                        <?php if ($st['email_1'] || $st['phone']): ?>
                        <div class="aa-staff-meta">
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
    document.querySelectorAll('.aa-fade').forEach(el => observer.observe(el));
});
</script>
</body>
</html>
