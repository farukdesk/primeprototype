<?php
/**
 * SEO Module – Shared Helpers
 */

// ── Settings cache ────────────────────────────────────────────────────────────
function seo_settings(): array {
    static $s = null;
    if ($s === null) {
        $rows = db()->query('SELECT setting_key, setting_value FROM seo_settings')->fetchAll();
        $s = [];
        foreach ($rows as $r) {
            $s[$r['setting_key']] = $r['setting_value'];
        }
    }
    return $s;
}

function seo_setting(string $key, string $default = ''): string {
    return seo_settings()[$key] ?? $default;
}

function seo_save_setting(string $key, string $value): void {
    $stmt = db()->prepare(
        'INSERT INTO seo_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([$key, $value]);
}

// ── Entry helpers ─────────────────────────────────────────────────────────────

/**
 * Get (or create) an SEO entry by URL.
 */
function seo_get_by_url(string $url): ?array {
    $stmt = db()->prepare('SELECT * FROM seo_entries WHERE page_url = ? LIMIT 1');
    $stmt->execute([$url]);
    return $stmt->fetch() ?: null;
}

function seo_get(int $id): ?array {
    $stmt = db()->prepare('SELECT * FROM seo_entries WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Build the effective <title> tag value.
 */
function seo_build_title(string $page_title, ?string $override = null): string {
    if ($override !== null && $override !== '') {
        return $override;
    }
    $fmt  = seo_setting('title_format', '{page_title} {sep} {site_name}');
    $sep  = seo_setting('title_separator', '–');
    $site = seo_setting('site_name', 'Prime University');
    return str_replace(
        ['{page_title}', '{sep}', '{site_name}'],
        [$page_title,    $sep,    $site],
        $fmt
    );
}

/**
 * Collect all discoverable pages from the database for the SEO index.
 * Returns an array of ['page_type', 'page_id', 'page_url', 'page_label', 'updated_at']
 */
function seo_discover_pages(): array {
    $pages = [];

    // Home page
    $pages[] = [
        'page_type'  => 'home',
        'page_id'    => null,
        'page_url'   => '/index.php',
        'page_label' => 'Home Page',
        'source_updated_at' => null,
    ];

    // Static pages
    $statics = [
        ['url' => '/contact.php',              'label' => 'Contact Us'],
        ['url' => '/admission.php',            'label' => 'Admissions'],
        ['url' => '/apply-now.php',            'label' => 'Apply Now'],
        ['url' => '/gallery.php',              'label' => 'Gallery'],
        ['url' => '/library.php',              'label' => 'Library'],
        ['url' => '/notice-board.php',         'label' => 'Notice Board'],
        ['url' => '/course-fees-calculator.php','label' => 'Course Fees Calculator'],
        ['url' => '/mission-and-vision.php',   'label' => 'Mission & Vision'],
        ['url' => '/pu-at-a-glance.php',       'label' => 'PU at a Glance'],
        ['url' => '/deans.php',                'label' => 'Deans'],
        ['url' => '/scholarships-waivers.php', 'label' => 'Scholarships & Waivers'],
        ['url' => '/jobs.php',                 'label' => 'Jobs / Careers'],
        ['url' => '/clubs.php',                'label' => 'Clubs'],
        ['url' => '/board-of-trustees.php',    'label' => 'Board of Trustees'],
        ['url' => '/code-of-conduct.php',      'label' => 'Code of Conduct'],
        ['url' => '/policy-page.php',          'label' => 'Privacy Policy'],
    ];
    foreach ($statics as $s) {
        $pages[] = [
            'page_type'  => 'static',
            'page_id'    => null,
            'page_url'   => $s['url'],
            'page_label' => $s['label'],
            'source_updated_at' => null,
        ];
    }

    // CMS Pages (pages table)
    try {
        $rows = db()->query(
            "SELECT id, title, slug, category, updated_at FROM pages WHERE is_published = 1 ORDER BY title"
        )->fetchAll();
        foreach ($rows as $r) {
            $pages[] = [
                'page_type'  => 'page',
                'page_id'    => (int)$r['id'],
                'page_url'   => '/page.php?slug=' . urlencode($r['slug']),
                'page_label' => $r['title'] . ' (Page – ' . ucfirst($r['category']) . ')',
                'source_updated_at' => $r['updated_at'],
            ];
        }
    } catch (Throwable $e) {}

    // Departments
    try {
        $rows = db()->query(
            "SELECT id, name, slug, updated_at FROM dept_departments WHERE is_active = 1 ORDER BY name"
        )->fetchAll();
        foreach ($rows as $r) {
            $pages[] = [
                'page_type'  => 'department',
                'page_id'    => (int)$r['id'],
                'page_url'   => '/department.php?slug=' . urlencode($r['slug']),
                'page_label' => $r['name'] . ' (Department)',
                'source_updated_at' => $r['updated_at'] ?? null,
            ];
        }
    } catch (Throwable $e) {}

    // Faculty Profiles
    try {
        $rows = db()->query(
            "SELECT id, full_name, slug, updated_at FROM faculty_profiles WHERE is_active = 1 ORDER BY full_name"
        )->fetchAll();
        foreach ($rows as $r) {
            $pages[] = [
                'page_type'  => 'faculty',
                'page_id'    => (int)$r['id'],
                'page_url'   => '/faculty-profile.php?slug=' . urlencode($r['slug']),
                'page_label' => $r['full_name'] . ' (Faculty)',
                'source_updated_at' => $r['updated_at'] ?? null,
            ];
        }
    } catch (Throwable $e) {}

    // News
    try {
        $rows = db()->query(
            "SELECT id, title, slug, published_at FROM cms_news WHERE is_published = 1 ORDER BY published_at DESC LIMIT 200"
        )->fetchAll();
        foreach ($rows as $r) {
            $pages[] = [
                'page_type'  => 'news',
                'page_id'    => (int)$r['id'],
                'page_url'   => '/news-detail.php?slug=' . urlencode($r['slug']),
                'page_label' => mb_substr($r['title'], 0, 80) . ' (News)',
                'source_updated_at' => $r['published_at'] ?? null,
            ];
        }
    } catch (Throwable $e) {}

    // Notices
    try {
        $rows = db()->query(
            "SELECT id, title, slug, published_at FROM cms_notices WHERE is_published = 1 ORDER BY published_at DESC LIMIT 200"
        )->fetchAll();
        foreach ($rows as $r) {
            $pages[] = [
                'page_type'  => 'notice',
                'page_id'    => (int)$r['id'],
                'page_url'   => '/notice-detail.php?slug=' . urlencode($r['slug']),
                'page_label' => mb_substr($r['title'], 0, 80) . ' (Notice)',
                'source_updated_at' => $r['published_at'] ?? null,
            ];
        }
    } catch (Throwable $e) {}

    // Jobs
    try {
        $rows = db()->query(
            "SELECT id, title, slug, updated_at FROM job_postings WHERE is_active = 1 ORDER BY created_at DESC LIMIT 100"
        )->fetchAll();
        foreach ($rows as $r) {
            $pages[] = [
                'page_type'  => 'job',
                'page_id'    => (int)$r['id'],
                'page_url'   => '/job-detail.php?slug=' . urlencode($r['slug']),
                'page_label' => mb_substr($r['title'], 0, 80) . ' (Job)',
                'source_updated_at' => $r['updated_at'] ?? null,
            ];
        }
    } catch (Throwable $e) {}

    // Clubs
    try {
        $rows = db()->query(
            "SELECT id, name, slug, updated_at FROM clubs WHERE is_active = 1 ORDER BY name"
        )->fetchAll();
        foreach ($rows as $r) {
            $pages[] = [
                'page_type'  => 'club',
                'page_id'    => (int)$r['id'],
                'page_url'   => '/club-detail.php?slug=' . urlencode($r['slug']),
                'page_label' => $r['name'] . ' (Club)',
                'source_updated_at' => $r['updated_at'] ?? null,
            ];
        }
    } catch (Throwable $e) {}

    return $pages;
}

/**
 * Get existing seo_entries keyed by page_url for fast lookup.
 */
function seo_existing_entries(): array {
    $rows = db()->query('SELECT * FROM seo_entries')->fetchAll();
    $map  = [];
    foreach ($rows as $r) {
        $map[$r['page_url']] = $r;
    }
    return $map;
}

/**
 * Save/update an SEO entry by ID.
 * Returns the entry id.
 */
function seo_save(array $data): int {
    $user_id = auth_user()['id'] ?? null;

    if (!empty($data['id'])) {
        $stmt = db()->prepare(
            'UPDATE seo_entries SET
               page_label        = :page_label,
               meta_title        = :meta_title,
               meta_description  = :meta_description,
               meta_keywords     = :meta_keywords,
               og_title          = :og_title,
               og_description    = :og_description,
               og_image          = :og_image,
               canonical_url     = :canonical_url,
               robots            = :robots,
               schema_json       = :schema_json,
               custom_head_code  = :custom_head_code,
               sitemap_include   = :sitemap_include,
               sitemap_priority  = :sitemap_priority,
               sitemap_changefreq= :sitemap_changefreq,
               is_active         = :is_active,
               updated_by        = :updated_by
             WHERE id = :id'
        );
        $stmt->execute([
            ':id'               => (int)$data['id'],
            ':page_label'       => $data['page_label']        ?? '',
            ':meta_title'       => $data['meta_title']        ?: null,
            ':meta_description' => $data['meta_description']  ?: null,
            ':meta_keywords'    => $data['meta_keywords']     ?: null,
            ':og_title'         => $data['og_title']          ?: null,
            ':og_description'   => $data['og_description']    ?: null,
            ':og_image'         => $data['og_image']          ?: null,
            ':canonical_url'    => $data['canonical_url']     ?: null,
            ':robots'           => $data['robots']            ?: 'index,follow',
            ':schema_json'      => $data['schema_json']       ?: null,
            ':custom_head_code' => $data['custom_head_code']  ?: null,
            ':sitemap_include'  => (int)($data['sitemap_include']  ?? 1),
            ':sitemap_priority' => (float)($data['sitemap_priority'] ?? 0.5),
            ':sitemap_changefreq' => $data['sitemap_changefreq'] ?? 'weekly',
            ':is_active'        => (int)($data['is_active']   ?? 1),
            ':updated_by'       => $user_id,
        ]);
        return (int)$data['id'];
    }

    // Insert new
    $stmt = db()->prepare(
        'INSERT INTO seo_entries
           (page_type, page_id, page_url, page_label,
            meta_title, meta_description, meta_keywords,
            og_title, og_description, og_image,
            canonical_url, robots, schema_json, custom_head_code,
            sitemap_include, sitemap_priority, sitemap_changefreq,
            is_active, updated_by)
         VALUES
           (:page_type, :page_id, :page_url, :page_label,
            :meta_title, :meta_description, :meta_keywords,
            :og_title, :og_description, :og_image,
            :canonical_url, :robots, :schema_json, :custom_head_code,
            :sitemap_include, :sitemap_priority, :sitemap_changefreq,
            :is_active, :updated_by)
         ON DUPLICATE KEY UPDATE
            page_label        = VALUES(page_label),
            page_type         = VALUES(page_type),
            page_id           = VALUES(page_id),
            updated_by        = VALUES(updated_by)'
    );
    $stmt->execute([
        ':page_type'        => $data['page_type']         ?? 'static',
        ':page_id'          => $data['page_id']           ?: null,
        ':page_url'         => $data['page_url']          ?? '',
        ':page_label'       => $data['page_label']        ?? '',
        ':meta_title'       => $data['meta_title']        ?: null,
        ':meta_description' => $data['meta_description']  ?: null,
        ':meta_keywords'    => $data['meta_keywords']     ?: null,
        ':og_title'         => $data['og_title']          ?: null,
        ':og_description'   => $data['og_description']    ?: null,
        ':og_image'         => $data['og_image']          ?: null,
        ':canonical_url'    => $data['canonical_url']     ?: null,
        ':robots'           => $data['robots']            ?: 'index,follow',
        ':schema_json'      => $data['schema_json']       ?: null,
        ':custom_head_code' => $data['custom_head_code']  ?: null,
        ':sitemap_include'  => (int)($data['sitemap_include']  ?? 1),
        ':sitemap_priority' => (float)($data['sitemap_priority'] ?? 0.5),
        ':sitemap_changefreq' => $data['sitemap_changefreq'] ?? 'weekly',
        ':is_active'        => (int)($data['is_active']   ?? 1),
        ':updated_by'       => $user_id,
    ]);
    return (int)db()->lastInsertId();
}

/**
 * Ensure an seo_entries row exists for the given page; create a stub if not.
 * Returns the entry id.
 */
function seo_ensure_entry(string $page_type, ?int $page_id, string $page_url, string $page_label): int {
    $stmt = db()->prepare('SELECT id FROM seo_entries WHERE page_url = ? LIMIT 1');
    $stmt->execute([$page_url]);
    $row = $stmt->fetch();
    if ($row) return (int)$row['id'];

    return seo_save([
        'page_type'  => $page_type,
        'page_id'    => $page_id,
        'page_url'   => $page_url,
        'page_label' => $page_label,
    ]);
}

/**
 * Robots options for the UI.
 */
function seo_robots_options(): array {
    return [
        'index,follow'      => 'index, follow (default)',
        'noindex,follow'    => 'noindex, follow',
        'index,nofollow'    => 'index, nofollow',
        'noindex,nofollow'  => 'noindex, nofollow',
    ];
}

/**
 * Changefreq options for the UI.
 */
function seo_changefreq_options(): array {
    return ['always','hourly','daily','weekly','monthly','yearly','never'];
}
