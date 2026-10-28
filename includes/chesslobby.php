<?php
/**
 * Chess lobby + match logic.
 *
 * The rules live in chessrules.php; this file is everything around them —
 * finding an opponent, seating players, applying a validated move,
 * resigning, offering a draw, timing out an abandoned board and paying
 * the winner.
 *
 * It is shared by the browser endpoint (api/chess.php) and the mobile API
 * (api/v1), so the two can never drift apart: one referee, two doors.
 */
require_once __DIR__ . '/chessrules.php';

/** Thrown for anything the caller did wrong; carries an HTTP status. */
class ChessError extends RuntimeException
{
    public string $errorCode;
    public array  $extra;

    public function __construct(string $code, string $message, int $http = 400, array $extra = [])
    {
        parent::__construct($message, $http);
        $this->errorCode = $code;
        $this->extra     = $extra;
    }
}

/** A short, unambiguous share code — no O/0 or I/1 confusion. */
function chess_code(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
    } while (fetch_col('SELECT id FROM chess_games WHERE code = ?', [$code], null) !== null);

    return $code;
}

/**
 * One match row by its numeric id.
 *
 * Renamed from chess_match(): includes/chess.php — which config.php loads on
 * every request — already defines chess_match(string $code) for the share-code
 * lookup. Both files are loaded together by api/chess.php and api/v1, so the
 * shared name was a fatal "Cannot redeclare" on those endpoints.
 */
function chess_match_by_id(int $id): ?array
{
    return fetch_one('SELECT * FROM chess_games WHERE id = ?', [$id]);
}

/** 'w', 'b', or '' when this player is not in the match. */
function chess_side(array $m, int $uid): string
{
    if ((int) $m['white_id'] === $uid) return 'w';
    if ((int) $m['black_id'] === $uid) return 'b';
    return '';
}

/** What one player is allowed to see of a match. */
function chess_view(array $m, int $uid): array
{
    $side  = chess_side($m, $uid);
    $names = [];

    foreach (['white_id', 'black_id'] as $f) {
        $id = (int) $m[$f];
        $names[$f] = $id
            ? fetch_one('SELECT id, username, name, avatar, level FROM users WHERE id = ?', [$id])
            : null;
    }

    $timeout = max(30, (int) setting('chess_move_timeout', '600'));
    $idle    = $m['last_move_at'] ? max(0, time() - strtotime($m['last_move_at'])) : 0;

    return [
        'id'          => (int) $m['id'],
        'code'        => $m['code'],
        'mode'        => $m['mode'],
        'fen'         => $m['fen'],
        'moves'       => trim((string) $m['moves']),
        'ply'         => (int) $m['ply'],
        'turn'        => $m['turn'],
        'status'      => $m['status'],
        'result'      => $m['result'],
        'reason'      => $m['reason'],
        'draw_offer'  => $m['draw_offer'],
        'you'         => $side,
        'your_turn'   => $side !== '' && $side === $m['turn'] && $m['status'] === 'active',
        'white'       => $names['white_id'],
        'black'       => $names['black_id'],
        'opponent'    => $side === 'w' ? $names['black_id']
                       : ($side === 'b' ? $names['white_id'] : null),
        'idle'        => $idle,
        'timeout'     => $timeout,
        'time_left'   => max(0, $timeout - $idle),
        'reward_win'  => (int) setting('chess_reward_win', '80'),
        'reward_draw' => (int) setting('chess_reward_draw', '30'),
    ];
}

/**
 * Close a match: store the result, pay the coins, and write a `scores` row
 * so online chess appears on the leaderboard like any other game.
 */
