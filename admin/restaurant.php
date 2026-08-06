<?php
require_once __DIR__ . '/includes/auth.php';

$key = trim($_GET['key'] ?? '');
$rows = $key === '' ? [] : admin_rows($conn, "SELECT * FROM restaurants WHERE restro_key = ?", [$key], 's');
$r = $rows[0] ?? null;

if (!$r) {
    header('Location: restaurants.php');
    exit;
}

$stats = [
    'items'   => (int) admin_scalar($conn, "SELECT COUNT(*) FROM items WHERE restro_key = ?", [$key], 's'),
    'cats'    => (int) admin_scalar($conn, "SELECT COUNT(*) FROM categories WHERE restro_key = ?", [$key], 's'),
    'tables'  => (int) admin_scalar($conn, "SELECT COUNT(*) FROM restaurant_tables WHERE restro_key = ?", [$key], 's'),
    'staff'   => (int) admin_scalar($conn, "SELECT COUNT(*) FROM staff WHERE restro_key = ?", [$key], 's'),
    'orders'  => (int) admin_scalar($conn, "SELECT COUNT(*) FROM orders WHERE restro_key = ?", [$key], 's'),
    'open'    => (int) admin_scalar($conn, "SELECT COUNT(*) FROM orders WHERE restro_key = ? AND status != 'completed'", [$key], 's'),
    'revenue' => (float) admin_scalar($conn, "SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE restro_key = ? AND status = 'completed'", [$key], 's'),
];

