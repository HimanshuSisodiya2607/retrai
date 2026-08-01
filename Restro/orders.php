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
$current_page = 'orders';

$tab = (isset($_GET['tab']) && $_GET['tab'] === 'completed') ? 'completed' : 'active';
$show_modal = isset($_GET['new']);
$error = '';

$status_flow = ['new', 'kitchen', 'ready', 'served', 'completed'];
$status_label = ['new' => 'New', 'kitchen' => 'Kitchen', 'ready' => 'Ready', 'served' => 'Served', 'completed' => 'Completed'];
$status_next = ['new' => 'Send to kitchen ▸', 'kitchen' => 'Mark ready ▸', 'ready' => 'Mark served ▸', 'served' => 'Bill & complete ▸'];

function timeAgo($datetime) {
    $mins = max(0, (int) round((time() - strtotime($datetime)) / 60));
    if ($mins < 1) return 'just now';
    if ($mins === 1) return '1m ago';
    if ($mins < 60) return $mins . 'm ago';
    return (int) round($mins / 60) . 'h ago';
}

// Advance order status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['advance_order'])) {
    $order_key = $_POST['advance_order'];
    $stmt = mysqli_prepare($conn, "SELECT status, table_key FROM orders WHERE order_key = ? AND restro_key = ?");
    mysqli_stmt_bind_param($stmt, 'ss', $order_key, $restro_key);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if ($row) {
        if ($row['status'] === 'served') {
            header('Location: tables.php?checkout=' . urlencode($row['table_key']) . '&return=' . urlencode('orders.php?tab=' . $tab));
            exit;
        }
        $idx = array_search($row['status'], $status_flow, true);
        if ($idx !== false && $idx < count($status_flow) - 1) {
            $next = $status_flow[$idx + 1];
            $upd = mysqli_prepare($conn, "UPDATE orders SET status = ? WHERE order_key = ? AND restro_key = ?");
            mysqli_stmt_bind_param($upd, 'sss', $next, $order_key, $restro_key);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);
        }
    }
    header('Location: orders.php?tab=' . urlencode($tab));
    exit;
}

// Create new order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['create_order'])) {
    $table_key = trim($_POST['table_key'] ?? '');
    $qtys = $_POST['qty'] ?? [];

    if ($table_key === '') {
        $error = 'Please select a table.';
        $show_modal = true;
    } else {
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
            $error = 'Please select at least one item from the menu.';
            $show_modal = true;
        } else {
            $chk = mysqli_prepare($conn, "SELECT table_key FROM restaurant_tables WHERE table_key = ? AND restro_key = ?");
            mysqli_stmt_bind_param($chk, 'ss', $table_key, $restro_key);
            mysqli_stmt_execute($chk);
            $table_ok = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
            mysqli_stmt_close($chk);

            if (!$table_ok) {
                $error = 'Invalid table selected.';
                $show_modal = true;
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

                header('Location: orders.php?tab=active');
                exit;
            }
        }
    }
}

// Orders list (from restroai.sql)
if ($tab === 'completed') {
    $order_sql = "
        SELECT o.order_key, o.table_key, t.table_name, o.status, o.total_amount, o.items_summary, o.ordered_at
        FROM orders o
        JOIN restaurant_tables t ON t.table_key = o.table_key
        WHERE o.restro_key = ? AND o.status = 'completed'
        ORDER BY o.ordered_at DESC
        LIMIT 50
    ";
} else {
    $order_sql = "
        SELECT o.order_key, o.table_key, t.table_name, o.status, o.total_amount, o.items_summary, o.ordered_at
        FROM orders o
        JOIN restaurant_tables t ON t.table_key = o.table_key
        WHERE o.restro_key = ? AND o.status != 'completed'
        ORDER BY o.ordered_at DESC
    ";
}
$stmt = mysqli_prepare($conn, $order_sql);
mysqli_stmt_bind_param($stmt, 's', $restro_key);
mysqli_stmt_execute($stmt);
$orders = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

