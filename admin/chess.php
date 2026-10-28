<?php
/**
 * Admin — chess.
 *
 * Vs-computer and pass-and-play need nothing from the server, so this
 * screen is about the online side: the rewards, the idle timeout, and a
 * view of what is actually being played right now.
 */
require_once __DIR__ . '/includes/auth.php';
$A = require_admin();
require_manage();

$admin_title = 'Chess';
$admin_sub   = 'Online matches, rewards and the idle timeout';

if (!has_table('chess_games')) {
    flash('The chess tables are not installed yet. Open any page on the site once so the '
        . 'v4 migration runs, then come back.', 'error');
    redirect('admin/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $do = post('do');

    if ($do === 'save') {
        set_setting('chess_online_enabled', empty($_POST['online']) ? '0' : '1');
        set_setting('chess_reward_win',  (string) max(0, (int) post('reward_win')));
        set_setting('chess_reward_draw', (string) max(0, (int) post('reward_draw')));
        set_setting('chess_move_timeout', (string) max(30, (int) post('timeout')));
        flash('Chess settings saved.');

    } elseif ($do === 'abort') {
        q('UPDATE chess_games SET status = "aborted", reason = "admin"
            WHERE id = ? AND status IN ("waiting","active")', [(int) post('id')]);
        flash('Match stopped.', 'info');

    } elseif ($do === 'sweep') {
        $n = q('UPDATE chess_games SET status = "aborted", reason = "stale"
                 WHERE status = "waiting" AND created_at < (NOW() - INTERVAL 1 DAY)')->rowCount();
        flash($n . ' stale waiting games cleared.', 'info');
    }

    redirect('admin/chess.php');
}

$live = fetch_all(
    'SELECT m.*, w.username AS white_name, b.username AS black_name
       FROM chess_games m
       LEFT JOIN users w ON w.id = m.white_id
       LEFT JOIN users b ON b.id = m.black_id
      WHERE m.status IN ("waiting","active")
      ORDER BY m.id DESC LIMIT 40');

$done = fetch_all(
    'SELECT m.*, w.username AS white_name, b.username AS black_name
       FROM chess_games m
       LEFT JOIN users w ON w.id = m.white_id
       LEFT JOIN users b ON b.id = m.black_id
      WHERE m.status = "finished"
      ORDER BY m.id DESC LIMIT 30');

$stats = fetch_one(
    'SELECT COUNT(*) AS total,
            SUM(status = "active")   AS active,
            SUM(status = "waiting")  AS waiting,
            SUM(status = "finished") AS finished,
            COALESCE(AVG(NULLIF(ply,0)),0) AS avg_ply
       FROM chess_games');

require __DIR__ . '/includes/layout.php';
?>

<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:22px">
  <div class="card stat-card"><div class="sc-num"><?= num((int) $stats['active']) ?></div>
    <div class="sc-lbl">Games in play</div></div>
  <div class="card stat-card"><div class="sc-num"><?= num((int) $stats['waiting']) ?></div>
    <div class="sc-lbl">Waiting for an opponent</div></div>
  <div class="card stat-card"><div class="sc-num"><?= num((int) $stats['finished']) ?></div>
    <div class="sc-lbl">Games finished</div></div>
  <div class="card stat-card"><div class="sc-num"><?= (int) round($stats['avg_ply']) ?></div>
    <div class="sc-lbl">Average moves</div></div>
</div>

<div class="adm-form-grid">
  <div class="adm-panel">
    <div class="adm-panel-head"><h2>Online chess</h2></div>
    <div class="adm-panel-body">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="save">

        <label class="check">
          <input type="checkbox" name="online" value="1"
                 <?= setting('chess_online_enabled', '1') === '1' ? 'checked' : '' ?>>
          <span>Players can challenge each other online</span>
        </label>
        <p class="form-hint">
          Switching this off leaves vs-computer and 2-player-on-one-device working —
          only the networked mode disappears.
        </p>

        <div class="row" style="gap:12px;margin-top:14px">
          <div class="form-group" style="flex:1">
            <label class="form-label">Coins for a win</label>
            <input class="form-control" type="number" min="0" name="reward_win"
                   value="<?= (int) setting('chess_reward_win', '80') ?>">
          </div>
          <div class="form-group" style="flex:1">
            <label class="form-label">Coins for a draw</label>
            <input class="form-control" type="number" min="0" name="reward_draw"
                   value="<?= (int) setting('chess_reward_draw', '30') ?>">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Idle timeout (seconds)</label>
          <input class="form-control" type="number" min="30" max="86400" name="timeout"
                 value="<?= (int) setting('chess_move_timeout', '600') ?>" style="max-width:160px">
          <p class="form-hint">
            A player who leaves the board this long loses on time. Checked whenever anyone
            looks at the game, so no scheduled job is needed.
          </p>
        </div>

        <button class="btn btn-primary" type="submit">Save</button>
      </form>

      <hr style="border:0;border-top:1px solid var(--line);margin:18px 0">

      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="sweep">
        <button class="btn btn-ghost btn-sm" type="submit">Clear stale waiting games</button>
      </form>
    </div>
  </div>

  <div class="adm-panel">
    <div class="adm-panel-head"><h2>How online chess is refereed</h2></div>
    <div class="adm-panel-body">
      <p class="form-hint">
        Every move is regenerated on the server from the stored position before it is
        accepted, using the same rules the browser uses. A modified client cannot make an
        illegal move, cannot move out of turn, and cannot claim a checkmate that is not
        there — so the coins paid out are always earned.
      </p>
      <p class="form-hint">
        Checkmate, stalemate, the fifty-move rule, insufficient material and threefold
        repetition are all decided server-side. Resignations and draw agreements go
        through the same path.
      </p>
      <p class="form-hint">
        The computer opponent runs entirely in the player's browser, so however many
        people play it, it costs the server nothing.
      </p>
    </div>
  </div>
</div>

<div class="adm-panel mt-4">
  <div class="adm-panel-head"><h2>Live games</h2></div>
  <div class="adm-panel-body flush">
    <?php if (!$live): ?>
      <div class="empty"><div class="em-ico">&#9822;</div><h3>Nothing in play</h3></div>
    <?php else: ?>
      <div class="table-scroll">
        <table class="tbl" style="min-width:800px">
          <thead><tr><th>Code</th><th>White</th><th>Black</th><th>Moves</th>
                     <th>Turn</th><th>Status</th><th>Last move</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($live as $m): ?>
            <tr>
              <td><code><?= e($m['code']) ?></code>
                <div class="text-dim"><?= e($m['mode']) ?></div></td>
              <td><?= e($m['white_name'] ?: '—') ?></td>
              <td><?= e($m['black_name'] ?: '—') ?></td>
              <td class="text-dim"><?= (int) $m['ply'] ?></td>
              <td><span class="badge badge-info"><?= $m['turn'] === 'w' ? 'White' : 'Black' ?></span></td>
              <td><span class="badge badge-<?= $m['status'] === 'active' ? 'green' : 'muted' ?>">
                    <?= e($m['status']) ?></span></td>
              <td class="text-dim"><?= fdate($m['last_move_at'], 'd M, H:i') ?></td>
              <td>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="do" value="abort">
                  <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                  <button class="btn btn-ghost btn-sm" type="submit"
                          data-confirm="Stop this game?">Stop</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($done): ?>
<div class="adm-panel mt-4">
  <div class="adm-panel-head"><h2>Recent results</h2></div>
  <div class="adm-panel-body flush">
    <div class="table-scroll">
      <table class="tbl" style="min-width:760px">
        <thead><tr><th>Code</th><th>White</th><th>Black</th><th>Result</th>
                   <th>By</th><th>Moves</th><th>Finished</th></tr></thead>
        <tbody>
        <?php foreach ($done as $m): ?>
          <tr>
            <td><code><?= e($m['code']) ?></code></td>
            <td><?= e($m['white_name'] ?: '—') ?></td>
            <td><?= e($m['black_name'] ?: '—') ?></td>
            <td><span class="badge badge-<?= $m['result'] === 'draw' ? 'info' : 'green' ?>">
                  <?= e($m['result']) ?></span></td>
            <td class="text-dim"><?= e($m['reason']) ?></td>
            <td class="text-dim"><?= (int) $m['ply'] ?></td>
            <td class="text-dim"><?= fdate($m['updated_at'], 'd M, H:i') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
