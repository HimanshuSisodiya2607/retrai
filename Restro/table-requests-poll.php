<?php
// ============================================================
// table-requests-poll.php
// Staff-side counterpart to table-request.php. Backs the request
// panel on overview.php — the SSE stream pushes updates, this is
// the initial load, the no-SSE fallback, and the resolve action.
//
// GET                          -> active requests for the logged-in restaurant
// POST resolve_request=<key>   -> marks that request resolved
// ============================================================
session_start();
require_once __DIR__ . '/../database/db.php';
header('Content-Type: application/json');

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if (empty($_SESSION['restro_key'])) {
    respond(['ok' => false, 'error' => 'Not logged in'], 401);
}

$restro_key = $_SESSION['restro_key'];
session_write_close();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $request_key = trim($_POST['resolve_request'] ?? '');
    if ($request_key === '') {
        respond(['ok' => false, 'error' => 'Missing request_key'], 400);
    }

    $stmt = mysqli_prepare($conn, "
        UPDATE table_requests
        SET status = 'resolved', resolved_at = NOW()
        WHERE request_key = ? AND restro_key = ? AND status IN ('pending','acknowledged')
    ");
    mysqli_stmt_bind_param($stmt, 'ss', $request_key, $restro_key);
    mysqli_stmt_execute($stmt);
    $changed = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    if ($changed === 0) {
        // Nothing updated: either another device already cleared it, or the key
        // isn't ours. Only the first is a success.
        $check = mysqli_prepare($conn, "
            SELECT status FROM table_requests
            WHERE request_key = ? AND restro_key = ?
        ");
        mysqli_stmt_bind_param($check, 'ss', $request_key, $restro_key);
        mysqli_stmt_execute($check);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($check));
        mysqli_stmt_close($check);

        if (!$row) {
            respond(['ok' => false, 'error' => 'Unknown request'], 404);
        }
        respond(['ok' => true, 'request_key' => $request_key, 'already' => true]);
    }

    respond(['ok' => true, 'request_key' => $request_key, 'already' => false]);
}

if ($method === 'GET') {
    // table_requests is utf8mb4_general_ci while restaurant_tables is
    // utf8mb4_unicode_ci, so the join needs an explicit collation.
    $stmt = mysqli_prepare($conn, "
        SELECT r.request_key, r.type, r.status, r.created_at, r.table_key, t.table_name,
               GREATEST(0, TIMESTAMPDIFF(SECOND, r.created_at, NOW())) AS seconds_ago
        FROM table_requests r
        JOIN restaurant_tables t ON t.table_key COLLATE utf8mb4_general_ci = r.table_key COLLATE utf8mb4_general_ci
        WHERE r.restro_key = ? AND r.status IN ('pending','acknowledged')
        ORDER BY r.created_at DESC
        LIMIT 30
    ");
    mysqli_stmt_bind_param($stmt, 's', $restro_key);
    mysqli_stmt_execute($stmt);
    $requests = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    foreach ($requests as &$r) {
        $r['seconds_ago'] = (int) $r['seconds_ago'];
    }
    unset($r);

    respond(['ok' => true, 'requests' => $requests]);
}

respond(['ok' => false, 'error' => 'Method not allowed'], 405);
