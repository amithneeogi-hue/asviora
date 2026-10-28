/* ==================================================================
   ASVIORA GAMES - front-end interactions
   ================================================================== */
(function () {
  'use strict';

  /* ---------- sticky header shadow ---------- */
  var header = document.querySelector('.site-header');
  function onScroll() {
    if (!header) return;
    header.classList.toggle('scrolled', window.scrollY > 12);
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  /* ---------- mobile nav ---------- */
  var burger = document.querySelector('.burger');
  var drawer = document.querySelector('.mobile-nav');
  if (burger && drawer) {
    burger.addEventListener('click', function () {
      var open = drawer.classList.toggle('open');
      burger.classList.toggle('open', open);
      document.body.classList.toggle('nav-open', open);
      burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    drawer.addEventListener('click', function (ev) {
      if (ev.target.tagName === 'A') {
        drawer.classList.remove('open');
        burger.classList.remove('open');
        document.body.classList.remove('nav-open');
      }
    });
  }

  /* ---------- user dropdown ---------- */
  var uMenu = document.querySelector('.user-menu');
  if (uMenu) {
    var uBtn = uMenu.querySelector('.user-btn');
    uBtn.addEventListener('click', function (ev) {
      ev.stopPropagation();
      uMenu.classList.toggle('open');
    });
    document.addEventListener('click', function () { uMenu.classList.remove('open'); });
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') uMenu.classList.remove('open');
    });
  }

  /* ---------- scroll reveal ---------- */
  var items = document.querySelectorAll('.reveal');
  if (items.length) {
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en, i) {
          if (en.isIntersecting) {
            var el = en.target;
            var d = parseInt(el.dataset.delay || (i * 70), 10);
            setTimeout(function () { el.classList.add('in'); }, d);
            io.unobserve(el);
          }
        });
      }, { threshold: 0.12, rootMargin: '0px 0px -40px' });
      items.forEach(function (el) { io.observe(el); });
    } else {
      items.forEach(function (el) { el.classList.add('in'); });
    }
  }

  /* ---------- animated counters ---------- */
  function animateCount(el) {
    var target = parseFloat(el.dataset.count || '0');
    var suffix = el.dataset.suffix || '';
    var dur = 1300, t0 = null;
    function step(ts) {
      if (!t0) t0 = ts;
      var p = Math.min((ts - t0) / dur, 1);
      var eased = 1 - Math.pow(1 - p, 3);
      var val = Math.floor(target * eased);
      el.textContent = val.toLocaleString('en-IN') + suffix;
      if (p < 1) requestAnimationFrame(step);
      else el.textContent = target.toLocaleString('en-IN') + suffix;
    }
    requestAnimationFrame(step);
  }
  var counters = document.querySelectorAll('[data-count]');
  if (counters.length && 'IntersectionObserver' in window) {
    var cio = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) { animateCount(en.target); cio.unobserve(en.target); }
      });
    }, { threshold: 0.4 });
    counters.forEach(function (el) { cio.observe(el); });
  } else {
    counters.forEach(animateCount);
  }

  /* ---------- show / hide password ----------
     Attaches an eye button to every password field on the page,
     so register, login, profile and the admin panel all get it. */
  (function () {
    var EYE =
      '<svg class="eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
      'stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
      '<path d="M1.5 12S5 5 12 5s10.5 7 10.5 7-3.5 7-10.5 7S1.5 12 1.5 12z"/>' +
      '<circle cx="12" cy="12" r="3.2"/></svg>' +
      '<svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
      'stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
      '<path d="M9.9 5.2A9.9 9.9 0 0 1 12 5c7 0 10.5 7 10.5 7a17 17 0 0 1-3.4 4.2M6.2 6.7A17 17 0 0 0 1.5 12S5 19 12 19a9.7 9.7 0 0 0 4.2-.9"/>' +
      '<path d="M9.8 9.9a3.2 3.2 0 0 0 4.4 4.4"/>' +
      '<path d="M2 2l20 20"/></svg>';

    var fields = document.querySelectorAll('input[type="password"]');

    Array.prototype.forEach.call(fields, function (input) {
      if (input.dataset.pwReady === '1') return;
      input.dataset.pwReady = '1';

      var wrap = document.createElement('span');
      wrap.className = 'pw-wrap';
      input.parentNode.insertBefore(wrap, input);
      wrap.appendChild(input);

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'pw-toggle';
      btn.innerHTML = EYE;
      btn.setAttribute('aria-label', 'Show password');
      btn.setAttribute('aria-pressed', 'false');
      btn.title = 'Show password';
      wrap.appendChild(btn);

      btn.addEventListener('click', function () {
        var showing = input.type === 'text';
        // remember the caret so the cursor does not jump to the end
        var start = null, end = null;
        try { start = input.selectionStart; end = input.selectionEnd; } catch (e) {}

        input.type = showing ? 'password' : 'text';
        btn.classList.toggle('is-on', !showing);
        btn.setAttribute('aria-pressed', showing ? 'false' : 'true');
        var label = showing ? 'Show password' : 'Hide password';
        btn.setAttribute('aria-label', label);
        btn.title = label;

        input.focus();
        if (start !== null) {
          try { input.setSelectionRange(start, end); } catch (e) {}
        }
      });

      // never leave a password visible after the form is submitted
      if (input.form) {
        input.form.addEventListener('submit', function () {
          input.type = 'password';
        });
      }
    });
  })();

  /* ---------- auto-hide flash messages ---------- */
  document.querySelectorAll('.alert').forEach(function (a) {
    if (a.dataset.persist === '1') return;
    setTimeout(function () {
      a.style.transition = 'opacity .45s, transform .45s, margin .45s, height .45s';
      a.style.opacity = '0';
      a.style.transform = 'translateY(-8px)';
      setTimeout(function () { a.remove(); }, 460);
    }, 5200);
  });

  /* ---------- confirm before destructive links ---------- */
  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-confirm]');
    if (t && !window.confirm(t.dataset.confirm)) ev.preventDefault();
  });

  /* ---------- receive scores from game iframes ---------- */
  window.addEventListener('message', function (ev) {
    if (ev.origin !== window.location.origin) return;
    var d = ev.data;
    if (!d || d.type !== 'asviora:score') return;
    submitScore(d.payload || {});
  });

  function submitScore(payload) {
    var meta = document.querySelector('meta[name="asviora-game"]');
    if (!meta) return;
    payload.game   = meta.dataset.slug;
    payload.token  = meta.dataset.token;

    fetch(meta.dataset.endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res && res.ok) showToast(res.message || 'Score saved!', 'success');
        else if (res && res.message) showToast(res.message, 'error');
      })
      .catch(function () { /* silent */ });
  }
  window.asvioraSubmitScore = submitScore;

  /* ---------- toast ---------- */
  function showToast(msg, type) {
    var wrap = document.getElementById('asvToast');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.id = 'asvToast';
      wrap.style.cssText =
        'position:fixed;right:18px;bottom:18px;z-index:9999;display:flex;' +
        'flex-direction:column;gap:10px;max-width:min(340px,calc(100vw - 36px))';
      document.body.appendChild(wrap);
    }
    var ok = type !== 'error';
    var t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText =
      'padding:13px 17px;border-radius:12px;font:600 .9rem/1.45 inherit;' +
      'background:' + (ok ? 'rgba(164,217,76,.14)' : 'rgba(242,99,126,.14)') + ';' +
      'color:' + (ok ? '#a4d94c' : '#f2637e') + ';' +
      'border:1px solid ' + (ok ? 'rgba(164,217,76,.36)' : 'rgba(242,99,126,.36)') + ';' +
      'backdrop-filter:blur(12px);box-shadow:0 14px 34px -14px rgba(0,0,0,.8);' +
      'opacity:0;transform:translateY(14px);transition:opacity .3s,transform .3s';
    wrap.appendChild(t);
    requestAnimationFrame(function () { t.style.opacity = '1'; t.style.transform = 'none'; });
    setTimeout(function () {
      t.style.opacity = '0'; t.style.transform = 'translateY(14px)';
      setTimeout(function () { t.remove(); }, 320);
    }, 3800);
  }
  window.asvioraToast = showToast;

  /* ---------- fullscreen toggle for game frame ---------- */
  var fsBtn = document.getElementById('fsToggle');
  if (fsBtn) {
    fsBtn.addEventListener('click', function () {
      var frame = document.querySelector('.play-frame');
      if (!frame) return;
      if (document.fullscreenElement) document.exitFullscreen();
      else if (frame.requestFullscreen) frame.requestFullscreen();
    });
  }
})();
