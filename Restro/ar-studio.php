<?php
session_start();
require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/uploads.php';

if (empty($_SESSION['restro_key'])) {
    header('Location: sign-in.php');
    exit;
}

$restro_key = $_SESSION['restro_key'];
session_write_close();

extract(restro_load_nav($conn, $restro_key));
$current_page = 'ar-studio';

$flash = '';

// --- Save the toggle selection ----------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['save_ar'])) {
    $wanted = array_values(array_unique(array_map('strval', (array) ($_POST['ar_item'] ?? []))));

    // Only this restaurant's items are eligible, and only ones that don't
    // already have a model — those post their key just to stay toggled on.
    $owned = [];
    $stmt = mysqli_prepare($conn, "SELECT item_key FROM items WHERE restro_key = ? AND (glb_url IS NULL OR glb_url = '')");
    mysqli_stmt_bind_param($stmt, 's', $restro_key);
    mysqli_stmt_execute($stmt);
    foreach (mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC) as $r) {
        $owned[$r['item_key']] = true;
    }
    mysqli_stmt_close($stmt);

    $existing = [];
    $stmt = mysqli_prepare($conn, "SELECT item_key, status FROM ar_requests WHERE restro_key = ?");
    mysqli_stmt_bind_param($stmt, 's', $restro_key);
    mysqli_stmt_execute($stmt);
    foreach (mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC) as $r) {
        $existing[$r['item_key']] = $r['status'];
    }
    mysqli_stmt_close($stmt);

    $added = 0;
    $removed = 0;

    foreach ($wanted as $item_key) {
        if (!isset($owned[$item_key]) || isset($existing[$item_key])) {
            continue;
        }
        $request_key = 'arq_' . bin2hex(random_bytes(6));
        $ins = mysqli_prepare($conn, "
            INSERT INTO ar_requests (request_key, restro_key, item_key, status)
            VALUES (?, ?, ?, 'requested')
        ");
        mysqli_stmt_bind_param($ins, 'sss', $request_key, $restro_key, $item_key);
        mysqli_stmt_execute($ins);
        mysqli_stmt_close($ins);
        $added++;
    }

    // Un-toggled dishes withdraw the request — but only while it is still
    // queued. Once our team starts or delivers, it stays on the record.
    foreach ($existing as $item_key => $status) {
        if (in_array($item_key, $wanted, true) || $status !== 'requested') {
            continue;
        }
        $del = mysqli_prepare($conn, "DELETE FROM ar_requests WHERE item_key = ? AND restro_key = ? AND status = 'requested'");
        mysqli_stmt_bind_param($del, 'ss', $item_key, $restro_key);
        mysqli_stmt_execute($del);
        mysqli_stmt_close($del);
        $removed++;
    }

    $bits = [];
    if ($added) $bits[] = $added . ' dish' . ($added > 1 ? 'es' : '') . ' requested';
    if ($removed) $bits[] = $removed . ' withdrawn';
    $flash = $bits ? ucfirst(implode(' · ', $bits)) . '.' : 'No changes to save.';
}

