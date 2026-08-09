<?php
/**
 * Public directory — /restaurants and /restaurants/<city-slug>
 * The city page is the one that targets "restaurants in <city>" queries,
 * so it carries an ItemList of the listings plus breadcrumbs.
 */
require_once __DIR__ . '/database/db.php';
require_once __DIR__ . '/includes/directory.php';

$base = site_base_url();
$city_slug = slugify(trim($_GET['city'] ?? ''));
$q = trim($_GET['q'] ?? '');

// Cities that actually have listings — never advertise an empty city page.
$city_rows = [];
$res = mysqli_query($conn, "
    SELECT city, city_slug, COUNT(*) AS n
    FROM restaurants
    WHERE is_active = 1 AND is_listed = 1 AND city_slug IS NOT NULL AND city_slug <> ''
    GROUP BY city, city_slug
    ORDER BY n DESC, city ASC
");
while ($row = mysqli_fetch_assoc($res)) {
    $city_rows[$row['city_slug']] = $row;
}

$city_name = $city_slug !== '' && isset($city_rows[$city_slug]) ? $city_rows[$city_slug]['city'] : null;
$unknown_city = $city_slug !== '' && $city_name === null;

// A city we have no listings for isn't a real page — 404 it so crawlers
// don't burn budget on an unbounded set of invented city URLs.
if ($unknown_city) {
    http_response_code(404);
}

// Listings
$sql = "
    SELECT r.restaurant_name, r.slug, r.tagline, r.cuisine, r.city, r.city_slug,
           (SELECT COUNT(*) FROM items i WHERE i.restro_key = r.restro_key AND i.is_active = 1) AS item_count,
           (SELECT COUNT(*) FROM items i WHERE i.restro_key = r.restro_key AND i.is_active = 1 AND i.glb_url IS NOT NULL AND i.glb_url <> '') AS ar_count,
           (SELECT i.photo_url FROM items i WHERE i.restro_key = r.restro_key AND i.is_active = 1 AND i.photo_url IS NOT NULL LIMIT 1) AS cover
    FROM restaurants r
    WHERE r.is_active = 1 AND r.is_listed = 1 AND r.slug IS NOT NULL
";
$params = [];
$types = '';
if ($city_slug !== '' && !$unknown_city) {
    $sql .= " AND r.city_slug = ?";
    $params[] = $city_slug;
    $types .= 's';
}
if ($q !== '') {
    $sql .= " AND (r.restaurant_name LIKE ? OR r.cuisine LIKE ? OR r.city LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
    $types .= 'sss';
}
$sql .= " ORDER BY r.restaurant_name ASC";

$stmt = mysqli_prepare($conn, $sql);
if ($params) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$listings = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$total = count($listings);

// ---- page metadata ---------------------------------------------------
if ($city_name) {
    $canonical = city_url($city_slug);
    $h1 = 'Restaurants in ' . $city_name;
    $title = seo_title('Restaurants in ' . $city_name . ' — Menus & Prices');
    $lede = 'Browse ' . $total . ' restaurant' . ($total === 1 ? '' : 's') . ' in ' . $city_name
          . ' on Dinetous. See full menus with live prices, opening hours and contact details — and preview dishes in 3D before you go.';
    $description = seo_description('Find restaurants in ' . $city_name
        . '. Browse full menus with prices, opening hours and phone numbers, and preview dishes in 3D AR before you go.');
} else {
    $canonical = city_url();
    $h1 = 'Restaurants on Dinetous';
    $title = seo_title('Restaurant Directory — Menus & Prices by City');
    $lede = 'Every restaurant running on Dinetous, with full menus, live prices and dishes you can preview in 3D augmented reality before you order.';
    $description = seo_description('Browse restaurants by city. Full menus with prices, opening hours and 3D AR dish previews, powered by Dinetous.');
}
// Search results are thin/duplicative — keep them out of the index.
$noindex = ($q !== '') || $unknown_city;

$crumbs = [['name' => 'Restaurants', 'item' => city_url()]];
if ($city_name) {
    $crumbs[] = ['name' => $city_name, 'item' => $canonical];
}
$breadcrumb_ld = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => []];
foreach ($crumbs as $i => $c) {
    $breadcrumb_ld['itemListElement'][] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $c['name'], 'item' => $c['item']];
}

$jsonld = [$breadcrumb_ld];
if ($listings) {
    $items = [];
    foreach ($listings as $i => $l) {
        $items[] = [
            '@type' => 'ListItem',
            'position' => $i + 1,
            'url' => restaurant_url($l['slug']),
            'name' => $l['restaurant_name'],
        ];
    }
    $jsonld[] = [
        '@context' => 'https://schema.org',
        '@type' => 'ItemList',
        'name' => $h1,
        'numberOfItems' => $total,
        'itemListElement' => $items,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/png" href="assets/logo-icon.png">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php seo_head([
    'title' => $title,
    'description' => $description,
    'canonical' => $canonical,
    'noindex' => $noindex,
    'jsonld' => $jsonld,
]); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?php echo $base; ?>/assets/public.css?v=<?php echo @filemtime(__DIR__ . '/assets/public.css'); ?>">
</head>
<body>
<div id="glow"></div>

