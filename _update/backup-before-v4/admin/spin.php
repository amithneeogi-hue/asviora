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
