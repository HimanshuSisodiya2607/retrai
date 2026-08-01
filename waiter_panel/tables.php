<?php
session_start();
require_once __DIR__ . '/../database/db.php';

if (empty($_SESSION['waiter_staff_key']) || empty($_SESSION['waiter_restro_key'])) {
    header('Location: sign-in.php');
    exit;
}

$restro_key = $_SESSION['waiter_restro_key'];
$waiter_name = $_SESSION['waiter_name'] ?? 'Waiter';
$restaurant_name = $_SESSION['waiter_restaurant_name'] ?? '';
session_write_close();

function menuUrl($table_key) {
    return '../Restro/customer-menu.php?table=' . urlencode($table_key);
}

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
    SELECT DISTINCT table_key FROM orders
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tables — Waiter</title>
<link rel="stylesheet" href="../Restro/assets/style.css">
</head>
<body>
<div id="bg-glow"></div>
<div class="shell" style="grid-template-columns:1fr;">
  <div class="main" style="margin-left:0;">
    <div class="topbar">
      <div>
        <h1>Tables</h1>
        <div class="sub"><?php echo htmlspecialchars($restaurant_name); ?> · <?php echo htmlspecialchars($waiter_name); ?></div>
      </div>
      <div class="topbar-right">
        <a href="logout.php" class="btn btn-ghost btn-sm">Sign out</a>
      </div>
    </div>
    <div class="content">
      <div class="panel-head"><h2>Tap a table to take order</h2><div class="link"><?php echo count($tables); ?> tables</div></div>
      <div class="table-grid">
        <?php foreach ($tables as $t):
          $busy = !empty($occupied[$t['table_key']]);
          $url = menuUrl($t['table_key']);
          $num_label = preg_replace('/^T-/', '', $t['table_name']);
        ?>
        <div class="table-card table-card-clickable<?php echo $busy ? ' occupied' : ''; ?>" onclick="location.href='<?php echo htmlspecialchars($url, ENT_QUOTES); ?>'" role="button" tabindex="0">
          <div class="table-top"><div class="table-num"><?php echo htmlspecialchars($num_label); ?></div></div>
          <div><h4><?php echo htmlspecialchars($t['table_name']); ?></h4><span class="seats"><?php echo (int) $t['seats']; ?> seats</span></div>
          <span class="table-status <?php echo $busy ? 'busy' : 'free'; ?>"><?php echo $busy ? 'Occupied' : 'Free'; ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
</body>
</html>
