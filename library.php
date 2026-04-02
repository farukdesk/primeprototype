<?php
require_once __DIR__ . '/includes/config.php';

$page_title = 'Library – Prime University';

// Fetch library data (gracefully fail if tables don't exist yet)
$lib_settings     = [];
$librarians       = [];
$books            = [];
$digital          = [];
$categories       = [];
$dept_collections = [];
$facilities       = [];
$search_q     = trim($_GET['q'] ?? '');
$search_cat   = (int)($_GET['cat'] ?? 0);

try {
    $db = front_db();
    if ($db) {
        // Settings
        $rows = $db->query('SELECT setting_key, setting_val FROM library_settings')->fetchAll();
        foreach ($rows as $r) {
            $lib_settings[$r['setting_key']] = $r['setting_val'];
        }

        // Librarians
        $librarians = $db->query(
            'SELECT * FROM library_librarians WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
        )->fetchAll();

        // Featured / recent books (with search)
        $book_sql    = 'SELECT b.*, c.name AS category_name
                        FROM library_books b
                        LEFT JOIN library_categories c ON c.id = b.category_id
                        WHERE 1';
        $book_params = [];
        if ($search_q !== '') {
            $book_sql    .= ' AND (b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ?)';
            $like = '%' . $search_q . '%';
            $book_params  = array_merge($book_params, [$like, $like, $like]);
        }
        if ($search_cat > 0) {
            $book_sql    .= ' AND b.category_id = ?';
            $book_params[] = $search_cat;
        }
        $book_sql .= ' ORDER BY b.created_at DESC LIMIT 12';
        $bstmt = $db->prepare($book_sql);
        $bstmt->execute($book_params);
        $books = $bstmt->fetchAll();

        // Categories for filter
        $categories = $db->query(
            'SELECT id, name FROM library_categories WHERE parent_id IS NULL OR parent_id = 0 ORDER BY name ASC'
        )->fetchAll();

        // Public digital resources
        $digital = $db->query(
            "SELECT * FROM library_digital_resources
             WHERE is_active = 1 AND access_level = 'Public'
             ORDER BY created_at DESC LIMIT 6"
        )->fetchAll();

        // Dept. collections (v2 table — optional)
        try {
            $dept_collections = $db->query(
                'SELECT * FROM library_dept_collections WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
            )->fetchAll();
        } catch (Throwable $e2) { /* table not yet created */ }

        // Library facilities (v2 table — optional)
        try {
            $facilities = $db->query(
                'SELECT * FROM library_facilities WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
            )->fetchAll();
        } catch (Throwable $e3) { /* table not yet created */ }
    }
} catch (Throwable $e) {
    // Tables may not exist yet; silently fall through
}

$lib_name    = $lib_settings['lib_name']        ?? 'Prime University Central Library';
$lib_address = $lib_settings['lib_address']     ?? '114/116 Mazar Road, Mirpur-1, Dhaka 1216, Bangladesh';
$lib_room    = $lib_settings['lib_room']         ?? 'Level-9';
$lib_location= $lib_settings['lib_location']    ?? 'Prime University (Level-9), 114/116 Mazar Road, Mirpur-1, Dhaka 1216';
$lib_phone   = $lib_settings['lib_phone']        ?? '48038147';
$lib_cell    = $lib_settings['lib_cell']         ?? '01341933646';
// Build E.164-like tel: hrefs (BD country code +880; local numbers starting with 0 drop the leading 0)
$lib_phone_tel = '+880-2-' . ltrim($lib_phone, '0');
$lib_cell_tel  = '+880-' . ltrim($lib_cell, '0');
$lib_email   = $lib_settings['lib_email']        ?? 'library@primeuniversity.ac.bd';
$lib_hours   = $lib_settings['lib_hours']        ?? 'Sun–Thu: 8 AM–9 PM';
$lib_desc    = $lib_settings['lib_description']  ?? 'A modern academic library serving students, faculty and researchers of Prime University.';
?>
<!doctype html>
<html class="no-js" lang="en">

