<?php
/**
 * Topbar search — JSON results across this restaurant's orders,
 * dishes and tables. Scoped to the session's restro_key, so one
 * restaurant can never search another's data.
 *
 * GET ?q=pasta -> {ok:true, groups:[{label, items:[{title, sub, url}]}]}
 */
session_start();
require_once __DIR__ . '/../database/db.php';
header('Content-Type: application/json');

if (empty($_SESSION['restro_key'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    exit;
}

$restro_key = $_SESSION['restro_key'];
session_write_close();

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'groups' => [], 'q' => $q]);
    exit;
}

$like = '%' . $q . '%';
$groups = [];

/** Run a prepared search query and return its rows. */
function search_rows(mysqli $conn, string $sql, array $params, string $types): array {
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    return $rows;
}

// ---- dishes ----------------------------------------------------------
$dishes = search_rows($conn, "
    SELECT i.item_key, i.name, i.emoji, i.price, i.is_active, c.name AS category
    FROM items i
    LEFT JOIN categories c ON c.category_key = i.category_key
    WHERE i.restro_key = ? AND (i.name LIKE ? OR i.description LIKE ?)
    ORDER BY i.name
    LIMIT 6
", [$restro_key, $like, $like], 'sss');

if ($dishes) {
    $items = [];
    foreach ($dishes as $d) {
        $items[] = [
            'title' => ($d['emoji'] ? $d['emoji'] . ' ' : '') . $d['name'],
            'sub'   => trim(($d['category'] ?: 'Uncategorised') . ' · ₹' . number_format((float) $d['price']))
                       . ((int) $d['is_active'] === 1 ? '' : ' · hidden'),
            'url'   => 'menu.php?edit=' . urlencode($d['item_key']),
        ];
    }
    $groups[] = ['label' => 'Dishes', 'items' => $items];
}

// ---- orders ----------------------------------------------------------
$orders = search_rows($conn, "
    SELECT o.order_key, o.status, o.total_amount, o.items_summary, o.ordered_at, t.table_name
    FROM orders o
    LEFT JOIN restaurant_tables t ON t.table_key = o.table_key
    WHERE o.restro_key = ?
      AND (o.order_key LIKE ? OR o.items_summary LIKE ? OR t.table_name LIKE ?)
    ORDER BY o.ordered_at DESC
    LIMIT 6
", [$restro_key, $like, $like, $like], 'ssss');

if ($orders) {
    $items = [];
    foreach ($orders as $o) {
        $summary = $o['items_summary'] ?: '—';
        if (mb_strlen($summary) > 48) {
            $summary = mb_substr($summary, 0, 45) . '…';
        }
        $items[] = [
            'title' => ($o['table_name'] ?? 'Table') . ' · ₹' . number_format((float) $o['total_amount']),
            'sub'   => $summary . ' · ' . $o['status'] . ' · ' . date('j M, g:i A', strtotime($o['ordered_at'])),
            'url'   => 'orders.php?tab=' . ($o['status'] === 'completed' ? 'completed' : 'active'),
        ];
    }
    $groups[] = ['label' => 'Orders', 'items' => $items];
}

// ---- tables ----------------------------------------------------------
$tables = search_rows($conn, "
    SELECT table_key, table_name, seats
    FROM restaurant_tables
    WHERE restro_key = ? AND table_name LIKE ?
    ORDER BY table_name
    LIMIT 5
", [$restro_key, $like], 'ss');

if ($tables) {
    $items = [];
    foreach ($tables as $t) {
        $items[] = [
            'title' => $t['table_name'],
            'sub'   => (int) $t['seats'] . ' seats',
            'url'   => 'tables.php?checkout=' . urlencode($t['table_key']) . '&return=' . urlencode('tables.php'),
        ];
    }
    $groups[] = ['label' => 'Tables', 'items' => $items];
}

// ---- staff -----------------------------------------------------------
$staff = search_rows($conn, "
    SELECT name, role_title, department, status
    FROM staff
    WHERE restro_key = ? AND (name LIKE ? OR role_title LIKE ? OR department LIKE ?)
    ORDER BY name
    LIMIT 4
", [$restro_key, $like, $like, $like], 'ssss');

if ($staff) {
    $items = [];
    foreach ($staff as $s) {
        $items[] = [
            'title' => $s['name'],
            'sub'   => $s['role_title'] . ' · ' . $s['department'] . ' · ' . $s['status'],
            'url'   => 'staff.php',
        ];
    }
    $groups[] = ['label' => 'Staff', 'items' => $items];
}

echo json_encode(['ok' => true, 'q' => $q, 'groups' => $groups]);
