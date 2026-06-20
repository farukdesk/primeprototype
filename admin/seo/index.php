<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('seo');
require_once __DIR__ . '/helpers.php';

$page_title = 'SEO Manager';

// ── Filters ──────────────────────────────────────────────────────────────────
$search    = trim($_GET['search'] ?? '');
$type_filter = $_GET['type'] ?? '';
$seo_filter  = $_GET['seo']  ?? '';  // '' | 'filled' | 'empty'

// Discover all pages
$all_pages = seo_discover_pages();
$existing  = seo_existing_entries();

// Merge existing data into discovered pages
$merged = [];
foreach ($all_pages as $pg) {
    $entry = $existing[$pg['page_url']] ?? null;
    $merged[] = array_merge($pg, [
        'seo_id'          => $entry['id']               ?? null,
        'meta_title'      => $entry['meta_title']        ?? null,
        'meta_description'=> $entry['meta_description']  ?? null,
        'robots'          => $entry['robots']            ?? null,
        'sitemap_include' => $entry['sitemap_include']   ?? 1,
        'is_active'       => $entry['is_active']         ?? 1,
        'updated_at'      => $entry['updated_at']        ?? null,
    ]);
}

// Also include any entries saved in DB not discovered above
foreach ($existing as $url => $entry) {
    $found = false;
    foreach ($merged as $m) {
        if ($m['page_url'] === $url) { $found = true; break; }
    }
    if (!$found) {
        $merged[] = [
            'page_type'  => $entry['page_type'],
            'page_id'    => $entry['page_id'],
            'page_url'   => $entry['page_url'],
            'page_label' => $entry['page_label'],
            'source_updated_at' => null,
            'seo_id'          => $entry['id'],
            'meta_title'      => $entry['meta_title'],
            'meta_description'=> $entry['meta_description'],
            'robots'          => $entry['robots'],
            'sitemap_include' => $entry['sitemap_include'],
            'is_active'       => $entry['is_active'],
            'updated_at'      => $entry['updated_at'],
        ];
    }
}

// Apply filters
if ($search !== '') {
    $lc = mb_strtolower($search);
    $merged = array_filter($merged, fn($m) =>
        str_contains(mb_strtolower($m['page_label']), $lc) ||
        str_contains(mb_strtolower($m['page_url']), $lc)
    );
}
if ($type_filter !== '') {
    $merged = array_filter($merged, fn($m) => $m['page_type'] === $type_filter);
}
if ($seo_filter === 'filled') {
    $merged = array_filter($merged, fn($m) => !empty($m['meta_title']) || !empty($m['meta_description']));
} elseif ($seo_filter === 'empty') {
    $merged = array_filter($merged, fn($m) => empty($m['meta_title']) && empty($m['meta_description']));
}
$merged = array_values($merged);

$total_pages   = count(seo_discover_pages());
$filled_count  = 0;
$all_entries   = seo_discover_pages();
$entry_map     = seo_existing_entries();
foreach ($all_entries as $pg) {
    $e = $entry_map[$pg['page_url']] ?? null;
    if ($e && (!empty($e['meta_title']) || !empty($e['meta_description']))) $filled_count++;
}
$empty_count = $total_pages - $filled_count;

$page_types = [
    'home'       => 'Home',
    'static'     => 'Static',
    'page'       => 'CMS Page',
    'department' => 'Department',
    'faculty'    => 'Faculty',
    'news'       => 'News',
    'notice'     => 'Notice',
    'job'        => 'Job',
    'club'       => 'Club',
];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">SEO Manager</li>
        </ol>
    </nav>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/seo/settings.php" class="btn btn-outline-secondary btn-sm" style="border-radius:8px;">
            <i class="fas fa-cog me-1"></i> SEO Settings
        </a>
        <a href="<?= APP_URL ?>/seo/sitemap-preview.php" class="btn btn-outline-info btn-sm" style="border-radius:8px;">
            <i class="fas fa-sitemap me-1"></i> Sitemap Preview
        </a>
        <a href="<?= SITE_URL ?>/sitemap.php" target="_blank" class="btn btn-outline-success btn-sm" style="border-radius:8px;">
            <i class="fas fa-external-link-alt me-1"></i> Live Sitemap
        </a>
    </div>
</div>

<?php flash_show(); ?>

