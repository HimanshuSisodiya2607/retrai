<?php
session_start();
unset($_SESSION['waiter_staff_key'], $_SESSION['waiter_restro_key'], $_SESSION['waiter_name'], $_SESSION['waiter_restaurant_name']);
header('Location: sign-in.php');
exit;
