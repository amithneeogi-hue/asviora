<?php
/**
 * PAY WEBHOOK — server-to-server confirmation from the gateways  (v7)
 *
 * Set these URLs in the gateway dashboards:
 *   Razorpay  -> https://asviora.in/pay-webhook.php?gw=razorpay
 *                events: payment.captured, order.paid
 *                secret: the same value as Admin -> Payments -> "Razorpay webhook secret"
 *   Cashfree  -> https://asviora.in/pay-webhook.php?gw=cashfree
 *                (also sent automatically as notify_url on every order)
 *
 * This is what credits a payment when the player's browser or app was
 * closed before it got back to pay-callback.php.
 */
require_once __DIR__ . '/config/config.php';

header('Content-Type: application/json; charset=utf-8');

function hook_done(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['ok' => $code < 300, 'message' => $msg]);
    exit;
}

$raw = (string) file_get_contents('php://input');
$gw  = get('gw');

if ($raw === '' || !payments_ready()) {
    hook_done(200, 'ignored');
}

// ------------------------------------------------------------------
if ($gw === 'razorpay') {
    $secret = setting('pay_razorpay_webhook_secret');
    $sig    = (string) ($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '');
    if ($secret === '' || $sig === ''
        || !hash_equals(hash_hmac('sha256', $raw, $secret), $sig)) {
        hook_done(400, 'bad signature');
    }

    $j     = json_decode($raw, true) ?: [];
    $event = (string) ($j['event'] ?? '');
    $pay   = $j['payload']['payment']['entity'] ?? [];
    $ord   = $j['payload']['order']['entity'] ?? [];
    $oid   = (string) ($pay['order_id'] ?? ($ord['id'] ?? ''));

    if ($oid === '') {
        hook_done(200, 'no order');
    }
    $p = fetch_one('SELECT * FROM payments WHERE gw_order_id = ? AND gateway = "razorpay"', [$oid]);
    if (!$p) {
        hook_done(200, 'unknown order');
    }

    if (in_array($event, ['payment.captured', 'order.paid'], true)) {
        $amt = (int) ($pay['amount'] ?? ($ord['amount_paid'] ?? 0));
        if ($amt === (int) $p['amount_paise']) {
            pay_mark_paid($p, (string) ($pay['id'] ?? ''), ['via' => 'webhook', 'event' => $event]);
        }
    } elseif ($event === 'payment.failed') {
        pay_mark_failed($p, (string) ($pay['error_description'] ?? 'Payment failed'));
    }
    hook_done(200, 'ok');
}

// ------------------------------------------------------------------
if ($gw === 'cashfree') {
    $ts  = (string) ($_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '');
    $sig = (string) ($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '');
    $want = base64_encode(hash_hmac('sha256', $ts . $raw, setting('pay_cashfree_secret'), true));
    if ($ts === '' || $sig === '' || !hash_equals($want, $sig)) {
        hook_done(400, 'bad signature');
    }

    $j   = json_decode($raw, true) ?: [];
    $ref = (string) ($j['data']['order']['order_id'] ?? '');
    $p   = pay_by_ref($ref);
    if (!$p || $p['gateway'] !== 'cashfree') {
        hook_done(200, 'unknown order');
    }

    $status = strtoupper((string) ($j['data']['payment']['payment_status'] ?? ''));
    $amt    = (int) round(((float) ($j['data']['order']['order_amount'] ?? 0)) * 100);

    if ($status === 'SUCCESS' && $amt === (int) $p['amount_paise']) {
        pay_mark_paid($p, (string) ($j['data']['payment']['cf_payment_id'] ?? ''),
                      ['via' => 'webhook', 'type' => $j['type'] ?? '']);
    } elseif (in_array($status, ['FAILED', 'USER_DROPPED'], true)) {
        pay_mark_failed($p, 'Cashfree: ' . strtolower($status));
    }
    hook_done(200, 'ok');
}

hook_done(404, 'unknown gateway');
