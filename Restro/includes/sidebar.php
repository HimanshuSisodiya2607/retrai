<?php
/**
 * Shared sidebar — set $current_page before including.
 * Required vars: $current_page, $nav_order_count, $nav_table_count,
 *                $owner_initials, $restaurant_name
 */
$current_page = $current_page ?? '';
?>
<?php if (!empty($_SESSION['impersonating'])): ?>
  <?php /* Floating rather than in-flow so it can't disturb the shell layout. */ ?>
  <div style="position:fixed;bottom:18px;left:50%;transform:translateX(-50%);z-index:2000;display:flex;align-items:center;gap:12px;padding:10px 16px;border-radius:40px;background:linear-gradient(135deg,#ff5a1f 0%,#ff1f4c 100%);color:#fff;font-family:'Inter',sans-serif;font-size:12.5px;box-shadow:0 8px 28px rgba(0,0,0,0.45);max-width:calc(100vw - 32px);">
    <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
      Super admin — viewing <strong><?php echo htmlspecialchars($_SESSION['impersonating_name'] ?? 'restaurant'); ?></strong>
    </span>
    <a href="../admin/impersonate.php?stop=1"
       style="color:#fff;text-decoration:none;border:1px solid rgba(255,255,255,0.55);padding:4px 11px;border-radius:20px;font-size:11.5px;white-space:nowrap;">
      Exit to admin
    </a>
  </div>
<?php endif; ?>
<aside class="sidebar">
  <div class="logo"><img src="../assets/logo.png" alt="Dinetous" class="logo-img"></div>
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
      <div class="owner-meta">
        <div class="owner-name"><?php echo htmlspecialchars($restaurant_name); ?></div>
        <div class="owner-role">Owner Dashboard</div>
      </div>
      <a href="logout.php" class="signout-btn" title="Sign out" aria-label="Sign out"
         onclick="return confirm('Sign out of <?php echo htmlspecialchars(addslashes($restaurant_name), ENT_QUOTES); ?>?');">⏻</a>
    </div>
    <a href="logout.php" class="signout-link"
       onclick="return confirm('Sign out of <?php echo htmlspecialchars(addslashes($restaurant_name), ENT_QUOTES); ?>?');">Sign out</a>
  </div>
</aside>
