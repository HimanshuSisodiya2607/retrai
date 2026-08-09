<?php
/**
 * Sign out of the restaurant dashboard.
 *
 * A super admin viewing a restaurant holds both admin_key and
 * restro_key in the same session. Destroying everything would sign
 * them out of the admin panel too, so in that case we only drop the
 * tenant half and hand them back to the admin side.
 */
session_start();

if (!empty($_SESSION['admin_key'])) {
    unset(
        $_SESSION['restro_key'],
        $_SESSION['restaurant_name'],
        $_SESSION['owner_name'],
        $_SESSION['impersonating'],
        $_SESSION['impersonating_name']
    );
    header('Location: ../admin/restaurants.php');
    exit;
}

$_SESSION = [];

// Also expire the session cookie itself, so nothing is left behind on
// a shared machine.
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}

session_destroy();
header('Location: sign-in.php');
exit;
