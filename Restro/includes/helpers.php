<?php

function restro_initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    if (count($parts) >= 2) {
        return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
    }
    return strtoupper(mb_substr($name, 0, 2));
}

function restro_load_nav(mysqli $conn, string $restro_key): array {
    $stmt = mysqli_prepare($conn, "
        SELECT restaurant_name
        FROM restaurants
        WHERE restro_key = ?
    ");
    mysqli_stmt_bind_param($stmt, 's', $restro_key);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    $restaurant_name = $row['restaurant_name'] ?? 'RestroAI';

    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM orders WHERE restro_key = ? AND status != 'completed'");
    mysqli_stmt_bind_param($stmt, 's', $restro_key);
    mysqli_stmt_execute($stmt);
    $nav_order_count = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM restaurant_tables WHERE restro_key = ?");
    mysqli_stmt_bind_param($stmt, 's', $restro_key);
    mysqli_stmt_execute($stmt);
    $nav_table_count = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];
    mysqli_stmt_close($stmt);

    return [
        'restaurant_name' => $restaurant_name,
        'owner_initials' => restro_initials($restaurant_name),
        'nav_order_count' => $nav_order_count,
        'nav_table_count' => $nav_table_count,
    ];
}

function restro_nav_active(string $page, string $current_page): string {
    return $page === $current_page ? ' active' : '';
}
