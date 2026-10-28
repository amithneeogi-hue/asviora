<?php
/**
 * CHESS — server-side rules engine + match helpers.
 * ===================================================================
 * Coins are on the table in a staked match, so the browser is not trusted
 * with anything that matters. Every move a player sends is re-generated
 * here from the stored position and rejected if it is not legal; the clocks
 * are measured from the server's own timestamps; the payout is decided by
 * this file and nothing else.
 *
 * Board layout: a plain 64-entry array, index 0 = a8 ... 63 = h1.
 * Pieces: KQRBNP = white, kqrbnp = black, '' = empty square.
 */

const CHESS_START_FEN = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';

/** Square name ("e4") -> index, or -1. */
function chess_sq(string $name): int
{
    if (!preg_match('/^[a-h][1-8]$/', $name)) {
        return -1;
    }
    $f = ord($name[0]) - 97;
    $r = 8 - (int) $name[1];
    return $r * 8 + $f;
}

/** Index -> square name. */
function chess_name(int $i): string
{
    return chr(97 + ($i % 8)) . (8 - intdiv($i, 8));
}

/** Parse a FEN into the working position array. */
function chess_parse(string $fen): array
{
    $parts = preg_split('/\s+/', trim($fen));
    $board = array_fill(0, 64, '');
    $i     = 0;

    foreach (str_split((string) ($parts[0] ?? '')) as $c) {
        if ($c === '/') {
            continue;
        }
        if (ctype_digit($c)) {
            $i += (int) $c;
        } elseif ($i < 64) {
            $board[$i++] = $c;
        }
    }

    return [
        'board'  => $board,
        'turn'   => ($parts[1] ?? 'w') === 'b' ? 'b' : 'w',
        'castle' => ($parts[2] ?? '-') === '-' ? '' : (string) $parts[2],
        'ep'     => isset($parts[3]) && $parts[3] !== '-' ? chess_sq($parts[3]) : -1,
        'half'   => (int) ($parts[4] ?? 0),
        'full'   => max(1, (int) ($parts[5] ?? 1)),
    ];
}

/** Serialise a position back to FEN. */
function chess_fen(array $p): string
{
    $rows = [];
    for ($r = 0; $r < 8; $r++) {
        $row   = '';
        $empty = 0;
        for ($f = 0; $f < 8; $f++) {
            $c = $p['board'][$r * 8 + $f];
            if ($c === '') {
                $empty++;
                continue;
            }
            if ($empty) {
                $row .= $empty;
                $empty = 0;
            }
            $row .= $c;
        }
        if ($empty) {
            $row .= $empty;
        }
        $rows[] = $row;
    }

    return implode('/', $rows) . ' ' . $p['turn'] . ' '
         . ($p['castle'] !== '' ? $p['castle'] : '-') . ' '
         . ($p['ep'] >= 0 ? chess_name($p['ep']) : '-') . ' '
         . (int) $p['half'] . ' ' . (int) $p['full'];
}

/** Colour of the piece on a square: 'w', 'b' or ''. */
function chess_colour(string $piece): string
{
    if ($piece === '') {
        return '';
    }
    return ctype_upper($piece) ? 'w' : 'b';
}

