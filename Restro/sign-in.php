<?php
session_start();
require_once __DIR__ . '/../database/db.php';

$error = '';

if (!empty($_SESSION['restro_key'])) {
    header('Location: overview.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter your email and password.';
    } else {
        $sql = "
            SELECT restro_key, restaurant_name, owner_name
            FROM restaurants
            WHERE email = ? AND password = ? AND is_active = 1
        ";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'ss', $email, $password);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);

        if ($row) {
            $_SESSION['restro_key'] = $row['restro_key'];
            $_SESSION['restaurant_name'] = $row['restaurant_name'];
            $_SESSION['owner_name'] = $row['owner_name'];
            header('Location: overview.php');
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
<link rel="icon" type="image/png" href="../assets/logo-icon.png">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In — Dinetous</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=<?php echo @filemtime(__DIR__ . '/assets/style.css'); ?>">
</head>
<body class="auth-page">

<div id="bg-glow"></div>
<div class="auth-grain"></div>

<div class="auth-wrap">
  <a href="../index.html" class="auth-logo"><img src="../assets/logo.png" alt="Dinetous" class="logo-img"></a>

  <div class="auth-card">
    <div class="auth-card-head">
      <span class="auth-eyebrow">Owner Portal</span>
      <h1>Welcome back</h1>
      <p>Sign in to manage orders, menu, staff, and AI insights for your restaurant.</p>
    </div>

    <?php if ($error !== ''): ?>
      <p class="auth-error" style="color:#ff6b6b;font-size:13px;margin-bottom:16px;"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>

    <form method="post" action="sign-in.php">
      <div class="field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" placeholder="you@restaurant.com" autocomplete="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
      </div>
      <div class="field">
        <label for="password">Password</label>
        <div class="password-wrap">
          <input type="password" id="password" name="password" placeholder="••••••••" autocomplete="current-password" required>
          <button type="button" class="pw-toggle" id="pwToggle" aria-label="Show password">👁</button>
        </div>
      </div>

      <div class="auth-row">
        <label class="check-row">
          <input type="checkbox" id="remember" name="remember">
          Remember me
        </label>
        <a href="#" class="auth-link" onclick="alert('Password reset coming soon.'); return false;">Forgot password?</a>
      </div>

      <button type="submit" class="btn btn-primary auth-submit">Sign In →</button>
    </form>

    <div class="auth-divider"><span>or continue with</span></div>

    <div class="auth-social">
      <button type="button" class="btn btn-ghost auth-social-btn" onclick="alert('Google sign-in coming soon.')">
        <span class="social-ico">G</span> Google
      </button>
      <button type="button" class="btn btn-ghost auth-social-btn" onclick="alert('Apple sign-in coming soon.')">
        <span class="social-ico">&#63743;</span> Apple
      </button>
    </div>

    <p class="auth-foot">Don't have an account? <a href="../onboarding.php" class="auth-link">Create one</a></p>
  </div>
</div>

<script>
  document.getElementById('pwToggle').addEventListener('click', function () {
    var input = document.getElementById('password');
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    this.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  });
</script>

</body>
</html>
