<?php
require_once __DIR__ . '/includes/auth.php';

$flash = '';
$flash_type = 'ok';

// --- Platform actions -------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $key = trim($_POST['restro_key'] ?? '');
    $exists = $key !== '' && admin_scalar($conn, "SELECT COUNT(*) FROM restaurants WHERE restro_key = ?", [$key], 's');

    if (!$exists) {
        $flash = 'Restaurant not found.';
        $flash_type = 'err';
    } elseif (!empty($_POST['toggle_active'])) {
        $stmt = mysqli_prepare($conn, "UPDATE restaurants SET is_active = 1 - is_active WHERE restro_key = ?");
        mysqli_stmt_bind_param($stmt, 's', $key);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $now_active = (int) admin_scalar($conn, "SELECT is_active FROM restaurants WHERE restro_key = ?", [$key], 's');
        $flash = $now_active ? 'Restaurant reactivated — they can sign in again.' : 'Restaurant suspended — their sign-in is now blocked.';
    } elseif (isset($_POST['plan_name'])) {
        $plan = trim($_POST['plan_name']);
        $cycle = ($_POST['billing_cycle'] ?? 'monthly') === 'annual' ? 'annual' : 'monthly';
        if (!in_array($plan, ['Starter', 'Growth', 'Scale'], true)) {
            $flash = 'Unknown plan.';
            $flash_type = 'err';
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE restaurants SET plan_name = ?, billing_cycle = ? WHERE restro_key = ?");
            mysqli_stmt_bind_param($stmt, 'sss', $plan, $cycle, $key);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $flash = 'Plan updated to ' . $plan . ' (' . $cycle . ').';
        }
    }
}

$q = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? 'all';

$sql = "
    SELECT r.restro_key, r.restaurant_name, r.owner_name, r.email, r.phone, r.city, r.cuisine,
           r.plan_name, r.billing_cycle, r.is_active, r.created_at,
           (SELECT COUNT(*) FROM items i WHERE i.restro_key = r.restro_key) AS items,
           (SELECT COUNT(*) FROM restaurant_tables t WHERE t.restro_key = r.restro_key) AS tables_n,
           (SELECT COUNT(*) FROM staff s WHERE s.restro_key = r.restro_key) AS staff_n,
           (SELECT COUNT(*) FROM orders o WHERE o.restro_key = r.restro_key) AS orders_n,
           (SELECT COALESCE(SUM(o.total_amount),0) FROM orders o WHERE o.restro_key = r.restro_key AND o.status='completed') AS revenue
    FROM restaurants r
    WHERE 1=1
