<?php
/**
 * Daily spin wheel — one free spin per player per day.
 */
require_once __DIR__ . '/config/config.php';

$u        = current_user();
$enabled  = spin_on();
$segments = spin_segments();
$todayRow = $u ? spin_today((int) $u['id']) : null;
$access   = $u ? spin_status((int) $u['id']) : null;
$canSpin  = $access ? $access['allowed'] : false;

$recent = $myHistory = [];
$totalWon = 0;

if ($enabled) {
    // recent winners, for a bit of social proof
    $recent = fetch_all(
        'SELECT s.coins, s.created_at, u.username, u.avatar, u.name
           FROM spin_results s JOIN users u ON u.id = s.user_id
          ORDER BY s.id DESC LIMIT 8'
    );

    if ($u) {
        $myHistory = fetch_all(
            'SELECT spin_date, coins FROM spin_results
              WHERE user_id = ? ORDER BY spin_date DESC LIMIT 7',
            [(int) $u['id']]
        );
        $totalWon = (int) fetch_col(
            'SELECT COALESCE(SUM(coins),0) FROM spin_results WHERE user_id = ?',
            [(int) $u['id']], 0
        );
    }
}

$page_title = 'Daily Spin';
$page_desc  = 'One free spin every day. Up to 1,000 coins, no entry fee, no catch.';
require __DIR__ . '/includes/header.php';

$n = count($segments);
?>

<section class="page-hero">
  <div class="container">
    <div class="crumb"><a href="<?= url('index.php') ?>">Home</a> &rsaquo; Daily Spin</div>
    <h1>Daily <span class="gradient-text">Spin</span></h1>
    <p>One free spin every day. The wheel resets at midnight &mdash; miss a day and it is gone.</p>
  </div>
</section>

