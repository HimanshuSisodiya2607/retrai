<?php
session_start();
require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/includes/uploads.php';

$is_waiter = !empty($_SESSION['waiter_staff_key']);
$is_admin = !empty($_SESSION['restro_key']) && !$is_waiter;

$table_key = trim($_GET['table'] ?? '');
$placed_order_key = trim($_GET['placed'] ?? '');

function cat_slug($name) {
    return strtolower(preg_replace('/[^a-z0-9]+/', '-', $name));
}

function cat_label($name) {
    $suffix = ['Starters' => 'PROTOCOL', 'Mains' => 'ARRAY', 'Desserts' => 'MODULE', 'Beverages' => 'STREAM'];
    $s = $suffix[$name] ?? 'FEED';
    return '[ ' . strtoupper($name) . '_' . $s . ' ]';
}

$table = null;
$placed_order = null;
$menu_groups = [];
$error = '';

// Place order (form POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['place_order'])) {
    $table_key = trim($_POST['table_key'] ?? '');
    $qtys = $_POST['qty'] ?? [];

    $stmt = mysqli_prepare($conn, "
        SELECT t.table_key, t.restro_key, t.table_name
        FROM restaurant_tables t
        WHERE t.table_key = ?
    ");
    mysqli_stmt_bind_param($stmt, 's', $table_key);
    mysqli_stmt_execute($stmt);
    $table_row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$table_row) {
        $error = 'Invalid table.';
    } else {
        $restro_key = $table_row['restro_key'];

        $stmt = mysqli_prepare($conn, "
            SELECT item_key, name, price
            FROM items
            WHERE restro_key = ? AND is_active = 1
        ");
        mysqli_stmt_bind_param($stmt, 's', $restro_key);
        mysqli_stmt_execute($stmt);
        $menu_rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($stmt);

        $lines = [];
        $total = 0.0;
        $summary_parts = [];

        foreach ($menu_rows as $item) {
            $qty = (int) ($qtys[$item['item_key']] ?? 0);
            if ($qty <= 0) continue;
            $line_total = (float) $item['price'] * $qty;
            $total += $line_total;
            $lines[] = [
                'item_key' => $item['item_key'],
                'item_name' => $item['name'],
                'quantity' => $qty,
                'unit_price' => (float) $item['price'],
                'line_total' => $line_total,
            ];
            $summary_parts[] = $qty > 1 ? $item['name'] . ' ×' . $qty : $item['name'];
        }

        if (count($lines) === 0) {
            $error = 'Please add at least one item.';
        } else {
            $order_key = 'ord_' . bin2hex(random_bytes(6));
            $items_summary = implode(', ', $summary_parts);

            $ins = mysqli_prepare($conn, "
                INSERT INTO orders (order_key, restro_key, table_key, status, total_amount, items_summary)
                VALUES (?, ?, ?, 'new', ?, ?)
            ");
            mysqli_stmt_bind_param($ins, 'sssds', $order_key, $restro_key, $table_key, $total, $items_summary);
            mysqli_stmt_execute($ins);
            mysqli_stmt_close($ins);

            $item_ins = mysqli_prepare($conn, "
                INSERT INTO order_items (order_key, restro_key, item_key, item_name, quantity, unit_price, line_total)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($lines as $line) {
                mysqli_stmt_bind_param(
                    $item_ins,
                    'ssssidd',
                    $order_key,
                    $restro_key,
                    $line['item_key'],
                    $line['item_name'],
                    $line['quantity'],
                    $line['unit_price'],
                    $line['line_total']
                );
                mysqli_stmt_execute($item_ins);
            }
            mysqli_stmt_close($item_ins);

            header('Location: customer-menu.php?table=' . urlencode($table_key) . '&placed=' . urlencode($order_key));
            exit;
        }
    }
}

// Load table
if ($table_key !== '') {
    $stmt = mysqli_prepare($conn, "
        SELECT t.table_key, t.table_name, t.restro_key, r.restaurant_name
        FROM restaurant_tables t
        JOIN restaurants r ON r.restro_key = t.restro_key
        WHERE t.table_key = ?
    ");
    mysqli_stmt_bind_param($stmt, 's', $table_key);
    mysqli_stmt_execute($stmt);
    $table = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
}

// Placed order confirmation
$placed_order_items = [];
if ($table && $placed_order_key !== '') {
    $stmt = mysqli_prepare($conn, "
        SELECT order_key, status, total_amount, items_summary, ordered_at
        FROM orders
        WHERE order_key = ? AND table_key = ? AND restro_key = ?
    ");
    mysqli_stmt_bind_param($stmt, 'sss', $placed_order_key, $table['table_key'], $table['restro_key']);
    mysqli_stmt_execute($stmt);
    $placed_order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if ($placed_order) {
        $stmt = mysqli_prepare($conn, "
            SELECT item_name, quantity, unit_price, line_total
            FROM order_items
            WHERE order_key = ? AND restro_key = ?
            ORDER BY id ASC
        ");
        mysqli_stmt_bind_param($stmt, 'ss', $placed_order_key, $table['restro_key']);
        mysqli_stmt_execute($stmt);
        $placed_order_items = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($stmt);
    }
}

// Menu (query from restroai.sql)
if ($table && !$placed_order) {
    $stmt = mysqli_prepare($conn, "
        SELECT c.name AS category_name, c.sort_order AS cat_sort,
               i.item_key, i.name, i.emoji, i.description, i.photo_url, i.price, i.glb_url, i.sort_order AS item_sort
        FROM categories c
        JOIN items i ON i.category_key = c.category_key AND i.restro_key = c.restro_key
        WHERE c.restro_key = ? AND i.is_active = 1
        ORDER BY c.sort_order, i.sort_order, i.name
    ");
    mysqli_stmt_bind_param($stmt, 's', $table['restro_key']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $cat = $row['category_name'];
        if (!isset($menu_groups[$cat])) {
            $menu_groups[$cat] = [];
        }
        $menu_groups[$cat][] = $row;
    }
    mysqli_stmt_close($stmt);
}

$show_confirm = $placed_order !== null;
$show_menu = $table && !$show_confirm;
$menu_empty = $show_menu && count($menu_groups) === 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/png" href="../assets/logo-icon.png">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Menu — Dinetous</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/menu.css?v=<?php echo @filemtime(__DIR__ . '/assets/menu.css'); ?>">
<script type="module" src="https://unpkg.com/@google/model-viewer@3.4.0/dist/model-viewer.min.js"></script>
<style>
  /* Customer-only quick actions: Call Waiter / Ask for Bill */
  .quick-actions-bar{
    display:flex;gap:10px;margin:14px 0 20px;
  }
  .qa-btn{
    flex:1;display:flex;align-items:center;justify-content:center;gap:8px;
    font-family:var(--font-mono,'IBM Plex Mono',monospace);font-size:12.5px;font-weight:700;
    letter-spacing:0.03em;text-transform:uppercase;
    background:rgba(255,255,255,0.03);border:1px solid var(--line,rgba(255,255,255,0.08));
    color:var(--text-hi,#f4f2ef);border-radius:100px;padding:13px 14px;
    cursor:pointer;transition:background .25s ease,border-color .25s ease,transform .15s ease,opacity .25s ease;
  }
  .qa-btn:hover:not(:disabled){border-color:rgba(255,90,31,0.45);background:rgba(255,90,31,0.06);}
  .qa-btn:active:not(:disabled){transform:scale(0.98);}
  .qa-btn:disabled{cursor:default;}
  .qa-btn .qa-ico{font-size:15px;line-height:1;}
  .qa-btn.qa-btn-loading{opacity:0.6;}
  .qa-btn.qa-btn-active{
    background:linear-gradient(135deg, rgba(255,90,31,0.16), rgba(255,31,76,0.1));
    border-color:rgba(255,90,31,0.5);color:var(--amber,#ffb347);
  }
  @media(max-width:380px){
    .qa-btn{font-size:11px;padding:12px 8px;}
  }
</style>
</head>
<body>

<div id="bg-glow"></div>
<div class="grain"></div>

<div class="order-page-wrap" id="pageWrap">
  <div class="order-header"><img src="../assets/logo.png" alt="Dinetous" class="logo-img"></div>

  <?php if ($table && !$is_waiter && !$is_admin): ?>
  <div class="quick-actions-bar" id="quickActionsBar">
    <button type="button" class="qa-btn" id="callWaiterBtn" onclick="sendTableRequest('waiter')">
      <span class="qa-ico">🛎️</span><span class="qa-label">Call Waiter</span>
    </button>
    <button type="button" class="qa-btn" id="askBillBtn" onclick="sendTableRequest('bill')">
      <span class="qa-ico">🧾</span><span class="qa-label">Ask for Bill</span>
    </button>
  </div>
  <?php endif; ?>

  <?php if (!$table): ?>
    <div class="empty-note link-note" style="margin:20px 0 24px;">
      This QR link is missing a valid table ID, or the table no longer exists.
    </div>
  <?php elseif ($show_confirm): ?>
    <div class="order-confirm">
      <div class="oc-tick">✅</div>
      <h2>Order placed!</h2>
      <div class="oc-meta">
        Table <?php echo htmlspecialchars($table['table_name']); ?>
        · ₹<?php echo number_format((float) $placed_order['total_amount'], 0, '.', ','); ?>
      </div>
      <div class="oc-note">The kitchen has been notified. You can order more items anytime.</div>
      <button type="button" class="btn btn-ghost" id="orderBackBtn" style="margin-top:22px;display:inline-flex;">
        ← <?php echo $is_waiter ? 'Back to Tables' : ($is_admin ? 'Back to Tables' : 'Back to Menu'); ?>
      </button>
    </div>
  <?php else: ?>
    <div class="table-band">
      <div>
        <div class="tb-kicker">You're ordering for</div>
        <div class="tb-name"><?php echo htmlspecialchars($table['table_name']); ?></div>
      </div>
      <div class="restaurant-pill"><span class="dot"></span><?php echo htmlspecialchars($table['restaurant_name']); ?></div>
    </div>

    <div id="guestOrdersPanel" class="guest-orders-panel" style="display:none;"></div>

    <?php if ($error !== ''): ?>
      <p style="color:#ff6b6b;font-size:13px;margin-bottom:16px;"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>

    <?php if ($menu_empty): ?>
      <div class="empty-note">The menu is being updated right now — please check back shortly, or ask a staff member.</div>
    <?php else: ?>
      <div class="cat-tabs" id="catTabs">
        <?php $i = 0; foreach ($menu_groups as $cat_name => $items): ?>
          <button type="button" class="cat-tab<?php echo $i === 0 ? ' active' : ''; ?>" data-cat="<?php echo htmlspecialchars(cat_slug($cat_name)); ?>" onclick="scrollToCat('<?php echo htmlspecialchars(cat_slug($cat_name), ENT_QUOTES); ?>')"><?php echo htmlspecialchars($cat_name); ?></button>
        <?php $i++; endforeach; ?>
      </div>

      <div id="menuList">
        <?php foreach ($menu_groups as $cat_name => $items): $slug = cat_slug($cat_name); ?>
          <div class="cat-block" id="cat-<?php echo htmlspecialchars($slug); ?>">
            <div class="cat-heading"><?php echo htmlspecialchars(cat_label($cat_name)); ?></div>
            <div class="menu-dish-grid">
              <?php foreach ($items as $d): ?>
                <div class="menu-dish-card">
                  <div class="mdc-emoji<?php echo !empty($d['photo_url']) ? ' has-photo' : ''; ?>">
                    <?php if (!empty($d['photo_url'])): ?>
                      <img src="<?php echo htmlspecialchars(asset_url($d['photo_url'])); ?>" alt="<?php echo htmlspecialchars($d['name'], ENT_QUOTES); ?>" loading="lazy">
                    <?php else: ?>
                      <?php echo htmlspecialchars($d['emoji'] ?: '🍽'); ?>
                    <?php endif; ?>
                  </div>
                  <div class="mdc-body">
                    <div class="mdc-row">
                      <h4><?php echo htmlspecialchars($d['name']); ?></h4>
                      <span class="mdc-price">₹<?php echo number_format((float) $d['price'], 0, '.', ','); ?></span>
                    </div>
                    <?php if (!empty($d['description'])): ?>
                      <p class="mdc-desc"><?php echo htmlspecialchars($d['description']); ?></p>
                    <?php endif; ?>
                    <button type="button" class="ar-badge-btn" data-name="<?php echo htmlspecialchars($d['name'], ENT_QUOTES); ?>" data-glb="<?php echo htmlspecialchars(asset_url($d['glb_url'] ?? ''), ENT_QUOTES); ?>" onclick="viewInAR(this)">📱 View in AR</button>
                  </div>
                  <div class="mdc-qty" id="qtyctrl-<?php echo htmlspecialchars($d['item_key']); ?>"></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php if ($show_menu && !$menu_empty): ?>
<form id="orderForm" method="post" action="customer-menu.php?table=<?php echo urlencode($table['table_key']); ?>" style="display:none;">
  <input type="hidden" name="place_order" value="1">
  <input type="hidden" name="table_key" value="<?php echo htmlspecialchars($table['table_key']); ?>">
</form>

<div class="cart-bar" id="cartBar">
  <div>
    <div class="cb-label" id="cartCountLabel">0 ITEMS ADDED</div>
    <div class="cb-amount" id="cartTotal">₹0</div>
  </div>
  <button type="button" class="btn-place-order" id="placeOrderBtn" disabled>Place Order →</button>
</div>
<?php endif; ?>

<div class="modal-overlay" id="arModalOverlay">
  <div class="modal" style="max-width:420px;padding:20px;">
    <h3 id="arModalTitle" style="margin-bottom:4px;">Dish</h3>
    <p style="font-size:12px;color:var(--text-mid);margin-bottom:14px;">Drag to rotate, or tap "See on Table" to place it in AR.</p>
    <model-viewer
      id="arViewer"
      ar
      ar-modes="webxr scene-viewer quick-look"
      ar-scale="fixed"
      ar-placement="floor"
      camera-controls
      auto-rotate
      shadow-intensity="1.1"
      environment-image="neutral"
      exposure="1.05"
      loading="eager"
      reveal="auto"
      style="width:100%;height:280px;border-radius:14px;background:#0a0a0a;position:relative;">
      <button slot="ar-button" class="btn btn-primary btn-sm">📱 See on Table</button>
    </model-viewer>
    <div id="arDemoNote" class="empty-note" style="display:none;margin-top:10px;padding:10px;font-size:11px;">This is a placeholder 3D model for demo purposes — add your own scanned dish's .glb file under Menu Management.</div>
    <div id="arLoadError" class="empty-note" style="display:none;margin-top:10px;padding:10px;font-size:12px;">Couldn't load this 3D model right now.</div>
    <div class="modal-actions">
      <button type="button" class="btn btn-ghost" onclick="closeArModal()">Close</button>
    </div>
  </div>
</div>

<?php if ($show_menu && !$menu_empty): ?>
<script>
  const DEMO_GLB = 'https://modelviewer.dev/shared-assets/models/Astronaut.glb';
  const menuPrices = <?php
    $prices = [];
    foreach ($menu_groups as $items) {
        foreach ($items as $d) {
            $prices[$d['item_key']] = (float) $d['price'];
        }
    }
    echo json_encode($prices);
  ?>;

  let cart = {};

  function renderQtyControl(itemKey) {
    const qty = cart[itemKey] || 0;
    if (qty === 0) {
      return '<button type="button" class="qty-add-btn" onclick="changeCartQty(\'' + itemKey + '\', 1)">+</button>';
    }
    return '<div class="qty-stepper-mini">' +
      '<button type="button" onclick="changeCartQty(\'' + itemKey + '\', -1)">−</button>' +
      '<span class="qty-val">' + qty + '</span>' +
      '<button type="button" onclick="changeCartQty(\'' + itemKey + '\', 1)">+</button>' +
      '</div>';
  }

  function initQtyControls() {
    Object.keys(menuPrices).forEach(function (key) {
      const el = document.getElementById('qtyctrl-' + key);
      if (el) el.innerHTML = renderQtyControl(key);
    });
    updateCartTotal();
  }

  function changeCartQty(itemKey, delta) {
    cart[itemKey] = Math.max(0, (cart[itemKey] || 0) + delta);
    const el = document.getElementById('qtyctrl-' + itemKey);
    if (el) el.innerHTML = renderQtyControl(itemKey);
    updateCartTotal();
  }

  function updateCartTotal() {
    let total = 0, count = 0;
    Object.keys(cart).forEach(function (key) {
      const qty = cart[key] || 0;
      if (qty > 0 && menuPrices[key]) {
        total += menuPrices[key] * qty;
        count += qty;
      }
    });
    document.getElementById('cartTotal').textContent = '₹' + total.toLocaleString('en-IN');
    document.getElementById('cartCountLabel').textContent = count + (count === 1 ? ' ITEM ADDED' : ' ITEMS ADDED');
    document.getElementById('placeOrderBtn').disabled = count === 0;
  }

  document.getElementById('placeOrderBtn').addEventListener('click', function () {
    const form = document.getElementById('orderForm');
    form.querySelectorAll('input[name^="qty"]').forEach(function (el) { el.remove(); });
    Object.keys(cart).forEach(function (key) {
      if (cart[key] > 0) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'qty[' + key + ']';
        input.value = cart[key];
        form.appendChild(input);
      }
    });
    form.submit();
  });

  function scrollToCat(slug) {
    const el = document.getElementById('cat-' + slug);
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  (function setupScrollSpy() {
    const sections = document.querySelectorAll('.cat-block');
    if (!sections.length) return;
    const observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          const slug = entry.target.id.replace('cat-', '');
          document.querySelectorAll('.cat-tab').forEach(function (t) {
            t.classList.toggle('active', t.dataset.cat === slug);
          });
        }
      });
    }, { rootMargin: '-40% 0px -50% 0px', threshold: 0 });
    sections.forEach(function (s) { observer.observe(s); });
  })();

  const DEMO_GLB_URL = DEMO_GLB;
  let arSessionStarted = false;

  function viewInAR(btn) {
    const name = btn.dataset.name;
    const glb = btn.dataset.glb;
    const usingFallback = !glb;
    const glbUrl = glb || DEMO_GLB_URL;
    document.getElementById('arModalTitle').textContent = name;
    const viewer = document.getElementById('arViewer');
    document.getElementById('arLoadError').style.display = 'none';
    viewer.style.display = 'block';
    viewer.setAttribute('src', glbUrl);
    viewer.removeAttribute('ios-src');
    document.getElementById('arDemoNote').style.display = usingFallback ? 'block' : 'none';
    document.getElementById('arModalOverlay').classList.add('open');
    viewer.addEventListener('load', function tryAR() {
      viewer.removeEventListener('load', tryAR);
      try { viewer.activateAR(); } catch (e) {}
    }, { once: true });
  }

  document.getElementById('arViewer').addEventListener('ar-status', function (event) {
    if (event.detail.status === 'session-started') {
      arSessionStarted = true;
    } else if (event.detail.status === 'not-presenting' && arSessionStarted) {
      arSessionStarted = false;
      closeArModal();
    }
  });

  document.getElementById('arViewer').addEventListener('error', function () {
    document.getElementById('arViewer').style.display = 'none';
    document.getElementById('arDemoNote').style.display = 'none';
    document.getElementById('arLoadError').style.display = 'block';
  });

  function closeArModal() {
    document.getElementById('arModalOverlay').classList.remove('open');
    const viewer = document.getElementById('arViewer');
    if (viewer) { viewer.removeAttribute('src'); viewer.style.display = 'block'; }
    document.getElementById('arLoadError').style.display = 'none';
  }

  document.getElementById('arModalOverlay').addEventListener('click', function (e) {
    if (e.target.id === 'arModalOverlay') closeArModal();
  });

  const TABLE_KEY = <?php echo json_encode($table['table_key']); ?>;
  const GUEST_ORDER_TTL_MS = 2 * 60 * 60 * 1000;

  function guestOrdersStorageKey(tableKey) {
    return 'restroai_guest_orders_' + tableKey;
  }

  function loadGuestOrders(tableKey) {
    const key = guestOrdersStorageKey(tableKey);
    try {
      const raw = localStorage.getItem(key);
      if (!raw) return null;
      const data = JSON.parse(raw);
      if (!data || !data.expiresAt || Date.now() > data.expiresAt) {
        localStorage.removeItem(key);
        return null;
      }
      if (!Array.isArray(data.orders)) data.orders = [];
      return data;
    } catch (e) {
      localStorage.removeItem(key);
      return null;
    }
  }

  function initGuestOrdersPanel() {
    const panel = document.getElementById('guestOrdersPanel');
    if (!panel) return;

    const data = loadGuestOrders(TABLE_KEY);
    if (!data || !data.orders.length) {
      panel.style.display = 'none';
      return;
    }

    const totalSpent = data.orders.reduce(function (sum, o) { return sum + (o.total_amount || 0); }, 0);
    const ordersHtml = data.orders.map(function (order) {
      const items = (order.items || []).map(function (item) {
        return item.quantity + '× ' + item.item_name;
      }).join(', ') || order.items_summary || 'Items unavailable';
      return '<div class="guest-order-row">' +
        '<div class="guest-order-items">' + items + '</div>' +
        '<div class="guest-order-total">₹' + Number(order.total_amount || 0).toLocaleString('en-IN') + '</div>' +
      '</div>';
    }).join('');

    panel.innerHTML =
      '<div class="guest-orders-head">' +
        '<div class="guest-orders-title">Your orders this session</div>' +
        '<div class="guest-orders-meta">' + data.orders.length + ' order' + (data.orders.length === 1 ? '' : 's') + ' · ₹' + totalSpent.toLocaleString('en-IN') + '</div>' +
      '</div>' +
      '<div class="guest-orders-list">' + ordersHtml + '</div>';
    panel.style.display = 'block';
  }

  initQtyControls();
  initGuestOrdersPanel();
</script>
<?php endif; ?>

<?php if ($show_confirm): ?>
<script>
  const TABLE_KEY = <?php echo json_encode($table['table_key']); ?>;
  const IS_ADMIN = <?php echo $is_admin ? 'true' : 'false'; ?>;
  const IS_WAITER = <?php echo $is_waiter ? 'true' : 'false'; ?>;
  const GUEST_ORDER_TTL_MS = 2 * 60 * 60 * 1000;
  const PLACED_ORDER = <?php echo json_encode([
      'order_key' => $placed_order['order_key'],
      'status' => $placed_order['status'],
      'total_amount' => (float) $placed_order['total_amount'],
      'items_summary' => $placed_order['items_summary'],
      'ordered_at' => $placed_order['ordered_at'],
      'items' => array_map(function ($row) {
          return [
              'item_name' => $row['item_name'],
              'quantity' => (int) $row['quantity'],
              'unit_price' => (float) $row['unit_price'],
              'line_total' => (float) $row['line_total'],
          ];
      }, $placed_order_items),
  ]); ?>;

  function guestOrdersStorageKey(tableKey) {
    return 'restroai_guest_orders_' + tableKey;
  }

  function loadGuestOrders(tableKey) {
    const key = guestOrdersStorageKey(tableKey);
    try {
      const raw = localStorage.getItem(key);
      if (!raw) return null;
      const data = JSON.parse(raw);
      if (!data || !data.expiresAt || Date.now() > data.expiresAt) {
        localStorage.removeItem(key);
        return null;
      }
      if (!Array.isArray(data.orders)) data.orders = [];
      return data;
    } catch (e) {
      localStorage.removeItem(key);
      return null;
    }
  }

  function saveGuestOrder(tableKey, order) {
    const key = guestOrdersStorageKey(tableKey);
    const data = loadGuestOrders(tableKey) || { orders: [] };
    const entry = {
      order_key: order.order_key,
      status: order.status,
      total_amount: order.total_amount,
      items_summary: order.items_summary,
      ordered_at: order.ordered_at,
      items: order.items || [],
      saved_at: new Date().toISOString(),
    };
    const idx = data.orders.findIndex(function (o) { return o.order_key === entry.order_key; });
    if (idx >= 0) data.orders[idx] = entry;
    else data.orders.push(entry);
    data.expiresAt = Date.now() + GUEST_ORDER_TTL_MS;
    localStorage.setItem(key, JSON.stringify(data));
  }

  function handleOrderBack() {
    if (IS_ADMIN) {
      window.location.href = 'tables.php';
      return;
    }
    if (IS_WAITER) {
      window.location.href = '../waiter_panel/tables.php';
      return;
    }
    window.location.href = 'customer-menu.php?table=' + encodeURIComponent(TABLE_KEY);
  }

  if (!IS_ADMIN && !IS_WAITER) {
    saveGuestOrder(TABLE_KEY, PLACED_ORDER);
  }

  document.getElementById('orderBackBtn').addEventListener('click', handleOrderBack);
</script>
<?php endif; ?>

<?php if ($show_menu && $menu_empty): ?>
<script>
  const TABLE_KEY = <?php echo json_encode($table['table_key']); ?>;
  const GUEST_ORDER_TTL_MS = 2 * 60 * 60 * 1000;

  function guestOrdersStorageKey(tableKey) {
    return 'restroai_guest_orders_' + tableKey;
  }

  function loadGuestOrders(tableKey) {
    const key = guestOrdersStorageKey(tableKey);
    try {
      const raw = localStorage.getItem(key);
      if (!raw) return null;
      const data = JSON.parse(raw);
      if (!data || !data.expiresAt || Date.now() > data.expiresAt) {
        localStorage.removeItem(key);
        return null;
      }
      if (!Array.isArray(data.orders)) data.orders = [];
      return data;
    } catch (e) {
      localStorage.removeItem(key);
      return null;
    }
  }

  function initGuestOrdersPanel() {
    const panel = document.getElementById('guestOrdersPanel');
    if (!panel) return;
    const data = loadGuestOrders(TABLE_KEY);
    if (!data || !data.orders.length) { panel.style.display = 'none'; return; }
    const totalSpent = data.orders.reduce(function (sum, o) { return sum + (o.total_amount || 0); }, 0);
    const ordersHtml = data.orders.map(function (order) {
      const items = (order.items || []).map(function (item) { return item.quantity + '× ' + item.item_name; }).join(', ') || order.items_summary || 'Items unavailable';
      return '<div class="guest-order-row"><div class="guest-order-items">' + items + '</div><div class="guest-order-total">₹' + Number(order.total_amount || 0).toLocaleString('en-IN') + '</div></div>';
    }).join('');
    panel.innerHTML = '<div class="guest-orders-head"><div class="guest-orders-title">Your orders this session</div><div class="guest-orders-meta">' + data.orders.length + ' order' + (data.orders.length === 1 ? '' : 's') + ' · ₹' + totalSpent.toLocaleString('en-IN') + '</div></div><div class="guest-orders-list">' + ordersHtml + '</div>';
    panel.style.display = 'block';
  }

  initGuestOrdersPanel();
</script>
<?php endif; ?>

<?php if ($table && !$is_waiter && !$is_admin): ?>
<script>
  (function () {
    const TABLE_KEY_QA = <?php echo json_encode($table['table_key']); ?>;
    const requestState = { waiter: null, bill: null };
    const LABELS = {
      waiter: { idle: 'Call Waiter', pending: 'Waiter called ✓', acknowledged: 'On the way…' },
      bill:   { idle: 'Ask for Bill', pending: 'Bill requested ✓', acknowledged: 'Bringing your bill…' }
    };

    function qaBtn(type) {
      return document.getElementById(type === 'waiter' ? 'callWaiterBtn' : 'askBillBtn');
    }

    function renderQaButton(type) {
      const btn = qaBtn(type);
      if (!btn) return;
      const state = requestState[type];
      const label = btn.querySelector('.qa-label');
      if (state === 'pending' || state === 'acknowledged') {
        label.textContent = LABELS[type][state];
        btn.classList.add('qa-btn-active');
        btn.disabled = true;
      } else {
        label.textContent = LABELS[type].idle;
        btn.classList.remove('qa-btn-active');
        btn.disabled = false;
      }
    }

    window.sendTableRequest = function (type) {
      const btn = qaBtn(type);
      if (!btn || btn.disabled) return;
      btn.disabled = true;
      btn.classList.add('qa-btn-loading');

      const body = new URLSearchParams({ table_key: TABLE_KEY_QA, type: type });
      fetch('table-request.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          btn.classList.remove('qa-btn-loading');
          if (data.ok) {
            requestState[type] = data.status;
            renderQaButton(type);
          } else {
            btn.disabled = false;
            alert('Something went wrong — please try again or ask a staff member directly.');
          }
        })
        .catch(function () {
          btn.classList.remove('qa-btn-loading');
          btn.disabled = false;
          alert('Network error — please try again.');
        });
    };

    var qaPollTimer = null;
    var qaStream = null;

    function applyQaState(active) {
      ['waiter', 'bill'].forEach(function (type) {
        requestState[type] = active[type] ? active[type].status : null;
        renderQaButton(type);
      });
    }

    function refreshQaState() {
      fetch('table-request.php?table_key=' + encodeURIComponent(TABLE_KEY_QA))
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data.ok) return;
          applyQaState(data.active);
        })
        .catch(function () {});
    }

    function startQaPolling() {
      if (qaPollTimer) return;
      refreshQaState();
      qaPollTimer = setInterval(refreshQaState, 10000);
    }

    if (typeof window.EventSource !== 'undefined') {
      try {
        qaStream = new EventSource('table-request-stream.php?table_key=' + encodeURIComponent(TABLE_KEY_QA));

        qaStream.addEventListener('qa_state', function (e) {
          try {
            var data = JSON.parse(e.data);
            if (data.ok) applyQaState(data.active);
          } catch (err) {}
        });

        // The stream exits every 5 min by design; EventSource reconnects on
        // its own, so only fall back to polling if it errors while closed.
        qaStream.onerror = function (e) {
          var src = e.target;
          if (src.readyState === EventSource.CLOSED) {
            src.close();
            if (qaStream === src) qaStream = null;
            startQaPolling();
          }
        };
      } catch (err) {
        startQaPolling();
      }
    } else {
      startQaPolling();
    }
  })();
</script>
<?php endif; ?>

</body>
</html>