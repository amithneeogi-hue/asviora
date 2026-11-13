<?php
/**
 * Rewarded ad API — grants the rewards unlocked by Google AdSense rewarded
 * placements. The player watches the rewarded ad in the browser, then calls
 * this endpoint to settle the grant server-side.
 *
 * Actions:
 *  - double_coins : watch the rewarded ad → get reward coins credited
 *  - extra_life   : watch the rewarded ad → get one extra life
 *
 * Both actions are gated by the Admin -> Advertising "Rewarded ads" toggle and
 * are limited to once per player per day.
 */
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

/** Send JSON and stop. */
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

$json = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($json)) {
    $json = $_POST;
}

if (!isset($json['csrf'], $_SESSION['csrf'])
    || !hash_equals($_SESSION['csrf'], (string) $json['csrf'])) {
    out(['ok' => false, 'message' => 'Session expired. Reload the page.'], 419);
}

$user   = current_user();
$uid    = (int) $user['id'];
$action = (string) ($json['action'] ?? '');

if (!ads_reward_enabled()) {
    out(['ok' => false, 'message' => 'Rewarded ads are not enabled.'], 403);
}

// Once per player per day per reward type.
$usedKey  = 'ads_reward_used_' . $uid . '_' . $action;
$lastDay = date('Y-m-d');
if (trim((string) setting($usedKey)) === $lastDay) {
    out(['ok' => true, 'claimed' => true, 'message' => 'Already claimed today.']);
}

set_setting($usedKey, $lastDay);

if ($action === 'double_coins') {
    $amount = ads_reward_coins();
    if ($amount > 0) {
        add_coins($uid, $amount, 'ads_reward', 'Rewarded ad — double coins');
    }
    out(['ok' => true, 'granted' => 'double_coins', 'coins' => $amount]);
}

if ($action === 'extra_life') {
    // One extra life per player per day.
    $lifeKey = 'ads_life_' . $uid;
    $life    = (int) setting($lifeKey, '0') + 1;
    set_setting($lifeKey, (string) $life);
    out(['ok' => true, 'granted' => 'extra_life', 'life' => $life]);
}

out(['ok' => false, 'message' => 'Unknown action.'], 400);
