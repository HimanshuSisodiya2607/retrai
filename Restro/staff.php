<?php
session_start();
require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/includes/helpers.php';

if (empty($_SESSION['restro_key'])) {
    header('Location: sign-in.php');
    exit;
}

$restro_key = $_SESSION['restro_key'];
session_write_close();

extract(restro_load_nav($conn, $restro_key));
$current_page = 'staff';

$error = '';
$show_modal = false;

$departments = ['Kitchen', 'Floor', 'Front desk', 'Bar', 'Management'];

function initials($name) {
    $parts = preg_split('/\s+/', trim($name));
    if (count($parts) >= 2) {
        return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
    }
    return strtoupper(mb_substr($name, 0, 2));
}

function status_label($status) {
    return $status === 'active' ? 'Active' : 'Off shift';
}

function format_join_date($datetime) {
    if (empty($datetime)) return '—';
    return date('j M Y', strtotime($datetime));
}

/**
 * Prepares a statement and stops with a clear message if it fails,
 * instead of silently returning false and letting everything downstream
 * quietly do nothing.
 */
function prepare_or_die($conn, $sql) {
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt === false) {
        http_response_code(500);
        die('<pre style="color:#ff6b6b;background:#111;padding:16px;font-family:monospace;">'
            . "SQL prepare failed.\n\nQuery: " . htmlspecialchars($sql)
            . "\nMySQL error: " . htmlspecialchars(mysqli_error($conn))
            . '</pre>');
    }
    return $stmt;
}

// Delete staff
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['delete_staff'])) {
    $staff_key = $_POST['delete_staff'];
    $del = prepare_or_die($conn, "DELETE FROM staff WHERE staff_key = ? AND restro_key = ?");
    mysqli_stmt_bind_param($del, 'ss', $staff_key, $restro_key);
    mysqli_stmt_execute($del);
    mysqli_stmt_close($del);
    header('Location: staff.php');
    exit;
}

