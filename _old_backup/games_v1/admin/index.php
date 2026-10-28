<?php
require_once __DIR__ . '/includes/auth.php';
$A = require_admin();

$admin_title = 'Dashboard';
$admin_sub   = 'Snapshot of players, rounds and content';

/* ---------- headline numbers ---------- */
$s = [
    'players'   => (int) fetch_col('SELECT COUNT(*) FROM users', [], 0),
    'active'    => (int) fetch_col('SELECT COUNT(*) FROM users WHERE status = "active"', [], 0),
    'newWeek'   => (int) fetch_col('SELECT COUNT(*) FROM users WHERE created_at > (NOW() - INTERVAL 7 DAY)', [], 0),
    'rounds'    => (int) fetch_col('SELECT COUNT(*) FROM scores', [], 0),
    'roundsDay' => (int) fetch_col('SELECT COUNT(*) FROM scores WHERE DATE(played_at) = CURDATE()', [], 0),
    'games'     => (int) fetch_col('SELECT COUNT(*) FROM games WHERE is_active = 1', [], 0),
    'posts'     => (int) fetch_col('SELECT COUNT(*) FROM blog_posts WHERE status = "published"', [], 0),
    'drafts'    => (int) fetch_col('SELECT COUNT(*) FROM blog_posts WHERE status = "draft"', [], 0),
    'unread'    => (int) fetch_col('SELECT COUNT(*) FROM contact_messages WHERE is_read = 0', [], 0),
    'subs'      => (int) fetch_col('SELECT COUNT(*) FROM subscribers', [], 0),
    'coins'     => (int) fetch_col('SELECT COALESCE(SUM(coins),0) FROM users', [], 0),
    'views'     => (int) fetch_col('SELECT COALESCE(SUM(views),0) FROM blog_posts', [], 0),
];

