/**
 * Chess board page controller.
 *
 * AI mode  — the whole game runs in the browser; only the final result is
 *            reported, and it is worth a small capped reward.
 * PVP mode — the browser is a viewer with buttons. Every move is sent to
 *            api/chess.php, and the position it sends back is what is drawn.
 */
(function () {
  'use strict';

  var shell = document.getElementById('chessShell');
  if (!shell || !window.Chess) return;

  var API    = shell.dataset.endpoint;
  var CSRF   = shell.dataset.csrf;
  var MODE   = shell.dataset.mode;
  var LEVEL  = parseInt(shell.dataset.ai || '0', 10);
  var CODE   = shell.dataset.code;
  var el     = {
    board:   document.getElementById('board'),
    state:   document.getElementById('stateBox'),
    moves:   document.getElementById('moveList'),
    topName: document.getElementById('topName'),
    botName: document.getElementById('botName'),
    topClock:document.getElementById('topClock'),
    botClock:document.getElementById('botClock'),
    actions: document.getElementById('liveActions'),
    pot:     document.getElementById('potView')
  };

  var board, history = [], reported = false;

  function say(html, tone) {
    el.state.innerHTML = html;
    el.state.className = 'chess-state' + (tone ? ' ' + tone : '');
  }

  function addMove(text) {
    var li = document.createElement('li');
    li.textContent = text;
    el.moves.appendChild(li);
    el.moves.scrollTop = el.moves.scrollHeight;
  }

  function post(payload, cb) {
    payload.csrf = CSRF;
    fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    }).then(function (r) { return r.json(); })
      .then(cb)
      .catch(function () { say('Network problem — retrying&hellip;', 'warn'); });
  }

  function clockText(ms) {
    if (ms === null || ms === undefined) return '--:--';
    ms = Math.max(0, ms);
    var s = Math.floor(ms / 1000);
    return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
  }

  /* ================================================================
   * Practice against the engine
   * ============================================================== */
  function startAi() {
    board = new window.ChessBoard(el.board, {
      fen: window.Chess.START,
      side: 'w',
      onMove: function (m, uci, text) {
        history.push(uci);
        addMove(history.length + '. ' + text);
        if (finished(board.pos)) return;
        board.lock(true);
        say('Computer is thinking&hellip;');
        setTimeout(engineTurn, 220);
      }
    });

    el.topName.textContent = 'Computer (' + ['', 'Easy', 'Medium', 'Hard'][LEVEL] + ')';
    el.botName.textContent = 'You';
    say('Your move — you are white.');

    var undo = document.getElementById('btnUndo');
    if (undo) undo.addEventListener('click', function () {
      // rebuild from the move list minus the last full move
      if (history.length < 2) return;
      history = history.slice(0, history.length - 2);
      var p = window.Chess.parse(window.Chess.START);
      el.moves.innerHTML = '';
      history.forEach(function (u, i) {
        var mv = window.Chess.findMove(p, u);
        addMove((i + 1) + '. ' + window.Chess.label(p, mv));
        p = window.Chess.make(p, mv);
      });
      board.setFen(window.Chess.fen(p), null);
      board.lock(false);
      say('Taken back. Your move.');
    });
  }

  function engineTurn() {
    var mv = window.ChessAI.bestMove(board.pos, LEVEL);
    if (!mv) { finished(board.pos); return; }
    var res = board.applyUci(window.Chess.uci(mv));
    if (res) {
      history.push(window.Chess.uci(mv));
      addMove(history.length + '. ' + res.text);
    }
    board.lock(false);
    if (!finished(board.pos)) say('Your move.');
  }

  function finished(pos) {
    var st = window.Chess.state(pos);
    if (st.state === 'checkmate') {
      var iWon = st.winner === 'white';
      say(iWon ? '<b>Checkmate — you win.</b>' : '<b>Checkmate — the computer wins.</b>',
          iWon ? 'good' : 'bad');
      board.lock(true);
      report(iWon ? 'win' : 'loss');
      return true;
    }
    if (st.state === 'stalemate' || st.state === 'draw') {
      say('<b>Draw.</b>', 'warn');
      board.lock(true);
      report('draw');
      return true;
    }
    if (st.state === 'check') say('Check!', 'warn');
    return false;
  }

  function report(result) {
    if (MODE !== 'ai' || reported) return;
    reported = true;
    post({ action: 'ai_result', result: result, level: LEVEL,
           plies: history.length, moves: history.join(' ') }, function (d) {
      if (d && d.ok && d.coins) {
        say(el.state.innerHTML + ' <span class="text-gold">+' + d.coins + ' coins</span>', 'good');
      } else if (d && d.ok && d.capped) {
        say(el.state.innerHTML + ' <span class="text-dim">(daily reward limit reached)</span>', 'good');
      }
    });
  }

  /* ================================================================
   * Staked match
   * ============================================================== */
  var pollTimer = null, tickTimer = null, lastCount = -1, lastStatus = '';
  var clocks = { w: null, b: null, turn: 'w', running: false };

  function startPvp() {
    board = new window.ChessBoard(el.board, {
      fen: shell.dataset.fen,
      side: shell.dataset.side,
      locked: true,
      onMove: function (m, uci) {
        board.lock(true);
        post({ action: 'move', code: CODE, uci: uci }, function (d) {
          if (!d.ok) {
            say(d.message || 'That move was refused.', 'bad');
            if (d.match) draw(d.match);
            return;
          }
          draw(d.match);
        });
      }
    });

    var join = document.getElementById('btnJoin');
    if (join) join.addEventListener('click', function () {
      join.disabled = true; join.textContent = 'Joining...';
      post({ action: 'join', code: CODE }, function (d) {
        if (!d.ok) {
          join.disabled = false; join.textContent = 'Accept the challenge';
          say(d.message || 'Could not join.', 'bad');
          return;
        }
        location.reload();
      });
    });

    var cancel = document.getElementById('btnCancel');
    if (cancel) cancel.addEventListener('click', function () {
      post({ action: 'cancel', code: CODE }, function (d) {
        if (d.ok) location.href = shell.dataset.lobby;
        else say(d.message || 'Could not cancel.', 'bad');
      });
    });

    var resign = document.getElementById('btnResign');
    if (resign) resign.addEventListener('click', function () {
      if (!confirm('Resign this match? Your opponent takes the pot.')) return;
      post({ action: 'resign', code: CODE }, function (d) { if (d.ok) draw(d.match); });
    });

    var drawBtn = document.getElementById('btnDraw');
    if (drawBtn) drawBtn.addEventListener('click', function () {
      post({ action: 'draw', code: CODE, what: drawBtn.dataset.what || 'offer' },
        function (d) { if (d.ok) draw(d.match); });
    });

    var copy = document.getElementById('copyLink');
    if (copy) copy.addEventListener('click', function () {
      var i = document.getElementById('inviteLink');
      i.select();
      try { document.execCommand('copy'); } catch (e) {}
      if (navigator.clipboard) navigator.clipboard.writeText(i.value);
      copy.textContent = 'Copied';
      setTimeout(function () { copy.textContent = 'Copy'; }, 1500);
    });

    poll();
    pollTimer = setInterval(poll, 2000);
    tickTimer = setInterval(tick, 250);
  }

  function poll() {
    post({ action: 'state', code: CODE }, function (d) {
      if (d && d.ok) draw(d.match);
    });
  }

  function draw(m) {
    if (!m) return;

    // board — only redraw when something actually changed, so the player's
    // own selection is not wiped every two seconds
    if (m.moves.length !== lastCount || m.status !== lastStatus) {
      var last = null;
      if (m.moves.length) {
        var u = m.moves[m.moves.length - 1];
        last = { from: window.Chess.sqIndex(u.slice(0, 2)), to: window.Chess.sqIndex(u.slice(2, 4)) };
      }
      board.side = m.you || 'w';
      board.flip = board.side === 'b';
      board.setFen(m.fen, last);

      el.moves.innerHTML = '';
      var p = window.Chess.parse(window.Chess.START);
      m.moves.forEach(function (u, i) {
        var mv = window.Chess.findMove(p, u);
        if (!mv) return;
        addMove((i + 1) + '. ' + window.Chess.label(p, mv));
        p = window.Chess.make(p, mv);
      });
      lastCount = m.moves.length;
      lastStatus = m.status;
    }

    if (el.pot) el.pot.textContent = m.pot ? m.pot.toLocaleString() : '0';

    // names + clocks, opponent on top
    var top = m.you === 'b' ? m.white : m.black;
    var bot = m.you === 'b' ? m.black : m.white;
    el.topName.textContent = top ? top.name : 'Waiting for an opponent';
    el.botName.textContent = bot ? bot.name : 'You';

    clocks.w = m.white_ms;
    clocks.b = m.black_ms;
    clocks.turn = m.turn;
    clocks.you = m.you;
    clocks.running = m.status === 'active';
    paintClocks();

    if (m.status === 'open') {
      say('Waiting for someone to accept&hellip;');
      board.lock(true);
      el.actions.hidden = true;
      return;
    }

    if (m.status === 'cancelled') {
      say('This challenge was cancelled.', 'warn');
      board.lock(true);
      el.actions.hidden = true;
      stop();
      return;
    }

    if (m.status === 'finished') {
      var mine = m.you === 'w' ? 'white' : 'black';
      var msg;
      if (m.result === 'draw') msg = '<b>Draw</b> — stakes returned.';
      else if (m.result === mine) msg = '<b>You won</b> — the pot is yours.';
      else msg = '<b>You lost</b> — ' + (m.reason || 'game over') + '.';
      say(msg + ' <span class="text-dim">(' + (m.reason || '') + ')</span>',
          m.result === mine ? 'good' : (m.result === 'draw' ? 'warn' : 'bad'));
      board.lock(true);
      el.actions.hidden = true;
      stop();
      return;
    }

    // active
    el.actions.hidden = false;
    var myTurn = m.turn === m.you;
    board.lock(!myTurn);

    var db = document.getElementById('btnDraw');
    if (db) {
      if (m.draw_offer && !m.draw_offer_mine) {
        db.textContent = 'Accept draw';
        db.dataset.what = 'accept';
      } else {
        db.textContent = m.draw_offer ? 'Draw offered' : 'Offer draw';
        db.dataset.what = 'offer';
      }
    }

    say(myTurn ? '<b>Your move.</b>' : 'Waiting for your opponent&hellip;', myTurn ? 'good' : '');
  }

  function paintClocks() {
    var youTop = clocks.you === 'b' ? 'w' : 'b';
    el.topClock.textContent = clockText(clocks[youTop]);
    el.botClock.textContent = clockText(clocks[clocks.you === 'b' ? 'b' : 'w']);
    el.topClock.classList.toggle('run', clocks.running && clocks.turn === youTop);
    el.botClock.classList.toggle('run', clocks.running && clocks.turn === clocks.you);
  }

  function tick() {
    if (!clocks.running) return;
    var side = clocks.turn;
    if (clocks[side] === null) return;
    clocks[side] = Math.max(0, clocks[side] - 250);
    paintClocks();
  }

  function stop() {
    if (pollTimer) clearInterval(pollTimer);
    if (tickTimer) clearInterval(tickTimer);
    pollTimer = tickTimer = null;
    clocks.running = false;
  }

  if (MODE === 'ai') startAi(); else startPvp();
})();
