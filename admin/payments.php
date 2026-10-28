<?php
/**
 * Admin — Payments  (v7)
 *
 *   Transactions   every order, filters, CSV, mark paid / refunded
 *   Gateways       Razorpay + Cashfree keys, on/off, which one shows first
 *   Registration   the one-time registration fee: on/off, amount, who pays
 *
 * Game prices live on their own screen: Admin -> Game Plans.
 */
require_once __DIR__ . '/includes/auth.php';
$A = require_admin();
require_manage();

$admin_title = 'Payments';
$admin_sub   = 'Gateways, registration fee and every transaction';

$installed = has_table('payments');
$tab = get('tab', 'transactions');
if (!in_array($tab, ['transactions', 'gateways', 'registration'], true)) {
    $tab = 'transactions';
}

/* ================================================================
   ACTIONS
   ================================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok() && $installed) {
    $do = post('do');

    // ---- gateways ---------------------------------------------------
    if ($do === 'gateways') {
        set_setting('pay_razorpay_enabled', empty($_POST['rzp_on']) ? '0' : '1');
        set_setting('pay_razorpay_key_id', post('rzp_key'));
        if (post('rzp_secret') !== '') {
            set_setting('pay_razorpay_key_secret', post('rzp_secret'));
        }
        if (post('rzp_hook') !== '') {
            set_setting('pay_razorpay_webhook_secret', post('rzp_hook'));
        }

        set_setting('pay_cashfree_enabled', empty($_POST['cf_on']) ? '0' : '1');
        set_setting('pay_cashfree_mode', post('cf_mode') === 'production' ? 'production' : 'sandbox');
        set_setting('pay_cashfree_app_id', post('cf_app'));
        if (post('cf_secret') !== '') {
            set_setting('pay_cashfree_secret', post('cf_secret'));
        }

        $first = post('first') === 'cashfree' ? 'cashfree' : 'razorpay';
        set_setting('pay_order', $first === 'cashfree' ? 'cashfree,razorpay' : 'razorpay,cashfree');
        set_setting('pay_brand_note', mb_substr(post('brand_note'), 0, 190));

        if (has_table('modules')) {
            module_set('payments', !empty($_POST['module_on']), (int) $A['id']);
        }

        // a gateway switched on without its keys is the usual mistake
        foreach (['razorpay', 'cashfree'] as $g) {
            if (setting('pay_' . $g . '_enabled') === '1' && !pay_gateway_configured($g)) {
                flash(PAY_GATEWAYS[$g] . ' is switched on but its keys are missing — players will not see it.', 'error');
            }
        }
        flash('Gateway settings saved.');
        redirect('admin/payments.php?tab=gateways');
    }

    // ---- test a gateway's keys --------------------------------------
    if ($do === 'test') {
        $g = post('gw');
        if ($g === 'razorpay') {
            [$code, $j] = pay_http('GET', 'https://api.razorpay.com/v1/orders?count=1', rzp_auth());
            $code === 200
                ? flash('Razorpay keys work — connected.')
                : flash('Razorpay said: ' . ($j['error']['description'] ?? ('HTTP ' . $code)), 'error');
        } elseif ($g === 'cashfree') {
            [$code, $j] = pay_http('GET', cf_base() . '/orders/asv-key-test-' . time(), cf_headers());
            // 404 "order not found" means the keys were accepted
            ($code === 404 || $code === 200)
                ? flash('Cashfree keys work — connected (' . setting('pay_cashfree_mode') . ').')
                : flash('Cashfree said: ' . ($j['message'] ?? ('HTTP ' . $code)), 'error');
        }
        redirect('admin/payments.php?tab=gateways');
    }

    // ---- registration fee -------------------------------------------
    if ($do === 'regfee') {
        $wasOn = setting('reg_fee_enabled', '0') === '1';
        $on    = !empty($_POST['fee_on']);
        set_setting('reg_fee_enabled', $on ? '1' : '0');
        set_setting('reg_fee_amount', (string) paise_from_input(post('fee_amount')));
        set_setting('reg_fee_apply_existing', empty($_POST['fee_existing']) ? '0' : '1');
        set_setting('reg_fee_note', mb_substr(post('fee_note'), 0, 300));
        // the fee applies to accounts created from the moment it is switched on
        if ($on && (!$wasOn || setting('reg_fee_since') === '')) {
            // the database clock, so it compares like-for-like with users.created_at
            set_setting('reg_fee_since', (string) fetch_col('SELECT NOW()', [], date('Y-m-d H:i:s')));
        }
        if ($on && (int) setting('reg_fee_amount') <= 0) {
            flash('The fee is switched on but the amount is 0, so nobody is asked to pay.', 'error');
        }
        if ($on && !pay_enabled()) {
            flash('No payment gateway is ready yet — set one up under Gateways, or players cannot pay the fee.', 'error');
        }
        flash('Registration fee saved.');
        redirect('admin/payments.php?tab=registration');
    }

    // ---- let a player off the fee -----------------------------------
    if ($do === 'waive') {
        $who = trim(post('who'));
        $usr = fetch_one('SELECT * FROM users WHERE username = ? OR email = ?',
                         [strtolower($who), strtolower($who)]);
        if (!$usr) {
            flash('No player found with that username or email.', 'error');
        } elseif (reg_fee_paid((int) $usr['id'])) {
            flash($usr['username'] . ' has already paid or been let off.', 'info');
        } else {
            $p = pay_create((int) $usr['id'], 'registration', null, 0, null, 'manual', 'admin');
            q('UPDATE payments SET note = ? WHERE id = ?',
              ['Waived by ' . $A['name'] . (post('why') !== '' ? ' — ' . mb_substr(post('why'), 0, 150) : ''),
               (int) $p['id']]);
            pay_mark_paid($p, '', ['by_admin' => (int) $A['id']]);
            flash('Registration fee waived for ' . $usr['username'] . '.');
        }
        redirect('admin/payments.php?tab=registration');
    }

    // ---- an order paid outside the gateway (cash, bank transfer) ----
    if ($do === 'markpaid') {
        $p = fetch_one('SELECT * FROM payments WHERE id = ?', [(int) post('id')]);
        if ($p && $p['status'] !== 'paid') {
            q('UPDATE payments SET note = ? WHERE id = ?',
              [mb_substr('Marked paid by ' . $A['name'] . ' ' . post('why'), 0, 250), (int) $p['id']]);
            pay_mark_paid($p, post('ref_no'), ['by_admin' => (int) $A['id'], 'was' => $p['status']]);
            flash('Order ' . $p['order_ref'] . ' marked paid' .
                  ($p['purpose'] === 'subscription' ? ' and the pass activated.' : '.'));
        }
        redirect('admin/payments.php');
    }

    // ---- refunded in the gateway dashboard -> record it here --------
    if ($do === 'refund') {
        $p = fetch_one('SELECT * FROM payments WHERE id = ?', [(int) post('id')]);
        if ($p && $p['status'] === 'paid') {
            q('UPDATE payments SET status = "refunded", note = ? WHERE id = ?',
              [mb_substr('Refunded — recorded by ' . $A['name'], 0, 250), (int) $p['id']]);
            q('UPDATE subscriptions SET status = "revoked" WHERE payment_id = ?', [(int) $p['id']]);
            flash('Order ' . $p['order_ref'] . ' marked refunded. Any pass it bought was switched off. '
                . 'Remember to issue the actual refund from the gateway dashboard.', 'info');
        }
        redirect('admin/payments.php');
    }
}

/* ================================================================
   CSV
   ================================================================ */
