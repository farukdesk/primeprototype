<?php
require_once __DIR__ . '/includes/config.php';

// ── Fetch settings & active fee structures ────────────────────────────────────
$db = front_db();

$settings = [];
$programs = [];

if ($db) {
    try {
        $settings = $db->query('SELECT * FROM cf_settings WHERE id = 1')->fetch() ?: [];

        if (!($settings['is_published'] ?? 1)) {
            // Page not published – show a coming-soon message instead of a 404
            $programs = []; // empty programs will trigger the "not yet configured" message
        }

        $programs = $db->query(
            "SELECT p.*,
                    d.name           AS dept_name,
                    ap.program_name
             FROM cf_programs p
             LEFT JOIN dept_departments     d  ON d.id  = p.dept_id
             LEFT JOIN dept_academic_programs ap ON ap.id = p.program_id
             WHERE p.is_active = 1
             ORDER BY p.sort_order, d.name, ap.program_name, p.id"
        )->fetchAll();

        // Fixed fees per program
        if ($programs) {
            $ids   = implode(',', array_map(fn($r) => (int)$r['id'], $programs));
            $fees  = $db->query(
                "SELECT * FROM cf_fixed_fees WHERE cf_program_id IN ($ids) ORDER BY sort_order, id"
            )->fetchAll();

            // Index by program id
            $fees_by_prog = [];
            foreach ($fees as $f) {
                $fees_by_prog[$f['cf_program_id']][] = $f;
            }
        }
    } catch (Throwable $e) {
        // fall through silently
    }
}

$page_title = ($settings['page_title'] ?? 'Course Fees Calculator') . ' – Prime University';
$currency   = $settings['currency'] ?? 'BDT';

