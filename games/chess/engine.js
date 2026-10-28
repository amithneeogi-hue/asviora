/* ==================================================================
   ASVIORA CHESS ENGINE
   ------------------------------------------------------------------
   A complete, self-contained chess implementation: full legal move
   generation (castling, en passant, promotion, pins, checks), draw
   detection (stalemate, insufficient material, fifty-move, threefold)
   and a searching computer opponent.

   The board is a 0x88 array — 128 squares, of which the "off-board"
   half is used purely as a boundary test. `sq & 0x88` is non-zero for
   any square that has fallen off the edge, which turns every sliding
   move loop into a single AND instead of a pair of range checks.

   Nothing here touches the DOM, so the same file drives the local
   game, the computer and the online board.
   ================================================================== */
(function (root) {
  'use strict';

  // ---------------- pieces ----------------
  var EMPTY = 0,
      PAWN = 1, KNIGHT = 2, BISHOP = 3, ROOK = 4, QUEEN = 5, KING = 6,
      WHITE = 8, BLACK = 16,
      TYPE = 7, COLOR = 24;

  var PIECE_CHAR = { 1: 'p', 2: 'n', 3: 'b', 4: 'r', 5: 'q', 6: 'k' };
  var CHAR_PIECE = { p: PAWN, n: KNIGHT, b: BISHOP, r: ROOK, q: QUEEN, k: KING };

  // ---------------- geometry ----------------
  var KNIGHT_DIRS = [-33, -31, -18, -14, 14, 18, 31, 33],
      BISHOP_DIRS = [-17, -15, 15, 17],
      ROOK_DIRS   = [-16, -1, 1, 16],
      KING_DIRS   = [-17, -16, -15, -1, 1, 15, 16, 17];

  function sqName(sq) {
    return 'abcdefgh'[sq & 15] + (8 - (sq >> 4));
  }
  function nameSq(name) {
    var f = 'abcdefgh'.indexOf(name[0]),
        r = 8 - parseInt(name[1], 10);
    return (r << 4) + f;
  }
  function onBoard(sq) { return (sq & 0x88) === 0; }
  function colorOf(p) { return p & COLOR; }
  function typeOf(p)  { return p & TYPE; }

  // ==================================================================
  //  POSITION
  // ==================================================================
  function Position(fen) {
    this.board    = new Array(128).fill(EMPTY);
    this.turn     = WHITE;
    this.castling = { K: false, Q: false, k: false, q: false };
    this.ep       = -1;
    this.half     = 0;
    this.full     = 1;
    this.history  = [];      // for undo
    this.repeats  = {};      // fen-key counts, for threefold

    this.load(fen || 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1');
  }

  Position.prototype.load = function (fen) {
    var parts = fen.trim().split(/\s+/),
        rows  = parts[0].split('/'),
        sq    = 0, i, j, ch;

    this.board.fill(EMPTY);

    for (i = 0; i < 8; i++) {
      sq = i << 4;
      for (j = 0; j < rows[i].length; j++) {
        ch = rows[i][j];
        if (/\d/.test(ch)) {
          sq += parseInt(ch, 10);
        } else {
          var t = CHAR_PIECE[ch.toLowerCase()];
          this.board[sq] = t | (ch === ch.toUpperCase() ? WHITE : BLACK);
          sq++;
        }
      }
    }

    this.turn = (parts[1] === 'b') ? BLACK : WHITE;

    var c = parts[2] || '-';
    this.castling = {
      K: c.indexOf('K') > -1, Q: c.indexOf('Q') > -1,
      k: c.indexOf('k') > -1, q: c.indexOf('q') > -1
    };

    this.ep   = (parts[3] && parts[3] !== '-') ? nameSq(parts[3]) : -1;
    this.half = parseInt(parts[4] || '0', 10);
    this.full = parseInt(parts[5] || '1', 10);

    this.history = [];
    this.repeats = {};
    this.repeats[this.key()] = 1;
  };

  Position.prototype.fen = function () {
    var out = '', empty = 0, r, f, sq, p;

    for (r = 0; r < 8; r++) {
      empty = 0;
      for (f = 0; f < 8; f++) {
        sq = (r << 4) + f;
        p  = this.board[sq];
        if (p === EMPTY) { empty++; continue; }
        if (empty) { out += empty; empty = 0; }
        var ch = PIECE_CHAR[typeOf(p)];
        out += colorOf(p) === WHITE ? ch.toUpperCase() : ch;
      }
      if (empty) out += empty;
      if (r < 7) out += '/';
    }

    var c = (this.castling.K ? 'K' : '') + (this.castling.Q ? 'Q' : '') +
            (this.castling.k ? 'k' : '') + (this.castling.q ? 'q' : '');

    return out + ' ' + (this.turn === WHITE ? 'w' : 'b') + ' ' +
           (c || '-') + ' ' + (this.ep >= 0 ? sqName(this.ep) : '-') + ' ' +
           this.half + ' ' + this.full;
  };

  /** Position identity for repetition — everything but the clocks. */
  Position.prototype.key = function () {
    return this.fen().split(' ').slice(0, 4).join(' ');
  };

  Position.prototype.kingSquare = function (color) {
    for (var sq = 0; sq < 128; sq++) {
      if (sq & 0x88) { sq += 7; continue; }
      var p = this.board[sq];
      if (p && typeOf(p) === KING && colorOf(p) === color) return sq;
    }
    return -1;
  };

  /**
   * Is `sq` attacked by `byColor`? Used for check detection and for the
   * squares a king passes over while castling.
   */
  Position.prototype.attacked = function (sq, byColor) {
    var i, d, t, p;

    // pawns
    var pawnDir = byColor === WHITE ? 16 : -16;   // from sq back towards them
    for (i = -1; i <= 1; i += 2) {
      t = sq + pawnDir + i;
      if (onBoard(t)) {
        p = this.board[t];
        if (p && colorOf(p) === byColor && typeOf(p) === PAWN) return true;
      }
    }

    // knights
    for (i = 0; i < 8; i++) {
      t = sq + KNIGHT_DIRS[i];
      if (!onBoard(t)) continue;
      p = this.board[t];
      if (p && colorOf(p) === byColor && typeOf(p) === KNIGHT) return true;
    }

    // king
    for (i = 0; i < 8; i++) {
      t = sq + KING_DIRS[i];
      if (!onBoard(t)) continue;
      p = this.board[t];
      if (p && colorOf(p) === byColor && typeOf(p) === KING) return true;
    }

    // sliding: bishops/queens then rooks/queens
    for (i = 0; i < 4; i++) {
      d = BISHOP_DIRS[i];
      t = sq + d;
      while (onBoard(t)) {
        p = this.board[t];
        if (p) {
          if (colorOf(p) === byColor && (typeOf(p) === BISHOP || typeOf(p) === QUEEN)) return true;
          break;
        }
        t += d;
      }
    }
    for (i = 0; i < 4; i++) {
      d = ROOK_DIRS[i];
      t = sq + d;
      while (onBoard(t)) {
        p = this.board[t];
        if (p) {
          if (colorOf(p) === byColor && (typeOf(p) === ROOK || typeOf(p) === QUEEN)) return true;
          break;
        }
        t += d;
      }
    }

    return false;
  };

  Position.prototype.inCheck = function (color) {
    color = color === undefined ? this.turn : color;
    var k = this.kingSquare(color);
    if (k < 0) return false;
    return this.attacked(k, color === WHITE ? BLACK : WHITE);
  };

  /** Pseudo-legal moves; legality is filtered in moves(). */
  Position.prototype.pseudoMoves = function (onlyCaptures) {
    var out = [], me = this.turn, them = me === WHITE ? BLACK : WHITE,
        sq, p, t, i, d, from;

    var push = function (from, to, flags, promo) {
      out.push({
        from: from, to: to,
        piece: this.board[from],
        captured: flags === 'ep' ? (PAWN | them) : this.board[to],
        flags: flags || '',
        promo: promo || 0
      });
    }.bind(this);

    for (sq = 0; sq < 128; sq++) {
      if (sq & 0x88) { sq += 7; continue; }
      p = this.board[sq];
      if (!p || colorOf(p) !== me) continue;
      from = sq;

      switch (typeOf(p)) {

        case PAWN: {
          var fwd  = me === WHITE ? -16 : 16,
              start = me === WHITE ? 6 : 1,
              last  = me === WHITE ? 0 : 7,
              rank  = from >> 4;

          // one and two forward
          t = from + fwd;
          if (onBoard(t) && !this.board[t] && !onlyCaptures) {
            if ((t >> 4) === last) {
              [QUEEN, ROOK, BISHOP, KNIGHT].forEach(function (pr) { push(from, t, 'promo', pr); });
            } else {
              push(from, t, '');
              var t2 = from + 2 * fwd;
              if (rank === start && !this.board[t2]) push(from, t2, 'big');
            }
          }

          // captures + en passant
          for (i = -1; i <= 1; i += 2) {
            t = from + fwd + i;
            if (!onBoard(t)) continue;
            if (this.board[t] && colorOf(this.board[t]) === them) {
              if ((t >> 4) === last) {
                [QUEEN, ROOK, BISHOP, KNIGHT].forEach(function (pr) { push(from, t, 'promo', pr); });
              } else {
                push(from, t, 'cap');
              }
            } else if (t === this.ep) {
              push(from, t, 'ep');
            }
          }
          break;
        }

        case KNIGHT:
        case KING: {
          var dirs = typeOf(p) === KNIGHT ? KNIGHT_DIRS : KING_DIRS;
          for (i = 0; i < 8; i++) {
            t = from + dirs[i];
            if (!onBoard(t)) continue;
            var occ = this.board[t];
            if (occ && colorOf(occ) === me) continue;
            if (onlyCaptures && !occ) continue;
            push(from, t, occ ? 'cap' : '');
          }
          break;
        }

        default: {
          var slides = typeOf(p) === BISHOP ? BISHOP_DIRS
                     : typeOf(p) === ROOK   ? ROOK_DIRS
                     : BISHOP_DIRS.concat(ROOK_DIRS);
          for (i = 0; i < slides.length; i++) {
            d = slides[i];
            t = from + d;
            while (onBoard(t)) {
              var occ2 = this.board[t];
              if (!occ2) {
                if (!onlyCaptures) push(from, t, '');
              } else {
                if (colorOf(occ2) === them) push(from, t, 'cap');
                break;
              }
              t += d;
            }
          }
        }
      }
    }

    // castling — the king may not start in check, pass through an
    // attacked square, or land on one
    if (!onlyCaptures) {
      var kSq = me === WHITE ? nameSq('e1') : nameSq('e8'),
          rights = me === WHITE ? [this.castling.K, this.castling.Q]
                                : [this.castling.k, this.castling.q];

      if (this.board[kSq] === (KING | me) && !this.attacked(kSq, them)) {
        // king side
        if (rights[0] && !this.board[kSq + 1] && !this.board[kSq + 2] &&
            this.board[kSq + 3] === (ROOK | me) &&
            !this.attacked(kSq + 1, them) && !this.attacked(kSq + 2, them)) {
          push(kSq, kSq + 2, 'kcastle');
        }
        // queen side
        if (rights[1] && !this.board[kSq - 1] && !this.board[kSq - 2] && !this.board[kSq - 3] &&
            this.board[kSq - 4] === (ROOK | me) &&
            !this.attacked(kSq - 1, them) && !this.attacked(kSq - 2, them)) {
          push(kSq, kSq - 2, 'qcastle');
        }
      }
    }

    return out;
  };

  /** Fully legal moves for the side to move. */
  Position.prototype.moves = function (onlyCaptures) {
    var pseudo = this.pseudoMoves(onlyCaptures),
        legal  = [], me = this.turn, i;

    for (i = 0; i < pseudo.length; i++) {
      this.make(pseudo[i]);
      if (!this.inCheck(me)) legal.push(pseudo[i]);
      this.unmake();
    }
    return legal;
  };

  /** Legal destinations from one square — what the board highlights. */
  Position.prototype.movesFrom = function (sq) {
    return this.moves().filter(function (m) { return m.from === sq; });
  };

  Position.prototype.make = function (m) {
    var me = this.turn, them = me === WHITE ? BLACK : WHITE;

    this.history.push({
      move: m,
      castling: { K: this.castling.K, Q: this.castling.Q,
                  k: this.castling.k, q: this.castling.q },
      ep: this.ep, half: this.half, full: this.full,
      captured: this.board[m.to]
    });

    this.board[m.to]   = this.board[m.from];
    this.board[m.from] = EMPTY;

    if (m.flags === 'promo') this.board[m.to] = m.promo | me;

    if (m.flags === 'ep') {
      this.board[m.to + (me === WHITE ? 16 : -16)] = EMPTY;
    }

    if (m.flags === 'kcastle') {
      this.board[m.to - 1] = this.board[m.to + 1];
      this.board[m.to + 1] = EMPTY;
    }
    if (m.flags === 'qcastle') {
      this.board[m.to + 1] = this.board[m.to - 2];
      this.board[m.to - 2] = EMPTY;
    }

    // castling rights die when the king or a rook moves, or a rook is taken
    if (typeOf(m.piece) === KING) {
      if (me === WHITE) { this.castling.K = this.castling.Q = false; }
      else              { this.castling.k = this.castling.q = false; }
    }
    var h1 = nameSq('h1'), a1 = nameSq('a1'), h8 = nameSq('h8'), a8 = nameSq('a8');
    if (m.from === h1 || m.to === h1) this.castling.K = false;
    if (m.from === a1 || m.to === a1) this.castling.Q = false;
    if (m.from === h8 || m.to === h8) this.castling.k = false;
    if (m.from === a8 || m.to === a8) this.castling.q = false;

    this.ep = (m.flags === 'big') ? (m.from + (me === WHITE ? -16 : 16)) : -1;

    this.half = (typeOf(m.piece) === PAWN || m.captured) ? 0 : this.half + 1;
    if (me === BLACK) this.full++;

    this.turn = them;

    var k = this.key();
    this.repeats[k] = (this.repeats[k] || 0) + 1;
  };

  Position.prototype.unmake = function () {
    var h = this.history.pop();
    if (!h) return;

    var k = this.key();
    this.repeats[k] = Math.max(0, (this.repeats[k] || 1) - 1);

    var m = h.move,
        them = this.turn,
        me = them === WHITE ? BLACK : WHITE;

    this.turn = me;

    this.board[m.from] = (m.flags === 'promo') ? (PAWN | me) : this.board[m.to];
    this.board[m.to]   = h.captured || EMPTY;

    if (m.flags === 'ep') {
      this.board[m.to] = EMPTY;
      this.board[m.to + (me === WHITE ? 16 : -16)] = PAWN | them;
    }
    if (m.flags === 'kcastle') {
      this.board[m.to + 1] = this.board[m.to - 1];
      this.board[m.to - 1] = EMPTY;
    }
    if (m.flags === 'qcastle') {
      this.board[m.to - 2] = this.board[m.to + 1];
      this.board[m.to + 1] = EMPTY;
    }

    this.castling = h.castling;
    this.ep       = h.ep;
    this.half     = h.half;
    this.full     = h.full;
  };

  /** Long algebraic ("e2e4", "e7e8q") for one move. */
  Position.prototype.uci = function (m) {
    return sqName(m.from) + sqName(m.to) +
           (m.flags === 'promo' ? PIECE_CHAR[m.promo] : '');
  };

  /** Find the legal move matching a uci string, or null. */
  Position.prototype.moveFromUci = function (uci) {
    var list = this.moves(), i;
    for (i = 0; i < list.length; i++) {
      if (this.uci(list[i]) === uci) return list[i];
    }
    return null;
  };

  /** Standard algebraic notation, for the move list. */
  Position.prototype.san = function (m) {
    if (m.flags === 'kcastle') return 'O-O';
    if (m.flags === 'qcastle') return 'O-O-O';

    var t = typeOf(m.piece), s = '';

    if (t === PAWN) {
      if (m.captured) s += 'abcdefgh'[m.from & 15] + 'x';
      s += sqName(m.to);
      if (m.flags === 'promo') s += '=' + PIECE_CHAR[m.promo].toUpperCase();
    } else {
      s += PIECE_CHAR[t].toUpperCase();

      // disambiguate when two identical pieces can reach the same square
      var others = this.moves().filter(function (o) {
        return o.to === m.to && o.from !== m.from && typeOf(o.piece) === t;
      });
      if (others.length) {
        var sameFile = others.some(function (o) { return (o.from & 15) === (m.from & 15); }),
            sameRank = others.some(function (o) { return (o.from >> 4) === (m.from >> 4); });
        if (!sameFile)      s += 'abcdefgh'[m.from & 15];
        else if (!sameRank) s += String(8 - (m.from >> 4));
        else                s += sqName(m.from);
      }

      if (m.captured) s += 'x';
      s += sqName(m.to);
    }

    this.make(m);
    if (this.inCheck()) s += this.moves().length ? '+' : '#';
    this.unmake();

    return s;
  };

  // ---------------- game state ----------------

  Position.prototype.insufficientMaterial = function () {
    var pieces = [], sq, p;
    for (sq = 0; sq < 128; sq++) {
      if (sq & 0x88) { sq += 7; continue; }
      p = this.board[sq];
      if (p && typeOf(p) !== KING) pieces.push({ t: typeOf(p), sq: sq, c: colorOf(p) });
    }
    if (!pieces.length) return true;                                  // K vs K
    if (pieces.length === 1 &&
        (pieces[0].t === BISHOP || pieces[0].t === KNIGHT)) return true;  // K+minor
    if (pieces.length === 2 && pieces[0].t === BISHOP && pieces[1].t === BISHOP) {
      // both bishops on the same colour squares
      var col = function (s) { return ((s >> 4) + (s & 15)) & 1; };
      if (col(pieces[0].sq) === col(pieces[1].sq)) return true;
    }
    return false;
  };

  Position.prototype.threefold = function () {
    return (this.repeats[this.key()] || 0) >= 3;
  };

  /**
   * Where the game stands.
   * -> { over, result: 'white'|'black'|'draw'|null, reason }
   */
  Position.prototype.status = function () {
    var legal = this.moves(),
        me    = this.turn,
        check = this.inCheck();

    if (!legal.length) {
      if (check) {
        return { over: true, check: true,
                 result: me === WHITE ? 'black' : 'white', reason: 'checkmate' };
      }
      return { over: true, check: false, result: 'draw', reason: 'stalemate' };
    }
    if (this.insufficientMaterial()) {
      return { over: true, check: check, result: 'draw', reason: 'insufficient material' };
    }
    if (this.half >= 100) {
      return { over: true, check: check, result: 'draw', reason: 'fifty-move rule' };
    }
    if (this.threefold()) {
      return { over: true, check: check, result: 'draw', reason: 'threefold repetition' };
    }
    return { over: false, check: check, result: null, reason: '' };
  };

  // ==================================================================
  //  EVALUATION + SEARCH
  // ==================================================================
  var VALUE = { 1: 100, 2: 320, 3: 330, 4: 500, 5: 900, 6: 20000 };

  // piece-square tables, from White's point of view, a8 first
  var PST = {
    1: [  0,  0,  0,  0,  0,  0,  0,  0,
         50, 50, 50, 50, 50, 50, 50, 50,
         10, 10, 20, 30, 30, 20, 10, 10,
          5,  5, 10, 25, 25, 10,  5,  5,
          0,  0,  0, 20, 20,  0,  0,  0,
          5, -5,-10,  0,  0,-10, -5,  5,
          5, 10, 10,-20,-20, 10, 10,  5,
          0,  0,  0,  0,  0,  0,  0,  0],
    2: [-50,-40,-30,-30,-30,-30,-40,-50,
        -40,-20,  0,  0,  0,  0,-20,-40,
        -30,  0, 10, 15, 15, 10,  0,-30,
        -30,  5, 15, 20, 20, 15,  5,-30,
        -30,  0, 15, 20, 20, 15,  0,-30,
        -30,  5, 10, 15, 15, 10,  5,-30,
        -40,-20,  0,  5,  5,  0,-20,-40,
        -50,-40,-30,-30,-30,-30,-40,-50],
    3: [-20,-10,-10,-10,-10,-10,-10,-20,
        -10,  0,  0,  0,  0,  0,  0,-10,
        -10,  0,  5, 10, 10,  5,  0,-10,
        -10,  5,  5, 10, 10,  5,  5,-10,
        -10,  0, 10, 10, 10, 10,  0,-10,
        -10, 10, 10, 10, 10, 10, 10,-10,
        -10,  5,  0,  0,  0,  0,  5,-10,
        -20,-10,-10,-10,-10,-10,-10,-20],
    4: [  0,  0,  0,  0,  0,  0,  0,  0,
          5, 10, 10, 10, 10, 10, 10,  5,
         -5,  0,  0,  0,  0,  0,  0, -5,
         -5,  0,  0,  0,  0,  0,  0, -5,
         -5,  0,  0,  0,  0,  0,  0, -5,
         -5,  0,  0,  0,  0,  0,  0, -5,
         -5,  0,  0,  0,  0,  0,  0, -5,
          0,  0,  0,  5,  5,  0,  0,  0],
    5: [-20,-10,-10, -5, -5,-10,-10,-20,
        -10,  0,  0,  0,  0,  0,  0,-10,
        -10,  0,  5,  5,  5,  5,  0,-10,
         -5,  0,  5,  5,  5,  5,  0, -5,
          0,  0,  5,  5,  5,  5,  0, -5,
        -10,  5,  5,  5,  5,  5,  0,-10,
        -10,  0,  5,  0,  0,  0,  0,-10,
        -20,-10,-10, -5, -5,-10,-10,-20],
    6: [-30,-40,-40,-50,-50,-40,-40,-30,
        -30,-40,-40,-50,-50,-40,-40,-30,
        -30,-40,-40,-50,-50,-40,-40,-30,
        -30,-40,-40,-50,-50,-40,-40,-30,
        -20,-30,-30,-40,-40,-30,-30,-20,
        -10,-20,-20,-20,-20,-20,-20,-10,
         20, 20,  0,  0,  0,  0, 20, 20,
         20, 30, 10,  0,  0, 10, 30, 20]
  };

  var KING_END = [
    -50,-40,-30,-20,-20,-30,-40,-50,
    -30,-20,-10,  0,  0,-10,-20,-30,
    -30,-10, 20, 30, 30, 20,-10,-30,
    -30,-10, 30, 40, 40, 30,-10,-30,
    -30,-10, 30, 40, 40, 30,-10,-30,
    -30,-10, 20, 30, 30, 20,-10,-30,
    -30,-30,  0,  0,  0,  0,-30,-30,
    -50,-30,-30,-30,-30,-30,-30,-50];

  function idx64(sq, color) {
    var r = sq >> 4, f = sq & 15;
    return color === WHITE ? (r * 8 + f) : ((7 - r) * 8 + f);
  }

  /** Score from White's point of view, in centipawns. */
  Position.prototype.evaluate = function () {
    var score = 0, sq, p, t, c, material = 0;

    for (sq = 0; sq < 128; sq++) {
      if (sq & 0x88) { sq += 7; continue; }
      p = this.board[sq];
      if (!p) continue;
      t = typeOf(p);
      if (t !== KING && t !== PAWN) material += VALUE[t];
    }
    var endgame = material < 1400;

    for (sq = 0; sq < 128; sq++) {
      if (sq & 0x88) { sq += 7; continue; }
      p = this.board[sq];
      if (!p) continue;
      t = typeOf(p);
      c = colorOf(p);

      var v = VALUE[t];
      var table = (t === KING && endgame) ? KING_END : PST[t];
      v += table[idx64(sq, c)];

      score += (c === WHITE) ? v : -v;
    }

    return score;
  };

  /**
   * Alpha-beta with a short capture-only extension at the leaves, so the
   * computer does not walk into a recapture it cannot see.
   */
  function search(pos, depth, alpha, beta, deadline) {
    if (Date.now() > deadline) return pos.evaluate() * (pos.turn === WHITE ? 1 : -1);

    if (depth <= 0) return quiesce(pos, alpha, beta, 4, deadline);

    var moves = pos.moves();

    if (!moves.length) {
      return pos.inCheck() ? (-30000 - depth) : 0;   // mate is worse the sooner it comes
    }

    orderMoves(pos, moves);

    var best = -Infinity, i, score;

    for (i = 0; i < moves.length; i++) {
      pos.make(moves[i]);
      score = -search(pos, depth - 1, -beta, -alpha, deadline);
      pos.unmake();

      if (score > best) best = score;
      if (best > alpha) alpha = best;
      if (alpha >= beta) break;                      // this branch is refuted
    }

    return best;
  }

  function quiesce(pos, alpha, beta, depth, deadline) {
    var stand = pos.evaluate() * (pos.turn === WHITE ? 1 : -1);

    if (depth <= 0 || Date.now() > deadline) return stand;
    if (stand >= beta) return beta;
    if (stand > alpha) alpha = stand;

    var caps = pos.moves(true);
    orderMoves(pos, caps);

    for (var i = 0; i < caps.length; i++) {
      pos.make(caps[i]);
      var score = -quiesce(pos, -beta, -alpha, depth - 1, deadline);
      pos.unmake();

      if (score >= beta) return beta;
      if (score > alpha) alpha = score;
    }
    return alpha;
  }

  /** Captures first, biggest victim by cheapest attacker — cheap but effective. */
  function orderMoves(pos, moves) {
    moves.forEach(function (m) {
      m.score = 0;
      if (m.captured) m.score = 10 * VALUE[typeOf(m.captured)] - VALUE[typeOf(m.piece)];
      if (m.flags === 'promo') m.score += VALUE[m.promo];
    });
    moves.sort(function (a, b) { return b.score - a.score; });
  }

  /**
   * Pick a move for the computer.
   *
   * `level` is 1-3. Level 1 searches shallowly and deliberately plays a
   * near-best move some of the time, so a beginner can actually win;
   * level 3 uses the full search and always plays what it thinks is best.
   */
  function bestMove(pos, level, msBudget) {
    var moves = pos.moves();
    if (!moves.length) return null;

    var depth    = level >= 3 ? 4 : level === 2 ? 3 : 2,
        deadline = Date.now() + (msBudget || (level >= 3 ? 2200 : level === 2 ? 900 : 350)),
        slack    = level === 1 ? 90 : level === 2 ? 35 : 0;

    orderMoves(pos, moves);

    var scored = [], i, s;

    for (i = 0; i < moves.length; i++) {
      pos.make(moves[i]);
      s = -search(pos, depth - 1, -Infinity, Infinity, deadline);
      pos.unmake();
      scored.push({ move: moves[i], score: s });
    }

    scored.sort(function (a, b) { return b.score - a.score; });

    // beginners get an opponent that sometimes takes the second-best line
    var top = scored[0].score,
        pool = scored.filter(function (x) { return x.score >= top - slack; });

    return pool[Math.floor(Math.random() * pool.length)].move;
  }

  // ==================================================================
  //  EXPORT
  // ==================================================================
  root.ChessEngine = {
    Position: Position,
    bestMove: bestMove,
    sqName: sqName,
    nameSq: nameSq,
    typeOf: typeOf,
    colorOf: colorOf,
    onBoard: onBoard,
    PIECE_CHAR: PIECE_CHAR,
    WHITE: WHITE, BLACK: BLACK,
    PAWN: PAWN, KNIGHT: KNIGHT, BISHOP: BISHOP, ROOK: ROOK, QUEEN: QUEEN, KING: KING,
    START_FEN: 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1'
  };

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = root.ChessEngine;
  }

})(typeof window !== 'undefined' ? window : globalThis);
