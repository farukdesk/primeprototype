<?php /** Shared styles for the public journal pages (journal.php, journal-issue.php, journal-article.php) */ ?>
<style>
.jm-wrap{--jm-navy:#002147;--jm-blue:#1f5eff;--jm-gold:#f0a500;--jm-ink:#1c2437;--jm-mut:#5f6b83;--jm-border:#e6ebf4;--jm-bg:#f6f8fc;background:var(--jm-bg);}
.jm-wrap a{transition:color .15s;}

/* Hero */
.jm-hero{position:relative;overflow:hidden;border-radius:22px;padding:44px 44px 40px;color:#fff;background:linear-gradient(135deg,#002147 0%,#0b3d78 55%,#1f5eff 130%);}
.jm-hero::before{content:'';position:absolute;right:-120px;top:-120px;width:340px;height:340px;border-radius:50%;background:rgba(255,255,255,.06);}
.jm-hero::after{content:'';position:absolute;right:60px;bottom:-140px;width:260px;height:260px;border-radius:50%;background:rgba(240,165,0,.12);}
.jm-hero>*{position:relative;z-index:1;}
.jm-hero h1,.jm-hero h2{color:#fff;}
.jm-logo{width:88px;height:88px;flex:0 0 88px;border-radius:18px;background:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.5rem;color:var(--jm-navy);box-shadow:0 10px 26px rgba(0,0,0,.25);overflow:hidden;}
.jm-logo img{width:100%;height:100%;object-fit:contain;padding:8px;}
.jm-hero .jm-shortname{display:inline-block;background:var(--jm-gold);color:#2b1d00;font-weight:700;font-size:.75rem;letter-spacing:.06em;border-radius:30px;padding:4px 14px;text-transform:uppercase;}

/* Pills & chips */
.jm-pill{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.25);color:#fff;border-radius:30px;padding:5px 14px;font-size:.78rem;font-weight:500;}
.jm-chip{display:inline-flex;align-items:center;gap:6px;background:#eef3ff;color:#1f4fd8;border-radius:30px;padding:5px 14px;font-size:.78rem;font-weight:600;text-decoration:none;}
.jm-chip:hover{background:#dfe9ff;color:#123a9e;}
.jm-tag{display:inline-block;background:#fff;border:1px solid var(--jm-border);color:var(--jm-mut);border-radius:8px;padding:4px 12px;font-size:.78rem;margin:0 6px 6px 0;}

/* Cards */
.jm-card{background:#fff;border:1px solid var(--jm-border);border-radius:18px;box-shadow:0 4px 14px rgba(16,42,90,.05);}
.jm-card .jm-card-body{padding:26px 28px;}
.jm-card-hover{transition:transform .2s,box-shadow .2s;}
.jm-card-hover:hover{transform:translateY(-5px);box-shadow:0 16px 34px rgba(16,42,90,.12);}
.jm-section-title{display:flex;align-items:center;gap:10px;font-weight:700;color:var(--jm-ink);font-size:1.05rem;margin-bottom:16px;}
.jm-section-title::before{content:'';width:5px;height:22px;border-radius:4px;background:linear-gradient(180deg,var(--jm-gold),var(--jm-blue));}

/* Journal grid card */
.jm-jcard{display:flex;flex-direction:column;height:100%;}
.jm-jcard .jm-jcard-top{display:flex;align-items:center;gap:16px;}
.jm-jcard .jm-logo{width:64px;height:64px;flex:0 0 64px;border-radius:14px;background:linear-gradient(135deg,#eef3ff,#dfe9ff);color:var(--jm-navy);font-size:1.05rem;box-shadow:none;border:1px solid var(--jm-border);}
.jm-jcard h5 a{color:var(--jm-ink);text-decoration:none;}
.jm-jcard h5 a:hover{color:var(--jm-blue);}
.jm-meta-line{font-size:.8rem;color:var(--jm-mut);}
.jm-meta-line i{width:16px;color:var(--jm-blue);}

/* Issue / article listing */
.jm-issue-link{display:inline-flex;align-items:center;gap:8px;background:#fff;border:1px solid var(--jm-border);border-radius:12px;padding:8px 16px;font-size:.85rem;font-weight:600;color:var(--jm-ink);text-decoration:none;margin:0 8px 8px 0;transition:all .15s;}
.jm-issue-link:hover{border-color:var(--jm-blue);color:var(--jm-blue);box-shadow:0 6px 16px rgba(31,94,255,.12);}
.jm-issue-link .jm-issue-date{font-weight:400;color:var(--jm-mut);font-size:.75rem;}
.jm-current-card{border:0;border-radius:18px;background:linear-gradient(135deg,#fff8ea,#fff);border:1px solid #f4dfad;position:relative;}
.jm-current-card .jm-current-badge{position:absolute;top:-12px;left:24px;background:var(--jm-gold);color:#2b1d00;font-size:.72rem;font-weight:700;letter-spacing:.05em;border-radius:20px;padding:4px 14px;text-transform:uppercase;}

.jm-art{display:flex;gap:18px;padding:22px 24px;}
.jm-art .jm-art-num{flex:0 0 44px;width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#eef3ff,#dfe9ff);color:var(--jm-navy);font-weight:700;display:flex;align-items:center;justify-content:center;}
.jm-art h5{margin:0 0 6px;font-size:1.02rem;line-height:1.45;}
.jm-art h5 a{color:var(--jm-ink);text-decoration:none;}
.jm-art h5 a:hover{color:var(--jm-blue);}
.jm-art .jm-authors{font-size:.83rem;color:var(--jm-mut);}

/* Author chips */
.jm-author-chip{display:inline-flex;align-items:center;gap:8px;background:#fff;border:1px solid var(--jm-border);border-radius:30px;padding:6px 16px 6px 8px;margin:0 8px 8px 0;font-size:.83rem;color:var(--jm-ink);}
.jm-author-chip .jm-avatar{width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,var(--jm-blue),#6f9bff);color:#fff;font-size:.7rem;font-weight:700;display:flex;align-items:center;justify-content:center;}
.jm-author-chip small{color:var(--jm-mut);}

/* Buttons */
.jm-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border-radius:12px;padding:12px 24px;font-weight:600;font-size:.9rem;text-decoration:none;transition:all .18s;border:0;cursor:pointer;}
.jm-btn-pdf{background:linear-gradient(135deg,#e0342c,#b81f18);color:#fff;box-shadow:0 8px 20px rgba(224,52,44,.3);}
.jm-btn-pdf:hover{transform:translateY(-2px);box-shadow:0 12px 26px rgba(224,52,44,.4);color:#fff;}
.jm-btn-outline{border:1.5px solid var(--jm-border);color:var(--jm-ink);background:#fff;}
.jm-btn-outline:hover{border-color:var(--jm-blue);color:var(--jm-blue);}
.jm-btn-light{background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.35);color:#fff;}
.jm-btn-light:hover{background:rgba(255,255,255,.28);color:#fff;}

/* Sidebar */
.jm-sticky{position:sticky;top:110px;}
.jm-info-list{list-style:none;margin:0;padding:0;}
.jm-info-list li{display:flex;justify-content:space-between;gap:14px;padding:10px 0;border-bottom:1px dashed var(--jm-border);font-size:.85rem;}
.jm-info-list li:last-child{border-bottom:0;}
.jm-info-list .k{color:var(--jm-mut);white-space:nowrap;}
.jm-info-list .v{color:var(--jm-ink);font-weight:600;text-align:right;word-break:break-word;}

/* Board */
.jm-board-item{display:flex;gap:12px;align-items:flex-start;padding:12px 0;border-bottom:1px dashed var(--jm-border);}
.jm-board-item:last-child{border-bottom:0;}
.jm-board-item .jm-avatar{width:40px;height:40px;flex:0 0 40px;border-radius:12px;background:linear-gradient(135deg,#eef3ff,#dfe9ff);color:var(--jm-navy);font-weight:700;font-size:.8rem;display:flex;align-items:center;justify-content:center;overflow:hidden;}
.jm-board-item .jm-avatar img{width:100%;height:100%;object-fit:cover;}
.jm-role-badge{display:inline-block;background:#eef3ff;color:#1f4fd8;font-size:.68rem;font-weight:700;border-radius:20px;padding:2px 10px;text-transform:uppercase;letter-spacing:.04em;}

/* Abstract & citation */
.jm-abstract{font-size:.96rem;line-height:1.85;color:#33405c;}
.jm-cite-box{background:#f2f6ff;border:1px solid #d8e4ff;border-left:5px solid var(--jm-blue);border-radius:14px;padding:20px 24px;font-size:.88rem;color:#2a3a5f;}
.jm-stat{display:flex;align-items:center;gap:10px;background:#fff;border:1px solid var(--jm-border);border-radius:14px;padding:12px 16px;}
.jm-stat i{color:var(--jm-blue);font-size:1.05rem;}
.jm-stat .n{font-weight:800;color:var(--jm-ink);}
.jm-stat .l{font-size:.72rem;color:var(--jm-mut);text-transform:uppercase;letter-spacing:.05em;}

@media (max-width:767px){
  .jm-hero{padding:28px 22px;border-radius:18px;}
  .jm-card .jm-card-body{padding:20px;}
  .jm-art{padding:18px;}
}
</style>
