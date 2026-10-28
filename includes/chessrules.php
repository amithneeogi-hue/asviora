<?php
/**
 * Chess rules — server side.
 *
 * This is a PHP port of the same 0x88 move generator the browser runs, and
 * it exists for one reason: online games pay coins, so the server cannot
 * take the client's word for what is legal. Every move sent to
 * api/chess.php is regenerated here from the stored position and only
 * accepted if it appears in the legal move list.
 *
 * It knows castling, en passant, promotion, pins, checkmate, stalemate,
 * the fifty-move rule, insufficient material and threefold repetition.
 * It does not evaluate positions — the computer opponent lives entirely
 * in the browser, where it costs the server nothing.
 */

const CH_EMPTY = 0;
const CH_PAWN = 1, CH_KNIGHT = 2, CH_BISHOP = 3, CH_ROOK = 4, CH_QUEEN = 5, CH_KING = 6;
const CH_WHITE = 8, CH_BLACK = 16;
const CH_TYPE = 7, CH_COLOR = 24;

const CH_START_FEN = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';

function ch_piece_char(int $t): string
{
    return [1 => 'p', 2 => 'n', 3 => 'b', 4 => 'r', 5 => 'q', 6 => 'k'][$t] ?? '';
}
function ch_char_piece(string $c): int
{
    return ['p' => 1, 'n' => 2, 'b' => 3, 'r' => 4, 'q' => 5, 'k' => 6][strtolower($c)] ?? 0;
}
function ch_on(int $sq): bool { return ($sq & 0x88) === 0; }
function ch_type(int $p): int  { return $p & CH_TYPE; }
function ch_color(int $p): int { return $p & CH_COLOR; }

function ch_sq_name(int $sq): string
{
    return substr('abcdefgh', $sq & 15, 1) . (8 - ($sq >> 4));
}
function ch_name_sq(string $n): int
{
    $f = strpos('abcdefgh', substr($n, 0, 1));
    $r = 8 - (int) substr($n, 1, 1);
    return ($r << 4) + (int) $f;
}

function ch_dirs(string $which): array
{
    switch ($which) {
        case 'knight': return [-33, -31, -18, -14, 14, 18, 31, 33];
        case 'bishop': return [-17, -15, 15, 17];
        case 'rook':   return [-16, -1, 1, 16];
        default:       return [-17, -16, -15, -1, 1, 15, 16, 17];   // king
    }
}

/** FEN -> state array. Returns null when the FEN cannot be read. */
function ch_load(string $fen): ?array
{
    $parts = preg_split('/\s+/', trim($fen));
    if (count($parts) < 2) {
        return null;
    }

    $board = array_fill(0, 128, CH_EMPTY);
    $rows  = explode('/', $parts[0]);
    if (count($rows) !== 8) {
        return null;
    }

    foreach ($rows as $r => $row) {
        $sq = $r << 4;
        for ($i = 0, $n = strlen($row); $i < $n; $i++) {
            $ch = $row[$i];
            if (ctype_digit($ch)) {
                $sq += (int) $ch;
                continue;
            }
            $t = ch_char_piece($ch);
            if (!$t || !ch_on($sq)) {
                return null;
            }
            $board[$sq] = $t | (ctype_upper($ch) ? CH_WHITE : CH_BLACK);
            $sq++;
        }
    }

    $c = $parts[2] ?? '-';

    return [
        'board'    => $board,
        'turn'     => ($parts[1] === 'b') ? CH_BLACK : CH_WHITE,
        'castling' => [
            'K' => str_contains($c, 'K'), 'Q' => str_contains($c, 'Q'),
            'k' => str_contains($c, 'k'), 'q' => str_contains($c, 'q'),
        ],
        'ep'   => (isset($parts[3]) && $parts[3] !== '-') ? ch_name_sq($parts[3]) : -1,
        'half' => (int) ($parts[4] ?? 0),
        'full' => (int) ($parts[5] ?? 1),
    ];
}

