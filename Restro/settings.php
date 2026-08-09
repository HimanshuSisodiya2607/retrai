<?php
session_start();
require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/../includes/directory.php';

if (empty($_SESSION['restro_key'])) {
    header('Location: sign-in.php');
    exit;
}

$restro_key = $_SESSION['restro_key'];
session_write_close();

extract(restro_load_nav($conn, $restro_key));
$current_page = 'settings';

$success = '';
$error = '';

// Clear all orders for this restaurant
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['clear_orders'])) {
    $del = mysqli_prepare($conn, "DELETE FROM orders WHERE restro_key = ?");
    mysqli_stmt_bind_param($del, 's', $restro_key);
    mysqli_stmt_execute($del);
    mysqli_stmt_close($del);
    $success = 'All orders cleared for your restaurant.';
}

// Save settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['save_settings'])) {
    $restaurant_name = trim($_POST['restaurant_name'] ?? '');
    $cuisine = trim($_POST['cuisine'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $tagline = mb_substr(trim($_POST['tagline'] ?? ''), 0, 200);
    $address = trim($_POST['address'] ?? '');
    $is_listed = !empty($_POST['is_listed']) ? 1 : 0;
    $opening_time = trim($_POST['opening_time'] ?? '');
    $closing_time = trim($_POST['closing_time'] ?? '');
    $notify_new_orders = !empty($_POST['notify_new_orders']) ? 1 : 0;
    $notify_weekly_digest = !empty($_POST['notify_weekly_digest']) ? 1 : 0;

    if ($restaurant_name === '') {
        $error = 'Restaurant name is required.';
    } else {
        $upd = mysqli_prepare($conn, "
            UPDATE restaurants
            SET restaurant_name = ?, cuisine = ?, city = ?, tagline = ?, address = ?,
                opening_time = ?, closing_time = ?,
                notify_new_orders = ?, notify_weekly_digest = ?, is_listed = ?
            WHERE restro_key = ?
        ");
        mysqli_stmt_bind_param(
            $upd, 'sssssssiiis',
            $restaurant_name, $cuisine, $city, $tagline, $address, $opening_time, $closing_time,
            $notify_new_orders, $notify_weekly_digest, $is_listed, $restro_key
        );
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);

        // Keep the public directory URL and city grouping in step.
        sync_restaurant_directory_fields($conn, $restro_key);

        $_SESSION['restaurant_name'] = $restaurant_name;
        $success = 'Settings saved.';
    }
}

// Load restaurant settings
$stmt = mysqli_prepare($conn, "
    SELECT restaurant_name, owner_name, cuisine, city, tagline, address, opening_time, closing_time,
           notify_new_orders, notify_weekly_digest, slug, is_listed
    FROM restaurants
    WHERE restro_key = ?
");
mysqli_stmt_bind_param($stmt, 's', $restro_key);
mysqli_stmt_execute($stmt);
$settings = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$settings) {
    die('Restaurant not found.');
}

$display_address = $settings['address'] ?? $settings['city'] ?? '';
$display_opening = $settings['opening_time'] ?? '12:00 PM';
$display_closing = $settings['closing_time'] ?? '11:30 PM';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/png" href="../assets/logo-icon.png">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings — Dinetous</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=<?php echo @filemtime(__DIR__ . '/assets/style.css'); ?>">
</head>
<body>

<div id="bg-glow"></div>

<div class="shell">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <div class="main">
    <div class="topbar">
      <div><h1>Settings</h1><div class="sub">Restaurant profile and preferences</div></div>
      <div class="topbar-right">
        <div class="search-box" id="topbarSearchBox">
          <span class="si-icon">🔍</span>
          <input type="search" id="topbarSearch" autocomplete="off" spellcheck="false"
                 placeholder="Search orders, dishes, tables…" aria-label="Search">
          <kbd>/</kbd>
        </div>
      </div>
    </div>

    <div class="content">

      <?php if ($success !== ''): ?>
        <p style="color:#4ade80;font-size:13px;margin-bottom:16px;"><?php echo htmlspecialchars($success); ?></p>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <p style="color:#ff6b6b;font-size:13px;margin-bottom:16px;"><?php echo htmlspecialchars($error); ?></p>
      <?php endif; ?>

      <div class="panel" style="padding:24px 26px;max-width:720px;">
        <form method="post" action="settings.php">
          <input type="hidden" name="save_settings" value="1">

          <div class="settings-section">
            <h3>Restaurant Profile</h3>
            <div class="field-row">
              <div class="field">
                <label for="restaurant_name">Restaurant Name</label>
                <input type="text" id="restaurant_name" name="restaurant_name" required value="<?php echo htmlspecialchars($settings['restaurant_name']); ?>">
              </div>
              <div class="field">
                <label for="cuisine">Cuisine Type</label>
                <input type="text" id="cuisine" name="cuisine" value="<?php echo htmlspecialchars($settings['cuisine'] ?? ''); ?>">
              </div>
            </div>
            <div class="field-row">
              <div class="field">
                <label for="city">City</label>
                <input type="text" id="city" name="city" list="cityOptions" autocomplete="address-level2"
                       placeholder="e.g. Bikaner" value="<?php echo htmlspecialchars($settings['city'] ?? ''); ?>">
                <datalist id="cityOptions">
                  <?php foreach (directory_cities() as $c): ?>
                    <option value="<?php echo htmlspecialchars($c); ?>"></option>
                  <?php endforeach; ?>
                </datalist>
                <div class="field-hint">Decides which city page you appear on in the public directory.</div>
              </div>
              <div class="field">
                <label for="tagline">Tagline</label>
                <input type="text" id="tagline" name="tagline" maxlength="200" placeholder="e.g. Authentic Rajasthani thalis since 1998" value="<?php echo htmlspecialchars($settings['tagline'] ?? ''); ?>">
                <div class="field-hint">One line shown under your name in search results.</div>
              </div>
            </div>
            <div class="field">
              <label for="address">Address</label>
              <input type="text" id="address" name="address" value="<?php echo htmlspecialchars($display_address); ?>">
            </div>
            <div class="field-row">
              <div class="field">
                <label for="opening_time">Opening Time</label>
                <input type="text" id="opening_time" name="opening_time" value="<?php echo htmlspecialchars($display_opening); ?>">
              </div>
              <div class="field">
                <label for="closing_time">Closing Time</label>
                <input type="text" id="closing_time" name="closing_time" value="<?php echo htmlspecialchars($display_closing); ?>">
              </div>
            </div>
          </div>

          <div class="settings-section">
            <h3>Public Listing</h3>
            <label class="toggle-row" style="cursor:pointer;">
              <div>
                <div class="t-label">List us in the Dinetous directory</div>
                <div class="t-sub">Diners searching for restaurants in your city can find you on Google.</div>
              </div>
              <input type="checkbox" name="is_listed" value="1" style="width:18px;height:18px;accent-color:#ff5a1f;"<?php echo (int) ($settings['is_listed'] ?? 1) === 1 ? ' checked' : ''; ?>>
            </label>
            <?php if (!empty($settings['slug'])): ?>
              <div class="toggle-row" style="gap:12px;flex-wrap:wrap;">
                <div style="min-width:0;">
                  <div class="t-label">Your public page</div>
                  <div class="t-sub" style="word-break:break-all;font-family:var(--font-mono);font-size:11.5px;">
                    <?php echo htmlspecialchars(restaurant_url($settings['slug'])); ?>
                  </div>
                </div>
                <a href="<?php echo htmlspecialchars(restaurant_url($settings['slug'])); ?>" target="_blank" rel="noopener" class="btn btn-ghost" style="flex-shrink:0;">View page ↗</a>
              </div>
            <?php endif; ?>
          </div>

          <div class="settings-section">
            <h3>Notifications</h3>
            <label class="toggle-row" style="cursor:pointer;">
              <div><div class="t-label">New order alerts</div><div class="t-sub">Ping when a new order comes in</div></div>
              <input type="checkbox" name="notify_new_orders" value="1" style="width:18px;height:18px;accent-color:#ff5a1f;"<?php echo (int) ($settings['notify_new_orders'] ?? 1) === 1 ? ' checked' : ''; ?>>
            </label>
            <label class="toggle-row" style="cursor:pointer;">
              <div><div class="t-label">Weekly AI insight digest</div><div class="t-sub">Email summary every Monday</div></div>
              <input type="checkbox" name="notify_weekly_digest" value="1" style="width:18px;height:18px;accent-color:#ff5a1f;"<?php echo (int) ($settings['notify_weekly_digest'] ?? 0) === 1 ? ' checked' : ''; ?>>
            </label>
          </div>

          <div class="modal-actions" style="justify-content:flex-start;margin-top:24px;">
            <button type="submit" class="btn btn-primary">Save Changes</button>
          </div>
        </form>

        <div class="settings-section" style="border-bottom:none;margin-bottom:0;margin-top:28px;">
          <h3>Danger Zone</h3>
          <form method="post" action="settings.php" class="toggle-row" onsubmit="return confirm('Clear all orders for this restaurant? This cannot be undone.');">
            <input type="hidden" name="clear_orders" value="1">
            <div><div class="t-label">Clear all orders</div><div class="t-sub">Deletes every order and order line for your restaurant</div></div>
            <button type="submit" class="btn btn-ghost btn-sm">Clear</button>
          </form>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
(function initMobileNav(){
  const sidebar = document.querySelector('.sidebar');
  const topbar = document.querySelector('.topbar');
  if(!sidebar || !topbar) return;
  if(!document.querySelector('.sidebar-backdrop')){
    const backdrop = document.createElement('div');
    backdrop.className = 'sidebar-backdrop';
    backdrop.addEventListener('click', () => document.body.classList.remove('sidebar-open'));
    sidebar.insertAdjacentElement('afterend', backdrop);
  }
  if(!document.getElementById('mobileNavToggle')){
    const toggle = document.createElement('button');
    toggle.id = 'mobileNavToggle';
    toggle.type = 'button';
    toggle.className = 'mobile-nav-toggle';
    toggle.setAttribute('aria-label', 'Open navigation menu');
    toggle.innerHTML = '☰';
    toggle.addEventListener('click', () => document.body.classList.toggle('sidebar-open'));
    topbar.insertBefore(toggle, topbar.firstChild);
  }
  sidebar.querySelectorAll('.nav-item').forEach(link => {
    link.addEventListener('click', () => document.body.classList.remove('sidebar-open'));
  });
})();
</script>

<script src="assets/topbar-search.js?v=<?php echo @filemtime(__DIR__ . '/assets/topbar-search.js'); ?>"></script>
</body>
</html>
