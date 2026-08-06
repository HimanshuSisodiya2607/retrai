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
$current_page = 'analytics';

function formatShortMoney($amount) {
    $amount = (float) $amount;
    if ($amount >= 1000) {
        return '₹' . round($amount / 1000) . 'k';
    }
    return '₹' . number_format($amount, 0, '.', ',');
}

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

$week_revenue = array_sum(array_column($chart_days, 'total'));
$max_chart = max(array_column($chart_days, 'total'));
if ($max_chart <= 0) {
    $max_chart = 1;
}

$best_day_label = '—';
$best_day_amount = 0.0;
$peak_day = null;
foreach ($chart_days as $day_key => $info) {
    if ($info['total'] >= $best_day_amount) {
        $best_day_amount = $info['total'];
        $best_day_label = date('l', strtotime($day_key));
        $peak_day = $day_key;
    }
}

// 7-day order count & average
$stmt = mysqli_prepare($conn, "
    SELECT COUNT(*) AS orders, COALESCE(SUM(total_amount), 0) AS revenue
    FROM orders
    WHERE restro_key = ? AND ordered_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
");
mysqli_stmt_bind_param($stmt, 's', $restro_key);
mysqli_stmt_execute($stmt);
$week_stats = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

$week_orders = (int) $week_stats['orders'];
$avg_order = $week_orders > 0 ? (int) round((float) $week_stats['revenue'] / $week_orders) : 0;

// Top dishes by revenue (last 7 days)
$top_dishes = [];
$stmt = mysqli_prepare($conn, "
    SELECT oi.item_name, SUM(oi.line_total) AS revenue
    FROM order_items oi
    JOIN orders o ON o.order_key = oi.order_key AND o.restro_key = oi.restro_key
    WHERE oi.restro_key = ? AND o.ordered_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY oi.item_name
    ORDER BY revenue DESC
    LIMIT 5
");
mysqli_stmt_bind_param($stmt, 's', $restro_key);
mysqli_stmt_execute($stmt);
$top_dishes = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$max_dish_revenue = 0.0;
foreach ($top_dishes as $dish) {
    $max_dish_revenue = max($max_dish_revenue, (float) $dish['revenue']);
}
if ($max_dish_revenue <= 0) {
    $max_dish_revenue = 1;
}

// Orders by hour bucket (last 7 days)
$hour_buckets = [
    '12–2 PM' => [12, 13],
    '2–5 PM' => [14, 15, 16],
    '5–7 PM' => [17, 18],
    '7–9 PM' => [19, 20],
    '9–11 PM' => [21, 22],
];
$bucket_counts = array_fill_keys(array_keys($hour_buckets), 0);

$stmt = mysqli_prepare($conn, "
    SELECT HOUR(ordered_at) AS hr, COUNT(*) AS cnt
    FROM orders
    WHERE restro_key = ? AND ordered_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY HOUR(ordered_at)
");
mysqli_stmt_bind_param($stmt, 's', $restro_key);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
    $hr = (int) $row['hr'];
    foreach ($hour_buckets as $label => $hours) {
        if (in_array($hr, $hours, true)) {
            $bucket_counts[$label] += (int) $row['cnt'];
            break;
        }
    }
}
mysqli_stmt_close($stmt);

$max_bucket = max($bucket_counts);
if ($max_bucket <= 0) {
    $max_bucket = 1;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Analytics — RestroAI</title>
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
      <div><h1>Analytics</h1><div class="sub">Deeper trends across revenue, dishes and hours</div></div>
      <div class="topbar-right">
        <div class="search-box">🔍 Search orders, dishes…</div>
        <div class="icon-btn">🔔<span class="dot"></span></div>
        <div class="restaurant-pill"><span class="dot"></span>Open — Dine-in</div>
      </div>
    </div>

    <div class="content">

      <div class="stat-grid">
        <div class="stat-card"><div class="label">7-Day Revenue</div><div class="value">₹<?php echo number_format($week_revenue, 0, '.', ','); ?></div><div class="delta up"><?php echo $week_orders; ?> orders</div></div>
        <div class="stat-card"><div class="label">Best Day</div><div class="value"><?php echo htmlspecialchars($best_day_label); ?></div><div class="delta up"><?php echo $best_day_amount > 0 ? '₹' . number_format($best_day_amount, 0, '.', ',') : 'No sales yet'; ?></div></div>
        <div class="stat-card"><div class="label">Avg. Order Value</div><div class="value">₹<?php echo number_format($avg_order); ?></div><div class="delta up">Last 7 days</div></div>
        <div class="stat-card"><div class="label">Orders (7 Days)</div><div class="value"><?php echo $week_orders; ?></div><div class="delta up"><?php echo count($top_dishes); ?> top dishes</div></div>
      </div>

      <div class="panel chart-panel" style="margin-bottom:32px;">
        <div class="panel-inner-head" style="padding:0 0 16px;border-bottom:none;"><div class="title">Revenue — Last 7 Days</div><div class="link" style="font-size:11.5px;">₹<?php echo number_format($week_revenue, 0, '.', ','); ?> total</div></div>
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

      <div class="split-grid">
        <div class="panel" style="padding:20px 22px;">
          <div class="panel-inner-head" style="padding:0 0 14px;border-bottom:none;"><div class="title">Top Dishes by Revenue</div></div>
          <?php if (count($top_dishes) === 0): ?>
            <div class="empty-note">No dish sales in the last 7 days.</div>
          <?php else: ?>
            <?php foreach ($top_dishes as $dish):
              $width = (int) round(((float) $dish['revenue'] / $max_dish_revenue) * 100);
            ?>
            <div class="bar-h-row">
              <div class="bar-h-label"><?php echo htmlspecialchars($dish['item_name']); ?></div>
              <div class="bar-h-track"><i class="bar-h-fill" style="width:<?php echo max($width, 4); ?>%"></i></div>
              <div class="bar-h-val"><?php echo htmlspecialchars(formatShortMoney($dish['revenue'])); ?></div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <div class="panel" style="padding:20px 22px;">
          <div class="panel-inner-head" style="padding:0 0 14px;border-bottom:none;"><div class="title">Orders by Hour</div></div>
          <?php if ($week_orders === 0): ?>
            <div class="empty-note">No orders in the last 7 days.</div>
          <?php else: ?>
            <?php foreach ($bucket_counts as $label => $count):
              $width = (int) round(($count / $max_bucket) * 100);
            ?>
            <div class="bar-h-row">
              <div class="bar-h-label"><?php echo htmlspecialchars($label); ?></div>
              <div class="bar-h-track"><i class="bar-h-fill" style="width:<?php echo $count > 0 ? max($width, 4) : 0; ?>%"></i></div>
              <div class="bar-h-val"><?php echo $count; ?></div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
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

</body>
</html>
