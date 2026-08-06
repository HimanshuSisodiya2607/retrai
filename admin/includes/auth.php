<?php
/**
 * Super-admin session guard. Include at the top of every admin page.
 *
 * Uses $_SESSION['admin_key'] — deliberately distinct from the tenant
 * session's $_SESSION['restro_key'], so a restaurant login can never
 * satisfy this check, and so impersonation can hold both at once.
 *
 * Passwords are plaintext, matching the rest of the app.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../database/db.php';

if (empty($_SESSION['admin_key'])) {
    header('Location: sign-in.php');
    exit;
}

$admin_key = $_SESSION['admin_key'];
$admin_name = $_SESSION['admin_name'] ?? 'Admin';

function admin_initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    if (count($parts) >= 2) {
        return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
    }
    return strtoupper(mb_substr($name, 0, 2));
}

function admin_nav_active(string $page, string $current): string {
    return $page === $current ? ' active' : '';
}

function admin_money(float $n): string {
    return '₹' . number_format($n);
}

/** One prepared-statement round trip returning a single scalar. */
function admin_scalar(mysqli $conn, string $sql, array $params = [], string $types = '') {
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return 0;
    }
    if ($params) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_row(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ? $row[0] : 0;
}

/** Rows for a prepared query, as an array of assoc arrays. */
function admin_rows(mysqli $conn, string $sql, array $params = [], string $types = ''): array {
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return [];
    }
    if ($params) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    return $rows;
}