";
$params = [];
$types = '';
if ($q !== '') {
    $sql .= " AND (r.restaurant_name LIKE ? OR r.owner_name LIKE ? OR r.email LIKE ? OR r.city LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}
if ($status === 'active') {
    $sql .= " AND r.is_active = 1";
} elseif ($status === 'suspended') {
    $sql .= " AND r.is_active = 0";
}
$sql .= " ORDER BY r.created_at DESC";

$restaurants = admin_rows($conn, $sql, $params, $types);

$page_title = 'Restaurants';
$page_sub = count($restaurants) . ' of ' . (int) admin_scalar($conn, "SELECT COUNT(*) FROM restaurants") . ' shown';
$current_page = 'restaurants';
include __DIR__ . '/includes/header.php';
?>

<?php if ($flash !== ''): ?>
  <div class="flash <?php echo $flash_type; ?>"><?php echo htmlspecialchars($flash); ?></div>
<?php endif; ?>

<form method="get" action="restaurants.php" class="admin-toolbar">
  <input type="search" name="q" placeholder="Search name, owner, email, city…" value="<?php echo htmlspecialchars($q); ?>">
  <select name="status" onchange="this.form.submit()">
    <option value="all"<?php echo $status === 'all' ? ' selected' : ''; ?>>All statuses</option>
    <option value="active"<?php echo $status === 'active' ? ' selected' : ''; ?>>Active only</option>
    <option value="suspended"<?php echo $status === 'suspended' ? ' selected' : ''; ?>>Suspended only</option>
  </select>
  <button type="submit" class="btn-xs primary">Search</button>
  <?php if ($q !== '' || $status !== 'all'): ?><a href="restaurants.php" class="btn-xs">Clear</a><?php endif; ?>
</form>

<div class="panel">
  <?php if (!$restaurants): ?>
    <div class="empty">No restaurants match that filter.</div>
  <?php else: ?>
  <table class="dt">
    <thead>
      <tr>
        <th>Restaurant</th><th>Owner</th><th>Plan</th><th>Status</th>
        <th class="r">Menu</th><th class="r">Tables</th><th class="r">Staff</th>
        <th class="r">Orders</th><th class="r">Revenue</th><th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($restaurants as $r): ?>
      <tr>
        <td>
          <a class="link" href="restaurant.php?key=<?php echo urlencode($r['restro_key']); ?>"><?php echo htmlspecialchars($r['restaurant_name']); ?></a>
          <div style="font-size:11px;color:var(--text-low);">
            <?php echo htmlspecialchars(trim(($r['cuisine'] ?: '') . ($r['city'] ? ' · ' . $r['city'] : ''), ' ·')) ?: '—'; ?>
          </div>
        </td>
        <td>
          <?php echo htmlspecialchars($r['owner_name']); ?>
          <div style="font-size:11px;color:var(--text-low);"><?php echo htmlspecialchars($r['email']); ?></div>
        </td>
        <td>
          <form method="post" action="restaurants.php" class="inline-form">
            <input type="hidden" name="restro_key" value="<?php echo htmlspecialchars($r['restro_key']); ?>">
            <select name="plan_name" onchange="this.form.submit()" style="background:var(--bg);border:1px solid var(--line);border-radius:8px;color:var(--text-hi);padding:5px 8px;font-size:11px;font-family:inherit;">
              <?php foreach (['Starter', 'Growth', 'Scale'] as $p): ?>
                <option value="<?php echo $p; ?>"<?php echo $r['plan_name'] === $p ? ' selected' : ''; ?>><?php echo $p; ?></option>
              <?php endforeach; ?>
            </select>
            <input type="hidden" name="billing_cycle" value="<?php echo htmlspecialchars($r['billing_cycle'] ?: 'monthly'); ?>">
          </form>
        </td>
        <td><span class="pill <?php echo $r['is_active'] ? 'on' : 'off'; ?>"><?php echo $r['is_active'] ? 'Active' : 'Suspended'; ?></span></td>
        <td class="r"><?php echo (int) $r['items']; ?></td>
        <td class="r"><?php echo (int) $r['tables_n']; ?></td>
        <td class="r"><?php echo (int) $r['staff_n']; ?></td>
        <td class="r"><?php echo (int) $r['orders_n']; ?></td>
        <td class="r"><?php echo admin_money((float) $r['revenue']); ?></td>
        <td class="r" style="white-space:nowrap;">
          <a class="btn-xs primary" href="impersonate.php?key=<?php echo urlencode($r['restro_key']); ?>">Open as</a>
          <form method="post" action="restaurants.php" style="display:inline;"
                onsubmit="return confirm('<?php echo $r['is_active'] ? 'Suspend' : 'Reactivate'; ?> <?php echo htmlspecialchars(addslashes($r['restaurant_name'])); ?>?');">
            <input type="hidden" name="restro_key" value="<?php echo htmlspecialchars($r['restro_key']); ?>">
            <input type="hidden" name="toggle_active" value="1">
            <button type="submit" class="btn-xs<?php echo $r['is_active'] ? ' danger' : ''; ?>"><?php echo $r['is_active'] ? 'Suspend' : 'Activate'; ?></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
