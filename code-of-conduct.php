<?php
require_once __DIR__ . '/includes/config.php';

$page_title = 'Code of Conduct – Prime University';

$sections = [];
try {
    $db = front_db();
    if ($db) {
        $sections = $db->query(
            'SELECT * FROM cms_coc_sections WHERE is_active = 1 ORDER BY sort_order, id'
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($sections as &$sec) {
            $stmt = $db->prepare(
                'SELECT item_text FROM cms_coc_items WHERE section_id = ? AND is_active = 1 ORDER BY sort_order, id'
            );
            $stmt->execute([$sec['id']]);
            $sec['items'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        unset($sec);
    }
} catch (Throwable $e) {
    // Silently degrade
}
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?= fh($page_title) ?></title>
    <meta name="description" content="Prime University Code of Conduct – Standards of behaviour for students, faculty and staff.">

    <!-- Favicon -->
    <link rel="shortcut icon" href="/assets/img/logo/favicon.png" type="image/x-icon">

    <!-- Bootstrap -->
    <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="/assets/css/font-awesome-pro.css">
    <!-- Main stylesheet -->
    <link rel="stylesheet" href="/assets/css/main.css">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">

    <style>
        /* ── Reset / Base ── */
        *, *::before, *::after { box-sizing: border-box; }

        /* ── Hero ── */
        .coc-hero {
            position: relative;
            background: linear-gradient(135deg, #0d1b4b 0%, #1a3a7c 50%, #0d1b4b 100%);
            color: #fff;
            padding: 110px 0 80px;
            overflow: hidden;
            text-align: center;
        }
        .coc-hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(ellipse at 20% 30%, rgba(79,142,247,.25) 0%, transparent 60%),
                radial-gradient(ellipse at 80% 70%, rgba(139,92,246,.2) 0%, transparent 60%);
            pointer-events: none;
        }
        .coc-hero-pattern {
            position: absolute;
            inset: 0;
            background-image:
                repeating-linear-gradient(0deg, transparent, transparent 40px, rgba(255,255,255,.03) 40px, rgba(255,255,255,.03) 41px),
                repeating-linear-gradient(90deg, transparent, transparent 40px, rgba(255,255,255,.03) 40px, rgba(255,255,255,.03) 41px);
            pointer-events: none;
        }
        .coc-hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.2);
            backdrop-filter: blur(8px);
            border-radius: 100px;
            padding: 6px 18px;
            font-size: .8rem;
            font-weight: 600;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: #a5c8ff;
            margin-bottom: 20px;
        }
        .coc-hero h1 {
            font-family: 'Inter', sans-serif;
            font-size: clamp(2rem, 5vw, 3.4rem);
            font-weight: 800;
            line-height: 1.15;
            margin-bottom: 18px;
        }
        .coc-hero h1 span {
            background: linear-gradient(90deg, #60a5fa, #a78bfa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .coc-hero p {
            max-width: 640px;
            margin: 0 auto 32px;
            font-size: 1.05rem;
            color: rgba(255,255,255,.75);
            line-height: 1.7;
        }
        .coc-hero-stats {
            display: inline-flex;
            gap: 32px;
            background: rgba(255,255,255,.08);
            border: 1px solid rgba(255,255,255,.12);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 16px 32px;
            flex-wrap: wrap;
            justify-content: center;
        }
        .coc-hero-stats .stat strong {
            display: block;
            font-size: 1.5rem;
            font-weight: 800;
        }
        .coc-hero-stats .stat span {
            font-size: .78rem;
            color: rgba(255,255,255,.6);
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        /* ── Tab Navigation ── */
        .coc-tabs-wrap {
            background: #fff;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 20px rgba(0,0,0,.08);
        }
        .coc-tabs {
            display: flex;
            gap: 0;
            overflow-x: auto;
            scrollbar-width: none;
            max-width: 900px;
            margin: 0 auto;
        }
        .coc-tabs::-webkit-scrollbar { display: none; }
        .coc-tab-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 18px 28px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            font-family: 'Inter', sans-serif;
            font-size: .9rem;
            font-weight: 600;
            color: #6b7280;
            cursor: pointer;
            white-space: nowrap;
            transition: color .2s, border-color .2s;
        }
        .coc-tab-btn .tab-icon {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .85rem;
            background: #f3f4f6;
            transition: background .2s, color .2s;
        }
        .coc-tab-btn.active {
            color: #1a3a7c;
            border-bottom-color: #4f8ef7;
        }
        .coc-tab-btn.active .tab-icon {
            background: linear-gradient(135deg, #4f8ef7, #7c3aed);
            color: #fff;
        }
        .coc-tab-btn:hover:not(.active) { color: #374151; }
        .coc-tab-btn:hover:not(.active) .tab-icon { background: #e5e7eb; }

        /* ── Section Content ── */
        .coc-main { background: #f8faff; }

        .coc-section {
            display: none;
            padding: 70px 0;
            animation: coc-fadein .35s ease both;
        }
        .coc-section.active { display: block; }

        @keyframes coc-fadein {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── Section Header ── */
        .coc-section-header {
            display: flex;
            align-items: flex-start;
            gap: 24px;
            margin-bottom: 40px;
        }
        .coc-section-icon-wrap {
            flex-shrink: 0;
            width: 72px;
            height: 72px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            color: #fff;
        }
        .coc-section-icon-wrap.student { background: linear-gradient(135deg, #4f8ef7, #1a3a7c); }
        .coc-section-icon-wrap.faculty { background: linear-gradient(135deg, #10b981, #065f46); }
        .coc-section-icon-wrap.staff   { background: linear-gradient(135deg, #f59e0b, #b45309); }
        .coc-section-icon-wrap.default { background: linear-gradient(135deg, #7c3aed, #4f8ef7); }

        .coc-section-header h2 {
            font-family: 'Inter', sans-serif;
            font-size: clamp(1.5rem, 3.5vw, 2.2rem);
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 6px;
        }
        .coc-section-header .subtitle {
            color: #6b7280;
            font-size: .95rem;
            font-weight: 500;
        }

        /* ── Intro Card ── */
        .coc-intro {
            background: linear-gradient(135deg, #eff6ff 0%, #f5f3ff 100%);
            border: 1px solid #dbeafe;
            border-radius: 16px;
            padding: 28px 32px;
            margin-bottom: 40px;
            position: relative;
            overflow: hidden;
        }
        .coc-intro::before {
            content: '\f518';
            font-family: 'Font Awesome 6 Pro', 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            right: 20px;
            top: 10px;
            font-size: 5rem;
            color: rgba(79,142,247,.08);
            pointer-events: none;
        }
        .coc-intro p {
            color: #374151;
            font-size: .95rem;
            line-height: 1.8;
            margin: 0;
        }

        /* ── Items List ── */
        .coc-items-heading {
            font-family: 'Inter', sans-serif;
            font-size: 1rem;
            font-weight: 700;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: .07em;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .coc-items-heading::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e5e7eb;
        }

        .coc-list {
            list-style: none;
            padding: 0;
            margin: 0;
            display: grid;
            gap: 12px;
        }
        .coc-list-item {
            background: #fff;
            border-radius: 12px;
            padding: 18px 20px 18px 56px;
            position: relative;
            border: 1px solid #e5e7eb;
            transition: box-shadow .2s, border-color .2s, transform .2s;
            font-size: .92rem;
            color: #374151;
            line-height: 1.6;
            opacity: 0;
            transform: translateY(20px);
        }
        .coc-list-item.coc-visible {
            opacity: 1;
            transform: translateY(0);
            transition: opacity .4s ease, transform .4s ease, box-shadow .2s, border-color .2s;
        }
        .coc-list-item:hover {
            box-shadow: 0 6px 24px rgba(79,142,247,.12);
            border-color: #bfdbfe;
            transform: translateX(4px);
        }
        .coc-list-item::before {
            content: attr(data-num);
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .72rem;
            font-weight: 700;
            color: #fff;
        }
        .coc-section[data-key="student"] .coc-list-item::before {
            background: linear-gradient(135deg, #4f8ef7, #1a3a7c);
        }
        .coc-section[data-key="faculty"] .coc-list-item::before {
            background: linear-gradient(135deg, #10b981, #065f46);
        }
        .coc-section[data-key="staff"] .coc-list-item::before {
            background: linear-gradient(135deg, #f59e0b, #b45309);
        }
        .coc-section[data-key] .coc-list-item::before {
            background: linear-gradient(135deg, #7c3aed, #4f8ef7);
        }

        /* ── Mobile ── */
        @media (max-width: 576px) {
            .coc-hero { padding: 80px 0 60px; }
            .coc-hero-stats { gap: 20px; padding: 14px 20px; }
            .coc-section-header { flex-direction: column; gap: 16px; }
            .coc-tab-btn { padding: 14px 18px; font-size: .82rem; }
            .coc-tab-btn .tab-icon { width: 28px; height: 28px; font-size: .75rem; }
            .coc-intro { padding: 20px; }
            .coc-list-item { padding: 16px 16px 16px 52px; }
        }
    </style>
</head>
<body>

<!-- ══ Header ══ -->
<?php include __DIR__ . '/includes/header-top.php'; ?>
<?php include __DIR__ . '/includes/nav-menu.php'; ?>

<!-- ══ Hero ══ -->
<section class="coc-hero">
    <div class="coc-hero-pattern"></div>
    <div class="container position-relative">
        <div class="coc-hero-badge">
            <i class="fas fa-gavel"></i>
            Prime University
        </div>
        <h1>Code of <span>Conduct</span></h1>
        <p>Our commitment to integrity, respect, and excellence. These guidelines uphold the standards that make Prime University a place of learning and growth for everyone.</p>

        <?php if ($sections): ?>
        <div class="coc-hero-stats">
            <?php foreach ($sections as $sec):
                $shortTitle = htmlspecialchars(preg_replace('/\s+Code of Conduct|\s+Member\s+/i', '', $sec['title']), ENT_QUOTES, 'UTF-8');
            ?>
            <div class="stat">
                <strong><?= count($sec['items']) ?>+</strong>
                <span><?= $shortTitle ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<?php if ($sections): ?>
<!-- ══ Tab Nav ══ -->
<div class="coc-tabs-wrap">
    <div class="coc-tabs" role="tablist">
        <?php foreach ($sections as $i => $sec):
            $tabLabel = htmlspecialchars(preg_replace('/\s*Code of Conduct/i', '', $sec['title']), ENT_QUOTES, 'UTF-8');
        ?>
        <button class="coc-tab-btn <?= $i === 0 ? 'active' : '' ?>"
                data-target="coc-<?= htmlspecialchars($sec['section_key'], ENT_QUOTES, 'UTF-8') ?>"
                role="tab"
                aria-selected="<?= $i === 0 ? 'true' : 'false' ?>">
            <span class="tab-icon"><i class="<?= htmlspecialchars($sec['icon'], ENT_QUOTES, 'UTF-8') ?>"></i></span>
            <?= $tabLabel ?>
        </button>
        <?php endforeach; ?>
    </div>
</div>

<!-- ══ Content Sections ══ -->
<div class="coc-main">
    <?php foreach ($sections as $i => $sec):
        $key = htmlspecialchars($sec['section_key'], ENT_QUOTES, 'UTF-8');
        $iconClass = match($sec['section_key']) {
            'student' => 'student',
            'faculty' => 'faculty',
            'staff'   => 'staff',
            default   => 'default',
        };
    ?>
    <div class="coc-section <?= $i === 0 ? 'active' : '' ?>"
         id="coc-<?= $key ?>"
         data-key="<?= $key ?>">
        <div class="container">

            <!-- Section header -->
            <div class="coc-section-header">
                <div class="coc-section-icon-wrap <?= $iconClass ?>">
                    <i class="<?= htmlspecialchars($sec['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                </div>
                <div>
                    <h2><?= htmlspecialchars($sec['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <?php if ($sec['subtitle']): ?>
                    <div class="subtitle"><?= htmlspecialchars($sec['subtitle'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Intro text -->
            <?php if ($sec['intro_text']): ?>
            <div class="coc-intro">
                <p><?= nl2br(htmlspecialchars($sec['intro_text'], ENT_QUOTES, 'UTF-8')) ?></p>
            </div>
            <?php endif; ?>

            <!-- Conduct items -->
            <?php if ($sec['items']): ?>
            <?php $headingText = $sec['section_key'] === 'student'
                ? 'The following shall be considered as offences'
                : 'The following shall constitute misconduct'; ?>
            <div class="coc-items-heading">
                <i class="fas fa-list-ul" style="color:#4f8ef7;"></i>
                <?= $headingText ?>
            </div>
            <ul class="coc-list">
                <?php foreach ($sec['items'] as $n => $item): ?>
                <li class="coc-list-item" data-num="<?= $n + 1 ?>">
                    <?= htmlspecialchars($item, ENT_QUOTES, 'UTF-8') ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php else: ?>
<div class="py-5 text-center text-muted">
    <i class="fas fa-gavel fa-3x mb-3 d-block opacity-25"></i>
    <p>Code of Conduct content is being updated. Please check back soon.</p>
</div>
<?php endif; ?>

<!-- ══ Footer ══ -->
<?php include __DIR__ . '/includes/footer.php'; ?>
<?php include __DIR__ . '/includes/scripts.php'; ?>

<script>
(function () {
    // ── Tab switching ──
    var tabBtns    = document.querySelectorAll('.coc-tab-btn');
    var sections   = document.querySelectorAll('.coc-section');

    tabBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = btn.getAttribute('data-target');

            tabBtns.forEach(function (b) {
                b.classList.remove('active');
                b.setAttribute('aria-selected', 'false');
            });
            btn.classList.add('active');
            btn.setAttribute('aria-selected', 'true');

            sections.forEach(function (s) {
                s.classList.remove('active');
            });

            var targetSection = document.getElementById(target);
            if (targetSection) {
                targetSection.classList.add('active');
                // Re-trigger animations for newly revealed items
                var items = targetSection.querySelectorAll('.coc-list-item');
                items.forEach(function (item) {
                    item.classList.remove('coc-visible');
                });
                observeItems(items);

                // Smooth scroll to content on mobile
                if (window.innerWidth < 768) {
                    setTimeout(function () {
                        targetSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }, 50);
                }
            }
        });
    });

    // ── Intersection Observer for staggered reveal ──
    function observeItems(items) {
        if (!('IntersectionObserver' in window)) {
            items.forEach(function (item) { item.classList.add('coc-visible'); });
            return;
        }
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('coc-visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.08, rootMargin: '0px 0px -30px 0px' });

        items.forEach(function (item, idx) {
            item.style.transitionDelay = Math.min(idx * 40, 400) + 'ms';
            observer.observe(item);
        });
    }

    // Observe items in the initially visible section
    var initialSection = document.querySelector('.coc-section.active');
    if (initialSection) {
        observeItems(initialSection.querySelectorAll('.coc-list-item'));
    }
})();
</script>

</body>
</html>
