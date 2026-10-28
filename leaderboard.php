<?php
require_once __DIR__ . '/config/config.php';

$page_title = 'Leaderboard';
$page_desc  = 'Live leaderboards for every Asviora Games title — daily, weekly and all-time rankings.';

$gameSlug = get('game');
$range    = get('range', 'all');
$games    = fetch_all('SELECT id, slug, title, icon, thumbnail FROM games WHERE is_active = 1 ORDER BY sort_order');

$game = null;
if ($gameSlug !== '') {
    foreach ($games as $g) {
        if ($g['slug'] === $gameSlug) { $game = $g; break; }
    }
    if (!$game) { $gameSlug = ''; }
}

$ranges = ['today' => 'Today', 'week' => 'This week', 'month' => 'This month', 'all' => 'All time'];
if (!isset($ranges[$range])) $range = 'all';

$where  = ['s.result = "win"', 's.score > 0'];
$params = [];
if ($game) { $where[] = 's.game_id = ?'; $params[] = $game['id']; }
switch ($range) {
    case 'today': $where[] = 'DATE(s.played_at) = CURDATE()'; break;
    case 'week':  $where[] = 's.played_at >= (NOW() - INTERVAL 7 DAY)'; break;
    case 'month': $where[] = 's.played_at >= (NOW() - INTERVAL 30 DAY)'; break;
}
$whereSql = implode(' AND ', $where);

/*
 * Per-game boards rank by the single best score.
 * The combined board ranks by total points across every game.
 */
if ($game) {
    $rows = fetch_all(
        "SELECT u.id, u.username, u.name, u.avatar, u.level,
                MAX(s.score) AS score, COUNT(s.id) AS rounds,
                MIN(s.time_taken) AS best_time
         FROM scores s JOIN users u ON u.id = s.user_id
         WHERE $whereSql AND u.status = 'active'
         GROUP BY u.id ORDER BY score DESC, best_time ASC LIMIT " . LEADERBOARD_LIMIT,
        $params);
    $metric = 'Best score';
} else {
    $rows = fetch_all(
        "SELECT u.id, u.username, u.name, u.avatar, u.level,
                SUM(s.score) AS score, COUNT(s.id) AS rounds,
                MAX(s.score) AS best_single
         FROM scores s JOIN users u ON u.id = s.user_id
         WHERE $whereSql AND u.status = 'active'
         GROUP BY u.id ORDER BY score DESC, rounds ASC LIMIT " . LEADERBOARD_LIMIT,
        $params);
    $metric = 'Total points';
}

$me      = current_user();
$myRow   = null;
$myRank  = 0;
if ($me) {
    foreach ($rows as $i => $r) {
        if ((int) $r['id'] === (int) $me['id']) { $myRow = $r; $myRank = $i + 1; break; }
    }
}

$podium = array_slice($rows, 0, 3);
$rest   = $rows;

require __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
  <div class="container">
    <div class="crumb"><a href="<?= url('index.php') ?>">Home</a> &rsaquo; Leaderboard</div>
    <h1><span class="gradient-text">Leaderboard</span></h1>
    <p><?= $game ? e($game['title']) . ' — ranked by best single score.'
                 : 'Ranked by total points earned across every game.' ?></p>
  </div>
</section>

