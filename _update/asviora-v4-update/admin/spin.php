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

    } elseif ($do === 'assign') {
        $name = post('username');
        $row  = fetch_one('SELECT id, username FROM users WHERE username = ? OR email = ?',
                          [$name, $name]);
        if (!$row) {
            flash('No player found with that username or email.', 'error');
        } else {
            $mode = post('access') === 'blocked' ? 'blocked'
                  : (post('access') === 'default' ? 'default' : 'allowed');
            update_row('users', ['spin_access' => $mode], 'id = ?', [(int) $row['id']]);
            flash($row['username'] . ' — spin access set to ' . $mode . '.');
        }

    } elseif ($do === 'unassign') {
        update_row('users', ['spin_access' => 'default'], 'id = ?', [(int) post('id')]);
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
$mode      = setting('spin_access_mode', 'all');
$assigned  = fetch_all("SELECT id, username, name, spin_access FROM users
                         WHERE spin_access <> 'default' ORDER BY spin_access, username");
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
              <td><span class="badge <?= $p['spin_access'] === 'allowed' ? 'badge-green' : 'badge-red' ?>">
                <?= e($p['spin_access']) ?></span></td>
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
        A player can spin once per calendar day. The limit is enforced by a unique
        key on the database, so it cannot be bypassed by refreshing or opening
        a second tab.
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
