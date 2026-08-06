<?php
require_once __DIR__ . '/includes/auth.php';

$page_title = 'Platform Dashboard';
$page_sub = date('D, j M Y') . ' · across all restaurants';
$current_page = 'dashboard';

$total_restros  = (int) admin_scalar($conn, "SELECT COUNT(*) FROM restaurants");
$active_restros = (int) admin_scalar($conn, "SELECT COUNT(*) FROM restaurants WHERE is_active = 1");
$total_items    = (int) admin_scalar($conn, "SELECT COUNT(*) FROM items");
$total_tables   = (int) admin_scalar($conn, "SELECT COUNT(*) FROM restaurant_tables");
$total_staff    = (int) admin_scalar($conn, "SELECT COUNT(*) FROM staff");
$total_orders   = (int) admin_scalar($conn, "SELECT COUNT(*) FROM orders");
$open_orders    = (int) admin_scalar($conn, "SELECT COUNT(*) FROM orders WHERE status != 'completed'");
$gross_revenue  = (float) admin_scalar($conn, "SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status = 'completed'");
$today_revenue  = (float) admin_scalar($conn, "SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status = 'completed' AND DATE(ordered_at) = CURDATE()");
$today_orders   = (int) admin_scalar($conn, "SELECT COUNT(*) FROM orders WHERE DATE(ordered_at) = CURDATE()");