// --- Load the menu with AR state --------------------------------------
$stmt = mysqli_prepare($conn, "
    SELECT i.item_key, i.name, i.emoji, i.photo_url, i.price, i.glb_url, i.is_active,
           c.name AS category,
           a.status AS ar_status, a.requested_at, a.admin_note
    FROM items i
    LEFT JOIN categories c ON c.category_key = i.category_key
    LEFT JOIN ar_requests a ON a.item_key = i.item_key
    WHERE i.restro_key = ?
    ORDER BY c.sort_order, i.sort_order, i.name
");
mysqli_stmt_bind_param($stmt, 's', $restro_key);
mysqli_stmt_execute($stmt);
$dishes = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$ready = 0;
$pending = 0;
foreach ($dishes as $d) {
    if (!empty($d['glb_url'])) {
        $ready++;
    } elseif (!empty($d['ar_status'])) {
        $pending++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/png" href="../assets/logo-icon.png">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AR Menu Studio — Dinetous</title>
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
      <div><h1>AR Menu Studio</h1><div class="sub">Choose which dishes get a 3D model</div></div>
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

      <?php if ($flash !== ''): ?>
        <div class="panel" style="border-color:rgba(74,222,128,0.3);background:rgba(74,222,128,0.07);color:var(--good);font-size:13px;padding:13px 18px;margin-bottom:18px;">
          <?php echo htmlspecialchars($flash); ?>
        </div>
      <?php endif; ?>

      <div class="hero-panel">
        <div>
          <h2>Bring your menu to life in AR</h2>
          <p>Toggle the dishes you want in 3D and hit save. Our team builds the model from your dish photo and delivers it here — guests then see it in AR straight from the table QR code.</p>
        </div>
      </div>

      <?php if (!$dishes): ?>
        <div class="panel" style="text-align:center;padding:44px 20px;color:var(--text-low);">
          You haven't added any dishes yet. <a href="menu.php" style="color:var(--ember);">Add your menu first →</a>
        </div>
      <?php else: ?>

      <form method="post" action="ar-studio.php" id="arForm">
        <input type="hidden" name="save_ar" value="1">

        <div class="panel-head">
          <h2>Menu Coverage</h2>
          <div class="link"><?php echo $ready; ?> ready · <?php echo $pending; ?> in progress · <?php echo count($dishes); ?> dishes</div>
        </div>

        <div class="table-grid">
          <?php foreach ($dishes as $d):
            $has_model = !empty($d['glb_url']);
            $req = $d['ar_status'] ?? null;
            $locked = $has_model || in_array($req, ['in_progress', 'delivered'], true);
            $checked = $has_model || $req !== null;
          ?>
            <div class="dish-card ar-card<?php echo $checked ? ' is-on' : ''; ?>">
              <div class="dish-top">
                <div class="dish-emoji<?php echo !empty($d['photo_url']) ? ' has-photo' : ''; ?>">
                  <?php if (!empty($d['photo_url'])): ?>
                    <img src="<?php echo htmlspecialchars(asset_url($d['photo_url'])); ?>" alt="">
                  <?php else: ?>
                    <?php echo htmlspecialchars($d['emoji'] ?: '🍽'); ?>
                  <?php endif; ?>
                </div>
                <?php if ($has_model): ?>
                  <span class="ar-status ready">AR Ready</span>
                <?php elseif ($req === 'in_progress'): ?>
                  <span class="ar-status pending">Modelling</span>
                <?php elseif ($req === 'requested'): ?>
                  <span class="ar-status pending">Queued</span>
                <?php elseif ($req === 'rejected'): ?>
                  <span class="ar-status none">Declined</span>
                <?php else: ?>
                  <span class="ar-status none">No model</span>
                <?php endif; ?>
              </div>

              <div>
                <span class="cat"><?php echo htmlspecialchars($d['category'] ?: 'Uncategorised'); ?></span>
                <h4><?php echo htmlspecialchars($d['name']); ?></h4>
              </div>

              <?php if (!empty($d['admin_note'])): ?>
                <div class="ar-note"><?php echo htmlspecialchars($d['admin_note']); ?></div>
              <?php elseif (empty($d['photo_url']) && !$has_model): ?>
                <div class="ar-note muted">
                  <a href="menu.php?edit=<?php echo urlencode($d['item_key']); ?>">Add a photo</a> so we can model it accurately.
                </div>
              <?php endif; ?>

              <div class="dish-foot ar-switch">
                <span class="dish-price">₹<?php echo number_format((float) $d['price']); ?></span>
                <label class="switch" title="<?php echo $locked ? ($has_model ? 'Already live in AR' : 'Already with our team') : 'Request a 3D model'; ?>">
                  <input type="checkbox" name="ar_item[]" value="<?php echo htmlspecialchars($d['item_key']); ?>"
                         <?php echo $checked ? 'checked' : ''; ?> <?php echo $locked ? 'disabled' : ''; ?>>
                  <span class="slider"></span>
                </label>
                <?php if ($locked): ?>
                  <input type="hidden" name="ar_item[]" value="<?php echo htmlspecialchars($d['item_key']); ?>">
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="ar-savebar">
          <div class="count" id="arCount"></div>
          <button type="submit" class="btn btn-primary">Save AR selection →</button>
        </div>
      </form>

      <?php endif; ?>

    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/mobile-nav.php'; ?>

<script>
(function () {
  var form = document.getElementById('arForm');
  if (!form) return;
  var boxes = form.querySelectorAll('input[type=checkbox][name="ar_item[]"]');
  var count = document.getElementById('arCount');

  function refresh() {
    var picked = 0;
    boxes.forEach(function (b) {
      if (b.checked) picked++;
      var card = b.closest('.ar-card');
      if (card) card.classList.toggle('is-on', b.checked);
    });
    count.textContent = picked + ' of ' + boxes.length + ' dishes selected for AR';
  }

  boxes.forEach(function (b) { b.addEventListener('change', refresh); });
  refresh();
})();
</script>

<script src="assets/topbar-search.js?v=<?php echo @filemtime(__DIR__ . '/assets/topbar-search.js'); ?>"></script>
</body>
</html>