// Add or edit staff
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['save_staff'])) {
    $staff_key = trim($_POST['staff_key'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $role_title = trim($_POST['role_title'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password_raw = trim($_POST['password'] ?? '');
    $status = ($_POST['status'] ?? 'active') === 'off' ? 'off' : 'active';

    $is_new = ($staff_key === '');

    if ($name === '' || $role_title === '' || $department === '' || $email === '' || $password_raw === '') {
        $error = 'Please fill in name, email, password, role, and department.';
        $show_modal = true;
    } else {
        if ($is_new) {
            $staff_key = 'stf_' . bin2hex(random_bytes(6));

            // 8 columns, 8 placeholders, 8 bound values — all matching, in order.
            $ins = prepare_or_die($conn, "
                INSERT INTO staff (staff_key, restro_key, name, email, password, role_title, department, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            mysqli_stmt_bind_param(
                $ins, 'ssssssss',
                $staff_key, $restro_key, $name, $email, $password_raw, $role_title, $department, $status
            );
            mysqli_stmt_execute($ins);
            mysqli_stmt_close($ins);
            header('Location: staff.php');
            exit;
        }

        $upd = prepare_or_die($conn, "
            UPDATE staff SET name = ?, email = ?, password = ?, role_title = ?, department = ?, status = ?
            WHERE staff_key = ? AND restro_key = ?
        ");
        mysqli_stmt_bind_param(
            $upd, 'ssssssss',
            $name, $email, $password_raw, $role_title, $department, $status, $staff_key, $restro_key
        );
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);
        header('Location: staff.php');
        exit;
    }
}

$edit_key = trim($_GET['edit'] ?? '');
$edit_staff = null;

if (!$show_modal) {
    $show_modal = isset($_GET['add']);
}

if ($edit_key !== '') {
    $stmt = prepare_or_die($conn, "
        SELECT staff_key, name, email, password, role_title, department, status, created_at
        FROM staff WHERE staff_key = ? AND restro_key = ?
    ");
    mysqli_stmt_bind_param($stmt, 'ss', $edit_key, $restro_key);
    mysqli_stmt_execute($stmt);
    $edit_staff = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if ($edit_staff) $show_modal = true;
}

// Restaurant info
$stmt = prepare_or_die($conn, "SELECT restaurant_name FROM restaurants WHERE restro_key = ?");
mysqli_stmt_bind_param($stmt, 's', $restro_key);
mysqli_stmt_execute($stmt);
$restaurant = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

// Nav badges
$stmt = prepare_or_die($conn, "SELECT COUNT(*) AS c FROM orders WHERE restro_key = ? AND status != 'completed'");
mysqli_stmt_bind_param($stmt, 's', $restro_key);
mysqli_stmt_execute($stmt);
$nav_order_count = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];
mysqli_stmt_close($stmt);

$stmt = prepare_or_die($conn, "SELECT COUNT(*) AS c FROM restaurant_tables WHERE restro_key = ?");
mysqli_stmt_bind_param($stmt, 's', $restro_key);
mysqli_stmt_execute($stmt);
$nav_table_count = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];
mysqli_stmt_close($stmt);

// Staff list
$stmt = prepare_or_die($conn, "
    SELECT staff_key, name, email, role_title, department, status, created_at
    FROM staff
    WHERE restro_key = ?
    ORDER BY department, name
");
mysqli_stmt_bind_param($stmt, 's', $restro_key);
mysqli_stmt_execute($stmt);
$staff_list = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$owner_initials = initials($restaurant['restaurant_name'] ?? 'RA');
$form_staff = $edit_staff ?: ($_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Staff &amp; Roles — RestroAI</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<style>
  /* Updated for the new Email column — 7 tracks now instead of the old 5/6.
     Move this into style.css and remove this block once merged in. */
  .staff-row{grid-template-columns:44px 1.4fr 1.6fr 1fr 100px 90px 150px !important;}
  .staff-row .staff-actions{display:flex;gap:8px;flex-wrap:wrap;}
  .password-field-wrap{position:relative;}
  .password-field-wrap input{width:100%;padding-right:42px;}
  .password-eye-btn{
    position:absolute;right:6px;top:50%;transform:translateY(-50%);
    width:30px;height:30px;border:none;background:transparent;color:var(--text-mid);
    font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;
    border-radius:8px;
  }
  .password-eye-btn:hover{background:rgba(255,255,255,0.06);color:var(--text-hi);}
  @media(max-width:900px){
    .staff-row{grid-template-columns:36px 1fr !important;row-gap:4px;}
    .staff-row.head{display:none;}
    .staff-row > div:nth-child(3),
    .staff-row > div:nth-child(4),
    .staff-row > div:nth-child(5){grid-column:2;font-size:12px;color:var(--text-mid);}
  }
</style>
</head>
<body>

<div id="bg-glow"></div>

<div class="shell">
  <aside class="sidebar">
    <div class="logo"><span class="logo-dot"></span>RestroAI</div>
    <div class="nav-group">
      <div class="group-label">Operate</div>
      <a href="overview.php" class="nav-item"><span class="ico">▦</span>Overview</a>
      <a href="orders.php" class="nav-item"><span class="ico">☰</span>Live Orders<span class="badge"><?php echo $nav_order_count; ?></span></a>
      <a href="tables.php" class="nav-item"><span class="ico">▥</span>Tables<span class="badge"><?php echo $nav_table_count; ?></span></a>
      <a href="menu.php" class="nav-item"><span class="ico">▣</span>Menu</a>
      <a href="categories.php" class="nav-item"><span class="ico">▨</span>Categories</a>
    </div>
    <div class="nav-group">
      <div class="group-label">Grow</div>
      <a href="ai-insights.php" class="nav-item"><span class="ico">✦</span>AI Insights</a>
      <a href="marketing.html" class="nav-item"><span class="ico">↗</span>Marketing</a>
      <a href="ar-studio.html" class="nav-item"><span class="ico">◐</span>AR Menu Studio</a>
      <a href="analytics.php" class="nav-item"><span class="ico">▤</span>Analytics</a>
    </div>
    <div class="nav-group">
      <div class="group-label">Manage</div>
      <a href="settings.php" class="nav-item"><span class="ico">⚙</span>Settings</a>
      <a href="staff.php" class="nav-item active"><span class="ico">◎</span>Staff &amp; Roles</a>
    </div>
    <div class="sidebar-foot">
      <div class="owner-card">
        <div class="owner-avatar"><?php echo htmlspecialchars($owner_initials); ?></div>
        <div><div class="owner-name"><?php echo htmlspecialchars($restaurant['restaurant_name'] ?? ''); ?></div><div class="owner-role">Owner Dashboard</div></div>
      </div>
    </div>
  </aside>

  <div class="main">
    <div class="topbar">
      <div><h1>Staff &amp; Roles</h1><div class="sub">Manage your team and shift status</div></div>
      <div class="topbar-right">
        <div class="search-box">🔍 Search orders, dishes…</div>
        <div class="icon-btn">🔔<span class="dot"></span></div>
        <div class="restaurant-pill"><span class="dot"></span>Open — Dine-in</div>
      </div>
    </div>

    <div class="content">

      <?php if ($error !== ''): ?>
        <p style="color:#ff6b6b;font-size:13px;margin-bottom:16px;"><?php echo htmlspecialchars($error); ?></p>
      <?php endif; ?>

      <div class="panel">
        <div class="panel-inner-head">
          <div class="title">Team</div>
          <a href="staff.php?add=1" class="btn btn-primary btn-sm">+ Add Staff</a>
        </div>
        <div class="staff-row head">
          <div></div><div>Name</div><div>Email</div><div>Department</div><div>Joined</div><div>Status</div><div class="staff-actions-head">Actions</div>
        </div>
        <?php if (count($staff_list) === 0): ?>
          <div class="empty-note">No staff yet — add your first team member.</div>
        <?php else: ?>
          <?php foreach ($staff_list as $member): ?>
            <div class="staff-row">
              <div class="staff-avatar"><?php echo htmlspecialchars(initials($member['name'])); ?></div>
              <div>
                <div class="staff-name"><?php echo htmlspecialchars($member['name']); ?></div>
                <div class="staff-role"><?php echo htmlspecialchars($member['role_title']); ?></div>
              </div>
              <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($member['email'] ?? ''); ?></div>
              <div><?php echo htmlspecialchars($member['department']); ?></div>
              <div class="staff-joined"><?php echo htmlspecialchars(format_join_date($member['created_at'])); ?></div>
              <div class="staff-status">
                <span class="status-pill <?php echo $member['status'] === 'active' ? 'status-active' : 'status-off'; ?>">
                  <?php echo htmlspecialchars(status_label($member['status'])); ?>
                </span>
              </div>
              <div class="staff-actions">
                <a href="staff.php?edit=<?php echo urlencode($member['staff_key']); ?>" class="advance-btn">Edit ▸</a>
                <form method="post" action="staff.php" onsubmit="return confirm('Remove <?php echo htmlspecialchars($member['name'], ENT_QUOTES); ?> from the team?');">
                  <input type="hidden" name="delete_staff" value="<?php echo htmlspecialchars($member['staff_key']); ?>">
                  <button type="submit" class="advance-btn staff-remove-btn">Remove</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>

<div class="modal-overlay<?php echo $show_modal ? ' open' : ''; ?>">
  <div class="modal">
    <h3><?php echo $edit_staff ? 'Edit Staff Member' : 'Add Staff Member'; ?></h3>
      <?php if ($edit_staff): ?>
      <p style="font-size:13px;color:var(--text-mid);margin-bottom:18px;">Joined <?php echo htmlspecialchars(format_join_date($edit_staff['created_at'] ?? '')); ?></p>
      <?php endif; ?>
      <form method="post" action="staff.php">
      <input type="hidden" name="save_staff" value="1">
      <input type="hidden" name="staff_key" value="<?php echo htmlspecialchars($edit_staff['staff_key'] ?? ($form_staff['staff_key'] ?? '')); ?>">
      <div class="field">
        <label for="name">Full name</label>
        <input type="text" id="name" name="name" placeholder="e.g. Ravi Kumar" required value="<?php echo htmlspecialchars($edit_staff['name'] ?? ($form_staff['name'] ?? '')); ?>">
      </div>

      <div class="field">
        <label for="email">Email</label>
        <input type="text" id="email" name="email" placeholder="e.g. ravi@gmail.com" required value="<?php echo htmlspecialchars($edit_staff['email'] ?? ($form_staff['email'] ?? '')); ?>">
      </div>
      <div class="field">
        <label for="password">Password</label>
        <div class="password-field-wrap">
          <input type="password" id="password" name="password" placeholder="e.g. a strong password" required value="<?php echo htmlspecialchars($edit_staff['password'] ?? ($form_staff['password'] ?? '')); ?>">
          <button type="button" class="password-eye-btn" onclick="togglePasswordVisibility()" aria-label="Show password">👁</button>
        </div>
      </div>

      <div class="field-row">
        <div class="field">
          <label for="role_title">Role / job title</label>
          <input type="text" id="role_title" name="role_title" placeholder="e.g. Head Chef" required value="<?php echo htmlspecialchars($edit_staff['role_title'] ?? ($form_staff['role_title'] ?? '')); ?>">
        </div>
        <div class="field">
          <label for="department">Department</label>
          <select id="department" name="department" required>
            <?php
            $sel_dept = $edit_staff['department'] ?? ($form_staff['department'] ?? '');
            foreach ($departments as $dept):
            ?>
              <option value="<?php echo htmlspecialchars($dept); ?>"<?php echo $sel_dept === $dept ? ' selected' : ''; ?>><?php echo htmlspecialchars($dept); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="field">
        <label for="status">Shift status</label>
        <select id="status" name="status">
          <?php $sel_status = $edit_staff['status'] ?? ($form_staff['status'] ?? 'active'); ?>
          <option value="active"<?php echo $sel_status === 'active' ? ' selected' : ''; ?>>Active</option>
          <option value="off"<?php echo $sel_status === 'off' ? ' selected' : ''; ?>>Off shift</option>
        </select>
      </div>
      <div class="modal-actions">
        <a href="staff.php" class="btn btn-ghost">Cancel</a>
        <button type="submit" class="btn btn-primary"><?php echo $edit_staff ? 'Save Changes' : 'Add Staff'; ?></button>
      </div>
    </form>
  </div>
</div>

<script>
function togglePasswordVisibility(){
  const input = document.getElementById('password');
  const btn = document.querySelector('.password-eye-btn');
  if(!input || !btn) return;
  const showing = input.type === 'text';
  input.type = showing ? 'password' : 'text';
  btn.textContent = showing ? '👁' : '🙈';
  btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
}
(function initMobileNav(){
  const sidebar = document.querySelector('.sidebar');
  const topbar = document.querySelector('.topbar');
  if(!sidebar || !topbar) return;
  if(!document.querySelector('.sidebar-backdrop')){
    const backdrop = document.createElement('div');
    backdrop.className = 'sidebar-backdrop';
    backdrop.addEventListener('click', () => document.body.classList.remove('sidebar-open'));
    sidebar.insertAdjacentElement('afterend', backdrop);
  }
  if(!document.getElementById('mobileNavToggle')){
    const toggle = document.createElement('button');
    toggle.id = 'mobileNavToggle';
    toggle.type = 'button';
    toggle.className = 'mobile-nav-toggle';
    toggle.setAttribute('aria-label', 'Open navigation menu');
    toggle.innerHTML = '☰';
    toggle.addEventListener('click', () => document.body.classList.toggle('sidebar-open'));
    topbar.insertBefore(toggle, topbar.firstChild);
  }
  sidebar.querySelectorAll('.nav-item').forEach(link => {
    link.addEventListener('click', () => document.body.classList.remove('sidebar-open'));
  });
})();
</script>

</body>
</html>