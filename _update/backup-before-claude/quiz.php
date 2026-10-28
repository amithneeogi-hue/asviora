<?php
/**
 * Quiz contest — lobby.
 *
 * With level mode on (the default) this is a level map: Easy first, and Mid
 * only opens once every Easy level is cleared, Hard once every Mid level is.
 * How many levels a difficulty has comes from the bank —
 *      300 active questions / 10 per level = 30 levels
 * — both numbers being admin settings.
 *
 * With level mode off it falls back to the older free-play round picker.
 */
require_once __DIR__ . '/config/config.php';

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

$u        = current_user();
$uid      = $u ? (int) $u['id'] : 0;
$levels   = quiz_levels();
$perRound = quiz_level_mode() ? quiz_per_level() : quiz_round_size();

$cats = fetch_all('SELECT c.*, (SELECT COUNT(*) FROM quiz_questions q
                                 WHERE q.category_id = c.id AND q.is_active = 1) AS qcount
                   FROM quiz_categories c
                   WHERE c.is_active = 1
                   ORDER BY c.sort_order, c.name');

$activeCat = (int) get('cat');
$catQs     = $activeCat ? '&cat=' . $activeCat : '';

// counts per difficulty, so a level with no questions is never offered
$counts = [];
foreach (quiz_chain() as $d) {
    $counts[$d] = quiz_bank_count($d, $activeCat);
}

