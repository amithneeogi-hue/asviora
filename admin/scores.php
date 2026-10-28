<?php
require_once __DIR__ . '/includes/auth.php';
$A = require_admin();
require_manage();

$admin_title = 'Scores';
$admin_sub   = 'Every recorded round, with fair-play flags';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $do = post('do');
    if ($do === 'delete') {
        q('DELETE FROM scores WHERE id = ?', [(int) post('id')]);
        flash('Score record deleted.');
    } elseif ($do === 'purge_game') {
        $gid = (int) post('game_id');
        if ($gid) {
            $n = q('DELETE FROM scores WHERE game_id = ?', [$gid])->rowCount();
            flash('Cleared ' . $n . ' score record(s) for that game.');
        }
    }
    redirect('admin/scores.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
}

$gameId = (int) get('game');
$result = get('result');
$search = get('q');
$page   = max(1, (int) get('page', '1'));
$per    = 25;

$where  = ['1 = 1'];
$params = [];
if ($gameId) { $where[] = 's.game_id = ?'; $params[] = $gameId; }
if (in_array($result, ['win', 'loss', 'incomplete'], true)) {
    $where[] = 's.result = ?'; $params[] = $result;
}
if ($search !== '') {
    $where[] = '(u.username LIKE ? OR u.email LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
$w = implode(' AND ', $where);

$total  = (int) fetch_col(
    "SELECT COUNT(*) FROM scores s JOIN users u ON u.id = s.user_id WHERE $w", $params, 0);
$pages  = max(1, (int) ceil($total / $per));
$page   = min($page, $pages);
$offset = ($page - 1) * $per;

$rows = fetch_all(
    "SELECT s.*, u.username, u.avatar, u.name, g.title, g.icon
     FROM scores s
     JOIN users u ON u.id = s.user_id
     JOIN games g ON g.id = s.game_id
     WHERE $w ORDER BY s.id DESC LIMIT $per OFFSET $offset", $params);

$games = fetch_all('SELECT id, title FROM games ORDER BY sort_order');

$avg  = (int) fetch_col('SELECT COALESCE(AVG(score),0) FROM scores WHERE result = "win"', [], 0);
$high = (int) fetch_col('SELECT COALESCE(MAX(score),0) FROM scores', [], 0);
$today = (int) fetch_col('SELECT COUNT(*) FROM scores WHERE DATE(played_at) = CURDATE()', [], 0);

/** Flag rounds that look implausible. */
function suspicious(array $r, int $avg): ?string
{
    if ($r['result'] === 'win' && (int) $r['time_taken'] < 6 && (int) $r['score'] > 0) {
        return 'finished in under 6s';
    }
    if ($avg > 0 && (int) $r['score'] > $avg * 6) {
        return 'score far above average';
    }
    if ($r['result'] === 'win' && (int) $r['moves'] === 0 && (int) $r['time_taken'] < 15) {
        return 'no moves recorded';
    }
    return null;
}

function sl(array $over = []): string
{
    $q = array_merge(['game' => get('game'), 'result' => get('result'),
                      'q' => get('q'), 'page' => get('page')], $over);
    $q = array_filter($q, function ($v) { return $v !== '' && $v !== null; });
    return admin_url('scores.php' . ($q ? '?' . http_build_query($q) : ''));
}

require __DIR__ . '/includes/layout.php';
?>

<div class="adm-stats">
  <div class="adm-stat"><span class="as-ico">&#127942;</span>
    <div class="as-num"><?= num($total) ?></div><div class="as-lbl">Records matching</div></div>
  <div class="adm-stat green"><span class="as-ico">&#128200;</span>
    <div class="as-num"><?= num($avg) ?></div><div class="as-lbl">Average winning score</div></div>
  <div class="adm-stat gold"><span class="as-ico">&#11088;</span>
    <div class="as-num"><?= num($high) ?></div><div class="as-lbl">All-time high</div></div>
  <div class="adm-stat info"><span class="as-ico">&#128197;</span>
    <div class="as-num"><?= num($today) ?></div><div class="as-lbl">Rounds today</div></div>
</div>

<div class="adm-panel">
  <form method="get" class="adm-toolbar">
    <input type="text" name="q" class="form-control grow" value="<?= e($search) ?>"
           placeholder="Search by player…">
    <select name="game" class="form-select" style="width:auto">
      <option value="">All games</option>
      <?php foreach ($games as $g): ?>
        <option value="<?= (int) $g['id'] ?>" <?= $gameId === (int) $g['id'] ? 'selected' : '' ?>>
          <?= e($g['title']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="result" class="form-select" style="width:auto">
      <option value="">Any result</option>
      <?php foreach (['win', 'loss', 'incomplete'] as $r): ?>
        <option value="<?= $r ?>" <?= $result === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary btn-sm" type="submit">Filter</button>
    <?php if ($search !== '' || $gameId || $result !== ''): ?>
      <a href="<?= admin_url('scores.php') ?>" class="btn btn-ghost btn-sm">Reset</a>
    <?php endif; ?>
    <?php if ($gameId): ?>
      <span style="flex:1"></span>
    <?php endif; ?>
  </form>

  <?php if ($gameId): ?>
    <form method="post" class="adm-toolbar" style="border-top:0">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="purge_game">
      <input type="hidden" name="game_id" value="<?= $gameId ?>">
      <span class="text-dim">Danger zone:</span>
      <button class="btn btn-danger btn-xs" type="submit"
        data-confirm="Delete EVERY score record for this game? This cannot be undone.">
        Clear all scores for this game</button>
    </form>
  <?php endif; ?>

  <div class="adm-panel-body flush">
    <?php if (!$rows): ?>
      <div class="empty"><div class="em-ico">&#127942;</div>
        <h3>No score records match</h3><p>Adjust the filters above.</p></div>
    <?php else: ?>
      <div class="table-scroll">
        <table class="tbl" style="min-width:1040px">
          <thead>
            <tr><th>Player</th><th>Game</th><th>Score</th><th>Time</th><th>Moves</th>
                <th>Level</th><th>Coins</th><th>Result</th><th>Flag</th><th>When</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): $flag = suspicious($r, $avg); ?>
              <tr <?= $flag ? 'style="background:rgba(242,99,126,.06)"' : '' ?>>
                <td>
                  <div class="u-cell">
                    <img src="<?= e(avatar_url($r)) ?>" alt="">
                    <div>
                      <div class="u-name"><?= e($r['username']) ?></div>
                      <div class="u-sub"><?= e($r['ip_address'] ?: '—') ?></div>
                    </div>
                  </div>
                </td>
                <td class="text-muted nowrap"><?= $r['icon'] ?> <?= e($r['title']) ?></td>
                <td><strong class="text-green"><?= num($r['score']) ?></strong></td>
                <td><?= ftime((int) $r['time_taken']) ?></td>
                <td><?= $r['moves'] ? num($r['moves']) : '&mdash;' ?></td>
                <td class="text-dim"><?= e($r['difficulty'] ?: '—') ?></td>
                <td class="text-gold"><?= $r['coins_earned'] ? '+' . num($r['coins_earned']) : '&mdash;' ?></td>
                <td><span class="badge <?= $r['result'] === 'win' ? 'badge-green' : 'badge-red' ?>">
                  <?= e($r['result']) ?></span></td>
                <td>
                  <?php if ($flag): ?>
                    <span class="badge badge-red" title="<?= e($flag) ?>">&#9888; check</span>
                  <?php else: ?>
                    <span class="text-dim">ok</span>
                  <?php endif; ?>
                </td>
                <td class="text-dim nowrap"><?= fdate($r['played_at'], 'd M, H:i') ?></td>
                <td>
                  <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button class="btn btn-danger btn-xs" type="submit"
                      data-confirm="Delete this score record?">&#10006;</button>
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

<?php if ($pages > 1): ?>
  <nav class="pagination">
    <?php if ($page > 1): ?><a href="<?= sl(['page' => $page - 1]) ?>">&lsaquo;</a><?php endif; ?>
    <?php
    $start = max(1, $page - 3);
    $end   = min($pages, $page + 3);
    for ($i = $start; $i <= $end; $i++): ?>
      <?php if ($i === $page): ?><span class="current"><?= $i ?></span>
      <?php else: ?><a href="<?= sl(['page' => $i]) ?>"><?= $i ?></a><?php endif; ?>
    <?php endfor; ?>
    <?php if ($page < $pages): ?><a href="<?= sl(['page' => $page + 1]) ?>">&rsaquo;</a><?php endif; ?>
  </nav>
<?php endif; ?>

<p class="text-dim center mt-2">
  Flagged rows are heuristics, not proof &mdash; review before acting.
</p>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
