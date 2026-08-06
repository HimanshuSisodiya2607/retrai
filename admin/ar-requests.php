<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../Restro/includes/uploads.php';

$flash = '';
$flash_type = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $request_key = trim($_POST['request_key'] ?? '');
    $rows = $request_key === '' ? [] : admin_rows($conn, "
        SELECT a.request_key, a.item_key, a.status, i.name AS item_name, i.glb_url
        FROM ar_requests a
        JOIN items i ON i.item_key = a.item_key
        WHERE a.request_key = ?
    ", [$request_key], 's');
    $req = $rows[0] ?? null;

    if (!$req) {
        $flash = 'AR request not found.';
        $flash_type = 'err';
    } elseif (!empty($_POST['upload_model'])) {
        $up = store_ar_model($_FILES['model'] ?? []);
        if (!$up['ok']) {
            $flash = $up['error'];
            $flash_type = 'err';
        } else {
            delete_upload($req['glb_url']);

            $stmt = mysqli_prepare($conn, "UPDATE items SET glb_url = ?, updated_at = NOW() WHERE item_key = ?");
            mysqli_stmt_bind_param($stmt, 'ss', $up['path'], $req['item_key']);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare($conn, "UPDATE ar_requests SET status = 'delivered', delivered_at = NOW() WHERE request_key = ?");
            mysqli_stmt_bind_param($stmt, 's', $request_key);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $flash = 'Model delivered — ' . $req['item_name'] . ' is now live in AR on their customer menu.';
        }
    } elseif (isset($_POST['set_status'])) {
        $status = $_POST['set_status'];
        if (!in_array($status, ['requested', 'in_progress', 'rejected'], true)) {
            $flash = 'Unknown status.';
            $flash_type = 'err';
        } else {
            $note = trim($_POST['admin_note'] ?? '');
            $note = $note === '' ? null : mb_substr($note, 0, 500);
            $stmt = mysqli_prepare($conn, "UPDATE ar_requests SET status = ?, admin_note = ? WHERE request_key = ?");
            mysqli_stmt_bind_param($stmt, 'sss', $status, $note, $request_key);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $flash = $req['item_name'] . ' marked ' . str_replace('_', ' ', $status) . '.';
        }
    }
}

$status_filter = $_GET['status'] ?? 'open';
$restro = trim($_GET['restro'] ?? '');

$sql = "
    SELECT a.request_key, a.item_key, a.status, a.admin_note, a.requested_at, a.delivered_at,
           i.name AS item_name, i.emoji, i.photo_url, i.price, i.glb_url, i.description,
           c.name AS category, r.restaurant_name, r.restro_key
    FROM ar_requests a
    JOIN items i ON i.item_key = a.item_key
    JOIN restaurants r ON r.restro_key = a.restro_key
    LEFT JOIN categories c ON c.category_key = i.category_key
    WHERE 1=1
