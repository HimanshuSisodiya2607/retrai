<?php
session_start();
require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/uploads.php';

if (empty($_SESSION['restro_key'])) {
    header('Location: sign-in.php');
    exit;
}

$restro_key = $_SESSION['restro_key'];
session_write_close();

extract(restro_load_nav($conn, $restro_key));
$current_page = 'menu';

$error = '';
$show_modal = false;

// Toggle active / inactive
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['toggle_item'])) {
    $item_key = $_POST['toggle_item'];
    $stmt = mysqli_prepare($conn, "
        UPDATE items SET is_active = IF(is_active = 1, 0, 1)
        WHERE item_key = ? AND restro_key = ?
    ");
    mysqli_stmt_bind_param($stmt, 'ss', $item_key, $restro_key);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    header('Location: menu.php');
    exit;
}

// Delete item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['delete_item'])) {
    $item_key = $_POST['delete_item'];
    $del = mysqli_prepare($conn, "DELETE FROM items WHERE item_key = ? AND restro_key = ?");
    mysqli_stmt_bind_param($del, 'ss', $item_key, $restro_key);
    mysqli_stmt_execute($del);
    mysqli_stmt_close($del);
    header('Location: menu.php');
    exit;
}

// Add or edit item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['save_item'])) {
    $item_key = trim($_POST['item_key'] ?? '');
    $category_key = trim($_POST['category_key'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $emoji = trim($_POST['emoji'] ?? '') ?: '🍽';
    $description = trim($_POST['description'] ?? '');
    $price = (float) ($_POST['price'] ?? 0);
    $is_active = !empty($_POST['is_active']) ? 1 : 0;

    // The 3D model is delivered by the RestroAI team via the AR Studio
    // request flow, so it is never edited here — carry the existing value.
    $glb_db = null;
    $existing_photo = null;
    if ($item_key !== '') {
        $cur = mysqli_prepare($conn, "SELECT glb_url, photo_url FROM items WHERE item_key = ? AND restro_key = ?");
        mysqli_stmt_bind_param($cur, 'ss', $item_key, $restro_key);
        mysqli_stmt_execute($cur);
        $cur_row = mysqli_fetch_assoc(mysqli_stmt_get_result($cur));
        mysqli_stmt_close($cur);
        $glb_db = $cur_row['glb_url'] ?? null;
        $existing_photo = $cur_row['photo_url'] ?? null;
    }

    $photo_db = $existing_photo;
    if (!empty($_POST['remove_photo'])) {
        delete_upload($existing_photo);
        $photo_db = null;
    }
    if (($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $up = store_dish_photo($_FILES['photo']);
        if (!$up['ok']) {
            $error = $up['error'];
            $show_modal = true;
        } else {
            delete_upload($existing_photo);
            $photo_db = $up['path'];
        }
    }

    if ($error !== '') {
        // fall through to re-render the modal with the message
    } elseif ($name === '' || $price <= 0) {
        $error = 'Please add at least a name and price.';
        $show_modal = true;
    } elseif ($category_key === '') {
        $error = 'Please add a category first under Categories.';
        $show_modal = true;
    } else {
        $chk = mysqli_prepare($conn, "SELECT category_key FROM categories WHERE category_key = ? AND restro_key = ?");
        mysqli_stmt_bind_param($chk, 'ss', $category_key, $restro_key);
        mysqli_stmt_execute($chk);
        $cat_ok = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
        mysqli_stmt_close($chk);

        if (!$cat_ok) {
            $error = 'Invalid category selected.';
            $show_modal = true;
        } elseif ($item_key === '') {
            $item_key = 'itm_' . bin2hex(random_bytes(6));
            $sort_stmt = mysqli_prepare($conn, "SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_sort FROM items WHERE restro_key = ?");
            mysqli_stmt_bind_param($sort_stmt, 's', $restro_key);
            mysqli_stmt_execute($sort_stmt);
            $sort_order = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($sort_stmt))['next_sort'];
            mysqli_stmt_close($sort_stmt);

            $ins = mysqli_prepare($conn, "
                INSERT INTO items (item_key, restro_key, category_key, name, emoji, description, photo_url, price, glb_url, is_active, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            mysqli_stmt_bind_param(
                $ins, 'sssssssdsii',
                $item_key, $restro_key, $category_key, $name, $emoji, $description, $photo_db, $price, $glb_db, $is_active, $sort_order
            );
            mysqli_stmt_execute($ins);
            mysqli_stmt_close($ins);
            header('Location: menu.php');
            exit;
        } else {
            $upd = mysqli_prepare($conn, "
                UPDATE items
                SET category_key = ?, name = ?, emoji = ?, description = ?, photo_url = ?, price = ?, is_active = ?
                WHERE item_key = ? AND restro_key = ?
            ");
            mysqli_stmt_bind_param(
                $upd, 'sssssdiss',
                $category_key, $name, $emoji, $description, $photo_db, $price, $is_active, $item_key, $restro_key
            );
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);
            header('Location: menu.php');
            exit;
        }
    }
}

$edit_key = trim($_GET['edit'] ?? '');
$edit_item = null;

if (!$show_modal) {
    $show_modal = isset($_GET['add']);
}

if ($edit_key !== '') {
    $stmt = mysqli_prepare($conn, "
        SELECT item_key, category_key, name, emoji, description, photo_url, price, glb_url, is_active
        FROM items
        WHERE item_key = ? AND restro_key = ?
    ");
    mysqli_stmt_bind_param($stmt, 'ss', $edit_key, $restro_key);
    mysqli_stmt_execute($stmt);
    $edit_item = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if ($edit_item) $show_modal = true;
}

// Categories for dropdown
$stmt = mysqli_prepare($conn, "
    SELECT category_key, name, emoji
    FROM categories
    WHERE restro_key = ?
    ORDER BY sort_order, name
");
mysqli_stmt_bind_param($stmt, 's', $restro_key);
mysqli_stmt_execute($stmt);
$categories = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

// Menu items (query from restroai.sql)
$stmt = mysqli_prepare($conn, "
    SELECT i.item_key, i.name, i.emoji, i.description, i.photo_url, i.price, i.glb_url, i.is_active,
           c.name AS category_name, c.category_key
    FROM items i
    JOIN categories c ON c.category_key = i.category_key AND c.restro_key = i.restro_key
    WHERE i.restro_key = ?
    ORDER BY c.sort_order, i.sort_order, i.name
");
mysqli_stmt_bind_param($stmt, 's', $restro_key);
mysqli_stmt_execute($stmt);
$dishes = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$active_count = 0;
foreach ($dishes as $d) {
    if ((int) $d['is_active'] === 1) $active_count++;
}

$form_item = $edit_item ?: ($_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Menu — RestroAI</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=<?php echo @filemtime(__DIR__ . '/assets/style.css'); ?>">
</head>
<body>

<div id="bg-glow"></div>

<div class="shell">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <div class="main">
    <div class="topbar">
      <div><h1>Menu Management</h1><div class="sub">Add dishes, adjust prices, toggle availability</div></div>
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
        <h2>Menu Management</h2>
        <div class="link"><?php echo count($dishes); ?> dishes · <?php echo $active_count; ?> active</div>
      </div>
      <div class="menu-grid">
        <?php foreach ($dishes as $d): ?>
          <div class="dish-card<?php echo (int) $d['is_active'] === 1 ? '' : ' inactive'; ?>">
            <div class="dish-top">
              <div class="dish-emoji<?php echo !empty($d['photo_url']) ? ' has-photo' : ''; ?>">
                <?php if (!empty($d['photo_url'])): ?>
                  <img src="<?php echo htmlspecialchars(asset_url($d['photo_url'])); ?>" alt="">
                <?php else: ?>
                  <?php echo htmlspecialchars($d['emoji'] ?: '🍽'); ?>
                <?php endif; ?>
              </div>
              <form method="post" action="menu.php" style="display:inline;">
                <input type="hidden" name="toggle_item" value="<?php echo htmlspecialchars($d['item_key']); ?>">
                <button type="submit" class="toggle<?php echo (int) $d['is_active'] === 1 ? ' on' : ''; ?>" aria-label="Toggle availability"></button>
              </form>
            </div>
            <div><span class="cat"><?php echo htmlspecialchars($d['category_name']); ?></span><h4><?php echo htmlspecialchars($d['name']); ?></h4></div>
            <?php if (!empty($d['description'])): ?>
              <div class="desc"><?php echo htmlspecialchars($d['description']); ?></div>
            <?php endif; ?>
            <?php if (!empty($d['glb_url'])): ?>
              <div class="impact" style="width:fit-content;">📱 AR model attached</div>
            <?php endif; ?>
            <div class="dish-foot">
              <span class="dish-price">₹<?php echo number_format((float) $d['price'], 0, '.', ','); ?></span>
              <div class="dish-actions">
                <a href="menu.php?edit=<?php echo urlencode($d['item_key']); ?>" class="a" title="Edit">✎</a>
                <form method="post" action="menu.php" style="display:inline;">
                  <input type="hidden" name="delete_item" value="<?php echo htmlspecialchars($d['item_key']); ?>">
                  <button type="submit" class="a" title="Delete" onclick="return confirm('Remove &quot;<?php echo htmlspecialchars($d['name'], ENT_QUOTES); ?>&quot; from the menu?');">✕</button>
                </form>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
        <a href="menu.php?add=1" class="dish-card add-dish-card"><span class="plus">+</span>Add menu item</a>
      </div>

    </div>
  </div>
</div>

<div class="modal-overlay<?php echo $show_modal ? ' open' : ''; ?>" id="menuModalOverlay">
  <div class="modal">
    <h3><?php echo $edit_item ? 'Edit Menu Item' : 'Add Menu Item'; ?></h3>
    <form method="post" action="menu.php" enctype="multipart/form-data">
      <input type="hidden" name="save_item" value="1">
      <input type="hidden" name="item_key" value="<?php echo htmlspecialchars($edit_item['item_key'] ?? ($form_item['item_key'] ?? '')); ?>">
      <div class="field-row">
        <div class="field">
          <label for="emoji">Emoji</label>
          <input type="text" id="emoji" name="emoji" maxlength="8" placeholder="🍽" value="<?php echo htmlspecialchars($edit_item['emoji'] ?? ($form_item['emoji'] ?? '🍽')); ?>">
        </div>
        <div class="field" style="flex:2;">
          <label for="name">Name</label>
          <input type="text" id="name" name="name" placeholder="e.g. Truffle Pasta" required value="<?php echo htmlspecialchars($edit_item['name'] ?? ($form_item['name'] ?? '')); ?>">
        </div>
      </div>
      <div class="field-row">
        <div class="field">
          <label for="category_key">Category</label>
          <select id="category_key" name="category_key" required>
            <?php if (count($categories) === 0): ?>
              <option value="">No categories — add one first</option>
            <?php else: ?>
              <option value="">Select category</option>
              <?php
              $selected_cat = $edit_item['category_key'] ?? ($form_item['category_key'] ?? '');
              foreach ($categories as $cat):
              ?>
                <option value="<?php echo htmlspecialchars($cat['category_key']); ?>"<?php echo $selected_cat === $cat['category_key'] ? ' selected' : ''; ?>>
                  <?php echo htmlspecialchars(($cat['emoji'] ? $cat['emoji'] . ' ' : '') . $cat['name']); ?>
                </option>
              <?php endforeach; ?>
            <?php endif; ?>
          </select>
        </div>
        <div class="field">
          <label for="price">Price (₹)</label>
          <input type="number" id="price" name="price" placeholder="e.g. 320" min="1" step="1" required value="<?php echo htmlspecialchars((string) ($edit_item['price'] ?? ($form_item['price'] ?? ''))); ?>">
        </div>
      </div>
      <div class="field">
        <label for="description">Description</label>
        <textarea id="description" name="description" placeholder="Short description shown on the menu"><?php echo htmlspecialchars($edit_item['description'] ?? ($form_item['description'] ?? '')); ?></textarea>
      </div>
      <?php $cur_photo = $edit_item['photo_url'] ?? null; ?>
      <div class="field">
        <label for="photo">Dish photo — optional</label>
        <?php if ($cur_photo): ?>
          <div class="photo-current">
            <img src="../<?php echo htmlspecialchars($cur_photo); ?>" alt="">
            <label class="photo-remove">
              <input type="checkbox" name="remove_photo" value="1" style="accent-color:#ff5a1f;">
              Remove this photo
            </label>
          </div>
        <?php endif; ?>
        <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp,image/gif">
        <div class="field-hint">JPG, PNG, WEBP or GIF · up to 4 MB. Shown to guests on the QR menu.</div>
      </div>

      <?php if ($edit_item): ?>
      <div class="field">
        <label>3D AR model</label>
        <div class="field-hint">
          <?php if (!empty($edit_item['glb_url'])): ?>
            ✅ This dish has an AR model. Guests can view it in 3D.
          <?php else: ?>
            Request one from <a href="ar-studio.php" style="color:var(--ember);">AR Menu Studio</a> — our team builds and delivers it.
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
      <label class="check-row">
        <input type="checkbox" name="is_active" value="1" style="accent-color:#ff5a1f;"<?php echo ($edit_item ? (int) $edit_item['is_active'] === 1 : ($form_item ? !empty($form_item['is_active']) : true)) ? ' checked' : ''; ?>>
        Active — visible on customer menu
      </label>
      <div class="modal-actions">
        <a href="menu.php" class="btn btn-ghost">Cancel</a>
        <button type="submit" class="btn btn-primary"<?php echo count($categories) === 0 ? ' disabled' : ''; ?>><?php echo $edit_item ? 'Save Changes' : 'Add Item'; ?></button>
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
