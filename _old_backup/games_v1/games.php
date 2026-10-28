<?php
require_once __DIR__ . '/config/config.php';

$page_title = 'All Games';
$page_desc  = 'Browse every skill-based game on Asviora Games — 3D Cube Solver, Memory Match and Sudoku. Free to play in your browser.';

$cat = get('cat');
$cats = fetch_all('SELECT DISTINCT category FROM games WHERE is_active = 1 ORDER BY category');

$sql    = 'SELECT * FROM games WHERE is_active = 1';
$params = [];
if ($cat !== '') {
    $sql .= ' AND category = ?';
    $params[] = $cat;
}
$sql .= ' ORDER BY sort_order, id';
$games = fetch_all($sql, $params);

require __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
  <div class="container">
    <div class="crumb"><a href="<?= url('index.php') ?>">Home</a> &rsaquo; Games</div>
    <h1>The <span class="gradient-text">Arena</span></h1>
    <p>Three browser-native skill games with difficulty levels, personal bests and live
       leaderboards. Nothing to download.</p>
  </div>
</section>

<section class="section">
  <div class="container">

    <div class="tabs">
      <a href="<?= url('games.php') ?>" class="<?= $cat === '' ? 'active' : '' ?>">All games</a>
      <?php foreach ($cats as $c): ?>
        <a href="<?= url('games.php?cat=' . urlencode($c['category'])) ?>"
           class="<?= $cat === $c['category'] ? 'active' : '' ?>"><?= e($c['category']) ?></a>
      <?php endforeach; ?>
    </div>

    <?php if (!$games): ?>
      <div class="card empty">
        <div class="em-ico">&#127918;</div>
        <h3>Nothing here yet</h3>
        <p>No games match this filter. Try another category.</p>
        <a href="<?= url('games.php') ?>" class="btn btn-primary">Show all games</a>
      </div>
    <?php else: ?>
      <div class="grid g-3">
        <?php foreach ($games as $g):
          $best = fetch_one(
            'SELECT s.score, u.username FROM scores s JOIN users u ON u.id = s.user_id
             WHERE s.game_id = ? ORDER BY s.score DESC LIMIT 1', [$g['id']]);
          $myBest = 0;
          if (is_logged_in()) {
              $myBest = (int) fetch_col(
                'SELECT MAX(score) FROM scores WHERE game_id = ? AND user_id = ?',
                [$g['id'], current_user()['id']], 0);
          }
        ?>
          <article class="card card-hover game-card reveal">
            <div class="game-thumb">
              <div class="game-tags">
                <?php if ($g['is_featured']): ?><span class="badge badge-gold">Featured</span><?php endif; ?>
                <span class="badge"><?= e($g['category']) ?></span>
              </div>
              <?php if ($g['thumbnail'] && file_exists(ROOT_PATH . '/uploads/games/' . $g['thumbnail'])): ?>
                <img src="<?= UPLOAD_URL ?>/games/<?= e($g['thumbnail']) ?>" alt="<?= e($g['title']) ?>">
              <?php else: ?>
                <span class="g-icon"><?= $g['icon'] ?: '&#127918;' ?></span>
              <?php endif; ?>
            </div>
            <div class="game-body">
              <h3><?= e($g['title']) ?></h3>
              <p><?= e($g['tagline'] ?: excerpt($g['description'], 130)) ?></p>

              <div class="kv"><span class="k">Reward</span>
                <span class="v text-gold">+<?= num($g['reward_coins']) ?> coins</span></div>
              <div class="kv"><span class="k">Top score</span>
                <span class="v"><?= $best ? num($best['score']) . ' <span class="text-dim">&middot; ' . e($best['username']) . '</span>' : '&mdash;' ?></span></div>
              <?php if (is_logged_in()): ?>
                <div class="kv"><span class="k">Your best</span>
                  <span class="v text-green"><?= $myBest ? num($myBest) : 'Not played' ?></span></div>
              <?php endif; ?>
              <div class="kv"><span class="k">Rounds played</span>
                <span class="v"><?= num($g['plays']) ?></span></div>

              <div class="game-foot mt-2">
                <a href="<?= url('leaderboard.php?game=' . urlencode($g['slug'])) ?>"
                   class="btn btn-ghost btn-sm">Board</a>
                <a href="<?= url('play.php?game=' . urlencode($g['slug'])) ?>"
                   class="btn btn-primary btn-sm">Play Now &rarr;</a>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="card cta-band mt-4 reveal" style="border-radius:var(--radius)">
      <h3 style="margin-bottom:6px">More games are on the way</h3>
      <p class="text-muted" style="margin:0">Quiz tournaments, chess and strategy puzzles are next.
        Follow us on <a href="<?= e(setting('social_instagram')) ?>" target="_blank" rel="noopener">Instagram</a>
        or <a href="<?= e(setting('social_facebook')) ?>" target="_blank" rel="noopener">Facebook</a> for launch news.</p>
    </div>

  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
