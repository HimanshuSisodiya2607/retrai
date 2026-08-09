<?php
/**
 * XML sitemap, served at /sitemap.xml via .htaccess.
 * Generated from the database so new restaurants and cities are
 * discoverable by search engines the moment they're listed.
 */
require_once __DIR__ . '/database/db.php';
require_once __DIR__ . '/includes/directory.php';

header('Content-Type: application/xml; charset=utf-8');

$base = site_base_url();
$urls = [];

$urls[] = ['loc' => $base . '/', 'priority' => '1.0', 'changefreq' => 'weekly'];
$urls[] = ['loc' => city_url(), 'priority' => '0.9', 'changefreq' => 'daily'];

// City pages that actually have listings.
$res = mysqli_query($conn, "
    SELECT city_slug, MAX(updated_at) AS lastmod
    FROM restaurants
    WHERE is_active = 1 AND is_listed = 1 AND city_slug IS NOT NULL AND city_slug <> ''
    GROUP BY city_slug
    ORDER BY city_slug
");
while ($row = mysqli_fetch_assoc($res)) {
    $urls[] = [
        'loc' => city_url($row['city_slug']),
        'lastmod' => date('Y-m-d', strtotime($row['lastmod'])),
        'priority' => '0.8',
        'changefreq' => 'daily',
    ];
}

// Individual restaurant pages.
$res = mysqli_query($conn, "
    SELECT slug, updated_at
    FROM restaurants
    WHERE is_active = 1 AND is_listed = 1 AND slug IS NOT NULL AND slug <> ''
    ORDER BY restaurant_name
");
while ($row = mysqli_fetch_assoc($res)) {
    $urls[] = [
        'loc' => restaurant_url($row['slug']),
        'lastmod' => date('Y-m-d', strtotime($row['updated_at'])),
        'priority' => '0.7',
        'changefreq' => 'weekly',
    ];
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($u['loc'], ENT_XML1) . "</loc>\n";
    if (!empty($u['lastmod'])) {
        echo '    <lastmod>' . $u['lastmod'] . "</lastmod>\n";
    }
    echo '    <changefreq>' . $u['changefreq'] . "</changefreq>\n";
    echo '    <priority>' . $u['priority'] . "</priority>\n";
    echo "  </url>\n";
}
echo '</urlset>' . "\n";
