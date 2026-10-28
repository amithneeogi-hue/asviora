<?php
/**
 * Online chess — browser endpoint.
 *
 * A thin door onto includes/chesslobby.php, which is the same referee the
 * mobile API uses. Nothing about the rules lives here: every move arrives
 * as a uci string, is regenerated from the stored position by
 * includes/chessrules.php, and is written only if it appears in the legal
 * move list for the player whose turn it actually is.
 *
 * Actions: token · current · find · create · join · state · move ·
 *          resign · draw · cancel · lobby
 */
require_once __DIR__ . '/../config/config.php';
require_once ROOT_PATH . '/includes/chesslobby.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

function cout(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if (!chess_online_on()) {
    cout(['ok' => false, 'message' => 'Online chess is switched off right now.'], 403);
}
if (!is_logged_in()) {
    cout(['ok' => false, 'message' => 'Log in to play online.',
          'redirect' => url('login.php')], 401);
}

$user = current_user();
$uid  = (int) $user['id'];

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) {
    $body = $_POST;
}
$action = (string) ($body['action'] ?? ($_GET['action'] ?? ''));

/**
 * The game is a static .html file inside an iframe, so it has no
 * PHP-rendered CSRF token to embed. It asks for one here instead: another
 * site can send a request with the player's cookies, but it cannot read
 * this response, so holding the token still proves same-origin.
 */
if ($action === 'token') {
    cout([
        'ok'      => true,
        'csrf'    => csrf_token(),
        'user'    => ['id' => $uid, 'username' => $user['username'], 'name' => $user['name']],
        'rewards' => [
            'win'  => (int) setting('chess_reward_win', '80'),
            'draw' => (int) setting('chess_reward_draw', '30'),
        ],
        'timeout' => (int) setting('chess_move_timeout', '600'),
    ]);
}

// everything past this point changes state and needs the token back
if (!isset($body['csrf'], $_SESSION['csrf'])
    || !hash_equals($_SESSION['csrf'], (string) $body['csrf'])) {
    cout(['ok' => false, 'message' => 'Session expired. Reload the page.'], 419);
}

try {
    cout(['ok' => true] + chess_handle($action, $uid, $body));
} catch (ChessError $e) {
    cout(['ok' => false, 'message' => $e->getMessage()] + $e->extra,
         $e->getCode() ?: 400);
}
