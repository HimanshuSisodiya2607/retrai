<?php
session_start();
// Clear the whole session — this also drops any restaurant the admin
// was impersonating, so signing out never leaves a tenant session behind.
$_SESSION = [];
session_destroy();
header('Location: sign-in.php');
exit;