/** Is square $i attacked by side $by in this board? */
function chess_attacked(array $b, int $i, string $by): bool
{
    $r = intdiv($i, 8);
    $f = $i % 8;

    // pawns
    $dir  = $by === 'w' ? 1 : -1;          // white pawns come from below
    $pawn = $by === 'w' ? 'P' : 'p';
    foreach ([-1, 1] as $df) {
        $rr = $r + $dir;
        $ff = $f + $df;
        if ($rr >= 0 && $rr < 8 && $ff >= 0 && $ff < 8 && $b[$rr * 8 + $ff] === $pawn) {
            return true;
        }
    }

    // knights
    $kn = $by === 'w' ? 'N' : 'n';
    foreach ([[1,2],[2,1],[-1,2],[-2,1],[1,-2],[2,-1],[-1,-2],[-2,-1]] as $d) {
        $rr = $r + $d[0];
        $ff = $f + $d[1];
        if ($rr >= 0 && $rr < 8 && $ff >= 0 && $ff < 8 && $b[$rr * 8 + $ff] === $kn) {
            return true;
        }
    }

    // king
    $kg = $by === 'w' ? 'K' : 'k';
    for ($dr = -1; $dr <= 1; $dr++) {
        for ($df = -1; $df <= 1; $df++) {
            if (!$dr && !$df) {
                continue;
            }
            $rr = $r + $dr;
            $ff = $f + $df;
            if ($rr >= 0 && $rr < 8 && $ff >= 0 && $ff < 8 && $b[$rr * 8 + $ff] === $kg) {
                return true;
            }
        }
    }

    // sliders
    $straight = $by === 'w' ? ['R', 'Q'] : ['r', 'q'];
    $diagonal = $by === 'w' ? ['B', 'Q'] : ['b', 'q'];
    foreach ([[-1,0],[1,0],[0,-1],[0,1]] as $d) {
        $rr = $r + $d[0];
        $ff = $f + $d[1];
        while ($rr >= 0 && $rr < 8 && $ff >= 0 && $ff < 8) {
            $c = $b[$rr * 8 + $ff];
            if ($c !== '') {
                if (in_array($c, $straight, true)) {
                    return true;
                }
                break;
            }
            $rr += $d[0];
            $ff += $d[1];
        }
    }
    foreach ([[-1,-1],[-1,1],[1,-1],[1,1]] as $d) {
        $rr = $r + $d[0];
        $ff = $f + $d[1];
        while ($rr >= 0 && $rr < 8 && $ff >= 0 && $ff < 8) {
            $c = $b[$rr * 8 + $ff];
            if ($c !== '') {
                if (in_array($c, $diagonal, true)) {
                    return true;
                }
                break;
            }
            $rr += $d[0];
            $ff += $d[1];
        }
    }

    return false;
}

/** Where is $side's king? -1 if it is not on the board. */
function chess_king(array $b, string $side): int
{
    $k = $side === 'w' ? 'K' : 'k';
    foreach ($b as $i => $c) {
        if ($c === $k) {
            return $i;
        }
    }
    return -1;
}

/** Is $side to move in check? */
function chess_in_check(array $p, string $side): bool
{
    $k = chess_king($p['board'], $side);
    return $k >= 0 && chess_attacked($p['board'], $k, $side === 'w' ? 'b' : 'w');
}

/**
 * Every legal move for the side to move.
 * @return array<int, array{from:int,to:int,promo:string,flag:string}>
 */