function chess_finish(array $m, string $result, string $reason): array
{
    if ($m['status'] === 'finished') {
        return chess_match_by_id((int) $m['id']) ?? $m;
    }

    $n = q('UPDATE chess_games SET status = "finished", result = ?, reason = ?
             WHERE id = ? AND status <> "finished"',
           [$result, mb_substr($reason, 0, 40), (int) $m['id']])->rowCount();

    if ($n !== 1) {
        // somebody else finished it a moment ago — do not pay twice
        return chess_match_by_id((int) $m['id']) ?? $m;
    }

    $winCoins  = (int) setting('chess_reward_win', '80');
    $drawCoins = (int) setting('chess_reward_draw', '30');
    $gameId    = (int) fetch_col('SELECT id FROM games WHERE slug = "chess"', [], 0);
    $ply       = (int) $m['ply'];

    $pay = static function (?int $playerId, string $outcome, int $coins)
                   use ($gameId, $ply, $m): void {
        if (!$playerId) {
            return;
        }
        if ($coins > 0) {
            add_coins($playerId, $coins, 'reward',
                      'Online chess — ' . $outcome . ' (' . $m['code'] . ')');
        }
        add_xp($playerId, $outcome === 'win' ? 30 : ($outcome === 'draw' ? 12 : 4));

        if ($gameId) {
            insert_row('scores', [
                'user_id'      => $playerId,
                'game_id'      => $gameId,
                'score'        => $outcome === 'win' ? 1000 : ($outcome === 'draw' ? 500 : 100),
                'moves'        => $ply,
                'difficulty'   => 'online',
                'coins_earned' => $coins,
                'result'       => $outcome === 'loss' ? 'loss' : 'win',
                'meta'         => 'Online chess ' . $m['code'],
                'ip_address'   => client_ip(),
            ]);
        }
    };

    $w = $m['white_id'] ? (int) $m['white_id'] : null;
    $b = $m['black_id'] ? (int) $m['black_id'] : null;

    if ($result === 'white')     { $pay($w, 'win', $winCoins);   $pay($b, 'loss', 0); }
    elseif ($result === 'black') { $pay($b, 'win', $winCoins);   $pay($w, 'loss', 0); }
    elseif ($result === 'draw')  { $pay($w, 'draw', $drawCoins); $pay($b, 'draw', $drawCoins); }

    return chess_match_by_id((int) $m['id']) ?? $m;
}

/**
 * A player who walks away loses on time. Checked lazily whenever anyone
 * looks at the match, so there is no scheduled job to keep alive.
 */
function chess_check_timeout(array $m): array
{
    if ($m['status'] !== 'active' || !$m['last_move_at']) {
        return $m;
    }
    $timeout = max(30, (int) setting('chess_move_timeout', '600'));
    if (time() - strtotime($m['last_move_at']) <= $timeout) {
        return $m;
    }
    return chess_finish($m, $m['turn'] === 'w' ? 'black' : 'white', 'timeout');
}

/**
 * Handle one lobby/match action.
 *
 * Returns the payload to send back. Throws ChessError for anything the
 * caller got wrong, so both front doors can turn that into their own
 * error shape.
 */
