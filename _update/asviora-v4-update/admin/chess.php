<?php
/**
 * Admin — Chess.
 *
 * Stake limits, the practice reward, and every match on the board. A match
 * that is stuck (someone closed the tab and never came back) can be voided
 * from here, which returns both stakes.
 */
require_once __DIR__ . '/includes/auth.php';
$A = require_admin();
require_manage();

$admin_title = 'Chess';
$admin_sub   = 'Stakes, the engine reward, and every match played';

if (!has_table('chess_matches')) {
    flash('The chess tables are not installed yet. Open any page on the site once so the '
        . 'migration runs, then come back.', 'error');
    redirect('admin/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $do = post('do');

    if ($do === 'settings') {
        set_setting('chess_enabled',     empty($_POST['chess_enabled'])     ? '0' : '1');
        set_setting('chess_ai_enabled',  empty($_POST['chess_ai_enabled'])  ? '0' : '1');
        set_setting('chess_pvp_enabled', empty($_POST['chess_pvp_enabled']) ? '0' : '1');
        set_setting('chess_min_stake',   (string) max(0, (int) post('min_stake')));
        set_setting('chess_max_stake',   (string) max(0, (int) post('max_stake')));
        set_setting('chess_house_cut',   (string) min(50, max(0, (int) post('house_cut'))));
        set_setting('chess_ai_reward',   (string) max(0, (int) post('ai_reward')));
        flash('Chess settings saved.');
        redirect('admin/chess.php');

    } elseif ($do === 'void') {
        $m = fetch_one('SELECT * FROM chess_matches WHERE id = ?', [(int) post('id')]);
        if ($m && $m['status'] !== 'finished') {
            if ($m['status'] === 'open') {
                q('UPDATE chess_matches SET status = "cancelled", ended_at = NOW() WHERE id = ?',
                  [(int) $m['id']]);
                if ((int) $m['stake'] > 0) {
                    add_coins((int) $m['host_id'], (int) $m['stake'], 'bonus',
                              'Chess challenge voided by an admin — stake returned');
                }
            } else {
                chess_settle($m, 'abort', 'admin');
            }
            flash('Match voided and the stakes returned.');
        }
        redirect('admin/chess.php');
    }
}

$filter = get('status');
$where  = '';
$params = [];
if (in_array($filter, ['open', 'active', 'finished', 'cancelled'], true)) {
    $where    = ' WHERE m.status = ?';
    $params[] = $filter;
}

$matches = fetch_all(
    'SELECT m.*, h.username AS host_name, g.username AS guest_name
       FROM chess_matches m
       LEFT JOIN users h ON h.id = m.host_id
       LEFT JOIN users g ON g.id = m.guest_id' . $where . '
      ORDER BY m.id DESC LIMIT 80', $params);

$stats = fetch_one(
    'SELECT COUNT(*) AS total,
            SUM(status = "active") AS live,
            SUM(status = "open")   AS waiting,
            SUM(mode = "pvp")      AS staked,
            COALESCE(SUM(CASE WHEN status = "finished" THEN pot ELSE 0 END),0) AS paid
       FROM chess_matches');

require __DIR__ . '/includes/layout.php';
?>

<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:22px">
  <div class="card stat-card"><div class="sc-num"><?= num((int) $stats['total']) ?></div>
    <div class="sc-lbl">Matches played</div></div>
  <div class="card stat-card"><div class="sc-num"><?= num((int) $stats['live']) ?></div>
    <div class="sc-lbl">In play now</div></div>
  <div class="card stat-card"><div class="sc-num"><?= num((int) $stats['waiting']) ?></div>
    <div class="sc-lbl">Waiting for an opponent</div></div>
  <div class="card stat-card"><div class="sc-num"><?= num((int) $stats['paid']) ?></div>
    <div class="sc-lbl">Coins through the pot</div></div>
</div>

<div class="adm-panel">
  <div class="adm-panel-head">
    <div><h2>Settings</h2><p>What players may stake, and what the engine pays.</p></div>
    <a href="<?= url('chess.php') ?>" target="_blank" rel="noopener"
       class="btn btn-ghost btn-sm">Open the lobby &nearr;</a>
  </div>
  <div class="adm-panel-body">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="do" value="settings">
      <div class="adm-form-grid">
        <div>
          <div class="form-group">
            <label class="adm-switch"><input type="checkbox" name="chess_enabled" value="1"
              <?= setting('chess_enabled', '1') === '1' ? 'checked' : '' ?>>
              <span class="track"></span><span>Chess is live on the site</span></label>
          </div>
          <div class="form-group">
            <label class="adm-switch"><input type="checkbox" name="chess_ai_enabled" value="1"
              <?= setting('chess_ai_enabled', '1') === '1' ? 'checked' : '' ?>>
              <span class="track"></span><span>Practice games against the computer</span></label>
          </div>
          <div class="form-group">
            <label class="adm-switch"><input type="checkbox" name="chess_pvp_enabled" value="1"
              <?= setting('chess_pvp_enabled', '1') === '1' ? 'checked' : '' ?>>
              <span class="track"></span><span>Staked challenges between players</span></label>
          </div>
          <div class="form-group">
            <label class="form-label" for="ai_reward">Coins for beating the engine</label>
            <input class="form-control" type="number" id="ai_reward" name="ai_reward" min="0"
                   value="<?= (int) setting('chess_ai_reward', '25') ?>">
            <div class="form-hint">Multiplied by the engine level, capped at three wins a day.</div>
          </div>
        </div>
        <div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="min_stake">Minimum stake</label>
              <input class="form-control" type="number" id="min_stake" name="min_stake" min="0"
                     value="<?= chess_min_stake() ?>">
            </div>
            <div class="form-group">
              <label class="form-label" for="max_stake">Maximum stake</label>
              <input class="form-control" type="number" id="max_stake" name="max_stake" min="0"
                     value="<?= chess_max_stake() ?>">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label" for="house_cut">House cut (%)</label>
            <input class="form-control" type="number" id="house_cut" name="house_cut"
                   min="0" max="50" value="<?= (int) setting('chess_house_cut', '0') ?>">
            <div class="form-hint">Taken off the pot before the winner is paid. 0 = winner takes
              everything. A draw always returns both stakes in full.</div>
          </div>
          <button class="btn btn-primary btn-block btn-lg" type="submit">Save chess settings</button>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="adm-panel">
  <div class="adm-panel-head">
    <div><h2>Matches</h2><p>Newest first</p></div>
    <div class="tabs" style="margin:0">
      <?php foreach (['' => 'All', 'open' => 'Open', 'active' => 'In play',
                      'finished' => 'Finished', 'cancelled' => 'Cancelled'] as $k => $lbl): ?>
        <a href="<?= admin_url('chess.php' . ($k ? '?status=' . $k : '')) ?>"
           class="<?= $filter === $k ? 'active' : '' ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="adm-panel-body flush">
    <div class="table-scroll">
      <table class="tbl" style="min-width:880px">
        <thead><tr><th>Code</th><th>Players</th><th>Mode</th><th>Stake / pot</th>
                   <th>Status</th><th>Result</th><th>When</th>
                   <th style="text-align:right">Action</th></tr></thead>
        <tbody>
        <?php foreach ($matches as $m): ?>
          <tr>
            <td><strong><?= e($m['code']) ?></strong>
              <span class="text-dim" style="display:block"><?= (int) $m['minutes'] ?> min</span></td>
            <td>
              <?= e($m['host_name'] ?? '—') ?>
              <?php if ($m['mode'] === 'ai'): ?>
                <span class="text-dim">vs computer L<?= (int) $m['ai_level'] ?></span>
              <?php else: ?>
                <span class="text-dim">vs <?= e($m['guest_name'] ?? 'waiting') ?></span>
              <?php endif; ?>
            </td>
            <td><span class="badge <?= $m['mode'] === 'ai' ? '' : 'badge-gold' ?>">
              <?= $m['mode'] === 'ai' ? 'practice' : 'staked' ?></span></td>
            <td class="text-gold"><?= num($m['stake']) ?> / <?= num($m['pot']) ?></td>
            <td><span class="badge <?= $m['status'] === 'active' ? 'badge-green'
                                     : ($m['status'] === 'open' ? 'badge-info' : '') ?>">
              <?= e($m['status']) ?></span></td>
            <td class="text-muted"><?= e($m['result'] ?: '—') ?>
              <span class="text-dim"><?= e($m['reason']) ?></span></td>
            <td class="text-dim nowrap"><?= fdate($m['created_at'], 'd M, H:i') ?></td>
            <td style="text-align:right">
              <?php if ($m['status'] !== 'finished' && $m['status'] !== 'cancelled'): ?>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?><input type="hidden" name="do" value="void">
                  <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                  <button class="btn btn-danger btn-xs" type="submit"
                    data-confirm="Void match <?= e($m['code']) ?> and return both stakes?">
                    Void &amp; refund</button>
                </form>
              <?php else: ?>
                <span class="text-dim">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$matches): ?>
          <tr><td colspan="8" class="text-muted center">No matches yet.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
