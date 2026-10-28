<?php
/**
 * Mobile API v1 — shared bootstrap.
 *
 * The Android app never touches the PHP session. It logs in once, gets a
 * bearer token, and sends it on every later call:
 *
 *     Authorization: Bearer <token>
 *
 * Only the sha256 of the token is stored, so a leaked database row cannot
 * be replayed as a login. Tokens expire (api_token_days, default 60) and
 * can be revoked per device from the app or by an admin.
 *
 * Every response has the same envelope:
 *
 *     { "ok": true,  "data": { ... } }
 *     { "ok": false, "error": { "code": "...", "message": "..." } }
 *
 * so the client has exactly one shape to parse.
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

// The app is a native client, not a browser page, so CORS is not what
// protects this — the bearer token is. These headers only make life easy
// for a debug build served from a local dev server.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-App-Version');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---------------------------------------------------------------
// Envelope
// ---------------------------------------------------------------

function api_ok($data = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_SLASHES);
    exit;
}

function api_fail(string $errCode, string $message, int $http = 400, array $extra = []): void
{
    http_response_code($http);
    echo json_encode([
        'ok'    => false,
        'error' => array_merge(['code' => $errCode, 'message' => $message], $extra),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

/** The JSON body, or the form fields if the client posted a form. */
function api_body(): array
{
    static $body = null;
    if ($body !== null) {
        return $body;
    }
    $raw  = file_get_contents('php://input') ?: '';
    $json = json_decode($raw, true);
    $body = is_array($json) ? $json : $_POST;
    return $body;
}

function api_param(string $key, $default = null)
{
    $b = api_body();
    if (array_key_exists($key, $b)) {
        return $b[$key];
    }
    return $_GET[$key] ?? $default;
}

function api_str(string $key, string $default = ''): string
{
    return trim((string) api_param($key, $default));
}

function api_int(string $key, int $default = 0): int
{
    return (int) api_param($key, $default);
}

// ---------------------------------------------------------------
// Tokens
// ---------------------------------------------------------------

function api_token_from_header(): string
{
    $h = $_SERVER['HTTP_AUTHORIZATION']
      ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
      ?? '';

    // some Apache setups strip the header; getallheaders() usually still has it
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $h = $v;
                break;
            }
        }
    }

    if (preg_match('/Bearer\s+([A-Za-z0-9._\-]+)/i', (string) $h, $m)) {
        return $m[1];
    }
    return (string) api_param('token', '');
}

/** Mint a token for a player and store only its hash. */
function api_issue_token(int $userId, string $device, string $platform, string $version): array
{
    $token = bin2hex(random_bytes(32));
    $days  = max(1, (int) setting('api_token_days', '60'));
    $exp   = date('Y-m-d H:i:s', time() + $days * 86400);

    insert_row('api_tokens', [
        'token_hash'   => hash('sha256', $token),
        'user_id'      => $userId,
        'device_name'  => mb_substr($device, 0, 120) ?: null,
        'platform'     => mb_substr($platform, 0, 30) ?: 'android',
        'app_version'  => mb_substr($version, 0, 20) ?: null,
        'ip_address'   => client_ip(),
        'expires_at'   => $exp,
        'last_used_at' => date('Y-m-d H:i:s'),
    ]);

    return ['token' => $token, 'expires_at' => $exp];
}

/** The player behind the bearer token, or null. */
function api_user(): ?array
{
    static $user = null;
    static $done = false;

    if ($done) {
        return $user;
    }
    $done = true;

    $token = api_token_from_header();
    if ($token === '' || !has_table('api_tokens')) {
        return null;
    }

    $row = fetch_one(
        'SELECT t.id AS token_id, t.expires_at, u.*
           FROM api_tokens t
           JOIN users u ON u.id = t.user_id
          WHERE t.token_hash = ? AND t.revoked = 0
            AND (t.expires_at IS NULL OR t.expires_at > NOW())
            AND u.status = "active"',
        [hash('sha256', $token)]
    );

    if (!$row) {
        return null;
    }

    q('UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?', [(int) $row['token_id']]);

    $user = $row;
    return $user;
}

/** Every protected route starts with this. */
function api_require_user(): array
{
    $u = api_user();
    if (!$u) {
        api_fail('unauthorized', 'Please sign in again.', 401);
    }
    return $u;
}