function chess_moves(array $p): array
{
    $b     = $p['board'];
    $me    = $p['turn'];
    $them  = $me === 'w' ? 'b' : 'w';
    $out   = [];

    $push = static function (array &$out, int $from, int $to, string $promo = '', string $flag = '') {
        $out[] = ['from' => $from, 'to' => $to, 'promo' => $promo, 'flag' => $flag];
    };

    for ($i = 0; $i < 64; $i++) {
        $pc = $b[$i];
        if ($pc === '' || chess_colour($pc) !== $me) {
            continue;
        }
        $r    = intdiv($i, 8);
        $f    = $i % 8;
        $type = strtolower($pc);

        if ($type === 'p') {
            $dir   = $me === 'w' ? -1 : 1;
            $start = $me === 'w' ? 6 : 1;
            $last  = $me === 'w' ? 0 : 7;

            $r1 = $r + $dir;
            if ($r1 >= 0 && $r1 < 8 && $b[$r1 * 8 + $f] === '') {
                if ($r1 === $last) {
                    foreach (['q', 'r', 'b', 'n'] as $pr) {
                        $push($out, $i, $r1 * 8 + $f, $pr);
                    }
                } else {
                    $push($out, $i, $r1 * 8 + $f);
                    $r2 = $r + 2 * $dir;
                    if ($r === $start && $b[$r2 * 8 + $f] === '') {
                        $push($out, $i, $r2 * 8 + $f, '', 'double');
                    }
                }
            }
            foreach ([-1, 1] as $df) {
                $ff = $f + $df;
                if ($ff < 0 || $ff > 7 || $r1 < 0 || $r1 > 7) {
                    continue;
                }
                $t  = $r1 * 8 + $ff;
                $tc = $b[$t];
                if ($tc !== '' && chess_colour($tc) === $them) {
                    if ($r1 === $last) {
                        foreach (['q', 'r', 'b', 'n'] as $pr) {
                            $push($out, $i, $t, $pr);
                        }
                    } else {
                        $push($out, $i, $t);
                    }
                } elseif ($tc === '' && $t === $p['ep']) {
                    $push($out, $i, $t, '', 'ep');
                }
            }
            continue;
        }

        if ($type === 'n' || $type === 'k') {
            $steps = $type === 'n'
                ? [[1,2],[2,1],[-1,2],[-2,1],[1,-2],[2,-1],[-1,-2],[-2,-1]]
                : [[1,0],[-1,0],[0,1],[0,-1],[1,1],[1,-1],[-1,1],[-1,-1]];
            foreach ($steps as $d) {
                $rr = $r + $d[0];
                $ff = $f + $d[1];
                if ($rr < 0 || $rr > 7 || $ff < 0 || $ff > 7) {
                    continue;
                }
                $t = $rr * 8 + $ff;
                if ($b[$t] === '' || chess_colour($b[$t]) === $them) {
                    $push($out, $i, $t);
                }
            }

            if ($type === 'k') {
                $rights = $p['castle'];
                $homeR  = $me === 'w' ? 7 : 0;
                if ($i === $homeR * 8 + 4 && !chess_attacked($b, $i, $them)) {
                    $kSide = $me === 'w' ? 'K' : 'k';
                    $qSide = $me === 'w' ? 'Q' : 'q';
                    if (strpos($rights, $kSide) !== false
                        && $b[$homeR * 8 + 5] === '' && $b[$homeR * 8 + 6] === ''
                        && !chess_attacked($b, $homeR * 8 + 5, $them)
                        && !chess_attacked($b, $homeR * 8 + 6, $them)) {
                        $push($out, $i, $homeR * 8 + 6, '', 'castle');
                    }
                    if (strpos($rights, $qSide) !== false
                        && $b[$homeR * 8 + 3] === '' && $b[$homeR * 8 + 2] === ''
                        && $b[$homeR * 8 + 1] === ''
                        && !chess_attacked($b, $homeR * 8 + 3, $them)
                        && !chess_attacked($b, $homeR * 8 + 2, $them)) {
                        $push($out, $i, $homeR * 8 + 2, '', 'castle');
                    }
                }
            }
            continue;
        }

        $dirs = [];
        if ($type === 'r' || $type === 'q') {
            $dirs = array_merge($dirs, [[-1,0],[1,0],[0,-1],[0,1]]);
        }
        if ($type === 'b' || $type === 'q') {
            $dirs = array_merge($dirs, [[-1,-1],[-1,1],[1,-1],[1,1]]);
        }
        foreach ($dirs as $d) {
            $rr = $r + $d[0];
            $ff = $f + $d[1];
            while ($rr >= 0 && $rr < 8 && $ff >= 0 && $ff < 8) {
                $t = $rr * 8 + $ff;
                if ($b[$t] === '') {
                    $push($out, $i, $t);
                } else {
                    if (chess_colour($b[$t]) === $them) {
                        $push($out, $i, $t);
                    }
                    break;
                }
                $rr += $d[0];
                $ff += $d[1];
            }
        }
    }

    // drop anything that leaves our own king in check
    $legal = [];
    foreach ($out as $m) {
        $after = chess_make($p, $m);
        if (!chess_in_check($after, $me)) {
            $legal[] = $m;
        }
    }
    return $legal;
}

