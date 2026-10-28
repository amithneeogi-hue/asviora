/**
 * ASVIORA CHESS
 * ===================================================================
 * Three things live in this file:
 *
 *   Chess  — the rules: move generation, make/unmake, check and mate.
 *            A straight port of includes/chess.php, so the browser and the
 *            server always agree on what is legal.
 *   ChessAI — alpha-beta search with piece-square tables, three strengths.
 *   Board   — the DOM board: click or drag to move, legal-square dots,
 *            last-move trail, promotion picker, flips for black.
 *
 * Index 0 = a8 ... 63 = h1, exactly like the PHP side.
 */
(function (global) {
  'use strict';

  /* ================================================================
   * Rules
   * ============================================================== */

  var START = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';

  function sqName(i) { return String.fromCharCode(97 + (i % 8)) + (8 - Math.floor(i / 8)); }
  function sqIndex(n) {
    if (!/^[a-h][1-8]$/.test(n)) return -1;
    return (8 - parseInt(n[1], 10)) * 8 + (n.charCodeAt(0) - 97);
  }
  function colourOf(p) { return p === '' ? '' : (p === p.toUpperCase() ? 'w' : 'b'); }

  function parseFen(fen) {
    var parts = String(fen || START).trim().split(/\s+/);
    var board = new Array(64).fill('');
    var i = 0;
    parts[0].split('').forEach(function (c) {
      if (c === '/') return;
      if (/[0-9]/.test(c)) i += parseInt(c, 10);
      else if (i < 64) board[i++] = c;
    });
    return {
      board: board,
      turn: parts[1] === 'b' ? 'b' : 'w',
      castle: (parts[2] && parts[2] !== '-') ? parts[2] : '',
      ep: (parts[3] && parts[3] !== '-') ? sqIndex(parts[3]) : -1,
      half: parseInt(parts[4] || '0', 10),
      full: Math.max(1, parseInt(parts[5] || '1', 10))
    };
  }

  function toFen(p) {
    var rows = [], r, f, empty, row, c;
    for (r = 0; r < 8; r++) {
      row = ''; empty = 0;
      for (f = 0; f < 8; f++) {
        c = p.board[r * 8 + f];
        if (c === '') { empty++; continue; }
        if (empty) { row += empty; empty = 0; }
        row += c;
      }
      if (empty) row += empty;
      rows.push(row);
    }
    return rows.join('/') + ' ' + p.turn + ' ' + (p.castle || '-') + ' ' +
           (p.ep >= 0 ? sqName(p.ep) : '-') + ' ' + p.half + ' ' + p.full;
  }

  var KNIGHT = [[1,2],[2,1],[-1,2],[-2,1],[1,-2],[2,-1],[-1,-2],[-2,-1]];
  var KING   = [[1,0],[-1,0],[0,1],[0,-1],[1,1],[1,-1],[-1,1],[-1,-1]];
  var ROOKD  = [[-1,0],[1,0],[0,-1],[0,1]];
  var BISHD  = [[-1,-1],[-1,1],[1,-1],[1,1]];

  function attacked(b, i, by) {
    var r = Math.floor(i / 8), f = i % 8, d, rr, ff, c, k;

    var dir = by === 'w' ? 1 : -1, pawn = by === 'w' ? 'P' : 'p';
    for (k = -1; k <= 1; k += 2) {
      rr = r + dir; ff = f + k;
      if (rr >= 0 && rr < 8 && ff >= 0 && ff < 8 && b[rr * 8 + ff] === pawn) return true;
    }

    var kn = by === 'w' ? 'N' : 'n';
    for (k = 0; k < KNIGHT.length; k++) {
      rr = r + KNIGHT[k][0]; ff = f + KNIGHT[k][1];
      if (rr >= 0 && rr < 8 && ff >= 0 && ff < 8 && b[rr * 8 + ff] === kn) return true;
    }

    var kg = by === 'w' ? 'K' : 'k';
    for (k = 0; k < KING.length; k++) {
      rr = r + KING[k][0]; ff = f + KING[k][1];
      if (rr >= 0 && rr < 8 && ff >= 0 && ff < 8 && b[rr * 8 + ff] === kg) return true;
    }

    var straight = by === 'w' ? ['R', 'Q'] : ['r', 'q'];
    var diagonal = by === 'w' ? ['B', 'Q'] : ['b', 'q'];
    for (k = 0; k < ROOKD.length; k++) {
      d = ROOKD[k]; rr = r + d[0]; ff = f + d[1];
      while (rr >= 0 && rr < 8 && ff >= 0 && ff < 8) {
        c = b[rr * 8 + ff];
        if (c !== '') { if (straight.indexOf(c) >= 0) return true; break; }
        rr += d[0]; ff += d[1];
      }
    }
    for (k = 0; k < BISHD.length; k++) {
      d = BISHD[k]; rr = r + d[0]; ff = f + d[1];
      while (rr >= 0 && rr < 8 && ff >= 0 && ff < 8) {
        c = b[rr * 8 + ff];
        if (c !== '') { if (diagonal.indexOf(c) >= 0) return true; break; }
        rr += d[0]; ff += d[1];
      }
    }
    return false;
  }

  function kingSquare(b, side) {
    var k = side === 'w' ? 'K' : 'k';
    for (var i = 0; i < 64; i++) if (b[i] === k) return i;
    return -1;
  }

  function inCheck(p, side) {
    var k = kingSquare(p.board, side);
    return k >= 0 && attacked(p.board, k, side === 'w' ? 'b' : 'w');
  }

  function make(p, m) {
    var b = p.board.slice(), from = m.from, to = m.to, pc = b[from];
    var me = colourOf(pc), type = pc.toLowerCase();
    var cap = b[to] !== '' || (type === 'p' && to === p.ep);

    b[to] = pc; b[from] = '';

    if (type === 'p' && m.flag === 'ep') b[to + (me === 'w' ? 8 : -8)] = '';
    if (type === 'p' && m.promo) b[to] = me === 'w' ? m.promo.toUpperCase() : m.promo.toLowerCase();

    if (type === 'k' && Math.abs((to % 8) - (from % 8)) === 2) {
      var row = Math.floor(to / 8);
      if (to % 8 === 6) { b[row * 8 + 5] = b[row * 8 + 7]; b[row * 8 + 7] = ''; }
      else { b[row * 8 + 3] = b[row * 8 + 0]; b[row * 8 + 0] = ''; }
    }

    var castle = p.castle;
    if (type === 'k') {
      castle = castle.split('').filter(function (c) {
        return me === 'w' ? (c !== 'K' && c !== 'Q') : (c !== 'k' && c !== 'q');
      }).join('');
    }
    [[63, 'K'], [56, 'Q'], [7, 'k'], [0, 'q']].forEach(function (rk) {
      if (from === rk[0] || to === rk[0]) castle = castle.replace(rk[1], '');
    });

    var ep = -1;
    if (type === 'p' && Math.abs(Math.floor(to / 8) - Math.floor(from / 8)) === 2) ep = (from + to) / 2;

    return {
      board: b,
      turn: me === 'w' ? 'b' : 'w',
      castle: castle,
      ep: ep,
      half: (type === 'p' || cap) ? 0 : p.half + 1,
      full: p.full + (me === 'b' ? 1 : 0)
    };
  }

  function moves(p) {
    var b = p.board, me = p.turn, them = me === 'w' ? 'b' : 'w', out = [];
    var i, r, f, pc, type, k, d, rr, ff, t, tc;

    function push(from, to, promo, flag) {
      out.push({ from: from, to: to, promo: promo || '', flag: flag || '' });
    }

    for (i = 0; i < 64; i++) {
      pc = b[i];
      if (pc === '' || colourOf(pc) !== me) continue;
      r = Math.floor(i / 8); f = i % 8; type = pc.toLowerCase();

      if (type === 'p') {
        var dir = me === 'w' ? -1 : 1, start = me === 'w' ? 6 : 1, last = me === 'w' ? 0 : 7;
        var r1 = r + dir;
        if (r1 >= 0 && r1 < 8 && b[r1 * 8 + f] === '') {
          if (r1 === last) ['q','r','b','n'].forEach(function (pr) { push(i, r1 * 8 + f, pr); });
          else {
            push(i, r1 * 8 + f);
            var r2 = r + 2 * dir;
            if (r === start && b[r2 * 8 + f] === '') push(i, r2 * 8 + f, '', 'double');
          }
        }
        [-1, 1].forEach(function (df) {
          var ff2 = f + df;
          if (ff2 < 0 || ff2 > 7 || r1 < 0 || r1 > 7) return;
          var t2 = r1 * 8 + ff2, tc2 = b[t2];
          if (tc2 !== '' && colourOf(tc2) === them) {
            if (r1 === last) ['q','r','b','n'].forEach(function (pr) { push(i, t2, pr); });
            else push(i, t2);
          } else if (tc2 === '' && t2 === p.ep) push(i, t2, '', 'ep');
        });
        continue;
      }

      if (type === 'n' || type === 'k') {
        var steps = type === 'n' ? KNIGHT : KING;
        for (k = 0; k < steps.length; k++) {
          rr = r + steps[k][0]; ff = f + steps[k][1];
          if (rr < 0 || rr > 7 || ff < 0 || ff > 7) continue;
          t = rr * 8 + ff;
          if (b[t] === '' || colourOf(b[t]) === them) push(i, t);
        }
        if (type === 'k') {
          var homeR = me === 'w' ? 7 : 0;
          if (i === homeR * 8 + 4 && !attacked(b, i, them)) {
            var kS = me === 'w' ? 'K' : 'k', qS = me === 'w' ? 'Q' : 'q';
            if (p.castle.indexOf(kS) >= 0 && b[homeR * 8 + 5] === '' && b[homeR * 8 + 6] === '' &&
                !attacked(b, homeR * 8 + 5, them) && !attacked(b, homeR * 8 + 6, them)) {
              push(i, homeR * 8 + 6, '', 'castle');
            }
            if (p.castle.indexOf(qS) >= 0 && b[homeR * 8 + 3] === '' && b[homeR * 8 + 2] === '' &&
                b[homeR * 8 + 1] === '' && !attacked(b, homeR * 8 + 3, them) &&
                !attacked(b, homeR * 8 + 2, them)) {
              push(i, homeR * 8 + 2, '', 'castle');
            }
          }
        }
        continue;
      }

      var dirs = [];
      if (type === 'r' || type === 'q') dirs = dirs.concat(ROOKD);
      if (type === 'b' || type === 'q') dirs = dirs.concat(BISHD);
      for (k = 0; k < dirs.length; k++) {
        d = dirs[k]; rr = r + d[0]; ff = f + d[1];
        while (rr >= 0 && rr < 8 && ff >= 0 && ff < 8) {
          t = rr * 8 + ff; tc = b[t];
          if (tc === '') push(i, t);
          else { if (colourOf(tc) === them) push(i, t); break; }
          rr += d[0]; ff += d[1];
        }
      }
    }

    return out.filter(function (m) { return !inCheck(make(p, m), me); });
  }

  function state(p) {
    var ms = moves(p), check = inCheck(p, p.turn);
    if (!ms.length) {
      return check
        ? { state: 'checkmate', winner: p.turn === 'w' ? 'black' : 'white' }
        : { state: 'stalemate', winner: 'draw' };
    }
    if (p.half >= 100) return { state: 'draw', winner: 'draw' };
    if (deadPosition(p)) return { state: 'draw', winner: 'draw' };
    return { state: check ? 'check' : 'play', winner: '' };
  }

  function deadPosition(p) {
    var men = p.board.filter(function (c) { return c !== '' && c.toLowerCase() !== 'k'; })
                     .map(function (c) { return c.toLowerCase(); });
    if (!men.length) return true;
    return men.length === 1 && (men[0] === 'b' || men[0] === 'n');
  }

  function uci(m) { return sqName(m.from) + sqName(m.to) + (m.promo || ''); }

  function findMove(p, str) {
    var ms = moves(p), i;
    var from = sqIndex(str.slice(0, 2)), to = sqIndex(str.slice(2, 4)), promo = str.slice(4, 5);
    for (i = 0; i < ms.length; i++) {
      if (ms[i].from === from && ms[i].to === to && (ms[i].promo || '') === (promo || '')) return ms[i];
    }
    return null;
  }

  /* Short algebraic-ish label, good enough for a move list. */
  function label(p, m) {
    var pc = p.board[m.from], type = pc.toLowerCase(), cap = p.board[m.to] !== '' || m.flag === 'ep';
    if (type === 'k' && Math.abs((m.to % 8) - (m.from % 8)) === 2) return (m.to % 8 === 6) ? 'O-O' : 'O-O-O';
    var s = type === 'p' ? (cap ? sqName(m.from)[0] : '') : type.toUpperCase();
    if (cap) s += 'x';
    s += sqName(m.to);
    if (m.promo) s += '=' + m.promo.toUpperCase();
    var after = make(p, m), st = state(after);
    if (st.state === 'checkmate') s += '#';
    else if (st.state === 'check') s += '+';
    return s;
  }

  var Chess = {
    START: START, parse: parseFen, fen: toFen, moves: moves, make: make,
    state: state, inCheck: inCheck, sqName: sqName, sqIndex: sqIndex,
    colourOf: colourOf, uci: uci, findMove: findMove, label: label
  };

  /* ================================================================
   * Engine
   * ============================================================== */

  var VALUE = { p: 100, n: 320, b: 330, r: 500, q: 900, k: 20000 };

  var PST = {
    p: [ 0,  0,  0,  0,  0,  0,  0,  0,
        50, 50, 50, 50, 50, 50, 50, 50,
        10, 10, 20, 30, 30, 20, 10, 10,
         5,  5, 10, 25, 25, 10,  5,  5,
         0,  0,  0, 20, 20,  0,  0,  0,
         5, -5,-10,  0,  0,-10, -5,  5,
         5, 10, 10,-20,-20, 10, 10,  5,
         0,  0,  0,  0,  0,  0,  0,  0],
    n: [-50,-40,-30,-30,-30,-30,-40,-50,
        -40,-20,  0,  0,  0,  0,-20,-40,
        -30,  0, 10, 15, 15, 10,  0,-30,
        -30,  5, 15, 20, 20, 15,  5,-30,
        -30,  0, 15, 20, 20, 15,  0,-30,
        -30,  5, 10, 15, 15, 10,  5,-30,
        -40,-20,  0,  5,  5,  0,-20,-40,
        -50,-40,-30,-30,-30,-30,-40,-50],
    b: [-20,-10,-10,-10,-10,-10,-10,-20,
        -10,  0,  0,  0,  0,  0,  0,-10,
        -10,  0,  5, 10, 10,  5,  0,-10,
        -10,  5,  5, 10, 10,  5,  5,-10,
        -10,  0, 10, 10, 10, 10,  0,-10,
        -10, 10, 10, 10, 10, 10, 10,-10,
        -10,  5,  0,  0,  0,  0,  5,-10,
        -20,-10,-10,-10,-10,-10,-10,-20],
    r: [  0,  0,  0,  0,  0,  0,  0,  0,
          5, 10, 10, 10, 10, 10, 10,  5,
         -5,  0,  0,  0,  0,  0,  0, -5,
         -5,  0,  0,  0,  0,  0,  0, -5,
         -5,  0,  0,  0,  0,  0,  0, -5,
         -5,  0,  0,  0,  0,  0,  0, -5,
         -5,  0,  0,  0,  0,  0,  0, -5,
          0,  0,  0,  5,  5,  0,  0,  0],
    q: [-20,-10,-10, -5, -5,-10,-10,-20,
        -10,  0,  0,  0,  0,  0,  0,-10,
        -10,  0,  5,  5,  5,  5,  0,-10,
         -5,  0,  5,  5,  5,  5,  0, -5,
          0,  0,  5,  5,  5,  5,  0, -5,
        -10,  5,  5,  5,  5,  5,  0,-10,
        -10,  0,  5,  0,  0,  0,  0,-10,
        -20,-10,-10, -5, -5,-10,-10,-20],
    k: [-30,-40,-40,-50,-50,-40,-40,-30,
        -30,-40,-40,-50,-50,-40,-40,-30,
        -30,-40,-40,-50,-50,-40,-40,-30,
        -30,-40,-40,-50,-50,-40,-40,-30,
        -20,-30,-30,-40,-40,-30,-30,-20,
        -10,-20,-20,-20,-20,-20,-20,-10,
         20, 20,  0,  0,  0,  0, 20, 20,
         20, 30, 10,  0,  0, 10, 30, 20]
  };

  /** Score from white's point of view. */
  function evaluate(p) {
    var s = 0, i, c, t, idx;
    for (i = 0; i < 64; i++) {
      c = p.board[i];
      if (c === '') continue;
      t = c.toLowerCase();
      idx = (c === c.toUpperCase()) ? i : (56 - 8 * Math.floor(i / 8) + (i % 8));
      if (c === c.toUpperCase()) s += VALUE[t] + PST[t][idx];
      else s -= VALUE[t] + PST[t][idx];
    }
    return s;
  }

  function orderMoves(p, ms) {
    return ms.map(function (m) {
      var victim = p.board[m.to];
      var score = 0;
      if (victim !== '') score += 10 * VALUE[victim.toLowerCase()] - VALUE[p.board[m.from].toLowerCase()];
      if (m.promo) score += 800;
      return { m: m, s: score };
    }).sort(function (a, b) { return b.s - a.s; }).map(function (x) { return x.m; });
  }

  function search(p, depth, alpha, beta) {
    if (depth === 0) return evaluate(p);
    var ms = moves(p);
    if (!ms.length) {
      if (inCheck(p, p.turn)) return p.turn === 'w' ? -99999 + (10 - depth) : 99999 - (10 - depth);
      return 0;
    }
    ms = orderMoves(p, ms);
    var i, v;
    if (p.turn === 'w') {
      v = -Infinity;
      for (i = 0; i < ms.length; i++) {
        v = Math.max(v, search(make(p, ms[i]), depth - 1, alpha, beta));
        alpha = Math.max(alpha, v);
        if (beta <= alpha) break;
      }
      return v;
    }
    v = Infinity;
    for (i = 0; i < ms.length; i++) {
      v = Math.min(v, search(make(p, ms[i]), depth - 1, alpha, beta));
      beta = Math.min(beta, v);
      if (beta <= alpha) break;
    }
    return v;
  }

  /**
   * Pick a move.
   * level 1 — depth 1 with a wobble, so it misses things a beginner misses
   * level 2 — depth 2, clean
   * level 3 — depth 3, clean
   */
  function bestMove(p, level) {
    var ms = orderMoves(p, moves(p));
    if (!ms.length) return null;

    var depth = level >= 3 ? 3 : (level === 2 ? 2 : 1);
    var noise = level === 1 ? 90 : (level === 2 ? 20 : 0);
    var best = null, bestScore = p.turn === 'w' ? -Infinity : Infinity, i, sc;

    for (i = 0; i < ms.length; i++) {
      sc = search(make(p, ms[i]), depth - 1, -Infinity, Infinity);
      if (noise) sc += (Math.random() * 2 - 1) * noise;
      if (p.turn === 'w' ? sc > bestScore : sc < bestScore) { bestScore = sc; best = ms[i]; }
    }
    return best;
  }

  var ChessAI = { bestMove: bestMove, evaluate: evaluate };

  /* ================================================================
   * Board UI
   * ============================================================== */

  var GLYPH = {
    K: '♔', Q: '♕', R: '♖', B: '♗', N: '♘', P: '♙',
    k: '♚', q: '♛', r: '♜', b: '♝', n: '♞', p: '♟'
  };

  function Board(el, opts) {
    opts = opts || {};
    this.el       = el;
    this.pos      = parseFen(opts.fen || START);
    this.side     = opts.side || 'w';          // which colour the human plays
    this.flip     = this.side === 'b';
    this.onMove   = opts.onMove || function () {};
    this.locked   = !!opts.locked;
    this.selected = -1;
    this.legal    = [];
    this.lastMove = null;
    this.squares  = [];
    this.build();
    this.render();
  }

  Board.prototype.build = function () {
    var self = this;
    this.el.classList.add('cb');
    this.el.innerHTML = '';
    for (var i = 0; i < 64; i++) {
      var d = document.createElement('div');
      d.className = 'cb-sq ' + ((Math.floor(i / 8) + i) % 2 ? 'dark' : 'light');
      d.dataset.i = i;
      d.addEventListener('click', function () { self.tap(parseInt(this.dataset.i, 10)); });
      this.el.appendChild(d);
      this.squares.push(d);
    }
  };

  Board.prototype.view = function (i) { return this.flip ? 63 - i : i; };

  Board.prototype.render = function () {
    var self = this;
    this.squares.forEach(function (d, vi) {
      var i = self.flip ? 63 - vi : vi;
      var pc = self.pos.board[i];
      d.textContent = pc ? GLYPH[pc] : '';
      d.className = 'cb-sq ' + ((Math.floor(i / 8) + i) % 2 ? 'dark' : 'light');
      if (pc) d.classList.add(colourOf(pc) === 'w' ? 'wp' : 'bp');
      if (self.selected === i) d.classList.add('sel');
      if (self.lastMove && (self.lastMove.from === i || self.lastMove.to === i)) d.classList.add('last');
      if (self.legal.some(function (m) { return m.to === i; })) {
        d.classList.add(self.pos.board[i] ? 'cap' : 'dot');
      }
    });

    if (inCheck(this.pos, this.pos.turn)) {
      var k = kingSquare(this.pos.board, this.pos.turn);
      if (k >= 0) this.squares[this.view(k)].classList.add('check');
    }
  };

  Board.prototype.setFen = function (fen, last) {
    this.pos = parseFen(fen);
    this.lastMove = last || null;
    this.selected = -1;
    this.legal = [];
    this.render();
  };

  Board.prototype.lock = function (v) { this.locked = !!v; };

  Board.prototype.tap = function (i) {
    if (this.locked) return;
    if (this.pos.turn !== this.side) return;

    var self = this;
    var hit = this.legal.filter(function (m) { return m.to === i; });

    if (hit.length) {
      if (hit.length > 1 && hit[0].promo) {
        this.askPromotion(function (piece) {
          var m = hit.filter(function (x) { return x.promo === piece; })[0] || hit[0];
          self.commit(m);
        });
        return;
      }
      this.commit(hit[0]);
      return;
    }

    var pc = this.pos.board[i];
    if (pc && colourOf(pc) === this.pos.turn) {
      this.selected = i;
      this.legal = moves(this.pos).filter(function (m) { return m.from === i; });
    } else {
      this.selected = -1;
      this.legal = [];
    }
    this.render();
  };

  Board.prototype.commit = function (m) {
    var text = label(this.pos, m);
    this.pos = make(this.pos, m);
    this.lastMove = m;
    this.selected = -1;
    this.legal = [];
    this.render();
    this.onMove(m, uci(m), text, this.pos);
  };

  /** Play a move that came from the engine or the server. */
  Board.prototype.applyUci = function (str) {
    var m = findMove(this.pos, str);
    if (!m) return null;
    var text = label(this.pos, m);
    this.pos = make(this.pos, m);
    this.lastMove = m;
    this.selected = -1;
    this.legal = [];
    this.render();
    return { move: m, text: text };
  };

  Board.prototype.askPromotion = function (cb) {
    var wrap = document.createElement('div');
    wrap.className = 'cb-promo';
    var white = this.side === 'w';
    ['q', 'r', 'b', 'n'].forEach(function (p) {
      var b = document.createElement('button');
      b.type = 'button';
      b.textContent = GLYPH[white ? p.toUpperCase() : p];
      b.addEventListener('click', function () { wrap.remove(); cb(p); });
      wrap.appendChild(b);
    });
    this.el.parentNode.appendChild(wrap);
  };

  global.Chess = Chess;
  global.ChessAI = ChessAI;
  global.ChessBoard = Board;

})(window);
