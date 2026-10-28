<?php
/**
 * PAY — registration fee and game subscriptions  (v7)
 *
 *   pay.php?for=registration
 *   pay.php?for=game&item=sudoku
 *
 * Shows what is being bought, lets the player pick Razorpay or Cashfree,
 * creates the order on our side and at the gateway, and opens the
 * gateway's checkout. The result comes back to pay-callback.php (and,
 * independently, to pay-webhook.php).
 *
 * The Android app opens this page in a WebView with a signed ticket
 * (&t=...), the same kind game-frame.php accepts, so the app never has
 * to put the player's password or token in a URL.
 */
require_once __DIR__ . '/config/config.php';

/* ---------- the app's door ---------- */
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

if (!payments_ready()) {
    flash('Payments are not switched on.', 'info');
    redirect('dashboard.php');
}

$for  = get('for') === 'game' ? 'game' : 'registration';
$item = preg_replace('/[^a-z0-9_\-]/i', '', get('item'));

/* ---------- what is being bought ---------- */
if ($for === 'registration') {
    $amount = reg_fee_amount();
    if ($amount <= 0 || !reg_fee_due($u)) {
        // nothing to pay — straight on to wherever they were going
        $back = $_SESSION['redirect_after_pay'] ?? '';
        unset($_SESSION['redirect_after_pay']);
        redirect($inApp ? 'pay-result.php?done=registration' : ($back !== '' ? pay_abs_url($back) : 'games.php'));
    }
    $days    = null;
    $title   = 'Registration fee';
    $sub     = 'One-time fee — pay once, play every free game for good.';
    $thumb   = logo_icon(512);
    $purpose = 'registration';
    $key     = null;
    $perks   = [
        'Unlocks your account on the website and in the app',
        'All free games, the quiz and the daily spin open straight away',
        'Paid once — never charged again',
    ];
} else {
    if (reg_fee_due($u)) {
        redirect('pay.php?for=registration' . ($inApp ? '&app=1' : ''));
    }
    $plan = game_plan($item);
    if (!$plan) {
        flash('That game is free to play.', 'info');
        redirect('games.php');
    }
    $info    = pay_item_info($item);
    $amount  = (int) $plan['price_paise'];
    $days    = (int) $plan['duration_days'];
    $title   = $info['title'];
    $sub     = ($plan['label'] ?: 'Game pass') . ' — ' . days_label($days) . ' of unlimited play';
    $thumb   = $info['thumb'] ?: logo_icon(512);
    $purpose = 'subscription';
    $key     = $item;
    $active  = sub_active((int) $u['id'], $item);
    $perks   = [
        'Unlimited rounds of ' . $title . ' for ' . days_label($days),
        'Works on the website and in the Asviora app',
        'Scores, coins and XP count exactly as in free games',
        $active ? 'Buying now adds the days on top of your current pass' : 'No auto-renewal — you are never charged again without asking',
    ];
}

$gateways = pay_gateways();
$error    = '';
$checkout = null;   // set when an order has just been created

