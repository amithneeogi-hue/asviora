<?php
/**
 * PAYMENTS, GAME PLANS, SUBSCRIPTIONS, REGISTRATION FEE  (v7)
 * ------------------------------------------------------------------
 * One file decides who may play what:
 *
 *   game_access($user, $key)  ->  ['ok' => bool, 'reason' => ..., ...]
 *
 * and every entry point — play.php, game-frame.php, quiz, spin, chess,
 * the old AJAX endpoints and the mobile API — asks it. Change the rule
 * here and the website and the app change together.
 *
 * Money is always handled in paise (integer). Gateways:
 *   razorpay — Orders API + Checkout.js, verified by HMAC signature
 *   cashfree — PG Orders API (2023-08-01) + cashfree.js, verified by
 *              fetching the order back from Cashfree
 * Both also accept a webhook (pay-webhook.php) so a payment whose browser
 * closed before returning is still credited.
 *
 * Nothing here throws if the v7 tables are missing: payments simply stay
 * off and every game stays free.
 */

const PAY_GATEWAYS = [
    'razorpay' => 'Razorpay',
    'cashfree' => 'Cashfree',
];

/** The v7 tables exist and the Payments module is on. */
function payments_ready(): bool
{
    static $ok = null;
    if ($ok === null) {
        $ok = has_table('payments') && has_table('game_plans') && has_table('subscriptions');
    }
    return $ok && module_on('payments');
}

// ---------------------------------------------------------------
// Money helpers
// ---------------------------------------------------------------

function pay_currency(): string
{
    return strtoupper(setting('pay_currency', 'INR')) ?: 'INR';
}

/** 4900 -> "₹49", 4950 -> "₹49.50" */
function money(int $paise, ?string $cur = null): string
{
    $cur = $cur ?: pay_currency();
    $sym = ['INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'][$cur] ?? ($cur . ' ');
    $amt = $paise / 100;
    return $sym . (fmod($amt, 1.0) == 0.0 ? number_format($amt) : number_format($amt, 2));
}

/** "49" / "49.50" from the admin form -> paise. */
function paise_from_input(string $v): int
{
    $v = preg_replace('/[^\d.]/', '', $v);
    if ($v === '' || !is_numeric($v)) {
        return 0;
    }
    return max(0, (int) round(((float) $v) * 100));
}

function days_label(int $d): string
{
    if ($d <= 0)          return 'lifetime';
    if ($d === 1)         return '1 day';
    if ($d === 7)         return '1 week';
    if ($d % 365 === 0)   return ($d / 365) . ' year' . ($d > 365 ? 's' : '');
    if ($d % 30 === 0)    return ($d / 30) . ' month' . ($d > 30 ? 's' : '');
    return $d . ' days';
}

// ---------------------------------------------------------------
// Gateways
// ---------------------------------------------------------------

function pay_gateway_configured(string $gw): bool
{
    if ($gw === 'razorpay') {
        return setting('pay_razorpay_key_id') !== '' && setting('pay_razorpay_key_secret') !== '';
    }
    if ($gw === 'cashfree') {
        return setting('pay_cashfree_app_id') !== '' && setting('pay_cashfree_secret') !== '';
    }
    return false;
}

/** Switched on AND has its keys, in the admin's order. */
function pay_gateways(): array
{
    if (!payments_ready()) {
        return [];
    }
    $order = array_filter(array_map('trim', explode(',', setting('pay_order', 'razorpay,cashfree'))));
    foreach (array_keys(PAY_GATEWAYS) as $g) {
        if (!in_array($g, $order, true)) {
            $order[] = $g;
        }
    }
    $out = [];
    foreach ($order as $g) {
        if (isset(PAY_GATEWAYS[$g])
            && setting('pay_' . $g . '_enabled', '0') === '1'
            && pay_gateway_configured($g)) {
            $out[] = $g;
        }
    }
    return $out;
}

function pay_enabled(): bool
{
    return (bool) pay_gateways();
}

// ---------------------------------------------------------------
// Registration fee
// ---------------------------------------------------------------

function reg_fee_amount(): int
{
    if (!payments_ready() || setting('reg_fee_enabled', '0') !== '1') {
        return 0;
    }
    return max(0, (int) setting('reg_fee_amount', '0'));
}

