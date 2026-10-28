<?php
/**
 * Daily spin — server decides the result.
 *
 * The winning segment is picked here using the configured weights.
 *
 * How many spins a player gets is no longer "one, always": it comes from
 * their access rule (see includes/spinaccess.php), which an admin sets per
 * player — so the daily count can differ between accounts, and in
 * invitation-only mode a player with no rule cannot spin at all.
 *
 * Because the allowance is now a count rather than a unique key, the
 * check-and-insert is wrapped in a MySQL named lock. Two tabs clicking at
 * the same moment queue up behind each other instead of both slipping
 * through, so nobody gets a spin they were not owed.
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

$segments = spin_segments();
if (!$segments) {
    out(['ok' => false, 'message' => 'The wheel is not configured yet.'], 500);
}

// -------------------------------------------------------------------
// One spin at a time per player. The named lock is held only for the few
// milliseconds between checking the allowance and writing the result, so
// two tabs clicking together queue up instead of both slipping through.
// -------------------------------------------------------------------
$lockName = 'asviora_spin_' . $uid;
fetch_col('SELECT GET_LOCK(?, 5)', [$lockName], 0);

$idx   = 0;
$coins = 0;

try {
    $status = spin_status($uid);

    if (!$status['allowed']) {
        $last = spin_today($uid);
        fetch_col('SELECT RELEASE_LOCK(?)', [$lockName], 0);
        out([
            'ok'        => false,
            'already'   => $status['used'] > 0,
            'segment'   => (int) ($last['segment'] ?? 0),
            'coins'     => (int) ($last['coins'] ?? 0),
            'remaining' => 0,
            // a cooldown expires before midnight does, so it wins the countdown
            'next_in'   => !empty($status['wait'])
                           ? (int) $status['wait'] : seconds_to_midnight(),
            'message'   => $status['reason'],
        ], 409);
    }

    $idx   = spin_pick($segments);
    $coins = (int) $segments[$idx]['coins'];

    insert_row('spin_results', [
        'user_id'    => $uid,
        'spin_date'  => date('Y-m-d'),
        'segment'    => $idx,
        'coins'      => $coins,
        'ip_address' => client_ip(),
    ]);

    // a bonus spin is burnt only once the daily allowance is already spent
    spin_consume($uid);

} finally {
    fetch_col('SELECT RELEASE_LOCK(?)', [$lockName], 0);
}

$balance = $coins > 0
    ? add_coins($uid, $coins, 'spin', 'Spin — segment ' . ($idx + 1))
    : (int) fetch_col('SELECT coins FROM users WHERE id = ?', [$uid], 0);

$after = spin_status($uid);

out([
    'ok'        => true,
    'segment'   => $idx,
    'coins'     => $coins,
    'balance'   => $balance,
    'remaining' => (int) $after['remaining'],
    'wait'      => (int) ($after['wait'] ?? 0),
    'message'   => $after['allowed'] ? '' : $after['reason'],
    'next_in'   => !empty($after['wait'])
                   ? (int) $after['wait'] : seconds_to_midnight(),
]);