/** state -> FEN */
function ch_fen(array $st): string
{
    $out = '';
    for ($r = 0; $r < 8; $r++) {
        $empty = 0;
        for ($f = 0; $f < 8; $f++) {
            $p = $st['board'][($r << 4) + $f];
            if ($p === CH_EMPTY) { $empty++; continue; }
            if ($empty) { $out .= $empty; $empty = 0; }
            $ch = ch_piece_char(ch_type($p));
            $out .= ch_color($p) === CH_WHITE ? strtoupper($ch) : $ch;
        }
        if ($empty) $out .= $empty;
        if ($r < 7) $out .= '/';
    }

    $c = ($st['castling']['K'] ? 'K' : '') . ($st['castling']['Q'] ? 'Q' : '')
       . ($st['castling']['k'] ? 'k' : '') . ($st['castling']['q'] ? 'q' : '');

    return $out . ' ' . ($st['turn'] === CH_WHITE ? 'w' : 'b')
         . ' ' . ($c !== '' ? $c : '-')
         . ' ' . ($st['ep'] >= 0 ? ch_sq_name($st['ep']) : '-')
         . ' ' . $st['half'] . ' ' . $st['full'];
}

/** The repetition key: everything but the two clocks. */
function ch_key(array $st): string
{
    return implode(' ', array_slice(explode(' ', ch_fen($st)), 0, 4));
}

function ch_king_square(array $st, int $color): int
{
    for ($sq = 0; $sq < 128; $sq++) {
        if ($sq & 0x88) { $sq += 7; continue; }
        $p = $st['board'][$sq];
        if ($p && ch_type($p) === CH_KING && ch_color($p) === $color) {
            return $sq;
        }
    }
    return -1;
}

/** Is $sq attacked by $by? */
function ch_attacked(array $st, int $sq, int $by): bool
{
    $b = $st['board'];

    // pawns
    $back = $by === CH_WHITE ? 16 : -16;
    foreach ([-1, 1] as $d) {
        $t = $sq + $back + $d;
        if (ch_on($t)) {
            $p = $b[$t];
            if ($p && ch_color($p) === $by && ch_type($p) === CH_PAWN) return true;
        }
    }

    // knights and king
    foreach ([[ch_dirs('knight'), CH_KNIGHT], [ch_dirs('king'), CH_KING]] as [$dirs, $want]) {
        foreach ($dirs as $d) {
            $t = $sq + $d;
            if (!ch_on($t)) continue;
            $p = $b[$t];
            if ($p && ch_color($p) === $by && ch_type($p) === $want) return true;
        }
    }

    // sliders
    foreach ([[ch_dirs('bishop'), CH_BISHOP], [ch_dirs('rook'), CH_ROOK]] as [$dirs, $want]) {
        foreach ($dirs as $d) {
            $t = $sq + $d;
            while (ch_on($t)) {
                $p = $b[$t];
                if ($p) {
                    if (ch_color($p) === $by
                        && (ch_type($p) === $want || ch_type($p) === CH_QUEEN)) {
                        return true;
                    }
                    break;
                }
                $t += $d;
            }
        }
    }

    return false;
}

function ch_in_check(array $st, ?int $color = null): bool
{
    $color = $color ?? $st['turn'];
    $k = ch_king_square($st, $color);
    return $k >= 0 && ch_attacked($st, $k, $color === CH_WHITE ? CH_BLACK : CH_WHITE);
}