<section class="section">
  <div class="container">
    <?= render_flash() ?>

    <?php if (!$enabled): ?>
      <div class="card empty">
        <div class="em-ico">&#127920;</div>
        <h3>The wheel is resting</h3>
        <p>The daily spin is switched off or not installed yet. Check back soon.</p>
        <a class="btn btn-primary" href="<?= url('games.php') ?>">Play a game instead</a>
      </div>

    <?php elseif (!$segments): ?>
      <div class="card empty">
        <div class="em-ico">&#9881;</div>
        <h3>Wheel not configured</h3>
        <p>An administrator still needs to set the prize segments.</p>
      </div>

    <?php else: ?>
      <div class="spin-wrap">

        <div class="spin-stage" id="spinStage"
             data-endpoint="<?= url('api/spin.php') ?>"
             data-csrf="<?= csrf_token() ?>"
             <?php /* NOT data-count — main.js claims every [data-count] as an
                      animated stat counter and overwrites its textContent,
                      which would wipe the whole wheel. */ ?>
             data-seg-count="<?= $n ?>"
             data-spun="<?= ($u && !$canSpin) ? 1 : 0 ?>"
             data-segments='<?= e(json_encode(array_map(static function (array $s): array {
                 return ['coins' => (int) $s['coins'], 'color' => (string) $s['color']];
             }, $segments), JSON_UNESCAPED_SLASHES)) ?>'>

          <?php
          // The wheel is painted on a <canvas> by the script at the bottom of
          // this page, from the data-segments JSON above. Segment i occupies
          // the slice centred on angle (i * 360/n) measured clockwise from the
          // top, so the pointer at 12 o'clock lands on segment i after the
          // wheel rotates by -i * 360/n.
          ?>
          <div class="wheel-holder">
            <div class="wheel-pointer"></div>
            <canvas class="wheel" id="wheel" width="760" height="760"
                    role="img" aria-label="Prize wheel"></canvas>
            <div class="wheel-hub"><div class="logo-crest"></div></div>
          </div>

          <div class="center mt-3" style="position:relative;z-index:5">
            <?php if (!$u): ?>
              <a class="btn btn-primary btn-lg" href="<?= url('login.php') ?>">Log in to spin</a>
              <p class="text-dim mt-2" style="margin-bottom:0">Free account, free spin, every day.</p>

            <?php elseif (!$canSpin): ?>
              <button class="btn btn-primary btn-lg is-disabled" id="spinBtn" disabled>
                <?= $access['used'] > 0 ? 'No spins left today' : 'Spin unavailable' ?>
              </button>
              <p class="text-muted mt-2" style="margin-bottom:0">
                <?= e($access['reason']) ?>
                <?php if ($todayRow): ?>
                  You won <strong class="text-gold"><?= num($todayRow['coins']) ?> coins</strong>
                  on your last spin.
                <?php endif; ?>
                <?php if ($access['used'] > 0 || !empty($access['wait'])): ?>
                  Next spin in <span class="countdown-next" id="nextIn"
                    data-seconds="<?= !empty($access['wait'])
                                       ? (int) $access['wait'] : seconds_to_midnight() ?>">&hellip;</span>
                <?php endif; ?>
              </p>

            <?php else: ?>
              <button class="btn btn-primary btn-lg" id="spinBtn">&#127920; Spin the wheel</button>
              <p class="text-dim mt-2" style="margin-bottom:0">
                Free &mdash; costs you nothing.
                <?php if ($access['remaining'] > 1): ?>
                  You have <strong class="text-gold"><?= (int) $access['remaining'] ?></strong>
                  spins left today.
                <?php endif; ?>
                <?php if (!empty($access['cooldown'])): ?>
                  <br><span class="text-dim">A <?= (int) $access['cooldown'] ?>-minute wait
                  applies between spins.</span>
                <?php endif; ?>
                <?php if (!empty($access['weekly_cap'])): ?>
                  <br><span class="text-dim">Weekly limit:
                  <?= (int) $access['weekly_used'] ?>/<?= (int) $access['weekly_cap'] ?> used.</span>
                <?php endif; ?>
                <?php if (!empty($access['monthly_cap'])): ?>
                  <br><span class="text-dim">Monthly limit:
                  <?= (int) $access['monthly_used'] ?>/<?= (int) $access['monthly_cap'] ?> used.</span>
                <?php endif; ?>
              </p>
            <?php endif; ?>

            <?php if ($u && setting('spin_limit_note') !== ''): ?>
              <p class="form-hint mt-2" style="margin-bottom:0">
                <?= e(setting('spin_limit_note')) ?></p>
            <?php endif; ?>
          </div>

          <div class="spin-win" id="spinWin">
            <div>
              <div style="font-size:2.4rem">&#127881;</div>
              <div class="sw-amt" id="swAmt">0</div>
              <p class="text-muted">coins added to your wallet</p>
              <button class="btn btn-ghost btn-sm" id="swClose">Nice</button>
            </div>
          </div>
        </div>

        <div>
          <div class="card mb-3">
            <h3 style="font-size:1.05rem">Prize table</h3>
            <ul class="spin-prizes">
              <?php foreach ($segments as $s): ?>
                <li>
                  <span class="sp-dot" style="background:<?= e($s['color']) ?>"></span>
                  <span class="text-muted"><?= num($s['coins']) ?> coins</span>
                  <span class="sp-amt"><?= round($s['weight'] / max(1, array_sum(array_column($segments, 'weight'))) * 100) ?>%</span>
                </li>
              <?php endforeach; ?>
            </ul>
            <p class="form-hint" style="margin-top:14px">
              Odds are shown as a percentage chance per spin.
            </p>
          </div>

          <?php if ($u): ?>
            <div class="card mb-3">
              <h3 style="font-size:1.05rem">Your spins</h3>
              <div class="kv"><span class="k">Total won</span>
                <span class="v text-gold"><?= num($totalWon) ?> coins</span></div>
              <?php foreach ($myHistory as $h): ?>
                <div class="kv">
                  <span class="k"><?= fdate($h['spin_date']) ?></span>
                  <span class="v">+<?= num($h['coins']) ?></span>
                </div>
              <?php endforeach; ?>
              <?php if (!$myHistory): ?>
                <p class="text-dim" style="margin:10px 0 0">No spins yet. The first one is waiting.</p>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <?php if ($recent): ?>
            <div class="card">
              <h3 style="font-size:1.05rem">Just won</h3>
              <?php foreach ($recent as $r): ?>
                <div class="u-cell" style="padding:8px 0">
                  <img src="<?= e(avatar_url($r)) ?>" alt="">
                  <div style="min-width:0">
                    <div class="u-name"><?= e($r['username']) ?></div>
                    <div class="u-sub"><?= fdate($r['created_at'], 'd M') ?></div>
                  </div>
                  <span class="sp-amt" style="margin-left:auto">+<?= num($r['coins']) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="section-sm">
  <div class="container">
    <div class="cta-band">
      <h2>Turn those coins into a score</h2>
      <p class="text-muted">Take your spin winnings into the quiz contest or the games.</p>
      <div class="row" style="justify-content:center;margin-top:18px">
        <a class="btn btn-primary" href="<?= url('quiz.php') ?>">&#129504; Quiz contest</a>
        <a class="btn btn-ghost" href="<?= url('games.php') ?>">&#127918; All games</a>
      </div>
    </div>
  </div>
