<?php
/**
 * Dynamic XML Sitemap
 * Serves as /sitemap.php – submit this URL to Google Search Console.
 */
require_once __DIR__ . '/includes/config.php';

header('Content-Type: application/xml; charset=utf-8');
header('X-Robots-Tag: noindex');

// ── Helpers ───────────────────────────────────────────────────────────────────
function sm_esc(string $v): string {
    return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

// ── Load settings ─────────────────────────────────────────────────────────────
$settings = [];
try {
    $db = front_db();
    if ($db) {
        $rows = $db->query('SELECT setting_key, setting_value FROM seo_settings')->fetchAll();
        foreach ($rows as $r) {
            $settings[$r['setting_key']] = $r['setting_value'];
        }
    }
} catch (Throwable $e) {}

$base_url = rtrim($settings['sitemap_base_url'] ?? SITE_URL, '/');

// ── Collect URLs ──────────────────────────────────────────────────────────────
$urls = [];

// 1. All active seo_entries with sitemap_include = 1
try {
    if ($db) {
        $rows = $db->query(
            "SELECT page_url, sitemap_priority, sitemap_changefreq, updated_at
             FROM seo_entries WHERE is_active = 1 AND sitemap_include = 1
             ORDER BY sitemap_priority DESC, id ASC"
        )->fetchAll();
        foreach ($rows as $r) {
            $urls[$r['page_url']] = [
                'loc'        => $base_url . $r['page_url'],
                'lastmod'    => $r['updated_at'] ? date('Y-m-d', strtotime($r['updated_at'])) : date('Y-m-d'),
                'changefreq' => $r['sitemap_changefreq'] ?? 'weekly',
                'priority'   => number_format((float)($r['sitemap_priority'] ?? 0.5), 1),
            ];
        }
    }
} catch (Throwable $e) {}

// 2. Auto-discover pages NOT yet in seo_entries
$discovered_types = [
    // [table, slug_col, url_pattern, date_col, priority, changefreq]
    ['dept_departments', 'slug', '/department.php?slug=', 'updated_at', '0.8', 'weekly'],
    ['faculty_profiles', 'slug', '/faculty-profile.php?slug=', 'updated_at', '0.6', 'monthly'],
    ['cms_news',         'slug', '/news-detail.php?slug=', 'published_at', '0.7', 'weekly'],
    ['cms_notices',      'slug', '/notice-detail.php?slug=', 'published_at', '0.6', 'weekly'],
    ['job_postings',     'slug', '/job-detail.php?slug=', 'updated_at', '0.5', 'weekly'],
    ['clubs',            'slug', '/club-detail.php?slug=', 'updated_at', '0.5', 'monthly'],
    ['pages',            'slug', '/page.php?slug=', 'updated_at', '0.6', 'monthly'],
];

foreach ($discovered_types as [$table, $slug_col, $url_prefix, $date_col, $priority, $changefreq]) {
    try {
        if (!$db) break;
        $is_col = ($table === 'dept_departments' || $table === 'faculty_profiles' || $table === 'clubs' || $table === 'job_postings')
            ? 'is_active = 1'
            : 'is_published = 1';
        $rows = $db->query(
            "SELECT {$slug_col}, {$date_col} FROM {$table} WHERE {$is_col} LIMIT 1000"
        )->fetchAll();
        foreach ($rows as $r) {
            $rel_url = $url_prefix . urlencode($r[$slug_col]);
            if (isset($urls[$rel_url])) continue;  // already from seo_entries
            $urls[$rel_url] = [
                'loc'        => $base_url . $rel_url,
                'lastmod'    => $r[$date_col] ? date('Y-m-d', strtotime($r[$date_col])) : date('Y-m-d'),
                'changefreq' => $changefreq,
                'priority'   => $priority,
            ];
        }
    } catch (Throwable $e) {}
}

// 3. Ensure home page is in sitemap
if (!isset($urls['/index.php'])) {
    $urls['/index.php'] = [
        'loc'        => $base_url . '/index.php',
        'lastmod'    => date('Y-m-d'),
        'changefreq' => 'daily',
        'priority'   => '1.0',
    ];
}

// ── Output XML ────────────────────────────────────────────────────────────────
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9
            http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">' . "\n";

foreach ($urls as $url) {
    echo "  <url>\n";
    echo "    <loc>" . sm_esc($url['loc']) . "</loc>\n";
    echo "    <lastmod>" . sm_esc($url['lastmod']) . "</lastmod>\n";
    echo "    <changefreq>" . sm_esc($url['changefreq']) . "</changefreq>\n";
    echo "    <priority>" . sm_esc($url['priority']) . "</priority>\n";
    echo "  </url>\n";
}

echo "</urlset>\n";
