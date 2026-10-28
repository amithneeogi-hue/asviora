<?php
/**
 * CHESS — the board.
 *
 *   chess-play.php?ai=1|2|3   practice game against the engine (all local)
 *   chess-play.php?m=CODE     staked match; every move goes through api/chess.php
 */
require_once __DIR__ . '/config/config.php';

if (!chess_on()) {
    flash('Chess is switched off right now.', 'info');
    redirect('games.php');
}

$aiLevel = (int) get('ai');
$code    = strtoupper(trim(get('m')));
$isAi    = $aiLevel > 0 && $code === '';

if ($isAi) {
    if (!chess_ai_on()) {
        flash('Practice games are switched off.', 'info');
        redirect('chess.php');
    }
    $aiLevel = min(3, max(1, $aiLevel));
    $match   = null;
} else {
    require_login('chess.php');
    $match = $code !== '' ? chess_match($code) : null;
    if (!$match) {
        flash('That match does not exist.', 'error');
        redirect('chess.php');
    }
}

$u   = current_user();
$uid = $u ? (int) $u['id'] : 0;

// ---- who is who, in a staked match ----
$mySide = 'w';
$role   = 'player';
if ($match) {
    if ((int) $match['white_id'] === $uid) {
        $mySide = 'w';
    } elseif ((int) $match['black_id'] === $uid) {
        $mySide = 'b';
    } elseif ((int) $match['host_id'] === $uid) {
        $mySide = 'w';                       // colours are drawn when the guest joins
    } else {
        $role = ($match['status'] === 'open') ? 'joiner' : 'watcher';
    }
}

$aiNames    = [1 => 'Easy', 2 => 'Medium', 3 => 'Hard'];
$page_title = $isAi ? ('Chess vs Computer — ' . $aiNames[$aiLevel]) : ('Chess — ' . $code);
$page_desc  = 'A chess board on Asviora Games.';
$extra_head = '<link rel="stylesheet" href="' . asset('assets/css/chess.css') . '">'
            . '<meta name="robots" content="noindex">';
require __DIR__ . '/includes/header.php';
?>

<section class="section">
  <div class="container">

    <div class="chess-shell"
         id="chessShell"
         data-endpoint="<?= url('api/chess.php') ?>"
         data-csrf="<?= csrf_token() ?>"
         data-mode="<?= $isAi ? 'ai' : 'pvp' ?>"
         data-ai="<?= (int) $aiLevel ?>"
         data-code="<?= e($match['code'] ?? '') ?>"
         data-side="<?= e($mySide) ?>"
         data-role="<?= e($role) ?>"
         data-status="<?= e($match['status'] ?? 'ai') ?>"
         data-fen="<?= e($match['fen'] ?? CHESS_START_FEN) ?>"
         data-lobby="<?= url('chess.php') ?>">

      <div class="chess-main">
        <div class="chess-player" id="topPlayer">
          <div class="cp-who"><span class="cp-name" id="topName">Opponent</span></div>
          <div class="cp-clock" id="topClock"><?= $isAi ? '&infin;' : '--:--' ?></div>
        </div>

        <div class="cb-wrap">
          <div id="board"></div>
        </div>

        <div class="chess-player" id="botPlayer">
          <div class="cp-who"><span class="cp-name" id="botName">You</span></div>
          <div class="cp-clock" id="botClock"><?= $isAi ? '&infin;' : '--:--' ?></div>
        </div>
      </div>

      <aside class="chess-side">
        <div class="card">
          <div class="game-body">
            <?php if ($isAi): ?>
              <h3>&#129302; Computer &mdash; <?= e($aiNames[$aiLevel]) ?></h3>
              <p class="text-muted">You are white. Beat the engine and coins land in your wallet
                 (three wins a day count).</p>
            <?php else: ?>
              <h3>Match <?= e($match['code']) ?></h3>
              <div class="kv"><span class="k">Stake each</span>
                <span class="v text-gold"><?= num($match['stake']) ?></span></div>
              <div class="kv"><span class="k">Winner takes</span>
                <span class="v text-green" id="potView"><?= num($match['pot'] ?: $match['stake'] * 2) ?></span></div>
              <div class="kv"><span class="k">Clock</span>
                <span class="v"><?= (int) $match['minutes'] ?> min each</span></div>
            <?php endif; ?>

            <div class="chess-state" id="stateBox">Loading&hellip;</div>

            <?php if (!$isAi && $match['status'] === 'open'): ?>
              <?php if ((int) $match['host_id'] === $uid): ?>
                <label class="form-label mt-2">Share this link</label>
                <div class="row" style="gap:8px">
                  <input class="form-control" id="inviteLink" readonly
                         value="<?= e(url('chess-play.php?m=' . $match['code'])) ?>">
                  <button class="btn btn-ghost" type="button" id="copyLink">Copy</button>
                </div>
                <button class="btn btn-danger btn-block mt-2" id="btnCancel">Cancel &amp; refund</button>
              <?php else: ?>
                <p class="text-muted mt-2">
                  <?= num($match['stake']) ?> coins to accept. You have <?= num($u['coins']) ?>.</p>
                <button class="btn btn-primary btn-block" id="btnJoin">Accept the challenge</button>
              <?php endif; ?>
            <?php endif; ?>

            <div class="chess-actions mt-2" id="liveActions" hidden>
              <button class="btn btn-ghost btn-sm" id="btnDraw">Offer draw</button>
              <button class="btn btn-danger btn-sm" id="btnResign"
                      data-confirm="Resign this match? Your opponent takes the pot.">Resign</button>
            </div>

            <?php if ($isAi): ?>
              <div class="chess-actions mt-2">
                <button class="btn btn-ghost btn-sm" id="btnUndo">Take back</button>
                <a class="btn btn-ghost btn-sm" href="<?= url('chess-play.php?ai=' . $aiLevel) ?>">New game</a>
              </div>
            <?php endif; ?>

            <a class="btn btn-ghost btn-sm btn-block mt-2" href="<?= url('chess.php') ?>">Back to the lobby</a>
          </div>
        </div>

        <div class="card mt-3">
          <div class="game-body">
            <h3 style="margin-bottom:8px">Moves</h3>
            <ol class="chess-moves" id="moveList"></ol>
          </div>
        </div>
      </aside>
    </div>

  </div>
</section>

<?php
$extra_foot = '<script src="' . asset('assets/js/chess.js') . '"></script>'
            . '<script src="' . asset('assets/js/chess-play.js') . '"></script>';
require __DIR__ . '/includes/footer.php';