<!-- Stats cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card border-0 shadow-sm" style="border-radius:12px;">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div style="width:44px;height:44px;border-radius:10px;background:#eff6ff;display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-file-alt" style="color:#2563eb;font-size:1.1rem;"></i>
                </div>
                <div>
                    <div style="font-size:1.4rem;font-weight:700;color:#1a2e5a;line-height:1;"><?= $total_pages ?></div>
                    <div style="font-size:.78rem;color:#6b7280;">Total Pages</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card border-0 shadow-sm" style="border-radius:12px;">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div style="width:44px;height:44px;border-radius:10px;background:#f0fdf4;display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-check-circle" style="color:#16a34a;font-size:1.1rem;"></i>
                </div>
                <div>
                    <div style="font-size:1.4rem;font-weight:700;color:#1a2e5a;line-height:1;"><?= $filled_count ?></div>
                    <div style="font-size:.78rem;color:#6b7280;">SEO Optimised</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card border-0 shadow-sm" style="border-radius:12px;">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div style="width:44px;height:44px;border-radius:10px;background:#fff7ed;display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-exclamation-circle" style="color:#ea580c;font-size:1.1rem;"></i>
                </div>
                <div>
                    <div style="font-size:1.4rem;font-weight:700;color:#1a2e5a;line-height:1;"><?= $empty_count ?></div>
                    <div style="font-size:.78rem;color:#6b7280;">Needs SEO</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filter bar -->
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-body py-3 px-4">
        <form method="GET" class="d-flex gap-3 flex-wrap align-items-end">
            <div>
                <label class="form-label mb-1" style="font-size:.78rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">Search</label>
                <input type="text" name="search" class="form-control form-control-sm" style="min-width:220px;border-radius:8px;"
                       placeholder="Page name or URL…" value="<?= h($search) ?>">
            </div>
            <div>
                <label class="form-label mb-1" style="font-size:.78rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">Type</label>
                <select name="type" class="form-select form-select-sm" style="border-radius:8px;">
                    <option value="">All Types</option>
                    <?php foreach ($page_types as $k => $v): ?>
                    <option value="<?= h($k) ?>" <?= $type_filter === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label mb-1" style="font-size:.78rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">SEO Status</label>
                <select name="seo" class="form-select form-select-sm" style="border-radius:8px;">
                    <option value="">All</option>
                    <option value="filled"  <?= $seo_filter === 'filled'  ? 'selected' : '' ?>>Optimised</option>
                    <option value="empty"   <?= $seo_filter === 'empty'   ? 'selected' : '' ?>>Needs SEO</option>
                </select>
            </div>
            <div>
                <button class="btn btn-outline-primary btn-sm" style="border-radius:8px;padding:7px 18px;">
                    <i class="fas fa-search me-1"></i> Filter
                </button>
                <?php if ($search !== '' || $type_filter !== '' || $seo_filter !== ''): ?>
                <a href="<?= APP_URL ?>/seo/index.php" class="btn btn-outline-secondary btn-sm ms-1" style="border-radius:8px;padding:7px 14px;">
                    <i class="fas fa-times me-1"></i> Clear
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Pages table -->
<div class="card" style="border-radius:12px;">
    <div class="card-header d-flex justify-content-between align-items-center" style="border-radius:12px 12px 0 0;background:#fff;border-bottom:1px solid #f0f0f0;padding:16px 20px;">
        <span style="font-weight:600;font-size:.9rem;color:#374151;">
            <?= count($merged) ?> page<?= count($merged) !== 1 ? 's' : '' ?>
        </span>
    </div>
    <?php if (empty($merged)): ?>
    <div class="card-body text-center py-5">
        <i class="fas fa-search" style="font-size:2.5rem;color:#d1d5db;display:block;margin-bottom:12px;"></i>
        <p class="mb-0" style="color:#9ca3af;">No pages found matching your filters.</p>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size:.875rem;">
            <thead style="background:#f9fafb;border-bottom:2px solid #f0f0f0;">
                <tr>
                    <th style="padding:12px 16px;font-weight:600;color:#6b7280;font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;">Page</th>
                    <th style="padding:12px 16px;font-weight:600;color:#6b7280;font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;">Type</th>
                    <th style="padding:12px 16px;font-weight:600;color:#6b7280;font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;">SEO Status</th>
                    <th style="padding:12px 16px;font-weight:600;color:#6b7280;font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;">Meta Title</th>
                    <th style="padding:12px 16px;font-weight:600;color:#6b7280;font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;">Last Updated</th>
                    <th style="padding:12px 16px;font-weight:600;color:#6b7280;font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($merged as $pg): ?>
            <?php
            $has_seo   = !empty($pg['meta_title']) || !empty($pg['meta_description']);
            $type_label = $page_types[$pg['page_type']] ?? ucfirst($pg['page_type']);
            $type_color = [
                'home' => '#7c3aed', 'static' => '#0891b2', 'page' => '#2563eb',
                'department' => '#16a34a', 'faculty' => '#ea580c',
                'news' => '#d97706', 'notice' => '#dc2626',
                'job' => '#9333ea', 'club' => '#0d9488',
            ][$pg['page_type']] ?? '#6b7280';
            ?>
            <tr>
                <td style="padding:14px 16px;max-width:280px;">
                    <div style="font-weight:600;color:#1a2e5a;" title="<?= h($pg['page_label']) ?>">
                        <?= h(mb_strlen($pg['page_label']) > 55 ? mb_substr($pg['page_label'], 0, 55) . '…' : $pg['page_label']) ?>
                    </div>
                    <div style="font-size:.78rem;color:#9ca3af;font-family:monospace;">
                        <?= h(mb_strlen($pg['page_url']) > 60 ? mb_substr($pg['page_url'], 0, 60) . '…' : $pg['page_url']) ?>
                    </div>
                </td>
                <td style="padding:14px 16px;">
                    <span style="background:<?= $type_color ?>20;color:<?= $type_color ?>;border-radius:50px;padding:3px 10px;font-size:.75rem;font-weight:600;">
                        <?= h($type_label) ?>
                    </span>
                </td>
                <td style="padding:14px 16px;">
                    <?php if ($has_seo): ?>
                    <span class="badge" style="background:#f0fdf4;color:#16a34a;border-radius:50px;padding:5px 12px;font-size:.75rem;font-weight:600;">
                        <i class="fas fa-check me-1"></i> Optimised
                    </span>
                    <?php else: ?>
                    <span class="badge" style="background:#fff7ed;color:#ea580c;border-radius:50px;padding:5px 12px;font-size:.75rem;font-weight:600;">
                        <i class="fas fa-exclamation me-1"></i> Needs SEO
                    </span>
                    <?php endif; ?>
                </td>
                <td style="padding:14px 16px;max-width:220px;">
                    <?php if (!empty($pg['meta_title'])): ?>
                    <span style="color:#374151;" title="<?= h($pg['meta_title']) ?>">
                        <?= h(mb_strlen($pg['meta_title']) > 45 ? mb_substr($pg['meta_title'], 0, 45) . '…' : $pg['meta_title']) ?>
                    </span>
                    <?php else: ?>
                    <span style="color:#d1d5db;font-style:italic;">—</span>
                    <?php endif; ?>
                </td>
                <td style="padding:14px 16px;white-space:nowrap;color:#6b7280;font-size:.82rem;">
                    <?php if ($pg['updated_at']): ?>
                    <?= date('d M Y', strtotime($pg['updated_at'])) ?>
                    <?php else: ?>
                    <span style="color:#d1d5db;">—</span>
                    <?php endif; ?>
                </td>
                <td style="padding:14px 16px;">
                    <div class="d-flex gap-2 align-items-center">
                        <?php
                        // Build edit URL: ensure entry exists
                        if (!empty($pg['seo_id'])) {
                            $edit_url = APP_URL . '/seo/edit.php?id=' . $pg['seo_id'];
                        } else {
                            $edit_url = APP_URL . '/seo/edit.php?page_type=' . urlencode($pg['page_type'])
                                      . '&page_id=' . urlencode((string)($pg['page_id'] ?? ''))
                                      . '&page_url=' . urlencode($pg['page_url'])
                                      . '&page_label=' . urlencode($pg['page_label']);
                        }
                        ?>
                        <a href="<?= h($edit_url) ?>"
                           class="btn btn-sm btn-outline-primary" style="border-radius:8px;font-size:.8rem;"
                           title="Edit SEO">
                            <i class="fas fa-search-plus me-1"></i> SEO
                        </a>
                        <a href="<?= h(SITE_URL . $pg['page_url']) ?>" target="_blank"
                           class="btn btn-sm btn-outline-secondary" style="border-radius:8px;font-size:.8rem;"
                           title="View live page">
                            <i class="fas fa-external-link-alt"></i>
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
