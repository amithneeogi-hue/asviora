<?php
/**
 * Chess API — the only place a match may change.
 *
 * The browser sends a move; this file re-generates the legal moves from the
 * stored FEN and refuses anything that is not in that list. Clocks are
 * measured against the server's own timestamps, and the pot is paid out by
 * chess_settle() in includes/chess.php.
 *
 * Actions: create | join | cancel | state | move | resign | draw | ai_result
 */
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

function out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(['ok' => false, 'message' => 'POST only.'], 405);
}
if (!is_logged_in()) {
    out(['ok' => false, 'message' => 'Please log in again.', 'redirect' => url('login.php')], 401);
}
if (!chess_on()) {
    out(['ok' => false, 'message' => 'Chess is switched off right now.'], 403);
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) {
    $body = $_POST;
}
if (!isset($body['csrf'], $_SESSION['csrf'])
    || !hash_equals($_SESSION['csrf'], (string) $body['csrf'])) {
    out(['ok' => false, 'message' => 'Session expired. Reload the page.'], 419);
}

$user   = current_user();
$uid    = (int) $user['id'];

/**
 * Timestamps are written by PHP, not by MySQL's NOW().
 *
 * The clocks are the difference between a stored timestamp and PHP's own
 * time(), and PHP and MySQL do not always agree on what timezone they are
 * in — on a host where they differ, NOW() would look hours old and every
 * first move would flag on time. Writing the value from PHP makes the two
 * ends of that subtraction come from the same clock.
 */
function now_str(): string
{
    return date('Y-m-d H:i:s');
}
$action = (string) ($body['action'] ?? '');
$code   = strtoupper(trim((string) ($body['code'] ?? '')));

/**
 * Everything the board needs to draw itself.
 */
function match_payload(array $m, int $uid): array
{
    $players = chess_players($m);
    $me      = ((int) $m['white_id'] === $uid) ? 'w'
             : (((int) $m['black_id'] === $uid) ? 'b' : '');

    $white = $players[(int) $m['white_id']] ?? null;
    $black = $players[(int) $m['black_id']] ?? null;

    // how long the side to move has already burned
    $spent = 0;
    if ($m['status'] === 'active' && $m['last_move_at']) {
        $spent = max(0, time() - strtotime($m['last_move_at'])) * 1000;
    }

    return [
        'code'    => $m['code'],
        'mode'    => $m['mode'],
        'status'  => $m['status'],
        'fen'     => $m['fen'],
        'turn'    => $m['turn'],
        'moves'   => $m['moves'] ? explode(' ', trim($m['moves'])) : [],
        'you'     => $me,
        'stake'   => (int) $m['stake'],
        'pot'     => (int) $m['pot'],
        'minutes' => (int) $m['minutes'],
        'white_ms' => max(0, (int) $m['white_ms'] - ($m['turn'] === 'w' ? $spent : 0)),
        'black_ms' => max(0, (int) $m['black_ms'] - ($m['turn'] === 'b' ? $spent : 0)),
        'white'   => $white ? ['name' => $white['username'], 'avatar' => avatar_url($white)] : null,
        'black'   => $black ? ['name' => $black['username'], 'avatar' => avatar_url($black)] : null,
        'result'  => $m['result'],
        'reason'  => $m['reason'],
        'winner'  => $m['winner_id'] ? (int) $m['winner_id'] : null,
        'draw_offer'      => $m['draw_offer'] ? (int) $m['draw_offer'] : null,
        'draw_offer_mine' => $m['draw_offer'] && (int) $m['draw_offer'] === $uid,
        'balance' => (int) fetch_col('SELECT coins FROM users WHERE id = ?', [$uid], 0),
    ];
}

/**
 * Has the side to move run out of time? Settles the match if so.
 */
function enforce_clock(array $m): array
{
    if ($m['status'] !== 'active' || !$m['last_move_at']) {
        return $m;
    }
    $spent = max(0, time() - strtotime($m['last_move_at'])) * 1000;
    $left  = $m['turn'] === 'w' ? (int) $m['white_ms'] : (int) $m['black_ms'];

    if ($spent >= $left) {
        q('UPDATE chess_matches SET white_ms = ?, black_ms = ? WHERE id = ?',
          [$m['turn'] === 'w' ? 0 : (int) $m['white_ms'],
           $m['turn'] === 'b' ? 0 : (int) $m['black_ms'], (int) $m['id']]);
        $m['white_ms'] = $m['turn'] === 'w' ? 0 : $m['white_ms'];
        $m['black_ms'] = $m['turn'] === 'b' ? 0 : $m['black_ms'];
        return chess_settle($m, $m['turn'] === 'w' ? 'black' : 'white', 'timeout');
    }
    return $m;
}

