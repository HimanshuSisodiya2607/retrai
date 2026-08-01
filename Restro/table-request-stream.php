<?php
/**
 * RestroAI — Customer-side SSE stream.
 * Pushes the live state of a table's "Call Waiter" / "Ask for Bill"
 * requests to customer-menu.php, replacing its 10s poll.
 *
 * GET ?table_key=...  (no login — trusts the QR code's table_key,
 * same as table-request.php and order placement already do)
 *
 * Every diner with the menu open holds a connection, so unlike the
 * staff stream this one caps its lifetime and lets EventSource
 * reconnect — that hands the Apache worker back periodically.
 */
require_once __DIR__ . '/../database/db.php';

if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', 1);
}
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
while (ob_get_level() > 0) {
    ob_end_flush();
}
@set_time_limit(0);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

$table_key = trim($_GET['table_key'] ?? '');
if ($table_key === '') {
    echo "event: error\n";
    echo "data: " . json_encode(['error' => 'Missing table_key']) . "\n\n";
    flush();
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT table_key FROM restaurant_tables WHERE table_key = ?");
mysqli_stmt_bind_param($stmt, 's', $table_key);
mysqli_stmt_execute($stmt);
$table = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$table) {
    echo "event: error\n";
    echo "data: " . json_encode(['error' => 'Invalid table']) . "\n\n";
    flush();
    exit;
}

echo "retry: 5000\n\n";
flush();

$sql = "
    SELECT type, request_key, status
    FROM table_requests
    WHERE table_key = ? AND status IN ('pending','acknowledged')
    ORDER BY created_at DESC
";

$lastHash = '';
$started = time();
$max_lifetime = 300; // Recycle the worker every 5 min; the browser reconnects.

while (!connection_aborted() && (time() - $started) < $max_lifetime) {
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 's', $table_key);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    $active = [];
    foreach ($rows as $r) {
        // Keep only the most recent active request per type.
        if (!isset($active[$r['type']])) {
            $active[$r['type']] = [
                'request_key' => $r['request_key'],
                'status' => $r['status'],
            ];
        }
    }

    $payload = ['ok' => true, 'active' => (object) $active];
    $hash = md5(json_encode($payload));
    if ($hash !== $lastHash) {
        echo "event: qa_state\n";
        echo "data: " . json_encode($payload) . "\n\n";
        $lastHash = $hash;
    }

    echo ": ping\n\n";
    flush();
    sleep(2);
}
