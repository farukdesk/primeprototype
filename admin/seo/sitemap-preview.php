<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('seo');
require_once __DIR__ . '/helpers.php';

$page_title = 'Sitemap Preview';

// Build sitemap entries
$base_url  = rtrim(seo_setting('sitemap_base_url', SITE_URL), '/');
$all_pages = seo_discover_pages();
$existing  = seo_existing_entries();

$sitemap_rows = [];
foreach ($all_pages as $pg) {
    $entry  = $existing[$pg['page_url']] ?? null;
    $include = $entry ? (int)$entry['sitemap_include'] : 1;
    if (!$include) continue;

    $priority   = $entry['sitemap_priority']   ?? (($pg['page_type'] === 'home') ? '1.0' : '0.5');
    $changefreq = $entry['sitemap_changefreq'] ?? (($pg['page_type'] === 'home') ? 'daily' : 'weekly');
    $lastmod    = $pg['source_updated_at'] ?? $entry['updated_at'] ?? null;

    $sitemap_rows[] = [
        'url'        => $base_url . $pg['page_url'],
        'lastmod'    => $lastmod ? date('Y-m-d', strtotime($lastmod)) : date('Y-m-d'),
        'changefreq' => $changefreq,
        'priority'   => number_format((float)$priority, 1),
        'label'      => $pg['page_label'],
        'has_seo'    => $entry && (!empty($entry['meta_title']) || !empty($entry['meta_description'])),
        'excluded'   => false,
    ];
}

// Add entries from DB that aren't in discovered
foreach ($existing as $url => $entry) {
    if (!(int)$entry['sitemap_include']) continue;
    $found = false;
    foreach ($all_pages as $pg) {
        if ($pg['page_url'] === $url) { $found = true; break; }
    }
    if (!$found) {
        $sitemap_rows[] = [
            'url'        => $base_url . $url,
            'lastmod'    => $entry['updated_at'] ? date('Y-m-d', strtotime($entry['updated_at'])) : date('Y-m-d'),
            'changefreq' => $entry['sitemap_changefreq'] ?? 'weekly',
            'priority'   => number_format((float)($entry['sitemap_priority'] ?? 0.5), 1),
            'label'      => $entry['page_label'],
            'has_seo'    => !empty($entry['meta_title']) || !empty($entry['meta_description']),
            'excluded'   => false,
        ];
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/seo/index.php">SEO Manager</a></li>
            <li class="breadcrumb-item active">Sitemap Preview</li>
        </ol>
    </nav>
    <div class="d-flex gap-2">
        <a href="<?= SITE_URL ?>/sitemap.php" target="_blank" class="btn btn-success btn-sm" style="border-radius:8px;">
            <i class="fas fa-external-link-alt me-1"></i> Live XML Sitemap
        </a>
    </div>
</div>

<?php flash_show(); ?>

<div class="alert alert-info py-2 px-3 mb-4" style="border-radius:8px;font-size:.875rem;">
    <i class="fas fa-info-circle me-1"></i>
    This preview shows all pages that will appear in the live sitemap at
    <a href="<?= SITE_URL ?>/sitemap.php" target="_blank"><code><?= SITE_URL ?>/sitemap.php</code></a>.
    To exclude a page or change its priority, click <strong>Edit SEO</strong> for that page.
    <strong><?= count($sitemap_rows) ?></strong> URL<?= count($sitemap_rows) !== 1 ? 's' : '' ?> included.
</div>

<div class="card" style="border-radius:12px;">
    <div class="card-header" style="background:#fff;border-bottom:1px solid #f0f0f0;padding:14px 20px;">
        <span style="font-weight:600;font-size:.875rem;color:#374151;">
            <i class="fas fa-sitemap me-2" style="color:#4f8ef7;"></i>
            Sitemap Entries (<?= count($sitemap_rows) ?>)
        </span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size:.85rem;">
            <thead style="background:#f9fafb;border-bottom:2px solid #f0f0f0;">
                <tr>
                    <th style="padding:12px 16px;font-weight:600;color:#6b7280;font-size:.75rem;text-transform:uppercase;">#</th>
                    <th style="padding:12px 16px;font-weight:600;color:#6b7280;font-size:.75rem;text-transform:uppercase;">URL</th>
                    <th style="padding:12px 16px;font-weight:600;color:#6b7280;font-size:.75rem;text-transform:uppercase;">Priority</th>
                    <th style="padding:12px 16px;font-weight:600;color:#6b7280;font-size:.75rem;text-transform:uppercase;">Changefreq</th>
                    <th style="padding:12px 16px;font-weight:600;color:#6b7280;font-size:.75rem;text-transform:uppercase;">Last Modified</th>
                    <th style="padding:12px 16px;font-weight:600;color:#6b7280;font-size:.75rem;text-transform:uppercase;">SEO</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($sitemap_rows as $i => $row): ?>
            <tr>
                <td style="padding:10px 16px;color:#9ca3af;"><?= $i + 1 ?></td>
                <td style="padding:10px 16px;max-width:380px;">
                    <div style="font-weight:500;color:#1a2e5a;"><?= h($row['label']) ?></div>
                    <a href="<?= h($row['url']) ?>" target="_blank" style="font-size:.75rem;color:#2563eb;font-family:monospace;text-decoration:none;">
                        <?= h(mb_strlen($row['url']) > 80 ? mb_substr($row['url'], 0, 80) . '…' : $row['url']) ?>
                    </a>
                </td>
                <td style="padding:10px 16px;">
                    <?php
                    $p = (float)$row['priority'];
                    $pcolor = $p >= 0.8 ? '#16a34a' : ($p >= 0.5 ? '#d97706' : '#6b7280');
                    ?>
                    <span style="font-weight:700;color:<?= $pcolor ?>;"><?= h($row['priority']) ?></span>
                </td>
                <td style="padding:10px 16px;color:#374151;"><?= h(ucfirst($row['changefreq'])) ?></td>
                <td style="padding:10px 16px;color:#6b7280;"><?= h($row['lastmod']) ?></td>
                <td style="padding:10px 16px;">
                    <?php if ($row['has_seo']): ?>
                    <span style="color:#16a34a;font-size:.75rem;"><i class="fas fa-check-circle"></i></span>
                    <?php else: ?>
                    <span style="color:#f59e0b;font-size:.75rem;"><i class="fas fa-exclamation-circle"></i></span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
