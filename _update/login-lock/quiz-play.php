<?php
/**
 * Quiz contest — the round itself.
 *
 * The page ships no answers. It asks api/quiz.php for one question at a
 * time and only learns which option was correct after it has submitted a
 * choice, so the answer key cannot be read out of the page source.
 */
require_once __DIR__ . '/config/config.php';

require_play_login('quiz.php');

if (!quiz_on()) {
    flash('The quiz contest is not available right now.', 'info');
    redirect('quiz.php');
}

$u        = current_user();
$uid      = (int) $u['id'];
$levelKey = get('level');
$levels   = quiz_levels();
if (!isset($levels[$levelKey])) {
    $levelKey = 'easy';
}
$level    = $levels[$levelKey];
$catId    = (int) get('cat');

// -------------------------------------------------------------------
// Which level of the ladder is being played
// -------------------------------------------------------------------
$useLevels = quiz_levels_ready();
$levelNo   = max(0, (int) get('n'));
$cfg       = quiz_level_cfg($catId, $levelKey);
$perRound  = $useLevels ? $cfg['questions_per_level'] : quiz_round_size();

if ($useLevels) {
    $totalLevels = quiz_level_total($catId, $levelKey);

    if ($totalLevels < 1) {
        flash('No questions are available for that level yet.', 'info');
        redirect('quiz.php');
    }
    if ($levelNo < 1) {
        $levelNo = quiz_next_level($uid, $catId, $levelKey);
    }
    if (!quiz_difficulty_unlocked($uid, $catId, $levelKey)) {
        flash(quiz_difficulty_lock_reason($uid, $catId, $levelKey), 'info');
        redirect('quiz.php' . ($catId ? '?cat=' . $catId : ''));
    }
    if (!quiz_level_unlocked($uid, $catId, $levelKey, $levelNo)) {
        flash('Level ' . $levelNo . ' is still locked — clear level ' . max(1, $levelNo - 1)
            . ' first.', 'info');
        redirect('quiz.php?level=' . e($levelKey) . ($catId ? '&cat=' . $catId : ''));
    }
}