$menu = admin_rows($conn, "
    SELECT i.item_key, i.name, i.emoji, i.price, i.is_active, i.glb_url, c.name AS category
    FROM items i
    LEFT JOIN categories c ON c.category_key = i.category_key
    WHERE i.restro_key = ?
    ORDER BY c.sort_order, i.sort_order, i.name
", [$key], 's');

$tables = admin_rows($conn, "SELECT table_key, table_name, seats FROM restaurant_tables WHERE restro_key = ? ORDER BY table_name", [$key], 's');
$staff = admin_rows($conn, "SELECT name, role_title, department, status FROM staff WHERE restro_key = ? ORDER BY name", [$key], 's');
$orders = admin_rows($conn, "
    SELECT o.order_key, o.status, o.total_amount, o.ordered_at, t.table_name
    FROM orders o
    LEFT JOIN restaurant_tables t ON t.table_key = o.table_key
    WHERE o.restro_key = ?
    ORDER BY o.ordered_at DESC
    LIMIT 15
", [$key], 's');

$has_bills = (int) admin_scalar($conn, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bills'");
$bills = $has_bills ? admin_rows($conn, "
    SELECT table_name, subtotal, discount_type, discount_amount, total, settled_at
    FROM bills WHERE restro_key = ? ORDER BY settled_at DESC LIMIT 10
", [$key], 's') : [];

$page_title = $r['restaurant_name'];
$page_sub = trim(($r['cuisine'] ?: '') . ($r['city'] ? ' · ' . $r['city'] : ''), ' ·') ?: 'Restaurant detail';
$current_page = 'restaurants';
include __DIR__ . '/includes/header.php';
?>

<div class="admin-toolbar">
  <a href="restaurants.php" class="btn-xs">← All restaurants</a>
  <a href="impersonate.php?key=<?php echo urlencode($key); ?>" class="btn-xs primary">Open as this restaurant →</a>
  <a href="menus.php?restro=<?php echo urlencode($key); ?>" class="btn-xs">Edit menu</a>
  <span class="pill <?php echo $r['is_active'] ? 'on' : 'off'; ?>"><?php echo $r['is_active'] ? 'Active' : 'Suspended'; ?></span>
  <span class="pill plan"><?php echo htmlspecialchars($r['plan_name'] ?: '—'); ?> · <?php echo htmlspecialchars($r['billing_cycle'] ?: 'monthly'); ?></span>
</div>

<div class="stat-row">
  <div class="stat-tile accent"><div class="k">Revenue</div><div class="v"><?php echo admin_money($stats['revenue']); ?></div><div class="s">completed orders</div></div>
  <div class="stat-tile"><div class="k">Orders</div><div class="v"><?php echo $stats['orders']; ?></div><div class="s"><?php echo $stats['open']; ?> still open</div></div>
  <div class="stat-tile"><div class="k">Menu</div><div class="v"><?php echo $stats['items']; ?></div><div class="s"><?php echo $stats['cats']; ?> categories</div></div>
  <div class="stat-tile"><div class="k">Tables</div><div class="v"><?php echo $stats['tables']; ?></div><div class="s">QR ordering points</div></div>
  <div class="stat-tile"><div class="k">Staff</div><div class="v"><?php echo $stats['staff']; ?></div><div class="s">members</div></div>
</div>

<div class="panel">
  <div class="panel-head"><h3>Account</h3></div>
  <table class="dt">
    <tbody>
      <tr><th style="width:180px;">Owner</th><td><?php echo htmlspecialchars($r['owner_name']); ?></td></tr>
      <tr><th>Email</th><td><?php echo htmlspecialchars($r['email']); ?></td></tr>
      <tr><th>Phone</th><td><?php echo htmlspecialchars($r['phone'] ?: '—'); ?></td></tr>
      <tr><th>Address</th><td><?php echo htmlspecialchars($r['address'] ?: '—'); ?></td></tr>
      <tr><th>Hours</th><td><?php echo htmlspecialchars(($r['opening_time'] ?: '—') . ' – ' . ($r['closing_time'] ?: '—')); ?></td></tr>
      <tr><th>Restro key</th><td class="num" style="font-size:11px;color:var(--text-low);"><?php echo htmlspecialchars($r['restro_key']); ?></td></tr>
      <tr><th>Joined</th><td><?php echo date('j M Y', strtotime($r['created_at'])); ?></td></tr>
    </tbody>
  </table>
</div>

<div class="panel">
  <div class="panel-head">
    <h3>Menu — <?php echo count($menu); ?> items</h3>
    <a href="menus.php?restro=<?php echo urlencode($key); ?>" class="btn-xs">Edit prices &amp; availability →</a>
  </div>
  <?php if (!$menu): ?>
    <div class="empty">This restaurant hasn't added any menu items yet.</div>
  <?php else: ?>
  <table class="dt">
    <thead><tr><th>Item</th><th>Category</th><th class="r">Price</th><th>AR model</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($menu as $m): ?>
      <tr>
        <td><?php echo htmlspecialchars(($m['emoji'] ? $m['emoji'] . ' ' : '') . $m['name']); ?></td>
        <td style="color:var(--text-mid);"><?php echo htmlspecialchars($m['category'] ?: '—'); ?></td>
        <td class="r"><?php echo admin_money((float) $m['price']); ?></td>
        <td><?php echo !empty($m['glb_url']) ? '<span class="pill on">3D</span>' : '<span style="color:var(--text-low);font-size:11px;">—</span>'; ?></td>
        <td><span class="pill <?php echo $m['is_active'] ? 'on' : 'off'; ?>"><?php echo $m['is_active'] ? 'Live' : 'Hidden'; ?></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="panel">
  <div class="panel-head"><h3>Recent orders</h3></div>
  <?php if (!$orders): ?>
    <div class="empty">No orders yet.</div>
  <?php else: ?>
  <table class="dt">
    <thead><tr><th>Order</th><th>Table</th><th>Status</th><th class="r">Amount</th><th class="r">Placed</th></tr></thead>
    <tbody>
      <?php foreach ($orders as $o): ?>
      <tr>
        <td class="num" style="font-size:11px;color:var(--text-low);"><?php echo htmlspecialchars($o['order_key']); ?></td>
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

<?php if ($bills): ?>
<div class="panel">
  <div class="panel-head"><h3>Settled bills</h3><span class="sub">most recent 10</span></div>
  <table class="dt">
    <thead><tr><th>Table</th><th class="r">Subtotal</th><th class="r">Discount</th><th class="r">Total</th><th class="r">Settled</th></tr></thead>
    <tbody>
      <?php foreach ($bills as $b): ?>
      <tr>
        <td><?php echo htmlspecialchars($b['table_name']); ?></td>
        <td class="r"><?php echo admin_money((float) $b['subtotal']); ?></td>
        <td class="r"><?php echo (float) $b['discount_amount'] > 0 ? '−' . admin_money((float) $b['discount_amount']) : '—'; ?></td>
        <td class="r"><?php echo admin_money((float) $b['total']); ?></td>
        <td class="r" style="font-size:11px;color:var(--text-low);"><?php echo date('j M, g:i A', strtotime($b['settled_at'])); ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="panel">
  <div class="panel-head"><h3>Tables &amp; staff</h3></div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:22px;">
    <div>
      <div class="k" style="font-family:var(--font-mono);font-size:10px;color:var(--text-low);text-transform:uppercase;margin-bottom:10px;">Tables</div>
      <?php if (!$tables): ?><div class="empty">No tables.</div><?php else: ?>
      <table class="dt" style="min-width:0;">
        <thead><tr><th>Name</th><th class="r">Seats</th></tr></thead>
        <tbody>
          <?php foreach ($tables as $t): ?>
          <tr><td><?php echo htmlspecialchars($t['table_name']); ?></td><td class="r"><?php echo (int) $t['seats']; ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
    <div>
      <div class="k" style="font-family:var(--font-mono);font-size:10px;color:var(--text-low);text-transform:uppercase;margin-bottom:10px;">Staff</div>
      <?php if (!$staff): ?><div class="empty">No staff.</div><?php else: ?>
      <table class="dt" style="min-width:0;">
        <thead><tr><th>Name</th><th>Role</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($staff as $s): ?>
          <tr>
            <td><?php echo htmlspecialchars($s['name']); ?></td>
            <td style="color:var(--text-mid);font-size:12px;"><?php echo htmlspecialchars($s['role_title']); ?></td>
            <td><span class="pill <?php echo $s['status'] === 'active' ? 'on' : 'off'; ?>"><?php echo htmlspecialchars($s['status']); ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
