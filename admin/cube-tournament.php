<?php
/**
 * Admin — 3D Cube Daily Tournament  (v8)
 */
require_once __DIR__ . '/includes/auth.php';
$A = require_admin();
require_manage();

$admin_title = '3D Cube Tournament';
$admin_sub   = 'Daily fastest-solve ranking and prize distribution';

$today = cube_tournament_date();
$selDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) get('date')) ? get('date') : $today;

/* ---------- actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $do = post('do');

    if ($do === 'settings') {
        set_setting('cube_tournament_enabled', empty($_POST['enabled']) ? '0' : '1');
        set_setting('cube_prize_1st', (string) max(0, (int) post('p1')));
        set_setting('cube_prize_2nd', (string) max(0, (int) post('p2')));
        set_setting('cube_prize_3rd', (string) max(0, (int) post('p3')));
        set_setting('cube_tournament_min_plays', (string) max(1, (int) post('minp')));
        flash('Tournament settings saved.');
        redirect('admin/cube-tournament.php?date=' . urlencode($selDate));
    }

    if ($do === 'distribute') {
        $d = post('date');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            flash('Invalid date.', 'error');
        } else {
            try {
                [$ok, $msg] = cube_tournament_distribute($d, (int) $A['id']);
                $ok ? flash($msg) : flash($msg, 'error');
            } catch (Throwable $e) {
                flash('Error: ' . $e->getMessage(), 'error');
            }
        }
        redirect('admin/cube-tournament.php?date=' . urlencode($d));
    }

    if ($do === 'undo') {
        $d = post('date');
        try {
            [$ok, $msg] = cube_tournament_undo($d, (int) $A['id']);
            $ok ? flash($msg) : flash($msg, 'error');
        } catch (Throwable $e) {
            flash('Error: ' . $e->getMessage(), 'error');
        }
        redirect('admin/cube-tournament.php?date=' . urlencode($d));
    }
}

$lb     = cube_tournament_leaderboard($selDate);
$plays  = cube_tournament_play_count($selDate);
$result = cube_tournament_result($selDate);

$past = fetch_all('SELECT * FROM cube_tournament_results ORDER BY tournament_date DESC LIMIT 30');

require __DIR__ . '/includes/layout.php';
?>
<div class="adm-panel">
  <div class="adm-panel-head"><div><h2>Settings</h2><p>Prizes in coins. 2nd/3rd only if completed solves ≥ min.</p></div></div>
  <div class="adm-panel-body">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="do" value="settings">
      <label class="check"><input type="checkbox" name="enabled" value="1" <?= cube_tournament_on() ? 'checked' : '' ?>>
        <span>Tournament is <strong>on</strong></span></label>
      <div class="row mt-3" style="gap:12px;flex-wrap:wrap">
        <div class="form-group"><label class="form-label">1st prize (coins)</label>
          <input class="form-control" type="number" name="p1" min="0" value="<?= cube_prize(1) ?>" style="max-width:120px"></div>
        <div class="form-group"><label class="form-label">2nd prize (coins)</label>
          <input class="form-control" type="number" name="p2" min="0" value="<?= cube_prize(2) ?>" style="max-width:120px"></div>
        <div class="form-group"><label class="form-label">3rd prize (coins)</label>
          <input class="form-control" type="number" name="p3" min="0" value="<?= cube_prize(3) ?>" style="max-width:120px"></div>
        <div class="form-group"><label class="form-label">Min plays for 2nd/3rd</label>
          <input class="form-control" type="number" name="minp" min="1" value="<?= cube_tournament_min_plays() ?>" style="max-width:120px"></div>
      </div>
      <button class="btn btn-primary" type="submit">Save settings</button>
    </form>
  </div>
</div>

<div class="adm-panel mt-4">
  <div class="adm-panel-head">
    <div><h2>Leaderboard — <?= e($selDate) ?></h2>
      <p><?= num($plays) ?> completed solves. Best (fastest) per user.</p></div>
    <form method="get" class="row" style="gap:8px">
      <input class="form-control" type="date" name="date" value="<?= e($selDate) ?>" style="max-width:180px">
      <button class="btn btn-ghost btn-sm" type="submit">View</button>
    </form>
  </div>
  <div class="adm-panel-body flush">
    <?php if (!$lb): ?>
      <div class="empty"><div class="em-ico">&#127918;</div><h3>No solves yet</h3></div>
    <?php else: ?>
      <div class="table-scroll">
        <table class="tbl">
          <thead><tr><th>Rank</th><th>Player</th><th>Solve time (s)</th><th>First solve at</th></tr></thead>
          <tbody>
          <?php foreach ($lb as $r): ?>
            <tr>
              <td><strong><?= (int) $r['rank'] ?></strong></td>
              <td><?= e($r['username']) ?></td>
              <td><?= num((int) $r['time_taken']) ?>s</td>
              <td class="text-dim"><?= e($r['played_at']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="adm-panel mt-4">
  <div class="adm-panel-head"><div><h2>Distribute prizes — <?= e($selDate) ?></h2>
    <p>Credits coins to top 3. Idempotent per date.</p></div></div>
  <div class="adm-panel-body">
    <?php if ($result && empty($result['undone_at'])): ?>
      <div class="alert alert-info" data-persist="1"><span>&#8505;</span>
        Already distributed on <?= e($result['distributed_at']) ?>.
        1st: #<?= (int) $result['rank1_user'] ?> (<?= num((int)$result['rank1_prize']) ?> coins),
        2nd: #<?= (int) $result['rank2_user'] ?> (<?= num((int)$result['rank2_prize']) ?> coins),
        3rd: #<?= (int) $result['rank3_user'] ?> (<?= num((int)$result['rank3_prize']) ?> coins).</div>
      <?php
        $dist = strtotime((string) $result['distributed_at']);
        if (time() - $dist <= 24*3600): ?>
        <form method="post" style="display:inline"><?= csrf_field() ?>
          <input type="hidden" name="do" value="undo">
          <input type="hidden" name="date" value="<?= e($selDate) ?>">
          <button class="btn btn-ghost btn-sm" type="submit"
                  data-confirm="Undo distribution for <?= e($selDate) ?>? Coins will be debited back.">Undo (within 24h)</button>
        </form>
      <?php endif; ?>
    <?php else: ?>
      <form method="post"><?= csrf_field() ?>
        <input type="hidden" name="do" value="distribute">
        <input type="hidden" name="date" value="<?= e($selDate) ?>">
        <button class="btn btn-primary" type="submit"
                data-confirm="Distribute prizes for <?= e($selDate) ?>?">Distribute now</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="adm-panel mt-4">
  <div class="adm-panel-head"><div><h2>Past distributions</h2></div></div>
  <div class="adm-panel-body flush">
    <?php if (!$past): ?>
      <div class="empty"><div class="em-ico">&#127915;</div><h3>None yet</h3></div>
    <?php else: ?>
      <div class="table-scroll">
        <table class="tbl" style="min-width:700px">
          <thead><tr><th>Date</th><th>Plays</th><th>1st</th><th>2nd</th><th>3rd</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($past as $r): ?>
            <tr>
              <td><?= e($r['tournament_date']) ?></td>
              <td><?= num((int) $r['total_plays']) ?></td>
              <td><?= $r['rank1_user'] ? '#' . (int) $r['rank1_user'] . ' (' . num((int)$r['rank1_prize']) . ')' : '—' ?></td>
              <td><?= $r['rank2_user'] ? '#' . (int) $r['rank2_user'] . ' (' . num((int)$r['rank2_prize']) . ')' : '—' ?></td>
              <td><?= $r['rank3_user'] ? '#' . (int) $r['rank3_user'] . ' (' . num((int)$r['rank3_prize']) . ')' : '—' ?></td>
              <td><?= !empty($r['undone_at']) ? 'Undone' : 'Done' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
