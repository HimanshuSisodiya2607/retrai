<?php
require_once __DIR__ . '/includes/auth.php';

$flash = '';
$flash_type = 'ok';

// --- Edits ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_key = trim($_POST['item_key'] ?? '');
    $item = $item_key === ''
        ? null
        : (admin_rows($conn, "SELECT item_key, name, is_active FROM items WHERE item_key = ?", [$item_key], 's')[0] ?? null);

    if (!$item) {
        $flash = 'Menu item not found.';
        $flash_type = 'err';
    } elseif (!empty($_POST['toggle_item'])) {
        $stmt = mysqli_prepare($conn, "UPDATE items SET is_active = 1 - is_active, updated_at = NOW() WHERE item_key = ?");
        mysqli_stmt_bind_param($stmt, 's', $item_key);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $flash = $item['name'] . ($item['is_active'] ? ' hidden from the customer menu.' : ' is now live on the customer menu.');
    } elseif (isset($_POST['price'])) {
        $price = (float) $_POST['price'];
        if ($price < 0) {
            $flash = 'Price cannot be negative.';
            $flash_type = 'err';
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE items SET price = ?, updated_at = NOW() WHERE item_key = ?");
            mysqli_stmt_bind_param($stmt, 'ds', $price, $item_key);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $flash = $item['name'] . ' repriced to ₹' . number_format($price, 2) . '.';
        }
    } elseif (!empty($_POST['delete_item'])) {
        // order_items.item_key is ON DELETE SET NULL, so past orders keep
        // their item_name snapshot and history stays intact.
        $stmt = mysqli_prepare($conn, "DELETE FROM items WHERE item_key = ?");
        mysqli_stmt_bind_param($stmt, 's', $item_key);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $flash = $item['name'] . ' deleted. Past orders keep their record of it.';
    }
}

// --- Filters ----------------------------------------------------------
$q = trim($_GET['q'] ?? '');
$restro = trim($_GET['restro'] ?? '');
$avail = $_GET['avail'] ?? 'all';

$sql = "
    SELECT i.item_key, i.name, i.emoji, i.price, i.is_active, i.glb_url, i.description,
           c.name AS category, r.restaurant_name, r.restro_key
    FROM items i
    JOIN restaurants r ON r.restro_key = i.restro_key
    LEFT JOIN categories c ON c.category_key = i.category_key
    WHERE 1=1
";
$params = [];
$types = '';
if ($q !== '') {
    $sql .= " AND (i.name LIKE ? OR c.name LIKE ? OR r.restaurant_name LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
    $types .= 'sss';
}
if ($restro !== '') {
    $sql .= " AND i.restro_key = ?";
    $params[] = $restro;
    $types .= 's';
}
if ($avail === 'live') {
    $sql .= " AND i.is_active = 1";
} elseif ($avail === 'hidden') {
    $sql .= " AND i.is_active = 0";
}
$sql .= " ORDER BY r.restaurant_name, c.sort_order, i.name";

$items = admin_rows($conn, $sql, $params, $types);
$all_restros = admin_rows($conn, "SELECT restro_key, restaurant_name FROM restaurants ORDER BY restaurant_name");

$page_title = 'All Menus';
$page_sub = count($items) . ' items across ' . count($all_restros) . ' restaurants';
$current_page = 'menus';
include __DIR__ . '/includes/header.php';
?>

<?php if ($flash !== ''): ?>
  <div class="flash <?php echo $flash_type; ?>"><?php echo htmlspecialchars($flash); ?></div>
<?php endif; ?>

<form method="get" action="menus.php" class="admin-toolbar">
  <input type="search" name="q" placeholder="Search dish, category or restaurant…" value="<?php echo htmlspecialchars($q); ?>">
  <select name="restro" onchange="this.form.submit()">
    <option value="">All restaurants</option>
    <?php foreach ($all_restros as $ar): ?>
      <option value="<?php echo htmlspecialchars($ar['restro_key']); ?>"<?php echo $restro === $ar['restro_key'] ? ' selected' : ''; ?>>
        <?php echo htmlspecialchars($ar['restaurant_name']); ?>
      </option>
    <?php endforeach; ?>
  </select>
  <select name="avail" onchange="this.form.submit()">
    <option value="all"<?php echo $avail === 'all' ? ' selected' : ''; ?>>All items</option>
    <option value="live"<?php echo $avail === 'live' ? ' selected' : ''; ?>>Live only</option>
    <option value="hidden"<?php echo $avail === 'hidden' ? ' selected' : ''; ?>>Hidden only</option>
  </select>
  <button type="submit" class="btn-xs primary">Search</button>
  <?php if ($q !== '' || $restro !== '' || $avail !== 'all'): ?><a href="menus.php" class="btn-xs">Clear</a><?php endif; ?>
</form>

<div class="panel">
  <?php if (!$items): ?>
    <div class="empty">No menu items match that filter.</div>
  <?php else: ?>
  <table class="dt">
    <thead>
      <tr><th>Item</th><th>Restaurant</th><th>Category</th><th>Price</th><th>AR</th><th>Status</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($items as $it): ?>
      <tr>
        <td>
          <?php echo htmlspecialchars(($it['emoji'] ? $it['emoji'] . ' ' : '') . $it['name']); ?>
          <?php if (!empty($it['description'])): ?>
            <div style="font-size:11px;color:var(--text-low);max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
              <?php echo htmlspecialchars($it['description']); ?>
            </div>
          <?php endif; ?>
        </td>
        <td>
          <a class="link" href="restaurant.php?key=<?php echo urlencode($it['restro_key']); ?>" style="font-size:12px;">
            <?php echo htmlspecialchars($it['restaurant_name']); ?>
          </a>
        </td>
        <td style="color:var(--text-mid);font-size:12px;"><?php echo htmlspecialchars($it['category'] ?: '—'); ?></td>
        <td>
          <form method="post" action="menus.php" class="inline-form">
            <input type="hidden" name="item_key" value="<?php echo htmlspecialchars($it['item_key']); ?>">
            <input type="number" name="price" step="0.01" min="0" value="<?php echo htmlspecialchars(number_format((float) $it['price'], 2, '.', '')); ?>">
            <button type="submit" class="btn-xs">Save</button>
          </form>
        </td>
        <td><?php echo !empty($it['glb_url']) ? '<span class="pill on">3D</span>' : '<span style="color:var(--text-low);font-size:11px;">—</span>'; ?></td>
        <td><span class="pill <?php echo $it['is_active'] ? 'on' : 'off'; ?>"><?php echo $it['is_active'] ? 'Live' : 'Hidden'; ?></span></td>
        <td class="r" style="white-space:nowrap;">
          <form method="post" action="menus.php" style="display:inline;">
            <input type="hidden" name="item_key" value="<?php echo htmlspecialchars($it['item_key']); ?>">
            <input type="hidden" name="toggle_item" value="1">
            <button type="submit" class="btn-xs"><?php echo $it['is_active'] ? 'Hide' : 'Show'; ?></button>
          </form>
          <form method="post" action="menus.php" style="display:inline;"
                onsubmit="return confirm('Delete <?php echo htmlspecialchars(addslashes($it['name'])); ?> from <?php echo htmlspecialchars(addslashes($it['restaurant_name'])); ?>? This cannot be undone.');">
            <input type="hidden" name="item_key" value="<?php echo htmlspecialchars($it['item_key']); ?>">
            <input type="hidden" name="delete_item" value="1">
            <button type="submit" class="btn-xs danger">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
