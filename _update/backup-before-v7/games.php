<?php
require_once __DIR__ . '/config/config.php';

// The arcade has its own switch in Admin -> Modules. Hidden means hidden:
// a bookmarked link lands back on the home page rather than on a page
// that should not be there.
require_module('games');

// Locked: the arcade list is for players, not passers-by.
require_play_login();

$page_title = 'All Games';
$page_desc  = 'Every way to play on Asviora Games — 3D Cube Solver, Memory Match, Sudoku, '
            . 'the timed Quiz Contest and the free Daily Spin. All free in your browser.';

$cat   = get('cat');
$cats  = arena_categories();
if ($cat !== '' && !in_array($cat, $cats, true)) {
    $cat = '';
}

$items = arena_items($cat);
$total = count(arena_items());

require __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
  <div class="container">
    <div class="crumb"><a href="<?= url('index.php') ?>">Home</a> &rsaquo; Games</div>
    <h1>The <span class="gradient-text">Arena</span></h1>
    <p><?= num($total) ?> browser-native ways to play &mdash; skill games with difficulty levels
       and personal bests, a timed quiz that pays per answer, and a free spin every day.
       Nothing to download.</p>
  </div>
</section>

<section class="section">
  <div class="container">

    <div class="tabs">
      <a href="<?= url('games.php') ?>" class="<?= $cat === '' ? 'active' : '' ?>">
        All <span class="tab-n"><?= num($total) ?></span>
      </a>
      <?php foreach ($cats as $c): ?>
        <a href="<?= url('games.php?cat=' . urlencode($c)) ?>"
           class="<?= $cat === $c ? 'active' : '' ?>">
          <?= e($c) ?> <span class="tab-n"><?= num(count(arena_items($c))) ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if (!$items): ?>
      <div class="card empty">
        <div class="em-ico">&#127918;</div>
        <h3>Nothing here yet</h3>
        <p>No games match this filter. Try another category.</p>
        <a href="<?= url('games.php') ?>" class="btn btn-primary">Show all games</a>
      </div>
    <?php else: ?>
      <div class="grid g-3">
        <?php foreach ($items as $i): ?>
          <?= arena_card($i, true) ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="card cta-band mt-4 reveal" style="border-radius:var(--radius)">
      <h3 style="margin-bottom:6px">More games are on the way</h3>
      <p class="text-muted" style="margin:0">Chess, strategy puzzles and live tournaments are next.
        Follow us on <a href="<?= e(setting('social_instagram')) ?>" target="_blank" rel="noopener">Instagram</a>
        or <a href="<?= e(setting('social_facebook')) ?>" target="_blank" rel="noopener">Facebook</a> for launch news.</p>
    </div>

  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
