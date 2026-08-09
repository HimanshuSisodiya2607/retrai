<?php
/**
 * Public restaurant profile — /r/<slug>
 * No login. This is the page we want Google to index and rank, so it
 * carries full Restaurant + Menu structured data.
 */
require_once __DIR__ . '/database/db.php';
require_once __DIR__ . '/includes/directory.php';

$slug = trim($_GET['slug'] ?? '');
$r = null;

if ($slug !== '') {
    $stmt = mysqli_prepare($conn, "
        SELECT restro_key, restaurant_name, slug, tagline, cuisine, city, city_slug,
               address, phone, opening_time, closing_time
        FROM restaurants
        WHERE slug = ? AND is_active = 1 AND is_listed = 1
    ");
    mysqli_stmt_bind_param($stmt, 's', $slug);
    mysqli_stmt_execute($stmt);
    $r = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
}

if (!$r) {
    http_response_code(404);
    $canonical = site_base_url() . '/restaurants';
    ?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<link rel="icon" type="image/png" href="assets/logo-icon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php seo_head(['title' => 'Restaurant not found — Dinetous', 'description' => 'This restaurant page is not available.', 'noindex' => true]); ?>
    <link rel="stylesheet" href="<?php echo site_base_url(); ?>/assets/public.css"></head>
    <body><div id="glow"></div><div class="wrap"><div class="empty-state" style="margin-top:80px;">
    We couldn't find that restaurant. <a href="<?php echo htmlspecialchars($canonical); ?>">Browse all restaurants →</a>
    </div></div></body></html><?php
    exit;
}

// Menu, grouped by category.
$stmt = mysqli_prepare($conn, "
    SELECT c.name AS category, c.sort_order AS cat_sort,
           i.item_key, i.name, i.emoji, i.description, i.photo_url, i.price, i.glb_url
    FROM categories c
    JOIN items i ON i.category_key = c.category_key AND i.restro_key = c.restro_key
    WHERE c.restro_key = ? AND i.is_active = 1
    ORDER BY c.sort_order, i.sort_order, i.name
");
mysqli_stmt_bind_param($stmt, 's', $r['restro_key']);
mysqli_stmt_execute($stmt);
$groups = [];
$total_items = 0;
$ar_count = 0;
$first_photo = null;
$res = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($res)) {
    $groups[$row['category']][] = $row;
    $total_items++;
    if (!empty($row['glb_url'])) $ar_count++;
    if (!$first_photo && !empty($row['photo_url'])) $first_photo = $row['photo_url'];
}
mysqli_stmt_close($stmt);

$canonical = restaurant_url($r['slug']);
$base = site_base_url();
$city = $r['city'] ?: '';
$cuisine = $r['cuisine'] ?: '';

// Name first — it's what people scan for — then cuisine and city, which
// are the words they actually searched.
$title = seo_title(trim($r['restaurant_name'] . ($cuisine || $city
    ? ' — ' . trim($cuisine . ($city ? ' in ' . $city : ''))
    : '')));
$description = seo_description($r['tagline']
    ?: trim(sprintf(
        '%s is a %s restaurant%s. See the full menu with prices%s, opening hours and contact details.',
        $r['restaurant_name'],
        $cuisine ?: 'dine-in',
        $city ? ' in ' . $city : '',
        $ar_count > 0 ? ', plus ' . $ar_count . ' dishes viewable in 3D AR' : ''
    )));

// ---- structured data -------------------------------------------------
$menu_sections = [];
foreach ($groups as $cat => $items) {
    $menu_items = [];
    foreach ($items as $it) {
        $mi = ['@type' => 'MenuItem', 'name' => $it['name']];
        if (!empty($it['description'])) $mi['description'] = $it['description'];
        if (!empty($it['photo_url'])) $mi['image'] = $base . '/' . $it['photo_url'];
        $mi['offers'] = ['@type' => 'Offer', 'price' => number_format((float) $it['price'], 2, '.', ''), 'priceCurrency' => 'INR'];
        $menu_items[] = $mi;
    }
    $menu_sections[] = ['@type' => 'MenuSection', 'name' => $cat, 'hasMenuItem' => $menu_items];
}

$restaurant_ld = [
    '@context' => 'https://schema.org',
    '@type' => 'Restaurant',
    'name' => $r['restaurant_name'],
    'url' => $canonical,
    '@id' => $canonical,
];
if ($description) $restaurant_ld['description'] = $description;
if ($cuisine) $restaurant_ld['servesCuisine'] = $cuisine;
if ($r['phone']) $restaurant_ld['telephone'] = $r['phone'];
if ($first_photo) $restaurant_ld['image'] = $base . '/' . $first_photo;

$addr = ['@type' => 'PostalAddress', 'addressCountry' => 'IN'];
if ($r['address']) $addr['streetAddress'] = $r['address'];
if ($city) $addr['addressLocality'] = $city;
$restaurant_ld['address'] = $addr;

$open = schema_time($r['opening_time']);
$close = schema_time($r['closing_time']);
if ($open && $close) {
    $restaurant_ld['openingHoursSpecification'] = [[
        '@type' => 'OpeningHoursSpecification',
        'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
        'opens' => $open,
        'closes' => $close,
    ]];
}
if ($menu_sections) {
    $restaurant_ld['hasMenu'] = ['@type' => 'Menu', 'hasMenuSection' => $menu_sections];
}

$crumbs = [
    ['name' => 'Restaurants', 'item' => city_url()],
];
if ($r['city_slug']) {
    $crumbs[] = ['name' => $city, 'item' => city_url($r['city_slug'])];
}
$crumbs[] = ['name' => $r['restaurant_name'], 'item' => $canonical];

$breadcrumb_ld = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => []];
foreach ($crumbs as $i => $c) {
    $breadcrumb_ld['itemListElement'][] = [
        '@type' => 'ListItem', 'position' => $i + 1, 'name' => $c['name'], 'item' => $c['item'],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php seo_head([
    'title' => $title,
    'description' => $description,
    'canonical' => $canonical,
    'og_type' => 'business.business',
    'image' => $first_photo ? $base . '/' . $first_photo : '',
    'jsonld' => [$restaurant_ld, $breadcrumb_ld],
]); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?php echo $base; ?>/assets/public.css?v=<?php echo @filemtime(__DIR__ . '/assets/public.css'); ?>">
<?php if ($ar_count > 0): ?>
<script type="module" src="https://unpkg.com/@google/model-viewer@3.4.0/dist/model-viewer.min.js"></script>
<?php endif; ?>
</head>
<body>
<div id="glow"></div>

<header class="site-head">
  <div class="wrap head-row">
    <a href="<?php echo $base; ?>/" class="logo"><img src="<?php echo $base; ?>/assets/logo.png" alt="Dinetous" class="logo-img"></a>
    <div style="display:flex;gap:9px;">
      <a href="<?php echo htmlspecialchars(city_url()); ?>" class="btn btn-ghost">All restaurants</a>
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
    <div class="profile-top">
      <div>
        <?php if ($cuisine || $city): ?>
          <div class="eyebrow"><?php echo htmlspecialchars(trim($cuisine . ($city ? ' · ' . $city : ''), ' ·')); ?></div>
        <?php endif; ?>
        <h1><?php echo htmlspecialchars($r['restaurant_name']); ?></h1>
        <?php if (!empty($r['tagline'])): ?>
          <p class="lede"><?php echo htmlspecialchars($r['tagline']); ?></p>
        <?php endif; ?>

        <div class="fact-list">
          <?php if (!empty($r['address'])): ?>
            <div class="fact"><span class="k">Address</span><span><?php echo htmlspecialchars($r['address']); ?><?php echo $city ? ', ' . htmlspecialchars($city) : ''; ?></span></div>
          <?php elseif ($city): ?>
            <div class="fact"><span class="k">City</span><span><?php echo htmlspecialchars($city); ?></span></div>
          <?php endif; ?>
          <?php if (!empty($r['phone'])): ?>
            <div class="fact"><span class="k">Phone</span><a href="tel:<?php echo htmlspecialchars(preg_replace('/\s+/', '', $r['phone'])); ?>" style="color:var(--text-hi);"><?php echo htmlspecialchars($r['phone']); ?></a></div>
          <?php endif; ?>
          <?php if ($r['opening_time'] && $r['closing_time']): ?>
            <div class="fact"><span class="k">Hours</span><span><?php echo htmlspecialchars($r['opening_time'] . ' – ' . $r['closing_time']); ?></span></div>
          <?php endif; ?>
          <?php if ($total_items): ?>
            <div class="fact"><span class="k">Menu</span><span><?php echo $total_items; ?> dishes<?php echo $ar_count ? ' · ' . $ar_count . ' viewable in 3D AR' : ''; ?></span></div>
          <?php endif; ?>
        </div>

        <div class="profile-actions">
          <?php if (!empty($r['phone'])): ?>
            <a href="tel:<?php echo htmlspecialchars(preg_replace('/\s+/', '', $r['phone'])); ?>" class="btn btn-primary">Call restaurant</a>
          <?php endif; ?>
          <?php if (!empty($r['address'])): ?>
            <a href="https://www.google.com/maps/search/<?php echo rawurlencode($r['restaurant_name'] . ' ' . $r['address'] . ' ' . $city); ?>" target="_blank" rel="noopener nofollow" class="btn btn-ghost">Get directions ↗</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?php if (!$groups): ?>
    <div class="empty-state">This restaurant hasn't published its menu yet.</div>
  <?php else: ?>
    <?php foreach ($groups as $cat => $items): ?>
      <section class="menu-section">
        <h2><?php echo htmlspecialchars($cat); ?> <span class="n"><?php echo count($items); ?></span></h2>
        <div class="dish-grid">
          <?php foreach ($items as $d): ?>
            <article class="dish">
              <div class="dish-photo">
                <?php if (!empty($d['photo_url'])): ?>
                  <img src="<?php echo htmlspecialchars($base . '/' . $d['photo_url']); ?>" alt="<?php echo htmlspecialchars($d['name']); ?>" loading="lazy" width="400" height="290">
                <?php else: ?>
                  <?php echo htmlspecialchars($d['emoji'] ?: '🍽'); ?>
                <?php endif; ?>
              </div>
              <div class="dish-info">
                <h3><?php echo htmlspecialchars($d['name']); ?></h3>
                <?php if (!empty($d['description'])): ?>
                  <p><?php echo htmlspecialchars($d['description']); ?></p>
                <?php endif; ?>
                <div class="row">
                  <span class="price">₹<?php echo number_format((float) $d['price']); ?></span>
                  <?php if (!empty($d['glb_url'])): ?>
                    <button type="button" class="ar-open"
                            data-glb="<?php echo htmlspecialchars($base . '/' . $d['glb_url'], ENT_QUOTES); ?>"
                            data-name="<?php echo htmlspecialchars($d['name'], ENT_QUOTES); ?>">View in 3D</button>
                  <?php endif; ?>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php if ($ar_count > 0): ?>
<div class="ar-modal" id="arModal">
  <div class="ar-box">
    <header>
      <h3 id="arTitle">Dish</h3>
      <button type="button" class="x" id="arClose" aria-label="Close">✕</button>
    </header>
    <model-viewer id="arViewer" camera-controls auto-rotate auto-rotate-delay="0"
                  shadow-intensity="1.1" exposure="1.1" environment-image="neutral"
                  ar ar-modes="webxr scene-viewer quick-look" touch-action="pan-y"
                  interaction-prompt="none" alt="3D preview"></model-viewer>
  </div>
</div>
<?php endif; ?>

<footer>
  <div class="wrap foot-row">
    <div class="logo"><img src="<?php echo $base; ?>/assets/logo.png" alt="Dinetous" class="logo-img"></div>
    <div class="foot-links">
      <a href="<?php echo htmlspecialchars(city_url()); ?>">All restaurants</a>
      <?php if ($r['city_slug']): ?><a href="<?php echo htmlspecialchars(city_url($r['city_slug'])); ?>">Restaurants in <?php echo htmlspecialchars($city); ?></a><?php endif; ?>
      <a href="<?php echo $base; ?>/onboarding.php">List your restaurant</a>
    </div>
    <div class="fine">© <?php echo date('Y'); ?> Dinetous</div>
  </div>
</footer>

<?php if ($ar_count > 0): ?>
<script>
(function(){
  var modal = document.getElementById('arModal');
  var viewer = document.getElementById('arViewer');
  var title = document.getElementById('arTitle');
  function close(){ modal.classList.remove('open'); viewer.removeAttribute('src'); }
  document.querySelectorAll('.ar-open').forEach(function(b){
    b.addEventListener('click', function(){
      title.textContent = b.dataset.name;
      viewer.setAttribute('src', b.dataset.glb);
      modal.classList.add('open');
    });
  });
  document.getElementById('arClose').addEventListener('click', close);
  modal.addEventListener('click', function(e){ if(e.target === modal) close(); });
  document.addEventListener('keydown', function(e){ if(e.key === 'Escape') close(); });
})();
</script>
<?php endif; ?>
</body>
</html>