/** Apply a move blindly (no legality check) and return the new position. */
function chess_make(array $p, array $m): array
{
    $b    = $p['board'];
    $from = $m['from'];
    $to   = $m['to'];
    $pc   = $b[$from];
    $me   = chess_colour($pc);
    $type = strtolower($pc);
    $cap  = $b[$to] !== '' || ($type === 'p' && $to === $p['ep']);

    $b[$to]   = $pc;
    $b[$from] = '';

    // en passant capture removes the pawn beside the target square
    if ($type === 'p' && $m['flag'] === 'ep') {
        $capIdx = $to + ($me === 'w' ? 8 : -8);
        $b[$capIdx] = '';
    }

    // promotion
    if ($type === 'p' && ($m['promo'] ?? '') !== '') {
        $b[$to] = $me === 'w' ? strtoupper($m['promo']) : strtolower($m['promo']);
    }

    // rook hop when castling
    if ($type === 'k' && abs(($to % 8) - ($from % 8)) === 2) {
        $row = intdiv($to, 8);
        if ($to % 8 === 6) {                    // king side
            $b[$row * 8 + 5] = $b[$row * 8 + 7];
            $b[$row * 8 + 7] = '';
        } else {                                // queen side
            $b[$row * 8 + 3] = $b[$row * 8 + 0];
            $b[$row * 8 + 0] = '';
        }
    }

    // castling rights
    $castle = $p['castle'];
    if ($type === 'k') {
        $castle = str_replace($me === 'w' ? ['K', 'Q'] : ['k', 'q'], '', $castle);
    }
    foreach ([[63, 'K'], [56, 'Q'], [7, 'k'], [0, 'q']] as $rk) {
        if ($from === $rk[0] || $to === $rk[0]) {
            $castle = str_replace($rk[1], '', $castle);
        }
    }

    // en passant square
    $ep = -1;
    if ($type === 'p' && abs(intdiv($to, 8) - intdiv($from, 8)) === 2) {
        $ep = ($from + $to) / 2;
    }

    return [
        'board'  => $b,
        'turn'   => $me === 'w' ? 'b' : 'w',
        'castle' => $castle,
        'ep'     => (int) $ep,
        'half'   => ($type === 'p' || $cap) ? 0 : $p['half'] + 1,
        'full'   => $p['full'] + ($me === 'b' ? 1 : 0),
    ];
}

/** Find the legal move matching a UCI string like "e2e4" or "e7e8q". */
function chess_find_move(array $p, string $uci): ?array
{
    $uci = strtolower(trim($uci));
    if (!preg_match('/^([a-h][1-8])([a-h][1-8])([qrbn]?)$/', $uci, $mm)) {
        return null;
    }
    $from  = chess_sq($mm[1]);
    $to    = chess_sq($mm[2]);
    $promo = $mm[3];

    foreach (chess_moves($p) as $m) {
        if ($m['from'] === $from && $m['to'] === $to
            && ($m['promo'] === $promo || ($m['promo'] === '' && $promo === ''))) {
            return $m;
        }
    }
    return null;
}

/** Not enough material left for anyone to mate. */
function chess_dead_position(array $p): bool
{
    $men = [];
    foreach ($p['board'] as $c) {
        if ($c !== '' && strtolower($c) !== 'k') {
            $men[] = strtolower($c);
        }
    }
    if (!$men) {
        return true;                                  // K v K
    }
    if (count($men) === 1 && in_array($men[0], ['b', 'n'], true)) {
        return true;                                  // K+B or K+N v K
    }
    return false;
}

/**
 * State of the position for the side to move.
 * @return array{state:string, winner:string}
 *         state: play | check | checkmate | stalemate | draw
 */
function chess_state(array $p): array
{
    $moves = chess_moves($p);
    $check = chess_in_check($p, $p['turn']);

    if (!$moves) {
        return $check
            ? ['state' => 'checkmate', 'winner' => $p['turn'] === 'w' ? 'black' : 'white']
            : ['state' => 'stalemate', 'winner' => 'draw'];
    }
    if ($p['half'] >= 100) {
        return ['state' => 'draw', 'winner' => 'draw'];
    }
    if (chess_dead_position($p)) {
        return ['state' => 'draw', 'winner' => 'draw'];
    }
    return ['state' => $check ? 'check' : 'play', 'winner' => ''];
}

