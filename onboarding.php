<?php
session_start();
require_once __DIR__ . '/database/db.php';
require_once __DIR__ . '/includes/directory.php';

require_once __DIR__ . '/includes/razorpay.php';

$error = '';
$stage = 'form';          // 'form' | 'pay'
$checkout = null;         // data handed to Razorpay Checkout

/**
 * Create the restaurant, its tables and its session. Called only after a
 * payment has been verified.
 */
function complete_signup(mysqli $conn, array $d): string {
    $restro_key = 'rst_' . bin2hex(random_bytes(6));
    $phone_db = $d['phone'] !== '' ? $d['phone'] : null;
    $cuisine_db = $d['cuisine'] !== '' ? $d['cuisine'] : null;
    $city_db = $d['city'] !== '' ? $d['city'] : null;

    $ins = mysqli_prepare($conn, "
        INSERT INTO restaurants (
            restro_key, restaurant_name, owner_name, email, password,
            phone, cuisine, city, plan_name, billing_cycle
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    mysqli_stmt_bind_param(
        $ins, 'ssssssssss',
        $restro_key, $d['restaurant_name'], $d['owner_name'], $d['email'], $d['password'],
        $phone_db, $cuisine_db, $city_db, $d['plan_name'], $d['billing_cycle']
    );
    mysqli_stmt_execute($ins);
    mysqli_stmt_close($ins);

    sync_restaurant_directory_fields($conn, $restro_key);

    if ($d['table_count'] > 0) {
        $tbl_ins = mysqli_prepare($conn, "
            INSERT INTO restaurant_tables (table_key, restro_key, table_name, seats)
            VALUES (?, ?, ?, 4)
        ");
        for ($i = 1; $i <= $d['table_count']; $i++) {
            $table_key = 'tbl_' . bin2hex(random_bytes(6));
            $table_name = 'T-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            mysqli_stmt_bind_param($tbl_ins, 'sss', $table_key, $restro_key, $table_name);
            mysqli_stmt_execute($tbl_ins);
        }
        mysqli_stmt_close($tbl_ins);
    }

    $_SESSION['restro_key'] = $restro_key;
    $_SESSION['restaurant_name'] = $d['restaurant_name'];
    $_SESSION['owner_name'] = $d['owner_name'];
    return $restro_key;
}

// ---------------------------------------------------------------------
// Step 2 — payment came back from Razorpay Checkout.
// Nothing here is trusted until the signature verifies.
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['verify_payment'])) {
    $pending = $_SESSION['pending_signup'] ?? null;
    $order_id = trim($_POST['razorpay_order_id'] ?? '');
    $payment_id = trim($_POST['razorpay_payment_id'] ?? '');
    $signature = trim($_POST['razorpay_signature'] ?? '');

    if (!$pending) {
        $error = 'Your signup session expired. Please fill the form again.';
    } elseif ($order_id === '' || $order_id !== $pending['order_id']) {
        // Guards against replaying someone else's paid order.
        $error = 'Payment could not be matched to your signup. Nothing was charged twice — please try again.';
    } elseif (!razorpay_verify_signature($order_id, $payment_id, $signature)) {
        $error = 'We could not verify this payment. If money was deducted it will be refunded automatically.';
        $upd = mysqli_prepare($conn, "UPDATE payments SET status = 'failed' WHERE razorpay_order_id = ?");
        mysqli_stmt_bind_param($upd, 's', $order_id);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);
    } else {
        // Signature is good. Confirm with Razorpay that it really was
        // captured for the amount we asked for.
        $check = razorpay_fetch_payment($payment_id);
        $paid_ok = $check['ok']
            && in_array($check['payment']['status'] ?? '', ['captured', 'authorized'], true)
            && (int) ($check['payment']['amount'] ?? 0) === SIGNUP_FEE_PAISE;

        if (!$paid_ok) {
            $error = $check['ok']
                ? 'That payment was not completed. Please try again.'
                : $check['error'];
        } else {
            // Guard the rare case where the email was taken while paying.
            $chk = mysqli_prepare($conn, "SELECT id FROM restaurants WHERE email = ?");
            mysqli_stmt_bind_param($chk, 's', $pending['email']);
            mysqli_stmt_execute($chk);
            $taken = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
            mysqli_stmt_close($chk);

            if ($taken) {
                $error = 'An account with this email was created in the meantime. Please sign in.';
            } else {
                $restro_key = complete_signup($conn, $pending);

                $upd = mysqli_prepare($conn, "
                    UPDATE payments
                    SET status = 'paid', razorpay_payment_id = ?, restro_key = ?, paid_at = NOW()
                    WHERE razorpay_order_id = ?
                ");
                mysqli_stmt_bind_param($upd, 'sss', $payment_id, $restro_key, $order_id);
                mysqli_stmt_execute($upd);
                mysqli_stmt_close($upd);

                unset($_SESSION['pending_signup']);
                header('Location: Restro/overview.php');
                exit;
            }
        }
    }
}

// ---------------------------------------------------------------------
// Step 1 — validate the form, then open checkout.
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['register'])) {
    $restaurant_name = trim($_POST['restaurant_name'] ?? '');
    $owner_name = trim($_POST['owner_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $cuisine = trim($_POST['cuisine'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $table_count = max(0, (int) ($_POST['tables'] ?? 0));
    $plan_name = trim($_POST['plan_name'] ?? 'Growth');
    $billing_cycle = ($_POST['billing_cycle'] ?? 'monthly') === 'annual' ? 'annual' : 'monthly';

    if ($restaurant_name === '' || $owner_name === '' || $email === '') {
        $error = 'Please fill in restaurant name, owner name, and email.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        $chk = mysqli_prepare($conn, "SELECT id FROM restaurants WHERE email = ?");
        mysqli_stmt_bind_param($chk, 's', $email);
        mysqli_stmt_execute($chk);
        $exists = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
        mysqli_stmt_close($chk);

        if ($exists) {
            $error = 'An account with this email already exists. Please sign in instead.';
        } elseif (!razorpay_configured()) {
            $error = 'Payments are not configured on this server. Please contact support.';
        } else {
            // Everything the account needs, held server-side. The browser
            // never gets to change the plan or the price after this point.
            $pending = [
                'restaurant_name' => $restaurant_name,
                'owner_name'      => $owner_name,
                'email'           => $email,
                'password'        => $password,
                'phone'           => $phone,
                'cuisine'         => $cuisine,
                'city'            => $city,
                'table_count'     => $table_count,
                'plan_name'       => $plan_name,
                'billing_cycle'   => $billing_cycle,
            ];

            $receipt = 'signup_' . bin2hex(random_bytes(6));
            $created = razorpay_create_order(SIGNUP_FEE_PAISE, $receipt, [
                'purpose'    => 'Dinetous signup',
                'restaurant' => $restaurant_name,
                'email'      => $email,
            ]);

            if (!$created['ok']) {
                $error = $created['error'];
            } else {
                $order = $created['order'];
                $pending['order_id'] = $order['id'];
                $_SESSION['pending_signup'] = $pending;

                $payment_key = 'pay_' . bin2hex(random_bytes(6));
                $amount = SIGNUP_FEE_PAISE;
                $ins = mysqli_prepare($conn, "
                    INSERT INTO payments (payment_key, email, razorpay_order_id, amount, currency, purpose, status)
                    VALUES (?, ?, ?, ?, 'INR', 'signup', 'created')
                ");
                mysqli_stmt_bind_param($ins, 'sssi', $payment_key, $email, $order['id'], $amount);
                mysqli_stmt_execute($ins);
                mysqli_stmt_close($ins);

                $stage = 'pay';
                $checkout = [
                    'key'      => razorpay_key_id(),
                    'amount'   => $order['amount'],
                    'currency' => $order['currency'],
                    'order_id' => $order['id'],
                    'name'     => $restaurant_name,
                    'owner'    => $owner_name,
                    'email'    => $email,
                    'phone'    => $phone,
                    'plan'     => $plan_name,
                ];
            }
        }
    }
}

$post = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/png" href="assets/logo-icon.png">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Start Modernizing — Dinetous</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root{
    --bg:#050505;
    --ember:#ff5a1f;
    --signal:#ff1f4c;
    --amber:#ffb347;
    --line:rgba(255,255,255,0.08);
    --glass:rgba(255,255,255,0.035);
    --text-hi:#f4f2ef;
    --text-mid:#a8a6a2;
    --text-low:#69675f;
    --grad-ai: linear-gradient(135deg, var(--ember) 0%, var(--signal) 100%);
    --font-display:'Space Grotesk', sans-serif;
    --font-body:'Inter', sans-serif;
    --font-mono:'IBM Plex Mono', monospace;
  }

  *{margin:0;padding:0;box-sizing:border-box;}
  html{scroll-behavior:smooth;}
  body{
    background:var(--bg);color:var(--text-hi);font-family:var(--font-body);
    overflow-x:hidden;line-height:1.5;-webkit-font-smoothing:antialiased;min-height:100vh;
  }
  ::selection{background:var(--ember);color:#050505;}
  a{color:inherit;text-decoration:none;}
  h1,h2,h3{font-family:var(--font-display);letter-spacing:-0.02em;font-weight:600;}
  .wrap{max-width:1280px;margin:0 auto;padding:0 40px;}
  @media(max-width:768px){.wrap{padding:0 22px;}}

  #bg-canvas{
    position:fixed;inset:0;z-index:0;pointer-events:none;
    background:
      radial-gradient(ellipse 900px 600px at 10% -5%, rgba(255,90,31,0.10), transparent 60%),
      radial-gradient(ellipse 800px 700px at 100% 15%, rgba(255,31,76,0.09), transparent 55%),
      radial-gradient(ellipse 700px 500px at 50% 100%, rgba(255,90,31,0.06), transparent 60%);
  }
  .grain{
    position:fixed;inset:0;z-index:1;pointer-events:none;opacity:0.035;mix-blend-mode:overlay;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='100' height='100'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
  }
  .cursor-light{
    position:fixed;width:480px;height:480px;border-radius:50%;
    background:radial-gradient(circle, rgba(255,90,31,0.09), transparent 70%);
    pointer-events:none;z-index:2;transform:translate(-50%,-50%);will-change:transform;
  }

  section, header{position:relative;z-index:3;}

  header{
    padding:22px 0;border-bottom:1px solid var(--line);
    backdrop-filter:blur(16px) saturate(140%);background:rgba(5,5,5,0.6);
  }
  nav{display:flex;align-items:center;justify-content:space-between;}
  .logo{font-family:var(--font-display);font-weight:700;font-size:20px;letter-spacing:-0.02em;display:flex;align-items:center;gap:9px;}
  .logo-dot{width:8px;height:8px;border-radius:50%;background:var(--grad-ai);box-shadow:0 0 14px var(--ember);}
  .back-link{font-size:13px;color:var(--text-mid);display:flex;align-items:center;gap:8px;transition:color .25s ease;}
  .back-link:hover{color:var(--text-hi);}

  .eyebrow{
    font-family:var(--font-mono);font-size:12px;letter-spacing:0.14em;text-transform:uppercase;
    color:var(--ember);display:inline-flex;align-items:center;gap:8px;margin-bottom:16px;
  }
  .eyebrow::before{content:'';width:6px;height:6px;border-radius:50%;background:var(--ember);box-shadow:0 0 8px var(--ember);}

  /* ---------- layout ---------- */
  .onboard-shell{padding:64px 0 100px;min-height:calc(100vh - 84px);display:flex;align-items:flex-start;}
  .onboard-grid{display:grid;grid-template-columns:1.1fr 0.9fr;gap:56px;align-items:flex-start;width:100%;}
  @media(max-width:960px){.onboard-grid{grid-template-columns:1fr;}}

  /* ---------- stepper ---------- */
  .stepper{display:flex;align-items:center;margin-bottom:38px;max-width:520px;}
  .step-node{
    width:34px;height:34px;border-radius:10px;flex-shrink:0;
    background:var(--glass);border:1px solid var(--line);
    display:flex;align-items:center;justify-content:center;
    font-family:var(--font-mono);font-size:12px;color:var(--text-low);
    transition:border-color .4s ease, color .4s ease, box-shadow .4s ease, background .4s ease;
  }
  .step-node.active{border-color:var(--ember);color:var(--ember);box-shadow:0 0 18px -4px rgba(255,90,31,0.6);}
  .step-node.done{background:var(--grad-ai);border-color:transparent;color:#0a0500;}
  .step-connector{flex:1;height:2px;background:var(--line);margin:0 8px;position:relative;overflow:hidden;}
  .step-connector i{position:absolute;inset:0;background:var(--grad-ai);width:0%;transition:width .5s cubic-bezier(.16,1,.3,1);}
  .step-labels{display:flex;justify-content:space-between;max-width:520px;margin-top:8px;font-family:var(--font-mono);font-size:10.5px;color:var(--text-low);text-transform:uppercase;letter-spacing:0.05em;}

  /* ---------- form panel ---------- */
  .panel{
    background:linear-gradient(155deg, rgba(255,255,255,0.045), rgba(255,255,255,0.012));
    border:1px solid rgba(255,255,255,0.09);border-radius:22px;
    padding:40px;backdrop-filter:blur(20px) saturate(150%);
    box-shadow:0 40px 90px -40px rgba(0,0,0,0.8), inset 0 1px 0 rgba(255,255,255,0.06);
  }
  @media(max-width:600px){.panel{padding:28px 22px;}}

  .panel h2{font-size:26px;margin-bottom:8px;}
  .panel .step-sub{color:var(--text-mid);font-size:14.5px;margin-bottom:32px;}

  .field-row{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px;}
  @media(max-width:560px){.field-row{grid-template-columns:1fr;}}
  .field{display:flex;flex-direction:column;gap:8px;margin-bottom:18px;}
  .field label{font-size:12.5px;color:var(--text-mid);font-family:var(--font-mono);text-transform:uppercase;letter-spacing:0.05em;}
  .field input, .field select{
    background:rgba(255,255,255,0.03);border:1px solid var(--line);border-radius:10px;
    padding:13px 14px;color:var(--text-hi);font-family:var(--font-body);font-size:14.5px;
    transition:border-color .25s ease, background .25s ease;
  }
  .field input::placeholder{color:var(--text-low);}
  .field input:focus, .field select:focus{outline:none;border-color:var(--ember);background:rgba(255,90,31,0.05);}
  .password-wrap{position:relative;}
  .password-wrap input{padding-right:42px;}
  .pw-toggle{
    position:absolute;right:10px;top:50%;transform:translateY(-50%);
    width:30px;height:30px;border-radius:8px;border:none;background:none;
    color:var(--text-low);font-size:14px;cursor:pointer;
  }
  .pw-toggle:hover{color:var(--text-mid);}
  .field select{appearance:none;background-image:linear-gradient(45deg, transparent 50%, var(--text-mid) 50%), linear-gradient(135deg, var(--text-mid) 50%, transparent 50%);background-position:calc(100% - 18px) center, calc(100% - 13px) center;background-size:5px 5px, 5px 5px;background-repeat:no-repeat;}

  .plan-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:10px;}
  @media(max-width:700px){.plan-grid{grid-template-columns:1fr;}}
  .plan-card{
    border:1px solid var(--line);border-radius:16px;padding:20px 18px;cursor:pointer;
    background:rgba(255,255,255,0.02);transition:border-color .25s ease, background .25s ease, transform .25s ease;
    position:relative;
  }
  .plan-card:hover{transform:translateY(-2px);}
  .plan-card.selected{border-color:var(--ember);background:rgba(255,90,31,0.06);box-shadow:0 0 24px -8px rgba(255,90,31,0.5);}
  .plan-card .plan-name{font-family:var(--font-display);font-size:16px;font-weight:600;}
  .plan-card .plan-price{font-family:var(--font-mono);color:var(--ember);font-size:13px;margin-top:6px;}
  .plan-card ul{margin-top:14px;padding-left:0;list-style:none;display:flex;flex-direction:column;gap:6px;}
  .plan-card li{font-size:12px;color:var(--text-mid);}
  .plan-card .badge{position:absolute;top:-10px;right:14px;font-family:var(--font-mono);font-size:9.5px;background:var(--grad-ai);color:#0a0500;padding:3px 8px;border-radius:100px;letter-spacing:0.05em;}
  .plan-check{position:absolute;top:16px;right:16px;width:18px;height:18px;border-radius:50%;border:1px solid var(--line);display:flex;align-items:center;justify-content:center;font-size:10px;transition:all .25s ease;}
  .plan-card.selected .plan-check{background:var(--grad-ai);border-color:transparent;color:#0a0500;}

  .review-box{border:1px solid var(--line);border-radius:14px;padding:20px;background:rgba(255,255,255,0.02);margin-bottom:14px;}
  .review-row{display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid rgba(255,255,255,0.06);font-size:13.5px;}
  .review-row:last-child{border-bottom:none;}
  .review-row .k{color:var(--text-mid);}
  .review-row .v{font-family:var(--font-mono);color:var(--text-hi);}
  .review-row.total-row{border-top:1px solid var(--line);margin-top:4px;padding-top:14px;}
  .review-row.total-row .k{color:var(--text-hi);font-weight:600;}
  .review-row.total-row .v{color:var(--ember);font-size:15px;}

  .billing-toggle{display:inline-flex;border:1px solid var(--line);border-radius:100px;padding:4px;margin-bottom:22px;background:rgba(255,255,255,0.02);}
  .cycle-btn{
    font-family:var(--font-body);font-size:13px;font-weight:600;color:var(--text-mid);
    background:none;border:none;padding:9px 18px;border-radius:100px;cursor:pointer;
    display:flex;align-items:center;gap:8px;transition:all .25s ease;
  }
  .cycle-btn.active{background:var(--grad-ai);color:#0a0500;}
  .save-tag{font-family:var(--font-mono);font-size:10px;background:rgba(255,255,255,0.15);padding:2px 7px;border-radius:100px;}
  .cycle-btn.active .save-tag{background:rgba(10,5,0,0.25);}

  .order-summary{border:1px solid rgba(255,90,31,0.2);border-radius:14px;padding:18px 20px;background:rgba(255,90,31,0.04);margin-bottom:6px;}

  .secure-note{margin-top:18px;font-family:var(--font-mono);font-size:11.5px;color:var(--text-low);display:flex;align-items:center;gap:8px;}

  .btn{
    font-family:var(--font-body);font-weight:600;font-size:14.5px;
    padding:13px 24px;border-radius:100px;border:1px solid var(--line);
    display:inline-flex;align-items:center;gap:8px;cursor:pointer;
    transition:transform .25s cubic-bezier(.16,1,.3,1), border-color .25s ease, box-shadow .25s ease;
  }
  .btn-primary{background:var(--grad-ai);color:#0a0500;border:none;}
  .btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 30px -6px rgba(255,90,31,0.55);}
  .btn-ghost{color:var(--text-hi);background:rgba(255,255,255,0.02);}
  .btn-ghost:hover{border-color:rgba(255,255,255,0.25);}
  .btn:disabled{opacity:0.4;cursor:not-allowed;transform:none !important;box-shadow:none !important;}

  .form-actions{display:flex;justify-content:space-between;align-items:center;margin-top:30px;}

  .step-panel{display:none;}
  .step-panel.active{display:block;animation:step-in .5s cubic-bezier(.16,1,.3,1);}
  @keyframes step-in{from{opacity:0;transform:translateY(14px);}to{opacity:1;transform:translateY(0);}}

  /* ---------- success ---------- */
  .success-view{display:none;text-align:center;padding:30px 10px;}
  .success-view.active{display:block;animation:step-in .6s cubic-bezier(.16,1,.3,1);}
  .success-ring{
    width:74px;height:74px;border-radius:50%;margin:0 auto 26px;
    background:var(--grad-ai);display:flex;align-items:center;justify-content:center;
    font-size:30px;color:#0a0500;box-shadow:0 0 50px -8px rgba(255,90,31,0.7);
  }
  .success-view h2{font-size:26px;margin-bottom:12px;}
  .success-view p{color:var(--text-mid);font-size:14.5px;max-width:400px;margin:0 auto 30px;}

  /* ---------- live preview ---------- */
  .preview-stage{position:sticky;top:110px;}
  .preview-card{
    background:linear-gradient(155deg, rgba(255,255,255,0.055), rgba(255,255,255,0.015));
    border:1px solid rgba(255,255,255,0.09);border-radius:22px;padding:26px;
    backdrop-filter:blur(22px) saturate(160%);
    box-shadow:0 40px 90px -30px rgba(0,0,0,0.8), 0 0 60px -25px rgba(255,90,31,0.35), inset 0 1px 0 rgba(255,255,255,0.08);
    animation:float 7s ease-in-out infinite;
  }
  @keyframes float{0%,100%{transform:translateY(0);}50%{transform:translateY(-12px);}}
  @media(max-width:960px){.preview-stage{position:static;margin-top:12px;}.preview-card{animation:none;}}

  .preview-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;}
  .preview-head .label{font-family:var(--font-mono);font-size:11px;color:var(--text-low);text-transform:uppercase;letter-spacing:0.08em;}
  .preview-name{font-family:var(--font-display);font-size:22px;font-weight:600;margin-top:6px;}
  .building-pill{
    font-family:var(--font-mono);font-size:10px;color:var(--ember);background:rgba(255,90,31,0.1);
    border:1px solid rgba(255,90,31,0.3);padding:5px 10px;border-radius:100px;display:flex;align-items:center;gap:6px;flex-shrink:0;
  }
  .building-pill::before{content:'';width:6px;height:6px;border-radius:50%;background:var(--ember);animation:pulse-dot 1.6s ease-in-out infinite;}
  @keyframes pulse-dot{0%,100%{opacity:1;}50%{opacity:0.25;}}

  .preview-row{display:flex;justify-content:space-between;align-items:center;padding:11px 0;border-bottom:1px solid rgba(255,255,255,0.06);font-size:13px;}
  .preview-row:last-child{border-bottom:none;}
  .preview-row .k{color:var(--text-mid);}
  .preview-row .v{font-family:var(--font-mono);color:var(--text-hi);}

  .preview-modules{margin-top:18px;display:flex;flex-wrap:wrap;gap:8px;}
  .module-chip{
    font-family:var(--font-mono);font-size:11px;padding:7px 12px;border-radius:100px;
    border:1px solid var(--line);color:var(--text-low);background:rgba(255,255,255,0.02);
    transition:all .35s ease;
  }
  .module-chip.on{border-color:rgba(255,90,31,0.4);color:var(--amber);background:rgba(255,90,31,0.07);}

  .ai-card{
    margin-top:20px;padding:16px;border-radius:14px;
    background:linear-gradient(135deg, rgba(255,90,31,0.1), rgba(255,31,76,0.05));
    border:1px solid rgba(255,90,31,0.25);
  }
  .ai-card .ai-label{display:flex;align-items:center;gap:7px;font-family:var(--font-mono);font-size:11px;color:var(--amber);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:9px;}
  .ai-dot{width:6px;height:6px;border-radius:50%;background:var(--amber);box-shadow:0 0 8px var(--amber);flex-shrink:0;}
  .ai-card p{font-size:13px;color:var(--text-hi);line-height:1.55;}

  .trust-row{display:flex;gap:18px;margin-top:22px;flex-wrap:wrap;}
  .trust-row .t{font-size:12px;color:var(--text-low);display:flex;align-items:center;gap:7px;}
  .trust-row .t::before{content:'✓';color:var(--ember);font-weight:700;}

  @media (prefers-reduced-motion: reduce){
    *{animation-duration:0.01ms !important;animation-iteration-count:1 !important;transition-duration:0.01ms !important;}
  }
  :focus-visible{outline:2px solid var(--ember);outline-offset:3px;}
  /* ---------- payment stage ---------- */
  .pay-summary{
    background:rgba(255,255,255,0.03);border:1px solid var(--line);
    border-radius:14px;padding:6px 18px;margin-bottom:4px;
  }
  .pay-row{
    display:flex;justify-content:space-between;align-items:center;gap:14px;
    padding:12px 0;border-bottom:1px solid rgba(255,255,255,0.06);font-size:13.5px;
    color:var(--text-mid);
  }
  .pay-row:last-child{border-bottom:none;}
  .pay-row strong{color:var(--text-hi);font-weight:600;text-align:right;word-break:break-word;}
  .pay-row.total{border-top:1px solid var(--line);margin-top:2px;padding-top:14px;font-size:15px;}
  .pay-row.total strong{font-family:var(--font-mono);color:var(--ember);font-size:19px;}
  .pay-status{
    margin-top:18px;padding:12px 15px;border-radius:11px;font-size:13px;line-height:1.55;
    background:var(--glass);border:1px solid var(--line);color:var(--text-mid);
  }
  .pay-status.ok{background:rgba(74,222,128,0.1);border-color:rgba(74,222,128,0.3);color:#4ade80;}
  .pay-status.warn{background:rgba(255,179,71,0.1);border-color:rgba(255,179,71,0.32);color:var(--amber);}
  .pay-status.err{background:rgba(255,31,76,0.1);border-color:rgba(255,31,76,0.3);color:#ff6b6b;}
  #payBtn:disabled{opacity:0.6;cursor:default;transform:none;}
  .logo-img{height:30px;width:auto;display:block;mix-blend-mode:screen;}
  @media(max-width:520px){.logo-img{height:25px;}}
</style>
</head>
<body>

<div id="bg-canvas"></div>
<div class="grain"></div>
<div class="cursor-light" id="cursorLight"></div>

<header>
  <div class="wrap">
    <nav>
      <div class="logo"><img src="assets/logo.png" alt="Dinetous" class="logo-img"></div>
      <a href="index.html" class="back-link">← Back to home</a>
    </nav>
  </div>
</header>

<section class="onboard-shell">
  <div class="wrap onboard-grid">

    <!-- LEFT: FORM -->
    <div>
      <div class="eyebrow">Start Modernizing</div>
      <div class="stepper" id="stepper">
        <div class="step-node active" data-step="0">1</div>
        <div class="step-connector"><i></i></div>
        <div class="step-node" data-step="1">2</div>
        <div class="step-connector"><i></i></div>
        <div class="step-node" data-step="2">3</div>
        <div class="step-connector"><i></i></div>
        <div class="step-node" data-step="3">4</div>
        <div class="step-connector"><i></i></div>
        <div class="step-node" data-step="4">5</div>
      </div>
      <div class="step-labels">
        <span>Restaurant</span><span>Contact</span><span>Plan</span><span>Payment</span><span>Launch</span>
      </div>

      <div class="panel" style="margin-top:30px;">

        <?php if ($error !== ''): ?>
          <div class="pay-status err" style="margin-bottom:20px;display:block;">
            <?php echo htmlspecialchars($error); ?>
          </div>
        <?php endif; ?>

        <?php if ($stage === 'pay'): ?>

        <div class="pay-stage">
          <h2 style="font-size:20px;margin-bottom:8px;">Confirm &amp; pay</h2>
          <p style="color:var(--text-mid);font-size:13.5px;line-height:1.65;margin-bottom:22px;">
            One last step. We charge <strong>₹1</strong> to verify your payment method —
            your account is created the moment it succeeds.
          </p>

          <div class="pay-summary">
            <div class="pay-row"><span>Restaurant</span><strong><?php echo htmlspecialchars($checkout['name']); ?></strong></div>
            <div class="pay-row"><span>Owner</span><strong><?php echo htmlspecialchars($checkout['owner']); ?></strong></div>
            <div class="pay-row"><span>Email</span><strong><?php echo htmlspecialchars($checkout['email']); ?></strong></div>
            <div class="pay-row"><span>Plan</span><strong><?php echo htmlspecialchars($checkout['plan']); ?></strong></div>
            <div class="pay-row total"><span>Amount due today</span><strong>₹<?php echo number_format($checkout['amount'] / 100, 2); ?></strong></div>
          </div>

          <div id="payStatus" class="pay-status" style="display:none;"></div>

          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:20px;">
            <button type="button" class="btn btn-primary btn-lg" id="payBtn">Pay ₹1 &amp; create account</button>
            <a href="onboarding.php" class="btn btn-ghost btn-lg">← Edit details</a>
          </div>

          <p style="color:var(--text-low);font-size:11.5px;margin-top:16px;line-height:1.6;">
            Payment is handled by Razorpay. We never see or store your card details.
            Test mode — use card <span style="font-family:var(--font-mono);">4111 1111 1111 1111</span>,
            any future expiry, any CVV.
          </p>
        </div>

        <form method="post" action="onboarding.php" id="verifyForm" style="display:none;">
          <input type="hidden" name="verify_payment" value="1">
          <input type="hidden" name="razorpay_payment_id" id="rzpPaymentId">
          <input type="hidden" name="razorpay_order_id" id="rzpOrderId">
          <input type="hidden" name="razorpay_signature" id="rzpSignature">
        </form>

        <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
        <script>
        (function () {
          var CHECKOUT = <?php echo json_encode([
              'key'      => $checkout['key'],
              'amount'   => $checkout['amount'],
              'currency' => $checkout['currency'],
              'order_id' => $checkout['order_id'],
              'name'     => $checkout['name'],
              'owner'    => $checkout['owner'],
              'email'    => $checkout['email'],
              'phone'    => $checkout['phone'],
              'plan'     => $checkout['plan'],
          ], JSON_UNESCAPED_SLASHES); ?>;

          var btn = document.getElementById('payBtn');
          var status = document.getElementById('payStatus');

          function say(msg, kind) {
            status.textContent = msg;
            status.className = 'pay-status ' + (kind || '');
            status.style.display = 'block';
          }

          function openCheckout() {
            if (typeof Razorpay === 'undefined') {
              say('Could not load the payment window. Check your internet connection and try again.', 'err');
              return;
            }
            btn.disabled = true;
            btn.textContent = 'Opening payment…';

            var rzp = new Razorpay({
              key: CHECKOUT.key,
              amount: CHECKOUT.amount,
              currency: CHECKOUT.currency,
              order_id: CHECKOUT.order_id,
              name: 'Dinetous',
              description: CHECKOUT.plan + ' plan — signup verification',
              prefill: {
                name: CHECKOUT.owner,
                email: CHECKOUT.email,
                contact: CHECKOUT.phone || ''
              },
              notes: { restaurant: CHECKOUT.name },
              theme: { color: '#ff5a1f' },
              // The server re-checks this signature before creating anything.
              handler: function (res) {
                document.getElementById('rzpPaymentId').value = res.razorpay_payment_id || '';
                document.getElementById('rzpOrderId').value = res.razorpay_order_id || '';
                document.getElementById('rzpSignature').value = res.razorpay_signature || '';
                say('Payment received. Setting up your account…', 'ok');
                document.getElementById('verifyForm').submit();
              },
              modal: {
                ondismiss: function () {
                  btn.disabled = false;
                  btn.textContent = 'Pay ₹1 & create account';
                  say('Payment was cancelled. Your account has not been created yet — press the button to try again.', 'warn');
                }
              }
            });

            rzp.on('payment.failed', function (e) {
              btn.disabled = false;
              btn.textContent = 'Try payment again';
              say((e.error && e.error.description) ? e.error.description : 'That payment did not go through. Please try again.', 'err');
            });

            rzp.open();
          }

          btn.addEventListener('click', openCheckout);
          openCheckout(); // open straight away; the button is the retry path
        })();
        </script>

        <?php else: ?>

        <form id="onboardForm" method="post" action="onboarding.php">
          <input type="hidden" name="register" id="registerField" value="0">
          <input type="hidden" name="plan_name" id="planNameField" value="<?php echo htmlspecialchars($post['plan_name'] ?? 'Growth'); ?>">
          <input type="hidden" name="billing_cycle" id="billingCycleField" value="<?php echo htmlspecialchars($post['billing_cycle'] ?? 'monthly'); ?>">
          <!-- STEP 1 -->
          <div class="step-panel active" data-panel="0">
            <h2>Tell us about your restaurant</h2>
            <p class="step-sub">This builds the foundation of your Dinetous system.</p>

            <div class="field">
              <label>Restaurant name</label>
              <input type="text" id="restName" name="restaurant_name" placeholder="e.g. Spice Route Kitchen" required value="<?php echo htmlspecialchars($post['restaurant_name'] ?? ''); ?>">
            </div>
            <div class="field-row">
              <div class="field">
                <label>Cuisine type</label>
                <select id="cuisine" name="cuisine">
                  <?php
                  $cuisines = ['Multi-cuisine', 'North Indian', 'South Indian', 'Italian', 'Cafe & Bakery', 'Fine Dining', 'Other'];
                  $sel_cuisine = $post['cuisine'] ?? 'Multi-cuisine';
                  foreach ($cuisines as $c):
                  ?>
                  <option<?php echo $sel_cuisine === $c ? ' selected' : ''; ?>><?php echo htmlspecialchars($c); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label>City</label>
                <input type="text" id="city" name="city" list="cityOptions" autocomplete="address-level2"
                       placeholder="e.g. Bikaner" value="<?php echo htmlspecialchars($post['city'] ?? ''); ?>">
                <datalist id="cityOptions">
                  <?php foreach (directory_cities() as $c): ?>
                    <option value="<?php echo htmlspecialchars($c); ?>"></option>
                  <?php endforeach; ?>
                </datalist>
              </div>
            </div>
            <div class="field">
              <label>Number of tables</label>
              <input type="number" id="tables" name="tables" placeholder="e.g. 18" min="0" value="<?php echo htmlspecialchars($post['tables'] ?? ''); ?>">
            </div>
          </div>

          <!-- STEP 2 -->
          <div class="step-panel" data-panel="1">
            <h2>Your contact details</h2>
            <p class="step-sub">We'll send your setup link and onboarding call here.</p>

            <div class="field">
              <label>Full name</label>
              <input type="text" id="ownerName" name="owner_name" placeholder="e.g. Aarav Sharma" required value="<?php echo htmlspecialchars($post['owner_name'] ?? ''); ?>">
            </div>
            <div class="field-row">
              <div class="field">
                <label>Work email</label>
                <input type="email" id="email" name="email" placeholder="you@restaurant.com" required value="<?php echo htmlspecialchars($post['email'] ?? ''); ?>">
              </div>
              <div class="field">
                <label>Phone number</label>
                <input type="tel" id="phone" name="phone" placeholder="+91 98xxxxxxx0" value="<?php echo htmlspecialchars($post['phone'] ?? ''); ?>">
              </div>
            </div>
            <div class="field-row">
              <div class="field">
                <label>Password</label>
                <div class="password-wrap">
                  <input type="password" id="password" name="password" placeholder="Create a password" minlength="6" autocomplete="new-password" required>
                  <button type="button" class="pw-toggle" data-target="password" aria-label="Show password">👁</button>
                </div>
              </div>
              <div class="field">
                <label>Confirm password</label>
                <div class="password-wrap">
                  <input type="password" id="confirmPassword" name="confirm_password" placeholder="Re-enter password" minlength="6" autocomplete="new-password" required>
                  <button type="button" class="pw-toggle" data-target="confirmPassword" aria-label="Show password">👁</button>
                </div>
              </div>
            </div>
          </div>

          <!-- STEP 3 -->
          <div class="step-panel" data-panel="2">
            <h2>Choose your plan</h2>
            <p class="step-sub">Start on any plan — upgrade anytime as you grow.</p>

            <div class="plan-grid" id="planGrid">
              <div class="plan-card" data-plan="Starter">
                <div class="plan-check">✓</div>
                <div class="plan-name">Starter</div>
                <div class="plan-price">₹2,999/mo</div>
                <ul>
                  <li>QR ordering</li>
                  <li>Kitchen display</li>
                  <li>Basic analytics</li>
                </ul>
              </div>
              <div class="plan-card selected" data-plan="Growth">
                <div class="badge">Most popular</div>
                <div class="plan-check">✓</div>
                <div class="plan-name">Growth</div>
                <div class="plan-price">₹6,999/mo</div>
                <ul>
                  <li>Everything in Starter</li>
                  <li>3D AR menu</li>
                  <li>AI growth engine</li>
                </ul>
              </div>
              <div class="plan-card" data-plan="Enterprise">
                <div class="plan-check">✓</div>
                <div class="plan-name">Enterprise</div>
                <div class="plan-price">Custom</div>
                <ul>
                  <li>Everything in Growth</li>
                  <li>Multi-location AI</li>
                  <li>Dedicated strategist</li>
                </ul>
              </div>
            </div>
          </div>

          <!-- STEP 4: PAYMENT -->
          <div class="step-panel" data-panel="3">
            <h2>Payment details</h2>
            <p class="step-sub">Your subscription starts once payment is confirmed. Cancel anytime.</p>

            <div class="billing-toggle" id="billingToggle">
              <button type="button" class="cycle-btn active" data-cycle="monthly">Monthly</button>
              <button type="button" class="cycle-btn" data-cycle="annual">Annual <span class="save-tag">Save 20%</span></button>
            </div>

            <div class="order-summary">
              <div class="review-row"><span class="k">Plan</span><span class="v" id="paySummaryPlan">Growth</span></div>
              <div class="review-row"><span class="k">Billing cycle</span><span class="v" id="paySummaryCycle">Monthly</span></div>
              <div class="review-row"><span class="k">Subscription</span><span class="v" id="paySummarySubtotal">₹6,999</span></div>
              <div class="review-row"><span class="k">GST (18%)</span><span class="v" id="paySummaryTax">₹1,260</span></div>
              <div class="review-row"><span class="k">Billed from</span><span class="v" id="paySummaryStart">After your free setup period</span></div>
              <div class="review-row total-row"><span class="k">Due today</span><span class="v" id="paySummaryTotal">₹1</span></div>
            </div>

            <div id="cardFieldsWrap">
              <div class="secure-note" style="margin-top:22px;">
                🔒 We charge <strong>₹1</strong> now to verify your payment method. You'll enter your
                card on Razorpay's secure window after this step — Dinetous never sees or stores your
                card details.
              </div>
            </div>

            <div id="enterpriseNote" style="display:none;margin-top:22px;padding:18px 20px;border:1px solid var(--line);border-radius:14px;background:rgba(255,255,255,0.02);font-size:13.5px;color:var(--text-mid);">
              Enterprise plans are custom-priced. No payment is needed now — our team will reach out to scope your setup and invoice you directly.
            </div>
          </div>

          <!-- STEP 5 -->
          <div class="step-panel" data-panel="4">
            <h2>Review & launch</h2>
            <p class="step-sub">Confirm the details below — you can edit anything later.</p>

            <div class="review-box">
              <div class="review-row"><span class="k">Restaurant</span><span class="v" id="rvName">—</span></div>
              <div class="review-row"><span class="k">Cuisine</span><span class="v" id="rvCuisine">—</span></div>
              <div class="review-row"><span class="k">City</span><span class="v" id="rvCity">—</span></div>
              <div class="review-row"><span class="k">Tables</span><span class="v" id="rvTables">—</span></div>
              <div class="review-row"><span class="k">Owner</span><span class="v" id="rvOwner">—</span></div>
              <div class="review-row"><span class="k">Email</span><span class="v" id="rvEmail">—</span></div>
              <div class="review-row"><span class="k">Password</span><span class="v" id="rvPassword">—</span></div>
              <div class="review-row"><span class="k">Plan</span><span class="v" id="rvPlan">—</span></div>
              <div class="review-row"><span class="k">Billing</span><span class="v" id="rvBilling">—</span></div>
              <div class="review-row total-row"><span class="k">Total charged today</span><span class="v" id="rvTotal">—</span></div>
            </div>
          </div>

          <div class="form-actions">
            <button type="button" class="btn btn-ghost" id="prevBtn" style="visibility:hidden;">← Back</button>
            <button type="button" class="btn btn-primary" id="nextBtn">Continue →</button>
          </div>
        </form>

        <?php endif; ?>

        <!-- SUCCESS VIEW -->
        <div class="success-view" id="successView">
          <div class="success-ring">✓</div>
          <h2>Welcome to Dinetous</h2>
          <p>Payment confirmed and your restaurant OS is being provisioned. We're sending your receipt, setup instructions, and QR kit details to <span id="successEmail" style="color:var(--text-hi);">your email</span>.</p>
          <a href="Restro/overview.php" class="btn btn-primary">Go to dashboard</a>
        </div>

      </div>
    </div>

    <!-- RIGHT: LIVE PREVIEW -->
    <div class="preview-stage">
      <div class="preview-card">
        <div class="preview-head">
          <div>
            <div class="label">Your Restaurant OS</div>
            <div class="preview-name" id="prevName">Your Restaurant</div>
          </div>
          <div class="building-pill">BUILDING</div>
        </div>

        <div class="preview-row"><span class="k">Cuisine</span><span class="v" id="prevCuisine">Multi-cuisine</span></div>
        <div class="preview-row"><span class="k">City</span><span class="v" id="prevCity">—</span></div>
        <div class="preview-row"><span class="k">Tables connected</span><span class="v" id="prevTables">0</span></div>
        <div class="preview-row"><span class="k">Plan</span><span class="v" id="prevPlan">Growth</span></div>

        <div class="preview-modules">
          <div class="module-chip on">QR Ordering</div>
          <div class="module-chip" id="chipAR">AR Menu</div>
          <div class="module-chip on">Kitchen Display</div>
          <div class="module-chip" id="chipAI">AI Strategist</div>
        </div>

        <div class="ai-card">
          <div class="ai-label"><span class="ai-dot"></span>AI Insight</div>
          <p id="aiInsight">Once live, your AI strategist starts learning from every order within 24 hours.</p>
        </div>
      </div>

      <div class="trust-row">
        <span class="t">Setup in ~10 minutes</span>
        <span class="t">Cancel anytime</span>
      </div>
    </div>

  </div>
</section>

<script>
  // cursor light
  const cursorLight = document.getElementById('cursorLight');
  window.addEventListener('pointermove', (e) => {
    cursorLight.style.transform = `translate(${e.clientX}px, ${e.clientY}px) translate(-50%,-50%)`;
  });

  // ---- state ----
  let step = 0;
  const totalSteps = 5;
  const panels = document.querySelectorAll('.step-panel');
  const nodes = document.querySelectorAll('.step-node');
  const connectors = document.querySelectorAll('.step-connector i');
  const nextBtn = document.getElementById('nextBtn');
  const prevBtn = document.getElementById('prevBtn');
  const form = document.getElementById('onboardForm');
  const successView = document.getElementById('successView');

  let selectedPlan = <?php echo json_encode($post['plan_name'] ?? 'Growth'); ?>;
  let billingCycle = <?php echo json_encode($post['billing_cycle'] ?? 'monthly'); ?>;

  const planPricing = {
    Starter: 2999,
    Growth: 6999,
    Enterprise: null // custom — handled separately
  };

  const els = {
    restName: document.getElementById('restName'),
    cuisine: document.getElementById('cuisine'),
    city: document.getElementById('city'),
    tables: document.getElementById('tables'),
    ownerName: document.getElementById('ownerName'),
    email: document.getElementById('email'),
    phone: document.getElementById('phone'),
    password: document.getElementById('password'),
    confirmPassword: document.getElementById('confirmPassword'),
  };

  function renderStepUI(){
    panels.forEach(p => p.classList.toggle('active', +p.dataset.panel === step));
    nodes.forEach(n => {
      const idx = +n.dataset.step;
      n.classList.toggle('active', idx === step);
      n.classList.toggle('done', idx < step);
      n.textContent = idx < step ? '✓' : idx + 1;
    });
    connectors.forEach((c, i) => { c.style.width = i < step ? '100%' : '0%'; });

    prevBtn.style.visibility = step === 0 ? 'hidden' : 'visible';
    nextBtn.textContent = step === totalSteps - 1 ? 'Confirm & Launch →' : (step === 2 ? 'Continue to payment →' : 'Continue →');

    if(step === 3){ updatePaymentSummary(); }
    if(step === 4){ fillReview(); }
  }

  function pricingFor(plan){
    const base = planPricing[plan];
    if(base === null) return null; // Enterprise = custom quote
    const monthly = base;
    const annualMonthly = Math.round(base * 0.8);
    return billingCycle === 'monthly' ? monthly : annualMonthly * 12;
  }

  function formatRupee(n){ return '₹' + n.toLocaleString('en-IN'); }

  function updatePaymentSummary(){
    const subtotal = pricingFor(selectedPlan);
    document.getElementById('paySummaryPlan').textContent = selectedPlan;
    document.getElementById('paySummaryCycle').textContent = billingCycle === 'monthly' ? 'Monthly' : 'Annual (20% off)';

    if(subtotal === null){
      document.getElementById('paySummarySubtotal').textContent = 'Custom quote';
      document.getElementById('paySummaryTax').textContent = '—';
      document.getElementById('paySummaryTotal').textContent = 'Our team will contact you';
      return;
    }
    const tax = Math.round(subtotal * 0.18);
    document.getElementById('paySummarySubtotal').textContent = formatRupee(subtotal) + (billingCycle === 'annual' ? '/yr' : '/mo');
    document.getElementById('paySummaryTax').textContent = formatRupee(tax);
    // Only the ₹1 verification charge is taken today — say so plainly
    // rather than showing a subscription total we don't actually collect.
    document.getElementById('paySummaryTotal').textContent = '₹1';
  }

  function fillReview(){
    document.getElementById('rvName').textContent = els.restName.value || '—';
    document.getElementById('rvCuisine').textContent = els.cuisine.value;
    document.getElementById('rvCity').textContent = els.city.value || '—';
    document.getElementById('rvTables').textContent = els.tables.value || '—';
    document.getElementById('rvOwner').textContent = els.ownerName.value || '—';
    document.getElementById('rvEmail').textContent = els.email.value || '—';
    document.getElementById('rvPassword').textContent = els.password.value ? '••••••••' : '—';
    document.getElementById('rvPlan').textContent = selectedPlan;
    document.getElementById('rvBilling').textContent = billingCycle === 'monthly' ? 'Monthly' : 'Annual (20% off)';

    const subtotal = pricingFor(selectedPlan);
    document.getElementById('rvTotal').textContent = subtotal === null ? 'Custom quote' : '₹1 today';
  }

  function validateStep(){
    if(step === 0){
      if(!els.restName.value.trim()){ els.restName.focus(); return false; }
    }
    if(step === 1){
      if(!els.ownerName.value.trim()){ els.ownerName.focus(); return false; }
      if(!els.email.value.trim() || !els.email.value.includes('@')){ els.email.focus(); return false; }
      if(!els.password.value.trim()){ els.password.focus(); return false; }
      if(els.password.value.length < 6){
        alert('Password must be at least 6 characters.');
        els.password.focus();
        return false;
      }
      if(els.password.value !== els.confirmPassword.value){
        alert('Passwords do not match.');
        els.confirmPassword.focus();
        return false;
      }
    }
    // Nothing to validate on the payment step — card details are
    // collected by Razorpay, not by us.
    return true;
  }

  document.querySelectorAll('.pw-toggle').forEach(btn => {
    btn.addEventListener('click', () => {
      const input = document.getElementById(btn.dataset.target);
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
  });

  // billing cycle toggle
  document.querySelectorAll('.cycle-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.cycle-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      billingCycle = btn.dataset.cycle;
      updatePaymentSummary();
    });
  });

  nextBtn.addEventListener('click', () => {
    if(!validateStep()) return;
    if(step < totalSteps - 1){
      step++;
      renderStepUI();
    } else {
      document.getElementById('planNameField').value = selectedPlan;
      document.getElementById('billingCycleField').value = billingCycle;
      document.getElementById('registerField').value = '1';
      form.submit();
    }
  });

  prevBtn.addEventListener('click', () => {
    if(step > 0){ step--; renderStepUI(); }
  });

  // plan selection
  document.querySelectorAll('.plan-card').forEach(card => {
    card.addEventListener('click', () => {
      document.querySelectorAll('.plan-card').forEach(c => c.classList.remove('selected'));
      card.classList.add('selected');
      selectedPlan = card.dataset.plan;
      updatePreview();
      toggleCardFormForPlan();
    });
  });

  // Enterprise is quoted manually, so it sees a "we'll contact you" note
  // instead of the ₹1 verification note.
  function toggleCardFormForPlan(){
    const cardFieldsWrap = document.getElementById('cardFieldsWrap');
    const enterpriseNote = document.getElementById('enterpriseNote');
    const isEnterprise = selectedPlan === 'Enterprise';
    cardFieldsWrap.style.display = isEnterprise ? 'none' : 'block';
    enterpriseNote.style.display = isEnterprise ? 'block' : 'none';
  }

  // ---- live preview sync ----
  function updatePreview(){
    document.getElementById('prevName').textContent = els.restName.value || 'Your Restaurant';
    document.getElementById('prevCuisine').textContent = els.cuisine.value;
    document.getElementById('prevCity').textContent = els.city.value || '—';
    document.getElementById('prevTables').textContent = els.tables.value || '0';
    document.getElementById('prevPlan').textContent = selectedPlan;

    const chipAR = document.getElementById('chipAR');
    const chipAI = document.getElementById('chipAI');
    chipAR.classList.toggle('on', selectedPlan !== 'Starter');
    chipAI.classList.toggle('on', selectedPlan !== 'Starter');

    const insight = document.getElementById('aiInsight');
    const name = els.restName.value.trim();
    if(name){
      insight.innerHTML = `<b style="color:var(--amber)">${name}</b> is projected to save ~6 hrs/week in order handling once live.`;
    } else {
      insight.textContent = 'Once live, your AI strategist starts learning from every order within 24 hours.';
    }
  }

  Object.values(els).forEach(el => el.addEventListener('input', updatePreview));
  els.cuisine.addEventListener('change', updatePreview);

  document.querySelectorAll('.plan-card').forEach(card => {
    card.classList.toggle('selected', card.dataset.plan === selectedPlan);
  });
  document.querySelectorAll('.cycle-btn').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.cycle === billingCycle);
  });

  renderStepUI();
  updatePreview();
  toggleCardFormForPlan();
<?php if ($error !== '' && $stage !== 'pay'): ?>
  // The message is already visible in the panel above; just jump the
  // wizard to the step it relates to.
  step = 4;
  renderStepUI();
<?php endif; ?>
</script>

</body>
</html>