// Per-restaurant leaderboard.
$leaders = admin_rows($conn, "
    SELECT r.restro_key, r.restaurant_name, r.city, r.plan_name, r.is_active,
           COUNT(DISTINCT o.order_key) AS orders,
           COALESCE(SUM(CASE WHEN o.status = 'completed' THEN o.total_amount END), 0) AS revenue,
           (SELECT COUNT(*) FROM items i WHERE i.restro_key = r.restro_key) AS items
    FROM restaurants r
    LEFT JOIN orders o ON o.restro_key = r.restro_key
    GROUP BY r.restro_key, r.restaurant_name, r.city, r.plan_name, r.is_active
    ORDER BY revenue DESC, orders DESC
");

// Last 7 days of platform-wide completed revenue.
$chart = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $chart[$d] = ['label' => date('D', strtotime($d)), 'total' => 0.0];
}
foreach (admin_rows($conn, "
    SELECT DATE(ordered_at) AS day, SUM(total_amount) AS total
    FROM orders
    WHERE status = 'completed' AND ordered_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(ordered_at)
") as $row) {
    if (isset($chart[$row['day']])) {
        $chart[$row['day']]['total'] = (float) $row['total'];
    }
}
$chart_max = max(array_column($chart, 'total')) ?: 1;

$recent = admin_rows($conn, "
    SELECT o.order_key, o.status, o.total_amount, o.ordered_at,
           r.restaurant_name, t.table_name
    FROM orders o
    JOIN restaurants r ON r.restro_key = o.restro_key
    LEFT JOIN restaurant_tables t ON t.table_key = o.table_key
    ORDER BY o.ordered_at DESC
    LIMIT 8
");

include __DIR__ . '/includes/header.php';
?>

<div class="stat-row">
  <div class="stat-tile accent">
    <div class="k">Restaurants</div>
    <div class="v"><?php echo $total_restros; ?></div>
    <div class="s"><?php echo $active_restros; ?> active · <?php echo $total_restros - $active_restros; ?> suspended</div>
  </div>
  <div class="stat-tile">
    <div class="k">Gross revenue</div>
    <div class="v"><?php echo admin_money($gross_revenue); ?></div>
    <div class="s"><?php echo admin_money($today_revenue); ?> today</div>
  </div>
  <div class="stat-tile">
    <div class="k">Orders</div>
    <div class="v"><?php echo number_format($total_orders); ?></div>
    <div class="s"><?php echo $open_orders; ?> open · <?php echo $today_orders; ?> today</div>
  </div>
  <div class="stat-tile">
    <div class="k">Menu items</div>
    <div class="v"><?php echo number_format($total_items); ?></div>
    <div class="s">across all restaurants</div>
  </div>
  <div class="stat-tile">
    <div class="k">Tables &amp; staff</div>
    <div class="v"><?php echo $total_tables; ?> / <?php echo $total_staff; ?></div>
    <div class="s">tables · staff members</div>
  </div>
</div>

<div class="panel">
  <div class="panel-head">
    <h3>Platform revenue — last 7 days</h3>
    <span class="sub">completed orders only</span>
  </div>
  <div style="display:flex;align-items:flex-end;gap:10px;height:150px;padding-top:10px;">
    <?php foreach ($chart as $day): ?>
      <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:8px;height:100%;justify-content:flex-end;">
        <div style="font-family:var(--font-mono);font-size:10px;color:var(--text-mid);">
          <?php echo $day['total'] > 0 ? admin_money($day['total']) : ''; ?>
        </div>
        <div style="width:100%;border-radius:6px 6px 0 0;background:var(--grad-ai);opacity:<?php echo $day['total'] > 0 ? '1' : '0.15'; ?>;height:<?php echo max(3, round($day['total'] / $chart_max * 100)); ?>%;"></div>
        <div style="font-family:var(--font-mono);font-size:10px;color:var(--text-low);"><?php echo $day['label']; ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="panel">
  <div class="panel-head">
    <h3>Restaurants by revenue</h3>
    <a href="restaurants.php" class="btn-xs">Manage all →</a>
  </div>
  <?php if (!$leaders): ?>
    <div class="empty">No restaurants have signed up yet.</div>
  <?php else: ?>
  <table class="dt">
    <thead>
      <tr><th>Restaurant</th><th>Plan</th><th>Status</th><th class="r">Items</th><th class="r">Orders</th><th class="r">Revenue</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($leaders as $l): ?>
      <tr>
        <td>
          <a class="link" href="restaurant.php?key=<?php echo urlencode($l['restro_key']); ?>"><?php echo htmlspecialchars($l['restaurant_name']); ?></a>
          <?php if (!empty($l['city'])): ?><div style="font-size:11px;color:var(--text-low);"><?php echo htmlspecialchars($l['city']); ?></div><?php endif; ?>
        </td>
        <td><span class="pill plan"><?php echo htmlspecialchars($l['plan_name'] ?: '—'); ?></span></td>
        <td><span class="pill <?php echo $l['is_active'] ? 'on' : 'off'; ?>"><?php echo $l['is_active'] ? 'Active' : 'Suspended'; ?></span></td>
        <td class="r"><?php echo (int) $l['items']; ?></td>
        <td class="r"><?php echo (int) $l['orders']; ?></td>
        <td class="r"><?php echo admin_money((float) $l['revenue']); ?></td>
        <td class="r"><a class="btn-xs" href="restaurant.php?key=<?php echo urlencode($l['restro_key']); ?>">Open</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="panel">
  <div class="panel-head">
    <h3>Latest orders across the platform</h3>
    <a href="orders.php" class="btn-xs">All orders →</a>
  </div>
  <?php if (!$recent): ?>
    <div class="empty">No orders yet.</div>
  <?php else: ?>
  <table class="dt">
    <thead><tr><th>Order</th><th>Restaurant</th><th>Table</th><th>Status</th><th class="r">Amount</th><th class="r">Placed</th></tr></thead>
    <tbody>
      <?php foreach ($recent as $o): ?>
      <tr>
        <td class="num" style="font-size:11px;color:var(--text-low);"><?php echo htmlspecialchars($o['order_key']); ?></td>
        <td><?php echo htmlspecialchars($o['restaurant_name']); ?></td>
        <td><?php echo htmlspecialchars($o['table_name'] ?? '—'); ?></td>
        <td><span class="pill <?php echo $o['status'] === 'completed' ? 'on' : 'plan'; ?>"><?php echo htmlspecialchars($o['status']); ?></span></td>
        <td class="r"><?php echo admin_money((float) $o['total_amount']); ?></td>
        <td class="r" style="font-size:11px;color:var(--text-low);"><?php echo date('j M, g:i A', strtotime($o['ordered_at'])); ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