// Tables for new-order form
$stmt = mysqli_prepare($conn, "
    SELECT table_key, table_name, seats
    FROM restaurant_tables
    WHERE restro_key = ?
    ORDER BY table_name
");
mysqli_stmt_bind_param($stmt, 's', $restro_key);
mysqli_stmt_execute($stmt);
$tables = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$stmt = mysqli_prepare($conn, "
    SELECT DISTINCT table_key
    FROM orders
    WHERE restro_key = ? AND status != 'completed'
");
mysqli_stmt_bind_param($stmt, 's', $restro_key);
mysqli_stmt_execute($stmt);
$occupied = [];
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
    $occupied[$row['table_key']] = true;
}
mysqli_stmt_close($stmt);

// Active menu items for new-order form
$stmt = mysqli_prepare($conn, "
    SELECT item_key, name, emoji, price
    FROM items
    WHERE restro_key = ? AND is_active = 1
    ORDER BY sort_order, name
");
mysqli_stmt_bind_param($stmt, 's', $restro_key);
mysqli_stmt_execute($stmt);
$menu_items = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Live Orders — RestroAI</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<style>
  /* Table requests: Call Waiter / Ask for Bill notifications */
  .table-requests-panel{
    margin-bottom:22px;border:1px solid rgba(255,90,31,0.3) !important;
    background:linear-gradient(155deg, rgba(255,90,31,0.06), rgba(255,31,76,0.02)) !important;
  }
  .tr-row{
    display:flex;align-items:center;justify-content:space-between;gap:14px;
    padding:14px 20px;border-bottom:1px solid var(--line,rgba(255,255,255,0.08));
  }
  .tr-row:last-child{border-bottom:none;}
  .tr-left{display:flex;align-items:center;gap:12px;min-width:0;}
  .tr-ico{
    width:38px;height:38px;border-radius:10px;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;font-size:17px;
    background:rgba(255,90,31,0.12);border:1px solid rgba(255,90,31,0.3);
  }
  .tr-text{min-width:0;}
  .tr-title{font-size:13.5px;font-weight:600;color:var(--text-hi,#f4f2ef);}
  .tr-meta{font-size:11.5px;color:var(--text-mid,#a8a6a2);font-family:var(--font-mono,'IBM Plex Mono',monospace);margin-top:2px;}
  .tr-resolve-btn{
    flex-shrink:0;font-family:var(--font-mono,'IBM Plex Mono',monospace);font-size:11px;font-weight:700;
    text-transform:uppercase;letter-spacing:0.04em;color:#0a0500;
    background:linear-gradient(135deg,#ff5a1f,#ff1f4c);border:none;border-radius:100px;
    padding:9px 16px;cursor:pointer;transition:transform .15s ease, opacity .2s ease;
  }
  .tr-resolve-btn:hover{transform:translateY(-1px);}
  .tr-resolve-btn:disabled{opacity:0.5;cursor:default;transform:none;}
  .tr-row.tr-new{animation:tr-flash 1.4s ease-out 2;}
  @keyframes tr-flash{
    0%{background:rgba(255,90,31,0.22);}
    100%{background:transparent;}
  }
  .icon-btn{position:relative;}
  .bell-count{
    position:absolute;top:-4px;right:-4px;min-width:16px;height:16px;padding:0 3px;
    border-radius:100px;background:#ff1f4c;color:#fff;font-size:10px;font-weight:700;
    display:none;align-items:center;justify-content:center;font-family:var(--font-mono,'IBM Plex Mono',monospace);
  }
</style>
</head>
<body>

<div id="bg-glow"></div>

<div class="shell">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <div class="main">
    <div class="topbar">
      <div><h1>Live Orders</h1><div class="sub">Track every order from kitchen to table</div></div>
      <div class="topbar-right">
        <div class="search-box">🔍 Search orders, dishes…</div>
        <div class="icon-btn" id="bellIcon">🔔<span class="dot"></span><span class="bell-count" id="bellCount">0</span></div>
        <div class="restaurant-pill"><span class="dot"></span>Open — Dine-in</div>
      </div>
    </div>

    <div class="content">

      <div class="panel table-requests-panel" id="tableRequestsPanel" style="display:none;">
        <div class="panel-inner-head">
          <div class="title">🔔 Table Requests</div>
          <div class="live-pill" id="requestsLivePill">LIVE</div>
        </div>
        <div id="tableRequestsList"></div>
      </div>

      <div class="panel">
        <div class="panel-inner-head">
          <div class="title">Orders</div>
            <div style="display:flex;align-items:center;gap:10px;">
            <div class="tab-row">
              <a href="orders.php?tab=active" class="tab-btn<?php echo $tab === 'active' ? ' active' : ''; ?>">Active</a>
              <a href="orders.php?tab=completed" class="tab-btn<?php echo $tab === 'completed' ? ' active' : ''; ?>">Completed</a>
            </div>
            <?php if ($tab === 'active'): ?>
            <div class="live-pill" id="ordersLivePill">CONNECTING</div>
            <?php endif; ?>
            <a href="orders.php?new=1&amp;tab=<?php echo urlencode($tab); ?>" class="btn btn-primary btn-sm">+ New Order</a>
          </div>
        </div>
        <div class="order-row head">
          <div>Table</div><div>Items</div><div>Status</div><div style="text-align:right;">Amount</div><div></div>
        </div>
        <div id="ordersLiveList">
        <?php if (count($orders) === 0): ?>
          <div class="empty-note"><?php echo $tab === 'completed' ? 'No completed orders yet.' : 'No active orders — tap "+ New Order" to add one.'; ?></div>
        <?php else: ?>
          <?php foreach ($orders as $o): ?>
            <div class="order-row" data-order-key="<?php echo htmlspecialchars($o['order_key']); ?>">
              <div class="table-id"><?php echo htmlspecialchars($o['table_name']); ?></div>
              <div class="items"><?php echo htmlspecialchars($o['items_summary']); ?><span><?php echo $tab === 'completed' ? 'Completed' : htmlspecialchars($status_label[$o['status']] ?? $o['status']); ?> · <?php echo timeAgo($o['ordered_at']); ?></span></div>
              <div><span class="status-pill status-<?php echo htmlspecialchars($o['status']); ?>"><?php echo htmlspecialchars($status_label[$o['status']] ?? $o['status']); ?></span></div>
              <div class="amount">₹<?php echo number_format((float) $o['total_amount'], 0, '.', ','); ?></div>
              <div class="action-cell">
                <?php if ($o['status'] !== 'completed' && isset($status_next[$o['status']])): ?>
                  <?php if ($o['status'] === 'served'): ?>
                    <a href="tables.php?checkout=<?php echo urlencode($o['table_key']); ?>&return=<?php echo urlencode('orders.php?tab=' . $tab); ?>" class="advance-btn"><?php echo htmlspecialchars($status_next['served']); ?></a>
                  <?php else: ?>
                  <form method="post" action="orders.php?tab=<?php echo urlencode($tab); ?>" style="display:inline;">
                    <input type="hidden" name="advance_order" value="<?php echo htmlspecialchars($o['order_key']); ?>">
                    <button type="submit" class="advance-btn"><?php echo htmlspecialchars($status_next[$o['status']]); ?></button>
                  </form>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
        </div>
      </div>

    </div>
  </div>
</div>

<div class="modal-overlay<?php echo $show_modal ? ' open' : ''; ?>" id="orderModalOverlay">
  <div class="modal">
    <h3>New Order</h3>
    <?php if ($error !== ''): ?>
      <p style="color:#ff6b6b;font-size:13px;margin-bottom:14px;"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>
    <form method="post" action="orders.php?tab=<?php echo urlencode($tab); ?>">
      <input type="hidden" name="create_order" value="1">
      <div class="field">
        <label for="table_key">Table</label>
        <select id="table_key" name="table_key" required>
          <?php if (count($tables) === 0): ?>
            <option value="">No tables yet — add one first</option>
          <?php else: ?>
            <option value="">Select a table</option>
            <?php foreach ($tables as $t):
              $busy = !empty($occupied[$t['table_key']]);
            ?>
              <option value="<?php echo htmlspecialchars($t['table_key']); ?>"<?php echo $busy ? ' disabled' : ''; ?><?php echo (($_POST['table_key'] ?? '') === $t['table_key']) ? ' selected' : ''; ?>>
                <?php echo htmlspecialchars($t['table_name']); ?> · <?php echo (int) $t['seats']; ?> seats<?php echo $busy ? ' (Occupied)' : ''; ?>
              </option>
            <?php endforeach; ?>
          <?php endif; ?>
        </select>
      </div>
      <div class="field">
        <label>Items from menu</label>
        <div class="item-picker">
          <?php if (count($menu_items) === 0): ?>
            <div class="empty-picker-note">No active menu items — add or enable one in Menu Management.</div>
          <?php else: ?>
            <?php foreach ($menu_items as $item):
              $qty = (int) ($_POST['qty'][$item['item_key']] ?? 0);
            ?>
              <div class="item-picker-row">
                <div>
                  <div class="ipr-name"><?php echo htmlspecialchars($item['emoji'] ?: '🍽'); ?> <?php echo htmlspecialchars($item['name']); ?></div>
                  <span class="ipr-price">₹<?php echo number_format((float) $item['price'], 0, '.', ','); ?></span>
                </div>
                <div class="qty-stepper">
                  <input type="number" name="qty[<?php echo htmlspecialchars($item['item_key']); ?>]" value="<?php echo $qty; ?>" min="0" max="99" style="width:48px;text-align:center;background:var(--glass);border:1px solid var(--line);border-radius:8px;color:var(--text-hi);padding:4px 6px;font-family:var(--font-mono);font-size:13px;">
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="modal-actions">
        <a href="orders.php?tab=<?php echo urlencode($tab); ?>" class="btn btn-ghost">Cancel</a>
        <button type="submit" class="btn btn-primary"<?php echo (count($tables) === 0 || count($menu_items) === 0) ? ' disabled' : ''; ?>>Add Order</button>
      </div>
    </form>
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

<?php if ($tab === 'active'): ?>
<script>
window.ORDERS_LIVE_CONFIG = {
  enabled: true,
  useSSE: true,
  sseEndpoint: 'sse-stream.php',
  tab: 'active',
  endpoint: 'orders-poll.php',
  pollMs: 5000,
  updateStats: false,
  advanceFormAction: 'orders.php?tab=active',
  returnPath: 'orders.php?tab=active',
  emptyActive: 'No active orders — tap "+ New Order" to add one.',
  emptyCompleted: 'No completed orders yet.',
  statusLabel: <?php echo json_encode($status_label); ?>,
  statusNext: <?php echo json_encode($status_next); ?>
};
</script>
<script src="assets/orders-live.js?v=3"></script>
<?php endif; ?>

<script>
(function tableRequestsWatcher(){
  const panel = document.getElementById('tableRequestsPanel');
  const list = document.getElementById('tableRequestsList');
  const bellCount = document.getElementById('bellCount');
  if(!panel || !list) return;

  const TYPE_META = {
    waiter: {icon:'🛎️', label:'requested a waiter'},
    bill:   {icon:'🧾', label:'asked for the bill'}
  };

  let knownKeys = new Set();
  let firstLoad = true;

  function timeAgoLabel(seconds){
    if(seconds < 60) return 'just now';
    const mins = Math.round(seconds / 60);
    if(mins === 1) return '1m ago';
    if(mins < 60) return mins + 'm ago';
    return Math.round(mins / 60) + 'h ago';
  }

  function render(requests){
    if(!requests || requests.length === 0){
      panel.style.display = 'none';
      if(bellCount) bellCount.style.display = 'none';
      return;
    }
    panel.style.display = 'block';
    if(bellCount){
      bellCount.style.display = 'flex';
      bellCount.textContent = requests.length > 9 ? '9+' : String(requests.length);
    }

    list.innerHTML = requests.map(function(r){
      const meta = TYPE_META[r.type] || {icon:'🔔', label:'made a request'};
      const isNew = !knownKeys.has(r.request_key) && !firstLoad;
      return '<div class="tr-row' + (isNew ? ' tr-new' : '') + '" data-key="' + r.request_key + '">' +
        '<div class="tr-left">' +
          '<div class="tr-ico">' + meta.icon + '</div>' +
          '<div class="tr-text">' +
            '<div class="tr-title">' + r.table_name + ' ' + meta.label + '</div>' +
            '<div class="tr-meta">' + timeAgoLabel(r.seconds_ago) + '</div>' +
          '</div>' +
        '</div>' +
        '<button type="button" class="tr-resolve-btn" onclick="resolveTableRequest(\'' + r.request_key + '\', this)">Mark handled</button>' +
      '</div>';
    }).join('');

    knownKeys = new Set(requests.map(function(r){ return r.request_key; }));
    firstLoad = false;
  }

  window.renderTableRequests = render;

  window.resolveTableRequest = function(requestKey, btn){
    btn.disabled = true;
    btn.textContent = 'Clearing…';
    fetch('table-requests-poll.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'resolve_request=' + encodeURIComponent(requestKey)
    })
      .then(function(r){ return r.json(); })
      .then(function(data){
        if(data && data.ok){
          const row = btn.closest('.tr-row');
          if(row) row.remove();
          const remaining = list.querySelectorAll('.tr-row').length;
          if(remaining === 0){
            panel.style.display = 'none';
            if(bellCount) bellCount.style.display = 'none';
          } else if(bellCount){
            bellCount.textContent = remaining > 9 ? '9+' : String(remaining);
          }
        } else {
          btn.disabled = false;
          btn.textContent = 'Mark handled';
        }
      })
      .catch(function(){
        btn.disabled = false;
        btn.textContent = 'Mark handled';
      });
  };
})();
</script>

</body>
</html>
