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
$current_page = 'categories';

$error = '';
$show_modal = false;

// Delete category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['delete_category'])) {
    $category_key = $_POST['delete_category'];

    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM items WHERE category_key = ? AND restro_key = ?");
    mysqli_stmt_bind_param($stmt, 'ss', $category_key, $restro_key);
    mysqli_stmt_execute($stmt);
    $dish_count = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];
    mysqli_stmt_close($stmt);

    if ($dish_count > 0) {
        $stmt = mysqli_prepare($conn, "SELECT name FROM categories WHERE category_key = ? AND restro_key = ?");
        mysqli_stmt_bind_param($stmt, 'ss', $category_key, $restro_key);
        mysqli_stmt_execute($stmt);
        $cat_row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        $cat_name = $cat_row['name'] ?? 'Category';
        $error = 'Cannot delete "' . $cat_name . '" — ' . $dish_count . ' menu item' . ($dish_count === 1 ? '' : 's') . ' still use it.';
    } else {
        $del = mysqli_prepare($conn, "DELETE FROM categories WHERE category_key = ? AND restro_key = ?");
        mysqli_stmt_bind_param($del, 'ss', $category_key, $restro_key);
        mysqli_stmt_execute($del);
        mysqli_stmt_close($del);
        header('Location: categories.php');
        exit;
    }
}