// -------------------------------------------------------------------
// Only one live round per player, ever.
//
// This is the server-side half of "opening a second tab ends the quiz":
// starting a round kills any round still running, and the old tab finds
// out the moment it next talks to the API. Voided rather than abandoned,
// so the old tab can explain itself instead of failing silently.
// -------------------------------------------------------------------
q('UPDATE quiz_sessions
      SET status = "voided", void_reason = "duplicate", finished_at = NOW()
    WHERE user_id = ? AND status = "active"', [$uid]);

// -------------------------------------------------------------------
// Pick the questions
//
// With the level system on this is not a plain RAND(): the bank is cut
// into levels using the player's own shuffle key, so their level 4 is a
// different set from anyone else's, and anything they have already been
// shown inside the no-repeat window is filtered out and replaced.
// -------------------------------------------------------------------
$recycled = false;

if ($useLevels) {
    $draw     = quiz_level_questions($uid, $catId, $levelKey, $levelNo);
    $ids      = $draw['ids'];
    $recycled = $draw['recycled'];
} else {
    $sql    = 'SELECT id FROM quiz_questions WHERE is_active = 1 AND difficulty = ?';
    $params = [$levelKey];
    if ($catId) {
        $sql .= ' AND category_id = ?';
        $params[] = $catId;
    }
    $sql .= ' ORDER BY RAND() LIMIT ' . (int) $perRound;

    $ids = array_map('intval', array_column(fetch_all($sql, $params), 'id'));
}

if (!$ids) {
    flash('No questions are available for that level yet.', 'info');
    redirect('quiz.php');
}

// -------------------------------------------------------------------
// Coins are paid for the first N finished rounds each day
// -------------------------------------------------------------------
$dailyCap  = (int) setting('quiz_daily_rounds', '5');
$playedToday = (int) fetch_col(
    'SELECT COUNT(*) FROM quiz_sessions
      WHERE user_id = ? AND status = "finished" AND DATE(finished_at) = CURDATE()',
    [$uid], 0
);
$paid = ($dailyCap <= 0) || ($playedToday < $dailyCap);

$marksTotal = (int) fetch_col(
    'SELECT COALESCE(SUM(marks),0) FROM quiz_questions WHERE id IN ('
    . implode(',', array_map('intval', $ids)) . ')', [], count($ids));

$sid = insert_row('quiz_sessions', [
    'user_id'     => $uid,
    'category_id' => $catId ?: null,
    'difficulty'  => $levelKey,
    'level_no'    => $useLevels ? $levelNo : 0,
    'total'       => count($ids),
    'marks_total' => $marksTotal,
    'payload'     => json_encode([
        'ids'   => $ids,
        'res'   => [],
        'paid'  => $paid,
        'level' => $useLevels ? $levelNo : 0,
        'cat'   => $catId,
    ]),
    'status'      => 'active',
]);

$page_title = $useLevels
    ? ucfirst($levelKey) . ' — Level ' . $levelNo
    : ucfirst($levelKey) . ' Quiz';
$page_desc  = 'A timed quiz round on Aegis Esports.';
$extra_head = '<meta name="robots" content="noindex">';
require __DIR__ . '/includes/header.php';
?>

<section class="section">
  <div class="container">
    <div class="quiz-shell" id="quizShell"
         data-endpoint="<?= url('api/quiz.php') ?>"
         data-session="<?= (int) $sid ?>"
         data-csrf="<?= csrf_token() ?>"
         data-total="<?= count($ids) ?>"
         data-lobby="<?= url('quiz.php') ?>"
         data-fullscreen="<?= setting('quiz_require_fullscreen', '1') === '1' ? '1' : '0' ?>"
         data-strikes="<?= max(0, (int) setting('quiz_strike_limit', '1')) ?>"
         data-level="<?= $useLevels ? (int) $levelNo : 0 ?>"
         data-passmark="<?= (int) $cfg['pass_percent'] ?>"
         data-again="<?= url('quiz-play.php?level=' . e($levelKey)
                            . ($useLevels ? '&n=' . (int) $levelNo : '')
                            . ($catId ? '&cat=' . $catId : '')) ?>"
         data-next="<?= url('quiz-play.php?level=' . e($levelKey)
                            . ($useLevels ? '&n=' . ((int) $levelNo + 1) : '')
                            . ($catId ? '&cat=' . $catId : '')) ?>">

      <div class="quiz-bar">
        <div>
          <span class="badge badge-<?= $levelKey === 'hard' ? 'gold' : ($levelKey === 'mid' ? 'info' : 'green') ?>">
            <?= $level['icon'] ?> <?= e($level['label']) ?>
          </span>
          <?php if ($useLevels): ?>
            <span class="badge badge-info">Level <?= (int) $levelNo ?>
              <span class="text-dim">/ <?= (int) $totalLevels ?></span></span>
            <span class="badge badge-muted">pass <?= (int) $cfg['pass_percent'] ?>%</span>
          <?php endif; ?>
          <span class="qb-step" id="qbStep">Question 1 / <?= count($ids) ?></span>
        </div>

        <div class="row" style="align-items:center;gap:14px;margin:0">
          <span class="coin-pill nowrap">&#129689; <span id="qbCoins">0</span> earned</span>
          <div class="timer-ring" id="timerRing">
            <svg viewBox="0 0 62 62">
              <circle class="tr-track" cx="31" cy="31" r="27"></circle>
              <circle class="tr-bar" id="trBar" cx="31" cy="31" r="27"
                      stroke-dasharray="169.6" stroke-dashoffset="0"></circle>
            </svg>
            <div class="tr-num" id="trNum"><?= (int) $level['seconds'] ?></div>
          </div>
        </div>
      </div>

      <div class="quiz-progress" id="quizProgress">
        <?php for ($i = 0; $i < count($ids); $i++): ?><i></i><?php endfor; ?>
      </div>

      <?php if (!$paid): ?>
        <div class="alert alert-info" data-persist="1"><span>&#8505;</span>
          You have already claimed your <?= (int) $dailyCap ?> rewarded rounds today.
          This round still counts for your stats, but pays no coins.
        </div>
      <?php endif; ?>

      <?php if ($recycled): ?>
        <div class="alert alert-info" data-persist="1"><span>&#8505;</span>
          You have worked through most of this bank — a few questions you saw a while
          back are in this level so it could be filled.
        </div>
      <?php endif; ?>

      <!-- everything inside .noselect is copy-protected -->
      <div class="card q-card noselect" id="qCard">
        <div class="center" style="padding:40px 0">
          <div style="font-size:2.2rem">&#129504;</div>
          <p class="text-muted" style="margin:10px 0 0">Loading your first question&hellip;</p>
        </div>
      </div>

      <div class="row between mt-3">
        <a href="<?= url('quiz.php') ?>" class="btn btn-ghost btn-sm"
           data-confirm="Leave the round? Your progress will not be saved.">Quit round</a>
        <button class="btn btn-primary btn-sm" id="nextBtn" hidden>Next question &rarr;</button>
      </div>

      <!-- ---------- proctor overlay ----------
           Covers the questions whenever the round is not in a valid state:
           before the player accepts exam mode, after a warning, and once a
           round has been voided. -->
      <div class="proctor" id="proctor" hidden>
        <div class="proctor-box">
          <div class="p-ico" id="pIco">&#128274;</div>
          <h2 id="pTitle">Exam mode</h2>
          <p id="pText">
            This round runs in fullscreen. Leaving the window, switching tabs or
            opening developer tools will end it.
          </p>
          <ul class="p-rules" id="pRules">
            <li>Stay in this window until the round is over</li>
            <li>No copying, right-click or developer tools</li>
            <li>You get <strong><?= max(0, (int) setting('quiz_strike_limit', '1')) ?></strong>
                warning<?= ((int) setting('quiz_strike_limit', '1')) === 1 ? '' : 's' ?> &mdash;
                after that the round is cancelled</li>
          </ul>
          <button class="btn btn-primary btn-lg" id="pBtn">Start exam mode</button>
          <a class="btn btn-ghost btn-sm" id="pLeave" href="<?= url('quiz.php') ?>" hidden>Back to the lobby</a>
        </div>
      </div>

    </div>
  </div>
