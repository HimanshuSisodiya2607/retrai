<?php
/**
 * Dinetous — Server-Sent Events (SSE) Live Stream Endpoint
 * Pushes real-time order updates and table requests to the dashboard.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../database/db.php';

// Prevent buffering & set SSE event-stream header
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', 1);
}
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
while (ob_get_level() > 0) {
    ob_end_flush();
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Disables Nginx buffering if used

// Authenticate session
if (empty($_SESSION['restro_key'])) {
    echo "event: error\n";
    echo "data: " . json_encode(['error' => 'Unauthorized']) . "\n\n";
    flush();
    exit;
}

$restro_key = $_SESSION['restro_key'];

// CRITICAL: Close session write lock so other requests (e.g., POSTing new orders, updating status) are not blocked!
session_write_close();

// Send initial retry interval hint to browser (reconnect every 3 seconds if disconnected)
echo "retry: 3000\n\n";
flush();

$lastOrdersHash = '';
$lastRequestsHash = '';

// Main streaming loop
while (!connection_aborted()) {
    // -------------------------------------------------------------------------
    // 1. FETCH LIVE ORDERS DATA
    // -------------------------------------------------------------------------
    $sqlActive = "
        SELECT o.order_key, o.table_key, t.table_name, o.status, o.total_amount,
               o.items_summary, o.ordered_at
        FROM orders o
        JOIN restaurant_tables t ON t.table_key = o.table_key
        WHERE o.restro_key = ? AND o.status != 'completed'
        ORDER BY o.ordered_at DESC
        LIMIT 100
    ";
    $stmt = mysqli_prepare($conn, $sqlActive);
    mysqli_stmt_bind_param($stmt, 's', $restro_key);
    mysqli_stmt_execute($stmt);
    $activeOrders = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    // Nav badge count
    $stmtCount = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM orders WHERE restro_key = ? AND status != 'completed'");
    mysqli_stmt_bind_param($stmtCount, 's', $restro_key);
    mysqli_stmt_execute($stmtCount);
    $navCount = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmtCount))['c'];
    mysqli_stmt_close($stmtCount);

    // Revenue stats today
    $stmtStats = mysqli_prepare($conn, "
        SELECT COALESCE(SUM(total_amount), 0) AS revenue, COUNT(*) AS orders
        FROM orders
        WHERE restro_key = ? AND DATE(ordered_at) = CURDATE()
    ");
    mysqli_stmt_bind_param($stmtStats, 's', $restro_key);
    mysqli_stmt_execute($stmtStats);
    $statsRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtStats));
    mysqli_stmt_close($stmtStats);

    $ordersToday = (int) $statsRow['orders'];
    $revenue = (float) $statsRow['revenue'];

    $ordersPayload = [
        'tab' => 'active',
        'orders' => $activeOrders,
        'nav_count' => $navCount,
        'stats' => [
            'revenue' => $revenue,
            'orders' => $ordersToday,
            'avg' => $ordersToday > 0 ? (int) round($revenue / $ordersToday) : 0,
        ],
    ];

    $currentOrdersHash = md5(json_encode($ordersPayload));
    if ($currentOrdersHash !== $lastOrdersHash) {
        echo "event: orders_update\n";
        echo "data: " . json_encode($ordersPayload) . "\n\n";
        $lastOrdersHash = $currentOrdersHash;
    }

    // -------------------------------------------------------------------------
    // 2. FETCH TABLE ASSISTANCE REQUESTS
    // -------------------------------------------------------------------------
    $sqlRequests = "
        SELECT r.request_key, r.type, r.status, r.created_at, r.table_key, t.table_name,
               GREATEST(0, TIMESTAMPDIFF(SECOND, r.created_at, NOW())) AS seconds_ago
        FROM table_requests r
        JOIN restaurant_tables t ON t.table_key COLLATE utf8mb4_general_ci = r.table_key COLLATE utf8mb4_general_ci
        WHERE r.restro_key = ? AND r.status IN ('pending','acknowledged')
        ORDER BY r.created_at DESC
        LIMIT 30
    ";
    $stmtReq = mysqli_prepare($conn, $sqlRequests);
    if ($stmtReq) {
        mysqli_stmt_bind_param($stmtReq, 's', $restro_key);
        mysqli_stmt_execute($stmtReq);
        $tableRequests = mysqli_fetch_all(mysqli_stmt_get_result($stmtReq), MYSQLI_ASSOC);
        mysqli_stmt_close($stmtReq);
    } else {
        $tableRequests = [];
    }

    $requestsPayload = [
        'ok' => true,
        'requests' => $tableRequests,
    ];

    $currentRequestsHash = md5(json_encode($requestsPayload));
    if ($currentRequestsHash !== $lastRequestsHash) {
        echo "event: table_requests_update\n";
        echo "data: " . json_encode($requestsPayload) . "\n\n";
        $lastRequestsHash = $currentRequestsHash;
    }

    // Send heartbeat ping every loop to keep connection open & flush buffer
    echo ": ping\n\n";
    flush();

    // Sleep 1 second before next check loop
    sleep(1);
}
