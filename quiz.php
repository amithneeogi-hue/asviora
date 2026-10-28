<?php
/**
 * Quiz contest — lobby and level map.
 *
 * The bank is cut into levels, so this page is a ladder rather than a
 * menu: level 1 is open, the rest unlock as they are cleared, and Mid
 * and Hard stay shut until the difficulty below them is finished.
 *
 * Two players looking at the same level number are not looking at the
 * same questions — the cut of the bank is per account.
 */
require_once __DIR__ . '/config/config.php';

// No game without an account. One helper, every entry point.
require_play_login();

// v7 — registration fee / paid plan
require_game_access('quiz');

if (!quiz_on()) {
    $page_title = 'Quiz';
    require __DIR__ . '/includes/header.php';
    echo '<div class="container section"><div class="empty card"><div class="em-ico">&#129504;</div>'
       . '<h3>The quiz contest is not available</h3><p>It is either paused or not installed yet. '
       . 'Meanwhile there are games waiting for you.</p>'
       . '<a class="btn btn-primary" href="' . url('games.php') . '">Browse games</a></div></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$u         = current_user();
$uid       = $u ? (int) $u['id'] : 0;
$useLevels = quiz_levels_ready();

$cats = fetch_all('SELECT c.*, (SELECT COUNT(*) FROM quiz_questions q
                                 WHERE q.category_id = c.id AND q.is_active = 1) AS qcount
                   FROM quiz_categories c
                   WHERE c.is_active = 1
                   ORDER BY c.sort_order, c.name');

$activeCat = (int) get('cat');
$openDiff  = get('level');
if (!in_array($openDiff, quiz_difficulty_order(), true)) {
    $openDiff = '';
}

// counts per difficulty, so a level with no questions is never offered
$counts = [];
foreach (quiz_difficulty_order() as $d) {
    $counts[$d] = quiz_bank_count($activeCat, $d);
}

// the player's standing across the three ladders
$overview = $uid && $useLevels ? quiz_overview($uid, $activeCat) : null;

// the level map for whichever difficulty is open
$map = [];
if ($uid && $useLevels && $openDiff !== '') {
    $map = quiz_level_map($uid, $activeCat, $openDiff);
}

// personal stats
$mine = null;
$best = [];
if ($u) {
    $mine = fetch_one(
        'SELECT COUNT(*) AS rounds, COALESCE(SUM(correct),0) AS correct,
                COALESCE(SUM(coins_earned),0) AS coins
           FROM quiz_sessions WHERE user_id = ? AND status = "finished"',
        [$uid]
    );
    foreach (fetch_all(
        'SELECT difficulty, MAX(correct) AS best FROM quiz_sessions
          WHERE user_id = ? AND status = "finished" GROUP BY difficulty',
        [$uid]) as $r) {
        $best[$r['difficulty']] = (int) $r['best'];
    }
}

// today's leaders
$today = fetch_all(
    'SELECT s.correct, s.total, s.difficulty, s.level_no, s.coins_earned,
            u.username, u.avatar, u.name
       FROM quiz_sessions s
       JOIN users u ON u.id = s.user_id
      WHERE s.status = "finished" AND DATE(s.finished_at) = CURDATE()
      ORDER BY s.correct DESC, s.time_taken ASC
      LIMIT 8'
);

$levels     = quiz_levels();
$totalDone  = $overview ? $overview['passed'] : 0;
$totalAll   = $overview ? $overview['total']  : 0;

$page_title = 'Quiz Contest';
$page_desc  = 'Levelled quiz — clear a level to open the next, with your own set of questions.';
require __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
  <div class="container">
    <div class="crumb"><a href="<?= url('index.php') ?>">Home</a> &rsaquo; Quiz</div>
    <h1>Quiz <span class="gradient-text">Levels</span></h1>
    <?php if ($useLevels): ?>
      <p>Clear a level to open the next one. Finish every Easy level and Mid opens;
         finish every Mid and Hard opens. Your questions are drawn from your own cut
         of the bank — nobody else gets the same set.</p>
      <?php if ($overview && $totalAll > 0): ?>
        <div class="lvl-topbar">
          <div class="lvl-track"><i style="width:<?= (int) round($totalDone / max(1, $totalAll) * 100) ?>%"></i></div>
          <span><?= (int) $totalDone ?> of <?= (int) $totalAll ?> levels cleared</span>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <p><?= (int) quiz_round_size() ?> questions a round, a countdown on every single one,
         and coins the moment you get it right.</p>
    <?php endif; ?>
  </div>
</section>

<section class="section">
  <div class="container">

    <?= render_flash() ?>

    <?php if ($cats): ?>
      <div class="tabs">
        <a href="<?= url('quiz.php') ?>" class="<?= $activeCat ? '' : 'active' ?>">All topics</a>
        <?php foreach ($cats as $c): if (!$c['qcount']) continue; ?>
          <a href="<?= url('quiz.php?cat=' . (int) $c['id']) ?>"
             class="<?= $activeCat === (int) $c['id'] ? 'active' : '' ?>">
            <?= $c['icon'] ?> <?= e($c['name']) ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- ===================== DIFFICULTY CARDS ===================== -->
    <div class="quiz-levels">
      <?php foreach ($levels as $key => $lv):
        $n     = $counts[$key] ?? 0;
        $ready = $n > 0;
        $o     = $overview['difficulties'][$key] ?? null;
        $cfg   = quiz_level_cfg($activeCat, $key);
        $nLv   = quiz_level_total($activeCat, $key);
        $lock  = $o ? !$o['unlocked'] : false;
      ?>
        <div class="card card-hover level-card lv-<?= e($key) ?> reveal <?= $lock ? 'is-locked' : '' ?>">
          <div class="lv-ico"><?= $lock ? '&#128274;' : $lv['icon'] ?></div>
          <h3><?= e($lv['label']) ?></h3>
          <div class="lv-meta"><?= e($lv['blurb']) ?></div>

          <?php if ($useLevels): ?>
            <div class="kv"><span class="k">Levels</span>
              <span class="v"><?= (int) $nLv ?></span></div>
            <div class="kv"><span class="k">Questions each</span>
              <span class="v"><?= (int) $cfg['questions_per_level'] ?></span></div>
            <div class="kv"><span class="k">Pass mark</span>
              <span class="v"><?= (int) $cfg['pass_percent'] ?>%</span></div>
          <?php else: ?>
            <div class="kv"><span class="k">Questions available</span>
              <span class="v"><?= num($n) ?></span></div>
          <?php endif; ?>

          <div class="kv"><span class="k">Time per question</span>
            <span class="v"><?= (int) $cfg['seconds'] ?>s</span></div>
          <div class="kv"><span class="k">Coins per correct</span>
            <span class="v text-gold">+<?= num($cfg['reward']) ?></span></div>

          <?php if ($o && $o['levels'] > 0): ?>
            <div class="lvl-track sm mt-2"><i style="width:<?= (int) $o['percent'] ?>%"></i></div>
            <div class="lv-meta" style="margin-top:6px">
              <?= (int) $o['passed'] ?> / <?= (int) $o['levels'] ?> levels cleared
            </div>
          <?php elseif (isset($best[$key])): ?>
            <div class="kv"><span class="k">Your best</span>
              <span class="v text-green"><?= (int) $best[$key] ?> correct</span></div>
          <?php endif; ?>

          <div class="mt-3">
            <?php if (!$ready): ?>
              <span class="btn btn-ghost btn-block is-disabled">No questions yet</span>
            <?php elseif (!$u): ?>
              <a class="btn btn-ghost btn-block" href="<?= url('login.php') ?>">Log in to play</a>
            <?php elseif ($lock): ?>
              <span class="btn btn-ghost btn-block is-disabled">&#128274; Locked</span>
              <p class="lv-meta" style="margin-top:8px"><?= e($o['lock_note']) ?></p>
            <?php elseif ($useLevels): ?>
              <a class="btn btn-primary btn-block"
                 href="<?= url('quiz.php?level=' . e($key) . ($activeCat ? '&cat=' . $activeCat : '')) ?>">
                <?= $openDiff === $key ? 'Level map below' : 'Open the level map' ?>
              </a>
              <?php if ($o && $o['next_level'] > 0): ?>
                <a class="btn btn-ghost btn-block mt-2"
                   href="<?= url('quiz-play.php?level=' . e($key) . '&n=' . (int) $o['next_level']
                                 . ($activeCat ? '&cat=' . $activeCat : '')) ?>">
                  Continue at level <?= (int) $o['next_level'] ?> &rarr;
                </a>
              <?php endif; ?>
            <?php else: ?>
              <a class="btn btn-primary btn-block"
                 href="<?= url('quiz-play.php?level=' . e($key) . ($activeCat ? '&cat=' . $activeCat : '')) ?>">
                Start <?= e($lv['label']) ?> round
              </a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- ===================== LEVEL MAP ===================== -->
    <?php if ($useLevels && $openDiff !== ''):
          $cfg = quiz_level_cfg($activeCat, $openDiff); ?>
      <div class="head center mt-5">
        <span class="eyebrow"><?= e($cfg['label']) ?></span>
        <h2>Level map</h2>
        <p><?= count($map) ?> levels &middot; <?= (int) $cfg['questions_per_level'] ?> questions each
           &middot; pass at <?= (int) $cfg['pass_percent'] ?>%</p>
      </div>

      <?php if (!$u): ?>
        <div class="empty card"><div class="em-ico">&#128274;</div>
          <h3>Log in to see your levels</h3>
          <p>Your ladder is personal — the questions in each level are drawn just for you.</p>
          <a class="btn btn-primary" href="<?= url('login.php') ?>">Log in</a></div>

      <?php elseif (!$map): ?>
        <div class="empty card"><div class="em-ico">&#129504;</div>
          <h3>No levels here yet</h3>
          <p>There are not enough questions in this topic at this difficulty.</p></div>

      <?php else: ?>
        <div class="lvl-grid">
          <?php foreach ($map as $m): ?>
            <?php if ($m['state'] === 'locked'): ?>
              <div class="lvl-tile is-locked" title="Clear the level before this one first">
                <span class="lt-no"><?= (int) $m['level'] ?></span>
                <span class="lt-ico">&#128274;</span>
              </div>
            <?php else: ?>
              <a class="lvl-tile is-<?= e($m['state']) ?>"
                 href="<?= url('quiz-play.php?level=' . e($openDiff) . '&n=' . (int) $m['level']
                               . ($activeCat ? '&cat=' . $activeCat : '')) ?>">
                <span class="lt-no"><?= (int) $m['level'] ?></span>
                <?php if ($m['state'] === 'passed'): ?>
                  <span class="lt-stars">
                    <?php for ($s = 1; $s <= 3; $s++): ?>
                      <i class="<?= $s <= $m['stars'] ? 'on' : '' ?>">&#9733;</i>
                    <?php endfor; ?>
                  </span>
                  <span class="lt-best"><?= (int) $m['percent'] ?>%</span>
                <?php else: ?>
                  <span class="lt-ico">&#9654;</span>
                  <span class="lt-best"><?= (int) $m['size'] ?> Q</span>
                <?php endif; ?>
              </a>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>

        <p class="text-dim center mt-3" style="font-size:.88rem">
          A question you have answered will not come back for
          <?= (int) setting('quiz_repeat_block_days', '180') ?> days.
        </p>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($u && $mine): ?>
      <div class="grid g-3 mt-4">
        <div class="card stat-card reveal">
          <div class="sc-num"><?= num((int) $mine['rounds']) ?></div>
          <div class="sc-lbl">Rounds played</div>
        </div>
        <div class="card stat-card reveal">
          <div class="sc-num"><?= num((int) $mine['correct']) ?></div>
          <div class="sc-lbl">Correct answers</div>
        </div>
        <div class="card stat-card reveal">
          <div class="sc-num"><?= num((int) $mine['coins']) ?></div>
          <div class="sc-lbl">Coins from quiz</div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php if ($today): ?>