// Add or edit category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['save_category'])) {
    $category_key = trim($_POST['category_key'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $emoji = trim($_POST['emoji'] ?? '') ?: '📁';

    if ($name === '') {
        $error = 'Please enter a category name.';
        $show_modal = true;
    } else {
        if ($category_key === '') {
            $dup = mysqli_prepare($conn, "
                SELECT category_key FROM categories
                WHERE restro_key = ? AND LOWER(name) = LOWER(?)
            ");
            mysqli_stmt_bind_param($dup, 'ss', $restro_key, $name);
            mysqli_stmt_execute($dup);
            $exists = mysqli_fetch_assoc(mysqli_stmt_get_result($dup));
            mysqli_stmt_close($dup);

            if ($exists) {
                $error = 'A category with this name already exists.';
                $show_modal = true;
            } else {
                $category_key = 'cat_' . bin2hex(random_bytes(6));
                $sort_stmt = mysqli_prepare($conn, "SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_sort FROM categories WHERE restro_key = ?");
                mysqli_stmt_bind_param($sort_stmt, 's', $restro_key);
                mysqli_stmt_execute($sort_stmt);
                $sort_order = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($sort_stmt))['next_sort'];
                mysqli_stmt_close($sort_stmt);

                $ins = mysqli_prepare($conn, "
                    INSERT INTO categories (category_key, restro_key, name, emoji, sort_order)
                    VALUES (?, ?, ?, ?, ?)
                ");
                mysqli_stmt_bind_param($ins, 'ssssi', $category_key, $restro_key, $name, $emoji, $sort_order);
                mysqli_stmt_execute($ins);
                mysqli_stmt_close($ins);
                header('Location: categories.php');
                exit;
            }
        } else {
            $dup = mysqli_prepare($conn, "
                SELECT category_key FROM categories
                WHERE restro_key = ? AND LOWER(name) = LOWER(?) AND category_key != ?
            ");
            mysqli_stmt_bind_param($dup, 'sss', $restro_key, $name, $category_key);
            mysqli_stmt_execute($dup);
            $exists = mysqli_fetch_assoc(mysqli_stmt_get_result($dup));
            mysqli_stmt_close($dup);

            if ($exists) {
                $error = 'A category with this name already exists.';
                $show_modal = true;
            } else {
                $upd = mysqli_prepare($conn, "
                    UPDATE categories SET name = ?, emoji = ?
                    WHERE category_key = ? AND restro_key = ?
                ");
                mysqli_stmt_bind_param($upd, 'ssss', $name, $emoji, $category_key, $restro_key);
                mysqli_stmt_execute($upd);
                mysqli_stmt_close($upd);
                header('Location: categories.php');
                exit;
            }
        }
    }
}

$edit_key = trim($_GET['edit'] ?? '');
$edit_category = null;

if (!$show_modal) {
    $show_modal = isset($_GET['add']);
}

if ($edit_key !== '') {
    $stmt = mysqli_prepare($conn, "SELECT category_key, name, emoji FROM categories WHERE category_key = ? AND restro_key = ?");
    mysqli_stmt_bind_param($stmt, 'ss', $edit_key, $restro_key);
    mysqli_stmt_execute($stmt);
    $edit_category = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if ($edit_category) $show_modal = true;
}

// Categories with dish counts
$stmt = mysqli_prepare($conn, "
    SELECT c.category_key, c.name, c.emoji, COUNT(i.id) AS dish_count
    FROM categories c
    LEFT JOIN items i ON i.category_key = c.category_key AND i.restro_key = c.restro_key
    WHERE c.restro_key = ?
    GROUP BY c.category_key, c.name, c.emoji, c.sort_order
    ORDER BY c.sort_order, c.name
");
mysqli_stmt_bind_param($stmt, 's', $restro_key);
mysqli_stmt_execute($stmt);
$categories = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$cat_label = count($categories) === 1 ? '1 category' : count($categories) . ' categories';
$form_cat = $edit_category ?: ($_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Categories — RestroAI</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>

<div id="bg-glow"></div>

<div class="shell">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <div class="main">
    <div class="topbar">
      <div><h1>Categories</h1><div class="sub">Organize dishes into menu sections</div></div>
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

      <div class="panel-head">
        <h2>Menu Categories</h2>
        <div class="link"><?php echo htmlspecialchars($cat_label); ?></div>
      </div>
      <p style="font-size:13px;color:var(--text-mid);margin:-8px 0 20px;">Categories appear in the menu item form and on the customer ordering page.</p>
      <div class="category-grid">
        <?php if (count($categories) === 0): ?>
          <div class="empty-note">No categories yet — add one to organize your menu.</div>
        <?php else: ?>
          <?php foreach ($categories as $cat):
            $count = (int) $cat['dish_count'];
          ?>
            <div class="category-card">
              <div class="category-top">
                <div class="category-emoji"><?php echo htmlspecialchars($cat['emoji'] ?: '📁'); ?></div>
                <div class="dish-actions">
                  <a href="categories.php?edit=<?php echo urlencode($cat['category_key']); ?>" class="a" title="Edit">✎</a>
                  <form method="post" action="categories.php" style="display:inline;">
                    <input type="hidden" name="delete_category" value="<?php echo htmlspecialchars($cat['category_key']); ?>">
                    <button type="submit" class="a" title="Delete" onclick="return confirm('Remove category &quot;<?php echo htmlspecialchars($cat['name'], ENT_QUOTES); ?>&quot;?');">✕</button>
                  </form>
                </div>
              </div>
              <h4><?php echo htmlspecialchars($cat['name']); ?></h4>
              <span class="category-meta"><?php echo $count; ?> dish<?php echo $count === 1 ? '' : 'es'; ?></span>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
        <a href="categories.php?add=1" class="category-card add-category-card"><span class="plus">+</span>Add category</a>
      </div>

    </div>
  </div>
</div>

<div class="modal-overlay<?php echo $show_modal ? ' open' : ''; ?>" id="categoryModalOverlay">
  <div class="modal">
    <h3><?php echo $edit_category ? 'Edit Category' : 'Add Category'; ?></h3>
    <form method="post" action="categories.php">
      <input type="hidden" name="save_category" value="1">
      <input type="hidden" name="category_key" value="<?php echo htmlspecialchars($edit_category['category_key'] ?? ($form_cat['category_key'] ?? '')); ?>">
      <div class="field-row">
        <div class="field">
          <label for="emoji">Emoji</label>
          <input type="text" id="emoji" name="emoji" maxlength="8" placeholder="📁" value="<?php echo htmlspecialchars($edit_category['emoji'] ?? ($form_cat['emoji'] ?? '📁')); ?>">
        </div>
        <div class="field" style="flex:2;">
          <label for="name">Name</label>
          <input type="text" id="name" name="name" placeholder="e.g. Starters" required value="<?php echo htmlspecialchars($edit_category['name'] ?? ($form_cat['name'] ?? '')); ?>">
        </div>
      </div>
      <div class="modal-actions">
        <a href="categories.php" class="btn btn-ghost">Cancel</a>
        <button type="submit" class="btn btn-primary"><?php echo $edit_category ? 'Save Changes' : 'Add Category'; ?></button>
      </div>
    </form>
  </div>
</div>

<script>
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