<section class="section">
  <div class="container">

    <div class="tabs">
      <a href="<?= url('leaderboard.php?range=' . $range) ?>" class="<?= !$game ? 'active' : '' ?>">
        &#127942; All games</a>
      <?php foreach ($games as $g): ?>
        <a href="<?= url('leaderboard.php?game=' . urlencode($g['slug']) . '&range=' . $range) ?>"
           class="<?= ($game && $game['slug'] === $g['slug']) ? 'active' : '' ?>">
          <?= game_icon_html($g['slug'], $g['thumbnail'], $g['icon'], 22) ?> <?= e($g['title']) ?></a>
      <?php endforeach; ?>
    </div>

    <div class="tabs" style="margin-bottom:32px">
      <?php foreach ($ranges as $k => $label): ?>
        <a href="<?= url('leaderboard.php?' . ($game ? 'game=' . urlencode($game['slug']) . '&' : '') . 'range=' . $k) ?>"
           class="<?= $range === $k ? 'active' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>

    <?php if (!$rows): ?>
      <div class="card empty">
        <div class="em-ico">&#127942;</div>
        <h3>No scores in this range yet</h3>
        <p>Be the first name on this board.</p>
        <a href="<?= $game ? url('play.php?game=' . urlencode($game['slug'])) : url('games.php') ?>"
           class="btn btn-primary">Play now</a>
      </div>
    <?php else: ?>

      <?php if (count($podium) === 3): ?>
        <div class="podium">
          <?php foreach ($podium as $i => $p): ?>
            <div class="card card-hover p-item p-<?= $i + 1 ?> reveal">
              <?php if ($i === 0): ?><div class="p-crown">&#128081;</div><?php endif; ?>
              <img src="<?= e(avatar_url($p)) ?>" alt="">
              <div class="p-name"><?= e($p['username']) ?></div>
              <div class="text-dim mb-2">Level <?= (int) $p['level'] ?> &middot; <?= num($p['rounds']) ?> rounds</div>
              <div class="p-score gradient-text"><?= num($p['score']) ?></div>
              <div class="text-dim"><?= e($metric) ?></div>
              <div class="mt-2"><span class="rank r<?= $i + 1 ?>" style="margin:0 auto"><?= $i + 1 ?></span></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="table-wrap reveal">
        <div class="table-scroll">
          <table class="tbl">
            <thead>
              <tr>
                <th style="width:72px">Rank</th>
                <th>Player</th>
                <th>Level</th>
                <th>Rounds</th>
                <?php if ($game): ?><th>Fastest</th><?php else: ?><th>Best single</th><?php endif; ?>
                <th style="text-align:right"><?= e($metric) ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rest as $i => $r): ?>
                <tr class="<?= ($me && (int) $r['id'] === (int) $me['id']) ? 'is-me' : '' ?>">
                  <td><span class="rank r<?= $i + 1 ?>"><?= $i + 1 ?></span></td>
                  <td>
                    <div class="u-cell">
                      <img src="<?= e(avatar_url($r)) ?>" alt="">
                      <div>
                        <div class="u-name"><?= e($r['username']) ?>
                          <?php if ($me && (int) $r['id'] === (int) $me['id']): ?>
                            <span class="badge badge-green" style="margin-left:6px">You</span>
                          <?php endif; ?>
                        </div>
                        <div class="u-sub"><?= e($r['name']) ?></div>
                      </div>
                    </div>
                  </td>
                  <td><span class="badge badge-green">Lv <?= (int) $r['level'] ?></span></td>
                  <td><?= num($r['rounds']) ?></td>
                  <td><?= $game ? ftime((int) ($r['best_time'] ?? 0)) : num($r['best_single'] ?? 0) ?></td>
                  <td style="text-align:right;font-weight:900;font-size:1rem"><?= num($r['score']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php if ($me && !$myRow): ?>
        <div class="alert alert-info mt-3" data-persist="1">
          <span>&#8505;</span>
          You are not in the top <?= LEADERBOARD_LIMIT ?> for this filter yet.
          <a href="<?= $game ? url('play.php?game=' . urlencode($game['slug'])) : url('games.php') ?>"
             style="text-decoration:underline">Play a round</a> to climb.
        </div>
      <?php endif; ?>

      <?php if (!$me): ?>
        <div class="card cta-band mt-4 reveal" style="border-radius:var(--radius)">
          <h3 style="margin-bottom:6px">Want your name up there?</h3>
          <p class="text-muted">Create a free account &mdash; scores only count for registered players.</p>
          <a href="<?= url('register.php') ?>" class="btn btn-primary mt-1">Sign Up Free</a>
        </div>
      <?php endif; ?>

    <?php endif; ?>

  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
