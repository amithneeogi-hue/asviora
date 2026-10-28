/* ==================================================================
   ASVIORA GAME SDK
   Loaded inside each game iframe. Posts results up to play.php,
   which forwards them to api/submit_score.php.
   ================================================================== */
(function (w) {
  'use strict';

  var API = {
    /**
     * Report a finished round.
     * @param {Object} data { score, time_taken, moves, difficulty, result, meta }
     */
    submit: function (data) {
      var payload = {
        score:      Math.max(0, Math.round(data.score || 0)),
        time_taken: Math.max(0, Math.round(data.time_taken || 0)),
        moves:      Math.max(0, Math.round(data.moves || 0)),
        difficulty: String(data.difficulty || '').slice(0, 20),
        result:     data.result === 'loss' || data.result === 'incomplete' ? data.result : 'win',
        meta:       String(data.meta || '').slice(0, 200)
      };
      try {
        w.parent.postMessage({ type: 'asviora:score', payload: payload }, w.location.origin);
      } catch (err) { /* standalone mode */ }
      API.toast('Round complete — score ' + payload.score.toLocaleString('en-IN'));
    },

    /** Ask the parent page whether a player is logged in. */
    isEmbedded: function () {
      try { return w.parent !== w; } catch (e) { return false; }
    },

    /** Lightweight in-frame toast. */
    toast: function (msg, kind) {
      var wrap = document.getElementById('gToast');
      if (!wrap) {
        wrap = document.createElement('div');
        wrap.id = 'gToast';
        wrap.style.cssText = 'position:fixed;left:50%;bottom:16px;transform:translateX(-50%);' +
          'z-index:9999;display:flex;flex-direction:column;gap:8px;align-items:center;' +
          'pointer-events:none;width:max-content;max-width:92vw';
        document.body.appendChild(wrap);
      }
      var bad = kind === 'error';
      var el = document.createElement('div');
      el.textContent = msg;
      el.style.cssText = 'padding:11px 18px;border-radius:100px;font:700 .86rem/1.4 inherit;' +
        'text-align:center;background:' + (bad ? 'rgba(242,99,126,.16)' : 'rgba(164,217,76,.16)') + ';' +
        'color:' + (bad ? '#f2637e' : '#a4d94c') + ';border:1px solid ' +
        (bad ? 'rgba(242,99,126,.4)' : 'rgba(164,217,76,.4)') + ';' +
        'backdrop-filter:blur(10px);opacity:0;transform:translateY(10px);' +
        'transition:opacity .3s,transform .3s';
      wrap.appendChild(el);
      requestAnimationFrame(function () { el.style.opacity = '1'; el.style.transform = 'none'; });
      setTimeout(function () {
        el.style.opacity = '0'; el.style.transform = 'translateY(10px)';
        setTimeout(function () { el.remove(); }, 320);
      }, 2600);
    },

    /** mm:ss timer helper. */
    Timer: function (onTick) {
      var t = 0, id = null;
      return {
        get value() { return t; },
        start: function () {
          if (id) return;
          id = setInterval(function () { t++; if (onTick) onTick(t); }, 1000);
        },
        stop: function () { clearInterval(id); id = null; },
        reset: function () { this.stop(); t = 0; if (onTick) onTick(0); },
        format: function () {
          return String(Math.floor(t / 60)).padStart(2, '0') + ':' + String(t % 60).padStart(2, '0');
        }
      };
    },

    /** Best-score memory for the current browser session. */
    best: function (key, value) {
      var k = 'asv_best_' + key;
      try {
        if (value === undefined) return parseInt(sessionStorage.getItem(k) || '0', 10);
        var prev = parseInt(sessionStorage.getItem(k) || '0', 10);
        if (value > prev) { sessionStorage.setItem(k, String(value)); return value; }
        return prev;
      } catch (e) { return 0; }
    }
  };

  w.Asviora = API;
})(window);

/* ==================================================================
   v3 — PREMIUM SHELL
   Runs as soon as this script is parsed (it sits after the markup and
   before each game's own script), so the layout is final before a game
   measures its stage.

   - tags <html> with .in-app when the Android app hosts the page
     (its WebView adds "AsvioraApp/<ver>" to the user agent)
   - tags <body data-game="..."> so game.css can give each game its accent
   - moves the top bar's controls into a bottom DOCK on phones / in the app
   - ripple + a light haptic on every .gb button
   - bumps a stat when its number changes
   - throws confetti when a result modal opens
   ================================================================== */