</section>

<style>
  .proctor {
    position: fixed; inset: 0; z-index: 900;
    display: grid; place-items: center; padding: 22px;
    background: rgba(6, 9, 16, .93); backdrop-filter: blur(9px);
  }
  .proctor[hidden] { display: none; }
  .proctor-box {
    width: min(520px, 100%); text-align: center;
    padding: 34px 28px;
    background: linear-gradient(160deg, var(--surface), var(--bg-2));
    border: 1px solid var(--line); border-radius: var(--radius-lg);
    box-shadow: 0 26px 70px rgba(0,0,0,.6);
  }
  .proctor-box .p-ico { font-size: 3rem; line-height: 1; margin-bottom: 10px; }
  .proctor-box h2 { font-size: 1.6rem; margin-bottom: 8px; }
  .proctor-box p  { color: var(--muted); font-size: .95rem; }
  .p-rules {
    list-style: none; margin: 18px 0 22px; padding: 0;
    text-align: left; font-size: .88rem; color: var(--muted);
  }
  .p-rules li { padding: 9px 0 9px 26px; position: relative; border-bottom: 1px solid var(--line-soft); }
  .p-rules li:last-child { border-bottom: 0; }
  .p-rules li::before {
    content: '\2022'; position: absolute; left: 8px; top: 7px;
    color: var(--cyan); font-size: 1.2rem; line-height: 1;
  }
  .proctor.is-warning .p-ico { color: var(--gold); }
  .proctor.is-void .p-ico    { color: var(--danger); }

  /* Text inside the round is not selectable at all, at the CSS level, so a
     long-press on mobile cannot raise the copy bubble in the first place. */
  .noselect, .noselect * {
    -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; user-select: none;
    -webkit-touch-callout: none;
  }

  /* level result stars */
  .qr-stars { font-size: 2rem; letter-spacing: 6px; margin-bottom: 6px; }
  .qr-stars span { color: var(--line); }
  .qr-stars span.on { color: var(--gold); text-shadow: 0 0 18px rgba(217,166,60,.5); }
