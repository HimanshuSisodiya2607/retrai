<?php
session_start();
require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/includes/helpers.php';

if (empty($_SESSION['restro_key'])) {
    header('Location: sign-in.php');
    exit;
}

$restro_key = $_SESSION['restro_key'];
session_write_close();

extract(restro_load_nav($conn, $restro_key));
$current_page = 'ar-studio';

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AR Menu Studio — RestroAI</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>

<div id="bg-glow"></div>

<div class="shell">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <div class="main">
    <div class="topbar">
      <div><h1>AR Menu Studio</h1><div class="sub">Manage 3D previews for your menu</div></div>
      <div class="topbar-right">
        <div class="search-box">🔍 Search orders, dishes…</div>
        <div class="icon-btn">🔔<span class="dot"></span></div>
        <div class="restaurant-pill"><span class="dot"></span>Open — Dine-in</div>
      </div>
    </div>

    <div class="content">

      <div class="hero-panel">
        <div>
          <h2>Bring your menu to life in AR</h2>
          <p>Guests scan the table QR code and see a photorealistic 3D model of the dish before ordering. Dishes marked "AR Ready" already have a scanned model on file.</p>
        </div>
        <button class="btn btn-primary" onclick="alert('Studio upload flow coming soon (demo).')">Scan a New Dish</button>
      </div>

      <div class="panel-head"><h2>Menu Coverage</h2><div class="link">3 of 6 dishes AR-ready</div></div>
      <div class="table-grid">
        <div class="dish-card">
          <div class="dish-top"><div class="dish-emoji">🍝</div></div>
          <div><h4>Truffle Pasta</h4></div>
          <div class="dish-foot" style="border-top:none;padding-top:2px;">
            <button class="ar-toggle on" onclick="this.classList.toggle('on'); this.textContent = this.classList.contains('on') ? 'AR Ready ✓' : 'Enable AR';">AR Ready ✓</button>
          </div>
        </div>
        <div class="dish-card">
          <div class="dish-top"><div class="dish-emoji">🍔</div></div>
          <div><h4>Signature Burger</h4></div>
          <div class="dish-foot" style="border-top:none;padding-top:2px;">
            <button class="ar-toggle on" onclick="this.classList.toggle('on'); this.textContent = this.classList.contains('on') ? 'AR Ready ✓' : 'Enable AR';">AR Ready ✓</button>
          </div>
        </div>
        <div class="dish-card">
          <div class="dish-top"><div class="dish-emoji">🍛</div></div>
          <div><h4>Butter Chicken Bowl</h4></div>
          <div class="dish-foot" style="border-top:none;padding-top:2px;">
            <button class="ar-toggle " onclick="this.classList.toggle('on'); this.textContent = this.classList.contains('on') ? 'AR Ready ✓' : 'Enable AR';">Enable AR</button>
          </div>
        </div>
        <div class="dish-card">
          <div class="dish-top"><div class="dish-emoji">🍕</div></div>
          <div><h4>Margherita Pizza</h4></div>
          <div class="dish-foot" style="border-top:none;padding-top:2px;">
            <button class="ar-toggle on" onclick="this.classList.toggle('on'); this.textContent = this.classList.contains('on') ? 'AR Ready ✓' : 'Enable AR';">AR Ready ✓</button>
          </div>
        </div>
        <div class="dish-card">
          <div class="dish-top"><div class="dish-emoji">🍰</div></div>
          <div><h4>Molten Brownie</h4></div>
          <div class="dish-foot" style="border-top:none;padding-top:2px;">
            <button class="ar-toggle " onclick="this.classList.toggle('on'); this.textContent = this.classList.contains('on') ? 'AR Ready ✓' : 'Enable AR';">Enable AR</button>
          </div>
        </div>
        <div class="dish-card">
          <div class="dish-top"><div class="dish-emoji">🍹</div></div>
          <div><h4>Cold Coffee</h4></div>
          <div class="dish-foot" style="border-top:none;padding-top:2px;">
            <button class="ar-toggle " onclick="this.classList.toggle('on'); this.textContent = this.classList.contains('on') ? 'AR Ready ✓' : 'Enable AR';">Enable AR</button>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/mobile-nav.php'; ?>

</body>
</html>