// ===================================================================
// Match helpers
// ===================================================================

/** Is chess switched on at all? */
function chess_on(): bool
{
    return setting('chess_enabled', '1') === '1' && has_table('chess_matches');
}

function chess_ai_on(): bool
{
    return chess_on() && setting('chess_ai_enabled', '1') === '1';
}

function chess_pvp_on(): bool
{
    return chess_on() && setting('chess_pvp_enabled', '1') === '1';
}

function chess_min_stake(): int
{
    return max(0, (int) setting('chess_min_stake', '0'));
}

function chess_max_stake(): int
{
    return max(0, (int) setting('chess_max_stake', '5000'));
}

/** The time controls a player may choose, in minutes. */
function chess_time_options(): array
{
    return [3, 5, 10, 15, 30];
}

/** A short, unguessable invite code. */
function chess_new_code(): string
{
    do {
        $code = strtoupper(bin2hex(random_bytes(3)));       // 6 chars
    } while (fetch_one('SELECT id FROM chess_matches WHERE code = ?', [$code]));
    return $code;
}

/** Load a match by its invite code. */
function chess_match(string $code): ?array
{
    return fetch_one('SELECT * FROM chess_matches WHERE code = ?', [strtoupper(trim($code))]);
}

/** Both players of a match, keyed by user id. */
function chess_players(array $m): array
{
    $ids = array_values(array_filter([(int) $m['host_id'], (int) $m['guest_id']]));
    if (!$ids) {
        return [];
    }
    $in  = implode(',', array_map('intval', $ids));
    $out = [];
    foreach (fetch_all("SELECT id, name, username, avatar, coins FROM users WHERE id IN ($in)") as $u) {
        $out[(int) $u['id']] = $u;
    }
    return $out;
}

/**
 * Settle a finished match: pay the pot out and stamp the result.
 * Safe to call twice — a match that is already finished is left alone.
 *
 * @param string $result white | black | draw | abort
 */
function chess_settle(array $m, string $result, string $reason = ''): array
{
    if ($m['status'] === 'finished') {
        return $m;
    }

    $pot      = (int) $m['pot'];
    $stake    = (int) $m['stake'];
    $whiteId  = (int) $m['white_id'];
    $blackId  = (int) $m['black_id'];
    $winnerId = null;

    if ($result === 'white') {
        $winnerId = $whiteId;
    } elseif ($result === 'black') {
        $winnerId = $blackId;
    }

    if ($m['mode'] === 'pvp' && $pot > 0) {
        if ($result === 'draw' || $result === 'abort') {
            // stake back to whoever put one in
            foreach ([$whiteId, $blackId] as $uid) {
                if ($uid && $stake > 0) {
                    add_coins($uid, $stake, 'bonus', 'Chess ' . $m['code'] . ' — stake returned');
                }
            }
        } elseif ($winnerId) {
            $cut   = min(50, max(0, (int) setting('chess_house_cut', '0')));
            $prize = $pot - (int) floor($pot * $cut / 100);
            add_coins($winnerId, $prize, 'bonus',
                      'Chess ' . $m['code'] . ' — won the pot' . ($cut ? " (after {$cut}% fee)" : ''));
        }
    }

    q('UPDATE chess_matches
          SET status = "finished", result = ?, reason = ?, winner_id = ?, ended_at = ?
        WHERE id = ? AND status <> "finished"',
      [$result, $reason !== '' ? mb_substr($reason, 0, 40) : null, $winnerId,
       date('Y-m-d H:i:s'), (int) $m['id']]);

    return fetch_one('SELECT * FROM chess_matches WHERE id = ?', [(int) $m['id']]) ?: $m;
}
