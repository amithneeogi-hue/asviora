<?php
/**
 * Quiz contest — lobby.
 * Pick a category + one of the three levels (Easy / Mid / Hard), then play.
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

$u       = current_user();
$levels  = quiz_levels();
$perRound = quiz_round_size();
$cats    = fetch_all('SELECT c.*, (SELECT COUNT(*) FROM quiz_questions q
                                    WHERE q.category_id = c.id AND q.is_active = 1) AS qcount
                      FROM quiz_categories c
                      WHERE c.is_active = 1
                      ORDER BY c.sort_order, c.name');

$activeCat = (int) get('cat');

// counts per level, so we never offer a level with no questions
$counts = [];
foreach (fetch_all(
    'SELECT difficulty, COUNT(*) AS n FROM quiz_questions
      WHERE is_active = 1' . ($activeCat ? ' AND category_id = ' . $activeCat : '') . '
      GROUP BY difficulty') as $r) {
    $counts[$r['difficulty']] = (int) $r['n'];
}

// personal stats
$mine = null;
$best = [];
if ($u) {
    $mine = fetch_one(
        'SELECT COUNT(*) AS rounds, COALESCE(SUM(correct),0) AS correct,
                COALESCE(SUM(coins_earned),0) AS coins
           FROM quiz_sessions WHERE user_id = ? AND status = "finished"',
        [(int) $u['id']]
    );
    foreach (fetch_all(
        'SELECT difficulty, MAX(correct) AS best FROM quiz_sessions
          WHERE user_id = ? AND status = "finished" GROUP BY difficulty',
        [(int) $u['id']]) as $r) {
        $best[$r['difficulty']] = (int) $r['best'];
    }
}

// today's leaders
$today = fetch_all(
    'SELECT s.correct, s.total, s.difficulty, s.coins_earned, u.username, u.avatar, u.name
       FROM quiz_sessions s
       JOIN users u ON u.id = s.user_id
      WHERE s.status = "finished" AND DATE(s.finished_at) = CURDATE()
      ORDER BY s.correct DESC, s.time_taken ASC
      LIMIT 8'
);

$page_title = 'Quiz Contest';
$page_desc  = 'Three levels, a live countdown on every question and coins for every correct answer.';
require __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
  <div class="container">
    <div class="crumb"><a href="<?= url('index.php') ?>">Home</a> &rsaquo; Quiz</div>
    <h1>Quiz <span class="gradient-text">Contest</span></h1>
    <p><?= (int) $perRound ?> questions a round, a countdown on every single one,
       and coins the moment you get it right. Pick your level.</p>
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

    <div class="quiz-levels">
      <?php foreach ($levels as $key => $lv):
        $n = $counts[$key] ?? 0;
        $ready = $n > 0;
      ?>
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
            <?php if (!$ready): ?>
              <span class="btn btn-ghost btn-block is-disabled">No questions yet</span>
            <?php elseif (!$u): ?>
              <a class="btn btn-ghost btn-block"
                 href="<?= url('login.php') ?>">Log in to play</a>
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
                <td><span class="badge badge-info"><?= e(ucfirst($r['difficulty'])) ?></span></td>
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

<section class="section-sm">
  <div class="container">
    <div class="cta-band">
      <h2>Spin before you play</h2>
      <p class="text-muted">One free spin every day &mdash; up to 1,000 coins, no entry fee.</p>
      <a class="btn btn-gold btn-lg mt-2" href="<?= url('spin.php') ?>">&#127920; Spin the wheel</a>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
