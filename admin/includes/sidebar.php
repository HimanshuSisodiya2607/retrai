<?php
/** Shared admin sidebar — set $current_page before including. */
$current_page = $current_page ?? '';
?>
<aside class="sidebar">
  <div class="logo"><img src="../assets/logo.png" alt="Dinetous" class="logo-img"><span class="logo-tag">ADMIN</span></div>
  <div class="nav-group">
    <div class="group-label">Platform</div>
    <a href="index.php" class="nav-item<?php echo admin_nav_active('dashboard', $current_page); ?>"><span class="ico">▦</span>Dashboard</a>
    <a href="restaurants.php" class="nav-item<?php echo admin_nav_active('restaurants', $current_page); ?>"><span class="ico">◍</span>Restaurants<span class="badge"><?php echo (int) ($nav_restro_count ?? 0); ?></span></a>
  </div>
  <div class="nav-group">
    <div class="group-label">Catalogue</div>
    <a href="menus.php" class="nav-item<?php echo admin_nav_active('menus', $current_page); ?>"><span class="ico">▣</span>All Menus<span class="badge"><?php echo (int) ($nav_item_count ?? 0); ?></span></a>
    <a href="orders.php" class="nav-item<?php echo admin_nav_active('orders', $current_page); ?>"><span class="ico">☰</span>All Orders</a>
  </div>
  <div class="nav-group">
    <div class="group-label">Fulfilment</div>
    <a href="ar-requests.php" class="nav-item<?php echo admin_nav_active('ar-requests', $current_page); ?>"><span class="ico">◐</span>AR Requests<?php if (!empty($nav_ar_open)): ?><span class="badge"><?php echo (int) $nav_ar_open; ?></span><?php endif; ?></a>
  </div>
  <div class="sidebar-foot">
    <div class="owner-card">
      <div class="owner-avatar admin-avatar"><?php echo htmlspecialchars(admin_initials($admin_name)); ?></div>
      <div>
        <div class="owner-name"><?php echo htmlspecialchars($admin_name); ?></div>
        <div class="owner-role">Super Admin</div>
      </div>
    </div>
    <a href="logout.php" class="admin-signout">Sign out</a>
  </div>
</aside>