/** Pseudo-legal moves. */
function ch_pseudo(array $st): array
{
    $out  = [];
    $b    = $st['board'];
    $me   = $st['turn'];
    $them = $me === CH_WHITE ? CH_BLACK : CH_WHITE;

    $add = static function (int $from, int $to, string $flags = '', int $promo = 0)
                    use (&$out, $b, $them) {
        $out[] = [
            'from'     => $from,
            'to'       => $to,
            'piece'    => $b[$from],
            'captured' => $flags === 'ep' ? (CH_PAWN | $them) : $b[$to],
            'flags'    => $flags,
            'promo'    => $promo,
        ];
    };

    for ($sq = 0; $sq < 128; $sq++) {
        if ($sq & 0x88) { $sq += 7; continue; }
        $p = $b[$sq];
        if (!$p || ch_color($p) !== $me) continue;

        $t = ch_type($p);

        if ($t === CH_PAWN) {
            $fwd   = $me === CH_WHITE ? -16 : 16;
            $start = $me === CH_WHITE ? 6 : 1;
            $last  = $me === CH_WHITE ? 0 : 7;

            $to = $sq + $fwd;
            if (ch_on($to) && !$b[$to]) {
                if (($to >> 4) === $last) {
                    foreach ([CH_QUEEN, CH_ROOK, CH_BISHOP, CH_KNIGHT] as $pr) {
                        $add($sq, $to, 'promo', $pr);
                    }
                } else {
                    $add($sq, $to);
                    $to2 = $sq + 2 * $fwd;
                    if (($sq >> 4) === $start && !$b[$to2]) {
                        $add($sq, $to2, 'big');
                    }
                }
            }
            foreach ([-1, 1] as $d) {
                $to = $sq + $fwd + $d;
                if (!ch_on($to)) continue;
                if ($b[$to] && ch_color($b[$to]) === $them) {
                    if (($to >> 4) === $last) {
                        foreach ([CH_QUEEN, CH_ROOK, CH_BISHOP, CH_KNIGHT] as $pr) {
                            $add($sq, $to, 'promo', $pr);
                        }
                    } else {
                        $add($sq, $to, 'cap');
                    }
                } elseif ($to === $st['ep']) {
                    $add($sq, $to, 'ep');
                }
            }
            continue;
        }

        if ($t === CH_KNIGHT || $t === CH_KING) {
            foreach (ch_dirs($t === CH_KNIGHT ? 'knight' : 'king') as $d) {
                $to = $sq + $d;
                if (!ch_on($to)) continue;
                if ($b[$to] && ch_color($b[$to]) === $me) continue;
                $add($sq, $to, $b[$to] ? 'cap' : '');
            }
            continue;
        }

        $slides = $t === CH_BISHOP ? ch_dirs('bishop')
                : ($t === CH_ROOK  ? ch_dirs('rook')
                                   : array_merge(ch_dirs('bishop'), ch_dirs('rook')));
        foreach ($slides as $d) {
            $to = $sq + $d;
            while (ch_on($to)) {
                if (!$b[$to]) {
                    $add($sq, $to);
                } else {
                    if (ch_color($b[$to]) === $them) $add($sq, $to, 'cap');
                    break;
                }
                $to += $d;
            }
        }
    }

    // castling
    $kSq    = $me === CH_WHITE ? ch_name_sq('e1') : ch_name_sq('e8');
    $rights = $me === CH_WHITE
            ? [$st['castling']['K'], $st['castling']['Q']]
            : [$st['castling']['k'], $st['castling']['q']];

    if ($b[$kSq] === (CH_KING | $me) && !ch_attacked($st, $kSq, $them)) {
        if ($rights[0] && !$b[$kSq + 1] && !$b[$kSq + 2] && $b[$kSq + 3] === (CH_ROOK | $me)
            && !ch_attacked($st, $kSq + 1, $them) && !ch_attacked($st, $kSq + 2, $them)) {
            $add($kSq, $kSq + 2, 'kcastle');
        }
        if ($rights[1] && !$b[$kSq - 1] && !$b[$kSq - 2] && !$b[$kSq - 3]
            && $b[$kSq - 4] === (CH_ROOK | $me)
            && !ch_attacked($st, $kSq - 1, $them) && !ch_attacked($st, $kSq - 2, $them)) {
            $add($kSq, $kSq - 2, 'qcastle');
        }
    }

    return $out;
}

/** Apply a move to a state, returning the new state (no legality check). */
function ch_make(array $st, array $m): array
{
    $me   = $st['turn'];
    $them = $me === CH_WHITE ? CH_BLACK : CH_WHITE;
    $b    = $st['board'];

    $b[$m['to']]   = $b[$m['from']];
    $b[$m['from']] = CH_EMPTY;

    if ($m['flags'] === 'promo') {
        $b[$m['to']] = $m['promo'] | $me;
    }
    if ($m['flags'] === 'ep') {
        $b[$m['to'] + ($me === CH_WHITE ? 16 : -16)] = CH_EMPTY;
    }
    if ($m['flags'] === 'kcastle') {
        $b[$m['to'] - 1] = $b[$m['to'] + 1];
        $b[$m['to'] + 1] = CH_EMPTY;
    }
    if ($m['flags'] === 'qcastle') {
        $b[$m['to'] + 1] = $b[$m['to'] - 2];
        $b[$m['to'] - 2] = CH_EMPTY;
    }

    $cast = $st['castling'];
    if (ch_type($m['piece']) === CH_KING) {
        if ($me === CH_WHITE) { $cast['K'] = $cast['Q'] = false; }
        else                  { $cast['k'] = $cast['q'] = false; }
    }
    foreach ([['h1', 'K'], ['a1', 'Q'], ['h8', 'k'], ['a8', 'q']] as [$name, $flag]) {
        $s = ch_name_sq($name);
        if ($m['from'] === $s || $m['to'] === $s) {
            $cast[$flag] = false;
        }
    }

    return [
        'board'    => $b,
        'turn'     => $them,
        'castling' => $cast,
        'ep'       => $m['flags'] === 'big'
                      ? $m['from'] + ($me === CH_WHITE ? -16 : 16) : -1,
        'half'     => (ch_type($m['piece']) === CH_PAWN || $m['captured'])
                      ? 0 : $st['half'] + 1,
        'full'     => $st['full'] + ($me === CH_BLACK ? 1 : 0),
    ];
}