/** Has this player paid (or been let off) the one-time fee? */
function reg_fee_paid(int $userId): bool
{
    if (!has_table('payments')) {
        return true;
    }
    return (bool) fetch_col(
        'SELECT 1 FROM payments WHERE user_id = ? AND purpose = "registration"
            AND status = "paid" LIMIT 1', [$userId], 0);
}

/**
 * Does this player still owe the registration fee?
 *
 * Accounts created before the fee was switched on are left alone unless
 * the admin ticks "existing players must pay too".
 */
function reg_fee_due(?array $user): bool
{
    if (!$user || reg_fee_amount() <= 0) {
        return false;
    }
    if (setting('reg_fee_apply_existing', '0') !== '1') {
        $since = setting('reg_fee_since');
        if ($since !== '' && !empty($user['created_at'])
            && strtotime((string) $user['created_at']) < strtotime($since)) {
            return false;
        }
    }
    return !reg_fee_paid((int) $user['id']);
}

// ---------------------------------------------------------------
// Plans + subscriptions
// ---------------------------------------------------------------

/** Every plan row, keyed by item. Cached for the request. */
function game_plans_all(bool $reload = false): array
{
    static $rows = null;
    if ($rows !== null && !$reload) {
        return $rows;
    }
    $rows = [];
    if (!has_table('game_plans')) {
        return $rows;
    }
    foreach (fetch_all('SELECT * FROM game_plans') as $r) {
        $rows[$r['item_key']] = $r;
    }
    return $rows;
}

/** The plan for one item if it is paid, else null. */
function game_plan(string $key): ?array
{
    if (!payments_ready()) {
        return null;
    }
    $p = game_plans_all()[$key] ?? null;
    if (!$p || (int) $p['is_paid'] !== 1 || (int) $p['price_paise'] <= 0) {
        return null;
    }
    return $p;
}

/** The live subscription row for a player + item, or null. */
function sub_active(int $userId, string $key): ?array
{
    if (!has_table('subscriptions')) {
        return null;
    }
    return fetch_one(
        'SELECT * FROM subscriptions
          WHERE user_id = ? AND item_key = ? AND status = "active" AND expires_at > NOW()
          ORDER BY expires_at DESC LIMIT 1', [$userId, $key]);
}

/** All live subscriptions of a player, keyed by item. */
function subs_of(int $userId): array
{
    if (!has_table('subscriptions')) {
        return [];
    }
    $out = [];
    foreach (fetch_all(
        'SELECT item_key, MAX(expires_at) AS expires_at FROM subscriptions
          WHERE user_id = ? AND status = "active" AND expires_at > NOW()
          GROUP BY item_key', [$userId]) as $r) {
        $out[$r['item_key']] = $r['expires_at'];
    }
    return $out;
}

/** Title + image for any item key, for pay pages and receipts. */
function pay_item_info(string $key): array
{
    foreach (arena_items() as $i) {
        if ($i['key'] === $key) {
            return ['title' => $i['title'], 'thumb' => $i['thumb'], 'icon' => $i['icon']];
        }
    }
    $g = fetch_one('SELECT title, thumbnail, icon FROM games WHERE slug = ?', [$key]);
    if ($g) {
        $thumb = game_thumb($key, $g['thumbnail']);
        return ['title' => $g['title'], 'thumb' => $thumb, 'icon' => $g['icon'] ?: '&#127918;'];
    }
    $names = ['quiz' => 'Quiz Contest', 'spin' => 'Daily Spin', 'chess' => 'Chess'];
    return ['title' => $names[$key] ?? ucfirst($key), 'thumb' => null, 'icon' => '&#127918;'];
}

/**
 * May this player play this item?
 *
 * @return array{ok:bool, reason:string, message:string, plan:?array, expires:?string}
 *   reason: '' | 'registration' | 'subscription' | 'unavailable'
 */
function game_access(?array $user, string $key): array
{
    $out = ['ok' => true, 'reason' => '', 'message' => '', 'plan' => null, 'expires' => null];

    if (!$user || !payments_ready()) {
        return $out;
    }

    if (reg_fee_due($user)) {
        return ['ok' => false, 'reason' => 'registration',
                'message' => 'Complete your registration (one-time fee '
                           . money(reg_fee_amount()) . ') to start playing.',
                'plan' => null, 'expires' => null];
    }

    $plan = game_plan($key);
    if (!$plan) {
        return $out;
    }
    $out['plan'] = $plan;

    $sub = sub_active((int) $user['id'], $key);
    if ($sub) {
        $out['expires'] = $sub['expires_at'];
        return $out;
    }

    $info = pay_item_info($key);
    return ['ok' => false,
            'reason'  => pay_enabled() ? 'subscription' : 'unavailable',
            'message' => pay_enabled()
                ? $info['title'] . ' is a paid game — ' . money((int) $plan['price_paise'])
                  . ' for ' . days_label((int) $plan['duration_days']) . '.'
                : $info['title'] . ' is a paid game, and online payment is not open yet. '
                  . 'Please contact us to get access.',
            'plan' => $plan, 'expires' => null];
}

/**
 * Website gate. Call after require_play_login(). Sends the player to the
 * right payment page when they may not play.
 */
function require_game_access(string $key): void
{
    $u = current_user();
    $a = game_access($u, $key);
    if ($a['ok']) {
        return;
    }
    $_SESSION['redirect_after_pay'] = $_SERVER['REQUEST_URI'] ?? '';
    if ($a['reason'] === 'registration') {
        redirect('pay.php?for=registration');
    }
    redirect('pay.php?for=game&item=' . urlencode($key));
}

/** Same check for JSON endpoints: answers 402 and stops. */
function json_require_game_access(string $key): void
{
    $a = game_access(current_user(), $key);
    if ($a['ok']) {
        return;
    }
    http_response_code(402);
    header('Content-Type: application/json; charset=utf-8');
    $payUrl = $a['reason'] === 'registration'
        ? url('pay.php?for=registration')
        : url('pay.php?for=game&item=' . urlencode($key));
    echo json_encode([
        'ok'       => false,
        'error'    => $a['message'],
        'message'  => $a['message'],
        'pay_url'  => $payUrl,
        'redirect' => $payUrl,
    ]);
    exit;
}

// ---------------------------------------------------------------
// Orders
// ---------------------------------------------------------------

/** A stored REQUEST_URI ("/gamesv/play.php?game=x") or a site path -> absolute URL. */
function pay_abs_url(string $u): string
{
    if (preg_match('#^https?://#i', $u)) {
        return $u;
    }
    if (str_starts_with($u, '/')) {
        $p = parse_url(SITE_URL);
        return ($p['scheme'] ?? 'http') . '://' . ($p['host'] ?? 'localhost')
             . (isset($p['port']) ? ':' . $p['port'] : '') . $u;
    }
    return url($u);
}

function pay_new_ref(): string
{
    return 'ASV' . date('ymd') . strtoupper(bin2hex(random_bytes(5)));
}

/**
 * Create our own payments row. The gateway order is created next and its
 * id stored on the same row.
 */
function pay_create(int $userId, string $purpose, ?string $item, int $amount,
                    ?int $days, string $gateway, string $source = 'web'): array
{
    $ref = pay_new_ref();
    $id  = insert_row('payments', [
        'user_id'      => $userId,
        'purpose'      => $purpose,
        'item_key'     => $item,
        'plan_days'    => $days,
        'amount_paise' => $amount,
        'currency'     => pay_currency(),
        'gateway'      => $gateway,
        'order_ref'    => $ref,
        'status'       => 'created',
        'source'       => $source,
    ]);
    return fetch_one('SELECT * FROM payments WHERE id = ?', [$id]);
}

function pay_by_ref(string $ref): ?array
{
    if (!preg_match('/^[A-Z0-9]{6,40}$/', $ref) || !has_table('payments')) {
        return null;
    }
    return fetch_one('SELECT * FROM payments WHERE order_ref = ?', [$ref]);
}

/**
 * Mark an order paid and hand over what was bought. Safe to call twice —
 * from the browser return AND the webhook — because only the call that
 * actually flips the status grants anything.
 */
function pay_mark_paid(array $p, string $gwPaymentId = '', array $meta = []): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('UPDATE payments SET status = "paid", paid_at = NOW(),
                                    gw_payment_id = COALESCE(?, gw_payment_id),
                                    meta = ?
                              WHERE id = ? AND status <> "paid"');
        $st->execute([$gwPaymentId !== '' ? mb_substr($gwPaymentId, 0, 100) : null,
                      $meta ? json_encode($meta) : $p['meta'], (int) $p['id']]);

        if ($st->rowCount() !== 1) {
            $pdo->commit();
            return false;                       // already handled
        }

        if ($p['purpose'] === 'subscription' && $p['item_key']) {
            sub_grant((int) $p['user_id'], (string) $p['item_key'],
                      (int) ($p['plan_days'] ?? 30), (int) $p['id'], 'payment');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return true;
}

function pay_mark_failed(array $p, string $why = ''): void
{
    q('UPDATE payments SET status = "failed", note = ? WHERE id = ? AND status = "created"',
      [$why !== '' ? mb_substr($why, 0, 250) : null, (int) $p['id']]);
}

/**
 * Add days to a player's access for one item. A renewal before expiry
 * stacks on top of the time left; 0 days = lifetime (100 years).
 */
function sub_grant(int $userId, string $key, int $days, ?int $paymentId,
                   string $source = 'payment', string $note = ''): void
{
    $cur   = sub_active($userId, $key);
    $start = $cur ? strtotime($cur['expires_at']) : time();
    $len   = $days > 0 ? $days * 86400 : 100 * 365 * 86400;

    insert_row('subscriptions', [
        'user_id'    => $userId,
        'item_key'   => $key,
        'starts_at'  => date('Y-m-d H:i:s', $start),
        'expires_at' => date('Y-m-d H:i:s', $start + $len),
        'payment_id' => $paymentId,
        'source'     => $source,
        'status'     => 'active',
        'note'       => $note !== '' ? mb_substr($note, 0, 190) : null,
    ]);
}

// ---------------------------------------------------------------
// HTTP helper for the gateway APIs
// ---------------------------------------------------------------

/** @return array{0:int,1:array} [http status, decoded json] */
function pay_http(string $method, string $url, array $headers, ?array $body = null): array
{
    $ch = curl_init($url);
    $h  = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $h,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 25,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return [0, ['error' => ['description' => 'Could not reach the payment gateway: ' . $err]]];
    }
    $json = json_decode((string) $raw, true);
    return [$code, is_array($json) ? $json : ['raw' => (string) $raw]];
}

// ---------------------------------------------------------------
// Razorpay
// ---------------------------------------------------------------

function rzp_auth(): array
{
    return ['Authorization: Basic ' . base64_encode(
        setting('pay_razorpay_key_id') . ':' . setting('pay_razorpay_key_secret'))];
}

/** Create the Razorpay order. Returns its id or throws. */
function rzp_create_order(array $p): string
{
    [$code, $j] = pay_http('POST', 'https://api.razorpay.com/v1/orders', rzp_auth(), [
        'amount'   => (int) $p['amount_paise'],
        'currency' => $p['currency'],
        'receipt'  => $p['order_ref'],
        'notes'    => ['ref' => $p['order_ref'], 'purpose' => $p['purpose'],
                       'item' => (string) $p['item_key']],
    ]);
    if ($code !== 200 || empty($j['id'])) {
        throw new RuntimeException('Razorpay: '
            . ($j['error']['description'] ?? ('HTTP ' . $code)));
    }
    q('UPDATE payments SET gw_order_id = ? WHERE id = ?', [$j['id'], (int) $p['id']]);
    return (string) $j['id'];
}

/** Checkout handler signature: HMAC-SHA256(order_id|payment_id, secret). */
function rzp_signature_ok(string $orderId, string $paymentId, string $sig): bool
{
    $want = hash_hmac('sha256', $orderId . '|' . $paymentId, setting('pay_razorpay_key_secret'));
    return $sig !== '' && hash_equals($want, $sig);
}

/** Ask Razorpay whether an order has been paid (used after a closed browser). */
function rzp_order_paid(string $orderId): ?string
{
    [$code, $j] = pay_http('GET', 'https://api.razorpay.com/v1/orders/'
                           . rawurlencode($orderId) . '/payments', rzp_auth());
    if ($code !== 200) {
        return null;
    }
    foreach ($j['items'] ?? [] as $pm) {
        if (($pm['status'] ?? '') === 'captured') {
            return (string) $pm['id'];
        }
    }
    return null;
}

// ---------------------------------------------------------------
// Cashfree (PG API version 2023-08-01)
// ---------------------------------------------------------------

function cf_base(): string
{
    return setting('pay_cashfree_mode', 'sandbox') === 'production'
        ? 'https://api.cashfree.com/pg' : 'https://sandbox.cashfree.com/pg';
}

function cf_headers(): array
{
    return [
        'x-client-id: '     . setting('pay_cashfree_app_id'),
        'x-client-secret: ' . setting('pay_cashfree_secret'),
        'x-api-version: 2023-08-01',
    ];
}

/** Create a Cashfree order, return its payment_session_id. */
function cf_create_order(array $p, array $user, string $phone): string
{
    $ret = url('pay-callback.php?gw=cashfree&ref=' . urlencode($p['order_ref']));

    [$code, $j] = pay_http('POST', cf_base() . '/orders', cf_headers(), [
        'order_id'         => $p['order_ref'],
        'order_amount'     => round($p['amount_paise'] / 100, 2),
        'order_currency'   => $p['currency'],
        'customer_details' => [
            'customer_id'    => 'u' . (int) $user['id'],
            'customer_name'  => mb_substr((string) $user['name'], 0, 60),
            'customer_email' => (string) $user['email'],
            'customer_phone' => $phone,
        ],
        'order_meta' => [
            'return_url' => $ret,
            'notify_url' => url('pay-webhook.php?gw=cashfree'),
        ],
        'order_note' => mb_substr(ucfirst($p['purpose']) . ' ' . (string) $p['item_key'], 0, 190),
    ]);
    if ($code < 200 || $code >= 300 || empty($j['payment_session_id'])) {
        throw new RuntimeException('Cashfree: ' . ($j['message'] ?? ('HTTP ' . $code)));
    }
    q('UPDATE payments SET gw_order_id = ? WHERE id = ?',
      [(string) ($j['cf_order_id'] ?? $p['order_ref']), (int) $p['id']]);
    return (string) $j['payment_session_id'];
}

/** 'PAID' | 'ACTIVE' | 'EXPIRED' | ... | null */
function cf_order_status(string $orderRef): ?array
{
    [$code, $j] = pay_http('GET', cf_base() . '/orders/' . rawurlencode($orderRef), cf_headers());
    if ($code !== 200) {
        return null;
    }
    return $j;
}

/** The successful payment id of a paid Cashfree order, if any. */
function cf_paid_payment_id(string $orderRef): string
{
    [$code, $j] = pay_http('GET', cf_base() . '/orders/' . rawurlencode($orderRef) . '/payments',
                           cf_headers());
    if ($code === 200 && is_array($j)) {
        foreach ($j as $pm) {
            if (($pm['payment_status'] ?? '') === 'SUCCESS') {
                return (string) ($pm['cf_payment_id'] ?? '');
            }
        }
    }
    return '';
}

// ---------------------------------------------------------------
// The app's door to pay.php
// ---------------------------------------------------------------

/** A signed pay.php URL the app can open in its WebView. */
function app_pay_url(int $userId, string $for, string $item = ''): string
{
    return url('pay.php?for=' . urlencode($for)
             . ($item !== '' ? '&item=' . urlencode($item) : '')
             . '&app=1&t=' . urlencode(app_frame_ticket($userId, 'pay')));
}

/** The 402 the API sends when a player has to pay first. */
function api_payment_payload(array $user, array $access, string $key): array
{
    $for = $access['reason'] === 'registration' ? 'registration' : 'game';
    return [
        'reason'  => $access['reason'],
        'pay_for' => $for,
        'item'    => $for === 'game' ? $key : '',
        'amount'  => $for === 'registration'
            ? reg_fee_amount() : (int) ($access['plan']['price_paise'] ?? 0),
        'price'   => $for === 'registration'
            ? money(reg_fee_amount()) : money((int) ($access['plan']['price_paise'] ?? 0)),
        'days'    => (int) ($access['plan']['duration_days'] ?? 0),
        'pay_url' => $access['reason'] === 'unavailable'
            ? '' : app_pay_url((int) $user['id'], $for, $for === 'game' ? $key : ''),
    ];
}
