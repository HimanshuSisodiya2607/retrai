<?php
/**
 * Shared admin page shell — set $current_page and $page_title first.
 * Emits everything up to the start of the main content area.
 */
$nav_restro_count = admin_scalar($conn, "SELECT COUNT(*) FROM restaurants");
$nav_item_count = admin_scalar($conn, "SELECT COUNT(*) FROM items");
$nav_ar_open = admin_scalar($conn, "SELECT COUNT(*) FROM ar_requests WHERE status IN ('requested','in_progress')");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/png" href="../assets/logo-icon.png">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($page_title ?? 'Admin'); ?> — Dinetous Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../Restro/assets/style.css?v=<?php echo @filemtime(dirname(__DIR__) . '/../Restro/assets/style.css'); ?>">
<link rel="stylesheet" href="assets/admin.css?v=<?php echo @filemtime(dirname(__DIR__) . '/assets/admin.css'); ?>">
</head>
<body>
<div id="bg-glow"></div>
<div class="shell">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="main">
    <div class="topbar">
      <div>
        <h1><?php echo htmlspecialchars($page_title ?? 'Admin'); ?></h1>
        <?php if (!empty($page_sub)): ?><div class="sub"><?php echo htmlspecialchars($page_sub); ?></div><?php endif; ?>
      </div>
    </div>
    <div class="content">