/** Fully legal moves. */
function ch_moves(array $st): array
{
    $legal = [];
    $me    = $st['turn'];

    foreach (ch_pseudo($st) as $m) {
        $next = ch_make($st, $m);
        if (!ch_in_check($next, $me)) {
            $legal[] = $m;
        }
    }
    return $legal;
}

/** Long algebraic for a move: e2e4, e7e8q. */
function ch_uci(array $m): string
{
    return ch_sq_name($m['from']) . ch_sq_name($m['to'])
         . ($m['flags'] === 'promo' ? ch_piece_char($m['promo']) : '');
}

/**
 * Play one move given as a uci string.
 * Returns the new state, or null when the move is not legal here.
 */
function ch_play(array $st, string $uci): ?array
{
    $uci = strtolower(trim($uci));
    if (!preg_match('/^[a-h][1-8][a-h][1-8][qrbn]?$/', $uci)) {
        return null;
    }
    foreach (ch_moves($st) as $m) {
        if (ch_uci($m) === $uci) {
            return ch_make($st, $m);
        }
    }
    return null;
}

function ch_insufficient(array $st): bool
{
    $pieces = [];
    for ($sq = 0; $sq < 128; $sq++) {
        if ($sq & 0x88) { $sq += 7; continue; }
        $p = $st['board'][$sq];
        if ($p && ch_type($p) !== CH_KING) {
            $pieces[] = ['t' => ch_type($p), 'sq' => $sq];
        }
    }
    if (!$pieces) return true;
    if (count($pieces) === 1
        && in_array($pieces[0]['t'], [CH_BISHOP, CH_KNIGHT], true)) return true;
    if (count($pieces) === 2
        && $pieces[0]['t'] === CH_BISHOP && $pieces[1]['t'] === CH_BISHOP) {
        $col = static fn(int $s): int => (($s >> 4) + ($s & 15)) & 1;
        if ($col($pieces[0]['sq']) === $col($pieces[1]['sq'])) return true;
    }
    return false;
}

/**
 * Where the game stands.
 * $keys is the list of position keys already reached, for threefold.
 */
function ch_status(array $st, array $keys = []): array
{
    $legal = ch_moves($st);
    $check = ch_in_check($st);

    if (!$legal) {
        if ($check) {
            return ['over' => true, 'check' => true,
                    'result' => $st['turn'] === CH_WHITE ? 'black' : 'white',
                    'reason' => 'checkmate'];
        }
        return ['over' => true, 'check' => false, 'result' => 'draw', 'reason' => 'stalemate'];
    }
    if (ch_insufficient($st)) {
        return ['over' => true, 'check' => $check, 'result' => 'draw',
                'reason' => 'insufficient material'];
    }
    if ($st['half'] >= 100) {
        return ['over' => true, 'check' => $check, 'result' => 'draw',
                'reason' => 'fifty-move rule'];
    }
    if ($keys) {
        $k = ch_key($st);
        $n = 0;
        foreach ($keys as $key) {
            if ($key === $k) $n++;
        }
        if ($n >= 3) {
            return ['over' => true, 'check' => $check, 'result' => 'draw',
                    'reason' => 'threefold repetition'];
        }
    }

    return ['over' => false, 'check' => $check, 'result' => null, 'reason' => ''];
}

/**
 * Replay a whole move list from the start, returning
 * ['state' => ..., 'keys' => [...]] — used to rebuild a match's position
 * key history so threefold can be judged.
 */
function ch_replay(string $moves): array
{
    $st   = ch_load(CH_START_FEN);
    $keys = [ch_key($st)];

    foreach (preg_split('/\s+/', trim($moves)) ?: [] as $uci) {
        if ($uci === '') continue;
        $next = ch_play($st, $uci);
        if ($next === null) break;
        $st     = $next;
        $keys[] = ch_key($st);
    }

    return ['state' => $st, 'keys' => $keys];
}
