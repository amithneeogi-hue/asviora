<?php
require_once __DIR__ . '/config/config.php';

$page_title = '';

// The three HTML5 games, the Quiz Contest and the Daily Spin, all normalised
// into one list so they render as one set of five tiles — see includes/arena.php.
$arena   = arena_items();

$posts   = fetch_all('SELECT p.*, c.name AS cat_name, c.slug AS cat_slug
                      FROM blog_posts p LEFT JOIN blog_categories c ON c.id = p.category_id
                      WHERE p.status = "published"
                      ORDER BY p.is_featured DESC, p.published_at DESC LIMIT 3');

$totPlayers = (int) fetch_col('SELECT COUNT(*) FROM users WHERE status = "active"', [], 0);
$totRounds  = (int) fetch_col('SELECT COUNT(*) FROM scores', [], 0);
$totGames   = count($arena);
$topScore   = (int) fetch_col('SELECT MAX(score) FROM scores', [], 0);

$quizOn = quiz_on();
$spinOn = spin_on();
$quizQs = $quizOn
    ? (int) fetch_col('SELECT COUNT(*) FROM quiz_questions WHERE is_active = 1', [], 0) : 0;
$topSpin = $spinOn
    ? (int) fetch_col('SELECT COALESCE(MAX(coins),0) FROM spin_results', [], 0) : 0;

$topPlayers = fetch_all(
    'SELECT u.id, u.username, u.name, u.avatar, u.level,
            COALESCE(SUM(s.score),0) AS total, COUNT(s.id) AS rounds
     FROM users u LEFT JOIN scores s ON s.user_id = u.id
     WHERE u.status = "active"
     GROUP BY u.id HAVING rounds > 0
     ORDER BY total DESC LIMIT 5'
);

require __DIR__ . '/includes/header.php';
?>

<!-- ================= HERO ================= -->
<section class="hero">
  <div class="container hero-grid">
    <div>
      <span class="eyebrow reveal in">100% skill &middot; 0% luck</span>
      <h1 class="reveal in">
        Play smart.<br>
        <span class="gradient-text">Win on skill</span>&nbsp;alone.
      </h1>
      <p class="hero-lead reveal in">
        <?= e(setting('site_name', 'Aegis Esports')) ?> is a skill-based arena built around logic,
        memory, quiz knowledge and 3D spatial thinking. No random rolls, no pay-to-win
        &mdash; just you, the clock and the leaderboard.
      </p>
      <div class="hero-actions reveal in">
        <a href="<?= url('games.php') ?>" class="btn btn-primary btn-lg">&#127918; Start Playing Free</a>
        <?php if ($quizOn): ?>
          <a href="<?= url('quiz.php') ?>" class="btn btn-gold btn-lg">&#129504; Quiz Contest</a>
        <?php else: ?>
          <a href="<?= url('leaderboard.php') ?>" class="btn btn-ghost btn-lg">&#127942; View Leaderboard</a>
        <?php endif; ?>
      </div>
      <div class="hero-stats reveal in">
        <div>
          <div class="st-num" data-count="<?= max($totPlayers, 1) ?>">0</div>
          <div class="st-lbl">Players</div>
        </div>
        <div>
          <div class="st-num" data-count="<?= max($totRounds, 0) ?>">0</div>
          <div class="st-lbl">Rounds played</div>
        </div>
        <div>
          <div class="st-num" data-count="<?= $totGames ?>">0</div>
          <div class="st-lbl">Ways to play</div>
        </div>
        <div>
          <div class="st-num" data-count="<?= $topScore ?>">0</div>
          <div class="st-lbl">Top score</div>
        </div>
      </div>
    </div>

    <div class="hero-visual">
      <div class="hero-orb"></div>
      <?php
        // three floating tiles: lead with a game, then the quiz and the spin if
        // they are switched on, so the hero previews the whole arena
        $lead   = array_values(array_filter($arena, fn($i) => $i['kind'] !== 'game'));
        $plain  = array_values(array_filter($arena, fn($i) => $i['kind'] === 'game'));
        $tiles  = array_slice(array_merge(array_slice($plain, 0, 1), $lead, array_slice($plain, 1)), 0, 3);
        $pos    = ['fc-1', 'fc-2', 'fc-3'];
        foreach ($tiles as $n => $t):
      ?>
        <a href="<?= e($t['play_url']) ?>" class="float-card <?= $pos[$n] ?>">
          <div class="fc-thumb">
            <?php if ($t['thumb']): ?>
              <img src="<?= e($t['thumb']) ?>" alt="<?= e($t['title']) ?>">
            <?php else: ?>
              <span class="fc-icon"><?= $t['icon'] ?></span>
            <?php endif; ?>
          </div>
          <div class="fc-title"><?= e($t['title']) ?></div>
          <div class="fc-meta"><?= e($t['category']) ?></div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ================= GAMES ================= -->
<section class="section section-tint" id="games">
  <div class="container">
    <div class="head center">
      <span class="eyebrow">The Arena</span>
      <h2>Games that reward a sharp mind</h2>
      <p>Every title is built for the browser &mdash; no download, no install. Jump in, beat your
         own record, then climb past everyone else's.</p>
    </div>

    <div class="grid g-3">
      <?php foreach ($arena as $i): ?>
        <?= arena_card($i, true) ?>
      <?php endforeach; ?>
    </div>

    <?php if (!$arena): ?>
      <div class="card empty"><div class="em-ico">&#127918;</div>
        <h3>No games published yet</h3>
        <p>Add games from the admin panel to see them listed here.</p></div>
    <?php else: ?>
      <div class="center mt-4">
        <a href="<?= url('games.php') ?>" class="btn btn-ghost">See all <?= num(count($arena)) ?> ways to play &rarr;</a>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- ================= QUIZ LEVELS + SPIN LADDER =================
     The Quiz and the Spin now sit in the games grid above alongside the three
     titles, so this band no longer repeats them — it only shows the detail the
     tiles have no room for: the three quiz clocks and the prize ladder. -->
<?php if ($quizOn || $spinOn): ?>
<section class="section section-tint">
  <div class="container">
    <div class="head center">
      <span class="eyebrow">Inside the extras</span>
      <h2>How the Quiz and the Spin pay out</h2>
      <p>Both sit in the arena above with everything else &mdash; here is what the
         clocks and the prize ladder actually look like.</p>
    </div>

    <div class="grid g-2">
      <?php if ($quizOn): ?>
        <div class="card reveal" style="padding:28px">
          <h3 style="font-size:1.15rem;margin-bottom:16px">&#129504; Three difficulty levels</h3>
          <?php foreach (quiz_levels() as $k => $lv): ?>
            <div class="kv">
              <span class="k">
                <span class="badge badge-<?= $k === 'hard' ? 'gold' : ($k === 'mid' ? 'info' : 'green') ?>">
                  <?= $lv['icon'] ?> <?= e($lv['label']) ?></span>
                <span class="text-dim">&nbsp;<?= (int) $lv['seconds'] ?>s per question</span>
              </span>
              <span class="v text-gold">+<?= num((int) $lv['reward']) ?> coins</span>
            </div>
          <?php endforeach; ?>
          <div class="kv"><span class="k">Perfect round bonus</span>
            <span class="v text-gold">+<?= num((int) setting('quiz_perfect_bonus', '100')) ?> coins</span></div>
          <div class="kv"><span class="k">Questions in the bank</span>
            <span class="v"><?= num($quizQs) ?></span></div>
          <a href="<?= url('quiz.php') ?>" class="btn btn-primary btn-block mt-3">Enter the quiz</a>
        </div>
      <?php endif; ?>

      <?php if ($spinOn): ?>
        <div class="card reveal" style="padding:28px">
          <h3 style="font-size:1.15rem;margin-bottom:16px">&#127920; The prize ladder</h3>
          <div class="spin-ladder">
            <?php
              $segs = spin_segments();
              usort($segs, static fn($a, $b) => $b['coins'] <=> $a['coins']);
              $wTot = array_sum(array_column($segs, 'weight')) ?: 1;
              foreach (array_slice($segs, 0, 6) as $s):
                $pc = round($s['weight'] / $wTot * 100);
            ?>
              <div class="sl-row">
                <span class="sl-dot" style="background:<?= e($s['color']) ?>"></span>
                <span class="sl-coin">+<?= num($s['coins']) ?></span>
                <span class="sl-bar"><i style="width:<?= max(4, $pc) ?>%;background:<?= e($s['color']) ?>"></i></span>
                <span class="sl-pc"><?= $pc ?>%</span>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="kv mt-2"><span class="k">Cost to spin</span>
            <span class="v text-green">Free &middot; once a day</span></div>
          <div class="kv"><span class="k">Biggest win so far</span>
            <span class="v text-gold"><?= $topSpin ? '+' . num($topSpin) . ' coins' : '&mdash;' ?></span></div>
          <a href="<?= url('spin.php') ?>" class="btn btn-gold btn-block mt-3">Spin the wheel</a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ================= WHY US ================= -->
<section class="section">
  <div class="container">
    <div class="head center">
      <span class="eyebrow">Why <?= e(setting('site_name', 'Aegis Esports')) ?></span>
      <h2>Built for players who like a fair fight</h2>
    </div>
    <div class="grid g-4">
      <?php
      $features = [
        ['&#127919;', 'Pure skill scoring', 'Scores come from your moves, your speed and your accuracy. No dice, no loot boxes, no hidden multipliers.'],
        ['&#129689;', 'Virtual coins only', 'Compete for coins, XP and leaderboard rank. No deposits, no withdrawals, no real-money wagering anywhere on the platform.'],
        ['&#128241;', 'Plays anywhere', 'Every game is browser-native and touch-friendly. Same experience on a phone, tablet or desktop.'],
        ['&#128200;', 'Track your growth', 'Match history, personal bests, XP levels and per-game leaderboards so you can see the improvement.'],
        ['&#9889;', 'Instant rounds', 'No lobby waits and no matchmaking queue. Load a game and you are playing in under two seconds.'],
        ['&#128737;', 'Fair-play checks', 'Server-side score validation, rate limiting and anomaly flags keep the boards honest.'],
        ['&#127942;', 'Daily leaderboards', 'Daily, weekly and all-time boards reset the race so newcomers always have a shot at the top.'],
        ['&#128218;', 'Learn as you play', 'Guides, solver walkthroughs and strategy articles in the blog to help you get measurably better.'],
      ];
      foreach ($features as $f): ?>
        <div class="card card-hover feature reveal">
          <div class="f-ico"><?= $f[0] ?></div>
          <h3><?= e($f[1]) ?></h3>
          <p><?= e($f[2]) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ================= HOW IT WORKS ================= -->
<section class="section section-tint">
  <div class="container">
    <div class="grid g-2" style="gap:52px;align-items:center">
      <div>
        <span class="eyebrow">How it works</span>
        <h2>From sign-up to leaderboard in four steps</h2>
        <p class="text-muted">Creating an account takes about twenty seconds and comes with a
           welcome coin balance so you can start competing straight away.</p>
        <a href="<?= url('register.php') ?>" class="btn btn-primary mt-2">Create Free Account</a>
      </div>
      <div class="grid" style="gap:24px">
        <?php
        $steps = [
          ['Register free', 'Pick a username, set a password, done. You get ' . num((int) setting('signup_bonus', '500')) . ' welcome coins instantly.'],
          ['Choose how to play', 'Cube, memory, sudoku, the timed quiz or the daily spin &mdash; all in one arena.'],
          ['Play and score', 'Your result is validated server-side and turned into points, coins and XP.'],
          ['Climb the board', 'Beat your best, pass other players, and hold the top spot as long as you can.'],
        ];
        foreach ($steps as $i => $s): ?>
          <div class="step reveal">
            <div class="s-num"><?= $i + 1 ?></div>
            <h3><?= e($s[0]) ?></h3>
            <p><?= $s[1] ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<!-- ================= LEADERBOARD PREVIEW ================= -->
<?php if ($topPlayers): ?>
<section class="section">
  <div class="container">
    <div class="head center">
      <span class="eyebrow">Hall of fame</span>
      <h2>Current top five</h2>
      <p>Ranked by total points earned across every game.</p>
    </div>

    <div class="table-wrap reveal">
      <div class="table-scroll">
        <table class="tbl">
          <thead>
            <tr><th style="width:70px">Rank</th><th>Player</th><th>Level</th>
                <th>Rounds</th><th style="text-align:right">Total points</th></tr>
          </thead>
          <tbody>
            <?php foreach ($topPlayers as $i => $p): ?>
              <tr class="<?= (current_user() && current_user()['id'] == $p['id']) ? 'is-me' : '' ?>">
                <td><span class="rank r<?= $i + 1 ?>"><?= $i + 1 ?></span></td>
                <td>
                  <div class="u-cell">
                    <img src="<?= e(avatar_url($p)) ?>" alt="">
                    <div>
                      <div class="u-name"><?= e($p['username']) ?></div>
                      <div class="u-sub"><?= e($p['name']) ?></div>
                    </div>
                  </div>
                </td>
                <td><span class="badge badge-green">Lv <?= (int) $p['level'] ?></span></td>
                <td><?= num($p['rounds']) ?></td>
                <td style="text-align:right;font-weight:900"><?= num($p['total']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="center mt-3">
      <a href="<?= url('leaderboard.php') ?>" class="btn btn-ghost">See the full leaderboard</a>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ================= BLOG ================= -->
<?php if ($posts): ?>
<section class="section section-tint">
  <div class="container">
    <div class="row between mb-3" style="align-items:flex-end">
      <div class="head" style="margin-bottom:0">
        <span class="eyebrow">From the blog</span>
        <h2>Guides, tips and updates</h2>
      </div>
      <a href="<?= url('blog.php') ?>" class="btn btn-ghost btn-sm">All articles &rarr;</a>
    </div>

    <div class="grid g-3">
      <?php foreach ($posts as $p): $cov = cover_url($p['cover_image']); ?>
        <article class="card card-hover post-card reveal">
          <div class="post-cover">
            <?php if ($cov): ?>
              <img src="<?= e($cov) ?>" alt="<?= e($p['title']) ?>" loading="lazy">
            <?php else: ?>
              <span class="pc-fallback">&#128221;</span>
            <?php endif; ?>
          </div>
          <div class="post-body">
            <div class="post-meta">
              <?php if ($p['cat_name']): ?>
                <span class="badge badge-green"><?= e($p['cat_name']) ?></span>
              <?php endif; ?>
              <span><?= fdate($p['published_at']) ?></span>
            </div>
            <h3><a href="<?= url('post.php?slug=' . urlencode($p['slug'])) ?>"><?= e($p['title']) ?></a></h3>
            <p><?= e($p['excerpt'] ?: excerpt($p['content'], 120)) ?></p>
            <a href="<?= url('post.php?slug=' . urlencode($p['slug'])) ?>"
               class="btn btn-ghost btn-sm">Read article</a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ================= CTA ================= -->
<section class="section-sm">
  <div class="container">
    <div class="cta-band reveal">
      <span class="eyebrow">Ready when you are</span>
      <h2>Your seat at the table is free</h2>
      <p class="text-muted" style="max-width:520px;margin:0 auto">
        Sign up, claim your welcome coins and see how far pure skill takes you.
        Follow us on Facebook and Instagram for launch news and weekly challenges.
      </p>
      <div class="hero-actions" style="justify-content:center;margin:26px 0 0">
        <?php if (is_logged_in()): ?>
          <a href="<?= url('games.php') ?>" class="btn btn-primary btn-lg">Play a Round</a>
          <a href="<?= url('dashboard.php') ?>" class="btn btn-ghost btn-lg">My Dashboard</a>
        <?php else: ?>
          <a href="<?= url('register.php') ?>" class="btn btn-primary btn-lg">Create Free Account</a>
          <a href="<?= url('login.php') ?>" class="btn btn-ghost btn-lg">I already have one</a>
        <?php endif; ?>
      </div>

      <form action="<?= url('contact.php') ?>" method="post" class="subscribe">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="subscribe">
        <input type="email" name="email" class="form-control" placeholder="you@email.com"
               required aria-label="Email for updates">
        <button class="btn btn-gold" type="submit">Notify Me</button>
      </form>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