// Build JS-safe programme data
$js_programs = [];
foreach ($programs as $p) {
    $fixed = $fees_by_prog[$p['id']] ?? [];
    $js_programs[] = [
        'id'            => (int)$p['id'],
        'label'         => ($p['program_name'] ?: ($p['dept_name'] ?: 'Programme #' . $p['id'])),
        'dept'          => $p['dept_name'] ?? '',
        'degree'        => $p['degree_type'],
        'total_credits' => $p['total_credits'] ? (float)$p['total_credits'] : null,
        'duration'      => $p['duration_years'] ? (float)$p['duration_years'] : null,
        'num_semesters' => $p['num_semesters'] ? (int)$p['num_semesters'] : null,
        'fixed_fees'    => array_map(fn($f) => [
            'name'   => $f['fee_name'],
            'amount' => (int)$f['amount'],
            'type'   => $f['fee_type'],
        ], $fixed),
    ];
}
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title><?= fh($page_title) ?></title>
   <meta name="description" content="<?= fh($settings['page_subtitle'] ?? 'Estimate your tuition and fees at Prime University instantly.') ?>">
   <meta name="viewport" content="width=device-width, initial-scale=1">
   <link rel="shortcut icon" type="image/x-icon" href="/assets/img/logo/favicon.png">

   <!-- CSS Libraries -->
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
   /* ===== COURSE FEES CALCULATOR – CUSTOM STYLES ===== */
   :root {
      --pu-navy:    #1a2e5a;
      --pu-blue:    #2563eb;
      --pu-gold:    #f59e0b;
      --pu-green:   #059669;
      --pu-purple:  #7c3aed;
      --pu-teal:    #0891b2;
   }

   /* ── Hero ─────────────────────────────────────────────────────── */
   .cf-hero {
      background: linear-gradient(135deg, var(--pu-navy) 0%, #0f1f40 55%, #1e3a6e 100%);
      padding: 90px 0 80px;
      position: relative;
      overflow: hidden;
   }
   .cf-hero::before {
      content: '';
      position: absolute;
      inset: 0;
      background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
   }
   .cf-hero-blob {
      position: absolute;
      border-radius: 50%;
      filter: blur(60px);
      opacity: .15;
      animation: blobDrift 12s ease-in-out infinite alternate;
   }
   .cf-hero-blob-1 { width:400px;height:400px;background:var(--pu-blue);top:-120px;right:-100px; }
   .cf-hero-blob-2 { width:300px;height:300px;background:var(--pu-gold);bottom:-80px;left:-60px;animation-delay:-5s; }
   @keyframes blobDrift { from { transform: translate(0,0) scale(1); } to { transform: translate(30px,20px) scale(1.07); } }

   .cf-hero-title {
      font-size: clamp(2rem, 5vw, 3.2rem);
      font-weight: 900;
      color: #fff;
      line-height: 1.15;
   }
   .cf-hero-title span { color: var(--pu-gold); }
   .cf-hero-sub { color: rgba(255,255,255,.72); font-size: 1.05rem; max-width: 580px; line-height: 1.7; }

   .cf-hero-stat {
      background: rgba(255,255,255,.08);
      border: 1px solid rgba(255,255,255,.12);
      border-radius: 12px;
      padding: 16px 20px;
      text-align: center;
      backdrop-filter: blur(8px);
      transition: transform .25s, background .25s;
   }
   .cf-hero-stat:hover { transform: translateY(-4px); background: rgba(255,255,255,.13); }
   .cf-hero-stat-val { font-size: 1.75rem; font-weight: 900; color: var(--pu-gold); line-height: 1; }
   .cf-hero-stat-lbl { font-size: .78rem; color: rgba(255,255,255,.65); margin-top: 4px; }

   /* ── How It Works ─────────────────────────────────────────────── */
   .cf-step-card {
      border-radius: 16px;
      padding: 28px 24px;
      background: #fff;
      border: 1.5px solid #e5e7eb;
      position: relative;
      transition: transform .25s, box-shadow .25s;
   }
   .cf-step-card:hover { transform: translateY(-6px); box-shadow: 0 14px 40px rgba(26,46,90,.1); }
   .cf-step-num {
      width: 52px; height: 52px;
      border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.35rem; font-weight: 900;
      margin-bottom: 14px;
   }

   /* ── Calculator Card ──────────────────────────────────────────── */
   .cf-card {
      border-radius: 20px;
      border: none;
      box-shadow: 0 8px 48px rgba(26,46,90,.13);
      overflow: hidden;
   }
   .cf-card-header {
      background: linear-gradient(135deg, var(--pu-navy), #2563eb);
      color: #fff;
      padding: 28px 32px;
   }
   .cf-card-body { padding: 32px; }

   .cf-select-wrap { position: relative; }
   .cf-select-wrap select {
      appearance: none;
      -webkit-appearance: none;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' fill='none'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%231a2e5a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 16px center;
      padding-right: 40px;
      border: 2px solid #e2e8f0;
      border-radius: 12px;
      font-size: 1rem;
      padding: 14px 40px 14px 16px;
      transition: border-color .2s, box-shadow .2s;
      width: 100%;
      cursor: pointer;
   }
   .cf-select-wrap select:focus {
      outline: none;
      border-color: var(--pu-blue);
      box-shadow: 0 0 0 4px rgba(37,99,235,.12);
   }

   .cf-slider-wrap {
      padding: 8px 0;
   }
   .cf-range {
      -webkit-appearance: none;
      appearance: none;
      width: 100%;
      height: 6px;
      border-radius: 3px;
      background: #e2e8f0;
      outline: none;
      cursor: pointer;
      transition: background .2s;
   }
   .cf-range::-webkit-slider-thumb {
      -webkit-appearance: none;
      appearance: none;
      width: 22px; height: 22px;
      border-radius: 50%;
      background: var(--pu-blue);
      border: 3px solid #fff;
      box-shadow: 0 2px 8px rgba(37,99,235,.4);
      cursor: grab;
      transition: transform .15s;
   }
   .cf-range::-webkit-slider-thumb:active { cursor: grabbing; transform: scale(1.2); }
   .cf-range::-moz-range-thumb {
      width: 22px; height: 22px;
      border-radius: 50%;
      background: var(--pu-blue);
      border: 3px solid #fff;
      box-shadow: 0 2px 8px rgba(37,99,235,.4);
      cursor: grab;
   }
   .cf-credit-display {
      font-size: 2.5rem;
      font-weight: 900;
      color: var(--pu-navy);
      line-height: 1;
   }
   .cf-credit-unit { font-size: .9rem; font-weight: 600; color: #6b7280; margin-left: 4px; }

    /* Results panel */
   .cf-result-panel {
      background: linear-gradient(160deg, #f0f7ff 0%, #ffffff 100%);
      border-radius: 16px;
      border: 1.5px solid #bfdbfe;
      padding: 28px;
      position: sticky;
      top: 100px;
   }
   .cf-result-row {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      padding: 8px 0;
      border-bottom: 1px solid #e5e7eb;
      font-size: .92rem;
      gap: 12px;
   }
   .cf-result-row:last-child { border-bottom: none; }
   .cf-result-val { font-weight: 700; color: var(--pu-navy); white-space: nowrap; }
   .cf-result-val.discount { color: var(--pu-green); }

   /* Count-up animated number */
   .cf-amount-big {
      font-size: clamp(2rem, 5vw, 3rem);
      font-weight: 900;
      color: var(--pu-blue);
      line-height: 1;
      transition: color .3s;
   }
   .cf-amount-currency { font-size: 1rem; font-weight: 700; color: #6b7280; vertical-align: top; margin-top: 6px; display: inline-block; }

   /* Progress indicator */
   .cf-steps-indicator {
      display: flex;
      gap: 8px;
      margin-bottom: 28px;
   }
   .cf-step-dot {
      flex: 1;
      height: 4px;
      border-radius: 2px;
      background: #e2e8f0;
      transition: background .35s;
   }
   .cf-step-dot.done { background: var(--pu-blue); }

   /* CTA section */
   .cf-cta {
      background: linear-gradient(135deg, #1a2e5a, #2563eb);
      border-radius: 20px;
      padding: 52px 40px;
      color: #fff;
      text-align: center;
   }
   .cf-cta h2 { font-size: clamp(1.6rem, 4vw, 2.4rem); font-weight: 900; margin-bottom: 14px; }
   .cf-cta-btn {
      background: var(--pu-gold);
      color: var(--pu-navy);
      font-weight: 800;
      border: none;
      padding: 14px 36px;
      border-radius: 50px;
      font-size: 1rem;
      text-decoration: none;
      display: inline-block;
      transition: transform .2s, box-shadow .2s;
   }
   .cf-cta-btn:hover { transform: translateY(-3px); box-shadow: 0 10px 30px rgba(245,158,11,.4); color: var(--pu-navy); }

   /* Note box */
   .cf-note {
      background: #fffbeb;
      border: 1.5px solid #fde68a;
      border-radius: 12px;
      padding: 18px 22px;
      font-size: .88rem;
      color: #78350f;
      line-height: 1.7;
   }

   /* Animations */
   @keyframes fadeInUp {
      from { opacity: 0; transform: translateY(24px); }
      to   { opacity: 1; transform: translateY(0); }
   }
   .fade-in-up { animation: fadeInUp .55s ease both; }
   .delay-1 { animation-delay: .1s; }
   .delay-2 { animation-delay: .22s; }
   .delay-3 { animation-delay: .34s; }
   .delay-4 { animation-delay: .46s; }

   @keyframes pulseGlow {
      0%, 100% { box-shadow: 0 0 0 0 rgba(37,99,235,.25); }
      50%       { box-shadow: 0 0 0 10px rgba(37,99,235,0); }
   }
   .cf-result-panel.recalculating { animation: pulseGlow .6s ease; }

   /* ── Responsive ────────────────────────────────────────────────── */
   @media (max-width: 767px) {
      .cf-hero { padding: 60px 0 50px; }
      .cf-card-body { padding: 20px; }
      .cf-result-panel { position: static; }
   }

   /* ── Sticky selector (desktop) ────────────────────────────── */
   @media (min-width: 992px) {
      .cf-card { position: sticky; top: 88px; }
   }

   /* ── Search Input ─────────────────────────────────────────── */
   .cf-search-wrap { position: relative; }
   .cf-search-icon {
      position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
      color: #9ca3af; pointer-events: none; font-size: .88rem;
   }
   .cf-search-input {
      width: 100%; border: 2px solid #e2e8f0; border-radius: 12px;
      padding: 12px 40px 12px 40px; font-size: .95rem;
      transition: border-color .2s, box-shadow .2s; background: #fff; color: #1a2e5a;
   }
   .cf-search-input::placeholder { color: #9ca3af; }
   .cf-search-input:focus { outline: none; border-color: var(--pu-blue); box-shadow: 0 0 0 4px rgba(37,99,235,.12); }
   .cf-search-clear {
      position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
      background: none; border: none; color: #9ca3af; cursor: pointer; padding: 4px 6px; border-radius: 6px; transition: color .2s;
   }
   .cf-search-clear:hover { color: var(--pu-navy); }

   /* ── Degree Filter Pills ──────────────────────────────────── */
   .cf-degree-filters { display: flex; gap: 6px; flex-wrap: wrap; }
   .cf-deg-pill {
      padding: 5px 12px; border-radius: 50px; border: 1.5px solid #e2e8f0;
      background: #fff; color: #6b7280; font-size: .78rem; font-weight: 600;
      cursor: pointer; transition: all .2s; white-space: nowrap;
   }
   .cf-deg-pill:hover { border-color: var(--pu-blue); color: var(--pu-blue); }
   .cf-deg-pill.active { background: var(--pu-blue); border-color: var(--pu-blue); color: #fff; }

   /* ── Step badge ───────────────────────────────────────────── */
   .cf-step-badge {
      display: inline-flex; align-items: center; justify-content: center;
      width: 22px; height: 22px; border-radius: 50%;
      font-size: .7rem; font-weight: 900; color: #fff; margin-right: 6px;
   }

   /* ── Tip box ──────────────────────────────────────────────── */
   .cf-tip-box {
      background: #fffbeb; border: 1.5px solid #fde68a;
      border-radius: 10px; padding: 12px 16px; font-size: .82rem; color: #78350f; line-height: 1.6;
   }

   /* ── Result Sections ──────────────────────────────────────── */
   .cf-result-section {
      border-radius: 14px; overflow: hidden;
      margin-bottom: 10px; border: 1.5px solid #e5e7eb; transition: box-shadow .2s;
   }
   .cf-result-section:hover { box-shadow: 0 4px 16px rgba(26,46,90,.07); }
   .cf-section-header {
      display: flex; align-items: center; gap: 10px;
      padding: 12px 18px; font-weight: 800; font-size: .88rem; color: #fff;
   }
   .cf-sec-note { margin-left: auto; font-size: .75rem; font-weight: 500; opacity: .8; }
   .cf-sec-overview  { background: linear-gradient(135deg, #1a2e5a 0%, #2563eb 100%); }
   .cf-sec-persem    { background: linear-gradient(135deg, #065f46 0%, #059669 100%); }
   .cf-sec-admission { background: linear-gradient(135deg, #92400e 0%, #d97706 100%); }
   .cf-sec-monthly   { background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); }
   .cf-sec-safety    { background: linear-gradient(135deg, #4c1d95 0%, #7c3aed 100%); }
   .cf-section-body { padding: 14px 18px; }
   .cf-section-row {
      display: flex; justify-content: space-between; align-items: flex-start;
      padding: 7px 0; border-bottom: 1px solid #f3f4f6; font-size: .88rem; gap: 12px;
   }
   .cf-section-row:last-child { border-bottom: none; }
   .cf-section-row-val { font-weight: 700; color: var(--pu-navy); white-space: nowrap; }
   .cf-section-total {
      margin-top: 10px; padding-top: 10px; border-top: 2px solid #e5e7eb;
      display: flex; justify-content: space-between; align-items: center;
      font-weight: 800; font-size: .95rem; color: var(--pu-navy);
   }

   /* ── Program Overview Stats ───────────────────────────────── */
   .cf-ov-item {
      background: #f8fafc; border-radius: 10px; padding: 10px 8px; text-align: center;
   }
   .cf-ov-label {
      font-size: .68rem; color: #6b7280; font-weight: 600;
      text-transform: uppercase; letter-spacing: .04em; margin-bottom: 4px;
   }
   .cf-ov-val { font-size: .95rem; font-weight: 800; color: var(--pu-navy); }

   /* ── 1st Semester Monthly Highlight ──────────────────────── */
   .cf-monthly-highlight {
      background: #eff6ff; border: 1.5px solid #bfdbfe;
      border-radius: 10px; padding: 14px 16px; text-align: center; margin-top: 10px;
   }
   .cf-monthly-big { font-size: 1.55rem; font-weight: 900; color: var(--pu-blue); line-height: 1.1; }
   .cf-monthly-note { font-size: .78rem; color: #6b7280; margin-top: 4px; }

   /* ── Forecast Toggle & Content ────────────────────────────── */
   .cf-forecast-toggle {
      width: 100%; display: flex; align-items: center; gap: 10px;
      padding: 14px 18px;
      background: linear-gradient(135deg, #0f172a 0%, #1a2e5a 100%);
      border: none; color: #fff; font-weight: 800; font-size: .88rem;
      cursor: pointer; transition: opacity .2s;
   }
   .cf-forecast-toggle:hover { opacity: .92; }
   .cf-forecast-toggle .cf-chevron { color: var(--pu-gold); transition: transform .3s; }
   .cf-forecast-toggle.open .cf-chevron { transform: rotate(180deg); }
   .cf-forecast-year-row {
      display: flex; justify-content: space-between; align-items: center;
      padding: 9px 0; border-bottom: 1px dashed #e5e7eb; font-size: .88rem;
   }
   .cf-forecast-year-row:last-child { border-bottom: none; }
   .cf-forecast-year-label { color: #374151; font-weight: 600; }
   .cf-forecast-year-amt   { color: var(--pu-navy); font-weight: 800; }
   .cf-forecast-grand {
      margin-top: 12px; padding: 14px 16px;
      background: linear-gradient(135deg, #1a2e5a, #2563eb);
      border-radius: 10px; color: #fff;
      display: flex; justify-content: space-between; align-items: center;
   }
   .cf-forecast-grand-label { font-size: .78rem; opacity: .8; font-weight: 600; letter-spacing: .04em; }
   .cf-forecast-grand-amt   { font-size: 1.35rem; font-weight: 900; color: var(--pu-gold); line-height: 1; }

   /* ── Waiver Buttons ───────────────────────────────────────── */
   .cf-waiver-options { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
   .cf-waiver-btn {
      padding: 9px 4px; border-radius: 10px; border: 2px solid #e2e8f0;
      background: #fff; font-size: .82rem; font-weight: 800; color: var(--pu-navy);
      cursor: pointer; text-align: center; transition: all .2s; line-height: 1.2;
   }
   .cf-waiver-btn small { font-size: .68rem; font-weight: 500; color: #6b7280; display: block; margin-top: 2px; }
   .cf-waiver-btn:hover  { border-color: #7c3aed; color: #7c3aed; background: #faf5ff; }
   .cf-waiver-btn.active { background: #7c3aed; border-color: #7c3aed; color: #fff; }
   .cf-waiver-btn.active small { color: rgba(255,255,255,.8); }

   /* ── Waiver Summary Box ───────────────────────────────────── */
   .cf-waiver-summary-box {
      background: #f0fdf4; border: 1.5px solid #bbf7d0; border-radius: 10px; padding: 12px 16px;
   }
   .cf-ws-row {
      display: flex; justify-content: space-between; align-items: center;
      padding: 6px 0; font-size: .88rem; border-bottom: 1px solid #d1fae5;
   }
   .cf-ws-row:last-child { border-bottom: none; }
   .cf-ws-total { font-weight: 800; font-size: .95rem; color: var(--pu-navy); }

   /* ── Mobile Sticky Bar ────────────────────────────────────── */
   .cf-sticky-bar {
      position: fixed; bottom: 0; left: 0; right: 0; background: var(--pu-navy); color: #fff;
      padding: 12px 20px; z-index: 1099; display: none;
      align-items: center; justify-content: space-between; gap: 12px;
      box-shadow: 0 -4px 20px rgba(0,0,0,.25); transform: translateY(100%); transition: transform .35s ease;
   }
   .cf-sticky-bar.show { display: flex; transform: translateY(0); }
   .cf-sticky-bar-prog  { font-size: .78rem; opacity: .7; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 160px; }
   .cf-sticky-bar-total { font-size: 1rem; font-weight: 900; color: var(--pu-gold); }
   .cf-sticky-bar-scroll {
      flex-shrink: 0; background: var(--pu-gold); color: var(--pu-navy);
      border: none; border-radius: 8px; padding: 8px 14px;
      font-size: .8rem; font-weight: 800; cursor: pointer; white-space: nowrap;
   }

   /* ── FAQ ──────────────────────────────────────────────────── */
   .cf-faq-item {
      border: 1.5px solid #e5e7eb; border-radius: 12px; margin-bottom: 10px;
      overflow: hidden; transition: border-color .2s;
   }
   .cf-faq-item.open { border-color: var(--pu-blue); }
   .cf-faq-q {
      padding: 16px 20px; cursor: pointer;
      display: flex; justify-content: space-between; align-items: center;
      background: #fff; font-weight: 700; font-size: .92rem;
      color: var(--pu-navy); user-select: none; gap: 12px;
   }
   .cf-faq-q:hover { background: #f8fafc; }
   .cf-faq-q i.cf-faq-icon { flex-shrink: 0; color: var(--pu-blue); transition: transform .3s; }
   .cf-faq-item.open .cf-faq-q i.cf-faq-icon { transform: rotate(180deg); }
   .cf-faq-a {
      padding: 0 20px; max-height: 0; overflow: hidden;
      transition: max-height .4s ease, padding .4s;
      font-size: .88rem; color: #6b7280; line-height: 1.75; background: #fff;
   }
   .cf-faq-item.open .cf-faq-a { max-height: 400px; padding: 0 20px 16px; }

   /* ── Empty state ──────────────────────────────────────────── */
   .cf-empty-icon {
      width: 72px; height: 72px; border-radius: 50%;
      background: #f1f5f9; display: flex; align-items: center; justify-content: center; margin: 0 auto;
   }

   @media (max-width: 767px) {
      .cf-waiver-options { grid-template-columns: repeat(2, 1fr); }
      .cf-sticky-bar { padding: 10px 16px; }
      .cf-deg-pill { font-size: .74rem; padding: 4px 10px; }
      .cf-forecast-toggle { font-size: .82rem; padding: 12px 14px; }
   }
   </style>
<?php include __DIR__ . '/includes/meta-pixel.php'; ?>
</head>

<body id="body" class="it-magic-cursor">

   <!-- preloader -->
   <div id="preloader">
      <div class="preloader"><span></span><span></span></div>
   </div>

   <div id="magic-cursor"><div id="ball"></div></div>

   <button class="scroll-top scroll-to-target" data-target="html">
      <i class="far fa-angle-double-up"></i>
   </button>

   <!-- search popup -->
   <div class="search-popup">
      <button class="close-search"><span class="flaticon-multiply"><i class="fal fa-times"></i></span></button>
      <form method="post" action="#">
         <div class="form-group">
            <input type="search" name="search-field" value="" placeholder="Search Here" required>
            <button type="submit"><i class="fal fa-search"></i></button>
         </div>
      </form>
   </div>

   <!-- offcanvas -->
   <div class="it-offcanvas-area">
      <div class="itoffcanvas">
         <div class="itoffcanvas__close-btn">
            <button class="close-btn"><i class="fal fa-times"></i></button>
         </div>
         <div class="itoffcanvas__logo">
            <a href="<?= fh(SITE_URL) ?>/index.php">
               <img src="/assets/img/logo/logo-black.png" alt="Prime University">
            </a>
         </div>
         <div class="it-menu-mobile d-xl-none"></div>
         <div class="itoffcanvas__info">
            <h3 class="offcanva-title">Get In Touch</h3>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon"><a href="#"><i class="fal fa-envelope"></i></a></div>
               <div class="itoffcanvas__info-address">
                  <span>Email</span><a href="mailto:info@primeuniversity.ac.bd">info@primeuniversity.ac.bd</a>
               </div>
            </div>
            <div class="it-info-wrapper d-flex align-items-center">
               <div class="itoffcanvas__info-icon"><a href="#"><i class="fal fa-phone-alt"></i></a></div>
               <div class="itoffcanvas__info-address">
                  <span>Phone</span><a href="tel:01969955566">01969-955566</a>
               </div>
            </div>
         </div>
      </div>
   </div>

   <!-- Header -->
   <?php include __DIR__ . '/includes/header-top.php'; ?>
   <?php include __DIR__ . '/includes/nav-menu.php'; ?>

   <!-- news ticker -->
   <?php include __DIR__ . '/includes/news-ticker.php'; ?>

   <!-- ═══════ HERO ═══════ -->
   <section class="cf-hero">
      <div class="cf-hero-blob cf-hero-blob-1"></div>
      <div class="cf-hero-blob cf-hero-blob-2"></div>
      <div class="container position-relative">
         <div class="row align-items-center gy-5">
            <div class="col-lg-7 fade-in-up">
               <p class="text-uppercase fw-bold mb-3" style="color:var(--pu-gold);letter-spacing:.08em;font-size:.85rem;">
                  <i class="fas fa-calculator me-2"></i>Tuition & Fee Estimator
               </p>
               <h1 class="cf-hero-title mb-4">
                  <?= fh($settings['page_title'] ?? 'Course Fees') ?><br>
                  <span>Calculator</span>
               </h1>
               <p class="cf-hero-sub mb-5">
                  <?= fh($settings['page_subtitle'] ?? 'See the complete fee breakdown for any programme — transparent, real-time, and easy to understand.') ?>
               </p>
               <a href="#calculator" class="btn btn-warning fw-bold px-5 py-3 rounded-pill me-3"
                  style="font-size:1rem;color:var(--pu-navy);">
                  <i class="fas fa-calculator me-2"></i>Calculate Now
               </a>
               <a href="/apply-now.php" class="btn btn-outline-light fw-semibold px-4 py-3 rounded-pill"
                  style="font-size:.95rem;">
                  <i class="fas fa-paper-plane me-2"></i>Apply Now
               </a>
            </div>

            <!-- Stats -->
            <div class="col-lg-5 fade-in-up delay-2">
               <div class="row g-3">
                  <?php
                  $hero_stats = [
                      ['val' => count($programs), 'lbl' => 'Programmes', 'icon' => 'fas fa-book-open'],
                      ['val' => '100%', 'lbl' => 'Transparent Fees', 'icon' => 'fas fa-check-shield'],
                      ['val' => 'BDT', 'lbl' => 'Currency', 'icon' => 'fas fa-coins'],
                      ['val' => 'Live', 'lbl' => 'Real-Time Estimate', 'icon' => 'fas fa-bolt'],
                  ];
                  foreach ($hero_stats as $hs):
                  ?>
                  <div class="col-6">
                     <div class="cf-hero-stat">
                        <div class="mb-1"><i class="<?= $hs['icon'] ?>" style="color:var(--pu-gold);font-size:1.2rem;"></i></div>
                        <div class="cf-hero-stat-val"><?= $hs['val'] ?></div>
                        <div class="cf-hero-stat-lbl"><?= $hs['lbl'] ?></div>
                     </div>
                  </div>
                  <?php endforeach; ?>
               </div>
            </div>
         </div>
      </div>
   </section>

   <!-- ═══════ HOW IT WORKS ═══════ -->
   <section class="pt-80 pb-60" style="background:#f8fafc;">
      <div class="container">
         <div class="text-center mb-50 fade-in-up">
            <p class="text-uppercase fw-bold mb-2" style="color:var(--pu-blue);letter-spacing:.08em;font-size:.82rem;">Simple Steps</p>
            <h2 class="section-title" style="color:var(--pu-navy);">How It Works</h2>
         </div>
         <div class="row g-4">
            <?php
            $steps = [
               ['n'=>'1','icon'=>'fas fa-search','color'=>'#2563eb','bg'=>'#dbeafe','title'=>'Find Your Programme','desc'=>'Search by name or filter by department and degree type to find your programme instantly.'],
               ['n'=>'2','icon'=>'fas fa-layer-group','color'=>'#059669','bg'=>'#d1fae5','title'=>'See Cost Sections','desc'=>'View Regular Program Cost, Admission Day Payment, and your 1st Semester monthly breakdown.'],
               ['n'=>'3','icon'=>'fas fa-chart-line','color'=>'#d97706','bg'=>'#fef3c7','title'=>'Explore Total Forecast','desc'=>'Expand the forecast to see your full programme cost broken down year by year.'],
               ['n'=>'4','icon'=>'fas fa-shield-alt','color'=>'#7c3aed','bg'=>'#ede9fe','title'=>'Apply Your Safety Net','desc'=>'See how a scholarship or waiver reduces your total cost in the Safety Net section below.'],
            ];
            foreach ($steps as $i => $s):
            ?>
            <div class="col-sm-6 col-lg-3 fade-in-up delay-<?= $i + 1 ?>">
               <div class="cf-step-card h-100">
                  <div class="cf-step-num" style="background:<?= $s['bg'] ?>;color:<?= $s['color'] ?>"><?= $s['n'] ?></div>
                  <i class="<?= $s['icon'] ?> mb-3" style="font-size:1.6rem;color:<?= $s['color'] ?>"></i>
                  <h5 style="font-weight:800;color:var(--pu-navy);margin-bottom:8px;"><?= $s['title'] ?></h5>
                  <p style="font-size:.88rem;color:#6b7280;line-height:1.65;margin:0;"><?= $s['desc'] ?></p>
               </div>
            </div>
            <?php endforeach; ?>
         </div>
      </div>
   </section>

   <!-- ═══════ CALCULATOR ═══════ -->
   <section id="calculator" class="pt-80 pb-80">
      <div class="container">
         <div class="text-center mb-50 fade-in-up">
            <p class="text-uppercase fw-bold mb-2" style="color:var(--pu-blue);letter-spacing:.08em;font-size:.82rem;">
               <i class="fas fa-calculator me-1"></i>Fee Estimator
            </p>
            <h2 class="section-title" style="color:var(--pu-navy);">Calculate Your Fees</h2>
            <p class="mx-auto" style="max-width:540px;color:#6b7280;font-size:.95rem;">
               Select your programme to see a complete, easy-to-understand fee breakdown.
            </p>
         </div>

         <?php if (empty($programs)): ?>
         <div class="alert alert-info text-center py-5">
            <i class="fas fa-info-circle fa-2x mb-3 d-block"></i>
            Fee structures are not yet configured. Please check back later.
         </div>
         <?php else: ?>

         <?php
         $dept_list = [];
         foreach ($programs as $p) {
             $dept = $p['dept_name'] ?: 'Other';
             if (!in_array($dept, $dept_list, true)) $dept_list[] = $dept;
         }
         sort($dept_list);
         ?>

         <div class="row g-5 align-items-start">

            <!-- ── Programme Selector ── -->
            <div class="col-lg-4">
               <div class="cf-card card fade-in-up">
                  <div class="cf-card-header">
                     <h3 class="mb-1 fw-bold" style="font-size:1.15rem;">
                        <i class="fas fa-graduation-cap me-2" style="color:var(--pu-gold)"></i>
                        Find Your Programme
                     </h3>
                     <p class="mb-0" style="font-size:.84rem;opacity:.75;">Search or filter to see instant fee details.</p>
                  </div>
                  <div class="cf-card-body">
                     <!-- Progress dots -->
                     <div class="cf-steps-indicator" id="progress-dots">
                        <div class="cf-step-dot"></div>
                        <div class="cf-step-dot"></div>
                        <div class="cf-step-dot"></div>
                     </div>

                     <!-- Quick Search -->
                     <div class="mb-3">
                        <div class="cf-search-wrap">
                           <i class="fas fa-search cf-search-icon"></i>
                           <input type="text" id="progSearch" class="cf-search-input" placeholder="Search by programme name…">
                           <button class="cf-search-clear" id="searchClear" style="display:none;" title="Clear">
                              <i class="fas fa-times"></i>
                           </button>
                        </div>
                     </div>

                     <!-- Degree filter pills -->
                     <div class="cf-degree-filters mb-4" id="degreeFilters">
                        <button class="cf-deg-pill active" data-deg="all">All</button>
                        <button class="cf-deg-pill" data-deg="bachelor">Bachelor</button>
                        <button class="cf-deg-pill" data-deg="master">Master</button>
                        <button class="cf-deg-pill" data-deg="diploma">Diploma</button>
                        <button class="cf-deg-pill" data-deg="certificate">Certificate</button>
                     </div>

                     <!-- Department -->
                     <div class="mb-3">
                        <label class="fw-bold mb-2" style="color:var(--pu-navy);font-size:.9rem;">
                           <span class="cf-step-badge" style="background:var(--pu-blue)">1</span>Department
                        </label>
                        <div class="cf-select-wrap">
                           <select id="deptSelect" class="form-select">
                              <option value="">— All Departments —</option>
                              <?php foreach ($dept_list as $dept): ?>
                              <option value="<?= fh($dept) ?>"><?= fh($dept) ?></option>
                              <?php endforeach; ?>
                           </select>
                        </div>
                     </div>

                     <!-- Programme -->
                     <div class="mb-3" id="programWrap">
                        <label class="fw-bold mb-2" style="color:var(--pu-navy);font-size:.9rem;">
                           <span class="cf-step-badge" style="background:var(--pu-purple)">2</span>Programme
                        </label>
                        <div class="cf-select-wrap">
                           <select id="programSelect" class="form-select">
                              <option value="">— Choose a programme —</option>
                              <?php foreach ($programs as $p): ?>
                              <option value="<?= $p['id'] ?>"
                                 data-dept="<?= fh($p['dept_name'] ?: 'Other') ?>"
                                 data-degree="<?= fh($p['degree_type']) ?>">
                                 <?= fh($p['program_name'] ?: $p['dept_name']) ?>
                                 (<?= ucfirst($p['degree_type']) ?>)
                              </option>
                              <?php endforeach; ?>
                           </select>
                        </div>
                        <div id="prog-meta" class="mt-2 d-none">
                           <div class="d-flex gap-2 flex-wrap">
                              <span class="badge bg-primary" id="meta-degree"></span>
                              <span class="badge bg-secondary" id="meta-credits"></span>
                              <span class="badge bg-info text-dark" id="meta-duration"></span>
                              <span class="badge bg-success" id="meta-semesters"></span>
                           </div>
                        </div>
                     </div>

                     <!-- Tip -->
                     <div class="cf-tip-box">
                        <i class="fas fa-lightbulb text-warning me-2"></i>
                        Select a programme above to see the full cost breakdown instantly.
                     </div>
                  </div>
               </div>
            </div>

            <!-- ── Results ── -->
            <div class="col-lg-8 fade-in-up delay-2">
               <div id="resultPanel">

                  <!-- Empty state -->
                  <div class="text-center py-5" id="resultEmpty"
                       style="background:linear-gradient(160deg,#f0f7ff 0%,#fff 100%);border-radius:16px;border:1.5px solid #bfdbfe;">
                     <div class="cf-empty-icon mb-4">
                        <i class="fas fa-search" style="font-size:1.8rem;color:#94a3b8;"></i>
                     </div>
                     <h5 style="color:var(--pu-navy);font-weight:700;">Select a Programme to Begin</h5>
                     <p class="text-muted" style="font-size:.9rem;max-width:320px;margin:8px auto 0;">
                        Choose your department and programme on the left to see the full fee breakdown.
                     </p>
                  </div>

                  <!-- Results content -->
                  <div id="resultContent" class="d-none">

                     <!-- 1. Program Overview -->
                     <div class="cf-result-section" id="sec-overview">
                        <div class="cf-section-header cf-sec-overview">
                           <i class="fas fa-graduation-cap"></i>
                           <span id="res-prog-name">Programme Overview</span>
                        </div>
                        <div class="cf-section-body">
                           <div class="row g-2">
                              <div class="col-6 col-sm-3">
                                 <div class="cf-ov-item">
                                    <div class="cf-ov-label">Degree</div>
                                    <div class="cf-ov-val" id="ov-degree">—</div>
                                 </div>
                              </div>
                              <div class="col-6 col-sm-3">
                                 <div class="cf-ov-item">
                                    <div class="cf-ov-label">Duration</div>
                                    <div class="cf-ov-val" id="ov-duration">—</div>
                                 </div>
                              </div>
                              <div class="col-6 col-sm-3">
                                 <div class="cf-ov-item">
                                    <div class="cf-ov-label">Semesters</div>
                                    <div class="cf-ov-val" id="ov-semesters">—</div>
                                 </div>
                              </div>
                              <div class="col-6 col-sm-3">
                                 <div class="cf-ov-item">
                                    <div class="cf-ov-label">Credits</div>
                                    <div class="cf-ov-val" id="ov-credits">—</div>
                                 </div>
                              </div>
                           </div>
                        </div>
                     </div>

                     <!-- 2. Regular Program Cost (Per Semester) -->
                     <div class="cf-result-section d-none" id="sec-persem">
                        <div class="cf-section-header cf-sec-persem">
                           <i class="fas fa-calendar-alt"></i>
                           Regular Program Cost
                           <span class="cf-sec-note">per semester</span>
                        </div>
                        <div class="cf-section-body">
                           <div id="persem-rows"></div>
                           <div id="persem-total" class="cf-section-total d-none"></div>
                        </div>
                     </div>

                     <!-- 3. Admission Day Payment -->
                     <div class="cf-result-section d-none" id="sec-admission">
                        <div class="cf-section-header cf-sec-admission">
                           <i class="fas fa-door-open"></i>
                           Admission Day Payment
                           <span class="cf-sec-note">paid once at enrolment</span>
                        </div>
                        <div class="cf-section-body">
                           <div id="admission-rows"></div>
                           <div id="admission-total" class="cf-section-total d-none"></div>
                        </div>
                     </div>

                     <!-- 4. 1st Semester Monthly Breakdown -->
                     <div class="cf-result-section d-none" id="sec-monthly">
                        <div class="cf-section-header cf-sec-monthly">
                           <i class="fas fa-credit-card"></i>
                           1st Semester Monthly Breakdown
                        </div>
                        <div class="cf-section-body">
                           <div id="monthly-rows"></div>
                        </div>
                     </div>

                     <!-- 5. See X-Year Total Forecast -->
                     <div class="cf-result-section" id="sec-forecast">
                        <button class="cf-forecast-toggle" id="forecastToggle">
                           <i class="fas fa-chart-line"></i>
                           See <span id="forecast-label">Full</span> Total Forecast
                           <i class="fas fa-chevron-down ms-auto cf-chevron"></i>
                        </button>
                        <div id="forecastContent" class="d-none cf-section-body pt-2">
                           <div id="forecast-rows"></div>
                           <div id="forecast-grand" class="cf-forecast-grand d-none"></div>
                        </div>
                     </div>

                     <!-- 6. Safety Net (Scholarships & Waivers) -->
                     <div class="cf-result-section" id="sec-safety">
                        <div class="cf-section-header cf-sec-safety">
                           <i class="fas fa-shield-alt"></i>
                           Safety Net
                           <span class="cf-sec-note">scholarships &amp; waivers</span>
                        </div>
                        <div class="cf-section-body">
                           <p style="font-size:.85rem;color:#6b7280;margin-bottom:12px;">
                              Apply a scholarship to instantly see your reduced net cost:
                           </p>
                           <div class="cf-waiver-options">
                              <button class="cf-waiver-btn active" data-pct="0">No Waiver<small>Full Cost</small></button>
                              <button class="cf-waiver-btn" data-pct="25">25%<small>Merit Award</small></button>
                              <button class="cf-waiver-btn" data-pct="50">50%<small>Half Waiver</small></button>
                              <button class="cf-waiver-btn" data-pct="75">75%<small>Full Merit</small></button>
                           </div>
                           <div id="waiver-summary" class="mt-3 d-none">
                              <div class="cf-waiver-summary-box">
                                 <div class="cf-ws-row">
                                    <span>Original Total</span>
                                    <span id="ws-original" class="fw-bold"></span>
                                 </div>
                                 <div class="cf-ws-row">
                                    <span>Scholarship Savings</span>
                                    <span id="ws-savings" style="color:var(--pu-green);font-weight:800;"></span>
                                 </div>
                                 <div class="cf-ws-row cf-ws-total">
                                    <span>Your Net Cost</span>
                                    <span id="ws-net"></span>
                                 </div>
                                 <div class="cf-ws-row" id="ws-monthly-row">
                                    <span>Est. Monthly</span>
                                    <span id="ws-monthly" style="color:var(--pu-blue);font-weight:700;"></span>
                                 </div>
                              </div>
                           </div>
                        </div>
                     </div>

                     <!-- Actions -->
                     <div class="mt-4 d-grid gap-2">
                        <a href="/apply-now.php" class="btn btn-primary fw-bold py-3 rounded-pill">
                           <i class="fas fa-paper-plane me-2"></i>Apply Now
                        </a>
                        <div class="d-flex gap-2">
                           <button class="btn btn-outline-secondary btn-sm rounded-pill flex-fill" onclick="window.print()">
                              <i class="fas fa-print me-1"></i>Print Estimate
                           </button>
                           <button class="btn btn-outline-secondary btn-sm rounded-pill flex-fill" id="copyBtn">
                              <i class="fas fa-copy me-1"></i>Copy Summary
                           </button>
                        </div>
                     </div>

                  </div><!-- /resultContent -->
               </div><!-- /resultPanel -->
            </div>

         </div>

         <?php endif; ?>
      </div>
   </section>

   <!-- ═══════ DISCLAIMER ═══════ -->
   <?php if (!empty($settings['note_text'])): ?>
   <section class="pb-60">
      <div class="container">
         <div class="cf-note fade-in-up">
            <p class="fw-bold mb-1" style="color:#92400e;"><i class="fas fa-exclamation-triangle me-2"></i>Disclaimer</p>
            <p class="mb-0"><?= fh($settings['note_text']) ?></p>
         </div>
      </div>
   </section>
   <?php endif; ?>

   <!-- ═══════ FAQ ═══════ -->
   <section class="pt-80 pb-60" style="background:#f8fafc;">
      <div class="container">
         <div class="text-center mb-50 fade-in-up">
            <p class="text-uppercase fw-bold mb-2" style="color:var(--pu-blue);letter-spacing:.08em;font-size:.82rem;">
               <i class="fas fa-question-circle me-1"></i>Got Questions?
            </p>
            <h2 class="section-title" style="color:var(--pu-navy);">Frequently Asked Questions</h2>
            <p class="mx-auto mt-2" style="max-width:520px;color:#6b7280;font-size:.95rem;">
               Everything you need to know about fees at Prime University.
            </p>
         </div>
         <div class="row justify-content-center">
            <div class="col-lg-8 fade-in-up delay-1">
               <div class="cf-faq-item" role="button" tabindex="0" onclick="cfToggleFaq(this)" onkeydown="if(event.key==='Enter'||event.key===' '){cfToggleFaq(this);event.preventDefault();}">
                  <div class="cf-faq-q">When are admission fees paid?<i class="fas fa-chevron-down cf-faq-icon"></i></div>
                  <div class="cf-faq-a">Admission fees (one-time fees) are paid once when you first enrol at Prime University. They cover registration, library membership, student ID, and other one-time costs and are non-refundable after enrolment is confirmed.</div>
               </div>
               <div class="cf-faq-item" role="button" tabindex="0" onclick="cfToggleFaq(this)" onkeydown="if(event.key==='Enter'||event.key===' '){cfToggleFaq(this);event.preventDefault();}">
                  <div class="cf-faq-q">Can I pay per-semester fees in instalments?<i class="fas fa-chevron-down cf-faq-icon"></i></div>
                  <div class="cf-faq-a">Per-semester fees are typically due at the beginning of each semester. The university may offer a structured payment plan in some circumstances. Please contact the Accounts Office to discuss your options.</div>
               </div>
               <div class="cf-faq-item" role="button" tabindex="0" onclick="cfToggleFaq(this)" onkeydown="if(event.key==='Enter'||event.key===' '){cfToggleFaq(this);event.preventDefault();}">
                  <div class="cf-faq-q">Are scholarships or waivers available?<i class="fas fa-chevron-down cf-faq-icon"></i></div>
                  <div class="cf-faq-a">Yes! Prime University offers merit-based and need-based scholarships. Use the Safety Net waiver calculator above to see how a scholarship would affect your total cost. Visit the Scholarships &amp; Waivers page or contact the Admissions Office for eligibility details.</div>
               </div>
               <div class="cf-faq-item" role="button" tabindex="0" onclick="cfToggleFaq(this)" onkeydown="if(event.key==='Enter'||event.key===' '){cfToggleFaq(this);event.preventDefault();}">
                  <div class="cf-faq-q">What does the per-semester fee include?<i class="fas fa-chevron-down cf-faq-icon"></i></div>
                  <div class="cf-faq-a">Per-semester fees typically cover tuition, examination fees, lab fees (where applicable), and other academic service charges. The exact breakdown is shown in the Regular Program Cost section for each programme.</div>
               </div>
               <div class="cf-faq-item" role="button" tabindex="0" onclick="cfToggleFaq(this)" onkeydown="if(event.key==='Enter'||event.key===' '){cfToggleFaq(this);event.preventDefault();}">
                  <div class="cf-faq-q">Is the calculator estimate guaranteed?<i class="fas fa-chevron-down cf-faq-icon"></i></div>
                  <div class="cf-faq-a">The calculator provides estimates based on current published fee structures. Fees are subject to change. Always confirm the latest figures with the Accounts or Admissions Office before making financial decisions.</div>
               </div>
               <div class="cf-faq-item" role="button" tabindex="0" onclick="cfToggleFaq(this)" onkeydown="if(event.key==='Enter'||event.key===' '){cfToggleFaq(this);event.preventDefault();}">
                  <div class="cf-faq-q">How do I contact the accounts office for fee queries?<i class="fas fa-chevron-down cf-faq-icon"></i></div>
                  <div class="cf-faq-a">You can reach the Accounts Office by email at <a href="mailto:info@primeuniversity.ac.bd">info@primeuniversity.ac.bd</a>, by phone at <a href="tel:01969955566">01969-955566</a>, or by visiting the campus during office hours (Sunday–Thursday, 9am–5pm).</div>
               </div>
            </div>
         </div>
      </div>
   </section>

   <!-- ═══════ CTA ═══════ -->
   <section class="pb-80">
      <div class="container">
         <div class="cf-cta fade-in-up">
            <i class="fas fa-graduation-cap mb-3" style="font-size:2.5rem;color:var(--pu-gold);"></i>
            <h2>Ready to Join Prime University?</h2>
            <p style="max-width:500px;margin:0 auto 28px;opacity:.8;font-size:.98rem;">
               Start your application today and take the first step toward a world-class education.
            </p>
            <div class="d-flex gap-3 justify-content-center flex-wrap">
               <a href="/apply-now.php" class="cf-cta-btn">
                  <i class="fas fa-paper-plane me-2"></i>Apply Now
               </a>
               <a href="/contact.php" class="btn btn-outline-light fw-semibold px-5 py-3 rounded-pill">
                  <i class="fas fa-phone me-2"></i>Contact Us
               </a>
            </div>
         </div>
      </div>
   </section>

   <!-- Mobile Sticky Total Bar -->
   <div class="cf-sticky-bar" id="stickyBar">
      <div style="min-width:0;flex:1;">
         <div class="cf-sticky-bar-prog" id="stickyProg">Select a programme</div>
         <div class="cf-sticky-bar-total" id="stickyTotal">—</div>
      </div>
      <button class="cf-sticky-bar-scroll"
              onclick="document.getElementById('resultContent').scrollIntoView({behavior:'smooth'})">
         <i class="fas fa-arrow-down me-1"></i>See Breakdown
      </button>
   </div>

   <?php include __DIR__ . '/includes/footer.php'; ?>
   <?php include __DIR__ . '/includes/scripts.php'; ?>

   <script>
   // ── Programme data from PHP ───────────────────────────────────────────────
   var CF_PROGRAMS = <?= json_encode($js_programs, JSON_UNESCAPED_UNICODE) ?>;
   var CF_CURRENCY = <?= json_encode($currency) ?>;

   // ── State ─────────────────────────────────────────────────────────────────
   var state = {
      dept      : '',
      programId : null,
      waiverPct : 0,
      degFilter : 'all',
      searchText: ''
   };

   // ── Element refs ─────────────────────────────────────────────────────────
   var elDeptSelect   = document.getElementById('deptSelect');
   var elProgramWrap  = document.getElementById('programWrap');
   var elSelect       = document.getElementById('programSelect');
   var elResultEmpty  = document.getElementById('resultEmpty');
   var elResultCont   = document.getElementById('resultContent');
   var elProgMeta     = document.getElementById('prog-meta');
   var elMetaDegree   = document.getElementById('meta-degree');
   var elMetaCredits  = document.getElementById('meta-credits');
   var elMetaDuration = document.getElementById('meta-duration');
   var elMetaSem      = document.getElementById('meta-semesters');
   var elSearch       = document.getElementById('progSearch');
   var elSearchClear  = document.getElementById('searchClear');
   var elDots         = document.querySelectorAll('.cf-step-dot');

   // Result section elements
   var elResProgName    = document.getElementById('res-prog-name');
   var elOvDegree       = document.getElementById('ov-degree');
   var elOvDuration     = document.getElementById('ov-duration');
   var elOvSemesters    = document.getElementById('ov-semesters');
   var elOvCredits      = document.getElementById('ov-credits');
   var elSecPersem      = document.getElementById('sec-persem');
   var elPersemRows     = document.getElementById('persem-rows');
   var elPersemTotal    = document.getElementById('persem-total');
   var elSecAdmission   = document.getElementById('sec-admission');
   var elAdmissionRows  = document.getElementById('admission-rows');
   var elAdmissionTotal = document.getElementById('admission-total');
   var elSecMonthly     = document.getElementById('sec-monthly');
   var elMonthlyRows    = document.getElementById('monthly-rows');
   var elForecastLabel  = document.getElementById('forecast-label');
   var elForecastContent = document.getElementById('forecastContent');
   var elForecastRows   = document.getElementById('forecast-rows');
   var elForecastGrand  = document.getElementById('forecast-grand');
   var elForecastToggle = document.getElementById('forecastToggle');
   var elWaiverSummary  = document.getElementById('waiver-summary');
   var elWsOriginal     = document.getElementById('ws-original');
   var elWsSavings      = document.getElementById('ws-savings');
   var elWsNet          = document.getElementById('ws-net');
   var elWsMonthly      = document.getElementById('ws-monthly');
   var elWsMonthlyRow   = document.getElementById('ws-monthly-row');
   var elStickyBar      = document.getElementById('stickyBar');
   var elStickyProg     = document.getElementById('stickyProg');
   var elStickyTotal    = document.getElementById('stickyTotal');
   var elCopyBtn        = document.getElementById('copyBtn');

   // ── Helpers ───────────────────────────────────────────────────────────────
   function fmt(n) {
      return CF_CURRENCY + ' ' + Math.round(n).toLocaleString('en-BD');
   }

   function fmtExact(n) {
      var r = Math.round(n * 100) / 100;
      return CF_CURRENCY + ' ' + r.toLocaleString('en-BD', {minimumFractionDigits: 0, maximumFractionDigits: 2});
   }

   function getProgram() {
      return CF_PROGRAMS.find(function(p) { return p.id === state.programId; }) || null;
   }

   function secRow(label, note, val) {
      return '<div class="cf-section-row">' +
         '<span>' + label + (note ? '<br><small class="text-muted" style="font-size:.78rem;">' + note + '</small>' : '') + '</span>' +
         '<span class="cf-section-row-val">' + val + '</span>' +
         '</div>';
   }

   function updateDots() {
      var filled = (!state.dept && !state.searchText) ? 0 : (!state.programId ? 1 : (state.waiverPct > 0 ? 3 : 2));
      elDots.forEach(function(d, i) { d.classList.toggle('done', i < filled); });
   }

   function updateStickyBar(prog, total) {
      if (!elStickyBar) return;
      if (prog && total > 0 && window.innerWidth < 992) {
         elStickyProg.textContent  = prog.label;
         elStickyTotal.textContent = fmt(total);
         elStickyBar.classList.add('show');
      } else {
         elStickyBar.classList.remove('show');
      }
   }

   // ── Calculate & render ────────────────────────────────────────────────────
   function calculate() {
      var prog = getProgram();
      if (!prog) {
         elResultEmpty.classList.remove('d-none');
         elResultCont.classList.add('d-none');
         updateStickyBar(null, 0);
         updateDots();
         return;
      }

      var months       = prog.duration ? Math.round(prog.duration * 12) : 0;
      var semesters    = prog.num_semesters || 0;
      var monthsPerSem = (semesters > 0 && months > 0) ? months / semesters : 6;
      if (monthsPerSem <= 0) monthsPerSem = 6;

      var oneTimeFees = prog.fixed_fees.filter(function(f) { return f.type === 'one_time'; });
      var perSemFees  = prog.fixed_fees.filter(function(f) { return f.type === 'per_semester'; });
      var monthlyFees = prog.fixed_fees.filter(function(f) { return f.type === 'monthly'; });

      var oneTimeTotal    = oneTimeFees.reduce(function(s, f) { return s + f.amount; }, 0);
      var perSemPerSem    = perSemFees.reduce(function(s, f) { return s + f.amount; }, 0);
      var perSemFullTotal = semesters > 0 ? perSemPerSem * semesters : 0;
      var monthlyBase     = monthlyFees.reduce(function(s, f) { return s + f.amount; }, 0);
      var monthlyPerMonth = months > 0 ? monthlyBase / months : monthlyBase;
      var grandTotal      = oneTimeTotal + perSemFullTotal + monthlyBase;
      var waiverFactor    = 1 - state.waiverPct / 100;

      // ── 1. Program Overview ─────────────────────────────────────────────
      elResProgName.textContent = prog.label;
      elOvDegree.textContent    = prog.degree.charAt(0).toUpperCase() + prog.degree.slice(1);
      elOvDuration.textContent  = prog.duration ? prog.duration + ' yrs' : '—';
      elOvSemesters.textContent = semesters > 0 ? semesters : '—';
      elOvCredits.textContent   = prog.total_credits ? prog.total_credits : '—';

      // ── 2. Regular Program Cost (Per Semester) ──────────────────────────
      if (perSemFees.length > 0) {
         var psRows = '';
         perSemFees.forEach(function(f) {
            psRows += secRow(f.name, null, fmt(f.amount) + '/sem');
         });
         elPersemRows.innerHTML = psRows;
         if (perSemFees.length > 1) {
            elPersemTotal.innerHTML = '<span>Total per Semester</span><span>' + fmt(perSemPerSem) + '</span>';
            elPersemTotal.classList.remove('d-none');
         } else {
            elPersemTotal.classList.add('d-none');
         }
         elSecPersem.classList.remove('d-none');
      } else {
         elSecPersem.classList.add('d-none');
      }

      // ── 3. Admission Day Payment ────────────────────────────────────────
      if (oneTimeFees.length > 0) {
         var adRows = '';
         oneTimeFees.forEach(function(f) {
            adRows += secRow(f.name, 'Paid once at enrolment', fmt(f.amount));
         });
         elAdmissionRows.innerHTML = adRows;
         if (oneTimeFees.length > 1) {
            elAdmissionTotal.innerHTML = '<span>Total on Admission Day</span><span>' + fmt(oneTimeTotal) + '</span>';
            elAdmissionTotal.classList.remove('d-none');
         } else {
            elAdmissionTotal.classList.add('d-none');
         }
         elSecAdmission.classList.remove('d-none');
      } else {
         elSecAdmission.classList.add('d-none');
      }

      // ── 4. 1st Semester Monthly Breakdown ──────────────────────────────
      if (perSemFees.length > 0 || oneTimeFees.length > 0) {
         var firstSemTotal     = oneTimeTotal + perSemPerSem;
         var monthlyFromPerSem = perSemPerSem / monthsPerSem;
         var admissionMonthly  = oneTimeTotal / monthsPerSem;
         var firstMonthTotal   = monthlyFromPerSem + admissionMonthly;
         var moRows = '';
         if (oneTimeFees.length > 0) {
            moRows += secRow(
               'Admission fees spread over ' + Math.round(monthsPerSem) + ' months',
               null,
               fmtExact(admissionMonthly) + '/mo'
            );
         }
         if (perSemFees.length > 0) {
            moRows += secRow(
               'Semester fees spread over ' + Math.round(monthsPerSem) + ' months',
               null,
               fmtExact(monthlyFromPerSem) + '/mo'
            );
         }
         moRows += '<div class="cf-monthly-highlight">' +
            '<div class="cf-monthly-note">Estimated 1st semester monthly payment</div>' +
            '<div class="cf-monthly-big">' + fmtExact(firstMonthTotal) + ' /month</div>' +
            '<div class="cf-monthly-note">Based on ' + Math.round(monthsPerSem) + ' months per semester &middot; 1st semester total: ' + fmt(firstSemTotal) + '</div>' +
            '</div>';
         elMonthlyRows.innerHTML = moRows;
         elSecMonthly.classList.remove('d-none');
      } else {
         elSecMonthly.classList.add('d-none');
      }

      // ── 5. Total Forecast ───────────────────────────────────────────────
      var durationYears = prog.duration || (semesters > 0 ? semesters / 3 : 4);
      var semsPerYear   = semesters > 0 && durationYears > 0 ? semesters / durationYears : 3;
      elForecastLabel.textContent = Math.round(durationYears) + '-Year';
      var fRows = '';
      if (semesters > 0 && perSemFees.length > 0) {
         var semCount = 0;
         for (var yr = 1; yr <= Math.ceil(durationYears); yr++) {
            var semsThisYear = Math.min(Math.round(semsPerYear), semesters - semCount);
            if (semsThisYear <= 0) break;
            semCount += semsThisYear;
            var yrCost = semsThisYear * perSemPerSem;
            if (yr === 1) yrCost += oneTimeTotal;
            fRows += '<div class="cf-forecast-year-row">' +
               '<span class="cf-forecast-year-label">Year ' + yr +
               (yr === 1 ? ' <small style="color:#f59e0b;font-size:.75rem;">(incl. admission fees)</small>' : '') +
               '</span>' +
               '<span class="cf-forecast-year-amt">' + fmt(yrCost * waiverFactor) + '</span>' +
               '</div>';
         }
         elForecastGrand.innerHTML =
            '<div><div class="cf-forecast-grand-label">TOTAL PROGRAMME COST</div>' +
            '<div style="font-size:.78rem;opacity:.7;margin-top:2px;">' +
            Math.round(durationYears) + ' years &middot; ' + semesters + ' semesters' +
            (state.waiverPct > 0 ? ' &middot; after ' + state.waiverPct + '% waiver' : '') +
            '</div></div>' +
            '<div class="cf-forecast-grand-amt">' + fmt(grandTotal * waiverFactor) + '</div>';
         elForecastGrand.classList.remove('d-none');
      } else {
         fRows = '<p class="text-muted py-2 mb-0" style="font-size:.85rem;">Semester count not specified for this programme — forecast unavailable.</p>';
         elForecastGrand.classList.add('d-none');
      }
      elForecastRows.innerHTML = fRows;

      // ── 6. Safety Net ───────────────────────────────────────────────────
      if (state.waiverPct > 0 && grandTotal > 0) {
         var savings  = grandTotal * state.waiverPct / 100;
         var netTotal = grandTotal - savings;
         elWsOriginal.textContent = fmt(grandTotal);
         elWsSavings.textContent  = '− ' + fmt(savings);
         elWsNet.textContent      = fmt(netTotal);
         var netMonthly = monthlyPerMonth > 0 ? monthlyPerMonth * waiverFactor
                         : (perSemPerSem > 0 ? (perSemPerSem * waiverFactor) / monthsPerSem : 0);
         if (netMonthly > 0) {
            elWsMonthly.textContent      = fmtExact(netMonthly) + '/month';
            elWsMonthlyRow.style.display = '';
         } else {
            elWsMonthlyRow.style.display = 'none';
         }
         elWaiverSummary.classList.remove('d-none');
      } else {
         elWaiverSummary.classList.add('d-none');
      }

      elResultEmpty.classList.add('d-none');
      elResultCont.classList.remove('d-none');
      updateStickyBar(prog, grandTotal * waiverFactor);
      updateDots();
   }

   // ── Filter programme options ──────────────────────────────────────────────
   function applyFilters() {
      var q    = state.searchText.toLowerCase().trim();
      var deg  = state.degFilter;
      var dept = state.dept;
      var opts = elSelect.querySelectorAll('option');
      opts.forEach(function(opt) {
         if (!opt.value) { opt.style.display = ''; return; }
         var matchSearch = !q || opt.textContent.toLowerCase().includes(q) || (opt.dataset.dept || '').toLowerCase().includes(q);
         var matchDeg    = deg === 'all' || opt.dataset.degree === deg;
         var matchDept   = q ? true : (!dept || opt.dataset.dept === dept);
         opt.style.display = (matchSearch && matchDeg && matchDept) ? '' : 'none';
      });
      // If current selection is now hidden, deselect it
      if (state.programId) {
         var cur = elSelect.querySelector('option[value="' + state.programId + '"]');
         if (cur && cur.style.display === 'none') {
            state.programId = null;
            elSelect.value  = '';
            elProgMeta.classList.add('d-none');
            elResultEmpty.classList.remove('d-none');
            elResultCont.classList.add('d-none');
            updateStickyBar(null, 0);
         }
      }
      if (elSearchClear) elSearchClear.style.display = q ? '' : 'none';
      updateDots();
   }

   // ── Event Listeners ───────────────────────────────────────────────────────

   // Search
   if (elSearch) {
      elSearch.addEventListener('input', function() {
         state.searchText = this.value;
         applyFilters();
      });
   }
   if (elSearchClear) {
      elSearchClear.addEventListener('click', function() {
         elSearch.value = '';
         state.searchText = '';
         applyFilters();
         elSearch.focus();
      });
   }

   // Degree filter pills
   document.querySelectorAll('.cf-deg-pill').forEach(function(btn) {
      btn.addEventListener('click', function() {
         document.querySelectorAll('.cf-deg-pill').forEach(function(b) { b.classList.remove('active'); });
         this.classList.add('active');
         state.degFilter = this.dataset.deg;
         applyFilters();
      });
   });

   // Waiver buttons
   document.querySelectorAll('.cf-waiver-btn').forEach(function(btn) {
      btn.addEventListener('click', function() {
         document.querySelectorAll('.cf-waiver-btn').forEach(function(b) { b.classList.remove('active'); });
         this.classList.add('active');
         state.waiverPct = parseInt(this.dataset.pct) || 0;
         calculate();
      });
   });

   // Department dropdown
   elDeptSelect.addEventListener('change', function() {
      state.dept = this.value;
      state.programId = null;
      state.searchText = '';
      if (elSearch) elSearch.value = '';
      applyFilters();
      elSelect.value = '';
      elProgMeta.classList.add('d-none');
      elResultEmpty.classList.remove('d-none');
      elResultCont.classList.add('d-none');
      updateStickyBar(null, 0);
   });

   // Programme selected
   elSelect.addEventListener('change', function() {
      var id = parseInt(this.value);
      state.programId = isNaN(id) ? null : id;
      var prog = getProgram();
      if (prog) {
         elProgMeta.classList.remove('d-none');
         elMetaDegree.textContent   = prog.degree.charAt(0).toUpperCase() + prog.degree.slice(1);
         elMetaCredits.textContent  = prog.total_credits ? prog.total_credits + ' credits' : '';
         elMetaDuration.textContent = prog.duration ? prog.duration + ' yrs' : '';
         elMetaSem.textContent      = prog.num_semesters ? prog.num_semesters + ' semesters' : '';
         elMetaCredits.style.display  = prog.total_credits  ? '' : 'none';
         elMetaDuration.style.display = prog.duration       ? '' : 'none';
         elMetaSem.style.display      = prog.num_semesters  ? '' : 'none';
         // Sync dept dropdown when not searching
         if (prog.dept && !state.searchText) {
            elDeptSelect.value = prog.dept;
            state.dept = prog.dept;
         }
      } else {
         elProgMeta.classList.add('d-none');
      }
      calculate();
   });

   // Forecast toggle
   if (elForecastToggle) {
      elForecastToggle.addEventListener('click', function() {
         this.classList.toggle('open');
         elForecastContent.classList.toggle('d-none');
      });
   }

   // Copy summary
   if (elCopyBtn) {
      elCopyBtn.addEventListener('click', function() {
         var prog = getProgram();
         if (!prog) return;
         var ot  = prog.fixed_fees.filter(function(f) { return f.type === 'one_time'; }).reduce(function(s, f) { return s + f.amount; }, 0);
         var ps  = prog.fixed_fees.filter(function(f) { return f.type === 'per_semester'; }).reduce(function(s, f) { return s + f.amount; }, 0);
         var sem = prog.num_semesters || 0;
         var gt  = ot + (sem > 0 ? ps * sem : 0);
         var wf  = 1 - state.waiverPct / 100;
         var lines = [
            prog.label + ' — Fee Summary',
            '─────────────────────────────',
            'Admission Day Payment: ' + fmt(ot),
            'Per Semester Cost:     ' + fmt(ps),
            'Total (' + sem + ' semesters): ' + fmt(gt * wf) + (state.waiverPct > 0 ? ' (after ' + state.waiverPct + '% waiver)' : ''),
            '─────────────────────────────',
            'fees.primeuniversity.ac.bd'
         ];
         var text = lines.join('\n');
         if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
               elCopyBtn.innerHTML = '<i class="fas fa-check me-1"></i>Copied!';
               setTimeout(function() { elCopyBtn.innerHTML = '<i class="fas fa-copy me-1"></i>Copy Summary'; }, 2200);
            }).catch(function() {
               elCopyBtn.innerHTML = '<i class="fas fa-times me-1"></i>Copy failed';
               setTimeout(function() { elCopyBtn.innerHTML = '<i class="fas fa-copy me-1"></i>Copy Summary'; }, 2200);
            });
         }
      });
   }

   // FAQ accordion
   function cfToggleFaq(el) {
      var isOpen = el.classList.contains('open');
      document.querySelectorAll('.cf-faq-item.open').forEach(function(o) { o.classList.remove('open'); });
      if (!isOpen) el.classList.add('open');
   }

   // Smooth scroll for hero CTA button
   document.querySelector('a[href="#calculator"]') &&
   document.querySelector('a[href="#calculator"]').addEventListener('click', function(e) {
      e.preventDefault();
      document.getElementById('calculator').scrollIntoView({ behavior: 'smooth' });
   });

   // Update sticky bar on resize
   window.addEventListener('resize', function() {
      var prog = getProgram();
      if (!prog) { updateStickyBar(null, 0); return; }
      var ot  = prog.fixed_fees.filter(function(f) { return f.type === 'one_time'; }).reduce(function(s, f) { return s + f.amount; }, 0);
      var ps  = prog.fixed_fees.filter(function(f) { return f.type === 'per_semester'; }).reduce(function(s, f) { return s + f.amount; }, 0);
      var mb  = prog.fixed_fees.filter(function(f) { return f.type === 'monthly'; }).reduce(function(s, f) { return s + f.amount; }, 0);
      var sem = prog.num_semesters || 0;
      var gt  = ot + (sem > 0 ? ps * sem : 0) + mb;
      updateStickyBar(prog, Math.round(gt * (1 - state.waiverPct / 100)));
   });

   // Intersection observer for fade-in-up
   if ('IntersectionObserver' in window) {
      var fadeEls = document.querySelectorAll('.fade-in-up');
      var obs = new IntersectionObserver(function(entries) {
         entries.forEach(function(en) {
            if (en.isIntersecting) { en.target.style.opacity = '1'; obs.unobserve(en.target); }
         });
      }, { threshold: 0.15 });
      fadeEls.forEach(function(el) { el.style.opacity = '0'; obs.observe(el); });
   }
   </script>

</body>
</html>
