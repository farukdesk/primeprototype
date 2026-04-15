<?php
/**
 * Dynamic robots.txt
 * Serves the robots.txt content managed from SEO Settings in the admin panel.
 * Route: /robots.php – add a rewrite rule to serve this as /robots.txt if desired.
 */
require_once __DIR__ . '/includes/config.php';

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');

$robots_txt = null;
try {
    $db = front_db();
    if ($db) {
        $st = $db->prepare("SELECT setting_value FROM seo_settings WHERE setting_key = 'robots_txt' LIMIT 1");
        $st->execute();
        $row = $st->fetch();
        if ($row) $robots_txt = $row['setting_value'];
    }
} catch (Throwable $e) {}

if ($robots_txt === null || $robots_txt === '') {
    $robots_txt = "User-agent: *\nAllow: /\nDisallow: /admin/\nSitemap: " . SITE_URL . "/sitemap.php";
}

echo $robots_txt;
