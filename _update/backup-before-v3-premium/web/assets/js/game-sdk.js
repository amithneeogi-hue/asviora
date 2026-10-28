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