/* ---------- start a payment ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $gw = post('gw');
    if (!csrf_ok()) {
        $error = 'Your session expired. Please try again.';
    } elseif (!in_array($gw, $gateways, true)) {
        $error = 'Please choose a payment method.';
    } else {
        $phone = preg_replace('/[^\d]/', '', post('phone') ?: (string) $u['phone']);
        if (strlen($phone) > 10) {
            $phone = substr($phone, -10);          // drop the 91 / 0 prefix
        }
        if ($gw === 'cashfree' && !preg_match('/^[6-9]\d{9}$/', $phone)) {
            $error = 'Enter a valid 10-digit mobile number — the payment page needs it.';
        }

        if ($error === '') {
            if ($phone !== '' && (string) $u['phone'] === '') {
                q('UPDATE users SET phone = ? WHERE id = ?', [$phone, (int) $u['id']]);
            }
            try {
                $p = pay_create((int) $u['id'], $purpose, $key, $amount, $days, $gw,
                                $inApp ? 'app' : 'web');
                if ($gw === 'razorpay') {
                    $checkout = ['gw' => 'razorpay', 'ref' => $p['order_ref'],
                                 'order' => rzp_create_order($p), 'phone' => $phone];
                } else {
                    $checkout = ['gw' => 'cashfree', 'ref' => $p['order_ref'],
                                 'session' => cf_create_order($p, $u, $phone)];
                }
            } catch (Throwable $e) {
                if (isset($p)) {
                    pay_mark_failed($p, $e->getMessage());
                }
                $error = 'The payment could not be started. '
                       . (DEBUG_MODE ? $e->getMessage() : 'Please try again in a minute or use the other method.');
            }
        }
    }
}

$page_title  = 'Secure payment';
$seo_noindex = true;
$body_class  = $inApp ? 'in-app' : '';
require __DIR__ . '/includes/header.php';
?>

<section class="section-sm pay-page">
  <div class="container" style="max-width:980px">

    <?php if (!$inApp): ?>
      <div class="crumb"><a href="<?= url('index.php') ?>">Home</a> &rsaquo;
        <?php if ($for === 'game'): ?><a href="<?= url('games.php') ?>">Games</a> &rsaquo; <?php endif; ?>
        Payment</div>
    <?php endif; ?>

    <?= render_flash() ?>
    <?php if ($error): ?>
      <div class="alert alert-error" data-persist="1"><span>&#9888;</span><?= e($error) ?></div>
    <?php endif; ?>

    <div class="pay-grid">

      <!-- what you are buying -->
      <div class="card pay-item reveal">
        <div class="pay-art"><img src="<?= e($thumb) ?>" alt="<?= e($title) ?>"></div>
        <div class="pay-body">
          <span class="badge <?= $for === 'game' ? 'badge-gold' : 'badge-green' ?>">
            <?= $for === 'game' ? 'Game pass' : 'One-time' ?></span>
          <h1 class="pay-title"><?= e($title) ?></h1>
          <p class="text-muted" style="margin:0 0 14px"><?= e($sub) ?></p>

          <?php if ($for === 'game' && !empty($active)): ?>
            <div class="alert alert-info" data-persist="1"><span>&#8505;</span>
              Your pass is active until <?= fdate($active['expires_at'], 'd M Y, h:i A') ?>.</div>
          <?php endif; ?>

          <ul class="pay-perks">
            <?php foreach ($perks as $p): ?><li><?= e($p) ?></li><?php endforeach; ?>
          </ul>
          <?php if ($for === 'registration' && setting('reg_fee_note') !== ''): ?>
            <p class="text-dim" style="margin:12px 0 0"><?= e(setting('reg_fee_note')) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <!-- how to pay -->
      <div class="card pay-box reveal">
        <div class="pay-total">
          <span class="text-dim">Total</span>
          <strong><?= money($amount) ?></strong>
          <?php if ($days !== null): ?><span class="text-dim">for <?= e(days_label($days)) ?></span><?php endif; ?>
        </div>

        <?php if (!$gateways): ?>
          <div class="alert alert-info" data-persist="1"><span>&#8505;</span>
            Online payment is not open yet. Please contact us and we will activate it for you.</div>
          <a class="btn btn-ghost btn-block" href="<?= url('contact.php') ?>">Contact us</a>

        <?php elseif ($checkout): ?>
          <div class="pay-wait" id="payWait">
            <div class="pay-spinner"></div>
            <p style="margin:12px 0 4px;font-weight:700">Opening secure checkout…</p>
            <p class="text-dim" style="margin:0">Order <?= e($checkout['ref']) ?></p>
          </div>
          <div id="payRetry" hidden>
            <p class="text-muted center">The payment window was closed.</p>
            <button class="btn btn-primary btn-block" type="button" id="payAgain">Try again</button>
            <a class="btn btn-ghost btn-block mt-2" href="<?= e(url('pay.php?for=' . $for
                . ($key ? '&item=' . urlencode($key) : '') . ($inApp ? '&app=1' : ''))) ?>">Choose another method</a>
          </div>

        <?php else: ?>
          <form method="post" id="payForm">
            <?= csrf_field() ?>
            <div class="form-label" style="margin-bottom:10px">Pay with</div>
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
              <div class="form-hint">Your payment receipt is sent here and to <?= e($u['email']) ?></div>
            </div>

            <button class="btn btn-primary btn-block btn-lg" type="submit">
              Pay <?= money($amount) ?> securely &rarr;</button>
          </form>
        <?php endif; ?>

        <p class="text-dim center" style="margin:14px 0 0;font-size:.8rem">
          &#128274; <?= e(setting('pay_brand_note', 'Payments are processed securely by our payment partner.')) ?>
          We never see or store your card or UPI details.</p>

        <?php if ($for === 'registration' && !$inApp): ?>
          <p class="center" style="margin:10px 0 0;font-size:.85rem">
            <a href="<?= url('logout.php') ?>" class="text-dim">Not now — log out</a></p>
        <?php endif; ?>
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
    amount: <?= (int) $amount ?>,
    currency: <?= json_encode(pay_currency()) ?>,
    name: <?= json_encode(setting('site_name', 'Asviora Games')) ?>,
    description: <?= json_encode($title . ($days !== null ? ' — ' . days_label($days) : '')) ?>,
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
  function open() {
    var rz = new Razorpay(opts);
    rz.on('payment.failed', function () { /* the modal shows the reason and lets them retry */ });
    rz.open();
  }
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
  var cf = Cashfree({ mode: <?= json_encode(setting('pay_cashfree_mode', 'sandbox') === 'production'
                                             ? 'production' : 'sandbox') ?> });
  function open() {
    cf.checkout({ paymentSessionId: <?= json_encode($checkout['session']) ?>, redirectTarget: '_self' });
  }
  document.getElementById('payAgain').addEventListener('click', open);
  open();
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
