<?php
// ============================================================
// table-request.php
// Customer-facing endpoint for the "Call Waiter" / "Ask for Bill"
// buttons on customer-menu.php. No staff login required — trusts
// the table_key the same way order placement already does (it
// comes from the table's QR code).
//
// GET  ?table_key=...                 -> current pending request types for that table
// POST table_key=..., type=waiter|bill -> creates a request (or returns the existing pending one)
// ============================================================
session_start();
require_once __DIR__ . '/../database/db.php';
header('Content-Type: application/json');

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function get_table_row($conn, $table_key) {
    $stmt = mysqli_prepare($conn, "
        SELECT table_key, restro_key, table_name
        FROM restaurant_tables
        WHERE table_key = ?
    ");
    mysqli_stmt_bind_param($stmt, 's', $table_key);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $table_key = trim($_GET['table_key'] ?? '');
    if ($table_key === '') respond(['ok' => false, 'error' => 'Missing table_key'], 400);

    $table = get_table_row($conn, $table_key);
    if (!$table) respond(['ok' => false, 'error' => 'Invalid table'], 404);

    $stmt = mysqli_prepare($conn, "
        SELECT type, request_key, status, created_at
        FROM table_requests
        WHERE table_key = ? AND status IN ('pending','acknowledged')
        ORDER BY created_at DESC
    ");
    mysqli_stmt_bind_param($stmt, 's', $table_key);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    $active = [];
    foreach ($rows as $r) {
        // keep only the most recent active request per type
        if (!isset($active[$r['type']])) {
            $active[$r['type']] = [
                'request_key' => $r['request_key'],
                'status' => $r['status'],
            ];
        }
    }

    respond(['ok' => true, 'active' => $active]);
}

if ($method === 'POST') {
    $table_key = trim($_POST['table_key'] ?? '');
    $type = trim($_POST['type'] ?? '');

    if ($table_key === '' || !in_array($type, ['waiter', 'bill'], true)) {
        respond(['ok' => false, 'error' => 'Invalid request'], 400);
    }

    $table = get_table_row($conn, $table_key);
    if (!$table) respond(['ok' => false, 'error' => 'Invalid table'], 404);

    // Don't spam duplicate requests — if one's already pending/acknowledged, return it as-is.
    $stmt = mysqli_prepare($conn, "
        SELECT request_key, status
        FROM table_requests
        WHERE table_key = ? AND type = ? AND status IN ('pending','acknowledged')
        ORDER BY created_at DESC
        LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt, 'ss', $table_key, $type);
    mysqli_stmt_execute($stmt);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if ($existing) {
        respond(['ok' => true, 'status' => $existing['status'], 'request_key' => $existing['request_key'], 'already' => true]);
    }

    $request_key = 'req_' . bin2hex(random_bytes(6));
    $ins = mysqli_prepare($conn, "
        INSERT INTO table_requests (request_key, restro_key, table_key, type, status)
        VALUES (?, ?, ?, ?, 'pending')
    ");
    mysqli_stmt_bind_param($ins, 'ssss', $request_key, $table['restro_key'], $table_key, $type);
    mysqli_stmt_execute($ins);
    mysqli_stmt_close($ins);

    respond(['ok' => true, 'status' => 'pending', 'request_key' => $request_key, 'already' => false]);
}

respond(['ok' => false, 'error' => 'Method not allowed'], 405);