/* ---------- last 14 days of activity ---------- */
$series = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $series[$d] = 0;
}
foreach (fetch_all(
    'SELECT DATE(played_at) AS d, COUNT(*) AS n FROM scores
     WHERE played_at > (NOW() - INTERVAL 14 DAY) GROUP BY DATE(played_at)') as $r) {
    if (isset($series[$r['d']])) $series[$r['d']] = (int) $r['n'];
}
$peak = max(1, max($series));

$topGames = fetch_all(
    'SELECT g.title, g.icon, g.plays, COUNT(s.id) AS rounds, COALESCE(MAX(s.score),0) AS best
     FROM games g LEFT JOIN scores s ON s.game_id = g.id
     GROUP BY g.id ORDER BY rounds DESC, g.plays DESC LIMIT 6');

$newUsers = fetch_all(
    'SELECT id, name, username, email, avatar, coins, level, created_at
     FROM users ORDER BY id DESC LIMIT 6');

$recentScores = fetch_all(
    'SELECT s.*, u.username, u.avatar, g.title, g.icon
     FROM scores s JOIN users u ON u.id = s.user_id JOIN games g ON g.id = s.game_id
     ORDER BY s.id DESC LIMIT 8');

$recentMsgs = fetch_all(
    'SELECT * FROM contact_messages ORDER BY id DESC LIMIT 5');

require __DIR__ . '/includes/layout.php';
?>

<div class="adm-stats">
  <div class="adm-stat green">
    <span class="as-ico">&#128101;</span>
    <div class="as-num"><?= num($s['players']) ?></div>
    <div class="as-lbl">Registered players</div>
    <div class="as-sub"><?= num($s['newWeek']) ?> joined in the last 7 days</div>
  </div>
  <div class="adm-stat gold">
    <span class="as-ico">&#127942;</span>
    <div class="as-num"><?= num($s['rounds']) ?></div>
    <div class="as-lbl">Rounds played</div>
    <div class="as-sub"><?= num($s['roundsDay']) ?> today</div>
  </div>
  <div class="adm-stat info">
    <span class="as-ico">&#128221;</span>
    <div class="as-num"><?= num($s['posts']) ?></div>
    <div class="as-lbl">Published posts</div>
    <div class="as-sub"><?= num($s['drafts']) ?> draft<?= $s['drafts'] === 1 ? '' : 's' ?>
      &middot; <?= num($s['views']) ?> total views</div>
  </div>
  <div class="adm-stat <?= $s['unread'] ? 'red' : '' ?>">
    <span class="as-ico">&#9993;</span>
    <div class="as-num"><?= num($s['unread']) ?></div>
    <div class="as-lbl">Unread messages</div>
    <div class="as-sub"><?= num($s['subs']) ?> newsletter subscriber<?= $s['subs'] === 1 ? '' : 's' ?></div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1.6fr 1fr;gap:22px;align-items:start" class="adm-split">

  <!-- ---------- activity chart ---------- -->
  <div class="adm-panel">
    <div class="adm-panel-head">
      <div><h2>Rounds played &mdash; last 14 days</h2>
        <p>Peak day: <?= num($peak) ?> round<?= $peak === 1 ? '' : 's' ?></p></div>
      <a href="<?= admin_url('scores.php') ?>" class="btn btn-ghost btn-xs">All scores</a>
    </div>
    <div class="adm-panel-body">
      <div class="adm-chart">
        <?php foreach ($series as $day => $n): ?>
          <div class="bar-col" title="<?= date('d M Y', strtotime($day)) ?>: <?= $n ?> rounds">
            <span class="bar-val"><?= $n ?: '' ?></span>
            <div class="bar-fill" style="height:<?= max(2, ($n / $peak) * 100) ?>%"></div>
            <span class="bar-lbl"><?= date('d/m', strtotime($day)) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- ---------- quick actions ---------- -->
  <div class="adm-panel">
    <div class="adm-panel-head"><h2>Quick actions</h2></div>
    <div class="adm-panel-body" style="display:grid;gap:9px">
      <a href="<?= admin_url('blog-edit.php') ?>" class="btn btn-primary btn-block">&#10133; Write a blog post</a>
      <a href="<?= admin_url('games.php') ?>" class="btn btn-ghost btn-block">&#127918; Manage games</a>
      <a href="<?= admin_url('users.php') ?>" class="btn btn-ghost btn-block">&#128101; Manage players</a>
      <a href="<?= admin_url('settings.php') ?>" class="btn btn-ghost btn-block">&#9881; Site settings &amp; socials</a>
      <a href="<?= admin_url('messages.php') ?>" class="btn btn-ghost btn-block">
        &#9993; Inbox<?= $s['unread'] ? ' (' . $s['unread'] . ')' : '' ?></a>

      <hr style="border:0;border-top:1px solid var(--line-soft);margin:6px 0">
      <div class="kv"><span class="k">Active games</span><span class="v"><?= num($s['games']) ?></span></div>
      <div class="kv"><span class="k">Coins in circulation</span><span class="v text-gold"><?= num($s['coins']) ?></span></div>
      <div class="kv"><span class="k">Active accounts</span><span class="v text-green"><?= num($s['active']) ?></span></div>
    </div>
  </div>
</div>

<!-- ---------- top games ---------- -->
<div class="adm-panel">
  <div class="adm-panel-head">
    <div><h2>Game performance</h2><p>Ranked by rounds completed</p></div>
    <a href="<?= admin_url('games.php') ?>" class="btn btn-ghost btn-xs">Manage</a>
  </div>
  <div class="adm-panel-body flush">
    <div class="table-scroll">
      <table class="tbl">
        <thead><tr><th>Game</th><th>Page opens</th><th>Rounds completed</th>
                   <th>Best score</th><th>Completion</th></tr></thead>
        <tbody>
          <?php foreach ($topGames as $g):
            $rate = $g['plays'] > 0 ? round(($g['rounds'] / $g['plays']) * 100) : 0; ?>
            <tr>
              <td><strong><?= $g['icon'] ?> <?= e($g['title']) ?></strong></td>
              <td><?= num($g['plays']) ?></td>
              <td><strong class="text-green"><?= num($g['rounds']) ?></strong></td>
              <td class="text-gold"><?= $g['best'] ? num($g['best']) : '&mdash;' ?></td>
              <td style="min-width:140px">
                <div class="bar"><i style="width:<?= min(100, $rate) ?>%"></i></div>
                <span class="text-dim"><?= $rate ?>%</span>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$topGames): ?>
            <tr><td colspan="5" class="text-muted center">No games yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:22px;align-items:start" class="adm-split">

  <!-- ---------- recent rounds ---------- -->
  <div class="adm-panel">
    <div class="adm-panel-head">
      <div><h2>Latest rounds</h2></div>
      <a href="<?= admin_url('scores.php') ?>" class="btn btn-ghost btn-xs">View all</a>
    </div>
    <div class="adm-panel-body flush">
      <div class="table-scroll">
        <table class="tbl" style="min-width:430px">
          <thead><tr><th>Player</th><th>Game</th><th>Score</th><th>When</th></tr></thead>
          <tbody>
            <?php foreach ($recentScores as $r): ?>
              <tr>
                <td>
                  <div class="u-cell">
                    <img src="<?= e(avatar_url($r)) ?>" alt="">
                    <span class="u-name"><?= e($r['username']) ?></span>
                  </div>
                </td>
                <td class="text-muted"><?= $r['icon'] ?> <?= e($r['title']) ?></td>
                <td><strong class="text-green"><?= num($r['score']) ?></strong></td>
                <td class="text-dim nowrap"><?= fdate($r['played_at'], 'd M, H:i') ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$recentScores): ?>
              <tr><td colspan="4" class="text-muted center">No rounds recorded yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ---------- newest players ---------- -->
  <div class="adm-panel">
    <div class="adm-panel-head">
      <div><h2>Newest players</h2></div>
      <a href="<?= admin_url('users.php') ?>" class="btn btn-ghost btn-xs">View all</a>
    </div>
    <div class="adm-panel-body flush">
      <div class="table-scroll">
        <table class="tbl" style="min-width:430px">
          <thead><tr><th>Player</th><th>Level</th><th>Coins</th><th>Joined</th></tr></thead>
          <tbody>
            <?php foreach ($newUsers as $u): ?>
              <tr>
                <td>
                  <div class="u-cell">
                    <img src="<?= e(avatar_url($u)) ?>" alt="">
                    <div>
                      <div class="u-name"><?= e($u['username']) ?></div>
                      <div class="u-sub"><?= e($u['email']) ?></div>
                    </div>
                  </div>
                </td>
                <td><span class="badge badge-green">Lv <?= (int) $u['level'] ?></span></td>
                <td class="text-gold"><?= num($u['coins']) ?></td>
                <td class="text-dim nowrap"><?= fdate($u['created_at'], 'd M') ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$newUsers): ?>
              <tr><td colspan="4" class="text-muted center">No players registered yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ---------- inbox preview ---------- -->
<?php if ($recentMsgs): ?>
<div class="adm-panel">
  <div class="adm-panel-head">
    <div><h2>Recent messages</h2></div>
    <a href="<?= admin_url('messages.php') ?>" class="btn btn-ghost btn-xs">Open inbox</a>
  </div>
  <div class="adm-panel-body flush">
    <div class="table-scroll">
      <table class="tbl">
        <thead><tr><th>From</th><th>Subject</th><th>Preview</th><th>When</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($recentMsgs as $m): ?>
            <tr>
              <td><strong><?= e($m['name']) ?></strong><br><span class="text-dim"><?= e($m['email']) ?></span></td>
              <td><?= e($m['subject'] ?: '—') ?></td>
              <td class="text-muted"><?= e(excerpt($m['message'], 70)) ?></td>
              <td class="text-dim nowrap"><?= fdate($m['created_at'], 'd M, H:i') ?></td>
              <td><?= $m['is_read']
                    ? '<span class="badge">read</span>'
                    : '<span class="badge badge-gold">new</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<style>
@media (max-width: 1180px) { .adm-split { grid-template-columns: 1fr !important; } }
</style>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
