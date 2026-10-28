<?php
/**
 * PAY CALLBACK — the browser comes back here from the gateway  (v7)
 *
 *   POST pay-callback.php?gw=razorpay   (from the Checkout handler)
 *   GET  pay-callback.php?gw=cashfree&ref=ASV...   (Cashfree return_url)
 *
 * Nothing the browser sends is trusted on its own: Razorpay's answer is
 * checked against its HMAC signature, Cashfree's by asking Cashfree for
 * the order. pay_mark_paid() is idempotent, so the webhook arriving first
 * (or second) never grants anything twice.
 */
require_once __DIR__ . '/config/config.php';

$gw = get('gw');

if ($gw === 'razorpay') {
    $ref = post('ref');
    $p   = pay_by_ref($ref);
    $pid = post('razorpay_payment_id');
    $oid = post('razorpay_order_id');
    $sig = post('razorpay_signature');

    if (!$p || $p['gateway'] !== 'razorpay') {
        redirect('pay-result.php?ref=' . urlencode($ref));
    }
    if ($p['status'] !== 'paid') {
        if ($oid !== '' && $oid === (string) $p['gw_order_id'] && rzp_signature_ok($oid, $pid, $sig)) {
            pay_mark_paid($p, $pid, ['via' => 'checkout']);
        } else {
            // a bad or missing signature — ask Razorpay directly before giving up
            $paidId = $p['gw_order_id'] ? rzp_order_paid((string) $p['gw_order_id']) : null;
            if ($paidId) {
                pay_mark_paid($p, $paidId, ['via' => 'checkout-recheck']);
            } else {
                pay_mark_failed($p, 'Signature check failed');
            }
        }
    }
    redirect('pay-result.php?ref=' . urlencode($p['order_ref']));
}

if ($gw === 'cashfree') {
    $ref = get('ref') ?: get('order_id');
    $p   = pay_by_ref($ref);
    if (!$p || $p['gateway'] !== 'cashfree') {
        redirect('pay-result.php?ref=' . urlencode($ref));
    }
    if ($p['status'] !== 'paid') {
        $o = cf_order_status($p['order_ref']);
        $st = strtoupper((string) ($o['order_status'] ?? ''));
        if ($st === 'PAID'
            && (int) round(((float) ($o['order_amount'] ?? 0)) * 100) === (int) $p['amount_paise']) {
            pay_mark_paid($p, cf_paid_payment_id($p['order_ref']), ['via' => 'return']);
        } elseif (in_array($st, ['EXPIRED', 'TERMINATED', 'TERMINATION_REQUESTED'], true)) {
            pay_mark_failed($p, 'Cashfree order ' . strtolower($st));
        }
        // ACTIVE = not paid yet (closed or still pending) — leave it "created"
    }
    redirect('pay-result.php?ref=' . urlencode($p['order_ref']));
}

redirect('index.php');