";
$params = [];
$types = '';
if ($status_filter === 'open') {
    $sql .= " AND a.status IN ('requested','in_progress')";
} elseif (in_array($status_filter, ['requested', 'in_progress', 'delivered', 'rejected'], true)) {
    $sql .= " AND a.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}
if ($restro !== '') {
    $sql .= " AND a.restro_key = ?";
    $params[] = $restro;
    $types .= 's';
}
$sql .= " ORDER BY FIELD(a.status,'requested','in_progress','delivered','rejected'), a.requested_at ASC";

$requests = admin_rows($conn, $sql, $params, $types);
$all_restros = admin_rows($conn, "SELECT restro_key, restaurant_name FROM restaurants ORDER BY restaurant_name");

$counts = [];
foreach (admin_rows($conn, "SELECT status, COUNT(*) AS c FROM ar_requests GROUP BY status") as $row) {
    $counts[$row['status']] = (int) $row['c'];
}
$open_total = ($counts['requested'] ?? 0) + ($counts['in_progress'] ?? 0);

$page_title = 'AR Requests';
$page_sub = $open_total . ' awaiting a model · ' . ($counts['delivered'] ?? 0) . ' delivered';
$current_page = 'ar-requests';
include __DIR__ . '/includes/header.php';
?>

<?php if ($flash !== ''): ?>
  <div class="flash <?php echo $flash_type; ?>"><?php echo htmlspecialchars($flash); ?></div>
<?php endif; ?>

<div class="stat-row">
  <div class="stat-tile accent"><div class="k">Queued</div><div class="v"><?php echo $counts['requested'] ?? 0; ?></div><div class="s">not started</div></div>
  <div class="stat-tile"><div class="k">In progress</div><div class="v"><?php echo $counts['in_progress'] ?? 0; ?></div><div class="s">being modelled</div></div>
  <div class="stat-tile"><div class="k">Delivered</div><div class="v"><?php echo $counts['delivered'] ?? 0; ?></div><div class="s">live in AR</div></div>
  <div class="stat-tile"><div class="k">Declined</div><div class="v"><?php echo $counts['rejected'] ?? 0; ?></div><div class="s">not proceeding</div></div>
</div>

<form method="get" action="ar-requests.php" class="admin-toolbar">
  <select name="status" onchange="this.form.submit()">
    <?php foreach ([
        'open' => 'Open (queued + in progress)', 'requested' => 'Queued only',
        'in_progress' => 'In progress only', 'delivered' => 'Delivered', 'rejected' => 'Declined', 'all' => 'All requests',
    ] as $v => $label): ?>
      <option value="<?php echo $v; ?>"<?php echo $status_filter === $v ? ' selected' : ''; ?>><?php echo $label; ?></option>
    <?php endforeach; ?>
  </select>
  <select name="restro" onchange="this.form.submit()">
    <option value="">All restaurants</option>
    <?php foreach ($all_restros as $ar): ?>
      <option value="<?php echo htmlspecialchars($ar['restro_key']); ?>"<?php echo $restro === $ar['restro_key'] ? ' selected' : ''; ?>>
        <?php echo htmlspecialchars($ar['restaurant_name']); ?>
      </option>
    <?php endforeach; ?>
  </select>
  <noscript><button type="submit" class="btn-xs primary">Filter</button></noscript>
</form>

<?php if (!$requests): ?>
  <div class="panel"><div class="empty">No AR requests match that filter.</div></div>
<?php else: ?>
  <?php foreach ($requests as $q): ?>
  <div class="panel">
    <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:flex-start;">

      <div style="width:150px;flex-shrink:0;">
        <div style="width:150px;height:118px;border-radius:12px;overflow:hidden;background:var(--glass);display:flex;align-items:center;justify-content:center;font-size:40px;border:1px solid var(--line);">
          <?php if (!empty($q['photo_url'])): ?>
            <img src="../<?php echo htmlspecialchars($q['photo_url']); ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
          <?php else: ?>
            <?php echo htmlspecialchars($q['emoji'] ?: '🍽'); ?>
          <?php endif; ?>
        </div>
        <?php if (!empty($q['photo_url'])): ?>
          <a class="btn-xs" style="margin-top:8px;display:inline-block;" href="../<?php echo htmlspecialchars($q['photo_url']); ?>" target="_blank">View photo</a>
        <?php else: ?>
          <div style="font-size:10.5px;color:var(--amber);margin-top:8px;line-height:1.5;">No photo supplied — ask the restaurant to add one.</div>
        <?php endif; ?>
      </div>

      <div style="flex:1;min-width:250px;">
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <h3 style="font-family:var(--font-display);font-size:16px;font-weight:600;"><?php echo htmlspecialchars($q['item_name']); ?></h3>
          <?php
          $pill = ['requested' => 'plan', 'in_progress' => 'plan', 'delivered' => 'on', 'rejected' => 'off'][$q['status']] ?? 'plan';
          ?>
          <span class="pill <?php echo $pill; ?>"><?php echo htmlspecialchars(str_replace('_', ' ', $q['status'])); ?></span>
          <?php if (!empty($q['glb_url'])): ?><span class="pill on">model on file</span><?php endif; ?>
        </div>
        <div style="font-size:12px;color:var(--text-mid);margin-top:5px;">
          <a class="link" href="restaurant.php?key=<?php echo urlencode($q['restro_key']); ?>"><?php echo htmlspecialchars($q['restaurant_name']); ?></a>
          · <?php echo htmlspecialchars($q['category'] ?: 'Uncategorised'); ?>
          · <?php echo admin_money((float) $q['price']); ?>
        </div>
        <?php if (!empty($q['description'])): ?>
          <div style="font-size:12px;color:var(--text-low);margin-top:7px;max-width:520px;line-height:1.55;"><?php echo htmlspecialchars($q['description']); ?></div>
        <?php endif; ?>
        <div style="font-family:var(--font-mono);font-size:10.5px;color:var(--text-low);margin-top:8px;">
          Requested <?php echo date('j M Y, g:i A', strtotime($q['requested_at'])); ?>
          <?php if ($q['delivered_at']): ?> · delivered <?php echo date('j M Y', strtotime($q['delivered_at'])); ?><?php endif; ?>
        </div>
        <?php if (!empty($q['admin_note'])): ?>
          <div style="font-size:12px;color:var(--amber);margin-top:8px;">Note to restaurant: <?php echo htmlspecialchars($q['admin_note']); ?></div>
        <?php endif; ?>
      </div>

      <div style="width:290px;flex-shrink:0;display:flex;flex-direction:column;gap:12px;">
        <form method="post" action="ar-requests.php" enctype="multipart/form-data"
              style="background:var(--bg);border:1px solid var(--line);border-radius:12px;padding:13px;">
          <input type="hidden" name="request_key" value="<?php echo htmlspecialchars($q['request_key']); ?>">
          <input type="hidden" name="upload_model" value="1">
          <label style="font-family:var(--font-mono);font-size:10px;letter-spacing:0.07em;text-transform:uppercase;color:var(--text-low);display:block;margin-bottom:8px;">
            <?php echo !empty($q['glb_url']) ? 'Replace model' : 'Upload model'; ?>
          </label>
          <input type="file" name="model" accept=".glb,.gltf" required
                 style="width:100%;background:var(--bg-panel);border:1px solid var(--line);border-radius:8px;color:var(--text-mid);padding:8px;font-size:11.5px;font-family:inherit;">
          <div style="font-size:10.5px;color:var(--text-low);margin-top:7px;">.glb or .gltf · up to 30 MB</div>
          <button type="submit" class="btn-xs primary" style="margin-top:10px;width:100%;padding:8px;">
            <?php echo !empty($q['glb_url']) ? 'Replace &amp; redeliver' : 'Deliver model'; ?>
          </button>
        </form>

        <form method="post" action="ar-requests.php"
              style="background:var(--bg);border:1px solid var(--line);border-radius:12px;padding:13px;">
          <input type="hidden" name="request_key" value="<?php echo htmlspecialchars($q['request_key']); ?>">
          <label style="font-family:var(--font-mono);font-size:10px;letter-spacing:0.07em;text-transform:uppercase;color:var(--text-low);display:block;margin-bottom:8px;">Status &amp; note</label>
          <input type="text" name="admin_note" maxlength="500" placeholder="Optional note shown to the restaurant"
                 value="<?php echo htmlspecialchars($q['admin_note'] ?? ''); ?>"
                 style="width:100%;background:var(--bg-panel);border:1px solid var(--line);border-radius:8px;color:var(--text-hi);padding:8px 10px;font-size:11.5px;font-family:inherit;margin-bottom:9px;">
          <div style="display:flex;gap:6px;flex-wrap:wrap;">
            <button type="submit" name="set_status" value="in_progress" class="btn-xs">Start</button>
            <button type="submit" name="set_status" value="requested" class="btn-xs">Re-queue</button>
            <button type="submit" name="set_status" value="rejected" class="btn-xs danger">Decline</button>
          </div>
        </form>
      </div>

    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
