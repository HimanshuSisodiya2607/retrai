<?php
require_once __DIR__ . '/includes/auth.php';

$q = trim($_GET['q'] ?? '');
$restro = trim($_GET['restro'] ?? '');
$status = trim($_GET['status'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 40;
$offset = ($page - 1) * $per_page;

$where = " WHERE 1=1";
$params = [];
$types = '';
if ($q !== '') {
    $where .= " AND (o.order_key LIKE ? OR r.restaurant_name LIKE ? OR o.items_summary LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
    $types .= 'sss';
}
if ($restro !== '') {
    $where .= " AND o.restro_key = ?";
    $params[] = $restro;
    $types .= 's';
}
if (in_array($status, ['new', 'kitchen', 'ready', 'served', 'completed'], true)) {
    $where .= " AND o.status = ?";
    $params[] = $status;
    $types .= 's';
}

$base = " FROM orders o JOIN restaurants r ON r.restro_key = o.restro_key
          LEFT JOIN restaurant_tables t ON t.table_key = o.table_key" . $where;

$total = (int) admin_scalar($conn, "SELECT COUNT(*)" . $base, $params, $types);
$sum = (float) admin_scalar($conn, "SELECT COALESCE(SUM(o.total_amount),0)" . $base, $params, $types);

$rows_sql = "SELECT o.order_key, o.status, o.total_amount, o.ordered_at, o.items_summary,
                    r.restaurant_name, r.restro_key, t.table_name" . $base . "
             ORDER BY o.ordered_at DESC LIMIT $per_page OFFSET $offset";
$orders = admin_rows($conn, $rows_sql, $params, $types);
$all_restros = admin_rows($conn, "SELECT restro_key, restaurant_name FROM restaurants ORDER BY restaurant_name");
$pages = max(1, (int) ceil($total / $per_page));

$page_title = 'All Orders';
$page_sub = number_format($total) . ' orders · ' . admin_money($sum) . ' total value';
$current_page = 'orders';
include __DIR__ . '/includes/header.php';

$qs = function (array $extra) use ($q, $restro, $status) {
    return http_build_query(array_merge(['q' => $q, 'restro' => $restro, 'status' => $status], $extra));
};
?>

<form method="get" action="orders.php" class="admin-toolbar">
  <input type="search" name="q" placeholder="Search order key, restaurant, items…" value="<?php echo htmlspecialchars($q); ?>">
  <select name="restro" onchange="this.form.submit()">
    <option value="">All restaurants</option>
    <?php foreach ($all_restros as $ar): ?>
      <option value="<?php echo htmlspecialchars($ar['restro_key']); ?>"<?php echo $restro === $ar['restro_key'] ? ' selected' : ''; ?>>
        <?php echo htmlspecialchars($ar['restaurant_name']); ?>
      </option>
    <?php endforeach; ?>
  </select>
  <select name="status" onchange="this.form.submit()">
    <option value="">Any status</option>
    <?php foreach (['new', 'kitchen', 'ready', 'served', 'completed'] as $s): ?>
      <option value="<?php echo $s; ?>"<?php echo $status === $s ? ' selected' : ''; ?>><?php echo ucfirst($s); ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn-xs primary">Search</button>
  <?php if ($q !== '' || $restro !== '' || $status !== ''): ?><a href="orders.php" class="btn-xs">Clear</a><?php endif; ?>
</form>

<div class="panel">
  <?php if (!$orders): ?>
    <div class="empty">No orders match that filter.</div>
  <?php else: ?>
  <table class="dt">
    <thead><tr><th>Order</th><th>Restaurant</th><th>Table</th><th>Items</th><th>Status</th><th class="r">Amount</th><th class="r">Placed</th></tr></thead>
    <tbody>
      <?php foreach ($orders as $o): ?>
      <tr>
        <td class="num" style="font-size:11px;color:var(--text-low);"><?php echo htmlspecialchars($o['order_key']); ?></td>
        <td><a class="link" href="restaurant.php?key=<?php echo urlencode($o['restro_key']); ?>" style="font-size:12px;"><?php echo htmlspecialchars($o['restaurant_name']); ?></a></td>
        <td><?php echo htmlspecialchars($o['table_name'] ?? '—'); ?></td>
        <td style="color:var(--text-mid);font-size:12px;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
          <?php echo htmlspecialchars($o['items_summary'] ?: '—'); ?>
        </td>
        <td><span class="pill <?php echo $o['status'] === 'completed' ? 'on' : 'plan'; ?>"><?php echo htmlspecialchars($o['status']); ?></span></td>
        <td class="r"><?php echo admin_money((float) $o['total_amount']); ?></td>
        <td class="r" style="font-size:11px;color:var(--text-low);"><?php echo date('j M Y, g:i A', strtotime($o['ordered_at'])); ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($pages > 1): ?>
  <div style="display:flex;gap:8px;align-items:center;justify-content:center;margin-top:18px;">
    <?php if ($page > 1): ?><a class="btn-xs" href="orders.php?<?php echo $qs(['page' => $page - 1]); ?>">← Prev</a><?php endif; ?>
    <span style="font-family:var(--font-mono);font-size:11px;color:var(--text-low);">Page <?php echo $page; ?> of <?php echo $pages; ?></span>
    <?php if ($page < $pages): ?><a class="btn-xs" href="orders.php?<?php echo $qs(['page' => $page + 1]); ?>">Next →</a><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