<header class="site-head">
  <div class="wrap head-row">
    <a href="<?php echo $base; ?>/" class="logo"><img src="<?php echo $base; ?>/assets/logo.png" alt="Dinetous" class="logo-img"></a>
    <div style="display:flex;gap:9px;">
      <a href="<?php echo $base; ?>/Restro/sign-in.php" class="btn btn-ghost">Sign in</a>
      <a href="<?php echo $base; ?>/onboarding.php" class="btn btn-primary">List your restaurant</a>
    </div>
  </div>
</header>

<div class="wrap">
  <nav class="crumbs" aria-label="Breadcrumb">
    <?php foreach ($crumbs as $i => $c): ?>
      <?php if ($i > 0): ?><span>/</span><?php endif; ?>
      <?php if ($i < count($crumbs) - 1): ?>
        <a href="<?php echo htmlspecialchars($c['item']); ?>"><?php echo htmlspecialchars($c['name']); ?></a>
      <?php else: ?>
        <span style="color:var(--text-mid);"><?php echo htmlspecialchars($c['name']); ?></span>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>

  <div class="page-head">
    <div class="eyebrow">Restaurant Directory</div>
    <h1><?php echo htmlspecialchars($h1); ?></h1>
    <p class="lede"><?php echo htmlspecialchars($lede); ?></p>

    <form method="get" action="<?php echo $base; ?>/restaurants.php" style="margin-top:22px;display:flex;gap:9px;flex-wrap:wrap;">
      <?php if ($city_slug !== '' && !$unknown_city): ?>
        <input type="hidden" name="city" value="<?php echo htmlspecialchars($city_slug); ?>">
      <?php endif; ?>
      <input type="search" name="q" value="<?php echo htmlspecialchars($q); ?>"
             placeholder="Search by name, cuisine or city…"
             style="flex:1;min-width:220px;max-width:360px;background:var(--bg-panel);border:1px solid var(--line);border-radius:100px;color:var(--text-hi);padding:11px 18px;font-size:13.5px;font-family:inherit;">
      <button type="submit" class="btn btn-primary">Search</button>
      <?php if ($q !== ''): ?>
        <a href="<?php echo htmlspecialchars($city_name ? city_url($city_slug) : city_url()); ?>" class="btn btn-ghost">Clear</a>
      <?php endif; ?>
    </form>
  </div>

  <?php if ($city_rows): ?>
    <div class="chips">
      <a href="<?php echo htmlspecialchars(city_url()); ?>" class="chip<?php echo $city_name ? '' : ' active'; ?>">All cities</a>
      <?php foreach ($city_rows as $cs => $c): ?>
        <a href="<?php echo htmlspecialchars(city_url($cs)); ?>" class="chip<?php echo $city_slug === $cs ? ' active' : ''; ?>">
          <?php echo htmlspecialchars($c['city']); ?><span class="n"><?php echo (int) $c['n']; ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($unknown_city): ?>
    <div class="empty-state">
      We don't have any restaurants listed in that city yet.
      <a href="<?php echo htmlspecialchars(city_url()); ?>">Browse all cities →</a>
    </div>
  <?php elseif (!$listings): ?>
    <div class="empty-state">
      <?php if ($q !== ''): ?>
        Nothing matched “<?php echo htmlspecialchars($q); ?>”.
        <a href="<?php echo htmlspecialchars(city_url()); ?>">Browse all restaurants →</a>
      <?php else: ?>
        No restaurants listed yet.
        <a href="<?php echo $base; ?>/onboarding.php">List yours first →</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="listing-grid">
      <?php foreach ($listings as $l): ?>
        <a class="listing" href="<?php echo htmlspecialchars(restaurant_url($l['slug'])); ?>">
          <div class="listing-cover">
            <?php if (!empty($l['cover'])): ?>
              <img src="<?php echo htmlspecialchars($base . '/' . $l['cover']); ?>" alt="<?php echo htmlspecialchars($l['restaurant_name']); ?>" loading="lazy" width="420" height="150">
            <?php else: ?>
              <div class="placeholder">🍽</div>
            <?php endif; ?>
          </div>
          <div class="listing-body">
            <h3><?php echo htmlspecialchars($l['restaurant_name']); ?></h3>
            <div class="meta"><?php echo htmlspecialchars(trim(($l['cuisine'] ?: '') . ($l['city'] ? ' · ' . $l['city'] : ''), ' ·')); ?></div>
            <?php if (!empty($l['tagline'])): ?>
              <p class="tagline"><?php echo htmlspecialchars($l['tagline']); ?></p>
            <?php endif; ?>
            <div class="foot">
              <span class="count"><?php echo (int) $l['item_count']; ?> dishes</span>
              <?php if ((int) $l['ar_count'] > 0): ?>
                <span class="badge-ar"><?php echo (int) $l['ar_count']; ?> in 3D AR</span>
              <?php endif; ?>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<footer>
  <div class="wrap foot-row">
    <div class="logo"><img src="<?php echo $base; ?>/assets/logo.png" alt="Dinetous" class="logo-img"></div>
    <div class="foot-links">
      <?php foreach (array_slice($city_rows, 0, 6) as $cs => $c): ?>
        <a href="<?php echo htmlspecialchars(city_url($cs)); ?>">Restaurants in <?php echo htmlspecialchars($c['city']); ?></a>
      <?php endforeach; ?>
    </div>
    <div class="fine">© <?php echo date('Y'); ?> Dinetous</div>
  </div>
</footer>
</body>
</html>