</section>

<style>
/* Geometry the wheel cannot render without — kept next to the markup so a
   change in style.css can never collapse it again. */
.spin-stage .wheel-holder {
  position: relative;
  width: min(400px, 82vw);
  height: min(400px, 82vw);   /* explicit, not aspect-ratio */
  margin-inline: auto;
  flex: none;
}
.spin-stage canvas.wheel {
  display: block;
  width: 100%;
  height: 100%;               /* beats the global `svg,img { height:auto }` */
  max-width: none;
  border-radius: 50%;
  background: #0b0f1c;
  box-shadow: 0 20px 44px rgba(0,0,0,.7), 0 0 0 3px rgba(143,206,63,.45);
  transition: transform 5.4s cubic-bezier(.16,.84,.24,1);
}
</style>

<script>
(function () {
  'use strict';

  var stage = document.getElementById('spinStage');
  if (!stage) return;

  var wheel = document.getElementById('wheel'),
      btn   = document.getElementById('spinBtn'),
      win   = document.getElementById('spinWin'),
      swAmt = document.getElementById('swAmt'),
      swX   = document.getElementById('swClose'),
      N     = parseInt(stage.dataset.segCount, 10) || 1,
      STEP  = 360 / N,
      busy  = false,
      turns = 0;

  /* ---- paint the wheel ---------------------------------------------- */
  var segs = [];
  try { segs = JSON.parse(stage.dataset.segments || '[]'); } catch (err) { segs = []; }

  function drawWheel() {
    if (!wheel || !wheel.getContext || !segs.length) return;

    // Match the backing store to the on-screen size so it stays crisp on
    // high-DPI screens and on any container width.
    var css = wheel.getBoundingClientRect(),
        dpr = window.devicePixelRatio || 1,
        size = Math.max(120, Math.round(Math.min(css.width, css.height)));

    wheel.width = wheel.height = Math.round(size * dpr);

    var ctx = wheel.getContext('2d'),
        R   = size / 2;

    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, size, size);
    ctx.translate(R, R);

    var step = (Math.PI * 2) / segs.length;

    segs.forEach(function (s, i) {
      // -90deg puts segment 0 at the top, under the pointer
      var mid = i * step - Math.PI / 2,
          a0  = mid - step / 2,
          a1  = mid + step / 2;

      ctx.beginPath();
      ctx.moveTo(0, 0);
      ctx.arc(0, 0, R - 4, a0, a1);
      ctx.closePath();
      ctx.fillStyle = s.color || '#2e3239';
      ctx.fill();
      ctx.lineWidth = 1.5;
      ctx.strokeStyle = 'rgba(8,10,18,.65)';
      ctx.stroke();

      // label, two-thirds of the way out, reading along the slice
      ctx.save();
      ctx.rotate(mid);
      ctx.translate(R * 0.64, 0);
      ctx.rotate(Math.PI / 2);
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.font = '800 ' + Math.max(12, Math.round(R * 0.115)) + 'px system-ui, sans-serif';
      ctx.lineWidth = Math.max(2, R * 0.022);
      ctx.strokeStyle = 'rgba(0,0,0,.45)';
      ctx.strokeText(String(s.coins), 0, 0);
      ctx.fillStyle = '#ffffff';
      ctx.fillText(String(s.coins), 0, 0);
      ctx.restore();
    });

    ctx.setTransform(1, 0, 0, 1, 0, 0);
  }

  drawWheel();
  // Draw again once fonts and stylesheets have settled, in case the canvas
  // still measured zero on the first pass.
  window.addEventListener('load', function () { if (!busy) drawWheel(); });

  // Redraw on resize, but never mid-spin — that would reset the canvas.
  var rt;
  window.addEventListener('resize', function () {
    clearTimeout(rt);
    rt = setTimeout(function () { if (!busy) drawWheel(); }, 200);
  });

  /* ---- countdown to the next spin ---- */
  var next = document.getElementById('nextIn');
  if (next) {
    var left = parseInt(next.dataset.seconds, 10) || 0;
    (function tick() {
      if (left <= 0) { next.textContent = 'a moment — refresh'; return; }
      var h = Math.floor(left / 3600),
          m = Math.floor((left % 3600) / 60),
          s = left % 60;
      next.textContent = h + 'h ' + ('0' + m).slice(-2) + 'm ' + ('0' + s).slice(-2) + 's';
      left--;
      setTimeout(tick, 1000);
    })();
  }

  if (swX) swX.addEventListener('click', function () { win.classList.remove('show'); });

  /* ---- cooldown between spins --------------------------------------- */
  function fmtWait(s) {
    if (s >= 3600) {
      return Math.floor(s / 3600) + 'h ' + ('0' + Math.floor((s % 3600) / 60)).slice(-2) + 'm';
    }
    if (s >= 60) {
      return Math.floor(s / 60) + 'm ' + ('0' + (s % 60)).slice(-2) + 's';
    }
    return s + 's';
  }

  /** Tick the button down, then hand the spin back when the wait is over. */
  function countdownOn(button, seconds, remaining) {
    var left = seconds;
    (function tick() {
      if (left <= 0) {
        if (remaining > 0) {
          button.textContent = '🎰 Spin again (' + remaining + ' left)';
          button.disabled = false;
          busy = false;
          if (wheel) wheel.classList.remove('is-spinning');
        } else {
          button.textContent = 'Come back tomorrow';
        }
        return;
      }
      button.textContent = 'Next spin in ' + fmtWait(left);
      left--;
      setTimeout(tick, 1000);
    })();
  }

  if (!btn || btn.disabled) return;

  btn.addEventListener('click', function () {
    if (busy) return;
    busy = true;
    btn.disabled = true;
    btn.textContent = 'Spinning…';
    wheel.classList.add('is-spinning');

    fetch(stage.dataset.endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ csrf: stage.dataset.csrf })
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {

        if (!res.ok && res.redirect) { window.location = res.redirect; return; }

        if (!res.ok && !res.already) {
          wheel.classList.remove('is-spinning');
          btn.disabled = false;
          btn.textContent = '🎰 Spin the wheel';
          busy = false;
          if (window.asvioraToast) window.asvioraToast(res.message || 'Spin failed.', 'error');
          return;
        }

        // land the pointer (top of the wheel) on the winning slice
        var seg    = res.segment || 0,
            target = 360 - (seg * STEP);
        turns += 6;                                   // six full rotations of drama
        wheel.style.transform = 'rotate(' + (turns * 360 + target) + 'deg)';

        setTimeout(function () {
          if (res.already) {
            btn.textContent = 'No spins left today';
            if (window.asvioraToast) window.asvioraToast(res.message, 'error');
            return;
          }
          swAmt.textContent = res.coins.toLocaleString('en-IN');
          win.classList.add('show');

          // an allowance can be more than one a day, so offer the next spin —
          // unless a cooldown is now running, in which case count it down
          var left = typeof res.remaining === 'number' ? res.remaining : 0,
              wait = typeof res.wait === 'number' ? res.wait : 0;

          if (wait > 0) {
            btn.textContent = 'Next spin in ' + fmtWait(wait);
            countdownOn(btn, wait, left);
          } else if (left > 0) {
            btn.textContent = '🎰 Spin again (' + left + ' left)';
            btn.disabled = false;
            busy = false;
            wheel.classList.remove('is-spinning');
          } else {
            btn.textContent = res.message || 'Come back tomorrow';
          }

          var pill = document.querySelector('.nav-cta .coin-pill');
          if (pill) pill.innerHTML = '🪙 ' + res.balance.toLocaleString('en-IN');
        }, 5500);
      })
      .catch(function () {
        wheel.classList.remove('is-spinning');
        btn.disabled = false;
        btn.textContent = '🎰 Spin the wheel';
        busy = false;
        if (window.asvioraToast) window.asvioraToast('Network error. Try again.', 'error');
      });
  });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
