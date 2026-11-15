<?php
/**
 * DEPOSIT — top up the coin wallet  (v8)
 *
 * Simple 1:1 model: ₹1 deposited = 1 coin credited to the wallet.
 * No packages. The player enters the amount (minimum = game entry fee).
 */
require_once __DIR__ . '/config/config.php';

$ticket = trim((string) ($_GET['t'] ?? ''));
if ($ticket !== '') {
    $appUser = app_frame_user($ticket, 'pay');
    if ($appUser > 0 && (int) ($_SESSION['user_id'] ?? 0) !== $appUser) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $appUser;
    }
    if ($appUser > 0) {
        $_SESSION['via'] = 'app';
    }
}
$inApp = ($_SESSION['via'] ?? '') === 'app' || !empty($_GET['app'])
      || str_contains((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 'AsvioraApp');

require_login();
$u = current_user();
if (!$u) {
    redirect('login.php');
}

if (!payments_ready() || !pay_enabled()) {
    flash('Coin top-up is not open yet.', 'info');
    redirect('dashboard.php');
}

$cubeEntry = (int) fetch_col('SELECT entry_coins FROM games WHERE slug = "cube3d"', [], 0);
if ($cubeEntry <= 0) $cubeEntry = (int) setting('cube_entry_unlock_min_deposit', '1');

$gateways = pay_gateways();
$error    = '';
$checkout = null;
$amountRupees = (int) ($_POST['amount'] ?? ($_GET['amount'] ?? 0));
if ($amountRupees <= 0) $amountRupees = max($cubeEntry, 10);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $amt = (int) post('amount');
        if ($amt < 1) {
            $error = 'Enter an amount of at least ₹1.';
        } else {
            $isFirst = !user_has_deposited((int) $u['id']);
            $coinsGranted = $amt; // 1:1
            if ($isFirst && $coinsGranted < $cubeEntry) {
                $error = 'Your first deposit must unlock at least ' . num($cubeEntry)
                       . ' coins to play 3D Cube. Deposit at least ₹' . num($cubeEntry) . '.';
            } else {
                $gw = post('gw');
                if (!in_array($gw, $gateways, true)) {
                    $error = 'Please choose a payment method.';
                } else {
                    $phone = preg_replace('/[^\d]/', '', post('phone') ?: (string) $u['phone']);
                    if (strlen($phone) > 10) $phone = substr($phone, -10);
                    if ($gw === 'cashfree' && !preg_match('/^[6-9]\d{9}$/', $phone)) {
                        $error = 'Enter a valid 10-digit mobile number — the payment page needs it.';
                    }
                    if ($error === '') {
                        if ($phone !== '' && (string) $u['phone'] === '') {
                            q('UPDATE users SET phone = ? WHERE id = ?', [$phone, (int) $u['id']]);
                        }
                        try {
                            $amountPaise = $amt * 100;
                            $p = pay_create((int) $u['id'], 'deposit', null, $amountPaise, null, $gw,
                                             $inApp ? 'app' : 'web');
                            q('UPDATE payments SET meta = ? WHERE id = ?',
                              [json_encode(['coins' => $coinsGranted, 'bonus_coins' => 0]), (int) $p['id']]);
                            if ($gw === 'razorpay') {
                                $checkout = ['gw' => 'razorpay', 'ref' => $p['order_ref'],
                                             'order' => rzp_create_order($p), 'phone' => $phone];
                            } else {
                                $checkout = ['gw' => 'cashfree', 'ref' => $p['order_ref'],
                                             'session' => cf_create_order($p, $u, $phone)];
                            }
                            $amountRupees = $amt;
                        } catch (Throwable $e) {
                            if (isset($p)) pay_mark_failed($p, $e->getMessage());
                            $error = 'The payment could not be started. '
                                   . (DEBUG_MODE ? $e->getMessage() : 'Please try again in a minute or use the other method.');
                        }
                    }
                }
            }
        }
    }
}

