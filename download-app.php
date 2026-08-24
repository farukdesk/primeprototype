<?php
// ============================================================
// PU Portal App - Landing Page
// Replace YOUTUBE_VIDEO_ID below with your actual YouTube
// Shorts / 9:16 video ID (the part after "v=" or "shorts/").
// ============================================================
$YOUTUBE_VIDEO_ID = 'tTRdKca_JJU';
$APK_URL          = 'https://primeuniversity.ac.bd/downloads/app/pu-portal-v1.0.9.apk';
$WEB_PORTAL_URL   = 'https://primeuniversity.ac.bd/admin/index.php';
$APP_VERSION      = 'v1.0.9';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PU Portal App &mdash; Prime University Student Portal</title>
<meta name="description" content="Download the official Prime University Student Portal Android app. Watch the installation tutorial, download the APK, or log in using the web portal.">
<meta name="theme-color" content="#0d1b3e">
<style>
  :root{
    --navy:#0d1b3e;
    --navy-2:#152a5c;
    --accent:#2e7d32;
    --accent-2:#43a047;
    --gold:#f5b301;
    --text:#1e2733;
    --muted:#5b6b7f;
    --bg:#f4f7fc;
    --card:#ffffff;
    --radius:18px;
    --shadow:0 10px 30px rgba(13,27,62,.12);
  }
  *{margin:0;padding:0;box-sizing:border-box}
  html{scroll-behavior:smooth}
  body{font-family:'Segoe UI',system-ui,-apple-system,Roboto,'Helvetica Neue',Arial,sans-serif;color:var(--text);background:var(--bg);line-height:1.6}
  img{max-width:100%;display:block}
  a{text-decoration:none;color:inherit}
  .container{width:min(1120px,92%);margin-inline:auto}

  /* ---------- Header ---------- */
  header{position:sticky;top:0;z-index:50;background:rgba(13,27,62,.95);backdrop-filter:blur(8px);color:#fff}
  .nav{display:flex;align-items:center;justify-content:space-between;padding:.8rem 0}
  .brand{display:flex;align-items:center;gap:.6rem;font-weight:700;font-size:1.05rem}
  .brand .logo{height:42px;padding:4px 8px;border-radius:10px;background:#fff;display:flex;align-items:center;box-shadow:0 2px 8px rgba(0,0,0,.25)}
  .brand .logo img{height:100%;width:auto;object-fit:contain}
  .nav-cta{display:inline-flex;align-items:center;gap:.45rem;background:var(--accent);padding:.5rem 1rem;border-radius:999px;font-weight:600;font-size:.85rem;transition:.25s}
  .nav-cta:hover{background:var(--accent-2);transform:translateY(-1px)}

  /* ---------- Hero ---------- */
  .hero{background:linear-gradient(160deg,var(--navy) 0%,var(--navy-2) 55%,#1d3a7a 100%);color:#fff;padding:3rem 0 4.5rem;position:relative;overflow:hidden}
  .hero::after{content:"";position:absolute;inset:auto -10% -60% -10%;height:70%;background:radial-gradient(ellipse at center,rgba(245,179,1,.16),transparent 65%)}
  .hero-grid{display:grid;grid-template-columns:1.2fr .8fr;gap:3rem;align-items:center;position:relative;z-index:1}
  .badge{display:inline-flex;align-items:center;gap:.4rem;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.25);padding:.35rem .9rem;border-radius:999px;font-size:.78rem;letter-spacing:.4px;text-transform:uppercase;font-weight:600;margin-bottom:1.1rem}
  .badge .dot{width:8px;height:8px;border-radius:50%;background:var(--gold);animation:pulse 1.8s infinite}
  @keyframes pulse{0%,100%{opacity:1}50%{opacity:.35}}
  .hero h1{font-size:clamp(1.9rem,4.5vw,3.1rem);line-height:1.15;font-weight:800;margin-bottom:1rem}
  .hero h1 span{color:var(--gold)}
  .hero p.lead{color:#c9d4ea;font-size:clamp(.98rem,2vw,1.12rem);max-width:34rem;margin-bottom:1.8rem}
  .cta-row{display:flex;flex-wrap:wrap;gap:.9rem;margin-bottom:1.2rem}
  .btn{display:inline-flex;align-items:center;justify-content:center;gap:.6rem;font-weight:700;border-radius:14px;padding:.95rem 1.6rem;font-size:1rem;transition:.25s;border:2px solid transparent}
  .btn svg{flex:none}
  .btn-primary{background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#fff;box-shadow:0 8px 24px rgba(46,125,50,.45)}
  .btn-primary:hover{transform:translateY(-2px);box-shadow:0 12px 30px rgba(46,125,50,.55)}
  .btn-outline{border-color:rgba(255,255,255,.45);color:#fff;background:rgba(255,255,255,.06)}
  .btn-outline:hover{background:rgba(255,255,255,.14);transform:translateY(-2px)}
  .btn small{display:block;font-weight:400;font-size:.7rem;opacity:.85;line-height:1;margin-top:2px}
  .btn .stack{display:flex;flex-direction:column;align-items:flex-start;line-height:1.2;text-align:left}
  .hero-note{font-size:.82rem;color:#9fb0d0;display:flex;align-items:center;gap:.45rem}
  .beta-alert{display:flex;gap:.8rem;align-items:flex-start;background:rgba(245,179,1,.12);border:1px solid rgba(245,179,1,.5);border-left:4px solid var(--gold);border-radius:12px;padding:.9rem 1.1rem;margin-bottom:1.6rem;max-width:36rem;text-align:left}
  .beta-alert .ic{flex:none;font-size:1.2rem;line-height:1.3}
  .beta-alert p{font-size:.86rem;color:#e8d9a8;margin:0}
  .beta-alert strong{color:var(--gold)}
  @media (max-width:960px){.beta-alert{margin-inline:auto}}

  /* ---------- Video (highlighted, 9:16) ---------- */
  .video-wrap{display:flex;flex-direction:column;align-items:center;gap:.9rem}
  .video-label{display:inline-flex;align-items:center;gap:.5rem;background:var(--gold);color:var(--navy);font-weight:800;font-size:.8rem;padding:.4rem 1rem;border-radius:999px;text-transform:uppercase;letter-spacing:.5px;box-shadow:0 6px 18px rgba(245,179,1,.4)}
  .phone-frame{width:min(300px,78vw);aspect-ratio:9/16;border-radius:28px;padding:10px;background:linear-gradient(145deg,#22345f,#0a1430);box-shadow:0 24px 60px rgba(0,0,0,.5),0 0 0 3px rgba(245,179,1,.55);position:relative}
  .phone-frame::before{content:"";position:absolute;top:16px;left:50%;transform:translateX(-50%);width:70px;height:6px;border-radius:99px;background:rgba(255,255,255,.18);z-index:2}
  .phone-frame iframe{width:100%;height:100%;border:0;border-radius:20px;background:#000}

  /* ---------- Sections ---------- */
  section{padding:3.5rem 0}
  .section-head{text-align:center;max-width:40rem;margin:0 auto 2.4rem}
  .section-head h2{font-size:clamp(1.5rem,3.4vw,2.1rem);font-weight:800;color:var(--navy);margin-bottom:.5rem}
  .section-head p{color:var(--muted)}

  /* Steps */
  .steps{display:grid;grid-template-columns:repeat(4,1fr);gap:1.2rem}
  .step{background:var(--card);border-radius:var(--radius);padding:1.6rem 1.3rem;box-shadow:var(--shadow);position:relative;transition:.25s}
  .step:hover{transform:translateY(-5px)}
  .step .num{width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,var(--navy),var(--navy-2));color:var(--gold);font-weight:800;display:grid;place-items:center;font-size:1.05rem;margin-bottom:.9rem}
  .step h3{font-size:1rem;margin-bottom:.4rem;color:var(--navy)}
  .step p{font-size:.88rem;color:var(--muted)}

  /* Features */
  .features{display:grid;grid-template-columns:repeat(3,1fr);gap:1.2rem}
  .feature{background:var(--card);border-radius:var(--radius);padding:1.5rem;box-shadow:var(--shadow);display:flex;gap:1rem;align-items:flex-start;transition:.25s}
  .feature:hover{transform:translateY(-5px)}
  .feature .icon{flex:none;width:46px;height:46px;border-radius:13px;background:#e8f5e9;display:grid;place-items:center;font-size:1.3rem}
  .feature h3{font-size:.98rem;color:var(--navy);margin-bottom:.25rem}
  .feature p{font-size:.86rem;color:var(--muted)}

  /* Web portal strip */
  .portal-strip{background:linear-gradient(135deg,var(--navy),#1d3a7a);border-radius:var(--radius);color:#fff;padding:2.4rem 2rem;display:flex;align-items:center;justify-content:space-between;gap:1.5rem;flex-wrap:wrap;box-shadow:var(--shadow)}
  .portal-strip h3{font-size:1.3rem;margin-bottom:.3rem}
  .portal-strip p{color:#c9d4ea;font-size:.92rem;max-width:32rem}

  /* Download banner */
  .dl-banner{text-align:center;background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);padding:2.8rem 1.5rem}
  .dl-banner h2{color:var(--navy);font-size:clamp(1.4rem,3vw,1.9rem);margin-bottom:.5rem}
  .dl-banner p{color:var(--muted);margin-bottom:1.5rem}

  footer{background:var(--navy);color:#9fb0d0;text-align:center;padding:1.6rem 1rem;font-size:.85rem}
  footer a{color:var(--gold)}

  /* Sticky mobile download bar */
  .mobile-bar{display:none;position:fixed;bottom:0;left:0;right:0;z-index:60;background:rgba(13,27,62,.97);padding:.7rem .9rem;box-shadow:0 -6px 20px rgba(0,0,0,.25)}
  .mobile-bar .btn{width:100%;padding:.8rem 1rem;font-size:.95rem}

  /* ---------- Responsive ---------- */
  @media (max-width:960px){
    .hero-grid{grid-template-columns:1fr;text-align:center;gap:2.5rem}
    .hero p.lead{margin-inline:auto}
    .cta-row{justify-content:center}
    .hero-note{justify-content:center}
    .steps{grid-template-columns:repeat(2,1fr)}
    .features{grid-template-columns:repeat(2,1fr)}
    .video-wrap{order:-1}
  }
  @media (max-width:600px){
    .steps{grid-template-columns:1fr}
    .features{grid-template-columns:1fr}
    .portal-strip{flex-direction:column;text-align:center}
    .btn{width:100%;max-width:340px}
    .mobile-bar{display:block}
    body{padding-bottom:64px}
    section{padding:2.6rem 0}
  }
</style>
</head>
<body>

<header>
  <div class="container nav">
    <a class="brand" href="https://primeuniversity.ac.bd">
      <span class="logo"><img src="/assets/img/logo/logo-black.png" alt="Prime University"></span>
      <span>PU Portal <small style="opacity:.6;font-weight:400">&mdash; Prime University</small></span>
    </a>
    <a class="nav-cta" href="<?= htmlspecialchars($APK_URL) ?>" download>
      <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M12 16l-6-6h4V4h4v6h4l-6 6zm-8 2h16v2H4v-2z"/></svg>
      Download
    </a>
  </div>
</header>

<!-- ================= HERO ================= -->
<section class="hero">
  <div class="container hero-grid">
    <div>
      <span class="badge"><span class="dot"></span> Beta &middot; Android App <?= htmlspecialchars($APP_VERSION) ?></span>
      <h1>Your Campus, <span>In Your Pocket.</span></h1>
      <p class="lead">The official <strong>Prime University Student Portal</strong> app. Check results, class routines, notices, fees and more &mdash; anytime, anywhere. Watch the quick video tutorial to install it in under 2 minutes.</p>
      <div class="beta-alert">
        <span class="ic">&#9888;&#65039;</span>
        <p><strong>Beta Version &mdash; For Testing Purposes Only.</strong> This is a beta release of the PU Portal app. A final version is currently awaiting <strong>Google Play Store</strong> review. Once approved, you will be able to install the app directly from the Google Play Store.</p>
      </div>
      <div class="cta-row">
        <a class="btn btn-primary" href="<?= htmlspecialchars($APK_URL) ?>" download>
          <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><path d="M17.6 9.48l1.84-3.18c.16-.31.04-.7-.26-.85-.29-.15-.65-.06-.83.22l-1.88 3.24a11.46 11.46 0 0 0-8.94 0L5.65 5.67c-.19-.29-.55-.37-.84-.22-.3.15-.42.54-.26.85L6.4 9.48A10.81 10.81 0 0 0 1 18h22a10.81 10.81 0 0 0-5.4-8.52zM7 15.25a1.25 1.25 0 1 1 0-2.5 1.25 1.25 0 0 1 0 2.5zm10 0a1.25 1.25 0 1 1 0-2.5 1.25 1.25 0 0 1 0 2.5z"/></svg>
          <span class="stack">Download APK<small>Android &middot; <?= htmlspecialchars($APP_VERSION) ?> &middot; Free</small></span>
        </a>
        <a class="btn btn-outline" href="<?= htmlspecialchars($WEB_PORTAL_URL) ?>" target="_blank" rel="noopener">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm7.93 9h-3.02a15.7 15.7 0 0 0-1.27-6.06A8.02 8.02 0 0 1 19.93 11zM12 4c.9 1.2 1.9 3.6 2.1 7H9.9c.2-3.4 1.2-5.8 2.1-7zM8.36 4.94A15.7 15.7 0 0 0 7.09 11H4.07a8.02 8.02 0 0 1 4.29-6.06zM4.07 13h3.02c.14 2.3.6 4.4 1.27 6.06A8.02 8.02 0 0 1 4.07 13zM12 20c-.9-1.2-1.9-3.6-2.1-7h4.2c-.2 3.4-1.2 5.8-2.1 7zm3.64-.94A15.7 15.7 0 0 0 16.91 13h3.02a8.02 8.02 0 0 1-4.29 6.06z"/></svg>
          <span class="stack">Login via Web Portal<small>No installation needed</small></span>
        </a>
      </div>
      <p class="hero-note">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="#f5b301"><path d="M12 1 3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/></svg>
        Safe &amp; official &mdash; hosted on primeuniversity.ac.bd
      </p>
    </div>

    <!-- Highlighted 9:16 video tutorial -->
    <div class="video-wrap" id="tutorial">
      <span class="video-label">&#9654; Watch: How to Install</span>
      <div class="phone-frame">
        <iframe
          src="https://www.youtube.com/embed/<?= htmlspecialchars($YOUTUBE_VIDEO_ID) ?>?rel=0&modestbranding=1&playsinline=1"
          title="PU Portal App - Installation Tutorial"
          allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
          allowfullscreen loading="lazy"></iframe>
      </div>
    </div>
  </div>
</section>

<!-- ================= INSTALL STEPS ================= -->
<section id="install">
  <div class="container">
    <div class="section-head">
      <h2>Manual Installation in 4 Easy Steps</h2>
      <p>This app is installed manually (not from the Play Store). Follow the video above or the steps below.</p>
    </div>
    <div class="steps">
      <div class="step"><div class="num">1</div><h3>Download the APK</h3><p>Tap the <strong>Download APK</strong> button on this page. The file <em>pu-portal-<?= htmlspecialchars($APP_VERSION) ?>.apk</em> will be saved to your phone.</p></div>
      <div class="step"><div class="num">2</div><h3>Allow Unknown Apps</h3><p>When prompted, allow your browser to <strong>install unknown apps</strong> in Settings &rarr; Security. This is required only once.</p></div>
      <div class="step"><div class="num">3</div><h3>Install the App</h3><p>Open the downloaded file from your notifications or the <strong>Downloads</strong> folder and tap <strong>Install</strong>.</p></div>
      <div class="step"><div class="num">4</div><h3>Login &amp; Go</h3><p>Open <strong>PU Portal</strong>, log in with your student ID and password, and you're all set!</p></div>
    </div>
  </div>
</section>

<!-- ================= FEATURES ================= -->
<section style="padding-top:0">
  <div class="container">
    <div class="section-head">
      <h2>Everything a Student Needs</h2>
      <p>One app for your entire academic life at Prime University.</p>
    </div>
    <div class="features">
      <div class="feature"><div class="icon">&#128203;</div><div><h3>Results &amp; Grades</h3><p>View semester results and CGPA the moment they're published.</p></div></div>
      <div class="feature"><div class="icon">&#128197;</div><div><h3>Class Routine</h3><p>Your up-to-date class schedule, right on your home screen.</p></div></div>
      <div class="feature"><div class="icon">&#128276;</div><div><h3>Notices &amp; Alerts</h3><p>Never miss important announcements from your department.</p></div></div>
      <div class="feature"><div class="icon">&#128179;</div><div><h3>Fees &amp; Payments</h3><p>Check dues, payment history and fee deadlines instantly.</p></div></div>
      <div class="feature"><div class="icon">&#9989;</div><div><h3>Attendance</h3><p>Track your attendance across all enrolled courses.</p></div></div>
      <div class="feature"><div class="icon">&#128100;</div><div><h3>Profile &amp; More</h3><p>Manage your student profile and access campus services.</p></div></div>
    </div>
  </div>
</section>

<!-- ================= WEB PORTAL ================= -->
<section style="padding-top:0">
  <div class="container">
    <div class="portal-strip">
      <div>
        <h3>Prefer the browser? Use the Web Portal.</h3>
        <p>All portal features are also available from any device through the web app &mdash; no installation required.</p>
      </div>
      <a class="btn btn-primary" href="<?= htmlspecialchars($WEB_PORTAL_URL) ?>" target="_blank" rel="noopener">Login to Web Portal &rarr;</a>
    </div>
  </div>
</section>

<!-- ================= FINAL CTA ================= -->
<section style="padding-top:0">
  <div class="container">
    <div class="dl-banner">
      <h2>Ready to get started?</h2>
      <p>Download the PU Portal beta app now &mdash; it takes less than 2 minutes to install.<br><small style="color:#b58900">&#9888;&#65039; Beta release for testing purposes. The final version will be available on the Google Play Store once its review is approved.</small></p>
      <a class="btn btn-primary" href="<?= htmlspecialchars($APK_URL) ?>" download>
        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 16l-6-6h4V4h4v6h4l-6 6zm-8 2h16v2H4v-2z"/></svg>
        Download PU Portal <?= htmlspecialchars($APP_VERSION) ?>
      </a>
    </div>
  </div>
</section>

<footer>
  &copy; <?= date('Y') ?> Prime University. All rights reserved. &middot; <a href="https://primeuniversity.ac.bd" target="_blank" rel="noopener">primeuniversity.ac.bd</a>
</footer>

<!-- Sticky download bar (mobile only) -->
<div class="mobile-bar">
  <a class="btn btn-primary" href="<?= htmlspecialchars($APK_URL) ?>" download>
    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 16l-6-6h4V4h4v6h4l-6 6zm-8 2h16v2H4v-2z"/></svg>
    Download APK (<?= htmlspecialchars($APP_VERSION) ?>)
  </a>
</div>

</body>
</html>