switch ($action) {

// -------------------------------------------------------------------
// Open a challenge. The host's stake leaves their balance immediately,
// so a challenge can never be created with coins that are not there.
// -------------------------------------------------------------------
case 'create':

    if (!chess_pvp_on()) {
        out(['ok' => false, 'message' => 'Player matches are switched off.'], 403);
    }

    $stake   = max(0, (int) ($body['stake'] ?? 0));
    $minutes = (int) ($body['minutes'] ?? 10);
    if (!in_array($minutes, chess_time_options(), true)) {
        $minutes = 10;
    }
    if ($stake < chess_min_stake() || $stake > chess_max_stake()) {
        out(['ok' => false, 'message' => 'Stake must be between '
             . num(chess_min_stake()) . ' and ' . num(chess_max_stake()) . ' coins.'], 422);
    }
    if ($stake > (int) $user['coins']) {
        out(['ok' => false, 'message' => 'You do not have that many coins.'], 422);
    }

    // one open challenge at a time keeps stakes from being parked everywhere
    $open = fetch_one('SELECT * FROM chess_matches
                        WHERE host_id = ? AND status = "open" AND mode = "pvp"', [$uid]);
    if ($open) {
        out(['ok' => false, 'message' => 'You already have an open challenge.',
             'code' => $open['code']], 409);
    }

    if ($stake > 0) {
        add_coins($uid, -$stake, 'entry_fee', 'Chess stake');
    }

    $newCode = chess_new_code();
    insert_row('chess_matches', [
        'code'      => $newCode,
        'mode'      => 'pvp',
        'host_id'   => $uid,
        'stake'     => $stake,
        'pot'       => $stake,
        'minutes'   => $minutes,
        'fen'       => CHESS_START_FEN,
        'white_ms'  => $minutes * 60000,
        'black_ms'  => $minutes * 60000,
        'status'    => 'open',
    ]);

    out(['ok' => true, 'code' => $newCode,
         'link' => url('chess-play.php?m=' . $newCode),
         'balance' => (int) fetch_col('SELECT coins FROM users WHERE id = ?', [$uid], 0)]);

// -------------------------------------------------------------------
// Take a challenge on
// -------------------------------------------------------------------
case 'join':

    $m = chess_match($code);
    if (!$m) {
        out(['ok' => false, 'message' => 'That challenge does not exist.'], 404);
    }
    if ($m['status'] !== 'open') {
        out(['ok' => false, 'message' => 'That challenge is no longer open.'], 409);
    }
    if ((int) $m['host_id'] === $uid) {
        out(['ok' => false, 'message' => 'That is your own challenge — share the link.'], 409);
    }

    $stake = (int) $m['stake'];
    if ($stake > (int) $user['coins']) {
        out(['ok' => false, 'message' => 'You need ' . num($stake) . ' coins to accept.'], 422);
    }
    if ($stake > 0) {
        add_coins($uid, -$stake, 'entry_fee', 'Chess stake — match ' . $m['code']);
    }

    // colours drawn at random, so neither player can pick white
    $hostWhite = random_int(0, 1) === 1;

    $done = q('UPDATE chess_matches
                  SET guest_id = ?, white_id = ?, black_id = ?, pot = pot + ?,
                      status = "active", started_at = ?, last_move_at = ?
                WHERE id = ? AND status = "open"',
      [$uid,
       $hostWhite ? (int) $m['host_id'] : $uid,
       $hostWhite ? $uid : (int) $m['host_id'],
       $stake, now_str(), now_str(), (int) $m['id']])->rowCount();

    if (!$done) {
        if ($stake > 0) {
            add_coins($uid, $stake, 'bonus', 'Chess stake returned — match already taken');
        }
        out(['ok' => false, 'message' => 'Someone accepted it first.'], 409);
    }

    $m = chess_match($code);
    out(['ok' => true, 'match' => match_payload($m, $uid)]);

// -------------------------------------------------------------------
// Pull the challenge back
// -------------------------------------------------------------------
case 'cancel':

    $m = chess_match($code);
    if (!$m || (int) $m['host_id'] !== $uid) {
        out(['ok' => false, 'message' => 'Challenge not found.'], 404);
    }
    if ($m['status'] !== 'open') {
        out(['ok' => false, 'message' => 'That match has already started.'], 409);
    }
    q('UPDATE chess_matches SET status = "cancelled", ended_at = ? WHERE id = ?',
      [now_str(), (int) $m['id']]);
    if ((int) $m['stake'] > 0) {
        add_coins($uid, (int) $m['stake'], 'bonus', 'Chess challenge cancelled — stake returned');
    }
    out(['ok' => true, 'balance' => (int) fetch_col('SELECT coins FROM users WHERE id = ?', [$uid], 0)]);

// -------------------------------------------------------------------
// Poll
// -------------------------------------------------------------------
case 'state':

    $m = chess_match($code);
    if (!$m) {
        out(['ok' => false, 'message' => 'Match not found.'], 404);
    }
    $m = enforce_clock($m);
    out(['ok' => true, 'match' => match_payload($m, $uid)]);

// -------------------------------------------------------------------
// Play a move
// -------------------------------------------------------------------
case 'move':

    $m = chess_match($code);
    if (!$m) {
        out(['ok' => false, 'message' => 'Match not found.'], 404);
    }
    $m = enforce_clock($m);
    if ($m['status'] !== 'active') {
        out(['ok' => true, 'match' => match_payload($m, $uid)]);
    }

    $side = ((int) $m['white_id'] === $uid) ? 'w' : (((int) $m['black_id'] === $uid) ? 'b' : '');
    if ($side === '') {
        out(['ok' => false, 'message' => 'You are not in this match.'], 403);
    }
    if ($side !== $m['turn']) {
        out(['ok' => false, 'message' => 'Not your turn.', 'match' => match_payload($m, $uid)], 409);
    }

    $pos  = chess_parse($m['fen']);
    $move = chess_find_move($pos, (string) ($body['uci'] ?? ''));
    if (!$move) {
        out(['ok' => false, 'message' => 'Illegal move.',
             'match' => match_payload($m, $uid)], 422);
    }

    // ---- clocks ----
    $spent = max(0, time() - strtotime($m['last_move_at'] ?: $m['started_at'])) * 1000;
    $inc   = (int) $m['increment'] * 1000;
    $wms   = (int) $m['white_ms'];
    $bms   = (int) $m['black_ms'];
    if ($side === 'w') {
        $wms = $wms - $spent + $inc;
    } else {
        $bms = $bms - $spent + $inc;
    }
    if ($wms <= 0 || $bms <= 0) {
        q('UPDATE chess_matches SET white_ms = ?, black_ms = ? WHERE id = ?',
          [max(0, $wms), max(0, $bms), (int) $m['id']]);
        $m['white_ms'] = max(0, $wms);
        $m['black_ms'] = max(0, $bms);
        $m = chess_settle($m, $wms <= 0 ? 'black' : 'white', 'timeout');
        out(['ok' => true, 'match' => match_payload($m, $uid)]);
    }

    $after = chess_make($pos, $move);
    $list  = trim(($m['moves'] ?? '') . ' ' . chess_name($move['from']) . chess_name($move['to'])
                  . ($move['promo'] ?? ''));

    q('UPDATE chess_matches
          SET fen = ?, turn = ?, moves = ?, white_ms = ?, black_ms = ?,
              last_move_at = ?, draw_offer = NULL
        WHERE id = ?',
      [chess_fen($after), $after['turn'], $list, $wms, $bms, now_str(), (int) $m['id']]);

    $m = chess_match($code);

    $st = chess_state($after);
    if ($st['state'] === 'checkmate') {
        $m = chess_settle($m, $st['winner'], 'checkmate');
    } elseif ($st['state'] === 'stalemate' || $st['state'] === 'draw') {
        $m = chess_settle($m, 'draw', $st['state']);
    }

    out(['ok' => true, 'match' => match_payload($m, $uid)]);

// -------------------------------------------------------------------
// Resign
// -------------------------------------------------------------------
case 'resign':

    $m = chess_match($code);
    if (!$m) {
        out(['ok' => false, 'message' => 'Match not found.'], 404);
    }
    $side = ((int) $m['white_id'] === $uid) ? 'w' : (((int) $m['black_id'] === $uid) ? 'b' : '');
    if ($side === '' || $m['status'] !== 'active') {
        out(['ok' => false, 'message' => 'Nothing to resign.'], 409);
    }
    $m = chess_settle($m, $side === 'w' ? 'black' : 'white', 'resign');
    out(['ok' => true, 'match' => match_payload($m, $uid)]);

// -------------------------------------------------------------------
// Draw offer / acceptance
// -------------------------------------------------------------------
case 'draw':

    $m = chess_match($code);
    if (!$m) {
        out(['ok' => false, 'message' => 'Match not found.'], 404);
    }
    $side = ((int) $m['white_id'] === $uid) ? 'w' : (((int) $m['black_id'] === $uid) ? 'b' : '');
    if ($side === '' || $m['status'] !== 'active') {
        out(['ok' => false, 'message' => 'No live match.'], 409);
    }

    $what = (string) ($body['what'] ?? 'offer');
    if ($what === 'accept' && $m['draw_offer'] && (int) $m['draw_offer'] !== $uid) {
        $m = chess_settle($m, 'draw', 'agreed');
    } elseif ($what === 'decline') {
        q('UPDATE chess_matches SET draw_offer = NULL WHERE id = ?', [(int) $m['id']]);
        $m = chess_match($code);
    } else {
        q('UPDATE chess_matches SET draw_offer = ? WHERE id = ?', [$uid, (int) $m['id']]);
        $m = chess_match($code);
    }
    out(['ok' => true, 'match' => match_payload($m, $uid)]);

// -------------------------------------------------------------------
// Result of a game against the engine.
//
// Nothing here can be verified, so the payout is small, fixed and capped
// per day — it is a practice reward, not a prize.
// -------------------------------------------------------------------
case 'ai_result':

    if (!chess_ai_on()) {
        out(['ok' => false, 'message' => 'Practice games are switched off.'], 403);
    }

    $result = (string) ($body['result'] ?? '');
    $level  = min(3, max(1, (int) ($body['level'] ?? 1)));
    $plies  = (int) ($body['plies'] ?? 0);

    if (!in_array($result, ['win', 'draw', 'loss'], true)) {
        out(['ok' => false, 'message' => 'Unknown result.'], 422);
    }

    $capPerDay = 3;
    $wonToday  = (int) fetch_col(
        'SELECT COUNT(*) FROM chess_matches
          WHERE mode = "ai" AND host_id = ? AND winner_id = ?
            AND DATE(ended_at) = CURDATE()', [$uid, $uid], 0);

    // a 4-ply "win" is a resignation, not a game
    $earned = 0;
    if ($result === 'win' && $plies >= 20 && $wonToday < $capPerDay) {
        $earned = max(0, (int) setting('chess_ai_reward', '25')) * $level;
    }

    insert_row('chess_matches', [
        'code'      => chess_new_code(),
        'mode'      => 'ai',
        'host_id'   => $uid,
        'white_id'  => $uid,
        'ai_level'  => $level,
        'stake'     => 0,
        'pot'       => 0,
        'minutes'   => 0,
        'moves'     => mb_substr((string) ($body['moves'] ?? ''), 0, 4000),
        'status'    => 'finished',
        'result'    => $result === 'win' ? 'white' : ($result === 'draw' ? 'draw' : 'black'),
        'reason'    => 'vs computer',
        'winner_id' => $result === 'win' ? $uid : null,
        'started_at'=> date('Y-m-d H:i:s'),
        'ended_at'  => date('Y-m-d H:i:s'),
    ]);

    if ($earned > 0) {
        add_coins($uid, $earned, 'reward', 'Beat the chess engine (level ' . $level . ')');
        add_xp($uid, 10 * $level);
    }

    out(['ok' => true, 'coins' => $earned,
         'capped' => $result === 'win' && $earned === 0 && $wonToday >= $capPerDay,
         'balance' => (int) fetch_col('SELECT coins FROM users WHERE id = ?', [$uid], 0)]);

default:
    out(['ok' => false, 'message' => 'Unknown action.'], 400);
}
