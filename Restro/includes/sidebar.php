<?php
/**
 * Shared sidebar — set $current_page before including.
 * Required vars: $current_page, $nav_order_count, $nav_table_count,
 *                $owner_initials, $restaurant_name
 */
$current_page = $current_page ?? '';
?>
<aside class="sidebar">
  <div class="logo"><span class="logo-dot"></span>RestroAI</div>
  <div class="nav-group">
    <div class="group-label">Operate</div>
    <a href="overview.php" class="nav-item<?php echo restro_nav_active('overview', $current_page); ?>"><span class="ico">▦</span>Overview</a>
    <a href="orders.php" class="nav-item<?php echo restro_nav_active('orders', $current_page); ?>"><span class="ico">☰</span>Live Orders<span class="badge"><?php echo (int) $nav_order_count; ?></span></a>
    <a href="tables.php" class="nav-item<?php echo restro_nav_active('tables', $current_page); ?>"><span class="ico">▥</span>Tables<span class="badge"><?php echo (int) $nav_table_count; ?></span></a>
    <a href="menu.php" class="nav-item<?php echo restro_nav_active('menu', $current_page); ?>"><span class="ico">▣</span>Menu</a>
    <a href="categories.php" class="nav-item<?php echo restro_nav_active('categories', $current_page); ?>"><span class="ico">▨</span>Categories</a>
  </div>
  <div class="nav-group">
    <div class="group-label">Grow</div>
    <a href="ai-insights.php" class="nav-item<?php echo restro_nav_active('ai-insights', $current_page); ?>"><span class="ico">✦</span>AI Insights</a>
    <a href="marketing.php" class="nav-item<?php echo restro_nav_active('marketing', $current_page); ?>"><span class="ico">↗</span>Marketing</a>
    <a href="ar-studio.php" class="nav-item<?php echo restro_nav_active('ar-studio', $current_page); ?>"><span class="ico">◐</span>AR Menu Studio</a>
    <a href="analytics.php" class="nav-item<?php echo restro_nav_active('analytics', $current_page); ?>"><span class="ico">▤</span>Analytics</a>
  </div>
  <div class="nav-group">
    <div class="group-label">Manage</div>
    <a href="settings.php" class="nav-item<?php echo restro_nav_active('settings', $current_page); ?>"><span class="ico">⚙</span>Settings</a>
    <a href="staff.php" class="nav-item<?php echo restro_nav_active('staff', $current_page); ?>"><span class="ico">◎</span>Staff &amp; Roles</a>
  </div>
  <div class="sidebar-foot">
    <div class="owner-card">
      <div class="owner-avatar"><?php echo htmlspecialchars($owner_initials); ?></div>
      <div><div class="owner-name"><?php echo htmlspecialchars($restaurant_name); ?></div><div class="owner-role">Owner Dashboard</div></div>
    </div>
  </div>
</aside>
