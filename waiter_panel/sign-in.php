<?php
session_start();
require_once __DIR__ . '/../database/db.php';

$error = '';

if (!empty($_SESSION['waiter_staff_key'])) {
    header('Location: tables.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter your email and password.';
    } else {
        $stmt = mysqli_prepare($conn, "
            SELECT s.staff_key, s.restro_key, s.name, r.restaurant_name
            FROM staff s
            JOIN restaurants r ON r.restro_key = s.restro_key
            WHERE s.email = ? AND s.password = ? AND s.status = 'active'
        ");
        mysqli_stmt_bind_param($stmt, 'ss', $email, $password);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if ($row) {
            $_SESSION['waiter_staff_key'] = $row['staff_key'];
            $_SESSION['waiter_restro_key'] = $row['restro_key'];
            $_SESSION['waiter_name'] = $row['name'];
            $_SESSION['waiter_restaurant_name'] = $row['restaurant_name'];
            header('Location: tables.php');
            exit;
        }
        $error = 'Invalid email or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Waiter Sign In — RestroAI</title>
<link rel="stylesheet" href="../Restro/assets/style.css">
</head>
<body class="auth-page">
<div id="bg-glow"></div>
<div class="auth-grain"></div>
<div class="auth-wrap">
  <a href="../index.html" class="auth-logo"><span class="logo-dot"></span>RestroAI</a>
  <div class="auth-card">
    <div class="auth-card-head">
      <span class="auth-eyebrow">Waiter Panel</span>
      <h1>Sign in</h1>
      <p>Take orders table by table.</p>
    </div>
    <?php if ($error !== ''): ?>
      <p style="color:#ff6b6b;font-size:13px;margin-bottom:16px;"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>
    <form method="post">
      <div class="field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>
      </div>
      <button type="submit" class="btn btn-primary auth-submit">Sign In →</button>
    </form>
  </div>
</div>
</body>
</html>
