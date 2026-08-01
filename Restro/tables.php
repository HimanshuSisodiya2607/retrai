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
$current_page = 'tables';

$error = '';

function tableMenuUrl($table_key, $table_name) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = $scheme . '://' . $host . dirname($_SERVER['SCRIPT_NAME']);
    $base = rtrim(str_replace('\\', '/', $base), '/');
    return $base . '/customer-menu.php?table=' . urlencode($table_key) . '&name=' . urlencode($table_name);
}

function safe_return_url($url, $fallback = 'orders.php') {
    $url = trim($url);
    if ($url !== '' && preg_match('/^[a-z0-9_\-\.]+\.php(\?[a-z0-9_\-=&%.]*)?$/i', $url)) {
        return $url;
    }
    return $fallback;
}

/**
 * Every open order on a table, as one bill: line items plus totals.
 * Shared by the checkout screen, the printable bill, and settlement.
 */
function bill_for_table(mysqli $conn, string $restro_key, string $table_key): array {
    $stmt = mysqli_prepare($conn, "
        SELECT oi.item_name, oi.quantity, oi.unit_price, oi.line_total
        FROM orders o
        JOIN order_items oi ON oi.order_key = o.order_key
        WHERE o.restro_key = ? AND o.table_key = ? AND o.status != 'completed'
        ORDER BY oi.item_name
    ");
    mysqli_stmt_bind_param($stmt, 'ss', $restro_key, $table_key);
    mysqli_stmt_execute($stmt);
    $lines = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, "
        SELECT COUNT(*) AS c, COALESCE(SUM(total_amount), 0) AS total
        FROM orders
        WHERE restro_key = ? AND table_key = ? AND status != 'completed'
    ");
    mysqli_stmt_bind_param($stmt, 'ss', $restro_key, $table_key);
    mysqli_stmt_execute($stmt);
    $meta = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    return [
        'lines' => $lines,
        'order_count' => (int) $meta['c'],
        'subtotal' => (float) $meta['total'],
    ];
}

/**
 * Resolve a requested discount against a subtotal. Clamps to the
 * subtotal so a bill can never go negative, and rejects nonsense.
 */
function resolve_discount(string $type, $value, float $subtotal): array {
    $value = (float) $value;
    if (!in_array($type, ['percent', 'flat'], true) || $value <= 0 || $subtotal <= 0) {
        return ['type' => 'none', 'value' => 0.0, 'amount' => 0.0, 'total' => $subtotal];
    }

    if ($type === 'percent') {
        $value = min($value, 100.0);
        $amount = round($subtotal * $value / 100, 2);
    } else {
        $amount = round($value, 2);
    }
    $amount = min($amount, $subtotal);

    return [
        'type' => $type,
        'value' => $value,
        'amount' => $amount,
        'total' => round($subtotal - $amount, 2),
    ];
}

// Complete table orders after bill review
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['complete_table'])) {
    $table_key = trim($_POST['table_key'] ?? '');
    $return = safe_return_url($_POST['return'] ?? 'orders.php');

    $chk = mysqli_prepare($conn, "SELECT table_key, table_name FROM restaurant_tables WHERE table_key = ? AND restro_key = ?");
    mysqli_stmt_bind_param($chk, 'ss', $table_key, $restro_key);
    mysqli_stmt_execute($chk);
    $ok = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
    mysqli_stmt_close($chk);

    if ($ok) {
        // Snapshot the bill before completing — once the orders flip to
        // 'completed' the queries above no longer see them.
        $bill = bill_for_table($conn, $restro_key, $table_key);
        $disc = resolve_discount($_POST['discount_type'] ?? 'none', $_POST['discount_value'] ?? 0, $bill['subtotal']);

        if ($bill['order_count'] > 0) {
            $bill_key = 'bil_' . bin2hex(random_bytes(6));
            $snapshot = json_encode($bill['lines']);
            $ins = mysqli_prepare($conn, "
                INSERT INTO bills (bill_key, restro_key, table_key, table_name, order_count,
                                   subtotal, discount_type, discount_value, discount_amount, total, items_snapshot)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            mysqli_stmt_bind_param(
                $ins, 'ssssidsddds',
                $bill_key, $restro_key, $table_key, $ok['table_name'], $bill['order_count'],
                $bill['subtotal'], $disc['type'], $disc['value'], $disc['amount'], $disc['total'], $snapshot
            );
            mysqli_stmt_execute($ins);
            mysqli_stmt_close($ins);
        }

        $upd = mysqli_prepare($conn, "
            UPDATE orders SET status = 'completed'
            WHERE restro_key = ? AND table_key = ? AND status != 'completed'
        ");
        mysqli_stmt_bind_param($upd, 'ss', $restro_key, $table_key);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);
    }
    header('Location: ' . $return);
    exit;
}

// Checkout — print bill first, then complete to free the table
if (!empty($_GET['checkout'])) {
    $table_key = trim($_GET['checkout']);
    $return = safe_return_url($_GET['return'] ?? 'orders.php');

    $stmt = mysqli_prepare($conn, "
        SELECT table_name FROM restaurant_tables
        WHERE table_key = ? AND restro_key = ?
    ");
    mysqli_stmt_bind_param($stmt, 'ss', $table_key, $restro_key);
    mysqli_stmt_execute($stmt);
    $table = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$table) {
        die('Table not found.');
    }

    $bill = bill_for_table($conn, $restro_key, $table_key);
    $lines = $bill['lines'];

    if ($bill['order_count'] === 0) {
        header('Location: ' . $return);
        exit;
    }

    $stmt = mysqli_prepare($conn, "SELECT restaurant_name FROM restaurants WHERE restro_key = ?");
    mysqli_stmt_bind_param($stmt, 's', $restro_key);
    mysqli_stmt_execute($stmt);
    $brand = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    $subtotal = $bill['subtotal'];
    $total = $subtotal;
    $brand_name = $brand['restaurant_name'] ?? 'Restaurant';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Checkout — <?php echo htmlspecialchars($table['table_name']); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<style>
.checkout-wrap{max-width:520px;margin:40px auto;padding:0 20px 60px;position:relative;z-index:1;}
.checkout-notice{background:rgba(255,179,71,0.1);border:1px solid rgba(255,179,71,0.35);border-radius:12px;padding:14px 16px;margin-bottom:20px;font-size:13px;color:var(--amber);line-height:1.5;}
.checkout-bill{background:var(--bg-panel);border:1px solid var(--line);border-radius:16px;padding:22px;margin-bottom:20px;}
.checkout-bill table{width:100%;border-collapse:collapse;font-size:13px;margin:14px 0;}
.checkout-bill th{font-family:var(--font-mono);font-size:10px;color:var(--text-low);text-transform:uppercase;border-bottom:1px solid var(--line);padding:8px 4px;text-align:left;}
.checkout-bill td{padding:8px 4px;border-bottom:1px solid rgba(255,255,255,0.05);}
.checkout-bill .r{text-align:right;font-family:var(--font-mono);}
.checkout-line{display:flex;justify-content:space-between;font-size:13px;color:var(--text-mid);padding:4px 0;}
.checkout-line span:last-child{font-family:var(--font-mono);}
.checkout-total{display:flex;justify-content:space-between;font-size:16px;font-weight:600;margin-top:12px;padding-top:12px;border-top:1px solid var(--line);}
.checkout-total span:last-child{font-family:var(--font-mono);color:var(--ember);}
.discount-box{background:var(--bg-panel);border:1px solid var(--line);border-radius:16px;padding:18px 22px;margin-bottom:20px;}
.discount-label{font-family:var(--font-mono);font-size:10px;color:var(--text-low);text-transform:uppercase;letter-spacing:0.08em;display:block;margin-bottom:10px;}
.discount-row{display:flex;gap:10px;}
.discount-select,.discount-input{background:var(--bg-deep);border:1px solid var(--line);border-radius:10px;color:var(--text-high);padding:10px 12px;font-size:13px;font-family:inherit;}
.discount-select{flex:1;}
.discount-input{width:120px;font-family:var(--font-mono);}
.discount-input:disabled{opacity:0.4;}
.discount-hint{font-size:11px;color:var(--text-low);margin-top:8px;}
.checkout-actions{display:flex;flex-wrap:wrap;gap:10px;align-items:center;}
@media print{.checkout-notice,.checkout-actions,.discount-box{display:none !important;}}
</style>
</head>
<body>
<div id="bg-glow"></div>
<div class="checkout-wrap">
  <h1 style="font-size:22px;margin-bottom:6px;">Checkout — <?php echo htmlspecialchars($table['table_name']); ?></h1>
  <p style="color:var(--text-mid);font-size:13px;margin-bottom:20px;">Review the bill before completing the order.</p>

  <div class="checkout-notice">
    Print the bill first. Once you complete the order, the table is marked <strong>free</strong> and the bill will no longer be available from the floor view.
  </div>

  <div class="checkout-bill" id="checkoutBill">
    <div style="font-family:var(--font-mono);font-size:11px;color:var(--text-low);text-transform:uppercase;"><?php echo htmlspecialchars($brand_name); ?></div>
    <div style="font-size:15px;font-weight:600;margin-top:4px;">Table <?php echo htmlspecialchars($table['table_name']); ?></div>
    <table>
      <thead><tr><th>Item</th><th>Qty</th><th class="r">Rate</th><th class="r">Amt</th></tr></thead>
      <tbody>
        <?php foreach ($lines as $line): ?>
        <tr>
          <td><?php echo htmlspecialchars($line['item_name']); ?></td>
          <td><?php echo (int) $line['quantity']; ?></td>
          <td class="r">₹<?php echo number_format((float) $line['unit_price']); ?></td>
          <td class="r">₹<?php echo number_format((float) $line['line_total']); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="checkout-line"><span>Subtotal</span><span id="billSubtotal">₹<?php echo number_format($subtotal, 2); ?></span></div>
    <div class="checkout-line" id="discountRow" style="display:none;"><span id="discountLabel">Discount</span><span id="billDiscount">−₹0.00</span></div>
    <div class="checkout-total"><span>Total</span><span id="billTotal">₹<?php echo number_format($total, 2); ?></span></div>
  </div>

  <div class="discount-box">
    <label class="discount-label">Discount</label>
    <div class="discount-row">
      <select id="discountType" class="discount-select">
        <option value="none">No discount</option>
        <option value="percent">Percent (%)</option>
        <option value="flat">Flat (₹)</option>
      </select>
      <input type="number" id="discountValue" class="discount-input" min="0" step="0.01" value="" placeholder="0" disabled>
    </div>
    <div class="discount-hint" id="discountHint">Applied to the whole table bill.</div>
  </div>

  <div class="checkout-actions">
    <button type="button" class="btn btn-ghost" onclick="window.print()">🖨 Print bill</button>
    <a href="tables.php?bill=<?php echo urlencode($table_key); ?>" class="btn btn-ghost" id="printViewLink" target="_blank">Open print view</a>
    <form method="post" action="tables.php" onsubmit="return confirm('Complete all orders for this table and mark it free?');">
      <input type="hidden" name="complete_table" value="1">
      <input type="hidden" name="table_key" value="<?php echo htmlspecialchars($table_key); ?>">
      <input type="hidden" name="return" value="<?php echo htmlspecialchars($return); ?>">
      <input type="hidden" name="discount_type" id="discountTypeField" value="none">
      <input type="hidden" name="discount_value" id="discountValueField" value="0">
      <button type="submit" class="btn btn-primary">Complete &amp; free table →</button>
    </form>
    <a href="<?php echo htmlspecialchars($return); ?>" class="btn btn-ghost">← Back</a>
  </div>

<script>
(function () {
  var SUBTOTAL = <?php echo json_encode(round($subtotal, 2)); ?>;
  var BILL_URL = <?php echo json_encode('tables.php?bill=' . urlencode($table_key)); ?>;

  var typeEl = document.getElementById('discountType');
  var valueEl = document.getElementById('discountValue');
  var rowEl = document.getElementById('discountRow');
  var labelEl = document.getElementById('discountLabel');
  var amountEl = document.getElementById('billDiscount');
  var totalEl = document.getElementById('billTotal');
  var hintEl = document.getElementById('discountHint');
  var typeField = document.getElementById('discountTypeField');
  var valueField = document.getElementById('discountValueField');
  var printLink = document.getElementById('printViewLink');

  function money(n) {
    return '₹' + n.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  }

  function recalc() {
    var type = typeEl.value;
    var raw = parseFloat(valueEl.value);
    if (isNaN(raw) || raw < 0) raw = 0;

    // Mirror the server's clamping in resolve_discount() so the number
    // on screen is the number that gets saved.
    var amount = 0;
    if (type === 'percent') {
      if (raw > 100) { raw = 100; valueEl.value = 100; }
      amount = SUBTOTAL * raw / 100;
    } else if (type === 'flat') {
      amount = raw;
    }
    amount = Math.round(Math.min(amount, SUBTOTAL) * 100) / 100;

    valueEl.disabled = (type === 'none');
    if (type === 'none') {
      valueEl.value = '';
      rowEl.style.display = 'none';
      hintEl.textContent = 'Applied to the whole table bill.';
    } else {
      rowEl.style.display = 'flex';
      labelEl.textContent = type === 'percent' ? 'Discount (' + raw + '%)' : 'Discount';
      amountEl.textContent = '−' + money(amount);
      hintEl.textContent = amount >= SUBTOTAL && SUBTOTAL > 0
        ? 'Discount covers the full bill — total is ₹0.'
        : 'Applied to the whole table bill.';
    }

    totalEl.textContent = money(SUBTOTAL - amount);
    typeField.value = amount > 0 ? type : 'none';
    valueField.value = amount > 0 ? raw : 0;
    printLink.href = amount > 0
      ? BILL_URL + '&discount_type=' + encodeURIComponent(type) + '&discount_value=' + encodeURIComponent(raw)
      : BILL_URL;
  }

  typeEl.addEventListener('change', recalc);
  valueEl.addEventListener('input', recalc);
  recalc();
})();
</script>
</div>
</body>
</html>
    <?php
    exit;
}

// Printable bill (opens in new tab)
if (!empty($_GET['bill'])) {
    $table_key = $_GET['bill'];

    $stmt = mysqli_prepare($conn, "
        SELECT table_name FROM restaurant_tables
        WHERE table_key = ? AND restro_key = ?
    ");
    mysqli_stmt_bind_param($stmt, 'ss', $table_key, $restro_key);
    mysqli_stmt_execute($stmt);
    $table = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$table) {
        die('Table not found.');
    }

    $bill = bill_for_table($conn, $restro_key, $table_key);
    $lines = $bill['lines'];

    if ($bill['order_count'] === 0) {
        die('No active orders for this table — nothing to bill.');
    }

    $stmt = mysqli_prepare($conn, "SELECT restaurant_name FROM restaurants WHERE restro_key = ?");
    mysqli_stmt_bind_param($stmt, 's', $restro_key);
    mysqli_stmt_execute($stmt);
    $brand = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    $subtotal = $bill['subtotal'];
    // Discount carried over from the checkout screen so the printed bill
    // matches what gets settled.
    $disc = resolve_discount($_GET['discount_type'] ?? 'none', $_GET['discount_value'] ?? 0, $subtotal);
    $total = $disc['total'];
    $brand_name = strtoupper($brand['restaurant_name'] ?? 'RESTAURANT');
    $date_str = date('D, j M Y');
    $time_str = date('g:i A');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Bill — <?php echo htmlspecialchars($table['table_name']); ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:Arial,Helvetica,sans-serif;color:#111;padding:28px 22px;max-width:320px;margin:0 auto;}
.brand{text-align:center;border-bottom:2px dashed #ccc;padding-bottom:14px;margin-bottom:14px;}
.brand h1{font-size:20px;letter-spacing:0.04em;}
.brand p{font-size:11px;color:#666;margin-top:4px;}
.meta{font-size:12px;margin-bottom:16px;line-height:1.7;}
.meta strong{font-weight:700;}
table{width:100%;border-collapse:collapse;font-size:12px;margin-bottom:12px;}
th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:0.06em;color:#666;border-bottom:1px solid #ddd;padding:6px 4px;}
th.c,td.c{text-align:center;}
th.r,td.r{text-align:right;}
td{padding:7px 4px;border-bottom:1px dotted #e5e5e5;vertical-align:top;}
.totals{border-top:2px solid #111;padding-top:10px;font-size:13px;}
.totals .row{display:flex;justify-content:space-between;padding:3px 0;}
.totals .grand{font-size:17px;font-weight:700;margin-top:6px;padding-top:8px;border-top:1px dashed #ccc;}
.foot{text-align:center;margin-top:22px;font-size:10px;color:#999;letter-spacing:0.06em;}
@media print{body{padding:12px;}}
</style>
</head>
<body>
<div class="brand"><h1><?php echo htmlspecialchars($brand_name); ?></h1><p>RestroAI · Dine-in Bill</p></div>
<div class="meta">
  <div><strong>Table:</strong> <?php echo htmlspecialchars($table['table_name']); ?></div>
  <div><strong>Date:</strong> <?php echo htmlspecialchars($date_str); ?> · <?php echo htmlspecialchars($time_str); ?></div>
  <div><strong>Orders:</strong> <?php echo (int) $bill['order_count']; ?></div>
</div>
<table>
  <thead><tr><th>Item</th><th class="c">Qty</th><th class="r">Rate</th><th class="r">Amt</th></tr></thead>
  <tbody>
    <?php foreach ($lines as $line): ?>
    <tr>
      <td><?php echo htmlspecialchars($line['item_name']); ?></td>
      <td class="c"><?php echo (int) $line['quantity']; ?></td>
      <td class="r">₹<?php echo number_format((float) $line['unit_price']); ?></td>
      <td class="r">₹<?php echo number_format((float) $line['line_total']); ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<div class="totals">
  <?php if ($disc['amount'] > 0): ?>
  <div class="row"><span>Subtotal</span><span>₹<?php echo number_format($subtotal, 2); ?></span></div>
  <div class="row"><span>Discount<?php echo $disc['type'] === 'percent' ? ' (' . rtrim(rtrim(number_format($disc['value'], 2), '0'), '.') . '%)' : ''; ?></span><span>−₹<?php echo number_format($disc['amount'], 2); ?></span></div>
  <?php endif; ?>
  <div class="row grand"><span>Total</span><span>₹<?php echo number_format($total, 2); ?></span></div>
</div>
<div class="foot">THANK YOU · VISIT AGAIN</div>
<script>window.onload=function(){setTimeout(function(){window.print();},300);};</script>
</body>
</html>
    <?php
    exit;
}

// Delete table
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['delete_table'])) {
    $table_key = $_POST['delete_table'];

    $stmt = mysqli_prepare($conn, "
        SELECT COUNT(*) AS c FROM orders
        WHERE restro_key = ? AND table_key = ? AND status != 'completed'
    ");
    mysqli_stmt_bind_param($stmt, 'ss', $restro_key, $table_key);
    mysqli_stmt_execute($stmt);
    $busy = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];
    mysqli_stmt_close($stmt);

    if ($busy > 0) {
        $error = 'This table has an active order — complete or clear it before deleting.';
    } else {
        $del = mysqli_prepare($conn, "DELETE FROM restaurant_tables WHERE table_key = ? AND restro_key = ?");
        mysqli_stmt_bind_param($del, 'ss', $table_key, $restro_key);
        mysqli_stmt_execute($del);
        mysqli_stmt_close($del);
        header('Location: tables.php');
        exit;
    }
}

// Add or edit table
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['save_table'])) {
    $table_key = trim($_POST['table_key'] ?? '');
    $table_name = trim($_POST['table_name'] ?? '');
    $seats = (int) ($_POST['seats'] ?? 2);
    if ($seats < 1) $seats = 2;

    if ($table_name === '') {
        $error = 'Please name the table.';
    } else {
        if ($table_key === '') {
            $table_key = 'tbl_' . bin2hex(random_bytes(6));
            $ins = mysqli_prepare($conn, "
                INSERT INTO restaurant_tables (table_key, restro_key, table_name, seats)
                VALUES (?, ?, ?, ?)
            ");
            mysqli_stmt_bind_param($ins, 'sssi', $table_key, $restro_key, $table_name, $seats);
            $ok = mysqli_stmt_execute($ins);
            mysqli_stmt_close($ins);
            if (!$ok) {
                $error = 'Could not add table — that name may already exist.';
            } else {
                header('Location: tables.php');
                exit;
            }
        } else {
            $upd = mysqli_prepare($conn, "
                UPDATE restaurant_tables SET table_name = ?, seats = ?
                WHERE table_key = ? AND restro_key = ?
            ");
            mysqli_stmt_bind_param($upd, 'siss', $table_name, $seats, $table_key, $restro_key);
            $ok = mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);
            if (!$ok) {
                $error = 'Could not save changes — that name may already exist.';
            } else {
                header('Location: tables.php');
                exit;
            }
        }
    }
}

$show_add_modal = isset($_GET['add']);
$edit_key = trim($_GET['edit'] ?? '');
$qr_key = trim($_GET['qr'] ?? '');
$edit_table = null;
$qr_table = null;

if ($edit_key !== '') {
    $stmt = mysqli_prepare($conn, "SELECT table_key, table_name, seats FROM restaurant_tables WHERE table_key = ? AND restro_key = ?");
    mysqli_stmt_bind_param($stmt, 'ss', $edit_key, $restro_key);
    mysqli_stmt_execute($stmt);
    $edit_table = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if ($edit_table) $show_add_modal = true;
}

if ($qr_key !== '') {
    $stmt = mysqli_prepare($conn, "SELECT table_key, table_name FROM restaurant_tables WHERE table_key = ? AND restro_key = ?");
    mysqli_stmt_bind_param($stmt, 'ss', $qr_key, $restro_key);
    mysqli_stmt_execute($stmt);
    $qr_table = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
}

// Restaurant info
// Tables list
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
    SELECT DISTINCT table_key
    FROM orders
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

$next_num = count($tables) + 1;
$default_name = 'T-' . str_pad((string) $next_num, 2, '0', STR_PAD_LEFT);
$qr_url = $qr_table ? tableMenuUrl($qr_table['table_key'], $qr_table['table_name']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tables — RestroAI</title>
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
      <div><h1>Tables</h1><div class="sub">Floor status at a glance</div></div>
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
        <h2>Tables</h2>
        <div class="link"><?php echo count($tables); ?> tables</div>
      </div>
      <div class="table-grid">
        <?php foreach ($tables as $t):
          $busy = !empty($occupied[$t['table_key']]);
          $menu_url = tableMenuUrl($t['table_key'], $t['table_name']);
          $num_label = preg_replace('/^T-/', '', $t['table_name']);
        ?>
          <div class="table-card table-card-clickable<?php echo $busy ? ' occupied' : ''; ?>" onclick="location.href='<?php echo htmlspecialchars($menu_url, ENT_QUOTES); ?>'" role="button" tabindex="0">
            <div class="table-top">
              <div class="table-num"><?php echo htmlspecialchars($num_label); ?></div>
            </div>
            <div><h4><?php echo htmlspecialchars($t['table_name']); ?></h4><span class="seats"><?php echo (int) $t['seats']; ?> seats · Tap to open menu</span></div>
            <span class="table-status <?php echo $busy ? 'busy' : 'free'; ?>"><?php echo $busy ? 'Occupied' : 'Free'; ?></span>
            <div class="table-foot">
              <div class="dish-actions">
                <?php if ($busy): ?>
                  <a href="tables.php?checkout=<?php echo urlencode($t['table_key']); ?>&amp;return=<?php echo urlencode('tables.php'); ?>" class="advance-btn" onclick="event.stopPropagation();" title="Review bill, apply discount, settle">Bill</a>
                <?php endif; ?>
                <a href="tables.php?qr=<?php echo urlencode($t['table_key']); ?>" class="a" onclick="event.stopPropagation();" title="Print QR">QR</a>
                <a href="tables.php?edit=<?php echo urlencode($t['table_key']); ?>" class="a" onclick="event.stopPropagation();" title="Edit">✎</a>
                <form method="post" action="tables.php" style="display:inline;" onclick="event.stopPropagation();">
                  <input type="hidden" name="delete_table" value="<?php echo htmlspecialchars($t['table_key']); ?>">
                  <button type="submit" class="a" title="Delete" onclick="return confirm('Remove this table?');">✕</button>
                </form>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
        <a href="tables.php?add=1" class="table-card add-table-card"><span class="plus">+</span>Add table</a>
      </div>

    </div>
  </div>
</div>

<div class="modal-overlay<?php echo $show_add_modal ? ' open' : ''; ?>" id="tableModalOverlay">
  <div class="modal">
    <h3><?php echo $edit_table ? 'Edit Table' : 'Add Table'; ?></h3>
    <form method="post" action="tables.php">
      <input type="hidden" name="save_table" value="1">
      <input type="hidden" name="table_key" value="<?php echo htmlspecialchars($edit_table['table_key'] ?? ''); ?>">
      <div class="field-row">
        <div class="field">
          <label for="table_name">Table Name / Number</label>
          <input type="text" id="table_name" name="table_name" placeholder="e.g. T-16" required value="<?php echo htmlspecialchars($edit_table['table_name'] ?? ($_POST['table_name'] ?? $default_name)); ?>">
        </div>
        <div class="field">
          <label for="seats">Seats</label>
          <input type="number" id="seats" name="seats" placeholder="e.g. 4" min="1" required value="<?php echo (int) ($edit_table['seats'] ?? ($_POST['seats'] ?? 4)); ?>">
        </div>
      </div>
      <div class="modal-actions">
        <a href="tables.php" class="btn btn-ghost">Cancel</a>
        <button type="submit" class="btn btn-primary"><?php echo $edit_table ? 'Save Changes' : 'Add Table'; ?></button>
      </div>
    </form>
  </div>
</div>

<?php if ($qr_table): ?>
<div class="modal-overlay open" id="qrModalOverlay">
  <div class="modal" style="max-width:340px;text-align:center;">
    <h3>QR — <?php echo htmlspecialchars($qr_table['table_name']); ?></h3>
    <p style="font-size:12px;color:var(--text-mid);margin-bottom:14px;">Guests scan this to open the menu and order straight to this table.</p>
    <div id="qrCodeBox" style="background:#fff;padding:16px;border-radius:14px;display:inline-block;"></div>
    <div id="qrLinkText" style="font-family:var(--font-mono);font-size:10.5px;color:var(--text-low);word-break:break-all;margin:14px 0;"><?php echo htmlspecialchars($qr_url); ?></div>
    <div class="modal-actions" style="justify-content:center;">
      <a href="tables.php" class="btn btn-ghost">Close</a>
      <button type="button" class="btn btn-primary" id="printQrBtn">🖨 Print</button>
    </div>
  </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
  new QRCode(document.getElementById('qrCodeBox'), {text: <?php echo json_encode($qr_url); ?>, width: 200, height: 200, colorDark: '#111111', colorLight: '#ffffff'});
  document.getElementById('printQrBtn').addEventListener('click', function () {
    var url = <?php echo json_encode($qr_url); ?>;
    var tname = <?php echo json_encode($qr_table['table_name']); ?>;
    var w = window.open('', '_blank', 'width=420,height=560');
    if (!w) { alert('Please allow pop-ups to print this QR code.'); return; }
    w.document.write(
      '<html><head><title>' + tname + ' QR</title>' +
      '<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"><\/script>' +
      '<style>body{font-family:Arial,Helvetica,sans-serif;text-align:center;padding:50px 20px;color:#111;}' +
      'h1{font-size:22px;margin-bottom:4px;}p{color:#777;font-size:12px;margin-bottom:22px;}' +
      '#q{display:inline-block;padding:18px;border:1px solid #eee;border-radius:14px;}' +
      '.foot{margin-top:20px;font-size:11px;color:#aaa;letter-spacing:0.04em;}</style></head><body>' +
      '<h1>' + tname + '</h1><p>Scan to view the menu &amp; order</p><div id="q"></div>' +
      '<div class="foot">RESTROAI · SCAN TO ORDER</div>' +
      '<script>window.onload=function(){new QRCode(document.getElementById("q"),{text:' + JSON.stringify(url) + ',width:220,height:220});setTimeout(function(){window.print();},350);};<\/script>' +
      '</body></html>'
    );
    w.document.close();
  });
</script>
<?php endif; ?>

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