/** Refuse the request when the module it belongs to is switched off. */
function api_require_module(string $key): void
{
    if (!module_on($key)) {
        api_fail('module_off', module_label($key) . ' is not available right now.', 403);
    }
}

// ---------------------------------------------------------------
// Shapes the app expects
// ---------------------------------------------------------------

/** One player, trimmed to what a client should ever see. */
function api_user_public(array $u): array
{
    return [
        'id'       => (int) $u['id'],
        'name'     => $u['name'],
        'username' => $u['username'],
        'email'    => $u['email'] ?? null,
        'avatar'   => avatar_url($u),
        'coins'    => (int) ($u['coins'] ?? 0),
        'xp'       => (int) ($u['xp'] ?? 0),
        'level'    => (int) ($u['level'] ?? 1),
        'bio'      => $u['bio'] ?? null,
        'referral' => $u['referral_code'] ?? null,
        'joined'   => $u['created_at'] ?? null,
        // v7 — the app shows the pay screen straight after sign-in when due
        'reg_fee_due' => function_exists('reg_fee_due') && isset($u['id'], $u['created_at'])
                         ? reg_fee_due($u) : false,
        'reg_fee'     => function_exists('reg_fee_amount') && reg_fee_amount() > 0
                         ? money(reg_fee_amount()) : null,
    ];
}

/**
 * v7 — refuse a play request the player has not paid for. Answers 402 with
 * everything the app needs to open the pay screen:
 *   { "ok": false, "error": { "code": "payment_required", "message": "...",
 *     "reason": "registration|subscription|unavailable", "pay_url": "...", ... } }
 */
function api_require_access(array $u, string $key): void
{
    if (!function_exists('game_access')) {
        return;
    }
    $a = game_access($u, $key);
    if ($a['ok']) {
        return;
    }
    api_fail('payment_required', $a['message'], 402, api_payment_payload($u, $a, $key));
}

/** Price / lock fields added to every catalogue entry. (v7) */
function api_plan_fields(string $key, ?array $u): array
{
    $plan = function_exists('game_plan') ? game_plan($key) : null;
    $till = ($plan && $u) ? (sub_active((int) $u['id'], $key)['expires_at'] ?? null) : null;
    return [
        'paid'     => $plan !== null,
        'price'    => $plan ? money((int) $plan['price_paise']) : null,
        'amount'   => $plan ? (int) $plan['price_paise'] : 0,
        'days'     => $plan ? (int) $plan['duration_days'] : 0,
        'plan'     => $plan ? ($plan['label'] ?: days_label((int) $plan['duration_days']) . ' pass') : null,
        'sub_till' => $till,
        'locked'   => $plan !== null && $till === null,
    ];
}

function api_game_public(array $g): array
{
    return [
        'id'        => (int) $g['id'],
        'slug'      => $g['slug'],
        'title'     => $g['title'],
        'tagline'   => $g['tagline'],
        'category'  => $g['category'],
        'icon'      => html_entity_decode((string) $g['icon'], ENT_QUOTES, 'UTF-8'),
        // v7 — falls back to the bundled icon, so chess has a picture too
        'thumbnail' => game_thumb((string) $g['slug'], $g['thumbnail']),
        'play_url'  => url('play.php?game=' . urlencode($g['slug'])),
        'entry'     => (int) $g['entry_coins'],
        'reward'    => (int) $g['reward_coins'],
        'featured'  => (int) $g['is_featured'] === 1,
        'plays'     => (int) $g['plays'],
    ];
}

// ---------------------------------------------------------------
// Rate limiting — cheap, per token, per minute
// ---------------------------------------------------------------
function api_rate_limit(string $bucket, int $perMinute): void
{
    $u  = api_user();
    $id = $u ? (int) $u['id'] : 0;
    $k  = 'rl_' . $bucket . '_' . $id . '_' . client_ip();

    $dir  = ROOT_PATH . '/storage/ratelimit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $file = $dir . '/' . md5($k) . '.txt';

    $now  = time();
    $hits = [];
    if (is_readable($file)) {
        $hits = array_filter(
            array_map('intval', explode(',', (string) file_get_contents($file))),
            static fn($t) => $t > $now - 60
        );
    }

    if (count($hits) >= $perMinute) {
        api_fail('rate_limited', 'Slow down a moment and try again.', 429);
    }

    $hits[] = $now;
    @file_put_contents($file, implode(',', $hits), LOCK_EX);
}
