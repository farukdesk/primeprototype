<?php
/**
 * Public SEO Helper
 * Provides get_seo() and render_seo_meta() for front-end pages.
 */

/**
 * Load all seo_settings into an array (cached per request).
 */
function seo_pub_settings(): array {
    static $s = null;
    if ($s !== null) return $s;
    try {
        $db = front_db();
        if (!$db) return [];
        $rows = $db->query('SELECT setting_key, setting_value FROM seo_settings')->fetchAll();
        $s = [];
        foreach ($rows as $r) {
            $s[$r['setting_key']] = $r['setting_value'];
        }
    } catch (Throwable $e) {
        $s = [];
    }
    return $s;
}

function seo_pub_setting(string $key, string $default = ''): string {
    return seo_pub_settings()[$key] ?? $default;
}

/**
 * Retrieve the SEO entry for a given page URL (relative, e.g. /department.php?slug=cse).
 * Falls back to defaults from seo_settings.
 *
 * @param  string $page_url  Relative URL of the current page.
 * @return array             Associative array with all SEO fields merged with defaults.
 */
function get_seo(string $page_url): array {
    static $cache = [];
    if (isset($cache[$page_url])) return $cache[$page_url];

    $entry = [];
    try {
        $db = front_db();
        if ($db) {
            $st = $db->prepare('SELECT * FROM seo_entries WHERE page_url = ? AND is_active = 1 LIMIT 1');
            $st->execute([$page_url]);
            $row = $st->fetch();
            if ($row) $entry = $row;
        }
    } catch (Throwable $e) {}

    $s = seo_pub_settings();

    $site_name = $s['site_name']       ?? 'Prime University';
    $sep       = $s['title_separator'] ?? '–';
    $fmt       = $s['title_format']    ?? '{page_title} {sep} {site_name}';
    $def_desc  = $s['default_meta_description'] ?? '';
    $def_kw    = $s['default_meta_keywords']    ?? '';
    $def_img   = $s['default_og_image']         ?? '/assets/img/logo/logo-black.png';

    // Page title from entry, or derive from page_label, or fallback
    $page_label = $entry['page_label'] ?? '';
    if (!empty($entry['meta_title'])) {
        $final_title = str_replace(
            ['{page_title}', '{sep}', '{site_name}'],
            [$entry['meta_title'], $sep, $site_name],
            $fmt
        );
    } elseif ($page_label !== '') {
        $final_title = str_replace(
            ['{page_title}', '{sep}', '{site_name}'],
            [$page_label, $sep, $site_name],
            $fmt
        );
    } else {
        $final_title = $site_name;
    }

    $result = [
        'meta_title'        => $final_title,
        'meta_description'  => $entry['meta_description']  ?: $def_desc,
        'meta_keywords'     => $entry['meta_keywords']     ?: $def_kw,
        'og_title'          => $entry['og_title']          ?: $final_title,
        'og_description'    => $entry['og_description']    ?: ($entry['meta_description'] ?: $def_desc),
        'og_image'          => $entry['og_image']          ?: $def_img,
        'canonical_url'     => $entry['canonical_url']     ?: null,
        'robots'            => $entry['robots']            ?? 'index,follow',
        'schema_json'       => $entry['schema_json']       ?? null,
        'custom_head_code'  => $entry['custom_head_code']  ?? null,
        'og_type'           => $s['og_type_default']       ?? 'website',
        'twitter_card'      => $s['twitter_card_type']     ?? 'summary_large_image',
        'twitter_site'      => $s['twitter_site']          ?? '',
        'fb_app_id'         => $s['fb_app_id']             ?? '',
        'google_analytics_id'    => $s['google_analytics_id']    ?? '',
        'google_search_console'  => $s['google_search_console']  ?? '',
        'site_name'         => $site_name,
    ];

    $cache[$page_url] = $result;
    return $result;
}

/**
 * Render all SEO meta tags for the current page into <head>.
 *
 * @param string      $page_url      Relative URL of this page (e.g. '/department.php?slug=cse')
 * @param string|null $fallback_title Optional title override (e.g. the dynamic page title already computed)
 * @param string|null $fallback_desc  Optional description override
 * @param string|null $fallback_image Optional image override (for news/faculty photos etc.)
 */