function chess_handle(string $action, int $uid, array $body): array
{
    switch ($action) {

    // ---- the match this player is already in ---------------------------
    case 'current': {
        $m = fetch_one(
            'SELECT * FROM chess_games
              WHERE (white_id = ? OR black_id = ? OR host_id = ?)
                AND status IN ("waiting","active")
              ORDER BY id DESC LIMIT 1', [$uid, $uid, $uid]);

        if (!$m) {
            return ['match' => null];
        }
        return ['match' => chess_view(chess_check_timeout($m), $uid)];
    }

    // ---- quick play ----------------------------------------------------
    case 'find': {
        $open = fetch_one(
            'SELECT * FROM chess_games
              WHERE status = "waiting" AND mode = "public"
                AND host_id <> ? AND (white_id IS NULL OR black_id IS NULL)
              ORDER BY id ASC LIMIT 1', [$uid]);

        if ($open) {
            $seat = $open['white_id'] === null ? 'white_id' : 'black_id';
            $n = q('UPDATE chess_games
                       SET ' . $seat . ' = ?, status = "active", last_move_at = NOW()
                     WHERE id = ? AND ' . $seat . ' IS NULL AND status = "waiting"',
                   [$uid, (int) $open['id']])->rowCount();

            if ($n === 1) {
                return ['joined' => true,
                        'match'  => chess_view(chess_match_by_id((int) $open['id']), $uid)];
            }
            // someone took the seat first — open our own instead
        }

        $white = random_int(0, 1) === 1;
        $id = insert_row('chess_games', [
            'code'         => chess_code(),
            'mode'         => 'public',
            'host_id'      => $uid,
            'white_id'     => $white ? $uid : null,
            'black_id'     => $white ? null : $uid,
            'fen'          => CH_START_FEN,
            'moves'        => '',
            'turn'         => 'w',
            'status'       => 'waiting',
            'last_move_at' => date('Y-m-d H:i:s'),
        ]);

        return ['joined' => false, 'match' => chess_view(chess_match_by_id($id), $uid)];
    }

    // ---- private game --------------------------------------------------
    case 'create': {
        q('UPDATE chess_games SET status = "aborted", reason = "replaced"
            WHERE host_id = ? AND status = "waiting"', [$uid]);

        $want  = (string) ($body['color'] ?? 'random');
        $white = $want === 'white' ? true
               : ($want === 'black' ? false : random_int(0, 1) === 1);

        $id = insert_row('chess_games', [
            'code'         => chess_code(),
            'mode'         => 'private',
            'host_id'      => $uid,
            'white_id'     => $white ? $uid : null,
            'black_id'     => $white ? null : $uid,
            'fen'          => CH_START_FEN,
            'moves'        => '',
            'turn'         => 'w',
            'status'       => 'waiting',
            'last_move_at' => date('Y-m-d H:i:s'),
        ]);

        return ['match' => chess_view(chess_match_by_id($id), $uid)];
    }

    case 'join': {
        $code = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) ($body['code'] ?? '')));
        if (strlen($code) !== 6) {
            throw new ChessError('bad_code', 'A game code is six letters and digits.', 422);
        }

        $m = fetch_one('SELECT * FROM chess_games WHERE code = ?', [$code]);
        if (!$m) {
            throw new ChessError('not_found', 'No game with that code.', 404);
        }
        if (chess_side($m, $uid) !== '') {
            return ['match' => chess_view($m, $uid)];
        }
        if ($m['status'] !== 'waiting') {
            throw new ChessError('started', 'That game has already started.', 409);
        }

        $seat = $m['white_id'] === null ? 'white_id' : 'black_id';
        $n = q('UPDATE chess_games
                   SET ' . $seat . ' = ?, status = "active", last_move_at = NOW()
                 WHERE id = ? AND ' . $seat . ' IS NULL AND status = "waiting"',
               [$uid, (int) $m['id']])->rowCount();

        if ($n !== 1) {
            throw new ChessError('taken', 'Somebody else took that seat.', 409);
        }

        return ['match' => chess_view(chess_match_by_id((int) $m['id']), $uid)];
    }

    // ---- polling -------------------------------------------------------
    case 'state': {
        $m = chess_match_by_id((int) ($body['id'] ?? 0));
        if (!$m) {
            throw new ChessError('not_found', 'Game not found.', 404);
        }
        $m    = chess_check_timeout($m);
        $side = chess_side($m, $uid);

        if ($side === 'w') {
            q('UPDATE chess_games SET white_seen = NOW() WHERE id = ?', [(int) $m['id']]);
        } elseif ($side === 'b') {
            q('UPDATE chess_games SET black_seen = NOW() WHERE id = ?', [(int) $m['id']]);
        }

        return ['match' => chess_view($m, $uid)];
    }

    // ---- a move --------------------------------------------------------
    case 'move': {
        $m = chess_match_by_id((int) ($body['id'] ?? 0));
        if (!$m) {
            throw new ChessError('not_found', 'Game not found.', 404);
        }

        $m    = chess_check_timeout($m);
        $side = chess_side($m, $uid);

        if ($side === '') {
            throw new ChessError('not_yours', 'You are not playing in this game.', 403);
        }
        if ($m['status'] !== 'active') {
            return ['match' => chess_view($m, $uid), 'rejected' => 'not active'];
        }
        if ($side !== $m['turn']) {
            throw new ChessError('not_your_turn', 'It is not your move.', 409,
                                 ['match' => chess_view($m, $uid)]);
        }

        // the client says which ply it is answering; a mismatch means the
        // two sides have drifted, so make it resync instead of moving
        $ply = (int) ($body['ply'] ?? -1);
        if ($ply >= 0 && $ply !== (int) $m['ply']) {
            throw new ChessError('out_of_sync', 'The board moved on — refreshing.', 409,
                                 ['match' => chess_view($m, $uid)]);
        }

        $st = ch_load((string) $m['fen']);
        if (!$st) {
            throw new ChessError('corrupt', 'The stored position is unreadable.', 500);
        }

        $uci  = strtolower(trim((string) ($body['uci'] ?? '')));
        $next = ch_play($st, $uci);
        if ($next === null) {
            throw new ChessError('illegal', 'That move is not legal.', 422,
                                 ['match' => chess_view($m, $uid)]);
        }

        $moves = trim(trim((string) $m['moves']) . ' ' . $uci);

        $n = q('UPDATE chess_games
                   SET fen = ?, moves = ?, ply = ply + 1, turn = ?,
                       draw_offer = "none", last_move_at = NOW()
                 WHERE id = ? AND ply = ?',
               [ch_fen($next), $moves, $next['turn'] === CH_WHITE ? 'w' : 'b',
                (int) $m['id'], (int) $m['ply']])->rowCount();

        if ($n !== 1) {
            throw new ChessError('out_of_sync', 'That move arrived twice — refreshing.', 409,
                                 ['match' => chess_view(chess_match_by_id((int) $m['id']), $uid)]);
        }

        $m = chess_match_by_id((int) $m['id']);

        $replay = ch_replay($moves);
        $status = ch_status($replay['state'], $replay['keys']);
        if ($status['over']) {
            $m = chess_finish($m, (string) $status['result'], (string) $status['reason']);
        }

        return ['match' => chess_view($m, $uid), 'status' => $status];
    }

    // ---- resign / draw / cancel ----------------------------------------
    case 'resign': {
        $m = chess_match_by_id((int) ($body['id'] ?? 0));
        if (!$m) {
            throw new ChessError('not_found', 'Game not found.', 404);
        }
        $side = chess_side($m, $uid);
        if ($side === '') {
            throw new ChessError('not_yours', 'You are not playing in this game.', 403);
        }

        if ($m['status'] === 'active') {
            $m = chess_finish($m, $side === 'w' ? 'black' : 'white', 'resignation');
        } elseif ($m['status'] === 'waiting') {
            q('UPDATE chess_games SET status = "aborted", reason = "cancelled" WHERE id = ?',
              [(int) $m['id']]);
            $m = chess_match_by_id((int) $m['id']);
        }

        return ['match' => chess_view($m, $uid)];
    }

    case 'draw': {
        $m = chess_match_by_id((int) ($body['id'] ?? 0));
        if (!$m) {
            throw new ChessError('not_found', 'Game not found.', 404);
        }
        $side = chess_side($m, $uid);
        if ($side === '' || $m['status'] !== 'active') {
            throw new ChessError('no_game', 'No game to offer a draw in.', 409);
        }

        $what = (string) ($body['what'] ?? 'offer');

        if ($what === 'accept') {
            if ($m['draw_offer'] === 'none' || $m['draw_offer'] === $side) {
                throw new ChessError('no_offer', 'There is no draw offer to accept.', 409);
            }
            $m = chess_finish($m, 'draw', 'agreement');
        } elseif ($what === 'decline') {
            q('UPDATE chess_games SET draw_offer = "none" WHERE id = ?', [(int) $m['id']]);
            $m = chess_match_by_id((int) $m['id']);
        } else {
            q('UPDATE chess_games SET draw_offer = ? WHERE id = ?', [$side, (int) $m['id']]);
            $m = chess_match_by_id((int) $m['id']);
        }

        return ['match' => chess_view($m, $uid)];
    }

    case 'cancel': {
        $m = chess_match_by_id((int) ($body['id'] ?? 0));
        if ($m && (int) $m['host_id'] === $uid && $m['status'] === 'waiting') {
            q('UPDATE chess_games SET status = "aborted", reason = "cancelled" WHERE id = ?',
              [(int) $m['id']]);
        }
        return ['match' => null];
    }

    // ---- who is waiting -------------------------------------------------
    case 'lobby': {
        return [
            'open' => fetch_all(
                'SELECT m.code, m.created_at, u.username, u.level
                   FROM chess_games m JOIN users u ON u.id = m.host_id
                  WHERE m.status = "waiting" AND m.mode = "public"
                  ORDER BY m.id DESC LIMIT 10'),
            'recent' => fetch_all(
                'SELECT m.code, m.result, m.reason, m.ply, m.updated_at,
                        w.username AS white, b.username AS black
                   FROM chess_games m
                   LEFT JOIN users w ON w.id = m.white_id
                   LEFT JOIN users b ON b.id = m.black_id
                  WHERE m.status = "finished"
                  ORDER BY m.id DESC LIMIT 8'),
        ];
    }

    default:
        throw new ChessError('unknown_action', 'Unknown action.', 400);
    }
}
