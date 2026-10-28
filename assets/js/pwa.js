/* ==================================================================
   ASVIORA GAMES — install-as-an-app (PWA) wiring
   ------------------------------------------------------------------
   Three jobs:
     1. register the service worker (required before Chrome will ever
        offer an install prompt);
     2. capture beforeinstallprompt and fire it from the footer button;
     3. when the browser gives us no prompt — iOS Safari, Firefox, or a
        desktop browser that has not decided yet — fall back to a sheet
        with the manual steps instead of a dead button.
   ================================================================== */
(function () {
  'use strict';

  var band   = document.getElementById('installBand');
  var btn    = document.getElementById('installBtn');
  var hint   = document.getElementById('installHint');
  var helpBt = document.getElementById('installHelpBtn');
  var sheet  = document.getElementById('installSheet');
  var closeB = document.getElementById('installSheetClose');

  var script = document.currentScript;
  var swUrl  = (script && script.dataset.sw) || 'sw.js';
  var scope  = (script && script.dataset.scope) || './';

  /* ---------- 1. service worker ---------- */
  if ('serviceWorker' in navigator && location.protocol !== 'file:') {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(swUrl, { scope: scope }).catch(function () {
        /* registration fails on http:// over a LAN IP — harmless, the site
           still works, only the install prompt is unavailable */
      });
    });
  }

  if (!band || !btn) return;

  /* ---------- platform sniffing, only for the help text ---------- */
  var ua        = navigator.userAgent || '';
  var isIOS     = /iPad|iPhone|iPod/.test(ua) ||
                  (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var isAndroid = /Android/.test(ua);
  var installed = window.matchMedia('(display-mode: standalone)').matches ||
                  window.navigator.standalone === true;

  /* already running as an app — nothing to install */
  if (installed) {
    band.hidden = true;
    return;
  }

  function showOnly(id) {
    ['isIos', 'isAndroid', 'isDesktop'].forEach(function (k) {
      var el = document.getElementById(k);
      if (el) el.hidden = (k !== id);
    });
  }
  showOnly(isIOS ? 'isIos' : isAndroid ? 'isAndroid' : 'isDesktop');

  function openSheet() {
    if (!sheet) return;
    sheet.hidden = false;
    document.body.style.overflow = 'hidden';
  }
  function closeSheet() {
    if (!sheet) return;
    sheet.hidden = true;
    document.body.style.overflow = '';
  }
  if (closeB) closeB.addEventListener('click', closeSheet);
  if (sheet) {
    sheet.addEventListener('click', function (e) { if (e.target === sheet) closeSheet(); });
  }
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && sheet && !sheet.hidden) closeSheet();
  });
  if (helpBt) helpBt.addEventListener('click', openSheet);

  /* ---------- 2. the real Chrome/Edge install prompt ---------- */
  var deferred = null;

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferred = e;
    band.classList.add('ready');
    btn.disabled = false;
  });

  btn.addEventListener('click', function () {
    if (deferred) {
      deferred.prompt();
      deferred.userChoice.then(function (choice) {
        deferred = null;
        if (choice && choice.outcome === 'accepted') {
          band.classList.add('done');
          if (hint) hint.textContent = 'Installed. Look for the app icon on your home screen.';
          btn.disabled = true;
          btn.innerHTML = '✓&nbsp;Installed';
        }
      });
      return;
    }
    /* ---------- 3. no prompt available — show the manual steps ---------- */
    openSheet();
  });

  window.addEventListener('appinstalled', function () {
    band.classList.add('done');
    if (hint) hint.textContent = 'Installed. Look for the app icon on your home screen.';
    btn.disabled = true;
    btn.innerHTML = '✓&nbsp;Installed';
    closeSheet();
  });

  /* iOS never fires beforeinstallprompt, so label the button honestly */
  if (isIOS) {
    btn.innerHTML = '⬇&nbsp;Add to Home Screen';
    if (hint) {
      hint.textContent = 'Two taps in Safari and the app sits on your home screen — ' +
                         'full screen, no app store needed.';
    }
  }
}());
