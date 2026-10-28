<?php
/**
 * Admin — Game Plans  (v7)
 *
 * Decide, per game, whether it is FREE or PAID (subscription). A paid game
 * has a price and a number of days; a player who pays gets that game for
 * that long, on the website and in the app. The Quiz Contest, the Daily
 * Spin and Chess can be made paid the same way.
 *
 * Below the plans: every active pass, with "give a pass" and "revoke".
 */
require_once __DIR__ . '/includes/auth.php';
$A = require_admin();
require_manage();

$admin_title = 'Game Plans';
$admin_sub   = 'Free or paid — per game subscription prices';

$installed = has_table('game_plans') && has_table('subscriptions');

/** Everything a plan can be put on: every game row (hidden ones too) + quiz / spin / chess. */
function plan_items(): array
{
    $out = [];
    foreach (fetch_all('SELECT slug, title, category, thumbnail, icon, is_active FROM games
                         ORDER BY sort_order, title') as $g) {
        $out[$g['slug']] = ['title' => $g['title'], 'cat' => $g['category'],
                            'thumb' => game_thumb($g['slug'], $g['thumbnail']),
                            'icon' => $g['icon'] ?: '&#127918;', 'live' => (int) $g['is_active'] === 1];
    }
    if (has_table('quiz_questions')) {
        $out['quiz'] = ['title' => 'Quiz Contest', 'cat' => 'Quiz', 'thumb' => game_thumb('quiz'),
                        'icon' => '&#129504;', 'live' => quiz_on()];
    }
    if (has_table('spin_results')) {
        $out['spin'] = ['title' => 'Daily Spin', 'cat' => 'Rewards', 'thumb' => game_thumb('spin'),
                        'icon' => '&#127920;', 'live' => spin_on()];
    }
    if (!isset($out['chess']) && function_exists('chess_on')) {
        $out['chess'] = ['title' => 'Chess', 'cat' => 'Strategy', 'thumb' => game_thumb('chess'),
                         'icon' => '&#9822;', 'live' => chess_on()];
    }
    return $out;
}

/* ================================================================
   ACTIONS
   ================================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok() && $installed) {
    $do = post('do');

    if ($do === 'plans') {
        $items = plan_items();
        $n = 0;
        foreach ($items as $key => $_) {
            $f    = $_POST['plan'][$key] ?? [];
            $paid = !empty($f['paid']) ? 1 : 0;
            $price = paise_from_input((string) ($f['price'] ?? '0'));
            $days  = max(0, min(3650, (int) ($f['days'] ?? 30)));
            $label = mb_substr(trim((string) ($f['label'] ?? '')), 0, 80);
            if ($paid && $price <= 0) {
                flash($items[$key]['title'] . ': a paid game needs a price — left as free.', 'error');
                $paid = 0;
            }
            q('INSERT INTO game_plans (item_key, is_paid, price_paise, duration_days, label)
               VALUES (?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE is_paid = VALUES(is_paid), price_paise = VALUES(price_paise),
                                       duration_days = VALUES(duration_days), label = VALUES(label)',
              [$key, $paid, $price, $days, $label !== '' ? $label : null]);
            $n += $paid;
        }
        flash('Plans saved — ' . $n . ' paid ' . ($n === 1 ? 'game' : 'games') . '.');
        if ($n > 0 && !pay_enabled()) {
            flash('No payment gateway is live yet, so players cannot buy these passes. Set one up in Payments → Gateways.', 'error');
        }
        redirect('admin/game-plans.php');
    }

    if ($do === 'grant') {
        $who  = strtolower(trim(post('who')));
        $usr  = fetch_one('SELECT id, username FROM users WHERE username = ? OR email = ?', [$who, $who]);
        $key  = post('item');
        $days = max(1, (int) post('days'));
        if (!$usr) {
            flash('No player found with that username or email.', 'error');
        } elseif (!isset(plan_items()[$key])) {
            flash('Pick a game.', 'error');
        } else {
            sub_grant((int) $usr['id'], $key, $days, null, 'admin',
                      'Given by ' . $A['name'] . (post('note') !== '' ? ' — ' . post('note') : ''));
            flash($usr['username'] . ' now has ' . plan_items()[$key]['title'] . ' for ' . days_label($days) . '.');
        }
        redirect('admin/game-plans.php#passes');
    }

    if ($do === 'revoke') {
        q('UPDATE subscriptions SET status = "revoked" WHERE user_id = ? AND item_key = ? AND status = "active"',
          [(int) post('uid'), post('item')]);
        flash('Pass revoked.', 'info');
        redirect('admin/game-plans.php#passes');
    }
}

$items = $installed ? plan_items() : [];
$plans = $installed ? game_plans_all(true) : [];

$passes = $installed ? fetch_all(
    'SELECT s.user_id, s.item_key, MAX(s.expires_at) AS expires_at, MIN(s.starts_at) AS since,
            GROUP_CONCAT(DISTINCT s.source) AS sources, u.username, u.email
       FROM subscriptions s JOIN users u ON u.id = s.user_id
      WHERE s.status = "active" AND s.expires_at > NOW()
      GROUP BY s.user_id, s.item_key, u.username, u.email
      ORDER BY expires_at ASC LIMIT 300') : [];

require __DIR__ . '/includes/layout.php';
?>

<?php if (!$installed): ?>
  <div class="adm-panel"><div class="adm-panel-body"><div class="empty"><div class="em-ico">&#127915;</div>
    <h3>Not installed yet</h3><p>Open any page of the site once (or run <code>v7migration.php</code>).</p></div></div></div>
<?php require __DIR__ . '/includes/layout_end.php'; exit; endif; ?>

<?php if (!module_on('payments')): ?>
  <div class="alert alert-info" data-persist="1"><span>&#8505;</span>
    <span>The Payments module is switched off, so every game is free right now. Turn it on in
      <a href="<?= admin_url('payments.php?tab=gateways') ?>">Payments → Gateways</a>.</span></div>
<?php elseif (!pay_enabled()): ?>
  <div class="alert alert-info" data-persist="1"><span>&#8505;</span>
    <span>No payment gateway is live yet — set up Razorpay or Cashfree in
      <a href="<?= admin_url('payments.php?tab=gateways') ?>">Payments → Gateways</a> before making a game paid.</span></div>
<?php endif; ?>

<form method="post">
  <?= csrf_field() ?><input type="hidden" name="do" value="plans">
  <div class="adm-panel">
    <div class="adm-panel-head"><div><h2>Free or paid</h2>
      <p>Tick “Paid”, set the price and how many days one payment buys. 0 days = lifetime.</p></div></div>
    <div class="adm-panel-body flush">
      <div class="table-scroll">
        <table class="tbl plan-tbl" style="min-width:860px">
          <thead><tr><th>Game</th><th>Paid?</th><th>Price (₹)</th><th>Days</th><th>Plan name</th><th>Players with a pass</th></tr></thead>
          <tbody>
          <?php foreach ($items as $key => $it):
                $p = $plans[$key] ?? null;
                $isPaid = $p && (int) $p['is_paid'] === 1;
                $holders = (int) fetch_col('SELECT COUNT(DISTINCT user_id) FROM subscriptions
                              WHERE item_key = ? AND status = "active" AND expires_at > NOW()', [$key], 0); ?>
            <tr class="<?= $isPaid ? 'is-paid' : '' ?>">
              <td>
                <div class="row" style="gap:12px;align-items:center;flex-wrap:nowrap">
                  <?php if ($it['thumb']): ?>
                    <img src="<?= e($it['thumb']) ?>" alt="" width="46" height="46" style="border-radius:10px;object-fit:cover">
                  <?php else: ?><span style="font-size:1.6rem"><?= $it['icon'] ?></span><?php endif; ?>
                  <div><strong><?= e($it['title']) ?></strong>
                    <div class="text-dim" style="font-size:.78rem"><?= e($it['cat']) ?><?= $it['live'] ? '' : ' · hidden' ?></div></div>
                </div>
              </td>
              <td>
                <label class="switch-mini">
                  <input type="checkbox" name="plan[<?= e($key) ?>][paid]" value="1" <?= $isPaid ? 'checked' : '' ?>>
                  <span><?= $isPaid ? 'Paid' : 'Free' ?></span>
                </label>
              </td>
              <td><input class="form-control" style="max-width:110px" inputmode="decimal"
                         name="plan[<?= e($key) ?>][price]"
                         value="<?= $p ? e(number_format($p['price_paise'] / 100, 2, '.', '')) : '' ?>" placeholder="49"></td>
              <td><input class="form-control" style="max-width:90px" type="number" min="0" max="3650"
                         name="plan[<?= e($key) ?>][days]" value="<?= $p ? (int) $p['duration_days'] : 30 ?>"></td>
              <td><input class="form-control" maxlength="80" name="plan[<?= e($key) ?>][label]"
                         value="<?= e($p['label'] ?? '') ?>" placeholder="Monthly pass"></td>
              <td><?= num($holders) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <button class="btn btn-primary" type="submit">Save plans</button>
</form>

<div class="adm-form-grid mt-4" id="passes">
  <div class="adm-panel">
    <div class="adm-panel-head"><div><h2>Give a pass</h2><p>Free access without payment (prize, promotion, support)</p></div></div>
    <div class="adm-panel-body">
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="do" value="grant">
        <div class="form-group"><label class="form-label" for="who">Username or email</label>
          <input class="form-control" id="who" name="who" required></div>
        <div class="row" style="gap:12px">
          <div class="form-group" style="flex:2"><label class="form-label" for="item">Game</label>
            <select class="form-select" id="item" name="item">
              <?php foreach ($items as $key => $it): ?>
                <option value="<?= e($key) ?>"><?= e($it['title']) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="form-group" style="flex:1"><label class="form-label" for="days">Days</label>
            <input class="form-control" type="number" min="1" max="3650" id="days" name="days" value="30"></div>
        </div>
        <div class="form-group"><label class="form-label" for="note">Note</label>
          <input class="form-control" id="note" name="note" maxlength="120" placeholder="Tournament winner"></div>
        <button class="btn btn-ghost" type="submit">Give pass</button>
      </form>
    </div>
  </div>

  <div class="adm-panel">
    <div class="adm-panel-head"><div><h2>Active passes</h2><p>Soonest to expire first</p></div></div>
    <div class="adm-panel-body flush">
      <?php if (!$passes): ?>
        <div class="empty"><div class="em-ico">&#127915;</div><h3>No active passes</h3></div>
      <?php else: ?>
        <div class="table-scroll" style="max-height:460px">
          <table class="tbl">
            <thead><tr><th>Player</th><th>Game</th><th>Until</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($passes as $s): ?>
              <tr>
                <td><strong><?= e($s['username']) ?></strong><div class="text-dim" style="font-size:.75rem"><?= e($s['email']) ?></div></td>
                <td><?= e($items[$s['item_key']]['title'] ?? $s['item_key']) ?>
                  <?php if (str_contains((string) $s['sources'], 'admin')): ?><span class="badge">given</span><?php endif; ?></td>
                <td class="nowrap"><?= fdate($s['expires_at'], 'd M Y') ?></td>
                <td><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="revoke">
                  <input type="hidden" name="uid" value="<?= (int) $s['user_id'] ?>">
                  <input type="hidden" name="item" value="<?= e($s['item_key']) ?>">
                  <button class="btn btn-ghost btn-xs" type="submit" data-confirm="Revoke this pass?">Revoke</button></form></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<style>
.plan-tbl tr.is-paid td { background: rgba(217,166,60,.05); }
.switch-mini { display:inline-flex; align-items:center; gap:8px; cursor:pointer; font-weight:700; }
.switch-mini input { width:18px; height:18px; accent-color: var(--gold); }
</style>
<script>
document.querySelectorAll('.switch-mini input').forEach(function (c) {
  c.addEventListener('change', function () {
    c.nextElementSibling.textContent = c.checked ? 'Paid' : 'Free';
    c.closest('tr').classList.toggle('is-paid', c.checked);
  });
});
</script>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