</style>

<script>
(function () {
  'use strict';

  var shell = document.getElementById('quizShell');
  if (!shell) return;

  var ENDPOINT = shell.dataset.endpoint,
      SESSION  = shell.dataset.session,
      CSRF     = shell.dataset.csrf,
      TOTAL    = parseInt(shell.dataset.total, 10);

  var qCard   = document.getElementById('qCard'),
      qbStep  = document.getElementById('qbStep'),
      qbCoins = document.getElementById('qbCoins'),
      ring    = document.getElementById('timerRing'),
      trBar   = document.getElementById('trBar'),
      trNum   = document.getElementById('trNum'),
      nextBtn = document.getElementById('nextBtn'),
      pips    = document.getElementById('quizProgress').children;

  var CIRC = 2 * Math.PI * 27;
  trBar.setAttribute('stroke-dasharray', CIRC.toFixed(1));

  var timer = null, left = 0, span = 0, locked = false, earned = 0, current = 0;

  /* ================================================================
     COPY PROTECTION
     Question and answer text must not be copyable, right-clickable,
     draggable, printable or scrapable through the usual shortcuts.
     ================================================================ */
  function guard(ev) { ev.preventDefault(); return false; }

  ['contextmenu', 'copy', 'cut', 'paste', 'selectstart', 'dragstart']
    .forEach(function (type) {
      document.addEventListener(type, function (ev) {
        if (ev.target.closest && ev.target.closest('input, textarea')) return;
        return guard(ev);
      }, { capture: true });
    });

  document.addEventListener('keydown', function (ev) {
    var k = (ev.key || '').toLowerCase();

    // devtools + view-source + save + print + select-all + copy
    if (k === 'f12') { report('devtools', 'F12', true); return guard(ev); }
    if (ev.ctrlKey && ev.shiftKey && ['i', 'j', 'c', 'k'].indexOf(k) > -1) {
      report('devtools', 'Ctrl+Shift+' + k.toUpperCase(), true);
      return guard(ev);
    }
    if ((ev.ctrlKey || ev.metaKey) && ['u', 's', 'p', 'a', 'c', 'x'].indexOf(k) > -1) {
      if (ev.target.closest && ev.target.closest('input, textarea')) return;
      return guard(ev);
    }

    // keyboard answering: 1-4 or A-D
    if (!locked && current) {
      var n = 0;
      if (k >= '1' && k <= '4') n = parseInt(k, 10);
      if (k >= 'a' && k <= 'd') n = k.charCodeAt(0) - 96;
      if (n) {
        var btn = qCard.querySelector('.opt[data-choice="' + n + '"]');
        if (btn) btn.click();
      }
    }
  }, { capture: true });

  // clearing the selection defeats select-then-drag copying
  document.addEventListener('mouseup', function () {
    if (window.getSelection) {
      var s = window.getSelection();
      if (s.rangeCount && !document.activeElement.matches('input, textarea')) {
        var node = s.anchorNode;
        if (node && node.parentElement && node.parentElement.closest('.noselect')) {
          s.removeAllRanges();
        }
      }
    }
  });

  /* ---------------- transport ---------------- */
  function call(action, extra) {
    var body = { action: action, session: SESSION, csrf: CSRF };
    for (var k in extra) body[k] = extra[k];

    return fetch(ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json(); })
      .then(function (r) {
        // The server can void a round at any moment — including from another
        // tab — so every reply is checked, not just the violation ones.
        if (r && r.voided) voidRound(r.message, r.summary);
        return r;
      });
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  /* ---------------- countdown ---------------- */
  function stopTimer() { if (timer) { clearInterval(timer); timer = null; } }

  function startTimer(seconds) {
    stopTimer();
    span = seconds; left = seconds;
    paint();
    timer = setInterval(function () {
      left--;
      paint();
      if (left <= 0) { stopTimer(); submit(0); }
    }, 1000);
  }

  function paint() {
    trNum.textContent = Math.max(0, left);
    var frac = span > 0 ? (span - left) / span : 1;
    trBar.style.strokeDashoffset = (CIRC * frac).toFixed(1);
    ring.classList.toggle('warn',   left <= Math.ceil(span * 0.4) && left > Math.ceil(span * 0.2));
    ring.classList.toggle('danger', left <= Math.ceil(span * 0.2));
  }

  /* ---------------- render ---------------- */
  var KEYS = ['A', 'B', 'C', 'D'];

  function loadQuestion() {
    locked = false;
    nextBtn.hidden = true;

    call('question').then(function (r) {
      if (!r.ok) { fail(r.message); return; }
      if (r.retry) { loadQuestion(); return; }
      if (r.done)  { finish(); return; }

      current = r.index;
      qbStep.textContent = 'Question ' + r.index + ' / ' + r.total;

      for (var i = 0; i < pips.length; i++) pips[i].classList.remove('now');
      if (pips[r.index - 1]) pips[r.index - 1].classList.add('now');

      var html = '';
      if (r.image) html += '<img class="q-image" src="' + esc(r.image) + '" alt="">';
      html += '<div class="q-text">' + esc(r.question) + '</div><div class="opt-list">';
      r.options.forEach(function (opt, i) {
        html += '<button type="button" class="opt" data-choice="' + (i + 1) + '">' +
                  '<span class="opt-key">' + KEYS[i] + '</span>' +
                  '<span class="opt-txt">' + esc(opt) + '</span>' +
                '</button>';
      });
      html += '</div>';
      qCard.innerHTML = html;

      Array.prototype.forEach.call(qCard.querySelectorAll('.opt'), function (b) {
        b.addEventListener('click', function () {
          if (locked) return;
          submit(parseInt(b.dataset.choice, 10));
        });
      });

      startTimer(r.seconds);
    }).catch(function () { fail('Connection lost. Check your network.'); });
  }

  function submit(choice) {
    if (locked) return;
    locked = true;
    stopTimer();

    Array.prototype.forEach.call(qCard.querySelectorAll('.opt'), function (b) {
      b.disabled = true;
    });

    call('answer', { choice: choice }).then(function (r) {
      if (!r.ok) { fail(r.message); return; }

      var chosen = qCard.querySelector('.opt[data-choice="' + choice + '"]');
      var right  = qCard.querySelector('.opt[data-choice="' + r.answer + '"]');
      if (right) right.classList.add('is-right');
      if (chosen && !r.correct) chosen.classList.add('is-wrong');

      var pip = pips[current - 1];
      if (pip) { pip.classList.remove('now'); pip.classList.add(r.correct ? 'ok' : 'bad'); }

      if (r.coins) {
        earned += r.coins;
        qbCoins.textContent = earned;
        if (window.asvioraToast) window.asvioraToast('+' + r.coins + ' coins', 'success');
      } else if (r.expired) {
        if (window.asvioraToast) window.asvioraToast('Time up on that one', 'error');
      }

      if (r.explanation) {
        var box = document.createElement('div');
        box.className = 'q-explain';
        box.innerHTML = '<b>' + (r.correct ? 'Correct.' : 'Answer:') + '</b> ' + esc(r.explanation);
        qCard.appendChild(box);
      }

      nextBtn.hidden = false;
      nextBtn.textContent = r.done ? 'See my result →' : 'Next question →';
      nextBtn.onclick = function () { r.done ? finish() : loadQuestion(); };

    }).catch(function () { fail('Connection lost while sending your answer.'); });
  }

  /* ---------------- result ---------------- */
  function finish() {
    stopTimer();
    nextBtn.hidden = true;

    call('finish').then(function (r) {
      if (!r.ok) { fail(r.message); return; }
      var s = r.summary;

      var levelled = typeof s.level === 'number' && s.level > 0;

      var verdict;
      if (levelled) {
        verdict = s.level_passed
          ? (s.percent === 100 ? 'Level ' + s.level + ' cleared — perfectly.'
                               : 'Level ' + s.level + ' cleared.')
          : 'Level ' + s.level + ' not cleared. You need ' + s.pass_mark + '% to move on.';
      } else {
        verdict = s.percent === 100 ? 'Flawless round.'
                : s.percent >= 70  ? 'Strong round.'
                : s.percent >= 40  ? 'Solid, but there is room.'
                : 'Rough one. Run it back.';
      }

      // stars, when the level system is running
      var stars = '';
      if (levelled) {
        stars = '<div class="qr-stars">';
        for (var i = 1; i <= 3; i++) {
          stars += '<span class="' + (i <= (s.stars || 0) ? 'on' : '') + '">&#9733;</span>';
        }
        stars += '</div>';
      }

      // what to offer next: the next level if it just opened, otherwise a retry
      var primary = levelled && s.next_unlocked && s.next_level
        ? '<a class="btn btn-primary" href="' + shell.dataset.next + '">' +
            'Next level &rarr;</a>' +
          '<a class="btn btn-ghost" href="' + shell.dataset.again + '">Replay level ' + s.level + '</a>'
        : '<a class="btn btn-primary" href="' + shell.dataset.again + '">' +
            (levelled ? 'Try level ' + s.level + ' again' : 'Play again') + '</a>';

      var html =
        '<div class="quiz-result">' +
          stars +
          '<div class="qr-score">' + s.correct + '/' + s.total + '</div>' +
          '<p class="qr-sub">' + verdict + ' You scored ' + s.percent + '%.</p>' +
          '<div class="qr-stats">' +
            '<div class="qs"><b class="text-green">' + s.correct + '</b><span>Correct</span></div>' +
            '<div class="qs"><b style="color:var(--danger)">' + s.wrong + '</b><span>Missed</span></div>' +
            '<div class="qs"><b class="text-gold">' + s.coins + '</b><span>Coins</span></div>' +
            '<div class="qs"><b>' + s.xp + '</b><span>XP</span></div>' +
          '</div>' +
          (s.first_clear && s.bonus
            ? '<div class="alert alert-success" data-persist="1"><span>&#127881;</span>' +
              'Level cleared for the first time: +' + s.bonus + ' coins</div>'
            : (s.bonus ? '<div class="alert alert-success" data-persist="1"><span>&#127881;</span>' +
                         'Bonus: +' + s.bonus + ' coins</div>' : '')) +
          (s.ladder_done
            ? '<div class="alert alert-success" data-persist="1"><span>&#127942;</span>' +
              'That was the last level at this difficulty — the next one is open.</div>' : '') +
          (s.paid === false ? '<p class="text-dim">Daily reward limit reached &mdash; ' +
                     'this round was scored but not paid.</p>' : '') +
          '<div class="row" style="justify-content:center;margin-top:8px">' +
            primary +
            '<a class="btn btn-ghost" href="' + shell.dataset.lobby + '">Back to quiz lobby</a>' +
          '</div>' +
        '</div>';

      qCard.classList.remove('noselect');
      qCard.innerHTML = html;
      qCard.scrollIntoView({ behavior: 'smooth', block: 'center' });

    }).catch(function () { fail('Could not save your result.'); });
  }

  function fail(msg) {
    stopTimer();
    qCard.classList.remove('noselect');
    qCard.innerHTML =
      '<div class="empty"><div class="em-ico">&#9888;</div>' +
      '<h3>Round interrupted</h3><p>' + esc(msg || 'Something went wrong.') + '</p>' +
      '<a class="btn btn-primary" href="' + shell.dataset.lobby + '">Back to the lobby</a></div>';
  }

  /* ================================================================
     PROCTOR

     What this layer can and cannot do, stated plainly: none of the
     blocking below is a security boundary. A determined player can
     disable JavaScript and every guard here goes with it.

     What makes it bite is that each detection is *reported to the
     server*, which keeps the strike count and flips the round to
     'voided'. From that moment api/quiz.php refuses to serve another
     question or pay another coin, whatever the browser does next. The
     code here is the sensor; the server is the enforcement.
     ================================================================ */
  var proctor  = document.getElementById('proctor'),
      pIco     = document.getElementById('pIco'),
      pTitle   = document.getElementById('pTitle'),
      pText    = document.getElementById('pText'),
      pRules   = document.getElementById('pRules'),
      pBtn     = document.getElementById('pBtn'),
      pLeave   = document.getElementById('pLeave');

  var REQUIRE_FS = shell.dataset.fullscreen === '1',
      started    = false,      // exam mode accepted
      dead       = false,      // round voided — nothing more to do
      paused     = false,      // warning overlay is up
      reporting  = false,      // one report in flight at a time
      lastReport = 0;

  function showOverlay(opts) {
    proctor.classList.toggle('is-warning', opts.tone === 'warning');
    proctor.classList.toggle('is-void',    opts.tone === 'void');
    pIco.innerHTML   = opts.icon;
    pTitle.textContent = opts.title;
    pText.textContent  = opts.text;
    pRules.hidden    = !opts.rules;
    pBtn.hidden      = !opts.button;
    pBtn.textContent = opts.button || '';
    pLeave.hidden    = !opts.leave;
    proctor.hidden   = false;
  }

  function hideOverlay() { proctor.hidden = true; }

  /* ---------------- fullscreen ---------------- */
  function fsElement() {
    return document.fullscreenElement || document.webkitFullscreenElement || null;
  }
  function enterFullscreen() {
    var el = document.documentElement;
    var fn = el.requestFullscreen || el.webkitRequestFullscreen;
    if (fn) { try { fn.call(el); } catch (e) { /* refused — the report still fires */ } }
  }
  function exitFullscreen() {
    var fn = document.exitFullscreen || document.webkitExitFullscreen;
    if (fn && fsElement()) { try { fn.call(document); } catch (e) {} }
  }

  /* ---------------- reporting ---------------- */
  /**
   * Tell the server what happened. `soft` reports are logged only; the rest
   * cost a strike and, once the allowance runs out, the round.
   */
  function report(kind, detail, soft) {
    if (dead || !started) return;

    // one detection often trips two listeners (blur *and* visibilitychange);
    // collapse anything within a second into a single strike
    var now = Date.now();
    if (!soft && (reporting || now - lastReport < 1000)) return;
    if (!soft) { reporting = true; lastReport = now; }

    call('violation', { kind: kind, detail: detail || '', soft: soft ? 1 : 0 })
      .then(function (r) {
        reporting = false;
        if (!r || !r.ok) return;
        if (r.logged) return;                       // soft report, nothing to show
        if (r.voided) { voidRound(r.message, r.summary); return; }
        warn(r.message);
      })
      .catch(function () { reporting = false; });
  }

  /** A strike was spent but the round survives. */
  function warn(msg) {
    if (dead) return;
    paused = true;
    showOverlay({
      tone: 'warning',
      icon: '&#9888;',
      title: 'Warning',
      text: (msg || 'You left the quiz window.') +
            ' The timer kept running. One more and the round is cancelled.',
      button: 'Return to the round'
    });
  }

  /** The round is over and the server will not serve it again. */
  function voidRound(msg, summary) {
    if (dead) return;
    dead = true;
    paused = false;
    stopTimer();
    locked = true;
    nextBtn.hidden = true;
    exitFullscreen();

    var scored = summary
      ? ' You answered ' + summary.correct + ' of ' + summary.total + ' correctly before it ended.'
      : '';

    showOverlay({
      tone: 'void',
      icon: '&#128683;',
      title: 'Round cancelled',
      text: (msg || 'The exam rules were broken.') + scored +
            ' Coins already earned are yours, but this round will not be scored.',
      leave: true
    });

    qCard.classList.remove('noselect');
    qCard.innerHTML =
      '<div class="empty"><div class="em-ico">&#128683;</div>' +
      '<h3>Round cancelled</h3><p class="text-muted">' + esc(msg || '') + '</p></div>';
  }

  pBtn.addEventListener('click', function () {
    if (dead) return;

    if (!started) {
      started = true;
      hideOverlay();
      if (REQUIRE_FS) enterFullscreen();
      loadQuestion();
      return;
    }

    // resuming after a warning
    paused = false;
    hideOverlay();
    if (REQUIRE_FS) enterFullscreen();
  });

  /* ---------------- detections ---------------- */

  // tab switch / minimise / app switch on mobile
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) report('hidden', 'document.hidden');
  });

  // another window took focus — covers a second monitor and most devtools
  window.addEventListener('blur', function () {
    if (!paused) report('blur', 'window blur');
  });

  // left fullscreen exam mode
  document.addEventListener('fullscreenchange', function () {
    if (REQUIRE_FS && started && !dead && !fsElement()) report('fullscreen', 'left fullscreen');
  });
  document.addEventListener('webkitfullscreenchange', function () {
    if (REQUIRE_FS && started && !dead && !fsElement()) report('fullscreen', 'left fullscreen');
  });

  // Docked devtools steal room from the viewport without changing the window.
  // A large, sustained gap between outer and inner size is the tell. It is a
  // heuristic — undocked devtools on a second monitor will not show up here,
  // which is exactly why the blur listener above matters too.
  (function watchDevtools() {
    var TRIP = 170, hits = 0;
    setInterval(function () {
      if (dead || !started || !document.hasFocus()) return;
      var gapW = window.outerWidth  - window.innerWidth,
          gapH = window.outerHeight - window.innerHeight;
      if (gapW > TRIP || gapH > TRIP) {
        if (++hits >= 2) { hits = 0; report('devtools', 'viewport gap ' + gapW + 'x' + gapH); }
      } else {
        hits = 0;
      }
    }, 1200);
  })();

  // copy / cut / context menu are already blocked above; log the attempts so
  // a pattern of them shows up in the admin trail, without costing a strike
  ['copy', 'cut', 'contextmenu'].forEach(function (type) {
    document.addEventListener(type, function () { report('copy', type, true); }, { capture: true });
  });

  // if the tab is being torn down mid-round, get the report out with a beacon
  window.addEventListener('pagehide', function () {
    if (!started || dead || locked) return;
    if (!navigator.sendBeacon) return;
    navigator.sendBeacon(ENDPOINT, new Blob(
      [JSON.stringify({ action: 'violation', kind: 'hidden',
                        detail: 'pagehide', session: SESSION, csrf: CSRF })],
      { type: 'application/json' }
    ));
  });

  /* ---------------- go ---------------- */
  showOverlay({
    tone: '',
    icon: '&#128274;',
    title: 'Exam mode',
    text: REQUIRE_FS
      ? 'This round runs in fullscreen and is monitored.'
      : 'This round is monitored.',
    rules: true,
    button: REQUIRE_FS ? 'Start in fullscreen' : 'Start the round'
  });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
