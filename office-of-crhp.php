<?php
require_once __DIR__ . '/includes/config.php';

$s = [];
$staff = [];
try {
    $db = front_db();
    if ($db) {
        $rows = $db->query('SELECT setting_key, setting_val FROM crhp_settings')->fetchAll();
        foreach ($rows as $r) $s[$r['setting_key']] = $r['setting_val'];
        $staff = $db->query('SELECT * FROM crhp_staff WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
    }
} catch (Throwable $e) {}

function crhps(array $s, string $key, string $default = ''): string {
    return isset($s[$key]) && $s[$key] !== '' ? $s[$key] : $default;
}

if (crhps($s, 'is_published', '1') !== '1') { header('Location: /index.php'); exit; }

$page_title = crhps($s, 'hero_title', 'Office of the CRHP') . ' – Prime University';
$meta_desc  = crhps($s, 'meta_description', 'Office of the CRHP – Prime University');

$message_paragraphs = [];
$raw_message = crhps($s, 'message_body', '');
if ($raw_message !== '') {
    $message_paragraphs = array_filter(
        array_map('trim', preg_split('/\n{2,}/', $raw_message)),
        fn($p) => $p !== ''
    );
}

$head_photo_url = !empty($s['head_photo']) ? ADMIN_UPLOAD_URL . '/office-of-crhp/' . $s['head_photo'] : '';
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
  --cr-navy:#002147;--cr-gold:#FFB81C;--cr-blue:#1a4faf;
  --cr-green:#16a34a;--cr-light:#f4f7fb;--cr-text:#334155;--cr-muted:#64748b;
  --cr-shadow:0 8px 40px rgba(0,33,71,.10);--cr-shadow-h:0 18px 60px rgba(0,33,71,.18);
  --cr-trans:.35s cubic-bezier(.4,0,.2,1);
}
.cr-hero{background:linear-gradient(135deg,#001530 0%,#002f68 55%,#1a4faf 100%);padding:110px 0 90px;position:relative;overflow:hidden;}
.cr-hero::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 60% 80% at 80% 50%,rgba(255,184,28,.10) 0%,transparent 70%),radial-gradient(ellipse 40% 60% at 10% 80%,rgba(26,79,175,.35) 0%,transparent 60%);pointer-events:none;}
.cr-circle{position:absolute;border-radius:50%;pointer-events:none;animation:crFloat 9s ease-in-out infinite;}
.cr-circle.c1{width:380px;height:380px;background:rgba(255,184,28,.07);top:-90px;right:-70px;}
.cr-circle.c2{width:200px;height:200px;background:rgba(255,255,255,.05);bottom:20px;left:4%;animation-delay:3s;}
.cr-circle.c3{width:120px;height:120px;background:rgba(255,184,28,.10);top:35%;right:22%;animation-delay:1.5s;}
@keyframes crFloat{0%,100%{transform:translateY(0) scale(1);}50%{transform:translateY(-22px) scale(1.05);}}
.cr-hero .breadcrumb-nav{display:flex;align-items:center;gap:8px;font-size:.82rem;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:rgba(255,255,255,.65);margin-bottom:24px;}
.cr-hero .breadcrumb-nav a{color:var(--cr-gold);text-decoration:none;}
.cr-hero .breadcrumb-nav .sep{color:rgba(255,255,255,.35);}
.cr-hero-tag{display:inline-flex;align-items:center;gap:8px;background:rgba(255,184,28,.18);color:var(--cr-gold);font-size:.77rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;padding:7px 18px;border-radius:50px;border:1px solid rgba(255,184,28,.35);margin-bottom:20px;}
.cr-hero-tag .dot{width:7px;height:7px;border-radius:50%;background:var(--cr-gold);animation:crPulse 1.8s ease-in-out infinite;}
@keyframes crPulse{0%,100%{opacity:1;transform:scale(1);}50%{opacity:.5;transform:scale(1.6);}}
.cr-hero h1{font-size:clamp(2rem,5vw,3.4rem);font-weight:800;color:#fff;line-height:1.15;margin-bottom:18px;}
.cr-hero h1 .accent{color:var(--cr-gold);}
.cr-hero .hero-sub{font-size:clamp(.93rem,1.8vw,1.1rem);color:rgba(255,255,255,.78);max-width:580px;line-height:1.75;}
.cr-facts-strip{background:var(--cr-navy);padding:24px 0;}
.cr-fact-item{display:flex;align-items:center;gap:12px;color:rgba(255,255,255,.85);padding:10px 20px;border-right:1px solid rgba(255,255,255,.12);}
.cr-fact-item:last-child{border-right:none;}
.cr-fact-icon{color:var(--cr-gold);font-size:1.2rem;flex-shrink:0;}
.cr-fact-text strong{display:block;font-size:.92rem;font-weight:700;color:#fff;}
.cr-fact-text span{font-size:.77rem;color:rgba(255,255,255,.55);letter-spacing:.04em;text-transform:uppercase;}
@media(max-width:767.98px){.cr-fact-item{border-right:none;border-bottom:1px solid rgba(255,255,255,.10);}.cr-fact-item:last-child{border-bottom:none;}}
.cr-profile-section{padding:80px 0 60px;background:#fff;}
.cr-profile-card{background:#fff;border-radius:24px;box-shadow:var(--cr-shadow);overflow:hidden;transition:box-shadow var(--cr-trans);border:1px solid rgba(0,33,71,.06);}
.cr-profile-card:hover{box-shadow:var(--cr-shadow-h);}
.cr-card-accent{height:6px;background:linear-gradient(90deg,var(--cr-navy) 0%,var(--cr-blue) 50%,var(--cr-gold) 100%);}
.cr-card-body{padding:40px;}
@media(max-width:767.98px){.cr-card-body{padding:28px 20px;}}
.cr-photo-wrap{position:relative;display:inline-block;}
.cr-photo-wrap img{width:160px;height:160px;border-radius:50%;object-fit:cover;object-position:top center;border:5px solid #fff;box-shadow:0 8px 32px rgba(0,33,71,.18);display:block;}
.cr-photo-badge{position:absolute;bottom:6px;right:6px;width:32px;height:32px;border-radius:50%;background:var(--cr-gold);border:3px solid #fff;display:flex;align-items:center;justify-content:center;}
.cr-photo-badge i{color:var(--cr-navy);font-size:.7rem;}
.cr-photo-placeholder{width:160px;height:160px;border-radius:50%;background:linear-gradient(135deg,var(--cr-navy),var(--cr-blue));display:flex;align-items:center;justify-content:center;box-shadow:0 8px 32px rgba(0,33,71,.18);border:5px solid #fff;}
.cr-photo-placeholder i{color:rgba(255,255,255,.75);font-size:3.5rem;}
.cr-name{font-size:clamp(1.3rem,3vw,1.75rem);font-weight:800;color:var(--cr-navy);margin-bottom:4px;}
.cr-designation{display:inline-flex;align-items:center;gap:8px;background:var(--cr-navy);color:var(--cr-gold);font-size:.8rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:5px 16px;border-radius:50px;margin-bottom:24px;}
.cr-contact-list{list-style:none;padding:0;margin:0;}
.cr-contact-list li{display:flex;align-items:flex-start;gap:12px;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:.9rem;color:var(--cr-text);}
.cr-contact-list li:last-child{border-bottom:none;}
.cr-contact-icon{width:34px;height:34px;border-radius:8px;flex-shrink:0;background:var(--cr-light);display:flex;align-items:center;justify-content:center;}
.cr-contact-icon i{color:var(--cr-blue);font-size:.85rem;}
.cr-contact-list a{color:var(--cr-blue);text-decoration:none;transition:color var(--cr-trans);}
.cr-contact-list a:hover{color:var(--cr-navy);text-decoration:underline;}
.cr-bio-section{background:var(--cr-light);padding:70px 0;}
.cr-bio-card{background:#fff;border-radius:20px;padding:44px 48px;box-shadow:var(--cr-shadow);border-left:5px solid var(--cr-gold);position:relative;}
@media(max-width:767.98px){.cr-bio-card{padding:28px 22px;}}
.cr-bio-card p{font-size:1.02rem;color:var(--cr-text);line-height:1.85;margin-bottom:0;}
.cr-message-section{padding:80px 0;background:#fff;position:relative;overflow:hidden;}
.cr-message-section::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,var(--cr-navy),var(--cr-blue),var(--cr-gold));}
.cr-message-card{background:linear-gradient(135deg,#001e45 0%,#002f68 100%);border-radius:24px;padding:56px 56px 48px;position:relative;overflow:hidden;box-shadow:0 16px 56px rgba(0,33,71,.22);}
@media(max-width:767.98px){.cr-message-card{padding:36px 24px 32px;}}
.cr-message-card::before{content:'';position:absolute;top:-60px;right:-60px;width:280px;height:280px;border-radius:50%;background:rgba(255,184,28,.08);pointer-events:none;}
.cr-message-card::after{content:'';position:absolute;bottom:-80px;left:-40px;width:220px;height:220px;border-radius:50%;background:rgba(26,79,175,.3);pointer-events:none;}
.cr-msg-header{position:relative;z-index:1;margin-bottom:32px;}
.cr-msg-icon{width:56px;height:56px;border-radius:14px;background:rgba(255,184,28,.2);border:1px solid rgba(255,184,28,.4);display:flex;align-items:center;justify-content:center;margin-bottom:16px;}
.cr-msg-icon i{color:var(--cr-gold);font-size:1.4rem;}
.cr-msg-title{font-size:clamp(1.35rem,3vw,1.9rem);font-weight:800;color:#fff;margin-bottom:4px;}
.cr-msg-subtitle{color:rgba(255,255,255,.6);font-size:.9rem;}
.cr-msg-divider{height:2px;width:60px;background:linear-gradient(90deg,var(--cr-gold),transparent);margin:20px 0 28px;}
.cr-msg-body{position:relative;z-index:1;}
.cr-msg-body p{color:rgba(255,255,255,.88);font-size:1rem;line-height:1.9;margin-bottom:1.4rem;}
.cr-msg-body p:last-child{margin-bottom:0;}
.cr-msg-sig{position:relative;z-index:1;margin-top:36px;padding-top:24px;border-top:1px solid rgba(255,255,255,.12);display:flex;align-items:center;gap:16px;flex-wrap:wrap;}
.cr-msg-sig-photo{width:54px;height:54px;border-radius:50%;object-fit:cover;object-position:top center;border:2px solid rgba(255,184,28,.4);flex-shrink:0;}
.cr-msg-sig-placeholder{width:54px;height:54px;border-radius:50%;flex-shrink:0;background:rgba(255,255,255,.1);display:flex;align-items:center;justify-content:center;border:2px solid rgba(255,184,28,.3);}
.cr-msg-sig-placeholder i{color:rgba(255,255,255,.6);font-size:1.2rem;}
.cr-msg-sig-name{color:#fff;font-weight:700;font-size:1rem;margin-bottom:2px;}
.cr-msg-sig-role{color:var(--cr-gold);font-size:.8rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;}
/* Staff Grid */
.cr-staff-section{background:var(--cr-light);padding:80px 0;}
.cr-staff-card{background:#fff;border-radius:18px;box-shadow:var(--cr-shadow);overflow:hidden;height:100%;transition:box-shadow var(--cr-trans),transform var(--cr-trans);border:1px solid rgba(0,33,71,.06);}
.cr-staff-card:hover{box-shadow:var(--cr-shadow-h);transform:translateY(-5px);}
.cr-staff-card-accent{height:4px;background:linear-gradient(90deg,var(--cr-blue),var(--cr-gold));}
.cr-staff-card-body{padding:24px;}
.cr-staff-photo{width:72px;height:72px;border-radius:50%;object-fit:cover;object-position:top center;border:3px solid #fff;box-shadow:0 4px 16px rgba(0,33,71,.12);}
.cr-staff-avatar{width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,var(--cr-blue),#3b82f6);display:flex;align-items:center;justify-content:center;box-shadow:0 4px 14px rgba(26,79,175,.2);}
.cr-staff-avatar i{color:#fff;font-size:1.5rem;}
.cr-staff-name{font-size:1rem;font-weight:700;color:var(--cr-navy);margin-bottom:4px;}
.cr-staff-title{display:inline-block;background:rgba(26,79,175,.1);color:var(--cr-blue);font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;padding:2px 10px;border-radius:50px;margin-bottom:12px;}
.cr-staff-meta{font-size:.82rem;color:var(--cr-muted);}
.cr-staff-meta a{color:var(--cr-blue);text-decoration:none;}
.cr-staff-meta a:hover{text-decoration:underline;}
.cr-section-tag{display:inline-flex;align-items:center;gap:8px;background:rgba(0,33,71,.07);color:var(--cr-navy);font-size:.76rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:6px 16px;border-radius:50px;margin-bottom:12px;}
.cr-section-tag .dot-sm{width:6px;height:6px;border-radius:50%;background:var(--cr-gold);}
.cr-section-title{font-size:clamp(1.5rem,3.5vw,2.2rem);font-weight:800;color:var(--cr-navy);margin-bottom:12px;line-height:1.2;}
.cr-divider{width:56px;height:4px;border-radius:2px;background:linear-gradient(90deg,var(--cr-gold),var(--cr-blue));margin:14px 0 0;}
.cr-fade{opacity:0;transform:translateY(30px);transition:opacity .7s ease,transform .7s ease;}
.cr-fade.visible{opacity:1;transform:translateY(0);}
.cr-fade-delay-1{transition-delay:.1s;}.cr-fade-delay-2{transition-delay:.2s;}.cr-fade-delay-3{transition-delay:.3s;}
</style>
</head>
<body>
<?php include __DIR__ . '/includes/header-top.php'; ?>
<?php include __DIR__ . '/includes/nav-menu.php'; ?>

<section class="cr-hero">
    <div class="cr-circle c1"></div><div class="cr-circle c2"></div><div class="cr-circle c3"></div>
    <div class="container" style="position:relative;z-index:2;">
        <div class="breadcrumb-nav">
            <a href="/index.php">Home</a><span class="sep">/</span>
            <a href="#">About</a><span class="sep">/</span>
            <span style="color:rgba(255,255,255,.85);"><?= fh(crhps($s,'hero_title','Office of the CRHP')) ?></span>
        </div>
        <div class="cr-hero-tag"><span class="dot"></span>Prime University · Bangladesh</div>
        <h1><?php $w=explode(' ',crhps($s,'hero_title','Office of the CRHP'));$l=array_pop($w);echo fh(implode(' ',$w)).' <span class="accent">'.fh($l).'</span>'; ?></h1>
        <?php if (crhps($s,'hero_subtitle','')!==''): ?><p class="cr-designation" style="background:rgba(255,184,28,.18);color:var(--cr-gold);margin-bottom:16px;"><?= fh(crhps($s,'hero_subtitle','')) ?></p><?php endif; ?>
        <?php if (crhps($s,'hero_intro','')!==''): ?><p class="hero-sub"><?= fh(crhps($s,'hero_intro','')) ?></p><?php endif; ?>
    </div>
</section>

<div class="cr-facts-strip">
    <div class="container"><div class="row g-0 justify-content-center">
        <?php if (crhps($s,'head_phone','')!==''): ?>
        <div class="col-sm-6 col-md-4 col-lg-3"><div class="cr-fact-item"><i class="fas fa-phone cr-fact-icon"></i><div class="cr-fact-text"><strong><?= fh(crhps($s,'head_phone','')) ?></strong><span>Direct Line</span></div></div></div>
        <?php endif; ?>
        <?php if (crhps($s,'head_email_1','')!==''): ?>
        <div class="col-sm-6 col-md-4 col-lg-4"><div class="cr-fact-item"><i class="fas fa-envelope cr-fact-icon"></i><div class="cr-fact-text"><strong><?= fh(crhps($s,'head_email_1','')) ?></strong><span>Official Email</span></div></div></div>
        <?php endif; ?>
        <?php if (count($staff)>0): ?>
        <div class="col-sm-6 col-md-4 col-lg-3"><div class="cr-fact-item"><i class="fas fa-users cr-fact-icon"></i><div class="cr-fact-text"><strong><?= count($staff) ?> Staff Member<?= count($staff)!==1?'s':'' ?></strong><span>Office Team</span></div></div></div>
        <?php endif; ?>
    </div></div>
</div>

<section class="cr-profile-section">
    <div class="container"><div class="row justify-content-center"><div class="col-xl-10 col-lg-11">
        <div class="cr-profile-card cr-fade">
            <div class="cr-card-accent"></div>
            <div class="cr-card-body">
                <div class="row align-items-center g-4">
                    <div class="col-md-auto text-center text-md-start">
                        <div class="cr-photo-wrap">
                            <?php if ($head_photo_url): ?><img src="<?= fh($head_photo_url) ?>" alt="<?= fh(crhps($s,'head_name','Head of CRHP')) ?>">
                            <?php else: ?><div class="cr-photo-placeholder"><i class="fas fa-flask"></i></div><?php endif; ?>
                            <div class="cr-photo-badge"><i class="fas fa-star"></i></div>
                        </div>
                    </div>
                    <div class="col-md">
                        <h2 class="cr-name"><?= fh(crhps($s,'head_name','—')) ?></h2>
                        <div class="cr-designation"><i class="fas fa-flask me-1"></i><?= fh(crhps($s,'head_title','')) ?></div>
                        <ul class="cr-contact-list">
                            <?php if (crhps($s,'head_email_1','')!==''): ?><li><span class="cr-contact-icon"><i class="fas fa-envelope"></i></span><span><a href="mailto:<?= fh(crhps($s,'head_email_1','')) ?>"><?= fh(crhps($s,'head_email_1','')) ?></a></span></li><?php endif; ?>
                            <?php if (crhps($s,'head_email_2','')!==''): ?><li><span class="cr-contact-icon"><i class="fas fa-envelope"></i></span><span><a href="mailto:<?= fh(crhps($s,'head_email_2','')) ?>"><?= fh(crhps($s,'head_email_2','')) ?></a></span></li><?php endif; ?>
                            <?php if (crhps($s,'head_phone','')!==''): ?><li><span class="cr-contact-icon"><i class="fas fa-phone"></i></span><span><a href="tel:<?= fh(crhps($s,'head_phone','')) ?>"><?= fh(crhps($s,'head_phone','')) ?></a></span></li><?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div></div></div>
</section>

<?php if (crhps($s,'head_bio','')!==''): ?>
<section class="cr-bio-section">
    <div class="container"><div class="row justify-content-center"><div class="col-xl-10 col-lg-11">
        <div class="cr-bio-card cr-fade">
            <p><?= nl2br(fh(crhps($s,'head_bio',''))) ?></p>
        </div>
    </div></div></div>
</section>
<?php endif; ?>

<?php if (!empty($message_paragraphs)): ?>
<section class="cr-message-section">
    <div class="container"><div class="row justify-content-center"><div class="col-xl-10 col-lg-11">
        <div class="cr-message-card cr-fade">
            <div class="cr-msg-header">
                <div class="cr-msg-icon"><i class="fas fa-quote-left"></i></div>
                <h3 class="cr-msg-title"><?= fh(crhps($s,'message_title','Message from the Office of the CRHP')) ?></h3>
                <div class="cr-msg-divider"></div>
            </div>
            <div class="cr-msg-body">
                <?php foreach ($message_paragraphs as $p): ?><p><?= fh($p) ?></p><?php endforeach; ?>
            </div>
            <div class="cr-msg-sig">
                <?php if ($head_photo_url): ?>
                <img src="<?= fh($head_photo_url) ?>" class="cr-msg-sig-photo" alt="<?= fh(crhps($s,'head_name','')) ?>">
                <?php else: ?><div class="cr-msg-sig-placeholder"><i class="fas fa-flask"></i></div><?php endif; ?>
                <div>
                    <div class="cr-msg-sig-name"><?= fh(crhps($s,'head_name','—')) ?></div>
                    <div class="cr-msg-sig-role"><?= fh(crhps($s,'head_title','')) ?></div>
                </div>
            </div>
        </div>
    </div></div></div>
</section>
<?php endif; ?>

<?php if (!empty($staff)): ?>
<section class="cr-staff-section">
    <div class="container">
        <div class="text-center mb-5 cr-fade">
            <div class="cr-section-tag"><span class="dot-sm"></span>Our Team</div>
            <h2 class="cr-section-title">Office Staff Directory</h2>
            <div class="cr-divider mx-auto"></div>
        </div>
        <div class="row g-4">
            <?php foreach ($staff as $i => $st): ?>
            <div class="col-xl-3 col-lg-4 col-md-6 cr-fade cr-fade-delay-<?= ($i % 3) + 1 ?>">
                <div class="cr-staff-card">
                    <div class="cr-staff-card-accent"></div>
                    <div class="cr-staff-card-body">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <?php if (!empty($st['photo'])): ?>
                            <img src="<?= fh(ADMIN_UPLOAD_URL . '/office-of-crhp/' . $st['photo']) ?>" class="cr-staff-photo" alt="<?= fh($st['name']) ?>">
                            <?php else: ?>
                            <div class="cr-staff-avatar"><i class="fas fa-user"></i></div>
                            <?php endif; ?>
                            <div class="flex-grow-1 min-w-0">
                                <div class="cr-staff-name"><?= fh($st['name']) ?></div>
                                <?php if ($st['title']): ?><span class="cr-staff-title"><?= fh($st['title']) ?></span><?php endif; ?>
                            </div>
                        </div>
                        <?php if ($st['email_1'] || $st['phone']): ?>
                        <div class="cr-staff-meta">
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
    document.querySelectorAll('.cr-fade').forEach(el => observer.observe(el));
});
</script>
</body>
</html>