<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title><?= fh($page_title) ?></title>
   <meta name="description" content="Explore the <?= fh($lib_name) ?> – books, digital resources, and more.">
   <meta name="viewport" content="width=device-width, initial-scale=1">

   <link rel="shortcut icon" type="image/x-icon" href="assets/img/logo/favicon.png">

   <link rel="stylesheet" href="assets/css/bootstrap.min.css">
   <link rel="stylesheet" href="assets/css/font-awesome-pro.css">
   <link rel="stylesheet" href="assets/css/swiper-bundle.min.css">
   <link rel="stylesheet" href="assets/css/slick.css">
   <link rel="stylesheet" href="assets/css/magnific-popup.css">
   <link rel="stylesheet" href="assets/css/nice-select.css">
   <link rel="stylesheet" href="assets/css/custom-animation.css">
   <link rel="stylesheet" href="assets/css/spacing.css">
   <link rel="stylesheet" href="assets/css/main.css">
   <style>
      /* ══════════════════════════════════════════════════════
         PRIME UNIVERSITY LIBRARY — Design System
         Palette rationale:
           Ink Navy    (#12213a) — authority, scholarship, depth
           Crimson     (#b5182e) — Prime University brand energy
           Warm Cream  (#faf6f0) — paper / book warmth
           Teal Accent (#0d8a7a) — modernity, digital resources
           Amber Gold  (#d4930a) — classic academic accent
         ══════════════════════════════════════════════════ */
      :root {
         --ink:        #12213a;   /* deep navy — headings, text */
         --ink2:       #1e3155;   /* mid navy — cards, hover bg */
         --crimson:    #b5182e;   /* university red — key accents */
         --crimson-lt: #f9e8eb;   /* tint for crimson bg */
         --teal:       #0d8a7a;   /* teal — online/digital */
         --teal-lt:    #e3f5f2;   /* teal tint */
         --amber:      #d4930a;   /* amber gold — guide, arrivals */
         --amber-lt:   #fef3dc;   /* amber tint */
         --cream:      #faf6f0;   /* warm page bg */
         --white:      #ffffff;
         --border:     #e4e8ef;
         --text:       #374151;
         --muted:      #6b7280;
         --radius-lg:  16px;
         --radius-md:  10px;
         --shadow-sm:  0 2px 8px rgba(18,33,58,.08);
         --shadow-md:  0 6px 24px rgba(18,33,58,.12);
         --shadow-lg:  0 12px 40px rgba(18,33,58,.18);
      }

      body { background: var(--cream); }

      /* ── Section headings ───────────────────────────────── */
      .lib-section-title {
         font-size: 1.9rem; font-weight: 800; color: var(--ink);
         letter-spacing: -.02em; display: inline-block; margin-bottom: 6px;
      }
      .lib-section-subtitle { color: var(--muted); font-size: .95rem; margin-bottom: 0; }
      .lib-section-head { margin-bottom: 44px; }
      .lib-section-head .title-bar {
         width: 56px; height: 4px; border-radius: 2px;
         background: linear-gradient(90deg, var(--crimson), var(--amber));
         margin: 10px auto 0;
      }
      /* left-aligned variant */
      .lib-section-head.left .title-bar { margin-left: 0; }

      /* ── Breadcrumb ─────────────────────────────────────── */
      .lib-breadcrumb { display: none; }

      /* ── Hero ───────────────────────────────────────────── */
      .lib-hero {
         background:
            linear-gradient(135deg, var(--ink) 0%, var(--ink2) 50%, #1e4480 100%);
         color: #fff; padding: 88px 0 68px; position: relative; overflow: hidden;
      }
      /* Subtle diagonal stripe overlay */
      .lib-hero::before {
         content: ''; position: absolute; inset: 0; pointer-events: none;
         background: repeating-linear-gradient(
            -55deg,
            transparent,
            transparent 40px,
            rgba(255,255,255,.015) 40px,
            rgba(255,255,255,.015) 80px
         );
      }
      /* Crimson accent bar on the left edge */
      .lib-hero::after {
         content: ''; position: absolute; left: 0; top: 0; bottom: 0;
         width: 5px; background: linear-gradient(180deg, var(--crimson), var(--amber));
      }
      .lib-hero h1 {
         font-size: 2.65rem; font-weight: 800; line-height: 1.18; color: #fff;
         letter-spacing: -.02em; text-shadow: 0 2px 16px rgba(0,0,0,.35);
      }
      .lib-hero p { font-size: 1rem; color: rgba(255,255,255,.88); line-height: 1.7; }
      .lib-badge {
         background: rgba(181,24,46,.3); color: #ffb3bd; border: 1px solid rgba(181,24,46,.4);
         padding: 5px 14px; border-radius: 20px; font-size: .8rem; font-weight: 600;
         display: inline-block; margin-bottom: 14px; letter-spacing: .03em;
      }
      .lib-hero-meta { font-size: .82rem; color: rgba(255,255,255,.75); }
      .lib-hero-meta i { color: var(--amber); }

      /* ── Search box ─────────────────────────────────────── */
      .lib-search-box {
         background: rgba(255,255,255,.1); backdrop-filter: blur(8px);
         border-radius: var(--radius-lg); padding: 28px;
         border: 1px solid rgba(255,255,255,.15);
      }
      .lib-search-box input, .lib-search-box select {
         border-radius: var(--radius-md) !important; height: 48px; font-size: .93rem;
         border: 1.5px solid #dde2ea;
      }
      .lib-search-box input:focus, .lib-search-box select:focus {
         border-color: var(--teal); box-shadow: 0 0 0 3px rgba(13,138,122,.18);
      }
      .lib-search-box .btn-search {
         height: 48px; width: 100%; border: none; border-radius: var(--radius-md);
         background: linear-gradient(90deg, var(--crimson), #8c0f1f);
         color: #fff; font-weight: 700; font-size: .93rem;
         transition: filter .2s, transform .15s;
      }
      .lib-search-box .btn-search:hover { filter: brightness(1.12); transform: translateY(-1px); }

      /* ── Koha / OPAC banner ─────────────────────────────── */
      .koha-banner {
         background: var(--white);
         border-left: 5px solid var(--teal); border-radius: 0 var(--radius-lg) var(--radius-lg) 0;
         padding: 24px 32px; display: flex; flex-wrap: wrap;
         align-items: center; gap: 20px;
         box-shadow: var(--shadow-sm);
      }
      .koha-banner .koha-icon {
         width: 56px; height: 56px; border-radius: 14px; background: var(--teal-lt);
         color: var(--teal); display: flex; align-items: center; justify-content: center;
         font-size: 1.5rem; flex-shrink: 0;
      }
      .koha-banner .koha-badge {
         background: var(--teal); color: #fff; font-size: .7rem; font-weight: 700;
         padding: 3px 9px; border-radius: 10px; margin-bottom: 6px; display: inline-block;
         letter-spacing: .07em; text-transform: uppercase;
      }
      .koha-banner h5 { font-weight: 700; color: var(--ink); margin-bottom: 4px; font-size: 1rem; }
      .koha-banner p  { font-size: .85rem; color: var(--muted); margin-bottom: 0; }
      .koha-banner .koha-btn {
         background: var(--teal); color: #fff; border: none;
         padding: 10px 22px; border-radius: 8px; font-weight: 700; font-size: .85rem;
         white-space: nowrap; text-decoration: none; transition: background .2s;
         margin-left: auto; flex-shrink: 0;
      }
      .koha-banner .koha-btn:hover { background: #076358; color: #fff; text-decoration: none; }

      /* ── Collection stats strip ─────────────────────────── */
      .coll-strip {
         background: var(--ink);
         background-image: linear-gradient(135deg, var(--ink) 0%, var(--ink2) 100%);
         padding: 48px 0;
      }
      .coll-item { text-align: center; padding: 0 8px; }
      .coll-icon-wrap {
         width: 82px; height: 82px; border-radius: 50%;
         display: flex; align-items: center; justify-content: center;
         margin: 0 auto 14px; font-size: 1.9rem;
         border: 2px solid; transition: transform .25s, box-shadow .25s;
      }
      .coll-item:hover .coll-icon-wrap { transform: translateY(-6px); box-shadow: 0 8px 24px rgba(0,0,0,.35); }
      /* Each icon gets its own accent colour */
      .coll-books    .coll-icon-wrap { background: rgba(181,24,46,.18);  border-color: #b5182e; color: #ff8090; }
      .coll-ebooks   .coll-icon-wrap { background: rgba(13,138,122,.2);  border-color: #0d8a7a; color: #3ffae7; }
      .coll-ejournal .coll-icon-wrap { background: rgba(100,100,240,.2); border-color: #6464f0; color: #9e9eff; }
      .coll-magazine .coll-icon-wrap { background: rgba(212,147,10,.2);  border-color: #d4930a; color: #ffd166; }
      .coll-newspaper .coll-icon-wrap{ background: rgba(34,197,94,.15);  border-color: #22c55e; color: #6ee7a0; }

      .coll-item .coll-count { font-size: 1.8rem; font-weight: 800; color: #fff; line-height: 1; }
      .coll-item .coll-label {
         font-size: .8rem; font-weight: 600; color: rgba(255,255,255,.6);
         text-transform: uppercase; letter-spacing: .07em; margin-top: 5px;
      }

      /* ── Department grid ────────────────────────────────── */
      .dept-card {
         border-radius: var(--radius-lg); overflow: hidden; position: relative;
         height: 155px; display: flex; align-items: flex-end; cursor: pointer;
         transition: transform .28s, box-shadow .28s;
      }
      .dept-card:hover { transform: translateY(-7px); box-shadow: var(--shadow-lg); }
      .dept-card .dept-label {
         position: relative; z-index: 2; width: 100%;
         background: linear-gradient(0deg, rgba(0,0,0,.78) 0%, transparent 100%);
         padding: 16px 14px 13px; color: #fff; font-weight: 700; font-size: .95rem;
      }
      .dept-card .dept-sub { font-size: .72rem; font-weight: 400; opacity: .75; margin-top: 2px; }
      /* Top-right department icon badge */
      .dept-card .dept-icon {
         position: absolute; top: 10px; right: 12px; z-index: 3;
         width: 32px; height: 32px; border-radius: 8px;
         background: rgba(255,255,255,.18); backdrop-filter: blur(4px);
         display: flex; align-items: center; justify-content: center;
         font-size: .9rem; color: rgba(255,255,255,.9);
      }
      /* Gradient backgrounds per department */
      .dept-cse    { background: linear-gradient(150deg, #0f2a6b 0%, #1e4db7 100%); }
      .dept-eee    { background: linear-gradient(150deg, #0a3d5c 0%, #0e7cb8 100%); }
      .dept-civil  { background: linear-gradient(150deg, #0c3325 0%, #1a7a52 100%); }
      .dept-law    { background: linear-gradient(150deg, #3d0e0e 0%, #a31c1c 100%); }
      .dept-biz    { background: linear-gradient(150deg, #2e1a00 0%, #9c5f0a 100%); }
      .dept-eng    { background: linear-gradient(150deg, #0e2040 0%, #1d5490 100%); }
      .dept-bangla { background: linear-gradient(150deg, #280a3d 0%, #7b22c4 100%); }
      .dept-fash   { background: linear-gradient(150deg, #3d0a2a 0%, #c4227d 100%); }

      /* ── Facilities ─────────────────────────────────────── */
      .facility-card {
         background: var(--white); border: 1px solid var(--border); border-radius: var(--radius-lg);
         padding: 30px 20px; text-align: center; height: 100%;
         transition: box-shadow .22s, transform .22s, border-color .22s;
      }
      .facility-card:hover {
         box-shadow: var(--shadow-md); transform: translateY(-5px);
         border-color: transparent;
      }
      /* Each facility icon gets a unique colour */
      .fi-circulation  { background: var(--crimson-lt); color: var(--crimson); }
      .fi-eresource    { background: var(--teal-lt);    color: var(--teal);    }
      .fi-reading      { background: #e8f0ff;           color: #3563e9;        }
      .fi-teacher      { background: var(--amber-lt);   color: var(--amber);   }
      .fi-thesis       { background: #f0e8ff;           color: #7c3aed;        }
      .fi-wifi         { background: #e8fff3;           color: #16a34a;        }
      .facility-icon {
         width: 66px; height: 66px; border-radius: 18px;
         display: flex; align-items: center; justify-content: center;
         font-size: 1.7rem; margin: 0 auto 16px;
         transition: transform .22s;
      }
      .facility-card:hover .facility-icon { transform: scale(1.1) rotate(-3deg); }
      .facility-card h6 { font-weight: 700; color: var(--ink); font-size: .97rem; margin-bottom: 6px; }
      .facility-card p  { font-size: .8rem; color: var(--muted); margin: 0; line-height: 1.55; }

      /* ── Online services ────────────────────────────────── */
      .online-service-item {
         display: flex; align-items: center; gap: 14px; padding: 13px 16px;
         background: var(--white); border: 1.5px solid var(--border); border-radius: var(--radius-md);
         text-decoration: none; color: var(--ink);
         transition: border-color .2s, box-shadow .2s, background .2s;
      }
      .online-service-item:hover {
         border-color: var(--teal); background: var(--teal-lt);
         box-shadow: 0 4px 14px rgba(13,138,122,.15);
         color: var(--ink); text-decoration: none;
      }
      .online-service-item .os-icon {
         width: 40px; height: 40px; flex-shrink: 0; border-radius: 10px;
         display: flex; align-items: center; justify-content: center; font-size: 1rem;
      }
      /* Individual icon colours */
      .os-catalogue  { background: #e8f0ff; color: #3563e9; }
      .os-renewal    { background: var(--teal-lt);   color: var(--teal);   }
      .os-ebook      { background: #f0e8ff; color: #7c3aed; }
      .os-purchase   { background: var(--amber-lt);  color: var(--amber);  }
      .os-membership { background: var(--crimson-lt);color: var(--crimson);}
      .os-feedback   { background: #e8fff3; color: #16a34a; }
      .online-service-item span { font-weight: 600; font-size: .93rem; }

      /* ── Library service buttons ────────────────────────── */
      .service-btn {
         display: flex; align-items: center; justify-content: center; gap: 10px;
         font-weight: 700; font-size: .93rem; padding: 20px 16px; border-radius: var(--radius-md);
         text-decoration: none; text-align: center; transition: filter .2s, transform .18s;
      }
      .service-btn:hover { filter: brightness(1.1); transform: translateY(-4px); text-decoration: none; }
      .service-btn i { font-size: 1.3rem; }
      .svc-circulation { background: linear-gradient(135deg, var(--crimson), #8c0f1f); color: #fff; }
      .svc-orientation { background: linear-gradient(135deg, var(--ink2), #0e3166); color: #fff; }
      .svc-reference   { background: linear-gradient(135deg, var(--teal), #076358);  color: #fff; }
      .svc-ebook       { background: linear-gradient(135deg, #7c3aed, #4c1d95);      color: #fff; }

      /* ── New Arrivals ───────────────────────────────────── */
      .arrivals-section { background: var(--ink); }
      .arrivals-header-bar {
         background: linear-gradient(90deg, var(--crimson), var(--amber));
         color: #fff; text-align: center; font-weight: 800; font-size: .8rem;
         letter-spacing: .12em; padding: 9px 0; border-radius: 10px 10px 0 0;
         text-transform: uppercase;
      }
      .arrivals-inner {
         background: var(--white); border-radius: 0 0 var(--radius-lg) var(--radius-lg);
         overflow: hidden; box-shadow: var(--shadow-lg);
      }
      .spotlight-card { background: var(--white); padding: 28px; height: 100%; }
      .spotlight-cover {
         width: 110px; height: 165px; border-radius: 8px; flex-shrink: 0;
         background: linear-gradient(145deg, #2d1e4a, #7b22c4);
         display: flex; align-items: center; justify-content: center;
         color: rgba(255,255,255,.4); font-size: 2.5rem;
         box-shadow: 4px 6px 18px rgba(0,0,0,.25);
      }
      .spotlight-stars { color: var(--amber); font-size: .88rem; }
      .arrivals-grid-bg { background: var(--cream); padding: 24px; }
      .mini-book-wrap { text-align: center; }
      .mini-book-cover {
         width: 100%; aspect-ratio: 2/3; border-radius: 8px; overflow: hidden;
         display: flex; align-items: center; justify-content: center;
         box-shadow: 2px 4px 12px rgba(0,0,0,.2);
         font-size: 1.3rem; color: rgba(255,255,255,.5);
         transition: transform .22s;
      }
      .mini-book-cover:hover { transform: scale(1.06); }
      .mini-book-wrap .mini-label {
         font-size: .72rem; font-weight: 700; color: var(--ink);
         margin-top: 7px; display: block; letter-spacing: .02em;
      }

      /* ── User Guide ─────────────────────────────────────── */
      .guide-section { background: var(--cream); }
      .guide-btn {
         display: flex; align-items: center; gap: 12px;
         background: var(--white); color: var(--ink);
         border: 1.5px solid var(--border); border-radius: var(--radius-md);
         padding: 14px 18px; text-decoration: none; font-weight: 600; font-size: .88rem;
         transition: background .18s, border-color .18s, box-shadow .18s, transform .18s;
         box-shadow: var(--shadow-sm);
      }
      .guide-btn:hover {
         background: var(--amber); border-color: var(--amber); color: #fff;
         box-shadow: 0 6px 20px rgba(212,147,10,.3); transform: translateX(4px);
         text-decoration: none;
      }
      .guide-btn .gb-icon {
         width: 34px; height: 34px; border-radius: 8px; flex-shrink: 0;
         display: flex; align-items: center; justify-content: center; font-size: .92rem;
         background: var(--amber-lt); color: var(--amber); transition: background .18s, color .18s;
      }
      .guide-btn:hover .gb-icon { background: rgba(255,255,255,.22); color: #fff; }
      .guide-center-emblem {
         width: 170px; height: 170px; border-radius: 50%;
         background: linear-gradient(145deg, var(--ink2), var(--ink));
         border: 4px solid var(--amber); display: flex; flex-direction: column;
         align-items: center; justify-content: center; margin: 0 auto;
         color: var(--amber); font-size: 3.2rem;
         box-shadow: 0 8px 32px rgba(18,33,58,.35);
      }
      .guide-center-emblem span { font-size: .72rem; font-weight: 700; color: rgba(255,255,255,.7);
         text-transform: uppercase; letter-spacing: .1em; margin-top: 6px; }

      /* ── Gallery ────────────────────────────────────────── */
      .gallery-section { background: var(--ink); }
      .gallery-card {
         border-radius: var(--radius-lg); overflow: hidden; display: block;
         position: relative; text-decoration: none;
         transition: transform .25s; box-shadow: var(--shadow-md);
      }
      .gallery-card:hover { transform: translateY(-6px); }
      .gallery-img-wrap {
         height: 250px; overflow: hidden;
         display: flex; align-items: center; justify-content: center;
      }
      .gallery-img-wrap i { font-size: 4rem; color: rgba(255,255,255,.4); }
      .gallery-footer { padding: 14px 16px 10px; background: var(--white); }
      .gallery-footer strong { font-size: 1rem; color: var(--ink); font-weight: 800; display: block; }
      .gallery-footer small { font-size: .78rem; color: var(--muted); }

      /* ── Forms section ──────────────────────────────────── */
      .forms-section { background: var(--cream); }
      .form-doc-card { text-align: center; padding: 20px 12px; transition: transform .22s; }
      .form-doc-card:hover { transform: translateY(-6px); }
      .form-doc-icon {
         width: 88px; height: 108px; margin: 0 auto 14px; border-radius: 10px;
         position: relative; display: flex; flex-direction: column;
         align-items: center; justify-content: flex-end; padding-bottom: 16px;
         color: #fff; box-shadow: 0 6px 20px rgba(0,0,0,.2);
      }
      /* folded-corner triangle */
      .form-doc-icon::before {
         content: ''; position: absolute; top: 0; right: 0; width: 0; height: 0;
         border-style: solid; border-width: 0 24px 24px 0;
         border-color: transparent rgba(255,255,255,.22) transparent transparent;
      }
      .form-doc-icon i { font-size: 1.75rem; }
      .form-doc-student  { background: linear-gradient(145deg, #5b2fa0, #8b5cf6); }
      .form-doc-faculty  { background: linear-gradient(145deg, #0f5c9e, #3b82f6); }
      .form-doc-req      { background: linear-gradient(145deg, #065f46, #10b981); }
      .form-doc-card h6 { font-weight: 700; color: var(--ink); font-size: .88rem; line-height: 1.35; }

      /* ── Contact section ────────────────────────────────── */
      .contact-section { background: var(--white); }
      .contact-panel {
         background: var(--cream); border-radius: var(--radius-lg); padding: 32px 28px;
         height: 100%; border-top: 4px solid;
      }
      .contact-panel.cp-phone { border-color: var(--crimson); }
      .contact-panel.cp-addr  { border-color: var(--teal);    }
      .contact-panel h4 { font-weight: 800; color: var(--ink); font-size: 1.2rem; margin-bottom: 20px; }
      .contact-item { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
      .contact-item .ci-icon {
         width: 40px; height: 40px; border-radius: 10px; flex-shrink: 0;
         display: flex; align-items: center; justify-content: center; font-size: 1rem;
      }
      .ci-phone { background: var(--crimson-lt); color: var(--crimson); }
      .ci-cell  { background: var(--amber-lt);   color: var(--amber);   }
      .ci-map   { background: var(--teal-lt);    color: var(--teal);    }
      .contact-item span { font-weight: 600; color: var(--ink); font-size: .93rem; }
      .contact-item a { color: var(--ink); text-decoration: none; }
      .contact-item a:hover { color: var(--teal); }

      /* ── Librarians ──────────────────────────────────────── */
      .librarian-card {
         background: var(--white); border: 1px solid var(--border);
         border-radius: var(--radius-lg); padding: 28px 20px 22px;
         text-align: center; height: 100%;
         transition: box-shadow .22s, transform .22s, border-color .22s;
      }
      .librarian-card:hover { box-shadow: var(--shadow-md); transform: translateY(-5px); border-color: transparent; }
      .librarian-photo {
         width: 90px; height: 90px; border-radius: 50%; object-fit: cover;
         border: 3px solid var(--crimson-lt); margin: 0 auto 14px; display: block;
      }
      .librarian-avatar {
         width: 90px; height: 90px; border-radius: 50%;
         background: linear-gradient(135deg, var(--ink) 0%, var(--ink2) 100%);
         display: flex; align-items: center; justify-content: center;
         margin: 0 auto 14px; font-size: 2rem; color: rgba(255,255,255,.6);
      }
      .librarian-name { font-weight: 700; color: var(--ink); font-size: 1rem; margin-bottom: 4px; }
      .librarian-designation { font-size: .8rem; color: var(--crimson); font-weight: 600; margin-bottom: 10px; }
      .librarian-info { font-size: .8rem; color: var(--muted); line-height: 1.6; }
      .librarian-info a { color: var(--teal); text-decoration: none; }
      .librarian-info a:hover { text-decoration: underline; }

      /* ── Responsive ─────────────────────────────────────── */
      @media (max-width: 991.98px) {
         .lib-hero h1 { font-size: 2rem; }
         .lib-search-box { margin-top: 28px; }
         .koha-banner .koha-btn { margin-left: 0; }
      }
      @media (max-width: 767.98px) {
         .lib-hero { padding: 52px 0 42px; }
         .lib-hero h1 { font-size: 1.75rem; }
         .coll-icon-wrap { width: 66px; height: 66px; font-size: 1.55rem; }
         .coll-item .coll-count { font-size: 1.4rem; }
         .lib-section-title { font-size: 1.55rem; }
      }
      @media (max-width: 575.98px) {
         .lib-hero { padding: 38px 0 32px; }
         .lib-hero h1 { font-size: 1.5rem; }
         .guide-btn { transform: none !important; }
      }
   </style>
</head>

<body id="body" class="it-magic-cursor">

   <!-- preloader -->
   <div id="preloader">
      <div class="preloader"><span></span><span></span></div>
   </div>

   <div id="magic-cursor"><div id="ball"></div></div>

   <!-- back to top -->
   <button class="scroll-top scroll-to-target" data-target="html">
      <i class="far fa-angle-double-up"></i>
   </button>

   <!-- search popup -->
   <div class="search-popup">
      <button class="close-search"><span class="flaticon-multiply"><i class="fal fa-times"></i></span></button>
      <form method="post" action="#">
         <div class="form-group">
            <input type="search" name="search-field" value="" placeholder="Search Here" required="">
            <button type="submit"><i class="fal fa-search"></i></button>
         </div>
      </form>
   </div>

   <!-- offcanvas -->
   <div class="it-offcanvas-area">
      <div class="itoffcanvas">
         <div class="itoffcanvas__close-btn"><button class="close-btn"><i class="fal fa-times"></i></button></div>
         <div class="itoffcanvas__logo">
            <a href="<?= fh(SITE_URL) ?>/index.php">
               <img src="assets/img/logo/logo-black.png" alt="Prime University">
            </a>
         </div>
         <div class="it-menu-mobile d-xl-none"></div>
         <div class="itoffcanvas__info">
            <h3 class="offcanva-title">Get In Touch</h3>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon"><a href="#"><i class="fal fa-phone-alt"></i></a></div>
               <div class="itoffcanvas__info-address">
                  <span>Phone</span>
                  <a href="tel:<?= fh($lib_phone_tel) ?>"><?= fh($lib_phone) ?></a>
               </div>
            </div>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon"><a href="#"><i class="fal fa-mobile-alt"></i></a></div>
               <div class="itoffcanvas__info-address">
                  <span>Cell</span>
                  <a href="tel:<?= fh($lib_cell_tel) ?>"><?= fh($lib_cell) ?></a>
               </div>
            </div>
         </div>
      </div>
   </div>
   <div class="body-overlay"></div>

   <header class="it-header-height">
      <?php include __DIR__ . '/includes/header-top.php'; ?>
      <?php include __DIR__ . '/includes/nav-menu.php'; ?>
   </header>

   <main>

   <?php include __DIR__ . '/includes/news-ticker.php'; ?>

   <!-- Breadcrumb -->
   <div class="lib-breadcrumb">
      <div class="container">
         <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= fh(SITE_URL) ?>/index.php">Home</a></li>
            <li class="breadcrumb-item active">Library</li>
         </ol>
      </div>
   </div>

   <!-- ══════════════════════════════════════════════════
        HERO + SEARCH
   ══════════════════════════════════════════════════ -->
   <section class="lib-hero">
      <div class="container">
         <div class="row align-items-center gy-4">
            <div class="col-lg-6">
               <span class="lib-badge"><i class="fas fa-book-open me-2"></i>Prime University Library</span>
               <h1><?= fh($lib_name) ?></h1>
               <p class="mt-3 mb-4"><?= fh($lib_desc) ?></p>
               <div class="d-flex flex-wrap gap-3 lib-hero-meta">
                  <span><i class="fas fa-map-marker-alt me-2"></i><?= fh($lib_room) ?>, Mirpur-1, Dhaka</span>
                  <span><i class="fas fa-clock me-2"></i><?= fh(explode('|', $lib_hours)[0]) ?></span>
               </div>
            </div>
            <div class="col-lg-6">
               <div class="lib-search-box">
                  <h5 class="text-white mb-3" style="font-weight:700;font-size:1rem;">
                     <i class="fas fa-search me-2" style="color:#ffd166;"></i>Search the Catalogue
                  </h5>
                  <form method="GET" action="library.php">
                     <div class="row g-2">
                        <div class="col-12">
                           <input type="text" name="q" class="form-control" placeholder="Title, author, ISBN…"
                                  value="<?= fh($search_q) ?>">
                        </div>
                        <div class="col-12">
                           <select name="cat" class="form-select">
                              <option value="0">All Categories</option>
                              <?php foreach ($categories as $cat): ?>
                              <option value="<?= (int)$cat['id'] ?>" <?= $search_cat === (int)$cat['id'] ? 'selected' : '' ?>>
                                 <?= fh($cat['name']) ?>
                              </option>
                              <?php endforeach; ?>
                           </select>
                        </div>
                        <div class="col-12">
                           <button type="submit" class="btn-search">
                              <i class="fas fa-search me-2"></i>Search the Catalogue
                           </button>
                        </div>
                     </div>
                  </form>
               </div>
            </div>
         </div>
      </div>
   </section>

   <!-- ══════════════════════════════════════════════════
        KOHA OPAC INTEGRATION PLACEHOLDER
   ══════════════════════════════════════════════════ -->
   <section class="py-4" style="background:var(--cream);">
      <div class="container">
         <div class="koha-banner">
            <div class="koha-icon"><i class="fas fa-database"></i></div>
            <div class="flex-grow-1">
               <div class="koha-badge"><i class="fas fa-plug me-1"></i> Coming Soon</div>
               <h5>Koha Online Public Access Catalogue (OPAC)</h5>
               <p>The integrated Koha library management system will be available here. Members will be able to search the full catalogue, check availability, renew books, and manage reservations online.</p>
            </div>
            <a href="#" class="koha-btn" title="Koha integration — coming soon">
               <i class="fas fa-external-link-alt me-1"></i>Access Online Catalogue
            </a>
         </div>
      </div>
   </section>

   <!-- ══════════════════════════════════════════════════
        COLLECTION STATS — 5 ICONS
   ══════════════════════════════════════════════════ -->
   <div class="coll-strip">
      <div class="container">
         <div class="row g-3 justify-content-center">
            <?php
            // TODO: Replace placeholder counts with actual DB queries once Koha tables are populated
            // e.g. SELECT COUNT(*) FROM library_books, library_ebooks, library_journals, etc.
            $collections = [
               ['icon' => 'fas fa-book',         'label' => 'Books',      'count' => 1000, 'cls' => 'coll-books'],
               ['icon' => 'fas fa-tablet-alt',   'label' => 'E-Books',    'count' => 1000, 'cls' => 'coll-ebooks'],
               ['icon' => 'fas fa-newspaper',    'label' => 'E-Journal',  'count' => 1000, 'cls' => 'coll-ejournal'],
               ['icon' => 'fas fa-bookmark',     'label' => 'Magazine',   'count' => 1000, 'cls' => 'coll-magazine'],
               ['icon' => 'fas fa-scroll',       'label' => 'Newspaper',  'count' => 1000, 'cls' => 'coll-newspaper'],
            ];
            foreach ($collections as $c):
            ?>
            <div class="col-6 col-md-4 col-lg-2">
               <div class="coll-item <?= $c['cls'] ?>">
                  <div class="coll-icon-wrap"><i class="<?= $c['icon'] ?>"></i></div>
                  <div class="coll-count"><?= number_format($c['count']) ?>+</div>
                  <div class="coll-label"><?= $c['label'] ?></div>
               </div>
            </div>
            <?php endforeach; ?>
         </div>
      </div>
   </div>

   <!-- ══════════════════════════════════════════════════
        DEPARTMENT COLLECTION GRID
   ══════════════════════════════════════════════════ -->
   <section class="py-5" style="background:var(--white);">
      <div class="container">
         <div class="text-center lib-section-head">
            <h2 class="lib-section-title">Department Collections</h2>
            <p class="lib-section-subtitle">Explore books curated for each department</p>
            <div class="title-bar mx-auto"></div>
         </div>
         <div class="row g-3">
            <?php
            // Use DB data if available, otherwise fall back to defaults
            $depts_display = $dept_collections ?: [
               ['label'=>'CSE',            'sub_label'=>'Computer Science & Eng.',  'icon_class'=>'fas fa-microchip',           'color_from'=>'#0f2a6b','color_to'=>'#1e4db7','image_file'=>''],
               ['label'=>'EEE',            'sub_label'=>'Electrical & Electronic',  'icon_class'=>'fas fa-bolt',                'color_from'=>'#0a3d5c','color_to'=>'#0e7cb8','image_file'=>''],
               ['label'=>'Civil',          'sub_label'=>'Civil Engineering',         'icon_class'=>'fas fa-hard-hat',            'color_from'=>'#0c3325','color_to'=>'#1a7a52','image_file'=>''],
               ['label'=>'Law',            'sub_label'=>'Department of Law',         'icon_class'=>'fas fa-balance-scale',       'color_from'=>'#3d0e0e','color_to'=>'#a31c1c','image_file'=>''],
               ['label'=>'Business',       'sub_label'=>'Business Administration',   'icon_class'=>'fas fa-briefcase',           'color_from'=>'#2e1a00','color_to'=>'#9c5f0a','image_file'=>''],
               ['label'=>'English',        'sub_label'=>'Department of English',     'icon_class'=>'fas fa-pen-nib',             'color_from'=>'#0e2040','color_to'=>'#1d5490','image_file'=>''],
               ['label'=>'Bangla',         'sub_label'=>'Department of Bangla',      'icon_class'=>'fas fa-language',            'color_from'=>'#280a3d','color_to'=>'#7b22c4','image_file'=>''],
               ['label'=>'Fashion Design', 'sub_label'=>'Fashion & Technology',      'icon_class'=>'fas fa-tshirt',              'color_from'=>'#3d0a2a','color_to'=>'#c4227d','image_file'=>''],
            ];
            foreach ($depts_display as $d):
               $bg_style = $d['image_file']
                  ? 'background:url(' . fh(ADMIN_UPLOAD_URL) . '/library/dept-collections/' . fh($d['image_file']) . ') center/cover no-repeat'
                  : 'background:linear-gradient(150deg,' . fh($d['color_from']) . ' 0%,' . fh($d['color_to']) . ' 100%)';
            ?>
            <div class="col-6 col-md-4 col-lg-3">
               <div class="dept-card" style="<?= $bg_style ?>">
                  <div class="dept-icon"><i class="<?= fh($d['icon_class']) ?>"></i></div>
                  <div class="dept-label">
                     <div><?= fh($d['label']) ?></div>
                     <div class="dept-sub"><?= fh($d['sub_label']) ?></div>
                  </div>
               </div>
            </div>
            <?php endforeach; ?>
         </div>
      </div>
   </section>

   <!-- ══════════════════════════════════════════════════
        FACILITIES
   ══════════════════════════════════════════════════ -->
   <section class="py-5" style="background:var(--cream);">
      <div class="container">
         <div class="text-center lib-section-head">
            <h2 class="lib-section-title">Library Facilities</h2>
            <p class="lib-section-subtitle">Modern spaces designed for effective learning</p>
            <div class="title-bar mx-auto"></div>
         </div>
         <div class="row g-4">
            <?php
            // Use DB data if available, otherwise fall back to defaults
            $fac_display = $facilities ?: [
               ['icon_class'=>'fas fa-exchange-alt',      'name'=>'Circulation Area',   'description'=>'Borrow, return and renew books at the main counter.',         'icon_bg_color'=>'#f9e8eb','icon_text_color'=>'#b5182e'],
               ['icon_class'=>'fas fa-desktop',           'name'=>'E-Resource Centre',  'description'=>'Access digital databases, e-journals and online resources.',  'icon_bg_color'=>'#e3f5f2','icon_text_color'=>'#0d8a7a'],
               ['icon_class'=>'fas fa-book-reader',       'name'=>'Reading Room',        'description'=>'A quiet space dedicated to focused study and reading.',        'icon_bg_color'=>'#e8f0ff','icon_text_color'=>'#3563e9'],
               ['icon_class'=>'fas fa-chalkboard-teacher','name'=>"Teacher's Corner",   'description'=>'Reserved section with faculty reference materials.',           'icon_bg_color'=>'#fef3dc','icon_text_color'=>'#d4930a'],
               ['icon_class'=>'fas fa-graduation-cap',    'name'=>'Thesis Area',         'description'=>'Collection of student theses and research dissertations.',     'icon_bg_color'=>'#f0e8ff','icon_text_color'=>'#7c3aed'],
               ['icon_class'=>'fas fa-wifi',              'name'=>'Library Wi-Fi',        'description'=>'High-speed wireless internet throughout the library.',         'icon_bg_color'=>'#e8fff3','icon_text_color'=>'#16a34a'],
            ];
            foreach ($fac_display as $f): ?>
            <div class="col-md-4 col-sm-6">
               <div class="facility-card">
                  <div class="facility-icon" style="background:<?= fh($f['icon_bg_color']) ?>;color:<?= fh($f['icon_text_color']) ?>"><i class="<?= fh($f['icon_class']) ?>"></i></div>
                  <h6><?= fh($f['name']) ?></h6>
                  <p><?= fh($f['description']) ?></p>
               </div>
            </div>
            <?php endforeach; ?>
         </div>
      </div>
   </section>

   <!-- ══════════════════════════════════════════════════
        LIBRARIANS
   ══════════════════════════════════════════════════ -->
   <?php if ($librarians): ?>
   <section class="py-5" style="background:var(--white);">
      <div class="container">
         <div class="text-center lib-section-head">
            <h2 class="lib-section-title">Meet Our Librarians</h2>
            <p class="lib-section-subtitle">Our dedicated team is here to help you</p>
            <div class="title-bar mx-auto"></div>
         </div>
         <div class="row g-4 justify-content-center">
            <?php foreach ($librarians as $lib): ?>
            <div class="col-sm-6 col-md-4 col-lg-3">
               <div class="librarian-card">
                  <?php if ($lib['photo']): ?>
                     <img src="<?= fh(ADMIN_UPLOAD_URL) ?>/library/librarians/<?= fh($lib['photo']) ?>" alt="<?= fh($lib['name']) ?>" class="librarian-photo">
                  <?php else: ?>
                     <div class="librarian-avatar"><i class="fas fa-user-tie"></i></div>
                  <?php endif; ?>
                  <div class="librarian-name"><?= fh($lib['name']) ?></div>
                  <div class="librarian-designation"><?= fh($lib['designation']) ?></div>
                  <div class="librarian-info">
                     <?php if ($lib['email']): ?>
                        <div><i class="fas fa-envelope me-1" style="color:var(--crimson);"></i><a href="mailto:<?= fh($lib['email']) ?>"><?= fh($lib['email']) ?></a></div>
                     <?php endif; ?>
                     <?php if ($lib['phone']): ?>
                        <div><i class="fas fa-phone me-1" style="color:var(--teal);"></i><a href="tel:<?= fh($lib['phone']) ?>"><?= fh($lib['phone']) ?></a></div>
                     <?php endif; ?>
                     <?php if ($lib['room_number']): ?>
                        <div><i class="fas fa-door-open me-1" style="color:var(--amber);"></i>Room: <?= fh($lib['room_number']) ?></div>
                     <?php endif; ?>
                     <?php if ($lib['bio']): ?>
                        <p class="mt-2 mb-0" style="font-size:.78rem;"><?= fh($lib['bio']) ?></p>
                     <?php endif; ?>
                  </div>
               </div>
            </div>
            <?php endforeach; ?>
         </div>
      </div>
   </section>
   <?php endif; ?>

   <!-- ══════════════════════════════════════════════════
        ONLINE SERVICES + LIBRARY SERVICES
   ══════════════════════════════════════════════════ -->
   <section class="py-5" style="background:#fff;">
      <div class="container">
         <div class="row gy-5">

            <!-- Online Services -->
            <div class="col-lg-6">
               <div class="lib-section-head mb-4">
                  <h2 class="lib-section-title" style="font-size:1.5rem;">Online Services</h2>
                  <div class="title-bar"></div>
               </div>
               <div class="row g-3">
                  <?php
                  $online_services = [
                     ['icon'=>'fas fa-search',       'name'=>'Online Catalogue',    'href'=>'#', 'icls'=>'os-catalogue'],
                     ['icon'=>'fas fa-redo',          'name'=>'Book Renewal',        'href'=>'#', 'icls'=>'os-renewal'],
                     ['icon'=>'fas fa-tablet-alt',    'name'=>'E-Book Request',      'href'=>'#', 'icls'=>'os-ebook'],
                     ['icon'=>'fas fa-cart-plus',     'name'=>'Purchase Suggestion', 'href'=>'#', 'icls'=>'os-purchase'],
                     ['icon'=>'fas fa-id-card',       'name'=>'Membership',          'href'=>'#', 'icls'=>'os-membership'],
                     ['icon'=>'fas fa-comment-dots',  'name'=>'Feedback',            'href'=>'#', 'icls'=>'os-feedback'],
                  ];
                  foreach ($online_services as $s): ?>
                  <div class="col-12 col-sm-6">
                     <a href="<?= $s['href'] ?>" class="online-service-item">
                        <div class="os-icon <?= $s['icls'] ?>"><i class="<?= $s['icon'] ?>"></i></div>
                        <span><?= $s['name'] ?></span>
                        <i class="fas fa-chevron-right ms-auto" style="font-size:.75rem;color:#9ca3af;"></i>
                     </a>
                  </div>
                  <?php endforeach; ?>
               </div>
            </div>

            <!-- Library Services -->
            <div class="col-lg-6">
               <div class="lib-section-head mb-4">
                  <h2 class="lib-section-title" style="font-size:1.5rem;">Library Services</h2>
                  <div class="title-bar"></div>
               </div>
               <div class="row g-3">
                  <?php
                  $lib_services = [
                     ['icon'=>'fas fa-exchange-alt', 'name'=>'Circulation Service',          'cls'=>'svc-circulation'],
                     ['icon'=>'fas fa-chalkboard',   'name'=>'Library Orientation Program',  'cls'=>'svc-orientation'],
                     ['icon'=>'fas fa-book-open',    'name'=>'Reference Service',            'cls'=>'svc-reference'],
                     ['icon'=>'fas fa-tablet-alt',   'name'=>'E-Book Request',               'cls'=>'svc-ebook'],
                  ];
                  foreach ($lib_services as $sv): ?>
                  <div class="col-12 col-sm-6">
                     <a href="#" class="service-btn <?= $sv['cls'] ?>">
                        <i class="<?= $sv['icon'] ?>"></i>
                        <?= $sv['name'] ?>
                     </a>
                  </div>
                  <?php endforeach; ?>
               </div>
            </div>

         </div>
      </div>
   </section>

   <!-- ══════════════════════════════════════════════════
        NEW ARRIVALS
   ══════════════════════════════════════════════════ -->
   <section class="py-5 arrivals-section">
      <div class="container">
         <div class="text-center lib-section-head" style="margin-bottom:28px;">
            <h2 class="lib-section-title" style="color:#fff;">New Arrivals</h2>
            <p class="lib-section-subtitle" style="color:rgba(255,255,255,.6);">Latest additions to our collection</p>
            <div class="title-bar mx-auto"></div>
         </div>
         <div class="arrivals-header-bar">NEW ARRIVALS</div>
         <div class="arrivals-inner">
            <div class="row g-0">
               <!-- Spotlight book -->
               <div class="col-lg-5 col-md-6">
                  <div class="spotlight-card">
                     <div class="d-flex gap-3 align-items-start">
                        <div class="spotlight-cover flex-shrink-0">
                           <i class="fas fa-book"></i>
                        </div>
                        <div>
                           <h5 style="font-weight:800;color:var(--ink);font-size:1.05rem;line-height:1.35;">
                              কেতা নদী সেরাবর বা বাঙলা ভাষার জীবনী
                           </h5>
                           <p style="font-size:.85rem;font-weight:600;color:var(--muted);margin-bottom:6px;">হুমায়ুন আজাদ</p>
                           <div class="spotlight-stars mb-2">
                              <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i>
                              <i class="far fa-star"></i><i class="far fa-star"></i>
                           </div>
                           <p style="font-size:.8rem;color:var(--muted);line-height:1.65;">
                              হাজার বছর আগে প্রাচীন ভারতীয় আর্যভাষা রূপান্তরিত হয়ে বঙ্গীয় অঞ্চলে জন্ম নিয়েছিলো এক মধুর – কোমলবিচিত্র প্রাকৃত। তার নাম বাঙলা।
                           </p>
                           <a href="#" style="font-size:.82rem;color:var(--teal);font-weight:700;">See More →</a>
                        </div>
                     </div>
                  </div>
               </div>
               <!-- Mini book grid -->
               <div class="col-lg-7 col-md-6 arrivals-grid-bg">
                  <div class="row g-2 h-100 align-items-center">
                     <?php
                     $mini_books = [
                        ['label'=>'Marketing',        'bg'=>'linear-gradient(145deg,#6b0f0f,#c0392b)'],
                        ['label'=>'Probability',      'bg'=>'linear-gradient(145deg,#0f2a5c,#2563eb)'],
                        ['label'=>'Modern Physics',   'bg'=>'linear-gradient(145deg,#0a3325,#16a34a)'],
                        ['label'=>'C Programming',    'bg'=>'linear-gradient(145deg,#2a0a5c,#7c3aed)'],
                        ['label'=>'Language Teaching','bg'=>'linear-gradient(145deg,#5c3a0a,#d97706)'],
                     ];
                     foreach ($mini_books as $mb): ?>
                     <div class="col" style="min-width:0;">
                        <div class="mini-book-wrap">
                           <div class="mini-book-cover" style="background:<?= $mb['bg'] ?>;min-height:90px;">
                              <i class="fas fa-book"></i>
                           </div>
                           <span class="mini-label"><?= $mb['label'] ?></span>
                        </div>
                     </div>
                     <?php endforeach; ?>
                  </div>
               </div>
            </div>
         </div>
      </div>
   </section>

   <!-- ══════════════════════════════════════════════════
        USER GUIDE
   ══════════════════════════════════════════════════ -->
   <section class="py-5 guide-section">
      <div class="container">
         <div class="text-center lib-section-head">
            <h2 class="lib-section-title">User Guide</h2>
            <p class="lib-section-subtitle">Policies, rules, and helpful resources</p>
            <div class="title-bar mx-auto"></div>
         </div>
         <div class="row g-3 justify-content-center align-items-center">
            <div class="col-lg-4">
               <div class="row g-3">
                  <?php
                  $guide_left = [
                     ['icon'=>'fas fa-sitemap',   'name'=>'Library Flowchart'],
                     ['icon'=>'fas fa-book',      'name'=>'Library Brochure'],
                     ['icon'=>'fas fa-id-card',   'name'=>'Membership Rules'],
                     ['icon'=>'fas fa-door-open', 'name'=>'Entrance Rules'],
                  ];
                  foreach ($guide_left as $g): ?>
                  <div class="col-12">
                     <a href="#" class="guide-btn">
                        <div class="gb-icon"><i class="<?= $g['icon'] ?>"></i></div>
                        <?= $g['name'] ?>
                        <i class="fas fa-file-pdf ms-auto" style="font-size:.75rem;opacity:.5;"></i>
                     </a>
                  </div>
                  <?php endforeach; ?>
               </div>
            </div>
            <div class="col-lg-4 text-center d-none d-lg-block">
               <div class="guide-center-emblem">
                  <i class="fas fa-book-open"></i>
                  <span>User Guide</span>
               </div>
            </div>
            <div class="col-lg-4">
               <div class="row g-3">
                  <?php
                  $guide_right = [
                     ['icon'=>'fas fa-exchange-alt', 'name'=>'Borrowing Rules'],
                     ['icon'=>'fas fa-book-reader',  'name'=>'Reading Room Rules'],
                     ['icon'=>'fas fa-gavel',        'name'=>'Fine Policy'],
                     ['icon'=>'fas fa-balance-scale','name'=>'Library Policy'],
                  ];
                  foreach ($guide_right as $g): ?>
                  <div class="col-12">
                     <a href="#" class="guide-btn">
                        <div class="gb-icon"><i class="<?= $g['icon'] ?>"></i></div>
                        <?= $g['name'] ?>
                        <i class="fas fa-file-pdf ms-auto" style="font-size:.75rem;opacity:.5;"></i>
                     </a>
                  </div>
                  <?php endforeach; ?>
               </div>
            </div>
         </div>
      </div>
   </section>

   <!-- ══════════════════════════════════════════════════
        PHOTO & VIDEO GALLERY
   ══════════════════════════════════════════════════ -->
   <section class="py-5 gallery-section">
      <div class="container">
         <div class="text-center lib-section-head" style="margin-bottom:32px;">
            <h2 class="lib-section-title" style="color:#fff;">PHOTO &amp; VIDEO Gallery</h2>
            <div class="title-bar mx-auto"></div>
         </div>
         <div class="row g-4">
            <div class="col-md-6">
               <a href="#" class="gallery-card">
                  <div class="gallery-img-wrap" style="background:linear-gradient(135deg,#1a2744 0%,#b5182e 100%);">
                     <i class="fas fa-images"></i>
                  </div>
                  <div class="gallery-footer">
                     <strong>Photos</strong>
                     <small>Browse library photo gallery</small>
                  </div>
               </a>
            </div>
            <div class="col-md-6">
               <a href="#" class="gallery-card" target="_blank" title="YouTube Channel">
                  <div class="gallery-img-wrap" style="background:linear-gradient(135deg,#1a0a0a 0%,#ff0000 100%);">
                     <i class="fab fa-youtube"></i>
                  </div>
                  <div class="gallery-footer">
                     <strong>Videos</strong>
                     <small>(youtube channel link)</small>
                  </div>
               </a>
            </div>
         </div>
      </div>
   </section>

   <!-- ══════════════════════════════════════════════════
        FORMS
   ══════════════════════════════════════════════════ -->
   <section class="py-5 forms-section">
      <div class="container">
         <div class="text-center lib-section-head">
            <h2 class="lib-section-title">Forms</h2>
            <div class="title-bar mx-auto"></div>
         </div>
         <div class="row justify-content-center g-4">
            <?php
            $forms = [
               ['name'=>'Membership Form (Student)',           'icon'=>'fas fa-list-ul', 'href'=>'#', 'icls'=>'form-doc-student'],
               ['name'=>'Membership Form (Faculty/Executive)', 'icon'=>'fas fa-list-ul', 'href'=>'#', 'icls'=>'form-doc-faculty'],
               ['name'=>'Book Requisition Form',               'icon'=>'fas fa-list-ul', 'href'=>'#', 'icls'=>'form-doc-req'],
            ];
            foreach ($forms as $f): ?>
            <div class="col-6 col-md-4 col-lg-3">
               <a href="<?= $f['href'] ?>" class="form-doc-card d-block text-decoration-none">
                  <div class="form-doc-icon <?= $f['icls'] ?> mx-auto">
                     <i class="<?= $f['icon'] ?>"></i>
                  </div>
                  <h6><?= $f['name'] ?></h6>
               </a>
            </div>
            <?php endforeach; ?>
         </div>
      </div>
   </section>

   <!-- ══════════════════════════════════════════════════
        CONTACT
   ══════════════════════════════════════════════════ -->
   <section class="py-5 contact-section">
      <div class="container">
         <div class="text-center lib-section-head">
            <h2 class="lib-section-title">Contact Us</h2>
            <div class="title-bar mx-auto"></div>
         </div>
         <div class="row g-4 justify-content-center">
            <div class="col-md-5">
               <div class="contact-panel cp-phone">
                  <h4><i class="fas fa-phone-volume me-2"></i>Contact Number</h4>
                  <div class="contact-item">
                     <div class="ci-icon ci-phone"><i class="fas fa-phone"></i></div>
                     <div>
                        <div style="font-size:.75rem;color:var(--muted);font-weight:500;">Phone</div>
                        <span><a href="tel:<?= fh($lib_phone_tel) ?>"><?= fh($lib_phone) ?></a></span>
                     </div>
                  </div>
                  <div class="contact-item">
                     <div class="ci-icon ci-cell"><i class="fas fa-mobile-alt"></i></div>
                     <div>
                        <div style="font-size:.75rem;color:var(--muted);font-weight:500;">Cell</div>
                        <span><a href="tel:<?= fh($lib_cell_tel) ?>"><?= fh($lib_cell) ?></a></span>
                     </div>
                  </div>
               </div>
            </div>
            <div class="col-md-5">
               <div class="contact-panel cp-addr">
                  <h4><i class="fas fa-map-marked-alt me-2"></i>Contact Address</h4>
                  <div class="contact-item align-items-start">
                     <div class="ci-icon ci-map" style="margin-top:2px;"><i class="fas fa-map-marker-alt"></i></div>
                     <div>
                        <div style="font-size:.75rem;color:var(--muted);font-weight:500;margin-bottom:4px;">Address</div>
                        <span style="font-size:.93rem;line-height:1.7;">
                           Prime University (Level-9)<br>
                           114/116 Mazar Road, Mirpur-1<br>
                           Dhaka 1216, Bangladesh
                        </span>
                     </div>
                  </div>
               </div>
            </div>
         </div>
      </div>
   </section>

   </main>

   <footer>
   <section class="it-footer-wrap it-footer-style-2 fix">
      <div class="it-footer-area z-index-1 pt-120 pb-80" data-background="assets/img/shape/footer-bg-3-1.jpg">
         <img class="it-footer-shape-1 d-none d-xxl-block" src="assets/img/shape/footer-3-1.png" alt="">
         <img class="it-footer-shape-2" data-parallax='{"y": -200, "smoothness": 30}' src="assets/img/shape/footer-3-2.png" alt="">
         <div class="it-footer-border"><span></span></div>
         <div class="container">
            <div class="row">
               <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 mb-50">
                  <div class="it-footer-widget">
                     <div class="it-footer-widget-logo mb-30">
                        <a href="<?= fh(SITE_URL) ?>/index.php"><img src="assets/img/logo/logo-black.png" alt="Prime University"></a>
                     </div>
                     <div class="it-footer-widget-text">
                        <p><?= fh($lib_name) ?> – serving students, faculty and researchers.</p>
                     </div>
                  </div>
               </div>
               <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 mb-50">
                  <div class="it-footer-widget">
                     <h4 class="it-footer-widget-title">Library</h4>
                     <div class="it-footer-widget-menu">
                        <ul>
                           <li><a href="library.php">Home</a></li>
                           <li><a href="library.php?q=">Book Catalogue</a></li>
                        </ul>
                     </div>
                  </div>
               </div>
               <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 mb-50">
                  <div class="it-footer-widget">
                     <h4 class="it-footer-widget-title">Useful Links</h4>
                     <div class="it-footer-widget-menu">
                        <ul>
                           <li><a href="<?= fh(SITE_URL) ?>/index.php">Home</a></li>
                           <li><a href="contact-us.html">Contact Us</a></li>
                        </ul>
                     </div>
                  </div>
               </div>
               <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 mb-50">
                  <div class="it-footer-widget d-flex justify-content-lg-end">
                     <div>
                        <h4 class="it-footer-widget-title">Contact Library</h4>
                        <div class="it-footer-widget-contact mb-25">
                           <ul>
                              <li><span>Phone:</span><a href="tel:<?= fh($lib_phone_tel) ?>"><?= fh($lib_phone) ?></a></li>
                              <li><span>Cell:</span><a href="tel:<?= fh($lib_cell_tel) ?>"><?= fh($lib_cell) ?></a></li>
                              <li><span>Room:</span><?= fh($lib_room) ?></li>
                           </ul>
                        </div>
                     </div>
                  </div>
               </div>
            </div>
         </div>
      </div>
      <div class="it-copyright-area it-copyright-ptb it-copyright-bg z-index-1 theme-bg">
         <div class="container">
            <div class="row align-items-center">
               <div class="col-12">
                  <div class="it-copyright-left style-2 text-center">
                     <p class="mb-0">Copyright &copy; <?= date('Y') ?> <a href="#">Prime University</a> All Rights Reserved</p>
                  </div>
               </div>
            </div>
         </div>
      </div>
   </section>
   </footer>

   <!-- JS Libraries -->
   <script src="assets/js/jquery.js"></script>
   <script src="assets/js/bootstrap.bundle.min.js"></script>
   <script src="assets/js/purecounter.js"></script>
   <script src="assets/js/nice-select.js"></script>
   <script src="assets/js/swiper-bundle.min.js"></script>
   <script src="assets/js/slick.min.js"></script>
   <script src="assets/js/wow.js"></script>
   <script src="assets/js/magnific-popup.js"></script>
   <script src="assets/js/parallax.js"></script>
   <script src="assets/js/main.js"></script>
</body>
</html>