<section class="section section-tint">
  <div class="container">
    <div class="head center">
      <span class="eyebrow">Today</span>
      <h2>Top rounds of the day</h2>
      <p>Resets at midnight. Beat them before it does.</p>
    </div>

    <div class="table-wrap">
      <div class="table-scroll">
        <table class="tbl">
          <thead>
            <tr><th>#</th><th>Player</th><th>Level</th><th>Score</th><th>Coins</th></tr>
          </thead>
          <tbody>
            <?php foreach ($today as $i => $r): ?>
              <tr class="<?= ($u && $r['username'] === $u['username']) ? 'is-me' : '' ?>">
                <td><span class="rank r<?= $i + 1 ?>"><?= $i + 1 ?></span></td>
                <td>
                  <div class="u-cell">
                    <img src="<?= e(avatar_url($r)) ?>" alt="">
                    <div>
                      <div class="u-name"><?= e($r['username']) ?></div>
                      <div class="u-sub"><?= e($r['name']) ?></div>
                    </div>
                  </div>
                </td>
                <td>
                  <span class="badge badge-info"><?= e(ucfirst($r['difficulty'])) ?></span>
                  <?php if ((int) $r['level_no'] > 0): ?>
                    <span class="text-dim">L<?= (int) $r['level_no'] ?></span>
                  <?php endif; ?>
                </td>
                <td><strong><?= (int) $r['correct'] ?></strong>
                    <span class="text-dim">/ <?= (int) $r['total'] ?></span></td>
                <td class="text-gold">+<?= num($r['coins_earned']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (spin_on()): ?>
<section class="section-sm">
  <div class="container">
    <div class="cta-band">
      <h2>Spin before you play</h2>
      <p class="text-muted">A free spin every day &mdash; up to 1,000 coins, no entry fee.</p>
      <a class="btn btn-gold btn-lg mt-2" href="<?= url('spin.php') ?>">&#127920; Spin the wheel</a>
    </div>
  </div>
</section>
<?php endif; ?>

<style>
/* ---------- level map ---------- */
.lvl-topbar { display:flex; align-items:center; gap:14px; margin-top:16px; flex-wrap:wrap; }
.lvl-topbar span { color:var(--muted); font-size:.9rem; }

.lvl-track {
  flex:1; min-width:200px; height:8px; border-radius:99px;
  background:var(--bg-2); border:1px solid var(--line); overflow:hidden;
}
.lvl-track.sm { height:6px; min-width:0; }
.lvl-track i {
  display:block; height:100%;
  background:linear-gradient(90deg, var(--green,#8fce3f), var(--gold,#d9a63c));
}

.lvl-grid {
  display:grid; gap:12px;
  grid-template-columns:repeat(auto-fill, minmax(96px, 1fr));
}

.lvl-tile {
  position:relative; display:flex; flex-direction:column; align-items:center;
  justify-content:center; gap:4px; padding:14px 8px; min-height:96px;
  border-radius:14px; border:1px solid var(--line); background:var(--surface);
  text-decoration:none; color:inherit; transition:transform .15s ease, border-color .15s ease;
}
.lvl-tile:hover { transform:translateY(-2px); }

.lvl-tile .lt-no   { font-size:1.5rem; font-weight:800; line-height:1; }
.lvl-tile .lt-ico  { font-size:.9rem; color:var(--muted); }
.lvl-tile .lt-best { font-size:.72rem; color:var(--muted); }

.lvl-tile.is-open   { border-color:var(--gold,#d9a63c); box-shadow:0 0 0 1px rgba(217,166,60,.25) inset; }
.lvl-tile.is-open .lt-ico { color:var(--gold,#d9a63c); }

.lvl-tile.is-passed { border-color:rgba(143,206,63,.45); background:linear-gradient(160deg,var(--surface),rgba(143,206,63,.06)); }
.lvl-tile.is-locked { opacity:.45; cursor:not-allowed; }
.lvl-tile.is-locked:hover { transform:none; }

.lt-stars { font-size:.8rem; letter-spacing:1px; }
.lt-stars i { font-style:normal; color:var(--line); }
.lt-stars i.on { color:var(--gold,#d9a63c); }

.level-card.is-locked { opacity:.72; }
.mt-5 { margin-top:44px; }
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
