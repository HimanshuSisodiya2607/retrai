<?php
/**
 * "Open as this restaurant" — grants the admin a normal tenant session
 * for the chosen restaurant while keeping admin_key in the session, so
 * the dashboard shows a banner and the admin can hand the session back.
 *
 * ?key=<restro_key>  start impersonating
 * ?stop=1            drop the tenant session, return to the admin panel
 */
require_once __DIR__ . '/includes/auth.php';

if (!empty($_GET['stop'])) {
    unset($_SESSION['restro_key'], $_SESSION['impersonating'], $_SESSION['impersonating_name']);
    header('Location: restaurants.php');
    exit;
}

$key = trim($_GET['key'] ?? '');
$row = null;
if ($key !== '') {
    $rows = admin_rows($conn, "SELECT restro_key, restaurant_name FROM restaurants WHERE restro_key = ?", [$key], 's');
    $row = $rows[0] ?? null;
}

if (!$row) {
    header('Location: restaurants.php');
    exit;
}

// Suspended restaurants are still openable — that's often exactly when
// support needs to look inside.
$_SESSION['restro_key'] = $row['restro_key'];
$_SESSION['impersonating'] = true;
$_SESSION['impersonating_name'] = $row['restaurant_name'];

header('Location: ../Restro/overview.php');
exit;
