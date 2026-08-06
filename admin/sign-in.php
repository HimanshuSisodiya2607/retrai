<?php
session_start();
require_once __DIR__ . '/../database/db.php';

if (!empty($_SESSION['admin_key'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter your email and password.';
    } else {
        $stmt = mysqli_prepare($conn, "
            SELECT admin_key, name
            FROM super_admins
            WHERE email = ? AND password = ? AND is_active = 1
        ");
        mysqli_stmt_bind_param($stmt, 'ss', $email, $password);
        mysqli_stmt_execute($stmt);
        $admin = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if ($admin) {
            session_regenerate_id(true);
            $_SESSION['admin_key'] = $admin['admin_key'];
            $_SESSION['admin_name'] = $admin['name'];

            $upd = mysqli_prepare($conn, "UPDATE super_admins SET last_login_at = NOW() WHERE admin_key = ?");
            mysqli_stmt_bind_param($upd, 's', $admin['admin_key']);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);

            header('Location: index.php');
            exit;
        }
        // Same message either way — don't reveal which accounts exist.
        $error = 'Invalid email or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Super Admin — RestroAI</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../Restro/assets/style.css?v=<?php echo @filemtime(__DIR__ . '/../Restro/assets/style.css'); ?>">
<link rel="stylesheet" href="assets/admin.css?v=<?php echo @filemtime(__DIR__ . '/assets/admin.css'); ?>">
<style>
.auth-wrap{max-width:400px;margin:0 auto;padding:70px 20px;position:relative;z-index:1;}
.auth-card{background:var(--bg-panel);border:1px solid var(--line);border-radius:20px;padding:32px 30px;}
.auth-logo{display:flex;align-items:center;gap:9px;font-family:var(--font-display);font-weight:700;font-size:19px;margin-bottom:6px;}
.auth-logo .logo-dot{width:9px;height:9px;border-radius:50%;background:var(--grad-ai);display:inline-block;}
.auth-sub{color:var(--text-mid);font-size:13px;margin-bottom:24px;}
.auth-field{margin-bottom:16px;}
.auth-field label{display:block;font-family:var(--font-mono);font-size:10px;letter-spacing:0.08em;text-transform:uppercase;color:var(--text-low);margin-bottom:7px;}
.auth-field input{width:100%;background:var(--bg);border:1px solid var(--line);border-radius:10px;color:var(--text-hi);padding:11px 13px;font-size:14px;font-family:inherit;}
.auth-field input:focus{outline:none;border-color:var(--ember);}
.auth-err{background:rgba(255,31,76,0.1);border:1px solid rgba(255,31,76,0.3);color:var(--signal);border-radius:10px;padding:10px 13px;font-size:12.5px;margin-bottom:18px;}
.auth-btn{width:100%;background:var(--grad-ai);color:#fff;border:none;border-radius:10px;padding:12px;font-size:14px;font-weight:600;font-family:inherit;cursor:pointer;margin-top:6px;}
.auth-foot{text-align:center;margin-top:20px;font-size:12px;color:var(--text-low);}
.auth-foot a{color:var(--text-mid);text-decoration:none;}
.auth-foot a:hover{color:var(--ember);}
</style>
</head>
<body>
<div id="bg-glow"></div>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-logo"><span class="logo-dot"></span>RestroAI<span class="logo-tag">ADMIN</span></div>
    <p class="auth-sub">Platform operator sign-in.</p>

    <?php if ($error !== ''): ?>
      <div class="auth-err"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="post" action="sign-in.php">
      <div class="auth-field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" required autocomplete="email"
               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
      </div>
      <div class="auth-field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">
      </div>
      <button type="submit" class="auth-btn">Sign in →</button>
    </form>

    <div class="auth-foot"><a href="../Restro/sign-in.php">Restaurant owner sign-in →</a></div>
  </div>
</div>
</body>
</html>
