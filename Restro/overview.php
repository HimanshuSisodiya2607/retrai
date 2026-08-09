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
$current_page = 'overview';

$tab = (isset($_GET['tab']) && $_GET['tab'] === 'completed') ? 'completed' : 'active';

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

// Advance order status (form POST, no AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['advance_order'])) {
    $order_key = $_POST['advance_order'];
    $stmt = mysqli_prepare($conn, "SELECT status, table_key FROM orders WHERE order_key = ? AND restro_key = ?");
    mysqli_stmt_bind_param($stmt, 'ss', $order_key, $restro_key);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if ($row) {
        if ($row['status'] === 'served') {
            header('Location: tables.php?checkout=' . urlencode($row['table_key']) . '&return=' . urlencode('overview.php?tab=active'));
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
    header('Location: overview.php?tab=active');
    exit;
}

// Restaurant info
$stmt = mysqli_prepare($conn, "SELECT restaurant_name, owner_name, city FROM restaurants WHERE restro_key = ?");
mysqli_stmt_bind_param($stmt, 's', $restro_key);
mysqli_stmt_execute($stmt);
$restaurant = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

// Stats
$stats = ['revenue' => 0, 'orders' => 0, 'avg' => 0, 'tables_total' => 0, 'tables_occupied' => 0];

$stmt = mysqli_prepare($conn, "
    SELECT COALESCE(SUM(total_amount), 0) AS revenue, COUNT(*) AS orders
    FROM orders
    WHERE restro_key = ? AND DATE(ordered_at) = CURDATE()
");
mysqli_stmt_bind_param($stmt, 's', $restro_key);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
$stats['revenue'] = (float) $row['revenue'];
$stats['orders'] = (int) $row['orders'];
$stats['avg'] = $stats['orders'] > 0 ? (int) round($stats['revenue'] / $stats['orders']) : 0;

$stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM restaurant_tables WHERE restro_key = ?");
mysqli_stmt_bind_param($stmt, 's', $restro_key);
mysqli_stmt_execute($stmt);
$stats['tables_total'] = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];
mysqli_stmt_close($stmt);

$stmt = mysqli_prepare($conn, "
    SELECT COUNT(DISTINCT table_key) AS c
    FROM orders
    WHERE restro_key = ? AND status != 'completed'
");
mysqli_stmt_bind_param($stmt, 's', $restro_key);
mysqli_stmt_execute($stmt);
$stats['tables_occupied'] = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];
mysqli_stmt_close($stmt);

$occupied_note = 'All clear';
if ($stats['tables_occupied'] === $stats['tables_total'] && $stats['tables_total'] > 0) {
    $occupied_note = 'Fully booked';
} elseif ($stats['tables_occupied'] > 0) {
    $occupied_note = 'Filling up';
}

// Orders list (reuses query from restroai.sql)
if ($tab === 'completed') {
    $order_sql = "
        SELECT o.order_key, o.table_key, t.table_name, o.status, o.total_amount, o.items_summary, o.ordered_at
        FROM orders o
        JOIN restaurant_tables t ON t.table_key = o.table_key
        WHERE o.restro_key = ? AND o.status = 'completed'
        ORDER BY o.ordered_at DESC
        LIMIT 20
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

// Revenue chart — last 7 days
$chart_days = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $chart_days[$d] = ['label' => date('D', strtotime($d)), 'total' => 0.0];
}
$stmt = mysqli_prepare($conn, "
    SELECT DATE(ordered_at) AS day, SUM(total_amount) AS total
    FROM orders
    WHERE restro_key = ? AND ordered_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(ordered_at)
");
mysqli_stmt_bind_param($stmt, 's', $restro_key);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
    if (isset($chart_days[$row['day']])) {
        $chart_days[$row['day']]['total'] = (float) $row['total'];
    }
}
mysqli_stmt_close($stmt);

$max_chart = max(array_column($chart_days, 'total'));
if ($max_chart <= 0) $max_chart = 1;
$peak_day = null;
foreach ($chart_days as $day_key => $info) {
    if ($info['total'] >= $max_chart) {
        $peak_day = $day_key;
    }
}

$topbar_date = date('l, F j');
$topbar_city = $restaurant['city'] ?? '';

// Fetch real AI insights for this restaurant
$stmt = mysqli_prepare($conn, "
    SELECT icon, title, description, impact, card_type
    FROM ai_insights
    WHERE restro_key = ?
    ORDER BY sort_order ASC
    LIMIT 3
");
mysqli_stmt_bind_param($stmt, 's', $restro_key);
mysqli_stmt_execute($stmt);
$overview_insights = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/png" href="../assets/logo-icon.png">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Overview — Dinetous</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=<?php echo @filemtime(__DIR__ . '/assets/style.css'); ?>">
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
  .icon-btn .dot{display:none;}
</style>
</head>
<body>

<div id="bg-glow"></div>

<div class="shell">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <div class="main">
    <div class="topbar">
      <div><h1>Overview</h1><div class="sub"><?php echo htmlspecialchars($topbar_date); ?><?php echo $topbar_city !== '' ? ' · ' . htmlspecialchars($topbar_city) : ''; ?></div></div>
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

      <div class="panel table-requests-panel" id="tableRequestsPanel" style="display:none;">
        <div class="panel-inner-head">
          <div class="title">🔔 Table Requests</div>
          <div class="live-pill" id="requestsLivePill">LIVE</div>
        </div>
        <div id="tableRequestsList"></div>
      </div>

      <div class="stat-grid">
        <div class="stat-card"><div class="label">Today's Revenue</div><div class="value" id="statRevenueToday">₹<?php echo number_format($stats['revenue'], 0, '.', ','); ?></div><div class="delta up">↑ 18% vs last Fri</div></div>
        <div class="stat-card"><div class="label">Orders Today</div><div class="value" id="statOrdersToday"><?php echo $stats['orders']; ?></div><div class="delta up">↑ 9% vs last Fri</div></div>
        <div class="stat-card"><div class="label">Avg. Order Value</div><div class="value" id="statAvgOrder">₹<?php echo number_format($stats['avg']); ?></div><div class="delta up">↑ 4.2%</div></div>
        <div class="stat-card"><div class="label">Tables Occupied</div><div class="value"><?php echo $stats['tables_occupied']; ?> / <?php echo $stats['tables_total']; ?></div><div class="delta up"><?php echo htmlspecialchars($occupied_note); ?></div></div>
      </div>

      <div class="panel-head"><h2>Quick Access</h2></div>
      <div class="quick-grid">
        <a class="quick-tile" href="tables.php"><div class="qt-ico">▥</div><h4>Tables</h4><p>View floor status and manage seating.</p></a>
        <a class="quick-tile" href="menu.php"><div class="qt-ico">▣</div><h4>Menu</h4><p>Add dishes, edit prices, toggle availability.</p></a>
        <a class="quick-tile" href="analytics.php"><div class="qt-ico">▤</div><h4>Analytics</h4><p>Dig into revenue trends and top dishes.</p></a>
      </div>

      <div class="split-grid">
        <div class="panel">
          <div class="panel-inner-head">
            <div class="title">Orders</div>
            <div style="display:flex;align-items:center;gap:10px;">
              <div class="tab-row">
                <a href="overview.php?tab=active" class="tab-btn<?php echo $tab === 'active' ? ' active' : ''; ?>">Active</a>
                <a href="overview.php?tab=completed" class="tab-btn<?php echo $tab === 'completed' ? ' active' : ''; ?>">Completed</a>
              </div>
              <?php if ($tab === 'active'): ?>
              <div class="live-pill" id="ordersLivePill">CONNECTING</div>
              <?php endif; ?>
              <a class="btn btn-primary btn-sm" href="orders.php?new=1">+ New Order</a>
            </div>
          </div>
          <div class="order-row head">
            <div>Table</div><div>Items</div><div>Status</div><div style="text-align:right;">Amount</div><div></div>
          </div>
          <div id="ordersLiveList">
          <?php if (count($orders) === 0): ?>
            <div class="empty-note"><?php echo $tab === 'completed' ? 'No completed orders yet.' : 'No active orders — tap "New Order" to add one.'; ?></div>
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
                      <a href="tables.php?checkout=<?php echo urlencode($o['table_key']); ?>&return=<?php echo urlencode('overview.php?tab=active'); ?>" class="advance-btn"><?php echo htmlspecialchars($status_next['served']); ?></a>
                    <?php else: ?>
                    <form method="post" action="overview.php?tab=active" style="display:inline;">
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
          <div style="padding:12px 20px;border-top:1px solid var(--line);"><a class="link" href="orders.php" style="font-size:12px;">View all orders →</a></div>
        </div>

        <div class="panel">
          <div class="panel-inner-head">
            <div class="title">AI Restaurant Brain</div>
            <div class="live-pill">ANALYZING</div>
          </div>
          <div class="ai-panel-body">
          <?php if (empty($overview_insights)): ?>
            <div style="padding:28px 20px;text-align:center;color:var(--text-mid);font-size:13px;">
              No AI insights generated yet.<br>
              <a href="ai-insights.php" class="link" style="margin-top:8px;display:inline-block;">Generate AI Insights →</a>
            </div>
          <?php else: ?>
            <?php foreach ($overview_insights as $ins): ?>
              <div class="ai-card<?php echo ($ins['card_type'] ?? '') === 'warn' ? ' warn' : ''; ?>">
                <span class="kicker"><?php echo htmlspecialchars($ins['icon'] ?? '💡'); ?></span>
                <h4><?php echo htmlspecialchars($ins['title']); ?></h4>
                <p><?php echo htmlspecialchars($ins['description']); ?></p>
                <span class="impact"><?php echo htmlspecialchars($ins['impact']); ?></span>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
          </div>
          <div style="padding:4px 20px 16px;"><a class="link" href="ai-insights.php" style="font-size:12px;">View all insights →</a></div>
        </div>
      </div>

      <div class="panel chart-panel">
          <div class="panel-inner-head" style="padding:0 0 16px;border-bottom:none;"><div class="title">Revenue — Last 7 Days</div><a class="link" href="analytics.php" style="font-size:11.5px;">Full analytics →</a></div>
          <div class="chart-bars">
            <?php foreach ($chart_days as $day_key => $info):
              $height = (int) round(($info['total'] / $max_chart) * 100);
              $val_label = $info['total'] >= 1000 ? round($info['total'] / 1000) . 'k' : (string) (int) $info['total'];
              $peak_class = ($day_key === $peak_day && $info['total'] > 0) ? ' peak' : '';
            ?>
            <div class="bar-col<?php echo $peak_class; ?>">
              <div class="val"><?php echo htmlspecialchars($val_label); ?></div>
              <div class="bar" style="height:<?php echo max($height, 4); ?>%;"></div>
              <div class="day"><?php echo htmlspecialchars($info['label']); ?></div>
            </div>
            <?php endforeach; ?>
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

<?php if ($tab === 'active'): ?>
<script>
window.ORDERS_LIVE_CONFIG = {
  enabled: true,
  useSSE: true,
  sseEndpoint: 'sse-stream.php',
  tab: 'active',
  endpoint: 'orders-poll.php',
  pollMs: 5000,
  updateStats: true,
  advanceFormAction: 'overview.php?tab=active',
  returnPath: 'overview.php?tab=active',
  emptyActive: 'No active orders — tap "New Order" to add one.',
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
    if(requests.length === 0){
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

  function pollRequests(){
    fetch('table-requests-poll.php')
      .then(function(r){ return r.json(); })
      .then(function(data){
        if(!data.ok) return;
        render(data.requests);
      })
      .catch(function(){});
  }

  pollRequests(); // Initial fetch
})();
</script>

<script src="assets/topbar-search.js?v=<?php echo @filemtime(__DIR__ . '/assets/topbar-search.js'); ?>"></script>
</body>
</html>