(function (w, d) {
  'use strict';

  var html = d.documentElement;
  var body = d.body;
  if (!body) return;

  var inApp = /AsvioraApp\//.test(navigator.userAgent || '');
  if (inApp) html.classList.add('in-app');

  var reduce = false;
  try { reduce = w.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) {}

  /* ---------- which game ---------- */
  if (!body.getAttribute('data-game')) {
    var slug = '';
    try { slug = new URLSearchParams(w.location.search).get('game') || ''; } catch (e) {}
    if (!slug) {
      var m = (w.location.pathname || '').match(/games\/([^\/]+)\//i);
      if (m) slug = m[1];
    }
    slug = String(slug).toLowerCase();
    if (slug) body.setAttribute('data-game', slug);
  }

  /* ---------- dock ---------- */
  function buildDock() {
    var shell = d.querySelector('.g-shell');
    var bar = shell && shell.querySelector('.g-bar');
    var spacer = bar && bar.querySelector('.g-spacer');
    if (!spacer || shell.querySelector('.g-dock')) return;

    var moving = [];
    for (var n = spacer.nextElementSibling; n; n = n.nextElementSibling) moving.push(n);
    if (!moving.length) return;

    var dock = d.createElement('div');
    dock.className = 'g-dock';
    dock.setAttribute('role', 'toolbar');

    var primary = [];
    moving.forEach(function (el) {
      if (el.classList.contains('primary')) primary.push(el);
      else dock.appendChild(el);
    });
    // the main action sits last, pinned to the right edge
    primary.forEach(function (el) { dock.appendChild(el); });

    shell.appendChild(dock);
    html.classList.add('g-docked');
  }

  var narrow = false;
  try { narrow = w.matchMedia('(max-width: 720px)').matches; } catch (e) {}
  if (inApp || narrow) buildDock();

  /* ---------- ripple + haptic ---------- */
  d.addEventListener('pointerdown', function (ev) {
    var b = ev.target && ev.target.closest ? ev.target.closest('.gb') : null;
    if (!b || b.disabled) return;
    if (inApp && navigator.vibrate) { try { navigator.vibrate(8); } catch (e) {} }
    if (reduce) return;
    var r = b.getBoundingClientRect();
    var size = Math.max(r.width, r.height);
    var dot = d.createElement('span');
    dot.className = 'gb-ripple';
    dot.style.width = dot.style.height = size + 'px';
    dot.style.left = (ev.clientX - r.left - size / 2) + 'px';
    dot.style.top  = (ev.clientY - r.top  - size / 2) + 'px';
    b.appendChild(dot);
    setTimeout(function () { if (dot.parentNode) dot.parentNode.removeChild(dot); }, 600);
  }, { passive: true });

  /* ---------- stat bump ---------- */
  if (w.MutationObserver && !reduce) {
    Array.prototype.forEach.call(d.querySelectorAll('.g-stat .s-v'), function (el) {
      if (/time/i.test(el.id)) return;            // the clock ticks every second
      var last = el.textContent;
      new MutationObserver(function () {
        if (el.textContent === last) return;
        last = el.textContent;
        el.classList.remove('bump');
        void el.offsetWidth;                      // restart the animation
        el.classList.add('bump');
      }).observe(el, { childList: true, characterData: true, subtree: true });
    });
  }

  /* ---------- confetti ---------- */
  function confetti() {
    if (reduce) return;
    var c = d.createElement('canvas');
    c.className = 'g-confetti';
    var dpr = Math.min(w.devicePixelRatio || 1, 2);
    c.width = w.innerWidth * dpr;
    c.height = w.innerHeight * dpr;
    body.appendChild(c);
    var ctx = c.getContext('2d');
    if (!ctx) { c.remove(); return; }
    ctx.scale(dpr, dpr);

    var cs = getComputedStyle(body);
    var cols = [cs.getPropertyValue('--acc1').trim() || '#9BE15D', '#FFC94D', '#4FD1FF',
                '#FF5FA2', '#FFFFFF', '#8B5CF6'];
    var W = w.innerWidth, H = w.innerHeight, parts = [];
    for (var i = 0; i < 150; i++) {
      var fromLeft = i % 2 === 0;
      parts.push({
        x: fromLeft ? -10 : W + 10,
        y: H * (0.55 + Math.random() * 0.3),
        vx: (fromLeft ? 1 : -1) * (4 + Math.random() * 7),
        vy: -(9 + Math.random() * 9),
        s: 5 + Math.random() * 6,
        r: Math.random() * Math.PI,
        vr: (Math.random() - 0.5) * 0.4,
        c: cols[i % cols.length],
        round: Math.random() < 0.3
      });
    }
    var start = performance.now();
    (function frame(t) {
      var age = t - start;
      ctx.clearRect(0, 0, W, H);
      parts.forEach(function (p) {
        p.vy += 0.32; p.vx *= 0.99;
        p.x += p.vx; p.y += p.vy; p.r += p.vr;
        ctx.save();
        ctx.globalAlpha = Math.max(0, 1 - age / 2600);
        ctx.translate(p.x, p.y); ctx.rotate(p.r);
        ctx.fillStyle = p.c;
        if (p.round) { ctx.beginPath(); ctx.arc(0, 0, p.s / 2, 0, Math.PI * 2); ctx.fill(); }
        else ctx.fillRect(-p.s / 2, -p.s / 4, p.s, p.s / 2);
        ctx.restore();
      });
      if (age < 2600) requestAnimationFrame(frame);
      else if (c.parentNode) c.parentNode.removeChild(c);
    })(start);
    if (inApp && navigator.vibrate) { try { navigator.vibrate([20, 40, 30]); } catch (e) {} }
  }

  if (w.MutationObserver) {
    Array.prototype.forEach.call(d.querySelectorAll('.g-modal'), function (m) {
      var open = m.classList.contains('show');
      new MutationObserver(function () {
        var now = m.classList.contains('show');
        if (now && !open) {
          // only celebrate a result that earned something
          var sc = m.querySelector('.g-modal-score');
          var val = sc ? parseInt((sc.textContent || '').replace(/[^0-9]/g, ''), 10) : 1;
          if (!sc || val > 0) setTimeout(confetti, 180);
        }
        open = now;
      }).observe(m, { attributes: true, attributeFilter: ['class'] });
    });
  }

  w.Asviora = w.Asviora || {};
  w.Asviora.confetti = confetti;
  w.Asviora.inApp = inApp;
})(window, document);