// personal stats
$mine = null;
$best = [];
if ($uid) {
    $mine = fetch_one(
        'SELECT COUNT(*) AS rounds, COALESCE(SUM(correct),0) AS correct,
                COALESCE(SUM(coins_earned),0) AS coins
           FROM quiz_sessions WHERE user_id = ? AND status = "finished"', [$uid]);
    foreach (fetch_all(
        'SELECT difficulty, MAX(correct) AS best FROM quiz_sessions
          WHERE user_id = ? AND status = "finished" GROUP BY difficulty', [$uid]) as $r) {
        $best[$r['difficulty']] = (int) $r['best'];
    }
}

$today = fetch_all(
    'SELECT s.correct, s.total, s.difficulty, s.level_no, s.coins_earned,
            u.username, u.avatar, u.name
       FROM quiz_sessions s
       JOIN users u ON u.id = s.user_id
      WHERE s.status = "finished" AND DATE(s.finished_at) = CURDATE()
      ORDER BY s.correct DESC, s.time_taken ASC
      LIMIT 8');

$page_title = 'Quiz Contest';
$page_desc  = 'Clear one level to open the next. Easy, then Mid, then Hard — '
            . 'with a fresh set of questions every single time.';
require __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
  <div class="container">
    <div class="crumb"><a href="<?= url('index.php') ?>">Home</a> &rsaquo; Quiz</div>
    <h1>Quiz <span class="gradient-text">Levels</span></h1>
    <?php if (quiz_level_mode()): ?>
      <p><?= (int) $perRound ?> questions a level, a countdown on every one, and the next
         level opens the moment you clear this one.
         Pass mark <?= quiz_pass_percent() ?>%.</p>
    <?php else: ?>
      <p><?= (int) $perRound ?> questions a round, a countdown on every single one,
         and coins the moment you get it right. Pick your level.</p>
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

<?php if (quiz_level_mode()): ?>

    <?php if (!$uid): ?>
      <div class="card mb-3" style="padding:18px 20px">
        <strong>Log in to keep your progress.</strong>
        <span class="text-muted"> Levels, unlocks and coins are saved to your account.</span>
        <a class="btn btn-primary btn-sm" style="margin-left:10px" href="<?= url('login.php') ?>">Log in</a>
      </div>
    <?php endif; ?>

    <?php foreach (quiz_chain() as $d):
      $lv        = $levels[$d];
      $totalLv   = quiz_level_count($d, $activeCat);
      $cleared   = $uid ? quiz_cleared_count($uid, $d) : 0;
      $openDiff  = $uid ? quiz_difficulty_open($uid, $d, $activeCat) : ($d === 'easy');
      $nextLv    = $uid ? quiz_next_level($uid, $d) : 1;
      $prog      = $uid ? quiz_progress($uid, $d) : [];
      $unseen    = $uid ? quiz_unseen_count($uid, $d, $activeCat) : $counts[$d];
      $pct       = $totalLv > 0 ? round(min($cleared, $totalLv) / $totalLv * 100) : 0;
    ?>
      <div class="card lv-block mb-3" style="padding:20px">
        <div class="row between" style="align-items:flex-start;gap:14px;flex-wrap:wrap">
          <div>
            <h2 style="margin:0"><?= $lv['icon'] ?> <?= e($lv['label']) ?>
              <?php if (!$openDiff): ?>
                <span class="badge badge-red">Locked</span>
              <?php elseif ($totalLv && $cleared >= $totalLv): ?>
                <span class="badge badge-gold">All cleared</span>
              <?php endif; ?>
            </h2>
            <p class="text-muted" style="margin:4px 0 0"><?= e($lv['blurb']) ?></p>
          </div>
          <div class="text-right">
            <div class="text-dim">Cleared</div>
            <div style="font-weight:800;font-size:1.2rem">
              <?= (int) min($cleared, $totalLv) ?> / <?= (int) $totalLv ?></div>
          </div>
        </div>

        <div class="lv-bar mt-2"><i style="width:<?= (int) $pct ?>%"></i></div>

        <div class="row mt-2" style="gap:18px;flex-wrap:wrap;font-size:.86rem" >
          <span class="text-muted"><?= (int) $lv['seconds'] ?>s per question</span>
          <span class="text-gold">+<?= num($lv['reward']) ?> coins per correct answer</span>
          <span class="text-muted"><?= num($counts[$d]) ?> questions in the bank</span>
          <?php if ($uid): ?>
            <span class="text-muted"><?= num($unseen) ?> you have not seen yet</span>
          <?php endif; ?>
        </div>

        <?php if (!$totalLv): ?>
          <p class="text-muted mt-3">Not enough questions for a level here yet —
            <?= (int) quiz_per_level() ?> are needed for one level.</p>

        <?php elseif (!$openDiff): ?>
          <?php
            $chain = quiz_chain();
            $prev  = $chain[array_search($d, $chain, true) - 1];
            $need  = quiz_level_count($prev, $activeCat) - quiz_cleared_count($uid, $prev);
          ?>
          <p class="text-muted mt-3">&#128274; Clear <?= (int) max(0, $need) ?> more
            <?= e($levels[$prev]['label']) ?> level<?= $need === 1 ? '' : 's' ?> to open this.</p>

        <?php else: ?>
          <div class="lv-grid mt-3">
            <?php for ($n = 1; $n <= $totalLv; $n++):
              $row    = $prog[$n] ?? null;
              $done   = $row && $row['passed'];
              $isNow  = ($n === $nextLv);
              $locked = $uid ? ($n > $nextLv) : ($n > 1);
              $cls    = $done ? 'done' : ($locked ? 'lock' : 'now');
              $href   = url('quiz-play.php?level=' . $d . '&lv=' . $n . $catQs);
            ?>
              <?php if ($locked): ?>
                <span class="lv-chip lock" title="Clear level <?= $n - 1 ?> first">
                  <b><?= $n ?></b><i>&#128274;</i></span>
              <?php else: ?>
                <a class="lv-chip <?= $cls ?>" href="<?= e($href) ?>"
                   title="<?= $done ? 'Cleared — replay for practice' : 'Play level ' . $n ?>">
                  <b><?= $n ?></b>
                  <i><?= $done ? '&#10003; ' . (int) $row['best_percent'] . '%' : ($isNow ? 'Play' : '') ?></i>
                </a>
              <?php endif; ?>
            <?php endfor; ?>
          </div>

          <?php if ($uid && $nextLv <= $totalLv): ?>
            <a class="btn btn-primary mt-3"
               href="<?= url('quiz-play.php?level=' . $d . '&lv=' . $nextLv . $catQs) ?>">
               Continue &mdash; Level <?= (int) $nextLv ?> &rarr;</a>
          <?php elseif (!$uid): ?>
            <a class="btn btn-primary mt-3"
               href="<?= url('quiz-play.php?level=' . $d . '&lv=1' . $catQs) ?>">
               Start Level 1 &rarr;</a>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

<?php else: ?>

    <div class="quiz-levels">
      <?php foreach ($levels as $key => $lv):
        $n = $counts[$key] ?? 0; ?>
        <div class="card card-hover level-card lv-<?= e($key) ?> reveal">
          <div class="lv-ico"><?= $lv['icon'] ?></div>
          <h3><?= e($lv['label']) ?></h3>
          <div class="lv-meta"><?= e($lv['blurb']) ?></div>
          <div class="kv"><span class="k">Time per question</span>
            <span class="v"><?= (int) $lv['seconds'] ?>s</span></div>
          <div class="kv"><span class="k">Coins per correct</span>
            <span class="v text-gold">+<?= num($lv['reward']) ?></span></div>
          <div class="kv"><span class="k">Questions available</span>
            <span class="v"><?= num($n) ?></span></div>
          <?php if (isset($best[$key])): ?>
            <div class="kv"><span class="k">Your best</span>
              <span class="v text-green"><?= (int) $best[$key] ?> correct</span></div>
          <?php endif; ?>
          <div class="mt-3">
            <?php if (!$n): ?>
              <button class="btn btn-ghost btn-block" disabled>No questions yet</button>
            <?php else: ?>
              <a class="btn btn-primary btn-block"
                 href="<?= url('quiz-play.php?level=' . $key . $catQs) ?>">Play <?= e($lv['label']) ?></a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

<?php endif; ?>

    <?php if ($mine && (int) $mine['rounds'] > 0): ?>
      <div class="card mt-3" style="padding:18px 20px">
        <div class="row" style="gap:26px;flex-wrap:wrap">
          <div><div class="text-dim">Rounds played</div>
            <div style="font-weight:800"><?= num($mine['rounds']) ?></div></div>
          <div><div class="text-dim">Correct answers</div>
            <div style="font-weight:800"><?= num($mine['correct']) ?></div></div>
          <div><div class="text-dim">Coins from the quiz</div>
            <div style="font-weight:800" class="text-gold"><?= num($mine['coins']) ?></div></div>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($today): ?>
      <h2 class="mt-4">Today&rsquo;s best rounds</h2>
      <div class="table-scroll card">
        <table class="tbl" style="min-width:520px">
          <thead><tr><th>Player</th><th>Level</th><th>Score</th><th>Coins</th></tr></thead>
          <tbody>
          <?php foreach ($today as $t): ?>
            <tr>
              <td><?= e($t['username']) ?></td>
              <td class="text-muted"><?= e(ucfirst($t['difficulty'])) ?>
                <?= $t['level_no'] ? '&middot; L' . (int) $t['level_no'] : '' ?></td>
              <td><?= (int) $t['correct'] ?> / <?= (int) $t['total'] ?></td>
              <td class="text-gold">+<?= num($t['coins_earned']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  </div>
</section>

<style>
  .lv-bar { height: 8px; border-radius: 99px; background: rgba(255,255,255,.07); overflow: hidden; }
  .lv-bar i { display: block; height: 100%; background: linear-gradient(90deg,#6aa521,#b6e857); }
  .lv-grid { display: flex; flex-wrap: wrap; gap: 10px; }
  .lv-chip {
    width: 74px; padding: 10px 6px; border-radius: 12px; text-align: center;
    border: 1px solid var(--line); background: rgba(255,255,255,.04);
    text-decoration: none; color: inherit; display: block;
  }
  .lv-chip b { display: block; font-size: 1.05rem; }
  .lv-chip i { display: block; font-style: normal; font-size: .72rem; opacity: .8; min-height: 1em; }
  .lv-chip.now  { border-color: rgba(143,206,63,.6); background: rgba(143,206,63,.12); }
  .lv-chip.done { border-color: rgba(240,200,96,.45); background: rgba(240,200,96,.10); }
  .lv-chip.lock { opacity: .45; cursor: not-allowed; }
  .lv-chip.now:hover, .lv-chip.done:hover { transform: translateY(-2px); transition: transform .12s; }
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