function render_seo_meta(
    string  $page_url,
    ?string $fallback_title = null,
    ?string $fallback_desc  = null,
    ?string $fallback_image = null
): void {
    $seo = get_seo($page_url);
    $s   = seo_pub_settings();

    $site_name = $seo['site_name'];
    $sep       = $s['title_separator'] ?? '–';
    $fmt       = $s['title_format']    ?? '{page_title} {sep} {site_name}';

    // Title: SEO entry first, then fallback
    $meta_title = $seo['meta_title'];
    if ($fallback_title && $seo['meta_title'] === $site_name) {
        // No SEO title was set; use the provided fallback
        $meta_title = str_replace(
            ['{page_title}', '{sep}', '{site_name}'],
            [$fallback_title, $sep, $site_name],
            $fmt
        );
    }

    $meta_desc  = $seo['meta_description'] ?: ($fallback_desc ?? '');
    $og_image   = $seo['og_image'] ?: ($fallback_image ?? '');
    if ($og_image && strpos($og_image, 'http') !== 0) {
        $og_image = (defined('SITE_URL') ? SITE_URL : '') . $og_image;
    }

    $og_title  = $seo['og_title']       ?: $meta_title;
    $og_desc   = $seo['og_description'] ?: $meta_desc;
    $canonical = $seo['canonical_url']  ?: ((defined('SITE_URL') ? SITE_URL : '') . $page_url);

    $page_url_encoded = htmlspecialchars($canonical, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    echo "\n    <!-- SEO Meta Tags -->\n";
    echo '    <title>' . $e($meta_title) . "</title>\n";
    echo '    <meta name="description" content="' . $e(mb_substr($meta_desc, 0, 500)) . "\">\n";
    if ($seo['meta_keywords']) {
        echo '    <meta name="keywords" content="' . $e($seo['meta_keywords']) . "\">\n";
    }
    echo '    <meta name="robots" content="' . $e($seo['robots']) . "\">\n";
    echo '    <link rel="canonical" href="' . $page_url_encoded . "\">\n";

    // Google Search Console verification
    if ($seo['google_search_console']) {
        echo '    <meta name="google-site-verification" content="' . $e($seo['google_search_console']) . "\">\n";
    }

    // Open Graph
    echo "\n    <!-- Open Graph -->\n";
    echo '    <meta property="og:type" content="'        . $e($seo['og_type'])   . "\">\n";
    echo '    <meta property="og:title" content="'       . $e($og_title)         . "\">\n";
    echo '    <meta property="og:description" content="' . $e(mb_substr($og_desc, 0, 300)) . "\">\n";
    echo '    <meta property="og:url" content="'         . $page_url_encoded     . "\">\n";
    echo '    <meta property="og:site_name" content="'   . $e($site_name)        . "\">\n";
    if ($og_image) {
        echo '    <meta property="og:image" content="'   . $e($og_image)         . "\">\n";
        echo "    <meta property=\"og:image:width\" content=\"1200\">\n";
        echo "    <meta property=\"og:image:height\" content=\"630\">\n";
    }
    if ($seo['fb_app_id']) {
        echo '    <meta property="fb:app_id" content="'  . $e($seo['fb_app_id']) . "\">\n";
    }

    // Twitter Card
    echo "\n    <!-- Twitter Card -->\n";
    echo '    <meta name="twitter:card" content="'        . $e($seo['twitter_card']) . "\">\n";
    echo '    <meta name="twitter:title" content="'       . $e($og_title)            . "\">\n";
    echo '    <meta name="twitter:description" content="' . $e(mb_substr($og_desc, 0, 200)) . "\">\n";
    if ($og_image) {
        echo '    <meta name="twitter:image" content="'   . $e($og_image)            . "\">\n";
    }
    if ($seo['twitter_site']) {
        $tw = '@' . ltrim($seo['twitter_site'], '@');
        echo '    <meta name="twitter:site" content="'    . $e($tw)                  . "\">\n";
    }

    // JSON-LD Structured Data
    if ($seo['schema_json']) {
        echo "\n    <!-- Structured Data -->\n";
        echo "    <script type=\"application/ld+json\">\n";
        echo $seo['schema_json'];
        echo "\n    </script>\n";
    }

    // Custom head code
    if ($seo['custom_head_code']) {
        echo "\n    <!-- Custom SEO Head Code -->\n";
        echo $seo['custom_head_code'] . "\n";
    }

    // Google Analytics
    if ($seo['google_analytics_id']) {
        $gaid = $e($seo['google_analytics_id']);
        echo "\n    <!-- Google Analytics -->\n";
        if (strpos($seo['google_analytics_id'], 'G-') === 0) {
            // GA4
            echo "    <script async src=\"https://www.googletagmanager.com/gtag/js?id={$gaid}\"></script>\n";
            echo "    <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config','{$gaid}');</script>\n";
        } else {
            // Universal Analytics
            echo "    <script>(function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){\n";
            echo "    (i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),\n";
            echo "    m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)\n";
            echo "    })(window,document,'script','https://www.google-analytics.com/analytics.js','ga');\n";
            echo "    ga('create','{$gaid}','auto');ga('send','pageview');</script>\n";
        }
    }
}
