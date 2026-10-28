<?php
/**
 * PAY RESULT — what the player sees after checkout  (v7)
 *
 * Paid -> receipt and a "Play now" button (or "Back to the app").
 * Still pending -> asks the gateway once more, then offers a refresh.
 * Failed -> the reason and a retry button.
 *
 * Inside the Android app the buttons point at asviora://pay-done, which
 * PayActivity catches to close itself and refresh the screen behind it.
 */
require_once __DIR__ . '/config/config.php';

require_login();
$u     = current_user();
$inApp = ($_SESSION['via'] ?? '') === 'app'
      || str_contains((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 'AsvioraApp');

// pay.php sends people here when there was nothing left to pay
if (get('done') === 'registration') {
    $p = null;
    $status = 'paid';
} else {
    $p = pay_by_ref(get('ref'));
    if (!$p || (int) $p['user_id'] !== (int) $u['id']) {
        flash('That payment could not be found.', 'error');
        redirect('dashboard.php?tab=payments');
    }

    // a pending order gets one more look at the gateway before we say so
    if ($p['status'] === 'created') {
        try {
            if ($p['gateway'] === 'razorpay' && $p['gw_order_id']) {
                $pid = rzp_order_paid((string) $p['gw_order_id']);
                if ($pid) {
                    pay_mark_paid($p, $pid, ['via' => 'result-recheck']);
                }
            } elseif ($p['gateway'] === 'cashfree') {
                $o = cf_order_status($p['order_ref']);
                if (strtoupper((string) ($o['order_status'] ?? '')) === 'PAID') {
                    pay_mark_paid($p, cf_paid_payment_id($p['order_ref']), ['via' => 'result-recheck']);
                }
            }
        } catch (Throwable $e) {
            // the gateway being slow is not the player's problem — show pending
        }
        $p = pay_by_ref($p['order_ref']);
    }
    $status = $p['status'];
}

$info = $p && $p['purpose'] === 'subscription' ? pay_item_info((string) $p['item_key']) : null;

// where "Play now" goes
$next = $_SESSION['redirect_after_pay'] ?? '';
if ($status === 'paid') {
    unset($_SESSION['redirect_after_pay']);
}
if ($next === '') {
    $next = 'games.php';
    if ($p && $p['purpose'] === 'subscription') {
        foreach (arena_items() as $it) {
            if ($it['key'] === $p['item_key']) {
                $next = $it['play_url'];
                break;
            }
        }
    }
}
$appDone = 'asviora://pay-done?status=' . urlencode($status) . ($p ? '&ref=' . urlencode($p['order_ref']) : '');

$sub = $p && $p['purpose'] === 'subscription' && $status === 'paid'
     ? sub_active((int) $u['id'], (string) $p['item_key']) : null;

$page_title  = $status === 'paid' ? 'Payment successful' : 'Payment status';
$seo_noindex = true;
$body_class  = $inApp ? 'in-app' : '';
require __DIR__ . '/includes/header.php';
?>

<section class="section-sm pay-page">
  <div class="container" style="max-width:560px">
    <div class="card pay-result pr-<?= e($status) ?> reveal">

      <?php if ($status === 'paid'): ?>
        <div class="pr-ico">&#10003;</div>
        <h1>Payment successful</h1>
        <p class="text-muted">
          <?php if (!$p || $p['purpose'] === 'registration'): ?>
            Your account is fully active. Every free game is open — have fun!
          <?php else: ?>
            <strong><?= e($info['title']) ?></strong> is unlocked<?php
              if ($sub): ?> until <strong><?= fdate($sub['expires_at'], 'd M Y') ?></strong><?php endif; ?>.
          <?php endif; ?>
        </p>
      <?php elseif ($status === 'created'): ?>
        <div class="pr-ico">&#8987;</div>
        <h1>Waiting for confirmation</h1>
        <p class="text-muted">If money left your account it will show here within a few minutes —
          you will not be charged twice. You can refresh this page.</p>
      <?php else: ?>
        <div class="pr-ico">&#10005;</div>
        <h1>Payment not completed</h1>
        <p class="text-muted"><?= e($p['note'] ?: 'The payment was cancelled or declined.') ?>
          No money was taken for this order.</p>
      <?php endif; ?>

      <?php if ($p): ?>
        <div class="pr-receipt">
          <div class="kv"><span class="k">Order</span><span class="v"><?= e($p['order_ref']) ?></span></div>
          <div class="kv"><span class="k">For</span><span class="v"><?=
            $p['purpose'] === 'registration' ? 'Registration fee'
              : e($info['title'] . ' — ' . days_label((int) $p['plan_days'])) ?></span></div>
          <div class="kv"><span class="k">Amount</span><span class="v text-gold"><?= money((int) $p['amount_paise'], $p['currency']) ?></span></div>
          <div class="kv"><span class="k">Method</span><span class="v"><?= e(PAY_GATEWAYS[$p['gateway']] ?? ucfirst($p['gateway'])) ?></span></div>
          <div class="kv"><span class="k">Date</span><span class="v"><?= fdate($p['paid_at'] ?: $p['created_at'], 'd M Y, h:i A') ?></span></div>
        </div>
      <?php endif; ?>

      <div class="pr-actions">
        <?php if ($status === 'paid'): ?>
          <?php if ($inApp): ?>
            <a class="btn btn-primary btn-block btn-lg" href="<?= e($appDone) ?>">Back to the app &rarr;</a>
          <?php else: ?>
            <a class="btn btn-primary btn-block btn-lg" href="<?= e(pay_abs_url($next)) ?>">Play now &rarr;</a>
            <a class="btn btn-ghost btn-block" href="<?= url('dashboard.php?tab=payments') ?>">My payments</a>
          <?php endif; ?>
        <?php elseif ($status === 'created'): ?>
          <a class="btn btn-primary btn-block" href="<?= e(url('pay-result.php?ref=' . urlencode($p['order_ref']))) ?>">Refresh</a>
          <?php if ($inApp): ?><a class="btn btn-ghost btn-block" href="<?= e($appDone) ?>">Back to the app</a><?php endif; ?>
        <?php else: ?>
          <a class="btn btn-primary btn-block" href="<?= e(url('pay.php?for=' . ($p['purpose'] === 'registration' ? 'registration'
              : 'game&item=' . urlencode((string) $p['item_key'])) . ($inApp ? '&app=1' : ''))) ?>">Try again</a>
          <?php if ($inApp): ?><a class="btn btn-ghost btn-block" href="<?= e($appDone) ?>">Back to the app</a><?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<?php if ($status === 'paid'): ?>
<script>try { navigator.vibrate && navigator.vibrate([30, 40, 30]); } catch (e) {}</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
