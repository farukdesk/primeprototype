<?php
/**
 * Admin Layout – HTML <head> + top navbar open
 * $page_title should be set before including this file.
 */
$page_title = $page_title ?? APP_NAME;
$user       = auth_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($page_title) ?> | <?= h(APP_NAME) ?></title>

    <!-- Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --sidebar-width: 260px;
            --sidebar-bg:    #1a1f36;
            --sidebar-text:  #a8b2d8;
            --sidebar-hover: #252d4a;
            --sidebar-active:#4f8ef7;
            --topbar-h:      60px;
            --accent:        #4f8ef7;
        }

        * { box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: #f4f6fb;
            margin: 0;
            color: #333;
        }

        /* ── Sidebar ── */
        #sidebar {
            position: fixed;
            top: 0; left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: var(--sidebar-bg);
            overflow-y: auto;
            z-index: 1040;
            transition: transform .25s ease;
        }

        #sidebar .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 18px 20px;
            border-bottom: 1px solid rgba(255,255,255,.06);
            text-decoration: none;
        }
        #sidebar .brand img { width: 38px; border-radius: 8px; }
        #sidebar .brand span {
            color: #fff;
            font-weight: 700;
            font-size: .95rem;
            line-height: 1.2;
        }

        #sidebar .nav-label {
            font-size: .68rem;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #556;
            padding: 18px 20px 6px;
        }

        #sidebar .nav-item a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 20px;
            color: var(--sidebar-text);
            text-decoration: none;
            font-size: .875rem;
            border-left: 3px solid transparent;
            transition: background .15s, color .15s;
        }
        #sidebar .nav-item a i { width: 18px; text-align: center; }
        #sidebar .nav-item a:hover,
        #sidebar .nav-item a.active {
            background: var(--sidebar-hover);
            color: #fff;
            border-left-color: var(--sidebar-active);
        }

        /* ── Main ── */
        #main-wrapper {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ── Topbar ── */
        #topbar {
            position: sticky;
            top: 0;
            z-index: 1030;
            height: var(--topbar-h);
            background: #fff;
            border-bottom: 1px solid #e8eaf0;
            display: flex;
            align-items: center;
            padding: 0 24px;
            gap: 12px;
        }
        #topbar .toggle-btn {
            background: none;
            border: none;
            font-size: 1.25rem;
            color: #555;
            cursor: pointer;
            display: none;
        }
        #topbar .page-heading {
            font-size: 1rem;
            font-weight: 600;
            color: #1a1f36;
            flex: 1;
        }
        #topbar .user-menu .dropdown-toggle {
            background: none;
            border: none;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: .875rem;
            color: #333;
            cursor: pointer;
        }
        #topbar .user-menu .avatar {
            width: 34px; height: 34px;
            border-radius: 50%;
            background: var(--accent);
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-weight: 600;
            font-size: .85rem;
        }

        /* ── Content ── */
        #content {
            flex: 1;
            padding: 28px 28px 40px;
        }

        /* ── Cards ── */
        .stat-card {
            border: none;
            border-radius: 12px;
            padding: 22px 24px;
            color: #fff;
            transition: transform .2s;
        }
        .stat-card:hover { transform: translateY(-3px); }
        .stat-card .stat-icon {
            font-size: 2rem;
            opacity: .8;
        }
        .stat-card .stat-val {
            font-size: 2rem;
            font-weight: 700;
        }
        .stat-card .stat-label {
            font-size: .8rem;
            opacity: .85;
        }

        .card { border: none; border-radius: 12px; box-shadow: 0 1px 6px rgba(0,0,0,.06); }
        .card-header { border-bottom: 1px solid #f0f2f7; background: #fff; border-radius: 12px 12px 0 0 !important; }

        /* ── Tables ── */
        .table th { font-weight: 600; font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; }
        .table td { vertical-align: middle; font-size: .875rem; }

        /* ── Badges ── */
        .badge-super { background: linear-gradient(135deg,#f5a623,#e07b00); color:#fff; }

        /* ── Alerts ── */
        .alert { border: none; border-radius: 10px; font-size: .875rem; }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            #sidebar { transform: translateX(-100%); }
            #sidebar.open { transform: translateX(0); }
            #main-wrapper { margin-left: 0; }
            #topbar .toggle-btn { display: block; }
        }
    </style>
</head>
<body>

<!-- ═══════════════════════ SIDEBAR ═══════════════════════ -->
<nav id="sidebar">
    <a href="<?= APP_URL ?>/index.php" class="brand">
        <img src="<?= APP_URL ?>/../assets/img/logo/favicon.png" alt="PU" onerror="this.style.display='none'">
        <span>Prime University<br><small style="font-weight:400;font-size:.7rem;opacity:.7">Admin Panel</small></span>
    </a>

    <p class="nav-label">Main</p>
    <ul class="nav flex-column">
        <li class="nav-item">
            <a href="<?= APP_URL ?>/index.php"
               class="<?= preg_match('#/admin/index\.php$#', $_SERVER['PHP_SELF']) ? 'active' : '' ?>">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
        </li>
    </ul>

    <?php if (is_super_admin() || can_access('users')): ?>
    <p class="nav-label">User Management</p>
    <ul class="nav flex-column">
        <?php if (is_super_admin() || can_access('user-groups')): ?>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/user-groups/index.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/user-groups/') !== false ? 'active' : '' ?>">
                <i class="fas fa-layer-group"></i> User Groups
            </a>
        </li>
        <?php endif; ?>
        <?php if (is_super_admin() || can_access('users')): ?>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/users/index.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/users/') !== false ? 'active' : '' ?>">
                <i class="fas fa-users"></i> Users
            </a>
        </li>
        <?php endif; ?>
    </ul>
    <?php endif; ?>

    <?php if (is_super_admin() || can_access('modules') || can_access('access') || can_access('email-templates')): ?>
    <p class="nav-label">System</p>
    <ul class="nav flex-column">
        <?php if (is_super_admin() || can_access('modules')): ?>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/modules/index.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/modules/') !== false ? 'active' : '' ?>">
                <i class="fas fa-cubes"></i> Modules
            </a>
        </li>
        <?php endif; ?>
        <?php if (is_super_admin() || can_access('access')): ?>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/access/index.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/access/') !== false ? 'active' : '' ?>">
                <i class="fas fa-shield-alt"></i> Module Access
            </a>
        </li>
        <?php endif; ?>
        <?php if (is_super_admin() || can_access('email-templates')): ?>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/email-templates/index.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/email-templates/') !== false ? 'active' : '' ?>">
                <i class="fas fa-envelope-open-text"></i> Email Templates
            </a>
        </li>
        <?php endif; ?>
    </ul>
    <?php endif; ?>

    <div style="padding: 20px; margin-top: auto;">
        <a href="<?= APP_URL ?>/logout.php"
           style="display:flex;align-items:center;gap:8px;color:#e74c3c;font-size:.85rem;text-decoration:none;">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>
</nav>

<!-- ═══════════════════════ MAIN ═══════════════════════ -->
<div id="main-wrapper">

    <!-- Topbar -->
    <header id="topbar">
        <button class="toggle-btn" onclick="document.getElementById('sidebar').classList.toggle('open')">
            <i class="fas fa-bars"></i>
        </button>
        <div class="page-heading"><?= h($page_title) ?></div>

        <div class="user-menu dropdown">
            <button class="dropdown-toggle" data-bs-toggle="dropdown">
                <div class="avatar"><?= strtoupper(substr($user['full_name'] ?? 'A', 0, 1)) ?></div>
                <span><?= h($user['full_name'] ?? 'Admin') ?></span>
                <i class="fas fa-chevron-down" style="font-size:.7rem;opacity:.6"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="border-radius:12px;font-size:.875rem;">
                <li><a class="dropdown-item" href="<?= APP_URL ?>/users/edit.php?id=<?= $user['id'] ?>">
                    <i class="fas fa-user-edit me-2 text-muted"></i>My Profile</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="<?= APP_URL ?>/logout.php">
                    <i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
            </ul>
        </div>
    </header>

    <!-- Flash messages -->
    <div style="padding: 0 28px; margin-top: 16px;">
    <?php
    foreach (['success','error','warning','info'] as $t):
        $msg = flash_get($t);
        if ($msg):
    ?>
    <div class="alert alert-<?= $t === 'error' ? 'danger' : h($t) ?> alert-dismissible fade show" role="alert">
        <?= strip_tags($msg, '<strong><em><b><i>') ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; endforeach; ?>
    </div>

    <!-- Content starts here -->
    <main id="content">
