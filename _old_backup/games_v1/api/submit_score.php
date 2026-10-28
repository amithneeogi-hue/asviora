<?php
/**
 * Receives a finished round from a game iframe (via play.php).
 * Validates it, stores the score, awards coins + XP.
 */
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function out(bool $ok, string $message, array $extra = []): void
{
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    out(false, 'POST only.');
}

// ---- read the JSON body ----
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

$slug  = trim((string) ($data['game'] ?? ''));
$token = trim((string) ($data['token'] ?? ''));

if ($slug === '' || $token === '') {
    out(false, 'Malformed request.');
}

// ---- verify the play token issued by play.php ----
$issued = $_SESSION['play_tokens'][$slug] ?? null;
if (!$issued || !hash_equals((string) $issued['token'], $token)) {
    out(false, 'This round could not be verified. Reload the page and try again.');
}
// a round older than 6 hours is stale
if (time() - (int) $issued['issued'] > 21600) {
    unset($_SESSION['play_tokens'][$slug]);
    out(false, 'Session expired. Reload the page.');
}

// ---- guests can play but not score ----
if (!is_logged_in()) {
    out(false, 'Log in to save your score to the leaderboard.', ['guest' => true]);
}
$user = current_user();
if (!$user) {
    out(false, 'Your session ended. Please log in again.');
}

$game = fetch_one('SELECT * FROM games WHERE slug = ? AND is_active = 1', [$slug]);
if (!$game) {
    out(false, 'Unknown game.');
}

// ---- sanitise the payload ----
$score  = (int) ($data['score'] ?? 0);
$time   = (int) ($data['time_taken'] ?? 0);
$moves  = (int) ($data['moves'] ?? 0);
$diff   = substr(preg_replace('/[^a-z0-9_\-]/i', '', (string) ($data['difficulty'] ?? '')), 0, 20);
$result = in_array($data['result'] ?? 'win', ['win', 'loss', 'incomplete'], true)
        ? $data['result'] : 'win';
$meta   = substr(strip_tags((string) ($data['meta'] ?? '')), 0, 200);

// hard ceilings stop a tampered client from posting nonsense
$CEIL_SCORE = 100000;
$CEIL_TIME  = 7200;
$CEIL_MOVES = 5000;

if ($score < 0 || $score > $CEIL_SCORE)  out(false, 'Score out of range.');
if ($time  < 0 || $time  > $CEIL_TIME)   out(false, 'Time out of range.');
if ($moves < 0 || $moves > $CEIL_MOVES)  out(false, 'Move count out of range.');

// ---- rate limit: max 40 submissions per hour per player ----
$recent = (int) fetch_col(
    'SELECT COUNT(*) FROM scores WHERE user_id = ? AND played_at > (NOW() - INTERVAL 1 HOUR)',
    [$user['id']], 0);
if ($recent >= 40) {
    out(false, 'Too many rounds submitted in the last hour. Take a short break.');
}

// ---- a win must take a plausible minimum amount of time ----
if ($result === 'win' && $score > 0 && $time < 3) {
    out(false, 'That round finished suspiciously fast and was not recorded.');
}

// ---- store ----
$prevBest = (int) fetch_col('SELECT MAX(score) FROM scores WHERE user_id = ? AND game_id = ?',
                            [$user['id'], $game['id']], 0);

$coins = 0;
if ($result === 'win' && $score > 0) {
    $coins = (int) $game['reward_coins'];
    // beating your own record pays a 50% bonus
    if ($score > $prevBest && $prevBest > 0) {
        $coins += (int) round($game['reward_coins'] * 0.5);
    }
}

try {
    db()->beginTransaction();

    insert_row('scores', [
        'user_id'      => $user['id'],
        'game_id'      => $game['id'],
        'score'        => $score,
        'time_taken'   => $time,
        'moves'        => $moves,
        'difficulty'   => $diff !== '' ? $diff : null,
        'coins_earned' => $coins,
        'result'       => $result,
        'meta'         => $meta !== '' ? $meta : null,
        'ip_address'   => client_ip(),
    ]);

    if ($coins > 0) {
        add_coins((int) $user['id'], $coins, 'reward',
                  $game['title'] . ' — ' . num($score) . ' pts');
    }
    if ($score > 0) {
        add_xp((int) $user['id'], max(1, (int) floor($score / 20)));
    }

    db()->commit();
} catch (Throwable $ex) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    out(false, DEBUG_MODE ? 'DB error: ' . $ex->getMessage() : 'Could not save the score.');
}

// ---- work out the new rank ----
$rank = 1 + (int) fetch_col(
    'SELECT COUNT(DISTINCT user_id) FROM scores
     WHERE game_id = ? AND score > ?', [$game['id'], $score], 0);

$isPB   = $score > $prevBest;
$newBal = (int) fetch_col('SELECT coins FROM users WHERE id = ?', [$user['id']], 0);

$msg = $result === 'loss'
    ? 'Round recorded — better luck next time.'
    : ($isPB ? 'New personal best! ' : 'Score saved! ')
      . num($score) . ' pts'
      . ($coins > 0 ? ' · +' . num($coins) . ' coins' : '')
      . ' · rank #' . $rank;

out(true, $msg, [
    'score'        => $score,
    'coins_earned' => $coins,
    'balance'      => $newBal,
    'personal_best'=> $isPB,
    'rank'         => $rank,
]);
