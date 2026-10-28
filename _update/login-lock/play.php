<?php
require_once __DIR__ . '/config/config.php';

// No game without an account. One helper, every entry point.
require_play_login();

$slug = get('game');
$game = fetch_one('SELECT * FROM games WHERE slug = ? AND is_active = 1', [$slug]);

if (!$game) {
    http_response_code(404);
    $page_title = 'Game not found';
    require __DIR__ . '/includes/header.php';
    echo '<section class="section"><div class="container"><div class="card empty">'
       . '<div class="em-ico">&#128533;</div><h3>That game does not exist</h3>'
       . '<p>It may have been renamed or taken offline.</p>'
       . '<a href="' . url('games.php') . '" class="btn btn-primary">Browse all games</a>'
       . '</div></div></section>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$page_title = $game['title'];
$page_desc  = $game['tagline'] ?: excerpt($game['description'], 155);

// one-shot token so the score endpoint only accepts posts that came from this page
$playToken = bin2hex(random_bytes(16));
$_SESSION['play_tokens'][$game['slug']] = ['token' => $playToken, 'issued' => time()];

// count the play
q('UPDATE games SET plays = plays + 1 WHERE id = ?', [$game['id']]);

$board = fetch_all(
    'SELECT s.score, s.time_taken, s.difficulty, s.played_at, u.username, u.avatar, u.name
     FROM scores s JOIN users u ON u.id = s.user_id
     WHERE s.game_id = ? AND s.result = "win"
     ORDER BY s.score DESC LIMIT 10', [$game['id']]);

$me = current_user();
$myBest = $myRounds = 0;
if ($me) {
    $myBest   = (int) fetch_col('SELECT MAX(score) FROM scores WHERE game_id = ? AND user_id = ?',
                                [$game['id'], $me['id']], 0);
    $myRounds = (int) fetch_col('SELECT COUNT(*) FROM scores WHERE game_id = ? AND user_id = ?',
                                [$game['id'], $me['id']], 0);
}

$extra_head = '<meta name="asviora-game"'
    . ' data-slug="' . e($game['slug']) . '"'
    . ' data-token="' . e($playToken) . '"'
    . ' data-endpoint="' . url('api/submit_score.php') . '">';

require __DIR__ . '/includes/header.php';
?>

<section class="section-sm">
  <div class="container">

    <div class="crumb">
      <a href="<?= url('index.php') ?>">Home</a> &rsaquo;
      <a href="<?= url('games.php') ?>">Games</a> &rsaquo; <?= e($game['title']) ?>
    </div>

    <div class="play-head">
      <div>
        <h1 style="font-size:clamp(1.6rem,3.6vw,2.3rem);margin-bottom:6px">
          <?= $game['icon'] ?: '' ?> <?= e($game['title']) ?>
        </h1>
        <div class="row" style="gap:8px">
          <span class="badge badge-green"><?= e($game['category']) ?></span>
          <span class="badge badge-gold">+<?= num($game['reward_coins']) ?> coins per win</span>
          <span class="badge"><?= num($game['plays']) ?> rounds played</span>
        </div>
      </div>
      <div class="row" style="gap:9px">
        <button class="btn btn-ghost btn-sm" id="fsToggle">&#9974; Fullscreen</button>
        <a href="<?= url('leaderboard.php?game=' . urlencode($game['slug'])) ?>"
           class="btn btn-ghost btn-sm">&#127942; Leaderboard</a>
      </div>
    </div>

    <?= render_flash() ?>

    <div class="play-layout">

      <!-- ---------- the game ---------- -->
      <div>
        <div class="play-frame">
          <iframe src="<?= url('game-frame.php?game=' . urlencode($game['slug'])) ?>"
                  title="<?= e($game['title']) ?>"
                  allow="fullscreen"
                  loading="eager"></iframe>
        </div>

        <div class="card mt-3">
          <h3 style="font-size:1.1rem">About this game</h3>
          <p class="text-muted" style="margin:0;font-size:.94rem"><?= e($game['description']) ?></p>
        </div>
      </div>

      <!-- ---------- side rail ---------- -->
      <aside class="play-side">

        <div class="card side-box">
          <h3 style="font-size:1rem">Your stats</h3>
          <div class="kv"><span class="k">Personal best</span>
            <span class="v text-green"><?= $myBest ? num($myBest) : '&mdash;' ?></span></div>
          <div class="kv"><span class="k">Rounds played</span>
            <span class="v"><?= num($myRounds) ?></span></div>
          <div class="kv"><span class="k">Coin balance</span>
            <span class="v text-gold"><?= num($me['coins']) ?></span></div>
          <div class="kv"><span class="k">Level</span>
            <span class="v">Lv <?= (int) $me['level'] ?> &middot; <?= num($me['xp']) ?> XP</span></div>
          <div class="mt-2">
            <div class="bar"><i style="width:<?= max(3, ($me['xp'] % 250) / 250 * 100) ?>%"></i></div>
            <div class="text-dim mt-1"><?= 250 - ($me['xp'] % 250) ?> XP to level <?= (int) $me['level'] + 1 ?></div>
          </div>
        </div>

        <div class="card side-box card-flush">
          <div style="padding:20px 22px 12px">
            <h3 style="font-size:1rem;margin:0">Top 10 &mdash; <?= e($game['title']) ?></h3>
          </div>
          <?php if (!$board): ?>
            <div style="padding:0 22px 22px" class="text-muted">
              No scores yet. Play a round and the first spot is yours.
            </div>
          <?php else: ?>
            <div style="padding:0 12px 12px">
              <?php foreach ($board as $i => $b): ?>
                <div class="row between" style="gap:10px;padding:9px 10px;border-radius:10px;<?=
                  ($me && $me['username'] === $b['username']) ? 'background:rgba(164,217,76,.08)' : '' ?>">
                  <div class="u-cell">
                    <span class="rank r<?= $i + 1 ?>" style="width:26px;height:26px;font-size:.76rem"><?= $i + 1 ?></span>
                    <div>
                      <div class="u-name" style="font-size:.87rem"><?= e($b['username']) ?></div>
                      <div class="u-sub"><?= $b['difficulty'] ? e(ucfirst($b['difficulty'])) : '&mdash;' ?>
                        &middot; <?= ftime((int) $b['time_taken']) ?></div>
                    </div>
                  </div>
                  <strong style="font-size:.9rem"><?= num($b['score']) ?></strong>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="card side-box">
          <h3 style="font-size:1rem">Other games</h3>
          <?php
          $others = fetch_all('SELECT slug, title, icon, category FROM games
                               WHERE is_active = 1 AND id <> ? ORDER BY sort_order LIMIT 4',
                              [$game['id']]);
          foreach ($others as $o): ?>
            <a href="<?= url('play.php?game=' . urlencode($o['slug'])) ?>"
               class="row" style="gap:11px;align-items:center;padding:10px 0;border-top:1px solid var(--line-soft)">
              <span style="font-size:1.5rem"><?= $o['icon'] ?: '&#127918;' ?></span>
              <span>
                <span style="display:block;font-weight:700;color:var(--text);font-size:.9rem"><?= e($o['title']) ?></span>
                <span class="text-dim"><?= e($o['category']) ?></span>
              </span>
            </a>
          <?php endforeach; ?>
          <?php if (!$others): ?><p class="text-muted" style="margin:0">More coming soon.</p><?php endif; ?>
        </div>

      </aside>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
