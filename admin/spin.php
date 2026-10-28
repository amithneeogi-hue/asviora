<?php
/**
 * Admin — daily spin wheel.
 * Retune the prize segments and their odds, and review recent payouts.
 */
require_once __DIR__ . '/includes/auth.php';
$A = require_admin();
require_manage();

$admin_title = 'Daily Spin';
$admin_sub   = 'Prize segments, odds and payout history';

if (!has_table('spin_results')) {
    flash('The spin table is not installed. Import config/upgrade-quiz-spin.sql first.', 'error');
    redirect('admin/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $do = post('do');

    if ($do === 'save') {
        set_setting('spin_enabled', empty($_POST['spin_enabled']) ? '0' : '1');

        $coins   = $_POST['coins']  ?? [];
        $weights = $_POST['weight'] ?? [];
        $colors  = $_POST['color']  ?? [];

        $segments = [];
        foreach ($coins as $i => $c) {
            $w = (int) ($weights[$i] ?? 0);
            if ($w <= 0) {
                continue;                         // weight 0 removes the slice
            }
            $segments[] = [
                'coins'  => max(0, (int) $c),
                'weight' => $w,
                'color'  => preg_match('/^#[0-9a-f]{6}$/i', (string) ($colors[$i] ?? ''))
                            ? strtolower($colors[$i]) : '#8fce3f',
            ];
        }

        if (count($segments) < 2) {
            flash('The wheel needs at least two segments with a weight above zero.', 'error');
        } else {
            set_setting('spin_segments', json_encode($segments));
            flash('Wheel saved — ' . count($segments) . ' segments live.');
        }

    } elseif ($do === 'reset') {
        set_setting('spin_segments', '');
        flash('Wheel reset to the default prize table.');

    } elseif ($do === 'access_mode') {
        set_setting('spin_access_mode', post('mode') === 'assigned' ? 'assigned' : 'all');
        flash(post('mode') === 'assigned'
            ? 'Only players you assign can spin now.'
            : 'The spin is open to every player again.');

    } elseif ($do === 'limits') {
        // The site-wide spin limits. A player row on spin_access may
        // still override any of these — see Admin -> Spin Access.
        $perDay   = max(0, min(100,   (int) post('spin_default_per_day')));
        $cooldown = max(0, min(1440,  (int) post('spin_cooldown_minutes')));
        $weekly   = max(0, min(1000,  (int) post('spin_weekly_cap')));
        $monthly  = max(0, min(5000,  (int) post('spin_monthly_cap')));

        if ($weekly > 0 && $weekly < $perDay) {
            flash('The weekly cap cannot be lower than the daily allowance — '
                . 'players would never get a full day. Weekly cap left unchanged.', 'error');
        } else {
            set_setting('spin_weekly_cap', (string) $weekly);
        }
        if ($monthly > 0 && $weekly > 0 && $monthly < $weekly) {
            flash('The monthly cap cannot be lower than the weekly cap. '
                . 'Monthly cap left unchanged.', 'error');
        } else {
            set_setting('spin_monthly_cap', (string) $monthly);
        }

        set_setting('spin_default_per_day',  (string) $perDay);
        set_setting('spin_cooldown_minutes', (string) $cooldown);
        set_setting('spin_limit_note',       mb_substr(post('spin_limit_note'), 0, 190));
        flash('Spin limits saved — ' . $perDay . ' spin' . ($perDay === 1 ? '' : 's')
            . ' a day' . ($cooldown > 0 ? ', ' . $cooldown . ' min apart' : '') . '.');

    } elseif ($do === 'limit_reset') {
        // Clear every personal override so all players follow the site rule.
        if (spin_limits_installed()) {
            q('UPDATE spin_access SET cooldown_minutes = NULL,
                                      weekly_cap = NULL,
                                      monthly_cap = NULL');
        }
        flash('Personal limit overrides cleared — every player now follows the site limits.');

    } elseif ($do === 'assign') {
        // Per-player access lives in the `spin_access` table (schema v5).
        // This block used to write a `users.spin_access` column that the
        // v5 schema dropped, so the page died with "Unknown column".
        $name = post('username');
        $row  = fetch_one('SELECT id, username FROM users WHERE username = ? OR email = ?',
                          [$name, $name]);
        if (!$row) {
            flash('No player found with that username or email.', 'error');
        } elseif (!has_table('spin_access')) {
            flash('The spin access table is not installed yet — run cppsmigration.php once.',
                  'error');
        } else {
            $choice = post('access');
            if ($choice === 'default') {
                spin_access_clear((int) $row['id']);
                flash($row['username'] . ' now follows the site rule.');
            } else {
                $existing = spin_access_row((int) $row['id']);
                spin_access_set((int) $row['id'], [
                    'is_allowed'    => $choice !== 'blocked',
                    'spins_per_day' => $existing ? (int) $existing['spins_per_day']
                                                 : spin_default_per_day(),
                    'bonus_spins'   => $existing ? (int) $existing['bonus_spins'] : 0,
                    'valid_from'    => $existing['valid_from']  ?? null,
                    'valid_until'   => $existing['valid_until'] ?? null,
                    'note'          => $existing['note'] ?? '',
                ], (int) $A['id']);
                flash($row['username'] . ' — spin access set to '
                    . ($choice === 'blocked' ? 'blocked' : 'always allowed') . '.');
            }
        }

    } elseif ($do === 'unassign') {
        spin_access_clear((int) post('id'));
        flash('Back to the site rule for that player.');
    }

    redirect('admin/spin.php');
}

$segments = spin_segments();
$totalW   = max(1, array_sum(array_column($segments, 'weight')));
$enabled  = setting('spin_enabled', '1') === '1';

$recent = fetch_all(
    'SELECT s.*, u.username, u.name FROM spin_results s
       JOIN users u ON u.id = s.user_id
      ORDER BY s.id DESC LIMIT 40');

$stats = fetch_one(
    'SELECT COUNT(*) AS spins,
            COALESCE(SUM(coins),0) AS paid,
            COUNT(DISTINCT user_id) AS players
       FROM spin_results');

$todayStats = fetch_one(
    'SELECT COUNT(*) AS spins, COALESCE(SUM(coins),0) AS paid
       FROM spin_results WHERE spin_date = CURDATE()');

require __DIR__ . '/includes/layout.php';
?>

<?php
$mode = setting('spin_access_mode', 'all');

// Players with a personal rule. These live in `spin_access` (schema v5) —
// the old `users.spin_access` column this page used to read no longer
// exists, which is what made the page fatal on a v5 database.
$assigned = has_table('spin_access')
    ? fetch_all('SELECT u.id, u.username, u.name, a.is_allowed
                   FROM spin_access a JOIN users u ON u.id = a.user_id
                  ORDER BY a.is_allowed, u.username LIMIT 100')
    : [];
?>

<div class="adm-panel">
  <div class="adm-panel-head">
    <div><h2>Who may spin</h2>
      <p>Open to everyone, or only to the players you put on the list.</p></div>
    <span class="badge <?= $mode === 'assigned' ? 'badge-gold' : 'badge-green' ?>">
      <?= $mode === 'assigned' ? 'assigned players only' : 'everyone' ?></span>
  </div>
  <div class="adm-panel-body">
    <form method="post" class="row" style="gap:10px;align-items:flex-end;flex-wrap:wrap">
      <?= csrf_field() ?><input type="hidden" name="do" value="access_mode">
      <div class="form-group" style="margin:0">
        <label class="form-label" for="mode">Access mode</label>
        <select class="form-select" id="mode" name="mode">
          <option value="all" <?= $mode === 'all' ? 'selected' : '' ?>>Everyone can spin</option>
          <option value="assigned" <?= $mode === 'assigned' ? 'selected' : '' ?>>
            Only players I assign</option>
        </select>
      </div>
      <button class="btn btn-primary btn-sm" type="submit">Save mode</button>
    </form>

    <hr style="border:0;border-top:1px solid var(--line);margin:18px 0">

    <form method="post" class="row" style="gap:10px;align-items:flex-end;flex-wrap:wrap">
      <?= csrf_field() ?><input type="hidden" name="do" value="assign">
      <div class="form-group" style="margin:0">
        <label class="form-label" for="username">Player (username or email)</label>
        <input class="form-control" id="username" name="username" placeholder="rahul21" required>
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label" for="access">Access</label>
        <select class="form-select" id="access" name="access">
          <option value="allowed">Always allowed</option>
          <option value="blocked">Blocked</option>
          <option value="default">Follow the site rule</option>
        </select>
      </div>
      <button class="btn btn-primary btn-sm" type="submit">Apply</button>
    </form>

    <?php if ($assigned): ?>
      <div class="table-scroll mt-3">
        <table class="tbl" style="min-width:460px">
          <thead><tr><th>Player</th><th>Access</th><th style="text-align:right">Clear</th></tr></thead>
          <tbody>
          <?php foreach ($assigned as $p): ?>
            <tr>
              <td><strong><?= e($p['username']) ?></strong>
                <span class="text-dim" style="display:block"><?= e($p['name']) ?></span></td>
              <td><span class="badge <?= (int) $p['is_allowed'] === 1 ? 'badge-green' : 'badge-red' ?>">
                <?= (int) $p['is_allowed'] === 1 ? 'allowed' : 'blocked' ?></span></td>
              <td style="text-align:right">
                <form method="post" style="display:inline">
                  <?= csrf_field() ?><input type="hidden" name="do" value="unassign">
                  <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                  <button class="btn btn-ghost btn-xs" type="submit">Reset</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="text-muted mt-3" style="margin-bottom:0">
        Nobody has a personal setting — everyone follows the mode above.</p>
    <?php endif; ?>
  </div>
</div>

<!-- ==================== SPIN LIMITS ==================== -->
<?php
$limPerDay   = spin_default_per_day();
$limCooldown = spin_default_cooldown();
$limWeekly   = spin_default_weekly_cap();
$limMonthly  = spin_default_monthly_cap();
$overrides   = spin_limits_installed()
    ? (int) fetch_col('SELECT COUNT(*) FROM spin_access
                        WHERE cooldown_minutes IS NOT NULL
                           OR weekly_cap IS NOT NULL
                           OR monthly_cap IS NOT NULL', [], 0)
    : 0;
?>
<div class="adm-panel">
  <div class="adm-panel-head">
    <div><h2>Spin limits</h2>
      <p>How often a player may spin. These are the site defaults —
         a player can still be given their own numbers in Spin Access.</p></div>
    <span class="badge badge-green"><?= (int) $limPerDay ?>/day</span>
  </div>
  <div class="adm-panel-body">
    <?php if (!spin_limits_installed()): ?>
      <div class="alert alert-info" style="margin-bottom:16px">
        <span>&#8505;</span>
        The per-player limit columns are not installed yet. The daily allowance below
        still works; run <code>cppsmigration.php</code> to unlock cooldowns and caps
        per player.
      </div>
    <?php endif; ?>

    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="limits">

      <div class="adm-form-grid">
        <div class="form-group">
          <label class="form-label" for="perday">Spins per player per day</label>
          <input class="form-control" type="number" min="0" max="100" id="perday"
                 name="spin_default_per_day" value="<?= (int) $limPerDay ?>">
          <p class="form-hint">0 stops everyone from spinning without hiding the page.</p>
        </div>

        <div class="form-group">
          <label class="form-label" for="cool">Wait between spins (minutes)</label>
          <input class="form-control" type="number" min="0" max="1440" id="cool"
                 name="spin_cooldown_minutes" value="<?= (int) $limCooldown ?>">
          <p class="form-hint">0 = no wait. Useful when the daily allowance is more than one,
             so the whole day's spins are not burnt in ten seconds.</p>
        </div>

        <div class="form-group">
          <label class="form-label" for="wcap">Weekly cap</label>
          <input class="form-control" type="number" min="0" max="1000" id="wcap"
                 name="spin_weekly_cap" value="<?= (int) $limWeekly ?>">
          <p class="form-hint">0 = no cap. Counted over a rolling 7 days.</p>
        </div>

        <div class="form-group">
          <label class="form-label" for="mcap">Monthly cap</label>
          <input class="form-control" type="number" min="0" max="5000" id="mcap"
                 name="spin_monthly_cap" value="<?= (int) $limMonthly ?>">
          <p class="form-hint">0 = no cap. Counted over a rolling 30 days.</p>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label" for="lnote">Note shown on the spin page (optional)</label>
        <input class="form-control" type="text" id="lnote" name="spin_limit_note"
               maxlength="190" value="<?= e(setting('spin_limit_note')) ?>"
               placeholder="e.g. Festival week — 3 spins a day until Sunday">
      </div>

      <div class="row mt-3" style="gap:10px;flex-wrap:wrap">
        <button class="btn btn-primary" type="submit">Save limits</button>
        <span class="text-dim" style="align-self:center">
          Live rule: <strong class="text-green"><?= (int) $limPerDay ?></strong> a day<?php
            if ($limCooldown > 0) echo ', <strong>' . (int) $limCooldown . ' min</strong> apart';
            if ($limWeekly  > 0) echo ', max <strong>' . (int) $limWeekly . '</strong>/week';
            if ($limMonthly > 0) echo ', max <strong>' . (int) $limMonthly . '</strong>/month';
          ?>.
        </span>
      </div>
    </form>

    <?php if ($overrides > 0): ?>
      <hr style="border:0;border-top:1px solid var(--line);margin:18px 0">
      <form method="post" class="row" style="gap:10px;align-items:center;flex-wrap:wrap">
        <?= csrf_field() ?><input type="hidden" name="do" value="limit_reset">
        <span class="text-muted"><strong><?= num($overrides) ?></strong>
          player<?= $overrides === 1 ? ' has' : 's have' ?> a personal limit that
          overrides the numbers above.</span>
        <button class="btn btn-ghost btn-sm" type="submit"
                data-confirm="Clear every personal limit override?">Clear overrides</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:22px">
  <div class="card stat-card"><div class="sc-num"><?= num((int) $stats['spins']) ?></div>
    <div class="sc-lbl">Spins all time</div></div>
  <div class="card stat-card"><div class="sc-num"><?= num((int) $stats['paid']) ?></div>
    <div class="sc-lbl">Coins paid out</div></div>
  <div class="card stat-card"><div class="sc-num"><?= num((int) $todayStats['spins']) ?></div>
    <div class="sc-lbl">Spins today</div></div>
  <div class="card stat-card"><div class="sc-num"><?= num((int) $stats['players']) ?></div>
    <div class="sc-lbl">Players reached</div></div>
</div>

<div class="adm-form-grid">

  <div class="adm-panel">
    <div class="adm-panel-head">
      <div><h2>Prize segments</h2>
        <p>Weight sets the odds. Set a weight to 0 to remove a slice.</p></div>
      <a href="<?= url('spin.php') ?>" target="_blank" rel="noopener"
         class="btn btn-ghost btn-sm">Preview &nearr;</a>
    </div>
    <div class="adm-panel-body">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="save">

        <div class="form-group">
          <label class="check">
            <input type="checkbox" name="spin_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
            <span>Daily spin is live on the site</span>
          </label>
        </div>

        <div class="table-scroll">
          <table class="tbl" style="min-width:460px">
            <thead><tr><th>#</th><th>Coins</th><th>Weight</th><th>Odds</th><th>Colour</th></tr></thead>
            <tbody>
              <?php
              // always render two spare rows so new slices can be added
              $rows = $segments;
              $rows[] = ['coins' => 0, 'weight' => 0, 'color' => '#8fce3f'];
              $rows[] = ['coins' => 0, 'weight' => 0, 'color' => '#d9a63c'];
              foreach ($rows as $i => $s): ?>
                <tr>
                  <td class="text-dim"><?= $i + 1 ?></td>
                  <td><input class="form-control" type="number" min="0" name="coins[<?= $i ?>]"
                             value="<?= (int) $s['coins'] ?>" style="width:110px"></td>
                  <td><input class="form-control" type="number" min="0" name="weight[<?= $i ?>]"
                             value="<?= (int) $s['weight'] ?>" style="width:96px"></td>
                  <td class="text-dim">
                    <?= $s['weight'] > 0 ? round($s['weight'] / $totalW * 100, 1) . '%' : '—' ?></td>
                  <td><input type="color" name="color[<?= $i ?>]"
                             value="<?= e($s['color']) ?>"
                             style="width:52px;height:38px;border:1px solid var(--line);
                                    border-radius:8px;background:var(--bg-2);padding:3px;cursor:pointer"></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="row mt-3">
          <button class="btn btn-primary" type="submit">Save Wheel</button>
        </div>
      </form>

      <form method="post" class="mt-2">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="reset">
        <button class="btn btn-ghost btn-sm" type="submit"
                data-confirm="Reset the wheel to the built-in default prize table?">
          Reset to defaults</button>
      </form>

      <p class="form-hint mt-2">
        How many spins a player gets is set in <strong>Spin limits</strong> above
        (currently <?= (int) spin_default_per_day() ?> a day<?php
          if (spin_default_cooldown() > 0) {
              echo ', ' . (int) spin_default_cooldown() . ' minutes apart';
          } ?>). Every spin is checked and written inside a database lock, so the
        limit cannot be beaten by refreshing or opening a second tab.
      </p>
    </div>
  </div>

  <div class="adm-panel">
    <div class="adm-panel-head"><h2>Recent spins</h2></div>
    <div class="adm-panel-body flush">
      <?php if (!$recent): ?>
        <div class="empty"><div class="em-ico">&#127920;</div>
          <h3>No spins yet</h3><p>Results will appear here as players spin.</p></div>
      <?php else: ?>
        <div class="table-scroll">
          <table class="tbl" style="min-width:420px">
            <thead><tr><th>Player</th><th>Date</th><th>Won</th></tr></thead>
            <tbody>
              <?php foreach ($recent as $r): ?>
                <tr>
                  <td><strong><?= e($r['username']) ?></strong>
                    <div class="text-dim"><?= e($r['name']) ?></div></td>
                  <td class="text-dim"><?= fdate($r['spin_date']) ?></td>
                  <td class="text-gold">+<?= num($r['coins']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
