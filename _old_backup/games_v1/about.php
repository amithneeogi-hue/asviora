<?php
require_once __DIR__ . '/config/config.php';

$page_title = 'About Us';
$page_desc  = 'Asviora Games is a skill-based gaming platform built on the belief that games should be won with a sharp mind, not a lucky roll.';

$totPlayers = (int) fetch_col('SELECT COUNT(*) FROM users WHERE status = "active"', [], 0);
$totRounds  = (int) fetch_col('SELECT COUNT(*) FROM scores', [], 0);
$totGames   = (int) fetch_col('SELECT COUNT(*) FROM games WHERE is_active = 1', [], 0);

require __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
  <div class="container">
    <div class="crumb"><a href="<?= url('index.php') ?>">Home</a> &rsaquo; About</div>
    <h1>Where <span class="gradient-text">skill wins</span></h1>
    <p>We build games that reward what you know and how fast you think &mdash; nothing else.</p>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="grid g-2" style="gap:52px;align-items:center">
      <div>
        <span class="eyebrow">Our story</span>
        <h2>A fair arena for people who like to think</h2>
        <p class="text-muted">Most online games hide a slot machine somewhere. A random drop, a
          weighted roll, a paid upgrade that quietly decides the match before it starts. We wanted
          the opposite: an arena where the only variable is the player.</p>
        <p class="text-muted">Asviora Games started with three titles that cannot be faked &mdash;
          a 3D cube that demands spatial reasoning, a memory board that measures recall under
          pressure, and sudoku, which is pure deduction with nowhere to hide. Every score comes
          from measurable performance: your moves, your time, your accuracy.</p>
        <p class="text-muted">We keep the economy virtual on purpose. Coins and XP exist to track
          progress and unlock challenges, not to move money around. That keeps the focus on the
          part that actually matters &mdash; getting better.</p>
      </div>
      <div class="grid" style="gap:18px">
        <div class="card stat-card"><div class="sc-num" data-count="<?= max($totPlayers, 1) ?>">0</div>
          <div class="sc-lbl">Registered players</div></div>
        <div class="card stat-card"><div class="sc-num" data-count="<?= $totRounds ?>">0</div>
          <div class="sc-lbl">Rounds completed</div></div>
        <div class="card stat-card"><div class="sc-num" data-count="<?= $totGames ?>">0</div>
          <div class="sc-lbl">Live game titles</div></div>
      </div>
    </div>
  </div>
</section>

<section class="section section-tint">
  <div class="container">
    <div class="head center">
      <span class="eyebrow">What we stand for</span>
      <h2>Four rules we do not bend</h2>
    </div>
    <div class="grid g-4">
      <?php
      $vals = [
        ['&#9878;', 'Fairness first', 'Identical rules for every player. Server-side validation on every score, and no paid advantage of any kind.'],
        ['&#128274;', 'No real-money play', 'Virtual coins only. Nothing to deposit, nothing to withdraw, no wagering — by design, not as a temporary state.'],
        ['&#128200;', 'Measurable progress', 'Personal bests, XP levels and full match history so improvement is visible rather than vague.'],
        ['&#129504;', 'Respect your time', 'Fast loads, short rounds and no dark patterns engineered to keep you scrolling.'],
      ];
      foreach ($vals as $v): ?>
        <div class="card card-hover feature reveal">
          <div class="f-ico"><?= $v[0] ?></div>
          <h3><?= e($v[1]) ?></h3>
          <p><?= e($v[2]) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="head center">
      <span class="eyebrow">The roadmap</span>
      <h2>What we are building next</h2>
      <p>An honest look at where the platform is heading. Dates move; the order rarely does.</p>
    </div>
    <div class="grid g-2" style="gap:22px">
      <?php
      $road = [
        ['Live now', 'badge-green', '3D Cube Solver, Memory Match and Sudoku, with per-game leaderboards, coin rewards and XP levels.'],
        ['In progress', 'badge-gold', 'Quiz mode with admin-managed question banks, plus daily challenge boards that reset every midnight.'],
        ['Next up', 'badge-info', 'Chess against a tuneable engine, weekly championships and an achievements system.'],
        ['Later', 'badge', 'Strategy card puzzles, regional language support and a native mobile wrapper.'],
      ];
      foreach ($road as $r): ?>
        <div class="card reveal">
          <span class="badge <?= $r[1] ?>"><?= e($r[0]) ?></span>
          <p class="text-muted mt-2" style="margin-bottom:0"><?= e($r[2]) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section-sm">
  <div class="container">
    <div class="cta-band reveal">
      <span class="eyebrow">Say hello</span>
      <h2>Questions, feedback or partnerships</h2>
      <p class="text-muted" style="max-width:520px;margin:0 auto">
        We read everything that comes in. Email us, or reach out on Facebook or Instagram.</p>
      <div class="hero-actions" style="justify-content:center;margin:24px 0 0">
        <a href="<?= url('contact.php') ?>" class="btn btn-primary btn-lg">Contact Us</a>
        <a href="<?= url('games.php') ?>" class="btn btn-ghost btn-lg">Play a Game</a>
      </div>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
