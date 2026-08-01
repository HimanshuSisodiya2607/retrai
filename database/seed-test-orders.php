<?php
/**
 * One-off script: insert 50 realistic test orders for the demo restaurant.
 * Usage: php database/seed-test-orders.php
 */
require_once __DIR__ . '/db.php';

$restro_key = 'rst_spicebazaar01';
$count = 50;

$tables = [];
$stmt = mysqli_prepare($conn, "SELECT table_key FROM restaurant_tables WHERE restro_key = ?");
mysqli_stmt_bind_param($stmt, 's', $restro_key);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
    $tables[] = $row['table_key'];
}
mysqli_stmt_close($stmt);

$items = [];
$stmt = mysqli_prepare($conn, "SELECT item_key, name, price FROM items WHERE restro_key = ? AND is_active = 1");
mysqli_stmt_bind_param($stmt, 's', $restro_key);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
    $items[] = $row;
}
mysqli_stmt_close($stmt);

if (count($tables) === 0 || count($items) === 0) {
    fwrite(STDERR, "No tables or menu items found for {$restro_key}\n");
    exit(1);
}

$statuses = ['completed', 'completed', 'completed', 'completed', 'completed', 'served', 'ready', 'kitchen', 'new'];
$hour_weights = [
    12 => 2, 13 => 3, 14 => 2, 15 => 1, 16 => 1,
    17 => 2, 18 => 3, 19 => 5, 20 => 6, 21 => 4, 22 => 2,
];

function weighted_hour(array $weights): int {
    $pool = [];
    foreach ($weights as $hour => $weight) {
        for ($i = 0; $i < $weight; $i++) {
            $pool[] = (int) $hour;
        }
    }
    return $pool[array_rand($pool)];
}

$order_ins = mysqli_prepare($conn, "
    INSERT INTO orders (order_key, restro_key, table_key, status, total_amount, items_summary, ordered_at)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");

$item_ins = mysqli_prepare($conn, "
    INSERT INTO order_items (order_key, restro_key, item_key, item_name, quantity, unit_price, line_total)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");

$created = 0;
for ($i = 0; $i < $count; $i++) {
    $order_key = 'ord_test_' . bin2hex(random_bytes(4));
    $table_key = $tables[array_rand($tables)];
    $status = $statuses[array_rand($statuses)];

    $line_count = random_int(1, 3);
    $picked = (array) array_rand($items, min($line_count, count($items)));
    if (!isset($picked[0])) {
        $picked = [$picked];
    }

    $lines = [];
    $total = 0.0;
    $summary_parts = [];

    foreach ($picked as $idx) {
        $item = $items[$idx];
        $qty = random_int(1, 2);
        $unit_price = (float) $item['price'];
        $line_total = $unit_price * $qty;
        $total += $line_total;
        $lines[] = [
            'item_key' => $item['item_key'],
            'item_name' => $item['name'],
            'quantity' => $qty,
            'unit_price' => $unit_price,
            'line_total' => $line_total,
        ];
        $summary_parts[] = $qty > 1 ? $item['name'] . ' ×' . $qty : $item['name'];
    }

    $days_ago = random_int(0, 29);
    $hour = weighted_hour($hour_weights);
    $minute = random_int(0, 59);
    $ordered_at = date('Y-m-d H:i:s', strtotime("-{$days_ago} days") + ($hour * 3600) + ($minute * 60));
    $items_summary = implode(', ', $summary_parts);

    mysqli_stmt_bind_param(
        $order_ins,
        'ssssdss',
        $order_key,
        $restro_key,
        $table_key,
        $status,
        $total,
        $items_summary,
        $ordered_at
    );
    mysqli_stmt_execute($order_ins);

    foreach ($lines as $line) {
        mysqli_stmt_bind_param(
            $item_ins,
            'ssssidd',
            $order_key,
            $restro_key,
            $line['item_key'],
            $line['item_name'],
            $line['quantity'],
            $line['unit_price'],
            $line['line_total']
        );
        mysqli_stmt_execute($item_ins);
    }

    $created++;
}

mysqli_stmt_close($order_ins);
mysqli_stmt_close($item_ins);

echo "Created {$created} test orders for {$restro_key}.\n";
