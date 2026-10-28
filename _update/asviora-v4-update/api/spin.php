<?php
/**
 * Daily spin — server decides the result.
 *
 * The winning segment is picked here using the configured weights, and the
 * unique key on (user_id, spin_date) is what actually enforces "once a day"
 * — so double-clicking, replaying the request or opening two tabs cannot
 * produce a second payout.
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
if (!spin_on()) {
    out(['ok' => false, 'message' => 'The daily spin is not available right now.'], 403);
}
if (!is_logged_in()) {
    out(['ok' => false, 'message' => 'Please log in first.', 'redirect' => url('login.php')], 401);
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) {
    $body = $_POST;
}
if (!isset($body['csrf'], $_SESSION['csrf'])
    || !hash_equals($_SESSION['csrf'], (string) $body['csrf'])) {
    out(['ok' => false, 'message' => 'Session expired. Reload the page.'], 419);
}

$user = current_user();
$uid  = (int) $user['id'];

// The admin can hand the spin out per player (Admin -> Players), or open it
// to everyone (Admin -> Daily Spin). Either way the check happens here.
if (!spin_allowed($user)) {
    out(['ok' => false, 'message' => 'The daily spin is not open to your account.'], 403);
}

// already spun today?
$existing = spin_today($uid);
if ($existing) {
    out([
        'ok'       => false,
        'already'  => true,
        'segment'  => (int) $existing['segment'],
        'coins'    => (int) $existing['coins'],
        'next_in'  => seconds_to_midnight(),
        'message'  => 'You have already spun today. Come back after midnight.',
    ], 409);
}

$segments = spin_segments();
if (!$segments) {
    out(['ok' => false, 'message' => 'The wheel is not configured yet.'], 500);
}

$idx   = spin_pick($segments);
$coins = (int) $segments[$idx]['coins'];

// The unique index is the real lock. If a parallel request beat us to it,
// the insert throws and we report the existing result instead of paying twice.
try {
    insert_row('spin_results', [
        'user_id'    => $uid,
        'spin_date'  => date('Y-m-d'),
        'segment'    => $idx,
        'coins'      => $coins,
        'ip_address' => client_ip(),
    ]);
} catch (PDOException $e) {
    $existing = spin_today($uid);
    out([
        'ok'      => false,
        'already' => true,
        'segment' => (int) ($existing['segment'] ?? 0),
        'coins'   => (int) ($existing['coins'] ?? 0),
        'next_in' => seconds_to_midnight(),
        'message' => 'You have already spun today.',
    ], 409);
}

$balance = $coins > 0
    ? add_coins($uid, $coins, 'spin', 'Daily spin — segment ' . ($idx + 1))
    : (int) fetch_col('SELECT coins FROM users WHERE id = ?', [$uid], 0);

out([
    'ok'      => true,
    'segment' => $idx,
    'coins'   => $coins,
    'balance' => $balance,
    'next_in' => seconds_to_midnight(),
]);