$page_title  = 'Top up coins';
$seo_noindex = true;
$body_class  = $inApp ? 'in-app' : '';
require __DIR__ . '/includes/header.php';
?>
<section class="section-sm pay-page">
  <div class="container" style="max-width:720px">
    <?= render_flash() ?>
    <?php if ($error): ?>
      <div class="alert alert-error" data-persist="1"><span>&#9888;</span><?= e($error) ?></div>
    <?php endif; ?>

    <div class="pay-grid" style="grid-template-columns:1fr">
      <div class="card pay-box reveal">
        <div class="pay-total">
          <span class="text-dim">You will get</span>
          <strong><?= num($amountRupees) ?> coins</strong>
          <span class="text-dim">for ₹<?= num($amountRupees) ?></span>
        </div>

        <?php if (!user_has_deposited((int) $u['id'])): ?>
          <div class="alert alert-info" data-persist="1"><span>&#8505;</span>
            <strong>First deposit unlocks your signup bonus coins too.</strong>
            Minimum first deposit: ₹<?= num($cubeEntry) ?>.</div>
        <?php endif; ?>

        <?php if (!$gateways): ?>
          <div class="alert alert-info" data-persist="1"><span>&#8505;</span>
            Top-up is not open yet. Please contact us.</div>
        <?php elseif ($checkout): ?>
          <div class="pay-wait" id="payWait">
            <div class="pay-spinner"></div>
            <p style="margin:12px 0 4px;font-weight:700">Opening secure checkout…</p>
            <p class="text-dim" style="margin:0">Order <?= e($checkout['ref']) ?></p>
          </div>
          <div id="payRetry" hidden>
            <p class="text-muted center">The payment window was closed.</p>
            <button class="btn btn-primary btn-block" type="button" id="payAgain">Try again</button>
          </div>
        <?php else: ?>
          <form method="post" id="depositForm">
            <?= csrf_field() ?>
            <div class="form-group">
              <label class="form-label" for="amount">Amount (₹)</label>
              <input class="form-control" id="amount" name="amount" type="number" min="1"
                     value="<?= (int) $amountRupees ?>" required>
              <div class="form-hint">₹1 = 1 coin. Minimum first deposit ₹<?= num($cubeEntry) ?>.</div>
            </div>

            <div class="form-label" style="margin:14px 0 10px">Pay with</div>
            <div class="pay-methods">
              <?php foreach ($gateways as $i => $g): ?>
                <label class="pay-method">
                  <input type="radio" name="gw" value="<?= e($g) ?>" <?= $i === 0 ? 'checked' : '' ?>>
                  <span class="pm-in">
                    <span class="pm-logo pm-<?= e($g) ?>"><?= e(PAY_GATEWAYS[$g]) ?></span>
                    <span class="text-dim">UPI · Cards · Netbanking · Wallets</span>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>

            <div class="form-group mt-3">
              <label class="form-label" for="phone">Mobile number</label>
              <input class="form-control" id="phone" name="phone" inputmode="numeric"
                     maxlength="14" placeholder="10-digit mobile"
                     value="<?= e(preg_replace('/[^\d]/', '', (string) $u['phone'])) ?>">
            </div>

            <button class="btn btn-primary btn-block btn-lg" type="submit">
              Pay ₹<?= num($amountRupees) ?> securely &rarr;</button>
          </form>
        <?php endif; ?>

        <p class="text-dim center" style="margin:14px 0 0;font-size:.8rem">
          &#128274; <?= e(setting('pay_brand_note', 'Payments are processed securely by our payment partner.')) ?>
        </p>
      </div>
    </div>
  </div>
</section>

<?php if ($checkout && $checkout['gw'] === 'razorpay'): ?>
<form method="post" action="<?= e(url('pay-callback.php?gw=razorpay')) ?>" id="rzpDone" hidden>
  <input type="hidden" name="ref" value="<?= e($checkout['ref']) ?>">
  <input type="hidden" name="razorpay_payment_id" id="rzpPid">
  <input type="hidden" name="razorpay_order_id" id="rzpOid">
  <input type="hidden" name="razorpay_signature" id="rzpSig">
</form>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
(function () {
  var opts = {
    key: <?= json_encode(setting('pay_razorpay_key_id')) ?>,
    order_id: <?= json_encode($checkout['order']) ?>,
    amount: <?= (int) ($amountRupees * 100) ?>,
    currency: <?= json_encode(pay_currency()) ?>,
    name: <?= json_encode(setting('site_name', 'Asviora Games')) ?>,
    description: <?= json_encode('Coin top-up — ' . $amountRupees . ' coins') ?>,
    image: <?= json_encode(logo_icon(192)) ?>,
    prefill: { name: <?= json_encode($u['name']) ?>, email: <?= json_encode($u['email']) ?>,
               contact: <?= json_encode($checkout['phone']) ?> },
    notes: { ref: <?= json_encode($checkout['ref']) ?> },
    theme: { color: '#8fce3f' },
    handler: function (r) {
      document.getElementById('rzpPid').value = r.razorpay_payment_id;
      document.getElementById('rzpOid').value = r.razorpay_order_id;
      document.getElementById('rzpSig').value = r.razorpay_signature;
      document.getElementById('rzpDone').submit();
    },
    modal: { ondismiss: function () {
      document.getElementById('payWait').hidden = true;
      document.getElementById('payRetry').hidden = false;
    } }
  };
  function open() { var rz = new Razorpay(opts); rz.open(); }
  document.getElementById('payAgain').addEventListener('click', function () {
    document.getElementById('payRetry').hidden = true;
    document.getElementById('payWait').hidden = false;
    open();
  });
  open();
})();
</script>
<?php elseif ($checkout && $checkout['gw'] === 'cashfree'): ?>
<script src="https://sdk.cashfree.com/js/v3/cashfree.js"></script>
<script>
(function () {
  var cf = Cashfree({ mode: <?= json_encode(setting('pay_cashfree_mode', 'sandbox') === 'production' ? 'production' : 'sandbox') ?> });
  function open() { cf.checkout({ paymentSessionId: <?= json_encode($checkout['session']) ?>, redirectTarget: '_self' }); }
  document.getElementById('payAgain').addEventListener('click', open);
  open();
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