if ($installed && get('export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payments-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Order', 'Player', 'Email', 'For', 'Item', 'Days', 'Amount', 'Currency',
                   'Gateway', 'Gateway order', 'Gateway payment', 'Status', 'Source', 'Created', 'Paid']);
    foreach (fetch_all('SELECT p.*, u.username, u.email FROM payments p
                        LEFT JOIN users u ON u.id = p.user_id ORDER BY p.id DESC') as $r) {
        fputcsv($out, [$r['order_ref'], $r['username'], $r['email'], $r['purpose'], $r['item_key'],
                       $r['plan_days'], number_format($r['amount_paise'] / 100, 2, '.', ''),
                       $r['currency'], $r['gateway'], $r['gw_order_id'], $r['gw_payment_id'],
                       $r['status'], $r['source'], $r['created_at'], $r['paid_at']]);
    }
    fclose($out);
    exit;
}

/* ================================================================
   DATA
   ================================================================ */
$stats = ['total' => 0, 'month' => 0, 'today' => 0, 'paid' => 0, 'pending' => 0, 'subs' => 0];
$rows  = [];
if ($installed) {
    $stats['total']   = (int) fetch_col('SELECT COALESCE(SUM(amount_paise),0) FROM payments WHERE status = "paid"', [], 0);
    $stats['month']   = (int) fetch_col('SELECT COALESCE(SUM(amount_paise),0) FROM payments
                                          WHERE status = "paid" AND paid_at >= DATE_FORMAT(NOW(), "%Y-%m-01")', [], 0);
    $stats['today']   = (int) fetch_col('SELECT COALESCE(SUM(amount_paise),0) FROM payments
                                          WHERE status = "paid" AND paid_at >= CURDATE()', [], 0);
    $stats['paid']    = (int) fetch_col('SELECT COUNT(*) FROM payments WHERE status = "paid" AND gateway <> "manual"', [], 0);
    $stats['pending'] = (int) fetch_col('SELECT COUNT(*) FROM payments WHERE status = "created"
                                          AND created_at > (NOW() - INTERVAL 1 DAY)', [], 0);
    $stats['subs']    = (int) fetch_col('SELECT COUNT(DISTINCT user_id, item_key) FROM subscriptions
                                          WHERE status = "active" AND expires_at > NOW()', [], 0);

    $fStatus  = get('status');
    $fPurpose = get('purpose');
    $fQ       = get('q');
    $where = ['1=1'];
    $par   = [];
    if (in_array($fStatus, ['paid', 'created', 'failed', 'refunded'], true)) {
        $where[] = 'p.status = ?';
        $par[]   = $fStatus;
    }
    if (in_array($fPurpose, ['registration', 'subscription'], true)) {
        $where[] = 'p.purpose = ?';
        $par[]   = $fPurpose;
    }
    if ($fQ !== '') {
        $where[] = '(p.order_ref LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR p.gw_payment_id LIKE ?)';
        array_push($par, "%$fQ%", "%$fQ%", "%$fQ%", "%$fQ%");
    }
    $rows = fetch_all('SELECT p.*, u.username, u.email FROM payments p
                       LEFT JOIN users u ON u.id = p.user_id
                       WHERE ' . implode(' AND ', $where) . ' ORDER BY p.id DESC LIMIT 300', $par);
}

$admin_actions = $installed && $tab === 'transactions'
    ? '<a href="' . admin_url('payments.php?export=csv') . '" class="btn btn-ghost btn-sm">Download CSV</a>' : '';

require __DIR__ . '/includes/layout.php';
?>

<?php if (!$installed): ?>
  <div class="adm-panel"><div class="adm-panel-body">
    <div class="empty"><div class="em-ico">&#128179;</div><h3>Not installed yet</h3>
      <p>Open any page of the site once (or run <code>v7migration.php</code>) to create the payment tables.</p></div>
  </div></div>
<?php require __DIR__ . '/includes/layout_end.php'; exit; endif; ?>

<div class="pay-stats">
  <div class="card stat-card"><div class="sc-num"><?= money($stats['total']) ?></div><div class="sc-lbl">Collected, all time</div></div>
  <div class="card stat-card"><div class="sc-num"><?= money($stats['month']) ?></div><div class="sc-lbl">This month</div></div>
  <div class="card stat-card"><div class="sc-num"><?= money($stats['today']) ?></div><div class="sc-lbl">Today</div></div>
  <div class="card stat-card"><div class="sc-num"><?= num($stats['subs']) ?></div><div class="sc-lbl">Active game passes</div></div>
</div>

<div class="tabs" style="margin-bottom:18px">
  <a href="<?= admin_url('payments.php') ?>" class="<?= $tab === 'transactions' ? 'active' : '' ?>">Transactions</a>
  <a href="<?= admin_url('payments.php?tab=gateways') ?>" class="<?= $tab === 'gateways' ? 'active' : '' ?>">Gateways
    <?php if (!pay_enabled()): ?><span class="tab-n">off</span><?php endif; ?></a>
  <a href="<?= admin_url('payments.php?tab=registration') ?>" class="<?= $tab === 'registration' ? 'active' : '' ?>">Registration fee
    <?php if (reg_fee_amount() > 0): ?><span class="tab-n"><?= money(reg_fee_amount()) ?></span><?php endif; ?></a>
  <a href="<?= admin_url('game-plans.php') ?>">Game plans &nearr;</a>
</div>

<?php if ($tab === 'gateways'): /* ======================= GATEWAYS ======================= */ ?>

<form method="post">
  <?= csrf_field() ?><input type="hidden" name="do" value="gateways">

  <div class="adm-panel">
    <div class="adm-panel-head"><div><h2>Payments module</h2>
      <p>Switch everything paid off in one click — every game becomes free, nothing is deleted.</p></div></div>
    <div class="adm-panel-body">
      <label class="check"><input type="checkbox" name="module_on" value="1" <?= module_on('payments') ? 'checked' : '' ?>>
        <span>Payments, registration fee and paid game plans are <strong>on</strong></span></label>
      <div class="row mt-3" style="gap:14px;flex-wrap:wrap">
        <div class="form-group" style="flex:1;min-width:220px">
          <label class="form-label" for="first">Show first on the pay page</label>
          <select class="form-select" id="first" name="first">
            <option value="razorpay" <?= str_starts_with(setting('pay_order'), 'razorpay') ? 'selected' : '' ?>>Razorpay</option>
            <option value="cashfree" <?= str_starts_with(setting('pay_order'), 'cashfree') ? 'selected' : '' ?>>Cashfree</option>
          </select>
        </div>
        <div class="form-group" style="flex:2;min-width:260px">
          <label class="form-label" for="brand_note">Note under the pay button</label>
          <input class="form-control" id="brand_note" name="brand_note" maxlength="190"
                 value="<?= e(setting('pay_brand_note')) ?>">
        </div>
      </div>
    </div>
  </div>

  <div class="adm-form-grid">
    <!-- RAZORPAY -->
    <div class="adm-panel">
      <div class="adm-panel-head"><div><h2>&#9889; Razorpay</h2>
        <p>Dashboard → Account &amp; Settings → API Keys</p></div>
        <span class="badge <?= setting('pay_razorpay_enabled') === '1' && pay_gateway_configured('razorpay') ? 'badge-green' : '' ?>">
          <?= setting('pay_razorpay_enabled') === '1' && pay_gateway_configured('razorpay') ? 'Live' : 'Off' ?></span></div>
      <div class="adm-panel-body">
        <label class="check"><input type="checkbox" name="rzp_on" value="1" <?= setting('pay_razorpay_enabled') === '1' ? 'checked' : '' ?>>
          <span>Offer Razorpay</span></label>
        <div class="form-group mt-2">
          <label class="form-label" for="rzp_key">Key ID</label>
          <input class="form-control" id="rzp_key" name="rzp_key" placeholder="rzp_live_… or rzp_test_…"
                 value="<?= e(setting('pay_razorpay_key_id')) ?>" autocomplete="off">
          <p class="form-hint"><?= str_starts_with(setting('pay_razorpay_key_id'), 'rzp_test_')
              ? '<span class="text-gold">Test key — no real money moves.</span>' : 'Use an rzp_test_ key first to try it out.' ?></p>
        </div>
        <div class="form-group">
          <label class="form-label" for="rzp_secret">Key Secret</label>
          <input class="form-control" type="password" id="rzp_secret" name="rzp_secret" autocomplete="new-password"
                 placeholder="<?= setting('pay_razorpay_key_secret') !== '' ? '•••••••• saved — leave blank to keep' : 'Paste the secret' ?>">
        </div>
        <div class="form-group">
          <label class="form-label" for="rzp_hook">Webhook secret <span class="text-dim">(recommended)</span></label>
          <input class="form-control" type="password" id="rzp_hook" name="rzp_hook" autocomplete="new-password"
                 placeholder="<?= setting('pay_razorpay_webhook_secret') !== '' ? '•••••••• saved — leave blank to keep' : 'Any strong text — same as in Razorpay' ?>">
          <p class="form-hint">Webhook URL: <code><?= e(url('pay-webhook.php?gw=razorpay')) ?></code><br>
            Events: <code>payment.captured</code>, <code>order.paid</code>, <code>payment.failed</code></p>
        </div>
      </div>
    </div>

    <!-- CASHFREE -->
    <div class="adm-panel">
      <div class="adm-panel-head"><div><h2>&#9670; Cashfree</h2>
        <p>Dashboard → Developers → API Keys (Payment Gateway)</p></div>
        <span class="badge <?= setting('pay_cashfree_enabled') === '1' && pay_gateway_configured('cashfree') ? 'badge-green' : '' ?>">
          <?= setting('pay_cashfree_enabled') === '1' && pay_gateway_configured('cashfree') ? 'Live' : 'Off' ?></span></div>
      <div class="adm-panel-body">
        <label class="check"><input type="checkbox" name="cf_on" value="1" <?= setting('pay_cashfree_enabled') === '1' ? 'checked' : '' ?>>
          <span>Offer Cashfree</span></label>
        <div class="form-group mt-2">
          <label class="form-label" for="cf_mode">Mode</label>
          <select class="form-select" id="cf_mode" name="cf_mode">
            <option value="sandbox" <?= setting('pay_cashfree_mode') !== 'production' ? 'selected' : '' ?>>Sandbox (test)</option>
            <option value="production" <?= setting('pay_cashfree_mode') === 'production' ? 'selected' : '' ?>>Production (real money)</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label" for="cf_app">App ID (Client ID)</label>
          <input class="form-control" id="cf_app" name="cf_app" autocomplete="off"
                 value="<?= e(setting('pay_cashfree_app_id')) ?>">
        </div>
        <div class="form-group">
          <label class="form-label" for="cf_secret">Secret Key</label>
          <input class="form-control" type="password" id="cf_secret" name="cf_secret" autocomplete="new-password"
                 placeholder="<?= setting('pay_cashfree_secret') !== '' ? '•••••••• saved — leave blank to keep' : 'Paste the secret key' ?>">
          <p class="form-hint">Webhook URL (sent automatically with every order, or add it under Developers → Webhooks):
            <code><?= e(url('pay-webhook.php?gw=cashfree')) ?></code><br>
            Your domain must be whitelisted in Cashfree for production.</p>
        </div>
      </div>
    </div>
  </div>

  <button class="btn btn-primary" type="submit">Save gateway settings</button>
</form>

<div class="row mt-3" style="gap:10px">
  <?php foreach (['razorpay', 'cashfree'] as $g): if (!pay_gateway_configured($g)) continue; ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="test">
      <input type="hidden" name="gw" value="<?= $g ?>">
      <button class="btn btn-ghost btn-sm" type="submit">Test <?= PAY_GATEWAYS[$g] ?> keys</button></form>
  <?php endforeach; ?>
</div>

<?php elseif ($tab === 'registration'): /* ======================= REG FEE ======================= */ ?>

<div class="adm-form-grid">
  <div class="adm-panel">
    <div class="adm-panel-head"><div><h2>Registration fee</h2>
      <p>A one-time fee, paid right after sign-up (website and app)</p></div>
      <span class="badge <?= reg_fee_amount() > 0 ? 'badge-green' : '' ?>"><?= reg_fee_amount() > 0 ? 'On' : 'Off' ?></span></div>
    <div class="adm-panel-body">
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="do" value="regfee">
        <label class="check"><input type="checkbox" name="fee_on" value="1" <?= setting('reg_fee_enabled') === '1' ? 'checked' : '' ?>>
          <span>Charge a registration fee</span></label>

        <div class="form-group mt-2">
          <label class="form-label" for="fee_amount">Amount (₹)</label>
          <input class="form-control" id="fee_amount" name="fee_amount" inputmode="decimal" style="max-width:180px"
                 value="<?= e(number_format(((int) setting('reg_fee_amount', '0')) / 100, 2, '.', '')) ?>">
        </div>

        <label class="check"><input type="checkbox" name="fee_existing" value="1" <?= setting('reg_fee_apply_existing') === '1' ? 'checked' : '' ?>>
          <span>Existing players must pay too
            <span class="text-dim">(off = only accounts created after the fee was switched on<?=
              setting('reg_fee_since') !== '' ? ', i.e. after ' . fdate(setting('reg_fee_since'), 'd M Y H:i') : '' ?>)</span></span></label>

        <div class="form-group mt-2">
          <label class="form-label" for="fee_note">Note on the pay page</label>
          <textarea class="form-control" id="fee_note" name="fee_note" rows="2" maxlength="300"><?= e(setting('reg_fee_note')) ?></textarea>
        </div>
        <button class="btn btn-primary" type="submit">Save</button>
      </form>
    </div>
  </div>

  <div class="adm-panel">
    <div class="adm-panel-head"><div><h2>Let a player off</h2><p>Mark the fee as paid without charging</p></div></div>
    <div class="adm-panel-body">
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="do" value="waive">
        <div class="form-group">
          <label class="form-label" for="who">Username or email</label>
          <input class="form-control" id="who" name="who" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="why">Reason <span class="text-dim">(optional)</span></label>
          <input class="form-control" id="why" name="why" maxlength="150" placeholder="Paid in cash at the event">
        </div>
        <button class="btn btn-ghost" type="submit">Waive the fee</button>
      </form>
      <?php
      $feePaid = (int) fetch_col('SELECT COUNT(*) FROM payments WHERE purpose = "registration" AND status = "paid"', [], 0);
      $feeSum  = (int) fetch_col('SELECT COALESCE(SUM(amount_paise),0) FROM payments WHERE purpose = "registration" AND status = "paid"', [], 0);
      ?>
      <div class="kv mt-3"><span class="k">Players who have paid / been let off</span><span class="v"><?= num($feePaid) ?></span></div>
      <div class="kv"><span class="k">Collected from fees</span><span class="v text-gold"><?= money($feeSum) ?></span></div>
    </div>
  </div>
</div>

<?php else: /* ======================= TRANSACTIONS ======================= */ ?>

<?php if (!pay_enabled()): ?>
  <div class="alert alert-info" data-persist="1"><span>&#8505;</span>
    <span>No gateway is live yet. Add your Razorpay or Cashfree keys under
      <a href="<?= admin_url('payments.php?tab=gateways') ?>">Gateways</a>.</span></div>
<?php endif; ?>

<form class="row" style="gap:10px;flex-wrap:wrap;margin-bottom:14px" method="get">
  <input class="form-control" name="q" placeholder="Order, player, email or payment id" value="<?= e(get('q')) ?>" style="max-width:300px">
  <select class="form-select" name="status" style="max-width:170px">
    <option value="">Any status</option>
    <?php foreach (['paid' => 'Paid', 'created' => 'Pending', 'failed' => 'Failed', 'refunded' => 'Refunded'] as $k => $l): ?>
      <option value="<?= $k ?>" <?= get('status') === $k ? 'selected' : '' ?>><?= $l ?></option>
    <?php endforeach; ?>
  </select>
  <select class="form-select" name="purpose" style="max-width:190px">
    <option value="">Everything</option>
    <option value="registration" <?= get('purpose') === 'registration' ? 'selected' : '' ?>>Registration fee</option>
    <option value="subscription" <?= get('purpose') === 'subscription' ? 'selected' : '' ?>>Game passes</option>
  </select>
  <button class="btn btn-ghost btn-sm" type="submit">Filter</button>
</form>

<div class="adm-panel">
  <div class="adm-panel-body flush">
    <?php if (!$rows): ?>
      <div class="empty"><div class="em-ico">&#128179;</div><h3>No payments yet</h3>
        <p>Orders show up here the moment a player starts a payment.</p></div>
    <?php else: ?>
      <div class="table-scroll">
        <table class="tbl" style="min-width:980px">
          <thead><tr><th>Order</th><th>Player</th><th>For</th><th style="text-align:right">Amount</th>
            <th>Gateway</th><th>Status</th><th>When</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><strong><?= e($r['order_ref']) ?></strong>
                <?php if ($r['gw_payment_id']): ?><div class="text-dim" style="font-size:.75rem"><?= e($r['gw_payment_id']) ?></div><?php endif; ?></td>
              <td><?= e($r['username'] ?: '#' . $r['user_id']) ?><div class="text-dim" style="font-size:.75rem"><?= e($r['email']) ?></div></td>
              <td><?= $r['purpose'] === 'registration' ? 'Registration fee'
                    : e(pay_item_info((string) $r['item_key'])['title'] . ' · ' . days_label((int) $r['plan_days'])) ?>
                <?php if ($r['source'] === 'app'): ?><span class="badge">app</span><?php endif; ?></td>
              <td style="text-align:right;font-weight:800"><?= money((int) $r['amount_paise'], $r['currency']) ?></td>
              <td class="text-dim"><?= e(PAY_GATEWAYS[$r['gateway']] ?? ucfirst($r['gateway'])) ?></td>
              <td><span class="badge <?= ['paid' => 'badge-green', 'failed' => 'badge-red', 'refunded' => 'badge-gold'][$r['status']] ?? '' ?>"
                        title="<?= e($r['note'] ?? '') ?>"><?= e($r['status'] === 'created' ? 'pending' : $r['status']) ?></span></td>
              <td class="text-dim nowrap"><?= fdate($r['paid_at'] ?: $r['created_at'], 'd M Y, H:i') ?></td>
              <td class="nowrap">
                <?php if ($r['status'] !== 'paid' && $r['status'] !== 'refunded'): ?>
                  <form method="post" style="display:inline"><?= csrf_field() ?>
                    <input type="hidden" name="do" value="markpaid"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button class="btn btn-ghost btn-xs" type="submit"
                            data-confirm="Mark <?= e($r['order_ref']) ?> as paid? Only do this if the money really arrived.">Mark paid</button></form>
                <?php elseif ($r['status'] === 'paid' && $r['gateway'] !== 'manual'): ?>
                  <form method="post" style="display:inline"><?= csrf_field() ?>
                    <input type="hidden" name="do" value="refund"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button class="btn btn-ghost btn-xs" type="submit"
                            data-confirm="Record <?= e($r['order_ref']) ?> as refunded? Any pass it bought is switched off.">Refunded</button></form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php endif; ?>

<style>
.pay-stats { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:16px; margin-bottom:20px; }
@media (max-width: 1000px) { .pay-stats { grid-template-columns:repeat(2,minmax(0,1fr)); } }
</style>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
