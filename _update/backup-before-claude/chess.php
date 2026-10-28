<?php
/**
 * CHESS — lobby.
 *
 * Three ways in:
 *   * a practice game against the engine (three strengths),
 *   * a challenge with coins on it, shared by link,
 *   * anyone else's open challenge.
 */
require_once __DIR__ . '/config/config.php';

if (!chess_on()) {
    $page_title = 'Chess';
    require __DIR__ . '/includes/header.php';
    echo '<div class="container section"><div class="empty card"><div class="em-ico">&#9822;</div>'
       . '<h3>Chess is not available right now</h3>'
       . '<p>It is paused by the admin. There is plenty else to play.</p>'
       . '<a class="btn btn-primary" href="' . url('games.php') . '">Browse games</a></div></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$u   = current_user();
$uid = $u ? (int) $u['id'] : 0;

$mine = $open = $recent = [];
if ($uid) {
    $mine = fetch_all(
        'SELECT * FROM chess_matches
          WHERE mode = "pvp" AND status IN ("open","active")
            AND (host_id = ? OR guest_id = ?)
          ORDER BY created_at DESC', [$uid, $uid]);

    $recent = fetch_all(
        'SELECT * FROM chess_matches
          WHERE status = "finished" AND (host_id = ? OR guest_id = ?)
          ORDER BY ended_at DESC LIMIT 8', [$uid, $uid]);
}

$open = fetch_all(
    'SELECT m.*, u.username, u.avatar FROM chess_matches m
       JOIN users u ON u.id = m.host_id
      WHERE m.mode = "pvp" AND m.status = "open" AND m.host_id <> ?
      ORDER BY m.created_at DESC LIMIT 12', [$uid]);

$live = (int) fetch_col('SELECT COUNT(*) FROM chess_matches WHERE status = "active"', [], 0);

$page_title = 'Chess';
$page_desc  = 'Play chess against the computer, or put coins on the table and challenge '
            . 'anyone with a link. Winner takes the pot.';
require __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
  <div class="container">
    <div class="crumb"><a href="<?= url('index.php') ?>">Home</a> &rsaquo; Chess</div>
    <h1>Asviora <span class="gradient-text">Chess</span></h1>
    <p>Sharpen up against the engine, or stake coins and play someone for the pot.
       Full rules — castling, en passant, promotion — refereed on the server.</p>
  </div>
</section>

<section class="section">
  <div class="container">

    <?= render_flash() ?>

    <div class="grid g-2">

      <?php if (chess_ai_on()): ?>
      <div class="card reveal">
        <div class="game-body">
          <h3>&#129302; Play the computer</h3>
          <p class="text-muted">No stake, no clock pressure — a practice board that plays back.
             Beat it and you earn coins (up to three wins a day).</p>
          <div class="kv"><span class="k">Reward per win</span>
            <span class="v text-gold">+<?= num((int) setting('chess_ai_reward', '25')) ?>
              &times; level</span></div>
          <div class="row mt-3" style="gap:10px;flex-wrap:wrap">
            <a class="btn btn-ghost" href="<?= url('chess-play.php?ai=1') ?>">&#127793; Easy</a>
            <a class="btn btn-ghost" href="<?= url('chess-play.php?ai=2') ?>">&#9889; Medium</a>
            <a class="btn btn-ghost" href="<?= url('chess-play.php?ai=3') ?>">&#128293; Hard</a>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <?php if (chess_pvp_on()): ?>
      <div class="card reveal" id="challengeCard">
        <div class="game-body">
          <h3>&#127942; Challenge a player</h3>
          <p class="text-muted">Both players put in the same number of coins. Pick a clock,
             share the link, and the winner takes everything on the table.</p>

          <?php if (!$uid): ?>
            <p class="text-muted">You need an account to stake coins.</p>
            <a class="btn btn-primary" href="<?= url('login.php') ?>">Log in to play</a>
          <?php else: ?>
            <form id="challengeForm" class="mt-2"
                  data-endpoint="<?= url('api/chess.php') ?>"
                  data-csrf="<?= csrf_token() ?>">
              <div class="form-row">
                <div class="form-group">
                  <label class="form-label" for="stake">Your stake (coins)</label>
                  <input class="form-control" type="number" id="stake" name="stake"
                         min="<?= chess_min_stake() ?>" max="<?= min(chess_max_stake(), (int) $u['coins']) ?>"
                         value="<?= min(100, (int) $u['coins']) ?>">
                  <div class="form-hint">You have <?= num($u['coins']) ?> coins.
                    Limit <?= num(chess_min_stake()) ?>&ndash;<?= num(chess_max_stake()) ?>.</div>
                </div>
                <div class="form-group">
                  <label class="form-label" for="minutes">Time each</label>
                  <select class="form-select" id="minutes" name="minutes">
                    <?php foreach (chess_time_options() as $mn): ?>
                      <option value="<?= $mn ?>" <?= $mn === 10 ? 'selected' : '' ?>>
                        <?= $mn ?> minutes</option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <button class="btn btn-primary btn-block" type="submit">Create challenge &amp; get the link</button>
              <div id="challengeOut" class="mt-3" hidden>
                <label class="form-label">Send this link to your opponent</label>
                <div class="row" style="gap:8px">
                  <input class="form-control" id="inviteLink" readonly>
                  <button class="btn btn-ghost" type="button" id="copyLink">Copy</button>
                </div>
                <a class="btn btn-primary btn-block mt-2" id="goBoard" href="#">Open the board &rarr;</a>
              </div>
              <div id="challengeErr" class="alert alert-error mt-2" hidden></div>
            </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

    </div>

    <?php if ($mine): ?>
      <h2 class="mt-4">Your matches</h2>
      <div class="grid g-3">
        <?php foreach ($mine as $m): ?>
          <div class="card"><div class="game-body">
            <h3><?= $m['status'] === 'open' ? 'Waiting for an opponent' : 'In play' ?></h3>
            <div class="kv"><span class="k">Code</span><span class="v"><?= e($m['code']) ?></span></div>
            <div class="kv"><span class="k">Stake each</span>
              <span class="v text-gold"><?= num($m['stake']) ?></span></div>
            <div class="kv"><span class="k">Pot</span>
              <span class="v text-gold"><?= num($m['pot']) ?></span></div>
            <div class="kv"><span class="k">Clock</span><span class="v"><?= (int) $m['minutes'] ?> min</span></div>
            <a class="btn btn-primary btn-sm mt-2"
               href="<?= url('chess-play.php?m=' . e($m['code'])) ?>">
               <?= $m['status'] === 'open' ? 'Open / share' : 'Resume' ?> &rarr;</a>
          </div></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <h2 class="mt-4">Open challenges <span class="text-dim" style="font-size:.6em"><?= num($live) ?> live now</span></h2>
    <?php if (!$open): ?>
      <div class="card empty"><div class="em-ico">&#9823;</div>
        <h3>Nobody is waiting right now</h3>
        <p>Create a challenge above and send the link — it will show up here too.</p>
      </div>
    <?php else: ?>
      <div class="grid g-3">
        <?php foreach ($open as $m): ?>
          <div class="card"><div class="game-body">
            <h3><?= e($m['username']) ?></h3>
            <div class="kv"><span class="k">Stake each</span>
              <span class="v text-gold"><?= num($m['stake']) ?> coins</span></div>
            <div class="kv"><span class="k">Winner takes</span>
              <span class="v text-green"><?= num($m['stake'] * 2) ?> coins</span></div>
            <div class="kv"><span class="k">Clock</span><span class="v"><?= (int) $m['minutes'] ?> min each</span></div>
            <a class="btn btn-primary btn-sm mt-2"
               href="<?= url('chess-play.php?m=' . e($m['code'])) ?>">Accept &rarr;</a>
          </div></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($recent): ?>
      <h2 class="mt-4">Your last games</h2>
      <div class="table-scroll card">
        <table class="tbl" style="min-width:520px">
          <thead><tr><th>Code</th><th>Result</th><th>Pot</th><th>Ended</th></tr></thead>
          <tbody>
          <?php foreach ($recent as $m):
            $iWon = $m['winner_id'] && (int) $m['winner_id'] === $uid; ?>
            <tr>
              <td><?= e($m['code']) ?></td>
              <td>
                <?php if ($m['result'] === 'draw'): ?>
                  <span class="badge">Draw</span>
                <?php elseif ($iWon): ?>
                  <span class="badge badge-green">Won</span>
                <?php else: ?>
                  <span class="badge badge-red">Lost</span>
                <?php endif; ?>
                <span class="text-dim"><?= e($m['reason']) ?></span>
              </td>
              <td class="text-gold"><?= num($m['pot']) ?></td>
              <td class="text-muted"><?= fdate($m['ended_at'], 'd M, H:i') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  </div>
</section>

<?php
$extra_foot = <<<'HTML'
<script>
(function () {
  var f = document.getElementById('challengeForm');
  if (!f) return;
  var out = document.getElementById('challengeOut');
  var err = document.getElementById('challengeErr');

  f.addEventListener('submit', function (ev) {
    ev.preventDefault();
    err.hidden = true;
    var btn = f.querySelector('button[type=submit]');
    btn.disabled = true; btn.textContent = 'Creating...';

    fetch(f.dataset.endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'create',
        csrf: f.dataset.csrf,
        stake: document.getElementById('stake').value,
        minutes: document.getElementById('minutes').value
      })
    }).then(function (r) { return r.json(); }).then(function (d) {
      btn.disabled = false; btn.textContent = 'Create challenge & get the link';
      if (!d.ok) {
        err.textContent = d.message || 'Could not create the challenge.';
        err.hidden = false;
        return;
      }
      document.getElementById('inviteLink').value = d.link;
      document.getElementById('goBoard').href = d.link;
      out.hidden = false;
    }).catch(function () {
      btn.disabled = false; btn.textContent = 'Create challenge & get the link';
      err.textContent = 'Network problem. Try again.';
      err.hidden = false;
    });
  });

  document.getElementById('copyLink').addEventListener('click', function () {
    var i = document.getElementById('inviteLink');
    i.select();
    try { document.execCommand('copy'); } catch (e) {}
    if (navigator.clipboard) navigator.clipboard.writeText(i.value);
    this.textContent = 'Copied';
    var b = this;
    setTimeout(function () { b.textContent = 'Copy'; }, 1500);
  });
})();
</script>
HTML;
require __DIR__ . '/includes/footer.php';
