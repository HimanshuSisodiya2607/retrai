<?php
/**
 * Razorpay helpers for the signup fee.
 *
 * Two rules this file exists to enforce:
 *  1. The amount is decided on the server. A browser never tells us
 *     what to charge.
 *  2. A payment is only trusted after its signature verifies against
 *     our key secret. The checkout callback alone proves nothing —
 *     anyone can POST fake ids at us.
 */
require_once __DIR__ . '/../database/env.php';

/** Signup fee in paise. 100 paise = ₹1. */
const SIGNUP_FEE_PAISE = 100;
const RAZORPAY_CURRENCY = 'INR';

function razorpay_key_id(): string {
    return (string) (getenv('RAZORPAY_KEY_ID') ?: '');
}

function razorpay_key_secret(): string {
    return (string) (getenv('RAZORPAY_KEY_SECRET') ?: '');
}

function razorpay_configured(): bool {
    return razorpay_key_id() !== '' && razorpay_key_secret() !== '';
}

/**
 * Create an order with Razorpay.
 * Returns ['ok'=>true,'order'=>[...]] or ['ok'=>false,'error'=>'...'].
 */
function razorpay_create_order(int $amount_paise, string $receipt, array $notes = []): array {
    if (!razorpay_configured()) {
        return ['ok' => false, 'error' => 'Payments are not configured on this server.'];
    }

    $payload = [
        'amount'   => $amount_paise,
        'currency' => RAZORPAY_CURRENCY,
        'receipt'  => mb_substr($receipt, 0, 40),
        'notes'    => $notes,
    ];

    $ch = curl_init('https://api.razorpay.com/v1/orders');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERPWD        => razorpay_key_id() . ':' . razorpay_key_secret(),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'error' => 'Could not reach Razorpay: ' . $err];
    }

    $data = json_decode($body, true);
    if ($code !== 200 || empty($data['id'])) {
        $msg = $data['error']['description'] ?? ('Razorpay returned HTTP ' . $code);
        return ['ok' => false, 'error' => $msg];
    }
    return ['ok' => true, 'order' => $data];
}

/**
 * Verify a checkout response. Razorpay signs "order_id|payment_id"
 * with our key secret, so a matching HMAC is proof the payment is real
 * and untampered. hash_equals avoids a timing side channel.
 */
function razorpay_verify_signature(string $order_id, string $payment_id, string $signature): bool {
    if ($order_id === '' || $payment_id === '' || $signature === '') {
        return false;
    }
    $expected = hash_hmac('sha256', $order_id . '|' . $payment_id, razorpay_key_secret());
    return hash_equals($expected, $signature);
}

/** Fetch a payment from Razorpay to confirm status and amount server-side. */
function razorpay_fetch_payment(string $payment_id): array {
    $ch = curl_init('https://api.razorpay.com/v1/payments/' . rawurlencode($payment_id));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERPWD        => razorpay_key_id() . ':' . razorpay_key_secret(),
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code !== 200) {
        return ['ok' => false, 'error' => 'Could not verify the payment with Razorpay.'];
    }
    return ['ok' => true, 'payment' => json_decode($body, true)];
}
